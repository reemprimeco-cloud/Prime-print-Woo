/**
 * Custom Paper Bag — the flat-sheet drawing and the quote code.
 *
 * A PREVIEW ONLY: nothing computed here is ever sent as a price. The size,
 * quantity, ordering mode and quote code are submitted, and
 * prime_paper_bag_price() in inc/paper-bag-calculator.php recomputes the
 * price server-side on every cart calculation. The constants are read from
 * data attributes PHP emits rather than redeclared. A quote code is checked
 * against the server, which answers with the price for that exact size and
 * quantity, and is checked again at add-to-cart and at checkout.
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
	var pricing = json( 'data-pricing', null ) || { sheet_per_m2: 0, making_each: 0, setup: 0, min_order: 0, tiers: [], tier_above: 1, max_online_qty: 0 };
	var currency = calc.getAttribute( 'data-currency' ) || '';
	var ajax = calc.getAttribute( 'data-ajax' ) || '';
	var nonce = calc.getAttribute( 'data-nonce' ) || '';
	var labelQuote = calc.getAttribute( 'data-label-quote' ) || 'Request a quote';
	var labelOrder = calc.getAttribute( 'data-label-order' ) || 'Add to cart';
	var quoteText = calc.getAttribute( 'data-quote-text' ) || 'Quote on request';

	var wInput = calc.querySelector( '#bag-w' );
	var hInput = calc.querySelector( '#bag-h' );
	var dInput = calc.querySelector( '#bag-d' );
	var qInput = calc.querySelector( '#bag-q' );
	var codeInput = calc.querySelector( '[data-bag-code]' );
	var codeApply = calc.querySelector( '[data-bag-code-apply]' );
	var codeMsg = calc.querySelector( '[data-bag-code-msg]' );
	var codeField = codeInput ? codeInput.closest( '.prime-field' ) : null;
	var codeHint = codeMsg ? codeMsg.textContent : '';

	var countOut = calc.querySelector( '[data-bag-count]' );
	var eachOut = calc.querySelector( '[data-bag-each]' );
	var modeInputs = calc.querySelectorAll( '[data-bag-mode]' );
	var quoteOnly = calc.querySelector( '[data-bag-quote-only]' );
	var totalOut = calc.querySelector( '[data-bag-total]' );
	var sheetOut = calc.querySelector( '[data-bag-sheet-size]' );
	var svg = calc.querySelector( '[data-bag-svg]' );
	var summaryPrice = document.querySelector( '.prime-product__summary .price' );
	var form = calc.closest( 'form.cart' );
	var button = form ? form.querySelector( '.single_add_to_cart_button' ) : null;

	// The code the server last accepted, and for which size and quantity.
	var applied = null;

	var inRange = function ( v, range ) {
		return v >= range[ 0 ] && v <= range[ 1 ];
	};

	var format = function ( value ) {
		return value.toFixed( 3 ) + ' ' + currency;
	};

	var factorFor = function ( quantity ) {
		var tiers = pricing.tiers || [];
		for ( var i = 0; i < tiers.length; i++ ) {
			if ( quantity >= tiers[ i ].min && quantity <= tiers[ i ].max ) {
				return parseFloat( tiers[ i ].factor );
			}
		}
		return parseFloat( pricing.tier_above ) || 1;
	};

	var calculate = function ( w, h, d, quantity ) {
		var sheet = sheetFor( w, h, d );
		var area = ( ( sheet.w + 2 * build.bleed_cm ) / 100 ) * ( ( sheet.h + 2 * build.bleed_cm ) / 100 );
		var each = ( area * pricing.sheet_per_m2 + pricing.making_each ) * factorFor( quantity );
		var total = Math.max( pricing.min_order, pricing.setup + each * quantity );
		return { each: each, total: total };
	};

	var pricedOnline = function ( quantity ) {
		return ! pricing.max_online_qty || quantity <= pricing.max_online_qty;
	};

	var mode = function () {
		var chosen = 'price';
		Array.prototype.forEach.call( modeInputs, function ( input ) {
			if ( input.checked ) {
				chosen = input.value;
			}
		} );
		return chosen;
	};

	var sheetFor = function ( w, h, d ) {
		return {
			w: Math.round( ( build.glue_cm + 2 * w + 2 * d ) * 10 ) / 10,
			h: Math.round( ( build.top_fold_cm + h + d / 2 + build.base_extra_cm ) * 10 ) / 10
		};
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
		svg.appendChild( el( 'rect', { x: 0, y: 0, width: g, height: sh, fill: '#1f2937', 'fill-opacity': 0.08 } ) );
		svg.appendChild( el( 'rect', { x: g, y: 0, width: sw - g, height: t, fill: '#1f2937', 'fill-opacity': 0.08 } ) );

		var fold = function ( x1, y1, x2, y2 ) {
			svg.appendChild( el( 'line', { x1: x1, y1: y1, x2: x2, y2: y2, stroke: '#00AEEF', 'stroke-width': stroke, 'stroke-dasharray': ( stroke * 5 ) + ' ' + ( stroke * 3 ) } ) );
		};
		[ g, g + w, g + w + d / 2, g + w + d, g + 2 * w + d, g + 2 * w + d + d / 2 ].forEach( function ( x ) {
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

		[ g, g + w + d ].forEach( function ( x0 ) {
			[ w / 4, ( 3 * w ) / 4 ].forEach( function ( dx ) {
				svg.appendChild( el( 'circle', { cx: x0 + dx, cy: t + 2, r: 0.3, fill: 'none', stroke: '#EC008C', 'stroke-width': stroke } ) );
			} );
		} );
	};

	/* ---- The quote code ---------------------------------------------------- */

	var read = function () {
		return {
			w: Math.round( ( parseFloat( wInput.value ) || 0 ) * 10 ) / 10,
			h: Math.round( ( parseFloat( hInput.value ) || 0 ) * 10 ) / 10,
			d: Math.round( ( parseFloat( dInput.value ) || 0 ) * 10 ) / 10,
			q: parseInt( qInput.value, 10 ) || 0
		};
	};

	var keyOf = function ( v ) {
		return v.w + 'x' + v.h + 'x' + v.d + ':' + v.q;
	};

	var setCodeState = function ( state, message ) {
		if ( codeField ) {
			codeField.classList.toggle( 'is-applied', 'applied' === state );
			codeField.classList.toggle( 'is-error', 'error' === state );
		}
		if ( codeMsg ) {
			codeMsg.textContent = message || codeHint;
		}
	};

	var showPrice = function () {
		var v = read();
		var coded = applied && applied.key === keyOf( v );
		var sized = inRange( v.w, limits.w ) && inRange( v.h, limits.h ) && inRange( v.d, limits.d ) && v.q > 0;
		var online = pricedOnline( v.q );
		var asQuote = ! coded && ( 'quote' === mode() || ! online );
		var text = '—';
		var each = '—';

		// Too many bags for the online price: only a quote is offered.
		if ( quoteOnly ) {
			quoteOnly.hidden = online;
		}
		Array.prototype.forEach.call( modeInputs, function ( input ) {
			if ( 'price' === input.value ) {
				input.disabled = ! online;
				if ( ! online && input.checked ) {
					input.checked = false;
				}
			} else if ( ! online ) {
				input.checked = true;
			}
		} );

		if ( coded ) {
			text = applied.text;
		} else if ( asQuote ) {
			text = quoteText;
		} else if ( sized ) {
			var result = calculate( v.w, v.h, v.d, v.q );
			text = format( result.total );
			each = format( result.each );
		}

		totalOut.textContent = text;
		if ( eachOut ) {
			eachOut.textContent = each;
		}
		if ( summaryPrice ) {
			summaryPrice.textContent = text;
		}
		if ( button ) {
			button.textContent = asQuote ? labelQuote : labelOrder;
		}
		if ( applied && ! coded ) {
			// The size or quantity moved away from what the code was for.
			setCodeState( 'error', applied.mismatch );
		}
	};

	var checkCode = function () {
		var code = codeInput ? codeInput.value.trim() : '';
		var v = read();

		if ( ! code ) {
			applied = null;
			setCodeState( '', '' );
			showPrice();
			return;
		}
		if ( ! ajax ) {
			return;
		}

		var body = new FormData();
		body.append( 'action', 'prime_bag_quote_check' );
		body.append( 'nonce', nonce );
		body.append( 'code', code );
		body.append( 'bag_width', v.w );
		body.append( 'bag_height', v.h );
		body.append( 'bag_depth', v.d );
		body.append( 'bag_quantity', v.q );

		if ( codeApply ) {
			codeApply.disabled = true;
		}

		fetch( ajax, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				if ( res && res.success ) {
					applied = { key: keyOf( v ), code: res.data.code, text: res.data.text, mismatch: '' };
					if ( codeInput ) {
						codeInput.value = res.data.code;
					}
					setCodeState( 'applied', res.data.text );
				} else {
					applied = null;
					setCodeState( 'error', res && res.data && res.data.message ? res.data.message : '' );
				}
				showPrice();
			} )
			.catch( function () {
				applied = null;
				setCodeState( 'error', '' );
				showPrice();
			} )
			.then( function () {
				if ( codeApply ) {
					codeApply.disabled = false;
				}
			} );
	};

	/* ---- Render ------------------------------------------------------------ */

	var render = function () {
		var v = read();

		countOut.textContent = v.q > 0 ? String( v.q ) : '—';

		if ( inRange( v.w, limits.w ) && inRange( v.h, limits.h ) && inRange( v.d, limits.d ) ) {
			draw( v.w, v.h, v.d );
			var sheet = sheetFor( v.w, v.h, v.d );
			sheetOut.textContent = sheet.w + ' × ' + sheet.h + ' cm';
		} else {
			sheetOut.textContent = '—';
		}

		showPrice();
	};

	Array.prototype.forEach.call(
		calc.querySelectorAll( '[data-bag-input]' ),
		function ( input ) {
			input.addEventListener( 'input', render );
			input.addEventListener( 'change', render );
		}
	);

	Array.prototype.forEach.call( modeInputs, function ( input ) {
		input.addEventListener( 'change', showPrice );
	} );

	if ( codeApply ) {
		codeApply.addEventListener( 'click', checkCode );
	}
	if ( codeInput ) {
		codeInput.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key ) {
				event.preventDefault();
				checkCode();
			}
		} );
		codeInput.addEventListener( 'input', function () {
			if ( applied && codeInput.value.trim().toUpperCase() !== applied.code ) {
				applied = null;
				setCodeState( '', '' );
				showPrice();
			}
		} );
	}

	render();

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
