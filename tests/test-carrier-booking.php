<?php

function es_door_current() {
	return array(
		'origin' => array( 'street_address' => '269 Plantation Street', 'local_area' => 'Silverton', 'city' => 'Pretoria', 'zone' => 'GP', 'country' => 'ZA', 'code' => '0184' ),
		'destination' => array( 'street_address' => '1 Test Road', 'local_area' => 'Centurion', 'city' => 'Centurion', 'zone' => 'GP', 'country' => 'ZA', 'code' => '0157' ),
		'parcels' => array( array( 'weight_kg' => 5.8, 'length_cm' => 40, 'width_cm' => 30, 'height_cm' => 20 ) ),
		'delivery_contact' => array( 'Customer', 'customer@example.com', '0820000000' ),
	);
}

es_test( 'door rate metadata pins an exact provider booking snapshot', function () {
	$meta = ES_Carrier_Booking::build_rate_meta(
		array( 'carrier' => 'MDS Collivery', 'service_name' => 'Road Freight', 'booking_service' => '3', 'price_incl_vat' => 112.34 ),
		'pretoria',
		array( array( 'weight_kg' => 5.8, 'length_cm' => 40, 'width_cm' => 30, 'height_cm' => 20 ) ),
		129,
		false,
		1000,
		44
	);
	es_eq( 'mds-collivery', $meta[ ES_Carrier_Booking::M_PROVIDER ], 'provider canonicalized' );
	es_eq( '3', $meta[ ES_Carrier_Booking::M_SERVICE_CODE ], 'numeric booking service pinned' );
	es_eq( '44', $meta[ ES_Carrier_Booking::M_INSTANCE_ID ], 'settings instance pinned' );
	es_eq( '112.34', $meta[ ES_Carrier_Booking::M_PROVIDER_RATE ], 'provider rate pinned' );
	es_ok( is_array( json_decode( $meta[ ES_Carrier_Booking::M_PARCELS ], true ) ), 'parcels persist as JSON' );
	$bad = ES_Carrier_Booking::build_rate_meta( array( 'carrier' => 'MDS Collivery', 'service_code' => 'ECO', 'price_incl_vat' => 100 ), 'p', array( array( 'weight_kg' => 1, 'length_cm' => 1, 'width_cm' => 1, 'height_cm' => 1 ) ), 100 );
	es_eq( array(), $bad, 'MDS display code cannot masquerade as booking service id' );
} );

es_test( 'door booking outcome is conservative around uncertain responses', function () {
	es_eq( ES_Carrier_Booking::STATE_BOOKED, ES_Carrier_Booking::classify_result( array( 'ok' => true, 'http' => 201, 'shipment_id' => '44' ) )['state'], 'id-bearing success books' );
	foreach ( array( 0, 408, 409, 429, 500, 503, 200 ) as $http ) {
		$out = ES_Carrier_Booking::classify_result( array( 'ok' => false, 'http' => $http, 'error' => 'x', 'transport_error' => 0 === $http ) );
		es_eq( ES_Carrier_Booking::STATE_AMBIGUOUS, $out['state'], "HTTP $http is ambiguous" );
	}
	es_eq( ES_Carrier_Booking::STATE_ERROR, ES_Carrier_Booking::classify_result( array( 'ok' => false, 'http' => 422, 'error' => 'bad address' ) )['state'], 'validation 4xx is retryable error' );
} );

es_test( 'booking confirmation expires and is bound to current order facts', function () {
	$snapshot = array( ES_Carrier_Booking::M_PROVIDER => 'the-courier-guy', ES_Carrier_Booking::M_SERVICE_CODE => 'ECO', ES_Carrier_Booking::M_ORIGIN_LOC => 'pta' );
	$current  = es_door_current();
	$c = ES_Carrier_Booking::make_confirmation( 'secret', $snapshot, $current, 100, 1000 );
	es_ok( ES_Carrier_Booking::confirmation_valid( $c, 'secret', $snapshot, $current, 1100 ), 'valid inside TTL' );
	es_ok( ! ES_Carrier_Booking::confirmation_valid( $c, 'wrong', $snapshot, $current, 1100 ), 'token mismatch rejected' );
	es_ok( ! ES_Carrier_Booking::confirmation_valid( $c, 'secret', $snapshot, $current, 1301 ), 'expired rejected' );
	$current['destination']['code'] = '9999';
	es_ok( ! ES_Carrier_Booking::confirmation_valid( $c, 'secret', $snapshot, $current, 1100 ), 'address change rejected' );
} );

es_test( 'ShipLogic payload uses exact service, contacts and parcel geometry', function () {
	$args = es_door_current();
	$args += array(
		'service' => 'ECO', 'company' => 'Cactus Craft', 'destination_company' => 'Customer Co', 'reference' => 'WC-123',
		'collection_contact' => array( 'name' => 'Dispatch', 'email' => 'dispatch@example.com', 'mobile_number' => '0120000000' ),
		'delivery_contact' => array( 'name' => 'Customer', 'email' => 'customer@example.com', 'mobile_number' => '0820000000' ),
	);
	$p = ES_Door_Booking_Client::shiplogic_payload( $args );
	es_eq( 'ECO', $p['service_level_code'], 'service pinned' );
	es_eq( 5.8, $p['parcels'][0]['submitted_weight_kg'], 'weight pinned' );
	es_eq( 'Dispatch', $p['collection_contact']['name'], 'collection contact supplied' );
	es_eq( 'residential', $p['delivery_address']['type'], 'delivery is residential' );
} );

es_test( 'Collivery payload creates and accepts a carrier-native waybill', function () {
	$args = es_door_current();
	$args += array(
		'service' => '3', 'company' => 'Cactus Craft', 'destination_company' => '', 'reference' => 'WC-123',
		'collection_contact' => array( 'name' => 'Dispatch', 'email' => 'dispatch@example.com', 'mobile_number' => '0120000000' ),
		'delivery_contact' => array( 'name' => 'Customer', 'email' => 'customer@example.com', 'mobile_number' => '0820000000' ),
	);
	$p = ES_Door_Booking_Client::collivery_payload( $args );
	es_eq( 3, $p['service'], 'numeric service used' );
	es_eq( true, $p['auto_accept'], 'manual confirmation creates accepted waybill' );
	es_eq( 'ZAF', $p['collection_address']['country'], 'Collivery ISO3 country used' );
	es_eq( '269', $p['collection_address']['street_number'], 'street number split' );
	es_eq( 'Plantation Street', $p['collection_address']['street'], 'street name split' );
} );

es_test( 'provider clients parse booking ids without leaking credentials', function () {
	$t = new ES_Door_Fake_Transport();
	$t->push( 201, json_encode( array( 'id' => 88, 'custom_tracking_reference' => 'TCG123' ) ) );
	$c = new ES_Door_Booking_Client( ES_Carrier_Booking::PROVIDER_TCG, '62429|secret', $t );
	$r = $c->create_shipment( array( 'service' => 'ECO', 'parcels' => array( array( 'weight_kg' => 1, 'length_cm' => 1, 'width_cm' => 1, 'height_cm' => 1 ) ) ) );
	es_eq( true, $r['ok'], 'ShipLogic creation parsed' );
	es_eq( '88', $r['shipment_id'], 'ShipLogic id parsed' );
	es_eq( 'TCG123', $r['tracking_ref'], 'ShipLogic tracking parsed' );
	es_not_contains( $t->calls[0]['url'], 'secret', 'Bearer secret not in URL' );

	$t2 = new ES_Door_Fake_Transport();
	$t2->push( 201, json_encode( array( 'data' => array( 'id' => 991, 'waybill_number' => '7712345' ) ) ) );
	$c2 = new ES_Door_Booking_Client( ES_Carrier_Booking::PROVIDER_MDS, 'mds-secret', $t2 );
	$r2 = $c2->create_shipment( array( 'service' => 3, 'parcels' => array( array( 'weight_kg' => 1, 'length_cm' => 1, 'width_cm' => 1, 'height_cm' => 1 ) ) ) );
	es_eq( '991', $r2['shipment_id'], 'MDS id parsed' );
	es_eq( '7712345', $r2['tracking_ref'], 'MDS waybill parsed' );
} );

es_test( 'native provider documents are proxied only as real PDFs', function () {
	$pdf = "%PDF-1.4\nfixture";
	$t = new ES_Door_Fake_Transport();
	$t->push( 200, json_encode( array( 'url' => 'https://labels.shiplogic.com/signed/waybill.pdf' ) ) )->push( 200, $pdf, null, 'application/pdf' );
	$c = new ES_Door_Booking_Client( ES_Carrier_Booking::PROVIDER_TCG, 'token', $t );
	$r = $c->fetch_document( 'waybill', 88 );
	es_eq( true, $r['ok'], 'signed ShipLogic PDF accepted' );
	es_eq( $pdf, $r['body'], 'ShipLogic PDF preserved' );
	$tbad = new ES_Door_Fake_Transport();
	$tbad->push( 200, json_encode( array( 'url' => 'https://127.0.0.1/internal' ) ) );
	$cbad = new ES_Door_Booking_Client( ES_Carrier_Booking::PROVIDER_TCG, 'token', $tbad );
	es_eq( false, $cbad->fetch_document( 'waybill', 88 )['ok'], 'provider-controlled SSRF URL rejected' );

	$t2 = new ES_Door_Fake_Transport();
	$t2->push( 200, json_encode( array( 'data' => array( 'image' => base64_encode( $pdf ) ) ) ) );
	$c2 = new ES_Door_Booking_Client( ES_Carrier_Booking::PROVIDER_MDS, 'token', $t2 );
	$r2 = $c2->fetch_document( 'label', 99 );
	es_eq( true, $r2['ok'], 'base64 MDS PDF accepted' );

	$t3 = new ES_Door_Fake_Transport();
	$t3->push( 200, json_encode( array( 'data' => array( 'image' => base64_encode( '<html>bad</html>' ) ) ) ) );
	$c3 = new ES_Door_Booking_Client( ES_Carrier_Booking::PROVIDER_MDS, 'token', $t3 );
	es_eq( false, $c3->fetch_document( 'waybill', 1 )['ok'], 'non-PDF rejected' );
} );
