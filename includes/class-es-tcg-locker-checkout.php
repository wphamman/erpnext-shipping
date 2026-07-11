<?php
/**
 * TCG Locker checkout selector (classic checkout only).
 *
 * Renders an accessible, dependency-free locker search/selection control in the
 * order-review shipping section, backed by nonce-protected AJAX. The selected
 * locker lives in the WC session and is always re-validated server-side; hidden
 * checkout fields are never trusted. Selecting/clearing triggers a standard
 * `update_checkout`, which re-runs rate calculation so the `_locker` rate
 * appears/updates. No shipment is ever booked here.
 *
 * Blocks checkout is intentionally unsupported (this uses classic hooks).
 *
 * @package ERPNext_Shipping
 */

defined( 'ABSPATH' ) || exit;

class ES_TCG_Locker_Checkout {

	const SESSION_KEY = 'es_tcg_locker_selection';
	const NONCE       = 'es_checkout_locker';

	public static function init() {
		// Initial prompt on both classic cart and classic checkout. Once the
		// priced `_locker` rate exists, its selected-locker details move beneath
		// the real WC shipping radio via woocommerce_after_shipping_rate.
		add_action( 'woocommerce_review_order_after_shipping', array( __CLASS__, 'render_selector' ) );
		add_action( 'woocommerce_cart_totals_after_shipping', array( __CLASS__, 'render_cart_selector' ) );
		add_action( 'woocommerce_after_shipping_rate', array( __CLASS__, 'render_rate_selector' ), 20, 2 );

		add_action( 'wp_ajax_es_tcg_locker_search', array( __CLASS__, 'ajax_search' ) );
		add_action( 'wp_ajax_nopriv_es_tcg_locker_search', array( __CLASS__, 'ajax_search' ) );
		add_action( 'wp_ajax_es_tcg_locker_select', array( __CLASS__, 'ajax_select' ) );
		add_action( 'wp_ajax_nopriv_es_tcg_locker_select', array( __CLASS__, 'ajax_select' ) );
		add_action( 'wp_ajax_es_tcg_locker_clear', array( __CLASS__, 'ajax_clear' ) );
		add_action( 'wp_ajax_nopriv_es_tcg_locker_clear', array( __CLASS__, 'ajax_clear' ) );

		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'validate' ) );
		add_action( 'wp_head', array( __CLASS__, 'assets' ) );
	}

	/**
	 * The validated locker selection from the session, or null.
	 *
	 * @return array|null [ code, name, address ]
	 */
	public static function get_selected_locker() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return null;
		}
		$sel = WC()->session->get( self::SESSION_KEY );
		if ( is_array( $sel ) && ! empty( $sel['code'] ) ) {
			return $sel;
		}
		return null;
	}

	/** Find the currently-calculated locker rate across WC shipping packages. */
	private static function available_locker_rate_id() {
		if ( ! function_exists( 'WC' ) || ! WC()->shipping() ) {
			return null;
		}
		foreach ( (array) WC()->shipping()->get_packages() as $package ) {
			$rates   = isset( $package['rates'] ) ? (array) $package['rates'] : array();
			$rate_id = ES_TCG_Locker_Rate::find_locker_rate_id( array_keys( $rates ) );
			if ( $rate_id ) {
				return $rate_id;
			}
		}
		return null;
	}

	/**
	 * Locker selection is session state, not part of WooCommerce's package hash.
	 * Explicitly invalidate package caches or WC will reuse the pre-selection door
	 * rates forever and the new `_locker` rate can never appear.
	 */
	private static function invalidate_shipping_cache() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		$packages = WC()->shipping() ? (array) WC()->shipping()->get_packages() : array();
		$count    = max( 1, count( $packages ) );
		for ( $i = 0; $i < $count; $i++ ) {
			WC()->session->__unset( 'shipping_for_package_' . $i );
		}
	}

	/** Recalculate shipping and make the real locker rate the chosen WC method. */
	private static function refresh_and_choose_locker_rate() {
		if ( ! function_exists( 'WC' ) || ! WC()->session || ! WC()->cart ) {
			return null;
		}
		self::invalidate_shipping_cache();
		WC()->cart->calculate_shipping();

		$chosen   = (array) WC()->session->get( 'chosen_shipping_methods', array() );
		$found_id = null;
		foreach ( (array) WC()->shipping()->get_packages() as $index => $package ) {
			$rates   = isset( $package['rates'] ) ? (array) $package['rates'] : array();
			$rate_id = ES_TCG_Locker_Rate::find_locker_rate_id( array_keys( $rates ) );
			if ( $rate_id ) {
				$chosen[ $index ] = $rate_id;
				$found_id         = $rate_id;
			}
		}
		if ( $found_id ) {
			WC()->session->set( 'chosen_shipping_methods', $chosen );
		}
		return $found_id;
	}

	/** Remove a now-invalid locker rate from the chosen-method session list. */
	private static function unchoose_locker_rate() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		$chosen = (array) WC()->session->get( 'chosen_shipping_methods', array() );
		foreach ( $chosen as $index => $rate_id ) {
			if ( ES_TCG_Locker_Rate::is_locker_rate_id( $rate_id ) ) {
				unset( $chosen[ $index ] );
			}
		}
		WC()->session->set( 'chosen_shipping_methods', $chosen );
		self::invalidate_shipping_cache();
	}

	/** Render the initial selector row in the checkout review table. */
	public static function render_selector() {
		self::render_prompt_row( 'checkout' );
	}

	/** Render the initial selector row in classic cart totals. */
	public static function render_cart_selector() {
		self::render_prompt_row( 'cart' );
	}

	/**
	 * Render a prompt before a priced locker rate exists. If a selected locker
	 * already has a real rate, the normal WC radio owns the UI instead.
	 */
	private static function render_prompt_row( $context ) {
		$client = es_tcg_locker_client();
		if ( ! $client ) {
			return; // Disabled or not configured — no selector, no rate.
		}
		$sel = self::get_selected_locker();
		if ( $sel && self::available_locker_rate_id() ) {
			return;
		}
		?>
		<tr class="es-tcg-locker-row es-tcg-locker-<?php echo esc_attr( $context ); ?>">
			<th><?php esc_html_e( 'TCG Locker delivery', 'erpnext-shipping' ); ?></th>
			<td>
				<?php self::render_control( $sel, true ); ?>
			</td>
		</tr>
		<?php
	}

	/** Show selected-locker controls directly beneath the real priced WC rate. */
	public static function render_rate_selector( $method, $index ) {
		$rate_id = is_object( $method ) && method_exists( $method, 'get_id' ) ? $method->get_id() : '';
		if ( ! ES_TCG_Locker_Rate::is_locker_rate_id( $rate_id ) ) {
			return;
		}
		$sel = self::get_selected_locker();
		if ( ! $sel ) {
			return;
		}
		self::render_control( $sel, false );
	}

	/** Shared cart/checkout locker search and selected-locker control. */
	private static function render_control( $sel, $pre_rate = false ) {
		?>
		<div class="es-tcg-locker" data-pre-rate="<?php echo $pre_rate ? '1' : '0'; ?>">
			<?php if ( $sel ) : ?>
				<div class="es-locker-selected">
					<strong><?php echo esc_html( $sel['name'] ); ?></strong>
					<?php if ( ! empty( $sel['address'] ) ) : ?>
						<br><span class="es-locker-addr"><?php echo esc_html( $sel['address'] ); ?></span>
					<?php endif; ?>
					<?php if ( $pre_rate ) : ?>
						<br><span class="es-locker-unavailable"><?php esc_html_e( 'Selected, but no locker rate is available for this cart yet.', 'erpnext-shipping' ); ?></span>
					<?php endif; ?>
					<br><a href="#" class="es-locker-toggle"><?php esc_html_e( 'Change locker', 'erpnext-shipping' ); ?></a>
					&middot; <a href="#" class="es-locker-clear"><?php esc_html_e( 'Remove', 'erpnext-shipping' ); ?></a>
				</div>
			<?php else : ?>
				<p class="es-locker-prompt"><strong><?php esc_html_e( 'Choose a locker to see the exact price', 'erpnext-shipping' ); ?></strong><br><span><?php esc_html_e( 'Your priced TCG Locker option will appear with the other shipping methods.', 'erpnext-shipping' ); ?></span></p>
			<?php endif; ?>
			<div class="es-locker-search-wrap"<?php echo $sel ? ' style="display:none;"' : ''; ?>>
				<input type="text" class="es-locker-q" autocomplete="off" aria-label="<?php esc_attr_e( 'Search lockers by name, town or postcode', 'erpnext-shipping' ); ?>" placeholder="<?php esc_attr_e( 'Search by name, town or postcode…', 'erpnext-shipping' ); ?>">
				<ul class="es-locker-results" role="listbox" aria-live="polite"></ul>
			</div>
		</div>
		<?php
	}

	// ─────────────────────────── AJAX ──────────────────────────────────

	public static function ajax_search() {
		check_ajax_referer( self::NONCE );
		$client = es_tcg_locker_client();
		if ( ! $client ) {
			wp_send_json_error( array( 'message' => __( 'TCG Locker is unavailable.', 'erpnext-shipping' ) ) );
		}
		$q       = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
		$results = $client->search_lockers( $q, 12 );

		$out = array();
		foreach ( $results as $l ) {
			$out[] = array(
				'code'     => (string) $l['code'],
				'name'     => (string) $l['name'],
				'address'  => (string) $l['address'],
				'town'     => (string) $l['town'],
				'postcode' => (string) $l['postcode'],
				'hours'    => is_string( $l['hours'] ) ? $l['hours'] : '',
				'sizes'    => is_array( $l['box_sizes'] ) ? implode( ', ', array_map( 'strval', $l['box_sizes'] ) ) : '',
			);
		}
		wp_send_json_success( array( 'lockers' => $out ) );
	}

	public static function ajax_select() {
		check_ajax_referer( self::NONCE );
		$client = es_tcg_locker_client();
		if ( ! $client ) {
			wp_send_json_error( array( 'message' => __( 'TCG Locker is unavailable.', 'erpnext-shipping' ) ) );
		}
		$code   = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
		$locker = $client->get_locker( $code ); // Server-side validation — never trust the posted code.
		if ( ! $locker ) {
			wp_send_json_error( array( 'message' => __( 'That locker is no longer available. Please choose another.', 'erpnext-shipping' ) ) );
		}
		if ( WC()->session ) {
			WC()->session->set(
				self::SESSION_KEY,
				array(
					'code'    => $locker['code'],
					'name'    => $locker['name'],
					'address' => $locker['address'],
				)
			);
		}
		$rate_id = self::refresh_and_choose_locker_rate();
		wp_send_json_success(
			array(
				'locker' => array(
					'code'    => $locker['code'],
					'name'    => $locker['name'],
					'address' => $locker['address'],
				),
				'rate_id'        => $rate_id,
				'rate_available' => (bool) $rate_id,
			)
		);
	}

	public static function ajax_clear() {
		check_ajax_referer( self::NONCE );
		if ( WC()->session ) {
			WC()->session->set( self::SESSION_KEY, null );
		}
		self::unchoose_locker_rate();
		wp_send_json_success();
	}

	/**
	 * Block placing a TCG Locker order without a valid, server-validated locker.
	 */
	public static function validate() {
		$chosen = ( WC()->session ) ? (array) WC()->session->get( 'chosen_shipping_methods', array() ) : array();
		$is_locker = false;
		foreach ( $chosen as $m ) {
			if ( ES_TCG_Locker_Rate::is_locker_rate_id( $m ) ) {
				$is_locker = true;
				break;
			}
		}
		if ( ! $is_locker ) {
			return;
		}

		$ok  = false;
		$sel = self::get_selected_locker();
		if ( $sel && ! empty( $sel['code'] ) ) {
			$client = es_tcg_locker_client();
			if ( $client && $client->get_locker( $sel['code'] ) ) {
				$ok = true;
			}
		}
		if ( ! $ok ) {
			wc_add_notice( __( 'Please choose a valid TCG Locker for delivery before placing your order.', 'erpnext-shipping' ), 'error' );
		}
	}

	// ─────────────────────────── Assets ────────────────────────────────

	public static function assets() {
		$is_checkout = function_exists( 'is_checkout' ) && is_checkout();
		$is_cart     = function_exists( 'is_cart' ) && is_cart();
		if ( ! $is_checkout && ! $is_cart ) {
			return;
		}
		if ( ! es_tcg_locker_client() ) {
			return;
		}
		$ajax  = admin_url( 'admin-ajax.php' );
		$nonce = wp_create_nonce( self::NONCE );
		?>
		<style>
			.es-tcg-locker{margin-top:8px}
			.es-tcg-locker-row th,.es-tcg-locker-row td{vertical-align:top}
			.es-tcg-locker .es-locker-prompt{margin:0 0 8px}
			.es-tcg-locker .es-locker-prompt span{font-size:.9em;color:#555}
			.es-tcg-locker .es-locker-q{width:100%;box-sizing:border-box;padding:8px 10px;margin:4px 0}
			.es-tcg-locker .es-locker-results{list-style:none;margin:6px 0 0;padding:0;max-height:260px;overflow-y:auto}
			.es-tcg-locker .es-locker-results li{margin:0 0 4px;padding:0}
			.es-tcg-locker .es-locker-pick{display:block;width:100%;text-align:left;padding:8px 10px;border:1px solid #ccc;border-radius:4px;background:#fff;cursor:pointer}
			.es-tcg-locker .es-locker-pick:hover,.es-tcg-locker .es-locker-pick:focus{border-color:#2a7d2e;background:#f3faf3}
			.es-tcg-locker .es-locker-pick strong{display:block}
			.es-tcg-locker .es-locker-addr,.es-tcg-locker .es-locker-sizes,.es-tcg-locker .es-locker-hours{display:block;font-size:.85em;color:#555}
			.es-tcg-locker .es-locker-unavailable{display:inline-block;margin-top:4px;color:#9a3412;font-size:.9em}
			.es-tcg-locker .es-locker-loading,.es-tcg-locker .es-locker-empty{font-size:.9em;color:#777;padding:6px 2px}
			@media(max-width:600px){.es-tcg-locker .es-locker-q{font-size:16px}}
		</style>
		<script>
		(function($){
			var ajaxurl = <?php echo wp_json_encode( $ajax ); ?>;
			var nonce   = <?php echo wp_json_encode( $nonce ); ?>;
			var isCart  = <?php echo $is_cart ? 'true' : 'false'; ?>;
			var timer;
			function esc(s){ return String(s == null ? '' : s); }
			function refreshShipping(){
				if(isCart){ window.location.reload(); }
				else { $(document.body).trigger('update_checkout'); }
			}

			$(document).on('click', '.es-tcg-locker .es-locker-toggle', function(e){
				e.preventDefault();
				var $box = $(this).closest('.es-tcg-locker');
				$box.find('.es-locker-search-wrap').slideToggle(120, function(){ $box.find('.es-locker-q').trigger('focus'); });
			});

			$(document).on('input', '.es-tcg-locker .es-locker-q', function(){
				var $box = $(this).closest('.es-tcg-locker');
				var q = $(this).val();
				clearTimeout(timer);
				timer = setTimeout(function(){ doSearch($box, q); }, 300);
			});

			function doSearch($box, q){
				var $ul = $box.find('.es-locker-results');
				$ul.html('<li class="es-locker-loading"><?php echo esc_js( __( 'Searching…', 'erpnext-shipping' ) ); ?></li>');
				$.post(ajaxurl, { action:'es_tcg_locker_search', _wpnonce:nonce, q:q }, function(r){
					$ul.empty();
					if(!r || !r.success || !r.data.lockers || !r.data.lockers.length){
						$ul.html('<li class="es-locker-empty"><?php echo esc_js( __( 'No lockers found.', 'erpnext-shipping' ) ); ?></li>');
						return;
					}
					r.data.lockers.forEach(function(l){
						var $b = $('<button type="button" class="es-locker-pick" role="option">').attr('data-code', esc(l.code));
						$b.append($('<strong>').text(esc(l.name)));
						var meta = [l.address, l.town, l.postcode].filter(Boolean).join(', ');
						if(meta) $b.append($('<span class="es-locker-addr">').text(meta));
						if(l.sizes) $b.append($('<span class="es-locker-sizes">').text('<?php echo esc_js( __( 'Boxes', 'erpnext-shipping' ) ); ?>: ' + esc(l.sizes)));
						if(l.hours) $b.append($('<span class="es-locker-hours">').text('<?php echo esc_js( __( 'Hours', 'erpnext-shipping' ) ); ?>: ' + esc(l.hours)));
						$ul.append($('<li>').append($b));
					});
				});
			}

			$(document).on('click', '.es-tcg-locker .es-locker-pick', function(e){
				e.preventDefault();
				var code = $(this).data('code');
				$.post(ajaxurl, { action:'es_tcg_locker_select', _wpnonce:nonce, code:code }, function(r){
					if(!r || !r.success){
						window.alert(r && r.data && r.data.message ? r.data.message : '<?php echo esc_js( __( 'Could not select that locker.', 'erpnext-shipping' ) ); ?>');
						return;
					}
					refreshShipping();
				});
			});

			$(document).on('click', '.es-tcg-locker .es-locker-clear', function(e){
				e.preventDefault();
				$.post(ajaxurl, { action:'es_tcg_locker_clear', _wpnonce:nonce }, function(){
					refreshShipping();
				});
			});
		})(jQuery);
		</script>
		<?php
	}
}
