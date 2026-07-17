<?php
/**
 * TCG Locker rate helpers — pure, testable logic for the checkout locker rate.
 *
 * Kept free of WordPress/WooCommerce runtime dependencies so pricing, tax
 * reconciliation, dispatch-origin resolution, and rate-meta assembly can be
 * unit-tested. The WC_Shipping_Method calls these to build the `_locker` rate.
 *
 * @package ERPNext_Shipping
 */

defined( 'ABSPATH' ) || exit;

class ES_TCG_Locker_Rate {

	// Order/shipping-item meta keys (defined once; reused by Phase 4 booking).
	const M_DEST_CODE     = '_es_tcg_locker_dest_code';
	const M_DEST_NAME     = '_es_tcg_locker_dest_name';
	const M_DEST_ADDRESS  = '_es_tcg_locker_dest_address';
	const M_DEST_LAT      = '_es_tcg_locker_dest_lat';
	const M_DEST_LNG      = '_es_tcg_locker_dest_lng';
	const M_DISPATCH_LOC  = '_es_tcg_locker_dispatch_location_id';
	const M_SERVICE_CODE  = '_es_tcg_locker_service_code';
	const M_SERVICE_NAME  = '_es_tcg_locker_service_name';
	const M_BOX_CODE      = '_es_tcg_locker_box_code';
	const M_BOX_NAME      = '_es_tcg_locker_box_name';
	const M_BOX_SIZE      = '_es_tcg_locker_box_size';
	const M_BOX_DIMS      = '_es_tcg_locker_box_dims';
	const M_BOX_MAX_WT    = '_es_tcg_locker_box_max_weight';
	const M_PACKED_WEIGHT = '_es_tcg_locker_packed_weight';
	// Collection window (hours) PROMISED TO THIS CUSTOMER AT CHECKOUT. Snapshotted
	// like every other quote fact so that retuning the setting later can never
	// change the deadline an existing order was sold on.
	const M_COLLECT_HOURS = '_es_tcg_locker_collection_hours';
	const M_PROVIDER_RATE = '_es_tcg_locker_provider_rate';
	const M_PROVIDER_EX   = '_es_tcg_locker_provider_rate_ex_vat';
	const M_CUSTOMER_CHG  = '_es_tcg_locker_customer_charge';
	const M_REVISION_ID   = '_es_tcg_locker_rate_revision_id';
	const M_QUOTE_TS      = '_es_tcg_locker_quote_ts';
	const M_PRICING_MODE  = '_es_tcg_locker_pricing_mode';

	/** Store VAT rate used to split the provider's VAT-inclusive rate. */
	const VAT_RATE = 0.15;

	/** Is this the plugin's dedicated locker rate id? */
	public static function is_locker_rate_id( $rate_id ) {
		return is_string( $rate_id ) && strlen( $rate_id ) >= 7 && '_locker' === substr( $rate_id, -7 );
	}

	/** Return the first locker rate id from a rate-id list, or null. */
	public static function find_locker_rate_id( $rate_ids ) {
		foreach ( (array) $rate_ids as $rate_id ) {
			if ( self::is_locker_rate_id( $rate_id ) ) {
				return $rate_id;
			}
		}
		return null;
	}

	/**
	 * Compute the customer charge for a chosen offer.
	 *
	 * The provider `rate` is VAT-inclusive and `rate_excluding_vat` is net. To
	 * avoid double taxation on a taxable store, we hand WooCommerce the EX-VAT
	 * cost and let it apply the single shipping tax, so the displayed total
	 * reconciles to the VAT-inclusive target.
	 *
	 * @param array  $offer         One offer from ES_TCG_Locker_Client::get_rates().
	 * @param string $mode          'live' | 'fixed'.
	 * @param float  $fixed         Fixed VAT-inclusive customer price (fixed mode).
	 * @param float  $free_threshold TCG-Locker-specific free threshold (0 = off).
	 * @param float  $cart_total    Cart subtotal used against the threshold.
	 * @param float  $vat_rate      Shipping VAT rate (default 15%).
	 * @return array Success: [ ok=>true, pricing_mode, customer_charge_incl,
	 *                 rate_cost_ex_vat, provider_rate_incl, provider_rate_ex_vat ]
	 *               Failure (fail-closed): [ ok=>false, reason ]
	 */
	public static function compute_pricing( $offer, $mode, $fixed, $free_threshold, $cart_total, $vat_rate = self::VAT_RATE ) {
		// A real quote must carry a positive numeric provider rate. Missing,
		// non-numeric, or non-positive pricing fails closed — never a free rate.
		if ( ! isset( $offer['rate'] ) || ! is_numeric( $offer['rate'] ) || (float) $offer['rate'] <= 0 ) {
			return array( 'ok' => false, 'reason' => 'invalid_provider_rate' );
		}
		$provider_incl = (float) $offer['rate'];
		// A usable ex-VAT figure must be strictly positive. A present-but-zero or
		// negative rate_excluding_vat is malformed and must NOT flow through as the
		// WooCommerce cost (that would produce a free/negative locker rate off an
		// otherwise valid positive inclusive rate). Treat it as absent → the ex-VAT
		// cost is derived from the positive inclusive rate below (fail closed).
		$provider_ex   = isset( $offer['rate_excluding_vat'] ) && is_numeric( $offer['rate_excluding_vat'] ) && (float) $offer['rate_excluding_vat'] > 0 ? (float) $offer['rate_excluding_vat'] : null;

		$free = ( $free_threshold > 0 && $cart_total >= $free_threshold );

		if ( $free ) {
			$target_incl = 0.0;
			$mode_out    = 'free';
		} elseif ( 'fixed' === $mode ) {
			// A fixed price must be positive; 0/absent is a misconfiguration, not free.
			if ( (float) $fixed <= 0 ) {
				return array( 'ok' => false, 'reason' => 'invalid_fixed_price' );
			}
			$target_incl = (float) $fixed;
			$mode_out    = 'fixed';
		} else {
			$target_incl = $provider_incl;
			$mode_out    = 'live';
		}

		// Ex-VAT cost for WooCommerce (single shipping tax applied on top).
		if ( $free ) {
			$cost_ex = 0.0;
		} elseif ( 'live' === $mode_out && null !== $provider_ex ) {
			// Use the provider's own ex-VAT figure for maximum fidelity.
			$cost_ex = $provider_ex;
		} else {
			$cost_ex = ( $vat_rate > -1.0 ) ? $target_incl / ( 1.0 + $vat_rate ) : $target_incl;
		}

		return array(
			'ok'                   => true,
			'pricing_mode'         => $mode_out,
			'customer_charge_incl' => round( $target_incl, 2 ),
			'rate_cost_ex_vat'     => round( $cost_ex, 2 ),
			'provider_rate_incl'   => round( $provider_incl, 2 ),
			'provider_rate_ex_vat' => null === $provider_ex ? null : round( $provider_ex, 2 ),
		);
	}

	/**
	 * Is a cart line locker-eligible? Pure so the parent-level variation opt-out
	 * and excluded-class rules are testable.
	 *
	 * @param bool   $own_ineligible    Product's own _es_locker_ineligible flag.
	 * @param bool   $parent_ineligible Parent product's flag (variations).
	 * @param string $shipping_class    Product shipping-class slug.
	 * @param array  $excluded_classes  tcg_locker_excluded_shipping_classes slugs.
	 * @return bool
	 */
	public static function is_line_locker_eligible( $own_ineligible, $parent_ineligible, $shipping_class, $excluded_classes ) {
		if ( $own_ineligible || $parent_ineligible ) {
			return false;
		}
		if ( is_array( $excluded_classes ) && '' !== (string) $shipping_class && in_array( $shipping_class, $excluded_classes, true ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Resolve the dispatch origin (ERPNext-selected warehouse) for a plan, or
	 * null when TCG Locker must not be offered.
	 *
	 * Rules:
	 *  - split plans → null (never offer TCG Locker for split fulfilment);
	 *  - only `warehouse` locations flagged tcg_locker_dispatch_enabled qualify
	 *    (collection points are never dispatch origins);
	 *  - single: the plan's location must be dispatch-enabled;
	 *  - chooseable: the first dispatch-enabled candidate (deterministic by plan
	 *    order) — the L2L rate is destination-only, so any eligible origin yields
	 *    the same quote; the chosen origin is persisted for staff workflow.
	 *
	 * @return string|null Location id, or null.
	 */
	public static function dispatch_origin_for_plan( $plan, $locations ) {
		if ( ! is_array( $plan ) ) {
			return null;
		}
		$type = $plan['type'] ?? '';
		if ( 'split' === $type ) {
			return null;
		}

		$candidates = array();
		if ( 'single' === $type && ! empty( $plan['location'] ) ) {
			$candidates[] = $plan['location'];
		} elseif ( 'chooseable' === $type && ! empty( $plan['locations'] ) ) {
			$candidates = array_values( (array) $plan['locations'] );
		}
		if ( empty( $candidates ) ) {
			return null;
		}

		$enabled = array();
		foreach ( (array) $locations as $loc ) {
			if ( ! is_array( $loc ) || empty( $loc['id'] ) ) {
				continue;
			}
			$is_warehouse = ( $loc['type'] ?? 'warehouse' ) === 'warehouse';
			if ( $is_warehouse && ! empty( $loc['tcg_locker_dispatch_enabled'] ) ) {
				$enabled[ $loc['id'] ] = true;
			}
		}

		foreach ( $candidates as $cid ) {
			if ( isset( $enabled[ $cid ] ) ) {
				return $cid;
			}
		}
		return null;
	}

	/**
	 * Read the persisted locker quote snapshot off an order's shipping line.
	 *
	 * Lives here rather than in ES_TCG_Locker_Admin because that class is only
	 * loaded behind is_admin(), and the tracking poll (WP-Cron) plus the arrival
	 * email need this outside any admin request. ES_TCG_Locker_Admin::
	 * read_order_locker_meta() delegates here, so there is one implementation.
	 *
	 * HPOS-safe: iterates the order's shipping line items and returns the meta of
	 * the one carrying a persisted service code (WooCommerce copies all `_locker`
	 * rate meta_data onto the shipping item at order creation). Returns null when
	 * the order was not shipped via TCG Locker.
	 *
	 * @param WC_Order $order
	 * @return array|null Map of ES_TCG_Locker_Rate::M_* keys → string values.
	 */
	public static function read_order_snapshot( $order ) {
		if ( ! $order || ! is_callable( array( $order, 'get_items' ) ) ) {
			return null;
		}
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			$svc = (string) $item->get_meta( self::M_SERVICE_CODE, true );
			if ( '' === $svc ) {
				continue;
			}
			$keys = array(
				self::M_DEST_CODE,
				self::M_DEST_NAME,
				self::M_DEST_ADDRESS,
				self::M_DISPATCH_LOC,
				self::M_SERVICE_CODE,
				self::M_SERVICE_NAME,
				self::M_BOX_CODE,
				self::M_BOX_NAME,
				self::M_BOX_SIZE,
				self::M_BOX_DIMS,
				self::M_BOX_MAX_WT,
				self::M_PACKED_WEIGHT,
				self::M_PROVIDER_RATE,
				self::M_PROVIDER_EX,
				self::M_CUSTOMER_CHG,
				self::M_REVISION_ID,
				self::M_QUOTE_TS,
				self::M_PRICING_MODE,
				self::M_COLLECT_HOURS,
			);
			$out = array();
			foreach ( $keys as $k ) {
				$v = $item->get_meta( $k, true );
				if ( '' !== (string) $v ) {
					$out[ $k ] = (string) $v;
				}
			}
			return $out;
		}
		return null;
	}

	/**
	 * Assemble the WC rate meta_data for the `_locker` rate. Underscore-prefixed
	 * keys are hidden from the customer and persist onto the order shipping item;
	 * the plain keys are shown beneath the rate. Pure — the caller supplies the
	 * timestamp.
	 *
	 * @param array $locker        Validated locker record (code/name/address/lat/lng).
	 * @param array $offer         Chosen offer from get_rates() (with dimensions).
	 * @param string $origin_id    Persisted dispatch location id.
	 * @param array $pricing       Output of compute_pricing().
	 * @param int   $quote_ts      Quote timestamp.
	 * @param float $packed_weight Packer total packed weight (kg).
	 * @param float $collect_hours Collection window promised at checkout (hours).
	 * @return array meta_data map.
	 */
	public static function build_rate_meta( $locker, $offer, $origin_id, $pricing, $quote_ts, $packed_weight = 0.0, $collect_hours = 36.0 ) {
		$box_display = '' !== ( $offer['box_size'] ?? '' ) ? $offer['box_size'] : ( $offer['box_name'] ?? '' );
		$dims        = is_array( $offer['dimensions'] ?? null ) ? $offer['dimensions'] : array();

		$meta = array(
			// Customer-visible.
			'Locker' => (string) ( $locker['name'] ?? '' ),
			'Box'    => (string) $box_display,
			// Persisted (hidden).
			self::M_DEST_CODE     => (string) ( $locker['code'] ?? '' ),
			self::M_DEST_NAME     => (string) ( $locker['name'] ?? '' ),
			self::M_DEST_ADDRESS  => (string) ( $locker['address'] ?? '' ),
			self::M_DISPATCH_LOC  => (string) $origin_id,
			self::M_SERVICE_CODE  => (string) ( $offer['service_code'] ?? '' ),
			self::M_SERVICE_NAME  => (string) ( $offer['service_name'] ?? '' ),
			self::M_BOX_CODE      => (string) ( $offer['box_code'] ?? '' ),
			self::M_BOX_NAME      => (string) ( $offer['box_name'] ?? '' ),
			self::M_BOX_SIZE      => (string) ( $offer['box_size'] ?? '' ),
			self::M_BOX_DIMS      => self::format_dims( $dims ),
			self::M_PACKED_WEIGHT => (string) round( (float) $packed_weight, 3 ),
			self::M_COLLECT_HOURS => (string) ES_TCG_Locker_Tracking::sane_collection_hours( $collect_hours ),
			self::M_PROVIDER_RATE => (string) $pricing['provider_rate_incl'],
			self::M_CUSTOMER_CHG  => (string) $pricing['customer_charge_incl'],
			self::M_REVISION_ID   => (string) ( $offer['rate_revision_id'] ?? '' ),
			self::M_QUOTE_TS      => (string) (int) $quote_ts,
			self::M_PRICING_MODE  => (string) $pricing['pricing_mode'],
		);

		if ( isset( $dims['max_weight'] ) && null !== $dims['max_weight'] ) {
			$meta[ self::M_BOX_MAX_WT ] = (string) $dims['max_weight'];
		}
		if ( null !== $pricing['provider_rate_ex_vat'] ) {
			$meta[ self::M_PROVIDER_EX ] = (string) $pricing['provider_rate_ex_vat'];
		}
		if ( isset( $locker['lat'] ) && null !== $locker['lat'] ) {
			$meta[ self::M_DEST_LAT ] = (string) $locker['lat'];
		}
		if ( isset( $locker['lng'] ) && null !== $locker['lng'] ) {
			$meta[ self::M_DEST_LNG ] = (string) $locker['lng'];
		}

		return $meta;
	}

	/** "LxWxH" from a normalised dimensions array, or '' when incomplete. */
	private static function format_dims( $dims ) {
		$l = $dims['length'] ?? null;
		$w = $dims['width'] ?? null;
		$h = $dims['height'] ?? null;
		if ( null === $l || null === $w || null === $h ) {
			return '';
		}
		return $l . 'x' . $w . 'x' . $h;
	}
}
