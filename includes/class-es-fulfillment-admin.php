<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin UI for shipment tracking on order pages.
 * - Meta box on order edit screen (HPOS + legacy)
 * - Quick action in order list
 * - AJAX handlers for add/delete tracking
 */
class ES_Fulfillment_Admin {

    public static function init() {
        // Meta box on order edit — single hook works for both HPOS and legacy
        // (add_meta_box() inside dynamically detects the correct screen).
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );

        // AJAX handlers.
        add_action( 'wp_ajax_es_add_tracking', array( __CLASS__, 'ajax_add_tracking' ) );
        add_action( 'wp_ajax_es_delete_tracking', array( __CLASS__, 'ajax_delete_tracking' ) );
        add_action( 'wp_ajax_es_save_pickup_location', array( __CLASS__, 'ajax_save_pickup_location' ) );

        // Quick actions in order list.
        add_filter( 'woocommerce_admin_order_actions', array( __CLASS__, 'add_order_actions' ), 10, 2 );

        // Admin notice for pickup orders missing location.
        add_action( 'admin_notices', array( __CLASS__, 'pickup_location_notice' ) );
    }

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

        wp_nonce_field( 'es_tracking_' . $order_id, 'es_tracking_nonce' );
        ?>
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

        <?php
        // Pickup location selector (only for pickup-flow orders).
        $pickup_statuses = array( 'processing-lp', 'ready-pickup', 'pickup' );
        $locations       = get_option( 'es_shipping_locations', array() );
        $pickup_locations = array_filter( $locations, function( $loc ) {
            return ! empty( $loc['pickup_enabled'] );
        } );

        if ( ! empty( $pickup_locations ) && in_array( $order->get_status(), $pickup_statuses, true ) ) :
            $current_pickup_loc = $order->get_meta( '_es_pickup_location_id', true );
        ?>
        <hr style="margin:12px 0;">
        <p><strong><?php esc_html_e( 'Pickup Location', 'erpnext-shipping' ); ?></strong></p>
        <p>
            <select id="es-pickup-location" style="width:100%;">
                <option value=""><?php esc_html_e( '— Select pickup location —', 'erpnext-shipping' ); ?></option>
                <?php foreach ( $pickup_locations as $loc ) : ?>
                    <option value="<?php echo esc_attr( $loc['id'] ); ?>" <?php selected( $current_pickup_loc, $loc['id'] ); ?>>
                        <?php echo esc_html( $loc['name'] . ' — ' . $loc['city'] ); ?>
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

        <p>
            <button type="button" class="button button-primary" id="es-add-tracking-btn" style="width:100%;">
                <?php esc_html_e( 'Add Tracking', 'erpnext-shipping' ); ?>
            </button>
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
                        $btn.prop('disabled', false).text('<?php esc_html_e( 'Add Tracking', 'erpnext-shipping' ); ?>');
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
        });
        </script>
        <?php
    }

    /**
     * AJAX: Add tracking entry.
     */
    public static function ajax_add_tracking() {
        $order_id = intval( $_POST['order_id'] ?? 0 );
        check_ajax_referer( 'es_tracking_' . $order_id );

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

        $order->update_meta_data( ES_Fulfillment_Tracking::META_KEY, array_values( $items ) );
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
        } else {
            $order->update_meta_data( '_es_pickup_location_id', $location_id );
        }
        $order->save();

        wp_send_json_success( 'Pickup location saved.' );
    }

    /**
     * Add "Add tracking" link to order row actions.
     */
    public static function add_order_actions( $actions, $order ) {
        $edit_url = $order->get_edit_order_url();
        $actions['es_tracking'] = array(
            'url'    => $edit_url . '#es-shipment-tracking',
            'name'   => __( 'Add tracking', 'erpnext-shipping' ),
            'action' => 'es_tracking',
        );
        return $actions;
    }

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

        $pickup_statuses = array( 'processing-lp', 'ready-pickup', 'pickup' );
        if ( ! in_array( $order->get_status(), $pickup_statuses, true ) ) {
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
}

ES_Fulfillment_Admin::init();
