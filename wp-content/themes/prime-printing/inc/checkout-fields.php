<?php
/**
 * Checkout fields — the three dynamic address types, Kuwaiti phone
 * validation, and the fields the reference groups under "invoice" and
 * "delivery pin".
 *
 * All three address sub-forms (house / apartment / gift) are registered here
 * unconditionally and all render in the DOM at once — see
 * woocommerce/checkout/form-billing.php. Only one is shown at a time, toggled
 * client-side by checkout.js with no page reload, matching the reference. That
 * is deliberately NOT the same as those fields being decorative: every one of
 * them is a real registered WooCommerce checkout field, its value really
 * persists to the order if submitted, and — this is the part a client-side
 * toggle cannot be trusted for on its own — prime_validate_checkout() below
 * enforces which subset is REQUIRED by checking the submitted
 * `prime_address_type` value on the server, not by trusting that only the
 * "visible" set was filled in. A request forged to submit `address_type=gift`
 * with no recipient phone fails validation here regardless of what any
 * browser would have shown.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

/**
 * The three address types.
 *
 * @return array<string, string>
 */
function prime_address_types() {
	return array(
		'house'     => __( 'House', 'prime-printing' ),
		'apartment' => __( 'Apartment', 'prime-printing' ),
		'gift'      => __( 'Gift', 'prime-printing' ),
	);
}

/**
 * The submitted (or default) address type for this request.
 *
 * Used only to pick which tile starts selected on page load and which panel
 * is server-rendered as visible before JS runs — never to decide what is
 * required. That happens once, in prime_validate_checkout(), from the same
 * $_POST value read the same way, so the two can never disagree.
 *
 * @return string
 */
function prime_current_address_type() {
	$types  = array_keys( prime_address_types() );
	$posted = isset( $_POST['prime_address_type'] ) ? sanitize_key( wp_unslash( $_POST['prime_address_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only default selection; not trusted for anything security-relevant.

	return in_array( $posted, $types, true ) ? $posted : 'house';
}

/**
 * The actual field definitions, shared by two different WooCommerce filters
 * (see the two callers below) — every address sub-field is registered with
 * `required => false` here, on purpose. WooCommerce's own generic
 * required-field check runs against whichever fields are registered
 * regardless of which the customer can currently see, and would otherwise
 * demand (say) a floor number from a customer who picked "house". The real
 * per-type required check is prime_validate_checkout(), and it only runs at
 * checkout — the account page's saved-address edit form (see
 * prime_billing_fields() below) has no equivalent enforcement, since a saved
 * address is re-validated against whichever type is chosen the next time it
 * is actually used at checkout.
 *
 * Billing doubles as the delivery address — this is a single-address boutique
 * checkout, not a bill-here-ship-there business, so "ship to a different
 * address" is removed rather than left as an unused option (see
 * prime_disable_ship_to_different_address()).
 *
 * @param array $fields Flat field array (no 'billing' nesting).
 * @return array
 */
function prime_billing_field_definitions( $fields ) {
	// ---- Your details ------------------------------------------------------
	$fields['billing_first_name'] = array(
		'label'       => __( 'Full name', 'prime-printing' ),
		'placeholder' => __( 'Full name', 'prime-printing' ),
		'required'    => true,
		'class'       => array( 'form-row-wide' ),
		'priority'    => 10,
	);
	unset( $fields['billing_last_name'] );

	$fields['billing_phone']['label']              = __( 'Phone', 'prime-printing' );
	$fields['billing_phone']['placeholder']        = __( 'Your phone', 'prime-printing' );
	$fields['billing_phone']['required']           = true;
	$fields['billing_phone']['priority']           = 20;
	$fields['billing_phone']['custom_attributes']  = array(
		'inputmode' => 'numeric',
		'maxlength' => '8',
		'pattern'   => '[569][0-9]{7}',
	);

	if ( isset( $fields['billing_email'] ) ) {
		$fields['billing_email']['required'] = false;
		$fields['billing_email']['priority']  = 95;
	}

	unset( $fields['billing_company'], $fields['billing_address_1'], $fields['billing_address_2'], $fields['billing_city'], $fields['billing_postcode'] );

	$fields['billing_country'] = array(
		'type'    => 'hidden',
		'default' => 'KW',
	);

	// ---- Address: governorate + area, for house and apartment ---------------
	// `required => false` like every other sub-field, for the same reason (see
	// the docblock above): a gift order collects no address at all — Prime
	// Printing phones the recipient for it — so WooCommerce's generic required
	// check must not demand these. prime_validate_checkout() requires both for
	// house/apartment and skips them for gift.
	$fields['billing_state'] = array(
		'type'        => 'state',
		'label'       => __( 'Governorate', 'prime-printing' ),
		'placeholder' => __( 'Select governorate', 'prime-printing' ),
		'required'    => false,
		'class'       => array( 'form-row-wide' ),
		'priority'    => 40,
	);

	$fields['billing_area'] = array(
		'type'              => 'select',
		'label'             => __( 'Area', 'prime-printing' ),
		'placeholder'       => __( 'Select area', 'prime-printing' ),
		'required'          => false,
		'class'             => array( 'form-row-wide' ),
		'priority'          => 41,
		// A placeholder option ships server-side so the field still validates
		// (as "empty") with JS disabled; checkout.js repopulates the real
		// per-governorate options once a governorate is chosen, and appends an
		// "Other" option (value `__other__`) that reveals the text field below.
		'options'           => array( '' => __( 'Select area', 'prime-printing' ) ),
		'custom_attributes' => array( 'data-other-label' => __( 'Other — type it below', 'prime-printing' ) ),
	);

	// Free-text area name, shown only when Area is set to "Other". Required in
	// that case (prime_validate_checkout()), and saved as the order's area in
	// place of the `__other__` marker (prime_save_checkout_fields()).
	$fields['prime_area_other'] = array(
		'label'       => __( 'Area name', 'prime-printing' ),
		'placeholder' => __( 'Type your area', 'prime-printing' ),
		'required'    => false,
		'class'       => array( 'form-row-wide' ),
		'priority'    => 42,
	);

	// ---- Street address (house and apartment alike) --------------------------
	//
	// A Kuwaiti address is the same down to the building whether it is a house
	// or a flat; only floor and apartment number are extra (Reem, 2026-09-05).
	// So these five are rendered once, for both types, and the apartment panel
	// adds nothing but the two fields below them. The old separate
	// `prime_building` and `prime_apartment_notes` fields are gone — house no.
	// now doubles as the building no./name, and there is one directions box.
	$fields['prime_block']     = array( 'label' => __( 'Block', 'prime-printing' ), 'required' => false, 'class' => array( 'form-row-first' ), 'priority' => 50 );
	$fields['prime_street']    = array( 'label' => __( 'Street', 'prime-printing' ), 'required' => false, 'class' => array( 'form-row-last' ), 'priority' => 51 );
	$fields['prime_house_no']  = array( 'label' => __( 'House / building no.', 'prime-printing' ), 'required' => false, 'class' => array( 'form-row-first' ), 'priority' => 52 );
	$fields['prime_avenue']    = array( 'label' => __( 'Avenue (optional)', 'prime-printing' ), 'required' => false, 'class' => array( 'form-row-last' ), 'priority' => 53 );

	// ---- Apartment only ------------------------------------------------------
	$fields['prime_floor']        = array( 'label' => __( 'Floor', 'prime-printing' ), 'required' => false, 'class' => array( 'form-row-first' ), 'priority' => 54 );
	$fields['prime_apartment_no'] = array( 'label' => __( 'Apartment no.', 'prime-printing' ), 'required' => false, 'class' => array( 'form-row-last' ), 'priority' => 55 );

	$fields['prime_house_notes'] = array( 'type' => 'textarea', 'label' => __( 'Extra directions (optional)', 'prime-printing' ), 'required' => false, 'class' => array( 'form-row-wide' ), 'priority' => 56 );

	// ---- Gift ------------------------------------------------------------------
	$fields['prime_gift_name']  = array( 'label' => __( 'Recipient name', 'prime-printing' ), 'required' => false, 'class' => array( 'form-row-wide' ), 'priority' => 70 );
	$fields['prime_gift_phone'] = array(
		'type'              => 'tel',
		'label'             => __( 'Recipient phone', 'prime-printing' ),
		'required'          => false,
		'class'             => array( 'form-row-wide' ),
		'priority'          => 71,
		'custom_attributes' => array( 'inputmode' => 'numeric', 'maxlength' => '8', 'pattern' => '[569][0-9]{7}' ),
	);
	$fields['prime_gift_note']  = array( 'type' => 'textarea', 'label' => __( 'Gift note (optional)', 'prime-printing' ), 'required' => false, 'class' => array( 'form-row-wide' ), 'priority' => 72 );

	// ---- Invoice (optional company billing info) ----------------------------
	$fields['prime_company_name']  = array( 'label' => __( 'Company name', 'prime-printing' ), 'required' => false, 'class' => array( 'form-row-wide' ), 'priority' => 91 );
	$fields['prime_tax_number']    = array( 'label' => __( 'Tax number (optional)', 'prime-printing' ), 'required' => false, 'class' => array( 'form-row-wide' ), 'priority' => 92 );
	$fields['prime_invoice_email'] = array( 'type' => 'email', 'label' => __( 'Invoice email', 'prime-printing' ), 'required' => false, 'class' => array( 'form-row-wide' ), 'priority' => 93 );

	return $fields;
}

/**
 * Checkout's own field set — the nested `$fields['billing'][...]` shape
 * `woocommerce_checkout_fields` uses.
 *
 * @param array $fields Default fields.
 * @return array
 */
function prime_checkout_fields( $fields ) {
	$fields['billing'] = prime_billing_field_definitions( $fields['billing'] );

	return $fields;
}
add_filter( 'woocommerce_checkout_fields', 'prime_checkout_fields' );

/**
 * The SAME field set on the account page's "Address" tab
 * (/my-account/edit-address/billing/) — a different, flat-shaped filter
 * WooCommerce fires from WC_Countries::get_address_fields(), which checkout's
 * own filter above never reaches. Without this, editing a saved address here
 * showed WooCommerce's generic address_1/city/postcode fields instead of the
 * governorate/area/block/street fields checkout actually collects — a
 * customer could save an address on this page that checkout would then be
 * unable to read back.
 *
 * This intentionally does not attempt to reproduce checkout's dynamic
 * house/apartment/gift panel-switching (that JS is scoped to the checkout
 * page's own markup — see checkout.js and
 * woocommerce/checkout/form-billing.php); every sub-field for all three
 * types just appears together on this plainer, secondary settings screen,
 * same as any standard WooCommerce address form.
 *
 * @param array $fields Flat field array (no 'billing' nesting).
 * @return array
 */
function prime_billing_fields( $fields ) {
	return prime_billing_field_definitions( $fields );
}
add_filter( 'woocommerce_billing_fields', 'prime_billing_fields' );

/**
 * The governorate → areas data checkout.js needs to populate the Area select
 * once a governorate is chosen — normally printed by
 * woocommerce/checkout/form-billing.php, which never runs on this page. Same
 * markup, same script tag ID, so the one copy of checkout.js that reads it
 * (see prime_enqueue_billing_edit_assets() below) works unmodified here too.
 */
function prime_print_governorate_data_for_address_edit() {
	$governorates = prime_governorates();
	?>
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
	<?php
}
add_action( 'woocommerce_before_edit_address_form_billing', 'prime_print_governorate_data_for_address_edit' );

/**
 * The account page's "Address" tab uses the same field set as checkout
 * (prime_billing_fields() above) and so needs the same governorate → area
 * population — see assets/js/address-fields.js for why that's a small
 * separate file rather than reusing checkout.js here.
 */
function prime_enqueue_billing_edit_assets() {
	if ( is_account_page() && is_wc_endpoint_url( 'edit-address' ) ) {
		wp_enqueue_script( 'prime-address-fields', PRIME_URI . '/assets/js/address-fields.js', array(), prime_asset_version( '/assets/js/address-fields.js' ), true );
	}
}
add_action( 'wp_enqueue_scripts', 'prime_enqueue_billing_edit_assets' );

/**
 * A single-address checkout: there is nothing to ship to a different address
 * than the one already collected, and leaving the toggle in would just be a
 * control that changes nothing.
 *
 * The work is done by the `woocommerce_ship_to_destination = billing_only`
 * option (set in prime_ensure_billing_only_addresses(), inc/account.php),
 * which is WooCommerce's own supported way to say "the billing address *is*
 * the delivery address" — it removes the separate shipping form and the
 * "ship to a different address" checkbox on its own.
 *
 * This deliberately does NOT filter `woocommerce_cart_needs_shipping_address`
 * to false, which is the obvious-looking way to do the same thing and was
 * what this function used to do. That filter also feeds
 * `WC_Cart::show_shipping()`, so it hid the entire shipping section from the
 * cart and checkout: every order went through with no delivery fee at all,
 * while the rates themselves calculated correctly and invisibly behind it.
 * Found 2026-09-05 — don't reintroduce it.
 */
function prime_disable_ship_to_different_address() {
	add_filter(
		'woocommerce_ship_to_different_address_checked',
		static function () {
			return false;
		}
	);
}
add_action( 'woocommerce_init', 'prime_disable_ship_to_different_address' );

/**
 * Server-side validation for everything the JS also checks client-side.
 *
 * This is the one place address-type-dependent "required" is actually
 * enforced — see the note on prime_checkout_fields() above for why it is not
 * done through WooCommerce's own required-field mechanism.
 */
function prime_validate_checkout() {
	$get = static function ( $key ) {
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before this hook runs.
	};

	$phone_re = '/^[569]\d{7}$/';

	if ( ! preg_match( $phone_re, $get( 'billing_phone' ) ) ) {
		wc_add_notice( __( 'Enter a valid Kuwaiti phone number — it should start with 5, 6 or 9 and be 8 digits long.', 'prime-printing' ), 'error' );
	}

	$address_type = prime_current_address_type();

	// Gift: no delivery address is collected here — Prime Printing phones the
	// recipient for it — so governorate/area are not required. Recipient name
	// and phone are what make a gift order deliverable.
	if ( 'gift' === $address_type ) {
		if ( mb_strlen( $get( 'prime_gift_name' ) ) < 2 ) {
			wc_add_notice( __( 'Enter the recipient\'s name.', 'prime-printing' ), 'error' );
		}

		if ( ! preg_match( $phone_re, $get( 'prime_gift_phone' ) ) ) {
			wc_add_notice( __( 'Enter a valid Kuwaiti phone number for the recipient.', 'prime-printing' ), 'error' );
		}

		return;
	}

	if ( ! $get( 'billing_state' ) ) {
		wc_add_notice( __( 'Select your governorate.', 'prime-printing' ), 'error' );
	}

	if ( ! $get( 'billing_area' ) ) {
		wc_add_notice( __( 'Select your area.', 'prime-printing' ), 'error' );
	} elseif ( '__other__' === $get( 'billing_area' ) && mb_strlen( $get( 'prime_area_other' ) ) < 2 ) {
		wc_add_notice( __( 'Type the name of your area.', 'prime-printing' ), 'error' );
	}

	$required = array(
		'prime_block'  => __( 'Block', 'prime-printing' ),
		'prime_street' => __( 'Street', 'prime-printing' ),
	);

	$required['prime_house_no'] = __( 'House / building no.', 'prime-printing' );

	if ( 'apartment' === $address_type ) {
		$required['prime_floor']        = __( 'Floor', 'prime-printing' );
		$required['prime_apartment_no'] = __( 'Apartment no.', 'prime-printing' );
	}

	foreach ( $required as $key => $label ) {
		if ( '' === $get( $key ) ) {
			wc_add_notice(
				sprintf(
					/* translators: %s: field label. */
					__( '%s is required.', 'prime-printing' ),
					$label
				),
				'error'
			);
		}
	}
}
add_action( 'woocommerce_after_checkout_validation', 'prime_validate_checkout' );

/**
 * Persist every custom field onto the order, and normalise the effective
 * shipping contact.
 *
 * Gift mode does not collect a street address at checkout — Prime Printing
 * contacts the recipient afterward for it (the whole point of a gift order is
 * that the buyer may not know it). What it DOES have is the recipient's name
 * and phone, and those — not the buyer's — are who and where the parcel
 * actually goes to. Saving them as `_shipping_first_name` / `_shipping_phone`
 * unconditionally, for every order regardless of address type, is what fixes
 * the Armada phone-field bug: dispatch always reads a normalised shipping
 * contact field, never a field that may or may not have been the right one
 * for how this particular order was placed.
 *
 * @param WC_Order $order Order being created.
 * @param array    $data  Posted checkout data.
 */
function prime_save_checkout_fields( $order, $data ) {
	$address_type = prime_current_address_type();
	$order->update_meta_data( '_prime_address_type', $address_type );

	// The shared address (governorate/area/block/street/building) is saved for
	// every type now, including gift — it's optional there (Reem, 2026-09-05:
	// "use the same field address but optional not forced only to show them
	// the price"), left empty when the customer skipped it, populated when
	// they filled it in for a price preview. The apartment-only floor/flat and
	// the gift-only recipient fields are added on top of that shared set,
	// never instead of it.
	$fields = array_merge(
		array( 'billing_area', 'prime_area_other', 'prime_block', 'prime_street', 'prime_house_no', 'prime_avenue', 'prime_house_notes' ),
		'apartment' === $address_type ? array( 'prime_floor', 'prime_apartment_no' ) : array(),
		'gift' === $address_type ? array( 'prime_gift_name', 'prime_gift_phone', 'prime_gift_note' ) : array()
	);

	$fields = array_merge( $fields, array( 'prime_company_name', 'prime_tax_number', 'prime_invoice_email' ) );

	foreach ( $fields as $key ) {
		if ( isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before woocommerce_checkout_create_order fires.
			$is_textarea = in_array( $key, array( 'prime_gift_note', 'prime_house_notes' ), true );
			$value       = $is_textarea
				? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
				: sanitize_text_field( wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash

			$order->update_meta_data( '_' . $key, $value );
		}
	}

	// "Other" area: everything downstream (admin screen, invoice, dispatch)
	// reads _billing_area, so store the typed name there rather than the
	// `__other__` marker — the raw typed value is also kept under its own key
	// above.
	if ( isset( $_POST['billing_area'] ) && '__other__' === $_POST['billing_area'] && ! empty( $_POST['prime_area_other'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$order->update_meta_data( '_billing_area', sanitize_text_field( wp_unslash( $_POST['prime_area_other'] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
	}

	// Mirror "extra directions" onto the key inc/checkout-admin.php and the
	// Armada payload read. There is only one such field now, shared by both
	// address types.
	if ( isset( $_POST['prime_house_notes'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$order->update_meta_data( '_prime_address_notes', sanitize_textarea_field( wp_unslash( $_POST['prime_house_notes'] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
	}

	if ( isset( $_POST['prime_whatsapp_optin'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$order->update_meta_data( '_prime_whatsapp_optin', 'yes' );
	}

	if ( ! empty( $_POST['prime_maps_link'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$order->update_meta_data( '_delivery_maps_link', esc_url_raw( wp_unslash( $_POST['prime_maps_link'] ) ) );
	}

	// The normalised shipping contact — see the docblock above.
	if ( 'gift' === $address_type ) {
		$name  = sanitize_text_field( wp_unslash( $data['prime_gift_name'] ?? '' ) );
		$phone = sanitize_text_field( wp_unslash( $data['prime_gift_phone'] ?? '' ) );
	} else {
		// Both halves: splitting only the first name gave order #2509 a
		// Shipping panel reading "Reem" beside a Billing panel reading
		// "Reem Test" (2026-09-05).
		$name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$phone = $order->get_billing_phone();
	}

	$parts = explode( ' ', trim( $name ), 2 );

	$order->update_meta_data( '_shipping_first_name', $parts[0] ?? '' );
	$order->update_meta_data( '_shipping_last_name', $parts[1] ?? '' );
	$order->update_meta_data( '_shipping_phone', $phone );

	prime_write_native_address( $order, $address_type );
}

/**
 * Compose the Kuwaiti address into WooCommerce's own address fields.
 *
 * A Kuwaiti address is governorate → area → block → street → building, and
 * this checkout collects exactly that, in its own fields. WooCommerce's
 * address_1/city/postcode are never filled in as a result — they are not even
 * rendered on the form — and everything downstream reads *those*: the order
 * screen's Billing and Shipping panels, the order emails, the invoice, the
 * packing slip. The first real order (2026-09-05, #2508) came out with
 * "Al Farwaniya" as its entire billing address and "No shipping address set",
 * which no driver could deliver against.
 *
 * So the parts are composed into a single readable line here, at order
 * creation, and written to both the billing and shipping address. The custom
 * meta stays the source of truth (inc/invoice.php and the Armada payload read
 * it); this is the human-readable rendering of the same thing, in the fields
 * WooCommerce and every plugin already know how to display.
 *
 * @param WC_Order $order        Order being created.
 * @param string   $address_type house, apartment or gift.
 */
function prime_write_native_address( $order, $address_type ) {
	$get = static function ( $key ) {
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before this runs.
	};

	$area = $get( 'billing_area' );

	if ( '__other__' === $area ) {
		$area = $get( 'prime_area_other' );
	}

	$line = array();

	if ( $get( 'prime_block' ) ) {
		/* translators: %s: block number. */
		$line[] = sprintf( __( 'Block %s', 'prime-printing' ), $get( 'prime_block' ) );
	}

	if ( $get( 'prime_street' ) ) {
		/* translators: %s: street name or number. */
		$line[] = sprintf( __( 'Street %s', 'prime-printing' ), $get( 'prime_street' ) );
	}

	if ( $get( 'prime_avenue' ) ) {
		/* translators: %s: avenue. */
		$line[] = sprintf( __( 'Avenue %s', 'prime-printing' ), $get( 'prime_avenue' ) );
	}

	if ( $get( 'prime_house_no' ) ) {
		$line[] = 'apartment' === $address_type
			/* translators: %s: building number or name. */
			? sprintf( __( 'Building %s', 'prime-printing' ), $get( 'prime_house_no' ) )
			/* translators: %s: house number. */
			: sprintf( __( 'House %s', 'prime-printing' ), $get( 'prime_house_no' ) );
	}

	if ( 'apartment' === $address_type ) {
		if ( $get( 'prime_floor' ) ) {
			/* translators: %s: floor. */
			$line[] = sprintf( __( 'Floor %s', 'prime-printing' ), $get( 'prime_floor' ) );
		}

		if ( $get( 'prime_apartment_no' ) ) {
			/* translators: %s: apartment number. */
			$line[] = sprintf( __( 'Apartment %s', 'prime-printing' ), $get( 'prime_apartment_no' ) );
		}
	}

	$address_1 = implode( ', ', $line );

	// A gift order may legitimately have none of this — the recipient is
	// phoned for it — so nothing is forced in that case.
	if ( '' === $address_1 && '' === $area ) {
		return;
	}

	$order->set_billing_address_1( $address_1 );
	$order->set_billing_city( $area );
	$order->set_billing_country( 'KW' );

	// Billing is the delivery address on this store
	// (prime_ensure_billing_only_addresses()), so the shipping side is filled
	// from the same values rather than left empty on the order screen.
	$order->set_shipping_address_1( $address_1 );
	$order->set_shipping_city( $area );
	$order->set_shipping_state( $order->get_billing_state() );
	$order->set_shipping_country( 'KW' );

	$name  = $order->get_meta( '_shipping_first_name' );
	$last  = $order->get_meta( '_shipping_last_name' );

	if ( $name ) {
		$order->set_shipping_first_name( $name );
		$order->set_shipping_last_name( $last );
	}
}
add_action( 'woocommerce_checkout_create_order', 'prime_save_checkout_fields', 10, 2 );

/**
 * Also split the single "Full name" field into WooCommerce's native first/
 * last name, so anything reading get_billing_first_name()/last_name() the
 * ordinary way — order emails, the admin screen, other plugins — still sees a
 * sensible value instead of an empty last name string.
 *
 * @param WC_Order $order Order being created.
 * @param array    $data  Posted checkout data.
 */
function prime_split_billing_name( $order, $data ) {
	$name  = sanitize_text_field( wp_unslash( $_POST['billing_first_name'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
	$parts = explode( ' ', trim( $name ), 2 );

	$order->set_billing_first_name( $parts[0] ?? '' );
	$order->set_billing_last_name( $parts[1] ?? '' );
}
add_action( 'woocommerce_checkout_create_order', 'prime_split_billing_name', 5, 2 );
