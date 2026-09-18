<?php
/**
 * Service ticker.
 *
 * A marquee of everything the shop makes. The list comes from the Customizer,
 * or from the real product categories when that is empty — see prime_services().
 *
 * Like the hero columns, the row is rendered twice so the -50% keyframe loops
 * seamlessly.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

$prime_services = prime_services();

if ( ! $prime_services ) {
	return;
}
?>

<div class="prime-ticker">
	<?php
	/*
	 * The visible marquee is decorative — it duplicates links available in the
	 * nav and the wall below, and moving text is a poor reading surface — so it
	 * is hidden from assistive tech and the list is exposed once, statically,
	 * for screen readers.
	 */
	?>
	<h2 class="screen-reader-text"><?php esc_html_e( 'What we print', 'prime-printing' ); ?></h2>
	<ul class="screen-reader-text">
		<?php foreach ( $prime_services as $prime_service ) : ?>
			<li><?php echo esc_html( $prime_service ); ?></li>
		<?php endforeach; ?>
	</ul>

	<div class="prime-ticker__row" aria-hidden="true">
		<?php for ( $prime_pass = 0; $prime_pass < 2; $prime_pass++ ) : ?>
			<?php foreach ( $prime_services as $prime_service ) : ?>
				<span><?php echo esc_html( $prime_service ); ?></span>
				<?php prime_mark(); ?>
			<?php endforeach; ?>
		<?php endfor; ?>
	</div>
</div>
