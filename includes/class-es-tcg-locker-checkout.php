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
		// Primary placement: always present in the review table, even before a
		// `_locker` rate exists (woocommerce_after_shipping_rate only fires
		// beneath an existing rate, so it cannot host the initial prompt).
		add_action( 'woocommerce_review_order_after_shipping', array( __CLASS__, 'render_selector' ) );

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

	/** Render the selector row in the checkout review table. */
	public static function render_selector() {
		$client = es_tcg_locker_client();
		if ( ! $client ) {
			return; // Disabled or not configured — no selector, no rate.
		}
		$sel = self::get_selected_locker();
		?>
		<tr class="es-tcg-locker-row">
			<th><?php esc_html_e( 'TCG Locker', 'erpnext-shipping' ); ?></th>
			<td>
				<div id="es-tcg-locker">
					<?php if ( $sel ) : ?>
						<div class="es-locker-selected">
							<strong><?php echo esc_html( $sel['name'] ); ?></strong>
							<?php if ( ! empty( $sel['address'] ) ) : ?>
								<br><span class="es-locker-addr"><?php echo esc_html( $sel['address'] ); ?></span>
							<?php endif; ?>
							<br>
							<a href="#" class="es-locker-toggle"><?php esc_html_e( 'Change locker', 'erpnext-shipping' ); ?></a>
							&middot;
							<a href="#" class="es-locker-clear"><?php esc_html_e( 'Remove', 'erpnext-shipping' ); ?></a>
						</div>
					<?php else : ?>
						<p class="es-locker-prompt"><?php esc_html_e( 'Choose a TCG Locker for cheaper delivery', 'erpnext-shipping' ); ?></p>
					<?php endif; ?>

					<div id="es-locker-search-wrap"<?php echo $sel ? ' style="display:none;"' : ''; ?>>
						<label for="es-locker-q" class="screen-reader-text"><?php esc_html_e( 'Search lockers by name, town or postcode', 'erpnext-shipping' ); ?></label>
						<input type="text" id="es-locker-q" autocomplete="off" placeholder="<?php esc_attr_e( 'Search by name, town or postcode…', 'erpnext-shipping' ); ?>">
						<ul id="es-locker-results" role="listbox" aria-live="polite"></ul>
					</div>
				</div>
			</td>
		</tr>
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
		wp_send_json_success(
			array(
				'locker' => array(
					'code'    => $locker['code'],
					'name'    => $locker['name'],
					'address' => $locker['address'],
				),
			)
		);
	}

	public static function ajax_clear() {
		check_ajax_referer( self::NONCE );
		if ( WC()->session ) {
			WC()->session->set( self::SESSION_KEY, null );
		}
		wp_send_json_success();
	}

	/**
	 * Block placing a TCG Locker order without a valid, server-validated locker.
	 */
	public static function validate() {
		$chosen = ( WC()->session ) ? (array) WC()->session->get( 'chosen_shipping_methods', array() ) : array();
		$is_locker = false;
		foreach ( $chosen as $m ) {
			if ( is_string( $m ) && '_locker' === substr( $m, -7 ) ) {
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
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		if ( ! es_tcg_locker_client() ) {
			return;
		}
		$ajax  = admin_url( 'admin-ajax.php' );
		$nonce = wp_create_nonce( self::NONCE );
		?>
		<style>
			#es-tcg-locker .es-locker-prompt{margin:0 0 6px;font-weight:600}
			#es-tcg-locker #es-locker-q{width:100%;box-sizing:border-box;padding:8px 10px;margin:4px 0}
			#es-tcg-locker #es-locker-results{list-style:none;margin:6px 0 0;padding:0;max-height:260px;overflow-y:auto}
			#es-tcg-locker #es-locker-results li{margin:0 0 4px;padding:0}
			#es-tcg-locker .es-locker-pick{display:block;width:100%;text-align:left;padding:8px 10px;border:1px solid #ccc;border-radius:4px;background:#fff;cursor:pointer}
			#es-tcg-locker .es-locker-pick:hover,#es-tcg-locker .es-locker-pick:focus{border-color:#2a7d2e;background:#f3faf3}
			#es-tcg-locker .es-locker-pick strong{display:block}
			#es-tcg-locker .es-locker-addr,#es-tcg-locker .es-locker-sizes,#es-tcg-locker .es-locker-hours{display:block;font-size:.85em;color:#555}
			#es-tcg-locker .es-locker-loading,#es-tcg-locker .es-locker-empty{font-size:.9em;color:#777;padding:6px 2px}
			@media(max-width:600px){#es-tcg-locker #es-locker-q{font-size:16px}}
		</style>
		<script>
		(function($){
			var ajaxurl = <?php echo wp_json_encode( $ajax ); ?>;
			var nonce   = <?php echo wp_json_encode( $nonce ); ?>;
			var timer;
			function esc(s){ return String(s == null ? '' : s); }

			$(document).on('click', '#es-tcg-locker .es-locker-toggle', function(e){
				e.preventDefault();
				$('#es-locker-search-wrap').slideToggle(120, function(){ $('#es-locker-q').trigger('focus'); });
			});

			$(document).on('input', '#es-locker-q', function(){
				var q = $(this).val();
				clearTimeout(timer);
				timer = setTimeout(function(){ doSearch(q); }, 300);
			});

			function doSearch(q){
				var $ul = $('#es-locker-results');
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

			$(document).on('click', '#es-tcg-locker .es-locker-pick', function(e){
				e.preventDefault();
				var code = $(this).data('code');
				$.post(ajaxurl, { action:'es_tcg_locker_select', _wpnonce:nonce, code:code }, function(r){
					if(!r || !r.success){
						window.alert(r && r.data && r.data.message ? r.data.message : '<?php echo esc_js( __( 'Could not select that locker.', 'erpnext-shipping' ) ); ?>');
						return;
					}
					$(document.body).trigger('update_checkout');
				});
			});

			$(document).on('click', '#es-tcg-locker .es-locker-clear', function(e){
				e.preventDefault();
				$.post(ajaxurl, { action:'es_tcg_locker_clear', _wpnonce:nonce }, function(){
					$(document.body).trigger('update_checkout');
				});
			});
		})(jQuery);
		</script>
		<?php
	}
}
