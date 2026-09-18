/**
 * Diecut Cards (product id 2041) — live price preview.
 *
 * A PREVIEW ONLY: nothing computed here is ever sent as a price.
 * prime_diecut_price() in inc/diecut-cards-calculator.php recomputes the real
 * price server-side on every cart calculation. The nesting and pricing math
 * is ported line-for-line from Reem's reference (diecut_public_calculator.html),
 * with the constants read from data attributes PHP emits rather than
 * redeclared here.
 */
(function () {
	'use strict';

	var calc = document.querySelector( '[data-diecut-calculator]' );

	if ( ! calc ) {
		return;
	}

	var n = function ( attr, fallback ) {
		var v = parseFloat( calc.getAttribute( attr ) );
		return isNaN( v ) ? fallback : v;
	};

	var sheetW = n( 'data-sheet-w', 31 );
	var sheetH = n( 'data-sheet-h', 46 );
	var gap = n( 'data-gap', 0.1 );
	// Cost per printed sheet: print + diecut. Lamination is charged on top.
	var sheetCost = n( 'data-sheet-cost', 0.5 );
	var lamCost = n( 'data-lamination-cost', 0.05 );
	var twoSideCost = n( 'data-two-side-cost', 0.05 );
	var minQty = parseInt( calc.getAttribute( 'data-min-qty' ), 10 ) || 50;
	var minPrice = n( 'data-min-price', 0 );
	var currency = calc.getAttribute( 'data-currency' ) || '';

	var widthInput = calc.querySelector( '#diecut-w' );
	var heightInput = calc.querySelector( '#diecut-h' );
	var qtyInput = calc.querySelector( '#diecut-q' );
	var lamInput = calc.querySelector( '#diecut-lam' );
	var sidesInput = calc.querySelector( '#diecut-sides' );

	var yieldOut = calc.querySelector( '[data-diecut-yield]' );
	var sheetsOut = calc.querySelector( '[data-diecut-sheets]' );
	var unitOut = calc.querySelector( '[data-diecut-unit]' );
	var totalOut = calc.querySelector( '[data-diecut-total]' );
	var tooBig = calc.querySelector( '[data-diecut-toobig]' );
	var summaryPrice = document.querySelector( '.prime-product__summary .price' );

	var format = function ( value ) {
		return value.toFixed( 3 ) + ' ' + currency;
	};

	var markupFor = function ( qty ) {
		if ( qty <= 500 ) {
			return 0.60;
		}
		if ( qty <= 1000 ) {
			return 0.45;
		}
		return 0.35;
	};

	// Both orientations; a tie keeps the unrotated layout, matching the
	// reference (it only replaces the best on a strictly greater yield).
	var nest = function ( w, h ) {
		var tryOri = function ( pw, ph ) {
			var cols = Math.floor( sheetW / ( pw + gap ) );
			var rows = Math.floor( sheetH / ( ph + gap ) );
			if ( cols < 1 || rows < 1 ) {
				return null;
			}
			return { cols: cols, rows: rows, yield: cols * rows };
		};

		var normal = tryOri( w, h );
		var rotated = tryOri( h, w );
		var best = null;

		if ( normal ) {
			best = normal;
			best.rotated = false;
		}
		if ( rotated && ( ! best || rotated.yield > best.yield ) ) {
			best = rotated;
			best.rotated = true;
		}

		return best;
	};

	var render = function () {
		var width = parseFloat( widthInput.value ) || 0;
		var height = parseFloat( heightInput.value ) || 0;
		var qty = parseInt( qtyInput.value, 10 ) || 0;

		var fit = ( width > 0 && height > 0 ) ? nest( width, height ) : null;

		if ( tooBig ) {
			tooBig.hidden = ! ( width > 0 && height > 0 && ! fit );
		}

		if ( ! fit || qty < minQty ) {
			// Either the size doesn't fit the sheet, or the run is under the
			// minimum — the server refuses both, so no price is shown.
			yieldOut.textContent = fit ? fit.yield : '—';
			sheetsOut.textContent = '—';
			unitOut.textContent = '—';
			totalOut.textContent = '—';
			return;
		}

		var sheets = Math.ceil( qty / fit.yield );
		// Any chosen finish (matt or gloss) adds the same per-sheet charge, and
		// so does printing the second side. Neither rate is shown to the
		// customer — only the total moves.
		var lam = lamInput && lamInput.value ? lamCost : 0;
		var second = sidesInput && 'double' === sidesInput.value ? twoSideCost : 0;
		var total = sheets * ( sheetCost + lam + second ) * ( 1 + markupFor( qty ) );
		// Minimum job charge — mirrors the same floor in prime_diecut_price().
		total = Math.max( total, minPrice );
		total = Number( total.toFixed( 3 ) );

		yieldOut.textContent = fit.yield;
		sheetsOut.textContent = sheets;
		unitOut.textContent = format( total / qty );
		totalOut.textContent = format( total );

		if ( summaryPrice ) {
			summaryPrice.textContent = format( total );
		}
	};

	Array.prototype.forEach.call(
		calc.querySelectorAll( '[data-diecut-input]' ),
		function ( input ) {
			input.addEventListener( 'input', render );
			input.addEventListener( 'change', render );
		}
	);

	render();

	var form = calc.closest( 'form.cart' );

	if ( form ) {
		form.addEventListener( 'submit', function ( event ) {
			var width = parseFloat( widthInput.value ) || 0;
			var height = parseFloat( heightInput.value ) || 0;
			var qty = parseInt( qtyInput.value, 10 ) || 0;
			var invalid = false;

			if ( qty < minQty ) {
				qtyInput.closest( '.prime-field' ).classList.add( 'is-invalid' );
				invalid = true;
			}

			if ( width < 0.1 || height < 0.1 || ! nest( width, height ) ) {
				widthInput.closest( '.prime-field' ).classList.add( 'is-invalid' );
				heightInput.closest( '.prime-field' ).classList.add( 'is-invalid' );
				invalid = true;
			}

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
