<?php
/**
 * Removes Axle Read-Only Mode's state, settings, log and history, on every site and for the network.
 *
 * @package ReadOnlyMode
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes every Axle Read-Only Mode option from the current site: the freeze,
 * the settings, the log, the history, and the one-time markers and the email
 * rate limit marks that carry the freeze's start time in their names.
 */
function axle_read_only_mode_uninstall_site(): void {
	global $wpdb;

	foreach ( array( 'state', 'log', 'history', 'settings' ) as $name ) {
		delete_option( 'axle_read_only_mode_' . $name );
	}

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup on uninstall.
	foreach ( array( 'axle_read_only_mode_', '_transient_axle_read_only_mode_', '_transient_timeout_axle_read_only_mode_' ) as $prefix ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
	}
	// phpcs:enable
}

/**
 * Removes the network's freeze, settings and history, and every network-wide marker.
 */
function axle_read_only_mode_uninstall_network(): void {
	global $wpdb;

	foreach ( array( 'network_state', 'network_history', 'network_settings' ) as $name ) {
		delete_site_option( 'axle_read_only_mode_' . $name );
	}

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup on uninstall.
	foreach ( array( 'axle_read_only_mode_', '_site_transient_axle_read_only_mode_', '_site_transient_timeout_axle_read_only_mode_' ) as $prefix ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
	}
	// phpcs:enable
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

	axle_read_only_mode_uninstall_network();
} else {
	axle_read_only_mode_uninstall_site();
}
