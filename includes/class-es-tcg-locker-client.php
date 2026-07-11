<?php
/**
 * TCG Locker API client (Locker-to-Locker MVP).
 *
 * Isolated, testable client for the TCG Locker (PUDO) API. The HTTP transport,
 * cache, and logger are all injected so the core logic runs with no WordPress
 * dependency and no network access under test.
 *
 * Contract authority: the published Postman collection
 * https://api-docs.tcglocker.co.za/ (TCG LOCKER SANDBOX API). See
 * docs/tcg-locker-architecture.md. Sandbox by default; the API base is
 * configurable and validated to end exactly in /api/v1.
 *
 * SECURITY: the Bearer token and the api_key query parameter on label URLs must
 * NEVER appear in logs, order notes, AJAX/REST responses, or exception messages.
 * Every logged string passes through self::redact(). Label URLs are composed
 * server-side and are never logged or returned to the browser.
 *
 * @package ERPNext_Shipping
 */

defined( 'ABSPATH' ) || exit;

/**
 * HTTP transport seam. Implementations perform the actual request; the client
 * never calls wp_remote_* directly, so tests inject a fake transport.
 */
interface ES_TCG_Locker_Transport {
	/**
	 * @param string      $method  HTTP method.
	 * @param string      $url     Absolute URL.
	 * @param array       $headers Header map.
	 * @param string|null $body    Raw request body or null.
	 * @param int         $timeout Seconds.
	 * @return array{code:int, body:string, error:?string, content_type?:string}
	 */
	public function request( $method, $url, array $headers, $body, $timeout );
}

/**
 * Cache seam. get() returns the stored value or null; set() stores with a TTL
 * in seconds (0 = no expiry). Tests inject an in-memory cache.
 */
interface ES_TCG_Locker_Cache {
	public function get( $key );
	public function set( $key, $value, $ttl );
}

/**
 * Default transport backed by the WP HTTP API.
 */
class ES_TCG_Locker_WP_Transport implements ES_TCG_Locker_Transport {
	public function request( $method, $url, array $headers, $body, $timeout ) {
		$args = array(
			'method'  => $method,
			'headers' => $headers,
			'timeout' => $timeout,
		);
		if ( null !== $body ) {
			$args['body'] = $body;
		}
		$resp = wp_remote_request( $url, $args );
		if ( is_wp_error( $resp ) ) {
			return array(
				'code'  => 0,
				'body'  => '',
				'error' => ES_TCG_Locker_Client::redact( $resp->get_error_message() ),
			);
		}
		return array(
			'code'         => (int) wp_remote_retrieve_response_code( $resp ),
			'body'         => (string) wp_remote_retrieve_body( $resp ),
			'error'        => null,
			'content_type' => (string) wp_remote_retrieve_header( $resp, 'content-type' ),
		);
	}
}

/**
 * Default cache backed by WP transients.
 */
class ES_TCG_Locker_Transient_Cache implements ES_TCG_Locker_Cache {
	public function get( $key ) {
		$v = get_transient( $key );
		return ( false === $v ) ? null : $v;
	}
	public function set( $key, $value, $ttl ) {
		set_transient( $key, $value, $ttl );
	}
}

class ES_TCG_Locker_Client {

	const LOCKERS_TTL     = 86400; // 24h.
	const DEFAULT_TIMEOUT = 15;

	const OPTIN_PATH        = '/rates/opt-in';
	const RATES_PATH        = '/rates';
	const LOCKERS_PATH      = '/lockers-data';
	const LOCKER_RATES_PATH = '/locker-rates';
	const SHIPMENTS_PATH    = '/shipments';
	const TRACKING_PATH     = '/tracking/shipments';

	/** @var string API base, e.g. https://sandbox.api-pudo.co.za/api/v1 */
	private $api_base;
	/** @var string Origin (base minus /api/v1) for /generate/* label URLs. */
	private $origin;
	/** @var string */
	private $token;
	/** @var ES_TCG_Locker_Transport */
	private $transport;
	/** @var ES_TCG_Locker_Cache|null */
	private $cache;
	/** @var callable|null function( string $level, string $message ) */
	private $logger;
	/** @var int */
	private $timeout;

	public function __construct( $api_base, $token, ES_TCG_Locker_Transport $transport, ES_TCG_Locker_Cache $cache = null, $logger = null, $timeout = self::DEFAULT_TIMEOUT ) {
		$this->api_base  = self::normalize_base( $api_base );
		$this->origin    = self::derive_origin( $this->api_base );
		$this->token     = (string) $token;
		$this->transport = $transport;
		$this->cache     = $cache;
		$this->logger    = is_callable( $logger ) ? $logger : null;
		$this->timeout   = (int) $timeout > 0 ? (int) $timeout : self::DEFAULT_TIMEOUT;
	}

	// ─────────────────────────── URL helpers ───────────────────────────

	public static function normalize_base( $url ) {
		return rtrim( trim( (string) $url ), '/' );
	}

	/**
	 * Derive the origin by stripping a terminal /api/v1 segment.
	 * Assumes a base that passed is_valid_api_base().
	 */
	public static function derive_origin( $api_base ) {
		return preg_replace( '#/api/v1$#', '', self::normalize_base( $api_base ) );
	}

	/**
	 * Validate an API base: http(s) scheme, a host, and a path that ends
	 * EXACTLY in /api/v1 (trailing slash tolerated). A URL that merely
	 * contains /api/v1 elsewhere (e.g. .../api/v1/foo) is rejected, because
	 * origin derivation only strips a terminal segment.
	 */
	public static function is_valid_api_base( $url ) {
		$url = self::normalize_base( $url );
		if ( '' === $url ) {
			return false;
		}
		$scheme = parse_url( $url, PHP_URL_SCHEME );
		$host   = parse_url( $url, PHP_URL_HOST );
		$path   = parse_url( $url, PHP_URL_PATH );
		return in_array( $scheme, array( 'http', 'https' ), true ) && ! empty( $host ) && '/api/v1' === $path;
	}

	public function get_api_base() {
		return $this->api_base;
	}

	public function get_origin() {
		return $this->origin;
	}

	/**
	 * Strip credentials from any string before it is logged or surfaced.
	 * Removes api_key query values and Bearer tokens. Idempotent.
	 */
	public static function redact( $str ) {
		$str = (string) $str;
		$str = preg_replace( '/(api_key=)[^&\s"\']+/i', '$1[REDACTED]', $str );
		$str = preg_replace( '/(Bearer\s+)[A-Za-z0-9._\-]+/i', '$1[REDACTED]', $str );
		return $str;
	}

	// ─────────────────────────── Core request ──────────────────────────

	private function auth_headers( array $extra = array() ) {
		return array_merge(
			array(
				'Authorization' => 'Bearer ' . $this->token,
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
			),
			$extra
		);
	}

	/**
	 * JSON API request against {api_base}{path}. Returns a structured result:
	 *   success: [ 'ok' => true, 'data' => mixed, 'code' => int ]
	 *   failure: [ 'ok' => false, 'error' => string, ... ]
	 * Only the relative $path is ever logged (never the token or full URL).
	 */
	private function api_request( $method, $path, $body_array = null ) {
		$url  = $this->api_base . $path;
		$body = ( null === $body_array ) ? null : json_encode( $body_array );

		$res = $this->transport->request( $method, $url, $this->auth_headers(), $body, $this->timeout );

		if ( ! empty( $res['error'] ) ) {
			$this->log( 'error', $method . ' ' . $path . ' transport error: ' . self::redact( $res['error'] ) );
			return array( 'ok' => false, 'error' => 'transport' );
		}

		$code = (int) ( $res['code'] ?? 0 );
		if ( $code < 200 || $code >= 300 ) {
			$this->log( 'error', $method . ' ' . $path . ' HTTP ' . $code );
			return array( 'ok' => false, 'error' => 'http', 'code' => $code );
		}

		$raw = (string) ( $res['body'] ?? '' );
		if ( '' === trim( $raw ) ) {
			return array( 'ok' => true, 'data' => null, 'code' => $code );
		}

		$data = json_decode( $raw, true );
		if ( null === $data ) {
			$this->log( 'error', $method . ' ' . $path . ' non-JSON response' );
			return array( 'ok' => false, 'error' => 'decode' );
		}
		return array( 'ok' => true, 'data' => $data, 'code' => $code );
	}

	// ─────────────────────────── Lockers ───────────────────────────────

	/**
	 * Get the locker dataset, cached 24h and namespaced by API base.
	 *
	 * Never overwrites good cached data with an empty/error response; falls
	 * back to the last-known-good list when a refresh fails. Empty/error is
	 * never cached as success.
	 *
	 * @return array Normalised locker records (possibly empty).
	 */
	public function get_lockers( $force_refresh = false ) {
		$key     = $this->cache_key( 'data' );
		$lkg_key = $this->cache_key( 'lkg' );

		if ( ! $force_refresh && $this->cache ) {
			$cached = $this->cache->get( $key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$res     = $this->api_request( 'GET', self::LOCKERS_PATH );
		$lockers = ( $res['ok'] ?? false ) ? $this->extract_lockers( $res['data'] ) : null;

		if ( is_array( $lockers ) && ! empty( $lockers ) ) {
			if ( $this->cache ) {
				$this->cache->set( $key, $lockers, self::LOCKERS_TTL );
				$this->cache->set( $lkg_key, $lockers, 0 ); // Last-known-good, no expiry.
			}
			return $lockers;
		}

		// Refresh failed or returned nothing usable — do NOT cache, fall back to LKG.
		$this->log( 'warning', 'lockers-data refresh failed or empty; using last-known-good' );
		if ( $this->cache ) {
			$lkg = $this->cache->get( $lkg_key );
			if ( is_array( $lkg ) ) {
				return $lkg;
			}
		}
		return array();
	}

	/**
	 * Search lockers by name, address, town, postcode, or code. Returns a
	 * limited result set (never the full national dataset).
	 */
	public function search_lockers( $query, $limit = 20 ) {
		$limit   = max( 1, (int) $limit );
		$lockers = $this->get_lockers();
		$q       = trim( strtolower( (string) $query ) );

		if ( '' === $q ) {
			return array_slice( $lockers, 0, $limit );
		}

		$matches = array();
		foreach ( $lockers as $l ) {
			$hay = strtolower( $l['name'] . ' ' . $l['address'] . ' ' . $l['town'] . ' ' . $l['postcode'] . ' ' . $l['code'] );
			if ( false !== strpos( $hay, $q ) ) {
				$matches[] = $l;
				if ( count( $matches ) >= $limit ) {
					break;
				}
			}
		}
		return $matches;
	}

	/**
	 * Server-side locker validation: return the normalised record for a code,
	 * or null when the code is unknown. Never trust a client-supplied code
	 * without this check.
	 */
	public function get_locker( $code ) {
		$code = (string) $code;
		if ( '' === $code ) {
			return null;
		}
		foreach ( $this->get_lockers() as $l ) {
			if ( $l['code'] === $code ) {
				return $l;
			}
		}
		return null;
	}

	/**
	 * Normalise the lockers-data payload. Field names are (to confirm) against
	 * a Phase-1 sandbox fixture, so mapping is tolerant of the likely aliases.
	 * Returns null when the shape is unrecognisable.
	 */
	private function extract_lockers( $data ) {
		if ( ! is_array( $data ) ) {
			return null;
		}
		$list = null;
		if ( array_key_exists( 0, $data ) ) {
			$list = $data;
		} elseif ( isset( $data['lockers'] ) && is_array( $data['lockers'] ) ) {
			$list = $data['lockers'];
		} elseif ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
			$list = $data['data'];
		}
		if ( ! is_array( $list ) ) {
			return null;
		}

		$out = array();
		foreach ( $list as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$code = $row['code'] ?? $row['terminal_id'] ?? $row['id'] ?? '';
			if ( '' === (string) $code ) {
				continue;
			}
			$out[] = array(
				'code'      => (string) $code,
				'name'      => (string) ( $row['name'] ?? '' ),
				'address'   => (string) ( $row['address'] ?? '' ),
				'town'      => (string) ( $row['town'] ?? $row['city'] ?? '' ),
				'postcode'  => (string) ( $row['postcode'] ?? $row['postal_code'] ?? '' ),
				'lat'       => self::num_or_null( $row['latitude'] ?? $row['lat'] ?? null ),
				'lng'       => self::num_or_null( $row['longitude'] ?? $row['lng'] ?? null ),
				'type'      => (string) ( $row['type'] ?? '' ),
				'hours'     => $row['opening_hours'] ?? $row['hours'] ?? '',
				'box_sizes' => is_array( $row['box_sizes'] ?? null ) ? $row['box_sizes'] : ( is_array( $row['boxes'] ?? null ) ? $row['boxes'] : array() ),
			);
		}
		return $out;
	}

	// ─────────────────────────── Rates ─────────────────────────────────

	/**
	 * Account rate opt-in. Body shape is (to confirm); not invoked in the
	 * Phase-1 flow. Returns the structured api_request result.
	 */
	public function opt_in() {
		return $this->api_request( 'POST', self::OPTIN_PATH, array() );
	}

	/**
	 * Reference locker rate card (diagnostics). Returns raw decoded data.
	 */
	public function get_locker_rates() {
		return $this->api_request( 'GET', self::LOCKER_RATES_PATH );
	}

	/**
	 * L2L quote for a destination locker.
	 *
	 * Collection is identified as type "locker" (NOT a source terminal_id).
	 * Returns [ 'ok' => true, 'offers' => [...] ] or a structured error.
	 * Each offer: service_code, service_name, box_code (provider ID),
	 * box_name (label), box_size (derived), dimensions, rate,
	 * rate_excluding_vat, rate_revision_id.
	 */
	public function get_rates( $dest_terminal_id ) {
		$dest_terminal_id = (string) $dest_terminal_id;
		if ( '' === $dest_terminal_id ) {
			return array( 'ok' => false, 'error' => 'no_destination' );
		}

		$body = array(
			'collection_address' => array( 'type' => 'locker' ),
			'delivery_address'   => array( 'terminal_id' => $dest_terminal_id ),
		);

		$res = $this->api_request( 'POST', self::RATES_PATH, $body );
		if ( ! ( $res['ok'] ?? false ) ) {
			return $res;
		}

		$offers = $this->extract_rate_offers( $res['data'] );
		if ( null === $offers ) {
			$this->log( 'error', self::RATES_PATH . ' unexpected response shape' );
			return array( 'ok' => false, 'error' => 'shape' );
		}
		return array( 'ok' => true, 'offers' => $offers );
	}

	/**
	 * Parse the /rates response. service_level.* fields are NESTED per rate
	 * item; rate / rate_excluding_vat / rate_revision_id are top-level on the
	 * rate item. box_type is a provider box-type ID string (e.g. "14"), NOT a
	 * size class. Returns null on unrecognisable shape.
	 */
	private function extract_rate_offers( $data ) {
		if ( ! is_array( $data ) || ! isset( $data['rates'] ) || ! is_array( $data['rates'] ) ) {
			return null;
		}
		$offers = array();
		foreach ( $data['rates'] as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$sl = $r['service_level'] ?? null;
			if ( ! is_array( $sl ) ) {
				continue;
			}
			$code = (string) ( $sl['code'] ?? '' );
			if ( '' === $code ) {
				continue;
			}
			$offers[] = array(
				'service_code'       => $code,
				'service_name'       => (string) ( $sl['name'] ?? '' ),
				'box_code'           => (string) ( $sl['box_type'] ?? '' ),       // Provider box-type ID.
				'box_name'           => (string) ( $sl['box_type_name'] ?? '' ),  // e.g. V4-XL.
				'box_size'           => self::derive_box_size( $sl['box_type_name'] ?? '' ),
				'dimensions'         => $this->normalize_dims( $sl['dimensions'] ?? null ),
				'rate'               => self::num_or_null( $r['rate'] ?? null ),
				'rate_excluding_vat' => self::num_or_null( $r['rate_excluding_vat'] ?? null ),
				'rate_revision_id'   => (string) ( $r['rate_revision_id'] ?? '' ),
			);
		}
		return $offers;
	}

	/**
	 * Normalise a service_level.dimensions object. Inner key names are
	 * (to confirm); tolerant of length/width/height/weight and l/w/h/max_weight.
	 */
	private function normalize_dims( $d ) {
		if ( ! is_array( $d ) ) {
			return array(
				'length'     => null,
				'width'      => null,
				'height'     => null,
				'max_weight' => null,
			);
		}
		$pick = function ( array $keys ) use ( $d ) {
			foreach ( $keys as $k ) {
				if ( isset( $d[ $k ] ) && is_numeric( $d[ $k ] ) ) {
					return (float) $d[ $k ];
				}
			}
			return null;
		};
		return array(
			'length'     => $pick( array( 'length', 'l' ) ),
			'width'      => $pick( array( 'width', 'w' ) ),
			'height'     => $pick( array( 'height', 'h' ) ),
			'max_weight' => $pick( array( 'weight', 'max_weight', 'max_weight_kg', 'weight_kg' ) ),
		);
	}

	/**
	 * Derive a human size class (XS/S/M/L/XL) from a provider box label such as
	 * "V4-XL". Never inferred from the numeric box_type ID.
	 */
	public static function derive_box_size( $box_type_name ) {
		$label = strtoupper( trim( (string) $box_type_name ) );
		if ( '' === $label ) {
			return '';
		}
		if ( preg_match( '/(XXL|XS|XL|S|M|L)$/', $label, $m ) ) {
			return $m[1];
		}
		return '';
	}

	// ─────────────────────────── Shipments ─────────────────────────────

	/**
	 * Create an L2L shipment. NOT wired to any UI in Phase 1 — the guarded
	 * booking action (capability, nonce, mutex, drift check) lands in Phase 4.
	 * Contacts (name/email/mobile) are supplied by the caller at booking time.
	 *
	 * @param array $args {
	 *   dest_terminal_id, service_level_code (required),
	 *   collection_contact, delivery_contact (each: name/email/mobile),
	 *   collection_instructions, customer_reference (optional)
	 * }
	 * @return array [ 'ok'=>true, 'shipment_id'=>..., 'tracking_ref'=>... ] or structured error.
	 */
	public function create_shipment( array $args ) {
		$dest = (string) ( $args['dest_terminal_id'] ?? '' );
		$svc  = (string) ( $args['service_level_code'] ?? '' );
		if ( '' === $dest || '' === $svc ) {
			return array( 'ok' => false, 'error' => 'missing_args' );
		}

		$body = array(
			'collection_address'               => array( 'type' => 'locker' ),
			'special_instructions_collection'  => (string) ( $args['collection_instructions'] ?? 'None' ),
			'collection_contact'               => $this->contact( $args['collection_contact'] ?? array() ),
			'delivery_address'                 => array( 'terminal_id' => $dest ),
			'delivery_contact'                 => $this->contact( $args['delivery_contact'] ?? array() ),
			'service_level_code'               => $svc,
		);
		if ( ! empty( $args['customer_reference'] ) ) {
			// Merchant reference key is (to confirm) against sandbox.
			$body['customer_reference'] = (string) $args['customer_reference'];
		}

		$res = $this->api_request( 'POST', self::SHIPMENTS_PATH, $body );
		if ( ! ( $res['ok'] ?? false ) ) {
			return $res;
		}
		$parsed = $this->extract_shipment( $res['data'] );
		if ( null === $parsed ) {
			$this->log( 'error', self::SHIPMENTS_PATH . ' unexpected response shape' );
			return array( 'ok' => false, 'error' => 'shape' );
		}
		return array( 'ok' => true ) + $parsed;
	}

	private function contact( $c ) {
		$c = is_array( $c ) ? $c : array();
		return array(
			'name'          => (string) ( $c['name'] ?? '' ),
			'email'         => (string) ( $c['email'] ?? '' ),
			'mobile_number' => (string) ( $c['mobile_number'] ?? $c['mobile'] ?? '' ),
		);
	}

	/**
	 * Extract shipment id + tracking reference. Key names (to confirm).
	 */
	private function extract_shipment( $data ) {
		if ( ! is_array( $data ) ) {
			return null;
		}
		$node = ( isset( $data['shipment'] ) && is_array( $data['shipment'] ) ) ? $data['shipment']
			: ( ( isset( $data['data'] ) && is_array( $data['data'] ) ) ? $data['data'] : $data );

		$id = $node['id'] ?? $node['shipment_id'] ?? '';
		if ( '' === (string) $id ) {
			return null;
		}
		return array(
			'shipment_id'  => (string) $id,
			'tracking_ref' => (string) ( $node['waybill'] ?? $node['tracking_reference'] ?? $node['tracking_number'] ?? '' ),
		);
	}

	// ─────────────────────────── Tracking ──────────────────────────────

	/**
	 * Public tracking lookup by waybill. Sends Bearer auth (there is no
	 * unauthenticated tracking endpoint). Returns the raw status string plus
	 * decoded data. Status→WC mapping is Phase 5.
	 */
	public function get_tracking( $waybill ) {
		$waybill = (string) $waybill;
		if ( '' === $waybill ) {
			return array( 'ok' => false, 'error' => 'no_waybill' );
		}
		$res = $this->api_request( 'GET', self::TRACKING_PATH . '?waybill=' . rawurlencode( $waybill ) );
		if ( ! ( $res['ok'] ?? false ) ) {
			return $res;
		}
		return array(
			'ok'     => true,
			'status' => $this->extract_tracking_status( $res['data'] ),
			'data'   => $res['data'],
		);
	}

	/**
	 * Best-effort extraction of the current status string. Shape (to confirm).
	 */
	private function extract_tracking_status( $data ) {
		if ( ! is_array( $data ) ) {
			return '';
		}
		$node = ( isset( $data['shipment'] ) && is_array( $data['shipment'] ) ) ? $data['shipment']
			: ( ( isset( $data['data'] ) && is_array( $data['data'] ) ) ? $data['data'] : $data );
		return (string) ( $node['status'] ?? $node['current_status'] ?? '' );
	}

	// ─────────────────────────── Labels ────────────────────────────────

	/**
	 * Waybill/sticker URL. Origin-relative (outside /api/v1), key in query.
	 * SERVER-SIDE ONLY — never log this, never return it to the browser.
	 * Consumed by the Phase-4 authenticated admin proxy.
	 */
	public function waybill_url( $shipment_id ) {
		return $this->label_url( 'waybill', $shipment_id );
	}

	public function sticker_url( $shipment_id ) {
		return $this->label_url( 'sticker', $shipment_id );
	}

	private function label_url( $kind, $shipment_id ) {
		return $this->origin . '/generate/' . $kind . '/' . rawurlencode( (string) $shipment_id )
			. '?api_key=' . rawurlencode( $this->token );
	}

	/**
	 * Fetch a label PDF. The URL carries the api_key and is NEVER logged — only
	 * the outcome (kind + HTTP code) is. Labels authenticate via the query
	 * param, not Bearer.
	 *
	 * @return array [ 'ok'=>true, 'body'=>bytes, 'content_type'=>string ] or error.
	 */
	public function fetch_label( $kind, $shipment_id ) {
		if ( ! in_array( $kind, array( 'waybill', 'sticker' ), true ) ) {
			return array( 'ok' => false, 'error' => 'bad_kind' );
		}
		$url = $this->label_url( $kind, $shipment_id );
		$res = $this->transport->request( 'GET', $url, array( 'Accept' => 'application/pdf' ), null, $this->timeout );

		if ( ! empty( $res['error'] ) ) {
			$this->log( 'error', $kind . ' fetch transport error: ' . self::redact( $res['error'] ) );
			return array( 'ok' => false, 'error' => 'transport' );
		}
		$code = (int) ( $res['code'] ?? 0 );
		if ( $code < 200 || $code >= 300 ) {
			$this->log( 'error', $kind . ' fetch HTTP ' . $code );
			return array( 'ok' => false, 'error' => 'http', 'code' => $code );
		}
		return array(
			'ok'           => true,
			'body'         => (string) ( $res['body'] ?? '' ),
			'content_type' => (string) ( $res['content_type'] ?? 'application/pdf' ),
		);
	}

	// ─────────────────────────── Internals ─────────────────────────────

	private function cache_key( $suffix ) {
		return 'es_tcg_locker_' . $suffix . '_' . md5( $this->api_base );
	}

	private function log( $level, $message ) {
		if ( null !== $this->logger ) {
			call_user_func( $this->logger, $level, self::redact( (string) $message ) );
		}
	}

	private static function num_or_null( $v ) {
		return is_numeric( $v ) ? (float) $v : null;
	}
}
