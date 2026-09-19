<?php
/**
 * Per-product template assignment: the Binder Template tab in the product data
 * panel (§3.4). Step 6 adds the tab and the storefront wiring.
 *
 * @package PrimeBinderDesigner
 */

defined( 'ABSPATH' ) || exit;

class Binder_Product_Meta {

	const META = '_binder_template';

	public static function init() {
		// Hooks are added by Step 6.
	}

	/**
	 * May this product take a design for this template?
	 *
	 * Until a product is assigned (Step 6) any product is accepted, so the
	 * editor can be developed and tested against a plain product. Once a
	 * product carries an assignment, only its own templates are allowed.
	 *
	 * @param int    $product_id Product id.
	 * @param string $template   'binder_outer' | 'binder_inner'.
	 * @return bool
	 */
	public static function allows( $product_id, $template ) {
		$assigned = get_post_meta( (int) $product_id, self::META, true );

		if ( '' === $assigned ) {
			return true;
		}

		$map = array(
			'binder_outer' => array( 'binder_outer', 'binder_set' ),
			'binder_inner' => array( 'binder_inner', 'binder_set' ),
		);

		return isset( $map[ $template ] ) && in_array( $assigned, $map[ $template ], true );
	}
}
