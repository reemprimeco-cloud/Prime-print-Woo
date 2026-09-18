<?php
/**
 * My Account → Orders — card layout matching prime-printing-account.html,
 * replacing WooCommerce's default plain table so line items (and their
 * Phase 4 specs) can expand inline, per order.
 *
 * Overrides woocommerce/templates/myaccount/orders.php. Variables ($current_page,
 * $customer_orders, $has_orders, $wp_button_class) come from
 * woocommerce_account_orders() in WC core — see that function for the exact
 * query.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_account_orders', $has_orders );
?>

<?php if ( $has_orders ) : ?>

	<div class="prime-order-filters" role="group" aria-label="<?php esc_attr_e( 'Filter orders by status', 'prime-printing' ); ?>">
		<button type="button" class="prime-order-filter is-active" data-prime-order-filter="all"><?php esc_html_e( 'All', 'prime-printing' ); ?></button>
		<button type="button" class="prime-order-filter" data-prime-order-filter="processing"><?php esc_html_e( 'Processing', 'prime-printing' ); ?></button>
		<button type="button" class="prime-order-filter" data-prime-order-filter="delivered"><?php esc_html_e( 'Delivered', 'prime-printing' ); ?></button>
		<button type="button" class="prime-order-filter" data-prime-order-filter="awaiting-quote"><?php esc_html_e( 'Awaiting quote', 'prime-printing' ); ?></button>
	</div>

	<div class="prime-orders">
		<?php foreach ( $customer_orders->orders as $customer_order ) :
			$order       = wc_get_order( $customer_order );
			$status_slug = $order->get_status();
			$filter_group = prime_order_filter_group( $status_slug );
			?>
			<article class="prime-order" data-prime-order-status="<?php echo esc_attr( $filter_group ); ?>">
				<button type="button" class="prime-order__summary" data-prime-order-toggle aria-expanded="false">
					<span class="prime-order__number">
						<?php
						printf(
							/* translators: %s: order number */
							esc_html__( 'Order #%s', 'prime-printing' ),
							esc_html( $order->get_order_number() )
						);
						?>
					</span>
					<time class="prime-order__date" datetime="<?php echo esc_attr( $order->get_date_created()->date( 'c' ) ); ?>">
						<?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?>
					</time>
					<span class="prime-order__status prime-order__status--<?php echo esc_attr( $filter_group ); ?>">
						<?php echo esc_html( wc_get_order_status_name( $status_slug ) ); ?>
					</span>
					<span class="prime-order__total"><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></span>
					<span class="prime-order__chevron" aria-hidden="true"></span>
				</button>

				<div class="prime-order__body">
					<ul class="prime-order__items">
						<?php foreach ( $order->get_items() as $item ) :
							/** @var WC_Order_Item_Product $item */
							$product = $item->get_product();
							$specs   = $item->get_formatted_meta_data( '_' );
							?>
							<li class="prime-order__item">
								<?php if ( $product ) : ?>
									<span class="prime-order__item-thumb"><?php echo wp_kses_post( $product->get_image( 'thumbnail' ) ); ?></span>
								<?php endif; ?>
								<span class="prime-order__item-info">
									<span class="prime-order__item-name"><?php echo esc_html( $item->get_name() ); ?></span>
									<?php foreach ( $specs as $spec ) : ?>
										<span class="prime-order__item-spec">
											<?php echo esc_html( wp_strip_all_tags( $spec->display_key ) ); ?>: <?php echo wp_kses_post( $spec->display_value ); ?>
										</span>
									<?php endforeach; ?>
								</span>
								<span class="prime-order__item-qty">&times;<?php echo esc_html( $item->get_quantity() ); ?></span>
								<span class="prime-order__item-price"><?php echo wp_kses_post( $order->get_formatted_line_subtotal( $item ) ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>

					<div class="prime-order__acts">
						<?php if ( (float) $order->get_total() > 0 ) : ?>
							<a class="button" href="<?php echo esc_url( prime_invoice_frontend_url( $order ) ); ?>" target="_blank" rel="noopener">
								<?php esc_html_e( 'Download invoice PDF', 'prime-printing' ); ?>
							</a>
						<?php endif; ?>

						<a class="button" href="<?php echo esc_url( prime_reorder_url( $order->get_id() ) ); ?>">
							<?php esc_html_e( 'Reorder', 'prime-printing' ); ?>
						</a>

						<?php $whatsapp_url = prime_order_whatsapp_link( $order ); ?>
						<?php if ( $whatsapp_url ) : ?>
							<a class="button" href="<?php echo esc_url( $whatsapp_url ); ?>" target="_blank" rel="noopener">
								<?php esc_html_e( 'Contact on WhatsApp', 'prime-printing' ); ?>
							</a>
						<?php endif; ?>
					</div>
				</div>
			</article>
		<?php endforeach; ?>
	</div>

	<?php do_action( 'woocommerce_before_account_orders_pagination' ); ?>

	<?php if ( 1 < $customer_orders->max_num_pages ) : ?>
		<div class="woocommerce-pagination woocommerce-pagination--without-numbers woocommerce-Pagination">
			<?php if ( 1 !== $current_page ) : ?>
				<a class="woocommerce-button woocommerce-button--previous woocommerce-Button woocommerce-Button--previous button<?php echo esc_attr( $wp_button_class ); ?>" href="<?php echo esc_url( wc_get_endpoint_url( 'orders', $current_page - 1 ) ); ?>"><?php esc_html_e( 'Previous', 'woocommerce' ); ?></a>
			<?php endif; ?>

			<?php if ( intval( $customer_orders->max_num_pages ) !== $current_page ) : ?>
				<a class="woocommerce-button woocommerce-button--next woocommerce-Button woocommerce-Button--next button<?php echo esc_attr( $wp_button_class ); ?>" href="<?php echo esc_url( wc_get_endpoint_url( 'orders', $current_page + 1 ) ); ?>"><?php esc_html_e( 'Next', 'woocommerce' ); ?></a>
			<?php endif; ?>
		</div>
	<?php endif; ?>

<?php else : ?>

	<div class="prime-orders-empty">
		<p><?php esc_html_e( 'No orders here yet.', 'prime-printing' ); ?></p>
		<a class="button" href="<?php echo esc_url( apply_filters( 'woocommerce_return_to_shop_redirect', wc_get_page_permalink( 'shop' ) ) ); ?>">
			<?php esc_html_e( 'Browse the shop', 'prime-printing' ); ?>
		</a>
	</div>

<?php endif; ?>

<?php do_action( 'woocommerce_after_account_orders', $has_orders ); ?>
