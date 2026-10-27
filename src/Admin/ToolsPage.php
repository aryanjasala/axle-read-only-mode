<?php
/**
 * Tools > Axle Read-Only Mode.
 *
 * @package AryanX\AxleReadOnlyMode
 */

namespace AryanX\AxleReadOnlyMode\Admin;

use AryanX\AxleReadOnlyMode\BlockedLog;
use AryanX\AxleReadOnlyMode\Freezer;
use AryanX\AxleReadOnlyMode\History;
use AryanX\AxleReadOnlyMode\Service;
use AryanX\AxleReadOnlyMode\State;
use const AryanX\AxleReadOnlyMode\PLUGIN_FILE;
use const AryanX\AxleReadOnlyMode\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Freeze form, live status with countdown, blocked attempts and history.
 */
final class ToolsPage implements Service {

	public const SLUG   = 'axle-read-only-mode';
	public const ACTION = 'axle_read_only_mode';


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
	 * Sets up the screen.
	 *
	 * @param State      $state   Saved freeze.
	 * @param Freezer    $freezer Starts, extends and ends freezes.
	 * @param BlockedLog $log     Blocked attempts.
	 * @param History    $history Past freezes.
	 */
	public function __construct( State $state, Freezer $freezer, BlockedLog $log, History $history ) {
		$this->state   = $state;
		$this->freezer = $freezer;
		$this->log     = $log;
		$this->history = $history;
	}

	/**
	 * Adds the menu entry and the action handler.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_action' ) );
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
	 * Registers Tools > Axle Read-Only Mode.
	 */
	public function add_page(): void {
		$hook = add_management_page(
			__( 'Axle Read-Only Mode', 'axle-read-only-mode' ),
			__( 'Axle Read-Only Mode', 'axle-read-only-mode' ),
			self::capability(),
			self::SLUG,
			array( $this, 'render' )
		);

		if ( $hook ) {
			add_action( "admin_print_styles-{$hook}", array( $this, 'enqueue_assets' ) );
		}
	}

	/**
	 * Screen styles and the countdown script.
	 */
	public function enqueue_assets(): void {
		wp_enqueue_style( 'axle-read-only-mode-admin', plugins_url( 'assets/admin.css', PLUGIN_FILE ), array(), VERSION );
		wp_enqueue_script( 'axle-read-only-mode-countdown', plugins_url( 'assets/countdown.js', PLUGIN_FILE ), array(), VERSION, true );
	}

	/**
	 * Prints the screen.
	 */
	public function render(): void {
		?>
		<div class="wrap aro-page">
			<h1><?php esc_html_e( 'Axle Read-Only Mode', 'axle-read-only-mode' ); ?></h1>
			<p class="aro-intro"><?php esc_html_e( 'Freeze the site before you migrate it or deploy a database. Visitors can still read it, but nothing can be saved until the freeze ends, so nothing written in the meantime is lost when you switch over.', 'axle-read-only-mode' ); ?></p>

			<?php
			$this->render_notices();

			if ( $this->state->forced_by_constant() ) {
				printf( '<div class="notice notice-warning inline"><p>%s</p></div>', esc_html__( 'The site is frozen by the AXLE_READ_ONLY_MODE constant in wp-config.php. Remove it to unfreeze.', 'axle-read-only-mode' ) );
			} elseif ( $this->state->is_frozen() ) {
				$this->render_status();
			} elseif ( $this->state->is_scheduled() ) {
				$this->render_scheduled();
			} else {
				$this->render_form();
			}

			$this->render_blocked();
			?>

			<div class="aro-columns">
				<?php $this->render_history(); ?>
				<div class="aro-panel">
					<h2><?php esc_html_e( 'From a deploy script', 'axle-read-only-mode' ); ?></h2>
					<p><?php esc_html_e( 'WP-CLI is never blocked, so scripts can freeze, import and unfreeze:', 'axle-read-only-mode' ); ?></p>
					<pre class="aro-code">wp axle-read-only-mode on --minutes=30 --reason="Moving servers"
# … migrate …
wp axle-read-only-mode off</pre>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Prints the live status of the current freeze.
	 */
	private function render_status(): void {
		$state  = $this->state->all();
		$by     = get_userdata( (int) $state['by'] );
		$format = get_option( 'time_format' );
		$exempt = array_filter( array_map( 'get_userdata', array_map( 'intval', $state['exempt'] ) ) );
		?>
		<section class="aro-status">
			<div class="aro-status__head">
				<span class="dashicons dashicons-lock" aria-hidden="true"></span>
				<h2><?php esc_html_e( 'The site is frozen', 'axle-read-only-mode' ); ?></h2>
				<?php if ( $state['end'] ) : ?>
					<span class="aro-countdown" role="timer" data-aro-end="<?php echo esc_attr( (string) $state['end'] ); ?>" data-aro-label="<?php /* translators: %s: time left, e.g. 1:23:45. */ esc_attr_e( 'Ends in %s', 'axle-read-only-mode' ); ?>">
						<?php
						/* translators: %s: time the freeze ends. */
						echo esc_html( sprintf( __( 'Ends at %s', 'axle-read-only-mode' ), $this->state->end_time() ) );
						?>
					</span>
				<?php endif; ?>
			</div>

			<?php if ( '' !== $state['reason'] ) : ?>
				<p class="aro-reason"><?php echo esc_html( $state['reason'] ); ?></p>
			<?php endif; ?>

			<ul class="aro-facts">
				<li>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: start time, 2: user name. */
							__( 'Started %1$s by %2$s', 'axle-read-only-mode' ),
							wp_date( $format, (int) $state['start'] ),
							$by ? $by->display_name : __( 'WP-CLI', 'axle-read-only-mode' )
						)
					);
					?>
				</li>
				<li><?php echo esc_html( $state['end'] ? sprintf( /* translators: %s: time. */ __( 'Ends %s', 'axle-read-only-mode' ), $this->state->end_time() ) : __( 'No end time: lasts until you unfreeze', 'axle-read-only-mode' ) ); ?></li>
				<li><?php echo esc_html( $state['visitors'] ? __( 'Visitor submissions paused', 'axle-read-only-mode' ) : __( 'Visitor submissions still open', 'axle-read-only-mode' ) ); ?></li>
				<li><?php echo esc_html( $state['cron'] ? __( 'Scheduled tasks paused', 'axle-read-only-mode' ) : __( 'Scheduled tasks still running', 'axle-read-only-mode' ) ); ?></li>
				<?php if ( $exempt ) : ?>
					<li>
						<?php
						/* translators: %s: user names. */
						echo esc_html( sprintf( __( 'Can still edit: %s', 'axle-read-only-mode' ), implode( ', ', wp_list_pluck( $exempt, 'display_name' ) ) ) );
						?>
					</li>
				<?php endif; ?>
			</ul>

			<div class="aro-actions">
				<?php
				$this->render_button( 'unfreeze', __( 'Unfreeze now', 'axle-read-only-mode' ), 'primary' );
				if ( $state['end'] ) {
					foreach ( Freezer::extend_steps() as $step ) {
						/* translators: %s: length, e.g. "30 minutes". */
						$this->render_button( 'extend', sprintf( __( '+%s', 'axle-read-only-mode' ), self::duration_label( $step ) ), 'secondary', array( 'minutes' => $step ) );
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
		$state = $this->state->all();
		?>
		<section class="aro-status is-scheduled">
			<div class="aro-status__head">
				<span class="dashicons dashicons-clock" aria-hidden="true"></span>
				<h2>
					<?php
					/* translators: %s: date and time the freeze starts. */
					echo esc_html( sprintf( __( 'Freeze scheduled for %s', 'axle-read-only-mode' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $state['start'] ) ) );
					?>
				</h2>
			</div>
			<?php if ( '' !== $state['reason'] ) : ?>
				<p class="aro-reason"><?php echo esc_html( $state['reason'] ); ?></p>
			<?php endif; ?>
			<div class="aro-actions"><?php $this->render_button( 'unfreeze', __( 'Cancel scheduled freeze', 'axle-read-only-mode' ), 'secondary' ); ?></div>
		</section>
		<?php
	}

	/**
	 * Prints the form that starts a freeze.
	 */
	private function render_form(): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aro-panel aro-form">
			<h2><?php esc_html_e( 'Freeze the site', 'axle-read-only-mode' ); ?></h2>
			<?php wp_nonce_field( self::ACTION ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="task" value="freeze">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="aro-reason"><?php esc_html_e( 'Reason', 'axle-read-only-mode' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" id="aro-reason" name="reason" placeholder="<?php esc_attr_e( 'Moving to the new server', 'axle-read-only-mode' ); ?>">
						<p class="description"><?php esc_html_e( 'Shown to logged-in users who try to save something.', 'axle-read-only-mode' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aro-visitor-message"><?php esc_html_e( 'Message for visitors', 'axle-read-only-mode' ); ?></label></th>
					<td>
						<input type="text" class="large-text" id="aro-visitor-message" name="visitor_message" placeholder="<?php echo esc_attr( $this->state->visitor_message() ); ?>">
						<p class="description"><?php esc_html_e( 'Shown when a visitor sends a comment, form or order. Leave empty for the default.', 'axle-read-only-mode' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Start', 'axle-read-only-mode' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Start', 'axle-read-only-mode' ); ?></legend>
							<label><input type="radio" name="when" value="now" checked> <?php esc_html_e( 'Now', 'axle-read-only-mode' ); ?></label><br>
							<label><input type="radio" name="when" value="later"> <?php esc_html_e( 'At', 'axle-read-only-mode' ); ?></label>
							<label class="screen-reader-text" for="aro-start"><?php esc_html_e( 'Start time', 'axle-read-only-mode' ); ?></label>
							<input type="datetime-local" id="aro-start" name="start">
							<p class="description">
								<?php
								/* translators: %s: site time zone. */
								echo esc_html( sprintf( __( 'Site time zone: %s', 'axle-read-only-mode' ), wp_timezone_string() ) );
								?>
							</p>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aro-minutes"><?php esc_html_e( 'End after', 'axle-read-only-mode' ); ?></label></th>
					<td>
						<select id="aro-minutes" name="minutes">
							<?php foreach ( Freezer::durations() as $minutes ) : ?>
								<option value="<?php echo esc_attr( (string) $minutes ); ?>" <?php selected( Freezer::default_duration(), $minutes ); ?>><?php echo esc_html( self::duration_label( $minutes ) ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'With an end time, a failed deploy cannot leave the site frozen.', 'axle-read-only-mode' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Also pause', 'axle-read-only-mode' ); ?></th>
					<td>
						<label><input type="checkbox" name="visitors" value="1" checked> <?php esc_html_e( 'Visitor submissions: comments, contact forms, checkouts and sign-ups', 'axle-read-only-mode' ); ?></label><br>
						<label><input type="checkbox" name="cron" value="1" checked> <?php esc_html_e( 'Scheduled tasks (they run when the freeze ends)', 'axle-read-only-mode' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Exempt', 'axle-read-only-mode' ); ?></th>
					<td><label><input type="checkbox" name="exempt_me" value="1"> <?php esc_html_e( 'Let me keep editing', 'axle-read-only-mode' ); ?></label></td>
				</tr>
			</table>
			<?php submit_button( __( 'Freeze the site', 'axle-read-only-mode' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Prints what was blocked during the current or last freeze.
	 */
	private function render_blocked(): void {
		$entries = $this->log->all();
		if ( ! $entries && ! $this->state->is_frozen() ) {
			return;
		}

		$sources = array(
			'admin'     => __( 'Dashboard', 'axle-read-only-mode' ),
			'ajax'      => __( 'Dashboard (ajax)', 'axle-read-only-mode' ),
			'rest'      => __( 'REST API / editor', 'axle-read-only-mode' ),
			'front-end' => __( 'Front end', 'axle-read-only-mode' ),
		);
		?>
		<section class="aro-panel aro-blocked">
			<h2>
				<?php
				$title = $this->state->is_frozen()
					/* translators: %d: number of blocked attempts. */
					? __( 'Blocked during this freeze (%d)', 'axle-read-only-mode' )
					/* translators: %d: number of blocked attempts. */
					: __( 'Blocked during the last freeze (%d)', 'axle-read-only-mode' );
				echo esc_html( sprintf( $title, count( $entries ) ) );
				?>
			</h2>
			<?php if ( ! $entries ) : ?>
				<p><?php esc_html_e( 'Nothing yet. Attempts to save, upload, comment or submit a form will be listed here, so you know what to redo on the new server.', 'axle-read-only-mode' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'When', 'axle-read-only-mode' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Who', 'axle-read-only-mode' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Where', 'axle-read-only-mode' ); ?></th>
							<th scope="col"><?php esc_html_e( 'What was blocked', 'axle-read-only-mode' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $entries as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( wp_date( get_option( 'time_format' ), (int) $entry['time'] ) ); ?></td>
								<td><?php echo esc_html( '' !== BlockedLog::who( $entry ) ? BlockedLog::who( $entry ) : __( 'Visitor', 'axle-read-only-mode' ) ); ?></td>
								<td><?php echo esc_html( $sources[ $entry['source'] ] ?? $entry['source'] ); ?></td>
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
		<section class="aro-panel aro-history">
			<h2><?php esc_html_e( 'Recent freezes', 'axle-read-only-mode' ); ?></h2>
			<?php if ( ! $entries ) : ?>
				<p><?php esc_html_e( 'No freezes yet.', 'axle-read-only-mode' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Started', 'axle-read-only-mode' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Length', 'axle-read-only-mode' ); ?></th>
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
									<span class="aro-muted"><?php echo esc_html( $by ? $by->display_name : ( BlockedLog::ERASED === (int) $entry['by'] ? __( '(removed)', 'axle-read-only-mode' ) : __( 'WP-CLI', 'axle-read-only-mode' ) ) ); ?></span>
								</td>
								<td><?php echo esc_html( human_time_diff( (int) $entry['start'], (int) $entry['end'] ) ); ?></td>
								<td><?php echo esc_html( '' !== $entry['reason'] ? $entry['reason'] : '—' ); ?></td>
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
	 * Prints a one-button form that runs a task.
	 *
	 * @param string $task   Task name.
	 * @param string $label  Button text.
	 * @param string $style  primary or secondary.
	 * @param array  $fields Extra hidden fields.
	 */
	private function render_button( string $task, string $label, string $style, array $fields = array() ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::ACTION ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="task" value="<?php echo esc_attr( $task ); ?>">
			<?php foreach ( $fields as $name => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>">
			<?php endforeach; ?>
			<button type="submit" class="button button-<?php echo esc_attr( $style ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
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
		);

		if ( isset( $messages[ $done ] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $messages[ $done ] ) );
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

		switch ( $task ) {
			case 'unfreeze':
				$this->freezer->unfreeze( 'user' );
				$done = 'unfrozen';
				break;

			case 'extend':
				$done = $this->freezer->extend( $minutes ? $minutes : Freezer::extend_steps()[0] ) ? 'extended' : '';
				break;

			case 'quick_freeze':
				$this->freezer->freeze( array( 'minutes' => $minutes ? $minutes : Freezer::default_duration() ) );
				$done = 'frozen';
				break;

			case 'freeze':
				$start = 0;
				if ( isset( $_POST['when'], $_POST['start'] ) && 'later' === $_POST['when'] && '' !== $_POST['start'] ) {
					try {
						$start = ( new \DateTimeImmutable( sanitize_text_field( wp_unslash( $_POST['start'] ) ), wp_timezone() ) )->getTimestamp();
					} catch ( \Exception $e ) {
						$start = 0;
					}
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
					)
				);
				$done = $start > time() ? 'scheduled' : 'frozen';
				break;
		}

		$back = 'GET' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ? wp_get_referer() : false;
		wp_safe_redirect( $back ? $back : add_query_arg( array_filter( array( 'aro_done' => $done ) ), admin_url( 'tools.php?page=' . self::SLUG ) ) );
		exit;
	}
}
