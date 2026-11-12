/**
 * Axle Read-Only Mode screen: the live "Ends in 1:23:45" countdown, which
 * reloads the page when the freeze ends so the screen shows the new state,
 * and the Copy buttons next to commands.
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

	document.querySelectorAll( '[data-aro-end]' ).forEach( function ( element ) {
		var end = Number( element.getAttribute( 'data-aro-end' ) ) * 1000;
		var label = element.getAttribute( 'data-aro-label' );
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

	document.querySelectorAll( '[data-aro-copy]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			if ( ! navigator.clipboard || ! navigator.clipboard.writeText ) {
				return;
			}

			navigator.clipboard.writeText( button.getAttribute( 'data-aro-copy' ) ).then( function () {
				if ( window.wp && window.wp.a11y ) {
					window.wp.a11y.speak( button.getAttribute( 'data-aro-copied' ) );
				}
			} );
		} );
	} );

	/**
	 * The "Before you freeze" card: refreshes when the length, start, scope or
	 * pauses change, and asks first when other people have a post open.
	 */
	var card = document.querySelector( '.aro-before' );
	var form = document.querySelector( '.aro-form' );
	var settings = window.axleReadOnlyModeCheck;

	if ( card && form && settings ) {
		var body = card.querySelector( '.aro-before__body' );
		var spinner = card.querySelector( '.aro-before__spinner' );
		var timer;
		var request = 0;

		var field = function ( name ) {
			return form.elements[ name ];
		};

		var query = function () {
			var params = new URLSearchParams();
			var when = form.querySelector( 'input[name="when"]:checked' );
			var scope = form.querySelector( 'input[name="freeze_scope"]:checked' );

			params.set( 'action', settings.action );
			params.set( '_ajax_nonce', settings.nonce );
			params.set( 'minutes', field( 'minutes' ).value );
			params.set( 'start', when && 'later' === when.value ? field( 'start' ).value : '' );
			params.set( 'freeze_scope', scope ? scope.value : 'all' );
			if ( field( 'cron' ).checked ) {
				params.set( 'cron', '1' );
			}
			if ( field( 'visitors' ).checked ) {
				params.set( 'visitors', '1' );
			}

			return params.toString();
		};

		var refresh = function () {
			var current = ++request;

			card.setAttribute( 'aria-busy', 'true' );
			spinner.classList.add( 'is-active' );

			window
				.fetch( settings.url + '?' + query(), { credentials: 'same-origin' } )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( json ) {
					if ( current !== request ) {
						return;
					}
					if ( ! json || ! json.success ) {
						throw new Error( 'failed' );
					}

					body.innerHTML = json.data.html;
					card.setAttribute( 'data-aro-confirm', json.data.confirm );
					if ( window.wp && window.wp.a11y ) {
						window.wp.a11y.speak( json.data.summary );
					}
				} )
				.catch( function () {
					if ( current === request ) {
						body.textContent = settings.failed;
					}
				} )
				.then( function () {
					if ( current === request ) {
						card.removeAttribute( 'aria-busy' );
						spinner.classList.remove( 'is-active' );
					}
				} );
		};

		form.addEventListener( 'change', function () {
			window.clearTimeout( timer );
			timer = window.setTimeout( refresh, 250 );
		} );

		var confirmBox = form.querySelector( '#aro-freeze-confirm' );
		var confirmed = false;

		if ( confirmBox ) {
			var yes = confirmBox.querySelector( '[data-aro-confirm-yes]' );
			var no = confirmBox.querySelector( '[data-aro-confirm-no]' );
			var text = confirmBox.querySelector( 'p' );
			var submit = form.querySelector( '#submit' );

			// Asks in place, not in a browser dialog, before freezing while other people are editing.
			form.addEventListener( 'submit', function ( event ) {
				var message = card.getAttribute( 'data-aro-confirm' );

				if ( confirmed || ! message ) {
					return;
				}

				event.preventDefault();
				text.textContent = message;
				confirmBox.hidden = false;
				yes.focus();
				if ( window.wp && window.wp.a11y ) {
					window.wp.a11y.speak( message, 'assertive' );
				}
			} );

			yes.addEventListener( 'click', function () {
				confirmed = true;
				form.requestSubmit ? form.requestSubmit() : form.submit();
			} );

			no.addEventListener( 'click', function () {
				confirmBox.hidden = true;
				if ( submit ) {
					submit.focus();
				}
			} );
		}
	}
} )();
