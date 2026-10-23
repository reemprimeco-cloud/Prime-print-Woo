<?php
/**
 * Plugin Name:       PRIME Image Lab
 * Description:       Free, private image tools that run entirely in the visitor's browser: improve a photo, remove a plain background and check print readiness. Add it to a page with the [prime_image_lab] shortcode.
 * Version:           0.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Prime Printing Co.
 * License:           GPL-2.0-or-later
 * Text Domain:       prime-image-lab
 *
 * Nothing is uploaded: there is no REST route, AJAX handler, database table
 * or stored option here. Deactivating the plugin removes the tool completely;
 * the page it sits on simply shows its other content.
 *
 * @package PrimeImageLab
 */

defined( 'ABSPATH' ) || exit;

define( 'PRIME_IMAGE_LAB_VERSION', '0.2.0' );
define( 'PRIME_IMAGE_LAB_URL', plugin_dir_url( __FILE__ ) );

/**
 * The WhatsApp link the tool hands off to. Set from the business number; the
 * `prime_image_lab_whatsapp` filter overrides it. An empty value hides the button.
 *
 * @return string
 */
function prime_image_lab_whatsapp() {
	return (string) apply_filters( 'prime_image_lab_whatsapp', 'https://wa.me/96565068000' );
}

/**
 * The shop URL: WooCommerce's own shop page, else /shop/.
 *
 * @return string
 */
function prime_image_lab_shop_url() {
	if ( function_exists( 'wc_get_page_id' ) ) {
		$id = (int) wc_get_page_id( 'shop' );

		if ( $id > 0 ) {
			$url = get_permalink( $id );

			if ( $url ) {
				return $url;
			}
		}
	}

	return home_url( '/shop/' );
}

/**
 * Whether the current request shows the tool.
 *
 * @return bool
 */
function prime_image_lab_is_page() {
	if ( ! is_singular() ) {
		return false;
	}

	$post = get_post();

	return $post && has_shortcode( (string) $post->post_content, 'prime_image_lab' );
}

/**
 * Page-scoped assets, only on a page that contains the shortcode.
 */
function prime_image_lab_enqueue() {
	if ( ! prime_image_lab_is_page() ) {
		return;
	}

	$base = PRIME_IMAGE_LAB_URL . 'assets/';
	$ver  = PRIME_IMAGE_LAB_VERSION;

	wp_enqueue_style( 'prime-image-lab', $base . 'image-lab.css', array(), $ver );
	wp_enqueue_script( 'prime-image-lab-core', $base . 'image-lab-core.js', array(), $ver, true );
	wp_enqueue_script( 'prime-image-lab', $base . 'image-lab.js', array( 'prime-image-lab-core' ), $ver, true );

	$lang = function_exists( 'pll_current_language' ) ? (string) pll_current_language() : substr( determine_locale(), 0, 2 );

	wp_localize_script(
		'prime-image-lab',
		'primeImageLab',
		array(
			'lang'      => 'ar' === $lang ? 'ar' : 'en',
			'whatsapp'  => esc_url_raw( prime_image_lab_whatsapp() ),
			'shop'      => esc_url_raw( prime_image_lab_shop_url() ),
			'workerUrl' => $base . 'image-lab-worker.js?ver=' . $ver,
		)
	);
}
add_action( 'wp_enqueue_scripts', 'prime_image_lab_enqueue' );

/**
 * [prime_image_lab]: the mount point the script fills in.
 *
 * @return string
 */
function prime_image_lab_shortcode() {
	ob_start();
	?>
	<div id="pil-root" class="pil">
		<noscript>
			<p>
				<?php esc_html_e( 'PRIME Image Lab needs JavaScript. Everything runs in your browser and nothing is uploaded.', 'prime-image-lab' ); ?>
				<br>
				<?php echo esc_html( 'مختبر الصور من برايم يحتاج تفعيل JavaScript. كل المعالجة تتم داخل متصفحك ولا يتم رفع أي شيء.' ); ?>
			</p>
		</noscript>
	</div>
	<?php
	return (string) ob_get_clean();
}
add_shortcode( 'prime_image_lab', 'prime_image_lab_shortcode' );
