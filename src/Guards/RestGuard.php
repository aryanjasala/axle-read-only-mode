<?php
/**
 * Rejects REST API writes.
 *
 * @package AryanX\DeployFreeze
 */

namespace AryanX\DeployFreeze\Guards;

use AryanX\DeployFreeze\Service;

defined( 'ABSPATH' ) || exit;

/**
 * Answers 423 Locked to every REST request that changes data.
 */
final class RestGuard implements Service {

	/**
	 * REST routes that use POST without saving anything.
	 */
	private const READ_ONLY_ROUTES = array( '/wp/v2/block-renderer/' );

	/**
	 * Stops requests.
	 *
	 * @var Blocker
	 */
	private Blocker $blocker;

	/**
	 * Sets up the guard.
	 *
	 * @param Blocker $blocker Stops requests.
	 */
	public function __construct( Blocker $blocker ) {
		$this->blocker = $blocker;
	}

	/**
	 * Runs before any REST handler.
	 */
	public function register(): void {
		add_filter( 'rest_pre_dispatch', array( $this, 'guard' ), 0, 3 );
	}

	/**
	 * Replaces write requests with an error.
	 *
	 * @param mixed            $result  Response to use instead, or null.
	 * @param \WP_REST_Server  $server  REST server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function guard( $result, $server, $request ) {
		$state = $this->blocker->state();

		if ( null !== $result || $state->can_bypass() || in_array( $request->get_method(), array( 'GET', 'HEAD', 'OPTIONS' ), true ) ) {
			return $result;
		}

		if ( ! is_user_logged_in() && ! $state->get( 'visitors' ) ) {
			return $result;
		}

		foreach ( self::READ_ONLY_ROUTES as $route ) {
			if ( 0 === strpos( $request->get_route(), $route ) ) {
				return $result;
			}
		}

		$this->blocker->record( 'REST ' . $request->get_method() . ' ' . $request->get_route(), 'rest' );

		return new \WP_Error( 'deploy_freeze', is_user_logged_in() ? $state->message() : $state->visitor_message(), array( 'status' => 423 ) );
	}
}
