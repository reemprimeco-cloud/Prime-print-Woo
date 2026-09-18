<?php
/**
 * Governorate-based shipping.
 *
 * A real WC_Shipping_Method rather than a price injected via a cart hook, so
 * it plugs into WooCommerce's existing shipping machinery — the AJAX
 * recalculation that already fires when checkout fields change, the shipping
 * line on the order, the admin order screen's shipping display — instead of
 * fighting all of that with custom code.
 *
 * The governorate is the customer's "state" (see prime_register_kuwait_states()
 * in inc/checkout-data.php), so this method reads
 * WC()->customer->get_shipping_state() exactly the way a built-in flat-rate
 * method would read any other country's state.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the method with WooCommerce.
 *
 * @param string[] $methods Registered shipping method classes.
 * @return string[]
 */
function prime_register_shipping_method( $methods ) {
	$methods['prime_governorate'] = 'Prime_Governorate_Shipping';

	return $methods;
}
add_filter( 'woocommerce_shipping_methods', 'prime_register_shipping_method' );

/**
 * Add the method to every shipping zone automatically.
 *
 * A store this size has one real shipping zone (Kuwait); rather than asking
 * Reem to remember to add this method by hand in WooCommerce → Settings →
 * Shipping whenever a zone is touched, it self-registers.
 */
function prime_ensure_shipping_method_enabled() {
	if ( get_option( 'prime_shipping_method_ensured' ) ) {
		return;
	}

	if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
		return;
	}

	$zones   = WC_Shipping_Zones::get_zones();
	$zone_id = 0;

	if ( empty( $zones ) ) {
		// No zone yet: create the one Kuwait-wide zone this store needs.
		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( 'Kuwait' );
		$zone->set_zone_order( 0 );
		$zone->add_location( 'KW', 'country' );
		$zone_id = $zone->save();
	} else {
		$zone_id = reset( $zones )['id'];
	}

	$zone = new WC_Shipping_Zone( $zone_id );

	$has_method = false;

	foreach ( $zone->get_shipping_methods() as $method ) {
		if ( 'prime_governorate' === $method->id ) {
			$has_method = true;
			break;
		}
	}

	if ( ! $has_method ) {
		$zone->add_shipping_method( 'prime_governorate' );
	}

	update_option( 'prime_shipping_method_ensured', 1 );
}
add_action( 'woocommerce_init', 'prime_ensure_shipping_method_enabled', 20 );

/**
 * The shipping method itself.
 */
class Prime_Governorate_Shipping extends WC_Shipping_Method {

	/**
	 * Constructor.
	 *
	 * @param int $instance_id Zone instance ID.
	 */
	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'prime_governorate';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'Prime Printing — by governorate', 'prime-printing' );
		$this->method_description = __( 'One flat rate per Kuwait governorate. Rates are set in code (prime_governorates() in inc/checkout-data.php), not in this settings screen — this method exists only to plug that table into WooCommerce\'s own shipping calculation.', 'prime-printing' );
		$this->supports            = array( 'shipping-zones', 'instance-settings' );

		$this->init();
	}

	/**
	 * Set up the (minimal) settings screen.
	 */
	public function init() {
		$this->enabled = 'yes';

		$this->init_form_fields();
		$this->init_settings();

		// Everything on this method's settings screen is a *zone instance*
		// setting, and init_settings() only fills $this->settings — the
		// instance values live in $this->instance_settings and stay empty
		// until this runs. Without it the Armada credentials read back blank,
		// so live pricing silently reported itself switched off and every
		// order fell back to the governorate table.
		$this->init_instance_settings();

		// Honour the Method title field rather than hard-coding it.
		$this->title = $this->get_option( 'title', __( 'Delivery', 'prime-printing' ) );

		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Admin settings — just the display title; rates are not editable here.
	 */
	public function init_form_fields() {
		$this->instance_form_fields = array(
			'title' => array(
				'title'       => __( 'Method title', 'prime-printing' ),
				'type'        => 'text',
				'description' => __( 'Shown to the customer as the shipping method name.', 'prime-printing' ),
				'default'     => __( 'Delivery', 'prime-printing' ),
			),

			'armada_section' => array(
				'title'       => __( 'Armada live pricing', 'prime-printing' ),
				'type'        => 'title',
				'description' => __( 'Armada charges by road distance from the shop, which a per-governorate table cannot express. With this on, each customer is quoted the real fee for their own address. If Armada cannot be reached the order still goes through, priced from the governorate rates below — the customer is never blocked. Failures are recorded under WooCommerce → Status → Logs (source "prime-armada").', 'prime-printing' ),
			),
			'armada_enabled' => array(
				'title'   => __( 'Live pricing', 'prime-printing' ),
				'type'    => 'checkbox',
				'label'   => __( 'Quote each delivery fee from Armada', 'prime-printing' ),
				'default' => 'no',
			),
			'armada_environment' => array(
				'title'       => __( 'Environment', 'prime-printing' ),
				'type'        => 'select',
				'description' => __( 'Production is correct for a live shop — use a Test-mode API key there to trial it without dispatching a driver. Sandbox is a separate Armada account with its own data.', 'prime-printing' ),
				'default'     => 'production',
				'options'     => array(
					'production' => __( 'Production', 'prime-printing' ),
					'sandbox'    => __( 'Sandbox', 'prime-printing' ),
				),
			),
			'armada_api_key' => array(
				'title'       => __( 'API key', 'prime-printing' ),
				'type'        => 'text',
				'description' => __( 'From Armada. Sent as the Authorization header.', 'prime-printing' ),
				'default'     => '',
			),
			'armada_api_secret' => array(
				'title'       => __( 'API secret', 'prime-printing' ),
				'type'        => 'password',
				'description' => __( 'From Armada. Used to sign each request — it is never sent to Armada or shown on the site.', 'prime-printing' ),
				'default'     => '',
			),
			'armada_branch_id' => array(
				'title'       => __( 'Branch ID (optional)', 'prime-printing' ),
				'type'        => 'text',
				'description' => __( 'If the Al-Dajeej shop is registered as a branch with Armada, put its ID here and pickup is taken from that. Leave empty to use the shop coordinates below.', 'prime-printing' ),
				'default'     => '',
			),
			'armada_origin_lat' => array(
				'title'       => __( 'Shop latitude', 'prime-printing' ),
				'type'        => 'text',
				'description' => __( 'Used only when no Branch ID is set. Defaults to the Al-Dajeej shop.', 'prime-printing' ),
				'default'     => '29.2631007',
			),
			'armada_origin_lng' => array(
				'title'   => __( 'Shop longitude', 'prime-printing' ),
				'type'    => 'text',
				'default' => '47.9655069',
			),
		);
	}

	/**
	 * Calculate the rate for the current package.
	 *
	 * @param array $package Shipping package.
	 */
	public function calculate_shipping( $package = array() ) {
		$state       = $package['destination']['state'] ?? '';
		$governorate = $state ? prime_governorate( $state ) : null;
		$is_gift     = 'gift' === ( $package['prime_address_type'] ?? '' );

		// A gift order's address is optional — Reem, 2026-09-05: "use the same
		// field address but optional not forced only to show them the price".
		// Whatever was typed (for any type) is priced exactly the same way:
		// Armada's real road-distance quote wins when it can be reached, any
		// failure — no credentials, timeout, or (for gift) an address left
		// blank entirely — falls through to the table below rather than
		// leaving the customer unable to check out.
		// A governorate with one fixed price (Al Jahra: 3 KD everywhere — see
		// prime_fixed_rate_governorates()) never consults Armada: its live
		// quote varied from 2 to 4 KD across Jahra's areas and a customer was
		// shown 4 (Reem, 2026-09-11). The table rate is the whole answer.
		if ( $governorate && in_array( $state, prime_fixed_rate_governorates(), true ) ) {
			$this->add_rate(
				array(
					'id'        => $this->get_rate_id(),
					'label'     => $is_gift ? __( 'Delivery — estimated, confirmed with the recipient', 'prime-printing' ) : $this->title,
					'cost'      => $governorate['rate'],
					'meta_data' => array( 'prime_rate_source' => 'fixed-governorate' ),
				)
			);

			return;
		}

		if ( ! empty( $package['prime_armada_address'] ) ) {
			$armada_address                = $package['prime_armada_address'];
			$armada_address['governorate'] = $state;
			$quote                         = prime_armada_estimate( $armada_address, $this->instance_settings );

			if ( ! is_wp_error( $quote ) ) {
				$this->add_rate(
					array(
						'id'        => $this->get_rate_id(),
						'label'     => $is_gift ? __( 'Delivery — estimated, confirmed with the recipient', 'prime-printing' ) : $this->title,
						'cost'      => $quote,
						'meta_data' => array( 'prime_rate_source' => 'armada' ),
					)
				);

				return;
			}
		}

		if ( ! $governorate ) {
			// No governorate at all: for house/apartment this just means it
			// hasn't been chosen yet — no rate is added, and the checkout
			// template shows "set after choosing governorate" for this state,
			// matching the reference, rather than a $0 or a guessed rate. For
			// gift it's the normal case when the optional address was skipped
			// entirely, so a placeholder line is added instead of leaving the
			// order with no delivery option at all — the fee is settled with
			// the recipient afterwards. The amount is filterable so a flat
			// gift-delivery fee can be set without touching this class. See
			// prime_shipping_package_address_type() for how the type reaches
			// this package.
			if ( $is_gift ) {
				$this->add_rate(
					array(
						'id'    => $this->get_rate_id(),
						'label' => __( 'Delivery — fee confirmed with the recipient', 'prime-printing' ),
						'cost'  => (float) apply_filters( 'prime_gift_delivery_fee', 0 ),
					)
				);
			}

			return;
		}

		$this->add_rate(
			array(
				'id'    => $this->get_rate_id(),
				'label' => $is_gift ? __( 'Delivery — estimated, confirmed with the recipient', 'prime-printing' ) : $this->title,
				'cost'  => $governorate['rate'],
			)
		);
	}
}

/**
 * Keep WooCommerce's shipping debug mode off.
 *
 * Debug mode prints an admin-only diagnostic notice — "Customer matched zone
 * 'X'" — on the Cart/Checkout pages for every visitor, not just admins. It
 * was left on in WooCommerce → Settings → Shipping and was showing to real
 * customers. Self-healing (mirrors prime_disable_cod_gateway() in
 * inc/checkout-payment.php) rather than a one-off settings change, since the
 * setting lives in the database independent of this code and could get
 * flipped back on by anyone testing shipping zones in wp-admin.
 */
function prime_disable_shipping_debug_mode() {
	if ( 'yes' !== get_option( 'woocommerce_shipping_debug_mode' ) ) {
		return;
	}

	update_option( 'woocommerce_shipping_debug_mode', 'no' );
}
add_action( 'woocommerce_init', 'prime_disable_shipping_debug_mode' );

/**
 * Remember the address type the customer has picked while they're still on
 * the checkout page.
 *
 * Every time a checkout field changes, WooCommerce posts the whole form to
 * update_order_review as one serialised `post_data` string — the address type
 * radio is in there, but it never reaches $_POST as its own key, and shipping
 * is recalculated from the *customer* object, which knows nothing about it.
 * Parking it in the session is the standard way to get a custom checkout
 * value to a shipping method mid-page. The final place-order request posts it
 * normally, which prime_shipping_package_address_type() prefers when present.
 *
 * @param string $post_data Serialised checkout form, as WooCommerce passes it.
 */
function prime_remember_checkout_address_type( $post_data ) {
	parse_str( (string) $post_data, $posted );

	if ( ! WC()->session ) {
		return;
	}

	$type = isset( $posted['prime_address_type'] ) ? sanitize_key( $posted['prime_address_type'] ) : '';

	if ( in_array( $type, array_keys( prime_address_types() ), true ) ) {
		WC()->session->set( 'prime_address_type', $type );
	}

	WC()->session->set( 'prime_armada_address', prime_armada_address_from_posted( $posted ) );
}

/**
 * The address parts Armada needs, pulled out of the posted checkout form.
 *
 * "Building" is whichever of house number / building name the chosen address
 * type collects, and the two "extra directions" boxes are the same field to
 * Armada, so both normalise here rather than at every call site.
 *
 * @param array $posted Parsed checkout form.
 * @return array
 */
function prime_armada_address_from_posted( $posted ) {
	$get = static function ( $key ) use ( $posted ) {
		return isset( $posted[ $key ] ) ? sanitize_text_field( wp_unslash( $posted[ $key ] ) ) : '';
	};

	$type = sanitize_key( $posted['prime_address_type'] ?? 'house' );
	$area = $get( 'billing_area' );

	// "Other" is a marker, not a place — send what the customer typed.
	if ( '__other__' === $area ) {
		$area = $get( 'prime_area_other' );
	}

	return array(
		'contact_name'  => $get( 'billing_first_name' ),
		'contact_phone' => $get( 'billing_phone' ),
		'area'          => $area,
		'block'         => $get( 'prime_block' ),
		'street'        => $get( 'prime_street' ),
		// House and apartment share one building field; only floor and flat
		// are extra, and they simply arrive empty for a house.
		'building'      => $get( 'prime_house_no' ),
		'floor'         => $get( 'prime_floor' ),
		'apartment'     => $get( 'prime_apartment_no' ),
		'instructions'  => $get( 'prime_house_notes' ),
	);
}
add_action( 'woocommerce_checkout_update_order_review', 'prime_remember_checkout_address_type' );

/**
 * Put the address type on each shipping package.
 *
 * Two jobs: it's how Prime_Governorate_Shipping::calculate_shipping() reads
 * the type, and — because WooCommerce keys its shipping-rate cache on a hash
 * of the whole package array — switching between Gift and House/Apartment
 * with the same (empty) governorate produces a different hash, so the gift
 * placeholder rate can never be served from cache to a house order, or vice
 * versa.
 *
 * @param array[] $packages Shipping packages.
 * @return array[]
 */
function prime_shipping_package_address_type( $packages ) {
	$type = isset( $_POST['prime_address_type'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce; this only selects which rate applies.
		? prime_current_address_type()
		: ( WC()->session ? (string) WC()->session->get( 'prime_address_type', 'house' ) : 'house' );

	// The full address too, so Armada can be asked what this exact drop-off
	// costs. It also lands in the package hash WooCommerce keys its rate cache
	// on, so editing the address re-quotes instead of reusing the old fee.
	$address = isset( $_POST['billing_area'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce; this only selects which rate applies.
		? prime_armada_address_from_posted( wp_unslash( $_POST ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised per field inside.
		: ( WC()->session ? (array) WC()->session->get( 'prime_armada_address', array() ) : array() );

	foreach ( $packages as &$package ) {
		$package['prime_address_type']   = $type;
		$package['prime_armada_address'] = $address;
	}
	unset( $package );

	return $packages;
}
add_filter( 'woocommerce_cart_shipping_packages', 'prime_shipping_package_address_type' );

