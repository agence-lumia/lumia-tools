<?php
namespace Lumia\Tools\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Autoloader simple PSR-4 pour le plugin.
 */
class Autoloader {

	/**
	 * Enregistre l'autoloader.
	 */
	public static function register(): void {
		spl_autoload_register( [ __CLASS__, 'autoload' ] );
	}

	/**
	 * Charge une classe.
	 *
	 * @param string $class_name Nom complet de la classe.
	 */
	public static function autoload( string $class_name ): void {
		$prefix = 'Lumia\\Tools\\';

		// Vérifier que la classe appartient au namespace du plugin
		if ( strpos( $class_name, $prefix ) !== 0 ) {
			return;
		}

		// Retirer le prefix
		$relative_class = substr( $class_name, strlen( $prefix ) );

		// Base des includes. On privilégie la constante définie par le bootstrap,
		// mais on retombe sur un chemin calculé depuis ce fichier : lors de la
		// désinstallation, WordPress ne charge que uninstall.php (pas le bootstrap),
		// donc LUMIA_INCLUDES_DIR n'est pas définie et l'autoload doit rester fonctionnel.
		$base = defined( 'LUMIA_INCLUDES_DIR' ) ? LUMIA_INCLUDES_DIR : dirname( __DIR__ ) . '/';

		// Convertir en chemin de fichier
		$file = $base . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
}
