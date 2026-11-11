<?php
/**
 * Pauses everything else that writes on its own.
 *
 * @package AryanX\Axle\ReadOnlyMode
 */

namespace AryanX\Axle\ReadOnlyMode\Guards;

use AryanX\Axle\ReadOnlyMode\Scopes;
use AryanX\Axle\ReadOnlyMode\Service;
use AryanX\Axle\ReadOnlyMode\State;

defined( 'ABSPATH' ) || exit;

/**
 * XML-RPC, automatic updates, and optionally comments, sign-ups and cron, as far as the freeze's scope covers them.
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
		$content = $this->state->freezes( Scopes::CONTENT );
		$code    = $this->state->freezes( Scopes::CODE );

		if ( $content ) {
			// XML-RPC writes posts, comments and media. A settings and code freeze keeps it, minus the methods that change settings.
			add_filter( 'xmlrpc_enabled', '__return_false' );
		} elseif ( $code ) {
			add_filter( 'xmlrpc_methods', array( $this, 'drop_code_methods' ) );
		}

		if ( $code ) {
			add_filter( 'automatic_updater_disabled', '__return_true' );
		}

		// Comments, pings and sign-ups are content, so they follow the scope.
		if ( $content && $this->state->get( 'visitors' ) ) {
			add_filter( 'comments_open', '__return_false', PHP_INT_MAX );
			add_filter( 'pings_open', '__return_false', PHP_INT_MAX );
			add_filter( 'pre_option_users_can_register', array( $this, 'registration_closed' ) );
		}

		if ( $this->state->get( 'cron' ) ) {
			add_filter( 'pre_get_ready_cron_jobs', array( $this, 'no_cron_jobs' ) );
		}
	}

	/**
	 * Removes the XML-RPC methods that change settings or accounts.
	 *
	 * @param array $methods XML-RPC methods.
	 * @return array
	 */
	public function drop_code_methods( $methods ): array {
		return array_diff_key( (array) $methods, array_flip( Scopes::code_xmlrpc_methods() ) );
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
	 * Holds back the scheduled tasks the scope covers. They run once the freeze ends.
	 *
	 * Everything holds every task. Content only holds every task except update
	 * checks and site checks. Settings and code only holds just those.
	 *
	 * @return array Ready tasks, in the shape wp_get_ready_cron_jobs() returns.
	 */
	public function no_cron_jobs(): array {
		if ( Scopes::ALL === $this->state->scope() ) {
			return array();
		}

		$crons = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
		$now   = microtime( true );
		$ready = array();

		foreach ( (array) $crons as $timestamp => $hooks ) {
			if ( $timestamp > $now ) {
				break;
			}

			foreach ( (array) $hooks as $hook => $events ) {
				if ( ! $this->state->freezes( Scopes::cron_area( (string) $hook ) ) ) {
					$ready[ $timestamp ][ $hook ] = $events;
				}
			}
		}

		return $ready;
	}
}
