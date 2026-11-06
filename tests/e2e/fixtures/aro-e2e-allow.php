<?php
/**
 * Plugin Name: Axle Read-Only Mode e2e allow-list
 * Description: Test switches for the allow-list tests. Copied into mu-plugins by the allow-list spec.
 *
 * @package ReadOnlyMode
 */

// phpcs:ignoreFile

// Code can still say no to a request the allow-list lets through.
foreach ( array( 'axle_read_only_mode_allow_request', 'axle_read_only_mode_allow_rest_request' ) as $hook ) {
	add_filter(
		$hook,
		static function ( $allow ) {
			return get_option( 'aro_e2e_deny' ) ? false : $allow;
		}
	);
}

// Code can say yes to a request the allow-list does not know.
add_filter(
	'axle_read_only_mode_allow_request',
	static function ( $allow, $where ) {
		return $allow || ( get_option( 'aro_e2e_code_allow' ) && false !== strpos( $where, '/code-allowed' ) );
	},
	10,
	2
);

// A plugin that is "active" and suggests an entry.
add_filter(
	'axle_read_only_mode_allow_suggestions',
	static function ( $suggestions ) {
		if ( get_option( 'aro_e2e_suggest' ) ) {
			$suggestions[] = array(
				'plugin' => 'Shop plugin',
				'type'   => 'paths',
				'value'  => '?shop-api=',
				'why'    => 'Payment callbacks.',
			);
		}

		return $suggestions;
	}
);
