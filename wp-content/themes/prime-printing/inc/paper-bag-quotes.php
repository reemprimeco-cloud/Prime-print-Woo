<?php
/**
 * Paper bag quotes (Reem, 2026-10-03): the bag is not priced on the site.
 *
 * Two ways in, both ending in a normal paid order:
 *
 *   1. Request a quote. The customer sizes the bag, designs it and places the
 *      order at no charge. The order arrives as "Quote requested" with the
 *      size, quantity and print files. The shop opens it, types the price in
 *      the "Paper bag quote" box and sends it: the order's line is priced,
 *      the order becomes "Pending payment", and the customer gets an email
 *      with the price, a pay link, and a quote code.
 *
 *   2. Order with a quote code. A code (from the email above, or one the shop
 *      made by hand under WooCommerce → Bag quotes after a WhatsApp quote) is
 *      typed on the product page. It carries a price for one exact bag size
 *      and quantity; with it the product is priced and ordered online like
 *      anything else. A code is used once.
 *
 * Codes are stored as a private post type (prime_bag_quote), one post per
 * code, with the size, quantity, price, who it is for and where it came from.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

const PRIME_BAG_QUOTE_CPT      = 'prime_bag_quote';
const PRIME_BAG_QUOTE_STATUS   = 'wc-quote-request';
const PRIME_BAG_QUOTE_DAYS     = 60;
const PRIME_BAG_QUOTE_ITEM_KEY = '_prime_bag_quote_request';

/* ------------------------------------------------------------------ storage */

/**
 * The quote post type: storage only, no UI of its own.
 */
function prime_bag_quote_register_cpt() {
	register_post_type(
		PRIME_BAG_QUOTE_CPT,
		array(
			'label'           => 'Bag quotes',
			'public'          => false,
			'show_ui'         => false,
			'show_in_rest'    => false,
			'supports'        => array( 'title' ),
			'capability_type' => 'post',
		)
	);
}
add_action( 'init', 'prime_bag_quote_register_cpt' );

/**
 * A fresh code: PB- plus six characters from an alphabet without 0/O/1/I.
 *
 * @return string
 */
function prime_bag_quote_make_code() {
	$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

	do {
		$code = 'PB-';
		for ( $i = 0; $i < 6; $i++ ) {
			$code .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
		}
	} while ( prime_bag_quote_find( $code ) );

	return $code;
}

/**
 * Create a quote code.
 *
 * @param array $args {
 *   @type float  $price    Total price for the run, KWD, delivery included.
 *   @type float  $w        Width cm.
 *   @type float  $h        Height cm.
 *   @type float  $d        Depth cm.
 *   @type int    $qty      Bags.
 *   @type string $name     Customer name (optional).
 *   @type string $email    Customer email (optional).
 *   @type string $phone    Customer phone (optional).
 *   @type string $note     Internal note (optional).
 *   @type int    $order_id The quote-request order it answers (optional).
 * }
 * @return array|WP_Error The quote record.
 */
function prime_bag_quote_create( array $args ) {
	$code = prime_bag_quote_make_code();
	$id   = wp_insert_post(
		array(
			'post_type'   => PRIME_BAG_QUOTE_CPT,
			'post_status' => 'publish',
			'post_title'  => $code,
			'meta_input'  => array(
				'_code'          => $code,
				'_price'         => round( (float) $args['price'], 3 ),
				'_w'             => round( (float) $args['w'], 1 ),
				'_h'             => round( (float) $args['h'], 1 ),
				'_d'             => round( (float) $args['d'], 1 ),
				'_qty'           => max( 1, (int) $args['qty'] ),
				'_name'          => sanitize_text_field( $args['name'] ?? '' ),
				'_email'         => sanitize_email( $args['email'] ?? '' ),
				'_phone'         => sanitize_text_field( $args['phone'] ?? '' ),
				'_note'          => sanitize_text_field( $args['note'] ?? '' ),
				'_order_id'      => (int) ( $args['order_id'] ?? 0 ),
				'_used_order_id' => 0,
				'_expires'       => time() + PRIME_BAG_QUOTE_DAYS * DAY_IN_SECONDS,
			),
		),
		true
	);

	if ( is_wp_error( $id ) ) {
		return $id;
	}

	return prime_bag_quote_record( $id );
}

/**
 * A quote as a plain array.
 *
 * @param int $id Post id.
 * @return array|null
 */
function prime_bag_quote_record( $id ) {
	$post = get_post( $id );
	if ( ! $post || PRIME_BAG_QUOTE_CPT !== $post->post_type ) {
		return null;
	}
	$m = static function ( $k ) use ( $id ) {
		return get_post_meta( $id, $k, true );
	};

	return array(
		'id'            => (int) $id,
		'code'          => (string) $m( '_code' ),
		'price'         => (float) $m( '_price' ),
		'w'             => (float) $m( '_w' ),
		'h'             => (float) $m( '_h' ),
		'd'             => (float) $m( '_d' ),
		'qty'           => (int) $m( '_qty' ),
		'name'          => (string) $m( '_name' ),
		'email'         => (string) $m( '_email' ),
		'phone'         => (string) $m( '_phone' ),
		'note'          => (string) $m( '_note' ),
		'order_id'      => (int) $m( '_order_id' ),
		'used_order_id' => (int) $m( '_used_order_id' ),
		'expires'       => (int) $m( '_expires' ),
		'created'       => get_post_time( 'U', true, $post ),
	);
}

/**
 * Tidy a typed code: upper case, no spaces, the dash optional.
 *
 * @param string $code Raw.
 * @return string
 */
function prime_bag_quote_clean_code( $code ) {
	$code = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $code ) );

	return '' === $code ? '' : ( 0 === strpos( $code, 'PB' ) ? 'PB-' . substr( $code, 2 ) : $code );
}

/**
 * Find a quote by code.
 *
 * @param string $code Code.
 * @return array|null
 */
function prime_bag_quote_find( $code ) {
	$code = prime_bag_quote_clean_code( $code );
	if ( '' === $code ) {
		return null;
	}
	$ids = get_posts(
		array(
			'post_type'      => PRIME_BAG_QUOTE_CPT,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => '_code', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => $code, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		)
	);

	return $ids ? prime_bag_quote_record( $ids[0] ) : null;
}

/**
 * All quotes, newest first.
 *
 * @return array[]
 */
function prime_bag_quote_all() {
	$ids = get_posts(
		array(
			'post_type'      => PRIME_BAG_QUOTE_CPT,
			'post_status'    => 'publish',
			'posts_per_page' => 200,
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'DESC',
		)
	);

	return array_values( array_filter( array_map( 'prime_bag_quote_record', $ids ) ) );
}

/**
 * Is this code good for this bag, right now?
 *
 * @param string $code Code as typed.
 * @param float  $w    Width cm.
 * @param float  $h    Height cm.
 * @param float  $d    Depth cm.
 * @param int    $qty  Bags.
 * @return array|WP_Error The quote, or why not.
 */
function prime_bag_quote_check( $code, $w, $h, $d, $qty ) {
	$q = prime_bag_quote_find( $code );

	if ( ! $q ) {
		return new WP_Error( 'unknown', __( 'We could not find this quote code. Check it and try again.', 'prime-printing' ) );
	}
	if ( $q['used_order_id'] ) {
		return new WP_Error( 'used', __( 'This quote code has already been used.', 'prime-printing' ) );
	}
	if ( $q['expires'] && $q['expires'] < time() ) {
		return new WP_Error( 'expired', __( 'This quote has expired. Ask us for a new one.', 'prime-printing' ) );
	}
	$same = static function ( $a, $b ) {
		return abs( (float) $a - (float) $b ) < 0.05;
	};
	if ( ! $same( $q['w'], $w ) || ! $same( $q['h'], $h ) || ! $same( $q['d'], $d ) || (int) $qty !== $q['qty'] ) {
		return new WP_Error(
			'mismatch',
			sprintf(
				/* translators: 1: width, 2: height, 3: depth, 4: quantity. */
				__( 'This quote is for %1$s × %2$s × %3$s cm, %4$s bags. Enter that size and quantity, or ask us for a new quote.', 'prime-printing' ),
				$q['w'],
				$q['h'],
				$q['d'],
				$q['qty']
			)
		);
	}

	return $q;
}

/**
 * Mark a code used by an order.
 *
 * @param string $code     Code.
 * @param int    $order_id Order.
 */
function prime_bag_quote_mark_used( $code, $order_id ) {
	$q = prime_bag_quote_find( $code );
	if ( $q ) {
		update_post_meta( $q['id'], '_used_order_id', (int) $order_id );
	}
}

/* ------------------------------------------------------- the product page */

/**
 * Check a code against the size on the page, for the live message.
 */
function prime_bag_quote_ajax_check() {
	check_ajax_referer( 'prime_bag_quote', 'nonce' );

	$code = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
	$in   = prime_paper_bag_read( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- numbers only, range-checked inside.

	if ( ! $in['ok'] || $in['qty'] < 1 ) {
		wp_send_json_error( array( 'message' => __( 'Enter the bag size and quantity first.', 'prime-printing' ) ) );
	}

	$q = prime_bag_quote_check( $code, $in['w'], $in['h'], $in['d'], $in['qty'] );
	if ( is_wp_error( $q ) ) {
		wp_send_json_error( array( 'message' => $q->get_error_message() ) );
	}

	wp_send_json_success(
		array(
			'code'  => $q['code'],
			'price' => $q['price'],
			'text'  => wp_strip_all_tags( wc_price( $q['price'] ) ),
		)
	);
}
add_action( 'wp_ajax_prime_bag_quote_check', 'prime_bag_quote_ajax_check' );
add_action( 'wp_ajax_nopriv_prime_bag_quote_check', 'prime_bag_quote_ajax_check' );

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
		if ( ! empty( $item['prime_paper_bag_specs'] ) && empty( $item['prime_paper_bag_specs']['quote_code'] ) ) {
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
	if ( ! empty( $cart_item['prime_paper_bag_specs'] ) && empty( $cart_item['prime_paper_bag_specs']['quote_code'] ) ) {
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

/**
 * A code is still good at checkout time (it may have been used in another tab).
 */
function prime_bag_quote_check_cart() {
	foreach ( WC()->cart->get_cart() as $item ) {
		$s = $item['prime_paper_bag_specs'] ?? null;
		if ( ! $s || empty( $s['quote_code'] ) ) {
			continue;
		}
		$q = prime_bag_quote_check( $s['quote_code'], $s['width'], $s['height'], $s['depth'], $s['quantity'] );
		if ( is_wp_error( $q ) ) {
			wc_add_notice( $q->get_error_message(), 'error' );
		}
	}
}
add_action( 'woocommerce_check_cart_items', 'prime_bag_quote_check_cart' );

/**
 * Codes are single-use: tie them to the order once it exists.
 *
 * @param int $order_id Order.
 */
function prime_bag_quote_consume( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}
	foreach ( $order->get_items() as $item ) {
		$code = (string) $item->get_meta( '_prime_bag_quote_code' );
		if ( '' !== $code ) {
			prime_bag_quote_mark_used( $code, $order_id );
		}
	}
}
add_action( 'woocommerce_checkout_order_processed', 'prime_bag_quote_consume' );

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
 * The customer's account and the order list understand the status.
 *
 * @param array $actions Account order actions.
 * @return array
 */
function prime_bag_quote_account_actions( $actions ) {
	return $actions;
}
add_filter( 'woocommerce_my_account_my_orders_actions', 'prime_bag_quote_account_actions' );

/**
 * What the thank-you page says for a quote request.
 *
 * @param string   $text  Text.
 * @param WC_Order $order Order.
 * @return string
 */
function prime_bag_quote_thankyou( $text, $order ) {
	if ( prime_order_has_bag_quote_request( $order ) ) {
		return __( 'Thank you — we have your bag design and quote request. We will send your price by email and WhatsApp, usually within one working day. Nothing is charged until you approve it.', 'prime-printing' );
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
		esc_html__( 'We will send your price by email and WhatsApp, usually within one working day. Nothing is charged until you approve it.', 'prime-printing' ),
		esc_html__( 'استلمنا تصميم الكيس الورقي وطلب التسعير. سنرسل لك السعر بالبريد وواتساب خلال يوم عمل عادةً، ولن يُخصم أي مبلغ قبل موافقتك.', 'prime-printing' )
	);
	prime_bag_quote_mail( $order->get_billing_email(), sprintf( __( 'Your bag quote request — order #%s', 'prime-printing' ), $order->get_order_number() ), $customer );
}
add_action( 'woocommerce_order_status_quote-request', 'prime_bag_quote_requested_emails' );

/**
 * The quote is ready: the price, a pay link and the code.
 *
 * @param WC_Order $order Order.
 * @param array    $quote Quote record.
 */
function prime_bag_quote_send_email( $order, array $quote ) {
	$pay  = $order->get_checkout_payment_url();
	$html = sprintf(
		'<p>%s</p>%s<p style="font-size:20px"><strong>%s</strong></p><p><a href="%s" style="display:inline-block;background:#10254a;color:#fff;padding:12px 22px;text-decoration:none;border-radius:4px">%s</a></p><p>%s <strong>%s</strong><br>%s</p><p>%s</p><p>%s</p>',
		esc_html__( 'Here is the price for your paper bags:', 'prime-printing' ),
		prime_bag_quote_order_summary_html( $order ),
		wp_kses_post( wc_price( $quote['price'] ) ),
		esc_url( $pay ),
		esc_html__( 'Approve and pay', 'prime-printing' ),
		esc_html__( 'Your quote code:', 'prime-printing' ),
		esc_html( $quote['code'] ),
		esc_html__( 'You can also use this code on the product page to place the order yourself. It is valid for one order at this size and quantity.', 'prime-printing' ),
		sprintf(
			/* translators: %s: date. */
			esc_html__( 'This quote is valid until %s. Printing starts once payment is received.', 'prime-printing' ),
			esc_html( date_i18n( get_option( 'date_format' ), $quote['expires'] ) )
		),
		esc_html__( 'هذا هو سعر أكياسك الورقية. اضغط «الموافقة والدفع» لاعتماد السعر، أو استخدم رمز العرض في صفحة المنتج. تبدأ الطباعة بعد استلام الدفع.', 'prime-printing' )
	);
	prime_bag_quote_mail( $order->get_billing_email(), sprintf( __( 'Your paper bag price — order #%s', 'prime-printing' ), $order->get_order_number() ), $html );
}

/**
 * A code made by hand: email it to the customer, if there is an address.
 *
 * @param array $quote Quote record.
 */
function prime_bag_quote_send_code_email( array $quote ) {
	if ( ! $quote['email'] ) {
		return;
	}
	$sheet = prime_paper_bag_sheet( $quote['w'], $quote['h'], $quote['d'] );
	$html  = sprintf(
		'<p>%s</p><ul><li>%s</li><li>%s</li></ul><p style="font-size:20px"><strong>%s</strong></p><p>%s <strong>%s</strong></p><p>%s</p><p>%s</p>',
		esc_html__( 'Here is the price for your paper bags:', 'prime-printing' ),
		sprintf( esc_html__( 'Bag %1$s × %2$s × %3$s cm (flat sheet %4$s × %5$s cm)', 'prime-printing' ), esc_html( $quote['w'] ), esc_html( $quote['h'] ), esc_html( $quote['d'] ), esc_html( $sheet['w'] ), esc_html( $sheet['h'] ) ),
		sprintf( esc_html__( '%s bags', 'prime-printing' ), esc_html( $quote['qty'] ) ),
		wp_kses_post( wc_price( $quote['price'] ) ),
		esc_html__( 'Your quote code:', 'prime-printing' ),
		esc_html( $quote['code'] ),
		sprintf(
			/* translators: 1: product link, 2: date. */
			esc_html__( 'Open the custom paper bag page, enter this size and quantity, design your bag, and type the code to order at this price. Valid until %2$s.', 'prime-printing' ),
			'',
			esc_html( date_i18n( get_option( 'date_format' ), $quote['expires'] ) )
		),
		esc_html__( 'افتح صفحة الكيس الورقي المخصص، أدخل المقاس والكمية، صمّم كيسك، واكتب رمز العرض لتطلب بهذا السعر.', 'prime-printing' )
	);
	prime_bag_quote_mail( $quote['email'], __( 'Your paper bag quote', 'prime-printing' ), $html );
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

	if ( is_array( $sent ) && ! empty( $sent['code'] ) ) {
		printf(
			'<p><strong>%s</strong> %s<br><strong>%s</strong> <code>%s</code><br><strong>%s</strong> %s</p>',
			esc_html__( 'Quoted:', 'prime-printing' ),
			wp_kses_post( wc_price( $sent['price'] ) ),
			esc_html__( 'Code:', 'prime-printing' ),
			esc_html( $sent['code'] ),
			esc_html__( 'Sent:', 'prime-printing' ),
			esc_html( date_i18n( get_option( 'date_format' ) . ' H:i', (int) $sent['sent_at'] ) )
		);
		if ( $order->needs_payment() ) {
			printf( '<p><a href="%s" target="_blank" rel="noopener">%s</a></p>', esc_url( $order->get_checkout_payment_url() ), esc_html__( 'Customer pay link', 'prime-printing' ) );
		}
	} elseif ( ! prime_order_has_bag_quote_request( $order ) ) {
		echo '<p>' . esc_html__( 'Ordered with a quote code; already priced.', 'prime-printing' ) . '</p>';
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
				<?php echo is_array( $sent ) && ! empty( $sent['code'] ) ? esc_html__( 'Resend quote', 'prime-printing' ) : esc_html__( 'Send quote to customer', 'prime-printing' ); ?>
			</button>
		</p>
		<p class="description"><?php esc_html_e( 'Prices the order, sets it to Pending payment and emails the customer a pay link and a quote code.', 'prime-printing' ); ?></p>
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
	$specs = null;
	foreach ( $order->get_items() as $item ) {
		$s = $item->get_meta( '_prime_paper_bag_specs_raw' );
		if ( ! is_array( $s ) ) {
			continue;
		}
		$specs = $s;
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

	$existing = $order->get_meta( '_prime_bag_quote' );
	$quote    = is_array( $existing ) && ! empty( $existing['code'] ) ? prime_bag_quote_find( $existing['code'] ) : null;
	if ( $quote && ! $quote['used_order_id'] ) {
		update_post_meta( $quote['id'], '_price', $price );
		$quote['price'] = $price;
	} else {
		$quote = prime_bag_quote_create(
			array(
				'price'    => $price,
				'w'        => $specs['width'],
				'h'        => $specs['height'],
				'd'        => $specs['depth'],
				'qty'      => $specs['quantity'],
				'name'     => $order->get_formatted_billing_full_name(),
				'email'    => $order->get_billing_email(),
				'phone'    => $order->get_billing_phone(),
				'note'     => sprintf( 'Order #%s', $order->get_order_number() ),
				'order_id' => $order->get_id(),
			)
		);
		if ( is_wp_error( $quote ) ) {
			wp_die( esc_html( $quote->get_error_message() ) );
		}
	}

	$order->update_meta_data( '_prime_bag_quote', array( 'price' => $price, 'code' => $quote['code'], 'sent_at' => time() ) );
	$order->add_order_note( sprintf( 'Quote sent: %s KWD, code %s.', number_format( $price, 3 ), $quote['code'] ) );
	if ( ! $order->is_paid() ) {
		$order->set_status( 'pending', 'Quote sent, awaiting approval and payment.' );
	}
	$order->save();

	prime_bag_quote_send_email( $order, $quote );

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
	if ( ! empty( $_GET['prime_quote_created'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="notice notice-success is-dismissible"><p>' . sprintf( esc_html__( 'Quote code %s created.', 'prime-printing' ), '<code>' . esc_html( sanitize_text_field( wp_unslash( $_GET['prime_quote_created'] ) ) ) . '</code>' ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}
}
add_action( 'admin_notices', 'prime_bag_quote_admin_notice' );

/* ------------------------------------------------- admin: the quotes page */

/**
 * WooCommerce → Bag quotes.
 */
function prime_bag_quote_menu() {
	add_submenu_page( 'woocommerce', __( 'Bag quotes', 'prime-printing' ), __( 'Bag quotes', 'prime-printing' ), 'edit_shop_orders', 'prime-bag-quotes', 'prime_bag_quote_render_page' );
}
add_action( 'admin_menu', 'prime_bag_quote_menu', 60 );

/**
 * The page: make a code by hand, and the list of every code.
 */
function prime_bag_quote_render_page() {
	$lim = prime_paper_bag_limits();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Paper bag quotes', 'prime-printing' ); ?></h1>
		<p><?php esc_html_e( 'A quote code carries a price for one exact bag size and quantity. The customer types it on the product page to order at that price; each code works once. Codes from quote-request orders are made for you when you send the quote from the order.', 'prime-printing' ); ?></p>

		<h2><?php esc_html_e( 'New quote code', 'prime-printing' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:720px">
			<input type="hidden" name="action" value="prime_bag_quote_create">
			<?php wp_nonce_field( 'prime_bag_quote_create' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Bag size (cm)', 'prime-printing' ); ?></th>
					<td>
						<input type="number" name="w" step="0.1" min="<?php echo esc_attr( $lim['w'][0] ); ?>" max="<?php echo esc_attr( $lim['w'][1] ); ?>" placeholder="<?php esc_attr_e( 'Width', 'prime-printing' ); ?>" required style="width:7em"> ×
						<input type="number" name="h" step="0.1" min="<?php echo esc_attr( $lim['h'][0] ); ?>" max="<?php echo esc_attr( $lim['h'][1] ); ?>" placeholder="<?php esc_attr_e( 'Height', 'prime-printing' ); ?>" required style="width:7em"> ×
						<input type="number" name="d" step="0.1" min="<?php echo esc_attr( $lim['d'][0] ); ?>" max="<?php echo esc_attr( $lim['d'][1] ); ?>" placeholder="<?php esc_attr_e( 'Depth', 'prime-printing' ); ?>" required style="width:7em">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="pbq-qty"><?php esc_html_e( 'Quantity (bags)', 'prime-printing' ); ?></label></th>
					<td><input type="number" id="pbq-qty" name="qty" min="1" step="1" required style="width:7em"></td>
				</tr>
				<tr>
					<th scope="row"><label for="pbq-price"><?php esc_html_e( 'Total price incl. delivery (KWD)', 'prime-printing' ); ?></label></th>
					<td><input type="number" id="pbq-price" name="price" min="0.001" step="0.001" required style="width:9em"></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Customer', 'prime-printing' ); ?></th>
					<td>
						<input type="text" name="name" placeholder="<?php esc_attr_e( 'Name', 'prime-printing' ); ?>" style="width:12em">
						<input type="email" name="email" placeholder="<?php esc_attr_e( 'Email (the code is emailed if given)', 'prime-printing' ); ?>" style="width:20em">
						<input type="text" name="phone" placeholder="+965" style="width:9em">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="pbq-note"><?php esc_html_e( 'Note (internal)', 'prime-printing' ); ?></label></th>
					<td><input type="text" id="pbq-note" name="note" style="width:100%"></td>
				</tr>
			</table>
			<?php submit_button( __( 'Create code', 'prime-printing' ) ); ?>
		</form>

		<h2><?php esc_html_e( 'All quote codes', 'prime-printing' ); ?></h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Code', 'prime-printing' ); ?></th>
					<th><?php esc_html_e( 'Bag', 'prime-printing' ); ?></th>
					<th><?php esc_html_e( 'Bags', 'prime-printing' ); ?></th>
					<th><?php esc_html_e( 'Price', 'prime-printing' ); ?></th>
					<th><?php esc_html_e( 'Customer', 'prime-printing' ); ?></th>
					<th><?php esc_html_e( 'From order', 'prime-printing' ); ?></th>
					<th><?php esc_html_e( 'Status', 'prime-printing' ); ?></th>
					<th><?php esc_html_e( 'Valid until', 'prime-printing' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php $quotes = prime_bag_quote_all(); ?>
				<?php if ( ! $quotes ) : ?>
					<tr><td colspan="8"><?php esc_html_e( 'No quotes yet.', 'prime-printing' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $quotes as $q ) : ?>
					<?php
					$order_link = static function ( $id ) {
						$o = $id ? wc_get_order( $id ) : null;
						return $o ? '<a href="' . esc_url( $o->get_edit_order_url() ) . '">#' . esc_html( $o->get_order_number() ) . '</a>' : '—';
					};
					if ( $q['used_order_id'] ) {
						$status = sprintf( esc_html__( 'Used on %s', 'prime-printing' ), $order_link( $q['used_order_id'] ) );
					} elseif ( $q['expires'] < time() ) {
						$status = esc_html__( 'Expired', 'prime-printing' );
					} else {
						$status = esc_html__( 'Open', 'prime-printing' );
					}
					?>
					<tr>
						<td><code><?php echo esc_html( $q['code'] ); ?></code></td>
						<td><?php echo esc_html( sprintf( '%s × %s × %s cm', $q['w'], $q['h'], $q['d'] ) ); ?></td>
						<td><?php echo esc_html( $q['qty'] ); ?></td>
						<td><?php echo wp_kses_post( wc_price( $q['price'] ) ); ?></td>
						<td><?php echo esc_html( trim( $q['name'] . ' ' . $q['email'] . ' ' . $q['phone'] ) ); ?><?php echo $q['note'] ? '<br><small>' . esc_html( $q['note'] ) . '</small>' : ''; ?></td>
						<td><?php echo wp_kses_post( $order_link( $q['order_id'] ) ); ?></td>
						<td><?php echo wp_kses_post( $status ); ?></td>
						<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), $q['expires'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}

/**
 * Make a code by hand.
 */
function prime_bag_quote_handle_create() {
	check_admin_referer( 'prime_bag_quote_create' );
	if ( ! current_user_can( 'edit_shop_orders' ) ) {
		wp_die( esc_html__( 'Not allowed.', 'prime-printing' ) );
	}

	$in = prime_paper_bag_read(
		array(
			'bag_width'    => $_POST['w'] ?? 0, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- numeric, range-checked.
			'bag_height'   => $_POST['h'] ?? 0, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			'bag_depth'    => $_POST['d'] ?? 0, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			'bag_quantity' => $_POST['qty'] ?? 0, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		)
	);
	$price = isset( $_POST['price'] ) ? round( (float) wp_unslash( $_POST['price'] ), 3 ) : 0;
	if ( ! $in['ok'] || $in['qty'] < 1 || $price <= 0 ) {
		wp_die( esc_html__( 'Enter a bag size within the limits, a quantity and a price.', 'prime-printing' ) );
	}

	$quote = prime_bag_quote_create(
		array(
			'price' => $price,
			'w'     => $in['w'],
			'h'     => $in['h'],
			'd'     => $in['d'],
			'qty'   => $in['qty'],
			'name'  => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			'email' => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
			'phone' => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
			'note'  => sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) ),
		)
	);
	if ( is_wp_error( $quote ) ) {
		wp_die( esc_html( $quote->get_error_message() ) );
	}
	prime_bag_quote_send_code_email( $quote );

	wp_safe_redirect( add_query_arg( array( 'page' => 'prime-bag-quotes', 'prime_quote_created' => $quote['code'] ), admin_url( 'admin.php' ) ) );
	exit;
}
add_action( 'admin_post_prime_bag_quote_create', 'prime_bag_quote_handle_create' );
