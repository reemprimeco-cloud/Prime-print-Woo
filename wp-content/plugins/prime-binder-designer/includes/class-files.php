<?php
/**
 * Where finished PDFs and customer uploads live, and how they are served.
 *
 * Print PDFs are customer artwork, so they are kept under an unguessable name
 * and only ever sent through the download handler below, which checks who is
 * asking. (An .htaccess deny is written too, but it is not relied on: this
 * host may not read it.) The CMYK production file is for the shop only; the
 * customer gets the RGB proof.
 *
 * @package PrimeBinderDesigner
 */

defined( 'ABSPATH' ) || exit;

class Binder_Files {

	const KINDS = array( 'rgb', 'cmyk' );

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_download' ), 1 );
	}

	/**
	 * @return string Absolute private directory, created on demand.
	 */
	public static function private_dir() {
		$dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'binder-designs/private';

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			file_put_contents( $dir . '/index.php', "<?php // Silence.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		return $dir;
	}

	/**
	 * Deterministic, unguessable file path for a design's PDF.
	 *
	 * @param int    $id   Design id.
	 * @param string $kind 'rgb' | 'cmyk'.
	 * @return string
	 */
	public static function path( $id, $kind ) {
		$name = substr( hash_hmac( 'sha256', "binder|{$id}|{$kind}", wp_salt( 'auth' ) ), 0, 32 );

		return self::private_dir() . '/' . $name . '.pdf';
	}

	/**
	 * Move a downloaded PDF into private storage.
	 *
	 * @param int    $id   Design id.
	 * @param string $kind 'rgb' | 'cmyk'.
	 * @param string $tmp  Temp file path.
	 * @return bool
	 */
	public static function adopt( $id, $kind, $tmp ) {
		$dest = self::path( $id, $kind );

		return @rename( $tmp, $dest ) || ( copy( $tmp, $dest ) && @unlink( $tmp ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
	}

	public static function exists( $id, $kind ) {
		return is_readable( self::path( $id, $kind ) );
	}

	/**
	 * Stable link base stored in the table (no credentials in it).
	 *
	 * @param int    $id   Design id.
	 * @param string $kind 'rgb' | 'cmyk'.
	 * @return string
	 */
	public static function base_url( $id, $kind ) {
		return add_query_arg( array( 'binder_dl' => $kind, 'id' => (int) $id ), home_url( '/' ) );
	}

	/**
	 * A download link the current viewer can use.
	 *
	 * @param array  $design Row.
	 * @param string $kind   'rgb' | 'cmyk'.
	 * @return string '' when there is no such file or the viewer may not have it.
	 */
	public static function link_for( array $design, $kind ) {
		if ( ! self::exists( $design['id'], $kind ) ) {
			return '';
		}

		$id = (int) $design['id'];

		if ( self::is_shop_staff() ) {
			return add_query_arg( '_wpnonce', wp_create_nonce( "binder_dl_{$id}_{$kind}" ), self::base_url( $id, $kind ) );
		}

		return 'rgb' === $kind ? add_query_arg( 't', $design['session_token'], self::base_url( $id, $kind ) ) : '';
	}

	public static function is_shop_staff() {
		return current_user_can( 'edit_shop_orders' ) || current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Stream a stored PDF if the request is allowed to have it.
	 */
	public static function maybe_download() {
		if ( empty( $_GET['binder_dl'] ) || empty( $_GET['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		$kind = sanitize_key( wp_unslash( $_GET['binder_dl'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$id   = absint( wp_unslash( $_GET['id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! in_array( $kind, self::KINDS, true ) ) {
			wp_die( esc_html__( 'Not found.', 'prime-binder-designer' ), '', array( 'response' => 404 ) );
		}

		$design  = Binder_DB::get( $id );
		$allowed = false;

		if ( $design ) {
			if ( self::is_shop_staff() ) {
				$allowed = isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), "binder_dl_{$id}_{$kind}" ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			} elseif ( 'rgb' === $kind && isset( $_GET['t'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$allowed = hash_equals( (string) $design['session_token'], sanitize_text_field( wp_unslash( $_GET['t'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
			}
		}

		// One answer for "no such design", "not yours" and "no file yet".
		if ( ! $allowed || ! self::exists( $id, $kind ) ) {
			wp_die( esc_html__( 'Not found.', 'prime-binder-designer' ), '', array( 'response' => 404 ) );
		}

		$path = self::path( $id, $kind );

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Disposition: attachment; filename="binder-' . $id . '-' . $kind . '.pdf"' );
		header( 'X-Robots-Tag: noindex' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}
}
