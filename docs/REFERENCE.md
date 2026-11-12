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

The table is for the default scope, Everything. A [scope](#scopes) can freeze only part of it.

## Scopes

A freeze covers one scope. Every request, ability and scheduled task belongs to one of two areas, and a freeze stops it only when its scope covers that area.

| Scope | `--scope=` | Refused | Keeps working |
| --- | --- | --- | --- |
| Everything (default) | `all` | Content, settings, plugins, themes and updates | Reading, logging in |
| Content only | `content` | Posts, pages, media, comments, terms, menus, orders, form entries and anything not recognised as settings or code | Options, plugins, themes, widgets, templates, user accounts, updates |
| Settings and code only | `code` | Options, plugins, themes, widgets, templates, fonts, updates, user accounts and roles | Editing content, comments, forms, checkouts |

A freeze stored before scopes existed is Everything. Scopes are one of the three above, or one added with the `axle_read_only_mode_scopes` filter.

**How a request is sorted.** Settings and code is what the built-in rules recognise: the admin screens for options, plugins, themes, updates, widgets, users and the customizer, anything posted from a Settings API form, a plugin settings page whose name says settings, options, config or license, REST routes such as `/wp/v2/settings`, `/wp/v2/plugins`, `/wp/v2/themes`, `/wp/v2/templates`, `/wp/v2/global-styles` and `/wp/v2/users`, the ajax actions that install or update code, and abilities whose name says option, setting, plugin, theme, update, user, role, widget or template. **Everything else is content**, so a content freeze errs on the side of stopping. If a plugin screen is sorted wrongly, fix it with `axle_read_only_mode_request_area` or one of the lists below.

**What each part of a freeze does per scope**

| Part | Everything | Content only | Settings and code only |
| --- | --- | --- | --- |
| wp-admin, REST, admin-ajax, abilities | As in the table above | Content writes refused, code writes allowed | Code writes refused, content writes allowed |
| Block editor | Locked | Locked | Not locked |
| Visitor submissions (comments, forms, checkouts, sign-ups) | Paused, if "visitor submissions" is on | Paused, if it is on | Open: they are content |
| XML-RPC | Off | Off | On, without `wp.setOptions`, `wp.newUser`, `wp.editProfile` and `wp.deleteUser` |
| Scheduled tasks (if "scheduled tasks" is on) | All held | All held, except update checks and site checks | Only update checks and site checks held |
| Automatic updates | Off | On | Off |
| The allow-list | Lets through what would be refused | Same, and only for content | Same, and only for settings and code |

A request the scope does not cover is left alone: it is not blocked, not logged and not counted as allowed. The scope shows in the status badge ("Content frozen", "Settings and code frozen"), the notice on every screen, the admin bar, the blocked list (an "Affects" column, and `area` in `wp axle-read-only-mode log` and the `get-blocked` ability), the history, the start and end emails, `wp axle-read-only-mode status`, and the abilities.

## Before you freeze

The freeze screen has a **Before you freeze** card. It shows what a freeze with the chosen length, start and scope would interrupt right now, and updates when you change them. It informs and never blocks. Each item has a tone and a word.

| Item | Looks at | Words |
| --- | --- | --- |
| Editing | Posts opened in the editor in the last 150 seconds (post locks): who, which post, how long ago. Not you | Editing now (warning), Clear (good), Not affected (neutral, when the scope leaves content alone) |
| Scheduled posts | Posts due inside the freeze window. With scheduled tasks held they publish when the freeze ends | Will be late (warning), Will publish (info), Clear (good) |
| Orders | WooCommerce orders waiting for payment, created in the last two hours. Only when WooCommerce is active | Mid-checkout (warning), Not affected (neutral), Clear (good) |
| Scheduled tasks | Tasks due inside the window that the scope would hold, grouped by name | Delayed (info), Clear (good) |

A freeze with no end time looks one day ahead. If anyone else is editing, the freeze button asks first and says how many people. The admin bar "Freeze site for 1 hour" stays one click, with no question: afterwards the notice on the freeze screen says how many people were editing, because it can be undone with one click and an extra step on every quick freeze would be the more annoying choice.

`wp axle-read-only-mode check` prints the same items, and the `axle-read-only-mode/check-freeze` ability returns them. Other plugins add items with the `axle_read_only_mode_checks` filter:

```php
add_filter( 'axle_read_only_mode_checks', function ( $checks ) {
	$checks['my_backup'] = function ( array $context ) {
		if ( ! my_backup_is_running() ) {
			return null;
		}

		return array(
			'tone'   => 'warning',
			'word'   => 'Backup running',
			'title'  => 'A backup is running',
			'detail' => 'Freezing now stops it from writing its log.',
		);
	};

	return $checks;
} );
```

## Constants

| Constant | Effect |
| --- | --- |
| `AXLE_READ_ONLY_MODE` | `true` freezes the site with no end time, before the database is even needed. Remove it to unfreeze |
| `AXLE_READ_ONLY_MODE_REASON` | The reason shown while `AXLE_READ_ONLY_MODE` is on |
| `AXLE_READ_ONLY_MODE_SCOPE` | `all`, `content` or `code`: the scope of a freeze that `AXLE_READ_ONLY_MODE` forces. Default `all` |

## Hooks

| Hook | Type | Use |
| --- | --- | --- |
| `axle_read_only_mode_default_duration` | filter | `( int $minutes )`. Length when none is given: the admin bar quick freeze, `wp axle-read-only-mode on` and the PHP API. Also preselected on the screen. Default 60, `0` for no end |
| `axle_read_only_mode_durations` | filter | `( int[] $minutes )`. Lengths offered on Tools > Read-Only Mode. Default 30, 60, 120, 240, 480 and 0 |
| `axle_read_only_mode_heads_up_minutes` | filter | `( int $minutes, array $plan )`. Minutes before a planned freeze that the countdown starts. Default 15, or the lead set on the plan. Clamped to 1 to 1440. `$plan` is empty for a freeze scheduled without a plan |
| `axle_read_only_mode_heads_up` | filter | `( ?array $upcoming )`. The countdown people see: `start`, `seconds`, `area`, `reason`, `message`, `now`. Return `null` to show none |
| `axle_read_only_mode_heads_up_message` | filter | `( string $message, string $area, int $seconds )`. The first words of the countdown, and what the Heartbeat sends |
| `axle_read_only_mode_heads_up_recipients` | filter | `( string[] $emails, array $plan )`. Who gets the heads-up email: the people with a post open when it starts |
| `axle_read_only_mode_heads_up_subject`, `axle_read_only_mode_heads_up_body` | filter | `( string $text, array $plan )`. The heads-up email |
| `axle_read_only_mode_plans_limit` | filter | `( int $limit )`. How many planned freezes the list holds. Default 50 |
| `axle_read_only_mode_health_plan_grace_minutes` | filter | `( int $minutes )`. How late a planned freeze can be before Site Health says it did not begin. Default 10 |
| `axle_read_only_mode_editor_strings` | filter | `( array $strings )`. The words the block and classic editors show during a freeze and when it ends |
| `axle_read_only_mode_planned` | action | `( array $plan, bool $network )`. A freeze was planned |
| `axle_read_only_mode_plan_updated` | action | `( array $plan, array $before, bool $network )`. A planned freeze was changed |
| `axle_read_only_mode_plan_cancelled` | action | `( array $plan, bool $network )`. A planned freeze was cancelled |
| `axle_read_only_mode_plan_started` | action | `( array $plan, bool $network )`. A planned freeze started. `axle_read_only_mode_started` fires too |
| `axle_read_only_mode_plan_skipped` | action | `( array $plan, string $reason, bool $network )`. A plan was passed over: `missed` (its whole window went by) or `busy` (a freeze was already running) |
| `axle_read_only_mode_heads_up_emailed` | action | `( string[] $emails, array $plan )`. The heads-up email was sent |
| `axle_read_only_mode_scopes` | filter | `( array $scopes )`. The scopes a freeze can have. Each: `label`, `status`, `description`, `covers` (the areas it freezes, `content` and `code`). Keep `all` first |
| `axle_read_only_mode_default_scope` | filter | `( string $scope )`. Scope when none is chosen: the admin bar quick freeze, WP-CLI, abilities, the PHP API. Also preselected on the screen. Default `all` |
| `axle_read_only_mode_scope` | filter | `( string $scope, array $state )`. The scope of the freeze in force |
| `axle_read_only_mode_request_area` | filter | `( string $area, string $kind, string $subject, array $context )`. Sort one request into `content` or `code`. `$kind` is `admin`, `rest`, `ajax`, `ability`, `cron` or `front-end` |
| `axle_read_only_mode_code_screens` | filter | `( string[] $screens )`. Admin files that change settings, plugins, themes, updates or accounts |
| `axle_read_only_mode_code_routes` | filter | `( string[] $routes )`. REST route prefixes that change settings, plugins, themes, templates or accounts |
| `axle_read_only_mode_code_ajax_actions` | filter | `( string[] $actions )`. Ajax actions that install, change or update code and settings |
| `axle_read_only_mode_code_ability_words` | filter | `( string[] $words )`. Words in an ability name that mark it as settings or code |
| `axle_read_only_mode_code_cron_hooks` | filter | `( string[] $hooks )`. Scheduled events that check for updates or run site checks. Every other event counts as content |
| `axle_read_only_mode_code_xmlrpc_methods` | filter | `( string[] $methods )`. XML-RPC methods a settings and code freeze removes |
| `axle_read_only_mode_checks` | filter | `( array $checks, array $context )`. The checks on the Before you freeze card: ID to callable. Add yours, or unset a built-in one: `editing`, `scheduled_posts`, `orders`, `cron`. A callable gets the context (`start`, `end`, `until`, `minutes`, `scope`, `cron`, `visitors`, `now`, `user_id`) and returns an item (`tone`, `word`, `title`, `detail`, `rows`, `count`) or `null` |
| `axle_read_only_mode_check_items` | filter | `( array $items, array $context )`. The items after every check ran |
| `axle_read_only_mode_check_editors` | filter | `( array $editors )`. The people the card says are editing. Each: `post_id`, `title`, `user_id`, `name`, `time` |
| `axle_read_only_mode_check_open_window` | filter | `( int $seconds )`. How far ahead the checks look when a freeze has no end time. Default one day |
| `axle_read_only_mode_check_order_age` | filter | `( int $seconds )`. How recent an unpaid WooCommerce order must be to count as waiting for payment. Default two hours |
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
| `axle_read_only_mode_started` | action | `( array $state )`. A freeze started or was scheduled. `$state['area']` is its scope: `all`, `content` or `code` |
| `axle_read_only_mode_ended` | action | `( array $state, string $ended_by )`. A freeze ended: `user`, `cli`, `ability` or `expired` |
| `axle_read_only_mode_extended` | action | `( array $state, int $minutes )`. A freeze got longer |
| `axle_read_only_mode_blocked` | action | `( string $where, string $source, int $user_id, string $area )`. Every blocked request, including the ones the log leaves out. `$area` is `content` or `code`. For an outside log or an alert channel |
| `axle_read_only_mode_checked` | action | `( array $result )`. The Before you freeze checks ran, from the screen, WP-CLI or an ability. `$result` has `items`, `editing`, `attention` and `context` |
| `axle_read_only_mode_scheduled_started` | action | `( array $state )`. A freeze that was set for later has started. Fires on the first request after its start time |
| `axle_read_only_mode_allowed` | action | `( string $where, string $source, int $user_id, string $via, string $area )`. Every request the allow-list (`settings`) or a filter (`code`) let through a freeze, including the ones the log leaves out |
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

## Planned freezes

Plan a freeze on **Tools > Read-Only Mode > Planned**, with `wp axle-read-only-mode plan`, or with the `plan-freeze` ability. A plan has a start, a length, a scope, a reason and a heads-up. It runs once, or every week on the days you tick, at the time of day of its start.

- **It starts by itself.** WP-Cron is not used. Every request reads the (autoloaded) list of plans and starts the first one whose time has passed, so a plan starts on the first request after its time. The end works the same way. A plan whose whole window went by before any request, or that comes due while another freeze is running, is skipped and fires `axle_read_only_mode_plan_skipped`.
- **The check runs again at the start.** The "Before you freeze" check runs when the plan starts, and what it finds (people with a post open, posts due, orders waiting) goes into the start email, if emails are on for starts.
- **The heads-up.** From 15 minutes before (the lead on the plan, or `axle_read_only_mode_heads_up_minutes`), everyone who can write content sees "The site goes read-only in 12 minutes. Save your work." on every admin screen, and in the block editor as an editor notice. A plan for settings and code tells people who can change settings. The countdown updates through the Heartbeat, so a cancelled or moved plan changes it within a minute, and screen readers hear it once through `wp.a11y.speak`. A freeze scheduled from the freeze form gets the same countdown.
- **The email.** Tick "Also email people who have a post open" and each of them gets one email when the heads-up starts.
- **Multisite.** Network Admin has its own Planned tab. A network plan can cover every site or chosen sites (set the sites with WP-CLI `--sites`).
- **Cancel.** A cancelled plan does not start, and a plan that repeats stops repeating. A freeze it already started keeps running.

## While you write

If a freeze that covers content starts while you have the block editor open, saving is locked and one notice says when it ends. Your changes stay in the editor and a copy waits in the browser (local storage, key `axle-read-only-mode:post:<id>`). When the freeze ends:

- If the post has not changed on the server, your changes are saved for you, and a notice says so. This also works after a reload during the freeze.
- If someone changed it meanwhile, both versions are offered: **Save my version** or **Use the saved version**. Nothing is overwritten without a choice.
- In the classic editor, Update does nothing while the freeze runs, the form keeps what you typed, and a notice says so. Quick edit and other screens show the freeze message and a Back link.

## Site Health

Tools > Site Health has a **Read-only mode** test:

| Status | When |
| --- | --- |
| Good | The site is not frozen. A scheduled freeze is mentioned |
| Recommended | A freeze with an end time is running, or a freeze with no end time is less than 6 hours old |
| Critical | A freeze ran more than 30 minutes past its end time, a freeze with no end time has lasted more than 6 hours, or `AXLE_READ_ONLY_MODE` in wp-config.php forces the freeze |

A second test, **Planned freezes**, is recommended when a plan is more than 10 minutes past its start and has not begun (`axle_read_only_mode_health_plan_grace_minutes`).

A freeze ends on the first request after its end time, so one that runs well past it means something is holding it on, such as a filter. On multisite the network freeze counts the same way for each site it covers. Both limits have filters, and `wp axle-read-only-mode health` runs the check from the command line. The Info tab has an Axle Read-Only Mode section with the state, end time, allow-list counts and email settings.

## Abilities

On WordPress 6.9 and later, Axle Read-Only Mode registers [abilities](https://developer.wordpress.org/apis/abilities/): named actions with input and output schemas and a permission check. AI agents find and run them through the [MCP adapter](https://github.com/WordPress/mcp-adapter), and anything else can use the REST API under `/wp-abilities/v1/`. Each one is marked public, which the MCP adapter needs before it lists an ability.

| Ability | Does | Needs |
| --- | --- | --- |
| `axle-read-only-mode/get-status` | Read-only. Whether the site is frozen, until when and why, what holds it (`source`: `site`, `network` or `constant`), and its `scope` | `edit_posts` |
| `axle-read-only-mode/check-freeze` | Read-only. What a freeze would interrupt right now: people editing, scheduled posts and tasks due, orders waiting for payment. Input: `minutes`, `start`, `scope`, `cron`, `visitors`. Run it before `freeze` | the freeze capability |
| `axle-read-only-mode/freeze` | Freezes the site. Input: `minutes`, `reason`, `visitor_message`, `visitors`, `cron`, `scope` (`all`, `content` or `code`) | the freeze capability |
| `axle-read-only-mode/list-plans` | Read-only. The planned freezes: next start, length, scope, repeat days and lead | the freeze capability |
| `axle-read-only-mode/plan-freeze` | Plans a freeze for later. Input: `start` (required, in the future), `minutes`, `reason`, `scope`, `days` (0 Sunday to 6 Saturday, to repeat every week), `lead`, `email`, `visitors`, `cron` | the freeze capability |
| `axle-read-only-mode/cancel-plan` | Destructive, idempotent. Cancels a planned freeze by `id`. A freeze it already started keeps running | the freeze capability |
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
wp axle-read-only-mode check [--minutes=<n>] [--start=<datetime>] [--scope=<all|content|code>] [--no-cron] [--no-visitors] [--format=table|json]
wp axle-read-only-mode on [--minutes=<n>] [--reason=<text>] [--visitor-message=<text>] [--scope=<all|content|code>] [--no-visitors] [--no-cron] [--network] [--sites=<ids>]
wp axle-read-only-mode off [--network]
wp axle-read-only-mode plan --start=<datetime> [--minutes=<n>] [--reason=<text>] [--scope=<all|content|code>] [--days=<sun,mon,...>] [--lead=<minutes>] [--email-editors] [--no-visitors] [--no-cron] [--id=<id>] [--network] [--sites=<ids>]
wp axle-read-only-mode plans [--network] [--format=table|csv|json|yaml]
wp axle-read-only-mode cancel <id> [--network]
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

`check` shows what a freeze would interrupt and always exits 0, so a script can read it and decide. `--scope=content` freezes only content, `--scope=code` only settings and code; the default is `all`.

`plan` sets a freeze for later: `--start` is in the site time zone, such as `"2026-11-20 02:00"`. `--days=mon,thu` repeats it every week at that time of day. `plans` lists them and `cancel` removes one. `--id` changes an existing plan.

`health` prints the Site Health result and exits with status 2 when it is critical, so a monitor can alert on it.

On multisite, a command acts on the site WP-CLI loaded: the main site, or the one given with the global `--url`. Add `--network` to freeze, end, extend, list or configure the whole network instead, and `--sites=2,5` with `on --network` to freeze only those sites.

```sh
wp axle-read-only-mode check --minutes=30                           # look first
wp axle-read-only-mode on --scope=content --minutes=120             # content migration, deploys still work
wp axle-read-only-mode on --scope=code --minutes=30                 # code deploy window, editors keep writing
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
| `axle_read_only_mode_state` | The current or scheduled freeze, with its scope in `area` (`all`, `content` or `code`; missing means `all`) |
| `axle_read_only_mode_log` | Blocked attempts, and the first few requests the allow-list let through, during the current or last freeze (up to 100): time, user ID, the address without its query string, where it came from, whether it was allowed, and `area` (what it would have changed: `content` or `code`) |
| `axle_read_only_mode_history` | The last ten freezes, with the ID of the user who started each one and each one's `scope` |
| `axle_read_only_mode_settings` | The allow-list and the email settings |
| `axle_read_only_mode_plans` | The planned freezes: next start, length, scope, repeat days, lead, who planned it. Small, and loaded with every request so a plan starts on time |
| `axle_read_only_mode_plan_run_<id>_<start>`, `axle_read_only_mode_plan_mail_<id>_<start>` | Mark a plan occurrence as started, or its heads-up email as sent, so busy sites do each once |
| `axle_read_only_mode_start_findings` | What the check before freezing found when the last planned freeze started, for the start email |
| `axle_read_only_mode_dismissed_start` (user meta) | Set when someone dismisses the "Get started" card |
| `axle-read-only-mode:post:<id>` (browser local storage) | Your unsaved changes in the block editor, kept while the site is read-only. Removed when they are saved |
| `axle_read_only_mode_expired_<start>`, `axle_read_only_mode_started_<start>` | Mark a freeze as expired, or a scheduled one as started, so busy sites record each once |
| `axle_read_only_mode_mail_<event>` (transient) | One minute, so an event sends at most one email a minute |

On a multisite network the network keeps its own, as network (site) options: `axle_read_only_mode_network_state`, `axle_read_only_mode_network_history`, `axle_read_only_mode_network_settings`, `axle_read_only_mode_network_plans`, and the same markers with `network_` in the name.

Every logged-in attempt is logged. Anonymous attempts stop being written after the first 10 per freeze, so bots posting forms cannot turn every blocked request into a database write. Uninstalling removes all of it on every site of a multisite network, and the network options.

## Privacy

The log, the history and the planned freezes hold user IDs (who started or planned a freeze), and the log holds the addresses people tried to post to or that the allow-list let through. If you turn on emails, the recipients are kept in the settings. Query strings are never stored, because they can hold email addresses or tokens. Both take part in **Tools > Export Personal Data** and **Tools > Erase Personal Data**: erasing keeps the entry and removes the person from it. **Settings > Privacy** gets suggested text for your privacy policy.

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
