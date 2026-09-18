/**
 * Governorate -> area population for the account page's "Address" tab
 * (/my-account/edit-address/billing/).
 *
 * The real checkout page already has this exact logic inline in
 * checkout.js, gated behind a `form.woocommerce-checkout` guard that page's
 * own form carries. The account address-edit form is a different,
 * plain `<form>` WooCommerce renders itself (no such class), so checkout.js
 * exits before ever reaching this block there — hence a second, small copy
 * here rather than loosening checkout.js's guard and risking the
 * already-verified checkout flow to serve a different page. If the
 * behaviour here ever needs to change, check whether checkout.js's copy
 * needs the same change.
 */
(function () {
	'use strict';

	var govField = document.getElementById( 'billing_state' );
	var areaField = document.getElementById( 'billing_area' );
	var govDataEl = document.getElementById( 'prime-governorate-data' );

	if ( ! govField || ! areaField || ! govDataEl ) {
		return;
	}

	var govData = JSON.parse( govDataEl.textContent || '{}' );

	var populateAreas = function ( keepSelection ) {
		var code = govField.value;
		var current = keepSelection ? areaField.value : '';
		var areas = ( govData[ code ] && govData[ code ].areas ) || [];

		areaField.innerHTML = '<option value="">' + ( areaField.getAttribute( 'data-placeholder' ) || 'Select area' ) + '</option>' +
			areas.map( function ( area ) {
				return '<option value="' + area.replace( /"/g, '&quot;' ) + '"' + ( area === current ? ' selected' : '' ) + '>' + area + '</option>';
			} ).join( '' );
	};

	var placeholderOption = areaField.querySelector( 'option[value=""]' );
	if ( placeholderOption ) {
		areaField.setAttribute( 'data-placeholder', placeholderOption.textContent );
	}

	govField.addEventListener( 'change', function () {
		populateAreas( false );
	} );

	if ( govField.value ) {
		populateAreas( true );
	}
})();
