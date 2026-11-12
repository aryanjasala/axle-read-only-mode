/**
 * Axle Read-Only Mode: the countdown notice before a planned freeze starts, on
 * every admin screen except the block editor (which has its own). Kept current
 * through the Heartbeat: a plan that is moved or cancelled changes or removes it.
 */
( function ( wp, $, settings ) {
	'use strict';

	var element = document.querySelector( '[data-aro-heads-up]' );
	var upcoming = settings && settings.upcoming;

	if ( ! element || ! upcoming ) {
		return;
	}

	var notice = element.closest( '.notice' );
	var strings = settings.strings;
	var offset = upcoming.now * 1000 - Date.now();
	var announced = false;
	var timer;

	function words( seconds ) {
		if ( seconds < 60 ) {
			return strings.under;
		}

		var minutes = Math.ceil( seconds / 60 );

		return ( 1 === minutes ? strings.one : strings.many ).replace( '%d', String( minutes ) );
	}

	function render() {
		var left = Math.max( 0, Math.round( ( upcoming.start * 1000 - ( Date.now() + offset ) ) / 1000 ) );
		var text = left > 0 ? ( strings[ upcoming.area ] || strings.all ).replace( '%s', words( left ) ) : strings.now;

		element.textContent = text;

		if ( ! announced ) {
			announced = true;
			wp.a11y.speak( text, 'assertive' );
		}

		if ( left <= 0 ) {
			window.clearInterval( timer );
			wp.a11y.speak( text, 'assertive' );
		} else if ( left < 120 && wp.heartbeat ) {
			// Check often near the start, so the notice follows a cancel or a move quickly.
			wp.heartbeat.interval( 'fast' );
		}
	}

	render();
	timer = window.setInterval( render, 15000 );

	// The Heartbeat only asks the server when the page sends something.
	$( document ).on( 'heartbeat-send', function ( event, data ) {
		data.axle_read_only_mode_heads_up = 1;
	} );

	$( document ).on( 'heartbeat-tick', function ( event, data ) {
		if ( ! data || ! ( 'axle_read_only_mode_heads_up' in data ) ) {
			return;
		}

		if ( ! data.axle_read_only_mode_heads_up ) {
			if ( upcoming.start * 1000 > Date.now() + offset ) {
				// Cancelled or moved out of the window.
				window.clearInterval( timer );
				notice.style.display = 'none';
			}
			return;
		}

		upcoming = data.axle_read_only_mode_heads_up;
		offset = upcoming.now * 1000 - Date.now();
		notice.style.display = '';
		render();
	} );
} )( window.wp, window.jQuery, window.axleReadOnlyModeHeadsUp );
