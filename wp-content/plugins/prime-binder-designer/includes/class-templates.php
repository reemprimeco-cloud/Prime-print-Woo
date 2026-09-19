<?php
/**
 * The two print templates and their spec.json files (§1).
 *
 * spec.json is the source of truth for every dimension. This class only
 * locates and reads those files; it never computes or stores a number of its
 * own.
 *
 * @package PrimeBinderDesigner
 */

defined( 'ABSPATH' ) || exit;

class Binder_Templates {

	/**
	 * Template key => file stem inside templates/.
	 */
	const TEMPLATES = array(
		'binder_outer' => 'binder-outer',
		'binder_inner' => 'binder-inner',
	);

	/**
	 * @return string[] Valid template keys.
	 */
	public static function keys() {
		return array_keys( self::TEMPLATES );
	}

	/**
	 * @param string $template Template key.
	 * @return bool
	 */
	public static function exists( $template ) {
		return isset( self::TEMPLATES[ $template ] );
	}

	/**
	 * Absolute path of one of a template's files.
	 *
	 * @param string $template Template key.
	 * @param string $kind     'spec' | 'overlay' | 'pdf' | 'svg'.
	 * @return string Path, or '' when the template or kind is unknown.
	 */
	public static function path( $template, $kind ) {
		$files = array(
			'spec'    => '-spec.json',
			'overlay' => '-overlay.png',
			'pdf'     => '-template.pdf',
			'svg'     => '-template.svg',
		);

		if ( ! self::exists( $template ) || ! isset( $files[ $kind ] ) ) {
			return '';
		}

		return PRIME_BINDER_DIR . 'templates/' . self::TEMPLATES[ $template ] . $files[ $kind ];
	}

	/**
	 * The parsed spec.json.
	 *
	 * @param string $template Template key.
	 * @return array|null Null when the template is unknown or the file is unreadable.
	 */
	public static function spec( $template ) {
		$path = self::path( $template, 'spec' );

		if ( '' === $path || ! is_readable( $path ) ) {
			return null;
		}

		$spec = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file.

		return is_array( $spec ) ? $spec : null;
	}

	/**
	 * A time-limited signed URL for the overlay PNG. The overlay is not secret
	 * but it is the shop's own template artwork; signing keeps it from being
	 * hot-linked by anyone who has not loaded the editor.
	 *
	 * @param string $template Template key.
	 * @param int    $ttl      Seconds the URL stays valid.
	 * @return string
	 */
	public static function overlay_url( $template, $ttl = 3600 ) {
		$expires = time() + (int) $ttl;

		return add_query_arg(
			array(
				'exp' => $expires,
				'sig' => self::sign( $template, $expires ),
			),
			rest_url( 'binder/v1/template/' . $template . '/overlay' )
		);
	}

	/**
	 * @param string $template Template key.
	 * @param int    $expires  Unix timestamp.
	 * @return string HMAC-SHA256, hex.
	 */
	public static function sign( $template, $expires ) {
		return hash_hmac( 'sha256', $template . '|' . (int) $expires, wp_salt( 'auth' ) );
	}

	/**
	 * @param string $template Template key.
	 * @param int    $expires  Unix timestamp from the URL.
	 * @param string $sig      Signature from the URL.
	 * @return bool
	 */
	public static function verify( $template, $expires, $sig ) {
		if ( (int) $expires < time() ) {
			return false;
		}

		return hash_equals( self::sign( $template, $expires ), (string) $sig );
	}
}
