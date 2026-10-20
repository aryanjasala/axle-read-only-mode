# Deploy Freeze

[![PHPCS](https://github.com/aryanjasala/deploy-freeze/actions/workflows/phpcs.yml/badge.svg)](https://github.com/aryanjasala/deploy-freeze/actions/workflows/phpcs.yml) [![PHPStan](https://github.com/aryanjasala/deploy-freeze/actions/workflows/phpstan.yml/badge.svg)](https://github.com/aryanjasala/deploy-freeze/actions/workflows/phpstan.yml) [![Unit tests](https://github.com/aryanjasala/deploy-freeze/actions/workflows/unit-tests.yml/badge.svg)](https://github.com/aryanjasala/deploy-freeze/actions/workflows/unit-tests.yml) [![E2E tests](https://github.com/aryanjasala/deploy-freeze/actions/workflows/e2e-tests.yml/badge.svg)](https://github.com/aryanjasala/deploy-freeze/actions/workflows/e2e-tests.yml) [![Plugin Check](https://github.com/aryanjasala/deploy-freeze/actions/workflows/plugin-check.yml/badge.svg)](https://github.com/aryanjasala/deploy-freeze/actions/workflows/plugin-check.yml)

Read-only mode for WordPress during a migration or deploy. Visitors still see the site, but nothing can be saved until the freeze ends, so nothing written on the old copy gets lost in the switch.

![Tools > Deploy Freeze during a freeze](.wordpress-org/screenshot-2.png)

## Features

While the site is frozen:

- wp-admin screens still open, but anything that changes data is refused: saves, uploads, settings, plugin installs and updates
- The block editor disables **Save** and **Publish** and says why. People who already have it open are locked within one heartbeat, and get their buttons back the same way
- REST and XML-RPC writes get `423 Locked`. Reads still work
- Visitor comments, forms, checkouts and sign-ups get a `503` with `Retry-After` and your own message (optional, on by default)
- WP-Cron is held back and runs when the freeze ends (optional, on by default)
- Logging in always works, so an admin can always unfreeze

And around it:

- A freeze can **end on its own**, so a deploy script that dies halfway cannot leave the site frozen
- Schedule a freeze for later, extend a running one by 30 minutes or an hour, or freeze for an hour straight from the admin bar
- Every **blocked attempt** is listed (who, when, what), so you know which edits to redo on the new server
- The last ten freezes are kept with how they ended and what they blocked

## Installation

1. Download `deploy-freeze.zip` from the [latest release](https://github.com/aryanjasala/deploy-freeze/releases/latest).
2. In WordPress, go to **Plugins > Add New Plugin > Upload Plugin**, choose the zip and activate it.

Or with WP-CLI:

```sh
wp plugin install https://github.com/aryanjasala/deploy-freeze/releases/latest/download/deploy-freeze.zip --activate
```

Needs WordPress 6.6+ and PHP 7.4+.

## Usage

Freeze from **Tools > Deploy Freeze**, the admin bar, or WP-CLI. Editors see this while it lasts:

![The block editor during a freeze](.wordpress-org/screenshot-1.png)

And a visitor who sends a comment or form gets your message:

![What visitors see when they send a form during a freeze](.wordpress-org/screenshot-3.png)

### From a deploy script

WP-CLI is never blocked, so a script can freeze, import and unfreeze:

```sh
wp deploy-freeze on --minutes=30 --reason="Moving servers"
# ... migrate ...
wp deploy-freeze off
```

Other commands: `wp deploy-freeze status`, `wp deploy-freeze extend <minutes>`, `wp deploy-freeze log`. Use `--no-visitors` or `--no-cron` to leave those running.

To freeze while files are being swapped, before the database is reachable, set it in wp-config.php:

```php
define( 'DEPLOY_FREEZE', true );
define( 'DEPLOY_FREEZE_REASON', 'Moving servers' );
```

### For developers

| Hook | Use |
| --- | --- |
| `deploy_freeze_user_can_bypass` (filter) | Let a user keep working during a freeze. Receives the current answer and the user ID |
| `deploy_freeze_allowed_ajax_actions` (filter) | Admin-ajax actions that only read data and should keep working during a freeze |

`AryanX\DeployFreeze\Plugin::instance()->freezer()` starts, extends and ends freezes from code.

## Development

```sh
composer install
npm ci
```

| Command | What it runs |
| --- | --- |
| `composer lint` | PHPCS with the WordPress standard and PHPCompatibility from PHP 7.4 |
| `composer analyse` | PHPStan level 6 with WordPress and WP-CLI stubs |
| `composer test` | PHPUnit unit tests. WordPress functions are mocked with Brain Monkey, so no database is needed |
| `npm run env:start` | WordPress and MariaDB in Docker on http://localhost:8895 |
| `npm run test:e2e` | Playwright end-to-end tests against that site, in headless Chrome |
| `npm run screenshots` | Regenerates the screenshots in `.wordpress-org/` |
| `npm run env:stop` | Removes the site and its database |

The plugin is mounted read-only into the Docker site, so nothing in WordPress can change or delete the source. The end-to-end run empties the site's content before it starts, so point it only at this throwaway site.

## Tests

- **12 unit tests**: running, expired, scheduled and open-ended freezes, the messages, who can bypass, freezing, extending, unfreezing into history, and expiry
- **7 end-to-end tests**: freezing from the screen (editor, REST and Settings all refused, nothing saved), a logged-out visitor posting a comment, the blocked list with extend and unfreeze, exempt users, the admin bar, the heartbeat lock on an open editor, and scheduled and expired freezes with WP-CLI

## Contributing

`main` is protected: every change goes through a pull request, and it can only be merged once these pass:

- PHPCS and PHPStan
- PHPUnit on PHP 7.4, 8.0, 8.1, 8.2, 8.3 and 8.4
- the end-to-end suite against the latest WordPress
- the WordPress.org Plugin Check on the built zip
- the PR title check

Pull requests are squash-merged, so the **PR title becomes the commit on `main`** and decides the next release. Use a [Conventional Commit](https://www.conventionalcommits.org/) title:

| Title | Release |
| --- | --- |
| `feat: add bulk restore` | minor (1.1.0 to 1.2.0) |
| `fix: keep the notice on narrow screens`, `perf: ...` | patch (1.1.0 to 1.1.1) |
| `feat!: drop PHP 7.4`, or a `BREAKING CHANGE:` footer | major (1.1.0 to 2.0.0) |
| `docs:`, `ci:`, `chore:`, `test:`, `refactor:`, `build:` | none |

## Releases

Releases are automatic:

1. After each merge to `main`, the **Prepare release** workflow checks for `feat`, `fix`, `perf` or breaking commits since the last tag. If there are any, it opens (or updates) a `chore(release): x.y.z` pull request that bumps the version in the plugin header, the `VERSION` constant and the readme `Stable tag`, and adds the changelog entry to `readme.txt`.
2. Merging that pull request runs the **Release** workflow: it tags `vx.y.z`, builds the zip with `bin/build-zip.sh` (leaving out everything in `.distignore`) and publishes the GitHub release with the changelog entry as notes.

`bin/release.sh next` and `bin/release.sh notes` show locally what the next release would contain.

Deploying to WordPress.org is off until the repository variable `WPORG_DEPLOY` is `true` and the secrets `SVN_USERNAME` and `SVN_PASSWORD` are set. The deploy uses `.wordpress-org/` for the screenshots.

## License

GPL-2.0-or-later
