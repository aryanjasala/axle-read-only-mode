<?php
/**
 * `wp axle-read-only-mode` commands.
 *
 * @package AryanX\Axle\ReadOnlyMode
 */

namespace AryanX\Axle\ReadOnlyMode\Cli;

use AryanX\Axle\ReadOnlyMode\AllowList;
use AryanX\Axle\ReadOnlyMode\BlockedLog;
use AryanX\Axle\ReadOnlyMode\Checks;
use AryanX\Axle\ReadOnlyMode\Freezer;
use AryanX\Axle\ReadOnlyMode\Health\SiteHealth;
use AryanX\Axle\ReadOnlyMode\Network;
use AryanX\Axle\ReadOnlyMode\Notifier;
use AryanX\Axle\ReadOnlyMode\Planner;
use AryanX\Axle\ReadOnlyMode\Scopes;
use AryanX\Axle\ReadOnlyMode\Settings;
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
	 * Command words and the allow-list kinds they stand for.
	 */
	private const KINDS = array(
		'rest' => 'rest',
		'path' => 'paths',
		'ajax' => 'ajax',
		'user' => 'users',
	);

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
	 * This site's planned freezes.
	 *
	 * @var Planner|null
	 */
	private ?Planner $planner;

	/**
	 * The network's freeze, on multisite.
	 *
	 * @var Network|null
	 */
	private ?Network $network;

	/**
	 * This site's settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * The Site Health check.
	 *
	 * @var SiteHealth|null
	 */
	private ?SiteHealth $health;

	/**
	 * The "Before you freeze" checks.
	 *
	 * @var Checks
	 */
	private Checks $checks;

	/**
	 * Sets up the command.
	 *
	 * @param State           $state    Saved freeze.
	 * @param Freezer         $freezer  Starts, extends and ends freezes.
	 * @param BlockedLog      $log      Blocked attempts.
	 * @param Network|null    $network  The network's freeze, on multisite.
	 * @param Settings|null   $settings This site's settings.
	 * @param SiteHealth|null $health The Site Health check.
	 * @param Checks|null     $checks The "Before you freeze" checks.
	 * @param Planner|null    $planner This site's planned freezes.
	 */
	public function __construct( State $state, Freezer $freezer, BlockedLog $log, ?Network $network = null, ?Settings $settings = null, ?SiteHealth $health = null, ?Checks $checks = null, ?Planner $planner = null ) {
		$this->planner  = $planner;
		$this->checks   = $checks ?? new Checks();
		$this->state    = $state;
		$this->freezer  = $freezer;
		$this->log      = $log;
		$this->network  = $network;
		$this->settings = $settings ?? new Settings();
		$this->health   = $health;
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
	 * [--scope=<scope>]
	 * : What to freeze. all (everything, the default), content (posts, pages, media, comments, terms, menus and other content; settings, plugins, themes and updates keep working) or code (options, plugins, themes, updates and user accounts; content keeps working).
	 * ---
	 * default: all
	 * ---
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
	 *     wp axle-read-only-mode on --scope=content --minutes=120 --reason="Content migration"
	 *     wp axle-read-only-mode on --scope=code --minutes=30
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
			'area'            => $this->scope_arg( $assoc_args ),
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
	 * The --scope value, checked.
	 *
	 * @param array $assoc_args Named arguments.
	 * @return string all, content or code.
	 */
	private function scope_arg( array $assoc_args ): string {
		if ( ! isset( $assoc_args['scope'] ) ) {
			return Scopes::default_scope();
		}

		$scopes = array_keys( Scopes::all() );
		if ( ! in_array( (string) $assoc_args['scope'], $scopes, true ) ) {
			\WP_CLI::error( sprintf( 'Unknown scope "%s". Choose from: %s.', (string) $assoc_args['scope'], implode( ', ', $scopes ) ) );
		}

		return (string) $assoc_args['scope'];
	}

	/**
	 * Shows what a freeze would interrupt right now, without freezing.
	 *
	 * Lists people with a post open in the editor, scheduled posts and scheduled
	 * tasks due during the freeze, and WooCommerce orders waiting for payment.
	 * It only reports: the exit status is 0 whatever it finds.
	 *
	 * ## OPTIONS
	 *
	 * [--minutes=<minutes>]
	 * : Length of the freeze to check. 0 means no end. Default 60, or the axle_read_only_mode_default_duration filter.
	 *
	 * [--start=<datetime>]
	 * : When the freeze would start, in the site time zone, such as "2026-11-20 22:00". Default: now.
	 *
	 * [--scope=<scope>]
	 * : all, content or code. Default all.
	 *
	 * [--[no-]cron]
	 * : Whether scheduled tasks would be paused. On by default.
	 *
	 * [--[no-]visitors]
	 * : Whether visitor submissions would be paused. On by default.
	 *
	 * [--format=<format>]
	 * : table or json.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-read-only-mode check --minutes=30
	 *     wp axle-read-only-mode check --start="2026-11-20 22:00" --minutes=120 --scope=content --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function check( array $args, array $assoc_args ): void {
		$result = $this->checks->run(
			array(
				'minutes'  => isset( $assoc_args['minutes'] ) ? (int) $assoc_args['minutes'] : Freezer::default_duration(),
				'start'    => isset( $assoc_args['start'] ) ? \AryanX\Axle\ReadOnlyMode\Admin\ToolsPage::parse_start( (string) $assoc_args['start'] ) : 0,
				'scope'    => $this->scope_arg( $assoc_args ),
				'cron'     => \WP_CLI\Utils\get_flag_value( $assoc_args, 'cron', true ),
				'visitors' => \WP_CLI\Utils\get_flag_value( $assoc_args, 'visitors', true ),
			)
		);

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			\WP_CLI::line( (string) wp_json_encode( array_diff_key( $result, array( 'context' => true ) ) ) );
			return;
		}

		foreach ( $result['items'] as $item ) {
			\WP_CLI::line( sprintf( '[%s] %s', $item['word'], $item['title'] ) );
			if ( '' !== $item['detail'] ) {
				\WP_CLI::line( '    ' . $item['detail'] );
			}
			foreach ( $item['rows'] as $row ) {
				\WP_CLI::line( '    - ' . $row );
			}
		}

		\WP_CLI::line( $result['attention'] ? sprintf( '%d check(s) need a look before you freeze.', $result['attention'] ) : 'Nothing would be interrupted.' );
	}

	/**
	 * The planner the command works on: the site's, or with --network the network's.
	 *
	 * @param array $assoc_args Named arguments.
	 * @return Planner
	 */
	private function planner_for( array $assoc_args ): Planner {
		$network = $this->network_target( $assoc_args );
		$planner = $network ? $network->planner : $this->planner;

		if ( ! $planner ) {
			\WP_CLI::error( 'Planned freezes are not available.' );
		}

		return $planner;
	}

	/**
	 * Plans a freeze for later, with a countdown for everyone who is writing before it starts.
	 *
	 * The freeze starts by itself on the first request after its time, with or
	 * without WP-Cron. Repeat it every week with --days.
	 *
	 * ## OPTIONS
	 *
	 * --start=<datetime>
	 * : When it starts, in the site time zone, such as "2026-11-20 02:00". Must be in the future. For a repeating plan this sets the time of day.
	 *
	 * [--minutes=<minutes>]
	 * : Length in minutes. 0 means until someone unfreezes. Default 60, or the axle_read_only_mode_default_duration filter.
	 *
	 * [--reason=<reason>]
	 * : Shown in the countdown and to people who try to save.
	 *
	 * [--scope=<scope>]
	 * : all, content or code. Default all.
	 *
	 * [--days=<days>]
	 * : Repeat every week on these days, comma-separated: sun,mon,tue,wed,thu,fri,sat. Default: freeze once.
	 *
	 * [--lead=<minutes>]
	 * : Minutes of heads-up before it starts. Default 15, or the axle_read_only_mode_heads_up_minutes filter.
	 *
	 * [--email-editors]
	 * : Email people who have a post open when the heads-up starts.
	 *
	 * [--[no-]visitors]
	 * : Pause visitor submissions. On by default.
	 *
	 * [--[no-]cron]
	 * : Pause scheduled tasks. On by default.
	 *
	 * [--id=<id>]
	 * : Change this plan instead of adding one.
	 *
	 * [--network]
	 * : Plan a freeze of the whole network (every site).
	 *
	 * [--sites=<ids>]
	 * : With --network, only these sites. Comma-separated site IDs.
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-read-only-mode plan --start="2026-11-20 02:00" --minutes=45 --reason="Release night"
	 *     wp axle-read-only-mode plan --start="2026-11-22 02:00" --days=sun --minutes=60 --scope=content
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function plan( array $args, array $assoc_args ): void {
		$planner = $this->planner_for( $assoc_args );
		$start   = \AryanX\Axle\ReadOnlyMode\Admin\ToolsPage::parse_start( (string) ( $assoc_args['start'] ?? '' ) );
		$days    = array();
		$names   = array( 'sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat' );

		foreach ( array_filter( array_map( 'trim', explode( ',', strtolower( (string) ( $assoc_args['days'] ?? '' ) ) ) ) ) as $name ) {
			$day = array_search( substr( $name, 0, 3 ), $names, true );
			if ( false === $day ) {
				\WP_CLI::error( sprintf( 'Unknown day "%s". Use sun, mon, tue, wed, thu, fri or sat.', $name ) );
			}
			$days[] = (int) $day;
		}

		$input = array(
			'start'    => $start,
			'minutes'  => isset( $assoc_args['minutes'] ) ? (int) $assoc_args['minutes'] : Freezer::default_duration(),
			'reason'   => (string) ( $assoc_args['reason'] ?? '' ),
			'area'     => $this->scope_arg( $assoc_args ),
			'days'     => $days,
			'lead'     => (int) ( $assoc_args['lead'] ?? 0 ),
			'email'    => \WP_CLI\Utils\get_flag_value( $assoc_args, 'email-editors', false ),
			'visitors' => \WP_CLI\Utils\get_flag_value( $assoc_args, 'visitors', true ),
			'cron'     => \WP_CLI\Utils\get_flag_value( $assoc_args, 'cron', true ),
		);

		if ( isset( $assoc_args['sites'] ) ) {
			if ( ! $planner->is_network() ) {
				\WP_CLI::error( '--sites needs --network.' );
			}
			$input['scope'] = 'sites';
			$input['sites'] = array_values( array_filter( array_map( 'absint', explode( ',', (string) $assoc_args['sites'] ) ) ) );
		}

		$plan = $planner->save( $input, (string) ( $assoc_args['id'] ?? '' ) );
		if ( is_wp_error( $plan ) ) {
			\WP_CLI::error( $plan->get_error_message() );
		}

		\WP_CLI::success( sprintf( 'Planned freeze %s starts %s (%s).', $plan['id'], wp_date( 'Y-m-d H:i', $plan['start'] ), wp_timezone_string() ) );
	}

	/**
	 * Lists planned freezes.
	 *
	 * ## OPTIONS
	 *
	 * [--network]
	 * : List the network's planned freezes.
	 *
	 * [--format=<format>]
	 * : table, json, csv or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-read-only-mode plans
	 *     wp axle-read-only-mode plans --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function plans( array $args, array $assoc_args ): void {
		$names = array( 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' );
		$rows  = array();

		foreach ( $this->planner_for( $assoc_args )->all() as $plan ) {
			$rows[] = array(
				'id'      => $plan['id'],
				'starts'  => wp_date( 'Y-m-d H:i', $plan['start'] ),
				'minutes' => $plan['minutes'],
				'scope'   => $plan['area'],
				'repeat'  => $plan['days'] ? implode( ',', array_map( static fn( int $day ): string => $names[ $day ], $plan['days'] ) ) : '',
				'reason'  => $plan['reason'],
			);
		}

		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'id', 'starts', 'minutes', 'scope', 'repeat', 'reason' ) );
	}

	/**
	 * Cancels a planned freeze. A weekly plan stops repeating. A freeze it already started keeps running.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The plan's ID, from `wp axle-read-only-mode plans`.
	 *
	 * [--network]
	 * : Cancel a network plan.
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-read-only-mode cancel k3Jx9aQz
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function cancel( array $args, array $assoc_args ): void {
		if ( ! $this->planner_for( $assoc_args )->cancel( (string) ( $args[0] ?? '' ) ) ) {
			\WP_CLI::error( 'There is no planned freeze with that ID.' );
		}

		\WP_CLI::success( 'Planned freeze cancelled.' );
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
			\WP_CLI::line( 'Scope:                     ' . $network->state->scope() );
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
		\WP_CLI::line( 'Scope:                     ' . $this->state->scope() );
		\WP_CLI::line( 'Visitor submissions paused: ' . ( $this->state->get( 'visitors' ) ? 'yes' : 'no' ) );
		\WP_CLI::line( 'Scheduled tasks paused:     ' . ( $this->state->get( 'cron' ) ? 'yes' : 'no' ) );
		\WP_CLI::line( 'Blocked so far:             ' . $this->log->count( $this->state->log_since() ) );
	}

	/**
	 * Adds a request to the allow-list, so it keeps working during a freeze.
	 *
	 * ## OPTIONS
	 *
	 * <type>
	 * : What to allow: rest (a REST route prefix), path (a front-end path or ?query-argument), ajax (an admin-ajax action) or user (a login, email address or ID).
	 * ---
	 * options:
	 *   - rest
	 *   - path
	 *   - ajax
	 *   - user
	 * ---
	 *
	 * <value>
	 * : The entry, such as /wc/v3/webhooks, ?wc-api=, my_action or editor-in-chief.
	 *
	 * [--network]
	 * : Change the network's allow-list, which applies during a network freeze.
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-read-only-mode allow rest /wc/v3/webhooks
	 *     wp axle-read-only-mode allow path "?wc-api="
	 *     wp axle-read-only-mode allow ajax subscribe_form
	 *     wp axle-read-only-mode allow user ops@example.com
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function allow( array $args, array $assoc_args ): void {
		$error = $this->settings_for( $assoc_args )->add_allow( self::kind( $args[0] ), (string) $args[1] );
		if ( '' !== $error ) {
			\WP_CLI::error( $error );
		}

		\WP_CLI::success( 'Added to the allow-list.' );
	}

	/**
	 * Takes a request off the allow-list.
	 *
	 * ## OPTIONS
	 *
	 * <type>
	 * : rest, path, ajax or user.
	 *
	 * <value>
	 * : The entry, as it was added.
	 *
	 * [--network]
	 * : Change the network's allow-list.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function disallow( array $args, array $assoc_args ): void {
		if ( ! $this->settings_for( $assoc_args )->remove_allow( self::kind( $args[0] ), (string) $args[1] ) ) {
			\WP_CLI::error( 'That entry is not on the allow-list.' );
		}

		\WP_CLI::success( 'Removed from the allow-list.' );
	}

	/**
	 * Lists what keeps working during a freeze.
	 *
	 * ## OPTIONS
	 *
	 * [--network]
	 * : List the network's allow-list.
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
	public function allowed( array $args, array $assoc_args ): void {
		$settings = $this->settings_for( $assoc_args );
		$routes   = array_keys( rest_get_server()->get_routes() );
		$names    = array_flip( self::KINDS );
		$rows     = array();

		foreach ( AllowList::TYPES as $type ) {
			foreach ( $settings->allow( $type ) as $entry ) {
				$user   = 'users' === $type ? get_userdata( (int) $entry ) : false;
				$rows[] = array(
					'type'    => $names[ $type ],
					'entry'   => $user ? $user->user_login : (string) $entry,
					'matches' => AllowList::describe( $type, $entry, $routes )['label'],
				);
			}
		}

		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'type', 'entry', 'matches' ) );
	}

	/**
	 * Shows or changes the emails sent when a freeze starts or ends.
	 *
	 * With no options, shows the current settings.
	 *
	 * ## OPTIONS
	 *
	 * [--enable]
	 * : Turn the emails on.
	 *
	 * [--disable]
	 * : Turn the emails off.
	 *
	 * [--to=<addresses>]
	 * : Who gets them. Comma-separated email addresses. An empty value goes back to the admin email.
	 *
	 * [--events=<events>]
	 * : Which events send one. Comma-separated: started, ended, expired, extended, scheduled_start.
	 *
	 * [--network]
	 * : Change the emails for network freezes.
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-read-only-mode notify --enable --to=ops@example.com --events=started,ended,expired
	 *     wp axle-read-only-mode notify --disable
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function notify( array $args, array $assoc_args ): void {
		$settings = $this->settings_for( $assoc_args );
		$current  = $settings->notify();

		if ( isset( $assoc_args['enable'], $assoc_args['disable'] ) ) {
			\WP_CLI::error( 'Use --enable or --disable, not both.' );
		}

		if ( isset( $assoc_args['enable'] ) || isset( $assoc_args['disable'] ) || isset( $assoc_args['to'] ) || isset( $assoc_args['events'] ) ) {
			$events = isset( $assoc_args['events'] ) ? array_filter( array_map( 'trim', explode( ',', (string) $assoc_args['events'] ) ) ) : $current['events'];
			foreach ( array_diff( $events, Notifier::EVENTS ) as $unknown ) {
				\WP_CLI::error( sprintf( 'Unknown event "%s". Choose from: %s.', $unknown, implode( ', ', Notifier::EVENTS ) ) );
			}

			$rejected = $settings->save_notify(
				array(
					'enabled'    => isset( $assoc_args['enable'] ) || ( ! isset( $assoc_args['disable'] ) && $current['enabled'] ),
					'recipients' => isset( $assoc_args['to'] ) ? (string) $assoc_args['to'] : implode( ',', $current['recipients'] ),
					'events'     => $events,
				)
			);
			foreach ( $rejected as $bad ) {
				\WP_CLI::warning( sprintf( '"%s" is not an email address and was left out.', $bad['line'] ) );
			}
			$current = $settings->notify();
		}

		\WP_CLI::line( 'Emails:     ' . ( $current['enabled'] ? 'on' : 'off' ) );
		\WP_CLI::line( 'Recipients: ' . ( $current['recipients'] ? implode( ', ', $current['recipients'] ) : 'the admin email' ) );
		\WP_CLI::line( 'Events:     ' . ( $current['events'] ? implode( ', ', $current['events'] ) : 'none' ) );
	}

	/**
	 * Runs the Site Health check for read-only mode.
	 *
	 * Exits with an error when the check is critical, so a monitor can alert on it.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table or json.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-read-only-mode health
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function health( array $args, array $assoc_args ): void {
		if ( ! $this->health ) {
			\WP_CLI::error( 'The Site Health check is not available.' );
		}

		$checked = $this->health->evaluate();

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			\WP_CLI::line( (string) wp_json_encode( $checked ) );
		} else {
			\WP_CLI::line( strtoupper( $checked['status'] ) . ': ' . $checked['label'] );
			\WP_CLI::line( $checked['description'] );
		}

		if ( 'critical' === $checked['status'] ) {
			\WP_CLI::halt( 2 );
		}
	}

	/**
	 * Settings the command changes: the site's, or with --network the network's.
	 *
	 * @param array $assoc_args Named arguments.
	 * @return Settings
	 */
	private function settings_for( array $assoc_args ): Settings {
		$network = $this->network_target( $assoc_args );

		return $network ? $network->settings : $this->settings;
	}

	/**
	 * The allow-list kind for a command word.
	 *
	 * @param string $word rest, path, ajax or user.
	 * @return string rest, paths, ajax or users.
	 */
	private static function kind( string $word ): string {
		if ( ! isset( self::KINDS[ $word ] ) ) {
			\WP_CLI::error( 'The type is rest, path, ajax or user.' );
		}

		return self::KINDS[ $word ];
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
		$what  = Scopes::ALL === $network->state->scope() ? '' : ' (' . strtolower( Scopes::label( $network->state->scope() ) ) . ')';

		return sprintf( 'The network is frozen%s%s for %s.%s', $what, $until, $where, $why );
	}

	/**
	 * Lists what was blocked, and what the allow-list let through, during the current or last freeze.
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
		$columns = $network ? array( 'time', 'site', 'who', 'source', 'area', 'result', 'where' ) : array( 'time', 'who', 'source', 'area', 'result', 'where' );

		$rows = array_map(
			static function ( array $entry ): array {
				return array(
					'time'   => gmdate( 'Y-m-d H:i:s', (int) $entry['time'] ),
					'who'    => BlockedLog::who( $entry ),
					'site'   => $entry['site_id'] ?? get_current_blog_id(),
					'area'   => $entry['area'] ?? '',
					'result' => empty( $entry['allowed'] ) ? 'blocked' : 'allowed',
				) + $entry;
			},
			$entries
		);

		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, $columns );
	}
}
