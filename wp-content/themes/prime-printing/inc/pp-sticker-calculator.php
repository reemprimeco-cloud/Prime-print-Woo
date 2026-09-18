<?php
/**
 * PP Sticker / waterproof vinyl (product id 235) — real price calculator.
 *
 * Third of the same treatment (see inc/uv-dtf-calculator.php and
 * inc/paper-sticker-calculator.php): replaces an external iframe embed
 * (pp-sticker.netlify.app, pasted into the product's short description) with
 * the same calculator inline in the theme, restyled to match the site. The
 * formula — 82×32cm sheet, 0.2cm spacing, 5 KD a sheet, 10% off from ten
 * sheets, 0.5 KD a sheet for lamination — is ported line-for-line from Reem's
 * own reference and is not touched here.
 *
 * As with the other two, the browser only ever shows a preview; the old
 * iframe posted a browser-computed `custom_price` into the cart, which a
 * customer could have altered in devtools. prime_pp_sticker_price() below
 * runs again in PHP on every cart calculation and is the only number ever
 * charged.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

/**
 * The one product this applies to.
 */
const PRIME_PP_STICKER_PRODUCT_ID = 235;

/**
 * Reem's real figures, ported verbatim from the reference calculator.
 *
 * @return array
 */
function prime_pp_sticker_constants() {
	return array(
		'sheet_width_cm'    => 82,
		'sheet_height_cm'   => 32,
		'spacing_cm'        => 0.2,
		'price_per_sheet'   => 5.0,
		'lamination_price'  => 0.5,
		// From this many sheets, the sheet cost drops by the percentage below.
		'bulk_discount_qty' => 10,
		'bulk_discount_pct' => 0.10,
		// Above this many pieces the reference stops quoting and asks the
		// customer to get in touch — kept as a notice, not a hard block.
		'large_order_qty'   => 1000,
	);
}

/**
 * The sticker shapes offered. Display only — shape does not change the price.
 *
 * @return array<string, string>
 */
function prime_pp_sticker_shapes() {
	return array(
		'round'     => __( 'Round', 'prime-printing' ),
		'square'    => __( 'Square', 'prime-printing' ),
		'rectangle' => __( 'Rectangle', 'prime-printing' ),
		'custom'    => __( 'Custom shape', 'prime-printing' ),
	);
}

/**
 * How many stickers fit a sheet, how many sheets the run needs, what it costs.
 *
 * @param float $width_cm   One sticker's width.
 * @param float $height_cm  One sticker's height.
 * @param int   $quantity   Pieces wanted.
 * @param bool  $lamination Whether lamination was chosen.
 * @return array{pieces_per_sheet: int, sheets: int, base_cost: float, discount: float, lamination_cost: float, total: float}
 */
function prime_pp_sticker_price( $width_cm, $height_cm, $quantity, $lamination ) {
	$c = prime_pp_sticker_constants();

	$width_cm  = max( 0.1, (float) $width_cm );
	$height_cm = max( 0.1, (float) $height_cm );
	$quantity  = max( 1, (int) $quantity );

	$across = (int) floor( $c['sheet_width_cm'] / ( $width_cm + $c['spacing_cm'] ) );
	$down   = (int) floor( $c['sheet_height_cm'] / ( $height_cm + $c['spacing_cm'] ) );

	$pieces_per_sheet = $across * $down;

	// The reference divides by this without checking; a sticker bigger than
	// the sheet would make it Infinity there. Guarded here so an impossible
	// size can never reach the cart as a price at all — validation below
	// turns it into a proper message instead.
	if ( $pieces_per_sheet < 1 ) {
		return array(
			'pieces_per_sheet' => 0,
			'sheets'           => 0,
			'base_cost'        => 0.0,
			'discount'         => 0.0,
			'lamination_cost'  => 0.0,
			'total'            => 0.0,
		);
	}

	$sheets    = (int) ceil( $quantity / $pieces_per_sheet );
	$base_cost = $sheets * $c['price_per_sheet'];

	$discount = 0.0;

	if ( $sheets >= $c['bulk_discount_qty'] ) {
		$discount = $base_cost * $c['bulk_discount_pct'];
	}

	$lamination_cost = $lamination ? $sheets * $c['lamination_price'] : 0.0;

	return array(
		'pieces_per_sheet' => $pieces_per_sheet,
		'sheets'           => $sheets,
		'base_cost'        => round( $base_cost, 3 ),
		'discount'         => round( $discount, 3 ),
		'lamination_cost'  => round( $lamination_cost, 3 ),
		'total'            => round( ( $base_cost - $discount ) + $lamination_cost, 3 ),
	);
}

/**
 * Is the product on this page the PP sticker?
 *
 * @return bool
 */
function prime_is_pp_sticker_product() {
	global $product;

	return $product instanceof WC_Product && prime_product_id_matches( $product->get_id(), PRIME_PP_STICKER_PRODUCT_ID );
}

/**
 * Render the calculator on the product page.
 */
function prime_render_pp_sticker_calculator() {
	if ( ! prime_is_pp_sticker_product() ) {
		return;
	}

	$c = prime_pp_sticker_constants();
	?>
	<div
		class="prime-configurator-fields"
		data-pp-sticker-calculator
		data-sheet-width="<?php echo esc_attr( $c['sheet_width_cm'] ); ?>"
		data-sheet-height="<?php echo esc_attr( $c['sheet_height_cm'] ); ?>"
		data-spacing="<?php echo esc_attr( $c['spacing_cm'] ); ?>"
		data-price-per-sheet="<?php echo esc_attr( $c['price_per_sheet'] ); ?>"
		data-lamination-price="<?php echo esc_attr( $c['lamination_price'] ); ?>"
		data-bulk-qty="<?php echo esc_attr( $c['bulk_discount_qty'] ); ?>"
		data-bulk-pct="<?php echo esc_attr( $c['bulk_discount_pct'] ); ?>"
		data-large-order="<?php echo esc_attr( $c['large_order_qty'] ); ?>"
		data-currency="<?php echo esc_attr( get_woocommerce_currency_symbol() ); ?>"
	>
		<div class="prime-field">
			<label for="pp-shape"><?php esc_html_e( 'Sticker shape', 'prime-printing' ); ?></label>
			<select id="pp-shape" name="pp_shape" data-pp-input>
				<?php foreach ( prime_pp_sticker_shapes() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( 'rectangle', $value ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>

		<div class="prime-field prime-field--duo">
			<div class="prime-field">
				<label for="pp-w"><?php esc_html_e( 'Width (cm)', 'prime-printing' ); ?></label>
				<input type="number" id="pp-w" name="pp_width" min="0.1" step="0.1" value="10" data-pp-input>
			</div>
			<div class="prime-field">
				<label for="pp-h"><?php esc_html_e( 'Height (cm)', 'prime-printing' ); ?></label>
				<input type="number" id="pp-h" name="pp_height" min="0.1" step="0.1" value="10" data-pp-input>
			</div>
		</div>

		<div class="prime-field">
			<label for="pp-q"><?php esc_html_e( 'Quantity (pcs)', 'prime-printing' ); ?></label>
			<input type="number" id="pp-q" name="pp_quantity" min="1" step="1" value="50" data-pp-input>
			<span class="prime-field__error" data-prime-error="q"><?php esc_html_e( 'Enter how many pieces you need', 'prime-printing' ); ?></span>
		</div>

		<label class="prime-check">
			<input type="checkbox" id="pp-lam" name="pp_lamination" value="1" data-pp-input>
			<span>
				<?php
				printf(
					/* translators: %s: lamination price per sheet. */
					esc_html__( 'Add lamination (+%s per sheet)', 'prime-printing' ),
					esc_html( number_format( $c['lamination_price'], 3 ) )
				);
				?>
			</span>
		</label>

		<div class="prime-field">
			<label for="pp-file"><?php esc_html_e( 'Artwork file (optional)', 'prime-printing' ); ?></label>
			<input type="file" id="pp-file" name="pp_artwork" accept=".pdf,.ai,.eps,.svg,.jpg,.jpeg,.png">
			<span class="prime-field__hint"><?php esc_html_e( 'You can also send it later on WhatsApp — the order still goes through without it.', 'prime-printing' ); ?></span>
		</div>

		<div class="prime-calcrow">
			<div class="prime-calcbox">
				<div class="prime-calcbox__k"><?php esc_html_e( 'Stickers / sheet', 'prime-printing' ); ?></div>
				<div class="prime-calcbox__v" data-pp-per-sheet>—</div>
			</div>
			<div class="prime-calcbox">
				<div class="prime-calcbox__k"><?php esc_html_e( 'Sheets needed', 'prime-printing' ); ?></div>
				<div class="prime-calcbox__v" data-pp-sheets>—</div>
			</div>
			<div class="prime-calcbox">
				<div class="prime-calcbox__k"><?php esc_html_e( 'Sheet cost', 'prime-printing' ); ?></div>
				<div class="prime-calcbox__v" data-pp-base>—</div>
			</div>
		</div>

		<div class="prime-calcrow">
			<div class="prime-calcbox" data-pp-discount-box hidden>
				<div class="prime-calcbox__k"><?php esc_html_e( 'Bulk discount', 'prime-printing' ); ?></div>
				<div class="prime-calcbox__v" data-pp-discount>—</div>
			</div>
			<div class="prime-calcbox prime-calcbox--hi">
				<div class="prime-calcbox__k"><?php esc_html_e( 'Total price', 'prime-printing' ); ?></div>
				<div class="prime-calcbox__v" data-pp-total>—</div>
			</div>
		</div>

		<p class="prime-calc-note" data-pp-large hidden>
			<?php
			printf(
				/* translators: %s: piece count. */
				esc_html__( 'Over %s pieces — please contact us for a custom quote on a run this size.', 'prime-printing' ),
				esc_html( number_format( $c['large_order_qty'] ) )
			);
			?>
		</p>

		<p class="prime-calc-note">
			<?php
			printf(
				/* translators: 1: bulk discount percentage, 2: sheet count. */
				esc_html__( '%1$s%% off from %2$s sheets. Final price is recalculated on our server when added to cart.', 'prime-printing' ),
				esc_html( $c['bulk_discount_pct'] * 100 ),
				esc_html( $c['bulk_discount_qty'] )
			);
			?>
		</p>

		<?php wp_nonce_field( 'prime_pp_sticker', 'prime_pp_sticker_nonce' ); ?>
	</div>
	<?php
}
add_action( 'woocommerce_before_add_to_cart_button', 'prime_render_pp_sticker_calculator', 5 );

/**
 * One run is one cart line.
 *
 * @param bool       $sold_individually Current value.
 * @param WC_Product $product           Product being checked.
 * @return bool
 */
function prime_pp_sticker_lock_quantity( $sold_individually, $product ) {
	if ( $product instanceof WC_Product && prime_product_id_matches( $product->get_id(), PRIME_PP_STICKER_PRODUCT_ID ) ) {
		return true;
	}

	return $sold_individually;
}
add_filter( 'woocommerce_is_sold_individually', 'prime_pp_sticker_lock_quantity', 10, 2 );

/**
 * Block add-to-cart when the inputs don't make sense.
 *
 * @param bool $passed     Validation state so far.
 * @param int  $product_id Product being added.
 * @return bool
 */
function prime_validate_pp_sticker( $passed, $product_id ) {
	if ( ! prime_product_id_matches( $product_id, PRIME_PP_STICKER_PRODUCT_ID ) ) {
		return $passed;
	}

	$width  = isset( $_POST['pp_width'] ) ? (float) wp_unslash( $_POST['pp_width'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only range check; authoritative validation happens again in prime_capture_pp_sticker() after the nonce check.
	$height = isset( $_POST['pp_height'] ) ? (float) wp_unslash( $_POST['pp_height'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$qty    = isset( $_POST['pp_quantity'] ) ? (int) wp_unslash( $_POST['pp_quantity'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

	if ( $width < 0.1 || $height < 0.1 ) {
		wc_add_notice( __( 'Enter a width and height for your sticker.', 'prime-printing' ), 'error' );
		$passed = false;
	}

	if ( $qty < 1 ) {
		wc_add_notice( __( 'Enter how many pieces you need.', 'prime-printing' ), 'error' );
		$passed = false;
	}

	if ( $width >= 0.1 && $height >= 0.1 ) {
		$result = prime_pp_sticker_price( $width, $height, max( 1, $qty ), false );

		if ( $result['pieces_per_sheet'] < 1 ) {
			$c = prime_pp_sticker_constants();
			wc_add_notice(
				sprintf(
					/* translators: 1: sheet width, 2: sheet height. */
					__( 'That size is bigger than the sheet (%1$s × %2$s cm). Try a smaller sticker, or contact us for a custom quote.', 'prime-printing' ),
					$c['sheet_width_cm'],
					$c['sheet_height_cm']
				),
				'error'
			);
			$passed = false;
		}
	}

	return $passed;
}
add_filter( 'woocommerce_add_to_cart_validation', 'prime_validate_pp_sticker', 10, 2 );

/**
 * Capture the submitted specs onto the cart item. No price is read from the
 * request at any point.
 *
 * @param array $cart_item_data Existing cart item data.
 * @param int   $product_id     Product being added.
 * @return array
 */
function prime_capture_pp_sticker( $cart_item_data, $product_id ) {
	if ( ! prime_product_id_matches( $product_id, PRIME_PP_STICKER_PRODUCT_ID ) ) {
		return $cart_item_data;
	}

	if ( ! isset( $_POST['prime_pp_sticker_nonce'] ) ||
		! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['prime_pp_sticker_nonce'] ) ), 'prime_pp_sticker' )
	) {
		return $cart_item_data;
	}

	$shape  = isset( $_POST['pp_shape'] ) ? sanitize_key( wp_unslash( $_POST['pp_shape'] ) ) : 'rectangle';
	$shapes = prime_pp_sticker_shapes();

	$specs = array(
		'shape'      => isset( $shapes[ $shape ] ) ? $shape : 'rectangle',
		'width'      => isset( $_POST['pp_width'] ) ? (float) wp_unslash( $_POST['pp_width'] ) : 0, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		'height'     => isset( $_POST['pp_height'] ) ? (float) wp_unslash( $_POST['pp_height'] ) : 0, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		'quantity'   => isset( $_POST['pp_quantity'] ) ? max( 1, (int) wp_unslash( $_POST['pp_quantity'] ) ) : 1, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		'lamination' => ! empty( $_POST['pp_lamination'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
	);

	if ( ! empty( $_FILES['pp_artwork']['name'] ) ) {
		$attachment_id = prime_handle_addon_upload( 'pp_artwork' );

		if ( $attachment_id ) {
			$specs['artwork_id'] = $attachment_id;
		}
	}

	$cart_item_data['prime_pp_sticker_specs'] = $specs;
	$cart_item_data['unique_key']             = md5( wp_json_encode( $specs ) . microtime() );

	return $cart_item_data;
}
add_filter( 'woocommerce_add_cart_item_data', 'prime_capture_pp_sticker', 10, 2 );

/**
 * Recompute the price server-side on every cart calculation.
 *
 * @param WC_Cart $cart Current cart.
 */
function prime_apply_pp_sticker_price( $cart ) {
	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
		return;
	}

	foreach ( $cart->get_cart() as $cart_item ) {
		if ( empty( $cart_item['prime_pp_sticker_specs'] ) ) {
			continue;
		}

		$product = $cart_item['data'];

		if ( ! $product instanceof WC_Product ) {
			continue;
		}

		$specs  = $cart_item['prime_pp_sticker_specs'];
		$result = prime_pp_sticker_price( $specs['width'], $specs['height'], $specs['quantity'], ! empty( $specs['lamination'] ) );

		$product->set_price( $result['total'] );
	}
}
add_action( 'woocommerce_before_calculate_totals', 'prime_apply_pp_sticker_price', 20 );

/**
 * Show the specs in the cart, checkout review, and order emails.
 *
 * @param array $item_data Existing display rows.
 * @param array $cart_item The cart item.
 * @return array
 */
function prime_display_pp_sticker_specs( $item_data, $cart_item ) {
	if ( empty( $cart_item['prime_pp_sticker_specs'] ) ) {
		return $item_data;
	}

	$specs  = $cart_item['prime_pp_sticker_specs'];
	$shapes = prime_pp_sticker_shapes();
	$result = prime_pp_sticker_price( $specs['width'], $specs['height'], $specs['quantity'], ! empty( $specs['lamination'] ) );

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

	if ( $result['discount'] > 0 ) {
		$item_data[] = array(
			'name'  => __( 'Bulk discount', 'prime-printing' ),
			'value' => '−' . wc_price( $result['discount'] ),
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
add_filter( 'woocommerce_get_item_data', 'prime_display_pp_sticker_specs', 10, 2 );

/**
 * Persist specs onto the order line item.
 *
 * @param WC_Order_Item_Product $item          Order line item.
 * @param string                $cart_item_key Cart item key.
 * @param array                 $values        Cart item values.
 */
function prime_persist_pp_sticker_to_order( $item, $cart_item_key, $values ) {
	if ( empty( $values['prime_pp_sticker_specs'] ) ) {
		return;
	}

	$specs  = $values['prime_pp_sticker_specs'];
	$shapes = prime_pp_sticker_shapes();
	$result = prime_pp_sticker_price( $specs['width'], $specs['height'], $specs['quantity'], ! empty( $specs['lamination'] ) );

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
		$item->add_meta_data(
			__( 'Artwork', 'prime-printing' ),
			get_the_title( $specs['artwork_id'] ) . ' — ' . prime_file_download_url( $specs['artwork_id'] ),
			true
		);
		$item->update_meta_data( '_prime_addon_files', array( $specs['artwork_id'] ) );
	}

	$item->update_meta_data( '_prime_pp_sticker_specs_raw', $specs );
}
add_action( 'woocommerce_checkout_create_order_line_item', 'prime_persist_pp_sticker_to_order', 10, 3 );

/**
 * The calculator's own JS.
 */
function prime_enqueue_pp_sticker_assets() {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}

	global $post;

	if ( ! $post || ! prime_product_id_matches( $post->ID, PRIME_PP_STICKER_PRODUCT_ID ) ) {
		return;
	}

	wp_enqueue_script(
		'prime-pp-sticker-calculator',
		PRIME_URI . '/assets/js/pp-sticker-calculator.js',
		array(),
		prime_asset_version( '/assets/js/pp-sticker-calculator.js' ),
		true
	);
}
add_action( 'wp_enqueue_scripts', 'prime_enqueue_pp_sticker_assets' );
