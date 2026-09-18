<?php
/**
 * Client logo marquee.
 *
 * Logos come from the `client_logo` post type — Reem adds and removes them in
 * wp-admin like any other post, ordering them with the page-attributes order
 * field. The section disappears when there are none.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

$prime_logos = prime_client_logos( 12 );

if ( ! $prime_logos ) {
	return;
}
?>

<section class="prime-clients">
	<p class="prime-label prime-clients__label">
		<?php prime_mark(); ?>
		<span><?php esc_html_e( 'Trusted by', 'prime-printing' ); ?></span>
	</p>

	<div class="prime-clients__track">
		<div class="prime-clients__row">
			<?php for ( $prime_pass = 0; $prime_pass < 2; $prime_pass++ ) : ?>
				<?php foreach ( $prime_logos as $prime_logo ) : ?>
					<?php
					/*
					 * The second pass is the seamless-loop duplicate, so it is
					 * hidden from assistive tech; the first pass carries the real
					 * client names as alt text.
					 */
					echo get_the_post_thumbnail(
						$prime_logo,
						'medium',
						array(
							'alt'         => 0 === $prime_pass ? esc_attr( get_the_title( $prime_logo ) ) : '',
							'aria-hidden' => 0 === $prime_pass ? 'false' : 'true',
							'loading'     => 'lazy',
						)
					);
					?>
				<?php endforeach; ?>
			<?php endfor; ?>
		</div>
	</div>
</section>
