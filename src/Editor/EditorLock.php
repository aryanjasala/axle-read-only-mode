<?php
/**
 * Locks saving in the block editor during a freeze.
 *
 * @package AryanX\Axle\ReadOnlyMode
 */

namespace AryanX\Axle\ReadOnlyMode\Editor;

use AryanX\Axle\ReadOnlyMode\HeadsUp;
use AryanX\Axle\ReadOnlyMode\Scopes;
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
	 * The countdown before a planned freeze.
	 *
	 * @var HeadsUp|null
	 */
	private ?HeadsUp $heads_up;

	/**
	 * Sets up the lock.
	 *
	 * @param State        $state    Saved freeze.
	 * @param HeadsUp|null $heads_up The countdown before a planned freeze.
	 */
	public function __construct( State $state, ?HeadsUp $heads_up = null ) {
		$this->state    = $state;
		$this->heads_up = $heads_up;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_classic' ) );
		add_filter( 'heartbeat_received', array( $this, 'heartbeat' ) );
	}

	/**
	 * The message to show, or an empty string when the user can edit: the freeze
	 * is off, does not cover content, or the user is exempt.
	 *
	 * @return string
	 */
	private function lock_message(): string {
		return $this->state->is_frozen() && $this->state->freezes( Scopes::CONTENT ) && ! $this->state->can_bypass() ? $this->state->message() : '';
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
			array( 'wp-data', 'wp-editor', 'wp-notices', 'wp-dom-ready', 'wp-api-fetch', 'wp-blocks', 'wp-a11y', 'heartbeat', 'jquery' ),
			VERSION,
			true
		);

		wp_add_inline_script( 'axle-read-only-mode-editor', 'window.axleReadOnlyModeEditor = ' . wp_json_encode( $this->script_data() ) . ';', 'before' );
	}

	/**
	 * Loads the classic editor script, which keeps the form when a save is refused.
	 */
	public function enqueue_classic(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base || $screen->is_block_editor() ) {
			return;
		}

		wp_enqueue_script( 'axle-read-only-mode-classic', plugins_url( 'assets/classic.js', PLUGIN_FILE ), array( 'wp-a11y', 'heartbeat', 'jquery' ), VERSION, true );
		wp_add_inline_script( 'axle-read-only-mode-classic', 'window.axleReadOnlyModeEditor = ' . wp_json_encode( $this->script_data() ) . ';', 'before' );
	}

	/**
	 * What the editor scripts start from.
	 *
	 * @return array<string, mixed>
	 */
	private function script_data(): array {
		$data = array(
			'message' => $this->lock_message(),
			'strings' => $this->strings(),
		);

		if ( $this->heads_up ) {
			$data['upcoming'] = $this->heads_up->upcoming();
			$data['strings']  = array_merge( $data['strings'], HeadsUp::strings() );
		}

		return $data;
	}

	/**
	 * The words the editor scripts show.
	 *
	 * @return array<string, string>
	 */
	private function strings(): array {
		$strings = array(
			'kept'           => __( 'Your changes stay in this browser and are saved when the freeze ends.', 'axle-read-only-mode' ),
			'saved'          => __( 'Your changes were saved.', 'axle-read-only-mode' ),
			'failed'         => __( 'Your changes could not be saved. They are still in this browser.', 'axle-read-only-mode' ),
			'saveNow'        => __( 'Save my changes now', 'axle-read-only-mode' ),
			/* translators: 1: time the copy in this browser was made, 2: time the post was last saved on the server. */
			'conflict'       => __( 'This post was changed by someone else while the site was read-only. Your version, kept in this browser at %1$s, is open in the editor. The saved version changed at %2$s. Choose which one to keep.', 'axle-read-only-mode' ),
			'keepMine'       => __( 'Save my version', 'axle-read-only-mode' ),
			'useSaved'       => __( 'Use the saved version', 'axle-read-only-mode' ),
			'classicKept'    => __( 'Your changes are still in this form. Save again when the freeze ends.', 'axle-read-only-mode' ),
			'classicRefused' => __( 'Nothing was saved. The site is read-only, and your changes are still in the form.', 'axle-read-only-mode' ),
			'classicOver'    => __( 'The freeze has ended. You can save now.', 'axle-read-only-mode' ),
		);

		/**
		 * Filters the words the editor shows while the site is read-only and when it is over.
		 *
		 * @param array<string, string> $strings Keys: kept, saved, failed, saveNow, conflict, keepMine, useSaved, classicKept, classicRefused, classicOver.
		 */
		return (array) apply_filters( 'axle_read_only_mode_editor_strings', $strings );
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
