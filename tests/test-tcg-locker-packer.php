<?php
/**
 * Tests for ES_TCG_Locker_Packer (Phase 2).
 *
 * Covers every required case: kits of increasing weight selecting S/M/L,
 * 10–11kg, fractional loose malt, 20kg exact, >20kg rejection, one dimension
 * too long, cumulative-volume overflow, missing weight/dimensions, rotated fit,
 * multiple cart lines, an explicit locker-ineligible product, and selection
 * from the RETURNED services only (M absent → L; only XS+XL → smallest fitting).
 */

defined( 'ABSPATH' ) || exit;

/** Build a cart line. */
function es_line( $qty, $weight, $l, $w, $h, $extra = array() ) {
	return array_merge(
		array( 'qty' => $qty, 'weight' => $weight, 'length' => $l, 'width' => $w, 'height' => $h ),
		$extra
	);
}

/** Build /rates-style offers from static size codes (simulates the returned services). */
function es_offers_from_sizes( array $sizes ) {
	$offers = array();
	foreach ( $sizes as $s ) {
		$box = ES_TCG_Locker_Packer::STATIC_BOXES[ $s ];
		$offers[] = array(
			'service_code' => 'L2L' . $s . ' - ECO',
			'box_code'     => $s,
			'box_size'     => $s,
			'dimensions'   => $box,
		);
	}
	return $offers;
}

/** Convenience: chosen size code, or the failure reason. */
function es_pick( $lines, array $sizes ) {
	$req = ES_TCG_Locker_Packer::compute_requirements( $lines );
	$res = ES_TCG_Locker_Packer::select_smallest( $req, es_offers_from_sizes( $sizes ) );
	return empty( $res['ok'] ) ? array( 'reason' => $res['reason'] ) : array( 'size' => $res['offer']['box_size'] );
}

$ALL = array( 'XS', 'S', 'M', 'L', 'XL' );

es_test( '4.5 kg kit → S (weight rules out XS)', function () use ( $ALL ) {
	$r = es_pick( array( es_line( 1, 4.5, 30, 20, 6 ) ), $ALL );
	es_eq( 'S', $r['size'] ?? null, 'selects S' );
} );

es_test( '5.8 kg kit → M (over S weight limit)', function () use ( $ALL ) {
	$r = es_pick( array( es_line( 1, 5.8, 30, 20, 6 ) ), $ALL );
	es_eq( 'M', $r['size'] ?? null, 'selects M' );
} );

es_test( '6.5 kg tall kit → L (dimension-driven bump past M)', function () use ( $ALL ) {
	// Smallest dim 20 > M height 19, so it cannot fit M regardless of weight.
	$r = es_pick( array( es_line( 1, 6.5, 30, 20, 25 ) ), $ALL );
	es_eq( 'L', $r['size'] ?? null, 'selects L' );
} );

es_test( '10–11 kg kit → L (over M weight limit)', function () use ( $ALL ) {
	$r = es_pick( array( es_line( 1, 10.5, 30, 20, 10 ) ), $ALL );
	es_eq( 'L', $r['size'] ?? null, 'selects L' );
} );

es_test( 'fractional loose malt quantity is not undercounted', function () use ( $ALL ) {
	$lines = array( es_line( 2.5, 1.01, 20, 15, 10 ) );
	$req   = ES_TCG_Locker_Packer::compute_requirements( $lines );
	es_ok( ! empty( $req['ok'] ), 'requirements computed' );
	es_close( 2.525, $req['total_weight'], 1e-6, 'weight = 2.5 × 1.01, not truncated to 2' );
	es_close( 2.5, $req['unit_count'], 1e-6, 'unit_count preserves the fraction' );
	$r = es_pick( $lines, $ALL );
	es_eq( 'M', $r['size'] ?? null, 'selects M (item too tall for S)' );
} );

es_test( '20 kg exact limit → XL (boundary inclusive)', function () use ( $ALL ) {
	$r = es_pick( array( es_line( 1, 20, 40, 40, 65 ) ), $ALL );
	es_eq( 'XL', $r['size'] ?? null, 'exactly 20kg still fits XL' );
} );

es_test( 'over 20 kg → no_box_fits', function () use ( $ALL ) {
	$r = es_pick( array( es_line( 1, 20.5, 40, 40, 65 ) ), $ALL );
	es_eq( 'no_box_fits', $r['reason'] ?? null, 'nothing fits above the max box weight' );
} );

es_test( 'one dimension too long → no_box_fits', function () use ( $ALL ) {
	// 70cm exceeds the longest box dimension (XL = 69).
	$r = es_pick( array( es_line( 1, 1, 70, 10, 10 ) ), $ALL );
	es_eq( 'no_box_fits', $r['reason'] ?? null, 'over-long item fits no box' );
} );

es_test( 'cumulative volume overflow → no_box_fits (each item fits, sum does not)', function () use ( $ALL ) {
	// 300 tiny light cubes: each fits any box, total volume 300,000 cm³ exceeds
	// even XL usable volume (169,740 × 0.8), weight only 3kg.
	$r = es_pick( array( es_line( 300, 0.01, 10, 10, 10 ) ), $ALL );
	es_eq( 'no_box_fits', $r['reason'] ?? null, 'volume overflow rejected despite low weight' );
} );

es_test( 'missing weight → structured reason, never optimistic', function () use ( $ALL ) {
	$r = es_pick( array( es_line( 1, 0, 10, 10, 10 ) ), $ALL );
	es_eq( 'missing_weight', $r['reason'] ?? null, 'zero/absent weight rejected' );
} );

es_test( 'missing dimensions → structured reason', function () use ( $ALL ) {
	$r = es_pick( array( es_line( 1, 1, 10, 0, 10 ) ), $ALL );
	es_eq( 'missing_dimensions', $r['reason'] ?? null, 'zero dimension rejected' );
} );

es_test( 'rotated fit — item fits only when dimensions are rotated', function () {
	// Item 69×41×60: naive orientation exceeds XL length 60, but sorted it is
	// exactly [41,60,69] == XL sorted, so rotation makes it fit.
	$req  = ES_TCG_Locker_Packer::compute_requirements( array( es_line( 1, 5, 69, 41, 60 ) ) );
	$xl   = ES_TCG_Locker_Packer::STATIC_BOXES['XL'];
	$l    = ES_TCG_Locker_Packer::STATIC_BOXES['L'];
	es_ok( ES_TCG_Locker_Packer::fits_box( $req, $xl ), 'fits XL by rotation' );
	es_ok( ! ES_TCG_Locker_Packer::fits_box( $req, $l ), 'does not fit L' );
	$r = es_pick( array( es_line( 1, 5, 69, 41, 60 ) ), array( 'XS', 'S', 'M', 'L', 'XL' ) );
	es_eq( 'XL', $r['size'] ?? null, 'selects XL' );
} );

es_test( 'multiple cart lines combine weight, volume and max dimensions', function () use ( $ALL ) {
	$lines = array(
		es_line( 1, 3, 30, 20, 10 ),
		es_line( 2, 1, 15, 15, 15 ),
	);
	$req = ES_TCG_Locker_Packer::compute_requirements( $lines );
	es_close( 5.0, $req['total_weight'], 1e-9, 'combined weight = 3 + 2×1' );
	es_eq( array( 15.0, 20.0, 30.0 ), $req['max_dims'], 'componentwise max of sorted per-line dims' );
	$r = es_pick( $lines, $ALL );
	es_eq( 'M', $r['size'] ?? null, 'selects M for the combined order' );
} );

es_test( 'explicit locker-ineligible product → product_excluded', function () use ( $ALL ) {
	$r = es_pick( array( es_line( 1, 1, 10, 10, 10, array( 'locker_eligible' => false ) ) ), $ALL );
	es_eq( 'product_excluded', $r['reason'] ?? null, 'opt-out product blocks locker eligibility' );
} );

es_test( 'selection uses RETURNED services: M absent but L fits → L', function () {
	// Order would fit M if offered, but M is not returned.
	$lines = array( es_line( 1, 7, 30, 20, 15 ) );
	es_eq( 'M', es_pick( $lines, array( 'XS', 'S', 'M', 'L', 'XL' ) )['size'] ?? null, 'M chosen when present' );
	es_eq( 'L', es_pick( $lines, array( 'XS', 'S', 'L', 'XL' ) )['size'] ?? null, 'next larger returned box (L) chosen when M absent' );
} );

es_test( 'selection uses RETURNED services: only XS and XL returned → smallest fitting (XL)', function () {
	$lines = array( es_line( 1, 6, 30, 20, 15 ) );
	// XS weight limit (2kg) rules it out; XL is the only fitting returned service.
	es_eq( 'XL', es_pick( $lines, array( 'XS', 'XL' ) )['size'] ?? null, 'smallest FITTING returned box selected' );
} );

es_test( 'fits_box fails closed on missing box weight/dimension data', function () {
	$req = ES_TCG_Locker_Packer::compute_requirements( array( es_line( 1, 1, 10, 10, 10 ) ) );
	es_ok( ! ES_TCG_Locker_Packer::fits_box( $req, array( 'length' => 60, 'width' => 41, 'height' => 69 ) ), 'no max_weight → not optimistic' );
	es_ok( ! ES_TCG_Locker_Packer::fits_box( $req, array( 'length' => 60, 'width' => null, 'height' => 69, 'max_weight' => 20 ) ), 'null dimension → rejected' );
} );

es_test( 'select_smallest with no services → no_services; empty cart → no_items', function () use ( $ALL ) {
	$req = ES_TCG_Locker_Packer::compute_requirements( array( es_line( 1, 1, 10, 10, 10 ) ) );
	$res = ES_TCG_Locker_Packer::select_smallest( $req, array() );
	es_eq( 'no_services', $res['reason'] ?? null, 'no returned services' );

	$empty = ES_TCG_Locker_Packer::compute_requirements( array() );
	es_eq( 'no_items', $empty['reason'] ?? null, 'empty cart' );
} );

es_test( 'spatial coexistence — two 40³ cubes do NOT fit XL (aggregate-volume trap)', function () {
	// Each cube fits XL individually and their combined volume (128,000 cm³) is
	// below XL usable volume (135,792 cm³), but no axis can hold both: any pairing
	// needs 80cm on some axis, exceeding every box dimension. Must be rejected.
	$req = ES_TCG_Locker_Packer::compute_requirements( array( es_line( 2, 5, 40, 40, 40 ) ) );
	$xl  = ES_TCG_Locker_Packer::STATIC_BOXES['XL'];
	es_ok( ! ES_TCG_Locker_Packer::fits_box( $req, $xl ), 'two 40³ cubes cannot coexist in XL' );
	$r = es_pick( array( es_line( 2, 5, 40, 40, 40 ) ), array( 'XS', 'S', 'M', 'L', 'XL' ) );
	es_eq( 'no_box_fits', $r['reason'] ?? null, 'order offered no locker' );
} );

es_test( 'spatial coexistence — two 20³ cubes DO fit (packable control, not over-rejected)', function () {
	// Two 20cm cubes sit side by side along the 60cm axis of M; must be accepted.
	$req = ES_TCG_Locker_Packer::compute_requirements( array( es_line( 2, 1, 20, 20, 15 ) ) );
	$m   = ES_TCG_Locker_Packer::STATIC_BOXES['M'];
	es_ok( ES_TCG_Locker_Packer::fits_box( $req, $m ), 'two 20×20×15 items coexist in M' );
} );

es_test( 'configurable fill factor — 6× 20×20×15 fits M at 0.80 but not at 0.70', function () {
	// Cumulative volume 36,000 cm³ sits between 0.70×M (32,718) and 0.80×M (37,392),
	// and the 6 units pack 3×2×1 in M (60×41×19). So the multi-item order IS offered a
	// locker at the default 0.80 fill factor but correctly withheld at a tighter 0.70.
	$req = ES_TCG_Locker_Packer::compute_requirements( array( es_line( 6, 1, 20, 20, 15 ) ) );
	$m   = ES_TCG_Locker_Packer::STATIC_BOXES['M'];
	es_ok( ES_TCG_Locker_Packer::fits_box( $req, $m, 0.80 ), 'fits at 0.80 fill factor' );
	es_ok( ! ES_TCG_Locker_Packer::fits_box( $req, $m, 0.70 ), 'withheld at tighter 0.70 fill factor' );
	// Sanitiser clamps operator input to (0,1], else falls back to 0.70.
	es_eq( 0.65, ES_TCG_Locker_Packer::sane_fill_factor( '0.65' ), 'valid string passes through' );
	es_eq( 0.70, ES_TCG_Locker_Packer::sane_fill_factor( 'abc' ), 'non-numeric → default 0.70' );
	es_eq( 0.70, ES_TCG_Locker_Packer::sane_fill_factor( 1.5 ), 'out-of-range → default 0.70' );
	es_eq( 0.70, ES_TCG_Locker_Packer::sane_fill_factor( 0 ), 'zero → default 0.70' );
} );

es_test( 'over the placement unit cap → conservative reject', function () {
	// 200 tiny units exceed MAX_PLACEMENT_UNITS; a fit cannot be demonstrated cheaply.
	$req = ES_TCG_Locker_Packer::compute_requirements( array( es_line( 200, 0.001, 1, 1, 1 ) ) );
	es_ok( ! empty( $req['over_unit_cap'] ), 'over_unit_cap flagged' );
	es_ok( ! ES_TCG_Locker_Packer::fits_box( $req, ES_TCG_Locker_Packer::STATIC_BOXES['XL'] ), 'rejected rather than optimistically accepted' );
} );
