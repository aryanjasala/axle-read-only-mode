<?php
/**
 * Past freezes.
 *
 * @package AryanX\DeployFreeze
 */

namespace AryanX\DeployFreeze;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the last ten freezes: when, who, why, how they ended, what was blocked.
 */
final class History {

	public const OPTION = 'deploy_freeze_history';

	private const LIMIT = 10;

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
	 * }
	 */
	public function add( array $entry ): void {
		$history = $this->all();
		array_unshift( $history, $entry );

		update_option( self::OPTION, array_slice( $history, 0, self::LIMIT ), false );
	}

	/**
	 * Freezes, newest first.
	 *
	 * @return array[]
	 */
	public function all(): array {
		$history = get_option( self::OPTION, array() );

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
			update_option( self::OPTION, $history, false );
		}

		return $changed;
	}
}
