<?php
namespace Lumia\Tools\Modules\ImageOptimizer;

defined( 'ABSPATH' ) || exit;

use Lumia\Tools\Core\AbstractModule;
use Lumia\Tools\Admin\Admin;

/**
 * Image Optimizer module — orchestrator.
 *
 * Delegates the AVIF siblings' lifecycle to FileLifecycle, their encoding to the background
 * queue (QueueRunner, AvifEncoder), the delivery self-test to DeliveryProbe, the bulk screen to
 * BulkProcessor, the media library UI to MediaLibrary and the legacy media migration to
 * MigrationCommand. ImageProcessor only detects the server capabilities.
 */
class Module extends AbstractModule {

	/** Global counters of the former in-place pipeline: only deleted on uninstall. */
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

	/**
	 * Folder (under uploads) where the former pipeline kept the untouched originals, suffixed
	 * with a token: see get_backup_dir(). Read by the legacy migration (a backup copy is the
	 * best source for a fallback) and by Core\Migration\FromSkmt (folder rename).
	 */
	public const BACKUP_DIR           = 'lumia-originals';
	private const BACKUP_TOKEN_SUFFIX = '_backup_token';

	/**
	 * Same folder under the former name of the plugin (Studio Kyne Mini Tools).
	 * The migration renames it; when that rename failed, get_backup_dir() keeps
	 * reading it.
	 */
	public const LEGACY_BACKUP_DIR = 'skmt-originals';

	/**
	 * Meta of the former pipeline: source files left next to the converted ones
	 * (keep_original), paths relative to the uploads folder. Absent from the WordPress
	 * metadata, they would not leave with a media item not migrated yet without this list.
	 */
	private const FALLBACK_META = '_lumia_fallback_files';

	/** Metas the former pipeline wrote on a converted media item (removed by the migration). */
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
	private ?QueueRunner $queue       = null;

	/**
	 * Active module settings (in-memory cache).
	 *
	 * @var array<string, mixed>
	 */
	private array $settings = [];

	/* ================================================================
	 * INITIALIZATION
	 * ================================================================ */

	/**
	 * Loads the settings, creates the sub-objects and registers the hooks.
	 */
	public function init(): void {
		$this->settings = $this->get_settings();

		// Sub-objects
		$this->processor = new ImageProcessor();

		$this->bulk = new BulkProcessor(
			$this->get_module_option_key() . self::BULK_STATE_SUFFIX,
			$this->get_queue(),
			$this->get_lifecycle(),
			fn( int $user_id ) => $this->notify_bulk_complete( $user_id )
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

		// The daily check also walks the fingerprints (a batch now, the next ones every 30 s
		// until the walk is over): a JPEG/PNG replaced by FTP or another tool loses its stale
		// AVIF within a day.
		add_action( DeliveryProbe::CRON_HOOK, [ $this->get_lifecycle(), 'run_reconcile' ], 20 );

		// AVIF encoding in the background: after the response, by loopback, or by cron.
		$this->get_queue()->register();

		// Automatic alt text
		add_action( 'add_attachment', [ $this, 'generate_alt_text' ] );

		// A media item not migrated yet (former pipeline): its kept original and fallback
		// files follow it when it is permanently deleted.
		add_action( 'delete_attachment', [ $this, 'delete_kept_files' ] );

		// Bulk screen: scan, start, stop, status.
		$this->bulk->register();

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
	 * The background AVIF queue (created on first use, after init() has built the processor).
	 */
	public function get_queue(): QueueRunner {
		if ( null === $this->queue ) {
			$this->queue = new QueueRunner( $this, $this->get_lifecycle(), $this->processor );
		}

		return $this->queue;
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
			'bulk' => $this->bulk->snapshot(),
			'i18n' => [
				'bulkScanning'  => __( 'Scanning…', 'lumia-tools' ),
				'bulkRunning'   => __( 'Optimizing…', 'lumia-tools' ),
				'bulkStopping'  => __( 'Stopping…', 'lumia-tools' ),
				/* translators: 1: number of images processed, 2: total number of images that went through the queue. */
				'bulkProgress'  => __( '%1$s of %2$s images processed', 'lumia-tools' ),
				'bulkEmpty'     => __( 'No image has been queued yet.', 'lumia-tools' ),
				'bulkComplete'  => __( 'Optimization complete.', 'lumia-tools' ),
				/* translators: %s: number of images. */
				'bulkQueued'    => __( '%s images queued.', 'lumia-tools' ),
				'bulkNothing'   => __( 'No image to queue.', 'lumia-tools' ),
				/* translators: %s: number of images. */
				'bulkStopped'   => __( 'Stopped: %s waiting images taken out of the queue.', 'lumia-tools' ),
				/* translators: %s: number of images. */
				'bulkRequeued'  => __( '%s images whose file changed were queued again.', 'lumia-tools' ),
				'bulkUnserved'  => __( 'The AVIF versions are not served by this server (see the Delivery tab): nothing would be generated.', 'lumia-tools' ),
				'networkError'  => __( 'Network error', 'lumia-tools' ),
				'mediaRunning'  => __( 'Processing…', 'lumia-tools' ),
				'mediaError'    => __( 'Error', 'lumia-tools' ),
				'mediaCopied'   => __( 'Original URL copied.', 'lumia-tools' ),
				'mediaCopyFail' => __( 'Copy failed: copy the URL by hand.', 'lumia-tools' ),
				'delivery'      => [
					'checking'   => __( 'Checking…', 'lumia-tools' ),
					'retesting'  => __( 'Testing…', 'lumia-tools' ),
					'unverified' => __( 'Could not be run', 'lumia-tools' ),
					'copied'     => __( 'Rule copied.', 'lumia-tools' ),
					'copyFailed' => __( 'Copy failed: select the rule and copy it by hand.', 'lumia-tools' ),
				],
			],
		];
	}

	/**
	 * Adds a persistent notice for the user who started the bulk run, so they are informed
	 * even if the queue emptied while they had left the page (the worker has no current user).
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

		// The deactivation purged every state. The self-test going back to "served" fires
		// `lumia_image_optimizer_enqueued` with ID 0, but this request booted the plugin with
		// the module off: no queue listens to it (init() never ran on this instance). The
		// library is queued again here, and a drain started in the web runtime. When the test
		// fails now, the next one that passes (daily check, Retest) runs where the queue listens.
		if ( DeliveryProbe::is_serving() ) {
			$queue = new QueueRunner( $this, $this->get_lifecycle(), new ImageProcessor() );
			if ( $queue->enqueue_all_eligible( 'reconcile' ) > 0 ) {
				$queue->trigger();
			}
		}
	}

	public function on_deactivate(): void {
		// Remove pending crons: the queue's recurring drain, the former bulk cron, the reconcile.
		delete_option( $this->get_module_option_key() . self::BULK_STATE_SUFFIX );
		wp_unschedule_hook( QueueRunner::CRON_HOOK );
		wp_unschedule_hook( QueueRunner::LEGACY_CRON_HOOK );
		wp_unschedule_hook( FileLifecycle::RECONCILE_HOOK );

		// uploads/.htaccess block, daily check and verdict.
		DeliveryProbe::reset();

		// The server would keep serving the AVIF siblings with nobody to keep them up to
		// date: they go (the bulk regenerates them after a reactivation).
		$this->get_lifecycle()->purge_all();
	}

	/* ================================================================
	 * STATIC: DEACTIVATION / UNINSTALL
	 * ================================================================ */

	/**
	 * Deletes every generated AVIF sibling and resets the state metas, without a booted module:
	 * plugin deactivation (Core\Deactivator) and uninstall call it whatever the module state.
	 * An excluded item keeps its status. The legacy files a migrated item kept on disk
	 * (`_lumia_avif_legacy`) are not siblings: they stay.
	 *
	 * @return int Siblings deleted.
	 */
	public static function purge_generated_siblings(): int {
		return ( new FileLifecycle( new self( 'image_optimizer' ) ) )->purge_all();
	}

	/**
	 * Files to remove on uninstall (uninstall.php, before the options and metas are deleted:
	 * the purge reads the state metas): the uploads/.htaccess block, every generated sibling,
	 * and the probe folder uploads/lumia-tools/. Left on disk: the legacy files of migrated
	 * items and the lumia-originals-* folder, the client's images.
	 */
	public static function uninstall_files(): void {
		DeliveryProbe::reset();
		self::purge_generated_siblings();

		$dir = DeliveryProbe::dir();
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$names = scandir( $dir );
		foreach ( is_array( $names ) ? $names : [] as $name ) {
			$path = $dir . '/' . $name;
			if ( '.' !== $name && '..' !== $name && ( is_file( $path ) || is_link( $path ) ) ) {
				wp_delete_file( $path );
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- the plugin's own folder, emptied above.
	}

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
			'cron'    => [ FileLifecycle::RECONCILE_HOOK, DeliveryProbe::CRON_HOOK, QueueRunner::CRON_HOOK, QueueRunner::LEGACY_CRON_HOOK ],
			// The files in lumia-originals/ and the legacy files of migrated items stay on
			// disk (uninstall_files()): they are the client's photos, not plugin data.
			'meta'    => array_merge(
				AvifState::STATE_KEYS,
				[ AvifState::LEGACY, MigrationCommand::JOURNAL ],
				self::OPTIMIZATION_META,
				[ '_lumia_backup_file', self::FALLBACK_META ]
			),
		];
	}

	/* ================================================================
	 * FORMER PIPELINE: KEPT ORIGINALS AND FALLBACK FILES
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
	 * BULK
	 * ================================================================ */

	/**
	 * Counts per status, for the Bulk tab (settings template).
	 *
	 * @return array{counts: array<string, int>, total: int, handled: int, active: bool, running: bool, serving: bool, untouched: int|null}
	 */
	public function get_bulk_snapshot(): array {
		return $this->bulk->snapshot();
	}

	/* ================================================================
	 * SERVER CAPABILITIES (upload conversion, migration command)
	 * ================================================================ */

	/**
	 * @return array<string, bool|string>
	 */
	public function get_capabilities(): array {
		return $this->processor->get_capabilities();
	}
}
