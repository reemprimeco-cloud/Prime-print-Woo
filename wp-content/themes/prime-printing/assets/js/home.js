/**
 * Prime Printing — homepage behaviour.
 *
 * Two features: the wall reshuffle, and the configurator's live price preview.
 * Both degrade to nothing if their markup is absent — the wall still arrives
 * shuffled from PHP, and the calculator is not the path prices reach the cart by.
 */
(function () {
	'use strict';

	var reduceMotion = window.matchMedia( '(prefers-reduced-motion: reduce)' );

	/* ---- Shuffling wall --------------------------------------------------- */

	var wall = document.querySelector( '[data-prime-wall]' );

	if ( wall ) {
		var FADE_MS = 380;
		var AUTO_MS = 9000;
		var autoTimer = null;

		var reorder = function () {
			var tiles = Array.prototype.slice.call( wall.children );

			// Fisher-Yates, then re-append in the new order. Moving the existing
			// nodes rather than rewriting innerHTML keeps the images loaded and
			// keeps focus and scroll position intact.
			for ( var i = tiles.length - 1; i > 0; i-- ) {
				var j = Math.floor( Math.random() * ( i + 1 ) );
				var swap = tiles[ i ];
				tiles[ i ] = tiles[ j ];
				tiles[ j ] = swap;
			}

			var fragment = document.createDocumentFragment();

			tiles.forEach( function ( tile ) {
				fragment.appendChild( tile );
			} );

			wall.appendChild( fragment );
		};

		var shuffle = function () {
			if ( reduceMotion.matches ) {
				reorder();
				return;
			}

			wall.classList.add( 'is-shuffling' );

			window.setTimeout( function () {
				reorder();
				wall.classList.remove( 'is-shuffling' );
			}, FADE_MS );
		};

		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-prime-shuffle]' ),
			function ( button ) {
				button.addEventListener( 'click', function () {
					shuffle();
					restartAuto();
				} );
			}
		);

		/*
		 * Auto-reshuffle, but never while someone is reading the wall: it pauses
		 * on hover, on keyboard focus inside it, and whenever the tab is hidden.
		 * Content that rearranges itself under a pointer is the reason WCAG has
		 * something to say about auto-updating content.
		 */
		var stopAuto = function () {
			if ( autoTimer ) {
				window.clearInterval( autoTimer );
				autoTimer = null;
			}
		};

		var startAuto = function () {
			if ( autoTimer || reduceMotion.matches || document.hidden ) {
				return;
			}

			autoTimer = window.setInterval( shuffle, AUTO_MS );
		};

		var restartAuto = function () {
			stopAuto();
			startAuto();
		};

		wall.addEventListener( 'mouseenter', stopAuto );
		wall.addEventListener( 'mouseleave', startAuto );
		wall.addEventListener( 'focusin', stopAuto );
		wall.addEventListener( 'focusout', startAuto );

		document.addEventListener( 'visibilitychange', function () {
			if ( document.hidden ) {
				stopAuto();
				return;
			}

			startAuto();
		} );

		if ( typeof reduceMotion.addEventListener === 'function' ) {
			reduceMotion.addEventListener( 'change', function ( event ) {
				if ( event.matches ) {
					stopAuto();
					return;
				}

				startAuto();
			} );
		}

		startAuto();
	}

	/* ---- Configurator preview --------------------------------------------- */

	var calc = document.querySelector( '[data-prime-calc]' );

	if ( ! calc ) {
		return;
	}

	var total = calc.querySelector( '[data-prime-calc-total]' );
	var cta = calc.querySelector( '[data-prime-calc-cta]' );
	var inputs = calc.querySelectorAll( '[data-prime-calc-input]' );
	var width = document.getElementById( 'prime-calc-w' );
	var height = document.getElementById( 'prime-calc-h' );
	var quantity = document.getElementById( 'prime-calc-q' );

	if ( ! total || ! width || ! height || ! quantity ) {
		return;
	}

	var rate = parseFloat( calc.getAttribute( 'data-rate' ) ) || 0;
	var minimum = parseFloat( calc.getAttribute( 'data-minimum' ) ) || 0;
	var currency = calc.getAttribute( 'data-currency' ) || '';
	var baseUrl = calc.getAttribute( 'data-url' ) || '';
	var flashTimer = null;

	var values = function () {
		return {
			w: Math.max( 0, parseFloat( width.value ) || 0 ),
			h: Math.max( 0, parseFloat( height.value ) || 0 ),
			q: Math.max( 0, parseInt( quantity.value, 10 ) || 0 )
		};
	};

	/*
	 * PLACEHOLDER FORMULA — area in cm², converted to the reference's rate unit,
	 * times quantity, floored at the minimum. Phase 4c replaces this with each
	 * product's real formula, fed from the data-* attributes above so this
	 * function keeps its shape.
	 *
	 * Whatever it computes is a preview. The price that is charged is calculated
	 * in PHP at add-to-cart, from the submitted dimensions only.
	 */
	var estimate = function ( v ) {
		if ( ! v.w || ! v.h || ! v.q ) {
			return null;
		}

		return Math.max( ( v.w * v.h ) / 100 * v.q * rate, minimum );
	};

	var render = function () {
		var v = values();
		var price = estimate( v );

		if ( null === price ) {
			total.textContent = '—';
			return;
		}

		total.textContent = price.toFixed( 3 ) + ' ' + currency;

		if ( ! reduceMotion.matches ) {
			total.classList.add( 'is-updated' );
			window.clearTimeout( flashTimer );
			flashTimer = window.setTimeout( function () {
				total.classList.remove( 'is-updated' );
			}, 260 );
		}

		// The dimensions travel to the product page in the URL so the real
		// configurator opens pre-filled. The price deliberately does not.
		if ( cta && baseUrl ) {
			cta.href = baseUrl +
				( baseUrl.indexOf( '?' ) > -1 ? '&' : '?' ) +
				'prime_w=' + encodeURIComponent( v.w ) +
				'&prime_h=' + encodeURIComponent( v.h ) +
				'&prime_q=' + encodeURIComponent( v.q );
		}
	};

	Array.prototype.forEach.call( inputs, function ( input ) {
		input.addEventListener( 'input', render );
		input.addEventListener( 'change', render );
	} );

	render();
})();
