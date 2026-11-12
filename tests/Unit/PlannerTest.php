<?php
/**
 * Tests for planned freezes and the countdown before them.
 *
 * @package AryanX\Axle\ReadOnlyMode
 */

namespace AryanX\Axle\ReadOnlyMode\Tests\Unit;

use AryanX\Axle\ReadOnlyMode\BlockedLog;
use AryanX\Axle\ReadOnlyMode\Freezer;
use AryanX\Axle\ReadOnlyMode\HeadsUp;
use AryanX\Axle\ReadOnlyMode\History;
use AryanX\Axle\ReadOnlyMode\Planner;
use AryanX\Axle\ReadOnlyMode\State;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class PlannerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_generate_password' )->justReturn( 'abc12345' );
		Functions\when( 'is_wp_error' )->alias( static fn( $thing ) => $thing instanceof \WP_Error );
		Functions\when( 'get_option' )->alias( fn( $name, $default = false ) => $this->options[ $name ] ?? $default );
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				unset( $this->options[ $name ] );
				return true;
			}
		);
		// No checks to run: the real ones need a database.
		Filters\expectApplied( 'axle_read_only_mode_checks' )->andReturn( array() );
	}

	private function planner(): Planner {
		$history = new History();
		$state   = new State();

		return Planner::for_site( new Freezer( $state, new BlockedLog( $history ), $history ), $state );
	}

	private function hours_ahead( int $hours ): int {
		return time() + $hours * HOUR_IN_SECONDS;
	}

	public function test_a_plan_needs_a_start_in_the_future(): void {
		$result = $this->planner()->save( array( 'start' => time() - 60 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( array(), $this->planner()->all() );
	}

	public function test_a_plan_is_saved_and_fires_an_action(): void {
		Actions\expectDone( 'axle_read_only_mode_planned' )->once();

		$plan = $this->planner()->save(
			array(
				'start'   => $this->hours_ahead( 2 ),
				'minutes' => 45,
				'area'    => 'content',
				'reason'  => 'Release night',
				'lead'    => 20,
			)
		);

		$this->assertSame( 'abc12345', $plan['id'] );
		$this->assertSame( 7, $plan['by'] );
		$this->assertSame( 'content', $this->planner()->get( 'abc12345' )['area'] );
		$this->assertSame( 20, Planner::lead_minutes( $plan ) );
	}

	public function test_the_lead_defaults_to_fifteen_minutes_and_goes_through_a_filter(): void {
		$this->assertSame( 15, Planner::lead_minutes() );

		Filters\expectApplied( 'axle_read_only_mode_heads_up_minutes' )->once()->andReturn( 99999 );
		$this->assertSame( 1440, Planner::lead_minutes() );
	}

	public function test_cancel_removes_the_plan_and_fires_an_action(): void {
		$planner = $this->planner();
		$planner->save( array( 'start' => $this->hours_ahead( 2 ) ) );

		Actions\expectDone( 'axle_read_only_mode_plan_cancelled' )->once();

		$this->assertNotNull( $planner->cancel( 'abc12345' ) );
		$this->assertNull( $planner->cancel( 'abc12345' ) );
		$this->assertSame( array(), $planner->all() );
	}

	public function test_next_start_keeps_the_time_of_day_on_the_next_chosen_weekday(): void {
		// Monday 2026-11-16 02:00 UTC. Repeats Mondays and Thursdays.
		$monday = gmmktime( 2, 0, 0, 11, 16, 2026 );

		$this->assertSame( gmmktime( 2, 0, 0, 11, 19, 2026 ), Planner::next_start( $monday, array( 1, 4 ), $monday ) );
		$this->assertSame( gmmktime( 2, 0, 0, 11, 23, 2026 ), Planner::next_start( $monday, array( 1, 4 ), gmmktime( 3, 0, 0, 11, 19, 2026 ) ) );
		$this->assertSame( 0, Planner::next_start( $monday, array(), $monday ) );
	}

	public function test_tick_does_nothing_before_the_time(): void {
		$planner = $this->planner();
		$planner->save( array( 'start' => $this->hours_ahead( 1 ) ) );

		Actions\expectDone( 'axle_read_only_mode_started' )->never();
		$planner->tick();

		$this->assertFalse( ( new State() )->is_frozen() );
	}

	public function test_tick_starts_a_due_plan_once_and_removes_a_one_off(): void {
		$planner = $this->planner();
		$planner->save(
			array(
				'start'   => $this->hours_ahead( 1 ),
				'minutes' => 30,
				'area'    => 'content',
				'reason'  => 'Deploy',
			)
		);
		$plans                          = $this->options[ Planner::OPTION ];
		$plans['abc12345']['start']     = time() - 5;
		$this->options[ Planner::OPTION ] = $plans;

		Actions\expectDone( 'axle_read_only_mode_started' )->once();
		Actions\expectDone( 'axle_read_only_mode_plan_started' )->once();

		$planner->tick();
		$planner->tick();

		$state = new State();
		$this->assertTrue( $state->is_frozen() );
		$this->assertSame( 'content', $state->own()['area'] );
		$this->assertSame( 'Deploy', $state->own()['reason'] );
		$this->assertSame( 7, $state->own()['by'] );
		$this->assertSame( array(), $planner->all() );
	}

	public function test_a_weekly_plan_moves_to_its_next_day_after_it_starts(): void {
		$planner = $this->planner();
		$planner->save(
			array(
				'start'   => $this->hours_ahead( 1 ),
				'minutes' => 30,
				'days'    => array( 0, 1, 2, 3, 4, 5, 6 ),
			)
		);
		$plans                          = $this->options[ Planner::OPTION ];
		$due                            = time() - 5;
		$plans['abc12345']['start']     = $due;
		$this->options[ Planner::OPTION ] = $plans;

		$planner->tick();

		$plan = $planner->get( 'abc12345' );
		$this->assertGreaterThan( time(), $plan['start'] );
		$this->assertSame( $due, $plan['last'] );
	}

	public function test_a_plan_is_skipped_when_a_freeze_is_already_running(): void {
		$this->options[ State::OPTION ] = array(
			'active' => true,
			'start'  => time() - 600,
			'end'    => time() + 600,
		);
		$planner = $this->planner();
		$planner->save( array( 'start' => $this->hours_ahead( 1 ) ) );
		$plans                          = $this->options[ Planner::OPTION ];
		$plans['abc12345']['start']     = time() - 5;
		$this->options[ Planner::OPTION ] = $plans;

		Actions\expectDone( 'axle_read_only_mode_plan_skipped' )->once()->with( \Mockery::type( 'array' ), 'busy', false );
		Actions\expectDone( 'axle_read_only_mode_plan_started' )->never();

		$planner->tick();

		$this->assertSame( array(), $planner->all() );
	}

	public function test_a_plan_whose_whole_window_went_by_is_missed_not_started(): void {
		$planner = $this->planner();
		$planner->save(
			array(
				'start'   => $this->hours_ahead( 1 ),
				'minutes' => 10,
			)
		);
		$plans                          = $this->options[ Planner::OPTION ];
		$plans['abc12345']['start']     = time() - 3600;
		$this->options[ Planner::OPTION ] = $plans;

		Actions\expectDone( 'axle_read_only_mode_plan_skipped' )->once()->with( \Mockery::type( 'array' ), 'missed', false );
		$planner->tick();

		$this->assertFalse( ( new State() )->is_frozen() );
	}

	public function test_the_heads_up_shows_only_inside_its_window_and_to_people_it_concerns(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );

		$planner = $this->planner();
		$planner->save(
			array(
				'start'  => time() + 12 * MINUTE_IN_SECONDS,
				'area'   => 'content',
				'reason' => 'Deploy',
			)
		);
		$far = $this->planner();
		$heads_up = new HeadsUp( array( array( 'state' => new State(), 'planner' => $far ) ) );

		$upcoming = $heads_up->upcoming();
		$this->assertNotNull( $upcoming );
		$this->assertSame( 'content', $upcoming['area'] );
		$this->assertStringContainsString( '12 minutes', $upcoming['message'] );

		$plans                          = $this->options[ Planner::OPTION ];
		$plans['abc12345']['start']     = time() + 3 * HOUR_IN_SECONDS;
		$this->options[ Planner::OPTION ] = $plans;
		$this->assertNull( $heads_up->upcoming() );
	}

	public function test_the_heads_up_follows_a_freeze_scheduled_without_a_plan(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->options[ State::OPTION ] = array(
			'active' => true,
			'start'  => time() + 5 * MINUTE_IN_SECONDS,
			'end'    => time() + HOUR_IN_SECONDS,
			'area'   => 'all',
		);

		$upcoming = ( new HeadsUp( array( array( 'state' => new State(), 'planner' => null ) ) ) )->upcoming();

		$this->assertNotNull( $upcoming );
		$this->assertStringContainsString( '5 minutes', $upcoming['message'] );
	}

	public function test_the_heads_up_is_not_for_visitors(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		$this->options[ State::OPTION ] = array(
			'active' => true,
			'start'  => time() + 5 * MINUTE_IN_SECONDS,
		);

		$this->assertNull( ( new HeadsUp( array( array( 'state' => new State(), 'planner' => null ) ) ) )->upcoming() );
	}

	public function test_the_start_findings_are_kept_for_the_freeze_they_belong_to(): void {
		$this->options[ Planner::FINDINGS ] = array(
			'start' => 1000,
			'lines' => array( '- Someone is editing' ),
		);

		$this->assertSame( array( '- Someone is editing' ), Planner::findings( 1000 ) );
		$this->assertSame( array(), Planner::findings( 2000 ) );
	}

	public function test_the_eraser_takes_a_user_out_of_the_plans_but_keeps_them(): void {
		Functions\when( 'get_user_by' )->justReturn( (object) array( 'ID' => 7 ) );
		$planner = $this->planner();
		$planner->save( array( 'start' => $this->hours_ahead( 3 ), 'exempt' => array( 7, 9 ) ) );

		$result = $planner->erase( 'a@example.com' );

		$this->assertTrue( $result['items_removed'] );
		$this->assertSame( BlockedLog::ERASED, $planner->get( 'abc12345' )['by'] );
		$this->assertSame( array( 9 ), $planner->get( 'abc12345' )['exempt'] );
	}
}
