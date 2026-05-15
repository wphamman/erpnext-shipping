<?php
defined( 'ABSPATH' ) || exit;

/**
 * Thin HTTP client for ERPNext.
 *
 * Reads creds from any ES_Shipping_Method instance's WP options
 * (woocommerce_erpnext_shipping_*_settings). Constructed via from_settings()
 * for the shared singleton, or directly with an array for callers that already
 * hold creds.
 */
class ES_ERPNext_Client {

    /** @var string */
    private $url;
    /** @var string */
    private $key;
    /** @var string */
    private $secret;
    /** @var int */
    private $timeout;
    /** @var string Last error from a failed request. */
    public $last_error = '';

    public function __construct( $settings, $timeout = 5 ) {
        $this->url     = rtrim( $settings['url'] ?? '', '/' );
        $this->key     = $settings['api_key'] ?? '';
        $this->secret  = $settings['api_secret'] ?? '';
        $this->timeout = $timeout;
    }

    /**
     * Build a client from the first shipping-method instance that has ERPNext
     * credentials configured. Returns null if no instance is configured.
     *
     * @param int $timeout Request timeout in seconds.
     * @return ES_ERPNext_Client|null
     */
    public static function from_settings( $timeout = 5 ) {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
                'woocommerce_erpnext_shipping_%_settings'
            )
        );
        foreach ( $rows as $row ) {
            $opts = maybe_unserialize( $row->option_value );
            if ( ! is_array( $opts ) ) {
                continue;
            }
            if ( ! empty( $opts['erp_url'] ) && ! empty( $opts['erp_api_key'] ) && ! empty( $opts['erp_api_secret'] ) ) {
                return new self( array(
                    'url'        => $opts['erp_url'],
                    'api_key'    => $opts['erp_api_key'],
                    'api_secret' => $opts['erp_api_secret'],
                ), $timeout );
            }
        }
        return null;
    }

    public function is_configured() {
        return ! empty( $this->url ) && ! empty( $this->key ) && ! empty( $this->secret );
    }

    public function get_base_url() {
        return $this->url;
    }

    /**
     * Issue a GET against /api/resource/{Doctype} or /api/method/{path}.
     *
     * @param string $endpoint Path beginning with `/api/...` or just `/{doctype}` (we'll prefix `/api/resource/`).
     * @param array  $params   Query string params (will be urlencoded).
     * @return array|WP_Error Decoded JSON body, or WP_Error.
     */
    public function get( $endpoint, $params = array() ) {
        return $this->request( 'GET', $endpoint, $params );
    }

    public function post( $endpoint, $data = array() ) {
        return $this->request( 'POST', $endpoint, $data );
    }

    /**
     * Probe connectivity with a cheap endpoint. Returns true / WP_Error.
     */
    public function test_connection() {
        $result = $this->get( '/api/method/frappe.auth.get_logged_user' );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        if ( empty( $result['message'] ) ) {
            return new WP_Error( 'es_erpnext_unexpected', 'Unexpected response from ERPNext.' );
        }
        return true;
    }

    private function request( $method, $endpoint, $payload ) {
        if ( ! $this->is_configured() ) {
            $this->last_error = 'ERPNext credentials not configured.';
            return new WP_Error( 'es_erpnext_not_configured', $this->last_error );
        }

        // Allow callers to pass a bare doctype slug ("Sales Order") or a full path ("/api/resource/Sales Order").
        if ( strpos( $endpoint, '/api/' ) !== 0 ) {
            $endpoint = '/api/resource/' . ltrim( $endpoint, '/' );
        }

        $url  = $this->url . $endpoint;
        $args = array(
            'headers' => array(
                'Authorization' => 'token ' . $this->key . ':' . $this->secret,
                'Accept'        => 'application/json',
            ),
            'timeout' => $this->timeout,
        );

        if ( 'GET' === $method ) {
            if ( ! empty( $payload ) ) {
                $url = add_query_arg( array_map( function ( $v ) {
                    return is_array( $v ) ? wp_json_encode( $v ) : $v;
                }, $payload ), $url );
            }
            $response = wp_remote_get( $url, $args );
        } else {
            $args['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
            $args['body'] = $payload;
            $response = wp_remote_post( $url, $args );
        }

        if ( is_wp_error( $response ) ) {
            $this->last_error = 'HTTP error: ' . $response->get_error_message();
            $this->log_failure( $method, $endpoint, $this->last_error );
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        $body = json_decode( $raw, true );

        if ( $code >= 400 ) {
            $msg = '';
            if ( is_array( $body ) ) {
                $msg = $body['message'] ?? $body['exc_type'] ?? '';
                if ( empty( $msg ) ) {
                    $msg = substr( $raw, 0, 200 );
                }
            } else {
                $msg = substr( $raw, 0, 200 );
            }
            $this->last_error = sprintf( 'ERPNext HTTP %d: %s', $code, $msg );
            $this->log_failure( $method, $endpoint, $this->last_error );
            return new WP_Error( 'es_erpnext_http_' . $code, $this->last_error, array( 'status' => $code ) );
        }

        if ( ! is_array( $body ) ) {
            $this->last_error = 'ERPNext returned non-JSON response.';
            return new WP_Error( 'es_erpnext_bad_response', $this->last_error );
        }

        return $body;
    }

    private function log_failure( $method, $endpoint, $msg ) {
        wc_get_logger()->error(
            sprintf( 'ERPNext client %s %s failed: %s', $method, $endpoint, $msg ),
            array( 'source' => 'erpnext-shipping' )
        );
    }
}
