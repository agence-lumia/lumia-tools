<?php
namespace Lumia\Tools\Modules\Files;

defined( 'ABSPATH' ) || exit;

use Lumia\Tools\Core\AbstractModule;
use Lumia\Tools\Admin\Admin;

/**
 * Files module — WordPress file manager.
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

		// The code editor relies on CodeMirror, shipped with WordPress. The core
		// does not load it by itself: wp_enqueue_code_editor() must be called
		// during admin_enqueue_scripts, which get_admin_js() cannot express
		// (it only returns URLs).
		//
		// Priority 5: Admin::enqueue_assets() reads get_admin_js_data() at
		// priority 10, and the CodeMirror settings must be there by then.
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_code_editor' ], 5 );
	}

	/* ================================================================
	 * CODE EDITOR
	 * ================================================================ */

	/**
	 * Extensions that can be opened in the editor, and therefore highlighted.
	 *
	 * Must stay aligned with isEditable() in files.js: this list decides which
	 * CodeMirror modes are prepared on the server side.
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
	 * CodeMirror settings per extension, filled by enqueue_code_editor().
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $code_editor_settings = [];

	/** True only on the plugin's Files tab. */
	private function is_files_screen(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : '';

		return 'lumia-tools' === $page && 'module_files' === $tab;
	}

	/**
	 * Loads CodeMirror (syntax highlighting, autocompletion, linting).
	 *
	 * Everything comes from WordPress: wp-codemirror bundles the modes and the
	 * show-hint add-on, and wp-admin/js/code-editor.js already triggers
	 * as-you-type autocompletion for HTML, CSS, JS and PHP. Nothing to bundle
	 * on our side.
	 *
	 * The call is made once per extension: each type brings its own linters
	 * (csslint, jshint, htmlhint, jsonlint) and wp_enqueue_script is
	 * idempotent. The settings to hand to the JS are collected along the way.
	 */
	public function enqueue_code_editor(): void {
		if ( ! $this->is_files_screen() || ! current_user_can( static::get_required_capability() ) ) {
			return;
		}

		$this->code_editor_settings = [];

		foreach ( self::EDITABLE_EXTENSIONS as $ext ) {
			$settings = wp_enqueue_code_editor( [ 'file' => 'lumia.' . $ext ] );

			// false = the user turned syntax highlighting off in their profile.
			// We respect that choice: the editor stays plain text.
			if ( false === $settings ) {
				$this->code_editor_settings = [];
				return;
			}

			$this->code_editor_settings[ $ext ] = $settings;
		}
	}

	/* ================================================================
	 * SECURITY
	 * ================================================================ */

	/**
	 * On multisite, `manage_options` is a per-site capability: this module
	 * gives access to the NETWORK's files. See AbstractModule.
	 */
	public static function get_required_capability(): string {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}

	private function check_nonce(): void {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'lumia_admin_nonce' ) || ! current_user_can( static::get_required_capability() ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'lumia-tools' ) ], 403 );
		}
	}

	/**
	 * Refuses any write when the administrator has set DISALLOW_FILE_MODS or
	 * DISALLOW_FILE_EDIT in wp-config.php.
	 *
	 * These two constants are not a cosmetic setting: they say "nobody modifies
	 * a file from the browser on this site". A file manager that ignores them
	 * empties the gesture of the administrator who set them of its meaning —
	 * and it is more permissive than the core editor they disable.
	 *
	 * The two do not cover the same thing:
	 *  - DISALLOW_FILE_EDIT means code EDITING from the admin. We therefore
	 *    refuse saving a content and uploading;
	 *  - DISALLOW_FILE_MODS is broader (no file modification at all,
	 *    installation included): we then refuse every mutation, including
	 *    rename, move, delete, create a folder, zip, extract.
	 *
	 * Reading (listing, preview, download) stays open in both cases: neither
	 * constant says anything about reading.
	 *
	 * DISALLOW_FILE_MODS is read through wp_is_file_mod_allowed(), which applies
	 * the `file_mod_allowed` filter: a host that locks files through that
	 * filter rather than through the constant is respected as well.
	 *
	 * @param bool $editing True if the call writes a CONTENT (edit/upload).
	 */
	private function check_file_mods( bool $editing = false ): void {
		$blocked = ! wp_is_file_mod_allowed( 'lumia_files' )
			|| ( $editing && defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT );

		if ( $blocked ) {
			wp_send_json_error(
				[
					'message' => __( 'File modification is disabled on this site (DISALLOW_FILE_EDIT, DISALLOW_FILE_MODS or the file_mod_allowed filter).', 'lumia-tools' ),
				],
				403
			);
		}
	}

	private function get_post_path( string $key = 'path' ): string {
		$raw = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by check_nonce().
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
	 * AJAX — OPERATIONS
	 * ================================================================ */

	public function ajax_delete(): void {
		$this->check_nonce();
		$this->check_file_mods( false );
		$paths  = isset( $_POST['paths'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['paths'] ) ) : []; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by check_nonce().
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

		wp_send_json_success( [ 'message' => __( 'Deleted successfully.', 'lumia-tools' ) ] );
	}

	public function ajax_rename(): void {
		$this->check_nonce();
		$this->check_file_mods( false );
		$path     = $this->get_post_path();
		$new_name = isset( $_POST['new_name'] ) ? sanitize_file_name( wp_unslash( $_POST['new_name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by check_nonce().

		try {
			$this->fm->rename( $path, $new_name );
			wp_send_json_success( [ 'message' => __( 'Renamed successfully.', 'lumia-tools' ) ] );
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
			wp_send_json_success( [ 'message' => __( 'Moved successfully.', 'lumia-tools' ) ] );
		} catch ( \Exception $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	public function ajax_mkdir(): void {
		$this->check_nonce();
		$this->check_file_mods( false );
		$parent = $this->get_post_path( 'parent' );
		$name   = isset( $_POST['name'] ) ? sanitize_file_name( wp_unslash( $_POST['name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by check_nonce() just above.
		$rel    = ( '' !== $parent ) ? rtrim( $parent, '/' ) . '/' . $name : $name;

		try {
			$this->fm->create_folder( $rel );
			wp_send_json_success( [ 'message' => __( 'Folder created.', 'lumia-tools' ) ] );
		} catch ( \Exception $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	public function ajax_zip(): void {
		$this->check_nonce();
		$this->check_file_mods( false );
		$paths  = isset( $_POST['paths'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['paths'] ) ) : []; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by check_nonce().
		$name   = isset( $_POST['name'] ) ? sanitize_file_name( wp_unslash( $_POST['name'] ) ) : 'archive.zip'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by check_nonce().
		$parent = $this->get_post_path( 'parent' );
		$dest   = ( '' !== $parent ) ? rtrim( $parent, '/' ) . '/' . $name : $name;

		try {
			$abs          = $this->fm->create_zip( $paths, $dest );
			$rel          = $this->fm->to_relative( $abs );
			$download_url = $this->build_download_url( $rel );
			wp_send_json_success(
				[
					'message'      => __( 'Archive created.', 'lumia-tools' ),
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
			wp_send_json_success( [ 'message' => __( 'Archive extracted.', 'lumia-tools' ) ] );
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
		$content = isset( $_POST['content'] ) ? wp_unslash( $_POST['content'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified by check_nonce(); source file content saved as is, writing restricted to get_required_capability() and blocked by DISALLOW_FILE_EDIT.

		try {
			$this->fm->save_content( $path, $content );
			wp_send_json_success( [ 'message' => __( 'File saved.', 'lumia-tools' ) ] );
		} catch ( \Exception $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	public function ajax_upload(): void {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'lumia_admin_nonce' ) || ! current_user_can( static::get_required_capability() ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'lumia-tools' ) ], 403 );
		}
		$this->check_file_mods( true );

		$dir   = isset( $_POST['path'] ) ? sanitize_text_field( wp_unslash( $_POST['path'] ) ) : '';
		$files = $_FILES['files'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( ! $files ) {
			wp_send_json_error( [ 'message' => __( 'No file received.', 'lumia-tools' ) ] );
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

			try {
				if ( UPLOAD_ERR_INI_SIZE === $file['error'] || UPLOAD_ERR_FORM_SIZE === $file['error'] ) {
					throw new \RuntimeException(
						sprintf(
							/* translators: %s: maximum upload size, e.g. "64 MB". */
							__( 'The file exceeds the server\'s maximum upload size (%s).', 'lumia-tools' ),
							// PHP's own limit, the one UPLOAD_ERR_INI_SIZE reports: wp_max_upload_size() can be lower (multisite quota).
							(string) size_format( wp_convert_hr_to_bytes( (string) ini_get( 'upload_max_filesize' ) ) )
						)
					);
				}
				if ( UPLOAD_ERR_OK !== $file['error'] ) {
					throw new \RuntimeException( __( 'Upload failed.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught just below, returned as JSON, escaped on display by the toast.
				}
				$uploaded[] = $this->fm->upload( $dir, $file );
			} catch ( \Exception $e ) {
				/* translators: 1: label, 2: value. */
				$errors[] = sprintf( __( '%1$s: %2$s', 'lumia-tools' ), sanitize_text_field( $file['name'] ), $e->getMessage() );
			}
		}

		if ( $errors && ! $uploaded ) {
			wp_send_json_error( [ 'message' => implode( ', ', $errors ) ] );
		}

		wp_send_json_success(
			[
				'message' => sprintf(
					/* translators: %d: number of files */
					_n( '%d file uploaded.', '%d files uploaded.', count( $uploaded ), 'lumia-tools' ),
					count( $uploaded )
				),
				'paths'   => $uploaded,
			]
		);
	}

	/* ================================================================
	 * DOWNLOAD
	 * ================================================================ */

	public function handle_download(): void {
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'lumia_files_download' ) || ! current_user_can( static::get_required_capability() ) ) {
			wp_die( esc_html__( 'Permission denied.', 'lumia-tools' ) );
		}

		$path = isset( $_GET['path'] ) ? sanitize_text_field( wp_unslash( $_GET['path'] ) ) : '';

		try {
			$abs = $this->fm->resolve( $path );
		} catch ( \Exception $e ) {
			wp_die( esc_html__( 'File not found.', 'lumia-tools' ) );
		}

		if ( is_dir( $abs ) ) {
			$this->stream_dir_as_zip( $abs );
			return;
		}

		if ( ! is_file( $abs ) ) {
			wp_die( esc_html__( 'File not found.', 'lumia-tools' ) );
		}

		$mime = (string) mime_content_type( $abs );
		if ( '' === $mime ) {
			$mime = 'application/octet-stream';
		}
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: ' . Admin::content_disposition( basename( $abs ) ) );
		header( 'Content-Length: ' . filesize( $abs ) );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streams a local file without loading it into memory.
		readfile( $abs );
		exit;
	}

	/**
	 * Zips a folder into a temp file, streams it, then deletes it.
	 */
	private function stream_dir_as_zip( string $abs ): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die( esc_html__( 'ZipArchive is not available on this server.', 'lumia-tools' ) );
		}

		// Temporary file outside the WP root
		$tmp = tempnam( sys_get_temp_dir(), 'lumia_zip_' );
		if ( false === $tmp ) {
			wp_die( esc_html__( 'Could not create the temporary file.', 'lumia-tools' ) );
		}

		// tempnam creates an empty file — ZipArchive::OVERWRITE overwrites it
		$zip = new \ZipArchive();
		if ( $zip->open( $tmp, \ZipArchive::OVERWRITE ) !== true ) {
			wp_delete_file( $tmp );
			wp_die( esc_html__( 'Could not create the archive.', 'lumia-tools' ) );
		}

		$this->add_dir_to_zip( $zip, $abs, basename( $abs ) );
		$zip->close();

		$filename = basename( $abs ) . '.zip';
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: ' . Admin::content_disposition( $filename ) );
		header( 'Content-Length: ' . filesize( $tmp ) );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streams a local file without loading it into memory.
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
				'confirmDelete'          => __( 'Delete this item(s)? This action cannot be undone.', 'lumia-tools' ),
				'emptyFolder'            => __( 'This folder is empty.', 'lumia-tools' ),
				'loading'                => __( 'Loading...', 'lumia-tools' ),
				'uploading'              => __( 'Uploading...', 'lumia-tools' ),
				'newFolderName'          => __( 'New folder name:', 'lumia-tools' ),
				'networkError'           => __( 'Network error.', 'lumia-tools' ),
				'uploadError'            => __( 'Upload error.', 'lumia-tools' ),
				'actionEdit'             => __( 'Edit', 'lumia-tools' ),
				'actionDownload'         => __( 'Download', 'lumia-tools' ),
				'actionCopyLink'         => __( 'Copy link', 'lumia-tools' ),
				'actionRename'           => __( 'Rename', 'lumia-tools' ),
				'actionMove'             => __( 'Move', 'lumia-tools' ),
				'actionExtract'          => __( 'Extract', 'lumia-tools' ),
				'actionDelete'           => __( 'Delete', 'lumia-tools' ),
				'deleted'                => __( 'Deleted successfully.', 'lumia-tools' ),
				'archiveExtracted'       => __( 'Archive extracted.', 'lumia-tools' ),
				'archiveCreatedDownload' => __( 'Archive created. Downloading...', 'lumia-tools' ),
				'folderCreated'          => __( 'Folder created.', 'lumia-tools' ),
				'renamed'                => __( 'Renamed successfully.', 'lumia-tools' ),
				'moved'                  => __( 'Moved successfully.', 'lumia-tools' ),
				'fileSaved'              => __( 'File saved.', 'lumia-tools' ),
				'linkCopied'             => __( 'Link copied.', 'lumia-tools' ),
				'copyFailed'             => __( 'Could not copy.', 'lumia-tools' ),
				'defaultFolderName'      => __( 'folder', 'lumia-tools' ),
				/* translators: %s: folder name */
				'compressing'            => __( 'Compressing "%s"...', 'lumia-tools' ),
				/* translators: %s: name of the downloaded zip file */
				'zipDownloaded'          => __( '"%s" downloaded.', 'lumia-tools' ),
				/* translators: %s: folder name */
				'compressError'          => __( 'Error while compressing "%s".', 'lumia-tools' ),
				/* translators: %d: number of selected items */
				'selectedCount'          => __( '%d selected', 'lumia-tools' ),
				'editorUnsavedTitle'     => _x( 'Unsaved changes', 'file editor', 'lumia-tools' ),
				'editorUnsavedMessage'   => __( 'Do you want to leave without saving your changes?', 'lumia-tools' ),
				'editorUnsavedLeave'     => _x( 'Leave without saving', 'file editor', 'lumia-tools' ),
				'editorUnsavedStay'      => __( 'Stay', 'lumia-tools' ),
				'downloadUrl'            => admin_url( 'admin-post.php?action=lumia_files_download' ),
				'downloadNonce'          => wp_create_nonce( 'lumia_files_download' ),
			],
			// Empty if syntax highlighting is turned off in the user's profile:
			// files.js then falls back to the bare textarea.
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
		return true; // No setting: nothing to write.
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
