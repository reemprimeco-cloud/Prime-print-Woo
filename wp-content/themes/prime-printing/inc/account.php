<?php
/**
 * Phase 8 — customer account page.
 *
 * Overrides WooCommerce's own `/my-account/` endpoints rather than building a
 * separate page (per the build plan) — this keeps login, logout, password
 * reset, and order processing security exactly as WooCommerce already built
 * them, and this file only adds content and a few new endpoints on top.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

/**
 * This checkout never collects a separate shipping address — every address
 * field Phase 5 registers lives under `billing` (see checkout-fields.php) —
 * so the account page should show one "Address" tab, not WooCommerce's
 * default "Billing address" / "Shipping address" pair. WooCommerce already
 * has a built-in setting for exactly this; it just wasn't turned on.
 */
function prime_ensure_billing_only_addresses() {
	if ( get_option( 'prime_billing_only_ensured' ) ) {
		return;
	}

	update_option( 'woocommerce_ship_to_destination', 'billing_only' );
	update_option( 'prime_billing_only_ensured', 1 );
}
add_action( 'woocommerce_init', 'prime_ensure_billing_only_addresses', 22 );

/**
 * A custom "Files" account endpoint — WooCommerce has no equivalent built in.
 *
 * Endpoints only take effect after a rewrite flush, which normally means
 * "visit Settings → Permalinks and save" — not something to ask of a fresh
 * install. The option flag makes the flush run once, automatically, the
 * same idempotent pattern the checkout/shipping/pickup modules use.
 */
function prime_register_files_endpoint() {
	add_rewrite_endpoint( 'files', EP_ROOT | EP_PAGES );
}
add_action( 'init', 'prime_register_files_endpoint' );

function prime_maybe_flush_for_files_endpoint() {
	if ( get_option( 'prime_files_endpoint_flushed' ) ) {
		return;
	}

	flush_rewrite_rules();
	update_option( 'prime_files_endpoint_flushed', 1 );
}
add_action( 'woocommerce_init', 'prime_maybe_flush_for_files_endpoint', 30 );

/**
 * Reorder the account tabs to Orders → Address → Files → Details (matching
 * the reference), relabel a couple to plainer language, and insert the new
 * Files tab where WooCommerce doesn't know it exists.
 *
 * @param string[] $items Endpoint => label.
 * @return string[]
 */
function prime_account_menu_items( $items ) {
	$labels = array(
		'orders'       => __( 'Orders', 'prime-printing' ),
		'edit-address' => __( 'Address', 'prime-printing' ),
		'edit-account' => __( 'Details', 'prime-printing' ),
	);

	foreach ( $labels as $key => $label ) {
		if ( isset( $items[ $key ] ) ) {
			$items[ $key ] = $label;
		}
	}

	unset( $items['dashboard'], $items['downloads'], $items['payment-methods'] );

	$ordered = array();

	foreach ( array( 'orders', 'edit-address' ) as $key ) {
		if ( isset( $items[ $key ] ) ) {
			$ordered[ $key ] = $items[ $key ];
			unset( $items[ $key ] );
		}
	}

	$ordered['files'] = __( 'Files', 'prime-printing' );

	if ( isset( $items['edit-account'] ) ) {
		$ordered['edit-account'] = $items['edit-account'];
		unset( $items['edit-account'] );
	}

	// Whatever's left (customer-logout, any third-party tab) keeps its
	// original relative order, at the end.
	return $ordered + $items;
}
add_filter( 'woocommerce_account_menu_items', 'prime_account_menu_items' );

/**
 * Map a WooCommerce order status to the reference's three filter chips.
 *
 * There's no real per-order "quote" workflow in this build — Phase 4c prices
 * everything automatically — so "Awaiting quote" is repurposed for orders
 * that exist but haven't been paid/confirmed yet (on-hold, pending), which
 * is the closest real equivalent: something the customer is waiting to hear
 * back about.
 *
 * @param string $status Order status without the wc- prefix.
 * @return string One of: processing, delivered, awaiting-quote, other.
 */
function prime_order_filter_group( $status ) {
	if ( in_array( $status, array( 'processing' ), true ) ) {
		return 'processing';
	}

	if ( in_array( $status, array( 'completed' ), true ) ) {
		return 'delivered';
	}

	if ( in_array( $status, array( 'pending', 'on-hold' ), true ) ) {
		return 'awaiting-quote';
	}

	return 'other';
}

/**
 * A wa.me deep link prefilled with the order number, for the "Contact on
 * WhatsApp" button per order.
 *
 * @param WC_Order $order Order.
 * @return string Empty string if no WhatsApp number is configured.
 */
function prime_order_whatsapp_link( WC_Order $order ) {
	$base = prime_contact( 'whatsapp' );

	if ( ! $base ) {
		return '';
	}

	$text = sprintf(
		/* translators: %s: order number */
		__( 'Hi! I have a question about order #%s.', 'prime-printing' ),
		$order->get_order_number()
	);

	$separator = ( false === strpos( $base, '?' ) ) ? '?' : '&';

	return $base . $separator . 'text=' . rawurlencode( $text );
}

/**
 * Every file a customer has uploaded, across every order — Phase 4a/4c
 * uploads are already tagged with `_prime_customer_id` the moment an order
 * is placed (see prime_link_addon_files_to_order() in product-addons.php);
 * this is just the query that was never written to read that tag back.
 *
 * @param int $user_id Customer's user ID.
 * @return WP_Post[] Attachment posts, newest first.
 */
function prime_get_customer_files( $user_id ) {
	return get_posts(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'private',
			'posts_per_page' => -1,
			'meta_key'       => '_prime_customer_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => $user_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'orderby'        => 'date',
			'order'          => 'DESC',
		)
	);
}

/**
 * Stream a customer's own uploaded file — the "Download" button on the
 * Files tab. The upload itself already lives outside the public uploads
 * tree with a `Deny from all` .htaccess (see prime_handle_addon_upload() in
 * product-addons.php); this is the ownership-checked door back into it that
 * comment already promised but never actually implemented.
 *
 * Hooked to `template_redirect`, not an `admin_action_*` on `admin.php` —
 * WordPress's own wp-admin bootstrap redirects any user without an admin-area
 * capability away before an `admin_action_*` hook ever fires, and a
 * WooCommerce "customer" has none. That gate isn't specific to this hook; it
 * runs for the whole of /wp-admin/, so an admin.php-based endpoint here would
 * silently 302 every real customer back to /my-account/ instead of serving
 * the file — caught by testing this as an actual customer account, not just
 * as an admin (admins have wp-admin access, so they'd never have hit it).
 */
function prime_serve_addon_file() {
	if ( empty( $_GET['prime_download_file'] ) ) {
		return;
	}

	$attachment_id = absint( $_GET['prime_download_file'] );

	if ( ! $attachment_id || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( wc_clean( wp_unslash( $_GET['_wpnonce'] ) ), 'prime_download_file_' . $attachment_id ) ) {
		wp_die( esc_html__( 'Invalid or expired link.', 'prime-printing' ), 403 );
	}

	$owner_id = (int) get_post_meta( $attachment_id, '_prime_customer_id', true );

	if ( ( ! $owner_id || ! is_user_logged_in() || get_current_user_id() !== $owner_id ) && ! current_user_can( 'edit_shop_orders' ) ) {
		wp_die( esc_html__( 'You do not have permission to download this file.', 'prime-printing' ), 403 );
	}

	$path = get_post_meta( $attachment_id, '_prime_private_path', true );

	if ( ! $path || ! is_readable( $path ) ) {
		wp_die( esc_html__( 'File not found.', 'prime-printing' ), 404 );
	}

	nocache_headers();
	header( 'Content-Type: ' . get_post_mime_type( $attachment_id ) );
	header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
	header( 'Content-Length: ' . filesize( $path ) );
	readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile -- streaming a private file after the ownership check above.
	exit;
}
add_action( 'template_redirect', 'prime_serve_addon_file' );

/**
 * Nonce'd download URL for one of a customer's own files.
 *
 * @param int $attachment_id Attachment ID.
 * @return string
 */
function prime_file_download_url( $attachment_id ) {
	return wp_nonce_url(
		add_query_arg( 'prime_download_file', $attachment_id, home_url( '/' ) ),
		'prime_download_file_' . $attachment_id
	);
}

/**
 * Re-add every item from a past order to the cart — the "Reorder" button.
 *
 * The whole point (per the build plan) is that a customer with a past
 * custom-pricing order can reorder it without re-uploading their artwork.
 * That only works because product-addons.php and product-pricing.php now
 * also save `_prime_addons_raw` / `_prime_pricing_specs_raw` on the order
 * line item — the exact structured data the original add-to-cart produced,
 * attachment IDs included — rather than only the human-readable "Size: 80 ×
 * 120 cm" meta meant for the invoice and admin screens. Re-adding to cart
 * with that same structure means the file is reused as-is, and price is
 * still recalculated from scratch server-side exactly like any other add to
 * cart (Phase 4c's security model doesn't change for a reorder).
 *
 * @param WC_Order_Item_Product $item Order line item.
 * @return bool True if the item was re-added to the cart.
 */
function prime_reorder_line_item( WC_Order_Item_Product $item ) {
	$product = $item->get_product();

	if ( ! $product || ! $product->is_purchasable() ) {
		return false;
	}

	$cart_item_data = array();

	$addons_raw = $item->get_meta( '_prime_addons_raw' );
	if ( $addons_raw ) {
		$cart_item_data['prime_addons'] = $addons_raw;
		$cart_item_data['unique_key']   = md5( wp_json_encode( $addons_raw ) . microtime() );
	}

	$specs_raw = $item->get_meta( '_prime_pricing_specs_raw' );
	if ( $specs_raw ) {
		$cart_item_data['prime_pricing_specs'] = $specs_raw;
		$cart_item_data['unique_key']          = md5( wp_json_encode( $specs_raw ) . microtime() );
	}

	return (bool) WC()->cart->add_to_cart(
		$product->get_id(),
		$item->get_quantity(),
		$item->get_variation_id(),
		array(),
		$cart_item_data
	);
}

/**
 * Re-add every item from a past order to the cart — the Orders tab's
 * "Reorder" button.
 *
 * @param WC_Order $order Order to reorder.
 * @return array{added: int, skipped: int} Counts, for the notice shown after redirect.
 */
function prime_reorder( WC_Order $order ) {
	$added   = 0;
	$skipped = 0;

	foreach ( $order->get_items() as $item ) {
		/** @var WC_Order_Item_Product $item */
		if ( prime_reorder_line_item( $item ) ) {
			++$added;
		} else {
			++$skipped;
		}
	}

	return array(
		'added'   => $added,
		'skipped' => $skipped,
	);
}

/**
 * The Files tab's "Order again" button: re-add just the one line item that
 * originally used this file — not the whole order it came from — which is
 * the more literal reading of "re-attachable to a new order in one click."
 *
 * @param int $order_id      Order the file came from.
 * @param int $attachment_id The file.
 * @return bool True if a matching item was found and re-added.
 */
function prime_reorder_file( $order_id, $attachment_id ) {
	$order = wc_get_order( $order_id );

	if ( ! $order instanceof WC_Order ) {
		return false;
	}

	foreach ( $order->get_items() as $item ) {
		/** @var WC_Order_Item_Product $item */
		$files = (array) $item->get_meta( '_prime_addon_files' );

		if ( in_array( (int) $attachment_id, array_map( 'intval', $files ), true ) ) {
			return prime_reorder_line_item( $item );
		}
	}

	return false;
}

/**
 * The Orders tab's "Reorder" button (a whole order) and the Files tab's
 * "Order again" button (one file) both land here with a nonce'd request;
 * this runs early enough to redirect before any output starts.
 */
function prime_handle_reorder_request() {
	if ( ! is_user_logged_in() ) {
		return;
	}

	if ( ! empty( $_GET['prime_reorder'] ) ) {
		$order_id = absint( $_GET['prime_reorder'] );

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( wc_clean( wp_unslash( $_GET['_wpnonce'] ) ), 'prime_reorder_' . $order_id ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order || get_current_user_id() !== $order->get_customer_id() ) {
			return;
		}

		$result = prime_reorder( $order );

		if ( $result['added'] ) {
			wc_add_notice(
				sprintf(
					/* translators: %d: number of items added */
					_n( 'Added %d item from that order to your cart.', 'Added %d items from that order to your cart.', $result['added'], 'prime-printing' ),
					$result['added']
				),
				'success'
			);
		}

		if ( $result['skipped'] ) {
			wc_add_notice(
				__( 'One or more items from that order are no longer available and were skipped.', 'prime-printing' ),
				'notice'
			);
		}

		wp_safe_redirect( wc_get_cart_url() );
		exit;
	}

	if ( ! empty( $_GET['prime_reorder_file'] ) && ! empty( $_GET['order_id'] ) ) {
		$attachment_id = absint( $_GET['prime_reorder_file'] );
		$order_id      = absint( $_GET['order_id'] );

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( wc_clean( wp_unslash( $_GET['_wpnonce'] ) ), 'prime_reorder_file_' . $attachment_id ) ) {
			return;
		}

		$owner_id = (int) get_post_meta( $attachment_id, '_prime_customer_id', true );

		if ( $owner_id !== get_current_user_id() ) {
			return;
		}

		if ( prime_reorder_file( $order_id, $attachment_id ) ) {
			wc_add_notice( __( 'Added to your cart, with the same file.', 'prime-printing' ), 'success' );
		} else {
			wc_add_notice( __( 'That item is no longer available.', 'prime-printing' ), 'notice' );
		}

		wp_safe_redirect( wc_get_cart_url() );
		exit;
	}
}
add_action( 'template_redirect', 'prime_handle_reorder_request' );

/**
 * Nonce'd reorder URL for a whole order (Orders tab).
 *
 * @param int $order_id Order ID.
 * @return string
 */
function prime_reorder_url( $order_id ) {
	return wp_nonce_url(
		add_query_arg( 'prime_reorder', $order_id, home_url( '/' ) ),
		'prime_reorder_' . $order_id
	);
}

/**
 * Nonce'd reorder URL for a single file (Files tab).
 *
 * @param int $order_id      Order the file came from.
 * @param int $attachment_id The file.
 * @return string
 */
function prime_reorder_file_url( $order_id, $attachment_id ) {
	return wp_nonce_url(
		add_query_arg(
			array(
				'prime_reorder_file' => $attachment_id,
				'order_id'           => $order_id,
			),
			home_url( '/' )
		),
		'prime_reorder_file_' . $attachment_id
	);
}

/**
 * Render the Files tab.
 */
function prime_account_files_content() {
	$files = prime_get_customer_files( get_current_user_id() );
	include PRIME_DIR . '/template-parts/account/files.php';
}
add_action( 'woocommerce_account_files_endpoint', 'prime_account_files_content' );

/**
 * Company billing info + notification preferences, stored as user meta so
 * they persist across orders — unlike checkout-fields.php's identically
 * named fields, which are per-order only. Rendered into WooCommerce's own
 * edit-account form rather than a separate page.
 */
function prime_account_extra_fields() {
	$user_id          = get_current_user_id();
	$company_name     = get_user_meta( $user_id, 'prime_company_name', true );
	$tax_number       = get_user_meta( $user_id, 'prime_tax_number', true );
	$notify_whatsapp  = get_user_meta( $user_id, 'prime_notify_whatsapp', true );
	$notify_invoice   = get_user_meta( $user_id, 'prime_notify_invoice_email', true );
	$notify_marketing = get_user_meta( $user_id, 'prime_notify_marketing', true );

	include PRIME_DIR . '/template-parts/account/extra-fields.php';
}
add_action( 'woocommerce_edit_account_form', 'prime_account_extra_fields' );

/**
 * Save the extra fields above. Hooked after WooCommerce's own
 * woocommerce_save_account_details has already saved name/phone/email, so a
 * failure here never blocks the fields WooCommerce itself considers
 * required.
 *
 * @param int $user_id User being saved.
 */
function prime_save_account_extra_fields( $user_id ) {
	if ( ! isset( $_POST['prime_account_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['prime_account_nonce'] ) ), 'prime_account_extra_fields' ) ) {
		return;
	}

	update_user_meta( $user_id, 'prime_company_name', isset( $_POST['prime_company_name'] ) ? sanitize_text_field( wp_unslash( $_POST['prime_company_name'] ) ) : '' );
	update_user_meta( $user_id, 'prime_tax_number', isset( $_POST['prime_tax_number'] ) ? sanitize_text_field( wp_unslash( $_POST['prime_tax_number'] ) ) : '' );
	update_user_meta( $user_id, 'prime_notify_whatsapp', isset( $_POST['prime_notify_whatsapp'] ) ? 'yes' : '' );
	update_user_meta( $user_id, 'prime_notify_invoice_email', isset( $_POST['prime_notify_invoice_email'] ) ? 'yes' : '' );
	update_user_meta( $user_id, 'prime_notify_marketing', isset( $_POST['prime_notify_marketing'] ) ? 'yes' : '' );
}
add_action( 'woocommerce_save_account_details', 'prime_save_account_extra_fields' );

/**
 * Stat tiles for the dashboard: total orders, active orders, total spent,
 * saved files — matching the reference. One customer query, not four.
 *
 * @param int $user_id Customer's user ID.
 * @return array{total_orders: int, active_orders: int, total_spent: string, saved_files: int}
 */
function prime_account_stats( $user_id ) {
	$orders = wc_get_orders(
		array(
			'customer_id' => $user_id,
			'limit'       => -1,
			'return'      => 'objects',
		)
	);

	$total_spent   = 0.0;
	$active_orders = 0;

	foreach ( $orders as $order ) {
		if ( $order->has_status( array( 'processing', 'completed' ) ) ) {
			$total_spent += (float) $order->get_total();
		}
		if ( $order->has_status( array( 'processing', 'on-hold', 'pending' ) ) ) {
			++$active_orders;
		}
	}

	return array(
		'total_orders'  => count( $orders ),
		'active_orders' => $active_orders,
		'total_spent'   => prime_invoice_format_money( $total_spent ),
		'saved_files'   => count( prime_get_customer_files( $user_id ) ),
	);
}
