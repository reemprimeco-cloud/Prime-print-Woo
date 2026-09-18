<?php
/**
 * App mode — the compact top bar.
 *
 * Home screen: wordmark + search. Every other screen: back, a short title,
 * and (on a product) a share button that assets/js/app.js unhides when the
 * WebView supports the Web Share API.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

$prime_is_home  = is_front_page() && ! is_paged();
$prime_shop_url = prime_has_woocommerce() && wc_get_page_id( 'shop' ) > 0
	? add_query_arg( 'view', 'all', get_permalink( wc_get_page_id( 'shop' ) ) )
	: home_url( '/' );
?>

<header class="prime-app-bar <?php echo $prime_is_home ? 'prime-app-bar--home' : 'prime-app-bar--inner'; ?>">
	<?php if ( $prime_is_home ) : ?>
		<?php prime_logo(); ?>
		<a class="prime-app-bar__btn" href="<?php echo esc_url( $prime_shop_url ); ?>" aria-label="<?php esc_attr_e( 'Search', 'prime-printing' ); ?>">
			<?php echo prime_app_icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG set. ?>
		</a>
	<?php else : ?>
		<button class="prime-app-bar__btn prime-app-bar__btn--back" type="button" data-prime-app-back aria-label="<?php esc_attr_e( 'Back', 'prime-printing' ); ?>">
			<?php echo prime_app_icon( 'back' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG set. ?>
		</button>

		<span class="prime-app-bar__title"><?php echo esc_html( prime_app_page_title() ); ?></span>

		<?php if ( prime_has_woocommerce() && is_product() ) : ?>
			<button class="prime-app-bar__btn" type="button" data-prime-app-share hidden aria-label="<?php esc_attr_e( 'Share', 'prime-printing' ); ?>">
				<?php echo prime_app_icon( 'share' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG set. ?>
			</button>
		<?php else : ?>
			<span class="prime-app-bar__btn" aria-hidden="true"></span>
		<?php endif; ?>
	<?php endif; ?>
</header>
