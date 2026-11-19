<?php
/**
 * Tests for Freeze until green: a freeze that ends when the smoke test passes.
 *
 * Axle Smoke Test is never loaded. A stub listener stands in for its two hooks.
 *
 * @package AryanX\Axle\ReadOnlyMode
 */

namespace AryanX\Axle\ReadOnlyMode\Tests\Unit;

use AryanX\Axle\ReadOnlyMode\BlockedLog;
use AryanX\Axle\ReadOnlyMode\Freezer;
use AryanX\Axle\ReadOnlyMode\Green;
use AryanX\Axle\ReadOnlyMode\Guards\Blocker;
use AryanX\Axle\ReadOnlyMode\History;
use AryanX\Axle\ReadOnlyMode\Notifier;
use AryanX\Axle\ReadOnlyMode\Settings;
use AryanX\Axle\ReadOnlyMode\State;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class GreenTest extends TestCase {

	/**
	 * Emails handed to wp_mail().
	 *
	 * @var array[]
	 */
	private array $mail = array();

	/**
	 * Transients, by name.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = array();

	protected function setUp(): void {
		parent::setUp();

		$this->mail       = array();
		$this->transients = array();

		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'sanitize_key' )->alias( 'strtolower' );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_date' )->justReturn( '14 Nov 2026 15:30' );
		Functions\when( 'wp_timezone_string' )->justReturn( 'Asia/Kolkata' );
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://example.test/wp-admin/' . $path );
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( true );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		Functions\when( 'get_transient' )->alias( fn( $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $body ) {
				$this->mail[] = compact( 'to', 'subject', 'body' );
				return true;
			}
		);
		Functions\when( 'is_email' )->alias( fn( $value ) => false !== filter_var( $value, FILTER_VALIDATE_EMAIL ) ? $value : false );
		Functions\when( 'wp_specialchars_decode' )->returnArg();
		Functions\when( 'get_userdata' )->justReturn( false );
		Functions\when( 'wp_get_current_user' )->alias( fn() => new class() {
			public $display_name = '';
			public function exists() {
				return false;
			}
		} );

		$this->options['blogname']    = 'Example Shop';
		$this->options['admin_email'] = 'admin@example.test';
		$this->options['date_format'] = 'j M Y';
		$this->options['time_format'] = 'H:i';
	}

	protected function tearDown(): void {
		$flag = new \ReflectionProperty( Green::class, 'rolling_back' );
		$flag->setAccessible( true );
		$flag->setValue( null, false );
		unset( $_SERVER['REQUEST_METHOD'], $_GET['wc-ajax'] );

		parent::tearDown();
	}

	/**
	 * Makes Smoke Test "active": something listens to the ask-for-a-run hook.
	 */
	private function smoke_test_active(): void {
		add_action( 'axle_smoke_test_run_now', '__return_true' );
	}

	private function parts(): array {
		$state   = new State();
		$history = new History();
		$freezer = new Freezer( $state, new BlockedLog( $history ), $history );

		return array( $state, $freezer, new Green( $state, $freezer ), $history );
	}

	private function waiting( array $changes = array() ): void {
		$this->options[ State::OPTION ] = $changes + array(
			'active'          => true,
			'start'           => time() - 600,
			'end'             => 0,
			'until_green'     => true,
			'green_deadline'  => time() + 7200,
			'green_requested' => time() - 590,
			'green_run'       => array(),
		);
	}

	public function test_it_is_not_available_without_smoke_test(): void {
		$this->assertFalse( Green::available() );

		$this->smoke_test_active();
		$this->assertTrue( Green::available() );
	}

	public function test_code_can_say_it_is_available(): void {
		Filters\expectApplied( 'axle_read_only_mode_green_available' )->once()->with( false )->andReturn( true );

		$this->assertTrue( Green::available() );
	}

	public function test_a_freeze_until_green_has_no_end_time_and_a_hard_limit(): void {
		$this->smoke_test_active();
		list( $state, $freezer ) = $this->parts();

		$freezer->freeze(
			array(
				'minutes'     => 30,
				'until_green' => true,
			)
		);

		$own = $state->own();
		$this->assertTrue( $own['until_green'] );
		$this->assertSame( 0, $own['end'], 'The 30 minutes are ignored: the end is the smoke test.' );
		$this->assertEqualsWithDelta( time() + 7200, $own['green_deadline'], 3, 'The hard limit defaults to two hours.' );
		$this->assertSame( 'waiting', $state->green_status() );
		$this->assertStringContainsString( 'until the smoke test passes', $state->message() );
	}

	public function test_without_smoke_test_the_freeze_keeps_its_minutes(): void {
		list( $state, $freezer ) = $this->parts();

		$freezer->freeze(
			array(
				'minutes'     => 30,
				'until_green' => true,
			)
		);

		$this->assertFalse( $state->own()['until_green'] );
		$this->assertEqualsWithDelta( time() + 1800, $state->own()['end'], 3 );
		$this->assertSame( '', $state->green_status() );
	}

	public function test_a_network_freeze_never_waits_for_the_smoke_test(): void {
		$this->smoke_test_active();
		$store   = new \AryanX\Axle\ReadOnlyMode\Store( State::NETWORK_OPTION, true );
		$state   = new State( $store );
		$history = new History();
		$freezer = new Freezer( $state, new BlockedLog( $history ), $history );

		$freezer->freeze(
			array(
				'minutes'     => 30,
				'until_green' => true,
			)
		);

		$this->assertFalse( $state->own()['until_green'] );
	}

	public function test_the_hard_limit_can_be_changed_and_is_clamped(): void {
		Filters\expectApplied( 'axle_read_only_mode_green_max_minutes' )->once()->andReturn( 1 );
		$this->assertSame( 5, Green::max_minutes() );

		Filters\expectApplied( 'axle_read_only_mode_green_max_minutes' )->once()->andReturn( 99999 );
		$this->assertSame( 1440, Green::max_minutes() );
	}

	public function test_starting_the_freeze_asks_smoke_test_for_a_run(): void {
		$this->smoke_test_active();
		$this->waiting( array( 'green_requested' => 0 ) );
		list( $state, , $green ) = $this->parts();

		Actions\expectDone( 'axle_smoke_test_run_now' )->once()->with( 'axle-read-only-mode' );
		Actions\expectDone( 'axle_read_only_mode_green_requested' )->once();

		$green->on_started( $state->own() );

		$this->assertEqualsWithDelta( time(), $state->own()['green_requested'], 3 );
	}

	public function test_a_freeze_set_for_later_asks_when_it_starts_not_before(): void {
		$this->smoke_test_active();
		$this->options[ State::OPTION ] = array(
			'active'      => true,
			'start'       => time() + 3600,
			'until_green' => true,
		);
		list( $state, , $green ) = $this->parts();

		Actions\expectDone( 'axle_smoke_test_run_now' )->never();

		$green->on_started( $state->own() );
	}

	public function test_a_freeze_that_does_not_wait_never_asks(): void {
		$this->smoke_test_active();
		$this->waiting( array( 'until_green' => false ) );
		list( $state, , $green ) = $this->parts();

		Actions\expectDone( 'axle_smoke_test_run_now' )->never();

		$green->on_started( $state->own() );
	}

	public function test_nothing_breaks_when_smoke_test_is_missing(): void {
		$this->waiting();
		list( , , $green ) = $this->parts();

		Actions\expectDone( 'axle_smoke_test_run_now' )->never();

		$this->assertFalse( $green->request_run() );
	}

	public function test_a_green_run_ends_the_freeze_and_is_written_in_the_history(): void {
		$this->waiting();
		list( $state, , $green, $history ) = $this->parts();

		Actions\expectDone( 'axle_read_only_mode_green_passed' )->once();
		Actions\expectDone( 'axle_read_only_mode_ended' )->once()->with( \Mockery::type( 'array' ), 'green' );

		$green->on_run_finished(
			array(
				'id'       => 12,
				'verdict'  => 'green',
				'source'   => 'axle-read-only-mode',
				'finished' => time(),
			)
		);

		$this->assertFalse( $state->own()['active'] );
		$entry = $history->all()[0];
		$this->assertSame( 'green', $entry['ended_by'] );
		$this->assertSame( 12, $entry['run'] );
		$this->assertTrue( $entry['until_green'] );
	}

	public function test_an_early_green_report_waits_for_the_final_one(): void {
		$this->waiting();
		list( $state, , $green ) = $this->parts();

		Actions\expectDone( 'axle_read_only_mode_green_passed' )->never();

		$green->on_run_finished(
			array(
				'id'       => 13,
				'verdict'  => 'green',
				'source'   => 'axle-read-only-mode',
				'finished' => time(),
				'final'    => false,
			)
		);

		$this->assertTrue( $state->own()['active'] );
	}

	public function test_the_final_green_report_ends_the_freeze(): void {
		$this->waiting();
		list( $state, , $green ) = $this->parts();

		Actions\expectDone( 'axle_read_only_mode_green_passed' )->once();

		$green->on_run_finished(
			array(
				'id'       => 14,
				'verdict'  => 'green',
				'source'   => 'axle-read-only-mode',
				'finished' => time(),
				'final'    => true,
			)
		);

		$this->assertFalse( $state->own()['active'] );
	}

	public function test_a_red_run_keeps_the_freeze_and_says_why(): void {
		$this->waiting();
		list( $state, , $green ) = $this->parts();

		Actions\expectDone( 'axle_read_only_mode_green_failed' )->once();
		Actions\expectDone( 'axle_read_only_mode_ended' )->never();

		$green->on_run_finished(
			array(
				'id'       => 13,
				'verdict'  => 'red',
				'source'   => 'axle-read-only-mode',
				'finished' => time(),
			)
		);

		$this->assertTrue( $state->is_running() );
		$this->assertSame( 'failed', $state->green_status() );
		$this->assertSame( 13, $state->own()['green_run']['id'] );
		$this->assertTrue( $state->rollback_allowed() );
		$this->assertStringContainsString( 'not green', $state->message() );
	}

	public function test_a_green_run_after_a_red_one_ends_the_freeze(): void {
		$this->waiting(
			array(
				'green_run' => array(
					'id'      => 13,
					'verdict' => 'red',
				),
			)
		);
		list( $state, , $green ) = $this->parts();

		$green->on_run_finished(
			array(
				'id'           => 14,
				'verdict'      => 'green',
				'trigger_type' => 'rollback',
				'finished'     => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		$this->assertFalse( $state->own()['active'], 'The run after a rollback counts, and its finish time may be a GMT date.' );
	}

	public function test_amber_does_not_end_it_unless_code_says_so(): void {
		$this->waiting();
		list( $state, , $green ) = $this->parts();
		$run                     = array(
			'id'       => 15,
			'verdict'  => 'amber',
			'source'   => 'axle-read-only-mode',
			'finished' => time(),
		);

		$green->on_run_finished( $run );
		$this->assertTrue( $state->is_running() );

		Filters\expectApplied( 'axle_read_only_mode_green_verdicts' )->once()->andReturn( array( 'green', 'amber' ) );
		$green->on_run_finished( $run );
		$this->assertFalse( $state->own()['active'] );
	}

	public function test_a_run_that_is_not_ours_does_not_count(): void {
		$this->waiting();
		list( $state, , $green ) = $this->parts();

		$green->on_run_finished(
			array(
				'id'           => 16,
				'verdict'      => 'green',
				'source'       => 'wp-update',
				'trigger_type' => 'update',
				'finished'     => time(),
			)
		);

		$this->assertTrue( $state->is_running() );
		$this->assertSame( 'waiting', $state->green_status() );
	}

	public function test_a_run_that_finished_before_we_asked_does_not_count(): void {
		$this->waiting();
		list( $state, , $green ) = $this->parts();

		$green->on_run_finished(
			array(
				'id'       => 17,
				'verdict'  => 'green',
				'source'   => 'axle-read-only-mode',
				'finished' => time() - 3600,
			)
		);

		$this->assertTrue( $state->is_running() );
	}

	public function test_code_decides_which_runs_count(): void {
		$this->waiting();
		list( $state, , $green ) = $this->parts();

		Filters\expectApplied( 'axle_read_only_mode_green_run_counts' )->once()->andReturn( true );
		$green->on_run_finished(
			array(
				'id'      => 18,
				'verdict' => 'green',
				'source'  => 'something-else',
			)
		);

		$this->assertFalse( $state->own()['active'] );
	}

	public function test_runs_are_ignored_when_no_freeze_waits(): void {
		$this->waiting( array( 'until_green' => false ) );
		list( $state, , $green ) = $this->parts();

		Actions\expectDone( 'axle_read_only_mode_green_passed' )->never();
		$green->on_run_finished(
			array(
				'id'      => 19,
				'verdict' => 'green',
				'source'  => 'axle-read-only-mode',
			)
		);

		$this->assertTrue( $state->is_running() );
	}

	public function test_bad_input_from_smoke_test_is_ignored(): void {
		$this->waiting();
		list( $state, , $green ) = $this->parts();

		$green->on_run_finished( 'nonsense' );
		$green->on_run_finished( array( 'verdict' => 'purple' ) );

		$this->assertTrue( $state->is_running() );
		$this->assertSame( 'waiting', $state->green_status() );
	}

	public function test_the_hard_limit_ends_the_freeze_once_with_a_warning(): void {
		$this->waiting( array( 'green_deadline' => time() - 5 ) );
		list( $state, $freezer, , $history ) = $this->parts();

		Actions\expectDone( 'axle_read_only_mode_green_timeout' )->once();

		$freezer->end_green_wait_if_needed();
		$freezer->end_green_wait_if_needed();

		$this->assertFalse( $state->own()['active'] );
		$this->assertCount( 1, $history->all() );
		$this->assertSame( 'green_timeout', $history->all()[0]['ended_by'] );
	}

	public function test_before_the_hard_limit_nothing_happens(): void {
		$this->waiting();
		list( $state, $freezer ) = $this->parts();

		$freezer->end_green_wait_if_needed();

		$this->assertTrue( $state->is_running() );
	}

	public function test_extending_a_waiting_freeze_moves_its_hard_limit(): void {
		$this->waiting();
		list( $state, $freezer ) = $this->parts();
		$before                  = $state->own()['green_deadline'];

		$this->assertTrue( $freezer->extend( 30 ) );
		$this->assertSame( $before + 1800, $state->own()['green_deadline'] );
	}

	public function test_the_smoke_test_runner_gets_through_the_freeze_while_waiting(): void {
		$this->waiting();
		list( , , $green ) = $this->parts();

		foreach ( array( '/axle-smoke-test/v1/runs', '/axle-smoke-test/v1/runs/next', '/axle-smoke-test/v1/runs/4/start', '/axle-smoke-test/v1/runs/4/browser', '/axle-smoke-test/v1/runs/4/results', '/axle-smoke-test/v1/runs/4/complete' ) as $route ) {
			$this->assertTrue( $green->allow_rest( false, new FakeRequest( $route ) ), $route );
		}

		foreach ( array( '/axle-smoke-test/v1/runs/4/baseline', '/axle-smoke-test/v1/urls', '/axle-smoke-test/v1/runs/4/findings', '/wp/v2/posts/4' ) as $route ) {
			$this->assertFalse( $green->allow_rest( false, new FakeRequest( $route ) ), $route );
		}
	}

	public function test_rollback_is_refused_until_a_run_was_not_green_then_allowed_in_that_one_request(): void {
		$this->waiting();
		list( $state, , $green ) = $this->parts();
		$route                   = new FakeRequest( '/axle-smoke-test/v1/runs/4/rollback' );

		$this->assertFalse( $green->allow_rest( false, $route ), 'No run has failed yet.' );
		$this->assertTrue( $green->open_for_rollback( true ), 'The site stays frozen.' );

		$this->waiting(
			array(
				'green_run' => array(
					'id'      => 4,
					'verdict' => 'red',
				),
			)
		);
		list( , , $green ) = $this->parts();

		$this->assertTrue( $green->allow_rest( false, $route ) );
		$this->assertFalse( $green->open_for_rollback( true ), 'Smoke Test asks "frozen?" before it rolls back, and now the answer is no.' );
		$this->assertSame( 'content', $green->content_only_for_rollback( 'all' ), 'And a Smoke Test that asks about code hears that code is open.' );
		$this->assertSame( 'content', $green->content_only_for_rollback( 'content' ) );
	}

	public function test_rollback_through_the_admin_form_and_the_ability(): void {
		$this->waiting(
			array(
				'green_run' => array(
					'id'      => 4,
					'verdict' => 'red',
				),
			)
		);
		list( , , $green ) = $this->parts();

		$this->assertFalse( $green->allow_admin_post( false, 'POST /wp-admin/admin-post.php', 'admin' ), 'Another action is not let through.' );

		$_REQUEST['action'] = 'axle_smoke_test_rollback';
		$this->assertTrue( $green->allow_admin_post( false, 'POST /wp-admin/admin-post.php', 'admin' ) );
		unset( $_REQUEST['action'] );

		$this->assertFalse( $green->allow_ability( false, 'axle-smoke-test/run-now' ) );
		$this->assertTrue( $green->allow_ability( false, 'axle-smoke-test/rollback-update' ) );
	}

	public function test_a_freeze_that_does_not_wait_lets_no_rollback_through(): void {
		$this->waiting( array( 'until_green' => false ) );
		list( , , $green ) = $this->parts();

		$this->assertFalse( $green->allow_rest( false, new FakeRequest( '/axle-smoke-test/v1/runs/4/rollback' ) ) );
		$this->assertFalse( $green->allow_ability( false, 'axle-smoke-test/rollback-update' ) );
		$this->assertTrue( $green->open_for_rollback( true ) );
	}

	public function test_smoke_test_cron_keeps_running_while_the_freeze_pauses_the_rest(): void {
		$this->waiting();
		list( , , $green ) = $this->parts();
		$past              = time() - 30;

		Functions\when( '_get_cron_array' )->justReturn(
			array(
				$past => array(
					'axle_smoke_test_run'        => array( 'k1' => array() ),
					'axle_read_only_mode_green_deadline' => array( 'k2' => array() ),
					'publish_future_post'        => array( 'k3' => array() ),
				),
			)
		);

		$jobs = $green->keep_smoke_test_cron( array() );

		$this->assertSame( array( 'axle_smoke_test_run', 'axle_read_only_mode_green_deadline' ), array_keys( $jobs[ $past ] ) );
		$this->assertNull( $green->keep_smoke_test_cron( null ), 'A freeze that pauses no tasks leaves WordPress to read them.' );
	}

	public function test_status_and_the_ability_say_where_it_stands(): void {
		$this->waiting();
		list( $state ) = $this->parts();

		$this->assertTrue( $state->waits_for_green() );
		$this->assertSame( 'waiting', $state->green_status() );
		$this->assertFalse( $state->rollback_allowed() );
	}

	public function test_the_red_email_says_why_and_always_sends_while_email_is_on(): void {
		$this->options[ Settings::OPTION ] = array(
			'notify' => array(
				'enabled'    => true,
				'recipients' => array(),
				'events'     => array(),
			),
		);
		$this->waiting(
			array(
				'green_run' => array(
					'id'      => 13,
					'verdict' => 'red',
				),
			)
		);
		list( $state, , , $history ) = $this->parts();
		$notifier                    = new Notifier( $state, new Settings(), $history );

		$notifier->on_green_failed( $state->own(), array() );

		$this->assertCount( 1, $this->mail );
		$this->assertStringContainsString( 'stays frozen', $this->mail[0]['subject'] );
		$this->assertStringContainsString( 'Last smoke test: red (run 13)', $this->mail[0]['body'] );
		$this->assertStringContainsString( 'tools.php?page=axle-smoke-test&run=13', $this->mail[0]['body'] );
		$this->assertStringContainsString( 'Until: the smoke test passes (at the latest', $this->mail[0]['body'] );
	}

	public function test_the_hard_limit_email_warns_and_the_plain_end_email_is_not_sent_too(): void {
		$this->options[ Settings::OPTION ] = array(
			'notify' => array(
				'enabled'    => true,
				'recipients' => array(),
				'events'     => array( 'ended' ),
			),
		);
		$this->waiting();
		list( $state, , , $history ) = $this->parts();
		$notifier                    = new Notifier( $state, new Settings(), $history );

		$notifier->on_green_timeout( $state->own() );
		$notifier->on_ended( $state->own(), 'green_timeout' );

		$this->assertCount( 1, $this->mail );
		$this->assertStringContainsString( 'ended without a green smoke test', $this->mail[0]['subject'] );
		$this->assertStringContainsString( 'No smoke test finished in time.', $this->mail[0]['body'] );
		$this->assertStringContainsString( 'Check the site before you trust it.', $this->mail[0]['body'] );
	}

	public function test_a_green_end_email_says_nobody_ended_it(): void {
		$this->options[ Settings::OPTION ] = array(
			'notify' => array(
				'enabled'    => true,
				'recipients' => array(),
				'events'     => array( 'ended' ),
			),
		);
		$this->waiting();
		list( $state, , , $history ) = $this->parts();
		$notifier                    = new Notifier( $state, new Settings(), $history );

		$notifier->on_ended( $state->own(), 'green' );

		$this->assertStringContainsString( 'Ended by: Nobody: the smoke test passed', $this->mail[0]['body'] );
	}

	public function test_the_classic_woocommerce_checkout_gets_its_own_failure_shape(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_GET['wc-ajax']           = 'checkout';

		$response = Blocker::woo_checkout_response( 'We are doing some maintenance.' );

		$this->assertSame( 'failure', $response['result'] );
		$this->assertStringContainsString( '<li>We are doing some maintenance.</li>', $response['messages'] );
		$this->assertFalse( $response['reload'] );
	}

	public function test_other_requests_keep_the_normal_answer(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_GET['wc-ajax']           = 'add_to_cart';
		$this->assertNull( Blocker::woo_checkout_response( 'x' ) );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET['wc-ajax']           = 'checkout';
		$this->assertNull( Blocker::woo_checkout_response( 'x' ) );
	}
}

/**
 * Enough of a REST request to give its route.
 */
final class FakeRequest {

	/**
	 * Route.
	 *
	 * @var string
	 */
	private string $route;

	public function __construct( string $route ) {
		$this->route = $route;
	}

	public function get_route(): string {
		return $this->route;
	}
}
