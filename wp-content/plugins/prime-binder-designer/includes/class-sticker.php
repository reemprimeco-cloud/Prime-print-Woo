<?php
/**
 * The sticker template: one shape cut to the size the customer chose on the
 * product page. There is no spec.json for it; the spec is derived from three
 * values (width, height, shape), exactly as binder-shared/src/sticker.ts does
 * for the editor and the render service. Keep the two in step.
 *
 * Where the size comes from: the theme's sticker calculators post width and
 * height in centimetres plus a shape, under field names that differ per
 * product (paper_width, pp_width, uvdtf_width, diecut_width). This class
 * knows those names (filter `binder_sticker_fields` to change them), converts
 * to millimetres, and checks that a design was made for the size being
 * ordered.
 *
 * @package PrimeBinderDesigner
 */

defined( 'ABSPATH' ) || exit;

class Binder_Sticker {

	const BLEED_MM = 1.0;
	const SAFE_MM  = 2.0;
	const MIN_MM   = 10.0;
	const MAX_MM   = 1000.0;
	const DPI      = 300;

	const SHAPES = array( 'rectangle', 'square', 'round', 'hexagon', 'triangle', 'star', 'heart', 'custom' );

	/**
	 * The UV DTF transfer (binder-shared/src/sticker.ts UVDTF): a rectangle of
	 * the typed size, no bleed, no safe zone, no cut line. Reem, 2026-10-01.
	 */
	const UVDTF_MIN_MM = 10.0;
	const UVDTF_MAX_MM = 600.0;

	/** Names the calculators use for the same shapes. */
	const ALIASES = array(
		'circle'  => 'round',
		'oval'    => 'round',
		'ellipse' => 'round',
		'rect'    => 'rectangle',
	);

	/**
	 * Validate and tidy a size and shape. Millimetres, rounded to 0.1.
	 *
	 * @param mixed $w_mm  Width in mm.
	 * @param mixed $h_mm  Height in mm.
	 * @param mixed $shape Shape name.
	 * @return array{w_mm: float, h_mm: float, shape: string}|null
	 */
	public static function normalize( $w_mm, $h_mm, $shape, $template = 'sticker' ) {
		if ( ! is_numeric( $w_mm ) || ! is_numeric( $h_mm ) ) {
			return null;
		}
		$w = round( (float) $w_mm, 1 );
		$h = round( (float) $h_mm, 1 );

		if ( 'uvdtf' === $template ) {
			// A transfer is not cut to a shape: whatever was sent, it is a rectangle.
			if ( $w < self::UVDTF_MIN_MM || $h < self::UVDTF_MIN_MM || $w > self::UVDTF_MAX_MM || $h > self::UVDTF_MAX_MM ) {
				return null;
			}

			return array( 'w_mm' => $w, 'h_mm' => $h, 'shape' => 'rectangle' );
		}

		if ( $w < self::MIN_MM || $h < self::MIN_MM || $w > self::MAX_MM || $h > self::MAX_MM ) {
			return null;
		}

		$shape = strtolower( trim( (string) ( '' === $shape || null === $shape ? 'rectangle' : $shape ) ) );
		$shape = isset( self::ALIASES[ $shape ] ) ? self::ALIASES[ $shape ] : $shape;
		if ( ! in_array( $shape, self::SHAPES, true ) ) {
			return null;
		}

		return array(
			'w_mm'  => $w,
			'h_mm'  => $h,
			'shape' => $shape,
		);
	}

	/**
	 * @param array $a Normalised params.
	 * @param array $b Normalised params.
	 * @return bool
	 */
	public static function same( array $a, array $b ) {
		return $a['shape'] === $b['shape'] && abs( $a['w_mm'] - $b['w_mm'] ) < 0.05 && abs( $a['h_mm'] - $b['h_mm'] ) < 0.05;
	}

	/**
	 * The spec for one sticker — the same shape as a binder spec.json.
	 *
	 * @param array $p Normalised params.
	 * @return array
	 */
	public static function spec( array $p, $template = 'sticker' ) {
		$w  = (float) $p['w_mm'];
		$h  = (float) $p['h_mm'];
		$b  = 'uvdtf' === $template ? 0.0 : self::BLEED_MM;
		$s  = 'uvdtf' === $template ? 0.0 : self::SAFE_MM;
		$cw = round( $w + 2 * $b, 1 );
		$ch = round( $h + 2 * $b, 1 );

		return array(
			'template'                       => 'uvdtf' === $template ? 'uvdtf' : 'sticker',
			'unit'                           => 'mm',
			'dpi'                            => self::DPI,
			'color'                          => 'uvdtf' === $template ? 'CMYK (FOGRA39 / ISO Coated v2) + White + Varnish' : 'CMYK (FOGRA39 / ISO Coated v2)',
			'bleed_mm'                       => $b,
			'safe_margin_mm'                 => $s,
			'turn_in_mm'                     => 0,
			'trim_mm'                        => array( 'w' => $w, 'h' => $h ),
			'canvas_with_bleed_mm'           => array( 'w' => $cw, 'h' => $ch ),
			'canvas_with_bleed_px'           => array(
				'w' => (int) round( $cw / 25.4 * self::DPI ),
				'h' => (int) round( $ch / 25.4 * self::DPI ),
			),
			'panels_relative_to_trim'        => array(
				array(
					'name'    => 'uvdtf' === $template ? 'transfer' : 'sticker',
					'trim_mm' => array( 'x' => 0, 'y' => 0, 'w' => $w, 'h' => $h ),
					'safe_mm' => array( 'x' => $s, 'y' => $s, 'w' => round( max( 0, $w - 2 * $s ), 1 ), 'h' => round( max( 0, $h - 2 * $s ), 1 ) ),
				),
			),
			'fold_lines_x_mm_from_trim_left' => array(),
			'fold_lines_y_mm_from_trim_top'  => array(),
			'sticker'                        => array( 'w_mm' => $w, 'h_mm' => $h, 'shape' => $p['shape'] ),
		);
	}

	/**
	 * The size and shape a design JSON says it is for, if valid.
	 *
	 * @param array $design Decoded design_json.
	 * @return array|null Normalised params.
	 */
	public static function design_params( array $design, $template = 'sticker' ) {
		if ( empty( $design['sticker'] ) || ! is_array( $design['sticker'] ) ) {
			return null;
		}
		$st = $design['sticker'];
		$p  = self::normalize( $st['w_mm'] ?? null, $st['h_mm'] ?? null, $st['shape'] ?? null, $template );
		if ( ! $p ) {
			return null;
		}
		// The canvas must be the one those params give.
		$spec = self::spec( $p, $template );
		$c    = $design['canvas_mm'] ?? array();
		if ( ! isset( $c['w'], $c['h'] ) || abs( (float) $c['w'] - $spec['canvas_with_bleed_mm']['w'] ) > 0.05 || abs( (float) $c['h'] - $spec['canvas_with_bleed_mm']['h'] ) > 0.05 ) {
			return null;
		}

		return $p;
	}

	/* ------------------------------------------------- the product's size fields */

	/**
	 * The form fields, per calculator, that carry the sticker's size and shape.
	 * Values are in centimetres. The first set whose width field is present in
	 * a request (or on the page) is the one in use.
	 *
	 * @param int $product_id Product id.
	 * @return array<int, array{w: string, h: string, shape: string|null, unit: string}>
	 */
	public static function size_fields( $product_id ) {
		// qty / total / sheets / per_sheet: what the designer's "Your order" box shows (read, never trusted for price).
		$sets = array(
			array( 'w' => 'paper_width', 'h' => 'paper_height', 'shape' => 'paper_shape', 'unit' => 'cm', 'qty' => 'paper_quantity', 'total' => '[data-paper-total]', 'sheets' => '[data-paper-sheets]', 'per_sheet' => '[data-paper-per-sheet]' ),
			array( 'w' => 'pp_width', 'h' => 'pp_height', 'shape' => 'pp_shape', 'unit' => 'cm', 'qty' => 'pp_quantity', 'total' => '[data-pp-total]', 'sheets' => '[data-pp-sheets]', 'per_sheet' => '[data-pp-per-sheet]' ),
			array( 'w' => 'uvdtf_width', 'h' => 'uvdtf_height', 'shape' => null, 'unit' => 'cm', 'qty' => 'uvdtf_quantity', 'total' => '[data-uvdtf-total]', 'sheets' => null, 'per_sheet' => null ),
			array( 'w' => 'diecut_width', 'h' => 'diecut_height', 'shape' => 'diecut_shape', 'unit' => 'cm', 'qty' => 'diecut_quantity', 'total' => '[data-diecut-total]', 'sheets' => '[data-diecut-sheets]', 'per_sheet' => '[data-diecut-yield]' ),
		);

		/**
		 * Which posted fields carry a sticker product's size and shape.
		 *
		 * @param array $sets       Candidate field sets, tried in order.
		 * @param int   $product_id Product id.
		 */
		return (array) apply_filters( 'binder_sticker_fields', $sets, (int) $product_id );
	}

	/**
	 * The size and shape posted with the current add-to-cart request.
	 *
	 * @param int $product_id Product id.
	 * @return array|null Normalised params in mm, or null when none were posted or they are invalid.
	 */
	public static function posted_params( $product_id, $template = 'sticker' ) {
		// phpcs:disable WordPress.Security.NonceVerification -- WooCommerce's add-to-cart request; the calculators validate the same fields.
		foreach ( self::size_fields( $product_id ) as $f ) {
			if ( ! isset( $_POST[ $f['w'] ] ) ) {
				continue;
			}
			$k     = 'cm' === $f['unit'] ? 10 : 1;
			$w     = (float) wp_unslash( $_POST[ $f['w'] ] ) * $k;
			$h     = isset( $_POST[ $f['h'] ] ) ? (float) wp_unslash( $_POST[ $f['h'] ] ) * $k : 0;
			$shape = $f['shape'] && isset( $_POST[ $f['shape'] ] ) ? sanitize_key( wp_unslash( $_POST[ $f['shape'] ] ) ) : 'rectangle';

			return self::normalize( $w, $h, $shape, $template );
		}
		// phpcs:enable

		return null;
	}

	/**
	 * Was this design made for the size and shape now being ordered?
	 *
	 * @param array $row        wp_binder_designs row (template 'sticker').
	 * @param int   $product_id Product id.
	 * @return bool
	 */
	public static function design_matches_request( array $row, $product_id ) {
		$template = 'uvdtf' === ( $row['template'] ?? '' ) ? 'uvdtf' : 'sticker';
		$posted   = self::posted_params( $product_id, $template );
		if ( ! $posted ) {
			return false;
		}
		$design = json_decode( (string) $row['design_json'], true );
		$made   = is_array( $design ) ? self::design_params( $design, $template ) : null;

		return $made ? self::same( $made, $posted ) : false;
	}

	/**
	 * A short human label, e.g. "Round · 50 × 50 mm".
	 *
	 * @param array $p Normalised params.
	 * @return string
	 */
	public static function label( array $p ) {
		return sprintf( '%s · %s × %s mm', ucfirst( $p['shape'] ), rtrim( rtrim( number_format( $p['w_mm'], 1, '.', '' ), '0' ), '.' ), rtrim( rtrim( number_format( $p['h_mm'], 1, '.', '' ), '0' ), '.' ) );
	}
}
