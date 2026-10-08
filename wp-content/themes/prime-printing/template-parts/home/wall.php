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

/*
 * The reserve: products that are not on the wall yet. home.js swaps them into
 * random tiles, sliding the old photo out and the new one in.
 */
$prime_wall_count = (int) get_theme_mod( 'prime_wall_count', 12 );
$prime_reserve    = array();

foreach ( array_slice( prime_wall_products( $prime_wall_count * 2 ), $prime_wall_count ) as $prime_extra ) {
	if ( $prime_extra->get_image_id() ) {
		$prime_reserve[] = $prime_extra;
	}
}

/*
 * Fill the last row. A tall or wide tile covers two cells, so the wall's area
 * is rarely a multiple of the 4 columns and the final row ends with a hole.
 * Square tiles from the reserve are added until the area divides evenly (4
 * also divides the 2-column mobile layout).
 */
$prime_area = 0;

foreach ( $prime_tiles as $prime_tile ) {
	$prime_area += $prime_tile['size'] ? 2 : 1;
}

while ( $prime_reserve && 0 !== $prime_area % 4 ) {
	$prime_tiles[] = array(
		'product' => array_shift( $prime_reserve ),
		'size'    => '',
	);
	++$prime_area;
}

shuffle( $prime_tiles );

$prime_pool = array();

foreach ( $prime_reserve as $prime_extra ) {
	$prime_img_id = (int) $prime_extra->get_image_id();
	$prime_price  = $prime_extra->get_price_html();

	$prime_pool[] = array(
		'url'   => $prime_extra->get_permalink(),
		'name'  => $prime_extra->get_name(),
		'price' => $prime_price ? $prime_price : esc_html__( 'On request', 'prime-printing' ),
		'img'   => (string) wp_get_attachment_image_url( $prime_img_id, 'prime-tile' ),
		'tall'  => (string) wp_get_attachment_image_url( $prime_img_id, 'prime-tile-tall' ),
	);
}

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
		<div class="prime-wall" data-prime-wall<?php echo $prime_pool ? ' data-prime-pool="' . esc_attr( wp_json_encode( $prime_pool ) ) . '"' : ''; ?>>
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
