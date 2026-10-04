<?php
/**
 * Delivery pin — the customer's exact location on a map (Reem, 2026-10-04).
 *
 * At checkout the address section carries a map (Leaflet over OpenStreetMap
 * tiles, with a satellite layer from Esri) centred on the phone's position,
 * or on the chosen area when there is no GPS, with a pin the customer drags
 * to their door. The pin is saved on the order as latitude, longitude, the
 * accuracy the phone reported, and a ready Google Maps link, which is what
 * the driver taps (admin order screen, the shop's order email, the invoice,
 * and the order meta PrimeFlow reads).
 *
 * Required for house and apartment deliveries; skipped for pickup orders
 * and optional for gifts (the buyer may not know the recipient's spot). The
 * pin is also kept on the customer's saved address, so it is already there
 * on the next order.
 *
 * The fields are registered as billing_* checkout fields on purpose:
 * WooCommerce then saves them to the customer and the order by itself and
 * prefills them from the saved address, exactly like any other billing field.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

const PRIME_GEO_FIELDS = array( 'billing_geo_lat', 'billing_geo_lng', 'billing_geo_acc' );

/**
 * Kuwait, for a sane first view when nothing else is known.
 *
 * @return array{lat: float, lng: float, zoom: int}
 */
function prime_geo_default_view() {
	return array(
		'lat'  => 29.3117,
		'lng'  => 47.9311,
		'zoom' => 10,
	);
}

/**
 * The three hidden fields that carry the pin.
 *
 * @param array $fields Flat billing field definitions.
 * @return array
 */
function prime_geo_billing_fields( $fields ) {
	foreach ( PRIME_GEO_FIELDS as $i => $key ) {
		$fields[ $key ] = array(
			'type'     => 'hidden',
			'required' => false,
			'priority' => 57 + $i,
			'class'    => array( 'prime-geo-field' ),
		);
	}

	return $fields;
}
add_filter( 'woocommerce_billing_fields', 'prime_geo_billing_fields', 20 );

/**
 * woocommerce_form_field() has no 'hidden' type of its own: render one.
 *
 * @param string $field Field html so far (empty for an unknown type).
 * @param string $key   Field key.
 * @param array  $args  Field args.
 * @param mixed  $value Current value.
 * @return string
 */
function prime_geo_hidden_field( $field, $key, $args, $value ) {
	if ( '' !== trim( (string) $field ) ) {
		return $field;
	}

	return sprintf( '<input type="hidden" name="%1$s" id="%1$s" value="%2$s">', esc_attr( $key ), esc_attr( (string) $value ) );
}
add_filter( 'woocommerce_form_field_hidden', 'prime_geo_hidden_field', 10, 4 );

/**
 * Checkout's nested shape.
 *
 * @param array $fields Checkout fields.
 * @return array
 */
function prime_geo_checkout_fields( $fields ) {
	$fields['billing'] = prime_geo_billing_fields( $fields['billing'] );

	return $fields;
}
add_filter( 'woocommerce_checkout_fields', 'prime_geo_checkout_fields', 20 );

/**
 * A Google Maps link for a pin.
 *
 * @param float $lat Latitude.
 * @param float $lng Longitude.
 * @return string
 */
function prime_geo_maps_link( $lat, $lng ) {
	return sprintf( 'https://www.google.com/maps?q=%s,%s', number_format( (float) $lat, 6, '.', '' ), number_format( (float) $lng, 6, '.', '' ) );
}

/**
 * Read a posted pin, if it is a real Kuwait coordinate.
 *
 * @param array $src Posted data.
 * @return array{lat: float, lng: float, acc: float}|null
 */
function prime_geo_read( array $src ) {
	$lat = isset( $src['billing_geo_lat'] ) ? (float) $src['billing_geo_lat'] : 0;
	$lng = isset( $src['billing_geo_lng'] ) ? (float) $src['billing_geo_lng'] : 0;
	$acc = isset( $src['billing_geo_acc'] ) ? (float) $src['billing_geo_acc'] : 0;

	// Kuwait and a margin around it; anything else is a stray value.
	if ( $lat < 28.3 || $lat > 30.2 || $lng < 46.3 || $lng > 49.0 ) {
		return null;
	}

	return array(
		'lat' => round( $lat, 6 ),
		'lng' => round( $lng, 6 ),
		'acc' => max( 0, round( $acc ) ),
	);
}

/**
 * Is the chosen shipping method local pickup?
 *
 * @return bool
 */
function prime_geo_checkout_is_pickup() {
	if ( ! function_exists( 'WC' ) || ! WC()->session ) {
		return false;
	}
	foreach ( (array) WC()->session->get( 'chosen_shipping_methods', array() ) as $method ) {
		if ( 0 === strpos( (string) $method, 'local_pickup' ) ) {
			return true;
		}
	}

	return false;
}

/**
 * The pin is required for a delivery to a house or apartment.
 */
function prime_geo_validate_checkout() {
	$type = prime_current_address_type();
	if ( 'gift' === $type || prime_geo_checkout_is_pickup() ) {
		return;
	}
	if ( ! prime_geo_read( wp_unslash( $_POST ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- numeric fields, range-checked; WooCommerce verifies the checkout nonce before this hook.
		wc_add_notice( __( 'Pin your delivery location on the map so the driver can find you.', 'prime-printing' ), 'error' );
	}
}
add_action( 'woocommerce_after_checkout_validation', 'prime_geo_validate_checkout', 20 );

/**
 * The pin on the order, in the keys the driver's tools read.
 *
 * @param WC_Order $order Order being created.
 */
function prime_geo_save_checkout_fields( $order ) {
	$pin = prime_geo_read( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- numeric fields, range-checked; WooCommerce verifies the checkout nonce before this hook.
	if ( ! $pin ) {
		return;
	}
	$order->update_meta_data( '_delivery_lat', $pin['lat'] );
	$order->update_meta_data( '_delivery_lng', $pin['lng'] );
	$order->update_meta_data( '_delivery_accuracy_m', $pin['acc'] );
	$order->update_meta_data( '_delivery_maps_link', prime_geo_maps_link( $pin['lat'], $pin['lng'] ) );
}
add_action( 'woocommerce_checkout_create_order', 'prime_geo_save_checkout_fields', 20 );

/**
 * An order's pin, if it has one.
 *
 * @param WC_Order $order Order.
 * @return array{lat: float, lng: float, acc: float, link: string}|null
 */
function prime_geo_order_pin( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return null;
	}
	$lat  = (float) $order->get_meta( '_delivery_lat' );
	$lng  = (float) $order->get_meta( '_delivery_lng' );
	$link = (string) $order->get_meta( '_delivery_maps_link' );
	if ( ! $lat && ! $lng ) {
		return $link ? array( 'lat' => 0, 'lng' => 0, 'acc' => 0, 'link' => $link ) : null;
	}

	return array(
		'lat'  => $lat,
		'lng'  => $lng,
		'acc'  => (float) $order->get_meta( '_delivery_accuracy_m' ),
		'link' => $link ? $link : prime_geo_maps_link( $lat, $lng ),
	);
}

/* ------------------------------------------------------------ the map UI */

/**
 * Where a map should start for an area or governorate: the same centroids
 * the delivery quote uses (inc/armada.php).
 *
 * @return array{areas: array<string, array{0: float, 1: float}>, governorates: array<string, array{0: float, 1: float}>}
 */
function prime_geo_centroids() {
	$areas = array();
	$govs  = array();
	foreach ( prime_governorates() as $code => $gov ) {
		$g = function_exists( 'prime_governorate_coordinates' ) ? prime_governorate_coordinates( $code ) : null;
		if ( $g ) {
			$govs[ $code ] = array( (float) $g[0], (float) $g[1] );
		}
		foreach ( (array) ( $gov['areas'] ?? array() ) as $area ) {
			$c = function_exists( 'prime_area_coordinates' ) ? prime_area_coordinates( $area ) : null;
			if ( $c ) {
				$areas[ $area ] = array( (float) $c[0], (float) $c[1] );
			}
		}
	}

	return array(
		'areas'        => $areas,
		'governorates' => $govs,
	);
}

/**
 * The map block. Printed inside the checkout address section and on the
 * account's address page; the script turns it into a map.
 *
 * @param array $pin   Saved pin to show, if any (lat, lng, acc).
 * @param bool  $hidden Whether it starts hidden (gift).
 */
function prime_geo_render_map( array $pin = array(), $hidden = false ) {
	$has = ! empty( $pin['lat'] ) && ! empty( $pin['lng'] );
	?>
	<div data-prime-locate-wrap class="prime-geo <?php echo $hidden ? 'is-hidden' : ''; ?>">
		<div class="prime-geo__head">
			<div>
				<div class="prime-locate__t1"><?php esc_html_e( 'Pin your delivery location', 'prime-printing' ); ?></div>
				<div class="prime-locate__t2" data-prime-loc-status>
					<?php echo $has ? esc_html__( 'Pinned from your saved address — drag to adjust.', 'prime-printing' ) : esc_html__( 'Drag the pin to your door, or use your current location.', 'prime-printing' ); ?>
				</div>
			</div>
			<button type="button" class="prime-locbtn" data-prime-geo-locate>
				<span aria-hidden="true">◎</span><span><?php esc_html_e( 'My location', 'prime-printing' ); ?></span>
			</button>
		</div>
		<div class="prime-geo__map" data-prime-geo-map></div>
		<div class="prime-geo__foot">
			<label class="prime-geo__sat"><input type="checkbox" data-prime-geo-satellite> <?php esc_html_e( 'Satellite view', 'prime-printing' ); ?></label>
			<a class="prime-geo__link" data-prime-geo-link href="<?php echo $has ? esc_url( prime_geo_maps_link( $pin['lat'], $pin['lng'] ) ) : '#'; ?>" target="_blank" rel="noopener" <?php echo $has ? '' : 'hidden'; ?>><?php esc_html_e( 'Open in Google Maps', 'prime-printing' ); ?></a>
		</div>
	</div>
	<?php
}

/**
 * What the script needs: tiles, centroids, strings, the saved pin.
 *
 * @param array $pin Saved pin (lat, lng, acc), if any.
 * @return array
 */
function prime_geo_script_config( array $pin = array() ) {
	return array(
		'tiles'      => array(
			'street'    => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
			'streetAttr' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
			'satellite' => 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
			'satAttr'   => 'Imagery &copy; Esri',
		),
		'icons'      => PRIME_URI . '/assets/vendor/leaflet/images/',
		'default'    => prime_geo_default_view(),
		'centroids'  => prime_geo_centroids(),
		'pin'        => $pin,
		'strings'    => array(
			'locating'    => __( 'Finding your location…', 'prime-printing' ),
			'pinned'      => __( 'Pinned — drag to adjust if needed.', 'prime-printing' ),
			'pinnedGps'   => __( 'Pinned to your current location (±%s m) — drag to adjust.', 'prime-printing' ),
			'moved'       => __( 'Pin placed. Make sure it is on your door or gate.', 'prime-printing' ),
			'denied'      => __( 'Location access is off — drag the pin to your door instead.', 'prime-printing' ),
			'unsupported' => __( 'Location not available here — drag the pin to your door instead.', 'prime-printing' ),
			'areaHint'    => __( 'Map centred on your area — drag the pin to your door.', 'prime-printing' ),
		),
	);
}

/**
 * Leaflet and the picker, on checkout and on the account's address page.
 */
function prime_geo_enqueue() {
	$on_checkout = function_exists( 'is_checkout' ) && is_checkout() && ! is_wc_endpoint_url( 'order-received' ) && ! is_wc_endpoint_url( 'order-pay' );
	$on_address  = function_exists( 'is_account_page' ) && is_account_page() && is_wc_endpoint_url( 'edit-address' );
	if ( ! $on_checkout && ! $on_address ) {
		return;
	}

	if ( $on_address && wp_style_is( 'prime-checkout', 'registered' ) ) {
		wp_enqueue_style( 'prime-checkout' ); // The map block's styles live with checkout's.
	}
	wp_enqueue_style( 'prime-leaflet', PRIME_URI . '/assets/vendor/leaflet/leaflet.css', array(), '1.9.4' );
	wp_enqueue_script( 'prime-leaflet', PRIME_URI . '/assets/vendor/leaflet/leaflet.js', array(), '1.9.4', true );
	wp_enqueue_script( 'prime-delivery-map', PRIME_URI . '/assets/js/delivery-map.js', array( 'prime-leaflet' ), prime_asset_version( '/assets/js/delivery-map.js' ), true );

	$pin = array();
	if ( is_user_logged_in() ) {
		$customer = new WC_Customer( get_current_user_id() );
		$saved    = prime_geo_read(
			array(
				'billing_geo_lat' => $customer->get_meta( 'billing_geo_lat' ),
				'billing_geo_lng' => $customer->get_meta( 'billing_geo_lng' ),
				'billing_geo_acc' => $customer->get_meta( 'billing_geo_acc' ),
			)
		);
		if ( $saved ) {
			$pin = $saved;
		}
	}
	wp_localize_script( 'prime-delivery-map', 'primeDeliveryMap', prime_geo_script_config( $pin ) );
}
add_action( 'wp_enqueue_scripts', 'prime_geo_enqueue', 20 );

/**
 * The map on the account's address page, under the address fields.
 */
function prime_geo_account_map() {
	$pin      = array();
	$customer = new WC_Customer( get_current_user_id() );
	$saved    = prime_geo_read(
		array(
			'billing_geo_lat' => $customer->get_meta( 'billing_geo_lat' ),
			'billing_geo_lng' => $customer->get_meta( 'billing_geo_lng' ),
			'billing_geo_acc' => $customer->get_meta( 'billing_geo_acc' ),
		)
	);
	if ( $saved ) {
		$pin = $saved;
	}
	prime_geo_render_map( $pin, false );
}
add_action( 'woocommerce_after_edit_address_form_billing', 'prime_geo_account_map' );

/* ------------------------------------------------- where the driver sees it */

/**
 * The pin in the order emails, under the address: the shop's "new order"
 * email most of all, so whoever dispatches has the link in hand.
 *
 * @param WC_Order $order         Order.
 * @param bool     $sent_to_admin Whether this is the shop's copy.
 * @param bool     $plain_text    Plain-text email.
 */
function prime_geo_email_block( $order, $sent_to_admin, $plain_text ) {
	$pin = prime_geo_order_pin( $order );
	if ( ! $pin ) {
		return;
	}
	if ( $plain_text ) {
		echo "\n" . esc_html__( 'Delivery location:', 'prime-printing' ) . ' ' . esc_url( $pin['link'] ) . "\n";
		return;
	}
	printf(
		'<p style="margin:0 0 16px"><strong>%s</strong> <a href="%s">%s</a>%s</p>',
		esc_html__( 'Delivery location:', 'prime-printing' ),
		esc_url( $pin['link'] ),
		esc_html__( 'Open in Google Maps', 'prime-printing' ),
		$pin['acc'] ? ' <span style="color:#6b7280">(±' . esc_html( (int) $pin['acc'] ) . ' m)</span>' : ''
	);
}
add_action( 'woocommerce_email_after_order_table', 'prime_geo_email_block', 10, 3 );

/**
 * The pin's accuracy beside the link on the admin order screen (the link
 * itself is already shown by inc/checkout-admin.php).
 *
 * @param WC_Order $order Order.
 */
function prime_geo_admin_accuracy( $order ) {
	$pin = prime_geo_order_pin( $order );
	if ( ! $pin || ! $pin['lat'] ) {
		return;
	}
	printf(
		'<p class="description" style="clear:both">%s %s, %s%s</p>',
		esc_html__( 'Pin:', 'prime-printing' ),
		esc_html( number_format( $pin['lat'], 6, '.', '' ) ),
		esc_html( number_format( $pin['lng'], 6, '.', '' ) ),
		$pin['acc'] ? ' · ±' . esc_html( (int) $pin['acc'] ) . ' m' : ''
	);
}
add_action( 'woocommerce_admin_order_data_after_shipping_address', 'prime_geo_admin_accuracy', 20 );
