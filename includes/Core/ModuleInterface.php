<?php
namespace Lumia\Tools\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Interface que tous les modules doivent implémenter.
 */
interface ModuleInterface {

	/**
	 * Initialise le module (enregistrement des hooks).
	 */
	public function init(): void;

	/**
	 * Retourne les réglages du module.
	 *
	 * @return array<string, mixed>
	 */
	public function get_settings(): array;

	/**
	 * Enregistre les réglages du module.
	 *
	 * @param array<string, mixed> $settings
	 */
	public function save_settings( array $settings ): bool;

	/**
	 * Retourne les URLs CSS admin du module.
	 *
	 * @return string[]
	 */
	public function get_admin_css(): array;

	/**
	 * Retourne les URLs JS admin du module.
	 *
	 * @return string[]
	 */
	public function get_admin_js(): array;

	/**
	 * Retourne les données JS à injecter dans lumiaAdmin pour ce module.
	 * Typiquement : ['i18n' => ['key' => 'translated string', ...]]
	 *
	 * @return array<string, mixed>
	 */
	public function get_admin_js_data(): array;

	/**
	 * Dépendances de script (handles WordPress) à charger avant le JS du module.
	 *
	 * @return string[]
	 */
	public function get_admin_js_deps(): array;

	/**
	 * Données à joindre à l'export de configuration, en plus des réglages.
	 *
	 * @return array<string, mixed>
	 */
	public function get_export_extras(): array;

	/**
	 * Réimporte ce qu'a produit get_export_extras(), après assainissement.
	 *
	 * @param array<string, mixed> $extras Bloc lu dans le fichier importé.
	 */
	public function import_extras( array $extras ): void;

	/**
	 * Appelé quand le module est activé.
	 */
	public function on_activate(): void;

	/**
	 * Appelé quand le module est désactivé.
	 */
	public function on_deactivate(): void;
}
