<?php
defined( 'ABSPATH' ) || exit;

class ES_Rate_Cache {

    const TTL = 900; // 15 minutes.

    /**
     * Build a cache key from origin, destination, and parcel data.
     */
    public function build_key( $location_id, $origin_postcode, $dest_postcode, $parcels ) {
        $parcel_hash = md5( wp_json_encode( $parcels ) );
        return 'es_ship_' . md5( $location_id . '_' . $origin_postcode . '_' . $dest_postcode . '_' . $parcel_hash );
    }

    /**
     * Get cached rates.
     *
     * @return array|false Cached rates or false if miss.
     */
    public function get( $key ) {
        $cached = get_transient( $key );
        return ( $cached !== false ) ? $cached : false;
    }

    /**
     * Store rates in cache.
     */
    public function set( $key, $rates ) {
        set_transient( $key, $rates, self::TTL );
    }
}
