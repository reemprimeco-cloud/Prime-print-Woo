<?php
/**
 * Product gallery — main image with a thumbnail strip.
 *
 * A deliberately simple thumbnail-swap gallery matching
 * prime-printing-product.html, rather than WooCommerce's default zoom/
 * lightbox/slider gallery (add_theme_support('wc-product-gallery-*'), declared
 * in inc/woocommerce.php for any plugin that expects it, but not used by this
 * markup). No JS framework needed: product.js swaps the main <img> src on
 * thumbnail click.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

global $product;

if ( ! $product instanceof WC_Product ) {
	return;
}

$ids = prime_product_gallery_ids();

if ( ! $ids ) {
	return;
}

$main_id = $ids[0];
?>

<div class="prime-gallery" data-prime-gallery>
	<div class="prime-gallery__main">
		<?php
		echo wp_get_attachment_image(
			$main_id,
			'large',
			false,
			array(
				'data-prime-gallery-main' => '',
				'alt'                     => get_the_title(),
			)
		);
		?>
	</div>

	<?php if ( count( $ids ) > 1 ) : ?>
		<div class="prime-gallery__thumbs">
			<?php foreach ( $ids as $index => $attachment_id ) : ?>
				<button
					type="button"
					class="prime-gallery__thumb <?php echo 0 === $index ? 'is-on' : ''; ?>"
					data-prime-gallery-thumb
					data-full="<?php echo esc_url( wp_get_attachment_image_url( $attachment_id, 'large' ) ); ?>"
					aria-label="<?php
						/* translators: %d: image number. */
						echo esc_attr( sprintf( __( 'Show image %d', 'prime-printing' ), $index + 1 ) );
					?>"
				>
					<?php echo wp_get_attachment_image( $attachment_id, 'thumbnail', false, array( 'alt' => '' ) ); ?>
				</button>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
