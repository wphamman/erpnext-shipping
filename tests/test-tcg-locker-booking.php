<?php
/**
 * Tests for ES_TCG_Locker_Booking (Phase 4) — the pure guard rails around the
 * manual, idempotent booking action: pre-flight guard, fresh-rate drift, and the
 * booked/failed/AMBIGUOUS classification of a create_shipment() result.
 */

defined( 'ABSPATH' ) || exit;

/** A persisted checkout snapshot (subset of ES_TCG_Locker_Rate::M_* keys). */
$SNAP = array(
	ES_TCG_Locker_Rate::M_SERVICE_CODE  => 'L2LL - ECO',
	ES_TCG_Locker_Rate::M_BOX_CODE      => '13',
	ES_TCG_Locker_Rate::M_PROVIDER_RATE => '92',
	ES_TCG_Locker_Rate::M_REVISION_ID   => 'rev_l_1',
	ES_TCG_Locker_Rate::M_DEST_CODE     => 'CG929',
);

/** A fresh offer that matches the snapshot exactly. */
$FRESH_MATCH = array(
	array( 'service_code' => 'L2LL - ECO', 'box_code' => '13', 'rate' => 92.0, 'rate_revision_id' => 'rev_l_1' ),
	array( 'service_code' => 'L2LXL - ECO', 'box_code' => '14', 'rate' => 115.0, 'rate_revision_id' => 'rev_xl_1' ),
);

es_test( 'guard: fresh order can book; booked id and ambiguous state both block', function () {
	$g = ES_TCG_Locker_Booking::guard( '', ES_TCG_Locker_Booking::STATE_NONE );
	es_ok( $g['can'], 'no shipment id, no prior state → can book' );

	$g2 = ES_TCG_Locker_Booking::guard( 'SHP-1001', ES_TCG_Locker_Booking::STATE_BOOKED );
	es_ok( ! $g2['can'], 'existing shipment id → blocked (duplicate guard)' );
	es_eq( 'already_booked', $g2['reason'], 'reason already_booked' );

	// A stored shipment id blocks regardless of the recorded state.
	$g3 = ES_TCG_Locker_Booking::guard( 'SHP-1001', ES_TCG_Locker_Booking::STATE_NONE );
	es_ok( ! $g3['can'], 'shipment id alone blocks even with empty state' );

	$g4 = ES_TCG_Locker_Booking::guard( '', ES_TCG_Locker_Booking::STATE_AMBIGUOUS );
	es_ok( ! $g4['can'], 'ambiguous prior attempt → blocked (no auto-retry)' );
	es_eq( 'ambiguous_locked', $g4['reason'], 'reason ambiguous_locked' );
} );

es_test( 'classify_result: a real shipment id is BOOKED', function () {
	$o = ES_TCG_Locker_Booking::classify_result( array( 'ok' => true, 'shipment_id' => 'SHP-1001', 'tracking_ref' => 'WB1' ) );
	es_eq( ES_TCG_Locker_Booking::STATE_BOOKED, $o['state'], 'state booked' );
	es_eq( 'SHP-1001', $o['shipment_id'], 'shipment id carried' );
	es_eq( 'WB1', $o['tracking_ref'], 'tracking ref carried' );
	es_eq( '', $o['error'], 'no error' );
} );

es_test( 'classify_result: ok but no shipment id is AMBIGUOUS (never silent success)', function () {
	$o = ES_TCG_Locker_Booking::classify_result( array( 'ok' => true, 'shipment_id' => '' ) );
	es_eq( ES_TCG_Locker_Booking::STATE_AMBIGUOUS, $o['state'], 'ok-without-id → ambiguous' );
	es_eq( '', $o['shipment_id'], 'no shipment id stored' );
	es_eq( 'no_shipment_id', $o['error'], 'error no_shipment_id' );
} );

es_test( 'classify_result: transport / timeout / 5xx / unparseable-2xx are AMBIGUOUS (no auto-retry)', function () {
	foreach ( array(
		array( 'ok' => false, 'error' => 'transport' ),                 // network/timeout.
		array( 'ok' => false, 'error' => 'shape' ),                     // 2xx but unrecognised body.
		array( 'ok' => false, 'error' => 'decode' ),                    // 2xx but non-JSON.
		array( 'ok' => false, 'error' => 'http', 'code' => 500 ),       // server error.
		array( 'ok' => false, 'error' => 'http', 'code' => 502 ),
		array( 'ok' => false, 'error' => 'http', 'code' => 504 ),       // gateway timeout.
		array( 'ok' => false, 'error' => 'mystery' ),                   // unknown → fail safe.
		array(),                                                        // empty → fail safe.
	) as $res ) {
		$o = ES_TCG_Locker_Booking::classify_result( $res );
		es_eq( ES_TCG_Locker_Booking::STATE_AMBIGUOUS, $o['state'], 'ambiguous for ' . wp_json_encode_or_var( $res ) );
		es_eq( '', $o['shipment_id'], 'no shipment id on ambiguous' );
	}
} );

es_test( 'classify_result: definite client rejection (missing args / HTTP 4xx) is FAILED (retry ok)', function () {
	$o1 = ES_TCG_Locker_Booking::classify_result( array( 'ok' => false, 'error' => 'missing_args' ) );
	es_eq( ES_TCG_Locker_Booking::STATE_FAILED, $o1['state'], 'missing_args → failed' );
	es_eq( 'missing_args', $o1['error'], 'error carried' );

	foreach ( array( 400, 401, 403, 404, 422 ) as $code ) {
		$o = ES_TCG_Locker_Booking::classify_result( array( 'ok' => false, 'error' => 'http', 'code' => $code ) );
		es_eq( ES_TCG_Locker_Booking::STATE_FAILED, $o['state'], "HTTP $code → failed" );
		es_eq( 'http_' . $code, $o['error'], "error http_$code" );
	}
} );

es_test( 'detect_drift: exact-match live quote does NOT drift', function () use ( $SNAP, $FRESH_MATCH ) {
	$d = ES_TCG_Locker_Booking::detect_drift( $SNAP, $FRESH_MATCH );
	es_ok( ! $d['drift'], 'identical service/box/revision/price → no drift' );
} );

es_test( 'detect_drift: no fresh quote refuses (cannot certify price)', function () use ( $SNAP ) {
	$d = ES_TCG_Locker_Booking::detect_drift( $SNAP, array() );
	es_ok( $d['drift'], 'empty offers → drift' );
	es_eq( 'no_fresh_quote', $d['reason'], 'reason no_fresh_quote' );
} );

es_test( 'detect_drift: service gone, box changed, revision changed, price moved all drift', function () use ( $SNAP ) {
	// Service no longer offered.
	$d1 = ES_TCG_Locker_Booking::detect_drift( $SNAP, array( array( 'service_code' => 'L2LXL - ECO', 'box_code' => '14', 'rate' => 115.0 ) ) );
	es_eq( 'service_unavailable', $d1['reason'], 'service_unavailable' );

	// Same service, different box id.
	$d2 = ES_TCG_Locker_Booking::detect_drift( $SNAP, array( array( 'service_code' => 'L2LL - ECO', 'box_code' => '99', 'rate' => 92.0, 'rate_revision_id' => 'rev_l_1' ) ) );
	es_eq( 'box_changed', $d2['reason'], 'box_changed' );

	// Same service/box, new revision.
	$d3 = ES_TCG_Locker_Booking::detect_drift( $SNAP, array( array( 'service_code' => 'L2LL - ECO', 'box_code' => '13', 'rate' => 92.0, 'rate_revision_id' => 'rev_l_2' ) ) );
	es_eq( 'revision_changed', $d3['reason'], 'revision_changed' );

	// Same service/box/revision, price moved beyond a cent.
	$d4 = ES_TCG_Locker_Booking::detect_drift( $SNAP, array( array( 'service_code' => 'L2LL - ECO', 'box_code' => '13', 'rate' => 99.0, 'rate_revision_id' => 'rev_l_1' ) ) );
	es_eq( 'price_changed', $d4['reason'], 'price_changed' );
} );

es_test( 'detect_drift: sub-cent price wobble is tolerated', function () use ( $SNAP ) {
	$d = ES_TCG_Locker_Booking::detect_drift( $SNAP, array( array( 'service_code' => 'L2LL - ECO', 'box_code' => '13', 'rate' => 92.009, 'rate_revision_id' => 'rev_l_1' ) ) );
	es_ok( ! $d['drift'], 'price within PRICE_EPSILON → no drift' );
} );

es_test( 'detect_drift: a snapshot missing its service code refuses', function () use ( $FRESH_MATCH ) {
	$d = ES_TCG_Locker_Booking::detect_drift( array(), $FRESH_MATCH );
	es_ok( $d['drift'], 'no persisted service → drift' );
	es_eq( 'no_persisted_service', $d['reason'], 'reason no_persisted_service' );
} );

/** Tiny helper so the harness (no WP) can stringify a value in a message. */
function wp_json_encode_or_var( $v ) {
	return function_exists( 'json_encode' ) ? json_encode( $v ) : var_export( $v, true );
}
