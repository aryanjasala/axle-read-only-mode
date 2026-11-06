<?php
/**
 * What the freezer needs from a log.
 *
 * @package AryanX\Axle\ReadOnlyMode
 */

namespace AryanX\Axle\ReadOnlyMode;

defined( 'ABSPATH' ) || exit;

/**
 * Counts blocked attempts and starts a fresh list: one site's log, or the
 * network's view over every site's log.
 */
interface LogCounter {

	/**
	 * Blocked attempts during the current or last freeze.
	 *
	 * @return int
	 */
	public function count(): int;

	/**
	 * Starts a fresh log for a new freeze.
	 */
	public function clear(): void;
}
