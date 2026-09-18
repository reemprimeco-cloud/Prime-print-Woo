/**
 * UV DTF Sticker (product id 4641) — live price preview.
 *
 * A PREVIEW ONLY, same rule as every other custom-priced product in this
 * theme: nothing computed here is ever sent as a price. Only width, height,
 * quantity and the artwork file are submitted; inc/uv-dtf-calculator.php
 * recomputes the real price server-side from those, every time.
 *
 * The math below is ported line-for-line from Reem's own reference,
 * UV_DTF_WooCommerce_Calculator.html, and from prime_uv_dtf_price() in PHP —
 * the cost constants are read from data attributes that PHP function emits
 * rather than redeclared here, so there is exactly one place they can drift
 * from her real figures.
 */
(function () {
	'use strict';

	var calc = document.querySelector( '[data-uvdtf-calculator]' );

	if ( ! calc ) {
		return;
	}

	var rollWidth = parseFloat( calc.getAttribute( 'data-roll-width' ) ) || 60;
	var filmPerCm = parseFloat( calc.getAttribute( 'data-film-per-cm' ) ) || 0;
	var inkPerCm = parseFloat( calc.getAttribute( 'data-ink-per-cm' ) ) || 0;
	var shippingPerCm = parseFloat( calc.getAttribute( 'data-shipping-per-cm' ) ) || 0;
	var fixedPerCm = parseFloat( calc.getAttribute( 'data-fixed-per-cm' ) ) || 0;
	var margin = parseFloat( calc.getAttribute( 'data-margin' ) ) || 0.5;
	var minOrder = parseFloat( calc.getAttribute( 'data-min-order' ) ) || 0;
	var spacing = parseFloat( calc.getAttribute( 'data-spacing' ) ) || 0.6;
	var cuttingPerPiece = parseFloat( calc.getAttribute( 'data-cutting-per-piece' ) ) || 0;
	var currency = calc.getAttribute( 'data-currency' ) || '';

	var widthInput = calc.querySelector( '#uvdtf-w' );
	var heightInput = calc.querySelector( '#uvdtf-h' );
	var quantityInput = calc.querySelector( '#uvdtf-q' );
	var cuttingInput = calc.querySelector( '#uvdtf-cutting' );

	var lengthOut = calc.querySelector( '[data-uvdtf-length]' );
	var totalOut = calc.querySelector( '[data-uvdtf-total]' );
	var pieceOut = calc.querySelector( '[data-uvdtf-piece]' );
	var summaryPrice = document.querySelector( '.prime-product__summary .price' );

	var format = function ( value ) {
		return value.toFixed( 3 ) + ' ' + currency;
	};

	// The roll-layout formula — see prime_uv_dtf_price() in
	// inc/uv-dtf-calculator.php for the authoritative, server-side twin.
	var calculate = function ( width, height, quantity, needCutting ) {
		width = Math.max( 0.1, width );
		height = Math.max( 0.1, height );
		quantity = Math.max( 1, quantity );

		var designsAcross = 1;
		for ( var n = 1; n <= 100; n++ ) {
			var widthNeeded = ( n * width ) + ( ( n - 1 ) * spacing );
			if ( widthNeeded <= rollWidth + 0.5 ) {
				designsAcross = n;
			} else {
				break;
			}
		}

		var rowsNeeded = Math.ceil( quantity / designsAcross );
		var sheetLengthCm = Math.ceil( ( rowsNeeded * height ) + ( ( rowsNeeded - 1 ) * spacing ) );

		var totalCostPerCm = filmPerCm + inkPerCm + shippingPerCm + fixedPerCm;
		var ratePerCm = totalCostPerCm / ( 1 - margin );

		var sheetTotal = sheetLengthCm * ratePerCm;
		sheetTotal = Math.max( sheetTotal, minOrder );
		sheetTotal = Number( sheetTotal.toFixed( 3 ) );

		// Added after the minimum-order floor, same as the server — see the
		// docblock on prime_uv_dtf_price() for why.
		var cuttingCost = needCutting ? Number( ( quantity * cuttingPerPiece ).toFixed( 3 ) ) : 0;
		var total = Number( ( sheetTotal + cuttingCost ).toFixed( 3 ) );

		return {
			sheetLength: sheetLengthCm,
			cuttingCost: cuttingCost,
			total: total,
			perPiece: total / quantity
		};
	};

	var render = function () {
		var width = widthInput ? parseFloat( widthInput.value ) || 0 : 0;
		var height = heightInput ? parseFloat( heightInput.value ) || 0 : 0;
		var quantity = quantityInput ? parseInt( quantityInput.value, 10 ) || 0 : 0;
		var needCutting = cuttingInput ? cuttingInput.checked : false;

		if ( ! width || ! height || ! quantity ) {
			if ( lengthOut ) { lengthOut.textContent = '—'; }
			if ( totalOut ) { totalOut.textContent = '—'; }
			if ( pieceOut ) { pieceOut.textContent = '—'; }
			return;
		}

		var result = calculate( width, height, quantity, needCutting );

		if ( lengthOut ) {
			lengthOut.textContent = result.sheetLength + ' cm';
		}
		if ( totalOut ) {
			totalOut.textContent = format( result.total );
		}
		if ( pieceOut ) {
			pieceOut.textContent = format( result.perPiece );
		}

		// The top-of-page price badge tracks the live estimate, matching every
		// other custom-priced product's configurator in this theme.
		if ( summaryPrice ) {
			summaryPrice.textContent = format( result.perPiece );
		}
	};

	Array.prototype.forEach.call(
		calc.querySelectorAll( '[data-uvdtf-input]' ),
		function ( input ) {
			input.addEventListener( 'input', render );
			input.addEventListener( 'change', render );
		}
	);

	render();

	/* ---- Inline validation feedback on submit ------------------------------- */

	var form = calc.closest( 'form.cart' );

	if ( form ) {
		form.addEventListener( 'submit', function ( event ) {
			var invalid = false;

			if ( widthInput && ( parseFloat( widthInput.value ) || 0 ) < 0.1 ) {
				widthInput.closest( '.prime-field' ).classList.add( 'is-invalid' );
				invalid = true;
			}

			if ( heightInput && ( parseFloat( heightInput.value ) || 0 ) < 0.1 ) {
				heightInput.closest( '.prime-field' ).classList.add( 'is-invalid' );
				invalid = true;
			}

			if ( quantityInput && ( parseInt( quantityInput.value, 10 ) || 0 ) < 1 ) {
				quantityInput.closest( '.prime-field' ).classList.add( 'is-invalid' );
				invalid = true;
			}

			// Artwork is optional (Reem, 2026-09-05) — nothing here blocks
			// submit for a missing file; a customer can send it on WhatsApp
			// afterwards instead.

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
