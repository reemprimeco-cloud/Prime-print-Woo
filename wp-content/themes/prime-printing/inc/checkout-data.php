<?php
/**
 * Kuwait governorates, areas, and shipping rates.
 *
 * Replaces the Cities Shipping Zones plugin entirely, per the build plan: a
 * simple PHP array rather than a settings screen, since Reem edits this by
 * asking for a code change, not by learning a new admin UI.
 *
 * The six governorates and all 129 districts are the real, full Kuwait list,
 * imported from the "States, Cities, and Places for WooCommerce" plugin's own
 * KW dataset (places/KW.php) and mapped onto this theme's own governorate
 * codes — the codes stay as they were so existing orders' billing_state
 * values keep resolving. The plugin itself is not a dependency: this array is
 * still the single source the checkout, cart and shipping method all read, so
 * the plugin can be deactivated again without changing behaviour.
 *
 * SHIPPING RATES ARE PLACEHOLDER. The governorates and areas are
 * real; the per-governorate shipping cost is not — it is a plausible
 * distance-ordered guess, not Reem's actual pricing. Swapping in the real
 * figures once she provides them is a one-line edit per governorate below;
 * nothing else in the checkout, cart, or shipping method needs to change when
 * that happens. Phase 10 also pulls the same real figures into the migrated
 * catalogue's shipping data — this array is the one place both read from.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every governorate, its areas, and its shipping rate.
 *
 * Keys are short codes used as WooCommerce state codes for Kuwait (KW) — see
 * prime_register_kuwait_states() — so a governorate doubles as the customer's
 * "state" in every WooCommerce API that already understands states: shipping
 * zones, tax classes, the admin order screen's address block.
 *
 * @return array<string, array{name: string, areas: string[], rate: float}>
 */
function prime_governorates() {
	return prime_apply_hidden_areas( prime_governorates_all() );
}

/**
 * Governorates priced at their flat table rate, never by Armada's quote.
 *
 * Al Jahra is one governorate with one price — 3 KD, whatever the area
 * (Reem, 2026-09-11: a customer was shown 4 KD; "ضروري تثبت الجهراء ٣ دك
 * للمحافظه كلها"). Armada's road-distance quote can land anywhere from 2 to
 * 4 KD across Jahra's areas, so for a code listed here
 * Prime_Governorate_Shipping::calculate_shipping() skips the Armada call
 * entirely and charges the governorate's `rate` from prime_governorates_all().
 * Everywhere else still gets the live quote.
 *
 * @return string[] Governorate codes, as keyed in prime_governorates_all().
 */
function prime_fixed_rate_governorates() {
	return apply_filters( 'prime_fixed_rate_governorates', array( 'JA' ) );
}

/**
 * Areas to leave out of the Area dropdown.
 *
 * Add an area's name here — exactly as it is spelled in
 * prime_governorates_all() below — and it stops being offered at checkout and
 * on the account page's address form. Everything else is unaffected: the
 * governorate keeps its shipping rate (rates are per governorate, not per
 * area), and an order already placed to a now-hidden area still displays and
 * reads back normally, because nothing validates a saved area against this
 * list. Customers in an area that isn't listed can still reach it through the
 * "Other" option, which lets them type the name.
 *
 * Empty by default — nothing is hidden until a name is added here.
 *
 * @return string[]
 */
function prime_hidden_areas() {
	$hidden = array(
		// Al Ahmadi — the far south, past the built-up coast.
		'Al-Julaiaa',
		'Ali Sabah Al-Salem - Umm Al Hayman',
		'Bnaider',
		'Khairan',
		'Magwa',
		'Mina Abdullah',
		'Nuwaiseeb',
		'Sabah AL Ahmad residential',
		'Sabah Al Ahmad Marine City',
		'Shuaiba Port',
		'Wafra farms',
		'Wafra residential',
		'Zour',

		// Al Jahra — industrial estates and the outlying desert areas.
		'Alsulaibiya Industrial 1',
		'Alsulaibiya Industrial 2',
		'Jahra Amgarah Industrial',
		'Kabd',
		'Taima',

		// Mubarak Al-Kabeer.
		'South Wista',
		'Wista',
	);

	return apply_filters( 'prime_hidden_areas', $hidden );
}

/**
 * Strip prime_hidden_areas() out of a governorate list.
 *
 * @param array $governorates Governorates, areas included.
 * @return array
 */
function prime_apply_hidden_areas( $governorates ) {
	$hidden = prime_hidden_areas();

	if ( ! $hidden ) {
		return $governorates;
	}

	foreach ( $governorates as $code => $governorate ) {
		$governorates[ $code ]['areas'] = array_values( array_diff( $governorate['areas'], $hidden ) );
	}

	return $governorates;
}

/**
 * The complete list, before prime_hidden_areas() is applied — the raw data.
 *
 * @return array<string, array{name: string, areas: string[], rate: float}>
 */
function prime_governorates_all() {
	return array(
		'AS' => array(
			'name'  => __( 'Al Asimah', 'prime-printing' ),
			'rate'  => 1.500,
			'areas' => array(
				'Abdullah Al-Salem', 'Adailiya', 'Bneid Al Qar', 'Daiya',
				'Dasma', 'Dasman', 'Doha', 'Faiha',
				'Ghornata', 'Jaber Al Ahmad', 'Kaifan', 'Khaldiya',
				'Kuwait City', 'Mansouriya', 'Mirqab', 'Mubarakiya Camps',
				'Nahda', 'North West Sulaibikhat', 'Nuzha', 'Qadsiya',
				'Qibla', 'Qortuba', 'Rawda', 'Salhiya',
				'Shamiya', 'Sharq', 'Shuwaikh', 'Shuwaikh Administrative',
				'Shuwaikh Educational', 'Shuwaikh Industrial 1', 'Shuwaikh Industrial 2', 'Shuwaikh Industrial 3',
				'Shuwaikh Medical', 'Shuwaikh Residential', 'Sulaibikhat', 'Surra',
				'Yarmouk',
			),
		),
		'HA' => array(
			'name'  => __( 'Hawalli', 'prime-printing' ),
			'rate'  => 1.500,
			'areas' => array(
				'Al-Bedae', 'Bayan', 'Hawally', 'Hitteen',
				'Jabriya', 'Maidan Hawally', 'Ministries Zone', 'Mishrif',
				'Mubarak Al-Abdullah - West Mishref', 'Rumaithiya', 'Salam', 'Salmiya',
				'Salwa', 'Shaab', 'Shuhada', 'Siddiq',
				'Zahra',
			),
		),
		'FA' => array(
			'name'  => __( 'Al Farwaniya', 'prime-printing' ),
			'rate'  => 2.000,
			'areas' => array(
				'Abdullah Al-Mubarak', 'Airport', 'Andalous', 'Ardhiya',
				'Ardhiya 4', 'Ardiya Small Industrial', 'Ardiya Storage Zone', 'Ashbeliah',
				'Dhajeej', 'Farwaniya', 'Ferdous', 'Jeleeb Al-Shuyoukh',
				'Khaitan', 'Omariya', 'Rabiya', 'Rai',
				'Reggai', 'Rehab', 'Sabah Al-Nasser', 'Sheikh Saad Al Abdullah Airport',
			),
		),
		'AH' => array(
			'name'  => __( 'Al Ahmadi', 'prime-printing' ),
			'rate'  => 2.500,
			'areas' => array(
				'Abu Halifa', 'Al-Ahmadi', 'Al-Julaiaa', 'Ali Sabah Al-Salem - Umm Al Hayman',
				'Bnaider', 'Dhaher', 'Egaila', 'Fahad Al Ahmed',
				'Fahaheel', 'Fintas', 'Hadiya', 'Jaber Al Ali',
				'Khairan', 'Magwa', 'Mahboula', 'Mangaf',
				'Mina Abdullah', 'Nuwaiseeb', 'Riqqa', 'Sabah AL Ahmad residential',
				'Sabah Al Ahmad Marine City', 'Sabahiya', 'Shuaiba Port', 'South Sabahiya',
				'Wafra farms', 'Wafra residential', 'Zour',
			),
		),
		'JA' => array(
			'name'  => __( 'Al Jahra', 'prime-printing' ),
			'rate'  => 3.000,
			'areas' => array(
				'Al Naeem', 'Alnasseem', 'Aloyoun', 'Alqairawan',
				'Alqasr', 'Alsulaibiya', 'Alsulaibiya Industrial 1', 'Alsulaibiya Industrial 2',
				'Alwaha', 'Jahra Amgarah Industrial', 'Jahra Area', 'Kabd',
				'Saad Al Abdullah', 'Taima',
			),
		),
		'MU' => array(
			'name'  => __( 'Mubarak Al-Kabeer', 'prime-printing' ),
			'rate'  => 2.000,
			'areas' => array(
				'Abu Ftaira', 'Abu Hasaniya', 'Adan', 'Al Masayel',
				'Al-Qurain', 'Al-Qusour', 'Fnaitess', 'Messila',
				'Mubarak Al-Kabir', 'Sabah Al-Salem', 'Sabhan Industrial', 'South Wista',
				'West Abu Fetera Small Indust', 'Wista',
			),
		),
	);
}

/**
 * One governorate's data.
 *
 * @param string $code Governorate code.
 * @return array|null
 */
function prime_governorate( $code ) {
	$governorates = prime_governorates();

	return isset( $governorates[ $code ] ) ? $governorates[ $code ] : null;
}

/**
 * Register the governorates as Kuwait's "states", so WooCommerce's existing
 * state-aware machinery (shipping zones, the admin address block, address
 * validation) treats a governorate as a first-class address component instead
 * of a bolted-on custom field.
 *
 * @param array $states States by country code.
 * @return array
 */
function prime_register_kuwait_states( $states ) {
	$states['KW'] = wp_list_pluck( prime_governorates(), 'name' );

	return $states;
}
add_filter( 'woocommerce_states', 'prime_register_kuwait_states' );

/**
 * Kuwait addresses have no postcode, so stop WooCommerce asking for one.
 *
 * WooCommerce's default locale marks the postcode required for Kuwait. This
 * checkout has never collected one — a Kuwaiti address is governorate → area
 * → block → street — which quietly broke delivery pricing: `WC_Cart::show_shipping()`
 * returns false when a *required* postcode is empty, so the whole shipping
 * section (rates, pickup, the lot) was hidden at render even though every
 * method calculated its rate correctly. Orders went through with no delivery
 * fee. It looked fine in testing only because an older saved test address had
 * a dummy "0000" postcode filling the field.
 *
 * Found 2026-09-05. Marking it not-required and hidden fixes the display and
 * keeps WooCommerce from demanding a postcode anywhere else either.
 *
 * `state` (relabelled "Governorate" below) is deliberately NOT marked
 * required here, even though prime_validate_checkout() does require it for
 * house/apartment orders. It used to be — "the governorate is this
 * checkout's most important address component" — until a gift order's
 * address became optional too (Reem, 2026-09-05: "use the same field address
 * but optional not forced only to show them the price"): a gift order left
 * with no address at all has an empty state, and this same
 * `WC_Cart::show_shipping()` required-field gate hid the whole Shipment
 * section again — not just the Armada quote, the "confirmed with the
 * recipient" placeholder rate too. Requiredness now lives only in
 * prime_validate_checkout(), the one place address-type-dependent rules were
 * already meant to be enforced (see the docblock on
 * inc/checkout-fields.php's prime_checkout_fields()).
 *
 * @param array $locales Address locale rules by country.
 * @return array
 */
function prime_kuwait_address_locale( $locales ) {
	$locales['KW']['postcode']['required'] = false;
	$locales['KW']['postcode']['hidden']   = true;
	$locales['KW']['state']['required']    = false;
	$locales['KW']['state']['label']       = __( 'Governorate', 'prime-printing' );

	return $locales;
}
add_filter( 'woocommerce_get_country_locale', 'prime_kuwait_address_locale' );

/**
 * Force the Cart and Checkout pages onto WooCommerce's classic shortcodes.
 *
 * WooCommerce creates these as block-based pages by default on a fresh
 * install (`woocommerce/cart` / `woocommerce/checkout` blocks) — an entirely
 * different customization surface (the Store API + WooCommerce Blocks'
 * Additional Fields API) from the one this theme is built against.
 * `woocommerce_checkout_fields`, every template in woocommerce/checkout/*.php,
 * and the governorate/address-type/payment-tile logic in inc/checkout-*.php
 * all target the classic system and do nothing on the block version — this
 * theme would silently show WooCommerce's stock block checkout, none of
 * Phase 5, if a fresh WooCommerce install were left as-is.
 *
 * Guarded by an option flag rather than run on every request — if Reem's team
 * ever deliberately rebuilds checkout as blocks, this does not silently
 * revert that choice.
 *
 * Originally an `after_switch_theme` callback, which turned out to never
 * fire in practice: WordPress only runs that hook from a wp-admin page load
 * (via core's `check_theme_switched()`), not from `switch_theme()` itself —
 * so anything that only visits the front end, like this dev environment's
 * boot flow, never triggered it. `woocommerce_init` runs on every request and
 * is what inc/checkout-shipping.php already used successfully for the same
 * kind of one-time setup; this now matches that proven pattern.
 */
function prime_force_classic_checkout_pages() {
	if ( get_option( 'prime_classic_checkout_forced' ) ) {
		return;
	}

	if ( ! function_exists( 'wc_get_page_id' ) ) {
		return;
	}

	$cart_id     = wc_get_page_id( 'cart' );
	$checkout_id = wc_get_page_id( 'checkout' );

	if ( $cart_id > 0 && ! has_shortcode( get_post_field( 'post_content', $cart_id ), 'woocommerce_cart' ) ) {
		wp_update_post(
			array(
				'ID'           => $cart_id,
				'post_content' => '[woocommerce_cart]',
			)
		);
	}

	if ( $checkout_id > 0 && ! has_shortcode( get_post_field( 'post_content', $checkout_id ), 'woocommerce_checkout' ) ) {
		wp_update_post(
			array(
				'ID'           => $checkout_id,
				'post_content' => '[woocommerce_checkout]',
			)
		);
	}

	update_option( 'prime_classic_checkout_forced', 1 );
}
add_action( 'woocommerce_init', 'prime_force_classic_checkout_pages' );

/**
 * Kuwait is the only country this store ships to or sells from.
 *
 * Guarded by an option flag, not re-forced every request — the store's
 * country settings are Reem's to change in WooCommerce → Settings if the
 * business ever does. See prime_force_classic_checkout_pages() above for why
 * this runs on `woocommerce_init` rather than the `after_switch_theme` it
 * started on. Removing the country selector rather than defaulting it keeps a
 * customer from ever landing on a shipping/tax configuration nothing on the
 * site actually supports.
 */
function prime_restrict_to_kuwait() {
	if ( get_option( 'prime_kuwait_restricted' ) ) {
		return;
	}

	update_option( 'woocommerce_allowed_countries', 'specific' );
	update_option( 'woocommerce_specific_allowed_countries', array( 'KW' ) );
	update_option( 'woocommerce_ship_to_countries', 'base' );
	update_option( 'prime_kuwait_restricted', 1 );
}
add_action( 'woocommerce_init', 'prime_restrict_to_kuwait' );
