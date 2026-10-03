<?php
/**
 * The paper bag template: a flat sheet (the dieline) laid out from the
 * width, height and depth the customer typed on the product page, designed
 * panel by panel. There is no spec.json for it; the spec is derived from the
 * three values exactly as binder-shared/src/bag.ts does for the editor and
 * the render service. Keep the two in step.
 *
 * Where the size comes from: the theme's paper bag calculator posts
 * bag_width, bag_height and bag_depth in centimetres. This class converts to
 * millimetres and checks that a design was made for the bag being ordered.
 *
 * @package PrimeBinderDesigner
 */

defined( 'ABSPATH' ) || exit;

class Binder_Bag {

	const BLEED_MM      = 3.0;
	const SAFE_MM       = 5.0;
	const GLUE_MM       = 15.0;
	const TOP_FOLD_MM   = 30.0;
	const BASE_EXTRA_MM = 15.0;
	const DPI           = 300;

	const MIN_W_MM = 60.0;
	const MAX_W_MM = 500.0;
	const MIN_H_MM = 80.0;
	const MAX_H_MM = 600.0;
	const MIN_D_MM = 30.0;
	const MAX_D_MM = 250.0;

	/** Panels the customer never sees on the finished bag. */
	const HIDDEN = array( 'glue', 'top_fold' );

	/**
	 * Validate and tidy a bag size. Millimetres, rounded to 0.1.
	 *
	 * @param mixed $w_mm Front panel width in mm.
	 * @param mixed $h_mm Bag height in mm.
	 * @param mixed $d_mm Depth (side gusset) in mm.
	 * @return array{w_mm: float, h_mm: float, d_mm: float}|null
	 */
	public static function normalize( $w_mm, $h_mm, $d_mm ) {
		if ( ! is_numeric( $w_mm ) || ! is_numeric( $h_mm ) || ! is_numeric( $d_mm ) ) {
			return null;
		}
		$w = round( (float) $w_mm, 1 );
		$h = round( (float) $h_mm, 1 );
		$d = round( (float) $d_mm, 1 );

		if ( $w < self::MIN_W_MM || $w > self::MAX_W_MM || $h < self::MIN_H_MM || $h > self::MAX_H_MM || $d < self::MIN_D_MM || $d > self::MAX_D_MM ) {
			return null;
		}

		return array(
			'w_mm' => $w,
			'h_mm' => $h,
			'd_mm' => $d,
		);
	}

	/**
	 * @param array $a Normalised params.
	 * @param array $b Normalised params.
	 * @return bool
	 */
	public static function same( array $a, array $b ) {
		return abs( $a['w_mm'] - $b['w_mm'] ) < 0.05 && abs( $a['h_mm'] - $b['h_mm'] ) < 0.05 && abs( $a['d_mm'] - $b['d_mm'] ) < 0.05;
	}

	/**
	 * The flat sheet: glue flap | front | side | back | side across, top fold
	 * | body | base down (bag.ts bagLayout()).
	 *
	 * @param array $p Normalised params.
	 * @return array
	 */
	public static function layout( array $p ) {
		$w = (float) $p['w_mm'];
		$h = (float) $p['h_mm'];
		$d = (float) $p['d_mm'];
		$g = self::GLUE_MM;
		$t = self::TOP_FOLD_MM;
		$b = round( $d / 2 + self::BASE_EXTRA_MM, 1 );

		return array(
			'glue'    => $g,
			'top'     => $t,
			'base'    => $b,
			'sheet'   => array( 'w' => round( $g + 2 * $w + 2 * $d, 1 ), 'h' => round( $t + $h + $b, 1 ) ),
			'columns' => array(
				'glue'   => 0.0,
				'front'  => $g,
				'side_a' => round( $g + $w, 1 ),
				'back'   => round( $g + $w + $d, 1 ),
				'side_b' => round( $g + 2 * $w + $d, 1 ),
				'end'    => round( $g + 2 * $w + 2 * $d, 1 ),
			),
			'rows'    => array(
				'top'  => 0.0,
				'body' => $t,
				'base' => round( $t + $h, 1 ),
				'end'  => round( $t + $h + $b, 1 ),
			),
		);
	}

	/**
	 * The spec for one bag — the same shape as a binder spec.json.
	 *
	 * @param array $p Normalised params.
	 * @return array
	 */
	public static function spec( array $p ) {
		$w  = (float) $p['w_mm'];
		$h  = (float) $p['h_mm'];
		$d  = (float) $p['d_mm'];
		$l  = self::layout( $p );
		$c  = $l['columns'];
		$r  = $l['rows'];
		$b  = self::BLEED_MM;
		$s  = self::SAFE_MM;
		$cw = round( $l['sheet']['w'] + 2 * $b, 1 );
		$ch = round( $l['sheet']['h'] + 2 * $b, 1 );

		$panel = static function ( $name, $x, $y, $pw, $ph, $hidden = false ) use ( $s ) {
			$box = array( 'x' => round( $x, 1 ), 'y' => round( $y, 1 ), 'w' => round( $pw, 1 ), 'h' => round( $ph, 1 ) );
			if ( $hidden ) {
				$safe = array( 'x' => round( $x + $pw / 2, 1 ), 'y' => round( $y + $ph / 2, 1 ), 'w' => 0, 'h' => 0 );
			} else {
				$safe = array( 'x' => round( $x + $s, 1 ), 'y' => round( $y + $s, 1 ), 'w' => round( max( 0, $pw - 2 * $s ), 1 ), 'h' => round( max( 0, $ph - 2 * $s ), 1 ) );
			}

			return array( 'name' => $name, 'trim_mm' => $box, 'safe_mm' => $safe );
		};

		$body_h = round( $r['base'] - $r['body'], 1 );

		return array(
			'template'                       => 'bag',
			'unit'                           => 'mm',
			'dpi'                            => self::DPI,
			'color'                          => 'CMYK (FOGRA39 / ISO Coated v2)',
			'bleed_mm'                       => $b,
			'safe_margin_mm'                 => $s,
			'turn_in_mm'                     => 0,
			'trim_mm'                        => array( 'w' => $l['sheet']['w'], 'h' => $l['sheet']['h'] ),
			'canvas_with_bleed_mm'           => array( 'w' => $cw, 'h' => $ch ),
			'canvas_with_bleed_px'           => array(
				'w' => (int) round( $cw / 25.4 * self::DPI ),
				'h' => (int) round( $ch / 25.4 * self::DPI ),
			),
			'panels_relative_to_trim'        => array(
				$panel( 'glue', 0, 0, $l['glue'], $l['sheet']['h'], true ),
				$panel( 'top_fold', $c['front'], 0, $c['end'] - $c['front'], $l['top'], true ),
				$panel( 'front', $c['front'], $r['body'], $w, $body_h ),
				$panel( 'side_a', $c['side_a'], $r['body'], $d, $body_h ),
				$panel( 'back', $c['back'], $r['body'], $w, $body_h ),
				$panel( 'side_b', $c['side_b'], $r['body'], $d, $body_h ),
				$panel( 'base', $c['front'], $r['base'], $c['end'] - $c['front'], $l['base'] ),
			),
			'fold_lines_x_mm_from_trim_left' => array( $c['front'], $c['side_a'], round( $c['side_a'] + $d / 2, 1 ), $c['back'], $c['side_b'], round( $c['side_b'] + $d / 2, 1 ) ),
			'fold_lines_y_mm_from_trim_top'  => array( $r['body'], $r['base'] ),
			'bag'                            => array( 'w_mm' => $w, 'h_mm' => $h, 'd_mm' => $d ),
		);
	}

	/**
	 * The bag size a design JSON says it is for, if valid.
	 *
	 * @param array $design Decoded design_json.
	 * @return array|null Normalised params.
	 */
	public static function design_params( array $design ) {
		if ( empty( $design['bag'] ) || ! is_array( $design['bag'] ) ) {
			return null;
		}
		$bag = $design['bag'];
		$p   = self::normalize( $bag['w_mm'] ?? null, $bag['h_mm'] ?? null, $bag['d_mm'] ?? null );
		if ( ! $p ) {
			return null;
		}
		// The canvas must be the one those params give.
		$spec = self::spec( $p );
		$c    = $design['canvas_mm'] ?? array();
		if ( ! isset( $c['w'], $c['h'] ) || abs( (float) $c['w'] - $spec['canvas_with_bleed_mm']['w'] ) > 0.05 || abs( (float) $c['h'] - $spec['canvas_with_bleed_mm']['h'] ) > 0.05 ) {
			return null;
		}

		return $p;
	}

	/* ------------------------------------------------- the product's size fields */

	/**
	 * The form fields that carry the bag's size. Values are in centimetres.
	 * Same shape as Binder_Sticker::size_fields() plus a depth field, so the
	 * storefront script reads both the same way.
	 *
	 * @param int $product_id Product id.
	 * @return array<int, array{w: string, h: string, d: string, shape: null, unit: string}>
	 */
	public static function size_fields( $product_id ) {
		$sets = array(
			array( 'w' => 'bag_width', 'h' => 'bag_height', 'd' => 'bag_depth', 'shape' => null, 'unit' => 'cm', 'qty' => 'bag_quantity', 'total' => '[data-bag-total]', 'sheets' => null, 'per_sheet' => null ),
		);

		/**
		 * Which posted fields carry a bag product's size.
		 *
		 * @param array $sets       Candidate field sets, tried in order.
		 * @param int   $product_id Product id.
		 */
		return (array) apply_filters( 'binder_bag_fields', $sets, (int) $product_id );
	}

	/**
	 * The bag size posted with the current add-to-cart request.
	 *
	 * @param int $product_id Product id.
	 * @return array|null Normalised params in mm, or null when none were posted or they are invalid.
	 */
	public static function posted_params( $product_id ) {
		// phpcs:disable WordPress.Security.NonceVerification -- WooCommerce's add-to-cart request; the calculator validates the same fields.
		foreach ( self::size_fields( $product_id ) as $f ) {
			if ( ! isset( $_POST[ $f['w'] ], $_POST[ $f['h'] ], $_POST[ $f['d'] ] ) ) {
				continue;
			}
			$k = 'cm' === $f['unit'] ? 10 : 1;

			return self::normalize(
				(float) wp_unslash( $_POST[ $f['w'] ] ) * $k,
				(float) wp_unslash( $_POST[ $f['h'] ] ) * $k,
				(float) wp_unslash( $_POST[ $f['d'] ] ) * $k
			);
		}
		// phpcs:enable

		return null;
	}

	/**
	 * Was this design made for the bag now being ordered?
	 *
	 * @param array $row        wp_binder_designs row (template 'bag').
	 * @param int   $product_id Product id.
	 * @return bool
	 */
	public static function design_matches_request( array $row, $product_id ) {
		$posted = self::posted_params( $product_id );
		if ( ! $posted ) {
			return false;
		}
		$design = json_decode( (string) $row['design_json'], true );
		$made   = is_array( $design ) ? self::design_params( $design ) : null;

		return $made ? self::same( $made, $posted ) : false;
	}

	/**
	 * A short human label, e.g. "Bag 200 × 250 × 80 mm".
	 *
	 * @param array $p Normalised params.
	 * @return string
	 */
	public static function label( array $p ) {
		$n = static function ( $v ) {
			return rtrim( rtrim( number_format( (float) $v, 1, '.', '' ), '0' ), '.' );
		};

		return sprintf( 'Bag %s × %s × %s mm', $n( $p['w_mm'] ), $n( $p['h_mm'] ), $n( $p['d_mm'] ) );
	}
}
