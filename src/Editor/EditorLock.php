<?php
/**
 * Locks saving in the block editor during a freeze.
 *
 * @package AryanX\Axle\ReadOnlyMode
 */

namespace AryanX\Axle\ReadOnlyMode\Editor;

use AryanX\Axle\ReadOnlyMode\Service;
use AryanX\Axle\ReadOnlyMode\State;
use const AryanX\Axle\ReadOnlyMode\PLUGIN_FILE;
use const AryanX\Axle\ReadOnlyMode\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Disables Save and Publish and explains why.
 *
 * The script loads on every editor screen, frozen or not, so a freeze that
 * starts mid-edit reaches people who already have the editor open, through
 * the heartbeat.
 */
final class EditorLock implements Service {

	/**
	 * Saved freeze.
	 *
	 * @var State
	 */
	private State $state;

	/**
	 * Sets up the lock.
	 *
	 * @param State $state Saved freeze.
	 */
	public function __construct( State $state ) {
		$this->state = $state;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
		add_filter( 'heartbeat_received', array( $this, 'heartbeat' ) );
	}

	/**
	 * The message to show, or an empty string when the user can edit.
	 *
	 * @return string
	 */
	private function lock_message(): string {
		return $this->state->is_frozen() && ! $this->state->can_bypass() ? $this->state->message() : '';
	}

	/**
	 * Loads the editor script with the current freeze message.
	 */
	public function enqueue(): void {
		// Only the post editor. The widgets editor and the Customizer must not load wp-editor.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base ) {
			return;
		}

		wp_enqueue_script(
			'axle-read-only-mode-editor',
			plugins_url( 'assets/editor.js', PLUGIN_FILE ),
			array( 'wp-data', 'wp-editor', 'wp-notices', 'wp-dom-ready', 'heartbeat', 'jquery' ),
			VERSION,
			true
		);

		wp_add_inline_script(
			'axle-read-only-mode-editor',
			'window.axleReadOnlyModeEditor = ' . wp_json_encode( array( 'message' => $this->lock_message() ) ) . ';',
			'before'
		);
	}

	/**
	 * Tells open editors whether the site is frozen.
	 *
	 * @param array $response Heartbeat response.
	 * @return array
	 */
	public function heartbeat( array $response ): array {
		$response['axle_read_only_mode'] = $this->lock_message();

		return $response;
	}
}
