<?php
/**
 * The countdown before a planned freeze starts.
 *
 * @package AryanX\Axle\ReadOnlyMode
 */

namespace AryanX\Axle\ReadOnlyMode;

use const AryanX\Axle\ReadOnlyMode\PLUGIN_FILE;
use const AryanX\Axle\ReadOnlyMode\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Tells people who are writing that a freeze is about to start.
 *
 * From a few minutes before the start, every admin screen shows a notice that
 * counts down, and the block editor shows the same words as an editor notice.
 * Both are kept current through the Heartbeat, so a plan that is cancelled or
 * moved changes the notice within a minute.
 */
final class HeadsUp implements Service {

	/**
	 * Where freezes start: the site's, and on multisite the network's.
	 *
	 * @var array<int, array{state: State, planner: Planner|null}>
	 */
	private array $sources;

	/**
	 * Sets up the heads-up.
	 *
	 * @param array<int, array{state: State, planner: Planner|null}> $sources The site's and the network's freeze, each with its planner.
	 */
	public function __construct( array $sources ) {
		$this->sources = $sources;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'network_admin_notices', array( $this, 'render' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'heartbeat_received', array( $this, 'heartbeat' ), 5 );
	}

	/**
	 * The freeze that starts next and is inside its heads-up window.
	 *
	 * @return array{start: int, seconds: int, area: string, reason: string, message: string, now: int}|null Null when no freeze is close enough, or it does not concern the current user.
	 */
	public function upcoming(): ?array {
		$now  = time();
		$next = null;

		foreach ( $this->sources as $source ) {
			$state    = $source['state'];
			$planner  = $source['planner'];
			$network  = $state->is_network();
			$starting = array();

			$own = $state->own();
			if ( $own['active'] && $own['start'] > $now && ( ! $network || $state->covers( get_current_blog_id() ) ) ) {
				$starting[] = array(
					'start'  => (int) $own['start'],
					'area'   => (string) $own['area'],
					'reason' => (string) $own['reason'],
					'plan'   => array(),
				);
			}

			if ( $planner ) {
				foreach ( $planner->all() as $plan ) {
					if ( $plan['start'] > $now && ( ! $network || 'all' === $plan['scope'] || in_array( get_current_blog_id(), $plan['sites'], true ) ) ) {
						$starting[] = array(
							'start'  => (int) $plan['start'],
							'area'   => (string) $plan['area'],
							'reason' => (string) $plan['reason'],
							'plan'   => $plan,
						);
					}
				}
			}

			foreach ( $starting as $candidate ) {
				$lead = Planner::lead_minutes( $candidate['plan'] ) * MINUTE_IN_SECONDS;
				if ( $candidate['start'] - $now > $lead || ( $next && $next['start'] <= $candidate['start'] ) ) {
					continue;
				}
				if ( $candidate['plan'] && in_array( get_current_user_id(), $candidate['plan']['exempt'], true ) ) {
					continue;
				}
				$next = $candidate;
			}
		}

		// The freeze only matters to people it stops: those who can write content, or change settings and code.
		if ( ! $next || ! $this->concerns_user( $next['area'] ) ) {
			return null;
		}

		$seconds = $next['start'] - $now;

		$upcoming = array(
			'start'   => $next['start'],
			'seconds' => $seconds,
			'area'    => $next['area'],
			'reason'  => $next['reason'],
			'message' => self::message( $next['area'], $seconds ),
			'now'     => $now,
		);

		/**
		 * Filters the heads-up before a freeze starts. Return null to show none.
		 *
		 * @param array|null $upcoming start, seconds, area, reason, message and now.
		 */
		$upcoming = apply_filters( 'axle_read_only_mode_heads_up', $upcoming );

		return is_array( $upcoming ) ? $upcoming : null;
	}

	/**
	 * Whether a freeze of this area stops the current user from doing their work.
	 *
	 * @param string $area all, content or code.
	 * @return bool
	 */
	private function concerns_user( string $area ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		return ( Scopes::covers( $area, Scopes::CONTENT ) && current_user_can( 'edit_posts' ) ) || ( Scopes::covers( $area, Scopes::CODE ) && current_user_can( 'manage_options' ) );
	}

	/**
	 * The words of the countdown.
	 *
	 * @param string $area    all, content or code.
	 * @param int    $seconds Seconds until the start.
	 * @return string
	 */
	public static function message( string $area, int $seconds ): string {
		$time = self::time_left( $seconds );

		if ( Scopes::CONTENT === $area ) {
			/* translators: %s: time left, e.g. "12 minutes". */
			$message = sprintf( __( 'Content goes read-only in %s. Save your work.', 'axle-read-only-mode' ), $time );
		} elseif ( Scopes::CODE === $area ) {
			/* translators: %s: time left, e.g. "12 minutes". */
			$message = sprintf( __( 'Settings and code go read-only in %s. Finish what you are changing.', 'axle-read-only-mode' ), $time );
		} else {
			/* translators: %s: time left, e.g. "12 minutes". */
			$message = sprintf( __( 'The site goes read-only in %s. Save your work.', 'axle-read-only-mode' ), $time );
		}

		/**
		 * Filters the countdown message before a freeze.
		 *
		 * The block editor and the admin notice update it in the browser with
		 * their own copy of this wording, so a filter changes the first line
		 * people see and what the Heartbeat sends, not every tick.
		 *
		 * @param string $message Message.
		 * @param string $area    all, content or code.
		 * @param int    $seconds Seconds until the start.
		 */
		return (string) apply_filters( 'axle_read_only_mode_heads_up_message', $message, $area, $seconds );
	}

	/**
	 * Time left in words: "12 minutes", "1 minute", "less than a minute".
	 *
	 * @param int $seconds Seconds.
	 * @return string
	 */
	private static function time_left( int $seconds ): string {
		if ( $seconds < MINUTE_IN_SECONDS ) {
			return __( 'less than a minute', 'axle-read-only-mode' );
		}

		$minutes = (int) ceil( $seconds / MINUTE_IN_SECONDS );

		/* translators: %d: minutes. */
		return sprintf( _n( '%d minute', '%d minutes', $minutes, 'axle-read-only-mode' ), $minutes );
	}

	/**
	 * What the browser scripts need to count down by themselves.
	 *
	 * @return array<string, mixed>
	 */
	public static function strings(): array {
		return array(
			/* translators: %s: time left. */
			'all'     => __( 'The site goes read-only in %s. Save your work.', 'axle-read-only-mode' ),
			/* translators: %s: time left. */
			'content' => __( 'Content goes read-only in %s. Save your work.', 'axle-read-only-mode' ),
			/* translators: %s: time left. */
			'code'    => __( 'Settings and code go read-only in %s. Finish what you are changing.', 'axle-read-only-mode' ),
			/* translators: %d: minutes. */
			'one'     => __( '%d minute', 'axle-read-only-mode' ),
			/* translators: %d: minutes. */
			'many'    => __( '%d minutes', 'axle-read-only-mode' ),
			'under'   => __( 'less than a minute', 'axle-read-only-mode' ),
			'now'     => __( 'The site is read-only now.', 'axle-read-only-mode' ),
		);
	}

	/**
	 * The values the scripts start from.
	 *
	 * @return array<string, mixed>
	 */
	public function script_data(): array {
		return array(
			'upcoming' => $this->upcoming(),
			'strings'  => self::strings(),
		);
	}

	/**
	 * Whether the current screen is the block editor, which shows its own notice.
	 *
	 * @return bool
	 */
	private static function on_block_editor(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && $screen->is_block_editor();
	}

	/**
	 * Loads the countdown script when a freeze is about to start.
	 */
	public function enqueue(): void {
		if ( self::on_block_editor() || ! $this->upcoming() ) {
			return;
		}

		wp_enqueue_script( 'axle-read-only-mode-heads-up', plugins_url( 'assets/heads-up.js', PLUGIN_FILE ), array( 'wp-a11y', 'heartbeat', 'jquery' ), VERSION, true );
		wp_add_inline_script( 'axle-read-only-mode-heads-up', 'window.axleReadOnlyModeHeadsUp = ' . wp_json_encode( $this->script_data() ) . ';', 'before' );
	}

	/**
	 * Prints the notice.
	 */
	public function render(): void {
		$upcoming = self::on_block_editor() ? null : $this->upcoming();
		if ( ! $upcoming ) {
			return;
		}

		printf(
			'<div class="notice notice-warning axle-heads-up" role="status"><p><span class="dashicons dashicons-clock" aria-hidden="true"></span> <strong data-aro-heads-up>%s</strong></p></div>',
			esc_html( $upcoming['message'] )
		);
	}

	/**
	 * Tells open screens how long is left, or that the plan is gone.
	 *
	 * @param array $response Heartbeat response.
	 * @return array
	 */
	public function heartbeat( array $response ): array {
		$response['axle_read_only_mode_heads_up'] = $this->upcoming();

		return $response;
	}
}
