<?php
/**
 * Step 6 tests: product assignment and the server-side add-to-cart gate.
 * WooCommerce is not loaded in this throwaway site, so the two WC helpers the
 * class calls are stubbed; the browser side is e2e-step6.mjs against real WooCommerce.
 */
require '/wordpress/wp-load.php';
require '/binder-tests/lib.php';

$plugin = 'prime-binder-designer/prime-binder-designer.php';
require_once WP_PLUGIN_DIR . '/' . $plugin;
Binder_DB::drop_table();
activate_plugin( $plugin );
prime_binder_boot();

$GLOBALS['notices'] = array();
if ( ! function_exists( 'wc_add_notice' ) ) {
	function wc_add_notice( $msg, $type = 'success' ) {
		$GLOBALS['notices'][] = array( $type, $msg );
	}
}

$mk = static function ( $title ) {
	return wp_insert_post( array( 'post_type' => 'product', 'post_title' => $title, 'post_status' => 'publish' ) );
};
$plain = $mk( 'Plain' );
$outer = $mk( 'Outer only' );
$set   = $mk( 'Binder set' );
$other = $mk( 'Other binder' );

// ---- assignment -------------------------------------------------------------------
t_eq( Binder_Product_Meta::required_templates( $plain ), array(), 'unassigned product needs no design' );

$_POST[ Binder_Product_Meta::META ] = 'binder_outer';
Binder_Product_Meta::save( $outer );
$_POST[ Binder_Product_Meta::META ] = 'binder_set';
Binder_Product_Meta::save( $set );
$_POST[ Binder_Product_Meta::META ] = 'binder_set';
Binder_Product_Meta::save( $other );

t_eq( Binder_Product_Meta::required_templates( $outer ), array( 'binder_outer' ), 'outer product requires the outer cover' );
t_eq( Binder_Product_Meta::required_templates( $set ), array( 'binder_outer', 'binder_inner' ), 'set requires both covers' );
t_ok( Binder_Product_Meta::allows( $outer, 'binder_outer' ), 'outer product allows an outer design' );
t_ok( ! Binder_Product_Meta::allows( $outer, 'binder_inner' ), 'outer product refuses an inner design' );
t_ok( Binder_Product_Meta::allows( $set, 'binder_inner' ), 'a set allows an inner design' );
t_ok( Binder_Product_Meta::allows( $plain, 'binder_inner' ), 'an unassigned product still accepts designs (dev/testing)' );

$_POST[ Binder_Product_Meta::META ] = 'rm -rf';
Binder_Product_Meta::save( $other );
t_eq( Binder_Product_Meta::assignment( $other ), '', 'an unknown value clears the assignment' );
$_POST[ Binder_Product_Meta::META ] = '';
Binder_Product_Meta::save( $set );
t_eq( get_post_meta( $set, '_binder_template', true ), '', 'choosing "None" removes the meta' );
$_POST[ Binder_Product_Meta::META ] = 'binder_set';
Binder_Product_Meta::save( $set );

// ---- designs -----------------------------------------------------------------------
$A = 'aaaaaaaaaaaaaaaaaaaaaaaa';
$B = 'bbbbbbbbbbbbbbbbbbbbbbbb';

$design = static function ( $product, $template, $status = 'ready', $token = null, $order = null ) use ( $A ) {
	return Binder_DB::insert(
		array(
			'product_id'    => $product,
			'template'      => $template,
			'mode'          => 'upload',
			'session_token' => $token ?? $A,
			'design_json'   => '{}',
			'status'        => $status,
			'order_id'      => $order,
		)
	);
};

$good_outer = $design( $set, 'binder_outer' );
$good_inner = $design( $set, 'binder_inner' );

$post = static function ( array $designs, $token ) {
	$_POST = array( 'binder_design' => $designs, 'binder_session' => $token );
	$GLOBALS['notices'] = array();
};
$last = static function () {
	$n = end( $GLOBALS['notices'] );
	return $n ? $n[1] : '';
};

// ---- validate ----------------------------------------------------------------------
$post( array(), $A );
t_ok( true === Binder_Storefront::validate( true, $plain ), 'ordinary products are untouched by the gate' );

$post( array(), $A );
t_ok( false === Binder_Storefront::validate( true, $outer ), 'no design: add to cart refused' );
t_ok( false !== strpos( $last(), 'outer cover' ), 'the refusal names the missing cover', $last() );

$mine = $design( $outer, 'binder_outer' );
$post( array( 'binder_outer' => $mine ), $A );
t_ok( true === Binder_Storefront::validate( true, $outer ), 'a ready design of the visitor is accepted' );

$post( array( 'binder_outer' => $mine ), $B );
t_ok( false === Binder_Storefront::validate( true, $outer ), "someone else's design is refused" );

$post( array( 'binder_outer' => $mine ), '' );
t_ok( false === Binder_Storefront::validate( true, $outer ), 'a missing session token is refused' );

$post( array( 'binder_outer' => $design( $outer, 'binder_outer', 'draft' ) ), $A );
t_ok( false === Binder_Storefront::validate( true, $outer ), 'a design that was never approved (draft) is refused' );

$post( array( 'binder_outer' => $design( $outer, 'binder_outer', 'rendering' ) ), $A );
t_ok( false === Binder_Storefront::validate( true, $outer ), 'a design still rendering is refused' );

$post( array( 'binder_outer' => $design( $outer, 'binder_outer', 'failed' ) ), $A );
t_ok( false === Binder_Storefront::validate( true, $outer ), 'a failed design is refused' );

$post( array( 'binder_outer' => $design( $outer, 'binder_outer', 'ready', null, 999 ) ), $A );
t_ok( false === Binder_Storefront::validate( true, $outer ), 'a design already used by an order is refused' );

$post( array( 'binder_outer' => $design( $set, 'binder_outer' ) ), $A );
t_ok( false === Binder_Storefront::validate( true, $outer ), "a design made for a different product is refused" );

$post( array( 'binder_outer' => $design( $outer, 'binder_inner' ) ), $A );
t_ok( false === Binder_Storefront::validate( true, $outer ), 'a design of the wrong template is refused' );

$post( array( 'binder_outer' => 999999 ), $A );
t_ok( false === Binder_Storefront::validate( true, $outer ), 'a design id that does not exist is refused' );

$post( array( 'binder_outer' => 'abc; DROP TABLE' ), $A );
t_ok( false === Binder_Storefront::validate( true, $outer ), 'a junk design id is refused' );

$post( array( 'binder_outer' => array( 1 ) ), $A );
t_ok( false === Binder_Storefront::validate( true, $outer ), 'an array where an id belongs is refused' );

$post( array( 'binder_outer' => $mine ), $A );
t_ok( false === Binder_Storefront::validate( false, $outer ), 'an earlier failure is not overridden' );

// The set needs BOTH.
$post( array( 'binder_outer' => $good_outer ), $A );
t_ok( false === Binder_Storefront::validate( true, $set ), 'a set with only the outer cover is refused' );
t_ok( false !== strpos( $last(), 'inner cover' ), 'the refusal asks for the inner cover', $last() );

$post( array( 'binder_outer' => $good_inner, 'binder_inner' => $good_outer ), $A );
t_ok( false === Binder_Storefront::validate( true, $set ), 'swapped covers are refused' );

$post( array( 'binder_outer' => $good_outer, 'binder_inner' => $good_inner ), $A );
t_ok( true === Binder_Storefront::validate( true, $set ), 'a set with both covers is accepted' );

// ---- cart data ---------------------------------------------------------------------
$data = Binder_Storefront::add_item_data( array(), $set );
t_eq( $data['binder_designs'] ?? null, array( 'binder_outer' => $good_outer, 'binder_inner' => $good_inner ), 'both design ids ride on the cart line' );

$post( array( 'binder_outer' => $good_outer, 'binder_inner' => $good_inner ), $B );
t_eq( Binder_Storefront::add_item_data( array(), $set ), array(), "someone else's designs never reach the cart line" );

t_eq( Binder_Storefront::add_item_data( array( 'x' => 1 ), $plain ), array( 'x' => 1 ), 'ordinary products get no design data' );

$shown = Binder_Storefront::show_item_data( array(), array( 'binder_designs' => array( 'binder_outer' => $good_outer ) ) );
t_eq( $shown[0]['key'] ?? '', 'Outer cover design', 'the cart lists the design by cover' );

// ---- shop cards + copy ---------------------------------------------------------------
t_eq( Binder_Storefront::copy( 'upload' ), 'Upload your design', 'English copy' );
t_ok( str_contains( Binder_Storefront::copy( 'binder_outer' ), 'Outer' ), 'cover name copy' );
add_filter( 'locale', static fn() => 'ar' );
t_ok( Binder_Storefront::is_arabic(), 'Arabic locale is detected' );
t_eq( Binder_Storefront::copy( 'upload' ), 'ارفع تصميمك', 'Arabic copy' );

t_done();
