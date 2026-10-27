<?php
/**
 * Constants PHPStan needs to know about. Each one is optional and set in
 * wp-config.php, so the code only reads it behind defined().
 *
 * @package AryanX\AxleReadOnlyMode
 */

defined( 'AXLE_READ_ONLY_MODE' ) || define( 'AXLE_READ_ONLY_MODE', false );
defined( 'AXLE_READ_ONLY_MODE_REASON' ) || define( 'AXLE_READ_ONLY_MODE_REASON', '' );
