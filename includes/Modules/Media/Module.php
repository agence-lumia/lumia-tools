<?php
namespace Lumia\Tools\Modules\Media;

defined( 'ABSPATH' ) || exit;

use Lumia\Tools\Core\AbstractModule;

/**
 * Module Médias — organisation de la médiathèque en dossiers virtuels.
 *
 * Les dossiers sont des termes d'une taxonomie (lumia_media_folder) attachée au
 * post type "attachment". Les fichiers ne sont jamais déplacés sur le disque :
 * seule l'association taxonomique change.
 *
 * Le filtrage se fait CÔTÉ SERVEUR. La taxonomie est enregistrée avec un
 * query_var, ce qui suffit à ce que WordPress le laisse passer : dans
 * wp_ajax_query_attachments(), le core whiteliste explicitement le query_var de
 * chaque taxonomie d'attachment (wp-admin/includes/ajax-actions.php). On
 * intercepte ensuite la valeur pour la traduire en tax_query — WP_Query
 * résoudrait sinon le query_var par slug, alors qu'on manipule des term_id.
 *
 * Conséquence : la vue ne transporte jamais de liste d'IDs. Elle envoie juste
 * lumia_folder=<id>, et la pagination / le scroll infini de WordPress continuent
 * de fonctionner nativement quelle que soit la taille de la médiathèque.
 */
class Module extends AbstractModule {

	const TAXONOMY = 'lumia_media_folder';

	/** Query var transportant le dossier courant jusqu'à WP_Query. */
	const QUERY_VAR = 'lumia_folder';

	/** Valeur sentinelle : médias n'appartenant à aucun dossier. */
	const UNASSIGNED = '__none__';

	/** Clé de term meta stockant la couleur d'un dossier. */
	const COLOR_META = 'lumia_folder_color';

	/**
	 * Au-delà de ce nombre de relations dossier↔média, on cesse de charger les
	 * paires en mémoire pour compter exactement (voir rollup_counts()).
	 */
	const MAX_PAIRS = 50000;

	/** Couleurs prédéfinies autorisées ('' = aucune / défaut). */
	const FOLDER_COLORS = [ '', '#ef4444', '#f59e0b', '#22c55e', '#0ea5e9', '#8b5cf6', '#64748b' ];

	/**
	 * Libellés lisibles des couleurs, pour le survol des pastilles : un code
	 * hexadécimal ne dit rien à l'utilisateur.
	 *
	 * @return array<string,string> hex => libellé
	 */
	private static function folder_color_labels(): array {
		return [
			'#ef4444' => __( 'Rouge', 'lumia-tools' ),
			'#f59e0b' => __( 'Orange', 'lumia-tools' ),
			'#22c55e' => __( 'Vert', 'lumia-tools' ),
			'#0ea5e9' => __( 'Bleu', 'lumia-tools' ),
			'#8b5cf6' => __( 'Violet', 'lumia-tools' ),
			'#64748b' => __( 'Gris', 'lumia-tools' ),
		];
	}

	public function init(): void {
		// init() est appelé pendant le hook `init` (via init_active_modules).
		// On enregistre donc la taxonomie immédiatement : un add_action('init')
		// ajouté ici, à la même priorité que le hook en cours, ne serait jamais
		// déclenché (WP n'exécute pas les callbacks ajoutés à la priorité courante).
		if ( did_action( 'init' ) ) {
			$this->register_taxonomy();
		} else {
			add_action( 'init', [ $this, 'register_taxonomy' ] );
		}

		// Point d'accroche déterministe : le core déclenche cette action à la fin
		// de wp_enqueue_media(). Elle couvre donc TOUS les contextes qui affichent
		// une médiathèque, sans avoir à deviner lequel ni à quel moment il la
		// charge — upload.php, éditeur de blocs, Personnalisateur, écran des
		// widgets, et les constructeurs frontend type Bricks (?bricks=run).
		//
		// Se caler sur admin_enqueue_scripts à priorité fixe ne marchait pas :
		// chaque écran appelle wp_enqueue_media() à un moment différent, et les
		// plus tardifs (Personnalisateur, widgets) passaient après notre test.
		add_action( 'wp_enqueue_media', [ $this, 'enqueue_assets' ] );

		// Vue liste de upload.php : seul cas où l'on veut le panneau sans que
		// wp.media soit nécessairement chargé.
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ], 100 );

		// Filtrage serveur.
		add_filter( 'ajax_query_attachments_args', [ $this, 'filter_media_query' ] );
		add_action( 'pre_get_posts', [ $this, 'filter_list_view' ] );

		// Rattachement automatique des nouveaux médias au dossier courant.
		add_action( 'add_attachment', [ $this, 'assign_uploaded_attachment' ] );

		// Expose les dossiers d'un média au modèle Backbone, pour que le panneau
		// de détails puisse les afficher et les modifier sans requête dédiée.
		add_filter( 'wp_prepare_attachment_for_js', [ $this, 'expose_attachment_folders' ], 10, 2 );

		add_action( 'wp_ajax_lumia_media_get_folders', [ $this, 'ajax_get_folders' ] );
		add_action( 'wp_ajax_lumia_media_create_folder', [ $this, 'ajax_create_folder' ] );
		add_action( 'wp_ajax_lumia_media_rename_folder', [ $this, 'ajax_rename_folder' ] );
		add_action( 'wp_ajax_lumia_media_delete_folder', [ $this, 'ajax_delete_folder' ] );
		add_action( 'wp_ajax_lumia_media_move_items', [ $this, 'ajax_move_items' ] );
		add_action( 'wp_ajax_lumia_media_move_folder', [ $this, 'ajax_move_folder' ] );
		add_action( 'wp_ajax_lumia_media_set_folder_color', [ $this, 'ajax_set_folder_color' ] );
	}

	/* ================================================================
	 * TAXONOMIE
	 * ================================================================ */

	public function register_taxonomy(): void {
		register_taxonomy(
			self::TAXONOMY,
			'attachment',
			[
				'hierarchical'          => true,
				'public'                => false,
				'publicly_queryable'    => false,
				'show_ui'               => false,
				'show_admin_column'     => false,
				'show_in_nav_menus'     => false,
				'show_in_rest'          => false,
				'rewrite'               => false,

				// Indispensable : c'est ce query_var que wp_ajax_query_attachments()
				// whiteliste, et donc notre seul canal jusqu'à WP_Query.
				'query_var'             => self::QUERY_VAR,

				// Le callback par défaut (_update_post_term_count) ne compte que les
				// posts en statut "publish". Les médias sont en "inherit" : sans ce
				// remplacement, tous les compteurs de dossiers restent bloqués à 0.
				'update_count_callback' => '_update_generic_term_count',

				'labels'                => [
					'name'          => __( 'Dossiers médias', 'lumia-tools' ),
					'singular_name' => __( 'Dossier média', 'lumia-tools' ),
				],
			]
		);
	}

	/* ================================================================
	 * FILTRAGE SERVEUR
	 * ================================================================ */

	/**
	 * Traduit lumia_folder en tax_query pour la médiathèque AJAX (vue grille et
	 * toutes les modales wp.media).
	 *
	 * @param array<string, mixed> $query
	 * @return array<string, mixed>
	 */
	public function filter_media_query( array $query ): array {
		$raw = isset( $_REQUEST['query'][ self::QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( $_REQUEST['query'][ self::QUERY_VAR ] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification -- requête AJAX de wp.media, nonce porté par WordPress.

		// On retire toujours la valeur brute : laissée en place, WP_Query
		// tenterait de la résoudre comme un slug de terme.
		unset( $query[ self::QUERY_VAR ] );

		$tax_query = $this->build_tax_query( $raw );
		if ( null !== $tax_query ) {
			$query['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery
		}

		// Ordre déterministe. WordPress trie la médiathèque sur post_date seule ;
		// or plusieurs médias téléversés dans la même seconde sont à égalité, et
		// MySQL est alors libre de les ordonner différemment d'une page à
		// l'autre. Le scroll infini répète alors des éléments et en saute
		// d'autres (constaté : 274 médias, 226 affichés). Départager par ID rend
		// la pagination stable sans changer l'ordre visible.
		$orderby = $query['orderby'] ?? 'date';
		if ( 'date' === $orderby ) {
			$order            = strtoupper( (string) ( $query['order'] ?? 'DESC' ) );
			$order            = 'ASC' === $order ? 'ASC' : 'DESC';
			$query['orderby'] = [
				'date' => $order,
				'ID'   => $order,
			];
		}

		return $query;
	}

	/**
	 * Même filtrage pour la vue liste de upload.php (?mode=list), qui ne passe
	 * pas par l'AJAX de wp.media.
	 */
	public function filter_list_view( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'upload' !== $screen->id ) {
			return;
		}

		$raw = isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification -- simple filtre d'affichage en GET.

		$tax_query = $this->build_tax_query( $raw );
		if ( null === $tax_query ) {
			return;
		}

		// Vider la valeur brute est indispensable. La taxonomie ayant un
		// query_var, WP_Query::parse_tax_query() ajouterait sinon SA propre
		// clause, résolue par slug ("4", "__none__" ne correspondent à aucun
		// terme), combinée en ET avec la nôtre — résultat toujours vide.
		$query->set( self::QUERY_VAR, '' );
		$query->set( 'tax_query', $tax_query );
	}

	/**
	 * Construit la tax_query correspondant à une valeur de dossier.
	 * Retourne null quand aucun filtrage ne doit s'appliquer.
	 *
	 * @param mixed $raw '' / null = tous, UNASSIGNED = non classés, sinon term_id.
	 * @return array<int, array<string, mixed>>|null
	 */
	private function build_tax_query( $raw ): ?array {
		if ( null === $raw || '' === $raw ) {
			return null;
		}

		$raw = sanitize_text_field( wp_unslash( (string) $raw ) );

		if ( self::UNASSIGNED === $raw ) {
			return [
				[
					'taxonomy' => self::TAXONOMY,
					'operator' => 'NOT EXISTS',
				],
			];
		}

		$term_id = (int) $raw;
		if ( $term_id <= 0 ) {
			return null;
		}

		return [
			[
				'taxonomy'         => self::TAXONOMY,
				'field'            => 'term_id',
				'terms'            => $term_id,
				'include_children' => true,
			],
		];
	}

	/**
	 * Range un média fraîchement téléversé dans le dossier actif au moment de
	 * l'upload (transmis par le JS dans le POST du plupload).
	 */
	public function assign_uploaded_attachment( int $attachment_id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification -- upload plupload, nonce vérifié par async-upload.php.
		$folder_id = isset( $_POST[ self::QUERY_VAR ] ) ? (int) $_POST[ self::QUERY_VAR ] : 0;

		if ( $folder_id <= 0 || ! current_user_can( 'upload_files' ) ) {
			return;
		}
		if ( ! term_exists( $folder_id, self::TAXONOMY ) ) {
			return;
		}

		wp_set_object_terms( $attachment_id, [ $folder_id ], self::TAXONOMY );
	}

	/* ================================================================
	 * ASSETS
	 * ================================================================ */

	/**
	 * Charge l'UI dossiers sur TOUTE page d'admin qui embarque wp.media —
	 * upload.php, mais aussi l'éditeur de contenu et les modales d'insertion.
	 */
	public function enqueue_assets(): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}

		// Deux situations distinctes :
		//  - media-views chargé : wp.media est là, on étend AttachmentsBrowser ;
		//  - upload.php en vue liste : wp.media n'est PAS chargé, mais on veut
		//    quand même le panneau (media.js le monte alors en autonome).
		$has_media_views = wp_script_is( 'media-views', 'enqueued' );

		if ( ! $has_media_views && ! $this->is_upload_list_screen() ) {
			return;
		}

		// Design system LUMIA. On charge UNIQUEMENT tokens.css (custom properties
		// pures) + les composants : reset.css est scopé sous .lumia-admin-wrap et
		// layout.css override le chrome wp-admin — aucun des deux n'a sa place
		// sur une page WordPress native.
		wp_enqueue_style( 'lumia-tokens-css', LUMIA_ASSETS_URL . 'admin/css/tokens.css', [], LUMIA_VERSION );
		wp_enqueue_style( 'lumia-components-css', LUMIA_ASSETS_URL . 'admin/css/components.css', [ 'lumia-tokens-css' ], LUMIA_VERSION );
		wp_enqueue_style( 'lumia-buttons-css', LUMIA_ASSETS_URL . 'admin/css/buttons.css', [ 'lumia-components-css' ], LUMIA_VERSION );
		wp_enqueue_style( 'lumia-notifications-css', LUMIA_ASSETS_URL . 'admin/css/notifications.css', [], LUMIA_VERSION );
		wp_enqueue_style( 'lumia-media-css', LUMIA_ASSETS_URL . 'admin/css/modules/media.css', [ 'lumia-components-css' ], LUMIA_VERSION );

		// admin.js fournit les modales nommées (lumiaModalOpen/Close) utilisées
		// par l'UI médias ; notifications.js fournit window.lumiaShowToast.
		wp_enqueue_script( 'lumia-admin-js', LUMIA_ASSETS_URL . 'admin/js/admin.js', [], LUMIA_VERSION, true );
		wp_enqueue_script( 'lumia-notifications-js', LUMIA_ASSETS_URL . 'admin/js/notifications.js', [], LUMIA_VERSION, true );
		wp_enqueue_script( 'lumia-sortable-js', LUMIA_ASSETS_URL . 'admin/js/vendor/sortable.min.js', [], LUMIA_VERSION, true );

		// media-views n'est déclaré en dépendance que s'il est déjà là : l'ajouter
		// systématiquement forcerait le chargement de toute la médiathèque
		// Backbone sur la vue liste, qui n'en a pas besoin.
		$deps = [ 'jquery', 'lumia-admin-js', 'lumia-notifications-js', 'lumia-sortable-js' ];
		if ( $has_media_views ) {
			$deps[] = 'media-views';
		}

		wp_enqueue_script(
			'lumia-media-js',
			LUMIA_ASSETS_URL . 'admin/js/modules/media.js',
			$deps,
			LUMIA_VERSION,
			true
		);

		wp_localize_script(
			'lumia-media-js',
			'lumiaMedia',
			[
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'lumia_admin_nonce' ),
				'colors'      => self::FOLDER_COLORS,
				'colorLabels' => self::folder_color_labels(),
				'queryVar'    => self::QUERY_VAR,
				'unassigned'  => self::UNASSIGNED,
				// L'interface doit refléter la garde serveur : sans ce drapeau, un
				// auteur voit les boutons « Nouveau dossier » / « Supprimer » et ne
				// récolte qu'un refus après coup. Le serveur reste seul juge —
				// guard_manage() ne dépend d'aucune valeur envoyée par le client.
				'canManage'   => current_user_can( self::CAP_MANAGE ),
				'i18n'        => [
					'folders'         => __( 'Dossiers', 'lumia-tools' ),
					'color'           => __( 'Couleur', 'lumia-tools' ),
					'defaultColor'    => __( 'Par défaut', 'lumia-tools' ),
					'newFolder'       => __( 'Nouveau dossier', 'lumia-tools' ),
					'newSubfolder'    => __( 'Nouveau sous-dossier', 'lumia-tools' ),
					'folderName'      => __( 'Nom du dossier', 'lumia-tools' ),
					'allMedia'        => __( 'Tous les médias', 'lumia-tools' ),
					'unorganized'     => __( 'Non classés', 'lumia-tools' ),
					'deleteFolder'    => __( 'Supprimer le dossier ?', 'lumia-tools' ),
					'deleteFolderMsg' => __( 'Les médias de ce dossier et de ses sous-dossiers seront déplacés à la racine.', 'lumia-tools' ),
					'rename'          => __( 'Renommer', 'lumia-tools' ),
					'delete'          => __( 'Supprimer', 'lumia-tools' ),
					'create'          => __( 'Créer', 'lumia-tools' ),
					'save'            => __( 'Enregistrer', 'lumia-tools' ),
					'cancel'          => __( 'Annuler', 'lumia-tools' ),
					'loading'         => __( 'Chargement…', 'lumia-tools' ),
					'folderCreated'   => __( 'Dossier créé.', 'lumia-tools' ),
					'folderRenamed'   => __( 'Dossier renommé.', 'lumia-tools' ),
					'folderDeleted'   => __( 'Dossier supprimé.', 'lumia-tools' ),
					'folderMoved'     => __( 'Dossier déplacé.', 'lumia-tools' ),
					'itemsMoved'      => __( 'média(s) déplacé(s).', 'lumia-tools' ),
					'itemsAdded'      => __( 'média(s) ajouté(s) au dossier.', 'lumia-tools' ),
					'itemsRemoved'    => __( 'média(s) retiré(s) du dossier.', 'lumia-tools' ),
					'noFolder'        => __( 'Aucun dossier', 'lumia-tools' ),
					'folderUpdated'   => __( 'Dossiers mis à jour.', 'lumia-tools' ),
					'itemsRefused'    => __( 'média(s) ignoré(s) : vous n\'avez pas le droit de les modifier.', 'lumia-tools' ),
				],
			]
		);
	}

	/**
	 * upload.php affiché en vue liste (?mode=list) — le seul écran où l'on veut
	 * le panneau sans que wp.media soit chargé.
	 */
	private function is_upload_list_screen(): bool {
		if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'upload' !== $screen->id ) {
			return false;
		}

		// WordPress mémorise le dernier mode choisi dans une user meta ; le
		// paramètre d'URL prime quand il est présent.
		$mode = isset( $_GET['mode'] ) // phpcs:ignore WordPress.Security.NonceVerification
			? sanitize_text_field( wp_unslash( $_GET['mode'] ) ) // phpcs:ignore WordPress.Security.NonceVerification
			: (string) get_user_option( 'media_library_mode', get_current_user_id() );

		return 'list' === $mode;
	}

	/* ================================================================
	 * AJAX
	 * ================================================================ */

	/**
	 * Capacité exigée pour MODIFIER l'arborescence (créer, renommer, supprimer,
	 * déplacer, colorer un dossier).
	 *
	 * `upload_files` — la seule garde d'origine — est la capacité de TÉLÉVERSER,
	 * pas celle d'organiser la bibliothèque du site. Un simple auteur pouvait
	 * donc renommer les dossiers d'autrui et en supprimer un avec toute sa
	 * descendance, d'un seul appel. Les dossiers sont une taxonomie : la
	 * capacité qui décrit ce pouvoir est `manage_categories`, détenue à partir
	 * du rôle éditeur.
	 */
	const CAP_MANAGE = 'manage_categories';

	/** Capacité exigée pour LIRE l'arborescence et classer ses propres médias. */
	const CAP_USE = 'upload_files';

	/** Garde de lecture : voir les dossiers, filtrer la médiathèque. */
	private function guard(): void {
		check_ajax_referer( 'lumia_admin_nonce', 'nonce' );
		if ( ! current_user_can( self::CAP_USE ) ) {
			wp_send_json_error( [ 'message' => __( 'Permissions insuffisantes.', 'lumia-tools' ) ] );
		}
	}

	/** Garde d'écriture : toute mutation de l'arborescence elle-même. */
	private function guard_manage(): void {
		$this->guard();
		if ( ! current_user_can( self::CAP_MANAGE ) ) {
			wp_send_json_error( [ 'message' => __( 'Vous n\'avez pas le droit de modifier l\'organisation de la médiathèque.', 'lumia-tools' ) ] );
		}
	}

	/**
	 * Ajoute les dossiers d'un média à son modèle Backbone (clé lumiaFolders).
	 *
	 * wp.media prépare déjà chaque pièce jointe pour le JS : on se greffe ici
	 * plutôt que d'ouvrir un endpoint, ce qui garde le panneau de détails
	 * synchrone avec la grille sans requête supplémentaire.
	 *
	 * @param array<string, mixed> $response Données préparées par le core.
	 * @param \WP_Post $attachment Pièce jointe concernée.
	 * @return array<string, mixed>
	 */
	public function expose_attachment_folders( array $response, $attachment ): array {
		$terms = get_the_terms( $attachment, self::TAXONOMY );

		$response['lumiaFolders'] = is_wp_error( $terms ) || ! $terms
			? []
			: array_values(
				array_map(
					static function ( $term ) {
						return (int) $term->term_id;
					},
					$terms
				)
			);

		return $response;
	}

	public function ajax_get_folders(): void {
		$this->guard();
		wp_send_json_success( $this->get_folder_payload() );
	}

	/**
	 * Arborescence + compteurs. Le compteur affiché inclut les descendants, ce
	 * qu'on attend d'un dossier ; il est calculé par remontée en PHP plutôt que
	 * par une requête par terme.
	 *
	 * @return array<string, mixed>
	 */
	private function get_folder_payload(): array {
		$terms = get_terms(
			[
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			]
		);

		if ( is_wp_error( $terms ) ) {
			$terms = [];
		}

		$parent = [];
		foreach ( $terms as $term ) {
			$parent[ $term->term_id ] = (int) $term->parent;
		}

		$total = $this->rollup_counts( $terms, $parent );

		$folders = [];
		foreach ( $terms as $term ) {
			$folders[] = [
				'id'     => (int) $term->term_id,
				'name'   => $term->name,
				'slug'   => $term->slug,
				'parent' => (int) $term->parent,
				'count'  => $total[ $term->term_id ] ?? 0,
				'color'  => (string) get_term_meta( $term->term_id, self::COLOR_META, true ),
			];
		}

		return [
			'folders'     => $folders,
			'unorganized' => $this->count_unassigned(),
		];
	}

	/**
	 * Compteur par dossier, descendants inclus.
	 *
	 * Un média pouvant appartenir à plusieurs dossiers, additionner simplement
	 * les compteurs des enfants dans le parent le comptait deux fois quand il
	 * était rangé à la fois dans le parent et dans un de ses sous-dossiers. On
	 * fait donc l'union des ENSEMBLES de médias, pas la somme des nombres.
	 *
	 * Une seule requête récupère toutes les paires (dossier, média). Au-delà de
	 * MAX_PAIRS on retombe sur l'addition : approximative en multi-dossiers,
	 * mais on refuse de charger un volume de relations non borné en mémoire.
	 *
	 * @param \WP_Term[] $terms  Termes de la taxonomie.
	 * @param array<int, int> $parent_map term_id => parent_id.
	 * @return array<int, int> term_id => nombre de médias distincts.
	 */
	private function rollup_counts( array $terms, array $parent_map ): array {
		global $wpdb;

		$direct = [];
		foreach ( $terms as $term ) {
			$direct[ (int) $term->term_id ] = (int) $term->count;
		}

		// Lecture directe voulue : WP n'expose pas les paires terme/objet d'une
		// taxonomie, et un cache servirait des compteurs périmés après chaque
		// déplacement de média.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$pair_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->term_relationships} tr
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			 WHERE tt.taxonomy = %s",
				self::TAXONOMY
			)
		);

		if ( $pair_count > self::MAX_PAIRS ) {
			return $this->rollup_counts_additive( $direct, $parent_map );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- même motif que ci-dessus.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tt.term_id, tr.object_id FROM {$wpdb->term_relationships} tr
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			 WHERE tt.taxonomy = %s",
				self::TAXONOMY
			)
		);

		// term_id => [ object_id => true ]
		$sets = [];
		foreach ( $rows as $row ) {
			$sets[ (int) $row->term_id ][ (int) $row->object_id ] = true;
		}

		// Chaque média remonte vers tous les ancêtres de son dossier ; les clés
		// du tableau assurent l'unicité, donc pas de double comptage.
		$totals = [];
		foreach ( $direct as $term_id => $unused ) {
			$totals[ $term_id ] = $sets[ $term_id ] ?? [];
		}

		foreach ( $sets as $term_id => $objects ) {
			$ancestor = $parent_map[ $term_id ] ?? 0;
			$guard    = 0;
			while ( $ancestor > 0 && isset( $totals[ $ancestor ] ) && $guard++ < 100 ) {
				$totals[ $ancestor ] += $objects; // union : conserve les clés existantes
				$ancestor             = $parent_map[ $ancestor ] ?? 0;
			}
		}

		return array_map( 'count', $totals );
	}

	/**
	 * Repli sur de simples additions quand le volume de relations est trop gros.
	 *
	 * @param array<int, int> $direct
	 * @param array<int, int> $parent_map
	 * @return array<int, int>
	 */
	private function rollup_counts_additive( array $direct, array $parent_map ): array {
		$total = $direct;

		foreach ( $direct as $term_id => $count ) {
			if ( ! $count ) {
				continue;
			}
			$ancestor = $parent_map[ $term_id ] ?? 0;
			$guard    = 0;
			while ( $ancestor > 0 && isset( $total[ $ancestor ] ) && $guard++ < 100 ) {
				$total[ $ancestor ] += $count;
				$ancestor            = $parent_map[ $ancestor ] ?? 0;
			}
		}

		return $total;
	}

	/**
	 * Nombre réel de médias sans dossier. Une vraie requête plutôt qu'une
	 * soustraction : celle-ci devenait fausse dès qu'un compteur de terme était
	 * périmé ou qu'un média appartenait à plusieurs dossiers.
	 */
	private function count_unassigned(): int {
		$query = new \WP_Query(
			[
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- seul moyen de compter les médias sans dossier (voir docblock).
				'tax_query'              => [
					[
						'taxonomy' => self::TAXONOMY,
						'operator' => 'NOT EXISTS',
					],
				],
			]
		);

		return (int) $query->found_posts;
	}

	public function ajax_create_folder(): void {
		$this->guard_manage();

		$name      = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par guard().
		$parent_id = isset( $_POST['parent_id'] ) ? (int) $_POST['parent_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par guard().

		if ( ! $name ) {
			wp_send_json_error( [ 'message' => __( 'Nom requis.', 'lumia-tools' ) ] );
		}
		if ( $parent_id > 0 && ! term_exists( $parent_id, self::TAXONOMY ) ) {
			wp_send_json_error( [ 'message' => __( 'Dossier parent introuvable.', 'lumia-tools' ) ] );
		}

		$result = wp_insert_term( $name, self::TAXONOMY, [ 'parent' => max( 0, $parent_id ) ] );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		wp_send_json_success( array_merge( [ 'created' => (int) $result['term_id'] ], $this->get_folder_payload() ) );
	}

	public function ajax_rename_folder(): void {
		$this->guard_manage();

		$id   = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par guard().
		$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par guard().

		if ( ! $id || ! $name ) {
			wp_send_json_error( [ 'message' => __( 'Paramètres manquants.', 'lumia-tools' ) ] );
		}
		if ( ! term_exists( $id, self::TAXONOMY ) ) {
			wp_send_json_error( [ 'message' => __( 'Dossier introuvable.', 'lumia-tools' ) ] );
		}

		$result = wp_update_term( $id, self::TAXONOMY, [ 'name' => $name ] );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		wp_send_json_success( $this->get_folder_payload() );
	}

	/**
	 * Supprime un dossier et TOUS ses descendants ; les médias concernés
	 * retombent dans « non classés ».
	 *
	 * L'ancienne version ne descendait que d'un niveau : wp_delete_term()
	 * rattache les enfants au grand-parent, ce qui faisait remonter dans l'arbre
	 * des sous-dossiers censés disparaître.
	 */
	public function ajax_delete_folder(): void {
		$this->guard_manage();

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par guard().
		if ( ! $id || ! term_exists( $id, self::TAXONOMY ) ) {
			wp_send_json_error( [ 'message' => __( 'Dossier introuvable.', 'lumia-tools' ) ] );
		}

		$children  = get_term_children( $id, self::TAXONOMY );
		$to_delete = is_wp_error( $children )
			? [ $id ]
			: array_merge( array_map( 'intval', $children ), [ $id ] );

		// Détacher les médias avant suppression, pour ne pas dépendre de l'ordre.
		$attachments = get_posts(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- action ponctuelle de suppression de dossier.
				'tax_query'      => [
					[
						'taxonomy' => self::TAXONOMY,
						'field'    => 'term_id',
						'terms'    => $to_delete,
					],
				],
			]
		);

		foreach ( $attachments as $attachment_id ) {
			wp_remove_object_terms( $attachment_id, $to_delete, self::TAXONOMY );
		}

		// Enfants d'abord : wp_delete_term() reparenterait sinon les descendants.
		foreach ( $to_delete as $term_id ) {
			wp_delete_term( $term_id, self::TAXONOMY );
		}

		wp_send_json_success( $this->get_folder_payload() );
	}

	/**
	 * Range des médias dans un dossier.
	 *
	 * Un média peut appartenir à PLUSIEURS dossiers. Le mode décide du geste :
	 *  - replace : le média ne sera plus que dans $folder_id (0 = aucun dossier) ;
	 *  - add     : $folder_id s'ajoute aux dossiers existants ;
	 *  - remove  : $folder_id est retiré, les autres sont conservés.
	 */
	public function ajax_move_items(): void {
		$this->guard();

		$attachment_ids = array_filter( array_map( 'absint', (array) ( $_POST['ids'] ?? [] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par guard().
		$folder_id      = isset( $_POST['folder_id'] ) ? (int) $_POST['folder_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par guard().
		$mode           = sanitize_key( wp_unslash( $_POST['mode'] ?? 'replace' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par guard().

		if ( ! in_array( $mode, [ 'replace', 'add', 'remove' ], true ) ) {
			$mode = 'replace';
		}

		if ( ! $attachment_ids ) {
			wp_send_json_error( [ 'message' => __( 'Aucun média sélectionné.', 'lumia-tools' ) ] );
		}
		if ( $folder_id > 0 && ! term_exists( $folder_id, self::TAXONOMY ) ) {
			wp_send_json_error( [ 'message' => __( 'Dossier introuvable.', 'lumia-tools' ) ] );
		}
		// add et remove n'ont aucun sens sans dossier cible.
		if ( $folder_id <= 0 && 'replace' !== $mode ) {
			wp_send_json_error( [ 'message' => __( 'Dossier cible requis.', 'lumia-tools' ) ] );
		}

		$moved   = 0;
		$refuses = 0;

		foreach ( $attachment_ids as $att_id ) {
			if ( 'attachment' !== get_post_type( $att_id ) ) {
				continue;
			}

			// Contrôle PAR PIÈCE JOINTE : la garde d'entrée dit seulement que
			// l'appelant a le droit d'utiliser la médiathèque, pas qu'il a le
			// droit de toucher CE média-là. Sans ce test, un auteur reclassait
			// les médias de l'administrateur en envoyant leurs identifiants.
			//
			// `edit_post` sur une pièce jointe se résout en edit_posts /
			// edit_others_posts selon le propriétaire : c'est exactement la règle
			// que WordPress applique déjà à l'édition d'un média.
			if ( ! current_user_can( 'edit_post', $att_id ) ) {
				++$refuses;
				continue;
			}

			if ( 'add' === $mode ) {
				// Le 4e argument à true ajoute au lieu de remplacer.
				wp_set_object_terms( $att_id, [ $folder_id ], self::TAXONOMY, true );
			} elseif ( 'remove' === $mode ) {
				wp_remove_object_terms( $att_id, [ $folder_id ], self::TAXONOMY );
			} else {
				wp_set_object_terms( $att_id, $folder_id > 0 ? [ $folder_id ] : [], self::TAXONOMY );
			}

			++$moved;
		}

		// `refused` remonte à l'interface : un déplacement silencieusement partiel
		// se lirait comme un bug, alors que c'est le refus qui est correct.
		wp_send_json_success(
			array_merge(
				[
					'moved'   => $moved,
					'mode'    => $mode,
					'refused' => $refuses,
				],
				$this->get_folder_payload()
			)
		);
	}

	/**
	 * Re-parente un dossier (drag dossier → dossier).
	 */
	public function ajax_move_folder(): void {
		$this->guard_manage();

		$id        = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par guard().
		$parent_id = isset( $_POST['parent_id'] ) ? (int) $_POST['parent_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par guard().

		if ( ! $id || ! term_exists( $id, self::TAXONOMY ) ) {
			wp_send_json_error( [ 'message' => __( 'Dossier introuvable.', 'lumia-tools' ) ] );
		}
		if ( $id === $parent_id ) {
			wp_send_json_error( [ 'message' => __( 'Un dossier ne peut pas être son propre parent.', 'lumia-tools' ) ] );
		}

		// Garde anti-cycle : déplacer un dossier dans l'un de ses descendants
		// détacherait toute la branche de l'arbre.
		if ( $parent_id > 0 ) {
			if ( ! term_exists( $parent_id, self::TAXONOMY ) ) {
				wp_send_json_error( [ 'message' => __( 'Dossier parent introuvable.', 'lumia-tools' ) ] );
			}
			$descendants = get_term_children( $id, self::TAXONOMY );
			if ( ! is_wp_error( $descendants ) && in_array( $parent_id, array_map( 'intval', $descendants ), true ) ) {
				wp_send_json_error( [ 'message' => __( 'Impossible de déplacer un dossier dans un de ses sous-dossiers.', 'lumia-tools' ) ] );
			}
		}

		$result = wp_update_term( $id, self::TAXONOMY, [ 'parent' => max( 0, $parent_id ) ] );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		wp_send_json_success( $this->get_folder_payload() );
	}

	public function ajax_set_folder_color(): void {
		$this->guard_manage();

		$id    = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par guard().
		$color = sanitize_text_field( wp_unslash( $_POST['color'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par guard().

		if ( ! $id || ! term_exists( $id, self::TAXONOMY ) ) {
			wp_send_json_error( [ 'message' => __( 'Dossier introuvable.', 'lumia-tools' ) ] );
		}
		if ( '' !== $color && ! in_array( $color, self::FOLDER_COLORS, true ) ) {
			wp_send_json_error( [ 'message' => __( 'Couleur non valide.', 'lumia-tools' ) ] );
		}

		if ( '' === $color ) {
			delete_term_meta( $id, self::COLOR_META );
		} else {
			update_term_meta( $id, self::COLOR_META, $color );
		}

		wp_send_json_success(
			[
				'id'    => $id,
				'color' => $color,
			]
		);
	}

	/* ================================================================
	 * SETTINGS
	 * ================================================================ */

	/**
	 * @return array<string, mixed>
	 */
	public function get_settings(): array {
		return [];
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	public function save_settings( array $settings ): bool {
		return false;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function get_defaults(): array {
		return [];
	}

	public static function get_uninstall_keys(): array {
		return [
			'options'  => [],
			'meta'     => [],
			'taxonomy' => [ self::TAXONOMY ], // suppression de tous les termes
		];
	}
}
