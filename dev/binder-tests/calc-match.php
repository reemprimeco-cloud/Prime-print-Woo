<?php
/**
 * Which products get a sticker price calculator (prime_product_id_matches()).
 *
 * A copy of a calculator product must keep its calculator whatever state the
 * language setup is in: pinned by hand on the product, or found through
 * Polylang's stored translation group even after products stop being a
 * Polylang-translated post type. Polylang is not installed here, so its
 * taxonomy is registered by hand, exactly as Polylang stores it.
 */
require '/wordpress/wp-load.php';
require '/binder-tests/lib.php';

if ( ! function_exists( 'woocommerce_wp_select' ) ) {
	function woocommerce_wp_select( $args ) {} // not rendered in this test
}
require_once get_theme_root() . '/prime-printing/inc/i18n.php';

register_taxonomy( 'post_translations', 'product', array( 'public' => false ) );

$mk = static function ( $title ) {
	return wp_insert_post( array( 'post_type' => 'product', 'post_title' => $title, 'post_status' => 'publish' ) );
};
$canonical = $mk( 'PP Sticker' );
$arabic    = $mk( 'ملصقات PP مقاومة للماء' );
$pinned    = $mk( 'PP Sticker (copy)' );
$other     = $mk( 'Desk calendar' );

t_ok( prime_product_id_matches( $canonical, $canonical ), 'the calculator product itself matches' );
t_ok( ! prime_product_id_matches( $arabic, $canonical ), 'an unlinked copy does not match on its own' );

// Polylang's storage: one post_translations term per group, the map serialized in its description.
$term = wp_insert_term( 'pll_' . uniqid(), 'post_translations', array( 'description' => serialize( array( 'en' => $canonical, 'ar' => $arabic ) ) ) );
wp_set_object_terms( $arabic, array( (int) $term['term_id'] ), 'post_translations' );
wp_set_object_terms( $canonical, array( (int) $term['term_id'] ), 'post_translations' );
t_ok( prime_product_id_matches( $arabic, $canonical ), 'the Arabic copy matches through the stored translation group (no pll_get_post needed)' );
t_ok( ! prime_product_id_matches( $other, $canonical ), 'an unrelated product still does not match' );

update_post_meta( $pinned, PRIME_CALCULATOR_META, $canonical );
t_ok( prime_product_id_matches( $pinned, $canonical ), 'a product pinned by hand in the admin matches' );
t_ok( ! prime_product_id_matches( $pinned, $canonical + 999 ), 'the pin only matches its own calculator' );

// The admin save keeps only real calculators.
define( 'PRIME_PP_STICKER_PRODUCT_ID', $canonical );
$_POST[ PRIME_CALCULATOR_META ] = (string) $canonical;
prime_save_calculator_field( $other );
t_eq( (int) get_post_meta( $other, PRIME_CALCULATOR_META, true ), $canonical, 'saving the field stores the chosen calculator' );
$_POST[ PRIME_CALCULATOR_META ] = '424242';
prime_save_calculator_field( $other );
t_eq( get_post_meta( $other, PRIME_CALCULATOR_META, true ), '', 'an unknown calculator id is not stored' );

t_done();
