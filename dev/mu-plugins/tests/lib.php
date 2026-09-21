<?php
/**
 * Tiny assertion helper for the binder plugin tests, run inside WordPress
 * Playground (see dev/binder-tests/run.sh). Prints one line per check and a
 * summary; the runner script greps for FAIL.
 */

$GLOBALS['binder_t'] = array( 'pass' => 0, 'fail' => 0 );

function t_ok( $cond, $label, $detail = '' ) {
	if ( $cond ) {
		$GLOBALS['binder_t']['pass']++;
		echo "PASS  $label\n";
	} else {
		$GLOBALS['binder_t']['fail']++;
		echo "FAIL  $label" . ( $detail ? "  ($detail)" : '' ) . "\n";
	}
}

function t_eq( $actual, $expected, $label ) {
	t_ok( $actual === $expected, $label, 'expected ' . json_encode( $expected ) . ', got ' . json_encode( $actual ) );
}

function t_done() {
	$t = $GLOBALS['binder_t'];
	echo "\nSUMMARY  pass={$t['pass']} fail={$t['fail']}\n";
}
