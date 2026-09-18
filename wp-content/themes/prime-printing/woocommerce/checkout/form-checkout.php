<?php
/**
 * Checkout — page shell.
 *
 * Overrides WooCommerce's own checkout/form-checkout.php. Keeps the standard
 * structure and hooks (login form, coupon form, the billing/shipping/review/
 * payment fragments each still resolve through their own template files, so
 * plugin compatibility is unaffected) — only the wrapping chrome around them
 * matches the reference: a sticky compact header instead of the theme's usual
 * one, and a step progress bar.
 *
 * @package PrimePrinting
 * @version 9.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

do_action( 'woocommerce_before_checkout_form', $checkout );

// A customer with nothing to check out is sent back to the cart rather than
// shown a payment form with nothing to pay for.
if ( ! WC()->cart || WC()->cart->is_empty() ) {
	echo '<div class="prime-checkout-page">';
	wc_print_notice( __( 'Your cart is currently empty.', 'prime-printing' ), 'notice' );
	echo '</div>';
	return;
}
?>

<div class="prime-checkout-head">
	<div class="prime-checkout-head__in">
		<a class="prime-back" href="<?php echo esc_url( wc_get_cart_url() ); ?>" aria-label="<?php esc_attr_e( 'Back to cart', 'prime-printing' ); ?>">←</a>
		<h1><?php esc_html_e( 'Checkout', 'prime-printing' ); ?></h1>
		<?php prime_language_switcher( 'button' ); ?>
	</div>
</div>

<div class="prime-checkout-page">
	<div class="prime-steps" aria-hidden="true">
		<div class="prime-steps__bar is-on"></div>
		<div class="prime-steps__bar is-on"></div>
		<div class="prime-steps__bar" id="prime-step-3"></div>
	</div>

	<form name="checkout" method="post" class="checkout woocommerce-checkout" action="<?php echo esc_url( wc_get_checkout_url() ); ?>" enctype="multipart/form-data">

		<?php if ( $checkout->get_checkout_fields() ) : ?>

			<?php do_action( 'woocommerce_checkout_before_customer_details' ); ?>

			<div class="col2-set" id="customer_details">
				<div class="col-1">
					<?php do_action( 'woocommerce_checkout_billing' ); ?>
				</div>
			</div>

			<?php do_action( 'woocommerce_checkout_after_customer_details' ); ?>

		<?php endif; ?>

		<div class="prime-sec">
			<div class="prime-sech">
				<h2><?php prime_mark(); ?><span><?php esc_html_e( 'Your order', 'prime-printing' ); ?></span></h2>
				<div class="prime-sech__line"></div>
			</div>

			<?php do_action( 'woocommerce_checkout_before_order_review_heading' ); ?>
			<?php do_action( 'woocommerce_checkout_before_order_review' ); ?>

			<div id="order_review" class="woocommerce-checkout-review-order">
				<?php do_action( 'woocommerce_checkout_order_review' ); ?>
			</div>

			<?php do_action( 'woocommerce_checkout_after_order_review' ); ?>
		</div>

	</form>

	<?php do_action( 'woocommerce_after_checkout_form', $checkout ); ?>
</div>
