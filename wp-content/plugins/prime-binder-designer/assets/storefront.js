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
	var hintDefault = hint ? hint.textContent : '';
	var token = sessionToken();

	/* -------------------------------------------------------------- sticker */

	// A sticker's template is its size and shape, which live in the product's
	// calculator fields (cfg.sticker.fields names them, values in cm). They are
	// read when the editor opens, and a design is dropped if they change after.
	var sticker = cfg.sticker || null;

	function stickerFields() {
		if ( ! sticker ) {
			return null;
		}

		var scope = form || document;

		for ( var i = 0; i < sticker.fields.length; i++ ) {
			var f = sticker.fields[ i ];
			var w = scope.querySelector( '[name="' + f.w + '"]' );

			if ( w ) {
				return {
					w: w,
					h: scope.querySelector( '[name="' + f.h + '"]' ),
					shape: f.shape ? scope.querySelector( '[name="' + f.shape + '"]' ) : null,
					unit: f.unit
				};
			}
		}

		return null;
	}

	function stickerParams() {
		var f = stickerFields();

		if ( ! f ) {
			return null;
		}

		var k = 'cm' === f.unit ? 10 : 1;
		var w = Math.round( parseFloat( f.w.value ) * k * 10 ) / 10;
		var h = f.h ? Math.round( parseFloat( f.h.value ) * k * 10 ) / 10 : 0;

		if ( ! ( w >= sticker.min_mm && h >= sticker.min_mm && w <= sticker.max_mm && h <= sticker.max_mm ) ) {
			return null;
		}

		return { w: w, h: h, shape: f.shape && f.shape.value ? f.shape.value : 'rectangle' };
	}

	/**
	 * What the designer's "Your order" box shows: chips (shape, size, quantity,
	 * sheets), the total and a per-sheet note, read from the page as it stands
	 * when the editor opens. Display only — the price is computed server-side.
	 */
	function orderSummary( row ) {
		var scope = form || document;
		var text = function ( sel ) {
			var el = sel ? document.querySelector( sel ) : null;
			var t = el ? el.textContent.replace( /\s+/g, ' ' ).trim() : '';
			return t && t !== '—' ? t : '';
		};
		var chips = [];
		var total = '';
		var note = '';

		if ( 'sticker' === row.template ) {
			var f = stickerFields();
			var set = f && sticker.fields.filter( function ( s ) { return s.w === f.w.name; } )[ 0 ];
			if ( f && f.shape && f.shape.selectedOptions && f.shape.selectedOptions[ 0 ] ) {
				chips.push( f.shape.selectedOptions[ 0 ].textContent.trim() );
			}
			if ( f && f.w.value && f.h && f.h.value ) {
				chips.push( f.w.value + ' × ' + f.h.value + ' cm' );
			}
			if ( set ) {
				var qty = scope.querySelector( '[name="' + set.qty + '"]' );
				if ( qty && qty.value ) {
					chips.push( qty.value + ' ' + cfg.labels.pcs );
				}
				var sheets = text( set.sheets );
				if ( sheets ) {
					chips.push( sheets + ' ' + cfg.labels.sheets );
				}
				total = text( set.total );
				var per = text( set.per_sheet );
				if ( per ) {
					note = per + ' ' + cfg.labels.per;
				}
			}
		} else {
			chips.push( row.el.querySelector( '.binder-row__label' ).textContent.trim() );
			var chosen = binding && ( form || document ).querySelector( '[name="' + binding.field + '"]:checked' );
			if ( chosen ) {
				chips.push( chosen.parentNode.textContent.replace( /\s+/g, ' ' ).trim() );
			}
			var q = scope.querySelector( 'input[name="quantity"]' );
			if ( q && q.value ) {
				chips.push( q.value + ' ' + cfg.labels.pcs );
			}
			total = text( '.prime-product__summary .price, .summary .price' );
		}

		return { title: cfg.title, chips: chips, total: total, note: note };
	}

	// The calculators' own "attach your artwork" field is replaced by the designer.
	Array.prototype.forEach.call( ( form || document ).querySelectorAll( 'input[type="file"][name$="_artwork"]' ), function ( input ) {
		var field = input.closest( '.prime-field' );
		if ( field ) {
			field.hidden = true;
		}
	} );

	function stickerKey( p ) {
		return p ? p.w + 'x' + p.h + ':' + p.shape : '';
	}

	/* -------------------------------------------------------------- binding */

	// A binder is English (opens from the left, front cover on the right of the
	// sheet) or Arabic (opens from the right, front cover on the left). The
	// customer must choose before designing; the choice travels to the editor
	// and a design made for the other side is dropped if it changes.
	var binding = cfg.binding || null;

	function chosenBinding() {
		if ( ! binding ) {
			return '';
		}
		var checked = ( form || document ).querySelector( '[name="' + binding.field + '"]:checked' );
		return checked ? checked.value : '';
	}

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
			draft: 0, // Unapproved draft id, so reopening the editor continues the customer's work.
			params: null, // Sticker: the size and shape the open/approved design is for.
			sizeKey: '',
			binding: '' // Binder: the language the open/approved design is for.
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
		var openable = ! binding || !! chosenBinding();

		// Nothing can be designed until the binder language is chosen.
		Array.prototype.forEach.call( panel.querySelectorAll( '[data-binder-open]' ), function ( btn ) {
			btn.disabled = ! openable;
		} );

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

		if ( 'sticker' === row.template && row.params ) {
			q.push( [ 'w', row.params.w ], [ 'h', row.params.h ], [ 'shape', row.params.shape ] );
		}

		if ( 'sticker' !== row.template && row.binding ) {
			q.push( [ 'binding', row.binding ] );
		}

		try {
			q.push( [ 'order', JSON.stringify( orderSummary( row ) ) ] );
		} catch ( e ) {}

		return cfg.editor + '?' + q.map( function ( kv ) {
			return encodeURIComponent( kv[ 0 ] ) + '=' + encodeURIComponent( kv[ 1 ] );
		} ).join( '&' );
	}

	function openEditor( row, mode, opener ) {
		if ( current ) {
			return;
		}

		if ( 'sticker' !== row.template && binding ) {
			var chosen = chosenBinding();

			if ( ! chosen ) {
				if ( hint ) {
					hint.textContent = binding.first;
					hint.hidden = false;
				}
				var first = ( form || document ).querySelector( '[name="' + binding.field + '"]' );
				if ( first && first.focus ) {
					first.focus();
				}
				return;
			}

			if ( row.binding && row.binding !== chosen ) {
				row.draft = 0; // a draft for the other side cannot continue
			}
			row.binding = chosen;
			if ( hint ) {
				hint.textContent = hintDefault;
			}
		}

		if ( 'sticker' === row.template ) {
			var params = stickerParams();

			if ( ! params ) {
				if ( hint ) {
					hint.textContent = sticker.size_first;
					hint.hidden = false;
				}
				var f = stickerFields();
				if ( f && f.w.focus ) {
					f.w.focus();
				}
				return;
			}

			// A draft made for another size cannot continue; start fresh.
			if ( row.sizeKey && row.sizeKey !== stickerKey( params ) ) {
				row.draft = 0;
			}
			row.params = params;
			row.sizeKey = stickerKey( params );
			if ( hint ) {
				hint.textContent = hintDefault;
			}
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

	// The size or shape changed after a design was made for it: that design no longer fits.
	if ( sticker ) {
		var onSizeChange = function ( event ) {
			var name = event.target && event.target.name;

			if ( ! name ) {
				return;
			}

			var named = sticker.fields.some( function ( f ) {
				return name === f.w || name === f.h || name === f.shape;
			} );

			if ( ! named ) {
				return;
			}

			var key = stickerKey( stickerParams() );

			Object.keys( rows ).forEach( function ( t ) {
				var row = rows[ t ];

				if ( 'sticker' === row.template && row.sizeKey && row.sizeKey !== key && ( row.input.value || row.draft ) ) {
					row.input.value = '';
					row.draft = 0;
					row.proof.removeAttribute( 'href' );

					if ( hint ) {
						hint.textContent = sticker.size_changed;
					}
				}
			} );

			render();
		};

		( form || document ).addEventListener( 'input', onSizeChange );
		( form || document ).addEventListener( 'change', onSizeChange );
	}

	// The binder language changed after a design was made: that design is for the other side.
	if ( binding ) {
		( form || document ).addEventListener( 'change', function ( event ) {
			if ( ! event.target || event.target.name !== binding.field ) {
				return;
			}

			var chosen = chosenBinding();

			Object.keys( rows ).forEach( function ( t ) {
				var row = rows[ t ];

				if ( 'sticker' !== row.template && row.binding && row.binding !== chosen && ( row.input.value || row.draft ) ) {
					row.input.value = '';
					row.draft = 0;
					row.proof.removeAttribute( 'href' );

					if ( hint ) {
						hint.textContent = binding.changed;
					}
				}
			} );

			render();
		} );
	}

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
