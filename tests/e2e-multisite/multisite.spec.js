// Network freezes on a multisite network. See playwright.multisite.config.js for how to run it.
const { test, expect, wp, wpEval } = require( '../e2e/utils' );
const { expectNoSeriousA11yViolations } = require( '../e2e/utils/a11y' );
const { BASE_URL } = require( '../e2e/utils/config' );

const HOST = new URL( BASE_URL ).host;
const NETWORK_PAGE = '/wp-admin/network/settings.php?page=axle-read-only-mode';
const TOOLS = '/wp-admin/tools.php?page=axle-read-only-mode';

// WP-CLI against one site of the network: 'two', or '' for the main site.
const site = ( slug, ...args ) => wp( ...args, `--url=${ HOST }${ slug ? `/${ slug }` : '' }` );
const status = ( slug, ...flags ) => site( slug, 'axle-read-only-mode', 'status', ...flags );
const network = () => JSON.parse( wpEval( 'echo wp_json_encode( get_site_option( "axle_read_only_mode_network_state" ) );' ) );

const reset = () =>
	wp(
		'eval',
		`delete_site_option( 'axle_read_only_mode_network_state' ); delete_site_option( 'axle_read_only_mode_network_history' ); delete_site_option( 'axle_read_only_mode_network_settings' );
		foreach ( get_sites( array( 'fields' => 'ids' ) ) as $id ) { switch_to_blog( $id ); foreach ( array( 'state', 'log', 'history', 'settings' ) as $n ) { delete_option( 'axle_read_only_mode_' . $n ); } restore_current_blog(); }`
	);

// A REST call on a site of the network, as the logged-in super admin.
async function restOn( request, slug, method, route, data ) {
	const base = slug ? `/${ slug }` : '';
	const nonce = ( await ( await request.get( `${ base }/wp-admin/admin-ajax.php?action=rest-nonce` ) ).text() ).trim();
	const response = await request.fetch( `${ base }/index.php?rest_route=${ encodeURIComponent( route ) }`, { method, headers: { 'X-WP-Nonce': nonce }, data } );
	return { status: response.status(), body: await response.json().catch( () => null ) };
}

const MAIL_FIXTURE = 'aro-e2e-mail.php';
const mail = () => JSON.parse( wpEval( 'echo wp_json_encode( get_site_option( "aro_e2e_mail", array() ) );' ) );

test.describe( 'Axle Read-Only Mode on a network', () => {
	test.beforeEach( reset );
	test.afterAll( reset );

	test( 'a network freeze reaches every site and a site cannot lift it', async ( { page, request } ) => {
		await page.goto( NETWORK_PAGE );
		await page.locator( '#aro-reason' ).fill( 'Moving the network' );
		await page.getByRole( 'button', { name: 'Freeze the network' } ).click();
		await expect( page.locator( '.aro-status .axle-card__title' ) ).toHaveText( 'The network is frozen' );
		await expect( page.locator( '.aro-status .axle-facts' ) ).toContainText( 'Every site on the network' );
		expect( network() ).toMatchObject( { active: true, reason: 'Moving the network', scope: 'all' } );

		for ( const slug of [ '', 'two', 'three' ] ) {
			expect( status( slug ) ).toContain( 'frozen by the network' );
		}

		// Site two shows who froze it, and offers no way to lift it.
		await page.goto( '/two/wp-admin/' );
		await expect( page.locator( '.axle-read-only-mode-notice' ) ).toContainText( 'frozen by the network' );
		await expect( page.locator( '.axle-read-only-mode-notice' ) ).toContainText( 'Reason: Moving the network' );
		await expect( page.locator( '.axle-read-only-mode-notice' ).getByRole( 'link', { name: 'Unfreeze now' } ) ).toHaveCount( 0 );
		await expect( page.locator( '#wp-admin-bar-axle-read-only-mode' ) ).toContainText( 'Frozen by the network until' );
		await page.goto( `/two${ TOOLS }` );
		await expect( page.locator( '.axle-status' ) ).toContainText( 'Frozen by the network' );
		await expect( page.locator( '.aro-network' ) ).toContainText( 'The network froze this site' );

		// A site that unfreezes itself is still frozen by the network.
		site( 'two', 'axle-read-only-mode', 'on', '--minutes=5' );
		expect( site( 'two', 'axle-read-only-mode', 'off' ) ).toContain( 'Site unfrozen' );
		expect( status( 'two' ) ).toContain( 'frozen by the network' );

		const save = await restOn( request, 'two', 'POST', '/wp/v2/posts/1', { title: 'Changed during the freeze' } );
		expect( save.status ).toBe( 423 );
		expect( save.body.message ).toContain( 'frozen by the network' );
		expect( wp( 'post', 'get', '1', '--field=post_title', `--url=${ HOST }/two` ) ).not.toBe( 'Changed during the freeze' );

		const visitor = await page.context().browser().newContext( { baseURL: BASE_URL, storageState: { cookies: [], origins: [] } } );
		const comment = await visitor.request.post( '/three/wp-comments-post.php', { form: { comment: 'Hi', comment_post_ID: '1', author: 'Sam', email: 'sam@example.com' } } );
		expect( comment.status() ).toBe( 503 );
		await visitor.close();

		// The network screen keeps working during the freeze, so it can be lifted.
		await page.goto( NETWORK_PAGE );
		await page.getByRole( 'button', { name: 'Unfreeze the network' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'The network is unfrozen.' );
		for ( const slug of [ '', 'two', 'three' ] ) {
			expect( status( slug ) ).toBe( 'Not frozen.' );
		}
		await expect( page.locator( '.aro-history tbody tr' ).first() ).toContainText( 'Moving the network' );
	} );

	test( 'a freeze can cover chosen sites only', async ( { page } ) => {
		await page.goto( NETWORK_PAGE );
		await page.getByLabel( 'Only the sites I tick below' ).check();
		await page.getByRole( 'button', { name: 'Freeze the network' } ).click();
		await expect( page.locator( '.notice-error' ) ).toContainText( 'Tick at least one site' );
		expect( network().active ).toBeFalsy();

		await page.getByLabel( 'Only the sites I tick below' ).check();
		await page.getByLabel( /Site Two/ ).check();
		await page.getByRole( 'button', { name: 'Freeze the network' } ).click();
		await expect( page.locator( '.aro-status .axle-facts' ) ).toContainText( '1 site: Site Two' );

		expect( status( 'two' ) ).toContain( 'frozen by the network' );
		expect( status( '' ) ).toBe( 'Not frozen.' );
		expect( status( 'three' ) ).toBe( 'Not frozen.' );

		wp( 'axle-read-only-mode', 'off', '--network' );
		expect( wp( 'axle-read-only-mode', 'on', '--network', '--sites=3', '--minutes=10' ) ).toContain( 'for 1 site(s)' );
		expect( status( 'three' ) ).toContain( 'frozen by the network' );
		expect( status( 'two' ) ).toBe( 'Not frozen.' );
	} );

	test( 'each site logs what it blocked, and the network sees all of it', async ( { page, request } ) => {
		wp( 'axle-read-only-mode', 'on', '--network', '--minutes=30' );
		expect( ( await restOn( request, 'two', 'POST', '/wp/v2/posts/1', { title: 'a' } ) ).status ).toBe( 423 );
		expect( ( await restOn( request, 'two', 'POST', '/wp/v2/posts/1', { title: 'b' } ) ).status ).toBe( 423 );
		expect( ( await restOn( request, 'three', 'POST', '/wp/v2/posts/1', { title: 'c' } ) ).status ).toBe( 423 );

		// Each site has its own log.
		expect( site( 'two', 'axle-read-only-mode', 'log', '--format=count' ) ).toBe( '2' );
		expect( site( 'three', 'axle-read-only-mode', 'log', '--format=count' ) ).toBe( '1' );
		expect( site( '', 'axle-read-only-mode', 'log', '--format=count' ) ).toBe( '0' );

		await page.goto( `/two${ TOOLS }` );
		await expect( page.locator( '.aro-blocked h2' ) ).toHaveText( 'Blocked during this freeze (2)' );

		// The network view lists each site, and one list across them.
		await page.goto( NETWORK_PAGE );
		await expect( page.locator( '.axle-stat__value' ).first() ).toHaveText( '3' );
		const rows = page.locator( '.aro-site-log tbody tr' );
		await expect( rows ).toHaveCount( 2 );
		await expect( rows.first() ).toContainText( 'Site Two' );
		await expect( rows.first().locator( '.num' ) ).toHaveText( '2' );
		await expect( page.locator( '.aro-network-log tbody tr' ) ).toHaveCount( 3 );

		const log = JSON.parse( wp( 'axle-read-only-mode', 'log', '--network', '--format=json' ) );
		expect( log.map( ( entry ) => String( entry.site ) ).sort() ).toEqual( [ '2', '2', '3' ] );
		expect( wp( 'axle-read-only-mode', 'status', '--network' ) ).toContain( 'Blocked so far:             3' );

		// Ending the freeze keeps the count in the network history.
		wp( 'axle-read-only-mode', 'off', '--network' );
		expect( JSON.parse( wpEval( 'echo wp_json_encode( get_site_option( "axle_read_only_mode_network_history" )[0] );' ) ) ).toMatchObject( { ended_by: 'cli', blocked: 3 } );
	} );

	test( 'a site admin can still freeze their own site from Tools', async ( { page } ) => {
		await page.goto( `/two${ TOOLS }` );
		await page.locator( '#aro-reason' ).fill( 'Site two deploy' );
		await page.getByRole( 'button', { name: 'Freeze the site' } ).click();
		await expect( page.locator( '.aro-status .axle-card__title' ) ).toHaveText( 'The site is frozen' );

		expect( status( 'two' ) ).toContain( 'Frozen by:                  site' );
		expect( status( '' ) ).toBe( 'Not frozen.' );
		expect( network().active ).toBeFalsy();

		// The site's own freeze is its own: a network freeze on top does not change it.
		wp( 'axle-read-only-mode', 'on', '--network', '--minutes=10' );
		wp( 'axle-read-only-mode', 'off', '--network' );
		expect( status( 'two' ) ).toContain( 'Frozen by:                  site' );
	} );

	test( 'a network freeze uses the network allow-list and ignores the one on each site', async ( { page, browser } ) => {
		site( 'two', 'axle-read-only-mode', 'allow', 'path', '?site-only=' );
		wp( 'axle-read-only-mode', 'allow', 'path', '?net-ok=', '--network' );
		expect( wp( 'axle-read-only-mode', 'allowed', '--network', '--format=count' ) ).toBe( '1' );
		expect( site( 'two', 'axle-read-only-mode', 'allowed', '--format=count' ) ).toBe( '1' );

		// The network's list is edited in Network Admin, on its own tab.
		await page.goto( `${ NETWORK_PAGE }&tab=settings` );
		await page.getByLabel( 'Ajax actions' ).fill( 'net_action' );
		await page.getByRole( 'button', { name: 'Save settings' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Settings saved.' );
		expect( JSON.parse( wpEval( 'echo wp_json_encode( get_site_option( "axle_read_only_mode_network_settings" ) );' ) ).allow ).toMatchObject( { paths: [ '?net-ok=' ], ajax: [ 'net_action' ] } );
		await expect( page.locator( '.aro-matches' ).first() ).toContainText( 'net-ok' );

		wp( 'axle-read-only-mode', 'on', '--network', '--minutes=30' );
		const visitor = await browser.newContext( { baseURL: BASE_URL, storageState: { cookies: [], origins: [] } } );
		const form = { comment: 'Hi', comment_post_ID: '1', author: 'Sam', email: 'sam@example.com' };
		expect( ( await visitor.request.post( '/two/wp-comments-post.php?site-only=1', { form } ) ).status() ).toBe( 503 );
		expect( ( await visitor.request.post( '/two/wp-comments-post.php?net-ok=1', { form } ) ).status() ).not.toBe( 503 );
		await visitor.close();

		const log = JSON.parse( wp( 'axle-read-only-mode', 'log', '--network', '--format=json' ) );
		expect( log.map( ( entry ) => `${ entry.site }:${ entry.result }` ).sort() ).toEqual( [ '2:allowed', '2:blocked' ] );

		await page.goto( NETWORK_PAGE );
		await expect( page.locator( '.aro-network-log tbody tr' ).filter( { hasText: 'Allowed' } ) ).toHaveCount( 1 );
		await expect( page.locator( '.aro-site-log tbody tr' ).first().locator( '.num' ) ).toHaveText( '1' );

		// Without the network freeze, the site's own list applies again.
		wp( 'axle-read-only-mode', 'off', '--network' );
		site( 'two', 'axle-read-only-mode', 'on', '--minutes=30' );
		const again = await browser.newContext( { baseURL: BASE_URL, storageState: { cookies: [], origins: [] } } );
		expect( ( await again.request.post( '/two/wp-comments-post.php?site-only=1', { form } ) ).status() ).not.toBe( 503 );
		await again.close();
	} );

	test( 'a network freeze emails the network admin, and a site freeze emails the site', async ( { page } ) => {
		// Catches every wp_mail() in a network option, on every site.
		wpEval( `wp_mkdir_p( WPMU_PLUGIN_DIR ); copy( WP_PLUGIN_DIR . '/axle-read-only-mode/tests/e2e/fixtures/${ MAIL_FIXTURE }', WPMU_PLUGIN_DIR . '/${ MAIL_FIXTURE }' ); file_put_contents( WPMU_PLUGIN_DIR . '/aro-e2e-mail-network.php', '<?php add_filter( "pre_wp_mail", function ( $n, $a ) { $m = get_site_option( "aro_e2e_mail", array() ); $m[] = array( "to" => (array) $a["to"], "subject" => $a["subject"], "message" => $a["message"] ); update_site_option( "aro_e2e_mail", $m ); return true; }, 5, 2 );' );` );
		try {
			wp( 'eval', 'delete_site_option( "aro_e2e_mail" ); foreach ( array( "started", "ended" ) as $e ) { delete_site_transient( "axle_read_only_mode_mail_" . $e . "_network" ); delete_transient( "axle_read_only_mode_mail_" . $e ); }' );

			await page.goto( `${ NETWORK_PAGE }&tab=settings` );
			await page.getByLabel( 'Send an email when a freeze changes' ).check();
			await page.locator( '#aro-notify-recipients' ).fill( 'network-ops@example.com' );
			await page.getByRole( 'button', { name: 'Save settings' } ).click();
			await expect( page.locator( '.notice-success' ) ).toContainText( 'Settings saved.' );

			wp( 'axle-read-only-mode', 'on', '--network', '--minutes=30', '--reason=Moving the network' );
			wp( 'axle-read-only-mode', 'off', '--network' );

			const sent = mail();
			expect( sent.map( ( email ) => email.subject.replace( /^\[[^\]]+\] /, '' ) ) ).toEqual( [ 'Network freeze started', 'Network freeze ended' ] );
			expect( sent[ 0 ].to ).toEqual( [ 'network-ops@example.com' ] );
			expect( sent[ 0 ].message ).toContain( 'Covers: every site on the network' );
			expect( sent[ 1 ].message ).toContain( 'network/settings.php?page=axle-read-only-mode' );

			// A site freeze is the site's own business: the network recipients are not told.
			site( 'two', 'axle-read-only-mode', 'on', '--minutes=30' );
			expect( mail() ).toHaveLength( 2 );
		} finally {
			wpEval( `@unlink( WPMU_PLUGIN_DIR . '/${ MAIL_FIXTURE }' ); @unlink( WPMU_PLUGIN_DIR . '/aro-e2e-mail-network.php' );` );
		}
	} );

	test( 'Site Health on a site shows the network freeze', async ( { page } ) => {
		wp( 'axle-read-only-mode', 'on', '--network', '--sites=2', '--minutes=30' );

		expect( site( 'two', 'axle-read-only-mode', 'health' ) ).toContain( 'RECOMMENDED: The site is read-only' );
		expect( site( 'two', 'axle-read-only-mode', 'health' ) ).toContain( 'set by the network' );
		expect( site( 'three', 'axle-read-only-mode', 'health' ) ).toContain( 'GOOD: The site is not read-only' );

		await page.goto( '/two/wp-admin/site-health.php?tab=debug' );
		const section = page.locator( '#health-check-accordion-block-axle-read-only-mode' );
		await expect( section ).toContainText( 'The network' );
		await expect( section ).toContainText( 'Network freeze covers' );
		await expect( section ).toContainText( '1 site' );
	} );

	test( 'works when the plugin is active on one site only', async ( { request } ) => {
		wp( 'plugin', 'deactivate', 'axle-read-only-mode', '--network' );
		wp( 'plugin', 'activate', 'axle-read-only-mode', `--url=${ HOST }/two` );
		try {
			site( 'two', 'axle-read-only-mode', 'on', '--network', '--minutes=10' );
			expect( status( 'two' ) ).toContain( 'frozen by the network' );
			expect( ( await restOn( request, 'two', 'POST', '/wp/v2/posts/1', { title: 'x' } ) ).status ).toBe( 423 );
			// The plugin is not running on site three, so nothing is enforced there.
			expect( ( await restOn( request, 'three', 'POST', '/wp/v2/posts/1', { title: 'Site three keeps saving' } ) ).status ).toBe( 200 );
		} finally {
			wp( 'plugin', 'deactivate', 'axle-read-only-mode', `--url=${ HOST }/two` );
			wp( 'plugin', 'activate', 'axle-read-only-mode', '--network' );
			wp( 'post', 'update', '1', '--post_title=Hello world!', `--url=${ HOST }/three` );
		}
	} );

	test( 'screens have no serious accessibility problems', async ( { page } ) => {
		const scan = ( name, include = [ '#wpbody-content' ] ) => expectNoSeriousA11yViolations( page, { name, include } );

		await page.goto( NETWORK_PAGE );
		await scan( 'Network Admin > Read-Only Mode, not frozen' );
		await page.goto( `${ NETWORK_PAGE }&tab=settings` );
		await scan( 'Network Admin > Read-Only Mode > Settings' );

		wp( 'axle-read-only-mode', 'on', '--network', '--minutes=90', '--reason=Moving the network' );
		wp( 'eval', 'switch_to_blog( 2 ); update_option( "axle_read_only_mode_log", array( array( "time" => time() - 30, "user" => 1, "where" => "REST POST /wp/v2/posts/1", "source" => "rest" ) ), false );' );
		await page.goto( NETWORK_PAGE );
		await page.locator( '.aro-countdown' ).waitFor();
		await scan( 'Network Admin > Read-Only Mode, frozen with blocked attempts' );

		await page.goto( `/two${ TOOLS }` );
		await scan( 'Tools > Read-Only Mode, frozen by the network' );
		await page.goto( '/two/wp-admin/' );
		await scan( 'Dashboard notice and toolbar item, frozen by the network', [ '.axle-read-only-mode-notice', '#wp-admin-bar-axle-read-only-mode' ] );

		wp( 'axle-read-only-mode', 'off', '--network' );
		wp( 'axle-read-only-mode', 'on', '--network', '--minutes=30', `--reason=Release night` );
		wp( 'eval', 'update_site_option( "axle_read_only_mode_network_state", array_merge( get_site_option( "axle_read_only_mode_network_state" ), array( "start" => time() + 3600 ) ) );' );
		await page.goto( NETWORK_PAGE );
		await page.locator( '.aro-status.is-scheduled' ).waitFor();
		await scan( 'Network Admin > Read-Only Mode, freeze scheduled' );
	} );

	test( 'uninstalling removes the network and site options', async () => {
		wp( 'axle-read-only-mode', 'on', '--network', '--minutes=30' );
		wp( 'axle-read-only-mode', 'off', '--network' );
		site( 'two', 'axle-read-only-mode', 'on', '--minutes=30' );
		site( 'two', 'option', 'add', 'axle_read_only_mode_expired_123', '1' );
		site( 'two', 'option', 'add', '_transient_axle_read_only_mode_mail_started', '1' );
		wp( 'network', 'meta', 'add', '1', 'axle_read_only_mode_network_expired_123', '1' );

		// Runs uninstall.php without deleting the plugin, whose folder is the source on disk.
		wp( 'eval', 'define( "WP_UNINSTALL_PLUGIN", "axle-read-only-mode/axle-read-only-mode.php" ); require WP_PLUGIN_DIR . "/axle-read-only-mode/uninstall.php";' );

		const left = JSON.parse(
			wpEval( `
				global $wpdb;
				$found = array();
				foreach ( get_sites( array( 'fields' => 'ids' ) ) as $id ) {
					switch_to_blog( $id );
					$found[ 'site ' . $id ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '%axle\\_read\\_only\\_mode%'" );
					restore_current_blog();
				}
				$found['network'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->sitemeta} WHERE meta_key LIKE '%axle\\_read\\_only\\_mode%'" );
				echo wp_json_encode( $found );
			` )
		);
		expect( Object.values( left ).every( ( count ) => 0 === count ), JSON.stringify( left ) ).toBe( true );
	} );
} );
