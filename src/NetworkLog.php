<?php
/**
 * The network's view over every site's blocked attempts.
 *
 * @package AryanX\Axle\ReadOnlyMode
 */

namespace AryanX\Axle\ReadOnlyMode;

defined( 'ABSPATH' ) || exit;

/**
 * Each site keeps its own log of what its freeze blocked. A network freeze
 * leaves those logs where they are, and this class reads them from the start
 * of the network freeze: per site, and as one list.
 */
final class NetworkLog implements LogCounter {

	/**
	 * The network's freeze.
	 *
	 * @var State
	 */
	private State $state;

	/**
	 * Sets up the view.
	 *
	 * @param State $state The network's freeze.
	 */
	public function __construct( State $state ) {
		$this->state = $state;
	}

	/**
	 * Does nothing: site logs are not emptied by a network freeze.
	 */
	public function clear(): void {}

	/**
	 * Attempts blocked on every site during the current or last network freeze.
	 *
	 * @return int
	 */
	public function count(): int {
		return array_sum( array_column( $this->sites(), 'blocked' ) );
	}

	/**
	 * Most sites the network view reads.
	 *
	 * @return int
	 */
	public function site_limit(): int {
		/**
		 * Filters how many sites the network log reads.
		 *
		 * Each site is one option read, so a large network is capped. Sites are
		 * read most recently active first.
		 *
		 * @param int $limit Default 100.
		 */
		return max( 1, (int) apply_filters( 'axle_read_only_mode_network_log_sites', 100 ) );
	}

	/**
	 * Sites with attempts since the network freeze started, busiest first.
	 *
	 * @return array[] Each: site_id, name, url, blocked, entries (newest first, blocked and allowed).
	 */
	public function sites(): array {
		$since = (int) $this->state->own_value( 'start' );
		$ids   = get_sites(
			array(
				'fields'  => 'ids',
				'number'  => $this->site_limit(),
				'orderby' => 'last_updated',
				'order'   => 'DESC',
			)
		);
		$rows  = array();

		foreach ( (array) $ids as $site_id ) {
			$site_id = (int) $site_id;
			if ( ! $this->state->covers( $site_id ) ) {
				continue;
			}

			$log = get_blog_option( $site_id, BlockedLog::OPTION, array() );
			$log = is_array( $log ) ? array_values(
				array_filter(
					$log,
					static function ( $entry ) use ( $since ): bool {
						return is_array( $entry ) && (int) ( $entry['time'] ?? 0 ) >= $since;
					}
				)
			) : array();
			if ( ! $log ) {
				continue;
			}

			$rows[] = array(
				'site_id' => $site_id,
				'name'    => (string) get_blog_option( $site_id, 'blogname', '' ),
				'url'     => (string) get_home_url( $site_id, '/' ),
				'blocked' => count(
					array_filter(
						$log,
						static function ( array $entry ): bool {
							return empty( $entry['allowed'] );
						}
					)
				),
				'entries' => $log,
			);
		}

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return $b['blocked'] <=> $a['blocked'];
			}
		);

		return $rows;
	}

	/**
	 * The newest entries across sites, each with its site.
	 *
	 * @param int $limit Entries to return.
	 * @return array[] Log entries plus site_id, name.
	 */
	public function recent( int $limit = 50 ): array {
		$all = array();
		foreach ( $this->sites() as $site ) {
			foreach ( $site['entries'] as $entry ) {
				$all[] = $entry + array(
					'site_id' => $site['site_id'],
					'name'    => $site['name'],
				);
			}
		}

		usort(
			$all,
			static function ( array $a, array $b ): int {
				return (int) ( $b['time'] ?? 0 ) <=> (int) ( $a['time'] ?? 0 );
			}
		);

		return array_slice( $all, 0, max( 1, $limit ) );
	}
}
