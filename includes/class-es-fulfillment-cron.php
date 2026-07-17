<?php
defined( 'ABSPATH' ) || exit;

/**
 * WP-Cron: poll TCG and MDS courier APIs, update order statuses.
 * Runs every 15 minutes (reuses es_every_15_min interval).
 */
class ES_Fulfillment_Cron {

    /** Max orders to poll per cron run (PHP timeout safety). */
    const BATCH_SIZE = 50;

	/**
	 * Slots reserved for booked shipments still in a pre-shipment WC state before
	 * the legacy completed-order fill. Shared fairly by Locker and door bookings.
	 */
	const PRE_SHIPMENT_POLL_RESERVE = 25;

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

    /** Option name for the database-atomic cron/manual-run mutex. */
    const LOCK_KEY = 'es_poll_lock';
    /** Immutable lease long enough for a full batch, including slow providers. */
    const LOCK_LEASE = 3600;
    /** Option name for the most-recent poll cycle summary. */
    const SUMMARY_OPTION = 'es_last_poll_summary';

    /**
     * Run the polling job.
     */
    public static function poll() {
        // One-statement mutex: prevent cron and Diagnostics "Run now" from
        // concurrently hitting carrier APIs. The immutable one-hour lease safely
        // exceeds a full batch; ownership-checked release runs in finally.
        $lock_token = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'es-poll-', true );
        if ( ! ES_Option_Mutex::acquire_or_replace_stale( self::LOCK_KEY, $lock_token, self::LOCK_LEASE ) ) {
            return;
        }

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

            // Poll when ANY carrier can be reached: a legacy Courier Guy / MDS token,
            // OR a configured TCG Locker client. TCG Locker is independently Bearer-
            // authenticated and does NOT use the legacy tokens, so a locker-only store
            // (both legacy tokens empty) must still poll its booked locker shipments.
            $locker_ready = function_exists( 'es_tcg_locker_client' ) && es_tcg_locker_client();
            if ( empty( $tcg_token ) && empty( $mds_token ) && ! $locker_ready ) {
                $logger->warning( 'No carrier API tokens configured, skipping poll.', $ctx );
                return;
            }

            // Cross-category anti-starvation: reserve a slice of the batch for
            // pre-shipment TCG Locker orders FIRST, so a continuous backlog of
            // conventional completed orders can never fill all BATCH_SIZE slots and
            // block a locker order's first processing→Shipped transition. Each
            // category still applies unpolled-first anti-starvation internally, and
            // any unused reserve is reclaimed by the legacy fill / the locker top-up
            // below — so no batch capacity is wasted when one category is empty.
			// Split the reserve between the two pre-shipment booking families. Each
			// receives guaranteed slots, while an under-filled first family leaves its
			// unused capacity available to the second.
			$reserve_cap = min( self::BATCH_SIZE, self::PRE_SHIPMENT_POLL_RESERVE );
			$orders = self::merge_locker_orders( array(), (int) ceil( $reserve_cap / 2 ) );
			$orders = self::merge_door_booking_orders( $orders, $reserve_cap );
			$orders = self::merge_locker_orders( $orders, $reserve_cap );
            $have   = array();
            foreach ( $orders as $o ) {
                $have[ $o->get_id() ] = true;
            }

            // Legacy tracking-items selection fills the remaining batch: unpolled
            // first, then oldest-polled (two queries avoid a meta_query + meta_key
            // JOIN conflict), de-duplicated against the locker reserve.
            $add_legacy = function ( $found ) use ( &$orders, &$have ) {
                foreach ( $found as $o ) {
                    $id = $o->get_id();
                    if ( ! isset( $have[ $id ] ) ) {
                        $orders[]    = $o;
                        $have[ $id ] = true;
                    }
                }
            };

            // Both legacy queries EXCLUDE the already-selected ids (the locker
            // reserve, plus anything the first legacy query added) so their `limit`
            // counts only NEW orders. Without this, a completed order that is ALSO a
            // reserved locker order would be fetched, discarded by the de-dup, and
            // leave its slot unfilled — letting an overlapping-locker backlog starve
            // conventional orders.
            $remaining = self::BATCH_SIZE - count( $orders );
            if ( $remaining > 0 ) {
                $add_legacy( wc_get_orders( array(
                    'status'     => array( 'completed', 'partially-shipped' ),
                    'exclude'    => array_map( 'intval', array_keys( $have ) ),
                    'meta_query' => array(
                        array( 'key' => '_wc_shipment_tracking_items', 'compare' => 'EXISTS' ),
                        array( 'key' => '_es_last_polled', 'compare' => 'NOT EXISTS' ),
                    ),
                    'limit' => $remaining,
                ) ) );
            }
            $remaining = self::BATCH_SIZE - count( $orders );
            if ( $remaining > 0 ) {
                $add_legacy( wc_get_orders( array(
                    'status'     => array( 'completed', 'partially-shipped' ),
                    'exclude'    => array_map( 'intval', array_keys( $have ) ),
                    'meta_query' => array(
                        array( 'key' => '_wc_shipment_tracking_items', 'compare' => 'EXISTS' ),
                        array( 'key' => '_es_last_polled', 'compare' => 'EXISTS' ),
                    ),
                    'orderby'  => 'meta_value_num',
                    'meta_key' => '_es_last_polled', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                    'order'    => 'ASC',
                    'limit'    => $remaining,
                ) ) );
            }

            // Reclaim any slots the legacy fill left unused with more locker orders
            // (idempotent — merge_locker_orders de-duplicates), so a small legacy
            // backlog never wastes capacity.
			$orders = self::merge_locker_orders( $orders, self::BATCH_SIZE );
			$orders = self::merge_door_booking_orders( $orders, self::BATCH_SIZE );

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
            ES_Option_Mutex::release( self::LOCK_KEY, $lock_token );
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
			if ( ! in_array( $canonical, array( 'the-courier-guy', 'mds-collivery', 'tcg-locker' ), true ) ) {
				// A manual/custom tracking item has no API integration to poll. It is
				// not a carrier failure and must not inflate watchdog alerts.
				continue;
			}

            if ( 'the-courier-guy' === $canonical && ! empty( $tcg_token ) ) {
                $result = self::poll_tcg( $tcg_token, $number, $logger, $ctx );
            } elseif ( 'mds-collivery' === $canonical && ! empty( $mds_token ) ) {
                $result = self::poll_mds( $mds_token, $number, $logger, $ctx );
            } elseif ( 'tcg-locker' === $canonical ) {
                // TCG Locker uses its OWN authenticated client/token (the Courier-Guy
                // portal token cannot see locker shipments), so it is not gated on
                // $tcg_token. The client self-gates on being enabled + configured.
                $result = self::poll_tcg_locker( $order, $number, $logger, $ctx );
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
			} elseif (
				in_array( $canonical, array( 'the-courier-guy', 'mds-collivery' ), true )
				&& (int) $order->get_meta( ES_Carrier_Booking::M_BOOKED_TS, true ) > time() - 900
			) {
				// Newly-created waybills can take a few minutes to become visible to
				// tracking. Record a stable no-op instead of a false watchdog failure.
				$per_provider[ $canonical ]['successes']++;
				$raw_statuses[] = ( 'the-courier-guy' === $canonical ? 'tcg' : 'mds' ) . ':awaiting-index';
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
     * Union booked TCG Locker orders into the poll set, de-duplicated and capped at
     * BATCH_SIZE. Selects orders with a stored shipment id AND booking_status='booked'
     * whose WC status is still pre-terminal (processing/on-hold/completed/partially-
     * shipped) — 'delivered' is deliberately excluded so selection stops naturally at
     * the terminal state (a delivered order is never re-polled). Anti-starvation:
     * unpolled first, then oldest-polled (two queries to avoid a meta_query + meta_key
     * JOIN conflict, mirroring the primary selection).
     *
     * @param array $orders Orders already selected (deduplicated against these).
     * @param int   $cap    Maximum total order count after merging.
     * @return array Merged, de-duplicated, $cap-capped order list.
     */
    private static function merge_locker_orders( $orders, $cap ) {
        if ( ! class_exists( 'ES_TCG_Locker_Booking' ) ) {
            return $orders;
        }
        $have = array();
        foreach ( $orders as $o ) {
            $have[ $o->get_id() ] = true;
        }
        $statuses = array( 'processing', 'on-hold', 'completed', 'partially-shipped' );
        $booked   = array(
            array( 'key' => ES_TCG_Locker_Booking::M_SHIPMENT_ID, 'compare' => 'EXISTS' ),
            array( 'key' => ES_TCG_Locker_Booking::M_BOOKING_STATUS, 'value' => 'booked', 'compare' => '=' ),
        );

        $add = function ( $found ) use ( &$orders, &$have ) {
            foreach ( $found as $o ) {
                $id = $o->get_id();
                if ( ! isset( $have[ $id ] ) ) {
                    $orders[]    = $o;
                    $have[ $id ] = true;
                }
            }
        };

        // Exclude orders already selected so the query's `limit` counts only NEW
        // locker orders — otherwise a top-up call would fetch already-taken orders
        // and de-dup them away, reclaiming nothing.
        $remaining = $cap - count( $orders );
        if ( $remaining > 0 ) {
            $add( wc_get_orders( array(
                'status'     => $statuses,
                'exclude'    => array_map( 'intval', array_keys( $have ) ),
                'meta_query' => array_merge( $booked, array(
                    array( 'key' => '_es_last_polled', 'compare' => 'NOT EXISTS' ),
                ) ),
                'limit'      => $remaining,
            ) ) );
        }

        $remaining = $cap - count( $orders );
        if ( $remaining > 0 ) {
            $add( wc_get_orders( array(
                'status'     => $statuses,
                'exclude'    => array_map( 'intval', array_keys( $have ) ),
                'meta_query' => array_merge( $booked, array(
                    array( 'key' => '_es_last_polled', 'compare' => 'EXISTS' ),
                ) ),
                'orderby'    => 'meta_value_num',
                'meta_key'   => '_es_last_polled', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'order'      => 'ASC',
                'limit'      => $remaining,
            ) ) );
        }

        return $orders;
    }

	/**
	 * Add manually-booked Courier Guy/MDS orders that have not yet advanced out of
	 * Processing. Without this union the legacy selector (completed/partially-
	 * shipped only) could never observe the carrier's first in-transit event.
	 */
	private static function merge_door_booking_orders( $orders, $cap ) {
		if ( ! class_exists( 'ES_Carrier_Booking' ) ) {
			return $orders;
		}
		$have = array();
		foreach ( $orders as $order ) {
			$have[ $order->get_id() ] = true;
		}
		$statuses = array( 'processing', 'on-hold', 'completed', 'partially-shipped' );
		$booked   = array(
			array( 'key' => ES_Carrier_Booking::M_SHIPMENT_ID, 'compare' => 'EXISTS' ),
			array( 'key' => ES_Carrier_Booking::M_BOOKING_STATUS, 'value' => ES_Carrier_Booking::STATE_BOOKED, 'compare' => '=' ),
		);
		$add = function ( $found ) use ( &$orders, &$have ) {
			foreach ( $found as $order ) {
				$id = $order->get_id();
				if ( ! isset( $have[ $id ] ) ) {
					$orders[]   = $order;
					$have[ $id ] = true;
				}
			}
		};

		$remaining = $cap - count( $orders );
		if ( $remaining > 0 ) {
			$add( wc_get_orders( array(
				'status' => $statuses, 'exclude' => array_map( 'intval', array_keys( $have ) ),
				'meta_query' => array_merge( $booked, array( array( 'key' => '_es_last_polled', 'compare' => 'NOT EXISTS' ) ) ),
				'limit' => $remaining,
			) ) );
		}
		$remaining = $cap - count( $orders );
		if ( $remaining > 0 ) {
			$add( wc_get_orders( array(
				'status' => $statuses, 'exclude' => array_map( 'intval', array_keys( $have ) ),
				'meta_query' => array_merge( $booked, array( array( 'key' => '_es_last_polled', 'compare' => 'EXISTS' ) ) ),
				'orderby' => 'meta_value_num', 'meta_key' => '_es_last_polled', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'order' => 'ASC', 'limit' => $remaining,
			) ) );
		}
		return $orders;
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
     * Poll the authenticated TCG Locker tracking endpoint and map the status via
     * the conservative forward-only §12 map. Uses the plugin's own client (Bearer),
     * not the Courier-Guy portal token.
     *
     * CARRY-FORWARD: a booked shipment may have no tracking reference, in which case
     * Phase-4 stored the SHIPMENT ID as the AST tracking number as a fallback — but a
     * shipment id is NOT a valid waybill for this endpoint. So we always poll the
     * PERSISTED tracking reference (the real waybill); when it is absent we record a
     * stable no-op status rather than polling the shipment id or marking a failure.
     *
     * @return array{raw:string, wc_status:?string}|null
     */
    private static function poll_tcg_locker( $order, $tracking_number, $logger, $ctx ) {
        if ( ! function_exists( 'es_tcg_locker_client' ) || ! class_exists( 'ES_TCG_Locker_Tracking' ) ) {
            return null;
        }
        $waybill = (string) $order->get_meta( ES_TCG_Locker_Booking::M_TRACKING_REF, true );
        if ( '' === $waybill ) {
            // No real waybill (shipment-id fallback in the AST item) — cannot poll.
            // Record a stable state; do not poll a shipment id, do not false-fail.
            $logger->warning( 'TCG Locker: order ' . $order->get_id() . ' booked without a tracking reference; skipping poll (no valid waybill).', $ctx );
            return array( 'raw' => 'tcg-locker:no-tracking-ref', 'wc_status' => null );
        }

        $client = es_tcg_locker_client();
        if ( ! $client ) {
            $logger->warning( 'TCG Locker: client not configured; cannot poll order ' . $order->get_id() . '.', $ctx );
            return null;
        }

        $res = $client->get_tracking( $waybill );
        if ( empty( $res['ok'] ) ) {
            // The client redacts its own token; log only the order + waybill.
            $logger->error( 'TCG Locker poll failed for order ' . $order->get_id() . ' (waybill ' . $waybill . ').', $ctx );
            return null;
        }

        $raw    = strtolower( trim( (string) ( $res['status'] ?? '' ) ) );
        $mapped = ES_TCG_Locker_Tracking::map_status( $raw ); // 'completed' | 'delivered' | null.

        self::maybe_notify_locker_arrival( $order, $waybill, $raw, $res['data'] ?? array(), $logger, $ctx );

        return array(
            'raw'       => 'tcg-locker:' . ( '' !== $raw ? $raw : 'unknown' ),
            'wc_status' => $mapped,
        );
    }

    /**
     * Fire the customer "your parcel is in the locker" notice exactly once.
     *
     * This cannot hang off a WC status transition: `in-locker` maps to `completed`,
     * but so do `courier-collected` / `in-transit`, and advancement is forward-only —
     * so the order reached `completed` days earlier and never transitions on arrival.
     * We therefore key on the provider's own in-locker EVENT, not on order status.
     *
     * The arrival time comes from that event rather than this poll's clock: the poll
     * runs every 15 minutes, so a poll-derived deadline would always sit LATER than
     * the real one and could send a customer to an already-emptied locker.
     *
     * Gated on the CURRENT status being `in-locker`, not merely on an in-locker
     * event existing in history. Every delivered parcel keeps its in-locker event
     * forever, so keying on history alone would mail "your parcel is waiting"
     * to customers who collected days ago — and to returned/expired shipments.
     * If the customer collects between two polls we simply never send, which is
     * correct: there is nothing useful to tell them.
     *
     * At-most-once: the flag is persisted BEFORE the action fires, so a failure in
     * any listener can never re-mail the customer on a later poll. The flag is only
     * burned once we know the mailer is actually listening (see below) — otherwise
     * a cron request without WooCommerce's mailer would silently eat the one email
     * this order ever gets.
     *
     * @param string $waybill The tracking reference THIS poll fetched.
     * @param string $raw     Current provider status for this shipment.
     */
    private static function maybe_notify_locker_arrival( $order, $waybill, $raw, $data, $logger, $ctx ) {
        if ( ! $order || $order->get_meta( '_es_tcg_locker_arrival_notified', true ) ) {
            return;
        }
        if ( 'in-locker' !== $raw ) {
            return; // Not sitting in the destination locker right now.
        }
        $raw_date = ES_TCG_Locker_Tracking::first_event_time( $data, 'in-locker' );
        if ( '' === $raw_date ) {
            return; // No arrival event to anchor a deadline to.
        }
        // Provider clock, NOT wp_timezone(): TCG is SA-only and sends naive SAST.
        $ts = ES_TCG_Locker_Tracking::event_timestamp( $raw_date, ES_TCG_Locker_Tracking::provider_tz() );
        if ( $ts <= 0 ) {
            // Unparseable date: skip rather than state a deadline we cannot stand behind.
            $logger->warning(
                'TCG Locker: unparseable in-locker event date "' . $raw_date . '" on order '
                . $order->get_id() . '; arrival notice skipped.',
                $ctx
            );
            return;
        }

        // Nothing truthful to say about where it is without the quote snapshot, and
        // the email would bail anyway — don't burn the one-shot flag on it.
        if ( empty( ES_TCG_Locker_Rate::read_order_snapshot( $order ) ) ) {
            return;
        }

        // Decide send-vs-suppress (read-only). Never state a deadline that has already
        // passed: if the poll was down long enough for the window to lapse, an
        // "in-locker" status is stale or the parcel is on its way back, and a
        // "collect by <yesterday>" mail is worse than silence. Rare — the poll runs
        // every 15 minutes against a 36-hour window.
        $snap     = ES_TCG_Locker_Rate::read_order_snapshot( $order );
        $hours    = ES_TCG_Locker_Tracking::sane_collection_hours(
            $snap[ ES_TCG_Locker_Rate::M_COLLECT_HOURS ] ?? es_tcg_locker_collection_hours()
        );
        $deadline = ES_TCG_Locker_Tracking::collection_deadline( $ts, $hours );
        $lapsed   = ( $deadline > 0 && $deadline <= time() );

        // Confirm the arrival-email LISTENER is actually registered before claiming.
        // Constructing the mailer registers it — but only in 'active' fulfillment
        // mode, where the email class is built; WC()->mailer() returns a valid mailer
        // regardless of mode, so checking the mailer alone would claim-and-fire into
        // the void (permanently, since the claim is one-shot) whenever locker
        // checkout is offered but fulfillment is not active. Force construction, then
        // check the listener itself. Bail WITHOUT claiming so a later activation
        // retries. Only gates the send path; a lapsed window suppresses regardless.
        if ( ! $lapsed ) {
            if ( function_exists( 'WC' ) ) {
                WC()->mailer(); // Triggers woocommerce_email_classes → registers our listener.
            }
            if ( ! has_action( 'es_tcg_locker_arrived' ) ) {
                $logger->warning(
                    'TCG Locker: arrival-email listener not registered (fulfillment not active?); '
                    . 'deferring arrival notice for order ' . $order->get_id() . '.',
                    $ctx
                );
                return;
            }
        }

        // Identity guard against a dead-booking clear that ran concurrently with this
        // poll. This poll may have fetched the OLD (now-cleared) waybill's status
        // before an operator cleared the dead booking and re-booked. Firing now would
        // set the one-shot flag for a shipment that no longer belongs to the order,
        // suppressing the re-booked parcel's own arrival email. Re-read the order
        // FRESH (the in-memory object predates any concurrent clear) and confirm its
        // booking still points at the waybill we actually polled. The clear blanks
        // the tracking ref, so a mismatch means "cleared — do not fire".
        $fresh = wc_get_order( $order->get_id() );
        if ( ! $fresh || (string) $fresh->get_meta( ES_TCG_Locker_Booking::M_TRACKING_REF, true ) !== (string) $waybill ) {
            return;
        }
        $order = $fresh;

        // Atomically claim the one-shot notification. The row/bulk/AJAX re-poll paths
        // call poll_single_order() directly and bypass the global poll mutex, so two
        // requests can both read the meta flag as unset above — this INSERT IGNORE
        // lets exactly one win. Never released, so it is a permanent claim; the meta
        // flag below is the durable, human-visible record. "At most once": a claimed
        // request that then crashes before sending forgoes the email rather than
        // risk a duplicate, which is the right trade for a customer notification.
        $claim_key = ES_TCG_Locker_Booking::arrival_claim_key( $order->get_id() );
        if ( ! ES_Option_Mutex::acquire( $claim_key, 'notify', DAY_IN_SECONDS ) ) {
            return; // Another request already owns this notification.
        }

        // Re-verify identity AFTER acquiring — the check above and this acquire are
        // not atomic, so a dead-booking clear could have committed its blank waybill
        // and freed the old claim in between, letting us acquire a claim for a
        // shipment that no longer belongs to the order. Left standing, that claim
        // would also block the re-booked parcel's own notification. Re-read fresh;
        // on mismatch, release the claim we just took and bail.
        $recheck = wc_get_order( $order->get_id() );
        if ( ! $recheck || (string) $recheck->get_meta( ES_TCG_Locker_Booking::M_TRACKING_REF, true ) !== (string) $waybill ) {
            delete_option( $claim_key );
            return;
        }
        $order = $recheck;

        $order->update_meta_data( '_es_tcg_locker_in_locker_at', $ts );
        $order->update_meta_data( '_es_tcg_locker_arrival_notified', 1 );
        $order->save();

        if ( $lapsed ) {
            $logger->warning(
                'TCG Locker: collection window already lapsed for order ' . $order->get_id()
                . ' (arrived ' . gmdate( 'c', $ts ) . '); arrival notice suppressed.',
                $ctx
            );
            return;
        }

        do_action( 'es_tcg_locker_arrived', $order->get_id(), $order );
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
