<?php
defined( 'ABSPATH' ) || exit;

class ES_Rate_Cache {

    const TTL = 900; // 15 minutes.

    /**
     * Build a cache key from origin, destination, and parcel data.
     *
     * Includes both city and postcode to prevent cache collisions when different
     * cities share the same postcode.
     */
    public function build_key( $origin, $destination, $parcels ) {
        // Include both postcode AND city to prevent cache collisions
        $origin_key = ( $origin['code'] ?? '' ) . '|' . ( $origin['city'] ?? '' );
        $dest_key   = ( $destination['code'] ?? '' ) . '|' . ( $destination['city'] ?? '' );
        $parcel_hash = md5( wp_json_encode( $parcels ) );
		// v3 also stores the delivery tier used to constrain an operator carrier
		// override. Do not reuse a cached quote without the complete snapshot.
        return 'es_ship_v3_' . md5( $origin_key . '_' . $dest_key . '_' . $parcel_hash );
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
