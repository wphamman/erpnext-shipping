<?php
/**
 * Tests for ES_TCG_Locker_Rate (Phase 3) — pricing, VAT reconciliation,
 * dispatch-origin resolution, and rate-meta assembly.
 */

defined( 'ABSPATH' ) || exit;

$OFFER = array(
	'service_code'       => 'L2LL - ECO',
	'service_name'       => 'Locker to Locker L',
	'box_code'           => '13',
	'box_name'           => 'V4-L',
	'box_size'           => 'L',
	'rate'               => 92.00,   // VAT-inclusive.
	'rate_excluding_vat' => 80.00,   // net.
	'rate_revision_id'   => 'rev_l_1',
);

es_test( 'live pricing: customer pays provider incl; ex-VAT cost reconciles at 15%', function () use ( $OFFER ) {
	$p = ES_TCG_Locker_Rate::compute_pricing( $OFFER, 'live', 0, 0, 500 );
	es_eq( 'live', $p['pricing_mode'], 'mode live' );
	es_close( 92.00, $p['customer_charge_incl'], 1e-9, 'customer charge = provider incl' );
	es_close( 80.00, $p['rate_cost_ex_vat'], 1e-9, 'ex-VAT cost = provider ex-VAT (fidelity)' );
	es_close( 92.00, $p['rate_cost_ex_vat'] * 1.15, 0.01, 'ex × 1.15 reconciles to provider incl (no double tax)' );
	es_close( 92.00, $p['provider_rate_incl'], 1e-9, 'provider incl stored' );
	es_close( 80.00, $p['provider_rate_ex_vat'], 1e-9, 'provider ex-VAT stored' );
} );

es_test( 'fixed pricing: customer sees fixed incl; ex-VAT derived so WC tax reconciles', function () use ( $OFFER ) {
	$p = ES_TCG_Locker_Rate::compute_pricing( $OFFER, 'fixed', 69.00, 0, 500 );
	es_eq( 'fixed', $p['pricing_mode'], 'mode fixed' );
	es_close( 69.00, $p['customer_charge_incl'], 1e-9, 'customer charge = fixed amount' );
	es_close( 69.00 / 1.15, $p['rate_cost_ex_vat'], 0.01, 'ex-VAT = fixed / 1.15' );
	es_close( 69.00, $p['rate_cost_ex_vat'] * 1.15, 0.01, 'reconciles to fixed incl' );
	// Provider cost still recorded for profitability.
	es_close( 92.00, $p['provider_rate_incl'], 1e-9, 'provider cost still recorded under fixed pricing' );
} );

es_test( 'free threshold met → zero charge; own threshold, mode "free"', function () use ( $OFFER ) {
	$p = ES_TCG_Locker_Rate::compute_pricing( $OFFER, 'live', 0, 1000, 1200 );
	es_eq( 'free', $p['pricing_mode'], 'mode free when cart >= threshold' );
	es_close( 0.0, $p['customer_charge_incl'], 1e-9, 'customer charge zero' );
	es_close( 0.0, $p['rate_cost_ex_vat'], 1e-9, 'cost zero' );
	es_close( 92.00, $p['provider_rate_incl'], 1e-9, 'provider cost still recorded (for margin)' );

	// Below threshold → not free.
	$p2 = ES_TCG_Locker_Rate::compute_pricing( $OFFER, 'live', 0, 1000, 800 );
	es_eq( 'live', $p2['pricing_mode'], 'below threshold stays live' );
	es_close( 92.00, $p2['customer_charge_incl'], 1e-9, 'charged when under threshold' );
} );

es_test( 'dispatch_origin_for_plan — single/chooseable/split + dispatch-enable gate', function () {
	$locations = array(
		array( 'id' => 'cpt', 'type' => 'warehouse', 'tcg_locker_dispatch_enabled' => true ),
		array( 'id' => 'pta', 'type' => 'warehouse', 'tcg_locker_dispatch_enabled' => false ),
		array( 'id' => 'shop', 'type' => 'collection_point', 'tcg_locker_dispatch_enabled' => true ),
	);

	es_eq( 'cpt', ES_TCG_Locker_Rate::dispatch_origin_for_plan( array( 'type' => 'single', 'location' => 'cpt' ), $locations ), 'single, enabled warehouse' );
	es_eq( null, ES_TCG_Locker_Rate::dispatch_origin_for_plan( array( 'type' => 'single', 'location' => 'pta' ), $locations ), 'single, dispatch-disabled warehouse → null' );
	es_eq( null, ES_TCG_Locker_Rate::dispatch_origin_for_plan( array( 'type' => 'single', 'location' => 'shop' ), $locations ), 'collection point never a dispatch origin' );
	es_eq( 'cpt', ES_TCG_Locker_Rate::dispatch_origin_for_plan( array( 'type' => 'chooseable', 'locations' => array( 'pta', 'cpt' ) ), $locations ), 'chooseable → first enabled candidate' );
	es_eq( null, ES_TCG_Locker_Rate::dispatch_origin_for_plan( array( 'type' => 'chooseable', 'locations' => array( 'pta' ) ), $locations ), 'chooseable, none enabled → null' );
	es_eq( null, ES_TCG_Locker_Rate::dispatch_origin_for_plan( array( 'type' => 'split', 'shipments' => array( 'cpt' => array(), 'pta' => array() ) ), $locations ), 'split never offers TCG Locker' );
} );

es_test( 'build_rate_meta — persists exact quote, origin and pricing; hides secrets-free keys', function () use ( $OFFER ) {
	$locker  = array( 'code' => 'CG929', 'name' => 'Brackenfell Locker', 'address' => '1 Main Rd', 'lat' => -33.87, 'lng' => 18.69 );
	$pricing = ES_TCG_Locker_Rate::compute_pricing( $OFFER, 'live', 0, 0, 500 );
	$meta    = ES_TCG_Locker_Rate::build_rate_meta( $locker, $OFFER, 'cpt', $pricing, 1731000000 );

	es_eq( 'CG929', $meta[ ES_TCG_Locker_Rate::M_DEST_CODE ], 'dest code persisted' );
	es_eq( 'cpt', $meta[ ES_TCG_Locker_Rate::M_DISPATCH_LOC ], 'dispatch origin persisted' );
	es_eq( 'L2LL - ECO', $meta[ ES_TCG_Locker_Rate::M_SERVICE_CODE ], 'exact service code persisted' );
	es_eq( '13', $meta[ ES_TCG_Locker_Rate::M_BOX_CODE ], 'provider box id persisted' );
	es_eq( 'L', $meta[ ES_TCG_Locker_Rate::M_BOX_SIZE ], 'derived box size persisted' );
	es_eq( 'rev_l_1', $meta[ ES_TCG_Locker_Rate::M_REVISION_ID ], 'rate revision id persisted' );
	es_eq( '92', $meta[ ES_TCG_Locker_Rate::M_PROVIDER_RATE ], 'provider incl persisted' );
	es_eq( '80', $meta[ ES_TCG_Locker_Rate::M_PROVIDER_EX ], 'provider ex-VAT persisted' );
	es_eq( '92', $meta[ ES_TCG_Locker_Rate::M_CUSTOMER_CHG ], 'customer charge persisted' );
	es_eq( '1731000000', $meta[ ES_TCG_Locker_Rate::M_QUOTE_TS ], 'quote timestamp persisted' );
	es_eq( 'Brackenfell Locker', $meta['Locker'], 'customer-visible locker name' );
	es_eq( 'L', $meta['Box'], 'customer-visible box size' );
} );
