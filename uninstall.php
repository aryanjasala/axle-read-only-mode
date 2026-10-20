<?php
/**
 * Removes Site Read-Only Mode's state, log and history, on every site.
 *
 * @package SiteReadOnlyMode
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes Site Read-Only Mode data from the current site.
 */
function site_read_only_mode_uninstall_site(): void {
	global $wpdb;

	delete_option( 'site_read_only_mode_state' );
	delete_option( 'site_read_only_mode_log' );
	delete_option( 'site_read_only_mode_history' );

	// One-time expiry markers, one per freeze.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup on uninstall.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'site_read_only_mode_expired_' ) . '%' ) );
}

if ( is_multisite() ) {
	$site_read_only_mode_offset = 0;
	do {
		$site_read_only_mode_sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $site_read_only_mode_offset,
			)
		);

		foreach ( $site_read_only_mode_sites as $site_read_only_mode_site ) {
			switch_to_blog( $site_read_only_mode_site );
			site_read_only_mode_uninstall_site();
			restore_current_blog();
		}

		$site_read_only_mode_offset += 100;
	} while ( $site_read_only_mode_sites );
} else {
	site_read_only_mode_uninstall_site();
}
