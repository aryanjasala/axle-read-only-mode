<?php
/**
 * `wp axle-read-only-mode` commands.
 *
 * @package AryanX\AxleReadOnlyMode
 */

namespace AryanX\AxleReadOnlyMode\Cli;

use AryanX\AxleReadOnlyMode\BlockedLog;
use AryanX\AxleReadOnlyMode\Freezer;
use AryanX\AxleReadOnlyMode\State;

defined( 'ABSPATH' ) || exit;

/**
 * Freezes and unfreezes the site from deploy scripts.
 */
final class Command {

	/**
	 * Saved freeze.
	 *
	 * @var State
	 */
	private State $state;

	/**
	 * Starts, extends and ends freezes.
	 *
	 * @var Freezer
	 */
	private Freezer $freezer;

	/**
	 * Blocked attempts.
	 *
	 * @var BlockedLog
	 */
	private BlockedLog $log;

	/**
	 * Sets up the command.
	 *
	 * @param State      $state   Saved freeze.
	 * @param Freezer    $freezer Starts, extends and ends freezes.
	 * @param BlockedLog $log     Blocked attempts.
	 */
	public function __construct( State $state, Freezer $freezer, BlockedLog $log ) {
		$this->state   = $state;
		$this->freezer = $freezer;
		$this->log     = $log;
	}

	/**
	 * Freezes the site.
	 *
	 * ## OPTIONS
	 *
	 * [--minutes=<minutes>]
	 * : Minutes until the freeze ends on its own. 0 means it lasts until `wp axle-read-only-mode off`. Default 60, or the axle_read_only_mode_default_duration filter.
	 *
	 * [--reason=<reason>]
	 * : Shown to logged-in users who try to save something.
	 *
	 * [--visitor-message=<message>]
	 * : Shown to visitors whose comment, form or order is blocked.
	 *
	 * [--[no-]visitors]
	 * : Pause comments, forms and sign-ups. On by default.
	 *
	 * [--[no-]cron]
	 * : Pause scheduled tasks. On by default.
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-read-only-mode on --minutes=30 --reason="Moving servers"
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function on( array $args, array $assoc_args ): void {
		$this->freezer->freeze(
			array(
				'minutes'         => isset( $assoc_args['minutes'] ) ? (int) $assoc_args['minutes'] : Freezer::default_duration(),
				'reason'          => (string) ( $assoc_args['reason'] ?? '' ),
				'visitor_message' => (string) ( $assoc_args['visitor-message'] ?? '' ),
				'visitors'        => \WP_CLI\Utils\get_flag_value( $assoc_args, 'visitors', true ),
				'cron'            => \WP_CLI\Utils\get_flag_value( $assoc_args, 'cron', true ),
			)
		);

		\WP_CLI::success( $this->state->message() );
	}

	/**
	 * Ends the freeze.
	 */
	public function off(): void {
		$blocked = $this->log->count();
		$this->freezer->unfreeze( 'cli' );

		\WP_CLI::success( sprintf( 'Site unfrozen. %d attempt(s) were blocked during the freeze.', $blocked ) );
	}

	/**
	 * Adds time to the freeze.
	 *
	 * ## OPTIONS
	 *
	 * <minutes>
	 * : Minutes to add.
	 *
	 * @param array $args Positional arguments.
	 */
	public function extend( array $args ): void {
		if ( ! $this->freezer->extend( (int) $args[0] ) ) {
			\WP_CLI::error( 'The site is not frozen, or the freeze has no end time.' );
		}

		\WP_CLI::success( $this->state->message() );
	}

	/**
	 * Shows whether the site is frozen.
	 */
	public function status(): void {
		if ( $this->state->is_scheduled() ) {
			\WP_CLI::line( 'Scheduled to freeze at ' . wp_date( 'Y-m-d H:i', (int) $this->state->get( 'start' ) ) . '.' );
			return;
		}

		if ( ! $this->state->is_frozen() ) {
			\WP_CLI::line( 'Not frozen.' );
			return;
		}

		\WP_CLI::line( $this->state->message() );
		\WP_CLI::line( 'Visitor submissions paused: ' . ( $this->state->get( 'visitors' ) ? 'yes' : 'no' ) );
		\WP_CLI::line( 'Scheduled tasks paused:     ' . ( $this->state->get( 'cron' ) ? 'yes' : 'no' ) );
		\WP_CLI::line( 'Blocked so far:             ' . $this->log->count() );
	}

	/**
	 * Lists what was blocked during the current or last freeze.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, csv, json or count.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function log( array $args, array $assoc_args ): void {
		$rows = array_map(
			static function ( array $entry ): array {
				return array(
					'time' => gmdate( 'Y-m-d H:i:s', (int) $entry['time'] ),
					'who'  => BlockedLog::who( $entry ),
				) + $entry;
			},
			$this->log->all()
		);

		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'time', 'who', 'source', 'where' ) );
	}
}
