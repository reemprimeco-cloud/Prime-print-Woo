/**
 * PP Sticker / waterproof vinyl (product id 235) — live price preview.
 *
 * A PREVIEW ONLY: nothing computed here is ever sent as a price.
 * prime_pp_sticker_price() in inc/pp-sticker-calculator.php recomputes the
 * real price server-side on every cart calculation. The math is ported
 * line-for-line from Reem's reference calculator, with the constants read
 * from data attributes PHP emits rather than redeclared here.
 */
(function () {
	'use strict';

	var calc = document.querySelector( '[data-pp-sticker-calculator]' );

	if ( ! calc ) {
		return;
	}

	var sheetWidth = parseFloat( calc.getAttribute( 'data-sheet-width' ) ) || 82;
	var sheetHeight = parseFloat( calc.getAttribute( 'data-sheet-height' ) ) || 32;
	var spacing = parseFloat( calc.getAttribute( 'data-spacing' ) ) || 0.2;
	var pricePerSheet = parseFloat( calc.getAttribute( 'data-price-per-sheet' ) ) || 5;
	var laminationPrice = parseFloat( calc.getAttribute( 'data-lamination-price' ) ) || 0;
	var bulkQty = parseInt( calc.getAttribute( 'data-bulk-qty' ), 10 ) || 10;
	var bulkPct = parseFloat( calc.getAttribute( 'data-bulk-pct' ) ) || 0;
	var largeOrder = parseInt( calc.getAttribute( 'data-large-order' ), 10 ) || 1000;
	var currency = calc.getAttribute( 'data-currency' ) || '';

	var widthInput = calc.querySelector( '#pp-w' );
	var heightInput = calc.querySelector( '#pp-h' );
	var quantityInput = calc.querySelector( '#pp-q' );
	var laminationInput = calc.querySelector( '#pp-lam' );

	var perSheetOut = calc.querySelector( '[data-pp-per-sheet]' );
	var sheetsOut = calc.querySelector( '[data-pp-sheets]' );
	var baseOut = calc.querySelector( '[data-pp-base]' );
	var discountOut = calc.querySelector( '[data-pp-discount]' );
	var discountBox = calc.querySelector( '[data-pp-discount-box]' );
	var totalOut = calc.querySelector( '[data-pp-total]' );
	var largeNote = calc.querySelector( '[data-pp-large]' );
	var summaryPrice = document.querySelector( '.prime-product__summary .price' );

	var format = function ( value ) {
		return value.toFixed( 3 ) + ' ' + currency;
	};

	var calculate = function ( width, height, quantity, lamination ) {
		var across = Math.floor( sheetWidth / ( width + spacing ) );
		var down = Math.floor( sheetHeight / ( height + spacing ) );
		var piecesPerSheet = across * down;

		if ( piecesPerSheet < 1 ) {
			return null;
		}

		var sheets = Math.ceil( quantity / piecesPerSheet );
		var baseCost = sheets * pricePerSheet;
		var discount = sheets >= bulkQty ? baseCost * bulkPct : 0;
		var laminationCost = lamination ? sheets * laminationPrice : 0;

		return {
			piecesPerSheet: piecesPerSheet,
			sheets: sheets,
			baseCost: baseCost,
			discount: discount,
			total: ( baseCost - discount ) + laminationCost
		};
	};

	var render = function () {
		var width = parseFloat( widthInput.value ) || 0;
		var height = parseFloat( heightInput.value ) || 0;
		var quantity = parseInt( quantityInput.value, 10 ) || 0;
		var lamination = laminationInput ? laminationInput.checked : false;

		if ( largeNote ) {
			largeNote.hidden = quantity <= largeOrder;
		}

		var result = ( width > 0 && height > 0 && quantity > 0 )
			? calculate( width, height, quantity, lamination )
			: null;

		if ( ! result ) {
			// Either nothing entered yet, or a sticker bigger than the sheet —
			// the server refuses that size too, so no price is shown for it.
			perSheetOut.textContent = ( width > 0 && height > 0 ) ? '0' : '—';
			sheetsOut.textContent = '—';
			baseOut.textContent = '—';
			totalOut.textContent = '—';
			if ( discountBox ) {
				discountBox.hidden = true;
			}
			return;
		}

		perSheetOut.textContent = result.piecesPerSheet;
		sheetsOut.textContent = result.sheets;
		baseOut.textContent = format( result.baseCost );
		totalOut.textContent = format( result.total );

		if ( discountBox ) {
			discountBox.hidden = result.discount <= 0;
			if ( discountOut && result.discount > 0 ) {
				discountOut.textContent = '−' + format( result.discount );
			}
		}

		if ( summaryPrice ) {
			summaryPrice.textContent = format( result.total );
		}
	};

	Array.prototype.forEach.call(
		calc.querySelectorAll( '[data-pp-input]' ),
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
