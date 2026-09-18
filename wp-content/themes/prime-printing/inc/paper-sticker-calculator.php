<?php
/**
 * Paper Sticker (product id 2090) — real price calculator.
 *
 * The same treatment as inc/uv-dtf-calculator.php, for the same reason:
 * replaces an external iframe embed (paper-sticker.netlify.app, pasted into
 * the product's short description) with the same calculator inline in the
 * theme, restyled to match the site. The formula — 26×62cm usable sheet,
 * 0.2cm spacing, the sheet-count price tiers, the per-sheet lamination
 * charge — is ported line-for-line from Reem's own reference and is not
 * touched here.
 *
 * As with UV DTF, the browser only ever shows a preview: the old iframe
 * posted a browser-computed `custom_price` into the cart, which a customer
 * could have altered in devtools. prime_paper_sticker_price() below runs
 * again in PHP on every cart calculation and is the only number ever charged.
 *
 * Kept as its own file rather than merged with the UV DTF one because the two
 * price on genuinely different bases (roll length vs. tiered sheet count) and
 * collect different fields — the plumbing rhymes, but nothing real is shared.
 * If a third and fourth calculator land, that is the point to factor the
 * common hooks out, not before.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

/**
 * The one product this applies to.
 */
const PRIME_PAPER_STICKER_PRODUCT_ID = 2090;

/**
 * Reem's real figures, ported verbatim from the reference calculator.
 * Values here decide what a customer is charged, so they stay in code rather
 * than a wp-admin field a misclick could break pricing on.
 *
 * @return array
 */
function prime_paper_sticker_constants() {
	return array(
		// Usable printing area, not the physical sheet (33×70cm).
		'sheet_width_cm'   => 26,
		'sheet_height_cm'  => 62,
		'spacing_cm'       => 0.2,
		'lamination_price' => 0.05,
		// Price per sheet, by how many sheets the order needs.
		'tiers'            => array(
			array( 'min' => 1,  'max' => 5,  'price' => 3.0 ),
			array( 'min' => 6,  'max' => 10, 'price' => 2.0 ),
			array( 'min' => 11, 'max' => 20, 'price' => 1.5 ),
			array( 'min' => 21, 'max' => 50, 'price' => 1.0 ),
		),
		// Anything above the last tier.
		'tier_above'       => 0.8,
	);
}

/**
 * The sticker shapes offered. Display only — the shape does not change the
 * price in Reem's formula, it is recorded on the order for production.
 *
 * @return array<string, string>
 */
function prime_paper_sticker_shapes() {
	return array(
		'round'     => __( 'Round', 'prime-printing' ),
		'square'    => __( 'Square', 'prime-printing' ),
		'rectangle' => __( 'Rectangle', 'prime-printing' ),
		'custom'    => __( 'Custom shape', 'prime-printing' ),
	);
}

/**
 * Price per sheet for a given sheet count.
 *
 * @param int $sheets Sheets needed.
 * @return float
 */
function prime_paper_sticker_sheet_price( $sheets ) {
	$c = prime_paper_sticker_constants();

	foreach ( $c['tiers'] as $tier ) {
		if ( $sheets >= $tier['min'] && $sheets <= $tier['max'] ) {
			return (float) $tier['price'];
		}
	}

	return (float) $c['tier_above'];
}

/**
 * How many stickers fit a sheet, how many sheets the run needs, what it costs.
 *
 * @param float $width_cm   One sticker's width.
 * @param float $height_cm  One sticker's height.
 * @param int   $quantity   Pieces wanted.
 * @param bool  $lamination Whether lamination was chosen.
 * @return array{pieces_per_sheet: int, sheets: int, sheet_price: float, sheet_cost: float, lamination_cost: float, total: float}
 */
function prime_paper_sticker_price( $width_cm, $height_cm, $quantity, $lamination ) {
	$c = prime_paper_sticker_constants();

	$width_cm  = max( 0.1, (float) $width_cm );
	$height_cm = max( 0.1, (float) $height_cm );
	$quantity  = max( 1, (int) $quantity );

	$across = (int) floor( $c['sheet_width_cm'] / ( $width_cm + $c['spacing_cm'] ) );
	$down   = (int) floor( $c['sheet_height_cm'] / ( $height_cm + $c['spacing_cm'] ) );

	$pieces_per_sheet = $across * $down;

	// A design bigger than the usable sheet cannot be printed at all — the
	// reference bails out here rather than dividing by zero.
	if ( $pieces_per_sheet < 1 ) {
		return array(
			'pieces_per_sheet' => 0,
			'sheets'           => 0,
			'sheet_price'      => 0.0,
			'sheet_cost'       => 0.0,
			'lamination_cost'  => 0.0,
			'total'            => 0.0,
		);
	}

	$sheets      = (int) ceil( $quantity / $pieces_per_sheet );
	$sheet_price = prime_paper_sticker_sheet_price( $sheets );
	$sheet_cost  = $sheets * $sheet_price;

	$lamination_cost = $lamination ? $sheets * $c['lamination_price'] : 0.0;

	return array(
		'pieces_per_sheet' => $pieces_per_sheet,
		'sheets'           => $sheets,
		'sheet_price'      => $sheet_price,
		'sheet_cost'       => round( $sheet_cost, 3 ),
		'lamination_cost'  => round( $lamination_cost, 3 ),
		'total'            => round( $sheet_cost + $lamination_cost, 3 ),
	);
}

/**
 * Is the product on this page the paper sticker?
 *
 * @return bool
 */
function prime_is_paper_sticker_product() {
	global $product;

	return $product instanceof WC_Product && prime_product_id_matches( $product->get_id(), PRIME_PAPER_STICKER_PRODUCT_ID );
}

/**
 * Render the calculator on the product page.
 */
function prime_render_paper_sticker_calculator() {
	if ( ! prime_is_paper_sticker_product() ) {
		return;
	}

	$c = prime_paper_sticker_constants();
	?>
	<div
		class="prime-configurator-fields"
		data-paper-sticker-calculator
		data-sheet-width="<?php echo esc_attr( $c['sheet_width_cm'] ); ?>"
		data-sheet-height="<?php echo esc_attr( $c['sheet_height_cm'] ); ?>"
		data-spacing="<?php echo esc_attr( $c['spacing_cm'] ); ?>"
		data-lamination-price="<?php echo esc_attr( $c['lamination_price'] ); ?>"
		data-tiers="<?php echo esc_attr( wp_json_encode( $c['tiers'] ) ); ?>"
		data-tier-above="<?php echo esc_attr( $c['tier_above'] ); ?>"
		data-currency="<?php echo esc_attr( get_woocommerce_currency_symbol() ); ?>"
	>
		<div class="prime-field">
			<label for="paper-shape"><?php esc_html_e( 'Sticker shape', 'prime-printing' ); ?></label>
			<select id="paper-shape" name="paper_shape" data-paper-input>
				<?php foreach ( prime_paper_sticker_shapes() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( 'rectangle', $value ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>

		<div class="prime-field prime-field--duo">
			<div class="prime-field">
				<label for="paper-w"><?php esc_html_e( 'Width (cm)', 'prime-printing' ); ?></label>
				<input type="number" id="paper-w" name="paper_width" min="0.1" step="0.1" value="10" data-paper-input>
			</div>
			<div class="prime-field">
				<label for="paper-h"><?php esc_html_e( 'Height (cm)', 'prime-printing' ); ?></label>
				<input type="number" id="paper-h" name="paper_height" min="0.1" step="0.1" value="10" data-paper-input>
			</div>
		</div>

		<div class="prime-field">
			<label for="paper-q"><?php esc_html_e( 'Quantity (pcs)', 'prime-printing' ); ?></label>
			<input type="number" id="paper-q" name="paper_quantity" min="1" step="1" value="50" data-paper-input>
			<span class="prime-field__error" data-prime-error="q"><?php esc_html_e( 'Enter how many pieces you need', 'prime-printing' ); ?></span>
		</div>

		<label class="prime-check">
			<input type="checkbox" id="paper-lam" name="paper_lamination" value="1" data-paper-input>
			<span>
				<?php
				printf(
					/* translators: %s: lamination price per sheet. */
					esc_html__( 'Add lamination (+%s per sheet) — recommended for anything that gets wet', 'prime-printing' ),
					esc_html( number_format( $c['lamination_price'], 3 ) )
				);
				?>
			</span>
		</label>

		<div class="prime-field">
			<label for="paper-file"><?php esc_html_e( 'Artwork file (optional)', 'prime-printing' ); ?></label>
			<input type="file" id="paper-file" name="paper_artwork" accept=".pdf,.ai,.eps,.svg,.jpg,.jpeg,.png">
			<span class="prime-field__hint"><?php esc_html_e( 'You can also send it later on WhatsApp — the order still goes through without it.', 'prime-printing' ); ?></span>
		</div>

		<div class="prime-calcrow">
			<div class="prime-calcbox">
				<div class="prime-calcbox__k"><?php esc_html_e( 'Stickers / sheet', 'prime-printing' ); ?></div>
				<div class="prime-calcbox__v" data-paper-per-sheet>—</div>
			</div>
			<div class="prime-calcbox">
				<div class="prime-calcbox__k"><?php esc_html_e( 'Sheets needed', 'prime-printing' ); ?></div>
				<div class="prime-calcbox__v" data-paper-sheets>—</div>
			</div>
			<div class="prime-calcbox">
				<div class="prime-calcbox__k"><?php esc_html_e( 'Price / sheet', 'prime-printing' ); ?></div>
				<div class="prime-calcbox__v" data-paper-sheet-price>—</div>
			</div>
		</div>

		<div class="prime-calcrow">
			<div class="prime-calcbox">
				<div class="prime-calcbox__k"><?php esc_html_e( 'Sheet cost', 'prime-printing' ); ?></div>
				<div class="prime-calcbox__v" data-paper-sheet-cost>—</div>
			</div>
			<div class="prime-calcbox prime-calcbox--hi">
				<div class="prime-calcbox__k"><?php esc_html_e( 'Total price', 'prime-printing' ); ?></div>
				<div class="prime-calcbox__v" data-paper-total>—</div>
			</div>
		</div>

		<p class="prime-calc-note" data-paper-note>
			<?php
			printf(
				/* translators: 1: usable width, 2: usable height, 3: spacing. */
				esc_html__( 'Printing area %1$s × %2$s cm, %3$s cm between stickers. Final price is recalculated on our server when added to cart.', 'prime-printing' ),
				esc_html( $c['sheet_width_cm'] ),
				esc_html( $c['sheet_height_cm'] ),
				esc_html( $c['spacing_cm'] )
			);
			?>
		</p>

		<?php wp_nonce_field( 'prime_paper_sticker', 'prime_paper_sticker_nonce' ); ?>
	</div>
	<?php
}
add_action( 'woocommerce_before_add_to_cart_button', 'prime_render_paper_sticker_calculator', 5 );

/**
 * One run is one cart line — the piece count is a spec, not a WooCommerce
 * quantity multiplier, exactly as for UV DTF.
 *
 * @param bool       $sold_individually Current value.
 * @param WC_Product $product           Product being checked.
 * @return bool
 */
function prime_paper_sticker_lock_quantity( $sold_individually, $product ) {
	if ( $product instanceof WC_Product && prime_product_id_matches( $product->get_id(), PRIME_PAPER_STICKER_PRODUCT_ID ) ) {
		return true;
	}

	return $sold_individually;
}
add_filter( 'woocommerce_is_sold_individually', 'prime_paper_sticker_lock_quantity', 10, 2 );

/**
 * Block add-to-cart when the inputs don't make sense.
 *
 * @param bool $passed     Validation state so far.
 * @param int  $product_id Product being added.
 * @return bool
 */
function prime_validate_paper_sticker( $passed, $product_id ) {
	if ( ! prime_product_id_matches( $product_id, PRIME_PAPER_STICKER_PRODUCT_ID ) ) {
		return $passed;
	}

	$width  = isset( $_POST['paper_width'] ) ? (float) wp_unslash( $_POST['paper_width'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only range check; authoritative validation happens again in prime_capture_paper_sticker() after the nonce check.
	$height = isset( $_POST['paper_height'] ) ? (float) wp_unslash( $_POST['paper_height'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$qty    = isset( $_POST['paper_quantity'] ) ? (int) wp_unslash( $_POST['paper_quantity'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

	if ( $width < 0.1 || $height < 0.1 ) {
		wc_add_notice( __( 'Enter a width and height for your sticker.', 'prime-printing' ), 'error' );
		$passed = false;
	}

	if ( $qty < 1 ) {
		wc_add_notice( __( 'Enter how many pieces you need.', 'prime-printing' ), 'error' );
		$passed = false;
	}

	// A design larger than the usable sheet can't be printed — catch it here
	// rather than letting it reach the cart at a zero price.
	if ( $width >= 0.1 && $height >= 0.1 ) {
		$result = prime_paper_sticker_price( $width, $height, max( 1, $qty ), false );

		if ( $result['pieces_per_sheet'] < 1 ) {
			$c = prime_paper_sticker_constants();
			wc_add_notice(
				sprintf(
					/* translators: 1: usable width, 2: usable height. */
					__( 'That size is bigger than the printing area (%1$s × %2$s cm). Try a smaller sticker, or contact us for a custom quote.', 'prime-printing' ),
					$c['sheet_width_cm'],
					$c['sheet_height_cm']
				),
				'error'
			);
			$passed = false;
		}
	}

	// Artwork is optional here too — a customer can send it on WhatsApp after.

	return $passed;
}
add_filter( 'woocommerce_add_to_cart_validation', 'prime_validate_paper_sticker', 10, 2 );

/**
 * Capture the submitted specs onto the cart item. No price is read from the
 * request at any point.
 *
 * @param array $cart_item_data Existing cart item data.
 * @param int   $product_id     Product being added.
 * @return array
 */
function prime_capture_paper_sticker( $cart_item_data, $product_id ) {
	if ( ! prime_product_id_matches( $product_id, PRIME_PAPER_STICKER_PRODUCT_ID ) ) {
		return $cart_item_data;
	}

	if ( ! isset( $_POST['prime_paper_sticker_nonce'] ) ||
		! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['prime_paper_sticker_nonce'] ) ), 'prime_paper_sticker' )
	) {
		return $cart_item_data;
	}

	$shape  = isset( $_POST['paper_shape'] ) ? sanitize_key( wp_unslash( $_POST['paper_shape'] ) ) : 'rectangle';
	$shapes = prime_paper_sticker_shapes();

	$specs = array(
		// Only a shape this product actually offers is stored — a request
		// could otherwise invent one and have it printed on the order.
		'shape'      => isset( $shapes[ $shape ] ) ? $shape : 'rectangle',
		'width'      => isset( $_POST['paper_width'] ) ? (float) wp_unslash( $_POST['paper_width'] ) : 0, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		'height'     => isset( $_POST['paper_height'] ) ? (float) wp_unslash( $_POST['paper_height'] ) : 0, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		'quantity'   => isset( $_POST['paper_quantity'] ) ? max( 1, (int) wp_unslash( $_POST['paper_quantity'] ) ) : 1, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		'lamination' => ! empty( $_POST['paper_lamination'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
	);

	if ( ! empty( $_FILES['paper_artwork']['name'] ) ) {
		$attachment_id = prime_handle_addon_upload( 'paper_artwork' );

		if ( $attachment_id ) {
			$specs['artwork_id'] = $attachment_id;
		}
	}

	$cart_item_data['prime_paper_sticker_specs'] = $specs;
	$cart_item_data['unique_key']                = md5( wp_json_encode( $specs ) . microtime() );

	return $cart_item_data;
}
add_filter( 'woocommerce_add_cart_item_data', 'prime_capture_paper_sticker', 10, 2 );

/**
 * Recompute the price server-side on every cart calculation. The only number
 * ever charged; nothing posted from the browser is trusted.
 *
 * @param WC_Cart $cart Current cart.
 */
function prime_apply_paper_sticker_price( $cart ) {
	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
		return;
	}

	foreach ( $cart->get_cart() as $cart_item ) {
		if ( empty( $cart_item['prime_paper_sticker_specs'] ) ) {
			continue;
		}

		$product = $cart_item['data'];

		if ( ! $product instanceof WC_Product ) {
			continue;
		}

		$specs  = $cart_item['prime_paper_sticker_specs'];
		$result = prime_paper_sticker_price( $specs['width'], $specs['height'], $specs['quantity'], ! empty( $specs['lamination'] ) );

		// The whole run is one cart line (sold_individually, quantity locked
		// to 1), so the run total is the line price directly.
		$product->set_price( $result['total'] );
	}
}
add_action( 'woocommerce_before_calculate_totals', 'prime_apply_paper_sticker_price', 20 );

/**
 * Show the specs in the cart, checkout review, and order emails.
 *
 * @param array $item_data Existing display rows.
 * @param array $cart_item The cart item.
 * @return array
 */
function prime_display_paper_sticker_specs( $item_data, $cart_item ) {
	if ( empty( $cart_item['prime_paper_sticker_specs'] ) ) {
		return $item_data;
	}

	$specs  = $cart_item['prime_paper_sticker_specs'];
	$shapes = prime_paper_sticker_shapes();
	$result = prime_paper_sticker_price( $specs['width'], $specs['height'], $specs['quantity'], ! empty( $specs['lamination'] ) );

	$item_data[] = array(
		'name'  => __( 'Shape', 'prime-printing' ),
		'value' => esc_html( $shapes[ $specs['shape'] ] ?? $specs['shape'] ),
	);
	$item_data[] = array(
		'name'  => __( 'Size', 'prime-printing' ),
		'value' => sprintf( '%s × %s cm', $specs['width'], $specs['height'] ),
	);
	$item_data[] = array(
		'name'  => __( 'Pieces', 'prime-printing' ),
		'value' => (string) $specs['quantity'],
	);
	$item_data[] = array(
		'name'  => __( 'Sheets', 'prime-printing' ),
		/* translators: 1: sheet count, 2: stickers per sheet. */
		'value' => sprintf( __( '%1$s (%2$s per sheet)', 'prime-printing' ), $result['sheets'], $result['pieces_per_sheet'] ),
	);
	$item_data[] = array(
		'name'  => __( 'Lamination', 'prime-printing' ),
		'value' => ! empty( $specs['lamination'] ) ? __( 'Yes', 'prime-printing' ) : __( 'No', 'prime-printing' ),
	);

	if ( ! empty( $specs['artwork_id'] ) ) {
		$item_data[] = array(
			'name'  => __( 'Artwork', 'prime-printing' ),
			'value' => esc_html( get_the_title( $specs['artwork_id'] ) ),
		);
	}

	return $item_data;
}
add_filter( 'woocommerce_get_item_data', 'prime_display_paper_sticker_specs', 10, 2 );

/**
 * Persist specs onto the order line item.
 *
 * @param WC_Order_Item_Product $item          Order line item.
 * @param string                $cart_item_key Cart item key.
 * @param array                 $values        Cart item values.
 */
function prime_persist_paper_sticker_to_order( $item, $cart_item_key, $values ) {
	if ( empty( $values['prime_paper_sticker_specs'] ) ) {
		return;
	}

	$specs  = $values['prime_paper_sticker_specs'];
	$shapes = prime_paper_sticker_shapes();
	$result = prime_paper_sticker_price( $specs['width'], $specs['height'], $specs['quantity'], ! empty( $specs['lamination'] ) );

	$item->add_meta_data( __( 'Shape', 'prime-printing' ), $shapes[ $specs['shape'] ] ?? $specs['shape'], true );
	$item->add_meta_data( __( 'Size', 'prime-printing' ), sprintf( '%s × %s cm', $specs['width'], $specs['height'] ), true );
	$item->add_meta_data( __( 'Pieces', 'prime-printing' ), $specs['quantity'], true );
	$item->add_meta_data(
		__( 'Sheets', 'prime-printing' ),
		/* translators: 1: sheet count, 2: stickers per sheet. */
		sprintf( __( '%1$s (%2$s per sheet)', 'prime-printing' ), $result['sheets'], $result['pieces_per_sheet'] ),
		true
	);
	$item->add_meta_data( __( 'Lamination', 'prime-printing' ), ! empty( $specs['lamination'] ) ? __( 'Yes', 'prime-printing' ) : __( 'No', 'prime-printing' ), true );

	if ( ! empty( $specs['artwork_id'] ) ) {
		// Same as everywhere else: the download URL rides along in the value
		// so WooCommerce's admin order screen linkifies it via make_clickable().
		$item->add_meta_data(
			__( 'Artwork', 'prime-printing' ),
			get_the_title( $specs['artwork_id'] ) . ' — ' . prime_file_download_url( $specs['artwork_id'] ),
			true
		);
		$item->update_meta_data( '_prime_addon_files', array( $specs['artwork_id'] ) );
	}

	$item->update_meta_data( '_prime_paper_sticker_specs_raw', $specs );
}
add_action( 'woocommerce_checkout_create_order_line_item', 'prime_persist_paper_sticker_to_order', 10, 3 );

/**
 * The calculator's own JS.
 */
function prime_enqueue_paper_sticker_assets() {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}

	global $post;

	if ( ! $post || ! prime_product_id_matches( $post->ID, PRIME_PAPER_STICKER_PRODUCT_ID ) ) {
		return;
	}

	wp_enqueue_script(
		'prime-paper-sticker-calculator',
		PRIME_URI . '/assets/js/paper-sticker-calculator.js',
		array(),
		prime_asset_version( '/assets/js/paper-sticker-calculator.js' ),
		true
	);
}
add_action( 'wp_enqueue_scripts', 'prime_enqueue_paper_sticker_assets' );
