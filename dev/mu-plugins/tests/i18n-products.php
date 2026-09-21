<?php
/** Tests for inc/i18n-products.php — one product, served in both languages. */
require_once __DIR__ . '/lib.php';

t_ok( function_exists( 'prime_is_arabic' ), 'the module is loaded' );
t_ok( ! pll_is_translated_post_type( 'product' ), 'products are NOT a translated post type' );

$p = new WC_Product_Simple();
$p->set_name( 'Desk Organiser' );
$p->set_slug( 'test-desk-organiser' );
$p->set_regular_price( '4.000' );
$p->set_description( '<p>A tidy desk.</p>' );
$p->set_short_description( 'Tidy.' );
$p->set_status( 'publish' );
$id = $p->save();

update_post_meta( $id, PRIME_AR_TITLE, 'منظّم المكتب' );
update_post_meta( $id, PRIME_AR_SLUG, 'منظم-المكتب' );
update_post_meta( $id, PRIME_AR_EXCERPT, 'مرتّب.' );
update_post_meta( $id, PRIME_AR_CONTENT, '<p>مكتب مرتّب.</p>' );

// ---- English ------------------------------------------------------------------
PLL()->curlang = PLL()->model->get_language( 'en' );
t_ok( ! prime_is_arabic(), 'English is the default language' );
t_eq( get_the_title( $id ), 'Desk Organiser', 'the English name shows in English' );
t_eq( wc_get_product( $id )->get_name(), 'Desk Organiser', 'and through WooCommerce too' );
t_ok( false === strpos( get_permalink( $id ), '/ar/' ), 'the English permalink has no /ar/ prefix', get_permalink( $id ) );

// ---- Arabic -------------------------------------------------------------------
PLL()->curlang = PLL()->model->get_language( 'ar' );
t_ok( prime_is_arabic(), 'Arabic is detected' );
t_eq( get_the_title( $id ), 'منظّم المكتب', 'the Arabic name shows in Arabic' );
t_eq( wc_get_product( $id )->get_name(), 'منظّم المكتب', 'and through WooCommerce (cart, orders, emails)' );
t_eq( wc_get_product( $id )->get_short_description(), 'مرتّب.', 'the Arabic short description shows' );
t_eq( wc_get_product( $id )->get_description(), '<p>مكتب مرتّب.</p>', 'the Arabic description shows' );

$ar_link = get_permalink( $id );
t_ok( false !== strpos( $ar_link, '/ar/product/' ), 'the Arabic permalink carries the /ar/ prefix', $ar_link );
t_ok( false !== strpos( rawurldecode( $ar_link ), 'منظم-المكتب' ), 'and the Arabic slug', rawurldecode( $ar_link ) );

// ---- one product, not two -------------------------------------------------------
t_eq( wc_get_product( $id )->get_stock_status(), 'instock', 'there is one stock value' );
$copies = get_posts( array( 'post_type' => 'product', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 's' => 'منظّم المكتب' ) );
t_eq( count( $copies ), 0, 'no second post was created for Arabic' );

// ---- the Arabic URL finds the same product ----------------------------------------
$resolved = prime_resolve_arabic_product_slug( array( 'post_type' => 'product', 'name' => 'منظم-المكتب' ) );
t_eq( $resolved['name'], 'test-desk-organiser', 'an Arabic URL resolves to the one product' );

$untouched = prime_resolve_arabic_product_slug( array( 'post_type' => 'product', 'name' => 'test-desk-organiser' ) );
t_eq( $untouched['name'], 'test-desk-organiser', 'an English URL is left alone' );

$missing = prime_resolve_arabic_product_slug( array( 'post_type' => 'product', 'name' => 'لا-يوجد' ) );
t_eq( $missing['name'], 'لا-يوجد', 'an unknown slug is left alone (404, not a wrong product)' );

// ---- falling back when nothing is translated -------------------------------------
$q = new WC_Product_Simple();
$q->set_name( 'Untranslated Item' );
$q->set_status( 'publish' );
$qid = $q->save();
t_eq( get_the_title( $qid ), 'Untranslated Item', 'a product with no Arabic text shows its English name' );

// ---- categories --------------------------------------------------------------------
$term = wp_insert_term( 'Notebooks TEST', 'product_cat' );
$tid  = $term['term_id'];
update_term_meta( $tid, PRIME_AR_NAME, 'دفاتر' );
t_eq( get_term( $tid )->name, 'دفاتر', 'a category shows its Arabic name' );
t_ok( false !== strpos( get_term_link( $tid ), '/ar/product-category/' ), 'and its link keeps the /ar/ prefix', get_term_link( $tid ) );

$plain = wp_insert_term( 'Untranslated Cat', 'product_cat' );
t_eq( get_term( $plain['term_id'] )->name, 'Untranslated Cat', 'an untranslated category keeps its English name' );

PLL()->curlang = PLL()->model->get_language( 'en' );
t_eq( get_term( $tid )->name, 'Notebooks TEST', 'and the English name on the English site' );

// ---- clean up --------------------------------------------------------------------
wp_delete_post( $id, true );
wp_delete_post( $qid, true );
wp_delete_term( $tid, 'product_cat' );
wp_delete_term( $plain['term_id'], 'product_cat' );

t_done();
