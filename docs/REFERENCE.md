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

Users marked exempt when the freeze starts, users on the [allow-list](#allow-list), and anyone a filter lets through, are not blocked at all. Requests the allow-list lets through are logged as allowed.

## Constants

| Constant | Effect |
| --- | --- |
| `AXLE_READ_ONLY_MODE` | `true` freezes the site with no end time, before the database is even needed. Remove it to unfreeze |
| `AXLE_READ_ONLY_MODE_REASON` | The reason shown while `AXLE_READ_ONLY_MODE` is on |

## Hooks

| Hook | Type | Use |
| --- | --- | --- |
| `axle_read_only_mode_default_duration` | filter | `( int $minutes )`. Length when none is given: the admin bar quick freeze, `wp axle-read-only-mode on` and the PHP API. Also preselected on the screen. Default 60, `0` for no end |
| `axle_read_only_mode_durations` | filter | `( int[] $minutes )`. Lengths offered on Tools > Read-Only Mode. Default 30, 60, 120, 240, 480 and 0 |
| `axle_read_only_mode_extend_minutes` | filter | `( int[] $minutes )`. The extend buttons. The first one is also the admin bar's "Add 30 minutes". Default 30 and 60 |
| `axle_read_only_mode_is_frozen` | filter | `( bool $frozen, array $state )`. Freeze or unfreeze from outside, such as one switch for every site. `$frozen` includes a network freeze. Add it from a must-use plugin. `AXLE_READ_ONLY_MODE` always wins |
| `axle_read_only_mode_message` | filter | `( string $message, array $state )`. What logged-in users see |
| `axle_read_only_mode_visitor_message` | filter | `( string $message, array $state )`. What visitors get when they send a form |
| `axle_read_only_mode_user_can_bypass` | filter | `( bool $exempt, int $user_id )`. Let a user keep working during a freeze |
| `axle_read_only_mode_allowed_ajax_actions` | filter | `( string[] $actions )`. Admin-ajax actions that keep working: the read-only ones plus the ones on the allow-list. Add one, or take one off |
| `axle_read_only_mode_allow_request` | filter | `( bool $allow, string $where, string $source )`. Let one wp-admin or front-end request through, such as a single sign-on callback. `$allow` is already true when the allow-list lets the request through, so code can return `false` to stop it |
| `axle_read_only_mode_allow_rest_request` | filter | `( bool $allow, WP_REST_Request $request )`. Let one REST request through. Like the one above, it runs after the allow-list |
| `axle_read_only_mode_read_only_routes` | filter | `( string[] $routes )`. REST route prefixes that only read even when called with POST. Default `/wp/v2/block-renderer/` |
| `axle_read_only_mode_writing_screens` | filter | `( string[] $screens )`. Admin screens blocked even when only opened. Default `post-new.php` and `upgrade.php` |
| `axle_read_only_mode_logged_visitors` | filter | `( int $limit )`. Anonymous blocked attempts logged per freeze. Default 10, `0` for none. Requests let through have their own limit |
| `axle_read_only_mode_log_size` | filter | `( int $size )`. Entries the log keeps, blocked and allowed. Default 100 |
| `axle_read_only_mode_history_size` | filter | `( int $size )`. Past freezes the history keeps. Default 10 |
| `axle_read_only_mode_capability` | filter | `( string $capability )`. Who may freeze and unfreeze. Default `manage_options` |
| `axle_read_only_mode_logged_allowed` | filter | `( int $limit )`. Requests let through that the log keeps per freeze. Default 25, `0` for none |
| `axle_read_only_mode_allow_limit` | filter | `( int $limit )`. Entries each part of the allow-list holds. Default 50 |
| `axle_read_only_mode_validate_allow_entry` | filter | `( string $error, string $type, string\|int $value )`. Runs after the built-in checks of an allow-list entry. Return a message to refuse it. `$type` is `rest`, `paths`, `ajax` or `users` |
| `axle_read_only_mode_allow_suggestions` | filter | `( array $suggestions )`. The "Add" buttons on the Settings tab. Each: `plugin`, `type`, `value`, `why`. They are only suggestions: nothing is added without a click |
| `axle_read_only_mode_notify_events` | filter | `( string[] $events, bool $network )`. The events that send an email: `started`, `ended`, `expired`, `extended`, `scheduled_start` |
| `axle_read_only_mode_notify_recipients` | filter | `( string[] $recipients, string $event, array $freeze, bool $network )`. Who gets the email. Default the addresses in the settings, or the admin email |
| `axle_read_only_mode_notify_subject` | filter | `( string $subject, string $event, array $freeze, bool $network )`. The email subject |
| `axle_read_only_mode_notify_body` | filter | `( string $body, string $event, array $freeze, bool $network, array $context )`. The plain-text body. `$context` has `minutes` (extended), `ended_by` and `blocked` (ended) |
| `axle_read_only_mode_notify_interval` | filter | `( int $seconds, string $event )`. Seconds before the same event can send another email. Default 60, never less |
| `axle_read_only_mode_health_overdue_minutes` | filter | `( int $minutes )`. Minutes a freeze can run past its end time before Site Health calls it critical. Default 30 |
| `axle_read_only_mode_health_open_ended_hours` | filter | `( int $hours )`. Hours a freeze with no end time can last before Site Health calls it critical. Default 6 |
| `axle_read_only_mode_health_result` | filter | `( array $result, string $code )`. The Site Health result. `$code` is `off`, `frozen`, `overdue`, `open_ended` or `constant` |
| `axle_read_only_mode_health_info` | filter | `( array $fields )`. The fields of the Site Health > Info section |
| `axle_read_only_mode_network_capability` | filter | `( string $capability )`. Who may freeze and unfreeze the network. Default `manage_network_options` |
| `axle_read_only_mode_network_log_sites` | filter | `( int $limit )`. Sites the network log reads, most recently active first. Default 100 |
| `axle_read_only_mode_network_site_picker_limit` | filter | `( int $limit )`. Sites the "chosen sites" list shows. Default 200 |
| `axle_read_only_mode_started` | action | `( array $state )`. A freeze started or was scheduled |
| `axle_read_only_mode_ended` | action | `( array $state, string $ended_by )`. A freeze ended: `user`, `cli`, `ability` or `expired` |
| `axle_read_only_mode_extended` | action | `( array $state, int $minutes )`. A freeze got longer |
| `axle_read_only_mode_blocked` | action | `( string $where, string $source, int $user_id )`. Every blocked request, including the ones the log leaves out. For an outside log or an alert channel |
| `axle_read_only_mode_scheduled_started` | action | `( array $state )`. A freeze that was set for later has started. Fires on the first request after its start time |
| `axle_read_only_mode_allowed` | action | `( string $where, string $source, int $user_id, string $via )`. Every request the allow-list (`settings`) or a filter (`code`) let through a freeze, including the ones the log leaves out |
| `axle_read_only_mode_allow_list_saved` | action | `( array $allow, bool $network )`. The allow-list was saved, from the screen, WP-CLI or a suggestion button |
| `axle_read_only_mode_notify_settings_saved` | action | `( array $notify, bool $network )`. The email settings were saved |
| `axle_read_only_mode_notified` | action | `( string $event, string[] $recipients, string $subject, bool $sent, array $freeze, bool $network )`. An email was handed to `wp_mail()` |
| `axle_read_only_mode_network_started` | action | `( array $state )`. A network freeze started or was scheduled |
| `axle_read_only_mode_network_scheduled_started` | action | `( array $state )`. A network freeze that was set for later has started |
| `axle_read_only_mode_network_extended` | action | `( array $state, int $minutes )`. A network freeze got longer |
| `axle_read_only_mode_network_ended` | action | `( array $state, string $ended_by )`. A network freeze ended |

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

## Allow-list

Some things need to keep working during a freeze: a payment webhook, one form, a REST route, an AJAX action, a person. **Tools > Read-Only Mode > Settings > Keep these working while frozen** lists them, so nobody needs a code filter.

| Entry | Example | What it lets through |
| --- | --- | --- |
| REST routes | `/wc/v3/webhooks` | `POST`, `PUT`, `PATCH` and `DELETE` to routes that start with it. A leading `/wp-json` and a query string are dropped. `/wp` and `/wp/v2` are refused: they would let every core write through |
| Front-end paths or query arguments | `/checkout/order-pay`, `?wc-api=`, `?callback=gravityformsstripe`, `/pay?token=abc` | Form posts to addresses that start with the path, or that carry the query argument (any value, or the one given). `/` alone and full addresses are refused |
| Ajax actions | `my_plugin_save_cart` | `admin-ajax.php` requests with that action, lower case |
| Users | `editor-in-chief`, `ops@example.com`, `12` | Everything, like the "Let me keep editing" tick. One login, email address or ID per line |

Each entry is checked when saved. Lines that fail are left out and listed with the reason, and the rest are saved. Under each field, every saved entry says what it matches: how many registered REST routes start with it, whether a plugin handles the AJAX action, who the user is.

The settings run first and the existing filters run after them, so code can still say yes or no: `axle_read_only_mode_allow_request`, `axle_read_only_mode_allow_rest_request`, `axle_read_only_mode_allowed_ajax_actions` and `axle_read_only_mode_user_can_bypass` receive the answer of the settings and can change it.

**Suggestions.** When WooCommerce, Stripe for WooCommerce or Gravity Forms is active, the Settings tab shows common entries with an **Add** button. Nothing is ever added without a click. Payment providers retry webhooks that get a "back soon" answer, so add a webhook only if its provider does not retry. The `axle_read_only_mode_allow_suggestions` filter changes the list.

**The log.** A request the allow-list or a filter lets through is logged with the result `allowed` (and `via`: `settings` or `code`), next to the blocked ones. Only the first 25 per freeze are written, so a busy webhook does not turn into a database write per request. The `axle_read_only_mode_allowed` action still fires for every one. The count of blocked attempts never includes them.

**On a network.** A network freeze uses the network's allow-list (Network Admin > Settings > Read-Only Mode > Settings) and ignores the site lists, so a site admin cannot open a hole in it. A site freeze uses the site's list.

## Email

**Tools > Read-Only Mode > Settings > Email when a freeze starts or ends.** Off by default. Choose the recipients (the admin email when empty) and the events:

| Event | Sent when |
| --- | --- |
| `started` | A freeze starts right away |
| `ended` | Someone ends a freeze, from the screen, the admin bar, WP-CLI or an ability |
| `expired` | A freeze reaches its end time and ends by itself |
| `extended` | A freeze is made longer |
| `scheduled_start` | A freeze that was set for later starts |

The email is plain text, sent with `wp_mail()`. It says who, why and until when. For `ended` and `expired` it says how many attempts were blocked and links to the list. The same event sends at most one email per minute, so a script that freezes and unfreezes in a loop cannot flood an inbox. A freeze ends and a scheduled one starts on the first request after the time, so a quiet site can be a little late.

A network freeze emails the network admin (or the recipients on the network's Settings tab) and says which sites it covers.

## Site Health

Tools > Site Health has a **Read-only mode** test:

| Status | When |
| --- | --- |
| Good | The site is not frozen. A scheduled freeze is mentioned |
| Recommended | A freeze with an end time is running, or a freeze with no end time is less than 6 hours old |
| Critical | A freeze ran more than 30 minutes past its end time, a freeze with no end time has lasted more than 6 hours, or `AXLE_READ_ONLY_MODE` in wp-config.php forces the freeze |

A freeze ends on the first request after its end time, so one that runs well past it means something is holding it on, such as a filter. On multisite the network freeze counts the same way for each site it covers. Both limits have filters, and `wp axle-read-only-mode health` runs the check from the command line. The Info tab has an Axle Read-Only Mode section with the state, end time, allow-list counts and email settings.

## Abilities

On WordPress 6.9 and later, Axle Read-Only Mode registers [abilities](https://developer.wordpress.org/apis/abilities/): named actions with input and output schemas and a permission check. AI agents find and run them through the [MCP adapter](https://github.com/WordPress/mcp-adapter), and anything else can use the REST API under `/wp-abilities/v1/`. Each one is marked public, which the MCP adapter needs before it lists an ability.

| Ability | Does | Needs |
| --- | --- | --- |
| `axle-read-only-mode/get-status` | Read-only. Whether the site is frozen, until when and why, and what holds it (`source`: `site`, `network` or `constant`) | `edit_posts` |
| `axle-read-only-mode/freeze` | Freezes the site. Input: `minutes`, `reason`, `visitor_message`, `visitors`, `cron` | the freeze capability |
| `axle-read-only-mode/extend` | Adds `minutes` to the running freeze | the freeze capability |
| `axle-read-only-mode/unfreeze` | Ends the freeze now | the freeze capability |
| `axle-read-only-mode/get-blocked` | Read-only. What the freeze blocked, and the requests the allow-list let through (`result`), newest first. Input: `limit` | the freeze capability |
| `axle-read-only-mode/get-allow-list` | Read-only. The allow-list: REST routes, paths, ajax actions and user IDs | the freeze capability |

During a freeze, **every ability that is not read-only is refused**, from any plugin and from anywhere, WP-CLI included, because agents also call abilities over WP-CLI. Axle Read-Only Mode's own abilities keep working. The refusal shows up in the blocked list as `ability <name>`. This needs WordPress 7.1, which added the `wp_pre_execute_ability` filter. On 6.9 and 7.0 the REST guard still blocks other abilities that run over HTTP.

| Hook | Type | Use |
| --- | --- | --- |
| `axle_read_only_mode_allow_ability` | filter | `( bool $allow, string $ability_name, mixed $input )`. Let one ability that writes run during a freeze |
| `axle_read_only_mode_ability_routes` | filter | `( string[] $routes )`. REST routes that run abilities, checked per ability on 7.1 and later. Default `/wp-abilities/v1/` and `/mcp/` |
| `axle_read_only_mode_dispatch_abilities` | filter | `( string[] $abilities )`. Abilities that only run other abilities, such as the MCP adapter's `mcp-adapter/execute-ability`. They get through a freeze, and the ability they run is checked instead |

There is no ability that changes the allow-list. It loosens the freeze, so only people change it.

## WP-CLI

```sh
wp axle-read-only-mode on [--minutes=<n>] [--reason=<text>] [--visitor-message=<text>] [--no-visitors] [--no-cron] [--network] [--sites=<ids>]
wp axle-read-only-mode off [--network]
wp axle-read-only-mode extend <minutes> [--network]
wp axle-read-only-mode status [--network]
wp axle-read-only-mode log [--network] [--format=table|csv|json|count]
wp axle-read-only-mode allow <rest|path|ajax|user> <value> [--network]
wp axle-read-only-mode disallow <rest|path|ajax|user> <value> [--network]
wp axle-read-only-mode allowed [--network] [--format=table|csv|json|count]
wp axle-read-only-mode notify [--enable|--disable] [--to=<emails>] [--events=<list>] [--network]
wp axle-read-only-mode health [--format=table|json]
```

`--minutes=0` freezes until `wp axle-read-only-mode off`. The default is 60.

`health` prints the Site Health result and exits with status 2 when it is critical, so a monitor can alert on it.

On multisite, a command acts on the site WP-CLI loaded: the main site, or the one given with the global `--url`. Add `--network` to freeze, end, extend, list or configure the whole network instead, and `--sites=2,5` with `on --network` to freeze only those sites.

```sh
wp axle-read-only-mode on --url=shop.example.com --minutes=30      # one site
wp axle-read-only-mode on --network --minutes=30                   # every site
wp axle-read-only-mode on --network --sites=2,5 --minutes=30       # chosen sites
wp axle-read-only-mode off --network
```

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
| `axle_read_only_mode_log` | Blocked attempts, and the first few requests the allow-list let through, during the current or last freeze (up to 100): time, user ID, the address without its query string, where it came from, and whether it was allowed |
| `axle_read_only_mode_history` | The last ten freezes, with the ID of the user who started each one |
| `axle_read_only_mode_settings` | The allow-list and the email settings |
| `axle_read_only_mode_expired_<start>`, `axle_read_only_mode_started_<start>` | Mark a freeze as expired, or a scheduled one as started, so busy sites record each once |
| `axle_read_only_mode_mail_<event>` (transient) | One minute, so an event sends at most one email a minute |

On a multisite network the network keeps its own, as network (site) options: `axle_read_only_mode_network_state`, `axle_read_only_mode_network_history`, `axle_read_only_mode_network_settings`, and the same markers with `network_` in the name.

Every logged-in attempt is logged. Anonymous attempts stop being written after the first 10 per freeze, so bots posting forms cannot turn every blocked request into a database write. Uninstalling removes all of it on every site of a multisite network, and the network options.

## Privacy

The log and history hold user IDs, and the log holds the addresses people tried to post to or that the allow-list let through. If you turn on emails, the recipients are kept in the settings. Query strings are never stored, because they can hold email addresses or tokens. Both take part in **Tools > Export Personal Data** and **Tools > Erase Personal Data**: erasing keeps the entry and removes the person from it. **Settings > Privacy** gets suggested text for your privacy policy.

## Multisite

A network admin can freeze every site, or chosen sites, from **Network Admin > Settings > Read-Only Mode**. Each site admin can still freeze their own site from Tools.

- **A network freeze applies on every site it covers and overrides the site.** A site that unfreezes itself is still frozen by the network. It shows "frozen by the network" in its notice, admin bar and messages, and offers no way to lift it.
- **A network freeze and a site freeze are separate.** Each has its own start, end, reason and history. The site is frozen while either one is running.
- **The network keeps its own allow-list, email settings and history** as network options. A network freeze uses the network's allow-list and ignores the site lists.
- **Logs stay per site.** Each site logs what it blocked, and the network screen reads them from the start of the network freeze: a table per site and one list across the network. It reads the 100 most recently active sites (`axle_read_only_mode_network_log_sites`).
- **Activation.** Network-activate the plugin so a network freeze reaches every site, and so the Network Admin screen exists. If it is only active on some sites, a network freeze applies on those and not on the others, and the network screen says so.
- **WP-CLI.** See above: `--network`, `--sites` and the global `--url`.
- **Constants.** `AXLE_READ_ONLY_MODE` in wp-config.php freezes the whole network, with no end time.
- **Capability.** `manage_network_options`, changed with `axle_read_only_mode_network_capability`.
