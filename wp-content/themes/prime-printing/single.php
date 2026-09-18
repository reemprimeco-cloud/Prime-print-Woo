<?php
/**
 * A single post.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

get_header();

while ( have_posts() ) :
	the_post();
	?>

	<header class="prime-pagehead">
		<div class="prime-wrap">
			<div class="prime-crumb">
				<?php prime_mark(); ?>
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'prime-printing' ); ?></a>
			</div>
			<h1><?php the_title(); ?></h1>
		</div>
	</header>

	<main id="prime-content" class="prime-main">
		<div class="prime-wrap">
			<div class="prime-prose">
				<?php the_content(); ?>
			</div>
		</div>
	</main>

	<?php
endwhile;

get_footer();
