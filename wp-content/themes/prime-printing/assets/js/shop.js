/**
 * Prime Printing — shop archive behaviour.
 *
 * Everything here talks to the server (inc/shop.php's AJAX endpoint) rather
 * than filtering an in-page array — the catalogue is too large to ship to the
 * browser whole, and a client-only filter would be invisible to search engines.
 *
 * Density is the one purely client-side preference, and it is the one thing
 * here persisted to localStorage, matching the reference's "remembers your last
 * view" behaviour.
 */
(function () {
	'use strict';

	var bar = document.querySelector( '[data-prime-shop]' );

	if ( ! bar ) {
		return;
	}

	var ajaxUrl = bar.getAttribute( 'data-ajax-url' );
	var shopUrl = bar.getAttribute( 'data-shop-url' );
	var categoryId = bar.getAttribute( 'data-category' ) || '0';
	var searchInput = bar.querySelector( '[data-prime-search]' );
	var sortSelect = bar.querySelector( '[data-prime-sort]' );
	var viewButtons = bar.querySelectorAll( '[data-prime-view]' );
	var densityButtons = bar.querySelectorAll( '[data-prime-density]' );
	var grid = document.querySelector( '[data-prime-grid]' );
	var moreButton = document.querySelector( '[data-prime-more]' );

	/* ---- Density: view preference, persisted per visitor ------------------ */

	var DENSITY_KEY = 'primeShopDensity';

	var applyDensity = function ( value ) {
		document.body.classList.toggle( 'prime-wide', 'one' === value );

		Array.prototype.forEach.call( densityButtons, function ( button ) {
			button.classList.toggle( 'is-on', button.getAttribute( 'data-prime-density' ) === value );
		} );
	};

	Array.prototype.forEach.call( densityButtons, function ( button ) {
		button.addEventListener( 'click', function () {
			var value = button.getAttribute( 'data-prime-density' );
			applyDensity( value );

			try {
				window.localStorage.setItem( DENSITY_KEY, value );
			} catch ( error ) {
				// Private browsing or a full quota — the toggle still works for
				// this page view, it just does not persist.
			}
		} );
	} );

	try {
		var stored = window.localStorage.getItem( DENSITY_KEY );
		if ( stored ) {
			applyDensity( stored );
		}
	} catch ( error ) {
		// No persisted preference available; default density stands.
	}

	/* ---- View toggle: categories <-> all products -------------------------- */

	Array.prototype.forEach.call( viewButtons, function ( button ) {
		button.addEventListener( 'click', function () {
			var view = button.getAttribute( 'data-prime-view' );
			var url = new URL( shopUrl, window.location.href );

			if ( 'all' === view ) {
				url.searchParams.set( 'view', 'all' );
			}

			window.location.href = url.toString();
		} );
	} );

	/* ---- Search: submits to the server, not filtered client-side ---------- */

	// The <form> around the search field already submits on Enter with no JS
	// needed; nothing to wire here beyond what the markup does natively.

	/* ---- Sort: re-navigates with the new orderby --------------------------- */

	if ( sortSelect ) {
		sortSelect.addEventListener( 'change', function () {
			var url = new URL( window.location.href );
			url.searchParams.set( 'orderby', sortSelect.value );
			window.location.href = url.toString();
		} );
	}

	/* ---- Show more: AJAX-append the next page ------------------------------ */

	if ( moreButton && grid && ajaxUrl ) {
		moreButton.addEventListener( 'click', function () {
			var nextPage = parseInt( moreButton.getAttribute( 'data-page' ), 10 ) + 1;
			var url = new URL( ajaxUrl, window.location.href );

			url.searchParams.set( 'action', 'prime_products' );
			url.searchParams.set( 'page', nextPage );
			url.searchParams.set( 'category', categoryId );

			if ( searchInput && searchInput.value ) {
				url.searchParams.set( 's', searchInput.value );
			}

			if ( sortSelect ) {
				url.searchParams.set( 'orderby', sortSelect.value );
			}

			moreButton.disabled = true;
			var originalText = moreButton.textContent;
			moreButton.textContent = moreButton.getAttribute( 'data-loading-text' ) || originalText;

			fetch( url.toString(), { credentials: 'same-origin' } )
				.then( function ( response ) {
					if ( ! response.ok ) {
						throw new Error( 'Request failed: ' + response.status );
					}
					return response.json();
				} )
				.then( function ( payload ) {
					if ( ! payload || ! payload.success || ! payload.data ) {
						throw new Error( 'Unexpected response' );
					}

					var data = payload.data;
					var template = document.createElement( 'template' );
					template.innerHTML = data.html;
					grid.appendChild( template.content );

					moreButton.setAttribute( 'data-page', String( data.page ) );
					moreButton.disabled = false;

					if ( data.hasMore ) {
						// Reuses the button's own server-rendered (and therefore
						// already-localized) text, just swapping the count —
						// no separate translated string needed here.
						moreButton.textContent = originalText.replace( /\(.*\)/, '(' + data.remaining + ')' );
					} else {
						moreButton.remove();
					}
				} )
				.catch( function () {
					// Network or server failure: restore the button so the visitor
					// can simply try again, rather than leaving it stuck loading.
					moreButton.disabled = false;
					moreButton.textContent = originalText;
				} );
		} );
	}
})();
