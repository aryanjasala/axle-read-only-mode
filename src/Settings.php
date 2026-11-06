<?php
/**
 * Saved settings: what keeps working during a freeze, and who is told about it.
 *
 * @package AryanX\Axle\ReadOnlyMode
 */

namespace AryanX\Axle\ReadOnlyMode;

defined( 'ABSPATH' ) || exit;

/**
 * One site's settings, or the network's. Both have the same shape.
 */
final class Settings {

	public const OPTION         = 'axle_read_only_mode_settings';
	public const NETWORK_OPTION = 'axle_read_only_mode_network_settings';

	private const DEFAULTS = array(
		'allow'  => array(
			'rest'  => array(),
			'paths' => array(),
			'ajax'  => array(),
			'users' => array(),
		),
		'notify' => array(
			'enabled'    => false,
			'recipients' => array(),
			'events'     => array( 'started', 'ended', 'expired' ),
		),
	);

	/**
	 * Where the settings are saved.
	 *
	 * @var Store
	 */
	private Store $store;

	/**
	 * Sets up the settings.
	 *
	 * @param Store|null $store Where they are saved. Default: this site's option.
	 */
	public function __construct( ?Store $store = null ) {
		$this->store = $store ?? new Store( self::OPTION, false, false );
	}

	/**
	 * Whether these are the network's settings.
	 *
	 * @return bool
	 */
	public function is_network(): bool {
		return $this->store->is_network();
	}

	/**
	 * Saved settings merged with defaults.
	 *
	 * @return array{allow: array{rest: string[], paths: string[], ajax: string[], users: int[]}, notify: array{enabled: bool, recipients: string[], events: string[]}}
	 */
	public function all(): array {
		$saved  = $this->store->get( array() );
		$saved  = is_array( $saved ) ? $saved : array();
		$allow  = isset( $saved['allow'] ) && is_array( $saved['allow'] ) ? $saved['allow'] : array();
		$notify = isset( $saved['notify'] ) && is_array( $saved['notify'] ) ? $saved['notify'] : array();

		$merged = array(
			'allow'  => array(),
			'notify' => array(
				'enabled'    => ! empty( $notify['enabled'] ),
				'recipients' => isset( $notify['recipients'] ) && is_array( $notify['recipients'] ) ? array_values( array_map( 'strval', $notify['recipients'] ) ) : self::DEFAULTS['notify']['recipients'],
				'events'     => isset( $notify['events'] ) && is_array( $notify['events'] ) ? array_values( array_map( 'strval', $notify['events'] ) ) : self::DEFAULTS['notify']['events'],
			),
		);
		foreach ( self::DEFAULTS['allow'] as $type => $default ) {
			$merged['allow'][ $type ] = isset( $allow[ $type ] ) && is_array( $allow[ $type ] ) ? array_values( $allow[ $type ] ) : $default;
		}
		$merged['allow']['rest']  = array_map( 'strval', $merged['allow']['rest'] );
		$merged['allow']['paths'] = array_map( 'strval', $merged['allow']['paths'] );
		$merged['allow']['ajax']  = array_map( 'strval', $merged['allow']['ajax'] );
		$merged['allow']['users'] = array_map( 'intval', $merged['allow']['users'] );

		return $merged;
	}

	/**
	 * The allow-list entries of one kind.
	 *
	 * @param string $type rest, paths, ajax or users.
	 * @return array
	 */
	public function allow( string $type ): array {
		return $this->all()['allow'][ $type ] ?? array();
	}

	/**
	 * The email notification settings.
	 *
	 * @return array{enabled: bool, recipients: string[], events: string[]}
	 */
	public function notify(): array {
		return $this->all()['notify'];
	}

	/**
	 * Saves the email notification settings from the settings form.
	 *
	 * @param array $input enabled (checked or not), recipients (one address per line or comma separated), events (list).
	 * @return array<int, array{line: string, error: string}> Recipients that are not email addresses.
	 */
	public function save_notify( array $input ): array {
		$all      = $this->all();
		$rejected = array();
		$valid    = array();

		foreach ( (array) preg_split( '/[\s,;]+/', (string) ( $input['recipients'] ?? '' ) ) as $address ) {
			$address = trim( (string) $address );
			if ( '' === $address ) {
				continue;
			}

			if ( is_email( $address ) ) {
				$valid[ strtolower( $address ) ] = $valid[ strtolower( $address ) ] ?? $address;
			} else {
				$rejected[] = array(
					'line'  => $address,
					'error' => __( 'That is not an email address.', 'axle-read-only-mode' ),
				);
			}
		}

		$all['notify'] = array(
			'enabled'    => ! empty( $input['enabled'] ),
			'recipients' => array_values( $valid ),
			'events'     => array_values( array_intersect( Notifier::EVENTS, array_map( 'strval', (array) ( $input['events'] ?? array() ) ) ) ),
		);
		$this->store->set( $all );

		/**
		 * Fires after the email notification settings are saved.
		 *
		 * @param array $notify  enabled, recipients (addresses) and events.
		 * @param bool  $network Whether they are the network's.
		 */
		do_action( 'axle_read_only_mode_notify_settings_saved', $all['notify'], $this->is_network() );

		return $rejected;
	}

	/**
	 * Replaces the allow-list from the settings form.
	 *
	 * Entries that do not pass validation are left out and reported, so one
	 * typo does not lose the rest of the list.
	 *
	 * @param array $input rest, paths, ajax and users, each a string with one entry per line.
	 * @return array<string, array<int, array{line: string, error: string}>> Rejected lines by type.
	 */
	public function save_allow( array $input ): array {
		$all      = $this->all();
		$rejected = array();

		foreach ( AllowList::TYPES as $type ) {
			if ( ! array_key_exists( $type, $input ) ) {
				continue;
			}

			$parsed                = AllowList::parse( $type, (string) $input[ $type ] );
			$all['allow'][ $type ] = array_slice( $parsed['entries'], 0, AllowList::limit() );
			if ( $parsed['rejected'] ) {
				$rejected[ $type ] = $parsed['rejected'];
			}
		}

		$this->store->set( $all );
		$this->saved( $all['allow'] );

		return $rejected;
	}

	/**
	 * Tells code the allow-list changed.
	 *
	 * @param array $allow The saved allow-list: rest, paths, ajax and users.
	 */
	private function saved( array $allow ): void {
		/**
		 * Fires after the allow-list is saved, from the settings screen, WP-CLI or a suggestion button.
		 *
		 * @param array $allow   The saved allow-list: rest, paths, ajax (strings) and users (IDs).
		 * @param bool  $network Whether it is the network's allow-list.
		 */
		do_action( 'axle_read_only_mode_allow_list_saved', $allow, $this->is_network() );
	}

	/**
	 * Adds one entry, for the suggestion buttons and WP-CLI.
	 *
	 * @param string $type  rest, paths, ajax or users.
	 * @param string $value Entry, as typed.
	 * @return string Empty on success, else why it was not added.
	 */
	public function add_allow( string $type, string $value ): string {
		$checked = AllowList::validate( $type, $value );
		if ( '' !== $checked['error'] ) {
			return $checked['error'];
		}

		$all     = $this->all();
		$current = $all['allow'][ $type ];
		if ( in_array( $checked['value'], $current, true ) ) {
			return '';
		}
		if ( count( $current ) >= AllowList::limit() ) {
			return __( 'The list is full.', 'axle-read-only-mode' );
		}

		$all['allow'][ $type ][] = $checked['value'];
		$this->store->set( $all );
		$this->saved( $all['allow'] );

		return '';
	}

	/**
	 * Removes one entry.
	 *
	 * @param string $type  rest, paths, ajax or users.
	 * @param string $value Entry, as typed.
	 * @return bool Whether it was in the list.
	 */
	public function remove_allow( string $type, string $value ): bool {
		$checked = AllowList::validate( $type, $value );
		$all     = $this->all();
		$current = $all['allow'][ $type ] ?? array();
		$kept    = array_values(
			array_filter(
				$current,
				static function ( $entry ) use ( $checked ): bool {
					return $entry !== $checked['value'];
				}
			)
		);

		if ( count( $kept ) === count( $current ) ) {
			return false;
		}

		$all['allow'][ $type ] = $kept;
		$this->store->set( $all );
		$this->saved( $all['allow'] );

		return true;
	}
}
