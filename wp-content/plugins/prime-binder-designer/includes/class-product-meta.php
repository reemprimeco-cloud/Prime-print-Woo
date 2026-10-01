<?php
/**
 * Per-product template assignment: the "Binder Template" tab in the product
 * data panel (§3.4). The storefront side lives in class-storefront.php.
 *
 * @package PrimeBinderDesigner
 */

defined( 'ABSPATH' ) || exit;

class Binder_Product_Meta {

	const META = '_binder_template';

	/**
	 * Assignment value => templates the customer must design before ordering.
	 */
	const REQUIRES = array(
		'binder_outer' => array( 'binder_outer' ),
		'binder_inner' => array( 'binder_inner' ),
		'binder_set'   => array( 'binder_outer', 'binder_inner' ),
		'sticker'      => array( 'sticker' ),
		'uvdtf'        => array( 'uvdtf' ),
	);

	public static function init() {
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'add_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'render_panel' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save' ) );
		add_action( 'admin_head', array( __CLASS__, 'tab_icon' ) );
	}

	/**
	 * @param array $tabs Product data tabs.
	 * @return array
	 */
	public static function add_tab( $tabs ) {
		$tabs['binder'] = array(
			'label'    => __( 'Design Template', 'prime-binder-designer' ),
			'target'   => 'binder_template_data',
			'class'    => array( 'show_if_simple', 'show_if_variable' ),
			'priority' => 65,
		);

		return $tabs;
	}

	public static function tab_icon() {
		echo '<style>#woocommerce-product-data ul.wc-tabs li.binder_options a::before{content:"\f497";font-family:Dashicons}</style>';
	}

	public static function render_panel() {
		global $post;

		echo '<div id="binder_template_data" class="panel woocommerce_options_panel hidden">';

		woocommerce_wp_select(
			array(
				'id'          => self::META,
				'label'       => __( 'Design template', 'prime-binder-designer' ),
				'value'       => (string) get_post_meta( $post->ID, self::META, true ),
				'options'     => array(
					''             => __( 'None — not a designed product', 'prime-binder-designer' ),
					'binder_outer' => __( 'Outer cover only', 'prime-binder-designer' ),
					'binder_inner' => __( 'Inner cover only', 'prime-binder-designer' ),
					'binder_set'   => __( 'Set — outer and inner cover', 'prime-binder-designer' ),
					'sticker'      => __( 'Sticker — size and shape from the product\'s calculator', 'prime-binder-designer' ),
					'uvdtf'        => __( 'UV DTF transfer — size from the calculator; text and images on a transparent artboard, TIFF with White and Varnish channels', 'prime-binder-designer' ),
				),
				'desc_tip'    => true,
				'description' => __( 'Customers must add a finished design for each cover before Add to Cart is enabled. A set asks for both. A sticker takes its size and shape from the calculator on the product page (bleed 1 mm, safe zone 2 mm).', 'prime-binder-designer' ),
			)
		);

		echo '</div>';
	}

	/**
	 * @param int $post_id Product id.
	 */
	public static function save( $post_id ) {
		// WooCommerce has already verified the product-save nonce and capability.
		$value = isset( $_POST[ self::META ] ) ? sanitize_key( wp_unslash( $_POST[ self::META ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		if ( isset( self::REQUIRES[ $value ] ) ) {
			update_post_meta( $post_id, self::META, $value );
		} else {
			delete_post_meta( $post_id, self::META );
		}
	}

	/**
	 * The assignment of a product: 'binder_outer' | 'binder_inner' | 'binder_set' | ''.
	 *
	 * @param int $product_id Product id.
	 * @return string
	 */
	public static function assignment( $product_id ) {
		$value = (string) get_post_meta( (int) $product_id, self::META, true );

		return isset( self::REQUIRES[ $value ] ) ? $value : '';
	}

	/**
	 * Templates a customer must design before this product can be ordered.
	 *
	 * @param int $product_id Product id.
	 * @return string[] Empty for an ordinary product.
	 */
	public static function required_templates( $product_id ) {
		$value = self::assignment( $product_id );

		return '' === $value ? array() : self::REQUIRES[ $value ];
	}

	/**
	 * May this product take a design for this template?
	 *
	 * A product with no assignment accepts any template, so the editor can be
	 * developed and tested against a plain product. Once assigned, only its
	 * own templates are allowed.
	 *
	 * @param int    $product_id Product id.
	 * @param string $template   'binder_outer' | 'binder_inner' | 'sticker'.
	 * @return bool
	 */
	public static function allows( $product_id, $template ) {
		$required = self::required_templates( $product_id );

		return empty( $required ) || in_array( $template, $required, true );
	}
}
