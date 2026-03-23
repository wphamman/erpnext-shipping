<?php
defined( 'ABSPATH' ) || exit;

class ES_Admin_Page {

    const SLUG = 'es-shipping';

    private $instance_id = 0;
    private $option_key  = '';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        add_action( 'admin_init', array( $this, 'detect_instance' ) );
    }

    /**
     * Auto-detect the WC shipping method instance ID.
     */
    public function detect_instance() {
        global $wpdb;
        $row = $wpdb->get_row(
            "SELECT instance_id FROM {$wpdb->prefix}woocommerce_shipping_zone_methods WHERE method_id = 'erpnext_shipping' AND is_enabled = 1 ORDER BY instance_id ASC LIMIT 1"
        );
        if ( $row ) {
            $this->instance_id = intval( $row->instance_id );
            $this->option_key  = 'woocommerce_erpnext_shipping_' . $this->instance_id . '_settings';
        }
    }

    /**
     * Register under WooCommerce menu.
     */
    public function add_menu() {
        add_submenu_page(
            'woocommerce',
            __( 'ERPNext Shipping', 'erpnext-shipping' ),
            __( 'ES Shipping', 'erpnext-shipping' ),
            'manage_woocommerce',
            self::SLUG,
            array( $this, 'render_page' )
        );
    }

    /**
     * Get current WC instance settings.
     */
    private function get_settings() {
        if ( ! $this->option_key ) {
            return array();
        }
        return get_option( $this->option_key, array() );
    }

    /**
     * Save settings on form submit.
     */
    private function maybe_save() {
        if ( ! isset( $_POST['es_shipping_save'] ) ) {
            return false;
        }
        check_admin_referer( 'es_shipping_settings' );

        // ── Save locations to separate option ──
        $locations_json = wp_unslash( $_POST['es_locations_json'] ?? '[]' );
        $locations_raw  = json_decode( $locations_json, true );
        $locations      = array();

        if ( is_array( $locations_raw ) ) {
            foreach ( $locations_raw as $loc ) {
                $name = sanitize_text_field( $loc['name'] ?? '' );
                if ( empty( $name ) ) {
                    continue;
                }

                // Generate stable ID from name if not already set.
                $id = ! empty( $loc['id'] ) ? sanitize_title( $loc['id'] ) : sanitize_title( $name );

                $erp_wh_raw = $loc['erp_warehouses'] ?? array();
                $erp_warehouses = array();
                if ( is_array( $erp_wh_raw ) ) {
                    foreach ( $erp_wh_raw as $wh ) {
                        $wh = trim( sanitize_text_field( $wh ) );
                        if ( ! empty( $wh ) ) {
                            $erp_warehouses[] = $wh;
                        }
                    }
                }

                $loc_type = sanitize_text_field( $loc['type'] ?? 'warehouse' );
                if ( ! in_array( $loc_type, array( 'warehouse', 'collection_point' ), true ) ) {
                    $loc_type = 'warehouse';
                }

                $locations[] = array(
                    'id'             => $id,
                    'name'           => $name,
                    'type'           => $loc_type,
                    'street'         => sanitize_text_field( $loc['street'] ?? '' ),
                    'suburb'         => sanitize_text_field( $loc['suburb'] ?? '' ),
                    'city'           => sanitize_text_field( $loc['city'] ?? '' ),
                    'province'       => sanitize_text_field( $loc['province'] ?? '' ),
                    'postcode'       => sanitize_text_field( $loc['postcode'] ?? '' ),
                    'country'        => sanitize_text_field( $loc['country'] ?? 'ZA' ),
                    'erp_warehouses' => $erp_warehouses,
                    'slw_term_id'    => intval( $loc['slw_term_id'] ?? 0 ),
                    'pickup_enabled'   => 'collection_point' === $loc_type ? true : ! empty( $loc['pickup_enabled'] ),
                    'customer_message' => sanitize_textarea_field( $loc['customer_message'] ?? '' ),
                );
            }
        }

        update_option( 'es_shipping_locations', $locations, false );

        // ── Save WC instance settings ──
        $opts = $this->get_settings();

        $text_fields = array(
            'erp_url', 'erp_api_key', 'erp_api_secret',
            'tcg_api_token', 'mds_api_token',
            'title', 'company_name',
        );
        foreach ( $text_fields as $key ) {
            if ( isset( $_POST[ $key ] ) ) {
                $opts[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
            }
        }

        $number_fields = array(
            'markup_value', 'free_shipping_threshold', 'fallback_rate',
            'default_weight', 'default_length', 'default_width', 'default_height',
        );
        foreach ( $number_fields as $key ) {
            if ( isset( $_POST[ $key ] ) ) {
                $opts[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
            }
        }

        $checkbox_fields = array( 'enabled', 'tcg_enabled', 'mds_enabled', 'debug' );
        foreach ( $checkbox_fields as $key ) {
            $opts[ $key ] = isset( $_POST[ $key ] ) ? 'yes' : 'no';
        }

        $select_fields = array( 'markup_type', 'free_shipping_source' );
        foreach ( $select_fields as $key ) {
            if ( isset( $_POST[ $key ] ) ) {
                $opts[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
            }
        }

        // No free shipping classes (comma-separated text).
        if ( isset( $_POST['no_free_shipping_classes'] ) ) {
            $opts['no_free_shipping_classes'] = sanitize_text_field( wp_unslash( $_POST['no_free_shipping_classes'] ) );
        }

        // Fulfillment mode.
        if ( isset( $_POST['es_fulfillment_mode'] ) ) {
            $mode = sanitize_text_field( wp_unslash( $_POST['es_fulfillment_mode'] ) );
            if ( in_array( $mode, array( 'migration', 'active' ), true ) ) {
                $prev_mode = get_option( 'es_fulfillment_mode', 'migration' );
                update_option( 'es_fulfillment_mode', $mode );

                // Side effects when switching modes.
                if ( 'active' === $mode && 'active' !== $prev_mode ) {
                    // Enable AST compat flag so woocommerce_fusion reads tracking meta.
                    update_option( 'wc_plugin_advanced_shipment_tracking', 'yes' );
                    // Schedule fulfillment cron if not already scheduled.
                    if ( ! wp_next_scheduled( 'es_fulfillment_tracking_poll' ) ) {
                        wp_schedule_event( time(), 'es_every_15_min', 'es_fulfillment_tracking_poll' );
                    }
                } elseif ( 'migration' === $mode && 'migration' !== $prev_mode ) {
                    // Leave wc_plugin_advanced_shipment_tracking alone — AST Pro owns it in migration mode.
                    // Unschedule fulfillment cron (old plugins handle their own polling).
                    wp_clear_scheduled_hook( 'es_fulfillment_tracking_poll' );
                }
            }
        }

        update_option( $this->option_key, $opts );
        return true;
    }

    /**
     * Render the admin page.
     */
    public function render_page() {
        if ( ! $this->option_key ) {
            echo '<div class="wrap"><h1>' . esc_html__( 'ERPNext Shipping', 'erpnext-shipping' ) . '</h1>';
            echo '<div class="notice notice-error"><p>';
            echo esc_html__( 'Shipping method not found. Please add ERPNext Multi-Carrier Shipping to a WooCommerce shipping zone first.', 'erpnext-shipping' );
            echo '</p></div></div>';
            return;
        }

        $saved = $this->maybe_save();
        $opts  = $this->get_settings();

        $v = function ( $key, $default = '' ) use ( $opts ) {
            return $opts[ $key ] ?? $default;
        };

        $locations  = get_option( 'es_shipping_locations', array() );
        $last_sync  = get_option( 'es_shipping_stock_last_sync', 0 );
        $stock_data = get_option( 'es_shipping_warehouse_stock', array() );
        $item_count = count( $stock_data );

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'ERPNext Shipping', 'erpnext-shipping' ); ?></h1>

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'erpnext-shipping' ); ?></p></div>
            <?php endif; ?>

            <!-- Stock Sync Status -->
            <div class="card" style="max-width:800px; margin-bottom:20px; padding:15px 20px;">
                <h2 style="margin-top:0;"><?php esc_html_e( 'Stock Sync Status', 'erpnext-shipping' ); ?></h2>
                <table class="form-table" style="margin:0;">
                    <tr>
                        <th><?php esc_html_e( 'Last Sync', 'erpnext-shipping' ); ?></th>
                        <td>
                            <?php if ( $last_sync ) : ?>
                                <?php echo esc_html( date_i18n( 'Y-m-d H:i:s', $last_sync + ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) ); ?>
                                <span style="color:#666;"> (<?php echo esc_html( human_time_diff( $last_sync ) ); ?> ago)</span>
                            <?php else : ?>
                                <span style="color:#d63638;"><?php esc_html_e( 'Never', 'erpnext-shipping' ); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Items Synced', 'erpnext-shipping' ); ?></th>
                        <td><?php echo esc_html( number_format( $item_count ) ); ?> SKUs</td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Sync Now', 'erpnext-shipping' ); ?></th>
                        <td>
                            <button type="button" class="button button-secondary" id="es-sync-btn">
                                <?php esc_html_e( 'Sync Stock from ERPNext', 'erpnext-shipping' ); ?>
                            </button>
                            <span id="es-sync-status" style="margin-left:10px;"></span>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Settings Form -->
            <form method="post" action="" style="max-width:800px;">
                <?php wp_nonce_field( 'es_shipping_settings' ); ?>

                <!-- General -->
                <h2><?php esc_html_e( 'General', 'erpnext-shipping' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="enabled"><?php esc_html_e( 'Enable', 'erpnext-shipping' ); ?></label></th>
                        <td><label><input type="checkbox" name="enabled" id="enabled" value="1" <?php checked( $v( 'enabled', 'yes' ), 'yes' ); ?>> <?php esc_html_e( 'Enable ERPNext Multi-Carrier Shipping', 'erpnext-shipping' ); ?></label></td>
                    </tr>
                    <tr>
                        <th><label for="title"><?php esc_html_e( 'Method Title', 'erpnext-shipping' ); ?></label></th>
                        <td><input type="text" name="title" id="title" class="regular-text" value="<?php echo esc_attr( $v( 'title', 'Shipping' ) ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="company_name"><?php esc_html_e( 'Company Name', 'erpnext-shipping' ); ?></label></th>
                        <td>
                            <input type="text" name="company_name" id="company_name" class="regular-text" value="<?php echo esc_attr( $v( 'company_name' ) ); ?>">
                            <p class="description"><?php esc_html_e( 'Your business name as it should appear on shipping labels and waybills.', 'erpnext-shipping' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="debug"><?php esc_html_e( 'Debug Logging', 'erpnext-shipping' ); ?></label></th>
                        <td><label><input type="checkbox" name="debug" id="debug" value="1" <?php checked( $v( 'debug', 'yes' ), 'yes' ); ?>> <?php esc_html_e( 'Log shipping calculations to WooCommerce logs', 'erpnext-shipping' ); ?></label></td>
                    </tr>
                </table>

                <!-- Dispatch Locations -->
                <h2><?php esc_html_e( 'Dispatch Locations', 'erpnext-shipping' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Add your dispatch locations. Each location represents a physical address that parcels ship from. If you have multiple ERPNext warehouses at the same address (e.g. a warehouse and a retail shop in the same building), group them under one location.', 'erpnext-shipping' ); ?></p>

                <div id="es-locations-container">
                    <?php if ( empty( $locations ) ) : ?>
                        <p id="es-no-locations" style="color:#666; font-style:italic;"><?php esc_html_e( 'No locations configured. Click "Add Location" to add your first dispatch location.', 'erpnext-shipping' ); ?></p>
                    <?php endif; ?>
                </div>

                <p>
                    <button type="button" class="button" id="es-add-location"><?php esc_html_e( 'Add Location', 'erpnext-shipping' ); ?></button>
                    <input type="submit" name="es_shipping_save" class="button button-primary" value="<?php esc_attr_e( 'Save Settings', 'erpnext-shipping' ); ?>" style="margin-left:10px;">
                </p>

                <input type="hidden" name="es_locations_json" id="es-locations-json" value="">

                <!-- ERPNext -->
                <h2><?php esc_html_e( 'ERPNext Stock Sync', 'erpnext-shipping' ); ?></h2>
                <table class="form-table">
                    <?php $this->render_text_row( 'erp_url', __( 'ERPNext URL', 'erpnext-shipping' ), $v( 'erp_url' ) ); ?>
                    <?php $this->render_text_row( 'erp_api_key', __( 'API Key', 'erpnext-shipping' ), $v( 'erp_api_key' ) ); ?>
                    <?php $this->render_password_row( 'erp_api_secret', __( 'API Secret', 'erpnext-shipping' ), $v( 'erp_api_secret' ) ); ?>
                </table>

                <!-- Carriers -->
                <h2><?php esc_html_e( 'The Courier Guy', 'erpnext-shipping' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="tcg_enabled"><?php esc_html_e( 'Enable', 'erpnext-shipping' ); ?></label></th>
                        <td><label><input type="checkbox" name="tcg_enabled" id="tcg_enabled" value="1" <?php checked( $v( 'tcg_enabled', 'yes' ), 'yes' ); ?>> <?php esc_html_e( 'Enable The Courier Guy rates', 'erpnext-shipping' ); ?></label></td>
                    </tr>
                    <?php $this->render_password_row( 'tcg_api_token', __( 'Ship Logic API Token', 'erpnext-shipping' ), $v( 'tcg_api_token' ) ); ?>
                    <tr><th></th><td><p class="description"><?php esc_html_e( 'The Courier Guy rates are fetched via the Ship Logic platform. Get your API token at shiplogic.com.', 'erpnext-shipping' ); ?></p></td></tr>
                </table>

                <h2><?php esc_html_e( 'MDS Collivery', 'erpnext-shipping' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="mds_enabled"><?php esc_html_e( 'Enable', 'erpnext-shipping' ); ?></label></th>
                        <td><label><input type="checkbox" name="mds_enabled" id="mds_enabled" value="1" <?php checked( $v( 'mds_enabled', 'no' ), 'yes' ); ?>> <?php esc_html_e( 'Enable MDS Collivery rates', 'erpnext-shipping' ); ?></label></td>
                    </tr>
                    <?php $this->render_password_row( 'mds_api_token', __( 'API Token', 'erpnext-shipping' ), $v( 'mds_api_token' ) ); ?>
                </table>

                <!-- Pricing -->
                <h2><?php esc_html_e( 'Pricing', 'erpnext-shipping' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="markup_type"><?php esc_html_e( 'Markup Type', 'erpnext-shipping' ); ?></label></th>
                        <td>
                            <select name="markup_type" id="markup_type">
                                <option value="none" <?php selected( $v( 'markup_type', 'none' ), 'none' ); ?>><?php esc_html_e( 'No markup', 'erpnext-shipping' ); ?></option>
                                <option value="percentage" <?php selected( $v( 'markup_type' ), 'percentage' ); ?>><?php esc_html_e( 'Percentage (%)', 'erpnext-shipping' ); ?></option>
                                <option value="flat" <?php selected( $v( 'markup_type' ), 'flat' ); ?>><?php esc_html_e( 'Flat amount (R)', 'erpnext-shipping' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <?php $this->render_number_row( 'markup_value', __( 'Markup Value', 'erpnext-shipping' ), $v( 'markup_value', '0' ) ); ?>
                    <tr>
                        <th><label for="free_shipping_source"><?php esc_html_e( 'Free Shipping Source', 'erpnext-shipping' ); ?></label></th>
                        <td>
                            <select name="free_shipping_source" id="free_shipping_source">
                                <option value="wc_method" <?php selected( $v( 'free_shipping_source', 'wc_method' ), 'wc_method' ); ?>><?php esc_html_e( 'WooCommerce Free Shipping zone method', 'erpnext-shipping' ); ?></option>
                                <option value="plugin" <?php selected( $v( 'free_shipping_source' ), 'plugin' ); ?>><?php esc_html_e( 'This plugin (uses threshold below)', 'erpnext-shipping' ); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e( '"WC method" is recommended if your theme shows a free shipping progress bar.', 'erpnext-shipping' ); ?></p>
                        </td>
                    </tr>
                    <?php $this->render_number_row( 'free_shipping_threshold', __( 'Free Shipping Above (R)', 'erpnext-shipping' ), $v( 'free_shipping_threshold', '0' ), __( 'Only used when source is "This plugin". Set to 0 to disable.', 'erpnext-shipping' ) ); ?>
                    <?php $this->render_text_row( 'no_free_shipping_classes', __( 'No Free Shipping Classes', 'erpnext-shipping' ), $v( 'no_free_shipping_classes', '' ) ); ?>
                    <tr><th></th><td><p class="description"><?php esc_html_e( 'Comma-separated shipping class slugs. Orders with items in these classes will not qualify for free shipping (e.g. "heavy, oversized").', 'erpnext-shipping' ); ?></p></td></tr>
                    <?php $this->render_number_row( 'fallback_rate', __( 'Flat Rate Fallback (R)', 'erpnext-shipping' ), $v( 'fallback_rate', '0' ), __( 'Used when carrier APIs fail.', 'erpnext-shipping' ) ); ?>
                </table>

                <!-- Default Parcel Dimensions -->
                <h2><?php esc_html_e( 'Default Parcel Dimensions', 'erpnext-shipping' ); ?></h2>
                <table class="form-table">
                    <?php $this->render_number_row( 'default_weight', __( 'Weight (kg)', 'erpnext-shipping' ), $v( 'default_weight', '0.5' ) ); ?>
                    <?php $this->render_number_row( 'default_length', __( 'Length (cm)', 'erpnext-shipping' ), $v( 'default_length', '20' ) ); ?>
                    <?php $this->render_number_row( 'default_width', __( 'Width (cm)', 'erpnext-shipping' ), $v( 'default_width', '15' ) ); ?>
                    <?php $this->render_number_row( 'default_height', __( 'Height (cm)', 'erpnext-shipping' ), $v( 'default_height', '10' ) ); ?>
                </table>

                <p class="submit">
                    <input type="submit" name="es_shipping_save" class="button button-primary" value="<?php esc_attr_e( 'Save Settings', 'erpnext-shipping' ); ?>">
                </p>

                <!-- Fulfillment Module -->
                <div class="card" style="max-width:800px; margin-bottom:20px; padding:15px 20px;">
                    <h2><?php esc_html_e( 'Fulfillment Module', 'erpnext-shipping' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'Order tracking, status management, courier polling, and email notifications.', 'erpnext-shipping' ); ?></p>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Mode', 'erpnext-shipping' ); ?></th>
                            <td>
                                <?php $current_mode = get_option( 'es_fulfillment_mode', 'migration' ); ?>
                                <label>
                                    <input type="radio" name="es_fulfillment_mode" value="migration" <?php checked( $current_mode, 'migration' ); ?>>
                                    <?php esc_html_e( 'Migration — Only register order statuses (safe to run alongside AST Pro / TrackShip / Local Pickup Pro)', 'erpnext-shipping' ); ?>
                                </label><br><br>
                                <label>
                                    <input type="radio" name="es_fulfillment_mode" value="active" <?php checked( $current_mode, 'active' ); ?>>
                                    <?php esc_html_e( 'Active — Full fulfillment (tracking, emails, courier polling, admin UI)', 'erpnext-shipping' ); ?>
                                </label>
                                <p class="description" style="margin-top:10px;">
                                    <?php esc_html_e( 'Start in Migration mode. Switch to Active only after deactivating AST Pro, TrackShip, and Local Pickup Pro.', 'erpnext-shipping' ); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>
            </form>
        </div>

        <style>
            .es-location-card {
                background: #fff;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
                padding: 15px 20px;
                margin-bottom: 15px;
                position: relative;
            }
            .es-location-card h3 {
                margin: 0 0 12px 0;
                padding: 0;
                display: flex;
                align-items: center;
                gap: 10px;
            }
            .es-location-card h3 input {
                font-size: 14px;
                font-weight: 600;
                flex: 1;
            }
            .es-location-card .es-remove-location {
                color: #b32d2e;
                cursor: pointer;
                text-decoration: none;
                font-size: 13px;
            }
            .es-location-card .es-remove-location:hover {
                color: #a00;
            }
            .es-location-fields {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 8px 16px;
            }
            .es-location-fields label {
                display: block;
                font-weight: 600;
                font-size: 12px;
                margin-bottom: 2px;
            }
            .es-location-fields input,
            .es-location-fields textarea {
                width: 100%;
            }
            .es-location-fields .es-full-width {
                grid-column: 1 / -1;
            }
        </style>

        <script>
        jQuery(function($) {
            var ajaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
            var instanceId = <?php echo intval( $this->instance_id ); ?>;

            // ── Stock Sync ──
            $('#es-sync-btn').on('click', function() {
                var $btn = $(this);
                var $status = $('#es-sync-status');
                $btn.prop('disabled', true).text('Syncing…');
                $status.text('').css('color', '');

                $.post(ajaxUrl, {
                    action: 'es_shipping_sync_stock',
                    _ajax_nonce: '<?php echo esc_js( wp_create_nonce( 'es_stock_sync' ) ); ?>',
                    instance_id: instanceId
                }, function(response) {
                    $btn.prop('disabled', false).text('Sync Stock from ERPNext');
                    if (response.success) {
                        $status.text(response.data.message).css('color', '#00a32a');
                        setTimeout(function() { location.reload(); }, 1500);
                    } else {
                        $status.text(response.data || 'Sync failed.').css('color', '#d63638');
                    }
                }).fail(function() {
                    $btn.prop('disabled', false).text('Sync Stock from ERPNext');
                    $status.text('Request failed.').css('color', '#d63638');
                });
            });

            // ── Dynamic Locations ──
            var locations = <?php echo wp_json_encode( $locations ); ?>;
            var $container = $('#es-locations-container');

            function renderLocations() {
                $container.empty();
                if (locations.length === 0) {
                    $container.html('<p id="es-no-locations" style="color:#666; font-style:italic;">No locations configured. Click "Add Location" to add your first dispatch location.</p>');
                }
                locations.forEach(function(loc, idx) {
                    var whText = (loc.erp_warehouses || []).join('\n');
                    var locType = loc.type || 'warehouse';
                    var isCP = locType === 'collection_point';
                    var card = '<div class="es-location-card" data-index="' + idx + '">' +
                        '<h3>' +
                            '<input type="text" class="es-loc-name regular-text" value="' + escAttr(loc.name || '') + '" placeholder="Location name (e.g. Cape Town Warehouse)">' +
                            '<a href="#" class="es-remove-location">Remove</a>' +
                        '</h3>' +
                        '<div class="es-location-fields">' +
                            '<div class="es-full-width"><label>Location Type</label>' +
                            '<select class="es-loc-type">' +
                                '<option value="warehouse"' + (locType === 'warehouse' ? ' selected' : '') + '>Warehouse — ships orders, needs ERPNext mapping</option>' +
                                '<option value="collection_point"' + (isCP ? ' selected' : '') + '>Collection Point — pickup only, no warehouse mapping</option>' +
                            '</select></div>' +
                            '<div><label>Street</label><input type="text" class="es-loc-street" value="' + escAttr(loc.street || '') + '"></div>' +
                            '<div><label>Suburb</label><input type="text" class="es-loc-suburb" value="' + escAttr(loc.suburb || '') + '"></div>' +
                            '<div><label>City</label><input type="text" class="es-loc-city" value="' + escAttr(loc.city || '') + '"></div>' +
                            '<div><label>Province</label><input type="text" class="es-loc-province" value="' + escAttr(loc.province || '') + '"></div>' +
                            '<div><label>Postal Code</label><input type="text" class="es-loc-postcode" value="' + escAttr(loc.postcode || '') + '"></div>' +
                            '<div><label>Country</label><input type="text" class="es-loc-country" value="' + escAttr(loc.country || 'ZA') + '"></div>' +
                            '<div class="es-full-width es-warehouse-fields" style="' + (isCP ? 'display:none;' : '') + '"><label>ERPNext Warehouses (one per line)</label><textarea class="es-loc-warehouses" rows="3" placeholder="Main Warehouse - My Company&#10;Retail Shop - My Company">' + escAttr(whText) + '</textarea>' +
                            '<p class="description" style="margin-top:4px;">List all ERPNext warehouses that ship from this location. Stock from these warehouses will be combined when determining if this location can fulfill an order.</p></div>' +
                            '<div class="es-warehouse-fields" style="' + (isCP ? 'display:none;' : '') + '"><label>SLW Term ID (optional)</label><input type="number" class="es-loc-slw-term small-text" value="' + (loc.slw_term_id || '') + '" min="0" placeholder="Optional">' +
                            '<p class="description" style="margin-top:4px;">If using Stock Locations for WooCommerce, create the location there first, then copy its term ID here. Find it under Products &gt; Stock Locations.</p></div>' +
                            '<div style="padding-top:8px;"><label style="display:inline-flex; align-items:center; gap:6px; font-weight:normal;">' +
                            '<input type="checkbox" name="" class="es-pickup-enabled" ' + (loc.pickup_enabled || isCP ? 'checked' : '') + (isCP ? ' disabled' : '') + '> ' +
                            '<?php esc_html_e( "Available for pickup", "erpnext-shipping" ); ?>' +
                            '</label></div>' +
                            '<div class="es-full-width"><label>Customer Message (optional)</label><textarea class="es-loc-customer-msg" rows="2" placeholder="e.g. Allow 5 business days for delivery to this location">' + escAttr(loc.customer_message || '') + '</textarea>' +
                            '<p class="description" style="margin-top:4px;">Shown at checkout when this location is selected for pickup, and in the Ready for Pickup email.</p></div>' +
                        '</div>' +
                    '</div>';
                    $container.append(card);
                });
            }

            function collectLocations() {
                var result = [];
                $container.find('.es-location-card').each(function() {
                    var $card = $(this);
                    var idx = $card.data('index');
                    var name = $card.find('.es-loc-name').val().trim();
                    var locType = $card.find('.es-loc-type').val() || 'warehouse';
                    var whText = $card.find('.es-loc-warehouses').val();
                    var warehouses = whText ? whText.split('\n').map(function(s){ return s.trim(); }).filter(Boolean) : [];

                    result.push({
                        id: (locations[idx] && locations[idx].id) || '',
                        name: name,
                        type: locType,
                        street: $card.find('.es-loc-street').val().trim(),
                        suburb: $card.find('.es-loc-suburb').val().trim(),
                        city: $card.find('.es-loc-city').val().trim(),
                        province: $card.find('.es-loc-province').val().trim(),
                        postcode: $card.find('.es-loc-postcode').val().trim(),
                        country: $card.find('.es-loc-country').val().trim() || 'ZA',
                        erp_warehouses: locType === 'collection_point' ? [] : warehouses,
                        // Preserve slw_term_id even for collection points — the Zorem
                        // migration fallback maps old pickup orders via this field.
                        slw_term_id: parseInt($card.find('.es-loc-slw-term').val()) || (locations[idx] && locations[idx].slw_term_id) || 0,
                        pickup_enabled: locType === 'collection_point' ? true : $card.find('.es-pickup-enabled').is(':checked'),
                        customer_message: $card.find('.es-loc-customer-msg').val().trim(),
                    });
                });
                return result;
            }

            function escAttr(str) {
                return String(str).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
            }

            renderLocations();

            // Toggle warehouse fields when type changes.
            $container.on('change', '.es-loc-type', function() {
                var $card = $(this).closest('.es-location-card');
                var isCP = $(this).val() === 'collection_point';
                $card.find('.es-warehouse-fields').toggle(!isCP);
                $card.find('.es-pickup-enabled').prop('checked', isCP || $card.find('.es-pickup-enabled').is(':checked')).prop('disabled', isCP);
            });

            // Add location button.
            $('#es-add-location').on('click', function() {
                locations.push({
                    id: '',
                    name: '',
                    type: 'warehouse',
                    street: '',
                    suburb: '',
                    city: '',
                    province: '',
                    postcode: '',
                    country: 'ZA',
                    erp_warehouses: [],
                    slw_term_id: 0,
                });
                renderLocations();
                // Focus the new card's name field.
                $container.find('.es-location-card:last .es-loc-name').focus();
            });

            // Remove location.
            $container.on('click', '.es-remove-location', function(e) {
                e.preventDefault();
                var idx = $(this).closest('.es-location-card').data('index');
                locations.splice(idx, 1);
                renderLocations();
            });

            // Serialize locations to hidden input before form submit.
            $('form').on('submit', function() {
                locations = collectLocations();
                $('#es-locations-json').val(JSON.stringify(locations));
            });
        });
        </script>
        <?php
    }

    /**
     * Helper: render a text input row.
     */
    private function render_text_row( $name, $label, $value ) {
        ?>
        <tr>
            <th><label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label></th>
            <td><input type="text" name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $name ); ?>" class="regular-text" value="<?php echo esc_attr( $value ); ?>"></td>
        </tr>
        <?php
    }

    /**
     * Helper: render a password input row.
     */
    private function render_password_row( $name, $label, $value ) {
        ?>
        <tr>
            <th><label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label></th>
            <td><input type="password" name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $name ); ?>" class="regular-text" value="<?php echo esc_attr( $value ); ?>"></td>
        </tr>
        <?php
    }

    /**
     * Helper: render a number input row.
     */
    private function render_number_row( $name, $label, $value, $description = '' ) {
        ?>
        <tr>
            <th><label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label></th>
            <td>
                <input type="number" name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $name ); ?>" class="small-text" value="<?php echo esc_attr( $value ); ?>" step="any" min="0">
                <?php if ( $description ) : ?>
                    <p class="description"><?php echo esc_html( $description ); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }
}
