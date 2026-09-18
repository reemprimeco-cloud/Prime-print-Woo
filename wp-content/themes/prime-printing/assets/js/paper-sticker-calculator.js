/**
 * Paper Sticker (product id 2090) — live price preview.
 *
 * A PREVIEW ONLY: nothing computed here is ever sent as a price. Only the
 * shape, size, quantity, lamination choice and artwork file are submitted;
 * prime_paper_sticker_price() in inc/paper-sticker-calculator.php recomputes
 * the real price server-side from those on every cart calculation.
 *
 * The math is ported line-for-line from Reem's reference calculator, and the
 * constants are read from data attributes PHP emits rather than redeclared,
 * so there is one place they can drift from her real figures.
 */
(function () {
	'use strict';

	var calc = document.querySelector( '[data-paper-sticker-calculator]' );

	if ( ! calc ) {
		return;
	}

	var sheetWidth = parseFloat( calc.getAttribute( 'data-sheet-width' ) ) || 26;
	var sheetHeight = parseFloat( calc.getAttribute( 'data-sheet-height' ) ) || 62;
	var spacing = parseFloat( calc.getAttribute( 'data-spacing' ) ) || 0.2;
	var laminationPrice = parseFloat( calc.getAttribute( 'data-lamination-price' ) ) || 0;
	var tierAbove = parseFloat( calc.getAttribute( 'data-tier-above' ) ) || 0.8;
	var currency = calc.getAttribute( 'data-currency' ) || '';

	var tiers = [];
	try {
		tiers = JSON.parse( calc.getAttribute( 'data-tiers' ) || '[]' );
	} catch ( e ) {
		tiers = [];
	}

	var widthInput = calc.querySelector( '#paper-w' );
	var heightInput = calc.querySelector( '#paper-h' );
	var quantityInput = calc.querySelector( '#paper-q' );
	var laminationInput = calc.querySelector( '#paper-lam' );

	var perSheetOut = calc.querySelector( '[data-paper-per-sheet]' );
	var sheetsOut = calc.querySelector( '[data-paper-sheets]' );
	var sheetPriceOut = calc.querySelector( '[data-paper-sheet-price]' );
	var sheetCostOut = calc.querySelector( '[data-paper-sheet-cost]' );
	var totalOut = calc.querySelector( '[data-paper-total]' );
	var summaryPrice = document.querySelector( '.prime-product__summary .price' );

	var format = function ( value ) {
		return value.toFixed( 3 ) + ' ' + currency;
	};

	var sheetPriceFor = function ( sheets ) {
		for ( var i = 0; i < tiers.length; i++ ) {
			if ( sheets >= tiers[ i ].min && sheets <= tiers[ i ].max ) {
				return parseFloat( tiers[ i ].price );
			}
		}
		return tierAbove;
	};

	var calculate = function ( width, height, quantity, lamination ) {
		var across = Math.floor( sheetWidth / ( width + spacing ) );
		var down = Math.floor( sheetHeight / ( height + spacing ) );
		var piecesPerSheet = across * down;

		if ( piecesPerSheet < 1 ) {
			return null;
		}

		var sheets = Math.ceil( quantity / piecesPerSheet );
		var sheetPrice = sheetPriceFor( sheets );
		var sheetCost = sheets * sheetPrice;
		var laminationCost = lamination ? sheets * laminationPrice : 0;

		return {
			piecesPerSheet: piecesPerSheet,
			sheets: sheets,
			sheetPrice: sheetPrice,
			sheetCost: sheetCost,
			total: sheetCost + laminationCost
		};
	};

	var render = function () {
		var width = parseFloat( widthInput.value ) || 0;
		var height = parseFloat( heightInput.value ) || 0;
		var quantity = parseInt( quantityInput.value, 10 ) || 0;
		var lamination = laminationInput ? laminationInput.checked : false;

		var result = ( width > 0 && height > 0 && quantity > 0 )
			? calculate( width, height, quantity, lamination )
			: null;

		if ( ! result ) {
			// Either nothing entered yet, or a design too big for the sheet —
			// the server refuses that size too, so no price is shown for it.
			perSheetOut.textContent = ( width > 0 && height > 0 ) ? '0' : '—';
			sheetsOut.textContent = '—';
			sheetPriceOut.textContent = '—';
			sheetCostOut.textContent = '—';
			totalOut.textContent = '—';
			return;
		}

		perSheetOut.textContent = result.piecesPerSheet;
		sheetsOut.textContent = result.sheets;
		sheetPriceOut.textContent = format( result.sheetPrice );
		sheetCostOut.textContent = format( result.sheetCost );
		totalOut.textContent = format( result.total );

		if ( summaryPrice ) {
			summaryPrice.textContent = format( result.total );
		}
	};

	Array.prototype.forEach.call(
		calc.querySelectorAll( '[data-paper-input]' ),
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
			var quantity = parseInt( quantityInput.value, 10 ) || 0;
			var invalid = false;

			if ( quantity < 1 ) {
				quantityInput.closest( '.prime-field' ).classList.add( 'is-invalid' );
				invalid = true;
			}

			if ( width < 0.1 || height < 0.1 || ! calculate( width, height, Math.max( 1, quantity ), false ) ) {
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
