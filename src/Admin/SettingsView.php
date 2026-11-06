<?php
/**
 * The settings tab, for a site and for the network.
 *
 * @package AryanX\Axle\ReadOnlyMode
 */

namespace AryanX\Axle\ReadOnlyMode\Admin;

use AryanX\Axle\ReadOnlyMode\AllowList;
use AryanX\Axle\ReadOnlyMode\AllowSuggestions;
use AryanX\Axle\ReadOnlyMode\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Prints the settings cards and saves what the form sends. The site screen and
 * the network screen differ only in where they post and whose settings they edit.
 */
final class SettingsView {

	/**
	 * Prints the settings tab.
	 *
	 * @param Settings $settings Whose settings: the site's or the network's.
	 * @param string   $post_url Where the forms post.
	 * @param string   $action   Action name, also the nonce action.
	 */
	public static function render( Settings $settings, string $post_url, string $action ): void {
		$rejected = self::take_rejected( $settings );

		self::render_suggestions( $settings, $post_url, $action );
		?>
		<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="aro-settings">
			<?php wp_nonce_field( $action ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
			<input type="hidden" name="task" value="save_settings">

			<?php self::render_allow( $settings, $rejected ); ?>

			<?php submit_button( __( 'Save settings', 'axle-read-only-mode' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Prints the "Keep these working while frozen" card.
	 *
	 * @param Settings $settings Whose settings.
	 * @param array    $rejected Lines the last save refused, by kind.
	 */
	private static function render_allow( Settings $settings, array $rejected ): void {
		$network = $settings->is_network();
		$routes  = $settings->allow( 'rest' ) ? array_keys( rest_get_server()->get_routes() ) : array();
		$fields  = array(
			'rest'  => array(
				'label'       => __( 'REST API routes', 'axle-read-only-mode' ),
				'placeholder' => "/wc/v3/webhooks\n/my-plugin/v1/callback",
				'help'        => __( 'One route prefix per line. Writes to these routes, and everything below them, keep working.', 'axle-read-only-mode' ),
			),
			'paths' => array(
				'label'       => __( 'Front-end paths or query arguments', 'axle-read-only-mode' ),
				'placeholder' => "/checkout/order-pay\n?wc-api=",
				'help'        => __( 'One per line. A path such as /checkout/order-pay lets form posts to addresses that start with it through. ?wc-api= lets any request with that query argument through; add a value to match only that one.', 'axle-read-only-mode' ),
			),
			'ajax'  => array(
				'label'       => __( 'Ajax actions', 'axle-read-only-mode' ),
				'placeholder' => "my_plugin_save_cart\nsubscribe_form",
				'help'        => __( 'One admin-ajax.php action per line, in lower case.', 'axle-read-only-mode' ),
			),
			'users' => array(
				'label'       => $network ? __( 'Users who may keep editing on every site', 'axle-read-only-mode' ) : __( 'Users who may keep editing', 'axle-read-only-mode' ),
				'placeholder' => "editor-in-chief\nops@example.com",
				'help'        => __( 'One login, email address or user ID per line. They add to the people ticked with "Let me keep editing" when a freeze starts.', 'axle-read-only-mode' ),
			),
		);
		?>
		<section class="axle-card aro-allow">
			<h2 class="axle-card__title"><?php esc_html_e( 'Keep these working while frozen', 'axle-read-only-mode' ); ?></h2>
			<p>
				<?php
				echo esc_html(
					$network
						? __( 'A network freeze uses this list on every site it covers. Site lists do not apply during a network freeze. Everything else stays frozen.', 'axle-read-only-mode' )
						: __( 'A payment webhook, one form or one user may need to keep working during a freeze. Everything else stays frozen. Requests let through are listed as allowed on the Freeze tab.', 'axle-read-only-mode' )
				);
				?>
			</p>

			<?php if ( $rejected ) : ?>
				<div class="notice notice-error inline" role="alert">
					<p><?php esc_html_e( 'These lines were not saved:', 'axle-read-only-mode' ); ?></p>
					<ul class="aro-rejected">
						<?php foreach ( $rejected as $type => $lines ) : ?>
							<?php foreach ( $lines as $line ) : ?>
								<li><code><?php echo esc_html( $line['line'] ); ?></code> (<?php echo esc_html( $fields[ $type ]['label'] ?? $type ); ?>): <?php echo esc_html( $line['error'] ); ?></li>
							<?php endforeach; ?>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<table class="form-table" role="presentation">
				<?php foreach ( $fields as $type => $field ) : ?>
					<?php $entries = $settings->allow( $type ); ?>
					<tr>
						<th scope="row"><label for="aro-allow-<?php echo esc_attr( $type ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
						<td>
							<textarea id="aro-allow-<?php echo esc_attr( $type ); ?>" name="allow[<?php echo esc_attr( $type ); ?>]" class="large-text code" rows="4" placeholder="<?php echo esc_attr( $field['placeholder'] ); ?>" aria-describedby="aro-allow-<?php echo esc_attr( $type ); ?>-help"><?php echo esc_textarea( implode( "\n", self::lines( $type, $entries ) ) ); ?></textarea>
							<p class="description" id="aro-allow-<?php echo esc_attr( $type ); ?>-help"><?php echo esc_html( $field['help'] ); ?></p>
							<?php if ( $entries ) : ?>
								<ul class="aro-matches" aria-label="<?php esc_attr_e( 'What each entry matches', 'axle-read-only-mode' ); ?>">
									<?php foreach ( $entries as $entry ) : ?>
										<?php $match = AllowList::describe( $type, $entry, $routes ); ?>
										<li>
											<code><?php echo esc_html( self::line( $type, $entry ) ); ?></code>
											<?php ToolsPage::badge( $match['tone'], $match['label'] ); ?>
											<span class="axle-muted"><?php echo esc_html( $match['detail'] ); ?></span>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
		</section>
		<?php
	}

	/**
	 * Prints the "Suggested for your plugins" card: one Add button each, nothing added by itself.
	 *
	 * @param Settings $settings Whose settings.
	 * @param string   $post_url Where the buttons post.
	 * @param string   $action   Action name, also the nonce action.
	 */
	private static function render_suggestions( Settings $settings, string $post_url, string $action ): void {
		$suggestions = array_values(
			array_filter(
				AllowSuggestions::detect(),
				static function ( array $suggestion ) use ( $settings ): bool {
					$checked = AllowList::validate( $suggestion['type'], (string) $suggestion['value'] );

					return '' === $checked['error'] && ! in_array( $checked['value'], $settings->allow( $suggestion['type'] ), true );
				}
			)
		);
		if ( ! $suggestions ) {
			return;
		}
		?>
		<section class="axle-card aro-suggestions">
			<h2 class="axle-card__title"><?php esc_html_e( 'Suggested for the plugins on this site', 'axle-read-only-mode' ); ?></h2>
			<p><?php esc_html_e( 'These are common entries for plugins that are active. Nothing is added until you press Add. Payment providers retry webhooks that get a "back soon" answer, so add one only if its provider does not retry.', 'axle-read-only-mode' ); ?></p>
			<ul class="aro-suggestion-list">
				<?php foreach ( $suggestions as $suggestion ) : ?>
					<li>
						<form method="post" action="<?php echo esc_url( $post_url ); ?>">
							<?php wp_nonce_field( $action ); ?>
							<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
							<input type="hidden" name="task" value="add_allow">
							<input type="hidden" name="type" value="<?php echo esc_attr( $suggestion['type'] ); ?>">
							<input type="hidden" name="value" value="<?php echo esc_attr( (string) $suggestion['value'] ); ?>">
							<code><?php echo esc_html( (string) $suggestion['value'] ); ?></code>
							<span class="axle-muted"><?php echo esc_html( $suggestion['plugin'] . ': ' . $suggestion['why'] ); ?></span>
							<button type="submit" class="button button-small">
								<?php
								/* translators: %s: the entry to add, such as ?wc-api= */
								echo esc_html( sprintf( __( 'Add %s', 'axle-read-only-mode' ), (string) $suggestion['value'] ) );
								?>
							</button>
						</form>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
	}

	/**
	 * Saves the settings form. The caller has checked the capability and the nonce.
	 *
	 * @param Settings $settings Whose settings.
	 * @return string What happened: saved, saved_with_errors, added, add_failed.
	 */
	public static function handle( Settings $settings ): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- The caller has verified the nonce.
		$task = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( $_POST['task'] ) ) : '';

		if ( 'add_allow' === $task ) {
			$type  = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
			$value = isset( $_POST['value'] ) ? sanitize_text_field( wp_unslash( $_POST['value'] ) ) : '';

			return '' === $settings->add_allow( $type, $value ) ? 'added' : 'add_failed';
		}

		$input = array();
		foreach ( AllowList::TYPES as $type ) {
			if ( isset( $_POST['allow'][ $type ] ) && is_string( $_POST['allow'][ $type ] ) ) {
				$input[ $type ] = sanitize_textarea_field( wp_unslash( $_POST['allow'][ $type ] ) );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$rejected = $settings->save_allow( $input );
		if ( $rejected ) {
			set_transient( self::rejected_key( $settings ), $rejected, 5 * MINUTE_IN_SECONDS );

			return 'saved_with_errors';
		}

		return 'saved';
	}

	/**
	 * The lines the last save refused, shown once.
	 *
	 * @param Settings $settings Whose settings.
	 * @return array
	 */
	private static function take_rejected( Settings $settings ): array {
		$key      = self::rejected_key( $settings );
		$rejected = $settings->is_network() ? get_site_transient( $key ) : get_transient( $key );
		if ( ! $rejected ) {
			return array();
		}

		if ( $settings->is_network() ) {
			delete_site_transient( $key );
		} else {
			delete_transient( $key );
		}

		return (array) $rejected;
	}

	/**
	 * Where the refused lines wait for the next page load.
	 *
	 * @param Settings $settings Whose settings.
	 * @return string
	 */
	private static function rejected_key( Settings $settings ): string {
		return 'axle_read_only_mode_rejected_' . get_current_user_id() . ( $settings->is_network() ? '_network' : '' );
	}

	/**
	 * Entries as the textarea shows them: users by login.
	 *
	 * @param string $type    rest, paths, ajax or users.
	 * @param array  $entries Saved entries.
	 * @return string[]
	 */
	private static function lines( string $type, array $entries ): array {
		return array_map(
			static function ( $entry ) use ( $type ): string {
				return self::line( $type, $entry );
			},
			$entries
		);
	}

	/**
	 * One entry as typed: a user by login.
	 *
	 * @param string     $type  rest, paths, ajax or users.
	 * @param string|int $entry Saved entry.
	 * @return string
	 */
	private static function line( string $type, $entry ): string {
		if ( 'users' === $type ) {
			$user = get_userdata( (int) $entry );

			return $user ? $user->user_login : (string) $entry;
		}

		return (string) $entry;
	}
}
