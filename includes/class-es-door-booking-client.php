<?php
/** Injectable door-carrier booking and native-document clients. */

defined( 'ABSPATH' ) || exit;

interface ES_Door_Booking_Transport {
	public function request( $method, $url, array $headers, $body, $timeout );
}

class ES_Door_Booking_WP_Transport implements ES_Door_Booking_Transport {
	public function request( $method, $url, array $headers, $body, $timeout ) {
		$args = array(
			'method'      => strtoupper( $method ),
			'headers'     => $headers,
			'timeout'     => max( 1, (int) $timeout ),
			'redirection' => 0,
		);
		if ( null !== $body ) {
			$args['body'] = is_string( $body ) ? $body : wp_json_encode( $body );
		}
		$res = wp_remote_request( $url, $args );
		if ( is_wp_error( $res ) ) {
			return array( 'code' => 0, 'body' => '', 'content_type' => '', 'error' => $res->get_error_message() );
		}
		return array(
			'code'         => (int) wp_remote_retrieve_response_code( $res ),
			'body'         => (string) wp_remote_retrieve_body( $res ),
			'content_type' => (string) wp_remote_retrieve_header( $res, 'content-type' ),
			'error'        => null,
		);
	}
}

class ES_Door_Booking_Client {
	const SHIPLOGIC_BASE = 'https://api.portal.thecourierguy.co.za/v2';
	const COLLIVERY_BASE = 'https://api.collivery.co.za/v3';

	private $provider;
	private $token;
	private $transport;
	private $timeout;
	private $logger;

	public function __construct( $provider, $token, ES_Door_Booking_Transport $transport, $timeout = 20, $logger = null ) {
		$this->provider  = (string) $provider;
		$this->token     = (string) $token;
		$this->transport = $transport;
		$this->timeout   = max( 1, (int) $timeout );
		$this->logger    = is_callable( $logger ) ? $logger : null;
	}

	public function create_shipment( array $args ) {
		if ( '' === $this->token ) {
			return array( 'ok' => false, 'http' => 0, 'error' => 'Carrier API token is not configured.' );
		}
		if ( ES_Carrier_Booking::PROVIDER_TCG === $this->provider ) {
			return $this->create_shiplogic( $args );
		}
		if ( ES_Carrier_Booking::PROVIDER_MDS === $this->provider ) {
			return $this->create_collivery( $args );
		}
		return array( 'ok' => false, 'http' => 400, 'error' => 'Unsupported carrier.' );
	}

	public function fetch_document( $kind, $shipment_id, $tracking_ref = '' ) {
		$kind = 'label' === $kind || 'sticker' === $kind ? 'label' : 'waybill';
		if ( ES_Carrier_Booking::PROVIDER_TCG === $this->provider ) {
			$path = 'label' === $kind ? '/shipments/label/stickers' : '/shipments/label';
			$url  = self::SHIPLOGIC_BASE . $path . '?id=' . rawurlencode( (string) $shipment_id );
			if ( 'label' === $kind ) {
				$url .= '&print=false&tracking_reference=' . rawurlencode( (string) $tracking_ref );
			}
			$res = $this->request( 'GET', $url, $this->bearer_headers(), null );
			if ( ! $this->is_2xx( $res ) ) {
				return $this->document_error( $res );
			}
			$data = json_decode( $res['body'], true );
			$url  = is_array( $data ) ? (string) ( $data['url'] ?? '' ) : '';
			if ( ! self::safe_signed_url( $url ) ) {
				return array( 'ok' => false, 'error' => 'Courier Guy did not return a safe signed document URL.' );
			}
			$pdf = $this->request( 'GET', $url, array( 'Accept' => 'application/pdf' ), null );
			return $this->validated_pdf( $pdf );
		}

		$url = self::COLLIVERY_BASE . '/waybill_documents/' . rawurlencode( (string) $shipment_id ) . '/' . $kind
			. '?api_token=' . rawurlencode( $this->token );
		$res = $this->request( 'GET', $url, $this->collivery_headers(), null );
		if ( ! $this->is_2xx( $res ) ) {
			return $this->document_error( $res );
		}
		$data  = json_decode( $res['body'], true );
		$image = is_array( $data ) ? (string) ( $data['data']['image'] ?? '' ) : '';
		$body  = base64_decode( $image, true );
		if ( false === $body ) {
			return array( 'ok' => false, 'error' => 'MDS returned an invalid document payload.' );
		}
		return $this->validated_pdf( array( 'code' => 200, 'body' => $body, 'content_type' => 'application/pdf', 'error' => null ) );
	}

	public static function shiplogic_payload( array $args ) {
		$parcels = array();
		foreach ( ES_Carrier_Booking::normalize_parcels( (array) ( $args['parcels'] ?? array() ) ) as $p ) {
			$parcels[] = array(
				'submitted_length_cm' => $p['length_cm'],
				'submitted_width_cm'  => $p['width_cm'],
				'submitted_height_cm' => $p['height_cm'],
				'submitted_weight_kg' => $p['weight_kg'],
				'parcel_description'  => (string) ( $args['description'] ?? 'WooCommerce order' ),
				'alternative_tracking_reference' => '',
			);
		}
		return array(
			'collection_address'              => self::shiplogic_address( (array) ( $args['origin'] ?? array() ), 'business', (string) ( $args['company'] ?? '' ) ),
			'special_instructions_collection' => (string) ( $args['collection_instructions'] ?? 'None' ),
			'collection_contact'              => self::shiplogic_contact( (array) ( $args['collection_contact'] ?? array() ) ),
			'delivery_address'                => self::shiplogic_address( (array) ( $args['destination'] ?? array() ), 'residential', (string) ( $args['destination_company'] ?? '' ) ),
			'special_instructions_delivery'   => (string) ( $args['delivery_instructions'] ?? 'None' ),
			'delivery_contact'                => self::shiplogic_contact( (array) ( $args['delivery_contact'] ?? array() ) ),
			'parcels'                         => $parcels,
			'opt_in_rates'                    => array(),
			'opt_in_time_based_rates'         => array(),
			'service_level_code'              => (string) ( $args['service'] ?? '' ),
			'customer_reference'              => (string) ( $args['reference'] ?? '' ),
		);
	}

	public static function collivery_payload( array $args ) {
		$parcels = array();
		foreach ( ES_Carrier_Booking::normalize_parcels( (array) ( $args['parcels'] ?? array() ) ) as $i => $p ) {
			$parcels[] = array(
				'length'    => $p['length_cm'],
				'width'     => $p['width_cm'],
				'height'    => $p['height_cm'],
				'weight'    => $p['weight_kg'],
				'quantity'  => 1,
				'reference' => substr( (string) ( $args['reference'] ?? '' ) . '-' . ( $i + 1 ), 0, 25 ),
			);
		}
		return array(
			'service'                => (int) ( $args['service'] ?? 0 ),
			'parcels'                => $parcels,
			'collection_address'     => self::collivery_address( (array) ( $args['origin'] ?? array() ), (array) ( $args['collection_contact'] ?? array() ), (string) ( $args['company'] ?? '' ), 'Business' ),
			'delivery_address'       => self::collivery_address( (array) ( $args['destination'] ?? array() ), (array) ( $args['delivery_contact'] ?? array() ), (string) ( $args['destination_company'] ?? '' ), 'Residential' ),
			'custom_id'              => substr( (string) ( $args['reference'] ?? '' ), 0, 25 ),
			'reference'              => substr( (string) ( $args['reference'] ?? '' ), 0, 100 ),
			'description'            => substr( (string) ( $args['description'] ?? 'WooCommerce order' ), 0, 100 ),
			'special_instructions'   => substr( (string) ( $args['delivery_instructions'] ?? '' ), 0, 2048 ),
			'exclude_weekend'        => true,
			'risk_cover'             => false,
			'sms_tracking'           => false,
			'consolidate'            => false,
			'auto_accept'            => true,
			'include_pricing'        => true,
		);
	}

	private function create_shiplogic( array $args ) {
		$payload = self::shiplogic_payload( $args );
		$res     = $this->request( 'POST', self::SHIPLOGIC_BASE . '/shipments', $this->bearer_headers(), $payload );
		$data    = json_decode( $res['body'] ?? '', true );
		$node    = isset( $data['shipment'] ) && is_array( $data['shipment'] ) ? $data['shipment'] : $data;
		$id      = is_array( $node ) ? (string) ( $node['id'] ?? $node['shipment_id'] ?? '' ) : '';
		$track   = is_array( $node ) ? (string) ( $node['custom_tracking_reference'] ?? $node['short_tracking_reference'] ?? '' ) : '';
		return array(
			'ok'              => $this->is_2xx( $res ) && '' !== $id,
			'http'            => (int) ( $res['code'] ?? 0 ),
			'shipment_id'     => $id,
			'tracking_ref'    => $track,
			'transport_error' => ! empty( $res['error'] ),
			'error'           => $this->response_error( $res, $data ),
		);
	}

	private function create_collivery( array $args ) {
		$url     = self::COLLIVERY_BASE . '/waybill?api_token=' . rawurlencode( $this->token );
		$res     = $this->request( 'POST', $url, $this->collivery_headers(), self::collivery_payload( $args ) );
		$data    = json_decode( $res['body'] ?? '', true );
		$node    = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : $data;
		$id      = is_array( $node ) ? (string) ( $node['id'] ?? $node['waybill_id'] ?? '' ) : '';
		// Collivery's status/document routes use the numeric waybill id. Never use
		// our custom_id (WC order reference) as a tracking target.
		$track   = is_array( $node ) ? (string) ( $node['waybill'] ?? $node['waybill_number'] ?? $id ) : $id;
		return array(
			'ok'              => $this->is_2xx( $res ) && '' !== $id,
			'http'            => (int) ( $res['code'] ?? 0 ),
			'shipment_id'     => $id,
			'tracking_ref'    => $track,
			'transport_error' => ! empty( $res['error'] ),
			'error'           => $this->response_error( $res, $data ),
		);
	}

	private function request( $method, $url, array $headers, $body ) {
		$res = $this->transport->request( $method, $url, $headers, $body, $this->timeout );
		if ( $this->logger && ( ! empty( $res['error'] ) || (int) ( $res['code'] ?? 0 ) >= 400 ) ) {
			// Query strings may carry MDS credentials or a short-lived signed PDF
			// signature. Never put either in WooCommerce logs.
			$log_url = preg_replace( '/\?.*$/', '?[redacted]', (string) $url );
			call_user_func( $this->logger, 'warning', $this->redact( $method . ' ' . $log_url . ' failed: HTTP ' . (int) ( $res['code'] ?? 0 ) . ' ' . (string) ( $res['error'] ?? '' ) ) );
		}
		return $res;
	}

	private function bearer_headers() {
		return array( 'Authorization' => 'Bearer ' . $this->token, 'Content-Type' => 'application/json', 'Accept' => 'application/json' );
	}

	private function collivery_headers() {
		return array(
			'Content-Type' => 'application/json', 'Accept' => 'application/json',
			'X-App-Name' => 'ERPNextShipping', 'X-App-Version' => defined( 'ES_SHIPPING_VERSION' ) ? ES_SHIPPING_VERSION : 'dev',
			'X-App-Host' => 'WooCommerce', 'X-App-Lang' => 'PHP', 'X-App-Url' => function_exists( 'home_url' ) ? home_url() : '',
		);
	}

	private static function shiplogic_address( array $a, $type, $company ) {
		$a = ES_Carrier_Booking::canonical_address( $a );
		return array(
			'type' => $type, 'company' => $company, 'street_address' => $a['street_address'],
			'local_area' => $a['local_area'], 'city' => $a['city'], 'zone' => $a['zone'],
			'country' => $a['country'], 'code' => $a['code'],
			'entered_address' => implode( ', ', array_filter( array( $a['street_address'], $a['local_area'], $a['city'], $a['code'], $a['country'] ) ) ),
		);
	}

	private static function shiplogic_contact( array $c ) {
		return array( 'name' => (string) ( $c['name'] ?? '' ), 'email' => (string) ( $c['email'] ?? '' ), 'mobile_number' => (string) ( $c['mobile_number'] ?? '' ) );
	}

	private static function collivery_address( array $a, array $contact, $company, $location_type ) {
		$a = ES_Carrier_Booking::canonical_address( $a );
		$street_number = '';
		$street        = $a['street_address'];
		if ( preg_match( '/^([0-9][0-9A-Za-z\-\/]*)\s+(.+)$/', $street, $m ) ) {
			$street_number = substr( $m[1], 0, 5 );
			$street        = $m[2];
		}
		return array(
			'country' => 'ZAF', 'town_name' => $a['city'], 'suburb_name' => $a['local_area'],
			'company_name' => substr( $company, 0, 50 ), 'street_number' => $street_number,
			'street' => substr( $street, 0, 50 ), 'location_type_name' => $location_type,
			'contact' => array(
				'full_name' => substr( (string) ( $contact['name'] ?? '' ), 0, 50 ),
				'cellphone' => substr( (string) ( $contact['mobile_number'] ?? '' ), 0, 20 ),
				'email_address' => substr( (string) ( $contact['email'] ?? '' ), 0, 50 ),
			),
		);
	}

	private function is_2xx( array $res ) {
		return empty( $res['error'] ) && (int) ( $res['code'] ?? 0 ) >= 200 && (int) ( $res['code'] ?? 0 ) < 300;
	}

	private function response_error( array $res, $data ) {
		if ( ! empty( $res['error'] ) ) {
			return (string) $res['error'];
		}
		if ( is_array( $data ) ) {
			$error = $data['error'] ?? $data['message'] ?? $data['meta']['error'] ?? '';
			if ( is_array( $error ) ) {
				$error = json_encode( $error );
			}
			if ( '' !== (string) $error ) {
				return substr( (string) $error, 0, 500 );
			}
		}
		return 'HTTP ' . (int) ( $res['code'] ?? 0 ) . ' or an unexpected provider response.';
	}

	private function document_error( array $res ) {
		return array( 'ok' => false, 'error' => $this->response_error( $res, json_decode( $res['body'] ?? '', true ) ) );
	}

	private function validated_pdf( array $res ) {
		$body = (string) ( $res['body'] ?? '' );
		if ( ! $this->is_2xx( $res ) || '%PDF-' !== substr( $body, 0, 5 ) ) {
			return array( 'ok' => false, 'error' => 'Carrier did not return a valid PDF document.' );
		}
		return array( 'ok' => true, 'body' => $body );
	}

	private static function safe_signed_url( $url ) {
		$parts = parse_url( (string) $url );
		$host  = strtolower( (string) ( $parts['host'] ?? '' ) );
		return is_array( $parts )
			&& 'https' === ( $parts['scheme'] ?? '' )
			&& ( 'labels.shiplogic.com' === $host || 'shiplogic-backend-prod-infra-label-pdfs.s3.af-south-1.amazonaws.com' === $host );
	}

	private function redact( $message ) {
		$message = str_replace( $this->token, '[redacted]', (string) $message );
		$message = preg_replace( '/api_token=[^&\s]+/i', 'api_token=[redacted]', $message );
		return preg_replace( '/Bearer\s+[^\s"\x27]+/i', 'Bearer [redacted]', $message );
	}
}
