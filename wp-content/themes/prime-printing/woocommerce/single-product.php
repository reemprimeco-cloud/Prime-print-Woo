<?php
/**
 * Single product — thin wrapper.
 *
 * Overrides WooCommerce's own single-product.php. The actual layout lives in
 * woocommerce/content-single-product.php, kept separate so the loop/header/
 * footer plumbing here never needs to change when that layout does.
 *
 * @package PrimePrinting
 * @version 9.4.0
 */

defined( 'ABSPATH' ) || exit;

get_header();

while ( have_posts() ) :
	the_post();

	wc_get_template_part( 'content', 'single-product' );

endwhile;

get_footer();
