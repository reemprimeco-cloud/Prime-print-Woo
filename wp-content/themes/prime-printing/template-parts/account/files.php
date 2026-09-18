<?php
/**
 * Account → Files tab.
 *
 * Every file the current customer has uploaded via a product add-on
 * (Phase 4a) or a custom-pricing configurator (Phase 4c), across every
 * order, with a same-file reorder — see prime_get_customer_files() and
 * prime_reorder() in inc/account.php.
 *
 * @package PrimePrinting
 *
 * @var WP_Post[] $files
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="prime-account-files">
	<p class="prime-account-files__intro">
		<?php esc_html_e( 'Every file you uploaded with a past order. Reorder with the same file in one click.', 'prime-printing' ); ?>
	</p>

	<?php if ( ! $files ) : ?>
		<p class="woocommerce-info">
			<?php esc_html_e( 'No uploaded files yet — files you upload with a custom-pricing product or a text/file add-on will show up here after you place the order.', 'prime-printing' ); ?>
		</p>
	<?php else : ?>
		<ul class="prime-account-files__list">
			<?php foreach ( $files as $file ) :
				$order_id   = (int) get_post_meta( $file->ID, '_prime_order_id', true );
				$order      = $order_id ? wc_get_order( $order_id ) : false;
				$path       = get_post_meta( $file->ID, '_prime_private_path', true );
				$size       = $path && is_readable( $path ) ? size_format( filesize( $path ) ) : '';
				$ext        = strtoupper( pathinfo( $file->post_title, PATHINFO_EXTENSION ) );
				?>
				<li class="prime-account-files__row">
					<span class="prime-account-files__ext"><?php echo esc_html( $ext ); ?></span>
					<span class="prime-account-files__info">
						<span class="prime-account-files__name"><?php echo esc_html( $file->post_title ); ?></span>
						<span class="prime-account-files__meta">
							<?php
							if ( $order ) {
								printf(
									/* translators: 1: file size, 2: order number */
									esc_html__( '%1$s · uploaded with order #%2$s', 'prime-printing' ),
									esc_html( $size ),
									esc_html( $order->get_order_number() )
								);
							} else {
								echo esc_html( $size );
							}
							?>
						</span>
					</span>
					<span class="prime-account-files__actions">
						<a class="button" href="<?php echo esc_url( prime_file_download_url( $file->ID ) ); ?>">
							<?php esc_html_e( 'Download', 'prime-printing' ); ?>
						</a>
						<?php if ( $order && get_current_user_id() === $order->get_customer_id() ) : ?>
							<a class="button button--solid" href="<?php echo esc_url( prime_reorder_file_url( $order_id, $file->ID ) ); ?>">
								<?php esc_html_e( 'Order again', 'prime-printing' ); ?>
							</a>
						<?php endif; ?>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
