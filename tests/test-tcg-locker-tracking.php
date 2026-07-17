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

// ─────────── Arrival detection + collection window (v1.15.0) ───────────
//
// Payload shape below is taken VERBATIM from a real production tracking response
// (order #30322, waybill LL-D1VDVR, probed 2026-07-17): events are newest-first,
// dates are naive SAST with fractional seconds.

function es_test_locker_payload() {
	return array(
		'shipment_id'           => 21660605,
		'status'                => 'delivered',
		'shipment_time_created' => '2026-07-14 11:23:55',
		'tracking_events'       => array(
			array( 'status' => 'delivered',   'date' => '2026-07-15 10:51:52.255832', 'location' => 'MAL' ),
			array( 'status' => 'in-locker',   'date' => '2026-07-15 08:03:55.596697', 'location' => 'MAL' ),
			array( 'status' => 'in-transit',  'date' => '2026-07-14 20:11:02.000000', 'location' => 'JNB' ),
			array( 'status' => 'collected',   'date' => '2026-07-14 16:21:42.000000', 'location' => 'CPT' ),
		),
	);
}

es_test( 'first_event_time: finds the in-locker event date from a real payload', function () {
	es_eq( '2026-07-15 08:03:55.596697',
		ES_TCG_Locker_Tracking::first_event_time( es_test_locker_payload(), 'in-locker' ),
		'in-locker event located' );
} );

es_test( 'first_event_time: absent status / empty / malformed → empty string', function () {
	$p = es_test_locker_payload();
	es_eq( '', ES_TCG_Locker_Tracking::first_event_time( $p, 'customer-collected' ), 'status not present' );
	es_eq( '', ES_TCG_Locker_Tracking::first_event_time( array(), 'in-locker' ), 'empty payload' );
	es_eq( '', ES_TCG_Locker_Tracking::first_event_time( 'nonsense', 'in-locker' ), 'non-array payload' );
	es_eq( '', ES_TCG_Locker_Tracking::first_event_time( $p, '' ), 'empty status' );
	es_eq( '', ES_TCG_Locker_Tracking::first_event_time( array( 'tracking_events' => 'bad' ), 'in-locker' ), 'events not an array' );
} );

es_test( 'first_event_time: takes the EARLIEST match when a parcel re-enters the locker', function () {
	$p = array( 'tracking_events' => array(
		array( 'status' => 'in-locker', 'date' => '2026-07-16 09:00:00' ), // re-deposit
		array( 'status' => 'in-locker', 'date' => '2026-07-15 08:03:55' ), // first arrival
	) );
	es_eq( '2026-07-15 08:03:55', ES_TCG_Locker_Tracking::first_event_time( $p, 'in-locker' ),
		'clock starts at first arrival, not the latest event' );
} );

es_test( 'first_event_time: resolves nested shipment/data nodes', function () {
	$inner = es_test_locker_payload();
	es_eq( '2026-07-15 08:03:55.596697',
		ES_TCG_Locker_Tracking::first_event_time( array( 'shipment' => $inner ), 'in-locker' ), 'shipment node' );
	es_eq( '2026-07-15 08:03:55.596697',
		ES_TCG_Locker_Tracking::first_event_time( array( 'data' => $inner ), 'in-locker' ), 'data node' );
} );

es_test( 'provider_tz: is the provider clock (SAST), never the site clock', function () {
	es_eq( 'Africa/Johannesburg', ES_TCG_Locker_Tracking::PROVIDER_TZ, 'provider tz constant' );
	es_ok( ES_TCG_Locker_Tracking::provider_tz() instanceof DateTimeZone, 'provider_tz() returns a DateTimeZone' );
	es_eq( 'Africa/Johannesburg', ES_TCG_Locker_Tracking::provider_tz()->getName(), 'provider_tz() name' );
} );

es_test( 'event_timestamp: reads naive provider dates in the given zone (SAST)', function () {
	$tz = ES_TCG_Locker_Tracking::provider_tz();
	// 2026-07-15 08:03:55 SAST == 06:03:55 UTC
	$expected = ( new DateTime( '2026-07-15 06:03:55', new DateTimeZone( 'UTC' ) ) )->getTimestamp();
	es_eq( $expected, ES_TCG_Locker_Tracking::event_timestamp( '2026-07-15 08:03:55.596697', $tz ),
		'fractional seconds tolerated; SAST basis' );
	es_eq( $expected, ES_TCG_Locker_Tracking::event_timestamp( '2026-07-15 08:03:55', $tz ),
		'without fractional seconds' );
} );

es_test( 'event_timestamp: same instant regardless of the SITE timezone', function () {
	// A store running WordPress on UTC must still read TCG's naive SAST correctly.
	$raw = '2026-07-15 08:03:55';
	$via_provider = ES_TCG_Locker_Tracking::event_timestamp( $raw, ES_TCG_Locker_Tracking::provider_tz() );
	$via_utc      = ES_TCG_Locker_Tracking::event_timestamp( $raw, new DateTimeZone( 'UTC' ) );
	es_ok( $via_provider !== $via_utc, 'reading naive SAST as UTC yields a different instant' );
	es_eq( 7200, $via_utc - $via_provider, 'the UTC misreading lands 2h LATE — exactly the deadline hazard' );
} );

es_test( 'event_timestamp: unparseable / empty / bad tz → 0 (never a bogus deadline)', function () {
	$tz = ES_TCG_Locker_Tracking::provider_tz();
	es_eq( 0, ES_TCG_Locker_Tracking::event_timestamp( '', $tz ), 'empty' );
	es_eq( 0, ES_TCG_Locker_Tracking::event_timestamp( 'not a date', $tz ), 'garbage' );
	es_eq( 0, ES_TCG_Locker_Tracking::event_timestamp( '2026-07-15 08:03:55', null ), 'missing tz' );
} );

es_test( 'sane_collection_hours: default 36, clamps nonsense', function () {
	es_eq( 36.0, ES_TCG_Locker_Tracking::sane_collection_hours( null ), 'unset → 36' );
	es_eq( 36.0, ES_TCG_Locker_Tracking::sane_collection_hours( '' ), 'empty → 36' );
	es_eq( 36.0, ES_TCG_Locker_Tracking::sane_collection_hours( 'abc' ), 'garbage → 36' );
	es_eq( 36.0, ES_TCG_Locker_Tracking::sane_collection_hours( 0 ), 'zero → 36' );
	es_eq( 36.0, ES_TCG_Locker_Tracking::sane_collection_hours( -5 ), 'negative → 36' );
	es_eq( 36.0, ES_TCG_Locker_Tracking::sane_collection_hours( 721 ), 'absurd → 36' );
	es_eq( 48.0, ES_TCG_Locker_Tracking::sane_collection_hours( '48' ), 'operator value honoured' );
	es_eq( 1.5,  ES_TCG_Locker_Tracking::sane_collection_hours( 1.5 ), 'fractional honoured' );
	es_eq( 24.0, ES_TCG_Locker_Tracking::sane_collection_hours( null, 24 ), 'caller default honoured' );
} );

es_test( 'collection_deadline: arrival + window; unknown arrival → 0', function () {
	$t = 1784102635; // arbitrary
	es_eq( $t + 36 * 3600, ES_TCG_Locker_Tracking::collection_deadline( $t, 36 ), '36h window' );
	es_eq( $t + 12 * 3600, ES_TCG_Locker_Tracking::collection_deadline( $t, 12 ), '12h window' );
	es_eq( $t + 36 * 3600, ES_TCG_Locker_Tracking::collection_deadline( $t, 'junk' ), 'bad window falls back to 36' );
	es_eq( 0, ES_TCG_Locker_Tracking::collection_deadline( 0, 36 ), 'unknown arrival → 0 (no deadline stated)' );
	es_eq( 0, ES_TCG_Locker_Tracking::collection_deadline( -1, 36 ), 'negative arrival → 0' );
} );

es_test( 'end-to-end: real payload → deadline is 36h after the true arrival, not poll time', function () {
	$tz      = ES_TCG_Locker_Tracking::provider_tz();
	$raw     = ES_TCG_Locker_Tracking::first_event_time( es_test_locker_payload(), 'in-locker' );
	$arrival = ES_TCG_Locker_Tracking::event_timestamp( $raw, $tz );
	$dl      = ES_TCG_Locker_Tracking::collection_deadline( $arrival, 36 );
	$shown   = ( new DateTime( '@' . $dl ) )->setTimezone( $tz )->format( 'Y-m-d H:i:s' );
	// Arrived 2026-07-15 08:03:55 SAST → must expire 2026-07-16 20:03:55 SAST.
	es_eq( '2026-07-16 20:03:55', $shown, 'deadline anchored to provider arrival event' );
} );

es_test( 'REGRESSION: a delivered parcel keeps its in-locker event forever', function () {
	// Guards the defect that history alone must never drive the arrival email:
	// #30322/#30374 are DELIVERED yet still carry an in-locker event, so keying on
	// history would mail "your parcel is waiting" to customers who already collected.
	// The event is still findable — the CRON gates on current status, not on this.
	$p = es_test_locker_payload();
	es_eq( 'delivered', $p['status'], 'fixture is a delivered shipment (real #30322)' );
	es_ok( '' !== ES_TCG_Locker_Tracking::first_event_time( $p, 'in-locker' ),
		'in-locker event persists in a delivered parcel history — hence the status gate' );
} );

es_test( 'collection_deadline: a lapsed window is detectable (past deadline)', function () {
	$long_ago = 1600000000; // 2020
	$dl = ES_TCG_Locker_Tracking::collection_deadline( $long_ago, 36 );
	es_ok( $dl > 0, 'deadline computed' );
	es_ok( $dl < time(), 'deadline is in the past — cron suppresses rather than stating it' );
} );
