<?php
/**
 * Tests for inc/product-sync.php (stock/price shared between Polylang
 * translations) and the paid-order-status rule in inc/checkout-payment.php.
 */
require_once __DIR__ . '/lib.php';

t_ok( function_exists( 'pll_get_post_translations' ), 'Polylang is active' );
t_ok( function_exists( 'prime_sync_product_translations' ), 'the sync module is loaded' );
t_ok( function_exists( 'prime_paid_order_status' ), 'the order-status rule is loaded' );

// ---- a product and its Arabic translation ------------------------------------------
$mk = static function ( $title, $price, $lang ) {
	$p = new WC_Product_Simple();
	$p->set_name( $title );
	$p->set_regular_price( $price );
	$p->set_stock_status( 'instock' );
	$p->set_status( 'publish' );
	$id = $p->save();
	if ( function_exists( 'pll_set_post_language' ) ) {
		pll_set_post_language( $id, $lang );
	}
	return $id;
};

$en = $mk( 'UV Sticker', '2.000', 'en' );
$ar = $mk( 'ملصق UV', '2.000', 'ar' );
pll_save_post_translations( array( 'en' => $en, 'ar' => $ar ) );

t_eq( prime_product_translations( $en ), array( $ar ), 'the English product knows its Arabic copy' );
t_eq( prime_product_translations( $ar ), array( $en ), 'and the other way round' );

$lone = $mk( 'Untranslated', '1.000', 'en' );
t_eq( prime_product_translations( $lone ), array(), 'a product with no translation has none' );

$reload = static fn( $id ) => wc_get_product( $id );

// ---- out of stock on one copy --------------------------------------------------------
$p = $reload( $en );
$p->set_stock_status( 'outofstock' );
$p->save();

t_eq( $reload( $ar )->get_stock_status(), 'outofstock', 'out of stock on the English copy takes the Arabic copy out of stock' );
t_ok( ! $reload( $ar )->is_in_stock(), 'the Arabic copy is no longer purchasable' );

// ---- and back, from the other side ---------------------------------------------------
$p = $reload( $ar );
$p->set_stock_status( 'instock' );
$p->save();
t_eq( $reload( $en )->get_stock_status(), 'instock', 'back in stock from the Arabic copy restores the English one' );

// ---- price ----------------------------------------------------------------------------
$p = $reload( $en );
$p->set_regular_price( '3.500' );
$p->set_sale_price( '2.750' );
$p->save();
t_eq( $reload( $ar )->get_regular_price(), '3.500', 'the price follows' );
t_eq( $reload( $ar )->get_sale_price(), '2.750', 'the sale price follows' );

// ---- tracked quantities ----------------------------------------------------------------
$p = $reload( $en );
$p->set_manage_stock( true );
$p->set_stock_quantity( 10 );
$p->save();
t_ok( $reload( $ar )->get_manage_stock(), 'stock tracking is switched on for both' );
t_eq( (int) $reload( $ar )->get_stock_quantity(), 10, 'the quantity follows' );

// A sale deducts stock through WooCommerce's direct path, not the CRUD.
wc_update_product_stock( $reload( $en ), 3, 'decrease' );
t_eq( (int) $reload( $ar )->get_stock_quantity(), 7, 'stock sold on one copy is deducted from the other' );

wc_update_product_stock( $reload( $ar ), 7, 'decrease' );
t_eq( (int) $reload( $en )->get_stock_quantity(), 0, 'selling the last of them empties both' );
t_eq( $reload( $en )->get_stock_status(), 'outofstock', 'and both go out of stock' );

// ---- what must NOT be shared ------------------------------------------------------------
$post_en = get_post( $en );
t_eq( $post_en->post_title, 'UV Sticker', 'the English title is untouched' );
t_eq( get_post( $ar )->post_title, 'ملصق UV', 'the Arabic title is untouched' );

// ---- no runaway loop ---------------------------------------------------------------------
$before = did_action( 'woocommerce_update_product' );
$p = $reload( $en );
$p->set_regular_price( '9.000' );
$p->save();
$after = did_action( 'woocommerce_update_product' );
t_ok( $after - $before <= 3, 'a save does not bounce between the copies', "fired {$before} -> {$after}" );
t_eq( $reload( $ar )->get_regular_price(), '9.000', 'and the value still arrived' );

// ---- variable products are left alone -----------------------------------------------------
$v = new WC_Product_Variable();
$v->set_name( 'Variable EN' );
$v->set_status( 'publish' );
$v_en = $v->save();
$v2 = new WC_Product_Variable();
$v2->set_name( 'Variable AR' );
$v2->set_status( 'publish' );
$v_ar = $v2->save();
pll_set_post_language( $v_en, 'en' );
pll_set_post_language( $v_ar, 'ar' );
pll_save_post_translations( array( 'en' => $v_en, 'ar' => $v_ar ) );
update_post_meta( $v_ar, '_prime_marker', 'untouched' );
$vp = wc_get_product( $v_en );
$vp->set_stock_status( 'outofstock' );
$vp->save();
t_eq( get_post_meta( $v_ar, '_prime_marker', true ), 'untouched', 'a variable product is not synced (its variations hold the real values)' );

// ================= the order status rule ==========================================
$order_with = static function ( $product_id ) {
	$o = wc_create_order();
	$o->add_product( wc_get_product( $product_id ), 1 );
	$o->set_status( 'pending' );
	$o->save();
	return $o;
};

$physical = $order_with( $lone );
t_ok( prime_order_needs_fulfilment( $physical ), 'a printed item needs fulfilment' );
t_eq( prime_paid_order_status( 'completed', $physical->get_id(), $physical ), 'processing', 'a gateway asking for Completed gets Processing' );
t_eq( prime_paid_order_status( 'processing', $physical->get_id(), $physical ), 'processing', 'a gateway asking for Processing is unchanged' );

$vp = new WC_Product_Simple();
$vp->set_name( 'Gift card' );
$vp->set_virtual( true );
$vp->set_downloadable( true );
$vp->set_regular_price( '5.000' );
$vp->set_status( 'publish' );
$vid = $vp->save();
$digital = $order_with( $vid );
t_ok( ! prime_order_needs_fulfilment( $digital ), 'a virtual downloadable item needs no fulfilment' );
t_eq( prime_paid_order_status( 'completed', $digital->get_id(), $digital ), 'completed', 'and such an order may complete on payment' );

// A gateway that sets the status itself, with nobody signed in.
wp_set_current_user( 0 );
$direct = $order_with( $lone );
$direct->update_status( 'completed' );
t_eq( wc_get_order( $direct->get_id() )->get_status(), 'processing', 'a gateway jumping straight to Completed is pulled back to Processing' );

// Reem marking it Completed herself.
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admin ? $admin[0]->ID : 1 );
t_ok( current_user_can( 'edit_shop_orders' ), 'the test is now running as the shop owner' );
$mine = $order_with( $lone );
$mine->update_status( 'processing' );
$mine->update_status( 'completed' );
t_eq( wc_get_order( $mine->get_id() )->get_status(), 'completed', 'the shop owner can still complete an order' );

$straight = $order_with( $lone );
$straight->update_status( 'completed' );
t_eq( wc_get_order( $straight->get_id() )->get_status(), 'completed', 'and can complete one straight from pending' );

// Leave the dev site as it was found.
foreach ( array( $en, $ar, $lone, $v_en, $v_ar, $vid ) as $id ) {
	wp_delete_post( $id, true );
}

t_done();
