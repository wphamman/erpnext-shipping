<?php
defined( 'ABSPATH' ) || exit;

abstract class ES_Carrier_Base {

    /**
     * Get the display name for this carrier.
     */
    abstract public function get_carrier_name();

    /**
     * Check if carrier is configured (has required credentials).
     */
    abstract public function is_configured();

    /**
     * Fetch rates from the carrier API.
     *
     * @param array $origin      Address array { street_address, local_area, city, zone, country, code }.
     * @param array $destination Address array (same format).
     * @param array $parcels     Array of { weight_kg, length_cm, width_cm, height_cm }.
     * @return array|false Array of rate objects or false on failure.
     *   Rate object: { carrier, service_name, service_code, tier, price_incl_vat, estimated_days }
     */
    abstract public function get_rates( $origin, $destination, $parcels );

    /**
     * Map a carrier-specific service code/name to a standard tier.
     *
     * @param string $service_code The carrier's service code.
     * @return string One of 'economy', 'standard', 'express'.
     */
    abstract public function map_service_tier( $service_code );
}
