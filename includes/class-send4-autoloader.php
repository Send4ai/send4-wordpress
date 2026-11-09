<?php
/**
 * Maps Send4_* class names onto the includes/ tree. No Composer at runtime.
 *
 * @package Send4
 */

defined( 'ABSPATH' ) || exit;

/**
 * Classmap-style autoloader following the WordPress file naming convention.
 */
class Send4_Autoloader {

	/**
	 * Register the autoloader.
	 *
	 * @return void
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Resolve a class name to a file under includes/.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	public static function load( $class_name ) {
		if ( 0 !== strpos( $class_name, 'Send4_' ) ) {
			return;
		}

		$slug       = strtolower( str_replace( '_', '-', $class_name ) );
		$candidates = array(
			SEND4_PLUGIN_DIR . 'includes/class-' . $slug . '.php',
			SEND4_PLUGIN_DIR . 'includes/adapters/class-' . $slug . '.php',
			SEND4_PLUGIN_DIR . 'includes/adapters/interface-' . $slug . '.php',
		);

		foreach ( $candidates as $file ) {
			if ( file_exists( $file ) ) {
				require_once $file;
				return;
			}
		}
	}
}
