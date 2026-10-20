# Deploy Freeze

Read-only mode for WordPress during a migration or deploy. Visitors still see the site, but nothing can be saved until the freeze ends, so nothing written on the old copy gets lost in the switch.

While the site is frozen:

- wp-admin screens still open, but anything that changes data is refused: saves, uploads, settings, plugin installs and updates
- The block editor disables Save and Publish and says why. Editors who already have it open are locked within one heartbeat, and get their buttons back the same way
- REST and XML-RPC writes get `423 Locked`. Reads still work
- Visitor comments, forms, checkouts and sign-ups get a `503` with `Retry-After` and a custom message (optional, on by default)
- WP-Cron is held back and runs when the freeze ends (optional, on by default)
- Logging in always works, so an admin can always unfreeze

A freeze can have an end time, so a deploy script that dies halfway cannot leave the site frozen. It can also be scheduled, extended, or started from the admin bar or WP-CLI. Every blocked attempt is listed, and the last ten freezes are kept with their blocked counts.

User-facing docs are in [`readme.txt`](readme.txt), which is what WordPress.org shows.

## Requirements

WordPress 6.6+, PHP 7.4+.

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
| `npm run env:stop` | Removes the site and its database |

The plugin is mounted read-only into the Docker site, so nothing in WordPress can change or delete the source. The end-to-end run empties the site's content before it starts, so point it only at this throwaway site.

## Tests

- **12 unit tests**: running, expired, scheduled and open-ended freezes, the messages, who can bypass, freezing, extending, unfreezing into history, and expiry
- **7 end-to-end tests**: freezing from the screen (editor, REST and Settings all refused, nothing saved), a logged-out visitor posting a comment, the blocked list with extend and unfreeze, exempt users, the admin bar, the heartbeat lock on an open editor, and scheduled and expired freezes with WP-CLI

## CI

| Workflow | Runs on | What it checks |
| --- | --- | --- |
| PHPCS | push to `main`, pull requests | WordPress coding standards |
| PHPStan | push to `main`, pull requests | Static analysis, level 6 |
| Unit tests | push to `main`, pull requests | PHPUnit on PHP 7.4, 8.0, 8.1, 8.2, 8.3 and 8.4 |
| E2E tests | push to `main`, pull requests | Playwright against the latest WordPress in Docker |
| Plugin Check | push to `main`, pull requests | The official WordPress.org Plugin Check, on the built zip |
| Release | tags `v*` | Builds the zip and publishes a GitHub release |

## Releasing

1. Update the version in three places: the plugin header, the `VERSION` constant and `Stable tag` in `readme.txt`. Add a `= x.y.z =` entry under `== Changelog ==`.
2. Push a tag: `git tag v1.2.0 && git push origin v1.2.0`.

The release workflow fails if the tag does not match all three versions or the changelog has no entry for it. Otherwise it runs PHPCS and PHPUnit, builds the zip with `bin/build-zip.sh` (leaving out everything in `.distignore`), and publishes a GitHub release with the changelog entry as notes.

Deploying to WordPress.org is off until the repository variable `WPORG_DEPLOY` is `true` and the secrets `SVN_USERNAME` and `SVN_PASSWORD` are set.

## Layout

```
deploy-freeze.php       plugin header and PSR-4 autoloader
src/
  Plugin.php            creates the services, guards only load while frozen
  State.php             the saved freeze, its timing and messages
  Freezer.php           starts, extends and ends freezes
  BlockedLog.php        what the freeze stopped
  History.php           the last ten freezes
  Guards/               wp-admin, REST, front-end and cron guards
  Admin/                freeze screen, notice, admin bar
  Editor/EditorLock.php locks the block editor through the heartbeat
  Cli/Command.php       wp deploy-freeze on|off|extend|status|log
assets/                 editor lock and countdown scripts
tests/
  Unit/                 PHPUnit
  e2e/                  Playwright specs and the Docker site
```

## License

GPL-2.0-or-later
