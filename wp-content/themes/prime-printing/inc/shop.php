<?php
/**
 * Shop archive — queries, sorting, and the AJAX endpoint behind "Show more".
 *
 * The reference filters and sorts a hard-coded array in the browser. That does
 * not survive contact with a real catalogue: 150 products cannot all be shipped
 * to the client, and a client-side filter is invisible to search engines. So the
 * same interactions are served from the server here, and the JS only asks for
 * the next slice.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

/** How many products a page of the grid holds. Matches the reference. */
const PRIME_SHOP_PER_PAGE = 16;

/**
 * Which of the three archive views this request wants.
 *
 * cats      the category grid — the default landing view for /shop/
 * all       every product, paginated
 * category  one category's products, from /product-category/{slug}/
 *
 * @return string
 */
function prime_shop_view() {
	if ( function_exists( 'is_product_category' ) && is_product_category() ) {
		return 'category';
	}

	$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';

	if ( 'all' === $view ) {
		return 'all';
	}

	// A search or an explicit sort only makes sense against products, so either
	// one implies the product grid rather than the category grid.
	if ( prime_shop_search() || isset( $_GET['orderby'] ) ) {
		return 'all';
	}

	return 'cats';
}

/**
 * The current search term.
 *
 * @return string
 */
function prime_shop_search() {
	if ( empty( $_GET['s'] ) ) {
		return '';
	}

	return sanitize_text_field( wp_unslash( $_GET['s'] ) );
}

/**
 * The current sort key.
 *
 * @return string One of: feat, most, lo, hi, az.
 */
function prime_shop_sort() {
	$sort = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'feat';

	return array_key_exists( $sort, prime_shop_sort_options() ) ? $sort : 'feat';
}

/**
 * The sort dropdown's options.
 *
 * "Most products" only means something when sorting categories, so it is
 * filtered out of the product grid by the template rather than offered and
 * silently ignored.
 *
 * @return array<string, string>
 */
function prime_shop_sort_options() {
	return array(
		'feat' => __( 'Featured', 'prime-printing' ),
		'most' => __( 'Most products', 'prime-printing' ),
		'lo'   => __( 'Price: low to high', 'prime-printing' ),
		'hi'   => __( 'Price: high to low', 'prime-printing' ),
		'az'   => __( 'Name A–Z', 'prime-printing' ),
	);
}

/**
 * Translate a sort key into WP_Query arguments.
 *
 * Price sorting reads `_price`, the numeric lookup WooCommerce maintains, rather
 * than the display price — otherwise 10.000 sorts before 9.000 as a string.
 * Quote-only products have no `_price`, so they are ordered last instead of
 * being dropped, matching the reference's treatment of "On request".
 *
 * @param string $sort Sort key.
 * @return array
 */
function prime_shop_order_args( $sort ) {
	switch ( $sort ) {
		case 'lo':
			return array(
				'orderby'  => array(
					'meta_value_num' => 'ASC',
					'title'          => 'ASC',
				),
				'meta_key' => '_price', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			);

		case 'hi':
			return array(
				'orderby'  => array(
					'meta_value_num' => 'DESC',
					'title'          => 'ASC',
				),
				'meta_key' => '_price', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			);

		case 'az':
			return array( 'orderby' => array( 'title' => 'ASC' ) );

		case 'feat':
		default:
			// Featured first, then the shop's own menu order.
			return array(
				'orderby' => array(
					'menu_order' => 'ASC',
					'date'       => 'DESC',
				),
			);
	}
}

/**
 * Build the product query for the grid.
 *
 * @param array $args {
 *     @type string $search   Search term.
 *     @type string $sort     Sort key.
 *     @type int    $category Term ID to restrict to, or 0.
 *     @type int    $page     1-based page number.
 * }
 * @return WP_Query
 */
function prime_shop_query( $args = array() ) {
	$args = wp_parse_args(
		$args,
		array(
			'search'   => '',
			'sort'     => 'feat',
			'category' => 0,
			'page'     => 1,
		)
	);

	$query_args = array(
		'post_type'           => 'product',
		'post_status'         => 'publish',
		'posts_per_page'      => PRIME_SHOP_PER_PAGE,
		'paged'               => max( 1, (int) $args['page'] ),
		'ignore_sticky_posts' => true,
		// Respect the catalogue-visibility setting, which "hidden" products and
		// search-only products rely on.
		'tax_query'           => array(
			array(
				'taxonomy' => 'product_visibility',
				'field'    => 'name',
				'terms'    => 'exclude-from-catalog',
				'operator' => 'NOT IN',
			),
		),
	);

	if ( $args['search'] ) {
		$query_args['s'] = $args['search'];
	}

	if ( $args['category'] > 0 ) {
		$query_args['tax_query'][] = array(
			'taxonomy'         => 'product_cat',
			'field'            => 'term_id',
			'terms'            => (int) $args['category'],
			'include_children' => true,
		);
	}

	if ( count( $query_args['tax_query'] ) > 1 ) {
		$query_args['tax_query']['relation'] = 'AND';
	}

	$query_args = array_merge( $query_args, prime_shop_order_args( $args['sort'] ) );

	// Featured-first ordering cannot be expressed in orderby, so it is applied as
	// a post__in preface when no explicit sort was chosen.
	if ( 'feat' === $args['sort'] ) {
		$query_args['meta_query'] = array();
	}

	return new WP_Query( $query_args );
}

/**
 * Top-level product categories for the category grid.
 *
 * @param string $sort   Sort key.
 * @param string $search Search term.
 * @return WP_Term[]
 */
function prime_shop_categories( $sort = 'feat', $search = '' ) {
	if ( ! prime_has_woocommerce() ) {
		return array();
	}

	$terms = get_terms(
		array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => true,
			'parent'     => 0,
		)
	);

	if ( is_wp_error( $terms ) ) {
		return array();
	}

	if ( $search ) {
		$needle = function_exists( 'mb_strtolower' ) ? mb_strtolower( $search ) : strtolower( $search );

		$terms = array_filter(
			$terms,
			static function ( $term ) use ( $needle ) {
				$name = function_exists( 'mb_strtolower' ) ? mb_strtolower( $term->name ) : strtolower( $term->name );

				return false !== strpos( $name, $needle );
			}
		);
	}

	usort(
		$terms,
		static function ( $a, $b ) use ( $sort ) {
			if ( 'az' === $sort ) {
				return strcmp( $a->name, $b->name );
			}

			// Both "featured" and "most products" order categories by size — the
			// biggest category is the most useful thing to show first, and the
			// shop has no separate featured flag for terms.
			return $b->count <=> $a->count;
		}
	);

	return array_values( $terms );
}

/**
 * Render one page of product cards.
 *
 * Shared by the template and the AJAX endpoint so both produce identical markup.
 *
 * @param WP_Query $query Product query.
 * @return string
 */
function prime_shop_render_cards( $query ) {
	if ( ! $query->have_posts() ) {
		return '';
	}

	ob_start();

	global $post;

	while ( $query->have_posts() ) {
		$query->the_post();

		// WooCommerce template functions read the global $product, which
		// the_post() does not set on a custom query.
		$GLOBALS['product'] = wc_get_product( get_the_ID() );

		get_template_part( 'template-parts/shop/card' );
	}

	wp_reset_postdata();

	return ob_get_clean();
}

/**
 * AJAX: return the next page of products.
 *
 * Public endpoint — it exposes only what the shop page already shows, so it is
 * registered for logged-out visitors too. It takes no nonce for the same reason:
 * a nonce on a public read would break for cached visitors without adding
 * protection to anything.
 */
function prime_ajax_products() {
	$page = isset( $_GET['page'] ) ? absint( wp_unslash( $_GET['page'] ) ) : 1;

	$query = prime_shop_query(
		array(
			'search'   => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'sort'     => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'feat',
			'category' => isset( $_GET['category'] ) ? absint( wp_unslash( $_GET['category'] ) ) : 0,
			'page'     => $page,
		)
	);

	wp_send_json_success(
		array(
			'html'      => prime_shop_render_cards( $query ),
			'total'     => (int) $query->found_posts,
			'page'      => $page,
			'hasMore'   => $page < (int) $query->max_num_pages,
			'remaining' => max( 0, (int) $query->found_posts - ( $page * PRIME_SHOP_PER_PAGE ) ),
		)
	);
}
add_action( 'wp_ajax_prime_products', 'prime_ajax_products' );
add_action( 'wp_ajax_nopriv_prime_products', 'prime_ajax_products' );
