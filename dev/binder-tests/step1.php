<?php
/**
 * Step 1 tests: activation/deactivation/uninstall lifecycle of the design
 * table, REST route registration, stub responses, template geometry endpoint.
 */
require '/wordpress/wp-load.php';
require '/binder-tests/lib.php';

$plugin = 'prime-binder-designer/prime-binder-designer.php';

t_ok( file_exists( WP_PLUGIN_DIR . '/' . $plugin ), 'plugin file is mounted' );

// ---- Lifecycle ---------------------------------------------------------------
require_once WP_PLUGIN_DIR . '/' . $plugin;
t_ok( class_exists( 'Binder_DB' ), 'plugin classes load' );

Binder_DB::drop_table();
t_ok( ! Binder_DB::table_exists(), 'table absent before activation' );

$res = activate_plugin( $plugin );
t_ok( ! is_wp_error( $res ), 'activate_plugin() succeeds', is_wp_error( $res ) ? $res->get_error_message() : '' );
t_ok( Binder_DB::table_exists(), 'activation creates the table' );
t_eq( get_option( 'binder_db_version' ), Binder_DB::DB_VERSION, 'db version option stored' );

// Columns exactly as §3.2.
global $wpdb;
$cols = $wpdb->get_col( 'SELECT name FROM pragma_table_info("' . Binder_DB::table() . '")' );
if ( empty( $cols ) ) {
	$cols = $wpdb->get_col( 'SHOW COLUMNS FROM ' . Binder_DB::table() );
}
// The 15 columns of §3.2, plus render_job_id and render_error (added in schema v2 for the async render).
$expected_cols = array( 'id', 'product_id', 'order_id', 'order_item_id', 'session_token', 'template', 'mode', 'design_json', 'status', 'preview_url', 'pdf_url', 'pdf_cmyk_url', 'validation_warnings', 'render_job_id', 'render_error', 'created_at', 'updated_at' );
t_eq( array_values( $cols ), $expected_cols, 'table has the §3.2 columns plus render_job_id / render_error' );
t_eq( array_slice( array_values( $cols ), 0, 13 ), array_slice( $expected_cols, 0, 13 ), 'the first 13 columns are exactly §3.2' );

// Insert/read round trip, including the default status.
$now = current_time( 'mysql', true );
$wpdb->insert( Binder_DB::table(), array( 'product_id' => 3457, 'session_token' => str_repeat( 'a', 32 ), 'template' => 'binder_outer', 'mode' => 'upload', 'design_json' => '{}', 'created_at' => $now, 'updated_at' => $now ) );
$row = $wpdb->get_row( 'SELECT * FROM ' . Binder_DB::table() . ' WHERE id = ' . (int) $wpdb->insert_id, ARRAY_A );
t_eq( $row['status'] ?? null, 'draft', 'status defaults to draft' );
t_ok( array_key_exists( 'order_id', $row ) && null === $row['order_id'], 'order_id defaults to NULL' );

// Re-activation is idempotent and keeps rows.
$res = activate_plugin( $plugin );
$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Binder_DB::table() );
t_eq( $count, 1, 'a second activation does not wipe rows' );

deactivate_plugins( $plugin );
t_ok( Binder_DB::table_exists(), 'deactivation keeps the table (customer data survives)' );
t_eq( (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Binder_DB::table() ), 1, 'deactivation keeps the rows' );

// Uninstall drops it.
uninstall_plugin( $plugin );
t_ok( ! Binder_DB::table_exists(), 'uninstall drops the table' );
t_ok( false === get_option( 'binder_db_version' ), 'uninstall removes the version option' );

// ---- REST -------------------------------------------------------------------
activate_plugin( $plugin );
prime_binder_boot();
do_action( 'rest_api_init', rest_get_server() );

$routes = array_keys( rest_get_server()->get_routes( 'binder/v1' ) );
foreach ( array( '/binder/v1/template/(?P<template>[a-z_]+)', '/binder/v1/template/(?P<template>[a-z_]+)/overlay', '/binder/v1/upload', '/binder/v1/render-callback', '/binder/v1/design', '/binder/v1/design/(?P<id>\d+)', '/binder/v1/design/(?P<id>\d+)/preview', '/binder/v1/design/(?P<id>\d+)/finalize', '/binder/v1/design/(?P<id>\d+)/status' ) as $r ) {
	t_ok( in_array( $r, $routes, true ), "route registered: $r" );
}

function call( $method, $route, $params = array() ) {
	$req = new WP_REST_Request( $method, $route );
	foreach ( $params as $k => $v ) {
		$req->set_param( $k, $v );
	}
	return rest_do_request( $req );
}

// Design routes are real now (tests/step4.php); here only that they demand a session token.
$valid = array( 'session_token' => 'abcdefabcdefabcdefabcdef', 'product_id' => 3457, 'template' => 'binder_outer', 'mode' => 'upload', 'design_json' => array( 'elements' => array() ) );
$req = new WP_REST_Request( 'POST', '/binder/v1/design' );
foreach ( array_diff_key( $valid, array( 'session_token' => 1 ) ) as $k => $v ) {
	$req->set_param( $k, $v );
}
t_eq( rest_do_request( $req )->get_status(), 401, 'POST /design without a session token is refused (401)' );

foreach ( array( 'template' => 'binder_x', 'mode' => 'weird', 'session_token' => 'short', 'product_id' => 0 ) as $field => $bad ) {
	$r = call( 'POST', '/binder/v1/design', array_merge( $valid, array( $field => $bad ) ) );
	t_eq( $r->get_status(), 400, "POST /design rejects bad $field" );
}

t_eq( call( 'GET', '/binder/v1/design/999999' )->get_status(), 404, 'GET /design/<unknown> -> 404 (no token, no row)' );

// ---- Template geometry: served numbers must equal the spec.json files ------------
foreach ( array( 'binder_outer' => 'binder-outer', 'binder_inner' => 'binder-inner' ) as $key => $stem ) {
	$r    = call( 'GET', "/binder/v1/template/$key" );
	$data = $r->get_data();
	$file = json_decode( file_get_contents( PRIME_BINDER_DIR . "templates/{$stem}-spec.json" ), true );
	t_eq( $r->get_status(), 200, "GET /template/$key -> 200" );
	t_eq( $data['spec'] ?? null, $file, "GET /template/$key returns spec.json unchanged" );
	t_ok( isset( $data['overlay_url'] ) && false !== strpos( $data['overlay_url'], 'sig=' ), "GET /template/$key includes a signed overlay URL" );
}
t_eq( call( 'GET', '/binder/v1/template/binder_nope' )->get_status(), 400, 'unknown template rejected' );

// Signature helper.
$exp = time() + 60;
$sig = Binder_Templates::sign( 'binder_outer', $exp );
t_ok( Binder_Templates::verify( 'binder_outer', $exp, $sig ), 'valid signature verifies' );
t_ok( ! Binder_Templates::verify( 'binder_outer', $exp, $sig . 'x' ), 'tampered signature fails' );
t_ok( ! Binder_Templates::verify( 'binder_inner', $exp, $sig ), 'signature is bound to the template' );
t_ok( ! Binder_Templates::verify( 'binder_outer', time() - 1, Binder_Templates::sign( 'binder_outer', time() - 1 ) ), 'expired signature fails' );

t_done();
