<?php
/**
 * Closes the page shell.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

// App mode (inc/native-app-ui.php) ends the page with its tab bar instead.
if ( ! function_exists( 'prime_is_native_app_request' ) || ! prime_is_native_app_request() ) {
	get_template_part( 'template-parts/footer' );
}

wp_footer();
?>
</body>
</html>
