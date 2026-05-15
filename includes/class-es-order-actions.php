<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin order actions:
 *  - "Open in ERPNext"        (item 1) — meta box link
 *  - "Re-poll courier status" (item 2) — meta box button + AJAX, bulk action
 *  - "Force ERPNext sync"     (item 5) — meta box button + AJAX, row action
 *
 * One meta box ("ERPNext") on the order edit screen contains all three buttons.
 * Row actions on the order list table add the Re-poll and Force-sync shortcuts.
 *
 * AJAX endpoints:
 *  - wp_ajax_es_repoll_order
 *  - wp_ajax_es_force_sync
 *
 * All actions require `manage_woocommerce` cap + nonce.
 */
class ES_Order_Actions {

    /** Per-order force-sync mutex TTL (seconds). */
    const FORCE_SYNC_LOCK_TTL = 30;
    /** Max orders accepted in a single bulk Re-poll submission. */
    const BULK_REPOLL_LIMIT = 25;
    /** Sleep between per-order polls during bulk action (microseconds). */
    const BULK_REPOLL_SLEEP_US = 200000; // 200ms

    public static function init() {
        // Meta box on order edit screen — HPOS-aware screen selection.
        add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_box' ) );

        // Row actions on the order list — single hook covers HPOS + legacy CPT.
        add_filter( 'woocommerce_admin_order_actions', array( __CLASS__, 'add_row_actions' ), 10, 2 );

        // Bulk action: Re-poll (legacy + HPOS).
        add_filter( 'bulk_actions-edit-shop_order', array( __CLASS__, 'add_bulk_actions' ) );
        add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( __CLASS__, 'add_bulk_actions' ) );
        add_filter( 'handle_bulk_actions-edit-shop_order', array( __CLASS__, 'handle_bulk_repoll' ), 10, 3 );
        add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( __CLASS__, 'handle_bulk_repoll' ), 10, 3 );
        add_action( 'admin_notices', array( __CLASS__, 'bulk_action_admin_notice' ) );

        // AJAX endpoints (used by the meta-box buttons).
        add_action( 'wp_ajax_es_repoll_order', array( __CLASS__, 'ajax_repoll_order' ) );
        add_action( 'wp_ajax_es_force_sync', array( __CLASS__, 'ajax_force_sync' ) );

        // admin-post.php endpoints (used by row actions, which can't carry POST nonces).
        add_action( 'admin_post_es_repoll_row', array( __CLASS__, 'handle_row_repoll' ) );
        add_action( 'admin_post_es_force_sync_row', array( __CLASS__, 'handle_row_force_sync' ) );
        add_action( 'admin_notices', array( __CLASS__, 'row_action_admin_notice' ) );

        // Enqueue JS on order screens.
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
    }

    // ── Meta box ───────────────────────────────────────────────────────────

    public static function register_meta_box() {
        $screen = wc_get_container()
            ->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )
            ->custom_orders_table_usage_is_enabled()
                ? wc_get_page_screen_id( 'shop-order' )
                : 'shop_order';

        add_meta_box(
            'es-erpnext-actions',
            __( 'ERPNext', 'erpnext-shipping' ),
            array( __CLASS__, 'render_meta_box' ),
            $screen,
            'side',
            'default'
        );
    }

    public static function render_meta_box( $post_or_order ) {
        $order = ( $post_or_order instanceof WP_Post ) ? wc_get_order( $post_or_order->ID ) : $post_or_order;
        if ( ! $order ) {
            return;
        }
        $order_id = $order->get_id();
        $so_name  = self::derive_so_name( $order_id );
        $erp_url  = self::resolve_erp_url();
        $erp_link = $erp_url ? trailingslashit( $erp_url ) . 'app/sales-order/' . $so_name : '';

        $nonce = wp_create_nonce( 'es_order_actions_' . $order_id );
        ?>
        <p style="margin-top:0;">
            <strong><?php esc_html_e( 'Sales Order:', 'erpnext-shipping' ); ?></strong>
            <code><?php echo esc_html( $so_name ); ?></code>
        </p>
        <?php if ( $erp_link ) : ?>
            <p>
                <a href="<?php echo esc_url( $erp_link ); ?>" target="_blank" rel="noopener" class="button button-secondary" style="width:100%;text-align:center;">
                    <?php esc_html_e( 'Open in ERPNext', 'erpnext-shipping' ); ?>
                </a>
            </p>
        <?php else : ?>
            <p><em><?php esc_html_e( 'Configure ERPNext URL in shipping method settings to enable the open link.', 'erpnext-shipping' ); ?></em></p>
        <?php endif; ?>
        <p>
            <button type="button" class="button button-secondary es-action-repoll" style="width:100%;"
                data-order-id="<?php echo esc_attr( $order_id ); ?>"
                data-nonce="<?php echo esc_attr( $nonce ); ?>">
                <?php esc_html_e( 'Re-poll Courier', 'erpnext-shipping' ); ?>
            </button>
        </p>
        <p>
            <button type="button" class="button button-secondary es-action-force-sync" style="width:100%;"
                data-order-id="<?php echo esc_attr( $order_id ); ?>"
                data-nonce="<?php echo esc_attr( $nonce ); ?>">
                <?php esc_html_e( 'Force ERPNext Sync', 'erpnext-shipping' ); ?>
            </button>
        </p>
        <div class="es-action-result" style="font-size:12px;color:#555;"></div>
        <?php
    }

    // ── Row actions ────────────────────────────────────────────────────────

    public static function add_row_actions( $actions, $order ) {
        if ( ! current_user_can( 'manage_woocommerce' ) || ! ( $order instanceof WC_Order ) ) {
            return $actions;
        }
        $order_id = $order->get_id();

        // Each action is a real admin-post.php URL with its own nonce, so the
        // browser navigates server-side and we handle the action there.
        $actions['es_repoll'] = array(
            'url'    => wp_nonce_url(
                add_query_arg(
                    array( 'action' => 'es_repoll_row', 'order_id' => $order_id ),
                    admin_url( 'admin-post.php' )
                ),
                'es_row_repoll_' . $order_id
            ),
            'name'   => __( 'Re-poll Courier', 'erpnext-shipping' ),
            'action' => 'es-action-repoll-row',
        );
        $actions['es_force_sync'] = array(
            'url'    => wp_nonce_url(
                add_query_arg(
                    array( 'action' => 'es_force_sync_row', 'order_id' => $order_id ),
                    admin_url( 'admin-post.php' )
                ),
                'es_row_force_sync_' . $order_id
            ),
            'name'   => __( 'Force ERPNext Sync', 'erpnext-shipping' ),
            'action' => 'es-action-force-sync-row',
        );
        return $actions;
    }

    /**
     * Where to send the user back to after a row-action invocation.
     */
    private static function row_action_redirect_target( $order_id ) {
        $referer = wp_get_referer();
        if ( ! $referer ) {
            return admin_url( 'edit.php?post_type=shop_order' );
        }
        return $referer;
    }

    public static function handle_row_repoll() {
        $order_id = intval( $_GET['order_id'] ?? 0 );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Permission denied.', '', array( 'response' => 403 ) );
        }
        check_admin_referer( 'es_row_repoll_' . $order_id );

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_die( 'Order not found.', '', array( 'response' => 404 ) );
        }
        $r = ES_Fulfillment_Cron::poll_single_order( $order );
        $msg = ( null === $r )
            ? sprintf( 'no-tracking-%d', $order_id )
            : sprintf( 'repoll-done-%d-%d', $order_id, ! empty( $r['had_failure'] ) ? 1 : 0 );

        wp_safe_redirect( add_query_arg( 'es_row_msg', $msg, self::row_action_redirect_target( $order_id ) ) );
        exit;
    }

    public static function handle_row_force_sync() {
        $order_id = intval( $_GET['order_id'] ?? 0 );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Permission denied.', '', array( 'response' => 403 ) );
        }
        check_admin_referer( 'es_row_force_sync_' . $order_id );

        $lock_key = 'es_force_sync_lock_' . $order_id;
        if ( get_transient( $lock_key ) ) {
            wp_safe_redirect( add_query_arg( 'es_row_msg', 'force-busy-' . $order_id, self::row_action_redirect_target( $order_id ) ) );
            exit;
        }
        set_transient( $lock_key, time(), self::FORCE_SYNC_LOCK_TTL );

        $client = ES_ERPNext_Client::from_settings( 30 );
        if ( ! $client ) {
            delete_transient( $lock_key );
            wp_safe_redirect( add_query_arg( 'es_row_msg', 'force-noerp-' . $order_id, self::row_action_redirect_target( $order_id ) ) );
            exit;
        }
        $so_name = self::derive_so_name( $order_id );
        $r = $client->post(
            '/api/method/woocommerce_fusion.tasks.sync_sales_orders.run_sales_order_sync',
            array( 'sales_order_name' => $so_name )
        );
        delete_transient( $lock_key );

        $code = is_wp_error( $r ) ? 'force-fail' : 'force-ok';
        wp_safe_redirect( add_query_arg( 'es_row_msg', $code . '-' . $order_id, self::row_action_redirect_target( $order_id ) ) );
        exit;
    }

    public static function row_action_admin_notice() {
        $msg = sanitize_text_field( $_GET['es_row_msg'] ?? '' );
        if ( '' === $msg ) {
            return;
        }
        // Message format: {prefix}-{order_id} or {prefix}-{order_id}-{flag}
        // where {prefix} is one of: repoll-done, no-tracking, force-ok, force-fail, force-busy, force-noerp.
        $known = array(
            'repoll-done' => 'repoll',
            'no-tracking' => 'noTracking',
            'force-ok'    => 'forceOk',
            'force-fail'  => 'forceFail',
            'force-busy'  => 'forceBusy',
            'force-noerp' => 'forceNoErp',
        );
        $matched_prefix = null;
        foreach ( array_keys( $known ) as $p ) {
            if ( 0 === strpos( $msg, $p . '-' ) ) {
                $matched_prefix = $p;
                break;
            }
        }
        if ( ! $matched_prefix ) {
            return;
        }
        $tail     = substr( $msg, strlen( $matched_prefix ) + 1 );
        $tail_p   = explode( '-', $tail );
        $order_id = intval( $tail_p[0] ?? 0 );
        $flag     = intval( $tail_p[1] ?? 0 );

        switch ( $matched_prefix ) {
            case 'repoll-done':
                $class = $flag ? 'notice-warning' : 'notice-success';
                $text  = sprintf(
                    /* translators: %d order id, %s suffix */
                    __( 'Order #%1$d re-polled%2$s.', 'erpnext-shipping' ),
                    $order_id,
                    $flag ? __( ' (with API errors)', 'erpnext-shipping' ) : ''
                );
                break;
            case 'no-tracking':
                $class = 'notice-info';
                $text  = sprintf( __( 'Order #%d has no tracking items to poll.', 'erpnext-shipping' ), $order_id );
                break;
            case 'force-ok':
                $class = 'notice-success';
                $text  = sprintf( __( 'ERPNext sync requested for order #%d.', 'erpnext-shipping' ), $order_id );
                break;
            case 'force-fail':
                $class = 'notice-error';
                $text  = sprintf( __( 'ERPNext sync failed for order #%d. Check Diagnostics tab for details.', 'erpnext-shipping' ), $order_id );
                break;
            case 'force-busy':
                $class = 'notice-warning';
                $text  = sprintf( __( 'A force-sync for order #%d is already in progress.', 'erpnext-shipping' ), $order_id );
                break;
            case 'force-noerp':
                $class = 'notice-error';
                $text  = __( 'ERPNext credentials are not configured.', 'erpnext-shipping' );
                break;
            default:
                return;
        }
        echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
    }

    // ── Bulk action: Re-poll ───────────────────────────────────────────────

    public static function add_bulk_actions( $actions ) {
        if ( current_user_can( 'manage_woocommerce' ) ) {
            $actions['es_bulk_repoll'] = __( 'Re-poll Courier Status', 'erpnext-shipping' );
        }
        return $actions;
    }

    public static function handle_bulk_repoll( $redirect, $action, $ids ) {
        if ( 'es_bulk_repoll' !== $action ) {
            return $redirect;
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return $redirect;
        }
        $ids = array_map( 'intval', (array) $ids );
        if ( count( $ids ) > self::BULK_REPOLL_LIMIT ) {
            return add_query_arg(
                array(
                    'es_bulk_repoll_error' => 'too_many',
                    'es_bulk_repoll_count' => count( $ids ),
                ),
                $redirect
            );
        }

        $tokens = ES_Fulfillment_Cron::resolve_tokens();
        $processed = 0;
        $errors    = 0;
        foreach ( $ids as $id ) {
            $order = wc_get_order( $id );
            if ( ! $order ) {
                continue;
            }
            $r = ES_Fulfillment_Cron::poll_single_order( $order, $tokens['tcg'], $tokens['mds'] );
            if ( null === $r ) {
                continue;
            }
            $processed++;
            if ( $r['had_failure'] ) {
                $errors++;
            }
            // Rate-limit between orders to avoid hammering carrier APIs.
            usleep( self::BULK_REPOLL_SLEEP_US );
        }

        return add_query_arg(
            array(
                'es_bulk_repoll_done'   => $processed,
                'es_bulk_repoll_errors' => $errors,
            ),
            $redirect
        );
    }

    public static function bulk_action_admin_notice() {
        if ( ! empty( $_GET['es_bulk_repoll_error'] ) && 'too_many' === $_GET['es_bulk_repoll_error'] ) {
            $n = intval( $_GET['es_bulk_repoll_count'] ?? 0 );
            echo '<div class="notice notice-error is-dismissible"><p>';
            printf(
                esc_html__( 'Re-poll Courier: selected %d orders, but the bulk action is capped at %d. Process in smaller batches.', 'erpnext-shipping' ),
                $n,
                self::BULK_REPOLL_LIMIT
            );
            echo '</p></div>';
            return;
        }
        if ( isset( $_GET['es_bulk_repoll_done'] ) ) {
            $done   = intval( $_GET['es_bulk_repoll_done'] );
            $errors = intval( $_GET['es_bulk_repoll_errors'] ?? 0 );
            $class  = $errors > 0 ? 'notice-warning' : 'notice-success';
            echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>';
            printf(
                esc_html__( 'Re-poll Courier: processed %d orders (%d with API errors).', 'erpnext-shipping' ),
                $done,
                $errors
            );
            echo '</p></div>';
        }
    }

    // ── AJAX: Re-poll ──────────────────────────────────────────────────────

    public static function ajax_repoll_order() {
        $order_id = intval( $_POST['order_id'] ?? 0 );
        $nonce    = $_POST['nonce'] ?? '';

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
        }
        if ( ! wp_verify_nonce( $nonce, 'es_order_actions_' . $order_id ) ) {
            wp_send_json_error( array( 'message' => 'Invalid nonce.' ), 400 );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( array( 'message' => 'Order not found.' ), 404 );
        }

        $result = ES_Fulfillment_Cron::poll_single_order( $order );
        if ( null === $result ) {
            wp_send_json_success( array( 'message' => __( 'No tracking items on this order.', 'erpnext-shipping' ) ) );
        }

        $parts = array();
        foreach ( $result['per_provider'] as $slug => $stats ) {
            $parts[] = sprintf( '%s: %d ok, %d fail', $slug, $stats['successes'], $stats['failures'] );
        }
        wp_send_json_success( array(
            'message' => sprintf(
                /* translators: %s = per-provider counts */
                __( 'Polled. %s', 'erpnext-shipping' ),
                implode( ' · ', $parts )
            ),
            'had_failure' => $result['had_failure'],
        ) );
    }

    // ── AJAX: Force ERPNext sync ───────────────────────────────────────────

    public static function ajax_force_sync() {
        $order_id = intval( $_POST['order_id'] ?? 0 );
        $nonce    = $_POST['nonce'] ?? '';

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
        }
        if ( ! wp_verify_nonce( $nonce, 'es_order_actions_' . $order_id ) ) {
            wp_send_json_error( array( 'message' => 'Invalid nonce.' ), 400 );
        }

        $lock_key = 'es_force_sync_lock_' . $order_id;
        if ( get_transient( $lock_key ) ) {
            wp_send_json_error( array(
                'message' => __( 'A force-sync for this order is already in progress.', 'erpnext-shipping' ),
            ), 429 );
        }
        set_transient( $lock_key, time(), self::FORCE_SYNC_LOCK_TTL );

        $client = ES_ERPNext_Client::from_settings( 30 );
        if ( ! $client ) {
            delete_transient( $lock_key );
            wp_send_json_error( array(
                'message' => __( 'ERPNext credentials are not configured in shipping method settings.', 'erpnext-shipping' ),
            ), 500 );
        }

        $so_name = self::derive_so_name( $order_id );
        $result  = $client->post(
            '/api/method/woocommerce_fusion.tasks.sync_sales_orders.run_sales_order_sync',
            array( 'sales_order_name' => $so_name )
        );

        delete_transient( $lock_key );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), 502 );
        }
        wp_send_json_success( array(
            'message' => sprintf(
                /* translators: %s = Sales Order name */
                __( 'Sync requested for %s.', 'erpnext-shipping' ),
                $so_name
            ),
        ) );
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * woocommerce_fusion uses a `WEB1-{padded order id}` naming series for
     * WooCommerce-synced Sales Orders. This holds for online orders. ERP-
     * generated orders use a different series, which is fine — this button
     * only appears on WC orders.
     */
    public static function derive_so_name( $order_id ) {
        return 'WEB1-' . str_pad( (string) $order_id, 6, '0', STR_PAD_LEFT );
    }

    /**
     * Pull the ERPNext URL from any shipping-method instance settings.
     */
    public static function resolve_erp_url() {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
                'woocommerce_erpnext_shipping_%_settings'
            )
        );
        foreach ( $rows as $row ) {
            $opts = maybe_unserialize( $row->option_value );
            if ( is_array( $opts ) && ! empty( $opts['erp_url'] ) ) {
                return rtrim( $opts['erp_url'], '/' );
            }
        }
        return '';
    }

    // ── Assets ─────────────────────────────────────────────────────────────

    public static function enqueue_assets( $hook ) {
        // Limit to order-related screens.
        $screen = get_current_screen();
        if ( ! $screen ) {
            return;
        }
        $order_screens = array( 'shop_order', 'edit-shop_order', 'woocommerce_page_wc-orders' );
        if ( ! in_array( $screen->id, $order_screens, true ) ) {
            return;
        }
        wp_enqueue_script(
            'es-order-actions',
            plugins_url( 'assets/js/es-order-actions.js', ES_SHIPPING_PATH . 'erpnext-shipping.php' ),
            array( 'jquery' ),
            ES_SHIPPING_VERSION,
            true
        );
        wp_localize_script( 'es-order-actions', 'esOrderActions', array(
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
        ) );
    }
}
