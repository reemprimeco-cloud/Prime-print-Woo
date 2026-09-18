<?php
/**
 * The document head and the opening of the page shell.
 *
 * The visible header itself is template-parts/header.php; this file is only the
 * document boilerplate around it, so the header markup stays reusable.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="profile" href="https://gmpg.org/xfn/11">

	<?php
	/*
	 * Flag JS before first paint. The mobile burger opens a scripted overlay, so
	 * the inline nav links must stay visible when scripting is unavailable —
	 * layout.css keys the small-screen swap off this class rather than assuming.
	 */
	?>
	<script>document.documentElement.className += ' prime-js';</script>

	<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<a class="screen-reader-text" href="#prime-content"><?php esc_html_e( 'Skip to content', 'prime-printing' ); ?></a>

<?php
// App mode (inc/native-app-ui.php) has its own top bar and tab bar; the web
// header and the full-screen mobile menu are not rendered at all there.
if ( ! function_exists( 'prime_is_native_app_request' ) || ! prime_is_native_app_request() ) {
	get_template_part( 'template-parts/header' );
	get_template_part( 'template-parts/mobile-menu' );
}
