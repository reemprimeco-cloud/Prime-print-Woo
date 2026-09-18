/**
 * Product edit screen — the add-ons repeater and the pricing-model toggle.
 *
 * No build step, no dependency beyond the jQuery WordPress already loads for
 * every other product-data tab.
 */
( function ( $ ) {
	'use strict';

	function syncPricingModel() {
		var model = $( '#prime_pricing_model' ).val();
		$( '.prime-custom-formula' ).toggleClass( 'is-visible', 'custom' === model );
	}

	function reindexAddons() {
		$( '#prime-addons-rows .prime-addon-row' ).each( function ( index ) {
			$( this )
				.find( 'input, select' )
				.each( function () {
					var name = $( this ).attr( 'name' );
					if ( name ) {
						$( this ).attr( 'name', name.replace( /prime_addons\[[^\]]*\]/, 'prime_addons[' + index + ']' ) );
					}
				} );
		} );
	}

	$( document ).on( 'change', '#prime_pricing_model', syncPricingModel );

	$( document ).on( 'click', '[data-prime-add-addon]', function ( event ) {
		event.preventDefault();

		var template = document.getElementById( 'prime-addon-row-template' );
		if ( ! template ) {
			return;
		}

		var clone = template.content.cloneNode( true );
		$( '#prime-addons-rows' ).append( clone );
		reindexAddons();
	} );

	$( document ).on( 'click', '[data-prime-remove-addon]', function ( event ) {
		event.preventDefault();
		$( this ).closest( '.prime-addon-row' ).remove();
		reindexAddons();
	} );

	$( function () {
		syncPricingModel();
	} );
} )( jQuery );
