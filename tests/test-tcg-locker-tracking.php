<?php
/**
 * Tests for ES_TCG_Locker_Tracking (Phase 5) — the conservative forward-only §12
 * status map. Verifies the exact vocabulary, the deliberate in-locker/customer-
 * collected asymmetry, and that everything else (exceptions, cancellations, and
 * ANY unknown) never advances WC status.
 */

defined( 'ABSPATH' ) || exit;

es_test( 'map_status: accepted handoff/transit states → completed (Shipped)', function () {
	foreach ( array(
		'customer-deposited',
		'courier-collected',
		'collected',
		'at-hub',
		'in-transit',
		'at-destination-hub',
		'delivery-assigned',
		'out-for-delivery',
		'in-locker',
	) as $s ) {
		es_eq( 'completed', ES_TCG_Locker_Tracking::map_status( $s ), "$s → completed (Shipped)" );
	}
} );

es_test( 'map_status: only customer-collected and delivered → delivered', function () {
	es_eq( 'delivered', ES_TCG_Locker_Tracking::map_status( 'customer-collected' ), 'customer-collected → delivered' );
	es_eq( 'delivered', ES_TCG_Locker_Tracking::map_status( 'delivered' ), 'delivered → delivered' );
} );

es_test( 'map_status: the deliberate asymmetry (§12) holds', function () {
	// In the destination locker but not yet collected → Shipped, NOT delivered.
	es_eq( 'completed', ES_TCG_Locker_Tracking::map_status( 'in-locker' ), 'in-locker → Shipped (NOT delivered)' );
	// Customer took it out → delivered.
	es_eq( 'delivered', ES_TCG_Locker_Tracking::map_status( 'customer-collected' ), 'customer-collected → delivered' );
} );

es_test( 'map_status: exceptions / cancellations / pre-handoff never advance', function () {
	foreach ( array(
		'quote-pending',
		'payment-failed',
		'submitted',
		'deposit-pending',
		'collection-assigned',
		'collection-unassigned',
		'collection-rejected',
		'collection-exception',
		'collection-failed',
		'delivery-exception',
		'delivery-failed-attempt',
		'returned-to-hub',
		'cancelled',
		'cancel-booking-expired',
	) as $s ) {
		es_eq( null, ES_TCG_Locker_Tracking::map_status( $s ), "$s → no advancement (null)" );
	}
} );

es_test( 'map_status: unknown / empty / junk → null (never advance)', function () {
	es_eq( null, ES_TCG_Locker_Tracking::map_status( 'totally-made-up' ), 'unknown → null' );
	es_eq( null, ES_TCG_Locker_Tracking::map_status( '' ), 'empty → null' );
	es_eq( null, ES_TCG_Locker_Tracking::map_status( '   ' ), 'whitespace → null' );
	es_eq( null, ES_TCG_Locker_Tracking::map_status( null ), 'null input → null' );
} );

es_test( 'map_status: case- and whitespace-insensitive', function () {
	es_eq( 'completed', ES_TCG_Locker_Tracking::map_status( 'In-Transit' ), 'mixed case' );
	es_eq( 'completed', ES_TCG_Locker_Tracking::map_status( '  IN-LOCKER  ' ), 'upper + padded' );
	es_eq( 'delivered', ES_TCG_Locker_Tracking::map_status( 'Delivered' ), 'capitalised delivered' );
} );

es_test( 'is_known + label helpers', function () {
	es_ok( ES_TCG_Locker_Tracking::is_known( 'in-locker' ), 'in-locker is known vocabulary' );
	es_ok( ES_TCG_Locker_Tracking::is_known( 'CANCELLED' ), 'known is case-insensitive' );
	es_ok( ! ES_TCG_Locker_Tracking::is_known( 'made-up' ), 'unknown is not known' );
	es_eq( 'In Locker', ES_TCG_Locker_Tracking::label( 'in-locker' ), 'label humanises slug' );
	es_eq( 'Customer Deposited', ES_TCG_Locker_Tracking::label( 'customer-deposited' ), 'label multi-word' );
	es_eq( '', ES_TCG_Locker_Tracking::label( '' ), 'empty label' );

	// Every "advancing" status must also be in the documented vocabulary.
	foreach ( array( 'in-locker', 'in-transit', 'customer-collected', 'delivered' ) as $s ) {
		es_ok( ES_TCG_Locker_Tracking::is_known( $s ), "$s is in the vocabulary" );
	}
} );
