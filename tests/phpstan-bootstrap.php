<?php
/**
 * Constants PHPStan needs to know about. Each one is optional and set in
 * wp-config.php, so the code only reads it behind defined().
 *
 * @package AryanX\DeployFreeze
 */

defined( 'DEPLOY_FREEZE' ) || define( 'DEPLOY_FREEZE', false );
defined( 'DEPLOY_FREEZE_REASON' ) || define( 'DEPLOY_FREEZE_REASON', '' );
