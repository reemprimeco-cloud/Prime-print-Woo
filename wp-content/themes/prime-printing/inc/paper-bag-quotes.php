<?php
/**
 * Paper bag quote requests (Reem, 2026-10-03/04).
 *
 * Besides the calculated price in inc/paper-bag-calculator.php, a customer
 * can ask for a quote: they size and design the bag and place the order at
 * no charge. The order arrives as "Quote requested" with the size, quantity
 * and print files. The shop opens it, types the price in the "Paper bag
 * quote" box and sends it: the order's line is priced, the order becomes
 * "Pending payment", and the customer gets an email with the price and a
 * pay link. Paying from the link turns it into a normal Processing order.
 *
 * A quote settled outside the site is handled on the order too: send the
 * quote, then set the order to Processing by hand.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

const PRIME_BAG_QUOTE_STATUS   = 'wc-quote-request';
const PRIME_BAG_QUOTE_ITEM_KEY = '_prime_bag_quote_request';

/* ----------------------------------------------------------- cart & order */

/**
 * Does the cart hold a bag waiting for a quote?
 *
 * @return bool
 */
function prime_cart_has_bag_quote_request() {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return false;
	}
	foreach ( WC()->cart->get_cart() as $item ) {
		if ( ! empty( $item['prime_paper_bag_specs'] ) && prime_bag_specs_is_quote_request( $item['prime_paper_bag_specs'] ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Does an order hold a bag waiting for a quote?
 *
 * @param WC_Order $order Order.
 * @return bool
 */
function prime_order_has_bag_quote_request( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return false;
	}
	foreach ( $order->get_items() as $item ) {
		if ( 'yes' === $item->get_meta( PRIME_BAG_QUOTE_ITEM_KEY ) ) {
			return true;
		}
	}

	return false;
}

/**
 * "Quote on request" where the cart would show 0.000.
 *
 * @param string $html      Price html.
 * @param array  $cart_item Cart item.
 * @return string
 */
function prime_bag_quote_cart_price( $html, $cart_item ) {
	if ( ! empty( $cart_item['prime_paper_bag_specs'] ) && prime_bag_specs_is_quote_request( $cart_item['prime_paper_bag_specs'] ) ) {
		return '<span class="prime-quote-tag">' . esc_html__( 'Quote on request', 'prime-printing' ) . '</span>';
	}

	return $html;
}
add_filter( 'woocommerce_cart_item_price', 'prime_bag_quote_cart_price', 10, 2 );
add_filter( 'woocommerce_cart_item_subtotal', 'prime_bag_quote_cart_price', 10, 2 );

/**
 * Delivery is part of the quote: no delivery charge on a quote request.
 *
 * @param array $rates Shipping rates.
 * @return array
 */
function prime_bag_quote_free_delivery( $rates ) {
	if ( ! prime_cart_has_bag_quote_request() ) {
		return $rates;
	}
	foreach ( $rates as $rate ) {
		$rate->set_cost( 0 );
		$rate->set_taxes( array() );
		$rate->set_label( __( 'Delivery included in your quote', 'prime-printing' ) );
	}

	return $rates;
}
add_filter( 'woocommerce_package_rates', 'prime_bag_quote_free_delivery', 50 );

/**
 * The checkout button says what will happen.
 *
 * @param string $text Button text.
 * @return string
 */
function prime_bag_quote_order_button( $text ) {
	return prime_cart_has_bag_quote_request() ? __( 'Request a quote', 'prime-printing' ) : $text;
}
add_filter( 'woocommerce_order_button_text', 'prime_bag_quote_order_button' );

/* ------------------------------------------------------------ order status */

/**
 * "Quote requested": where a free bag order waits for its price.
 */
function prime_bag_quote_register_status() {
	register_post_status(
		PRIME_BAG_QUOTE_STATUS,
		array(
			'label'                     => _x( 'Quote requested', 'Order status', 'prime-printing' ),
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: count. */
			'label_count'               => _n_noop( 'Quote requested <span class="count">(%s)</span>', 'Quote requested <span class="count">(%s)</span>', 'prime-printing' ),
		)
	);
}
add_action( 'init', 'prime_bag_quote_register_status' );

/**
 * @param array $statuses Order statuses.
 * @return array
 */
function prime_bag_quote_order_statuses( $statuses ) {
	$out = array();
	foreach ( $statuses as $key => $label ) {
		$out[ $key ] = $label;
		if ( 'wc-pending' === $key ) {
			$out[ PRIME_BAG_QUOTE_STATUS ] = _x( 'Quote requested', 'Order status', 'prime-printing' );
		}
	}
	if ( ! isset( $out[ PRIME_BAG_QUOTE_STATUS ] ) ) {
		$out[ PRIME_BAG_QUOTE_STATUS ] = _x( 'Quote requested', 'Order status', 'prime-printing' );
	}

	return $out;
}
add_filter( 'wc_order_statuses', 'prime_bag_quote_order_statuses' );

/**
 * A free order with a bag waiting for a price is a quote request, not a paid
 * order to print (runs after prime_paid_order_status).
 *
 * @param string   $status   Status.
 * @param int      $order_id Order id.
 * @param WC_Order $order    Order.
 * @return string
 */
function prime_bag_quote_request_status( $status, $order_id, $order = null ) {
	$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );

	return prime_order_has_bag_quote_request( $order ) && (float) $order->get_total() <= 0 ? 'quote-request' : $status;
}
add_filter( 'woocommerce_payment_complete_order_status', 'prime_bag_quote_request_status', 30, 3 );

/**
 * What the thank-you page says for a quote request.
 *
 * @param string   $text  Text.
 * @param WC_Order $order Order.
 * @return string
 */
function prime_bag_quote_thankyou( $text, $order ) {
	if ( prime_order_has_bag_quote_request( $order ) ) {
		return __( 'Thank you — we have your bag design and quote request. We will email your price within one working day. Nothing is charged until you approve it.', 'prime-printing' );
	}

	return $text;
}
add_filter( 'woocommerce_thankyou_order_received_text', 'prime_bag_quote_thankyou', 10, 2 );

/* ----------------------------------------------------------------- emails */

/**
 * Send one email in WooCommerce's own template.
 *
 * @param string $to      Address.
 * @param string $subject Subject.
 * @param string $html    Body html.
 */
function prime_bag_quote_mail( $to, $subject, $html ) {
	if ( ! $to || ! function_exists( 'WC' ) ) {
		return;
	}
	$mailer = WC()->mailer();
	$mailer->send( $to, $subject, $mailer->wrap_message( $subject, $html ), "Content-Type: text/html\r\n" );
}

/**
 * The bag lines of an order, as text rows.
 *
 * @param WC_Order $order Order.
 * @return string
 */
function prime_bag_quote_order_summary_html( $order ) {
	$rows = array();
	foreach ( $order->get_items() as $item ) {
		$specs = $item->get_meta( '_prime_paper_bag_specs_raw' );
		if ( ! is_array( $specs ) ) {
			continue;
		}
		$sheet  = prime_paper_bag_sheet( $specs['width'], $specs['height'], $specs['depth'] );
		$rows[] = sprintf(
			'<li><strong>%s</strong> — %s × %s × %s cm (flat sheet %s × %s cm), %s bags</li>',
			esc_html( $item->get_name() ),
			esc_html( $specs['width'] ),
			esc_html( $specs['height'] ),
			esc_html( $specs['depth'] ),
			esc_html( $sheet['w'] ),
			esc_html( $sheet['h'] ),
			esc_html( $specs['quantity'] )
		);
	}

	return $rows ? '<ul>' . implode( '', $rows ) . '</ul>' : '';
}

/**
 * The request arrived: tell the shop and the customer.
 *
 * @param int $order_id Order.
 */
function prime_bag_quote_requested_emails( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order || ! prime_order_has_bag_quote_request( $order ) || 'yes' === $order->get_meta( '_prime_bag_quote_notified' ) ) {
		return;
	}
	$order->update_meta_data( '_prime_bag_quote_notified', 'yes' );
	$order->save();

	$summary = prime_bag_quote_order_summary_html( $order );
	$admin   = sprintf(
		'<p>%s</p>%s<p><a href="%s">%s</a></p>',
		sprintf(
			/* translators: 1: order number, 2: customer name, 3: phone. */
			esc_html__( 'Order #%1$s from %2$s (%3$s) is waiting for a bag price.', 'prime-printing' ),
			esc_html( $order->get_order_number() ),
			esc_html( $order->get_formatted_billing_full_name() ),
			esc_html( $order->get_billing_phone() )
		),
		$summary,
		esc_url( $order->get_edit_order_url() ),
		esc_html__( 'Open the order and send the quote', 'prime-printing' )
	);
	prime_bag_quote_mail( get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) ), sprintf( __( 'Bag quote request — order #%s', 'prime-printing' ), $order->get_order_number() ), $admin );

	$customer = sprintf(
		'<p>%s</p>%s<p>%s</p><p>%s</p>',
		esc_html__( 'We have your paper bag design and quote request:', 'prime-printing' ),
		$summary,
		esc_html__( 'We will email your price within one working day. Nothing is charged until you approve it.', 'prime-printing' ),
		esc_html__( 'استلمنا تصميم الكيس الورقي وطلب التسعير. سنرسل لك السعر بالبريد خلال يوم عمل، ولن يُخصم أي مبلغ قبل موافقتك.', 'prime-printing' )
	);
	prime_bag_quote_mail( $order->get_billing_email(), sprintf( __( 'Your bag quote request — order #%s', 'prime-printing' ), $order->get_order_number() ), $customer );
}
add_action( 'woocommerce_order_status_quote-request', 'prime_bag_quote_requested_emails' );

/**
 * The quote is ready: the price and a pay link.
 *
 * @param WC_Order $order Order.
 * @param float    $price Total price.
 */
function prime_bag_quote_send_email( $order, $price ) {
	$html = sprintf(
		'<p>%s</p>%s<p style="font-size:20px"><strong>%s</strong></p><p><a href="%s" style="display:inline-block;background:#10254a;color:#fff;padding:12px 22px;text-decoration:none;border-radius:4px">%s</a></p><p>%s</p><p>%s</p>',
		esc_html__( 'Here is the price for your paper bags:', 'prime-printing' ),
		prime_bag_quote_order_summary_html( $order ),
		wp_kses_post( wc_price( $price ) ),
		esc_url( $order->get_checkout_payment_url() ),
		esc_html__( 'Approve and pay', 'prime-printing' ),
		esc_html__( 'Delivery is included. Printing starts once payment is received.', 'prime-printing' ),
		esc_html__( 'هذا هو سعر أكياسك الورقية شاملاً التوصيل. اضغط «الموافقة والدفع» لاعتماد السعر، وتبدأ الطباعة بعد استلام الدفع.', 'prime-printing' )
	);
	prime_bag_quote_mail( $order->get_billing_email(), sprintf( __( 'Your paper bag price — order #%s', 'prime-printing' ), $order->get_order_number() ), $html );
}

/* ------------------------------------------------------- admin: the order */

/**
 * The "Paper bag quote" box on a bag order.
 */
function prime_bag_quote_add_meta_box() {
	$screens = array( 'shop_order' );
	if ( function_exists( 'wc_get_page_screen_id' ) ) {
		$screens[] = wc_get_page_screen_id( 'shop-order' );
	}
	foreach ( array_unique( $screens ) as $screen ) {
		add_meta_box( 'prime-bag-quote', __( 'Paper bag quote', 'prime-printing' ), 'prime_bag_quote_render_meta_box', $screen, 'side', 'high' );
	}
}
add_action( 'add_meta_boxes', 'prime_bag_quote_add_meta_box' );

/**
 * @param WP_Post|WC_Order $post_or_order The order.
 */
function prime_bag_quote_render_meta_box( $post_or_order ) {
	$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
	if ( ! $order ) {
		return;
	}
	$has_bag = false;
	foreach ( $order->get_items() as $item ) {
		if ( is_array( $item->get_meta( '_prime_paper_bag_specs_raw' ) ) ) {
			$has_bag = true;
		}
	}
	if ( ! $has_bag ) {
		echo '<p>' . esc_html__( 'No paper bag on this order.', 'prime-printing' ) . '</p>';
		return;
	}

	$sent = $order->get_meta( '_prime_bag_quote' );
	echo wp_kses_post( prime_bag_quote_order_summary_html( $order ) );

	if ( is_array( $sent ) && ! empty( $sent['sent_at'] ) ) {
		printf(
			'<p><strong>%s</strong> %s<br><strong>%s</strong> %s</p>',
			esc_html__( 'Quoted:', 'prime-printing' ),
			wp_kses_post( wc_price( $sent['price'] ) ),
			esc_html__( 'Sent:', 'prime-printing' ),
			esc_html( date_i18n( get_option( 'date_format' ) . ' H:i', (int) $sent['sent_at'] ) )
		);
		if ( $order->needs_payment() ) {
			printf( '<p><a href="%s" target="_blank" rel="noopener">%s</a></p>', esc_url( $order->get_checkout_payment_url() ), esc_html__( 'Customer pay link', 'prime-printing' ) );
		}
	} elseif ( ! prime_order_has_bag_quote_request( $order ) ) {
		echo '<p>' . esc_html__( 'Ordered at the calculated price; nothing to quote.', 'prime-printing' ) . '</p>';
		return;
	}

	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="prime_bag_send_quote">
		<input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>">
		<?php wp_nonce_field( 'prime_bag_send_quote_' . $order->get_id() ); ?>
		<p>
			<label for="prime-bag-quote-price"><strong><?php esc_html_e( 'Total price incl. delivery (KWD)', 'prime-printing' ); ?></strong></label><br>
			<input type="number" id="prime-bag-quote-price" name="price" step="0.001" min="0.001" style="width:100%" value="<?php echo esc_attr( is_array( $sent ) ? $sent['price'] : '' ); ?>" required>
		</p>
		<p>
			<button type="submit" class="button button-primary" style="width:100%">
				<?php echo is_array( $sent ) && ! empty( $sent['sent_at'] ) ? esc_html__( 'Resend quote', 'prime-printing' ) : esc_html__( 'Send quote to customer', 'prime-printing' ); ?>
			</button>
		</p>
		<p class="description"><?php esc_html_e( 'Prices the order, sets it to Pending payment and emails the customer the price with a pay link. Paid outside the site? Send the quote, then set the order to Processing.', 'prime-printing' ); ?></p>
	</form>
	<?php
}

/**
 * Price the order and send the quote.
 */
function prime_bag_quote_handle_send() {
	$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
	check_admin_referer( 'prime_bag_send_quote_' . $order_id );

	if ( ! current_user_can( 'edit_shop_orders' ) ) {
		wp_die( esc_html__( 'Not allowed.', 'prime-printing' ) );
	}
	$order = wc_get_order( $order_id );
	$price = isset( $_POST['price'] ) ? round( (float) wp_unslash( $_POST['price'] ), 3 ) : 0;
	if ( ! $order || $price <= 0 ) {
		wp_die( esc_html__( 'Enter a price.', 'prime-printing' ) );
	}

	// Price the bag line(s): the quote is the whole run, delivery included.
	foreach ( $order->get_items() as $item ) {
		if ( ! is_array( $item->get_meta( '_prime_paper_bag_specs_raw' ) ) ) {
			continue;
		}
		$item->set_subtotal( $price );
		$item->set_total( $price );
		$item->update_meta_data( PRIME_BAG_QUOTE_ITEM_KEY, 'quoted' );
		$item->save();
	}
	foreach ( $order->get_items( 'shipping' ) as $ship ) {
		$ship->set_total( 0 );
		$ship->save();
	}
	$order->calculate_totals( false );

	$order->update_meta_data( '_prime_bag_quote', array( 'price' => $price, 'sent_at' => time() ) );
	$order->add_order_note( sprintf( 'Quote sent: %s KWD.', number_format( $price, 3 ) ) );
	if ( ! $order->is_paid() ) {
		$order->set_status( 'pending', 'Quote sent, awaiting approval and payment.' );
	}
	$order->save();

	prime_bag_quote_send_email( $order, $price );

	wp_safe_redirect( add_query_arg( 'prime_quote_sent', '1', $order->get_edit_order_url() ) );
	exit;
}
add_action( 'admin_post_prime_bag_send_quote', 'prime_bag_quote_handle_send' );

/**
 * A note after sending.
 */
function prime_bag_quote_admin_notice() {
	if ( ! empty( $_GET['prime_quote_sent'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Quote sent to the customer.', 'prime-printing' ) . '</p></div>';
	}
}
add_action( 'admin_notices', 'prime_bag_quote_admin_notice' );
