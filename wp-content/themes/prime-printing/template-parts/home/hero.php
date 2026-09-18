<?php
/**
 * Homepage hero.
 *
 * Four columns of catalogue imagery drifting in alternating directions behind a
 * radial veil, with the headline over the top. The drift is pure CSS — the
 * columns duplicate their contents so the keyframe can translate by exactly
 * -50% and loop seamlessly.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

$prime_images   = prime_hero_images( 8 );
$prime_heading  = get_theme_mod( 'prime_hero_heading', __( 'If it can be printed, we <em>already</em> print it.', 'prime-printing' ) );
$prime_eyebrow  = get_theme_mod( 'prime_hero_eyebrow', __( 'Creative printing boutique — Kuwait', 'prime-printing' ) );
$prime_text     = get_theme_mod( 'prime_hero_text', __( 'Stickers, stamps, calendars, packaging, backdrops, catalogs. Everything in one place — scroll and it finds you.', 'prime-printing' ) );
$prime_config   = prime_configurator_product();
$prime_shop_id  = prime_has_woocommerce() ? wc_get_page_id( 'shop' ) : 0;

if ( function_exists( 'pll__' ) ) {
	$prime_heading = $prime_heading ? pll__( $prime_heading ) : $prime_heading;
	$prime_eyebrow = $prime_eyebrow ? pll__( $prime_eyebrow ) : $prime_eyebrow;
	$prime_text    = $prime_text ? pll__( $prime_text ) : $prime_text;
}

// Four columns, each long enough to fill a tall viewport before it repeats.
$prime_columns = array( 'a', 'b', 'c', 'd' );
$prime_per_col = 5;
?>

<header class="prime-hero">
	<?php if ( count( $prime_images ) >= 4 ) : ?>
		<div class="prime-hero__streams" aria-hidden="true">
			<?php foreach ( $prime_columns as $prime_col_index => $prime_col ) : ?>
				<div class="prime-hero__col prime-hero__col--<?php echo esc_attr( $prime_col ); ?>">
					<?php
					/*
					 * Rendered twice. The keyframe moves the column up by half its
					 * own height, so the second copy is exactly what scrolls into
					 * the space the first one vacates — that is what makes the loop
					 * invisible. Changing one without the other breaks the seam.
					 */
					for ( $prime_pass = 0; $prime_pass < 2; $prime_pass++ ) :
						for ( $prime_row = 0; $prime_row < $prime_per_col; $prime_row++ ) :
							$prime_image = $prime_images[ ( $prime_col_index * 3 + $prime_row ) % count( $prime_images ) ];
							?>
							<img
								src="<?php echo esc_url( $prime_image['url'] ); ?>"
								alt=""
								loading="lazy"
								decoding="async"
							>
							<?php
						endfor;
					endfor;
					?>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<div class="prime-hero__veil"></div>

	<div class="prime-hero__text">
		<?php if ( $prime_eyebrow ) : ?>
			<p class="prime-eyebrow">
				<?php prime_mark(); ?>
				<span><?php echo esc_html( $prime_eyebrow ); ?></span>
			</p>
		<?php endif; ?>

		<h1>
			<?php
			echo wp_kses(
				$prime_heading,
				array(
					'em'     => array(),
					'br'     => array(),
					'strong' => array(),
				)
			);
			?>
		</h1>

		<?php if ( $prime_text ) : ?>
			<p class="prime-hero__lede"><?php echo esc_html( $prime_text ); ?></p>
		<?php endif; ?>

		<div class="prime-hero__actions">
			<?php if ( $prime_shop_id > 0 ) : ?>
				<a class="prime-btn" href="<?php echo esc_url( get_permalink( $prime_shop_id ) ); ?>">
					<?php prime_mark( 'navy' ); ?>
					<span><?php esc_html_e( 'See everything', 'prime-printing' ); ?></span>
				</a>
			<?php endif; ?>

			<?php if ( $prime_config ) : ?>
				<a class="prime-btn prime-btn--ghost" href="#prime-configurator">
					<?php esc_html_e( 'Price my sticker', 'prime-printing' ); ?>
				</a>
			<?php endif; ?>
		</div>
	</div>

	<div class="prime-hero__cue" aria-hidden="true">
		<span><?php esc_html_e( 'Scroll', 'prime-printing' ); ?></span>
		<i></i>
	</div>
</header>
