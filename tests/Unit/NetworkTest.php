<?php
/**
 * Tests for network freezes on multisite.
 *
 * @package AryanX\Axle\ReadOnlyMode
 */

namespace AryanX\Axle\ReadOnlyMode\Tests\Unit;

use AryanX\Axle\ReadOnlyMode\BlockedLog;
use AryanX\Axle\ReadOnlyMode\History;
use AryanX\Axle\ReadOnlyMode\Network;
use AryanX\Axle\ReadOnlyMode\NetworkLog;
use AryanX\Axle\ReadOnlyMode\State;
use AryanX\Axle\ReadOnlyMode\Store;
use AryanX\Axle\ReadOnlyMode\Admin\NetworkPage;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class NetworkTest extends TestCase {

	/**
	 * Site the code thinks it runs on.
	 *
	 * @var int
	 */
	private int $blog = 2;

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_current_blog_id' )->alias( fn() => $this->blog );
		Functions\when( 'get_current_user_id' )->justReturn( 9 );
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'wp_date' )->justReturn( '14:30' );
		Functions\when( 'get_sites' )->justReturn( array() );
		$this->options['time_format'] = 'H:i';
	}

	private function network_state(): State {
		return new State( new Store( State::NETWORK_OPTION, true ) );
	}

	private function site_state(): State {
		return new State( null, $this->network_state() );
	}

	private function freeze_network( array $changes = array() ): void {
		$this->site_options[ State::NETWORK_OPTION ] = $changes + array(
			'active' => true,
			'start'  => time() - 60,
			'end'    => time() + 600,
			'reason' => 'Moving servers',
		);
	}

	public function test_a_network_freeze_freezes_every_site(): void {
		$this->freeze_network();

		$state = $this->site_state();

		$this->assertTrue( $state->is_frozen() );
		$this->assertTrue( $state->network_applies() );
		$this->assertSame( 'network', $state->source() );
		$this->assertSame( 'Moving servers', $state->get( 'reason' ) );
	}

	public function test_a_site_that_unfroze_itself_stays_frozen_by_the_network(): void {
		$this->options[ State::OPTION ] = array(
			'active' => false,
			'start'  => time() - 7200,
		);
		$this->freeze_network();

		$state = $this->site_state();

		$this->assertFalse( $state->is_running(), 'The site itself is not frozen.' );
		$this->assertTrue( $state->is_frozen() );
	}

	public function test_a_freeze_for_chosen_sites_covers_only_those(): void {
		$this->freeze_network(
			array(
				'scope' => 'sites',
				'sites' => array( 3, 4 ),
			)
		);

		$this->blog = 2;
		$this->assertFalse( $this->site_state()->is_frozen() );

		$this->blog = 4;
		$this->assertTrue( $this->site_state()->is_frozen() );
	}

	public function test_a_site_freeze_and_the_network_freeze_each_keep_their_own_record(): void {
		$this->options[ State::OPTION ] = array(
			'active' => true,
			'start'  => time() - 60,
			'end'    => time() + 60,
			'reason' => 'Site deploy',
		);
		$this->freeze_network();

		$state = $this->site_state();

		$this->assertSame( 'Site deploy', $state->own()['reason'] );
		$this->assertSame( 'Moving servers', $state->all()['reason'], 'The network freeze is the one in force.' );

		$state->update( array( 'reason' => 'Changed' ) );
		$this->assertSame( 'Changed', $this->options[ State::OPTION ]['reason'] );
		$this->assertSame( 'Moving servers', $this->site_options[ State::NETWORK_OPTION ]['reason'] );
	}

	public function test_an_ended_network_freeze_frees_the_site_again(): void {
		$this->freeze_network( array( 'end' => time() - 5 ) );

		$this->assertFalse( $this->site_state()->is_frozen() );
		$this->assertSame( '', $this->site_state()->source() );
	}

	public function test_the_message_says_the_network_froze_the_site(): void {
		$this->freeze_network();

		$message = $this->site_state()->message();

		$this->assertStringContainsString( 'frozen by the network for maintenance until 14:30', $message );
		$this->assertStringContainsString( 'Reason: Moving servers', $message );
	}

	public function test_exempt_network_users_bypass_on_every_site(): void {
		$this->freeze_network( array( 'exempt' => array( 9 ) ) );
		$this->options[ State::OPTION ] = array(
			'active' => true,
			'start'  => time() - 60,
			'exempt' => array( 1 ),
		);

		$this->assertTrue( $this->site_state()->can_bypass(), 'The network list applies, not the site list.' );
	}

	public function test_a_network_freeze_is_saved_as_a_network_option_and_announced(): void {
		$network = new Network();

		Actions\expectDone( 'axle_read_only_mode_network_started' )->once();
		$network->freezer->freeze(
			array(
				'minutes' => 30,
				'reason'  => 'Moving servers',
				'scope'   => 'sites',
				'sites'   => array( '3', 4, 4, 0 ),
			)
		);

		$saved = $this->site_options[ State::NETWORK_OPTION ];
		$this->assertTrue( $saved['active'] );
		$this->assertSame( 'sites', $saved['scope'] );
		$this->assertSame( array( 3, 4 ), $saved['sites'] );
		$this->assertSame( 9, $saved['by'] );
		$this->assertArrayNotHasKey( State::OPTION, $this->options, 'Nothing is written to the site.' );
	}

	public function test_a_scope_of_chosen_sites_without_sites_falls_back_to_every_site(): void {
		$network = new Network();

		$network->freezer->freeze( array( 'scope' => 'sites' ) );

		$this->assertSame( 'all', $this->site_options[ State::NETWORK_OPTION ]['scope'] );
	}

	public function test_extending_and_ending_a_network_freeze_use_the_network_actions_and_history(): void {
		$network = new Network();
		$this->freeze_network( array( 'by' => 9 ) );
		$end = $this->site_options[ State::NETWORK_OPTION ]['end'];

		Actions\expectDone( 'axle_read_only_mode_network_extended' )->once();
		$this->assertTrue( $network->freezer->extend( 30 ) );
		$this->assertSame( $end + 1800, $this->site_options[ State::NETWORK_OPTION ]['end'] );

		Actions\expectDone( 'axle_read_only_mode_network_ended' )->once()->with( \Mockery::type( 'array' ), 'cli' );
		$network->freezer->unfreeze( 'cli' );

		$this->assertFalse( $this->site_options[ State::NETWORK_OPTION ]['active'] );
		$entry = $this->site_options[ History::NETWORK_OPTION ][0];
		$this->assertSame( 'cli', $entry['ended_by'] );
		$this->assertSame( 0, $entry['sites'], 'Zero means every site.' );
		$this->assertArrayNotHasKey( History::OPTION, $this->options );
	}

	public function test_a_network_freeze_that_ran_out_is_recorded_once(): void {
		$network = new Network();
		$this->freeze_network(
			array(
				'start' => time() - 7200,
				'end'   => time() - 60,
			)
		);

		$network->freezer->expire_if_needed();
		$this->site_options[ State::NETWORK_OPTION ]['active'] = true;
		$network->freezer->expire_if_needed();

		$this->assertCount( 1, $this->site_options[ History::NETWORK_OPTION ] );
		$this->assertSame( 'expired', $this->site_options[ History::NETWORK_OPTION ][0]['ended_by'] );
	}

	public function test_starting_a_network_freeze_leaves_site_logs_alone(): void {
		$this->options[ BlockedLog::OPTION ] = array( array( 'time' => 1 ) );

		( new Network() )->freezer->freeze( array( 'minutes' => 30 ) );

		$this->assertArrayHasKey( BlockedLog::OPTION, $this->options );
	}

	public function test_a_sites_log_is_read_from_the_start_of_the_network_freeze(): void {
		$start = time() - 600;
		$this->freeze_network( array( 'start' => $start ) );
		$this->options[ BlockedLog::OPTION ] = array(
			array(
				'time'  => time() - 10,
				'where' => 'new',
			),
			array(
				'time'  => $start - 5000,
				'where' => 'from an older freeze',
			),
		);
		$state = $this->site_state();
		$log   = new BlockedLog( new History() );

		$this->assertSame( $start, $state->log_since() );
		$this->assertSame( 1, $log->count( $state->log_since() ) );
		$this->assertSame( 2, $log->count() );
	}

	public function test_old_entries_do_not_use_up_the_visitor_limit_of_a_new_freeze(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		$start                               = time() - 60;
		$this->options[ BlockedLog::OPTION ] = array_fill(
			0,
			10,
			array(
				'time'  => $start - 5000,
				'user'  => 0,
				'where' => 'old',
			)
		);

		( new BlockedLog( new History() ) )->add( 'POST /wp-comments-post.php', 'front-end', $start );

		$this->assertSame( 'POST /wp-comments-post.php', $this->options[ BlockedLog::OPTION ][0]['where'] );
	}

	public function test_the_network_log_collects_every_covered_site_since_the_freeze_began(): void {
		$start = time() - 600;
		$this->freeze_network(
			array(
				'start' => $start,
				'scope' => 'sites',
				'sites' => array( 2, 3 ),
			)
		);
		$logs  = array(
			2 => array(
				array(
					'time'   => time() - 20,
					'user'   => 1,
					'where'  => 'REST POST /wp/v2/posts/1',
					'source' => 'rest',
				),
				array(
					'time'    => time() - 15,
					'user'    => 0,
					'where'   => 'POST /hook',
					'source'  => 'front-end',
					'allowed' => true,
				),
			),
			3 => array(
				array(
					'time'   => $start - 1,
					'where'  => 'before the freeze',
					'source' => 'admin',
				),
			),
			4 => array(
				array(
					'time'   => time() - 5,
					'where'  => 'site not covered',
					'source' => 'admin',
				),
			),
		);
		Functions\when( 'get_sites' )->justReturn( array( 2, 3, 4 ) );
		Functions\when( 'get_blog_option' )->alias(
			function ( $id, $name ) use ( $logs ) {
				return BlockedLog::OPTION === $name ? ( $logs[ $id ] ?? array() ) : 'Site ' . $id;
			}
		);
		Functions\when( 'get_home_url' )->alias( fn( $id ) => 'https://example.test/site' . $id . '/' );
		$network_log = new NetworkLog( $this->network_state() );

		$sites = $network_log->sites();

		$this->assertCount( 1, $sites, 'Site 3 has nothing since the start, site 4 is not covered.' );
		$this->assertSame( 2, $sites[0]['site_id'] );
		$this->assertSame( 1, $sites[0]['blocked'], 'An allowed request is not a blocked attempt.' );
		$this->assertSame( 1, $network_log->count() );
		$this->assertSame( 'Site 2', $network_log->recent()[0]['name'] );
		$this->assertCount( 2, $network_log->recent() );
	}

	public function test_a_single_site_has_no_network_state(): void {
		$state = new State();

		$this->assertFalse( $state->network_applies() );
		$this->assertSame( '', $state->source() );
	}

	public function test_the_network_screen_capability_and_limits_go_through_filters(): void {
		$this->assertSame( 'manage_network_options', NetworkPage::capability() );
		$this->assertSame( 200, NetworkPage::site_picker_limit() );
		$this->assertSame( 100, ( new NetworkLog( $this->network_state() ) )->site_limit() );

		Filters\expectApplied( 'axle_read_only_mode_network_capability' )->once()->andReturn( 'manage_sites' );
		Filters\expectApplied( 'axle_read_only_mode_network_site_picker_limit' )->once()->andReturn( 0 );
		Filters\expectApplied( 'axle_read_only_mode_network_log_sites' )->once()->andReturn( 25 );

		$this->assertSame( 'manage_sites', NetworkPage::capability() );
		$this->assertSame( 1, NetworkPage::site_picker_limit(), 'Never less than one site.' );
		$this->assertSame( 25, ( new NetworkLog( $this->network_state() ) )->site_limit() );
	}
}
