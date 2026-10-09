<?php
namespace Lumia\Tools\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Gère l'enregistrement et le chargement des modules.
 */
class Modules {

	/**
	 * Liste des modules enregistrés.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $registered = [];

	/**
	 * Instances des modules actifs.
	 *
	 * @var array<string, ModuleInterface>
	 */
	private array $active = [];

	/**
	 * Settings manager.
	 */
	private Settings $settings;

	/**
	 * Constructeur.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Enregistre les modules par défaut via filter + hook impératif.
	 * Doit être appelé au hook init ou plus tard (JIT i18n WP 6.7+).
	 */
	public function register_default_modules( bool $only_active = false ): void {
		$defaults = [
			'image_optimizer' => [
				'name'        => __( 'Image Optimizer', 'lumia-tools' ),
				'description' => __( 'Optimisation des images', 'lumia-tools' ),
				'menu_label'  => __( 'Image Optimizer', 'lumia-tools' ),
				'menu_desc'   => __( 'Optimiser les images', 'lumia-tools' ),
				'class'       => 'Lumia\\Tools\\Modules\\ImageOptimizer\\Module',
				'icon'        => 'image',
			],
			'security'        => [
				'name'        => __( 'Sécurité', 'lumia-tools' ),
				'description' => __( 'Authentification, hardening et logging de sécurité', 'lumia-tools' ),
				'menu_label'  => __( 'Sécurité', 'lumia-tools' ),
				'menu_desc'   => __( 'Gérer la sécurité', 'lumia-tools' ),
				'class'       => 'Lumia\\Tools\\Modules\\Security\\Module',
				'icon'        => 'shield',
			],
			'login'           => [
				'name'        => __( 'Connexion', 'lumia-tools' ),
				'description' => __( 'Personnalisez le design et le branding de la page de connexion WordPress.', 'lumia-tools' ),
				'menu_label'  => __( 'Connexion', 'lumia-tools' ),
				'menu_desc'   => __( 'Personnaliser la page de connexion', 'lumia-tools' ),
				'class'       => 'Lumia\\Tools\\Modules\\Login\\Module',
				'icon'        => 'log-in',
			],
			'files'           => [
				'name'        => __( 'Fichiers', 'lumia-tools' ),
				'description' => __( 'Explorateur et gestionnaire de fichiers WordPress.', 'lumia-tools' ),
				'menu_label'  => __( 'Fichiers', 'lumia-tools' ),
				'menu_desc'   => __( 'Gérer les fichiers', 'lumia-tools' ),
				'class'       => 'Lumia\\Tools\\Modules\\Files\\Module',
				'icon'        => 'folder',
			],
			'white_label'     => [
				'name'        => __( 'Marque Blanche', 'lumia-tools' ),
				'description' => __( 'Personnalisez l\'interface d\'administration WordPress pour vos clients.', 'lumia-tools' ),
				'menu_label'  => __( 'Marque Blanche', 'lumia-tools' ),
				'menu_desc'   => __( 'Personnaliser l\'admin WP', 'lumia-tools' ),
				'class'       => 'Lumia\\Tools\\Modules\\WhiteLabel\\Module',
				'icon'        => 'palette',
			],
			'menu_creator'    => [
				'name'        => __( 'Créateur de menu', 'lumia-tools' ),
				'description' => __( 'Personnalisez la navigation WordPress pour vos rôles et utilisateurs.', 'lumia-tools' ),
				'menu_label'  => __( 'Créateur de menu', 'lumia-tools' ),
				'menu_desc'   => __( 'Personnaliser les menus', 'lumia-tools' ),
				'class'       => 'Lumia\\Tools\\Modules\\MenuCreator\\Module',
				'icon'        => 'menu',
			],
			'database'        => [
				'name'        => __( 'Base de données', 'lumia-tools' ),
				'description' => __( 'Explorez, éditez et exportez vos tables WordPress.', 'lumia-tools' ),
				'menu_label'  => __( 'Base de données', 'lumia-tools' ),
				'menu_desc'   => __( 'Gérer la base de données', 'lumia-tools' ),
				'class'       => 'Lumia\\Tools\\Modules\\Database\\Module',
				'icon'        => 'database',
			],
			'media'           => [
				'name'        => __( 'Médias', 'lumia-tools' ),
				'description' => __( 'Organisez vos médias en dossiers virtuels.', 'lumia-tools' ),
				'menu_label'  => __( 'Médias', 'lumia-tools' ),
				'menu_desc'   => __( 'Organiser les médias', 'lumia-tools' ),
				'class'       => 'Lumia\\Tools\\Modules\\Media\\Module',
				'icon'        => 'folder-tree',
			],
			'activity_log'    => [
				'name'        => __( 'Journal d\'activité', 'lumia-tools' ),
				'description' => __( 'Qui a modifié quoi, et quand : connexions, contenus, extensions, utilisateurs et réglages.', 'lumia-tools' ),
				'menu_label'  => __( 'Journal d\'activité', 'lumia-tools' ),
				'menu_desc'   => __( 'Consulter l\'historique', 'lumia-tools' ),
				'class'       => 'Lumia\\Tools\\Modules\\ActivityLog\\Module',
				'icon'        => 'history',
			],
			'smtp'            => [
				'name'        => __( 'SMTP', 'lumia-tools' ),
				'description' => __( 'Envoi des mails par un serveur SMTP authentifié ou l\'API Brevo, mail de test et journal des mails.', 'lumia-tools' ),
				'menu_label'  => __( 'SMTP', 'lumia-tools' ),
				'menu_desc'   => __( 'Configurer l\'envoi des mails', 'lumia-tools' ),
				'class'       => 'Lumia\\Tools\\Modules\\Smtp\\Module',
				'icon'        => 'mail',
			],
		];

		/**
		 * Permet d'ajouter/surcharger des modules depuis d'autres plugins/thèmes.
		 * Format : [ 'module_id' => [ 'name' => ..., 'class' => ..., ... ] ]
		 */
		$definitions = apply_filters( 'lumia_module_definitions', $defaults );

		if ( ! is_array( $definitions ) ) {
			$definitions = $defaults;
		}

		foreach ( $definitions as $id => $args ) {
			$id = sanitize_key( (string) $id );
			if ( '' === $id || ! is_array( $args ) ) {
				continue;
			}

			$args = $this->normalize_definition( $args );
			if ( empty( $args['class'] ) ) {
				continue;
			}

			if ( $only_active && ! $this->is_active( $id ) ) {
				continue;
			}

			$this->register( $id, $args );
		}

		/**
		 * Hook impératif pour enregistrer des modules via $modules->register(...).
		 */
		do_action( 'lumia_register_modules', $this, $only_active );
	}

	/**
	 * Normalise une définition de module.
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	private function normalize_definition( array $args ): array {
		$normalized = wp_parse_args(
			$args,
			[
				'name'        => '',
				'description' => '',
				'menu_label'  => '',
				'menu_desc'   => '',
				'class'       => '',
				'icon'        => 'package',
			]
		);

		$normalized['name']        = is_string( $normalized['name'] ) ? $normalized['name'] : '';
		$normalized['description'] = is_string( $normalized['description'] ) ? $normalized['description'] : '';
		$normalized['menu_label']  = is_string( $normalized['menu_label'] ) ? $normalized['menu_label'] : '';
		$normalized['menu_desc']   = is_string( $normalized['menu_desc'] ) ? $normalized['menu_desc'] : '';
		$normalized['class']       = is_string( $normalized['class'] ) ? ltrim( $normalized['class'], '\\' ) : '';
		$normalized['icon']        = is_string( $normalized['icon'] ) ? sanitize_key( $normalized['icon'] ) : 'package';

		return $normalized;
	}

	/**
	 * Enregistre un module.
	 *
	 * @param array<string, mixed> $args
	 */
	public function register( string $id, array $args ): void {
		$this->registered[ $id ] = wp_parse_args(
			$args,
			[
				'name'        => '',
				'description' => '',
				'menu_label'  => '',
				'menu_desc'   => '',
				'class'       => '',
				'icon'        => 'package',
			]
		);
	}

	/**
	 * Retourne tous les modules enregistrés.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_all(): array {
		return $this->registered;
	}

	/**
	 * Retourne un module spécifique.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get( string $id ): ?array {
		return $this->registered[ $id ] ?? null;
	}

	/**
	 * Vérifie si un module est actif.
	 */
	public function is_active( string $id ): bool {
		return (bool) $this->settings->get( "modules.{$id}", false );
	}

	/**
	 * Active un module et appelle son hook on_activate().
	 *
	 * L'état déjà atteint est un succès, pas un échec : update_option() renvoie
	 * false quand la valeur ne change pas, et s'y fier faisait annoncer une
	 * erreur — sans appeler on_activate() — pour un module déjà actif.
	 */
	public function activate( string $id ): bool {
		if ( ! isset( $this->registered[ $id ] ) ) {
			return false;
		}

		if ( $this->is_active( $id ) ) {
			return true;
		}

		$result = $this->settings->set( "modules.{$id}", true );

		if ( $result ) {
			$instance = $this->make_instance( $id );
			if ( $instance ) {
				$instance->on_activate();
			}
		}

		return $result;
	}

	/**
	 * Désactive un module et appelle son hook on_deactivate().
	 *
	 * Voir activate() : l'état déjà atteint est un succès.
	 */
	public function deactivate( string $id ): bool {
		if ( ! isset( $this->registered[ $id ] ) ) {
			return false;
		}

		if ( ! $this->is_active( $id ) ) {
			return true;
		}

		// Utiliser l'instance active si disponible, sinon en créer une temporaire.
		$instance = $this->active[ $id ] ?? $this->make_instance( $id );

		$result = $this->settings->set( "modules.{$id}", false );

		if ( $result && $instance ) {
			$instance->on_deactivate();
		}

		return $result;
	}

	/**
	 * Instancie et initialise tous les modules actifs.
	 */
	public function init_active_modules(): void {
		foreach ( $this->registered as $id => $module ) {
			if ( ! $this->is_active( $id ) ) {
				continue;
			}

			if ( ! class_exists( $module['class'] ) ) {
				continue;
			}

			$instance = new $module['class']( $id );

			if ( $instance instanceof ModuleInterface ) {
				$instance->init();
				$this->active[ $id ] = $instance;
			}
		}
	}

	/**
	 * Retourne les instances des modules actifs.
	 *
	 * @return array<string, ModuleInterface>
	 */
	public function get_active_instances(): array {
		return $this->active;
	}

	/**
	 * Retourne une instance d'un module enregistré, active ou non.
	 *
	 * L'instance active est réutilisée quand elle existe ; sinon une instance
	 * non initialisée est créée. Permet de lire le schéma de réglages d'un
	 * module désactivé (import de configuration) sans l'accrocher à WordPress.
	 */
	public function get_instance( string $id ): ?ModuleInterface {
		return $this->active[ $id ] ?? $this->make_instance( $id );
	}

	/**
	 * Crée une instance du module sans l'initialiser (pour les hooks lifecycle).
	 */
	private function make_instance( string $id ): ?ModuleInterface {
		$class = $this->registered[ $id ]['class'] ?? '';

		if ( empty( $class ) || ! class_exists( $class ) ) {
			return null;
		}

		$instance = new $class( $id );

		return $instance instanceof ModuleInterface ? $instance : null;
	}
}
