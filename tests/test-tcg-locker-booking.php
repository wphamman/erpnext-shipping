<?php
/**
 * Tests for ES_TCG_Locker_Booking (Phase 4) — the pure guard rails around the
 * manual, idempotent booking action: pre-flight guard (incl. durable in-progress
 * and ambiguous locks), fail-closed fresh-rate drift, and the
 * booked/error/AMBIGUOUS classification of a create_shipment() result.
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

es_test( 'guard: fresh order can book; booked id, in-progress and ambiguous all block', function () {
	$g = ES_TCG_Locker_Booking::guard( '', ES_TCG_Locker_Booking::STATE_NONE );
	es_ok( $g['can'], 'no shipment id, no prior state → can book' );
	es_ok( ES_TCG_Locker_Booking::guard( '', '' )['can'], 'empty status treated as none → can book' );

	$g2 = ES_TCG_Locker_Booking::guard( 'SHP-1001', ES_TCG_Locker_Booking::STATE_BOOKED );
	es_ok( ! $g2['can'], 'existing shipment id → blocked (duplicate guard)' );
	es_eq( 'already_booked', $g2['reason'], 'reason already_booked' );

	$g3 = ES_TCG_Locker_Booking::guard( 'SHP-1001', ES_TCG_Locker_Booking::STATE_NONE );
	es_ok( ! $g3['can'], 'shipment id alone blocks even with none status' );

	// A durable in-progress marker (crash mid-call) hard-blocks re-book.
	$g4 = ES_TCG_Locker_Booking::guard( '', ES_TCG_Locker_Booking::STATE_BOOKING );
	es_ok( ! $g4['can'], 'in-progress booking → blocked (no silent re-book)' );
	es_eq( 'in_progress_locked', $g4['reason'], 'reason in_progress_locked' );

	$g5 = ES_TCG_Locker_Booking::guard( '', ES_TCG_Locker_Booking::STATE_AMBIGUOUS );
	es_ok( ! $g5['can'], 'ambiguous prior attempt → blocked (no auto-retry)' );
	es_eq( 'ambiguous_locked', $g5['reason'], 'reason ambiguous_locked' );

	// An error state is recoverable — booking is allowed again.
	es_ok( ES_TCG_Locker_Booking::guard( '', ES_TCG_Locker_Booking::STATE_ERROR )['can'], 'error state → can retry' );

	es_ok( ES_TCG_Locker_Booking::is_blocking_status( ES_TCG_Locker_Booking::STATE_BOOKING ), 'booking is blocking' );
	es_ok( ES_TCG_Locker_Booking::is_blocking_status( ES_TCG_Locker_Booking::STATE_AMBIGUOUS ), 'ambiguous is blocking' );
	es_ok( ! ES_TCG_Locker_Booking::is_blocking_status( ES_TCG_Locker_Booking::STATE_ERROR ), 'error is not blocking' );
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

es_test( 'classify_result: ambiguity-sensitive 4xx (408/409/429) are AMBIGUOUS, not retryable', function () {
	foreach ( array( 408, 409, 429 ) as $code ) {
		$o = ES_TCG_Locker_Booking::classify_result( array( 'ok' => false, 'error' => 'http', 'code' => $code ) );
		es_eq( ES_TCG_Locker_Booking::STATE_AMBIGUOUS, $o['state'], "HTTP $code → ambiguous (request may have been processed)" );
		es_eq( 'http_' . $code, $o['error'], "error http_$code" );
	}
} );

es_test( 'classify_result: definite client rejection (missing args / other HTTP 4xx) is ERROR (retry ok)', function () {
	$o1 = ES_TCG_Locker_Booking::classify_result( array( 'ok' => false, 'error' => 'missing_args' ) );
	es_eq( ES_TCG_Locker_Booking::STATE_ERROR, $o1['state'], 'missing_args → error' );
	es_eq( 'missing_args', $o1['error'], 'error carried' );

	foreach ( array( 400, 401, 403, 404, 422 ) as $code ) {
		$o = ES_TCG_Locker_Booking::classify_result( array( 'ok' => false, 'error' => 'http', 'code' => $code ) );
		es_eq( ES_TCG_Locker_Booking::STATE_ERROR, $o['state'], "HTTP $code → error" );
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

es_test( 'detect_drift: FAILS CLOSED on an incomplete snapshot (missing box or price)', function () use ( $FRESH_MATCH ) {
	// Only a service code — the classic fail-open case. Must refuse.
	$only_service = array( ES_TCG_Locker_Rate::M_SERVICE_CODE => 'L2LL - ECO' );
	$d1 = ES_TCG_Locker_Booking::detect_drift( $only_service, $FRESH_MATCH );
	es_ok( $d1['drift'], 'service-only snapshot → drift (no silent pass)' );
	es_eq( 'incomplete_snapshot', $d1['reason'], 'reason incomplete_snapshot (missing box)' );

	// Service + box but a non-numeric price.
	$no_price = array(
		ES_TCG_Locker_Rate::M_SERVICE_CODE  => 'L2LL - ECO',
		ES_TCG_Locker_Rate::M_BOX_CODE      => '13',
		ES_TCG_Locker_Rate::M_PROVIDER_RATE => '',
	);
	$d2 = ES_TCG_Locker_Booking::detect_drift( $no_price, $FRESH_MATCH );
	es_ok( $d2['drift'], 'missing price → drift' );
	es_eq( 'incomplete_snapshot', $d2['reason'], 'reason incomplete_snapshot (missing price)' );
} );

es_test( 'detect_drift: a fresh offer without a numeric price refuses', function () use ( $SNAP ) {
	$d = ES_TCG_Locker_Booking::detect_drift( $SNAP, array( array( 'service_code' => 'L2LL - ECO', 'box_code' => '13', 'rate' => null, 'rate_revision_id' => 'rev_l_1' ) ) );
	es_ok( $d['drift'], 'fresh offer with no numeric price → drift' );
	es_eq( 'no_fresh_price', $d['reason'], 'reason no_fresh_price' );
} );

es_test( 'detect_drift: service gone, box changed, revision changed, price moved all drift', function () use ( $SNAP ) {
	$d1 = ES_TCG_Locker_Booking::detect_drift( $SNAP, array( array( 'service_code' => 'L2LXL - ECO', 'box_code' => '14', 'rate' => 115.0 ) ) );
	es_eq( 'service_unavailable', $d1['reason'], 'service_unavailable' );

	$d2 = ES_TCG_Locker_Booking::detect_drift( $SNAP, array( array( 'service_code' => 'L2LL - ECO', 'box_code' => '99', 'rate' => 92.0, 'rate_revision_id' => 'rev_l_1' ) ) );
	es_eq( 'box_changed', $d2['reason'], 'box_changed' );

	$d3 = ES_TCG_Locker_Booking::detect_drift( $SNAP, array( array( 'service_code' => 'L2LL - ECO', 'box_code' => '13', 'rate' => 92.0, 'rate_revision_id' => 'rev_l_2' ) ) );
	es_eq( 'revision_changed', $d3['reason'], 'revision_changed' );

	$d4 = ES_TCG_Locker_Booking::detect_drift( $SNAP, array( array( 'service_code' => 'L2LL - ECO', 'box_code' => '13', 'rate' => 99.0, 'rate_revision_id' => 'rev_l_1' ) ) );
	es_eq( 'price_changed', $d4['reason'], 'price_changed' );
} );

es_test( 'detect_drift: revision must match EXACTLY (missing on either side is drift)', function () use ( $SNAP ) {
	// No persisted revision but a fresh revision present → drift (the fail-open case).
	$no_prev = array(
		ES_TCG_Locker_Rate::M_SERVICE_CODE  => 'L2LL - ECO',
		ES_TCG_Locker_Rate::M_BOX_CODE      => '13',
		ES_TCG_Locker_Rate::M_PROVIDER_RATE => '92',
		ES_TCG_Locker_Rate::M_REVISION_ID   => '',
	);
	$d1 = ES_TCG_Locker_Booking::detect_drift( $no_prev, array( array( 'service_code' => 'L2LL - ECO', 'box_code' => '13', 'rate' => 92.0, 'rate_revision_id' => 'rev_l_2' ) ) );
	es_ok( $d1['drift'], 'no persisted revision vs a fresh revision → drift' );
	es_eq( 'revision_changed', $d1['reason'], 'reason revision_changed' );

	// Persisted revision but fresh dropped it → drift.
	$d2 = ES_TCG_Locker_Booking::detect_drift( $SNAP, array( array( 'service_code' => 'L2LL - ECO', 'box_code' => '13', 'rate' => 92.0, 'rate_revision_id' => '' ) ) );
	es_ok( $d2['drift'], 'persisted revision vs a fresh blank → drift' );

	// Both sides genuinely have no revision (a provider that never issues them) → OK.
	$d3 = ES_TCG_Locker_Booking::detect_drift( $no_prev, array( array( 'service_code' => 'L2LL - ECO', 'box_code' => '13', 'rate' => 92.0, 'rate_revision_id' => '' ) ) );
	es_ok( ! $d3['drift'], 'both revisions absent → no drift' );
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

// ─────────── Staff re-quote planning (v1.15.1) ───────────

es_test( 'plan_requote: price + revision moved → ok, reports old/new and changed=true', function () use ( $SNAP ) {
	$offers = array(
		array( 'service_code' => 'L2LL - ECO', 'box_code' => '13', 'rate' => 99.0, 'rate_excluding_vat' => 86.09, 'rate_revision_id' => 'rev_l_2' ),
		array( 'service_code' => 'L2LXL - ECO', 'box_code' => '14', 'rate' => 115.0, 'rate_revision_id' => 'rev_xl_2' ),
	);
	$p = ES_TCG_Locker_Booking::plan_requote( $SNAP, $offers );
	es_ok( $p['ok'], 'same service+box, numeric price → re-quotable' );
	es_ok( $p['changed'], 'price/revision differ → changed' );
	es_eq( 92.0, $p['old_rate'], 'old_rate from snapshot' );
	es_eq( 99.0, $p['new_rate'], 'new_rate from live offer' );
	es_eq( 'rev_l_1', $p['old_rev'], 'old_rev' );
	es_eq( 'rev_l_2', $p['new_rev'], 'new_rev' );
	es_eq( 86.09, $p['new_rate_ex'], 'ex-VAT carried when positive' );
} );

es_test( 'plan_requote: identical live offer → ok but changed=false', function () use ( $SNAP, $FRESH_MATCH ) {
	$p = ES_TCG_Locker_Booking::plan_requote( $SNAP, $FRESH_MATCH );
	es_ok( $p['ok'], 'unchanged offer still re-quotable' );
	es_ok( ! $p['changed'], 'same rate + revision → not changed' );
} );

es_test( 'plan_requote: reuses detect_drift structural refusals (fail closed)', function () use ( $SNAP, $FRESH_MATCH ) {
	es_eq( 'no_persisted_service', ES_TCG_Locker_Booking::plan_requote( array(), $FRESH_MATCH )['reason'], 'no service' );

	$incomplete = array( ES_TCG_Locker_Rate::M_SERVICE_CODE => 'L2LL - ECO' );
	es_eq( 'incomplete_snapshot', ES_TCG_Locker_Booking::plan_requote( $incomplete, $FRESH_MATCH )['reason'], 'no box/price' );

	es_eq( 'no_fresh_quote', ES_TCG_Locker_Booking::plan_requote( $SNAP, array() )['reason'], 'no live offers' );

	$other = array( array( 'service_code' => 'L2LXL - ECO', 'box_code' => '14', 'rate' => 115.0, 'rate_revision_id' => 'rev_xl_1' ) );
	es_eq( 'service_unavailable', ES_TCG_Locker_Booking::plan_requote( $SNAP, $other )['reason'], 'chosen service gone' );

	$rebox = array( array( 'service_code' => 'L2LL - ECO', 'box_code' => '99', 'rate' => 92.0, 'rate_revision_id' => 'rev_l_1' ) );
	es_eq( 'box_changed', ES_TCG_Locker_Booking::plan_requote( $SNAP, $rebox )['reason'], 'provider box changed → refuse, not re-box' );

	$noprice = array( array( 'service_code' => 'L2LL - ECO', 'box_code' => '13', 'rate' => null, 'rate_revision_id' => 'rev_l_2' ) );
	es_eq( 'no_fresh_price', ES_TCG_Locker_Booking::plan_requote( $SNAP, $noprice )['reason'], 'no numeric fresh price' );

	$failed = ES_TCG_Locker_Booking::plan_requote( array(), $FRESH_MATCH );
	es_ok( ! $failed['ok'] && false === $failed['changed'], 'a failed plan is not ok and not changed' );
} );

es_test( 'lock_stale_threshold: derived from timeout plus order-write safety margin', function () {
	// Default/absent timeout → the client default (15) drives the bound, floored.
	es_eq( 180, ES_TCG_Locker_Booking::lock_stale_threshold( 0 ), 'absent → 180s floor' );
	es_eq( 180, ES_TCG_Locker_Booking::lock_stale_threshold( 15 ), 'default 15 → floored at 180' );
	es_eq( 180, ES_TCG_Locker_Booking::lock_stale_threshold( -5 ), 'negative → treated as default → floor' );

	// A raised timeout raises the threshold in lock-step: 3*call + 120s writes margin.
	es_eq( 3 * 30 + 120, ES_TCG_Locker_Booking::lock_stale_threshold( 30 ), 'timeout 30 → 210s' );
	es_eq( 3 * 120 + 120, ES_TCG_Locker_Booking::lock_stale_threshold( 120 ), 'timeout 120 → 480s (plus writes)' );
	es_eq( 3 * 600 + 120, ES_TCG_Locker_Booking::lock_stale_threshold( 600 ), 'uncapped high timeout still bounds correctly' );

	// The bound must always strictly exceed the single worst-case call so a live
	// request mid-provider-call can never be seen as stale.
	foreach ( array( 15, 30, 120, 600 ) as $t ) {
		es_ok( ES_TCG_Locker_Booking::lock_stale_threshold( $t ) > $t, "threshold > one call timeout ($t)" );
	}
} );

/** Tiny helper so the harness (no WP) can stringify a value in a message. */
function wp_json_encode_or_var( $v ) {
	return function_exists( 'json_encode' ) ? json_encode( $v ) : var_export( $v, true );
}

// ─────────── Dead-booking detection / re-book (v1.15.0) ───────────

es_test( 'is_dead_status: only terminal provider failures are dead', function () {
	foreach ( array( 'cancelled', 'cancel-booking-expired', 'CANCELLED', ' Cancel-Booking-Expired ' ) as $s ) {
		es_ok( ES_TCG_Locker_Booking::is_dead_status( $s ), "$s is dead (case/space tolerant)" );
	}
	// Anything a parcel can still move out of must NOT be clearable.
	foreach ( array(
		'in-locker', 'in-transit', 'submitted', 'deposit-pending', 'collected',
		'courier-collected', 'delivered', 'customer-collected', 'out-for-delivery',
		'delivery-exception', 'returned-to-hub', '', 'unknown-future-status',
	) as $s ) {
		es_ok( ! ES_TCG_Locker_Booking::is_dead_status( $s ), "'$s' is NOT dead — must never be auto-cleared" );
	}
} );

es_test( 'raw_from_courier_meta: extracts the locker status from combined meta', function () {
	es_eq( 'cancel-booking-expired',
		ES_TCG_Locker_Booking::raw_from_courier_meta( 'tcg-locker:cancel-booking-expired' ), 'single provider' );
	es_eq( 'in-locker',
		ES_TCG_Locker_Booking::raw_from_courier_meta( 'tcg:delivered, tcg-locker:in-locker' ), 'multi-provider meta' );
	es_eq( 'in-locker',
		ES_TCG_Locker_Booking::raw_from_courier_meta( 'tcg-locker:IN-LOCKER' ), 'normalised to lowercase' );
	es_eq( '', ES_TCG_Locker_Booking::raw_from_courier_meta( 'tcg:delivered, mds:in-transit' ), 'locker absent' );
	es_eq( '', ES_TCG_Locker_Booking::raw_from_courier_meta( '' ), 'empty meta' );
	// Must not be fooled by another provider whose status merely contains the word.
	es_eq( '', ES_TCG_Locker_Booking::raw_from_courier_meta( 'mds:tcg-locker-lookalike' ), 'prefix must anchor' );
} );

es_test( 'REGRESSION: a live booking is never classified dead (the #30322 shape)', function () {
	// #30322 polled 'delivered' with a real shipment id — clearing that would
	// destroy our only record of a shipment the customer actually received.
	es_ok( ! ES_TCG_Locker_Booking::is_dead_status( 'delivered' ), 'delivered is not dead' );
	es_ok( ! ES_TCG_Locker_Booking::is_dead_status(
		ES_TCG_Locker_Booking::raw_from_courier_meta( 'tcg-locker:delivered' ) ), 'end-to-end: delivered not clearable' );
	// #30314's real shape MUST be clearable.
	es_ok( ES_TCG_Locker_Booking::is_dead_status(
		ES_TCG_Locker_Booking::raw_from_courier_meta( 'tcg-locker:cancel-booking-expired' ) ), 'the real dead shape is clearable' );
} );

es_test( 'arrival_claim_key: stable, shared between poll (set) and clear (delete)', function () {
	es_eq( 'es_locker_arr_30314', ES_TCG_Locker_Booking::arrival_claim_key( 30314 ), 'expected key format' );
	es_eq( 'es_locker_arr_30314', ES_TCG_Locker_Booking::arrival_claim_key( '30314' ), 'coerces to int' );
} );
