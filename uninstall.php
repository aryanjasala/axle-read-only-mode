<?php
/**
 * Removes the saved freeze, blocked attempts and history.
 *
 * @package AryanX\DeployFreeze
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'deploy_freeze_state' );
delete_option( 'deploy_freeze_log' );
delete_option( 'deploy_freeze_history' );
