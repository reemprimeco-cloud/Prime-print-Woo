<?php
/**
 * Talks to the Node render service over HTTPS with a shared-secret header
 * (§2, §5). Nothing here waits for a render: finalize hands the job over with a
 * callback URL and returns immediately.
 *
 * @package PrimeBinderDesigner
 */

defined( 'ABSPATH' ) || exit;

class Binder_Render_Client {

	public static function url() {
		$url = defined( 'BINDER_RENDER_URL' ) ? BINDER_RENDER_URL : (string) get_option( Binder_Settings::OPT_URL, '' );

		return untrailingslashit( $url );
	}

	public static function secret() {
		return defined( 'BINDER_RENDER_SECRET' ) ? (string) BINDER_RENDER_SECRET : (string) get_option( Binder_Settings::OPT_SECRET, '' );
	}

	public static function configured() {
		return '' !== self::url() && '' !== self::secret();
	}

	/**
	 * @param string $path    Service path, e.g. '/render'.
	 * @param array  $body    JSON body.
	 * @param int    $timeout Seconds.
	 * @return array|WP_Error wp_remote_* response.
	 */
	private static function post( $path, array $body, $timeout ) {
		return wp_remote_post(
			self::url() . $path,
			array(
				'timeout'     => $timeout,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type'    => 'application/json',
					'X-Binder-Secret' => self::secret(),
				),
				'body'        => wp_json_encode( $body ),
			)
		);
	}

	/**
	 * The request body both /render and /preview take.
	 *
	 * @param array $design A wp_binder_designs row.
	 * @return array
	 */
	private static function payload( array $design ) {
		$title = sprintf( 'Binder design #%d', (int) $design['id'] );
		$name  = get_the_title( (int) $design['product_id'] );

		return array(
			'design_id'     => (int) $design['id'],
			'template'      => $design['template'],
			'session_token' => $design['session_token'],
			'design_json'   => json_decode( (string) $design['design_json'], true ),
			'title'         => $name ? $name . ' — ' . $title : $title,
		);
	}

	/**
	 * GET /healthz.
	 *
	 * @return array|WP_Error
	 */
	public static function health() {
		if ( '' === self::url() ) {
			return new WP_Error( 'binder_not_configured', __( 'Render service URL is not set.', 'prime-binder-designer' ) );
		}

		$res = wp_remote_get( self::url() . '/healthz', array( 'timeout' => 10, 'redirection' => 0 ) );

		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( 200 !== wp_remote_retrieve_response_code( $res ) ) {
			return new WP_Error( 'binder_health_failed', sprintf( /* translators: %d: HTTP status */ __( 'Render service answered %d.', 'prime-binder-designer' ), wp_remote_retrieve_response_code( $res ) ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $res ), true );

		return is_array( $data ) ? $data : new WP_Error( 'binder_health_failed', __( 'Unexpected response from the render service.', 'prime-binder-designer' ) );
	}

	/**
	 * Start a production render. The service answers 202 at once and calls
	 * back when the PDFs exist.
	 *
	 * @param array $design A wp_binder_designs row.
	 * @return array {status:int, body:array}|WP_Error
	 */
	public static function start_render( array $design ) {
		$payload                 = self::payload( $design );
		$payload['callback_url'] = rest_url( 'binder/v1/render-callback' );

		$res = self::post( '/render', $payload, 20 );

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		return array(
			'status' => (int) wp_remote_retrieve_response_code( $res ),
			'body'   => (array) json_decode( wp_remote_retrieve_body( $res ), true ),
			'retry'  => (int) wp_remote_retrieve_header( $res, 'retry-after' ),
		);
	}

	/**
	 * Fast RGB PNG proof.
	 *
	 * @param array $design A wp_binder_designs row.
	 * @return array {status:int, body:string, json:array}|WP_Error
	 */
	public static function preview( array $design ) {
		$res = self::post( '/preview', self::payload( $design ), 60 );

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		return array(
			'status' => (int) wp_remote_retrieve_response_code( $res ),
			'body'   => wp_remote_retrieve_body( $res ),
			'type'   => (string) wp_remote_retrieve_header( $res, 'content-type' ),
		);
	}

	/**
	 * GET /jobs/{id}, the fallback when a callback never arrived.
	 *
	 * @param string $job_id Job id.
	 * @return array|WP_Error
	 */
	public static function job( $job_id ) {
		$res = wp_remote_get(
			self::url() . '/jobs/' . rawurlencode( $job_id ),
			array( 'timeout' => 10, 'redirection' => 0, 'headers' => array( 'X-Binder-Secret' => self::secret() ) )
		);

		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( 200 !== wp_remote_retrieve_response_code( $res ) ) {
			return new WP_Error( 'binder_job_unknown', __( 'The render service does not know this job.', 'prime-binder-designer' ) );
		}

		return (array) json_decode( wp_remote_retrieve_body( $res ), true );
	}

	/**
	 * Download a finished PDF from a signed service link into a temp file.
	 *
	 * @param string $url Signed URL from the service.
	 * @return string|WP_Error Path of the temp file.
	 */
	public static function download( $url ) {
		// wp_tempnam() lives in the admin file API, which REST and front-end requests do not load.
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$tmp = wp_tempnam( 'binder-pdf' );
		$res = wp_remote_get( $url, array( 'timeout' => 180, 'redirection' => 0, 'stream' => true, 'filename' => $tmp ) );

		if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			return is_wp_error( $res ) ? $res : new WP_Error( 'binder_download_failed', __( 'Could not download the PDF from the render service.', 'prime-binder-designer' ) );
		}

		$head = (string) file_get_contents( $tmp, false, null, 0, 5 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( '%PDF-' !== $head ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			return new WP_Error( 'binder_download_invalid', __( 'The render service returned something that is not a PDF.', 'prime-binder-designer' ) );
		}

		return $tmp;
	}

	/**
	 * Verify the HMAC on a callback body.
	 *
	 * @param string $raw    Raw request body.
	 * @param string $header X-Binder-Signature value ("sha256=<hex>").
	 * @return bool
	 */
	public static function verify_signature( $raw, $header ) {
		if ( '' === self::secret() || 0 !== strpos( (string) $header, 'sha256=' ) ) {
			return false;
		}

		return hash_equals( hash_hmac( 'sha256', $raw, self::secret() ), substr( (string) $header, 7 ) );
	}
}
