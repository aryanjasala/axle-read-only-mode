<?php
/**
 * The network's freeze, history and log, kept together.
 *
 * @package AryanX\Axle\ReadOnlyMode
 */

namespace AryanX\Axle\ReadOnlyMode;

defined( 'ABSPATH' ) || exit;

/**
 * Only built on a multisite network. Holds what a network admin works with.
 */
final class Network {

	/**
	 * The network's freeze.
	 *
	 * @var State
	 */
	public State $state;

	/**
	 * Starts, extends and ends network freezes.
	 *
	 * @var Freezer
	 */
	public Freezer $freezer;

	/**
	 * Past network freezes.
	 *
	 * @var History
	 */
	public History $history;

	/**
	 * Attempts blocked on every site.
	 *
	 * @var NetworkLog
	 */
	public NetworkLog $log;

	/**
	 * The network's settings: what keeps working during a network freeze.
	 *
	 * @var Settings
	 */
	public Settings $settings;

	/**
	 * The network's planned freezes.
	 *
	 * @var Planner
	 */
	public Planner $planner;

	/**
	 * Builds the network's objects.
	 */
	public function __construct() {
		$this->settings = new Settings( new Store( Settings::NETWORK_OPTION, true ) );
		$this->state    = new State( new Store( State::NETWORK_OPTION, true ), null, new AllowList( $this->settings ) );
		$this->history  = new History( new Store( History::NETWORK_OPTION, true ) );
		$this->log      = new NetworkLog( $this->state );
		$this->freezer  = new Freezer( $this->state, $this->log, $this->history );
		$this->planner  = Planner::for_network( $this->freezer, $this->state );
	}
}
