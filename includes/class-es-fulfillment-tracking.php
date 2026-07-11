<?php
defined( 'ABSPATH' ) || exit;

/**
 * Tracking meta storage and AST-compatible REST endpoint.
 * Meta key and format match AST Pro exactly for woocommerce_fusion compatibility.
 */
class ES_Fulfillment_Tracking {

    const META_KEY = '_wc_shipment_tracking_items';

    /**
     * Canonical provider slugs with display names, tracking URLs, and aliases.
     * Aliases cover legacy AST Pro slugs (e.g. `the-courier-guy-sa`, `collivery`)
     * and the display names that woocommerce_fusion echoes back from its dropdown.
     */
    private static $providers = array(
        'the-courier-guy' => array(
            'name'    => 'The Courier Guy',
            'url'     => 'https://www.thecourierguy.co.za/tracking?reference=%s',
            'aliases' => array( 'the-courier-guy-sa', 'tcg', 'courier guy', 'courierguy', 'courier-guy' ),
        ),
        'mds-collivery' => array(
            'name'    => 'MDS Collivery',
            'url'     => 'https://www.collivery.co.za/tracking/%s',
            'aliases' => array( 'collivery', 'mds', 'mds collivery' ),
        ),
    );

    /**
     * Map any plausible input (slug, display name, alias, legacy value, mixed case)
     * to a canonical slug. Returns the original sanitized value if no match — never
     * silently discards data. Used at every ingestion and lookup point.
     */
    public static function normalize_provider( $value ) {
        if ( ! is_scalar( $value ) ) {
            return '';
        }
        $needle = strtolower( trim( (string) $value ) );
        if ( '' === $needle ) {
            return '';
        }
        // Fast path: exact canonical slug.
        if ( isset( self::$providers[ $needle ] ) ) {
            return $needle;
        }
        // Fuzzier match: treat `-`, `_`, and whitespace as equivalent separators so
        // `the_courier_guy`, `Courier Guy`, and `courier-guy` all resolve.
        $needle_fuzzy = preg_replace( '/[_\-\s]+/', ' ', $needle );
        foreach ( self::$providers as $slug => $data ) {
            $candidates = array_merge(
                array( $slug, $data['name'] ),
                $data['aliases'] ?? array()
            );
            foreach ( $candidates as $candidate ) {
                $cand_fuzzy = preg_replace( '/[_\-\s]+/', ' ', strtolower( (string) $candidate ) );
                if ( $cand_fuzzy === $needle_fuzzy ) {
                    return $slug;
                }
            }
        }
        return sanitize_text_field( (string) $value );
    }

    /**
     * Return all string variants (slug + display name + aliases) that may appear
     * in stored `_wc_shipment_tracking_items` JSON for a given canonical slug.
     * Used by the admin order-list filter to OR-match legacy data.
     */
    public static function get_provider_search_terms( $slug ) {
        $slug = self::normalize_provider( $slug );
        if ( ! isset( self::$providers[ $slug ] ) ) {
            return array( $slug );
        }
        $data  = self::$providers[ $slug ];
        $terms = array_merge(
            array( $slug, $data['name'] ),
            $data['aliases'] ?? array()
        );
        return array_values( array_unique( array_filter( $terms ) ) );
    }

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );

        // Inject tracking info into WooCommerce order emails.
        add_action( 'woocommerce_email_order_details', array( __CLASS__, 'email_tracking_info' ), 50, 4 );

        // Customer-facing: tracking on My Account order detail page.
        add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'myaccount_order_tracking' ) );

        // Customer-facing: tracking column on My Account orders list.
        add_filter( 'woocommerce_my_account_my_orders_columns', array( __CLASS__, 'myaccount_orders_column' ) );
        add_action( 'woocommerce_my_account_my_orders_column_es-tracking', array( __CLASS__, 'myaccount_orders_column_content' ) );
    }

    /**
     * Register AST-compatible REST endpoints under BOTH namespaces.
     * - wc/v3: used by woocommerce_fusion (its wc_api client prepends wc/v3/)
     * - wc-shipment-tracking/v3: used by AST Pro's own clients
     */
    public static function register_rest_routes() {
        $namespaces = array( 'wc/v3', 'wc-shipment-tracking/v3' );

        foreach ( $namespaces as $ns ) {
            register_rest_route( $ns, '/orders/(?P<order_id>\d+)/shipment-trackings', array(
                array(
                    'methods'             => 'GET',
                    'callback'            => array( __CLASS__, 'get_trackings' ),
                    'permission_callback' => array( __CLASS__, 'check_read_permission' ),
                ),
                array(
                    'methods'             => 'POST',
                    'callback'            => array( __CLASS__, 'add_tracking' ),
                    'permission_callback' => array( __CLASS__, 'check_write_permission' ),
                ),
            ) );

            register_rest_route( $ns, '/orders/(?P<order_id>\d+)/shipment-trackings/(?P<tracking_id>[a-f0-9]+)', array(
                array(
                    'methods'             => 'DELETE',
                    'callback'            => array( __CLASS__, 'delete_tracking' ),
                    'permission_callback' => array( __CLASS__, 'check_write_permission' ),
                ),
            ) );

            // Providers list — woocommerce_fusion calls this on validate.
            register_rest_route( $ns, '/orders/(?P<order_id>\d+)/shipment-trackings/providers', array(
                array(
                    'methods'             => 'GET',
                    'callback'            => array( __CLASS__, 'get_providers_rest' ),
                    'permission_callback' => array( __CLASS__, 'check_read_permission' ),
                ),
            ) );
        }
    }

    /**
     * Permission check — supports both cookie auth and WC API key auth.
     * WC consumer keys only authenticate requests to WC's own namespace (wc/v3).
     * Since we use the wc-shipment-tracking/v3 namespace (for AST compatibility),
     * we must manually validate WC API keys via WC_REST_Authentication.
     */
    public static function check_read_permission( $request ) {
        return self::check_permission( $request, array( 'read', 'read_write' ) );
    }

    public static function check_write_permission( $request ) {
        return self::check_permission( $request, array( 'read_write' ) );
    }

    /**
     * Permission check — supports both cookie auth and WC API key auth.
     * WC consumer keys only authenticate requests to WC's own namespace (wc/v3).
     * Since we use the wc-shipment-tracking/v3 namespace (for AST compatibility),
     * we must manually validate WC API keys via the woocommerce_api_keys table.
     *
     * @param WP_REST_Request $request        The REST request.
     * @param array           $allowed_perms  Allowed permission levels (e.g. ['read', 'read_write']).
     */
    private static function check_permission( $request, $allowed_perms ) {
        // Cookie auth (logged-in admin in browser).
        if ( current_user_can( 'manage_woocommerce' ) ) {
            return true;
        }

        // WC API key auth (woocommerce_fusion, external integrations).
        // Parse Basic Auth credentials from the request.
        $consumer_key = '';
        if ( ! empty( $_SERVER['PHP_AUTH_USER'] ) ) {
            $consumer_key = sanitize_text_field( $_SERVER['PHP_AUTH_USER'] );
        } elseif ( ! empty( $request->get_header( 'authorization' ) ) ) {
            $auth = $request->get_header( 'authorization' );
            if ( 0 === stripos( $auth, 'basic ' ) ) {
                $decoded = base64_decode( substr( $auth, 6 ) );
                $parts   = explode( ':', $decoded, 2 );
                $consumer_key = sanitize_text_field( $parts[0] ?? '' );
            }
        }

        if ( empty( $consumer_key ) ) {
            return false;
        }

        // Look up the consumer key in WooCommerce's API keys table.
        global $wpdb;
        $key = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT key_id, user_id, permissions, consumer_secret
                 FROM {$wpdb->prefix}woocommerce_api_keys
                 WHERE consumer_key = %s",
                wc_api_hash( $consumer_key )
            )
        );

        if ( ! $key ) {
            return false;
        }

        // Verify the consumer secret.
        $consumer_secret = '';
        if ( ! empty( $_SERVER['PHP_AUTH_PW'] ) ) {
            $consumer_secret = $_SERVER['PHP_AUTH_PW'];
        } elseif ( ! empty( $request->get_header( 'authorization' ) ) ) {
            $auth = $request->get_header( 'authorization' );
            if ( 0 === stripos( $auth, 'basic ' ) ) {
                $decoded = base64_decode( substr( $auth, 6 ) );
                $parts   = explode( ':', $decoded, 2 );
                $consumer_secret = $parts[1] ?? '';
            }
        }

        if ( ! hash_equals( $key->consumer_secret, $consumer_secret ) ) {
            return false;
        }

        // Match WooCommerce's normal API-key authorization boundary: the key's
        // permission alone is not enough; its owning user must also be allowed
        // to read/edit orders. Without this, a key assigned to a low-privilege
        // user could access tracking data for arbitrary orders through this
        // custom namespace.
        if ( ! in_array( $key->permissions, $allowed_perms, true ) ) {
            return false;
        }

        $required_capability = in_array( 'read', $allowed_perms, true )
            ? 'read_private_shop_orders'
            : 'edit_shop_orders';

        return user_can( (int) $key->user_id, $required_capability );
    }

    /**
     * GET: Return available shipping providers.
     * Format matches AST Pro: { "Region": { "Provider Name": "tracking_url" } }
     * woocommerce_fusion parses this to populate its provider dropdown.
     */
    public static function get_providers_rest( $request ) {
        $result = array(
            'South Africa' => array(),
        );
        foreach ( self::$providers as $slug => $data ) {
            // Convert sprintf-style URL (%s) to AST-style (%number%).
            $url = str_replace( '%s', '%number%', $data['url'] );
            $result['South Africa'][ $data['name'] ] = $url;
        }
        return rest_ensure_response( $result );
    }

    /**
     * GET: Return all tracking items for an order.
     */
    public static function get_trackings( $request ) {
        $order = wc_get_order( $request['order_id'] );
        if ( ! $order ) {
            return new WP_Error( 'not_found', 'Order not found', array( 'status' => 404 ) );
        }

        $items = self::get_tracking_items( $order );
        return rest_ensure_response( $items );
    }

    /**
     * POST: Add a tracking entry.
     */
    public static function add_tracking( $request ) {
        $order = wc_get_order( $request['order_id'] );
        if ( ! $order ) {
            return new WP_Error( 'not_found', 'Order not found', array( 'status' => 404 ) );
        }

        $params = $request->get_json_params();
        if ( empty( $params ) ) {
            $params = $request->get_body_params();
        }

        $tracking_number = sanitize_text_field( $params['tracking_number'] ?? '' );
        if ( empty( $tracking_number ) ) {
            return new WP_Error( 'missing_field', 'tracking_number is required', array( 'status' => 400 ) );
        }

        $provider    = sanitize_text_field( $params['tracking_provider'] ?? '' );
        $raw_date    = $params['date_shipped'] ?? '';
        $date_shipped = is_numeric( $raw_date ) ? intval( $raw_date ) : ( strtotime( $raw_date ) ?: time() );
        $custom_link = esc_url_raw( $params['custom_tracking_link'] ?? '' );

        $item = self::create_tracking_item( $provider, $tracking_number, $date_shipped, $custom_link );
        self::save_tracking_item( $order, $item );

        return rest_ensure_response( $item );
    }

    /**
     * DELETE: Remove a tracking entry by tracking_id.
     */
    public static function delete_tracking( $request ) {
        $order = wc_get_order( $request['order_id'] );
        if ( ! $order ) {
            return new WP_Error( 'not_found', 'Order not found', array( 'status' => 404 ) );
        }

        $tracking_id = sanitize_text_field( $request['tracking_id'] );
        $items       = self::get_tracking_items( $order );
        $found       = false;

        foreach ( $items as $i => $item ) {
            if ( $item['tracking_id'] === $tracking_id ) {
                unset( $items[ $i ] );
                $found = true;
                break;
            }
        }

        if ( ! $found ) {
            return new WP_Error( 'not_found', 'Tracking entry not found', array( 'status' => 404 ) );
        }

        $remaining = array_values( $items );
        if ( empty( $remaining ) ) {
            // Remove meta entirely so NOT EXISTS filters and cron queries work correctly.
            $order->delete_meta_data( self::META_KEY );
            $order->delete_meta_data( '_es_courier_status' );
            $order->delete_meta_data( '_es_last_polled' );
        } else {
            $order->update_meta_data( self::META_KEY, $remaining );
        }
        $order->save();

        return rest_ensure_response( array( 'message' => 'Tracking entry deleted' ) );
    }

    // ── Public helpers (used by admin, cron, emails) ──

    /**
     * Get all tracking items for an order.
     */
    public static function get_tracking_items( $order ) {
        $items = $order->get_meta( self::META_KEY, true );
        if ( ! is_array( $items ) ) {
            return array();
        }
        return $items;
    }

    /**
     * Create a tracking item array.
     */
    public static function create_tracking_item( $provider, $tracking_number, $date_shipped = 0, $custom_link = '' ) {
        if ( ! $date_shipped ) {
            $date_shipped = time();
        }
        // Single ingestion chokepoint — REST, admin AJAX, and any other caller
        // all flow through here, so normalize once and store canonical slugs.
        return array(
            'tracking_provider'    => self::normalize_provider( $provider ),
            'tracking_number'      => $tracking_number,
            'tracking_id'          => md5( $tracking_number . '-' . $date_shipped ),
            'date_shipped'         => $date_shipped,
            'custom_tracking_link' => $custom_link,
        );
    }

    /**
     * Save a tracking item to an order (appends to existing items).
     */
    public static function save_tracking_item( $order, $item ) {
        $items   = self::get_tracking_items( $order );
        $items[] = $item;
        $order->update_meta_data( self::META_KEY, $items );
        $order->save();
    }

    /**
     * Get the tracking URL for a tracking item.
     */
    public static function get_tracking_url( $item ) {
        if ( ! empty( $item['custom_tracking_link'] ) ) {
            return $item['custom_tracking_link'];
        }
        // Normalize at read time so legacy stored values (display names, old slugs) still resolve.
        $provider = self::normalize_provider( $item['tracking_provider'] ?? '' );
        if ( isset( self::$providers[ $provider ] ) ) {
            return sprintf( self::$providers[ $provider ]['url'], urlencode( $item['tracking_number'] ) );
        }
        return '';
    }

    /**
     * Get provider display name.
     */
    public static function get_provider_name( $slug ) {
        $slug = self::normalize_provider( $slug );
        return self::$providers[ $slug ]['name'] ?? $slug;
    }

    /**
     * Get all known providers.
     */
    public static function get_providers() {
        return self::$providers;
    }

    /**
     * Format raw courier status string for customer display.
     */
    public static function format_courier_status( $raw_status ) {
        $labels = array(
            'tcg:created'          => __( 'Processing', 'erpnext-shipping' ),
            'tcg:collected'        => __( 'Collected', 'erpnext-shipping' ),
            'tcg:in-transit'       => __( 'In Transit', 'erpnext-shipping' ),
            'tcg:out-for-delivery' => __( 'Out For Delivery', 'erpnext-shipping' ),
            'tcg:delivered'        => __( 'Delivered', 'erpnext-shipping' ),
            'mds:7'               => __( 'Collected', 'erpnext-shipping' ),
            'mds:9'               => __( 'In Transit', 'erpnext-shipping' ),
            'mds:8'               => __( 'Delivered', 'erpnext-shipping' ),
            'mds:32'              => __( 'Delivered', 'erpnext-shipping' ),
        );

        $parts = array_map( 'trim', explode( ',', $raw_status ) );
        $readable = array();
        foreach ( $parts as $part ) {
            $readable[] = $labels[ $part ] ?? $part;
        }
        return implode( ', ', array_unique( $readable ) );
    }

    /**
     * Inject tracking info into WooCommerce order emails.
     * Fires after order details table on shipped/delivered/completed emails.
     */
    public static function email_tracking_info( $order, $sent_to_admin, $plain_text, $email ) {
        $items = self::get_tracking_items( $order );
        if ( empty( $items ) ) {
            return;
        }

        // Only show on WC core emails that don't have their own tracking template.
        // Our plugin emails (es_partially_shipped, es_order_delivered) render
        // tracking inline via their template files — skip them here to avoid duplication.
        $show_on = array(
            'customer_completed_order',  // Shipped (WC core)
            'customer_invoice',          // Invoice (WC core)
        );

        $email_id = $email->id ?? '';
        if ( ! empty( $email_id ) && ! in_array( $email_id, $show_on, true ) ) {
            return;
        }

        if ( $plain_text ) {
            self::render_tracking_email_plain( $items );
        } else {
            self::render_tracking_email_html( $items );
        }
    }

    /**
     * Render tracking info for HTML emails.
     */
    private static function render_tracking_email_html( $items ) {
        echo '<h2 style="color:#7f54b3; display:block; font-family:&quot;Helvetica Neue&quot;,Helvetica,Roboto,Arial,sans-serif; font-size:18px; font-weight:bold; line-height:130%; margin:16px 0 8px; text-align:left;">';
        echo esc_html__( 'Shipment Tracking', 'erpnext-shipping' );
        echo '</h2>';
        echo '<div style="margin-bottom:16px;">';

        foreach ( $items as $item ) {
            $provider_name = self::get_provider_name( $item['tracking_provider'] );
            $url           = self::get_tracking_url( $item );
            $date          = date_i18n( get_option( 'date_format' ), $item['date_shipped'] );

            echo '<table cellspacing="0" cellpadding="6" style="width:100%; border:1px solid #e5e5e5; margin-bottom:8px;" border="1">';
            echo '<tr>';
            echo '<td style="padding:12px; border:1px solid #e5e5e5;"><strong>' . esc_html( $provider_name ) . '</strong></td>';
            echo '<td style="padding:12px; border:1px solid #e5e5e5;">';
            if ( $url ) {
                echo '<a href="' . esc_url( $url ) . '" style="color:#7f54b3; text-decoration:underline;">' . esc_html( $item['tracking_number'] ) . '</a>';
            } else {
                echo esc_html( $item['tracking_number'] );
            }
            echo '</td>';
            echo '<td style="padding:12px; border:1px solid #e5e5e5;">' . esc_html( $date ) . '</td>';
            echo '</tr>';
            echo '</table>';
        }

        echo '</div>';
    }

    /**
     * Render tracking info for plain text emails.
     */
    private static function render_tracking_email_plain( $items ) {
        echo "\n" . esc_html__( 'Shipment Tracking', 'erpnext-shipping' ) . "\n";
        echo str_repeat( '-', 40 ) . "\n";

        foreach ( $items as $item ) {
            $provider_name = self::get_provider_name( $item['tracking_provider'] );
            $url           = self::get_tracking_url( $item );
            $date          = date_i18n( get_option( 'date_format' ), $item['date_shipped'] );

            echo esc_html( $provider_name ) . ': ' . esc_html( $item['tracking_number'] ) . "\n";
            if ( $url ) {
                echo esc_html__( 'Track: ', 'erpnext-shipping' ) . esc_url( $url ) . "\n";
            }
            echo esc_html__( 'Shipped: ', 'erpnext-shipping' ) . esc_html( $date ) . "\n\n";
        }
    }

    // ── My Account: Order Detail Page ──

    /**
     * Show tracking info on the customer's order detail page (My Account > View Order).
     */
    public static function myaccount_order_tracking( $order ) {
        $items = self::get_tracking_items( $order );
        if ( empty( $items ) ) {
            return;
        }
        ?>
        <?php
        // Determine order-level status for display below the table.
        $courier_status = $order->get_meta( '_es_courier_status', true );
        if ( $courier_status ) {
            $status_label = self::format_courier_status( $courier_status );
        } else {
            $status_label = 'delivered' === $order->get_status()
                ? __( 'Delivered', 'erpnext-shipping' )
                : __( 'Shipped', 'erpnext-shipping' );
        }
        ?>
        <h2><?php esc_html_e( 'Shipment Tracking', 'erpnext-shipping' ); ?></h2>
        <table class="woocommerce-table shop_table es-tracking-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Carrier', 'erpnext-shipping' ); ?></th>
                    <th><?php esc_html_e( 'Tracking Number', 'erpnext-shipping' ); ?></th>
                    <th><?php esc_html_e( 'Shipped', 'erpnext-shipping' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $items as $item ) :
                    $provider_name = self::get_provider_name( $item['tracking_provider'] );
                    $url           = self::get_tracking_url( $item );
                    $date          = date_i18n( get_option( 'date_format' ), $item['date_shipped'] );
                ?>
                <tr>
                    <td><?php echo esc_html( $provider_name ); ?></td>
                    <td>
                        <?php if ( $url ) : ?>
                            <a href="<?php echo esc_url( $url ); ?>" target="_blank"><?php echo esc_html( $item['tracking_number'] ); ?></a>
                        <?php else : ?>
                            <?php echo esc_html( $item['tracking_number'] ); ?>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html( $date ); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p style="margin-top:8px;">
            <strong><?php esc_html_e( 'Status:', 'erpnext-shipping' ); ?></strong>
            <?php echo esc_html( $status_label ); ?>
        </p>
        <?php
    }

    // ── My Account: Orders List Column ──

    /**
     * Add a Tracking column to the My Account orders list.
     */
    public static function myaccount_orders_column( $columns ) {
        // Insert before 'order-actions'.
        $new_columns = array();
        foreach ( $columns as $key => $label ) {
            if ( 'order-actions' === $key ) {
                $new_columns['es-tracking'] = __( 'Tracking', 'erpnext-shipping' );
            }
            $new_columns[ $key ] = $label;
        }
        // Fallback if order-actions wasn't found.
        if ( ! isset( $new_columns['es-tracking'] ) ) {
            $new_columns['es-tracking'] = __( 'Tracking', 'erpnext-shipping' );
        }
        return $new_columns;
    }

    /**
     * Render the Tracking column content on the My Account orders list.
     */
    public static function myaccount_orders_column_content( $order ) {
        $items = self::get_tracking_items( $order );
        if ( empty( $items ) ) {
            echo '&ndash;';
            return;
        }

        foreach ( $items as $item ) {
            $provider_name = self::get_provider_name( $item['tracking_provider'] );
            $url           = self::get_tracking_url( $item );

            echo '<span style="font-size:12px;">';
            echo esc_html( $provider_name ) . ': ';
            if ( $url ) {
                echo '<a href="' . esc_url( $url ) . '" target="_blank">' . esc_html( $item['tracking_number'] ) . '</a>';
            } else {
                echo esc_html( $item['tracking_number'] );
            }
            echo '</span><br>';
        }
    }
}

ES_Fulfillment_Tracking::init();
