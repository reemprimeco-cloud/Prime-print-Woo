<?php
/**
 * A product card in the shop grid.
 *
 * Rendered both by the archive template and by the AJAX endpoint, so the two
 * always agree. Expects the global $product to be set.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

global $product;

if ( ! $product instanceof WC_Product ) {
	return;
}

$prime_terms    = get_the_terms( $product->get_id(), 'product_cat' );
$prime_category = ( $prime_terms && ! is_wp_error( $prime_terms ) ) ? $prime_terms[0] : null;
$prime_price    = $product->get_price_html();
?>

<article <?php wc_product_class( 'prime-card', $product ); ?>>
	<a class="prime-card__media" href="<?php echo esc_url( $product->get_permalink() ); ?>" tabindex="-1" aria-hidden="true">
		<?php
		echo wp_kses_post(
			$product->get_image( 'prime-card', array( 'loading' => 'lazy' ) )
		);
		?>

		<?php if ( $product->is_featured() ) : ?>
			<span class="prime-card__badge"><?php esc_html_e( 'Best seller', 'prime-printing' ); ?></span>
		<?php elseif ( $product->is_on_sale() ) : ?>
			<span class="prime-card__badge"><?php esc_html_e( 'Sale', 'prime-printing' ); ?></span>
		<?php endif; ?>
	</a>

	<div class="prime-card__body">
		<?php if ( $prime_category ) : ?>
			<a class="prime-card__kicker" href="<?php echo esc_url( get_term_link( $prime_category ) ); ?>">
				<?php echo esc_html( $prime_category->name ); ?>
			</a>
		<?php endif; ?>

		<h3 class="prime-card__title">
			<a href="<?php echo esc_url( $product->get_permalink() ); ?>">
				<?php echo esc_html( $product->get_name() ); ?>
			</a>
		</h3>

		<div class="prime-card__foot">
			<span class="prime-price">
				<?php
				echo $prime_price
					? wp_kses_post( $prime_price )
					: esc_html__( 'On request', 'prime-printing' );
				?>
			</span>

			<?php
			/*
			 * Quote-only products (no price) send the visitor to the product page
			 * rather than offering an add-to-cart that would fail. Anything with a
			 * price, and no required options, adds straight from the grid.
			 */
			if ( ! $prime_price || ! $product->is_purchasable() || ! $product->is_in_stock() ) :
				?>
				<a class="prime-card__add" href="<?php echo esc_url( $product->get_permalink() ); ?>">
					<?php esc_html_e( 'Get a quote', 'prime-printing' ); ?>
				</a>
			<?php else : ?>
				<a
					class="prime-card__add <?php echo esc_attr( $product->supports( 'ajax_add_to_cart' ) ? 'ajax_add_to_cart add_to_cart_button' : '' ); ?>"
					href="<?php echo esc_url( $product->add_to_cart_url() ); ?>"
					data-product_id="<?php echo esc_attr( $product->get_id() ); ?>"
					data-quantity="1"
					rel="nofollow"
				>
					<?php echo esc_html( $product->add_to_cart_text() ); ?>
				</a>
			<?php endif; ?>
		</div>
	</div>
</article>
