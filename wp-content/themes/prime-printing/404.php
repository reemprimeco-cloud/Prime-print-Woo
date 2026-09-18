<?php
/**
 * Not found.
 *
 * Phase 12 checks that migrated URLs redirect rather than land here; this is the
 * page for the ones that legitimately do not exist.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<header class="prime-pagehead">
	<div class="prime-wrap">
		<div class="prime-crumb">
			<?php prime_mark(); ?>
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'prime-printing' ); ?></a>
		</div>
		<h1><?php esc_html_e( 'Page not found', 'prime-printing' ); ?></h1>
		<p><?php esc_html_e( 'That page has moved or never existed. Try the shop, or search for what you need.', 'prime-printing' ); ?></p>
	</div>
</header>

<main id="prime-content" class="prime-main">
	<div class="prime-wrap" style="padding-block: var(--sp-10) var(--sp-12)">
		<?php get_search_form(); ?>

		<?php if ( prime_has_woocommerce() && wc_get_page_id( 'shop' ) > 0 ) : ?>
			<p style="margin-block-start: var(--sp-6)">
				<a class="prime-btn prime-btn--navy" href="<?php echo esc_url( get_permalink( wc_get_page_id( 'shop' ) ) ); ?>">
					<?php prime_mark( 'white' ); ?>
					<span><?php esc_html_e( 'Go to the shop', 'prime-printing' ); ?></span>
				</a>
			</p>
		<?php endif; ?>
	</div>
</main>

<?php
get_footer();
