<?php
defined( 'ABSPATH' ) || exit;

class ES_Carrier_Collivery extends ES_Carrier_Base {

    const API_BASE   = 'https://api.collivery.co.za/v3';
    const TOWN_CACHE = 'es_collivery_town_';

    private $api_token;

    // Actual Collivery service types → tiers (confirmed from live API).
    // service_type 1 = "Same Day" (SDX)   → express
    // service_type 2 = "Next Day" (ONX)    → standard
    // service_type 3 = "Road Freight" (FRT) → economy
    // service_type 5 = "Road Freight Express" (ECO) → economy
    private static $service_tiers = array(
        1 => 'express',   // Same Day
        2 => 'standard',  // Next Day
        3 => 'economy',   // Road Freight (slower, cheapest for heavy)
        5 => 'economy',   // Road Freight Express (faster economy)
    );

    private static $service_days = array(
        1 => 0,  // Same Day
        2 => 1,  // Next Day
        3 => 5,  // Road Freight
        5 => 3,  // Road Freight Express
    );

    public function __construct( $api_token ) {
        $this->api_token = $api_token;
    }

    public function get_carrier_name() {
        return 'MDS Collivery';
    }

    public function is_configured() {
        return ! empty( $this->api_token );
    }

    public function get_rates( $origin, $destination, $parcels ) {
        if ( ! $this->is_configured() ) {
            return false;
        }

        // Resolve town IDs from city names.
        $from_town = $this->resolve_town_id( $origin['city'], $origin['zone'] ?? '' );
        $to_town   = $this->resolve_town_id( $destination['city'], $destination['zone'] ?? '' );

        if ( ! $from_town || ! $to_town ) {
            // Record the early-exit so the diagnostics tab still sees the attempt.
            ES_Quote_Log::record(
                'mds-collivery',
                $destination['code'] ?? '',
                count( $parcels ),
                0,
                false,
                'Town resolution failed: from=' . ( $from_town ? 'ok' : ( $origin['city'] ?? '?' ) ) . ', to=' . ( $to_town ? 'ok' : ( $destination['city'] ?? '?' ) )
            );
            return false;
        }

        // Build parcels array.
        $col_parcels = array();
        foreach ( $parcels as $p ) {
            $col_parcels[] = array(
                'weight' => $p['weight_kg'],
                'length' => $p['length_cm'],
                'width'  => $p['width_cm'],
                'height' => $p['height_cm'],
            );
        }

        // Actual API: POST /v3/quote (singular), fields: collection_town, delivery_town, service, parcels.
        // Service 0 or omitting service returns all available services.
        $body = array(
            'collection_town' => $from_town,
            'delivery_town'   => $to_town,
            'service'         => 0,
            'parcels'         => $col_parcels,
        );

        $url = self::API_BASE . '/quote?api_token=' . urlencode( $this->api_token );

        $start_ms = (int) ( microtime( true ) * 1000 );
        $response = wp_remote_post( $url, array(
            'headers' => $this->get_headers(),
            'body'    => wp_json_encode( $body ),
            'timeout' => 5,
        ) );
        $duration_ms = (int) ( microtime( true ) * 1000 ) - $start_ms;
        $dest_code   = $destination['code'] ?? '';
        $parcel_n    = count( $col_parcels );

        if ( is_wp_error( $response ) ) {
            ES_Quote_Log::record( 'mds-collivery', $dest_code, $parcel_n, $duration_ms, false, $response->get_error_message() );
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            ES_Quote_Log::record( 'mds-collivery', $dest_code, $parcel_n, $duration_ms, false, 'HTTP ' . $code );
            return false;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! isset( $data['data'] ) || ! is_array( $data['data'] ) ) {
            ES_Quote_Log::record( 'mds-collivery', $dest_code, $parcel_n, $duration_ms, false, 'Unexpected response shape' );
            return false;
        }
        ES_Quote_Log::record( 'mds-collivery', $dest_code, $parcel_n, $duration_ms, true );

        $rates = array();
        foreach ( $data['data'] as $quote ) {
            $service_type = intval( $quote['service_type'] ?? 0 );
            $service_name = $quote['service_text'] ?? 'Service ' . $service_type;
            $service_code = $quote['service_code'] ?? '';
            $price        = floatval( $quote['total_inclusive'] ?? 0 );

            if ( $price <= 0 ) {
                continue;
            }

            $rates[] = array(
                'carrier'        => $this->get_carrier_name(),
                'service_name'   => $service_name,
                'service_code'   => $service_code,
                'tier'           => $this->map_service_tier( strval( $service_type ) ),
                'price_incl_vat' => round( $price, 2 ),
                'estimated_days' => self::$service_days[ $service_type ] ?? 2,
            );
        }

        return $rates;
    }

    public function map_service_tier( $service_code ) {
        $id = intval( $service_code );
        return self::$service_tiers[ $id ] ?? 'standard';
    }

    /**
     * Resolve a city name to a Collivery town ID (cached 24h).
     * When province is provided, prefers results matching the province to avoid
     * ambiguous city name collisions (e.g. "Springfield" in multiple provinces).
     */
    private function resolve_town_id( $city_name, $province = '' ) {
        if ( empty( $city_name ) ) {
            return false;
        }

        $cache_key = self::TOWN_CACHE . sanitize_key( $city_name . '_' . $province );
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) {
            return intval( $cached );
        }

        $url = self::API_BASE . '/towns?api_token=' . urlencode( $this->api_token )
             . '&search=' . urlencode( $city_name );

        $response = wp_remote_get( $url, array(
            'headers' => $this->get_headers(),
            'timeout' => 5,
        ) );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! isset( $data['data'] ) || empty( $data['data'] ) ) {
            return false;
        }

        // If province is provided, try to find a result that matches it.
        $town_id = 0;
        if ( ! empty( $province ) ) {
            $province_lower = strtolower( trim( $province ) );
            foreach ( $data['data'] as $town ) {
                $town_province = strtolower( trim( $town['province'] ?? '' ) );
                if ( strpos( $town_province, $province_lower ) !== false || strpos( $province_lower, $town_province ) !== false ) {
                    $town_id = intval( $town['id'] ?? 0 );
                    break;
                }
            }
        }

        // Fall back to first result if no province match.
        if ( $town_id <= 0 ) {
            $town_id = intval( $data['data'][0]['id'] ?? 0 );
        }

        if ( $town_id > 0 ) {
            set_transient( $cache_key, $town_id, DAY_IN_SECONDS );
        }

        return $town_id ?: false;
    }

    private function get_headers() {
        return array(
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
            'X-App-Name'    => 'ERPNextShipping',
            'X-App-Version' => ES_SHIPPING_VERSION,
            'X-App-Host'    => 'WooCommerce',
            'X-App-Lang'    => 'PHP ' . phpversion(),
            'X-App-Url'     => home_url(),
        );
    }
}
