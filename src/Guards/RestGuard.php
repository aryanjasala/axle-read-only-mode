<?php
/**
 * Rejects REST API writes.
 *
 * @package AryanX\DeployFreeze
 */

namespace AryanX\DeployFreeze\Guards;

use AryanX\DeployFreeze\Abilities;
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

		/**
		 * Filters the REST routes that only read, even when called with POST.
		 *
		 * @param string[] $routes Route prefixes. Default /wp/v2/block-renderer/.
		 */
		foreach ( (array) apply_filters( 'deploy_freeze_read_only_routes', self::READ_ONLY_ROUTES ) as $route ) {
			if ( 0 === strpos( $request->get_route(), $route ) ) {
				return $result;
			}
		}

		if ( $this->only_saves_preferences( $request ) ) {
			return $result;
		}

		if ( $this->is_ability_route( $request->get_route() ) ) {
			return $result;
		}

		/**
		 * Filters whether a REST request gets through a freeze anyway.
		 *
		 * @param bool             $allow   Whether to let the request through. Default false.
		 * @param \WP_REST_Request $request The request.
		 */
		if ( apply_filters( 'deploy_freeze_allow_rest_request', false, $request ) ) {
			return $result;
		}

		$this->blocker->record( 'REST ' . $request->get_method() . ' ' . $request->get_route(), 'rest' );

		return new \WP_Error( 'deploy_freeze', is_user_logged_in() ? $state->message() : $state->visitor_message(), array( 'status' => 423 ) );
	}

	/**
	 * Whether a request runs abilities, which are checked one by one instead.
	 *
	 * Deploy Freeze's own abilities always get through, or an agent could
	 * freeze the site and never unfreeze it. On WordPress 7.1 and later every
	 * ability run gets through here, because Abilities refuses each one that
	 * writes, wherever it is called from. Older versions have no way to tell,
	 * so other abilities stay blocked.
	 *
	 * @param string $route REST route.
	 * @return bool
	 */
	private function is_ability_route( string $route ): bool {
		if ( 0 === strpos( $route, '/wp-abilities/v1/abilities/' . Abilities::CATEGORY . '/' ) ) {
			return true;
		}

		if ( ! class_exists( 'WP_Filter_Sentinel' ) ) {
			return false;
		}

		/**
		 * Filters the REST routes that run abilities, which a freeze checks per ability.
		 *
		 * @param string[] $routes Route prefixes. Default the Abilities API and the MCP adapter.
		 */
		foreach ( (array) apply_filters( 'deploy_freeze_ability_routes', array( '/wp-abilities/v1/', '/mcp/' ) ) as $prefix ) {
			if ( 0 === strpos( $route, (string) $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the request only saves the user's editor preferences.
	 *
	 * The block editor stores its UI preferences (panels, welcome guide, view
	 * options) in the persisted_preferences user meta through /wp/v2/users/me.
	 * That is personal screen state, like closed meta boxes, so it is allowed.
	 * Any other field on that endpoint, such as the email or password, is not.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	private function only_saves_preferences( $request ): bool {
		if ( '/wp/v2/users/me' !== $request->get_route() ) {
			return false;
		}

		$params = $request->get_json_params();
		if ( ! $params ) {
			$params = $request->get_body_params();
		}

		return array( 'meta' ) === array_keys( $params )
			&& is_array( $params['meta'] )
			&& array( 'persisted_preferences' ) === array_keys( $params['meta'] );
	}
}
