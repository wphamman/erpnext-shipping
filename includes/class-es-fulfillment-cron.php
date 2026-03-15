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
     * TCG ShipLogic status → WC status mapping.
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

    /**
     * Run the polling job.
     */
    public static function poll() {
        $logger = wc_get_logger();
        $ctx    = array( 'source' => 'erpnext-shipping-fulfillment' );

        // Get carrier credentials from WC instance settings.
        global $wpdb;
        $options = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
                'woocommerce_erpnext_shipping_%_settings'
            )
        );

        $tcg_token = '';
        $mds_token = '';

        foreach ( $options as $row ) {
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
            $items = ES_Fulfillment_Tracking::get_tracking_items( $order );
            if ( empty( $items ) ) {
                continue;
            }

            $best_wc_status  = null;
            $best_raw_status = null;

            foreach ( $items as $item ) {
                $provider = $item['tracking_provider'] ?? '';
                $number   = $item['tracking_number'] ?? '';

                if ( empty( $number ) ) {
                    continue;
                }

                $result = null;

                if ( 'the-courier-guy' === $provider && ! empty( $tcg_token ) ) {
                    $result = self::poll_tcg( $tcg_token, $number, $logger, $ctx );
                } elseif ( 'mds-collivery' === $provider && ! empty( $mds_token ) ) {
                    $result = self::poll_mds( $mds_token, $number, $logger, $ctx );
                }

                if ( $result ) {
                    if ( $result['wc_status'] && self::is_higher_priority( $result['wc_status'], $best_wc_status ) ) {
                        $best_wc_status  = $result['wc_status'];
                        $best_raw_status = $result['raw'];
                    } elseif ( ! $best_raw_status ) {
                        // If no WC mapping yet, still capture the raw status for diagnostics.
                        $best_raw_status = $result['raw'];
                    }
                }
            }

            // Store raw courier status for admin display / debugging.
            if ( $best_raw_status ) {
                $order->update_meta_data( '_es_courier_status', $best_raw_status );
            }

            // Update order status if we have a higher-priority status.
            if ( $best_wc_status ) {
                $current = $order->get_status();
                if ( self::is_higher_priority( $best_wc_status, $current ) ) {
                    $order->update_status(
                        $best_wc_status,
                        sprintf( __( 'Auto-updated by courier tracking poll.', 'erpnext-shipping' ) )
                    );
                }
            }

            // Record poll timestamp.
            $order->update_meta_data( '_es_last_polled', time() );
            $order->save();
        }
    }

    /**
     * Poll TCG ShipLogic API for tracking status.
     */
    private static function poll_tcg( $token, $tracking_ref, $logger, $ctx ) {
        $url = 'https://api.shiplogic.com/v2/tracking/shipments?tracking_reference=' . urlencode( $tracking_ref );

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
