const { test, expect, wp, wpEval, openEditor } = require( './utils' );

const PAGE = '/wp-admin/tools.php?page=axle-read-only-mode';
const PLANS = `${ PAGE }&tab=plans`;
const CLASSIC = 'aro-e2e-classic.php';

const reset = () => {
	for ( const option of [ 'axle_read_only_mode_state', 'axle_read_only_mode_log', 'axle_read_only_mode_history', 'axle_read_only_mode_settings', 'axle_read_only_mode_plans', 'axle_read_only_mode_start_findings' ] ) {
		try {
			wp( 'option', 'delete', option );
		} catch ( error ) {
			// Not set.
		}
	}
	// One-time markers of earlier plans.
	wpEval( 'global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE \'axle_read_only_mode_plan_%\' OR option_name LIKE \'axle_read_only_mode_started_%\' OR option_name LIKE \'axle_read_only_mode_expired_%\'" );' );
};

const plans = () => JSON.parse( wp( 'axle-read-only-mode', 'plans', '--format=json' ) || '[]' );

// Moves the first plan's start, as if time had passed.
const moveFirstPlan = ( secondsFromNow ) =>
	wpEval( `$p = get_option( 'axle_read_only_mode_plans' ); $k = array_key_first( $p ); $p[ $k ]['start'] = time() + ( ${ secondsFromNow } ); update_option( 'axle_read_only_mode_plans', $p );` );

test.describe( 'Planned freezes', () => {
	test.beforeEach( reset );
	test.afterAll( reset );

	test( 'plan, change and cancel a freeze on the Planned tab', async ( { page } ) => {
		await page.goto( PLANS );
		await expect( page.locator( '.aro-plans .axle-empty' ) ).toContainText( 'Nothing is planned' );

		const start = wpEval( "echo wp_date( 'Y-m-d\\TH:i', time() + 3 * HOUR_IN_SECONDS );" );
		await page.getByLabel( 'Starts at' ).fill( start );
		await page.getByLabel( 'Content only' ).check();
		await page.getByLabel( 'Monday' ).check();
		await page.getByLabel( 'Reason' ).fill( 'Release night' );
		await page.getByRole( 'button', { name: 'Plan this freeze' } ).click();

		await expect( page.locator( '.notice-success' ) ).toContainText( 'Freeze planned' );
		const row = page.locator( '.aro-plans tbody tr' );
		await expect( row ).toHaveCount( 1 );
		await expect( row ).toContainText( 'Release night' );
		await expect( row ).toContainText( 'Every Mon at' );
		expect( plans() ).toHaveLength( 1 );
		expect( plans()[ 0 ].scope ).toBe( 'content' );

		await row.getByRole( 'link', { name: /Edit/ } ).click();
		await expect( page.locator( '#aro-plan-form .axle-card__title' ) ).toHaveText( 'Change the planned freeze' );
		await page.getByLabel( 'Reason' ).fill( 'Release night, moved' );
		await page.getByRole( 'button', { name: 'Save changes' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Planned freeze changed' );
		expect( plans()[ 0 ].reason ).toBe( 'Release night, moved' );

		await page.locator( '.aro-cancel summary' ).click();
		await expect( page.locator( '.aro-cancel .axle-confirm' ) ).toContainText( 'every week after' );
		await page.getByRole( 'button', { name: 'Cancel this planned freeze' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Planned freeze cancelled' );
		expect( plans() ).toHaveLength( 0 );
	} );

	test( 'a start in the past is refused with a message', async ( { page } ) => {
		await page.goto( PLANS );
		const past = wpEval( "echo wp_date( 'Y-m-d\\TH:i', time() - HOUR_IN_SECONDS );" );
		await page.getByLabel( 'Starts at' ).fill( past );
		await page.getByRole( 'button', { name: 'Plan this freeze' } ).click();
		await expect( page.locator( '.notice-error' ) ).toContainText( 'Choose a start time in the future' );
		expect( plans() ).toHaveLength( 0 );
	} );

	test( 'a plan starts by itself on the first request after its time, without cron', async ( { page } ) => {
		wp( 'axle-read-only-mode', 'plan', `--start=${ wpEval( "echo wp_date( 'Y-m-d H:i', time() + 2 * HOUR_IN_SECONDS );" ) }`, '--minutes=30', '--scope=content', '--reason=Deploy' );
		expect( wp( 'axle-read-only-mode', 'status' ) ).toBe( 'Not frozen.' );

		moveFirstPlan( -5 );
		await page.goto( PAGE );
		await expect( page.locator( '.aro-status .axle-card__title' ) ).toHaveText( 'The site is frozen' );
		expect( wp( 'axle-read-only-mode', 'status' ) ).toContain( 'content of this site is frozen' );
		expect( plans() ).toHaveLength( 0 );
	} );

	test( 'a weekly plan stays on the list for its next day after it starts', async ( { page } ) => {
		wp( 'axle-read-only-mode', 'plan', `--start=${ wpEval( "echo wp_date( 'Y-m-d H:i', time() + 2 * HOUR_IN_SECONDS );" ) }`, '--minutes=10', '--days=sun,mon,tue,wed,thu,fri,sat' );
		moveFirstPlan( -5 );
		await page.goto( PAGE );
		await expect( page.locator( '.aro-status' ) ).toBeVisible();
		const [ plan ] = plans();
		expect( new Date( plan.starts.replace( ' ', 'T' ) ).getTime() ).toBeGreaterThan( 0 );
		expect( plans() ).toHaveLength( 1 );
	} );

	test( 'the heads-up counts down in wp-admin and goes away when the plan is cancelled', async ( { page } ) => {
		wp( 'axle-read-only-mode', 'plan', `--start=${ wpEval( "echo wp_date( 'Y-m-d H:i', time() + 2 * HOUR_IN_SECONDS );" ) }`, '--minutes=30', '--reason=Deploy' );
		moveFirstPlan( 9 * 60 );

		await page.goto( '/wp-admin/index.php' );
		const notice = page.locator( '.axle-heads-up' );
		await expect( notice ).toContainText( /The site goes read-only in (9|10) minutes\. Save your work\./ );

		wp( 'axle-read-only-mode', 'cancel', plans()[ 0 ].id );
		await page.evaluate( () => window.wp.heartbeat.connectNow() );
		await expect( notice ).toBeHidden();
	} );

	test( 'nobody outside the window sees a heads-up', async ( { page } ) => {
		wp( 'axle-read-only-mode', 'plan', `--start=${ wpEval( "echo wp_date( 'Y-m-d H:i', time() + 5 * HOUR_IN_SECONDS );" ) }` );
		await page.goto( '/wp-admin/index.php' );
		await expect( page.locator( '.axle-heads-up' ) ).toHaveCount( 0 );
	} );

	test( 'the start email carries what the check found', async () => {
		wp( 'axle-read-only-mode', 'notify', '--enable', '--to=ops@example.com', '--events=started' );
		const postId = wp( 'post', 'create', '--post_title=Check findings', '--post_status=publish', '--porcelain' );
		wp( 'post', 'meta', 'update', postId, '_edit_lock', `${ Math.floor( Date.now() / 1000 ) }:1` );
		wp( 'axle-read-only-mode', 'plan', `--start=${ wpEval( "echo wp_date( 'Y-m-d H:i', time() + 2 * HOUR_IN_SECONDS );" ) }`, '--minutes=20' );
		moveFirstPlan( -2 );
		wpEval( 'AryanX\\Axle\\ReadOnlyMode\\Plugin::instance()->planner()->tick();' );
		const findings = JSON.parse( wp( 'option', 'get', 'axle_read_only_mode_start_findings', '--format=json' ) );
		expect( findings.lines.join( '\n' ) ).toContain( 'open' );
		wp( 'post', 'delete', postId, '--force' );
	} );

	test( 'the Planned tab, abilities and the heads-up in the block editor', async ( { page } ) => {
		wp( 'axle-read-only-mode', 'plan', `--start=${ wpEval( "echo wp_date( 'Y-m-d H:i', time() + 2 * HOUR_IN_SECONDS );" ) }`, '--reason=Editor notice' );
		moveFirstPlan( 6 * 60 );
		const postId = Number( wp( 'post', 'create', '--post_title=Heads-up post', '--post_status=publish', '--porcelain' ) );

		await openEditor( page, postId );
		await expect( page.locator( '.components-notice' ).filter( { hasText: /The site goes read-only in (6|7) minutes/ } ) ).toBeVisible();

		wp( 'post', 'delete', String( postId ), '--force' );
	} );
} );

test.describe( 'Changes made while the site is read-only are not lost', () => {
	let postId;

	const content = ( text ) => `<!-- wp:paragraph --><p>${ text }</p><!-- /wp:paragraph -->`;

	test.beforeEach( () => {
		reset();
		postId = Number( wp( 'post', 'create', '--post_title=Keep my words', '--post_status=publish', `--post_content=${ content( 'Original' ) }`, '--porcelain' ) );
	} );

	test.afterEach( () => {
		reset();
		wp( 'post', 'delete', String( postId ), '--force' );
	} );

	const heartbeat = ( page ) => page.evaluate( () => window.wp.heartbeat.connectNow() );

	test( 'an edit open when the freeze starts is saved when it ends', async ( { page } ) => {
		await openEditor( page, postId );
		await page.evaluate( () => window.wp.data.dispatch( 'core/editor' ).editPost( { title: 'Typed during the freeze' } ) );

		wp( 'axle-read-only-mode', 'on', '--minutes=30', '--scope=content' );
		await heartbeat( page );
		await expect( page.locator( '.components-notice' ).filter( { hasText: 'saved when the freeze ends' } ) ).toBeVisible();
		expect( await page.evaluate( () => window.wp.data.select( 'core/editor' ).isPostSavingLocked() ) ).toBe( true );

		// A copy waits in the browser.
		await expect.poll( () => page.evaluate( ( id ) => window.localStorage.getItem( `axle-read-only-mode:post:${ id }` ), postId ) ).toContain( 'Typed during the freeze' );
		expect( wp( 'post', 'get', String( postId ), '--field=post_title' ) ).toBe( 'Keep my words' );

		wp( 'axle-read-only-mode', 'off' );
		await heartbeat( page );
		await expect( page.locator( '.components-notice' ).filter( { hasText: 'Your changes were saved' } ) ).toBeVisible();
		await expect.poll( () => wp( 'post', 'get', String( postId ), '--field=post_title' ) ).toBe( 'Typed during the freeze' );
		expect( await page.evaluate( ( id ) => window.localStorage.getItem( `axle-read-only-mode:post:${ id }` ), postId ) ).toBeNull();
	} );

	test( 'a copy kept before a reload is saved after the freeze when the post did not change', async ( { page } ) => {
		await openEditor( page, postId );
		await page.evaluate( () => window.wp.data.dispatch( 'core/editor' ).editPost( { title: 'Survives a reload' } ) );
		wp( 'axle-read-only-mode', 'on', '--minutes=30', '--scope=content' );
		await heartbeat( page );
		await expect.poll( () => page.evaluate( ( id ) => window.localStorage.getItem( `axle-read-only-mode:post:${ id }` ), postId ) ).toContain( 'Survives a reload' );

		wp( 'axle-read-only-mode', 'off' );
		await page.evaluate( () => ( window.onbeforeunload = null ) );
		await page.goto( `/wp-admin/post.php?post=${ postId }&action=edit` );
		await expect.poll( () => wp( 'post', 'get', String( postId ), '--field=post_title' ) ).toBe( 'Survives a reload' );
	} );

	test( 'when someone else changed the post meanwhile, both versions are offered and nothing is overwritten', async ( { page } ) => {
		await openEditor( page, postId );
		await page.evaluate( () => window.wp.data.dispatch( 'core/editor' ).editPost( { title: 'My version' } ) );
		wp( 'axle-read-only-mode', 'on', '--minutes=30', '--scope=content' );
		await heartbeat( page );
		await expect.poll( () => page.evaluate( ( id ) => window.localStorage.getItem( `axle-read-only-mode:post:${ id }` ), postId ) ).toContain( 'My version' );

		// Someone with WP-CLI (never blocked) changes the post on the server.
		await page.waitForTimeout( 1100 );
		wp( 'post', 'update', String( postId ), '--post_title=Their version' );
		wp( 'axle-read-only-mode', 'off' );
		await heartbeat( page );

		const choice = page.locator( '.components-notice' ).filter( { hasText: 'changed by someone else' } );
		await expect( choice ).toBeVisible();
		expect( wp( 'post', 'get', String( postId ), '--field=post_title' ) ).toBe( 'Their version' );

		await choice.getByRole( 'button', { name: 'Save my version' } ).click();
		await expect.poll( () => wp( 'post', 'get', String( postId ), '--field=post_title' ) ).toBe( 'My version' );
	} );

	test( 'the classic editor keeps the form when a save is refused', async ( { page } ) => {
		wpEval( `wp_mkdir_p( WPMU_PLUGIN_DIR ); file_put_contents( WPMU_PLUGIN_DIR . '/${ CLASSIC }', '<?php add_filter( "use_block_editor_for_post", "__return_false" );' );` );

		try {
			await page.goto( `/wp-admin/post.php?post=${ postId }&action=edit` );
			await page.locator( '#title' ).fill( 'Typed in the classic form' );
			wp( 'axle-read-only-mode', 'on', '--minutes=30', '--scope=content' );
			await page.evaluate( () => window.wp.heartbeat.connectNow() );
			await expect( page.locator( '.notice-warning' ).filter( { hasText: 'still in this form' } ) ).toBeVisible();

			await page.locator( '#publish' ).click();
			await expect( page.locator( '#title' ) ).toHaveValue( 'Typed in the classic form' );
			expect( wp( 'post', 'get', String( postId ), '--field=post_title' ) ).toBe( 'Keep my words' );

			wp( 'axle-read-only-mode', 'off' );
			await page.evaluate( () => window.wp.heartbeat.connectNow() );
			await expect( page.locator( '.notice-success' ).filter( { hasText: 'You can save now' } ) ).toBeVisible();
			await page.locator( '#publish' ).click();
			await expect.poll( () => wp( 'post', 'get', String( postId ), '--field=post_title' ) ).toBe( 'Typed in the classic form' );
		} finally {
			wpEval( `@unlink( WPMU_PLUGIN_DIR . '/${ CLASSIC }' );` );
		}
	} );
} );
