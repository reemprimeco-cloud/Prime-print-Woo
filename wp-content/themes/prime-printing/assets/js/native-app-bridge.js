/**
 * Bridge between the live site and the iOS app shell (ios-app/, a Capacitor
 * WebView pointed at this same site — see that project's capacitor.config.json).
 *
 * Loaded on every page, for every visitor, because there is no reliable
 * server-side signal that a request comes from the app rather than Safari —
 * Capacitor does not alter the User-Agent. So the real gate is the very
 * first line: `window.Capacitor` only exists inside the native shell, and
 * everything below is a no-op anywhere else. The cost to a normal browser
 * visit is one property check.
 *
 * What it does inside the app:
 *   - asks for notification permission and registers for push, then POSTs
 *     the resulting APNs device token to inc/push-notifications.php's REST
 *     route so it can be pushed to later (an order status change, or an
 *     announcement Reem sends from wp-admin);
 *   - drops the token again on logout, so a shared/reset phone stops being
 *     treated as that customer's device;
 *   - opens a tapped notification's deep link (an order, for now) inside the
 *     same WebView instead of doing nothing, which is the default behaviour
 *     for a notification tap Capacitor doesn't otherwise handle.
 */
(function () {
	'use strict';

	if ( ! window.Capacitor || ! window.Capacitor.isNativePlatform || ! window.Capacitor.isNativePlatform() ) {
		return;
	}

	var Push = window.Capacitor.Plugins && window.Capacitor.Plugins.PushNotifications;

	if ( ! Push ) {
		return;
	}

	var REGISTER_URL = '/wp-json/prime/v1/push-token';

	var sendToken = function ( token ) {
		fetch( REGISTER_URL, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			credentials: 'same-origin', // The app is logged in the same way a browser is — via this site's own cookies.
			body: JSON.stringify( { token: token, platform: 'ios' } )
		} );
	};

	Push.addListener( 'registration', function ( result ) {
		if ( result && result.value ) {
			sendToken( result.value );
		}
	} );

	Push.addListener( 'registrationError', function () {
		// Nothing to recover to — the permission prompt only reappears if the
		// customer re-enables notifications in iOS Settings themselves.
	} );

	// Tapping a push while the app is backgrounded/closed: open whatever the
	// payload points to (an order, today; the field is deliberately generic
	// so a future notification type needs no app update to route somewhere
	// new — inc/push-notifications.php just starts sending a different path).
	Push.addListener( 'pushNotificationActionPerformed', function ( action ) {
		var data = action && action.notification && action.notification.data;

		if ( data && data.order_id ) {
			window.location.href = '/my-account/view-order/' + data.order_id + '/';
		}
	} );

	Push.checkPermissions().then( function ( status ) {
		if ( 'granted' === status.receive ) {
			Push.register();
			return;
		}

		if ( 'prompt' === status.receive || 'prompt-with-rationale' === status.receive ) {
			Push.requestPermissions().then( function ( result ) {
				if ( 'granted' === result.receive ) {
					Push.register();
				}
			} );
		}
	} );

	// Logout: prime-account.js's own "Log out" link is a normal WooCommerce
	// endpoint, not a fetch call this file can hook — instead this listens
	// for the click directly, since it is the one action here that must
	// happen before the page navigates away and the session is gone.
	document.addEventListener( 'click', function ( event ) {
		var link = event.target.closest && event.target.closest( 'a.wc-logout, a[href*="customer-logout"]' );

		if ( ! link ) {
			return;
		}

		Push.checkPermissions().then( function ( status ) {
			if ( 'granted' !== status.receive ) {
				return;
			}

			// register() again just to read back the current token — Capacitor
			// has no separate "get my token" call — then release it server-side.
			var onceMore = Push.addListener( 'registration', function ( result ) {
				onceMore.remove();
				if ( result && result.value ) {
					// sendBeacon can only POST, so WP REST's own `_method`
					// override (query param, not header — that's the form it
					// reads on a plain request) turns this into the DELETE
					// route; a Blob with an explicit type is required or the
					// request arrives as text/plain and get_json_params()
					// never populates $request->get_param().
					var blob = new Blob( [ JSON.stringify( { token: result.value } ) ], { type: 'application/json' } );
					navigator.sendBeacon( REGISTER_URL + '?_method=DELETE', blob );
				}
			} );
			Push.register();
		} );
	} );
})();
