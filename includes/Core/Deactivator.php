<?php
namespace Lumia\Tools\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Gère la désactivation du plugin.
 */
class Deactivator {

	/**
	 * Exécuté à la désactivation du plugin.
	 */
	public static function deactivate(): void {
		// Aucune rewrite rule n'est enregistrée par le plugin. Les tâches
		// planifiées, elles, survivraient à la désactivation : WordPress les
		// relancerait chaque jour sur un hook que plus personne n'écoute.
		foreach ( Activator::MODULE_CLASSES as $class ) {
			foreach ( $class::get_uninstall_keys()['cron'] ?? [] as $hook ) {
				wp_clear_scheduled_hook( $hook );
			}
		}
	}
}
