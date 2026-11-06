<?php
/**
 * Tests for the Site Health test and info section.
 *
 * @package AryanX\Axle\ReadOnlyMode
 */

namespace AryanX\Axle\ReadOnlyMode\Tests\Unit;

use AryanX\Axle\ReadOnlyMode\Health\SiteHealth;
use AryanX\Axle\ReadOnlyMode\Network;
use AryanX\Axle\ReadOnlyMode\Settings;
use AryanX\Axle\ReadOnlyMode\State;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class SiteHealthTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_date' )->justReturn( '6 Nov 2026 10:00' );
		Functions\when( 'human_time_diff' )->justReturn( '2 hours' );
		Functions\when( 'get_current_blog_id' )->justReturn( 2 );
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://example.test/wp-admin/' . $path );
		Functions\when( 'network_admin_url' )->alias( fn( $path = '' ) => 'https://example.test/wp-admin/network/' . $path );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'esc_url' )->returnArg();
		$this->options['date_format'] = 'j M Y';
		$this->options['time_format'] = 'H:i';
	}

	private function health( bool $network = false ): SiteHealth {
		$net = $network ? new Network() : null;

		return new SiteHealth( new State( null, $net ? $net->state : null ), $net, new Settings() );
	}

	private function freeze( array $changes ): void {
		$this->options[ State::OPTION ] = $changes + array( 'active' => true );
	}

	public function test_a_site_that_is_not_frozen_is_good(): void {
		$result = $this->health()->evaluate();

		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( 'off', $result['code'] );
	}

	public function test_a_freeze_set_for_later_is_still_good_and_says_when(): void {
		$this->freeze(
			array(
				'start' => time() + 3600,
				'end'   => time() + 7200,
			)
		);

		$result = $this->health()->evaluate();

		$this->assertSame( 'good', $result['status'] );
		$this->assertStringContainsString( 'scheduled to start on 6 Nov 2026 10:00', $result['description'] );
	}

	public function test_a_running_freeze_with_an_end_in_the_future_is_recommended(): void {
		$this->freeze(
			array(
				'start' => time() - 600,
				'end'   => time() + 3600,
			)
		);

		$result = $this->health()->evaluate();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'is on until 6 Nov 2026 10:00', $result['description'] );
	}

	public function test_a_freeze_just_past_its_end_is_not_yet_a_problem(): void {
		$this->freeze(
			array(
				'start' => time() - 7200,
				'end'   => time() - 10 * 60,
			)
		);

		$this->assertSame( 'good', $this->health()->evaluate()['status'], 'The next request records the end.' );
	}

	public function test_a_freeze_more_than_thirty_minutes_past_its_end_is_critical(): void {
		$this->freeze(
			array(
				'start' => time() - 7200,
				'end'   => time() - 31 * 60,
			)
		);

		$result = $this->health()->evaluate();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertSame( 'overdue', $result['code'] );
		$this->assertStringContainsString( 'should have ended on 6 Nov 2026 10:00, 2 hours ago', $result['description'] );
	}

	public function test_the_overdue_allowance_follows_a_filter_and_is_at_least_a_minute(): void {
		$this->freeze(
			array(
				'start' => time() - 7200,
				'end'   => time() - 10 * 60,
			)
		);
		Filters\expectApplied( 'axle_read_only_mode_health_overdue_minutes' )->andReturn( 5 );

		$this->assertSame( 'critical', $this->health()->evaluate()['status'] );

	}

	public function test_the_defaults_are_thirty_minutes_and_six_hours(): void {
		$this->assertSame( 30, SiteHealth::overdue_minutes() );
		$this->assertSame( 6, SiteHealth::open_ended_hours() );
	}

	public function test_a_freeze_without_an_end_is_recommended_at_first_and_critical_after_six_hours(): void {
		$this->freeze(
			array(
				'start' => time() - 5 * HOUR_IN_SECONDS,
				'end'   => 0,
			)
		);
		$this->assertSame( 'recommended', $this->health()->evaluate()['status'] );

		$this->freeze(
			array(
				'start' => time() - 7 * HOUR_IN_SECONDS,
				'end'   => 0,
			)
		);
		$result = $this->health()->evaluate();
		$this->assertSame( 'critical', $result['status'] );
		$this->assertSame( 'open_ended', $result['code'] );
	}

	public function test_the_open_ended_limit_follows_a_filter(): void {
		$this->freeze(
			array(
				'start' => time() - 2 * HOUR_IN_SECONDS,
				'end'   => 0,
			)
		);
		Filters\expectApplied( 'axle_read_only_mode_health_open_ended_hours' )->andReturn( 1 );

		$this->assertSame( 'critical', $this->health()->evaluate()['status'] );
	}

	public function test_a_network_freeze_that_covers_the_site_counts_the_same_way(): void {
		$this->site_options[ State::NETWORK_OPTION ] = array(
			'active' => true,
			'start'  => time() - 7 * HOUR_IN_SECONDS,
			'end'    => 0,
		);

		$result = $this->health( true )->evaluate();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( 'set by the network', $result['description'] );
	}

	public function test_a_network_freeze_for_other_sites_does_not_count(): void {
		$this->site_options[ State::NETWORK_OPTION ] = array(
			'active' => true,
			'start'  => time() - 7 * HOUR_IN_SECONDS,
			'end'    => 0,
			'scope'  => 'sites',
			'sites'  => array( 3 ),
		);

		$this->assertSame( 'good', $this->health( true )->evaluate()['status'] );
	}

	public function test_the_worst_of_the_site_and_the_network_wins(): void {
		$this->freeze(
			array(
				'start' => time() - 60,
				'end'   => time() + 600,
			)
		);
		$this->site_options[ State::NETWORK_OPTION ] = array(
			'active' => true,
			'start'  => time() - 7 * HOUR_IN_SECONDS,
			'end'    => 0,
		);

		$this->assertSame( 'critical', $this->health( true )->evaluate()['status'] );
	}

	public function test_the_result_is_a_site_health_result_with_a_colored_badge_and_a_link(): void {
		$this->freeze(
			array(
				'start' => time() - 60,
				'end'   => time() + 600,
			)
		);

		$result = $this->health()->test();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'orange', $result['badge']['color'] );
		$this->assertSame( SiteHealth::TEST, $result['test'] );
		$this->assertStringStartsWith( '<p>', $result['description'] );
		$this->assertStringContainsString( 'tools.php?page=axle-read-only-mode', $result['actions'] );

		$this->options = array();
		$good          = $this->health()->test();
		$this->assertSame( 'green', $good['badge']['color'] );
		$this->assertSame( '', $good['actions'] );
	}

	public function test_the_test_is_added_to_the_direct_tests_and_the_result_can_be_filtered(): void {
		$tests = $this->health()->add_test( array( 'direct' => array( 'other' => array() ) ) );
		$this->assertArrayHasKey( SiteHealth::TEST, $tests['direct'] );
		$this->assertArrayHasKey( 'other', $tests['direct'] );

		Filters\expectApplied( 'axle_read_only_mode_health_result' )->once()->with( \Mockery::type( 'array' ), 'off' )->andReturnUsing(
			function ( $result ) {
				$result['label'] = 'Changed';
				return $result;
			}
		);
		$this->assertSame( 'Changed', $this->health()->test()['label'] );
	}

	public function test_info_lists_the_state_end_time_allow_list_counts_and_email_settings(): void {
		$this->freeze(
			array(
				'start' => time() - 60,
				'end'   => time() + 600,
			)
		);
		$this->options[ Settings::OPTION ] = array(
			'allow'  => array(
				'rest'  => array( '/wc/v3/webhooks', '/a/b' ),
				'paths' => array( '?wc-api=' ),
				'users' => array( 4, 5, 6 ),
			),
			'notify' => array(
				'enabled'    => true,
				'recipients' => array( 'ops@example.test' ),
				'events'     => array( 'started', 'ended' ),
			),
		);

		$info   = $this->health()->add_info( array() );
		$fields = $info['axle-read-only-mode']['fields'];

		$this->assertSame( 'Frozen', $fields['state']['value'] );
		$this->assertSame( 'This site', $fields['source']['value'] );
		$this->assertSame( '6 Nov 2026 10:00', $fields['end']['value'] );
		$this->assertSame( '2 REST routes, 1 paths or query arguments, 0 ajax actions, 3 users', $fields['allow']['value'] );
		$this->assertSame( 'On, 1 recipients (started, ended)', $fields['notify']['value'] );
		$this->assertArrayNotHasKey( 'network', $fields );
	}

	public function test_info_for_a_site_that_is_not_frozen_and_has_default_settings(): void {
		$fields = $this->health()->add_info( array() )['axle-read-only-mode']['fields'];

		$this->assertSame( 'Not frozen', $fields['state']['value'] );
		$this->assertSame( 'Nothing', $fields['source']['value'] );
		$this->assertSame( 'Off', $fields['notify']['value'] );
	}

	public function test_info_on_a_network_adds_the_network_freeze(): void {
		$this->site_options[ State::NETWORK_OPTION ] = array(
			'active' => true,
			'start'  => time() - 60,
			'end'    => time() + 600,
			'scope'  => 'sites',
			'sites'  => array( 2, 3 ),
		);

		$fields = $this->health( true )->add_info( array() )['axle-read-only-mode']['fields'];

		$this->assertSame( 'Running', $fields['network']['value'] );
		$this->assertSame( '2 sites', $fields['network_covers']['value'] );
		$this->assertSame( 'The network', $fields['source']['value'] );
	}

	public function test_the_info_fields_can_be_filtered(): void {
		Filters\expectApplied( 'axle_read_only_mode_health_info' )->once()->andReturn( array( 'only' => array() ) );

		$this->assertSame( array( 'only' => array() ), $this->health()->add_info( array() )['axle-read-only-mode']['fields'] );
	}

	/**
	 * A constant cannot be undefined, so this runs in its own process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_wp_config_constant_is_critical_whatever_else_is_set(): void {
		define( 'AXLE_READ_ONLY_MODE', true );
		$this->freeze(
			array(
				'start' => time() - 60,
				'end'   => time() + 600,
			)
		);

		$result = $this->health()->evaluate();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertSame( 'constant', $result['code'] );
		$this->assertStringContainsString( 'AXLE_READ_ONLY_MODE', $result['description'] );
		$this->assertSame( 'wp-config.php constant', $this->health()->add_info( array() )['axle-read-only-mode']['fields']['source']['value'] );
		$this->assertStringNotContainsString( 'tools.php', $this->health()->test()['actions'], 'Nothing on the screen can lift it, so no link.' );
	}
}
