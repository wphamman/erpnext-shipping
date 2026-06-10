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
        'dispatched-pickup' => array(
            'label' => 'Dispatched to Pickup',
            'color' => '#5b9bd5',
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

        // Safety net for ALL status-change paths (bulk actions, order-edit
        // dropdown, third-party code): pickup statuses without a pickup
        // location are reverted. NOTE on ordering: WC fires the per-status
        // `woocommerce_order_status_{to}` actions (where our pickup emails
        // hook) BEFORE `woocommerce_order_status_changed` — that is safe here
        // because every pickup email's trigger() independently bails when no
        // pickup location resolves (the same condition this guard checks), so
        // nothing is sent before the revert. If that email-side bail is ever
        // removed, this hook must move to the per-status actions instead.
        add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'enforce_pickup_location' ), 4, 4 );

        // Record when an order entered its current status. The watchdog keys
        // staleness on this instead of date_modified, which every routine save
        // (e.g. the 15-min tracking poll) bumps — that previously meant the
        // ">N days in status" alerts and pickup reminders could never fire.
        add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'record_status_change' ), 5, 4 );
    }

    /**
     * Revert pickup-flow status changes made without a pickup location set.
     * The quick actions pre-check this with a friendly redirect; this guard
     * catches bulk actions and the order-edit dropdown, where the pickup email
     * would otherwise silently bail and the customer would never be notified.
     */
    public static function enforce_pickup_location( $order_id, $from, $to, $order ) {
        static $reverting = false;
        if ( $reverting || ! $order instanceof WC_Order ) {
            return;
        }
        if ( ! in_array( $to, array( 'dispatched-pickup', 'ready-pickup', 'pickup' ), true ) ) {
            return;
        }
        if ( self::resolve_pickup_location( $order ) ) {
            return;
        }
        $reverting = true;
        $order->update_status(
            $from,
            sprintf(
                /* translators: %s: attempted status */
                __( 'Status change to "%s" blocked: no pickup location set on this order. Set the pickup location first.', 'erpnext-shipping' ),
                $to
            )
        );
        $reverting = false;
    }

    /**
     * Stamp the time the order entered its current status.
     */
    public static function record_status_change( $order_id, $from, $to, $order ) {
        if ( ! $order instanceof WC_Order ) {
            return;
        }
        $order->update_meta_data( '_es_status_changed_at', time() );
        $order->save();
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
                $new_statuses['wc-processing-lp']     = _x( 'Processing LP', 'Order status', 'erpnext-shipping' );
                $new_statuses['wc-dispatched-pickup']  = _x( 'Dispatched to Pickup', 'Order status', 'erpnext-shipping' );
                $new_statuses['wc-ready-pickup']       = _x( 'Ready For Pickup', 'Order status', 'erpnext-shipping' );
                $new_statuses['wc-pickup']             = _x( 'Picked Up', 'Order status', 'erpnext-shipping' );
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
