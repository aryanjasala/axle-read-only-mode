<?php
/**
 * Removes Axle Read-Only Mode's state, log and history, on every site.
 *
 * @package AxleReadOnlyMode
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes Axle Read-Only Mode data from the current site.
 */
function axle_read_only_mode_uninstall_site(): void {
	global $wpdb;

	delete_option( 'axle_read_only_mode_state' );
	delete_option( 'axle_read_only_mode_log' );
	delete_option( 'axle_read_only_mode_history' );

	// One-time expiry markers, one per freeze.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup on uninstall.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'axle_read_only_mode_expired_' ) . '%' ) );
}

if ( is_multisite() ) {
	$axle_read_only_mode_offset = 0;
	do {
		$axle_read_only_mode_sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $axle_read_only_mode_offset,
			)
		);

		foreach ( $axle_read_only_mode_sites as $axle_read_only_mode_site ) {
			switch_to_blog( $axle_read_only_mode_site );
			axle_read_only_mode_uninstall_site();
			restore_current_blog();
		}

		$axle_read_only_mode_offset += 100;
	} while ( $axle_read_only_mode_sites );
} else {
	axle_read_only_mode_uninstall_site();
}
