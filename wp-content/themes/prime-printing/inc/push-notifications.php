<?php
/**
 * Phase 13 — push notifications for the iOS app.
 *
 * The app (ios-app/, a Capacitor shell around this live site — see its own
 * capacitor.config.json) is a WebView, not a separate client with its own
 * API. So the flow is: a small bridge script (assets/js/native-app-bridge.js)
 * runs inside that WebView, asks Capacitor's native PushNotifications plugin
 * to register, and POSTs the resulting APNs device token to the REST route
 * this file defines. From there two things can trigger a push:
 *
 *   1. An order's status changes (`woocommerce_order_status_changed`) —
 *      pushed to that order's own customer only.
 *   2. Reem sends an announcement from wp-admin — pushed to every stored
 *      token (or her chosen platform/segment once one is added).
 *
 * Sending goes straight to Apple's provider API (APNs HTTP/2), not through
 * Firebase or any other relay (Reem's call, 2026-09-05) — one less account,
 * and this store's whole stack has favoured "call the real API directly"
 * over adding a service in between (see armada.php, invoice.php's own
 * Dompdf). That means this file needs three secrets to actually send
 * anything: an APNs Auth Key (.p8), its Key ID, and the Apple Team ID. Those
 * are entered on the settings screen this file also builds
 * (Settings → Push Notifications) — never pasted into chat, same rule as
 * the Armada API secret (see armada.php's own docblock).
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

/** The app's bundle identifier — must match ios-app/capacitor.config.json's `appId`. */
define( 'PRIME_APNS_BUNDLE_ID', 'com.primeprintco.app' );

/**
 * Create the device-token table, and keep it current across theme updates.
 *
 * A real table, not post meta or a theme mod: this is registration data with
 * no post to attach to, queried by token (uniqueness, on every registration)
 * and by user (on every order status change) — exactly what an indexed table
 * is for, and everything else in this theme that reached for one already
 * outgrew post meta before reaching for a table (see checkout-data.php's
 * static area list, which stayed an array precisely because it's never
 * queried that way).
 */
function prime_push_install_table() {
	global $wpdb;

	$table_name      = $wpdb->prefix . 'prime_push_tokens';
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table_name} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		token VARCHAR(200) NOT NULL,
		user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		platform VARCHAR(20) NOT NULL DEFAULT 'ios',
		created_at DATETIME NOT NULL,
		last_seen_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY token (token),
		KEY user_id (user_id)
	) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	update_option( 'prime_push_table_version', 1 );
}

/**
 * Run the table migration once per version bump, on any admin request — the
 * same pattern the rest of this theme uses (see prime_setup_languages()'s
 * docblock in i18n.php) rather than relying on a theme (re)activation hook,
 * which SFTP-deployed updates never trigger.
 */
function prime_push_maybe_install_table() {
	if ( (int) get_option( 'prime_push_table_version', 0 ) < 1 ) {
		prime_push_install_table();
	}
}
add_action( 'admin_init', 'prime_push_maybe_install_table' );

/**
 * Register the token-submission REST route.
 *
 * No nonce/login requirement: the app registers a device for push the
 * moment permission is granted, which on a fresh install happens before any
 * WordPress session exists — the token is meaningless to anyone but Apple's
 * push service anyway, so there is nothing to protect here beyond basic
 * shape validation. A logged-in customer's token is tied to their user ID so
 * order-status pushes can find it later; an anonymous one is only ever
 * reachable through a broadcast announcement.
 */
function prime_register_push_routes() {
	register_rest_route(
		'prime/v1',
		'/push-token',
		array(
			'methods'             => 'POST',
			'callback'            => 'prime_handle_push_token_registration',
			'permission_callback' => '__return_true',
			'args'                => array(
				'token'    => array( 'required' => true ),
				'platform' => array( 'required' => false ),
			),
		)
	);

	register_rest_route(
		'prime/v1',
		'/push-token',
		array(
			'methods'             => 'DELETE',
			'callback'            => 'prime_handle_push_token_removal',
			'permission_callback' => '__return_true',
			'args'                => array(
				'token' => array( 'required' => true ),
			),
		)
	);
}
add_action( 'rest_api_init', 'prime_register_push_routes' );

/**
 * Store (or refresh) one device token.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function prime_handle_push_token_registration( WP_REST_Request $request ) {
	global $wpdb;

	$token    = sanitize_text_field( $request->get_param( 'token' ) );
	$platform = sanitize_key( $request->get_param( 'platform' ) ?: 'ios' );

	// An APNs device token is 64-160 hex characters depending on iOS
	// version/format; this is deliberately loose rather than pinned to one
	// exact length, but still refuses to store obvious garbage.
	if ( ! preg_match( '/^[a-f0-9]{32,200}$/i', $token ) ) {
		return new WP_REST_Response( array( 'error' => 'invalid_token' ), 400 );
	}

	$table_name = $wpdb->prefix . 'prime_push_tokens';
	$now        = current_time( 'mysql' );

	$wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$table_name} (token, user_id, platform, created_at, last_seen_at)
			 VALUES (%s, %d, %s, %s, %s)
			 ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), last_seen_at = VALUES(last_seen_at)",
			$token,
			get_current_user_id(),
			$platform,
			$now,
			$now
		)
	);

	return new WP_REST_Response( array( 'ok' => true ), 200 );
}

/**
 * Drop a token — called on logout/uninstall so a signed-out phone stops
 * being treated as that customer's device for order-status pushes (it stays
 * reachable for general announcements only, same as any anonymous install,
 * until it registers again against whoever logs in next).
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function prime_handle_push_token_removal( WP_REST_Request $request ) {
	global $wpdb;

	$token      = sanitize_text_field( $request->get_param( 'token' ) );
	$table_name = $wpdb->prefix . 'prime_push_tokens';

	$wpdb->update( $table_name, array( 'user_id' => 0 ), array( 'token' => $token ) );

	return new WP_REST_Response( array( 'ok' => true ), 200 );
}

/* ---------------------------------------------------------------------- */
/* Settings — Apple's three secrets, entered here, never in chat.          */
/* ---------------------------------------------------------------------- */

/**
 * Register the "Push Notifications" settings screen under Settings.
 *
 * add_options_page()/add_submenu_page() must run on `admin_menu` — WordPress
 * builds the admin menu (and the access-control table every page request is
 * checked against) on that hook specifically. Registering it on `admin_init`
 * instead — as this originally did, alongside the Settings API calls below,
 * which really do belong there — meant the menu link rendered (menu display
 * and the page's own access check apparently read slightly different
 * state), but opening it always hit WordPress's own "Sorry, you are not
 * allowed to access this page" (2026-09-05). Split in two so each half runs
 * on the hook it actually requires.
 */
function prime_register_push_menu() {
	add_options_page(
		__( 'Push Notifications', 'prime-printing' ),
		__( 'Push Notifications', 'prime-printing' ),
		'manage_options',
		'prime-push-settings',
		'prime_render_push_settings_page'
	);
}
add_action( 'admin_menu', 'prime_register_push_menu' );

/**
 * The Settings API fields backing that screen — `register_setting()`, unlike
 * the menu registration above, is meant to run on `admin_init`.
 */
function prime_register_push_settings_fields() {
	register_setting( 'prime_push_settings', 'prime_apns_key_id', 'sanitize_text_field' );
	register_setting( 'prime_push_settings', 'prime_apns_team_id', 'sanitize_text_field' );
	register_setting( 'prime_push_settings', 'prime_apns_environment', 'sanitize_key' );
	register_setting(
		'prime_push_settings',
		'prime_apns_private_key',
		array(
			// Autoload off: this is a secret read on the rare occasion a
			// push actually sends, not on every page load like most options.
			'sanitize_callback' => 'prime_sanitize_apns_key_field',
		)
	);
}
add_action( 'admin_init', 'prime_register_push_settings_fields' );

/**
 * Keep the stored .p8 key when the field is left blank on save.
 *
 * The settings page never echoes the real key back into the textarea (see
 * prime_render_push_settings_page()) — only a placeholder once one is
 * stored — so a normal "change the Key ID, leave everything else" save must
 * not overwrite the key with that blank field.
 *
 * @param string $value Submitted textarea value.
 * @return string
 */
function prime_sanitize_apns_key_field( $value ) {
	$value = is_string( $value ) ? trim( $value ) : '';

	if ( '' === $value ) {
		return get_option( 'prime_apns_private_key', '' );
	}

	return $value;
}

/**
 * The settings screen: Key ID, Team ID, environment, and the .p8 key itself.
 */
function prime_render_push_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$has_key = (bool) get_option( 'prime_apns_private_key', '' );
	?>
	<div class="wrap">
		<h1>
			<?php esc_html_e( 'Push Notifications', 'prime-printing' ); ?>
			<a href="<?php echo esc_url( admin_url( 'options-general.php?page=prime-push-announce' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Send Announcement', 'prime-printing' ); ?></a>
		</h1>
		<p>
			<?php esc_html_e( 'These three values come from your Apple Developer account, not from Claude — generate them yourself at developer.apple.com and paste them in here directly.', 'prime-printing' ); ?>
		</p>
		<ol>
			<li><?php esc_html_e( 'Certificates, Identifiers & Profiles → Keys → the “+” button.', 'prime-printing' ); ?></li>
			<li><?php esc_html_e( 'Name it (e.g. "Prime Printing Push"), tick Apple Push Notifications service (APNs), Continue, Register.', 'prime-printing' ); ?></li>
			<li><?php esc_html_e( 'Download the .p8 file immediately — Apple only lets you download it once. Open it in a text editor and paste its full contents below, including the BEGIN/END lines.', 'prime-printing' ); ?></li>
			<li><?php esc_html_e( 'The Key ID is shown on the same page. The Team ID is in the top-right of the Developer account, or under Membership.', 'prime-printing' ); ?></li>
		</ol>

		<form method="post" action="options.php">
			<?php settings_fields( 'prime_push_settings' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="prime_apns_key_id"><?php esc_html_e( 'Key ID', 'prime-printing' ); ?></label></th>
					<td><input type="text" id="prime_apns_key_id" name="prime_apns_key_id" class="regular-text" value="<?php echo esc_attr( get_option( 'prime_apns_key_id', '' ) ); ?>"></td>
				</tr>
				<tr>
					<th><label for="prime_apns_team_id"><?php esc_html_e( 'Team ID', 'prime-printing' ); ?></label></th>
					<td><input type="text" id="prime_apns_team_id" name="prime_apns_team_id" class="regular-text" value="<?php echo esc_attr( get_option( 'prime_apns_team_id', '' ) ); ?>"></td>
				</tr>
				<tr>
					<th><label for="prime_apns_environment"><?php esc_html_e( 'Environment', 'prime-printing' ); ?></label></th>
					<td>
						<select id="prime_apns_environment" name="prime_apns_environment">
							<?php $env = get_option( 'prime_apns_environment', 'development' ); ?>
							<option value="development" <?php selected( $env, 'development' ); ?>><?php esc_html_e( 'Development (Xcode/TestFlight debug builds)', 'prime-printing' ); ?></option>
							<option value="production" <?php selected( $env, 'production' ); ?>><?php esc_html_e( 'Production (App Store build)', 'prime-printing' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="prime_apns_private_key"><?php esc_html_e( '.p8 key contents', 'prime-printing' ); ?></label></th>
					<td>
						<textarea id="prime_apns_private_key" name="prime_apns_private_key" rows="6" class="large-text code" placeholder="<?php echo $has_key ? esc_attr__( 'A key is already stored — leave blank to keep it, or paste a new one to replace it.', 'prime-printing' ) : esc_attr__( '-----BEGIN PRIVATE KEY-----...', 'prime-printing' ); ?>"></textarea>
						<p class="description">
							<?php
							echo $has_key
								? esc_html__( 'A key is currently stored. This field is intentionally left blank when the page loads — the stored key is never redisplayed.', 'prime-printing' )
								: esc_html__( 'No key stored yet.', 'prime-printing' );
							?>
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

/* ---------------------------------------------------------------------- */
/* Sending — a signed JWT, then a plain HTTP/2 POST to Apple.               */
/* ---------------------------------------------------------------------- */

/**
 * A signed ES256 JWT for APNs's provider authentication token scheme.
 *
 * Cached for 50 minutes (Apple allows reuse up to an hour) so a burst of
 * pushes — an announcement to hundreds of tokens — signs once, not once per
 * device.
 *
 * @return string|WP_Error
 */
function prime_apns_jwt() {
	$cached = get_transient( 'prime_apns_jwt' );

	if ( $cached ) {
		return $cached;
	}

	$key_id  = get_option( 'prime_apns_key_id', '' );
	$team_id = get_option( 'prime_apns_team_id', '' );
	$pem     = get_option( 'prime_apns_private_key', '' );

	if ( ! $key_id || ! $team_id || ! $pem ) {
		return new WP_Error( 'prime_apns_not_configured', __( 'Push Notifications are not configured yet (Settings → Push Notifications).', 'prime-printing' ) );
	}

	$private_key = openssl_pkey_get_private( $pem );

	if ( ! $private_key ) {
		return new WP_Error( 'prime_apns_bad_key', __( 'The stored .p8 key could not be read — re-paste it from the downloaded file.', 'prime-printing' ) );
	}

	$b64url = static function ( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	};

	$header  = $b64url( wp_json_encode( array( 'alg' => 'ES256', 'kid' => $key_id ) ) );
	$payload = $b64url( wp_json_encode( array( 'iss' => $team_id, 'iat' => time() ) ) );
	$signing_input = $header . '.' . $payload;

	$der_signature = '';
	$signed        = openssl_sign( $signing_input, $der_signature, $private_key, OPENSSL_ALGO_SHA256 );

	if ( ! $signed ) {
		return new WP_Error( 'prime_apns_sign_failed', __( 'Signing the APNs token failed.', 'prime-printing' ) );
	}

	$jwt = $signing_input . '.' . $b64url( prime_der_ecdsa_to_raw( $der_signature ) );

	set_transient( 'prime_apns_jwt', $jwt, 50 * MINUTE_IN_SECONDS );

	return $jwt;
}

/**
 * openssl_sign() on an EC key returns a DER-encoded ASN.1 SEQUENCE of two
 * INTEGERs (r, s). JWT's ES256 wants those concatenated as two fixed-width
 * 32-byte big-endian numbers instead — there is no PHP/openssl option to get
 * that format directly, so it is parsed out by hand here.
 *
 * @param string $der DER-encoded ECDSA signature.
 * @return string 64-byte raw r||s signature.
 */
function prime_der_ecdsa_to_raw( $der ) {
	// SEQUENCE tag (0x30) + length byte, then two INTEGER (0x02) TLVs.
	$offset = 2;
	if ( ord( $der[1] ) & 0x80 ) {
		// Long-form length: low 7 bits of the first byte say how many
		// following bytes encode the length itself.
		$offset += ord( $der[1] ) & 0x7F;
	}

	$parts = array();

	for ( $i = 0; $i < 2; $i++ ) {
		$offset++; // Skip the INTEGER tag (0x02).
		$len = ord( $der[ $offset ] );
		$offset++;

		$int = substr( $der, $offset, $len );
		// A leading 0x00 pad byte (present whenever the high bit of the real
		// value would otherwise read as a negative ASN.1 integer) is dropped;
		// the result is then left-padded to exactly 32 bytes.
		$int = ltrim( $int, "\x00" );
		$parts[] = str_pad( $int, 32, "\x00", STR_PAD_LEFT );

		$offset += $len;
	}

	return $parts[0] . $parts[1];
}

/**
 * Send one push payload to every token belonging to a set of tokens.
 *
 * @param string[] $tokens Raw APNs device tokens.
 * @param string   $title  Notification title.
 * @param string   $body   Notification body.
 * @param array    $data   Extra fields merged into the payload (e.g. a deep
 *                         link the app reads in its notification-tap handler).
 * @return int Number of tokens the push was actually accepted for.
 */
function prime_apns_send( array $tokens, $title, $body, array $data = array() ) {
	$jwt = prime_apns_jwt();

	if ( is_wp_error( $jwt ) ) {
		return 0;
	}

	$environment = get_option( 'prime_apns_environment', 'development' );
	$host        = 'production' === $environment ? 'api.push.apple.com' : 'api.sandbox.push.apple.com';

	$payload = wp_json_encode(
		array_merge(
			$data,
			array(
				'aps' => array(
					'alert' => array(
						'title' => $title,
						'body'  => $body,
					),
					'sound' => 'default',
				),
			)
		)
	);

	$sent = 0;

	foreach ( array_unique( $tokens ) as $token ) {
		$result = prime_apns_send_one( $host, $token, $jwt, $payload );

		if ( is_wp_error( $result ) ) {
			continue;
		}

		if ( 200 === wp_remote_retrieve_response_code( $result ) ) {
			++$sent;
		} elseif ( in_array( wp_remote_retrieve_response_code( $result ), array( 400, 410 ), true ) ) {
			// BadDeviceToken / Unregistered — the phone uninstalled the app
			// or the token rotated; keeping a dead token around only means
			// paying the request cost for it forever.
			prime_forget_push_token( $token );
		}
	}

	return $sent;
}

/**
 * The single HTTP/2 POST APNs's provider API requires.
 *
 * @param string $host    APNs host (production or sandbox).
 * @param string $token   Device token.
 * @param string $jwt     Signed provider-authentication token.
 * @param string $payload JSON-encoded aps payload.
 * @return array|WP_Error
 */
function prime_apns_send_one( $host, $token, $jwt, $payload ) {
	// WordPress's HTTP API has no direct HTTP/2 switch, and Apple's provider
	// API refuses anything else — this filter reaches into the underlying
	// curl handle for just this one request to force it, mirroring how
	// armada.php reaches past wp_remote_post for the one thing it can't
	// express (see that file's own use of raw curl for HMAC headers).
	$force_http2 = static function ( $handle ) {
		curl_setopt( $handle, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0 );
		return $handle;
	};

	add_action( 'http_api_curl', $force_http2 );

	$result = wp_remote_post(
		"https://{$host}/3/device/{$token}",
		array(
			'headers' => array(
				'authorization'  => 'bearer ' . $jwt,
				'apns-topic'     => PRIME_APNS_BUNDLE_ID,
				'apns-push-type' => 'alert',
				'content-type'   => 'application/json',
			),
			'body'    => $payload,
			'timeout' => 10,
		)
	);

	remove_action( 'http_api_curl', $force_http2 );

	return $result;
}

/**
 * Remove a dead token entirely (see the 400/410 handling above).
 *
 * @param string $token Device token.
 */
function prime_forget_push_token( $token ) {
	global $wpdb;

	$wpdb->delete( $wpdb->prefix . 'prime_push_tokens', array( 'token' => $token ) );
}

/**
 * A customer's stored device tokens.
 *
 * @param int $user_id WordPress user ID.
 * @return string[]
 */
function prime_push_tokens_for_user( $user_id ) {
	global $wpdb;

	$table = $wpdb->prefix . 'prime_push_tokens';

	return $wpdb->get_col( $wpdb->prepare( "SELECT token FROM {$table} WHERE user_id = %d", $user_id ) );
}

/**
 * Every stored token, for a broadcast announcement.
 *
 * @return string[]
 */
function prime_all_push_tokens() {
	global $wpdb;

	return $wpdb->get_col( 'SELECT token FROM ' . $wpdb->prefix . 'prime_push_tokens' );
}

/* ---------------------------------------------------------------------- */
/* Order-status pushes.                                                    */
/* ---------------------------------------------------------------------- */

/**
 * Push a customer their order's new status.
 *
 * Guest checkouts (this store's default — see checkout-fields.php) have no
 * WordPress user account and so nothing to look a token up against; they get
 * their usual order emails only, which was already true before push existed.
 *
 * @param int      $order_id   Order ID.
 * @param string   $status_from Old status slug, without wc- prefix.
 * @param string   $status_to   New status slug, without wc- prefix.
 * @param WC_Order $order      Order object.
 */
function prime_push_order_status_update( $order_id, $status_from, $status_to, $order ) {
	$customer_id = $order->get_customer_id();

	if ( ! $customer_id ) {
		return;
	}

	$tokens = prime_push_tokens_for_user( $customer_id );

	if ( ! $tokens ) {
		return;
	}

	$status_labels = wc_get_order_statuses();
	$label         = $status_labels[ 'wc-' . $status_to ] ?? $status_to;

	prime_apns_send(
		$tokens,
		__( 'Order update', 'prime-printing' ),
		sprintf(
			/* translators: 1: order number, 2: new order status. */
			__( 'Order #%1$s is now %2$s.', 'prime-printing' ),
			$order->get_order_number(),
			$label
		),
		array( 'order_id' => $order_id, 'type' => 'order_status' )
	);
}
add_action( 'woocommerce_order_status_changed', 'prime_push_order_status_update', 10, 4 );

/* ---------------------------------------------------------------------- */
/* Announcements — Reem broadcasting to every installed app.                */
/* ---------------------------------------------------------------------- */

/**
 * The "Send Announcement" screen, next to Push Notifications under Settings.
 */
function prime_register_announcement_page() {
	add_submenu_page(
		'options-general.php',
		__( 'Send Announcement', 'prime-printing' ),
		__( 'Send Announcement', 'prime-printing' ),
		'manage_options',
		'prime-push-announce',
		'prime_render_announcement_page'
	);
}
add_action( 'admin_menu', 'prime_register_announcement_page' );

/**
 * Render + handle the announcement form.
 */
function prime_render_announcement_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$sent_count = null;

	if ( isset( $_POST['prime_announce_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['prime_announce_nonce'] ) ), 'prime_send_announcement' ) ) {
		$title = sanitize_text_field( wp_unslash( $_POST['prime_announce_title'] ?? '' ) );
		$body  = sanitize_textarea_field( wp_unslash( $_POST['prime_announce_body'] ?? '' ) );

		if ( $title && $body ) {
			$tokens     = prime_all_push_tokens();
			$sent_count = prime_apns_send( $tokens, $title, $body, array( 'type' => 'announcement' ) );
		}
	}

	$total_devices = count( prime_all_push_tokens() );
	?>
	<div class="wrap">
		<h1>
			<?php esc_html_e( 'Send Announcement', 'prime-printing' ); ?>
			<a href="<?php echo esc_url( admin_url( 'options-general.php?page=prime-push-settings' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Settings', 'prime-printing' ); ?></a>
		</h1>

		<?php if ( null !== $sent_count ) : ?>
			<div class="notice notice-success"><p>
				<?php
				printf(
					/* translators: 1: number of devices notified, 2: number of devices with a stored token. */
					esc_html__( 'Sent to %1$d of %2$d registered devices.', 'prime-printing' ),
					(int) $sent_count,
					(int) $total_devices
				);
				?>
			</p></div>
		<?php endif; ?>

		<p>
			<?php
			printf(
				/* translators: %d: number of devices with the app installed. */
				esc_html( _n( '%d device has the app installed and can receive this.', '%d devices have the app installed and can receive this.', $total_devices, 'prime-printing' ) ),
				(int) $total_devices
			);
			?>
		</p>

		<form method="post">
			<?php wp_nonce_field( 'prime_send_announcement', 'prime_announce_nonce' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="prime_announce_title"><?php esc_html_e( 'Title', 'prime-printing' ); ?></label></th>
					<td><input type="text" id="prime_announce_title" name="prime_announce_title" class="regular-text" maxlength="120" required></td>
				</tr>
				<tr>
					<th><label for="prime_announce_body"><?php esc_html_e( 'Message', 'prime-printing' ); ?></label></th>
					<td><textarea id="prime_announce_body" name="prime_announce_body" rows="4" class="large-text" maxlength="500" required></textarea></td>
				</tr>
			</table>
			<?php submit_button( __( 'Send to everyone', 'prime-printing' ) ); ?>
		</form>
	</div>
	<?php
}
