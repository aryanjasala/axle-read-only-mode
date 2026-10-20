<?php
/**
 * Blocks changes made through wp-admin and admin-ajax.
 *
 * @package AryanX\DeployFreeze
 */

namespace AryanX\DeployFreeze\Guards;

use AryanX\DeployFreeze\Admin\ToolsPage;
use AryanX\DeployFreeze\Service;

defined( 'ABSPATH' ) || exit;

/**
 * In wp-admin every change is a POST or a link carrying a nonce, so those
 * are blocked while reading screens and opening the editor still work.
 * Admin-ajax is blocked unless the action is known to be read-only.
 */
final class AdminGuard implements Service {

	/**
	 * Admin-ajax actions that only read data, or only save personal screen
	 * preferences, and keep working during a freeze.
	 */
	public const READ_ONLY_AJAX = array(
		'heartbeat',
		'query-attachments',
		'get-attachment',
		'wp-link-ajax',
		'ajax-tag-search',
		'menu-quick-search',
		'menu-get-metabox',
		'fetch-list',
		'get-revision-diffs',
		'get-community-events',
		'dashboard-widgets',
		'closed-postboxes',
		'hidden-columns',
		'meta-box-order',
		'logged-in',
		'parse-embed',
		'parse-media-shortcode',
		'query-themes',
		'wp-compression-test',
		'health-check-site-status-result',
		'health-check-get-sizes',
	);

	/**
	 * Admin screens that change something just by opening them.
	 */
	private const WRITING_SCREENS = array( 'post-new.php', 'upgrade.php' );

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
	 * Runs before any admin screen or ajax handler.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'guard' ), 0 );
	}

	/**
	 * Blocks the request if it would change something.
	 */
	public function guard(): void {
		if ( $this->blocker->state()->can_bypass() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification -- Nothing is processed; the request is only inspected to decide whether to block it.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

		if ( wp_doing_ajax() ) {
			$this->guard_ajax( $action );
			return;
		}

		global $pagenow;

		// The freeze's own controls must keep working, or nobody could unfreeze.
		if ( 'admin-post.php' === $pagenow && ToolsPage::ACTION === $action ) {
			return;
		}

		if ( 'POST' === Blocker::method() || isset( $_GET['_wpnonce'] ) || isset( $_GET['_ajax_nonce'] ) || in_array( $pagenow, self::WRITING_SCREENS, true ) ) {
			$this->blocker->stop( Blocker::method() . ' ' . Blocker::uri(), 'admin' );
		}
		// phpcs:enable WordPress.Security.NonceVerification
	}

	/**
	 * Allows read-only ajax actions and blocks the rest.
	 *
	 * @param string $action Ajax action.
	 */
	private function guard_ajax( string $action ): void {
		if ( ! is_user_logged_in() && ! $this->blocker->state()->get( 'visitors' ) ) {
			return;
		}

		/**
		 * Filters the admin-ajax actions that keep working during a freeze.
		 *
		 * @param string[] $actions Read-only ajax actions.
		 */
		$allowed = (array) apply_filters( 'deploy_freeze_allowed_ajax_actions', self::READ_ONLY_AJAX );

		if ( in_array( $action, $allowed, true ) ) {
			if ( 'heartbeat' === $action ) {
				// Heartbeat stays on so editors learn about the freeze, but must not autosave.
				remove_filter( 'heartbeat_received', 'heartbeat_autosave', 500 );
			}
			return;
		}

		$this->blocker->stop( 'admin-ajax ' . $action, 'ajax' );
	}
}
