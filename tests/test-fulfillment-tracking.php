<?php
/** Provider inference for the order-edit Add Tracking default. */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() {}
}

require_once dirname( __DIR__ ) . '/includes/class-es-fulfillment-tracking.php';
require_once dirname( __DIR__ ) . '/includes/class-es-fulfillment-admin.php';

es_test( 'tracking provider defaults from the customer-selected shipping evidence', function () {
	es_eq( 'tcg-locker', ES_Fulfillment_Tracking::infer_provider_from_shipping( 'erpnext_shipping_locker', 'TCG Locker Delivery — Centurion', '', false ), 'locker method id/title wins' );
	es_eq( 'tcg-locker', ES_Fulfillment_Tracking::infer_provider_from_shipping( 'erpnext_shipping', 'Economy Shipping', '', true ), 'persisted locker meta wins even with a legacy method id' );
	es_eq( 'tcg-locker', ES_Fulfillment_Tracking::infer_provider_from_shipping( 'flat_rate', 'PUDO Locker', '', false ), 'PUDO title alias maps to locker' );
	es_eq( 'mds-collivery', ES_Fulfillment_Tracking::infer_provider_from_shipping( 'erpnext_shipping_standard', 'Standard Shipping', 'MDS Collivery', false ), 'persisted winning MDS carrier maps canonically' );
	es_eq( 'the-courier-guy', ES_Fulfillment_Tracking::infer_provider_from_shipping( 'erpnext_shipping_economy', 'Economy Shipping', 'The Courier Guy', false ), 'persisted winning TCG door carrier maps canonically' );
	es_eq( 'mds-collivery', ES_Fulfillment_Tracking::infer_provider_from_shipping( 'flat_rate', 'MDS Collivery', '', false ), 'legacy title inference works' );
	es_eq( '', ES_Fulfillment_Tracking::infer_provider_from_shipping( 'erpnext_shipping_standard', 'Standard Shipping', '', false ), 'generic tier alone does not invent a carrier' );
} );

es_test( 'locker quote snapshot stays hidden from the admin order-item display', function () {
	$hidden = ES_Fulfillment_Admin::hide_internal_order_item_meta( array( '_legacy_hidden' ) );
	es_ok( in_array( '_legacy_hidden', $hidden, true ), 'existing hidden keys preserved' );
	es_ok( in_array( ES_TCG_Locker_Rate::M_DEST_CODE, $hidden, true ), 'destination code hidden' );
	es_ok( in_array( ES_TCG_Locker_Rate::M_PROVIDER_RATE, $hidden, true ), 'provider economics hidden' );
	es_ok( in_array( ES_TCG_Locker_Rate::M_QUOTE_TS, $hidden, true ), 'quote timestamp hidden' );
	es_eq( count( $hidden ), count( array_unique( $hidden ) ), 'hidden list remains duplicate-free' );
} );
