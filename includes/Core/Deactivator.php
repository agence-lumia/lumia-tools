<?php
namespace Lumia\Tools\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Handles plugin deactivation.
 */
class Deactivator {

	/**
	 * Runs when the plugin is deactivated.
	 */
	public static function deactivate(): void {
		// The plugin registers no rewrite rule. Scheduled tasks, however, would
		// survive deactivation: WordPress would keep firing them every day on a
		// hook nobody listens to anymore.
		foreach ( Activator::MODULE_CLASSES as $class ) {
			foreach ( $class::get_uninstall_keys()['cron'] ?? [] as $hook ) {
				wp_clear_scheduled_hook( $hook );
			}
		}

		// Image Optimizer: the AVIF negotiation block of uploads/.htaccess goes, whatever the
		// module state: the web server would otherwise keep serving siblings nobody maintains.
		\Lumia\Tools\Modules\ImageOptimizer\DeliveryProbe::reset();
	}
}
