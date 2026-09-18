<?php
/**
 * Homepage data.
 *
 * Every list the homepage renders comes from here, and every one of them is
 * editable from wp-admin without touching code — that is the Phase 2
 * requirement, not a nicety. Nothing on this page is a hard-coded array.
 *
 *   services      Customizer list, falling back to the real product categories
 *   wall products WooCommerce query (featured first), shuffled server-side
 *   hero images   product imagery, so the hero tracks the catalogue
 *   client logos  a `client_logo` post type, managed like any other post
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the client logo post type.
 *
 * A post type rather than an ACF gallery field: the theme then carries its own
 * content model instead of depending on a plugin being installed, and each logo
 * gets a title to use as its alt text.
 */
function prime_register_client_logo() {
	register_post_type(
		'client_logo',
		array(
			'labels'          => array(
				'name'               => __( 'Client logos', 'prime-printing' ),
				'singular_name'      => __( 'Client logo', 'prime-printing' ),
				'add_new_item'       => __( 'Add client logo', 'prime-printing' ),
				'edit_item'          => __( 'Edit client logo', 'prime-printing' ),
				'not_found'          => __( 'No client logos yet.', 'prime-printing' ),
				'featured_image'     => __( 'Logo image', 'prime-printing' ),
				'set_featured_image' => __( 'Set logo image', 'prime-printing' ),
			),
			// Not publicly queryable: a logo is a homepage asset, not a page
			// anyone should be able to land on from search.
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => true,
			'show_in_rest'    => true,
			'menu_icon'       => 'dashicons-awards',
			'menu_position'   => 26,
			'supports'        => array( 'title', 'thumbnail', 'page-attributes' ),
			'has_archive'     => false,
			'rewrite'         => false,
			'capability_type' => 'post',
		)
	);
}
add_action( 'init', 'prime_register_client_logo' );

/**
 * The service labels for the ticker and the configurator pill row.
 *
 * Falls back to the store's real product categories when the Customizer list is
 * empty, so a fresh install shows something true rather than nothing — and so
 * the ticker stays correct on its own if nobody ever edits it.
 *
 * @return string[]
 */
function prime_services() {
	$manual = (string) get_theme_mod( 'prime_services', '' );

	if ( function_exists( 'pll__' ) && $manual ) {
		$manual = pll__( $manual );
	}

	$services = array_filter( array_map( 'trim', explode( "\n", $manual ) ) );

	if ( $services ) {
		return array_values( $services );
	}

	return array_map(
		static function ( $term ) {
			return $term->name;
		},
		prime_product_categories( 12 )
	);
}

/**
 * Products for the shuffling wall.
 *
 * Featured products first, then the rest of the catalogue by recency, so the
 * wall leads with whatever Reem has starred in WooCommerce.
 *
 * @param int $limit How many tiles.
 * @return WC_Product[]
 */
function prime_wall_products( $limit = 12 ) {
	if ( ! prime_has_woocommerce() ) {
		return array();
	}

	$limit = max( 1, (int) $limit );

	$featured = wc_get_products(
		array(
			'status'   => 'publish',
			'limit'    => $limit,
			'featured' => true,
			'orderby'  => 'menu_order',
			'order'    => 'ASC',
		)
	);

	$products = is_array( $featured ) ? $featured : array();

	if ( count( $products ) < $limit ) {
		// wp_list_pluck() reads properties, and WC_Product exposes its ID only
		// through a getter, so the exclusion list is built by hand.
		$seen = array_map(
			static function ( $product ) {
				return $product->get_id();
			},
			$products
		);

		$fill = wc_get_products(
			array(
				'status'  => 'publish',
				'limit'   => $limit - count( $products ),
				'exclude' => $seen,
				'orderby' => 'date',
				'order'   => 'DESC',
			)
		);

		if ( is_array( $fill ) ) {
			$products = array_merge( $products, $fill );
		}
	}

	// Only tiles that can actually carry an image; a grey box in the wall reads
	// as a loading failure.
	return array_values(
		array_filter(
			$products,
			static function ( $product ) {
				return $product instanceof WC_Product && $product->get_image_id();
			}
		)
	);
}

/**
 * The grid size for a tile at a given position in the catalogue order.
 *
 * The reference gives a handful of tiles a tall or wide span so the wall does
 * not read as a plain grid. Assignment is by catalogue position, not by shuffle
 * position, so a given product keeps its shape as the wall reshuffles — the
 * layout changes, the products do not change size under the visitor.
 *
 * @param int $index Position in catalogue order.
 * @return string '', 'tall' or 'wide'.
 */
function prime_tile_size( $index ) {
	$sizes = array(
		0 => 'tall',
		3 => 'wide',
		9 => 'wide',
	);

	return isset( $sizes[ $index ] ) ? $sizes[ $index ] : '';
}

/**
 * Image URLs for the drifting hero columns.
 *
 * Sourced from the catalogue so the hero always shows real work. Returns an
 * empty array when there is nothing to show, and the hero then renders as the
 * plain navy field rather than a broken grid.
 *
 * @param int $limit How many distinct images.
 * @return array[] Each item: array{ url: string }
 */
function prime_hero_images( $limit = 8 ) {
	if ( ! prime_has_woocommerce() ) {
		return array();
	}

	$products = wc_get_products(
		array(
			'status'  => 'publish',
			'limit'   => (int) $limit,
			'orderby' => 'rand',
		)
	);

	if ( ! is_array( $products ) ) {
		return array();
	}

	$images = array();

	foreach ( $products as $product ) {
		$image_id = $product->get_image_id();

		if ( ! $image_id ) {
			continue;
		}

		$src = wp_get_attachment_image_src( $image_id, 'prime-card' );

		if ( ! $src ) {
			continue;
		}

		$images[] = array( 'url' => $src[0] );
	}

	return $images;
}

/**
 * The client logos.
 *
 * @param int $limit Maximum logos.
 * @return WP_Post[]
 */
function prime_client_logos( $limit = 12 ) {
	$logos = get_posts(
		array(
			'post_type'      => 'client_logo',
			'post_status'    => 'publish',
			'posts_per_page' => (int) $limit,
			'orderby'        => array(
				'menu_order' => 'ASC',
				'date'       => 'DESC',
			),
		)
	);

	return array_values(
		array_filter(
			$logos,
			static function ( $logo ) {
				return has_post_thumbnail( $logo );
			}
		)
	);
}

/**
 * The product the homepage configurator points at.
 *
 * @return WC_Product|null
 */
function prime_configurator_product() {
	if ( ! prime_has_woocommerce() ) {
		return null;
	}

	$product_id = (int) get_theme_mod( 'prime_configurator_product', 0 );

	if ( $product_id <= 0 ) {
		return null;
	}

	$product = wc_get_product( $product_id );

	if ( ! $product || 'publish' !== $product->get_status() ) {
		return null;
	}

	return $product;
}

/**
 * Homepage responses vary per request because the wall is shuffled in PHP.
 *
 * Without this a page cache would freeze one shuffle for every visitor, which
 * looks like the shuffle is broken rather than cached. Declaring it lets the
 * hosting cache (and Cloudways' Varnish in Phase 11) treat the page correctly.
 */
function prime_homepage_cache_headers() {
	if ( ! is_front_page() || is_admin() ) {
		return;
	}

	// The markup is public and cacheable, but only briefly — long enough to
	// absorb a traffic spike, short enough that the wall still varies.
	header( 'Cache-Control: public, max-age=0, s-maxage=60' );
}
add_action( 'template_redirect', 'prime_homepage_cache_headers' );
