<?php
/**
 * Fallback template.
 *
 * Required by WordPress. Real layouts live in front-page.php, page.php,
 * single.php and the WooCommerce overrides; this catches everything else
 * (blog index, archives, search) with the shared page-head + list treatment.
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
		<h1>
			<?php
			if ( is_search() ) {
				printf(
					/* translators: %s: search query. */
					esc_html__( 'Results for “%s”', 'prime-printing' ),
					esc_html( get_search_query() )
				);
			} elseif ( is_archive() ) {
				the_archive_title();
			} else {
				echo esc_html( get_the_title( get_option( 'page_for_posts' ) ) );
			}
			?>
		</h1>
	</div>
</header>

<main id="prime-content" class="prime-main">
	<div class="prime-wrap">
		<?php if ( have_posts() ) : ?>
			<?php
			while ( have_posts() ) :
				the_post();
				?>
				<article <?php post_class( 'prime-entry' ); ?>>
					<h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
					<?php the_excerpt(); ?>
				</article>
				<?php
			endwhile;

			the_posts_pagination(
				array(
					'mid_size'  => 1,
					'prev_text' => esc_html__( 'Previous', 'prime-printing' ),
					'next_text' => esc_html__( 'Next', 'prime-printing' ),
				)
			);
			?>
		<?php else : ?>
			<p><?php esc_html_e( 'Nothing found.', 'prime-printing' ); ?></p>
		<?php endif; ?>
	</div>
</main>

<?php
get_footer();
