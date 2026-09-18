<?php
/**
 * Single product — the actual layout.
 *
 * Overrides WooCommerce's own content-single-product.php. Matches
 * prime-printing-product.html: gallery + info in a two-column grid, tabs
 * below, related products below that. The summary column fires WooCommerce's
 * standard hooks in their normal order (rearranged in inc/product.php, not
 * here) so gallery aside, this stays compatible with anything a plugin hangs
 * off woocommerce_single_product_summary.
 *
 * @package PrimePrinting
 * @version 9.4.0
 */

defined( 'ABSPATH' ) || exit;

global $product;

if ( ! is_singular( 'product' ) ) {
	return;
}
?>

<div id="product-<?php the_ID(); ?>" <?php wc_product_class( 'prime-product', $product ); ?>>

	<div class="prime-wrap">
		<div class="prime-crumb">
			<?php prime_mark(); ?>
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'prime-printing' ); ?></a>
			<span aria-hidden="true">/</span>
			<?php if ( prime_has_woocommerce() && wc_get_page_id( 'shop' ) > 0 ) : ?>
				<a href="<?php echo esc_url( get_permalink( wc_get_page_id( 'shop' ) ) ); ?>"><?php esc_html_e( 'Shop', 'prime-printing' ); ?></a>
				<span aria-hidden="true">/</span>
			<?php endif; ?>
			<span><?php the_title(); ?></span>
		</div>

		<?php do_action( 'woocommerce_before_single_product' ); ?>

		<div class="prime-product__grid">
			<div class="prime-product__gallery">
				<?php get_template_part( 'template-parts/product/gallery' ); ?>
			</div>

			<div class="prime-product__summary summary entry-summary">
				<?php do_action( 'woocommerce_single_product_summary' ); ?>
			</div>
		</div>

		<?php do_action( 'woocommerce_after_single_product_summary' ); ?>
	</div>

</div>
