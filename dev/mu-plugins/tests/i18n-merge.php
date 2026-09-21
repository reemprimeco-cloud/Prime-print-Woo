<?php
/** Tests for inc/i18n-product-merge.php — folding the duplicates back into one product. */
require_once __DIR__ . '/lib.php';

t_ok( function_exists( 'prime_merge_one_product' ), 'the merge tool is loaded' );

// Recreate the live shape: an English product with a separate Arabic post.
$en = new WC_Product_Simple();
$en->set_name( 'Ramadan Cups' );
$en->set_slug( 'ramadan-cups-test' );
$en->set_regular_price( '5.000' );
$en->set_description( '<p>Paper cups.</p>' );
$en->set_short_description( 'Cups.' );
$en->set_stock_status( 'instock' );
$en->set_status( 'publish' );
$en_id = $en->save();

$ar_id = wp_insert_post(
	array(
		'post_type'    => 'product',
		'post_status'  => 'publish',
		'post_title'   => 'أكواب رمضان',
		'post_name'    => 'اكواب-رمضان',
		'post_content' => '<p>أكواب ورقية.</p>',
		'post_excerpt' => 'أكواب.',
	)
);
update_post_meta( $ar_id, '_price', '5.000' );
update_post_meta( $ar_id, '_stock_status', 'instock' );
pll_set_post_language( $en_id, 'en' );
pll_set_post_language( $ar_id, 'ar' );
pll_save_post_translations( array( 'en' => $en_id, 'ar' => $ar_id ) );

$pairs = prime_find_arabic_duplicates();
$mine  = array_values( array_filter( $pairs, static fn( $p ) => $p['english_id'] === $en_id ) );
t_eq( count( $mine ), 1, 'the duplicate pair is found' );
t_eq( $mine[0]['arabic_id'], $ar_id, 'and points at the Arabic post' );
t_ok( ! $mine[0]['done'], 'it is not merged yet' );

// ---- preview changes nothing ------------------------------------------------------
$preview = prime_merge_one_product( $en_id, $ar_id, true );
t_eq( get_post_meta( $en_id, PRIME_AR_TITLE, true ), '', 'a preview writes nothing' );
t_eq( get_post_status( $ar_id ), 'publish', 'and leaves the duplicate published' );
t_eq( count( $preview['moved'] ), 4, 'but reports all four fields it would move' );

// ---- the merge ---------------------------------------------------------------------
prime_merge_one_product( $en_id, $ar_id );

t_eq( get_post_meta( $en_id, PRIME_AR_TITLE, true ), 'أكواب رمضان', 'the Arabic name moved onto the one product' );
t_eq( rawurldecode( get_post_meta( $en_id, PRIME_AR_SLUG, true ) ), 'اكواب-رمضان', 'the Arabic URL slug moved too' );
t_eq( get_post_meta( $en_id, PRIME_AR_EXCERPT, true ), 'أكواب.', 'the Arabic short description moved' );
t_eq( get_post_meta( $en_id, PRIME_AR_CONTENT, true ), '<p>أكواب ورقية.</p>', 'the Arabic description moved' );
t_eq( get_post_status( $ar_id ), 'publish', 'copying the text changes nothing a customer can see' );

// ---- the old Arabic URL still finds the product ----------------------------------------
$resolved = prime_resolve_arabic_product_slug( array( 'post_type' => 'product', 'name' => 'اكواب-رمضان' ) );
t_eq( $resolved['name'], 'ramadan-cups-test', 'the Arabic URL (plain letters) serves the one product' );
$encoded = prime_resolve_arabic_product_slug( array( 'post_type' => 'product', 'name' => rawurlencode( 'اكواب-رمضان' ) ) );
t_eq( $encoded['name'], 'ramadan-cups-test', 'and the same URL percent-encoded, as a browser sends it' );

// ---- and it serves it in Arabic ---------------------------------------------------------
PLL()->curlang = PLL()->model->get_language( 'ar' );
t_eq( wc_get_product( $en_id )->get_name(), 'أكواب رمضان', 'that product shows its Arabic name' );
t_eq( wc_get_product( $en_id )->get_short_description(), 'أكواب.', 'and its Arabic short description' );
t_ok( false !== strpos( rawurldecode( get_permalink( $en_id ) ), '/ar/product/اكواب-رمضان' ), 'and its Arabic permalink is the one that was indexed', rawurldecode( get_permalink( $en_id ) ) );
PLL()->curlang = PLL()->model->get_language( 'en' );
t_eq( wc_get_product( $en_id )->get_name(), 'Ramadan Cups', 'while English is unchanged' );

// ---- one stock, one price -----------------------------------------------------------------
$live = wc_get_product( $en_id );
$live->set_stock_status( 'outofstock' );
$live->save();
PLL()->curlang = PLL()->model->get_language( 'ar' );
t_ok( ! wc_get_product( $en_id )->is_in_stock(), 'out of stock on the Arabic side too — there is only one product now' );
PLL()->curlang = PLL()->model->get_language( 'en' );

// ---- running it twice is safe ---------------------------------------------------------------
prime_merge_one_product( $en_id, $ar_id );
t_eq( get_post_meta( $en_id, PRIME_AR_TITLE, true ), 'أكواب رمضان', 're-running the merge changes nothing' );
$again = array_values( array_filter( prime_find_arabic_duplicates(), static fn( $p ) => $p['english_id'] === $en_id ) );
t_ok( $again[0]['done'], 'and the pair now reports itself as done' );

// ---- an Arabic-only product is left alone -------------------------------------------------
$orphan = wp_insert_post( array( 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'منتج عربي فقط', 'post_name' => 'منتج-عربي-فقط' ) );
pll_set_post_language( $orphan, 'ar' );
t_ok( in_array( $orphan, prime_find_orphan_arabic_products(), true ), 'an Arabic-only product is reported, not merged' );
t_eq( get_post_status( $orphan ), 'publish', 'and stays published' );

// ---- the switch -------------------------------------------------------------------------------
$before = get_option( 'polylang', array() );
$o = $before;
$o['post_types'] = array( 'product' );
$o['taxonomies'] = array( 'product_cat' );
update_option( 'polylang', $o );
$switched = prime_stop_translating_products();
t_ok( $switched['changed'], 'the switch reports a change' );
t_ok( $switched['retired'] >= 1, 'and retires the duplicates', wp_json_encode( $switched ) );
t_eq( get_post_status( $ar_id ), 'draft', 'the duplicate is a draft, not deleted' );
t_ok( null !== get_post( $ar_id ), 'the duplicate post still exists, so old orders keep their record' );
$after = get_option( 'polylang', array() );
t_ok( ! in_array( 'product', (array) $after['post_types'], true ), 'products are no longer translated by Polylang' );
t_ok( ! in_array( 'product_cat', (array) $after['taxonomies'], true ), 'nor are product categories' );
$again2 = prime_stop_translating_products();
t_ok( ! $again2['changed'] && 0 === $again2['retired'], 'running it again changes nothing' );

// ---- the way back ------------------------------------------------------------------------------
t_ok( prime_undo_switch() >= 1, 'undo restores the duplicates' );
t_eq( get_post_status( $ar_id ), 'publish', 'the duplicate is published again' );
t_ok( pll_is_translated_post_type( 'product' ) || in_array( 'product', (array) get_option( 'polylang' )['post_types'], true ), 'and Polylang translates products again' );
t_eq( get_post_meta( $en_id, PRIME_AR_TITLE, true ), 'أكواب رمضان', 'the copied Arabic text survives the undo' );
update_option( 'polylang', $before );

// ---- clean up ----------------------------------------------------------------------------------
wp_delete_post( $en_id, true );
wp_delete_post( $ar_id, true );
wp_delete_post( $orphan, true );

t_done();
