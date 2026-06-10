<?php
defined( 'ABSPATH' ) || exit;

class ES_Parcel_Estimator {

    const MAX_PARCEL_WEIGHT = 30; // kg — carrier limit.

    private $default_weight;
    private $default_length;
    private $default_width;
    private $default_height;

    public function __construct( $default_weight = 0.5, $default_length = 20, $default_width = 15, $default_height = 10 ) {
        $this->default_weight = $default_weight;
        $this->default_length = $default_length;
        $this->default_width  = $default_width;
        $this->default_height = $default_height;
    }

    /**
     * Estimate parcels from cart items.
     *
     * @param array $items Array of { sku, qty, weight, length, width, height, name }.
     * @return array Array of parcel objects { weight_kg, length_cm, width_cm, height_cm }.
     */
    public function estimate( $items ) {
        $total_weight = 0;
        $max_length   = 0;
        $max_width    = 0;
        $max_height   = 0;

        foreach ( $items as $item ) {
            // Quantities can be fractional (per-kg products sold in 0.01 steps) —
            // intval() here previously billed 0.25 kg as a full unit and 2.9 as 2.
            $qty    = max( 0.01, floatval( $item['qty'] ) );
            $weight = ( $item['weight'] > 0 ) ? $item['weight'] : $this->default_weight;
            $length = ( $item['length'] > 0 ) ? $item['length'] : $this->default_length;
            $width  = ( $item['width'] > 0 )  ? $item['width']  : $this->default_width;
            $height = ( $item['height'] > 0 ) ? $item['height'] : $this->default_height;

            $total_weight += $weight * $qty;

            // Track the largest single item dimensions.
            $max_length = max( $max_length, $length );
            $max_width  = max( $max_width, $width );
            $max_height = max( $max_height, $height );
        }

        // Ensure minimums.
        $total_weight = max( 0.1, $total_weight );
        $max_length   = max( 1, $max_length );
        $max_width    = max( 1, $max_width );
        $max_height   = max( 1, $max_height );

        // Split into parcels if over max weight.
        if ( $total_weight <= self::MAX_PARCEL_WEIGHT ) {
            return array(
                array(
                    'weight_kg' => round( $total_weight, 2 ),
                    'length_cm' => round( $max_length, 1 ),
                    'width_cm'  => round( $max_width, 1 ),
                    'height_cm' => round( $max_height, 1 ),
                ),
            );
        }

        // Multiple parcels: split weight evenly, each with the same dimensions.
        $num_parcels   = intval( ceil( $total_weight / self::MAX_PARCEL_WEIGHT ) );
        $weight_each   = round( $total_weight / $num_parcels, 2 );
        $parcels       = array();

        for ( $i = 0; $i < $num_parcels; $i++ ) {
            $parcels[] = array(
                'weight_kg' => $weight_each,
                'length_cm' => round( $max_length, 1 ),
                'width_cm'  => round( $max_width, 1 ),
                'height_cm' => round( $max_height, 1 ),
            );
        }

        return $parcels;
    }
}
