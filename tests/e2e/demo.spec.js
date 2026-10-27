// Records .github/media/demo.gif for the README. Run on demand: npm run demo
const { test, expect, wp, openEditor } = require( './utils' );
const { prepareSite, recordDemo } = require( './utils/media' );

const reset = () => {
	for ( const option of [ 'axle_read_only_mode_state', 'axle_read_only_mode_log', 'axle_read_only_mode_history' ] ) {
		try {
			wp( 'option', 'delete', option );
		} catch ( error ) {
			// Not set.
		}
	}
};

test( 'demo: freeze, see what is blocked, unfreeze', async ( { browser } ) => {
	test.setTimeout( 180_000 );
	prepareSite( 'Kitchen Notes' );
	reset();
	const post = wp(
		'post',
		'create',
		'--post_title=Spring menu',
		'--post_status=publish',
		'--post_content=<!-- wp:paragraph --><p>Our spring menu starts on Monday, with three new dishes and a lemon tart.</p><!-- /wp:paragraph -->',
		'--porcelain'
	);

	try {
		await recordDemo( browser, async ( page, demo ) => {
			await page.goto( '/wp-admin/tools.php?page=axle-read-only-mode' );
			await demo.start();
			await demo.pause( 1200 );
			await demo.type( page.locator( '#aro-reason' ), 'Moving to the new server' );
			await demo.choose( page.locator( '#aro-minutes' ), '60' );
			await demo.click( page.getByRole( 'button', { name: 'Freeze the site' } ), 300 );
			await expect( page.locator( '.aro-status h2' ) ).toHaveText( 'The site is frozen' );
			await demo.moveTo( page.locator( '.aro-countdown' ) );
			await demo.pause( 1500 );
			await demo.moveTo( page.locator( '#wp-admin-bar-axle-read-only-mode > a' ) );
			await demo.pause( 1000 );

			// The editor is read-only for the length of the freeze.
			await openEditor( page, Number( post ) );
			await expect( page.locator( '.components-notice' ).filter( { hasText: 'frozen for maintenance' } ) ).toBeVisible();
			await demo.moveTo( page.locator( '.components-notice' ).filter( { hasText: 'frozen for maintenance' } ) );
			await demo.pause( 2200 );
			// A save from an editor left open in another tab, refused by the REST API.
			const status = await page.evaluate( ( id ) => window.wp.apiFetch( { path: `/wp/v2/posts/${ id }`, method: 'POST', data: { title: 'Spring menu (new prices)' } } ).then( () => 200, ( error ) => error.data && error.data.status ), Number( post ) );
			expect( status ).toBe( 423 );

			// Visitors can still read the site. The comment form is closed until the freeze ends,
			// and a comment sent anyway gets the maintenance message.
			await page.goto( `/?p=${ post }` );
			await demo.moveTo( page.locator( '.wp-block-post-title' ).first() );
			await demo.pause( 1800 );
			const comment = await page.evaluate( ( id ) => fetch( '/wp-comments-post.php', { method: 'POST', credentials: 'omit', body: new URLSearchParams( { comment: 'See you on Monday!', author: 'Sam', email: 'sam@example.com', comment_post_ID: String( id ) } ) } ).then( ( response ) => response.status ), Number( post ) );
			expect( comment ).toBe( 503 );

			await page.goto( '/wp-admin/tools.php?page=axle-read-only-mode' );
			await expect( page.locator( '.aro-blocked tbody tr' ) ).toHaveCount( 2 );
			await demo.moveTo( page.locator( '.aro-blocked tbody tr' ).first() );
			await demo.pause( 1800 );
			await demo.click( page.getByRole( 'button', { name: 'Unfreeze now' } ), 300 );
			await expect( page.locator( '.notice-success' ) ).toContainText( 'The site is unfrozen.' );
			await demo.moveTo( page.locator( '.aro-history tbody tr' ).first() );
			await demo.pause( 1800 );
		} );
	} finally {
		reset();
		wp( 'post', 'delete', post, '--force' );
	}
} );
