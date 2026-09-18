<?php
/**
 * App mode — the iOS app's own presentation of the same site.
 *
 * Reem's ask (2026-09-18): the app should not look like the website inside a
 * frame; it should look like an app. Same WooCommerce, same products, same
 * cart/checkout/calculators/Armada/Tap — only the chrome changes, and only
 * when the request comes from the app (prime_is_native_app_request(), the
 * same User-Agent signal inc/native-app-tracking.php already keys the
 * ad-tracker stripping off). A browser visit is never affected.
 *
 * What changes in app mode:
 *   - body gets `.prime-app`; the web header/topbar/mobile menu/footer are
 *     not rendered at all (header.php / footer.php) and the page CSS in
 *     assets/css/app.css restyles what's left for a phone;
 *   - a compact top bar (template-parts/app/topbar.php): the logo centred
 *     with a search button on the home screen, back + title (+ share on a
 *     product) everywhere else;
 *   - a fixed bottom tab bar (template-parts/app/tabbar.php): Home, Shop,
 *     Cart with a live count, Account;
 *   - the homepage is swapped for front-page-app.php (a seasonal banner Reem
 *     edits in the Customizer, a categories strip, popular products) instead
 *     of the long web landing;
 *   - the shop archive shows category chips instead of the category grid
 *     (woocommerce/archive-product.php);
 *   - the language switcher, and the About/Contact/Privacy page links the
 *     web footer normally carries, move to the Account tab.
 *
 * Everything ships from the server, so a change here reaches the installed
 * app immediately with no App Store review.
 *
 * Preview from a normal browser: append `?prime_app=1` to any URL — see
 * prime_app_preview_cookie() in inc/native-app-tracking.php. `?prime_app=0`
 * turns it off again.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

/**
 * Body classes: flag app mode, and drop the transparent-over-hero header
 * mode — there is no hero (and no web header) in the app.
 *
 * @param string[] $classes Body classes.
 * @return string[]
 */
function prime_app_body_classes( $classes ) {
	if ( ! prime_is_native_app_request() ) {
		return $classes;
	}

	$classes[] = 'prime-app';

	return array_values( array_diff( $classes, array( 'prime-has-hero' ) ) );
}
add_filter( 'body_class', 'prime_app_body_classes', 20 );

/**
 * Serve the app's own homepage template instead of the web landing page.
 *
 * @param string $template Template WordPress chose.
 * @return string
 */
function prime_app_template( $template ) {
	if ( prime_is_native_app_request() && is_front_page() && ! is_paged() ) {
		$app_template = PRIME_DIR . '/front-page-app.php';

		if ( is_readable( $app_template ) ) {
			return $app_template;
		}
	}

	return $template;
}
add_filter( 'template_include', 'prime_app_template', 20 );

/**
 * App stylesheet and script — after every page stylesheet, so its overrides
 * win on cascade order without !important.
 */
function prime_app_assets() {
	if ( ! prime_is_native_app_request() ) {
		return;
	}

	$deps = array( 'prime-layout' );

	foreach ( prime_current_page_styles() as $handle ) {
		if ( wp_style_is( $handle, 'registered' ) ) {
			$deps[] = $handle;
		}
	}

	// The app home reuses the shop's product cards (template-parts/shop/card.php),
	// whose styles live in shop.css — a stylesheet the web homepage never loads.
	if ( is_front_page() && wp_style_is( 'prime-shop', 'registered' ) ) {
		wp_enqueue_style( 'prime-shop' );
		$deps[] = 'prime-shop';
	}

	wp_enqueue_style( 'prime-app', PRIME_URI . '/assets/css/app.css', $deps, prime_asset_version( '/assets/css/app.css' ) );
	wp_enqueue_script( 'prime-app', PRIME_URI . '/assets/js/app.js', array(), prime_asset_version( '/assets/js/app.js' ), true );

	wp_localize_script(
		'prime-app',
		'primeApp',
		array(
			'homeUrl' => home_url( '/' ),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'prime_app_assets', 20 );

/**
 * No WordPress admin bar inside the app — it pushes the whole page down by
 * its own height and has no place on a phone screen.
 *
 * @param bool $show Whether to show it.
 * @return bool
 */
function prime_app_hide_admin_bar( $show ) {
	return prime_is_native_app_request() ? false : $show;
}
add_filter( 'show_admin_bar', 'prime_app_hide_admin_bar' );

/**
 * App responses must never be cached and handed to a browser visitor (or the
 * other way round) — same URL, different HTML.
 */
function prime_app_nocache_headers() {
	if ( prime_is_native_app_request() ) {
		nocache_headers();
		header( 'Vary: User-Agent, Cookie' );
	}
}
add_action( 'template_redirect', 'prime_app_nocache_headers', 1 );

/**
 * The compact top bar, first thing in <body>.
 */
function prime_app_render_topbar() {
	if ( prime_is_native_app_request() ) {
		get_template_part( 'template-parts/app/topbar' );
	}
}
add_action( 'wp_body_open', 'prime_app_render_topbar' );

/**
 * The fixed bottom tab bar, printed with the footer scripts.
 */
function prime_app_render_tabbar() {
	if ( prime_is_native_app_request() ) {
		get_template_part( 'template-parts/app/tabbar' );
	}
}
add_action( 'wp_footer', 'prime_app_render_tabbar', 5 );

/**
 * The short title the top bar shows for the current screen.
 *
 * @return string
 */
function prime_app_page_title() {
	if ( is_front_page() && ! is_paged() ) {
		return '';
	}

	if ( prime_has_woocommerce() ) {
		if ( is_product() ) {
			$terms = get_the_terms( get_the_ID(), 'product_cat' );

			return ( $terms && ! is_wp_error( $terms ) ) ? $terms[0]->name : __( 'Shop', 'prime-printing' );
		}

		if ( is_product_category() || is_product_tag() ) {
			return single_term_title( '', false );
		}

		if ( is_shop() ) {
			return prime_shop_search()
				? __( 'Search', 'prime-printing' )
				: __( 'Shop', 'prime-printing' );
		}

		if ( is_cart() ) {
			return __( 'Cart', 'prime-printing' );
		}

		if ( is_checkout() ) {
			return is_order_received_page()
				? __( 'Order received', 'prime-printing' )
				: __( 'Checkout', 'prime-printing' );
		}

		if ( is_account_page() ) {
			return __( 'My account', 'prime-printing' );
		}
	}

	if ( is_search() ) {
		return __( 'Search', 'prime-printing' );
	}

	if ( is_404() ) {
		return __( 'Not found', 'prime-printing' );
	}

	$title = single_post_title( '', false );

	return $title ? $title : get_the_title();
}

/**
 * Which bottom tab the current screen belongs to.
 *
 * @return string home|shop|cart|account|''
 */
function prime_app_active_tab() {
	if ( is_front_page() && ! is_paged() ) {
		return 'home';
	}

	if ( ! prime_has_woocommerce() ) {
		return '';
	}

	if ( is_cart() || is_checkout() ) {
		return 'cart';
	}

	if ( is_account_page() ) {
		return 'account';
	}

	if ( is_shop() || is_product_taxonomy() || is_product() || is_search() ) {
		return 'shop';
	}

	return '';
}

/**
 * The cart-count badge on the Cart tab.
 *
 * @return string HTML.
 */
function prime_app_cart_badge_html() {
	$count = 0;

	if ( prime_has_woocommerce() && WC()->cart ) {
		$count = (int) WC()->cart->get_cart_contents_count();
	}

	return sprintf(
		'<span class="prime-app-tab__badge%1$s" data-prime-app-badge>%2$s</span>',
		$count > 0 ? '' : ' is-empty',
		esc_html( number_format_i18n( $count ) )
	);
}

/**
 * Keep the tab-bar badge in step with WooCommerce's AJAX add-to-cart.
 *
 * @param array $fragments Fragments keyed by selector.
 * @return array
 */
function prime_app_cart_fragment( $fragments ) {
	if ( prime_is_native_app_request() ) {
		$fragments['span[data-prime-app-badge]'] = prime_app_cart_badge_html();
	}

	return $fragments;
}
add_filter( 'woocommerce_add_to_cart_fragments', 'prime_app_cart_fragment' );

/**
 * The language switcher, on the Account screen (logged in or the login form).
 * The web header that carried it is not rendered in app mode.
 */
function prime_app_account_language() {
	if ( ! prime_is_native_app_request() || ! function_exists( 'pll_the_languages' ) ) {
		return;
	}

	echo '<div class="prime-app-lang">';
	prime_language_switcher( 'segmented' );
	echo '</div>';
}
add_action( 'woocommerce_before_account_navigation', 'prime_app_account_language' );
add_action( 'woocommerce_before_customer_login_form', 'prime_app_account_language' );

/**
 * The app home's promo banner — the one piece of merchandising on the home
 * screen, and the only thing on it Reem changes by season.
 *
 * It started as a single line of text ("Delivery within 48 hours across
 * Kuwait") borrowed from the web announcement bar, which meant a seasonal
 * promotion — Ramadan, National Day, back-to-school, a new product — needed a
 * developer. Every part of it is a Customizer setting now (inc/customizer.php,
 * "App — home banner"): image, eyebrow, title, body, button label and the link
 * it points at.
 *
 * Returns false when the banner is switched off or has nothing to say, so
 * front-page-app.php can skip the whole block rather than print an empty box.
 *
 * @return array{image: string, image_id: int, eyebrow: string, title: string, text: string, cta: string, url: string}|false
 */
function prime_app_banner() {
	if ( ! get_theme_mod( 'prime_app_banner_on', true ) ) {
		return false;
	}

	$translate = static function ( $value ) {
		return ( $value && function_exists( 'pll__' ) ) ? pll__( $value ) : $value;
	};

	$image_id = (int) get_theme_mod( 'prime_app_banner_image', 0 );
	$title    = $translate( trim( (string) get_theme_mod( 'prime_app_banner_title', prime_app_banner_default_title() ) ) );
	$eyebrow  = $translate( trim( (string) get_theme_mod( 'prime_app_banner_eyebrow', __( 'Fast delivery', 'prime-printing' ) ) ) );
	$text     = $translate( trim( (string) get_theme_mod( 'prime_app_banner_text', '' ) ) );
	$cta      = $translate( trim( (string) get_theme_mod( 'prime_app_banner_cta', __( 'Shop now', 'prime-printing' ) ) ) );
	$url      = trim( (string) get_theme_mod( 'prime_app_banner_url', '' ) );

	/*
	 * An image on its own is a perfectly good seasonal banner — a designed
	 * artwork with the offer already set in it needs no copy over the top.
	 * Only a banner with neither image nor words is nothing to show.
	 */
	if ( '' === $title && '' === $text && '' === $eyebrow && ! $image_id ) {
		return false;
	}

	if ( '' === $url ) {
		$url = prime_has_woocommerce() && wc_get_page_id( 'shop' ) > 0
			? add_query_arg( 'view', 'all', get_permalink( wc_get_page_id( 'shop' ) ) )
			: home_url( '/' );
	}

	return array(
		'image'    => $image_id ? (string) wp_get_attachment_image_url( $image_id, 'large' ) : '',
		'image_id' => $image_id,
		'eyebrow'  => $eyebrow,
		'title'    => $title,
		'text'     => $text,
		'cta'      => $cta,
		'url'      => $url,
	);
}

/**
 * The banner title's default, shared by the setting in inc/customizer.php and
 * the get_theme_mod() fallback above — the two must be the same string or a
 * fresh install (where the setting has never been saved) silently shows
 * something the Customizer control doesn't admit to.
 *
 * @return string
 */
function prime_app_banner_default_title() {
	return __( 'Delivery within 48 hours across Kuwait', 'prime-printing' );
}

/**
 * The app's own page links — About, Contact, Privacy policy — at the foot of
 * the Account screen.
 *
 * The web footer that normally carries them is not rendered in app mode, and
 * the tab bar has room for four destinations only, so without this they are
 * unreachable from inside the app (Reem, 2026-09-18). Top-level published
 * pages, minus the ones that are already a tab or a screen of their own.
 *
 * @return WP_Post[]
 */
function prime_app_account_pages() {
	$exclude = prime_system_page_ids();

	$front = (int) get_option( 'page_on_front' );

	if ( $front > 0 ) {
		$exclude[] = $front;
	}

	if ( prime_has_woocommerce() ) {
		$shop = wc_get_page_id( 'shop' );

		if ( $shop > 0 ) {
			$exclude[] = $shop;
		}
	}

	$pages = get_pages(
		array(
			'parent'      => 0,
			'sort_column' => 'menu_order,post_title',
			'exclude'     => $exclude,
		)
	);

	return is_array( $pages ) ? $pages : array();
}

/**
 * Render those links, at the bottom of the Account screen.
 *
 * Dashboard and login form only — the endpoint screens (Orders, Addresses,
 * Account details) are a task in progress, not a place to wander off to
 * Privacy policy from.
 */
function prime_app_account_links() {
	if ( ! prime_is_native_app_request() ) {
		return;
	}

	$pages = prime_app_account_pages();

	if ( ! $pages ) {
		return;
	}
	?>
	<nav class="prime-app-links" aria-label="<?php esc_attr_e( 'About Prime Printing', 'prime-printing' ); ?>">
		<?php foreach ( $pages as $page ) : ?>
			<a class="prime-app-link" href="<?php echo esc_url( get_permalink( $page ) ); ?>">
				<span><?php echo esc_html( get_the_title( $page ) ); ?></span>
				<?php echo prime_app_icon( 'arrow' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG set. ?>
			</a>
		<?php endforeach; ?>

		<?php if ( prime_contact( 'whatsapp' ) ) : ?>
			<a class="prime-app-link" href="<?php echo esc_url( prime_contact( 'whatsapp' ) ); ?>" rel="noopener" target="_blank">
				<span><?php esc_html_e( 'WhatsApp us', 'prime-printing' ); ?></span>
				<?php echo prime_app_icon( 'arrow' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG set. ?>
			</a>
		<?php endif; ?>

		<p class="prime-app-links__legal">
			<?php
			printf(
				/* translators: 1: current year, 2: site name. */
				esc_html__( '© %1$s %2$s', 'prime-printing' ),
				esc_html( wp_date( 'Y' ) ),
				esc_html( get_bloginfo( 'name', 'display' ) )
			);
			?>
		</p>
	</nav>
	<?php
}

/**
 * Account dashboard only — woocommerce_account_content fires on every account
 * screen, and is_wc_endpoint_url() with no argument is what separates the
 * dashboard from Orders/Addresses/Account details.
 */
function prime_app_account_links_dashboard() {
	if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url() ) {
		return;
	}

	prime_app_account_links();
}
add_action( 'woocommerce_account_content', 'prime_app_account_links_dashboard', 30 );
add_action( 'woocommerce_after_customer_login_form', 'prime_app_account_links' );

/**
 * No "… has been added to your cart" notice inside the app.
 *
 * The tab bar's cart badge already counts up the moment the item lands
 * (prime_app_cart_fragment()), so the notice is a second, slower answer to a
 * question already answered — and on a phone it pushes the product the
 * customer is still looking at off the screen (Reem, 2026-09-18). An empty
 * string here means wc_add_to_cart_message() never reaches wc_add_notice() at
 * all, rather than storing a blank notice that renders as an empty box.
 *
 * @param string $message The notice HTML.
 * @return string
 */
function prime_app_silence_add_to_cart_message( $message ) {
	return prime_is_native_app_request() ? '' : $message;
}
add_filter( 'wc_add_to_cart_message_html', 'prime_app_silence_add_to_cart_message', 20 );

/**
 * Inline SVG icons for the app chrome. Stroke icons on currentColor, so the
 * CSS decides the colour; a fixed, hand-authored set — no user input.
 *
 * @param string $name Icon name.
 * @return string SVG markup, or '' for an unknown name.
 */
function prime_app_icon( $name ) {
	$icons = array(
		'home'    => '<path d="M3 11 12 4l9 7v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
		'shop'    => '<rect x="3" y="3" width="8" height="8"/><rect x="13" y="3" width="8" height="8"/><rect x="3" y="13" width="8" height="8"/><rect x="13" y="13" width="8" height="8"/>',
		'cart'    => '<path d="M6 7h13l-1.5 8H7.5z"/><path d="M6 7 5 4H3"/><circle cx="9" cy="19" r="1.3"/><circle cx="16" cy="19" r="1.3"/>',
		'account' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
		'search'  => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
		'back'    => '<path d="M15 5l-7 7 7 7"/>',
		'share'   => '<path d="M4 12v7a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-7"/><path d="M12 3v12M8 7l4-4 4 4"/>',
		'arrow'   => '<path d="M5 12h14M13 6l6 6-6 6"/>',
	);

	if ( ! isset( $icons[ $name ] ) ) {
		return '';
	}

	return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $icons[ $name ] . '</svg>';
}
