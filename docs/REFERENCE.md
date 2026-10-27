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
| `AXLE_READ_ONLY_MODE` | `true` freezes the site with no end time, before the database is even needed. Remove it to unfreeze |
| `AXLE_READ_ONLY_MODE_REASON` | The reason shown while `AXLE_READ_ONLY_MODE` is on |

## Hooks

| Hook | Type | Use |
| --- | --- | --- |
| `axle_read_only_mode_default_duration` | filter | `( int $minutes )`. Length when none is given: the admin bar quick freeze, `wp axle-read-only-mode on` and the PHP API. Also preselected on the screen. Default 60, `0` for no end |
| `axle_read_only_mode_durations` | filter | `( int[] $minutes )`. Lengths offered on Tools > Axle Read-Only Mode. Default 30, 60, 120, 240, 480 and 0 |
| `axle_read_only_mode_extend_minutes` | filter | `( int[] $minutes )`. The extend buttons. The first one is also the admin bar's "Add 30 minutes". Default 30 and 60 |
| `axle_read_only_mode_is_frozen` | filter | `( bool $frozen, array $state )`. Freeze or unfreeze from outside, such as one switch for every site. Add it from a must-use plugin. `AXLE_READ_ONLY_MODE` always wins |
| `axle_read_only_mode_message` | filter | `( string $message, array $state )`. What logged-in users see |
| `axle_read_only_mode_visitor_message` | filter | `( string $message, array $state )`. What visitors get when they send a form |
| `axle_read_only_mode_user_can_bypass` | filter | `( bool $exempt, int $user_id )`. Let a user keep working during a freeze |
| `axle_read_only_mode_allowed_ajax_actions` | filter | `( string[] $actions )`. Admin-ajax actions that only read and should keep working |
| `axle_read_only_mode_allow_request` | filter | `( bool $allow, string $where, string $source )`. Let one wp-admin or front-end request through, such as a single sign-on callback |
| `axle_read_only_mode_allow_rest_request` | filter | `( bool $allow, WP_REST_Request $request )`. Let one REST request through |
| `axle_read_only_mode_read_only_routes` | filter | `( string[] $routes )`. REST route prefixes that only read even when called with POST. Default `/wp/v2/block-renderer/` |
| `axle_read_only_mode_writing_screens` | filter | `( string[] $screens )`. Admin screens blocked even when only opened. Default `post-new.php` and `upgrade.php` |
| `axle_read_only_mode_logged_visitors` | filter | `( int $limit )`. Anonymous blocked attempts logged per freeze. Default 10, `0` for none |
| `axle_read_only_mode_log_size` | filter | `( int $size )`. Blocked attempts the log keeps. Default 100 |
| `axle_read_only_mode_history_size` | filter | `( int $size )`. Past freezes the history keeps. Default 10 |
| `axle_read_only_mode_capability` | filter | `( string $capability )`. Who may freeze and unfreeze. Default `manage_options` |
| `axle_read_only_mode_started` | action | `( array $state )`. A freeze started or was scheduled |
| `axle_read_only_mode_ended` | action | `( array $state, string $ended_by )`. A freeze ended: `user`, `wp-cli` or `expired` |
| `axle_read_only_mode_extended` | action | `( array $state, int $minutes )`. A freeze got longer |
| `axle_read_only_mode_blocked` | action | `( string $where, string $source, int $user_id )`. Every blocked request, including the ones the log leaves out. For an outside log or an alert channel |

```php
// Let the person running the migration keep working.
add_filter( 'axle_read_only_mode_user_can_bypass', function ( $exempt, $user_id ) {
	return $exempt || user_can( $user_id, 'manage_network' );
}, 10, 2 );

// Deploys here take longer: default to two hours, and offer 15 minutes too.
add_filter( 'axle_read_only_mode_default_duration', fn() => 120 );
add_filter( 'axle_read_only_mode_durations', fn( $minutes ) => array_merge( array( 15 ), $minutes ) );

// Tell the team channel about every blocked save.
add_action( 'axle_read_only_mode_blocked', function ( $where, $source, $user_id ) {
	if ( $user_id ) {
		my_team_alert( sprintf( 'Freeze blocked %s for user %d', $where, $user_id ) );
	}
}, 10, 3 );

// Single sign-on callbacks cannot wait for the freeze to end.
add_filter( 'axle_read_only_mode_allow_request', function ( $allow, $where ) {
	return $allow || 0 === strpos( $where, 'POST /sso/callback' );
}, 10, 2 );
```

Payment providers such as Stripe and PayPal retry webhooks that get a 503, so orders paid during a freeze arrive once it ends. Let a webhook through only if its provider does not retry.

## Abilities

On WordPress 6.9 and later, Axle Read-Only Mode registers [abilities](https://developer.wordpress.org/apis/abilities/): named actions with input and output schemas and a permission check. AI agents find and run them through the [MCP adapter](https://github.com/WordPress/mcp-adapter), and anything else can use the REST API under `/wp-abilities/v1/`. Each one is marked public, which the MCP adapter needs before it lists an ability.

| Ability | Does | Needs |
| --- | --- | --- |
| `axle-read-only-mode/get-status` | Read-only. Whether the site is frozen, until when and why | `edit_posts` |
| `axle-read-only-mode/freeze` | Freezes the site. Input: `minutes`, `reason`, `visitor_message`, `visitors`, `cron` | the freeze capability |
| `axle-read-only-mode/extend` | Adds `minutes` to the running freeze | the freeze capability |
| `axle-read-only-mode/unfreeze` | Ends the freeze now | the freeze capability |
| `axle-read-only-mode/get-blocked` | Read-only. What the freeze blocked, newest first. Input: `limit` | the freeze capability |

During a freeze, **every ability that is not read-only is refused**, from any plugin and from anywhere, WP-CLI included, because agents also call abilities over WP-CLI. Axle Read-Only Mode's own abilities keep working. The refusal shows up in the blocked list as `ability <name>`. This needs WordPress 7.1, which added the `wp_pre_execute_ability` filter. On 6.9 and 7.0 the REST guard still blocks other abilities that run over HTTP.

| Hook | Type | Use |
| --- | --- | --- |
| `axle_read_only_mode_allow_ability` | filter | `( bool $allow, string $ability_name, mixed $input )`. Let one ability that writes run during a freeze |
| `axle_read_only_mode_ability_routes` | filter | `( string[] $routes )`. REST routes that run abilities, checked per ability on 7.1 and later. Default `/wp-abilities/v1/` and `/mcp/` |
| `axle_read_only_mode_dispatch_abilities` | filter | `( string[] $abilities )`. Abilities that only run other abilities, such as the MCP adapter's `mcp-adapter/execute-ability`. They get through a freeze, and the ability they run is checked instead |

## WP-CLI

```sh
wp axle-read-only-mode on [--minutes=<n>] [--reason=<text>] [--visitor-message=<text>] [--no-visitors] [--no-cron]
wp axle-read-only-mode off
wp axle-read-only-mode extend <minutes>
wp axle-read-only-mode status
wp axle-read-only-mode log [--format=table|csv|json|count]
```

`--minutes=0` freezes until `wp axle-read-only-mode off`. The default is 60.

## PHP API

```php
$freezer = AryanX\Axle\ReadOnlyMode\Plugin::instance()->freezer();

$freezer->freeze( array( 'minutes' => 30, 'reason' => 'Moving servers' ) );
$freezer->extend( 15 );
$freezer->unfreeze();
```

## Data

| Option | Contents |
| --- | --- |
| `axle_read_only_mode_state` | The current or scheduled freeze |
| `axle_read_only_mode_log` | Blocked attempts during the current or last freeze (up to 100): time, user ID, the address without its query string, and where it came from |
| `axle_read_only_mode_history` | The last ten freezes, with the ID of the user who started each one |
| `axle_read_only_mode_expired_<start>` | Marks a freeze as expired, so busy sites record its end once |

Every logged-in attempt is logged. Anonymous attempts stop being written after the first 10 per freeze, so bots posting forms cannot turn every blocked request into a database write. Uninstalling removes all of it on every site of a multisite network.

## Privacy

The log and history hold user IDs, and the log holds the addresses people tried to post to. Query strings are never stored, because they can hold email addresses or tokens. Both take part in **Tools > Export Personal Data** and **Tools > Erase Personal Data**: erasing keeps the entry and removes the person from it. **Settings > Privacy** gets suggested text for your privacy policy.

## Multisite

Each site freezes on its own. To freeze a whole network, put `define( 'AXLE_READ_ONLY_MODE', true );` in wp-config.php.
