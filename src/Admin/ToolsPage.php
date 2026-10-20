<?php
/**
 * Tools > Deploy Freeze.
 *
 * @package AryanX\DeployFreeze
 */

namespace AryanX\DeployFreeze\Admin;

use AryanX\DeployFreeze\BlockedLog;
use AryanX\DeployFreeze\Freezer;
use AryanX\DeployFreeze\History;
use AryanX\DeployFreeze\Service;
use AryanX\DeployFreeze\State;
use const AryanX\DeployFreeze\PLUGIN_FILE;
use const AryanX\DeployFreeze\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Freeze form, live status with countdown, blocked attempts and history.
 */
final class ToolsPage implements Service {

	public const SLUG   = 'deploy-freeze';
	public const ACTION = 'deploy_freeze';


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
		return (string) apply_filters( 'deploy_freeze_capability', 'manage_options' );
	}

	/**
	 * Registers Tools > Deploy Freeze.
	 */
	public function add_page(): void {
		$hook = add_management_page(
			__( 'Deploy Freeze', 'deploy-freeze' ),
			__( 'Deploy Freeze', 'deploy-freeze' ),
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
		wp_enqueue_style( 'deploy-freeze-admin', plugins_url( 'assets/admin.css', PLUGIN_FILE ), array(), VERSION );
		wp_enqueue_script( 'deploy-freeze-countdown', plugins_url( 'assets/countdown.js', PLUGIN_FILE ), array(), VERSION, true );
	}

	/**
	 * Prints the screen.
	 */
	public function render(): void {
		?>
		<div class="wrap df-page">
			<h1><?php esc_html_e( 'Deploy Freeze', 'deploy-freeze' ); ?></h1>
			<p class="df-intro"><?php esc_html_e( 'Freeze the site before you migrate it or deploy a database. Visitors can still read it, but nothing can be saved until the freeze ends, so nothing written in the meantime is lost when you switch over.', 'deploy-freeze' ); ?></p>

			<?php
			$this->render_notices();

			if ( $this->state->forced_by_constant() ) {
				printf( '<div class="notice notice-warning inline"><p>%s</p></div>', esc_html__( 'The site is frozen by the DEPLOY_FREEZE constant in wp-config.php. Remove it to unfreeze.', 'deploy-freeze' ) );
			} elseif ( $this->state->is_frozen() ) {
				$this->render_status();
			} elseif ( $this->state->is_scheduled() ) {
				$this->render_scheduled();
			} else {
				$this->render_form();
			}

			$this->render_blocked();
			?>

			<div class="df-columns">
				<?php $this->render_history(); ?>
				<div class="df-panel">
					<h2><?php esc_html_e( 'From a deploy script', 'deploy-freeze' ); ?></h2>
					<p><?php esc_html_e( 'WP-CLI is never blocked, so scripts can freeze, import and unfreeze:', 'deploy-freeze' ); ?></p>
					<pre class="df-code">wp deploy-freeze on --minutes=30 --reason="Moving servers"
# … migrate …
wp deploy-freeze off</pre>
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
		<section class="df-status">
			<div class="df-status__head">
				<span class="dashicons dashicons-lock" aria-hidden="true"></span>
				<h2><?php esc_html_e( 'The site is frozen', 'deploy-freeze' ); ?></h2>
				<?php if ( $state['end'] ) : ?>
					<span class="df-countdown" role="timer" data-df-end="<?php echo esc_attr( (string) $state['end'] ); ?>" data-df-label="<?php /* translators: %s: time left, e.g. 1:23:45. */ esc_attr_e( 'Ends in %s', 'deploy-freeze' ); ?>">
						<?php
						/* translators: %s: time the freeze ends. */
						echo esc_html( sprintf( __( 'Ends at %s', 'deploy-freeze' ), $this->state->end_time() ) );
						?>
					</span>
				<?php endif; ?>
			</div>

			<?php if ( '' !== $state['reason'] ) : ?>
				<p class="df-reason"><?php echo esc_html( $state['reason'] ); ?></p>
			<?php endif; ?>

			<ul class="df-facts">
				<li>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: start time, 2: user name. */
							__( 'Started %1$s by %2$s', 'deploy-freeze' ),
							wp_date( $format, (int) $state['start'] ),
							$by ? $by->display_name : __( 'WP-CLI', 'deploy-freeze' )
						)
					);
					?>
				</li>
				<li><?php echo esc_html( $state['end'] ? sprintf( /* translators: %s: time. */ __( 'Ends %s', 'deploy-freeze' ), $this->state->end_time() ) : __( 'No end time: lasts until you unfreeze', 'deploy-freeze' ) ); ?></li>
				<li><?php echo esc_html( $state['visitors'] ? __( 'Visitor submissions paused', 'deploy-freeze' ) : __( 'Visitor submissions still open', 'deploy-freeze' ) ); ?></li>
				<li><?php echo esc_html( $state['cron'] ? __( 'Scheduled tasks paused', 'deploy-freeze' ) : __( 'Scheduled tasks still running', 'deploy-freeze' ) ); ?></li>
				<?php if ( $exempt ) : ?>
					<li>
						<?php
						/* translators: %s: user names. */
						echo esc_html( sprintf( __( 'Can still edit: %s', 'deploy-freeze' ), implode( ', ', wp_list_pluck( $exempt, 'display_name' ) ) ) );
						?>
					</li>
				<?php endif; ?>
			</ul>

			<div class="df-actions">
				<?php
				$this->render_button( 'unfreeze', __( 'Unfreeze now', 'deploy-freeze' ), 'primary' );
				if ( $state['end'] ) {
					foreach ( Freezer::extend_steps() as $step ) {
						/* translators: %s: length, e.g. "30 minutes". */
						$this->render_button( 'extend', sprintf( __( '+%s', 'deploy-freeze' ), self::duration_label( $step ) ), 'secondary', array( 'minutes' => $step ) );
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
		<section class="df-status is-scheduled">
			<div class="df-status__head">
				<span class="dashicons dashicons-clock" aria-hidden="true"></span>
				<h2>
					<?php
					/* translators: %s: date and time the freeze starts. */
					echo esc_html( sprintf( __( 'Freeze scheduled for %s', 'deploy-freeze' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $state['start'] ) ) );
					?>
				</h2>
			</div>
			<?php if ( '' !== $state['reason'] ) : ?>
				<p class="df-reason"><?php echo esc_html( $state['reason'] ); ?></p>
			<?php endif; ?>
			<div class="df-actions"><?php $this->render_button( 'unfreeze', __( 'Cancel scheduled freeze', 'deploy-freeze' ), 'secondary' ); ?></div>
		</section>
		<?php
	}

	/**
	 * Prints the form that starts a freeze.
	 */
	private function render_form(): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="df-panel df-form">
			<h2><?php esc_html_e( 'Freeze the site', 'deploy-freeze' ); ?></h2>
			<?php wp_nonce_field( self::ACTION ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="task" value="freeze">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="df-reason"><?php esc_html_e( 'Reason', 'deploy-freeze' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" id="df-reason" name="reason" placeholder="<?php esc_attr_e( 'Moving to the new server', 'deploy-freeze' ); ?>">
						<p class="description"><?php esc_html_e( 'Shown to logged-in users who try to save something.', 'deploy-freeze' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="df-visitor-message"><?php esc_html_e( 'Message for visitors', 'deploy-freeze' ); ?></label></th>
					<td>
						<input type="text" class="large-text" id="df-visitor-message" name="visitor_message" placeholder="<?php echo esc_attr( $this->state->visitor_message() ); ?>">
						<p class="description"><?php esc_html_e( 'Shown when a visitor sends a comment, form or order. Leave empty for the default.', 'deploy-freeze' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Start', 'deploy-freeze' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Start', 'deploy-freeze' ); ?></legend>
							<label><input type="radio" name="when" value="now" checked> <?php esc_html_e( 'Now', 'deploy-freeze' ); ?></label><br>
							<label><input type="radio" name="when" value="later"> <?php esc_html_e( 'At', 'deploy-freeze' ); ?></label>
							<label class="screen-reader-text" for="df-start"><?php esc_html_e( 'Start time', 'deploy-freeze' ); ?></label>
							<input type="datetime-local" id="df-start" name="start">
							<p class="description">
								<?php
								/* translators: %s: site time zone. */
								echo esc_html( sprintf( __( 'Site time zone: %s', 'deploy-freeze' ), wp_timezone_string() ) );
								?>
							</p>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="df-minutes"><?php esc_html_e( 'End after', 'deploy-freeze' ); ?></label></th>
					<td>
						<select id="df-minutes" name="minutes">
							<?php foreach ( Freezer::durations() as $minutes ) : ?>
								<option value="<?php echo esc_attr( (string) $minutes ); ?>" <?php selected( Freezer::default_duration(), $minutes ); ?>><?php echo esc_html( self::duration_label( $minutes ) ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'With an end time, a failed deploy cannot leave the site frozen.', 'deploy-freeze' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Also pause', 'deploy-freeze' ); ?></th>
					<td>
						<label><input type="checkbox" name="visitors" value="1" checked> <?php esc_html_e( 'Visitor submissions: comments, contact forms, checkouts and sign-ups', 'deploy-freeze' ); ?></label><br>
						<label><input type="checkbox" name="cron" value="1" checked> <?php esc_html_e( 'Scheduled tasks (they run when the freeze ends)', 'deploy-freeze' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Exempt', 'deploy-freeze' ); ?></th>
					<td><label><input type="checkbox" name="exempt_me" value="1"> <?php esc_html_e( 'Let me keep editing', 'deploy-freeze' ); ?></label></td>
				</tr>
			</table>
			<?php submit_button( __( 'Freeze the site', 'deploy-freeze' ) ); ?>
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
			'admin'     => __( 'Dashboard', 'deploy-freeze' ),
			'ajax'      => __( 'Dashboard (ajax)', 'deploy-freeze' ),
			'rest'      => __( 'REST API / editor', 'deploy-freeze' ),
			'front-end' => __( 'Front end', 'deploy-freeze' ),
		);
		?>
		<section class="df-panel df-blocked">
			<h2>
				<?php
				$title = $this->state->is_frozen()
					/* translators: %d: number of blocked attempts. */
					? __( 'Blocked during this freeze (%d)', 'deploy-freeze' )
					/* translators: %d: number of blocked attempts. */
					: __( 'Blocked during the last freeze (%d)', 'deploy-freeze' );
				echo esc_html( sprintf( $title, count( $entries ) ) );
				?>
			</h2>
			<?php if ( ! $entries ) : ?>
				<p><?php esc_html_e( 'Nothing yet. Attempts to save, upload, comment or submit a form will be listed here, so you know what to redo on the new server.', 'deploy-freeze' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'When', 'deploy-freeze' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Who', 'deploy-freeze' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Where', 'deploy-freeze' ); ?></th>
							<th scope="col"><?php esc_html_e( 'What was blocked', 'deploy-freeze' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $entries as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( wp_date( get_option( 'time_format' ), (int) $entry['time'] ) ); ?></td>
								<td><?php echo esc_html( '' !== BlockedLog::who( $entry ) ? BlockedLog::who( $entry ) : __( 'Visitor', 'deploy-freeze' ) ); ?></td>
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
			'user'    => __( 'Unfrozen', 'deploy-freeze' ),
			'cli'     => __( 'Unfrozen by WP-CLI', 'deploy-freeze' ),
			'expired' => __( 'Ended on time', 'deploy-freeze' ),
		);
		$format  = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<section class="df-panel df-history">
			<h2><?php esc_html_e( 'Recent freezes', 'deploy-freeze' ); ?></h2>
			<?php if ( ! $entries ) : ?>
				<p><?php esc_html_e( 'No freezes yet.', 'deploy-freeze' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Started', 'deploy-freeze' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Length', 'deploy-freeze' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Reason', 'deploy-freeze' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Ended', 'deploy-freeze' ); ?></th>
							<th scope="col" class="num"><?php esc_html_e( 'Blocked', 'deploy-freeze' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $entries as $entry ) : ?>
							<?php $by = get_userdata( (int) $entry['by'] ); ?>
							<tr>
								<td>
									<?php echo esc_html( wp_date( $format, (int) $entry['start'] ) ); ?><br>
									<span class="df-muted"><?php echo esc_html( $by ? $by->display_name : ( BlockedLog::ERASED === (int) $entry['by'] ? __( '(removed)', 'deploy-freeze' ) : __( 'WP-CLI', 'deploy-freeze' ) ) ); ?></span>
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
		$done     = isset( $_GET['df_done'] ) ? sanitize_key( wp_unslash( $_GET['df_done'] ) ) : '';
		$messages = array(
			'frozen'    => __( 'The site is frozen.', 'deploy-freeze' ),
			'scheduled' => __( 'Freeze scheduled.', 'deploy-freeze' ),
			'unfrozen'  => __( 'The site is unfrozen.', 'deploy-freeze' ),
			'extended'  => __( 'Freeze extended.', 'deploy-freeze' ),
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
			return __( 'Until I unfreeze it', 'deploy-freeze' );
		}

		$hours = intdiv( $minutes, 60 );
		$rest  = $minutes % 60;
		/* translators: %d: minutes. */
		$minutes_label = sprintf( _n( '%d minute', '%d minutes', $rest, 'deploy-freeze' ), $rest );

		if ( ! $hours ) {
			return $minutes_label;
		}

		/* translators: %d: hours. */
		$hours_label = sprintf( _n( '%d hour', '%d hours', $hours, 'deploy-freeze' ), $hours );

		/* translators: 1: hours, e.g. "1 hour", 2: minutes, e.g. "30 minutes". */
		return $rest ? sprintf( __( '%1$s %2$s', 'deploy-freeze' ), $hours_label, $minutes_label ) : $hours_label;
	}

	/**
	 * Handles the freeze form, buttons and admin bar links.
	 */
	public function handle_action(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to freeze this site.', 'deploy-freeze' ), 403 );
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
		wp_safe_redirect( $back ? $back : add_query_arg( array_filter( array( 'df_done' => $done ) ), admin_url( 'tools.php?page=' . self::SLUG ) ) );
		exit;
	}
}
