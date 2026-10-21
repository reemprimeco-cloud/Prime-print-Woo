<?php
/**
 * Prime's iOS apps: where they live on the App Store, and the Smart App Banner.
 *
 * The homepage band (template-parts/home/apps.php) and the banner both read
 * from here, so an App Store ID changes in one place. Inside the app itself
 * nothing is promoted.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

/**
 * The apps Prime publishes.
 *
 * @return array[] Each: array{ key: string, name: string, url: string, id: string }
 */
function prime_apps() {
	return array(
		array(
			'key'  => 'printing',
			'name' => 'Prime Printing',
			'id'   => '6809028470',
			'url'  => 'https://apps.apple.com/app/id6809028470',
		),
		array(
			'key'  => 'rewards',
			'name' => 'Prime Rewards',
			'id'   => '6799943266',
			'url'  => 'https://apps.apple.com/app/id6799943266',
		),
	);
}

/**
 * Safari's native "Open in the App Store" banner on iPhone and iPad, for the
 * main Prime Printing app. Browsers only; never inside the app's own WebView.
 */
function prime_smart_app_banner() {
	if ( is_admin() || prime_is_native_app_request() ) {
		return;
	}

	$apps = prime_apps();

	printf( "<meta name=\"apple-itunes-app\" content=\"app-id=%s\">\n", esc_attr( $apps[0]['id'] ) );
}
add_action( 'wp_head', 'prime_smart_app_banner', 1 );
