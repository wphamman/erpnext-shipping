<?php
/**
 * Minimal repo-local test harness for ERPNext Shipping (no PHPUnit dependency).
 *
 * Provides: a WP-shim (ABSPATH), a tiny assertion framework, in-memory fakes
 * for the TCG Locker transport/cache seams, and a fixture loader. Run via
 * `php tests/run.php`.
 */

error_reporting( E_ALL );

// Let the plugin files' `defined('ABSPATH') || exit;` guards pass.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// The unit under test — loaded here so the fakes below can implement its
// interfaces. Only pure classes are exercised; the WP-backed transport/cache
// impls are declared but never instantiated under test.
require dirname( __DIR__ ) . '/includes/class-es-tcg-locker-client.php';
require dirname( __DIR__ ) . '/includes/class-es-tcg-locker-packer.php';
require dirname( __DIR__ ) . '/includes/class-es-tcg-locker-rate.php';

$GLOBALS['es_test_pass']    = 0;
$GLOBALS['es_test_fail']    = 0;
$GLOBALS['es_test_current'] = '';

function es_test( $name, callable $fn ) {
	$GLOBALS['es_test_current'] = $name;
	echo "• $name\n";
	try {
		$fn();
	} catch ( \Throwable $e ) {
		es_fail( 'threw ' . get_class( $e ) . ': ' . $e->getMessage() );
	}
}

function es_ok( $cond, $msg ) {
	if ( $cond ) {
		$GLOBALS['es_test_pass']++;
	} else {
		es_fail( $msg );
	}
}

function es_fail( $msg ) {
	$GLOBALS['es_test_fail']++;
	echo "    FAIL [{$GLOBALS['es_test_current']}]: $msg\n";
}

function es_eq( $expected, $actual, $msg ) {
	es_ok(
		$expected === $actual,
		$msg . ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')'
	);
}

function es_close( $expected, $actual, $eps, $msg ) {
	es_ok(
		is_numeric( $actual ) && abs( $expected - $actual ) <= $eps,
		$msg . ' (expected ~' . $expected . ', got ' . var_export( $actual, true ) . ')'
	);
}

function es_contains( $haystack, $needle, $msg ) {
	es_ok( is_string( $haystack ) && false !== strpos( $haystack, $needle ), $msg . " (missing '$needle')" );
}

function es_not_contains( $haystack, $needle, $msg ) {
	es_ok( is_string( $haystack ) && false === strpos( $haystack, $needle ), $msg . " (unexpectedly found '$needle')" );
}

function es_fixture( $name ) {
	return file_get_contents( __DIR__ . '/fixtures/' . $name );
}

/**
 * Fake transport: queue responses, capture calls. No network.
 */
class ES_Fake_Transport implements ES_TCG_Locker_Transport {
	public $queue = array();
	public $calls = array();

	public function push( $code, $body, $error = null, $content_type = 'application/json' ) {
		$this->queue[] = array(
			'code'         => $code,
			'body'         => $body,
			'error'        => $error,
			'content_type' => $content_type,
		);
		return $this;
	}

	public function request( $method, $url, array $headers, $body, $timeout ) {
		$this->calls[] = array(
			'method'  => $method,
			'url'     => $url,
			'headers' => $headers,
			'body'    => $body,
			'timeout' => $timeout,
		);
		if ( $this->queue ) {
			return array_shift( $this->queue );
		}
		return array( 'code' => 0, 'body' => '', 'error' => 'no-response-queued', 'content_type' => '' );
	}

	public function last_call() {
		return $this->calls ? end( $this->calls ) : null;
	}
}

/**
 * Fake cache: in-memory map (ignores TTL).
 */
class ES_Array_Cache implements ES_TCG_Locker_Cache {
	public $store = array();

	public function get( $key ) {
		return array_key_exists( $key, $this->store ) ? $this->store[ $key ] : null;
	}

	public function set( $key, $value, $ttl ) {
		$this->store[ $key ] = $value;
	}
}

/** Cache key the client uses for the locker dataset (mirror of the private helper). */
function es_lockers_cache_key( $api_base = 'https://sandbox.api-pudo.co.za/api/v1' ) {
	return 'es_tcg_locker_data_' . md5( $api_base );
}
