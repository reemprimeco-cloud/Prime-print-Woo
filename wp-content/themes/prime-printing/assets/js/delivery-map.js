/**
 * Delivery pin — a Leaflet map with one draggable marker.
 *
 * Fills the three hidden billing fields (billing_geo_lat / lng / acc) that
 * inc/checkout-location.php reads. Starts on the saved pin, else on the
 * phone's location, else on the chosen area's centroid, else on Kuwait.
 * Works the same on the checkout page and on the account's address page.
 */
(function () {
	'use strict';

	var cfg = window.primeDeliveryMap;
	var wrap = document.querySelector( '[data-prime-locate-wrap]' );
	var mapEl = wrap ? wrap.querySelector( '[data-prime-geo-map]' ) : null;

	if ( ! cfg || ! wrap || ! mapEl || ! window.L ) {
		return;
	}

	var L = window.L;
	var form = wrap.closest( 'form' ) || document;
	var latInput = form.querySelector( '[name="billing_geo_lat"]' );
	var lngInput = form.querySelector( '[name="billing_geo_lng"]' );
	var accInput = form.querySelector( '[name="billing_geo_acc"]' );
	var status = wrap.querySelector( '[data-prime-loc-status]' );
	var link = wrap.querySelector( '[data-prime-geo-link]' );
	var locateBtn = wrap.querySelector( '[data-prime-geo-locate]' );
	var satToggle = wrap.querySelector( '[data-prime-geo-satellite]' );
	var areaSelect = form.querySelector( '#billing_area' );
	var stateSelect = form.querySelector( '#billing_state' );

	if ( ! latInput || ! lngInput ) {
		return;
	}

	L.Icon.Default.imagePath = cfg.icons;

	var say = function ( text ) {
		if ( status ) {
			status.textContent = text;
		}
	};

	/* ---- The map ------------------------------------------------------------- */

	var start = cfg.default;
	var map = L.map( mapEl, { zoomControl: true, attributionControl: true, tap: false } ).setView( [ start.lat, start.lng ], start.zoom );

	var street = L.tileLayer( cfg.tiles.street, { maxZoom: 19, attribution: cfg.tiles.streetAttr } ).addTo( map );
	var satellite = L.tileLayer( cfg.tiles.satellite, { maxZoom: 19, attribution: cfg.tiles.satAttr } );

	if ( satToggle ) {
		satToggle.addEventListener( 'change', function () {
			if ( satToggle.checked ) {
				map.removeLayer( street );
				satellite.addTo( map );
			} else {
				map.removeLayer( satellite );
				street.addTo( map );
			}
		} );
	}

	var marker = null;
	var pinned = false;

	var writePin = function ( lat, lng, acc, text ) {
		latInput.value = lat.toFixed( 6 );
		lngInput.value = lng.toFixed( 6 );
		if ( accInput ) {
			accInput.value = acc ? String( Math.round( acc ) ) : '';
		}
		pinned = true;
		if ( link ) {
			link.href = 'https://www.google.com/maps?q=' + lat.toFixed( 6 ) + ',' + lng.toFixed( 6 );
			link.hidden = false;
		}
		if ( text ) {
			say( text );
		}
		latInput.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	};

	var placeMarker = function ( lat, lng ) {
		if ( marker ) {
			marker.setLatLng( [ lat, lng ] );
			return;
		}
		marker = L.marker( [ lat, lng ], { draggable: true, autoPan: true } ).addTo( map );
		marker.on( 'dragend', function () {
			var p = marker.getLatLng();
			writePin( p.lat, p.lng, 0, cfg.strings.moved );
		} );
	};

	var pinAt = function ( lat, lng, acc, zoom, text ) {
		placeMarker( lat, lng );
		map.setView( [ lat, lng ], zoom || Math.max( map.getZoom(), 17 ) );
		writePin( lat, lng, acc, text );
	};

	// Tap anywhere to move the pin there.
	map.on( 'click', function ( e ) {
		placeMarker( e.latlng.lat, e.latlng.lng );
		writePin( e.latlng.lat, e.latlng.lng, 0, cfg.strings.moved );
	} );

	/* ---- Where to start ------------------------------------------------------ */

	var centroidFor = function () {
		var area = areaSelect ? areaSelect.value : '';
		var state = stateSelect ? stateSelect.value : '';
		if ( area && cfg.centroids.areas[ area ] ) {
			return { at: cfg.centroids.areas[ area ], zoom: 15 };
		}
		if ( state && cfg.centroids.governorates[ state ] ) {
			return { at: cfg.centroids.governorates[ state ], zoom: 12 };
		}
		return null;
	};

	var showArea = function () {
		if ( pinned ) {
			return;
		}
		var c = centroidFor();
		if ( c ) {
			map.setView( c.at, c.zoom );
			say( cfg.strings.areaHint );
		}
	};

	var locate = function ( quiet ) {
		if ( ! navigator.geolocation ) {
			if ( ! quiet ) {
				say( cfg.strings.unsupported );
			}
			return;
		}
		say( cfg.strings.locating );
		navigator.geolocation.getCurrentPosition(
			function ( pos ) {
				var acc = pos.coords.accuracy || 0;
				pinAt( pos.coords.latitude, pos.coords.longitude, acc, 18, cfg.strings.pinnedGps.replace( '%s', String( Math.round( acc ) ) ) );
			},
			function () {
				if ( ! pinned ) {
					showArea();
				}
				if ( ! quiet ) {
					say( cfg.strings.denied );
				}
			},
			{ enableHighAccuracy: true, timeout: 12000, maximumAge: 30000 }
		);
	};

	if ( locateBtn ) {
		locateBtn.addEventListener( 'click', function () {
			locate( false );
		} );
	}

	// A saved pin (customer account) or a value already in the fields (a
	// checkout page re-rendered after a validation error) comes first.
	var savedLat = parseFloat( latInput.value ) || ( cfg.pin && cfg.pin.lat ) || 0;
	var savedLng = parseFloat( lngInput.value ) || ( cfg.pin && cfg.pin.lng ) || 0;
	if ( savedLat && savedLng ) {
		pinAt( savedLat, savedLng, parseFloat( accInput ? accInput.value : 0 ) || ( cfg.pin && cfg.pin.acc ) || 0, 17, cfg.strings.pinned );
	} else {
		showArea();
		// Ask for the phone's position quietly; a refusal just leaves the area view.
		locate( true );
	}

	// Area or governorate changed before any pin: follow it.
	[ areaSelect, stateSelect ].forEach( function ( sel ) {
		if ( sel ) {
			sel.addEventListener( 'change', showArea );
		}
	} );

	/* ---- Show / hide with the address type and the delivery method ------------ */

	var visible = function () {
		var type = form.querySelector( 'input[name="prime_address_type"]:checked' );
		var ship = form.querySelector( 'input[name^="shipping_method"]:checked' ) || form.querySelector( 'input[name^="shipping_method"][type="hidden"]' );
		var gift = type && 'gift' === type.value;
		var pickup = ship && /^local_pickup/.test( ship.value );
		return ! gift && ! pickup;
	};

	var refresh = function () {
		wrap.classList.toggle( 'is-hidden', ! visible() );
		if ( visible() ) {
			// A map created while hidden has no size; tell it to measure again.
			setTimeout( function () {
				map.invalidateSize();
			}, 50 );
		}
	};

	form.addEventListener( 'change', function ( e ) {
		var n = e.target && e.target.name ? e.target.name : '';
		if ( 'prime_address_type' === n || 0 === n.indexOf( 'shipping_method' ) ) {
			refresh();
		}
	} );
	if ( window.jQuery ) {
		window.jQuery( document.body ).on( 'updated_checkout', refresh );
	}
	refresh();
})();
