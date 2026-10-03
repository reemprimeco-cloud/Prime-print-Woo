/**
 * Custom Paper Bag — live price preview and the flat-sheet drawing.
 *
 * A PREVIEW ONLY: nothing computed here is ever sent as a price. Only the
 * width, height, depth and quantity are submitted; prime_paper_bag_price()
 * in inc/paper-bag-calculator.php recomputes the real price server-side on
 * every cart calculation. The constants are read from data attributes PHP
 * emits rather than redeclared, so there is one place they can drift.
 *
 * The drawing is the same layout the designer uses (glue flap | front |
 * side | back | side across; top fold | body | base down), so what the
 * customer sees here is what they will design on.
 */
(function () {
	'use strict';

	var calc = document.querySelector( '[data-paper-bag-calculator]' );

	if ( ! calc ) {
		return;
	}

	var json = function ( attr, fallback ) {
		try {
			return JSON.parse( calc.getAttribute( attr ) || '' );
		} catch ( e ) {
			return fallback;
		}
	};

	var limits = json( 'data-limits', { w: [ 6, 50 ], h: [ 8, 60 ], d: [ 3, 25 ] } );
	var build = json( 'data-construction', { glue_cm: 1.5, top_fold_cm: 3, base_extra_cm: 1.5, bleed_cm: 0.3 } );
	var tiers = json( 'data-tiers', [] );
	var sheetPerM2 = parseFloat( calc.getAttribute( 'data-sheet-per-m2' ) ) || 0;
	var makingEach = parseFloat( calc.getAttribute( 'data-making-each' ) ) || 0;
	var setup = parseFloat( calc.getAttribute( 'data-setup' ) ) || 0;
	var minOrder = parseFloat( calc.getAttribute( 'data-min-order' ) ) || 0;
	var tierAbove = parseFloat( calc.getAttribute( 'data-tier-above' ) ) || 1;
	var currency = calc.getAttribute( 'data-currency' ) || '';

	var wInput = calc.querySelector( '#bag-w' );
	var hInput = calc.querySelector( '#bag-h' );
	var dInput = calc.querySelector( '#bag-d' );
	var qInput = calc.querySelector( '#bag-q' );

	var eachOut = calc.querySelector( '[data-bag-each]' );
	var setupOut = calc.querySelector( '[data-bag-setup]' );
	var totalOut = calc.querySelector( '[data-bag-total]' );
	var sheetOut = calc.querySelector( '[data-bag-sheet-size]' );
	var svg = calc.querySelector( '[data-bag-svg]' );
	var summaryPrice = document.querySelector( '.prime-product__summary .price' );

	var format = function ( value ) {
		return value.toFixed( 3 ) + ' ' + currency;
	};

	var inRange = function ( v, range ) {
		return v >= range[ 0 ] && v <= range[ 1 ];
	};

	var factorFor = function ( quantity ) {
		for ( var i = 0; i < tiers.length; i++ ) {
			if ( quantity >= tiers[ i ].min && quantity <= tiers[ i ].max ) {
				return parseFloat( tiers[ i ].factor );
			}
		}
		return tierAbove;
	};

	var sheetFor = function ( w, h, d ) {
		return {
			w: Math.round( ( build.glue_cm + 2 * w + 2 * d ) * 10 ) / 10,
			h: Math.round( ( build.top_fold_cm + h + d / 2 + build.base_extra_cm ) * 10 ) / 10
		};
	};

	var calculate = function ( w, h, d, quantity ) {
		var sheet = sheetFor( w, h, d );
		var area = ( ( sheet.w + 2 * build.bleed_cm ) / 100 ) * ( ( sheet.h + 2 * build.bleed_cm ) / 100 );
		var factor = factorFor( quantity );
		var each = ( area * sheetPerM2 + makingEach ) * factor;
		var total = Math.max( minOrder, setup + each * quantity );
		return { sheet: sheet, each: each, setup: setup, total: total };
	};

	/* ---- The flat sheet drawing ------------------------------------------ */

	var SVG = 'http://www.w3.org/2000/svg';
	var el = function ( name, attrs, text ) {
		var node = document.createElementNS( SVG, name );
		Object.keys( attrs ).forEach( function ( k ) {
			node.setAttribute( k, attrs[ k ] );
		} );
		if ( text ) {
			node.textContent = text;
		}
		return node;
	};

	var labels = json( 'data-labels', null ) || { glue: 'GLUE', top: 'TOP FOLD', front: 'FRONT', side: 'SIDE', back: 'BACK', base: 'BASE' };

	var draw = function ( w, h, d ) {
		if ( ! svg ) {
			return;
		}
		while ( svg.firstChild ) {
			svg.removeChild( svg.firstChild );
		}

		var g = build.glue_cm;
		var t = build.top_fold_cm;
		var b = d / 2 + build.base_extra_cm;
		var sw = g + 2 * w + 2 * d;
		var sh = t + h + b;
		var pad = 1;
		svg.setAttribute( 'viewBox', ( -pad ) + ' ' + ( -pad ) + ' ' + ( sw + 2 * pad ) + ' ' + ( sh + 2 * pad ) );

		var stroke = Math.max( 0.12, sw / 400 );
		var fontBig = Math.min( h * 0.14, w * 0.22 );
		var fontSmall = Math.min( fontBig, d * 0.26 );

		svg.appendChild( el( 'rect', { x: 0, y: 0, width: sw, height: sh, fill: '#fff', stroke: '#EC008C', 'stroke-width': stroke } ) );
		// Hidden parts: the glue flap and the top fold.
		svg.appendChild( el( 'rect', { x: 0, y: 0, width: g, height: sh, fill: '#1f2937', 'fill-opacity': 0.08 } ) );
		svg.appendChild( el( 'rect', { x: g, y: 0, width: sw - g, height: t, fill: '#1f2937', 'fill-opacity': 0.08 } ) );

		var fold = function ( x1, y1, x2, y2 ) {
			svg.appendChild( el( 'line', { x1: x1, y1: y1, x2: x2, y2: y2, stroke: '#00AEEF', 'stroke-width': stroke, 'stroke-dasharray': ( stroke * 5 ) + ' ' + ( stroke * 3 ) } ) );
		};
		var cols = [ g, g + w, g + w + d / 2, g + w + d, g + 2 * w + d, g + 2 * w + d + d / 2 ];
		cols.forEach( function ( x ) {
			fold( x, 0, x, sh );
		} );
		fold( g, t, sw, t );
		fold( g, t + h, sw, t + h );
		[ g + w, g + 2 * w + d ].forEach( function ( x0 ) {
			var tip = Math.min( t + h + d / 2, sh );
			fold( x0, t + h, x0 + d / 2, tip );
			fold( x0 + d, t + h, x0 + d / 2, tip );
		} );

		var label = function ( x, y, text, size, vertical ) {
			var attrs = { x: x, y: y, 'text-anchor': 'middle', 'dominant-baseline': 'central', 'font-family': 'Poppins, Arial, sans-serif', 'font-weight': 700, 'font-size': size, fill: '#10254a', 'fill-opacity': 0.55 };
			if ( vertical ) {
				attrs.transform = 'rotate(-90 ' + x + ' ' + y + ')';
			}
			svg.appendChild( el( 'text', attrs, text ) );
		};
		label( g / 2, sh / 2, labels.glue, Math.min( g * 0.6, h * 0.08 ), true );
		label( g + ( sw - g ) / 2, t / 2, labels.top, Math.min( t * 0.5, fontSmall ) );
		label( g + w / 2, t + h / 2, labels.front, fontBig );
		label( g + w + d / 2, t + h / 2, labels.side, fontSmall );
		label( g + w + d + w / 2, t + h / 2, labels.back, fontBig );
		label( g + 2 * w + d + d / 2, t + h / 2, labels.side, fontSmall );
		label( g + ( sw - g ) / 2, t + h + b / 2, labels.base, Math.min( b * 0.5, fontSmall ) );

		// Handle holes.
		[ g, g + w + d ].forEach( function ( x0 ) {
			[ w / 4, ( 3 * w ) / 4 ].forEach( function ( dx ) {
				svg.appendChild( el( 'circle', { cx: x0 + dx, cy: t + 2, r: 0.3, fill: 'none', stroke: '#EC008C', 'stroke-width': stroke } ) );
			} );
		} );
	};

	/* ---- Render ------------------------------------------------------------ */

	var read = function () {
		return {
			w: Math.round( ( parseFloat( wInput.value ) || 0 ) * 10 ) / 10,
			h: Math.round( ( parseFloat( hInput.value ) || 0 ) * 10 ) / 10,
			d: Math.round( ( parseFloat( dInput.value ) || 0 ) * 10 ) / 10,
			q: parseInt( qInput.value, 10 ) || 0
		};
	};

	var valid = function ( v ) {
		return inRange( v.w, limits.w ) && inRange( v.h, limits.h ) && inRange( v.d, limits.d );
	};

	var render = function () {
		var v = read();

		if ( ! valid( v ) ) {
			eachOut.textContent = '—';
			setupOut.textContent = '—';
			totalOut.textContent = '—';
			sheetOut.textContent = '—';
			return;
		}

		draw( v.w, v.h, v.d );
		var sheet = sheetFor( v.w, v.h, v.d );
		sheetOut.textContent = sheet.w + ' × ' + sheet.h + ' cm';

		if ( v.q < 1 ) {
			eachOut.textContent = '—';
			setupOut.textContent = '—';
			totalOut.textContent = '—';
			return;
		}

		var result = calculate( v.w, v.h, v.d, v.q );
		eachOut.textContent = format( result.each );
		setupOut.textContent = format( result.setup );
		totalOut.textContent = format( result.total );

		if ( summaryPrice ) {
			summaryPrice.textContent = format( result.total );
		}
	};

	Array.prototype.forEach.call(
		calc.querySelectorAll( '[data-bag-input]' ),
		function ( input ) {
			input.addEventListener( 'input', render );
			input.addEventListener( 'change', render );
		}
	);

	render();

	var form = calc.closest( 'form.cart' );

	if ( form ) {
		form.addEventListener( 'submit', function ( event ) {
			var v = read();
			var invalid = false;

			[ [ wInput, v.w, limits.w ], [ hInput, v.h, limits.h ], [ dInput, v.d, limits.d ] ].forEach( function ( f ) {
				var field = f[ 0 ].closest( '.prime-field' );
				var bad = ! inRange( f[ 1 ], f[ 2 ] );
				field.classList.toggle( 'is-invalid', bad );
				invalid = invalid || bad;
			} );

			var qBad = v.q < 1;
			qInput.closest( '.prime-field' ).classList.toggle( 'is-invalid', qBad );
			invalid = invalid || qBad;

			if ( invalid ) {
				event.preventDefault();
				var target = calc.querySelector( '.is-invalid' );
				if ( target && target.scrollIntoView ) {
					target.scrollIntoView( { behavior: 'smooth', block: 'center' } );
				}
			}
		} );
	}
})();
