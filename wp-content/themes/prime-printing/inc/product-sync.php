<?php
/**
 * Keep a product's stock and price identical across its Polylang translations.
 *
 * Polylang gives every product a second post per language (inc/i18n-product-import.php
 * created the Arabic ones), and those posts each carry their own stock and price.
 * Nothing in Polylang links the two: taking the Arabic copy out of stock leaves
 * the English copy on sale, on its own URL, with a working Add-to-cart button.
 *
 * Reem hit exactly that, 2026-09-21: four UV sticker products marked out of
 * stock in wp-admin, and customers could still order two of them (#5455 and
 * #5656, the untouched copies of #5962 and #5959). Her ask was to "manage the
 * products in 1 side" — including from the WooCommerce mobile app, which knows
 * nothing about languages and only ever edits whichever copy it was shown.
 *
 * So the link is made here instead: whichever copy is edited wins, and its
 * stock and price are written to every other translation. That holds for the
 * product screen, Quick Edit, bulk edit, the REST API (the mobile app), and
 * the stock WooCommerce deducts when an order is placed — a sale on the
 * English copy now also reduces the Arabic one, which is the same physical
 * stock in the same shop.
 *
 * Deliberately NOT synced: title, description, slug, images, categories. Those
 * are what a translation is for.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

/**
 * The WC_Product properties copied to a product's translations.
 *
 * Stock is the reason this file exists. Price is here for the same reason one
 * step later: a price changed on one copy only would be the identical bug,
 * discovered at checkout instead of at the Add-to-cart button.
 *
 * @return string[] Property names, each with a matching get_/set_ on WC_Product.
 */
function prime_synced_product_props() {
	return (array) apply_filters(
		'prime_synced_product_props',
		array(
			'manage_stock',
			'stock_quantity',
			'stock_status',
			'backorders',
			'low_stock_amount',
			'regular_price',
			'sale_price',
			'date_on_sale_from',
			'date_on_sale_to',
		)
	);
}

/**
 * The other language versions of a product.
 *
 * @param int $product_id Product post ID.
 * @return int[] Post IDs of the translations, never including $product_id itself.
 */
function prime_product_translations( $product_id ) {
	if ( ! function_exists( 'pll_get_post_translations' ) ) {
		return array();
	}

	$translations = array_map( 'absint', (array) pll_get_post_translations( $product_id ) );

	return array_values( array_diff( array_filter( $translations ), array( (int) $product_id ) ) );
}

/**
 * Copy the synced properties from one product onto its translations.
 *
 * The guard is what stops the mirror facing itself: saving the Arabic copy
 * fires this hook again for the Arabic copy, which would write back to the
 * English one, and so on. While a sync is running, every product taking part
 * in it is ignored as a source.
 *
 * @param int             $product_id Product being saved.
 * @param WC_Product|null $product    The saved product, when the hook passes one.
 * @param string[]|null   $props      Limit to these properties; null means all of them.
 */
function prime_sync_product_translations( $product_id, $product = null, $props = null ) {
	static $busy = array();

	$product_id = absint( $product_id );

	if ( isset( $busy[ $product_id ] ) ) {
		return;
	}

	$translations = prime_product_translations( $product_id );

	if ( ! $translations ) {
		return;
	}

	$source = $product instanceof WC_Product ? $product : wc_get_product( $product_id );

	if ( ! $source ) {
		return;
	}

	// A variable product's stock and price live on its variations, and the
	// Arabic copies of variable products have no variations of their own yet
	// (see inc/i18n-product-import.php). Copying the parent's empty values
	// over would erase what is there, so those are left alone for now.
	if ( $source->is_type( 'variable' ) ) {
		return;
	}

	$props = null === $props ? prime_synced_product_props() : (array) $props;
	$busy  = array_fill_keys( array_merge( array( $product_id ), $translations ), true );

	foreach ( $translations as $translation_id ) {
		$target = wc_get_product( $translation_id );

		if ( ! $target || $target->is_type( 'variable' ) ) {
			continue;
		}

		$changed = false;

		foreach ( $props as $prop ) {
			$getter = "get_{$prop}";
			$setter = "set_{$prop}";

			if ( ! is_callable( array( $source, $getter ) ) || ! is_callable( array( $target, $setter ) ) ) {
				continue;
			}

			$value = $source->$getter( 'edit' );

			if ( $value === $target->$getter( 'edit' ) ) {
				continue;
			}

			$target->$setter( $value );
			$changed = true;
		}

		if ( $changed ) {
			$target->save();
		}
	}

	$busy = array();
}

/**
 * Any save through the CRUD: the product screen, Quick Edit, bulk edit, the
 * REST API (the WooCommerce mobile app), WP-CLI.
 *
 * @param int        $product_id Product ID.
 * @param WC_Product $product    Product.
 */
function prime_sync_product_on_save( $product_id, $product = null ) {
	prime_sync_product_translations( $product_id, $product );
}
add_action( 'woocommerce_update_product', 'prime_sync_product_on_save', 20, 2 );

/**
 * `woocommerce_updated_product_stock` passes an ID, not a product.
 *
 * @param int $product_id Product ID.
 */
function prime_sync_product_on_stock_change_by_id( $product_id ) {
	$product = wc_get_product( absint( $product_id ) );

	if ( $product ) {
		prime_sync_product_on_stock_change( $product );
	}
}

/**
 * Stock deducted (or restored) by an order.
 *
 * WooCommerce writes that straight to the database for speed rather than
 * through the CRUD, so `woocommerce_update_product` never fires for it and
 * only this hook reports it. Stock alone is synced here — a sale says nothing
 * about the price.
 *
 * @param WC_Product $product The product whose stock changed.
 */
function prime_sync_product_on_stock_change( $product ) {
	if ( ! $product instanceof WC_Product ) {
		return;
	}

	// Read it back: the direct write above happened behind this object.
	$fresh = wc_get_product( $product->get_id() );

	prime_sync_product_translations( $product->get_id(), $fresh ? $fresh : $product, array( 'manage_stock', 'stock_quantity', 'stock_status', 'backorders' ) );
}
add_action( 'woocommerce_product_set_stock', 'prime_sync_product_on_stock_change' );
add_action( 'woocommerce_updated_product_stock', 'prime_sync_product_on_stock_change_by_id' );

/**
 * The product screen tells whoever is editing that this copy is not alone,
 * so a value they are about to change is understood to apply to both.
 */
function prime_product_translation_notice() {
	global $post;

	if ( ! $post || 'product' !== $post->post_type ) {
		return;
	}

	$translations = prime_product_translations( $post->ID );

	if ( ! $translations ) {
		return;
	}

	$links = array();

	foreach ( $translations as $translation_id ) {
		$language = function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $translation_id, 'name' ) : '';
		$links[]  = sprintf(
			'<a href="%s">%s</a>',
			esc_url( (string) get_edit_post_link( $translation_id ) ),
			esc_html( $language ? $language : '#' . $translation_id )
		);
	}

	printf(
		'<div class="notice notice-info"><p>%s %s</p></div>',
		esc_html__( 'Stock and price on this product are shared with its other language:', 'prime-printing' ),
		wp_kses_post( implode( ', ', $links ) )
	);
}
add_action( 'edit_form_top', 'prime_product_translation_notice' );
