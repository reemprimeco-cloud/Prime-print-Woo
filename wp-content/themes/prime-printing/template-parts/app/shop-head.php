<?php
/**
 * App mode — the shop screen's head: title + count, search, category chips.
 *
 * Replaces the web archive's dark page head, Categories/All toggle, density
 * and sort controls (all hidden by app.css). The chips are the top-level
 * categories; the current one (or the current sub-category's parent) is lit.
 *
 * @package PrimePrinting
 *
 * @var array $args {
 *     @type WP_Term|null $current Current category, if any.
 *     @type string       $search  Current search term.
 *     @type int          $count   Number of products in the grid.
 * }
 */

defined( 'ABSPATH' ) || exit;

$prime_current = isset( $args['current'] ) ? $args['current'] : null;
$prime_search  = isset( $args['search'] ) ? (string) $args['search'] : '';
$prime_count   = isset( $args['count'] ) ? (int) $args['count'] : 0;
$prime_shop    = get_permalink( wc_get_page_id( 'shop' ) );
$prime_all_url = add_query_arg( 'view', 'all', $prime_shop );

// Light the chip for the current category, or its top-level ancestor.
$prime_lit = 0;
if ( $prime_current instanceof WP_Term ) {
	$prime_lit       = $prime_current->term_id;
	$prime_ancestors = get_ancestors( $prime_current->term_id, 'product_cat', 'taxonomy' );
	if ( $prime_ancestors ) {
		$prime_lit = (int) end( $prime_ancestors );
	}
}

if ( $prime_current instanceof WP_Term ) {
	$prime_title = $prime_current->name;
} elseif ( $prime_search ) {
	/* translators: %s: search term. */
	$prime_title = sprintf( __( 'Results for “%s”', 'prime-printing' ), $prime_search );
} else {
	$prime_title = __( 'Shop', 'prime-printing' );
}
?>

<div class="prime-wrap prime-app-shophead">
	<div class="prime-app-shophead__row">
		<h1><?php echo esc_html( $prime_title ); ?></h1>
		<span class="prime-app-shophead__count">
			<?php
			printf(
				/* translators: %s: number of products. */
				esc_html( _n( '%s product', '%s products', $prime_count, 'prime-printing' ) ),
				esc_html( number_format_i18n( $prime_count ) )
			);
			?>
		</span>
	</div>

	<form class="prime-app-search" role="search" method="get" action="<?php echo esc_url( $prime_current instanceof WP_Term ? get_term_link( $prime_current ) : $prime_shop ); ?>">
		<?php echo prime_app_icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG set. ?>
		<label class="screen-reader-text" for="prime-app-search"><?php esc_html_e( 'Search', 'prime-printing' ); ?></label>
		<input
			id="prime-app-search"
			type="search"
			name="s"
			value="<?php echo esc_attr( $prime_search ); ?>"
			placeholder="<?php esc_attr_e( 'Search products', 'prime-printing' ); ?>"
			enterkeyhint="search"
		>
		<input type="hidden" name="post_type" value="product">
		<?php if ( ! $prime_current instanceof WP_Term ) : ?>
			<input type="hidden" name="view" value="all">
		<?php endif; ?>
	</form>

	<div class="prime-app-chips">
		<a class="prime-app-chip <?php echo ( ! $prime_lit && ! $prime_search ) ? 'is-on' : ''; ?>" href="<?php echo esc_url( $prime_all_url ); ?>">
			<?php esc_html_e( 'All', 'prime-printing' ); ?>
		</a>
		<?php foreach ( prime_shop_categories( 'feat' ) as $prime_term ) : ?>
			<a class="prime-app-chip <?php echo $prime_lit === (int) $prime_term->term_id ? 'is-on' : ''; ?>" href="<?php echo esc_url( get_term_link( $prime_term ) ); ?>">
				<?php echo esc_html( $prime_term->name ); ?>
			</a>
		<?php endforeach; ?>
	</div>
</div>
