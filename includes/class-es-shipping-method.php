<?php
defined( 'ABSPATH' ) || exit;

class ES_Shipping_Method extends WC_Shipping_Method {

    private static $instance = null;

    public function __construct( $instance_id = 0 ) {
        $this->id                 = 'erpnext_shipping';
        $this->instance_id        = absint( $instance_id );
        $this->method_title       = __( 'ERPNext Multi-Carrier Shipping', 'erpnext-shipping' );
        $this->method_description = __( 'Real-time shipping rates from The Courier Guy and MDS Collivery with ERPNext warehouse routing.', 'erpnext-shipping' );
        $this->supports           = array( 'shipping-zones', 'instance-settings', 'instance-settings-modal' );

        $this->init_form_fields();
        $this->instance_form_fields = $this->form_fields;
        $this->init_settings();

        $this->enabled = $this->get_option( 'enabled', 'yes' );
        $this->title   = $this->get_option( 'title', 'Shipping' );

        add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );

        self::$instance = $this;
    }

    public static function get_instance() {
        return self::$instance;
    }

    public function init_form_fields() {
        $this->form_fields = array(

            // ── General ──
            'enabled' => array(
                'title'   => __( 'Enable/Disable', 'erpnext-shipping' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable ERPNext Multi-Carrier Shipping', 'erpnext-shipping' ),
                'default' => 'yes',
            ),
            'title' => array(
                'title'   => __( 'Method Title', 'erpnext-shipping' ),
                'type'    => 'text',
                'default' => 'Shipping',
            ),
            'company_name' => array(
                'title'       => __( 'Company Name', 'erpnext-shipping' ),
                'type'        => 'text',
                'default'     => '',
                'description' => __( 'Your business name as it should appear on shipping labels and waybills (e.g. "My Store").', 'erpnext-shipping' ),
            ),
            'locations_notice' => array(
                'title' => __( 'Dispatch Locations', 'erpnext-shipping' ),
                'type'  => 'locations_notice',
            ),

            // ── ERPNext ──
            'erp_heading' => array(
                'title' => __( 'ERPNext Stock Sync', 'erpnext-shipping' ),
                'type'  => 'title',
            ),
            'erp_url' => array(
                'title'   => __( 'ERPNext URL', 'erpnext-shipping' ),
                'type'    => 'text',
                'default' => '',
            ),
            'erp_api_key' => array(
                'title' => __( 'API Key', 'erpnext-shipping' ),
                'type'  => 'text',
            ),
            'erp_api_secret' => array(
                'title' => __( 'API Secret', 'erpnext-shipping' ),
                'type'  => 'password',
            ),
            'erp_sync_button' => array(
                'title' => __( 'Stock Sync', 'erpnext-shipping' ),
                'type'  => 'sync_button',
            ),
            // ── The Courier Guy (via Ship Logic) ──
            'tcg_heading' => array(
                'title' => __( 'The Courier Guy', 'erpnext-shipping' ),
                'type'  => 'title',
            ),
            'tcg_enabled' => array(
                'title'   => __( 'Enable', 'erpnext-shipping' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable The Courier Guy rates', 'erpnext-shipping' ),
                'default' => 'yes',
            ),
            'tcg_api_token' => array(
                'title'       => __( 'Ship Logic API Token', 'erpnext-shipping' ),
                'type'        => 'password',
                'description' => __( 'The Courier Guy rates are fetched via the Ship Logic platform. Get your API token at shiplogic.com.', 'erpnext-shipping' ),
            ),

            // ── MDS Collivery ──
            'mds_heading' => array(
                'title' => __( 'MDS Collivery', 'erpnext-shipping' ),
                'type'  => 'title',
            ),
            'mds_enabled' => array(
                'title'   => __( 'Enable', 'erpnext-shipping' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable MDS Collivery rates', 'erpnext-shipping' ),
                'default' => 'no',
            ),
            'mds_api_token' => array(
                'title'       => __( 'API Token', 'erpnext-shipping' ),
                'type'        => 'password',
                'description' => __( 'API token from collivery.co.za', 'erpnext-shipping' ),
            ),

            // ── Pricing ──
            'pricing_heading' => array(
                'title' => __( 'Pricing', 'erpnext-shipping' ),
                'type'  => 'title',
            ),
            'markup_type' => array(
                'title'   => __( 'Markup Type', 'erpnext-shipping' ),
                'type'    => 'select',
                'options' => array(
                    'none'       => __( 'No markup', 'erpnext-shipping' ),
                    'percentage' => __( 'Percentage (%)', 'erpnext-shipping' ),
                    'flat'       => __( 'Flat amount (R)', 'erpnext-shipping' ),
                ),
                'default' => 'none',
            ),
            'markup_value' => array(
                'title'   => __( 'Markup Value', 'erpnext-shipping' ),
                'type'    => 'number',
                'default' => '0',
                'custom_attributes' => array( 'min' => '0', 'step' => '0.01' ),
            ),
            'free_shipping_source' => array(
                'title'       => __( 'Free Shipping Source', 'erpnext-shipping' ),
                'type'        => 'select',
                'options'     => array(
                    'wc_method' => __( 'WooCommerce Free Shipping zone method (recommended for CommerceKit/theme integration)', 'erpnext-shipping' ),
                    'plugin'    => __( 'This plugin (uses threshold below)', 'erpnext-shipping' ),
                ),
                'default'     => 'wc_method',
                'description' => __( 'Where free shipping is configured. "WC method" means you have a Free Shipping method in your WC Shipping Zone. "This plugin" means free shipping is handled by the threshold below.', 'erpnext-shipping' ),
            ),
            'free_shipping_threshold' => array(
                'title'       => __( 'Free Shipping Above (R)', 'erpnext-shipping' ),
                'type'        => 'number',
                'default'     => '0',
                'description' => __( 'Only used when Free Shipping Source is set to "This plugin". Set to 0 to disable.', 'erpnext-shipping' ),
                'custom_attributes' => array( 'min' => '0', 'step' => '1' ),
            ),
            'no_free_shipping_classes' => array(
                'title'       => __( 'No Free Shipping Classes', 'erpnext-shipping' ),
                'type'        => 'text',
                'default'     => '',
                'description' => __( 'Comma-separated shipping class slugs. Orders containing items in these classes will not get free shipping (e.g. "heavy, oversized").', 'erpnext-shipping' ),
            ),
            'fallback_rate' => array(
                'title'       => __( 'Flat Rate Fallback (R)', 'erpnext-shipping' ),
                'type'        => 'number',
                'default'     => '0',
                'description' => __( 'Used when carrier APIs fail.', 'erpnext-shipping' ),
                'custom_attributes' => array( 'min' => '0', 'step' => '1' ),
            ),

            // ── Defaults ──
            'defaults_heading' => array(
                'title' => __( 'Defaults for Missing Data', 'erpnext-shipping' ),
                'type'  => 'title',
            ),
            'default_weight' => array(
                'title'   => __( 'Default Weight (kg)', 'erpnext-shipping' ),
                'type'    => 'number',
                'default' => '0.5',
                'custom_attributes' => array( 'min' => '0.01', 'step' => '0.01' ),
            ),
            'default_length' => array(
                'title'   => __( 'Default Length (cm)', 'erpnext-shipping' ),
                'type'    => 'number',
                'default' => '20',
                'custom_attributes' => array( 'min' => '1', 'step' => '1' ),
            ),
            'default_width' => array(
                'title'   => __( 'Default Width (cm)', 'erpnext-shipping' ),
                'type'    => 'number',
                'default' => '15',
                'custom_attributes' => array( 'min' => '1', 'step' => '1' ),
            ),
            'default_height' => array(
                'title'   => __( 'Default Height (cm)', 'erpnext-shipping' ),
                'type'    => 'number',
                'default' => '10',
                'custom_attributes' => array( 'min' => '1', 'step' => '1' ),
            ),

            // ── Debug ──
            'debug_heading' => array(
                'title' => __( 'Debug', 'erpnext-shipping' ),
                'type'  => 'title',
            ),
            'debug' => array(
                'title'   => __( 'Debug Logging', 'erpnext-shipping' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable debug logging (WooCommerce → Status → Logs)', 'erpnext-shipping' ),
                'default' => 'no',
            ),
        );
    }

    /**
     * Render the locations notice field in WC instance settings modal.
     */
    public function generate_locations_notice_html( $key, $data ) {
        $url = admin_url( 'admin.php?page=es-shipping' );
        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label><?php echo esc_html( $data['title'] ); ?></label>
            </th>
            <td class="forminp">
                <p><?php
                    printf(
                        /* translators: %s: link to admin settings page */
                        esc_html__( 'Manage dispatch locations on the %s page.', 'erpnext-shipping' ),
                        '<a href="' . esc_url( $url ) . '">' . esc_html__( 'ERPNext Shipping settings', 'erpnext-shipping' ) . '</a>'
                    );
                ?></p>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Get all configured locations.
     *
     * @return array Array of location objects from es_shipping_locations option.
     */
    public static function get_locations() {
        return get_option( 'es_shipping_locations', array() );
    }

    /**
     * Get a single location by ID.
     *
     * @param string $location_id The location ID to look up.
     * @return array|null Location object or null if not found.
     */
    public static function get_location( $location_id ) {
        $locations = self::get_locations();
        foreach ( $locations as $loc ) {
            if ( ( $loc['id'] ?? '' ) === $location_id ) {
                return $loc;
            }
        }
        return null;
    }

    /**
     * Get dispatch address array for a location.
     *
     * @param string $location_id The location ID.
     * @return array Address array for carrier APIs.
     */
    public function get_origin( $location_id ) {
        $loc = self::get_location( $location_id );
        if ( ! $loc ) {
            return array(
                'street_address' => '',
                'local_area'     => '',
                'city'           => '',
                'zone'           => '',
                'country'        => 'ZA',
                'code'           => '',
            );
        }
        return array(
            'street_address' => $loc['street'] ?? '',
            'local_area'     => $loc['suburb'] ?? '',
            'city'           => $loc['city'] ?? '',
            'zone'           => $loc['province'] ?? '',
            'country'        => $loc['country'] ?? 'ZA',
            'code'           => $loc['postcode'] ?? '',
        );
    }

    /**
     * Get ERPNext connection settings.
     */
    public function get_erp_settings() {
        return array(
            'url'        => rtrim( $this->get_option( 'erp_url' ), '/' ),
            'api_key'    => $this->get_option( 'erp_api_key' ),
            'api_secret' => $this->get_option( 'erp_api_secret' ),
        );
    }

    /**
     * Get all configured carrier instances.
     */
    public function get_carriers() {
        $carriers = array();

        if ( 'yes' === $this->get_option( 'tcg_enabled' ) && $this->get_option( 'tcg_api_token' ) ) {
            $carriers[] = new ES_Carrier_ShipLogic( $this->get_option( 'tcg_api_token' ), $this->get_option( 'company_name', '' ) );
        }

        if ( 'yes' === $this->get_option( 'mds_enabled' ) && $this->get_option( 'mds_api_token' ) ) {
            $carriers[] = new ES_Carrier_Collivery( $this->get_option( 'mds_api_token' ) );
        }

        return $carriers;
    }

    /**
     * Log a debug message.
     */
    public function log( $message ) {
        if ( 'yes' === $this->get_option( 'debug' ) ) {
            if ( ! isset( $this->logger ) ) {
                $this->logger = wc_get_logger();
            }
            $this->logger->debug( $message, array( 'source' => 'erpnext-shipping' ) );
        }
    }

    /**
     * Render the sync button field in admin settings.
     */
    public function generate_sync_button_html( $key, $data ) {
        $last_sync  = get_option( ES_Stock_Sync::OPTION_LAST_SYNC, 0 );
        $stock_data = get_option( ES_Stock_Sync::OPTION_STOCK, array() );
        $item_count = count( $stock_data );

        if ( $last_sync > 0 ) {
            $ago = human_time_diff( $last_sync, time() ) . ' ago';
            $status = sprintf( '%s (%d items)', $ago, $item_count );
        } else {
            $status = 'Never synced';
        }

        $nonce = wp_create_nonce( 'es_stock_sync' );

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label><?php echo esc_html( $data['title'] ); ?></label>
            </th>
            <td class="forminp">
                <p>Last sync: <strong id="es-sync-status"><?php echo esc_html( $status ); ?></strong></p>
                <button type="button" class="button" id="es-sync-now-btn">Sync Now</button>
                <span id="es-sync-spinner" class="spinner" style="float:none;"></span>
                <p id="es-sync-result" style="margin-top:8px;"></p>
                <script>
                (function(){
                    var btn = document.getElementById('es-sync-now-btn');
                    var spinner = document.getElementById('es-sync-spinner');
                    var result = document.getElementById('es-sync-result');
                    var status = document.getElementById('es-sync-status');
                    btn.addEventListener('click', function(){
                        btn.disabled = true;
                        spinner.classList.add('is-active');
                        result.textContent = '';
                        var data = new FormData();
                        data.append('action', 'es_shipping_sync_stock');
                        data.append('_wpnonce', '<?php echo esc_js( $nonce ); ?>');
                        data.append('instance_id', '<?php echo esc_js( $this->instance_id ); ?>');
                        var url = (typeof ajaxurl !== 'undefined') ? ajaxurl : '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
                        fetch(url, {method:'POST', body:data})
                            .then(function(r){return r.json()})
                            .then(function(r){
                                spinner.classList.remove('is-active');
                                btn.disabled = false;
                                if(r.success){
                                    status.textContent = 'Just now (' + r.data.count + ' items)';
                                    result.innerHTML = '<span style="color:green">&#10003; ' + r.data.message + '</span>';
                                } else {
                                    result.innerHTML = '<span style="color:red">&#10007; ' + (r.data||'Sync failed') + '</span>';
                                }
                            })
                            .catch(function(e){
                                spinner.classList.remove('is-active');
                                btn.disabled = false;
                                result.innerHTML = '<span style="color:red">&#10007; Network error</span>';
                            });
                    });
                })();
                </script>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Calculate shipping rates.
     */
    public function calculate_shipping( $package = array() ) {
        $this->log( 'calculate_shipping() called — instance_id=' . $this->instance_id . ', destination=' . wp_json_encode( $package['destination'] ?? array() ) );
        try {
            $this->do_calculate_shipping( $package );
        } catch ( \Throwable $e ) {
            $this->log( 'FATAL ERROR in calculate_shipping: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
            $this->add_fallback_rate();
        }
        $this->log( 'calculate_shipping() completed.' );
    }

    /**
     * Internal: actual shipping calculation wrapped by calculate_shipping().
     */
    private function do_calculate_shipping( $package ) {
        $start_time = microtime( true );

        // 1. Free shipping check (only when source is "plugin", not "wc_method").
        $free_source = $this->get_option( 'free_shipping_source', 'wc_method' );
        $threshold   = floatval( $this->get_option( 'free_shipping_threshold', 0 ) );
        $cart_total  = 0;
        if ( WC()->cart ) {
            $cart_total = WC()->cart->get_subtotal();
        }
        if ( 'plugin' === $free_source && $threshold > 0 && $cart_total >= $threshold ) {
            $this->add_rate( array(
                'id'    => $this->id . '_free',
                'label' => __( 'Free Shipping', 'erpnext-shipping' ),
                'cost'  => 0,
            ) );
            $this->log( 'Free shipping (plugin): cart R' . $cart_total . ' >= threshold R' . $threshold . ' — continuing to fetch carrier rates' );
            // Don't return — still fetch carrier rates so the woocommerce_package_rates
            // filter can offer premium options alongside free shipping.
        }

        // 2. Build destination address from the package.
        $destination = $this->build_destination( $package );
        if ( empty( $destination['code'] ) && empty( $destination['city'] ) ) {
            $this->log( 'No destination address yet, skipping rate calculation.' );
            return;
        }

        // 3. Get fulfillment plan from ERPNext stock (non-blocking — uses cached data only, never syncs during checkout).
        $cart_items = $this->get_cart_items( $package );
        $sync = new ES_Stock_Sync( $this->get_erp_settings() );

        // 3a. Check if SLW per-item location selections override automatic routing.
        $plan = null;
        if ( taxonomy_exists( 'location' ) ) {
            $term_map = ES_Stock_Sync::get_location_term_map();
            // Invert: term_id => location_id
            $term_to_loc = array_flip( $term_map );

            $items_by_location = array();
            $has_slw           = true;
            $i                 = 0;

            foreach ( $package['contents'] as $cart_item ) {
                $term_id = isset( $cart_item['stock_location'] ) ? intval( $cart_item['stock_location'] ) : 0;
                if ( $term_id > 0 && isset( $term_to_loc[ $term_id ] ) ) {
                    $loc_id = $term_to_loc[ $term_id ];
                    $items_by_location[ $loc_id ][] = $cart_items[ $i ];
                } else {
                    $has_slw = false;
                }
                $i++;
            }

            if ( $has_slw && ! empty( $items_by_location ) ) {
                $loc_ids = array_keys( $items_by_location );
                if ( count( $loc_ids ) === 1 ) {
                    $plan = array(
                        'type'     => 'single',
                        'location' => $loc_ids[0],
                        'items'    => $items_by_location[ $loc_ids[0] ],
                    );
                } else {
                    $plan = array(
                        'type'      => 'split',
                        'shipments' => $items_by_location,
                    );
                }
                $this->log( 'SLW location override: ' . wp_json_encode( $plan ) );
            }
        }

        // 3b. Fall back to automatic stock-based routing if no SLW override.
        if ( ! $plan ) {
            $plan = $sync->get_fulfillment_plan_fast( $cart_items );
        }
        $this->log( 'Fulfillment plan: ' . wp_json_encode( $plan ) );

        // 4. Get carriers.
        $carriers = $this->get_carriers();
        if ( empty( $carriers ) ) {
            $this->log( 'No carriers configured, adding fallback rate.' );
            $this->add_fallback_rate();
            return;
        }

        // 5. Build parcels and get rates per dispatch location.
        $estimator = new ES_Parcel_Estimator(
            floatval( $this->get_option( 'default_weight', 0.5 ) ),
            floatval( $this->get_option( 'default_length', 20 ) ),
            floatval( $this->get_option( 'default_width', 15 ) ),
            floatval( $this->get_option( 'default_height', 10 ) )
        );
        $cache = new ES_Rate_Cache();

        $is_split      = ( $plan['type'] === 'split' );
        $is_chooseable = ( $plan['type'] === 'chooseable' );

        // Build list of { location, items } to quote.
        $locations_to_quote = array();
        if ( $plan['type'] === 'single' ) {
            $locations_to_quote[] = array(
                'location' => $plan['location'],
                'items'    => $plan['items'],
            );
        } elseif ( $is_chooseable ) {
            // Multiple locations can fulfill — quote all with full cart, pick cheapest later.
            foreach ( $plan['locations'] as $loc_id ) {
                $locations_to_quote[] = array(
                    'location' => $loc_id,
                    'items'    => $plan['items'],
                );
            }
        } else {
            // Split: quote each location for its items.
            foreach ( $plan['shipments'] as $loc_id => $items ) {
                $locations_to_quote[] = array(
                    'location' => $loc_id,
                    'items'    => $items,
                );
            }
        }

        $location_rates = array(); // location_id => array of rate objects
        $time_budget    = 20; // seconds — stay well under PHP's 30s limit.

        foreach ( $locations_to_quote as $lq ) {
            $elapsed = microtime( true ) - $start_time;
            if ( $elapsed > $time_budget ) {
                $this->log( 'Time budget exceeded (' . round( $elapsed, 1 ) . 's), using rates collected so far.' );
                break;
            }

            $origin  = $this->get_origin( $lq['location'] );
            $parcels = $estimator->estimate( $lq['items'] );
            $this->log( 'Location ' . $lq['location'] . ' parcels: ' . wp_json_encode( $parcels ) );

            // Check cache.
            $cache_key = $cache->build_key( $origin, $destination, $parcels );
            $cached = $cache->get( $cache_key );
            if ( $cached !== false ) {
                $this->log( 'Cache hit for ' . $lq['location'] );
                $location_rates[ $lq['location'] ] = $cached;
                continue;
            }

            // Query carriers.
            $rates = array();
            foreach ( $carriers as $carrier ) {
                $elapsed = microtime( true ) - $start_time;
                if ( $elapsed > $time_budget ) {
                    $this->log( 'Time budget exceeded before ' . $carrier->get_carrier_name() . ', skipping.' );
                    break;
                }

                try {
                    $carrier_rates = $carrier->get_rates( $origin, $destination, $parcels );
                    if ( is_array( $carrier_rates ) ) {
                        $rates = array_merge( $rates, $carrier_rates );
                        $this->log( $carrier->get_carrier_name() . ' returned ' . count( $carrier_rates ) . ' rates for ' . $lq['location'] . ' in ' . round( microtime( true ) - $start_time, 1 ) . 's' );
                    } else {
                        $this->log( $carrier->get_carrier_name() . ' returned no rates for ' . $lq['location'] );
                    }
                } catch ( \Throwable $e ) {
                    $this->log( $carrier->get_carrier_name() . ' error: ' . $e->getMessage() );
                }
            }

            $cache->set( $cache_key, $rates );
            $location_rates[ $lq['location'] ] = $rates;
        }

        // 6. Combine rates.
        $all_rates = array();
        if ( $is_split ) {
            $all_rates = $this->combine_split_rates( $location_rates );
        } elseif ( $is_chooseable ) {
            $all_rates = $this->pick_cheapest_location_rates( $location_rates );
        } else {
            $loc = $plan['location'];
            $all_rates = $location_rates[ $loc ] ?? array();
        }

        // 7. Group by tier, pick cheapest per tier.
        $tiered = $this->group_by_tier( $all_rates );

        // 8. Apply markup and add rates.
        if ( empty( $tiered ) ) {
            $this->log( 'No rates from carriers, adding fallback.' );
            $this->add_fallback_rate();
            return;
        }

        $tier_labels = array(
            'economy'  => __( 'Economy Shipping (2-5 days)', 'erpnext-shipping' ),
            'standard' => __( 'Standard Shipping (1-2 days)', 'erpnext-shipping' ),
            'express'  => __( 'Express Shipping (overnight)', 'erpnext-shipping' ),
        );

        $split_count = $is_split ? count( $plan['shipments'] ) : 0;

        foreach ( $tiered as $tier => $rate ) {
            $cost  = $this->apply_markup( $rate['price_incl_vat'] );
            $label = $tier_labels[ $tier ] ?? ucfirst( $tier ) . ' Shipping';
            if ( $is_split ) {
                /* translators: %d: number of dispatch locations */
                $label .= ' ' . sprintf( _n( '(ships from %d location)', '(ships from %d locations)', $split_count, 'erpnext-shipping' ), $split_count );
            }

            $this->add_rate( array(
                'id'        => $this->id . '_' . $tier,
                'label'     => $label,
                'cost'      => $cost,
                'meta_data' => array(
                    'Carrier' => $rate['carrier'],
                    'Service' => $rate['service_name'] ?? '',
                ),
            ) );
            $this->log( 'Rate added: ' . $label . ' R' . $cost . ' (' . $rate['carrier'] . ')' );
        }
    }

    /**
     * Build destination address from WooCommerce package.
     */
    private function build_destination( $package ) {
        $dest = $package['destination'] ?? array();
        return array(
            'street_address' => trim( ( $dest['address'] ?? '' ) . ' ' . ( $dest['address_2'] ?? '' ) ),
            'local_area'     => $dest['address_2'] ?? '',
            'city'           => $dest['city'] ?? '',
            'zone'           => $dest['state'] ?? '',
            'country'        => $dest['country'] ?? 'ZA',
            'code'           => $dest['postcode'] ?? '',
        );
    }

    /**
     * Extract cart items with SKU, qty, weight, dimensions from the package.
     */
    private function get_cart_items( $package ) {
        $items = array();
        foreach ( $package['contents'] as $item ) {
            $product = $item['data'];
            $items[] = array(
                'sku'      => $product->get_sku(),
                'qty'      => $item['quantity'],
                'weight'   => floatval( $product->get_weight() ),
                'length'   => floatval( $product->get_length() ),
                'width'    => floatval( $product->get_width() ),
                'height'   => floatval( $product->get_height() ),
                'name'     => $product->get_name(),
            );
        }
        return $items;
    }

    /**
     * When multiple locations can fulfill: merge all rates, let group_by_tier pick cheapest.
     */
    private function pick_cheapest_location_rates( $location_rates ) {
        $merged = array();
        foreach ( $location_rates as $rates ) {
            $merged = array_merge( $merged, $rates );
        }
        return $merged;
    }

    /**
     * For split shipments: combine rates from N locations by tier.
     * Sum prices across locations for matching tiers.
     * Only show tiers available from ALL locations.
     */
    private function combine_split_rates( $location_rates ) {
        // Group each location's rates by tier.
        $tiered_per_location = array();
        foreach ( $location_rates as $loc_id => $rates ) {
            $tiered_per_location[ $loc_id ] = $this->group_by_tier( $rates );
        }

        // Find tiers available from ALL locations.
        $all_tier_sets = array_map( 'array_keys', $tiered_per_location );
        if ( empty( $all_tier_sets ) ) {
            return array();
        }
        $common_tiers = array_shift( $all_tier_sets );
        foreach ( $all_tier_sets as $tier_set ) {
            $common_tiers = array_intersect( $common_tiers, $tier_set );
        }

        $combined = array();
        foreach ( $common_tiers as $tier ) {
            $total_price = 0;
            $max_days    = 0;
            foreach ( $tiered_per_location as $tiered ) {
                $total_price += $tiered[ $tier ]['price_incl_vat'];
                $max_days     = max( $max_days, $tiered[ $tier ]['estimated_days'] );
            }
            $combined[] = array(
                'carrier'        => 'Multiple carriers',
                'service_name'   => $tier,
                'tier'           => $tier,
                'price_incl_vat' => $total_price,
                'estimated_days' => $max_days,
            );
        }

        return $combined;
    }

    /**
     * Group rates by tier and pick cheapest per tier.
     */
    private function group_by_tier( $rates ) {
        $tiers = array();
        foreach ( $rates as $rate ) {
            $tier = $rate['tier'] ?? 'standard';
            if ( ! isset( $tiers[ $tier ] ) || $rate['price_incl_vat'] < $tiers[ $tier ]['price_incl_vat'] ) {
                $tiers[ $tier ] = $rate;
            }
        }
        return $tiers;
    }

    /**
     * Apply markup to a rate.
     */
    private function apply_markup( $cost ) {
        $type  = $this->get_option( 'markup_type', 'none' );
        $value = floatval( $this->get_option( 'markup_value', 0 ) );

        if ( $type === 'percentage' && $value > 0 ) {
            $cost = $cost * ( 1 + $value / 100 );
        } elseif ( $type === 'flat' && $value > 0 ) {
            $cost = $cost + $value;
        }

        // Round up to nearest R5 for cleaner pricing and a small margin.
        return ceil( $cost / 5 ) * 5;
    }

    /**
     * Add the flat rate fallback.
     */
    private function add_fallback_rate() {
        $fallback = floatval( $this->get_option( 'fallback_rate', 0 ) );
        if ( $fallback <= 0 ) {
            return;
        }
        $this->add_rate( array(
            'id'    => $this->id . '_fallback',
            'label' => __( 'Flat Rate Shipping', 'erpnext-shipping' ),
            'cost'  => $fallback,
        ) );
    }
}
