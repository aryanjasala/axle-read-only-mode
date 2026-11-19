<?php
/**
 * Freeze until green: a freeze that ends when the smoke test passes.
 *
 * @package AryanX\Axle\ReadOnlyMode
 */

namespace AryanX\Axle\ReadOnlyMode;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to Axle Smoke Test through two hooks and nothing else.
 *
 * - `axle_smoke_test_run_now( $source )` asks it to queue a full run.
 * - `axle_smoke_test_run_finished( $run )` tells us a run is done: `id`,
 *   `verdict` (green, amber, red), `source` and `finished`.
 *
 * Both are guarded, so nothing here breaks when Smoke Test is not active. A
 * freeze that waits for the smoke test:
 *
 * - asks for a run when it starts (or when a planned one starts);
 * - ends on the next counted run that is green;
 * - stays frozen on a run that is not green, says why, and lets Smoke Test
 *   roll an update back, which a freeze would otherwise refuse;
 * - ends at a hard limit (two hours by default) with a warning, so a stuck run
 *   cannot freeze the site forever.
 */
final class Green implements Service {

	public const SOURCE        = 'axle-read-only-mode';
	public const DEADLINE_HOOK = 'axle_read_only_mode_green_deadline';
	public const DEFAULT_MAX   = 120;

	/**
	 * Smoke Test's REST routes that a run needs while it waits: the runner's own calls.
	 */
	private const RUNNER_ROUTE = '#^/axle-smoke-test/v1/runs(/next|/\d+/(start|browser|results|complete))?$#';

	/**
	 * Smoke Test's rollback route.
	 */
	private const ROLLBACK_ROUTE = '#^/axle-smoke-test/v1/runs/\d+/rollback$#';

	/**
	 * Whether the request now running was let through to roll an update back.
	 *
	 * @var bool
	 */
	private static bool $rolling_back = false;

	/**
	 * Saved freeze.
	 *
	 * @var State
	 */
	private State $state;

	/**
	 * Starts and ends freezes.
	 *
	 * @var Freezer
	 */
	private Freezer $freezer;

	/**
	 * Sets up the service.
	 *
	 * @param State   $state   Saved freeze.
	 * @param Freezer $freezer Freeze controls.
	 */
	public function __construct( State $state, Freezer $freezer ) {
		$this->state   = $state;
		$this->freezer = $freezer;
	}

	/**
	 * Listens to the freeze and to Smoke Test.
	 */
	public function register(): void {
		add_action( 'axle_read_only_mode_started', array( $this, 'on_started' ), 20 );
		add_action( 'axle_read_only_mode_scheduled_started', array( $this, 'on_started' ), 20 );
		add_action( 'axle_smoke_test_run_finished', array( $this, 'on_run_finished' ), 10, 1 );
		add_action( self::DEADLINE_HOOK, array( $this->freezer, 'end_green_wait_if_needed' ) );

		// The freeze must not stop the smoke test from running or from rolling back.
		add_filter( 'axle_read_only_mode_allow_rest_request', array( $this, 'allow_rest' ), 10, 2 );
		add_filter( 'axle_read_only_mode_allow_request', array( $this, 'allow_admin_post' ), 10, 3 );
		add_filter( 'axle_read_only_mode_allow_ability', array( $this, 'allow_ability' ), 10, 2 );
		add_filter( 'axle_read_only_mode_is_frozen', array( $this, 'open_for_rollback' ) );
		add_filter( 'axle_read_only_mode_scope', array( $this, 'content_only_for_rollback' ) );
		add_filter( 'pre_get_ready_cron_jobs', array( $this, 'keep_smoke_test_cron' ), 20 );
	}

	/**
	 * Whether Axle Smoke Test is active and listening.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		$available = (bool) has_action( 'axle_smoke_test_run_now' );

		/**
		 * Filters whether "Freeze until green" can be used.
		 *
		 * Default: whether something listens to `axle_smoke_test_run_now`, which Axle Smoke Test does while it is active.
		 *
		 * @param bool $available Whether a smoke test can be asked for.
		 */
		return (bool) apply_filters( 'axle_read_only_mode_green_available', $available );
	}

	/**
	 * The longest a freeze waits for the smoke test.
	 *
	 * @return int Minutes, between 5 and 1440.
	 */
	public static function max_minutes(): int {
		/**
		 * Filters the longest a freeze waits for a green smoke test, in minutes.
		 *
		 * When it passes, the freeze ends with a warning, so a stuck run cannot freeze the site forever.
		 *
		 * @param int $minutes Default 120. Clamped between 5 and 1440.
		 */
		return max( 5, min( 1440, (int) apply_filters( 'axle_read_only_mode_green_max_minutes', self::DEFAULT_MAX ) ) );
	}

	/**
	 * Verdicts that end the freeze.
	 *
	 * @return string[]
	 */
	public static function passing_verdicts(): array {
		/**
		 * Filters the smoke test verdicts that end a "Freeze until green" freeze.
		 *
		 * @param string[] $verdicts Default green. Add amber to accept runs with warnings.
		 */
		$verdicts = array_values( array_intersect( array( 'green', 'amber' ), array_map( 'sanitize_key', (array) apply_filters( 'axle_read_only_mode_green_verdicts', array( 'green' ) ) ) ) );

		return $verdicts ? $verdicts : array( 'green' );
	}

	/**
	 * Address of Smoke Test's screen, on a run when one is given.
	 *
	 * @param int $run Run ID, 0 for the screen itself.
	 * @return string
	 */
	public static function smoke_test_url( int $run = 0 ): string {
		return admin_url( 'tools.php?page=axle-smoke-test' . ( $run > 0 ? '&run=' . $run : '' ) );
	}

	/**
	 * Plans the one WP-Cron event that checks the hard limit on a quiet site.
	 *
	 * @param int $deadline Unix time of the limit, 0 to remove it.
	 */
	public static function schedule_deadline( int $deadline ): void {
		wp_clear_scheduled_hook( self::DEADLINE_HOOK );
		if ( $deadline > 0 && function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( $deadline + 1, self::DEADLINE_HOOK );
		}
	}

	/**
	 * A freeze started: ask Smoke Test for a run and plan the hard limit.
	 *
	 * @param array $freeze The freeze.
	 */
	public function on_started( $freeze ): void {
		$freeze = (array) $freeze;
		if ( empty( $freeze['until_green'] ) || (int) ( $freeze['start'] ?? 0 ) > time() ) {
			return;
		}

		self::schedule_deadline( (int) ( $freeze['green_deadline'] ?? 0 ) );
		$this->request_run();
	}

	/**
	 * Asks Smoke Test for a full run, on a freeze that waits for it.
	 *
	 * @return bool Whether a run was asked for.
	 */
	public function request_run(): bool {
		if ( ! $this->state->waits_for_green() || ! self::available() ) {
			return false;
		}

		// Set first: a run that finishes in this same request must count.
		$this->state->update(
			array(
				'green_requested' => time(),
				'green_run'       => array(),
			)
		);

		/**
		 * Fires when a freeze asks Smoke Test for a run, and also when someone asks again from the status card.
		 *
		 * Axle Smoke Test listens and queues a full run. Anything else that runs checks can listen too.
		 *
		 * @param string $source Who asks: axle-read-only-mode.
		 */
		do_action( 'axle_smoke_test_run_now', self::SOURCE ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Axle Smoke Test's hook, documented there.

		/**
		 * Fires after the freeze asked for a smoke test.
		 *
		 * @param array $state The freeze.
		 */
		do_action( 'axle_read_only_mode_green_requested', $this->state->own() );

		return true;
	}

	/**
	 * A smoke test run finished. Ends the freeze on green, keeps it and says why on anything else.
	 *
	 * @param mixed $run The run: id, verdict, source, finished.
	 */
	public function on_run_finished( $run ): void {
		if ( ! $this->state->waits_for_green() || ! is_array( $run ) ) {
			return;
		}

		$own     = $this->state->own();
		$verdict = sanitize_key( (string) ( $run['verdict'] ?? '' ) );
		if ( ! in_array( $verdict, array( 'green', 'amber', 'red' ), true ) ) {
			return;
		}

		$finished = self::time_of( $run['finished'] ?? ( $run['finished_gmt'] ?? 0 ) );
		$counts   = $this->counts( $run, $finished, (int) $own['green_requested'] );

		/**
		 * Filters whether a finished smoke test run counts for a freeze that waits for it.
		 *
		 * Default: the run finished after the freeze asked for one, and it was asked
		 * for by this plugin, or it is the run after a rollback. A run that Smoke Test
		 * started on its own for something else does not end the freeze.
		 *
		 * @param bool  $counts Whether the run counts.
		 * @param array $run    The run: id, verdict, source, finished and the rest Smoke Test sends.
		 * @param array $state  The freeze.
		 */
		if ( ! apply_filters( 'axle_read_only_mode_green_run_counts', $counts, $run, $own ) ) {
			return;
		}

		$record = array(
			'id'       => (int) ( $run['id'] ?? 0 ),
			'verdict'  => $verdict,
			'finished' => $finished ? $finished : time(),
			'source'   => sanitize_key( (string) ( $run['source'] ?? '' ) ),
		);

		if ( in_array( $verdict, self::passing_verdicts(), true ) ) {
			$this->state->update( array( 'green_run' => $record ) );

			/**
			 * Fires when the smoke test passes and the freeze is about to end.
			 *
			 * @param array $state The freeze, with the passing run in green_run.
			 * @param array $run   The run Smoke Test sent.
			 */
			do_action( 'axle_read_only_mode_green_passed', $this->state->own(), $run );

			$this->freezer->unfreeze( 'green' );

			return;
		}

		$this->state->update( array( 'green_run' => $record ) );

		/**
		 * Fires when a smoke test finished and was not green, so the freeze stays.
		 *
		 * @param array $state The freeze, with the run that failed in green_run.
		 * @param array $run   The run Smoke Test sent.
		 */
		do_action( 'axle_read_only_mode_green_failed', $this->state->own(), $run );
	}

	/**
	 * The default rule for which runs count.
	 *
	 * @param array $run       The run.
	 * @param int   $finished  When it finished, 0 if unknown.
	 * @param int   $requested When the freeze asked for a run.
	 * @return bool
	 */
	private function counts( array $run, int $finished, int $requested ): bool {
		if ( $finished && $finished + 2 < $requested ) {
			return false;
		}

		$source  = sanitize_key( (string) ( $run['source'] ?? '' ) );
		$trigger = sanitize_key( (string) ( $run['trigger_type'] ?? ( $run['trigger'] ?? '' ) ) );

		return self::SOURCE === $source || 'rollback' === $trigger || ( '' === $source && '' === $trigger );
	}

	/**
	 * A run's finish time as a Unix time.
	 *
	 * @param mixed $value Unix time, or a GMT date such as "2026-11-20 02:00:00".
	 * @return int 0 when unknown.
	 */
	private static function time_of( $value ): int {
		if ( is_numeric( $value ) ) {
			return max( 0, (int) $value );
		}

		$time = is_string( $value ) && '' !== $value ? strtotime( $value . ' UTC' ) : false;

		return false === $time ? 0 : (int) $time;
	}

	/**
	 * Lets the smoke test's own REST calls through while the freeze waits, and its rollback once a run was not green.
	 *
	 * @param mixed $allow   Whether to let the request through.
	 * @param mixed $request The REST request.
	 * @return bool
	 */
	public function allow_rest( $allow, $request ): bool {
		if ( $allow || ! $this->state->waits_for_green() || ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
			return (bool) $allow;
		}

		$route = (string) $request->get_route();
		if ( preg_match( self::RUNNER_ROUTE, $route ) ) {
			return true;
		}

		if ( preg_match( self::ROLLBACK_ROUTE, $route ) && $this->state->rollback_allowed() ) {
			self::$rolling_back = true;

			return true;
		}

		return false;
	}

	/**
	 * Lets the Smoke Test rollback form through once a run was not green.
	 *
	 * @param mixed  $allow  Whether to let the request through.
	 * @param string $where  What would be blocked.
	 * @param string $source admin, ajax or front-end.
	 * @return bool
	 */
	public function allow_admin_post( $allow, $where = '', $source = '' ): bool {
		unset( $where );
		if ( $allow || 'admin' !== $source || ! $this->state->rollback_allowed() ) {
			return (bool) $allow;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- Only inspected. Smoke Test checks the nonce itself.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( 'axle_smoke_test_rollback' === $action ) {
			self::$rolling_back = true;

			return true;
		}

		return false;
	}

	/**
	 * Lets Smoke Test's rollback ability run once a run was not green.
	 *
	 * @param mixed  $allow Whether the ability may run.
	 * @param string $name  Ability name.
	 * @return bool
	 */
	public function allow_ability( $allow, $name = '' ): bool {
		if ( ! $allow && 'axle-smoke-test/rollback-update' === $name && $this->state->rollback_allowed() ) {
			self::$rolling_back = true;

			return true;
		}

		return (bool) $allow;
	}

	/**
	 * Smoke Test asks "is the site frozen?" before it rolls back. In the one request let through for it, the answer is no.
	 *
	 * @param mixed $frozen Whether the site is frozen.
	 * @return bool
	 */
	public function open_for_rollback( $frozen ): bool {
		return self::$rolling_back ? false : (bool) $frozen;
	}

	/**
	 * Same for a Smoke Test that asks whether code is frozen: in that one request it is not.
	 *
	 * @param mixed $scope The scope in force.
	 * @return string
	 */
	public function content_only_for_rollback( $scope ): string {
		$scope = (string) $scope;

		return self::$rolling_back && Scopes::covers( $scope, Scopes::CODE ) ? Scopes::CONTENT : $scope;
	}

	/**
	 * Keeps Smoke Test's and this plugin's own scheduled events running while the freeze pauses the others.
	 *
	 * Without it a server-tier run, queued on WP-Cron, would never start and the freeze would wait for the hard limit.
	 *
	 * @param mixed $jobs Ready jobs the freeze left, in the shape wp_get_ready_cron_jobs() returns.
	 * @return mixed
	 */
	public function keep_smoke_test_cron( $jobs ) {
		// Not an array: the freeze does not pause scheduled tasks, and WordPress reads them itself.
		if ( ! is_array( $jobs ) || ! $this->state->waits_for_green() || ! function_exists( '_get_cron_array' ) ) {
			return $jobs;
		}

		/**
		 * Filters the prefixes of the scheduled events that keep running while a freeze waits for the smoke test.
		 *
		 * @param string[] $prefixes Default the Smoke Test events and this plugin's own.
		 */
		$prefixes = array_map( 'strval', (array) apply_filters( 'axle_read_only_mode_green_cron_prefixes', array( 'axle_smoke_test_', 'axle_read_only_mode_green_' ) ) );
		$now      = microtime( true );

		foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
			if ( $timestamp > $now ) {
				break;
			}
			foreach ( (array) $hooks as $hook => $events ) {
				foreach ( $prefixes as $prefix ) {
					if ( '' !== $prefix && 0 === strpos( (string) $hook, $prefix ) ) {
						$jobs[ $timestamp ][ $hook ] = $events;
						break;
					}
				}
			}
		}

		return $jobs;
	}
}
