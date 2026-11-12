/**
 * Axle Read-Only Mode: the classic post editor during a freeze. A refused save
 * would drop the form, so the form stays where it is and says why. The Heartbeat
 * lifts the hold when the freeze ends.
 */
( function ( wp, $, settings ) {
	'use strict';

	var form = document.getElementById( 'post' );
	var frozen = !! settings.message;
	var box;

	if ( ! form ) {
		return;
	}

	function show( text, tone ) {
		if ( ! box ) {
			box = document.createElement( 'div' );
			box.setAttribute( 'role', 'status' );
			var heading = document.querySelector( '.wrap h1, .wrap h2' );
			if ( heading && heading.parentNode ) {
				heading.parentNode.insertBefore( box, heading.nextSibling );
			} else {
				form.parentNode.insertBefore( box, form );
			}
		}

		box.className = 'notice inline notice-' + tone;
		box.innerHTML = '';
		var paragraph = document.createElement( 'p' );
		paragraph.textContent = text;
		box.appendChild( paragraph );
	}

	if ( frozen ) {
		show( settings.message + ' ' + settings.strings.classicKept, 'warning' );
	}

	form.addEventListener( 'submit', function ( event ) {
		if ( ! frozen ) {
			return;
		}

		event.preventDefault();
		event.stopImmediatePropagation();
		show( settings.message + ' ' + settings.strings.classicKept, 'warning' );
		wp.a11y.speak( settings.strings.classicRefused, 'assertive' );

		// Publish and Update buttons stay clickable but the form is kept as it is.
		$( '#publishing-action .spinner, #major-publishing-actions .spinner' ).removeClass( 'is-active' );
		$( '#publish, #save-post' ).removeClass( 'disabled' ).prop( 'disabled', false );
	}, true );

	$( document ).on( 'heartbeat-send', function ( event, data ) {
		data.axle_read_only_mode = 1;
	} );

	$( document ).on( 'heartbeat-tick', function ( event, data ) {
		if ( ! data || ! ( 'axle_read_only_mode' in data ) ) {
			return;
		}

		var now = !! data.axle_read_only_mode;
		if ( now === frozen ) {
			return;
		}

		frozen = now;
		if ( frozen ) {
			settings.message = data.axle_read_only_mode;
			show( settings.message + ' ' + settings.strings.classicKept, 'warning' );
		} else {
			show( settings.strings.classicOver, 'success' );
			wp.a11y.speak( settings.strings.classicOver );
		}
	} );
} )( window.wp, window.jQuery, window.axleReadOnlyModeEditor || { message: '', strings: {} } );
