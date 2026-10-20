<?php
/**
 * Pauses everything else that writes on its own.
 *
 * @package AryanX\SiteReadOnlyMode
 */

namespace AryanX\SiteReadOnlyMode\Guards;

use AryanX\SiteReadOnlyMode\Service;
use AryanX\SiteReadOnlyMode\State;

defined( 'ABSPATH' ) || exit;

/**
 * XML-RPC, automatic updates, and optionally comments, sign-ups and cron.
 */
final class SideEffects implements Service {

	/**
	 * Saved freeze.
	 *
	 * @var State
	 */
	private State $state;

	/**
	 * Sets up the pauses.
	 *
	 * @param State $state Saved freeze.
	 */
	public function __construct( State $state ) {
		$this->state = $state;
	}

	/**
	 * Adds the filters.
	 */
	public function register(): void {
		add_filter( 'xmlrpc_enabled', '__return_false' );
		add_filter( 'automatic_updater_disabled', '__return_true' );

		if ( $this->state->get( 'visitors' ) ) {
			add_filter( 'comments_open', '__return_false', PHP_INT_MAX );
			add_filter( 'pings_open', '__return_false', PHP_INT_MAX );
			add_filter( 'pre_option_users_can_register', array( $this, 'registration_closed' ) );
		}

		if ( $this->state->get( 'cron' ) ) {
			add_filter( 'pre_get_ready_cron_jobs', array( $this, 'no_cron_jobs' ) );
		}
	}

	/**
	 * Closes user registration.
	 *
	 * @return string
	 */
	public function registration_closed(): string {
		return '0';
	}

	/**
	 * Holds back scheduled tasks. They run once the freeze ends.
	 *
	 * @return array
	 */
	public function no_cron_jobs(): array {
		return array();
	}
}
