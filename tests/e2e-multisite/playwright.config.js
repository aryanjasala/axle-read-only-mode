// End-to-end tests on a multisite network: network freezes, per-site freezes and the network log.
// Start the network first: docker compose -f tests/e2e-multisite/docker-compose.yml up -d
// Then: npx playwright test -c tests/e2e-multisite/playwright.config.js
// The network lives on its own port (8921), apart from the single-site test site on 8911.
const path = require( 'path' );

// Point the shared helpers (WP-CLI in the cli container, base URL) at the network, before they load.
process.env.WP_PORT = process.env.WP_PORT || '8921';
process.env.WP_COMPOSE_DIR = process.env.WP_COMPOSE_DIR || __dirname;

const { defineConfig } = require( '@playwright/test' );
const { BASE_URL } = require( '../e2e/utils/config' );

module.exports = defineConfig( {
	testDir: '.',
	testMatch: '**/*.spec.js',
	workers: 1,
	fullyParallel: false,
	retries: process.env.CI ? 1 : 0,
	timeout: 90_000,
	expect: { timeout: 10_000 },
	reporter: [ [ 'list' ] ],
	globalSetup: require.resolve( './global-setup.js' ),
	use: {
		baseURL: BASE_URL,
		storageState: path.join( __dirname, '..', 'e2e', '.auth', 'admin-multisite.json' ),
		channel: process.env.PW_CHANNEL || 'chrome',
		headless: true,
		viewport: { width: 1440, height: 900 },
		screenshot: 'only-on-failure',
		trace: 'retain-on-failure',
	},
} );
