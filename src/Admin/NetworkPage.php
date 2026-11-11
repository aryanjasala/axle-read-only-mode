<?php
/**
 * Network Admin > Settings > Read-Only Mode.
 *
 * @package AryanX\Axle\ReadOnlyMode
 */

namespace AryanX\Axle\ReadOnlyMode\Admin;

use AryanX\Axle\ReadOnlyMode\BlockedLog;
use AryanX\Axle\ReadOnlyMode\Freezer;
use AryanX\Axle\ReadOnlyMode\Network;
use AryanX\Axle\ReadOnlyMode\Scopes;
use AryanX\Axle\ReadOnlyMode\Service;
use AryanX\Axle\ReadOnlyMode\Settings;
use const AryanX\Axle\ReadOnlyMode\PLUGIN_FILE;
use const AryanX\Axle\ReadOnlyMode\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Freezes the whole network, or chosen sites, and shows what was blocked on each.
 *
 * Site admins can still freeze their own site from Tools. A network freeze
 * applies on every site it covers and cannot be lifted from a site.
 */
final class NetworkPage implements Service {

	public const SLUG   = 'axle-read-only-mode';
	public const ACTION = 'axle_read_only_mode_network';

	/**
	 * The network's freeze, history and log.
	 *
	 * @var Network
	 */
	private Network $network;

	/**
	 * Sets up the screen.
	 *
	 * @param Network $network The network's freeze, history and log.
	 */
	public function __construct( Network $network ) {
		$this->network = $network;
	}

	/**
	 * Adds the menu entry and the action handler.
	 */
	public function register(): void {
		add_action( 'network_admin_menu', array( $this, 'add_page' ) );
		add_action( 'network_admin_edit_' . self::ACTION, array( $this, 'handle_action' ) );
	}

	/**
	 * Capability needed to freeze and unfreeze the network.
	 *
	 * @return string
	 */
	public static function capability(): string {
		/**
		 * Filters the capability needed to freeze and unfreeze the network.
		 *
		 * @param string $capability Default manage_network_options.
		 */
		return (string) apply_filters( 'axle_read_only_mode_network_capability', 'manage_network_options' );
	}

	/**
	 * The screen's address.
	 *
	 * @return string
	 */
	public static function page_url(): string {
		return network_admin_url( 'settings.php?page=' . self::SLUG );
	}

	/**
	 * Where network forms and links post to.
	 *
	 * @return string
	 */
	public static function post_url(): string {
		return network_admin_url( 'edit.php?action=' . self::ACTION );
	}

	/**
	 * URL that runs a network freeze action from a link.
	 *
	 * @param string $task    unfreeze or extend.
	 * @param int    $minutes Minutes, for extend.
	 * @return string
	 */
	public static function action_url( string $task, int $minutes = 0 ): string {
		return wp_nonce_url(
			add_query_arg(
				array_filter(
					array(
						'task'    => $task,
						'minutes' => $minutes,
					)
				),
				self::post_url()
			),
			self::ACTION
		);
	}

	/**
	 * Registers Network Admin > Settings > Read-Only Mode.
	 */
	public function add_page(): void {
		$hook = add_submenu_page(
			'settings.php',
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
	 * Shared styles, screen styles and the countdown script.
	 */
	public function enqueue_assets(): void {
		AxleUi::enqueue();
		wp_enqueue_style( 'axle-read-only-mode-admin', plugins_url( 'assets/admin.css', PLUGIN_FILE ), array( AxleUi::HANDLE ), VERSION );
		wp_enqueue_script( 'axle-read-only-mode-admin', plugins_url( 'assets/admin.js', PLUGIN_FILE ), array( 'wp-a11y' ), VERSION, true );
	}

	/**
	 * Prints the screen.
	 */
	public function render(): void {
		$state     = $this->network->state;
		$frozen    = $state->is_running();
		$scheduled = $state->is_scheduled();

		if ( $frozen ) {
			$badge = array( 'warning', Scopes::status( $state->scope() ) );
		} elseif ( $scheduled ) {
			$badge = array( 'info', __( 'Freeze scheduled', 'axle-read-only-mode' ) );
		} else {
			$badge = array( 'neutral', __( 'Not frozen', 'axle-read-only-mode' ) );
		}
		?>
		<div class="wrap axle-ui aro-page">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Read-Only Mode', 'axle-read-only-mode' ); ?></h1>
			<span class="axle-status"><?php ToolsPage::badge( $badge[0], $badge[1] ); ?></span>
			<hr class="wp-header-end">
			<?php ToolsPage::render_tabs( self::page_url(), ToolsPage::current_tab() ); ?>

			<?php
			if ( 'settings' === ToolsPage::current_tab() ) {
				$this->render_settings_tab();
			} else {
				$this->render_freeze_tab( $frozen, $scheduled );
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
		<p class="axle-intro"><?php esc_html_e( 'Choose what keeps working on every site while the network is frozen.', 'axle-read-only-mode' ); ?></p>
		<?php
		$this->render_notices();
		SettingsView::render( $this->network->settings, self::post_url(), self::ACTION );
	}

	/**
	 * Prints the freeze tab.
	 *
	 * @param bool $frozen    Whether the network freeze is running.
	 * @param bool $scheduled Whether one is set to start later.
	 */
	private function render_freeze_tab( bool $frozen, bool $scheduled ): void {
		?>
		<p class="axle-intro"><?php esc_html_e( 'Freeze every site on the network, or chosen sites, before you migrate or deploy. A network freeze applies on each site it covers and cannot be lifted from a site. Site admins can still freeze their own site from Tools.', 'axle-read-only-mode' ); ?></p>

		<?php
		$this->render_notices();

		if ( ! is_plugin_active_for_network( plugin_basename( PLUGIN_FILE ) ) ) {
			printf( '<div class="notice notice-warning inline"><p>%s</p></div>', esc_html__( 'This plugin is not network-activated. A network freeze only applies on sites where it is active.', 'axle-read-only-mode' ) );
		}

		if ( $this->network->state->forced_by_constant() ) {
			printf( '<div class="notice notice-warning inline"><p>%s</p></div>', esc_html__( 'Every site is frozen by the AXLE_READ_ONLY_MODE constant in wp-config.php. Remove it to unfreeze.', 'axle-read-only-mode' ) );
		}

		if ( $frozen ) {
			$this->render_status();
		} elseif ( $scheduled ) {
			$this->render_scheduled();
		} else {
			$this->render_form();
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
	 * Words for the sites a network freeze covers.
	 *
	 * @return string
	 */
	private function scope_label(): string {
		$own = $this->network->state->own();
		if ( 'sites' !== ( $own['scope'] ?? 'all' ) ) {
			return __( 'Every site on the network', 'axle-read-only-mode' );
		}

		$names = array();
		foreach ( (array) ( $own['sites'] ?? array() ) as $site_id ) {
			$name    = (string) get_blog_option( (int) $site_id, 'blogname', '' );
			$names[] = '' !== $name ? $name : '#' . (int) $site_id;
		}

		/* translators: 1: number of sites, 2: their names. */
		return sprintf( _n( '%1$d site: %2$s', '%1$d sites: %2$s', count( $names ), 'axle-read-only-mode' ), count( $names ), implode( ', ', $names ) );
	}

	/**
	 * Prints the live status of the network freeze.
	 */
	private function render_status(): void {
		$state  = $this->network->state->own();
		$by     = get_userdata( (int) $state['by'] );
		$format = get_option( 'time_format' );
		$exempt = array_filter( array_map( 'get_userdata', array_map( 'intval', $state['exempt'] ) ) );
		?>
		<section class="axle-card aro-status">
			<div class="axle-card__head">
				<h2 class="axle-card__title"><?php esc_html_e( 'The network is frozen', 'axle-read-only-mode' ); ?></h2>
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
				<dt><?php esc_html_e( 'Covers', 'axle-read-only-mode' ); ?></dt>
				<dd><?php echo esc_html( $this->scope_label() ); ?></dd>
				<dt><?php esc_html_e( 'Scope', 'axle-read-only-mode' ); ?></dt>
				<dd><?php echo esc_html( Scopes::label( $this->network->state->scope() ) ); ?></dd>
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
				<dt><?php esc_html_e( 'Visitor submissions', 'axle-read-only-mode' ); ?></dt>
				<dd><?php echo esc_html( ToolsPage::visitors_label( (bool) $state['visitors'], $this->network->state->scope() ) ); ?></dd>
				<dt><?php esc_html_e( 'Scheduled tasks', 'axle-read-only-mode' ); ?></dt>
				<dd><?php echo esc_html( ToolsPage::cron_label( (bool) $state['cron'], $this->network->state->scope() ) ); ?></dd>
				<?php if ( $exempt ) : ?>
					<dt><?php esc_html_e( 'Can still edit', 'axle-read-only-mode' ); ?></dt>
					<dd><?php echo esc_html( implode( ', ', wp_list_pluck( $exempt, 'display_name' ) ) ); ?></dd>
				<?php endif; ?>
			</dl>

			<div class="axle-actions">
				<?php
				$this->render_button( 'unfreeze', __( 'Unfreeze the network', 'axle-read-only-mode' ), 'primary' );
				if ( $state['end'] ) {
					foreach ( Freezer::extend_steps() as $step ) {
						/* translators: %s: length, e.g. "30 minutes". */
						$this->render_button( 'extend', sprintf( __( 'Add %s', 'axle-read-only-mode' ), ToolsPage::duration_label( $step ) ), 'secondary', array( 'minutes' => $step ) );
					}
				}
				?>
			</div>
		</section>
		<?php
	}

	/**
	 * Prints a network freeze that has not started yet.
	 */
	private function render_scheduled(): void {
		$state = $this->network->state->own();
		?>
		<section class="axle-card aro-status is-scheduled">
			<div class="axle-card__head">
				<h2 class="axle-card__title">
					<?php
					/* translators: %s: date and time the freeze starts. */
					echo esc_html( sprintf( __( 'Network freeze scheduled for %s', 'axle-read-only-mode' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $state['start'] ) ) );
					?>
				</h2>
				<?php ToolsPage::badge( 'info', __( 'Scheduled', 'axle-read-only-mode' ) ); ?>
			</div>
			<?php if ( '' !== $state['reason'] ) : ?>
				<p class="aro-reason"><?php echo esc_html( $state['reason'] ); ?></p>
			<?php endif; ?>
			<p><?php echo esc_html( $this->scope_label() ); ?></p>
			<div class="axle-actions"><?php $this->render_button( 'unfreeze', __( 'Cancel scheduled freeze', 'axle-read-only-mode' ), 'secondary' ); ?></div>
		</section>
		<?php
	}

	/**
	 * Prints the form that starts a network freeze.
	 */
	private function render_form(): void {
		Forms::freeze(
			self::post_url(),
			self::ACTION,
			__( 'Freeze the network', 'axle-read-only-mode' ),
			$this->network->state->visitor_message(),
			array( $this, 'render_scope_rows' )
		);
	}

	/**
	 * Most sites the "chosen sites" list shows.
	 *
	 * @return int
	 */
	public static function site_picker_limit(): int {
		/**
		 * Filters how many sites the "chosen sites" list shows.
		 *
		 * On a larger network, freeze chosen sites with `wp axle-read-only-mode on --network --sites=<ids>`.
		 *
		 * @param int $limit Default 200.
		 */
		return max( 1, (int) apply_filters( 'axle_read_only_mode_network_site_picker_limit', 200 ) );
	}

	/**
	 * Prints the rows that choose which sites a freeze covers.
	 */
	public function render_scope_rows(): void {
		$limit = self::site_picker_limit();
		$sites = get_sites(
			array(
				'number'  => $limit + 1,
				'orderby' => 'path',
			)
		);
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Sites', 'axle-read-only-mode' ); ?></th>
			<td>
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'Sites to freeze', 'axle-read-only-mode' ); ?></legend>
					<label><input type="radio" name="scope" value="all" checked> <?php esc_html_e( 'Every site on the network', 'axle-read-only-mode' ); ?></label><br>
					<label><input type="radio" name="scope" value="sites"> <?php esc_html_e( 'Only the sites I tick below', 'axle-read-only-mode' ); ?></label>
					<ul class="aro-site-picker">
						<?php foreach ( array_slice( $sites, 0, $limit ) as $site ) : ?>
							<li>
								<label>
									<input type="checkbox" name="sites[]" value="<?php echo esc_attr( (string) $site->blog_id ); ?>">
									<?php echo esc_html( $site->blogname . ' (' . untrailingslashit( $site->domain . $site->path ) . ')' ); ?>
								</label>
							</li>
						<?php endforeach; ?>
					</ul>
					<?php if ( count( $sites ) > $limit ) : ?>
						<p class="description"><?php esc_html_e( 'Only the first sites are listed. Use WP-CLI with --sites for the rest.', 'axle-read-only-mode' ); ?></p>
					<?php endif; ?>
				</fieldset>
			</td>
		</tr>
		<?php
	}

	/**
	 * Prints the numbers at the top.
	 */
	private function render_stats(): void {
		$sites   = $this->network->log->sites();
		$blocked = array_sum( wp_list_pluck( $sites, 'blocked' ) );
		if ( ! $blocked && ! $this->network->state->is_running() ) {
			return;
		}
		?>
		<div class="axle-stats">
			<div class="axle-stat <?php echo $blocked ? 'axle-stat--error' : 'axle-stat--good'; ?>">
				<span class="axle-stat__value"><?php echo esc_html( number_format_i18n( $blocked ) ); ?></span>
				<span class="axle-stat__label"><?php esc_html_e( 'attempts blocked', 'axle-read-only-mode' ); ?></span>
			</div>
			<div class="axle-stat <?php echo $sites ? 'axle-stat--warning' : 'axle-stat--good'; ?>">
				<span class="axle-stat__value"><?php echo esc_html( number_format_i18n( count( $sites ) ) ); ?></span>
				<span class="axle-stat__label"><?php esc_html_e( 'sites with blocked attempts', 'axle-read-only-mode' ); ?></span>
			</div>
		</div>
		<?php
	}

	/**
	 * Prints what each site blocked during the current or last network freeze.
	 */
	private function render_blocked(): void {
		$sites = $this->network->log->sites();
		if ( ! $sites && ! $this->network->state->is_running() ) {
			return;
		}

		?>
		<section class="axle-card aro-blocked">
			<h2 class="axle-card__title">
				<?php
				echo esc_html(
					$this->network->state->is_running()
						? __( 'Blocked on each site during this freeze', 'axle-read-only-mode' )
						: __( 'Blocked on each site during the last freeze', 'axle-read-only-mode' )
				);
				?>
			</h2>
			<?php if ( ! $sites ) : ?>
				<div class="axle-empty"><p><?php esc_html_e( 'Nothing yet. Attempts to save, upload, comment or submit a form on any covered site will be listed here, with the site they came from.', 'axle-read-only-mode' ); ?></p></div>
			<?php else : ?>
				<table class="widefat striped aro-site-log">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Site', 'axle-read-only-mode' ); ?></th>
							<th scope="col" class="num"><?php esc_html_e( 'Blocked', 'axle-read-only-mode' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Latest attempt', 'axle-read-only-mode' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $sites as $site ) : ?>
							<tr>
								<td><a href="<?php echo esc_url( get_admin_url( $site['site_id'], 'tools.php?page=' . ToolsPage::SLUG ) ); ?>"><?php echo esc_html( '' !== $site['name'] ? $site['name'] : $site['url'] ); ?></a></td>
								<td class="num"><?php echo esc_html( number_format_i18n( $site['blocked'] ) ); ?></td>
								<td><?php echo esc_html( $site['entries'][0]['where'] ?? '' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<h3><?php esc_html_e( 'Latest attempts across the network', 'axle-read-only-mode' ); ?></h3>
				<table class="widefat striped aro-network-log">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'When', 'axle-read-only-mode' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Site', 'axle-read-only-mode' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Who', 'axle-read-only-mode' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Where', 'axle-read-only-mode' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Result', 'axle-read-only-mode' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Request', 'axle-read-only-mode' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $this->network->log->recent() as $entry ) : ?>
							<tr>
								<td><?php echo ToolsPage::time_tag( (int) $entry['time'], get_option( 'time_format' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in time_tag(). ?></td>
								<td><?php echo esc_html( '' !== $entry['name'] ? $entry['name'] : '#' . $entry['site_id'] ); ?></td>
								<td><?php echo esc_html( $this->who( $entry ) ); ?></td>
								<td><?php echo esc_html( ToolsPage::source_label( (string) ( $entry['source'] ?? '' ) ) ); ?></td>
								<td><?php ToolsPage::result_badge( $entry ); ?></td>
								<td><code><?php echo esc_html( (string) $entry['where'] ); ?></code></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Who is behind a log entry, which can belong to any site.
	 *
	 * @param array $entry Log entry.
	 * @return string
	 */
	private function who( array $entry ): string {
		$who = BlockedLog::who( $entry );

		return '' !== $who ? $who : __( 'Visitor', 'axle-read-only-mode' );
	}

	/**
	 * Prints recent network freezes.
	 */
	private function render_history(): void {
		$entries = $this->network->history->all();
		$ended   = array(
			'user'    => __( 'Unfrozen', 'axle-read-only-mode' ),
			'cli'     => __( 'Unfrozen by WP-CLI', 'axle-read-only-mode' ),
			'expired' => __( 'Ended on time', 'axle-read-only-mode' ),
			'ability' => __( 'Unfrozen by an AI agent or app', 'axle-read-only-mode' ),
		);
		$format  = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<section class="axle-card aro-history">
			<h2 class="axle-card__title"><?php esc_html_e( 'Recent network freezes', 'axle-read-only-mode' ); ?></h2>
			<?php if ( ! $entries ) : ?>
				<div class="axle-empty"><p><?php esc_html_e( 'No network freezes yet. Each one is listed here once it ends, with how many attempts it blocked across the network.', 'axle-read-only-mode' ); ?></p></div>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Started', 'axle-read-only-mode' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Length', 'axle-read-only-mode' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Reason', 'axle-read-only-mode' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Scope', 'axle-read-only-mode' ); ?></th>
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
								<td><?php echo esc_html( '' !== $entry['reason'] ? $entry['reason'] : '-' ); ?></td>
								<td><?php echo esc_html( Scopes::label( (string) ( $entry['scope'] ?? Scopes::ALL ) ) ); ?></td>
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
			'wp axle-read-only-mode on --network --minutes=30 --reason="Moving servers"',
			'wp axle-read-only-mode on --network --sites=2,3 --minutes=30',
			'wp axle-read-only-mode off --network',
		);
		?>
		<section class="axle-card aro-cli">
			<h2 class="axle-card__title"><?php esc_html_e( 'From a deploy script', 'axle-read-only-mode' ); ?></h2>
			<p><?php esc_html_e( 'Add --network to freeze the network. Without it, a command acts on the site given with --url.', 'axle-read-only-mode' ); ?></p>
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
		Forms::button( self::post_url(), self::ACTION, $task, $label, $style, $fields );
	}

	/**
	 * Prints the result of the last action.
	 */
	private function render_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only values set by our own redirect.
		$done  = isset( $_GET['aro_done'] ) ? sanitize_key( wp_unslash( $_GET['aro_done'] ) ) : '';
		$error = isset( $_GET['aro_error'] ) ? sanitize_key( wp_unslash( $_GET['aro_error'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$messages = array(
			'frozen'    => __( 'The network is frozen.', 'axle-read-only-mode' ),
			'scheduled' => __( 'Network freeze scheduled.', 'axle-read-only-mode' ),
			'unfrozen'  => __( 'The network is unfrozen.', 'axle-read-only-mode' ),
			'extended'  => __( 'Freeze extended.', 'axle-read-only-mode' ),
			'saved'     => __( 'Settings saved.', 'axle-read-only-mode' ),
			'added'     => __( 'Entry added to the allow-list.', 'axle-read-only-mode' ),
		);

		if ( isset( $messages[ $done ] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $messages[ $done ] ) );
		}
		if ( 'saved_with_errors' === $done ) {
			printf( '<div class="notice notice-warning is-dismissible"><p>%s</p></div>', esc_html__( 'Settings saved, except the lines listed below.', 'axle-read-only-mode' ) );
		} elseif ( 'add_failed' === $done ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html__( 'That entry could not be added.', 'axle-read-only-mode' ) );
		}
		if ( 'no_sites' === $error ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html__( 'Tick at least one site, or choose every site on the network.', 'axle-read-only-mode' ) );
		}
	}

	/**
	 * Handles the freeze form, buttons and admin bar links.
	 */
	public function handle_action(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to freeze this network.', 'axle-read-only-mode' ), 403 );
		}

		check_admin_referer( self::ACTION );

		$freezer = $this->network->freezer;
		$task    = isset( $_REQUEST['task'] ) ? sanitize_key( wp_unslash( $_REQUEST['task'] ) ) : '';
		$minutes = isset( $_REQUEST['minutes'] ) ? absint( $_REQUEST['minutes'] ) : 0;
		$done    = '';
		$error   = '';

		$tab = '';

		switch ( $task ) {
			case 'save_settings':
			case 'add_allow':
				$done = SettingsView::handle( $this->network->settings );
				$tab  = 'settings';
				break;

			case 'unfreeze':
				$freezer->unfreeze( 'user' );
				$done = 'unfrozen';
				break;

			case 'extend':
				$done = $freezer->extend( $minutes ? $minutes : Freezer::extend_steps()[0] ) ? 'extended' : '';
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

				$scope = isset( $_POST['scope'] ) && 'sites' === $_POST['scope'] ? 'sites' : 'all';
				$sites = isset( $_POST['sites'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['sites'] ) ) : array();
				if ( 'sites' === $scope && ! array_filter( $sites ) ) {
					$error = 'no_sites';
					break;
				}

				$freezer->freeze(
					array(
						'start'           => $start,
						'minutes'         => isset( $_POST['minutes'] ) ? absint( $_POST['minutes'] ) : Freezer::default_duration(),
						'reason'          => isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '',
						'visitor_message' => isset( $_POST['visitor_message'] ) ? sanitize_text_field( wp_unslash( $_POST['visitor_message'] ) ) : '',
						'visitors'        => ! empty( $_POST['visitors'] ),
						'cron'            => ! empty( $_POST['cron'] ),
						'exempt'          => ! empty( $_POST['exempt_me'] ) ? array( get_current_user_id() ) : array(),
						'scope'           => $scope,
						'sites'           => $sites,
						'area'            => isset( $_POST['freeze_scope'] ) ? sanitize_key( wp_unslash( $_POST['freeze_scope'] ) ) : Scopes::default_scope(),
					)
				);
				$done = $start > time() ? 'scheduled' : 'frozen';
				break;
		}

		wp_safe_redirect(
			add_query_arg(
				array_filter(
					array(
						'aro_done'  => $done,
						'aro_error' => $error,
						'tab'       => $tab,
					)
				),
				self::page_url() 
			) 
		);
		exit;
	}
}
