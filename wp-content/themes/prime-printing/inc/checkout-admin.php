<?php
/**
 * Admin order screen — surfaces the checkout data that has nowhere else to
 * show up: the delivery pin, the address type, and (for gift orders) who the
 * parcel actually goes to versus who paid for it.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render the extra block, near the shipping address on the order edit screen.
 *
 * @param WC_Order $order Order being viewed.
 */
function prime_admin_order_delivery_details( $order ) {
	$maps_link    = $order->get_meta( '_delivery_maps_link' );
	$address_type = $order->get_meta( '_prime_address_type' );
	$notes        = $order->get_meta( '_prime_address_notes' );

	if ( ! $maps_link && ! $address_type && ! $notes ) {
		return;
	}

	$type_labels = prime_address_types();
	?>
	<div class="prime-admin-delivery order_data_column" style="clear:both; padding-top:12px;">
		<h4><?php esc_html_e( 'Delivery details', 'prime-printing' ); ?></h4>

		<?php if ( $address_type ) : ?>
			<p>
				<strong><?php esc_html_e( 'Address type:', 'prime-printing' ); ?></strong>
				<?php echo esc_html( $type_labels[ $address_type ] ?? $address_type ); ?>
			</p>
		<?php endif; ?>

		<?php if ( 'gift' === $address_type ) : ?>
			<p>
				<strong><?php esc_html_e( 'Recipient:', 'prime-printing' ); ?></strong>
				<?php echo esc_html( $order->get_meta( '_prime_gift_name' ) ); ?>
				·
				<a href="tel:+965<?php echo esc_attr( $order->get_meta( '_prime_gift_phone' ) ); ?>">
					<?php echo esc_html( $order->get_meta( '_prime_gift_phone' ) ); ?>
				</a>
			</p>
			<?php if ( $order->get_meta( '_prime_gift_note' ) ) : ?>
				<p><strong><?php esc_html_e( 'Gift note:', 'prime-printing' ); ?></strong> <?php echo esc_html( $order->get_meta( '_prime_gift_note' ) ); ?></p>
			<?php endif; ?>
			<?php
			/*
			 * A gift order's street address is optional (Reem, 2026-09-05:
			 * "use the same field address but optional not forced only to
			 * show them the price") — the buyer may have typed one in just to
			 * see an estimated delivery fee. It is never confirmed, so it's
			 * shown here as a hint for whoever calls the recipient, not as
			 * something to dispatch from directly.
			 */
			$gift_governorate   = prime_governorate( $order->get_billing_state() );
			$gift_address_parts = array_filter(
				array(
					$order->get_meta( '_prime_block' ),
					$order->get_meta( '_prime_street' ),
					$order->get_meta( '_prime_house_no' ),
					$order->get_meta( '_billing_area' ),
					$gift_governorate ? $gift_governorate['name'] : '',
				)
			);
			?>
			<?php if ( $gift_address_parts ) : ?>
				<p>
					<strong><?php esc_html_e( 'Address given by buyer (unconfirmed):', 'prime-printing' ); ?></strong>
					<?php echo esc_html( implode( ', ', $gift_address_parts ) ); ?>
				</p>
				<p class="description"><?php esc_html_e( 'Given only to estimate the delivery fee at checkout — still confirm the exact address with the recipient before dispatch.', 'prime-printing' ); ?></p>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'No address was given at checkout — contact the recipient above for delivery details.', 'prime-printing' ); ?></p>
			<?php endif; ?>
		<?php endif; ?>

		<?php if ( $notes ) : ?>
			<p><strong><?php esc_html_e( 'Directions:', 'prime-printing' ); ?></strong> <?php echo esc_html( $notes ); ?></p>
		<?php endif; ?>

		<?php if ( $maps_link ) : ?>
			<p>
				<a class="button" href="<?php echo esc_url( $maps_link ); ?>" target="_blank" rel="noopener">
					📍 <?php esc_html_e( 'Open in Google Maps', 'prime-printing' ); ?>
				</a>
			</p>
		<?php endif; ?>
	</div>
	<?php
}
add_action( 'woocommerce_admin_order_data_after_shipping_address', 'prime_admin_order_delivery_details' );
