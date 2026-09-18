<?php
/**
 * Armada Delivery — live delivery-fee quotes.
 *
 * Armada prices by road distance from the shop, not by governorate (their
 * published table runs 1.5 KD at 0–15 km up to 6 KD at 45–60 km, then 6 KD +
 * 250 fils/km beyond). A per-governorate table can't express that: Al Ahmadi
 * alone spans several bands. So rather than approximating it, the shipping
 * method asks Armada what this specific address costs.
 *
 * `/v2/deliveries/estimate/static` is used rather than `/estimate` on purpose:
 * "static pricing (no live traffic)" returns the published table, so the same
 * address always quotes the same fee. The live-traffic endpoint can move
 * between the moment a customer reads the total and the moment they pay.
 *
 * Nothing here can block a sale. Every failure path — no credentials, a
 * timeout, a bad response, an address too incomplete to quote — returns a
 * WP_Error and the caller falls back to the per-governorate table in
 * inc/checkout-data.php.
 *
 * Credentials live in WooCommerce → Settings → Shipping → Kuwait → Delivery,
 * entered by Reem, stored in the options table. They are deliberately not in
 * this file or anywhere in the repository.
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

/**
 * Base URL for an environment.
 *
 * Test-mode keys work against production and simulate the full delivery
 * lifecycle without dispatching a driver, so sandbox is only needed for a
 * fully separate merchant account.
 *
 * @param string $environment 'production' or 'sandbox'.
 * @return string
 */
function prime_armada_base_url( $environment ) {
	return 'sandbox' === $environment
		? 'https://sandbox.api.armadadelivery.com'
		: 'https://api.armadadelivery.com';
}

/**
 * Sign and send one request.
 *
 * Armada signs `"{timestamp}.{method}.{path}.{body}"` with the merchant
 * secret, and rejects a signature older than 30 seconds. The exact body string
 * that is signed has to be the one that is sent, so it is encoded once here
 * and reused rather than re-encoded per use.
 *
 * @param string $method      HTTP method.
 * @param string $path        Path beginning with a slash, e.g. /v2/deliveries/estimate/static.
 * @param array  $payload     Request body, JSON-encoded here.
 * @param array  $credentials key, secret, environment.
 * @return array|WP_Error Decoded response body, or WP_Error.
 */
function prime_armada_request( $method, $path, $payload, $credentials ) {
	if ( empty( $credentials['key'] ) || empty( $credentials['secret'] ) ) {
		return new WP_Error( 'prime_armada_no_credentials', __( 'Armada API credentials are not set.', 'prime-printing' ) );
	}

	$body      = $payload ? wp_json_encode( $payload ) : '';
	$timestamp = (string) round( microtime( true ) * 1000 );
	$signature = hash_hmac( 'sha256', $timestamp . '.' . $method . '.' . $path . '.' . $body, $credentials['secret'] );

	$response = wp_remote_request(
		prime_armada_base_url( $credentials['environment'] ) . $path,
		array(
			'method'  => $method,
			// Short on purpose: this runs inside the checkout's shipping
			// recalculation, so a slow Armada must not stall the page. The
			// caller falls back to the rate table well before a customer
			// notices.
			'timeout' => 5,
			'headers' => array(
				'Authorization'     => 'Key ' . $credentials['key'],
				'x-armada-timestamp' => $timestamp,
				'x-armada-signature' => $signature,
				'Content-Type'      => 'application/json',
				'Accept'            => 'application/json',
			),
			'body'    => $body,
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code   = wp_remote_retrieve_response_code( $response );
	$parsed = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( $code < 200 || $code >= 300 ) {
		return new WP_Error(
			'prime_armada_http_' . $code,
			isset( $parsed['message'] ) ? $parsed['message'] : sprintf(
				/* translators: %d: HTTP status code. */
				__( 'Armada returned HTTP %d.', 'prime-printing' ),
				$code
			),
			$parsed
		);
	}

	if ( ! is_array( $parsed ) ) {
		return new WP_Error( 'prime_armada_bad_json', __( 'Armada returned a response that could not be read.', 'prime-printing' ) );
	}

	return $parsed;
}

/**
 * The shop's pickup point, as the estimate's origin.
 *
 * Prefers a branch id when one is configured (Armada then uses the exact
 * pickup point registered on their side); otherwise sends the shop's
 * coordinates, which default to the real Al-Dajeej location already used for
 * the pickup map on the order-confirmation page.
 *
 * @param array $settings Shipping method settings.
 * @return array{origin_format: string, origin: array}
 */
function prime_armada_origin( $settings ) {
	$branch_id = isset( $settings['armada_branch_id'] ) ? trim( (string) $settings['armada_branch_id'] ) : '';

	if ( $branch_id ) {
		return array(
			'origin_format' => 'branch_format',
			'origin'        => array( 'branch_id' => $branch_id ),
		);
	}

	$store_name  = get_bloginfo( 'name' );
	$store_phone = get_theme_mod( 'prime_contact_phone', '' );

	return array(
		'origin_format' => 'location_format',
		'origin'        => array(
			'contact_name'  => $store_name ? $store_name : 'Prime Printing Co.',
			'contact_phone' => $store_phone ? preg_replace( '/\D/', '', $store_phone ) : '',
			'latitude'      => (float) ( $settings['armada_origin_lat'] ?? 29.2631007 ),
			'longitude'     => (float) ( $settings['armada_origin_lng'] ?? 47.9655069 ),
			'first_line'    => 'Al-Dajeej, Block 1, Street 79, Dasman Mall',
		),
	);
}

/**
 * Turn the checkout's own fields into the destination Armada will accept.
 *
 * Armada publishes a `kuwait_format` destination — area, block, street,
 * building, exactly what this checkout collects — and it is documented as
 * valid on both estimate endpoints. It is not: as deployed on 2026-09-05 this
 * merchant account answered every `kuwait_format` request with
 * `400 destination_format: unsupported format`, on `/estimate` and
 * `/estimate/static` alike. The same address sent as `location_format`
 * (latitude/longitude) quotes normally.
 *
 * So the coordinates of the customer's *area* are sent, with the full written
 * address as `first_line`. Armada prices in 15 km distance bands, and a Kuwait
 * area is a couple of kilometres across, so an area centroid lands in the same
 * band as the exact building in every practical case. The written address is
 * still what a driver would read — and dispatch happens in PrimeFlow, not
 * here, so this call only ever produces a price.
 *
 * Returns null when the address is not complete enough to quote (the normal
 * state for most of a checkout session) or when neither the area nor its
 * governorate has a known location, in which case the caller falls back to
 * the per-governorate table.
 *
 * @param array $address Address parts stamped onto the shipping package,
 *                        plus 'governorate' — the governorate code, only used
 *                        as a fallback when the area itself (typically a
 *                        customer-typed "Other" name) isn't in the table.
 * @return array{destination_format: string, destination: array}|null
 */
function prime_armada_destination( $address ) {
	/*
	 * Only contact info and the area are actually required. Block, street and
	 * building used to be required too, which meant no real quote could be
	 * fetched until a customer had typed their entire address — so the
	 * checkout showed the generic per-governorate placeholder rate first
	 * (inc/checkout-data.php's flat table; 3 KD for all of Al Jahra), then
	 * jumped to the real, much higher, area-specific figure once those last
	 * fields were filled in. Reem saw this as "the delivery price changed
	 * when I finished the address" (2026-09-07) and it should not — the price
	 * is decided entirely by the area's coordinate (see prime_area_coordinates()
	 * and this file's own docblock on Armada's distance bands), so it is
	 * fetched, and locked, as soon as the area is chosen. Block/street/building
	 * still travel in `first_line` for Armada's own records once known, but a
	 * blank one here no longer blocks getting the real quote — it does not
	 * change what that quote comes back as.
	 */
	$required = array( 'contact_name', 'contact_phone', 'area' );

	foreach ( $required as $key ) {
		if ( empty( $address[ $key ] ) ) {
			return null;
		}
	}

	$coordinates = prime_area_coordinates( $address['area'] );

	if ( ! $coordinates && ! empty( $address['governorate'] ) ) {
		$coordinates = prime_governorate_coordinates( $address['governorate'] );
	}

	if ( ! $coordinates ) {
		return null;
	}

	$written = sprintf(
		/* translators: 1: area, 2: block, 3: street, 4: building. */
		__( '%1$s, Block %2$s, Street %3$s, Building %4$s', 'prime-printing' ),
		$address['area'],
		$address['block'] ?: '—',
		$address['street'] ?: '—',
		$address['building'] ?: '—'
	);

	$destination = array(
		'contact_name'  => $address['contact_name'],
		'contact_phone' => preg_replace( '/\D/', '', $address['contact_phone'] ),
		'latitude'      => $coordinates[0],
		'longitude'     => $coordinates[1],
		'first_line'    => $written,
	);

	foreach ( array( 'floor', 'apartment', 'instructions' ) as $optional ) {
		if ( ! empty( $address[ $optional ] ) ) {
			$destination[ $optional ] = $address[ $optional ];
		}
	}

	return array(
		'destination_format' => 'location_format',
		'destination'        => $destination,
	);
}

/**
 * The centroid of a Kuwait area, for pricing a delivery by road distance.
 *
 * Armada's own `kuwait_format` destination (area/block/street/building) is
 * documented as valid on the estimate endpoints and, as tested live on
 * 2026-09-05, is not — this merchant account rejects it with
 * `400 destination_format: unsupported format`. The same address sent as
 * coordinates quotes correctly, so every area this checkout offers
 * (inc/checkout-data.php) is geocoded once here rather than calling a mapping
 * API on every checkout. Armada's published pricing bands are 15 km wide and
 * a Kuwait area is a couple of kilometres across, so a centroid always lands
 * in the same band as the exact building.
 *
 * Areas hidden from the checkout (prime_hidden_areas()) are geocoded anyway —
 * a hidden area can still arrive as admin-entered or via "Other" matching an
 * existing name — so this list intentionally does not filter against the
 * hidden set.
 *
 * "Other" (a customer-typed area name with no match here) and the six
 * governorate centroids it falls back to are handled by the caller in
 * prime_armada_destination().
 *
 * @param string $area Area name exactly as prime_governorates_all() spells it.
 * @return array{0: float, 1: float}|null [lat, lng], or null if unknown.
 */
function prime_area_coordinates( $area ) {
	static $coordinates = array(
		// Al Asimah
		'Abdullah Al-Salem' => array( 29.352838, 47.982797 ),
		'Adailiya' => array( 29.326254, 47.981843 ),
		'Bneid Al Qar' => array( 29.375232, 48.000566 ),
		'Daiya' => array( 29.360443, 48.016365 ),
		'Dasma' => array( 29.365699, 48.001924 ),
		'Dasman' => array( 29.387344, 47.999412 ),
		'Doha' => array( 29.32388, 47.793266 ),
		'Faiha' => array( 29.338664, 47.978913 ),
		'Ghornata' => array( 29.312907, 47.877935 ),
		'Jaber Al Ahmad' => array( 29.342458, 47.760022 ),
		'Kaifan' => array( 29.340629, 47.959201 ),
		'Khaldiya' => array( 29.32515, 47.965099 ),
		'Kuwait City' => array( 29.379653, 47.973417 ),
		'Mansouriya' => array( 29.357698, 47.995719 ),
		'Mirqab' => array( 29.36866, 47.985082 ),
		'Mubarakiya Camps' => array( 29.37635, 47.973962 ),
		'Nahda' => array( 29.30315, 47.859143 ),
		'North West Sulaibikhat' => array( 29.328167, 47.806892 ),
		'Nuzha' => array( 29.341631, 47.99151 ),
		'Qadsiya' => array( 29.348632, 48.003878 ),
		'Qibla' => array( 29.379653, 47.973417 ),
		'Qortuba' => array( 29.313404, 47.986269 ),
		'Rawda' => array( 29.33007, 47.998402 ),
		'Salhiya' => array( 29.367476, 47.970542 ),
		'Shamiya' => array( 29.351557, 47.965809 ),
		'Sharq' => array( 29.38214, 47.984906 ),
		'Shuwaikh' => array( 29.353325, 47.952082 ),
		'Shuwaikh Administrative' => array( 29.353325, 47.952082 ),
		'Shuwaikh Educational' => array( 29.334385, 47.916452 ),
		'Shuwaikh Industrial 1' => array( 29.335891, 47.939086 ),
		'Shuwaikh Industrial 2' => array( 29.324131, 47.947449 ),
		'Shuwaikh Industrial 3' => array( 29.322906, 47.929749 ),
		'Shuwaikh Medical' => array( 29.322668, 47.894864 ),
		'Shuwaikh Residential' => array( 29.35441, 47.956379 ),
		'Sulaibikhat' => array( 29.316544, 47.845782 ),
		'Surra' => array( 29.314499, 48.006962 ),
		'Yarmouk' => array( 29.311201, 47.969491 ),
		// Hawalli
		'Al-Bedae' => array( 29.291124, 48.037852 ),
		'Bayan' => array( 29.297513, 48.047848 ),
		'Hawally' => array( 29.337868, 48.023551 ),
		'Hitteen' => array( 29.283523, 48.020395 ),
		'Jabriya' => array( 29.318924, 48.032238 ),
		'Maidan Hawally' => array( 29.291124, 48.037852 ),
		'Ministries Zone' => array( 29.271436, 48.019306 ),
		'Mishrif' => array( 29.279305, 48.069841 ),
		'Mubarak Al-Abdullah - West Mishref' => array( 29.291124, 48.037852 ),
		'Rumaithiya' => array( 29.315135, 48.070535 ),
		'Salam' => array( 29.296782, 48.014581 ),
		'Salmiya' => array( 29.332783, 48.068488 ),
		'Salwa' => array( 29.289286, 48.080736 ),
		'Shaab' => array( 29.351289, 48.025153 ),
		'Shuhada' => array( 29.272119, 48.03072 ),
		'Siddiq' => array( 29.294623, 47.992465 ),
		'Zahra' => array( 29.275588, 47.999768 ),
		// Al Farwaniya
		'Abdullah Al-Mubarak' => array( 29.245992, 47.909195 ),
		'Airport' => array( 29.220268, 47.955156 ),
		// Andalous and Reggai were sharing the Airport's point (2026-09-07
		// audit); re-geocoded from their Arabic names — same 0–15 km band, so
		// no fee changed, but the table now says where they actually are.
		'Andalous' => array( 29.303102, 47.885202 ),
		'Ardhiya' => array( 29.286978, 47.893979 ),
		'Ardhiya 4' => array( 29.277089, 47.916962 ),
		'Ardiya Small Industrial' => array( 29.289342, 47.894669 ),
		'Ardiya Storage Zone' => array( 29.289342, 47.894669 ),
		'Ashbeliah' => array( 29.274058, 47.939092 ),
		'Dhajeej' => array( 29.261262, 47.962294 ),
		'Farwaniya' => array( 29.278658, 47.958954 ),
		'Ferdous' => array( 29.283319, 47.874774 ),
		'Jeleeb Al-Shuyoukh' => array( 29.220268, 47.955156 ),
		'Khaitan' => array( 29.28531, 47.975938 ),
		'Omariya' => array( 29.295513, 47.955802 ),
		'Rabiya' => array( 29.2957, 47.937293 ),
		'Rai' => array( 29.308948, 47.944561 ),
		'Reggai' => array( 29.305746, 47.916026 ),
		'Rehab' => array( 29.284365, 47.93872 ),
		'Sabah Al-Nasser' => array( 29.271254, 47.885977 ),
		'Sheikh Saad Al Abdullah Airport' => array( 29.220268, 47.955156 ),
		// Al Ahmadi
		'Abu Halifa' => array( 29.128701, 48.125579 ),
		'Al-Ahmadi' => array( 29.089069, 48.061024 ),
		'Al-Julaiaa' => array( 29.089069, 48.061024 ),
		'Ali Sabah Al-Salem - Umm Al Hayman' => array( 28.963858, 48.160733 ),
		'Bnaider' => array( 28.822483, 48.226507 ),
		'Dhaher' => array( 29.164608, 48.065312 ),
		'Egaila' => array( 29.170064, 48.101921 ),
		'Fahad Al Ahmed' => array( 29.089069, 48.061024 ),
		'Fahaheel' => array( 29.081324, 48.127512 ),
		'Fintas' => array( 29.171771, 48.117815 ),
		'Hadiya' => array( 29.144576, 48.091939 ),
		'Jaber Al Ali' => array( 29.167717, 48.083037 ),
		'Khairan' => array( 29.089069, 48.061024 ),
		'Magwa' => array( 29.173108, 47.989215 ),
		'Mahboula' => array( 29.149022, 48.12101 ),
		'Mangaf' => array( 29.105911, 48.128144 ),
		'Mina Abdullah' => array( 28.940393, 48.072277 ),
		'Nuwaiseeb' => array( 28.562071, 48.407157 ),
		'Riqqa' => array( 29.147364, 48.105693 ),
		'Sabah AL Ahmad residential' => array( 29.089069, 48.061024 ),
		'Sabah Al Ahmad Marine City' => array( 28.650973, 48.332527 ),
		'Sabahiya' => array( 29.107113, 48.106954 ),
		'Shuaiba Port' => array( 29.045615, 48.123115 ),
		'South Sabahiya' => array( 29.077125, 48.10628 ),
		'Wafra farms' => array( 28.606983, 48.02191 ),
		'Wafra residential' => array( 28.607679, 48.020843 ),
		'Zour' => array( 28.712717, 48.336368 ),
		// Al Jahra
		'Al Naeem' => array( 29.329941, 47.704936 ),
		/*
		 * These nine areas shared one wrong coordinate until 2026-09-07 —
		 * (29.520518, 47.306211), which reverse-geocodes to a vague Jahra
		 * Governorate boundary point 70 km from the shop, not any of these
		 * actual neighbourhoods. It priced a real Alqairawan delivery at
		 * 12.750 KD when Armada's own app quotes 3 KD for the same drop-off
		 * (Reem, 2026-09-07, with a screenshot of Armada's own estimate).
		 * Re-geocoded individually below; each is now 15-20 km from the shop,
		 * matching that evidence instead of contradicting it.
		 */
		'Alnasseem' => array( 29.322352, 47.671328 ),
		'Aloyoun' => array( 29.325324, 47.647819 ),
		'Alqairawan' => array( 29.303155, 47.801948 ),
		'Alqasr' => array( 29.336233, 47.699368 ),
		'Alsulaibiya' => array( 29.293355, 47.810831 ),
		'Alsulaibiya Industrial 1' => array( 29.279906, 47.841207 ),
		'Alsulaibiya Industrial 2' => array( 29.280189, 47.85391 ),
		'Alwaha' => array( 29.344094, 47.656207 ),
		'Jahra Amgarah Industrial' => array( 29.31194, 47.74915 ),
		'Jahra Area' => array( 29.347464, 47.672205 ),
		'Kabd' => array( 29.102708, 47.744282 ),
		'Saad Al Abdullah' => array( 29.30995, 47.720734 ),
		'Taima' => array( 29.327858, 47.680923 ),
		// Mubarak Al-Kabeer
		'Abu Ftaira' => array( 29.217374, 48.039347 ),
		'Abu Hasaniya' => array( 29.193411, 48.115013 ),
		'Adan' => array( 29.223016, 48.066088 ),
		'Al Masayel' => array( 29.238148, 48.089325 ),
		'Al-Qurain' => array( 29.225393, 48.073074 ),
		'Al-Qusour' => array( 29.216065, 48.073475 ),
		'Fnaitess' => array( 29.217374, 48.039347 ),
		'Messila' => array( 29.217374, 48.039347 ),
		'Mubarak Al-Kabir' => array( 29.226521, 47.999856 ),
		'Sabah Al-Salem' => array( 29.253876, 48.067745 ),
		'Sabhan Industrial' => array( 29.229681, 48.010234 ),
		'South Wista' => array( 29.217374, 48.039347 ),
		'West Abu Fetera Small Indust' => array( 29.217374, 48.039347 ),
		'Wista' => array( 29.219123, 48.027801 ),
	);

	return $coordinates[ $area ] ?? null;
}

/**
 * The centroid of a Kuwait governorate — the fallback when a customer typed a
 * free-text "Other" area name that matches nothing in prime_area_coordinates().
 *
 * @param string $code Governorate code (see prime_governorates_all()).
 * @return array{0: float, 1: float}|null
 */
function prime_governorate_coordinates( $code ) {
	static $coordinates = array(
		'AS' => array( 29.379653, 47.973417 ), // Al Asimah.
		'HA' => array( 29.291124, 48.037852 ), // Hawalli.
		'FA' => array( 29.220268, 47.955156 ), // Al Farwaniya.
		'AH' => array( 29.089069, 48.061024 ), // Al Ahmadi.
		'JA' => array( 29.520518, 47.306211 ), // Al Jahra.
		'MU' => array( 29.217374, 48.039347 ), // Mubarak Al-Kabeer.
	);

	return $coordinates[ $code ] ?? null;
}

/**
 * The delivery fee for one address, in KWD.
 *
 * Quotes are cached for an hour against a hash of the exact address and
 * origin: Armada's static pricing is a fixed distance table, so the same
 * address quotes the same fee, and a customer editing unrelated checkout
 * fields shouldn't trigger a fresh call on every keystroke.
 *
 * @param array $address  Address parts (see prime_armada_destination()).
 * @param array $settings Shipping method settings.
 * @return float|WP_Error Fee in KWD, or WP_Error explaining why not.
 */
function prime_armada_estimate( $address, $settings ) {
	if ( 'yes' !== ( $settings['armada_enabled'] ?? 'no' ) ) {
		return new WP_Error( 'prime_armada_disabled', __( 'Armada live pricing is switched off.', 'prime-printing' ) );
	}

	$destination = prime_armada_destination( $address );

	if ( ! $destination ) {
		return new WP_Error( 'prime_armada_incomplete_address', __( 'Not enough of the address has been entered to price a delivery yet.', 'prime-printing' ) );
	}

	$credentials = array(
		'key'         => trim( (string) ( $settings['armada_api_key'] ?? '' ) ),
		'secret'      => trim( (string) ( $settings['armada_api_secret'] ?? '' ) ),
		'environment' => $settings['armada_environment'] ?? 'production',
	);

	$origin  = prime_armada_origin( $settings );
	$payload = array_merge( $origin, $destination );

	$cache_key = 'prime_armada_' . md5( wp_json_encode( $payload ) . $credentials['environment'] );
	$cached    = get_transient( $cache_key );

	if ( false !== $cached ) {
		return (float) $cached;
	}

	/*
	 * Static pricing is asked for first, and the live-traffic endpoint is only
	 * tried if the first one rejects the request outright.
	 *
	 * Static pricing is preferred because it returns Armada's published
	 * distance table, so the same address always quotes the same fee — a
	 * live-traffic quote can move between the moment a customer reads the
	 * total and the moment they pay.
	 *
	 * The two endpoints are documented as identical in what they accept, and
	 * on 2026-09-05 they were not: one rejected a destination format the other
	 * took. Trying both in preference order means a difference like that costs
	 * a customer nothing. The log records when the second one was needed.
	 */
	$endpoints = array( '/v2/deliveries/estimate/static', '/v2/deliveries/estimate' );
	$response  = null;

	foreach ( $endpoints as $index => $endpoint ) {
		$response = prime_armada_request( 'POST', $endpoint, $payload, $credentials );

		if ( ! is_wp_error( $response ) ) {
			if ( $index > 0 ) {
				prime_armada_log( 'Quoted from ' . $endpoint . ' — the preferred static endpoint refused this request.', null );
			}

			break;
		}

		prime_armada_log( 'Estimate failed on ' . $endpoint . ': ' . $response->get_error_message(), $address );
	}

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	/*
	 * Armada's live API answers with `deliveryFee` even though the published
	 * OpenAPI spec for this endpoint calls the field `fee` (checked against a
	 * real response on 2026-09-05: {"deliveryFee":4,"totalDeliveryDuration":…}).
	 * Both are accepted so a corrected API doesn't break the integration.
	 */
	$fee = null;

	foreach ( array( 'deliveryFee', 'fee', 'delivery_fee' ) as $key ) {
		if ( isset( $response[ $key ] ) && is_numeric( $response[ $key ] ) ) {
			$fee = (float) $response[ $key ];
			break;
		}
	}

	if ( null === $fee ) {
		prime_armada_log( 'Estimate response had no usable fee.', $response );

		return new WP_Error( 'prime_armada_no_fee', __( 'Armada did not return a delivery fee.', 'prime-printing' ) );
	}

	set_transient( $cache_key, $fee, HOUR_IN_SECONDS );

	return $fee;
}

/**
 * Write to WooCommerce's own log (WooCommerce → Status → Logs, source
 * "prime-armada") rather than error_log, so Reem can read why a quote fell
 * back without server access.
 *
 * @param string $message Message.
 * @param mixed  $context Extra detail, JSON-encoded.
 */
function prime_armada_log( $message, $context = null ) {
	if ( ! function_exists( 'wc_get_logger' ) ) {
		return;
	}

	if ( null !== $context ) {
		$message .= ' — ' . wp_json_encode( $context );
	}

	wc_get_logger()->warning( $message, array( 'source' => 'prime-armada' ) );
}
