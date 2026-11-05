<?php
/**
 * One saved value: a site option, or a network (site) option.
 *
 * @package AryanX\Axle\ReadOnlyMode
 */

namespace AryanX\Axle\ReadOnlyMode;

defined( 'ABSPATH' ) || exit;

/**
 * Lets State, History and Settings keep their data per site or for the whole
 * network without caring which. On a single site both are the same option.
 */
final class Store {

	/**
	 * Option name.
	 *
	 * @var string
	 */
	private string $option;

	/**
	 * Whether this is a network option.
	 *
	 * @var bool
	 */
	private bool $network;

	/**
	 * Whether the option loads on every request.
	 *
	 * @var bool
	 */
	private bool $autoload;

	/**
	 * Sets up the store.
	 *
	 * @param string $option   Option name.
	 * @param bool   $network  Keep it as a network option.
	 * @param bool   $autoload Load it with every request. Only site options autoload.
	 */
	public function __construct( string $option, bool $network = false, bool $autoload = true ) {
		$this->option   = $option;
		$this->network  = $network;
		$this->autoload = $autoload;
	}

	/**
	 * Whether this value belongs to the network.
	 *
	 * @return bool
	 */
	public function is_network(): bool {
		return $this->network;
	}

	/**
	 * Option name.
	 *
	 * @return string
	 */
	public function option(): string {
		return $this->option;
	}

	/**
	 * Saved value.
	 *
	 * @param mixed $fallback Returned when nothing is saved.
	 * @return mixed
	 */
	public function get( $fallback = array() ) {
		return $this->network ? get_site_option( $this->option, $fallback ) : get_option( $this->option, $fallback );
	}

	/**
	 * Saves the value.
	 *
	 * @param mixed $value Value.
	 */
	public function set( $value ): void {
		if ( $this->network ) {
			update_site_option( $this->option, $value );
			return;
		}

		update_option( $this->option, $value, $this->autoload );
	}

	/**
	 * Removes the value.
	 */
	public function delete(): void {
		if ( $this->network ) {
			delete_site_option( $this->option );
			return;
		}

		delete_option( $this->option );
	}
}
