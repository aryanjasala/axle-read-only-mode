<?php
/**
 * Plugin Name: Axle Read-Only Mode e2e mail catcher
 * Description: Keeps every wp_mail() in an option instead of sending it. Copied into mu-plugins by the notifications spec.
 *
 * @package ReadOnlyMode
 */

// phpcs:ignoreFile

add_filter(
	'pre_wp_mail',
	static function ( $short_circuit, $atts ) {
		$mail   = get_option( 'aro_e2e_mail', array() );
		$mail[] = array(
			'to'      => (array) $atts['to'],
			'subject' => $atts['subject'],
			'message' => $atts['message'],
		);
		update_option( 'aro_e2e_mail', $mail, false );

		return true;
	},
	10,
	2
);
