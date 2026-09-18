<?php
/**
 * Shared markup helpers.
 *
 * Anything that appears in more than one template gets a function here rather
 * than being copy-pasted, so a change to (say) the wordmark is made once.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

/**
 * Is WooCommerce active on this install?
 *
 * Every WooCommerce call in the theme is guarded by this so the theme still
 * renders — degraded but not fatal — if the plugin is deactivated.
 *
 * @return bool
 */
function prime_has_woocommerce() {
	return class_exists( 'WooCommerce' );
}

/**
 * The signature mark: the sky square from the dot on the logo's "i".
 *
 * Always decorative. Every caller in the theme uses it as an accent beside real
 * text, so it is hidden from assistive tech rather than announced as an image.
 *
 * @param string $variant Optional modifier: 'navy', 'white', 'logo'.
 * @return string
 */
function prime_get_mark( $variant = '' ) {
	$class = 'prime-mark';

	if ( $variant ) {
		$class .= ' prime-mark--' . sanitize_html_class( $variant );
	}

	return '<span class="' . esc_attr( $class ) . '" aria-hidden="true"></span>';
}

/**
 * Echo the signature mark.
 *
 * @param string $variant Optional modifier.
 */
function prime_mark( $variant = '' ) {
	echo prime_get_mark( $variant ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
}

/**
 * The wordmark.
 *
 * The real brand asset, not a type reconstruction. Both colour variants ship in
 * the markup and CSS shows the right one, because the homepage header changes
 * from transparent-over-hero to solid white *while the page scrolls* — swapping
 * a src at that moment would flash an unloaded image.
 *
 * A logo is a picture of the company's name, so the images carry no alt text and
 * the link is given the accessible name instead. That way it is announced once,
 * not twice.
 *
 * @param array $args {
 *     @type string $tag  Element to render: 'a' (default) or 'div'.
 *     @type string $href Link target when tag is 'a'. Defaults to home.
 * }
 */
function prime_logo( $args = array() ) {
	$args = wp_parse_args(
		$args,
		array(
			'tag'  => 'a',
			'href' => home_url( '/' ),
		)
	);

	$name = get_bloginfo( 'name', 'display' );
	$name = $name ? $name : __( 'Prime Printing Co.', 'prime-printing' );

	// A custom logo set in the Customizer wins — but only one image is available
	// then, so it is used in both contexts and the site owner is responsible for
	// picking one that reads on navy as well as on white.
	$custom_id = (int) get_theme_mod( 'custom_logo', 0 );

	if ( $custom_id > 0 ) {
		$inner = wp_get_attachment_image(
			$custom_id,
			'full',
			false,
			array(
				'class' => 'prime-logo__img',
				'alt'   => '',
			)
		);
	} else {
		$inner = sprintf(
			'<img class="prime-logo__img prime-logo__img--dark" src="%1$s" alt="" width="720" height="315" decoding="async">' .
			'<img class="prime-logo__img prime-logo__img--light" src="%2$s" alt="" width="720" height="315" decoding="async">',
			esc_url( PRIME_URI . '/assets/img/logo-navy.png' ),
			esc_url( PRIME_URI . '/assets/img/logo-white.png' )
		);
	}

	if ( 'a' === $args['tag'] ) {
		printf(
			'<a class="prime-logo" href="%1$s" rel="home" aria-label="%2$s">%3$s</a>',
			esc_url( $args['href'] ),
			esc_attr( $name ),
			$inner // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled from escaped parts.
		);
		return;
	}

	printf(
		'<div class="prime-logo" role="img" aria-label="%1$s">%2$s</div>',
		esc_attr( $name ),
		$inner // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled from escaped parts.
	);
}

/**
 * The cart link shown in the header.
 *
 * Renders nothing when WooCommerce is inactive rather than linking to a page
 * that would not exist.
 */
function prime_cart_link() {
	if ( ! prime_has_woocommerce() ) {
		return;
	}

	$cart = WC()->cart;
	$count = $cart ? $cart->get_cart_contents_count() : 0;

	printf(
		'<a class="prime-cart-link" href="%1$s"><span class="prime-cart-count">%2$s</span></a>',
		esc_url( wc_get_cart_url() ),
		esc_html(
			sprintf(
				/* translators: %s: number of items in the cart. */
				_n( 'Cart (%s)', 'Cart (%s)', $count, 'prime-printing' ),
				number_format_i18n( $count )
			)
		)
	);
}

/**
 * Keep the header cart count in step with WooCommerce's AJAX fragments.
 *
 * @param array $fragments Fragments keyed by selector.
 * @return array
 */
function prime_cart_fragment( $fragments ) {
	if ( ! prime_has_woocommerce() ) {
		return $fragments;
	}

	ob_start();
	prime_cart_link();
	$fragments['a.prime-cart-link'] = ob_get_clean();

	return $fragments;
}
add_filter( 'woocommerce_add_to_cart_fragments', 'prime_cart_fragment' );

/**
 * The language switcher.
 *
 * Phase 6 replaces the body of this with Polylang's own switcher, which links
 * to the translated counterpart of the current URL. Until Polylang is installed
 * this renders nothing, so no dead control ships in the meantime.
 *
 * @param string $style 'button' for the header control, 'segmented' for the
 *                      two-up control inside the mobile menu.
 */
function prime_language_switcher( $style = 'button' ) {
	if ( ! function_exists( 'pll_the_languages' ) ) {
		return;
	}

	$languages = pll_the_languages(
		array(
			'raw'                    => 1,
			'hide_if_no_translation' => 0,
			/*
			 * Polylang defaults `hide_if_empty` to 1, which drops a language
			 * that has no published content of its own. Arabic starts with
			 * none, so on 2026-09-05 — the day Polylang went live — the
			 * switcher rendered on /ar/ pages but nowhere on the English site:
			 * there was no way in to Arabic at all, and no way to create the
			 * content that would have unhidden it. Both languages always show.
			 */
			'hide_if_empty'          => 0,
		)
	);

	if ( empty( $languages ) || ! is_array( $languages ) ) {
		return;
	}

	if ( 'segmented' === $style ) {
		echo '<div class="prime-seg">';

		foreach ( $languages as $language ) {
			printf(
				'<a class="%1$s" href="%2$s" lang="%3$s" hreflang="%3$s">%4$s</a>',
				esc_attr( $language['current_lang'] ? 'is-on' : '' ),
				esc_url( $language['url'] ),
				esc_attr( $language['slug'] ),
				esc_html( $language['name'] )
			);
		}

		echo '</div>';
		return;
	}

	// Header button: a single link to the language the visitor is not in.
	foreach ( $languages as $language ) {
		if ( $language['current_lang'] ) {
			continue;
		}

		printf(
			'<a class="prime-langbtn" href="%1$s" lang="%2$s" hreflang="%2$s">%3$s</a>',
			esc_url( $language['url'] ),
			esc_attr( $language['slug'] ),
			esc_html( $language['name'] )
		);
		break;
	}
}

/**
 * A navigation menu, or a sensible fallback when none is assigned yet.
 *
 * Without this a fresh install shows nothing where the nav should be, which
 * reads as a broken header rather than an unconfigured one.
 *
 * @param string $location  Registered nav menu location.
 * @param array  $overrides wp_nav_menu() arguments to merge in.
 */
function prime_nav_menu( $location, $overrides = array() ) {
	$args = wp_parse_args(
		$overrides,
		array(
			'theme_location' => $location,
			'container'      => false,
			'depth'          => 1,
			'fallback_cb'    => 'prime_nav_menu_fallback',
			'echo'           => true,
		)
	);

	wp_nav_menu( $args );
}

/**
 * The company address's default — a single source shared by customizer.php
 * (the setting's own default) and invoice.php (the fallback when reading the
 * theme mod), so they can't drift apart the way they did once already:
 * get_theme_mod()'s own second-argument fallback matching the Customizer
 * control's default is easy to forget is a SEPARATE value, and if the mod has
 * never actually been saved (nobody has opened the Customizer panel yet,
 * which is the state of every fresh install), a mismatched fallback here
 * silently drops the address off every invoice with no error.
 *
 * Al-Dajeej — the move from the old Shuwaikh Industrial address, confirmed by
 * Reem directly (the Google Business listing's name/address text is still
 * pending Google's review and shows the old Shuwaikh details in the
 * meantime; the pin itself has already moved — see prime_default_pickup_map()).
 *
 * @return string
 */
function prime_default_company_address() {
	return "Al-Dajeej, Block 1, Street 79\nDasman Mall, Floor 1, Office 18\nKuwait";
}

/**
 * The pickup location's map defaults — same reasoning, and same shared-source
 * fix, as prime_default_company_address() above: customizer.php's own
 * setting default and checkout-pickup.php's get_theme_mod() fallback must be
 * the exact same value, or the map silently never renders on a fresh install
 * where nobody has opened the Customizer panel yet.
 *
 * Coordinates pulled directly from Google Maps' own "Embed a map" panel for
 * Prime Printing Co.'s listing (same listing/place ID as before the move —
 * only the pin position and, once Google's review completes, the name/address
 * text have changed).
 *
 * @return array{embed: string, directions: string}
 */
function prime_default_pickup_map() {
	return array(
		'embed'      => 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3480.65470631334!2d47.9655069!3d29.263100699999995!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3fcf9b004086b431%3A0x47a5b0c49da80d4b!2sPrime%20Printing%20Co!5e0!3m2!1sen!2skw!4v1788469880512!5m2!1sen!2skw',
		'directions' => 'https://www.google.com/maps/dir/?api=1&destination=29.263100699999995,47.9655069',
	);
}

/**
 * WooCommerce's own system pages (Cart, Checkout, My Account) — these have no
 * standalone content of their own and are not meant to compete with real
 * company pages (About, Contact, …) in a generic "top-level pages" fallback
 * nav. Without this exclusion, get_pages() sorted alphabetically surfaces
 * "Cart" and "Checkout" ahead of any actual page, since WooCommerce creates
 * them automatically and their titles happen to sort early.
 *
 * @return int[]
 */
function prime_system_page_ids() {
	if ( ! prime_has_woocommerce() ) {
		return array();
	}

	return array_filter(
		array(
			wc_get_page_id( 'cart' ),
			wc_get_page_id( 'checkout' ),
			wc_get_page_id( 'myaccount' ),
		),
		static function ( $id ) {
			return $id > 0;
		}
	);
}

/**
 * Fallback navigation: home, shop, and the site's top-level pages.
 *
 * @param array $args wp_nav_menu() arguments.
 */
function prime_nav_menu_fallback( $args ) {
	$items = array(
		array(
			'title' => __( 'Home', 'prime-printing' ),
			'url'   => home_url( '/' ),
		),
	);

	if ( prime_has_woocommerce() ) {
		$shop_id = wc_get_page_id( 'shop' );

		if ( $shop_id > 0 ) {
			$items[] = array(
				'title' => __( 'Shop', 'prime-printing' ),
				'url'   => get_permalink( $shop_id ),
			);
		}
	}

	$pages = get_pages(
		array(
			'parent'      => 0,
			'sort_column' => 'menu_order,post_title',
			'number'      => 3,
			'exclude'     => prime_system_page_ids(),
		)
	);

	foreach ( $pages as $page ) {
		$items[] = array(
			'title' => $page->post_title,
			'url'   => get_permalink( $page ),
		);
	}

	$list = '<ul class="' . esc_attr( isset( $args['menu_class'] ) ? $args['menu_class'] : '' ) . '">';

	foreach ( $items as $item ) {
		$list .= sprintf(
			'<li><a href="%1$s">%2$s</a></li>',
			esc_url( $item['url'] ),
			esc_html( $item['title'] )
		);
	}

	$list .= '</ul>';

	if ( empty( $args['echo'] ) ) {
		return $list;
	}

	echo $list; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled from escaped parts.
}

/**
 * The top-level product categories, for the mobile menu's category shortcuts
 * and the footer's popular-categories column.
 *
 * @param int $limit Maximum number of categories.
 * @return WP_Term[]
 */
function prime_product_categories( $limit = 8 ) {
	if ( ! prime_has_woocommerce() ) {
		return array();
	}

	$terms = get_terms(
		array(
			'taxonomy'     => 'product_cat',
			'hide_empty'   => true,
			'parent'       => 0,
			'number'       => (int) $limit,
			'orderby'      => 'count',
			'order'        => 'DESC',
		)
	);

	if ( is_wp_error( $terms ) ) {
		return array();
	}

	return $terms;
}

/**
 * The site's contact details, editable from Appearance → Customize.
 *
 * The references hard-code a placeholder number; these options let Reem set the
 * real ones without touching a template.
 *
 * @param string $key One of: phone, email, whatsapp, instagram, facebook.
 * @return string
 */
function prime_contact( $key ) {
	$defaults = array(
		'phone'     => '+965 0000 0000',
		'email'     => 'hello@primeprint.com.kw',
		'whatsapp'  => '',
		'instagram' => '',
		'facebook'  => '',
	);

	if ( ! isset( $defaults[ $key ] ) ) {
		return '';
	}

	return (string) get_theme_mod( 'prime_contact_' . $key, $defaults[ $key ] );
}
