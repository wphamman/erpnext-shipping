<?php
/**
 * TCG Locker tracking status map — pure, testable §12 vocabulary.
 *
 * Maps a raw TCG Locker tracking status to the WooCommerce status it may ADVANCE
 * an order to, or null when the status must only be recorded (no advancement).
 * The mapping is intentionally conservative and forward-only:
 *
 *   - "Shipped" (WC `completed`) — accepted handoff / in-transit states, INCLUDING
 *     `in-locker` (parcel is in the destination locker but not yet collected).
 *   - "Delivered" (WC `delivered`) — `customer-collected` and `delivered` only.
 *   - Everything else — quote/payment/collection/delivery exceptions, cancellations,
 *     AND ANY UNKNOWN status — maps to null and never advances WC status.
 *
 * Note the deliberate asymmetry (architecture §12): `in-locker` → Shipped, NOT
 * delivered; `customer-collected` → delivered. This class only decides the
 * candidate target; the actual forward-only advancement (never regress, never
 * re-fire) is enforced by the shared ES_Fulfillment_Cron status-priority guard,
 * so a mapped target only takes effect when it is strictly ahead of the order's
 * current status.
 *
 * @package ERPNext_Shipping
 */

defined( 'ABSPATH' ) || exit;

class ES_TCG_Locker_Tracking {

	/**
	 * Timezone TCG's naive event datetimes are expressed in.
	 *
	 * TCG Locker (PUDO) is a South-Africa-only network, so its clock is SAST no
	 * matter how the WordPress site is configured. Deliberately NOT wp_timezone():
	 * a store running WordPress on UTC would otherwise read every arrival two hours
	 * late and promise a deadline two hours after the real collection cutoff.
	 * Verified 2026-07-17 against four live shipments — see event_timestamp().
	 */
	const PROVIDER_TZ = 'Africa/Johannesburg';

	/** Provider clock as a DateTimeZone. */
	public static function provider_tz() {
		return new DateTimeZone( self::PROVIDER_TZ );
	}

	/** Raw statuses that advance an order to WC `completed` ("Shipped"). */
	private static $shipped = array(
		'customer-deposited',
		'courier-collected',
		'collected',
		'at-hub',
		'in-transit',
		'at-destination-hub',
		'delivery-assigned',
		'out-for-delivery',
		'in-locker', // In the destination locker — Shipped, NOT delivered.
	);

	/** Raw statuses that advance an order to WC `delivered`. */
	private static $delivered = array(
		'customer-collected', // Customer took it out of the locker.
		'delivered',
	);

	/**
	 * Map a raw TCG Locker status to the WC status it may advance to.
	 *
	 * @param string $raw Raw provider status (any case; hyphenated slug).
	 * @return string|null 'completed' | 'delivered' | null (record-only / unknown).
	 */
	public static function map_status( $raw ) {
		$raw = strtolower( trim( (string) $raw ) );
		if ( '' === $raw ) {
			return null;
		}
		if ( in_array( $raw, self::$delivered, true ) ) {
			return 'delivered';
		}
		if ( in_array( $raw, self::$shipped, true ) ) {
			return 'completed';
		}
		return null; // Record-only (exceptions/cancellations) or unknown — never advance.
	}

	/** True when the raw status is a known (documented) vocabulary term. */
	public static function is_known( $raw ) {
		$raw = strtolower( trim( (string) $raw ) );
		return '' !== $raw && in_array( $raw, self::vocabulary(), true );
	}

	/** Human label for a raw status slug, e.g. 'in-locker' → 'In Locker'. */
	public static function label( $raw ) {
		$raw = strtolower( trim( (string) $raw ) );
		if ( '' === $raw ) {
			return '';
		}
		return ucwords( str_replace( array( '-', '_' ), ' ', $raw ) );
	}

	/** The full documented status vocabulary (§12) for display/diagnostics. */
	public static function vocabulary() {
		return array(
			'quote-pending',
			'payment-failed',
			'submitted',
			'deposit-pending',
			'customer-deposited',
			'courier-collected',
			'collection-assigned',
			'collection-unassigned',
			'collection-rejected',
			'collection-exception',
			'collection-failed',
			'collected',
			'at-hub',
			'in-transit',
			'at-destination-hub',
			'delivery-assigned',
			'out-for-delivery',
			'delivery-exception',
			'delivery-failed-attempt',
			'returned-to-hub',
			'in-locker',
			'customer-collected',
			'delivered',
			'cancelled',
			'cancel-booking-expired',
		);
	}

	// ───────────────────── Arrival / collection window ─────────────────────

	/**
	 * Resolve the payload node carrying the shipment fields.
	 * Mirrors ES_TCG_Locker_Client::extract_tracking_status()'s node resolution so
	 * both read the same place if the provider ever nests the response.
	 */
	private static function node( $data ) {
		if ( ! is_array( $data ) ) {
			return array();
		}
		if ( isset( $data['shipment'] ) && is_array( $data['shipment'] ) ) {
			return $data['shipment'];
		}
		if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
			return $data['data'];
		}
		return $data;
	}

	/**
	 * Earliest tracking-event date for a raw status, exactly as the provider sent it.
	 *
	 * The provider returns events newest-first, and a parcel can re-enter a state
	 * (e.g. removed and re-deposited), so we take the EARLIEST match — first arrival
	 * starts the collection clock. Returns '' when no such event is present.
	 *
	 * Pure: no WP, no timezone interpretation (see event_timestamp()).
	 *
	 * @param array  $data   Raw tracking payload.
	 * @param string $status Raw status slug, e.g. 'in-locker'.
	 * @return string Provider datetime string, or ''.
	 */
	public static function first_event_time( $data, $status ) {
		$status = strtolower( trim( (string) $status ) );
		if ( '' === $status ) {
			return '';
		}
		$events = self::node( $data )['tracking_events'] ?? array();
		if ( ! is_array( $events ) ) {
			return '';
		}
		$best = '';
		foreach ( $events as $ev ) {
			if ( ! is_array( $ev ) ) {
				continue;
			}
			if ( strtolower( trim( (string) ( $ev['status'] ?? '' ) ) ) !== $status ) {
				continue;
			}
			$date = trim( (string) ( $ev['date'] ?? '' ) );
			if ( '' === $date ) {
				continue;
			}
			// Provider format is fixed-width ISO-like, so lexical order == chronological.
			if ( '' === $best || $date < $best ) {
				$best = $date;
			}
		}
		return $best;
	}

	/**
	 * Convert a provider event date to a UTC timestamp.
	 *
	 * TCG sends NAIVE local datetimes with no offset ("2026-07-15 08:03:55.596697").
	 * Verified 2026-07-17 against four live shipments: the provider's
	 * `shipment_time_created` matches WooCommerce's own booking timestamp to within
	 * 1 second when read as SAST — i.e. these are South African local times.
	 *
	 * Callers should pass self::provider_tz(), NOT the site timezone: the provider's
	 * clock is SAST regardless of how this WordPress install is configured.
	 * Injected rather than read inside, so the method stays pure and testable.
	 *
	 * @param string       $raw Provider datetime.
	 * @param DateTimeZone $tz  Timezone to interpret $raw in.
	 * @return int UTC timestamp, or 0 when unparseable.
	 */
	public static function event_timestamp( $raw, $tz ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw || ! $tz instanceof DateTimeZone ) {
			return 0;
		}
		$raw = preg_replace( '/\.\d+$/', '', $raw ); // drop fractional seconds
		try {
			$dt = new DateTime( $raw, $tz );
		} catch ( Exception $ex ) {
			return 0;
		}
		return (int) $dt->getTimestamp();
	}

	/**
	 * Clamp the operator-set collection window (hours). Mirrors
	 * ES_TCG_Locker_Packer::sane_fill_factor(): unset/garbage falls back to the
	 * default rather than disabling the promise or inventing an absurd one.
	 */
	public static function sane_collection_hours( $value, $default = 36.0 ) {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return (float) $default;
		}
		$v = (float) $value;
		if ( $v <= 0 || $v > 720 ) { // 0 or >30 days is not a collection window
			return (float) $default;
		}
		return $v;
	}

	/**
	 * Collection deadline = arrival + window. Pure.
	 *
	 * Keyed on the provider's own arrival event, never on our poll clock — the poll
	 * runs every 15 minutes, so a poll-derived deadline would always sit LATER than
	 * the real one and could send a customer to an emptied locker.
	 *
	 * @return int UTC timestamp, or 0 when arrival is unknown.
	 */
	public static function collection_deadline( $arrival_ts, $hours ) {
		$arrival_ts = (int) $arrival_ts;
		if ( $arrival_ts <= 0 ) {
			return 0;
		}
		return $arrival_ts + (int) round( self::sane_collection_hours( $hours ) * 3600 );
	}
}
