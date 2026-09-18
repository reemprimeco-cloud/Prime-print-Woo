<?php
/**
 * Checkout — payment method tiles.
 *
 * Overrides WooCommerce's own checkout/payment.php. Keeps the terms checkbox
 * and "Place order" button exactly as WooCommerce renders them (any plugin
 * hooking woocommerce_review_order_before_submit /
 * woocommerce_checkout_terms_and_conditions still fires normally) — only the
 * gateway list itself is replaced, with prime_render_payment_tiles() in
 * inc/checkout-payment.php.
 *
 * @package PrimePrinting
 * @version 9.4.0
 *
 * @global WC_Checkout $checkout
 */

defined( 'ABSPATH' ) || exit;

if ( ! WC()->cart->needs_payment() ) {
	return;
}
?>

<div class="prime-sec" id="payment">
	<div class="prime-sech" id="prime-h3">
		<h2><?php prime_mark(); ?><span><?php esc_html_e( 'Payment method', 'prime-printing' ); ?></span></h2>
		<span class="prime-sech__ok" aria-hidden="true">✓</span>
		<div class="prime-sech__line"></div>
	</div>

	<?php prime_render_payment_tiles(); ?>

	<div class="form-row place-order">
		<noscript>
			<?php
			printf(
				/* translators: %s: order button text. */
				esc_html__( 'Since your browser does not support JavaScript, or it is disabled, please ensure you click the %s button below to update the totals on this order before placing your order. You may need to refresh this page or open it in a new browser tab.', 'prime-printing' ),
				esc_html( apply_filters( 'woocommerce_order_button_text', __( 'Place order', 'woocommerce' ) ) )
			);
			?>
		</noscript>

		<?php wc_get_template( 'checkout/terms.php' ); ?>

		<?php do_action( 'woocommerce_review_order_before_submit' ); ?>

		<?php echo apply_filters( 'woocommerce_order_button_html', '<button type="submit" class="prime-pay" id="place_order" name="woocommerce_checkout_place_order" value="' . esc_attr( apply_filters( 'woocommerce_order_button_text', __( 'Place order', 'woocommerce' ) ) ) . '" data-value="' . esc_attr( apply_filters( 'woocommerce_order_button_text', __( 'Place order', 'woocommerce' ) ) ) . '"><span>' . esc_html( apply_filters( 'woocommerce_order_button_text', __( 'Place order', 'woocommerce' ) ) ) . '</span><span class="prime-pay__amt">' . wp_kses_post( WC()->cart->get_total() ) . '</span></button>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- filtered value is escaped inline above. ?>

		<?php do_action( 'woocommerce_review_order_after_submit' ); ?>

		<?php wp_nonce_field( 'woocommerce-process_checkout', 'woocommerce-process-checkout-nonce' ); ?>
	</div>
</div>
