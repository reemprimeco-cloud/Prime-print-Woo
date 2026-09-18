<?php
/**
 * The homepage.
 *
 * Section order follows prime-printing-theme-motion.html: hero, service ticker,
 * shuffling product wall, client marquee. Each part renders nothing when it has
 * no content, so a half-populated install degrades section by section instead
 * of showing empty frames.
 *
 * The configurator strip ("Type the size. Watch the price move.") was in the
 * reference but has been removed from the homepage per Reem's request — the
 * calculator belongs on the product page once Phase 4c wires it to real
 * per-product formulas, not duplicated here. The markup and CSS
 * (template-parts/home/configurator.php, .prime-configurator rules in
 * home.css) are left in place, unused, for that phase to lift from rather than
 * rebuild.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

get_header();

get_template_part( 'template-parts/home/hero' );
?>

<main id="prime-content" class="prime-main prime-main--home">
	<?php
	get_template_part( 'template-parts/home/ticker' );
	get_template_part( 'template-parts/home/wall' );
	get_template_part( 'template-parts/home/clients' );

	// Anything typed into the page assigned as the static front page still
	// renders, below the designed sections.
	if ( have_posts() ) :
		while ( have_posts() ) :
			the_post();

			if ( ! trim( get_the_content() ) ) {
				continue;
			}
			?>
			<div class="prime-wrap prime-home__content">
				<div class="prime-prose"><?php the_content(); ?></div>
			</div>
			<?php
		endwhile;
	endif;
	?>
</main>

<?php
get_footer();
