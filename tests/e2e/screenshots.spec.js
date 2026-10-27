// Regenerates .wordpress-org/screenshot-N.png (readme.txt "== Screenshots ==" and README.md).
// Run on demand: npm run screenshots
const { test, expect, wp, wpEval, openEditor } = require( './utils' );
const { prepareSite, shot } = require( './utils/media' );

test.describe.serial( 'Screenshots', () => {
	test.use( { viewport: { width: 1280, height: 800 }, deviceScaleFactor: 2 } );
	let postId;

	test.beforeAll( () => {
		prepareSite( 'Kitchen Notes' );
		postId = wp(
			'post',
			'create',
			'--post_title=Spring menu',
			'--post_status=publish',
			'--post_content=<!-- wp:paragraph --><p>Our spring menu starts on Monday, with three new dishes and a lemon tart.</p><!-- /wp:paragraph -->',
			'--porcelain'
		);
		// One finished freeze for the history table.
		wpEval( `AryanX\\AxleReadOnlyMode\\Plugin::instance()->freezer()->freeze( array( 'start' => time() - 9000, 'minutes' => 45, 'reason' => 'PHP 8.3 upgrade' ) );` );
		wpEval( `AryanX\\AxleReadOnlyMode\\Plugin::instance()->freezer()->expire_if_needed();` );
		wp( 'axle-read-only-mode', 'on', '--minutes=90', '--reason=Moving to the new server', '--visitor-message=We are moving to a new server and will be back within the hour. Please try again soon.' );
	} );

	test.afterAll( () => {
		wp( 'axle-read-only-mode', 'off' );
		for ( const option of [ 'axle_read_only_mode_state', 'axle_read_only_mode_log', 'axle_read_only_mode_history' ] ) {
			wp( 'option', 'delete', option );
		}
		wp( 'post', 'delete', postId, '--force' );
	} );

	test( '1: the block editor during a freeze', async ( { page } ) => {
		await openEditor( page, postId );
		await expect( page.locator( '.components-notice' ).filter( { hasText: 'frozen for maintenance' } ) ).toBeVisible();
		await shot( page, 1 );
	} );

	test( '3: what visitors see when they send a form', async ( { browser } ) => {
		const visitor = await browser.newContext( { storageState: { cookies: [], origins: [] }, viewport: { width: 1280, height: 800 }, deviceScaleFactor: 2 } );
		const page = await visitor.newPage();
		const response = await page.request.post( '/wp-comments-post.php', { form: { comment: 'Looks great!', comment_post_ID: postId, author: 'Sam', email: 'sam@example.com' } } );
		expect( response.status() ).toBe( 503 );
		await page.setContent( await response.text() );
		await shot( page, 3 );
		await visitor.close();
	} );

	test( '2: Tools > Axle Read-Only Mode with the countdown and blocked attempts', async ( { page, rest } ) => {
		await rest( 'POST', `/wp/v2/posts/${ postId }`, { title: 'Spring menu (updated)' } );
		await page.goto( '/wp-admin/tools.php?page=axle-read-only-mode' );
		await expect( page.locator( '.aro-blocked tbody tr' ).first() ).toBeVisible();
		await page.waitForTimeout( 1200 );
		await shot( page, 2 );
	} );
} );
