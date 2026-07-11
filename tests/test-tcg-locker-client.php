<?php
/**
 * Tests for ES_TCG_Locker_Client (Phase 1).
 *
 * Covers the brief's Phase-1 contract-safety requirements: no /locker-rates-new,
 * sandbox /api/v1, L2L collection type=locker with no invented source terminal,
 * server-validated destination, returned-services parsing, Bearer tracking,
 * and credential-safe logging. Uses injected fakes — no network, no real shipment.
 */

defined( 'ABSPATH' ) || exit;

const ES_TEST_BASE  = 'https://sandbox.api-pudo.co.za/api/v1';
const ES_TEST_TOKEN = 'SANDBOX_TOKEN_SECRET';

es_test( 'is_valid_api_base — exact terminal /api/v1, HTTPS-only, no userinfo/query/fragment', function () {
	es_ok( ES_TCG_Locker_Client::is_valid_api_base( 'https://sandbox.api-pudo.co.za/api/v1' ), 'sandbox base valid' );
	es_ok( ES_TCG_Locker_Client::is_valid_api_base( 'https://sandbox.api-pudo.co.za/api/v1/' ), 'trailing slash tolerated' );
	es_ok( ! ES_TCG_Locker_Client::is_valid_api_base( 'https://sandbox.api-pudo.co.za/api/v1/foo' ), 'containing-not-terminal rejected' );
	es_ok( ! ES_TCG_Locker_Client::is_valid_api_base( 'sandbox.api-pudo.co.za/api/v1' ), 'missing scheme rejected' );
	es_ok( ! ES_TCG_Locker_Client::is_valid_api_base( 'https://host/v1' ), 'wrong path rejected' );
	es_ok( ! ES_TCG_Locker_Client::is_valid_api_base( '' ), 'empty rejected' );
	// Tightened rules (Phase-1 review).
	es_ok( ! ES_TCG_Locker_Client::is_valid_api_base( 'http://sandbox.api-pudo.co.za/api/v1' ), 'http rejected (Bearer needs TLS)' );
	es_ok( ! ES_TCG_Locker_Client::is_valid_api_base( 'https://sandbox.api-pudo.co.za/api/v1?x=1' ), 'query string rejected (breaks origin derivation)' );
	es_ok( ! ES_TCG_Locker_Client::is_valid_api_base( 'https://sandbox.api-pudo.co.za/api/v1#frag' ), 'fragment rejected' );
	es_ok( ! ES_TCG_Locker_Client::is_valid_api_base( 'https://user:pass@sandbox.api-pudo.co.za/api/v1' ), 'embedded userinfo rejected' );
} );

es_test( 'origin derivation', function () {
	$c = new ES_TCG_Locker_Client( 'https://sandbox.api-pudo.co.za/api/v1/', 'T', new ES_Fake_Transport() );
	es_eq( 'https://sandbox.api-pudo.co.za/api/v1', $c->get_api_base(), 'base normalised (no trailing slash)' );
	es_eq( 'https://sandbox.api-pudo.co.za', $c->get_origin(), 'origin derived by stripping terminal /api/v1' );
} );

es_test( 'L2L /rates payload, endpoint & Bearer auth', function () {
	$tx = ( new ES_Fake_Transport() )->push( 200, es_fixture( 'rates-l2l.json' ) );
	$c  = new ES_TCG_Locker_Client( ES_TEST_BASE, 'SECRET_TOKEN', $tx );

	$res = $c->get_rates( 'CG929' );
	es_ok( ! empty( $res['ok'] ), 'rates ok' );

	$call = $tx->last_call();
	es_eq( 'POST', $call['method'], 'method POST' );
	es_contains( $call['url'], '/api/v1/rates', 'hits /api/v1/rates' );
	es_not_contains( $call['url'], 'locker-rates-new', 'never calls /locker-rates-new' );

	$body = json_decode( $call['body'], true );
	es_eq( 'locker', $body['collection_address']['type'], 'collection_address.type = locker' );
	es_ok( ! isset( $body['collection_address']['terminal_id'] ), 'no source terminal_id invented' );
	es_eq( 'CG929', $body['delivery_address']['terminal_id'], 'destination terminal_id set' );
	es_eq( 'Bearer SECRET_TOKEN', $call['headers']['Authorization'], 'Bearer auth sent' );
} );

es_test( '/rates parses nested service_level; box_type is a provider ID', function () {
	$tx  = ( new ES_Fake_Transport() )->push( 200, es_fixture( 'rates-l2l.json' ) );
	$c   = new ES_TCG_Locker_Client( ES_TEST_BASE, 'T', $tx );
	$res = $c->get_rates( 'CG929' );

	es_eq( 3, count( $res['offers'] ), 'three offers returned' );
	$xl = $res['offers'][2];
	es_eq( 'L2LXL - ECO', $xl['service_code'], 'service_level.code' );
	es_eq( '14', $xl['box_code'], 'box_code is provider box_type ID string' );
	es_eq( 'V4-XL', $xl['box_name'], 'box_name is provider box_type_name' );
	es_eq( 'XL', $xl['box_size'], 'human size derived from label, not the ID' );
	es_eq( 115.0, $xl['rate'], 'rate is top-level, incl VAT' );
	es_eq( 100.0, $xl['rate_excluding_vat'], 'rate_excluding_vat top-level' );
	es_eq( 'rev_xl_1', $xl['rate_revision_id'], 'rate_revision_id top-level' );
	es_eq( 20.0, $xl['dimensions']['max_weight'], 'dimensions.max_weight parsed' );
	es_eq( 60.0, $xl['dimensions']['length'], 'dimensions.length parsed' );
} );

es_test( 'derive_box_size', function () {
	es_eq( 'XS', ES_TCG_Locker_Client::derive_box_size( 'V4-XS' ), 'V4-XS -> XS' );
	es_eq( 'S', ES_TCG_Locker_Client::derive_box_size( 'V4-S' ), 'V4-S -> S' );
	es_eq( 'M', ES_TCG_Locker_Client::derive_box_size( 'V4-M' ), 'V4-M -> M' );
	es_eq( 'L', ES_TCG_Locker_Client::derive_box_size( 'V4-L' ), 'V4-L -> L' );
	es_eq( 'XL', ES_TCG_Locker_Client::derive_box_size( 'V4-XL' ), 'V4-XL -> XL' );
	es_eq( '', ES_TCG_Locker_Client::derive_box_size( '' ), 'empty label -> empty' );
} );

es_test( 'lockers cache + last-known-good fallback', function () {
	$tx    = ( new ES_Fake_Transport() )->push( 200, es_fixture( 'lockers-data.json' ) );
	$cache = new ES_Array_Cache();
	$c     = new ES_TCG_Locker_Client( ES_TEST_BASE, 'T', $tx, $cache );

	$l1 = $c->get_lockers();
	es_eq( 2, count( $l1 ), 'first fetch parses 2 lockers' );
	es_eq( 1, count( $tx->calls ), 'one transport call' );

	$l2 = $c->get_lockers();
	es_eq( 2, count( $l2 ), 'second call served (from cache)' );
	es_eq( 1, count( $tx->calls ), 'cache hit — still one transport call' );

	// Forced refresh that errors must fall back to LKG, not empty, and not clobber good cache.
	$tx->push( 500, '' );
	$l3 = $c->get_lockers( true );
	es_eq( 2, count( $l3 ), 'error refresh falls back to last-known-good' );
	es_ok( is_array( $cache->get( es_lockers_cache_key() ) ), 'good cache still intact after failed refresh' );
} );

es_test( 'empty/error responses are never cached as success', function () {
	$cache = new ES_Array_Cache();
	$tx    = ( new ES_Fake_Transport() )->push( 200, '[]' );
	$c     = new ES_TCG_Locker_Client( ES_TEST_BASE, 'T', $tx, $cache );

	$l = $c->get_lockers();
	es_eq( 0, count( $l ), 'empty result returned' );
	es_eq( null, $cache->get( es_lockers_cache_key() ), 'empty response not cached' );
} );

es_test( 'search + server-side locker validation', function () {
	$tx = ( new ES_Fake_Transport() )->push( 200, es_fixture( 'lockers-data.json' ) );
	$c  = new ES_TCG_Locker_Client( ES_TEST_BASE, 'T', $tx, new ES_Array_Cache() );

	es_eq( 1, count( $c->search_lockers( 'brackenfell' ) ), 'search by town' );
	es_eq( 1, count( $c->search_lockers( '0184' ) ), 'search by postcode' );
	es_eq( 1, count( $c->search_lockers( 'Silverton' ) ), 'search by name' );
	es_eq( 2, count( $c->search_lockers( '' ) ), 'empty query returns all (limited)' );
	es_eq( 1, count( $c->search_lockers( '', 1 ) ), 'limit honoured' );

	es_ok( null !== $c->get_locker( 'CG929' ), 'known code resolves' );
	es_ok( null === $c->get_locker( 'FORGED' ), 'unknown/tampered code rejected' );
} );

es_test( 'tracking sends Bearer to the authenticated endpoint', function () {
	$tx  = ( new ES_Fake_Transport() )->push( 200, es_fixture( 'tracking.json' ) );
	$c   = new ES_TCG_Locker_Client( ES_TEST_BASE, 'TKN', $tx );
	$res = $c->get_tracking( 'WB123456' );

	es_ok( ! empty( $res['ok'] ), 'tracking ok' );
	es_eq( 'in-locker', $res['status'], 'status extracted' );

	$call = $tx->last_call();
	es_contains( $call['url'], '/api/v1/tracking/shipments?waybill=WB123456', 'tracking endpoint + waybill' );
	es_eq( 'Bearer TKN', $call['headers']['Authorization'], 'tracking sends Bearer' );
} );

es_test( 'create_shipment builds the L2L payload and parses ids', function () {
	$tx  = ( new ES_Fake_Transport() )->push( 200, es_fixture( 'shipment-create.json' ) );
	$c   = new ES_TCG_Locker_Client( ES_TEST_BASE, 'T', $tx );
	$res = $c->create_shipment( array(
		'dest_terminal_id'   => 'CG929',
		'service_level_code' => 'L2LXL - ECO',
		'collection_contact' => array( 'name' => 'WH', 'email' => 'wh@x.co', 'mobile' => '0810000000' ),
		'delivery_contact'   => array( 'name' => 'Cust', 'email' => 'c@x.co', 'mobile_number' => '0820000000' ),
	) );

	es_ok( ! empty( $res['ok'] ), 'shipment ok' );
	es_eq( 'SHP-1001', $res['shipment_id'], 'shipment_id parsed' );
	es_eq( 'WB123456', $res['tracking_ref'], 'tracking_ref parsed from published custom_tracking_reference' );

	$call = $tx->last_call();
	es_contains( $call['url'], '/api/v1/shipments', 'hits /api/v1/shipments' );
	$body = json_decode( $call['body'], true );
	es_eq( 'locker', $body['collection_address']['type'], 'collection_address.type = locker' );
	es_eq( 'CG929', $body['delivery_address']['terminal_id'], 'delivery terminal_id' );
	es_eq( 'L2LXL - ECO', $body['service_level_code'], 'exact persisted service code' );
	es_eq( 'WH', $body['collection_contact']['name'], 'collection contact name' );
	es_eq( '0820000000', $body['delivery_contact']['mobile_number'], 'delivery mobile normalised' );
} );

es_test( 'label URL is origin-relative with key in query; token never logged', function () {
	es_not_contains( ES_TCG_Locker_Client::redact( 'GET https://h/generate/waybill/1?api_key=SECRET&x=1' ), 'SECRET', 'redact scrubs api_key' );
	es_not_contains( ES_TCG_Locker_Client::redact( 'Authorization: Bearer SECRETTOKEN' ), 'SECRETTOKEN', 'redact scrubs Bearer' );
	// Provider tokens are pipe-delimited (e.g. "62429|secret") — the part after
	// the pipe must not survive redaction.
	es_not_contains( ES_TCG_Locker_Client::redact( 'Authorization: Bearer 62429|s3cr3tpart' ), 's3cr3tpart', 'redact scrubs pipe-delimited Bearer token' );
	es_not_contains( ES_TCG_Locker_Client::redact( 'url ...?api_key=62429|s3cr3tpart&y=2' ), 's3cr3tpart', 'redact scrubs pipe-delimited api_key token' );

	$log    = array();
	$logger = function ( $lvl, $msg ) use ( &$log ) {
		$log[] = $msg;
	};
	$tx = ( new ES_Fake_Transport() )->push( 500, '' );
	$c  = new ES_TCG_Locker_Client( ES_TEST_BASE, 'SUPERSECRET', $tx, null, $logger );

	$url = $c->waybill_url( 'SHP-1001' );
	es_contains( $url, 'https://sandbox.api-pudo.co.za/generate/waybill/SHP-1001', 'origin-relative waybill path (outside /api/v1)' );
	es_contains( $url, 'api_key=SUPERSECRET', 'key placed in query (server-side URL only)' );

	$c->fetch_label( 'waybill', 'SHP-1001' );
	$all = implode( "\n", $log );
	es_not_contains( $all, 'SUPERSECRET', 'token never appears in logs on a failed label fetch' );
	es_ok( count( $log ) >= 1, 'the failure was logged (credential-free)' );
} );

es_test( 'transport error messages are redacted before logging', function () {
	$log    = array();
	$logger = function ( $lvl, $msg ) use ( &$log ) {
		$log[] = $msg;
	};
	$tx = ( new ES_Fake_Transport() )->push( 0, '', 'connect failed while sending Bearer SUPERSECRET' );
	$c  = new ES_TCG_Locker_Client( ES_TEST_BASE, 'SUPERSECRET', $tx, null, $logger );

	$c->get_rates( 'CG929' );
	es_not_contains( implode( "\n", $log ), 'SUPERSECRET', 'token redacted from transport error' );
} );

es_test( 'HTTP + shape errors surface as structured results (no exceptions)', function () {
	$tx = ( new ES_Fake_Transport() )->push( 401, '{"message":"unauthorized"}' );
	$c  = new ES_TCG_Locker_Client( ES_TEST_BASE, 'T', $tx );
	$r  = $c->get_rates( 'CG929' );
	es_ok( empty( $r['ok'] ), 'HTTP error surfaced' );
	es_eq( 'http', $r['error'], 'error type = http' );

	$tx2 = ( new ES_Fake_Transport() )->push( 200, '{"nope":1}' );
	$c2  = new ES_TCG_Locker_Client( ES_TEST_BASE, 'T', $tx2 );
	$r2  = $c2->get_rates( 'CG929' );
	es_ok( empty( $r2['ok'] ) && 'shape' === $r2['error'], 'unexpected shape rejected' );
} );

es_test( 'no destination locker → no provider call', function () {
	$tx = new ES_Fake_Transport();
	$c  = new ES_TCG_Locker_Client( ES_TEST_BASE, 'T', $tx );
	$r  = $c->get_rates( '' );
	es_ok( empty( $r['ok'] ) && 'no_destination' === $r['error'], 'empty destination rejected' );
	es_eq( 0, count( $tx->calls ), 'no transport call made without a selected locker' );
} );
