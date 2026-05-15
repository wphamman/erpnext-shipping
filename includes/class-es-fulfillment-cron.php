<?php
defined( 'ABSPATH' ) || exit;

/**
 * WP-Cron: poll TCG and MDS courier APIs, update order statuses.
 * Runs every 15 minutes (reuses es_every_15_min interval).
 */
class ES_Fulfillment_Cron {

    /** Max orders to poll per cron run (PHP timeout safety). */
    const BATCH_SIZE = 50;

    /** Forward-only status order (index = priority). */
    private static $status_priority = array(
        'processing'        => 0,
        'partially-shipped' => 1,
        'completed'         => 2,  // "Shipped"
        'delivered'         => 3,
    );

    /**
     * TCG Courier Guy status → WC status mapping.
     */
    private static $tcg_map = array(
        'created'          => null,  // No change
        'collected'        => null,
        'in-transit'       => 'completed',
        'out-for-delivery' => 'completed',
        'delivered'        => 'delivered',
    );

    /**
     * MDS Collivery status_id → WC status mapping.
     */
    private static $mds_map = array(
        7  => 'completed',  // Collected
        9  => 'completed',  // In Transit
        8  => 'delivered',  // Delivered
        32 => 'delivered',  // Completed
    );

    /** Transient name for the cron/manual-run mutex (60s TTL). */
    const LOCK_KEY = 'es_poll_lock';
    /** Option name for the most-recent poll cycle summary. */
    const SUMMARY_OPTION = 'es_last_poll_summary';

    /**
     * Run the polling job.
     */
    public static function poll() {
        // Mutex: prevent cron and the Diagnostics "Run now" button from running
        // simultaneously, which would double-hit carrier APIs.
        if ( get_transient( self::LOCK_KEY ) ) {
            return;
        }
        set_transient( self::LOCK_KEY, time(), 60 );

        $logger   = wc_get_logger();
        $ctx      = array( 'source' => 'erpnext-shipping-fulfillment' );
        $start_ms = (int) ( microtime( true ) * 1000 );
        $summary  = array(
            'started_at'        => time(),
            'orders_processed'  => 0,
            'errors'            => 0,
            'per_provider'      => array(
                'the-courier-guy' => array( 'polled' => 0, 'successes' => 0, 'failures' => 0 ),
                'mds-collivery'   => array( 'polled' => 0, 'successes' => 0, 'failures' => 0 ),
            ),
            'duration_ms'       => 0,
        );

        try {
            // Get carrier credentials from WC instance settings.
            $tokens    = self::resolve_tokens();
            $tcg_token = $tokens['tcg'];
            $mds_token = $tokens['mds'];

            if ( empty( $tcg_token ) && empty( $mds_token ) ) {
                $logger->warning( 'No carrier API tokens configured, skipping poll.', $ctx );
                return;
            }

            // Anti-starvation: fetch unpolled orders first, then oldest-polled.
            // Two separate queries avoid meta_query + meta_key JOIN conflicts.
            $unpolled = wc_get_orders( array(
                'status'     => array( 'completed', 'partially-shipped' ),
                'meta_query' => array(
                    array(
                        'key'     => '_wc_shipment_tracking_items',
                        'compare' => 'EXISTS',
                    ),
                    array(
                        'key'     => '_es_last_polled',
                        'compare' => 'NOT EXISTS',
                    ),
                ),
                'limit' => self::BATCH_SIZE,
            ) );

            $remaining = self::BATCH_SIZE - count( $unpolled );
            $polled = array();
            if ( $remaining > 0 ) {
                $polled = wc_get_orders( array(
                    'status'     => array( 'completed', 'partially-shipped' ),
                    'meta_query' => array(
                        array(
                            'key'     => '_wc_shipment_tracking_items',
                            'compare' => 'EXISTS',
                        ),
                        array(
                            'key'     => '_es_last_polled',
                            'compare' => 'EXISTS',
                        ),
                    ),
                    'orderby'  => 'meta_value_num',
                    'meta_key' => '_es_last_polled',
                    'order'    => 'ASC',
                    'limit'    => $remaining,
                ) );
            }

            $orders = array_merge( $unpolled, $polled );

            if ( empty( $orders ) ) {
                return;
            }

            foreach ( $orders as $order ) {
                $r = self::poll_single_order( $order, $tcg_token, $mds_token, $logger, $ctx );
                if ( null === $r ) {
                    continue; // no tracking items
                }
                $summary['orders_processed']++;
                if ( $r['had_failure'] ) {
                    $summary['errors']++;
                }
                foreach ( $r['per_provider'] as $slug => $stats ) {
                    if ( ! isset( $summary['per_provider'][ $slug ] ) ) {
                        $summary['per_provider'][ $slug ] = array( 'polled' => 0, 'successes' => 0, 'failures' => 0 );
                    }
                    $summary['per_provider'][ $slug ]['polled']    += $stats['polled'];
                    $summary['per_provider'][ $slug ]['successes'] += $stats['successes'];
                    $summary['per_provider'][ $slug ]['failures']  += $stats['failures'];
                }
            }
        } finally {
            // Always: persist the summary and release the lock, even if a
            // wc_get_orders call or a per-order poll throws.
            $summary['duration_ms'] = (int) ( microtime( true ) * 1000 ) - $start_ms;
            update_option( self::SUMMARY_OPTION, $summary, false );
            delete_transient( self::LOCK_KEY );
        }
    }

    /**
     * Poll a single order's tracking items. Returns null if there are no items,
     * or a stats array { had_failure, per_provider: { slug: { polled, successes, failures } } }.
     */
    public static function poll_single_order( $order, $tcg_token = null, $mds_token = null, $logger = null, $ctx = null ) {
        if ( null === $tcg_token || null === $mds_token ) {
            $tokens = self::resolve_tokens();
            $tcg_token = $tokens['tcg'];
            $mds_token = $tokens['mds'];
        }
        if ( ! $logger ) {
            $logger = wc_get_logger();
        }
        if ( ! $ctx ) {
            $ctx = array( 'source' => 'erpnext-shipping-fulfillment' );
        }

        $items = ES_Fulfillment_Tracking::get_tracking_items( $order );
        if ( empty( $items ) ) {
            return null;
        }

        $item_statuses = array();
        $raw_statuses  = array();
        $had_failure   = false;
        $per_provider  = array();

        foreach ( $items as $item ) {
            $provider = $item['tracking_provider'] ?? '';
            $number   = $item['tracking_number'] ?? '';

            if ( empty( $number ) ) {
                continue;
            }

            $result    = null;
            $canonical = ES_Fulfillment_Tracking::normalize_provider( $provider );

            if ( 'the-courier-guy' === $canonical && ! empty( $tcg_token ) ) {
                $result = self::poll_tcg( $tcg_token, $number, $logger, $ctx );
            } elseif ( 'mds-collivery' === $canonical && ! empty( $mds_token ) ) {
                $result = self::poll_mds( $mds_token, $number, $logger, $ctx );
            }

            if ( ! isset( $per_provider[ $canonical ] ) ) {
                $per_provider[ $canonical ] = array( 'polled' => 0, 'successes' => 0, 'failures' => 0 );
            }
            $per_provider[ $canonical ]['polled']++;

            if ( $result ) {
                $per_provider[ $canonical ]['successes']++;
                $raw_statuses[] = $result['raw'];
                if ( $result['wc_status'] ) {
                    $item_statuses[] = $result['wc_status'];
                }
            } elseif ( ! empty( $provider ) ) {
                $per_provider[ $canonical ]['failures']++;
                $had_failure = true;
            }
        }

        if ( class_exists( 'ES_Fulfillment_Watchdog' ) ) {
            if ( $had_failure ) {
                ES_Fulfillment_Watchdog::record_poll_failure( $order );
            } elseif ( ! empty( $raw_statuses ) ) {
                ES_Fulfillment_Watchdog::reset_poll_failure( $order );
            }
        }

        if ( ! empty( $raw_statuses ) ) {
            $order->update_meta_data( '_es_courier_status', implode( ', ', $raw_statuses ) );
        }

        if ( ! empty( $item_statuses ) && count( $item_statuses ) === count( $items ) ) {
            $effective_status = $item_statuses[0];
            $min_priority     = self::$status_priority[ $effective_status ] ?? 0;
            foreach ( $item_statuses as $s ) {
                $p = self::$status_priority[ $s ] ?? 0;
                if ( $p < $min_priority ) {
                    $min_priority     = $p;
                    $effective_status = $s;
                }
            }
            $current = $order->get_status();
            if ( self::is_higher_priority( $effective_status, $current ) ) {
                $order->update_status(
                    $effective_status,
                    sprintf( __( 'Auto-updated by courier tracking poll.', 'erpnext-shipping' ) )
                );
            }
        }

        $order->update_meta_data( '_es_last_polled', time() );
        $order->save();

        return array(
            'had_failure'  => $had_failure,
            'per_provider' => $per_provider,
            'raw_statuses' => $raw_statuses,
        );
    }

    /**
     * Resolve carrier tokens from any shipping-method instance settings.
     * Extracted from poll() for reuse by poll_single_order() and Diagnostics "Run now".
     */
    public static function resolve_tokens() {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
                'woocommerce_erpnext_shipping_%_settings'
            )
        );
        $tcg_token = '';
        $mds_token = '';
        foreach ( $rows as $row ) {
            $opts = maybe_unserialize( $row->option_value );
            if ( ! is_array( $opts ) ) {
                continue;
            }
            if ( empty( $tcg_token ) && ! empty( $opts['tcg_api_token'] ) ) {
                $tcg_token = $opts['tcg_api_token'];
            }
            if ( empty( $mds_token ) && ! empty( $opts['mds_api_token'] ) ) {
                $mds_token = $opts['mds_api_token'];
            }
        }
        return array( 'tcg' => $tcg_token, 'mds' => $mds_token );
    }

    /**
     * Poll TCG Courier Guy API for tracking status.
     */
    private static function poll_tcg( $token, $tracking_ref, $logger, $ctx ) {
        $url = 'https://api.portal.thecourierguy.co.za/v2/tracking/shipments?tracking_reference=' . urlencode( $tracking_ref );

        $response = wp_remote_get( $url, array(
            'timeout' => 10,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            $logger->error( 'TCG poll failed for ' . $tracking_ref . ': ' . $response->get_error_message(), $ctx );
            return null;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            $logger->error( 'TCG poll HTTP ' . $code . ' for ' . $tracking_ref, $ctx );
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) || empty( $body['shipments'] ) ) {
            return null;
        }

        // Get the latest status from the first shipment.
        $shipment = $body['shipments'][0];
        $status   = strtolower( $shipment['status'] ?? '' );
        $mapped   = self::$tcg_map[ $status ] ?? null;

        return array( 'raw' => 'tcg:' . $status, 'wc_status' => $mapped );
    }

    /**
     * Poll MDS Collivery API for tracking status.
     */
    private static function poll_mds( $token, $waybill_id, $logger, $ctx ) {
        $url = 'https://api.collivery.co.za/v3/status_tracking/' . urlencode( $waybill_id ) . '?api_token=' . urlencode( $token );

        $response = wp_remote_get( $url, array(
            'timeout' => 10,
            'headers' => array(
                'X-App-Name'    => 'ERPNextShipping',
                'X-App-Version' => defined( 'ES_SHIPPING_VERSION' ) ? ES_SHIPPING_VERSION : '1.0.0',
                'X-App-Host'    => site_url(),
                'X-App-Lang'    => 'en',
                'Accept'        => 'application/json',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            $logger->error( 'MDS poll failed for ' . $waybill_id . ': ' . $response->get_error_message(), $ctx );
            return null;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            $logger->error( 'MDS poll HTTP ' . $code . ' for ' . $waybill_id, $ctx );
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) || ! isset( $body['data'] ) ) {
            return null;
        }

        // Get the latest status_id.
        $statuses = $body['data'];
        if ( empty( $statuses ) ) {
            return null;
        }

        // Last entry is the most recent status.
        $latest    = end( $statuses );
        $status_id = intval( $latest['status_id'] ?? 0 );
        $mapped    = self::$mds_map[ $status_id ] ?? null;

        return array( 'raw' => 'mds:' . $status_id, 'wc_status' => $mapped );
    }

    /**
     * Check if $new status is higher priority than $current.
     */
    private static function is_higher_priority( $new, $current ) {
        if ( null === $new ) {
            return false;
        }
        if ( null === $current ) {
            return true;
        }
        $new_p = self::$status_priority[ $new ] ?? -1;
        $cur_p = self::$status_priority[ $current ] ?? -1;
        return $new_p > $cur_p;
    }
}
