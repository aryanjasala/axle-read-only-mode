<?php
/**
 * What the freeze stopped.
 *
 * @package AryanX\DeployFreeze
 */

namespace AryanX\DeployFreeze;

defined( 'ABSPATH' ) || exit;

/**
 * Records each blocked attempt during the current freeze.
 *
 * After a migration this answers "did anyone try to change anything?",
 * and tells you which edits to redo on the new server.
 */
final class BlockedLog {

	public const OPTION = 'deploy_freeze_log';

	private const LIMIT = 100;

	/**
	 * Adds an attempt.
	 *
	 * @param string $where  What was blocked, e.g. "REST POST /wp/v2/posts/12".
	 * @param string $source admin, ajax, rest or front-end.
	 */
	public function add( string $where, string $source ): void {
		$user = wp_get_current_user();
		$log  = $this->all();

		array_unshift(
			$log,
			array(
				'time'   => time(),
				'who'    => $user->exists() ? $user->user_login : '',
				'where'  => substr( $where, 0, 200 ),
				'source' => $source,
			)
		);

		update_option( self::OPTION, array_slice( $log, 0, self::LIMIT ), false );
	}

	/**
	 * Attempts, newest first.
	 *
	 * @return array[]
	 */
	public function all(): array {
		$log = get_option( self::OPTION, array() );

		return is_array( $log ) ? $log : array();
	}

	/**
	 * Number of attempts recorded.
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->all() );
	}

	/**
	 * Empties the log. Runs when a new freeze starts.
	 */
	public function clear(): void {
		delete_option( self::OPTION );
	}
}
