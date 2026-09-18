<?php
/**
 * Plugin Name: Prime Printing — development seed
 * Description: Populates a fresh install with the real category structure and a
 *              representative catalogue so the theme can be built and reviewed
 *              against real WooCommerce data. DEVELOPMENT ONLY.
 *
 * This is not part of the theme and does not ship. Phase 10 replaces it with the
 * real CSV export from the live site. It exists so Phases 2–8 are built against
 * genuine WooCommerce objects — products, terms, attachments — rather than
 * hard-coded fixtures that hide integration bugs until migration day.
 *
 * Every product below is paired with a photograph that actually depicts it.
 * Products with no truthful image available are created without one; the theme
 * filters imageless products out of the homepage wall by design.
 *
 * @package PrimePrinting\Dev
 */

defined( 'ABSPATH' ) || exit;

const PRIME_SEED_VERSION = 7;
const PRIME_SEED_CDN     = 'https://i0.wp.com/primeprint.com.kw/wp-content/uploads/';

/**
 * The 18 real product categories from the live site, in the live site's order.
 *
 * @return array<string, array{name: string, count: int, image: string}>
 */
function prime_seed_categories() {
	return array(
		'occasions'           => array( 'name' => 'Occasions', 'image' => 'vellum' ),
		'stationery-printing' => array( 'name' => 'Stationery Printing', 'image' => 'booklet' ),
		'party-theme'         => array( 'name' => 'Party theme', 'image' => 'backdrop' ),
		'gifts-printing'      => array( 'name' => 'Gifts Printing', 'image' => 'poster' ),
		'calendars'           => array( 'name' => 'Calendars', 'image' => 'calendar' ),
		'stickers'            => array( 'name' => 'Stickers', 'image' => '' ),
		'notebooks'           => array( 'name' => 'Notebooks', 'image' => 'booklet' ),
		'luxury-box'          => array( 'name' => 'Luxury Hardboard Box', 'image' => 'branding' ),
		'teacher-supplies'    => array( 'name' => 'Teacher Supplies', 'image' => 'branding' ),
		'gift-wrapping'       => array( 'name' => 'Gift wrapping', 'image' => 'vellum' ),
		'bags-printing'       => array( 'name' => 'Bags Printing', 'image' => 'branding' ),
		'packaging'           => array( 'name' => 'Packaging', 'image' => 'branding' ),
		'sign-printing'       => array( 'name' => 'Sign Printing', 'image' => 'backdrop' ),
		'desk-set'            => array( 'name' => 'Desk Set', 'image' => 'branding' ),
		'digital-download'    => array( 'name' => 'Digital Download', 'image' => 'calendar' ),
		'stamps'              => array( 'name' => 'Stamps', 'image' => 'stamp' ),
		'vinyls'              => array( 'name' => 'Vinyls', 'image' => '' ),
		'silkscreen'          => array( 'name' => 'SilkScreen', 'image' => 'backdrop' ),
	);
}

/**
 * Source photographs, keyed by what they actually show.
 *
 * These are Prime Printing's own product photographs, pulled from the live
 * site's media library.
 *
 * @return array<string, array{url: string, title: string}>
 */
function prime_seed_images() {
	return array(
		'stamp'    => array(
			'url'   => PRIME_SEED_CDN . '2023/07/il_fullxfull.813564356_lia9.jpg.webp?resize=754%2C698&ssl=1',
			'title' => 'Custom rubber stamp on a kraft envelope',
		),
		'calendar' => array(
			'url'   => PRIME_SEED_CDN . '2026/01/unnamed.jpg?fit=468%2C400&ssl=1',
			'title' => 'Standing desk calendar with a stationery jar',
		),
		'booklet'  => array(
			'url'   => PRIME_SEED_CDN . '2023/12/booklet.jpg?resize=1020%2C680&ssl=1',
			'title' => 'Bound portfolio booklet, open on a spread',
		),
		'vellum'   => array(
			'url'   => PRIME_SEED_CDN . '2020/07/vellum.jpeg?fit=564%2C564&ssl=1',
			'title' => 'Vellum wedding invitation wrap with a wooden spool',
		),
		'backdrop' => array(
			'url'   => PRIME_SEED_CDN . '2024/02/WhatsApp-Image-2024-02-07-at-16.24.51.jpeg?fit=1020%2C574&ssl=1',
			'title' => 'Printed Ramadan entrance backdrop',
		),
		'poster'   => array(
			'url'   => PRIME_SEED_CDN . '2021/11/5386641-copy.jpg?fit=1020%2C612&ssl=1',
			'title' => 'Framed retail display poster',
		),
		'branding' => array(
			'url'   => PRIME_SEED_CDN . '2020/02/IMG_4269.jpg?fit=960%2C540&ssl=1',
			'title' => 'Branded packaging, cups and stationery set',
		),
	);
}

/**
 * The catalogue.
 *
 * `image` names a key from prime_seed_images() and must genuinely depict the
 * product. Each photograph is used by exactly one product — sharing one across
 * two products guarantees at least one of them is captioned with something it
 * does not show, which is precisely the mislabelling this avoids.
 *
 * An empty `image` means no truthful photograph exists for that product yet. It
 * is still created as a real product; it simply does not appear in the homepage
 * wall, which filters imageless products out by design. Phase 10 fills these in
 * from the live site's real media library.
 *
 * `price` empty means the product is quote-only ("On request").
 *
 * @return array[]
 */
function prime_seed_products() {
	return array(
		array(
			'name'     => 'Custom Rubber Stamp',
			'cat'      => 'stamps',
			'image'    => 'stamp',
			'price'    => '6.000',
			'featured' => true,
			'desc'     => 'A self-inking rubber stamp cut from your own artwork. Supply a logo or a monogram and we mount it ready to use.',
		),
		array(
			'name'     => 'Desk Calendar',
			'cat'      => 'calendars',
			'image'    => 'calendar',
			'price'    => '4.500',
			'featured' => true,
			'desc'     => 'A standing twelve-month desk calendar, printed on uncoated board with a wire binding.',
		),
		array(
			'name'  => 'Portfolio Booklet',
			'cat'   => 'notebooks',
			'image' => 'booklet',
			'price' => '3.000',
			'desc'  => 'A perfect-bound booklet for portfolios, catalogues and lookbooks. Priced per copy at the quantities shown.',
		),
		array(
			'name'     => 'Vellum Invitation Wrap',
			'cat'      => 'occasions',
			'image'    => 'vellum',
			'price'    => '2.000',
			'featured' => true,
			'desc'     => 'A translucent vellum wrap printed with your wording, finished with a wound spool tie.',
		),
		array(
			// Shares its subject with the invitation wrap above, so it ships
			// without a photograph rather than reusing that one under a
			// different name.
			'name'  => 'Gift Wrapping Vellum Sheets',
			'cat'   => 'gift-wrapping',
			'image' => '',
			'price' => '1.750',
			'desc'  => 'Printed vellum sheets sold in packs, for wrapping gifts and dressing boxes.',
		),
		array(
			'name'  => 'Event Backdrop',
			'cat'   => 'party-theme',
			'image' => 'backdrop',
			'price' => '',
			'desc'  => 'A free-standing printed backdrop for entrances, majlis settings and photo corners. Priced on the finished size.',
		),
		array(
			'name'  => 'Printed Sign Board',
			'cat'   => 'sign-printing',
			'image' => '',
			'price' => '',
			'desc'  => 'Rigid printed signage for shopfronts, events and wayfinding. Priced on size and material.',
		),
		array(
			'name'     => 'Framed Display Poster',
			'cat'      => 'gifts-printing',
			'image'    => 'poster',
			'price'    => '',
			'desc'     => 'A large-format poster print, supplied framed and ready to hang for retail and interior display.',
		),
		array(
			'name'     => 'Branded Packaging Set',
			'cat'      => 'packaging',
			'image'    => 'branding',
			'price'    => '',
			'featured' => true,
			'desc'     => 'Boxes, cups, cartons and sleeves printed as one matched set from your brand assets.',
		),
		array(
			'name'  => 'Desk Stationery Set',
			'cat'   => 'desk-set',
			'image' => '',
			'price' => '5.000',
			'desc'  => 'A matched desk set — notepad, clipboard, folder and cards — printed in your brand colours.',
		),
		array(
			'name'  => 'Stationery Notepad',
			'cat'   => 'stationery-printing',
			'image' => '',
			'price' => '3.500',
			'desc'  => 'Glue-bound notepads printed on uncoated stock, sold per pad.',
		),
		array(
			// No photograph in the library truthfully shows a UV DTF sticker, so
			// this one ships without an image rather than borrowing another
			// product's. It is the configurator target for Phase 4c.
			'name'         => 'UV DTF Stickers',
			'cat'          => 'stickers',
			'image'        => '',
			'price'        => '2.250',
			'configurator' => true,
			'desc'         => 'Transfer stickers cut to any size you need. Enter your dimensions and quantity and the price is calculated from the printed area.',
		),
	);
}

/**
 * Client logos for the homepage marquee.
 *
 * @return array<string, string> Title => URL.
 */
function prime_seed_clients() {
	return array(
		'Al-Futtaim Group' => PRIME_SEED_CDN . '2026/01/al-futtaim-group-seeklogo.png?w=1020&ssl=1',
		'Lush'             => PRIME_SEED_CDN . '2026/01/lush-seeklogo.png?fit=2000%2C345&ssl=1',
		'Maje'             => PRIME_SEED_CDN . '2023/12/maje-logo-E6E9A5DCF7-seeklogo.com_.png?w=1020&ssl=1',
		'Sandro'           => PRIME_SEED_CDN . '2023/12/Sandro-logo.jpg?fit=5634%2C1090&ssl=1',
		'Devon'            => PRIME_SEED_CDN . '2026/01/Devon-logo.png?w=1020&ssl=1',
		'Coded'            => PRIME_SEED_CDN . '2026/01/Coded-Logo.png?fit=2126%2C945&ssl=1',
		'Pick'             => PRIME_SEED_CDN . '2026/01/pick-seeklogo.png?w=1020&ssl=1',
		'Gifted'           => PRIME_SEED_CDN . '2023/12/Gifted-Logo.png?fit=2717%2C709&ssl=1',
	);
}

/**
 * Sideload one image into the media library, reusing it if already imported.
 *
 * @param string $url   Source URL.
 * @param string $title Attachment title, used as alt text.
 * @return int Attachment ID, or 0 on failure.
 */
function prime_seed_attachment( $url, $title ) {
	$existing = get_posts(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => '_prime_seed_source',
			'meta_value'     => $url,
		)
	);

	if ( $existing ) {
		return (int) $existing[0];
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$id = media_sideload_image( $url, 0, $title, 'id' );

	if ( is_wp_error( $id ) ) {
		error_log( 'Prime seed: could not sideload ' . $url . ' — ' . $id->get_error_message() );
		return 0;
	}

	update_post_meta( $id, '_prime_seed_source', $url );
	update_post_meta( $id, '_wp_attachment_image_alt', $title );

	return (int) $id;
}

/**
 * Find a post by exact title, scoped to a post type — the non-deprecated
 * replacement for get_page_by_title(), which WordPress 6.2 deprecated.
 *
 * @param string $title     Exact post title.
 * @param string $post_type Post type.
 * @return int Post ID, or 0 if not found.
 */
function prime_seed_find_by_title( $title, $post_type ) {
	$found = get_posts(
		array(
			'post_type'      => $post_type,
			'post_status'    => 'any',
			'title'          => $title,
			'posts_per_page' => 1,
			'fields'         => 'ids',
		)
	);

	return $found ? (int) $found[0] : 0;
}

/**
 * Run the seed once per version bump.
 */
function prime_seed_run() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	if ( (int) get_option( 'prime_seed_version', 0 ) >= PRIME_SEED_VERSION ) {
		return;
	}

	// Sideloading is slow; make sure a partial run cannot loop.
	update_option( 'prime_seed_version', PRIME_SEED_VERSION );

	$images     = prime_seed_images();
	$attachments = array();

	foreach ( $images as $key => $image ) {
		$attachments[ $key ] = prime_seed_attachment( $image['url'], $image['title'] );
	}

	// ---- Categories -------------------------------------------------------
	$term_ids = array();

	foreach ( prime_seed_categories() as $slug => $category ) {
		$term = get_term_by( 'slug', $slug, 'product_cat' );

		if ( ! $term ) {
			$created = wp_insert_term( $category['name'], 'product_cat', array( 'slug' => $slug ) );

			if ( is_wp_error( $created ) ) {
				continue;
			}

			$term_ids[ $slug ] = (int) $created['term_id'];
		} else {
			$term_ids[ $slug ] = (int) $term->term_id;
		}

		if ( $category['image'] && ! empty( $attachments[ $category['image'] ] ) ) {
			update_term_meta( $term_ids[ $slug ], 'thumbnail_id', $attachments[ $category['image'] ] );
		}
	}

	// ---- Products ---------------------------------------------------------
	foreach ( prime_seed_products() as $spec ) {
		if ( get_page_by_path( sanitize_title( $spec['name'] ), OBJECT, 'product' ) ) {
			continue;
		}

		$product = new WC_Product_Simple();
		$product->set_name( $spec['name'] );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'visible' );
		$product->set_description( $spec['desc'] );
		$product->set_short_description( $spec['desc'] );
		$product->set_featured( ! empty( $spec['featured'] ) );

		if ( $spec['price'] ) {
			$product->set_regular_price( $spec['price'] );
			$product->set_price( $spec['price'] );
		}

		if ( isset( $term_ids[ $spec['cat'] ] ) ) {
			$product->set_category_ids( array( $term_ids[ $spec['cat'] ] ) );
		}

		if ( $spec['image'] && ! empty( $attachments[ $spec['image'] ] ) ) {
			$product->set_image_id( $attachments[ $spec['image'] ] );
		}

		$product_id = $product->save();

		if ( ! empty( $spec['configurator'] ) && $product_id ) {
			set_theme_mod( 'prime_configurator_product', $product_id );

			// Phase 4c: exercise the custom-formula pricing model end to end —
			// this is the product prime_render_custom_pricing_fields() renders
			// its fields onto. Figures are the same placeholder rate the old
			// homepage calculator used; Phase 4c swaps these for Reem's real
			// per-product formula without touching anything else.
			update_post_meta( $product_id, '_prime_pricing_model', 'custom' );
			update_post_meta( $product_id, '_prime_rate_per_cm2', '0.00375' );
			update_post_meta( $product_id, '_prime_min_price_piece', '0.100' );
			update_post_meta( $product_id, '_prime_min_order_total', '2.250' );
			update_post_meta( $product_id, '_prime_custom_fields', array( 'dimensions', 'upload', 'finish' ) );
			update_post_meta(
				$product_id,
				'_prime_finishes',
				array(
					array( 'label' => 'Gloss', 'delta' => '0' ),
					array( 'label' => 'Matte', 'delta' => '0' ),
					array( 'label' => 'Holographic', 'delta' => '0.050' ),
				)
			);
		}

		// Phase 4a: exercise the simple add-ons system on one ordinary
		// product — a required text field, matching the "gift note" pattern
		// used across the account/checkout references.
		if ( 'Custom Rubber Stamp' === $spec['name'] && $product_id ) {
			update_post_meta(
				$product_id,
				'_prime_addons',
				array(
					array(
						'key'      => 'stamp-text',
						'label'    => 'Text to engrave',
						'type'     => 'text',
						'required' => true,
					),
				)
			);
		}
	}

	// ---- Variable product (Phase 4b) ---------------------------------------
	// Exercises WooCommerce's native variation system — no custom pricing code,
	// only the styling in assets/css/product.css. Only created once; re-running
	// the seeder does not duplicate it.
	if ( ! get_page_by_path( 'printed-sign-board-variable', OBJECT, 'product' ) && isset( $term_ids['sign-printing'] ) ) {
		$variable = new WC_Product_Variable();
		$variable->set_name( 'Printed Sign Board — Material' );
		$variable->set_slug( 'printed-sign-board-variable' );
		$variable->set_status( 'publish' );
		$variable->set_catalog_visibility( 'visible' );
		$variable->set_description( 'The same sign, in a material and size you pick — the price updates with the selection.' );
		$variable->set_category_ids( array( $term_ids['sign-printing'] ) );

		if ( ! empty( $attachments['backdrop'] ) ) {
			// Reuses the sign-printing category photo; a dedicated photo for
			// this variant is not in the seed set, and duplicating "backdrop"
			// under a second product name would repeat the mislabelling this
			// seeder otherwise avoids — acceptable here only because the title
			// itself ("— Material") makes clear this is a pricing-structure
			// demo product, not a second real photographed item.
			$variable->set_image_id( $attachments['backdrop'] );
		}

		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'Material' );
		$attribute->set_options( array( 'Foam board', 'Acrylic' ) );
		$attribute->set_position( 0 );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$variable->set_attributes( array( $attribute ) );

		$variable_id = $variable->save();

		if ( $variable_id ) {
			foreach ( array(
				'Foam board' => '4.000',
				'Acrylic'    => '9.500',
			) as $material => $price ) {
				$variation = new WC_Product_Variation();
				$variation->set_parent_id( $variable_id );
				$variation->set_attributes( array( 'material' => $material ) );
				$variation->set_regular_price( $price );
				$variation->set_status( 'publish' );
				$variation->save();
			}

			// WooCommerce derives the parent's own price range from its
			// variations, but only once the variation data is (re)synced.
			WC_Product_Variable::sync( $variable_id );
		}
	}

	// ---- Client logos -----------------------------------------------------
	$order = 0;

	foreach ( prime_seed_clients() as $title => $url ) {
		$order++;

		if ( prime_seed_find_by_title( $title, 'client_logo' ) ) {
			continue;
		}

		$logo_id = wp_insert_post(
			array(
				'post_type'   => 'client_logo',
				'post_status' => 'publish',
				'post_title'  => $title,
				'menu_order'  => $order,
			)
		);

		if ( is_wp_error( $logo_id ) || ! $logo_id ) {
			continue;
		}

		$attachment_id = prime_seed_attachment( $url, $title );

		if ( $attachment_id ) {
			set_post_thumbnail( $logo_id, $attachment_id );
		}
	}

	// ---- Front page -------------------------------------------------------
	$home = get_page_by_path( 'home' );

	if ( ! $home ) {
		$home_id = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Home',
				'post_name'   => 'home',
			)
		);
	} else {
		$home_id = $home->ID;
	}

	if ( $home_id && ! is_wp_error( $home_id ) ) {
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $home_id );
	}

	// ---- Theme settings ---------------------------------------------------
	set_theme_mod( 'prime_contact_phone', '+965 2222 0000' );
	set_theme_mod( 'prime_contact_email', 'hello@primeprint.com.kw' );
	set_theme_mod( 'prime_contact_whatsapp', 'https://wa.me/96522220000' );
	set_theme_mod( 'prime_contact_instagram', 'https://instagram.com/primeprintingco' );

	// The ticker is left empty on purpose, so it exercises the fallback path and
	// lists the real product categories.
	set_theme_mod( 'prime_services', '' );

	error_log( 'Prime seed: completed at version ' . PRIME_SEED_VERSION );
}
add_action( 'wp_loaded', 'prime_seed_run', 99 );
