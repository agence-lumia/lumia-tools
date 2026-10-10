<?php
namespace Lumia\Tools\Modules\ImageOptimizer;

defined( 'ABSPATH' ) || exit;

use Lumia\Tools\Core\AbstractModule;
use Lumia\Tools\Admin\Admin;

/**
 * Image Optimizer module — orchestrator.
 *
 * Delegates file processing to ImageProcessor,
 * the bulk workflow to BulkProcessor,
 * and the media library UI to MediaLibrary.
 */
class Module extends AbstractModule {

	private const BATCH_SIZE = 5;

	private const STATS_SUFFIX      = '_stats';
	private const BULK_STATE_SUFFIX = '_bulk_state';

	/**
	 * Settings schema version, stored in the option (`settings_version`). Version 2 (AVIF
	 * siblings): `quality` reset to 70 once, `max_dimension` replaces `max_width` /
	 * `max_height`, `format_mode` and `keep_original` removed.
	 */
	private const SETTINGS_VERSION = 2;

	/** AVIF encoder speed presets (libheif `heic:speed`). */
	public const SPEEDS = [
		'balanced' => 8,
		'fast'     => 9,
	];

	/** Folder (under uploads) holding the untouched originals, suffixed with a token: see get_backup_dir(). */
	public const BACKUP_DIR           = 'lumia-originals';
	private const BACKUP_TOKEN_SUFFIX = '_backup_token';

	/**
	 * Same folder under the former name of the plugin (Studio Kyne Mini Tools).
	 * The migration renames it; when that rename failed, get_backup_dir() keeps
	 * reading it.
	 */
	public const LEGACY_BACKUP_DIR = 'skmt-originals';

	/**
	 * Meta: source files left next to the converted ones (keep_original),
	 * paths relative to the uploads folder. Absent from the WordPress metadata,
	 * they would not leave with the media item without this list.
	 */
	private const FALLBACK_META = '_lumia_fallback_files';

	/** Metas describing a media item's optimization (cleared on restore). */
	private const OPTIMIZATION_META = [
		'_lumia_optimized',
		'_lumia_original_bytes',
		'_lumia_optimized_bytes',
		'_lumia_bytes_saved',
		'_lumia_main_original_bytes',
		'_lumia_main_optimized_bytes',
		'_lumia_main_bytes_saved',
		'_lumia_optimized_format',
		'_lumia_optimized_mime',
	];

	/* ================================================================
	 * SUB-OBJECTS (set up in init())
	 * ================================================================ */

	private ImageProcessor $processor;
	private BulkProcessor $bulk;
	private MediaLibrary $media_library;
	private SvgHandler $svg;
	private ?FileLifecycle $lifecycle = null;
	private ?DeliveryProbe $delivery  = null;

	/**
	 * Active module settings (in-memory cache).
	 *
	 * @var array<string, mixed>
	 */
	private array $settings = [];

	/**
	 * URL pairs waiting to be rewritten during a bulk batch: they are
	 * collected, then handed to UrlRewriter in a single call (one query
	 * per table for the whole batch, instead of one per image).
	 *
	 * @var array<string, string>
	 */
	private array $pending_url_pairs = [];

	/** True between begin_deferred_url_rewrites() and flush_url_rewrites(). */
	private bool $defer_url_rewrites = false;

	/* ================================================================
	 * INITIALIZATION
	 * ================================================================ */

	/**
	 * Loads the settings, creates the sub-objects and registers the hooks.
	 */
	public function init(): void {
		$this->settings = $this->get_settings();

		// Sub-objects
		$this->processor = new ImageProcessor( $this->settings );

		$this->bulk = new BulkProcessor(
			$this->get_module_option_key() . self::BULK_STATE_SUFFIX,
			function ( int $id ): void {
				$this->begin_deferred_url_rewrites();
				$this->process_and_update_attachment( $id, true );
			},
			fn(): array   => $this->get_stats(),
			fn( int $user_id ) => $this->notify_bulk_complete( $user_id ),
			fn() => $this->flush_url_rewrites()
		);

		$this->media_library = new MediaLibrary( $this, $this->processor );
		$this->media_library->init();

		// Secure SVG support (only hooks its filters when enabled).
		$this->svg = new SvgHandler( $this->settings );
		$this->svg->init();

		// AVIF siblings: queueing on metadata changes, deletion, names, WordPress image settings.
		$this->get_lifecycle()->register();

		// Delivery self-test: daily check, Retest button, browser check.
		$this->get_delivery_probe()->register();

		// Automatic alt text
		add_action( 'add_attachment', [ $this, 'generate_alt_text' ] );

		// The kept original and the fallback files follow the media item
		// when it is permanently deleted.
		add_action( 'delete_attachment', [ $this, 'delete_kept_files' ] );

		// Bulk AJAX
		add_action( 'wp_ajax_lumia_image_optimizer_bulk_scan', [ $this, 'ajax_bulk_scan' ] );
		add_action( 'wp_ajax_lumia_image_optimizer_bulk', [ $this, 'ajax_bulk_start' ] );
		add_action( 'wp_ajax_lumia_image_optimizer_bulk_status', [ $this, 'ajax_bulk_status' ] );

		// Cron
		add_action( 'lumia_image_optimizer_cron', [ $this, 'run_cron_batch' ] );

		// Migration of the media converted by the former pipeline (wp lumia images migrate).
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'lumia images', new MigrationCommand( $this ) );
		}
	}

	/* ================================================================
	 * SETTINGS
	 * ================================================================ */

	/**
	 * The AVIF siblings' lifecycle (created on first use: on_deactivate() may run on an
	 * instance that init() never saw).
	 */
	public function get_lifecycle(): FileLifecycle {
		if ( null === $this->lifecycle ) {
			$this->lifecycle = new FileLifecycle( $this );
		}

		return $this->lifecycle;
	}

	/**
	 * The delivery self-test (created on first use, like the lifecycle). A CDN that mixes the
	 * variants up has every generated sibling deleted, through the lifecycle's purge.
	 */
	public function get_delivery_probe(): DeliveryProbe {
		if ( null === $this->delivery ) {
			$this->delivery = new DeliveryProbe(
				function (): void {
					$this->get_lifecycle()->purge_all();
				}
			);
		}

		return $this->delivery;
	}

	/**
	 * Module state, read from the global settings: save_settings() also runs for an inactive
	 * module (settings import).
	 */
	private function is_module_active(): bool {
		$settings = get_option( 'lumia_settings', [] );

		return is_array( $settings ) && ! empty( $settings['modules'][ $this->id ] );
	}

	/**
	 * Settings, migrated once from the version 1 schema (see SETTINGS_VERSION).
	 *
	 * @return array<string, mixed>
	 */
	public function get_settings(): array {
		$stored = get_option( $this->get_module_option_key(), [] );
		if ( is_array( $stored ) && $stored && (int) ( $stored['settings_version'] ?? 0 ) < self::SETTINGS_VERSION ) {
			$this->save_module_settings( self::migrate_settings( $stored ) );
		}

		return $this->get_module_settings(
			[
				'optimize_on_upload'     => true,
				'quality'                => 70,
				'speed'                  => 'balanced',
				'max_dimension'          => 2560,
				'exclude_suffixes'       => [ '-noopt' ],
				'convert_modern_uploads' => true,
				'strip_exif'             => true,
				'generate_alt'           => true,
				'svg_upload'             => true,
				'svg_roles'              => [ 'administrator' ],
				'settings_version'       => self::SETTINGS_VERSION,
			]
		);
	}

	/**
	 * Version 1 → 2. The former quality was ignored by Imagick for AVIF: it is replaced by
	 * the new default once, never again (settings_version).
	 *
	 * @param array<string, mixed> $stored
	 * @return array<string, mixed>
	 */
	private static function migrate_settings( array $stored ): array {
		$stored['quality'] = 70;

		if ( isset( $stored['max_width'] ) || isset( $stored['max_height'] ) ) {
			$stored['max_dimension'] = max( absint( $stored['max_width'] ?? 0 ), absint( $stored['max_height'] ?? 0 ) );
		}

		unset( $stored['format_mode'], $stored['keep_original'], $stored['max_width'], $stored['max_height'] );
		$stored['settings_version'] = self::SETTINGS_VERSION;

		return $stored;
	}

	/**
	 * A version 1 configuration file is migrated before going through the form path.
	 *
	 * @param array<string, mixed> $stored
	 * @return array<string, mixed>
	 */
	public function to_form_payload( array $stored ): array {
		return (int) ( $stored['settings_version'] ?? 0 ) < self::SETTINGS_VERSION ? self::migrate_settings( $stored ) : $stored;
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	public function save_settings( array $settings ): bool {
		$max = isset( $settings['max_dimension'] ) ? absint( $settings['max_dimension'] ) : 2560;

		$sanitized = [
			'optimize_on_upload'     => ! empty( $settings['optimize_on_upload'] ),
			'quality'                => isset( $settings['quality'] ) ? min( 100, max( 1, absint( $settings['quality'] ) ) ) : 70,
			'speed'                  => isset( $settings['speed'] ) && is_string( $settings['speed'] ) && isset( self::SPEEDS[ $settings['speed'] ] )
				? $settings['speed']
				: 'balanced',
			'max_dimension'          => 0 === $max ? 0 : min( 20000, max( 100, $max ) ),
			'exclude_suffixes'       => self::sanitize_suffixes( $settings['exclude_suffixes'] ?? [] ),
			'convert_modern_uploads' => ! empty( $settings['convert_modern_uploads'] ),
			'strip_exif'             => ! empty( $settings['strip_exif'] ),
			'generate_alt'           => ! empty( $settings['generate_alt'] ),
			'svg_upload'             => ! empty( $settings['svg_upload'] ),
			'svg_roles'              => $this->sanitize_roles( $settings['svg_roles'] ?? [] ),
			'settings_version'       => self::SETTINGS_VERSION,
		];

		$this->settings = $sanitized;

		$saved = $this->save_module_settings( $sanitized );

		// Delivery tested again on every save (spec 1): the result also says whether the
		// AVIF can be generated.
		if ( $this->is_module_active() ) {
			$this->get_delivery_probe()->run();
		}

		return $saved;
	}

	/**
	 * Exclusion suffixes: a list, or the form's text (comma or line separated). Lowercase,
	 * `[a-z0-9_-]` only, a leading hyphen added when missing, 20 at most.
	 *
	 * @param mixed $raw
	 * @return string[]
	 */
	private static function sanitize_suffixes( $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/[,\r\n]+/', $raw );
		}

		$suffixes = [];
		foreach ( is_array( $raw ) ? $raw : [] as $suffix ) {
			$suffix = substr( (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $suffix ) ), 0, 32 );
			if ( '' === trim( $suffix, '-_' ) ) {
				continue;
			}
			$suffixes[] = '-' === $suffix[0] || '_' === $suffix[0] ? $suffix : '-' . $suffix;
		}

		return array_slice( array_values( array_unique( $suffixes ) ), 0, 20 );
	}

	/**
	 * Keeps only role slugs that actually exist in WordPress.
	 *
	 * @param mixed $roles
	 * @return string[]
	 */
	private function sanitize_roles( $roles ): array {
		if ( ! is_array( $roles ) ) {
			return [];
		}
		$valid = array_keys( wp_roles()->get_names() );
		return array_values( array_intersect( array_map( 'sanitize_key', $roles ), $valid ) );
	}

	/* ================================================================
	 * ADMIN ASSETS
	 * ================================================================ */

	public function get_admin_css(): array {
		return [
			LUMIA_ASSETS_URL . 'admin/css/modules/image-optimizer.css',
		];
	}

	public function get_admin_js(): array {
		return [
			LUMIA_ASSETS_URL . 'admin/js/modules/image-optimizer.js',
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_admin_js_data(): array {
		return [
			'bulkState' => $this->bulk->get_state(),
			'i18n'      => [
				'bulkScanning'  => __( 'Scanning…', 'lumia-tools' ),
				'bulkRunning'   => __( 'Optimizing…', 'lumia-tools' ),
				'bulkProcessed' => __( 'Processed:', 'lumia-tools' ),
				'bulkRemaining' => __( 'Remaining:', 'lumia-tools' ),
				'bulkDone'      => __( 'Optimization complete', 'lumia-tools' ),
				'bulkComplete'  => __( 'All images have been optimized.', 'lumia-tools' ),
				'bulkRetry'     => __( 'Try again', 'lumia-tools' ),
				'networkError'  => __( 'Network error', 'lumia-tools' ),
				'mediaRunning'  => __( 'Processing…', 'lumia-tools' ),
				'mediaError'    => __( 'Error', 'lumia-tools' ),
				'cancel'        => __( 'Cancel', 'lumia-tools' ),
				'format'        => __( 'Target format', 'lumia-tools' ),
				'delivery'      => [
					'checking'   => __( 'Checking…', 'lumia-tools' ),
					'retesting'  => __( 'Testing…', 'lumia-tools' ),
					'unverified' => __( 'Could not be run', 'lumia-tools' ),
					'copied'     => __( 'Rule copied.', 'lumia-tools' ),
					'copyFailed' => __( 'Copy failed: select the rule and copy it by hand.', 'lumia-tools' ),
				],
				'reoptimize'    => [
					'title'    => __( 'Re-optimize this image?', 'lumia-tools' ),
					'backup'   => __( 'The image is reprocessed from the kept original, using the current settings.', 'lumia-tools' ),
					'noBackup' => __( 'No original was kept: the image is recompressed from its current version, and the quality drops a little with each pass.', 'lumia-tools' ),
					'confirm'  => __( 'Re-optimize', 'lumia-tools' ),
				],
				'convert'       => [
					'title'   => __( 'Convert image', 'lumia-tools' ),
					'message' => __( 'The file and its thumbnails change extension; URLs already inserted in the site are rewritten.', 'lumia-tools' ),
					'confirm' => __( 'Convert', 'lumia-tools' ),
				],
				'restore'       => [
					'title'   => __( 'Restore the original?', 'lumia-tools' ),
					'message' => __( 'The optimized versions are deleted, the thumbnails are regenerated from the original and the site URLs are rewritten to point to it.', 'lumia-tools' ),
					'confirm' => __( 'Restore', 'lumia-tools' ),
				],
			],
		];
	}

	/**
	 * Adds a persistent notice for the user who started the bulk run,
	 * so they are informed even if the batch finished while they had
	 * left the page (or through a background cron resumption).
	 */
	private function notify_bulk_complete( int $user_id ): void {
		if ( ! $user_id ) {
			return;
		}
		Admin::add_persistent_notice(
			'image_optimizer_bulk_done',
			__( 'Bulk image optimization complete.', 'lumia-tools' ),
			'success',
			$user_id
		);
	}

	/* ================================================================
	 * LIFECYCLE
	 * ================================================================ */

	/**
	 * Fingerprints checked in the background: a source changed while the module was off
	 * must not keep its old AVIF.
	 */
	public function on_activate(): void {
		delete_option( FileLifecycle::RECONCILE_CURSOR );
		if ( ! wp_next_scheduled( FileLifecycle::RECONCILE_HOOK ) ) {
			wp_schedule_single_event( time(), FileLifecycle::RECONCILE_HOOK );
		}

		// Nothing is generated before delivery is proven: tested right away, then daily.
		$probe = $this->get_delivery_probe();
		$probe->schedule();
		$probe->run();
	}

	public function on_deactivate(): void {
		// Remove pending crons.
		$timestamp = wp_next_scheduled( 'lumia_image_optimizer_cron' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'lumia_image_optimizer_cron' );
		}
		wp_unschedule_hook( FileLifecycle::RECONCILE_HOOK );

		// uploads/.htaccess block, daily check and verdict.
		DeliveryProbe::reset();

		// The server would keep serving the AVIF siblings with nobody to keep them up to
		// date: they go (the bulk regenerates them after a reactivation).
		$this->get_lifecycle()->purge_all();
	}

	/* ================================================================
	 * STATIC: INSTALL / UNINSTALL
	 * ================================================================ */

	public static function get_uninstall_keys(): array {
		return [
			'options' => [
				'lumia_module_image_optimizer',
				'lumia_module_image_optimizer' . self::STATS_SUFFIX,
				'lumia_module_image_optimizer' . self::BULK_STATE_SUFFIX,
				'lumia_module_image_optimizer' . self::BACKUP_TOKEN_SUFFIX,
				FileLifecycle::TOMBSTONES_OPTION,
				FileLifecycle::RECONCILE_CURSOR,
				DeliveryProbe::OPTION,
				MigrationCommand::PAIRS_OPTION,
			],
			'cron'    => [ FileLifecycle::RECONCILE_HOOK, DeliveryProbe::CRON_HOOK ],
			// The files in lumia-originals/ stay on disk: they are the
			// client's photos, not plugin data.
			'meta'    => array_merge( self::OPTIMIZATION_META, [ '_lumia_backup_file', self::FALLBACK_META, MigrationCommand::JOURNAL ] ),
		];
	}

	/* ================================================================
	 * UPLOAD HOOK
	 * ================================================================ */

	/**
	 * Hook wp_generate_attachment_metadata: optimizes + converts the original and the thumbnails.
	 *
	 * Single optimization point on upload: it runs after the thumbnails are
	 * generated and measures the real size of the original file. Do NOT
	 * pre-optimize the file in wp_handle_upload — otherwise the "before" measure
	 * is taken on an already compressed file and the displayed saving is zero (the
	 * image is still marked "optimized").
	 *
	 * @param array<string, mixed> $metadata
	 * @return array<string, mixed>
	 */
	public function optimize_attachment_sizes( array $metadata, int $attachment_id ): array {
		if ( ! $this->settings['optimize_on_upload'] ) {
			return $metadata;
		}

		// wp_generate_attachment_metadata is not only used for uploads:
		// a thumbnail regeneration tool replays it on media items already
		// inserted in pages. So we rewrite here too; on a real
		// upload the sweep simply finds nothing.
		return $this->process_attachment_metadata( $metadata, $attachment_id, false );
	}

	/* ================================================================
	 * PROCESSING AN ATTACHMENT
	 * ================================================================ */

	/**
	 * Public entry point: processes an attachment and updates its WP metadata.
	 * Used by MediaLibrary (single) and BulkProcessor (batch).
	 */
	public function process_and_update_attachment( int $attachment_id, bool $force = true ): void {
		$metadata = wp_get_attachment_metadata( $attachment_id );

		if ( $metadata ) {
			$metadata = $this->process_attachment_metadata( $metadata, $attachment_id, $force );
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		$this->generate_alt_text( $attachment_id );
	}

	/**
	 * Processes every size of an attachment (optimization + conversion).
	 *
	 * @param bool $force Ignore the "already optimized" flag.
	 * @param array<string, mixed> $metadata
	 * @return array<string, mixed>
	 */
	public function process_attachment_metadata( array $metadata, int $attachment_id, bool $force ): array {
		$mime_type = $this->processor->get_mime_type( '', $attachment_id );

		if ( empty( $mime_type ) || ! $this->processor->is_supported_mime( $mime_type ) ) {
			return $metadata;
		}

		$attached_file = get_attached_file( $attachment_id );

		if ( $this->processor->is_animated( (string) $attached_file, $mime_type ) ) {
			return $metadata;
		}

		if ( ! $force && $this->is_already_optimized( $attachment_id ) ) {
			return $metadata;
		}

		if ( empty( $metadata['file'] ) ) {
			return $metadata;
		}

		$upload_dir = wp_upload_dir();
		$base_path  = trailingslashit( $upload_dir['basedir'] );
		$subdir     = dirname( $metadata['file'] );
		$sizes_path = trailingslashit( $base_path . $subdir );
		$rel_dir    = ( '.' === $subdir || '' === $subdir ) ? '' : trailingslashit( str_replace( '\\', '/', $subdir ) );

		$main_before = 0;
		$main_after  = 0;

		// --- Thumbnails ---
		$sizes        = $this->process_sizes( $metadata, $mime_type, $sizes_path, $rel_dir );
		$total_before = $sizes['before'];
		$total_after  = $sizes['after'];
		$size_updates = $sizes['updates'];
		$url_pairs    = $sizes['url_pairs']; // old path relative to uploads => new one (see UrlRewriter)

		// --- Original file ---
		$original_file      = $base_path . $metadata['file'];
		$original_converted = false;
		$original_new_file  = '';

		if ( file_exists( $original_file ) ) {
			// Backup BEFORE optimize(): it is what recompresses and
			// resizes in place. An already optimized media item has no original
			// left to save — we would only copy a degraded version.
			if ( ! $this->is_already_optimized( $attachment_id ) ) {
				$this->backup_original( $attachment_id, $original_file, str_replace( '\\', '/', $metadata['file'] ) );
			}

			$before        = (int) filesize( $original_file );
			$main_before   = $before;
			$total_before += $before;

			$this->processor->optimize( $original_file );
			$converted  = $this->processor->convert( $original_file, $mime_type, $attachment_id );
			$final_file = false !== $converted ? $converted : $original_file;

			$after        = file_exists( $final_file ) ? (int) filesize( $final_file ) : $before;
			$main_after   = $after;
			$total_after += $after;

			if ( $converted && $converted !== $original_file ) {
				$original_converted = true;
				$original_new_file  = $converted;
				$this->update_attachment_database_refs( $attachment_id, $original_file, $converted );
				$url_pairs[ str_replace( '\\', '/', $metadata['file'] ) ] = $rel_dir . basename( $converted );
			}
		}

		// With keep_original, convert() leaves the source next to the converted file.
		// The keys of $url_pairs are precisely the old paths.
		$this->record_fallbacks( $attachment_id, array_keys( $url_pairs ), $base_path );

		// A renamed file is a broken link wherever its URL has already been
		// inserted: we rewrite within the same processing. In a bulk run, the
		// pairs are accumulated and rewritten in one go by flush.
		if ( $url_pairs ) {
			if ( $this->defer_url_rewrites ) {
				$this->pending_url_pairs += $url_pairs;
			} else {
				( new UrlRewriter() )->rewrite( $url_pairs );
			}
		}

		// --- WP metadata update ---
		if ( $original_converted ) {
			$metadata = $this->update_metadata_after_conversion( $metadata, $original_file, $original_new_file, $size_updates );
		} elseif ( ! empty( $size_updates ) ) {
			$metadata = $this->update_metadata_after_conversion( $metadata, '', '', $size_updates );
		}

		$metadata = $this->refresh_metadata_filesizes( $metadata, $base_path );

		// --- Stats and marking ---
		if ( $total_before > 0 ) {
			$bytes_saved = max( $total_before - $total_after, 0 );
			$this->update_stats( $bytes_saved, $total_before );

			$final_mime = $original_converted
				? $this->processor->get_mime_type( $original_new_file )
				: $mime_type;

			$final_path = $original_converted ? $original_new_file : $original_file;
			$this->mark_attachment_optimized(
				$attachment_id,
				$total_before,
				$total_after,
				$final_path,
				$final_mime,
				$main_before,
				$main_after
			);
		}

		return $metadata;
	}

	/**
	 * Optimizes and converts the thumbnails of an attachment.
	 *
	 * @param array<string, mixed> $metadata
	 * @return array{before: int, after: int, updates: array<string, array<string, string>>, url_pairs: array<string, string>}
	 */
	private function process_sizes( array $metadata, string $mime_type, string $sizes_path, string $rel_dir ): array {
		$result = [
			'before'    => 0,
			'after'     => 0,
			'updates'   => [],
			'url_pairs' => [],
		];

		foreach ( $metadata['sizes'] ?? [] as $size => $size_data ) {
			if ( empty( $size_data['file'] ) ) {
				continue;
			}

			$size_file = $sizes_path . $size_data['file'];
			if ( ! file_exists( $size_file ) ) {
				continue;
			}

			$before            = (int) filesize( $size_file );
			$result['before'] += $before;

			$this->processor->optimize( $size_file );
			$converted  = $this->processor->convert( $size_file, $mime_type );
			$final_file = false !== $converted ? $converted : $size_file;

			$result['after'] += file_exists( $final_file ) ? (int) filesize( $final_file ) : $before;

			if ( $converted && $converted !== $size_file ) {
				$result['updates'][ $size ]                           = [
					'file' => $converted,
					'mime' => $this->processor->get_mime_type( $converted ),
				];
				$result['url_pairs'][ $rel_dir . $size_data['file'] ] = $rel_dir . basename( $converted );
			}
		}

		return $result;
	}

	/**
	 * Accumulates URL rewrites instead of running them one by one.
	 * Call before each image of a batch; flush_url_rewrites() empties them.
	 */
	public function begin_deferred_url_rewrites(): void {
		$this->defer_url_rewrites = true;
	}

	/**
	 * Rewrites in a single pass everything a batch has accumulated.
	 */
	public function flush_url_rewrites(): void {
		$this->defer_url_rewrites = false;
		if ( ! $this->pending_url_pairs ) {
			return;
		}
		$pairs                   = $this->pending_url_pairs;
		$this->pending_url_pairs = [];
		( new UrlRewriter() )->rewrite( $pairs );
	}

	/* ================================================================
	 * ACTIONS ON A MEDIA ITEM (attachment details panel)
	 * ================================================================ */

	/**
	 * Absolute path of the kept original, '' if there is none.
	 */
	public function get_backup_path( int $attachment_id ): string {
		$rel = (string) get_post_meta( $attachment_id, '_lumia_backup_file', true );

		// The path ends up in copy() and wp_delete_file(): no "..".
		if ( '' === $rel || 0 !== validate_file( $rel ) ) {
			return '';
		}

		$path = $this->get_backup_dir() . '/' . $rel;

		return file_exists( $path ) ? $path : '';
	}

	/**
	 * Originals folder: uploads/lumia-originals-{token}.
	 *
	 * The originals keep their EXIF (GPS included), and uploads/ is served
	 * as is: a fixed name would make every copy guessable from the public
	 * URL of the image. The .htaccess only protects under Apache (nginx
	 * ignores it); it is the random, per-site token that protects.
	 *
	 * On a site migrated from Studio Kyne Mini Tools whose folder could not be
	 * renamed (permissions), the old skmt-originals-{token} is used as long as
	 * it exists and lumia-originals-{token} does not: no original is lost.
	 */
	private function get_backup_dir(): string {
		$key   = $this->get_module_option_key() . self::BACKUP_TOKEN_SUFFIX;
		$token = (string) get_option( $key, '' );
		if ( '' === $token ) {
			$token = strtolower( wp_generate_password( 24, false ) );
			update_option( $key, $token, false );
		}

		$base   = trailingslashit( wp_upload_dir()['basedir'] );
		$dir    = $base . self::BACKUP_DIR . '-' . $token;
		$legacy = $base . self::LEGACY_BACKUP_DIR . '-' . $token;

		return ! is_dir( $dir ) && is_dir( $legacy ) ? $legacy : $dir;
	}

	/**
	 * Copies the intact main file into lumia-originals/, only once.
	 *
	 * @param string $rel Path relative to the uploads folder (metadata['file']).
	 */
	private function backup_original( int $attachment_id, string $file, string $rel ): void {
		if ( empty( $this->settings['keep_original'] ) || '' !== $this->get_backup_path( $attachment_id ) || 0 !== validate_file( $rel ) ) {
			return;
		}

		$dir  = $this->get_backup_dir();
		$dest = $dir . '/' . $rel;

		if ( ! wp_mkdir_p( dirname( $dest ) ) || ! copy( $file, $dest ) ) {
			return;
		}

		// No directory listing, and access denied under Apache.
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local file created once.
			file_put_contents( $dir . '/.htaccess', "Require all denied\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- same.
		}

		update_post_meta( $attachment_id, '_lumia_backup_file', $rel );
	}

	/**
	 * Remembers the source files left on disk after conversion.
	 *
	 * The original extension is no longer known once the metadata has
	 * been rewritten: it is now or never.
	 *
	 * @param string[] $rels Paths relative to the uploads folder.
	 */
	private function record_fallbacks( int $attachment_id, array $rels, string $base_path ): void {
		$rels = array_filter(
			$rels,
			static function ( string $rel ) use ( $base_path ): bool {
				return 0 === validate_file( $rel ) && file_exists( $base_path . $rel );
			}
		);
		if ( ! $rels ) {
			return;
		}

		$known = get_post_meta( $attachment_id, self::FALLBACK_META, true );
		$known = is_array( $known ) ? $known : [];

		update_post_meta( $attachment_id, self::FALLBACK_META, array_values( array_unique( array_merge( $known, $rels ) ) ) );
	}

	/**
	 * Hook delete_attachment: deletes the kept original and the fallback
	 * files of the media item.
	 */
	public function delete_kept_files( int $attachment_id ): void {
		$backup = $this->get_backup_path( $attachment_id );
		if ( '' !== $backup ) {
			wp_delete_file( $backup );
		}

		$rels = get_post_meta( $attachment_id, self::FALLBACK_META, true );
		if ( ! is_array( $rels ) || ! $rels ) {
			return;
		}

		$rels = array_values( array_filter( $rels, 'is_string' ) );
		if ( ! $rels ) {
			return;
		}

		// Deleted by hand then re-uploaded, a fallback file may have
		// become another media item's file, or its own fallback: we
		// do not touch what belongs to another.
		global $wpdb;
		$like  = implode( ' OR ', array_fill( 0, count( $rels ), 'meta_value LIKE %s' ) );
		$other = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off deletion, nothing to cache.
			$wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id <> %d AND ( ( meta_key = '_wp_attached_file' AND meta_value IN (" . implode( ',', array_fill( 0, count( $rels ), '%s' ) ) . ") ) OR ( meta_key = %s AND ( {$like} ) ) )", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one %s per path.
				array_merge(
					[ $attachment_id ],
					$rels,
					[ self::FALLBACK_META ],
					array_map(
						static function ( string $rel ) use ( $wpdb ): string {
							return '%' . $wpdb->esc_like( '"' . $rel . '"' ) . '%';
						},
						$rels
					)
				)
			)
		);

		$taken = [];
		foreach ( $other as $row ) {
			// Another media item's main file: its sizes share the same
			// base name, none of our fallbacks is safe.
			if ( '_wp_attached_file' === $row->meta_key ) {
				return;
			}
			$list  = maybe_unserialize( $row->meta_value );
			$taken = array_merge( $taken, is_array( $list ) ? $list : [] );
		}

		$base_path = trailingslashit( wp_upload_dir()['basedir'] );
		foreach ( array_diff( $rels, $taken ) as $rel ) {
			if ( 0 === validate_file( $rel ) ) {
				wp_delete_file( $base_path . $rel );
			}
		}
	}

	/**
	 * Re-optimizes a media item with the current settings, possibly to another
	 * format.
	 *
	 * Starts again from the kept original if there is one: otherwise every pass
	 * recompresses an already compressed image.
	 *
	 * @param string $format '' (settings) or 'webp' / 'avif'.
	 */
	public function reprocess_attachment( int $attachment_id, string $format = '' ): ?\WP_Error {
		if ( '' !== $format ) {
			$cap = $this->processor->get_capabilities();
			if ( ! in_array( $format, [ 'webp', 'avif' ], true ) || empty( $cap[ $format ] ) ) {
				return new \WP_Error( 'lumia_format', __( 'This format is not available on this server.', 'lumia-tools' ) );
			}
		}

		$previous = strtolower( pathinfo( (string) get_attached_file( $attachment_id ), PATHINFO_EXTENSION ) );

		if ( '' !== $this->get_backup_path( $attachment_id ) ) {
			$error = $this->restore_original( $attachment_id, true );
			if ( $error ) {
				return $error;
			}
		} else {
			// The metas stay: the media item is still "optimized", and
			// process_attachment_metadata() does not save its degraded version
			// as if it were an original.
			$this->unrecord_stats( $attachment_id );
		}

		$this->with_format(
			$format,
			function () use ( $attachment_id ): void {
				$this->process_and_update_attachment( $attachment_id, true );
			}
		);

		// convert() only keeps the requested format if it lightens the image.
		// Starting from the original, a WebP converted in vain to AVIF would
		// fall back to JPEG: we re-encode it in its previous format.
		$result = strtolower( pathinfo( (string) get_attached_file( $attachment_id ), PATHINFO_EXTENSION ) );
		if ( '' !== $format && $result !== $format && $result !== $previous
			&& in_array( $previous, [ 'webp', 'avif' ], true ) && ! empty( $this->processor->get_capabilities()[ $previous ] ) ) {
			return $this->reprocess_attachment( $attachment_id, $previous );
		}

		return null;
	}

	/**
	 * Runs $callback with a forced conversion format (empty string: settings).
	 */
	private function with_format( string $format, callable $callback ): void {
		$processor = $this->processor;
		if ( '' !== $format ) {
			$this->processor = new ImageProcessor( array_merge( $this->settings, [ 'format_mode' => $format ] ) );
		}

		try {
			$callback();
		} finally {
			$this->processor = $processor;
		}
	}

	/**
	 * Recreates the thumbnails from the main file, then optimizes them
	 * if the media item is.
	 */
	public function regenerate_thumbnails( int $attachment_id ): ?\WP_Error {
		$old_metadata = wp_get_attachment_metadata( $attachment_id );
		$file         = (string) get_attached_file( $attachment_id );

		if ( ! is_array( $old_metadata ) || '' === $file || ! file_exists( $file ) ) {
			return new \WP_Error( 'lumia_missing', __( 'File not found.', 'lumia-tools' ) );
		}

		$metadata = $this->generate_metadata( $attachment_id, $file, $old_metadata );
		if ( null === $metadata ) {
			return new \WP_Error( 'lumia_regenerate', __( 'Thumbnail generation failed.', 'lumia-tools' ) );
		}

		if ( $this->is_already_optimized( $attachment_id ) ) {
			$subdir  = dirname( $metadata['file'] );
			$rel_dir = ( '.' === $subdir || '' === $subdir ) ? '' : trailingslashit( str_replace( '\\', '/', $subdir ) );

			// The thumbnails follow the main file's format: after a conversion
			// to WebP from the details panel, the settings (AVIF, auto…)
			// would give a WebP main file and AVIF thumbnails.
			$format = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
			$this->with_format(
				in_array( $format, [ 'webp', 'avif' ], true ) ? $format : '',
				function () use ( &$metadata, $file, $rel_dir, $attachment_id ): void {
					$base_path = trailingslashit( wp_upload_dir()['basedir'] );
					$sizes     = $this->process_sizes( $metadata, $this->processor->get_mime_type( $file ), $base_path . $rel_dir, $rel_dir );
					$metadata  = $this->update_metadata_after_conversion( $metadata, '', '', $sizes['updates'] );
					$this->record_fallbacks( $attachment_id, array_keys( $sizes['url_pairs'] ), $base_path );
				}
			);
		}

		$metadata = $this->replace_metadata( $attachment_id, $old_metadata, $metadata );

		if ( $this->is_already_optimized( $attachment_id ) ) {
			$this->refresh_attachment_totals( $attachment_id, $metadata );
		}

		return null;
	}

	/**
	 * Puts the kept original back in place of the optimized versions.
	 *
	 * @param bool $keep_backup Keep the copy (re-optimization from
	 *                          the original) or delete it (restore).
	 */
	public function restore_original( int $attachment_id, bool $keep_backup = false ): ?\WP_Error {
		$backup       = $this->get_backup_path( $attachment_id );
		$old_metadata = wp_get_attachment_metadata( $attachment_id );

		if ( '' === $backup || ! is_array( $old_metadata ) || empty( $old_metadata['file'] ) ) {
			return new \WP_Error( 'lumia_no_backup', __( 'No original kept for this media item.', 'lumia-tools' ) );
		}

		$target  = trailingslashit( wp_upload_dir()['basedir'] ) . get_post_meta( $attachment_id, '_lumia_backup_file', true );
		$current = (string) get_attached_file( $attachment_id );

		if ( ! copy( $backup, $target ) ) {
			return new \WP_Error( 'lumia_restore', __( 'Could not copy the original back.', 'lumia-tools' ) );
		}

		if ( wp_normalize_path( $current ) !== wp_normalize_path( $target ) ) {
			$this->update_attachment_database_refs( $attachment_id, $current, $target );
		}

		$metadata = $this->generate_metadata( $attachment_id, $target, $old_metadata );
		if ( null === $metadata ) {
			return new \WP_Error( 'lumia_regenerate', __( 'Thumbnail generation failed.', 'lumia-tools' ) );
		}

		$this->replace_metadata( $attachment_id, $old_metadata, $metadata );

		$this->unrecord_stats( $attachment_id );
		foreach ( self::OPTIMIZATION_META as $key ) {
			delete_post_meta( $attachment_id, $key );
		}

		// The fallback files have become the media item's files again, or have
		// been overwritten: a stale list would end up targeting another item's.
		delete_post_meta( $attachment_id, self::FALLBACK_META );

		if ( ! $keep_backup ) {
			wp_delete_file( $backup );
			delete_post_meta( $attachment_id, '_lumia_backup_file' );
		}

		return null;
	}

	/**
	 * WordPress metadata recomputed from $file, without our processing.
	 *
	 * @param array<string, mixed> $old_metadata
	 * @return array<string, mixed>|null
	 */
	private function generate_metadata( int $attachment_id, string $file, array $old_metadata ): ?array {
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Large image: WordPress derives the thumbnails from the original from before
		// "-scaled" (photo-150x150.jpg), not from the reduced file
		// (photo-scaled-150x150.jpg). We do the same, otherwise every thumbnail
		// changes name and any URL outside the database (cache, CDN, e-mail) breaks.
		$original = empty( $old_metadata['original_image'] ) ? '' : path_join( dirname( $file ), $old_metadata['original_image'] );
		if ( '' !== $original && file_exists( $original ) ) {
			$metadata          = $old_metadata;
			$metadata['file']  = _wp_relative_upload_path( $file );
			$metadata['sizes'] = [];
			$dimensions        = wp_getimagesize( $file );
			if ( $dimensions ) {
				$metadata['width']  = $dimensions[0];
				$metadata['height'] = $dimensions[1];
			}

			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter, applied as in wp_create_image_subsizes().
			$sizes = apply_filters( 'intermediate_image_sizes_advanced', wp_get_registered_image_subsizes(), $metadata, $attachment_id );

			$metadata = _wp_make_subsizes( $sizes, $original, $metadata, $attachment_id );

			// The filter above raised the lifecycle's "generating" flag; without
			// wp_generate_attachment_metadata() nothing would lower it.
			return $this->get_lifecycle()->on_generate_metadata( $metadata, $attachment_id );
		}

		// Without the "big image" threshold: the main file is already the right one,
		// WordPress would otherwise make yet another "-scaled" of it.
		add_filter( 'big_image_size_threshold', '__return_false', 999 );

		$metadata = wp_generate_attachment_metadata( $attachment_id, $file );

		remove_filter( 'big_image_size_threshold', '__return_false', 999 );

		if ( empty( $metadata['file'] ) ) {
			return null;
		}

		return $metadata;
	}

	/**
	 * Saves the new metadata, deletes the files that no longer
	 * appear in it and rewrites the URLs of the renamed files.
	 *
	 * @param array<string, mixed> $old_metadata
	 * @param array<string, mixed> $metadata
	 * @return array<string, mixed>
	 */
	private function replace_metadata( int $attachment_id, array $old_metadata, array $metadata ): array {
		$base_path = trailingslashit( wp_upload_dir()['basedir'] );
		$old_files = $this->metadata_files( $old_metadata );
		$new_files = $this->metadata_files( $metadata );

		// Same key (main file, "medium" size…): old → new.
		$url_pairs = [];
		foreach ( $old_files as $key => $rel ) {
			if ( isset( $new_files[ $key ] ) && $new_files[ $key ] !== $rel ) {
				$url_pairs[ $rel ] = $new_files[ $key ];
			}
		}

		$kept = array_flip( $new_files );
		foreach ( $old_files as $rel ) {
			if ( ! isset( $kept[ $rel ] ) ) {
				wp_delete_file( $base_path . $rel );
			}
		}

		if ( $url_pairs ) {
			( new UrlRewriter() )->rewrite( $url_pairs );
		}

		$metadata = $this->refresh_metadata_filesizes( $metadata, $base_path );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		return $metadata;
	}

	/**
	 * Files of an attachment, relative to the uploads folder, indexed by role
	 * ('' for the main one, the size name otherwise).
	 *
	 * @param array<string, mixed> $metadata
	 * @return array<string, string>
	 */
	private function metadata_files( array $metadata ): array {
		if ( empty( $metadata['file'] ) ) {
			return [];
		}

		$main    = str_replace( '\\', '/', $metadata['file'] );
		$subdir  = dirname( $main );
		$rel_dir = '.' === $subdir ? '' : trailingslashit( $subdir );
		$files   = [ '' => $main ];

		foreach ( $metadata['sizes'] ?? [] as $size => $size_data ) {
			if ( ! empty( $size_data['file'] ) ) {
				$files[ (string) $size ] = $rel_dir . $size_data['file'];
			}
		}

		return $files;
	}

	/**
	 * Recomputes the final weight of an optimized media item after its thumbnails
	 * have been regenerated, and reports the difference in the global statistics.
	 *
	 * @param array<string, mixed> $metadata
	 */
	private function refresh_attachment_totals( int $attachment_id, array $metadata ): void {
		$after = (int) ( $metadata['filesize'] ?? 0 );
		foreach ( $metadata['sizes'] ?? [] as $size_data ) {
			$after += (int) ( $size_data['filesize'] ?? 0 );
		}

		$original  = (int) get_post_meta( $attachment_id, '_lumia_original_bytes', true );
		$old_saved = (int) get_post_meta( $attachment_id, '_lumia_bytes_saved', true );
		$new_saved = max( $original - $after, 0 );

		update_post_meta( $attachment_id, '_lumia_optimized_bytes', $after );
		update_post_meta( $attachment_id, '_lumia_bytes_saved', $new_saved );

		$stats                = $this->get_raw_stats();
		$stats['bytes_saved'] = max( $stats['bytes_saved'] - $old_saved + $new_saved, 0 );
		update_option( $this->get_stats_key(), $stats, false );
	}

	/**
	 * Removes an optimized media item from the global statistics, before
	 * reprocessing or restoring it: otherwise it would be counted twice.
	 */
	private function unrecord_stats( int $attachment_id ): void {
		if ( ! $this->is_already_optimized( $attachment_id ) ) {
			return;
		}

		$stats                   = $this->get_raw_stats();
		$stats['optimized']      = max( $stats['optimized'] - 1, 0 );
		$stats['bytes_saved']    = max( $stats['bytes_saved'] - (int) get_post_meta( $attachment_id, '_lumia_bytes_saved', true ), 0 );
		$stats['original_bytes'] = max( $stats['original_bytes'] - (int) get_post_meta( $attachment_id, '_lumia_original_bytes', true ), 0 );
		update_option( $this->get_stats_key(), $stats, false );
	}

	/* ================================================================
	 * ALT TEXT
	 * ================================================================ */

	/**
	 * Automatically generates the alt text from the file name.
	 */
	public function generate_alt_text( int $attachment_id ): void {
		if ( ! $this->settings['generate_alt'] ) {
			return;
		}

		$alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		if ( ! empty( $alt ) ) {
			return;
		}

		$file = get_attached_file( $attachment_id );
		if ( ! $file ) {
			return;
		}

		$filename = pathinfo( $file, PATHINFO_FILENAME );
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $this->processor->filename_to_alt( $filename ) );
	}

	/* ================================================================
	 * STATS
	 * ================================================================ */

	private function get_stats_key(): string {
		return $this->get_module_option_key() . self::STATS_SUFFIX;
	}

	/**
	 * Global counters as saved (without the server capabilities).
	 *
	 * @return array{optimized: int, bytes_saved: int, original_bytes: int}
	 */
	private function get_raw_stats(): array {
		$stats = (array) get_option( $this->get_stats_key(), [] );

		return [
			'optimized'      => (int) ( $stats['optimized'] ?? 0 ),
			'bytes_saved'    => (int) ( $stats['bytes_saved'] ?? 0 ),
			'original_bytes' => (int) ( $stats['original_bytes'] ?? 0 ),
		];
	}

	private function update_stats( int $bytes_saved, int $original_bytes ): void {
		$stats = $this->get_raw_stats();

		++$stats['optimized'];
		$stats['bytes_saved']    += max( $bytes_saved, 0 );
		$stats['original_bytes'] += max( $original_bytes, 0 );

		update_option( $this->get_stats_key(), $stats, false );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_stats(): array {
		return array_merge(
			$this->get_raw_stats(),
			[
				'capabilities' => $this->processor->get_capabilities(),
			]
		);
	}

	/**
	 * Savings estimate for the bulk run (used by the settings template).
	 *
	 * @return array<string, mixed>
	 */
	public function get_bulk_preview(): array {
		return $this->bulk->get_preview();
	}

	/* ================================================================
	 * ATTACHMENT META
	 * ================================================================ */

	public function is_already_optimized( int $attachment_id ): bool {
		return (bool) get_post_meta( $attachment_id, '_lumia_optimized', true );
	}

	private function mark_attachment_optimized(
		int $attachment_id,
		int $original_bytes,
		int $optimized_bytes,
		string $final_file,
		string $final_mime,
		int $main_original = 0,
		int $main_optimized = 0
	): void {
		$bytes_saved      = max( $original_bytes - $optimized_bytes, 0 );
		$main_bytes_saved = max( $main_original - $main_optimized, 0 );
		$format           = strtolower( pathinfo( $final_file, PATHINFO_EXTENSION ) );

		update_post_meta( $attachment_id, '_lumia_optimized', time() );
		update_post_meta( $attachment_id, '_lumia_original_bytes', $original_bytes );
		update_post_meta( $attachment_id, '_lumia_optimized_bytes', $optimized_bytes );
		update_post_meta( $attachment_id, '_lumia_bytes_saved', $bytes_saved );
		update_post_meta( $attachment_id, '_lumia_main_original_bytes', $main_original );
		update_post_meta( $attachment_id, '_lumia_main_optimized_bytes', $main_optimized );
		update_post_meta( $attachment_id, '_lumia_main_bytes_saved', $main_bytes_saved );
		update_post_meta( $attachment_id, '_lumia_optimized_format', $format );
		update_post_meta( $attachment_id, '_lumia_optimized_mime', $final_mime );
	}

	/* ================================================================
	 * WP METADATA
	 * ================================================================ */

	private function update_attachment_database_refs( int $attachment_id, string $old_file, string $new_file ): void {
		update_attached_file( $attachment_id, $new_file );

		$mime = $this->processor->get_mime_type( $new_file );
		if ( $mime ) {
			wp_update_post(
				[
					'ID'             => $attachment_id,
					'post_mime_type' => $mime,
				]
			);
		}

		// The guid carries the original URL of the file; some tools read it
		// as a URL. wp_update_post() does not rewrite it on an update:
		// we go through $wpdb, then purge the object cache.
		global $wpdb;
		$guid = (string) get_post_field( 'guid', $attachment_id );
		if ( '' !== $guid && false !== strpos( $guid, basename( $old_file ) ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $wpdb->posts, [ 'guid' => str_replace( basename( $old_file ), basename( $new_file ), $guid ) ], [ 'ID' => $attachment_id ] );
			clean_post_cache( $attachment_id );
		}
	}

	/**
	 * @param array<string, mixed> $metadata
	 * @param array<string, array<string, string>> $size_updates
	 * @return array<string, mixed>
	 */
	private function update_metadata_after_conversion( array $metadata, string $old_file, string $new_file, array $size_updates ): array {
		if ( $old_file && $new_file && ! empty( $metadata['file'] ) ) {
			$old_info         = pathinfo( $old_file );
			$new_info         = pathinfo( $new_file );
			$metadata['file'] = str_replace( $old_info['basename'], $new_info['basename'], $metadata['file'] );
		}

		if ( ! empty( $metadata['sizes'] ) && ! empty( $size_updates ) ) {
			foreach ( $size_updates as $size => $update ) {
				if ( empty( $metadata['sizes'][ $size ] ) ) {
					continue;
				}
				$metadata['sizes'][ $size ]['file']      = basename( $update['file'] );
				$metadata['sizes'][ $size ]['mime-type'] = $update['mime'] ?? $metadata['sizes'][ $size ]['mime-type'];
			}
		}

		return $metadata;
	}

	/**
	 * @param array<string, mixed> $metadata
	 * @return array<string, mixed>
	 */
	private function refresh_metadata_filesizes( array $metadata, string $base_path ): array {
		if ( ! empty( $metadata['file'] ) ) {
			$original_path = $base_path . $metadata['file'];
			if ( file_exists( $original_path ) ) {
				$metadata['filesize'] = (int) filesize( $original_path );
			}
		}

		if ( empty( $metadata['sizes'] ) || empty( $metadata['file'] ) ) {
			return $metadata;
		}

		$subdir          = dirname( $metadata['file'] );
		$sizes_base_path = trailingslashit( $base_path . $subdir );

		foreach ( $metadata['sizes'] as $size => $size_data ) {
			if ( empty( $size_data['file'] ) ) {
				continue;
			}
			$size_path = $sizes_base_path . $size_data['file'];
			if ( file_exists( $size_path ) ) {
				$metadata['sizes'][ $size ]['filesize'] = (int) filesize( $size_path );
			}
		}

		return $metadata;
	}

	/* ================================================================
	 * BULK DELEGATION (cron hooks + AJAX)
	 * ================================================================ */

	public function ajax_bulk_scan(): void {
		$this->bulk->ajax_scan();
	}

	public function ajax_bulk_start(): void {
		$this->bulk->ajax_start( self::BATCH_SIZE );
	}

	public function ajax_bulk_status(): void {
		$this->bulk->ajax_status( self::BATCH_SIZE );
	}

	public function run_cron_batch(): void {
		$this->bulk->run_batch( self::BATCH_SIZE );
	}

	/* ================================================================
	 * COMPATIBILITY: server capabilities (used in the settings template)
	 * ================================================================ */

	/**
	 * @return array<string, bool|string>
	 */
	public function get_capabilities(): array {
		return $this->processor->get_capabilities();
	}
}
