=== Axle Read-Only Mode ===
Contributors: aryanjasala
Tags: maintenance, migration, read only, deploy, freeze
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 3.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Read-only mode for migrations and deploys. Visitors still see the site; nothing can be saved, so no edits, orders or comments get lost.

== Description ==

When you move a site to a new server, or replace the live database with one from staging, anything saved on the old copy during the move is lost: a post an editor was finishing, a comment, a form entry, a WooCommerce order.

Maintenance mode hides the whole site from visitors. Axle Read-Only Mode keeps the site online but makes it **read-only**:

* **Editors** can open and read everything in wp-admin, but cannot save. The block editor disables Save and Publish and shows why. People who already have the editor open are locked within a minute, through the WordPress heartbeat, keep what they typed, and are unlocked again, with their changes saved, when the freeze ends.
* **Admin actions** that change data are refused: saving settings, uploading media, installing or updating plugins, trashing posts. Automatic updates are paused.
* **The REST API and XML-RPC** refuse anything that writes, with HTTP status 423. Reading still works.
* **Visitor submissions** (optional, on by default): comments, sign-ups, contact forms, checkouts and any other form post get a "back soon" page with HTTP 503 and a Retry-After header, which search engines understand.
* **Scheduled tasks** (optional, on by default) are held back and run when the freeze ends.

Logging in always works, so an admin can always unfreeze.

Free and open source under the GPL. No pro version, no upsells, no ads in wp-admin, no tracking. And it stays that way.

Part of [Axle](https://axlewp.com/) by AryanX, a family of free plugins for running WordPress sites safely.

= Check before you freeze =

The freeze screen has a "Before you freeze" card that shows what a freeze would interrupt right now, for the length, start and scope you picked, and updates as you change them:

* **People editing**: who has a post open in the editor, which post, and how long ago.
* **Scheduled posts** due inside the freeze. With scheduled tasks held, they publish when the freeze ends, not on time.
* **WooCommerce orders waiting for payment**, created recently. Only when WooCommerce is active.
* **Scheduled tasks** due inside the freeze that would be held.

It informs and never blocks. If anyone else is editing, the freeze button asks first and says how many people. The admin bar freeze stays one click, and the notice afterwards says how many people were editing. Other plugins can add their own items with the `axle_read_only_mode_checks` filter. `wp axle-read-only-mode check` and the `check-freeze` ability give the same answer to scripts and AI agents.

= Plan a freeze, with a heads-up =

Plan a freeze for later on **Tools > Read-Only Mode > Planned**: a start time, a length, a scope and a reason. Repeat it every week on the days you choose, for a regular deploy window. It starts by itself on the first request after its time, even when WP-Cron is late, and the check before freezing runs again at the start, with its findings in the start email.

From 15 minutes before (you can change it per plan), everyone who is writing sees a countdown: a notice in wp-admin and in the block editor, "The site goes read-only in 12 minutes. Save your work." It updates through the WordPress heartbeat, so a plan you cancel or move changes it within a minute, and screen readers hear it once. You can also email the people who have a post open when the heads-up starts. WP-CLI has `plan`, `plans` and `cancel`, and there are abilities for each. On multisite, Network Admin has its own list of planned freezes.

= Nothing typed during a freeze is lost =

If a freeze starts while you are writing in the block editor, your changes stay in the editor and a copy waits in your browser. One notice says when the freeze ends. When it ends, your changes are saved for you, unless the post changed on the server meanwhile. Then you see both versions and choose which one to keep. Nothing is overwritten without a choice. In the classic editor, Update does nothing during a freeze and the form keeps what you typed.

= Freeze part of the site =

A freeze has a scope:

* **Everything** (the default): nothing can be saved.
* **Content only**: posts, pages, media, comments, terms, menus, orders and form entries cannot be saved. Settings, plugins, themes and updates keep working, so a deploy can go on during a content migration or a launch-day content lock.
* **Settings and code only**: options, plugins, themes, updates and user accounts cannot be changed. Editors, commenters and customers keep working, so a code deploy or a content migration cannot drift from its configuration.

The admin screens, REST API, XML-RPC, block editor, abilities, visitor submissions, scheduled tasks and the allow-list all follow the scope. The scope shows in the status badge, the notice on every screen, the admin bar, the blocked list, the history, the emails and WP-CLI (`--scope=content` or `--scope=code`). Anything the plugin cannot recognise as settings or code counts as content, and the `axle_read_only_mode_request_area` filter fixes a screen it sorts wrongly.

= See what was blocked =

Every blocked attempt is listed: who tried, when, and what (a post save, an upload, a REST request, a contact form). After the move you know exactly which edits to redo on the new server. The last ten freezes are kept too, with how many attempts each one blocked.

= Freeze safely =

* Pick an end time and the freeze ends by itself. A deploy script that fails halfway cannot leave the site frozen for days.
* Running late? Add 30 minutes or an hour from the freeze screen or the admin bar. A live countdown shows the time left.
* Plan a freeze for later, once or every week, with a countdown for everyone who is writing.
* Tick "Let me keep editing" to exempt yourself.
* Write your own message for visitors, for example "We are moving servers. Back by 3 pm."
* Freeze for an hour straight from the admin bar (under the site name), and unfreeze from the admin bar or the notice on every screen.

= Keep what must keep working =

A payment webhook, one form, a REST route, an AJAX action or one person may need to keep working during a freeze. **Tools > Read-Only Mode > Settings** has a "Keep these working while frozen" card for them, so you do not need code:

* **REST API routes**, one prefix per line, such as `/wc/v3/webhooks`.
* **Front-end paths or query arguments**, such as `/checkout/order-pay` or `?wc-api=`.
* **Ajax actions**, one per line.
* **Users** who may keep editing, on top of the "Let me keep editing" tick.

Each entry is checked when you save it, and each one says what it matches. When WooCommerce, Stripe for WooCommerce or Gravity Forms is active, you also get common entries with an Add button. Nothing is ever added without a click. Requests the list lets through show up in the blocked list, marked as allowed, so you can see it working. The existing filters still run after the settings, so code can say yes or no.

= Multisite =

Freeze the whole network, or chosen sites, from **Network Admin > Settings > Read-Only Mode**. A network freeze applies on every site it covers and cannot be lifted from a site, which shows "frozen by the network" in its notice and admin bar. Each site admin can still freeze their own site from Tools. Each site logs what it blocked, and the network screen lists it per site and across the network. Network-activate the plugin so a network freeze reaches every site. WP-CLI takes `--network`, `--sites` and the global `--url`.

= Email when a freeze starts or ends =

Off by default. Choose who gets it (the admin email if you leave it empty) and when: a freeze starts, someone ends it, it ends by itself at its end time, it is extended, or a scheduled freeze starts. The email says who, why and until when, and for an end, how many attempts were blocked with a link to the list. It uses `wp_mail()`, and the same event never sends more than one email a minute.

= Site Health =

Tools > Site Health has a read-only mode test, and a second one that says when a planned freeze did not start. It is good when the site is not frozen, recommended while a freeze with an end time is running, and critical when a freeze ran 30 minutes past its end time, a freeze with no end time has lasted more than 6 hours, or `wp-config.php` forces the freeze. The Info tab lists the state, end time, allow-list counts and email settings.

= From a deploy script =

`wp axle-read-only-mode on --minutes=30 --reason="Moving servers"`
`wp axle-read-only-mode off`
`wp axle-read-only-mode status`
`wp axle-read-only-mode extend 30`
`wp axle-read-only-mode plan --start="2026-11-20 02:00" --minutes=45 --days=fri`
`wp axle-read-only-mode plans`
`wp axle-read-only-mode log`
`wp axle-read-only-mode on --network` (a whole multisite network)
`wp axle-read-only-mode allow rest /wc/v3/webhooks`
`wp axle-read-only-mode notify --enable`
`wp axle-read-only-mode health`

WP-CLI itself is never blocked, so your scripts can keep importing and replacing data. Use `--no-visitors` or `--no-cron` to leave those running.

Or freeze from wp-config.php while files are being swapped:

`define( 'AXLE_READ_ONLY_MODE', true );`
`define( 'AXLE_READ_ONLY_MODE_REASON', 'Moving servers' );`

**Try it before you install.** [try.axlewp.com/axle-read-only-mode](https://try.axlewp.com/axle-read-only-mode) opens a private WordPress in your browser with this plugin and some demo content.


== Frequently Asked Questions ==

= How is this different from a maintenance mode plugin? =

Maintenance mode shows visitors a placeholder page instead of the site. Axle Read-Only Mode keeps the site fully visible and only stops changes. Use it when the site has to stay online during the move.

= Does it stop every plugin's forms? =

It stops every form that posts to the site from the front end, every REST API write and every admin-ajax request that is not on the read-only list. A form that sends data straight to an outside service is not affected.

= A read-only screen of my plugin stopped working during the freeze. =

Admin-ajax requests are blocked unless they are known to be read-only. Add the action name under Tools > Read-Only Mode > Settings, or with the `axle_read_only_mode_allowed_ajax_actions` filter.

= How do I let a payment webhook or one form through? =

Add its REST route, its path or its query argument (such as `?wc-api=`) on the Settings tab. Payment providers such as Stripe and PayPal retry a webhook that gets a "back soon" answer, so orders paid during a freeze usually arrive once it ends. Let a webhook through only if its provider does not retry.

= Can someone keep working while the site is frozen? =

Yes. Tick "Let me keep editing" when you freeze, add the person under "Users who may keep editing" on the Settings tab, or use the `axle_read_only_mode_user_can_bypass` filter.

= Can a site on my network unfreeze itself during a network freeze? =

No. A network freeze overrides the site. Only a network admin can end it, from Network Admin or with `wp axle-read-only-mode off --network`. The site's own freeze is separate: the site is frozen while either one is running.

= Does a network freeze reach sites where the plugin is not active? =

No. The plugin enforces the freeze, so it has to be active on the site. Network-activate it so every site is covered.

= What does "Content only" leave open? =

Options, plugins, themes, widgets, templates, user accounts and updates keep working, and update checks keep running. Everything else is treated as content, including a plugin's own screens and forms the plugin does not recognise as settings. Visitor comments, forms and checkouts are content, so they pause too. Sign-ups pause with them.

= Do editors get interrupted when I freeze settings and code only? =

No. Editors, commenters and customers keep working. The "Before you freeze" card says so ("Not affected") when you pick that scope.

= Does the check stop me from freezing? =

No. It only tells you what would be interrupted. If other people have a post open, the freeze button asks first and says how many.

= Does a planned freeze need WP-Cron? =

No. Every request checks the list of plans and starts the first one whose time has passed, so a plan starts on the first request after its time. On a site nobody visits that can be late, and Site Health says so. A cron job that opens the site a minute before the plan is enough.

= What happens to my changes if the freeze starts while I am writing? =

In the block editor they stay in the editor, and a copy waits in your browser. When the freeze ends they are saved, unless someone else changed the post meanwhile: then you choose between your version and the saved one. In the classic editor the form keeps what you typed, and you press Update again when the freeze ends.

= Does it send email? =

Only if you turn it on, under Tools > Read-Only Mode > Settings. It uses `wp_mail()` and sends at most one email per event per minute. A planned freeze can also email the people who have a post open when its heads-up starts, if you tick that on the plan.

== Screenshots ==

1. The block editor during a freeze.
2. Tools > Read-Only Mode during a freeze, with countdown and blocked attempts.
3. What visitors see when they send a form during a freeze.
4. Tools > Read-Only Mode > Settings, with the allow-list and the email choices.

== Changelog ==

= 3.2.0 =
* Fix: write relative times in lower case so they read well mid-sentence
* New: plan freezes with a countdown, keep edits made during a freeze

= 3.1.0 =
* New: check what a freeze would interrupt, and freeze part of the site
* New: freeze a multisite network, allow-list, email and Site Health

= 3.0.0 =
* Changed (breaking change): rename to Axle Read-Only Mode for WordPress.org

= 2.0.1 =
* Fix: refuse abilities that write during a freeze on WordPress 6.9 and 7.0

= 2.0.0 =
* Changed (breaking change): rename to Axle Read-Only Mode for WordPress.org

= 1.3.0 =
* Fix: make the abilities visible to MCP agents, and let the MCP adapter run them during a freeze
* New: add abilities so AI agents can check and control a freeze, and refuse other abilities that write during one

= 1.2.0 =
* New: add filters for the freeze lengths, messages and limits, and actions for each blocked request
* Fix: stop bots from flooding the freeze log, and keep personal data out of it
* Fix: keep editor housekeeping working during a freeze

= 1.1.0 =
* New: list of blocked attempts, so you know which edits to redo after a migration.
* New: history of the last ten freezes.
* New: extend a freeze by 30 minutes or an hour, with a live countdown.
* New: freeze and unfreeze from the admin bar.
* New: custom message for visitors.
* New: `wp axle-read-only-mode extend` and `wp axle-read-only-mode log`.
* Rewritten as PSR-4 classes.

= 1.0.0 =
* First release.
