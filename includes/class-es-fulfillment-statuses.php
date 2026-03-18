<?php
defined( 'ABSPATH' ) || exit;

/**
 * Register custom order statuses for delivery and pickup flows.
 * Uses identical slugs to AST Pro and Zorem Local Pickup Pro for safe migration.
 */
class ES_Fulfillment_Statuses {

    /**
     * Custom statuses to register.
     * Key = slug (without wc- prefix), value = [label, color, show_in_reports].
     */
    private static $custom_statuses = array(
        'partially-shipped' => array(
            'label' => 'Partially Shipped',
            'color' => '#5b9bd5',
        ),
        'delivered' => array(
            'label' => 'Delivered',
            'color' => '#7ad03a',
        ),
        'processing-lp' => array(
            'label' => 'Processing LP',
            'color' => '#f0ad4e',
        ),
        'ready-pickup' => array(
            'label' => 'Ready For Pickup',
            'color' => '#ffba00',
        ),
        'pickup' => array(
            'label' => 'Picked Up',
            'color' => '#7ad03a',
        ),
    );

    public static function init() {
        // Register post statuses (legacy + HPOS).
        add_action( 'init', array( __CLASS__, 'register_statuses' ), 20 );

        // Add to WC status dropdown.
        add_filter( 'wc_order_statuses', array( __CLASS__, 'add_order_statuses' ) );

        // Bulk actions in order list.
        add_filter( 'bulk_actions-edit-shop_order', array( __CLASS__, 'add_bulk_actions' ) );
        add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( __CLASS__, 'add_bulk_actions' ) );

        // HPOS status registration.
        add_filter( 'woocommerce_register_shop_order_post_statuses', array( __CLASS__, 'register_hpos_statuses' ) );

        // Admin CSS for status colors.
        add_action( 'admin_head', array( __CLASS__, 'admin_status_css' ) );
    }

    public static function register_statuses() {
        foreach ( self::$custom_statuses as $slug => $data ) {
            register_post_status( 'wc-' . $slug, array(
                'label'                     => $data['label'],
                'public'                    => false,
                'exclude_from_search'       => false,
                'show_in_admin_all_list'    => true,
                'show_in_admin_status_list' => true,
                /* translators: %s: count */
                'label_count'               => _n_noop(
                    $data['label'] . ' <span class="count">(%s)</span>',
                    $data['label'] . ' <span class="count">(%s)</span>',
                    'erpnext-shipping'
                ),
            ) );
        }
    }

    public static function register_hpos_statuses( $statuses ) {
        foreach ( self::$custom_statuses as $slug => $data ) {
            $statuses[ 'wc-' . $slug ] = array(
                'label'                     => $data['label'],
                'public'                    => false,
                'exclude_from_search'       => false,
                'show_in_admin_all_list'    => true,
                'show_in_admin_status_list' => true,
                'label_count'               => _n_noop(
                    $data['label'] . ' <span class="count">(%s)</span>',
                    $data['label'] . ' <span class="count">(%s)</span>',
                    'erpnext-shipping'
                ),
            );
        }
        return $statuses;
    }

    public static function add_order_statuses( $statuses ) {
        // Rename "Completed" to "Shipped".
        if ( isset( $statuses['wc-completed'] ) ) {
            $statuses['wc-completed'] = _x( 'Shipped', 'Order status', 'erpnext-shipping' );
        }

        // Insert custom statuses after 'wc-completed'.
        $new_statuses = array();
        foreach ( $statuses as $key => $label ) {
            $new_statuses[ $key ] = $label;
            if ( 'wc-completed' === $key ) {
                $new_statuses['wc-partially-shipped'] = _x( 'Partially Shipped', 'Order status', 'erpnext-shipping' );
                $new_statuses['wc-delivered']         = _x( 'Delivered', 'Order status', 'erpnext-shipping' );
            }
            if ( 'wc-processing' === $key ) {
                $new_statuses['wc-processing-lp'] = _x( 'Processing LP', 'Order status', 'erpnext-shipping' );
                $new_statuses['wc-ready-pickup']  = _x( 'Ready For Pickup', 'Order status', 'erpnext-shipping' );
                $new_statuses['wc-pickup']        = _x( 'Picked Up', 'Order status', 'erpnext-shipping' );
            }
        }
        return $new_statuses;
    }

    public static function add_bulk_actions( $actions ) {
        foreach ( self::$custom_statuses as $slug => $data ) {
            // WC expects 'mark_{status-slug}' with hyphens, matching the slug used in register_post_status().
            $actions[ 'mark_' . $slug ] = sprintf(
                /* translators: %s: status label */
                __( 'Change status to %s', 'erpnext-shipping' ),
                $data['label']
            );
        }
        return $actions;
    }

    public static function admin_status_css() {
        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->id, array( 'edit-shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
            return;
        }
        echo '<style>';
        foreach ( self::$custom_statuses as $slug => $data ) {
            printf(
                '.order-status.status-%1$s { background: %2$s; color: #fff; }
                 mark.order-status.status-%1$s { background: %2$s; color: #fff; }
                ',
                esc_attr( $slug ),
                esc_attr( $data['color'] )
            );
        }
        echo '</style>';
    }

    /**
     * Get the list of custom statuses (for use by other classes).
     */
    public static function get_custom_statuses() {
        return self::$custom_statuses;
    }

    /**
     * Resolve pickup location ID — checks our meta first, then falls back to
     * Zorem Local Pickup Pro's meta for in-flight orders that existed before cutover.
     * If a Zorem ID is found, it is migrated to our meta key for future lookups.
     */
    public static function resolve_pickup_location( $order ) {
        $loc_id = $order->get_meta( '_es_pickup_location_id', true );
        if ( ! empty( $loc_id ) ) {
            return $loc_id;
        }

        // Zorem Local Pickup Pro stores location ID in these meta keys.
        $zorem_loc = $order->get_meta( 'alp_automation_location_id', true );
        if ( empty( $zorem_loc ) ) {
            $zorem_loc = $order->get_meta( 'alp_location_ids', true );
        }

        if ( ! empty( $zorem_loc ) ) {
            // Map Zorem location ID to our location ID.
            // Zorem uses WP term IDs; our locations have slw_term_id fields.
            $locations = get_option( 'es_shipping_locations', array() );
            foreach ( $locations as $loc ) {
                if ( ! empty( $loc['slw_term_id'] ) && (string) $loc['slw_term_id'] === (string) $zorem_loc ) {
                    // Persist migration so we don't repeat this lookup.
                    $order->update_meta_data( '_es_pickup_location_id', $loc['id'] );
                    $order->save();
                    return $loc['id'];
                }
            }
        }

        return '';
    }
}

ES_Fulfillment_Statuses::init();
