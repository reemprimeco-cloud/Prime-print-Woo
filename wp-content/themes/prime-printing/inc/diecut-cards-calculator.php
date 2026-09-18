<?php
/**
 * Diecut Cards (product id 2041) — real price calculator.
 *
 * Fourth of the same treatment (see inc/uv-dtf-calculator.php,
 * inc/paper-sticker-calculator.php, inc/pp-sticker-calculator.php). The
 * formula — 33×48cm sheet with a 1cm margin, 0.1cm between cards, the
 * per-sheet material/print/diecut/lamination costs and the quantity-banded
 * markup — is ported line-for-line from Reem's own reference
 * (diecut_public_calculator.html) and is not touched here.
 *
 * As with the others, the browser only ever shows a preview:
 * prime_diecut_price() below runs again in PHP on every cart calculation and
 * is the only number ever charged.
 *
 * NOT YET SWITCHED ON for the product: 2041 is still a variable product with
 * fixed Size × Quantity variations priced 5–60 KD, and this formula prices
 * the same jobs at roughly a tenth of that (a 6cm card ×1000 comes to 9.503
 * here against 60 live). The reference has a minimum *quantity* of 50 pieces
 * but no minimum *price*, where the UV DTF calculator floors at 5 KD and
 * every current 50-piece variation is priced at exactly 5 KD. Flagged to Reem
 * 2026-09-05; until she confirms, this file renders nothing, because
 * prime_is_diecut_product() only matches a *simple* product. Converting 2041
 * to simple is the switch — see the note on that function.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

/**
 * The one product this applies to.
 */
const PRIME_DIECUT_PRODUCT_ID = 2041;

/**
 * Reem's real figures, ported verbatim from the reference calculator.
 * Costs are per printed sheet.
 *
 * @return array
 */
function prime_diecut_constants() {
	return array(
		// Printable area of the 33×48cm sheet, less a 1cm margin each side.
		'sheet_w_cm'   => 31.0,
		'sheet_h_cm'   => 46.0,
		'full_w_cm'    => 33,
		'full_h_cm'    => 48,
		'gap_cm'       => 0.1,
		/*
		 * 500 fils a printed sheet, covering print and the diecut (Reem,
		 * 2026-09-05). Replaces the reference calculator's itemised costs —
		 * material 0.036 + print 0.080 + overhead 0.010 + diecut 0.100 — which
		 * came to 0.226 a sheet and priced 1,000 6cm cards at 9.503 KD.
		 *
		 * Neither lamination nor second-side printing is in this figure: both
		 * are optional extras the customer picks, charged separately below.
		 * The sheet cost is a single side printed, plus the diecut.
		 */
		'sheet_cost'       => 0.500,
		// Added per sheet when a lamination finish is chosen. Matt and gloss
		// cost the same; the choice is a finish, not a price tier.
		'lamination_cost'  => 0.050,
		/*
		 * Printing the second side, per sheet. Deliberately NOT shown to the
		 * customer as a price (Reem, 2026-09-05: "hiden price for the
		 * customer") — they choose one side or both, and the total moves; the
		 * per-sheet figure behind it stays internal, the same way the markup
		 * bands and the sheet cost do.
		 */
		'two_side_cost'    => 0.050,
		'min_quantity'     => 50,
		// Minimum job charge (Reem, 2026-09-05). The reference formula has a
		// minimum *quantity* but no minimum *price*, which priced a 50-piece run
		// at 0.362 KD — a fraction of the 5 KD every 50-piece variation was sold
		// at before this calculator. This floor restores that: 50 cards still
		// come to 5 KD, and the formula only takes over once it exceeds the floor.
		'min_price'        => 5.0,
	);
}

/**
 * The markup applied on top of cost, by order size. Hidden from the customer.
 *
 * @param int $quantity Pieces ordered.
 * @return float
 */
function prime_diecut_markup( $quantity ) {
	if ( $quantity <= 500 ) {
		return 0.60;
	}

	if ( $quantity <= 1000 ) {
		return 0.45;
	}

	return 0.35;
}

/**
 * How many sides are printed. Single-sided is the default; the second side is
 * an optional extra whose price the customer never sees.
 *
 * @return array<string, string>
 */
function prime_diecut_sides() {
	return array(
		'single' => __( 'One side', 'prime-printing' ),
		'double' => __( 'Both sides', 'prime-printing' ),
	);
}

/**
 * The lamination finishes offered. Both cost the same per sheet — the choice
 * is a finish, not a price tier — and "none" is the default.
 *
 * @return array<string, string>
 */
function prime_diecut_laminations() {
	return array(
		''      => __( 'No lamination', 'prime-printing' ),
		'matt'  => __( 'Matt', 'prime-printing' ),
		'gloss' => __( 'Gloss', 'prime-printing' ),
	);
}

/**
 * The card shapes offered. Display only — shape does not change the price,
 * but it is recorded on the order because it decides the diecut die.
 *
 * @return array<string, string>
 */
function prime_diecut_shapes() {
	return array(
		'circle'    => __( 'Circle', 'prime-printing' ),
		'star'      => __( 'Star', 'prime-printing' ),
		'heart'     => __( 'Heart', 'prime-printing' ),
		'square'    => __( 'Square', 'prime-printing' ),
		'rectangle' => __( 'Rectangle', 'prime-printing' ),
		'hexagon'   => __( 'Hexagon', 'prime-printing' ),
		'triangle'  => __( 'Triangle', 'prime-printing' ),
		'custom'    => __( 'Custom shape', 'prime-printing' ),
	);
}

/**
 * How many cards fit a sheet, trying both orientations.
 *
 * Ported from the reference's nest(): a tie goes to the unrotated layout,
 * because it only replaces the best when the rotated yield is strictly
 * greater.
 *
 * @param float $width_cm  Card width.
 * @param float $height_cm Card height.
 * @return array{cols: int, rows: int, yield: int, rotated: bool}|null Null when it does not fit at all.
 */
function prime_diecut_nest( $width_cm, $height_cm ) {
	$c = prime_diecut_constants();

	$try = static function ( $w, $h ) use ( $c ) {
		$cols = (int) floor( $c['sheet_w_cm'] / ( $w + $c['gap_cm'] ) );
		$rows = (int) floor( $c['sheet_h_cm'] / ( $h + $c['gap_cm'] ) );

		if ( $cols < 1 || $rows < 1 ) {
			return null;
		}

		return array(
			'cols'  => $cols,
			'rows'  => $rows,
			'yield' => $cols * $rows,
		);
	};

	$normal  = $try( $width_cm, $height_cm );
	$rotated = $try( $height_cm, $width_cm );

	$best = null;

	if ( $normal ) {
		$best            = $normal;
		$best['rotated'] = false;
	}

	if ( $rotated && ( null === $best || $rotated['yield'] > $best['yield'] ) ) {
		$best            = $rotated;
		$best['rotated'] = true;
	}

	return $best;
}

/**
 * What one run of diecut cards costs.
 *
 * @param float $width_cm   Card width.
 * @param float $height_cm  Card height.
 * @param int   $quantity   Pieces wanted.
 * @param string $lamination Lamination finish key ('', 'matt' or 'gloss'); any non-empty value adds the same per-sheet charge.
 * @param string $sides      'single' or 'double'; 'double' adds the second-side charge.
 * @return array{yield: int, cols: int, rows: int, rotated: bool, sheets: int, total: float, unit: float}
 */
function prime_diecut_price( $width_cm, $height_cm, $quantity, $lamination, $sides = 'single' ) {
	$c = prime_diecut_constants();

	$width_cm  = max( 0.1, (float) $width_cm );
	$height_cm = max( 0.1, (float) $height_cm );
	$quantity  = max( 1, (int) $quantity );

	$nest = prime_diecut_nest( $width_cm, $height_cm );

	if ( ! $nest ) {
		return array(
			'yield'   => 0,
			'cols'    => 0,
			'rows'    => 0,
			'rotated' => false,
			'sheets'  => 0,
			'total'   => 0.0,
			'unit'    => 0.0,
		);
	}

	$sheets    = (int) ceil( $quantity / $nest['yield'] );
	$per_sheet = $c['sheet_cost']
		+ ( $lamination ? $c['lamination_cost'] : 0 )
		+ ( 'double' === $sides ? $c['two_side_cost'] : 0 );
	$total     = $sheets * $per_sheet * ( 1 + prime_diecut_markup( $quantity ) );
	$total     = max( $total, $c['min_price'] );
	$total     = round( $total, 3 );

	return array(
		'yield'   => $nest['yield'],
		'cols'    => $nest['cols'],
		'rows'    => $nest['rows'],
		'rotated' => $nest['rotated'],
		'sheets'  => $sheets,
		'total'   => $total,
		'unit'    => $total / $quantity,
	);
}

/**
 * Is the product on this page the diecut card, and set up for this calculator?
 *
 * The `is_type( 'simple' )` check is the on/off switch described in the file
 * docblock. While 2041 stays a variable product with its Size × Quantity
 * variations, this returns false and nothing here renders or prices — the
 * product keeps behaving exactly as it does today. Converting it to a simple
 * product is what hands pricing to this calculator, and that should only
 * happen once the pricing question is settled.
 *
 * @return bool
 */
function prime_is_diecut_product() {
	global $product;

	return $product instanceof WC_Product
		&& prime_product_id_matches( $product->get_id(), PRIME_DIECUT_PRODUCT_ID )
		&& $product->is_type( 'simple' );
}

/**
 * Render the calculator on the product page.
 */
function prime_render_diecut_calculator() {
	if ( ! prime_is_diecut_product() ) {
		return;
	}

	$c = prime_diecut_constants();
	?>
	<div
		class="prime-configurator-fields"
		data-diecut-calculator
		data-sheet-w="<?php echo esc_attr( $c['sheet_w_cm'] ); ?>"
		data-sheet-h="<?php echo esc_attr( $c['sheet_h_cm'] ); ?>"
		data-gap="<?php echo esc_attr( $c['gap_cm'] ); ?>"
		data-sheet-cost="<?php echo esc_attr( $c['sheet_cost'] ); ?>"
		data-lamination-cost="<?php echo esc_attr( $c['lamination_cost'] ); ?>"
		data-two-side-cost="<?php echo esc_attr( $c['two_side_cost'] ); ?>"
		data-min-qty="<?php echo esc_attr( $c['min_quantity'] ); ?>"
		data-min-price="<?php echo esc_attr( $c['min_price'] ); ?>"
		data-currency="<?php echo esc_attr( get_woocommerce_currency_symbol() ); ?>"
	>
		<div class="prime-field">
			<label for="diecut-shape"><?php esc_html_e( 'Shape', 'prime-printing' ); ?></label>
			<select id="diecut-shape" name="diecut_shape" data-diecut-input>
				<?php foreach ( prime_diecut_shapes() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>

		<div class="prime-field prime-field--duo">
			<div class="prime-field">
				<label for="diecut-w"><?php esc_html_e( 'Width (cm)', 'prime-printing' ); ?></label>
				<input type="number" id="diecut-w" name="diecut_width" min="0.1" step="0.1" value="5" data-diecut-input>
			</div>
			<div class="prime-field">
				<label for="diecut-h"><?php esc_html_e( 'Height (cm)', 'prime-printing' ); ?></label>
				<input type="number" id="diecut-h" name="diecut_height" min="0.1" step="0.1" value="5" data-diecut-input>
			</div>
		</div>

		<div class="prime-field">
			<label for="diecut-q">
				<?php
				printf(
					/* translators: %s: minimum quantity. */
					esc_html__( 'Quantity (minimum %s pieces)', 'prime-printing' ),
					esc_html( $c['min_quantity'] )
				);
				?>
			</label>
			<input type="number" id="diecut-q" name="diecut_quantity" min="<?php echo esc_attr( $c['min_quantity'] ); ?>" step="1" value="<?php echo esc_attr( $c['min_quantity'] ); ?>" data-diecut-input>
			<span class="prime-field__error" data-prime-error="q">
				<?php
				printf(
					/* translators: %s: minimum quantity. */
					esc_html__( 'Minimum order is %s pieces', 'prime-printing' ),
					esc_html( $c['min_quantity'] )
				);
				?>
			</span>
		</div>

		<div class="prime-field">
			<label for="diecut-sides"><?php esc_html_e( 'Printing', 'prime-printing' ); ?></label>
			<select id="diecut-sides" name="diecut_sides" data-diecut-input>
				<?php foreach ( prime_diecut_sides() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php
			/*
			 * No price hint here on purpose — the second side's per-sheet cost
			 * is internal (see prime_diecut_constants()). The customer sees the
			 * total change when they pick it, not the rate behind it.
			 */
			?>
		</div>

		<div class="prime-field">
			<label for="diecut-lam"><?php esc_html_e( 'Lamination (optional)', 'prime-printing' ); ?></label>
			<select id="diecut-lam" name="diecut_lamination" data-diecut-input>
				<?php foreach ( prime_diecut_laminations() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<span class="prime-field__hint">
				<?php
				printf(
					/* translators: %s: lamination cost per sheet. */
					esc_html__( 'Protective coating, +%s per sheet.', 'prime-printing' ),
					esc_html( number_format( $c['lamination_cost'], 3 ) . ' ' . get_woocommerce_currency_symbol() )
				);
				?>
			</span>
		</div>

		<div class="prime-field">
			<label for="diecut-file"><?php esc_html_e( 'Artwork file (optional)', 'prime-printing' ); ?></label>
			<input type="file" id="diecut-file" name="diecut_artwork" accept=".pdf,.ai,.eps,.svg,.jpg,.jpeg,.png">
			<span class="prime-field__hint"><?php esc_html_e( 'You can also send it later on WhatsApp — the order still goes through without it.', 'prime-printing' ); ?></span>
		</div>

		<div class="prime-calcrow">
			<div class="prime-calcbox">
				<div class="prime-calcbox__k"><?php esc_html_e( 'Cards / sheet', 'prime-printing' ); ?></div>
				<div class="prime-calcbox__v" data-diecut-yield>—</div>
			</div>
			<div class="prime-calcbox">
				<div class="prime-calcbox__k"><?php esc_html_e( 'Sheets needed', 'prime-printing' ); ?></div>
				<div class="prime-calcbox__v" data-diecut-sheets>—</div>
			</div>
			<div class="prime-calcbox">
				<div class="prime-calcbox__k"><?php esc_html_e( 'Price / card', 'prime-printing' ); ?></div>
				<div class="prime-calcbox__v" data-diecut-unit>—</div>
			</div>
		</div>

		<div class="prime-calcrow">
			<div class="prime-calcbox prime-calcbox--hi">
				<div class="prime-calcbox__k"><?php esc_html_e( 'Total price', 'prime-printing' ); ?></div>
				<div class="prime-calcbox__v" data-diecut-total>—</div>
			</div>
		</div>

		<p class="prime-calc-note" data-diecut-toobig hidden>
			<?php
			printf(
				/* translators: 1: sheet width, 2: sheet height. */
				esc_html__( 'That size is bigger than our %1$s × %2$s cm sheet — please contact us for a custom quote.', 'prime-printing' ),
				esc_html( $c['full_w_cm'] ),
				esc_html( $c['full_h_cm'] )
			);
			?>
		</p>

		<p class="prime-calc-note">
			<?php
			printf(
				/* translators: 1: sheet width, 2: sheet height, 3: minimum order price. */
				esc_html__( 'Matt 300 gsm, printed both sides, diecut included. Laid out on a %1$s × %2$s cm sheet. Minimum order %3$s. Final price is recalculated on our server when added to cart.', 'prime-printing' ),
				esc_html( $c['full_w_cm'] ),
				esc_html( $c['full_h_cm'] ),
				esc_html( number_format( $c['min_price'], 3 ) . ' ' . get_woocommerce_currency_symbol() )
			);
			?>
		</p>

		<?php wp_nonce_field( 'prime_diecut', 'prime_diecut_nonce' ); ?>
	</div>
	<?php
}
add_action( 'woocommerce_before_add_to_cart_button', 'prime_render_diecut_calculator', 5 );

/**
 * One run is one cart line — the piece count is a spec, not a WooCommerce
 * quantity multiplier.
 *
 * @param bool       $sold_individually Current value.
 * @param WC_Product $product           Product being checked.
 * @return bool
 */
function prime_diecut_lock_quantity( $sold_individually, $product ) {
	if ( $product instanceof WC_Product
		&& prime_product_id_matches( $product->get_id(), PRIME_DIECUT_PRODUCT_ID )
		&& $product->is_type( 'simple' )
	) {
		return true;
	}

	return $sold_individually;
}
add_filter( 'woocommerce_is_sold_individually', 'prime_diecut_lock_quantity', 10, 2 );

/**
 * Block add-to-cart when the inputs don't make sense.
 *
 * @param bool $passed     Validation state so far.
 * @param int  $product_id Product being added.
 * @return bool
 */
function prime_validate_diecut( $passed, $product_id ) {
	if ( ! prime_product_id_matches( $product_id, PRIME_DIECUT_PRODUCT_ID ) ) {
		return $passed;
	}

	$product = wc_get_product( $product_id );

	if ( ! $product instanceof WC_Product || ! $product->is_type( 'simple' ) ) {
		return $passed;
	}

	$c = prime_diecut_constants();

	$width  = isset( $_POST['diecut_width'] ) ? (float) wp_unslash( $_POST['diecut_width'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only range check; authoritative validation happens again in prime_capture_diecut() after the nonce check.
	$height = isset( $_POST['diecut_height'] ) ? (float) wp_unslash( $_POST['diecut_height'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$qty    = isset( $_POST['diecut_quantity'] ) ? (int) wp_unslash( $_POST['diecut_quantity'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

	if ( $width < 0.1 || $height < 0.1 ) {
		wc_add_notice( __( 'Enter a width and height for your card.', 'prime-printing' ), 'error' );
		$passed = false;
	}

	if ( $qty < $c['min_quantity'] ) {
		wc_add_notice(
			sprintf(
				/* translators: %s: minimum quantity. */
				__( 'Minimum order is %s pieces.', 'prime-printing' ),
				$c['min_quantity']
			),
			'error'
		);
		$passed = false;
	}

	if ( $width >= 0.1 && $height >= 0.1 && ! prime_diecut_nest( $width, $height ) ) {
		wc_add_notice(
			sprintf(
				/* translators: 1: sheet width, 2: sheet height. */
				__( 'That size is bigger than our %1$s × %2$s cm sheet — please contact us for a custom quote.', 'prime-printing' ),
				$c['full_w_cm'],
				$c['full_h_cm']
			),
			'error'
		);
		$passed = false;
	}

	return $passed;
}
add_filter( 'woocommerce_add_to_cart_validation', 'prime_validate_diecut', 10, 2 );

/**
 * Capture the submitted specs onto the cart item. No price is read from the
 * request at any point.
 *
 * @param array $cart_item_data Existing cart item data.
 * @param int   $product_id     Product being added.
 * @return array
 */
function prime_capture_diecut( $cart_item_data, $product_id ) {
	if ( ! prime_product_id_matches( $product_id, PRIME_DIECUT_PRODUCT_ID ) ) {
		return $cart_item_data;
	}

	if ( ! isset( $_POST['prime_diecut_nonce'] ) ||
		! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['prime_diecut_nonce'] ) ), 'prime_diecut' )
	) {
		return $cart_item_data;
	}

	$shape  = isset( $_POST['diecut_shape'] ) ? sanitize_key( wp_unslash( $_POST['diecut_shape'] ) ) : 'circle';
	$shapes = prime_diecut_shapes();

	// Only a finish this product actually offers is stored — a request could
	// otherwise invent one, and it decides a real per-sheet charge.
	$lamination  = isset( $_POST['diecut_lamination'] ) ? sanitize_key( wp_unslash( $_POST['diecut_lamination'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$laminations = prime_diecut_laminations();
	$lamination  = ( '' !== $lamination && isset( $laminations[ $lamination ] ) ) ? $lamination : '';

	$sides     = isset( $_POST['diecut_sides'] ) ? sanitize_key( wp_unslash( $_POST['diecut_sides'] ) ) : 'single'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$all_sides = prime_diecut_sides();
	$sides     = isset( $all_sides[ $sides ] ) ? $sides : 'single';

	$specs = array(
		'shape'      => isset( $shapes[ $shape ] ) ? $shape : 'circle',
		'width'      => isset( $_POST['diecut_width'] ) ? (float) wp_unslash( $_POST['diecut_width'] ) : 0, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		'height'     => isset( $_POST['diecut_height'] ) ? (float) wp_unslash( $_POST['diecut_height'] ) : 0, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		'quantity'   => isset( $_POST['diecut_quantity'] ) ? max( 1, (int) wp_unslash( $_POST['diecut_quantity'] ) ) : 1, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		'lamination' => $lamination,
		'sides'      => $sides,
	);

	if ( ! empty( $_FILES['diecut_artwork']['name'] ) ) {
		$attachment_id = prime_handle_addon_upload( 'diecut_artwork' );

		if ( $attachment_id ) {
			$specs['artwork_id'] = $attachment_id;
		}
	}

	$cart_item_data['prime_diecut_specs'] = $specs;
	$cart_item_data['unique_key']         = md5( wp_json_encode( $specs ) . microtime() );

	return $cart_item_data;
}
add_filter( 'woocommerce_add_cart_item_data', 'prime_capture_diecut', 10, 2 );

/**
 * Recompute the price server-side on every cart calculation.
 *
 * @param WC_Cart $cart Current cart.
 */
function prime_apply_diecut_price( $cart ) {
	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
		return;
	}

	foreach ( $cart->get_cart() as $cart_item ) {
		if ( empty( $cart_item['prime_diecut_specs'] ) ) {
			continue;
		}

		$product = $cart_item['data'];

		if ( ! $product instanceof WC_Product ) {
			continue;
		}

		$specs  = $cart_item['prime_diecut_specs'];
		$result = prime_diecut_price( $specs['width'], $specs['height'], $specs['quantity'], $specs['lamination'] ?? '', $specs['sides'] ?? 'single' );

		$product->set_price( $result['total'] );
	}
}
add_action( 'woocommerce_before_calculate_totals', 'prime_apply_diecut_price', 20 );

/**
 * Show the specs in the cart, checkout review, and order emails.
 *
 * @param array $item_data Existing display rows.
 * @param array $cart_item The cart item.
 * @return array
 */
function prime_display_diecut_specs( $item_data, $cart_item ) {
	if ( empty( $cart_item['prime_diecut_specs'] ) ) {
		return $item_data;
	}

	$specs  = $cart_item['prime_diecut_specs'];
	$shapes = prime_diecut_shapes();
	$result = prime_diecut_price( $specs['width'], $specs['height'], $specs['quantity'], $specs['lamination'] ?? '', $specs['sides'] ?? 'single' );

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
		/* translators: 1: sheet count, 2: cards per sheet. */
		'value' => sprintf( __( '%1$s (%2$s per sheet)', 'prime-printing' ), $result['sheets'], $result['yield'] ),
	);
	$laminations = prime_diecut_laminations();
	$lamination  = $specs['lamination'] ?? '';

	$all_sides = prime_diecut_sides();
	$sides     = $specs['sides'] ?? 'single';

	$item_data[] = array(
		'name'  => __( 'Printing', 'prime-printing' ),
		'value' => esc_html( $all_sides[ $sides ] ?? $all_sides['single'] ),
	);
	$item_data[] = array(
		'name'  => __( 'Lamination', 'prime-printing' ),
		'value' => esc_html( $laminations[ $lamination ] ?? $laminations[''] ),
	);

	if ( ! empty( $specs['artwork_id'] ) ) {
		$item_data[] = array(
			'name'  => __( 'Artwork', 'prime-printing' ),
			'value' => esc_html( get_the_title( $specs['artwork_id'] ) ),
		);
	}

	return $item_data;
}
add_filter( 'woocommerce_get_item_data', 'prime_display_diecut_specs', 10, 2 );

/**
 * Persist specs onto the order line item.
 *
 * @param WC_Order_Item_Product $item          Order line item.
 * @param string                $cart_item_key Cart item key.
 * @param array                 $values        Cart item values.
 */
function prime_persist_diecut_to_order( $item, $cart_item_key, $values ) {
	if ( empty( $values['prime_diecut_specs'] ) ) {
		return;
	}

	$specs  = $values['prime_diecut_specs'];
	$shapes = prime_diecut_shapes();
	$result = prime_diecut_price( $specs['width'], $specs['height'], $specs['quantity'], $specs['lamination'] ?? '', $specs['sides'] ?? 'single' );

	$item->add_meta_data( __( 'Shape', 'prime-printing' ), $shapes[ $specs['shape'] ] ?? $specs['shape'], true );
	$item->add_meta_data( __( 'Size', 'prime-printing' ), sprintf( '%s × %s cm', $specs['width'], $specs['height'] ), true );
	$item->add_meta_data( __( 'Pieces', 'prime-printing' ), $specs['quantity'], true );
	$item->add_meta_data(
		__( 'Sheets', 'prime-printing' ),
		/* translators: 1: sheet count, 2: cards per sheet. */
		sprintf( __( '%1$s (%2$s per sheet)', 'prime-printing' ), $result['sheets'], $result['yield'] ),
		true
	);
	$laminations = prime_diecut_laminations();
	$lamination  = $specs['lamination'] ?? '';
	$all_sides = prime_diecut_sides();
	$sides     = $specs['sides'] ?? 'single';
	$item->add_meta_data( __( 'Printing', 'prime-printing' ), $all_sides[ $sides ] ?? $all_sides['single'], true );
	$item->add_meta_data( __( 'Lamination', 'prime-printing' ), $laminations[ $lamination ] ?? $laminations[''], true );

	if ( ! empty( $specs['artwork_id'] ) ) {
		$item->add_meta_data(
			__( 'Artwork', 'prime-printing' ),
			get_the_title( $specs['artwork_id'] ) . ' — ' . prime_file_download_url( $specs['artwork_id'] ),
			true
		);
		$item->update_meta_data( '_prime_addon_files', array( $specs['artwork_id'] ) );
	}

	$item->update_meta_data( '_prime_diecut_specs_raw', $specs );
}
add_action( 'woocommerce_checkout_create_order_line_item', 'prime_persist_diecut_to_order', 10, 3 );

/**
 * The calculator's own JS.
 */
function prime_enqueue_diecut_assets() {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}

	global $post;

	if ( ! $post || ! prime_product_id_matches( $post->ID, PRIME_DIECUT_PRODUCT_ID ) ) {
		return;
	}

	wp_enqueue_script(
		'prime-diecut-calculator',
		PRIME_URI . '/assets/js/diecut-cards-calculator.js',
		array(),
		prime_asset_version( '/assets/js/diecut-cards-calculator.js' ),
		true
	);
}
add_action( 'wp_enqueue_scripts', 'prime_enqueue_diecut_assets' );
