<?php
/**
 * Wrapper used by run.sh: runs one test file, captures its output to a file
 * on the mounted host directory (run-blueprint does not print PHP output).
 */
$name = $GLOBALS['BINDER_TEST'];
ob_start();
try {
	require "/binder-tests/{$name}.php";
} catch ( Throwable $e ) {
	echo 'FAIL  uncaught: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() . "\n";
}
file_put_contents( "/binder-tests/out-{$name}.txt", ob_get_clean() );
