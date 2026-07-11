<?php
/**
 * TCG Locker admin order panel + manual, idempotent booking + label proxy.
 *
 * Thin WordPress/WooCommerce glue around the pure decision logic in
 * ES_TCG_Locker_Booking. Reads the checkout quote snapshot that WooCommerce
 * persisted from the `_locker` rate's meta_data onto the order shipping item
 * (HPOS-safe via $order->get_items('shipping')), and lets a fulfilment operator
 * book the shipment ONCE, deliberately, from the order edit screen.
 *
 * The booking action is guarded on every axis that matters for a money-spending,
 * non-idempotent provider call:
 *   - capability check (fulfilment role),
 *   - per-order nonce,
 *   - an atomic per-order mutex (no double-click double-book),
 *   - a durable duplicate-guard on the stored shipment id,
 *   - a fresh-rate drift refusal (cache-bypassing re-quote must match checkout),
 *   - and an AMBIGUOUS terminal state that blocks any further automatic booking.
 * Nothing here books automatically; there is no checkout-time or cron booking.
 *
 * Labels (waybill/sticker) are streamed through an authenticated admin-ajax
 * proxy so the api_key-bearing provider URL is never exposed to the browser.
 *
 * @package ERPNext_Shipping
 */

defined( 'ABSPATH' ) || exit;

class ES_TCG_Locker_Admin {

	/** Atomic booking mutex: option-name prefix + stale-lock TTL (seconds). */
	const LOCK_PREFIX = 'es_tcg_locker_booking_lock_';
	const LOCK_TTL    = 120;

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ), 10, 2 );
		add_action( 'wp_ajax_es_tcg_locker_book', array( __CLASS__, 'ajax_book' ) );
		add_action( 'wp_ajax_es_tcg_locker_label', array( __CLASS__, 'ajax_label' ) );
	}

	// ─────────────────────────── Capability ────────────────────────────

	private static function current_user_can_book() {
		if ( class_exists( 'ES_Warehouse_Role' ) ) {
			return ES_Warehouse_Role::current_user_can_fulfill();
		}
		return current_user_can( 'manage_woocommerce' );
	}

	// ─────────────────────────── Order reads ───────────────────────────

	/**
	 * Read the TCG Locker checkout quote snapshot from the order's shipping item.
	 * HPOS-safe: iterates the order's shipping line items and returns the meta of
	 * the one carrying a persisted service code (WooCommerce copies all `_locker`
	 * rate meta_data onto the shipping item at order creation). Returns null when
	 * the order was not shipped via TCG Locker.
	 *
	 * @param WC_Order $order
	 * @return array|null Map of ES_TCG_Locker_Rate::M_* keys → string values.
	 */
	public static function read_order_locker_meta( $order ) {
		if ( ! $order || ! is_callable( array( $order, 'get_items' ) ) ) {
			return null;
		}
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			$svc = (string) $item->get_meta( ES_TCG_Locker_Rate::M_SERVICE_CODE, true );
			if ( '' === $svc ) {
				continue;
			}
			$keys = array(
				ES_TCG_Locker_Rate::M_DEST_CODE,
				ES_TCG_Locker_Rate::M_DEST_NAME,
				ES_TCG_Locker_Rate::M_DEST_ADDRESS,
				ES_TCG_Locker_Rate::M_DISPATCH_LOC,
				ES_TCG_Locker_Rate::M_SERVICE_CODE,
				ES_TCG_Locker_Rate::M_SERVICE_NAME,
				ES_TCG_Locker_Rate::M_BOX_CODE,
				ES_TCG_Locker_Rate::M_BOX_NAME,
				ES_TCG_Locker_Rate::M_BOX_SIZE,
				ES_TCG_Locker_Rate::M_BOX_DIMS,
				ES_TCG_Locker_Rate::M_BOX_MAX_WT,
				ES_TCG_Locker_Rate::M_PACKED_WEIGHT,
				ES_TCG_Locker_Rate::M_PROVIDER_RATE,
				ES_TCG_Locker_Rate::M_PROVIDER_EX,
				ES_TCG_Locker_Rate::M_CUSTOMER_CHG,
				ES_TCG_Locker_Rate::M_REVISION_ID,
				ES_TCG_Locker_Rate::M_QUOTE_TS,
				ES_TCG_Locker_Rate::M_PRICING_MODE,
			);
			$out = array();
			foreach ( $keys as $k ) {
				$v = $item->get_meta( $k, true );
				if ( '' !== (string) $v ) {
					$out[ $k ] = (string) $v;
				}
			}
			return $out;
		}
		return null;
	}

	// ─────────────────────────── Meta box ──────────────────────────────

	/**
	 * Register the TCG Locker meta box only on orders that were shipped via a
	 * locker rate. $post_or_order is the order object under HPOS and a WP_Post
	 * under the legacy CPT screen.
	 */
	public static function add_meta_box( $post_type, $post_or_order = null ) {
		$order = self::resolve_order( $post_or_order );
		if ( ! $order || null === self::read_order_locker_meta( $order ) ) {
			return;
		}
		$hpos   = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' )
			&& wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled();
		$screen = $hpos ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';

		add_meta_box(
			'es-tcg-locker-shipment',
			__( 'TCG Locker Shipment', 'erpnext-shipping' ),
			array( __CLASS__, 'render_meta_box' ),
			$screen,
			'side',
			'default'
		);
	}

	private static function resolve_order( $post_or_order ) {
		if ( $post_or_order instanceof WP_Post ) {
			return wc_get_order( $post_or_order->ID );
		}
		if ( is_object( $post_or_order ) && is_callable( array( $post_or_order, 'get_id' ) ) ) {
			return $post_or_order;
		}
		return null;
	}

	public static function render_meta_box( $post_or_order ) {
		$order = self::resolve_order( $post_or_order );
		if ( ! $order ) {
			return;
		}
		$snap = self::read_order_locker_meta( $order );
		if ( null === $snap ) {
			echo '<p>' . esc_html__( 'This order was not shipped via TCG Locker.', 'erpnext-shipping' ) . '</p>';
			return;
		}

		$order_id     = $order->get_id();
		$state        = (string) $order->get_meta( ES_TCG_Locker_Booking::M_BOOKING_STATE, true );
		$shipment_id  = (string) $order->get_meta( ES_TCG_Locker_Booking::M_SHIPMENT_ID, true );
		$tracking_ref = (string) $order->get_meta( ES_TCG_Locker_Booking::M_TRACKING_REF, true );
		$last_error   = (string) $order->get_meta( ES_TCG_Locker_Booking::M_LAST_ERROR, true );
		$guard        = ES_TCG_Locker_Booking::guard( $shipment_id, $state );

		$g = function ( $k ) use ( $snap ) {
			return isset( $snap[ $k ] ) ? $snap[ $k ] : '';
		};
		?>
		<div class="es-tcg-locker-box">
			<p style="margin:0 0 8px;">
				<strong><?php echo esc_html( $g( ES_TCG_Locker_Rate::M_DEST_NAME ) ); ?></strong>
				<?php if ( '' !== $g( ES_TCG_Locker_Rate::M_DEST_CODE ) ) : ?>
					<span style="color:#888;">(<?php echo esc_html( $g( ES_TCG_Locker_Rate::M_DEST_CODE ) ); ?>)</span>
				<?php endif; ?>
				<?php if ( '' !== $g( ES_TCG_Locker_Rate::M_DEST_ADDRESS ) ) : ?>
					<br><span style="color:#666;font-size:.9em;"><?php echo esc_html( $g( ES_TCG_Locker_Rate::M_DEST_ADDRESS ) ); ?></span>
				<?php endif; ?>
			</p>
			<table class="widefat striped" style="margin-bottom:10px;">
				<tbody>
					<tr><td><?php esc_html_e( 'Service', 'erpnext-shipping' ); ?></td><td><?php echo esc_html( $g( ES_TCG_Locker_Rate::M_SERVICE_CODE ) ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Box', 'erpnext-shipping' ); ?></td><td><?php echo esc_html( trim( $g( ES_TCG_Locker_Rate::M_BOX_SIZE ) . ' ' . $g( ES_TCG_Locker_Rate::M_BOX_DIMS ) ) ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Customer charge', 'erpnext-shipping' ); ?></td><td>R<?php echo esc_html( $g( ES_TCG_Locker_Rate::M_CUSTOMER_CHG ) ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Provider (incl VAT)', 'erpnext-shipping' ); ?></td><td>R<?php echo esc_html( $g( ES_TCG_Locker_Rate::M_PROVIDER_RATE ) ); ?></td></tr>
					<?php if ( '' !== $g( ES_TCG_Locker_Rate::M_QUOTE_TS ) ) : ?>
						<tr><td><?php esc_html_e( 'Quoted', 'erpnext-shipping' ); ?></td><td><?php echo esc_html( date_i18n( 'M j, g:i a', (int) $g( ES_TCG_Locker_Rate::M_QUOTE_TS ) ) ); ?></td></tr>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( ES_TCG_Locker_Booking::STATE_BOOKED === $state && '' !== $shipment_id ) : ?>
				<p style="margin:0 0 8px;">
					<span class="dashicons dashicons-yes-alt" style="color:#46b450;vertical-align:middle;"></span>
					<strong><?php esc_html_e( 'Shipment booked', 'erpnext-shipping' ); ?></strong><br>
					<span style="color:#666;"><?php esc_html_e( 'Shipment', 'erpnext-shipping' ); ?>: <?php echo esc_html( $shipment_id ); ?></span>
					<?php if ( '' !== $tracking_ref ) : ?>
						<br><span style="color:#666;"><?php esc_html_e( 'Tracking', 'erpnext-shipping' ); ?>: <?php echo esc_html( $tracking_ref ); ?></span>
					<?php endif; ?>
				</p>
				<?php
				$label_nonce = wp_create_nonce( 'es_tcg_locker_label_' . $order_id );
				$wb  = admin_url( 'admin-ajax.php?action=es_tcg_locker_label&kind=waybill&order_id=' . $order_id . '&_wpnonce=' . $label_nonce );
				$stk = admin_url( 'admin-ajax.php?action=es_tcg_locker_label&kind=sticker&order_id=' . $order_id . '&_wpnonce=' . $label_nonce );
				?>
				<p>
					<a href="<?php echo esc_url( $wb ); ?>" target="_blank" class="button" style="width:49%;text-align:center;"><?php esc_html_e( 'Waybill', 'erpnext-shipping' ); ?></a>
					<a href="<?php echo esc_url( $stk ); ?>" target="_blank" class="button" style="width:49%;text-align:center;"><?php esc_html_e( 'Sticker', 'erpnext-shipping' ); ?></a>
				</p>
			<?php elseif ( ES_TCG_Locker_Booking::STATE_AMBIGUOUS === $state ) : ?>
				<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:4px;padding:8px 10px;font-size:12px;color:#856404;">
					<strong><?php esc_html_e( 'Booking outcome unknown', 'erpnext-shipping' ); ?></strong><br>
					<?php esc_html_e( 'A previous attempt could not be confirmed and may already have created a shipment at TCG. Automatic booking is blocked. Check the TCG portal; if no shipment exists, clear the state below before retrying.', 'erpnext-shipping' ); ?>
					<?php if ( '' !== $last_error ) : ?>
						<br><em><?php echo esc_html( $last_error ); ?></em>
					<?php endif; ?>
				</div>
				<p style="margin-top:8px;">
					<button type="button" class="button es-tcg-clear-ambiguous" data-order="<?php echo esc_attr( $order_id ); ?>" style="width:100%;">
						<?php esc_html_e( 'I checked the portal — clear & allow re-book', 'erpnext-shipping' ); ?>
					</button>
				</p>
			<?php else : ?>
				<?php if ( ES_TCG_Locker_Booking::STATE_FAILED === $state && '' !== $last_error ) : ?>
					<p style="color:#b32d2e;font-size:12px;margin:0 0 6px;">
						<?php esc_html_e( 'Last attempt failed', 'erpnext-shipping' ); ?>: <?php echo esc_html( $last_error ); ?>
					</p>
				<?php endif; ?>
				<button type="button" class="button button-primary es-tcg-book" data-order="<?php echo esc_attr( $order_id ); ?>" style="width:100%;" <?php disabled( ! $guard['can'] ); ?>>
					<?php esc_html_e( 'Book TCG Locker Shipment', 'erpnext-shipping' ); ?>
				</button>
			<?php endif; ?>
		</div>

		<script>
		jQuery(function($){
			var nonce = <?php echo wp_json_encode( wp_create_nonce( 'es_tcg_locker_book_' . $order_id ) ); ?>;
			$('.es-tcg-book, .es-tcg-clear-ambiguous').on('click', function(){
				var $btn = $(this);
				var clear = $btn.hasClass('es-tcg-clear-ambiguous');
				if (clear && !window.confirm(<?php echo wp_json_encode( esc_html__( 'Only do this if the TCG portal shows NO shipment for this order. Continue?', 'erpnext-shipping' ) ); ?>)) { return; }
				$btn.prop('disabled', true).text(<?php echo wp_json_encode( esc_html__( 'Working…', 'erpnext-shipping' ) ); ?>);
				$.post(ajaxurl, {
					action: 'es_tcg_locker_book',
					_wpnonce: nonce,
					order_id: $btn.data('order'),
					clear_ambiguous: clear ? 1 : 0
				}, function(r){
					if (r && r.success) { location.reload(); return; }
					window.alert(r && r.data && r.data.message ? r.data.message : <?php echo wp_json_encode( esc_html__( 'Booking failed.', 'erpnext-shipping' ) ); ?>);
					$btn.prop('disabled', false);
					location.reload();
				});
			});
		});
		</script>
		<?php
	}

	// ─────────────────────────── Booking AJAX ──────────────────────────

	public static function ajax_book() {
		$order_id = intval( $_POST['order_id'] ?? 0 );

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'es_tcg_locker_book_' . $order_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Reload the order and try again.', 'erpnext-shipping' ) ) );
		}
		if ( ! self::current_user_can_book() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to book shipments.', 'erpnext-shipping' ) ) );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'erpnext-shipping' ) ) );
		}

		// Atomic mutex — a second concurrent request cannot enter the booking body.
		if ( ! self::acquire_lock( $order_id ) ) {
			wp_send_json_error( array( 'message' => __( 'A booking is already in progress for this order.', 'erpnext-shipping' ) ) );
		}
		// Every exit below is a wp_send_json_*/wp_die → exit(), which bypasses a
		// `finally`. A shutdown callback releases the lock on EVERY exit path
		// (success, refusal, or fatal) so a held lock can't strand the order.
		register_shutdown_function( array( __CLASS__, 'release_lock' ), $order_id );

		{
			$clear = ! empty( $_POST['clear_ambiguous'] );
			$state = (string) $order->get_meta( ES_TCG_Locker_Booking::M_BOOKING_STATE, true );

			// Operator-confirmed reconciliation: clear an AMBIGUOUS state (only) so a
			// re-book is permitted. Never clears a real shipment id.
			if ( $clear ) {
				$shipment_id = (string) $order->get_meta( ES_TCG_Locker_Booking::M_SHIPMENT_ID, true );
				if ( '' !== $shipment_id ) {
					wp_send_json_error( array( 'message' => __( 'This order already has a booked shipment; nothing to clear.', 'erpnext-shipping' ) ) );
				}
				if ( ES_TCG_Locker_Booking::STATE_AMBIGUOUS !== $state ) {
					wp_send_json_error( array( 'message' => __( 'Nothing to clear.', 'erpnext-shipping' ) ) );
				}
				$order->update_meta_data( ES_TCG_Locker_Booking::M_BOOKING_STATE, ES_TCG_Locker_Booking::STATE_NONE );
				$order->update_meta_data( ES_TCG_Locker_Booking::M_LAST_ERROR, '' );
				$order->add_order_note( __( 'TCG Locker: ambiguous booking state cleared after manual portal check — re-book allowed.', 'erpnext-shipping' ), 0 );
				$order->save();
				wp_send_json_success( array( 'message' => __( 'Cleared. You can attempt booking again.', 'erpnext-shipping' ) ) );
			}

			// Durable duplicate / ambiguous guard (survives even a lost mutex).
			$guard = ES_TCG_Locker_Booking::guard(
				(string) $order->get_meta( ES_TCG_Locker_Booking::M_SHIPMENT_ID, true ),
				$state
			);
			if ( ! $guard['can'] ) {
				$msg = 'already_booked' === $guard['reason']
					? __( 'This order already has a booked TCG Locker shipment.', 'erpnext-shipping' )
					: __( 'A previous attempt was inconclusive; check the TCG portal and clear the state before retrying.', 'erpnext-shipping' );
				wp_send_json_error( array( 'message' => $msg ) );
			}

			$snap = self::read_order_locker_meta( $order );
			if ( null === $snap || empty( $snap[ ES_TCG_Locker_Rate::M_SERVICE_CODE ] ) || empty( $snap[ ES_TCG_Locker_Rate::M_DEST_CODE ] ) ) {
				wp_send_json_error( array( 'message' => __( 'This order has no TCG Locker quote to book from.', 'erpnext-shipping' ) ) );
			}

			$client = function_exists( 'es_tcg_locker_client' ) ? es_tcg_locker_client() : null;
			if ( ! $client ) {
				wp_send_json_error( array( 'message' => __( 'TCG Locker is not configured.', 'erpnext-shipping' ) ) );
			}

			// Fresh, cache-bypassing re-quote → refuse if the quote drifted from
			// what the customer was charged at checkout.
			$fresh = $client->get_rates( $snap[ ES_TCG_Locker_Rate::M_DEST_CODE ], true );
			$offers = ( ! empty( $fresh['ok'] ) && ! empty( $fresh['offers'] ) ) ? $fresh['offers'] : array();
			$drift  = ES_TCG_Locker_Booking::detect_drift( $snap, $offers );
			if ( $drift['drift'] ) {
				$order->update_meta_data( ES_TCG_Locker_Booking::M_LAST_ERROR, 'drift:' . $drift['reason'] );
				$order->save();
				wp_send_json_error( array(
					'message' => sprintf(
						/* translators: %s: drift reason code. */
						__( 'The live TCG Locker quote no longer matches checkout (%s). Nothing was booked — re-quote the order first.', 'erpnext-shipping' ),
						$drift['reason']
					),
				) );
			}

			// Book exactly once.
			$res = $client->create_shipment( array(
				'dest_terminal_id'        => $snap[ ES_TCG_Locker_Rate::M_DEST_CODE ],
				'service_level_code'      => $snap[ ES_TCG_Locker_Rate::M_SERVICE_CODE ],
				'collection_contact'      => self::collection_contact( $snap ),
				'delivery_contact'        => self::delivery_contact( $order ),
				'collection_instructions' => 'None',
				'customer_reference'      => $order->get_order_number(),
			) );

			$outcome = ES_TCG_Locker_Booking::classify_result( $res );

			$order->update_meta_data( ES_TCG_Locker_Booking::M_BOOKING_STATE, $outcome['state'] );
			$order->update_meta_data( ES_TCG_Locker_Booking::M_LAST_ERROR, $outcome['error'] );
			if ( ES_TCG_Locker_Booking::STATE_BOOKED === $outcome['state'] ) {
				$order->update_meta_data( ES_TCG_Locker_Booking::M_SHIPMENT_ID, $outcome['shipment_id'] );
				$order->update_meta_data( ES_TCG_Locker_Booking::M_TRACKING_REF, $outcome['tracking_ref'] );
				$order->update_meta_data( ES_TCG_Locker_Booking::M_BOOKED_AT, time() );
				$order->add_order_note( sprintf(
					/* translators: 1: shipment id, 2: tracking reference. */
					__( 'TCG Locker shipment booked. Shipment %1$s, tracking %2$s.', 'erpnext-shipping' ),
					$outcome['shipment_id'],
					'' !== $outcome['tracking_ref'] ? $outcome['tracking_ref'] : '—'
				), 0 );
			} elseif ( ES_TCG_Locker_Booking::STATE_AMBIGUOUS === $outcome['state'] ) {
				$order->add_order_note( sprintf(
					/* translators: %s: error reason code. */
					__( 'TCG Locker booking INCONCLUSIVE (%s). A shipment may exist at TCG — check the portal before retrying. No automatic retry.', 'erpnext-shipping' ),
					$outcome['error']
				), 0 );
			} else {
				$order->add_order_note( sprintf(
					/* translators: %s: error reason code. */
					__( 'TCG Locker booking failed (%s). Nothing was created; safe to retry.', 'erpnext-shipping' ),
					$outcome['error']
				), 0 );
			}
			$order->save();

			if ( ES_TCG_Locker_Booking::STATE_BOOKED === $outcome['state'] ) {
				wp_send_json_success( array( 'message' => __( 'Shipment booked.', 'erpnext-shipping' ) ) );
			}
			if ( ES_TCG_Locker_Booking::STATE_AMBIGUOUS === $outcome['state'] ) {
				wp_send_json_error( array( 'message' => __( 'The booking could not be confirmed. Check the TCG portal before retrying — it was not auto-retried.', 'erpnext-shipping' ) ) );
			}
			wp_send_json_error( array( 'message' => __( 'Booking failed. You can try again.', 'erpnext-shipping' ) ) );
		}
	}

	// ─────────────────────────── Label proxy ───────────────────────────

	/**
	 * Authenticated waybill/sticker proxy. Streams the provider PDF server-side so
	 * the api_key-bearing label URL is never exposed to the browser or logged.
	 */
	public static function ajax_label() {
		$order_id = intval( $_GET['order_id'] ?? 0 );
		$kind     = sanitize_text_field( wp_unslash( $_GET['kind'] ?? '' ) );

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'es_tcg_locker_label_' . $order_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'erpnext-shipping' ), '', array( 'response' => 403 ) );
		}
		if ( ! self::current_user_can_book() ) {
			wp_die( esc_html__( 'Permission denied.', 'erpnext-shipping' ), '', array( 'response' => 403 ) );
		}
		if ( ! in_array( $kind, array( 'waybill', 'sticker' ), true ) ) {
			wp_die( esc_html__( 'Invalid label type.', 'erpnext-shipping' ), '', array( 'response' => 400 ) );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', 'erpnext-shipping' ), '', array( 'response' => 404 ) );
		}
		$shipment_id = (string) $order->get_meta( ES_TCG_Locker_Booking::M_SHIPMENT_ID, true );
		if ( '' === $shipment_id ) {
			wp_die( esc_html__( 'This order has no booked TCG Locker shipment.', 'erpnext-shipping' ), '', array( 'response' => 404 ) );
		}
		$client = function_exists( 'es_tcg_locker_client' ) ? es_tcg_locker_client() : null;
		if ( ! $client ) {
			wp_die( esc_html__( 'TCG Locker is not configured.', 'erpnext-shipping' ), '', array( 'response' => 500 ) );
		}

		$res = $client->fetch_label( $kind, $shipment_id );
		if ( empty( $res['ok'] ) ) {
			wp_die( esc_html__( 'Could not retrieve the label from TCG.', 'erpnext-shipping' ), '', array( 'response' => 502 ) );
		}

		nocache_headers();
		header( 'Content-Type: ' . ( $res['content_type'] ?: 'application/pdf' ) );
		header( 'Content-Disposition: inline; filename="tcg-locker-' . $kind . '-' . sanitize_file_name( $shipment_id ) . '.pdf"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $res['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw PDF bytes.
		exit;
	}

	// ─────────────────────────── Contacts ──────────────────────────────

	private static function delivery_contact( $order ) {
		$name = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
		if ( '' === $name ) {
			$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		}
		return array(
			'name'          => $name,
			'email'         => $order->get_billing_email(),
			'mobile_number' => $order->get_billing_phone(),
		);
	}

	private static function collection_contact( $snap ) {
		$loc_id = $snap[ ES_TCG_Locker_Rate::M_DISPATCH_LOC ] ?? '';
		$name   = get_bloginfo( 'name' );
		$phone  = '';
		if ( '' !== (string) $loc_id ) {
			foreach ( (array) get_option( 'es_shipping_locations', array() ) as $loc ) {
				if ( is_array( $loc ) && ( $loc['id'] ?? '' ) === $loc_id ) {
					$name  = $loc['name'] ?? $name;
					$phone = $loc['phone'] ?? ( $loc['contact_phone'] ?? '' );
					break;
				}
			}
		}
		return array(
			'name'          => $name,
			'email'         => get_option( 'admin_email' ),
			'mobile_number' => $phone,
		);
	}

	// ─────────────────────────── Mutex ─────────────────────────────────

	/**
	 * Atomic per-order booking lock. add_option() performs an INSERT against the
	 * UNIQUE option_name index, so exactly one concurrent caller wins. A lock left
	 * behind by a crashed request is broken after LOCK_TTL.
	 */
	private static function acquire_lock( $order_id ) {
		$key = self::LOCK_PREFIX . (int) $order_id;
		$now = time();
		if ( add_option( $key, $now, '', 'no' ) ) {
			return true;
		}
		$held = (int) get_option( $key, 0 );
		if ( $held && ( $now - $held ) > self::LOCK_TTL ) {
			update_option( $key, $now, false );
			return true;
		}
		return false;
	}

	/** Public so the booking shutdown callback can release from outside scope. */
	public static function release_lock( $order_id ) {
		delete_option( self::LOCK_PREFIX . (int) $order_id );
	}
}
