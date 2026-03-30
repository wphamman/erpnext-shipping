<?php
defined( 'ABSPATH' ) || exit;

/**
 * Order Assignment: assign orders to staff members.
 * - "Assigned to" dropdown on order detail page
 * - "Assigned to" column on order list
 * - Filter by assignee on order list
 * - Email notification to assignee on assignment
 */
class ES_Order_Assignment {

    /** Order meta key for assigned user ID. */
    const META_KEY = '_es_assigned_to';

    public static function init() {
        // Meta box on order edit screen.
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );

        // AJAX handler for saving assignment.
        add_action( 'wp_ajax_es_assign_order', array( __CLASS__, 'ajax_assign_order' ) );

        // Column on order list.
        add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'add_column' ), 25 );
        add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
        add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_column' ), 25 );
        add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_column_legacy' ), 10, 2 );

        // Filter dropdown.
        add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( __CLASS__, 'render_filter_hpos' ) );
        add_action( 'restrict_manage_posts', array( __CLASS__, 'render_filter_legacy' ) );
        add_filter( 'woocommerce_shop_order_list_table_prepare_items_query_args', array( __CLASS__, 'apply_filter_hpos' ) );
        add_filter( 'request', array( __CLASS__, 'apply_filter_legacy' ) );

        // CSS.
        add_action( 'admin_head', array( __CLASS__, 'admin_css' ) );
    }

    // ── Assignable Users ──

    /**
     * Get users who can be assigned orders (admins, shop managers, warehouse staff).
     */
    public static function get_assignable_users() {
        $users = get_users( array(
            'role__in' => array( 'administrator', 'shop_manager', 'warehouse_staff' ),
            'orderby'  => 'display_name',
            'order'    => 'ASC',
        ) );
        return $users;
    }

    // ── Meta Box ──

    public static function add_meta_box() {
        $screen = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
            ? wc_get_page_screen_id( 'shop-order' )
            : 'shop_order';

        add_meta_box(
            'es-order-assignment',
            __( 'Order Assignment', 'erpnext-shipping' ),
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

        $order_id    = $order->get_id();
        $assigned_id = absint( $order->get_meta( self::META_KEY, true ) );
        $users       = self::get_assignable_users();

        wp_nonce_field( 'es_assign_' . $order_id, 'es_assign_nonce' );
        ?>
        <p>
            <select id="es-assign-user" style="width:100%;">
                <option value=""><?php esc_html_e( '— Unassigned —', 'erpnext-shipping' ); ?></option>
                <?php foreach ( $users as $user ) : ?>
                    <option value="<?php echo esc_attr( $user->ID ); ?>" <?php selected( $assigned_id, $user->ID ); ?>>
                        <?php echo esc_html( $user->display_name ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <button type="button" class="button" id="es-assign-btn" style="width:100%;">
                <?php esc_html_e( 'Assign Order', 'erpnext-shipping' ); ?>
            </button>
        </p>
        <?php if ( $assigned_id ) :
            $user = get_userdata( $assigned_id );
            if ( $user ) : ?>
                <p style="font-size:12px; color:#666; margin-top:8px;">
                    <?php printf(
                        esc_html__( 'Currently assigned to %s', 'erpnext-shipping' ),
                        '<strong>' . esc_html( $user->display_name ) . '</strong>'
                    ); ?>
                </p>
            <?php endif;
        endif; ?>

        <script>
        jQuery(function($) {
            $('#es-assign-btn').on('click', function() {
                var $btn = $(this);
                var userId = $('#es-assign-user').val();
                $btn.prop('disabled', true).text('<?php echo esc_js( __( 'Saving...', 'erpnext-shipping' ) ); ?>');

                $.post(ajaxurl, {
                    action: 'es_assign_order',
                    _ajax_nonce: '<?php echo wp_create_nonce( 'es_assign_' . $order_id ); ?>',
                    order_id: <?php echo (int) $order_id; ?>,
                    user_id: userId
                }, function(response) {
                    if (response.success) {
                        $btn.text('<?php echo esc_js( __( 'Saved!', 'erpnext-shipping' ) ); ?>');
                        setTimeout(function() { location.reload(); }, 800);
                    } else {
                        alert(response.data || 'Failed.');
                        $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Assign Order', 'erpnext-shipping' ) ); ?>');
                    }
                });
            });
        });
        </script>
        <?php
    }

    // ── AJAX Handler ──

    public static function ajax_assign_order() {
        $order_id = intval( $_POST['order_id'] ?? 0 );
        check_ajax_referer( 'es_assign_' . $order_id );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( 'Order not found.' );
        }

        $user_id     = intval( $_POST['user_id'] ?? 0 );
        $prev_id     = absint( $order->get_meta( self::META_KEY, true ) );
        $changed     = $user_id !== $prev_id;

        if ( $user_id > 0 ) {
            // Validate user exists and is in the assignable set (admin/shop_manager/warehouse_staff).
            $user = get_userdata( $user_id );
            if ( ! $user ) {
                wp_send_json_error( 'User not found.' );
                return;
            }
            $allowed_roles = array( 'administrator', 'shop_manager', 'warehouse_staff' );
            if ( empty( array_intersect( $allowed_roles, $user->roles ) ) ) {
                wp_send_json_error( 'User cannot be assigned orders.' );
                return;
            }
            $order->update_meta_data( self::META_KEY, $user_id );
            $order->save();

            // Send email notification if assignment changed.
            if ( $changed ) {
                self::send_assignment_email( $order, $user );
                $order->add_order_note(
                    sprintf( __( 'Order assigned to %s.', 'erpnext-shipping' ), $user->display_name ),
                    false
                );
            }
        } else {
            // Unassign.
            $order->delete_meta_data( self::META_KEY );
            $order->save();
            if ( $prev_id > 0 ) {
                $order->add_order_note( __( 'Order unassigned.', 'erpnext-shipping' ), false );
            }
        }

        wp_send_json_success( 'Assignment saved.' );
    }

    // ── Email Notification ──

    private static function send_assignment_email( $order, $user ) {
        $to        = $user->user_email;
        $site_name = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
        $order_num = $order->get_order_number();
        $order_url = $order->get_edit_order_url();
        $customer  = $order->get_formatted_billing_full_name();
        $total     = $order->get_total();
        $status    = wc_get_order_status_name( $order->get_status() );

        $subject = sprintf( '[%s] Order #%s assigned to you', $site_name, $order_num );

        $body  = '<div style="font-family: -apple-system, BlinkMacSystemFont, sans-serif; max-width: 500px;">';
        $body .= '<h2 style="color: #333; margin-bottom: 8px;">Order #' . esc_html( $order_num ) . ' assigned to you</h2>';
        $body .= '<table style="font-size: 14px; line-height: 1.6;">';
        $body .= '<tr><td style="color:#666; padding-right:12px;">Customer:</td><td><strong>' . esc_html( $customer ) . '</strong></td></tr>';
        $body .= '<tr><td style="color:#666; padding-right:12px;">Total:</td><td>' . wc_price( $total ) . '</td></tr>';
        $body .= '<tr><td style="color:#666; padding-right:12px;">Status:</td><td>' . esc_html( $status ) . '</td></tr>';
        $body .= '</table>';

        // Customer note if present.
        $note = $order->get_customer_note();
        if ( $note ) {
            $body .= '<div style="margin-top:12px; padding:10px; background:#fff8e5; border-left:4px solid #f0ad4e;">';
            $body .= '<strong style="font-size:12px; color:#856404;">Customer Note:</strong><br>';
            $body .= '<span style="color:#856404;">' . esc_html( $note ) . '</span>';
            $body .= '</div>';
        }

        $body .= '<p style="margin-top:16px;"><a href="' . esc_url( $order_url ) . '" style="display:inline-block; padding:8px 20px; background:#2271b1; color:#fff; text-decoration:none; border-radius:4px;">View Order</a></p>';
        $body .= '</div>';

        $headers = array( 'Content-Type: text/html; charset=UTF-8' );
        wp_mail( $to, $subject, $body, $headers );
    }

    // ── Order List Column ──

    public static function add_column( $columns ) {
        $new = array();
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            // Insert after 'order_status'.
            if ( 'order_status' === $key ) {
                $new['es_assigned'] = __( 'Assigned to', 'erpnext-shipping' );
            }
        }
        if ( ! isset( $new['es_assigned'] ) ) {
            $new['es_assigned'] = __( 'Assigned to', 'erpnext-shipping' );
        }
        return $new;
    }

    public static function render_column( $column_name, $order ) {
        if ( 'es_assigned' !== $column_name ) {
            return;
        }
        self::render_assigned_cell( $order );
    }

    public static function render_column_legacy( $column_name, $post_id ) {
        if ( 'es_assigned' !== $column_name ) {
            return;
        }
        $order = wc_get_order( $post_id );
        if ( $order ) {
            self::render_assigned_cell( $order );
        } else {
            echo '&ndash;';
        }
    }

    private static function render_assigned_cell( $order ) {
        $user_id = absint( $order->get_meta( self::META_KEY, true ) );
        if ( ! $user_id ) {
            echo '<span style="color:#999;">&ndash;</span>';
            return;
        }
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            echo '<span style="color:#999;">&ndash;</span>';
            return;
        }
        echo '<span class="es-assigned-badge">' . esc_html( $user->display_name ) . '</span>';
    }

    // ── Filter ──

    public static function render_filter_hpos() {
        self::render_filter_dropdown();
    }

    public static function render_filter_legacy( $post_type ) {
        if ( 'shop_order' !== $post_type ) {
            return;
        }
        self::render_filter_dropdown();
    }

    private static function render_filter_dropdown() {
        $users    = self::get_assignable_users();
        $selected = sanitize_text_field( $_GET['es_assigned_filter'] ?? '' );
        ?>
        <select name="es_assigned_filter">
            <option value=""><?php esc_html_e( 'Filter by assignee', 'erpnext-shipping' ); ?></option>
            <option value="unassigned" <?php selected( $selected, 'unassigned' ); ?>><?php esc_html_e( 'Unassigned', 'erpnext-shipping' ); ?></option>
            <?php foreach ( $users as $user ) : ?>
                <option value="<?php echo esc_attr( $user->ID ); ?>" <?php selected( $selected, (string) $user->ID ); ?>>
                    <?php echo esc_html( $user->display_name ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public static function apply_filter_hpos( $args ) {
        $filter = sanitize_text_field( $_GET['es_assigned_filter'] ?? '' );
        if ( empty( $filter ) ) {
            return $args;
        }

        if ( ! isset( $args['meta_query'] ) ) {
            $args['meta_query'] = array();
        }

        if ( 'unassigned' === $filter ) {
            $args['meta_query'][] = array(
                'relation' => 'OR',
                array( 'key' => self::META_KEY, 'compare' => 'NOT EXISTS' ),
                array( 'key' => self::META_KEY, 'value' => '', 'compare' => '=' ),
                array( 'key' => self::META_KEY, 'value' => '0', 'compare' => '=' ),
            );
        } else {
            $args['meta_query'][] = array(
                'key'   => self::META_KEY,
                'value' => intval( $filter ),
            );
        }

        return $args;
    }

    public static function apply_filter_legacy( $vars ) {
        global $typenow;
        if ( 'shop_order' !== $typenow ) {
            return $vars;
        }

        $filter = sanitize_text_field( $_GET['es_assigned_filter'] ?? '' );
        if ( empty( $filter ) ) {
            return $vars;
        }

        if ( ! isset( $vars['meta_query'] ) ) {
            $vars['meta_query'] = array();
        }

        if ( 'unassigned' === $filter ) {
            $vars['meta_query'][] = array(
                'relation' => 'OR',
                array( 'key' => self::META_KEY, 'compare' => 'NOT EXISTS' ),
                array( 'key' => self::META_KEY, 'value' => '', 'compare' => '=' ),
            );
        } else {
            $vars['meta_query'][] = array(
                'key'   => self::META_KEY,
                'value' => intval( $filter ),
            );
        }

        return $vars;
    }

    // ── CSS ──

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
        .column-es_assigned { width: 120px; }
        .es-assigned-badge { font-size: 12px; font-weight: 500; }
        </style>
        <?php
    }
}

ES_Order_Assignment::init();
