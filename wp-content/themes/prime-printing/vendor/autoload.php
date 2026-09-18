<?php
/**
 * Minimal PSR-4 autoloader for the theme's vendored PDF-generation stack.
 *
 * There's no Composer on this project's dev machine (no PHP CLI to run it
 * against, and the actual site runs inside a WASM PHP sandbox — see
 * SETUP.md), so `dompdf/dompdf` and its four dependencies are vendored here
 * as plain source trees pulled directly from their tagged GitHub releases,
 * with this file standing in for `vendor/autoload.php`. The namespace-to-
 * directory map below mirrors each package's own composer.json exactly; if
 * any of these libraries are ever upgraded, re-check their composer.json
 * `autoload` block against this map.
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	function ( $class ) {
		static $prefixes = array(
			'Dompdf\\'        => array( __DIR__ . '/dompdf/dompdf/src/', __DIR__ . '/dompdf/dompdf/lib/' ),
			'FontLib\\'       => array( __DIR__ . '/dompdf/php-font-lib/src/FontLib/' ),
			'Svg\\'           => array( __DIR__ . '/dompdf/php-svg-lib/src/Svg/' ),
			'Masterminds\\'   => array( __DIR__ . '/masterminds/html5/src/' ),
			'Sabberworm\\CSS\\' => array( __DIR__ . '/sabberworm/php-css-parser/src/' ),
		);

		foreach ( $prefixes as $prefix => $base_dirs ) {
			if ( 0 !== strncmp( $prefix, $class, strlen( $prefix ) ) ) {
				continue;
			}

			$relative = substr( $class, strlen( $prefix ) );
			$relative_path = str_replace( '\\', '/', $relative ) . '.php';

			foreach ( $base_dirs as $base_dir ) {
				$file = $base_dir . $relative_path;
				if ( is_readable( $file ) ) {
					require $file;
					return;
				}
			}
		}
	}
);
