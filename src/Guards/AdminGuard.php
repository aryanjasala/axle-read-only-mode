<?php
/**
 * Blocks changes made through wp-admin and admin-ajax.
 *
 * @package AryanX\Axle\ReadOnlyMode
 */

namespace AryanX\Axle\ReadOnlyMode\Guards;

use AryanX\Axle\ReadOnlyMode\Admin\NetworkPage;
use AryanX\Axle\ReadOnlyMode\Admin\ToolsPage;
use AryanX\Axle\ReadOnlyMode\Scopes;
use AryanX\Axle\ReadOnlyMode\Service;

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
		'wp-remove-post-lock',
		'rest-nonce',
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
		'axle_read_only_mode_check',
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

		// Visitors posting forms through admin-post.php follow the "visitor submissions" setting.
		if ( ! is_user_logged_in() && ! $this->blocker->state()->get( 'visitors' ) ) {
			return;
		}

		// The freeze's own controls must keep working, or nobody could unfreeze.
		if ( 'admin-post.php' === $pagenow && ToolsPage::ACTION === $action ) {
			return;
		}
		if ( 'edit.php' === $pagenow && is_network_admin() && NetworkPage::ACTION === $action ) {
			return;
		}

		if ( 'POST' === Blocker::method() || isset( $_GET['_wpnonce'] ) || isset( $_GET['_ajax_nonce'] ) || in_array( $pagenow, $this->writing_screens(), true ) ) {
			$area = Scopes::admin_area(
				(string) $pagenow,
				$action,
				isset( $_REQUEST['page'] ) ? sanitize_key( wp_unslash( $_REQUEST['page'] ) ) : '',
				is_network_admin(),
				isset( $_POST['option_page'] )
			);

			// A freeze that does not cover this area leaves the request alone, and does not log it.
			if ( $this->blocker->state()->freezes( $area ) ) {
				$this->blocker->stop( Blocker::method() . ' ' . Blocker::uri(), 'admin', $area );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification
	}

	/**
	 * Admin screens that are blocked even when only opened, because opening them writes.
	 *
	 * @return string[]
	 */
	private function writing_screens(): array {
		/**
		 * Filters the admin screens blocked during a freeze even when only opened.
		 *
		 * @param string[] $screens File names. Default post-new.php (it saves an auto-draft) and upgrade.php.
		 */
		return array_map( 'strval', (array) apply_filters( 'axle_read_only_mode_writing_screens', self::WRITING_SCREENS ) );
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

		$area = Scopes::ajax_area( $action );
		if ( ! $this->blocker->state()->freezes( $area ) ) {
			return;
		}

		$custom = $this->blocker->state()->allow_list()->ajax_actions();

		/**
		 * Filters the admin-ajax actions that keep working during a freeze.
		 *
		 * Includes the actions on the allow-list in the settings, so code can
		 * add to the list or take one off it.
		 *
		 * @param string[] $actions Read-only ajax actions, and the ones on the allow-list.
		 */
		$allowed = (array) apply_filters( 'axle_read_only_mode_allowed_ajax_actions', array_merge( self::READ_ONLY_AJAX, $custom ) );

		if ( in_array( $action, $allowed, true ) ) {
			if ( 'heartbeat' === $action ) {
				// Heartbeat stays on so editors learn about the freeze, but must not autosave.
				remove_filter( 'heartbeat_received', 'heartbeat_autosave', 500 );
			}

			// The read-only list is not an exception, so it is not logged.
			if ( ! in_array( $action, self::READ_ONLY_AJAX, true ) ) {
				$this->blocker->record_allowed( 'admin-ajax ' . $action, 'ajax', in_array( $action, $custom, true ) ? 'settings' : 'code', $area );
			}
			return;
		}

		$this->blocker->stop( 'admin-ajax ' . $action, 'ajax', $area );
	}
}
