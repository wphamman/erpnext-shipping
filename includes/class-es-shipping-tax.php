<?php
/** Pure helpers for handing VAT-inclusive shipping prices to WooCommerce. */

defined( 'ABSPATH' ) || exit;

class ES_Shipping_Tax {

	/**
	 * Normalize WooCommerce's "prices entered with tax" setting.
	 */
	public static function configured_amount_includes_tax( $value ) {
		return true === $value || 1 === $value || '1' === $value || 'yes' === strtolower( (string) $value );
	}

	/**
	 * Split a gross customer charge into WooCommerce's net cost + tax map.
	 *
	 * WC_Tax calculates the inclusive tax map in the runtime-facing caller. This
	 * pure boundary validates that result before a rate can be added, preventing
	 * malformed tax data from producing a negative or silently free rate.
	 */
	public static function split_inclusive( $gross, array $taxes ) {
		if ( ! is_numeric( $gross ) || (float) $gross < 0 ) {
			return array( 'ok' => false, 'error' => 'invalid_gross' );
		}

		$gross = (float) $gross;
		$clean = array();
		foreach ( $taxes as $rate_id => $amount ) {
			if ( ! is_numeric( $amount ) || (float) $amount < 0 ) {
				return array( 'ok' => false, 'error' => 'invalid_tax' );
			}
			$clean[ $rate_id ] = (float) $amount;
		}

		$tax_total = array_sum( $clean );
		if ( $tax_total > $gross + 0.00001 ) {
			return array( 'ok' => false, 'error' => 'tax_exceeds_gross' );
		}

		return array(
			'ok'        => true,
			'gross'     => $gross,
			'net'       => max( 0, $gross - $tax_total ),
			'taxes'     => $clean,
			'tax_total' => $tax_total,
		);
	}
}
