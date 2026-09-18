<?php
/**
 * Product edit screen — the add-ons builder and the custom-pricing settings.
 *
 * A new tab on WooCommerce's existing product-data metabox, not a separate
 * screen. That keeps it inside the interface Reem already uses to edit a
 * product, and it means every field here saves through WooCommerce's own
 * "Update"/"Publish" button rather than a bespoke save handler.
 *
 * Two things live here:
 *   Add-ons          text/file fields, any product type (Phase 4a)
 *   Pricing model     Fixed / Variable / Custom formula (Phase 4c)
 *
 * "Variable" here only means "this product uses WooCommerce's native variation
 * system" — selecting it does not change the data model, it is a note for
 * whoever configures pricing later, since the real work for a variable product
 * is the Product data type dropdown at the top of the screen, unrelated to
 * this tab.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the tab.
 *
 * @param array $tabs Existing tabs.
 * @return array
 */
function prime_product_data_tab( $tabs ) {
	$tabs['prime_pricing'] = array(
		'label'    => __( 'Prime Printing', 'prime-printing' ),
		'target'   => 'prime_pricing_data',
		'class'    => array( 'show_if_simple', 'show_if_variable' ),
		'priority' => 21,
	);

	return $tabs;
}
add_filter( 'woocommerce_product_data_tabs', 'prime_product_data_tab' );

/**
 * The tab's panel.
 */
function prime_product_data_panel() {
	global $post;

	$model     = get_post_meta( $post->ID, '_prime_pricing_model', true );
	$model     = $model ? $model : 'fixed';
	$rate      = get_post_meta( $post->ID, '_prime_rate_per_cm2', true );
	$min_piece = get_post_meta( $post->ID, '_prime_min_price_piece', true );
	$min_order = get_post_meta( $post->ID, '_prime_min_order_total', true );
	$fields    = get_post_meta( $post->ID, '_prime_custom_fields', true );
	$fields    = is_array( $fields ) ? $fields : array( 'dimensions', 'upload' );
	$finishes  = get_post_meta( $post->ID, '_prime_finishes', true );
	$finishes  = is_array( $finishes ) ? $finishes : array();
	$addons    = prime_product_addons( $post->ID );
	?>
	<div id="prime_pricing_data" class="panel woocommerce_options_panel">

		<div class="options_group">
			<p class="form-field">
				<label for="prime_pricing_model"><?php esc_html_e( 'Pricing model', 'prime-printing' ); ?></label>
				<select id="prime_pricing_model" name="prime_pricing_model">
					<option value="fixed" <?php selected( $model, 'fixed' ); ?>><?php esc_html_e( 'Fixed — use the regular/sale price above', 'prime-printing' ); ?></option>
					<option value="variable" <?php selected( $model, 'variable' ); ?>><?php esc_html_e( 'Variable — priced by WooCommerce variations', 'prime-printing' ); ?></option>
					<option value="custom" <?php selected( $model, 'custom' ); ?>><?php esc_html_e( 'Custom formula — priced by size and quantity', 'prime-printing' ); ?></option>
				</select>
				<?php // phpcs:ignore Generic.Files.LineLength.TooLong ?>
				<span class="description"><?php esc_html_e( '"Variable" is a note for this product — set the actual product type in the Product data dropdown above.', 'prime-printing' ); ?></span>
			</p>
		</div>

		<div class="options_group prime-custom-formula" data-prime-show-when="custom">
			<p class="prime-admin-note">
				<?php esc_html_e( 'Placeholder formula — Phase 4c replaces this with the real figures once the calculator for this product is provided. Editing these numbers now is safe; nothing here is final.', 'prime-printing' ); ?>
			</p>

			<p class="form-field">
				<label for="prime_rate_per_cm2"><?php esc_html_e( 'Rate per cm² (KWD)', 'prime-printing' ); ?></label>
				<input type="number" step="0.001" min="0" id="prime_rate_per_cm2" name="prime_rate_per_cm2" value="<?php echo esc_attr( $rate ? $rate : '0.003' ); ?>">
			</p>

			<p class="form-field">
				<label for="prime_min_price_piece"><?php esc_html_e( 'Minimum price per piece (KWD)', 'prime-printing' ); ?></label>
				<input type="number" step="0.001" min="0" id="prime_min_price_piece" name="prime_min_price_piece" value="<?php echo esc_attr( $min_piece ? $min_piece : '0.100' ); ?>">
			</p>

			<p class="form-field">
				<label for="prime_min_order_total"><?php esc_html_e( 'Minimum order total (KWD)', 'prime-printing' ); ?></label>
				<input type="number" step="0.001" min="0" id="prime_min_order_total" name="prime_min_order_total" value="<?php echo esc_attr( $min_order ? $min_order : '2.250' ); ?>">
			</p>

			<p class="form-field">
				<label><?php esc_html_e( 'Fields shown to the customer', 'prime-printing' ); ?></label>
				<label class="prime-inline-check">
					<input type="checkbox" name="prime_custom_fields[]" value="dimensions" <?php checked( in_array( 'dimensions', $fields, true ) ); ?>>
					<?php esc_html_e( 'Width / height (cm)', 'prime-printing' ); ?>
				</label>
				<label class="prime-inline-check">
					<input type="checkbox" name="prime_custom_fields[]" value="upload" <?php checked( in_array( 'upload', $fields, true ) ); ?>>
					<?php esc_html_e( 'Artwork upload', 'prime-printing' ); ?>
				</label>
				<label class="prime-inline-check">
					<input type="checkbox" name="prime_custom_fields[]" value="finish" <?php checked( in_array( 'finish', $fields, true ) ); ?>>
					<?php esc_html_e( 'Finish options (below)', 'prime-printing' ); ?>
				</label>
			</p>

			<p class="form-field">
				<label><?php esc_html_e( 'Finish options', 'prime-printing' ); ?></label>
				<span class="description"><?php esc_html_e( 'One per line: Label | price delta in KWD. Example: Holographic | 0.050', 'prime-printing' ); ?></span>
				<textarea name="prime_finishes" rows="4" class="prime-finishes"><?php
					echo esc_textarea(
						implode(
							"\n",
							array_map(
								static function ( $finish ) {
									return $finish['label'] . ' | ' . $finish['delta'];
								},
								$finishes
							)
						)
					);
				?></textarea>
			</p>
		</div>

		<div class="options_group">
			<p class="prime-admin-note">
				<?php esc_html_e( 'Simple add-ons — a text field or a file upload shown on any product, independent of the pricing model above.', 'prime-printing' ); ?>
			</p>

			<div id="prime-addons-rows" data-prime-addons>
				<?php if ( $addons ) : ?>
					<?php foreach ( $addons as $i => $addon ) : ?>
						<?php prime_render_addon_admin_row( $i, $addon ); ?>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>

			<p>
				<button type="button" class="button" data-prime-add-addon><?php esc_html_e( '+ Add field', 'prime-printing' ); ?></button>
			</p>

			<template id="prime-addon-row-template">
				<?php prime_render_addon_admin_row( '__INDEX__', array( 'key' => '', 'type' => 'text', 'label' => '', 'required' => false ) ); ?>
			</template>
		</div>

		<?php wp_nonce_field( 'prime_product_admin', 'prime_product_admin_nonce' ); ?>
	</div>
	<?php
}
add_action( 'woocommerce_product_data_panels', 'prime_product_data_panel' );

/**
 * One row of the add-ons repeater, shared by the rendered rows and the
 * `<template>` the JS clones for "+ Add field".
 *
 * @param int|string $index Row index, or '__INDEX__' inside the template.
 * @param array      $addon Addon data.
 */
function prime_render_addon_admin_row( $index, $addon ) {
	?>
	<div class="prime-addon-row">
		<input
			type="text"
			name="prime_addons[<?php echo esc_attr( $index ); ?>][label]"
			placeholder="<?php esc_attr_e( 'Field label, e.g. "Gift note"', 'prime-printing' ); ?>"
			value="<?php echo esc_attr( $addon['label'] ); ?>"
		>
		<select name="prime_addons[<?php echo esc_attr( $index ); ?>][type]">
			<option value="text" <?php selected( $addon['type'], 'text' ); ?>><?php esc_html_e( 'Text', 'prime-printing' ); ?></option>
			<option value="file" <?php selected( $addon['type'], 'file' ); ?>><?php esc_html_e( 'File upload', 'prime-printing' ); ?></option>
		</select>
		<label class="prime-inline-check">
			<input type="checkbox" name="prime_addons[<?php echo esc_attr( $index ); ?>][required]" <?php checked( ! empty( $addon['required'] ) ); ?>>
			<?php esc_html_e( 'Required', 'prime-printing' ); ?>
		</label>
		<button type="button" class="button-link-delete" data-prime-remove-addon><?php esc_html_e( 'Remove', 'prime-printing' ); ?></button>
	</div>
	<?php
}

/**
 * Save every field on the tab.
 *
 * @param int $post_id Product ID.
 */
function prime_save_product_data( $post_id ) {
	if ( ! isset( $_POST['prime_product_admin_nonce'] ) ||
		! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['prime_product_admin_nonce'] ) ), 'prime_product_admin' )
	) {
		return;
	}

	// ---- Pricing model -----------------------------------------------------
	$model = isset( $_POST['prime_pricing_model'] ) ? sanitize_key( wp_unslash( $_POST['prime_pricing_model'] ) ) : 'fixed';
	$model = in_array( $model, array( 'fixed', 'variable', 'custom' ), true ) ? $model : 'fixed';
	update_post_meta( $post_id, '_prime_pricing_model', $model );

	update_post_meta( $post_id, '_prime_rate_per_cm2', isset( $_POST['prime_rate_per_cm2'] ) ? wc_format_decimal( wp_unslash( $_POST['prime_rate_per_cm2'] ) ) : '' );
	update_post_meta( $post_id, '_prime_min_price_piece', isset( $_POST['prime_min_price_piece'] ) ? wc_format_decimal( wp_unslash( $_POST['prime_min_price_piece'] ) ) : '' );
	update_post_meta( $post_id, '_prime_min_order_total', isset( $_POST['prime_min_order_total'] ) ? wc_format_decimal( wp_unslash( $_POST['prime_min_order_total'] ) ) : '' );

	$allowed_fields = array( 'dimensions', 'upload', 'finish' );
	$fields         = isset( $_POST['prime_custom_fields'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['prime_custom_fields'] ) ) : array();
	update_post_meta( $post_id, '_prime_custom_fields', array_values( array_intersect( $fields, $allowed_fields ) ) );

	// ---- Finish options: "Label | delta" per line --------------------------
	$finishes = array();

	if ( ! empty( $_POST['prime_finishes'] ) ) {
		$lines = explode( "\n", wp_unslash( $_POST['prime_finishes'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash

		foreach ( $lines as $line ) {
			$parts = array_map( 'trim', explode( '|', $line, 2 ) );

			if ( empty( $parts[0] ) ) {
				continue;
			}

			$finishes[] = array(
				'label' => sanitize_text_field( $parts[0] ),
				'delta' => isset( $parts[1] ) ? wc_format_decimal( $parts[1] ) : '0',
			);
		}
	}

	update_post_meta( $post_id, '_prime_finishes', $finishes );

	// ---- Add-ons -------------------------------------------------------------
	$addons = array();

	if ( ! empty( $_POST['prime_addons'] ) && is_array( $_POST['prime_addons'] ) ) {
		foreach ( wp_unslash( $_POST['prime_addons'] ) as $row ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			if ( empty( $row['label'] ) ) {
				continue;
			}

			$label = sanitize_text_field( $row['label'] );

			$addons[] = array(
				'key'      => sanitize_key( $label ),
				'label'    => $label,
				'type'     => ( isset( $row['type'] ) && 'file' === $row['type'] ) ? 'file' : 'text',
				'required' => ! empty( $row['required'] ),
			);
		}
	}

	update_post_meta( $post_id, '_prime_addons', $addons );
}
add_action( 'woocommerce_process_product_meta', 'prime_save_product_data' );

/**
 * Admin assets for the repeater's add/remove rows.
 *
 * @param string $hook Current admin page.
 */
function prime_product_admin_assets( $hook ) {
	if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
		return;
	}

	global $post_type;

	if ( 'product' !== $post_type ) {
		return;
	}

	wp_enqueue_style( 'prime-product-admin', PRIME_URI . '/assets/css/product-admin.css', array(), prime_asset_version( '/assets/css/product-admin.css' ) );
	wp_enqueue_script( 'prime-product-admin', PRIME_URI . '/assets/js/product-admin.js', array( 'jquery' ), prime_asset_version( '/assets/js/product-admin.js' ), true );
}
add_action( 'admin_enqueue_scripts', 'prime_product_admin_assets' );
