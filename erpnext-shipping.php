<?php
/**
 * Plugin Name: ERPNext Shipping for WooCommerce
 * Description: Real-time multi-carrier shipping rates with ERPNext stock-based warehouse routing.
 * Version: 1.12.14
 * Author: ERPNext Shipping Contributors
 * Requires Plugins: woocommerce
 * Requires at least: 6.0
 * Tested up to: 7.0
 * Requires PHP: 8.0
 * WC requires at least: 8.0
 * WC tested up to: 10.8
 * Text Domain: erpnext-shipping
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

define( 'ES_SHIPPING_VERSION', '1.12.14' );
define( 'ES_SHIPPING_PATH', plugin_dir_path( __FILE__ ) );

// TCG Locker (PUDO) — sandbox API base default. Origin is derived by stripping
// the terminal /api/v1. Production must be set explicitly (see docs/tcg-locker-architecture.md).
define( 'ES_TCG_LOCKER_SANDBOX_BASE', 'https://sandbox.api-pudo.co.za/api/v1' );

// Isolated TCG Locker API client. No side effects at load; required early so
// both the admin settings screen and the shipping-method flow can use it.
require_once ES_SHIPPING_PATH . 'includes/class-es-tcg-locker-client.php';

// Pure TCG Locker packer (locker eligibility + smallest-box selection). No side
// effects; not wired into checkout until Phase 3.
require_once ES_SHIPPING_PATH . 'includes/class-es-tcg-locker-packer.php';

// Declare HPOS (High-Performance Order Storage) compatibility. The plugin already
// uses HPOS-safe order APIs (wc_get_order, $order->get_meta, feature-detected order
// list columns); this declaration stops WooCommerce flagging it as "incompatible"
// on the Settings > Advanced > Features screen and keeps HPOS enabled.
// NOTE: Cart/Checkout Blocks compatibility is intentionally NOT declared — the
// pickup-location selector relies on classic checkout hooks and would not render in
// the Checkout block. Declaring it would hide a warning while the feature still broke.
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

// Add custom 15-minute cron interval (registered early so activation hook can use it).
add_filter( 'cron_schedules', function ( $schedules ) {
    $schedules['es_every_15_min'] = array(
        'interval' => 900,
        'display'  => __( 'Every 15 Minutes', 'erpnext-shipping' ),
    );
    return $schedules;
} );

// Admin settings page (under WooCommerce menu).
if ( is_admin() ) {
    require_once ES_SHIPPING_PATH . 'includes/class-es-admin-page.php';
    new ES_Admin_Page();
}

// Add "Settings" link on the Plugins page.
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
    $url = admin_url( 'admin.php?page=es-shipping' );
    array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . __( 'Settings', 'erpnext-shipping' ) . '</a>' );
    return $links;
} );

/**
 * Check WooCommerce is active before loading.
 */
function es_shipping_init() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return;
    }

    require_once ES_SHIPPING_PATH . 'includes/class-es-rate-cache.php';
    require_once ES_SHIPPING_PATH . 'includes/class-es-parcel-estimator.php';
    require_once ES_SHIPPING_PATH . 'includes/class-es-erpnext-client.php';
    require_once ES_SHIPPING_PATH . 'includes/class-es-quote-log.php';
    require_once ES_SHIPPING_PATH . 'includes/class-es-stock-sync.php';
    require_once ES_SHIPPING_PATH . 'includes/class-es-carrier-base.php';
    require_once ES_SHIPPING_PATH . 'includes/class-es-carrier-shiplogic.php';
    require_once ES_SHIPPING_PATH . 'includes/class-es-carrier-collivery.php';
    require_once ES_SHIPPING_PATH . 'includes/class-es-shipping-method.php';

    add_filter( 'woocommerce_shipping_methods', 'es_shipping_add_method' );
}
add_action( 'woocommerce_shipping_init', 'es_shipping_init' );

/**
 * Read TCG Locker settings from the ES shipping-method instances (settings live
 * in woocommerce_erpnext_shipping_{id}_settings).
 *
 * Deterministic instance contract: rows are ordered by option_name, and the
 * FIRST instance with tcg_locker_enabled === 'yes' wins. This prevents a
 * disabled instance that merely happens to sort first from silently shadowing a
 * genuinely-configured, enabled one. When no instance is enabled, the first
 * instance (by option_name) is returned so the settings screen still reads a
 * stable value (the factory will then see enabled=no and return null).
 *
 * @return array Settings array, or empty array when no instance exists.
 */
function es_tcg_locker_settings() {
    global $wpdb;
    $rows = $wpdb->get_col(
        "SELECT option_value FROM {$wpdb->options}
         WHERE option_name LIKE 'woocommerce_erpnext_shipping_%_settings'
         ORDER BY option_name ASC"
    );
    $first_any = array();
    foreach ( (array) $rows as $val ) {
        $opts = maybe_unserialize( $val );
        if ( ! is_array( $opts ) ) {
            continue;
        }
        if ( empty( $first_any ) ) {
            $first_any = $opts;
        }
        if ( 'yes' === ( $opts['tcg_locker_enabled'] ?? 'no' ) ) {
            return $opts; // First enabled instance (deterministic by option_name).
        }
    }
    return $first_any;
}

/**
 * Construct a configured TCG Locker client, or null when the feature is disabled
 * or not validly configured. Default-disabled: returns null unless
 * tcg_locker_enabled === 'yes' with a valid API base and a token.
 *
 * Nothing in Phase 1 calls this at runtime — checkout/quote/booking wiring lands
 * in later phases. This is the default-disabled construction seam only.
 *
 * @param array|null $opts Optional pre-read settings (avoids a DB read).
 * @return ES_TCG_Locker_Client|null
 */
function es_tcg_locker_client( $opts = null ) {
    if ( null === $opts ) {
        $opts = es_tcg_locker_settings();
    }
    if ( 'yes' !== ( $opts['tcg_locker_enabled'] ?? 'no' ) ) {
        return null;
    }
    $base  = $opts['tcg_locker_api_url'] ?? '';
    $token = $opts['tcg_locker_api_token'] ?? '';
    if ( '' === (string) $token || ! ES_TCG_Locker_Client::is_valid_api_base( $base ) ) {
        return null;
    }
    $timeout = (int) ( $opts['tcg_locker_rate_timeout'] ?? ES_TCG_Locker_Client::DEFAULT_TIMEOUT );
    $logger  = function ( $level, $message ) {
        if ( function_exists( 'wc_get_logger' ) ) {
            wc_get_logger()->log( $level, $message, array( 'source' => 'erpnext-shipping-tcg-locker' ) );
        }
    };
    return new ES_TCG_Locker_Client(
        $base,
        $token,
        new ES_TCG_Locker_WP_Transport(),
        new ES_TCG_Locker_Transient_Cache(),
        $logger,
        $timeout
    );
}

/**
 * Fulfillment module — loads on init (not shipping_init).
 * Statuses always load. Other components only load in 'active' mode.
 */
add_action( 'init', 'es_fulfillment_init' );
function es_fulfillment_init() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return;
    }

    require_once ES_SHIPPING_PATH . 'includes/class-es-fulfillment-statuses.php';

    $mode = get_option( 'es_fulfillment_mode', 'migration' );
    if ( 'active' === $mode ) {
        require_once ES_SHIPPING_PATH . 'includes/class-es-fulfillment-tracking.php';
        require_once ES_SHIPPING_PATH . 'includes/class-es-fulfillment-admin.php';
        require_once ES_SHIPPING_PATH . 'includes/class-es-fulfillment-cron.php';
        require_once ES_SHIPPING_PATH . 'includes/class-es-fulfillment-checkout.php';
        require_once ES_SHIPPING_PATH . 'includes/class-es-fulfillment-watchdog.php';
        require_once ES_SHIPPING_PATH . 'includes/class-es-order-assignment.php';

        // v1.12.0: cross-system admin UX + diagnostics. Depend on tracking + cron.
        require_once ES_SHIPPING_PATH . 'includes/class-es-erpnext-client.php';
        require_once ES_SHIPPING_PATH . 'includes/class-es-quote-log.php';
        require_once ES_SHIPPING_PATH . 'includes/class-es-order-actions.php';

        // v1.12.9: the force-sync now runs as an async Action Scheduler job so it
        // can't hit the 30s PHP time limit. Its callback must be registered in ALL
        // contexts — Action Scheduler runs the queue from cron/loopback where
        // is_admin() is false — so register it here, outside the admin gate.
        add_action( 'es_force_sync_job', array( 'ES_Order_Actions', 'run_force_sync_job' ), 10, 1 );

        if ( is_admin() ) {
            require_once ES_SHIPPING_PATH . 'includes/class-es-diagnostics-page.php';
            ES_Order_Actions::init();
            ES_Diagnostics_Page::init();
        }
    }

    // Warehouse role restrictions run in both modes (role has caps regardless of mode).
    require_once ES_SHIPPING_PATH . 'includes/class-es-warehouse-role.php';
    ES_Warehouse_Role::init();
}

/**
 * Register fulfillment email classes with WooCommerce.
 * Only loads in 'active' mode.
 */
add_filter( 'woocommerce_email_classes', function ( $emails ) {
    $mode = get_option( 'es_fulfillment_mode', 'migration' );
    if ( 'active' !== $mode ) {
        return $emails;
    }

    require_once ES_SHIPPING_PATH . 'includes/class-es-email-partially-shipped.php';
    require_once ES_SHIPPING_PATH . 'includes/class-es-email-order-delivered.php';
    require_once ES_SHIPPING_PATH . 'includes/class-es-email-processing-lp.php';
    require_once ES_SHIPPING_PATH . 'includes/class-es-email-dispatched-pickup.php';
    require_once ES_SHIPPING_PATH . 'includes/class-es-email-ready-pickup.php';
    require_once ES_SHIPPING_PATH . 'includes/class-es-email-picked-up.php';
    require_once ES_SHIPPING_PATH . 'includes/class-es-email-pickup-reminder.php';

    $emails['ES_Email_Partially_Shipped']  = new ES_Email_Partially_Shipped();
    $emails['ES_Email_Order_Delivered']    = new ES_Email_Order_Delivered();
    $emails['ES_Email_Processing_LP']     = new ES_Email_Processing_LP();
    $emails['ES_Email_Dispatched_Pickup'] = new ES_Email_Dispatched_Pickup();
    $emails['ES_Email_Ready_Pickup']      = new ES_Email_Ready_Pickup();
    $emails['ES_Email_Picked_Up']         = new ES_Email_Picked_Up();
    $emails['ES_Email_Pickup_Reminder']   = new ES_Email_Pickup_Reminder();

    return $emails;
} );

function es_shipping_add_method( $methods ) {
    $methods['erpnext_shipping'] = 'ES_Shipping_Method';
    return $methods;
}

// Rename the "Shipment 1" heading to "Shipping Options" at checkout.
add_filter( 'woocommerce_shipping_package_name', function () {
    return __( 'Shipping Options', 'erpnext-shipping' );
} );

// Hide SLW's redundant "X available at Location" text below the location dropdown on product pages.
add_action( 'wp_head', function () {
    if ( ! is_product() ) {
        return;
    }
    echo '<style>.single-product div.stock-msg { display: none !important; }</style>' . "\n";
} );

/**
 * Smart free shipping logic:
 * - Remove free shipping for split shipments (multiple warehouses = dual shipping cost).
 * - Hide cheapest carrier rate when free shipping is available (single package only).
 *
 * Adapts to the "Free Shipping Source" setting:
 * - "wc_method": looks for WC's native Free Shipping zone method (method_id = free_shipping).
 * - "plugin": looks for the plugin's own free rate (rate_id ending in _free).
 */
add_filter( 'woocommerce_package_rates', function ( $rates, $package ) {
    // Read plugin settings from the first ES instance found in rates.
    $free_source      = 'wc_method';
    $excluded_classes = array();
    $instance_found   = false;
    foreach ( $rates as $rate ) {
        if ( 'erpnext_shipping' === $rate->method_id ) {
            $instance_found = true;
            $instance_id = $rate->instance_id;
            $opts = get_option( 'woocommerce_erpnext_shipping_' . $instance_id . '_settings', array() );
            $free_source = $opts['free_shipping_source'] ?? 'wc_method';
            // Parse comma-separated shipping class slugs.
            $raw = trim( $opts['no_free_shipping_classes'] ?? '' );
            if ( $raw !== '' ) {
                $excluded_classes = array_map( 'trim', explode( ',', $raw ) );
            }
            break;
        }
    }

    // No ES rate present (carriers down, empty result, fallback disabled): the
    // heavy-class free-shipping exclusion must NOT fail open. Read the settings
    // straight from the first configured instance instead.
    if ( ! $instance_found ) {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
                'woocommerce_erpnext_shipping_%_settings'
            )
        );
        foreach ( (array) $rows as $row ) {
            $opts = maybe_unserialize( $row->option_value );
            if ( ! is_array( $opts ) || ( ! isset( $opts['free_shipping_source'] ) && ! isset( $opts['no_free_shipping_classes'] ) ) ) {
                continue;
            }
            $free_source = $opts['free_shipping_source'] ?? 'wc_method';
            $raw = trim( $opts['no_free_shipping_classes'] ?? '' );
            if ( $raw !== '' ) {
                $excluded_classes = array_map( 'trim', explode( ',', $raw ) );
            }
            break;
        }
    }

    // Helper: check if a rate is the "free shipping" rate based on the configured source.
    $is_free_rate = function ( $rate_id, $rate ) use ( $free_source ) {
        if ( 'wc_method' === $free_source ) {
            return 'free_shipping' === $rate->method_id;
        }
        // Plugin source: check for our own _free rate.
        return 'erpnext_shipping' === $rate->method_id && substr( $rate_id, -5 ) === '_free';
    };

    // 1. Detect split shipments.
    $is_split = false;

    // Check rate metadata for split flag (locale-safe, set by calculate_shipping).
    foreach ( $rates as $rate ) {
        if ( 'erpnext_shipping' === $rate->method_id ) {
            $meta = $rate->get_meta_data();
            foreach ( $meta as $key => $value ) {
                if ( '_es_is_split' === $key && '1' === $value ) {
                    $is_split = true;
                    break 2;
                }
            }
        }
    }

    // Fallback: check cart items for different SLW locations.
    if ( ! $is_split && WC()->cart ) {
        $locations = array();
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            if ( isset( $cart_item['stock_location'] ) && $cart_item['stock_location'] > 0 ) {
                $locations[] = $cart_item['stock_location'];
            }
        }
        if ( count( array_unique( $locations ) ) > 1 ) {
            $is_split = true;
        }
    }

    // 2. Remove free shipping for split shipments (protect margins).
    if ( $is_split ) {
        $had_free = false;
        foreach ( $rates as $rate_id => $rate ) {
            if ( $is_free_rate( $rate_id, $rate ) ) {
                $had_free = true;
                unset( $rates[ $rate_id ] );
            }
        }

        if ( $had_free && WC()->session && ! WC()->session->get( 'es_split_notice_shown' ) ) {
            wc_add_notice(
                __( 'Free shipping is only available for orders shipped from one location. Your order requires shipment from multiple warehouses.', 'erpnext-shipping' ),
                'notice'
            );
            WC()->session->set( 'es_split_notice_shown', true );
        }

        return $rates;
    }

    // Clear the notice flag for non-split orders.
    if ( WC()->session ) {
        WC()->session->set( 'es_split_notice_shown', false );
    }

    // 3. Remove free shipping if cart contains items in excluded shipping classes (e.g. "Heavy").
    if ( ! empty( $excluded_classes ) ) {
        foreach ( $package['contents'] as $item ) {
            $product = $item['data'];
            if ( $product && in_array( $product->get_shipping_class(), $excluded_classes, true ) ) {
                foreach ( $rates as $rate_id => $rate ) {
                    if ( $is_free_rate( $rate_id, $rate ) ) {
                        unset( $rates[ $rate_id ] );
                    }
                }
                if ( WC()->session && ! WC()->session->get( 'es_heavy_notice_shown' ) ) {
                    wc_add_notice(
                        __( 'Free shipping is not available for orders containing heavy items. Standard shipping rates apply.', 'erpnext-shipping' ),
                        'notice'
                    );
                    WC()->session->set( 'es_heavy_notice_shown', true );
                }
                return $rates;
            }
        }
        // Clear the notice flag if no excluded items.
        if ( WC()->session ) {
            WC()->session->set( 'es_heavy_notice_shown', false );
        }
    }

    // 4. For single-package orders: hide cheapest carrier rate when free shipping is available.
    $has_free = false;
    foreach ( $rates as $rate_id => $rate ) {
        if ( $is_free_rate( $rate_id, $rate ) ) {
            $has_free = true;
            break;
        }
    }

    if ( ! $has_free ) {
        return $rates;
    }

    // Find all carrier rates (excluding free, locker, and ALL own-vehicle/bulk-delivery
    // rates — including the zero-cost `_bulk_delivery_quote` rate, which previously
    // matched neither suffix check and, costing 0, was always removed as "cheapest").
    $carrier_rates = array();
    foreach ( $rates as $rate_id => $rate ) {
        if ( 'erpnext_shipping' === $rate->method_id && ! $is_free_rate( $rate_id, $rate ) && substr( $rate_id, -7 ) !== '_locker' && false === strpos( $rate_id, '_bulk_delivery' ) ) {
            $carrier_rates[ $rate_id ] = $rate;
        }
    }

    if ( empty( $carrier_rates ) ) {
        return $rates;
    }

    // Find and remove the cheapest carrier rate (free shipping replaces it).
    $cheapest_id   = null;
    $cheapest_cost = PHP_FLOAT_MAX;
    foreach ( $carrier_rates as $rate_id => $rate ) {
        if ( $rate->cost < $cheapest_cost ) {
            $cheapest_cost = $rate->cost;
            $cheapest_id   = $rate_id;
        }
    }

    if ( $cheapest_id ) {
        unset( $rates[ $cheapest_id ] );
    }

    return $rates;
}, 100, 2 );

/**
 * Auto-update cart shipping when warehouse location is changed.
 * Triggers cart update so free shipping / split logic recalculates properly.
 */
add_action( 'wp_footer', function () {
    if ( ! is_cart() ) {
        return;
    }
    ?>
    <script>
    jQuery(function($) {
        var justReloaded = sessionStorage.getItem('es_shipping_just_reloaded');
        if (justReloaded) {
            sessionStorage.removeItem('es_shipping_just_reloaded');
            setTimeout(function() { attachLocationChangeHandler(); }, 2000);
        } else {
            attachLocationChangeHandler();
        }

        function attachLocationChangeHandler() {
            $(document.body).on('change', 'select.slw_cart_item_stock_location_selection', function() {
                var $select = $(this);
                $select.prop('disabled', true);
                if (!$('.es-shipping-updating').length) {
                    $select.after('<span class="es-shipping-updating" style="margin-left:10px;color:#999;">Updating shipping...</span>');
                }
                sessionStorage.setItem('es_shipping_just_reloaded', '1');
                $.ajax({
                    url: wc_cart_params.wc_ajax_url.toString().replace('%%endpoint%%', 'update_shipping_method'),
                    type: 'POST',
                    data: {
                        security: wc_cart_params.update_shipping_method_nonce,
                        shipping_method: []
                    },
                    complete: function() {
                        window.location.reload();
                    }
                });
            });
        }

        $(document.body).on('removed_from_cart', function() {
            // Page will auto-reload after item removal.
        });
    });
    </script>
    <?php
} );

/**
 * Smart "Continue Shopping" button — returns to last browsed category/product page.
 */
add_action( 'template_redirect', function () {
    if ( ( is_shop() || is_product_category() || is_product_tag() || is_product() ) && ! is_cart() && ! is_checkout() ) {
        $current_url = add_query_arg( null, null );
        if ( $current_url && WC()->session ) {
            WC()->session->set( 'es_shop_referrer', $current_url );
        }
    }
} );

add_filter( 'woocommerce_return_to_shop_redirect', function ( $url ) {
    if ( WC()->session ) {
        $referrer = WC()->session->get( 'es_shop_referrer' );
        if ( $referrer ) {
            return $referrer;
        }
    }
    return $url;
} );

/**
 * WP Cron: schedule ERPNext stock sync every 15 minutes.
 */
function es_shipping_activate() {
    if ( ! wp_next_scheduled( 'es_shipping_stock_sync' ) ) {
        wp_schedule_event( time(), 'es_every_15_min', 'es_shipping_stock_sync' );
    }
    // Fulfillment cron + AST compat option (only if active mode).
    $mode = get_option( 'es_fulfillment_mode', 'migration' );
    if ( 'active' === $mode ) {
        if ( ! wp_next_scheduled( 'es_fulfillment_tracking_poll' ) ) {
            wp_schedule_event( time(), 'es_every_15_min', 'es_fulfillment_tracking_poll' );
        }
        if ( ! wp_next_scheduled( 'es_fulfillment_watchdog' ) ) {
            wp_schedule_event( time(), 'daily', 'es_fulfillment_watchdog' );
        }
        update_option( 'wc_plugin_advanced_shipment_tracking', 'yes' );
    }

    // Register warehouse staff role.
    if ( ! class_exists( 'ES_Warehouse_Role' ) ) {
        require_once ES_SHIPPING_PATH . 'includes/class-es-warehouse-role.php';
    }
    ES_Warehouse_Role::register_role();
}
register_activation_hook( __FILE__, 'es_shipping_activate' );

function es_shipping_deactivate() {
    wp_clear_scheduled_hook( 'es_shipping_stock_sync' );
    wp_clear_scheduled_hook( 'es_fulfillment_tracking_poll' );
    wp_clear_scheduled_hook( 'es_fulfillment_watchdog' );
}
register_deactivation_hook( __FILE__, 'es_shipping_deactivate' );

// Self-healing: re-schedule cron if it went missing.
add_action( 'admin_init', function () {
    // Stock sync — always scheduled.
    if ( ! wp_next_scheduled( 'es_shipping_stock_sync' ) ) {
        wp_schedule_event( time(), 'es_every_15_min', 'es_shipping_stock_sync' );
    }

    // Fulfillment cron — managed by mode.
    $mode = get_option( 'es_fulfillment_mode', 'migration' );
    if ( 'active' === $mode ) {
        if ( ! wp_next_scheduled( 'es_fulfillment_tracking_poll' ) ) {
            wp_schedule_event( time(), 'es_every_15_min', 'es_fulfillment_tracking_poll' );
        }
        if ( ! wp_next_scheduled( 'es_fulfillment_watchdog' ) ) {
            wp_schedule_event( time(), 'daily', 'es_fulfillment_watchdog' );
        }
        update_option( 'wc_plugin_advanced_shipment_tracking', 'yes' );
    } else {
        // Migration mode — clear fulfillment crons if scheduled.
        if ( wp_next_scheduled( 'es_fulfillment_tracking_poll' ) ) {
            wp_clear_scheduled_hook( 'es_fulfillment_tracking_poll' );
        }
        if ( wp_next_scheduled( 'es_fulfillment_watchdog' ) ) {
            wp_clear_scheduled_hook( 'es_fulfillment_watchdog' );
        }
    }
} );

// Hook the sync action.
add_action( 'es_shipping_stock_sync', function () {
    // Load ES_Stock_Sync directly — woocommerce_shipping_init doesn't fire during WP-Cron
    if ( ! class_exists( 'ES_Stock_Sync' ) ) {
        require_once ES_SHIPPING_PATH . 'includes/class-es-stock-sync.php';
    }

    // Find all ERPNext shipping instances by querying wp_options
    global $wpdb;
    $option_pattern = 'woocommerce_erpnext_shipping_%_settings';
    $options        = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
            $option_pattern
        )
    );

    if ( empty( $options ) ) {
        error_log( '[ERPNext Shipping] ERROR: No ERPNext shipping instances found in wp_options' );
        return;
    }

    // Sync each instance
    foreach ( $options as $row ) {
        $opts = maybe_unserialize( $row->option_value );
        if ( ! is_array( $opts ) ) {
            continue;
        }

        preg_match( '/woocommerce_erpnext_shipping_(\d+)_settings/', $row->option_name, $matches );
        $instance_id = isset( $matches[1] ) ? intval( $matches[1] ) : 0;

        $settings = array(
            'url'        => rtrim( $opts['erp_url'] ?? '', '/' ),
            'api_key'    => $opts['erp_api_key'] ?? '',
            'api_secret' => $opts['erp_api_secret'] ?? '',
        );

        if ( empty( $settings['url'] ) || empty( $settings['api_key'] ) ) {
            error_log( '[ERPNext Shipping] WARNING: Instance ' . $instance_id . ' missing ERPNext credentials, skipping' );
            continue;
        }

        try {
            $sync = new ES_Stock_Sync( $settings );
            $sync->sync();
            // Success - no logging needed (runs every 15min, would spam logs)
        } catch ( Exception $e ) {
            error_log( '[ERPNext Shipping] ERROR: Instance ' . $instance_id . ' sync failed: ' . $e->getMessage() );
        }
    }
} );

// Hook the daily watchdog action.
add_action( 'es_fulfillment_watchdog', function () {
    if ( ! class_exists( 'ES_Fulfillment_Watchdog' ) ) {
        require_once ES_SHIPPING_PATH . 'includes/class-es-fulfillment-watchdog.php';
    }
    ES_Fulfillment_Watchdog::run();
} );

/**
 * True when any shipping-method instance has an ERPNext URL configured —
 * i.e. ERPNext is integrated as the stock source of truth.
 */
function es_erp_is_configured() {
    static $configured = null;
    if ( null !== $configured ) {
        return $configured;
    }
    global $wpdb;
    $configured = false;
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
            'woocommerce_erpnext_shipping_%_settings'
        )
    );
    foreach ( $rows as $row ) {
        $opts = maybe_unserialize( $row->option_value );
        if ( is_array( $opts ) && ! empty( $opts['erp_url'] ) ) {
            $configured = true;
            break;
        }
    }
    return $configured;
}

// When ERPNext owns stock, block WooCommerce's automatic stock restore on
// order cancel / payment failure. ERPNext releases the reservation on its
// side and the sync pushes the true figure; letting WC add units back
// corrupts stock until the next full sync (a cancelled order re-added units
// that only ever existed as an ERP reservation). Manual "restock items" on
// refunds is a different code path and still works. Escape hatch:
// add_filter( 'es_erp_owns_stock', '__return_false' ).
add_filter( 'woocommerce_can_restore_order_stock', function ( $can_restore, $order ) {
    if ( es_erp_is_configured() && apply_filters( 'es_erp_owns_stock', true, $order ) ) {
        return false;
    }
    return $can_restore;
}, 10, 2 );

// Hook the fulfillment tracking poll action.
add_action( 'es_fulfillment_tracking_poll', function () {
    if ( ! class_exists( 'ES_Fulfillment_Tracking' ) ) {
        require_once ES_SHIPPING_PATH . 'includes/class-es-fulfillment-tracking.php';
    }
    if ( ! class_exists( 'ES_Fulfillment_Cron' ) ) {
        require_once ES_SHIPPING_PATH . 'includes/class-es-fulfillment-cron.php';
    }
    ES_Fulfillment_Cron::poll();
} );

// AJAX: manual stock sync from admin settings.
add_action( 'wp_ajax_es_shipping_sync_stock', function () {
    check_ajax_referer( 'es_stock_sync' );

    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( 'Permission denied.' );
    }

    // Load ES_Stock_Sync directly — woocommerce_shipping_init may not have fired during AJAX.
    require_once ES_SHIPPING_PATH . 'includes/class-es-stock-sync.php';

    // Read ERPNext settings directly from WC instance options (avoids loading the full shipping method class).
    $instance_id = intval( $_POST['instance_id'] ?? 0 );
    $option_key  = 'woocommerce_erpnext_shipping_' . $instance_id . '_settings';
    $opts        = get_option( $option_key, array() );

    $settings = array(
        'url'        => rtrim( $opts['erp_url'] ?? '', '/' ),
        'api_key'    => $opts['erp_api_key'] ?? '',
        'api_secret' => $opts['erp_api_secret'] ?? '',
    );

    if ( empty( $settings['url'] ) || empty( $settings['api_key'] ) ) {
        wp_send_json_error( 'ERPNext credentials not configured. Check instance ' . $instance_id . '.' );
    }

    $sync  = new ES_Stock_Sync( $settings );
    $count = $sync->sync();

    if ( $count === false ) {
        wp_send_json_error( $sync->last_error ?: 'Sync failed — check ERPNext URL and credentials.' );
    }

    wp_send_json_success( array(
        'count'   => $count,
        'message' => sprintf( 'Synced %d items from ERPNext.', $count ),
    ) );
} );
