<?php
/**
 * Tests for the emails sent when a freeze starts or ends.
 *
 * @package AryanX\Axle\ReadOnlyMode
 */

namespace AryanX\Axle\ReadOnlyMode\Tests\Unit;

use AryanX\Axle\ReadOnlyMode\BlockedLog;
use AryanX\Axle\ReadOnlyMode\Freezer;
use AryanX\Axle\ReadOnlyMode\History;
use AryanX\Axle\ReadOnlyMode\Network;
use AryanX\Axle\ReadOnlyMode\Notifier;
use AryanX\Axle\ReadOnlyMode\Planner;
use AryanX\Axle\ReadOnlyMode\Settings;
use AryanX\Axle\ReadOnlyMode\State;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class NotifierTest extends TestCase {

	/**
	 * Transients, by name.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = array();

	/**
	 * Seconds each transient was set for.
	 *
	 * @var array<string, int>
	 */
	private array $lifetimes = array();

	/**
	 * Emails handed to wp_mail(): to, subject, body.
	 *
	 * @var array[]
	 */
	private array $mail = array();

	protected function setUp(): void {
		parent::setUp();

		$this->transients = array();
		$this->mail       = array();

		Functions\when( 'get_transient' )->alias( fn( $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $seconds = 0 ) {
				$this->transients[ $key ] = $value;
				$this->lifetimes[ $key ]  = $seconds;
				return true;
			}
		);
		Functions\when( 'get_site_transient' )->alias( fn( $key ) => $this->transients[ 'site:' . $key ] ?? false );
		Functions\when( 'set_site_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ 'site:' . $key ] = $value;
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
		Functions\when( 'wp_date' )->justReturn( '14 Nov 2026 15:30' );
		Functions\when( 'wp_timezone_string' )->justReturn( 'Asia/Kolkata' );
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://example.test/wp-admin/' . $path );
		Functions\when( 'network_admin_url' )->alias( fn( $path = '' ) => 'https://example.test/wp-admin/network/' . $path );
		Functions\when( 'get_userdata' )->alias( fn( $id ) => 5 === (int) $id ? (object) array( 'display_name' => 'Sam Rivera' ) : false );
		Functions\when( 'wp_get_current_user' )->alias( fn() => new class() {
			public $display_name = 'Sam Rivera';
			public function exists() {
				return true;
			}
		} );
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );

		$this->options['blogname']     = 'Example Shop';
		$this->options['admin_email']  = 'admin@example.test';
		$this->options['date_format']  = 'j M Y';
		$this->options['time_format']  = 'H:i';
		$this->site_options['admin_email'] = 'network@example.test';
		$this->site_options['site_name']   = 'Example Network';
	}

	private function enable( array $notify = array() ): void {
		$this->options[ Settings::OPTION ] = array(
			'notify' => $notify + array(
				'enabled'    => true,
				'recipients' => array(),
				'events'     => Notifier::EVENTS,
			),
		);
	}

	private function notifier(): Notifier {
		return new Notifier( new State(), new Settings(), new History() );
	}

	private function freeze( array $changes = array() ): array {
		return $changes + array(
			'active' => true,
			'start'  => time() - 60,
			'end'    => time() + 3600,
			'reason' => 'Moving servers',
			'by'     => 5,
		);
	}

	public function test_the_email_says_what_the_freeze_covers(): void {
		$this->enable();

		$this->notifier()->send( 'started', $this->freeze( array( 'area' => 'content' ) ) );
		$this->assertStringContainsString( 'Scope: Content only', $this->mail[0]['body'] );

		$this->notifier()->send( 'ended', $this->freeze( array( 'area' => 'code' ) ), array( 'ended_by' => 'user' ) );
		$this->assertStringContainsString( 'Scope: Settings and code only', $this->mail[1]['body'] );

		$this->transients = array();
		$this->notifier()->send( 'started', $this->freeze() );
		$this->assertStringContainsString( 'Scope: Everything', $this->mail[2]['body'], 'A freeze with no stored scope covers everything.' );
	}

	public function test_the_start_email_of_a_planned_freeze_lists_what_the_check_found(): void {
		$this->enable();
		$freeze                                   = $this->freeze();
		$this->options[ Planner::FINDINGS ]       = array(
			'start' => $freeze['start'],
			'lines' => array( '- 2 people have a post open', '  Spring launch (Priya)' ),
		);

		$this->notifier()->send( 'started', $freeze );

		$this->assertStringContainsString( 'The check before freezing found:', $this->mail[0]['body'] );
		$this->assertStringContainsString( '- 2 people have a post open', $this->mail[0]['body'] );
	}

	public function test_nothing_is_added_when_the_check_found_nothing_for_this_freeze(): void {
		$this->enable();
		$this->options[ Planner::FINDINGS ] = array(
			'start' => 1,
			'lines' => array( '- stale' ),
		);

		$this->notifier()->send( 'started', $this->freeze() );

		$this->assertStringNotContainsString( 'The check before freezing found', $this->mail[0]['body'] );
	}

	public function test_nothing_is_sent_until_emails_are_turned_on(): void {
		$this->assertFalse( $this->notifier()->send( 'started', $this->freeze() ) );

		$this->enable( array( 'enabled' => false ) );
		$this->assertFalse( $this->notifier()->send( 'started', $this->freeze() ) );
		$this->assertSame( array(), $this->mail );
	}

	public function test_a_start_says_who_why_and_until_when_and_goes_to_the_admin_email_by_default(): void {
		$this->enable();

		$this->assertTrue( $this->notifier()->send( 'started', $this->freeze() ) );

		$mail = $this->mail[0];
		$this->assertSame( array( 'admin@example.test' ), $mail['to'] );
		$this->assertSame( '[Example Shop] Freeze started', $mail['subject'] );
		$this->assertStringContainsString( 'Read-only mode started on Example Shop.', $mail['body'] );
		$this->assertStringContainsString( 'Who: Sam Rivera', $mail['body'] );
		$this->assertStringContainsString( 'Why: Moving servers', $mail['body'] );
		$this->assertStringContainsString( 'Until: 14 Nov 2026 15:30 (Asia/Kolkata)', $mail['body'] );
		$this->assertStringContainsString( 'https://example.test/wp-admin/tools.php?page=axle-read-only-mode', $mail['body'] );
	}

	public function test_a_freeze_without_an_end_or_reason_says_so(): void {
		$this->enable();

		$this->notifier()->send( 'started', $this->freeze( array( 'end' => 0, 'reason' => '' ) ) );

		$this->assertStringContainsString( 'Why: No reason given', $this->mail[0]['body'] );
		$this->assertStringContainsString( 'Until: someone unfreezes the site (no end time)', $this->mail[0]['body'] );
	}

	public function test_an_end_says_how_many_attempts_were_blocked_and_links_to_the_list(): void {
		$this->enable();
		$freeze                         = $this->freeze();
		$this->options[ History::OPTION ] = array(
			array(
				'start'    => $freeze['start'],
				'end'      => time(),
				'blocked'  => 12,
				'ended_by' => 'user',
				'by'       => 5,
				'reason'   => 'Moving servers',
			),
		);
		$notifier = $this->notifier();

		$notifier->on_ended( $freeze, 'user' );

		$this->assertSame( '[Example Shop] Freeze ended', $this->mail[0]['subject'] );
		$this->assertStringContainsString( 'Ended by: Sam Rivera', $this->mail[0]['body'] );
		$this->assertStringContainsString( 'Started by: Sam Rivera', $this->mail[0]['body'] );
		$this->assertStringContainsString( 'Attempts blocked: 12', $this->mail[0]['body'] );
		$this->assertStringContainsString( 'See what was blocked: https://example.test/wp-admin/tools.php?page=axle-read-only-mode', $this->mail[0]['body'] );
		$this->assertStringNotContainsString( 'Until:', $this->mail[0]['body'] );
	}

	public function test_a_freeze_that_ran_out_is_its_own_event(): void {
		$this->enable( array( 'events' => array( 'expired' ) ) );

		$notifier = $this->notifier();
		$notifier->on_ended( $this->freeze(), 'user' );
		$this->assertSame( array(), $this->mail, 'Only the ticked events send.' );

		$notifier->on_ended( $this->freeze(), 'expired' );
		$this->assertSame( '[Example Shop] Freeze ended at its end time', $this->mail[0]['subject'] );
		$this->assertStringContainsString( 'Ended by: Nobody: it ended by itself', $this->mail[0]['body'] );
	}

	public function test_a_freeze_set_for_later_is_not_announced_until_it_starts(): void {
		$this->enable();
		$notifier = $this->notifier();

		$notifier->on_started( $this->freeze( array( 'start' => time() + 3600 ) ) );
		$this->assertSame( array(), $this->mail );

		$notifier->on_scheduled_started( $this->freeze() );
		$this->assertSame( '[Example Shop] Scheduled freeze started', $this->mail[0]['subject'] );
		$this->assertStringContainsString( 'Who: Nobody: it was scheduled', $this->mail[0]['body'] );
	}

	public function test_an_extension_says_how_long(): void {
		$this->enable();

		$this->notifier()->on_extended( $this->freeze(), 30 );

		$this->assertSame( '[Example Shop] Freeze extended', $this->mail[0]['subject'] );
		$this->assertStringContainsString( 'Added: 30 minutes', $this->mail[0]['body'] );
	}

	public function test_the_same_event_never_sends_twice_in_a_minute(): void {
		$this->enable();
		$notifier = $this->notifier();

		$this->assertTrue( $notifier->send( 'started', $this->freeze() ) );
		$this->assertFalse( $notifier->send( 'started', $this->freeze() ), 'A script that freezes in a loop cannot flood the inbox.' );
		$this->assertTrue( $notifier->send( 'extended', $this->freeze() ), 'Another event has its own slot.' );
		$this->assertCount( 2, $this->mail );
	}

	public function test_the_interval_can_be_longer_but_never_shorter_than_a_minute(): void {
		$this->enable();
		Filters\expectApplied( 'axle_read_only_mode_notify_interval' )->once()->with( 60, 'started' )->andReturn( 5 );

		$this->assertTrue( $this->notifier()->send( 'started', $this->freeze() ) );
		$this->assertSame( 60, $this->lifetimes['axle_read_only_mode_mail_started'] );
	}

	public function test_recipients_are_cleaned_and_code_can_change_everything(): void {
		$this->enable( array( 'recipients' => array( 'ops@example.test', 'not-an-address' ) ) );

		Filters\expectApplied( 'axle_read_only_mode_notify_recipients' )->once()->andReturnUsing(
			function ( $recipients ) {
				return array_merge( $recipients, array( 'pager@example.test', 'ops@example.test' ) );
			}
		);
		Filters\expectApplied( 'axle_read_only_mode_notify_subject' )->once()->andReturn( 'Custom subject' );
		Filters\expectApplied( 'axle_read_only_mode_notify_body' )->once()->andReturn( 'Custom body' );
		Actions\expectDone( 'axle_read_only_mode_notified' )->once()->with( 'started', array( 'ops@example.test', 'pager@example.test' ), 'Custom subject', true, \Mockery::type( 'array' ), false );

		$this->notifier()->send( 'started', $this->freeze() );

		$this->assertSame( array( 'ops@example.test', 'pager@example.test' ), $this->mail[0]['to'] );
		$this->assertSame( 'Custom subject', $this->mail[0]['subject'] );
		$this->assertSame( 'Custom body', $this->mail[0]['body'] );
	}

	public function test_code_can_change_which_events_send(): void {
		$this->enable( array( 'events' => array( 'started' ) ) );
		Filters\expectApplied( 'axle_read_only_mode_notify_events' )->andReturn( array( 'extended' ) );

		$notifier = $this->notifier();
		$this->assertFalse( $notifier->send( 'started', $this->freeze() ) );
		$this->assertTrue( $notifier->send( 'extended', $this->freeze() ) );
	}

	public function test_with_no_valid_recipient_nothing_is_sent(): void {
		$this->enable();
		$this->options['admin_email'] = 'not an address';

		$this->assertFalse( $this->notifier()->send( 'started', $this->freeze() ) );
		$this->assertSame( array(), $this->mail );
	}

	public function test_a_network_freeze_mails_the_network_admin_and_says_which_sites(): void {
		$this->site_options[ Settings::NETWORK_OPTION ] = array(
			'notify' => array(
				'enabled' => true,
				'events'  => array( 'started' ),
			),
		);
		$network  = new Network();
		$notifier = new Notifier( $network->state, $network->settings, $network->history );

		$notifier->send(
			'started',
			$this->freeze(
				array(
					'scope' => 'sites',
					'sites' => array( 2, 3 ),
				)
			)
		);

		$this->assertSame( array( 'network@example.test' ), $this->mail[0]['to'] );
		$this->assertSame( '[Example Network] Network freeze started', $this->mail[0]['subject'] );
		$this->assertStringContainsString( 'Covers: 2 chosen sites', $this->mail[0]['body'] );
		$this->assertStringContainsString( 'https://example.test/wp-admin/network/settings.php?page=axle-read-only-mode', $this->mail[0]['body'] );
		$this->assertArrayHasKey( 'site:axle_read_only_mode_mail_started_network', $this->transients );
	}

	public function test_settings_keep_valid_addresses_and_report_the_rest(): void {
		$settings = new Settings();

		$rejected = $settings->save_notify(
			array(
				'enabled'    => true,
				'recipients' => "ops@example.test, Ops@Example.test\nnope pager@example.test",
				'events'     => array( 'started', 'bogus', 'expired' ),
			)
		);

		$this->assertSame( array( 'ops@example.test', 'pager@example.test' ), $settings->notify()['recipients'] );
		$this->assertSame( array( 'started', 'expired' ), $settings->notify()['events'] );
		$this->assertTrue( $settings->notify()['enabled'] );
		$this->assertSame( 'nope', $rejected[0]['line'] );
	}

	public function test_emails_are_off_and_three_events_ticked_by_default(): void {
		$notify = ( new Settings() )->notify();

		$this->assertFalse( $notify['enabled'] );
		$this->assertSame( array(), $notify['recipients'] );
		$this->assertSame( array( 'started', 'ended', 'expired' ), $notify['events'] );
	}

	public function test_a_freeze_set_for_later_announces_itself_once_when_it_starts(): void {
		$history = new History();
		$state   = new State();
		$freezer = new Freezer( $state, new BlockedLog( $history ), $history );
		$freezer->freeze(
			array(
				'start'   => time() + 3600,
				'minutes' => 30,
			)
		);
		$this->assertTrue( $this->options[ State::OPTION ]['announce'] );

		$freezer->announce_scheduled_start();
		$this->assertTrue( $this->options[ State::OPTION ]['announce'], 'Not yet: the start time has not come.' );

		$this->options[ State::OPTION ]['start'] = time() - 5;
		$this->options[ State::OPTION ]['end']   = time() + 1800;
		Actions\expectDone( 'axle_read_only_mode_scheduled_started' )->once();

		$freezer->announce_scheduled_start();
		$freezer->announce_scheduled_start();

		$this->assertFalse( $this->options[ State::OPTION ]['announce'] );
	}

	public function test_a_freeze_that_starts_now_does_not_wait_to_be_announced(): void {
		$history = new History();
		$freezer = new Freezer( new State(), new BlockedLog( $history ), $history );
		Actions\expectDone( 'axle_read_only_mode_scheduled_started' )->never();

		$freezer->freeze( array( 'minutes' => 30 ) );
		$freezer->announce_scheduled_start();

		$this->assertFalse( $this->options[ State::OPTION ]['announce'] );
	}

	public function test_a_network_freeze_set_for_later_announces_itself_with_the_network_action(): void {
		Functions\when( 'get_sites' )->justReturn( array() );
		$network = new Network();
		$network->freezer->freeze(
			array(
				'start'   => time() + 3600,
				'minutes' => 30,
			)
		);
		$this->site_options[ State::NETWORK_OPTION ]['start'] = time() - 5;
		$this->site_options[ State::NETWORK_OPTION ]['end']   = time() + 1800;

		Actions\expectDone( 'axle_read_only_mode_network_scheduled_started' )->once();
		Actions\expectDone( 'axle_read_only_mode_scheduled_started' )->never();

		$network->freezer->announce_scheduled_start();
		$network->freezer->announce_scheduled_start();
	}

	public function test_saving_the_email_settings_fires_an_action_with_what_was_saved(): void {
		Actions\expectDone( 'axle_read_only_mode_notify_settings_saved' )->once()->with(
			array(
				'enabled'    => true,
				'recipients' => array( 'ops@example.test' ),
				'events'     => array( 'started' ),
			),
			false
		);

		( new Settings() )->save_notify(
			array(
				'enabled'    => true,
				'recipients' => 'ops@example.test',
				'events'     => array( 'started' ),
			)
		);
	}
}
