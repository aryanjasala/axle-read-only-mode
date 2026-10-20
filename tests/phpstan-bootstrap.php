<?php
/**
 * Constants PHPStan needs to know about. Each one is optional and set in
 * wp-config.php, so the code only reads it behind defined().
 *
 * @package AryanX\SiteReadOnlyMode
 */

defined( 'SITE_READ_ONLY_MODE' ) || define( 'SITE_READ_ONLY_MODE', false );
defined( 'SITE_READ_ONLY_MODE_REASON' ) || define( 'SITE_READ_ONLY_MODE_REASON', '' );
