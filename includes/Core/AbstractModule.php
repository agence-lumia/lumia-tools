<?php
namespace Lumia\Tools\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Base commune pour les modules LUMIA.
 *
 * Fournit : gestion des options, assets admin vides par défaut,
 * hooks de lifecycle et méthodes statiques pour l'install/uninstall.
 */
abstract class AbstractModule implements ModuleInterface {

	/**
	 * Identifiant unique du module (ex: "image_optimizer").
	 */
	protected string $id;

	/**
	 * Constructeur : reçoit l'ID du module depuis le registre.
	 */
	public function __construct( string $id ) {
		$this->id = $id;
	}

	/* ================================================================
	 * OPTIONS
	 * ================================================================ */

	/**
	 * Clé d'option WordPress pour ce module.
	 */
	protected function get_module_option_key(): string {
		return 'lumia_module_' . $this->id;
	}

	/**
	 * Lit les réglages du module depuis la base, fusionnés avec les defaults.
	 *
	 * La fusion est RÉCURSIVE. wp_parse_args() ne fusionne qu'au premier
	 * niveau : dès qu'une clé existe en base, sa valeur remplace le défaut en
	 * bloc. Pour les modules à réglages imbriqués (Sécurité, Connexion, Marque
	 * blanche), toute sous-clé ajoutée dans une version ultérieure serait donc
	 * absente des installations existantes tant que l'utilisateur n'a pas
	 * rouvert l'écran et re-sauvegardé — un nouveau réglage dont le défaut vaut
	 * true arriverait silencieusement à false chez tout le monde.
	 *
	 * @param array<string, mixed> $defaults Valeurs par défaut à appliquer.
	 * @return array<string, mixed>
	 */
	protected function get_module_settings( array $defaults = [] ): array {
		$stored = get_option( $this->get_module_option_key(), [] );

		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		return empty( $defaults ) ? $stored : self::merge_defaults( $defaults, $stored );
	}

	/**
	 * Fusionne les réglages stockés par-dessus les valeurs par défaut.
	 *
	 * On ne descend que dans les tableaux ASSOCIATIFS : une liste (rôles
	 * autorisés, IP whitelistées…) doit être remplacée en bloc, jamais fusionnée
	 * index par index — sinon retirer une entrée serait impossible, la valeur
	 * par défaut ressurgissant à sa position.
	 *
	 * @param array<string, mixed> $defaults Valeurs de référence.
	 * @param array<string, mixed> $stored   Valeurs lues en base.
	 * @return array<string, mixed>
	 */
	protected static function merge_defaults( array $defaults, array $stored ): array {
		$merged = $defaults;

		foreach ( $stored as $key => $value ) {
			$default = $defaults[ $key ] ?? null;

			$merged[ $key ] = ( is_array( $value ) && is_array( $default ) && ! self::is_list( $default ) )
				? self::merge_defaults( $default, $value )
				: $value;
		}

		return $merged;
	}

	/**
	 * Vrai pour un tableau à clés numériques consécutives (ou vide).
	 *
	 * @param array<mixed> $value
	 */
	private static function is_list( array $value ): bool {
		return [] === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * Sauvegarde les réglages du module en base.
	 *
	 * @param array<string, mixed> $data
	 */
	protected function save_module_settings( array $data ): bool {
		return update_option( $this->get_module_option_key(), $data );
	}

	/**
	 * Convertit des réglages STOCKÉS en charge utile de FORMULAIRE.
	 *
	 * save_settings() est écrit pour ce que poste l'écran de réglages. Pour la
	 * plupart des modules, cette forme coïncide avec celle qui est stockée, et
	 * l'identité suffit. Quand elle diffère — Sécurité stocke sous
	 * authentication/hardening ce que le formulaire envoie à plat — le module
	 * surcharge cette méthode.
	 *
	 * Sert à l'import de configuration : un fichier importé doit emprunter
	 * exactement le chemin d'assainissement du formulaire, jamais un second.
	 *
	 * @param array<string, mixed> $stored Réglages tels qu'ils sont en base.
	 * @return array<string, mixed>
	 */
	public function to_form_payload( array $stored ): array {
		return $stored;
	}

	/**
	 * Données du module à joindre à l'export de configuration, EN PLUS de son
	 * option `lumia_module_{id}`.
	 *
	 * Un module qui range une partie de son état dans une option à lui
	 * (Créateur de menu : les profils sous `lumia_wl_menu_profiles`) doit la
	 * déclarer ici, sinon elle est absente du JSON d'export et l'utilisateur
	 * croit avoir sauvegardé une configuration complète.
	 *
	 * @return array<string, mixed> Vide = rien à exporter au-delà des réglages.
	 */
	public function get_export_extras(): array {
		return [];
	}

	/**
	 * Réimporte ce qu'a produit get_export_extras().
	 *
	 * Même règle que pour les réglages : le contenu du fichier ne doit jamais
	 * atterrir tel quel en base — il repasse par l'assainisseur du module.
	 *
	 * @param array<string, mixed> $extras Bloc lu dans le fichier importé.
	 */
	public function import_extras( array $extras ): void {
		// Rien par défaut.
	}

	/* ================================================================
	 * ASSETS (défauts vides)
	 * ================================================================ */

	public function get_admin_css(): array {
		return [];
	}

	public function get_admin_js(): array {
		return [];
	}

	/**
	 * Handles de scripts déjà enregistrés dont dépend le JS du module.
	 *
	 * Permet de réutiliser une bibliothèque tierce partagée (SortableJS…) plutôt
	 * que d'en renvoyer l'URL depuis get_admin_js() : deux modules qui font ce
	 * dernier choix produisent deux handles différents pour le même fichier, que
	 * WordPress ne peut pas dédupliquer.
	 *
	 * @return string[]
	 */
	public function get_admin_js_deps(): array {
		return [];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_admin_js_data(): array {
		return [];
	}

	/* ================================================================
	 * LIFECYCLE
	 * ================================================================ */

	/**
	 * Appelé quand le module est activé.
	 * Surcharger pour créer des tables, programmer des crons, etc.
	 */
	public function on_activate(): void {}

	/**
	 * Appelé quand le module est désactivé.
	 * Surcharger pour nettoyer les crons, etc.
	 */
	public function on_deactivate(): void {}

	/* ================================================================
	 * CAPACITÉ REQUISE
	 * ================================================================ */

	/**
	 * Capacité exigée pour ouvrir l'écran du module et appeler ses endpoints.
	 *
	 * `manage_options` par défaut, comme le reste de l'extension. À surcharger
	 * par tout module dont le pouvoir dépasse le site courant : sous multisite,
	 * `manage_options` est une capacité PAR SITE, si bien que l'administrateur
	 * d'un simple sous-site l'obtient. Un gestionnaire de fichiers ou un
	 * éditeur SQL lui livrent alors le réseau entier — les fichiers et la base
	 * sont communs, eux.
	 *
	 * Les écrans concernés surchargent donc vers `manage_network_options`, que
	 * seul un super-administrateur détient (et que personne ne détient hors
	 * multisite : d'où le test).
	 */
	public static function get_required_capability(): string {
		return 'manage_options';
	}

	/* ================================================================
	 * INSTALL / UNINSTALL
	 * ================================================================ */

	/**
	 * Valeurs par défaut des options du module (créées à l'activation du plugin).
	 * Retourner [] si les defaults sont gérés à la volée dans get_settings().
	 *
	 * @return array<string, mixed>
	 */
	public static function get_defaults(): array {
		return [];
	}

	/**
	 * Clés à supprimer lors de la désinstallation.
	 *
	 * `meta` désigne des post_meta et `user_meta` des métadonnées d'utilisateur :
	 * ce sont deux tables distinctes, une clé rangée dans la mauvaise n'est
	 * jamais supprimée.
	 *
	 * Toutes les clés sont optionnelles : uninstall.php lit chacune avec `?? []`.
	 * `post_type` et `taxonomy` déclenchent la suppression des contenus et des
	 * termes correspondants. `tables` liste des tables propres au module, SANS
	 * préfixe (supprimées par DROP TABLE), `cron` des hooks de tâches
	 * planifiées — aussi désinscrits à la désactivation de l'extension.
	 *
	 * @return array{options?: string[], meta?: string[], user_meta?: string[], post_type?: string[], taxonomy?: string[], tables?: string[], cron?: string[]}
	 */
	public static function get_uninstall_keys(): array {
		return [
			'options'   => [],
			'meta'      => [],
			'user_meta' => [],
		];
	}
}
