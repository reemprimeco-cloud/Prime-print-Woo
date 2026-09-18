<?php
/**
 * WooCommerce integration.
 *
 * Phase 0 scope: declare support, strip the default wrappers the theme replaces
 * with its own, and set the Kuwaiti dinar formatting the references use. The
 * shop loop, product page, checkout and account overrides land in Phases 3–8.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

/**
 * Declare WooCommerce support and opt into the features the design needs.
 */
function prime_woocommerce_setup() {
	add_theme_support( 'woocommerce' );

	// Native gallery behaviour — the product reference relies on the main image
	// swapping on thumbnail and variation selection.
	add_theme_support( 'wc-product-gallery-zoom' );
	add_theme_support( 'wc-product-gallery-lightbox' );
	add_theme_support( 'wc-product-gallery-slider' );
}
add_action( 'after_setup_theme', 'prime_woocommerce_setup' );

/**
 * Declare compatibility with High-Performance Order Storage.
 *
 * Declared now so the new install starts on HPOS rather than being migrated
 * later, and so WooCommerce does not flag the theme as incompatible.
 */
function prime_declare_hpos_compatibility() {
	if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		return;
	}

	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
		'custom_order_tables',
		PRIME_DIR . '/functions.php',
		true
	);
}
add_action( 'before_woocommerce_init', 'prime_declare_hpos_compatibility' );

/**
 * Remove WooCommerce's default page wrappers.
 *
 * The theme's own header.php/footer.php provide the document shell, and each
 * WooCommerce template supplies its own container, so the default
 * <div id="primary"><main id="main"> pair would only add markup the CSS then
 * has to work around.
 */
function prime_remove_default_wrappers() {
	remove_action( 'woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10 );
	remove_action( 'woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10 );

	// The breadcrumb is rebuilt inside the theme's dark page head instead.
	remove_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', 20 );

	// The default sidebar is not part of any approved layout.
	remove_action( 'woocommerce_sidebar', 'woocommerce_get_sidebar', 10 );
}
add_action( 'init', 'prime_remove_default_wrappers' );

/**
 * Open the theme's content wrapper around WooCommerce pages.
 */
function prime_wc_wrapper_start() {
	echo '<main id="prime-content" class="prime-main prime-main--shop">';
}
add_action( 'woocommerce_before_main_content', 'prime_wc_wrapper_start', 10 );

/**
 * Close the theme's content wrapper.
 */
function prime_wc_wrapper_end() {
	echo '</main>';
}
add_action( 'woocommerce_after_main_content', 'prime_wc_wrapper_end', 10 );

/**
 * Kuwaiti dinar is quoted to three decimals throughout the references.
 *
 * This is the store-wide default; it is set here rather than left to the
 * WooCommerce settings screen so a fresh install and a restored backup agree.
 *
 * @return int
 */
function prime_price_decimals() {
	return 3;
}
add_filter( 'wc_get_price_decimals', 'prime_price_decimals' );

/**
 * Number of products per page in the shop grid.
 *
 * The reference pages twelve at a time behind a "Show more" control rather than
 * rendering the whole catalogue; Phase 3 wires the AJAX, this sets the page size.
 *
 * @return int
 */
function prime_loop_products_per_page() {
	return 12;
}
add_filter( 'loop_shop_per_page', 'prime_loop_products_per_page', 20 );

/**
 * Columns in the product loop.
 *
 * @return int
 */
function prime_loop_columns() {
	return 4;
}
add_filter( 'loop_shop_columns', 'prime_loop_columns' );

/**
 * Image size used by the loop's product thumbnail.
 *
 * @return string
 */
function prime_loop_thumbnail_size() {
	return 'prime-card';
}
add_filter( 'single_product_archive_thumbnail_size', 'prime_loop_thumbnail_size' );
