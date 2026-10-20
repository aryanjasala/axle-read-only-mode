<?php
/**
 * Freeze status and quick actions in the admin bar.
 *
 * @package AryanX\DeployFreeze
 */

namespace AryanX\DeployFreeze\Admin;

use AryanX\DeployFreeze\Service;
use AryanX\DeployFreeze\State;

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
						'id'     => 'deploy-freeze-quick',
						'title'  => esc_html__( 'Freeze site for 1 hour', 'deploy-freeze' ),
						'href'   => ToolsPage::action_url( 'quick_freeze', 60 ),
					)
				);
			}
			return;
		}

		$until = $this->state->end_time();
		$admin_bar->add_node(
			array(
				'id'    => 'deploy-freeze',
				/* translators: %s: time the freeze ends. */
				'title' => esc_html( $until ? sprintf( __( 'Frozen until %s', 'deploy-freeze' ), $until ) : __( 'Frozen', 'deploy-freeze' ) ),
				'href'  => $can_manage ? admin_url( 'tools.php?page=' . ToolsPage::SLUG ) : false,
				'meta'  => array( 'title' => $this->state->message() ),
			)
		);

		if ( ! $can_manage || $this->state->forced_by_constant() ) {
			return;
		}

		$admin_bar->add_node(
			array(
				'parent' => 'deploy-freeze',
				'id'     => 'deploy-freeze-unfreeze',
				'title'  => esc_html__( 'Unfreeze now', 'deploy-freeze' ),
				'href'   => ToolsPage::action_url( 'unfreeze' ),
			)
		);

		if ( $this->state->get( 'end' ) ) {
			$admin_bar->add_node(
				array(
					'parent' => 'deploy-freeze',
					'id'     => 'deploy-freeze-extend',
					'title'  => esc_html__( 'Add 30 minutes', 'deploy-freeze' ),
					'href'   => ToolsPage::action_url( 'extend', 30 ),
				)
			);
		}
	}

	/**
	 * Colours the freeze label.
	 */
	public function add_style(): void {
		if ( is_admin_bar_showing() && $this->state->is_frozen() ) {
			wp_add_inline_style( 'admin-bar', '#wpadminbar #wp-admin-bar-deploy-freeze>.ab-item{background:#135e96;color:#fff;font-weight:600}' );
		}
	}
}
