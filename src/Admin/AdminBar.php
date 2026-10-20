<?php
/**
 * Freeze status and quick actions in the admin bar.
 *
 * @package AryanX\SiteReadOnlyMode
 */

namespace AryanX\SiteReadOnlyMode\Admin;

use AryanX\SiteReadOnlyMode\Freezer;
use AryanX\SiteReadOnlyMode\Service;
use AryanX\SiteReadOnlyMode\State;

defined( 'ABSPATH' ) || exit;

/**
 * Shows "Frozen until …" with Unfreeze and Extend, and a "Freeze for 1 hour"
 * entry under the site name when the site is not frozen.
 */
final class AdminBar implements Service {

	/**
	 * Saved freeze.
	 *
	 * @var State
	 */
	private State $state;

	/**
	 * Sets up the admin bar items.
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
		add_action( 'admin_bar_menu', array( $this, 'add_nodes' ), 100 );
		add_action( 'wp_enqueue_scripts', array( $this, 'add_style' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'add_style' ) );
	}

	/**
	 * Adds the freeze items.
	 *
	 * @param \WP_Admin_Bar $admin_bar Admin bar.
	 */
	public function add_nodes( \WP_Admin_Bar $admin_bar ): void {
		$can_manage = current_user_can( ToolsPage::capability() );

		if ( ! $this->state->is_frozen() ) {
			if ( $can_manage ) {
				$admin_bar->add_node(
					array(
						'parent' => 'site-name',
						'id'     => 'site-read-only-mode-quick',
						/* translators: %s: length, e.g. "1 hour". */
						'title'  => esc_html( sprintf( __( 'Freeze site for %s', 'site-read-only-mode' ), ToolsPage::duration_label( Freezer::default_duration() ) ) ),
						'href'   => ToolsPage::action_url( 'quick_freeze', Freezer::default_duration() ),
					)
				);
			}
			return;
		}

		$until = $this->state->end_time();
		$admin_bar->add_node(
			array(
				'id'    => 'site-read-only-mode',
				/* translators: %s: time the freeze ends. */
				'title' => esc_html( $until ? sprintf( __( 'Frozen until %s', 'site-read-only-mode' ), $until ) : __( 'Frozen', 'site-read-only-mode' ) ),
				'href'  => $can_manage ? admin_url( 'tools.php?page=' . ToolsPage::SLUG ) : false,
				'meta'  => array( 'title' => $this->state->message() ),
			)
		);

		if ( ! $can_manage || $this->state->forced_by_constant() ) {
			return;
		}

		$admin_bar->add_node(
			array(
				'parent' => 'site-read-only-mode',
				'id'     => 'site-read-only-mode-unfreeze',
				'title'  => esc_html__( 'Unfreeze now', 'site-read-only-mode' ),
				'href'   => ToolsPage::action_url( 'unfreeze' ),
			)
		);

		if ( $this->state->get( 'end' ) ) {
			$admin_bar->add_node(
				array(
					'parent' => 'site-read-only-mode',
					'id'     => 'site-read-only-mode-extend',
					/* translators: %s: length, e.g. "30 minutes". */
					'title'  => esc_html( sprintf( __( 'Add %s', 'site-read-only-mode' ), ToolsPage::duration_label( Freezer::extend_steps()[0] ) ) ),
					'href'   => ToolsPage::action_url( 'extend', Freezer::extend_steps()[0] ),
				)
			);
		}
	}

	/**
	 * Colours the freeze label.
	 */
	public function add_style(): void {
		if ( is_admin_bar_showing() && $this->state->is_frozen() ) {
			wp_add_inline_style( 'admin-bar', '#wpadminbar #wp-admin-bar-site-read-only-mode>.ab-item{background:#135e96;color:#fff;font-weight:600}' );
		}
	}
}
