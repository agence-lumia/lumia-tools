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
		// hook nobody listens to anymore. wp_unschedule_hook() removes every event
		// of the hook, whatever its arguments (wp_clear_scheduled_hook() only
		// removes the events scheduled without any).
		foreach ( Activator::MODULE_CLASSES as $class ) {
			foreach ( $class::get_uninstall_keys()['cron'] ?? [] as $hook ) {
				wp_unschedule_hook( $hook );
			}
		}

		// Files danger mode: its cron event is gone (above), so an unlock left in
		// place would only be reported as expired whenever someone next opens the
		// Files screen. Lock it now, while the Activity Log can still record it.
		\Lumia\Tools\Modules\Files\DangerMode::disable();

		// Image Optimizer, whatever the module state: the AVIF negotiation block of
		// uploads/.htaccess goes, and so do the generated siblings (an nginx rule outside
		// WordPress would keep serving them). Nobody would keep either up to date.
		\Lumia\Tools\Modules\ImageOptimizer\DeliveryProbe::reset();
		\Lumia\Tools\Modules\ImageOptimizer\Module::purge_generated_siblings();
	}
}
