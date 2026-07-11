<?php
defined( 'ABSPATH' ) || exit;

class ES_Carrier_ShipLogic extends ES_Carrier_Base {

    const API_URL = 'https://api.portal.thecourierguy.co.za/v2/rates';

    private $api_token;
    private $company_name;

    // Actual Courier Guy service codes → tiers (confirmed from live API).
    private static $tier_map = array(
        'ECO' => 'economy',   // Economy (3-4 days)
        'OVN' => 'standard',  // Overnight (1-2 days)
        'SDX' => 'express',   // Same Day Express
    );

    // Keyword fallback for any codes not in the map.
    private static $tier_keywords = array(
        'economy'  => array( 'eco', 'road', 'freight', 'budget' ),
        'standard' => array( 'overnight', 'ovn', 'next day', 'nxt' ),
        'express'  => array( 'same day', 'sameday', 'sdx', 'express', 'lox', 'local' ),
    );

    public function __construct( $api_token, $company_name = '' ) {
        $this->api_token    = $api_token;
        $this->company_name = $company_name;
    }

    public function get_carrier_name() {
        return 'The Courier Guy';
    }

    public function is_configured() {
        return ! empty( $this->api_token );
    }

    public function get_rates( $origin, $destination, $parcels ) {
        if ( ! $this->is_configured() ) {
            return false;
        }

        $sl_parcels = array();
        foreach ( $parcels as $p ) {
            $sl_parcels[] = array(
                'submitted_length_cm' => $p['length_cm'],
                'submitted_width_cm'  => $p['width_cm'],
                'submitted_height_cm' => $p['height_cm'],
                'submitted_weight_kg' => $p['weight_kg'],
            );
        }

        $body = array(
            'collection_address' => array(
                'type'           => 'business',
                'company'        => $this->company_name,
                'street_address' => $origin['street_address'],
                'local_area'     => $origin['local_area'],
                'city'           => $origin['city'],
                'zone'           => $origin['zone'],
                'country'        => $origin['country'],
                'code'           => $origin['code'],
            ),
            'delivery_address' => array(
                'type'           => 'residential',
                'street_address' => $destination['street_address'],
                'local_area'     => $destination['local_area'],
                'city'           => $destination['city'],
                'zone'           => $destination['zone'],
                'country'        => $destination['country'],
                'code'           => $destination['code'],
            ),
            'parcels' => $sl_parcels,
        );

        $start_ms = (int) ( microtime( true ) * 1000 );
        $response = wp_remote_post( self::API_URL, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_token,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $body ),
            'timeout' => 5,
        ) );
        $duration_ms = (int) ( microtime( true ) * 1000 ) - $start_ms;
        $dest_code   = $destination['code'] ?? '';

        if ( is_wp_error( $response ) ) {
            ES_Quote_Log::record( 'the-courier-guy', $dest_code, count( $sl_parcels ), $duration_ms, false, $response->get_error_message() );
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            ES_Quote_Log::record( 'the-courier-guy', $dest_code, count( $sl_parcels ), $duration_ms, false, 'HTTP ' . $code );
            return false;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $data ) || ! isset( $data['rates'] ) ) {
            ES_Quote_Log::record( 'the-courier-guy', $dest_code, count( $sl_parcels ), $duration_ms, false, 'Unexpected response shape' );
            return false;
        }
        ES_Quote_Log::record( 'the-courier-guy', $dest_code, count( $sl_parcels ), $duration_ms, true );

        $rates = array();
        foreach ( $data['rates'] as $r ) {
            $sl = $r['service_level'] ?? array();
            $service_code = $sl['code'] ?? '';
            $service_name = $sl['name'] ?? $service_code;
            $description  = $sl['description'] ?? '';

            // "rate" is price INCLUDING VAT.
            $price = floatval( $r['rate'] ?? 0 );
            if ( $price <= 0 ) {
                continue;
            }

            // Calculate delivery days from delivery_date_to.
            $days = 0;
            if ( ! empty( $sl['delivery_date_from'] ) && ! empty( $sl['delivery_date_to'] ) ) {
                $collection = strtotime( $sl['collection_date'] ?? $sl['delivery_date_from'] );
                $delivery   = strtotime( $sl['delivery_date_to'] );
                if ( $collection && $delivery ) {
                    $days = max( 1, intval( ceil( ( $delivery - $collection ) / 86400 ) ) );
                }
            }

            $rates[] = array(
                'carrier'        => $this->get_carrier_name(),
                'service_name'   => $service_name,
                'service_code'   => $service_code,
				'booking_service'=> $service_code,
                'tier'           => $this->map_service_tier( $service_code ),
                'price_incl_vat' => round( $price, 2 ),
                'estimated_days' => $days,
                'description'    => $description,
            );
        }

        return $rates;
    }

    public function map_service_tier( $service_identifier ) {
        $upper = strtoupper( trim( $service_identifier ) );

        // Direct code match.
        if ( isset( self::$tier_map[ $upper ] ) ) {
            return self::$tier_map[ $upper ];
        }

        // Partial code match.
        foreach ( self::$tier_map as $code => $tier ) {
            if ( strpos( $upper, $code ) !== false ) {
                return $tier;
            }
        }

        // Keyword fallback.
        $lower = strtolower( $service_identifier );
        foreach ( self::$tier_keywords as $tier => $keywords ) {
            foreach ( $keywords as $kw ) {
                if ( strpos( $lower, $kw ) !== false ) {
                    return $tier;
                }
            }
        }

        return 'standard';
    }
}
