<?php
/**
 * The saved freeze.
 *
 * @package AryanX\DeployFreeze
 */

namespace AryanX\DeployFreeze;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the current (or scheduled) freeze.
 */
final class State {

	public const OPTION = 'deploy_freeze_state';

	private const DEFAULTS = array(
		'active'          => false,
		'start'           => 0,
		'end'             => 0,
		'reason'          => '',
		'visitor_message' => '',
		'visitors'        => true,
		'cron'            => true,
		'exempt'          => array(),
		'by'              => 0,
	);

	/**
	 * Saved state merged with defaults.
	 *
	 * @return array{active: bool, start: int, end: int, reason: string, visitor_message: string, visitors: bool, cron: bool, exempt: int[], by: int}
	 */
	public function all(): array {
		$saved = get_option( self::OPTION, array() );

		return array_merge( self::DEFAULTS, is_array( $saved ) ? array_intersect_key( $saved, self::DEFAULTS ) : array() );
	}

	/**
	 * One value.
	 *
	 * @param string $key Name.
	 * @return mixed
	 */
	public function get( string $key ) {
		return $this->all()[ $key ] ?? null;
	}

	/**
	 * Saves new values, keeping the rest.
	 *
	 * @param array $changes Name => value.
	 */
	public function update( array $changes ): void {
		update_option( self::OPTION, array_merge( $this->all(), array_intersect_key( $changes, self::DEFAULTS ) ) );
	}

	/**
	 * Whether wp-config.php forces the freeze on.
	 *
	 * @return bool
	 */
	public function forced_by_constant(): bool {
		return defined( 'DEPLOY_FREEZE' ) && DEPLOY_FREEZE;
	}

	/**
	 * Whether the site is frozen right now.
	 *
	 * A freeze with an end time expires on its own, so a deploy that dies
	 * halfway cannot leave the site frozen for days.
	 *
	 * @return bool
	 */
	public function is_frozen(): bool {
		if ( $this->forced_by_constant() ) {
			return true;
		}

		$state = $this->all();
		$now   = time();

		$frozen = $state['active'] && $now >= $state['start'] && ( ! $state['end'] || $now < $state['end'] );

		/**
		 * Filters whether the site is frozen right now.
		 *
		 * Lets a deploy system freeze every site from one switch (an environment
		 * variable, a cache key). Runs on plugins_loaded, so add it from a
		 * must-use plugin. The DEPLOY_FREEZE constant always wins.
		 *
		 * @param bool  $frozen Whether the freeze is on.
		 * @param array $state  The saved freeze.
		 */
		return (bool) apply_filters( 'deploy_freeze_is_frozen', $frozen, $state );
	}

	/**
	 * Whether a freeze is set to start later.
	 *
	 * @return bool
	 */
	public function is_scheduled(): bool {
		$state = $this->all();

		return $state['active'] && $state['start'] > time();
	}

	/**
	 * Whether a freeze ran past its end time and has not been recorded yet.
	 *
	 * @return bool
	 */
	public function has_expired(): bool {
		$state = $this->all();

		return $state['active'] && $state['end'] && time() >= $state['end'];
	}

	/**
	 * Seconds until the freeze ends, or 0 when it has no end.
	 *
	 * @return int
	 */
	public function remaining(): int {
		$end = (int) $this->get( 'end' );

		return $end ? max( 0, $end - time() ) : 0;
	}

	/**
	 * Whether the current user can keep working during the freeze.
	 *
	 * WP-CLI and cron are never blocked; deploy scripts need them.
	 *
	 * @return bool
	 */
	public function can_bypass(): bool {
		$user_id = get_current_user_id();
		$exempt  = $user_id && in_array( $user_id, array_map( 'intval', (array) $this->get( 'exempt' ) ), true );

		/**
		 * Filters whether the current user can keep editing during a freeze.
		 *
		 * @param bool $exempt  Whether the user is exempt.
		 * @param int  $user_id Current user ID, 0 for visitors.
		 */
		return (bool) apply_filters( 'deploy_freeze_user_can_bypass', $exempt, $user_id );
	}

	/**
	 * Time the freeze ends, formatted in the site's time zone.
	 *
	 * @return string Empty when the freeze has no end.
	 */
	public function end_time(): string {
		$end = (int) $this->get( 'end' );

		return $end && ! $this->forced_by_constant() ? wp_date( get_option( 'time_format' ), $end ) : '';
	}

	/**
	 * The message logged-in users see when something is blocked.
	 *
	 * @return string
	 */
	public function message(): string {
		$reason = $this->forced_by_constant() && defined( 'DEPLOY_FREEZE_REASON' ) ? (string) DEPLOY_FREEZE_REASON : (string) $this->get( 'reason' );
		$until  = $this->end_time();

		$message = $until
			/* translators: %s: time the freeze ends. */
			? sprintf( __( 'This site is frozen for maintenance until %s. Changes cannot be saved right now.', 'deploy-freeze' ), $until )
			: __( 'This site is frozen for maintenance. Changes cannot be saved right now.', 'deploy-freeze' );

		if ( '' !== $reason ) {
			/* translators: %s: reason for the freeze. */
			$message .= ' ' . sprintf( __( 'Reason: %s', 'deploy-freeze' ), $reason );
		}

		/**
		 * Filters the message logged-in users see during a freeze.
		 *
		 * @param string $message Message.
		 * @param array  $state   The freeze.
		 */
		return (string) apply_filters( 'deploy_freeze_message', $message, $this->all() );
	}

	/**
	 * The message visitors see when a form or comment is blocked.
	 *
	 * @return string
	 */
	public function visitor_message(): string {
		$message = (string) $this->get( 'visitor_message' );
		if ( '' === $message ) {
			$until   = $this->end_time();
			$message = $until
				/* translators: %s: time the site is back. */
				? sprintf( __( 'We are doing some maintenance and cannot accept submissions right now. Please try again after %s.', 'deploy-freeze' ), $until )
				: __( 'We are doing some maintenance and cannot accept submissions right now. Please try again shortly.', 'deploy-freeze' );
		}

		/**
		 * Filters the message visitors get when they send a form during a freeze.
		 *
		 * @param string $message Message, the one set for this freeze or the default.
		 * @param array  $state   The freeze.
		 */
		return (string) apply_filters( 'deploy_freeze_visitor_message', $message, $this->all() );
	}
}
