<?php
/**
 * Freeze status and quick actions in the admin bar.
 *
 * @package AryanX\Axle\ReadOnlyMode
 */

namespace AryanX\Axle\ReadOnlyMode\Admin;

use AryanX\Axle\ReadOnlyMode\Freezer;
use AryanX\Axle\ReadOnlyMode\Scopes;
use AryanX\Axle\ReadOnlyMode\Service;
use AryanX\Axle\ReadOnlyMode\State;

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
						'id'     => 'axle-read-only-mode-quick',
						/* translators: %s: length, e.g. "1 hour". */
						'title'  => esc_html( sprintf( __( 'Freeze site for %s', 'axle-read-only-mode' ), ToolsPage::duration_label( Freezer::default_duration() ) ) ),
						'href'   => ToolsPage::action_url( 'quick_freeze', Freezer::default_duration() ),
					)
				);
			}
			return;
		}

		$until   = $this->state->end_time();
		$network = $this->state->network_applies() && ! $this->state->forced_by_constant();
		$scope   = $this->state->scope();
		$status  = Scopes::status( $scope );
		if ( Scopes::ALL !== $scope ) {
			// For example: content frozen until 3 pm, or settings and code frozen by the network.
			if ( $network ) {
				$title = $until
					/* translators: 1: what is frozen, e.g. "Content frozen", 2: time the freeze ends. */
					? sprintf( __( '%1$s by the network until %2$s', 'axle-read-only-mode' ), $status, $until )
					/* translators: %s: what is frozen, e.g. "Content frozen". */
					: sprintf( __( '%s by the network', 'axle-read-only-mode' ), $status );
			} else {
				$title = $until
					/* translators: 1: what is frozen, e.g. "Content frozen", 2: time the freeze ends. */
					? sprintf( __( '%1$s until %2$s', 'axle-read-only-mode' ), $status, $until )
					: $status;
			}
		} elseif ( $network ) {
			$title = $until
				/* translators: %s: time the freeze ends. */
				? sprintf( __( 'Frozen by the network until %s', 'axle-read-only-mode' ), $until )
				: __( 'Frozen by the network', 'axle-read-only-mode' );
		} else {
			$title = $until
				/* translators: %s: time the freeze ends. */
				? sprintf( __( 'Frozen until %s', 'axle-read-only-mode' ), $until )
				: __( 'Frozen', 'axle-read-only-mode' );
		}

		$admin_bar->add_node(
			array(
				'id'    => 'axle-read-only-mode',
				'title' => esc_html( $title ),
				'href'  => $network ? ( current_user_can( NetworkPage::capability() ) ? NetworkPage::page_url() : false ) : ( $can_manage ? admin_url( 'tools.php?page=' . ToolsPage::SLUG ) : false ),
				'meta'  => array( 'title' => $this->state->message() ),
			)
		);

		if ( $network ) {
			if ( current_user_can( NetworkPage::capability() ) && is_multisite() ) {
				$admin_bar->add_node(
					array(
						'parent' => 'axle-read-only-mode',
						'id'     => 'axle-read-only-mode-network',
						'title'  => esc_html__( 'Manage in Network Admin', 'axle-read-only-mode' ),
						'href'   => NetworkPage::page_url(),
					)
				);
			}
			return;
		}

		if ( ! $can_manage || $this->state->forced_by_constant() ) {
			return;
		}

		$admin_bar->add_node(
			array(
				'parent' => 'axle-read-only-mode',
				'id'     => 'axle-read-only-mode-unfreeze',
				'title'  => esc_html__( 'Unfreeze now', 'axle-read-only-mode' ),
				'href'   => ToolsPage::action_url( 'unfreeze' ),
			)
		);

		if ( $this->state->get( 'end' ) ) {
			$admin_bar->add_node(
				array(
					'parent' => 'axle-read-only-mode',
					'id'     => 'axle-read-only-mode-extend',
					/* translators: %s: length, e.g. "30 minutes". */
					'title'  => esc_html( sprintf( __( 'Add %s', 'axle-read-only-mode' ), ToolsPage::duration_label( Freezer::extend_steps()[0] ) ) ),
					'href'   => ToolsPage::action_url( 'extend', Freezer::extend_steps()[0] ),
				)
			);
		}
	}

	/**
	 * Colours the freeze label in the shared warning tone (see _dev/DESIGN.md).
	 */
	public function add_style(): void {
		if ( is_admin_bar_showing() && $this->state->is_frozen() ) {
			wp_add_inline_style( 'admin-bar', '#wpadminbar #wp-admin-bar-axle-read-only-mode>.ab-item,#wpadminbar #wp-admin-bar-axle-read-only-mode:hover>.ab-item,#wpadminbar #wp-admin-bar-axle-read-only-mode.hover>.ab-item{background:#fcf9e8;color:#614200;font-weight:600;box-shadow:inset 0 -3px 0 #dba617}' );
		}
	}
}
