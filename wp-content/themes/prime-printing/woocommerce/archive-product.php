<?php
/**
 * Shop archive — categories view / all-products view.
 *
 * Overrides WooCommerce's own archive-product.php. Matches
 * prime-printing-shop-v3.html: a dark page head, a Categories/All-products
 * toggle, search + sort, and (on the all-products view) a "Show more" AJAX
 * pager instead of numbered pagination or rendering the whole catalogue.
 *
 * The reference builds this client-side against a hard-coded array. That does
 * not survive a real catalogue — 150 products cannot ship to the browser, and a
 * client-only filter is invisible to search engines — so the same interactions
 * are served from the server (inc/shop.php) and the JS only asks for more.
 *
 * @package PrimePrinting
 * @version 9.4.0
 */

defined( 'ABSPATH' ) || exit;

get_header();

$prime_app        = function_exists( 'prime_is_native_app_request' ) && prime_is_native_app_request();
$prime_view       = prime_shop_view();
$prime_search     = prime_shop_search();

// App mode: category chips (template-parts/app/shop-head.php) replace the
// category grid, so the shop always opens straight on products.
if ( $prime_app && 'cats' === $prime_view ) {
	$prime_view = 'all';
}

$prime_sort       = prime_shop_sort();
$prime_current    = is_product_category() ? get_queried_object() : null;
$prime_categories = ( 'cats' === $prime_view ) ? prime_shop_categories( $prime_sort, $prime_search ) : array();
$prime_query      = ( 'cats' === $prime_view ) ? null : prime_shop_query(
	array(
		'search'   => $prime_search,
		'sort'     => $prime_sort,
		'category' => $prime_current ? $prime_current->term_id : 0,
	)
);
?>

<?php
if ( $prime_app ) {
	get_template_part(
		'template-parts/app/shop-head',
		null,
		array(
			'current' => $prime_current,
			'search'  => $prime_search,
			'count'   => $prime_query ? (int) $prime_query->found_posts : 0,
		)
	);
}
?>

<header class="prime-pagehead">
	<div class="prime-wrap">
		<div class="prime-crumb">
			<?php prime_mark(); ?>
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'prime-printing' ); ?></a>
			<span aria-hidden="true">/</span>
			<?php if ( $prime_current ) : ?>
				<a href="<?php echo esc_url( get_permalink( wc_get_page_id( 'shop' ) ) ); ?>"><?php esc_html_e( 'Shop', 'prime-printing' ); ?></a>
				<span aria-hidden="true">/</span>
				<span><?php echo esc_html( $prime_current->name ); ?></span>
			<?php else : ?>
				<span><?php esc_html_e( 'Shop', 'prime-printing' ); ?></span>
			<?php endif; ?>
		</div>

		<h1>
			<?php
			if ( $prime_current ) {
				echo esc_html( $prime_current->name );
			} elseif ( $prime_search ) {
				printf(
					/* translators: %s: search term. */
					esc_html__( 'Results for “%s”', 'prime-printing' ),
					esc_html( $prime_search )
				);
			} else {
				esc_html_e( 'Shop', 'prime-printing' );
			}
			?>
		</h1>

		<?php if ( $prime_current && $prime_current->description ) : ?>
			<p><?php echo wp_kses_post( wpautop( $prime_current->description ) ); ?></p>
		<?php elseif ( ! $prime_current ) : ?>
			<p><?php esc_html_e( 'Everything we print, in one place.', 'prime-printing' ); ?></p>
		<?php endif; ?>
	</div>
</header>

<div
	class="prime-bar"
	data-prime-shop
	data-shop-url="<?php echo esc_url( get_permalink( wc_get_page_id( 'shop' ) ) ); ?>"
	data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
	data-category="<?php echo esc_attr( $prime_current ? $prime_current->term_id : 0 ); ?>"
	data-per-page="<?php echo esc_attr( PRIME_SHOP_PER_PAGE ); ?>"
>
	<div class="prime-wrap">
		<?php if ( ! $prime_current ) : ?>
			<div class="prime-toggle" role="tablist">
				<button
					type="button"
					role="tab"
					class="<?php echo 'cats' === $prime_view ? 'is-on' : ''; ?>"
					aria-selected="<?php echo 'cats' === $prime_view ? 'true' : 'false'; ?>"
					data-prime-view="cats"
				>
					<?php prime_mark(); ?>
					<span><?php esc_html_e( 'Categories', 'prime-printing' ); ?></span>
				</button>
				<button
					type="button"
					role="tab"
					class="<?php echo 'cats' !== $prime_view ? 'is-on' : ''; ?>"
					aria-selected="<?php echo 'cats' !== $prime_view ? 'true' : 'false'; ?>"
					data-prime-view="all"
				>
					<?php prime_mark(); ?>
					<span><?php esc_html_e( 'All products', 'prime-printing' ); ?></span>
				</button>
			</div>
		<?php endif; ?>

		<div class="prime-ctrls">
			<div class="prime-density">
				<button type="button" class="is-on" data-prime-density="two" aria-label="<?php esc_attr_e( 'Two columns', 'prime-printing' ); ?>">▦</button>
				<button type="button" data-prime-density="one" aria-label="<?php esc_attr_e( 'One column', 'prime-printing' ); ?>">▤</button>
			</div>

			<form role="search" method="get" action="<?php echo esc_url( $prime_current ? get_term_link( $prime_current ) : get_permalink( wc_get_page_id( 'shop' ) ) ); ?>">
				<label class="screen-reader-text" for="prime-shop-search"><?php esc_html_e( 'Search', 'prime-printing' ); ?></label>
				<input
					id="prime-shop-search"
					type="search"
					name="s"
					value="<?php echo esc_attr( $prime_search ); ?>"
					placeholder="<?php esc_attr_e( 'Search', 'prime-printing' ); ?>"
					data-prime-search
				>
				<input type="hidden" name="post_type" value="product">
				<?php if ( 'all' === $prime_view && ! $prime_current ) : ?>
					<input type="hidden" name="view" value="all">
				<?php endif; ?>
			</form>

			<label class="screen-reader-text" for="prime-shop-sort"><?php esc_html_e( 'Sort', 'prime-printing' ); ?></label>
			<select id="prime-shop-sort" data-prime-sort>
				<?php foreach ( prime_shop_sort_options() as $prime_key => $prime_label ) : ?>
					<?php
					// "Most products" sorts categories; it has nothing to do once a
					// category or the product grid is showing.
					if ( 'most' === $prime_key && ( 'cats' !== $prime_view || $prime_current ) ) {
						continue;
					}
					?>
					<option value="<?php echo esc_attr( $prime_key ); ?>" <?php selected( $prime_sort, $prime_key ); ?>>
						<?php echo esc_html( $prime_label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>
	</div>
</div>

<div class="prime-wrap">
	<p class="prime-count">
		<?php if ( $prime_categories ) : ?>
			<?php
			printf(
				/* translators: %s: number of categories. */
				esc_html( _n( '%s category', '%s categories', count( $prime_categories ), 'prime-printing' ) ),
				esc_html( number_format_i18n( count( $prime_categories ) ) )
			);
			?>
		<?php elseif ( $prime_query ) : ?>
			<?php
			printf(
				/* translators: %s: number of products. */
				esc_html( _n( '%s product', '%s products', $prime_query->found_posts, 'prime-printing' ) ),
				esc_html( number_format_i18n( $prime_query->found_posts ) )
			);
			?>

			<?php if ( $prime_current ) : ?>
				<a class="prime-back" href="<?php echo esc_url( get_permalink( wc_get_page_id( 'shop' ) ) ); ?>">
					<?php prime_mark(); ?>
					<span><?php esc_html_e( 'All categories', 'prime-printing' ); ?></span>
				</a>
			<?php endif; ?>
		<?php endif; ?>
	</p>

	<?php if ( $prime_categories ) : ?>
		<div class="prime-cats">
			<?php
			foreach ( $prime_categories as $prime_term ) {
				get_template_part( 'template-parts/shop/category-card', null, array( 'term' => $prime_term ) );
			}
			?>
		</div>
	<?php elseif ( $prime_query && $prime_query->have_posts() ) : ?>
		<div class="prime-grid" data-prime-grid>
			<?php echo prime_shop_render_cards( $prime_query ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- card.php escapes its own output. ?>
		</div>

		<?php if ( $prime_query->max_num_pages > 1 ) : ?>
			<button class="prime-more" type="button" data-prime-more data-page="1">
				<?php
				printf(
					/* translators: %s: number of remaining products. */
					esc_html__( 'Show more (%s)', 'prime-printing' ),
					esc_html( number_format_i18n( $prime_query->found_posts - PRIME_SHOP_PER_PAGE ) )
				);
				?>
			</button>
		<?php endif; ?>
	<?php else : ?>
		<div class="prime-empty">
			<h3><?php esc_html_e( 'No matches', 'prime-printing' ); ?></h3>
			<p><?php esc_html_e( 'Try a different word, or browse all categories.', 'prime-printing' ); ?></p>
		</div>
	<?php endif; ?>
</div>

<?php
get_footer();
