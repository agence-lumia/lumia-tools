<?php
/**
 * Plugin Name: Lümia Tools
 * Plugin URI:  https://github.com/agence-lumia/lumia-tools
 * Update URI:  https://github.com/agence-lumia/lumia-tools
 * Description: A modular toolkit to optimize and improve your WordPress site.
 * Version:     2.0.1-dev.2
 * Author:      Agence Lümia
 * Author URI:  https://agence-lumia.com
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: lumia-tools
 * Domain Path: /languages
 * Requires at least: 6.9
 * Requires PHP: 8.0
 */

// Security: block direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Base constants
define( 'LUMIA_VERSION', '2.0.1-dev.2' );
define( 'LUMIA_PLUGIN_FILE', __FILE__ );
define( 'LUMIA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LUMIA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'LUMIA_INCLUDES_DIR', LUMIA_PLUGIN_DIR . 'includes/' );
define( 'LUMIA_TEMPLATES_DIR', LUMIA_PLUGIN_DIR . 'templates/' );
define( 'LUMIA_ASSETS_URL', LUMIA_PLUGIN_URL . 'assets/' );

// Autoloader
require_once LUMIA_INCLUDES_DIR . 'Core/Autoloader.php';
Lumia\Tools\Core\Autoloader::register();

// Bootstrap
add_action(
	'plugins_loaded',
	static function (): void {
		Lumia\Tools\Core\Plugin::instance();
	},
	10
);

// Activation hook
register_activation_hook( __FILE__, [ 'Lumia\Tools\Core\Activator', 'activate' ] );

// Deactivation hook
register_deactivation_hook( __FILE__, [ 'Lumia\Tools\Core\Deactivator', 'deactivate' ] );
