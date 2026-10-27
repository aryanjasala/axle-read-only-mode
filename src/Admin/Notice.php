<?php
/**
 * The freeze notice on every admin screen.
 *
 * @package AryanX\AxleReadOnlyMode
 */

namespace AryanX\AxleReadOnlyMode\Admin;

use AryanX\AxleReadOnlyMode\Service;
use AryanX\AxleReadOnlyMode\State;

defined( 'ABSPATH' ) || exit;

/**
 * Tells everyone in wp-admin that the site is frozen, with Unfreeze for admins.
 */
final class Notice implements Service {

	/**
	 * Saved freeze.
	 *
	 * @var State
	 */
	private State $state;

	/**
	 * Sets up the notice.
	 *
	 * @param State $state Saved freeze.
	 */
	public function __construct( State $state ) {
		$this->state = $state;
	}

	/**
	 * Adds the notice.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Prints the notice, except on the freeze screen itself.
	 */
	public function render(): void {
		$screen = get_current_screen();
		if ( $screen && 'tools_page_' . ToolsPage::SLUG === $screen->id ) {
			return;
		}

		$exempt = $this->state->can_bypass();
		?>
		<div class="notice <?php echo $exempt ? 'notice-info' : 'notice-warning'; ?> axle-read-only-mode-notice">
			<p>
				<strong><?php echo esc_html( $this->state->message() ); ?></strong>
				<?php if ( $exempt ) : ?>
					<?php esc_html_e( 'You are exempt and can keep working.', 'axle-read-only-mode' ); ?>
				<?php endif; ?>
				<?php if ( current_user_can( ToolsPage::capability() ) && ! $this->state->forced_by_constant() ) : ?>
					<a class="button button-small" href="<?php echo esc_url( ToolsPage::action_url( 'unfreeze' ) ); ?>"><?php esc_html_e( 'Unfreeze now', 'axle-read-only-mode' ); ?></a>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}
}
