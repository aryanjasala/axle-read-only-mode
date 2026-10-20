<?php
/**
 * Tests for freeze timing, messages, extending and history.
 *
 * @package AryanX\DeployFreeze
 */

namespace AryanX\DeployFreeze\Tests\Unit;

use AryanX\DeployFreeze\BlockedLog;
use AryanX\DeployFreeze\Freezer;
use AryanX\DeployFreeze\History;
use AryanX\DeployFreeze\State;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class FreezeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'wp_date' )->justReturn( '14:30' );
		$this->options['time_format'] = 'H:i';
	}

	private function freezer(): Freezer {
		return new Freezer( new State(), new BlockedLog(), new History() );
	}

	private function store( array $state ): void {
		$this->options[ State::OPTION ] = $state + array( 'active' => true );
	}

	public function test_nothing_is_frozen_by_default(): void {
		$state = new State();

		$this->assertFalse( $state->is_frozen() );
		$this->assertFalse( $state->is_scheduled() );
	}

	public function test_a_running_freeze_is_frozen_until_its_end(): void {
		$this->store(
			array(
				'start' => time() - 60,
				'end'   => time() + 600,
			)
		);
		$state = new State();

		$this->assertTrue( $state->is_frozen() );
		$this->assertEqualsWithDelta( 600, $state->remaining(), 2 );
	}

	public function test_a_freeze_past_its_end_is_over_but_not_yet_recorded(): void {
		$this->store(
			array(
				'start' => time() - 7200,
				'end'   => time() - 3600,
			)
		);
		$state = new State();

		$this->assertFalse( $state->is_frozen() );
		$this->assertTrue( $state->has_expired() );
	}

	public function test_a_freeze_without_an_end_lasts_until_unfrozen(): void {
		$this->store(
			array(
				'start' => time() - 86400,
				'end'   => 0,
			)
		);
		$state = new State();

		$this->assertTrue( $state->is_frozen() );
		$this->assertFalse( $state->has_expired() );
		$this->assertSame( 0, $state->remaining() );
	}

	public function test_a_future_freeze_is_scheduled_not_frozen(): void {
		$this->store(
			array(
				'start' => time() + 3600,
				'end'   => time() + 7200,
			)
		);
		$state = new State();

		$this->assertTrue( $state->is_scheduled() );
		$this->assertFalse( $state->is_frozen() );
	}

	public function test_messages_include_the_end_time_and_reason(): void {
		$this->store(
			array(
				'start'  => time(),
				'end'    => time() + 600,
				'reason' => 'Moving servers',
			)
		);
		$state = new State();

		$this->assertSame( 'This site is frozen for maintenance until 14:30. Changes cannot be saved right now. Reason: Moving servers', $state->message() );
		$this->assertStringContainsString( 'try again after 14:30', $state->visitor_message() );

		$this->store( array( 'visitor_message' => 'Back by 3 pm.' ) );
		$this->assertSame( 'Back by 3 pm.', ( new State() )->visitor_message() );
	}

	public function test_only_exempt_users_bypass_and_the_filter_can_change_that(): void {
		$this->store( array( 'exempt' => array( 5 ) ) );
		$this->assertTrue( ( new State() )->can_bypass() );

		Filters\expectApplied( 'deploy_freeze_user_can_bypass' )->once()->with( true, 5 )->andReturn( false );
		$this->assertFalse( ( new State() )->can_bypass() );
	}

	public function test_freezing_sets_the_window_and_clears_the_old_blocked_log(): void {
		$this->options[ BlockedLog::OPTION ] = array( array( 'time' => 1 ) );
		$start                               = time() + 60;

		$this->freezer()->freeze(
			array(
				'start'   => $start,
				'minutes' => 30,
				'reason'  => ' DB swap ',
			)
		);

		$state = $this->options[ State::OPTION ];
		$this->assertTrue( $state['active'] );
		$this->assertSame( $start + 1800, $state['end'] );
		$this->assertSame( 'DB swap', $state['reason'] );
		$this->assertSame( 5, $state['by'] );
		$this->assertArrayNotHasKey( BlockedLog::OPTION, $this->options );
	}

	public function test_extending_adds_time_but_never_to_a_freeze_without_an_end(): void {
		$end = time() + 600;
		$this->store(
			array(
				'start' => time() - 60,
				'end'   => $end,
			)
		);

		$this->assertTrue( $this->freezer()->extend( 30 ) );
		$this->assertSame( $end + 1800, $this->options[ State::OPTION ]['end'] );

		$this->store(
			array(
				'start' => time() - 60,
				'end'   => 0,
			)
		);
		$this->assertFalse( $this->freezer()->extend( 30 ) );
	}

	public function test_unfreezing_records_history_with_the_blocked_count(): void {
		$this->store(
			array(
				'start'  => time() - 300,
				'end'    => time() + 300,
				'reason' => 'Deploy',
				'by'     => 5,
			)
		);
		$this->options[ BlockedLog::OPTION ] = array( array( 'time' => 1 ), array( 'time' => 2 ) );

		$this->freezer()->unfreeze( 'cli' );

		$this->assertFalse( $this->options[ State::OPTION ]['active'] );
		$entry = $this->options[ History::OPTION ][0];
		$this->assertSame( 'cli', $entry['ended_by'] );
		$this->assertSame( 2, $entry['blocked'] );
		$this->assertSame( 'Deploy', $entry['reason'] );
	}

	public function test_an_expired_freeze_is_recorded_with_its_planned_end(): void {
		$end = time() - 3600;
		$this->store(
			array(
				'start' => time() - 7200,
				'end'   => $end,
			)
		);

		$this->freezer()->expire_if_needed();

		$this->assertSame( 'expired', $this->options[ History::OPTION ][0]['ended_by'] );
		$this->assertSame( $end, $this->options[ History::OPTION ][0]['end'] );
	}

	public function test_cancelling_a_scheduled_freeze_leaves_no_history(): void {
		$this->store(
			array(
				'start' => time() + 3600,
				'end'   => time() + 7200,
			)
		);

		$this->freezer()->unfreeze();

		$this->assertArrayNotHasKey( History::OPTION, $this->options );
	}
}
