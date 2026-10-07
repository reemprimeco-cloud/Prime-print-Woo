<?php
/**
 * A category tile in the shop's category grid.
 *
 * @package PrimePrinting
 *
 * @var WP_Term $args['term']
 */

defined( 'ABSPATH' ) || exit;

$prime_term = isset( $args['term'] ) ? $args['term'] : null;

if ( ! $prime_term instanceof WP_Term ) {
	return;
}

$prime_thumb_id = (int) get_term_meta( $prime_term->term_id, 'thumbnail_id', true );
$prime_icon_svg = prime_category_icon_svg( $prime_term->name, $prime_term->term_id );

// A photo dropped in assets/img/categories/<slug>.jpg wins over the icon.
$prime_photo_rel = 'assets/img/categories/' . $prime_term->slug . '.jpg';
$prime_photo     = file_exists( get_theme_file_path( $prime_photo_rel ) ) ? get_theme_file_uri( $prime_photo_rel ) : '';
?>

<a class="prime-cat" href="<?php echo esc_url( get_term_link( $prime_term ) ); ?>">
	<span class="prime-cat__icon">
		<?php
		if ( $prime_photo ) {
			printf(
				'<img class="prime-cat__photo" src="%s" alt="%s" loading="lazy" decoding="async">',
				esc_url( $prime_photo ),
				esc_attr( $prime_term->name )
			);
		} elseif ( $prime_icon_svg ) {
			echo $prime_icon_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from a fixed set of hand-authored SVG templates, no user input.
		} elseif ( $prime_thumb_id ) {
			echo wp_get_attachment_image(
				$prime_thumb_id,
				'prime-cat',
				false,
				array(
					'loading' => 'lazy',
					'alt'     => '',
				)
			);
		}
		?>
		<span class="prime-cat__corner" aria-hidden="true"></span>
	</span>

	<span class="prime-cat__body">
		<span class="prime-cat__name">
			<?php prime_mark(); ?>
			<?php echo esc_html( $prime_term->name ); ?>
		</span>
		<span class="prime-cat__count">
			<?php
			printf(
				/* translators: %s: number of products in the category. */
				esc_html( _n( '%s product', '%s products', $prime_term->count, 'prime-printing' ) ),
				esc_html( number_format_i18n( $prime_term->count ) )
			);
			?>
		</span>
	</span>
</a>
