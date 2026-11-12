<?php
/**
 * Planned freezes.
 *
 * @package AryanX\Axle\ReadOnlyMode
 */

namespace AryanX\Axle\ReadOnlyMode;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the list of planned freezes and starts each one when its time comes.
 *
 * WP-Cron is not used. `tick()` runs on every request, reads one small
 * autoloaded option (already in memory) and returns at once when no plan is
 * due, so a plan starts on the first request after its time, cron or not.
 *
 * A plan is a one-off freeze, or a weekly one that repeats on chosen days.
 * One Planner works on one list: the site's, or the network's.
 */
final class Planner implements Service {

	public const OPTION         = 'axle_read_only_mode_plans';
	public const NETWORK_OPTION = 'axle_read_only_mode_network_plans';
	public const FINDINGS       = 'axle_read_only_mode_start_findings';

	private const DEFAULTS = array(
		'id'              => '',
		'start'           => 0,
		'minutes'         => 60,
		'area'            => 'all',
		'reason'          => '',
		'visitor_message' => '',
		'visitors'        => true,
		'cron'            => true,
		'days'            => array(),
		'lead'            => 0,
		'email'           => false,
		'exempt'          => array(),
		'by'              => 0,
		'last'            => 0,
		'scope'           => 'all',
		'sites'           => array(),
	);

	/**
	 * Where the plans are saved.
	 *
	 * @var Store
	 */
	private Store $store;

	/**
	 * Starts the freezes.
	 *
	 * @var Freezer
	 */
	private Freezer $freezer;

	/**
	 * The freeze these plans start.
	 *
	 * @var State
	 */
	private State $state;

	/**
	 * Sets up the planner.
	 *
	 * @param Store   $store   Where the plans are saved.
	 * @param Freezer $freezer Starts the freezes.
	 * @param State   $state   The freeze these plans start.
	 */
	public function __construct( Store $store, Freezer $freezer, State $state ) {
		$this->store   = $store;
		$this->freezer = $freezer;
		$this->state   = $state;
	}

	/**
	 * A planner for this site.
	 *
	 * @param Freezer $freezer Starts the freezes.
	 * @param State   $state   The site's freeze.
	 * @return Planner
	 */
	public static function for_site( Freezer $freezer, State $state ): Planner {
		return new self( new Store( self::OPTION ), $freezer, $state );
	}

	/**
	 * A planner for the network.
	 *
	 * @param Freezer $freezer Starts the network's freezes.
	 * @param State   $state   The network's freeze.
	 * @return Planner
	 */
	public static function for_network( Freezer $freezer, State $state ): Planner {
		return new self( new Store( self::NETWORK_OPTION, true ), $freezer, $state );
	}

	/**
	 * Adds the personal data eraser.
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
	}

	/**
	 * Registers the eraser for this list.
	 *
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public function add_eraser( $erasers ): array {
		$erasers = (array) $erasers;

		$erasers[ $this->is_network() ? 'axle-read-only-mode-network-plans' : 'axle-read-only-mode-plans' ] = array(
			'eraser_friendly_name' => $this->is_network() ? __( 'Axle Read-Only Mode network planned freezes', 'axle-read-only-mode' ) : __( 'Axle Read-Only Mode planned freezes', 'axle-read-only-mode' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Personal data eraser: takes the user out of the plans, which stay.
	 *
	 * @param string $email Email address.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( $email ): array {
		$user    = get_user_by( 'email', (string) $email );
		$plans   = $this->all();
		$removed = false;

		if ( $user ) {
			foreach ( $plans as $id => $plan ) {
				if ( (int) $plan['by'] === (int) $user->ID || in_array( (int) $user->ID, $plan['exempt'], true ) ) {
					$plans[ $id ]['by']     = BlockedLog::ERASED;
					$plans[ $id ]['exempt'] = array_values( array_diff( $plan['exempt'], array( (int) $user->ID ) ) );
					$removed                = true;
				}
			}
			if ( $removed ) {
				$this->store->set( $plans );
			}
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Whether this is the network's list.
	 *
	 * @return bool
	 */
	public function is_network(): bool {
		return $this->store->is_network();
	}

	/**
	 * Every plan, the soonest first.
	 *
	 * @return array<string, array> ID => plan.
	 */
	public function all(): array {
		$saved = $this->store->get( array() );
		$plans = array();

		foreach ( is_array( $saved ) ? $saved : array() as $plan ) {
			if ( is_array( $plan ) && ! empty( $plan['id'] ) ) {
				$plans[ (string) $plan['id'] ] = array_merge( self::DEFAULTS, array_intersect_key( $plan, self::DEFAULTS ) );
			}
		}

		uasort(
			$plans,
			static function ( array $a, array $b ): int {
				return (int) $a['start'] <=> (int) $b['start'];
			}
		);

		return $plans;
	}

	/**
	 * One plan.
	 *
	 * @param string $id Plan ID.
	 * @return array|null
	 */
	public function get( string $id ): ?array {
		return $this->all()[ $id ] ?? null;
	}

	/**
	 * Most plans a list holds.
	 *
	 * @return int
	 */
	public static function limit(): int {
		/**
		 * Filters how many planned freezes the list can hold.
		 *
		 * @param int $limit Default 50.
		 */
		return max( 1, (int) apply_filters( 'axle_read_only_mode_plans_limit', 50 ) );
	}

	/**
	 * Minutes before the start that the heads-up begins, when a plan sets none.
	 *
	 * @param array $plan The plan, or an empty array for a freeze that was scheduled without a plan.
	 * @return int Minutes, 1 to 1440.
	 */
	public static function lead_minutes( array $plan = array() ): int {
		$minutes = ! empty( $plan['lead'] ) ? (int) $plan['lead'] : 15;

		/**
		 * Filters how many minutes before a planned freeze the heads-up starts.
		 *
		 * @param int   $minutes Minutes. Default 15, or the lead set on the plan.
		 * @param array $plan    The plan. Empty for a freeze scheduled from the form or WP-CLI `on`.
		 */
		return min( DAY_IN_SECONDS / MINUTE_IN_SECONDS, max( 1, (int) apply_filters( 'axle_read_only_mode_heads_up_minutes', $minutes, $plan ) ) );
	}

	/**
	 * Cleans the values of a plan.
	 *
	 * @param array $args {
	 *     Plan values.
	 *
	 *     @type int    $start           Unix time the first run starts. Must be in the future.
	 *     @type int    $minutes         Length in minutes, 0 for no end.
	 *     @type string $area            all, content or code.
	 *     @type string $reason          Shown to people who are blocked.
	 *     @type string $visitor_message Shown to visitors whose submission is blocked.
	 *     @type bool   $visitors        Pause visitor submissions.
	 *     @type bool   $cron            Pause scheduled tasks.
	 *     @type int[]  $days            Weekdays it repeats on, 0 (Sunday) to 6. Empty for a one-off.
	 *     @type int    $lead            Minutes of heads-up. 0 for the default.
	 *     @type bool   $email           Email people with a post open when the heads-up starts.
	 *     @type int[]  $exempt          Users who keep working.
	 *     @type string $scope           Network plans: all, or sites.
	 *     @type int[]  $sites           Network plans: the sites.
	 * }
	 * @return array<string, mixed>
	 */
	private function clean( array $args ): array {
		$days = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $args['days'] ?? array() ) ), static fn( int $day ): bool => $day >= 0 && $day <= 6 ) ) );
		sort( $days );

		$plan = array(
			'start'           => (int) ( $args['start'] ?? 0 ),
			'minutes'         => isset( $args['minutes'] ) ? max( 0, (int) $args['minutes'] ) : Freezer::default_duration(),
			'area'            => isset( $args['area'] ) ? Scopes::normalize( (string) $args['area'] ) : Scopes::default_scope(),
			'reason'          => sanitize_text_field( (string) ( $args['reason'] ?? '' ) ),
			'visitor_message' => sanitize_text_field( (string) ( $args['visitor_message'] ?? '' ) ),
			'visitors'        => ! isset( $args['visitors'] ) || (bool) $args['visitors'],
			'cron'            => ! isset( $args['cron'] ) || (bool) $args['cron'],
			'days'            => $days,
			'lead'            => min( 1440, max( 0, (int) ( $args['lead'] ?? 0 ) ) ),
			'email'           => ! empty( $args['email'] ),
			'exempt'          => array_values( array_unique( array_filter( array_map( 'intval', (array) ( $args['exempt'] ?? array() ) ) ) ) ),
			'scope'           => 'all',
			'sites'           => array(),
		);

		if ( $this->is_network() ) {
			$sites         = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $args['sites'] ?? array() ) ) ) ) );
			$plan['scope'] = 'sites' === ( $args['scope'] ?? 'all' ) && $sites ? 'sites' : 'all';
			$plan['sites'] = 'sites' === $plan['scope'] ? $sites : array();
		}

		return $plan;
	}

	/**
	 * Adds a plan, or changes one.
	 *
	 * @param array  $args See clean().
	 * @param string $id   ID of the plan to change, empty to add one.
	 * @return array|\WP_Error The saved plan.
	 */
	public function save( array $args, string $id = '' ) {
		$plan  = $this->clean( $args );
		$plans = $this->all();

		if ( $plan['start'] <= time() ) {
			return new \WP_Error( 'axle_read_only_mode_plan_past', __( 'A planned freeze needs a start time in the future.', 'axle-read-only-mode' ) );
		}

		if ( '' !== $id && ! isset( $plans[ $id ] ) ) {
			return new \WP_Error( 'axle_read_only_mode_plan_missing', __( 'That planned freeze does not exist any more.', 'axle-read-only-mode' ) );
		}

		if ( '' === $id && count( $plans ) >= self::limit() ) {
			return new \WP_Error( 'axle_read_only_mode_plan_limit', __( 'There are too many planned freezes. Cancel one first.', 'axle-read-only-mode' ) );
		}

		$existing = '' !== $id ? $plans[ $id ] : array();
		$plan     = array_merge(
			self::DEFAULTS,
			$plan,
			array(
				'id'   => '' !== $id ? $id : strtolower( wp_generate_password( 8, false ) ),
				'by'   => '' !== $id ? (int) $existing['by'] : get_current_user_id(),
				'last' => '' !== $id ? (int) $existing['last'] : 0,
			)
		);

		$plans[ $plan['id'] ] = $plan;
		$this->store->set( $plans );

		if ( '' !== $id ) {
			/**
			 * Fires when a planned freeze is changed.
			 *
			 * @param array $plan    The plan after the change.
			 * @param array $before  The plan before it.
			 * @param bool  $network Whether it is the network's plan.
			 */
			do_action( 'axle_read_only_mode_plan_updated', $plan, $existing, $this->is_network() );

			return $plan;
		}

		/**
		 * Fires when a freeze is planned.
		 *
		 * @param array $plan    The plan: id, start, minutes, area, reason, visitors, cron, days, lead, email, exempt and by.
		 * @param bool  $network Whether it is the network's plan.
		 */
		do_action( 'axle_read_only_mode_planned', $plan, $this->is_network() );

		return $plan;
	}

	/**
	 * Cancels a plan. A plan that repeats stops repeating. A freeze it already started is left alone.
	 *
	 * @param string $id Plan ID.
	 * @return array|null The plan that was cancelled, null when there was none.
	 */
	public function cancel( string $id ): ?array {
		$plans = $this->all();
		if ( ! isset( $plans[ $id ] ) ) {
			return null;
		}

		$plan = $plans[ $id ];
		unset( $plans[ $id ] );
		$this->store->set( $plans );

		/**
		 * Fires when a planned freeze is cancelled.
		 *
		 * @param array $plan    The plan that was cancelled.
		 * @param bool  $network Whether it is the network's plan.
		 */
		do_action( 'axle_read_only_mode_plan_cancelled', $plan, $this->is_network() );

		return $plan;
	}

	/**
	 * The first start after a time, for a plan that repeats.
	 *
	 * Keeps the time of day of the plan's start, in the site's time zone, so a
	 * 02:00 deploy window stays at 02:00 across a daylight saving change.
	 *
	 * @param int   $start Start of the plan, for the time of day.
	 * @param int[] $days  Weekdays, 0 (Sunday) to 6.
	 * @param int   $after Only starts after this time count.
	 * @return int Unix time, 0 when there are no days.
	 */
	public static function next_start( int $start, array $days, int $after ): int {
		$days = array_map( 'intval', $days );
		if ( ! $days ) {
			return 0;
		}

		$zone  = wp_timezone();
		$clock = ( new \DateTimeImmutable( '@' . $start ) )->setTimezone( $zone )->format( 'H:i:s' );
		$day   = ( new \DateTimeImmutable( '@' . $after ) )->setTimezone( $zone )->setTime( 0, 0 );

		for ( $i = 0; $i <= 8; $i++ ) {
			$date      = $day->modify( '+' . $i . ' days' );
			$candidate = new \DateTimeImmutable( $date->format( 'Y-m-d' ) . ' ' . $clock, $zone );

			if ( $candidate->getTimestamp() > $after && in_array( (int) $candidate->format( 'w' ), $days, true ) ) {
				return $candidate->getTimestamp();
			}
		}

		return 0;
	}

	/**
	 * The plan that starts next, with the heads-up window it falls in.
	 *
	 * @return array|null A plan, or null when none is waiting.
	 */
	public function upcoming(): ?array {
		foreach ( $this->all() as $plan ) {
			if ( $plan['start'] > time() ) {
				return $plan;
			}
		}

		return null;
	}

	/**
	 * Starts every plan whose time has come, and sends the heads-up emails. Runs on every request.
	 */
	public function tick(): void {
		$plans = $this->all();
		if ( ! $plans ) {
			return;
		}

		$now     = time();
		$changed = false;

		foreach ( $plans as $id => $plan ) {
			if ( $plan['email'] && $plan['start'] > $now && $now >= $plan['start'] - self::lead_minutes( $plan ) * MINUTE_IN_SECONDS ) {
				$this->email_editors( $plan );
			}

			if ( $plan['start'] > $now || $plan['start'] <= $plan['last'] ) {
				continue;
			}

			// Many requests can notice the time at once. Only one claims the run.
			if ( ! $this->claim( 'run_' . $id . '_' . $plan['start'] ) ) {
				continue;
			}

			$this->run( $plan, $now );

			$this->forget( 'run_' . $id . '_' . $plan['last'] );
			$this->forget( 'mail_' . $id . '_' . $plan['start'] );

			$changed              = true;
			$plans[ $id ]['last'] = $plan['start'];
			$next                 = self::next_start( $plan['start'], $plan['days'], $now );

			if ( $next ) {
				$plans[ $id ]['start'] = $next;
			} else {
				unset( $plans[ $id ] );
			}
		}

		if ( $changed ) {
			$this->store->set( $plans );
		}
	}

	/**
	 * Starts one plan that is due, unless it was missed or a freeze is already running.
	 *
	 * @param array $plan Due plan.
	 * @param int   $now  Current time.
	 */
	private function run( array $plan, int $now ): void {
		$network = $this->is_network();
		$end     = $plan['minutes'] ? $plan['start'] + $plan['minutes'] * MINUTE_IN_SECONDS : 0;

		if ( $end && $end <= $now ) {
			/**
			 * Fires when a planned freeze is passed over: its whole window went by
			 * before any request noticed, or a freeze was already running.
			 *
			 * @param array  $plan    The plan.
			 * @param string $reason  missed or busy.
			 * @param bool   $network Whether it is the network's plan.
			 */
			do_action( 'axle_read_only_mode_plan_skipped', $plan, 'missed', $network );
			return;
		}

		$own = $this->state->own();
		if ( $own['active'] && ( $this->state->is_running() || $this->state->is_scheduled() ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Documented above, in the branch for a missed plan.
			do_action( 'axle_read_only_mode_plan_skipped', $plan, 'busy', $network );
			return;
		}

		$context = array(
			'start'    => $plan['start'],
			'minutes'  => $plan['minutes'],
			'scope'    => $plan['area'],
			'cron'     => $plan['cron'],
			'visitors' => $plan['visitors'],
		);

		// The check before freezing runs again now. Its findings go in the start email.
		if ( ! $network ) {
			$result = ( new Checks() )->run( $context );
			update_option( self::FINDINGS, array( 'start' => $plan['start'], 'lines' => self::finding_lines( $result ) ), false ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		}

		$this->freezer->freeze(
			array(
				'start'           => $plan['start'],
				'minutes'         => $plan['minutes'],
				'area'            => $plan['area'],
				'reason'          => $plan['reason'],
				'visitor_message' => $plan['visitor_message'],
				'visitors'        => $plan['visitors'],
				'cron'            => $plan['cron'],
				'exempt'          => $plan['exempt'],
				'scope'           => $plan['scope'],
				'sites'           => $plan['sites'],
			)
		);
		// Whoever planned it started it, not the visitor whose request happened to notice.
		$this->state->update( array( 'by' => max( 0, (int) $plan['by'] ) ) );

		/**
		 * Fires when a planned freeze starts.
		 *
		 * @param array $plan    The plan.
		 * @param bool  $network Whether it is the network's plan.
		 */
		do_action( 'axle_read_only_mode_plan_started', $plan, $network );
	}

	/**
	 * The check results that need a look, as short lines for the start email.
	 *
	 * @param array $result Result of Checks::run().
	 * @return string[]
	 */
	private static function finding_lines( array $result ): array {
		$lines = array();

		foreach ( (array) $result['items'] as $item ) {
			if ( ! in_array( $item['tone'], array( 'warning', 'error' ), true ) ) {
				continue;
			}

			$lines[] = '- ' . $item['title'] . ( '' !== $item['detail'] ? ': ' . $item['detail'] : '' );
			foreach ( array_slice( (array) $item['rows'], 0, 5 ) as $row ) {
				$lines[] = '  ' . $row;
			}
		}

		return $lines;
	}

	/**
	 * What the check found when the freeze with this start time began.
	 *
	 * @param int $start Start time of the freeze.
	 * @return string[] Lines, empty when the check found nothing or was not run.
	 */
	public static function findings( int $start ): array {
		$saved = get_option( self::FINDINGS, array() );

		return is_array( $saved ) && (int) ( $saved['start'] ?? 0 ) === $start ? array_map( 'strval', (array) $saved['lines'] ) : array();
	}

	/**
	 * Emails the people who have a post open, once, when the heads-up starts.
	 *
	 * @param array $plan The plan.
	 */
	private function email_editors( array $plan ): void {
		if ( $this->is_network() || ! Scopes::covers( $plan['area'], Scopes::CONTENT ) || ! $this->claim( 'mail_' . $plan['id'] . '_' . $plan['start'] ) ) {
			return;
		}

		$sent  = array();
		$until = $plan['minutes'] ? wp_date( get_option( 'time_format' ), $plan['start'] + $plan['minutes'] * MINUTE_IN_SECONDS ) : '';

		foreach ( ( new Checks() )->editors() as $editor ) {
			$user = get_userdata( (int) $editor['user_id'] );
			if ( ! $user || in_array( (int) $user->ID, $plan['exempt'], true ) || in_array( $user->user_email, $sent, true ) || ! is_email( $user->user_email ) ) {
				continue;
			}
			$sent[] = $user->user_email;
		}

		/**
		 * Filters who gets the heads-up email: the people with a post open when it starts.
		 *
		 * @param string[] $emails Email addresses.
		 * @param array    $plan   The plan.
		 */
		$sent = array_values( array_filter( array_map( 'strval', (array) apply_filters( 'axle_read_only_mode_heads_up_recipients', $sent, $plan ) ), 'is_email' ) );
		if ( ! $sent ) {
			return;
		}

		$minutes = max( 1, (int) ceil( ( $plan['start'] - time() ) / MINUTE_IN_SECONDS ) );
		/* translators: %s: site name. */
		$subject = sprintf( __( '[%s] The site goes read-only soon', 'axle-read-only-mode' ), wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ) );
		$lines   = array(
			sprintf(
				/* translators: %d: minutes until the freeze starts. */
				_n( 'The site goes read-only in %d minute. Save your work.', 'The site goes read-only in %d minutes. Save your work.', $minutes, 'axle-read-only-mode' ),
				$minutes
			),
			sprintf(
				/* translators: 1: time the freeze starts, 2: site time zone. */
				__( 'It starts at %1$s (%2$s).', 'axle-read-only-mode' ),
				wp_date( get_option( 'time_format' ), $plan['start'] ),
				wp_timezone_string()
			),
		);
		if ( '' !== $until ) {
			/* translators: %s: time the freeze ends. */
			$lines[] = sprintf( __( 'It is expected to end at %s.', 'axle-read-only-mode' ), $until );
		}
		if ( '' !== $plan['reason'] ) {
			/* translators: %s: reason for the freeze. */
			$lines[] = sprintf( __( 'Reason: %s', 'axle-read-only-mode' ), $plan['reason'] );
		}
		$lines[] = __( 'Anything you change after it starts waits in your browser if you are in the block editor, and is saved when the freeze ends.', 'axle-read-only-mode' );

		/**
		 * Filters the subject of the heads-up email.
		 *
		 * @param string $subject Subject.
		 * @param array  $plan    The plan.
		 */
		$subject = (string) apply_filters( 'axle_read_only_mode_heads_up_subject', $subject, $plan );

		/**
		 * Filters the body of the heads-up email, plain text.
		 *
		 * @param string $body Body.
		 * @param array  $plan The plan.
		 */
		$body = (string) apply_filters( 'axle_read_only_mode_heads_up_body', implode( "\n", $lines ) . "\n", $plan );

		foreach ( $sent as $email ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- One notice to each person who has a post open, not bulk mail.
			wp_mail( $email, $subject, $body );
		}

		/**
		 * Fires after the heads-up email was sent.
		 *
		 * @param string[] $sent The addresses it went to.
		 * @param array    $plan The plan.
		 */
		do_action( 'axle_read_only_mode_heads_up_emailed', $sent, $plan );
	}

	/**
	 * Claims a one-time marker. Only one of many simultaneous requests gets it.
	 *
	 * @param string $name Marker name.
	 * @return bool Whether this call set it.
	 */
	private function claim( string $name ): bool {
		$key = 'axle_read_only_mode_' . ( $this->is_network() ? 'network_' : '' ) . 'plan_' . $name;

		return $this->is_network() ? (bool) add_site_option( $key, time() ) : (bool) add_option( $key, time(), '', false );
	}

	/**
	 * Removes a one-time marker.
	 *
	 * @param string $name Marker name.
	 */
	private function forget( string $name ): void {
		$key = 'axle_read_only_mode_' . ( $this->is_network() ? 'network_' : '' ) . 'plan_' . $name;

		if ( $this->is_network() ) {
			delete_site_option( $key );
			return;
		}

		delete_option( $key );
	}
}
