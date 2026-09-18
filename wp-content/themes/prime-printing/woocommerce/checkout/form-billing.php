<?php
/**
 * Checkout — "your details" and "address" sections.
 *
 * Overrides WooCommerce's own checkout/form-billing.php. Every field rendered
 * here is a real registered checkout field (inc/checkout-fields.php) drawn
 * through woocommerce_form_field() — this file only controls their visual
 * grouping. All three address panels (house / apartment / gift) render at
 * once; checkout.js shows exactly one by toggling `.is-active` — see the
 * docblock on inc/checkout-fields.php for why the required-ness is enforced
 * server-side rather than by which panel happens to be visible.
 *
 * @package PrimePrinting
 * @version 9.4.0
 *
 * @global WC_Checkout $checkout
 */

defined( 'ABSPATH' ) || exit;

$fields       = $checkout->get_checkout_fields( 'billing' );
$address_type = prime_current_address_type();
$governorates = prime_governorates();

$field = static function ( $key ) use ( $checkout, $fields ) {
	woocommerce_form_field( $key, $fields[ $key ], $checkout->get_value( $key ) );
};
?>

<?php do_action( 'woocommerce_before_checkout_billing_form', $checkout ); ?>

<div class="prime-sec">
	<div class="prime-sech" id="prime-h1" data-prime-section="details">
		<h2><?php prime_mark(); ?><span><?php esc_html_e( 'Your details', 'prime-printing' ); ?></span></h2>
		<span class="prime-sech__ok" aria-hidden="true">✓</span>
		<div class="prime-sech__line"></div>
	</div>

	<?php $field( 'billing_first_name' ); ?>

	<div class="prime-phone">
		<div class="prime-cc"><span aria-hidden="true">🇰🇼</span><span>+965</span></div>
		<?php $field( 'billing_phone' ); ?>
	</div>

	<label class="prime-check">
		<input type="checkbox" name="prime_whatsapp_optin" value="1" checked>
		<span><?php esc_html_e( 'I agree to be contacted on WhatsApp about my order and offers.', 'prime-printing' ); ?></span>
	</label>

	<button type="button" class="prime-expand" data-prime-toggle="prime-invoice-panel">
		<?php prime_mark(); ?><span><?php esc_html_e( 'Need a company invoice?', 'prime-printing' ); ?></span>
	</button>
	<div class="prime-panel" id="prime-invoice-panel">
		<?php
		$field( 'prime_company_name' );
		$field( 'prime_tax_number' );
		$field( 'prime_invoice_email' );
		?>
	</div>
</div>

<div class="prime-sec">
	<div class="prime-sech" id="prime-h2" data-prime-section="address">
		<h2><?php prime_mark(); ?><span><?php esc_html_e( 'Address', 'prime-printing' ); ?></span></h2>
		<span class="prime-sech__ok" aria-hidden="true">✓</span>
		<div class="prime-sech__line"></div>
	</div>

	<?php
	/*
	 * The country, as a hidden input.
	 *
	 * It is registered in prime_billing_field_definitions() as a hidden field
	 * defaulting to KW, but a field only reaches the page if this template
	 * renders it — and this one did not, so nothing posted a country at all.
	 * WooCommerce matches a shipping zone on the country, so with it missing
	 * no zone matched: the customer saw "There are no shipping options
	 * available", no delivery fee, and no pickup option, and could not
	 * complete an order.
	 *
	 * It looked fine in earlier testing only because WooCommerce fell back to
	 * the store's base country for a customer who had never had an address
	 * set. Once a real address was entered and the customer record updated
	 * from these fields, the country went empty and the shipping section
	 * emptied with it. Found 2026-09-05 — do not drop this line.
	 */
	$field( 'billing_country' );
	?>

	<div class="prime-tiles" id="prime-address-type" role="radiogroup" aria-label="<?php esc_attr_e( 'Address type', 'prime-printing' ); ?>">
		<?php foreach ( prime_address_types() as $type_key => $type_label ) : ?>
			<label class="prime-tile <?php echo $type_key === $address_type ? 'is-on' : ''; ?>" data-prime-address-type-tile="<?php echo esc_attr( $type_key ); ?>">
				<input type="radio" name="prime_address_type" value="<?php echo esc_attr( $type_key ); ?>" class="screen-reader-text" <?php checked( $type_key, $address_type ); ?>>
				<span class="prime-tile__icon" aria-hidden="true"><?php echo 'house' === $type_key ? '⌂' : ( 'apartment' === $type_key ? '▤' : '✦' ); ?></span>
				<span class="prime-tile__label"><?php echo esc_html( $type_label ); ?></span>
			</label>
		<?php endforeach; ?>
	</div>

	<?php
	/*
	 * Delivery pin — hidden for now (Reem, 2026-09-04). As built, this only
	 * captures the phone's GPS coordinates into a Google Maps link saved on the
	 * order for the courier (see prime_maps_link below and _delivery_maps_link
	 * in inc/checkout-fields.php); despite its "Auto-fill address" label it
	 * never filled any address field, so customers saw it as broken. Reem
	 * plans to rebuild it as a real address auto-fill on the Google Maps
	 * Geocoding API — until then the block is off. checkout.js already
	 * tolerates the block being absent. To bring it back as-is:
	 * add_filter( 'prime_show_delivery_pin', '__return_true' );
	 */
	if ( apply_filters( 'prime_show_delivery_pin', false ) ) :
		?>
	<div data-prime-locate-wrap class="<?php echo 'gift' === $address_type ? 'is-hidden' : ''; ?>">
		<div class="prime-locate" id="prime-locate">
			<div class="prime-map" aria-hidden="true"><span class="prime-pin"></span></div>
			<div class="prime-locate__txt">
				<div class="prime-locate__t1"><?php esc_html_e( 'Delivery location', 'prime-printing' ); ?></div>
				<div class="prime-locate__t2" data-prime-loc-status><?php esc_html_e( 'Not set yet', 'prime-printing' ); ?></div>
			</div>
			<button
				type="button"
				class="prime-locbtn"
				data-prime-locate
				data-locating-text="<?php esc_attr_e( 'Locating…', 'prime-printing' ); ?>"
				data-unsupported-text="<?php esc_attr_e( 'Location not supported in this browser', 'prime-printing' ); ?>"
				data-pinned-text="<?php esc_attr_e( 'Pinned', 'prime-printing' ); ?>"
				data-open-maps-text="<?php esc_attr_e( 'Open in Google Maps', 'prime-printing' ); ?>"
				data-error-text="<?php esc_attr_e( 'Could not get location — enable location access and try again', 'prime-printing' ); ?>"
			>
				<span aria-hidden="true">◎</span><span><?php esc_html_e( 'Auto-fill address', 'prime-printing' ); ?></span>
			</button>
		</div>
		<input type="hidden" name="prime_maps_link" id="prime_maps_link" value="">
	</div>
	<?php endif; ?>

	<?php
	/*
	 * House and apartment take the same address, down to the building — only
	 * floor and flat are extra (Reem, 2026-09-05). So every shared field is
	 * rendered once here, and the apartment panel below adds nothing but its
	 * two extra fields.
	 *
	 * Rendering a field in more than one panel is also what broke block and
	 * street: two inputs of the same name went into the form, and on submit
	 * the hidden panel's empty copy overwrote what the customer had typed.
	 * Keep every field in exactly one place.
	 *
	 * A gift order shows this same block too (Reem, 2026-09-05: "use the same
	 * field address but optional not forced only to show them the price") —
	 * unlike house/apartment, prime_validate_checkout() never requires it for
	 * gift, and Prime Printing still calls the recipient to confirm the real
	 * address before dispatch. Filling it in only makes the delivery fee shown
	 * on this page a live Armada quote instead of the flat placeholder line —
	 * see Prime_Governorate_Shipping::calculate_shipping().
	 */
	?>
	<div data-prime-address-common>
		<p class="prime-gift__hint <?php echo 'gift' === $address_type ? '' : 'is-hidden'; ?>" data-prime-address-optional-hint>
			<?php esc_html_e( 'Optional for a gift order — add it only if you would like to see an estimated delivery price now. We will still call the recipient to confirm the exact address before dispatch.', 'prime-printing' ); ?>
		</p>
		<?php $field( 'billing_state' ); ?>
		<?php $field( 'billing_area' ); ?>
		<?php // Shown by checkout.js only while Area is "Other". ?>
		<div data-prime-area-other class="is-hidden">
			<?php $field( 'prime_area_other' ); ?>
		</div>
		<div class="prime-duo">
			<?php $field( 'prime_block' ); ?>
			<?php $field( 'prime_street' ); ?>
		</div>
		<div class="prime-duo">
			<?php $field( 'prime_house_no' ); ?>
			<?php $field( 'prime_avenue' ); ?>
		</div>
	</div>

	<?php // The only fields an apartment adds. There is no house panel — a house is the shared set above and nothing more. Never shown for gift, whose optional address above has no floor/flat granularity. ?>
	<div data-prime-address-panel="apartment" class="<?php echo 'apartment' === $address_type ? 'is-active' : 'is-hidden'; ?>">
		<div class="prime-duo">
			<?php $field( 'prime_floor' ); ?>
			<?php $field( 'prime_apartment_no' ); ?>
		</div>
	</div>

	<div data-prime-address-common>
		<?php $field( 'prime_house_notes' ); ?>
	</div>

	<div data-prime-address-panel="gift" class="prime-gift <?php echo 'gift' === $address_type ? 'is-active' : 'is-hidden'; ?>">
		<p class="prime-gift__hint"><?php esc_html_e( 'We will call the recipient to confirm the exact delivery address before dispatch.', 'prime-printing' ); ?></p>
		<?php $field( 'prime_gift_name' ); ?>
		<div class="prime-phone">
			<div class="prime-cc"><span aria-hidden="true">🇰🇼</span><span>+965</span></div>
			<?php $field( 'prime_gift_phone' ); ?>
		</div>
		<?php $field( 'prime_gift_note' ); ?>
	</div>
</div>

<?php do_action( 'woocommerce_after_checkout_billing_form', $checkout ); ?>

<script type="application/json" id="prime-governorate-data">
<?php
echo wp_json_encode(
	array_map(
		static function ( $gov ) {
			return array(
				'name'  => $gov['name'],
				'areas' => $gov['areas'],
			);
		},
		$governorates
	)
);
?>
</script>
