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
