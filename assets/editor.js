/**
 * Axle Read-Only Mode: locks Save and Publish in the block editor while the site is
 * frozen, and unlocks them when the heartbeat reports the freeze has ended.
 */
( function ( wp, $, settings ) {
	'use strict';

	var LOCK = 'axle-read-only-mode';
	var frozen = false;

	function apply( message ) {
		var editor = wp.data.dispatch( 'core/editor' );
		var notices = wp.data.dispatch( 'core/notices' );

		if ( message && ! frozen ) {
			editor.lockPostSaving( LOCK );
			editor.lockPostAutosaving( LOCK );
			notices.createWarningNotice( message, { id: LOCK, isDismissible: false } );
		} else if ( ! message && frozen ) {
			editor.unlockPostSaving( LOCK );
			editor.unlockPostAutosaving( LOCK );
			notices.removeNotice( LOCK );
		}

		frozen = !! message;
	}

	wp.domReady( function () {
		apply( settings.message );

		$( document ).on( 'heartbeat-tick', function ( event, data ) {
			if ( data && 'axle_read_only_mode' in data ) {
				apply( data.axle_read_only_mode );
			}
		} );
	} );
} )( window.wp, window.jQuery, window.axleReadOnlyModeEditor || { message: '' } );
