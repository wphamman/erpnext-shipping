<?php
/** Runtime-light tests for the cart/checkout locker-rate session transition. */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'WC' ) ) {
	function WC() {
		return $GLOBALS['es_checkout_fake_wc'];
	}
}

class ES_Checkout_Fake_Session {
	public $data = array();
	public $unset_keys = array();

	public function get( $key, $default = null ) {
		return array_key_exists( $key, $this->data ) ? $this->data[ $key ] : $default;
	}

	public function set( $key, $value ) {
		$this->data[ $key ] = $value;
	}

	public function __unset( $key ) {
		$this->unset_keys[] = $key;
		unset( $this->data[ $key ] );
	}
}

class ES_Checkout_Fake_Shipping {
	public $packages = array();

	public function get_packages() {
		return $this->packages;
	}
}

class ES_Checkout_Fake_Cart {
	public $calculate_calls = 0;
	private $shipping;

	public function __construct( $shipping ) {
		$this->shipping = $shipping;
	}

	public function calculate_shipping() {
		$this->calculate_calls++;
		$this->shipping->packages = array(
			array(
				'rates' => array(
					'erpnext_shipping:49' => new stdClass(),
					'erpnext_shipping_locker' => new stdClass(),
				),
			),
		);
	}
}

class ES_Checkout_Fake_WC {
	public $session;
	public $cart;
	private $shipping_instance;

	public function __construct() {
		$this->session           = new ES_Checkout_Fake_Session();
		$this->shipping_instance = new ES_Checkout_Fake_Shipping();
		$this->cart              = new ES_Checkout_Fake_Cart( $this->shipping_instance );
	}

	public function shipping() {
		return $this->shipping_instance;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-es-tcg-locker-checkout.php';

es_test( 'locker selection invalidates WC package cache and auto-chooses priced rate', function () {
	$wc = new ES_Checkout_Fake_WC();
	$wc->session->data['shipping_for_package_0'] = array( 'rates' => array( 'erpnext_shipping:49' ) );
	$wc->session->data['chosen_shipping_methods'] = array( 'erpnext_shipping:49' );
	$wc->shipping()->packages = array( array( 'rates' => array( 'erpnext_shipping:49' => new stdClass() ) ) );
	$GLOBALS['es_checkout_fake_wc'] = $wc;

	$method  = new ReflectionMethod( ES_TCG_Locker_Checkout::class, 'refresh_and_choose_locker_rate' );
	$rate_id = $method->invoke( null );

	es_eq( 'erpnext_shipping_locker', $rate_id, 'newly-calculated locker rate returned' );
	es_eq( 1, $wc->cart->calculate_calls, 'shipping recalculated exactly once' );
	es_ok( in_array( 'shipping_for_package_0', $wc->session->unset_keys, true ), 'stale package cache explicitly invalidated' );
	es_eq( array( 'erpnext_shipping_locker' ), $wc->session->get( 'chosen_shipping_methods' ), 'locker becomes the actual chosen WC radio' );
} );

es_test( 'clearing locker selection removes chosen locker rate and invalidates cache', function () {
	$wc = new ES_Checkout_Fake_WC();
	$wc->session->data['shipping_for_package_0'] = array( 'rates' => array( 'erpnext_shipping_locker' ) );
	$wc->session->data['chosen_shipping_methods'] = array( 'erpnext_shipping_locker' );
	$wc->shipping()->packages = array( array( 'rates' => array( 'erpnext_shipping_locker' => new stdClass() ) ) );
	$GLOBALS['es_checkout_fake_wc'] = $wc;

	$method = new ReflectionMethod( ES_TCG_Locker_Checkout::class, 'unchoose_locker_rate' );
	$method->invoke( null );

	es_eq( array(), $wc->session->get( 'chosen_shipping_methods' ), 'removed locker rate is no longer chosen' );
	es_ok( in_array( 'shipping_for_package_0', $wc->session->unset_keys, true ), 'clear invalidates the package cache too' );
} );
