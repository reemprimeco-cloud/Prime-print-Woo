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
		/* Staggered scroll-in reveal. Without IntersectionObserver the tiles
		   simply stay visible. */
		if ( 'IntersectionObserver' in window && ! reduceMotion.matches ) {
			wall.classList.add( 'has-reveal' );

			var revealed = 0;
			var observer = new IntersectionObserver( function ( entries ) {
				entries.forEach( function ( entry ) {
					if ( ! entry.isIntersecting ) {
						return;
					}

					var tile = entry.target;
					observer.unobserve( tile );
					tile.style.transitionDelay = ( ( revealed++ % 4 ) * 90 ) + 'ms';
					tile.classList.add( 'is-in' );
					window.setTimeout( function () {
						tile.style.transitionDelay = '';
					}, 1200 );
				} );
			}, { threshold: 0.12 } );

			Array.prototype.forEach.call( wall.children, function ( tile ) {
				observer.observe( tile );
			} );
		}

		/* ---- Live swapping ------------------------------------------------
		   Every couple of seconds one tile (sometimes two) lets its photo slide
		   out toward a random side and a different product slides in from
		   another random side. The old product goes back into the reserve. */
		var pool = [];

		try {
			pool = JSON.parse( wall.getAttribute( 'data-prime-pool' ) || '[]' );
		} catch ( e ) {
			pool = [];
		}

		var DIRS = [ [ 1, 0 ], [ -1, 0 ], [ 0, 1 ], [ 0, -1 ] ];
		var SWAP_MS = 1100;
		var swapTimer = null;

		var rand = function ( n ) {
			return Math.floor( Math.random() * n );
		};

		var current = function ( tile ) {
			var a = tile.querySelector( 'a' );
			var img = a.querySelector( 'img' );
			return {
				url: a.getAttribute( 'href' ),
				name: tile.querySelector( '.prime-tile__name' ).textContent.trim(),
				price: tile.querySelector( '.prime-tile__price' ).innerHTML,
				img: img.currentSrc || img.src,
				tall: img.currentSrc || img.src
			};
		};

		var swapTile = function ( tile ) {
			if ( ! pool.length || tile.dataset.swapping ) {
				return;
			}

			var oldA = tile.querySelector( 'a' );
			var oldData = current( tile );
			var index = rand( pool.length );
			var next = pool[ index ];
			var tall = tile.classList.contains( 'prime-tile--tall' );
			var src = tall && next.tall ? next.tall : next.img;

			if ( ! src ) {
				return;
			}

			var newA = oldA.cloneNode( true );
			var img = newA.querySelector( 'img' );
			var nameEl = newA.querySelector( '.prime-tile__name' );

			newA.setAttribute( 'href', next.url );
			img.removeAttribute( 'srcset' );
			img.removeAttribute( 'sizes' );
			img.removeAttribute( 'width' );
			img.removeAttribute( 'height' );
			img.setAttribute( 'alt', next.name );
			img.setAttribute( 'loading', 'eager' );
			nameEl.lastChild.nodeValue = ' ' + next.name + ' ';
			newA.querySelector( '.prime-tile__price' ).innerHTML = next.price;

			var go = function () {
				tile.dataset.swapping = '1';

				var inDir = DIRS[ rand( 4 ) ];
				var outDir = Math.random() < 0.5 ? [ -inDir[ 0 ], -inDir[ 1 ] ] : DIRS[ rand( 4 ) ];
				var fill = { position: 'absolute', inset: '0', blockSize: '100%', inlineSize: '100%' };

				Object.keys( fill ).forEach( function ( k ) {
					oldA.style[ k ] = fill[ k ];
					newA.style[ k ] = fill[ k ];
				} );

				tile.appendChild( newA );

				var opts = { duration: SWAP_MS, easing: 'cubic-bezier(.22,.8,.2,1)', fill: 'both' };

				oldA.animate(
					[
						{ transform: 'translate(0,0)', opacity: 1 },
						{ transform: 'translate(' + ( outDir[ 0 ] * 100 ) + '%,' + ( outDir[ 1 ] * 100 ) + '%)', opacity: 0.4 }
					],
					opts
				);

				newA.animate(
					[
						{ transform: 'translate(' + ( inDir[ 0 ] * 100 ) + '%,' + ( inDir[ 1 ] * 100 ) + '%)', opacity: 0.4 },
						{ transform: 'translate(0,0)', opacity: 1 }
					],
					opts
				).onfinish = function () {
					oldA.remove();
					[ 'position', 'inset', 'blockSize', 'inlineSize' ].forEach( function ( k ) {
						newA.style[ k ] = '';
					} );
					newA.getAnimations().forEach( function ( a ) {
						a.cancel();
					} );
					delete tile.dataset.swapping;
					pool[ index ] = oldData;
				};
			};

			// Decode first so the new photo never slides in blank.
			img.src = src;
			if ( typeof img.decode === 'function' ) {
				img.decode().then( go, go );
			} else {
				go();
			}
		};

		var swapOnce = function () {
			var tiles = Array.prototype.filter.call( wall.children, function ( tile ) {
				return ! tile.dataset.swapping && ! tile.matches( ':hover, :focus-within' ) && tile.classList.contains( 'is-in' ) || ( ! tile.dataset.swapping && ! tile.matches( ':hover, :focus-within' ) && ! wall.classList.contains( 'has-reveal' ) );
			} );

			if ( tiles.length ) {
				swapTile( tiles[ rand( tiles.length ) ] );
			}
		};

		var startSwaps = function () {
			if ( swapTimer || ! pool.length || reduceMotion.matches || document.hidden || ! window.Element.prototype.animate ) {
				return;
			}

			swapTimer = window.setInterval( function () {
				swapOnce();
				if ( Math.random() < 0.35 ) {
					window.setTimeout( swapOnce, 500 );
				}
			}, 2200 );
		};

		var stopSwaps = function () {
			window.clearInterval( swapTimer );
			swapTimer = null;
		};

		document.addEventListener( 'visibilitychange', function () {
			if ( document.hidden ) {
				stopSwaps();
			} else {
				startSwaps();
			}
		} );

		startSwaps();

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
			if ( autoTimer || pool.length || reduceMotion.matches || document.hidden ) {
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
