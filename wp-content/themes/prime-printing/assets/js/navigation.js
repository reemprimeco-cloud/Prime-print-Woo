/**
 * Prime Printing — header behaviour.
 *
 * Two things happen here and nothing else:
 *   1. The full-screen menu opens, closes, traps focus, and restores it.
 *   2. The transparent-over-hero header solidifies once past the hero edge.
 *
 * Page-level behaviour (shuffle, density toggle, configurator) lives with the
 * page that owns it, not in this file.
 */
(function () {
	'use strict';

	var SCROLL_THRESHOLD = 60;

	/* ---- Full-screen menu ------------------------------------------------ */

	var menu = document.getElementById( 'prime-menu' );
	var openers = document.querySelectorAll( '[data-prime-menu-open]' );
	var closers = document.querySelectorAll( '[data-prime-menu-close]' );
	var lastFocused = null;

	function focusableIn( root ) {
		return Array.prototype.filter.call(
			root.querySelectorAll( 'a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])' ),
			function ( el ) {
				return el.offsetParent !== null || el === document.activeElement;
			}
		);
	}

	function setMenu( open ) {
		if ( ! menu ) {
			return;
		}

		menu.classList.toggle( 'is-open', open );
		document.body.classList.toggle( 'prime-lock', open );

		// `inert` keeps the closed overlay out of the tab order and the
		// accessibility tree, which visibility:hidden alone does not guarantee
		// during the transition.
		if ( open ) {
			menu.removeAttribute( 'inert' );
		} else {
			menu.setAttribute( 'inert', '' );
		}

		Array.prototype.forEach.call( openers, function ( opener ) {
			opener.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		} );

		if ( open ) {
			lastFocused = document.activeElement;
			var first = focusableIn( menu )[ 0 ];
			if ( first ) {
				first.focus();
			}
			return;
		}

		if ( lastFocused && typeof lastFocused.focus === 'function' ) {
			lastFocused.focus();
			lastFocused = null;
		}
	}

	Array.prototype.forEach.call( openers, function ( opener ) {
		opener.addEventListener( 'click', function () {
			setMenu( true );
		} );
	} );

	Array.prototype.forEach.call( closers, function ( closer ) {
		closer.addEventListener( 'click', function () {
			setMenu( false );
		} );
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( ! menu || ! menu.classList.contains( 'is-open' ) ) {
			return;
		}

		if ( 'Escape' === event.key ) {
			setMenu( false );
			return;
		}

		if ( 'Tab' !== event.key ) {
			return;
		}

		var items = focusableIn( menu );

		if ( ! items.length ) {
			return;
		}

		var first = items[ 0 ];
		var last = items[ items.length - 1 ];

		if ( event.shiftKey && document.activeElement === first ) {
			event.preventDefault();
			last.focus();
			return;
		}

		if ( ! event.shiftKey && document.activeElement === last ) {
			event.preventDefault();
			first.focus();
		}
	} );

	// A viewport wide enough for the desktop nav has no burger to return focus
	// to, so close the overlay rather than stranding it open.
	var wide = window.matchMedia( '(min-width: 1001px)' );

	function handleWide( event ) {
		if ( event.matches && menu && menu.classList.contains( 'is-open' ) ) {
			setMenu( false );
		}
	}

	if ( typeof wide.addEventListener === 'function' ) {
		wide.addEventListener( 'change', handleWide );
	} else if ( typeof wide.addListener === 'function' ) {
		wide.addListener( handleWide );
	}

	/* ---- Header over the hero -------------------------------------------- */

	var nav = document.getElementById( 'prime-nav' );

	if ( nav && document.body.classList.contains( 'prime-has-hero' ) ) {
		var ticking = false;

		var syncNav = function () {
			nav.classList.toggle( 'is-solid', window.scrollY > SCROLL_THRESHOLD );
			ticking = false;
		};

		window.addEventListener(
			'scroll',
			function () {
				if ( ticking ) {
					return;
				}

				ticking = true;
				window.requestAnimationFrame( syncNav );
			},
			{ passive: true }
		);

		syncNav();
	}
})();
