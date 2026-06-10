<?php
defined( 'ABSPATH' ) || exit;

/**
 * Warehouse Staff role: limited access for warehouse/fulfillment staff.
 * Can view orders, print packing slips, use fulfillment action buttons.
 * Cannot edit products, settings, coupons, plugins, or manage users.
 */
class ES_Warehouse_Role {

    const ROLE_SLUG = 'warehouse_staff';
    const ROLE_NAME = 'Warehouse Staff';

    /**
     * Register the role on plugin activation.
     */
    public static function register_role() {
        $existing = get_role( self::ROLE_SLUG );
        if ( $existing ) {
            // Update capabilities in case they changed.
            self::set_capabilities( $existing );
            return;
        }

        $role = add_role( self::ROLE_SLUG, self::ROLE_NAME, array() );
        if ( $role ) {
            self::set_capabilities( $role );
        }
    }

    /**
     * Remove the role on plugin deactivation (optional — keeps clean).
     */
    public static function remove_role() {
        // Don't remove if users still have this role.
        $users = get_users( array( 'role' => self::ROLE_SLUG, 'number' => 1 ) );
        if ( ! empty( $users ) ) {
            return;
        }
        remove_role( self::ROLE_SLUG );
    }

    /**
     * Set the capabilities for the warehouse staff role.
     */
    private static function set_capabilities( $role ) {
        // WordPress core — minimal.
        $role->add_cap( 'read' );

        // WooCommerce order capabilities (read-only + edit for status changes).
        $role->add_cap( 'edit_shop_orders' );
        $role->add_cap( 'read_shop_orders' );
        $role->add_cap( 'edit_others_shop_orders' );
        $role->add_cap( 'read_private_shop_orders' );

        // WooCommerce requires manage_woocommerce for admin panel access
        // and menu registration. We grant it here, then strip menu items
        // and block page access via restrict_admin_menu() and restrict_admin_pages().
        $role->add_cap( 'manage_woocommerce' );

        // Custom capability for our fulfillment AJAX actions.
        $role->add_cap( 'es_fulfillment_actions' );
    }

    /**
     * Check if current user can perform fulfillment actions.
     * Accepts both manage_woocommerce (admin/shop manager) and es_fulfillment_actions (warehouse staff).
     */
    public static function current_user_can_fulfill() {
        return current_user_can( 'manage_woocommerce' ) || current_user_can( 'es_fulfillment_actions' );
    }

    /**
     * Restrict admin menu for warehouse staff.
     * Called on admin_menu with late priority to remove items added by WC/plugins.
     */
    public static function restrict_admin_menu() {
        if ( ! self::is_warehouse_user() ) {
            return;
        }

        // Remove top-level menus.
        $remove_menus = array(
            'edit.php',              // Posts
            'upload.php',            // Media
            'edit.php?post_type=page', // Pages
            'edit-comments.php',     // Comments
            'themes.php',            // Appearance
            'plugins.php',           // Plugins
            'users.php',             // Users
            'tools.php',             // Tools
            'options-general.php',   // Settings
            'index.php',             // Dashboard
        );
        foreach ( $remove_menus as $menu ) {
            remove_menu_page( $menu );
        }

        // Remove WooCommerce sub-menus (keep only Orders).
        $remove_wc_submenus = array(
            'wc-admin',              // Home/Analytics
            'wc-reports',            // Reports
            'wc-settings',           // Settings
            'wc-status',             // Status
            'wc-addons',             // Extensions
            'es-shipping',           // Our plugin settings
            'edit.php?post_type=shop_coupon', // Coupons (legacy)
            'edit.php?post_type=product',     // Products
        );
        foreach ( $remove_wc_submenus as $submenu ) {
            remove_submenu_page( 'woocommerce', $submenu );
        }

        // Remove product-related menus.
        remove_menu_page( 'edit.php?post_type=product' );
        remove_menu_page( 'edit.php?post_type=shop_coupon' );
    }

    /**
     * Redirect warehouse staff to orders page on login.
     */
    public static function login_redirect( $redirect_to, $requested, $user ) {
        if ( ! is_wp_error( $user ) && in_array( self::ROLE_SLUG, $user->roles, true ) ) {
            return admin_url( self::get_orders_url() );
        }
        return $redirect_to;
    }

    /**
     * Get the correct orders list URL based on HPOS status.
     */
    private static function get_orders_url() {
        if ( class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' )
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
            return 'admin.php?page=wc-orders';
        }
        return 'edit.php?post_type=shop_order';
    }

    /**
     * Redirect warehouse staff away from restricted admin pages.
     */
    public static function restrict_admin_pages() {
        if ( ! self::is_warehouse_user() ) {
            return;
        }

        global $pagenow;

        // Always allow AJAX.
        if ( 'admin-ajax.php' === $pagenow ) {
            return;
        }

        // Allow profile page.
        if ( 'profile.php' === $pagenow ) {
            return;
        }

        // Allow admin.php for order-related pages (HPOS) only.
        if ( 'admin.php' === $pagenow ) {
            $page = sanitize_text_field( $_GET['page'] ?? '' );
            $allowed_pages = array( 'wc-orders' );
            if ( in_array( $page, $allowed_pages, true ) ) {
                return;
            }
            // Explicitly block settings pages even though they have manage_woocommerce.
            wp_safe_redirect( admin_url( self::get_orders_url() ) );
            exit;
        }

        // Allow legacy order screens (non-HPOS).
        if ( 'edit.php' === $pagenow ) {
            $post_type = sanitize_text_field( $_GET['post_type'] ?? '' );
            if ( 'shop_order' === $post_type ) {
                return;
            }
        }

        // Allow individual order edit (legacy).
        if ( 'post.php' === $pagenow ) {
            $post_id = intval( $_GET['post'] ?? 0 );
            if ( $post_id && 'shop_order' === get_post_type( $post_id ) ) {
                return;
            }
        }

        // Block everything else — redirect to orders.
        wp_safe_redirect( admin_url( self::get_orders_url() ) );
        exit;
    }

    /**
     * Hide the admin bar for warehouse staff on the frontend.
     */
    public static function maybe_hide_admin_bar( $show ) {
        if ( self::is_warehouse_user() ) {
            return false;
        }
        return $show;
    }

    /**
     * Check if current user is warehouse staff.
     */
    private static function is_warehouse_user() {
        $user = wp_get_current_user();
        return $user && in_array( self::ROLE_SLUG, $user->roles, true );
    }

    /**
     * Deny the WooCommerce REST API (wc/v3 etc.) to warehouse staff.
     *
     * The role carries `manage_woocommerce` only because WC requires it for
     * admin-screen access; menu/page restrictions don't apply to REST, which
     * would otherwise hand warehouse users the full WC API (products, coupons,
     * settings, all customer data). Warehouse staff have no REST use case —
     * the plugin's own tracking REST endpoints accept WC API keys instead.
     */
    public static function restrict_rest_api( $permission ) {
        if ( $permission && self::is_warehouse_user() ) {
            return false;
        }
        return $permission;
    }

    /**
     * Hook all restrictions.
     */
    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'restrict_admin_menu' ), 999 );
        add_action( 'admin_init', array( __CLASS__, 'restrict_admin_pages' ) );
        add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 10, 3 );
        add_filter( 'show_admin_bar', array( __CLASS__, 'maybe_hide_admin_bar' ) );
        add_filter( 'woocommerce_rest_check_permissions', array( __CLASS__, 'restrict_rest_api' ), 999 );
    }
}
