<?php
/**
 * Forms the site screen and the network screen share.
 *
 * @package AryanX\Axle\ReadOnlyMode
 */

namespace AryanX\Axle\ReadOnlyMode\Admin;

use AryanX\Axle\ReadOnlyMode\Freezer;
use AryanX\Axle\ReadOnlyMode\Scopes;

defined( 'ABSPATH' ) || exit;

/**
 * The freeze form and one-button forms, for either admin-post.php (a site) or
 * network admin's edit.php (the network).
 */
final class Forms {

	/**
	 * Prints the form that starts a freeze.
	 *
	 * @param string        $post_url            Where the form posts.
	 * @param string        $action              Action name, also the nonce action.
	 * @param string        $title               Heading and button text.
	 * @param string        $visitor_placeholder Placeholder of the visitor message field.
	 * @param callable|null $extra_rows          Prints extra table rows, such as which sites.
	 */
	public static function freeze( string $post_url, string $action, string $title, string $visitor_placeholder, ?callable $extra_rows = null ): void {
		?>
		<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="axle-card aro-form">
			<h2 class="axle-card__title"><?php echo esc_html( $title ); ?></h2>
			<?php wp_nonce_field( $action ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
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
						<input type="text" class="large-text" id="aro-visitor-message" name="visitor_message" placeholder="<?php echo esc_attr( $visitor_placeholder ); ?>">
						<p class="description"><?php esc_html_e( 'Shown when a visitor sends a comment, form or order. Leave empty for the default.', 'axle-read-only-mode' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Freeze', 'axle-read-only-mode' ); ?></th>
					<td>
						<fieldset class="aro-scopes">
							<legend class="screen-reader-text"><?php esc_html_e( 'What to freeze', 'axle-read-only-mode' ); ?></legend>
							<?php foreach ( Scopes::all() as $slug => $scope ) : ?>
								<label class="aro-scope">
									<input type="radio" name="freeze_scope" value="<?php echo esc_attr( $slug ); ?>" <?php checked( Scopes::default_scope(), $slug ); ?>>
									<strong><?php echo esc_html( $scope['label'] ); ?></strong>
									<span class="description"><?php echo esc_html( $scope['description'] ); ?></span>
								</label>
							<?php endforeach; ?>
						</fieldset>
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
								<option value="<?php echo esc_attr( (string) $minutes ); ?>" <?php selected( Freezer::default_duration(), $minutes ); ?>><?php echo esc_html( ToolsPage::duration_label( $minutes ) ); ?></option>
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
						<p class="description"><?php esc_html_e( 'Visitor submissions are content, so they stay open when you freeze settings and code only. Content only holds every scheduled task except update checks. Settings and code only holds just those.', 'axle-read-only-mode' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Exempt', 'axle-read-only-mode' ); ?></th>
					<td><label><input type="checkbox" name="exempt_me" value="1"> <?php esc_html_e( 'Let me keep editing', 'axle-read-only-mode' ); ?></label></td>
				</tr>
				<?php
				if ( $extra_rows ) {
					$extra_rows();
				}
				?>
			</table>
			<div class="axle-confirm" id="aro-freeze-confirm" role="group" aria-labelledby="aro-freeze-confirm-text" hidden>
				<p id="aro-freeze-confirm-text"></p>
				<div class="axle-actions">
					<button type="button" class="button button-primary" data-aro-confirm-yes><?php esc_html_e( 'Freeze anyway', 'axle-read-only-mode' ); ?></button>
					<button type="button" class="button" data-aro-confirm-no><?php esc_html_e( 'Go back', 'axle-read-only-mode' ); ?></button>
				</div>
			</div>
			<?php submit_button( $title ); ?>
		</form>
		<?php
	}

	/**
	 * Prints a one-button form that runs a task.
	 *
	 * @param string $post_url Where the form posts.
	 * @param string $action   Action name, also the nonce action.
	 * @param string $task     Task name.
	 * @param string $label    Button text.
	 * @param string $style    primary or secondary.
	 * @param array  $fields   Extra hidden fields.
	 */
	public static function button( string $post_url, string $action, string $task, string $label, string $style, array $fields = array() ): void {
		?>
		<form method="post" action="<?php echo esc_url( $post_url ); ?>">
			<?php wp_nonce_field( $action ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
			<input type="hidden" name="task" value="<?php echo esc_attr( $task ); ?>">
			<?php foreach ( $fields as $name => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>">
			<?php endforeach; ?>
			<button type="submit" class="button button-<?php echo esc_attr( $style ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}
}
