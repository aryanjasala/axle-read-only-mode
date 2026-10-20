const { test, expect, wp, wpEval, openEditor } = require( './utils' );

const PAGE = '/wp-admin/tools.php?page=deploy-freeze';

const reset = () => {
	for ( const option of [ 'deploy_freeze_state', 'deploy_freeze_log', 'deploy_freeze_history' ] ) {
		try {
			wp( 'option', 'delete', option );
		} catch ( error ) {
			// Not set.
		}
	}
};

test.describe( 'Deploy Freeze', () => {
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
		await page.locator( '#df-reason' ).fill( 'Moving servers' );
		await page.locator( '#df-minutes' ).selectOption( '60' );
		await page.getByRole( 'button', { name: 'Freeze the site' } ).click();

		await expect( page.locator( '.df-status h2' ) ).toHaveText( 'The site is frozen' );
		await expect( page.locator( '.df-reason' ) ).toHaveText( 'Moving servers' );
		await expect( page.locator( '.df-countdown' ) ).toHaveText( /^Ends in (59|60):\d\d$/ );
		await expect( page.locator( '#wp-admin-bar-deploy-freeze' ) ).toContainText( 'Frozen until' );

		await openEditor( page, postId );
		await expect( page.locator( '.components-notice' ).filter( { hasText: 'This site is frozen for maintenance' } ) ).toBeVisible();
		expect( await page.evaluate( () => window.wp.data.select( 'core/editor' ).isPostSavingLocked() ) ).toBe( true );

		const save = await rest( 'POST', `/wp/v2/posts/${ postId }`, { title: 'Changed during freeze' } );
		expect( save.status ).toBe( 423 );
		expect( save.body.code ).toBe( 'deploy_freeze' );

		await page.goto( '/wp-admin/options-general.php' );
		await expect( page.locator( '.deploy-freeze-notice' ) ).toContainText( 'Reason: Moving servers' );
		const response = await Promise.all( [ page.waitForResponse( ( r ) => r.url().endsWith( '/wp-admin/options.php' ) ), page.locator( '#submit' ).click() ] );
		expect( response[ 0 ].status() ).toBe( 423 );
		await expect( page.locator( 'body' ) ).toContainText( 'This site is frozen for maintenance' );

		expect( wp( 'post', 'get', String( postId ), '--field=post_title' ) ).toBe( 'Freeze test post' );
	} );

	test( 'visitors can read the site but get the visitor message when they post', async ( { browser } ) => {
		wp( 'deploy-freeze', 'on', '--minutes=30', '--visitor-message=We are moving servers. Back by 3 pm.' );

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
		wp( 'deploy-freeze', 'on', '--minutes=30', '--reason=DB swap' );
		await rest( 'POST', `/wp/v2/posts/${ postId }`, { title: 'Blocked edit' } );

		await page.goto( PAGE );
		const blocked = page.locator( '.df-blocked' );
		await expect( blocked.locator( 'h2' ) ).toHaveText( 'Blocked during this freeze (1)' );
		await expect( blocked ).toContainText( `REST POST /wp/v2/posts/${ postId }` );
		await expect( blocked ).toContainText( 'admin' );

		const before = Number( wpEval( 'echo get_option( "deploy_freeze_state" )["end"];' ) );
		await page.getByRole( 'button', { name: '+1 hour' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Freeze extended.' );
		expect( Number( wpEval( 'echo get_option( "deploy_freeze_state" )["end"];' ) ) ).toBe( before + 3600 );

		await page.getByRole( 'button', { name: 'Unfreeze now' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'The site is unfrozen.' );
		const history = page.locator( '.df-history tbody tr' ).first();
		await expect( history ).toContainText( 'DB swap' );
		await expect( history ).toContainText( 'Unfrozen' );
		await expect( history.locator( '.num' ) ).toHaveText( '1' );
		await expect( page.locator( '.df-blocked h2' ) ).toHaveText( 'Blocked during the last freeze (1)' );
	} );

	test( 'exempt users keep editing', async ( { page, rest } ) => {
		await page.goto( PAGE );
		await page.getByLabel( 'Let me keep editing' ).check();
		await page.getByRole( 'button', { name: 'Freeze the site' } ).click();
		await expect( page.locator( '.df-facts' ) ).toContainText( 'Can still edit: admin' );

		const save = await rest( 'POST', `/wp/v2/posts/${ postId }`, { title: 'Edited by exempt admin' } );
		expect( save.status ).toBe( 200 );

		await openEditor( page, postId );
		expect( await page.evaluate( () => window.wp.data.select( 'core/editor' ).isPostSavingLocked() ) ).toBe( false );
		wp( 'post', 'update', String( postId ), '--post_title=Freeze test post' );
	} );

	test( 'admin bar freezes for an hour and unfreezes', async ( { page } ) => {
		await page.goto( '/wp-admin/' );
		await page.locator( '#wp-admin-bar-site-name' ).hover();
		await page.locator( '#wp-admin-bar-deploy-freeze-quick a' ).click();
		await expect( page.locator( '#wp-admin-bar-deploy-freeze' ) ).toContainText( 'Frozen until' );
		expect( wp( 'deploy-freeze', 'status' ) ).toContain( 'frozen for maintenance until' );

		await page.locator( '#wp-admin-bar-deploy-freeze' ).hover();
		await page.locator( '#wp-admin-bar-deploy-freeze-unfreeze a' ).click();
		await expect( page.locator( '#wp-admin-bar-deploy-freeze' ) ).toHaveCount( 0 );
		expect( wp( 'deploy-freeze', 'status' ) ).toBe( 'Not frozen.' );
	} );

	test( 'an open editor locks and unlocks through the heartbeat', async ( { page } ) => {
		await openEditor( page, postId );
		const locked = () => page.evaluate( () => window.wp.data.select( 'core/editor' ).isPostSavingLocked() );
		const tick = () => page.evaluate( () => new Promise( ( resolve ) => {
			window.jQuery( document ).one( 'heartbeat-tick', resolve );
			window.wp.heartbeat.connectNow();
		} ) );

		expect( await locked() ).toBe( false );
		wp( 'deploy-freeze', 'on', '--minutes=10' );
		await tick();
		expect( await locked() ).toBe( true );

		wp( 'deploy-freeze', 'off' );
		await tick();
		expect( await locked() ).toBe( false );
	} );

	test( 'scheduled freezes start later, expired freezes end on their own, WP-CLI works', async ( { page } ) => {
		const later = Math.floor( Date.now() / 1000 ) + 3600;
		wpEval( `AryanX\\DeployFreeze\\Plugin::instance()->freezer()->freeze( array( 'start' => ${ later }, 'minutes' => 30, 'reason' => 'Night deploy' ) );` );
		await page.goto( PAGE );
		await expect( page.locator( '.df-status.is-scheduled h2' ) ).toContainText( 'Freeze scheduled for' );
		expect( wp( 'deploy-freeze', 'status' ) ).toContain( 'Scheduled to freeze at' );
		await page.getByRole( 'button', { name: 'Cancel scheduled freeze' } ).click();
		await expect( page.locator( '.df-form' ) ).toBeVisible();

		wpEval( `AryanX\\DeployFreeze\\Plugin::instance()->freezer()->freeze( array( 'start' => time() - 7200, 'minutes' => 60, 'reason' => 'Old deploy' ) );` );
		expect( wp( 'deploy-freeze', 'status' ) ).toBe( 'Not frozen.' );
		await page.goto( PAGE );
		await expect( page.locator( '.df-history tbody tr' ).first() ).toContainText( 'Ended on time' );

		expect( wp( 'deploy-freeze', 'on', '--minutes=20', '--reason=CLI' ) ).toContain( 'Reason: CLI' );
		expect( wp( 'deploy-freeze', 'extend', '10' ) ).toContain( 'frozen for maintenance until' );
		expect( wp( 'deploy-freeze', 'off' ) ).toContain( 'Site unfrozen. 0 attempt(s)' );
	} );
} );
