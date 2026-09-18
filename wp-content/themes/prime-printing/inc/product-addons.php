<?php
/**
 * Phase 4a — simple add-ons for standard products (text field, file upload).
 *
 * This is the exact area the current site has a live bug in: add-on data is
 * captured at add-to-cart but never survives onto the order line item, so
 * fulfilment never sees what the customer typed or uploaded. The fix is
 * structural, not cosmetic — every hook below exists because skipping it is
 * how that bug happens:
 *
 *   woocommerce_add_cart_item_data       capture submitted value -> cart item
 *   woocommerce_get_item_data            show it back in cart/checkout review
 *   woocommerce_checkout_create_order_line_item   persist it onto the ORDER
 *   woocommerce_add_to_cart_validation   text add-ons required, must not be empty
 *
 * Uploaded files are moved to a private, non-guessable location — never left in
 * the public uploads directory, which would let anyone with the URL download a
 * customer's artwork.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

/**
 * A product's configured add-ons.
 *
 * @param int $product_id Product ID.
 * @return array[] Each: array{ key, type: 'text'|'file', label, required }.
 */
function prime_product_addons( $product_id ) {
	$addons = get_post_meta( $product_id, '_prime_addons', true );

	return is_array( $addons ) ? $addons : array();
}

/**
 * Render the add-on fields on the product page, above the add-to-cart button.
 */
function prime_render_addon_fields() {
	global $product;

	if ( ! $product instanceof WC_Product ) {
		return;
	}

	$addons = prime_product_addons( $product->get_id() );

	if ( ! $addons ) {
		return;
	}

	echo '<div class="prime-addons">';

	foreach ( $addons as $addon ) {
		$name = 'prime_addon_' . sanitize_key( $addon['key'] );
		?>
		<div class="prime-field">
			<label for="<?php echo esc_attr( $name ); ?>">
				<?php echo esc_html( $addon['label'] ); ?>
				<?php if ( ! empty( $addon['required'] ) ) : ?>
					<span aria-hidden="true">*</span>
				<?php endif; ?>
			</label>

			<?php if ( 'file' === $addon['type'] ) : ?>
				<input
					type="file"
					id="<?php echo esc_attr( $name ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					<?php echo ! empty( $addon['required'] ) ? 'required' : ''; ?>
				>
			<?php else : ?>
				<input
					type="text"
					id="<?php echo esc_attr( $name ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					<?php echo ! empty( $addon['required'] ) ? 'required' : ''; ?>
				>
			<?php endif; ?>
		</div>
		<?php
	}

	wp_nonce_field( 'prime_addons', 'prime_addons_nonce' );

	echo '</div>';
}
add_action( 'woocommerce_before_add_to_cart_button', 'prime_render_addon_fields' );

/**
 * Reject add-to-cart when a required add-on is missing.
 *
 * Runs before WooCommerce adds anything to the cart, so an invalid submission
 * never reaches the order at all — the alternative, validating only at
 * checkout, is how orders end up placed without artwork.
 *
 * @param bool $passed     Whether validation has passed so far.
 * @param int  $product_id Product being added.
 * @return bool
 */
function prime_validate_addons( $passed, $product_id ) {
	foreach ( prime_product_addons( $product_id ) as $addon ) {
		if ( empty( $addon['required'] ) ) {
			continue;
		}

		$field = 'prime_addon_' . sanitize_key( $addon['key'] );

		if ( 'file' === $addon['type'] ) {
			if ( empty( $_FILES[ $field ]['name'] ) ) {
				wc_add_notice(
					sprintf(
						/* translators: %s: add-on field label. */
						__( '%s is required.', 'prime-printing' ),
						$addon['label']
					),
					'error'
				);
				$passed = false;
			}
			continue;
		}

		if ( empty( $_POST[ $field ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only presence check; the value itself is sanitized and nonce-checked in prime_capture_addons().
			wc_add_notice(
				sprintf(
					/* translators: %s: add-on field label. */
					__( '%s is required.', 'prime-printing' ),
					$addon['label']
				),
				'error'
			);
			$passed = false;
		}
	}

	return $passed;
}
add_filter( 'woocommerce_add_to_cart_validation', 'prime_validate_addons', 10, 2 );

/**
 * Capture submitted add-on values onto the cart item.
 *
 * @param array $cart_item_data Existing cart item data.
 * @param int   $product_id     Product being added.
 * @return array
 */
function prime_capture_addons( $cart_item_data, $product_id ) {
	$addons = prime_product_addons( $product_id );

	if ( ! $addons ) {
		return $cart_item_data;
	}

	if ( ! isset( $_POST['prime_addons_nonce'] ) ||
		! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['prime_addons_nonce'] ) ), 'prime_addons' )
	) {
		return $cart_item_data;
	}

	$captured = array();

	foreach ( $addons as $addon ) {
		$field = 'prime_addon_' . sanitize_key( $addon['key'] );

		if ( 'file' === $addon['type'] ) {
			if ( empty( $_FILES[ $field ]['name'] ) ) {
				continue;
			}

			$attachment_id = prime_handle_addon_upload( $field );

			if ( $attachment_id ) {
				$captured[ $addon['key'] ] = array(
					'label'         => $addon['label'],
					'type'          => 'file',
					'attachment_id' => $attachment_id,
				);
			}
			continue;
		}

		if ( empty( $_POST[ $field ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above, before this loop.
			continue;
		}

		$captured[ $addon['key'] ] = array(
			'label' => $addon['label'],
			'type'  => 'text',
			'value' => sanitize_text_field( wp_unslash( $_POST[ $field ] ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		);
	}

	if ( $captured ) {
		$cart_item_data['prime_addons'] = $captured;
		// Ensures two otherwise-identical products with different add-ons stay
		// as separate cart line items rather than merging into one with a
		// summed quantity.
		$cart_item_data['unique_key'] = md5( wp_json_encode( $captured ) . microtime() );
	}

	return $cart_item_data;
}
add_filter( 'woocommerce_add_cart_item_data', 'prime_capture_addons', 10, 2 );

/**
 * Move an uploaded add-on file into a private, non-indexable directory.
 *
 * Customer artwork is not public-catalogue content; it must not sit at a
 * guessable URL under the regular uploads directory where anyone with the link
 * — not just the customer and Prime Printing — could fetch it.
 *
 * @param string $field The $_FILES key.
 * @return int Attachment ID, or 0 on failure.
 */
function prime_handle_addon_upload( $field ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';

	$upload_dir = wp_upload_dir();
	$private_dir = $upload_dir['basedir'] . '/prime-private';

	if ( ! file_exists( $private_dir ) ) {
		wp_mkdir_p( $private_dir );
		// Deny direct HTTP access; files are served only through
		// prime_serve_addon_file() after an ownership check.
		file_put_contents( $private_dir . '/.htaccess', "Deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $private_dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	$file = $_FILES[ $field ]; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified by the caller before this function runs.

	$allowed_types = array(
		'pdf'  => 'application/pdf',
		'ai'   => 'application/postscript',
		'eps'  => 'application/postscript',
		'svg'  => 'image/svg+xml',
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
	);

	$filetype = wp_check_filetype( $file['name'], $allowed_types );

	if ( ! $filetype['ext'] ) {
		return 0;
	}

	/*
	 * Stored under an unguessable name, not the customer's own filename.
	 *
	 * The .htaccess written above denies direct HTTP access on Apache — but
	 * this site runs on WordPress.com, which serves through nginx and ignores
	 * .htaccess completely. Verified 2026-09-05: uploading a file and then
	 * fetching its URL with no cookies at all returned HTTP 200 and the file.
	 * So on this host the random name is what actually keeps a customer's
	 * artwork private; nothing links to it, and the URL cannot be derived from
	 * anything the customer knows. The .htaccess stays for the move to
	 * Cloudways (Apache), where it does work — the two together, not either
	 * alone.
	 *
	 * The original filename is kept as the attachment title, so the cart, the
	 * order screen and the invoice still show "logo-final.pdf" rather than a
	 * random string.
	 */
	$filename       = wp_unique_filename( $private_dir, wp_generate_password( 32, false, false ) . '.' . $filetype['ext'] );
	$destination    = $private_dir . '/' . $filename;

	if ( ! move_uploaded_file( $file['tmp_name'], $destination ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_move_uploaded_file
		return 0;
	}

	$attachment_id = wp_insert_attachment(
		array(
			'post_mime_type' => $filetype['type'],
			'post_title'     => sanitize_file_name( $file['name'] ),
			'post_status'    => 'private',
		),
		$destination
	);

	if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
		return 0;
	}

	// This file lives outside the regular uploads tree, so WordPress cannot
	// generate its usual metadata/thumbnails for it — nor should it try to for
	// a PDF or an .ai file.
	update_post_meta( $attachment_id, '_prime_private_path', $destination );

	return (int) $attachment_id;
}

/**
 * Show captured add-ons in the cart and on the order review.
 *
 * @param array $item_data Existing display rows.
 * @param array $cart_item The cart item.
 * @return array
 */
function prime_display_addons_in_cart( $item_data, $cart_item ) {
	if ( empty( $cart_item['prime_addons'] ) ) {
		return $item_data;
	}

	foreach ( $cart_item['prime_addons'] as $addon ) {
		$item_data[] = array(
			'name'  => $addon['label'],
			'value' => 'file' === $addon['type']
				? esc_html( get_the_title( $addon['attachment_id'] ) )
				: esc_html( $addon['value'] ),
		);
	}

	return $item_data;
}
add_filter( 'woocommerce_get_item_data', 'prime_display_addons_in_cart', 10, 2 );

/**
 * Persist add-ons onto the order line item.
 *
 * This is the step the current site is missing — without it, everything above
 * works right up until the order is placed, and then the data silently
 * disappears. It must run on the order, not just the cart, because the cart
 * session ends at checkout; the order is what fulfilment actually looks at.
 *
 * @param WC_Order_Item_Product $item          Order line item.
 * @param string                $cart_item_key Cart item key.
 * @param array                 $values        Cart item values.
 */
function prime_persist_addons_to_order( $item, $cart_item_key, $values ) {
	if ( empty( $values['prime_addons'] ) ) {
		return;
	}

	foreach ( $values['prime_addons'] as $addon ) {
		// For a file addon, the download link is appended to the value, not a
		// separate row — WooCommerce's own admin order screen runs item meta
		// values through make_clickable(), so a plain URL in the text becomes
		// a real link there for free. prime_file_download_url() (inc/account.php)
		// is the same nonced, ownership-checked endpoint the customer's own
		// account order history already uses.
		$item->add_meta_data(
			$addon['label'],
			'file' === $addon['type']
				? get_the_title( $addon['attachment_id'] ) . ' — ' . prime_file_download_url( $addon['attachment_id'] )
				: $addon['value'],
			true
		);
	}

	// The attachment ID map is kept separately (not shown to the customer) so
	// the account "Files" tab (Phase 8) and reorder flow can retrieve the
	// actual file, not just its printed name.
	$files = wp_list_pluck(
		array_filter(
			$values['prime_addons'],
			static function ( $addon ) {
				return 'file' === $addon['type'];
			}
		),
		'attachment_id'
	);

	if ( $files ) {
		$item->update_meta_data( '_prime_addon_files', $files );
	}

	// The display meta above is lossy by design (a formatted string, not
	// structured data) — Phase 8's reorder needs the exact original
	// key/type/value shape back to rebuild cart_item_data faithfully, not a
	// re-parse of "Text to engrave: Prime Printing Co.".
	$item->update_meta_data( '_prime_addons_raw', $values['prime_addons'] );
}
add_action( 'woocommerce_checkout_create_order_line_item', 'prime_persist_addons_to_order', 10, 3 );

/**
 * Map an uploaded file to the order and customer once the order exists.
 *
 * Runs after order creation because the attachment is created at add-to-cart
 * time, before any order exists to associate it with.
 *
 * @param int $order_id Order ID.
 */
function prime_link_addon_files_to_order( $order_id ) {
	$order = wc_get_order( $order_id );

	if ( ! $order ) {
		return;
	}

	foreach ( $order->get_items() as $item ) {
		$files = $item->get_meta( '_prime_addon_files' );

		if ( ! $files ) {
			continue;
		}

		foreach ( (array) $files as $attachment_id ) {
			update_post_meta( $attachment_id, '_prime_order_id', $order_id );
			update_post_meta( $attachment_id, '_prime_customer_id', $order->get_customer_id() );
			wp_update_post(
				array(
					'ID'          => $attachment_id,
					'post_parent' => $order_id,
				)
			);
		}
	}
}
add_action( 'woocommerce_checkout_order_processed', 'prime_link_addon_files_to_order' );
