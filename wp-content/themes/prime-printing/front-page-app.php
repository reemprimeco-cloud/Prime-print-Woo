<?php
/**
 * The homepage, in app mode.
 *
 * Served instead of front-page.php when the request comes from the iOS app
 * (inc/native-app-ui.php, prime_app_template()). An app home is a launcher,
 * not a landing page: search, one banner, a category strip, and the popular
 * products — the same featured-first list the web wall uses, so Reem curates
 * both from the one "featured" flag.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

get_header();

$prime_has_shop  = prime_has_woocommerce() && wc_get_page_id( 'shop' ) > 0;
$prime_shop_url  = $prime_has_shop ? get_permalink( wc_get_page_id( 'shop' ) ) : home_url( '/' );
$prime_all_url   = $prime_has_shop ? add_query_arg( 'view', 'all', $prime_shop_url ) : home_url( '/' );
$prime_announce  = get_theme_mod( 'prime_announcement', __( 'Delivery within 48 hours across Kuwait', 'prime-printing' ) );
$prime_cats      = prime_product_categories( 10 );
$prime_popular   = function_exists( 'prime_wall_products' ) ? prime_wall_products( 6 ) : array();
?>

<main id="prime-content" class="prime-main prime-app-home">

	<form class="prime-app-search prime-app-home__search" role="search" method="get" action="<?php echo esc_url( $prime_shop_url ); ?>">
		<?php echo prime_app_icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG set. ?>
		<label class="screen-reader-text" for="prime-app-home-search"><?php esc_html_e( 'Search', 'prime-printing' ); ?></label>
		<input id="prime-app-home-search" type="search" name="s" placeholder="<?php esc_attr_e( 'Search products', 'prime-printing' ); ?>" enterkeyhint="search">
		<input type="hidden" name="post_type" value="product">
		<input type="hidden" name="view" value="all">
	</form>

	<?php if ( $prime_announce ) : ?>
		<a class="prime-app-banner" href="<?php echo esc_url( $prime_all_url ); ?>">
			<span class="prime-app-banner__eyebrow"><?php esc_html_e( 'Fast delivery', 'prime-printing' ); ?></span>
			<span class="prime-app-banner__title"><?php echo esc_html( $prime_announce ); ?></span>
			<span class="prime-app-banner__cta"><?php esc_html_e( 'Shop now', 'prime-printing' ); ?></span>
			<span class="prime-app-banner__art" aria-hidden="true"></span>
		</a>
	<?php endif; ?>

	<?php if ( $prime_cats ) : ?>
		<div class="prime-app-sec">
			<h2><?php esc_html_e( 'Categories', 'prime-printing' ); ?></h2>
			<a href="<?php echo esc_url( $prime_shop_url ); ?>"><?php esc_html_e( 'All', 'prime-printing' ); ?></a>
		</div>
		<div class="prime-app-cats">
			<?php foreach ( $prime_cats as $prime_term ) : ?>
				<?php
				$prime_thumb_id = (int) get_term_meta( $prime_term->term_id, 'thumbnail_id', true );
				$prime_icon_svg = function_exists( 'prime_category_icon_svg' ) ? prime_category_icon_svg( $prime_term->name, 'app-' . $prime_term->term_id ) : '';
				?>
				<a class="prime-app-cat" href="<?php echo esc_url( get_term_link( $prime_term ) ); ?>">
					<span class="prime-app-cat__icon">
						<?php
						if ( $prime_icon_svg ) {
							echo $prime_icon_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed hand-authored SVG set.
						} elseif ( $prime_thumb_id ) {
							echo wp_get_attachment_image( $prime_thumb_id, 'thumbnail', false, array( 'alt' => '', 'loading' => 'lazy' ) );
						} else {
							prime_mark();
						}
						?>
					</span>
					<span><?php echo esc_html( $prime_term->name ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php if ( $prime_popular ) : ?>
		<div class="prime-app-sec">
			<h2><?php esc_html_e( 'Popular', 'prime-printing' ); ?></h2>
			<a href="<?php echo esc_url( $prime_all_url ); ?>"><?php esc_html_e( 'See all', 'prime-printing' ); ?></a>
		</div>
		<div class="prime-grid prime-app-home__grid">
			<?php
			foreach ( $prime_popular as $prime_product ) {
				$GLOBALS['product'] = $prime_product;
				get_template_part( 'template-parts/shop/card' );
			}
			unset( $GLOBALS['product'] );
			?>
		</div>
	<?php endif; ?>

	<?php if ( $prime_has_shop ) : ?>
		<a class="prime-app-more" href="<?php echo esc_url( $prime_all_url ); ?>"><?php esc_html_e( 'Shop all products', 'prime-printing' ); ?></a>
	<?php endif; ?>

</main>

<?php
get_footer();
