<?php
/**
 * Local pickup — a real shipping option (there wasn't one before this: the
 * only shipping method on the site was Phase 5's governorate delivery), plus
 * the pickup-location map shown on the order confirmation page when a
 * customer chooses it.
 *
 * Uses WooCommerce's own built-in `local_pickup` method (registered by core,
 * not custom code here) rather than a bespoke WC_Shipping_Method the way
 * checkout-shipping.php's governorate rate is — pickup doesn't need per-area
 * rate logic, so there's nothing a custom class would add over the built-in
 * one.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

/**
 * Add Local pickup to the Kuwait zone, the same one-time, idempotent way
 * checkout-shipping.php adds the governorate method — see that file's
 * prime_ensure_shipping_method_enabled() for why this can't be
 * after_switch_theme instead.
 */
function prime_ensure_pickup_method_enabled() {
	if ( get_option( 'prime_pickup_method_ensured' ) ) {
		return;
	}

	if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
		return;
	}

	$zones = WC_Shipping_Zones::get_zones();

	if ( empty( $zones ) ) {
		// The governorate method's own init runs at the same priority; if it
		// hasn't created the Kuwait zone yet this request, there's nothing to
		// add the pickup method to yet — try again next request rather than
		// creating a second, duplicate zone.
		return;
	}

	$zone_id = reset( $zones )['id'];
	$zone    = new WC_Shipping_Zone( $zone_id );

	$has_method = false;

	foreach ( $zone->get_shipping_methods() as $method ) {
		if ( 'local_pickup' === $method->id ) {
			$has_method = true;
			break;
		}
	}

	if ( ! $has_method ) {
		$instance_id = $zone->add_shipping_method( 'local_pickup' );

		// Free by default and titled to match the reference's "Pickup" tile —
		// both editable afterwards from WooCommerce → Settings → Shipping →
		// Kuwait → Local pickup, same as any other zone method.
		if ( $instance_id ) {
			$method_settings = get_option( "woocommerce_local_pickup_{$instance_id}_settings", array() );
			$method_settings['title'] = __( 'Pickup', 'prime-printing' );
			$method_settings['cost']  = '0';
			update_option( "woocommerce_local_pickup_{$instance_id}_settings", $method_settings );
		}
	}

	update_option( 'prime_pickup_method_ensured', 1 );
}
add_action( 'woocommerce_init', 'prime_ensure_pickup_method_enabled', 21 );

/**
 * Whether an order's shipping is local pickup.
 *
 * @param WC_Order $order Order.
 * @return bool
 */
function prime_order_is_pickup( WC_Order $order ) {
	foreach ( $order->get_shipping_methods() as $shipping_item ) {
		/** @var WC_Order_Item_Shipping $shipping_item */
		if ( 'local_pickup' === $shipping_item->get_method_id() ) {
			return true;
		}
	}

	return false;
}

/**
 * The pickup-location block on the order confirmation ("thank you") page.
 *
 * Hooked to woocommerce_thankyou rather than overriding thankyou.php
 * wholesale — the default template is otherwise fine, this just adds one
 * block to it, the same "hook into WooCommerce rather than fork its
 * templates" approach as the rest of this checkout build.
 *
 * @param int $order_id Order ID.
 */
function prime_thankyou_pickup_location( $order_id ) {
	$order = wc_get_order( $order_id );

	if ( ! $order instanceof WC_Order || ! prime_order_is_pickup( $order ) ) {
		return;
	}

	$map_defaults   = prime_default_pickup_map();
	$embed_url      = get_theme_mod( 'prime_contact_map_embed', $map_defaults['embed'] );
	$directions_url = get_theme_mod( 'prime_contact_map_directions', $map_defaults['directions'] );
	$address        = get_theme_mod( 'prime_contact_address', prime_default_company_address() );

	if ( ! $embed_url ) {
		return;
	}
	?>
	<section class="prime-pickup-location">
		<h2><?php esc_html_e( 'Pick up your order here', 'prime-printing' ); ?></h2>
		<p class="prime-pickup-address"><?php echo nl2br( esc_html( $address ) ); ?></p>
		<div class="prime-pickup-map">
			<iframe
				src="<?php echo esc_url( $embed_url ); ?>"
				width="600"
				height="450"
				style="border:0;"
				allowfullscreen
				loading="lazy"
				referrerpolicy="strict-origin-when-cross-origin"
				title="<?php esc_attr_e( 'Prime Printing Co. — pickup location', 'prime-printing' ); ?>"
			></iframe>
		</div>
		<?php if ( $directions_url ) : ?>
			<p>
				<a class="button" href="<?php echo esc_url( $directions_url ); ?>" target="_blank" rel="noopener">
					<?php esc_html_e( 'Get directions', 'prime-printing' ); ?>
				</a>
			</p>
		<?php endif; ?>
	</section>
	<?php
}
add_action( 'woocommerce_thankyou', 'prime_thankyou_pickup_location', 20 );
