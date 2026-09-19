<?php
/**
 * Customer artwork uploads for upload mode and for images placed in live mode.
 *
 * @package PrimeBinderDesigner
 */

defined( 'ABSPATH' ) || exit;

class Binder_Uploads {

	const ALLOWED = array(
		'jpg|jpeg' => 'image/jpeg',
		'png'      => 'image/png',
		'webp'     => 'image/webp',
	);

	/** Print artwork is big; a 300 dpi wrap-around cover is tens of MB. */
	const MAX_BYTES = 157286400; // 150 MB, the render service's own ceiling.

	/**
	 * Validate and store one uploaded file.
	 *
	 * @param array $file A $_FILES entry.
	 * @return array|WP_Error {url, source_px:{w,h}, bytes, mime}
	 */
	public static function store( array $file ) {
		if ( empty( $file['tmp_name'] ) || ! empty( $file['error'] ) ) {
			return new WP_Error( 'binder_upload_failed', __( 'The upload did not complete.', 'prime-binder-designer' ), array( 'status' => 400 ) );
		}

		$limit = min( self::MAX_BYTES, wp_max_upload_size() );
		if ( (int) $file['size'] > $limit ) {
			return new WP_Error( 'binder_upload_size', __( 'This file is too large.', 'prime-binder-designer' ), array( 'status' => 413, 'max_bytes' => $limit ) );
		}

		$check = wp_check_filetype_and_ext( $file['tmp_name'], (string) $file['name'], self::ALLOWED );
		if ( empty( $check['type'] ) || ! in_array( $check['type'], self::ALLOWED, true ) ) {
			return new WP_Error( 'binder_upload_type', __( 'Please upload a JPG, PNG or WebP image.', 'prime-binder-designer' ), array( 'status' => 415 ) );
		}

		$info = @getimagesize( $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $info || $info[0] < 1 || $info[1] < 1 ) {
			return new WP_Error( 'binder_upload_type', __( 'This file could not be read as an image.', 'prime-binder-designer' ), array( 'status' => 415 ) );
		}

		list( $w, $h ) = $info;

		// EXIF orientation 5-8: the browser shows the picture turned 90 degrees, so
		// the size a customer sees (and the render service measures) is swapped.
		if ( 'image/jpeg' === $check['type'] && function_exists( 'exif_read_data' ) ) {
			$exif = @exif_read_data( $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( is_array( $exif ) && isset( $exif['Orientation'] ) && (int) $exif['Orientation'] >= 5 ) {
				list( $w, $h ) = array( $h, $w );
			}
		}

		$upload = wp_upload_dir();
		$sub    = 'binder-designs/' . gmdate( 'Y/m' );
		$dir    = trailingslashit( $upload['basedir'] ) . $sub;
		wp_mkdir_p( $dir );

		$ext  = 'image/jpeg' === $check['type'] ? 'jpg' : ( 'image/png' === $check['type'] ? 'png' : 'webp' );
		$name = bin2hex( random_bytes( 16 ) ) . '.' . $ext;

		$moved = is_uploaded_file( $file['tmp_name'] )
			? move_uploaded_file( $file['tmp_name'], $dir . '/' . $name )
			: copy( $file['tmp_name'], $dir . '/' . $name ); // Tests hand in a plain file.

		if ( ! $moved ) {
			return new WP_Error( 'binder_upload_failed', __( 'The file could not be saved.', 'prime-binder-designer' ), array( 'status' => 500 ) );
		}

		// A browser cannot comfortably hold a 34-megapixel bitmap (phones give up), so the
		// editor shows a downsized copy while the design keeps pointing at the original.
		$proxy = self::make_proxy( $dir . '/' . $name, $dir, $w * $h );

		return array(
			'url'       => trailingslashit( $upload['baseurl'] ) . $sub . '/' . $name,
			'proxy_url' => $proxy ? trailingslashit( $upload['baseurl'] ) . $sub . '/' . $proxy : null,
			'source_px' => array( 'w' => (int) $w, 'h' => (int) $h ),
			'bytes'     => (int) filesize( $dir . '/' . $name ),
			'mime'      => $check['type'],
		);
	}

	/**
	 * Longest side of the on-screen copy, in pixels.
	 */
	const PROXY_SIDE = 2400;

	/**
	 * Make the on-screen copy. Best effort: any failure just means the editor
	 * shows the original.
	 *
	 * @param string $path   Original file.
	 * @param string $dir    Directory to write into.
	 * @param int    $pixels Original pixel count.
	 * @return string Proxy file name, or '' when none was made.
	 */
	private static function make_proxy( $path, $dir, $pixels ) {
		// Skip when it is already small, or so large that decoding it here could exhaust PHP memory.
		if ( $pixels <= self::PROXY_SIDE * self::PROXY_SIDE || $pixels > 90000000 ) {
			return '';
		}

		wp_raise_memory_limit( 'image' );

		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			return '';
		}
		if ( method_exists( $editor, 'maybe_exif_rotate' ) ) {
			$editor->maybe_exif_rotate();
		}
		if ( is_wp_error( $editor->resize( self::PROXY_SIDE, self::PROXY_SIDE, false ) ) ) {
			return '';
		}
		$editor->set_quality( 85 );

		$name  = pathinfo( $path, PATHINFO_FILENAME ) . '-screen.' . ( 'png' === pathinfo( $path, PATHINFO_EXTENSION ) ? 'png' : 'jpg' );
		$saved = $editor->save( $dir . '/' . $name, 'png' === pathinfo( $name, PATHINFO_EXTENSION ) ? 'image/png' : 'image/jpeg' );

		return is_wp_error( $saved ) ? '' : $name;
	}
}
