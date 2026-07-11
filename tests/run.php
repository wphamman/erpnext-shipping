<?php
/**
 * Repo-local test runner. Usage: php tests/run.php
 * Exits non-zero if any assertion fails.
 */

require __DIR__ . '/bootstrap.php';

// Discover and run every test-*.php file.
foreach ( glob( __DIR__ . '/test-*.php' ) as $file ) {
	require $file;
}

echo "\n";
$pass = (int) $GLOBALS['es_test_pass'];
$fail = (int) $GLOBALS['es_test_fail'];
echo "──────────────────────────────\n";
echo "Assertions: {$pass} passed, {$fail} failed\n";

exit( $fail > 0 ? 1 : 0 );
