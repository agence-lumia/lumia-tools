<?php
namespace Lumia\Tools\Core;

defined( 'ABSPATH' ) || exit;

use Lumia\Tools\Core\Migration\FromSkmt;

/**
 * Handles plugin activation.
 *
 * Each module declares its own defaults through ::get_defaults().
 */
class Activator {

	/**
	 * Built-in module classes: the ONLY list, shared with uninstall.php (which
	 * does not boot the plugin and so cannot go through
	 * Modules::register_default_modules()). Add to it with every new module.
	 *
	 * @var array<string, class-string>
	 */
	public const MODULE_CLASSES = [
		'image_optimizer' => \Lumia\Tools\Modules\ImageOptimizer\Module::class,
		'security'        => \Lumia\Tools\Modules\Security\Module::class,
		'login'           => \Lumia\Tools\Modules\Login\Module::class,
		'files'           => \Lumia\Tools\Modules\Files\Module::class,
		'white_label'     => \Lumia\Tools\Modules\WhiteLabel\Module::class,
		'menu_creator'    => \Lumia\Tools\Modules\MenuCreator\Module::class,
		'database'        => \Lumia\Tools\Modules\Database\Module::class,
		'media'           => \Lumia\Tools\Modules\Media\Module::class,
		'activity_log'    => \Lumia\Tools\Modules\ActivityLog\Module::class,
		'smtp'            => \Lumia\Tools\Modules\Smtp\Module::class,
	];

	/**
	 * Runs when the plugin is activated.
	 */
	public static function activate(): void {
		// Migration from Studio Kyne Mini Tools FIRST: once the defaults exist
		// (`lumia_settings` with every module off, `lumia_module_*`), the copy,
		// which never overwrites a `lumia_*` option, would skip them.
		if ( FromSkmt::needed() ) {
			if ( ! FromSkmt::run() ) {
				// Stopped half-way: no defaults either, they would block the
				// copy when the migration resumes (next activation).
				return;
			}
		} else {
			// Left by a failed attempt whose data is gone since (SKMT deleted):
			// nothing to resume, Lumia must not stay on hold.
			delete_option( FromSkmt::ERROR_OPTION );
		}

		// Build the defaults, including the initial (inactive) state of each module.
		$modules_defaults = [];
		foreach ( self::MODULE_CLASSES as $id => $class ) {
			$modules_defaults[ $id ] = false;
		}

		$default_settings = [
			'global'  => [
				'update_channel' => 'stable',
			],
			'modules' => $modules_defaults,
		];

		// Create the global option only if it does not exist yet.
		if ( false === get_option( 'lumia_settings' ) ) {
			add_option( 'lumia_settings', $default_settings );
		}

		// Let each module initialize its own options when needed.
		foreach ( self::MODULE_CLASSES as $id => $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}

			$defaults   = $class::get_defaults();
			$option_key = 'lumia_module_' . $id;

			if ( ! empty( $defaults ) && false === get_option( $option_key ) ) {
				add_option( $option_key, $defaults );
			}
		}
	}
}
