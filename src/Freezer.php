<?php
/**
 * Starts, extends and ends freezes.
 *
 * @package AryanX\DeployFreeze
 */

namespace AryanX\DeployFreeze;

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
		$minutes = isset( $args['minutes'] ) ? max( 0, (int) $args['minutes'] ) : 60;

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

		return true;
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
	}

	/**
	 * Records a freeze that ran out on its own. Runs on every request.
	 */
	public function expire_if_needed(): void {
		if ( $this->state->has_expired() ) {
			$this->unfreeze( 'expired' );
		}
	}
}
