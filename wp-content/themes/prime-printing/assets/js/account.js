/**
 * Prime Printing — account page behaviour.
 *
 * Two small, independent pieces of UI, both presentation only:
 *   - expanding an order card to show its line items and actions
 *   - the status filter chips on the Orders tab
 * With JS disabled every order card just renders expanded (no [hidden] is
 * ever applied), so nothing here is required to use the page.
 */
(function () {
	'use strict';

	document.querySelectorAll( '[data-prime-order-toggle]' ).forEach( function ( toggle ) {
		var order = toggle.closest( '.prime-order' );
		var body = order ? order.querySelector( '.prime-order__body' ) : null;

		if ( ! body ) {
			return;
		}

		body.hidden = true;

		toggle.addEventListener( 'click', function () {
			var expanded = toggle.getAttribute( 'aria-expanded' ) === 'true';
			toggle.setAttribute( 'aria-expanded', String( ! expanded ) );
			body.hidden = expanded;
		} );
	} );

	var filters = document.querySelectorAll( '[data-prime-order-filter]' );
	var orders = document.querySelectorAll( '.prime-order' );

	filters.forEach( function ( filter ) {
		filter.addEventListener( 'click', function () {
			var group = filter.getAttribute( 'data-prime-order-filter' );

			filters.forEach( function ( other ) {
				other.classList.toggle( 'is-active', other === filter );
			} );

			orders.forEach( function ( order ) {
				var matches = 'all' === group || order.getAttribute( 'data-prime-order-status' ) === group;
				order.hidden = ! matches;
			} );
		} );
	} );
})();
