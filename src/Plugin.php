<?php
/**
 * Plugin bootstrap.
 *
 * @package AryanX\Axle\ReadOnlyMode
 */

namespace AryanX\Axle\ReadOnlyMode;

use AryanX\Axle\ReadOnlyMode\Admin\AdminBar;
use AryanX\Axle\ReadOnlyMode\Admin\Notice;
use AryanX\Axle\ReadOnlyMode\Admin\ToolsPage;
use AryanX\Axle\ReadOnlyMode\Cli\Command;
use AryanX\Axle\ReadOnlyMode\Editor\EditorLock;
use AryanX\Axle\ReadOnlyMode\Guards\AdminGuard;
use AryanX\Axle\ReadOnlyMode\Guards\Blocker;
use AryanX\Axle\ReadOnlyMode\Guards\FrontEndGuard;
use AryanX\Axle\ReadOnlyMode\Guards\RestGuard;
use AryanX\Axle\ReadOnlyMode\Guards\SideEffects;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the services once and registers their hooks.
 *
 * The screen, admin bar and editor script load everywhere. The guards only
 * load while the site is frozen.
 */
final class Plugin {

	/**
	 * The running instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Saved freeze.
	 *
	 * @var State
	 */
	private State $state;

	/**
	 * Blocked attempts.
	 *
	 * @var BlockedLog
	 */
	private BlockedLog $log;

	/**
	 * Past freezes.
	 *
	 * @var History
	 */
	private History $history;

	/**
	 * Starts, extends and ends freezes.
	 *
	 * @var Freezer
	 */
	private Freezer $freezer;

	/**
	 * Starts the plugin. Runs on `plugins_loaded`.
	 */
	public static function boot(): void {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register();
		}
	}

	/**
	 * The running instance.
	 *
	 * @return Plugin|null
	 */
	public static function instance(): ?Plugin {
		return self::$instance;
	}

	/**
	 * Builds the shared objects.
	 */
	private function __construct() {
		$this->state   = new State();
		$this->history = new History();
		$this->log     = new BlockedLog( $this->history );
		$this->freezer = new Freezer( $this->state, $this->log, $this->history );
	}

	/**
	 * Freeze controls, for other plugins and deploy tooling.
	 *
	 * @return Freezer
	 */
	public function freezer(): Freezer {
		return $this->freezer;
	}

	/**
	 * Saved freeze, for other plugins.
	 *
	 * @return State
	 */
	public function state(): State {
		return $this->state;
	}

	/**
	 * Registers the services.
	 */
	private function register(): void {
		$this->freezer->expire_if_needed();

		$services = array(
			$this->log,
			new ToolsPage( $this->state, $this->freezer, $this->log, $this->history ),
			new AdminBar( $this->state ),
			new EditorLock( $this->state ),
			new Abilities( $this->state, $this->freezer, $this->log ),
		);

		if ( $this->state->is_frozen() ) {
			$blocker  = new Blocker( $this->state, $this->log );
			$services = array_merge(
				$services,
				array(
					new Notice( $this->state ),
					new AdminGuard( $blocker ),
					new RestGuard( $blocker ),
					new FrontEndGuard( $blocker ),
					new SideEffects( $this->state ),
				)
			);
		}

		foreach ( $services as $service ) {
			$service->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'axle-read-only-mode', new Command( $this->state, $this->freezer, $this->log ) );
		}
	}
}
