<?php
defined( 'ABSPATH' ) || exit;

class ES_Stock_Sync {

    const OPTION_STOCK      = 'es_shipping_warehouse_stock';
    const OPTION_LAST_SYNC  = 'es_shipping_stock_last_sync';
    const STALE_THRESHOLD   = 1800; // 30 minutes

    private $erp_url;
    private $erp_key;
    private $erp_secret;

    public function __construct( $settings ) {
        $this->erp_url    = rtrim( $settings['url'] ?? '', '/' );
        $this->erp_key    = $settings['api_key'] ?? '';
        $this->erp_secret = $settings['api_secret'] ?? '';
    }

    /**
     * Build warehouse name → location_id map from configured locations.
     *
     * @return array { 'Warehouse Name - Company' => 'location-id', ... }
     */
    public static function build_warehouse_map() {
        $locations = get_option( 'es_shipping_locations', array() );
        $map = array();
        foreach ( $locations as $loc ) {
            $loc_id = $loc['id'] ?? '';
            if ( empty( $loc_id ) ) {
                continue;
            }
            foreach ( ( $loc['erp_warehouses'] ?? array() ) as $wh ) {
                $wh = trim( $wh );
                if ( ! empty( $wh ) ) {
                    $map[ $wh ] = $loc_id;
                }
            }
        }
        return $map;
    }

    /**
     * Get all configured location IDs.
     *
     * @return array Array of location ID strings.
     */
    public static function get_location_ids() {
        $locations = get_option( 'es_shipping_locations', array() );
        // Exclude collection points — they don't have stock or ship orders.
        $warehouse_locations = array_filter( $locations, function( $loc ) {
            return ( $loc['type'] ?? 'warehouse' ) === 'warehouse';
        } );
        return array_filter( array_column( $warehouse_locations, 'id' ) );
    }

    /**
     * Pull Bin data from ERPNext and store in WordPress options.
     */
    /** @var string Last error message (available after sync() returns false). */
    public $last_error = '';

    public function sync() {
        if ( empty( $this->erp_url ) || empty( $this->erp_key ) ) {
            $this->last_error = 'ERPNext URL or API Key is empty.';
            return false;
        }

        $warehouse_map = self::build_warehouse_map();
        $warehouses    = array_keys( $warehouse_map );
        if ( empty( $warehouses ) ) {
            $this->last_error = 'No ERPNext warehouses configured. Add warehouses to your dispatch locations first.';
            return false;
        }

        $location_ids = array_unique( array_values( $warehouse_map ) );

        $filters = wp_json_encode( array(
            array( 'warehouse', 'in', $warehouses ),
            array( 'actual_qty', '>', 0 ),
        ) );
        $fields = wp_json_encode( array( 'item_code', 'warehouse', 'actual_qty' ) );

        $url = $this->erp_url . '/api/resource/Bin?'
            . 'fields=' . urlencode( $fields )
            . '&filters=' . urlencode( $filters )
            . '&limit_page_length=0';

        $response = wp_remote_get( $url, array(
            'headers' => array(
                'Authorization' => 'token ' . $this->erp_key . ':' . $this->erp_secret,
            ),
            'timeout' => 30,
        ) );

        if ( is_wp_error( $response ) ) {
            $this->last_error = 'HTTP error: ' . $response->get_error_message();
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code >= 400 ) {
            $msg = $body['message'] ?? $body['exc'] ?? wp_remote_retrieve_body( $response );
            $this->last_error = 'ERPNext returned HTTP ' . $code . ': ' . substr( $msg, 0, 200 );
            return false;
        }

        if ( ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
            $this->last_error = 'Unexpected response from ERPNext (no "data" key).';
            return false;
        }

        // Build empty stock template from configured locations.
        $empty_stock = array();
        foreach ( $location_ids as $loc_id ) {
            $empty_stock[ $loc_id ] = 0;
        }

        // Build stock map: item_code => { location_id: qty, ... }.
        $stock = array();
        foreach ( $body['data'] as $bin ) {
            $item      = $bin['item_code'];
            $warehouse = $bin['warehouse'];
            $qty       = floatval( $bin['actual_qty'] );
            $loc_id    = $warehouse_map[ $warehouse ] ?? null;

            if ( ! $loc_id ) {
                continue;
            }

            if ( ! isset( $stock[ $item ] ) ) {
                $stock[ $item ] = $empty_stock;
            }
            $stock[ $item ][ $loc_id ] += $qty;
        }

        // Get previous stock before overwriting — needed to zero out depleted items in SLW.
        $prev_stock = get_option( self::OPTION_STOCK, array() );

        update_option( self::OPTION_STOCK, $stock, false );
        update_option( self::OPTION_LAST_SYNC, time(), false );

        // Write per-location stock to SLW product meta (if SLW is installed).
        $this->sync_to_slw( $stock, $prev_stock );

        return count( $stock );
    }

    /**
     * Get stock for an item across all locations.
     *
     * @return array { location_id: qty, ... } or empty array.
     */
    public function get_stock( $item_code ) {
        $stock = get_option( self::OPTION_STOCK, array() );
        return $stock[ $item_code ] ?? array();
    }

    /**
     * Check if stock data is stale (older than 30 min).
     */
    public function is_stale() {
        $last = get_option( self::OPTION_LAST_SYNC, 0 );
        return ( time() - $last ) > self::STALE_THRESHOLD;
    }

    /**
     * Location ID → SLW term_id mapping.
     * Reads from es_shipping_locations option.
     *
     * @return array { 'location-id' => term_id, ... } (only locations with a term_id set)
     */
    public static function get_location_term_map() {
        $locations = get_option( 'es_shipping_locations', array() );
        $map = array();
        foreach ( $locations as $loc ) {
            $loc_id  = $loc['id'] ?? '';
            $term_id = intval( $loc['slw_term_id'] ?? 0 );
            if ( ! empty( $loc_id ) && $term_id > 0 ) {
                $map[ $loc_id ] = $term_id;
            }
        }
        return $map;
    }

    /**
     * Write per-location stock to SLW product meta.
     * Also zeroes out SLW meta for items that dropped to zero stock since the last sync.
     * Skips gracefully if SLW plugin is not installed.
     */
    private function sync_to_slw( $stock, $prev_stock = array() ) {
        if ( ! taxonomy_exists( 'location' ) ) {
            return;
        }

        $map = self::get_location_term_map();
        if ( empty( $map ) ) {
            return;
        }

        $term_ids = array_values( $map );
        $updated  = 0;

        // Update items that have stock.
        foreach ( $stock as $item_code => $location_qtys ) {
            $product_id = wc_get_product_id_by_sku( $item_code );
            if ( ! $product_id ) {
                continue;
            }

            // Assign location terms to this product.
            wp_set_object_terms( $product_id, $term_ids, 'location', false );

            // Write per-location stock quantities.
            foreach ( $map as $loc_id => $term_id ) {
                $qty = intval( $location_qtys[ $loc_id ] ?? 0 );
                update_post_meta( $product_id, '_stock_at_' . $term_id, $qty );
            }

            $updated++;
        }

        // Zero out items that had stock in the previous sync but are now depleted
        // (not present in $stock because ERPNext filter is actual_qty > 0).
        $depleted = array_diff_key( $prev_stock, $stock );
        foreach ( $depleted as $item_code => $old_qtys ) {
            $product_id = wc_get_product_id_by_sku( $item_code );
            if ( ! $product_id ) {
                continue;
            }

            // Zero out all SLW location meta.
            foreach ( $map as $loc_id => $term_id ) {
                update_post_meta( $product_id, '_stock_at_' . $term_id, 0 );
            }

            // Update WC main stock to 0 and mark as out of stock.
            $product = wc_get_product( $product_id );
            if ( $product && $product->managing_stock() ) {
                wc_update_product_stock( $product_id, 0 );
                $product->set_stock_status( 'outofstock' );
                $product->save();
            }

            $updated++;
        }

        return $updated;
    }

    /**
     * Ensure stock data is reasonably fresh. Sync if stale.
     */
    private function ensure_fresh() {
        if ( $this->is_stale() ) {
            $this->sync();
        }
    }

    /**
     * Fast fulfillment plan — uses cached stock only, never calls ERPNext.
     * If no stock data or locations exist, defaults to the first configured location.
     */
    public function get_fulfillment_plan_fast( $cart_items ) {
        return $this->get_fulfillment_plan_internal( $cart_items, false );
    }

    /**
     * Determine the fulfillment plan for a set of cart items.
     *
     * @param array $cart_items Array of { sku, qty, weight, length, width, height, name }.
     * @return array Fulfillment plan.
     */
    public function get_fulfillment_plan( $cart_items ) {
        return $this->get_fulfillment_plan_internal( $cart_items, true );
    }

    private function get_fulfillment_plan_internal( $cart_items, $allow_sync = true ) {
        if ( $allow_sync ) {
            $this->ensure_fresh();
        }

        $stock        = get_option( self::OPTION_STOCK, array() );
        $location_ids = self::get_location_ids();

        // Fallback: if no locations configured, return single with empty location.
        if ( empty( $location_ids ) ) {
            return array(
                'type'     => 'single',
                'location' => '',
                'items'    => $cart_items,
            );
        }

        // Check which locations can fully fulfill all items.
        $can_fulfill = array();
        foreach ( $location_ids as $loc_id ) {
            $can = true;
            foreach ( $cart_items as $item ) {
                $s   = $stock[ $item['sku'] ] ?? array();
                $qty = floatval( $s[ $loc_id ] ?? 0 );
                if ( $qty < $item['qty'] ) {
                    $can = false;
                    break;
                }
            }
            if ( $can ) {
                $can_fulfill[] = $loc_id;
            }
        }

        // Multiple locations can fulfill — let caller quote all and pick cheapest.
        if ( count( $can_fulfill ) > 1 ) {
            return array(
                'type'      => 'chooseable',
                'locations' => $can_fulfill,
                'items'     => $cart_items,
            );
        }

        // Exactly one location can fulfill.
        if ( count( $can_fulfill ) === 1 ) {
            return array(
                'type'     => 'single',
                'location' => $can_fulfill[0],
                'items'    => $cart_items,
            );
        }

        // No location can fully fulfill — split across locations.
        // Strategy: assign each item to the location with sufficient stock.
        // If multiple locations have it, prefer the one that already has the most items assigned.
        $shipments = array(); // location_id => array of items

        foreach ( $cart_items as $item ) {
            $sku       = $item['sku'];
            $s         = $stock[ $sku ] ?? array();
            $candidates = array();

            // Find all locations that can fulfill this item.
            foreach ( $location_ids as $loc_id ) {
                if ( floatval( $s[ $loc_id ] ?? 0 ) >= $item['qty'] ) {
                    $candidates[] = $loc_id;
                }
            }

            if ( count( $candidates ) === 1 ) {
                // Only one location has it.
                $shipments[ $candidates[0] ][] = $item;
            } elseif ( count( $candidates ) > 1 ) {
                // Multiple have it — prefer the one already holding the most items.
                $best     = $candidates[0];
                $best_cnt = count( $shipments[ $best ] ?? array() );
                foreach ( $candidates as $c ) {
                    $cnt = count( $shipments[ $c ] ?? array() );
                    if ( $cnt > $best_cnt ) {
                        $best     = $c;
                        $best_cnt = $cnt;
                    }
                }
                $shipments[ $best ][] = $item;
            } else {
                // No location has enough — pick the one with the most stock for this item.
                $best     = $location_ids[0];
                $best_qty = 0;
                foreach ( $location_ids as $loc_id ) {
                    $q = floatval( $s[ $loc_id ] ?? 0 );
                    if ( $q > $best_qty ) {
                        $best     = $loc_id;
                        $best_qty = $q;
                    }
                }
                $shipments[ $best ][] = $item;
            }
        }

        // Remove empty entries.
        $shipments = array_filter( $shipments );

        // If everything ended up in one location, it's a single shipment.
        if ( count( $shipments ) === 1 ) {
            $loc_id = array_key_first( $shipments );
            return array(
                'type'     => 'single',
                'location' => $loc_id,
                'items'    => $shipments[ $loc_id ],
            );
        }

        return array(
            'type'      => 'split',
            'shipments' => $shipments,
        );
    }
}
