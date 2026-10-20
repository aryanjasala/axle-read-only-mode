/**
 * Deploy Freeze: live "Ends in 1:23:45" countdown on the freeze screen.
 * Reloads the page when the freeze ends so the screen shows the new state.
 */
( function () {
	'use strict';

	function format( seconds ) {
		var hours = Math.floor( seconds / 3600 );
		var minutes = Math.floor( ( seconds % 3600 ) / 60 );
		var rest = seconds % 60;
		var pad = function ( value ) {
			return String( value ).padStart( 2, '0' );
		};

		return ( hours ? hours + ':' + pad( minutes ) : String( minutes ) ) + ':' + pad( rest );
	}

	document.querySelectorAll( '[data-df-end]' ).forEach( function ( element ) {
		var end = Number( element.getAttribute( 'data-df-end' ) ) * 1000;
		var label = element.getAttribute( 'data-df-label' );
		var timer;

		function tick() {
			var left = Math.max( 0, Math.round( ( end - Date.now() ) / 1000 ) );
			element.textContent = label.replace( '%s', format( left ) );

			if ( left <= 0 ) {
				window.clearInterval( timer );
				window.setTimeout( function () {
					window.location.reload();
				}, 1500 );
			}
		}

		tick();
		timer = window.setInterval( tick, 1000 );
	} );
} )();
