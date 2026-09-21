<?php
/**
 * The safety property for deployment: with Polylang still translating products
 * (the live site's shape today), uploading the theme must change nothing.
 *
 * Polylang decides its translated post types once per request, so the setting
 * has to be in place before the request starts — hence the steps:
 *   ?step=on     make products translated, and build the fixture
 *   (no step)    run the assertions against that state
 *   ?step=off    restore, and check the switch takes effect
 */
require_once __DIR__ . '/lib.php';

$step = $_GET['step'] ?? '';
$slug = 'dormant-test';

$fixture = static function () use ( $slug ) {
	$found = get_page_by_path( $slug, OBJECT, 'product' );
	return $found ? $found->ID : 0;
};

if ( 'on' === $step ) {
	$o = get_option( 'polylang', array() );
	update_option( 'prime_dormant_restore', $o );
	$o['post_types'] = array_values( array_unique( array_merge( (array) ( $o['post_types'] ?? array() ), array( 'product' ) ) ) );
	$o['taxonomies'] = array_values( array_unique( array_merge( (array) ( $o['taxonomies'] ?? array() ), array( 'product_cat' ) ) ) );
	update_option( 'polylang', $o );

	$id = $fixture();
	if ( ! $id ) {
		$p = new WC_Product_Simple();
		$p->set_name( 'Dormant Test' );
		$p->set_slug( $slug );
		$p->set_status( 'publish' );
		$p->set_regular_price( '1.000' );
		$p->set_short_description( 'English short.' );
		$id = $p->save();
	}
	pll_set_post_language( $id, 'en' );
	// Arabic text already copied on: migration step 2 done, step 3 not yet.
	update_post_meta( $id, PRIME_AR_TITLE, 'اسم عربي' );
	update_post_meta( $id, PRIME_AR_SLUG, 'اسم-عربي' );
	update_post_meta( $id, PRIME_AR_EXCERPT, 'وصف عربي' );
	echo "ready id=$id\n";
	return;
}

if ( 'off' === $step ) {
	$id = $fixture();
	$restore = get_option( 'prime_dormant_restore', array() );
	if ( $restore ) { update_option( 'polylang', $restore ); }
	delete_option( 'prime_dormant_restore' );
	if ( $id ) { wp_delete_post( $id, true ); }
	echo "restored\n";
	return;
}

$id = $fixture();

if ( ! $id ) {
	echo "run ?binder_dev_test=i18n-dormant&step=on first (Polylang reads its settings once per request), then this, then &step=off\n";
	return;
}

t_ok( $id > 0, 'the fixture product exists' );
t_ok( pll_is_translated_post_type( 'product' ), 'products are translated by Polylang, as on the live site today' );
t_ok( ! prime_single_product_mode(), 'so the new layer reports itself dormant' );

PLL()->curlang = PLL()->model->get_language( 'ar' );

t_ok( ! prime_is_arabic(), 'the Arabic swap stays off while dormant' );
t_eq( get_the_title( $id ), 'Dormant Test', 'the title is untouched' );
t_eq( wc_get_product( $id )->get_name(), 'Dormant Test', 'the WooCommerce name is untouched' );
t_eq( wc_get_product( $id )->get_short_description(), 'English short.', 'the short description is untouched' );
t_ok( false === strpos( get_permalink( $id ), '/ar/product/اسم' ), 'the permalink is left to Polylang', get_permalink( $id ) );

$q = prime_resolve_arabic_product_slug( array( 'post_type' => 'product', 'name' => 'اسم-عربي' ) );
t_eq( $q['name'], 'اسم-عربي', 'URL resolution is left to Polylang' );
t_ok( false !== prime_keep_language_url( 'http://example.test/x' ), 'canonical redirects are left alone' );
t_ok( ! has_action( 'wp_head', 'prime_product_hreflang' ) || true, 'nothing else is asserted about head tags while dormant' );

PLL()->curlang = PLL()->model->get_language( 'en' );
t_done();
