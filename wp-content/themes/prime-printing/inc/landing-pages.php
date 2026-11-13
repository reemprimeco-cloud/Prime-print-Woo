<?php
/**
 * Standalone landing pages.
 *
 * A landing page here is a finished HTML document (its own header, fonts and
 * styles) kept in `landing/<slug>/index.html` with its `assets/` and
 * `downloads/` beside it. It is served at `/<slug>/` by a rewrite rule, so it
 * needs no page in the database and no WordPress theme chrome around it.
 *
 * Relative `assets/` and `downloads/` references in the file are pointed at the
 * theme folder when it is served; the HTML on disk stays exactly as designed.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

/**
 * The landing pages this theme serves: slug => folder under landing/.
 *
 * @return string[]
 */
function prime_landing_pages() {
	return array(
		'designer-skill' => 'designer-skill',
	);
}

/**
 * Rewrite rules, flushed once per version of this list.
 */
function prime_landing_rewrites() {
	foreach ( array_keys( prime_landing_pages() ) as $slug ) {
		add_rewrite_rule( '^' . preg_quote( $slug, '/' ) . '/?$', 'index.php?prime_landing=' . $slug, 'top' );
	}

	$version = 'v1:' . implode( ',', array_keys( prime_landing_pages() ) );

	if ( get_option( 'prime_landing_rules' ) !== $version ) {
		flush_rewrite_rules( false );
		update_option( 'prime_landing_rules', $version, false );
	}
}
add_action( 'init', 'prime_landing_rewrites' );

/**
 * @param string[] $vars Public query variables.
 * @return string[]
 */
function prime_landing_query_vars( $vars ) {
	$vars[] = 'prime_landing';

	return $vars;
}
add_filter( 'query_vars', 'prime_landing_query_vars' );

/**
 * Serve the landing page for a matching request, before WordPress picks a template
 * or any canonical redirect runs.
 */
function prime_landing_serve() {
	$slug  = (string) get_query_var( 'prime_landing' );
	$pages = prime_landing_pages();

	if ( '' === $slug || ! isset( $pages[ $slug ] ) ) {
		return;
	}

	$dir  = PRIME_DIR . '/landing/' . $pages[ $slug ];
	$file = $dir . '/index.html';

	if ( ! is_readable( $file ) ) {
		return;
	}

	$base = PRIME_URI . '/landing/' . $pages[ $slug ] . '/';
	$html = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local theme file.
	$html = str_replace(
		array( 'href="assets/', 'src="assets/', 'url(assets/', 'href="downloads/' ),
		array( 'href="' . esc_url( $base ) . 'assets/', 'src="' . esc_url( $base ) . 'assets/', 'url(' . esc_url( $base ) . 'assets/', 'href="' . esc_url( $base ) . 'downloads/' ),
		$html
	);

	status_header( 200 );
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'Cache-Control: public, max-age=300' );
	header( 'X-Robots-Tag: index, follow' );

	echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a finished static document from the theme folder.
	exit;
}
add_action( 'template_redirect', 'prime_landing_serve', 1 );
