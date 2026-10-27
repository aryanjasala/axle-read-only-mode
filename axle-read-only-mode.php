<?php
/**
 * Plugin Name:       Axle Read-Only Mode
 * Plugin URI:        https://wordpress.org/plugins/axle-read-only-mode/
 * Description:       Puts the site in read-only mode during a migration or deploy, so nothing written in the meantime gets lost. Visitors still see the site; edits, uploads, comments, forms and scheduled tasks wait until you unfreeze.
 * Version:           2.0.1
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Author:            Aryan Jasala
 * Author URI:        https://profiles.wordpress.org/aryanjasala/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       axle-read-only-mode
 *
 * @package AryanX\AxleReadOnlyMode
 */

namespace AryanX\AxleReadOnlyMode;

defined( 'ABSPATH' ) || exit;

const VERSION     = '2.0.1';
const PLUGIN_FILE = __FILE__;

// PSR-4: AryanX\AxleReadOnlyMode\Foo\Bar lives in src/Foo/Bar.php.
spl_autoload_register(
	static function ( $class_name ) {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
