<?php
defined( 'ABSPATH' ) || exit;

/**
 * Tracking meta storage and AST-compatible REST endpoint.
 * Meta key and format match AST Pro exactly for woocommerce_fusion compatibility.
 */
class ES_Fulfillment_Tracking {

    const META_KEY = '_wc_shipment_tracking_items';

    /**
     * Known provider slugs and their display names / tracking URLs.
     */
    private static $providers = array(
        'the-courier-guy' => array(
            'name' => 'The Courier Guy',
            'url'  => 'https://www.thecourierguy.co.za/tracking?reference=%s',
        ),
        'mds-collivery' => array(
            'name' => 'MDS Collivery',
            'url'  => 'https://www.collivery.co.za/tracking/%s',
        ),
    );

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
    }

    /**
     * Register the AST-compatible REST endpoint.
     * Namespace matches AST Pro so woocommerce_fusion doesn't need changes.
     */
    public static function register_rest_routes() {
        register_rest_route( 'wc-shipment-tracking/v3', '/orders/(?P<order_id>\d+)/shipment-trackings', array(
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

        register_rest_route( 'wc-shipment-tracking/v3', '/orders/(?P<order_id>\d+)/shipment-trackings/(?P<tracking_id>[a-f0-9]+)', array(
            array(
                'methods'             => 'DELETE',
                'callback'            => array( __CLASS__, 'delete_tracking' ),
                'permission_callback' => array( __CLASS__, 'check_write_permission' ),
            ),
        ) );
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

        // Check the key has the required permission level.
        return in_array( $key->permissions, $allowed_perms, true );
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

        $provider     = sanitize_text_field( $params['tracking_provider'] ?? '' );
        $date_shipped = intval( $params['date_shipped'] ?? time() );
        $custom_link  = esc_url_raw( $params['custom_tracking_link'] ?? '' );

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

        $order->update_meta_data( self::META_KEY, array_values( $items ) );
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
        return array(
            'tracking_provider'    => $provider,
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
        $provider = $item['tracking_provider'] ?? '';
        if ( isset( self::$providers[ $provider ] ) ) {
            return sprintf( self::$providers[ $provider ]['url'], urlencode( $item['tracking_number'] ) );
        }
        return '';
    }

    /**
     * Get provider display name.
     */
    public static function get_provider_name( $slug ) {
        return self::$providers[ $slug ]['name'] ?? $slug;
    }

    /**
     * Get all known providers.
     */
    public static function get_providers() {
        return self::$providers;
    }
}

ES_Fulfillment_Tracking::init();
