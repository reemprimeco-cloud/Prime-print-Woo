<?php
/**
 * The site footer.
 *
 * Four columns — blurb, shop/categories, company, contact — over a legal line.
 * The category column pulls the real top product categories so it stays right
 * as the catalogue changes; the company column is a nav menu Reem can edit.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

$prime_footer_categories = prime_product_categories( 4 );
?>

<footer class="prime-footer">
	<div class="prime-wrap">
		<div class="prime-footer__grid">
			<div>
				<?php prime_logo( array( 'tag' => 'div' ) ); ?>
				<p class="prime-footer__blurb">
					<?php
					echo esc_html(
						get_theme_mod(
							'prime_footer_blurb',
							__( 'A creative printing boutique in Kuwait producing stickers, stamps, calendars and packaging in-house.', 'prime-printing' )
						)
					);
					?>
				</p>
			</div>

			<div>
				<?php if ( $prime_footer_categories ) : ?>
					<h4><?php esc_html_e( 'Popular categories', 'prime-printing' ); ?></h4>
					<ul>
						<?php foreach ( $prime_footer_categories as $prime_category ) : ?>
							<li>
								<a href="<?php echo esc_url( get_term_link( $prime_category ) ); ?>">
									<?php echo esc_html( $prime_category->name ); ?>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<h4><?php esc_html_e( 'Shop', 'prime-printing' ); ?></h4>
					<?php
					prime_nav_menu(
						'footer_shop',
						array(
							'items_wrap'  => '<ul>%3$s</ul>',
							'fallback_cb' => '__return_empty_string',
						)
					);
					?>
				<?php endif; ?>
			</div>

			<div>
				<h4><?php esc_html_e( 'Company', 'prime-printing' ); ?></h4>
				<?php
				prime_nav_menu(
					'footer_pages',
					array(
						'items_wrap'  => '<ul>%3$s</ul>',
						'fallback_cb' => 'prime_nav_menu_fallback',
					)
				);
				?>
			</div>

			<div>
				<h4><?php esc_html_e( 'Get in touch', 'prime-printing' ); ?></h4>
				<ul>
					<?php if ( prime_contact( 'phone' ) ) : ?>
						<li>
							<a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', prime_contact( 'phone' ) ) ); ?>">
								<?php echo esc_html( prime_contact( 'phone' ) ); ?>
							</a>
						</li>
					<?php endif; ?>

					<?php if ( prime_contact( 'email' ) ) : ?>
						<li>
							<a href="mailto:<?php echo esc_attr( prime_contact( 'email' ) ); ?>">
								<?php echo esc_html( prime_contact( 'email' ) ); ?>
							</a>
						</li>
					<?php endif; ?>

					<?php if ( prime_contact( 'whatsapp' ) ) : ?>
						<li>
							<a href="<?php echo esc_url( prime_contact( 'whatsapp' ) ); ?>" rel="noopener" target="_blank">
								<?php esc_html_e( 'WhatsApp us', 'prime-printing' ); ?>
							</a>
						</li>
					<?php endif; ?>

					<?php if ( prime_contact( 'instagram' ) ) : ?>
						<li>
							<a href="<?php echo esc_url( prime_contact( 'instagram' ) ); ?>" rel="noopener" target="_blank">
								<?php esc_html_e( 'Instagram', 'prime-printing' ); ?>
							</a>
						</li>
					<?php endif; ?>
				</ul>
			</div>
		</div>

		<div class="prime-footer__bottom">
			<span>
				<?php
				printf(
					/* translators: 1: current year, 2: site name. */
					esc_html__( '© %1$s %2$s', 'prime-printing' ),
					esc_html( wp_date( 'Y' ) ),
					esc_html( get_bloginfo( 'name', 'display' ) )
				);
				?>
			</span>
			<span><?php esc_html_e( 'Kuwait — KNET · Visa · Mastercard', 'prime-printing' ); ?></span>
		</div>
	</div>
</footer>
