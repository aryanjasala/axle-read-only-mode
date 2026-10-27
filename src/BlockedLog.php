<?php
/**
 * What the freeze stopped.
 *
 * @package AryanX\Axle\ReadOnlyMode
 */

namespace AryanX\Axle\ReadOnlyMode;

defined( 'ABSPATH' ) || exit;

/**
 * Records each blocked attempt during the current freeze.
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
final class BlockedLog implements Service {

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
	 * Sets up the log.
	 *
	 * @param History $history Freeze history.
	 */
	public function __construct( History $history ) {
		$this->history = $history;
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
	 * Adds an attempt.
	 *
	 * @param string $where  What was blocked, e.g. "REST POST /wp/v2/posts/12".
	 * @param string $source admin, ajax, rest or front-end.
	 */
	public function add( string $where, string $source ): void {
		$user_id = get_current_user_id();
		$log     = $this->all();

		if ( ! $user_id && $this->visitor_entries( $log ) >= $this->visitor_limit() ) {
			return;
		}

		array_unshift(
			$log,
			array(
				'time'   => time(),
				'user'   => $user_id,
				// Query strings can hold email addresses or tokens.
				'where'  => substr( (string) preg_replace( '/\?.*$/', '', $where ), 0, 200 ),
				'source' => $source,
			)
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
	 * @return array[]
	 */
	public function all(): array {
		$log = get_option( self::OPTION, array() );

		return is_array( $log ) ? $log : array();
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
	 * Number of attempts.
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->all() );
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
								'name'  => __( 'What was blocked', 'axle-read-only-mode' ),
								'value' => (string) $entry['where'],
							),
						),
					);
				}
			}

			foreach ( $this->history->all() as $index => $freeze ) {
				if ( (int) ( $freeze['by'] ?? 0 ) === $user->ID ) {
					$data[] = array(
						'group_id'    => 'axle-read-only-mode-history',
						'group_label' => __( 'Freezes you started', 'axle-read-only-mode' ),
						'item_id'     => 'axle-read-only-mode-history-' . $index,
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
			'<p>' . esc_html__( 'While the site is frozen for maintenance, Axle Read-Only Mode keeps a list of the changes it blocked: when, the account of a logged-in user, and the address without its query string. The list is replaced when the next freeze starts. The freeze history keeps who started each of the last 10 freezes.', 'axle-read-only-mode' ) . '</p>'
		);
	}

	/**
	 * Anonymous entries in the log.
	 *
	 * @param array[] $log Log entries.
	 * @return int
	 */
	private function visitor_entries( array $log ): int {
		return count(
			array_filter(
				$log,
				static function ( array $entry ): bool {
					return empty( $entry['user'] ) && '' === (string) ( $entry['who'] ?? '' );
				}
			)
		);
	}
}
