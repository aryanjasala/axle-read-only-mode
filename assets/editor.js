/**
 * Axle Read-Only Mode in the block editor.
 *
 * - Locks Save and Publish while the site is frozen, and unlocks them when the
 *   Heartbeat reports the freeze has ended.
 * - Counts down before a planned freeze starts.
 * - Keeps what you type during a freeze: a copy waits in this browser, and when
 *   the freeze ends it is saved, unless the post changed on the server meanwhile.
 *   Then both versions are shown and you choose. Nothing is overwritten silently.
 */
( function ( wp, $, settings ) {
	'use strict';

	var LOCK = 'axle-read-only-mode';
	var HEADS_UP = 'axle-read-only-mode-heads-up';
	var CHOICE = 'axle-read-only-mode-choice';
	var strings = settings.strings || {};
	var frozen = false;
	var announced = false;
	var upcoming = null;
	var offset = 0;
	var countdown;
	var storeTimer;
	var resolving = false;

	function select( store ) {
		return wp.data.select( store );
	}

	function dispatch( store ) {
		return wp.data.dispatch( store );
	}

	function speak( text, politeness ) {
		if ( wp.a11y && text ) {
			wp.a11y.speak( text, politeness || 'polite' );
		}
	}

	/* The copy that waits in this browser. */

	function key() {
		return 'axle-read-only-mode:post:' + select( 'core/editor' ).getCurrentPostId();
	}

	function readCopy() {
		try {
			return JSON.parse( window.localStorage.getItem( key() ) || 'null' );
		} catch ( error ) {
			return null;
		}
	}

	function writeCopy( copy ) {
		try {
			window.localStorage.setItem( key(), JSON.stringify( copy ) );
		} catch ( error ) {
			// Storage is full or blocked. The edits still live in the editor.
		}
	}

	function clearCopy() {
		try {
			window.localStorage.removeItem( key() );
		} catch ( error ) {
			// Nothing to remove.
		}
	}

	function snapshot() {
		var editor = select( 'core/editor' );
		var previous = readCopy();

		return {
			title: editor.getEditedPostAttribute( 'title' ),
			excerpt: editor.getEditedPostAttribute( 'excerpt' ),
			content: editor.getEditedPostContent(),
			// What the server had when the first change was kept, so a change by someone else shows up later.
			base: previous && previous.base ? previous.base : editor.getCurrentPost().modified_gmt,
			time: Date.now(),
		};
	}

	function keepEdits() {
		window.clearTimeout( storeTimer );
		storeTimer = window.setTimeout( function () {
			if ( frozen && select( 'core/editor' ).isEditedPostDirty() ) {
				writeCopy( snapshot() );
			}
		}, 800 );
	}

	/* Resolving: the freeze is over and a copy is waiting. */

	function notify( status, message, id, actions ) {
		dispatch( 'core/notices' ).createNotice( status, message, {
			id: id,
			isDismissible: ! actions,
			actions: actions || [],
		} );
		speak( message, 'error' === status ? 'assertive' : 'polite' );
	}

	function serverModified() {
		var type = select( 'core/editor' ).getCurrentPostType();
		var id = select( 'core/editor' ).getCurrentPostId();

		return wp
			.apiFetch( { path: '/wp/v2/types/' + type + '?context=edit&_fields=rest_base' } )
			.then( function ( data ) {
				return wp.apiFetch( { path: '/wp/v2/' + data.rest_base + '/' + id + '?context=edit&_fields=modified_gmt' } );
			} )
			.then( function ( post ) {
				return post.modified_gmt;
			} );
	}

	function save() {
		dispatch( 'core/notices' ).removeNotice( CHOICE );

		return Promise.resolve( dispatch( 'core/editor' ).savePost() ).then( function () {
			if ( select( 'core/editor' ).didPostSaveRequestSucceed() ) {
				clearCopy();
				notify( 'success', strings.saved, CHOICE );
			} else {
				notify( 'error', strings.failed, CHOICE, [ { label: strings.saveNow, onClick: save } ] );
			}
		} );
	}

	function bring( copy ) {
		var editor = select( 'core/editor' );

		if ( editor.isEditedPostDirty() ) {
			return;
		}

		dispatch( 'core/editor' ).resetEditorBlocks( wp.blocks.parse( copy.content ) );
		dispatch( 'core/editor' ).editPost( { title: copy.title, excerpt: copy.excerpt } );
	}

	function useSaved() {
		clearCopy();
		window.location.reload();
	}

	function resolve() {
		var copy = readCopy();
		var editor = select( 'core/editor' );

		if ( ! copy || resolving ) {
			return;
		}

		if ( ! editor.isEditedPostDirty() && copy.content === editor.getEditedPostContent() && copy.title === editor.getEditedPostAttribute( 'title' ) ) {
			clearCopy();
			return;
		}

		resolving = true;

		serverModified()
			.then( function ( modified ) {
				if ( modified === copy.base ) {
					bring( copy );
					return save();
				}

				bring( copy );
				var when = new Date( copy.time ).toLocaleString();
				notify(
					'warning',
					strings.conflict.replace( '%1$s', when ).replace( '%2$s', new Date( modified + 'Z' ).toLocaleString() ),
					CHOICE,
					[
						{ label: strings.keepMine, onClick: save },
						{ label: strings.useSaved, onClick: useSaved },
					]
				);
			} )
			.catch( function () {
				notify( 'error', strings.failed, CHOICE, [ { label: strings.saveNow, onClick: resolve } ] );
			} )
			.then( function () {
				resolving = false;
			} );
	}

	/* The freeze. */

	function whenLoaded( callback ) {
		var done = false;
		var unsubscribe = null;

		function check() {
			var editor = select( 'core/editor' );
			var post = editor.getCurrentPostId() ? editor.getCurrentPost() : null;

			if ( ! done && post && post.modified_gmt ) {
				done = true;
				if ( unsubscribe ) {
					unsubscribe();
				}
				callback();
			}
		}

		unsubscribe = wp.data.subscribe( check );
		check();
	}

	function apply( message ) {
		var editor = dispatch( 'core/editor' );
		var notices = dispatch( 'core/notices' );

		if ( message && ! frozen ) {
			editor.lockPostSaving( LOCK );
			editor.lockPostAutosaving( LOCK );
			notices.createWarningNotice( message + ' ' + strings.kept, { id: LOCK, isDismissible: false } );
			notices.removeNotice( HEADS_UP );
			speak( message, 'assertive' );
			keepEdits();
		} else if ( message && frozen ) {
			notices.createWarningNotice( message + ' ' + strings.kept, { id: LOCK, isDismissible: false } );
		} else if ( ! message && frozen ) {
			editor.unlockPostSaving( LOCK );
			editor.unlockPostAutosaving( LOCK );
			notices.removeNotice( LOCK );
			frozen = false;
			resolve();
		}

		frozen = !! message;
	}

	/* The countdown before a freeze starts. */

	function words( seconds ) {
		if ( seconds < 60 ) {
			return strings.under;
		}

		var minutes = Math.ceil( seconds / 60 );

		return ( 1 === minutes ? strings.one : strings.many ).replace( '%d', String( minutes ) );
	}

	function tick() {
		var notices = dispatch( 'core/notices' );

		if ( ! upcoming || frozen ) {
			window.clearInterval( countdown );
			notices.removeNotice( HEADS_UP );
			return;
		}

		var left = Math.max( 0, Math.round( ( upcoming.start * 1000 - ( Date.now() + offset ) ) / 1000 ) );
		var text = left > 0 ? ( strings[ upcoming.area ] || strings.all ).replace( '%s', words( left ) ) : strings.now;

		notices.createWarningNotice( text, { id: HEADS_UP, isDismissible: false } );

		if ( ! announced ) {
			announced = true;
			speak( text, 'assertive' );
		}

		if ( left > 0 && left < 120 && wp.heartbeat ) {
			wp.heartbeat.interval( 'fast' );
		}
	}

	function headsUp( data ) {
		upcoming = data || null;
		window.clearInterval( countdown );

		if ( ! upcoming ) {
			dispatch( 'core/notices' ).removeNotice( HEADS_UP );
			announced = false;
			return;
		}

		offset = upcoming.now * 1000 - Date.now();
		tick();
		countdown = window.setInterval( tick, 15000 );
	}

	wp.domReady( function () {
		var editor = select( 'core/editor' );
		var failed = false;

		apply( settings.message );
		headsUp( settings.upcoming );

		if ( ! settings.message ) {
			// A copy from an earlier visit waits until the post is loaded.
			whenLoaded( resolve );
		}

		$( document ).on( 'heartbeat-send', function ( event, data ) {
			data.axle_read_only_mode = 1;
		} );

		$( document ).on( 'heartbeat-tick', function ( event, data ) {
			if ( ! data ) {
				return;
			}
			if ( 'axle_read_only_mode' in data ) {
				apply( data.axle_read_only_mode );
			}
			if ( 'axle_read_only_mode_heads_up' in data ) {
				headsUp( data.axle_read_only_mode_heads_up );
			}
		} );

		wp.data.subscribe( function () {
			if ( frozen ) {
				keepEdits();
			}

			// A save the server refused because the freeze began a moment ago: ask for the news at once.
			var now = editor.didPostSaveRequestFail();
			if ( now && ! failed && ! frozen && wp.heartbeat ) {
				wp.heartbeat.connectNow();
			}
			failed = now;
		} );
	} );
} )( window.wp, window.jQuery, window.axleReadOnlyModeEditor || { message: '', strings: {} } );
