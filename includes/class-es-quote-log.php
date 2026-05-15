<?php
defined( 'ABSPATH' ) || exit;

/**
 * Fixed-size circular buffer of recent carrier rate quotes for the
 * Diagnostics tab. Stored in a single WP option to avoid table bloat.
 */
class ES_Quote_Log {

    const OPTION  = 'es_recent_quotes';
    const MAX_LEN = 50;

    /**
     * Record one quote attempt.
     *
     * @param string      $carrier_slug e.g. 'the-courier-guy', 'mds-collivery'.
     * @param string      $dest_postcode Destination postcode (truncated for privacy on display).
     * @param int         $parcel_count  Number of parcels in the request.
     * @param int         $duration_ms   Request duration in milliseconds.
     * @param bool        $success
     * @param string|null $error         One-line error message on failure.
     */
    public static function record( $carrier_slug, $dest_postcode, $parcel_count, $duration_ms, $success, $error = null ) {
        $entry = array(
            'ts'       => time(),
            'carrier'  => $carrier_slug,
            'dest'     => self::truncate_postcode( $dest_postcode ),
            'parcels'  => intval( $parcel_count ),
            'ms'       => intval( $duration_ms ),
            'success'  => (bool) $success,
            'error'    => $error ? substr( $error, 0, 200 ) : null,
        );

        $log = get_option( self::OPTION, array() );
        if ( ! is_array( $log ) ) {
            $log = array();
        }
        $log[] = $entry;
        if ( count( $log ) > self::MAX_LEN ) {
            $log = array_slice( $log, -self::MAX_LEN );
        }
        update_option( self::OPTION, $log, false );
    }

    public static function recent( $limit = 20, $carrier = null ) {
        $log = get_option( self::OPTION, array() );
        if ( ! is_array( $log ) ) {
            return array();
        }
        if ( $carrier ) {
            $log = array_values( array_filter( $log, function ( $e ) use ( $carrier ) {
                return ( $e['carrier'] ?? '' ) === $carrier;
            } ) );
        }
        return array_slice( array_reverse( $log ), 0, $limit );
    }

    /**
     * Latest successful quote for one carrier — used in the Connectivity panel.
     */
    public static function last_success( $carrier_slug ) {
        $log = get_option( self::OPTION, array() );
        if ( ! is_array( $log ) ) {
            return null;
        }
        foreach ( array_reverse( $log ) as $entry ) {
            if ( ( $entry['carrier'] ?? '' ) === $carrier_slug && ! empty( $entry['success'] ) ) {
                return $entry;
            }
        }
        return null;
    }

    public static function clear() {
        delete_option( self::OPTION );
    }

    /**
     * Mask a postcode for privacy in admin display. ZA postcodes are 4 digits;
     * we keep the first two and asterisk the rest.
     */
    private static function truncate_postcode( $postcode ) {
        $pc = preg_replace( '/[^A-Za-z0-9]/', '', (string) $postcode );
        if ( strlen( $pc ) <= 2 ) {
            return $pc;
        }
        return substr( $pc, 0, 2 ) . str_repeat( '*', strlen( $pc ) - 2 );
    }
}
