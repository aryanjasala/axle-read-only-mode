<?php
/**
 * Plugin Name: Axle Read-Only Mode e2e constant
 * Description: Defines AXLE_READ_ONLY_MODE the way wp-config.php would, while an option is set. Copied into mu-plugins by the Site Health spec.
 *
 * @package ReadOnlyMode
 */

// phpcs:ignoreFile

if ( function_exists( 'get_option' ) ) {
	// mu-plugins load before the options cache is ready on some setups, so read the row directly.
	global $wpdb;
	$aro_e2e_on = $wpdb && $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'aro_e2e_constant'" );
	if ( $aro_e2e_on ) {
		define( 'AXLE_READ_ONLY_MODE', true );
	}
}
