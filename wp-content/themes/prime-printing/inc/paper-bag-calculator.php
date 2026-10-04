<?php
/**
 * Custom Paper Bag — size, quantity and the quote flow.
 *
 * The customer types the bag's width, height and depth (cm) and how many
 * bags they want; the page draws the flat sheet the bag is made from, and
 * the Prime Designer plugin's "Design it now" lays the dieline out from the
 * same three numbers (plugin class-bag.php, shared bag.ts): front, back, the
 * two sides and the base, each labelled.
 *
 * Three ways to order (Reem, 2026-10-03/04):
 *   - at the calculated price: the formula below, paid online like any product;
 *   - request a quote: the order is placed free as "Quote requested", the shop
 *     prices it and sends a pay link (inc/paper-bag-quotes.php);
 *   - with a quote code the shop gave them, which prices the order for that
 *     exact size and quantity (same file).
 * Above `max_online_qty` bags only a quote is offered.
 *
 * As with the sticker calculators, the browser only previews:
 * prime_paper_bag_price() below runs again in PHP on every cart calculation
 * and is the only number ever charged.
 *
 * The product is found by its slug (custom-paper-bag), so it can be created
 * in wp-admin without touching code; a product can also be pinned by id.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pinned product id, if any; 0 = find the product by slug alone.
 */
const PRIME_PAPER_BAG_PRODUCT_ID = 0;

/**
 * Slugs of the product this calculator belongs to.
 *
 * @return string[]
 */
function prime_paper_bag_slugs() {
	return array( 'custom-paper-bag', 'paper-bag', 'custom-paper-bags' );
}

/**
 * The size limits, in cm. The same as the designer's (plugin Binder_Bag):
 * a design is refused outside them, so the calculator must not accept more.
 *
 * @return array
 */
function prime_paper_bag_limits() {
	return array(
		'w' => array( 6, 50 ),
		'h' => array( 8, 60 ),
		'd' => array( 3, 25 ),
	);
}

/**
 * The construction the dieline uses (plugin Binder_Bag, shared bag.ts): the
 * flat sheet is glue flap + 2 × width + 2 × depth wide, and top fold +
 * height + base (depth / 2 + overlap) tall. Centimetres here.
 *
 * @return array
 */
function prime_paper_bag_construction() {
	return array(
		'glue_cm'       => 1.5,
		'top_fold_cm'   => 3.0,
		'base_extra_cm' => 1.5,
		'bleed_cm'      => 0.3,
	);
}

/**
 * The cost basis. PLACEHOLDER FIGURES (2026-10-04): Reem has not given a bag
 * formula yet; these price by the flat sheet's area plus a per-bag making
 * cost, with quantity tiers, a setup charge and a minimum, so the product
 * works end to end and the numbers can be corrected in one place. Every
 * number here decides what a customer is charged.
 *
 * @return array
 */
function prime_paper_bag_constants() {
	return array(
		// Paper + print, per square metre of flat sheet (bleed included).
		'sheet_per_m2'   => 1.200,
		// Folding, gluing, handles: per bag.
		'making_each'    => 0.150,
		// One-off per order: plates / setup / die.
		'setup'          => 5.000,
		// Smallest charge for a run.
		'min_order'      => 10.000,
		// Discount on the per-bag part by quantity (the setup is never discounted).
		'tiers'          => array(
			array( 'min' => 1,   'max' => 49,  'factor' => 1.00 ),
			array( 'min' => 50,  'max' => 99,  'factor' => 0.90 ),
			array( 'min' => 100, 'max' => 249, 'factor' => 0.80 ),
			array( 'min' => 250, 'max' => 499, 'factor' => 0.72 ),
		),
		'tier_above'     => 0.65,
		// Runs bigger than this are quoted by hand, never priced online.
		'max_online_qty' => 2000,
	);
}

/**
 * Quantity factor for a run.
 *
 * @param int $quantity Bags.
 * @return float
 */
function prime_paper_bag_factor( $quantity ) {
	$c = prime_paper_bag_constants();

	foreach ( $c['tiers'] as $tier ) {
		if ( $quantity >= $tier['min'] && $quantity <= $tier['max'] ) {
			return (float) $tier['factor'];
		}
	}

	return (float) $c['tier_above'];
}

/**
 * The calculated price of a run.
 *
 * @param float $w_cm     Width.
 * @param float $h_cm     Height.
 * @param float $d_cm     Depth.
 * @param int   $quantity Bags.
 * @return array{sheet: array{w: float, h: float}, each: float, setup: float, total: float}
 */
function prime_paper_bag_calculate( $w_cm, $h_cm, $d_cm, $quantity ) {
	$c        = prime_paper_bag_constants();
	$k        = prime_paper_bag_construction();
	$quantity = max( 1, (int) $quantity );

	$sheet   = prime_paper_bag_sheet( (float) $w_cm, (float) $h_cm, (float) $d_cm );
	$area_m2 = ( ( $sheet['w'] + 2 * $k['bleed_cm'] ) / 100 ) * ( ( $sheet['h'] + 2 * $k['bleed_cm'] ) / 100 );
	$each    = ( $area_m2 * $c['sheet_per_m2'] + $c['making_each'] ) * prime_paper_bag_factor( $quantity );
	$total   = max( $c['min_order'], $c['setup'] + $each * $quantity );

	return array(
		'sheet' => $sheet,
		'each'  => round( $each, 3 ),
		'setup' => (float) $c['setup'],
		'total' => round( $total, 3 ),
	);
}

/**
 * Is a run of this many bags priced online at all?
 *
 * @param int $quantity Bags.
 * @return bool
 */
function prime_paper_bag_priced_online( $quantity ) {
	return (int) $quantity <= (int) prime_paper_bag_constants()['max_online_qty'];
}

/**
 * Is this cart line a quote request (no price yet)?
 *
 * @param array $specs Cart specs.
 * @return bool
 */
function prime_bag_specs_is_quote_request( array $specs ) {
	return empty( $specs['quote_code'] ) && 'quote' === ( $specs['mode'] ?? 'price' );
}

/**
 * The flat sheet a bag of this size is made from, in cm.
 *
 * @param float $w Width (front panel).
 * @param float $h Height.
 * @param float $d Depth (side gusset).
 * @return array{w: float, h: float}
 */
function prime_paper_bag_sheet( $w, $h, $d ) {
	$c = prime_paper_bag_construction();

	return array(
		'w' => round( $c['glue_cm'] + 2 * $w + 2 * $d, 1 ),
		'h' => round( $c['top_fold_cm'] + $h + $d / 2 + $c['base_extra_cm'], 1 ),
	);
}

/**
 * What a run costs: the quote's price when a valid code was given; nothing
 * for a quote request (the shop prices it); else the calculated price.
 *
 * @param array $specs Cart specs (width, height, depth, quantity, mode, quote_code).
 * @return float
 */
function prime_paper_bag_price( array $specs ) {
	if ( ! empty( $specs['quote_code'] ) ) {
		$q = prime_bag_quote_find( $specs['quote_code'] );

		return $q && ! is_wp_error( prime_bag_quote_check( $q['code'], $specs['width'], $specs['height'], $specs['depth'], $specs['quantity'] ) ) ? (float) $q['price'] : 0.0;
	}
	if ( prime_bag_specs_is_quote_request( $specs ) ) {
		return 0.0;
	}

	return prime_paper_bag_calculate( $specs['width'], $specs['height'], $specs['depth'], $specs['quantity'] )['total'];
}

/**
 * Is this product the custom paper bag?
 *
 * @param int $product_id Product id.
 * @return bool
 */
function prime_is_paper_bag_product_id( $product_id ) {
	$product_id = (int) $product_id;

	if ( $product_id < 1 ) {
		return false;
	}
	if ( PRIME_PAPER_BAG_PRODUCT_ID > 0 && prime_product_id_matches( $product_id, PRIME_PAPER_BAG_PRODUCT_ID ) ) {
		return true;
	}

	return in_array( (string) get_post_field( 'post_name', $product_id ), prime_paper_bag_slugs(), true );
}

/**
 * Is the product on this page the paper bag?
 *
 * @return bool
 */
function prime_is_paper_bag_product() {
	global $product;

	return $product instanceof WC_Product && prime_is_paper_bag_product_id( $product->get_id() );
}

/**
 * Read and range-check the three sizes and the quantity from a request.
 *
 * @param array $src $_POST (unslashed by the caller is not required: numbers only).
 * @return array{w: float, h: float, d: float, qty: int, ok: bool}
 */
function prime_paper_bag_read( array $src ) {
	$lim = prime_paper_bag_limits();
	$w   = isset( $src['bag_width'] ) ? round( (float) $src['bag_width'], 1 ) : 0;
	$h   = isset( $src['bag_height'] ) ? round( (float) $src['bag_height'], 1 ) : 0;
	$d   = isset( $src['bag_depth'] ) ? round( (float) $src['bag_depth'], 1 ) : 0;
	$qty = isset( $src['bag_quantity'] ) ? (int) $src['bag_quantity'] : 0;

	$ok = $w >= $lim['w'][0] && $w <= $lim['w'][1] && $h >= $lim['h'][0] && $h <= $lim['h'][1] && $d >= $lim['d'][0] && $d <= $lim['d'][1];

	return array(
		'w'   => $w,
		'h'   => $h,
		'd'   => $d,
		'qty' => $qty,
		'ok'  => $ok,
	);
}

/**
 * Render the calculator on the product page.
 */
function prime_render_paper_bag_calculator() {
	if ( ! prime_is_paper_bag_product() ) {
		return;
	}

	$c   = prime_paper_bag_constants();
	$k   = prime_paper_bag_construction();
	$lim = prime_paper_bag_limits();
	?>
	<div
		class="prime-configurator-fields"
		data-paper-bag-calculator
		data-limits="<?php echo esc_attr( wp_json_encode( $lim ) ); ?>"
		data-construction="<?php echo esc_attr( wp_json_encode( $k ) ); ?>"
		data-pricing="<?php echo esc_attr( wp_json_encode( $c ) ); ?>"
		data-currency="<?php echo esc_attr( get_woocommerce_currency_symbol() ); ?>"
		data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
		data-nonce="<?php echo esc_attr( wp_create_nonce( 'prime_bag_quote' ) ); ?>"
		data-label-quote="<?php esc_attr_e( 'Request a quote', 'prime-printing' ); ?>"
		data-label-order="<?php esc_attr_e( 'Add to cart', 'prime-printing' ); ?>"
		data-quote-text="<?php esc_attr_e( 'Quote on request', 'prime-printing' ); ?>"
	>
		<p class="prime-field__hint">
			<?php esc_html_e( 'Measure the bag you want: the width of its front, its height, and its depth (the side gusset). We lay out the flat sheet — front, back, sides and base — and you design each panel.', 'prime-printing' ); ?>
		</p>

		<div class="prime-field prime-field--trio">
			<div class="prime-field">
				<label for="bag-w"><?php esc_html_e( 'Width (cm)', 'prime-printing' ); ?></label>
				<input type="number" id="bag-w" name="bag_width" min="<?php echo esc_attr( $lim['w'][0] ); ?>" max="<?php echo esc_attr( $lim['w'][1] ); ?>" step="0.1" value="25" data-bag-input>
				<span class="prime-field__error" data-prime-error="w">
					<?php
					/* translators: 1: min, 2: max. */
					printf( esc_html__( 'Between %1$s and %2$s cm', 'prime-printing' ), esc_html( $lim['w'][0] ), esc_html( $lim['w'][1] ) );
					?>
				</span>
			</div>
			<div class="prime-field">
				<label for="bag-h"><?php esc_html_e( 'Height (cm)', 'prime-printing' ); ?></label>
				<input type="number" id="bag-h" name="bag_height" min="<?php echo esc_attr( $lim['h'][0] ); ?>" max="<?php echo esc_attr( $lim['h'][1] ); ?>" step="0.1" value="32" data-bag-input>
				<span class="prime-field__error" data-prime-error="h">
					<?php
					/* translators: 1: min, 2: max. */
					printf( esc_html__( 'Between %1$s and %2$s cm', 'prime-printing' ), esc_html( $lim['h'][0] ), esc_html( $lim['h'][1] ) );
					?>
				</span>
			</div>
			<div class="prime-field">
				<label for="bag-d"><?php esc_html_e( 'Depth (cm)', 'prime-printing' ); ?></label>
				<input type="number" id="bag-d" name="bag_depth" min="<?php echo esc_attr( $lim['d'][0] ); ?>" max="<?php echo esc_attr( $lim['d'][1] ); ?>" step="0.1" value="10" data-bag-input>
				<span class="prime-field__error" data-prime-error="d">
					<?php
					/* translators: 1: min, 2: max. */
					printf( esc_html__( 'Between %1$s and %2$s cm', 'prime-printing' ), esc_html( $lim['d'][0] ), esc_html( $lim['d'][1] ) );
					?>
				</span>
			</div>
		</div>

		<div class="prime-field">
			<label for="bag-q"><?php esc_html_e( 'Quantity (bags)', 'prime-printing' ); ?></label>
			<input type="number" id="bag-q" name="bag_quantity" min="1" step="1" value="100" data-bag-input>
			<span class="prime-field__error" data-prime-error="q"><?php esc_html_e( 'Enter how many bags you need', 'prime-printing' ); ?></span>
		</div>

		<div class="prime-bag-sheet" data-bag-sheet aria-live="polite">
			<svg viewBox="0 0 100 60" preserveAspectRatio="xMidYMid meet" aria-hidden="true" data-bag-svg></svg>
			<p class="prime-field__hint">
				<?php esc_html_e( 'Flat sheet', 'prime-printing' ); ?>
				<strong data-bag-sheet-size>—</strong>
				· <?php esc_html_e( 'Front, Back, Side, Side, Base, with a glue flap and a top fold. You design every panel in the designer.', 'prime-printing' ); ?>
			</p>
		</div>

		<div class="prime-calcrow">
			<div class="prime-calcbox">
				<div class="prime-calcbox__k"><?php esc_html_e( 'Bags', 'prime-printing' ); ?></div>
				<div class="prime-calcbox__v" data-bag-count>—</div>
			</div>
			<div class="prime-calcbox">
				<div class="prime-calcbox__k"><?php esc_html_e( 'Price / bag', 'prime-printing' ); ?></div>
				<div class="prime-calcbox__v" data-bag-each>—</div>
			</div>
			<div class="prime-calcbox prime-calcbox--hi">
				<div class="prime-calcbox__k"><?php esc_html_e( 'Total price', 'prime-printing' ); ?></div>
				<div class="prime-calcbox__v" data-bag-total>—</div>
			</div>
		</div>

		<div class="prime-quote">
			<fieldset class="prime-quote__modes">
				<legend><strong><?php esc_html_e( 'How would you like to order?', 'prime-printing' ); ?></strong></legend>
				<label class="prime-check">
					<input type="radio" name="bag_mode" value="price" checked data-bag-mode>
					<span><?php esc_html_e( 'Order now at the price above — pay online and we start printing.', 'prime-printing' ); ?></span>
				</label>
				<label class="prime-check">
					<input type="radio" name="bag_mode" value="quote" data-bag-mode>
					<span><?php esc_html_e( 'Request a quote — place the order free; we send your price by email and WhatsApp within one working day, and you pay from the link to start printing.', 'prime-printing' ); ?></span>
				</label>
				<p class="prime-field__hint" data-bag-quote-only hidden>
					<?php
					printf(
						/* translators: %s: quantity. */
						esc_html__( 'Runs above %s bags are quoted by hand.', 'prime-printing' ),
						esc_html( number_format( $c['max_online_qty'] ) )
					);
					?>
				</p>
			</fieldset>
			<div class="prime-field">
				<label for="bag-code"><?php esc_html_e( 'Already have a quote code?', 'prime-printing' ); ?></label>
				<div class="prime-quote__code">
					<input type="text" id="bag-code" name="bag_quote_code" autocomplete="off" placeholder="PB-XXXXXX" data-bag-code>
					<button type="button" class="prime-btn prime-btn--ghost" data-bag-code-apply><?php esc_html_e( 'Apply', 'prime-printing' ); ?></button>
				</div>
				<span class="prime-field__hint" data-bag-code-msg><?php esc_html_e( 'Enter it to order straight away at your quoted price.', 'prime-printing' ); ?></span>
			</div>
		</div>

		<p class="prime-calc-note">
			<?php esc_html_e( 'Full-colour print on the whole sheet, bleed included. Final price is recalculated on our server when added to cart.', 'prime-printing' ); ?>
		</p>

		<?php wp_nonce_field( 'prime_paper_bag', 'prime_paper_bag_nonce' ); ?>
	</div>
	<?php
}
add_action( 'woocommerce_before_add_to_cart_button', 'prime_render_paper_bag_calculator', 5 );

/**
 * One run is one cart line: the bag count is a spec, not a WooCommerce
 * quantity multiplier, exactly as for the sticker calculators.
 *
 * @param bool       $sold_individually Current value.
 * @param WC_Product $product           Product being checked.
 * @return bool
 */
function prime_paper_bag_lock_quantity( $sold_individually, $product ) {
	if ( $product instanceof WC_Product && prime_is_paper_bag_product_id( $product->get_id() ) ) {
		return true;
	}

	return $sold_individually;
}
add_filter( 'woocommerce_is_sold_individually', 'prime_paper_bag_lock_quantity', 10, 2 );

/**
 * Block add-to-cart when the inputs don't make sense.
 *
 * @param bool $passed     Validation state so far.
 * @param int  $product_id Product being added.
 * @return bool
 */
function prime_validate_paper_bag( $passed, $product_id ) {
	if ( ! prime_is_paper_bag_product_id( $product_id ) ) {
		return $passed;
	}

	$in  = prime_paper_bag_read( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only range check; the nonce is checked in prime_capture_paper_bag().
	$lim = prime_paper_bag_limits();

	if ( ! $in['ok'] ) {
		wc_add_notice(
			sprintf(
				/* translators: 1-2: width range, 3-4: height range, 5-6: depth range. */
				__( 'Enter a bag width of %1$s–%2$s cm, a height of %3$s–%4$s cm and a depth of %5$s–%6$s cm.', 'prime-printing' ),
				$lim['w'][0],
				$lim['w'][1],
				$lim['h'][0],
				$lim['h'][1],
				$lim['d'][0],
				$lim['d'][1]
			),
			'error'
		);
		$passed = false;
	}

	if ( $in['qty'] < 1 ) {
		wc_add_notice( __( 'Enter how many bags you need.', 'prime-printing' ), 'error' );
		$passed = false;
	}

	// A quote code must be good for exactly this bag; a wrong one is refused
	// rather than ignored, so nobody places a free quote request by mistake.
	$code = isset( $_POST['bag_quote_code'] ) ? prime_bag_quote_clean_code( sanitize_text_field( wp_unslash( $_POST['bag_quote_code'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( $passed && '' !== $code ) {
		$q = prime_bag_quote_check( $code, $in['w'], $in['h'], $in['d'], $in['qty'] );
		if ( is_wp_error( $q ) ) {
			wc_add_notice( $q->get_error_message(), 'error' );
			$passed = false;
		}
	}

	return $passed;
}
add_filter( 'woocommerce_add_to_cart_validation', 'prime_validate_paper_bag', 10, 2 );

/**
 * Capture the submitted specs onto the cart item. No price is read from the
 * request at any point.
 *
 * @param array $cart_item_data Existing cart item data.
 * @param int   $product_id     Product being added.
 * @return array
 */
function prime_capture_paper_bag( $cart_item_data, $product_id ) {
	if ( ! prime_is_paper_bag_product_id( $product_id ) ) {
		return $cart_item_data;
	}

	if ( ! isset( $_POST['prime_paper_bag_nonce'] ) ||
		! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['prime_paper_bag_nonce'] ) ), 'prime_paper_bag' )
	) {
		return $cart_item_data;
	}

	$in = prime_paper_bag_read( $_POST );
	if ( ! $in['ok'] ) {
		return $cart_item_data;
	}

	$specs = array(
		'width'      => $in['w'],
		'height'     => $in['h'],
		'depth'      => $in['d'],
		'quantity'   => max( 1, $in['qty'] ),
		'mode'       => 'price',
		'quote_code' => '',
	);

	// Quote request when asked for, and always above the online limit.
	$mode = isset( $_POST['bag_mode'] ) ? sanitize_key( wp_unslash( $_POST['bag_mode'] ) ) : 'price';
	if ( 'quote' === $mode || ! prime_paper_bag_priced_online( $specs['quantity'] ) ) {
		$specs['mode'] = 'quote';
	}

	// A quote code prices the run; a wrong one is refused rather than ignored,
	// so the customer never places a free "quote request" by mistake.
	$code = isset( $_POST['bag_quote_code'] ) ? prime_bag_quote_clean_code( sanitize_text_field( wp_unslash( $_POST['bag_quote_code'] ) ) ) : '';
	if ( '' !== $code ) {
		$q = prime_bag_quote_check( $code, $specs['width'], $specs['height'], $specs['depth'], $specs['quantity'] );
		if ( is_wp_error( $q ) ) {
			return $cart_item_data; // Already refused in prime_validate_paper_bag().
		}
		$specs['quote_code'] = $q['code'];
	}

	$cart_item_data['prime_paper_bag_specs'] = $specs;
	$cart_item_data['unique_key']            = md5( wp_json_encode( $specs ) . microtime() );

	return $cart_item_data;
}
add_filter( 'woocommerce_add_cart_item_data', 'prime_capture_paper_bag', 10, 2 );

/**
 * Recompute the price server-side on every cart calculation. The only number
 * ever charged; nothing posted from the browser is trusted.
 *
 * @param WC_Cart $cart Current cart.
 */
function prime_apply_paper_bag_price( $cart ) {
	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
		return;
	}

	foreach ( $cart->get_cart() as $cart_item ) {
		if ( empty( $cart_item['prime_paper_bag_specs'] ) ) {
			continue;
		}

		$product = $cart_item['data'];

		if ( ! $product instanceof WC_Product ) {
			continue;
		}

		// The whole run is one cart line (sold_individually): the calculated or quoted price, or 0 while a quote is requested.
		$product->set_price( prime_paper_bag_price( $cart_item['prime_paper_bag_specs'] ) );
	}
}
add_action( 'woocommerce_before_calculate_totals', 'prime_apply_paper_bag_price', 20 );

/**
 * The rows the cart, checkout and order show for a run of bags.
 *
 * @param array $s Specs.
 * @return array<int, array{name: string, value: string}>
 */
function prime_paper_bag_rows( array $s ) {
	$sheet = prime_paper_bag_sheet( $s['width'], $s['height'], $s['depth'] );

	return array(
		array(
			'name'  => __( 'Bag size', 'prime-printing' ),
			'value' => sprintf( '%s × %s × %s cm', $s['width'], $s['height'], $s['depth'] ),
		),
		array(
			'name'  => __( 'Flat sheet', 'prime-printing' ),
			'value' => sprintf( '%s × %s cm', $sheet['w'], $sheet['h'] ),
		),
		array(
			'name'  => __( 'Bags', 'prime-printing' ),
			'value' => (string) $s['quantity'],
		),
		array(
			'name'  => __( 'Price', 'prime-printing' ),
			'value' => ! empty( $s['quote_code'] )
				? sprintf( __( 'Quote code %s', 'prime-printing' ), $s['quote_code'] )
				: ( prime_bag_specs_is_quote_request( $s ) ? __( 'Quote on request', 'prime-printing' ) : wp_strip_all_tags( wc_price( prime_paper_bag_price( $s ) ) ) ),
		),
	);
}

/**
 * Show the specs in the cart, checkout review, and order emails.
 *
 * @param array $item_data Existing display rows.
 * @param array $cart_item The cart item.
 * @return array
 */
function prime_display_paper_bag_specs( $item_data, $cart_item ) {
	if ( empty( $cart_item['prime_paper_bag_specs'] ) ) {
		return $item_data;
	}

	foreach ( prime_paper_bag_rows( $cart_item['prime_paper_bag_specs'] ) as $row ) {
		$item_data[] = $row;
	}

	return $item_data;
}
add_filter( 'woocommerce_get_item_data', 'prime_display_paper_bag_specs', 10, 2 );

/**
 * Persist specs onto the order line item.
 *
 * @param WC_Order_Item_Product $item          Order line item.
 * @param string                $cart_item_key Cart item key.
 * @param array                 $values        Cart item values.
 */
function prime_persist_paper_bag_to_order( $item, $cart_item_key, $values ) {
	if ( empty( $values['prime_paper_bag_specs'] ) ) {
		return;
	}

	foreach ( prime_paper_bag_rows( $values['prime_paper_bag_specs'] ) as $row ) {
		$item->add_meta_data( $row['name'], wp_strip_all_tags( $row['value'] ), true );
	}

	$specs = $values['prime_paper_bag_specs'];
	$item->update_meta_data( '_prime_paper_bag_specs_raw', $specs );
	if ( ! empty( $specs['quote_code'] ) ) {
		$item->update_meta_data( '_prime_bag_quote_code', $specs['quote_code'] );
	} elseif ( prime_bag_specs_is_quote_request( $specs ) ) {
		$item->update_meta_data( PRIME_BAG_QUOTE_ITEM_KEY, 'yes' );
	}
}
add_action( 'woocommerce_checkout_create_order_line_item', 'prime_persist_paper_bag_to_order', 10, 3 );

/**
 * The calculator's own JS.
 */
function prime_enqueue_paper_bag_assets() {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}

	global $post;

	if ( ! $post || ! prime_is_paper_bag_product_id( $post->ID ) ) {
		return;
	}

	wp_enqueue_script(
		'prime-paper-bag-calculator',
		PRIME_URI . '/assets/js/paper-bag-calculator.js',
		array(),
		prime_asset_version( '/assets/js/paper-bag-calculator.js' ),
		true
	);
}
add_action( 'wp_enqueue_scripts', 'prime_enqueue_paper_bag_assets' );
