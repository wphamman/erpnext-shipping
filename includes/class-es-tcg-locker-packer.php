<?php
/**
 * TCG Locker parcel packer (Locker-to-Locker eligibility + box selection).
 *
 * A pure, testable packer — deliberately separate from ES_Parcel_Estimator,
 * which is tuned for door-carrier parcel arrays (max-dimension only, no volume,
 * no box-fit) and MUST NOT be changed by this feature.
 *
 * The two-method design resolves the "packer needs boxes, boxes come from
 * /rates" circularity (see docs/tcg-locker-architecture.md §4.2):
 *
 *   1. compute_requirements()  — box-INDEPENDENT; runs BEFORE /rates.
 *   2. fits_box()/select_smallest() — box-AWARE; run AFTER /rates over the
 *      boxes actually returned by the provider.
 *
 * Conservative throughout: missing/insufficient packaging data yields a
 * structured ineligibility reason rather than an optimistic "fits".
 *
 * @package ERPNext_Shipping
 */

defined( 'ABSPATH' ) || exit;

class ES_TCG_Locker_Packer {

	/**
	 * Conservative usable fraction of a box's gross volume, applied to the
	 * CUMULATIVE item volume when there is more than one unit (real irregular
	 * items never pack to 100%). A single unit whose dimensions already fit the
	 * box is authoritative and is not penalised by this factor.
	 */
	const FILL_FACTOR = 0.80;

	/**
	 * Static reference catalogue (typical locker classes). For tests and the
	 * OPTIONAL pre-/rates short-circuit only — a live /rates response is always
	 * authoritative for actual availability and selection.
	 */
	const STATIC_BOXES = array(
		'XS' => array( 'length' => 60, 'width' => 17, 'height' => 8,  'max_weight' => 2 ),
		'S'  => array( 'length' => 60, 'width' => 41, 'height' => 8,  'max_weight' => 5 ),
		'M'  => array( 'length' => 60, 'width' => 41, 'height' => 19, 'max_weight' => 10 ),
		'L'  => array( 'length' => 60, 'width' => 41, 'height' => 41, 'max_weight' => 15 ),
		'XL' => array( 'length' => 60, 'width' => 41, 'height' => 69, 'max_weight' => 20 ),
	);

	const EPS = 1e-9;

	/**
	 * Box-independent: compute the packed-order profile from cart lines. Runs
	 * BEFORE /rates.
	 *
	 * @param array $lines Each: [ 'sku'?, 'qty', 'weight' (per-unit kg),
	 *                      'length','width','height' (cm),
	 *                      'locker_eligible' (bool, default true) ].
	 * @return array Success:
	 *   [ 'ok'=>true, 'total_weight'=>float, 'total_volume'=>float,
	 *     'unit_count'=>float, 'max_dims'=>[a,b,c] (sorted asc) ]
	 *   Failure: [ 'ok'=>false, 'reason'=>string ] where reason is one of
	 *   no_items | product_excluded | missing_quantity | missing_weight |
	 *   missing_dimensions.
	 */
	public static function compute_requirements( $lines ) {
		if ( ! is_array( $lines ) || empty( $lines ) ) {
			return array( 'ok' => false, 'reason' => 'no_items' );
		}

		$total_weight = 0.0;
		$total_volume = 0.0;
		$unit_count   = 0.0;
		$max_dims     = array( 0.0, 0.0, 0.0 );

		foreach ( $lines as $line ) {
			// Explicit per-product opt-out ("ship separately / locker ineligible").
			if ( array_key_exists( 'locker_eligible', $line ) && ! $line['locker_eligible'] ) {
				return array( 'ok' => false, 'reason' => 'product_excluded' );
			}

			// Preserve fractional quantities (loose per-kg malt must not be undercounted).
			$qty = isset( $line['qty'] ) ? (float) $line['qty'] : 0.0;
			if ( $qty <= 0 ) {
				return array( 'ok' => false, 'reason' => 'missing_quantity' );
			}

			$weight = isset( $line['weight'] ) ? (float) $line['weight'] : 0.0;
			if ( $weight <= 0 ) {
				return array( 'ok' => false, 'reason' => 'missing_weight' );
			}

			$dims = array(
				isset( $line['length'] ) ? (float) $line['length'] : 0.0,
				isset( $line['width'] )  ? (float) $line['width']  : 0.0,
				isset( $line['height'] ) ? (float) $line['height'] : 0.0,
			);
			foreach ( $dims as $d ) {
				if ( $d <= 0 ) {
					return array( 'ok' => false, 'reason' => 'missing_dimensions' );
				}
			}

			sort( $dims ); // Ascending — rotation-agnostic.
			for ( $i = 0; $i < 3; $i++ ) {
				if ( $dims[ $i ] > $max_dims[ $i ] ) {
					$max_dims[ $i ] = $dims[ $i ];
				}
			}

			$total_weight += $weight * $qty;
			$total_volume += ( $dims[0] * $dims[1] * $dims[2] ) * $qty;
			$unit_count   += $qty;
		}

		return array(
			'ok'           => true,
			'total_weight' => $total_weight,
			'total_volume' => $total_volume,
			'unit_count'   => $unit_count,
			'max_dims'     => $max_dims,
		);
	}

	/**
	 * Box-aware: does the packed order fit a single candidate box?
	 *
	 * @param array $requirements Output of compute_requirements().
	 * @param array $box [ 'length','width','height','max_weight' ]. Null/
	 *                    non-positive box data fails closed (never optimistic).
	 * @return bool
	 */
	public static function fits_box( $requirements, $box ) {
		if ( empty( $requirements['ok'] ) ) {
			return false;
		}

		// Max weight — must be present and positive; enforce the box's actual limit.
		$mw = ( is_array( $box ) && isset( $box['max_weight'] ) ) ? $box['max_weight'] : null;
		if ( ! is_numeric( $mw ) || $mw <= 0 ) {
			return false;
		}
		if ( $requirements['total_weight'] > (float) $mw + self::EPS ) {
			return false;
		}

		// Dimensions — every item must physically fit (rotation allowed via sorting).
		$bdims = array(
			( is_array( $box ) && isset( $box['length'] ) && is_numeric( $box['length'] ) ) ? (float) $box['length'] : 0.0,
			( is_array( $box ) && isset( $box['width'] ) && is_numeric( $box['width'] ) ) ? (float) $box['width'] : 0.0,
			( is_array( $box ) && isset( $box['height'] ) && is_numeric( $box['height'] ) ) ? (float) $box['height'] : 0.0,
		);
		foreach ( $bdims as $d ) {
			if ( $d <= 0 ) {
				return false;
			}
		}
		sort( $bdims );
		$mdims = $requirements['max_dims'];
		for ( $i = 0; $i < 3; $i++ ) {
			if ( $mdims[ $i ] > $bdims[ $i ] + self::EPS ) {
				return false;
			}
		}

		// Cumulative volume — apply the conservative fill factor only when there
		// is more than one unit. A single unit that dimensionally fits is
		// authoritative and not penalised.
		$box_volume = $bdims[0] * $bdims[1] * $bdims[2];
		$unit_count = isset( $requirements['unit_count'] ) ? (float) $requirements['unit_count'] : 1.0;
		if ( $unit_count > 1.0 + self::EPS ) {
			if ( $requirements['total_volume'] > $box_volume * self::FILL_FACTOR + self::EPS ) {
				return false;
			}
		} elseif ( $requirements['total_volume'] > $box_volume + self::EPS ) {
			return false;
		}

		return true;
	}

	/**
	 * Box-aware: choose the smallest fitting box among the RETURNED offers. Runs
	 * AFTER /rates. The provider's returned services are authoritative; the
	 * static catalogue is never substituted here.
	 *
	 * @param array $requirements Output of compute_requirements().
	 * @param array $offers Array of offers, each with a 'dimensions' box
	 *                      (length/width/height/max_weight). Extra keys
	 *                      (service_code, box_code, rate, …) are preserved.
	 * @return array [ 'ok'=>true, 'offer'=>array ]
	 *               | [ 'ok'=>false, 'reason'=>string ]
	 *               ('no_box_fits', 'no_services', or the compute reason).
	 */
	public static function select_smallest( $requirements, $offers ) {
		if ( empty( $requirements['ok'] ) ) {
			return array( 'ok' => false, 'reason' => $requirements['reason'] ?? 'ineligible' );
		}
		if ( ! is_array( $offers ) || empty( $offers ) ) {
			return array( 'ok' => false, 'reason' => 'no_services' );
		}

		$best        = null;
		$best_volume = null;
		foreach ( $offers as $offer ) {
			$box = ( is_array( $offer ) && isset( $offer['dimensions'] ) && is_array( $offer['dimensions'] ) )
				? $offer['dimensions'] : array();
			if ( ! self::fits_box( $requirements, $box ) ) {
				continue;
			}
			$vol = (float) $box['length'] * (float) $box['width'] * (float) $box['height'];
			if ( null === $best_volume || $vol < $best_volume ) {
				$best_volume = $vol;
				$best        = $offer;
			}
		}

		if ( null === $best ) {
			return array( 'ok' => false, 'reason' => 'no_box_fits' );
		}
		return array( 'ok' => true, 'offer' => $best );
	}

	/**
	 * Optional cheap pre-/rates gate: could this order plausibly fit ANY known
	 * locker box? Uses the static catalogue so an obviously oversized order can
	 * skip the /rates call. NEVER used to select a box or to override a live
	 * response.
	 */
	public static function plausibly_fits_any( $requirements ) {
		if ( empty( $requirements['ok'] ) ) {
			return false;
		}
		foreach ( self::STATIC_BOXES as $box ) {
			if ( self::fits_box( $requirements, $box ) ) {
				return true;
			}
		}
		return false;
	}
}
