<?php
/**
 * Plugin Name: Axle Read-Only Mode e2e smoke test stand-in
 * Description: Stands in for Axle Smoke Test's two hooks and a few of its REST routes. Copied into mu-plugins by the freeze-until-green spec.
 *
 * @package ReadOnlyMode
 */

// phpcs:ignoreFile

// Smoke Test listens to this hook and queues a run. Here every ask is only counted.
add_action(
	'axle_smoke_test_run_now',
	static function ( $source ) {
		$asks   = (array) get_option( 'aro_e2e_asks', array() );
		$asks[] = (string) $source;
		update_option( 'aro_e2e_asks', $asks, false );
	}
);

// The runner's own calls, the rollback and one call that must stay frozen.
add_action(
	'rest_api_init',
	static function () {
		foreach ( array( 'results', 'rollback', 'baseline' ) as $leaf ) {
			register_rest_route(
				'axle-smoke-test/v1',
				'/runs/(?P<id>\d+)/' . $leaf,
				array(
					'methods'             => 'POST',
					'permission_callback' => '__return_true',
					'callback'            => static function () use ( $leaf ) {
						return array( 'did' => $leaf );
					},
				)
			);
		}
	}
);
