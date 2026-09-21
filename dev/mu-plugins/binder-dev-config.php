<?php
/**
 * DEV ONLY (mounted by dev/start.sh, never deployed): points the binder plugin
 * at the render service running on this machine.
 */
if ( ! defined( 'BINDER_RENDER_URL' ) ) {
	define( 'BINDER_RENDER_URL', 'http://127.0.0.1:8787' );
}
if ( ! defined( 'BINDER_RENDER_SECRET' ) ) {
	define( 'BINDER_RENDER_SECRET', 'dev-secret-binder' );
}

/**
 * DEV ONLY: /?binder_dev_assign=<product id>:<binder_outer|binder_inner|binder_set> assigns a template to a
 * product, so the browser tests can set one up without wp-admin. Lives in dev/, never deployed.
 */
add_action(
	'init',
	static function () {
		if ( empty( $_GET['binder_dev_assign'] ) || ! class_exists( 'Binder_Product_Meta' ) ) { // phpcs:ignore
			return;
		}
		list( $id, $template ) = array_pad( explode( ':', sanitize_text_field( wp_unslash( $_GET['binder_dev_assign'] ) ) ), 2, '' ); // phpcs:ignore
		if ( $template ) {
			update_post_meta( (int) $id, Binder_Product_Meta::META, sanitize_key( $template ) );
		} else {
			delete_post_meta( (int) $id, Binder_Product_Meta::META );
		}
		wp_die( 'assigned ' . (int) $id . ' => ' . esc_html( $template ) );
	}
);

/**
 * DEV ONLY: order helpers for dev/binder-tests/e2e-step7.mjs (the dev site has no payment gateway).
 *   /?binder_dev_order=1      turns the visitor's cart into a real WooCommerce order (through WC_Checkout, so the
 *                             same hooks fire as at checkout), then marks it Processing as a payment would.
 *   /?binder_dev_notified=1   what the shop notification would have sent (captured instead of calling Twilio).
 */
add_filter(
	'binder_notify_handled',
	static function ( $handled, $order, $files ) {
		update_option(
			'binder_dev_notified',
			array(
				'order'   => $order->get_id(),
				'files'   => $files,
				'message' => class_exists( 'Binder_Notifier' ) ? Binder_Notifier::message( $order, $files ) : '',
			)
		);
		return true;
	},
	5,
	3
);

add_action(
	'wp_loaded',
	static function () {
		if ( ! empty( $_GET['binder_dev_notified'] ) ) { // phpcs:ignore
			wp_send_json( get_option( 'binder_dev_notified', null ) );
		}
		if ( empty( $_GET['binder_dev_order'] ) || ! function_exists( 'WC' ) || ! WC()->cart ) { // phpcs:ignore
			return;
		}
		WC()->cart->calculate_totals();
		$order_id = WC()->checkout()->create_order(
			array(
				'payment_method'     => 'cod',
				'billing_first_name' => 'Dev',
				'billing_last_name'  => 'Customer',
				'billing_email'      => 'dev@example.com',
				'billing_phone'      => '55555555',
				'billing_country'    => 'KW',
			)
		);
		if ( is_wp_error( $order_id ) ) {
			wp_send_json( array( 'error' => $order_id->get_error_message() ), 500 );
		}
		$order = wc_get_order( $order_id );
		$order->set_billing_email( 'dev@example.com' );
		$order->save();
		WC()->cart->empty_cart();
		$order->update_status( 'processing', 'Dev: payment received.' );
		wp_send_json( array( 'order_id' => $order_id, 'key' => $order->get_order_key(), 'received' => $order->get_checkout_order_received_url() ) );
	}
);

/** DEV ONLY: /?binder_dev_stock=<product id>:<instock|outofstock> */
add_action(
	'wp_loaded',
	static function () {
		if ( empty( $_GET['binder_dev_stock'] ) || ! function_exists( 'wc_get_product' ) ) { // phpcs:ignore
			return;
		}
		list( $id, $status ) = array_pad( explode( ':', sanitize_text_field( wp_unslash( $_GET['binder_dev_stock'] ) ) ), 2, '' ); // phpcs:ignore
		$product = wc_get_product( (int) $id );
		if ( $product ) {
			$product->set_stock_status( 'outofstock' === $status ? 'outofstock' : 'instock' );
			$product->save();
		}
		wp_send_json( array( 'id' => (int) $id, 'status' => $status ) );
	}
);

/** DEV ONLY: /?binder_dev_complete=<order id> marks the order Completed and clears the captured notification counter. */
add_action(
	'wp_loaded',
	static function () {
		if ( empty( $_GET['binder_dev_complete'] ) || ! function_exists( 'wc_get_order' ) ) { // phpcs:ignore
			return;
		}
		$order = wc_get_order( absint( $_GET['binder_dev_complete'] ) ); // phpcs:ignore
		if ( $order ) {
			delete_option( 'binder_dev_notified' );
			$order->update_status( 'completed', 'Dev: completed.' );
		}
		wp_send_json( array( 'completed' => (bool) $order, 'notified_again' => (bool) get_option( 'binder_dev_notified' ) ) );
	}
);

/**
 * DEV ONLY: /?binder_dev_test=<name> runs dev/mu-plugins/tests/<name>.php against this
 * fully-configured site (WooCommerce + Polylang + the theme) and prints the result.
 * The tests/ subdirectory is not auto-loaded by WordPress, so nothing there runs on its own.
 */
add_action(
	'wp_loaded',
	static function () {
		if ( empty( $_GET['binder_dev_test'] ) ) { // phpcs:ignore
			return;
		}
		$name = sanitize_file_name( wp_unslash( $_GET['binder_dev_test'] ) ); // phpcs:ignore
		$file = __DIR__ . '/tests/' . $name . '.php';
		header( 'Content-Type: text/plain; charset=utf-8' );
		if ( ! is_readable( $file ) ) {
			exit( 'no such test: ' . esc_html( $name ) );
		}
		try {
			require $file;
		} catch ( Throwable $e ) {
			echo 'FAIL  uncaught: ' . esc_html( $e->getMessage() ) . ' at ' . esc_html( $e->getFile() ) . ':' . (int) $e->getLine() . "\n";
		}
		exit;
	},
	5
);

