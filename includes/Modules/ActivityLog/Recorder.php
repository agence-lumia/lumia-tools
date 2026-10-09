<?php
namespace Lumia\Tools\Modules\ActivityLog;

defined( 'ABSPATH' ) || exit;

use Lumia\Tools\Modules\Security\ClientIp;

/**
 * Branche le journal sur les hooks WordPress et écrit les événements.
 *
 * Chaque handler ne fait que traduire les arguments du hook en une ligne ;
 * exclusions, IP, auteur et dédoublonnage vivent dans record().
 */
class Recorder {

	/**
	 * Échecs de connexion journalisés par IP et par heure. Au-delà, les
	 * tentatives de la même IP sont ignorées jusqu'à la fin de la fenêtre : une
	 * attaque par force brute de dix mille essais remplissait sinon le plafond
	 * de lignes en une nuit, et la purge par volume effaçait tout l'historique
	 * utile — l'inverse du but.
	 */
	const FAILED_LOGIN_CAP = 10;

	/** Longueur maximale d'une valeur avant/après conservée dans le détail. */
	const VALUE_MAX = 200;

	/** Nombre maximal de chemins listés pour un changement de réglages Lümia Tools. */
	const PATHS_MAX = 30;

	/** @var string[] */
	private array $excluded_groups;

	/** @var string[] */
	private array $excluded_roles;

	private bool $anonymize_ip;

	/**
	 * Événements déjà écrits pendant cette requête (clé => true).
	 *
	 * Gutenberg enregistre un article en deux requêtes, mais Bricks écrit
	 * plusieurs métas dans la même, et une mise à jour de rôle passe par
	 * plusieurs hooks : une seule ligne par objet et par requête suffit.
	 *
	 * @var array<string, bool>
	 */
	private array $seen = [];

	/**
	 * Noms capturés avant suppression (extension, thème), relus une fois la
	 * suppression confirmée : l'en-tête du fichier n'existe plus à ce moment.
	 *
	 * @var array<string, string>
	 */
	private array $pending_names = [];

	/**
	 * @param array<string, mixed> $settings Réglages du module.
	 */
	public function __construct( array $settings ) {
		$this->excluded_groups = array_map( 'strval', (array) ( $settings['excluded_groups'] ?? [] ) );
		$this->excluded_roles  = array_map( 'strval', (array) ( $settings['excluded_roles'] ?? [] ) );
		$this->anonymize_ip    = ! empty( $settings['anonymize_ip'] );
	}

	public function register(): void {
		// Connexions.
		add_action( 'wp_login', [ $this, 'on_login' ], 10, 2 );
		add_action( 'wp_login_failed', [ $this, 'on_login_failed' ], 10, 1 );
		add_action( 'wp_logout', [ $this, 'on_logout' ], 10, 1 );

		// Contenus.
		add_action( 'transition_post_status', [ $this, 'on_transition_post_status' ], 10, 3 );
		add_action( 'post_updated', [ $this, 'on_post_updated' ], 10, 3 );
		add_action( 'trashed_post', [ $this, 'on_trashed_post' ], 10, 1 );
		add_action( 'untrashed_post', [ $this, 'on_untrashed_post' ], 10, 1 );
		add_action( 'before_delete_post', [ $this, 'on_before_delete_post' ], 10, 1 );
		add_action( 'added_post_meta', [ $this, 'on_post_meta' ], 10, 3 );
		add_action( 'updated_post_meta', [ $this, 'on_post_meta' ], 10, 3 );

		// Médias.
		add_action( 'add_attachment', [ $this, 'on_add_attachment' ], 10, 1 );
		add_action( 'attachment_updated', [ $this, 'on_attachment_updated' ], 10, 3 );
		add_action( 'delete_attachment', [ $this, 'on_delete_attachment' ], 10, 1 );

		// Utilisateurs.
		add_action( 'user_register', [ $this, 'on_user_register' ], 10, 1 );
		add_action( 'profile_update', [ $this, 'on_profile_update' ], 10, 2 );
		add_action( 'set_user_role', [ $this, 'on_set_user_role' ], 10, 3 );
		add_action( 'delete_user', [ $this, 'on_delete_user' ], 10, 2 );
		add_action( 'after_password_reset', [ $this, 'on_password_reset' ], 10, 1 );

		// Extensions et thèmes.
		add_action( 'activated_plugin', [ $this, 'on_activated_plugin' ], 10, 2 );
		add_action( 'deactivated_plugin', [ $this, 'on_deactivated_plugin' ], 10, 2 );
		add_action( 'delete_plugin', [ $this, 'on_delete_plugin' ], 10, 1 );
		add_action( 'deleted_plugin', [ $this, 'on_deleted_plugin' ], 10, 2 );
		add_action( 'upgrader_process_complete', [ $this, 'on_upgrader_complete' ], 10, 2 );
		add_action( 'switch_theme', [ $this, 'on_switch_theme' ], 10, 3 );
		add_action( 'delete_theme', [ $this, 'on_delete_theme' ], 10, 1 );
		add_action( 'deleted_theme', [ $this, 'on_deleted_theme' ], 10, 2 );

		// Réglages.
		add_action( 'updated_option', [ $this, 'on_updated_option' ], 10, 3 );
		add_action( 'added_option', [ $this, 'on_added_option' ], 10, 2 );
	}

	/* ================================================================
	 * CONNEXIONS
	 * ================================================================ */

	/**
	 * `wp_login` passe l'utilisateur en argument : l'utilisateur courant n'est
	 * pas encore positionné quand le hook se déclenche.
	 *
	 * @param string   $user_login
	 * @param \WP_User $user
	 */
	public function on_login( $user_login, $user ): void {
		if ( ! $user instanceof \WP_User ) {
			return;
		}

		$this->record( 'login', 'user', $user->ID, $user->user_login, [], $user );
	}

	/**
	 * @param string $username Identifiant saisi — valeur libre de l'attaquant.
	 */
	public function on_login_failed( $username ): void {
		$ip  = $this->client_ip();
		$key = '_lumia_al_fail_' . md5( $ip );

		$count = (int) get_transient( $key );
		if ( $count >= self::FAILED_LOGIN_CAP ) {
			return;
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		$details = [];
		if ( self::FAILED_LOGIN_CAP - 1 === $count ) {
			$details['capped'] = self::FAILED_LOGIN_CAP;
		}

		$this->record( 'login_failed', 'user', 0, sanitize_user( (string) $username ), $details, null, false );
	}

	/**
	 * Depuis WordPress 5.5, `wp_logout` reçoit l'identifiant : l'utilisateur
	 * courant est déjà remis à zéro quand il se déclenche.
	 *
	 * @param int $user_id
	 */
	public function on_logout( $user_id = 0 ): void {
		$user = get_userdata( (int) $user_id );
		if ( ! $user instanceof \WP_User ) {
			return;
		}

		$this->record( 'logout', 'user', $user->ID, $user->user_login, [], $user );
	}

	/* ================================================================
	 * CONTENUS
	 * ================================================================ */

	/**
	 * Création : la seule transition qui nous intéresse ici. Les autres
	 * (publication d'un brouillon, dépublication) arrivent aussi par
	 * `post_updated`, qui voit l'avant et l'après.
	 *
	 * @param string   $new_status
	 * @param string   $old_status
	 * @param \WP_Post $post
	 */
	public function on_transition_post_status( $new_status, $old_status, $post ): void {
		if ( ! $post instanceof \WP_Post || ! $this->is_tracked_post( $post ) ) {
			return;
		}

		// L'auto-draft que WordPress crée à l'ouverture de l'éditeur n'est pas
		// une création : l'utilisateur n'a encore rien enregistré.
		if ( ! in_array( $old_status, [ 'new', 'auto-draft' ], true ) || in_array( $new_status, [ 'auto-draft', 'inherit', 'trash' ], true ) ) {
			return;
		}

		$this->record( 'post_created', 'post', $post->ID, $this->post_label( $post ), [ 'status' => $new_status ] );
	}

	/**
	 * @param int      $post_id
	 * @param \WP_Post $after
	 * @param \WP_Post $before
	 */
	public function on_post_updated( $post_id, $after, $before ): void {
		if ( ! $after instanceof \WP_Post || ! $before instanceof \WP_Post || ! $this->is_tracked_post( $after ) ) {
			return;
		}

		// Premier enregistrement d'un auto-draft : déjà journalisé comme
		// création. Corbeille et restauration ont leurs propres événements
		// (wp_trash_post passe lui aussi par wp_update_post).
		if ( in_array( $before->post_status, [ 'new', 'auto-draft', 'trash' ], true ) || in_array( $after->post_status, [ 'auto-draft', 'trash' ], true ) ) {
			return;
		}

		$changes = $this->post_changes( $before, $after );

		// Gutenberg renvoie l'article une seconde fois pour les boîtes méta :
		// rien n'a changé dans les champs, il n'y a rien à dire.
		if ( ! $changes ) {
			return;
		}

		$this->record( 'post_updated', 'post', $after->ID, $this->post_label( $after ), [ 'changes' => $changes ] );
	}

	/**
	 * @param int $post_id
	 */
	public function on_trashed_post( $post_id ): void {
		$post = get_post( (int) $post_id );
		if ( $post instanceof \WP_Post && $this->is_tracked_post( $post ) ) {
			$this->record( 'post_trashed', 'post', $post->ID, $this->post_label( $post ) );
		}
	}

	/**
	 * @param int $post_id
	 */
	public function on_untrashed_post( $post_id ): void {
		$post = get_post( (int) $post_id );
		if ( $post instanceof \WP_Post && $this->is_tracked_post( $post ) ) {
			$this->record( 'post_restored', 'post', $post->ID, $this->post_label( $post ) );
		}
	}

	/**
	 * `before_delete_post` plutôt que `deleted_post` : après suppression, le
	 * titre n'est plus lisible.
	 *
	 * @param int $post_id
	 */
	public function on_before_delete_post( $post_id ): void {
		$post = get_post( (int) $post_id );

		// Les auto-drafts sont purgés par le cron de WordPress chaque semaine.
		if ( ! $post instanceof \WP_Post || 'auto-draft' === $post->post_status || ! $this->is_tracked_post( $post ) ) {
			return;
		}

		$this->record( 'post_deleted', 'post', $post->ID, $this->post_label( $post ) );
	}

	/**
	 * Constructeurs de pages : Bricks et Elementor rangent le contenu en
	 * postmeta et n'appellent pas wp_update_post() — sans ce branchement, une
	 * page refaite de fond en comble dans Bricks n'apparaissait nulle part.
	 * Même chose pour le texte alternatif d'un média.
	 *
	 * @param int    $meta_id
	 * @param int    $object_id
	 * @param string $meta_key
	 */
	public function on_post_meta( $meta_id, $object_id, $meta_key ): void {
		$meta_key = (string) $meta_key;

		if ( '_wp_attachment_image_alt' === $meta_key ) {
			// Le texte alternatif généré au téléversement (Image Optimizer) fait
			// partie de l'ajout, pas d'une modification.
			if ( isset( $this->seen[ 'media_added|attachment|' . (int) $object_id . '|' ] ) ) {
				return;
			}

			$post = get_post( (int) $object_id );
			if ( $post instanceof \WP_Post && 'attachment' === $post->post_type ) {
				$this->record( 'media_updated', 'attachment', $post->ID, $this->post_label( $post ), [ 'changes' => [ 'alt' => true ] ] );
			}
			return;
		}

		/**
		 * Clés de postmeta qui portent le contenu d'une page.
		 *
		 * @param string[] $keys
		 */
		$keys = (array) apply_filters(
			'lumia_activity_log_content_meta_keys',
			[
				'_bricks_page_content_2',
				'_bricks_page_header_2',
				'_bricks_page_footer_2',
				'_elementor_data',
			]
		);

		if ( ! in_array( $meta_key, $keys, true ) ) {
			return;
		}

		$post = get_post( (int) $object_id );
		if ( ! $post instanceof \WP_Post || ! $this->is_tracked_post( $post ) || 'auto-draft' === $post->post_status ) {
			return;
		}

		$this->record( 'post_updated', 'post', $post->ID, $this->post_label( $post ), [ 'changes' => [ 'builder' => $meta_key ] ] );
	}

	/* ================================================================
	 * MÉDIAS
	 * ================================================================ */

	/**
	 * @param int $post_id
	 */
	public function on_add_attachment( $post_id ): void {
		$post = get_post( (int) $post_id );
		if ( $post instanceof \WP_Post ) {
			$this->record( 'media_added', 'attachment', $post->ID, $this->post_label( $post ), [ 'mime' => $post->post_mime_type ] );
		}
	}

	/**
	 * @param int      $post_id
	 * @param \WP_Post $after
	 * @param \WP_Post $before
	 */
	public function on_attachment_updated( $post_id, $after, $before ): void {
		if ( ! $after instanceof \WP_Post || ! $before instanceof \WP_Post ) {
			return;
		}

		$changes = $this->post_changes( $before, $after );
		if ( $changes ) {
			$this->record( 'media_updated', 'attachment', $after->ID, $this->post_label( $after ), [ 'changes' => $changes ] );
		}
	}

	/**
	 * @param int $post_id
	 */
	public function on_delete_attachment( $post_id ): void {
		$post = get_post( (int) $post_id );
		if ( $post instanceof \WP_Post ) {
			$this->record( 'media_deleted', 'attachment', $post->ID, $this->post_label( $post ) );
		}
	}

	/* ================================================================
	 * UTILISATEURS
	 * ================================================================ */

	/**
	 * @param int $user_id
	 */
	public function on_user_register( $user_id ): void {
		$user = get_userdata( (int) $user_id );
		if ( $user instanceof \WP_User ) {
			$this->record( 'user_created', 'user', $user->ID, $user->user_login, [ 'roles' => array_values( $user->roles ) ] );
		}
	}

	/**
	 * @param int      $user_id
	 * @param \WP_User $old
	 */
	public function on_profile_update( $user_id, $old ): void {
		$user = get_userdata( (int) $user_id );
		if ( ! $user instanceof \WP_User || ! $old instanceof \WP_User ) {
			return;
		}

		$changes = [];
		foreach ( [
			'user_email'   => 'email',
			'display_name' => 'display_name',
			'user_url'     => 'url',
		] as $field => $name ) {
			if ( (string) $old->$field !== (string) $user->$field ) {
				$changes[ $name ] = [
					'from' => $this->short( $old->$field ),
					'to'   => $this->short( $user->$field ),
				];
			}
		}

		// Le hash seul change : on note le fait, jamais la valeur.
		if ( $old->user_pass !== $user->user_pass ) {
			$changes['password'] = true;
		}

		if ( $changes ) {
			$this->record( 'user_updated', 'user', $user->ID, $user->user_login, [ 'changes' => $changes ] );
		}
	}

	/**
	 * wp_insert_user() pose le rôle AVANT `user_register` : à la création,
	 * ce hook part avec une liste d'anciens rôles vide, et la création est
	 * déjà journalisée.
	 *
	 * @param int      $user_id
	 * @param string   $role
	 * @param string[] $old_roles
	 */
	public function on_set_user_role( $user_id, $role, $old_roles ): void {
		$old_roles = array_values( (array) $old_roles );
		if ( ! $old_roles || [ (string) $role ] === $old_roles ) {
			return;
		}

		$user = get_userdata( (int) $user_id );
		if ( $user instanceof \WP_User ) {
			$this->record(
				'user_role',
				'user',
				$user->ID,
				$user->user_login,
				[
					'from' => $old_roles,
					'to'   => [ (string) $role ],
				]
			);
		}
	}

	/**
	 * @param int      $user_id
	 * @param int|null $reassign
	 */
	public function on_delete_user( $user_id, $reassign ): void {
		$user = get_userdata( (int) $user_id );
		if ( ! $user instanceof \WP_User ) {
			return;
		}

		$details = [ 'roles' => array_values( $user->roles ) ];
		if ( $reassign ) {
			$target              = get_userdata( (int) $reassign );
			$details['reassign'] = $target instanceof \WP_User ? $target->user_login : (int) $reassign;
		}

		$this->record( 'user_deleted', 'user', $user->ID, $user->user_login, $details );
	}

	/**
	 * La réinitialisation se fait déconnecté : l'auteur est l'utilisateur
	 * concerné, pas l'utilisateur courant (qui vaut 0).
	 *
	 * @param \WP_User $user
	 */
	public function on_password_reset( $user ): void {
		if ( $user instanceof \WP_User ) {
			$this->record( 'password_reset', 'user', $user->ID, $user->user_login, [], $user );
		}
	}

	/* ================================================================
	 * EXTENSIONS ET THÈMES
	 * ================================================================ */

	/**
	 * @param string $plugin
	 * @param bool   $network_wide
	 */
	public function on_activated_plugin( $plugin, $network_wide = false ): void {
		$this->record( 'plugin_activated', 'plugin', 0, $this->plugin_name( (string) $plugin ), $this->plugin_details( (string) $plugin, (bool) $network_wide ) );
	}

	/**
	 * @param string $plugin
	 * @param bool   $network_wide
	 */
	public function on_deactivated_plugin( $plugin, $network_wide = false ): void {
		$this->record( 'plugin_disabled', 'plugin', 0, $this->plugin_name( (string) $plugin ), $this->plugin_details( (string) $plugin, (bool) $network_wide ) );
	}

	/**
	 * @param string $plugin
	 */
	public function on_delete_plugin( $plugin ): void {
		$this->pending_names[ 'plugin:' . $plugin ] = $this->plugin_name( (string) $plugin );
	}

	/**
	 * @param string $plugin
	 * @param bool   $deleted
	 */
	public function on_deleted_plugin( $plugin, $deleted ): void {
		if ( ! $deleted ) {
			return;
		}

		$name = $this->pending_names[ 'plugin:' . $plugin ] ?? (string) $plugin;
		$this->record( 'plugin_deleted', 'plugin', 0, $name, [ 'file' => (string) $plugin ] );
	}

	/**
	 * Installations et mises à jour, extensions comme thèmes. Les mises à jour
	 * automatiques passent aussi par là, lancées par le cron : elles
	 * apparaissent sans auteur, ce qui est exactement ce qu'elles sont.
	 *
	 * @param \WP_Upgrader         $upgrader
	 * @param array<string, mixed> $extra
	 */
	public function on_upgrader_complete( $upgrader, $extra ): void {
		$type   = (string) ( $extra['type'] ?? '' );
		$action = (string) ( $extra['action'] ?? '' );

		if ( ! in_array( $type, [ 'plugin', 'theme' ], true ) || ! in_array( $action, [ 'install', 'update' ], true ) ) {
			return;
		}

		if ( 'install' === $action ) {
			$this->record_install( $type, $upgrader );
			return;
		}

		$plural = $type . 's';
		$items  = isset( $extra[ $plural ] ) ? (array) $extra[ $plural ] : ( isset( $extra[ $type ] ) ? [ $extra[ $type ] ] : [] );

		foreach ( $items as $item ) {
			$item = (string) $item;

			if ( 'plugin' === $type ) {
				$data = $this->plugin_data( $item );
				$this->record(
					'plugin_updated',
					'plugin',
					0,
					'' !== $data['Name'] ? $data['Name'] : $item,
					[
						'file'    => $item,
						'version' => $data['Version'],
					]
				);
			} else {
				$theme = wp_get_theme( $item );
				$this->record(
					'theme_updated',
					'theme',
					0,
					$theme->exists() ? (string) $theme->get( 'Name' ) : $item,
					[
						'stylesheet' => $item,
						'version'    => $theme->exists() ? (string) $theme->get( 'Version' ) : '',
					]
				);
			}
		}
	}

	/**
	 * @param string    $new_name
	 * @param \WP_Theme $new_theme
	 * @param \WP_Theme $old_theme
	 */
	public function on_switch_theme( $new_name, $new_theme, $old_theme ): void {
		$details = [];
		if ( $old_theme instanceof \WP_Theme ) {
			$details['from'] = (string) $old_theme->get( 'Name' );
		}

		$this->record( 'theme_switched', 'theme', 0, (string) $new_name, $details );
	}

	/**
	 * @param string $stylesheet
	 */
	public function on_delete_theme( $stylesheet ): void {
		$theme = wp_get_theme( (string) $stylesheet );

		$this->pending_names[ 'theme:' . $stylesheet ] = $theme->exists() ? (string) $theme->get( 'Name' ) : (string) $stylesheet;
	}

	/**
	 * @param string $stylesheet
	 * @param bool   $deleted
	 */
	public function on_deleted_theme( $stylesheet, $deleted ): void {
		if ( ! $deleted ) {
			return;
		}

		$name = $this->pending_names[ 'theme:' . $stylesheet ] ?? (string) $stylesheet;
		$this->record( 'theme_deleted', 'theme', 0, $name, [ 'stylesheet' => (string) $stylesheet ] );
	}

	/* ================================================================
	 * RÉGLAGES
	 * ================================================================ */

	/**
	 * @param string $option
	 * @param mixed  $old
	 * @param mixed  $value
	 */
	public function on_updated_option( $option, $old, $value ): void {
		$option = (string) $option;

		if ( $this->is_lumia_option( $option ) ) {
			$this->record_lumia_option( $option, $old, $value );
			return;
		}

		$labels = self::tracked_options();
		if ( ! isset( $labels[ $option ] ) ) {
			return;
		}

		$this->record(
			'option_updated',
			'option',
			0,
			$option,
			[
				'label' => $labels[ $option ],
				'from'  => $this->short( $old ),
				'to'    => $this->short( $value ),
			]
		);
	}

	/**
	 * Premier enregistrement d'un écran de module : l'option n'existait pas,
	 * WordPress déclenche `added_option` et non `updated_option`.
	 *
	 * @param string $option
	 * @param mixed  $value
	 */
	public function on_added_option( $option, $value ): void {
		$option = (string) $option;

		if ( $this->is_lumia_option( $option ) ) {
			$this->record_lumia_option( $option, [], $value );
		}
	}

	/**
	 * Options WordPress suivies et leur libellé.
	 *
	 * @return array<string, string>
	 */
	public static function tracked_options(): array {
		/**
		 * Options WordPress dont la modification est journalisée.
		 *
		 * @param array<string, string> $options Nom de l'option => libellé.
		 */
		return (array) apply_filters(
			'lumia_activity_log_tracked_options',
			[
				'blogname'               => __( 'Titre du site', 'lumia-tools' ),
				'blogdescription'        => __( 'Slogan', 'lumia-tools' ),
				'siteurl'                => __( 'Adresse web de WordPress', 'lumia-tools' ),
				'home'                   => __( 'Adresse web du site', 'lumia-tools' ),
				'admin_email'            => __( 'E-mail d\'administration', 'lumia-tools' ),
				'users_can_register'     => __( 'Inscription ouverte', 'lumia-tools' ),
				'default_role'           => __( 'Rôle par défaut', 'lumia-tools' ),
				'blog_public'            => __( 'Visibilité pour les moteurs de recherche', 'lumia-tools' ),
				'permalink_structure'    => __( 'Structure des permaliens', 'lumia-tools' ),
				'WPLANG'                 => __( 'Langue du site', 'lumia-tools' ),
				'timezone_string'        => __( 'Fuseau horaire', 'lumia-tools' ),
				'show_on_front'          => __( 'La page d\'accueil affiche', 'lumia-tools' ),
				'page_on_front'          => __( 'Page d\'accueil', 'lumia-tools' ),
				'page_for_posts'         => __( 'Page des articles', 'lumia-tools' ),
				'default_comment_status' => __( 'Commentaires autorisés', 'lumia-tools' ),
				'comment_registration'   => __( 'Commentaires réservés aux inscrits', 'lumia-tools' ),
			]
		);
	}

	/* ================================================================
	 * ÉCRITURE
	 * ================================================================ */

	/**
	 * Écrit un événement, sauf exclusion ou doublon.
	 *
	 * @param array<string, mixed> $details
	 * @param \WP_User|null        $actor           Auteur explicite ; l'utilisateur courant sinon.
	 * @param bool                 $apply_role_rule Faux pour les événements anonymes (échec de connexion) :
	 *                                              exclure les administrateurs ne doit pas masquer les
	 *                                              attaques qui les visent.
	 */
	private function record( string $event, string $object_type, int $object_id, string $label, array $details = [], ?\WP_User $actor = null, bool $apply_role_rule = true ): void {
		$group = Events::group_of( $event );

		if ( '' === $group || in_array( $group, $this->excluded_groups, true ) ) {
			return;
		}

		$dedupe_key = $event . '|' . $object_type . '|' . $object_id . '|' . ( $object_id ? '' : $label );
		// Une option ne déclenche son hook que si sa valeur change : deux
		// lignes dans la même requête sont deux changements réels.
		if ( ! in_array( $event, [ 'login_failed', 'option_updated' ], true ) && isset( $this->seen[ $dedupe_key ] ) ) {
			return;
		}

		if ( null === $actor ) {
			$current = wp_get_current_user();
			$actor   = $current->exists() ? $current : null;
		}

		$roles = $actor ? array_values( (array) $actor->roles ) : [];

		if ( $apply_role_rule && $roles && array_intersect( $roles, $this->excluded_roles ) ) {
			return;
		}

		$details['via'] = self::request_channel();

		/**
		 * Dernier mot avant l'écriture d'un événement du journal.
		 *
		 * @param array<string, mixed>|false $row Ligne à écrire ; false pour l'ignorer.
		 */
		$row = apply_filters(
			'lumia_activity_log_record',
			[
				'user_id'      => $actor ? $actor->ID : 0,
				'user_login'   => $actor ? $actor->user_login : '',
				'user_role'    => $roles[0] ?? '',
				'ip'           => $this->client_ip(),
				'event_group'  => $group,
				'event'        => $event,
				'object_type'  => $object_type,
				'object_id'    => $object_id,
				'object_label' => $label,
				'details'      => $details,
			]
		);

		if ( ! is_array( $row ) ) {
			return;
		}

		$this->seen[ $dedupe_key ] = true;
		Store::insert( $row );
	}

	/**
	 * Réglages Lümia Tools : on liste les chemins modifiés, sans les valeurs —
	 * un module peut stocker un secret (mot de passe SMTP à venir). Seuls les
	 * interrupteurs d'activation des modules, booléens, sont montrés.
	 *
	 * @param mixed $old
	 * @param mixed $value
	 */
	private function record_lumia_option( string $option, $old, $value ): void {
		// Une écriture sans utilisateur est technique (updater, cron), pas un
		// choix de réglage.
		if ( ! is_user_logged_in() ) {
			return;
		}

		$paths = [];
		$this->diff_paths( is_array( $old ) ? $old : [], is_array( $value ) ? $value : [], '', $paths );

		if ( ! $paths ) {
			return;
		}

		$details = [ 'paths' => array_slice( $paths, 0, self::PATHS_MAX ) ];

		if ( 'lumia_settings' === $option ) {
			$before  = is_array( $old ) && isset( $old['modules'] ) ? (array) $old['modules'] : [];
			$after   = is_array( $value ) && isset( $value['modules'] ) ? (array) $value['modules'] : [];
			$toggled = [];
			foreach ( $after as $module => $active ) {
				if ( (bool) ( $before[ $module ] ?? false ) !== (bool) $active ) {
					$toggled[ (string) $module ] = (bool) $active;
				}
			}
			if ( $toggled ) {
				$details['modules'] = $toggled;
			}
			$label = 'global';
		} else {
			$label = substr( $option, strlen( 'lumia_module_' ) );
		}

		$this->record( 'lumia_settings', 'lumia', 0, $label, $details );
	}

	/**
	 * Chemins pointés des feuilles qui diffèrent entre deux tableaux.
	 *
	 * @param array<mixed, mixed> $a
	 * @param array<mixed, mixed> $b
	 * @param string[]            $paths
	 */
	private function diff_paths( array $a, array $b, string $prefix, array &$paths ): void {
		foreach ( array_unique( array_merge( array_keys( $a ), array_keys( $b ) ) ) as $key ) {
			$path = '' === $prefix ? (string) $key : $prefix . '.' . $key;
			$va   = $a[ $key ] ?? null;
			$vb   = $b[ $key ] ?? null;

			// Les listes (rôles, IP) se comparent en bloc : lister chaque
			// indice décalé par une insertion ne dirait rien d'utile.
			if ( is_array( $va ) && is_array( $vb ) && ! wp_is_numeric_array( $va ) && ! wp_is_numeric_array( $vb ) ) {
				$this->diff_paths( $va, $vb, $path, $paths );
			} elseif ( $va !== $vb ) {
				$paths[] = $path;
			}
		}
	}

	/**
	 * Installation d'une extension ou d'un thème.
	 *
	 * @param \WP_Upgrader $upgrader
	 */
	private function record_install( string $type, $upgrader ): void {
		if ( 'plugin' === $type ) {
			$file = is_object( $upgrader ) && method_exists( $upgrader, 'plugin_info' ) ? (string) $upgrader->plugin_info() : '';
			$data = '' !== $file ? $this->plugin_data( $file ) : [
				'Name'    => '',
				'Version' => '',
			];
			$this->record(
				'plugin_installed',
				'plugin',
				0,
				'' !== $data['Name'] ? $data['Name'] : $file,
				[
					'file'    => $file,
					'version' => $data['Version'],
				]
			);
			return;
		}

		$theme = is_object( $upgrader ) && method_exists( $upgrader, 'theme_info' ) ? $upgrader->theme_info() : false;
		if ( $theme instanceof \WP_Theme ) {
			$this->record(
				'theme_installed',
				'theme',
				0,
				(string) $theme->get( 'Name' ),
				[
					'stylesheet' => $theme->get_stylesheet(),
					'version'    => (string) $theme->get( 'Version' ),
				]
			);
		}
	}

	/* ================================================================
	 * OUTILS
	 * ================================================================ */

	/**
	 * Types de contenu suivis : ceux qui ont une interface d'édition, hors
	 * médias (famille à part). Révisions, éléments de menu et changesets n'en
	 * ont pas, ce qui les écarte sans liste noire à entretenir.
	 */
	private function is_tracked_post( \WP_Post $post ): bool {
		if ( 'attachment' === $post->post_type || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return false;
		}

		static $types = null;
		if ( null === $types ) {
			/**
			 * Types de contenu journalisés.
			 *
			 * @param string[] $types
			 */
			$types = (array) apply_filters( 'lumia_activity_log_post_types', array_values( get_post_types( [ 'show_ui' => true ] ) ) );
		}

		return in_array( $post->post_type, $types, true );
	}

	/**
	 * Champs modifiés entre deux versions d'un contenu. Les textes longs ne
	 * sont notés que comme « modifiés » : les copier doublerait la base.
	 *
	 * @return array<string, mixed>
	 */
	private function post_changes( \WP_Post $before, \WP_Post $after ): array {
		$changes = [];

		foreach ( [
			'post_title'     => 'title',
			'post_status'    => 'status',
			'post_name'      => 'slug',
			'post_parent'    => 'parent',
			'post_date'      => 'date',
			'menu_order'     => 'order',
			'comment_status' => 'comments',
		] as $field => $name ) {
			if ( (string) $before->$field !== (string) $after->$field ) {
				$changes[ $name ] = [
					'from' => $this->short( $before->$field ),
					'to'   => $this->short( $after->$field ),
				];
			}
		}

		if ( (int) $before->post_author !== (int) $after->post_author ) {
			$from              = get_userdata( (int) $before->post_author );
			$to                = get_userdata( (int) $after->post_author );
			$changes['author'] = [
				'from' => $from instanceof \WP_User ? $from->user_login : (string) $before->post_author,
				'to'   => $to instanceof \WP_User ? $to->user_login : (string) $after->post_author,
			];
		}

		foreach ( [
			'post_content'  => 'content',
			'post_excerpt'  => 'excerpt',
			'post_password' => 'password',
		] as $field => $name ) {
			if ( (string) $before->$field !== (string) $after->$field ) {
				$changes[ $name ] = true;
			}
		}

		return $changes;
	}

	private function post_label( \WP_Post $post ): string {
		$title = trim( wp_strip_all_tags( (string) $post->post_title ) );

		if ( '' === $title && 'attachment' === $post->post_type ) {
			$title = wp_basename( (string) get_attached_file( $post->ID ) );
		}

		return '' !== $title ? $title : '#' . $post->ID;
	}

	/**
	 * @return array{Name: string, Version: string}
	 */
	private function plugin_data( string $file ): array {
		$path = WP_PLUGIN_DIR . '/' . $file;

		if ( '' === $file || ! is_file( $path ) ) {
			return [
				'Name'    => '',
				'Version' => '',
			];
		}

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$data = get_plugin_data( $path, false, false );

		return [
			'Name'    => (string) ( $data['Name'] ?? '' ),
			'Version' => (string) ( $data['Version'] ?? '' ),
		];
	}

	private function plugin_name( string $file ): string {
		$name = $this->plugin_data( $file )['Name'];
		return '' !== $name ? $name : $file;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function plugin_details( string $file, bool $network_wide ): array {
		$details = [
			'file'    => $file,
			'version' => $this->plugin_data( $file )['Version'],
		];

		if ( $network_wide ) {
			$details['network'] = true;
		}

		return $details;
	}

	private function is_lumia_option( string $option ): bool {
		return 'lumia_settings' === $option || 0 === strpos( $option, 'lumia_module_' );
	}

	/**
	 * Adresse du client selon la source déclarée dans le module Sécurité — lue
	 * même quand le module est inactif : c'est là que vit la configuration du
	 * proxy du site, et se fier aux en-têtes sans elle laisserait n'importe qui
	 * écrire l'IP de son choix dans le journal.
	 */
	private function client_ip(): string {
		// WP-CLI renseigne lui-même REMOTE_ADDR à 127.0.0.1 : une adresse
		// factice, pas celle de qui que ce soit.
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ! isset( $_SERVER['REMOTE_ADDR'] ) ) {
			return '';
		}

		$security = get_option( 'lumia_module_security', [] );
		$source   = is_array( $security ) ? ( $security['authentication']['ip_source'] ?? '' ) : '';
		$ip       = ClientIp::resolve( ClientIp::sanitize_source( $source ) );

		if ( ClientIp::UNKNOWN === $ip ) {
			return '';
		}

		return $this->anonymize_ip ? wp_privacy_anonymize_ip( $ip ) : $ip;
	}

	/**
	 * Canal de la requête : distingue un clic dans l'admin d'une commande
	 * WP-CLI, d'une tâche planifiée ou d'un appel d'API.
	 */
	private static function request_channel(): string {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}
		if ( wp_doing_cron() ) {
			return 'cron';
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'rest';
		}
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return 'xmlrpc';
		}
		if ( wp_doing_ajax() ) {
			return 'ajax';
		}
		return 'web';
	}

	/**
	 * Valeur scalaire courte pour le détail ; les tableaux sont encodés en JSON.
	 *
	 * @param mixed $value
	 */
	private function short( $value ): string {
		if ( is_bool( $value ) ) {
			$value = $value ? '1' : '0';
		} elseif ( ! is_scalar( $value ) && null !== $value ) {
			$value = (string) wp_json_encode( $value );
		}

		$value = (string) $value;

		return mb_strlen( $value ) > self::VALUE_MAX ? mb_substr( $value, 0, self::VALUE_MAX ) . '…' : $value;
	}
}
