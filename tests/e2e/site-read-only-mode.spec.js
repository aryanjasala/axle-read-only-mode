const { test, expect, wp, wpEval, openEditor } = require( './utils' );

const PAGE = '/wp-admin/tools.php?page=site-read-only-mode';

const reset = () => {
	for ( const option of [ 'site_read_only_mode_state', 'site_read_only_mode_log', 'site_read_only_mode_history' ] ) {
		try {
			wp( 'option', 'delete', option );
		} catch ( error ) {
			// Not set.
		}
	}
};

test.describe( 'Site Read-Only Mode', () => {
	let postId;

	test.beforeAll( () => {
		postId = Number( wp( 'post', 'create', '--post_title=Freeze test post', '--post_status=publish', '--post_content=<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->', '--porcelain' ) );
	} );

	test.beforeEach( reset );

	test.afterAll( () => {
		reset();
		wp( 'post', 'delete', String( postId ), '--force' );
	} );

	test( 'freezing from the screen locks editing, the REST API and settings', async ( { page, rest } ) => {
		await page.goto( PAGE );
		await page.locator( '#sro-reason' ).fill( 'Moving servers' );
		await page.locator( '#sro-minutes' ).selectOption( '60' );
		await page.getByRole( 'button', { name: 'Freeze the site' } ).click();

		await expect( page.locator( '.sro-status h2' ) ).toHaveText( 'The site is frozen' );
		await expect( page.locator( '.sro-reason' ) ).toHaveText( 'Moving servers' );
		await expect( page.locator( '.sro-countdown' ) ).toHaveText( /^Ends in (59|60):\d\d$/ );
		await expect( page.locator( '#wp-admin-bar-site-read-only-mode' ) ).toContainText( 'Frozen until' );

		await openEditor( page, postId );
		await expect( page.locator( '.components-notice' ).filter( { hasText: 'This site is frozen for maintenance' } ) ).toBeVisible();
		expect( await page.evaluate( () => window.wp.data.select( 'core/editor' ).isPostSavingLocked() ) ).toBe( true );

		const save = await rest( 'POST', `/wp/v2/posts/${ postId }`, { title: 'Changed during freeze' } );
		expect( save.status ).toBe( 423 );
		expect( save.body.code ).toBe( 'site_read_only_mode' );

		await page.goto( '/wp-admin/options-general.php' );
		await expect( page.locator( '.site-read-only-mode-notice' ) ).toContainText( 'Reason: Moving servers' );
		const response = await Promise.all( [ page.waitForResponse( ( r ) => r.url().endsWith( '/wp-admin/options.php' ) ), page.locator( '#submit' ).click() ] );
		expect( response[ 0 ].status() ).toBe( 423 );
		await expect( page.locator( 'body' ) ).toContainText( 'This site is frozen for maintenance' );

		expect( wp( 'post', 'get', String( postId ), '--field=post_title' ) ).toBe( 'Freeze test post' );
	} );

	test( 'visitors can read the site but get the visitor message when they post', async ( { browser } ) => {
		wp( 'site-read-only-mode', 'on', '--minutes=30', '--visitor-message=We are moving servers. Back by 3 pm.' );

		const visitor = await browser.newContext( { storageState: { cookies: [], origins: [] } } );
		const page = await visitor.newPage();

		const home = await page.goto( '/' );
		expect( home.status() ).toBe( 200 );

		const post = await page.request.post( '/wp-comments-post.php', { form: { comment: 'Hi', comment_post_ID: String( postId ), author: 'Sam', email: 'sam@example.com' } } );
		expect( post.status() ).toBe( 503 );
		expect( Number( post.headers()[ 'retry-after' ] ) ).toBeGreaterThan( 1500 );
		expect( await post.text() ).toContain( 'We are moving servers. Back by 3 pm.' );

		const restComment = await page.request.post( '/index.php?rest_route=/wp/v2/comments', { data: { post: postId, content: 'Hi', author_name: 'Sam', author_email: 'sam@example.com' } } );
		expect( restComment.status() ).toBe( 423 );

		const login = await page.request.post( '/wp-login.php', { form: { log: 'nobody', pwd: 'wrong' } } );
		expect( login.status() ).toBe( 200 );
		await visitor.close();
	} );

	test( 'blocked attempts are listed, the freeze can be extended, and unfreezing records history', async ( { page, rest } ) => {
		wp( 'site-read-only-mode', 'on', '--minutes=30', '--reason=DB swap' );
		await rest( 'POST', `/wp/v2/posts/${ postId }`, { title: 'Blocked edit' } );

		await page.goto( PAGE );
		const blocked = page.locator( '.sro-blocked' );
		await expect( blocked.locator( 'h2' ) ).toHaveText( 'Blocked during this freeze (1)' );
		await expect( blocked ).toContainText( `REST POST /wp/v2/posts/${ postId }` );
		await expect( blocked ).toContainText( 'admin' );

		const before = Number( wpEval( 'echo get_option( "site_read_only_mode_state" )["end"];' ) );
		await page.getByRole( 'button', { name: '+1 hour' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Freeze extended.' );
		expect( Number( wpEval( 'echo get_option( "site_read_only_mode_state" )["end"];' ) ) ).toBe( before + 3600 );

		await page.getByRole( 'button', { name: 'Unfreeze now' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'The site is unfrozen.' );
		const history = page.locator( '.sro-history tbody tr' ).first();
		await expect( history ).toContainText( 'DB swap' );
		await expect( history ).toContainText( 'Unfrozen' );
		await expect( history.locator( '.num' ) ).toHaveText( '1' );
		await expect( page.locator( '.sro-blocked h2' ) ).toHaveText( 'Blocked during the last freeze (1)' );
	} );

	test( 'bots cannot flood the log, and the log takes part in the privacy tools', async ( { browser, rest } ) => {
		wp( 'site-read-only-mode', 'on', '--minutes=30' );
		const visitor = await browser.newContext( { storageState: { cookies: [], origins: [] } } );
		const page = await visitor.newPage();
		for ( let i = 0; i < 14; i++ ) {
			const post = await page.request.post( `/wp-comments-post.php?email=sam${ i }@example.com`, { form: { comment: 'Hi', comment_post_ID: String( postId ), author: 'Sam', email: `sam${ i }@example.com` } } );
			expect( post.status() ).toBe( 503 );
		}
		await visitor.close();
		await rest( 'POST', `/wp/v2/posts/${ postId }`, { title: 'Blocked edit' } );

		const log = JSON.parse( wpEval( 'echo wp_json_encode( get_option( "site_read_only_mode_log" ) );' ) );
		expect( log.filter( ( entry ) => ! entry.user ) ).toHaveLength( 10 );
		expect( log.map( ( entry ) => entry.where ).join( ' ' ) ).not.toContain( '?email=' );
		expect( log[ 0 ] ).toMatchObject( { user: 1, source: 'rest' } );

		const privacy = ( tool ) =>
			JSON.parse(
				wpEval( `
					$tools = apply_filters( 'wp_privacy_personal_data_${ tool }s', array() );
					echo wp_json_encode( call_user_func( $tools['site-read-only-mode']['callback'], 'admin@example.com', 1 ) );
				` )
			);
		const exported = privacy( 'exporter' );
		expect( exported.data.filter( ( item ) => item.group_id === 'site-read-only-mode-blocked' ) ).toHaveLength( 1 );
		expect( privacy( 'eraser' ) ).toMatchObject( { items_removed: true, done: true } );
		expect( wp( 'site-read-only-mode', 'log', '--format=json' ) ).toContain( '(removed)' );
	} );

	test( 'abilities: an agent can check, freeze and unfreeze, and other abilities that write are refused', async ( { rest } ) => {
		const run = ( name, method, input ) => {
			const path = `/wp-abilities/v1/abilities/site-read-only-mode/${ name }/run`;
			if ( 'GET' === method ) {
				const query = input ? Object.entries( input ).map( ( [ key, value ] ) => `input[${ key }]=${ encodeURIComponent( value ) }` ).join( '&' ) : '';
				return rest( 'GET', query ? `${ path }?${ query }` : path );
			}
			return rest( method, path, input ? { input } : {} );
		};

		const listed = await rest( 'GET', '/wp-abilities/v1/abilities?category=site-read-only-mode' );
		expect( listed.status ).toBe( 200 );
		expect( listed.body.map( ( ability ) => ability.name ).sort() ).toEqual( [ 'site-read-only-mode/extend', 'site-read-only-mode/freeze', 'site-read-only-mode/get-blocked', 'site-read-only-mode/get-status', 'site-read-only-mode/unfreeze' ] );
		// MCP clients only list abilities marked public.
		expect( JSON.parse( wpEval( `echo wp_json_encode( array_values( array_filter( array_map( function ( $ability ) { $meta = $ability->get_meta(); return ( ! empty( $meta['public'] ) && ! empty( $meta['mcp']['public'] ) ) ? '' : $ability->get_name(); }, wp_get_abilities() ), function ( $name ) { return 0 === strpos( $name, 'site-read-only-mode/' ); } ) ) );` ) ) ).toEqual( [] );

		expect( ( await run( 'get-status', 'GET' ) ).body ).toMatchObject( { frozen: false } );

		const frozen = await run( 'freeze', 'POST', { minutes: 30, reason: 'Agent deploy' } );
		expect( frozen.status ).toBe( 200 );
		expect( frozen.body ).toMatchObject( { frozen: true, reason: 'Agent deploy', visitors_paused: true } );
		expect( frozen.body.seconds_left ).toBeGreaterThan( 1700 );

		const extended = await run( 'extend', 'POST', { minutes: 15 } );
		expect( extended.body.seconds_left ).toBeGreaterThan( 2600 );

		// Another plugin's abilities over WP-CLI, the way the MCP adapter runs them: reading works, writing does not.
		const other = JSON.parse(
			wpEval( `
				add_action( 'wp_abilities_api_categories_init', function () {
					wp_register_ability_category( 'e2e', array( 'label' => 'E2E', 'description' => 'Test abilities.' ) );
				} );
				add_action( 'wp_abilities_api_init', function () {
					foreach ( array( 'read' => true, 'write' => false ) as $name => $readonly ) {
						wp_register_ability( 'e2e/' . $name, array(
							'label'               => $name,
							'description'         => 'Test ability.',
							'category'            => 'e2e',
							'output_schema'       => array( 'type' => 'string' ),
							'execute_callback'    => function () use ( $name ) { return $name . ' ran'; },
							'permission_callback' => '__return_true',
							'meta'                => array( 'annotations' => array( 'readonly' => $readonly ) ),
						) );
					}
				} );
				// A stand-in for the MCP adapter's execute tool, which runs other abilities.
				add_action( 'wp_abilities_api_categories_init', function () {
					wp_register_ability_category( 'mcp-adapter', array( 'label' => 'MCP', 'description' => 'Stand-in.' ) );
				} );
				add_action( 'wp_abilities_api_init', function () {
					wp_register_ability( 'mcp-adapter/execute-ability', array(
						'label'               => 'Execute',
						'description'         => 'Runs another ability.',
						'category'            => 'mcp-adapter',
						'input_schema'        => array( 'type' => 'object', 'properties' => array( 'ability_name' => array( 'type' => 'string' ) ) ),
						'execute_callback'    => function ( $input ) {
							$result = wp_get_ability( $input['ability_name'] )->execute();
							return is_wp_error( $result ) ? $result->get_error_code() : $result;
						},
						'permission_callback' => '__return_true',
						'meta'                => array( 'annotations' => array( 'readonly' => false ) ),
					) );
				} );
				$write = wp_get_ability( 'e2e/write' )->execute();
				$dispatch = function ( $name ) {
					$result = wp_get_ability( 'mcp-adapter/execute-ability' )->execute( array( 'ability_name' => $name ) );
					return is_wp_error( $result ) ? 'wrapper refused' : $result;
				};
				echo wp_json_encode( array(
					'read'            => wp_get_ability( 'e2e/read' )->execute(),
					'write'           => is_wp_error( $write ) ? $write->get_error_code() : $write,
					'dispatched_read' => $dispatch( 'e2e/read' ),
					'dispatched_write' => $dispatch( 'e2e/write' ),
				) );
			` )
		);
		// Through a dispatcher such as the MCP adapter, the ability inside is what gets checked.
		expect( other ).toEqual( { read: 'read ran', write: 'site_read_only_mode', dispatched_read: 'read ran', dispatched_write: 'site_read_only_mode' } );

		const blocked = await run( 'get-blocked', 'GET', { limit: 5 } );
		expect( blocked.body.map( ( entry ) => entry.where ) ).toContain( 'ability e2e/write' );

		// Unfreezing gets through the freeze's own REST guard.
		const unfrozen = await run( 'unfreeze', 'POST' );
		expect( unfrozen.status ).toBe( 200 );
		expect( unfrozen.body ).toMatchObject( { frozen: false } );
		expect( JSON.parse( wpEval( 'echo wp_json_encode( get_option( "site_read_only_mode_history" )[0] );' ) ) ).toMatchObject( { ended_by: 'ability', reason: 'Agent deploy' } );
	} );

	test( 'editor housekeeping keeps working, profile changes stay blocked', async ( { page, rest } ) => {
		wp( 'site-read-only-mode', 'on', '--minutes=10' );

		const nonce = await page.request.get( '/wp-admin/admin-ajax.php?action=rest-nonce' );
		expect( nonce.status() ).toBe( 200 );
		expect( await nonce.text() ).toMatch( /^[0-9a-f]+$/ );

		const preferences = await rest( 'PUT', '/wp/v2/users/me', { meta: { persisted_preferences: { 'core/edit-post': { welcomeGuide: false } } } } );
		expect( preferences.status ).toBe( 200 );

		const email = await rest( 'PUT', '/wp/v2/users/me', { email: 'changed@example.com' } );
		expect( email.status ).toBe( 423 );
		const mixed = await rest( 'PUT', '/wp/v2/users/me', { email: 'changed@example.com', meta: { persisted_preferences: {} } } );
		expect( mixed.status ).toBe( 423 );

		expect( wp( 'user', 'get', 'admin', '--field=user_email' ) ).toBe( 'admin@example.com' );
		expect( wp( 'site-read-only-mode', 'log', '--format=count' ) ).toBe( '2' );
	} );

	test( 'exempt users keep editing', async ( { page, rest } ) => {
		await page.goto( PAGE );
		await page.getByLabel( 'Let me keep editing' ).check();
		await page.getByRole( 'button', { name: 'Freeze the site' } ).click();
		await expect( page.locator( '.sro-facts' ) ).toContainText( 'Can still edit: admin' );

		const save = await rest( 'POST', `/wp/v2/posts/${ postId }`, { title: 'Edited by exempt admin' } );
		expect( save.status ).toBe( 200 );

		await openEditor( page, postId );
		expect( await page.evaluate( () => window.wp.data.select( 'core/editor' ).isPostSavingLocked() ) ).toBe( false );
		wp( 'post', 'update', String( postId ), '--post_title=Freeze test post' );
	} );

	test( 'admin bar freezes for an hour and unfreezes', async ( { page } ) => {
		await page.goto( '/wp-admin/' );
		await page.locator( '#wp-admin-bar-site-name' ).hover();
		await page.locator( '#wp-admin-bar-site-read-only-mode-quick a' ).click();
		await expect( page.locator( '#wp-admin-bar-site-read-only-mode' ) ).toContainText( 'Frozen until' );
		expect( wp( 'site-read-only-mode', 'status' ) ).toContain( 'frozen for maintenance until' );

		await page.locator( '#wp-admin-bar-site-read-only-mode' ).hover();
		await page.locator( '#wp-admin-bar-site-read-only-mode-unfreeze a' ).click();
		await expect( page.locator( '#wp-admin-bar-site-read-only-mode' ) ).toHaveCount( 0 );
		expect( wp( 'site-read-only-mode', 'status' ) ).toBe( 'Not frozen.' );
	} );

	test( 'an open editor locks and unlocks through the heartbeat', async ( { page } ) => {
		await openEditor( page, postId );
		const locked = () => page.evaluate( () => window.wp.data.select( 'core/editor' ).isPostSavingLocked() );
		const tick = () => page.evaluate( () => new Promise( ( resolve ) => {
			window.jQuery( document ).one( 'heartbeat-tick', resolve );
			window.wp.heartbeat.connectNow();
		} ) );

		expect( await locked() ).toBe( false );
		wp( 'site-read-only-mode', 'on', '--minutes=10' );
		await tick();
		expect( await locked() ).toBe( true );

		wp( 'site-read-only-mode', 'off' );
		await tick();
		expect( await locked() ).toBe( false );
	} );

	test( 'scheduled freezes start later, expired freezes end on their own, WP-CLI works', async ( { page } ) => {
		const later = Math.floor( Date.now() / 1000 ) + 3600;
		wpEval( `AryanX\\SiteReadOnlyMode\\Plugin::instance()->freezer()->freeze( array( 'start' => ${ later }, 'minutes' => 30, 'reason' => 'Night deploy' ) );` );
		await page.goto( PAGE );
		await expect( page.locator( '.sro-status.is-scheduled h2' ) ).toContainText( 'Freeze scheduled for' );
		expect( wp( 'site-read-only-mode', 'status' ) ).toContain( 'Scheduled to freeze at' );
		await page.getByRole( 'button', { name: 'Cancel scheduled freeze' } ).click();
		await expect( page.locator( '.sro-form' ) ).toBeVisible();

		wpEval( `AryanX\\SiteReadOnlyMode\\Plugin::instance()->freezer()->freeze( array( 'start' => time() - 7200, 'minutes' => 60, 'reason' => 'Old deploy' ) );` );
		expect( wp( 'site-read-only-mode', 'status' ) ).toBe( 'Not frozen.' );
		await page.goto( PAGE );
		await expect( page.locator( '.sro-history tbody tr' ).first() ).toContainText( 'Ended on time' );

		expect( wp( 'site-read-only-mode', 'on', '--minutes=20', '--reason=CLI' ) ).toContain( 'Reason: CLI' );
		expect( wp( 'site-read-only-mode', 'extend', '10' ) ).toContain( 'frozen for maintenance until' );
		expect( wp( 'site-read-only-mode', 'off' ) ).toContain( 'Site unfrozen. 0 attempt(s)' );
	} );
} );
