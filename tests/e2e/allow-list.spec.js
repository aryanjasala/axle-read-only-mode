const { test, expect, wp, wpEval, logInAs } = require( './utils' );

const SETTINGS = '/wp-admin/tools.php?page=axle-read-only-mode&tab=settings';
const PAGE = '/wp-admin/tools.php?page=axle-read-only-mode';
const FIXTURE = 'aro-e2e-allow.php';

const settings = () => JSON.parse( wpEval( 'echo wp_json_encode( get_option( "axle_read_only_mode_settings" ) );' ) );

const reset = () => {
	for ( const option of [ 'axle_read_only_mode_state', 'axle_read_only_mode_log', 'axle_read_only_mode_history', 'axle_read_only_mode_settings', 'aro_e2e_deny', 'aro_e2e_suggest', 'aro_e2e_code_allow' ] ) {
		try {
			wp( 'option', 'delete', option );
		} catch ( error ) {
			// Not set.
		}
	}
};

test.describe( 'Keep these working while frozen', () => {
	let postId;

	test.beforeAll( () => {
		postId = Number( wp( 'post', 'create', '--post_title=Allow-list post', '--post_status=publish', '--porcelain' ) );
		wpEval( `wp_mkdir_p( WPMU_PLUGIN_DIR ); copy( WP_PLUGIN_DIR . '/axle-read-only-mode/tests/e2e/fixtures/${ FIXTURE }', WPMU_PLUGIN_DIR . '/${ FIXTURE }' );` );
	} );

	test.beforeEach( reset );

	test.afterAll( () => {
		reset();
		wp( 'post', 'delete', String( postId ), '--force' );
		wpEval( `@unlink( WPMU_PLUGIN_DIR . '/${ FIXTURE }' );` );
		try {
			wp( 'user', 'delete', 'aro-ops', '--yes' );
		} catch ( error ) {
			// Not created.
		}
	} );

	test( 'entries are checked, saved, and each says what it matches', async ( { page } ) => {
		await page.goto( SETTINGS );
		await page.getByLabel( 'REST API routes' ).fill( '/wp/v2/comments\n/wp/v2\n/my-plugin/v1/callback' );
		await page.getByLabel( 'Front-end paths or query arguments' ).fill( '?wc-api=\n/checkout/order-pay\n/' );
		await page.getByLabel( 'Ajax actions' ).fill( 'Subscribe_Form\nheartbeat' );
		await page.getByLabel( 'Users who may keep editing' ).fill( 'admin\nnobody-here' );
		await page.getByRole( 'button', { name: 'Save settings' } ).click();

		await expect( page.locator( '.aro-page .notice-warning' ) ).toContainText( 'Settings saved, except the lines listed below.' );
		const rejected = page.locator( '.aro-rejected li' );
		await expect( rejected ).toHaveCount( 3 );
		await expect( rejected.nth( 0 ) ).toContainText( '/wp/v2' );
		await expect( rejected.nth( 0 ) ).toContainText( 'every WordPress core write' );
		await expect( page.locator( '.aro-rejected' ) ).toContainText( 'would match every page' );
		await expect( page.locator( '.aro-rejected' ) ).toContainText( 'nobody-here' );

		const saved = settings();
		expect( saved.allow ).toEqual( {
			rest: [ '/wp/v2/comments', '/my-plugin/v1/callback' ],
			paths: [ '?wc-api=', '/checkout/order-pay' ],
			ajax: [ 'subscribe_form', 'heartbeat' ],
			users: [ 1 ],
		} );

		// What each entry matches, shown beside the field.
		const rest = page.locator( '.aro-matches' ).nth( 0 );
		await expect( rest.locator( 'li' ).nth( 0 ) ).toContainText( /\d+ routes?/ );
		await expect( rest.locator( 'li' ).nth( 1 ) ).toContainText( 'No route yet' );
		await expect( page.locator( '.aro-matches' ).nth( 1 ) ).toContainText( 'wc-api argument' );
		await expect( page.locator( '.aro-matches' ).nth( 1 ) ).toContainText( 'start with /checkout/order-pay' );
		await expect( page.locator( '.aro-matches' ).nth( 3 ) ).toContainText( 'can keep editing during a freeze' );

		// A reload does not show the same errors again.
		await page.goto( SETTINGS );
		await expect( page.locator( '.aro-rejected' ) ).toHaveCount( 0 );
	} );

	test( 'a REST route, a path, an ajax action and a user keep working, and everything else stays frozen', async ( { page, rest, browser } ) => {
		wp( 'axle-read-only-mode', 'allow', 'rest', '/wp/v2/comments' );
		wp( 'axle-read-only-mode', 'allow', 'path', '?wc-api=' );
		wp( 'axle-read-only-mode', 'allow', 'ajax', 'aro_open_action' );
		wp( 'user', 'create', 'aro-ops', 'aro-ops@example.com', '--role=editor', '--user_pass=aro-ops-pass' );
		wp( 'axle-read-only-mode', 'allow', 'user', 'aro-ops@example.com' );
		wp( 'axle-read-only-mode', 'on', '--minutes=30' );

		// REST: the allowed route works, a neighbour does not.
		// WordPress may still refuse the comment for its own reasons: what matters is that the freeze does not.
		const comment = await rest( 'POST', '/wp/v2/comments', { post: postId, content: 'Allowed during the freeze', author_name: 'Sam', author_email: 'sam@example.com' } );
		expect( comment.status ).not.toBe( 423 );
		expect( comment.body.code ).not.toBe( 'axle_read_only_mode' );
		expect( ( await rest( 'POST', `/wp/v2/posts/${ postId }`, { title: 'Still frozen' } ) ).status ).toBe( 423 );

		// Front end: a form post with the query argument gets through, without it the visitor is turned away.
		const visitor = await browser.newContext( { storageState: { cookies: [], origins: [] } } );
		const form = { comment: 'Hi', comment_post_ID: String( postId ), author: 'Sam', email: 'sam@example.com' };
		expect( ( await visitor.request.post( '/wp-comments-post.php', { form } ) ).status() ).toBe( 503 );
		expect( ( await visitor.request.post( '/wp-comments-post.php?wc-api=wc_stripe', { form } ) ).status() ).not.toBe( 503 );
		await visitor.close();

		// Ajax: the action reaches WordPress (400 for an action nobody handles), another is stopped.
		const open = await page.request.post( '/wp-admin/admin-ajax.php', { form: { action: 'aro_open_action' } } );
		expect( open.status() ).toBe( 400 );
		const shut = await page.request.post( '/wp-admin/admin-ajax.php', { form: { action: 'aro_other_action' } } );
		expect( shut.status() ).toBe( 423 );

		// The listed user keeps editing, another editor does not.
		const { context, page: ops } = await logInAs( browser, 'aro-ops', 'aro-ops-pass' );
		const nonce = ( await ( await ops.request.get( '/wp-admin/admin-ajax.php?action=rest-nonce' ) ).text() ).trim();
		const edit = await ops.request.fetch( `/index.php?rest_route=${ encodeURIComponent( '/wp/v2/posts/' + postId ) }`, { method: 'POST', headers: { 'X-WP-Nonce': nonce }, data: { title: 'Edited by the listed user' } } );
		expect( edit.status() ).toBe( 200 );
		expect( wp( 'post', 'get', String( postId ), '--field=post_title' ) ).toBe( 'Edited by the listed user' );
		await context.close();
		wp( 'axle-read-only-mode', 'disallow', 'user', 'aro-ops@example.com' );
		const second = await logInAs( browser, 'aro-ops', 'aro-ops-pass' );
		const nonce2 = ( await ( await second.page.request.get( '/wp-admin/admin-ajax.php?action=rest-nonce' ) ).text() ).trim();
		const blocked = await second.page.request.fetch( `/index.php?rest_route=${ encodeURIComponent( '/wp/v2/posts/' + postId ) }`, { method: 'POST', headers: { 'X-WP-Nonce': nonce2 }, data: { title: 'Not allowed any more' } } );
		expect( blocked.status() ).toBe( 423 );
		await second.context.close();
		wp( 'post', 'update', String( postId ), '--post_title=Allow-list post' );

		// The log shows what was let through, marked as allowed, next to what was blocked.
		const log = JSON.parse( wp( 'axle-read-only-mode', 'log', '--format=json' ) );
		const results = log.map( ( entry ) => `${ entry.result }:${ entry.where }` );
		expect( results ).toContain( 'allowed:REST POST /wp/v2/comments' );
		expect( results ).toContain( 'allowed:admin-ajax aro_open_action' );
		expect( results ).toContain( 'allowed:POST /wp-comments-post.php' );
		expect( results ).toContain( `blocked:REST POST /wp/v2/posts/${ postId }` );
		expect( wp( 'axle-read-only-mode', 'log', '--format=count' ) ).toBe( String( log.length ) );

		await page.goto( PAGE );
		await expect( page.locator( '.axle-stats' ) ).toContainText( 'requests allowed through' );
		await expect( page.locator( '.aro-blocked tbody tr' ).filter( { hasText: 'REST POST /wp/v2/comments' } ).locator( '.axle-badge--good' ) ).toHaveText( 'Allowed' );
		await expect( page.locator( '.aro-blocked tbody tr' ).filter( { hasText: `REST POST /wp/v2/posts/${ postId }` } ).first().locator( '.axle-badge--error' ) ).toHaveText( 'Blocked' );
		expect( Number( wp( 'axle-read-only-mode', 'status' ).match( /Blocked so far:\s+(\d+)/ )[ 1 ] ) ).toBe( log.filter( ( entry ) => 'blocked' === entry.result ).length );
	} );

	test( 'code runs after the settings and can say no, or yes', async ( { rest, browser } ) => {
		wp( 'axle-read-only-mode', 'allow', 'rest', '/wp/v2/comments' );
		wp( 'axle-read-only-mode', 'on', '--minutes=30' );
		const comment = { post: postId, content: 'Hello', author_name: 'Sam', author_email: 'sam@example.com' };

		expect( ( await rest( 'POST', '/wp/v2/comments', comment ) ).status ).not.toBe( 423 );
		wp( 'option', 'update', 'aro_e2e_deny', '1' );
		expect( ( await rest( 'POST', '/wp/v2/comments', comment ) ).status ).toBe( 423 );
		wp( 'option', 'delete', 'aro_e2e_deny' );

		// A request the list does not know, let through by code, is logged as allowed by code.
		const visitor = await browser.newContext( { storageState: { cookies: [], origins: [] } } );
		expect( ( await visitor.request.post( '/code-allowed/' ) ).status() ).toBe( 503 );
		wp( 'option', 'update', 'aro_e2e_code_allow', '1' );
		expect( ( await visitor.request.post( '/code-allowed/' ) ).status() ).not.toBe( 503 );
		await visitor.close();
		const log = JSON.parse( wp( 'axle-read-only-mode', 'log', '--format=json' ) );
		expect( log.find( ( entry ) => entry.where === 'POST /code-allowed/' ) ).toMatchObject( { result: 'allowed' } );
		expect( JSON.parse( wpEval( 'echo wp_json_encode( array_values( array_filter( get_option( "axle_read_only_mode_log" ), function ( $e ) { return ! empty( $e["allowed"] ) && "code" === $e["via"]; } ) ) );' ) ) ).toHaveLength( 1 );
	} );

	test( 'suggestions are buttons: nothing is added until one is pressed', async ( { page } ) => {
		wp( 'option', 'update', 'aro_e2e_suggest', '1' );
		await page.goto( SETTINGS );
		await expect( page.locator( '.aro-suggestions' ) ).toContainText( 'Shop plugin: Payment callbacks.' );
		expect( wp( 'axle-read-only-mode', 'allowed', '--format=count' ) ).toBe( '0' );

		await page.getByRole( 'button', { name: 'Add ?shop-api=' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Entry added to the allow-list.' );
		expect( settings().allow.paths ).toEqual( [ '?shop-api=' ] );
		await expect( page.locator( '.aro-suggestions' ) ).toHaveCount( 0 );
	} );

	test( 'WP-CLI lists, adds and removes entries', () => {
		expect( wp( 'axle-read-only-mode', 'allow', 'rest', 'wc/v3/webhooks' ) ).toContain( 'Added' );
		wp( 'axle-read-only-mode', 'allow', 'ajax', 'My_Action' );
		const rows = JSON.parse( wp( 'axle-read-only-mode', 'allowed', '--format=json' ) );
		expect( rows.map( ( row ) => `${ row.type }:${ row.entry }` ) ).toEqual( [ 'rest:/wc/v3/webhooks', 'ajax:my_action' ] );

		expect( () => wp( 'axle-read-only-mode', 'allow', 'rest', '/wp/v2' ) ).toThrow( /core write/ );
		expect( wp( 'axle-read-only-mode', 'disallow', 'rest', '/wc/v3/webhooks' ) ).toContain( 'Removed' );
		expect( () => wp( 'axle-read-only-mode', 'disallow', 'rest', '/wc/v3/webhooks' ) ).toThrow( /not on the allow-list/ );
		expect( wp( 'axle-read-only-mode', 'allowed', '--format=count' ) ).toBe( '1' );
	} );

	test( 'agents can read the allow-list but not change it', async ( { rest } ) => {
		test.skip( 'yes' !== wpEval( "echo function_exists( 'wp_register_ability' ) ? 'yes' : 'no';" ), 'The Abilities API needs WordPress 6.9.' );
		wp( 'axle-read-only-mode', 'allow', 'ajax', 'my_action' );

		const result = await rest( 'GET', '/wp-abilities/v1/abilities/axle-read-only-mode/get-allow-list/run' );

		expect( result.status ).toBe( 200 );
		expect( result.body ).toMatchObject( { rest: [], paths: [], ajax: [ 'my_action' ], users: [] } );
	} );
} );
