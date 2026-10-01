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

	/**
	 * A time-limited link the shop can open without logging in (used in the
	 * WhatsApp message to the shop's own phone). It is a bearer secret: anyone
	 * holding it can download that one file until it expires.
	 *
	 * @param int    $id   Design id.
	 * @param string $kind 'rgb' | 'cmyk'.
	 * @param int    $ttl  Seconds it stays valid.
	 * @return string '' when there is no such file.
	 */
	public static function signed_url( $id, $kind, $ttl = 7 * DAY_IN_SECONDS ) {
		if ( ! self::exists( $id, $kind ) ) {
			return '';
		}

		$exp = time() + (int) $ttl;

		return add_query_arg( array( 'exp' => $exp, 'sig' => self::sign( $id, $kind, $exp ) ), self::base_url( $id, $kind ) );
	}

	private static function sign( $id, $kind, $exp ) {
		return hash_hmac( 'sha256', "binder-dl|{$id}|{$kind}|{$exp}", wp_salt( 'auth' ) );
	}

	public static function is_shop_staff() {
		return current_user_can( 'edit_shop_orders' ) || current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
	}

	/**
	 * What the downloaded PDF is called: the order number, the customer's name
	 * and the product, then "print" (CMYK) or "proof" (RGB) — so a file on the
	 * shop's desk says whose job it is (Reem, 2026-10-01), never "binder-16".
	 * Before the design is on an order: design-16-<product>-print.pdf.
	 *
	 * @param array  $design wp_binder_designs row.
	 * @param string $kind   'cmyk' | 'rgb'.
	 * @return string
	 */
	public static function download_name( array $design, $kind ) {
		$parts = array();
		$order = ! empty( $design['order_id'] ) && function_exists( 'wc_get_order' ) ? wc_get_order( (int) $design['order_id'] ) : null;

		if ( $order ) {
			$parts[]  = 'order-' . $order->get_order_number();
			$customer = trim( $order->get_formatted_billing_full_name() );
			if ( '' === $customer ) {
				$customer = trim( $order->get_formatted_shipping_full_name() );
			}
			if ( '' !== $customer ) {
				$parts[] = $customer;
			}
		} else {
			$parts[] = 'design-' . (int) $design['id'];
		}

		$product = get_the_title( (int) $design['product_id'] );
		if ( '' !== $product ) {
			$parts[] = $product;
		}

		$parts[] = 'cmyk' === $kind ? 'print' : 'proof';

		$name = implode( ' ', $parts );
		$name = str_replace( array( '\\', '/', ':', '*', '?', '"', '<', '>', '|' ), '', $name ); // What no file system accepts.
		$name = preg_replace( '/\s+/u', '-', trim( $name ) );

		return $name . '.pdf';
	}

	/**
	 * A plain-ASCII fallback for browsers that ignore filename*: Arabic and
	 * other non-ASCII letters are dropped rather than mangled.
	 *
	 * @param string $name UTF-8 file name.
	 * @return string
	 */
	private static function ascii_name( $name ) {
		$ascii = preg_replace( '/[^A-Za-z0-9._-]+/', '-', remove_accents( $name ) );
		$ascii = trim( preg_replace( '/-+/', '-', $ascii ), '-' );

		return '' === $ascii || '.pdf' === $ascii ? 'print-file.pdf' : $ascii;
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
			if ( isset( $_GET['sig'], $_GET['exp'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$exp     = absint( wp_unslash( $_GET['exp'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
				$allowed = $exp >= time() && hash_equals( self::sign( $id, $kind, $exp ), sanitize_text_field( wp_unslash( $_GET['sig'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
			} elseif ( self::is_shop_staff() ) {
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
		$name = self::download_name( $design, $kind );
		header( 'Content-Disposition: attachment; filename="' . self::ascii_name( $name ) . '"; filename*=UTF-8\'\'' . rawurlencode( $name ) );
		header( 'X-Robots-Tag: noindex' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}
}
