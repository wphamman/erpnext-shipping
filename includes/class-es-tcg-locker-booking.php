<?php
/**
 * TCG Locker booking decisions — pure, testable logic for the manual, idempotent
 * "Book TCG Locker Shipment" action.
 *
 * Kept free of WordPress/WooCommerce runtime dependencies so the guard rails
 * around a money-spending, non-idempotent provider call can be unit-tested:
 *   - the pre-flight guard (duplicate-book + in-progress + ambiguous lock),
 *   - fresh-rate drift detection against the persisted checkout quote
 *     (fail-CLOSED: a snapshot missing box/price cannot certify, so it drifts),
 *   - and — most importantly — the classification of a create_shipment() result
 *     into booked / error / AMBIGUOUS, where ambiguous means "the provider may
 *     already hold a shipment; never auto-retry". Ambiguity-sensitive 4xx
 *     (408/409/429) are ambiguous, not retryable errors.
 *
 * Meta key + state-value names follow the locked architecture contract
 * (docs/tcg-locker-architecture.md §5.x): `_es_tcg_locker_booking_status` with
 * values none|booking|booked|ambiguous|error, and `_es_tcg_locker_booked_ts`.
 *
 * The WP-facing glue (nonce, capability, mutex, order I/O, validation, tracking
 * item, label proxy) lives in ES_TCG_Locker_Admin and calls into here.
 *
 * @package ERPNext_Shipping
 */

defined( 'ABSPATH' ) || exit;

class ES_TCG_Locker_Booking {

	// Order-level meta keys recording the BOOKING OUTCOME. Distinct from the
	// shipping-item quote snapshot keys on ES_TCG_Locker_Rate (M_SERVICE_CODE …),
	// which are written at checkout and read back here to book from. Names are
	// fixed by the contract so the Phase-5 poll-selection query can match them.
	const M_SHIPMENT_ID    = '_es_tcg_locker_shipment_id';
	const M_TRACKING_REF   = '_es_tcg_locker_tracking_ref';
	const M_BOOKING_STATUS = '_es_tcg_locker_booking_status';
	const M_BOOKED_TS      = '_es_tcg_locker_booked_ts';
	const M_LAST_ERROR     = '_es_tcg_locker_last_error';

	// Booking states (contract values).
	const STATE_NONE      = 'none';
	const STATE_BOOKING   = 'booking';   // Durable in-progress — set BEFORE the call.
	const STATE_BOOKED    = 'booked';
	const STATE_AMBIGUOUS = 'ambiguous'; // Provider may hold a shipment — NEVER auto-retry.
	const STATE_ERROR     = 'error';     // Definite failure — safe to correct and re-book.

	/** Provider (VAT-inclusive) rate drift tolerance, in ZAR. */
	const PRICE_EPSILON = 0.01;

	// Booking-path mutex lease bound (consumed by ES_TCG_Locker_Admin's mutex).
	const LOCK_PATH_CALLS = 3;   // get_locker + fresh get_rates + create_shipment.
	const LOCK_MARGIN     = 120; // Order saves, notes, tracking I/O + safety.
	const LOCK_TTL_FLOOR  = 180;

	/**
	 * Enforced upper bound (seconds) on how long a live booking request can hold the
	 * per-order mutex: every provider call in the booking path at the CONFIGURED
	 * per-call timeout, plus margin, floored. A fixed threshold is unsafe because the
	 * API timeout is merchant-configurable and uncapped — with a high timeout a
	 * legitimate request could still be running, and a fixed 120s would let the
	 * operator clear delete its live lock. Deriving from the timeout makes the
	 * threshold rise in lock-step, so a live request is never mistaken for a dead one.
	 * Pure + static so this correctness-critical bound is unit-tested.
	 *
	 * @param int $timeout Configured per-call API timeout (seconds); <=0 → default.
	 * @return int
	 */
	public static function lock_stale_threshold( $timeout ) {
		$timeout = (int) $timeout;
		if ( $timeout <= 0 ) {
			$timeout = class_exists( 'ES_TCG_Locker_Client' ) ? ES_TCG_Locker_Client::DEFAULT_TIMEOUT : 15;
		}
		return max( self::LOCK_TTL_FLOOR, self::LOCK_PATH_CALLS * $timeout + self::LOCK_MARGIN );
	}

	/** 4xx statuses where the request may still have been accepted/processed. */
	private static $ambiguous_http = array( 408, 409, 429 );

	/**
	 * Pre-flight guard: may this order be booked right now?
	 *
	 * A stored shipment id is the durable duplicate-book backstop. An in-progress
	 * ('booking') state left behind by a crash mid-call, or an ambiguous prior
	 * attempt, both hard-block any further automatic booking — a human reconciles
	 * against the TCG portal and clears the state first.
	 *
	 * An empty status (never booked) is treated the same as STATE_NONE.
	 *
	 * @param string $existing_shipment_id Persisted shipment id ('' if none).
	 * @param string $existing_status      Persisted booking status.
	 * @return array{can:bool, reason:string}
	 */
	public static function guard( $existing_shipment_id, $existing_status ) {
		if ( '' !== (string) $existing_shipment_id ) {
			return array( 'can' => false, 'reason' => 'already_booked' );
		}
		if ( self::STATE_BOOKING === (string) $existing_status ) {
			return array( 'can' => false, 'reason' => 'in_progress_locked' );
		}
		if ( self::STATE_AMBIGUOUS === (string) $existing_status ) {
			return array( 'can' => false, 'reason' => 'ambiguous_locked' );
		}
		return array( 'can' => true, 'reason' => '' );
	}

	/** A status that hard-blocks re-booking until a human clears it. */
	public static function is_blocking_status( $status ) {
		return in_array( (string) $status, array( self::STATE_BOOKING, self::STATE_AMBIGUOUS ), true );
	}

	/**
	 * Terminal PROVIDER states: the shipment is dead and will never move again.
	 *
	 * Distinct from our own booking state — the booking succeeded (we hold a real
	 * shipment id), but TCG has since cancelled or expired it. Either way nothing
	 * will ever be collected against that waybill, so the order must be re-bookable.
	 *
	 * Note `cancel-booking-expired` reads like an accident but is also what a
	 * REQUESTED cancellation produces — the two are indistinguishable from the
	 * status alone, and both leave the same dead booking to clean up.
	 */
	/**
	 * Option key for the permanent one-shot arrival-notification claim (set by the
	 * poll, cleared on dead-booking re-book). Defined here so the writer (cron) and
	 * the clearer (admin) cannot drift apart.
	 */
	public static function arrival_claim_key( $order_id ) {
		return 'es_locker_arr_' . (int) $order_id;
	}

	public static function is_dead_status( $raw ) {
		return in_array(
			strtolower( trim( (string) $raw ) ),
			array( 'cancelled', 'cancel-booking-expired' ),
			true
		);
	}

	/**
	 * Extract this provider's raw status from the combined `_es_courier_status`
	 * meta, which carries one or more comma-separated `provider:status` pairs.
	 * Returns '' when TCG Locker has not been polled for this order.
	 */
	public static function raw_from_courier_meta( $meta ) {
		foreach ( explode( ',', (string) $meta ) as $part ) {
			$part = trim( $part );
			if ( 0 === strpos( $part, 'tcg-locker:' ) ) {
				return strtolower( trim( substr( $part, strlen( 'tcg-locker:' ) ) ) );
			}
		}
		return '';
	}

	/**
	 * Classify a ES_TCG_Locker_Client::create_shipment() result into a durable
	 * booking state. This is the heart of the idempotency contract:
	 *
	 *   - ok + shipment id            → BOOKED.
	 *   - ok but no id                → AMBIGUOUS (never a silent success).
	 *   - HTTP 408/409/429            → AMBIGUOUS (request may have been processed).
	 *   - other definite rejection    → ERROR (missing args, other HTTP 4xx) — retry OK.
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

		if ( 'http' === $err && $code >= 400 && $code < 500 ) {
			// Ambiguity-sensitive 4xx: 408 Request Timeout / 409 Conflict (a
			// duplicate may already exist) / 429 Too Many Requests (the request may
			// have been processed before the limiter tripped). These are NOT safe to
			// auto-retry. Every other 4xx is a definite client-side rejection.
			if ( in_array( $code, self::$ambiguous_http, true ) ) {
				return self::state( self::STATE_AMBIGUOUS, '', '', 'http_' . $code );
			}
			return self::state( self::STATE_ERROR, '', '', 'http_' . $code );
		}

		// Definite client-side failure — rejected before any shipment could exist.
		if ( 'missing_args' === $err ) {
			return self::state( self::STATE_ERROR, '', '', $err );
		}

		// Everything else is ambiguous: transport error, timeout, HTTP 5xx, or a
		// 2xx we could not parse (shape/decode). A shipment may already exist.
		$reason = ( 'http' === $err && $code ) ? 'http_' . $code : $err;
		return self::state( self::STATE_AMBIGUOUS, '', '', $reason );
	}

	/**
	 * Has the live quote drifted from the quote persisted at checkout? Fail-CLOSED:
	 * returns drift=true whenever we cannot re-confirm the EXACT same service, box
	 * and price the customer was charged — including when the persisted snapshot is
	 * incomplete or the fresh offer lacks a comparable box/price.
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
		// The snapshot MUST carry the box and a numeric price to certify against —
		// otherwise the comparison would silently pass (fail open). Refuse.
		$p_box  = (string) ( $persisted[ ES_TCG_Locker_Rate::M_BOX_CODE ] ?? '' );
		$p_rate = $persisted[ ES_TCG_Locker_Rate::M_PROVIDER_RATE ] ?? null;
		if ( '' === $p_box || ! is_numeric( $p_rate ) ) {
			return array( 'drift' => true, 'reason' => 'incomplete_snapshot' );
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
		$f_box = (string) ( $match['box_code'] ?? '' );
		if ( '' === $f_box || $f_box !== $p_box ) {
			return array( 'drift' => true, 'reason' => 'box_changed' );
		}

		// The fresh offer must carry a comparable numeric price.
		$f_rate = $match['rate'] ?? null;
		if ( ! is_numeric( $f_rate ) ) {
			return array( 'drift' => true, 'reason' => 'no_fresh_price' );
		}

		// Revision id must match EXACTLY (contract: exact service/box/revision/price).
		// A missing persisted or fresh revision is a mismatch unless BOTH are absent
		// (a provider that never issues revision ids). Comparing only when both are
		// non-empty would fail open — e.g. no persisted revision vs a fresh 'rev_l_2'
		// would slip through — so compare the raw strings directly.
		$p_rev = (string) ( $persisted[ ES_TCG_Locker_Rate::M_REVISION_ID ] ?? '' );
		$f_rev = (string) ( $match['rate_revision_id'] ?? '' );
		if ( $p_rev !== $f_rev ) {
			return array( 'drift' => true, 'reason' => 'revision_changed' );
		}

		// Provider VAT-inclusive rate must match within a cent.
		if ( abs( (float) $p_rate - (float) $f_rate ) > self::PRICE_EPSILON ) {
			return array( 'drift' => true, 'reason' => 'price_changed' );
		}

		return array( 'drift' => false, 'reason' => '' );
	}

	/**
	 * Plan a staff RE-QUOTE: can the persisted checkout snapshot be refreshed to the
	 * live offer for the SAME service and box, and what changed? Used by the admin
	 * "Re-quote" action after a drift refusal, so a locker order whose provider price
	 * moved between checkout and booking can be re-priced to the current quote and then
	 * booked through the plugin (keeping polling + the arrival email) instead of being
	 * booked by hand off-platform.
	 *
	 * Fail-CLOSED on every axis that would make the refresh unsafe: it reuses the exact
	 * structural refusals of detect_drift() so a re-quote can never land the order on a
	 * service that is gone, a box that changed, or a non-numeric price. A changed BOX is
	 * deliberately refused (not silently re-boxed) — the fit was certified at checkout
	 * against the persisted box, so a provider box change needs manual handling.
	 *
	 * PURE: computes only. The caller decides whether to persist (and never touches the
	 * customer charge — only the provider rate/revision, so the drift guard will pass and
	 * any increase is absorbed).
	 *
	 * @param array $persisted    Shipping-item snapshot (ES_TCG_Locker_Rate::M_* keys).
	 * @param array $fresh_offers Offers from a FORCE-REFRESHED get_rates().
	 * @return array{ok:bool, reason:string, old_rate:?float, new_rate:?float,
	 *               old_rev:string, new_rev:string, new_rate_ex:?float, changed:bool}
	 */
	public static function plan_requote( $persisted, $fresh_offers ) {
		$fail = function ( $reason ) {
			return array(
				'ok'          => false,
				'reason'      => $reason,
				'old_rate'    => null,
				'new_rate'    => null,
				'old_rev'     => '',
				'new_rev'     => '',
				'new_rate_ex' => null,
				'changed'     => false,
			);
		};
		$persisted = is_array( $persisted ) ? $persisted : array();

		$svc = (string) ( $persisted[ ES_TCG_Locker_Rate::M_SERVICE_CODE ] ?? '' );
		if ( '' === $svc ) {
			return $fail( 'no_persisted_service' );
		}
		$p_box  = (string) ( $persisted[ ES_TCG_Locker_Rate::M_BOX_CODE ] ?? '' );
		$p_rate = $persisted[ ES_TCG_Locker_Rate::M_PROVIDER_RATE ] ?? null;
		if ( '' === $p_box || ! is_numeric( $p_rate ) ) {
			return $fail( 'incomplete_snapshot' );
		}
		if ( ! is_array( $fresh_offers ) || empty( $fresh_offers ) ) {
			return $fail( 'no_fresh_quote' );
		}

		$match = null;
		foreach ( $fresh_offers as $o ) {
			if ( is_array( $o ) && (string) ( $o['service_code'] ?? '' ) === $svc ) {
				$match = $o;
				break;
			}
		}
		if ( null === $match ) {
			return $fail( 'service_unavailable' );
		}
		$f_box = (string) ( $match['box_code'] ?? '' );
		if ( '' === $f_box || $f_box !== $p_box ) {
			return $fail( 'box_changed' );
		}
		$f_rate = $match['rate'] ?? null;
		if ( ! is_numeric( $f_rate ) ) {
			return $fail( 'no_fresh_price' );
		}

		$p_rev = (string) ( $persisted[ ES_TCG_Locker_Rate::M_REVISION_ID ] ?? '' );
		$f_rev = (string) ( $match['rate_revision_id'] ?? '' );
		$f_ex  = $match['rate_excluding_vat'] ?? null;

		$changed = ( $p_rev !== $f_rev )
			|| ( abs( (float) $p_rate - (float) $f_rate ) > self::PRICE_EPSILON );

		return array(
			'ok'          => true,
			'reason'      => '',
			'old_rate'    => (float) $p_rate,
			'new_rate'    => (float) $f_rate,
			'old_rev'     => $p_rev,
			'new_rev'     => $f_rev,
			'new_rate_ex' => is_numeric( $f_ex ) ? (float) $f_ex : null,
			'changed'     => $changed,
		);
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
