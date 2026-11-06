<?php
/**
 * What the freeze stopped.
 *
 * @package AryanX\Axle\ReadOnlyMode
 */

namespace AryanX\Axle\ReadOnlyMode;

defined( 'ABSPATH' ) || exit;

/**
 * Records each blocked attempt during the current freeze, and the first few
 * requests the allow-list let through, marked as allowed.
 *
 * After a migration this answers "did anyone try to change anything?",
 * and tells you which edits to redo on the new server.
 *
 * Every logged-in attempt is kept. Anonymous attempts (bots, visitors posting
 * forms) stop being written after the first few per freeze, so a busy site
 * does not rewrite this option on every blocked request. Entries keep the user
 * ID and the address without its query string, and take part in the WordPress
 * personal data export and erase tools.
 */
final class BlockedLog implements Service, LogCounter {

	public const OPTION = 'axle_read_only_mode_log';

	private const LIMIT = 100;

	/**
	 * User ID left in entries whose user was erased by the privacy tools.
	 */
	public const ERASED = -1;

	/**
	 * Freeze history, which also holds personal data: who started each freeze.
	 *
	 * @var History
	 */
	private History $history;

	/**
	 * The network's freeze history, on multisite.
	 *
	 * @var History|null
	 */
	private ?History $network_history;

	/**
	 * Sets up the log.
	 *
	 * @param History      $history         Freeze history.
	 * @param History|null $network_history The network's freeze history, on multisite.
	 */
	public function __construct( History $history, ?History $network_history = null ) {
		$this->history         = $history;
		$this->network_history = $network_history;
	}

	/**
	 * Registers the privacy tools.
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
		add_action( 'admin_init', array( $this, 'privacy_policy' ) );
	}

	/**
	 * Adds a blocked attempt.
	 *
	 * @param string $where  What was blocked, e.g. "REST POST /wp/v2/posts/12".
	 * @param string $source admin, ajax, rest or front-end.
	 * @param int    $since  Only entries from this time count towards the visitor limit: the start of the freeze, for a network freeze that does not empty the log.
	 */
	public function add( string $where, string $source, int $since = 0 ): void {
		$log = $this->all();

		if ( ! get_current_user_id() && $this->visitor_entries( $log, $since ) >= $this->visitor_limit() ) {
			return;
		}

		$this->write( $log, $where, $source );
	}

	/**
	 * Adds a request the allow-list or a filter let through a freeze.
	 *
	 * It sits in the same list, marked as allowed, so the person who set up
	 * the allow-list can see it working.
	 *
	 * @param string $where  What was let through, e.g. "POST /?wc-api=wc_stripe".
	 * @param string $source admin, ajax, rest or front-end.
	 * @param string $via    settings (the allow-list) or code (a filter).
	 * @param int    $since  Only entries from this time count towards the limit.
	 */
	public function add_allowed( string $where, string $source, string $via, int $since = 0 ): void {
		$log = $this->all();

		if ( $this->allowed_entries( $log, $since ) >= $this->allowed_limit() ) {
			return;
		}

		$this->write(
			$log,
			$where,
			$source,
			array(
				'allowed' => true,
				'via'     => $via,
			)
		);
	}

	/**
	 * Saves a new entry at the top of the log.
	 *
	 * @param array[] $log    Entries so far.
	 * @param string  $where  What happened.
	 * @param string  $source admin, ajax, rest or front-end.
	 * @param array   $extra  Extra fields of the entry.
	 */
	private function write( array $log, string $where, string $source, array $extra = array() ): void {
		array_unshift(
			$log,
			array(
				'time'   => time(),
				'user'   => get_current_user_id(),
				// Query strings can hold email addresses or tokens.
				'where'  => substr( (string) preg_replace( '/\?.*$/', '', $where ), 0, 200 ),
				'source' => $source,
			) + $extra
		);

		/**
		 * Filters how many blocked attempts the log keeps.
		 *
		 * @param int $size Default 100.
		 */
		$size = max( 1, (int) apply_filters( 'axle_read_only_mode_log_size', self::LIMIT ) );
		update_option( self::OPTION, array_slice( $log, 0, $size ), false );
	}

	/**
	 * Allowed-through requests written per freeze.
	 *
	 * @return int
	 */
	public function allowed_limit(): int {
		/**
		 * Filters how many requests let through a freeze are logged per freeze.
		 *
		 * A webhook can arrive many times a minute, and each one is a database
		 * write, so the log keeps the first few: enough to see the allow-list
		 * working. The `axle_read_only_mode_allowed` action still fires for every one.
		 * Return 0 to log none.
		 *
		 * @param int $limit Default 25.
		 */
		return max( 0, (int) apply_filters( 'axle_read_only_mode_logged_allowed', 25 ) );
	}

	/**
	 * Anonymous attempts written per freeze.
	 *
	 * @return int
	 */
	public function visitor_limit(): int {
		/**
		 * Filters how many anonymous blocked attempts are logged per freeze.
		 *
		 * Each one is a database write, so the default keeps bots from causing
		 * one on every request. Return 0 to log logged-in attempts only.
		 *
		 * @param int $limit Default 10.
		 */
		return max( 0, (int) apply_filters( 'axle_read_only_mode_logged_visitors', 10 ) );
	}

	/**
	 * Entries, newest first.
	 *
	 * @param int $since Only entries from this time on, 0 for all.
	 * @return array[]
	 */
	public function all( int $since = 0 ): array {
		$log = get_option( self::OPTION, array() );
		$log = is_array( $log ) ? $log : array();

		if ( $since > 0 ) {
			$log = array_values(
				array_filter(
					$log,
					static function ( array $entry ) use ( $since ): bool {
						return (int) ( $entry['time'] ?? 0 ) >= $since;
					}
				)
			);
		}

		return $log;
	}

	/**
	 * Login of the user behind an entry, or '' for a visitor.
	 *
	 * @param array $entry Log entry.
	 * @return string
	 */
	public static function who( array $entry ): string {
		if ( self::ERASED === (int) ( $entry['user'] ?? 0 ) ) {
			return __( '(removed)', 'axle-read-only-mode' );
		}
		if ( ! empty( $entry['user'] ) ) {
			$user = get_userdata( (int) $entry['user'] );
			return $user ? $user->user_login : '#' . (int) $entry['user'];
		}

		// Entries written by 1.1.0 and earlier kept the login itself.
		return (string) ( $entry['who'] ?? '' );
	}

	/**
	 * Number of blocked attempts. Requests that were let through are not counted.
	 *
	 * @param int $since Only entries from this time on, 0 for all.
	 * @return int
	 */
	public function count( int $since = 0 ): int {
		return count( $this->blocked_only( $this->all( $since ) ) );
	}

	/**
	 * Number of requests the allow-list or a filter let through.
	 *
	 * @param int $since Only entries from this time on, 0 for all.
	 * @return int
	 */
	public function count_allowed( int $since = 0 ): int {
		return count( $this->all( $since ) ) - $this->count( $since );
	}

	/**
	 * Empties the log.
	 */
	public function clear(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Adds the log and history to Tools > Export Personal Data.
	 *
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public function add_exporter( array $exporters ): array {
		$exporters['axle-read-only-mode'] = array(
			'exporter_friendly_name' => __( 'Axle Read-Only Mode', 'axle-read-only-mode' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Adds the log and history to Tools > Erase Personal Data.
	 *
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public function add_eraser( array $erasers ): array {
		$erasers['axle-read-only-mode'] = array(
			'eraser_friendly_name' => __( 'Axle Read-Only Mode', 'axle-read-only-mode' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Personal data exporter: blocked attempts by a user, and freezes they started.
	 *
	 * @param string $email Email address.
	 * @return array{data: array, done: bool}
	 */
	public function export( $email ): array {
		$user = get_user_by( 'email', (string) $email );
		$data = array();

		if ( $user ) {
			foreach ( $this->all() as $index => $entry ) {
				if ( (int) ( $entry['user'] ?? 0 ) === $user->ID || ( $entry['who'] ?? '' ) === $user->user_login ) {
					$data[] = array(
						'group_id'    => 'axle-read-only-mode-blocked',
						'group_label' => __( 'Changes blocked during a freeze', 'axle-read-only-mode' ),
						'item_id'     => 'axle-read-only-mode-blocked-' . $index,
						'data'        => array(
							array(
								'name'  => __( 'Date', 'axle-read-only-mode' ),
								'value' => gmdate( 'Y-m-d H:i:s', (int) $entry['time'] ),
							),
							array(
								'name'  => empty( $entry['allowed'] ) ? __( 'What was blocked', 'axle-read-only-mode' ) : __( 'What was let through', 'axle-read-only-mode' ),
								'value' => (string) $entry['where'],
							),
						),
					);
				}
			}

			$histories = array( 'site' => $this->history );
			if ( $this->network_history ) {
				$histories['network'] = $this->network_history;
			}

			foreach ( $histories as $kind => $history ) {
				foreach ( $history->all() as $index => $freeze ) {
					if ( (int) ( $freeze['by'] ?? 0 ) !== $user->ID ) {
						continue;
					}

					$data[] = array(
						'group_id'    => 'axle-read-only-mode-history',
						'group_label' => __( 'Freezes you started', 'axle-read-only-mode' ),
						'item_id'     => 'axle-read-only-mode-history-' . $kind . '-' . $index,
						'data'        => array(
							array(
								'name'  => __( 'Started', 'axle-read-only-mode' ),
								'value' => gmdate( 'Y-m-d H:i:s', (int) $freeze['start'] ),
							),
							array(
								'name'  => __( 'Reason', 'axle-read-only-mode' ),
								'value' => (string) $freeze['reason'],
							),
						),
					);
				}
			}
		}

		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Personal data eraser: removes the user from blocked attempts and the history.
	 *
	 * The entries stay, without the user, so the record of what was blocked is not lost.
	 *
	 * @param string $email Email address.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( $email ): array {
		$user    = get_user_by( 'email', (string) $email );
		$removed = false;

		if ( $user ) {
			$log = $this->all();
			foreach ( $log as $index => $entry ) {
				if ( (int) ( $entry['user'] ?? 0 ) === $user->ID || ( $entry['who'] ?? '' ) === $user->user_login ) {
					$log[ $index ]['user'] = self::ERASED;
					unset( $log[ $index ]['who'] );
					$removed = true;
				}
			}
			if ( $removed ) {
				update_option( self::OPTION, $log, false );
			}

			$removed = $this->history->forget_user( $user->ID ) || $removed;
			$removed = ( $this->network_history && $this->network_history->forget_user( $user->ID ) ) || $removed;
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Suggested text for Settings > Privacy.
	 */
	public function privacy_policy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		wp_add_privacy_policy_content(
			__( 'Axle Read-Only Mode', 'axle-read-only-mode' ),
			'<p>' . esc_html__( 'While the site is frozen for maintenance, Axle Read-Only Mode keeps a list of the changes it blocked, and the first few requests your allow-list let through: when, the account of a logged-in user, and the address without its query string. The list is replaced when the next freeze starts. The freeze history keeps who started each of the last 10 freezes, and on a multisite network the network freeze history does the same for network freezes. If you turn on email notices, the addresses you choose are kept in the plugin settings and get a short message when a freeze starts or ends.', 'axle-read-only-mode' ) . '</p>'
		);
	}

	/**
	 * The entries that were blocked, not let through.
	 *
	 * @param array[] $log Log entries.
	 * @return array[]
	 */
	private function blocked_only( array $log ): array {
		return array_filter(
			$log,
			static function ( array $entry ): bool {
				return empty( $entry['allowed'] );
			}
		);
	}

	/**
	 * Allowed-through entries in the log.
	 *
	 * @param array[] $log   Log entries.
	 * @param int     $since Only entries from this time on count.
	 * @return int
	 */
	private function allowed_entries( array $log, int $since = 0 ): int {
		return count(
			array_filter(
				$log,
				static function ( array $entry ) use ( $since ): bool {
					return ! empty( $entry['allowed'] ) && (int) ( $entry['time'] ?? 0 ) >= $since;
				}
			)
		);
	}

	/**
	 * Anonymous entries in the log.
	 *
	 * @param array[] $log   Log entries.
	 * @param int     $since Only entries from this time on count.
	 * @return int
	 */
	private function visitor_entries( array $log, int $since = 0 ): int {
		return count(
			array_filter(
				$log,
				static function ( array $entry ) use ( $since ): bool {
					return empty( $entry['allowed'] ) && empty( $entry['user'] ) && '' === (string) ( $entry['who'] ?? '' ) && (int) ( $entry['time'] ?? 0 ) >= $since;
				}
			)
		);
	}
}
