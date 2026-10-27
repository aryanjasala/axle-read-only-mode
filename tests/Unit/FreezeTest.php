<?php
/**
 * Tests for freeze timing, messages, extending and history.
 *
 * @package AryanX\Axle\ReadOnlyMode
 */

namespace AryanX\Axle\ReadOnlyMode\Tests\Unit;

use AryanX\Axle\ReadOnlyMode\BlockedLog;
use AryanX\Axle\ReadOnlyMode\Freezer;
use AryanX\Axle\ReadOnlyMode\History;
use AryanX\Axle\ReadOnlyMode\State;
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
		$history = new History();

		return new Freezer( new State(), new BlockedLog( $history ), $history );
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

		Filters\expectApplied( 'axle_read_only_mode_user_can_bypass' )->once()->with( true, 5 )->andReturn( false );
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

	public function test_requests_that_notice_the_end_together_record_it_once(): void {
		$this->store(
			array(
				'start' => time() - 7200,
				'end'   => time() - 60,
			)
		);

		$this->freezer()->expire_if_needed();
		// A second request read the state before the first one saved it.
		$this->options[ State::OPTION ]['active'] = true;
		$this->freezer()->expire_if_needed();

		$this->assertCount( 1, $this->options[ History::OPTION ] );
	}

	public function test_blocked_attempts_keep_the_user_id_and_drop_the_query_string(): void {
		$log = new BlockedLog( new History() );
		$log->add( 'POST /checkout/?email=sam@example.com&token=abc', 'front-end' );

		$entry = $this->options[ BlockedLog::OPTION ][0];
		$this->assertSame( 5, $entry['user'] );
		$this->assertSame( 'POST /checkout/', $entry['where'] );
		$this->assertArrayNotHasKey( 'who', $entry );
	}

	public function test_only_the_first_visitor_attempts_are_written(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Filters\expectApplied( 'axle_read_only_mode_logged_visitors' )->andReturn( 3 );
		$log = new BlockedLog( new History() );

		for ( $i = 0; $i < 8; $i++ ) {
			$log->add( 'POST /wp-comments-post.php', 'front-end' );
		}

		$this->assertCount( 3, $this->options[ BlockedLog::OPTION ] );
	}

	public function test_who_reads_old_entries_and_erased_users(): void {
		$this->assertSame( 'editor', BlockedLog::who( array( 'who' => 'editor' ) ) );
		$this->assertSame( '', BlockedLog::who( array( 'user' => 0 ) ) );
		$this->assertSame( '(removed)', BlockedLog::who( array( 'user' => BlockedLog::ERASED ) ) );
	}

	public function test_lengths_follow_the_filters_and_always_offer_the_default(): void {
		Filters\expectApplied( 'axle_read_only_mode_default_duration' )->andReturn( 90 );
		Filters\expectApplied( 'axle_read_only_mode_durations' )->andReturn( array( 0, 240, 15 ) );

		$this->assertSame( 90, Freezer::default_duration() );
		$this->assertSame( array( 15, 90, 240, 0 ), Freezer::durations(), 'Shortest first, "until I unfreeze it" last, with the default added.' );
	}

	public function test_extend_steps_never_end_up_empty(): void {
		Filters\expectApplied( 'axle_read_only_mode_extend_minutes' )->andReturn( array( 0, 'x' ) );

		$this->assertSame( array( 30 ), Freezer::extend_steps() );
	}

	public function test_a_freeze_without_a_length_uses_the_filtered_default(): void {
		Filters\expectApplied( 'axle_read_only_mode_default_duration' )->andReturn( 45 );

		$this->freezer()->freeze( array( 'reason' => 'Swap' ) );

		$state = $this->options[ State::OPTION ];
		$this->assertSame( 45 * 60, $state['end'] - $state['start'] );
	}

	public function test_lengths_read_as_hours_and_minutes(): void {
		$this->assertSame( '45 minutes', \AryanX\Axle\ReadOnlyMode\Admin\ToolsPage::duration_label( 45 ) );
		$this->assertSame( '2 hours', \AryanX\Axle\ReadOnlyMode\Admin\ToolsPage::duration_label( 120 ) );
		$this->assertSame( '1 hour 30 minutes', \AryanX\Axle\ReadOnlyMode\Admin\ToolsPage::duration_label( 90 ) );
	}

	public function test_a_filter_can_freeze_the_site_from_outside(): void {
		Filters\expectApplied( 'axle_read_only_mode_is_frozen' )->andReturn( true );

		$this->assertTrue( ( new State() )->is_frozen() );
	}

	public function test_the_messages_go_through_filters(): void {
		$this->store(
			array(
				'start' => time() - 60,
				'end'   => 0,
			)
		);
		Filters\expectApplied( 'axle_read_only_mode_visitor_message' )->andReturn( 'Back at 3 pm.' );
		Filters\expectApplied( 'axle_read_only_mode_message' )->andReturn( 'Ask Sam before editing.' );

		$this->assertSame( 'Back at 3 pm.', ( new State() )->visitor_message() );
		$this->assertSame( 'Ask Sam before editing.', ( new State() )->message() );
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
