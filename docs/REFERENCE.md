# Reference

## What a freeze blocks

| Surface | During a freeze |
| --- | --- |
| wp-admin | Any POST, any link carrying a nonce, `post-new.php` and `upgrade.php` get `423`. Screens still open |
| admin-ajax | Blocked unless the action only reads (heartbeat, media queries, link search, screen preferences and similar) |
| REST API | `POST`, `PUT`, `PATCH`, `DELETE` get `423`. `GET` works. Saving editor preferences to `/wp/v2/users/me` is allowed, other profile changes are not |
| Front end | Form posts (comments, checkouts, contact forms) get `503` with `Retry-After`. `wp-login.php` is never blocked |
| XML-RPC | Off |
| WP-Cron | Held back (optional) |
| Automatic updates | Off |
| WP-CLI | Never blocked |

Users marked exempt when the freeze starts, and anyone the filter lets through, are not blocked at all.

## Constants

| Constant | Effect |
| --- | --- |
| `DEPLOY_FREEZE` | `true` freezes the site with no end time, before the database is even needed. Remove it to unfreeze |
| `DEPLOY_FREEZE_REASON` | The reason shown while `DEPLOY_FREEZE` is on |

## Hooks

| Hook | Type | Use |
| --- | --- | --- |
| `deploy_freeze_user_can_bypass` | filter | `( bool $exempt, int $user_id )`. Let a user keep working during a freeze |
| `deploy_freeze_allowed_ajax_actions` | filter | `( string[] $actions )`. Admin-ajax actions that only read and should keep working |

```php
// Let the person running the migration keep working.
add_filter( 'deploy_freeze_user_can_bypass', function ( $exempt, $user_id ) {
	return $exempt || user_can( $user_id, 'manage_network' );
}, 10, 2 );
```

## WP-CLI

```sh
wp deploy-freeze on [--minutes=<n>] [--reason=<text>] [--visitor-message=<text>] [--no-visitors] [--no-cron]
wp deploy-freeze off
wp deploy-freeze extend <minutes>
wp deploy-freeze status
wp deploy-freeze log [--format=table|csv|json|count]
```

`--minutes=0` freezes until `wp deploy-freeze off`. The default is 60.

## PHP API

```php
$freezer = AryanX\DeployFreeze\Plugin::instance()->freezer();

$freezer->freeze( array( 'minutes' => 30, 'reason' => 'Moving servers' ) );
$freezer->extend( 15 );
$freezer->unfreeze();
```

## Data

| Option | Contents |
| --- | --- |
| `deploy_freeze_state` | The current or scheduled freeze |
| `deploy_freeze_log` | Blocked attempts during the current or last freeze (up to 100) |
| `deploy_freeze_history` | The last ten freezes |

All three are removed on uninstall.
