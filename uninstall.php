<?php
/**
 * Removes Deploy Freeze's state, log and history, on every site.
 *
 * @package DeployFreeze
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes Deploy Freeze data from the current site.
 */
function deploy_freeze_uninstall_site(): void {
	global $wpdb;

	delete_option( 'deploy_freeze_state' );
	delete_option( 'deploy_freeze_log' );
	delete_option( 'deploy_freeze_history' );

	// One-time expiry markers, one per freeze.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup on uninstall.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'deploy_freeze_expired_' ) . '%' ) );
}

if ( is_multisite() ) {
	$deploy_freeze_offset = 0;
	do {
		$deploy_freeze_sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $deploy_freeze_offset,
			)
		);

		foreach ( $deploy_freeze_sites as $deploy_freeze_site ) {
			switch_to_blog( $deploy_freeze_site );
			deploy_freeze_uninstall_site();
			restore_current_blog();
		}

		$deploy_freeze_offset += 100;
	} while ( $deploy_freeze_sites );
} else {
	deploy_freeze_uninstall_site();
}
