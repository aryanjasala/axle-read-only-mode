<?php
/**
 * Starts, extends and ends freezes.
 *
 * @package AryanX\Axle\ReadOnlyMode
 */

namespace AryanX\Axle\ReadOnlyMode;

defined( 'ABSPATH' ) || exit;

/**
 * The one place that changes the freeze, so the log and history stay in step.
 *
 * One Freezer works on one freeze: the site's, or the network's. The
 * network's fires `axle_read_only_mode_network_*` actions instead.
 */
final class Freezer {

	/**
	 * Saved freeze.
	 *
	 * @var State
	 */
	private State $state;

	/**
	 * Blocked attempts.
	 *
	 * @var LogCounter
	 */
	private LogCounter $log;

	/**
	 * Past freezes.
	 *
	 * @var History
	 */
	private History $history;

	/**
	 * Sets up the freezer.
	 *
	 * @param State      $state   Saved freeze.
	 * @param LogCounter $log     Blocked attempts.
	 * @param History    $history Past freezes.
	 */
	public function __construct( State $state, LogCounter $log, History $history ) {
		$this->state   = $state;
		$this->log     = $log;
		$this->history = $history;
	}

	/**
	 * Starts (or schedules) a freeze.
	 *
	 * @param array $args {
	 *     Freeze settings.
	 *
	 *     @type int    $start           Unix time to start, 0 for now.
	 *     @type int    $minutes         Length in minutes, 0 for no end.
	 *     @type string $reason          Shown to people who are blocked.
	 *     @type string $visitor_message Shown to visitors whose submission is blocked.
	 *     @type bool   $visitors        Also pause comments, forms and sign-ups.
	 *     @type bool   $cron            Also pause scheduled tasks.
	 *     @type int[]  $exempt          Users who can keep working.
	 *     @type string $area            What the freeze covers: all (default), content or code. See Scopes.
	 *     @type bool   $until_green     End when the smoke test passes instead of at a time. Needs Axle Smoke Test; `minutes` is ignored. Not for network freezes.
	 *     @type string $scope           For a network freeze: all, or sites for the ones in $sites.
	 *     @type int[]  $sites           For a network freeze with scope sites: the sites it covers.
	 * }
	 */
	public function freeze( array $args ): void {
		$start   = ! empty( $args['start'] ) ? (int) $args['start'] : time();
		$green   = ! empty( $args['until_green'] ) && ! $this->state->is_network() && Green::available();
		$minutes = $green ? 0 : ( isset( $args['minutes'] ) ? max( 0, (int) $args['minutes'] ) : self::default_duration() );

		// The previous freeze's one-time expiry marker is no longer needed.
		$this->forget_marker( self::expiry_key( (int) $this->state->own_value( 'start' ), $this->state->is_network() ) );
		$this->forget_marker( self::marker_key( 'started', (int) $this->state->own_value( 'start' ), $this->state->is_network() ) );
		$this->forget_marker( self::marker_key( 'greentimeout', (int) $this->state->own_value( 'start' ), $this->state->is_network() ) );
		$this->log->clear();

		$changes = array(
			'active'          => true,
			'start'           => $start,
			'end'             => $minutes ? $start + $minutes * MINUTE_IN_SECONDS : 0,
			'reason'          => sanitize_text_field( (string) ( $args['reason'] ?? '' ) ),
			'visitor_message' => sanitize_text_field( (string) ( $args['visitor_message'] ?? '' ) ),
			'visitors'        => ! isset( $args['visitors'] ) || (bool) $args['visitors'],
			'cron'            => ! isset( $args['cron'] ) || (bool) $args['cron'],
			'exempt'          => array_map( 'intval', (array) ( $args['exempt'] ?? array() ) ),
			'by'              => get_current_user_id(),
			'area'            => isset( $args['area'] ) ? Scopes::normalize( (string) $args['area'] ) : Scopes::default_scope(),
			// A freeze set for later announces itself once it starts.
			'announce'        => $start > time(),
			'until_green'     => $green,
			'green_deadline'  => $green ? $start + Green::max_minutes() * MINUTE_IN_SECONDS : 0,
			'green_requested' => 0,
			'green_run'       => array(),
		);

		if ( $this->state->is_network() ) {
			$sites            = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $args['sites'] ?? array() ) ) ) ) );
			$changes['scope'] = 'sites' === ( $args['scope'] ?? 'all' ) && $sites ? 'sites' : 'all';
			$changes['sites'] = 'sites' === $changes['scope'] ? $sites : array();
		}

		$this->state->update( $changes );

		if ( $this->state->is_network() ) {
			/**
			 * Fires when a network freeze starts, or is scheduled to start.
			 *
			 * @param array $state The network freeze: start, end, reason, visitors, cron, exempt, by, area (all, content or code), scope (which sites) and sites.
			 */
			do_action( 'axle_read_only_mode_network_started', $this->state->own() );
			return;
		}

		/**
		 * Fires when a freeze starts, or is scheduled to start.
		 *
		 * @param array $state The freeze: start, end, reason, visitors, cron, exempt, by and area (all, content or code).
		 */
		do_action( 'axle_read_only_mode_started', $this->state->own() );
	}

	/**
	 * Pushes the end time back. A freeze with no end is left alone.
	 *
	 * @param int $minutes Minutes to add.
	 * @return bool Whether the freeze was extended.
	 */
	public function extend( int $minutes ): bool {
		$end = (int) $this->state->own_value( 'end' );
		if ( $this->state->waits_for_green() && $minutes > 0 ) {
			// A freeze that waits for the smoke test has no end time. Extending moves its hard limit.
			$this->state->update( array( 'green_deadline' => max( (int) $this->state->own_value( 'green_deadline' ), time() ) + $minutes * MINUTE_IN_SECONDS ) );
			Green::schedule_deadline( (int) $this->state->own_value( 'green_deadline' ) );

			do_action( 'axle_read_only_mode_extended', $this->state->own(), $minutes );

			return true;
		}
		if ( ! $this->state->is_running() || ! $end || $minutes <= 0 ) {
			return false;
		}

		$this->state->update( array( 'end' => max( $end, time() ) + $minutes * MINUTE_IN_SECONDS ) );

		if ( $this->state->is_network() ) {
			/**
			 * Fires when a network freeze is extended.
			 *
			 * @param array $state   The network freeze with its new end.
			 * @param int   $minutes Minutes added.
			 */
			do_action( 'axle_read_only_mode_network_extended', $this->state->own(), $minutes );

			return true;
		}

		/**
		 * Fires when a freeze is extended.
		 *
		 * @param array $state   The freeze with its new end.
		 * @param int   $minutes Minutes added.
		 */
		do_action( 'axle_read_only_mode_extended', $this->state->own(), $minutes );

		return true;
	}

	/**
	 * Minutes a freeze lasts when nobody says: the quick freeze in the admin bar,
	 * `wp axle-read-only-mode on` without --minutes, and the PHP API.
	 *
	 * @return int Minutes, 0 for no end.
	 */
	public static function default_duration(): int {
		/**
		 * Filters how long a freeze lasts when no length is given.
		 *
		 * Also preselects that length on Tools > Read-Only Mode.
		 *
		 * @param int $minutes Default 60. 0 means until someone unfreezes.
		 */
		return max( 0, (int) apply_filters( 'axle_read_only_mode_default_duration', 60 ) );
	}

	/**
	 * Lengths offered on Tools > Read-Only Mode.
	 *
	 * @return int[] Minutes, 0 for no end. Always includes the default length.
	 */
	public static function durations(): array {
		/**
		 * Filters the lengths offered on Tools > Read-Only Mode.
		 *
		 * @param int[] $minutes Default 30, 60, 120, 240, 480 and 0 (until someone unfreezes).
		 */
		$durations = array_map( 'absint', (array) apply_filters( 'axle_read_only_mode_durations', array( 30, 60, 120, 240, 480, 0 ) ) );
		$durations = array_values( array_unique( array_merge( $durations, array( self::default_duration() ) ) ) );

		// Lengths first, shortest to longest, then "until I unfreeze it".
		usort(
			$durations,
			static function ( int $a, int $b ): int {
				return ( 0 === $a ? PHP_INT_MAX : $a ) <=> ( 0 === $b ? PHP_INT_MAX : $b );
			}
		);

		return $durations;
	}

	/**
	 * Steps offered for extending a freeze, in the admin bar and on the screen.
	 *
	 * @return int[] Minutes, at least one.
	 */
	public static function extend_steps(): array {
		/**
		 * Filters the "+30 minutes" style buttons for extending a freeze.
		 *
		 * The first one is also the admin bar's "Add 30 minutes".
		 *
		 * @param int[] $minutes Default 30 and 60.
		 */
		$steps = array_values( array_filter( array_map( 'absint', (array) apply_filters( 'axle_read_only_mode_extend_minutes', array( 30, 60 ) ) ) ) );

		return $steps ? $steps : array( 30 );
	}

	/**
	 * Ends the freeze (or cancels a scheduled one) and records it.
	 *
	 * @param string $ended_by user, cli, ability, expired, green (the smoke test passed) or green_timeout (the hard limit came first).
	 */
	public function unfreeze( string $ended_by = 'user' ): void {
		$state = $this->state->own();
		if ( ! $state['active'] ) {
			return;
		}

		if ( $state['start'] <= time() ) {
			$entry = array(
				'start'    => (int) $state['start'],
				'end'      => 'expired' === $ended_by ? (int) $state['end'] : time(),
				'reason'   => (string) $state['reason'],
				'by'       => (int) $state['by'],
				'ended_by' => $ended_by,
				'blocked'  => $this->log->count(),
				'scope'    => Scopes::normalize( (string) $state['area'] ),
			);
			if ( ! empty( $state['until_green'] ) ) {
				$entry['until_green'] = true;
				$entry['run']         = (int) ( $state['green_run']['id'] ?? 0 );
				$entry['verdict']     = (string) ( $state['green_run']['verdict'] ?? '' );
			}
			if ( $this->state->is_network() ) {
				$entry['sites'] = 'sites' === ( $state['scope'] ?? 'all' ) ? count( (array) ( $state['sites'] ?? array() ) ) : 0;
			}
			$this->history->add( $entry );
		}

		$this->state->update( array( 'active' => false ) );

		if ( ! empty( $state['until_green'] ) ) {
			Green::schedule_deadline( 0 );
		}

		if ( $this->state->is_network() ) {
			/**
			 * Fires when a network freeze ends.
			 *
			 * @param array  $state    The network freeze that ended.
			 * @param string $ended_by user, cli, ability or expired.
			 */
			do_action( 'axle_read_only_mode_network_ended', $state, $ended_by );

			return;
		}

		/**
		 * Fires when a freeze ends.
		 *
		 * @param array  $state    The freeze that ended.
		 * @param string $ended_by user, cli, ability or expired.
		 */
		do_action( 'axle_read_only_mode_ended', $state, $ended_by );
	}

	/**
	 * Records a freeze that ran out on its own. Runs on every request.
	 */
	public function expire_if_needed(): void {
		if ( ! $this->state->has_expired() ) {
			return;
		}

		// Many requests can notice the end at once. add_option() succeeds for
		// one of them only, so the history gets one entry per freeze.
		if ( $this->claim_marker( self::expiry_key( (int) $this->state->own_value( 'start' ), $this->state->is_network() ) ) ) {
			$this->unfreeze( 'expired' );
		}
	}

	/**
	 * Ends a freeze that waited for the smoke test when its hard limit has passed.
	 *
	 * Runs on every request and from one WP-Cron event, and reads only the
	 * saved freeze, so a stuck run cannot hold the site forever and the check
	 * costs no query.
	 */
	public function end_green_wait_if_needed(): void {
		if ( ! $this->state->waits_for_green() ) {
			return;
		}

		$deadline = (int) $this->state->own_value( 'green_deadline' );
		if ( ! $deadline || time() < $deadline ) {
			return;
		}

		// Many requests can notice the limit at once. Only one claims it.
		if ( ! $this->claim_marker( self::marker_key( 'greentimeout', (int) $this->state->own_value( 'start' ), false ) ) ) {
			return;
		}

		/**
		 * Fires when a freeze that waited for the smoke test reaches its hard limit and ends without a green run.
		 *
		 * @param array $state The freeze, with the last run that finished (green_run) if any.
		 */
		do_action( 'axle_read_only_mode_green_timeout', $this->state->own() );

		$this->unfreeze( 'green_timeout' );
	}

	/**
	 * Announces a freeze that was set for later, once its start time has come.
	 *
	 * Runs on every request. A freeze that starts right away is announced by
	 * `started` when it is made, so only scheduled freezes come through here.
	 */
	public function announce_scheduled_start(): void {
		$own = $this->state->own();
		if ( empty( $own['announce'] ) || ! $this->state->is_running() ) {
			return;
		}

		// Many requests can notice the start at once. Only one claims it.
		if ( ! $this->claim_marker( self::marker_key( 'started', (int) $own['start'], $this->state->is_network() ) ) ) {
			return;
		}

		$this->state->update( array( 'announce' => false ) );

		if ( $this->state->is_network() ) {
			/**
			 * Fires when a network freeze that was scheduled starts.
			 *
			 * @param array $state The network freeze.
			 */
			do_action( 'axle_read_only_mode_network_scheduled_started', $this->state->own() );

			return;
		}

		/**
		 * Fires when a freeze that was scheduled starts.
		 *
		 * Runs on the first request after the start time, so a quiet site can be late.
		 *
		 * @param array $state The freeze.
		 */
		do_action( 'axle_read_only_mode_scheduled_started', $this->state->own() );
	}

	/**
	 * Option that marks a freeze as already expired.
	 *
	 * @param int  $start   Start time of the freeze.
	 * @param bool $network Whether it is the network's freeze.
	 * @return string
	 */
	private static function expiry_key( int $start, bool $network ): string {
		return self::marker_key( 'expired', $start, $network );
	}

	/**
	 * Name of a one-time marker option.
	 *
	 * @param string $kind    expired or started.
	 * @param int    $start   Start time of the freeze.
	 * @param bool   $network Whether it is the network's freeze.
	 * @return string
	 */
	private static function marker_key( string $kind, int $start, bool $network ): string {
		return 'axle_read_only_mode_' . ( $network ? 'network_' : '' ) . $kind . '_' . $start;
	}

	/**
	 * Claims a one-time marker. Only one of many simultaneous requests gets it.
	 *
	 * @param string $key Option name.
	 * @return bool Whether this call set it.
	 */
	private function claim_marker( string $key ): bool {
		return $this->state->is_network() ? (bool) add_site_option( $key, time() ) : (bool) add_option( $key, time(), '', false );
	}

	/**
	 * Removes a one-time marker.
	 *
	 * @param string $key Option name.
	 */
	private function forget_marker( string $key ): void {
		if ( $this->state->is_network() ) {
			delete_site_option( $key );
			return;
		}

		delete_option( $key );
	}
}
