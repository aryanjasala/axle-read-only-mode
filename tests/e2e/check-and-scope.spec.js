const { test, expect, wp, wpEval, openEditor } = require( './utils' );

const PAGE = '/wp-admin/tools.php?page=axle-read-only-mode';

const reset = () => {
	for ( const option of [ 'axle_read_only_mode_state', 'axle_read_only_mode_log', 'axle_read_only_mode_history', 'axle_read_only_mode_settings' ] ) {
		try {
			wp( 'option', 'delete', option );
		} catch ( error ) {
			// Not set.
		}
	}
};

test.describe( 'Before you freeze, and freezing part of the site', () => {
	let postId;
	let editorId;

	test.beforeAll( () => {
		postId = Number( wp( 'post', 'create', '--post_title=Scope test post', '--post_status=publish', '--porcelain' ) );
		try {
			wp( 'user', 'delete', 'aro-editor', '--yes' );
		} catch ( error ) {
			// Not created.
		}
		editorId = Number( wp( 'user', 'create', 'aro-editor', 'aro-editor@example.com', '--role=editor', '--display_name=Priya Editor', '--porcelain' ) );
	} );

	test.beforeEach( reset );

	test.afterEach( () => {
		try {
			wp( 'post', 'meta', 'delete', String( postId ), '_edit_lock' );
		} catch ( error ) {
			// Not set.
		}
		wp( 'option', 'update', 'blogname', 'Test site' );
	} );

	test.afterAll( () => {
		reset();
		wp( 'post', 'delete', String( postId ), '--force' );
		wp( 'user', 'delete', 'aro-editor', '--yes' );
	} );

	const lock = ( secondsAgo = 20 ) => wp( 'post', 'meta', 'update', String( postId ), '_edit_lock', `${ Math.floor( Date.now() / 1000 ) - secondsAgo }:${ editorId }` );

	test( 'the card says who is editing, which post and how long ago, and updates with the length', async ( { page } ) => {
		lock( 40 );
		const dueIn45 = wpEval( "echo gmdate( 'Y-m-d H:i:s', time() + 45 * 60 );" );
		const future = Number( wp( 'post', 'create', '--post_title=Due soon', '--post_status=future', `--post_date_gmt=${ dueIn45 }`, '--porcelain' ) );
		wp( 'cron', 'event', 'schedule', 'aro_e2e_event', '+5 hours' );

		try {
			await page.goto( PAGE );
			const card = page.locator( '.aro-before' );
			await expect( card.locator( 'h2' ) ).toHaveText( 'Before you freeze' );

			const editing = card.locator( '.aro-check--editing' );
			await expect( editing.locator( '.axle-badge' ) ).toHaveText( 'Editing now' );
			await expect( editing.locator( '.axle-badge' ) ).toHaveClass( /axle-badge--warning/ );
			await expect( editing ).toContainText( '1 person has a post open in the editor' );
			await expect( editing ).toContainText( 'Priya Editor has "Scope test post" open' );

			const posts = card.locator( '.aro-check--scheduled_posts' );
			await expect( posts.locator( '.axle-badge' ) ).toHaveText( 'Will be late' );
			await expect( posts ).toContainText( '"Due soon" is due in' );
			const tasks = async () => Number( ( ( await card.locator( '.aro-check--cron strong' ).textContent() ) || '' ).match( /^(\d+) scheduled task/ )[ 1 ] );

			// A 30 minute freeze ends before the post is due, so the card changes without a reload.
			await page.locator( '#aro-minutes' ).selectOption( '30' );
			await expect( posts.locator( '.axle-badge' ) ).toHaveText( 'Clear' );
			const before = await tasks();
			await page.locator( '#aro-minutes' ).selectOption( '480' );
			await expect( posts.locator( '.axle-badge' ) ).toHaveText( 'Will be late' );
			expect( await tasks() ).toBeGreaterThan( before );

			// Without scheduled tasks held, the post publishes on time.
			await page.locator( 'input[name="cron"]' ).uncheck();
			await expect( posts.locator( '.axle-badge' ) ).toHaveText( 'Will publish' );
			await page.locator( 'input[name="cron"]' ).check();

			// A settings and code freeze leaves editors alone.
			await page.getByLabel( /Settings and code only/ ).check();
			await expect( editing.locator( '.axle-badge' ) ).toHaveText( 'Not affected' );
		} finally {
			wp( 'post', 'delete', String( future ), '--force' );
			wp( 'cron', 'event', 'delete', 'aro_e2e_event' );
		}
	} );

	test( 'the card is also on the command line, as JSON', () => {
		lock();
		const result = JSON.parse( wp( 'axle-read-only-mode', 'check', '--minutes=30', '--format=json' ) );

		expect( result.editing ).toBe( 1 );
		expect( result.items.map( ( item ) => item.id ) ).toEqual( expect.arrayContaining( [ 'editing', 'scheduled_posts', 'cron' ] ) );
		expect( wp( 'axle-read-only-mode', 'check', '--minutes=30' ) ).toContain( 'Priya Editor has "Scope test post" open' );
		expect( () => wp( 'axle-read-only-mode', 'check', '--scope=nonsense' ) ).toThrow();
		expect( wp( 'axle-read-only-mode', 'status' ) ).toBe( 'Not frozen.' );
	} );

	test( 'freezing asks first when someone is editing, and not otherwise', async ( { page } ) => {
		lock();
		await page.goto( PAGE );
		await expect( page.locator( '.aro-before .aro-check--editing .axle-badge' ) ).toHaveText( 'Editing now' );

		let asked = '';
		page.once( 'dialog', ( dialog ) => {
			asked = dialog.message();
			dialog.dismiss();
		} );
		await page.getByRole( 'button', { name: 'Freeze the site' } ).click();
		await expect.poll( () => asked ).toContain( '1 other person has a post open in the editor' );
		expect( asked ).toContain( 'You can unfreeze at any time' );
		expect( wp( 'axle-read-only-mode', 'status' ) ).toBe( 'Not frozen.' );

		page.once( 'dialog', ( dialog ) => dialog.accept() );
		await page.getByRole( 'button', { name: 'Freeze the site' } ).click();
		await expect( page.locator( '.aro-status .axle-card__title' ) ).toHaveText( 'The site is frozen' );
		wp( 'axle-read-only-mode', 'off' );

		wp( 'post', 'meta', 'delete', String( postId ), '_edit_lock' );
		let dialogs = 0;
		page.on( 'dialog', ( dialog ) => {
			dialogs++;
			dialog.accept();
		} );
		await page.goto( PAGE );
		await expect( page.locator( '.aro-before .aro-check--editing .axle-badge' ) ).toHaveText( 'Clear' );
		await page.getByRole( 'button', { name: 'Freeze the site' } ).click();
		await expect( page.locator( '.aro-status .axle-card__title' ) ).toHaveText( 'The site is frozen' );
		expect( dialogs ).toBe( 0 );
	} );

	test( 'the admin bar freeze stays one click and says afterwards who was editing', async ( { page } ) => {
		lock();
		await page.goto( '/wp-admin/' );
		await page.locator( '#wp-admin-bar-site-name' ).hover();
		await page.locator( '#wp-admin-bar-axle-read-only-mode-quick a' ).click();

		await expect( page.locator( '.aro-page .notice-warning' ) ).toContainText( 'The site is frozen. 1 other person has a post open in the editor. Their next save will be refused.' );
		await expect( page.locator( '#wp-admin-bar-axle-read-only-mode' ) ).toContainText( 'Frozen until' );
	} );

	test( 'content only: content is refused, settings and updates keep working', async ( { page, rest } ) => {
		await page.goto( PAGE );
		await page.getByLabel( /Content only/ ).check();
		await page.getByRole( 'button', { name: 'Freeze the site' } ).click();

		await expect( page.locator( '.axle-status .axle-badge' ) ).toHaveText( 'Content frozen' );
		await expect( page.locator( '.aro-status .axle-facts' ) ).toContainText( 'Content only' );
		await expect( page.locator( '#wp-admin-bar-axle-read-only-mode' ) ).toContainText( 'Content frozen until' );
		expect( wp( 'axle-read-only-mode', 'status' ) ).toContain( 'Scope:                     content' );

		const post = await rest( 'POST', `/wp/v2/posts/${ postId }`, { title: 'Changed in a content freeze' } );
		expect( post.status ).toBe( 423 );
		expect( post.body.message ).toContain( 'The content of this site is frozen' );

		const settings = await rest( 'POST', '/wp/v2/settings', { title: 'Renamed during a content freeze' } );
		expect( settings.status ).toBe( 200 );
		expect( wp( 'option', 'get', 'blogname' ) ).toBe( 'Renamed during a content freeze' );

		// The settings screen saves, the notice says what is frozen, and the editor is locked.
		await page.goto( '/wp-admin/options-general.php' );
		await expect( page.locator( '.axle-read-only-mode-notice' ) ).toContainText( 'The content of this site is frozen' );
		const saved = await Promise.all( [ page.waitForResponse( ( r ) => r.url().endsWith( '/wp-admin/options.php' ) ), page.locator( '#submit' ).click() ] );
		expect( saved[ 0 ].status() ).toBe( 302 );

		await openEditor( page, postId );
		expect( await page.evaluate( () => window.wp.data.select( 'core/editor' ).isPostSavingLocked() ) ).toBe( true );

		// A visitor cannot comment, and the refusal is in the log as content.
		const visitor = await page.context().browser().newContext( { storageState: { cookies: [], origins: [] } } );
		const comment = await visitor.request.post( '/wp-comments-post.php', { form: { comment: 'Hi', comment_post_ID: String( postId ), author: 'Sam', email: 'sam@example.com' } } );
		expect( comment.status() ).toBe( 503 );
		await visitor.close();

		await page.goto( PAGE );
		const blocked = page.locator( '.aro-blocked' );
		await expect( blocked.locator( 'h2' ) ).toHaveText( 'Blocked during this freeze (2)' );
		await expect( blocked ).toContainText( 'Content only' );
		await expect( blocked.locator( 'tbody tr' ).first().locator( 'td' ).nth( 3 ) ).toHaveText( 'Content' );
		expect( JSON.parse( wp( 'axle-read-only-mode', 'log', '--format=json' ) ).map( ( entry ) => entry.area ) ).toEqual( [ 'content', 'content' ] );

		await page.getByRole( 'button', { name: 'Unfreeze now' } ).click();
		await expect( page.locator( '.aro-history tbody tr' ).first() ).toContainText( 'Content only' );
	} );

	test( 'settings and code only: options and plugins are refused, editors and visitors keep working', async ( { page, rest, browser } ) => {
		wp( 'axle-read-only-mode', 'on', '--minutes=30', '--scope=code' );

		await page.goto( PAGE );
		await expect( page.locator( '.axle-status .axle-badge' ) ).toHaveText( 'Settings and code frozen' );
		await expect( page.locator( '.aro-status .axle-facts' ) ).toContainText( 'Settings and code only' );
		await expect( page.locator( '.aro-status .axle-facts' ) ).toContainText( 'Still open: they are content' );
		await expect( page.locator( '#wp-admin-bar-axle-read-only-mode' ) ).toContainText( 'Settings and code frozen until' );

		const post = await rest( 'POST', `/wp/v2/posts/${ postId }`, { title: 'Edited in a code freeze' } );
		expect( post.status ).toBe( 200 );
		wp( 'post', 'update', String( postId ), '--post_title=Scope test post' );

		const settings = await rest( 'POST', '/wp/v2/settings', { title: 'Renamed during a code freeze' } );
		expect( settings.status ).toBe( 423 );
		expect( settings.body.message ).toContain( 'The settings and code of this site are frozen' );
		expect( wp( 'option', 'get', 'blogname' ) ).not.toBe( 'Renamed during a code freeze' );

		await page.goto( '/wp-admin/options-general.php' );
		const refused = await Promise.all( [ page.waitForResponse( ( r ) => r.url().endsWith( '/wp-admin/options.php' ) ), page.locator( '#submit' ).click() ] );
		expect( refused[ 0 ].status() ).toBe( 423 );
		await expect( page.locator( 'body' ) ).toContainText( 'The settings and code of this site are frozen' );

		// Plugins cannot be switched off from the list.
		const toggle = await page.request.get( '/wp-admin/plugins.php?action=deactivate&plugin=hello.php&_wpnonce=abc123' );
		expect( toggle.status() ).toBe( 423 );

		await openEditor( page, postId );
		expect( await page.evaluate( () => window.wp.data.select( 'core/editor' ).isPostSavingLocked() ) ).toBe( false );

		const visitor = await browser.newContext( { storageState: { cookies: [], origins: [] } } );
		const comment = await visitor.request.post( '/wp-comments-post.php', { maxRedirects: 0, form: { comment: 'Hi from a visitor', comment_post_ID: String( postId ), author: 'Sam', email: 'sam@example.com' } } );
		expect( comment.status() ).toBe( 302 );
		await visitor.close();
		wp( 'comment', 'delete', ...wp( 'comment', 'list', '--format=ids' ).split( ' ' ).filter( Boolean ), '--force' );

		const log = JSON.parse( wp( 'axle-read-only-mode', 'log', '--format=json' ) );
		expect( log.every( ( entry ) => 'code' === entry.area ) ).toBe( true );
		expect( log.length ).toBeGreaterThanOrEqual( 2 );
	} );

	test( 'scheduled tasks are held per scope', async () => {
		wp( 'cron', 'event', 'schedule', 'aro_e2e_overdue', 'now' );
		try {
			wp( 'axle-read-only-mode', 'on', '--minutes=30', '--scope=content' );
			const ready = ( hook ) => wpEval( `$ready = wp_get_ready_cron_jobs(); $found = false; foreach ( $ready as $hooks ) { if ( isset( $hooks['${ hook }'] ) ) { $found = true; } } echo $found ? 'yes' : 'no';` );

			expect( ready( 'aro_e2e_overdue' ) ).toBe( 'no' );

			wp( 'axle-read-only-mode', 'off' );
			wp( 'axle-read-only-mode', 'on', '--minutes=30', '--scope=code' );
			expect( ready( 'aro_e2e_overdue' ) ).toBe( 'yes' );
		} finally {
			wp( 'axle-read-only-mode', 'off' );
			wp( 'cron', 'event', 'delete', 'aro_e2e_overdue' );
		}
	} );

	test( 'the start email and WP-CLI say the scope, and a bad scope is refused', () => {
		expect( () => wp( 'axle-read-only-mode', 'on', '--scope=nonsense' ) ).toThrow();
		expect( wp( 'axle-read-only-mode', 'status' ) ).toBe( 'Not frozen.' );

		wp( 'axle-read-only-mode', 'on', '--minutes=30', '--scope=content' );
		expect( JSON.parse( wpEval( 'echo wp_json_encode( get_option( "axle_read_only_mode_state" ) );' ) ).area ).toBe( 'content' );
		wp( 'axle-read-only-mode', 'off' );
		expect( JSON.parse( wpEval( 'echo wp_json_encode( get_option( "axle_read_only_mode_history" )[0] );' ) ).scope ).toBe( 'content' );
	} );

	test( 'a freeze stored before scopes existed covers everything', async ( { rest } ) => {
		wpEval( 'update_option( "axle_read_only_mode_state", array( "active" => true, "start" => time() - 60, "end" => time() + 1800, "reason" => "Old", "by" => 1 ) );' );

		expect( wp( 'axle-read-only-mode', 'status' ) ).toContain( 'Scope:                     all' );
		expect( ( await rest( 'POST', `/wp/v2/posts/${ postId }`, { title: 'x' } ) ).status ).toBe( 423 );
		expect( ( await rest( 'POST', '/wp/v2/settings', { title: 'x' } ) ).status ).toBe( 423 );
	} );

	test( 'abilities: check before freezing, and freeze with a scope', async ( { rest } ) => {
		test.skip( 'yes' !== wpEval( "echo function_exists( 'wp_register_ability' ) ? 'yes' : 'no';" ), 'The Abilities API needs WordPress 6.9.' );
		lock();
		const run = ( name, input ) => rest( 'POST', `/wp-abilities/v1/abilities/axle-read-only-mode/${ name }/run`, input ? { input } : {} );
		const read = ( name, input ) => {
			const query = input ? Object.entries( input ).map( ( [ key, value ] ) => `input[${ key }]=${ encodeURIComponent( value ) }` ).join( '&' ) : '';
			return rest( 'GET', `/wp-abilities/v1/abilities/axle-read-only-mode/${ name }/run${ query ? '?' + query : '' }` );
		};

		const checked = await read( 'check-freeze', { minutes: 30 } );
		expect( checked.status ).toBe( 200 );
		expect( checked.body.editing ).toBe( 1 );
		expect( checked.body.items.find( ( item ) => 'editing' === item.id ).tone ).toBe( 'warning' );
		expect( ( await read( 'get-status' ) ).body.frozen ).toBe( false );

		const frozen = await run( 'freeze', { minutes: 30, scope: 'content' } );
		expect( frozen.status ).toBe( 200 );
		expect( frozen.body ).toMatchObject( { frozen: true, scope: 'content' } );
		await run( 'unfreeze' );
	} );
} );
