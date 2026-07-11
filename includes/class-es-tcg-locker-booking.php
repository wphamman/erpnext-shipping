<?php
/**
 * TCG Locker booking decisions — pure, testable logic for the manual, idempotent
 * "Book TCG Locker Shipment" action.
 *
 * Kept free of WordPress/WooCommerce runtime dependencies so the guard rails
 * around a money-spending, non-idempotent provider call can be unit-tested:
 *   - the pre-flight guard (duplicate-book + ambiguous-lock),
 *   - fresh-rate drift detection against the persisted checkout quote,
 *   - and — most importantly — the classification of a create_shipment() result
 *     into booked / failed / AMBIGUOUS, where ambiguous means "the provider may
 *     already hold a shipment; never auto-retry".
 *
 * The WP-facing glue (nonce, capability, mutex, order I/O, label proxy) lives in
 * ES_TCG_Locker_Admin and calls into here.
 *
 * @package ERPNext_Shipping
 */

defined( 'ABSPATH' ) || exit;

class ES_TCG_Locker_Booking {

	// Order-level meta keys recording the BOOKING OUTCOME. Distinct from the
	// shipping-item quote snapshot keys on ES_TCG_Locker_Rate (M_SERVICE_CODE …),
	// which are written at checkout and read back here to book from.
	const M_SHIPMENT_ID   = '_es_tcg_locker_shipment_id';
	const M_TRACKING_REF  = '_es_tcg_locker_tracking_ref';
	const M_BOOKING_STATE = '_es_tcg_locker_booking_state';
	const M_BOOKED_AT     = '_es_tcg_locker_booked_at';
	const M_LAST_ERROR    = '_es_tcg_locker_last_error';

	// Booking states.
	const STATE_NONE      = '';
	const STATE_BOOKED    = 'booked';
	const STATE_FAILED    = 'failed';    // Definite failure — safe to correct and re-book.
	const STATE_AMBIGUOUS = 'ambiguous'; // Provider may hold a shipment — NEVER auto-retry.

	/** Provider (VAT-inclusive) rate drift tolerance, in ZAR. */
	const PRICE_EPSILON = 0.01;

	/**
	 * Pre-flight guard: may this order be booked right now?
	 *
	 * A stored shipment id is the durable duplicate-book backstop (survives even
	 * a lost mutex). An ambiguous prior attempt hard-blocks any further automatic
	 * booking — a human must reconcile against the TCG portal first.
	 *
	 * @param string $existing_shipment_id Persisted shipment id ('' if none).
	 * @param string $existing_state       Persisted booking state.
	 * @return array{can:bool, reason:string}
	 */
	public static function guard( $existing_shipment_id, $existing_state ) {
		if ( '' !== (string) $existing_shipment_id ) {
			return array( 'can' => false, 'reason' => 'already_booked' );
		}
		if ( self::STATE_AMBIGUOUS === (string) $existing_state ) {
			return array( 'can' => false, 'reason' => 'ambiguous_locked' );
		}
		return array( 'can' => true, 'reason' => '' );
	}

	/**
	 * Classify a ES_TCG_Locker_Client::create_shipment() result into a durable
	 * booking state. This is the heart of the idempotency contract:
	 *
	 *   - ok + shipment id            → BOOKED.
	 *   - ok but no id                → AMBIGUOUS (never a silent success).
	 *   - definite client rejection   → FAILED (missing args, HTTP 4xx) — retry OK.
	 *   - transport / timeout / 5xx / → AMBIGUOUS — the request may have reached the
	 *     unparseable 2xx (shape/decode)  provider and created a shipment, so the
	 *                                     action must NOT auto-retry; a human checks
	 *                                     the portal and reconciles.
	 *   - anything unrecognised       → AMBIGUOUS (fail safe toward no auto-retry).
	 *
	 * @param array $result create_shipment() return.
	 * @return array{state:string, shipment_id:string, tracking_ref:string, error:string}
	 */
	public static function classify_result( $result ) {
		$result = is_array( $result ) ? $result : array();

		if ( ! empty( $result['ok'] ) ) {
			$sid = (string) ( $result['shipment_id'] ?? '' );
			if ( '' === $sid ) {
				return self::state( self::STATE_AMBIGUOUS, '', '', 'no_shipment_id' );
			}
			return self::state( self::STATE_BOOKED, $sid, (string) ( $result['tracking_ref'] ?? '' ), '' );
		}

		$err  = (string) ( $result['error'] ?? 'unknown' );
		$code = (int) ( $result['code'] ?? 0 );

		// Definite client-side failures — the request was rejected before any
		// shipment could exist, so a corrected retry is safe.
		if ( 'missing_args' === $err ) {
			return self::state( self::STATE_FAILED, '', '', $err );
		}
		if ( 'http' === $err && $code >= 400 && $code < 500 ) {
			return self::state( self::STATE_FAILED, '', '', 'http_' . $code );
		}

		// Everything else is ambiguous: transport error, timeout, HTTP 5xx, or a
		// 2xx we could not parse (shape/decode). A shipment may already exist.
		$reason = ( 'http' === $err && $code ) ? 'http_' . $code : $err;
		return self::state( self::STATE_AMBIGUOUS, '', '', $reason );
	}

	/**
	 * Has the live quote drifted from the quote persisted at checkout? Refuses the
	 * booking (drift=true) whenever we cannot re-confirm the exact same service,
	 * box, revision and price the customer was charged.
	 *
	 * @param array $persisted    Shipping-item meta snapshot (ES_TCG_Locker_Rate::M_* keys).
	 * @param array $fresh_offers Offers from a FORCE-REFRESHED get_rates() (cache bypassed).
	 * @return array{drift:bool, reason:string}
	 */
	public static function detect_drift( $persisted, $fresh_offers ) {
		$persisted = is_array( $persisted ) ? $persisted : array();

		$svc = (string) ( $persisted[ ES_TCG_Locker_Rate::M_SERVICE_CODE ] ?? '' );
		if ( '' === $svc ) {
			return array( 'drift' => true, 'reason' => 'no_persisted_service' );
		}
		if ( ! is_array( $fresh_offers ) || empty( $fresh_offers ) ) {
			// No confirmable live quote → cannot certify the price → refuse.
			return array( 'drift' => true, 'reason' => 'no_fresh_quote' );
		}

		$match = null;
		foreach ( $fresh_offers as $o ) {
			if ( is_array( $o ) && (string) ( $o['service_code'] ?? '' ) === $svc ) {
				$match = $o;
				break;
			}
		}
		if ( null === $match ) {
			return array( 'drift' => true, 'reason' => 'service_unavailable' );
		}

		// The packer's chosen box must still be the one offered for that service.
		$p_box = (string) ( $persisted[ ES_TCG_Locker_Rate::M_BOX_CODE ] ?? '' );
		$f_box = (string) ( $match['box_code'] ?? '' );
		if ( '' !== $p_box && $p_box !== $f_box ) {
			return array( 'drift' => true, 'reason' => 'box_changed' );
		}

		// Revision id, when known on both sides, must match.
		$p_rev = (string) ( $persisted[ ES_TCG_Locker_Rate::M_REVISION_ID ] ?? '' );
		$f_rev = (string) ( $match['rate_revision_id'] ?? '' );
		if ( '' !== $p_rev && '' !== $f_rev && $p_rev !== $f_rev ) {
			return array( 'drift' => true, 'reason' => 'revision_changed' );
		}

		// Provider VAT-inclusive rate must match within a cent.
		$p_rate = $persisted[ ES_TCG_Locker_Rate::M_PROVIDER_RATE ] ?? null;
		$f_rate = $match['rate'] ?? null;
		if ( is_numeric( $p_rate ) && is_numeric( $f_rate ) && abs( (float) $p_rate - (float) $f_rate ) > self::PRICE_EPSILON ) {
			return array( 'drift' => true, 'reason' => 'price_changed' );
		}

		return array( 'drift' => false, 'reason' => '' );
	}

	private static function state( $state, $shipment_id, $tracking_ref, $error ) {
		return array(
			'state'        => $state,
			'shipment_id'  => $shipment_id,
			'tracking_ref' => $tracking_ref,
			'error'        => $error,
		);
	}
}
