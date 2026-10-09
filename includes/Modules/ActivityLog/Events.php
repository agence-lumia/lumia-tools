<?php
namespace Lumia\Tools\Modules\ActivityLog;

defined( 'ABSPATH' ) || exit;

/**
 * Catalogue des événements journalisés : familles et libellés.
 *
 * Un événement appartient à une seule famille ; c'est la famille qui sert
 * d'unité aux exclusions et au filtre de la liste. La colonne `event` de la
 * table ne stocke que la clé : le libellé est traduit à l'affichage, si bien
 * qu'un journal écrit en français se relit en anglais après changement de
 * langue.
 */
class Events {

	/**
	 * Familles d'événements.
	 *
	 * @return array<string, string> Clé => libellé.
	 */
	public static function groups(): array {
		return [
			'auth'     => __( 'Connexions', 'lumia-tools' ),
			'content'  => __( 'Contenus', 'lumia-tools' ),
			'media'    => __( 'Médias', 'lumia-tools' ),
			'users'    => __( 'Utilisateurs', 'lumia-tools' ),
			'plugins'  => __( 'Extensions', 'lumia-tools' ),
			'themes'   => __( 'Thèmes', 'lumia-tools' ),
			'options'  => __( 'Réglages WordPress', 'lumia-tools' ),
			'settings' => __( 'Réglages Lümia Tools', 'lumia-tools' ),
		];
	}

	/**
	 * Événements connus.
	 *
	 * @return array<string, array{group: string, label: string}>
	 */
	public static function all(): array {
		return [
			'login'            => [
				'group' => 'auth',
				'label' => __( 'Connexion', 'lumia-tools' ),
			],
			'login_failed'     => [
				'group' => 'auth',
				'label' => __( 'Échec de connexion', 'lumia-tools' ),
			],
			'logout'           => [
				'group' => 'auth',
				'label' => __( 'Déconnexion', 'lumia-tools' ),
			],
			'post_created'     => [
				'group' => 'content',
				'label' => __( 'Contenu créé', 'lumia-tools' ),
			],
			'post_updated'     => [
				'group' => 'content',
				'label' => __( 'Contenu modifié', 'lumia-tools' ),
			],
			'post_trashed'     => [
				'group' => 'content',
				'label' => __( 'Contenu mis à la corbeille', 'lumia-tools' ),
			],
			'post_restored'    => [
				'group' => 'content',
				'label' => __( 'Contenu restauré', 'lumia-tools' ),
			],
			'post_deleted'     => [
				'group' => 'content',
				'label' => __( 'Contenu supprimé', 'lumia-tools' ),
			],
			'media_added'      => [
				'group' => 'media',
				'label' => __( 'Média ajouté', 'lumia-tools' ),
			],
			'media_updated'    => [
				'group' => 'media',
				'label' => __( 'Média modifié', 'lumia-tools' ),
			],
			'media_deleted'    => [
				'group' => 'media',
				'label' => __( 'Média supprimé', 'lumia-tools' ),
			],
			'user_created'     => [
				'group' => 'users',
				'label' => __( 'Utilisateur créé', 'lumia-tools' ),
			],
			'user_updated'     => [
				'group' => 'users',
				'label' => __( 'Profil modifié', 'lumia-tools' ),
			],
			'user_role'        => [
				'group' => 'users',
				'label' => __( 'Rôle modifié', 'lumia-tools' ),
			],
			'user_deleted'     => [
				'group' => 'users',
				'label' => __( 'Utilisateur supprimé', 'lumia-tools' ),
			],
			'password_reset'   => [
				'group' => 'users',
				'label' => __( 'Mot de passe réinitialisé', 'lumia-tools' ),
			],
			'plugin_activated' => [
				'group' => 'plugins',
				'label' => __( 'Extension activée', 'lumia-tools' ),
			],
			'plugin_disabled'  => [
				'group' => 'plugins',
				'label' => __( 'Extension désactivée', 'lumia-tools' ),
			],
			'plugin_installed' => [
				'group' => 'plugins',
				'label' => __( 'Extension installée', 'lumia-tools' ),
			],
			'plugin_updated'   => [
				'group' => 'plugins',
				'label' => __( 'Extension mise à jour', 'lumia-tools' ),
			],
			'plugin_deleted'   => [
				'group' => 'plugins',
				'label' => __( 'Extension supprimée', 'lumia-tools' ),
			],
			'theme_switched'   => [
				'group' => 'themes',
				'label' => __( 'Thème activé', 'lumia-tools' ),
			],
			'theme_installed'  => [
				'group' => 'themes',
				'label' => __( 'Thème installé', 'lumia-tools' ),
			],
			'theme_updated'    => [
				'group' => 'themes',
				'label' => __( 'Thème mis à jour', 'lumia-tools' ),
			],
			'theme_deleted'    => [
				'group' => 'themes',
				'label' => __( 'Thème supprimé', 'lumia-tools' ),
			],
			'option_updated'   => [
				'group' => 'options',
				'label' => __( 'Réglage modifié', 'lumia-tools' ),
			],
			'lumia_settings'   => [
				'group' => 'settings',
				'label' => __( 'Réglages modifiés', 'lumia-tools' ),
			],
		];
	}

	/**
	 * Famille d'un événement, '' s'il est inconnu.
	 */
	public static function group_of( string $event ): string {
		return self::all()[ $event ]['group'] ?? '';
	}

	/**
	 * Libellé d'un événement ; la clé brute s'il est inconnu (ligne écrite par
	 * une version ultérieure, ou par un tiers via le filtre).
	 */
	public static function label( string $event ): string {
		return self::all()[ $event ]['label'] ?? $event;
	}

	/**
	 * Clés des événements d'une famille.
	 *
	 * @return string[]
	 */
	public static function in_group( string $group ): array {
		return array_keys(
			array_filter(
				self::all(),
				static function ( array $def ) use ( $group ): bool {
					return $def['group'] === $group;
				}
			)
		);
	}
}
