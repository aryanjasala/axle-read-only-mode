<?php
/**
 * Emails when a freeze starts or ends.
 *
 * @package AryanX\Axle\ReadOnlyMode
 */

namespace AryanX\Axle\ReadOnlyMode;

use AryanX\Axle\ReadOnlyMode\Admin\NetworkPage;
use AryanX\Axle\ReadOnlyMode\Admin\ToolsPage;

defined( 'ABSPATH' ) || exit;

/**
 * Off by default. Listens to the freeze actions and sends one plain-text email
 * through wp_mail(), never more than one per event per minute.
 *
 * A site has one, and a multisite network has another for network freezes.
 */
final class Notifier implements Service {

	/**
	 * Events that can send an email.
	 */
	public const EVENTS = array( 'started', 'ended', 'expired', 'extended', 'scheduled_start' );

	/**
	 * Saved freeze.
	 *
	 * @var State
	 */
	private State $state;

	/**
	 * Settings with the notification choices.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Past freezes, for the blocked count of the one that ended.
	 *
	 * @var History
	 */
	private History $history;

	/**
	 * Sets up the notifier.
	 *
	 * @param State    $state    The freeze this notifier tells about.
	 * @param Settings $settings Its notification settings.
	 * @param History  $history  Its past freezes.
	 */
	public function __construct( State $state, Settings $settings, History $history ) {
		$this->state    = $state;
		$this->settings = $settings;
		$this->history  = $history;
	}

	/**
	 * Listens to the freeze actions.
	 */
	public function register(): void {
		$prefix = $this->state->is_network() ? 'axle_read_only_mode_network_' : 'axle_read_only_mode_';

		add_action( $prefix . 'started', array( $this, 'on_started' ) );
		add_action( $prefix . 'scheduled_started', array( $this, 'on_scheduled_started' ) );
		add_action( $prefix . 'extended', array( $this, 'on_extended' ), 10, 2 );
		add_action( $prefix . 'ended', array( $this, 'on_ended' ), 10, 2 );
	}

	/**
	 * Event labels, for the settings screen.
	 *
	 * @return array<string, string>
	 */
	public static function event_labels(): array {
		return array(
			'started'         => __( 'A freeze starts', 'axle-read-only-mode' ),
			'ended'           => __( 'A freeze is ended by someone', 'axle-read-only-mode' ),
			'expired'         => __( 'A freeze ends by itself at its end time', 'axle-read-only-mode' ),
			'extended'        => __( 'A freeze is extended', 'axle-read-only-mode' ),
			'scheduled_start' => __( 'A scheduled freeze starts', 'axle-read-only-mode' ),
		);
	}

	/**
	 * A freeze started. A freeze set for later only starts when its time comes.
	 *
	 * @param array $freeze The freeze.
	 */
	public function on_started( $freeze ): void {
		if ( (int) ( $freeze['start'] ?? 0 ) > time() ) {
			return;
		}

		$this->send( 'started', (array) $freeze );
	}

	/**
	 * A scheduled freeze started.
	 *
	 * @param array $freeze The freeze.
	 */
	public function on_scheduled_started( $freeze ): void {
		$this->send( 'scheduled_start', (array) $freeze );
	}

	/**
	 * A freeze was extended.
	 *
	 * @param array $freeze  The freeze with its new end.
	 * @param int   $minutes Minutes added.
	 */
	public function on_extended( $freeze, $minutes = 0 ): void {
		$this->send( 'extended', (array) $freeze, array( 'minutes' => (int) $minutes ) );
	}

	/**
	 * A freeze ended.
	 *
	 * @param array  $freeze   The freeze that ended.
	 * @param string $ended_by user, cli, ability or expired.
	 */
	public function on_ended( $freeze, $ended_by = 'user' ): void {
		$freeze  = (array) $freeze;
		$latest  = $this->history->all()[0] ?? array();
		$blocked = null;
		if ( isset( $latest['start'] ) && (int) ( $freeze['start'] ?? 0 ) === (int) $latest['start'] ) {
			$blocked = (int) ( $latest['blocked'] ?? 0 );
		}

		$this->send(
			'expired' === $ended_by ? 'expired' : 'ended',
			$freeze,
			array(
				'ended_by' => (string) $ended_by,
				'blocked'  => $blocked,
			)
		);
	}

	/**
	 * Sends the email for an event, if notifications are on for it.
	 *
	 * @param string $event   One of EVENTS.
	 * @param array  $freeze  The freeze.
	 * @param array  $context Extra facts: minutes (extended), ended_by and blocked (ended).
	 * @return bool Whether an email was handed to wp_mail() and sent.
	 */
	public function send( string $event, array $freeze, array $context = array() ): bool {
		$notify  = $this->settings->notify();
		$network = $this->state->is_network();
		if ( ! $notify['enabled'] ) {
			return false;
		}

		/**
		 * Filters the events that send an email.
		 *
		 * @param string[] $events  The events ticked on the settings screen: started, ended, expired, extended and scheduled_start.
		 * @param bool     $network Whether this is the network's freeze.
		 */
		$events = (array) apply_filters( 'axle_read_only_mode_notify_events', $notify['events'], $network );
		if ( ! in_array( $event, $events, true ) || ! $this->claim( $event ) ) {
			return false;
		}

		/**
		 * Filters who gets the email.
		 *
		 * @param string[] $recipients Email addresses. Default the ones on the settings screen, or the admin email when there are none.
		 * @param string   $event      started, ended, expired, extended or scheduled_start.
		 * @param array    $freeze     The freeze.
		 * @param bool     $network    Whether this is the network's freeze.
		 */
		$recipients = (array) apply_filters( 'axle_read_only_mode_notify_recipients', $notify['recipients'] ? $notify['recipients'] : array( $this->admin_email() ), $event, $freeze, $network );
		$recipients = array_values( array_unique( array_filter( array_map( 'strval', $recipients ), 'is_email' ) ) );
		if ( ! $recipients ) {
			return false;
		}

		/**
		 * Filters the email subject.
		 *
		 * @param string $subject Subject.
		 * @param string $event   started, ended, expired, extended or scheduled_start.
		 * @param array  $freeze  The freeze.
		 * @param bool   $network Whether this is the network's freeze.
		 */
		$subject = (string) apply_filters( 'axle_read_only_mode_notify_subject', $this->subject( $event ), $event, $freeze, $network );

		/**
		 * Filters the email body, plain text.
		 *
		 * @param string $body    Body.
		 * @param string $event   started, ended, expired, extended or scheduled_start.
		 * @param array  $freeze  The freeze.
		 * @param bool   $network Whether this is the network's freeze.
		 * @param array  $context Extra facts: minutes (extended), ended_by and blocked (ended, expired).
		 */
		$body = (string) apply_filters( 'axle_read_only_mode_notify_body', $this->body( $event, $freeze, $context ), $event, $freeze, $network, $context );

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- One notice to the few people the admin named, not bulk mail.
		$sent = (bool) wp_mail( $recipients, $subject, $body );

		/**
		 * Fires after a freeze email was handed to wp_mail().
		 *
		 * @param string   $event      started, ended, expired, extended or scheduled_start.
		 * @param string[] $recipients Who it went to.
		 * @param string   $subject    Subject.
		 * @param bool     $sent       Whether wp_mail() sent it.
		 * @param array    $freeze     The freeze.
		 * @param bool     $network    Whether this is the network's freeze.
		 */
		do_action( 'axle_read_only_mode_notified', $event, $recipients, $subject, $sent, $freeze, $network );

		return $sent;
	}

	/**
	 * Takes the one slot per event per interval. Others in the same minute are dropped.
	 *
	 * @param string $event Event.
	 * @return bool Whether this call may send.
	 */
	private function claim( string $event ): bool {
		/**
		 * Filters the seconds before the same event can send another email.
		 *
		 * Never less than 60, so a script that freezes and unfreezes in a loop cannot flood a mailbox.
		 *
		 * @param int    $seconds Default 60.
		 * @param string $event   started, ended, expired, extended or scheduled_start.
		 */
		$seconds = max( 60, (int) apply_filters( 'axle_read_only_mode_notify_interval', 60, $event ) );
		$key     = 'axle_read_only_mode_mail_' . $event;

		if ( $this->state->is_network() ) {
			$key .= '_network';
			if ( get_site_transient( $key ) ) {
				return false;
			}

			return (bool) set_site_transient( $key, time(), $seconds );
		}

		if ( get_transient( $key ) ) {
			return false;
		}

		return (bool) set_transient( $key, time(), $seconds );
	}

	/**
	 * The address used when none is set: the site's admin email, or the network's.
	 *
	 * @return string
	 */
	private function admin_email(): string {
		return (string) ( $this->state->is_network() ? get_site_option( 'admin_email' ) : get_option( 'admin_email' ) );
	}

	/**
	 * Name of the site, or the network.
	 *
	 * @return string
	 */
	private function name(): string {
		$name = $this->state->is_network() ? get_site_option( 'site_name' ) : get_option( 'blogname' );

		return wp_specialchars_decode( (string) $name, ENT_QUOTES );
	}

	/**
	 * Subject for an event.
	 *
	 * @param string $event Event.
	 * @return string
	 */
	private function subject( string $event ): string {
		$name    = $this->name();
		$network = $this->state->is_network();

		switch ( $event ) {
			case 'ended':
				/* translators: %s: site name. */
				$subject = $network ? __( '[%s] Network freeze ended', 'axle-read-only-mode' ) : __( '[%s] Freeze ended', 'axle-read-only-mode' );
				break;
			case 'expired':
				/* translators: %s: site name. */
				$subject = $network ? __( '[%s] Network freeze ended at its end time', 'axle-read-only-mode' ) : __( '[%s] Freeze ended at its end time', 'axle-read-only-mode' );
				break;
			case 'extended':
				/* translators: %s: site name. */
				$subject = $network ? __( '[%s] Network freeze extended', 'axle-read-only-mode' ) : __( '[%s] Freeze extended', 'axle-read-only-mode' );
				break;
			case 'scheduled_start':
				/* translators: %s: site name. */
				$subject = $network ? __( '[%s] Scheduled network freeze started', 'axle-read-only-mode' ) : __( '[%s] Scheduled freeze started', 'axle-read-only-mode' );
				break;
			default:
				/* translators: %s: site name. */
				$subject = $network ? __( '[%s] Network freeze started', 'axle-read-only-mode' ) : __( '[%s] Freeze started', 'axle-read-only-mode' );
		}

		return sprintf( $subject, $name );
	}

	/**
	 * Plain-text body for an event: who, why, until when, and for an end, how much was blocked.
	 *
	 * @param string $event   Event.
	 * @param array  $freeze  The freeze.
	 * @param array  $context Extra facts.
	 * @return string
	 */
	private function body( string $event, array $freeze, array $context ): string {
		$network = $this->state->is_network();
		$format  = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$end     = (int) ( $freeze['end'] ?? 0 );
		$reason  = (string) ( $freeze['reason'] ?? '' );
		$started = get_userdata( (int) ( $freeze['by'] ?? 0 ) );

		$headline = array(
			/* translators: %s: site name. */
			'started'         => __( 'Read-only mode started on %s.', 'axle-read-only-mode' ),
			/* translators: %s: site name. */
			'scheduled_start' => __( 'A scheduled freeze started on %s.', 'axle-read-only-mode' ),
			/* translators: %s: site name. */
			'extended'        => __( 'The freeze on %s was extended.', 'axle-read-only-mode' ),
			/* translators: %s: site name. */
			'ended'           => __( 'The freeze on %s ended.', 'axle-read-only-mode' ),
			/* translators: %s: site name. */
			'expired'         => __( 'The freeze on %s ended by itself at its end time.', 'axle-read-only-mode' ),
		);
		$lines    = array(
			sprintf( $headline[ $event ] ?? $headline['started'], $this->name() ),
			'',
		);

		if ( in_array( $event, array( 'ended', 'expired' ), true ) ) {
			$who = $this->who( $event, (string) ( $context['ended_by'] ?? '' ) );
			/* translators: %s: name of who ended the freeze. */
			$lines[] = sprintf( __( 'Ended by: %s', 'axle-read-only-mode' ), $who );
			/* translators: %s: name of who started the freeze. */
			$lines[] = sprintf( __( 'Started by: %s', 'axle-read-only-mode' ), $started ? $started->display_name : __( 'WP-CLI or a script', 'axle-read-only-mode' ) );
		} else {
			/* translators: %s: name of who started or extended the freeze. */
			$lines[] = sprintf( __( 'Who: %s', 'axle-read-only-mode' ), $this->who( $event, '' ) );
		}

		/* translators: %s: the reason given for the freeze, or "none given". */
		$lines[] = sprintf( __( 'Why: %s', 'axle-read-only-mode' ), '' !== $reason ? $reason : __( 'No reason given', 'axle-read-only-mode' ) );

		/* translators: %s: what the freeze covers, e.g. "Content only". */
		$lines[] = sprintf( __( 'Scope: %s', 'axle-read-only-mode' ), Scopes::label( (string) ( $freeze['area'] ?? Scopes::ALL ) ) );

		if ( ! in_array( $event, array( 'ended', 'expired' ), true ) ) {
			$lines[] = $end
				/* translators: 1: date and time the freeze ends, 2: site time zone. */
				? sprintf( __( 'Until: %1$s (%2$s)', 'axle-read-only-mode' ), wp_date( $format, $end ), wp_timezone_string() )
				: __( 'Until: someone unfreezes the site (no end time)', 'axle-read-only-mode' );
		}

		if ( 'extended' === $event && ! empty( $context['minutes'] ) ) {
			/* translators: %d: minutes added to the freeze. */
			$lines[] = sprintf( __( 'Added: %d minutes', 'axle-read-only-mode' ), (int) $context['minutes'] );
		}

		if ( $network ) {
			$lines[] = 'sites' === ( $freeze['scope'] ?? 'all' )
				/* translators: %d: number of sites. */
				? sprintf( __( 'Covers: %d chosen sites', 'axle-read-only-mode' ), count( (array) ( $freeze['sites'] ?? array() ) ) )
				: __( 'Covers: every site on the network', 'axle-read-only-mode' );
		}

		if ( in_array( $event, array( 'ended', 'expired' ), true ) ) {
			if ( isset( $context['blocked'] ) ) {
				/* translators: %d: number of attempts the freeze blocked. */
				$lines[] = sprintf( __( 'Attempts blocked: %d', 'axle-read-only-mode' ), (int) $context['blocked'] );
			}
			$lines[] = '';
			/* translators: %s: address of the page that lists what was blocked. */
			$lines[] = sprintf( __( 'See what was blocked: %s', 'axle-read-only-mode' ), $network ? NetworkPage::page_url() : ToolsPage::page_url() );
		} else {
			$lines[] = '';
			/* translators: %s: address of the page that controls the freeze. */
			$lines[] = sprintf( __( 'Manage the freeze: %s', 'axle-read-only-mode' ), $network ? NetworkPage::page_url() : ToolsPage::page_url() );
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Who did it: the person, WP-CLI, or nobody for a freeze that ended at its end time.
	 *
	 * @param string $event    Event.
	 * @param string $ended_by user, cli, ability or expired.
	 * @return string
	 */
	private function who( string $event, string $ended_by ): string {
		if ( 'expired' === $event || 'expired' === $ended_by ) {
			return __( 'Nobody: it ended by itself', 'axle-read-only-mode' );
		}
		if ( 'scheduled_start' === $event ) {
			return __( 'Nobody: it was scheduled', 'axle-read-only-mode' );
		}

		$user = wp_get_current_user();
		if ( $user->exists() ) {
			return $user->display_name;
		}
		if ( 'ability' === $ended_by ) {
			return __( 'An AI agent or app', 'axle-read-only-mode' );
		}

		return defined( 'WP_CLI' ) && WP_CLI ? __( 'WP-CLI', 'axle-read-only-mode' ) : __( 'A script', 'axle-read-only-mode' );
	}
}
