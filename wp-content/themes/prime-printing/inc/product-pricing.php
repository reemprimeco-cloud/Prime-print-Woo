<?php
/**
 * Phase 4c — custom-pricing products.
 *
 * Placeholder/example formula only: rate per cm², a floor per piece, a floor
 * per order, optional finish add-ons — matching what is already prototyped in
 * prime-printing-product.html. This is deliberately swappable per product:
 * once Reem's real calculator for a given product arrives, only the formula in
 * prime_calculate_custom_price() changes for that product (via the filter at
 * its end) — the surrounding structure (fields, cart flow, order display,
 * server-side enforcement) does not.
 *
 * The one rule that is NOT provisional: the browser-submitted price is never
 * trusted. It computes a preview only. This file recomputes the real price in
 * PHP from the submitted dimensions on every cart calculation. That closes the
 * `custom_price` vulnerability documented on the current site — the sole
 * reason this file exists ahead of the real formulas.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

/**
 * Is this product priced by the custom formula?
 *
 * @param int $product_id Product ID.
 * @return bool
 */
function prime_is_custom_priced( $product_id ) {
	return 'custom' === get_post_meta( $product_id, '_prime_pricing_model', true );
}

/**
 * The configured formula inputs for one product.
 *
 * @param int $product_id Product ID.
 * @return array{rate: float, min_piece: float, min_order: float, fields: string[], finishes: array[]}
 */
function prime_custom_pricing_config( $product_id ) {
	$fields = get_post_meta( $product_id, '_prime_custom_fields', true );
	$finishes = get_post_meta( $product_id, '_prime_finishes', true );

	return array(
		'rate'      => (float) get_post_meta( $product_id, '_prime_rate_per_cm2', true ),
		'min_piece' => (float) get_post_meta( $product_id, '_prime_min_price_piece', true ),
		'min_order' => (float) get_post_meta( $product_id, '_prime_min_order_total', true ),
		'fields'    => is_array( $fields ) ? $fields : array( 'dimensions', 'upload' ),
		'finishes'  => is_array( $finishes ) ? $finishes : array(),
		// The prototype's bounds. Not yet product-configurable — Reem's real
		// calculators may need per-product min/max, at which point this becomes
		// two more meta fields in inc/product-admin.php.
		'min_cm'    => 1,
		'max_cm'    => 30,
	);
}

/**
 * Render the configurator fields on the product page.
 *
 * Hooked before the add-to-cart button, same as the simple add-ons — the two
 * systems are independent (a custom-priced product can also carry simple
 * add-ons) and are rendered as siblings, in this order, so price-affecting
 * fields sit closer to the price.
 */
function prime_render_custom_pricing_fields() {
	global $product;

	if ( ! $product instanceof WC_Product || ! prime_is_custom_priced( $product->get_id() ) ) {
		return;
	}

	$config = prime_custom_pricing_config( $product->get_id() );
	?>
	<div
		class="prime-configurator-fields"
		data-prime-pricing
		data-rate="<?php echo esc_attr( $config['rate'] ); ?>"
		data-min-piece="<?php echo esc_attr( $config['min_piece'] ); ?>"
		data-min-order="<?php echo esc_attr( $config['min_order'] ); ?>"
		data-min-cm="<?php echo esc_attr( $config['min_cm'] ); ?>"
		data-max-cm="<?php echo esc_attr( $config['max_cm'] ); ?>"
		data-currency="<?php echo esc_attr( get_woocommerce_currency_symbol() ); ?>"
	>
		<?php if ( in_array( 'dimensions', $config['fields'], true ) ) : ?>
			<div class="prime-field prime-field--duo">
				<div class="prime-field">
					<label for="prime-price-w"><?php esc_html_e( 'Width (cm)', 'prime-printing' ); ?></label>
					<input
						type="number" id="prime-price-w" name="prime_width"
						min="<?php echo esc_attr( $config['min_cm'] ); ?>"
						max="<?php echo esc_attr( $config['max_cm'] ); ?>"
						value="<?php echo esc_attr( $config['min_cm'] * 5 ); ?>"
						data-prime-pricing-input
					>
					<span class="prime-field__error" data-prime-error="w">
						<?php
						printf(
							/* translators: 1: minimum cm, 2: maximum cm. */
							esc_html__( 'Enter %1$s–%2$s cm', 'prime-printing' ),
							esc_html( $config['min_cm'] ),
							esc_html( $config['max_cm'] )
						);
						?>
					</span>
				</div>
				<div class="prime-field">
					<label for="prime-price-h"><?php esc_html_e( 'Height (cm)', 'prime-printing' ); ?></label>
					<input
						type="number" id="prime-price-h" name="prime_height"
						min="<?php echo esc_attr( $config['min_cm'] ); ?>"
						max="<?php echo esc_attr( $config['max_cm'] ); ?>"
						value="<?php echo esc_attr( $config['min_cm'] * 5 ); ?>"
						data-prime-pricing-input
					>
					<span class="prime-field__error" data-prime-error="h">
						<?php
						printf(
							/* translators: 1: minimum cm, 2: maximum cm. */
							esc_html__( 'Enter %1$s–%2$s cm', 'prime-printing' ),
							esc_html( $config['min_cm'] ),
							esc_html( $config['max_cm'] )
						);
						?>
					</span>
				</div>
			</div>
		<?php endif; ?>

		<?php if ( in_array( 'finish', $config['fields'], true ) && $config['finishes'] ) : ?>
			<div class="prime-field">
				<label for="prime-price-finish"><?php esc_html_e( 'Finish', 'prime-printing' ); ?></label>
				<select id="prime-price-finish" name="prime_finish" data-prime-pricing-input>
					<?php foreach ( $config['finishes'] as $finish ) : ?>
						<option value="<?php echo esc_attr( $finish['label'] ); ?>" data-delta="<?php echo esc_attr( $finish['delta'] ); ?>">
							<?php
							echo esc_html( $finish['label'] );
							if ( (float) $finish['delta'] > 0 ) {
								printf(
									/* translators: %s: price delta. */
									esc_html__( ' (+%s/pc)', 'prime-printing' ),
									esc_html( number_format( (float) $finish['delta'], 3 ) )
								);
							}
							?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
		<?php endif; ?>

		<?php if ( in_array( 'upload', $config['fields'], true ) ) : ?>
			<div class="prime-field" data-prime-error-field="upload">
				<label for="prime-price-file"><?php esc_html_e( 'Artwork file', 'prime-printing' ); ?></label>
				<input type="file" id="prime-price-file" name="prime_artwork" accept=".pdf,.ai,.eps,.svg,.jpg,.jpeg,.png" required>
				<span class="prime-field__error" data-prime-error="upload"><?php esc_html_e( 'Please upload your artwork file', 'prime-printing' ); ?></span>
			</div>
		<?php endif; ?>

		<div class="prime-calcrow">
			<div class="prime-calcbox">
				<div class="prime-calcbox__k"><?php esc_html_e( 'Area / piece', 'prime-printing' ); ?></div>
				<div class="prime-calcbox__v" data-prime-price-area>—</div>
			</div>
			<div class="prime-calcbox prime-calcbox--hi">
				<div class="prime-calcbox__k"><?php esc_html_e( 'Price / piece', 'prime-printing' ); ?></div>
				<div class="prime-calcbox__v" data-prime-price-piece>—</div>
			</div>
		</div>

		<p class="prime-calc-note"><?php esc_html_e( 'Final price is recalculated on our server when added to cart.', 'prime-printing' ); ?></p>

		<?php wp_nonce_field( 'prime_pricing', 'prime_pricing_nonce' ); ?>
	</div>
	<?php
}
add_action( 'woocommerce_before_add_to_cart_button', 'prime_render_custom_pricing_fields', 5 );

/**
 * Block add-to-cart when dimensions are out of range or artwork is missing.
 *
 * @param bool $passed     Validation state so far.
 * @param int  $product_id Product being added.
 * @return bool
 */
function prime_validate_custom_pricing( $passed, $product_id ) {
	if ( ! prime_is_custom_priced( $product_id ) ) {
		return $passed;
	}

	$config = prime_custom_pricing_config( $product_id );

	if ( in_array( 'dimensions', $config['fields'], true ) ) {
		$width  = isset( $_POST['prime_width'] ) ? (float) wp_unslash( $_POST['prime_width'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only range check; authoritative validation happens again in prime_capture_custom_pricing() after the nonce check.
		$height = isset( $_POST['prime_height'] ) ? (float) wp_unslash( $_POST['prime_height'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( $width < $config['min_cm'] || $width > $config['max_cm'] || $height < $config['min_cm'] || $height > $config['max_cm'] ) {
			wc_add_notice(
				sprintf(
					/* translators: 1: minimum cm, 2: maximum cm. */
					__( 'Enter dimensions between %1$s and %2$s cm.', 'prime-printing' ),
					$config['min_cm'],
					$config['max_cm']
				),
				'error'
			);
			$passed = false;
		}
	}

	if ( in_array( 'upload', $config['fields'], true ) && empty( $_FILES['prime_artwork']['name'] ) ) {
		wc_add_notice( __( 'Please upload your artwork file.', 'prime-printing' ), 'error' );
		$passed = false;
	}

	return $passed;
}
add_filter( 'woocommerce_add_to_cart_validation', 'prime_validate_custom_pricing', 10, 2 );

/**
 * Capture the submitted specs onto the cart item.
 *
 * Only the *specs* are captured here — width, height, finish, the artwork
 * attachment. No price is read from the request at all, at any point in this
 * file. The price is derived in prime_apply_custom_pricing() from these specs,
 * every single time the cart total is calculated.
 *
 * @param array $cart_item_data Existing cart item data.
 * @param int   $product_id     Product being added.
 * @return array
 */
function prime_capture_custom_pricing( $cart_item_data, $product_id ) {
	if ( ! prime_is_custom_priced( $product_id ) ) {
		return $cart_item_data;
	}

	if ( ! isset( $_POST['prime_pricing_nonce'] ) ||
		! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['prime_pricing_nonce'] ) ), 'prime_pricing' )
	) {
		return $cart_item_data;
	}

	$config = prime_custom_pricing_config( $product_id );
	$specs  = array();

	if ( in_array( 'dimensions', $config['fields'], true ) ) {
		$specs['width']  = isset( $_POST['prime_width'] ) ? (float) wp_unslash( $_POST['prime_width'] ) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$specs['height'] = isset( $_POST['prime_height'] ) ? (float) wp_unslash( $_POST['prime_height'] ) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
	}

	if ( in_array( 'finish', $config['fields'], true ) && ! empty( $_POST['prime_finish'] ) ) {
		$submitted = sanitize_text_field( wp_unslash( $_POST['prime_finish'] ) );

		// The finish name is trusted only if it matches a finish actually
		// configured on this product — otherwise a request could invent a
		// finish label to sidestep whatever delta the real ones carry. Since
		// the delta is looked up server-side from this list either way, this
		// mainly keeps a stray value from being stored and displayed.
		foreach ( $config['finishes'] as $finish ) {
			if ( $finish['label'] === $submitted ) {
				$specs['finish'] = $submitted;
				break;
			}
		}
	}

	if ( in_array( 'upload', $config['fields'], true ) && ! empty( $_FILES['prime_artwork']['name'] ) ) {
		$attachment_id = prime_handle_addon_upload( 'prime_artwork' );

		if ( $attachment_id ) {
			$specs['artwork_id'] = $attachment_id;
		}
	}

	if ( $specs ) {
		$cart_item_data['prime_pricing_specs'] = $specs;
		$cart_item_data['unique_key']          = md5( wp_json_encode( $specs ) . microtime() );
	}

	return $cart_item_data;
}
add_filter( 'woocommerce_add_cart_item_data', 'prime_capture_custom_pricing', 10, 2 );

/**
 * The authoritative price for one set of specs.
 *
 * This is the ONE function real per-product formulas replace in Phase 4c, via
 * the filter at its end — swap the formula for a product without touching
 * anything else in the cart/order pipeline.
 *
 * @param array      $specs      Captured specs (width, height, finish).
 * @param array      $config     prime_custom_pricing_config() for this product.
 * @param WC_Product $product    The product.
 * @return float Price per piece, in the store's decimal currency.
 */
function prime_calculate_custom_price( $specs, $config, $product ) {
	$width  = isset( $specs['width'] ) ? $specs['width'] : 0;
	$height = isset( $specs['height'] ) ? $specs['height'] : 0;
	$area   = $width * $height;

	$finish_delta = 0.0;

	if ( ! empty( $specs['finish'] ) ) {
		foreach ( $config['finishes'] as $finish ) {
			if ( $finish['label'] === $specs['finish'] ) {
				$finish_delta = (float) $finish['delta'];
				break;
			}
		}
	}

	$price_per_piece = max( ( $area * $config['rate'] ) + $finish_delta, $config['min_piece'] );

	return (float) apply_filters( 'prime_custom_price', $price_per_piece, $specs, $config, $product );
}

/**
 * Recompute every custom-priced cart item's price on the server.
 *
 * Runs on every cart calculation — not only at add-to-cart — so a coupon, a
 * quantity change, or simply the cart page reloading can never leave a stale
 * or (worse) a client-influenced price sitting in the session.
 *
 * @param WC_Cart $cart Current cart.
 */
function prime_apply_custom_pricing( $cart ) {
	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
		return;
	}

	foreach ( $cart->get_cart() as $cart_item ) {
		if ( empty( $cart_item['prime_pricing_specs'] ) ) {
			continue;
		}

		$product = $cart_item['data'];

		if ( ! $product instanceof WC_Product ) {
			continue;
		}

		$config = prime_custom_pricing_config( $product->get_id() );
		$price  = prime_calculate_custom_price( $cart_item['prime_pricing_specs'], $config, $product );

		$product->set_price( $price );
	}
}
add_action( 'woocommerce_before_calculate_totals', 'prime_apply_custom_pricing', 20 );

/**
 * Enforce the per-order minimum after totals are calculated.
 *
 * A per-piece floor is applied above; a per-*order* floor (e.g. "we do not
 * print runs under 2.250 KWD regardless of size") has to run after the cart
 * total exists, which is one hook later than the per-piece price.
 *
 * @param WC_Cart $cart Current cart.
 */
function prime_enforce_order_minimum( $cart ) {
	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
		return;
	}

	foreach ( $cart->get_cart() as $cart_item ) {
		if ( empty( $cart_item['prime_pricing_specs'] ) ) {
			continue;
		}

		$product = $cart_item['data'];

		if ( ! $product instanceof WC_Product ) {
			continue;
		}

		$config    = prime_custom_pricing_config( $product->get_id() );
		$line_total = $product->get_price( 'edit' ) * $cart_item['quantity'];

		if ( $config['min_order'] > 0 && $line_total < $config['min_order'] ) {
			$product->set_price( $config['min_order'] / max( 1, $cart_item['quantity'] ) );
		}
	}
}
add_action( 'woocommerce_before_calculate_totals', 'prime_enforce_order_minimum', 21 );

/**
 * Show the specs in the cart, checkout review, and order emails.
 *
 * @param array $item_data Existing display rows.
 * @param array $cart_item The cart item.
 * @return array
 */
function prime_display_custom_pricing_specs( $item_data, $cart_item ) {
	if ( empty( $cart_item['prime_pricing_specs'] ) ) {
		return $item_data;
	}

	$specs = $cart_item['prime_pricing_specs'];

	if ( isset( $specs['width'], $specs['height'] ) ) {
		$item_data[] = array(
			'name'  => __( 'Size', 'prime-printing' ),
			'value' => sprintf( '%s × %s cm', $specs['width'], $specs['height'] ),
		);
	}

	if ( ! empty( $specs['finish'] ) ) {
		$item_data[] = array(
			'name'  => __( 'Finish', 'prime-printing' ),
			'value' => esc_html( $specs['finish'] ),
		);
	}

	if ( ! empty( $specs['artwork_id'] ) ) {
		$item_data[] = array(
			'name'  => __( 'Artwork', 'prime-printing' ),
			'value' => esc_html( get_the_title( $specs['artwork_id'] ) ),
		);
	}

	return $item_data;
}
add_filter( 'woocommerce_get_item_data', 'prime_display_custom_pricing_specs', 10, 2 );

/**
 * Persist specs onto the order line item — the same reasoning as the simple
 * add-ons: this is what survives past checkout, onto the invoice (Phase 7) and
 * into the Armada dispatch payload (Phase 9). This was "the exact missing
 * feature that started this whole project."
 *
 * @param WC_Order_Item_Product $item          Order line item.
 * @param string                $cart_item_key Cart item key.
 * @param array                 $values        Cart item values.
 */
function prime_persist_custom_pricing_to_order( $item, $cart_item_key, $values ) {
	if ( empty( $values['prime_pricing_specs'] ) ) {
		return;
	}

	$specs = $values['prime_pricing_specs'];

	if ( isset( $specs['width'], $specs['height'] ) ) {
		$item->add_meta_data( __( 'Size', 'prime-printing' ), sprintf( '%s × %s cm', $specs['width'], $specs['height'] ), true );
	}

	if ( ! empty( $specs['finish'] ) ) {
		$item->add_meta_data( __( 'Finish', 'prime-printing' ), $specs['finish'], true );
	}

	if ( ! empty( $specs['artwork_id'] ) ) {
		// Appended to the value, not a separate row — WooCommerce's own admin
		// order screen runs item meta values through make_clickable(), so a
		// plain URL in the text becomes a real link there for free. Found
		// 2026-09-05: this helper (inc/account.php) already existed for the
		// customer's own account order history but was never actually linked
		// from anywhere an admin could see it — Reem had no way to download an
		// uploaded file from the order screen at all until this.
		$item->add_meta_data(
			__( 'Artwork', 'prime-printing' ),
			get_the_title( $specs['artwork_id'] ) . ' — ' . prime_file_download_url( $specs['artwork_id'] ),
			true
		);
		$item->update_meta_data( '_prime_addon_files', array( $specs['artwork_id'] ) );
	}

	// Same reasoning as _prime_addons_raw in product-addons.php: the display
	// meta above ("80 × 120 cm") is for humans reading the order; Phase 8's
	// reorder needs the original $specs array back verbatim to rebuild
	// cart_item_data without re-parsing formatted strings.
	$item->update_meta_data( '_prime_pricing_specs_raw', $specs );
}
add_action( 'woocommerce_checkout_create_order_line_item', 'prime_persist_custom_pricing_to_order', 10, 3 );
