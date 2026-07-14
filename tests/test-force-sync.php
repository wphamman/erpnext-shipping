<?php
/**
 * Force-sync outcome decision — the pure logic behind the "confirm the Sales
 * Order didn't actually land before recording a failure" fix (OQ-16 secondary).
 *
 * Only ES_Order_Actions::decide_force_sync_outcome() is exercised here; it is
 * pure (i18n + sprintf), so no WP/ERP mocking is needed. The surrounding
 * run_force_sync_job() wiring (client GET, order note, meta) is WP-backed and
 * out of scope for this repo-local harness.
 */

// Minimal i18n stub — the loaded pure classes don't use __(), so defining it
// here is harmless and lets us require the WP-backed class for its pure method.
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) { return $text; }
}

require_once dirname( __DIR__ ) . '/includes/class-es-order-actions.php';

es_test( 'force-sync: clean success reports ok', function () {
	$out = ES_Order_Actions::decide_force_sync_outcome( false, false, true, 'WEB1-030370', '' );
	es_eq( true, $out['ok'], 'ok is true on a non-error result' );
	es_contains( $out['message'], 'completed for WEB1-030370', 'names the SO in the success note' );
	es_not_contains( $out['message'], 'confirmed present', 'plain success is not the confirmation variant' );
} );

es_test( 'force-sync: errored request but SO present → indeterminate "verify" (the fix)', function () {
	$out = ES_Order_Actions::decide_force_sync_outcome( true, false, true, 'WEB1-030370', 'ERPNext HTTP 404: DoesNotExistError' );
	// Existence does NOT prove this update landed, so it is indeterminate, not success.
	es_eq( null, $out['ok'], 'a failed POST with an existing SO is indeterminate (null), not a claimed success' );
	es_contains( $out['message'], 'verify', 'asks the operator to verify the SO is up to date' );
	es_contains( $out['message'], 'matching Sales Order exists', 'states the SO is present' );
	es_not_contains( $out['message'], 'failed', 'not phrased as an outright failure' );
} );

es_test( 'force-sync: timeout, SO not (yet) present → sent/indeterminate', function () {
	$out = ES_Order_Actions::decide_force_sync_outcome( true, true, false, 'WEB1-030370', 'Operation timed out after 25000 ms' );
	es_eq( null, $out['ok'], 'timeout without a confirmed SO stays indeterminate (null)' );
	es_contains( $out['message'], 'timed out', 'keeps the "sent — verify" wording' );
} );

es_test( 'force-sync: hard error, SO absent → failure note kept', function () {
	$out = ES_Order_Actions::decide_force_sync_outcome( true, false, false, 'WEB1-030370', 'ERPNext HTTP 500: ServerError' );
	es_eq( false, $out['ok'], 'a genuine error with no SO is a real failure' );
	es_contains( $out['message'], 'sync failed for WEB1-030370', 'names the SO in the failure note' );
	es_contains( $out['message'], 'ServerError', 'surfaces the underlying error' );
} );
