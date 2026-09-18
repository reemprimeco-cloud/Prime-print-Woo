<?php
/**
 * The shuffling product wall.
 *
 * Order is randomised in PHP on each page load, so the first paint already
 * differs between visitors and the page is not dependent on JS to feel alive.
 * The Shuffle button and the auto-reshuffle then reorder the same nodes on the
 * client — no re-fetch, no image reload.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

$prime_products = prime_wall_products( (int) get_theme_mod( 'prime_wall_count', 12 ) );

if ( ! $prime_products ) {
	return;
}

/*
 * Tile shape is decided from catalogue position and travels with the product,
 * so reshuffling rearranges the wall without resizing anything under the
 * visitor's cursor.
 */
$prime_tiles = array();

foreach ( $prime_products as $prime_index => $prime_product ) {
	$prime_tiles[] = array(
		'product' => $prime_product,
		'size'    => prime_tile_size( $prime_index ),
	);
}

shuffle( $prime_tiles );

$prime_shop_id = prime_has_woocommerce() ? wc_get_page_id( 'shop' ) : 0;
?>

<section class="prime-wall-section" id="prime-wall">
	<div class="prime-wrap">
		<div class="prime-sechead">
			<div>
				<p class="prime-label">
					<?php prime_mark(); ?>
					<span><?php esc_html_e( 'No searching required', 'prime-printing' ); ?></span>
				</p>
				<h2><?php esc_html_e( 'Everything we make, all at once', 'prime-printing' ); ?></h2>
			</div>

			<button
				class="prime-btn prime-btn--outline prime-shuffle"
				type="button"
				data-prime-shuffle
			>
				<?php prime_mark(); ?>
				<span><?php esc_html_e( 'Shuffle', 'prime-printing' ); ?></span>
			</button>
		</div>

		<?php
		/*
		 * aria-live is deliberately absent. The wall reorders itself on a timer,
		 * and announcing a twelve-item reshuffle every few seconds would make the
		 * page unusable with a screen reader. The order carries no meaning, so
		 * nothing is lost by leaving it silent.
		 */
		?>
		<div class="prime-wall" data-prime-wall>
			<?php
			foreach ( $prime_tiles as $prime_tile ) :
				$prime_product = $prime_tile['product'];
				$prime_classes = 'prime-tile';

				if ( $prime_tile['size'] ) {
					$prime_classes .= ' prime-tile--' . $prime_tile['size'];
				}
				?>
				<figure class="<?php echo esc_attr( $prime_classes ); ?>">
					<a href="<?php echo esc_url( $prime_product->get_permalink() ); ?>">
						<?php
						echo wp_kses_post(
							$prime_product->get_image(
								'tall' === $prime_tile['size'] ? 'prime-tile-tall' : 'prime-tile',
								array( 'loading' => 'lazy' )
							)
						);
						?>

						<figcaption>
							<span class="prime-tile__name">
								<?php prime_mark(); ?>
								<?php echo esc_html( $prime_product->get_name() ); ?>
							</span>
							<span class="prime-tile__price">
								<?php
								$prime_price = $prime_product->get_price_html();

								echo $prime_price
									? wp_kses_post( $prime_price )
									: esc_html__( 'On request', 'prime-printing' );
								?>
							</span>
						</figcaption>
					</a>
				</figure>
			<?php endforeach; ?>
		</div>

		<?php if ( $prime_shop_id > 0 ) : ?>
			<p class="prime-wall__more">
				<a class="prime-btn prime-btn--navy" href="<?php echo esc_url( get_permalink( $prime_shop_id ) ); ?>">
					<?php prime_mark( 'white' ); ?>
					<span><?php esc_html_e( 'Browse the full catalogue', 'prime-printing' ); ?></span>
				</a>
			</p>
		<?php endif; ?>
	</div>
</section>
