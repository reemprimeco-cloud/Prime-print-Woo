<?php
/**
 * Configurator strip.
 *
 * A live-updating price preview for the custom-priced product, set in
 * Customizer → Prime Printing → Homepage. Hidden entirely when none is set.
 *
 * IMPORTANT — the calculator is a *preview only*. It never posts a price.
 * The CTA carries the entered dimensions to the product page as query
 * arguments, and the real add-to-cart there recomputes the price in PHP
 * (Phase 4c). A browser-supplied price is exactly the `custom_price`
 * vulnerability documented on the current site, and it is not carried over.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

$prime_product = prime_configurator_product();

if ( ! $prime_product ) {
	return;
}

$prime_services = array_slice( prime_services(), 0, 8 );

/*
 * Placeholder pricing, matching the prototype in the approved reference:
 * rate per cm² per piece, with a floor. Phase 4c replaces these with the real
 * per-product figures from Reem's calculators, read from product meta — the
 * markup and the JS do not change when it does.
 */
$prime_rate = (float) apply_filters( 'prime_configurator_rate', 0.375, $prime_product );
$prime_min  = (float) apply_filters( 'prime_configurator_minimum', 2.25, $prime_product );
?>

<section class="prime-configurator" id="prime-configurator">
	<div class="prime-wrap">
		<div class="prime-configurator__copy">
			<p class="prime-eyebrow">
				<?php prime_mark(); ?>
				<span><?php esc_html_e( 'Made to your size', 'prime-printing' ); ?></span>
			</p>

			<h2><?php esc_html_e( 'Type the size. Watch the price move.', 'prime-printing' ); ?></h2>

			<p>
				<?php esc_html_e( 'Upload your artwork, pick the dimensions, and the total updates live. The specs travel with the order straight to your invoice.', 'prime-printing' ); ?>
			</p>

			<?php if ( $prime_services ) : ?>
				<ul class="prime-pillrow">
					<?php foreach ( $prime_services as $prime_service ) : ?>
						<li class="prime-pill"><?php echo esc_html( $prime_service ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>

		<div
			class="prime-calc"
			data-prime-calc
			data-rate="<?php echo esc_attr( $prime_rate ); ?>"
			data-minimum="<?php echo esc_attr( $prime_min ); ?>"
			data-currency="<?php echo esc_attr( get_woocommerce_currency_symbol() ); ?>"
			data-url="<?php echo esc_url( $prime_product->get_permalink() ); ?>"
		>
			<h3>
				<?php prime_mark(); ?>
				<span><?php echo esc_html( $prime_product->get_name() ); ?></span>
			</h3>

			<div class="prime-field prime-field--duo">
				<div class="prime-field">
					<label for="prime-calc-w"><?php esc_html_e( 'Width (cm)', 'prime-printing' ); ?></label>
					<input id="prime-calc-w" type="number" value="10" min="1" step="0.5" data-prime-calc-input>
				</div>
				<div class="prime-field">
					<label for="prime-calc-h"><?php esc_html_e( 'Height (cm)', 'prime-printing' ); ?></label>
					<input id="prime-calc-h" type="number" value="10" min="1" step="0.5" data-prime-calc-input>
				</div>
			</div>

			<div class="prime-field">
				<label for="prime-calc-q"><?php esc_html_e( 'Quantity', 'prime-printing' ); ?></label>
				<select id="prime-calc-q" data-prime-calc-input>
					<?php foreach ( array( 25, 50, 100, 250 ) as $prime_qty ) : ?>
						<option value="<?php echo esc_attr( $prime_qty ); ?>" <?php selected( 50, $prime_qty ); ?>>
							<?php echo esc_html( number_format_i18n( $prime_qty ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<p class="prime-calc__total">
				<span><?php esc_html_e( 'Estimated total', 'prime-printing' ); ?></span>
				<b data-prime-calc-total>&mdash;</b>
			</p>

			<p class="prime-calc__note">
				<?php esc_html_e( 'An estimate. The final price is confirmed on the product page.', 'prime-printing' ); ?>
			</p>

			<a class="prime-btn prime-btn--navy prime-btn--block" href="<?php echo esc_url( $prime_product->get_permalink() ); ?>" data-prime-calc-cta>
				<?php esc_html_e( 'Continue', 'prime-printing' ); ?>
			</a>
		</div>
	</div>
</section>
