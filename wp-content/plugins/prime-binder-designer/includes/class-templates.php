<?php
/**
 * The print templates (§1): the two binder covers with their spec.json files,
 * and the sticker, whose spec is derived from the size and shape the customer
 * chose (class-sticker.php).
 *
 * spec.json is the source of truth for every binder dimension. This class only
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
		'sticker'      => '',
		'uvdtf'        => '',
	);

	/**
	 * Templates whose geometry depends on values from the product page.
	 *
	 * @param string $template Template key.
	 * @return bool
	 */
	public static function is_parametric( $template ) {
		return 'sticker' === $template || 'uvdtf' === $template;
	}

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
	 * @param string $binding  'ltr' (English binder, the default drawing) or 'rtl' (Arabic: cover panels swapped). The spec is shared.
	 * @return string Path, or '' when the template or kind is unknown.
	 */
	public static function path( $template, $kind, $binding = 'ltr' ) {
		$files = array(
			'spec'    => '-spec.json',
			'overlay' => '-overlay.png',
			'pdf'     => '-template.pdf',
			'svg'     => '-template.svg',
		);

		if ( ! self::exists( $template ) || self::is_parametric( $template ) || ! isset( $files[ $kind ] ) ) {
			return '';
		}

		$variant = ( 'rtl' === $binding && 'spec' !== $kind ) ? '-rtl' : '';

		return PRIME_BINDER_DIR . 'templates/' . self::TEMPLATES[ $template ] . $variant . $files[ $kind ];
	}

	/**
	 * @param mixed $v A binding value from a request or a design.
	 * @return bool
	 */
	public static function is_binding( $v ) {
		return 'ltr' === $v || 'rtl' === $v;
	}

	/**
	 * The parsed spec.json.
	 *
	 * @param string     $template Template key.
	 * @param array|null $params   Sticker only: normalised size and shape (Binder_Sticker::normalize()).
	 * @return array|null Null when the template is unknown, the file is unreadable, or a sticker has no params.
	 */
	public static function spec( $template, $params = null ) {
		if ( self::is_parametric( $template ) ) {
			return is_array( $params ) ? Binder_Sticker::spec( $params, $template ) : null;
		}

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
	 * @param string $binding  'ltr' | 'rtl'.
	 * @return string
	 */
	public static function overlay_url( $template, $ttl = 3600, $binding = 'ltr' ) {
		if ( self::is_parametric( $template ) ) {
			return ''; // The editor draws the sticker guide from the spec.
		}

		$expires = time() + (int) $ttl;
		$binding = self::is_binding( $binding ) ? $binding : 'ltr';

		return add_query_arg(
			array(
				'binding' => $binding,
				'exp'     => $expires,
				'sig'     => self::sign( $template, $expires, $binding ),
			),
			rest_url( 'binder/v1/template/' . $template . '/overlay' )
		);
	}

	/**
	 * @param string $template Template key.
	 * @param int    $expires  Unix timestamp.
	 * @param string $binding  'ltr' | 'rtl'.
	 * @return string HMAC-SHA256, hex.
	 */
	public static function sign( $template, $expires, $binding = 'ltr' ) {
		return hash_hmac( 'sha256', $template . '|' . (int) $expires . '|' . $binding, wp_salt( 'auth' ) );
	}

	/**
	 * @param string $template Template key.
	 * @param int    $expires  Unix timestamp from the URL.
	 * @param string $sig      Signature from the URL.
	 * @param string $binding  'ltr' | 'rtl' from the URL.
	 * @return bool
	 */
	public static function verify( $template, $expires, $sig, $binding = 'ltr' ) {
		if ( (int) $expires < time() ) {
			return false;
		}

		return hash_equals( self::sign( $template, $expires, $binding ), (string) $sig );
	}
}
