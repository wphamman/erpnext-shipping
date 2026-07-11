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
}
