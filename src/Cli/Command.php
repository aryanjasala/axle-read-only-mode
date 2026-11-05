<?php
/**
 * `wp axle-read-only-mode` commands.
 *
 * @package AryanX\Axle\ReadOnlyMode
 */

namespace AryanX\Axle\ReadOnlyMode\Cli;

use AryanX\Axle\ReadOnlyMode\BlockedLog;
use AryanX\Axle\ReadOnlyMode\Freezer;
use AryanX\Axle\ReadOnlyMode\Network;
use AryanX\Axle\ReadOnlyMode\State;

defined( 'ABSPATH' ) || exit;

/**
 * Freezes and unfreezes the site from deploy scripts.
 *
 * On multisite a command acts on the site WP-CLI loaded: the main site, or the
 * one given with the global `--url`. Add `--network` to freeze, extend, end or
 * read the whole network instead.
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
	 * The network's freeze, on multisite.
	 *
	 * @var Network|null
	 */
	private ?Network $network;

	/**
	 * Sets up the command.
	 *
	 * @param State        $state   Saved freeze.
	 * @param Freezer      $freezer Starts, extends and ends freezes.
	 * @param BlockedLog   $log     Blocked attempts.
	 * @param Network|null $network The network's freeze, on multisite.
	 */
	public function __construct( State $state, Freezer $freezer, BlockedLog $log, ?Network $network = null ) {
		$this->state   = $state;
		$this->freezer = $freezer;
		$this->log     = $log;
		$this->network = $network;
	}

	/**
	 * The network, when `--network` is given.
	 *
	 * @param array $assoc_args Named arguments.
	 * @return Network|null Null when the command is for the site.
	 */
	private function network_target( array $assoc_args ): ?Network {
		if ( ! \WP_CLI\Utils\get_flag_value( $assoc_args, 'network', false ) ) {
			return null;
		}

		if ( ! $this->network ) {
			\WP_CLI::error( '--network only works on a multisite network.' );
		}

		return $this->network;
	}

	/**
	 * Freezes the site, or with --network the whole network.
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
	 * [--network]
	 * : Freeze the whole multisite network instead of one site. Without it, the command freezes the site WP-CLI loaded: the main site, or the one given with --url.
	 *
	 * [--sites=<ids>]
	 * : With --network, freeze only these sites. Comma-separated site IDs. Default: every site.
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-read-only-mode on --minutes=30 --reason="Moving servers"
	 *     wp axle-read-only-mode on --url=shop.example.com --minutes=30
	 *     wp axle-read-only-mode on --network --minutes=30
	 *     wp axle-read-only-mode on --network --sites=2,5 --minutes=30
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function on( array $args, array $assoc_args ): void {
		$network = $this->network_target( $assoc_args );
		$freeze  = array(
			'minutes'         => isset( $assoc_args['minutes'] ) ? (int) $assoc_args['minutes'] : Freezer::default_duration(),
			'reason'          => (string) ( $assoc_args['reason'] ?? '' ),
			'visitor_message' => (string) ( $assoc_args['visitor-message'] ?? '' ),
			'visitors'        => \WP_CLI\Utils\get_flag_value( $assoc_args, 'visitors', true ),
			'cron'            => \WP_CLI\Utils\get_flag_value( $assoc_args, 'cron', true ),
		);

		if ( ! $network ) {
			if ( isset( $assoc_args['sites'] ) ) {
				\WP_CLI::error( '--sites needs --network.' );
			}

			$this->freezer->freeze( $freeze );
			\WP_CLI::success( $this->state->message() );
			return;
		}

		if ( isset( $assoc_args['sites'] ) ) {
			$sites = array_values( array_filter( array_map( 'absint', explode( ',', (string) $assoc_args['sites'] ) ) ) );
			foreach ( $sites as $site_id ) {
				if ( ! get_site( $site_id ) ) {
					\WP_CLI::error( sprintf( 'There is no site with ID %d.', $site_id ) );
				}
			}
			if ( ! $sites ) {
				\WP_CLI::error( '--sites needs at least one site ID.' );
			}

			$freeze['scope'] = 'sites';
			$freeze['sites'] = $sites;
		}

		$network->freezer->freeze( $freeze );
		\WP_CLI::success( $this->network_summary( $network ) );
	}

	/**
	 * Ends the freeze, or with --network the network freeze.
	 *
	 * ## OPTIONS
	 *
	 * [--network]
	 * : End the network freeze. A site frozen by the network stays frozen until then, whatever its own freeze says.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function off( array $args = array(), array $assoc_args = array() ): void {
		$network = $this->network_target( $assoc_args );
		if ( $network ) {
			$blocked = $network->log->count();
			$network->freezer->unfreeze( 'cli' );

			\WP_CLI::success( sprintf( 'Network unfrozen. %d attempt(s) were blocked across the network during the freeze.', $blocked ) );
			return;
		}

		$blocked = $this->log->count();
		$this->freezer->unfreeze( 'cli' );

		\WP_CLI::success( sprintf( 'Site unfrozen. %d attempt(s) were blocked during the freeze.', $blocked ) );

		if ( $this->state->network_applies() ) {
			\WP_CLI::warning( 'The site is still frozen by the network. Run this with --network to lift it.' );
		}
	}

	/**
	 * Adds time to the freeze.
	 *
	 * ## OPTIONS
	 *
	 * <minutes>
	 * : Minutes to add.
	 *
	 * [--network]
	 * : Extend the network freeze.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function extend( array $args, array $assoc_args = array() ): void {
		$network = $this->network_target( $assoc_args );
		if ( $network ) {
			if ( ! $network->freezer->extend( (int) $args[0] ) ) {
				\WP_CLI::error( 'The network is not frozen, or the freeze has no end time.' );
			}

			\WP_CLI::success( $this->network_summary( $network ) );
			return;
		}

		if ( ! $this->freezer->extend( (int) $args[0] ) ) {
			\WP_CLI::error( 'The site is not frozen, or the freeze has no end time.' );
		}

		\WP_CLI::success( $this->state->message() );
	}

	/**
	 * Shows whether the site is frozen, and what holds it frozen.
	 *
	 * ## OPTIONS
	 *
	 * [--network]
	 * : Show the network freeze.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function status( array $args = array(), array $assoc_args = array() ): void {
		$network = $this->network_target( $assoc_args );
		if ( $network ) {
			if ( $network->state->is_scheduled() ) {
				\WP_CLI::line( 'Network freeze scheduled for ' . wp_date( 'Y-m-d H:i', (int) $network->state->own_value( 'start' ) ) . '.' );
				return;
			}

			if ( ! $network->state->is_running() ) {
				\WP_CLI::line( 'Network not frozen.' );
				return;
			}

			\WP_CLI::line( $this->network_summary( $network ) );
			\WP_CLI::line( 'Visitor submissions paused: ' . ( $network->state->own_value( 'visitors' ) ? 'yes' : 'no' ) );
			\WP_CLI::line( 'Scheduled tasks paused:     ' . ( $network->state->own_value( 'cron' ) ? 'yes' : 'no' ) );
			\WP_CLI::line( 'Blocked so far:             ' . $network->log->count() );
			return;
		}

		if ( $this->state->is_scheduled() ) {
			\WP_CLI::line( 'Scheduled to freeze at ' . wp_date( 'Y-m-d H:i', (int) $this->state->own_value( 'start' ) ) . '.' );
			return;
		}

		if ( ! $this->state->is_frozen() ) {
			\WP_CLI::line( 'Not frozen.' );
			return;
		}

		\WP_CLI::line( $this->state->message() );
		\WP_CLI::line( 'Frozen by:                  ' . $this->state->source() );
		\WP_CLI::line( 'Visitor submissions paused: ' . ( $this->state->get( 'visitors' ) ? 'yes' : 'no' ) );
		\WP_CLI::line( 'Scheduled tasks paused:     ' . ( $this->state->get( 'cron' ) ? 'yes' : 'no' ) );
		\WP_CLI::line( 'Blocked so far:             ' . $this->log->count( $this->state->log_since() ) );
	}

	/**
	 * One line about a network freeze.
	 *
	 * @param Network $network The network.
	 * @return string
	 */
	private function network_summary( Network $network ): string {
		$own   = $network->state->own();
		$until = $own['end'] ? ' until ' . wp_date( get_option( 'time_format' ), (int) $own['end'] ) : '';
		$where = 'sites' === ( $own['scope'] ?? 'all' ) ? count( (array) ( $own['sites'] ?? array() ) ) . ' site(s)' : 'every site';
		$why   = '' !== $own['reason'] ? ' Reason: ' . $own['reason'] : '';

		return sprintf( 'The network is frozen%s for %s.%s', $until, $where, $why );
	}

	/**
	 * Lists what was blocked during the current or last freeze.
	 *
	 * ## OPTIONS
	 *
	 * [--network]
	 * : List what every site blocked during the network freeze, with a site column.
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
		$network = $this->network_target( $assoc_args );
		$entries = $network ? $network->log->recent( PHP_INT_MAX ) : $this->log->all( $this->state->log_since() );
		$columns = $network ? array( 'time', 'site', 'who', 'source', 'where' ) : array( 'time', 'who', 'source', 'where' );

		$rows = array_map(
			static function ( array $entry ): array {
				return array(
					'time' => gmdate( 'Y-m-d H:i:s', (int) $entry['time'] ),
					'who'  => BlockedLog::who( $entry ),
					'site' => $entry['site_id'] ?? get_current_blog_id(),
				) + $entry;
			},
			$entries
		);

		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, $columns );
	}
}
