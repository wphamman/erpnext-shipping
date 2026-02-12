<?php
/**
 * Plugin Name: ERPNext Shipping for WooCommerce
 * Description: Real-time multi-carrier shipping rates with ERPNext stock-based warehouse routing.
 * Version: 1.0.0
 * Author: ERPNext Shipping Contributors
 * Requires Plugins: woocommerce
 * Text Domain: erpnext-shipping
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

define( 'ES_SHIPPING_VERSION', '1.0.0' );
define( 'ES_SHIPPING_PATH', plugin_dir_path( __FILE__ ) );

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
    require_once ES_SHIPPING_PATH . 'includes/class-es-stock-sync.php';
    require_once ES_SHIPPING_PATH . 'includes/class-es-carrier-base.php';
    require_once ES_SHIPPING_PATH . 'includes/class-es-carrier-shiplogic.php';
    require_once ES_SHIPPING_PATH . 'includes/class-es-carrier-collivery.php';
    require_once ES_SHIPPING_PATH . 'includes/class-es-shipping-method.php';

    add_filter( 'woocommerce_shipping_methods', 'es_shipping_add_method' );
}
add_action( 'woocommerce_shipping_init', 'es_shipping_init' );

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
 * WP Cron: schedule ERPNext stock sync every 15 minutes.
 */
function es_shipping_activate() {
    if ( ! wp_next_scheduled( 'es_shipping_stock_sync' ) ) {
        wp_schedule_event( time(), 'es_every_15_min', 'es_shipping_stock_sync' );
    }
}
register_activation_hook( __FILE__, 'es_shipping_activate' );

function es_shipping_deactivate() {
    wp_clear_scheduled_hook( 'es_shipping_stock_sync' );
}
register_deactivation_hook( __FILE__, 'es_shipping_deactivate' );

// Hook the sync action.
add_action( 'es_shipping_stock_sync', function () {
    if ( ! class_exists( 'ES_Stock_Sync' ) ) {
        return;
    }
    $method = ES_Shipping_Method::get_instance();
    if ( ! $method ) {
        return;
    }
    $sync = new ES_Stock_Sync( $method->get_erp_settings() );
    $sync->sync();
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
        wp_send_json_error( 'Sync failed — check ERPNext URL and credentials.' );
    }

    wp_send_json_success( array(
        'count'   => $count,
        'message' => sprintf( 'Synced %d items from ERPNext.', $count ),
    ) );
} );
