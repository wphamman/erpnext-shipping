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
	const M_PROVIDER_RATE = '_es_tcg_locker_provider_rate';
	const M_PROVIDER_EX   = '_es_tcg_locker_provider_rate_ex_vat';
	const M_CUSTOMER_CHG  = '_es_tcg_locker_customer_charge';
	const M_REVISION_ID   = '_es_tcg_locker_rate_revision_id';
	const M_QUOTE_TS      = '_es_tcg_locker_quote_ts';
	const M_PRICING_MODE  = '_es_tcg_locker_pricing_mode';

	/** Store VAT rate used to split the provider's VAT-inclusive rate. */
	const VAT_RATE = 0.15;

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
	 * @return array [ pricing_mode, customer_charge_incl, rate_cost_ex_vat,
	 *                 provider_rate_incl, provider_rate_ex_vat ]
	 */
	public static function compute_pricing( $offer, $mode, $fixed, $free_threshold, $cart_total, $vat_rate = self::VAT_RATE ) {
		$provider_incl = isset( $offer['rate'] ) && is_numeric( $offer['rate'] ) ? (float) $offer['rate'] : 0.0;
		$provider_ex   = isset( $offer['rate_excluding_vat'] ) && is_numeric( $offer['rate_excluding_vat'] ) ? (float) $offer['rate_excluding_vat'] : null;

		$free = ( $free_threshold > 0 && $cart_total >= $free_threshold );

		if ( $free ) {
			$target_incl = 0.0;
			$mode_out    = 'free';
		} elseif ( 'fixed' === $mode ) {
			$target_incl = max( 0.0, (float) $fixed );
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
			'pricing_mode'         => $mode_out,
			'customer_charge_incl' => round( $target_incl, 2 ),
			'rate_cost_ex_vat'     => round( $cost_ex, 2 ),
			'provider_rate_incl'   => round( $provider_incl, 2 ),
			'provider_rate_ex_vat' => null === $provider_ex ? null : round( $provider_ex, 2 ),
		);
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
	 * Assemble the WC rate meta_data for the `_locker` rate. Underscore-prefixed
	 * keys are hidden from the customer and persist onto the order shipping item;
	 * the plain keys are shown beneath the rate. Pure — the caller supplies the
	 * timestamp.
	 *
	 * @param array $locker    Validated locker record (code/name/address/lat/lng).
	 * @param array $offer     Chosen offer from get_rates().
	 * @param string $origin_id Persisted dispatch location id.
	 * @param array $pricing   Output of compute_pricing().
	 * @param int   $quote_ts  Quote timestamp.
	 * @return array meta_data map.
	 */
	public static function build_rate_meta( $locker, $offer, $origin_id, $pricing, $quote_ts ) {
		$box_display = '' !== ( $offer['box_size'] ?? '' ) ? $offer['box_size'] : ( $offer['box_name'] ?? '' );

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
			self::M_PROVIDER_RATE => (string) $pricing['provider_rate_incl'],
			self::M_CUSTOMER_CHG  => (string) $pricing['customer_charge_incl'],
			self::M_REVISION_ID   => (string) ( $offer['rate_revision_id'] ?? '' ),
			self::M_QUOTE_TS      => (string) (int) $quote_ts,
			self::M_PRICING_MODE  => (string) $pricing['pricing_mode'],
		);

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
}
