<?php
/**
 * Rejects front-end form posts.
 *
 * @package AryanX\SiteReadOnlyMode
 */

namespace AryanX\SiteReadOnlyMode\Guards;

use AryanX\SiteReadOnlyMode\Service;

defined( 'ABSPATH' ) || exit;

/**
 * Stops comments, checkouts, contact forms and every other front-end POST.
 *
 * Logins stay open so someone can sign in and unfreeze. REST requests are
 * left to RestGuard.
 */
final class FrontEndGuard implements Service {

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
	 * Runs as early as possible on front-end requests.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'guard' ), 0 );
	}

	/**
	 * Blocks the request if it posts something.
	 */
	public function guard(): void {
		$state = $this->blocker->state();

		if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || $state->can_bypass() ) {
			return;
		}

		if ( 'POST' !== Blocker::method() || 'wp-login.php' === $GLOBALS['pagenow'] || $this->is_rest_request() ) {
			return;
		}

		if ( is_user_logged_in() || $state->get( 'visitors' ) ) {
			$this->blocker->stop( 'POST ' . Blocker::uri(), 'front-end' );
		}
	}

	/**
	 * Whether the request targets the REST API, checked before WordPress parses it.
	 *
	 * @return bool
	 */
	private function is_rest_request(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only inspected.
		return isset( $_GET['rest_route'] ) || false !== strpos( Blocker::uri(), '/' . rest_get_url_prefix() . '/' );
	}
}
