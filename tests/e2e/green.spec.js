const { test, expect, wp, wpEval } = require( './utils' );

const PAGE = '/wp-admin/tools.php?page=axle-read-only-mode';
const MU = 'aro-e2e-smoketest.php';

const reset = () => {
	for ( const option of [ 'axle_read_only_mode_state', 'axle_read_only_mode_log', 'axle_read_only_mode_history', 'axle_read_only_mode_settings', 'aro_e2e_asks' ] ) {
		try {
			wp( 'option', 'delete', option );
		} catch ( error ) {
			// Not set.
		}
	}
	wpEval( 'global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE \'axle_read_only_mode_expired_%\' OR option_name LIKE \'axle_read_only_mode_started_%\' OR option_name LIKE \'axle_read_only_mode_greentimeout_%\'" ); @unlink( WPMU_PLUGIN_DIR . "/' + MU + '" );' );
};

const smokeTestOn = () => wpEval( `wp_mkdir_p( WPMU_PLUGIN_DIR ); copy( WP_PLUGIN_DIR . '/axle-read-only-mode/tests/e2e/fixtures/${ MU }', WPMU_PLUGIN_DIR . '/${ MU }' );` );

const state = () => JSON.parse( wpEval( 'echo wp_json_encode( get_option( "axle_read_only_mode_state" ) );' ) );
const asks = () => JSON.parse( wpEval( 'echo wp_json_encode( array_values( (array) get_option( "aro_e2e_asks", array() ) ) );' ) );

// What Smoke Test fires when a run is done.
const finish = ( verdict, id, source = 'axle-read-only-mode' ) =>
	wpEval( `do_action( 'axle_smoke_test_run_finished', array( 'id' => ${ id }, 'verdict' => '${ verdict }', 'source' => '${ source }', 'finished' => time() ) );` );

test.describe( 'Freeze until green', () => {
	test.beforeEach( reset );
	test.afterAll( reset );

	test( 'the option is greyed out with a line while Smoke Test is not active', async ( { page } ) => {
		await page.goto( PAGE );
		const box = page.getByLabel( 'The smoke test passes' );
		await expect( box ).toBeDisabled();
		await expect( page.locator( '#aro-until-green-help' ) ).toContainText( 'Needs Axle Smoke Test' );

		let failed = false;
		try {
			wp( 'axle-read-only-mode', 'on', '--until-green' );
		} catch ( error ) {
			failed = true;
			expect( String( error.stderr ) ).toContain( 'needs Axle Smoke Test' );
		}
		expect( failed ).toBe( true );
		expect( wp( 'axle-read-only-mode', 'status' ) ).toBe( 'Not frozen.' );
	} );

	test( 'start, wait, stay frozen on red, end on green', async ( { page } ) => {
		smokeTestOn();
		await page.goto( PAGE );
		await page.getByLabel( 'Reason' ).fill( 'Release night' );
		await page.getByLabel( 'The smoke test passes' ).check();
		await expect( page.locator( '#aro-minutes' ) ).toBeDisabled();
		await page.getByRole( 'button', { name: 'Freeze the site' } ).click();

		// Waiting: the card says so, the run was asked for, and the freeze has no end time.
		await expect( page.locator( '.aro-status .axle-badge' ).first() ).toHaveText( 'Waiting for the smoke test' );
		await expect( page.locator( '.aro-green' ) ).toContainText( 'ends by itself when the smoke test passes' );
		await expect( page.locator( '.aro-green a' ) ).toHaveAttribute( 'href', /page=axle-smoke-test/ );
		expect( asks() ).toEqual( [ 'axle-read-only-mode' ] );
		expect( state().until_green ).toBe( true );
		expect( state().end ).toBe( 0 );
		expect( state().green_deadline - state().start ).toBe( 7200 );
		expect( wp( 'axle-read-only-mode', 'status' ) ).toContain( 'Waiting for smoke test' );
		await expect( page.locator( '#wp-admin-bar-axle-read-only-mode > .ab-item' ) ).toContainText( 'Frozen until the smoke test passes' );

		// A run that Smoke Test started for another reason does not end it.
		finish( 'green', 3, 'wp-update' );
		await page.reload();
		await expect( page.locator( '.aro-status .axle-badge' ).first() ).toHaveText( 'Waiting for the smoke test' );

		// Red keeps the freeze and says why.
		finish( 'red', 4 );
		await page.reload();
		await expect( page.locator( '.aro-status .axle-badge' ).first() ).toHaveText( 'Smoke test red' );
		await expect( page.locator( '.aro-green' ) ).toContainText( 'The last smoke test was red (run 4)' );
		await expect( page.locator( '.aro-green a' ) ).toHaveAttribute( 'href', /run=4/ );
		await expect( page.locator( '#wp-admin-bar-axle-read-only-mode > .ab-item' ) ).toContainText( 'smoke test not green' );
		expect( state().active ).toBe( true );
		expect( state().green_run.verdict ).toBe( 'red' );

		// Asking again clears the old verdict and waits for the new run.
		await page.getByRole( 'button', { name: 'Run the smoke test again' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Asked Axle Smoke Test for another run' );
		await expect( page.locator( '.aro-status .axle-badge' ).first() ).toHaveText( 'Waiting for the smoke test' );
		expect( asks() ).toHaveLength( 2 );

		// Green ends it.
		finish( 'green', 5 );
		await page.reload();
		await expect( page.locator( '.axle-status .axle-badge' ) ).toContainText( 'Not frozen' );
		expect( state().active ).toBe( false );
		await expect( page.locator( '.aro-history tbody tr' ).first() ).toContainText( 'Smoke test passed' );
		expect( wp( 'axle-read-only-mode', 'status' ) ).toBe( 'Not frozen.' );
	} );

	test( 'the smoke test can run and roll back inside the freeze, nothing else gets through', async ( { rest } ) => {
		smokeTestOn();
		wp( 'axle-read-only-mode', 'on', '--until-green' );

		// While waiting, the runner may post results, the rollback and the baseline stay frozen.
		expect( ( await rest( 'POST', '/axle-smoke-test/v1/runs/4/results', {} ) ).status ).toBe( 200 );
		expect( ( await rest( 'POST', '/axle-smoke-test/v1/runs/4/rollback', {} ) ).status ).toBe( 423 );
		expect( ( await rest( 'POST', '/axle-smoke-test/v1/runs/4/baseline', {} ) ).status ).toBe( 423 );
		expect( ( await rest( 'POST', '/wp/v2/posts', { title: 'x' } ) ).status ).toBe( 423 );

		finish( 'red', 4 );
		expect( ( await rest( 'POST', '/axle-smoke-test/v1/runs/4/rollback', {} ) ).status ).toBe( 200 );
		expect( ( await rest( 'POST', '/axle-smoke-test/v1/runs/4/baseline', {} ) ).status ).toBe( 423 );
		expect( ( await rest( 'POST', '/wp/v2/posts', { title: 'x' } ) ).status ).toBe( 423 );
		wp( 'axle-read-only-mode', 'off' );
	} );

	test( 'a stuck run cannot hold the site: the hard limit ends it with a warning', async ( { page } ) => {
		smokeTestOn();
		wp( 'axle-read-only-mode', 'on', '--until-green' );
		wpEval( '$s = get_option( "axle_read_only_mode_state" ); $s["green_deadline"] = time() - 5; update_option( "axle_read_only_mode_state", $s );' );

		await page.goto( PAGE );
		await expect( page.locator( '.axle-status .axle-badge' ) ).toContainText( 'Not frozen' );
		await expect( page.locator( '.notice-warning:not(.update-nag)' ) ).toContainText( 'ended at its hard limit without a green smoke test' );
		await expect( page.locator( '.aro-history tbody tr' ).first() ).toContainText( 'Hard limit, no green run' );
		expect( state().active ).toBe( false );
	} );

	test( 'the hard limit can be changed with a filter', async () => {
		smokeTestOn();
		wpEval( 'update_option( "aro_e2e_max", 15 );' );
		wpEval( 'file_put_contents( WPMU_PLUGIN_DIR . "/aro-e2e-max.php", "<?php add_filter( \'axle_read_only_mode_green_max_minutes\', static function () { return (int) get_option( \'aro_e2e_max\', 120 ); } );" );' );
		try {
			wp( 'axle-read-only-mode', 'on', '--until-green' );
			expect( state().green_deadline - state().start ).toBe( 900 );
		} finally {
			wp( 'axle-read-only-mode', 'off' );
			wpEval( '@unlink( WPMU_PLUGIN_DIR . "/aro-e2e-max.php" ); delete_option( "aro_e2e_max" );' );
		}
	} );

	test( 'the ability starts it, reports it and can ask again', async () => {
		smokeTestOn();
		const out = JSON.parse(
			wpEval( 'echo wp_json_encode( AryanX\\Axle\\ReadOnlyMode\\Plugin::instance()->freezer() ? ( function () { $a = new AryanX\\Axle\\ReadOnlyMode\\Abilities( AryanX\\Axle\\ReadOnlyMode\\Plugin::instance()->state(), AryanX\\Axle\\ReadOnlyMode\\Plugin::instance()->freezer(), new AryanX\\Axle\\ReadOnlyMode\\BlockedLog( new AryanX\\Axle\\ReadOnlyMode\\History() ), null, null, AryanX\\Axle\\ReadOnlyMode\\Plugin::instance()->green() ); $a->freeze( array( "until_green" => true ) ); $r = $a->retest(); return $r; } )() : null );' )
		);
		expect( out.until_green ).toBe( true );
		expect( out.green_status ).toBe( 'waiting' );
		expect( out.green_deadline ).not.toBe( '' );
		expect( asks() ).toHaveLength( 2 );
		wp( 'axle-read-only-mode', 'off' );
	} );

	test( 'the classic WooCommerce checkout hears the visitor message, not "Service Unavailable"', async ( { playwright } ) => {
		wp( 'axle-read-only-mode', 'on', '--minutes=30', '--visitor-message=Back after the move.' );
		const visitor = await playwright.request.newContext( { baseURL: process.env.WP_BASE_URL || `http://localhost:${ process.env.WP_PORT || 8911 }`, storageState: { cookies: [], origins: [] } } );
		const checkout = await visitor.post( '/?wc-ajax=checkout', { headers: { Accept: 'application/json' }, form: { billing_email: 'a@example.com' } } );
		expect( checkout.status() ).toBe( 200 );
		const body = await checkout.json();
		expect( body.result ).toBe( 'failure' );
		expect( body.messages ).toContain( 'Back after the move.' );

		// Any other front-end post keeps core's answer.
		const other = await visitor.post( '/?wc-ajax=update_order_review', { headers: { Accept: 'application/json' }, form: {} } );
		expect( other.status() ).toBe( 503 );
		await visitor.dispose();
		wp( 'axle-read-only-mode', 'off' );
	} );

	test( 'the deploy-script commands live in the Help tab, not on the Freeze tab', async ( { page } ) => {
		await page.goto( PAGE );
		await expect( page.locator( '.aro-cli' ) ).toHaveCount( 0 );
		await page.locator( '#contextual-help-link' ).click();
		await page.locator( '#tab-link-axle-read-only-mode-cli a' ).click();
		await expect( page.locator( '#tab-panel-axle-read-only-mode-cli' ) ).toContainText( 'wp axle-read-only-mode on --until-green' );
	} );

	test( 'the admin bar shows the lock on a phone', async ( { page } ) => {
		wp( 'axle-read-only-mode', 'on', '--minutes=30' );
		await page.setViewportSize( { width: 390, height: 800 } );
		await page.goto( '/wp-admin/index.php' );
		const node = page.locator( '#wp-admin-bar-axle-read-only-mode' );
		await expect( node ).toBeVisible();
		await expect( node.locator( '.ab-icon' ) ).toBeVisible();
		await expect( node.locator( '.ab-item' ).first() ).toContainText( 'Frozen' );
		wp( 'axle-read-only-mode', 'off' );
	} );
} );
