<?php
namespace Lumia\Tools\Modules\Media;

defined( 'ABSPATH' ) || exit;

use Lumia\Tools\Core\AbstractModule;

/**
 * Media module — organizes the media library into virtual folders.
 *
 * Folders are terms of a taxonomy (lumia_media_folder) attached to the
 * "attachment" post type. Files are never moved on disk: only the taxonomy
 * association changes.
 *
 * Filtering happens SERVER-SIDE. The taxonomy is registered with a query_var,
 * which is enough for WordPress to let it through: in wp_ajax_query_attachments(),
 * the core explicitly whitelists the query_var of every attachment taxonomy
 * (wp-admin/includes/ajax-actions.php). The value is then intercepted and
 * translated into a tax_query — WP_Query would otherwise resolve the query_var
 * by slug, while we deal with term_ids.
 *
 * Consequence: the view never carries a list of IDs. It only sends
 * lumia_folder=<id>, and WordPress pagination / infinite scroll keep working
 * natively whatever the size of the media library.
 */
class Module extends AbstractModule {

	const TAXONOMY = 'lumia_media_folder';

	/** Query var carrying the current folder down to WP_Query. */
	const QUERY_VAR = 'lumia_folder';

	/** Sentinel value: media that belong to no folder. */
	const UNASSIGNED = '__none__';

	/** Term meta key storing a folder's color. */
	const COLOR_META = 'lumia_folder_color';

	/**
	 * Beyond this number of folder↔media relations, stop loading the pairs
	 * in memory and count exactly instead (see rollup_counts()).
	 */
	const MAX_PAIRS = 50000;

	/** Allowed preset colors ('' = none / default). */
	const FOLDER_COLORS = [ '', '#ef4444', '#f59e0b', '#22c55e', '#0ea5e9', '#8b5cf6', '#64748b' ];

	/**
	 * Human-readable color labels, for the swatch hover: a hexadecimal
	 * code means nothing to the user.
	 *
	 * @return array<string,string> hex => label
	 */
	private static function folder_color_labels(): array {
		return [
			'#ef4444' => __( 'Red', 'lumia-tools' ),
			'#f59e0b' => __( 'Orange', 'lumia-tools' ),
			'#22c55e' => __( 'Green', 'lumia-tools' ),
			'#0ea5e9' => __( 'Blue', 'lumia-tools' ),
			'#8b5cf6' => __( 'Purple', 'lumia-tools' ),
			'#64748b' => __( 'Gray', 'lumia-tools' ),
		];
	}

	public function init(): void {
		// init() is called during the `init` hook (via init_active_modules).
		// The taxonomy is therefore registered right away: an add_action('init')
		// added here, at the same priority as the running hook, would never be
		// fired (WP does not run callbacks added at the current priority).
		if ( did_action( 'init' ) ) {
			$this->register_taxonomy();
		} else {
			add_action( 'init', [ $this, 'register_taxonomy' ] );
		}

		// Deterministic hook: the core fires this action at the end of
		// wp_enqueue_media(). It therefore covers ALL contexts that display a
		// media library, with no need to guess which one or when it loads it —
		// upload.php, block editor, Customizer, widgets screen, and frontend
		// builders such as Bricks (?bricks=run).
		//
		// Hooking on admin_enqueue_scripts at a fixed priority did not work:
		// each screen calls wp_enqueue_media() at a different time, and the
		// latest ones (Customizer, widgets) came after our test.
		add_action( 'wp_enqueue_media', [ $this, 'enqueue_assets' ] );

		// List view of upload.php: the only case where we want the panel without
		// wp.media necessarily being loaded.
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ], 100 );

		// Server-side filtering.
		add_filter( 'ajax_query_attachments_args', [ $this, 'filter_media_query' ] );
		add_action( 'pre_get_posts', [ $this, 'filter_list_view' ] );

		// Automatically attach new media to the current folder.
		add_action( 'add_attachment', [ $this, 'assign_uploaded_attachment' ] );

		// Expose a media's folders to the Backbone model, so the details panel
		// can display and edit them without a dedicated request.
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
	 * TAXONOMY
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

				// Required: this is the query_var that wp_ajax_query_attachments()
				// whitelists, hence our only channel down to WP_Query.
				'query_var'             => self::QUERY_VAR,

				// The default callback (_update_post_term_count) only counts posts with
				// the "publish" status. Media are "inherit": without this override, every
				// folder counter stays stuck at 0.
				'update_count_callback' => '_update_generic_term_count',

				'labels'                => [
					'name'          => __( 'Media folders', 'lumia-tools' ),
					'singular_name' => __( 'Media folder', 'lumia-tools' ),
				],
			]
		);
	}

	/* ================================================================
	 * SERVER-SIDE FILTERING
	 * ================================================================ */

	/**
	 * Translates lumia_folder into a tax_query for the AJAX media library (grid
	 * view and all wp.media modals).
	 *
	 * @param array<string, mixed> $query
	 * @return array<string, mixed>
	 */
	public function filter_media_query( array $query ): array {
		$raw = isset( $_REQUEST['query'][ self::QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( $_REQUEST['query'][ self::QUERY_VAR ] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification -- wp.media AJAX request, nonce handled by WordPress.

		// Always remove the raw value: left in place, WP_Query would try to
		// resolve it as a term slug.
		unset( $query[ self::QUERY_VAR ] );

		$tax_query = $this->build_tax_query( $raw );
		if ( null !== $tax_query ) {
			$query['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery
		}

		// Deterministic order. WordPress sorts the media library on post_date alone;
		// but several media uploaded in the same second tie, and MySQL is then
		// free to order them differently from one page to the next. Infinite scroll
		// then repeats some items and skips others (observed: 274 media, 226
		// displayed). Breaking the tie by ID makes pagination stable without
		// changing the visible order.
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
	 * Same filtering for the upload.php list view (?mode=list), which does not
	 * go through wp.media AJAX.
	 */
	public function filter_list_view( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'upload' !== $screen->id ) {
			return;
		}

		$raw = isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification -- simple display filter via GET.

		$tax_query = $this->build_tax_query( $raw );
		if ( null === $tax_query ) {
			return;
		}

		// Clearing the raw value is essential. Since the taxonomy has a query_var,
		// WP_Query::parse_tax_query() would otherwise add ITS OWN clause, resolved
		// by slug ("4" and "__none__" match no term), AND-ed with ours — always an
		// empty result.
		$query->set( self::QUERY_VAR, '' );
		$query->set( 'tax_query', $tax_query );
	}

	/**
	 * Builds the tax_query matching a folder value.
	 * Returns null when no filtering should apply.
	 *
	 * @param mixed $raw '' / null = all, UNASSIGNED = unfiled, otherwise term_id.
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
	 * Files a freshly uploaded media into the active folder at upload time
	 * (passed by the JS in the plupload POST).
	 */
	public function assign_uploaded_attachment( int $attachment_id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification -- plupload upload, nonce checked by async-upload.php.
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
	 * Loads the folders UI on EVERY admin page that embeds wp.media —
	 * upload.php, but also the content editor and the insert modals.
	 */
	public function enqueue_assets(): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}

		// Two distinct situations:
		//  - media-views loaded: wp.media is there, we extend AttachmentsBrowser;
		//  - upload.php in list view: wp.media is NOT loaded, but we still want
		//    the panel (media.js then mounts it standalone).
		$has_media_views = wp_script_is( 'media-views', 'enqueued' );

		if ( ! $has_media_views && ! $this->is_upload_list_screen() ) {
			return;
		}

		// LUMIA design system. We load ONLY tokens.css (pure custom properties)
		// + the components: reset.css is scoped under .lumia-admin-wrap and
		// layout.css overrides the wp-admin chrome — neither belongs on a
		// native WordPress page.
		wp_enqueue_style( 'lumia-tokens-css', LUMIA_ASSETS_URL . 'admin/css/tokens.css', [], LUMIA_VERSION );
		wp_enqueue_style( 'lumia-components-css', LUMIA_ASSETS_URL . 'admin/css/components.css', [ 'lumia-tokens-css' ], LUMIA_VERSION );
		wp_enqueue_style( 'lumia-buttons-css', LUMIA_ASSETS_URL . 'admin/css/buttons.css', [ 'lumia-components-css' ], LUMIA_VERSION );
		wp_enqueue_style( 'lumia-notifications-css', LUMIA_ASSETS_URL . 'admin/css/notifications.css', [], LUMIA_VERSION );
		wp_enqueue_style( 'lumia-media-css', LUMIA_ASSETS_URL . 'admin/css/modules/media.css', [ 'lumia-components-css' ], LUMIA_VERSION );

		// admin.js provides the named modals (lumiaModalOpen/Close) used by the
		// media UI; notifications.js provides window.lumiaShowToast.
		wp_enqueue_script( 'lumia-admin-js', LUMIA_ASSETS_URL . 'admin/js/admin.js', [], LUMIA_VERSION, true );
		wp_enqueue_script( 'lumia-notifications-js', LUMIA_ASSETS_URL . 'admin/js/notifications.js', [], LUMIA_VERSION, true );
		wp_enqueue_script( 'lumia-sortable-js', LUMIA_ASSETS_URL . 'admin/js/vendor/sortable.min.js', [], LUMIA_VERSION, true );

		// media-views is declared as a dependency only when already there: adding
		// it systematically would force loading the whole Backbone media library
		// on the list view, which does not need it.
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
				// The UI must reflect the server guard: without this flag, an author
				// sees the "New folder" / "Delete" buttons and only gets a refusal
				// afterwards. The server remains the sole judge — guard_manage() does
				// not depend on any value sent by the client.
				'canManage'   => current_user_can( self::CAP_MANAGE ),
				'i18n'        => [
					'folders'         => __( 'Folders', 'lumia-tools' ),
					'color'           => __( 'Color', 'lumia-tools' ),
					'defaultColor'    => __( 'Default', 'lumia-tools' ),
					'newFolder'       => __( 'New folder', 'lumia-tools' ),
					'newSubfolder'    => __( 'New subfolder', 'lumia-tools' ),
					'folderName'      => __( 'Folder name', 'lumia-tools' ),
					'allMedia'        => __( 'All media', 'lumia-tools' ),
					'unorganized'     => __( 'Unfiled', 'lumia-tools' ),
					'deleteFolder'    => __( 'Delete folder?', 'lumia-tools' ),
					'deleteFolderMsg' => __( 'Media in this folder and its subfolders will be moved to the root.', 'lumia-tools' ),
					'rename'          => __( 'Rename', 'lumia-tools' ),
					'delete'          => __( 'Delete', 'lumia-tools' ),
					'create'          => __( 'Create', 'lumia-tools' ),
					'save'            => __( 'Save', 'lumia-tools' ),
					'cancel'          => __( 'Cancel', 'lumia-tools' ),
					'loading'         => __( 'Loading…', 'lumia-tools' ),
					'folderCreated'   => __( 'Folder created.', 'lumia-tools' ),
					'folderRenamed'   => __( 'Folder renamed.', 'lumia-tools' ),
					'folderDeleted'   => __( 'Folder deleted.', 'lumia-tools' ),
					'folderMoved'     => __( 'Folder moved.', 'lumia-tools' ),
					'itemsMoved'      => __( 'media item(s) moved.', 'lumia-tools' ),
					'itemsAdded'      => __( 'media item(s) added to the folder.', 'lumia-tools' ),
					'itemsRemoved'    => __( 'media item(s) removed from the folder.', 'lumia-tools' ),
					'noFolder'        => __( 'No folder', 'lumia-tools' ),
					'folderUpdated'   => __( 'Folders updated.', 'lumia-tools' ),
					'itemsRefused'    => __( 'media item(s) skipped: you are not allowed to edit them.', 'lumia-tools' ),
					'error'           => __( 'Error', 'lumia-tools' ),
					'networkError'    => __( 'Network error', 'lumia-tools' ),
					'actions'         => __( 'Actions', 'lumia-tools' ),
				],
			]
		);
	}

	/**
	 * upload.php displayed in list view — the only screen where we want the
	 * panel without wp.media being loaded.
	 */
	private function is_upload_list_screen(): bool {
		if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'upload' !== $screen->id ) {
			return false;
		}

		// WordPress remembers the last chosen mode in a user meta; the URL
		// parameter takes precedence when present.
		$mode = isset( $_GET['mode'] ) // phpcs:ignore WordPress.Security.NonceVerification
			? sanitize_text_field( wp_unslash( $_GET['mode'] ) ) // phpcs:ignore WordPress.Security.NonceVerification
			: (string) get_user_option( 'media_library_mode', get_current_user_id() );

		return 'list' === $mode;
	}

	/* ================================================================
	 * AJAX
	 * ================================================================ */

	/**
	 * Capability required to CHANGE the tree (create, rename, delete,
	 * move, color a folder).
	 *
	 * `upload_files` — the original sole guard — is the capability to UPLOAD,
	 * not to organize the site library. A mere author could therefore rename
	 * other people's folders and delete one with all its descendants, in a
	 * single call. Folders are a taxonomy: the capability that describes this
	 * power is `manage_categories`, held from the editor role up.
	 */
	const CAP_MANAGE = 'manage_categories';

	/** Capability required to READ the tree and file one's own media. */
	const CAP_USE = 'upload_files';

	/** Read guard: see the folders, filter the media library. */
	private function guard(): void {
		check_ajax_referer( 'lumia_admin_nonce', 'nonce' );
		if ( ! current_user_can( self::CAP_USE ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'lumia-tools' ) ] );
		}
	}

	/** Write guard: any mutation of the tree itself. */
	private function guard_manage(): void {
		$this->guard();
		if ( ! current_user_can( self::CAP_MANAGE ) ) {
			wp_send_json_error( [ 'message' => __( 'You are not allowed to change how the media library is organized.', 'lumia-tools' ) ] );
		}
	}

	/**
	 * Adds a media's folders to its Backbone model (lumiaFolders key).
	 *
	 * wp.media already prepares each attachment for the JS: we hook in here
	 * rather than opening an endpoint, which keeps the details panel
	 * in sync with the grid without any extra request.
	 *
	 * @param array<string, mixed> $response Data prepared by the core.
	 * @param \WP_Post $attachment Attachment concerned.
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
	 * Tree + counters. The displayed counter includes descendants, which is
	 * what one expects from a folder; it is computed by rolling up in PHP
	 * rather than with one query per term.
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
	 * Counter per folder, descendants included.
	 *
	 * Since a media can belong to several folders, simply adding the children's
	 * counters into the parent counted it twice when it was filed in both the
	 * parent and one of its subfolders. We therefore take the union of the
	 * media SETS, not the sum of the numbers.
	 *
	 * A single query fetches all (folder, media) pairs. Beyond MAX_PAIRS we
	 * fall back to addition: approximate for multi-folder media, but we refuse
	 * to load an unbounded number of relations in memory.
	 *
	 * @param \WP_Term[] $terms  Terms of the taxonomy.
	 * @param array<int, int> $parent_map term_id => parent_id.
	 * @return array<int, int> term_id => number of distinct media.
	 */
	private function rollup_counts( array $terms, array $parent_map ): array {
		global $wpdb;

		$direct = [];
		foreach ( $terms as $term ) {
			$direct[ (int) $term->term_id ] = (int) $term->count;
		}

		// Direct read on purpose: WP does not expose a taxonomy's term/object
		// pairs, and a cache would serve stale counters after each media move.
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- same reason as above.
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

		// Each media rolls up to all the ancestors of its folder; the array keys
		// ensure uniqueness, hence no double counting.
		$totals = [];
		foreach ( $direct as $term_id => $unused ) {
			$totals[ $term_id ] = $sets[ $term_id ] ?? [];
		}

		foreach ( $sets as $term_id => $objects ) {
			$ancestor = $parent_map[ $term_id ] ?? 0;
			$guard    = 0;
			while ( $ancestor > 0 && isset( $totals[ $ancestor ] ) && $guard++ < 100 ) {
				$totals[ $ancestor ] += $objects; // union: keeps existing keys
				$ancestor             = $parent_map[ $ancestor ] ?? 0;
			}
		}

		return array_map( 'count', $totals );
	}

	/**
	 * Falls back to plain additions when the number of relations is too large.
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
	 * Actual number of media without a folder. A real query rather than a
	 * subtraction: the latter became wrong as soon as a term counter was
	 * stale or a media belonged to several folders.
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
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- only way to count media without a folder (see docblock).
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

		$name      = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by guard().
		$parent_id = isset( $_POST['parent_id'] ) ? (int) $_POST['parent_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by guard().

		if ( ! $name ) {
			wp_send_json_error( [ 'message' => __( 'Name required.', 'lumia-tools' ) ] );
		}
		if ( $parent_id > 0 && ! term_exists( $parent_id, self::TAXONOMY ) ) {
			wp_send_json_error( [ 'message' => __( 'Parent folder not found.', 'lumia-tools' ) ] );
		}

		$result = wp_insert_term( $name, self::TAXONOMY, [ 'parent' => max( 0, $parent_id ) ] );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		wp_send_json_success( array_merge( [ 'created' => (int) $result['term_id'] ], $this->get_folder_payload() ) );
	}

	public function ajax_rename_folder(): void {
		$this->guard_manage();

		$id   = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by guard().
		$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by guard().

		if ( ! $id || ! $name ) {
			wp_send_json_error( [ 'message' => __( 'Missing parameters.', 'lumia-tools' ) ] );
		}
		if ( ! term_exists( $id, self::TAXONOMY ) ) {
			wp_send_json_error( [ 'message' => __( 'Folder not found.', 'lumia-tools' ) ] );
		}

		$result = wp_update_term( $id, self::TAXONOMY, [ 'name' => $name ] );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		wp_send_json_success( $this->get_folder_payload() );
	}

	/**
	 * Deletes a folder and ALL its descendants; the affected media
	 * fall back into "unfiled".
	 *
	 * The previous version only went down one level: wp_delete_term()
	 * attaches the children to the grandparent, which made subfolders that were
	 * supposed to disappear climb back up the tree.
	 */
	public function ajax_delete_folder(): void {
		$this->guard_manage();

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by guard().
		if ( ! $id || ! term_exists( $id, self::TAXONOMY ) ) {
			wp_send_json_error( [ 'message' => __( 'Folder not found.', 'lumia-tools' ) ] );
		}

		$children  = get_term_children( $id, self::TAXONOMY );
		$to_delete = is_wp_error( $children )
			? [ $id ]
			: array_merge( array_map( 'intval', $children ), [ $id ] );

		// Detach the media before deletion, to not depend on the order.
		$attachments = get_posts(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- one-off folder deletion action.
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

		// Children first: wp_delete_term() would otherwise reparent the descendants.
		foreach ( $to_delete as $term_id ) {
			wp_delete_term( $term_id, self::TAXONOMY );
		}

		wp_send_json_success( $this->get_folder_payload() );
	}

	/**
	 * Files media into a folder.
	 *
	 * A media can belong to SEVERAL folders. The mode decides the gesture:
	 *  - replace: the media will only be in $folder_id (0 = no folder);
	 *  - add:     $folder_id is added to the existing folders;
	 *  - remove:  $folder_id is removed, the others are kept.
	 */
	public function ajax_move_items(): void {
		$this->guard();

		$attachment_ids = array_filter( array_map( 'absint', (array) ( $_POST['ids'] ?? [] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by guard().
		$folder_id      = isset( $_POST['folder_id'] ) ? (int) $_POST['folder_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by guard().
		$mode           = sanitize_key( wp_unslash( $_POST['mode'] ?? 'replace' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by guard().

		if ( ! in_array( $mode, [ 'replace', 'add', 'remove' ], true ) ) {
			$mode = 'replace';
		}

		if ( ! $attachment_ids ) {
			wp_send_json_error( [ 'message' => __( 'No media selected.', 'lumia-tools' ) ] );
		}
		if ( $folder_id > 0 && ! term_exists( $folder_id, self::TAXONOMY ) ) {
			wp_send_json_error( [ 'message' => __( 'Folder not found.', 'lumia-tools' ) ] );
		}
		// add and remove make no sense without a target folder.
		if ( $folder_id <= 0 && 'replace' !== $mode ) {
			wp_send_json_error( [ 'message' => __( 'Target folder required.', 'lumia-tools' ) ] );
		}

		$moved   = 0;
		$refused = 0;

		foreach ( $attachment_ids as $att_id ) {
			if ( 'attachment' !== get_post_type( $att_id ) ) {
				continue;
			}

			// PER-ATTACHMENT check: the entry guard only says that the caller may
			// use the media library, not that they may touch THIS media. Without this
			// test, an author could refile the administrator's media by sending
			// their IDs.
			//
			// `edit_post` on an attachment resolves to edit_posts /
			// edit_others_posts depending on the owner: this is exactly the rule
			// WordPress already applies when editing a media.
			if ( ! current_user_can( 'edit_post', $att_id ) ) {
				++$refused;
				continue;
			}

			if ( 'add' === $mode ) {
				// The 4th argument set to true appends instead of replacing.
				wp_set_object_terms( $att_id, [ $folder_id ], self::TAXONOMY, true );
			} elseif ( 'remove' === $mode ) {
				wp_remove_object_terms( $att_id, [ $folder_id ], self::TAXONOMY );
			} else {
				wp_set_object_terms( $att_id, $folder_id > 0 ? [ $folder_id ] : [], self::TAXONOMY );
			}

			++$moved;
		}

		// `refused` goes back up to the interface: a silently partial move would
		// read as a bug, whereas the refusal is the correct behavior.
		wp_send_json_success(
			array_merge(
				[
					'moved'   => $moved,
					'mode'    => $mode,
					'refused' => $refused,
				],
				$this->get_folder_payload()
			)
		);
	}

	/**
	 * Re-parents a folder (folder → folder drag).
	 */
	public function ajax_move_folder(): void {
		$this->guard_manage();

		$id        = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by guard().
		$parent_id = isset( $_POST['parent_id'] ) ? (int) $_POST['parent_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by guard().

		if ( ! $id || ! term_exists( $id, self::TAXONOMY ) ) {
			wp_send_json_error( [ 'message' => __( 'Folder not found.', 'lumia-tools' ) ] );
		}
		if ( $id === $parent_id ) {
			wp_send_json_error( [ 'message' => __( 'A folder cannot be its own parent.', 'lumia-tools' ) ] );
		}

		// Anti-cycle guard: moving a folder into one of its descendants would
		// detach the whole branch from the tree.
		if ( $parent_id > 0 ) {
			if ( ! term_exists( $parent_id, self::TAXONOMY ) ) {
				wp_send_json_error( [ 'message' => __( 'Parent folder not found.', 'lumia-tools' ) ] );
			}
			$descendants = get_term_children( $id, self::TAXONOMY );
			if ( ! is_wp_error( $descendants ) && in_array( $parent_id, array_map( 'intval', $descendants ), true ) ) {
				wp_send_json_error( [ 'message' => __( 'A folder cannot be moved into one of its own subfolders.', 'lumia-tools' ) ] );
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

		$id    = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by guard().
		$color = sanitize_text_field( wp_unslash( $_POST['color'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by guard().

		if ( ! $id || ! term_exists( $id, self::TAXONOMY ) ) {
			wp_send_json_error( [ 'message' => __( 'Folder not found.', 'lumia-tools' ) ] );
		}
		if ( '' !== $color && ! in_array( $color, self::FOLDER_COLORS, true ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid color.', 'lumia-tools' ) ] );
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
			'taxonomy' => [ self::TAXONOMY ], // delete all terms
		];
	}
}
