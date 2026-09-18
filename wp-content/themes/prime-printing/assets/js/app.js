/**
 * App mode — the little behaviour the app chrome needs
 * (inc/native-app-ui.php enqueues this only for app requests).
 *
 *   - Back button: history back when there is somewhere to go, else home.
 *   - Share button on a product: shown only when the WebView has the Web
 *     Share API (iOS WKWebView does), opens the native share sheet.
 *   - Category chips: scroll the lit chip into view on load.
 *
 * The cart badge needs nothing here — WooCommerce's own add-to-cart
 * fragments replace it (prime_app_cart_fragment()).
 */
(function () {
	'use strict';

	var homeUrl = ( window.primeApp && window.primeApp.homeUrl ) || '/';

	var back = document.querySelector( '[data-prime-app-back]' );

	if ( back ) {
		back.addEventListener( 'click', function () {
			if ( window.history.length > 1 ) {
				window.history.back();
				return;
			}

			window.location.href = homeUrl;
		} );
	}

	var share = document.querySelector( '[data-prime-app-share]' );

	if ( share && navigator.share ) {
		share.hidden = false;

		share.addEventListener( 'click', function () {
			navigator.share( {
				title: document.title,
				url: window.location.href
			} ).catch( function () {
				// The customer dismissed the sheet — nothing to do.
			} );
		} );
	}

	var lit = document.querySelector( '.prime-app-chip.is-on' );

	if ( lit && lit.scrollIntoView ) {
		lit.scrollIntoView( { block: 'nearest', inline: 'center' } );
	}
})();
