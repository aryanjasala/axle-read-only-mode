<?php
/**
 * Tools > Read-Only Mode.
 *
 * @package AryanX\Axle\ReadOnlyMode
 */

namespace AryanX\Axle\ReadOnlyMode\Admin;

use AryanX\Axle\ReadOnlyMode\BlockedLog;
use AryanX\Axle\ReadOnlyMode\Checks;
use AryanX\Axle\ReadOnlyMode\Freezer;
use AryanX\Axle\ReadOnlyMode\History;
use AryanX\Axle\ReadOnlyMode\Scopes;
use AryanX\Axle\ReadOnlyMode\Service;
use AryanX\Axle\ReadOnlyMode\Settings;
use AryanX\Axle\ReadOnlyMode\State;
use const AryanX\Axle\ReadOnlyMode\PLUGIN_FILE;
use const AryanX\Axle\ReadOnlyMode\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Freeze form, live status with countdown, blocked attempts and history.
 */
final class ToolsPage implements Service {

	public const SLUG   = 'axle-read-only-mode';
	public const ACTION = 'axle_read_only_mode';

	/**
	 * The admin-ajax action that refreshes the "Before you freeze" card.
	 */
	public const CHECK_ACTION = 'axle_read_only_mode_check';

	/**
	 * Saved freeze.
	 *
	 * @var State
	 */
	private State $state;

	/**
	 * Starts, extends and ends freezes.
	 *
	 * @var Freezer
	 */
	private Freezer $freezer;

	/**
	 * Blocked attempts.
	 *
	 * @var BlockedLog
	 */
	private BlockedLog $log;

	/**
	 * Past freezes.
	 *
	 * @var History
	 */
	private History $history;

	/**
	 * This site's settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * The "Before you freeze" checks.
	 *
	 * @var Checks
	 */
	private Checks $checks;

	/**
	 * Sets up the screen.
	 *
	 * @param State      $state    Saved freeze.
	 * @param Freezer    $freezer  Starts, extends and ends freezes.
	 * @param BlockedLog $log      Blocked attempts.
	 * @param History    $history  Past freezes.
	 * @param Settings   $settings This site's settings.
	 * @param Checks     $checks   The "Before you freeze" checks.
	 */
	public function __construct( State $state, Freezer $freezer, BlockedLog $log, History $history, Settings $settings, Checks $checks ) {
		$this->checks   = $checks;
		$this->state    = $state;
		$this->freezer  = $freezer;
		$this->log      = $log;
		$this->history  = $history;
		$this->settings = $settings;
	}

	/**
	 * Adds the menu entry and the action handler.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_action' ) );
		add_action( 'wp_ajax_' . self::CHECK_ACTION, array( $this, 'ajax_check' ) );
	}

	/**
	 * Answers the screen's request for fresh checks, as the card's HTML.
	 */
	public function ajax_check(): void {
		check_ajax_referer( self::CHECK_ACTION );

		if ( ! current_user_can( self::capability() ) ) {
			wp_send_json_error( null, 403 );
		}

		$result = $this->checks->run( self::check_args() );

		ob_start();
		self::render_check_items( $result );

		wp_send_json_success(
			array(
				'html'    => ob_get_clean(),
				'editing' => $result['editing'],
				'confirm' => self::confirm_message( $result['editing'] ),
				'summary' => self::check_summary( $result ),
			)
		);
	}

	/**
	 * What the freeze being set up would be, read from the request.
	 *
	 * @return array See Checks::context().
	 */
	private static function check_args(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Verified by check_ajax_referer() in ajax_check().
		return array(
			'minutes'  => isset( $_GET['minutes'] ) ? absint( $_GET['minutes'] ) : Freezer::default_duration(),
			'start'    => isset( $_GET['start'] ) ? self::parse_start( sanitize_text_field( wp_unslash( $_GET['start'] ) ) ) : 0,
			'scope'    => isset( $_GET['freeze_scope'] ) ? sanitize_key( wp_unslash( $_GET['freeze_scope'] ) ) : Scopes::default_scope(),
			'cron'     => ! empty( $_GET['cron'] ),
			'visitors' => ! empty( $_GET['visitors'] ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Turns the start field's value into a time in the site's time zone.
	 *
	 * @param string $value A datetime-local value, or an empty string.
	 * @return int Unix time, 0 when empty or not a date.
	 */
	public static function parse_start( string $value ): int {
		if ( '' === $value ) {
			return 0;
		}

		try {
			return ( new \DateTimeImmutable( $value, wp_timezone() ) )->getTimestamp();
		} catch ( \Exception $e ) {
			return 0;
		}
	}

	/**
	 * The words the freeze confirm step shows when people are editing.
	 *
	 * @param int $editing Other people with a post open.
	 * @return string Empty when nobody is.
	 */
	public static function confirm_message( int $editing ): string {
		$sentence = Checks::editing_sentence( $editing );

		return '' === $sentence ? '' : $sentence . ' ' . __( 'You can unfreeze at any time. Freeze anyway?', 'axle-read-only-mode' );
	}

	/**
	 * One line for screen readers: how many items need a look.
	 *
	 * @param array $result Result of Checks::run().
	 * @return string
	 */
	private static function check_summary( array $result ): string {
		return $result['attention']
			? sprintf(
				/* translators: %d: number of checks. */
				_n( '%d check needs a look before you freeze.', '%d checks need a look before you freeze.', $result['attention'], 'axle-read-only-mode' ),
				$result['attention']
			)
			: __( 'Nothing would be interrupted.', 'axle-read-only-mode' );
	}

	/**
	 * URL that runs a freeze action from a link (admin bar, notices).
	 *
	 * @param string $task    unfreeze, extend or quick_freeze.
	 * @param int    $minutes Minutes, for extend and quick_freeze.
	 * @return string
	 */
	public static function action_url( string $task, int $minutes = 0 ): string {
		return wp_nonce_url(
			add_query_arg(
				array_filter(
					array(
						'action'  => self::ACTION,
						'task'    => $task,
						'minutes' => $minutes,
					)
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION
		);
	}

	/**
	 * Capability needed to freeze and unfreeze.
	 *
	 * @return string
	 */
	public static function capability(): string {
		/**
		 * Filters the capability needed to freeze and unfreeze the site.
		 *
		 * @param string $capability Default manage_options.
		 */
		return (string) apply_filters( 'axle_read_only_mode_capability', 'manage_options' );
	}

	/**
	 * Registers Tools > Read-Only Mode.
	 */
	public function add_page(): void {
		$hook = add_management_page(
			__( 'Read-Only Mode', 'axle-read-only-mode' ),
			__( 'Read-Only Mode', 'axle-read-only-mode' ),
			self::capability(),
			self::SLUG,
			array( $this, 'render' )
		);

		if ( $hook ) {
			add_action( "admin_print_styles-{$hook}", array( $this, 'enqueue_assets' ) );
		}
	}

	/**
	 * Shared styles, screen styles, and the countdown and copy script.
	 */
	public function enqueue_assets(): void {
		AxleUi::enqueue();
		wp_enqueue_style( 'axle-read-only-mode-admin', plugins_url( 'assets/admin.css', PLUGIN_FILE ), array( AxleUi::HANDLE ), VERSION );
		wp_enqueue_script( 'axle-read-only-mode-admin', plugins_url( 'assets/admin.js', PLUGIN_FILE ), array( 'wp-a11y' ), VERSION, true );
		wp_add_inline_script(
			'axle-read-only-mode-admin',
			'window.axleReadOnlyModeCheck = ' . wp_json_encode(
				array(
					'url'     => admin_url( 'admin-ajax.php' ),
					'action'  => self::CHECK_ACTION,
					'nonce'   => wp_create_nonce( self::CHECK_ACTION ),
					'failed'  => __( 'The checks could not be loaded. You can still freeze the site.', 'axle-read-only-mode' ),
					'loading' => __( 'Checking what a freeze would interrupt.', 'axle-read-only-mode' ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Prints the screen.
	 */
	public function render(): void {
		$frozen    = $this->state->is_frozen();
		$network   = $this->state->network_applies();
		$scheduled = ! $frozen && $this->state->is_scheduled();

		if ( $network ) {
			$badge = array(
				'warning',
				Scopes::ALL === $this->state->scope()
					? __( 'Frozen by the network', 'axle-read-only-mode' )
					/* translators: %s: what is frozen, e.g. "Content frozen". */
					: sprintf( __( '%s by the network', 'axle-read-only-mode' ), Scopes::status( $this->state->scope() ) ),
			);
		} elseif ( $frozen ) {
			$badge = array( 'warning', Scopes::status( $this->state->scope() ) );
		} elseif ( $scheduled ) {
			$badge = array( 'info', __( 'Freeze scheduled', 'axle-read-only-mode' ) );
		} else {
			$badge = array( 'neutral', __( 'Not frozen', 'axle-read-only-mode' ) );
		}
		?>
		<div class="wrap axle-ui aro-page">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Read-Only Mode', 'axle-read-only-mode' ); ?></h1>
			<span class="axle-status"><?php self::badge( $badge[0], $badge[1] ); ?></span>
			<hr class="wp-header-end">
			<?php self::render_tabs( self::page_url(), self::current_tab() ); ?>

			<?php
			if ( 'settings' === self::current_tab() ) {
				$this->render_settings_tab();
			} else {
				$this->render_freeze_tab();
			}

			AxleUi::footer();
			?>
		</div>
		<?php
	}

	/**
	 * Prints the settings tab.
	 */
	private function render_settings_tab(): void {
		?>
		<p class="axle-intro"><?php esc_html_e( 'Choose what keeps working while the site is frozen.', 'axle-read-only-mode' ); ?></p>
		<?php
		$this->render_notices();
		SettingsView::render( $this->settings, admin_url( 'admin-post.php' ), self::ACTION );
	}

	/**
	 * Prints the freeze tab: status or form, what was blocked, history.
	 */
	private function render_freeze_tab(): void {
		?>
		<p class="axle-intro"><?php esc_html_e( 'Freeze the site before you migrate it or deploy a database. Visitors can still read it, but nothing can be saved until the freeze ends, so nothing written in the meantime is lost when you switch over.', 'axle-read-only-mode' ); ?></p>

		<?php
		$this->render_notices();

		if ( $this->state->forced_by_constant() ) {
			printf( '<div class="notice notice-warning inline"><p>%s</p></div>', esc_html__( 'The site is frozen by the AXLE_READ_ONLY_MODE constant in wp-config.php. Remove it to unfreeze.', 'axle-read-only-mode' ) );
		} else {
			if ( $this->state->network_applies() ) {
				$this->render_network();
			}

			if ( $this->state->is_running() ) {
				$this->render_status();
			} elseif ( $this->state->is_scheduled() ) {
				$this->render_scheduled();
			} else {
				$this->render_form();
			}
		}

		$this->render_stats();
		$this->render_blocked();
		?>

		<div class="axle-grid aro-columns">
			<?php $this->render_history(); ?>
			<?php $this->render_cli(); ?>
		</div>
		<?php
	}

	/**
	 * The screen's address.
	 *
	 * @param string $tab Tab to open, empty for the first.
	 * @return string
	 */
	public static function page_url( string $tab = '' ): string {
		return admin_url( 'tools.php?page=' . self::SLUG . ( '' !== $tab ? '&tab=' . $tab : '' ) );
	}

	/**
	 * The tab being shown: freeze or settings.
	 *
	 * @return string
	 */
	public static function current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only value.
		return isset( $_GET['tab'] ) && 'settings' === sanitize_key( wp_unslash( $_GET['tab'] ) ) ? 'settings' : 'freeze';
	}

	/**
	 * Prints the tabs shared by the site and network screens.
	 *
	 * @param string $url Screen address without a tab.
	 * @param string $current freeze or settings.
	 */
	public static function render_tabs( string $url, string $current ): void {
		$tabs = array(
			'freeze'   => __( 'Freeze', 'axle-read-only-mode' ),
			'settings' => __( 'Settings', 'axle-read-only-mode' ),
		);
		?>
		<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Read-Only Mode sections', 'axle-read-only-mode' ); ?>">
			<?php foreach ( $tabs as $tab => $label ) : ?>
				<a class="nav-tab<?php echo $tab === $current ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( 'freeze' === $tab ? $url : add_query_arg( 'tab', $tab, $url ) ); ?>"<?php echo $tab === $current ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Prints a state badge in one of the five Axle tones.
	 *
	 * @param string $tone  good, warning, error, info or neutral.
	 * @param string $label Words for the badge. Colour never carries the meaning alone.
	 */
	public static function badge( string $tone, string $label ): void {
		printf( '<span class="axle-badge axle-badge--%1$s">%2$s</span>', esc_attr( $tone ), esc_html( $label ) );
	}

	/**
	 * A relative time with the exact time in the title.
	 *
	 * @param int    $timestamp Unix time.
	 * @param string $format    Date format for the exact time.
	 * @return string HTML, already escaped.
	 */
	public static function time_tag( int $timestamp, string $format = '' ): string {
		$format = '' !== $format ? $format : get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$past   = $timestamp <= time();
		$diff   = human_time_diff( $timestamp );

		return sprintf(
			'<time datetime="%1$s" title="%2$s">%3$s</time>',
			esc_attr( gmdate( 'c', $timestamp ) ),
			esc_attr( wp_date( $format, $timestamp ) ),
			/* translators: %s: time span, e.g. "5 minutes". */
			esc_html( sprintf( $past ? __( '%s ago', 'axle-read-only-mode' ) : __( 'in %s', 'axle-read-only-mode' ), $diff ) )
		);
	}

	/**
	 * Prints the numbers at the top: what was blocked.
	 */
	private function render_stats(): void {
		$since   = $this->state->log_since();
		$blocked = $this->log->count( $since );
		$allowed = $this->log->count_allowed( $since );
		if ( ! $blocked && ! $allowed && ! $this->state->is_frozen() ) {
			return;
		}
		?>
		<div class="axle-stats">
			<div class="axle-stat <?php echo $blocked ? 'axle-stat--error' : 'axle-stat--good'; ?>">
				<span class="axle-stat__value"><?php echo esc_html( number_format_i18n( $blocked ) ); ?></span>
				<span class="axle-stat__label"><?php esc_html_e( 'attempts blocked', 'axle-read-only-mode' ); ?></span>
			</div>
			<div class="axle-stat <?php echo $allowed ? 'axle-stat--good' : ''; ?>">
				<span class="axle-stat__value"><?php echo esc_html( number_format_i18n( $allowed ) ); ?></span>
				<span class="axle-stat__label"><?php esc_html_e( 'requests allowed through', 'axle-read-only-mode' ); ?></span>
			</div>
		</div>
		<?php
	}

	/**
	 * Prints the network freeze that covers this site.
	 */
	private function render_network(): void {
		$state  = $this->state->all();
		$format = get_option( 'time_format' );
		?>
		<section class="axle-card aro-network">
			<div class="axle-card__head">
				<h2 class="axle-card__title"><?php esc_html_e( 'The network froze this site', 'axle-read-only-mode' ); ?></h2>
				<?php if ( $state['end'] ) : ?>
					<span class="axle-badge axle-badge--info aro-countdown" role="timer" data-aro-end="<?php echo esc_attr( (string) $state['end'] ); ?>" data-aro-label="<?php /* translators: %s: time left, e.g. 1:23:45. */ esc_attr_e( 'Ends in %s', 'axle-read-only-mode' ); ?>">
						<?php
						/* translators: %s: time the freeze ends. */
						echo esc_html( sprintf( __( 'Ends at %s', 'axle-read-only-mode' ), wp_date( $format, (int) $state['end'] ) ) );
						?>
					</span>
				<?php endif; ?>
			</div>
			<?php if ( '' !== $state['reason'] ) : ?>
				<p class="aro-reason"><?php echo esc_html( $state['reason'] ); ?></p>
			<?php endif; ?>
			<p>
				<?php
				/* translators: %s: scope of the freeze, e.g. "Content only". */
				echo esc_html( sprintf( __( 'Scope: %s', 'axle-read-only-mode' ), Scopes::label( $this->state->scope() ) ) );
				?>
			</p>
			<p><?php esc_html_e( 'A network freeze applies on every site it covers. It cannot be lifted from a site, and it overrides anything set here.', 'axle-read-only-mode' ); ?></p>
			<?php if ( current_user_can( NetworkPage::capability() ) ) : ?>
				<div class="axle-actions"><a class="button" href="<?php echo esc_url( NetworkPage::page_url() ); ?>"><?php esc_html_e( 'Manage in Network Admin', 'axle-read-only-mode' ); ?></a></div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Prints the live status of the current freeze.
	 */
	private function render_status(): void {
		$state  = $this->state->own();
		$by     = get_userdata( (int) $state['by'] );
		$format = get_option( 'time_format' );
		$exempt = array_filter( array_map( 'get_userdata', array_map( 'intval', $state['exempt'] ) ) );
		?>
		<section class="axle-card aro-status">
			<div class="axle-card__head">
				<h2 class="axle-card__title"><?php esc_html_e( 'The site is frozen', 'axle-read-only-mode' ); ?></h2>
				<?php if ( $state['end'] ) : ?>
					<span class="axle-badge axle-badge--info aro-countdown" role="timer" data-aro-end="<?php echo esc_attr( (string) $state['end'] ); ?>" data-aro-label="<?php /* translators: %s: time left, e.g. 1:23:45. */ esc_attr_e( 'Ends in %s', 'axle-read-only-mode' ); ?>">
						<?php
						/* translators: %s: time the freeze ends. */
						echo esc_html( sprintf( __( 'Ends at %s', 'axle-read-only-mode' ), wp_date( $format, (int) $state['end'] ) ) );
						?>
					</span>
				<?php endif; ?>
			</div>

			<?php if ( '' !== $state['reason'] ) : ?>
				<p class="aro-reason"><?php echo esc_html( $state['reason'] ); ?></p>
			<?php endif; ?>

			<dl class="axle-facts">
				<dt><?php esc_html_e( 'Started', 'axle-read-only-mode' ); ?></dt>
				<dd>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: start time, 2: user name. */
							__( '%1$s by %2$s', 'axle-read-only-mode' ),
							wp_date( $format, (int) $state['start'] ),
							$by ? $by->display_name : __( 'WP-CLI', 'axle-read-only-mode' )
						)
					);
					?>
				</dd>
				<dt><?php esc_html_e( 'Ends', 'axle-read-only-mode' ); ?></dt>
				<dd><?php echo esc_html( $state['end'] ? wp_date( $format, (int) $state['end'] ) : __( 'No end time: lasts until you unfreeze', 'axle-read-only-mode' ) ); ?></dd>
				<dt><?php esc_html_e( 'Scope', 'axle-read-only-mode' ); ?></dt>
				<dd><?php echo esc_html( Scopes::label( $this->state->scope() ) ); ?></dd>
				<dt><?php esc_html_e( 'Visitor submissions', 'axle-read-only-mode' ); ?></dt>
				<dd><?php echo esc_html( self::visitors_label( (bool) $state['visitors'], $this->state->scope() ) ); ?></dd>
				<dt><?php esc_html_e( 'Scheduled tasks', 'axle-read-only-mode' ); ?></dt>
				<dd><?php echo esc_html( self::cron_label( (bool) $state['cron'], $this->state->scope() ) ); ?></dd>
				<?php if ( $exempt ) : ?>
					<dt><?php esc_html_e( 'Can still edit', 'axle-read-only-mode' ); ?></dt>
					<dd><?php echo esc_html( implode( ', ', wp_list_pluck( $exempt, 'display_name' ) ) ); ?></dd>
				<?php endif; ?>
			</dl>

			<div class="axle-actions">
				<?php
				$this->render_button( 'unfreeze', __( 'Unfreeze now', 'axle-read-only-mode' ), 'primary' );
				if ( $state['end'] ) {
					foreach ( Freezer::extend_steps() as $step ) {
						/* translators: %s: length, e.g. "30 minutes". */
						$this->render_button( 'extend', sprintf( __( 'Add %s', 'axle-read-only-mode' ), self::duration_label( $step ) ), 'secondary', array( 'minutes' => $step ) );
					}
				}
				?>
			</div>
		</section>
		<?php
	}

	/**
	 * Prints a freeze that has not started yet.
	 */
	private function render_scheduled(): void {
		$state = $this->state->own();
		?>
		<section class="axle-card aro-status is-scheduled">
			<div class="axle-card__head">
				<h2 class="axle-card__title">
					<?php
					/* translators: %s: date and time the freeze starts. */
					echo esc_html( sprintf( __( 'Freeze scheduled for %s', 'axle-read-only-mode' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $state['start'] ) ) );
					?>
				</h2>
				<?php self::badge( 'info', __( 'Scheduled', 'axle-read-only-mode' ) ); ?>
			</div>
			<?php if ( '' !== $state['reason'] ) : ?>
				<p class="aro-reason"><?php echo esc_html( $state['reason'] ); ?></p>
			<?php endif; ?>
			<p>
				<?php
				/* translators: %s: scope of the freeze, e.g. "Content only". */
				echo esc_html( sprintf( __( 'Scope: %s', 'axle-read-only-mode' ), Scopes::label( (string) $state['area'] ) ) );
				?>
			</p>
			<div class="axle-actions"><?php $this->render_button( 'unfreeze', __( 'Cancel scheduled freeze', 'axle-read-only-mode' ), 'secondary' ); ?></div>
		</section>
		<?php
	}

	/**
	 * What a freeze does to visitor submissions, for its scope.
	 *
	 * @param bool   $paused Whether the freeze pauses visitor submissions.
	 * @param string $scope  all, content or code.
	 * @return string
	 */
	public static function visitors_label( bool $paused, string $scope ): string {
		if ( ! Scopes::covers( $scope, Scopes::CONTENT ) ) {
			return __( 'Still open: they are content, which this freeze leaves alone', 'axle-read-only-mode' );
		}

		return $paused ? __( 'Paused', 'axle-read-only-mode' ) : __( 'Still open', 'axle-read-only-mode' );
	}

	/**
	 * What a freeze does to scheduled tasks, for its scope.
	 *
	 * @param bool   $paused Whether the freeze holds scheduled tasks.
	 * @param string $scope  all, content or code.
	 * @return string
	 */
	public static function cron_label( bool $paused, string $scope ): string {
		if ( ! $paused ) {
			return __( 'Still running', 'axle-read-only-mode' );
		}
		if ( Scopes::CONTENT === $scope ) {
			return __( 'Paused, except update checks and site checks', 'axle-read-only-mode' );
		}
		if ( Scopes::CODE === $scope ) {
			return __( 'Only update checks and site checks are paused', 'axle-read-only-mode' );
		}

		return __( 'Paused', 'axle-read-only-mode' );
	}

	/**
	 * Prints the "Before you freeze" card.
	 */
	private function render_checks(): void {
		$result = $this->checks->run(
			array(
				'minutes' => Freezer::default_duration(),
				'scope'   => Scopes::default_scope(),
			)
		);
		?>
		<section class="axle-card aro-before" data-aro-confirm="<?php echo esc_attr( self::confirm_message( $result['editing'] ) ); ?>">
			<div class="axle-card__head">
				<h2 class="axle-card__title"><?php esc_html_e( 'Before you freeze', 'axle-read-only-mode' ); ?></h2>
				<span class="spinner aro-before__spinner"></span>
			</div>
			<p class="axle-muted"><?php esc_html_e( 'What a freeze with the choices below would interrupt right now. This only informs you: it never stops a freeze. It updates when you change the length, start or scope.', 'axle-read-only-mode' ); ?></p>
			<div class="aro-before__body" aria-live="polite">
				<?php self::render_check_items( $result ); ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Prints the items of a checks result.
	 *
	 * @param array $result Result of Checks::run().
	 */
	public static function render_check_items( array $result ): void {
		?>
		<ul class="aro-checks">
			<?php foreach ( $result['items'] as $item ) : ?>
				<li class="aro-check aro-check--<?php echo esc_attr( $item['id'] ); ?>">
					<?php self::badge( $item['tone'], $item['word'] ); ?>
					<div class="aro-check__text">
						<strong><?php echo esc_html( $item['title'] ); ?></strong>
						<?php if ( '' !== $item['detail'] ) : ?>
							<span class="axle-muted"><?php echo esc_html( $item['detail'] ); ?></span>
						<?php endif; ?>
						<?php if ( $item['rows'] ) : ?>
							<ul class="aro-check__rows">
								<?php foreach ( $item['rows'] as $row ) : ?>
									<li><?php echo esc_html( $row ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Prints the form that starts a freeze.
	 */
	private function render_form(): void {
		$this->render_checks();
		Forms::freeze( admin_url( 'admin-post.php' ), self::ACTION, __( 'Freeze the site', 'axle-read-only-mode' ), $this->state->visitor_message() );
	}

	/**
	 * Prints what was blocked during the current or last freeze.
	 */
	private function render_blocked(): void {
		$entries = $this->log->all( $this->state->log_since() );
		if ( ! $entries && ! $this->state->is_frozen() ) {
			return;
		}

		?>
		<section class="axle-card aro-blocked">
			<div class="axle-card__head">
				<h2 class="axle-card__title">
					<?php
					$title = $this->state->is_frozen()
						/* translators: %d: number of blocked attempts. */
						? __( 'Blocked during this freeze (%d)', 'axle-read-only-mode' )
						/* translators: %d: number of blocked attempts. */
						: __( 'Blocked during the last freeze (%d)', 'axle-read-only-mode' );
					echo esc_html( sprintf( $title, $this->log->count( $this->state->log_since() ) ) );
					?>
				</h2>
				<?php self::badge( 'neutral', Scopes::label( $this->state->scope() ) ); ?>
			</div>
			<?php if ( ! $entries ) : ?>
				<div class="axle-empty"><p><?php esc_html_e( 'Nothing yet. Attempts to save, upload, comment or submit a form will be listed here, so you know what to redo on the new server.', 'axle-read-only-mode' ); ?></p></div>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'When', 'axle-read-only-mode' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Who', 'axle-read-only-mode' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Where', 'axle-read-only-mode' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Affects', 'axle-read-only-mode' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Result', 'axle-read-only-mode' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Request', 'axle-read-only-mode' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $entries as $entry ) : ?>
							<tr>
								<td><?php echo self::time_tag( (int) $entry['time'], get_option( 'time_format' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in time_tag(). ?></td>
								<td><?php echo esc_html( '' !== BlockedLog::who( $entry ) ? BlockedLog::who( $entry ) : __( 'Visitor', 'axle-read-only-mode' ) ); ?></td>
								<td><?php echo esc_html( self::source_label( (string) $entry['source'] ) ); ?></td>
								<td><?php echo esc_html( isset( $entry['area'] ) ? Scopes::area_label( (string) $entry['area'] ) : '-' ); ?></td>
								<td><?php self::result_badge( $entry ); ?></td>
								<td><code><?php echo esc_html( $entry['where'] ); ?></code></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Words for where a request came from.
	 *
	 * @param string $source admin, ajax, rest, front-end or ability.
	 * @return string
	 */
	public static function source_label( string $source ): string {
		$sources = array(
			'admin'     => __( 'Dashboard', 'axle-read-only-mode' ),
			'ajax'      => __( 'Dashboard (ajax)', 'axle-read-only-mode' ),
			'rest'      => __( 'REST API / editor', 'axle-read-only-mode' ),
			'front-end' => __( 'Front end', 'axle-read-only-mode' ),
			'ability'   => __( 'Ability', 'axle-read-only-mode' ),
		);

		return $sources[ $source ] ?? $source;
	}

	/**
	 * Prints whether a logged request was blocked or allowed through.
	 *
	 * @param array $entry Log entry.
	 */
	public static function result_badge( array $entry ): void {
		if ( empty( $entry['allowed'] ) ) {
			self::badge( 'error', __( 'Blocked', 'axle-read-only-mode' ) );
			return;
		}

		self::badge( 'good', 'code' === ( $entry['via'] ?? '' ) ? __( 'Allowed by code', 'axle-read-only-mode' ) : __( 'Allowed', 'axle-read-only-mode' ) );
	}

	/**
	 * Prints recent freezes.
	 */
	private function render_history(): void {
		$entries = $this->history->all();
		$ended   = array(
			'user'    => __( 'Unfrozen', 'axle-read-only-mode' ),
			'cli'     => __( 'Unfrozen by WP-CLI', 'axle-read-only-mode' ),
			'expired' => __( 'Ended on time', 'axle-read-only-mode' ),
			'ability' => __( 'Unfrozen by an AI agent or app', 'axle-read-only-mode' ),
		);
		$format  = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<section class="axle-card aro-history">
			<h2 class="axle-card__title"><?php esc_html_e( 'Recent freezes', 'axle-read-only-mode' ); ?></h2>
			<?php if ( ! $entries ) : ?>
				<div class="axle-empty"><p><?php esc_html_e( 'No freezes yet. Each freeze is listed here once it ends, with how many attempts it blocked.', 'axle-read-only-mode' ); ?></p></div>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Started', 'axle-read-only-mode' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Length', 'axle-read-only-mode' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Scope', 'axle-read-only-mode' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Reason', 'axle-read-only-mode' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Ended', 'axle-read-only-mode' ); ?></th>
							<th scope="col" class="num"><?php esc_html_e( 'Blocked', 'axle-read-only-mode' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $entries as $entry ) : ?>
							<?php $by = get_userdata( (int) $entry['by'] ); ?>
							<tr>
								<td>
									<?php echo esc_html( wp_date( $format, (int) $entry['start'] ) ); ?><br>
									<span class="axle-muted"><?php echo esc_html( $by ? $by->display_name : ( BlockedLog::ERASED === (int) $entry['by'] ? __( '(removed)', 'axle-read-only-mode' ) : __( 'WP-CLI', 'axle-read-only-mode' ) ) ); ?></span>
								</td>
								<td><?php echo esc_html( human_time_diff( (int) $entry['start'], (int) $entry['end'] ) ); ?></td>
								<td><?php echo esc_html( Scopes::label( (string) ( $entry['scope'] ?? Scopes::ALL ) ) ); ?></td>
								<td><?php echo esc_html( '' !== $entry['reason'] ? $entry['reason'] : '-' ); ?></td>
								<td><?php echo esc_html( $ended[ $entry['ended_by'] ] ?? $entry['ended_by'] ); ?></td>
								<td class="num"><?php echo esc_html( number_format_i18n( (int) $entry['blocked'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Prints the deploy-script commands with Copy buttons.
	 */
	private function render_cli(): void {
		$commands = array(
			'wp axle-read-only-mode check --minutes=30',
			'wp axle-read-only-mode on --minutes=30 --reason="Moving servers"',
			'wp axle-read-only-mode on --minutes=30 --scope=content',
			'wp axle-read-only-mode off',
		);
		?>
		<section class="axle-card aro-cli">
			<h2 class="axle-card__title"><?php esc_html_e( 'From a deploy script', 'axle-read-only-mode' ); ?></h2>
			<p><?php esc_html_e( 'WP-CLI is never blocked, so scripts can freeze, import and unfreeze:', 'axle-read-only-mode' ); ?></p>
			<ul class="aro-commands">
				<?php foreach ( $commands as $command ) : ?>
					<li>
						<code class="axle-code"><?php echo esc_html( $command ); ?></code>
						<button type="button" class="button button-small axle-copy" data-aro-copy="<?php echo esc_attr( $command ); ?>" data-aro-copied="<?php esc_attr_e( 'Copied.', 'axle-read-only-mode' ); ?>"><?php esc_html_e( 'Copy', 'axle-read-only-mode' ); ?></button>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
	}

	/**
	 * Prints a one-button form that runs a task.
	 *
	 * @param string $task   Task name.
	 * @param string $label  Button text.
	 * @param string $style  primary or secondary.
	 * @param array  $fields Extra hidden fields.
	 */
	private function render_button( string $task, string $label, string $style, array $fields = array() ): void {
		Forms::button( admin_url( 'admin-post.php' ), self::ACTION, $task, $label, $style, $fields );
	}

	/**
	 * Prints the result of the last action.
	 */
	private function render_notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only value set by our own redirect.
		$done     = isset( $_GET['aro_done'] ) ? sanitize_key( wp_unslash( $_GET['aro_done'] ) ) : '';
		$messages = array(
			'frozen'    => __( 'The site is frozen.', 'axle-read-only-mode' ),
			'scheduled' => __( 'Freeze scheduled.', 'axle-read-only-mode' ),
			'unfrozen'  => __( 'The site is unfrozen.', 'axle-read-only-mode' ),
			'extended'  => __( 'Freeze extended.', 'axle-read-only-mode' ),
			'saved'     => __( 'Settings saved.', 'axle-read-only-mode' ),
			'added'     => __( 'Entry added to the allow-list.', 'axle-read-only-mode' ),
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only value set by our own redirect.
		$editing = isset( $_GET['aro_editing'] ) ? absint( $_GET['aro_editing'] ) : 0;

		if ( 'frozen' === $done && $editing ) {
			// The one-click freeze in the admin bar does not ask first, so it says afterwards who it interrupted.
			printf( '<div class="notice notice-warning is-dismissible"><p>%s</p></div>', esc_html( $messages['frozen'] . ' ' . Checks::editing_sentence( $editing ) ) );
		} elseif ( isset( $messages[ $done ] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $messages[ $done ] ) );
		} elseif ( 'saved_with_errors' === $done ) {
			printf( '<div class="notice notice-warning is-dismissible"><p>%s</p></div>', esc_html__( 'Settings saved, except the lines listed below.', 'axle-read-only-mode' ) );
		} elseif ( 'add_failed' === $done ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html__( 'That entry could not be added.', 'axle-read-only-mode' ) );
		}
	}

	/**
	 * Human length of a freeze.
	 *
	 * @param int $minutes Minutes, 0 for no end.
	 * @return string
	 */
	public static function duration_label( int $minutes ): string {
		if ( ! $minutes ) {
			return __( 'Until I unfreeze it', 'axle-read-only-mode' );
		}

		$hours = intdiv( $minutes, 60 );
		$rest  = $minutes % 60;
		/* translators: %d: minutes. */
		$minutes_label = sprintf( _n( '%d minute', '%d minutes', $rest, 'axle-read-only-mode' ), $rest );

		if ( ! $hours ) {
			return $minutes_label;
		}

		/* translators: %d: hours. */
		$hours_label = sprintf( _n( '%d hour', '%d hours', $hours, 'axle-read-only-mode' ), $hours );

		/* translators: 1: hours, e.g. "1 hour", 2: minutes, e.g. "30 minutes". */
		return $rest ? sprintf( __( '%1$s %2$s', 'axle-read-only-mode' ), $hours_label, $minutes_label ) : $hours_label;
	}

	/**
	 * Handles the freeze form, buttons and admin bar links.
	 */
	public function handle_action(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to freeze this site.', 'axle-read-only-mode' ), 403 );
		}

		check_admin_referer( self::ACTION );

		$task    = isset( $_REQUEST['task'] ) ? sanitize_key( wp_unslash( $_REQUEST['task'] ) ) : '';
		$minutes = isset( $_REQUEST['minutes'] ) ? absint( $_REQUEST['minutes'] ) : 0;
		$done    = '';
		$tab     = '';
		$editing = 0;

		switch ( $task ) {
			case 'save_settings':
			case 'add_allow':
				$done = SettingsView::handle( $this->settings );
				$tab  = 'settings';
				break;

			case 'unfreeze':
				$this->freezer->unfreeze( 'user' );
				$done = 'unfrozen';
				break;

			case 'extend':
				$done = $this->freezer->extend( $minutes ? $minutes : Freezer::extend_steps()[0] ) ? 'extended' : '';
				break;

			case 'quick_freeze':
				// One click, no question: counted before the freeze starts, and said in the notice after.
				$editing = count( $this->checks->editors( get_current_user_id() ) );
				$this->freezer->freeze( array( 'minutes' => $minutes ? $minutes : Freezer::default_duration() ) );
				$done = 'frozen';
				break;

			case 'freeze':
				$start = 0;
				if ( isset( $_POST['when'], $_POST['start'] ) && 'later' === $_POST['when'] ) {
					$start = self::parse_start( sanitize_text_field( wp_unslash( $_POST['start'] ) ) );
				}

				$this->freezer->freeze(
					array(
						'start'           => $start,
						'minutes'         => isset( $_POST['minutes'] ) ? absint( $_POST['minutes'] ) : Freezer::default_duration(),
						'reason'          => isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '',
						'visitor_message' => isset( $_POST['visitor_message'] ) ? sanitize_text_field( wp_unslash( $_POST['visitor_message'] ) ) : '',
						'visitors'        => ! empty( $_POST['visitors'] ),
						'cron'            => ! empty( $_POST['cron'] ),
						'exempt'          => ! empty( $_POST['exempt_me'] ) ? array( get_current_user_id() ) : array(),
						'area'            => isset( $_POST['freeze_scope'] ) ? sanitize_key( wp_unslash( $_POST['freeze_scope'] ) ) : Scopes::default_scope(),
					)
				);
				$done = $start > time() ? 'scheduled' : 'frozen';
				break;
		}

		$back = 'GET' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ? wp_get_referer() : false;
		wp_safe_redirect(
			$back ? $back : add_query_arg(
				array_filter(
					array(
						'aro_done'    => $done,
						'aro_editing' => $editing,
					) 
				),
				self::page_url( $tab ) 
			) 
		);
		exit;
	}
}
