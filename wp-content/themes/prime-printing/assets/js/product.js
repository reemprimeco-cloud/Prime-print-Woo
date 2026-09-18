/**
 * Prime Printing — single product behaviour.
 *
 * Two independent pieces:
 *   1. Gallery — swap the main image on thumbnail click/keyboard.
 *   2. Configurator preview — live area/price for custom-priced products
 *      (inc/product-pricing.php). Exactly like the homepage calculator this
 *      replaced: a PREVIEW ONLY. The real price is computed in PHP from the
 *      submitted width/height/finish when the form actually posts to
 *      add-to-cart — nothing here is trusted, nothing here is sent as a price.
 */
(function () {
	'use strict';

	/* ---- Gallery ------------------------------------------------------------ */

	var gallery = document.querySelector( '[data-prime-gallery]' );

	if ( gallery ) {
		var mainImg = gallery.querySelector( '[data-prime-gallery-main]' );
		var thumbs = gallery.querySelectorAll( '[data-prime-gallery-thumb]' );

		Array.prototype.forEach.call( thumbs, function ( thumb ) {
			thumb.addEventListener( 'click', function () {
				if ( ! mainImg ) {
					return;
				}

				mainImg.src = thumb.getAttribute( 'data-full' );

				Array.prototype.forEach.call( thumbs, function ( other ) {
					other.classList.toggle( 'is-on', other === thumb );
				} );
			} );
		} );
	}

	/* ---- Configurator preview ------------------------------------------------ */

	var calc = document.querySelector( '[data-prime-pricing]' );

	if ( ! calc ) {
		return;
	}

	var rate = parseFloat( calc.getAttribute( 'data-rate' ) ) || 0;
	var minPiece = parseFloat( calc.getAttribute( 'data-min-piece' ) ) || 0;
	var minCm = parseFloat( calc.getAttribute( 'data-min-cm' ) ) || 1;
	var maxCm = parseFloat( calc.getAttribute( 'data-max-cm' ) ) || 999;
	var currency = calc.getAttribute( 'data-currency' ) || '';

	var widthInput = calc.querySelector( '#prime-price-w' );
	var heightInput = calc.querySelector( '#prime-price-h' );
	var finishSelect = calc.querySelector( '#prime-price-finish' );
	var areaOut = calc.querySelector( '[data-prime-price-area]' );
	var pieceOut = calc.querySelector( '[data-prime-price-piece]' );
	var summaryPrice = document.querySelector( '.prime-product__summary .price' );

	var format = function ( value ) {
		return value.toFixed( 3 ) + ' ' + currency;
	};

	var finishDelta = function () {
		if ( ! finishSelect || ! finishSelect.selectedOptions.length ) {
			return 0;
		}
		return parseFloat( finishSelect.selectedOptions[ 0 ].getAttribute( 'data-delta' ) ) || 0;
	};

	var render = function () {
		var width = widthInput ? parseFloat( widthInput.value ) || 0 : 0;
		var height = heightInput ? parseFloat( heightInput.value ) || 0 : 0;

		// Fields not shown on this product (per its configured field list) do
		// not block the calculation — only fields actually rendered participate.
		if ( widthInput && heightInput ) {
			var valid = width >= minCm && width <= maxCm && height >= minCm && height <= maxCm;
			widthInput.closest( '.prime-field' ).classList.toggle( 'is-invalid', ! valid && width > 0 );
			heightInput.closest( '.prime-field' ).classList.toggle( 'is-invalid', ! valid && height > 0 );
		}

		var area = width * height;
		var pricePerPiece = Math.max( area * rate + finishDelta(), minPiece );

		if ( areaOut ) {
			areaOut.textContent = area ? area + ' cm²' : '—';
		}

		if ( pieceOut ) {
			pieceOut.textContent = format( pricePerPiece );
		}

		// The top-of-page price badge ("From X KWD") tracks the live estimate
		// once the visitor has actually entered a size, so the number they see
		// while configuring matches the one in the calculator box below it.
		if ( summaryPrice && area > 0 ) {
			summaryPrice.textContent = format( pricePerPiece );
		}
	};

	Array.prototype.forEach.call(
		calc.querySelectorAll( '[data-prime-pricing-input]' ),
		function ( input ) {
			input.addEventListener( 'input', render );
			input.addEventListener( 'change', render );
		}
	);

	render();

	/* ---- Inline validation feedback on submit ------------------------------- */

	var form = calc.closest( 'form.cart' );
	var uploadField = calc.querySelector( '[data-prime-error-field="upload"]' );
	var uploadInput = calc.querySelector( '#prime-price-file' );

	if ( form ) {
		form.addEventListener( 'submit', function ( event ) {
			var invalid = false;

			if ( widthInput && heightInput ) {
				var w = parseFloat( widthInput.value ) || 0;
				var h = parseFloat( heightInput.value ) || 0;

				if ( w < minCm || w > maxCm || h < minCm || h > maxCm ) {
					widthInput.closest( '.prime-field' ).classList.add( 'is-invalid' );
					heightInput.closest( '.prime-field' ).classList.add( 'is-invalid' );
					invalid = true;
				}
			}

			if ( uploadInput && uploadField && ! uploadInput.files.length ) {
				uploadField.classList.add( 'is-invalid' );
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

	if ( uploadInput && uploadField ) {
		uploadInput.addEventListener( 'change', function () {
			uploadField.classList.toggle( 'is-invalid', ! uploadInput.files.length );
		} );
	}
})();
