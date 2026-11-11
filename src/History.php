<?php
/**
 * Past freezes.
 *
 * @package AryanX\Axle\ReadOnlyMode
 */

namespace AryanX\Axle\ReadOnlyMode;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the last ten freezes: when, who, why, how they ended, what was blocked.
 * The network has its own, for network freezes.
 */
final class History {

	public const OPTION         = 'axle_read_only_mode_history';
	public const NETWORK_OPTION = 'axle_read_only_mode_network_history';

	private const LIMIT = 10;

	/**
	 * Where the freezes are saved.
	 *
	 * @var Store
	 */
	private Store $store;

	/**
	 * Sets up the history.
	 *
	 * @param Store|null $store Where the freezes are saved. Default: this site's option.
	 */
	public function __construct( ?Store $store = null ) {
		$this->store = $store ?? new Store( self::OPTION, false, false );
	}

	/**
	 * Adds a finished freeze.
	 *
	 * @param array $entry {
	 *     The freeze.
	 *
	 *     @type int    $start    When it started.
	 *     @type int    $end      When it ended.
	 *     @type string $reason   Reason given.
	 *     @type int    $by       User who started it, 0 for WP-CLI.
	 *     @type string $ended_by user, cli or expired.
	 *     @type int    $blocked  Blocked attempts.
	 *     @type string $scope    What the freeze covered: all, content or code. Entries from before scopes existed have none and were `all`.
	 * }
	 */
	public function add( array $entry ): void {
		$history = $this->all();
		array_unshift( $history, $entry );

		/**
		 * Filters how many past freezes the history keeps.
		 *
		 * @param int $size Default 10.
		 */
		$size = max( 1, (int) apply_filters( 'axle_read_only_mode_history_size', self::LIMIT ) );
		$this->store->set( array_slice( $history, 0, $size ) );
	}

	/**
	 * Freezes, newest first.
	 *
	 * @return array[]
	 */
	public function all(): array {
		$history = $this->store->get( array() );

		return is_array( $history ) ? $history : array();
	}

	/**
	 * Removes a user from the history, for the personal data eraser.
	 *
	 * @param int $user_id User ID.
	 * @return bool Whether any entry changed.
	 */
	public function forget_user( int $user_id ): bool {
		$history = $this->all();
		$changed = false;

		foreach ( $history as $index => $freeze ) {
			if ( (int) ( $freeze['by'] ?? 0 ) === $user_id ) {
				$history[ $index ]['by'] = BlockedLog::ERASED;
				$changed                 = true;
			}
		}

		if ( $changed ) {
			$this->store->set( $history );
		}

		return $changed;
	}
}
