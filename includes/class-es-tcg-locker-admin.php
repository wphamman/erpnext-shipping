<?php
/**
 * TCG Locker admin order panel + manual, idempotent booking + label proxy.
 *
 * Thin WordPress/WooCommerce glue around the pure decision logic in
 * ES_TCG_Locker_Booking. Reads the checkout quote snapshot that WooCommerce
 * persisted from the `_locker` rate's meta_data onto the order shipping item
 * (HPOS-safe via $order->get_items('shipping')), and lets a booking-capable
 * operator book the shipment ONCE, deliberately, from the order edit screen.
 *
 * The booking action is guarded on every axis that matters for a money-spending,
 * non-idempotent provider call:
 *   - capability check (filterable booking cap),
 *   - per-order nonce,
 *   - an atomic, ownership-tokened per-order mutex (no double-click double-book),
 *   - a DURABLE in-progress ('booking') state saved BEFORE the provider call, so a
 *     crash mid-call cannot be silently re-booked (guard blocks 'booking'),
 *   - a durable duplicate-guard on the stored shipment id,
 *   - full pre-book re-validation (paid, destination locker, dispatch origin, and
 *     the CURRENT order still fits the persisted box),
 *   - a fresh-rate drift refusal (cache-bypassing re-quote must match checkout),
 *   - and an AMBIGUOUS terminal state that blocks any further automatic booking.
 * Nothing here books automatically; there is no checkout-time or cron booking.
 *
 * Labels (waybill/sticker) are streamed through an authenticated admin-ajax
 * proxy that verifies a genuine PDF and forces a fixed safe content type, so the
 * api_key-bearing provider URL is never exposed and a non-PDF provider response
 * can never execute as same-origin HTML.
 *
 * @package ERPNext_Shipping
 */

defined( 'ABSPATH' ) || exit;

class ES_TCG_Locker_Admin {

	/** Atomic booking mutex option-name prefix. */
	const LOCK_PREFIX = 'es_tcg_locker_booking_lock_';

	/** AST tracking provider slug for booked locker shipments (Phase-5 poll target). */
	const TRACKING_PROVIDER = 'tcg-locker';

	public static function init() {
		// Controls are rendered inside the unified Shipment Tracking meta box by
		// ES_Carrier_Booking_Admin. Keep only the hardened AJAX endpoints here.
		add_action( 'wp_ajax_es_tcg_locker_book', array( __CLASS__, 'ajax_book' ) );
		add_action( 'wp_ajax_es_tcg_locker_label', array( __CLASS__, 'ajax_label' ) );
	}

	// ─────────────────────────── Capability ────────────────────────────

	/**
	 * Booking capability. Defaults to manage_woocommerce and is filterable so a
	 * store can restrict booking to specific roles. Booking spends money, so it is
	 * intentionally a manager-level gate. NOTE: a dedicated `es_tcg_locker_book`
	 * capability provisioned away from warehouse-only staff is deferred to Phase 6
	 * (role work) — until then, filter this to tighten.
	 */
	private static function book_capability() {
		return apply_filters( 'es_tcg_locker_book_capability', 'manage_woocommerce' );
	}

	private static function current_user_can_book() {
		return current_user_can( self::book_capability() );
	}

	/**
	 * Capability to print/download labels. Separate from booking: printing a label
	 * does not spend money, so warehouse staff may print even where they should not
	 * book. Defaults to the fulfilment check; filterable to a specific capability.
	 */
	private static function current_user_can_labels() {
		$cap = apply_filters( 'es_tcg_locker_label_capability', '' );
		if ( '' !== $cap ) {
			return current_user_can( $cap );
		}
		return class_exists( 'ES_Warehouse_Role' )
			? ES_Warehouse_Role::current_user_can_fulfill()
			: current_user_can( 'manage_woocommerce' );
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
		$status       = (string) $order->get_meta( ES_TCG_Locker_Booking::M_BOOKING_STATUS, true );
		$shipment_id  = (string) $order->get_meta( ES_TCG_Locker_Booking::M_SHIPMENT_ID, true );
		$tracking_ref = (string) $order->get_meta( ES_TCG_Locker_Booking::M_TRACKING_REF, true );
		$last_error   = (string) $order->get_meta( ES_TCG_Locker_Booking::M_LAST_ERROR, true );
		$guard        = ES_TCG_Locker_Booking::guard( $shipment_id, $status );
		// Offer the recover/clear affordance when a prior attempt is stuck (blocking
		// status) OR a lock was stranded by a hard crash (stale lock, even if the
		// status never reached 'booking').
		$stale_lock   = self::lock_state( $order_id )['stale'];
		$needs_review = '' === $shipment_id && ( ES_TCG_Locker_Booking::is_blocking_status( $status ) || $stale_lock );

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

			<?php if ( ES_TCG_Locker_Booking::STATE_BOOKED === $status && '' !== $shipment_id ) : ?>
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
			<?php elseif ( $needs_review ) : ?>
				<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:4px;padding:8px 10px;font-size:12px;color:#856404;">
					<strong><?php esc_html_e( 'Booking outcome unknown', 'erpnext-shipping' ); ?></strong><br>
					<?php esc_html_e( 'A previous attempt could not be confirmed (or was interrupted) and may already have created a shipment at TCG. Automatic booking is blocked. Check the TCG portal; if no shipment exists, clear the state below before retrying.', 'erpnext-shipping' ); ?>
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
				<?php if ( ES_TCG_Locker_Booking::STATE_ERROR === $status && '' !== $last_error ) : ?>
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

		// The clear/reconcile action must NOT require the booking lock — otherwise a
		// lock stuck by a hard crash would deadlock its own recovery. It force-
		// releases any stale lock as part of clearing.
		if ( ! empty( $_POST['clear_ambiguous'] ) ) {
			self::handle_clear( $order );
			// handle_clear() always exits via wp_send_json_*.
		}

		// ONE settings snapshot drives both the lock lease AND the booking client, so
		// they cannot disagree, and a concurrent settings change mid-booking cannot
		// retroactively shrink this request's lease.
		$opts    = function_exists( 'es_tcg_locker_settings' ) ? es_tcg_locker_settings() : array();
		$timeout = (int) ( $opts['tcg_locker_rate_timeout'] ?? 0 );

		// Atomic, ownership-tokened mutex — a second concurrent request cannot enter
		// the booking body. The lock stores an ABSOLUTE expiry computed now from this
		// snapshot, so staleness is judged against an immutable lease (see acquire_lock).
		$token = self::mint_token();
		if ( ! self::acquire_lock( $order_id, $token, ES_TCG_Locker_Booking::lock_stale_threshold( $timeout ) ) ) {
			wp_send_json_error( array( 'message' => __( 'A booking is already in progress for this order.', 'erpnext-shipping' ) ) );
		}
		// Every exit below is a wp_send_json_*/wp_die → exit(), which bypasses a
		// `finally`. A shutdown callback releases OUR lock (ownership-checked) on
		// every exit path — success, refusal, or fatal — so a held lock can't
		// strand the order.
		register_shutdown_function( array( __CLASS__, 'release_lock' ), $order_id, $token );

		// Durable duplicate / in-progress / ambiguous guard (survives a lost mutex).
		$guard = ES_TCG_Locker_Booking::guard(
			(string) $order->get_meta( ES_TCG_Locker_Booking::M_SHIPMENT_ID, true ),
			(string) $order->get_meta( ES_TCG_Locker_Booking::M_BOOKING_STATUS, true )
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

		// Same snapshot as the lock lease — the client's per-call timeout and the
		// lease are guaranteed consistent.
		$client = function_exists( 'es_tcg_locker_client' ) ? es_tcg_locker_client( $opts ) : null;
		if ( ! $client ) {
			wp_send_json_error( array( 'message' => __( 'TCG Locker is not configured.', 'erpnext-shipping' ) ) );
		}

		// Pre-book re-validation: paid/processable, destination locker still valid,
		// dispatch origin still enabled, and the CURRENT order still fits the
		// persisted box (an order edited heavier/larger after checkout must not book
		// against a stale snapshot).
		$bad = self::validate_bookable( $order, $snap, $client, $opts );
		if ( '' !== $bad ) {
			wp_send_json_error( array( 'message' => self::validation_message( $bad ) ) );
		}

		// Fresh, cache-bypassing re-quote → refuse if the quote drifted from what
		// the customer was charged at checkout.
		$fresh  = $client->get_rates( $snap[ ES_TCG_Locker_Rate::M_DEST_CODE ], true );
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

		// DURABLE in-progress marker saved BEFORE the irreversible call. If PHP dies
		// after the provider accepts but before we record the outcome, this 'booking'
		// state persists and guard() blocks any silent re-book until a human clears it.
		$order->update_meta_data( ES_TCG_Locker_Booking::M_BOOKING_STATUS, ES_TCG_Locker_Booking::STATE_BOOKING );
		$order->save();

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

		$order->update_meta_data( ES_TCG_Locker_Booking::M_BOOKING_STATUS, $outcome['state'] );
		$order->update_meta_data( ES_TCG_Locker_Booking::M_LAST_ERROR, $outcome['error'] );

		if ( ES_TCG_Locker_Booking::STATE_BOOKED === $outcome['state'] ) {
			$order->update_meta_data( ES_TCG_Locker_Booking::M_SHIPMENT_ID, $outcome['shipment_id'] );
			$order->update_meta_data( ES_TCG_Locker_Booking::M_TRACKING_REF, $outcome['tracking_ref'] );
			$order->update_meta_data( ES_TCG_Locker_Booking::M_BOOKED_TS, time() );
			$order->add_order_note( sprintf(
				/* translators: 1: shipment id, 2: tracking reference. */
				__( 'TCG Locker shipment booked. Shipment %1$s, tracking %2$s.', 'erpnext-shipping' ),
				$outcome['shipment_id'],
				'' !== $outcome['tracking_ref'] ? $outcome['tracking_ref'] : '—'
			), 0 );
			// Append one AST-compatible tracking item (idempotent) so the Phase-5
			// poller always has a target — fall back to the shipment id when the
			// provider returned no tracking reference, so a booked shipment is never
			// left unpollable. save_tracking_item() persists the order (incl. the
			// booking meta set above).
			$track_id = '' !== $outcome['tracking_ref'] ? $outcome['tracking_ref'] : $outcome['shipment_id'];
			if ( '' !== $track_id && class_exists( 'ES_Fulfillment_Tracking' ) ) {
				self::append_tracking_once( $order, $track_id );
			} else {
				$order->save();
			}
			wp_send_json_success( array( 'message' => __( 'Shipment booked.', 'erpnext-shipping' ) ) );
		}

		if ( ES_TCG_Locker_Booking::STATE_AMBIGUOUS === $outcome['state'] ) {
			$order->add_order_note( sprintf(
				/* translators: %s: error reason code. */
				__( 'TCG Locker booking INCONCLUSIVE (%s). A shipment may exist at TCG — check the portal before retrying. No automatic retry.', 'erpnext-shipping' ),
				$outcome['error']
			), 0 );
			$order->save();
			wp_send_json_error( array( 'message' => __( 'The booking could not be confirmed. Check the TCG portal before retrying — it was not auto-retried.', 'erpnext-shipping' ) ) );
		}

		$order->add_order_note( sprintf(
			/* translators: %s: error reason code. */
			__( 'TCG Locker booking failed (%s). Nothing was created; safe to retry.', 'erpnext-shipping' ),
			$outcome['error']
		), 0 );
		$order->save();
		wp_send_json_error( array( 'message' => __( 'Booking failed. You can try again.', 'erpnext-shipping' ) ) );
	}

	/**
	 * Operator-confirmed reconciliation: clear a stuck 'booking'/'ambiguous' state
	 * (or a crash-stranded lock) so a re-book is permitted. Never clears a real
	 * shipment id. Always exits via wp_send_json_*.
	 *
	 * CRUCIALLY it refuses while the lock is unexpired — an unexpired lock means a
	 * booking request is genuinely in flight right now (the lock's stored expiry was
	 * fixed from the snapshot at acquisition to an enforced upper bound on the whole
	 * booking path, so a live request's lease has not yet passed), so clearing would
	 * let a second booking run against a live provider call. It only proceeds when
	 * the lock is absent or expired (holder presumed dead), and only then releases it.
	 */
	private static function handle_clear( $order ) {
		$shipment_id = (string) $order->get_meta( ES_TCG_Locker_Booking::M_SHIPMENT_ID, true );
		if ( '' !== $shipment_id ) {
			wp_send_json_error( array( 'message' => __( 'This order already has a booked shipment; nothing to clear.', 'erpnext-shipping' ) ) );
		}
		$lock = self::lock_state( $order->get_id() );
		if ( $lock['present'] && ! $lock['stale'] ) {
			wp_send_json_error( array( 'message' => __( 'A booking is currently in progress for this order. Wait a moment, then reload before clearing.', 'erpnext-shipping' ) ) );
		}
		$status = (string) $order->get_meta( ES_TCG_Locker_Booking::M_BOOKING_STATUS, true );
		if ( ! ES_TCG_Locker_Booking::is_blocking_status( $status ) && ! $lock['stale'] ) {
			wp_send_json_error( array( 'message' => __( 'Nothing to clear.', 'erpnext-shipping' ) ) );
		}
		$order->update_meta_data( ES_TCG_Locker_Booking::M_BOOKING_STATUS, ES_TCG_Locker_Booking::STATE_NONE );
		$order->update_meta_data( ES_TCG_Locker_Booking::M_LAST_ERROR, '' );
		$order->add_order_note( __( 'TCG Locker: booking state cleared after manual portal check — re-book allowed.', 'erpnext-shipping' ), 0 );
		$order->save();
		self::force_release_lock( $order->get_id() ); // Safe: lock is absent or stale (verified above).
		wp_send_json_success( array( 'message' => __( 'Cleared. You can attempt booking again.', 'erpnext-shipping' ) ) );
	}

	// ─────────────────────────── Validation ────────────────────────────

	/**
	 * Re-validate an order is bookable RIGHT NOW. Returns '' when OK, else a short
	 * reason code (see validation_message()).
	 */
	private static function validate_bookable( $order, $snap, $client, $opts ) {
		if ( ! $order->has_status( wc_get_is_paid_statuses() ) ) {
			return 'not_paid';
		}
		if ( ! $client->get_locker( $snap[ ES_TCG_Locker_Rate::M_DEST_CODE ] ) ) {
			return 'locker_gone';
		}
		if ( ! self::dispatch_origin_enabled( $snap[ ES_TCG_Locker_Rate::M_DISPATCH_LOC ] ?? '' ) ) {
			return 'origin_disabled';
		}
		$lines = self::order_packer_lines( $order, (array) $opts );
		if ( null === $lines ) {
			return 'product_missing';
		}
		$req = ES_TCG_Locker_Packer::compute_requirements( $lines );
		if ( empty( $req['ok'] ) ) {
			return 'not_packable';
		}
		if ( ! ES_TCG_Locker_Packer::fits_box( $req, self::persisted_box( $snap ) ) ) {
			return 'exceeds_box';
		}
		return '';
	}

	private static function validation_message( $code ) {
		$map = array(
			'not_paid'        => __( 'This order is not paid yet — it cannot be booked.', 'erpnext-shipping' ),
			'locker_gone'     => __( 'The chosen locker is no longer available. Ask the customer to pick another.', 'erpnext-shipping' ),
			'origin_disabled' => __( 'The dispatch warehouse is no longer enabled for TCG Locker.', 'erpnext-shipping' ),
			'product_missing' => __( 'A product on this order no longer exists, so its size/weight cannot be verified. Fix the order before booking.', 'erpnext-shipping' ),
			'not_packable'    => __( 'The current order contents cannot be packed into a locker box.', 'erpnext-shipping' ),
			'exceeds_box'     => __( 'The order has changed since checkout and no longer fits the quoted box. Re-quote it.', 'erpnext-shipping' ),
		);
		return $map[ $code ] ?? __( 'This order cannot be booked.', 'erpnext-shipping' );
	}

	private static function dispatch_origin_enabled( $loc_id ) {
		$loc_id = (string) $loc_id;
		if ( '' === $loc_id ) {
			return false;
		}
		foreach ( (array) get_option( 'es_shipping_locations', array() ) as $loc ) {
			if ( is_array( $loc ) && ( $loc['id'] ?? '' ) === $loc_id ) {
				$is_warehouse = ( $loc['type'] ?? 'warehouse' ) === 'warehouse';
				return $is_warehouse && ! empty( $loc['tcg_locker_dispatch_enabled'] );
			}
		}
		return false;
	}

	/**
	 * Build packer lines from the CURRENT order items (mirrors the checkout builder).
	 * Returns null if ANY line's product can no longer be resolved (deleted/trashed
	 * after checkout): its weight and dimensions would silently vanish from the fit
	 * check, letting the remaining lines book an undersized box. Fail closed instead.
	 */
	private static function order_packer_lines( $order, $opts ) {
		$excluded_raw = trim( (string) ( $opts['tcg_locker_excluded_shipping_classes'] ?? '' ) );
		$excluded     = '' === $excluded_raw ? array() : array_map( 'trim', explode( ',', $excluded_raw ) );

		$lines = array();
		foreach ( $order->get_items() as $item ) {
			$product = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : null;
			if ( ! $product ) {
				return null; // Unresolvable product line → cannot certify packing.
			}
			$own_ineligible = ( 'yes' === $product->get_meta( '_es_locker_ineligible' ) );
			$parent_ineligible = false;
			if ( ! $own_ineligible && $product->is_type( 'variation' ) ) {
				$parent = wc_get_product( $product->get_parent_id() );
				if ( $parent && 'yes' === $parent->get_meta( '_es_locker_ineligible' ) ) {
					$parent_ineligible = true;
				}
			}
			$lines[] = array(
				'sku'             => $product->get_sku(),
				'qty'             => $item->get_quantity(),
				'weight'          => floatval( $product->get_weight() ),
				'length'          => floatval( $product->get_length() ),
				'width'           => floatval( $product->get_width() ),
				'height'          => floatval( $product->get_height() ),
				'locker_eligible' => ES_TCG_Locker_Rate::is_line_locker_eligible(
					$own_ineligible,
					$parent_ineligible,
					$product->get_shipping_class(),
					$excluded
				),
			);
		}
		return $lines;
	}

	/** Reconstruct the persisted box (LxWxH + max weight) for fits_box(). */
	private static function persisted_box( $snap ) {
		$dims = explode( 'x', (string) ( $snap[ ES_TCG_Locker_Rate::M_BOX_DIMS ] ?? '' ) );
		$num  = function ( $v ) {
			return is_numeric( $v ) ? (float) $v : null;
		};
		return array(
			'length'     => $num( $dims[0] ?? null ),
			'width'      => $num( $dims[1] ?? null ),
			'height'     => $num( $dims[2] ?? null ),
			'max_weight' => $num( $snap[ ES_TCG_Locker_Rate::M_BOX_MAX_WT ] ?? null ),
		);
	}

	/** Append one AST tracking item for the booked shipment, idempotently. */
	private static function append_tracking_once( $order, $tracking_ref ) {
		foreach ( ES_Fulfillment_Tracking::get_tracking_items( $order ) as $it ) {
			if ( (string) ( $it['tracking_number'] ?? '' ) === (string) $tracking_ref ) {
				$order->save(); // Persist booking meta even when the item already exists.
				return;
			}
		}
		$item = ES_Fulfillment_Tracking::create_tracking_item( self::TRACKING_PROVIDER, $tracking_ref, time() );
		ES_Fulfillment_Tracking::save_tracking_item( $order, $item ); // Saves the order.
	}

	// ─────────────────────────── Label proxy ───────────────────────────

	/**
	 * Authenticated waybill/sticker proxy. Streams the provider PDF server-side so
	 * the api_key-bearing label URL is never exposed to the browser or logged. Only
	 * a genuine PDF is served, under a FIXED application/pdf content type and an
	 * attachment disposition — a non-PDF provider response (e.g. an HTML error or
	 * challenge page) can never execute as same-origin HTML from the admin origin.
	 */
	public static function ajax_label() {
		$order_id = intval( $_GET['order_id'] ?? 0 );
		$kind     = sanitize_text_field( wp_unslash( $_GET['kind'] ?? '' ) );

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'es_tcg_locker_label_' . $order_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'erpnext-shipping' ), '', array( 'response' => 403 ) );
		}
		if ( ! self::current_user_can_labels() ) {
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
		$body = (string) ( $res['body'] ?? '' );
		// Serve ONLY a genuine PDF. Anything else (HTML error/challenge, empty body)
		// is refused so it can never be interpreted as HTML at the admin origin.
		if ( 0 !== strncmp( $body, '%PDF-', 5 ) ) {
			wp_die( esc_html__( 'TCG did not return a valid PDF label.', 'erpnext-shipping' ), '', array( 'response' => 502 ) );
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );                 // Fixed — never the provider's.
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="tcg-locker-' . $kind . '-' . sanitize_file_name( $shipment_id ) . '.pdf"' );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- verified raw PDF bytes.
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

	private static function mint_token() {
		return function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'es', true );
	}

	/**
	 * Acquire the per-order booking lock with a single INSERT IGNORE against the
	 * UNIQUE option_name index, so exactly ONE concurrent caller wins. There is
	 * deliberately NO automatic stale takeover
	 * here: a takeover would need a delete-then-reinsert that two racers could both
	 * win, admitting two owners. So acquire is pure and provably single-owner; a
	 * lock stranded by a hard crash (whose shutdown release did not run) is recovered
	 * ONLY through the staleness-guarded operator clear path (handle_clear()), which
	 * refuses while the lock has not expired (a live holder).
	 *
	 * The value is `token|expiry`, where expiry is an ABSOLUTE deadline fixed FROM
	 * THE SNAPSHOT AT ACQUISITION. Storing the deadline (not the acquire time) means
	 * staleness is judged against an immutable lease — a later settings change that
	 * lowers the API timeout cannot retroactively shrink a live request's lease and
	 * let the operator clear delete its lock.
	 *
	 * @param int $lease_seconds Lease duration for this request (from the snapshot).
	 */
	private static function acquire_lock( $order_id, $token, $lease_seconds ) {
		return ES_Option_Mutex::acquire( self::LOCK_PREFIX . (int) $order_id, $token, $lease_seconds );
	}

	/**
	 * Read the current lock state from the IMMUTABLE stored lease: whether a lock is
	 * present and whether its stored expiry has passed (holder presumed dead). No
	 * recomputation from current settings — the lease is whatever was fixed at
	 * acquisition.
	 *
	 * @return array{present:bool, expiry:int, stale:bool}
	 */
	private static function lock_state( $order_id ) {
		return ES_Option_Mutex::state( self::LOCK_PREFIX . (int) $order_id );
	}

	/**
	 * Release the lock only if WE still hold it (ownership token match), so an
	 * unrelated/newer request's lock can never be deleted. Public so the booking
	 * shutdown callback can invoke it from outside class scope.
	 */
	public static function release_lock( $order_id, $token ) {
		return ES_Option_Mutex::release( self::LOCK_PREFIX . (int) $order_id, $token );
	}

	/**
	 * Unconditional release — used only by the operator clear/reconcile path, and
	 * only AFTER handle_clear() has confirmed the lock is stale/absent (never while
	 * a live holder still owns it).
	 */
	private static function force_release_lock( $order_id ) {
		delete_option( self::LOCK_PREFIX . (int) $order_id );
	}
}
