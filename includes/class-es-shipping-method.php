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
                'type'  => 'password',
            ),
            'erp_api_secret' => array(
                'title' => __( 'API Secret', 'erpnext-shipping' ),
                'type'  => 'password',
            ),
            'erp_so_prefix' => array(
                'title'       => __( 'ERP Sales Order prefix', 'erpnext-shipping' ),
                'type'        => 'text',
                'default'     => 'WEB1-',
                'description' => __( 'Naming-series prefix woocommerce_fusion uses for Sales Orders created from this site (differs per WooCommerce Server, e.g. WEB1-, WEB3-). Used for ERPNext links and Force ERPNext Sync.', 'erpnext-shipping' ),
            ),
            'erp_sync_button' => array(
                'title' => __( 'Stock Sync', 'erpnext-shipping' ),
                'type'  => 'sync_button',
            ),
            // ── The Courier Guy ──
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
                'title'       => __( 'The Courier Guy API Token', 'erpnext-shipping' ),
                'type'        => 'password',
                'description' => __( 'Get your API token from your Courier Guy portal at portal.thecourierguy.co.za.', 'erpnext-shipping' ),
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

            // ── Own Vehicle Delivery ──
            'bulk_delivery_heading' => array(
                'title' => __( 'Own Vehicle Delivery', 'erpnext-shipping' ),
                'type'  => 'title',
            ),
            'bulk_delivery_enabled' => array(
                'title'   => __( 'Enable', 'erpnext-shipping' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable own vehicle / bulk delivery rate', 'erpnext-shipping' ),
                'default' => 'no',
            ),
            'bulk_delivery_label' => array(
                'title'       => __( 'Rate Label', 'erpnext-shipping' ),
                'type'        => 'text',
                'default'     => __( 'Own Vehicle Delivery', 'erpnext-shipping' ),
                'description' => __( 'Shown to customers at checkout.', 'erpnext-shipping' ),
            ),
            'bulk_delivery_quote_label' => array(
                'title'       => __( 'Quote Rate Label', 'erpnext-shipping' ),
                'type'        => 'text',
                'default'     => __( 'Delivery Quote Required', 'erpnext-shipping' ),
                'description' => __( 'Shown when a distance band is configured as manual quote.', 'erpnext-shipping' ),
            ),
            'bulk_delivery_pricing_mode' => array(
                'title'       => __( 'Pricing Mode', 'erpnext-shipping' ),
                'type'        => 'select',
                'options'     => array(
                    'simple' => __( 'Simple rate per km', 'erpnext-shipping' ),
                    'bands'  => __( 'Distance bands', 'erpnext-shipping' ),
                ),
                'default'     => 'simple',
                'description' => __( 'Use simple rate/km pricing or per-distance delivery fees and free-delivery thresholds.', 'erpnext-shipping' ),
            ),
            'bulk_delivery_distance_basis' => array(
                'title'       => __( 'Distance Basis', 'erpnext-shipping' ),
                'type'        => 'select',
                'options'     => array(
                    'one_way'    => __( 'One-way distance', 'erpnext-shipping' ),
                    'round_trip' => __( 'Round-trip distance', 'erpnext-shipping' ),
                ),
                'default'     => 'one_way',
                'description' => __( 'Distance rules and Google lookups are one-way. Select round-trip to multiply that distance for pricing and band matching.', 'erpnext-shipping' ),
            ),
            'bulk_delivery_round_trip_multiplier' => array(
                'title'             => __( 'Round-trip Multiplier', 'erpnext-shipping' ),
                'type'              => 'number',
                'default'           => '2',
                'description'       => __( 'Used only when Distance Basis is round-trip.', 'erpnext-shipping' ),
                'custom_attributes' => array( 'min' => '1', 'step' => '0.1' ),
            ),
            'bulk_delivery_rate_per_km' => array(
                'title'             => __( 'Rate per km (R)', 'erpnext-shipping' ),
                'type'              => 'number',
                'default'           => '8',
                'custom_attributes' => array( 'min' => '0', 'step' => '0.01' ),
            ),
            'bulk_delivery_free_threshold' => array(
                'title'             => __( 'Free Above (R)', 'erpnext-shipping' ),
                'type'              => 'number',
                'default'           => '0',
                'description'       => __( 'Set to 0 to disable free own-vehicle delivery.', 'erpnext-shipping' ),
                'custom_attributes' => array( 'min' => '0', 'step' => '1' ),
            ),
            'bulk_delivery_max_distance_km' => array(
                'title'             => __( 'Maximum Distance (km)', 'erpnext-shipping' ),
                'type'              => 'number',
                'default'           => '0',
                'description'       => __( 'Set to 0 for no maximum. Orders beyond this distance will not show this rate.', 'erpnext-shipping' ),
                'custom_attributes' => array( 'min' => '0', 'step' => '0.1' ),
            ),
            'bulk_delivery_default_distance_km' => array(
                'title'             => __( 'Default Distance (km)', 'erpnext-shipping' ),
                'type'              => 'number',
                'default'           => '0',
                'description'       => __( 'Fallback distance when no postcode/city rule matches and no Google API key is configured. Set to 0 to hide the rate when distance is unknown.', 'erpnext-shipping' ),
                'custom_attributes' => array( 'min' => '0', 'step' => '0.1' ),
            ),
            'bulk_delivery_distance_map' => array(
                'title'       => __( 'Distance Rules', 'erpnext-shipping' ),
                'type'        => 'textarea',
                'default'     => '',
                'description' => __( 'One rule per line: postcode or city = km. Exact postcode wins, then city. Example: 0081 = 18', 'erpnext-shipping' ),
            ),
            'bulk_delivery_bands' => array(
                'title'       => __( 'Distance Bands', 'erpnext-shipping' ),
                'type'        => 'textarea',
                'default'     => '',
                'description' => __( 'One band per line: km range = delivery fee | free above. Use "quote" as the fee for manual quote bands. Example: 0-50 = 350 | 5000', 'erpnext-shipping' ),
            ),
            'bulk_delivery_google_api_key' => array(
                'title'       => __( 'Google Distance Matrix API Key', 'erpnext-shipping' ),
                'type'        => 'password',
                'default'     => '',
                'description' => __( 'Optional. When set, road distance is calculated from the selected dispatch location to the customer address and cached for 12 hours.', 'erpnext-shipping' ),
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
     * Render credentials as write-only fields in WooCommerce's native shipping
     * method settings. Core's password renderer includes the stored value in the
     * HTML, so temporarily blank it and show only a configured placeholder.
     */
    public function generate_password_html( $key, $data ) {
        $had_value = array_key_exists( $key, $this->settings );
        $value     = $had_value ? $this->settings[ $key ] : '';

        $this->settings[ $key ] = '';
        if ( '' !== (string) $value ) {
            $data['placeholder'] = __( 'Configured — leave blank to keep', 'erpnext-shipping' );
        }
        $html = parent::generate_password_html( $key, $data );

        if ( $had_value ) {
            $this->settings[ $key ] = $value;
        } else {
            unset( $this->settings[ $key ] );
        }

        return $html;
    }

    /**
     * Preserve an existing credential when the native WC settings form submits
     * a blank write-only password field.
     */
    public function validate_password_field( $key, $value ) {
        $value = parent::validate_password_field( $key, $value );
        return '' === $value ? (string) $this->get_option( $key, '' ) : $value;
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

        // 4. Add optional own-vehicle / bulk delivery rate.
        $bulk_delivery_added = $this->maybe_add_bulk_delivery_rate( $destination, $plan, $cart_total );

        // 5. Get carriers.
        $carriers = $this->get_carriers();
        if ( empty( $carriers ) ) {
            if ( $bulk_delivery_added ) {
                $this->log( 'No carriers configured, but own vehicle delivery was added.' );
                return;
            }
            $this->log( 'No carriers configured, adding fallback rate.' );
            $this->add_fallback_rate();
            return;
        }

        // 6. Build parcels and get rates per dispatch location.
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
            $rates   = array();
            $partial = false; // true if any carrier was skipped (budget) or errored.
            foreach ( $carriers as $carrier ) {
                $elapsed = microtime( true ) - $start_time;
                if ( $elapsed > $time_budget ) {
                    $this->log( 'Time budget exceeded before ' . $carrier->get_carrier_name() . ', skipping.' );
                    $partial = true;
                    break;
                }

                try {
                    $carrier_rates = $carrier->get_rates( $origin, $destination, $parcels );
                    if ( is_array( $carrier_rates ) ) {
                        $rates = array_merge( $rates, $carrier_rates );
                        $this->log( $carrier->get_carrier_name() . ' returned ' . count( $carrier_rates ) . ' rates for ' . $lq['location'] . ' in ' . round( microtime( true ) - $start_time, 1 ) . 's' );
                    } else {
                        $this->log( $carrier->get_carrier_name() . ' returned no rates for ' . $lq['location'] );
                        $partial = true; // carrier failed/empty — likely transient, don't pin it.
                    }
                } catch ( \Throwable $e ) {
                    $this->log( $carrier->get_carrier_name() . ' error: ' . $e->getMessage() );
                    $partial = true;
                }
            }

            // Only cache complete, non-empty result sets. Caching an empty/partial
            // result (carrier timeout, budget break) used to pin "no rates" for the
            // full cache TTL for every customer sharing this key.
            if ( ! empty( $rates ) && ! $partial ) {
                $cache->set( $cache_key, $rates );
            }
            $location_rates[ $lq['location'] ] = $rates;
        }

        // A location skipped entirely by the time budget must still count as
        // "no rates" — for split plans a missing key would otherwise sum only
        // the quoted legs and present the partial total as the full charge.
        foreach ( $locations_to_quote as $lq ) {
            if ( ! isset( $location_rates[ $lq['location'] ] ) ) {
                $location_rates[ $lq['location'] ] = array();
            }
        }

        // 7. Combine rates.
        $all_rates = array();
        if ( $is_split ) {
            $all_rates = $this->combine_split_rates( $location_rates );
        } elseif ( $is_chooseable ) {
            $all_rates = $this->pick_cheapest_location_rates( $location_rates );
        } else {
            $loc = $plan['location'];
            $all_rates = $location_rates[ $loc ] ?? array();
        }

        // 8. Group by tier, pick cheapest per tier.
        $tiered = $this->group_by_tier( $all_rates );

        // 9. Apply markup and add rates.
        if ( empty( $tiered ) ) {
            if ( $bulk_delivery_added ) {
                $this->log( 'No rates from carriers, but own vehicle delivery was added.' );
                return;
            }
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

            $meta = array(
                'Carrier' => $rate['carrier'],
                'Service' => $rate['service_name'] ?? '',
            );
            if ( $is_split ) {
                $meta['_es_is_split'] = '1';
            }

            $this->add_rate( array(
                'id'        => $this->id . '_' . $tier,
                'label'     => $label,
                'cost'      => $cost,
                'meta_data' => $meta,
            ) );
            $this->log( 'Rate added: ' . $label . ' R' . $cost . ' (' . $rate['carrier'] . ')' );
        }
    }

    /**
     * Add own-vehicle / bulk delivery rate when enabled and distance is known.
     *
     * @param array $destination Destination address.
     * @param array $plan Fulfillment plan.
     * @param float $cart_total Cart subtotal.
     * @return bool True when the rate was added.
     */
    private function maybe_add_bulk_delivery_rate( $destination, $plan, $cart_total ) {
        if ( 'yes' !== $this->get_option( 'bulk_delivery_enabled', 'no' ) ) {
            return false;
        }

        $distance_km = $this->resolve_nearest_bulk_delivery_distance_km( $plan, $destination );
        if ( $distance_km <= 0 ) {
            $this->log( 'Own vehicle delivery skipped: distance unknown.' );
            return false;
        }

        $priced_distance_km = $this->get_bulk_delivery_priced_distance_km( $distance_km );
        $pricing_mode       = $this->get_option( 'bulk_delivery_pricing_mode', 'simple' );
        $label              = $this->get_option( 'bulk_delivery_label', __( 'Own Vehicle Delivery', 'erpnext-shipping' ) );

        if ( 'bands' === $pricing_mode ) {
            $band = $this->match_bulk_delivery_band( $priced_distance_km );
            if ( ! $band ) {
                $this->log( 'Own vehicle delivery skipped: no band for ' . round( $priced_distance_km, 1 ) . 'km.' );
                return false;
            }

            if ( ! empty( $band['manual_quote'] ) ) {
                $quote_label = $this->get_option( 'bulk_delivery_quote_label', __( 'Delivery Quote Required', 'erpnext-shipping' ) );
                $this->add_rate( array(
                    'id'        => $this->id . '_bulk_delivery_quote',
                    'label'     => $quote_label,
                    'cost'      => 0,
                    'meta_data' => array(
                        '_es_bulk_delivery'       => '1',
                        '_es_bulk_delivery_quote' => '1',
                        'Distance'                => $this->format_bulk_delivery_distance_meta( $distance_km, $priced_distance_km ),
                        'Band'                    => $band['label'],
                        'Rate'                    => __( 'Manual delivery quote required before processing', 'erpnext-shipping' ),
                    ),
                ) );

                $this->log( 'Own vehicle delivery quote rate added from band ' . $band['label'] . ': ' . round( $priced_distance_km, 1 ) . 'km.' );
                return true;
            }

            $is_free = $band['free_threshold'] > 0 && $cart_total >= $band['free_threshold'];
            $cost    = $is_free ? 0 : $band['fee'];
            $meta    = array(
                '_es_bulk_delivery' => '1',
                'Distance'          => $this->format_bulk_delivery_distance_meta( $distance_km, $priced_distance_km ),
                'Band'              => $band['label'],
                'Rate'              => $is_free ? __( 'Free delivery threshold met', 'erpnext-shipping' ) : __( 'Distance-band delivery fee', 'erpnext-shipping' ),
            );

            if ( $band['free_threshold'] > 0 ) {
                $meta['Free Above'] = 'R' . wc_format_decimal( $band['free_threshold'], 2 );
            }

            $this->add_rate( array(
                'id'        => $this->id . '_bulk_delivery',
                'label'     => $label,
                'cost'      => $cost,
                'meta_data' => $meta,
            ) );

            $this->log( 'Own vehicle delivery added from band ' . $band['label'] . ': ' . round( $priced_distance_km, 1 ) . 'km, cost R' . $cost . ( $is_free ? ' (free threshold met)' : '' ) );
            return true;
        }

        $rate_per_km = floatval( $this->get_option( 'bulk_delivery_rate_per_km', 8 ) );
        if ( $rate_per_km <= 0 ) {
            $this->log( 'Own vehicle delivery enabled but rate per km is zero.' );
            return false;
        }

        $max_distance = floatval( $this->get_option( 'bulk_delivery_max_distance_km', 0 ) );
        if ( $max_distance > 0 && $priced_distance_km > $max_distance ) {
            $this->log( 'Own vehicle delivery skipped: ' . round( $priced_distance_km, 1 ) . 'km exceeds max ' . $max_distance . 'km.' );
            return false;
        }

        $free_threshold = floatval( $this->get_option( 'bulk_delivery_free_threshold', 0 ) );
        $is_free        = $free_threshold > 0 && $cart_total >= $free_threshold;
        $cost           = $is_free ? 0 : ceil( ( $priced_distance_km * $rate_per_km ) / 5 ) * 5;

        $this->add_rate( array(
            'id'        => $this->id . '_bulk_delivery',
            'label'     => $label,
            'cost'      => $cost,
            'meta_data' => array(
                '_es_bulk_delivery' => '1',
                'Distance'          => $this->format_bulk_delivery_distance_meta( $distance_km, $priced_distance_km ),
                'Rate'              => $is_free ? __( 'Free delivery threshold met', 'erpnext-shipping' ) : sprintf( 'R%s/km', wc_format_decimal( $rate_per_km, 2 ) ),
            ),
        ) );

        $this->log( 'Own vehicle delivery added: ' . round( $priced_distance_km, 1 ) . 'km, cost R' . $cost . ( $is_free ? ' (free threshold met)' : '' ) );
        return true;
    }

    /**
     * Convert one-way delivery distance to the configured pricing distance.
     *
     * @param float $one_way_km One-way distance in km.
     * @return float Distance used for pricing and band matching.
     */
    private function get_bulk_delivery_priced_distance_km( $one_way_km ) {
        if ( 'round_trip' !== $this->get_option( 'bulk_delivery_distance_basis', 'one_way' ) ) {
            return $one_way_km;
        }

        $multiplier = floatval( $this->get_option( 'bulk_delivery_round_trip_multiplier', 2 ) );
        if ( $multiplier < 1 ) {
            $multiplier = 2;
        }

        return $one_way_km * $multiplier;
    }

    /**
     * Format distance metadata shown under the checkout rate.
     *
     * @param float $one_way_km One-way distance in km.
     * @param float $priced_distance_km Distance used for pricing.
     * @return string Human-readable distance.
     */
    private function format_bulk_delivery_distance_meta( $one_way_km, $priced_distance_km ) {
        if ( 'round_trip' === $this->get_option( 'bulk_delivery_distance_basis', 'one_way' ) ) {
            return round( $priced_distance_km, 1 ) . ' km round trip (' . round( $one_way_km, 1 ) . ' km one way)';
        }

        return round( $one_way_km, 1 ) . ' km';
    }

    /**
     * Find the configured band for a priced delivery distance.
     *
     * @param float $distance_km Distance used for pricing.
     * @return array|null Matched band.
     */
    private function match_bulk_delivery_band( $distance_km ) {
        foreach ( $this->parse_bulk_delivery_bands() as $band ) {
            if ( $distance_km >= $band['min'] && $distance_km <= $band['max'] ) {
                return $band;
            }
        }

        return null;
    }

    /**
     * Parse admin-entered own-vehicle delivery bands.
     *
     * Supported examples:
     * - 0-50 = 350 | 5000
     * - 51-75 = R500 | R7,500
     * - 100+ = 1000 | 15000
     * - 100+ = quote
     *
     * @return array<int,array<string,float|string|bool>>
     */
    private function parse_bulk_delivery_bands() {
        $raw = trim( (string) $this->get_option( 'bulk_delivery_bands', '' ) );
        if ( '' === $raw ) {
            return array();
        }

        $bands = array();
        foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
            $line = trim( $line );
            if ( '' === $line || '#' === substr( $line, 0, 1 ) ) {
                continue;
            }

            $parts = preg_split( '/\s*[=:]\s*/', $line, 2 );
            if ( count( $parts ) !== 2 ) {
                continue;
            }

            $range = $this->parse_bulk_delivery_band_range( $parts[0] );
            if ( ! $range ) {
                continue;
            }

            $values       = preg_split( '/\s*\|\s*/', $parts[1] );
            $fee_raw      = trim( (string) ( $values[0] ?? '' ) );
            $manual_quote = $this->is_bulk_delivery_quote_value( $fee_raw );

            if ( $manual_quote ) {
                $fee            = 0;
                $free_threshold = 0;
            } else {
                $fee            = $this->parse_bulk_delivery_number( $fee_raw );
                $free_threshold = $this->parse_bulk_delivery_number( $values[1] ?? '' );
                if ( $fee < 0 ) {
                    continue;
                }
            }

            $bands[] = array(
                'min'            => $range['min'],
                'max'            => $range['max'],
                'fee'            => $fee,
                'free_threshold' => max( 0, $free_threshold ),
                'label'          => $range['label'],
                'manual_quote'   => $manual_quote,
            );
        }

        usort( $bands, function ( $a, $b ) {
            return $a['min'] <=> $b['min'];
        } );

        return $bands;
    }

    /**
     * Parse a km range from a band line.
     *
     * @param string $raw Raw range text.
     * @return array|null Parsed range.
     */
    private function parse_bulk_delivery_band_range( $raw ) {
        $raw = strtolower( trim( $raw ) );
        $raw = str_replace( array( 'km', ' ' ), '', $raw );

        if ( preg_match( '/^(\d+(?:[\.,]\d+)?)\-(\d+(?:[\.,]\d+)?)$/', $raw, $m ) ) {
            $min = $this->parse_bulk_delivery_number( $m[1] );
            $max = $this->parse_bulk_delivery_number( $m[2] );
        } elseif ( preg_match( '/^(\d+(?:[\.,]\d+)?)\+$/', $raw, $m ) ) {
            $min = $this->parse_bulk_delivery_number( $m[1] );
            $max = INF;
        } elseif ( preg_match( '/^<=?(\d+(?:[\.,]\d+)?)$/', $raw, $m ) ) {
            $min = 0;
            $max = $this->parse_bulk_delivery_number( $m[1] );
        } else {
            return null;
        }

        if ( $min < 0 || $max < $min ) {
            return null;
        }

        return array(
            'min'   => $min,
            'max'   => $max,
            'label' => is_infinite( $max ) ? round( $min, 1 ) . '+ km' : round( $min, 1 ) . '-' . round( $max, 1 ) . ' km',
        );
    }

    /**
     * Parse a money/km number from admin text.
     *
     * @param string $raw Raw value.
     * @return float Parsed number.
     */
    private function parse_bulk_delivery_number( $raw ) {
        $value = preg_replace( '/[^0-9,\.\-]/', '', (string) $raw );
        if ( '' === $value || '-' === $value ) {
            return 0;
        }

        if ( false !== strpos( $value, ',' ) && false !== strpos( $value, '.' ) ) {
            $value = str_replace( ',', '', $value );
        } elseif ( preg_match( '/^\d{1,3}(,\d{3})+$/', $value ) ) {
            $value = str_replace( ',', '', $value );
        } else {
            $value = str_replace( ',', '.', $value );
        }

        return floatval( $value );
    }

    /**
     * Check if a band value means "manual quote".
     *
     * @param string $raw Raw fee value.
     * @return bool True when manual quote is requested.
     */
    private function is_bulk_delivery_quote_value( $raw ) {
        $value = strtolower( trim( (string) $raw ) );
        return in_array( $value, array( 'quote', 'manual', 'manual quote', 'quote required', 'manual_quote' ), true );
    }

    /**
     * Resolve the own-vehicle distance using the NEAREST candidate dispatch
     * location in the plan. Previously the first configured location was used
     * for chooseable/split plans, which could price a customer from the wrong
     * province (wrong band, or "quote" instead of a deliverable rate).
     *
     * @param array $plan        Fulfillment plan.
     * @param array $destination Destination address.
     * @return float Distance in km (0 when unresolvable).
     */
    private function resolve_nearest_bulk_delivery_distance_km( $plan, $destination ) {
        $candidates = array();
        if ( isset( $plan['type'] ) && 'single' === $plan['type'] ) {
            $candidates[] = $plan['location'] ?? '';
        } elseif ( isset( $plan['type'] ) && 'chooseable' === $plan['type'] && ! empty( $plan['locations'] ) ) {
            $candidates = (array) $plan['locations'];
        } elseif ( isset( $plan['type'] ) && 'split' === $plan['type'] && ! empty( $plan['shipments'] ) ) {
            $candidates = array_keys( $plan['shipments'] );
        }
        $candidates = array_filter( array_unique( $candidates ) );

        if ( empty( $candidates ) ) {
            $locations = self::get_locations();
            if ( ! empty( $locations ) ) {
                $first        = reset( $locations );
                $candidates[] = $first['id'] ?? '';
            }
        }

        $best = 0;
        foreach ( $candidates as $loc_id ) {
            if ( ! $loc_id ) {
                continue;
            }
            $km = $this->resolve_bulk_delivery_distance_km( $this->get_origin( $loc_id ), $destination );
            if ( $km > 0 && ( $best <= 0 || $km < $best ) ) {
                $best = $km;
            }
        }

        return $best;
    }

    /**
     * Resolve the dispatch origin for own-vehicle distance calculations.
     *
     * @param array $plan Fulfillment plan.
     * @return array Origin address.
     */
    private function get_bulk_delivery_origin( $plan ) {
        $location_id = '';

        if ( isset( $plan['type'] ) && 'single' === $plan['type'] ) {
            $location_id = $plan['location'] ?? '';
        } elseif ( isset( $plan['type'] ) && 'chooseable' === $plan['type'] && ! empty( $plan['locations'] ) ) {
            $location_id = reset( $plan['locations'] );
        } elseif ( isset( $plan['type'] ) && 'split' === $plan['type'] && ! empty( $plan['shipments'] ) ) {
            $keys        = array_keys( $plan['shipments'] );
            $location_id = reset( $keys );
        }

        if ( ! $location_id ) {
            $locations = self::get_locations();
            if ( ! empty( $locations ) ) {
                $first       = reset( $locations );
                $location_id = $first['id'] ?? '';
            }
        }

        return $this->get_origin( $location_id );
    }

    /**
     * Resolve delivery kilometres by postcode/city map, Google Distance Matrix, or default.
     *
     * @param array $origin Origin address.
     * @param array $destination Destination address.
     * @return float Distance in km, or 0 when unknown.
     */
    private function resolve_bulk_delivery_distance_km( $origin, $destination ) {
        $mapped = $this->get_bulk_delivery_distance_from_map( $destination );
        if ( $mapped > 0 ) {
            return $mapped;
        }

        $google = $this->get_bulk_delivery_distance_from_google( $origin, $destination );
        if ( $google > 0 ) {
            return $google;
        }

        return floatval( $this->get_option( 'bulk_delivery_default_distance_km', 0 ) );
    }

    /**
     * Look up distance from admin-maintained postcode/city rules.
     *
     * @param array $destination Destination address.
     * @return float Distance in km.
     */
    private function get_bulk_delivery_distance_from_map( $destination ) {
        $raw = trim( (string) $this->get_option( 'bulk_delivery_distance_map', '' ) );
        if ( '' === $raw ) {
            return 0;
        }

        $postcode = strtolower( trim( (string) ( $destination['code'] ?? '' ) ) );
        $city     = strtolower( trim( (string) ( $destination['city'] ?? '' ) ) );
        $rules    = array();

        foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
            $line = trim( $line );
            if ( '' === $line || '#' === substr( $line, 0, 1 ) ) {
                continue;
            }
            $parts = preg_split( '/\s*[=:]\s*/', $line, 2 );
            if ( count( $parts ) !== 2 ) {
                continue;
            }
            $key = strtolower( trim( $parts[0] ) );
            $km  = floatval( str_replace( ',', '.', trim( $parts[1] ) ) );
            if ( '' !== $key && $km > 0 ) {
                $rules[ $key ] = $km;
            }
        }

        if ( $postcode && isset( $rules[ $postcode ] ) ) {
            return $rules[ $postcode ];
        }
        if ( $city && isset( $rules[ $city ] ) ) {
            return $rules[ $city ];
        }

        return 0;
    }

    /**
     * Calculate road distance via Google Distance Matrix when configured.
     *
     * @param array $origin Origin address.
     * @param array $destination Destination address.
     * @return float Distance in km.
     */
    private function get_bulk_delivery_distance_from_google( $origin, $destination ) {
        $api_key = trim( (string) $this->get_option( 'bulk_delivery_google_api_key', '' ) );
        if ( '' === $api_key ) {
            return 0;
        }

        $origin_address      = $this->format_address_for_distance( $origin );
        $destination_address = $this->format_address_for_distance( $destination );
        if ( '' === $origin_address || '' === $destination_address ) {
            return 0;
        }

        $cache_key = 'es_bulk_distance_' . md5( strtolower( $origin_address . '|' . $destination_address ) );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            return floatval( $cached );
        }

        $url = add_query_arg(
            array(
                'units'        => 'metric',
                'origins'      => $origin_address,
                'destinations' => $destination_address,
                'key'          => $api_key,
            ),
            'https://maps.googleapis.com/maps/api/distancematrix/json'
        );

        $response = wp_remote_get( $url, array( 'timeout' => 8 ) );
        if ( is_wp_error( $response ) ) {
            $this->log( 'Google distance lookup failed: ' . $response->get_error_message() );
            return 0;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if (
            ! is_array( $body )
            || ( $body['status'] ?? '' ) !== 'OK'
            || ( $body['rows'][0]['elements'][0]['status'] ?? '' ) !== 'OK'
            || empty( $body['rows'][0]['elements'][0]['distance']['value'] )
        ) {
            $this->log( 'Google distance lookup returned no usable distance.' );
            return 0;
        }

        $km = floatval( $body['rows'][0]['elements'][0]['distance']['value'] ) / 1000;
        set_transient( $cache_key, $km, 12 * HOUR_IN_SECONDS );
        return $km;
    }

    /**
     * Format a carrier-style address array for distance lookup.
     *
     * @param array $address Address fields.
     * @return string Address string.
     */
    private function format_address_for_distance( $address ) {
        $parts = array_filter( array(
            $address['street_address'] ?? '',
            $address['local_area'] ?? '',
            $address['city'] ?? '',
            $address['zone'] ?? '',
            $address['code'] ?? '',
            $address['country'] ?? 'ZA',
        ) );
        return trim( implode( ', ', $parts ) );
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
            // Use SKU for stock lookup. Fall back to product ID for SKU-less products
            // to prevent them from collapsing into the same empty-key lookup.
            $sku = $product->get_sku();
            if ( empty( $sku ) ) {
                $sku = '_pid_' . $product->get_id();
            }
            $items[] = array(
                'sku'      => $sku,
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
