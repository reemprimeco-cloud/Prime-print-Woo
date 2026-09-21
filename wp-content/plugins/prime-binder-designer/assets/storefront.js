/**
 * Binder product page: the "Your design" panel, the editor modal, and the
 * Add to Cart gate. The server re-checks every design on add-to-cart, so this
 * script only steers the customer — it is not what protects the order.
 *
 * Talks to the editor (an iframe on the same origin) with postMessage:
 *   draft         { designId }              a draft exists; remember it to reopen
 *   design-ready  { designId, proofUrl }    approved, print file rendered
 *   close                                   customer left the editor
 */
( function () {
	'use strict';

	var panel = document.querySelector( '[data-binder-panel]' );

	if ( ! panel ) {
		return;
	}

	var cfg;

	try {
		cfg = JSON.parse( panel.getAttribute( 'data-binder' ) );
	} catch ( e ) {
		return;
	}

	var SESSION_KEY = 'binder_session'; // Same key the editor uses, so both sides agree on who owns a draft.

	function sessionToken() {
		var fresh = function () {
			if ( window.crypto && window.crypto.randomUUID ) {
				return window.crypto.randomUUID();
			}
			var s = '';
			for ( var i = 0; i < 32; i++ ) {
				s += Math.floor( Math.random() * 16 ).toString( 16 );
			}
			return s;
		};

		try {
			var stored = window.localStorage.getItem( SESSION_KEY );

			if ( stored && /^[A-Za-z0-9-]{16,64}$/.test( stored ) ) {
				return stored;
			}

			var token = fresh();
			window.localStorage.setItem( SESSION_KEY, token );
			return token;
		} catch ( e ) {
			return fresh();
		}
	}

	var form = panel.closest( 'form' );
	var submit = form ? form.querySelector( '.single_add_to_cart_button' ) : null;
	var hint = panel.querySelector( '[data-binder-hint]' );
	var token = sessionToken();

	panel.querySelector( '[data-binder-session]' ).value = token;

	/* ---------------------------------------------------------------- rows */

	var rows = {};

	Array.prototype.forEach.call( panel.querySelectorAll( '[data-binder-row]' ), function ( el ) {
		var row = {
			template: el.getAttribute( 'data-binder-row' ),
			el: el,
			input: el.querySelector( '[data-binder-input]' ),
			state: el.querySelector( '[data-binder-state]' ),
			proof: el.querySelector( '[data-binder-proof]' ),
			upload: el.querySelector( '[data-binder-open="upload"]' ),
			draft: 0 // Unapproved draft id, so reopening the editor continues the customer's work.
		};

		rows[ row.template ] = row;
	} );

	function renderRow( row ) {
		var ready = !! row.input.value;

		row.state.textContent = row.state.getAttribute( ready ? 'data-ready' : 'data-none' );
		row.el.classList.toggle( 'is-ready', ready );
		row.upload.textContent = row.upload.getAttribute( ready ? 'data-label-change' : 'data-label-new' );
		row.proof.hidden = ! ( ready && row.proof.getAttribute( 'href' ) );
	}

	function allReady() {
		return Object.keys( rows ).every( function ( t ) {
			return !! rows[ t ].input.value;
		} );
	}

	function renderGate() {
		var ready = allReady();

		if ( submit ) {
			submit.disabled = ! ready;
			submit.setAttribute( 'aria-disabled', ready ? 'false' : 'true' );
			submit.classList.toggle( 'binder-gated', ! ready );
		}

		if ( hint ) {
			hint.hidden = ready;
		}
	}

	function render() {
		Object.keys( rows ).forEach( function ( t ) {
			renderRow( rows[ t ] );
		} );
		renderGate();
	}

	// Enter in a quantity field submits the form without touching the button.
	if ( form ) {
		form.addEventListener( 'submit', function ( event ) {
			if ( ! allReady() ) {
				event.preventDefault();
				panel.scrollIntoView( { behavior: 'smooth', block: 'center' } );
			}
		} );
	}

	/* --------------------------------------------------------------- modal */

	var current = null; // { row, overlay, frame, opener }

	function editorUrl( row, mode ) {
		var q = [
			[ 'rest', cfg.rest ],
			[ 'product', cfg.product ],
			[ 'template', row.template ],
			[ 'mode', mode ],
			[ 'lang', cfg.lang ]
		];

		if ( row.draft ) {
			q.push( [ 'design', row.draft ] );
		}

		return cfg.editor + '?' + q.map( function ( kv ) {
			return encodeURIComponent( kv[ 0 ] ) + '=' + encodeURIComponent( kv[ 1 ] );
		} ).join( '&' );
	}

	function openEditor( row, mode, opener ) {
		if ( current ) {
			return;
		}

		var overlay = document.createElement( 'div' );
		overlay.className = 'binder-modal';
		overlay.setAttribute( 'role', 'dialog' );
		overlay.setAttribute( 'aria-modal', 'true' );
		overlay.setAttribute( 'aria-label', row.el.querySelector( '.binder-row__label' ).textContent );

		var bar = document.createElement( 'div' );
		bar.className = 'binder-modal__bar';

		var title = document.createElement( 'span' );
		title.className = 'binder-modal__title';
		title.textContent = row.el.querySelector( '.binder-row__label' ).textContent;

		var closeBtn = document.createElement( 'button' );
		closeBtn.type = 'button';
		closeBtn.className = 'binder-modal__close';
		closeBtn.setAttribute( 'aria-label', cfg.close );
		closeBtn.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
		closeBtn.addEventListener( 'click', closeEditor );

		bar.appendChild( title );
		bar.appendChild( closeBtn );

		var frame = document.createElement( 'iframe' );
		frame.className = 'binder-modal__frame';
		frame.title = title.textContent;
		frame.src = editorUrl( row, mode );

		overlay.appendChild( bar );
		overlay.appendChild( frame );
		document.body.appendChild( overlay );
		document.documentElement.classList.add( 'binder-modal-open' );

		current = { row: row, overlay: overlay, frame: frame, opener: opener };
		closeBtn.focus();
	}

	function closeEditor() {
		if ( ! current ) {
			return;
		}

		var opener = current.opener;

		current.overlay.remove();
		document.documentElement.classList.remove( 'binder-modal-open' );
		current = null;
		render();

		if ( opener && opener.focus ) {
			opener.focus();
		}
	}

	panel.addEventListener( 'click', function ( event ) {
		var btn = event.target.closest( '[data-binder-open]' );

		if ( ! btn ) {
			return;
		}

		var rowEl = btn.closest( '[data-binder-row]' );

		openEditor( rows[ rowEl.getAttribute( 'data-binder-row' ) ], btn.getAttribute( 'data-binder-open' ), btn );
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' === event.key && current ) {
			closeEditor();
		}
	} );

	window.addEventListener( 'message', function ( event ) {
		var msg = event.data;

		if ( event.origin !== window.location.origin || ! current || event.source !== current.frame.contentWindow ) {
			return;
		}

		if ( ! msg || 'binder-editor' !== msg.source || msg.template !== current.row.template ) {
			return;
		}

		var row = current.row;

		if ( 'draft' === msg.type && msg.designId ) {
			row.draft = Number( msg.designId );
		} else if ( 'design-ready' === msg.type && msg.designId ) {
			// An approved design replaces any earlier one; the next "Change" starts a fresh draft.
			row.input.value = String( Number( msg.designId ) );
			row.draft = 0;

			if ( msg.proofUrl ) {
				row.proof.setAttribute( 'href', msg.proofUrl );
			}

			render();
		} else if ( 'close' === msg.type ) {
			closeEditor();
		}
	} );

	render();
}() );
