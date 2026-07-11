<?php
/** Unified order-screen controls for manual door-carrier booking. */

defined( 'ABSPATH' ) || exit;

class ES_Carrier_Booking_Admin {
	const LOCK_PREFIX = 'es_carrier_booking_lock_';

	public static function init() {
		add_action( 'wp_ajax_es_carrier_booking_check', array( __CLASS__, 'ajax_check' ) );
		add_action( 'wp_ajax_es_carrier_booking_book', array( __CLASS__, 'ajax_book' ) );
		add_action( 'wp_ajax_es_carrier_booking_clear', array( __CLASS__, 'ajax_clear' ) );
		add_action( 'wp_ajax_es_carrier_booking_document', array( __CLASS__, 'ajax_document' ) );
	}

	public static function render_for_order( $order ) {
		if ( ! $order ) {
			return;
		}

		// Locker orders use their already-hardened booking implementation, but it is
		// rendered in this same Shipment Tracking box instead of a second sidebar box.
		if ( class_exists( 'ES_TCG_Locker_Admin' ) && null !== ES_TCG_Locker_Admin::read_order_locker_meta( $order ) ) {
			echo '<div class="es-booking-section"><h4>' . esc_html__( 'Carrier booking', 'erpnext-shipping' ) . '</h4>';
			ES_TCG_Locker_Admin::render_meta_box( $order );
			echo '</div><hr style="margin:14px 0;">';
			return;
		}

		$snapshot = self::read_snapshot( $order );
		$provider = self::inferred_provider( $order );
		if ( null === $snapshot ) {
			if ( ES_Carrier_Booking::supported_provider( $provider ) ) {
				echo '<div class="es-booking-section"><h4>' . esc_html__( 'Carrier booking', 'erpnext-shipping' ) . '</h4>';
				echo '<p style="color:#646970;margin-top:0;">' . esc_html__( 'This order predates the safe booking snapshot. Book it in the carrier portal, then add its tracking number below.', 'erpnext-shipping' ) . '</p></div><hr style="margin:14px 0;">';
			}
			return;
		}

		$order_id    = $order->get_id();
		$status      = (string) $order->get_meta( ES_Carrier_Booking::M_BOOKING_STATUS, true );
		$shipment_id = (string) $order->get_meta( ES_Carrier_Booking::M_SHIPMENT_ID, true );
		$tracking    = (string) $order->get_meta( ES_Carrier_Booking::M_TRACKING_REF, true );
		$error       = (string) $order->get_meta( ES_Carrier_Booking::M_LAST_ERROR, true );
		$guard       = ES_Carrier_Booking::guard( $shipment_id, $status );
		$nonce       = wp_create_nonce( 'es_carrier_booking_' . $order_id );
		$name        = ES_Carrier_Booking::provider_name( $snapshot[ ES_Carrier_Booking::M_PROVIDER ] );
		?>
		<div class="es-booking-section" data-order-id="<?php echo esc_attr( $order_id ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<h4 style="margin:0 0 8px;"><?php esc_html_e( 'Carrier booking', 'erpnext-shipping' ); ?></h4>
			<div style="background:#f6f7f7;border-left:4px solid #2271b1;padding:9px 10px;margin-bottom:10px;">
				<strong><?php echo esc_html( $name ); ?></strong><br>
				<span><?php echo esc_html( $snapshot[ ES_Carrier_Booking::M_SERVICE_NAME ] ); ?></span><br>
				<small><?php echo esc_html( sprintf( __( 'Customer paid R%s shipping · checkout provider quote R%s', 'erpnext-shipping' ), $snapshot[ ES_Carrier_Booking::M_CUSTOMER_CHG ], $snapshot[ ES_Carrier_Booking::M_PROVIDER_RATE ] ) ); ?></small>
			</div>

			<?php if ( ES_Carrier_Booking::STATE_BOOKED === $status && '' !== $shipment_id ) : ?>
				<p style="margin:0 0 8px;color:#1d6f42;"><span class="dashicons dashicons-yes-alt"></span> <strong><?php esc_html_e( 'Shipment booked', 'erpnext-shipping' ); ?></strong><br><small><?php echo esc_html( $tracking ?: $shipment_id ); ?></small></p>
				<?php $doc_nonce = wp_create_nonce( 'es_carrier_document_' . $order_id ); ?>
				<p style="display:flex;gap:6px;margin-bottom:0;">
					<a class="button" style="flex:1;text-align:center;" target="_blank" href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=es_carrier_booking_document&kind=waybill&order_id=' . $order_id . '&_wpnonce=' . $doc_nonce ) ); ?>"><?php esc_html_e( 'Waybill PDF', 'erpnext-shipping' ); ?></a>
					<a class="button" style="flex:1;text-align:center;" target="_blank" href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=es_carrier_booking_document&kind=label&order_id=' . $order_id . '&_wpnonce=' . $doc_nonce ) ); ?>"><?php esc_html_e( 'Parcel label', 'erpnext-shipping' ); ?></a>
				</p>
			<?php elseif ( ! $guard['can'] ) : ?>
				<div style="background:#fff3cd;border:1px solid #dba617;padding:8px 10px;font-size:12px;">
					<strong><?php esc_html_e( 'Booking outcome needs review', 'erpnext-shipping' ); ?></strong><br>
					<?php esc_html_e( 'The request may have reached the carrier. Check the carrier portal before clearing this state.', 'erpnext-shipping' ); ?>
					<?php if ( $error ) : ?><br><em><?php echo esc_html( $error ); ?></em><?php endif; ?>
				</div>
				<p><button type="button" class="button es-carrier-clear" style="width:100%;"><?php esc_html_e( 'I checked the portal — clear & retry', 'erpnext-shipping' ); ?></button></p>
			<?php else : ?>
				<?php if ( ES_Carrier_Booking::STATE_ERROR === $status && $error ) : ?><p style="color:#b32d2e;font-size:12px;"><?php echo esc_html( $error ); ?></p><?php endif; ?>
				<div class="es-carrier-live-quote" style="display:none;background:#edfaef;border:1px solid #68a775;padding:9px 10px;margin-bottom:8px;"></div>
				<button type="button" class="button es-carrier-check" style="width:100%;"><?php esc_html_e( 'Check live booking cost', 'erpnext-shipping' ); ?></button>
				<button type="button" class="button button-primary es-carrier-book" style="width:100%;display:none;margin-top:6px;"></button>
				<p class="description" style="margin-bottom:0;"><?php esc_html_e( 'Checking is read-only. Booking happens only after you confirm the live carrier charge.', 'erpnext-shipping' ); ?></p>
			<?php endif; ?>
		</div>
		<hr style="margin:14px 0;">
		<?php self::render_script_once(); ?>
		<?php
	}

	private static function render_script_once() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		?>
		<script>
		jQuery(function($){
			function box(el){ return $(el).closest('.es-booking-section'); }
			function fail(r){ return (r && r.data && r.data.message) ? r.data.message : ((r && r.data) ? r.data : '<?php echo esc_js( __( 'Carrier request failed.', 'erpnext-shipping' ) ); ?>'); }
			$(document).on('click','.es-carrier-check',function(){
				var $b=$(this),$x=box(this); $b.prop('disabled',true).text('<?php echo esc_js( __( 'Checking live carrier…', 'erpnext-shipping' ) ); ?>');
				$.post(ajaxurl,{action:'es_carrier_booking_check',order_id:$x.data('order-id'),_ajax_nonce:$x.data('nonce')},function(r){
					if(!r.success){alert(fail(r));$b.prop('disabled',false).text('<?php echo esc_js( __( 'Check live booking cost', 'erpnext-shipping' ) ); ?>');return;}
					$x.data('confirm-token',r.data.token);$x.find('.es-carrier-live-quote').html('<strong>'+r.data.provider+'</strong><br><?php echo esc_js( __( 'Live booking cost:', 'erpnext-shipping' ) ); ?> R'+r.data.rate+'<br><small>'+r.data.margin_note+'</small>').show();
					$x.find('.es-carrier-book').text('<?php echo esc_js( __( 'Confirm & book — R', 'erpnext-shipping' ) ); ?>'+r.data.rate).show();$b.hide();
				});
			});
			$(document).on('click','.es-carrier-book',function(){
				var $b=$(this),$x=box(this);if(!confirm('<?php echo esc_js( __( 'Create and accept this carrier booking? This may incur the displayed charge.', 'erpnext-shipping' ) ); ?>'))return;
				$b.prop('disabled',true).text('<?php echo esc_js( __( 'Booking… do not close this page', 'erpnext-shipping' ) ); ?>');
				$.post(ajaxurl,{action:'es_carrier_booking_book',order_id:$x.data('order-id'),_ajax_nonce:$x.data('nonce'),confirm_token:$x.data('confirm-token')},function(r){if(r.success){location.reload();}else{alert(fail(r));location.reload();}}).fail(function(){alert('<?php echo esc_js( __( 'The response was interrupted. Check the carrier portal before retrying.', 'erpnext-shipping' ) ); ?>');location.reload();});
			});
			$(document).on('click','.es-carrier-clear',function(){
				var $x=box(this);if(!confirm('<?php echo esc_js( __( 'Only continue if the carrier portal shows NO shipment for this order.', 'erpnext-shipping' ) ); ?>'))return;
				$.post(ajaxurl,{action:'es_carrier_booking_clear',order_id:$x.data('order-id'),_ajax_nonce:$x.data('nonce')},function(r){if(r.success)location.reload();else alert(fail(r));});
			});
		});
		</script>
		<?php
	}

	public static function ajax_check() {
		list( $order, $snapshot ) = self::authorize_order();
		$valid = self::validate_current( $order, $snapshot );
		if ( ! $valid['ok'] ) {
			wp_send_json_error( array( 'message' => $valid['error'] ) );
		}
		$fresh = self::fresh_rate( $snapshot, $valid );
		if ( ! $fresh['ok'] ) {
			wp_send_json_error( array( 'message' => $fresh['error'] ) );
		}
		$token   = wp_generate_password( 32, false, false );
		$confirm = ES_Carrier_Booking::make_confirmation( $token, $snapshot, $valid, $fresh['rate'] );
		$order->update_meta_data( ES_Carrier_Booking::M_CONFIRMATION, $confirm );
		$order->save();
		$customer = (float) $snapshot[ ES_Carrier_Booking::M_CUSTOMER_CHG ];
		$delta    = $customer - (float) $fresh['rate'];
		$note     = $delta >= 0
			? sprintf( __( 'Customer charge covers the carrier by R%.2f.', 'erpnext-shipping' ), $delta )
			: sprintf( __( 'Carrier cost is R%.2f above the customer charge.', 'erpnext-shipping' ), abs( $delta ) );
		wp_send_json_success( array( 'token' => $token, 'rate' => number_format( $fresh['rate'], 2, '.', '' ), 'provider' => ES_Carrier_Booking::provider_name( $snapshot[ ES_Carrier_Booking::M_PROVIDER ] ), 'margin_note' => $note ) );
	}

	public static function ajax_book() {
		list( $order, $snapshot ) = self::authorize_order();
		$order_id = $order->get_id();
		$guard = ES_Carrier_Booking::guard( $order->get_meta( ES_Carrier_Booking::M_SHIPMENT_ID, true ), $order->get_meta( ES_Carrier_Booking::M_BOOKING_STATUS, true ) );
		if ( ! $guard['can'] ) {
			wp_send_json_error( array( 'message' => __( 'This order is already booked or has an unresolved booking attempt.', 'erpnext-shipping' ) ) );
		}
		$lock_token = self::mint_token();
		if ( ! self::acquire_lock( $order_id, $lock_token, 180 ) ) {
			wp_send_json_error( array( 'message' => __( 'Another booking request is already running.', 'erpnext-shipping' ) ) );
		}
		register_shutdown_function( array( __CLASS__, 'release_lock' ), $order_id, $lock_token );

		$order->update_meta_data( ES_Carrier_Booking::M_BOOKING_STATUS, ES_Carrier_Booking::STATE_BOOKING );
		$order->delete_meta_data( ES_Carrier_Booking::M_LAST_ERROR );
		$order->save();

		$valid   = self::validate_current( $order, $snapshot );
		$confirm = (array) $order->get_meta( ES_Carrier_Booking::M_CONFIRMATION, true );
		$token   = sanitize_text_field( wp_unslash( $_POST['confirm_token'] ?? '' ) );
		if ( ! $valid['ok'] || ! ES_Carrier_Booking::confirmation_valid( $confirm, $token, $snapshot, $valid ) ) {
			self::persist_outcome( $order, array( 'state' => ES_Carrier_Booking::STATE_ERROR, 'error' => $valid['ok'] ? __( 'The live quote expired or the order changed. Check the cost again.', 'erpnext-shipping' ) : $valid['error'], 'shipment_id' => '', 'tracking_ref' => '' ) );
			wp_send_json_error( array( 'message' => __( 'The live quote expired or the order changed. Check the cost again.', 'erpnext-shipping' ) ) );
		}

		$client = self::client( $snapshot, $valid['settings'] );
		$args   = self::booking_args( $order, $snapshot, $valid );
		$result = $client ? $client->create_shipment( $args ) : array( 'ok' => false, 'http' => 400, 'error' => 'Carrier is not configured.' );
		$outcome = ES_Carrier_Booking::classify_result( $result );
		self::persist_outcome( $order, $outcome );
		if ( ES_Carrier_Booking::STATE_BOOKED === $outcome['state'] ) {
			self::append_tracking_once( $order, $snapshot[ ES_Carrier_Booking::M_PROVIDER ], $outcome['tracking_ref'] ?: $outcome['shipment_id'] );
			wp_send_json_success( array( 'message' => __( 'Shipment booked.', 'erpnext-shipping' ) ) );
		}
		wp_send_json_error( array( 'message' => ES_Carrier_Booking::STATE_AMBIGUOUS === $outcome['state'] ? __( 'Booking outcome is uncertain. Check the carrier portal before retrying.', 'erpnext-shipping' ) : $outcome['error'] ) );
	}

	public static function ajax_clear() {
		list( $order ) = self::authorize_order();
		$order_id = $order->get_id();
		if ( '' !== (string) $order->get_meta( ES_Carrier_Booking::M_SHIPMENT_ID, true ) ) {
			wp_send_json_error( array( 'message' => __( 'A booked shipment cannot be cleared.', 'erpnext-shipping' ) ) );
		}
		$state = self::lock_state( $order_id );
		if ( $state['exists'] && ! $state['stale'] ) {
			wp_send_json_error( array( 'message' => __( 'The booking request may still be running. Wait before recovering it.', 'erpnext-shipping' ) ) );
		}
		if ( $state['exists'] ) {
			delete_option( self::LOCK_PREFIX . $order_id );
		}
		$order->update_meta_data( ES_Carrier_Booking::M_BOOKING_STATUS, ES_Carrier_Booking::STATE_NONE );
		$order->delete_meta_data( ES_Carrier_Booking::M_LAST_ERROR );
		$order->delete_meta_data( ES_Carrier_Booking::M_CONFIRMATION );
		$order->add_order_note( __( 'Carrier booking recovery cleared after operator confirmed no shipment exists in the carrier portal.', 'erpnext-shipping' ) );
		$order->save();
		wp_send_json_success();
	}

	public static function ajax_document() {
		$order_id = absint( $_GET['order_id'] ?? 0 );
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'es_carrier_document_' . $order_id ) || ! self::can_labels() ) {
			wp_die( esc_html__( 'Permission denied.', 'erpnext-shipping' ), '', array( 'response' => 403 ) );
		}
		$order    = wc_get_order( $order_id );
		$snapshot = self::read_snapshot( $order );
		$kind     = sanitize_key( $_GET['kind'] ?? '' );
		if ( ! $order || null === $snapshot || ! in_array( $kind, array( 'waybill', 'label' ), true ) ) {
			wp_die( esc_html__( 'Invalid document request.', 'erpnext-shipping' ), '', array( 'response' => 400 ) );
		}
		$id = (string) $order->get_meta( ES_Carrier_Booking::M_SHIPMENT_ID, true );
		if ( '' === $id ) {
			wp_die( esc_html__( 'No booked shipment exists.', 'erpnext-shipping' ), '', array( 'response' => 404 ) );
		}
		$settings = self::settings_for_snapshot( $snapshot );
		$client   = self::client( $snapshot, $settings );
		$res      = $client ? $client->fetch_document( $kind, $id, (string) $order->get_meta( ES_Carrier_Booking::M_TRACKING_REF, true ) ) : array( 'ok' => false );
		if ( empty( $res['ok'] ) || '%PDF-' !== substr( (string) ( $res['body'] ?? '' ), 0, 5 ) ) {
			wp_die( esc_html__( 'The carrier did not return a valid PDF.', 'erpnext-shipping' ), '', array( 'response' => 502 ) );
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $snapshot[ ES_Carrier_Booking::M_PROVIDER ] . '-' . $kind . '-' . $id . '.pdf' ) . '"' );
		echo $res['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- verified PDF bytes.
		exit;
	}

	public static function read_snapshot( $order ) {
		if ( ! $order || ! is_callable( array( $order, 'get_items' ) ) ) {
			return null;
		}
		$keys = array( ES_Carrier_Booking::M_PROVIDER, ES_Carrier_Booking::M_SERVICE_CODE, ES_Carrier_Booking::M_SERVICE_NAME, ES_Carrier_Booking::M_INSTANCE_ID, ES_Carrier_Booking::M_ORIGIN_LOC, ES_Carrier_Booking::M_PROVIDER_RATE, ES_Carrier_Booking::M_CUSTOMER_CHG, ES_Carrier_Booking::M_PARCELS, ES_Carrier_Booking::M_QUOTE_TS, ES_Carrier_Booking::M_IS_SPLIT );
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			if ( '' === (string) $item->get_meta( ES_Carrier_Booking::M_PROVIDER, true ) ) {
				continue;
			}
			$out = array();
			foreach ( $keys as $key ) {
				$out[ $key ] = (string) $item->get_meta( $key, true );
			}
			$parcels = json_decode( $out[ ES_Carrier_Booking::M_PARCELS ], true );
			if ( ! ES_Carrier_Booking::supported_provider( $out[ ES_Carrier_Booking::M_PROVIDER ] ) || '' === $out[ ES_Carrier_Booking::M_SERVICE_CODE ] || empty( $parcels ) ) {
				return null;
			}
			$out['parcels_array'] = $parcels;
			return $out;
		}
		return null;
	}

	private static function authorize_order() {
		$order_id = absint( $_POST['order_id'] ?? 0 );
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_ajax_nonce'] ?? '' ) ), 'es_carrier_booking_' . $order_id ) || ! self::can_book() ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'erpnext-shipping' ) ), 403 );
		}
		$order    = wc_get_order( $order_id );
		$snapshot = self::read_snapshot( $order );
		if ( ! $order || null === $snapshot ) {
			wp_send_json_error( array( 'message' => __( 'This order has no safe carrier booking snapshot.', 'erpnext-shipping' ) ), 400 );
		}
		return array( $order, $snapshot );
	}

	private static function validate_current( $order, array $snapshot ) {
		if ( '1' === $snapshot[ ES_Carrier_Booking::M_IS_SPLIT ] ) {
			return array( 'ok' => false, 'error' => __( 'Split shipments must be booked as separate consignments in the carrier portal.', 'erpnext-shipping' ) );
		}
		if ( ! $order->is_paid() ) {
			return array( 'ok' => false, 'error' => __( 'The order must be paid before booking.', 'erpnext-shipping' ) );
		}
		$settings = self::settings_for_snapshot( $snapshot );
		$token_key = ES_Carrier_Booking::PROVIDER_MDS === $snapshot[ ES_Carrier_Booking::M_PROVIDER ] ? 'mds_api_token' : 'tcg_api_token';
		if ( empty( $settings[ $token_key ] ) ) {
			return array( 'ok' => false, 'error' => __( 'The selected carrier is not configured.', 'erpnext-shipping' ) );
		}
		$origin = self::origin( $snapshot[ ES_Carrier_Booking::M_ORIGIN_LOC ] );
		$dest   = self::destination( $order );
		$parcels = self::order_parcels( $order, $settings );
		$delivery_contact = self::delivery_contact( $order );
		$collection_contact = array( 'name' => (string) ( $settings['dispatch_contact_name'] ?? $settings['company_name'] ?? '' ), 'email' => (string) ( $settings['dispatch_contact_email'] ?? get_option( 'admin_email' ) ), 'mobile_number' => (string) ( $settings['dispatch_contact_phone'] ?? '' ) );
		if ( ! in_array( strtoupper( (string) ( $origin['country'] ?? '' ) ), array( 'ZA', 'ZAF' ), true ) || ! in_array( strtoupper( (string) ( $dest['country'] ?? '' ) ), array( 'ZA', 'ZAF' ), true ) ) {
			return array( 'ok' => false, 'error' => __( 'Automatic booking currently supports South African domestic shipments only.', 'erpnext-shipping' ) );
		}
		if ( empty( $origin['street_address'] ) || empty( $origin['city'] ) || empty( $dest['street_address'] ) || empty( $dest['city'] ) || empty( $dest['code'] ) || empty( $parcels ) || empty( $delivery_contact['name'] ) || empty( $delivery_contact['email'] ) || empty( $delivery_contact['mobile_number'] ) || empty( $collection_contact['name'] ) || empty( $collection_contact['email'] ) || empty( $collection_contact['mobile_number'] ) ) {
			return array( 'ok' => false, 'error' => __( 'Booking needs complete dispatch/delivery addresses, customer email/phone, product weights/dimensions, and a Dispatch Contact Phone in ERPNext Shipping settings.', 'erpnext-shipping' ) );
		}
		return array( 'ok' => true, 'settings' => $settings, 'origin' => $origin, 'destination' => $dest, 'parcels' => $parcels, 'delivery_contact' => $delivery_contact, 'collection_contact' => $collection_contact );
	}

	private static function fresh_rate( array $snapshot, array $valid ) {
		$provider = $snapshot[ ES_Carrier_Booking::M_PROVIDER ];
		$settings = $valid['settings'];
		$carrier  = ES_Carrier_Booking::PROVIDER_MDS === $provider
			? new ES_Carrier_Collivery( $settings['mds_api_token'] )
			: new ES_Carrier_ShipLogic( $settings['tcg_api_token'], $settings['company_name'] ?? '' );
		$rates = $carrier->get_rates( $valid['origin'], $valid['destination'], $valid['parcels'] );
		foreach ( (array) $rates as $rate ) {
			$service = (string) ( $rate['booking_service'] ?? $rate['service_code'] ?? '' );
			if ( hash_equals( (string) $snapshot[ ES_Carrier_Booking::M_SERVICE_CODE ], $service ) && is_numeric( $rate['price_incl_vat'] ?? null ) && (float) $rate['price_incl_vat'] > 0 ) {
				return array( 'ok' => true, 'rate' => round( (float) $rate['price_incl_vat'], 2 ) );
			}
		}
		return array( 'ok' => false, 'error' => __( 'The customer-selected carrier service is no longer available for the current order/address.', 'erpnext-shipping' ) );
	}

	private static function booking_args( $order, array $snapshot, array $valid ) {
		return array(
			'service' => $snapshot[ ES_Carrier_Booking::M_SERVICE_CODE ], 'origin' => $valid['origin'], 'destination' => $valid['destination'], 'parcels' => $valid['parcels'],
			'collection_contact' => $valid['collection_contact'], 'delivery_contact' => $valid['delivery_contact'],
			'company' => (string) ( $valid['settings']['company_name'] ?? get_bloginfo( 'name' ) ), 'destination_company' => $order->get_shipping_company() ?: $order->get_billing_company(),
			'reference' => 'WC-' . $order->get_order_number(), 'description' => 'WooCommerce order ' . $order->get_order_number(),
			'collection_instructions' => 'WooCommerce order ' . $order->get_order_number(), 'delivery_instructions' => (string) $order->get_customer_note(),
		);
	}

	private static function persist_outcome( $order, array $outcome ) {
		$order->update_meta_data( ES_Carrier_Booking::M_BOOKING_STATUS, $outcome['state'] );
		$order->delete_meta_data( ES_Carrier_Booking::M_CONFIRMATION );
		if ( ES_Carrier_Booking::STATE_BOOKED === $outcome['state'] ) {
			$order->update_meta_data( ES_Carrier_Booking::M_SHIPMENT_ID, $outcome['shipment_id'] );
			$order->update_meta_data( ES_Carrier_Booking::M_TRACKING_REF, $outcome['tracking_ref'] );
			$order->update_meta_data( ES_Carrier_Booking::M_BOOKED_TS, time() );
			$order->delete_meta_data( ES_Carrier_Booking::M_LAST_ERROR );
			$order->add_order_note( sprintf( __( 'Carrier shipment booked. Shipment %1$s, tracking %2$s.', 'erpnext-shipping' ), $outcome['shipment_id'], $outcome['tracking_ref'] ?: $outcome['shipment_id'] ) );
		} else {
			$order->update_meta_data( ES_Carrier_Booking::M_LAST_ERROR, $outcome['error'] );
			$order->add_order_note( sprintf( __( 'Carrier booking %1$s: %2$s', 'erpnext-shipping' ), $outcome['state'], $outcome['error'] ) );
		}
		$order->save();
	}

	private static function append_tracking_once( $order, $provider, $tracking ) {
		foreach ( ES_Fulfillment_Tracking::get_tracking_items( $order ) as $item ) {
			if ( (string) ( $item['tracking_number'] ?? '' ) === (string) $tracking ) {
				return;
			}
		}
		ES_Fulfillment_Tracking::save_tracking_item( $order, ES_Fulfillment_Tracking::create_tracking_item( $provider, $tracking, time() ) );
	}

	private static function settings_for_snapshot( array $snapshot ) {
		$id = absint( $snapshot[ ES_Carrier_Booking::M_INSTANCE_ID ] ?? 0 );
		$opts = $id ? get_option( 'woocommerce_erpnext_shipping_' . $id . '_settings', array() ) : array();
		if ( is_array( $opts ) && ! empty( $opts ) ) {
			return $opts;
		}
		global $wpdb;
		$rows = $wpdb->get_col( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'woocommerce_erpnext_shipping_%_settings' ORDER BY option_name ASC" );
		foreach ( (array) $rows as $raw ) {
			$opts = maybe_unserialize( $raw );
			if ( is_array( $opts ) ) {
				return $opts;
			}
		}
		return array();
	}

	private static function client( array $snapshot, array $settings ) {
		$key   = ES_Carrier_Booking::PROVIDER_MDS === $snapshot[ ES_Carrier_Booking::M_PROVIDER ] ? 'mds_api_token' : 'tcg_api_token';
		$token = (string) ( $settings[ $key ] ?? '' );
		if ( '' === $token ) {
			return null;
		}
		$logger = function( $level, $message ) { wc_get_logger()->log( $level, $message, array( 'source' => 'erpnext-shipping-booking' ) ); };
		return new ES_Door_Booking_Client( $snapshot[ ES_Carrier_Booking::M_PROVIDER ], $token, new ES_Door_Booking_WP_Transport(), 20, $logger );
	}

	private static function origin( $location_id ) {
		foreach ( (array) get_option( 'es_shipping_locations', array() ) as $loc ) {
			if ( (string) ( $loc['id'] ?? '' ) === (string) $location_id && 'collection_point' !== ( $loc['type'] ?? 'warehouse' ) ) {
				return array( 'street_address' => $loc['street'] ?? '', 'local_area' => $loc['suburb'] ?? '', 'city' => $loc['city'] ?? '', 'zone' => $loc['province'] ?? '', 'country' => $loc['country'] ?? 'ZA', 'code' => $loc['postcode'] ?? '' );
			}
		}
		return array();
	}

	private static function destination( $order ) {
		$use_shipping = '' !== trim( (string) $order->get_shipping_address_1() );
		$a1 = $use_shipping ? $order->get_shipping_address_1() : $order->get_billing_address_1();
		$a2 = $use_shipping ? $order->get_shipping_address_2() : $order->get_billing_address_2();
		return array( 'street_address' => trim( $a1 . ' ' . $a2 ), 'local_area' => $a2, 'city' => $use_shipping ? $order->get_shipping_city() : $order->get_billing_city(), 'zone' => $use_shipping ? $order->get_shipping_state() : $order->get_billing_state(), 'country' => $use_shipping ? $order->get_shipping_country() : $order->get_billing_country(), 'code' => $use_shipping ? $order->get_shipping_postcode() : $order->get_billing_postcode() );
	}

	private static function delivery_contact( $order ) {
		$name = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ) ?: trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		return array( 'name' => $name, 'email' => $order->get_billing_email(), 'mobile_number' => $order->get_billing_phone() );
	}

	private static function order_parcels( $order, array $settings ) {
		$items = array();
		foreach ( $order->get_items( 'line_item' ) as $line ) {
			$product = $line->get_product();
			if ( ! $product ) {
				return array();
			}
			$items[] = array( 'qty' => (float) $line->get_quantity(), 'weight' => (float) $product->get_weight(), 'length' => (float) $product->get_length(), 'width' => (float) $product->get_width(), 'height' => (float) $product->get_height() );
		}
		$estimator = new ES_Parcel_Estimator( (float) ( $settings['default_weight'] ?? 0.5 ), (float) ( $settings['default_length'] ?? 20 ), (float) ( $settings['default_width'] ?? 15 ), (float) ( $settings['default_height'] ?? 10 ) );
		return empty( $items ) ? array() : $estimator->estimate( $items );
	}

	private static function inferred_provider( $order ) {
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			return ES_Fulfillment_Tracking::infer_provider_from_shipping( method_exists( $item, 'get_method_id' ) ? $item->get_method_id() : '', method_exists( $item, 'get_method_title' ) ? $item->get_method_title() : '', $item->get_meta( 'Carrier', true ), false );
		}
		return '';
	}

	private static function can_book() { return current_user_can( apply_filters( 'es_carrier_book_capability', 'manage_woocommerce' ) ); }
	private static function can_labels() { return class_exists( 'ES_Warehouse_Role' ) ? ES_Warehouse_Role::current_user_can_fulfill() : current_user_can( 'manage_woocommerce' ); }
	private static function mint_token() { return function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'es', true ); }
	private static function acquire_lock( $order_id, $token, $lease ) { return add_option( self::LOCK_PREFIX . $order_id, $token . '|' . ( time() + max( 120, (int) $lease ) ), '', false ); }
	public static function release_lock( $order_id, $token ) { $key=self::LOCK_PREFIX.$order_id;$raw=(string)get_option($key,'');if(0===strpos($raw,$token.'|'))delete_option($key); }
	private static function lock_state( $order_id ) { $raw=(string)get_option(self::LOCK_PREFIX.$order_id,'');$p=explode('|',$raw,2);return array('exists'=>''!==$raw,'stale'=>''!==$raw && time()>(int)($p[1]??PHP_INT_MAX)); }
}
