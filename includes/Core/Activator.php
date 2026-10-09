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
				self::cli_report( false );
				// Stopped half-way: no defaults either, they would block the
				// copy when the migration resumes (next activation).
				return;
			}
			self::cli_report( true );
		} else {
			// Left by a failed attempt whose data is gone since (SKMT deleted):
			// nothing to resume, Lumia must not stay on hold.
			FromSkmt::clear_error();
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

	/**
	 * Under WP-CLI, says how the migration went: `wp plugin activate` would
	 * otherwise print "Success" over a migration that stopped half-way.
	 */
	private static function cli_report( bool $success ): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		if ( $success ) {
			\WP_CLI::log( __( 'Migration from Studio Kyne Mini Tools complete. You can delete the old plugin.', 'lumia-tools' ) );
			return;
		}

		/* translators: %s: identifier of the migration step that failed, e.g. post_meta. */
		$message = sprintf( __( 'The migration from Studio Kyne Mini Tools stopped at the %s step: Lümia Tools loads none of its modules until it is complete. Do not delete Studio Kyne Mini Tools before then: its uninstallation would erase the data not migrated yet. Deactivate then reactivate Lümia Tools to resume it.', 'lumia-tools' ), FromSkmt::failed_step() );
		$detail  = FromSkmt::failure_detail();
		if ( '' !== $detail ) {
			/* translators: %s: database error message. */
			$message .= ' ' . sprintf( __( 'Database error: %s', 'lumia-tools' ), $detail );
		}

		\WP_CLI::warning( $message );
	}
}
