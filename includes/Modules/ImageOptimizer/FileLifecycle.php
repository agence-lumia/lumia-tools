<?php
namespace Lumia\Tools\Modules\ImageOptimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Life of the `file.ext.avif` siblings next to the JPEG/PNG files WordPress produces
 * (spec sections 3, 9.4, 9.5, 9.7, 9.8, 9.13).
 *
 * - Queueing: at the end of `wp_generate_attachment_metadata`, and on
 *   `wp_update_attachment_metadata` outside sub-size generation. The old and new file lists
 *   are compared: the siblings of vanished files are deleted, only new files and files whose
 *   fingerprint changed are queued again. Never a stale AVIF next to a rewritten source.
 * - Deletion: any file WordPress deletes takes its sibling with it (`wp_delete_file`), and a
 *   deleted media item's names go to a registry (tombstones) so that a re-upload under the
 *   same name gets another one: an AVIF cached by a browser for the old URL would otherwise
 *   be shown for the new image.
 * - Names: a name is taken when a sibling of its family exists on disk, or when it is in
 *   the registry (`pre_wp_unique_filename_file_list`, virtual names added to the list).
 * - WordPress settings the fallbacks depend on: big image threshold, progressive JPEG,
 *   browser-side media processing off.
 * - AVIF / WebP uploads converted to a JPEG/PNG fallback before WordPress processes them.
 */
final class FileLifecycle {

	public const TOMBSTONES_OPTION = 'lumia_module_image_optimizer_tombstones';
	public const RECONCILE_CURSOR  = 'lumia_module_image_optimizer_reconcile_cursor';
	public const RECONCILE_HOOK    = 'lumia_image_optimizer_reconcile';

	/** Registry entries older than one year are dropped. */
	private const TOMBSTONE_TTL = 31536000;

	/** Registry cap: the oldest entries go first. */
	private const TOMBSTONE_MAX = 20000;

	/** Source files that get an AVIF sibling. */
	private const SOURCE_PATTERN = '/\.(?:jpe?g|png)$/i';

	/**
	 * A file of a family: base, optional edit suffix, optional size / scaled / rotated
	 * suffix, extension.
	 */
	private const FAMILY_PATTERN = '/^(.+?)(?:-e\d+)?(?:-\d+x\d+|-scaled|-rotated)?\.(jpe?g|png)$/i';

	private const MODERN_TYPES = [ 'image/avif', 'image/webp' ];

	private Module $module;

	/**
	 * Attachments whose sub-sizes are being generated: the intermediate
	 * wp_update_attachment_metadata() calls are ignored until the end of the generation.
	 *
	 * @var array<int, true>
	 */
	private array $generating = [];

	/**
	 * Registry entries waiting to be written, once per request.
	 *
	 * @var array<string, int>
	 */
	private array $pending_tombstones = [];

	/** register() ran: suspend() / resume() only act on hooks that were added. */
	private bool $registered = false;

	/** The file and state hooks are off (suspend()). */
	private bool $suspended = false;

	public function __construct( Module $module ) {
		$this->module = $module;
	}

	public function register(): void {
		$this->registered = true;
		$this->add_suspendable_hooks();
		add_filter( 'pre_wp_unique_filename_file_list', [ $this, 'filter_unique_file_list' ], 10, 2 );
		add_filter( 'image_save_progressive', [ $this, 'filter_progressive' ], 10, 2 );
		add_filter( 'wp_client_side_media_processing_enabled', '__return_false' );
		add_action( self::RECONCILE_HOOK, [ $this, 'run_reconcile' ] );
	}

	/**
	 * Takes the hooks that act on a media item's files or state off: queueing, sibling
	 * deletion, name registry, upload conversion (spec 9.9, the legacy migration rewrites
	 * files and metadata itself). Kept: the name uniqueness check (the migration resolves its
	 * collisions with it), progressive JPEG and the browser-side processing switch. No effect
	 * when the hooks were never registered (inactive module).
	 */
	public function suspend(): void {
		if ( ! $this->registered || $this->suspended ) {
			return;
		}

		remove_filter( 'big_image_size_threshold', [ $this, 'filter_big_image_threshold' ], 10 );
		remove_filter( 'intermediate_image_sizes_advanced', [ $this, 'mark_generating' ], 10 );
		remove_filter( 'wp_generate_attachment_metadata', [ $this, 'on_generate_metadata' ], 99 );
		remove_filter( 'wp_update_attachment_metadata', [ $this, 'on_update_metadata' ], 99 );
		remove_filter( 'wp_delete_file', [ $this, 'filter_delete_file' ] );
		remove_action( 'delete_attachment', [ $this, 'on_delete_attachment' ] );
		remove_filter( 'wp_handle_upload', [ $this, 'convert_modern_upload' ] );

		$this->suspended = true;
	}

	/**
	 * Puts back the hooks taken off by suspend().
	 */
	public function resume(): void {
		if ( ! $this->suspended ) {
			return;
		}

		$this->suspended = false;
		$this->add_suspendable_hooks();
	}

	private function add_suspendable_hooks(): void {
		add_filter( 'big_image_size_threshold', [ $this, 'filter_big_image_threshold' ], 10, 4 );
		add_filter( 'intermediate_image_sizes_advanced', [ $this, 'mark_generating' ], 10, 3 );
		add_filter( 'wp_generate_attachment_metadata', [ $this, 'on_generate_metadata' ], 99, 2 );
		add_filter( 'wp_update_attachment_metadata', [ $this, 'on_update_metadata' ], 99, 2 );
		add_filter( 'wp_delete_file', [ $this, 'filter_delete_file' ] );
		add_action( 'delete_attachment', [ $this, 'on_delete_attachment' ] );
		add_filter( 'wp_handle_upload', [ $this, 'convert_modern_upload' ] );
	}

	/* ================================================================
	 * PATHS
	 * ================================================================ */

	public static function sibling( string $path ): string {
		return $path . '.avif';
	}

	/**
	 * Absolute paths of the files that get a sibling: main file and every size, JPEG/PNG
	 * only, never `original_image` (it is not served).
	 *
	 * @return string[]
	 */
	public function source_files( int $id ): array {
		$metadata = wp_get_attachment_metadata( $id );

		return array_values( is_array( $metadata ) ? $this->metadata_sources( $metadata ) : [] );
	}

	/**
	 * Deletes every sibling of a media item (current files and files recorded in its state).
	 */
	public function delete_siblings( int $id ): void {
		$this->remove_siblings( $id );
	}

	/**
	 * True when the file name (without extension, size or scaled suffix) ends with one of the
	 * excluded suffixes, possibly followed by WordPress's uniqueness number (`-noopt-1`).
	 */
	public function is_excluded_by_name( string $file ): bool {
		$name = pathinfo( wp_basename( $file ), PATHINFO_FILENAME );
		$name = (string) preg_replace( '/-(?:\d+x\d+|scaled|rotated)$/i', '', $name );

		foreach ( (array) $this->setting( 'exclude_suffixes' ) as $suffix ) {
			$suffix = (string) $suffix;
			if ( '' !== $suffix && preg_match( '/' . preg_quote( $suffix, '/' ) . '(?:-\d+)?$/i', $name ) ) {
				return true;
			}
		}

		return false;
	}

	/* ================================================================
	 * QUEUEING
	 * ================================================================ */

	/**
	 * Filter big_image_size_threshold: the "maximum dimension" setting (0 = no threshold).
	 * Called at the start of wp_create_image_subsizes(), before its first metadata save:
	 * the generation flag is raised here.
	 *
	 * @param int|false $threshold
	 * @param mixed     $imagesize
	 * @param mixed     $file
	 * @param mixed     $attachment_id
	 * @return int|false
	 */
	public function filter_big_image_threshold( $threshold, $imagesize = null, $file = '', $attachment_id = 0 ) {
		if ( (int) $attachment_id > 0 ) {
			$this->generating[ (int) $attachment_id ] = true;
		}

		$max = (int) $this->setting( 'max_dimension' );

		return $max > 0 ? $max : false;
	}

	/**
	 * Filter intermediate_image_sizes_advanced: sub-sizes about to be generated.
	 *
	 * @param mixed $sizes
	 * @param mixed $metadata
	 * @param mixed $attachment_id
	 * @return mixed
	 */
	public function mark_generating( $sizes, $metadata = [], $attachment_id = 0 ) {
		if ( (int) $attachment_id > 0 ) {
			$this->generating[ (int) $attachment_id ] = true;
		}

		return $sizes;
	}

	/**
	 * Filter wp_generate_attachment_metadata (late): the generation is over.
	 *
	 * @param mixed $metadata
	 * @param mixed $attachment_id
	 * @return mixed
	 */
	public function on_generate_metadata( $metadata, $attachment_id = 0 ) {
		$id = (int) $attachment_id;
		unset( $this->generating[ $id ] );

		if ( $id > 0 && is_array( $metadata ) ) {
			$metadata = $this->strip_served_jpegs( $metadata );
			$this->sync( $id, $metadata, true, 'upload' );
		}

		return $metadata;
	}

	/**
	 * With `strip_exif`, removes the EXIF / XMP APP1 segments of the served JPEG files (main
	 * file and sizes, never `original_image`) losslessly, as soon as WordPress has made them:
	 * whether or not an AVIF is ever encoded (delivery not proven, excluded name, automatic
	 * processing off), the GPS block must not stay public. Done before sync(), so that a
	 * fingerprint recorded later is the stripped file's; the `filesize` entries of the metadata
	 * about to be saved are corrected. The queue does it again (a no-op on a stripped file).
	 *
	 * @param array<string, mixed> $metadata
	 * @return array<string, mixed>
	 */
	private function strip_served_jpegs( array $metadata ): array {
		if ( ! $this->setting( 'strip_exif' ) ) {
			return $metadata;
		}

		$main = $this->metadata_sources( $metadata );
		$main = (string) reset( $main );

		foreach ( $this->metadata_sources( $metadata ) as $path ) {
			if ( ! is_file( $path ) || 'image/jpeg' !== wp_get_image_mime( $path ) || JpegMetadata::strip_app1( $path ) <= 0 ) {
				continue;
			}

			clearstatcache( true, $path );
			$bytes = (int) filesize( $path );
			if ( $path === $main && isset( $metadata['filesize'] ) ) {
				$metadata['filesize'] = $bytes;
			}
			foreach ( (array) ( $metadata['sizes'] ?? [] ) as $size => $data ) {
				if ( is_array( $data ) && isset( $data['filesize'], $data['file'] ) && wp_basename( (string) $data['file'] ) === wp_basename( $path ) ) {
					$metadata['sizes'][ $size ]['filesize'] = $bytes;
				}
			}
		}

		return $metadata;
	}

	/**
	 * Filter wp_update_attachment_metadata (late): ignored during sub-size generation.
	 *
	 * @param mixed $metadata
	 * @param mixed $attachment_id
	 * @return mixed
	 */
	public function on_update_metadata( $metadata, $attachment_id = 0 ) {
		$id = (int) $attachment_id;

		if ( $id > 0 && is_array( $metadata ) && ! isset( $this->generating[ $id ] ) ) {
			$this->sync( $id, $metadata, false, 'upload' );
		}

		return $metadata;
	}

	/**
	 * Aligns the state of a media item with its current files.
	 *
	 * @param array<string, mixed> $metadata  The metadata about to be saved.
	 * @param bool                 $generated The files were (re)generated by WordPress.
	 * @return bool True when the media item was queued.
	 */
	private function sync( int $id, array $metadata, bool $generated, string $origin ): bool {
		$state = AvifState::get( $id );
		$old   = $state['sizes'];
		$files = $this->metadata_sources( $metadata );

		// The siblings of files that left the metadata go.
		foreach ( array_diff_key( $old, $files ) as $rel => $entry ) {
			$this->delete_sibling( AvifState::abs( $rel ) );
		}

		if ( ! $files ) {
			if ( $old ) {
				AvifState::replace_sizes( $id, [] );
			}
			if ( $generated && '' === $state['status'] ) {
				$this->skip_unsupported( $id );
			}
			return false;
		}

		$main = reset( $files );

		if ( $this->is_animated_png( $main ) ) {
			$this->remove_siblings( $id );
			if ( AvifState::SKIPPED !== $state['status'] ) {
				AvifState::set_status( $id, AvifState::SKIPPED, __( 'Animated image: served as uploaded.', 'lumia-tools' ) );
			}
			return false;
		}

		$unencoded = [
			'bytes'      => null,
			'mtime'      => null,
			'avif_bytes' => null,
		];

		$excluded = AvifState::EXCLUDED === $state['status']
			|| $this->is_excluded_by_name( (string) ( $metadata['original_image'] ?? $metadata['file'] ?? '' ) );

		if ( $excluded ) {
			foreach ( $files as $path ) {
				$this->delete_sibling( $path );
			}
			$target = array_fill_keys( array_keys( $files ), $unencoded );
			if ( $target !== $old ) {
				AvifState::replace_sizes( $id, $target );
			}
			if ( AvifState::EXCLUDED !== $state['status'] ) {
				AvifState::set_status( $id, AvifState::EXCLUDED );
			}
			return false;
		}

		// Never processed and automatic processing off: no state is written (the bulk
		// picks the item up later), and a sibling nobody recorded is never valid.
		if ( '' === $state['status'] && ! $this->setting( 'optimize_on_upload' ) ) {
			foreach ( $files as $path ) {
				$this->delete_sibling( $path );
			}
			if ( $old ) {
				AvifState::replace_sizes( $id, [] );
			}
			return false;
		}

		$sizes   = [];
		$changed = false; // A file appeared, or an encoded file / its sibling changed.
		foreach ( $files as $rel => $path ) {
			$entry = $old[ $rel ] ?? null;

			if ( null !== $entry && null !== $entry['bytes'] && AvifState::is_fresh( $id, $rel ) ) {
				$sizes[ $rel ] = $entry;
				continue;
			}

			if ( null === $entry || null !== $entry['bytes'] ) {
				$changed = true;
			}
			if ( $this->delete_sibling( $path ) ) {
				$changed = true; // Stale or unknown sibling.
			}
			$sizes[ $rel ] = $unencoded;
		}

		if ( $sizes !== $old ) {
			AvifState::replace_sizes( $id, $sizes );
		}

		if ( ! in_array( $unencoded, $sizes, true ) ) {
			return false; // Everything is fresh (at most, sizes were dropped).
		}

		// Same work already waiting, or nothing new for a finished / failed item: an
		// unchanged metadata save must not queue again. A regeneration rewrote the files,
		// so it does (except for an item that is still waiting anyway).
		if ( ! $changed && ! ( $generated && AvifState::PENDING !== $state['status'] ) ) {
			return false;
		}

		$this->enqueue( $id, $origin );

		return true;
	}

	private function enqueue( int $id, string $origin ): void {
		AvifState::enqueue( $id, $origin );

		/**
		 * A media item was queued for AVIF generation.
		 *
		 * @param int $id Attachment ID.
		 */
		do_action( 'lumia_image_optimizer_enqueued', $id );
	}

	/**
	 * An uploaded AVIF / WebP left as is (animated, conversion off or impossible): marked
	 * `skipped` with the reason, for the media library. Legacy media (converted by the
	 * former pipeline) are left to the migration.
	 */
	private function skip_unsupported( int $id ): void {
		$mime = (string) get_post_mime_type( $id );
		if ( ! in_array( $mime, self::MODERN_TYPES, true ) || get_post_meta( $id, '_lumia_optimized', true ) ) {
			return;
		}

		$file = (string) get_attached_file( $id );

		if ( ! $this->setting( 'convert_modern_uploads' ) ) {
			$reason = __( 'Kept as uploaded: the conversion of AVIF and WebP uploads is turned off. Incompatible with some email clients.', 'lumia-tools' );
		} elseif ( ! $this->can_convert( $mime ) ) {
			$reason = __( 'Kept as uploaded: this server cannot convert AVIF or WebP images. Incompatible with some email clients.', 'lumia-tools' );
		} elseif ( $this->frame_count( $file ) > 1 ) {
			$reason = __( 'Animated image kept as uploaded. Incompatible with some email clients.', 'lumia-tools' );
		} else {
			$reason = __( 'Kept as uploaded: the conversion failed. Incompatible with some email clients.', 'lumia-tools' );
		}

		AvifState::set_status( $id, AvifState::SKIPPED, $reason );
	}

	/* ================================================================
	 * DELETION AND NAME REGISTRY
	 * ================================================================ */

	/**
	 * Filter wp_delete_file: a JPEG/PNG deleted by WordPress takes its sibling with it.
	 *
	 * @param mixed $file
	 * @return mixed
	 */
	public function filter_delete_file( $file ) {
		if ( is_string( $file ) && '' !== $file ) {
			$this->delete_sibling( $file );
		}

		return $file;
	}

	/**
	 * Action delete_attachment: siblings deleted (even those of source files already gone),
	 * and every file name of the media item recorded in the registry.
	 *
	 * @param mixed $attachment_id
	 */
	public function on_delete_attachment( $attachment_id ): void {
		$id = (int) $attachment_id;
		if ( $id <= 0 ) {
			return;
		}

		$this->remove_siblings( $id );
		$this->delete_legacy_files( $id );

		$rels     = [];
		$attached = (string) get_post_meta( $id, '_wp_attached_file', true );
		if ( '' !== $attached ) {
			$rels[] = AvifState::rel( $attached );
		}

		$metadata = wp_get_attachment_metadata( $id );
		if ( is_array( $metadata ) && ! empty( $metadata['file'] ) ) {
			$main   = AvifState::rel( (string) $metadata['file'] );
			$dir    = $this->rel_dir( $main );
			$rels[] = $main;
			foreach ( (array) ( $metadata['sizes'] ?? [] ) as $size ) {
				if ( is_array( $size ) && ! empty( $size['file'] ) ) {
					$rels[] = $dir . wp_basename( (string) $size['file'] );
				}
			}
			if ( ! empty( $metadata['original_image'] ) ) {
				$rels[] = $dir . wp_basename( (string) $metadata['original_image'] );
			}
		}

		$now = time();
		foreach ( array_filter( $rels ) as $rel ) {
			$this->pending_tombstones[ $rel ] = $now;
		}

		if ( $this->pending_tombstones && ! has_action( 'shutdown', [ $this, 'flush_tombstones' ] ) ) {
			add_action( 'shutdown', [ $this, 'flush_tombstones' ] );
		}
	}

	/**
	 * The legacy files a migrated media item kept on disk (former `.avif` / `.webp` URLs,
	 * spec 9.3) leave with it. A path that has become another media item's main file is left
	 * alone.
	 */
	private function delete_legacy_files( int $id ): void {
		$legacy = get_post_meta( $id, AvifState::LEGACY, true );
		if ( ! is_array( $legacy ) || ! $legacy ) {
			return;
		}

		global $wpdb;

		foreach ( $legacy as $rel ) {
			$rel = AvifState::rel( (string) $rel );
			if ( '' === $rel || 0 !== validate_file( $rel ) ) {
				continue;
			}

			$owner = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off check before a deletion.
				$wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s AND post_id <> %d LIMIT 1", $rel, $id )
			);
			if ( null !== $owner ) {
				continue;
			}

			$path = AvifState::abs( $rel );
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * Writes the registry entries collected during the request (one write per request),
	 * dropping entries older than a year and keeping at most the newest 20 000.
	 */
	public function flush_tombstones(): void {
		if ( ! $this->pending_tombstones ) {
			return;
		}

		$list                     = array_merge( $this->load_tombstones(), $this->pending_tombstones );
		$this->pending_tombstones = [];

		$limit = time() - self::TOMBSTONE_TTL;
		$list  = array_filter(
			$list,
			static function ( $timestamp ) use ( $limit ): bool {
				return (int) $timestamp >= $limit;
			}
		);

		if ( count( $list ) > self::TOMBSTONE_MAX ) {
			arsort( $list );
			$list = array_slice( $list, 0, self::TOMBSTONE_MAX, true );
		}

		update_option( self::TOMBSTONES_OPTION, $list, false );
	}

	/**
	 * Filter pre_wp_unique_filename_file_list: adds, for every family whose name is taken
	 * (a sibling on disk, or a registry entry in this folder), a virtual `<base>-scaled.<ext>`
	 * that WordPress's own check (_wp_check_existing_file_names) sees as a sub-size of
	 * `<base>.<ext>`: the name then gets WordPress's usual numeric suffix.
	 *
	 * @param mixed $files
	 * @param mixed $dir
	 * @return mixed
	 */
	public function filter_unique_file_list( $files, $dir = '' ) {
		$dir = (string) $dir;

		if ( null === $files ) {
			$files = is_dir( $dir ) ? scandir( $dir ) : [];
			$files = is_array( $files ) ? $files : [];
		}
		if ( ! is_array( $files ) ) {
			return $files;
		}

		$families = [];
		foreach ( $files as $name ) {
			if ( preg_match( '/^(.+)\.avif$/i', (string) $name, $match ) ) {
				$this->add_family( $families, $match[1] );
			}
		}

		$rel_dir = $this->rel_dir( trailingslashit( AvifState::rel( $dir ) ) . 'x' );
		foreach ( array_keys( $this->load_tombstones() + $this->pending_tombstones ) as $rel ) {
			$rel = (string) $rel;
			if ( $this->rel_dir( $rel ) === $rel_dir ) {
				$this->add_family( $families, wp_basename( $rel ) );
			}
		}

		foreach ( $families as $virtual ) {
			$files[] = $virtual;
		}

		return $files;
	}

	/**
	 * @param array<string, string> $families
	 */
	private function add_family( array &$families, string $name ): void {
		if ( preg_match( self::FAMILY_PATTERN, $name, $match ) ) {
			$families[ strtolower( $match[1] . '.' . $match[2] ) ] = $match[1] . '-scaled.' . $match[2];
		}
	}

	/**
	 * @return array<string, int>
	 */
	private function load_tombstones(): array {
		$list = get_option( self::TOMBSTONES_OPTION, [] );

		return is_array( $list ) ? $list : [];
	}

	/* ================================================================
	 * CORE IMAGE SETTINGS
	 * ================================================================ */

	/**
	 * Filter image_save_progressive: progressive JPEG only (an interlaced PNG is heavier).
	 *
	 * @param mixed $progressive
	 * @param mixed $mime_type
	 * @return mixed
	 */
	public function filter_progressive( $progressive, $mime_type = '' ) {
		return 'image/jpeg' === $mime_type ? true : $progressive;
	}

	/* ================================================================
	 * AVIF / WEBP UPLOADS (spec 9.13)
	 * ================================================================ */

	/**
	 * Filter wp_handle_upload: a still AVIF / WebP becomes a PNG (alpha used, or at most
	 * 256 colors) or a progressive JPEG q90, before WordPress makes its sizes. Animated
	 * images, and anything that cannot be converted here, are left as uploaded.
	 *
	 * @param mixed $upload
	 * @return mixed
	 */
	public function convert_modern_upload( $upload ) {
		if ( ! is_array( $upload ) || ! empty( $upload['error'] ) || empty( $upload['file'] ) || empty( $upload['url'] ) ) {
			return $upload;
		}

		$type = (string) ( $upload['type'] ?? '' );
		if ( ! in_array( $type, self::MODERN_TYPES, true ) || ! $this->setting( 'convert_modern_uploads' ) || ! $this->can_convert( $type ) ) {
			return $upload;
		}

		$converted = $this->convert_modern_file( (string) $upload['file'] );
		if ( null === $converted ) {
			return $upload;
		}

		$upload['url']  = trailingslashit( dirname( (string) $upload['url'] ) ) . wp_basename( $converted['file'] );
		$upload['file'] = $converted['file'];
		$upload['type'] = $converted['type'];

		return $upload;
	}

	/**
	 * Imagick in this process can read the uploaded format and write JPEG and PNG. The format
	 * list cannot tell: the CLI image's Imagick lists formats it has no codec for (spec 9.1).
	 * `can_encode_here` is a real decode of JPEG, PNG and AVIF in this process; WebP also
	 * needs a working WebP codec (a real WebP encode, `imagick_webp`).
	 */
	private function can_convert( string $type ): bool {
		$caps = $this->module->get_capabilities();

		if ( empty( $caps['can_encode_here'] ) ) {
			return false;
		}

		return 'image/avif' === $type || ! empty( $caps['imagick_webp'] );
	}

	/**
	 * Converts the file in place (same folder, same base name, unique name), deletes the
	 * uploaded file. Null when the image is animated or anything fails (file untouched).
	 *
	 * @return array{file: string, type: string}|null
	 */
	private function convert_modern_file( string $path ): ?array {
		$tmp   = '';
		$image = null;

		try {
			$image = new \Imagick( $path );
			if ( $image->getNumberImages() > 1 ) {
				return null;
			}

			$png = self::uses_alpha( $image ) || $image->getImageColors() <= 256;

			// EXIF / XMP / IPTC go with strip_exif; the ICC profile always stays.
			if ( $this->setting( 'strip_exif' ) ) {
				foreach ( array_keys( $image->getImageProfiles( '*', true ) ) as $profile ) {
					if ( 'icc' !== strtolower( (string) $profile ) ) {
						$image->removeImageProfile( (string) $profile );
					}
				}
			}

			$image->setImageDepth( 8 );

			if ( $png ) {
				$format = 'png';
				$type   = 'image/png';
				$image->setImageFormat( 'png' );
			} else {
				$format = 'jpeg';
				$type   = 'image/jpeg';
				$image->setImageFormat( 'jpeg' );
				$image->setCompressionQuality( 90 );
				$image->setImageCompressionQuality( 90 );
				$image->setInterlaceScheme( \Imagick::INTERLACE_PLANE );
			}

			$dir  = dirname( $path );
			$name = wp_unique_filename( $dir, pathinfo( $path, PATHINFO_FILENAME ) . ( $png ? '.png' : '.jpg' ) );
			$tmp  = $dir . '/.' . $name . '.' . wp_generate_password( 8, false ) . '.tmp';
			$dest = $dir . '/' . $name;

			// Explicit format: the temporary name has no image extension.
			$image->writeImage( $format . ':' . $tmp );

			if ( ! rename( $tmp, $dest ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- same-folder atomic rename of a local file.
				return null;
			}
			$tmp = '';

			$perms = fileperms( $path );
			if ( false !== $perms ) {
				chmod( $dest, $perms & 0777 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- same permissions as the uploaded file.
			}
			wp_delete_file( $path );

			return [
				'file' => $dest,
				'type' => $type,
			];
		} catch ( \Throwable $e ) {
			return null;
		} finally {
			if ( $image instanceof \Imagick ) {
				$image->clear();
			}
			if ( '' !== $tmp && file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
		}
	}

	/**
	 * The image has an alpha channel that is actually used (not fully opaque).
	 */
	public static function uses_alpha( \Imagick $image ): bool {
		if ( ! $image->getImageAlphaChannel() ) {
			return false;
		}

		$range = $image->getImageChannelRange( \Imagick::CHANNEL_ALPHA );
		$max   = \Imagick::getQuantumRange()['quantumRangeLong'];

		return (float) $range['minima'] < (float) $max;
	}

	private function frame_count( string $path ): int {
		if ( '' === $path || ! class_exists( 'Imagick' ) || ! is_file( $path ) ) {
			return 0;
		}

		try {
			$image = new \Imagick();
			$image->pingImage( $path );
			$count = $image->getNumberImages();
			$image->clear();
			return $count;
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	/**
	 * An APNG declares its animation (acTL chunk) before the first IDAT.
	 */
	private function is_animated_png( string $path ): bool {
		if ( ! preg_match( '/\.png$/i', $path ) || ! is_file( $path ) ) {
			return false;
		}

		$head = (string) file_get_contents( $path, false, null, 0, 262144 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file, header only.
		$actl = strpos( $head, 'acTL' );
		$idat = strpos( $head, 'IDAT' );

		return false !== $actl && ( false === $idat || $actl < $idat );
	}

	/* ================================================================
	 * RECONCILE AND PURGE
	 * ================================================================ */

	/**
	 * Checks the fingerprints of the next `$limit` processed media items (cursor kept in an
	 * option): a source changed behind WordPress (FTP, file manager, `unlink()`) has its
	 * sibling deleted and is queued again.
	 *
	 * @return int Media items queued again.
	 */
	public function reconcile( int $limit ): int {
		global $wpdb;

		$limit  = max( 1, $limit );
		$cursor = (int) get_option( self::RECONCILE_CURSOR, 0 );
		$ids    = array_map(
			'intval',
			(array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- batch walk over the state metas.
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value IN ( %s, %s, %s ) AND post_id > %d ORDER BY post_id ASC LIMIT %d",
					AvifState::STATUS,
					AvifState::DONE,
					AvifState::PARTIAL,
					AvifState::SKIPPED,
					$cursor,
					$limit
				)
			)
		);

		$queued = 0;
		foreach ( $ids as $id ) {
			$metadata = wp_get_attachment_metadata( $id );
			if ( is_array( $metadata ) && $this->sync( $id, $metadata, false, 'reconcile' ) ) {
				++$queued;
			}
		}

		if ( count( $ids ) < $limit ) {
			delete_option( self::RECONCILE_CURSOR );
		} else {
			update_option( self::RECONCILE_CURSOR, (int) end( $ids ), false );
		}

		return $queued;
	}

	/**
	 * Cron handler: one reconcile batch, then the next one in 30 s until the walk is over.
	 */
	public function run_reconcile(): void {
		$this->reconcile( 200 );

		if ( get_option( self::RECONCILE_CURSOR ) && ! wp_next_scheduled( self::RECONCILE_HOOK ) ) {
			wp_schedule_single_event( time() + 30, self::RECONCILE_HOOK );
		}
	}

	/**
	 * Deactivation: deletes every generated sibling and resets the state metas, so that
	 * the server stops serving AVIF nobody keeps up to date any more. The legacy list
	 * (migration) and a manual exclusion stay: neither has a generated file.
	 *
	 * Runs to the end whatever the library size: the module is already recorded as off
	 * when this runs (Modules::deactivate()), so a purge cut by max_execution_time would
	 * leave siblings served and never updated again. Per item, only file checks remain:
	 * the metadata is read in batches, and the metas go in one DELETE.
	 *
	 * @return int Siblings deleted.
	 */
	public function purge_all(): int {
		global $wpdb;

		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 );
		}

		$keys     = AvifState::STATE_KEYS;
		$excluded = array_map(
			'intval',
			(array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off read before the bulk delete.
				$wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", AvifState::STATUS, AvifState::EXCLUDED )
			)
		);

		$deleted  = 0;
		$last     = 0;
		$affected = [];

		do {
			// One query per batch: the item, its detail meta, its WordPress metadata.
			$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- batch walk over the state metas.
				$wpdb->prepare(
					"SELECT ids.post_id, d.meta_value AS detail, a.meta_value AS attachment
					FROM ( SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ( %s, %s ) AND post_id > %d ORDER BY post_id ASC LIMIT 1000 ) ids
					LEFT JOIN {$wpdb->postmeta} d ON d.post_id = ids.post_id AND d.meta_key = %s
					LEFT JOIN {$wpdb->postmeta} a ON a.post_id = ids.post_id AND a.meta_key = '_wp_attachment_metadata'
					ORDER BY ids.post_id ASC",
					AvifState::META,
					AvifState::STATUS,
					$last,
					AvifState::META
				)
			);

			$batch = [];
			foreach ( $rows as $row ) {
				$id = (int) $row->post_id;

				$paths  = [];
				$detail = maybe_unserialize( (string) $row->detail );
				if ( is_array( $detail ) && is_array( $detail['sizes'] ?? null ) ) {
					foreach ( array_keys( $detail['sizes'] ) as $rel ) {
						$paths[] = AvifState::abs( (string) $rel );
					}
				}
				$metadata = maybe_unserialize( (string) $row->attachment );
				if ( is_array( $metadata ) ) {
					$paths = array_merge( $paths, array_values( $this->metadata_sources( $metadata ) ) );
				}

				foreach ( array_unique( $paths ) as $path ) {
					if ( $this->delete_sibling( $path ) ) {
						++$deleted;
					}
				}

				$batch[ $id ] = true;
				$last         = max( $last, $id );
			}
			$affected += $batch;
			$size      = count( $batch );
		} while ( 1000 <= $size );

		// The metas, in one statement (the legacy list is not in STATE_KEYS).
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk reset, the cache is cleared below.
			$wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ( " . implode( ', ', array_fill( 0, count( $keys ), '%s' ) ) . ' )', $keys )
		);

		// A manual exclusion survives (status only).
		foreach ( array_chunk( $excluded, 500 ) as $chunk ) {
			$values = [];
			foreach ( $chunk as $id ) {
				$values[] = $wpdb->prepare( '( %d, %s, %s )', $id, AvifState::STATUS, AvifState::EXCLUDED );
			}
			$wpdb->query( "INSERT INTO {$wpdb->postmeta} ( post_id, meta_key, meta_value ) VALUES " . implode( ', ', $values ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- each tuple prepared above.
		}

		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( 'post_meta' );
		} else {
			foreach ( array_keys( $affected ) as $id ) {
				wp_cache_delete( $id, 'post_meta' );
			}
		}

		delete_option( self::RECONCILE_CURSOR );

		return $deleted;
	}

	/* ================================================================
	 * HELPERS
	 * ================================================================ */

	/**
	 * Source files of a metadata array, relative path => absolute path, main file first.
	 *
	 * @param array<string, mixed> $metadata
	 * @return array<string, string>
	 */
	private function metadata_sources( array $metadata ): array {
		if ( empty( $metadata['file'] ) || ! is_string( $metadata['file'] ) ) {
			return [];
		}

		$main = AvifState::rel( $metadata['file'] );
		if ( '' === $main ) {
			return [];
		}

		$dir  = $this->rel_dir( $main );
		$rels = [ $main ];
		foreach ( (array) ( $metadata['sizes'] ?? [] ) as $size ) {
			if ( is_array( $size ) && ! empty( $size['file'] ) && is_string( $size['file'] ) ) {
				$rels[] = $dir . wp_basename( $size['file'] );
			}
		}

		$files = [];
		foreach ( $rels as $rel ) {
			if ( preg_match( self::SOURCE_PATTERN, $rel ) && 0 === validate_file( $rel ) ) {
				$files[ $rel ] = AvifState::abs( $rel );
			}
		}

		return $files;
	}

	/**
	 * Folder part of a relative path, with a trailing slash ('' at the uploads root).
	 */
	private function rel_dir( string $rel ): string {
		$dir = dirname( $rel );

		return ( '.' === $dir || '' === $dir || '/' === $dir ) ? '' : trailingslashit( $dir );
	}

	/**
	 * Deletes the sibling of one source file. True when there was one.
	 */
	private function delete_sibling( string $path ): bool {
		if ( ! preg_match( self::SOURCE_PATTERN, $path ) ) {
			return false;
		}

		$sibling = self::sibling( $path );
		if ( ! is_file( $sibling ) ) {
			return false;
		}

		wp_delete_file( $sibling );

		return ! file_exists( $sibling );
	}

	/**
	 * @return int Siblings deleted.
	 */
	private function remove_siblings( int $id ): int {
		$paths = $this->source_files( $id );
		foreach ( array_keys( AvifState::get( $id )['sizes'] ) as $rel ) {
			$paths[] = AvifState::abs( $rel );
		}

		$deleted = 0;
		foreach ( array_unique( $paths ) as $path ) {
			if ( $this->delete_sibling( $path ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * @return mixed
	 */
	private function setting( string $key ) {
		return $this->module->get_settings()[ $key ] ?? null;
	}
}
