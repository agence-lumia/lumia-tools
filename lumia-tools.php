<?php
/**
 * Plugin Name: Lümia Tools
 * Plugin URI:  https://github.com/agence-lumia/lumia-tools
 * Update URI:  https://github.com/agence-lumia/lumia-tools
 * Description: Suite d'outils modulaires pour optimiser et améliorer votre site WordPress.
 * Version:     1.1.1-dev.1
 * Author:      Agence Lümia
 * Author URI:  https://agence-lumia.com
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: lumia-tools
 * Domain Path: /languages
 * Requires at least: 6.9
 * Requires PHP: 8.0
 */

// Sécurité : empêcher l'accès direct
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Constantes de base
define( 'LUMIA_VERSION', '1.1.1-dev.1' );
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
