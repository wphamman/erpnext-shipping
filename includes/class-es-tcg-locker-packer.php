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
 * SOUNDNESS: fits_box() must never claim a fit that cannot be physically
 * realised. For more than one physical unit it does NOT rely on aggregate
 * volume alone (which can pass while items cannot coexist — e.g. two 40³ cubes
 * in a 60×41×69 box); it runs a conservative constructive 3D placement
 * (extreme-point best-fit with real coordinates + overlap test, 6 orientations)
 * and returns true only when every unit is actually placed. The placement
 * heuristic is incomplete, so it may reject some theoretically-packable orders
 * — that is the intended conservative bias.
 *
 * @package ERPNext_Shipping
 */

defined( 'ABSPATH' ) || exit;

class ES_TCG_Locker_Packer {

	/**
	 * Default usable fraction of a box's gross volume, applied to the CUMULATIVE
	 * item volume when there is more than one unit — a cheap NECESSARY pre-filter
	 * (the constructive placement below is the SUFFICIENT check). A single unit
	 * whose dimensions already fit the box is authoritative and is not penalised.
	 *
	 * This constant is the fallback used when no factor is supplied. The live
	 * rate/booking path passes a per-store configurable value (WC setting
	 * `tcg_locker_fill_factor`, default 0.70) via sane_fill_factor().
	 */
	const FILL_FACTOR = 0.80;

	/**
	 * Upper bound on physical units the constructive packer will attempt. Above
	 * this, a fit cannot be cheaply demonstrated, so the order is rejected
	 * (conservative). Locker orders (≤20kg, small boxes) never approach this.
	 */
	const MAX_PLACEMENT_UNITS = 128;

	/**
	 * Static reference catalogue (typical locker classes) — for TESTS ONLY. A
	 * live /rates response is always authoritative for actual availability and
	 * selection; the static catalogue is never used to gate or select at runtime.
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
	 * Clamp a (possibly operator-entered) fill factor to a usable fraction in
	 * (0, 1]. Any non-numeric or out-of-range value falls back to $default. Pure —
	 * the packer never reads WP settings itself; callers pass the resolved value.
	 *
	 * @param mixed $value   Raw setting value (string/float/null).
	 * @param float $default Fallback when $value is unusable (live default 0.70).
	 * @return float
	 */
	public static function sane_fill_factor( $value, $default = 0.70 ) {
		if ( is_numeric( $value ) ) {
			$f = (float) $value;
			if ( $f > 0 && $f <= 1 ) {
				return $f;
			}
		}
		return (float) $default;
	}

	/**
	 * Box-independent: compute the packed-order profile from cart lines. Runs
	 * BEFORE /rates.
	 *
	 * @param array $lines Each: [ 'sku'?, 'qty', 'weight' (per-unit kg),
	 *                      'length','width','height' (cm),
	 *                      'locker_eligible' (bool, default true) ].
	 * @return array Success:
	 *   [ 'ok'=>true, 'total_weight'=>float, 'total_volume'=>float,
	 *     'unit_count'=>float, 'physical_units'=>int, 'over_unit_cap'=>bool,
	 *     'max_dims'=>[a,b,c] (sorted asc),
	 *     'units'=>[ [a,b,c] (sorted asc), ... ] (empty when over the cap) ]
	 *   Failure: [ 'ok'=>false, 'reason'=>string ] where reason is one of
	 *   no_items | product_excluded | missing_quantity | missing_weight |
	 *   missing_dimensions.
	 */
	public static function compute_requirements( $lines ) {
		if ( ! is_array( $lines ) || empty( $lines ) ) {
			return array( 'ok' => false, 'reason' => 'no_items' );
		}

		$total_weight   = 0.0;
		$total_volume   = 0.0;
		$unit_count     = 0.0;
		$physical_units = 0;
		$max_dims       = array( 0.0, 0.0, 0.0 );
		$units          = array();
		$over_cap       = false;

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

			// Physical units for spatial placement: round fractional quantities
			// UP (you cannot ship a fraction of a parcel; over-count is conservative).
			$count           = (int) ceil( $qty - self::EPS );
			$count           = $count < 1 ? 1 : $count;
			$physical_units += $count;

			if ( ! $over_cap ) {
				for ( $i = 0; $i < $count; $i++ ) {
					$units[] = $dims;
					if ( count( $units ) > self::MAX_PLACEMENT_UNITS ) {
						$over_cap = true;
						$units    = array();
						break;
					}
				}
			}
		}

		return array(
			'ok'             => true,
			'total_weight'   => $total_weight,
			'total_volume'   => $total_volume,
			'unit_count'     => $unit_count,
			'physical_units' => $physical_units,
			'over_unit_cap'  => $over_cap,
			'max_dims'       => $max_dims,
			'units'          => $units,
		);
	}

	/**
	 * Box-aware: does the packed order fit a single candidate box?
	 *
	 * @param array $requirements Output of compute_requirements().
	 * @param array $box [ 'length','width','height','max_weight' ]. Null/
	 *                    non-positive box data fails closed (never optimistic).
	 * @param float $fill_factor Usable-volume fraction for the multi-unit pre-filter
	 *                    (see FILL_FACTOR). Invalid values fall back to the default.
	 * @return bool
	 */
	public static function fits_box( $requirements, $box, $fill_factor = self::FILL_FACTOR ) {
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

		// Box dimensions — must all be present and positive.
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

		// Necessary condition: the largest single item must fit (rotation allowed).
		$mdims = $requirements['max_dims'];
		for ( $i = 0; $i < 3; $i++ ) {
			if ( $mdims[ $i ] > $bdims[ $i ] + self::EPS ) {
				return false;
			}
		}

		$box_volume     = $bdims[0] * $bdims[1] * $bdims[2];
		$physical_units = isset( $requirements['physical_units'] ) ? (int) $requirements['physical_units'] : 1;

		// Single physical unit: dimensional fit above already proves placement.
		if ( $physical_units <= 1 ) {
			return $requirements['total_volume'] <= $box_volume + self::EPS;
		}

		// Multi-unit — cheap NECESSARY volume pre-filter (configurable fill factor).
		$ff = self::sane_fill_factor( $fill_factor, self::FILL_FACTOR );
		if ( $requirements['total_volume'] > $box_volume * $ff + self::EPS ) {
			return false;
		}

		// Too many units to demonstrate a placement cheaply → conservative reject.
		if ( ! empty( $requirements['over_unit_cap'] ) || empty( $requirements['units'] ) ) {
			return false;
		}

		// SUFFICIENT check: constructively place every unit, or reject.
		return self::can_place_units( $requirements['units'], $bdims );
	}

	/**
	 * Box-aware: choose the smallest fitting box among the RETURNED offers. Runs
	 * AFTER /rates. The provider's returned services are authoritative.
	 *
	 * @param array $requirements Output of compute_requirements().
	 * @param array $offers Array of offers, each with a 'dimensions' box
	 *                      (length/width/height/max_weight). Extra keys
	 *                      (service_code, box_code, rate, …) are preserved.
	 * @param float $fill_factor Multi-unit usable-volume fraction (see fits_box).
	 * @return array [ 'ok'=>true, 'offer'=>array ]
	 *               | [ 'ok'=>false, 'reason'=>string ]
	 *               ('no_box_fits', 'no_services', or the compute reason).
	 */
	public static function select_smallest( $requirements, $offers, $fill_factor = self::FILL_FACTOR ) {
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
			if ( ! self::fits_box( $requirements, $box, $fill_factor ) ) {
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

	// ─────────────────────────── Constructive placement ────────────────────

	/**
	 * Conservative constructive placement (extreme-point best-fit with real
	 * coordinates). Returns true ONLY when every unit is placed axis-aligned
	 * inside the box with no overlap. Candidate positions are the box origin
	 * plus the far corners generated by each placement; each unit is tried at
	 * the lowest/nearest candidate first, in each of 6 orientations, against a
	 * true overlap test. Heuristic and incomplete — it errs toward rejection,
	 * never toward a false fit.
	 *
	 * @param array $units Array of [a,b,c] item dimensions.
	 * @param array $box   Sorted box dimensions [A,B,C].
	 * @return bool
	 */
	private static function can_place_units( $units, $box ) {
		// Largest items first — place the constrained pieces before the fillers.
		usort(
			$units,
			function ( $a, $b ) {
				return ( $b[0] * $b[1] * $b[2] ) <=> ( $a[0] * $a[1] * $a[2] );
			}
		);

		$bx     = (float) $box[0];
		$by     = (float) $box[1];
		$bz     = (float) $box[2];
		$placed = array();                        // each: [x,y,z,dx,dy,dz]
		$points = array( array( 0.0, 0.0, 0.0 ) ); // candidate corner positions

		foreach ( $units as $unit ) {
			// Fill low/near the origin first (deterministic).
			usort(
				$points,
				function ( $p, $q ) {
					return array( $p[2], $p[1], $p[0] ) <=> array( $q[2], $q[1], $q[0] );
				}
			);

			$done = false;
			foreach ( $points as $p ) {
				foreach ( self::orientations( $unit ) as $o ) {
					if ( self::fits_at( $p, $o, $bx, $by, $bz, $placed ) ) {
						$placed[] = array( $p[0], $p[1], $p[2], $o[0], $o[1], $o[2] );
						self::add_point( $points, array( $p[0] + $o[0], $p[1], $p[2] ) );
						self::add_point( $points, array( $p[0], $p[1] + $o[1], $p[2] ) );
						self::add_point( $points, array( $p[0], $p[1], $p[2] + $o[2] ) );
						$done = true;
						break 2;
					}
				}
			}
			if ( ! $done ) {
				return false; // No position/orientation could hold this unit.
			}
		}

		return true;
	}

	/** The 6 axis-aligned orientations of a unit (deduplicated). */
	private static function orientations( $unit ) {
		$u    = array( (float) $unit[0], (float) $unit[1], (float) $unit[2] );
		$all  = array(
			array( $u[0], $u[1], $u[2] ),
			array( $u[0], $u[2], $u[1] ),
			array( $u[1], $u[0], $u[2] ),
			array( $u[1], $u[2], $u[0] ),
			array( $u[2], $u[0], $u[1] ),
			array( $u[2], $u[1], $u[0] ),
		);
		$out  = array();
		$seen = array();
		foreach ( $all as $o ) {
			$key = $o[0] . 'x' . $o[1] . 'x' . $o[2];
			if ( ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = true;
				$out[]        = $o;
			}
		}
		return $out;
	}

	/** Does an item of size $o placed at corner $p fit in-bounds without overlapping any placed box? */
	private static function fits_at( $p, $o, $bx, $by, $bz, $placed ) {
		$x = $p[0];
		$y = $p[1];
		$z = $p[2];
		if ( $x + $o[0] > $bx + self::EPS || $y + $o[1] > $by + self::EPS || $z + $o[2] > $bz + self::EPS ) {
			return false;
		}
		foreach ( $placed as $b ) {
			$overlap = $x < $b[0] + $b[3] - self::EPS && $x + $o[0] > $b[0] + self::EPS
				&& $y < $b[1] + $b[4] - self::EPS && $y + $o[1] > $b[1] + self::EPS
				&& $z < $b[2] + $b[5] - self::EPS && $z + $o[2] > $b[2] + self::EPS;
			if ( $overlap ) {
				return false;
			}
		}
		return true;
	}

	/** Add a candidate corner point unless an equal one already exists. */
	private static function add_point( &$points, $np ) {
		foreach ( $points as $p ) {
			if ( abs( $p[0] - $np[0] ) < self::EPS && abs( $p[1] - $np[1] ) < self::EPS && abs( $p[2] - $np[2] ) < self::EPS ) {
				return;
			}
		}
		$points[] = $np;
	}
}
