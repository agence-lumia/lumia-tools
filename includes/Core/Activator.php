<?php
namespace Lumia\Tools\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Gère l'activation du plugin.
 *
 * Chaque module déclare ses propres defaults via ::get_defaults().
 */
class Activator {

	/**
	 * Classes des modules intégrés : la SEULE liste, partagée avec
	 * uninstall.php (qui ne boote pas le plugin et ne peut donc pas passer par
	 * Modules::register_default_modules()). À compléter à chaque nouveau module.
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
	 * Exécuté à l'activation du plugin.
	 */
	public static function activate(): void {
		// Construire les defaults en incluant l'état initial de chaque module (inactif).
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

		// Créer l'option globale uniquement si elle n'existe pas encore.
		if ( false === get_option( 'lumia_settings' ) ) {
			add_option( 'lumia_settings', $default_settings );
		}

		// Laisser chaque module initialiser ses propres options si nécessaire.
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
