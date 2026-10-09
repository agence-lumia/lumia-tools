<?php
namespace Lumia\Tools\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Simple PSR-4 autoloader for the plugin.
 */
class Autoloader {

	/**
	 * Registers the autoloader.
	 */
	public static function register(): void {
		spl_autoload_register( [ __CLASS__, 'autoload' ] );
	}

	/**
	 * Loads a class.
	 *
	 * @param string $class_name Fully qualified class name.
	 */
	public static function autoload( string $class_name ): void {
		$prefix = 'Lumia\\Tools\\';

		// Make sure the class belongs to the plugin namespace
		if ( strpos( $class_name, $prefix ) !== 0 ) {
			return;
		}

		// Strip the prefix
		$relative_class = substr( $class_name, strlen( $prefix ) );

		// Includes base. Prefer the constant defined by the bootstrap, but fall back
		// to a path computed from this file: on uninstall, WordPress only loads
		// uninstall.php (not the bootstrap), so LUMIA_INCLUDES_DIR is not defined
		// and the autoloader must keep working.
		$base = defined( 'LUMIA_INCLUDES_DIR' ) ? LUMIA_INCLUDES_DIR : dirname( __DIR__ ) . '/';

		// Convert to a file path
		$file = $base . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
}
