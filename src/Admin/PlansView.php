<?php
/**
 * The planned freezes list and form, shared by the site screen and the network screen.
 *
 * @package AryanX\Axle\ReadOnlyMode
 */

namespace AryanX\Axle\ReadOnlyMode\Admin;

use AryanX\Axle\ReadOnlyMode\Freezer;
use AryanX\Axle\ReadOnlyMode\Planner;
use AryanX\Axle\ReadOnlyMode\Scopes;

defined( 'ABSPATH' ) || exit;

/**
 * Prints and handles the "Planned" tab.
 */
final class PlansView {

	/**
	 * Words for the weekdays, in the order the site's week starts.
	 *
	 * @return array<int, string> Weekday number (0 is Sunday) => full name.
	 */
	private static function weekdays(): array {
		global $wp_locale;

		$first = (int) get_option( 'start_of_week', 1 );
		$days  = array();

		for ( $i = 0; $i < 7; $i++ ) {
			$day          = ( $first + $i ) % 7;
			$days[ $day ] = $wp_locale->get_weekday( $day );
		}

		return $days;
	}

	/**
	 * Words for when a plan runs: "Every Monday and Thursday at 02:00", or the date.
	 *
	 * @param array $plan Plan.
	 * @return string
	 */
	public static function repeat_label( array $plan ): string {
		if ( empty( $plan['days'] ) ) {
			return __( 'Once', 'axle-read-only-mode' );
		}

		global $wp_locale;

		$names = array();
		foreach ( self::weekdays() as $day => $name ) {
			if ( in_array( $day, $plan['days'], true ) ) {
				$names[] = $wp_locale->get_weekday_abbrev( $name );
			}
		}

		/* translators: 1: weekdays, e.g. "Mon, Thu", 2: time of day, e.g. "02:00". */
		return sprintf( __( 'Every %1$s at %2$s', 'axle-read-only-mode' ), implode( ', ', $names ), wp_date( get_option( 'time_format' ), (int) $plan['start'] ) );
	}

	/**
	 * Prints the tab.
	 *
	 * @param Planner   $planner  The list to show.
	 * @param string    $post_url Where the forms post.
	 * @param string    $action   Action name, also the nonce action.
	 * @param string    $page_url Address of the tab, for the Edit links.
	 * @param string    $intro    One or two sentences for the top of the tab.
	 * @param ?callable $extra_rows Prints extra form rows, such as which sites.
	 */
	public static function render( Planner $planner, string $post_url, string $action, string $page_url, string $intro, ?callable $extra_rows = null ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only value that picks which plan to show in the form.
		$edit_id = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : '';
		$editing = '' !== $edit_id ? $planner->get( $edit_id ) : null;
		$plans   = $planner->all();
		?>
		<p class="axle-intro"><?php echo esc_html( $intro ); ?></p>

		<section class="axle-card aro-plans" aria-labelledby="aro-plans-title">
			<h2 class="axle-card__title" id="aro-plans-title"><?php esc_html_e( 'Planned freezes', 'axle-read-only-mode' ); ?></h2>
			<?php if ( ! $plans ) : ?>
				<div class="axle-empty">
					<p><?php esc_html_e( 'Nothing is planned. A planned freeze starts by itself at the time you choose, and everyone who is writing gets a countdown first. Use it for a deploy window, once or every week.', 'axle-read-only-mode' ); ?></p>
					<a class="button" href="#aro-plan-form"><?php esc_html_e( 'Plan a freeze', 'axle-read-only-mode' ); ?></a>
				</div>
			<?php else : ?>
				<div class="axle-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Planned freezes', 'axle-read-only-mode' ); ?>">
					<table class="widefat striped">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Starts', 'axle-read-only-mode' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Repeats', 'axle-read-only-mode' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Length', 'axle-read-only-mode' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Covers', 'axle-read-only-mode' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Reason', 'axle-read-only-mode' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Actions', 'axle-read-only-mode' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $plans as $plan ) : ?>
								<tr>
									<td><?php echo AxleUi::relative_time( (int) $plan['start'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in AxleUi. ?></td>
									<td><?php echo esc_html( self::repeat_label( $plan ) ); ?></td>
									<td><?php echo esc_html( ToolsPage::duration_label( (int) $plan['minutes'] ) ); ?></td>
									<td><?php echo esc_html( Scopes::label( (string) $plan['area'] ) ); ?></td>
									<td><?php echo '' !== $plan['reason'] ? esc_html( $plan['reason'] ) : '<span class="axle-muted">' . esc_html__( 'None', 'axle-read-only-mode' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Both branches are escaped. ?></td>
									<td>
										<a class="button button-small" href="<?php echo esc_url( add_query_arg( 'edit', $plan['id'], $page_url ) . '#aro-plan-form' ); ?>">
											<?php esc_html_e( 'Edit', 'axle-read-only-mode' ); ?>
											<span class="screen-reader-text">
												<?php
												/* translators: %s: reason or start of the planned freeze. */
												echo esc_html( sprintf( __( 'planned freeze: %s', 'axle-read-only-mode' ), '' !== $plan['reason'] ? $plan['reason'] : wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $plan['start'] ) ) );
												?>
											</span>
										</a>
										<details class="aro-cancel">
											<summary class="button button-small"><?php esc_html_e( 'Cancel', 'axle-read-only-mode' ); ?></summary>
											<div class="axle-confirm axle-confirm--danger" role="group" aria-labelledby="aro-cancel-<?php echo esc_attr( $plan['id'] ); ?>">
												<p id="aro-cancel-<?php echo esc_attr( $plan['id'] ); ?>">
													<?php
													echo esc_html(
														$plan['days']
															? __( 'This stops the freeze from starting, now and every week after. A freeze that is already running is not changed. You can plan it again later.', 'axle-read-only-mode' )
															: __( 'This stops the freeze from starting. You can plan it again later.', 'axle-read-only-mode' )
													);
													?>
												</p>
												<div class="axle-actions">
													<?php Forms::button( $post_url, $action, 'plan_cancel', __( 'Cancel this planned freeze', 'axle-read-only-mode' ), 'secondary', array( 'plan' => $plan['id'] ) ); ?>
												</div>
											</div>
										</details>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</section>

		<?php self::render_form( $post_url, $action, $page_url, $editing, $extra_rows ); ?>
		<?php
	}

	/**
	 * Prints the form that plans a freeze, or changes a planned one.
	 *
	 * @param string     $post_url   Where the form posts.
	 * @param string     $action     Action name, also the nonce action.
	 * @param string     $page_url   Address of the tab.
	 * @param array|null $plan       The plan being changed, null for a new one.
	 * @param ?callable  $extra_rows Prints extra form rows.
	 */
	private static function render_form( string $post_url, string $action, string $page_url, ?array $plan, ?callable $extra_rows ): void {
		$plan    = $plan ?? array();
		$start   = ! empty( $plan['start'] ) ? wp_date( 'Y-m-d\TH:i', (int) $plan['start'] ) : '';
		$minutes = isset( $plan['minutes'] ) ? (int) $plan['minutes'] : Freezer::default_duration();
		$area    = isset( $plan['area'] ) ? (string) $plan['area'] : Scopes::default_scope();
		$days    = (array) ( $plan['days'] ?? array() );
		$title   = $plan ? __( 'Change the planned freeze', 'axle-read-only-mode' ) : __( 'Plan a freeze', 'axle-read-only-mode' );
		?>
		<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="axle-card aro-form" id="aro-plan-form">
			<h2 class="axle-card__title"><?php echo esc_html( $title ); ?></h2>
			<?php wp_nonce_field( $action ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
			<input type="hidden" name="task" value="plan_save">
			<input type="hidden" name="plan" value="<?php echo esc_attr( (string) ( $plan['id'] ?? '' ) ); ?>">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="aro-plan-start"><?php esc_html_e( 'Starts at', 'axle-read-only-mode' ); ?></label></th>
					<td>
						<input type="datetime-local" id="aro-plan-start" name="start" value="<?php echo esc_attr( $start ); ?>" required>
						<p class="description">
							<?php
							/* translators: %s: site time zone. */
							echo esc_html( sprintf( __( 'Site time zone: %s. For a weekly freeze, this sets the time of day.', 'axle-read-only-mode' ), wp_timezone_string() ) );
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aro-plan-minutes"><?php esc_html_e( 'Length', 'axle-read-only-mode' ); ?></label></th>
					<td>
						<select id="aro-plan-minutes" name="minutes">
							<?php foreach ( array_unique( array_merge( Freezer::durations(), array( $minutes ) ) ) as $option ) : ?>
								<option value="<?php echo esc_attr( (string) $option ); ?>" <?php selected( $minutes, $option ); ?>><?php echo esc_html( ToolsPage::duration_label( (int) $option ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Freeze', 'axle-read-only-mode' ); ?></th>
					<td>
						<fieldset class="aro-scopes">
							<legend class="screen-reader-text"><?php esc_html_e( 'What to freeze', 'axle-read-only-mode' ); ?></legend>
							<?php foreach ( Scopes::all() as $slug => $scope ) : ?>
								<label class="aro-scope">
									<input type="radio" name="freeze_scope" value="<?php echo esc_attr( $slug ); ?>" <?php checked( $area, $slug ); ?>>
									<strong><?php echo esc_html( $scope['label'] ); ?></strong>
									<span class="description"><?php echo esc_html( $scope['description'] ); ?></span>
								</label>
							<?php endforeach; ?>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Repeat', 'axle-read-only-mode' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Repeat every week on', 'axle-read-only-mode' ); ?></legend>
							<?php foreach ( self::weekdays() as $day => $name ) : ?>
								<label class="aro-day"><input type="checkbox" name="days[]" value="<?php echo esc_attr( (string) $day ); ?>" <?php checked( in_array( $day, $days, true ) ); ?>> <?php echo esc_html( $name ); ?></label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Tick days to repeat every week at the same time, for a regular deploy window. Leave them empty to freeze once.', 'axle-read-only-mode' ); ?></p>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aro-plan-reason"><?php esc_html_e( 'Reason', 'axle-read-only-mode' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" id="aro-plan-reason" name="reason" value="<?php echo esc_attr( (string) ( $plan['reason'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'Release night', 'axle-read-only-mode' ); ?>">
						<p class="description"><?php esc_html_e( 'Shown in the countdown and to anyone who tries to save during the freeze.', 'axle-read-only-mode' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aro-plan-lead"><?php esc_html_e( 'Heads-up', 'axle-read-only-mode' ); ?></label></th>
					<td>
						<input type="number" id="aro-plan-lead" name="lead" class="small-text" min="1" max="1440" value="<?php echo esc_attr( ! empty( $plan['lead'] ) ? (string) $plan['lead'] : '' ); ?>" placeholder="<?php echo esc_attr( (string) Planner::lead_minutes() ); ?>">
						<?php esc_html_e( 'minutes before it starts', 'axle-read-only-mode' ); ?>
						<p class="description"><?php esc_html_e( 'From then on, everyone who is writing sees a countdown in wp-admin and in the block editor. Leave empty for the default.', 'axle-read-only-mode' ); ?></p>
						<p><label><input type="checkbox" name="email" value="1" <?php checked( ! empty( $plan['email'] ) ); ?>> <?php esc_html_e( 'Also email people who have a post open when the heads-up starts', 'axle-read-only-mode' ); ?></label></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Also pause', 'axle-read-only-mode' ); ?></th>
					<td>
						<label><input type="checkbox" name="visitors" value="1" <?php checked( ! isset( $plan['visitors'] ) || $plan['visitors'] ); ?>> <?php esc_html_e( 'Visitor submissions: comments, contact forms, checkouts and sign-ups', 'axle-read-only-mode' ); ?></label><br>
						<label><input type="checkbox" name="cron" value="1" <?php checked( ! isset( $plan['cron'] ) || $plan['cron'] ); ?>> <?php esc_html_e( 'Scheduled tasks (they run when the freeze ends)', 'axle-read-only-mode' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Exempt', 'axle-read-only-mode' ); ?></th>
					<td><label><input type="checkbox" name="exempt_me" value="1" <?php checked( in_array( get_current_user_id(), (array) ( $plan['exempt'] ?? array() ), true ) ); ?>> <?php esc_html_e( 'Let me keep editing', 'axle-read-only-mode' ); ?></label></td>
				</tr>
				<?php
				if ( $extra_rows ) {
					$extra_rows();
				}
				?>
			</table>
			<p class="submit">
				<button type="submit" class="button button-primary"><?php echo esc_html( $plan ? __( 'Save changes', 'axle-read-only-mode' ) : __( 'Plan this freeze', 'axle-read-only-mode' ) ); ?></button>
				<?php if ( $plan ) : ?>
					<a class="button button-link" href="<?php echo esc_url( $page_url ); ?>"><?php esc_html_e( 'Stop editing', 'axle-read-only-mode' ); ?></a>
				<?php endif; ?>
			</p>
		</form>
		<?php
	}

	/**
	 * Saves or cancels a plan from a posted form.
	 *
	 * The caller has checked the capability and the nonce.
	 *
	 * @param Planner $planner The list.
	 * @param string  $task    plan_save or plan_cancel.
	 * @return array{done: string, error: string} Result codes for the redirect.
	 */
	public static function handle( Planner $planner, string $task ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer() in the caller.
		$id = isset( $_POST['plan'] ) ? sanitize_key( wp_unslash( $_POST['plan'] ) ) : '';

		if ( 'plan_cancel' === $task ) {
			return $planner->cancel( $id ) ? array(
				'done'  => 'plan_cancelled',
				'error' => '',
			) : array(
				'done'  => '',
				'error' => 'plan_missing',
			);
		}

		$scope = isset( $_POST['scope'] ) && 'sites' === $_POST['scope'] ? 'sites' : 'all';
		$sites = isset( $_POST['sites'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['sites'] ) ) : array();
		if ( $planner->is_network() && 'sites' === $scope && ! array_filter( $sites ) ) {
			return array(
				'done'  => '',
				'error' => 'no_sites',
			);
		}

		$result = $planner->save(
			array(
				'start'    => isset( $_POST['start'] ) ? ToolsPage::parse_start( sanitize_text_field( wp_unslash( $_POST['start'] ) ) ) : 0,
				'minutes'  => isset( $_POST['minutes'] ) ? absint( $_POST['minutes'] ) : Freezer::default_duration(),
				'area'     => isset( $_POST['freeze_scope'] ) ? sanitize_key( wp_unslash( $_POST['freeze_scope'] ) ) : Scopes::default_scope(),
				'reason'   => isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '',
				'visitors' => ! empty( $_POST['visitors'] ),
				'cron'     => ! empty( $_POST['cron'] ),
				'days'     => isset( $_POST['days'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['days'] ) ) : array(),
				'lead'     => isset( $_POST['lead'] ) ? absint( $_POST['lead'] ) : 0,
				'email'    => ! empty( $_POST['email'] ),
				'exempt'   => ! empty( $_POST['exempt_me'] ) ? array( get_current_user_id() ) : array(),
				'scope'    => $scope,
				'sites'    => $sites,
			),
			$id
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( is_wp_error( $result ) ) {
			$codes = array(
				'axle_read_only_mode_plan_past'    => 'plan_past',
				'axle_read_only_mode_plan_limit'   => 'plan_limit',
				'axle_read_only_mode_plan_missing' => 'plan_missing',
			);

			return array(
				'done'  => '',
				'error' => $codes[ $result->get_error_code() ] ?? 'plan_past',
			);
		}

		return array(
			'done'  => '' !== $id ? 'plan_updated' : 'planned',
			'error' => '',
		);
	}

	/**
	 * The notices for the codes `handle()` returns.
	 *
	 * @return array{done: array<string, string>, error: array<string, string>}
	 */
	public static function messages(): array {
		return array(
			'done'  => array(
				'planned'        => __( 'Freeze planned. Everyone who is writing gets a countdown before it starts.', 'axle-read-only-mode' ),
				'plan_updated'   => __( 'Planned freeze changed.', 'axle-read-only-mode' ),
				'plan_cancelled' => __( 'Planned freeze cancelled. It will not start. You can plan it again below.', 'axle-read-only-mode' ),
			),
			'error' => array(
				'plan_past'    => __( 'Nothing was planned. Choose a start time in the future.', 'axle-read-only-mode' ),
				'plan_limit'   => __( 'Nothing was planned. There are too many planned freezes: cancel one first.', 'axle-read-only-mode' ),
				'plan_missing' => __( 'That planned freeze no longer exists. It may have started or been cancelled already.', 'axle-read-only-mode' ),
				'no_sites'     => __( 'Tick at least one site, or choose every site on the network.', 'axle-read-only-mode' ),
			),
		);
	}
}
