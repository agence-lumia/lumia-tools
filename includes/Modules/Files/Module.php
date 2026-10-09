<?php
namespace Lumia\Tools\Modules\Files;

defined( 'ABSPATH' ) || exit;

use Lumia\Tools\Core\AbstractModule;
use Lumia\Tools\Admin\Admin;

/**
 * Module Fichiers — gestionnaire de fichiers WordPress.
 */
class Module extends AbstractModule {

	private FileManager $fm;

	/* ================================================================
	 * INIT
	 * ================================================================ */

	public function init(): void {
		$this->fm = new FileManager( ABSPATH );

		add_action( 'wp_ajax_lumia_files_list', [ $this, 'ajax_list' ] );
		add_action( 'wp_ajax_lumia_files_delete', [ $this, 'ajax_delete' ] );
		add_action( 'wp_ajax_lumia_files_rename', [ $this, 'ajax_rename' ] );
		add_action( 'wp_ajax_lumia_files_move', [ $this, 'ajax_move' ] );
		add_action( 'wp_ajax_lumia_files_mkdir', [ $this, 'ajax_mkdir' ] );
		add_action( 'wp_ajax_lumia_files_zip', [ $this, 'ajax_zip' ] );
		add_action( 'wp_ajax_lumia_files_extract', [ $this, 'ajax_extract' ] );
		add_action( 'wp_ajax_lumia_files_get_content', [ $this, 'ajax_get_content' ] );
		add_action( 'wp_ajax_lumia_files_save_content', [ $this, 'ajax_save_content' ] );
		add_action( 'wp_ajax_lumia_files_upload', [ $this, 'ajax_upload' ] );
		add_action( 'admin_post_lumia_files_download', [ $this, 'handle_download' ] );

		// L'éditeur de code s'appuie sur CodeMirror, livré avec WordPress. Le
		// core ne le charge pas de lui-même : il faut appeler wp_enqueue_code_editor()
		// pendant admin_enqueue_scripts, ce que get_admin_js() ne permet pas
		// d'exprimer (il ne retourne que des URL).
		//
		// Priorité 5 : Admin::enqueue_assets() lit get_admin_js_data() à la
		// priorité 10, et les réglages CodeMirror doivent y être présents.
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_code_editor' ], 5 );
	}

	/* ================================================================
	 * ÉDITEUR DE CODE
	 * ================================================================ */

	/**
	 * Extensions ouvrables dans l'éditeur, et donc coloriables.
	 *
	 * Doit rester alignée sur isEditable() dans files.js : c'est cette liste
	 * qui décide des modes CodeMirror préparés côté serveur.
	 */
	const EDITABLE_EXTENSIONS = [
		'php',
		'js',
		'ts',
		'css',
		'html',
		'htm',
		'xml',
		'svg',
		'json',
		'txt',
		'md',
		'sh',
		'bash',
		'sql',
		'htaccess',
		'env',
		'yml',
		'yaml',
		'ini',
		'conf',
		'config',
		'lock',
		'log',
		'htpasswd',
	];

	/**
	 * Réglages CodeMirror par extension, remplis par enqueue_code_editor().
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $code_editor_settings = [];

	/** Vrai uniquement sur l'onglet Fichiers du plugin. */
	private function is_files_screen(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : '';

		return 'lumia-tools' === $page && 'module_files' === $tab;
	}

	/**
	 * Charge CodeMirror (coloration syntaxique, autocomplétion, linting).
	 *
	 * Tout vient de WordPress : wp-codemirror embarque les modes et l'add-on
	 * show-hint, et wp-admin/js/code-editor.js déclenche déjà l'autocomplétion
	 * à la frappe pour HTML, CSS, JS et PHP. Rien à embarquer de notre côté.
	 *
	 * L'appel se fait une fois par extension : chaque type amène ses propres
	 * linters (csslint, jshint, htmlhint, jsonlint) et wp_enqueue_script est
	 * idempotent. On récupère au passage les réglages à passer au JS.
	 */
	public function enqueue_code_editor(): void {
		if ( ! $this->is_files_screen() || ! current_user_can( static::get_required_capability() ) ) {
			return;
		}

		$this->code_editor_settings = [];

		foreach ( self::EDITABLE_EXTENSIONS as $ext ) {
			$settings = wp_enqueue_code_editor( [ 'file' => 'lumia.' . $ext ] );

			// false = l'utilisateur a désactivé la coloration syntaxique dans son
			// profil. On respecte ce choix : l'éditeur restera en texte brut.
			if ( false === $settings ) {
				$this->code_editor_settings = [];
				return;
			}

			$this->code_editor_settings[ $ext ] = $settings;
		}
	}

	/* ================================================================
	 * SÉCURITÉ
	 * ================================================================ */

	/**
	 * Sous multisite, `manage_options` est une capacité par site : ce module
	 * donne accès aux fichiers du RÉSEAU. Voir AbstractModule.
	 */
	public static function get_required_capability(): string {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}

	private function check_nonce(): void {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'lumia_admin_nonce' ) || ! current_user_can( static::get_required_capability() ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission refusée.', 'lumia-tools' ) ], 403 );
		}
	}

	/**
	 * Refuse toute écriture quand l'administrateur a posé DISALLOW_FILE_MODS ou
	 * DISALLOW_FILE_EDIT dans wp-config.php.
	 *
	 * Ces deux constantes ne sont pas un réglage cosmétique : elles disent
	 * « personne ne modifie de fichier depuis le navigateur sur ce site ».
	 * Un gestionnaire de fichiers qui les ignore vide de son sens le geste de
	 * l'administrateur qui les a posées — et il est plus permissif que
	 * l'éditeur du cœur qu'elles désactivent.
	 *
	 * Les deux ne portent pas sur la même chose :
	 *  - DISALLOW_FILE_EDIT désigne l'ÉDITION de code depuis l'admin. On refuse
	 *    donc l'enregistrement d'un contenu et le téléversement ;
	 *  - DISALLOW_FILE_MODS est plus large (aucune modification de fichier,
	 *    installation comprise) : on refuse alors toute mutation, y compris
	 *    renommer, déplacer, supprimer, créer un dossier, zipper, extraire.
	 *
	 * La lecture (listing, aperçu, téléchargement) reste ouverte dans les deux
	 * cas : aucune des deux constantes ne parle de lecture.
	 *
	 * DISALLOW_FILE_MODS se lit via wp_is_file_mod_allowed(), qui applique le
	 * filtre `file_mod_allowed` : un hébergeur qui verrouille les fichiers par
	 * ce filtre plutôt que par la constante est ainsi respecté lui aussi.
	 *
	 * @param bool $edition true si l'appel écrit un CONTENU (édition/upload).
	 */
	private function check_file_mods( bool $edition = false ): void {
		$bloque = ! wp_is_file_mod_allowed( 'lumia_files' )
			|| ( $edition && defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT );

		if ( $bloque ) {
			wp_send_json_error(
				[
					'message' => __( 'La modification de fichiers est désactivée sur ce site (DISALLOW_FILE_EDIT, DISALLOW_FILE_MODS ou filtre file_mod_allowed).', 'lumia-tools' ),
				],
				403
			);
		}
	}

	private function get_post_path( string $key = 'path' ): string {
		$raw = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par check_nonce().
		return rawurldecode( $raw );
	}

	/* ================================================================
	 * AJAX — LISTING
	 * ================================================================ */

	public function ajax_list(): void {
		$this->check_nonce();
		$path = $this->get_post_path();

		try {
			$items     = $this->fm->list_directory( $path );
			$date_fmt  = get_option( 'date_format' ) . ' H:i';
			$formatted = array_map(
				function ( $item ) use ( $date_fmt ) {
					$item['size_fmt']     = ( null !== $item['size'] ) ? FileManager::format_size( (int) $item['size'] ) : '';
					$item['modified_fmt'] = $item['modified'] ? date_i18n( $date_fmt, $item['modified'] ) : '';
					return $item;
				},
				$items
			);

			wp_send_json_success(
				[
					'items' => $formatted,
					'path'  => $path,
				]
			);
		} catch ( \Exception $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	/* ================================================================
	 * AJAX — OPÉRATIONS
	 * ================================================================ */

	public function ajax_delete(): void {
		$this->check_nonce();
		$this->check_file_mods( false );
		$paths  = isset( $_POST['paths'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['paths'] ) ) : []; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par check_nonce().
		$errors = [];

		foreach ( $paths as $path ) {
			try {
				$this->fm->delete( $path );
			} catch ( \Exception $e ) {
				$errors[] = $e->getMessage();
			}
		}

		if ( $errors && count( $errors ) === count( $paths ) ) {
			wp_send_json_error( [ 'message' => implode( ', ', $errors ) ] );
		}

		wp_send_json_success( [ 'message' => __( 'Supprimé avec succès.', 'lumia-tools' ) ] );
	}

	public function ajax_rename(): void {
		$this->check_nonce();
		$this->check_file_mods( false );
		$path     = $this->get_post_path();
		$new_name = isset( $_POST['new_name'] ) ? sanitize_file_name( wp_unslash( $_POST['new_name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par check_nonce().

		try {
			$this->fm->rename( $path, $new_name );
			wp_send_json_success( [ 'message' => __( 'Renommé avec succès.', 'lumia-tools' ) ] );
		} catch ( \Exception $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	public function ajax_move(): void {
		$this->check_nonce();
		$this->check_file_mods( false );
		$src = $this->get_post_path( 'src' );
		$dst = $this->get_post_path( 'dst' );

		try {
			$this->fm->move( $src, $dst );
			wp_send_json_success( [ 'message' => __( 'Déplacé avec succès.', 'lumia-tools' ) ] );
		} catch ( \Exception $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	public function ajax_mkdir(): void {
		$this->check_nonce();
		$this->check_file_mods( false );
		$parent = $this->get_post_path( 'parent' );
		$name   = isset( $_POST['name'] ) ? sanitize_file_name( wp_unslash( $_POST['name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par check_nonce() juste au-dessus.
		$rel    = ( '' !== $parent ) ? rtrim( $parent, '/' ) . '/' . $name : $name;

		try {
			$this->fm->create_folder( $rel );
			wp_send_json_success( [ 'message' => __( 'Dossier créé.', 'lumia-tools' ) ] );
		} catch ( \Exception $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	public function ajax_zip(): void {
		$this->check_nonce();
		$this->check_file_mods( false );
		$paths  = isset( $_POST['paths'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['paths'] ) ) : []; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par check_nonce().
		$name   = isset( $_POST['name'] ) ? sanitize_file_name( wp_unslash( $_POST['name'] ) ) : 'archive.zip'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par check_nonce().
		$parent = $this->get_post_path( 'parent' );
		$dest   = ( '' !== $parent ) ? rtrim( $parent, '/' ) . '/' . $name : $name;

		try {
			$abs          = $this->fm->create_zip( $paths, $dest );
			$rel          = $this->fm->to_relative( $abs );
			$download_url = $this->build_download_url( $rel );
			wp_send_json_success(
				[
					'message'      => __( 'Archive créée.', 'lumia-tools' ),
					'path'         => $rel,
					'download_url' => $download_url,
				]
			);
		} catch ( \Exception $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	public function ajax_extract(): void {
		$this->check_nonce();
		$this->check_file_mods( false );
		$path = $this->get_post_path();

		try {
			$this->fm->extract_zip( $path );
			wp_send_json_success( [ 'message' => __( 'Archive extraite.', 'lumia-tools' ) ] );
		} catch ( \Exception $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	public function ajax_get_content(): void {
		$this->check_nonce();
		$path = $this->get_post_path();

		try {
			$content = $this->fm->get_content( $path );
			wp_send_json_success(
				[
					'content' => $content,
					'path'    => $path,
				]
			);
		} catch ( \Exception $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	public function ajax_save_content(): void {
		$this->check_nonce();
		$this->check_file_mods( true );
		$path    = $this->get_post_path();
		$content = isset( $_POST['content'] ) ? wp_unslash( $_POST['content'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce vérifié par check_nonce() ; contenu de fichier source enregistré tel quel, écriture réservée à get_required_capability() et bloquée par DISALLOW_FILE_EDIT.

		try {
			$this->fm->save_content( $path, $content );
			wp_send_json_success( [ 'message' => __( 'Fichier enregistré.', 'lumia-tools' ) ] );
		} catch ( \Exception $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	public function ajax_upload(): void {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'lumia_admin_nonce' ) || ! current_user_can( static::get_required_capability() ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission refusée.', 'lumia-tools' ) ], 403 );
		}
		$this->check_file_mods( true );

		$dir   = isset( $_POST['path'] ) ? sanitize_text_field( wp_unslash( $_POST['path'] ) ) : '';
		$files = $_FILES['files'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( ! $files ) {
			wp_send_json_error( [ 'message' => __( 'Aucun fichier reçu.', 'lumia-tools' ) ] );
		}

		$uploaded = [];
		$errors   = [];
		$count    = is_array( $files['name'] ) ? count( $files['name'] ) : 1;

		for ( $i = 0; $i < $count; $i++ ) {
			$file = is_array( $files['name'] )
				? [
					'name'     => $files['name'][ $i ],
					'tmp_name' => $files['tmp_name'][ $i ],
					'error'    => $files['error'][ $i ],
					'size'     => $files['size'][ $i ],
				]
				: $files;

			if ( UPLOAD_ERR_OK !== $file['error'] ) {
				$errors[] = sanitize_text_field( $file['name'] );
				continue;
			}

			try {
				$uploaded[] = $this->fm->upload( $dir, $file );
			} catch ( \Exception $e ) {
				$errors[] = sanitize_text_field( $file['name'] ) . ': ' . $e->getMessage();
			}
		}

		if ( $errors && ! $uploaded ) {
			wp_send_json_error( [ 'message' => implode( ', ', $errors ) ] );
		}

		wp_send_json_success(
			[
				'message' => sprintf(
					/* translators: %d: number of files */
					_n( '%d fichier uploadé.', '%d fichiers uploadés.', count( $uploaded ), 'lumia-tools' ),
					count( $uploaded )
				),
				'paths'   => $uploaded,
			]
		);
	}

	/* ================================================================
	 * TÉLÉCHARGEMENT
	 * ================================================================ */

	public function handle_download(): void {
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'lumia_files_download' ) || ! current_user_can( static::get_required_capability() ) ) {
			wp_die( esc_html__( 'Permission refusée.', 'lumia-tools' ) );
		}

		$path = isset( $_GET['path'] ) ? sanitize_text_field( wp_unslash( $_GET['path'] ) ) : '';

		try {
			$abs = $this->fm->resolve( $path );
		} catch ( \Exception $e ) {
			wp_die( esc_html__( 'Fichier introuvable.', 'lumia-tools' ) );
		}

		if ( is_dir( $abs ) ) {
			$this->stream_dir_as_zip( $abs );
			return;
		}

		if ( ! is_file( $abs ) ) {
			wp_die( esc_html__( 'Fichier introuvable.', 'lumia-tools' ) );
		}

		$mime = (string) mime_content_type( $abs );
		if ( '' === $mime ) {
			$mime = 'application/octet-stream';
		}
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: ' . Admin::content_disposition( basename( $abs ) ) );
		header( 'Content-Length: ' . filesize( $abs ) );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- envoi en flux d'un fichier local, sans le charger en mémoire.
		readfile( $abs );
		exit;
	}

	/**
	 * Zippe un dossier dans un fichier temp, le streame, puis le supprime.
	 */
	private function stream_dir_as_zip( string $abs ): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die( esc_html__( 'ZipArchive non disponible sur ce serveur.', 'lumia-tools' ) );
		}

		// Fichier temporaire hors racine WP
		$tmp = tempnam( sys_get_temp_dir(), 'lumia_zip_' );
		if ( false === $tmp ) {
			wp_die( esc_html__( 'Impossible de créer le fichier temporaire.', 'lumia-tools' ) );
		}

		// tempnam crée un fichier vide — ZipArchive::CREATE l'écrase
		$zip = new \ZipArchive();
		if ( $zip->open( $tmp, \ZipArchive::OVERWRITE ) !== true ) {
			wp_delete_file( $tmp );
			wp_die( esc_html__( 'Impossible de créer l\'archive.', 'lumia-tools' ) );
		}

		$this->add_dir_to_zip( $zip, $abs, basename( $abs ) );
		$zip->close();

		$filename = basename( $abs ) . '.zip';
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: ' . Admin::content_disposition( $filename ) );
		header( 'Content-Length: ' . filesize( $tmp ) );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- envoi en flux d'un fichier local, sans le charger en mémoire.
		readfile( $tmp );
		wp_delete_file( $tmp );
		exit;
	}

	private function add_dir_to_zip( \ZipArchive $zip, string $dir, string $base ): void {
		$zip->addEmptyDir( $base );
		foreach ( array_diff( (array) scandir( $dir ), [ '.', '..' ] ) as $item ) {
			$abs = $dir . DIRECTORY_SEPARATOR . $item;
			if ( is_dir( $abs ) ) {
				$this->add_dir_to_zip( $zip, $abs, $base . '/' . $item );
			} else {
				$zip->addFile( $abs, $base . '/' . $item );
			}
		}
	}

	/* ================================================================
	 * ASSETS
	 * ================================================================ */

	public function get_admin_css(): array {
		return [ LUMIA_ASSETS_URL . 'admin/css/modules/files.css' ];
	}

	public function get_admin_js(): array {
		return [ LUMIA_ASSETS_URL . 'admin/js/modules/files.js' ];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_admin_js_data(): array {
		return [
			'i18n'       => [
				'confirmDelete' => __( 'Supprimer ce(s) élément(s) ? Cette action est irréversible.', 'lumia-tools' ),
				'emptyFolder'   => __( 'Ce dossier est vide.', 'lumia-tools' ),
				'loading'       => __( 'Chargement...', 'lumia-tools' ),
				'uploading'     => __( 'Upload en cours...', 'lumia-tools' ),
				'newFolderName' => __( 'Nom du nouveau dossier :', 'lumia-tools' ),
				'downloadUrl'   => admin_url( 'admin-post.php?action=lumia_files_download' ),
				'downloadNonce' => wp_create_nonce( 'lumia_files_download' ),
			],
			// Vide si la coloration syntaxique est désactivée dans le profil de
			// l'utilisateur : files.js retombe alors sur le textarea nu.
			'codeEditor' => $this->code_editor_settings,
		];
	}

	/* ================================================================
	 * MODULE INTERFACE
	 * ================================================================ */

	/**
	 * @return array<string, mixed>
	 */
	public function get_settings(): array {
		return $this->get_module_settings( self::get_defaults() );
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	public function save_settings( array $settings ): bool {
		return true; // Aucun réglage : rien à écrire.
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function get_defaults(): array {
		return [];
	}

	public static function get_uninstall_keys(): array {
		return [
			'options' => [ 'lumia_module_files' ],
			'meta'    => [],
		];
	}

	/* ================================================================
	 * HELPER
	 * ================================================================ */

	private function build_download_url( string $rel ): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=lumia_files_download&path=' . rawurlencode( $rel ) ),
			'lumia_files_download'
		);
	}
}
