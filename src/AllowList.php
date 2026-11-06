<?php
/**
 * What keeps working during a freeze: validates, matches and describes the entries.
 *
 * @package AryanX\Axle\ReadOnlyMode
 */

namespace AryanX\Axle\ReadOnlyMode;

defined( 'ABSPATH' ) || exit;

/**
 * The settings' allow-list: REST route prefixes, front-end paths or query
 * arguments, admin-ajax actions and users. The guards ask it first. The
 * existing filters run after it, so code can still say yes or no.
 */
final class AllowList {

	public const TYPES = array( 'rest', 'paths', 'ajax', 'users' );

	/**
	 * Routes that would let every core write through.
	 */
	private const CORE_ROUTES = '#^/wp(/v2)?/?$#';

	/**
	 * The settings the lists come from.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Sets up the allow-list.
	 *
	 * @param Settings $settings Saved settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * The settings behind this list.
	 *
	 * @return Settings
	 */
	public function settings(): Settings {
		return $this->settings;
	}

	/**
	 * Most entries of each kind.
	 *
	 * @return int
	 */
	public static function limit(): int {
		/**
		 * Filters how many entries each part of the allow-list can hold.
		 *
		 * @param int $limit Default 50.
		 */
		return max( 1, (int) apply_filters( 'axle_read_only_mode_allow_limit', 50 ) );
	}

	/**
	 * Entries of one kind.
	 *
	 * @param string $type rest, paths, ajax or users.
	 * @return array
	 */
	public function entries( string $type ): array {
		return $this->settings->allow( $type );
	}

	/**
	 * How many entries each kind has.
	 *
	 * @return array<string, int>
	 */
	public function counts(): array {
		$counts = array();
		foreach ( self::TYPES as $type ) {
			$counts[ $type ] = count( $this->entries( $type ) );
		}

		return $counts;
	}

	/**
	 * Whether a REST route starts with an allowed prefix.
	 *
	 * @param string $route Route, such as /wc/v3/webhooks/12.
	 * @return bool
	 */
	public function matches_route( string $route ): bool {
		foreach ( $this->entries( 'rest' ) as $prefix ) {
			if ( 0 === strpos( $route, (string) $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a request address matches an allowed path or query argument.
	 *
	 * @param string $uri Address as requested: path and query string.
	 * @return bool
	 */
	public function matches_uri( string $uri ): bool {
		$entries = $this->entries( 'paths' );
		if ( ! $entries ) {
			return false;
		}

		$parts = wp_parse_url( $uri );
		$path  = (string) ( $parts['path'] ?? '' );
		$base  = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		if ( '' !== $base && 0 === strpos( $path, $base . '/' ) ) {
			$path = substr( $path, strlen( $base ) );
		}

		$query = array();
		parse_str( (string) ( $parts['query'] ?? '' ), $query );

		foreach ( $entries as $entry ) {
			$split = self::split_path_entry( (string) $entry );

			if ( '' !== $split['path'] && 0 !== strpos( $path, $split['path'] ) ) {
				continue;
			}
			if ( '' !== $split['arg'] ) {
				if ( ! array_key_exists( $split['arg'], $query ) ) {
					continue;
				}
				if ( '' !== $split['value'] && (string) $query[ $split['arg'] ] !== $split['value'] ) {
					continue;
				}
			}

			return true;
		}

		return false;
	}

	/**
	 * The admin-ajax actions on the list.
	 *
	 * @return string[]
	 */
	public function ajax_actions(): array {
		return array_map( 'strval', $this->entries( 'ajax' ) );
	}

	/**
	 * Whether an admin-ajax action is on the list.
	 *
	 * @param string $action Action, lower case.
	 * @return bool
	 */
	public function matches_ajax( string $action ): bool {
		return in_array( $action, $this->ajax_actions(), true );
	}

	/**
	 * Whether a user may keep editing.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public function matches_user( int $user_id ): bool {
		return $user_id > 0 && in_array( $user_id, array_map( 'intval', $this->entries( 'users' ) ), true );
	}

	/**
	 * Splits a path entry into its path and query argument.
	 *
	 * @param string $entry Entry such as /checkout/, ?wc-api= or /pay?token=abc.
	 * @return array{path: string, arg: string, value: string}
	 */
	private static function split_path_entry( string $entry ): array {
		$query = '';
		$pos   = strpos( $entry, '?' );
		$path  = false === $pos ? $entry : substr( $entry, 0, $pos );
		if ( false !== $pos ) {
			$query = substr( $entry, $pos + 1 );
		}

		$pair = explode( '=', $query, 2 );

		return array(
			'path'  => $path,
			'arg'   => $pair[0],
			'value' => $pair[1] ?? '',
		);
	}

	/**
	 * Checks and tidies one entry.
	 *
	 * @param string $type rest, paths, ajax or users.
	 * @param string $raw  Entry as typed.
	 * @return array{value: string|int, error: string} The entry to save, or why it was refused.
	 */
	public static function validate( string $type, string $raw ): array {
		$value = trim( $raw );
		$error = '';

		if ( '' === $value ) {
			$error = __( 'The entry is empty.', 'axle-read-only-mode' );
		} elseif ( strlen( $value ) > 200 ) {
			$error = __( 'The entry is longer than 200 characters.', 'axle-read-only-mode' );
		} elseif ( 'rest' === $type ) {
			list( $value, $error ) = self::validate_route( $value );
		} elseif ( 'paths' === $type ) {
			list( $value, $error ) = self::validate_path( $value );
		} elseif ( 'ajax' === $type ) {
			$value = strtolower( $value );
			if ( ! preg_match( '/^[a-z0-9_\-]+$/', $value ) ) {
				$error = __( 'An ajax action has letters, digits, dashes and underscores only.', 'axle-read-only-mode' );
			}
		} elseif ( 'users' === $type ) {
			$user = self::find_user( $value );
			if ( $user ) {
				$value = (int) $user->ID;
			} else {
				$error = __( 'No user has that login, email address or ID.', 'axle-read-only-mode' );
			}
		} else {
			$error = __( 'Unknown kind of entry.', 'axle-read-only-mode' );
		}

		if ( '' === $error ) {
			/**
			 * Filters whether an allow-list entry is accepted.
			 *
			 * Runs after the built-in checks. Return a message to refuse the entry,
			 * for example to keep a team from allowing a whole REST namespace.
			 *
			 * @param string     $error Empty to accept, or why the entry is refused. Default empty.
			 * @param string     $type  rest, paths, ajax or users.
			 * @param string|int $value The tidied entry: a route prefix, path, action name or user ID.
			 */
			$error = (string) apply_filters( 'axle_read_only_mode_validate_allow_entry', '', $type, $value );
		}

		return array(
			'value' => $value,
			'error' => $error,
		);
	}

	/**
	 * Checks a REST route prefix.
	 *
	 * @param string $value Entry.
	 * @return array{0: string, 1: string} Tidied route and error.
	 */
	private static function validate_route( string $value ): array {
		if ( preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $value ) ) {
			return array( $value, __( 'Give the route only, such as /wc/v3/webhooks, not a full address.', 'axle-read-only-mode' ) );
		}

		$value = (string) preg_replace( '/[?#].*$/', '', $value );
		if ( 0 === stripos( $value, '/wp-json/' ) ) {
			$value = substr( $value, 8 );
		}
		if ( '' === $value || '/' !== $value[0] ) {
			$value = '/' . $value;
		}

		if ( ! preg_match( '#^/[A-Za-z0-9_\-./]+$#', $value ) ) {
			return array( $value, __( 'A route looks like /plugin/v1/path: a slash, then letters, digits, dashes, underscores, dots and slashes.', 'axle-read-only-mode' ) );
		}
		if ( preg_match( self::CORE_ROUTES, $value ) ) {
			return array( $value, __( 'That would let every WordPress core write through, which defeats the freeze.', 'axle-read-only-mode' ) );
		}

		return array( $value, '' );
	}

	/**
	 * Checks a front-end path or query argument.
	 *
	 * @param string $value Entry.
	 * @return array{0: string, 1: string} Tidied entry and error.
	 */
	private static function validate_path( string $value ): array {
		if ( preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $value ) ) {
			return array( $value, __( 'Give the path only, such as /checkout/order-pay, not a full address.', 'axle-read-only-mode' ) );
		}
		if ( ! in_array( $value[0], array( '/', '?' ), true ) ) {
			return array( $value, __( 'Start with / for a path, or ? for a query argument such as ?wc-api=.', 'axle-read-only-mode' ) );
		}

		$split = self::split_path_entry( $value );
		if ( '' !== $split['path'] && ! preg_match( '#^/[A-Za-z0-9_\-./~%]*$#', $split['path'] ) ) {
			return array( $value, __( 'A path has letters, digits, dashes, underscores, dots, tildes, percent signs and slashes.', 'axle-read-only-mode' ) );
		}
		if ( '' !== $split['arg'] && ! preg_match( '/^[A-Za-z0-9_\-\[\]]+$/', $split['arg'] ) ) {
			return array( $value, __( 'A query argument has letters, digits, dashes and underscores.', 'axle-read-only-mode' ) );
		}
		if ( '' !== $split['value'] && ! preg_match( '/^[A-Za-z0-9_\-.%]+$/', $split['value'] ) ) {
			return array( $value, __( 'A query value has letters, digits, dashes, underscores, dots and percent signs.', 'axle-read-only-mode' ) );
		}
		if ( '' === $split['arg'] && ( '' === $split['path'] || '/' === $split['path'] ) ) {
			return array( $value, __( 'That would match every page. Name a path or a query argument.', 'axle-read-only-mode' ) );
		}

		return array( $value, '' );
	}

	/**
	 * Finds a user by login, email address or ID, in that order.
	 *
	 * @param string $value What was typed.
	 * @return \WP_User|false
	 */
	private static function find_user( string $value ) {
		$user = get_user_by( 'login', $value );
		if ( ! $user && false !== strpos( $value, '@' ) ) {
			$user = get_user_by( 'email', $value );
		}
		if ( ! $user && ctype_digit( $value ) ) {
			$user = get_user_by( 'id', (int) $value );
		}

		return $user;
	}

	/**
	 * Reads the settings form's text: one entry per line.
	 *
	 * @param string $type rest, paths, ajax or users.
	 * @param string $text The textarea.
	 * @return array{entries: array<int, string|int>, rejected: array<int, array{line: string, error: string}>}
	 */
	public static function parse( string $type, string $text ): array {
		$entries  = array();
		$rejected = array();

		foreach ( (array) preg_split( '/\R/', $text ) as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}

			$checked = self::validate( $type, $line );
			if ( '' !== $checked['error'] ) {
				$rejected[] = array(
					'line'  => $line,
					'error' => $checked['error'],
				);
				continue;
			}

			if ( ! in_array( $checked['value'], $entries, true ) ) {
				$entries[] = $checked['value'];
			}
		}

		return array(
			'entries'  => $entries,
			'rejected' => $rejected,
		);
	}

	/**
	 * What an entry matches, in plain words, for the settings screen.
	 *
	 * @param string        $type   rest, paths, ajax or users.
	 * @param string|int    $entry  A saved entry.
	 * @param string[]|null $routes Registered REST routes, to count what a prefix matches. Read once by the caller.
	 * @return array{tone: string, label: string, detail: string}
	 */
	public static function describe( string $type, $entry, ?array $routes = null ): array {
		if ( 'rest' === $type ) {
			$matching = array();
			foreach ( (array) $routes as $route ) {
				if ( 0 === strpos( (string) $route, (string) $entry ) ) {
					$matching[] = (string) $route;
				}
			}

			if ( $matching ) {
				return array(
					'tone'   => 'good',
					/* translators: %d: number of registered REST routes. */
					'label'  => sprintf( _n( '%d route', '%d routes', count( $matching ), 'axle-read-only-mode' ), count( $matching ) ),
					/* translators: 1: route prefix, 2: an example route. */
					'detail' => sprintf( __( 'POST, PUT, PATCH and DELETE requests to %1$s and everything below it, such as %2$s.', 'axle-read-only-mode' ), $entry, $matching[0] ),
				);
			}

			return array(
				'tone'   => 'neutral',
				'label'  => __( 'No route yet', 'axle-read-only-mode' ),
				/* translators: %s: route prefix. */
				'detail' => sprintf( __( 'No registered route starts with %s. It matches as soon as a plugin registers one.', 'axle-read-only-mode' ), $entry ),
			);
		}

		if ( 'paths' === $type ) {
			$split = self::split_path_entry( (string) $entry );

			if ( '' === $split['path'] ) {
				$detail = '' === $split['value']
					/* translators: %s: query argument. */
					? sprintf( __( 'Any request with the %s argument in its address, on any page.', 'axle-read-only-mode' ), $split['arg'] )
					/* translators: 1: query argument, 2: its value. */
					: sprintf( __( 'Any request with %1$s=%2$s in its address, on any page.', 'axle-read-only-mode' ), $split['arg'], $split['value'] );
			} elseif ( '' === $split['arg'] ) {
				/* translators: %s: path. */
				$detail = sprintf( __( 'Form posts to addresses that start with %s.', 'axle-read-only-mode' ), $split['path'] );
			} else {
				/* translators: 1: path, 2: query argument. */
				$detail = sprintf( __( 'Form posts to addresses that start with %1$s and carry the %2$s argument.', 'axle-read-only-mode' ), $split['path'], $split['arg'] );
			}

			return array(
				'tone'   => 'good',
				'label'  => '' === $split['path'] ? __( 'Query argument', 'axle-read-only-mode' ) : __( 'Path', 'axle-read-only-mode' ),
				'detail' => $detail,
			);
		}

		if ( 'ajax' === $type ) {
			$logged_in = has_action( 'wp_ajax_' . $entry );
			$visitors  = has_action( 'wp_ajax_nopriv_' . $entry );

			if ( $logged_in || $visitors ) {
				return array(
					'tone'   => 'good',
					'label'  => __( 'Handler found', 'axle-read-only-mode' ),
					/* translators: %s: who can call it: "logged-in users", "visitors" or both. */
					'detail' => sprintf( __( 'admin-ajax.php requests with this action are let through. A plugin handles it for %s.', 'axle-read-only-mode' ), $logged_in && $visitors ? __( 'logged-in users and visitors', 'axle-read-only-mode' ) : ( $logged_in ? __( 'logged-in users', 'axle-read-only-mode' ) : __( 'visitors', 'axle-read-only-mode' ) ) ),
				);
			}

			return array(
				'tone'   => 'neutral',
				'label'  => __( 'No handler yet', 'axle-read-only-mode' ),
				'detail' => __( 'No plugin handles this action on this screen. It is let through anyway if one does later.', 'axle-read-only-mode' ),
			);
		}

		$user = get_userdata( (int) $entry );
		if ( ! $user ) {
			return array(
				'tone'   => 'warning',
				'label'  => __( 'User missing', 'axle-read-only-mode' ),
				'detail' => __( 'This user no longer exists. Remove the entry.', 'axle-read-only-mode' ),
			);
		}

		return array(
			'tone'   => 'good',
			'label'  => __( 'User', 'axle-read-only-mode' ),
			/* translators: 1: display name, 2: login. */
			'detail' => sprintf( __( '%1$s (%2$s) can keep editing during a freeze.', 'axle-read-only-mode' ), $user->display_name, $user->user_login ),
		);
	}
}
