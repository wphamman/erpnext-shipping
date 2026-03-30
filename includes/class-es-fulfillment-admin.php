<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin UI for shipment tracking on order pages.
 * - Custom columns: Shipment Tracking, Shipment Status
 * - Flow-aware quick actions (Mark as Shipped, Ready for Pickup, Picked Up)
 * - Filter dropdowns (by provider, by shipment status)
 * - Meta box on order edit screen (HPOS + legacy)
 * - AJAX handlers for add/delete tracking, status updates, pickup location
 */
class ES_Fulfillment_Admin {

    /** Courier status labels for display. */
    private static $courier_status_labels = array(
        'tcg:created'          => 'Created',
        'tcg:collected'        => 'Collected',
        'tcg:in-transit'       => 'In Transit',
        'tcg:out-for-delivery' => 'Out For Delivery',
        'tcg:delivered'        => 'Delivered',
        'mds:1'                => 'Awaiting Collection',
        'mds:7'                => 'Collected',
        'mds:9'                => 'In Transit',
        'mds:8'                => 'Delivered',
        'mds:32'               => 'Completed',
    );

    public static function init() {
        // Meta box on order edit screen.
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );

        // AJAX handlers.
        add_action( 'wp_ajax_es_add_tracking', array( __CLASS__, 'ajax_add_tracking' ) );
        add_action( 'wp_ajax_es_delete_tracking', array( __CLASS__, 'ajax_delete_tracking' ) );
        add_action( 'wp_ajax_es_save_pickup_location', array( __CLASS__, 'ajax_save_pickup_location' ) );
        add_action( 'wp_ajax_es_update_order_status', array( __CLASS__, 'ajax_update_order_status' ) );

        // Flow-aware quick actions in order list.
        add_filter( 'woocommerce_admin_order_actions', array( __CLASS__, 'add_order_actions' ), 10, 2 );

        // Custom columns — HPOS and legacy.
        add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'add_order_columns' ) );
        add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'render_order_column' ), 10, 2 );
        add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_order_columns' ) );
        add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_order_column_legacy' ), 10, 2 );

        // Filter dropdowns.
        add_action( 'restrict_manage_posts', array( __CLASS__, 'render_filters_legacy' ) );
        add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( __CLASS__, 'render_filters_hpos' ) );
        add_filter( 'request', array( __CLASS__, 'apply_filters_legacy' ) );
        add_filter( 'woocommerce_shop_order_list_table_prepare_items_query_args', array( __CLASS__, 'apply_filters_hpos' ) );

        // Admin notice for pickup orders missing location.
        add_action( 'admin_notices', array( __CLASS__, 'pickup_location_notice' ) );

        // Pickup location display on order detail page.
        add_action( 'woocommerce_admin_order_data_after_shipping_address', array( __CLASS__, 'show_pickup_location_on_order' ) );

        // Packing slip AJAX handler.
        add_action( 'wp_ajax_es_packing_slip', array( __CLASS__, 'render_packing_slip' ) );

        // Admin CSS and JS.
        add_action( 'admin_head', array( __CLASS__, 'admin_css' ) );

        // Quick tracking modal on order list page.
        add_action( 'admin_footer', array( __CLASS__, 'render_tracking_modal' ) );
    }

    // ── Custom Columns ──

    public static function add_order_columns( $columns ) {
        $new_columns = array();
        foreach ( $columns as $key => $label ) {
            $new_columns[ $key ] = $label;
            // Insert after 'order_total' or 'order_status'.
            if ( 'order_total' === $key ) {
                $new_columns['es_ship_method']   = __( 'Shipping Method', 'erpnext-shipping' );
                $new_columns['es_customer_note'] = __( 'Customer Note', 'erpnext-shipping' );
                $new_columns['es_tracking']      = __( 'Shipment Tracking', 'erpnext-shipping' );
                $new_columns['es_status']        = __( 'Shipment Status', 'erpnext-shipping' );
            }
        }
        // Fallback if order_total wasn't found.
        if ( ! isset( $new_columns['es_tracking'] ) ) {
            $new_columns['es_ship_method']   = __( 'Shipping Method', 'erpnext-shipping' );
            $new_columns['es_customer_note'] = __( 'Customer Note', 'erpnext-shipping' );
            $new_columns['es_tracking']      = __( 'Shipment Tracking', 'erpnext-shipping' );
            $new_columns['es_status']        = __( 'Shipment Status', 'erpnext-shipping' );
        }
        return $new_columns;
    }

    /**
     * Render column content — HPOS (receives column name and order object).
     */
    public static function render_order_column( $column_name, $order ) {
        if ( 'es_ship_method' === $column_name ) {
            self::render_ship_method_column( $order );
        } elseif ( 'es_customer_note' === $column_name ) {
            self::render_customer_note_column( $order );
        } elseif ( 'es_tracking' === $column_name ) {
            self::render_tracking_column( $order );
        } elseif ( 'es_status' === $column_name ) {
            self::render_status_column( $order );
        }
    }

    /**
     * Render column content — Legacy (receives column name and post ID).
     */
    public static function render_order_column_legacy( $column_name, $post_id ) {
        if ( ! in_array( $column_name, array( 'es_ship_method', 'es_customer_note', 'es_tracking', 'es_status' ), true ) ) {
            return;
        }
        $order = wc_get_order( $post_id );
        if ( ! $order ) {
            echo '&ndash;';
            return;
        }
        if ( 'es_ship_method' === $column_name ) {
            self::render_ship_method_column( $order );
        } elseif ( 'es_customer_note' === $column_name ) {
            self::render_customer_note_column( $order );
        } elseif ( 'es_tracking' === $column_name ) {
            self::render_tracking_column( $order );
        } else {
            self::render_status_column( $order );
        }
    }

    private static function render_ship_method_column( $order ) {
        $methods = $order->get_shipping_methods();
        if ( empty( $methods ) ) {
            // Check if it's a pickup order (no shipping method).
            $pickup_statuses = array( 'processing-lp', 'dispatched-pickup', 'ready-pickup', 'pickup' );
            if ( in_array( $order->get_status(), $pickup_statuses, true ) ) {
                echo '<span style="color:#f0ad4e;">Local Pickup</span>';
            } else {
                echo '&ndash;';
            }
            return;
        }
        foreach ( $methods as $method ) {
            echo esc_html( $method->get_method_title() ) . '<br>';
        }
    }

    private static function render_customer_note_column( $order ) {
        $note = $order->get_customer_note();
        if ( empty( $note ) ) {
            echo '&ndash;';
            return;
        }
        $truncated = mb_strlen( $note ) > 50 ? mb_substr( $note, 0, 50 ) . '...' : $note;
        echo '<span class="es-customer-note" title="' . esc_attr( $note ) . '">' . esc_html( $truncated ) . '</span>';
    }

    private static function render_tracking_column( $order ) {
        $items = ES_Fulfillment_Tracking::get_tracking_items( $order );

        // For pickup orders without tracking, show the pickup location.
        $pickup_statuses = array( 'processing-lp', 'dispatched-pickup', 'ready-pickup', 'pickup' );
        if ( empty( $items ) && in_array( $order->get_status(), $pickup_statuses, true ) ) {
            $loc = self::resolve_pickup_location_for_display( $order );
            if ( $loc ) {
                echo '<span class="dashicons dashicons-location" style="font-size:14px; width:14px; height:14px; vertical-align:middle; color:#f0ad4e;"></span> ';
                echo esc_html( $loc['name'] );
                $city = $loc['city'] ?? '';
                if ( $city ) {
                    echo '<br><small style="color:#999;">' . esc_html( $city ) . '</small>';
                }
            } else {
                echo '<em style="color:#999;">' . esc_html__( 'No location set', 'erpnext-shipping' ) . '</em>';
            }
            return;
        }

        if ( empty( $items ) ) {
            echo '&ndash;';
            return;
        }
        foreach ( $items as $item ) {
            $provider_name = ES_Fulfillment_Tracking::get_provider_name( $item['tracking_provider'] );
            $url           = ES_Fulfillment_Tracking::get_tracking_url( $item );
            $date          = date_i18n( 'M j, Y', $item['date_shipped'] );

            echo '<div class="es-tracking-col-item">';
            echo '<strong>' . esc_html( $provider_name ) . '</strong><br>';
            if ( $url ) {
                echo '<a href="' . esc_url( $url ) . '" target="_blank">' . esc_html( $item['tracking_number'] ) . '</a>';
            } else {
                echo esc_html( $item['tracking_number'] );
            }
            echo '<br><small>' . esc_html( $date ) . '</small>';
            echo '</div>';
        }
    }

    private static function render_status_column( $order ) {
        $courier_status = $order->get_meta( '_es_courier_status', true );
        if ( empty( $courier_status ) ) {
            echo '&ndash;';
            return;
        }

        // May contain comma-separated statuses for multi-parcel orders.
        $statuses = array_map( 'trim', explode( ',', $courier_status ) );
        foreach ( $statuses as $raw ) {
            $label = self::$courier_status_labels[ $raw ] ?? $raw;
            $color = self::get_status_dot_color( $raw );
            echo '<span class="es-courier-badge" style="color:' . esc_attr( $color ) . ';">';
            echo '<span class="es-dot" style="background:' . esc_attr( $color ) . ';"></span> ';
            echo esc_html( $label );
            echo '</span><br>';
        }

        // Show last polled time.
        $last_polled = $order->get_meta( '_es_last_polled', true );
        if ( $last_polled ) {
            echo '<small style="color:#999;">' . esc_html( date_i18n( 'M j, g:i a', $last_polled ) ) . '</small>';
        }
    }

    private static function get_status_dot_color( $raw ) {
        if ( str_contains( $raw, 'delivered' ) || 'mds:8' === $raw || 'mds:32' === $raw ) {
            return '#7ad03a'; // Green.
        }
        if ( str_contains( $raw, 'transit' ) || str_contains( $raw, 'out-for' ) || 'mds:9' === $raw ) {
            return '#ffba00'; // Yellow/amber.
        }
        if ( str_contains( $raw, 'collected' ) || 'mds:7' === $raw ) {
            return '#5b9bd5'; // Blue.
        }
        return '#999'; // Grey for unknown/created.
    }

    // ── Flow-Aware Action Buttons ──

    public static function add_order_actions( $actions, $order ) {
        $status   = $order->get_status();
        $edit_url = $order->get_edit_order_url();

        // Remove WooCommerce core "Complete" action — we replace it with
        // flow-aware actions (Mark as Shipped, Ready for Pickup, etc.).
        unset( $actions['complete'] );

        // Delivery flow.
        if ( 'processing' === $status ) {
            $actions['es_mark_shipped'] = array(
                'url'    => wp_nonce_url(
                    admin_url( 'admin-ajax.php?action=es_update_order_status&order_id=' . $order->get_id() . '&new_status=completed' ),
                    'es_status_' . $order->get_id()
                ),
                'name'   => __( 'Mark as Shipped', 'erpnext-shipping' ),
                'action' => 'es_mark_shipped',
            );
            // Add tracking — opens inline modal on order list page.
            $actions['es_add_tracking'] = array(
                'url'    => '#',
                'name'   => __( 'Add tracking', 'erpnext-shipping' ),
                'action' => 'es_add_tracking',
            );
        }

        // Pickup flow.
        if ( 'processing-lp' === $status ) {
            $actions['es_dispatch_pickup'] = array(
                'url'    => wp_nonce_url(
                    admin_url( 'admin-ajax.php?action=es_update_order_status&order_id=' . $order->get_id() . '&new_status=dispatched-pickup' ),
                    'es_status_' . $order->get_id()
                ),
                'name'   => __( 'Dispatch to Pickup', 'erpnext-shipping' ),
                'action' => 'es_dispatch_pickup',
            );
        }

        if ( 'dispatched-pickup' === $status ) {
            $actions['es_ready_pickup'] = array(
                'url'    => wp_nonce_url(
                    admin_url( 'admin-ajax.php?action=es_update_order_status&order_id=' . $order->get_id() . '&new_status=ready-pickup' ),
                    'es_status_' . $order->get_id()
                ),
                'name'   => __( 'Ready for Pickup', 'erpnext-shipping' ),
                'action' => 'es_ready_pickup',
            );
        }

        if ( 'ready-pickup' === $status ) {
            $actions['es_picked_up'] = array(
                'url'    => wp_nonce_url(
                    admin_url( 'admin-ajax.php?action=es_update_order_status&order_id=' . $order->get_id() . '&new_status=pickup' ),
                    'es_status_' . $order->get_id()
                ),
                'name'   => __( 'Picked Up', 'erpnext-shipping' ),
                'action' => 'es_picked_up',
            );
        }

        // Shipped orders — link to tracking on order edit.
        if ( 'completed' === $status || 'partially-shipped' === $status ) {
            $actions['es_view_tracking'] = array(
                'url'    => $edit_url . '#es-shipment-tracking',
                'name'   => __( 'View tracking', 'erpnext-shipping' ),
                'action' => 'es_view_tracking',
            );
        }

        // Packing slip — available on all non-terminal orders.
        $terminal = array( 'cancelled', 'refunded', 'failed', 'trash' );
        if ( ! in_array( $status, $terminal, true ) ) {
            $actions['es_packing_slip'] = array(
                'url'    => wp_nonce_url(
                    admin_url( 'admin-ajax.php?action=es_packing_slip&order_id=' . $order->get_id() ),
                    'es_packing_' . $order->get_id()
                ),
                'name'   => __( 'Print Packing Slip', 'erpnext-shipping' ),
                'action' => 'es_packing_slip',
            );
        }

        return $actions;
    }

    /**
     * AJAX: Update order status from quick action buttons.
     */
    public static function ajax_update_order_status() {
        $order_id   = intval( $_GET['order_id'] ?? 0 );
        $new_status = sanitize_text_field( $_GET['new_status'] ?? '' );

        check_admin_referer( 'es_status_' . $order_id );

        if ( ! ES_Warehouse_Role::current_user_can_fulfill() ) {
            wp_die( 'Permission denied.' );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_die( 'Order not found.' );
        }

        $allowed = array( 'completed', 'dispatched-pickup', 'ready-pickup', 'pickup', 'delivered', 'partially-shipped' );
        if ( ! in_array( $new_status, $allowed, true ) ) {
            wp_die( 'Invalid status.' );
        }

        // Block pickup status changes if no pickup location is set — the email
        // class silently bails without a location, leaving the customer uninformed.
        if ( in_array( $new_status, array( 'dispatched-pickup', 'ready-pickup', 'pickup' ), true ) ) {
            $pickup_loc = ES_Fulfillment_Statuses::resolve_pickup_location( $order );
            if ( empty( $pickup_loc ) ) {
                // Redirect to order edit so admin can set the pickup location first.
                wp_safe_redirect( $order->get_edit_order_url() . '&es_notice=pickup_location_required' );
                exit;
            }
        }

        $labels = array(
            'completed'          => 'Shipped',
            'dispatched-pickup'  => 'Dispatched to Pickup',
            'ready-pickup'       => 'Ready for Pickup',
            'pickup'             => 'Picked Up',
            'delivered'          => 'Delivered',
            'partially-shipped'  => 'Partially Shipped',
        );

        $order->update_status(
            $new_status,
            sprintf( __( 'Status changed to %s via order list action.', 'erpnext-shipping' ), $labels[ $new_status ] ?? $new_status )
        );

        // Redirect back to orders list.
        wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=wc-orders' ) );
        exit;
    }

    // ── Filter Dropdowns ──

    /**
     * Render filter dropdowns — Legacy (CPT).
     */
    public static function render_filters_legacy( $post_type ) {
        if ( 'shop_order' !== $post_type ) {
            return;
        }
        self::render_filter_html();
    }

    /**
     * Render filter dropdowns — HPOS.
     */
    public static function render_filters_hpos() {
        self::render_filter_html();
    }

    private static function render_filter_html() {
        $current_provider = sanitize_text_field( $_GET['es_provider'] ?? '' );
        $current_status   = sanitize_text_field( $_GET['es_courier_status'] ?? '' );

        $providers = ES_Fulfillment_Tracking::get_providers();
        ?>
        <select name="es_provider">
            <option value=""><?php esc_html_e( 'Filter by shipping provider', 'erpnext-shipping' ); ?></option>
            <?php foreach ( $providers as $slug => $data ) : ?>
                <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current_provider, $slug ); ?>>
                    <?php echo esc_html( $data['name'] ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="es_courier_status">
            <option value=""><?php esc_html_e( 'Filter by shipment status', 'erpnext-shipping' ); ?></option>
            <option value="in-transit" <?php selected( $current_status, 'in-transit' ); ?>><?php esc_html_e( 'In Transit', 'erpnext-shipping' ); ?></option>
            <option value="out-for-delivery" <?php selected( $current_status, 'out-for-delivery' ); ?>><?php esc_html_e( 'Out For Delivery', 'erpnext-shipping' ); ?></option>
            <option value="delivered" <?php selected( $current_status, 'delivered' ); ?>><?php esc_html_e( 'Delivered', 'erpnext-shipping' ); ?></option>
            <option value="collected" <?php selected( $current_status, 'collected' ); ?>><?php esc_html_e( 'Collected', 'erpnext-shipping' ); ?></option>
            <option value="no-tracking" <?php selected( $current_status, 'no-tracking' ); ?>><?php esc_html_e( 'No Tracking', 'erpnext-shipping' ); ?></option>
        </select>
        <?php
    }

    /**
     * Apply filter query modifications — Legacy (CPT).
     */
    public static function apply_filters_legacy( $vars ) {
        global $typenow;
        if ( 'shop_order' !== $typenow ) {
            return $vars;
        }

        $provider = sanitize_text_field( $_GET['es_provider'] ?? '' );
        $status   = sanitize_text_field( $_GET['es_courier_status'] ?? '' );

        if ( ! empty( $provider ) || ! empty( $status ) ) {
            if ( ! isset( $vars['meta_query'] ) ) {
                $vars['meta_query'] = array();
            }

            if ( ! empty( $provider ) ) {
                $vars['meta_query'][] = array(
                    'key'     => '_wc_shipment_tracking_items',
                    'value'   => $provider,
                    'compare' => 'LIKE',
                );
            }

            if ( ! empty( $status ) ) {
                if ( 'no-tracking' === $status ) {
                    $vars['meta_query'][] = array(
                        'key'     => '_wc_shipment_tracking_items',
                        'compare' => 'NOT EXISTS',
                    );
                } else {
                    $vars['meta_query'][] = array(
                        'key'     => '_es_courier_status',
                        'value'   => $status,
                        'compare' => 'LIKE',
                    );
                }
            }
        }

        return $vars;
    }

    /**
     * Apply filter query modifications — HPOS.
     */
    public static function apply_filters_hpos( $args ) {
        $provider = sanitize_text_field( $_GET['es_provider'] ?? '' );
        $status   = sanitize_text_field( $_GET['es_courier_status'] ?? '' );

        if ( ! empty( $provider ) || ! empty( $status ) ) {
            if ( ! isset( $args['meta_query'] ) ) {
                $args['meta_query'] = array();
            }

            if ( ! empty( $provider ) ) {
                $args['meta_query'][] = array(
                    'key'     => '_wc_shipment_tracking_items',
                    'value'   => $provider,
                    'compare' => 'LIKE',
                );
            }

            if ( ! empty( $status ) ) {
                if ( 'no-tracking' === $status ) {
                    $args['meta_query'][] = array(
                        'key'     => '_wc_shipment_tracking_items',
                        'compare' => 'NOT EXISTS',
                    );
                } else {
                    $args['meta_query'][] = array(
                        'key'     => '_es_courier_status',
                        'value'   => $status,
                        'compare' => 'LIKE',
                    );
                }
            }
        }

        return $args;
    }

    // ── Admin CSS ──

    public static function admin_css() {
        $screen = get_current_screen();
        if ( ! $screen ) {
            return;
        }
        $order_screens = array( 'edit-shop_order', 'woocommerce_page_wc-orders' );
        if ( ! in_array( $screen->id, $order_screens, true ) ) {
            return;
        }
        ?>
        <style>
        /* Tracking column */
        .es-tracking-col-item { margin-bottom: 6px; line-height: 1.4; }
        .es-tracking-col-item:last-child { margin-bottom: 0; }

        /* Courier status badge */
        .es-courier-badge { font-size: 12px; line-height: 1.6; white-space: nowrap; }
        .es-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; vertical-align: middle; margin-right: 3px; }

        /* Customer note column */
        .es-customer-note { font-size: 12px; color: #666; line-height: 1.4; display: block; cursor: help; }

        /* Column widths */
        .column-es_ship_method { width: 130px; }
        .column-es_customer_note { width: 160px; }
        .column-es_tracking { width: 180px; }
        .column-es_status { width: 140px; }

        /* Action button icons — WC uses Dashicons via CSS classes */
        .wc-action-button-es_mark_shipped::after { font-family: Dashicons; content: "\f310" !important; }    /* truck */
        .wc-action-button-es_add_tracking::after { font-family: Dashicons; content: "\f179" !important; }    /* plus-alt */
        .wc-action-button-es_view_tracking::after { font-family: Dashicons; content: "\f177" !important; }   /* visibility */
        .wc-action-button-es_dispatch_pickup::after { font-family: Dashicons; content: "\f310" !important; } /* truck */
        .wc-action-button-es_ready_pickup::after { font-family: Dashicons; content: "\f513" !important; }    /* archive */
        .wc-action-button-es_picked_up::after { font-family: Dashicons; content: "\f147" !important; }       /* yes */

        /* Action button colors */
        .wc-action-button-es_mark_shipped { color: #5b9bd5 !important; }
        .wc-action-button-es_dispatch_pickup { color: #5b9bd5 !important; }
        .wc-action-button-es_ready_pickup { color: #ffba00 !important; }
        .wc-action-button-es_picked_up { color: #7ad03a !important; }
        .wc-action-button-es_add_tracking { color: #999 !important; }
        .wc-action-button-es_view_tracking { color: #5b9bd5 !important; }
        .wc-action-button-es_packing_slip::after { font-family: Dashicons; content: "\f464" !important; }  /* media-text */
        .wc-action-button-es_packing_slip { color: #666 !important; }

        /* Quick tracking modal */
        .es-modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:100000; }
        .es-modal-overlay.active { display:flex; align-items:center; justify-content:center; }
        .es-modal { background:#fff; border-radius:6px; padding:24px; width:380px; max-width:90vw; box-shadow:0 4px 20px rgba(0,0,0,.3); }
        .es-modal h3 { margin:0 0 16px; font-size:15px; }
        .es-modal label { display:block; font-weight:600; font-size:12px; margin:10px 0 4px; }
        .es-modal select, .es-modal input[type="text"] { width:100%; }
        .es-modal-actions { margin-top:16px; display:flex; gap:8px; justify-content:flex-end; }
        </style>
        <?php
    }

    // ── Quick Tracking Modal ──

    public static function render_tracking_modal() {
        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->id, array( 'edit-shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
            return;
        }
        $providers = ES_Fulfillment_Tracking::get_providers();
        ?>
        <div class="es-modal-overlay" id="es-tracking-modal">
            <div class="es-modal">
                <h3><?php esc_html_e( 'Add Tracking', 'erpnext-shipping' ); ?> — <span id="es-modal-order-label"></span></h3>
                <label><?php esc_html_e( 'Carrier', 'erpnext-shipping' ); ?></label>
                <select id="es-modal-provider">
                    <?php foreach ( $providers as $slug => $data ) : ?>
                        <option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $data['name'] ); ?></option>
                    <?php endforeach; ?>
                </select>
                <label><?php esc_html_e( 'Tracking Number', 'erpnext-shipping' ); ?></label>
                <input type="text" id="es-modal-tracking" placeholder="e.g. TCG100042593T">
                <div id="es-modal-waybill-warning" style="display:none; background:#fff3cd; border:1px solid #ffc107; border-radius:4px; padding:8px 10px; margin-top:6px; font-size:12px; color:#856404;"></div>
                <label><?php esc_html_e( 'Shipping Note (optional)', 'erpnext-shipping' ); ?></label>
                <input type="text" id="es-modal-note" placeholder="<?php esc_attr_e( 'Visible to customer', 'erpnext-shipping' ); ?>">
                <input type="hidden" id="es-modal-order-id" value="">
                <input type="hidden" id="es-modal-confirmed" value="0">
                <div class="es-modal-actions">
                    <button type="button" class="button" id="es-modal-cancel"><?php esc_html_e( 'Cancel', 'erpnext-shipping' ); ?></button>
                    <button type="button" class="button button-primary" id="es-modal-submit"><?php esc_html_e( 'Add + Ship', 'erpnext-shipping' ); ?></button>
                </div>
            </div>
        </div>
        <script>
        jQuery(function($) {
            var $modal = $('#es-tracking-modal');

            // Open modal when "Add tracking" action clicked.
            $(document).on('click', '.wc-action-button-es_add_tracking', function(e) {
                e.preventDefault();
                // Get order ID from the row — prefer data attributes over text content
                // (text may show a custom order number that differs from the WC order ID).
                var $row = $(this).closest('tr');
                var orderId = $row.data('id') || '';

                if (!orderId) {
                    var $link = $row.find('td.order_number a, td.column-order_number a, a.order-view').first();
                    var href = $link.length ? ($link.attr('href') || '') : '';
                    if (href) {
                        var m = href.match(/[?&]id=(\d+)/) || href.match(/post=(\d+)/);
                        orderId = m ? m[1] : '';
                    }
                }

                if (!orderId) {
                    orderId = $row.find('.order-view').text().replace('#', '').trim();
                }

                if (!orderId) {
                    alert('Could not determine order ID.');
                    return;
                }

                $('#es-modal-order-id').val(orderId);
                $('#es-modal-order-label').text('#' + orderId);
                $('#es-modal-tracking').val('');
                $('#es-modal-note').val('');
                $('#es-modal-confirmed').val('0');
                $('#es-modal-waybill-warning').hide();
                $('#es-modal-submit').text('<?php echo esc_js( __( 'Add + Ship', 'erpnext-shipping' ) ); ?>');
                $modal.addClass('active');
                setTimeout(function() { $('#es-modal-tracking').focus(); }, 50);
            });

            // Close modal.
            $('#es-modal-cancel').on('click', function() { $modal.removeClass('active'); });
            $modal.on('click', function(e) { if (e.target === this) $modal.removeClass('active'); });

            // Waybill validation — check carrier/number mismatch.
            function checkWaybillMismatch() {
                var provider = $('#es-modal-provider').val();
                var number = $('#es-modal-tracking').val().trim();
                var $warn = $('#es-modal-waybill-warning');
                var $btn = $('#es-modal-submit');
                var msg = '';

                if (number.length >= 3) {
                    var isAllDigits = /^\d+$/.test(number);
                    var isSevenDigits = /^\d{7}$/.test(number);

                    if (provider === 'the-courier-guy' && isSevenDigits) {
                        msg = '<?php echo esc_js( __( 'This looks like a Collivery waybill (7 digits). You selected The Courier Guy.', 'erpnext-shipping' ) ); ?>';
                    } else if (provider === 'mds-collivery' && !isAllDigits) {
                        msg = '<?php echo esc_js( __( 'This looks like a TCG waybill (contains letters). You selected Collivery.', 'erpnext-shipping' ) ); ?>';
                    }
                }

                if (msg) {
                    $warn.text(msg).show();
                    $btn.text('<?php echo esc_js( __( 'Confirm + Ship', 'erpnext-shipping' ) ); ?>');
                    $('#es-modal-confirmed').val('0');
                } else {
                    $warn.hide();
                    $btn.text('<?php echo esc_js( __( 'Add + Ship', 'erpnext-shipping' ) ); ?>');
                    $('#es-modal-confirmed').val('1');
                }
            }

            $('#es-modal-tracking').on('input', checkWaybillMismatch);
            $('#es-modal-provider').on('change', checkWaybillMismatch);

            // Submit tracking.
            $('#es-modal-submit').on('click', function() {
                var $btn = $(this);
                var number = $('#es-modal-tracking').val().trim();
                if (!number) { $('#es-modal-tracking').focus(); return; }

                // If there's a mismatch warning and user hasn't confirmed yet, require a second click.
                var $warn = $('#es-modal-waybill-warning');
                if ($warn.is(':visible') && $('#es-modal-confirmed').val() === '0') {
                    $('#es-modal-confirmed').val('1');
                    $btn.text('<?php echo esc_js( __( 'Yes, Confirm + Ship', 'erpnext-shipping' ) ); ?>');
                    return;
                }

                $btn.prop('disabled', true).text('<?php echo esc_js( __( 'Adding...', 'erpnext-shipping' ) ); ?>');

                var postData = {
                    action: 'es_add_tracking',
                    _ajax_nonce: '<?php echo wp_create_nonce( 'es_quick_tracking' ); ?>',
                    order_id: $('#es-modal-order-id').val(),
                    tracking_provider: $('#es-modal-provider').val(),
                    tracking_number: number,
                    date_shipped: '<?php echo esc_js( date( 'Y-m-d' ) ); ?>',
                    custom_tracking_link: '',
                    no_status_update: 0
                };

                var note = $('#es-modal-note').val().trim();
                if (note) {
                    postData.shipping_note = note;
                }

                $.post(ajaxurl, postData, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.data || 'Failed to add tracking.');
                        $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Add + Ship', 'erpnext-shipping' ) ); ?>');
                    }
                });
            });

            // Submit on Enter key.
            $('#es-modal-tracking').on('keypress', function(e) {
                if (e.which === 13) { e.preventDefault(); $('#es-modal-submit').click(); }
            });

            // Packing slip — open in new tab.
            $(document).on('click', '.wc-action-button-es_packing_slip', function(e) {
                e.preventDefault();
                window.open($(this).attr('href'), '_blank');
            });
        });
        </script>
        <?php
    }

    // ── Meta Box ──

    public static function add_meta_box() {
        $screen = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
            ? wc_get_page_screen_id( 'shop-order' )
            : 'shop_order';

        add_meta_box(
            'es-shipment-tracking',
            __( 'Shipment Tracking', 'erpnext-shipping' ),
            array( __CLASS__, 'render_meta_box' ),
            $screen,
            'side',
            'high'
        );
    }

    public static function render_meta_box( $post_or_order ) {
        $order = ( $post_or_order instanceof WP_Post )
            ? wc_get_order( $post_or_order->ID )
            : $post_or_order;

        if ( ! $order ) {
            return;
        }

        $items     = ES_Fulfillment_Tracking::get_tracking_items( $order );
        $providers = ES_Fulfillment_Tracking::get_providers();
        $order_id  = $order->get_id();
        $status    = $order->get_status();

        $pickup_statuses  = array( 'processing-lp', 'dispatched-pickup', 'ready-pickup', 'pickup' );
        $is_pickup_order  = in_array( $status, $pickup_statuses, true );

        wp_nonce_field( 'es_tracking_' . $order_id, 'es_tracking_nonce' );
        ?>

        <?php if ( $is_pickup_order ) : ?>
            <?php // ── PICKUP FLOW ── ?>
            <?php
            $locations       = get_option( 'es_shipping_locations', array() );
            $pickup_locations = array_filter( $locations, function( $loc ) {
                return ! empty( $loc['pickup_enabled'] );
            } );
            $current_pickup_loc = $order->get_meta( '_es_pickup_location_id', true );
            ?>

            <?php if ( ! empty( $pickup_locations ) ) : ?>
            <p><strong><?php esc_html_e( 'Pickup Location', 'erpnext-shipping' ); ?></strong></p>
            <p>
                <select id="es-pickup-location" style="width:100%;">
                    <option value=""><?php esc_html_e( '— Select pickup location —', 'erpnext-shipping' ); ?></option>
                    <?php foreach ( $pickup_locations as $loc ) : ?>
                        <option value="<?php echo esc_attr( $loc['id'] ); ?>" <?php selected( $current_pickup_loc, $loc['id'] ); ?>>
                            <?php echo esc_html( $loc['name'] . ' — ' . ( $loc['city'] ?? '' ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p>
                <button type="button" class="button" id="es-save-pickup-btn" style="width:100%;">
                    <?php esc_html_e( 'Save Pickup Location', 'erpnext-shipping' ); ?>
                </button>
            </p>
            <?php endif; ?>

            <hr style="margin:12px 0;">

            <?php // Status action buttons for pickup flow. ?>
            <?php if ( 'processing-lp' === $status ) : ?>
                <button type="button" class="button button-primary" id="es-pickup-status-btn" data-status="dispatched-pickup" style="width:100%;">
                    <?php esc_html_e( 'Dispatch to Pickup Location', 'erpnext-shipping' ); ?>
                </button>
            <?php elseif ( 'dispatched-pickup' === $status ) : ?>
                <button type="button" class="button button-primary" id="es-pickup-status-btn" data-status="ready-pickup" style="width:100%;">
                    <?php esc_html_e( 'Mark as Ready for Pickup', 'erpnext-shipping' ); ?>
                </button>
            <?php elseif ( 'ready-pickup' === $status ) : ?>
                <button type="button" class="button button-primary" id="es-pickup-status-btn" data-status="pickup" style="width:100%;">
                    <?php esc_html_e( 'Mark as Picked Up', 'erpnext-shipping' ); ?>
                </button>
            <?php endif; ?>

        <?php else : ?>
            <?php // ── DELIVERY FLOW ── ?>

            <div id="es-tracking-items">
                <?php if ( ! empty( $items ) ) : ?>
                    <?php foreach ( $items as $item ) : ?>
                        <div class="es-tracking-item" style="padding:8px 0; border-bottom:1px solid #eee;">
                            <strong><?php echo esc_html( ES_Fulfillment_Tracking::get_provider_name( $item['tracking_provider'] ) ); ?></strong><br>
                            <?php
                            $url = ES_Fulfillment_Tracking::get_tracking_url( $item );
                            if ( $url ) :
                            ?>
                                <a href="<?php echo esc_url( $url ); ?>" target="_blank"><?php echo esc_html( $item['tracking_number'] ); ?></a>
                            <?php else : ?>
                                <?php echo esc_html( $item['tracking_number'] ); ?>
                            <?php endif; ?>
                            <br>
                            <small><?php echo esc_html( date_i18n( get_option( 'date_format' ), $item['date_shipped'] ) ); ?></small>
                            <a href="#" class="es-delete-tracking" data-tracking-id="<?php echo esc_attr( $item['tracking_id'] ); ?>" data-order-id="<?php echo esc_attr( $order_id ); ?>" style="color:#a00; float:right; text-decoration:none;" title="<?php esc_attr_e( 'Delete', 'erpnext-shipping' ); ?>">&times;</a>
                        </div>
                    <?php endforeach; ?>
                <?php else : ?>
                    <p style="color:#999;"><?php esc_html_e( 'No tracking entries yet.', 'erpnext-shipping' ); ?></p>
                <?php endif; ?>
            </div>

            <?php
            // Show courier polling status if available.
            $courier_status = $order->get_meta( '_es_courier_status', true );
            $last_polled    = $order->get_meta( '_es_last_polled', true );
            if ( $courier_status ) :
                $statuses_raw = array_map( 'trim', explode( ',', $courier_status ) );
            ?>
            <div style="padding:8px 0; border-bottom:1px solid #eee;">
                <strong><?php esc_html_e( 'Courier Status', 'erpnext-shipping' ); ?></strong><br>
                <?php foreach ( $statuses_raw as $raw ) :
                    $label = self::$courier_status_labels[ $raw ] ?? $raw;
                    $color = self::get_status_dot_color( $raw );
                ?>
                    <span class="es-courier-badge" style="color:<?php echo esc_attr( $color ); ?>;">
                        <span class="es-dot" style="background:<?php echo esc_attr( $color ); ?>;"></span>
                        <?php echo esc_html( $label ); ?>
                    </span><br>
                <?php endforeach; ?>
                <?php if ( $last_polled ) : ?>
                    <small style="color:#999;"><?php echo esc_html( sprintf( __( 'Last polled: %s', 'erpnext-shipping' ), date_i18n( 'M j, g:i a', $last_polled ) ) ); ?></small>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <hr style="margin:12px 0;">
            <p><strong><?php esc_html_e( 'Add Tracking', 'erpnext-shipping' ); ?></strong></p>

            <p>
                <label><?php esc_html_e( 'Provider', 'erpnext-shipping' ); ?></label><br>
                <select id="es-tracking-provider" style="width:100%;">
                    <?php foreach ( $providers as $slug => $data ) : ?>
                        <option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $data['name'] ); ?></option>
                    <?php endforeach; ?>
                    <option value="custom"><?php esc_html_e( 'Custom', 'erpnext-shipping' ); ?></option>
                </select>
            </p>

            <p>
                <label><?php esc_html_e( 'Tracking Number', 'erpnext-shipping' ); ?></label><br>
                <input type="text" id="es-tracking-number" style="width:100%;" placeholder="<?php esc_attr_e( 'e.g. TCG100042593T', 'erpnext-shipping' ); ?>">
            </p>

            <p id="es-custom-url-row" style="display:none;">
                <label><?php esc_html_e( 'Custom Tracking URL', 'erpnext-shipping' ); ?></label><br>
                <input type="url" id="es-custom-url" style="width:100%;">
            </p>

            <p>
                <label><?php esc_html_e( 'Date Shipped', 'erpnext-shipping' ); ?></label><br>
                <input type="date" id="es-date-shipped" style="width:100%;" value="<?php echo esc_attr( date( 'Y-m-d' ) ); ?>">
            </p>

            <p>
                <label>
                    <input type="checkbox" id="es-no-status-update">
                    <?php esc_html_e( "Don't update order status", 'erpnext-shipping' ); ?>
                </label>
            </p>

            <p>
                <button type="button" class="button button-primary" id="es-add-tracking-btn" style="width:100%;">
                    <?php esc_html_e( 'Add Tracking', 'erpnext-shipping' ); ?>
                </button>
            </p>
        <?php endif; ?>

        <hr style="margin:12px 0;">
        <?php
        $slip_url = wp_nonce_url(
            admin_url( 'admin-ajax.php?action=es_packing_slip&order_id=' . $order_id ),
            'es_packing_' . $order_id
        );
        ?>
        <p>
            <a href="<?php echo esc_url( $slip_url ); ?>" target="_blank" class="button" style="width:100%; text-align:center;">
                <span class="dashicons dashicons-media-text" style="vertical-align:middle; margin-right:4px;"></span>
                <?php esc_html_e( 'Print Packing Slip', 'erpnext-shipping' ); ?>
            </a>
        </p>

        <script>
        jQuery(function($) {
            // Show/hide custom URL field.
            $('#es-tracking-provider').on('change', function() {
                $('#es-custom-url-row').toggle($(this).val() === 'custom');
            });

            // Add tracking AJAX.
            $('#es-add-tracking-btn').on('click', function() {
                var $btn = $(this);
                var number = $('#es-tracking-number').val().trim();
                if (!number) { alert('Please enter a tracking number.'); return; }

                $btn.prop('disabled', true).text('Adding...');

                $.post(ajaxurl, {
                    action: 'es_add_tracking',
                    _ajax_nonce: '<?php echo wp_create_nonce( 'es_tracking_' . $order_id ); ?>',
                    order_id: <?php echo intval( $order_id ); ?>,
                    tracking_provider: $('#es-tracking-provider').val(),
                    tracking_number: number,
                    date_shipped: $('#es-date-shipped').val(),
                    custom_tracking_link: $('#es-custom-url').val(),
                    no_status_update: $('#es-no-status-update').is(':checked') ? 1 : 0,
                    pickup_location_id: $('#es-pickup-location').val() || ''
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.data || 'Failed to add tracking.');
                        $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Add Tracking', 'erpnext-shipping' ) ); ?>');
                    }
                });
            });

            // Delete tracking AJAX.
            $(document).on('click', '.es-delete-tracking', function(e) {
                e.preventDefault();
                if (!confirm('Delete this tracking entry?')) return;

                var $link = $(this);
                $.post(ajaxurl, {
                    action: 'es_delete_tracking',
                    _ajax_nonce: '<?php echo wp_create_nonce( 'es_tracking_' . $order_id ); ?>',
                    order_id: $link.data('order-id'),
                    tracking_id: $link.data('tracking-id')
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.data || 'Failed to delete.');
                    }
                });
            });

            // Save pickup location independently.
            $('#es-save-pickup-btn').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('Saving...');
                $.post(ajaxurl, {
                    action: 'es_save_pickup_location',
                    _ajax_nonce: '<?php echo wp_create_nonce( 'es_tracking_' . $order_id ); ?>',
                    order_id: <?php echo (int) $order_id; ?>,
                    pickup_location_id: $('#es-pickup-location').val()
                }, function(response) {
                    if (response.success) {
                        $btn.text('Saved!');
                        setTimeout(function() { $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Save Pickup Location', 'erpnext-shipping' ) ); ?>'); }, 1500);
                    } else {
                        alert(response.data || 'Failed to save.');
                        $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Save Pickup Location', 'erpnext-shipping' ) ); ?>');
                    }
                });
            });

            // Pickup status action button (in meta box).
            $('#es-pickup-status-btn').on('click', function() {
                var $btn = $(this);
                var newStatus = $btn.data('status');
                $btn.prop('disabled', true).text('Updating...');
                // Build URL with status placeholder replaced, nonce appended after.
                var baseUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php?action=es_update_order_status&order_id=' . $order_id ) ); ?>';
                var nonce = '<?php echo esc_js( wp_create_nonce( 'es_status_' . $order_id ) ); ?>';
                window.location.href = baseUrl + '&new_status=' + newStatus + '&_wpnonce=' + nonce;
            });
        });
        </script>
        <?php
    }

    // ── AJAX Handlers ──

    /**
     * AJAX: Add tracking entry.
     */
    public static function ajax_add_tracking() {
        $order_id = intval( $_POST['order_id'] ?? 0 );

        // Accept either per-order nonce (meta box) or generic nonce (quick modal).
        if ( ! wp_verify_nonce( $_POST['_ajax_nonce'] ?? '', 'es_tracking_' . $order_id )
          && ! wp_verify_nonce( $_POST['_ajax_nonce'] ?? '', 'es_quick_tracking' ) ) {
            wp_send_json_error( 'Invalid nonce.' );
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( 'Order not found.' );
        }

        $provider    = sanitize_text_field( $_POST['tracking_provider'] ?? '' );
        $number      = sanitize_text_field( $_POST['tracking_number'] ?? '' );
        $date_str    = sanitize_text_field( $_POST['date_shipped'] ?? '' );
        $custom_link = esc_url_raw( $_POST['custom_tracking_link'] ?? '' );

        if ( empty( $number ) ) {
            wp_send_json_error( 'Tracking number is required.' );
        }

        $date_shipped = $date_str ? strtotime( $date_str ) : time();
        if ( ! $date_shipped ) {
            $date_shipped = time();
        }

        $item = ES_Fulfillment_Tracking::create_tracking_item( $provider, $number, $date_shipped, $custom_link );
        ES_Fulfillment_Tracking::save_tracking_item( $order, $item );

        // Add customer-visible shipping note if provided.
        $shipping_note = sanitize_text_field( $_POST['shipping_note'] ?? '' );
        if ( ! empty( $shipping_note ) ) {
            $order->add_order_note( $shipping_note, 1 ); // 1 = customer note (visible).
        }

        // Save pickup location if provided.
        $pickup_loc = sanitize_text_field( $_POST['pickup_location_id'] ?? '' );
        if ( ! empty( $pickup_loc ) ) {
            $order->update_meta_data( '_es_pickup_location_id', $pickup_loc );
            $order->save();
        }

        // Auto-update status (unless checkbox was checked).
        $no_update = intval( $_POST['no_status_update'] ?? 0 );
        if ( ! $no_update ) {
            $status = $order->get_status();
            if ( 'processing' === $status ) {
                $order->update_status( 'completed', __( 'Tracking added — marked as Shipped.', 'erpnext-shipping' ) );
            }
        }

        wp_send_json_success( 'Tracking added.' );
    }

    /**
     * AJAX: Delete tracking entry.
     */
    public static function ajax_delete_tracking() {
        $order_id = intval( $_POST['order_id'] ?? 0 );
        check_ajax_referer( 'es_tracking_' . $order_id );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( 'Order not found.' );
        }

        $tracking_id = sanitize_text_field( $_POST['tracking_id'] ?? '' );
        $items       = ES_Fulfillment_Tracking::get_tracking_items( $order );
        $found       = false;

        foreach ( $items as $i => $item ) {
            if ( $item['tracking_id'] === $tracking_id ) {
                unset( $items[ $i ] );
                $found = true;
                break;
            }
        }

        if ( ! $found ) {
            wp_send_json_error( 'Tracking entry not found.' );
        }

        $remaining = array_values( $items );
        if ( empty( $remaining ) ) {
            // Remove meta entirely so NOT EXISTS filters and cron queries work correctly.
            $order->delete_meta_data( ES_Fulfillment_Tracking::META_KEY );
            $order->delete_meta_data( '_es_courier_status' );
            $order->delete_meta_data( '_es_last_polled' );
        } else {
            $order->update_meta_data( ES_Fulfillment_Tracking::META_KEY, $remaining );
        }
        $order->save();

        wp_send_json_success( 'Tracking deleted.' );
    }

    /**
     * AJAX: Save pickup location independently (no tracking entry needed).
     */
    public static function ajax_save_pickup_location() {
        $order_id = intval( $_POST['order_id'] ?? 0 );
        check_ajax_referer( 'es_tracking_' . $order_id );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( 'Order not found.' );
        }

        $location_id = sanitize_text_field( $_POST['pickup_location_id'] ?? '' );
        if ( empty( $location_id ) ) {
            $order->delete_meta_data( '_es_pickup_location_id' );
        } elseif ( ES_Fulfillment_Checkout::is_valid_pickup_location( $location_id ) ) {
            $order->update_meta_data( '_es_pickup_location_id', $location_id );
        } else {
            wp_send_json_error( 'Invalid pickup location.' );
            return;
        }
        $order->save();

        wp_send_json_success( 'Pickup location saved.' );
    }

    // ── Admin Notice ──

    /**
     * Admin notice for pickup orders without a location set.
     */
    public static function pickup_location_notice() {
        $screen = get_current_screen();
        if ( ! $screen ) {
            return;
        }

        // Only show on order edit screens.
        if ( ! in_array( $screen->id, array( 'shop_order', wc_get_page_screen_id( 'shop-order' ) ), true ) ) {
            return;
        }

        // Get the order being edited.
        global $theorder;
        $order = $theorder;
        if ( ! $order && isset( $_GET['id'] ) ) {
            $order = wc_get_order( intval( $_GET['id'] ) );
        }
        if ( ! $order ) {
            return;
        }

        $pickup_statuses = array( 'processing-lp', 'dispatched-pickup', 'ready-pickup', 'pickup' );
        if ( ! in_array( $order->get_status(), $pickup_statuses, true ) ) {
            return;
        }

        // Show error if redirected from a blocked quick action.
        if ( isset( $_GET['es_notice'] ) && 'pickup_location_required' === $_GET['es_notice'] ) {
            echo '<div class="notice notice-error"><p>';
            esc_html_e( 'Cannot change status — please set a pickup location first. Without it, the customer will not receive a notification email.', 'erpnext-shipping' );
            echo '</p></div>';
            return;
        }

        // Use the shared resolver which also checks Zorem meta for in-flight orders.
        $pickup_loc = ES_Fulfillment_Statuses::resolve_pickup_location( $order );
        if ( empty( $pickup_loc ) ) {
            echo '<div class="notice notice-warning"><p>';
            esc_html_e( 'Pickup location not set — customer will not receive pickup notifications until a location is selected.', 'erpnext-shipping' );
            echo '</p></div>';
        }
    }

    // ── Pickup Location on Order Detail ──

    /**
     * Show pickup location below the shipping address on order edit screen.
     */
    public static function show_pickup_location_on_order( $order ) {
        $pickup_statuses = array( 'processing-lp', 'dispatched-pickup', 'ready-pickup', 'pickup' );
        if ( ! in_array( $order->get_status(), $pickup_statuses, true ) ) {
            return;
        }

        $loc = self::resolve_pickup_location_for_display( $order );
        if ( ! $loc ) {
            return;
        }

        $parts = array_filter( array(
            $loc['street'] ?? '',
            $loc['suburb'] ?? '',
            $loc['city'] ?? '',
            $loc['province'] ?? '',
            $loc['postcode'] ?? '',
        ) );
        ?>
        <div style="margin-top:12px; padding:10px; background:#fff8e5; border-left:4px solid #f0ad4e;">
            <strong><?php esc_html_e( 'Pickup Location:', 'erpnext-shipping' ); ?></strong>
            <?php echo esc_html( $loc['name'] ); ?><br>
            <span style="color:#666;"><?php echo esc_html( implode( ', ', $parts ) ); ?></span>
            <?php if ( ! empty( $loc['customer_message'] ) ) : ?>
                <br><em style="color:#888; font-size:12px;"><?php echo esc_html( $loc['customer_message'] ); ?></em>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Resolve pickup location data for an order (used by column + order detail).
     */
    private static function resolve_pickup_location_for_display( $order ) {
        $loc_id = $order->get_meta( '_es_pickup_location_id', true );
        if ( empty( $loc_id ) ) {
            // Try Zorem fallback via shared resolver.
            $loc_id = ES_Fulfillment_Statuses::resolve_pickup_location( $order );
        }
        if ( empty( $loc_id ) ) {
            return null;
        }

        $locations = get_option( 'es_shipping_locations', array() );
        foreach ( $locations as $loc ) {
            if ( ( $loc['id'] ?? '' ) === $loc_id ) {
                return $loc;
            }
        }
        return null;
    }

    // ── Packing Slip ──

    /**
     * AJAX: Render a print-friendly packing slip in a new window.
     */
    public static function render_packing_slip() {
        $order_id = intval( $_GET['order_id'] ?? 0 );
        check_admin_referer( 'es_packing_' . $order_id );

        if ( ! ES_Warehouse_Role::current_user_can_fulfill() ) {
            wp_die( 'Permission denied.' );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_die( 'Order not found.' );
        }

        $customer_note = $order->get_customer_note();
        $items         = $order->get_items();
        $order_number  = $order->get_order_number();
        $order_date    = $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : '';
        $customer_name = $order->get_formatted_billing_full_name();
        $status        = $order->get_status();

        // Determine if pickup or delivery.
        $pickup_statuses = array( 'processing-lp', 'dispatched-pickup', 'ready-pickup', 'pickup' );
        $is_pickup       = in_array( $status, $pickup_statuses, true );

        // Get destination info.
        $destination = '';
        if ( $is_pickup ) {
            $loc = self::resolve_pickup_location_for_display( $order );
            if ( $loc ) {
                $parts = array_filter( array(
                    $loc['street'] ?? '',
                    $loc['suburb'] ?? '',
                    $loc['city'] ?? '',
                    $loc['province'] ?? '',
                    $loc['postcode'] ?? '',
                ) );
                $destination = $loc['name'] . "\n" . implode( ', ', $parts );
            }
        } else {
            $destination = $order->get_formatted_shipping_address();
            if ( empty( $destination ) ) {
                $destination = $order->get_formatted_billing_address();
            }
        }

        // Build items table data.
        $line_items = array();
        $total_weight = 0;
        foreach ( $items as $item ) {
            $product = $item->get_product();
            $weight  = $product ? (float) $product->get_weight() : 0;
            $qty     = $item->get_quantity();
            $item_weight = $weight * $qty;
            $total_weight += $item_weight;

            // Collect visible product addon/custom fields (non-internal meta).
            $addons = array();
            foreach ( $item->get_meta_data() as $meta ) {
                $key = $meta->key;
                // Skip internal WC meta (prefixed with _) and known system keys.
                if ( str_starts_with( $key, '_' ) || in_array( $key, array( 'is_vat_exempt' ), true ) ) {
                    continue;
                }
                $addons[] = array(
                    'key'   => $key,
                    'value' => $meta->value,
                );
            }

            $line_items[] = array(
                'sku'     => $product ? $product->get_sku() : '',
                'name'    => $item->get_name(),
                'qty'     => $qty,
                'weight'  => $item_weight,
                'addons'  => $addons,
            );
        }

        // Output the print page.
        ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title><?php printf( esc_html__( 'Packing Slip — #%s', 'erpnext-shipping' ), esc_html( $order_number ) ); ?></title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 13px; color: #333; padding: 20px; }
    .slip-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; border-bottom: 2px solid #333; padding-bottom: 15px; }
    .slip-header h1 { font-size: 22px; margin-bottom: 4px; }
    .slip-header .order-meta { text-align: right; font-size: 14px; }
    .slip-header .order-meta strong { font-size: 18px; }
    .info-row { display: flex; gap: 30px; margin-bottom: 16px; }
    .info-box { flex: 1; }
    .info-box h3 { font-size: 11px; text-transform: uppercase; color: #666; letter-spacing: 0.5px; margin-bottom: 4px; }
    .info-box p { white-space: pre-line; line-height: 1.5; }
    .customer-note { background: #fff8e5; border: 1px solid #f0ad4e; border-radius: 4px; padding: 10px 12px; margin-bottom: 16px; }
    .customer-note h3 { font-size: 11px; text-transform: uppercase; color: #856404; letter-spacing: 0.5px; margin-bottom: 4px; }
    .customer-note p { color: #856404; font-weight: 500; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
    th { background: #f0f0f1; text-align: left; padding: 8px 10px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #ccc; }
    td { padding: 8px 10px; border-bottom: 1px solid #eee; }
    td.qty, td.weight, th.qty, th.weight { text-align: center; }
    .totals { text-align: right; font-weight: 600; }
    .item-addons { margin-top: 4px; }
    .item-addons .addon { display: block; font-size: 11px; color: #666; line-height: 1.5; }
    .item-addons .addon strong { color: #444; }
    .pickup-badge { display: inline-block; background: #f0ad4e; color: #fff; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 3px; text-transform: uppercase; }
    @media print {
        body { padding: 0; }
        @page { margin: 15mm; }
    }
</style>
</head>
<body onload="window.print();">

<div class="slip-header">
    <div>
        <h1><?php esc_html_e( 'Packing Slip', 'erpnext-shipping' ); ?></h1>
        <?php if ( $is_pickup ) : ?>
            <span class="pickup-badge"><?php esc_html_e( 'Pickup Order', 'erpnext-shipping' ); ?></span>
        <?php endif; ?>
    </div>
    <div class="order-meta">
        <strong>#<?php echo esc_html( $order_number ); ?></strong><br>
        <?php echo esc_html( $order_date ); ?>
    </div>
</div>

<div class="info-row">
    <div class="info-box">
        <h3><?php esc_html_e( 'Customer', 'erpnext-shipping' ); ?></h3>
        <p><?php echo esc_html( $customer_name ); ?></p>
    </div>
    <div class="info-box">
        <h3><?php echo $is_pickup ? esc_html__( 'Pickup Location', 'erpnext-shipping' ) : esc_html__( 'Ship To', 'erpnext-shipping' ); ?></h3>
        <p><?php echo wp_kses_post( $destination ); ?></p>
    </div>
</div>

<?php if ( $customer_note ) : ?>
<div class="customer-note">
    <h3><?php esc_html_e( 'Customer Note', 'erpnext-shipping' ); ?></h3>
    <p><?php echo esc_html( $customer_note ); ?></p>
</div>
<?php endif; ?>

<table>
    <thead>
        <tr>
            <th><?php esc_html_e( 'SKU', 'erpnext-shipping' ); ?></th>
            <th><?php esc_html_e( 'Product', 'erpnext-shipping' ); ?></th>
            <th class="qty"><?php esc_html_e( 'Qty', 'erpnext-shipping' ); ?></th>
            <th class="weight"><?php esc_html_e( 'Weight', 'erpnext-shipping' ); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ( $line_items as $li ) : ?>
        <tr>
            <td><?php echo esc_html( $li['sku'] ); ?></td>
            <td>
                <?php echo esc_html( $li['name'] ); ?>
                <?php if ( ! empty( $li['addons'] ) ) : ?>
                    <div class="item-addons">
                        <?php foreach ( $li['addons'] as $addon ) : ?>
                            <span class="addon"><strong><?php echo esc_html( $addon['key'] ); ?>:</strong> <?php echo esc_html( $addon['value'] ); ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </td>
            <td class="qty"><?php echo esc_html( $li['qty'] ); ?></td>
            <td class="weight"><?php echo $li['weight'] > 0 ? esc_html( number_format( $li['weight'], 2 ) . ' kg' ) : '&ndash;'; ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr>
            <td colspan="3" class="totals"><?php esc_html_e( 'Total Weight:', 'erpnext-shipping' ); ?></td>
            <td class="weight totals"><?php echo esc_html( number_format( $total_weight, 2 ) . ' kg' ); ?></td>
        </tr>
    </tfoot>
</table>

</body>
</html>
        <?php
        exit;
    }
}

ES_Fulfillment_Admin::init();
