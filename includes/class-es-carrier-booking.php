<?php
/**
 * Pure contracts and decisions for manual door-carrier booking.
 *
 * No WordPress dependency: request construction, response classification and the
 * short-lived confirmation contract are unit-testable in the repo harness.
 */

defined( 'ABSPATH' ) || exit;

class ES_Carrier_Booking {

	const PROVIDER_TCG = 'the-courier-guy';
	const PROVIDER_MDS = 'mds-collivery';

	const STATE_NONE      = 'none';
	const STATE_BOOKING   = 'booking';
	const STATE_BOOKED    = 'booked';
	const STATE_AMBIGUOUS = 'ambiguous';
	const STATE_ERROR     = 'error';

	const M_PROVIDER       = '_es_carrier_provider';
	const M_SERVICE_CODE   = '_es_carrier_service_code';
	const M_SERVICE_NAME   = '_es_carrier_service_name';
	const M_TIER           = '_es_carrier_service_tier';
	const M_INSTANCE_ID    = '_es_carrier_instance_id';
	const M_ORIGIN_LOC     = '_es_carrier_origin_location_id';
	const M_PROVIDER_RATE  = '_es_carrier_provider_rate';
	const M_CUSTOMER_CHG   = '_es_carrier_customer_charge';
	const M_PARCELS        = '_es_carrier_parcels';
	const M_QUOTE_TS       = '_es_carrier_quote_ts';
	const M_IS_SPLIT       = '_es_carrier_is_split';

	const M_BOOKING_STATUS = '_es_carrier_booking_status';
	const M_SHIPMENT_ID    = '_es_carrier_shipment_id';
	const M_TRACKING_REF   = '_es_carrier_tracking_ref';
	const M_BOOKED_TS      = '_es_carrier_booked_ts';
	const M_BOOKED_PROVIDER= '_es_carrier_booked_provider';
	const M_BOOKED_SERVICE = '_es_carrier_booked_service';
	const M_BOOKED_SERVICE_NAME = '_es_carrier_booked_service_name';
	const M_LAST_ERROR     = '_es_carrier_booking_last_error';
	const M_CONFIRMATION   = '_es_carrier_booking_confirmation';

	const CONFIRM_TTL = 300;

	public static function supported_provider( $provider ) {
		return in_array( (string) $provider, array( self::PROVIDER_TCG, self::PROVIDER_MDS ), true );
	}

	public static function provider_name( $provider ) {
		return self::PROVIDER_MDS === $provider ? 'MDS Collivery' : 'The Courier Guy';
	}

	/**
	 * Pick the booking rate for an operator's carrier choice.
	 *
	 * The customer's carrier retains its exact checkout service. An override uses
	 * the cheapest currently returned service in the same delivery tier, so an
	 * Economy order cannot silently become Express (or vice versa).
	 */
	public static function select_booking_rate( array $rates, $same_provider, $service, $tier ) {
		$service = (string) $service;
		$tier    = strtolower( trim( (string) $tier ) );
		$best    = null;

		foreach ( $rates as $rate ) {
			$code  = (string) ( $rate['booking_service'] ?? $rate['service_code'] ?? '' );
			$price = $rate['price_incl_vat'] ?? null;
			if ( '' === $code || ! is_numeric( $price ) || (float) $price <= 0 ) {
				continue;
			}
			if ( $same_provider ) {
				if ( ! hash_equals( $service, $code ) ) {
					continue;
				}
			} elseif ( '' === $tier || $tier !== strtolower( trim( (string) ( $rate['tier'] ?? '' ) ) ) ) {
				continue;
			}

			$candidate = array(
				'service'      => $code,
				'service_name' => (string) ( $rate['service_name'] ?? $code ),
				'tier'         => (string) ( $rate['tier'] ?? $tier ),
				'rate'         => round( (float) $price, 2 ),
			);
			if ( null === $best || $candidate['rate'] < $best['rate'] ) {
				$best = $candidate;
			}
			if ( $same_provider ) {
				break;
			}
		}

		return $best;
	}

	/** Build hidden WC rate meta for a single-origin door quote. */
	public static function build_rate_meta( array $rate, $origin_location_id, array $parcels, $customer_charge, $is_split = false, $quoted_at = null, $instance_id = 0 ) {
		$provider = self::normalize_provider( $rate['carrier'] ?? '' );
		$service  = (string) ( $rate['booking_service'] ?? $rate['service_code'] ?? '' );
		$cost     = $rate['price_incl_vat'] ?? null;

		if ( ! self::supported_provider( $provider ) || '' === $service || ! is_numeric( $cost ) || (float) $cost <= 0 ) {
			return array();
		}
		if ( self::PROVIDER_MDS === $provider && ! ctype_digit( $service ) ) {
			return array();
		}

		$clean_parcels = self::normalize_parcels( $parcels );
		if ( empty( $clean_parcels ) ) {
			return array();
		}

		return array(
			self::M_PROVIDER      => $provider,
			self::M_SERVICE_CODE  => $service,
			self::M_SERVICE_NAME  => (string) ( $rate['service_name'] ?? $service ),
			self::M_TIER          => (string) ( $rate['tier'] ?? 'standard' ),
			self::M_INSTANCE_ID   => (string) max( 0, (int) $instance_id ),
			self::M_ORIGIN_LOC    => (string) $origin_location_id,
			self::M_PROVIDER_RATE => number_format( (float) $cost, 2, '.', '' ),
			self::M_CUSTOMER_CHG  => number_format( (float) $customer_charge, 2, '.', '' ),
			self::M_PARCELS       => json_encode( $clean_parcels ),
			self::M_QUOTE_TS      => (string) ( null === $quoted_at ? time() : (int) $quoted_at ),
			self::M_IS_SPLIT      => $is_split ? '1' : '0',
		);
	}

	public static function normalize_provider( $value ) {
		$v = strtolower( trim( (string) $value ) );
		if ( false !== strpos( $v, 'collivery' ) || 'mds' === $v || 'mds-collivery' === $v ) {
			return self::PROVIDER_MDS;
		}
		if ( false !== strpos( $v, 'courier guy' ) || false !== strpos( $v, 'shiplogic' ) || 'the-courier-guy' === $v ) {
			return self::PROVIDER_TCG;
		}
		return '';
	}

	public static function normalize_parcels( array $parcels ) {
		$out = array();
		foreach ( $parcels as $parcel ) {
			$p = array(
				'weight_kg' => round( (float) ( $parcel['weight_kg'] ?? 0 ), 2 ),
				'length_cm' => round( (float) ( $parcel['length_cm'] ?? 0 ), 1 ),
				'width_cm'  => round( (float) ( $parcel['width_cm'] ?? 0 ), 1 ),
				'height_cm' => round( (float) ( $parcel['height_cm'] ?? 0 ), 1 ),
			);
			if ( min( $p ) <= 0 ) {
				return array();
			}
			$out[] = $p;
		}
		return $out;
	}

	public static function guard( $shipment_id, $status ) {
		if ( '' !== trim( (string) $shipment_id ) ) {
			return array( 'can' => false, 'reason' => 'already_booked' );
		}
		if ( in_array( (string) $status, array( self::STATE_BOOKING, self::STATE_AMBIGUOUS ), true ) ) {
			return array( 'can' => false, 'reason' => 'ambiguous_locked' );
		}
		return array( 'can' => true, 'reason' => '' );
	}

	/**
	 * Classify an external create call. 408/409/429, transport failures, 5xx and
	 * malformed success responses are ambiguous because the provider may have
	 * accepted the booking before our response path failed.
	 */
	public static function classify_result( array $result ) {
		$http = (int) ( $result['http'] ?? 0 );
		$id   = trim( (string) ( $result['shipment_id'] ?? '' ) );
		if ( ! empty( $result['ok'] ) && '' !== $id ) {
			return array(
				'state'        => self::STATE_BOOKED,
				'shipment_id'  => $id,
				'tracking_ref' => trim( (string) ( $result['tracking_ref'] ?? '' ) ),
				'error'        => '',
			);
		}
		$error = trim( (string) ( $result['error'] ?? 'Unknown provider response' ) );
		if ( ! empty( $result['transport_error'] ) || 0 === $http || $http >= 500 || in_array( $http, array( 408, 409, 429 ), true ) || ( $http >= 200 && $http < 300 ) ) {
			return array( 'state' => self::STATE_AMBIGUOUS, 'shipment_id' => '', 'tracking_ref' => '', 'error' => $error );
		}
		return array( 'state' => self::STATE_ERROR, 'shipment_id' => '', 'tracking_ref' => '', 'error' => $error );
	}

	public static function snapshot_hash( array $snapshot, array $current ) {
		$payload = array(
			'provider' => (string) ( $snapshot[ self::M_PROVIDER ] ?? '' ),
			'service'  => (string) ( $snapshot[ self::M_SERVICE_CODE ] ?? '' ),
			'tier'     => (string) ( $snapshot[ self::M_TIER ] ?? '' ),
			'origin'   => (string) ( $snapshot[ self::M_ORIGIN_LOC ] ?? '' ),
			'parcels'  => self::normalize_parcels( (array) ( $current['parcels'] ?? array() ) ),
			'from'     => self::canonical_address( (array) ( $current['origin'] ?? array() ) ),
			'to'       => self::canonical_address( (array) ( $current['destination'] ?? array() ) ),
			'contact'  => array_values( (array) ( $current['delivery_contact'] ?? array() ) ),
		);
		return hash( 'sha256', json_encode( $payload ) );
	}

	public static function make_confirmation( $token, array $snapshot, array $current, $fresh_rate, $now = null ) {
		$now = null === $now ? time() : (int) $now;
		return array(
			'token_hash'    => hash( 'sha256', (string) $token ),
			'snapshot_hash' => self::snapshot_hash( $snapshot, $current ),
			'fresh_rate'    => round( (float) $fresh_rate, 2 ),
			'provider'      => (string) ( $snapshot[ self::M_PROVIDER ] ?? '' ),
			'service'       => (string) ( $snapshot[ self::M_SERVICE_CODE ] ?? '' ),
			'service_name'  => (string) ( $snapshot[ self::M_SERVICE_NAME ] ?? '' ),
			'tier'          => (string) ( $snapshot[ self::M_TIER ] ?? '' ),
			'expires'       => $now + self::CONFIRM_TTL,
		);
	}

	public static function confirmation_valid( array $confirmation, $token, array $snapshot, array $current, $now = null ) {
		$now = null === $now ? time() : (int) $now;
		return ! empty( $confirmation['token_hash'] )
			&& hash_equals( (string) $confirmation['token_hash'], hash( 'sha256', (string) $token ) )
			&& (int) ( $confirmation['expires'] ?? 0 ) >= $now
			&& hash_equals( (string) ( $confirmation['snapshot_hash'] ?? '' ), self::snapshot_hash( $snapshot, $current ) )
			&& is_numeric( $confirmation['fresh_rate'] ?? null )
			&& (float) $confirmation['fresh_rate'] > 0;
	}

	/** Require the immediately-pre-book quote to match the operator-confirmed cents. */
	public static function confirmation_rate_matches( array $confirmation, $fresh_rate ) {
		return is_numeric( $confirmation['fresh_rate'] ?? null )
			&& is_numeric( $fresh_rate )
			&& (float) $fresh_rate > 0
			&& round( (float) $confirmation['fresh_rate'], 2 ) === round( (float) $fresh_rate, 2 );
	}

	public static function canonical_address( array $address ) {
		return array(
			'street_address' => trim( (string) ( $address['street_address'] ?? '' ) ),
			'local_area'     => trim( (string) ( $address['local_area'] ?? '' ) ),
			'city'           => trim( (string) ( $address['city'] ?? '' ) ),
			'zone'           => trim( (string) ( $address['zone'] ?? '' ) ),
			'country'        => strtoupper( trim( (string) ( $address['country'] ?? 'ZA' ) ) ),
			'code'           => trim( (string) ( $address['code'] ?? '' ) ),
		);
	}
}
