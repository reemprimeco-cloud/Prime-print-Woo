/**
 * App mode — the little behaviour the app chrome needs
 * (inc/native-app-ui.php enqueues this only for app requests).
 *
 *   - Back button: history back when there is somewhere to go, else home.
 *   - Share button on a product: shown only when the WebView has the Web
 *     Share API (iOS WKWebView does), opens the native share sheet.
 *   - Category chips: scroll the lit chip into view on load.
 *   - Instant navigation feedback: a top progress bar plus an optimistic
 *     tab-bar highlight on tap (see startNavigationFeedback() below) — the
 *     app has no client-side routing, every tab/link is a real full page
 *     load, and that load genuinely takes ~1.5–2s server-side (confirmed via
 *     WordPress.com's own `server-timing` header, 2026-09-18 — dozens of
 *     enqueued plugin scripts unrelated to the page being requested, not
 *     something this feedback can shorten). What was missing was *any*
 *     response to the tap before the new page started arriving, which reads
 *     as the app having frozen rather than as a page that is simply loading.
 *
 * The cart badge needs nothing here — WooCommerce's own add-to-cart
 * fragments replace it (prime_app_cart_fragment()).
 */
(function () {
	'use strict';

	var homeUrl = ( window.primeApp && window.primeApp.homeUrl ) || '/';

	/**
	 * Show the top progress bar and, if the tapped element is a bottom tab,
	 * light it immediately rather than waiting for the new page to render
	 * and decide which tab is active. A safety timeout clears both if the
	 * navigation this was called for never actually happens (the customer
	 * backgrounds the app, or iOS's edge-swipe-back gesture cancels it) —
	 * without it either state could be left showing indefinitely, since nothing
	 * else on this page will ever run again once a real navigation succeeds.
	 *
	 * @param {Element} [tappedTab] The `.prime-app-tab` that was tapped, if any.
	 */
	function startNavigationFeedback( tappedTab ) {
		var bar = document.querySelector( '.prime-app-progress' );

		if ( bar ) {
			bar.classList.remove( 'is-done' );
			// Reflow so the class removal above and the re-add below are two
			// separate style changes — otherwise the browser coalesces them
			// and the width transition never runs on a second tap in a row.
			void bar.offsetWidth; // eslint-disable-line no-unused-expressions
			bar.classList.add( 'is-active' );
		}

		if ( tappedTab && ! tappedTab.classList.contains( 'is-on' ) ) {
			var tabs = tappedTab.parentElement ? tappedTab.parentElement.children : [];

			for ( var i = 0; i < tabs.length; i++ ) {
				tabs[ i ].classList.remove( 'is-on' );
			}

			tappedTab.classList.add( 'is-on' );
		}

		window.clearTimeout( startNavigationFeedback.resetTimer );
		startNavigationFeedback.resetTimer = window.setTimeout( function () {
			if ( bar ) {
				bar.classList.remove( 'is-active' );
			}
		}, 6000 );
	}

	/**
	 * Same-origin, plain left-click, not opening in a new context — the only
	 * kind of link this app can usefully show progress for; anything else
	 * (external links, cmd/ctrl-click, downloads) navigates or opens on its
	 * own with no help from this page.
	 *
	 * @param {MouseEvent} event
	 * @param {HTMLAnchorElement} link
	 * @return {boolean}
	 */
	function isPlainSameOriginClick( event, link ) {
		return link.href
			&& link.origin === window.location.origin
			&& ! link.target
			&& ! link.hasAttribute( 'download' )
			&& 0 === event.button
			&& ! event.metaKey && ! event.ctrlKey && ! event.shiftKey && ! event.altKey;
	}

	document.addEventListener( 'click', function ( event ) {
		var link = event.target.closest && event.target.closest( 'a[href]' );

		if ( ! link || ! isPlainSameOriginClick( event, link ) ) {
			return;
		}

		// A same-page anchor (id or bare '#') is not a navigation.
		if ( link.pathname === window.location.pathname && link.hash ) {
			return;
		}

		startNavigationFeedback( link.closest( '.prime-app-tab' ) );
	} );

	// The search forms (home bar, top bar, shop head) are a GET submit —
	// also a full page load, same as a link tap.
	document.addEventListener( 'submit', function ( event ) {
		if ( event.target.classList && event.target.classList.contains( 'prime-app-search' ) ) {
			startNavigationFeedback();
		}
	} );

	var back = document.querySelector( '[data-prime-app-back]' );

	if ( back ) {
		back.addEventListener( 'click', function () {
			startNavigationFeedback();

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
