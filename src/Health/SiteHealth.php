<?php
/**
 * Site Health test and info section.
 *
 * @package AryanX\Axle\ReadOnlyMode
 */

namespace AryanX\Axle\ReadOnlyMode\Health;

use AryanX\Axle\ReadOnlyMode\Admin\NetworkPage;
use AryanX\Axle\ReadOnlyMode\Admin\ToolsPage;
use AryanX\Axle\ReadOnlyMode\Network;
use AryanX\Axle\ReadOnlyMode\Scopes;
use AryanX\Axle\ReadOnlyMode\Service;
use AryanX\Axle\ReadOnlyMode\Settings;
use AryanX\Axle\ReadOnlyMode\State;

defined( 'ABSPATH' ) || exit;

/**
 * Tools > Site Health: good when the site is not frozen, recommended while a
 * freeze with an end time is running, critical when a freeze looks forgotten.
 *
 * A freeze looks forgotten when it ran past its planned end, when it has no
 * end time and has lasted hours, or when wp-config.php forces it on, because
 * nobody can lift that one from wp-admin.
 */
final class SiteHealth implements Service {

	public const TEST = 'axle_read_only_mode';

	/**
	 * Saved freeze.
	 *
	 * @var State
	 */
	private State $state;

	/**
	 * The network's freeze, on multisite.
	 *
	 * @var Network|null
	 */
	private ?Network $network;

	/**
	 * This site's settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Sets up the checks.
	 *
	 * @param State        $state    Saved freeze.
	 * @param Network|null $network  The network's freeze, on multisite.
	 * @param Settings     $settings This site's settings.
	 */
	public function __construct( State $state, ?Network $network, Settings $settings ) {
		$this->state    = $state;
		$this->network  = $network;
		$this->settings = $settings;
	}

	/**
	 * Adds the test and the info section.
	 */
	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'add_test' ) );
		add_filter( 'debug_information', array( $this, 'add_info' ) );
	}

	/**
	 * Adds the direct test.
	 *
	 * @param array $tests Site Health tests.
	 * @return array
	 */
	public function add_test( $tests ) {
		$tests['direct'][ self::TEST ] = array(
			'label' => __( 'Read-only mode', 'axle-read-only-mode' ),
			'test'  => array( $this, 'test' ),
		);

		return $tests;
	}

	/**
	 * Minutes a freeze can run past its end before it counts as stuck.
	 *
	 * @return int
	 */
	public static function overdue_minutes(): int {
		/**
		 * Filters how many minutes a freeze can run past its planned end before Site Health calls it critical.
		 *
		 * A freeze ends on the first request after its end time, so a freeze
		 * that is still on well after that points at something stuck, such as
		 * a filter or a cache holding the site frozen.
		 *
		 * @param int $minutes Default 30.
		 */
		return max( 1, (int) apply_filters( 'axle_read_only_mode_health_overdue_minutes', 30 ) );
	}

	/**
	 * Hours a freeze with no end time can last before Site Health calls it critical.
	 *
	 * @return int
	 */
	public static function open_ended_hours(): int {
		/**
		 * Filters how many hours a freeze with no end time can last before Site Health calls it critical.
		 *
		 * @param int $hours Default 6.
		 */
		return max( 1, (int) apply_filters( 'axle_read_only_mode_health_open_ended_hours', 6 ) );
	}

	/**
	 * Works out the status.
	 *
	 * @return array{status: string, code: string, label: string, description: string}
	 */
	public function evaluate(): array {
		if ( $this->state->forced_by_constant() ) {
			return array(
				'status'      => 'critical',
				'code'        => 'constant',
				'label'       => __( 'wp-config.php forces the site read-only', 'axle-read-only-mode' ),
				'description' => __( 'The AXLE_READ_ONLY_MODE constant in wp-config.php freezes the site with no end time, and nobody can lift it from wp-admin. Remove it from wp-config.php to unfreeze.', 'axle-read-only-mode' ),
			);
		}

		$now      = time();
		$format   = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$overdue  = self::overdue_minutes() * MINUTE_IN_SECONDS;
		$open     = self::open_ended_hours() * HOUR_IN_SECONDS;
		$records  = array( array( 'site', $this->state->own() ) );
		$result   = null;
		$schedule = '';

		if ( $this->network && $this->network->state->covers( get_current_blog_id() ) ) {
			array_unshift( $records, array( 'network', $this->network->state->own() ) );
		}

		foreach ( $records as $record ) {
			list( $source, $freeze ) = $record;
			$who                     = 'network' === $source ? __( 'the network', 'axle-read-only-mode' ) : __( 'this site', 'axle-read-only-mode' );

			if ( empty( $freeze['active'] ) ) {
				continue;
			}

			$start = (int) $freeze['start'];
			$end   = (int) $freeze['end'];

			if ( $start > $now ) {
				/* translators: %s: date and time a scheduled freeze starts. */
				$schedule = sprintf( __( 'A freeze is scheduled to start on %s.', 'axle-read-only-mode' ), wp_date( $format, $start ) );
				continue;
			}

			if ( $end && $now > $end + $overdue ) {
				$found = array(
					'status'      => 'critical',
					'code'        => 'overdue',
					'label'       => __( 'A freeze ran past its end time', 'axle-read-only-mode' ),
					'description' => sprintf(
						/* translators: 1: "the network" or "this site", 2: date and time the freeze should have ended, 3: how long ago. */
						__( 'The freeze set by %1$s should have ended on %2$s, %3$s ago, but the site is still frozen. A freeze ends on the first request after its end time, so something may be holding it on, such as a filter or a stuck cache. Unfreeze the site, then look for it.', 'axle-read-only-mode' ),
						$who,
						wp_date( $format, $end ),
						human_time_diff( $end, $now )
					),
				);
			} elseif ( ! $end && $now - $start > $open ) {
				$found = array(
					'status'      => 'critical',
					'code'        => 'open_ended',
					'label'       => __( 'A freeze with no end time has been on for hours', 'axle-read-only-mode' ),
					'description' => sprintf(
						/* translators: 1: "the network" or "this site", 2: how long the freeze has lasted. */
						__( 'The freeze set by %1$s has no end time and has lasted %2$s. Nothing can be saved until someone unfreezes the site. If it is over, unfreeze it.', 'axle-read-only-mode' ),
						$who,
						human_time_diff( $start, $now )
					),
				);
			} elseif ( ! $end || $now < $end ) {
				$found = array(
					'status'      => 'recommended',
					'code'        => 'frozen',
					'label'       => __( 'The site is read-only', 'axle-read-only-mode' ),
					'description' => $end
						? sprintf(
							/* translators: 1: "the network" or "this site", 2: date and time the freeze ends. */
							__( 'The freeze set by %1$s is on until %2$s. Nothing can be saved until then. This is expected during a migration or deploy.', 'axle-read-only-mode' ),
							$who,
							wp_date( $format, $end )
						)
						: sprintf(
							/* translators: %s: "the network" or "this site". */
							__( 'The freeze set by %s has no end time. Nothing can be saved until someone unfreezes the site.', 'axle-read-only-mode' ),
							$who
						),
				);
			} else {
				// Past its end, but inside the allowance: the next request records the end.
				continue;
			}

			if ( null === $result || self::rank( $found['status'] ) > self::rank( $result['status'] ) ) {
				$result = $found;
			}
		}

		if ( $result ) {
			return $result;
		}

		return array(
			'status'      => 'good',
			'code'        => 'off',
			'label'       => __( 'The site is not read-only', 'axle-read-only-mode' ),
			'description' => trim( __( 'Nothing is frozen, so changes can be saved.', 'axle-read-only-mode' ) . ' ' . $schedule ),
		);
	}

	/**
	 * Orders the statuses.
	 *
	 * @param string $status good, recommended or critical.
	 * @return int
	 */
	private static function rank( string $status ): int {
		return array_search( $status, array( 'good', 'recommended', 'critical' ), true );
	}

	/**
	 * The Site Health result.
	 *
	 * @return array
	 */
	public function test(): array {
		$checked = $this->evaluate();
		$colors  = array(
			'good'        => 'green',
			'recommended' => 'orange',
			'critical'    => 'red',
		);
		$result  = array(
			'label'       => $checked['label'],
			'status'      => $checked['status'],
			'badge'       => array(
				'label' => __( 'Maintenance', 'axle-read-only-mode' ),
				'color' => $colors[ $checked['status'] ],
			),
			'description' => '<p>' . esc_html( $checked['description'] ) . '</p>',
			'actions'     => '',
			'test'        => self::TEST,
		);

		if ( 'good' !== $checked['status'] && 'constant' !== $checked['code'] ) {
			$result['actions'] = sprintf( '<p><a href="%1$s">%2$s</a></p>', esc_url( ToolsPage::page_url() ), esc_html__( 'Open Read-Only Mode', 'axle-read-only-mode' ) );
		}
		if ( $this->network && 'good' !== $checked['status'] && current_user_can( NetworkPage::capability() ) ) {
			$result['actions'] .= sprintf( '<p><a href="%1$s">%2$s</a></p>', esc_url( NetworkPage::page_url() ), esc_html__( 'Open Read-Only Mode in Network Admin', 'axle-read-only-mode' ) );
		}

		/**
		 * Filters the Site Health result for read-only mode.
		 *
		 * @param array  $result  The Site Health result: label, status, badge, description, actions and test.
		 * @param string $code    off, frozen, overdue, open_ended or constant.
		 */
		return (array) apply_filters( 'axle_read_only_mode_health_result', $result, $checked['code'] );
	}

	/**
	 * Adds the Info section.
	 *
	 * @param array $info Site Health info.
	 * @return array
	 */
	public function add_info( $info ) {
		$info['axle-read-only-mode'] = array(
			'label'  => __( 'Axle Read-Only Mode', 'axle-read-only-mode' ),
			'fields' => $this->fields(),
		);

		return $info;
	}

	/**
	 * The Info fields: state, end time, allow-list counts and email settings.
	 *
	 * @return array
	 */
	private function fields(): array {
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$source = $this->state->source();
		$frozen = $this->state->is_frozen();
		$freeze = $this->state->all();
		$counts = $this->state->allow_list()->counts();
		$notify = $this->settings->notify();
		$labels = array(
			'constant' => __( 'wp-config.php constant', 'axle-read-only-mode' ),
			'network'  => __( 'The network', 'axle-read-only-mode' ),
			'site'     => __( 'This site', 'axle-read-only-mode' ),
		);

		$fields = array(
			'state'  => array(
				'label' => __( 'State', 'axle-read-only-mode' ),
				'value' => $frozen ? __( 'Frozen', 'axle-read-only-mode' ) : ( $this->state->is_scheduled() ? __( 'Scheduled', 'axle-read-only-mode' ) : __( 'Not frozen', 'axle-read-only-mode' ) ),
				'debug' => $frozen ? 'frozen' : 'not frozen',
			),
			'source' => array(
				'label' => __( 'Frozen by', 'axle-read-only-mode' ),
				'value' => $labels[ $source ] ?? __( 'Nothing', 'axle-read-only-mode' ),
				'debug' => '' !== $source ? $source : 'none',
			),
			'scope'  => array(
				'label' => __( 'Scope', 'axle-read-only-mode' ),
				'value' => $frozen ? Scopes::label( $this->state->scope() ) : __( 'Not applicable', 'axle-read-only-mode' ),
				'debug' => $frozen ? $this->state->scope() : 'none',
			),
			'start'  => array(
				'label' => __( 'Started', 'axle-read-only-mode' ),
				'value' => $frozen && $freeze['start'] ? wp_date( $format, (int) $freeze['start'] ) : __( 'Not applicable', 'axle-read-only-mode' ),
				'debug' => $frozen ? (int) $freeze['start'] : 0,
			),
			'end'    => array(
				'label' => __( 'End time', 'axle-read-only-mode' ),
				'value' => $frozen ? ( $freeze['end'] && 'constant' !== $source ? wp_date( $format, (int) $freeze['end'] ) : __( 'None: lasts until someone unfreezes', 'axle-read-only-mode' ) ) : __( 'Not applicable', 'axle-read-only-mode' ),
				'debug' => $frozen ? (int) $freeze['end'] : 0,
			),
			'allow'  => array(
				'label' => __( 'Allow-list', 'axle-read-only-mode' ),
				'value' => sprintf(
					/* translators: 1: REST routes, 2: paths and query arguments, 3: ajax actions, 4: users. */
					__( '%1$d REST routes, %2$d paths or query arguments, %3$d ajax actions, %4$d users', 'axle-read-only-mode' ),
					$counts['rest'],
					$counts['paths'],
					$counts['ajax'],
					$counts['users']
				),
				'debug' => $counts,
			),
			'notify' => array(
				'label' => __( 'Email notifications', 'axle-read-only-mode' ),
				'value' => $notify['enabled']
					? sprintf(
						/* translators: 1: number of recipients, 2: events that send an email. */
						__( 'On, %1$d recipients (%2$s)', 'axle-read-only-mode' ),
						count( $notify['recipients'] ),
						implode( ', ', $notify['events'] )
					)
					: __( 'Off', 'axle-read-only-mode' ),
				'debug' => $notify['enabled'] ? 'on' : 'off',
			),
		);

		if ( $this->network ) {
			$network_state     = $this->network->state->own();
			$fields['network'] = array(
				'label' => __( 'Network freeze', 'axle-read-only-mode' ),
				'value' => $this->network->state->is_running() ? __( 'Running', 'axle-read-only-mode' ) : ( $this->network->state->is_scheduled() ? __( 'Scheduled', 'axle-read-only-mode' ) : __( 'Not frozen', 'axle-read-only-mode' ) ),
				'debug' => $this->network->state->is_running() ? 'running' : 'not frozen',
			);
			if ( $this->network->state->is_running() ) {
				$fields['network_covers'] = array(
					'label' => __( 'Network freeze covers', 'axle-read-only-mode' ),
					'value' => 'sites' === ( $network_state['scope'] ?? 'all' )
						/* translators: %d: number of sites. */
						? sprintf( _n( '%d site', '%d sites', count( (array) ( $network_state['sites'] ?? array() ) ), 'axle-read-only-mode' ), count( (array) ( $network_state['sites'] ?? array() ) ) )
						: __( 'Every site', 'axle-read-only-mode' ),
					'debug' => (string) ( $network_state['scope'] ?? 'all' ),
				);
			}
		}

		/**
		 * Filters the fields of the Axle Read-Only Mode section in Site Health > Info.
		 *
		 * @param array $fields Fields, each with label, value and debug.
		 */
		return (array) apply_filters( 'axle_read_only_mode_health_info', $fields );
	}
}
