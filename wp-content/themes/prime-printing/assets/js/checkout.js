/**
 * Prime Printing — checkout behaviour.
 *
 * Everything here is presentation only. Every value it touches is a real
 * WooCommerce checkout field (inc/checkout-fields.php) that WooCommerce's own
 * form submission and prime_validate_checkout() process normally — this file
 * never invents a field, and disabling JS degrades to a plain, still-correct
 * WooCommerce checkout (three panels all present, first one submitted).
 */
(function () {
	'use strict';

	var form = document.querySelector( 'form.woocommerce-checkout' );

	if ( ! form ) {
		return;
	}

	/* ---- Address type: swap panels, no reload ------------------------------ */

	var panels = form.querySelectorAll( '[data-prime-address-panel]' );
	var locateWrap = form.querySelector( '[data-prime-locate-wrap]' );
	var typeTiles = form.querySelectorAll( '[data-prime-address-type-tile]' );
	// The delivery-address fields shared by house, apartment AND gift alike:
	// governorate, area, block, street, building, avenue, directions. Shown
	// for every type now — for gift it is optional (Reem, 2026-09-05: "same
	// field address but optional not forced only to show them the price"),
	// so only the hint below changes with the type, not the fields.
	var addressHint = form.querySelector( '[data-prime-address-optional-hint]' );

	var setAddressType = function ( type ) {
		panels.forEach( function ( panel ) {
			panel.classList.toggle( 'is-active', panel.getAttribute( 'data-prime-address-panel' ) === type );
			panel.classList.toggle( 'is-hidden', panel.getAttribute( 'data-prime-address-panel' ) !== type );
		} );

		if ( locateWrap ) {
			locateWrap.classList.toggle( 'is-hidden', 'gift' === type );
		}

		if ( addressHint ) {
			addressHint.classList.toggle( 'is-hidden', 'gift' !== type );
		}

		typeTiles.forEach( function ( tile ) {
			tile.classList.toggle( 'is-on', tile.getAttribute( 'data-prime-address-type-tile' ) === type );
		} );
	};

	typeTiles.forEach( function ( tile ) {
		var input = tile.querySelector( 'input[type="radio"]' );

		if ( ! input ) {
			return;
		}

		input.addEventListener( 'change', function () {
			setAddressType( tile.getAttribute( 'data-prime-address-type-tile' ) );
			checkSectionDone();
		} );
	} );

	/* ---- Governorate -> area ------------------------------------------------- */

	var govField = document.getElementById( 'billing_state' );
	var areaField = document.getElementById( 'billing_area' );
	var govDataEl = document.getElementById( 'prime-governorate-data' );

	if ( govField && areaField && govDataEl ) {
		var govData = JSON.parse( govDataEl.textContent || '{}' );

		var OTHER = '__other__';
		var otherRow = form.querySelector( '[data-prime-area-other]' );

		// The free-text "Area name" field only shows while Area is set to
		// "Other" — server side, prime_validate_checkout() requires it then.
		var syncOtherRow = function () {
			if ( otherRow ) {
				otherRow.classList.toggle( 'is-hidden', areaField.value !== OTHER );
			}
		};

		var populateAreas = function ( keepSelection ) {
			var code = govField.value;
			var current = keepSelection ? areaField.value : '';
			var areas = ( govData[ code ] && govData[ code ].areas ) || [];

			areaField.innerHTML = '<option value="">' + ( areaField.getAttribute( 'data-placeholder' ) || 'Select area' ) + '</option>' +
				areas.map( function ( area ) {
					return '<option value="' + area.replace( /"/g, '&quot;' ) + '"' + ( area === current ? ' selected' : '' ) + '>' + area + '</option>';
				} ).join( '' ) +
				// Always last, once a governorate is chosen: a customer whose area
				// isn't listed picks this and types it in the field below.
				( code ? '<option value="' + OTHER + '"' + ( OTHER === current ? ' selected' : '' ) + '>' + ( areaField.getAttribute( 'data-other-label' ) || 'Other — type it below' ) + '</option>' : '' );

			syncOtherRow();
		};

		// Preserve the placeholder text WooCommerce already rendered, so a
		// re-render doesn't fall back to an English string on the /ar/ tree.
		var placeholderOption = areaField.querySelector( 'option[value=""]' );
		if ( placeholderOption ) {
			areaField.setAttribute( 'data-placeholder', placeholderOption.textContent );
		}

		/*
		 * WooCommerce turns #billing_state into a select2 widget, and select2
		 * announces a pick with jQuery's .trigger('change') — which does NOT
		 * dispatch a native DOM event, so a plain addEventListener('change')
		 * never hears a real customer's tap (it only ever fired in tests that
		 * dispatched a native Event by hand). Bind through jQuery when it's
		 * there (WooCommerce guarantees it on checkout); jQuery's .on() also
		 * receives genuine native changes, so one binding covers both paths.
		 */
		var onChange = function ( el, handler ) {
			if ( window.jQuery ) {
				window.jQuery( el ).on( 'change', handler );
			} else {
				el.addEventListener( 'change', handler );
			}
		};

		onChange( govField, function () {
			populateAreas( false );
		} );

		onChange( areaField, syncOtherRow );

		// On load, if a governorate is already selected (a returning, JS-off
		// submission that failed validation and reloaded with fields
		// preserved), populate its areas and keep whichever one was posted.
		if ( govField.value ) {
			populateAreas( true );
		} else {
			syncOtherRow();
		}
	}

	/* ---- Invoice panel expand ------------------------------------------------ */

	form.querySelectorAll( '[data-prime-toggle]' ).forEach( function ( button ) {
		var target = document.getElementById( button.getAttribute( 'data-prime-toggle' ) );

		if ( ! target ) {
			return;
		}

		button.addEventListener( 'click', function () {
			target.classList.toggle( 'is-open' );
		} );
	} );

	/* ---- Phone: inline feedback (server-side check is authoritative) -------- */

	var KUWAIT_PHONE = /^[569]\d{7}$/;

	var wirePhoneFeedback = function ( input ) {
		if ( ! input ) {
			return;
		}

		var row = input.closest( '.form-row' );

		input.addEventListener( 'input', function () {
			var value = input.value.trim();

			if ( 8 === value.length && ! KUWAIT_PHONE.test( value ) ) {
				row && row.classList.add( 'woocommerce-invalid' );
			} else {
				row && row.classList.remove( 'woocommerce-invalid' );
			}

			checkSectionDone();
		} );
	};

	wirePhoneFeedback( document.getElementById( 'billing_phone' ) );
	wirePhoneFeedback( document.getElementById( 'prime_gift_phone' ) );

	/* ---- Section-complete checkmarks (cosmetic) ------------------------------ */

	var value = function ( id ) {
		var el = document.getElementById( id );
		return el ? el.value.trim() : '';
	};

	var checkSectionDone = function () {
		var detailsDone = value( 'billing_first_name' ).length > 2 && KUWAIT_PHONE.test( value( 'billing_phone' ) );

		var activeType = form.querySelector( '[data-prime-address-type-tile].is-on' );
		var type = activeType ? activeType.getAttribute( 'data-prime-address-type-tile' ) : 'house';

		// Gift collects no address here (recipient is phoned for it), so only
		// house/apartment need governorate + area — and "Other" needs its name.
		var areaOk = !! value( 'billing_area' ) && ( '__other__' !== value( 'billing_area' ) || value( 'prime_area_other' ).length > 1 );
		var addressDone = 'gift' === type || ( !! value( 'billing_state' ) && areaOk );

		if ( addressDone ) {
			if ( 'gift' === type ) {
				addressDone = value( 'prime_gift_name' ).length > 2 && KUWAIT_PHONE.test( value( 'prime_gift_phone' ) );
			} else if ( 'apartment' === type ) {
				addressDone = !! value( 'prime_block' ) && !! value( 'prime_street' ) && !! value( 'prime_house_no' ) && !! value( 'prime_floor' ) && !! value( 'prime_apartment_no' );
			} else {
				addressDone = !! value( 'prime_block' ) && !! value( 'prime_street' ) && !! value( 'prime_house_no' );
			}
		}

		var detailsSection = document.getElementById( 'prime-h1' );
		var addressSection = document.getElementById( 'prime-h2' );

		if ( detailsSection ) {
			detailsSection.classList.toggle( 'is-done', detailsDone );
		}
		if ( addressSection ) {
			addressSection.classList.toggle( 'is-done', addressDone );
		}
	};

	form.addEventListener( 'input', checkSectionDone );
	form.addEventListener( 'change', checkSectionDone );
	checkSectionDone();

	/* ---- Delivery pin (geolocation) ------------------------------------------ */

	var locateButton = form.querySelector( '[data-prime-locate]' );

	if ( locateButton ) {
		locateButton.addEventListener( 'click', function () {
			var status = form.querySelector( '[data-prime-loc-status]' );
			var hidden = document.getElementById( 'prime_maps_link' );

			if ( ! navigator.geolocation ) {
				if ( status ) {
					status.textContent = locateButton.getAttribute( 'data-unsupported-text' ) || 'Location not supported in this browser';
				}
				return;
			}

			if ( status ) {
				status.textContent = locateButton.getAttribute( 'data-locating-text' ) || 'Locating…';
			}

			navigator.geolocation.getCurrentPosition(
				function ( position ) {
					var lat = position.coords.latitude.toFixed( 6 );
					var lng = position.coords.longitude.toFixed( 6 );
					var link = 'https://www.google.com/maps?q=' + lat + ',' + lng;

					if ( hidden ) {
						hidden.value = link;
					}

					if ( status ) {
						status.innerHTML = '';
						var prefix = document.createTextNode( ( locateButton.getAttribute( 'data-pinned-text' ) || 'Pinned' ) + ' · ' );
						var anchor = document.createElement( 'a' );
						anchor.href = link;
						anchor.target = '_blank';
						anchor.rel = 'noopener';
						anchor.textContent = locateButton.getAttribute( 'data-open-maps-text' ) || 'Open in Google Maps';
						status.appendChild( prefix );
						status.appendChild( anchor );
					}
				},
				function () {
					if ( status ) {
						status.textContent = locateButton.getAttribute( 'data-error-text' ) || 'Could not get location — enable location access and try again';
					}
				},
				{ enableHighAccuracy: true, timeout: 10000 }
			);
		} );
	}

	/* ---- Recalculate the delivery fee when the address changes ---------------- */

	/*
	 * The delivery fee is quoted from the full address (Armada prices by road
	 * distance — see inc/armada.php), but WooCommerce only refreshes the order
	 * totals for *its own* address fields: country, state, postcode, city and
	 * the two address lines. Block, street and building are custom fields, so
	 * without this the customer filled the address in and the fee stayed at
	 * whatever the governorate fallback said. Added 2026-09-05.
	 *
	 * `update_checkout` is debounced because it fires an AJAX round trip; the
	 * delay lets someone finish typing a block number first. `billing_state`
	 * is deliberately not listed — WooCommerce already watches it.
	 */
	if ( window.jQuery ) {
		var $ = window.jQuery;
		var refreshTimer = null;

		var scheduleRefresh = function () {
			clearTimeout( refreshTimer );
			refreshTimer = setTimeout( function () {
				$( document.body ).trigger( 'update_checkout' );
			}, 700 );
		};

		var refreshOn = [
			'#billing_area',
			'#prime_area_other',
			'#prime_block',
			'#prime_street',
			'#prime_house_no',
			'#prime_floor',
			'#prime_apartment_no',
			'input[name="prime_address_type"]'
		].join( ', ' );

		$( form ).on( 'change input', refreshOn, scheduleRefresh );
	}

	/* ---- Payment tiles --------------------------------------------------------- */

	var paymentTiles = document.querySelectorAll( '[data-prime-payment-tile]' );
	var paymentBoxes = document.querySelectorAll( '[data-prime-payment-box]' );

	paymentTiles.forEach( function ( tile ) {
		var input = tile.querySelector( 'input[type="radio"]' );

		if ( ! input ) {
			return;
		}

		input.addEventListener( 'change', function () {
			paymentTiles.forEach( function ( other ) {
				other.classList.toggle( 'is-on', other === tile );
			} );

			paymentBoxes.forEach( function ( box ) {
				box.classList.toggle( 'is-on', box.getAttribute( 'data-prime-payment-box' ) === input.value );
			} );
		} );
	} );
})();
