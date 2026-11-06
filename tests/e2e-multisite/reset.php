<?php
// Puts the network and every site back to "never frozen": run with `wp eval` before the suite.
foreach ( array( 'axle_read_only_mode_network_state', 'axle_read_only_mode_network_history', 'axle_read_only_mode_network_settings' ) as $option ) {
	delete_site_option( $option );
}
foreach ( get_sites( array( 'fields' => 'ids', 'number' => 100 ) ) as $id ) {
	switch_to_blog( $id );
	foreach ( array( 'state', 'log', 'history', 'settings' ) as $name ) {
		delete_option( 'axle_read_only_mode_' . $name );
	}
	restore_current_blog();
}
