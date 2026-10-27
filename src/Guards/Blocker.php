<?php
/**
 * Stops a request and records it.
 *
 * @package AryanX\Axle\ReadOnlyMode
 */

namespace AryanX\Axle\ReadOnlyMode\Guards;

use AryanX\Axle\ReadOnlyMode\BlockedLog;
use AryanX\Axle\ReadOnlyMode\State;

defined( 'ABSPATH' ) || exit;

/**
 * Shared by every guard: logs the attempt and ends the request with the
 * right message and status code.
 */
final class Blocker {

	/**
	 * Saved freeze.
	 *
	 * @var State
	 */
	private State $state;

	/**
	 * Blocked attempts.
	 *
	 * @var BlockedLog
	 */
	private BlockedLog $log;

	/**
	 * Sets up the blocker.
	 *
	 * @param State      $state Saved freeze.
	 * @param BlockedLog $log   Blocked attempts.
	 */
	public function __construct( State $state, BlockedLog $log ) {
		$this->state = $state;
		$this->log   = $log;
	}

	/**
	 * Saved freeze.
	 *
	 * @return State
	 */
	public function state(): State {
		return $this->state;
	}

	/**
	 * Records an attempt without stopping anything. For REST, which returns its own error.
	 *
	 * @param string $where  What was attempted.
	 * @param string $source admin, ajax, rest or front-end.
	 */
	public function record( string $where, string $source ): void {
		/**
		 * Fires for every request a freeze blocks, including ones the log leaves out.
		 *
		 * For sending blocked attempts to an outside log or an alert channel.
		 *
		 * @param string $where   What was blocked, e.g. "REST POST /wp/v2/posts/12".
		 * @param string $source  admin, ajax, rest or front-end.
		 * @param int    $user_id User, or 0 for a visitor.
		 */
		do_action( 'axle_read_only_mode_blocked', $where, $source, get_current_user_id() );

		$this->log->add( $where, $source );
	}

	/**
	 * Records the attempt and ends the request.
	 *
	 * Visitors get the friendly visitor message and a 503 with Retry-After,
	 * which search engines treat as temporary. Logged-in users get the
	 * freeze message with 423 Locked.
	 *
	 * @param string $where  What was attempted.
	 * @param string $source admin, ajax, rest or front-end.
	 */
	public function stop( string $where, string $source ): void {
		/**
		 * Filters whether a request gets through a freeze anyway.
		 *
		 * For callbacks that must not wait, such as single sign-on. Payment
		 * providers retry webhooks that get a 503, so those can usually wait.
		 *
		 * @param bool   $allow  Whether to let the request through. Default false.
		 * @param string $where  What would be blocked, e.g. "POST /checkout/".
		 * @param string $source admin, ajax or front-end.
		 */
		if ( apply_filters( 'axle_read_only_mode_allow_request', false, $where, $source ) ) {
			return;
		}

		$this->record( $where, $source );

		$visitor   = ! is_user_logged_in();
		$remaining = $this->state->remaining();
		if ( $remaining && ! headers_sent() ) {
			header( 'Retry-After: ' . max( 60, $remaining ) );
		}

		wp_die(
			esc_html( $visitor ? $this->state->visitor_message() : $this->state->message() ),
			esc_html( $visitor ? __( 'Back soon', 'axle-read-only-mode' ) : __( 'Site frozen', 'axle-read-only-mode' ) ),
			array(
				'response'  => $visitor ? 503 : 423,
				'back_link' => true,
			)
		);
	}

	/**
	 * Request method, upper case.
	 *
	 * @return string
	 */
	public static function method(): string {
		return isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	}

	/**
	 * Requested path and query, for the log.
	 *
	 * @return string
	 */
	public static function uri(): string {
		return isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	}
}
