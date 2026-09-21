<?php
/**
 * Payment method tiles, and the cash-on-delivery surcharge.
 *
 * The reference's six tiles (KNET, Visa/Master, COD, Apple Pay·KNET, Apple
 * Pay·Card, Google Pay) are MyFatoorah's payment methods, and MyFatoorah is
 * Phase 9 — not installed yet. Rather than hard-coding those six and hoping
 * the real plugin's gateway IDs match later, this renders whatever payment
 * gateways are ACTUALLY active as tiles, with the reference's icon/label
 * treatment applied by gateway ID where one is known. An unrecognised gateway
 * still renders — with its own title and a generic icon — rather than
 * silently vanishing, so enabling a gateway in WooCommerce settings is always
 * enough to make it appear here.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

/**
 * Known-gateway display overrides.
 *
 * Placeholder MyFatoorah method IDs — Phase 9 confirms the real ones from the
 * live plugin config and this map is updated to match; nothing else about the
 * tile rendering changes when that happens.
 *
 * @return array<string, array{label: string, icon: string}>
 */
function prime_payment_tile_map() {
	return array(
		'cod'                  => array( 'label' => __( 'Cash on delivery', 'prime-printing' ), 'icon' => '◫' ),
		'myfatoorah_knet'      => array( 'label' => __( 'KNET', 'prime-printing' ), 'icon' => '▭' ),
		'myfatoorah_visa_mastercard' => array( 'label' => __( 'Visa / Mastercard', 'prime-printing' ), 'icon' => '▤' ),
		'myfatoorah_applepay'  => array( 'label' => __( 'Apple Pay', 'prime-printing' ), 'icon' => '' ),
		'myfatoorah_googlepay' => array( 'label' => __( 'Google Pay', 'prime-printing' ), 'icon' => 'G' ),
	);
}

/**
 * Render available gateways as tiles instead of WooCommerce's default radio
 * list. Overrides woocommerce/checkout/payment.php's method-list markup only;
 * the surrounding "Place order" button and payment box are untouched, so
 * gateways' own `payment_fields()` output (card fields, redirects) still
 * renders exactly as that gateway expects.
 */
function prime_render_payment_tiles() {
	$gateways = WC()->payment_gateways()->get_available_payment_gateways();

	if ( ! $gateways ) {
		echo '<p>' . esc_html__( 'No payment methods are available. Please contact us to place your order.', 'prime-printing' ) . '</p>';
		return;
	}

	$map     = prime_payment_tile_map();
	$chosen  = WC()->session ? WC()->session->get( 'chosen_payment_method' ) : '';
	$chosen  = $chosen ? $chosen : key( $gateways );
	?>
	<div class="prime-tiles" id="prime-payment-tiles" role="radiogroup" aria-label="<?php esc_attr_e( 'Payment method', 'prime-printing' ); ?>">
		<?php foreach ( $gateways as $gateway ) : ?>
			<?php $meta = isset( $map[ $gateway->id ] ) ? $map[ $gateway->id ] : array( 'label' => $gateway->get_title(), 'icon' => '○' ); ?>
			<label class="prime-tile <?php echo $gateway->id === $chosen ? 'is-on' : ''; ?>" data-prime-payment-tile>
				<input
					type="radio"
					name="payment_method"
					value="<?php echo esc_attr( $gateway->id ); ?>"
					class="screen-reader-text"
					<?php checked( $gateway->id, $chosen ); ?>
				>
				<span class="prime-tile__icon" aria-hidden="true"><?php echo esc_html( $meta['icon'] ); ?></span>
				<span class="prime-tile__label"><?php echo esc_html( $meta['label'] ); ?></span>
			</label>
		<?php endforeach; ?>
	</div>

	<?php foreach ( $gateways as $gateway ) : ?>
		<div class="prime-payment-box <?php echo $gateway->id === $chosen ? 'is-on' : ''; ?>" data-prime-payment-box="<?php echo esc_attr( $gateway->id ); ?>">
			<?php if ( $gateway->has_fields() || $gateway->get_description() ) : ?>
				<div class="payment_box payment_method_<?php echo esc_attr( $gateway->id ); ?>">
					<?php $gateway->payment_fields(); ?>
				</div>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>
	<?php
}

/**
 * A COD surcharge, matching the reference's 0.500 KWD "cash on delivery fee"
 * row — real businesses commonly pass on the courier's cash-handling cost.
 * Configurable via a filter rather than another settings screen, since it is
 * one number that rarely changes.
 *
 * @param WC_Cart $cart Current cart.
 */
function prime_cod_surcharge( $cart ) {
	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
		return;
	}

	$chosen = WC()->session ? WC()->session->get( 'chosen_payment_method' ) : '';

	if ( 'cod' !== $chosen ) {
		return;
	}

	$fee = (float) apply_filters( 'prime_cod_surcharge_amount', 0.500 );

	if ( $fee > 0 ) {
		$cart->add_fee( __( 'Cash on delivery fee', 'prime-printing' ), $fee, false );
	}
}
add_action( 'woocommerce_cart_calculate_fees', 'prime_cod_surcharge' );

/**
 * Keep Cash on Delivery disabled.
 *
 * COD was enabled by default early on so checkout had at least one working
 * payment method before Tap Payments was live — Tap is the real gateway now,
 * so COD stays off. Kept as a self-healing check (rather than a one-off
 * settings change) since the option persists in the database regardless of
 * this code, and would otherwise silently reappear if anyone flips it back
 * on for local testing.
 */
function prime_disable_cod_gateway() {
	$settings = get_option( 'woocommerce_cod_settings', array() );

	if ( ! isset( $settings['enabled'] ) || 'no' === $settings['enabled'] ) {
		return;
	}

	$settings['enabled'] = 'no';

	update_option( 'woocommerce_cod_settings', $settings );
}
add_action( 'woocommerce_init', 'prime_disable_cod_gateway' );

/**
 * Does this order have anything to make and deliver?
 *
 * The same test WooCommerce core uses to decide whether a paid order still
 * needs a human: an order of only virtual, downloadable items is finished the
 * moment it is paid; anything else has to be printed and sent.
 *
 * @param WC_Order $order Order.
 * @return bool
 */
function prime_order_needs_fulfilment( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return false;
	}

	foreach ( $order->get_items() as $item ) {
		$product = $item instanceof WC_Order_Item_Product ? $item->get_product() : null;

		if ( ! $product || ! $product->is_virtual() || ! $product->is_downloadable() ) {
			return true;
		}
	}

	return false;
}

/**
 * A paid order becomes Processing, never Completed.
 *
 * Reem, 2026-09-21: "why the new orders comes showing completed? it must be
 * processing once they place it. then once its ready i click complete." Paid
 * orders were arriving already Completed, so nothing was left on the shop's
 * queue and the customer was told their order had shipped before it had been
 * printed.
 *
 * WooCommerce's own default here is already 'processing' for a physical order,
 * so this is not overriding core — it is overriding a gateway that asks for
 * 'completed' on payment (the UPayments plugin's own setting, which this does
 * not depend on being found and changed). Where the order genuinely has
 * nothing to fulfil, core's answer is left alone.
 *
 * @param string   $status   Status the gateway asked for.
 * @param int      $order_id Order ID.
 * @param WC_Order $order    Order.
 * @return string
 */
function prime_paid_order_status( $status, $order_id, $order = null ) {
	$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );

	return prime_order_needs_fulfilment( $order ) ? 'processing' : $status;
}
add_filter( 'woocommerce_payment_complete_order_status', 'prime_paid_order_status', 20, 3 );

/**
 * The same rule, for a gateway that sets the status itself.
 *
 * Not every gateway goes through payment_complete() — some call
 * `$order->update_status( 'completed' )` from their payment callback, which no
 * filter above can reach. What gives that away is the jump: an order going
 * straight from unpaid to Completed without ever passing through Processing,
 * with nobody signed in who could have clicked it.
 *
 * That last part is what keeps this out of Reem's way. When she marks an order
 * Completed herself — in wp-admin or from the WooCommerce app — she is signed
 * in and able to edit orders, so her click is left exactly as she made it.
 *
 * @param int      $order_id Order ID.
 * @param string   $from     Previous status.
 * @param string   $to       New status.
 * @param WC_Order $order    Order.
 */
function prime_keep_paid_orders_in_processing( $order_id, $from, $to, $order = null ) {
	if ( 'completed' !== $to || ! in_array( $from, array( 'pending', 'failed', 'on-hold', 'cancelled' ), true ) ) {
		return;
	}

	if ( current_user_can( 'edit_shop_orders' ) ) {
		return;
	}

	$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );

	if ( ! prime_order_needs_fulfilment( $order ) ) {
		return;
	}

	$order->update_status( 'processing', __( 'Payment received. Held in Processing until the order is printed and ready.', 'prime-printing' ) );
}
add_action( 'woocommerce_order_status_changed', 'prime_keep_paid_orders_in_processing', 20, 4 );
