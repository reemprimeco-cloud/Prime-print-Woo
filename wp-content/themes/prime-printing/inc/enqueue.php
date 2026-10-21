<?php
/**
 * Fonts, stylesheets and scripts.
 *
 * The stylesheet is deliberately split so that tokens.css is always first and
 * always the only place a brand value is defined. Page stylesheets are
 * registered up front and enqueued conditionally, so a template that needs one
 * asks for it by handle instead of the enqueue callback growing a chain of
 * is_*() checks it cannot see the templates for.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stop WordPress.com's `page-optimize` plugin combining scripts together.
 *
 * It concatenates every enqueued footer script into one file and prints it
 * as a single `<script>` tag. At least one third-party plugin script in that
 * mix (WooCommerce Jetpack's `wcj-checkout-core-fields.js`) calls jQuery at
 * its top level without declaring jQuery as a dependency, so depending on
 * load timing it can throw `jQuery is not defined` — and because everything
 * is one script tag, that uncaught error can silently stop every script
 * after it in the same file from ever running, this theme's own
 * `checkout.js` included (intermittent: only when that race goes the wrong
 * way, which is why this shipped as "the Area dropdown works" and was only
 * caught once a real customer hit the bad timing). `checkout.js` itself has
 * no jQuery dependency and would be unaffected on its own — the fix is
 * simply to stop it being combined into the same file as scripts that do.
 * `js_do_concat` is page-optimize's own documented filter for this.
 *
 * CSS concatenation is disabled too, separately: it doesn't share the
 * crash-mid-file failure mode above, but combined CSS/JS bundles are served
 * from a hashed `_jb_static/??<hash>` URL that this theme's own `?ver=`
 * cache-busting (prime_asset_version()) has no visibility into, and a CSS
 * fix has shown up late/stale behind that bundle cache more than once during
 * this build. Correctness over the small combine-request saving.
 */
add_filter( 'js_do_concat', '__return_false' );
add_filter( 'css_do_concat', '__return_false' );

/**
 * Google Fonts URL for the three brand typefaces.
 *
 * Enqueued as a stylesheet rather than @import so it is preloadable, dequeuable,
 * and does not block the theme CSS behind a second round trip.
 *
 * Weights are the ones the references actually use:
 *   Montserrat 200 300 400 500 600   display + body (Reem's brand font,
 *                                    2026-09-07 — replaced Jost and Inter)
 *   IBM Plex Sans Arabic 300 400 500 Arabic fallback only. The brand Arabic
 *                                    face is GE Dinar Two Medium, a licensed
 *                                    commercial font that has to be
 *                                    self-hosted from assets/fonts/ once
 *                                    Reem supplies her licensed files — see
 *                                    --arabic in tokens.css.
 *
 * @return string
 */
function prime_fonts_url() {
	$families = array(
		'Montserrat:wght@200;300;400;500;600',
		'IBM+Plex+Sans+Arabic:wght@300;400;500',
	);

	return add_query_arg(
		array(
			'family'  => implode( '&family=', $families ),
			'display' => 'swap',
		),
		'https://fonts.googleapis.com/css2'
	);
}

/**
 * Preconnect to the Google Fonts hosts.
 *
 * @param string[] $urls          URLs to print.
 * @param string   $relation_type Relation type being filtered.
 * @return array
 */
function prime_resource_hints( $urls, $relation_type ) {
	if ( 'preconnect' !== $relation_type ) {
		return $urls;
	}

	if ( ! wp_style_is( 'prime-fonts', 'enqueued' ) ) {
		return $urls;
	}

	$urls[] = array( 'href' => 'https://fonts.googleapis.com' );
	$urls[] = array(
		'href'        => 'https://fonts.gstatic.com',
		'crossorigin' => '',
	);

	return $urls;
}
add_filter( 'wp_resource_hints', 'prime_resource_hints', 10, 2 );

/**
 * The cache-busting version string for one theme asset.
 *
 * In development (SCRIPT_DEBUG on — set in wp-config.php, true on this dev
 * install) this is the file's own mtime, so every edit changes the URL and a
 * browser can never serve a stale cached copy of a file that has since
 * changed — the alternative bit the team once already: a static version
 * number left unbumped across a real edit means the browser keeps serving
 * whatever it cached the first time, silently, with no error to notice.
 *
 * In production this returns the theme version instead — filemtime() on every
 * request is a real, if small, cost not worth paying once assets are stable
 * and a CDN is doing the caching (Phase 11).
 *
 * @param string $relative_path Path under the theme root, e.g. '/assets/css/base.css'.
 * @return string
 */
function prime_asset_version( $relative_path ) {
	if ( ! ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ) {
		return PRIME_VERSION;
	}

	$file = PRIME_DIR . $relative_path;

	return file_exists( $file ) ? (string) filemtime( $file ) : PRIME_VERSION;
}

/**
 * Front-end assets.
 */
function prime_enqueue_assets() {
	wp_enqueue_style( 'prime-fonts', prime_fonts_url(), array(), null );

	// Cascade order matters: tokens, then base, then chrome.
	wp_enqueue_style( 'prime-tokens', PRIME_URI . '/assets/css/tokens.css', array(), prime_asset_version( '/assets/css/tokens.css' ) );
	wp_enqueue_style( 'prime-base', PRIME_URI . '/assets/css/base.css', array( 'prime-tokens' ), prime_asset_version( '/assets/css/base.css' ) );
	wp_enqueue_style( 'prime-layout', PRIME_URI . '/assets/css/layout.css', array( 'prime-base' ), prime_asset_version( '/assets/css/layout.css' ) );

	/*
	 * style.css carries only the theme header, but WordPress and several plugins
	 * assume the 'prime-style' handle resolves to the theme root stylesheet, and
	 * add_editor_style()/rtl.css load off it. Registering it keeps those
	 * assumptions true at negligible cost.
	 */
	wp_enqueue_style( 'prime-style', get_stylesheet_uri(), array( 'prime-layout' ), prime_asset_version( '/style.css' ) );
	wp_style_add_data( 'prime-style', 'rtl', 'replace' );

	// Page-level stylesheets, registered now, enqueued by whoever needs them.
	prime_register_page_styles();

	wp_enqueue_script( 'prime-navigation', PRIME_URI . '/assets/js/navigation.js', array(), prime_asset_version( '/assets/js/navigation.js' ), true );

	// Everywhere, every visitor — see the file's own docblock for why this is
	// safe: it is a no-op the instant window.Capacitor does not exist, which
	// is every request that isn't the iOS app's own WebView.
	wp_enqueue_script( 'prime-native-app-bridge', PRIME_URI . '/assets/js/native-app-bridge.js', array(), prime_asset_version( '/assets/js/native-app-bridge.js' ), true );

	if ( is_front_page() ) {
		wp_enqueue_script( 'prime-home', PRIME_URI . '/assets/js/home.js', array(), prime_asset_version( '/assets/js/home.js' ), true );
	}

	if ( prime_has_woocommerce() && ( is_shop() || is_product_category() || is_product_tag() ) ) {
		wp_enqueue_script( 'prime-shop', PRIME_URI . '/assets/js/shop.js', array(), prime_asset_version( '/assets/js/shop.js' ), true );

		// WooCommerce enqueues wc-add-to-cart itself when ajax add-to-cart is on
		// in Settings and .ajax_add_to_cart buttons are present, which the shop
		// cards carry (template-parts/shop/card.php). Nothing to register here.
	}

	if ( prime_has_woocommerce() && is_product() ) {
		wp_enqueue_script( 'prime-product', PRIME_URI . '/assets/js/product.js', array(), prime_asset_version( '/assets/js/product.js' ), true );
	}

	if ( prime_has_woocommerce() && is_checkout() && ! is_wc_endpoint_url() ) {
		wp_enqueue_script( 'prime-checkout', PRIME_URI . '/assets/js/checkout.js', array(), prime_asset_version( '/assets/js/checkout.js' ), true );
	}

	if ( prime_has_woocommerce() && is_account_page() ) {
		wp_enqueue_script( 'prime-account', PRIME_URI . '/assets/js/account.js', array(), prime_asset_version( '/assets/js/account.js' ), true );
	}

	wp_localize_script(
		'prime-navigation',
		'primeNav',
		array(
			'openLabel'  => __( 'Open menu', 'prime-printing' ),
			'closeLabel' => __( 'Close menu', 'prime-printing' ),
		)
	);

	if ( is_singular() && comments_open() && get_option( 'thread_comments' ) ) {
		wp_enqueue_script( 'comment-reply' );
	}
}
add_action( 'wp_enqueue_scripts', 'prime_enqueue_assets' );

/**
 * Drop the legacy `woocommerce-main` plugin's account stylesheet.
 *
 * That plugin (a pre-theme customisation from the Flatsome era) enqueues
 * `assets/css/customer.css` as `customer-new-style`, which forces
 * `.woocommerce-MyAccount-navigation { width: 15% !important }` and the
 * content to 83% at every screen width — on a phone the account menu
 * collapsed to a 51px card showing "Orde" (seen 2026-09-18 while building
 * the app mode; it was there on the web too). The theme has its own account
 * layout in account.css, so the plugin's sheet is simply not loaded.
 */
function prime_dequeue_legacy_account_styles() {
	wp_dequeue_style( 'customer-new-style' );
	wp_deregister_style( 'customer-new-style' );
}
add_action( 'wp_enqueue_scripts', 'prime_dequeue_legacy_account_styles', 100 );

/**
 * Register the per-page stylesheets and enqueue the ones this request needs.
 *
 * Each file is added as the phase that owns it lands; a handle whose file does
 * not exist yet is simply never enqueued, so this stays safe to call early.
 */
function prime_register_page_styles() {
	$pages = array(
		'prime-home'     => 'home.css',
		'prime-shop'     => 'shop.css',
		'prime-product'  => 'product.css',
		'prime-cart'     => 'cart.css',
		'prime-checkout' => 'checkout.css',
		'prime-account'  => 'account.css',
	);

	foreach ( $pages as $handle => $file ) {
		if ( ! file_exists( PRIME_DIR . '/assets/css/' . $file ) ) {
			continue;
		}

		wp_register_style( $handle, PRIME_URI . '/assets/css/' . $file, array( 'prime-layout' ), prime_asset_version( '/assets/css/' . $file ) );
	}

	foreach ( prime_current_page_styles() as $handle ) {
		if ( wp_style_is( $handle, 'registered' ) ) {
			wp_enqueue_style( $handle );
		}
	}
}

/**
 * Which page stylesheets this request wants.
 *
 * @return string[]
 */
function prime_current_page_styles() {
	$handles = array();

	if ( is_front_page() ) {
		$handles[] = 'prime-home';
	}

	if ( ! prime_has_woocommerce() ) {
		return $handles;
	}

	if ( is_shop() || is_product_category() || is_product_tag() ) {
		$handles[] = 'prime-shop';
	}

	if ( is_product() ) {
		$handles[] = 'prime-product';
	}

	if ( is_cart() ) {
		$handles[] = 'prime-cart';
	}

	if ( is_checkout() ) {
		$handles[] = 'prime-checkout';
	}

	if ( is_account_page() ) {
		$handles[] = 'prime-account';
	}

	return $handles;
}

/**
 * Match the editor to the front end so drafts do not look different once saved.
 */
function prime_editor_assets() {
	wp_enqueue_style( 'prime-editor-fonts', prime_fonts_url(), array(), null );
	wp_enqueue_style( 'prime-editor-tokens', PRIME_URI . '/assets/css/tokens.css', array(), prime_asset_version( '/assets/css/tokens.css' ) );
}
add_action( 'enqueue_block_editor_assets', 'prime_editor_assets' );
