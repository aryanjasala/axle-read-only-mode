// Turns the test site into a network (once), puts it in a known state, and logs in as the super admin.
const fs = require( 'fs' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const { request } = require( '@playwright/test' );
const { SLUG, BASE_URL, USERNAME, PASSWORD, COMPOSE_DIR } = require( '../e2e/utils/config' );
const { wp } = require( '../e2e/utils/wp-cli' );

const HOST = new URL( BASE_URL ).host;

// What WordPress needs from Apache for sub-directory sites.
const HTACCESS = `# BEGIN WordPress Multisite
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteBase /
RewriteRule ^index\\.php$ - [L]
RewriteRule ^([_0-9a-zA-Z-]+/)?wp-admin$ $1wp-admin/ [R=301,L]
RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]
RewriteRule ^([_0-9a-zA-Z-]+/)?(wp-(content|admin|includes).*) $2 [L]
RewriteRule ^([_0-9a-zA-Z-]+/)?(.*\\.php)$ $2 [L]
RewriteRule . index.php [L]
# END WordPress Multisite
`;

function waitForDatabase() {
	const deadline = Date.now() + 120000;
	for ( ;; ) {
		try {
			wp( 'db', 'check' );
			return;
		} catch ( error ) {
			if ( Date.now() > deadline ) {
				throw new Error( 'The network test site did not come up. Start it: docker compose -f tests/e2e-multisite/docker-compose.yml up -d' );
			}
			Atomics.wait( new Int32Array( new SharedArrayBuffer( 4 ) ), 0, 0, 2000 );
		}
	}
}

module.exports = async function globalSetup() {
	waitForDatabase();

	try {
		wp( 'core', 'is-installed' );
	} catch ( error ) {
		wp( 'core', 'install', `--url=${ HOST }`, '--title=E2E', `--admin_user=${ USERNAME }`, `--admin_password=${ PASSWORD }`, '--admin_email=admin@example.com', '--skip-email' );
	}

	if ( 'false' === wp( 'eval', 'echo is_multisite() ? "true" : "false";' ) ) {
		wp( 'core', 'multisite-convert', '--title=E2E network' );
		execFileSync( 'docker', [ 'compose', 'exec', '-T', 'cli', 'sh', '-c', 'cat > /var/www/html/.htaccess' ], { cwd: COMPOSE_DIR, input: HTACCESS } );
	}

	for ( const [ slug, title ] of [ [ 'two', 'Site Two' ], [ 'three', 'Site Three' ] ] ) {
		if ( ! wp( 'site', 'list', '--field=url' ).includes( `/${ slug }/` ) ) {
			wp( 'site', 'create', `--slug=${ slug }`, `--title=${ title }` );
		}
		wp( 'option', 'update', 'blogname', title, `--url=${ HOST }/${ slug }` );
	}

	wp( 'theme', 'enable', process.env.WP_THEME || 'twentytwentyfive', '--network' );
	wp( 'plugin', 'activate', SLUG, '--network' );
	wp( 'eval', fs.readFileSync( path.join( __dirname, 'reset.php' ), 'utf8' ).replace( /^<\?php\s*/, '' ) );

	const context = await request.newContext( { baseURL: BASE_URL } );
	await context.get( '/wp-login.php' );
	const response = await context.post( '/wp-login.php', {
		form: { log: USERNAME, pwd: PASSWORD, rememberme: 'forever', testcookie: '1', 'wp-submit': 'Log In', redirect_to: '/wp-admin/' },
		headers: { Cookie: 'wordpress_test_cookie=WP%20Cookie%20check' },
	} );
	if ( ! response.url().includes( '/wp-admin' ) ) {
		throw new Error( `Login failed, ended on ${ response.url() }` );
	}

	const state = path.join( __dirname, '..', 'e2e', '.auth', 'admin-multisite.json' );
	fs.mkdirSync( path.dirname( state ), { recursive: true } );
	await context.storageState( { path: state } );
	await context.dispose();
};
