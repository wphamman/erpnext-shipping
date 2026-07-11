<?php

es_test( 'VAT-inclusive shipping is split into one Woo tax charge', function () {
	// R100 gross at 15% VAT: Woo stores R86.9565 net + R13.0435 tax.
	$tax = 100 - ( 100 / 1.15 );
	$out = ES_Shipping_Tax::split_inclusive( 100, array( 1 => $tax ) );
	es_eq( true, $out['ok'], 'valid standard-rate split accepted' );
	es_close( 86.9565217, $out['net'], 0.00001, 'gross converted to net once' );
	es_close( 13.0434783, $out['tax_total'], 0.00001, 'single 15% VAT component retained' );
	es_close( 100, $out['net'] + $out['tax_total'], 0.00001, 'customer-facing gross is unchanged' );
} );

es_test( 'gross shipping remains gross when Woo resolves no shipping tax', function () {
	$out = ES_Shipping_Tax::split_inclusive( 115, array() );
	es_eq( true, $out['ok'], 'zero/non-taxable split accepted' );
	es_eq( 115.0, $out['net'], 'no tax means full gross becomes cost' );
	es_eq( array(), $out['taxes'], 'explicit empty map prevents Woo adding tax later' );
} );

es_test( 'inclusive shipping split preserves Woo tax ids and compound totals', function () {
	$out = ES_Shipping_Tax::split_inclusive( 140, array( 4 => 12.0, 9 => 6.260869565 ) );
	es_eq( true, $out['ok'], 'multi-rate tax split accepted' );
	es_eq( array( 4, 9 ), array_keys( $out['taxes'] ), 'Woo tax-rate ids preserved' );
	es_close( 121.739130435, $out['net'], 0.00001, 'all inclusive components removed from gross' );
} );

es_test( 'malformed inclusive tax results fail closed', function () {
	es_eq( 'invalid_gross', ES_Shipping_Tax::split_inclusive( 'free', array() )['error'], 'non-numeric gross refused' );
	es_eq( 'invalid_gross', ES_Shipping_Tax::split_inclusive( -1, array() )['error'], 'negative gross refused' );
	es_eq( 'invalid_tax', ES_Shipping_Tax::split_inclusive( 100, array( 1 => -1 ) )['error'], 'negative tax refused' );
	es_eq( 'tax_exceeds_gross', ES_Shipping_Tax::split_inclusive( 10, array( 1 => 11 ) )['error'], 'tax above gross refused' );
} );
