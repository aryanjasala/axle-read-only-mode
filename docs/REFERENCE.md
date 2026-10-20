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
| `deploy_freeze_default_duration` | filter | `( int $minutes )`. Length when none is given: the admin bar quick freeze, `wp deploy-freeze on` and the PHP API. Also preselected on the screen. Default 60, `0` for no end |
| `deploy_freeze_durations` | filter | `( int[] $minutes )`. Lengths offered on Tools > Deploy Freeze. Default 30, 60, 120, 240, 480 and 0 |
| `deploy_freeze_extend_minutes` | filter | `( int[] $minutes )`. The extend buttons. The first one is also the admin bar's "Add 30 minutes". Default 30 and 60 |
| `deploy_freeze_is_frozen` | filter | `( bool $frozen, array $state )`. Freeze or unfreeze from outside, such as one switch for every site. Add it from a must-use plugin. `DEPLOY_FREEZE` always wins |
| `deploy_freeze_message` | filter | `( string $message, array $state )`. What logged-in users see |
| `deploy_freeze_visitor_message` | filter | `( string $message, array $state )`. What visitors get when they send a form |
| `deploy_freeze_user_can_bypass` | filter | `( bool $exempt, int $user_id )`. Let a user keep working during a freeze |
| `deploy_freeze_allowed_ajax_actions` | filter | `( string[] $actions )`. Admin-ajax actions that only read and should keep working |
| `deploy_freeze_allow_request` | filter | `( bool $allow, string $where, string $source )`. Let one wp-admin or front-end request through, such as a single sign-on callback |
| `deploy_freeze_allow_rest_request` | filter | `( bool $allow, WP_REST_Request $request )`. Let one REST request through |
| `deploy_freeze_read_only_routes` | filter | `( string[] $routes )`. REST route prefixes that only read even when called with POST. Default `/wp/v2/block-renderer/` |
| `deploy_freeze_writing_screens` | filter | `( string[] $screens )`. Admin screens blocked even when only opened. Default `post-new.php` and `upgrade.php` |
| `deploy_freeze_logged_visitors` | filter | `( int $limit )`. Anonymous blocked attempts logged per freeze. Default 10, `0` for none |
| `deploy_freeze_log_size` | filter | `( int $size )`. Blocked attempts the log keeps. Default 100 |
| `deploy_freeze_history_size` | filter | `( int $size )`. Past freezes the history keeps. Default 10 |
| `deploy_freeze_capability` | filter | `( string $capability )`. Who may freeze and unfreeze. Default `manage_options` |
| `deploy_freeze_started` | action | `( array $state )`. A freeze started or was scheduled |
| `deploy_freeze_ended` | action | `( array $state, string $ended_by )`. A freeze ended: `user`, `wp-cli` or `expired` |
| `deploy_freeze_extended` | action | `( array $state, int $minutes )`. A freeze got longer |
| `deploy_freeze_blocked` | action | `( string $where, string $source, int $user_id )`. Every blocked request, including the ones the log leaves out. For an outside log or an alert channel |

```php
// Let the person running the migration keep working.
add_filter( 'deploy_freeze_user_can_bypass', function ( $exempt, $user_id ) {
	return $exempt || user_can( $user_id, 'manage_network' );
}, 10, 2 );

// Deploys here take longer: default to two hours, and offer 15 minutes too.
add_filter( 'deploy_freeze_default_duration', fn() => 120 );
add_filter( 'deploy_freeze_durations', fn( $minutes ) => array_merge( array( 15 ), $minutes ) );

// Tell the team channel about every blocked save.
add_action( 'deploy_freeze_blocked', function ( $where, $source, $user_id ) {
	if ( $user_id ) {
		my_team_alert( sprintf( 'Freeze blocked %s for user %d', $where, $user_id ) );
	}
}, 10, 3 );

// Single sign-on callbacks cannot wait for the freeze to end.
add_filter( 'deploy_freeze_allow_request', function ( $allow, $where ) {
	return $allow || 0 === strpos( $where, 'POST /sso/callback' );
}, 10, 2 );
```

Payment providers such as Stripe and PayPal retry webhooks that get a 503, so orders paid during a freeze arrive once it ends. Let a webhook through only if its provider does not retry.

## Abilities

On WordPress 6.9 and later, Deploy Freeze registers [abilities](https://developer.wordpress.org/apis/abilities/): named actions with input and output schemas and a permission check. AI agents find and run them through the [MCP adapter](https://github.com/WordPress/mcp-adapter), and anything else can use the REST API under `/wp-abilities/v1/`.

| Ability | Does | Needs |
| --- | --- | --- |
| `deploy-freeze/get-status` | Read-only. Whether the site is frozen, until when and why | `edit_posts` |
| `deploy-freeze/freeze` | Freezes the site. Input: `minutes`, `reason`, `visitor_message`, `visitors`, `cron` | the freeze capability |
| `deploy-freeze/extend` | Adds `minutes` to the running freeze | the freeze capability |
| `deploy-freeze/unfreeze` | Ends the freeze now | the freeze capability |
| `deploy-freeze/get-blocked` | Read-only. What the freeze blocked, newest first. Input: `limit` | the freeze capability |

During a freeze, **every ability that is not read-only is refused**, from any plugin and from anywhere, WP-CLI included, because agents also call abilities over WP-CLI. Deploy Freeze's own abilities keep working. The refusal shows up in the blocked list as `ability <name>`. This needs WordPress 7.1, which added the `wp_pre_execute_ability` filter. On 6.9 and 7.0 the REST guard still blocks other abilities that run over HTTP.

| Hook | Type | Use |
| --- | --- | --- |
| `deploy_freeze_allow_ability` | filter | `( bool $allow, string $ability_name, mixed $input )`. Let one ability that writes run during a freeze |
| `deploy_freeze_ability_routes` | filter | `( string[] $routes )`. REST routes that run abilities, checked per ability on 7.1 and later. Default `/wp-abilities/v1/` and `/mcp/` |

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
| `deploy_freeze_log` | Blocked attempts during the current or last freeze (up to 100): time, user ID, the address without its query string, and where it came from |
| `deploy_freeze_history` | The last ten freezes, with the ID of the user who started each one |
| `deploy_freeze_expired_<start>` | Marks a freeze as expired, so busy sites record its end once |

Every logged-in attempt is logged. Anonymous attempts stop being written after the first 10 per freeze, so bots posting forms cannot turn every blocked request into a database write. Uninstalling removes all of it on every site of a multisite network.

## Privacy

The log and history hold user IDs, and the log holds the addresses people tried to post to. Query strings are never stored, because they can hold email addresses or tokens. Both take part in **Tools > Export Personal Data** and **Tools > Erase Personal Data**: erasing keeps the entry and removes the person from it. **Settings > Privacy** gets suggested text for your privacy policy.

## Multisite

Each site freezes on its own. To freeze a whole network, put `define( 'DEPLOY_FREEZE', true );` in wp-config.php.
