const { test, expect, wp, wpEval } = require( './utils' );

const SETTINGS = '/wp-admin/tools.php?page=axle-read-only-mode&tab=settings';
const FIXTURE = 'aro-e2e-mail.php';
const EVENTS = [ 'started', 'ended', 'expired', 'extended', 'scheduled_start' ];

const mail = () => JSON.parse( wpEval( 'echo wp_json_encode( get_option( "aro_e2e_mail", array() ) );' ) );

const reset = () => {
	for ( const option of [ 'axle_read_only_mode_state', 'axle_read_only_mode_log', 'axle_read_only_mode_history', 'axle_read_only_mode_settings', 'aro_e2e_mail' ] ) {
		try {
			wp( 'option', 'delete', option );
		} catch ( error ) {
			// Not set.
		}
	}
	wpEval( `foreach ( ${ JSON.stringify( EVENTS ) } as $event ) { delete_transient( 'axle_read_only_mode_mail_' . $event ); } global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'axle\\\\_read\\\\_only\\\\_mode\\\\_%expired\\\\_%' OR option_name LIKE 'axle\\\\_read\\\\_only\\\\_mode\\\\_started\\\\_%'" );` );
};

const turnOn = ( events = EVENTS, to = 'ops@example.com' ) => wp( 'axle-read-only-mode', 'notify', '--enable', `--to=${ to }`, `--events=${ events.join( ',' ) }` );

test.describe( 'Email when a freeze starts or ends', () => {
	test.beforeAll( () => {
		wpEval( `wp_mkdir_p( WPMU_PLUGIN_DIR ); copy( WP_PLUGIN_DIR . '/axle-read-only-mode/tests/e2e/fixtures/${ FIXTURE }', WPMU_PLUGIN_DIR . '/${ FIXTURE }' );` );
	} );

	test.beforeEach( reset );

	test.afterAll( () => {
		reset();
		wpEval( `@unlink( WPMU_PLUGIN_DIR . '/${ FIXTURE }' );` );
	} );

	test( 'no email is sent unless it is turned on', () => {
		wp( 'axle-read-only-mode', 'on', '--minutes=30' );
		wp( 'axle-read-only-mode', 'off' );

		expect( mail() ).toEqual( [] );
		expect( wp( 'axle-read-only-mode', 'notify' ) ).toContain( 'Emails:     off' );
	} );

	test( 'settings: on or off, recipients and events, with bad addresses reported', async ( { page } ) => {
		await page.goto( SETTINGS );
		await expect( page.getByLabel( 'Send an email when a freeze changes' ) ).not.toBeChecked();
		await expect( page.locator( '#aro-notify-recipients' ) ).toHaveAttribute( 'placeholder', 'admin@example.com' );

		await page.getByLabel( 'Send an email when a freeze changes' ).check();
		await page.locator( '#aro-notify-recipients' ).fill( 'ops@example.com\nnot-an-address\npager@example.com' );
		await page.getByLabel( 'A freeze is extended' ).check();
		await page.getByLabel( 'A freeze ends by itself at its end time' ).uncheck();
		await page.getByRole( 'button', { name: 'Save settings' } ).click();

		await expect( page.locator( '.aro-rejected' ) ).toContainText( 'not-an-address' );
		await expect( page.locator( '.aro-rejected' ) ).toContainText( 'Email recipients' );
		expect( JSON.parse( wpEval( 'echo wp_json_encode( get_option( "axle_read_only_mode_settings" )["notify"] );' ) ) ).toEqual( {
			enabled: true,
			recipients: [ 'ops@example.com', 'pager@example.com' ],
			events: [ 'started', 'ended', 'extended' ],
		} );
		await expect( page.getByLabel( 'Send an email when a freeze changes' ) ).toBeChecked();
		await expect( page.locator( '#aro-notify-recipients' ) ).toHaveValue( 'ops@example.com\npager@example.com' );
	} );

	test( 'a freeze says who, why and until when; its end says how much was blocked and links to the list', async ( { rest } ) => {
		turnOn( [ 'started', 'ended', 'extended' ] );

		wp( 'axle-read-only-mode', 'on', '--minutes=30', '--reason=Moving servers' );
		await rest( 'POST', '/wp/v2/posts/1', { title: 'Blocked' } );
		wp( 'axle-read-only-mode', 'extend', '15' );
		wp( 'axle-read-only-mode', 'off' );

		const sent = mail();
		expect( sent.map( ( email ) => email.subject.replace( /^\[[^\]]+\] /, '' ) ) ).toEqual( [ 'Freeze started', 'Freeze extended', 'Freeze ended' ] );
		expect( sent.every( ( email ) => 1 === email.to.length && 'ops@example.com' === email.to[ 0 ] ) ).toBe( true );

		expect( sent[ 0 ].message ).toContain( 'Why: Moving servers' );
		expect( sent[ 0 ].message ).toMatch( /Until: .+\(\+00:00\)/ );
		expect( sent[ 0 ].message ).toContain( 'Who: WP-CLI' );
		expect( sent[ 1 ].message ).toContain( 'Added: 15 minutes' );
		expect( sent[ 2 ].message ).toContain( 'Attempts blocked: 1' );
		expect( sent[ 2 ].message ).toContain( 'tools.php?page=axle-read-only-mode' );
		expect( sent[ 2 ].message ).toContain( 'Ended by: WP-CLI' );
	} );

	test( 'the same event sends at most one email a minute', () => {
		turnOn( [ 'started', 'ended' ] );

		for ( let i = 0; i < 3; i++ ) {
			wp( 'axle-read-only-mode', 'on', '--minutes=30' );
			wp( 'axle-read-only-mode', 'off' );
		}

		expect( mail().map( ( email ) => email.subject.replace( /^\[[^\]]+\] /, '' ) ) ).toEqual( [ 'Freeze started', 'Freeze ended' ] );
	} );

	test( 'a freeze that reaches its end time sends one email when the next request notices', async ( { page } ) => {
		turnOn( [ 'expired', 'ended' ] );
		wpEval( 'AryanX\\Axle\\ReadOnlyMode\\Plugin::instance()->freezer()->freeze( array( "start" => time() - 7200, "minutes" => 60, "reason" => "Old deploy" ) );' );
		await page.goto( '/' );
		await page.goto( '/' );

		const sent = mail();
		expect( sent ).toHaveLength( 1 );
		expect( sent[ 0 ].subject ).toContain( 'Freeze ended at its end time' );
		expect( sent[ 0 ].message ).toContain( 'Ended by: Nobody: it ended by itself' );
		expect( sent[ 0 ].message ).toContain( 'Why: Old deploy' );
	} );

	test( 'a freeze set for later sends its email when it starts', async ( { page } ) => {
		turnOn( [ 'scheduled_start', 'started' ] );
		wpEval( 'AryanX\\Axle\\ReadOnlyMode\\Plugin::instance()->freezer()->freeze( array( "start" => time() + 3600, "minutes" => 30, "reason" => "Release night" ) );' );
		await page.goto( '/' );
		expect( mail() ).toEqual( [] );

		// The hour passes.
		wpEval( '$s = get_option( "axle_read_only_mode_state" ); $s["start"] = time() - 5; $s["end"] = time() + 1800; update_option( "axle_read_only_mode_state", $s );' );
		await page.goto( '/' );
		await page.goto( '/' );

		const sent = mail();
		expect( sent ).toHaveLength( 1 );
		expect( sent[ 0 ].subject ).toContain( 'Scheduled freeze started' );
		expect( sent[ 0 ].message ).toContain( 'Why: Release night' );
	} );

	test( 'WP-CLI turns emails on and off and lists the settings', () => {
		expect( () => wp( 'axle-read-only-mode', 'notify', '--events=started,nonsense' ) ).toThrow( /Unknown event "nonsense"/ );

		const status = wp( 'axle-read-only-mode', 'notify', '--enable', '--to=a@example.com,b@example.com', '--events=started,ended' );
		expect( status ).toContain( 'Emails:     on' );
		expect( status ).toContain( 'Recipients: a@example.com, b@example.com' );
		expect( status ).toContain( 'Events:     started, ended' );

		expect( wp( 'axle-read-only-mode', 'notify', '--disable', '--to=' ) ).toContain( 'Recipients: the admin email' );
		expect( wp( 'axle-read-only-mode', 'notify' ) ).toContain( 'Emails:     off' );
	} );
} );
