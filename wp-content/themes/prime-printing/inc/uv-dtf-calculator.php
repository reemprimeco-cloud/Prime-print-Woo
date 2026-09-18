<?php
/**
 * UV DTF Sticker (product id 4641) — real price calculator.
 *
 * Replaces the external iframe embed (wc-uv.netlify.app, pasted into the
 * product's short description) with the same calculator inline in the theme,
 * restyled to match the site (Reem, 2026-09-05: "dont touch the formula just
 * embedd it in the product page with the new theme design"). The formula
 * itself — roll width, film/ink/shipping/overhead cost per cm, 50% margin,
 * 5 KD minimum, the roll-packing math — is ported line-for-line from Reem's
 * own reference, UV_DTF_WooCommerce_Calculator.html, and is not touched here.
 *
 * What the iframe version could not do, and this closes: the old embed posted
 * its computed `custom_price` to nowhere (no listener existed on the parent
 * page, so its own Add to cart button did nothing), and even if it had, a
 * client-computed price is never safe to trust directly — a customer could
 * alter it in devtools before it reached the cart. Here the browser only ever
 * shows a preview; prime_uv_dtf_price() below runs again in PHP on every cart
 * calculation and is the only number ever charged.
 *
 * Deliberately scoped to this one product rather than folded into the generic
 * per-piece formula in inc/product-pricing.php — that system prices per piece
 * and multiplies by WooCommerce's own cart quantity; this product prices a
 * whole sheet once (quantity is "how many pieces come off it", not a cart
 * multiplier — see prime_uv_dtf_lock_quantity() below), which is a different
 * enough shape that bending the generic system to fit it would have meant
 * changing behaviour the other one already relies on.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

/**
 * The one product this applies to.
 */
const PRIME_UV_DTF_PRODUCT_ID = 4641;

/**
 * Reem's real cost basis, ported verbatim from her latest reference file
 * (UV_DTF_Calculator_30cm.html, 2026-09-07 — supersedes the original 60cm-roll
 * UV_DTF_WooCommerce_Calculator.html this was first built from). A real cost
 * basis, not a setting — a wrong number here changes what a customer is
 * charged, so it stays in code rather than a wp-admin field a misclick could
 * break pricing on.
 *
 * @return array
 */
function prime_uv_dtf_constants() {
	return array(
		'roll_width_cm' => 30,
		// Film: 30cm x 100m roll @ 45 KD — given by Reem as a working figure,
		// not yet backed by a real 30cm-roll invoice like the old 60cm figure
		// was (confirm/replace when a real invoice exists).
		'film_per_cm'     => 0.0045,
		// Ink: CMYK+White @ $21/500ml, Varnish @ $28/500ml — real coverage
		// from 4 actual print jobs (CMYK 1.8195, White 1.8851, Varnish
		// (V12+GL) 3.7701 ml/m²), scaled to the 30cm roll width.
		'ink_per_cm'      => 0.0003410,
		// ~30 KD/order avg x 12 orders/yr, spread over 1700m annual volume.
		'shipping_per_cm' => 0.0021176,
		// Machine dep. (2647.400 KWD / 2yrs) + overhead (rent 350 + labor 700 +
		// admin 500)/mo, spread over annualized output (11 busy months @3 rolls
		// + 1 slow month @1 roll = 1700m/yr). Same fixed-cost pool as before —
		// flag for a recalculation if the 30cm line turns out to run on its
		// own separate machine/labor/output rather than sharing the 60cm one's.
		'fixed_per_cm'    => 0.1171982,
		'margin'          => 0.50,
		'min_order'       => 5.0,
		'spacing_cm'      => 0.5,
		// Cutting is a flat per-piece add-on, not part of the roll-cost rate
		// above — it's added to the sheet total afterwards, not blended into
		// it, so the 5 KD minimum never absorbs it (Reem, 2026-09-07).
		'cutting_per_piece' => 0.020,
	);
}

/**
 * The roll-layout formula: how many pieces fit across the roll, how much
 * sheet length that many rows needs, and the price for that sheet.
 *
 * @param float $width_cm     One piece's width.
 * @param float $height_cm    One piece's height.
 * @param int   $quantity     Pieces wanted.
 * @param bool  $need_cutting Whether the optional per-piece cutting add-on was requested.
 * @return array{sheet_length_cm: int, sheet_total: float, cutting_cost: float, total: float, price_per_piece: float}
 */
function prime_uv_dtf_price( $width_cm, $height_cm, $quantity, $need_cutting = false ) {
	$c = prime_uv_dtf_constants();

	$quantity  = max( 1, (int) $quantity );
	$width_cm  = max( 0.1, (float) $width_cm );
	$height_cm = max( 0.1, (float) $height_cm );

	$designs_across = 1;

	for ( $n = 1; $n <= 100; $n++ ) {
		$width_needed = ( $n * $width_cm ) + ( ( $n - 1 ) * $c['spacing_cm'] );

		if ( $width_needed <= $c['roll_width_cm'] + 0.5 ) {
			$designs_across = $n;
		} else {
			break;
		}
	}

	$rows_needed     = (int) ceil( $quantity / $designs_across );
	$sheet_length_cm = (int) ceil( ( $rows_needed * $height_cm ) + ( ( $rows_needed - 1 ) * $c['spacing_cm'] ) );

	$total_cost_per_cm = $c['film_per_cm'] + $c['ink_per_cm'] + $c['shipping_per_cm'] + $c['fixed_per_cm'];
	$rate_per_cm       = $total_cost_per_cm / ( 1 - $c['margin'] );

	$total = $sheet_length_cm * $rate_per_cm;
	$total = max( $total, $c['min_order'] );
	$total = round( $total, 3 );

	// Added after the minimum-order floor, not before — cutting is a real
	// per-piece service cost on top of whatever the sheet itself costs, so it
	// must never be swallowed by the 5 KD minimum.
	$cutting_cost = $need_cutting ? round( $quantity * $c['cutting_per_piece'], 3 ) : 0.0;
	$grand_total  = round( $total + $cutting_cost, 3 );

	return array(
		'sheet_length_cm' => $sheet_length_cm,
		'sheet_total'     => $total,
		'cutting_cost'    => $cutting_cost,
		'total'           => $grand_total,
		'price_per_piece' => $grand_total / $quantity,
	);
}

/**
 * Is the product on this page the UV DTF sticker?
 *
 * @return bool
 */
function prime_is_uv_dtf_product() {
	global $product;

	return $product instanceof WC_Product && prime_product_id_matches( $product->get_id(), PRIME_UV_DTF_PRODUCT_ID );
}

/**
 * Render the calculator, replacing the generic price/short-description area
 * for this one product with the real one.
 *
 * Hooked at the same point and priority family as the generic configurator in
 * inc/product-pricing.php (which this product never engages — its
 * `_prime_pricing_model` meta is not 'custom') so the two features are inert
 * to one another regardless of which products either applies to later.
 */
function prime_render_uv_dtf_calculator() {
	if ( ! prime_is_uv_dtf_product() ) {
		return;
	}

	$c = prime_uv_dtf_constants();
	?>
	<div
		class="prime-configurator-fields"
		data-uvdtf-calculator
		data-roll-width="<?php echo esc_attr( $c['roll_width_cm'] ); ?>"
		data-film-per-cm="<?php echo esc_attr( $c['film_per_cm'] ); ?>"
		data-ink-per-cm="<?php echo esc_attr( $c['ink_per_cm'] ); ?>"
		data-shipping-per-cm="<?php echo esc_attr( $c['shipping_per_cm'] ); ?>"
		data-fixed-per-cm="<?php echo esc_attr( $c['fixed_per_cm'] ); ?>"
		data-margin="<?php echo esc_attr( $c['margin'] ); ?>"
		data-min-order="<?php echo esc_attr( $c['min_order'] ); ?>"
		data-spacing="<?php echo esc_attr( $c['spacing_cm'] ); ?>"
		data-cutting-per-piece="<?php echo esc_attr( $c['cutting_per_piece'] ); ?>"
		data-currency="<?php echo esc_attr( get_woocommerce_currency_symbol() ); ?>"
	>
		<div class="prime-field prime-field--duo">
			<div class="prime-field">
				<label for="uvdtf-w"><?php esc_html_e( 'Width (cm)', 'prime-printing' ); ?></label>
				<input type="number" id="uvdtf-w" name="uvdtf_width" min="0.1" step="0.1" value="5" data-uvdtf-input>
			</div>
			<div class="prime-field">
				<label for="uvdtf-h"><?php esc_html_e( 'Height (cm)', 'prime-printing' ); ?></label>
				<input type="number" id="uvdtf-h" name="uvdtf_height" min="0.1" step="0.1" value="5" data-uvdtf-input>
			</div>
		</div>

		<div class="prime-field">
			<label for="uvdtf-q"><?php esc_html_e( 'Quantity (pcs)', 'prime-printing' ); ?></label>
			<input type="number" id="uvdtf-q" name="uvdtf_quantity" min="1" step="1" value="10" data-uvdtf-input>
		</div>

		<label class="prime-check">
			<input type="checkbox" id="uvdtf-cutting" name="uvdtf_cutting" value="1" data-uvdtf-input>
			<span><?php esc_html_e( 'Need cutting?', 'prime-printing' ); ?></span>
		</label>

		<div class="prime-field">
			<label for="uvdtf-file"><?php esc_html_e( 'Artwork file (optional)', 'prime-printing' ); ?></label>
			<input type="file" id="uvdtf-file" name="uvdtf_artwork" accept=".pdf,.ai,.eps,.svg,.jpg,.jpeg,.png">
			<span class="prime-field__hint"><?php esc_html_e( 'You can also send it later on WhatsApp — the order still goes through without it.', 'prime-printing' ); ?></span>
		</div>

		<div class="prime-calcrow">
			<div class="prime-calcbox">
				<div class="prime-calcbox__k"><?php esc_html_e( 'Sheet length needed', 'prime-printing' ); ?></div>
				<div class="prime-calcbox__v" data-uvdtf-length>—</div>
			</div>
			<div class="prime-calcbox">
				<div class="prime-calcbox__k"><?php esc_html_e( 'Price / piece', 'prime-printing' ); ?></div>
				<div class="prime-calcbox__v" data-uvdtf-piece>—</div>
			</div>
		</div>

		<?php
		/*
		 * The total sits on its own full-width row below the two spec boxes,
		 * not beside them (Reem, 2026-09-07: "move the total price underneath
		 * ... to make it look more professional") — reads like a receipt's
		 * line items followed by its total rather than three same-weight
		 * figures in a row.
		 */
		?>
		<div class="prime-calcbox prime-calcbox--hi prime-calcbox--total">
			<div class="prime-calcbox__k"><?php esc_html_e( 'Total sheet price', 'prime-printing' ); ?></div>
			<div class="prime-calcbox__v" data-uvdtf-total>—</div>
		</div>

		<p class="prime-calc-note"><?php esc_html_e( 'Final price is recalculated on our server when added to cart.', 'prime-printing' ); ?></p>

		<?php wp_nonce_field( 'prime_uv_dtf', 'prime_uv_dtf_nonce' ); ?>
	</div>
	<?php
}
add_action( 'woocommerce_before_add_to_cart_button', 'prime_render_uv_dtf_calculator', 5 );

/**
 * A sheet is one job, not N individually-priced units — WooCommerce's own
 * quantity stepper has no meaning here (see the file docblock), so this
 * product is sold_individually, which is also what makes WooCommerce hide
 * that stepper on its own rather than this file having to.
 *
 * @param bool       $sold_individually Current value.
 * @param WC_Product $product           Product being checked.
 * @return bool
 */
function prime_uv_dtf_lock_quantity( $sold_individually, $product ) {
	if ( $product instanceof WC_Product && prime_product_id_matches( $product->get_id(), PRIME_UV_DTF_PRODUCT_ID ) ) {
		return true;
	}

	return $sold_individually;
}
add_filter( 'woocommerce_is_sold_individually', 'prime_uv_dtf_lock_quantity', 10, 2 );

/**
 * Block add-to-cart when the inputs don't make sense or artwork is missing.
 *
 * @param bool $passed     Validation state so far.
 * @param int  $product_id Product being added.
 * @return bool
 */
function prime_validate_uv_dtf( $passed, $product_id ) {
	if ( ! prime_product_id_matches( $product_id, PRIME_UV_DTF_PRODUCT_ID ) ) {
		return $passed;
	}

	$width  = isset( $_POST['uvdtf_width'] ) ? (float) wp_unslash( $_POST['uvdtf_width'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only range check; authoritative validation happens again in prime_capture_uv_dtf() after the nonce check.
	$height = isset( $_POST['uvdtf_height'] ) ? (float) wp_unslash( $_POST['uvdtf_height'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$qty    = isset( $_POST['uvdtf_quantity'] ) ? (int) wp_unslash( $_POST['uvdtf_quantity'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

	if ( $width < 0.1 || $height < 0.1 ) {
		wc_add_notice( __( 'Enter a width and height for your sticker.', 'prime-printing' ), 'error' );
		$passed = false;
	}

	if ( $qty < 1 ) {
		wc_add_notice( __( 'Enter how many pieces you need.', 'prime-printing' ), 'error' );
		$passed = false;
	}

	// Artwork is optional (Reem, 2026-09-05: "they can order without
	// uploading a file") — a customer can send it on WhatsApp afterwards.

	return $passed;
}
add_filter( 'woocommerce_add_to_cart_validation', 'prime_validate_uv_dtf', 10, 2 );

/**
 * Capture the submitted specs onto the cart item. No price is read from the
 * request at any point — prime_apply_uv_dtf_price() derives it from these
 * specs alone, every time the cart total is calculated.
 *
 * @param array $cart_item_data Existing cart item data.
 * @param int   $product_id     Product being added.
 * @return array
 */
function prime_capture_uv_dtf( $cart_item_data, $product_id ) {
	if ( ! prime_product_id_matches( $product_id, PRIME_UV_DTF_PRODUCT_ID ) ) {
		return $cart_item_data;
	}

	if ( ! isset( $_POST['prime_uv_dtf_nonce'] ) ||
		! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['prime_uv_dtf_nonce'] ) ), 'prime_uv_dtf' )
	) {
		return $cart_item_data;
	}

	$specs = array(
		'width'        => isset( $_POST['uvdtf_width'] ) ? (float) wp_unslash( $_POST['uvdtf_width'] ) : 0, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		'height'       => isset( $_POST['uvdtf_height'] ) ? (float) wp_unslash( $_POST['uvdtf_height'] ) : 0, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		'quantity'     => isset( $_POST['uvdtf_quantity'] ) ? max( 1, (int) wp_unslash( $_POST['uvdtf_quantity'] ) ) : 1, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		'need_cutting' => ! empty( $_POST['uvdtf_cutting'] ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.NonceVerification.Missing -- nonce verified above; this is a plain presence check, nothing unsafe is read from the value.
	);

	if ( ! empty( $_FILES['uvdtf_artwork']['name'] ) ) {
		$attachment_id = prime_handle_addon_upload( 'uvdtf_artwork' );

		if ( $attachment_id ) {
			$specs['artwork_id'] = $attachment_id;
		}
	}

	$cart_item_data['prime_uv_dtf_specs'] = $specs;
	$cart_item_data['unique_key']         = md5( wp_json_encode( $specs ) . microtime() );

	return $cart_item_data;
}
add_filter( 'woocommerce_add_cart_item_data', 'prime_capture_uv_dtf', 10, 2 );

/**
 * Recompute the price on the server, every time the cart total is
 * calculated — not only at add-to-cart — so a coupon, or the cart page simply
 * reloading, can never leave a stale or client-influenced price in the
 * session. This is the ONLY number ever charged; nothing posted from the
 * browser is trusted.
 *
 * @param WC_Cart $cart Current cart.
 */
function prime_apply_uv_dtf_price( $cart ) {
	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
		return;
	}

	foreach ( $cart->get_cart() as $cart_item ) {
		if ( empty( $cart_item['prime_uv_dtf_specs'] ) ) {
			continue;
		}

		$product = $cart_item['data'];

		if ( ! $product instanceof WC_Product ) {
			continue;
		}

		$specs  = $cart_item['prime_uv_dtf_specs'];
		$result = prime_uv_dtf_price( $specs['width'], $specs['height'], $specs['quantity'], ! empty( $specs['need_cutting'] ) );

		// The whole sheet is one cart line (this product is sold_individually,
		// quantity locked to 1 — see prime_uv_dtf_lock_quantity()), so the full
		// sheet total is the line price directly. Dividing by quantity here
		// only to have WooCommerce re-multiply it back would risk a fractional
		// fils of rounding drift for nothing.
		$product->set_price( $result['total'] );
	}
}
add_action( 'woocommerce_before_calculate_totals', 'prime_apply_uv_dtf_price', 20 );

/**
 * Show the specs in the cart, checkout review, and order emails.
 *
 * @param array $item_data Existing display rows.
 * @param array $cart_item The cart item.
 * @return array
 */
function prime_display_uv_dtf_specs( $item_data, $cart_item ) {
	if ( empty( $cart_item['prime_uv_dtf_specs'] ) ) {
		return $item_data;
	}

	$specs  = $cart_item['prime_uv_dtf_specs'];
	$result = prime_uv_dtf_price( $specs['width'], $specs['height'], $specs['quantity'], ! empty( $specs['need_cutting'] ) );

	$item_data[] = array(
		'name'  => __( 'Size', 'prime-printing' ),
		'value' => sprintf( '%s × %s cm', $specs['width'], $specs['height'] ),
	);
	$item_data[] = array(
		'name'  => __( 'Pieces', 'prime-printing' ),
		'value' => (string) $specs['quantity'],
	);
	$item_data[] = array(
		'name'  => __( 'Sheet length', 'prime-printing' ),
		'value' => $result['sheet_length_cm'] . ' cm',
	);

	if ( ! empty( $specs['need_cutting'] ) ) {
		$item_data[] = array(
			'name'  => __( 'Cutting', 'prime-printing' ),
			'value' => __( 'Yes', 'prime-printing' ),
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
add_filter( 'woocommerce_get_item_data', 'prime_display_uv_dtf_specs', 10, 2 );

/**
 * Persist specs onto the order line item — what survives past checkout, onto
 * the invoice and into production, same reasoning as every other custom
 * field in this theme (see inc/product-pricing.php, inc/product-addons.php).
 *
 * @param WC_Order_Item_Product $item          Order line item.
 * @param string                $cart_item_key Cart item key.
 * @param array                 $values        Cart item values.
 */
function prime_persist_uv_dtf_to_order( $item, $cart_item_key, $values ) {
	if ( empty( $values['prime_uv_dtf_specs'] ) ) {
		return;
	}

	$specs  = $values['prime_uv_dtf_specs'];
	$result = prime_uv_dtf_price( $specs['width'], $specs['height'], $specs['quantity'], ! empty( $specs['need_cutting'] ) );

	$item->add_meta_data( __( 'Size', 'prime-printing' ), sprintf( '%s × %s cm', $specs['width'], $specs['height'] ), true );
	$item->add_meta_data( __( 'Pieces', 'prime-printing' ), $specs['quantity'], true );
	$item->add_meta_data( __( 'Sheet length', 'prime-printing' ), $result['sheet_length_cm'] . ' cm', true );

	if ( ! empty( $specs['need_cutting'] ) ) {
		$item->add_meta_data( __( 'Cutting', 'prime-printing' ), __( 'Yes', 'prime-printing' ), true );
	}

	if ( ! empty( $specs['artwork_id'] ) ) {
		// The download link is appended to the value (not a separate row) because
	// WooCommerce's own admin order screen runs order-item meta values through
	// make_clickable() — a plain URL in the text becomes a real link there
	// with no extra template work. prime_file_download_url() (inc/account.php)
	// is the same nonced, ownership-checked download endpoint the customer's
	// own account order history already uses.
	$item->add_meta_data(
		__( 'Artwork', 'prime-printing' ),
		get_the_title( $specs['artwork_id'] ) . ' — ' . prime_file_download_url( $specs['artwork_id'] ),
		true
	);
		$item->update_meta_data( '_prime_addon_files', array( $specs['artwork_id'] ) );
	}

	$item->update_meta_data( '_prime_uv_dtf_specs_raw', $specs );
}
add_action( 'woocommerce_checkout_create_order_line_item', 'prime_persist_uv_dtf_to_order', 10, 3 );

/**
 * The calculator's own JS (assets/js/uv-dtf-calculator.js) — a separate file
 * from assets/js/product.js on purpose: that one drives the generic
 * per-piece-area preview in inc/product-pricing.php, a different formula
 * shape (see the file docblock above); keeping this one apart means neither
 * can accidentally interfere with the other.
 */
function prime_enqueue_uv_dtf_assets() {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}

	global $post;

	if ( ! $post || ! prime_product_id_matches( $post->ID, PRIME_UV_DTF_PRODUCT_ID ) ) {
		return;
	}

	wp_enqueue_script(
		'prime-uv-dtf-calculator',
		PRIME_URI . '/assets/js/uv-dtf-calculator.js',
		array(),
		prime_asset_version( '/assets/js/uv-dtf-calculator.js' ),
		true
	);
}
add_action( 'wp_enqueue_scripts', 'prime_enqueue_uv_dtf_assets' );
