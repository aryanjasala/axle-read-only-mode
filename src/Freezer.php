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
	 * @var BlockedLog
	 */
	private BlockedLog $log;

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
	 * @param BlockedLog $log     Blocked attempts.
	 * @param History    $history Past freezes.
	 */
	public function __construct( State $state, BlockedLog $log, History $history ) {
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
	 * }
	 */
	public function freeze( array $args ): void {
		$start   = ! empty( $args['start'] ) ? (int) $args['start'] : time();
		$minutes = isset( $args['minutes'] ) ? max( 0, (int) $args['minutes'] ) : self::default_duration();

		// The previous freeze's one-time expiry marker is no longer needed.
		delete_option( self::expiry_key( (int) $this->state->get( 'start' ) ) );
		$this->log->clear();
		$this->state->update(
			array(
				'active'          => true,
				'start'           => $start,
				'end'             => $minutes ? $start + $minutes * MINUTE_IN_SECONDS : 0,
				'reason'          => sanitize_text_field( (string) ( $args['reason'] ?? '' ) ),
				'visitor_message' => sanitize_text_field( (string) ( $args['visitor_message'] ?? '' ) ),
				'visitors'        => ! isset( $args['visitors'] ) || (bool) $args['visitors'],
				'cron'            => ! isset( $args['cron'] ) || (bool) $args['cron'],
				'exempt'          => array_map( 'intval', (array) ( $args['exempt'] ?? array() ) ),
				'by'              => get_current_user_id(),
			)
		);

		/**
		 * Fires when a freeze starts, or is scheduled to start.
		 *
		 * @param array $state The freeze: start, end, reason, visitors, cron, exempt and by.
		 */
		do_action( 'axle_read_only_mode_started', $this->state->all() );
	}

	/**
	 * Pushes the end time back. A freeze with no end is left alone.
	 *
	 * @param int $minutes Minutes to add.
	 * @return bool Whether the freeze was extended.
	 */
	public function extend( int $minutes ): bool {
		$end = (int) $this->state->get( 'end' );
		if ( ! $this->state->is_frozen() || ! $end || $minutes <= 0 ) {
			return false;
		}

		$this->state->update( array( 'end' => max( $end, time() ) + $minutes * MINUTE_IN_SECONDS ) );

		/**
		 * Fires when a freeze is extended.
		 *
		 * @param array $state   The freeze with its new end.
		 * @param int   $minutes Minutes added.
		 */
		do_action( 'axle_read_only_mode_extended', $this->state->all(), $minutes );

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
		 * Also preselects that length on Tools > Axle Read-Only Mode.
		 *
		 * @param int $minutes Default 60. 0 means until someone unfreezes.
		 */
		return max( 0, (int) apply_filters( 'axle_read_only_mode_default_duration', 60 ) );
	}

	/**
	 * Lengths offered on Tools > Axle Read-Only Mode.
	 *
	 * @return int[] Minutes, 0 for no end. Always includes the default length.
	 */
	public static function durations(): array {
		/**
		 * Filters the lengths offered on Tools > Axle Read-Only Mode.
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
	 * @param string $ended_by user, cli or expired.
	 */
	public function unfreeze( string $ended_by = 'user' ): void {
		$state = $this->state->all();
		if ( ! $state['active'] ) {
			return;
		}

		if ( $state['start'] <= time() ) {
			$this->history->add(
				array(
					'start'    => (int) $state['start'],
					'end'      => 'expired' === $ended_by ? (int) $state['end'] : time(),
					'reason'   => (string) $state['reason'],
					'by'       => (int) $state['by'],
					'ended_by' => $ended_by,
					'blocked'  => $this->log->count(),
				)
			);
		}

		$this->state->update( array( 'active' => false ) );

		/**
		 * Fires when a freeze ends.
		 *
		 * @param array  $state    The freeze that ended.
		 * @param string $ended_by user, wp-cli or expired.
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
		if ( add_option( self::expiry_key( (int) $this->state->get( 'start' ) ), time(), '', false ) ) {
			$this->unfreeze( 'expired' );
		}
	}

	/**
	 * Option that marks a freeze as already expired.
	 *
	 * @param int $start Start time of the freeze.
	 * @return string
	 */
	private static function expiry_key( int $start ): string {
		return 'axle_read_only_mode_expired_' . $start;
	}
}
