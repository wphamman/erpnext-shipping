<?php

es_test( 'option mutex stores an immutable owner lease and parses staleness', function () {
	$value = ES_Option_Mutex::value( 'owner-a', 180, 1000 );
	es_eq( 'owner-a|1180', $value, 'absolute expiry fixed at acquisition' );
	$live = ES_Option_Mutex::parse( $value, 1100 );
	es_eq( true, $live['present'], 'stored lock is present' );
	es_eq( 'owner-a', $live['owner'], 'ownership token retained' );
	es_eq( false, $live['stale'], 'unexpired lock remains live' );
	es_eq( true, ES_Option_Mutex::parse( $value, 1181 )['stale'], 'expired lease becomes stale' );
	es_eq( false, ES_Option_Mutex::parse( '', 2000 )['present'], 'missing lock is absent' );
	es_eq( true, ES_Option_Mutex::parse( 'malformed', 2000 )['stale'], 'malformed stranded lock is recoverable' );
} );
