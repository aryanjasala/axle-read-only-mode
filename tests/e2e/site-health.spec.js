const { test, expect, wp, wpEval } = require( './utils' );

const HEALTH = '/wp-admin/site-health.php';
const FIXTURE = 'aro-e2e-constant.php';

const reset = () => {
	for ( const option of [ 'axle_read_only_mode_state', 'axle_read_only_mode_log', 'axle_read_only_mode_history', 'axle_read_only_mode_settings', 'aro_e2e_constant' ] ) {
		try {
			wp( 'option', 'delete', option );
		} catch ( error ) {
			// Not set.
		}
	}
	wpEval( 'global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE \'axle\\\\_read\\\\_only\\\\_mode\\\\_expired\\\\_%\'" );' );
};

// A freeze the plugin did not end, written straight into the option the way a stuck site would have it.
const stuck = ( start, end ) =>
	wpEval( `update_option( "axle_read_only_mode_expired_${ start }", time() ); update_option( "axle_read_only_mode_state", array( "active" => true, "start" => ${ start }, "end" => ${ end }, "reason" => "Forgotten deploy", "by" => 1 ) );` );

// The critical check exits with status 2, so the text is on the error.
const criticalOutput = () => {
	try {
		wp( 'axle-read-only-mode', 'health' );
	} catch ( error ) {
		expect( error.status ).toBe( 2 );
		return String( error.stdout );
	}
	throw new Error( 'The health command should have failed.' );
};

// What Site Health shows for this plugin, by section.
async function results( page ) {
	await page.goto( HEALTH );
	await page.locator( '#health-check-issues-good, #health-check-issues-recommended, #health-check-issues-critical' ).first().waitFor( { state: 'attached' } );
	await expect( page.locator( '.site-health-progress-wrapper' ) ).not.toHaveClass( /loading/, { timeout: 30000 } );
}

test.describe( 'Site Health', () => {
	test.beforeAll( () => {
		wpEval( `wp_mkdir_p( WPMU_PLUGIN_DIR ); copy( WP_PLUGIN_DIR . '/axle-read-only-mode/tests/e2e/fixtures/${ FIXTURE }', WPMU_PLUGIN_DIR . '/${ FIXTURE }' );` );
	} );

	test.beforeEach( reset );

	test.afterAll( () => {
		reset();
		wpEval( `@unlink( WPMU_PLUGIN_DIR . '/${ FIXTURE }' );` );
	} );

	test( 'good when the site is not frozen', async ( { page } ) => {
		await results( page );

		await expect( page.locator( '#health-check-issues-good' ) ).toContainText( 'The site is not read-only' );
		await expect( page.locator( '#health-check-issues-critical, #health-check-issues-recommended' ).filter( { hasText: 'read-only' } ) ).toHaveCount( 0 );
		expect( wp( 'axle-read-only-mode', 'health' ) ).toContain( 'GOOD: The site is not read-only' );
	} );

	test( 'recommended while a freeze with an end time is running', async ( { page } ) => {
		wp( 'axle-read-only-mode', 'on', '--minutes=30', '--reason=Moving servers' );

		await results( page );

		const recommended = page.locator( '#health-check-issues-recommended' );
		await expect( recommended ).toContainText( 'The site is read-only' );
		await recommended.getByRole( 'button', { name: /The site is read-only/ } ).click();
		await expect( recommended ).toContainText( 'Maintenance' );
		await expect( recommended ).toContainText( 'This is expected during a migration or deploy.' );
		await expect( recommended.getByRole( 'link', { name: 'Open Read-Only Mode' } ) ).toHaveAttribute( 'href', /tools\.php\?page=axle-read-only-mode/ );
		expect( wp( 'axle-read-only-mode', 'health' ) ).toContain( 'RECOMMENDED: The site is read-only' );
	} );

	test( 'critical when a freeze ran past its end time', async ( { page } ) => {
		const now = Math.floor( Date.now() / 1000 );
		stuck( now - 4 * 3600, now - 2 * 3600 );

		await results( page );

		await expect( page.locator( '#health-check-issues-critical' ) ).toContainText( 'A freeze ran past its end time' );
		expect( criticalOutput() ).toContain( 'CRITICAL: A freeze ran past its end time' );
	} );

	test( 'critical when a freeze with no end time has lasted more than six hours', async ( { page } ) => {
		const now = Math.floor( Date.now() / 1000 );

		stuck( now - 3 * 3600, 0 );
		await results( page );
		await expect( page.locator( '#health-check-issues-recommended' ) ).toContainText( 'The site is read-only' );

		stuck( now - 7 * 3600, 0 );
		await results( page );
		await expect( page.locator( '#health-check-issues-critical' ) ).toContainText( 'A freeze with no end time has been on for hours' );
	} );

	test( 'critical when wp-config.php forces the freeze', async ( { page } ) => {
		wp( 'option', 'update', 'aro_e2e_constant', '1' );

		await results( page );

		await expect( page.locator( '#health-check-issues-critical' ) ).toContainText( 'wp-config.php forces the site read-only' );
		expect( criticalOutput() ).toContain( 'CRITICAL: wp-config.php forces the site read-only' );
	} );

	test( 'the info tab lists the state, end time, allow-list counts and email settings', async ( { page } ) => {
		wp( 'axle-read-only-mode', 'allow', 'rest', '/wc/v3/webhooks' );
		wp( 'axle-read-only-mode', 'allow', 'ajax', 'my_action' );
		wp( 'axle-read-only-mode', 'notify', '--enable', '--to=ops@example.com', '--events=started,ended' );
		wp( 'axle-read-only-mode', 'on', '--minutes=30' );

		await page.goto( `${ HEALTH }?tab=debug` );
		const section = page.locator( '#health-check-accordion-block-axle-read-only-mode' );
		await expect( section ).toBeAttached();
		await expect( section ).toContainText( 'Frozen' );
		await expect( section ).toContainText( 'This site' );
		await expect( section ).toContainText( '1 REST routes, 0 paths or query arguments, 1 ajax actions, 0 users' );
		await expect( section ).toContainText( 'On, 1 recipients (started, ended)' );
	} );
} );
