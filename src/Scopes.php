<?php
/**
 * What a freeze covers.
 *
 * @package AryanX\Axle\ReadOnlyMode
 */

namespace AryanX\Axle\ReadOnlyMode;

defined( 'ABSPATH' ) || exit;

/**
 * Scopes and the rules that sort a request into an area.
 *
 * A freeze has a scope: everything (the default), content only, or settings
 * and code only. A scope covers one or more areas. Every request, ability and
 * scheduled event belongs to one area, and a guard stops it only when the
 * freeze's scope covers that area.
 *
 * - `code` is options, plugins, themes, updates, widgets, templates, fonts and
 *   user accounts and roles.
 * - `content` is everything else: posts, pages, media, comments, terms, menus,
 *   orders, form entries, and anything this plugin cannot recognise as code.
 *   Unknown means content, so a content freeze errs on the side of stopping.
 *
 * Visitor submissions (comments, forms, checkouts, sign-ups) are content, so
 * they follow the scope: paused by Everything and Content only, open during
 * Settings and code only.
 */
final class Scopes {

	public const ALL     = 'all';
	public const CONTENT = 'content';
	public const CODE    = 'code';

	/**
	 * Admin screens that change settings, plugins, themes, updates or accounts.
	 */
	private const CODE_SCREENS = array(
		'options.php',
		'options-general.php',
		'options-writing.php',
		'options-reading.php',
		'options-discussion.php',
		'options-media.php',
		'options-permalink.php',
		'options-privacy.php',
		'plugins.php',
		'plugin-install.php',
		'plugin-editor.php',
		'theme-editor.php',
		'themes.php',
		'theme-install.php',
		'update.php',
		'update-core.php',
		'upgrade.php',
		'customize.php',
		'widgets.php',
		'site-editor.php',
		'site-health.php',
		'users.php',
		'user-new.php',
		'user-edit.php',
		'profile.php',
		'settings.php',
		'setup.php',
		'network.php',
		'sites.php',
		'site-new.php',
		'site-info.php',
		'site-users.php',
		'site-themes.php',
		'site-settings.php',
	);

	/**
	 * REST route prefixes that change settings, plugins, themes, templates or accounts.
	 */
	private const CODE_ROUTES = array(
		'/wp/v2/settings',
		'/wp/v2/plugins',
		'/wp/v2/themes',
		'/wp/v2/users',
		'/wp/v2/templates',
		'/wp/v2/template-parts',
		'/wp/v2/global-styles',
		'/wp/v2/widgets',
		'/wp/v2/sidebars',
		'/wp/v2/widget-types',
		'/wp/v2/block-directory',
		'/wp/v2/font-families',
		'/wp/v2/font-collections',
		'/wp-site-health/',
	);

	/**
	 * Admin-ajax actions that install, change or update code and settings.
	 */
	private const CODE_AJAX = array(
		'install-plugin',
		'install-theme',
		'update-plugin',
		'update-theme',
		'delete-plugin',
		'delete-theme',
		'edit-theme-plugin-file',
		'toggle-auto-updates',
		'save-widget',
		'update-widget',
		'delete-inactive-widgets',
		'customize_save',
		'update-core',
		'wp-ajax-edit-theme-plugin-file',
	);

	/**
	 * Scheduled events that check for or install updates, or run site checks.
	 */
	private const CODE_CRON = array(
		'wp_version_check',
		'wp_update_plugins',
		'wp_update_themes',
		'wp_maybe_auto_update',
		'wp_site_health_scheduled_check',
		'recovery_mode_clean_expired_keys',
		'delete_expired_transients',
	);

	/**
	 * XML-RPC methods that change settings or accounts.
	 */
	private const CODE_XMLRPC = array(
		'wp.setOptions',
		'wp.newUser',
		'wp.editProfile',
		'wp.deleteUser',
	);

	/**
	 * Words in a screen or action name that mark a settings form.
	 */
	private const SETTINGS_WORDS = '/settings|options|config|license/i';

	/**
	 * The scopes, in the order the screen lists them.
	 *
	 * @return array<string, array{label: string, status: string, description: string, covers: string[]}>
	 */
	public static function all(): array {
		$scopes = array(
			self::ALL     => array(
				'label'       => __( 'Everything', 'axle-read-only-mode' ),
				'status'      => __( 'Frozen', 'axle-read-only-mode' ),
				'description' => __( 'Nothing can be saved: content, settings, plugins, themes and updates.', 'axle-read-only-mode' ),
				'covers'      => array( self::CONTENT, self::CODE ),
			),
			self::CONTENT => array(
				'label'       => __( 'Content only', 'axle-read-only-mode' ),
				'status'      => __( 'Content frozen', 'axle-read-only-mode' ),
				'description' => __( 'Posts, pages, media, comments, terms, menus, orders and form entries cannot be saved. Settings, plugins, themes and updates still work, so a deploy can go on.', 'axle-read-only-mode' ),
				'covers'      => array( self::CONTENT ),
			),
			self::CODE    => array(
				'label'       => __( 'Settings and code only', 'axle-read-only-mode' ),
				'status'      => __( 'Settings and code frozen', 'axle-read-only-mode' ),
				'description' => __( 'Options, plugins, themes, updates and user accounts cannot be changed. Editors, commenters and customers keep working, so a content migration cannot drift from its configuration.', 'axle-read-only-mode' ),
				'covers'      => array( self::CODE ),
			),
		);

		/**
		 * Filters the scopes a freeze can have, and what each one covers.
		 *
		 * Each scope has a label, a status (the words on the badge and admin
		 * bar), a description, and `covers`: the areas it freezes. The areas
		 * this plugin sorts requests into are `content` and `code`. The first
		 * scope is the fallback for a stored value that no longer exists, so
		 * keep `all` first.
		 *
		 * @param array $scopes Scope slug => array( label, status, description, covers ).
		 */
		$filtered = apply_filters( 'axle_read_only_mode_scopes', $scopes );

		$clean = array();
		foreach ( (array) $filtered as $slug => $scope ) {
			if ( ! is_array( $scope ) || ! isset( $scope['label'], $scope['covers'] ) ) {
				continue;
			}
			$clean[ (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $slug ) ) ] = array(
				'label'       => (string) $scope['label'],
				'status'      => (string) ( $scope['status'] ?? $scope['label'] ),
				'description' => (string) ( $scope['description'] ?? '' ),
				'covers'      => array_values( array_map( 'strval', (array) $scope['covers'] ) ),
			);
		}

		return $clean ? $clean : $scopes;
	}

	/**
	 * A stored or typed scope, or the default when it is not a known one.
	 *
	 * @param string $scope Scope slug.
	 * @return string
	 */
	public static function normalize( string $scope ): string {
		$scopes = self::all();

		return isset( $scopes[ $scope ] ) ? $scope : self::ALL;
	}

	/**
	 * Whether a scope freezes an area.
	 *
	 * @param string $scope Scope slug.
	 * @param string $area  content or code.
	 * @return bool
	 */
	public static function covers( string $scope, string $area ): bool {
		$scopes = self::all();
		$scope  = self::normalize( $scope );

		return in_array( $area, $scopes[ $scope ]['covers'], true );
	}

	/**
	 * Words for a scope: "Everything", "Content only".
	 *
	 * @param string $scope Scope slug.
	 * @return string
	 */
	public static function label( string $scope ): string {
		return self::all()[ self::normalize( $scope ) ]['label'];
	}

	/**
	 * Words for a frozen site with this scope: "Frozen", "Content frozen".
	 *
	 * @param string $scope Scope slug.
	 * @return string
	 */
	public static function status( string $scope ): string {
		return self::all()[ self::normalize( $scope ) ]['status'];
	}

	/**
	 * Scope when none is given: the admin bar quick freeze, WP-CLI, the PHP API,
	 * abilities, and the choice preselected on the screen.
	 *
	 * @return string
	 */
	public static function default_scope(): string {
		/**
		 * Filters the scope a freeze gets when none is chosen.
		 *
		 * @param string $scope Scope slug. Default all.
		 */
		return self::normalize( (string) apply_filters( 'axle_read_only_mode_default_scope', self::ALL ) );
	}

	/**
	 * Words for an area: "Content", "Settings and code".
	 *
	 * @param string $area content or code.
	 * @return string
	 */
	public static function area_label( string $area ): string {
		if ( self::CODE === $area ) {
			return __( 'Settings and code', 'axle-read-only-mode' );
		}

		return __( 'Content', 'axle-read-only-mode' );
	}

	/**
	 * Sorts an admin screen request into an area.
	 *
	 * @param string $pagenow       The admin file, such as options.php.
	 * @param string $action        The action request value, if any.
	 * @param string $page          The page request value, if any.
	 * @param bool   $network_admin Whether this is Network Admin.
	 * @param bool   $option_page   Whether the request carries a Settings API form (option_page).
	 * @return string content or code.
	 */
	public static function admin_area( string $pagenow, string $action = '', string $page = '', bool $network_admin = false, bool $option_page = false ): string {
		$area = self::CONTENT;

		/**
		 * Filters the admin files that change settings, plugins, themes, updates or accounts.
		 *
		 * @param string[] $screens File names such as plugins.php.
		 */
		$screens = array_map( 'strval', (array) apply_filters( 'axle_read_only_mode_code_screens', self::CODE_SCREENS ) );

		if ( in_array( $pagenow, $screens, true ) || ( $network_admin && 'edit.php' === $pagenow ) || $option_page ) {
			$area = self::CODE;
		} elseif ( in_array( $pagenow, array( 'admin.php', 'admin-post.php', 'tools.php' ), true ) && ( preg_match( self::SETTINGS_WORDS, $page ) || preg_match( self::SETTINGS_WORDS, $action ) ) ) {
			// A plugin's own settings form that does not use the Settings API.
			$area = self::CODE;
		}

		return self::filter_area( $area, 'admin', $pagenow, array( 'action' => $action ) );
	}

	/**
	 * Sorts a REST route into an area.
	 *
	 * @param string $route  Route, such as /wp/v2/posts/12.
	 * @param string $method HTTP method.
	 * @return string content or code.
	 */
	public static function rest_area( string $route, string $method = 'POST' ): string {
		$area = self::CONTENT;

		/**
		 * Filters the REST route prefixes that change settings, plugins, themes, templates or accounts.
		 *
		 * @param string[] $routes Route prefixes.
		 */
		foreach ( (array) apply_filters( 'axle_read_only_mode_code_routes', self::CODE_ROUTES ) as $prefix ) {
			if ( 0 === strpos( $route, (string) $prefix ) ) {
				$area = self::CODE;
				break;
			}
		}

		return self::filter_area( $area, 'rest', $route, array( 'method' => $method ) );
	}

	/**
	 * Sorts an admin-ajax action into an area.
	 *
	 * @param string $action Ajax action.
	 * @return string content or code.
	 */
	public static function ajax_area( string $action ): string {
		/**
		 * Filters the admin-ajax actions that install, change or update code and settings.
		 *
		 * @param string[] $actions Ajax actions.
		 */
		$code = array_map( 'strval', (array) apply_filters( 'axle_read_only_mode_code_ajax_actions', self::CODE_AJAX ) );

		return self::filter_area( in_array( $action, $code, true ) ? self::CODE : self::CONTENT, 'ajax', $action );
	}

	/**
	 * Sorts an ability into an area, by what its name says it works on.
	 *
	 * @param string $name Ability name, such as core/update-option.
	 * @return string content or code.
	 */
	public static function ability_area( string $name ): string {
		/**
		 * Filters the words in an ability name that mark it as working on settings or code.
		 *
		 * @param string[] $words Default option, setting, plugin, theme, update, user, role, widget, template.
		 */
		$words = array_map( 'strval', (array) apply_filters( 'axle_read_only_mode_code_ability_words', array( 'option', 'setting', 'plugin', 'theme', 'update', 'user', 'role', 'widget', 'template' ) ) );
		$area  = self::CONTENT;

		foreach ( $words as $word ) {
			if ( '' !== $word && false !== stripos( $name, $word ) ) {
				$area = self::CODE;
				break;
			}
		}

		return self::filter_area( $area, 'ability', $name );
	}

	/**
	 * Sorts a scheduled event into an area.
	 *
	 * @param string $hook Event hook.
	 * @return string content or code.
	 */
	public static function cron_area( string $hook ): string {
		/**
		 * Filters the scheduled events that check for updates or run site checks.
		 *
		 * Every other event counts as content, so a content freeze holds it.
		 *
		 * @param string[] $hooks Event hooks.
		 */
		$code = array_map( 'strval', (array) apply_filters( 'axle_read_only_mode_code_cron_hooks', self::CODE_CRON ) );

		return self::filter_area( in_array( $hook, $code, true ) ? self::CODE : self::CONTENT, 'cron', $hook );
	}

	/**
	 * XML-RPC methods that change settings or accounts, which a settings and code freeze removes.
	 *
	 * @return string[]
	 */
	public static function code_xmlrpc_methods(): array {
		/**
		 * Filters the XML-RPC methods a settings and code freeze removes.
		 *
		 * @param string[] $methods Default wp.setOptions, wp.newUser, wp.editProfile, wp.deleteUser.
		 */
		return array_map( 'strval', (array) apply_filters( 'axle_read_only_mode_code_xmlrpc_methods', self::CODE_XMLRPC ) );
	}

	/**
	 * Sorts a front-end request into an area. Posts from the front end are content.
	 *
	 * @param string $uri Request path.
	 * @return string content or code.
	 */
	public static function front_end_area( string $uri ): string {
		return self::filter_area( self::CONTENT, 'front-end', $uri );
	}

	/**
	 * Lets code change the area of one request.
	 *
	 * @param string $area    content or code.
	 * @param string $kind    admin, rest, ajax, ability, cron or front-end.
	 * @param string $subject The screen, route, action, ability, hook or path.
	 * @param array  $context Extra facts about the request.
	 * @return string
	 */
	private static function filter_area( string $area, string $kind, string $subject, array $context = array() ): string {
		/**
		 * Filters the area a request belongs to, which decides whether a freeze with
		 * a given scope stops it.
		 *
		 * Use it for a plugin screen or route this plugin sorts wrongly. Return
		 * `code` for settings and `content` for everything else.
		 *
		 * @param string $area    Default content or code, from the built-in rules.
		 * @param string $kind    admin, rest, ajax, ability, cron or front-end.
		 * @param string $subject The admin file, REST route, ajax action, ability name, cron hook or request path.
		 * @param array  $context Extra facts: action (admin), method (rest).
		 */
		$filtered = (string) apply_filters( 'axle_read_only_mode_request_area', $area, $kind, $subject, $context );

		return '' !== $filtered ? $filtered : $area;
	}
}
