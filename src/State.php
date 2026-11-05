<?php
/**
 * The saved freeze.
 *
 * @package AryanX\Axle\ReadOnlyMode
 */

namespace AryanX\Axle\ReadOnlyMode;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the current (or scheduled) freeze.
 *
 * A site's State can know about the network's State. A network freeze applies
 * on every site it covers, whatever the site itself says, so `all()` and the
 * other readers describe the freeze that is in force on this site, and `own()`
 * is the freeze this site saved itself.
 */
final class State {

	public const OPTION         = 'axle_read_only_mode_state';
	public const NETWORK_OPTION = 'axle_read_only_mode_network_state';

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
	 * What only a network freeze has: which sites it covers.
	 */
	private const NETWORK_DEFAULTS = array(
		'scope' => 'all',
		'sites' => array(),
	);

	/**
	 * Where this freeze is saved.
	 *
	 * @var Store
	 */
	private Store $store;

	/**
	 * The network's freeze, on a site of a multisite network.
	 *
	 * @var State|null
	 */
	private ?State $network;

	/**
	 * Sets up the state.
	 *
	 * @param Store|null $store   Where the freeze is saved. Default: this site's option.
	 * @param State|null $network The network's freeze, for a site's State on multisite.
	 */
	public function __construct( ?Store $store = null, ?State $network = null ) {
		$this->store   = $store ?? new Store( self::OPTION );
		$this->network = $network;
	}

	/**
	 * Whether this is the network's freeze.
	 *
	 * @return bool
	 */
	public function is_network(): bool {
		return $this->store->is_network();
	}

	/**
	 * The freeze saved here, merged with defaults, whatever the network says.
	 *
	 * @return array{active: bool, start: int, end: int, reason: string, visitor_message: string, visitors: bool, cron: bool, exempt: int[], by: int, scope?: string, sites?: int[]}
	 */
	public function own(): array {
		$defaults = $this->is_network() ? self::DEFAULTS + self::NETWORK_DEFAULTS : self::DEFAULTS;
		$saved    = $this->store->get( array() );

		return array_merge( $defaults, is_array( $saved ) ? array_intersect_key( $saved, $defaults ) : array() );
	}

	/**
	 * The freeze in force on this site: the network's when it applies, else the site's own.
	 *
	 * @return array{active: bool, start: int, end: int, reason: string, visitor_message: string, visitors: bool, cron: bool, exempt: int[], by: int}
	 */
	public function all(): array {
		if ( $this->network_applies() && $this->network ) {
			return array_intersect_key( $this->network->own(), self::DEFAULTS );
		}

		return $this->own();
	}

	/**
	 * Whether a network freeze is running and covers this site.
	 *
	 * @return bool
	 */
	public function network_applies(): bool {
		return null !== $this->network && $this->network->is_running() && $this->network->covers( get_current_blog_id() );
	}

	/**
	 * Whether a network freeze covers a site. Only meaningful on the network's State.
	 *
	 * @param int $blog_id Site ID.
	 * @return bool
	 */
	public function covers( int $blog_id ): bool {
		$own = $this->own();

		return 'sites' !== ( $own['scope'] ?? 'all' ) || in_array( $blog_id, array_map( 'intval', (array) ( $own['sites'] ?? array() ) ), true );
	}

	/**
	 * What holds the site frozen: constant, network or site. Empty when it is not frozen.
	 *
	 * @return string
	 */
	public function source(): string {
		if ( $this->forced_by_constant() ) {
			return 'constant';
		}
		if ( $this->network_applies() ) {
			return 'network';
		}

		return $this->is_frozen() ? 'site' : '';
	}

	/**
	 * Start of the freeze whose attempts the log shows, or 0 for the whole log.
	 *
	 * A site's log is emptied when its own freeze starts. A network freeze
	 * leaves every log alone, so the log is read from the network freeze's start.
	 *
	 * @return int
	 */
	public function log_since(): int {
		return $this->network_applies() && ! $this->is_running() && $this->network ? (int) $this->network->own()['start'] : 0;
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
	 * One value of the freeze saved here.
	 *
	 * @param string $key Name.
	 * @return mixed
	 */
	public function own_value( string $key ) {
		return $this->own()[ $key ] ?? null;
	}

	/**
	 * Saves new values, keeping the rest.
	 *
	 * @param array $changes Name => value.
	 */
	public function update( array $changes ): void {
		$own = $this->own();

		$this->store->set( array_merge( $own, array_intersect_key( $changes, $own ) ) );
	}

	/**
	 * Whether wp-config.php forces the freeze on.
	 *
	 * @return bool
	 */
	public function forced_by_constant(): bool {
		return defined( 'AXLE_READ_ONLY_MODE' ) && AXLE_READ_ONLY_MODE;
	}

	/**
	 * Whether the freeze saved here is running, ignoring the constant, the
	 * network and the filter.
	 *
	 * A freeze with an end time expires on its own, so a deploy that dies
	 * halfway cannot leave the site frozen for days.
	 *
	 * @return bool
	 */
	public function is_running(): bool {
		$own = $this->own();
		$now = time();

		return $own['active'] && $now >= $own['start'] && ( ! $own['end'] || $now < $own['end'] );
	}

	/**
	 * Whether the site is frozen right now.
	 *
	 * @return bool
	 */
	public function is_frozen(): bool {
		if ( $this->forced_by_constant() ) {
			return true;
		}

		$frozen = $this->network_applies() || $this->is_running();

		/**
		 * Filters whether the site is frozen right now.
		 *
		 * Lets a deploy system freeze every site from one switch (an environment
		 * variable, a cache key). Runs on plugins_loaded, so add it from a
		 * must-use plugin. The AXLE_READ_ONLY_MODE constant always wins.
		 *
		 * @param bool  $frozen Whether the freeze is on, from the site's own freeze or the network's.
		 * @param array $state  The freeze in force on this site.
		 */
		return (bool) apply_filters( 'axle_read_only_mode_is_frozen', $frozen, $this->all() );
	}

	/**
	 * Whether a freeze is set to start later.
	 *
	 * @return bool
	 */
	public function is_scheduled(): bool {
		$own = $this->own();

		return $own['active'] && $own['start'] > time();
	}

	/**
	 * Whether a freeze ran past its end time and has not been recorded yet.
	 *
	 * @return bool
	 */
	public function has_expired(): bool {
		$own = $this->own();

		return $own['active'] && $own['end'] && time() >= $own['end'];
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
		return (bool) apply_filters( 'axle_read_only_mode_user_can_bypass', $exempt, $user_id );
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
		$reason = $this->forced_by_constant() && defined( 'AXLE_READ_ONLY_MODE_REASON' ) ? (string) AXLE_READ_ONLY_MODE_REASON : (string) $this->get( 'reason' );
		$until  = $this->end_time();

		if ( $this->network_applies() && ! $this->forced_by_constant() ) {
			$message = $until
				/* translators: %s: time the freeze ends. */
				? sprintf( __( 'This site is frozen by the network for maintenance until %s. Changes cannot be saved right now.', 'axle-read-only-mode' ), $until )
				: __( 'This site is frozen by the network for maintenance. Changes cannot be saved right now.', 'axle-read-only-mode' );
		} else {
			$message = $until
				/* translators: %s: time the freeze ends. */
				? sprintf( __( 'This site is frozen for maintenance until %s. Changes cannot be saved right now.', 'axle-read-only-mode' ), $until )
				: __( 'This site is frozen for maintenance. Changes cannot be saved right now.', 'axle-read-only-mode' );
		}

		if ( '' !== $reason ) {
			/* translators: %s: reason for the freeze. */
			$message .= ' ' . sprintf( __( 'Reason: %s', 'axle-read-only-mode' ), $reason );
		}

		/**
		 * Filters the message logged-in users see during a freeze.
		 *
		 * @param string $message Message.
		 * @param array  $state   The freeze in force on this site: the network's when it applies.
		 */
		return (string) apply_filters( 'axle_read_only_mode_message', $message, $this->all() );
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
				? sprintf( __( 'We are doing some maintenance and cannot accept submissions right now. Please try again after %s.', 'axle-read-only-mode' ), $until )
				: __( 'We are doing some maintenance and cannot accept submissions right now. Please try again shortly.', 'axle-read-only-mode' );
		}

		/**
		 * Filters the message visitors get when they send a form during a freeze.
		 *
		 * @param string $message Message, the one set for this freeze or the default.
		 * @param array  $state   The freeze.
		 */
		return (string) apply_filters( 'axle_read_only_mode_visitor_message', $message, $this->all() );
	}
}
