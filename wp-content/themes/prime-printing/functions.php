<?php
/**
 * Prime Printing — theme bootstrap.
 *
 * This file does nothing but define the theme constants and load inc/. Every
 * piece of real behaviour lives in its own module so a change to, say, the
 * checkout never risks touching the enqueue order.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

define( 'PRIME_VERSION', wp_get_theme()->get( 'Version' ) );
define( 'PRIME_DIR', get_template_directory() );
define( 'PRIME_URI', get_template_directory_uri() );

/**
 * Modules, in load order.
 *
 * setup          theme supports, menus, image sizes, content width
 * enqueue        fonts, stylesheets, scripts
 * template-tags  the shared markup helpers templates call (mark, logo, price…)
 * woocommerce    WooCommerce compatibility and hook rearrangement
 * customizer     every editable string and choice in wp-admin
 * homepage       the homepage's content sources (services, wall, logos)
 * shop            the shop archive's queries, sorting, and AJAX pager
 * category-icons   the inline-SVG category icon set (category-card.php)
 * product          single-product hook rearrangement and display helpers
 * product-admin    the product edit screen's pricing-model + add-ons builder
 * product-addons   Phase 4a — simple add-ons, captured through to the order
 * product-pricing  Phase 4c — custom-formula pricing, enforced server-side
 * checkout-data     Kuwait governorates, areas, shipping rates (Phase 5)
 * checkout-shipping governorate-based WC_Shipping_Method (Phase 5)
 * checkout-pickup   local pickup shipping option + the order-confirmation map
 * checkout-fields   the three address types, phone validation (Phase 5)
 * checkout-payment  payment method tiles, COD surcharge (Phase 5)
 * armada            Armada Delivery live fee quotes (inc/checkout-shipping.php uses it)
 * checkout-admin    delivery pin + gift recipient on the admin order screen
 * i18n              Phase 6 — Polylang language registration
 * i18n-product-import Phase 6 — one-time bulk Arabic product creation (Tools → Import Arabic Products)
 * invoice           Phase 7 — bilingual PDF invoices (Dompdf, vendored — see vendor/autoload.php)
 * account           Phase 8 — customer account page (my-account/ overrides, Files tab, reorder)
 * push-notifications Phase 13 — APNs push for the iOS app: token registration, order-status pushes, admin announcements
 * native-app-tracking Phase 13 — strips Meta Pixel/Pinterest tracking scripts inside the iOS app (no ATT prompt needed)
 */
$prime_modules = array(
	'setup',
	'enqueue',
	'template-tags',
	'woocommerce',
	'customizer',
	'homepage',
	'shop',
	'category-icons',
	'product',
	'product-admin',
	'product-addons',
	'product-pricing',
	'uv-dtf-calculator',
	'paper-sticker-calculator',
	'pp-sticker-calculator',
	'diecut-cards-calculator',
	'checkout-data',
	'checkout-shipping',
	'armada',
	'checkout-pickup',
	'checkout-fields',
	'checkout-payment',
	'checkout-admin',
	'i18n',
	'i18n-product-import',
	'invoice',
	'account',
	'push-notifications',
	'native-app-tracking',
	'native-app-ui',
);

foreach ( $prime_modules as $prime_module ) {
	$prime_module_path = PRIME_DIR . '/inc/' . $prime_module . '.php';

	if ( is_readable( $prime_module_path ) ) {
		require_once $prime_module_path;
		continue;
	}

	// A missing module is a build error, not something to fail silently on.
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		trigger_error(
			esc_html( sprintf( 'Prime Printing: missing theme module inc/%s.php', $prime_module ) ),
			E_USER_WARNING
		);
	}
}

unset( $prime_modules, $prime_module, $prime_module_path );
