<?php
namespace Lumia\Tools\Modules\ImageOptimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Legacy media migration of the Image Optimizer.
 *
 * Design, steps and pitfalls: docs/modules/image-optimizer.md, "Migration of legacy media".
 */
final class MigrationCommand {

	/** Per-media journal: step, plan, before / after state. */
	public const JOURNAL = '_lumia_migration';

	/**
	 * URL pairs (old => new path, relative to uploads) of the media items migrated since the
	 * last pass over Bricks' CSS files: kept until that pass, so that a killed run loses none.
	 */
	public const PAIRS_OPTION = 'lumia_module_image_optimizer_migration_pairs';

	/** Fired after each step of a media item: (int $id, string $step). */
	public const STEP_ACTION = 'lumia_image_optimizer_migration_step';

	public const STEP_PLANNED = 'planned';
	public const STEP_FILES   = 'files_written';
	public const STEP_DB      = 'db_written';

	private const CASE_ORIGINAL = 'original';
	private const CASE_DECODE   = 'decode';

	/** Metas of the former pipeline, removed once a media item is migrated. */
	private const LEGACY_METAS = [
		'_lumia_optimized',
		'_lumia_original_bytes',
		'_lumia_optimized_bytes',
		'_lumia_bytes_saved',
		'_lumia_main_original_bytes',
		'_lumia_main_optimized_bytes',
		'_lumia_main_bytes_saved',
		'_lumia_optimized_format',
		'_lumia_optimized_mime',
		'_lumia_backup_file',
		'_lumia_fallback_files',
	];

	/** Media items per URL rewrite pass. */
	private const BATCH = 20;

	/** A legacy AVIF is kept as a sibling only if it weighs at most this share of its fallback. */
	private const AVIF_MAX_RATIO = 0.9;

	/** Flat colours: the lossless PNG weighs at most this many times the JPEG q90 (see is_flat()). */
	private const FLAT_PNG_RATIO = 2.0;

	/** Absolute cap of the flat test, on the full-size image (see is_flat()). */
	private const FLAT_PNG_CAP = 3.0;

	/** Files of the former pipeline. */
	private const LEGACY_PATTERN = '/\.(?:avif|webp)$/i';

	private const REFUSAL = 'This command needs Imagick with AVIF, JPEG and PNG support in this PHP process. On the Dokploy template, run it in the wordpress container: docker exec -u www-data <project>-wordpress-1 php /tmp/wp-cli.phar lumia images migrate';

	private Module $module;

	private bool $dry_run = false;

	/** AVIF delivery is proven: legacy AVIF may become siblings. */
	private bool $serving = false;

	/** MySQL lock held by this run ('' when none). */
	private string $lock = '';

	/**
	 * Files written for the current item, as they are written (paths relative to uploads):
	 * what a failure in the middle of a step has to remove.
	 *
	 * @var string[]
	 */
	private array $written = [];

	/**
	 * @var array{processed: int, skipped: int, failed: int, rows: int, css: int, before: int, after: int, avif: int}
	 */
	private array $stats = [
		'processed' => 0,
		'skipped'   => 0,
		'failed'    => 0,
		'rows'      => 0,
		'css'       => 0,
		'before'    => 0,
		'after'     => 0,
		'avif'      => 0,
	];

	public function __construct( Module $module ) {
		$this->module = $module;
	}

	/**
	 * Migrates the media items converted to AVIF / WebP by the former Image Optimizer.
	 *
	 * Writes a JPEG/PNG fallback for each legacy file (or regenerates the sizes from the
	 * original when it is still on disk), keeps the legacy AVIF as the fallback's sibling,
	 * rewrites the URLs in the database and in Bricks' CSS files. The legacy files stay in
	 * place. Resumable: run it again after an interruption.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would be done, write nothing.
	 *
	 * [--ids=<ids>]
	 * : Only these attachment IDs (comma-separated).
	 *
	 * [--limit=<n>]
	 * : Process at most this many media items in this run.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lumia images migrate --dry-run
	 *     wp lumia images migrate --limit=20
	 *     wp lumia images migrate
	 *
	 * @param string[]              $args       Positional arguments (none).
	 * @param array<string, string> $assoc_args Options.
	 */
	public function migrate( array $args, array $assoc_args ): void {
		if ( empty( $this->module->get_capabilities()['can_encode_here'] ) ) {
			\WP_CLI::error( self::REFUSAL );
			return;
		}

		$this->dry_run = ! empty( $assoc_args['dry-run'] );
		$limit         = isset( $assoc_args['limit'] ) ? max( 0, (int) $assoc_args['limit'] ) : 0;
		$only          = isset( $assoc_args['ids'] ) ? array_filter( array_map( 'intval', explode( ',', (string) $assoc_args['ids'] ) ) ) : [];

		if ( ! $this->dry_run && ! $this->acquire_lock() ) {
			\WP_CLI::error( 'Another migration is running on this site.' );
			return;
		}

		$this->serving = DeliveryProbe::is_serving();
		$lifecycle     = $this->module->get_lifecycle();
		$lifecycle->suspend();

		// The sizes are made by Imagick (GD loses the ICC profile). A closure, not a public
		// method: WP-CLI would list it as a subcommand.
		$imagick_only = static fn(): array => [ 'WP_Image_Editor_Imagick' ];
		add_filter( 'wp_image_editors', $imagick_only );

		try {
			$this->run( $only, $limit );
		} finally {
			remove_filter( 'wp_image_editors', $imagick_only );
			$lifecycle->resume();
			$this->release_lock();
		}

		$this->report();
	}

	/* ================================================================
	 * RUN
	 * ================================================================ */

	/**
	 * @param int[] $only
	 */
	private function run( array $only, int $limit ): void {
		$ids = $this->legacy_ids();
		if ( $only ) {
			$ids = array_values( array_intersect( $ids, $only ) );
		}

		\WP_CLI::log( sprintf( '%d legacy media item(s) to migrate%s.', count( $ids ), $this->dry_run ? ' (dry run: nothing is written)' : '' ) );
		if ( ! $this->serving ) {
			\WP_CLI::warning( 'AVIF delivery is not confirmed on this site (Image Optimizer, Delivery tab): fallbacks are written, but no legacy AVIF becomes a sibling. The queue makes the siblings once delivery works.' );
		}

		if ( $limit > 0 ) {
			$ids = array_slice( $ids, 0, $limit );
		}

		foreach ( array_chunk( $ids, self::BATCH ) as $chunk ) {
			$ready = [];
			$pairs = [];
			foreach ( $chunk as $id ) {
				if ( $this->dry_run ) {
					$this->dry_run_item( (int) $id );
					continue;
				}
				$journal = $this->prepare( (int) $id );
				if ( null !== $journal ) {
					$ready[ (int) $id ] = $journal;
					$pairs             += (array) $journal['pairs'];
				}
			}

			if ( $this->dry_run || ! $ready ) {
				continue;
			}

			// One pass over posts, postmeta and options for the whole batch.
			$this->stats['rows'] += ( new UrlRewriter() )->rewrite( $pairs );

			foreach ( $ready as $id => $journal ) {
				$this->finalize( $id, $journal );
			}
		}

		if ( ! $this->dry_run ) {
			$this->finish_site();
		}
	}

	/**
	 * Attachments of the former pipeline: converted (`_lumia_optimized_format` avif / webp),
	 * optimized with a legacy file in their metadata, or with a journal (interrupted).
	 *
	 * @return int[]
	 */
	private function legacy_ids(): array {
		global $wpdb;

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off selection of a CLI command.
			$wpdb->prepare(
				"SELECT DISTINCT o.post_id FROM {$wpdb->postmeta} o
				INNER JOIN {$wpdb->posts} p ON p.ID = o.post_id AND p.post_type = 'attachment'
				LEFT JOIN {$wpdb->postmeta} a ON a.post_id = o.post_id AND a.meta_key = '_wp_attachment_metadata'
				WHERE ( o.meta_key = '_lumia_optimized_format' AND o.meta_value IN ( 'avif', 'webp' ) )
				OR ( o.meta_key = '_lumia_optimized' AND ( a.meta_value LIKE %s OR a.meta_value LIKE %s ) )
				OR o.meta_key = %s
				ORDER BY o.post_id ASC",
				'%' . $wpdb->esc_like( '.avif";' ) . '%',
				'%' . $wpdb->esc_like( '.webp";' ) . '%',
				self::JOURNAL
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Plans (or resumes), writes the files, then the metas of one media item. Returns the
	 * journal at the `db_written` step (only the URL rewrite and the end remain), or null
	 * when the item is skipped or failed (left intact).
	 *
	 * @return array<string, mixed>|null
	 */
	private function prepare( int $id ): ?array {
		// Per item: a failure must never reach the files of the previous one.
		$this->written = [];
		$journal       = $this->journal( $id );

		if ( null === $journal ) {
			$plan = $this->plan( $id );
			if ( is_string( $plan ) ) {
				$this->fail( $id, $plan );
				return null;
			}
			if ( null === $plan ) {
				++$this->stats['skipped'];
				return null;
			}
			$journal = $plan;
			if ( ! $this->save_journal( $id, $journal ) ) {
				$this->fail( $id, 'could not write the journal' );
				return null;
			}
			$this->step( $id, self::STEP_PLANNED );
		} else {
			\WP_CLI::log( sprintf( '#%d: resuming after step "%s".', $id, $journal['step'] ) );
		}

		if ( self::STEP_DB === $journal['step'] ) {
			return $journal;
		}

		if ( self::STEP_PLANNED === $journal['step'] || ! $this->files_present( $journal ) ) {
			$resumed = self::STEP_FILES === $journal['step'];
			try {
				$this->check_targets( $id, $journal );
				$journal = self::CASE_ORIGINAL === $journal['case']
					? $this->write_from_original( $id, $journal )
					: $this->write_decoded( $id, $journal );
			} catch ( \Throwable $e ) {
				// A run killed during its database step may have left the metas half written.
				if ( ! $resumed || $this->restore( $id, $journal ) ) {
					$this->discard( $id, $journal );
				}
				$this->fail( $id, $e->getMessage() );
				return null;
			}

			$journal['step'] = self::STEP_FILES;
			if ( ! $this->save_journal( $id, $journal ) ) {
				$this->discard( $id, $journal );
				$this->fail( $id, 'could not write the journal' );
				return null;
			}
			$this->step( $id, self::STEP_FILES );
		}

		try {
			$this->write_metas( $id, $journal );
		} catch ( \Throwable $e ) {
			if ( $this->restore( $id, $journal ) ) {
				$this->discard( $id, $journal );
			}
			$this->fail( $id, $e->getMessage() );
			return null;
		}

		$journal['step'] = self::STEP_DB;
		$this->save_journal( $id, $journal );
		$this->step( $id, self::STEP_DB );

		return $journal;
	}

	/**
	 * Dry run: the plan, logged, nothing written.
	 */
	private function dry_run_item( int $id ): void {
		$journal = $this->journal( $id );
		if ( null !== $journal ) {
			\WP_CLI::log( sprintf( '#%d: interrupted migration, would resume after step "%s".', $id, $journal['step'] ) );
			++$this->stats['processed'];
			return;
		}

		$plan = $this->plan( $id );
		if ( is_string( $plan ) ) {
			$this->fail( $id, $plan );
			return;
		}
		if ( null === $plan ) {
			++$this->stats['skipped'];
			return;
		}

		++$this->stats['processed'];
		$this->stats['before'] += (int) $plan['bytes_before'];

		if ( self::CASE_ORIGINAL === $plan['case'] ) {
			\WP_CLI::log( sprintf( '#%d %s: sizes regenerated from the original %s.', $id, $plan['main'], $plan['source'] ) );
		} else {
			$names = array_map( 'wp_basename', array_values( $plan['files'] ) );
			\WP_CLI::log( sprintf( '#%d %s: %s fallback(s): %s.', $id, $plan['main'], 'png' === $plan['format'] ? 'PNG' : 'JPEG', implode( ', ', $names ) ) );
		}
	}

	/**
	 * After the URL rewrite: AVIF state, pairs for the CSS pass, legacy metas and journal
	 * removed (in that order: the legacy metas gone means migrated).
	 *
	 * @param array<string, mixed> $journal
	 */
	private function finalize( int $id, array $journal ): void {
		$avif = (array) $journal['avif'];
		AvifState::replace_sizes( $id, (array) $avif['sizes'] );
		if ( AvifState::DONE === $avif['status'] ) {
			update_post_meta( $id, AvifState::GEN, AvifState::gen( $id ) + 1 );
			AvifState::set_status( $id, AvifState::DONE );
		} else {
			AvifState::enqueue( $id, 'reconcile' );

			/** This action is documented in includes/Modules/ImageOptimizer/FileLifecycle.php */
			do_action( 'lumia_image_optimizer_enqueued', $id );
		}

		$pending = get_option( self::PAIRS_OPTION, [] );
		$pending = is_array( $pending ) ? $pending : [];
		update_option( self::PAIRS_OPTION, array_merge( $pending, (array) $journal['pairs'] ), false );

		foreach ( self::LEGACY_METAS as $key ) {
			delete_post_meta( $id, $key );
		}
		delete_post_meta( $id, self::JOURNAL );

		++$this->stats['processed'];
		$this->stats['before'] += (int) $journal['bytes_before'];
		$this->stats['after']  += (int) $journal['bytes_after'];
		$this->stats['avif']   += (int) $journal['bytes_avif'];

		\WP_CLI::log( sprintf( '#%d %s -> %s (%s).', $id, $journal['main'], $journal['after']['attached_file'], AvifState::DONE === $avif['status'] ? 'legacy AVIF kept as sibling' : 'AVIF queued' ) );
	}

	/**
	 * End of the site: URLs in Bricks' CSS files, then the page caches.
	 */
	private function finish_site(): void {
		$pairs = get_option( self::PAIRS_OPTION, [] );
		if ( ! is_array( $pairs ) || ! $pairs ) {
			return;
		}

		$result              = $this->rewrite_bricks_css( $pairs );
		$this->stats['css'] += $result['rewritten'];
		if ( 0 === $result['failed'] ) {
			delete_option( self::PAIRS_OPTION );
		} else {
			\WP_CLI::warning( 'Some Bricks CSS files could not be rewritten (their old URLs still work): the next run tries again.' );
		}

		do_action( 'cache_enabler_clear_complete_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Cache Enabler's own action.
		wp_cache_flush();
	}

	/**
	 * Exact replacement of the old paths in `uploads/bricks/css/*.css`, each file written to a
	 * temporary file of the same folder, then renamed.
	 *
	 * @param array<string, string> $pairs
	 * @return array{rewritten: int, failed: int}
	 */
	private function rewrite_bricks_css( array $pairs ): array {
		$result = [
			'rewritten' => 0,
			'failed'    => 0,
		];
		$dir    = $this->uploads() . 'bricks/css';
		if ( ! is_dir( $dir ) ) {
			return $result;
		}

		$rewriter = new UrlRewriter();
		foreach ( (array) glob( $dir . '/*.css' ) as $file ) {
			$file = (string) $file;
			$css  = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
			$new  = (string) $rewriter->replace_in_value( $css, $pairs );
			if ( $new === $css ) {
				continue;
			}

			$tmp = dirname( $file ) . '/.' . wp_basename( $file ) . '.' . wp_generate_password( 8, false ) . '.tmp';
			if ( false === file_put_contents( $tmp, $new ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temporary file renamed below.
				\WP_CLI::warning( sprintf( 'Could not rewrite %s.', $file ) );
				++$result['failed'];
				continue;
			}
			$perms = fileperms( $file );
			if ( false !== $perms ) {
				chmod( $tmp, $perms & 0777 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- same permissions as the file it replaces.
			}
			if ( ! rename( $tmp, $file ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- same-folder atomic rename.
				wp_delete_file( $tmp );
				\WP_CLI::warning( sprintf( 'Could not rewrite %s.', $file ) );
				++$result['failed'];
				continue;
			}
			++$result['rewritten'];
		}

		return $result;
	}

	private function report(): void {
		$s = $this->stats;

		\WP_CLI::log( '' );
		\WP_CLI::log( $this->dry_run ? 'Dry run, nothing was written:' : 'Migration report:' );
		\WP_CLI::log( sprintf( '  %s: %d', $this->dry_run ? 'Would process' : 'Processed', $s['processed'] ) );
		\WP_CLI::log( sprintf( '  Skipped: %d', $s['skipped'] ) );
		\WP_CLI::log( sprintf( '  Failed: %d', $s['failed'] ) );
		if ( ! $this->dry_run ) {
			\WP_CLI::log( sprintf( '  Database rows rewritten: %d', $s['rows'] ) );
			\WP_CLI::log( sprintf( '  Bricks CSS files rewritten: %d', $s['css'] ) );
		}
		\WP_CLI::log( sprintf( '  Bytes before (legacy files): %d (%s)', $s['before'], size_format( $s['before'], 1 ) ) );
		if ( ! $this->dry_run ) {
			\WP_CLI::log( sprintf( '  Bytes after (JPEG/PNG served without AVIF): %d (%s)', $s['after'], size_format( $s['after'], 1 ) ) );
			\WP_CLI::log( sprintf( '  Legacy AVIF kept as siblings: %d (%s)', $s['avif'], size_format( $s['avif'], 1 ) ) );
		}

		if ( $s['failed'] > 0 ) {
			\WP_CLI::error( sprintf( '%d media item(s) failed and were left as they were.', $s['failed'] ) );
		}
	}

	/* ================================================================
	 * PLAN (read-only)
	 * ================================================================ */

	/**
	 * Decides what happens to a media item, without writing anything. Null: nothing to
	 * migrate; a string: why it cannot be migrated.
	 *
	 * @return array<string, mixed>|string|null
	 */
	private function plan( int $id ) {
		$metadata = wp_get_attachment_metadata( $id );
		if ( ! is_array( $metadata ) || empty( $metadata['file'] ) ) {
			return 'no usable attachment metadata';
		}

		$main = AvifState::rel( (string) $metadata['file'] );
		$dir  = $this->rel_dir( $main );
		$own  = $this->own_files( $metadata );

		// The served files (main and sizes): `original_image` is never served, it stays as is.
		$legacy = array_values( array_filter( $this->own_files( $metadata, false ), fn( string $rel ): bool => (bool) preg_match( self::LEGACY_PATTERN, $rel ) ) );
		if ( ! $legacy ) {
			return null;
		}

		foreach ( $legacy as $rel ) {
			if ( 0 !== validate_file( $rel ) ) {
				return sprintf( 'invalid path %s', $rel );
			}
		}
		if ( preg_grep( '/\.webp$/i', $legacy ) && ! \Imagick::queryFormats( 'WEBP' ) ) {
			return 'Imagick cannot read WebP here';
		}

		$bytes = 0;
		foreach ( $legacy as $rel ) {
			$bytes += is_file( $this->abs( $rel ) ) ? (int) filesize( $this->abs( $rel ) ) : 0;
		}

		$extra = $this->extra_legacy( $id );

		$journal = [
			'v'            => 1,
			'id'           => $id,
			'step'         => self::STEP_PLANNED,
			'started'      => time(),
			'main'         => $main,
			'dir'          => $dir,
			'own'          => array_map( 'wp_basename', $own ),
			'before'       => $this->encode_state( $this->current_state( $id ) ),
			'extra_legacy' => $extra,
			'bytes_before' => $bytes,
			'created'      => [],
			'pairs'        => [],
		];

		$source = $this->original_source( $id, $metadata, $dir );

		if ( null !== $source ) {
			$name  = pathinfo( wp_basename( $source['source'] ), PATHINFO_FILENAME );
			$ext   = pathinfo( $source['source'], PATHINFO_EXTENSION );
			$taken = $this->family_files( $this->abs( $dir ), $name, $ext, $journal['own'] );
			if ( $taken ) {
				return sprintf( 'name collision with %s: rename or delete it, then run again', $this->describe( $dir, $taken, $id ) );
			}

			return array_merge(
				$journal,
				[
					'case'      => self::CASE_ORIGINAL,
					'source'    => $source['source'],
					'copy_from' => $source['copy_from'],
				]
			);
		}

		if ( ! is_file( $this->abs( $main ) ) ) {
			return sprintf( 'file not found: %s', $main );
		}
		$format = $this->fallback_format( $main );
		if ( null === $format ) {
			return sprintf( 'cannot decode %s', $main );
		}

		return array_merge(
			$journal,
			[
				'case'   => self::CASE_DECODE,
				'format' => $format,
				'files'  => $this->fallback_names( $main, $legacy, $format, $journal['own'] ),
			]
		);
	}

	/**
	 * The original to regenerate from: `original_image` on disk (JPEG/PNG), else the former
	 * backup copy, copied next to the media item under a free name. Null: none.
	 *
	 * @param array<string, mixed> $metadata
	 * @return array{source: string, copy_from: string}|null
	 */
	private function original_source( int $id, array $metadata, string $dir ) {
		if ( ! empty( $metadata['original_image'] ) ) {
			$rel = $dir . wp_basename( (string) $metadata['original_image'] );
			if ( is_file( $this->abs( $rel ) ) && preg_match( '/\.(?:jpe?g|png)$/i', $rel ) ) {
				return [
					'source'    => $rel,
					'copy_from' => '',
				];
			}
		}

		$backup = $this->backup_file( $id );
		if ( '' === $backup || ! preg_match( '/\.(?:jpe?g|png)$/i', $backup ) ) {
			return null;
		}

		$own  = array_map( 'wp_basename', $this->own_files( $metadata ) );
		$name = $this->unique_name( $this->abs( $dir ), wp_basename( $backup ), $own );

		return [
			'source'    => $dir . $name,
			'copy_from' => $backup,
		];
	}

	/**
	 * Path (relative to uploads) of the former pipeline's backup copy, '' when none.
	 */
	private function backup_file( int $id ): string {
		if ( '' === (string) get_post_meta( $id, '_lumia_backup_file', true ) || '' === (string) get_option( 'lumia_module_image_optimizer_backup_token', '' ) ) {
			return ''; // No token: get_backup_path() would create one (a write, even in a dry run).
		}

		$path = $this->module->get_backup_path( $id );

		return '' === $path ? '' : AvifState::rel( $path );
	}

	/**
	 * PNG when the main image uses its alpha channel, has at most 256 colors, or is made of
	 * flat colours; else JPEG. A main file that is no longer legacy (the former pipeline kept
	 * the JPEG/PNG when the AVIF was not smaller) gives its own format. Null when it cannot be
	 * decoded.
	 *
	 * The exact color count alone misses the logos: the legacy AVIF is lossy (q50), and its
	 * artifacts give a 29-color logo 1 801 colors (measured on the bench). Hence the flat
	 * colour test, see is_flat().
	 */
	private function fallback_format( string $main ): ?string {
		if ( ! preg_match( self::LEGACY_PATTERN, $main ) ) {
			return preg_match( '/\.png$/i', $main ) ? 'png' : 'jpeg';
		}

		$image = null;
		try {
			$image = new \Imagick( $this->abs( $main ) );
			if ( $image->getNumberImages() > 1 ) {
				return null;
			}

			return FileLifecycle::uses_alpha( $image ) || $image->getImageColors() <= 256 || $this->is_flat( $image ) ? 'png' : 'jpeg';
		} catch ( \Throwable $e ) {
			return null;
		} finally {
			if ( $image instanceof \Imagick ) {
				$image->clear();
			}
		}
	}

	/**
	 * Flat colours: on a copy reduced to 512 px at most, the lossless PNG weighs at most
	 * FLAT_PNG_RATIO times the JPEG q90. Measured on the legacy AVIF of the bench: flat logo
	 * 1.45 to 1.76, smooth illustration 2.75 to 3.16, photos 4.3 to 5.4. A wrong PNG would
	 * cost every client without AVIF several times the bytes of a JPEG; a wrong JPEG on a logo
	 * only adds slight ringing to an image that already went through a lossy AVIF.
	 *
	 * The reduced copy only stands for the real file: a large image that passes it must also
	 * pass an absolute cap on the full-size image (PNG at most FLAT_PNG_CAP times the JPEG q90),
	 * or it gets a JPEG. A product photo on a white background is the case in point: the white
	 * dominates the small copy, the fallback is the full-size file.
	 */
	private function is_flat( \Imagick $image ): bool {
		[ $png, $jpeg ] = $this->png_jpeg_bytes( $image, 512 );
		if ( $jpeg <= 0 || $png > self::FLAT_PNG_RATIO * $jpeg ) {
			return false;
		}

		if ( $image->getImageWidth() <= 512 && $image->getImageHeight() <= 512 ) {
			return true; // Measured on the real size already.
		}

		return $this->within_png_cap( $image );
	}

	/**
	 * The full-size lossless PNG weighs at most FLAT_PNG_CAP times the full-size JPEG q90.
	 */
	private function within_png_cap( \Imagick $image ): bool {
		[ $png, $jpeg ] = $this->png_jpeg_bytes( $image, 0 );

		return $jpeg > 0 && $png <= self::FLAT_PNG_CAP * $jpeg;
	}

	/**
	 * Bytes of the image as a lossless PNG (compression level 9) and as a JPEG q90, on a copy
	 * reduced to `$max` px at most (0: full size).
	 *
	 * @return array{0: int, 1: int}
	 */
	private function png_jpeg_bytes( \Imagick $image, int $max ): array {
		$copy = clone $image;
		try {
			if ( $max > 0 && ( $copy->getImageWidth() > $max || $copy->getImageHeight() > $max ) ) {
				$copy->thumbnailImage( $max, $max, true );
			}
			$copy->setImageDepth( 8 );

			$png = clone $copy;
			$png->setImageFormat( 'png' );
			$png->setOption( 'png:compression-level', '9' );
			$png_bytes = strlen( (string) $png->getImageBlob() );
			$png->clear();

			$copy->setImageFormat( 'jpeg' );
			$copy->setImageCompressionQuality( 90 );

			return [ $png_bytes, strlen( (string) $copy->getImageBlob() ) ];
		} finally {
			$copy->clear();
		}
	}

	/**
	 * Fallback path of each legacy file, resolved for the whole family at once: a family
	 * whose base name is taken (a file, a sibling, a recently deleted name) moves to the next
	 * free base, its sizes with it. The upload's uniqueness function decides (spec 9.4).
	 *
	 * @param string[] $legacy Legacy files of the item (relative to uploads).
	 * @param string[] $own    Basenames of the item's own files (not a collision).
	 * @return array<string, string> Legacy path => fallback path.
	 */
	private function fallback_names( string $main, array $legacy, string $format, array $own ): array {
		$ext = 'png' === $format ? 'png' : 'jpg';
		$dir = $this->rel_dir( $main );

		$files = [];
		if ( preg_match( self::LEGACY_PATTERN, $main ) ) {
			$name     = pathinfo( $main, PATHINFO_FILENAME );
			$base     = (string) preg_replace( '/-(?:scaled|rotated)$/i', '', $name );
			$new_base = pathinfo( $this->unique_name( $this->abs( $dir ), $base . '.' . $ext, $own ), PATHINFO_FILENAME );

			foreach ( $legacy as $rel ) {
				$file          = pathinfo( $rel, PATHINFO_FILENAME );
				$suffix        = str_starts_with( $file, $base ) ? substr( $file, strlen( $base ) ) : '-' . $file;
				$files[ $rel ] = $dir . $new_base . $suffix . '.' . $ext;
			}

			return $files;
		}

		// The main file stays (it is not legacy): each legacy size gets its own free name.
		foreach ( $legacy as $rel ) {
			$files[ $rel ] = $dir . $this->unique_name( $this->abs( $dir ), pathinfo( $rel, PATHINFO_FILENAME ) . '.' . $ext, $own );
		}

		return $files;
	}

	/**
	 * wp_unique_filename() with the module's family check, the item's own files excluded:
	 * they are not a collision.
	 *
	 * @param string[] $own Basenames.
	 */
	private function unique_name( string $dir, string $name, array $own ): string {
		$exclude = static function ( $files, $folder = '' ) use ( $own ) {
			if ( null === $files ) {
				$files = is_dir( (string) $folder ) ? scandir( (string) $folder ) : [];
			}

			return is_array( $files ) ? array_values( array_diff( $files, $own ) ) : $files;
		};

		add_filter( 'pre_wp_unique_filename_file_list', $exclude, 20, 2 );
		try {
			return wp_unique_filename( $dir, $name );
		} finally {
			remove_filter( 'pre_wp_unique_filename_file_list', $exclude, 20 );
		}
	}

	/**
	 * Files of a family on disk (`name-WxH.ext`, `-scaled`, `-rotated`, their siblings,
	 * `name.ext.avif`), except the given basenames.
	 *
	 * @param string[] $except
	 * @return string[]
	 */
	private function family_files( string $dir, string $name, string $ext, array $except ): array {
		$pattern = '/^' . preg_quote( $name, '/' ) . '(?:-(?:\d+x\d+|scaled|rotated))?\.' . preg_quote( $ext, '/' ) . '(?:\.avif)?$/i';
		$found   = [];
		foreach ( (array) scandir( $dir ) as $file ) {
			$file = (string) $file;
			if ( preg_match( $pattern, $file ) && ! in_array( $file, $except, true ) && strcasecmp( $file, $name . '.' . $ext ) !== 0 ) {
				$found[] = $file;
			}
		}

		return $found;
	}

	/* ================================================================
	 * FILES
	 * ================================================================ */

	/**
	 * Case 1: main file and every registered size produced again from the original with
	 * WP_Image_Editor (no wp_create_image_subsizes(): it saves the metadata after each size).
	 *
	 * @param array<string, mixed> $journal
	 * @return array<string, mixed>
	 */
	private function write_from_original( int $id, array $journal ): array {
		$dir    = (string) $journal['dir'];
		$source = (string) $journal['source'];
		$abs    = $this->abs( $source );
		$name   = pathinfo( $source, PATHINFO_FILENAME );
		$ext    = pathinfo( $source, PATHINFO_EXTENSION );

		if ( '' !== $journal['copy_from'] ) {
			$this->atomic_copy( $this->abs( (string) $journal['copy_from'] ), $abs );
			$this->written[] = $source;
		}

		// A previous run killed in the middle: what it generated (after it started) goes.
		$this->remove_generated( $journal );
		$taken = $this->family_files( $this->abs( $dir ), $name, $ext, (array) $journal['own'] );
		if ( $taken ) {
			throw new \RuntimeException( sprintf( 'name collision with %s', $this->describe( $dir, $taken, $id ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WP-CLI message, never HTML.
		}

		$before = $this->state( $journal );
		$old    = (array) $before['metadata'];

		$size = wp_getimagesize( $abs );
		if ( ! is_array( $size ) ) {
			throw new \RuntimeException( sprintf( 'unreadable original %s', $source ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WP-CLI message, never HTML.
		}

		$max       = (int) ( $this->module->get_settings()['max_dimension'] ?? 2560 );
		$threshold = (int) apply_filters( 'big_image_size_threshold', $max > 0 ? $max : false, $size, $abs, $id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter.

		$editor = $this->editor( $abs );
		$main   = $abs;
		$orig   = '';
		if ( $threshold > 0 && ( $size[0] > $threshold || $size[1] > $threshold ) ) {
			$editor->maybe_exif_rotate();
			$this->check( $editor->resize( $threshold, $threshold ) );
			$saved = $this->check( $editor->save( $editor->generate_filename( 'scaled' ) ) );
			$main  = (string) $saved['path'];
			$orig  = wp_basename( $abs );
		} elseif ( true === $editor->maybe_exif_rotate() ) {
			$saved = $this->check( $editor->save( $editor->generate_filename( 'rotated' ) ) );
			$main  = (string) $saved['path'];
			$orig  = wp_basename( $abs );
		}
		if ( $main !== $abs ) {
			$this->track( AvifState::rel( $main ), $journal );
		}

		$sizes_editor = $this->editor( $abs );
		$sizes_editor->maybe_exif_rotate();
		$registered = apply_filters( 'intermediate_image_sizes_advanced', wp_get_registered_image_subsizes(), $old, $id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter.
		$made       = $sizes_editor->multi_resize( is_array( $registered ) ? $registered : [] );

		$sizes = [];
		foreach ( (array) $made as $size_name => $data ) {
			if ( ! is_array( $data ) || empty( $data['file'] ) ) {
				continue;
			}
			$rel                 = $dir . wp_basename( (string) $data['file'] );
			$data['filesize']    = (int) filesize( $this->abs( $rel ) );
			$sizes[ $size_name ] = $data;
			$this->track( $rel, $journal );
		}

		$main_size = wp_getimagesize( $main );
		$metadata  = $old;

		$metadata['width']    = is_array( $main_size ) ? (int) $main_size[0] : (int) ( $old['width'] ?? 0 );
		$metadata['height']   = is_array( $main_size ) ? (int) $main_size[1] : (int) ( $old['height'] ?? 0 );
		$metadata['file']     = AvifState::rel( $main );
		$metadata['filesize'] = (int) filesize( $main );
		$metadata['sizes']    = $sizes;
		if ( '' !== $orig ) {
			$metadata['original_image'] = $orig;
		} else {
			unset( $metadata['original_image'] );
		}

		// Old URL => new file: same size name, else the new file closest in width.
		$widths = [ $metadata['file'] => $metadata['width'] ];
		foreach ( $sizes as $data ) {
			$widths[ $dir . wp_basename( (string) $data['file'] ) ] = (int) $data['width'];
		}
		$pairs    = [];
		$old_main = AvifState::rel( (string) $old['file'] );
		if ( preg_match( self::LEGACY_PATTERN, $old_main ) ) {
			$pairs[ $old_main ] = $metadata['file'];
		}
		foreach ( (array) ( $old['sizes'] ?? [] ) as $size_name => $data ) {
			$rel = $dir . wp_basename( (string) ( $data['file'] ?? '' ) );
			if ( ! preg_match( self::LEGACY_PATTERN, $rel ) || isset( $pairs[ $rel ] ) ) {
				continue;
			}
			$pairs[ $rel ] = isset( $sizes[ $size_name ] )
				? $dir . wp_basename( (string) $sizes[ $size_name ]['file'] )
				: $this->closest( (int) ( $data['width'] ?? 0 ), $widths );
		}

		$new_files = array_merge( [ $metadata['file'] ], array_keys( $widths ) );
		if ( '' !== $orig ) {
			$new_files[] = $dir . $orig;
		}
		$after_bytes = 0;
		foreach ( array_unique( $new_files ) as $rel ) {
			if ( $rel !== $dir . $orig && is_file( $this->abs( $rel ) ) ) {
				$after_bytes += (int) filesize( $this->abs( $rel ) );
			}
		}

		// Every old file of the item that is not a file of the new layout stays on disk.
		$legacy = [];
		foreach ( $this->own_files( $old ) as $rel ) {
			if ( ! in_array( $rel, $new_files, true ) && is_file( $this->abs( $rel ) ) ) {
				$legacy[] = $rel;
			}
		}
		if ( '' !== $journal['copy_from'] ) {
			$legacy[] = (string) $journal['copy_from'];
		}

		$journal['created']     = array_values( array_unique( $this->written ) );
		$journal['pairs']       = $pairs;
		$journal['legacy']      = array_values( array_unique( array_merge( $legacy, (array) $journal['extra_legacy'] ) ) );
		$journal['bytes_after'] = $after_bytes;
		$journal['bytes_avif']  = 0;
		$journal['avif']        = [
			'status' => AvifState::PENDING,
			'sizes'  => $this->unencoded( $metadata ),
		];
		$journal['after']       = [
			'attached_file' => $metadata['file'],
			'mime'          => (string) wp_get_image_mime( $main ),
			'guid'          => $this->new_guid( $id, $old_main, $metadata['file'] ),
			'metadata'      => $metadata,
		];

		return $journal;
	}

	/**
	 * Case 2: a fallback per legacy file, the legacy AVIF linked as its sibling.
	 *
	 * @param array<string, mixed> $journal
	 * @return array<string, mixed>
	 */
	private function write_decoded( int $id, array $journal ): array {
		$format = (string) $journal['format'];
		$dir    = (string) $journal['dir'];
		$before = $this->state( $journal );
		$old    = (array) $before['metadata'];

		$kept        = [];
		$after_bytes = 0;
		$avif_bytes  = 0;
		$missing     = [];

		foreach ( (array) $journal['files'] as $legacy => $fallback ) {
			$legacy_abs   = $this->abs( (string) $legacy );
			$fallback_abs = $this->abs( (string) $fallback );
			if ( ! is_file( $legacy_abs ) ) {
				$missing[] = (string) $legacy;
				continue;
			}

			$this->written[] = (string) $fallback;
			$this->write_fallback( $legacy_abs, $fallback_abs, $format );
			$after_bytes += (int) filesize( $fallback_abs );

			if ( $this->serving && preg_match( '/\.avif$/i', (string) $legacy ) && filesize( $legacy_abs ) <= self::AVIF_MAX_RATIO * filesize( $fallback_abs ) ) {
				$sibling         = FileLifecycle::sibling( $fallback_abs );
				$this->written[] = AvifState::rel( $sibling );
				$this->link_sibling( $legacy_abs, $sibling );
				$kept[ (string) $fallback ] = (int) filesize( $sibling );
				$avif_bytes                += (int) filesize( $sibling );
			}
		}

		$files = (array) $journal['files'];
		$mime  = 'png' === $format ? 'image/png' : 'image/jpeg';

		$metadata = $old;
		$old_main = AvifState::rel( (string) $old['file'] );
		if ( isset( $files[ $old_main ] ) ) {
			if ( in_array( $old_main, $missing, true ) ) {
				throw new \RuntimeException( sprintf( 'file not found: %s', $old_main ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WP-CLI message, never HTML.
			}
			$metadata['file']     = (string) $files[ $old_main ];
			$metadata['filesize'] = (int) filesize( $this->abs( $metadata['file'] ) );
		}

		$widths = [ (string) $metadata['file'] => (int) ( $metadata['width'] ?? 0 ) ];
		foreach ( (array) ( $metadata['sizes'] ?? [] ) as $size_name => $data ) {
			$rel = $dir . wp_basename( (string) ( $data['file'] ?? '' ) );
			if ( in_array( $rel, $missing, true ) ) {
				unset( $metadata['sizes'][ $size_name ] ); // Already a dead file: the size goes.
				continue;
			}
			if ( isset( $files[ $rel ] ) ) {
				$metadata['sizes'][ $size_name ]['file']      = wp_basename( (string) $files[ $rel ] );
				$metadata['sizes'][ $size_name ]['mime-type'] = $mime;
				$metadata['sizes'][ $size_name ]['filesize']  = (int) filesize( $this->abs( (string) $files[ $rel ] ) );
				$rel = (string) $files[ $rel ];
			}
			$widths[ $rel ] = (int) ( $data['width'] ?? 0 );
		}

		if ( ! empty( $metadata['original_image'] ) && ! is_file( $this->abs( $dir . wp_basename( (string) $metadata['original_image'] ) ) ) ) {
			unset( $metadata['original_image'] );
		}

		$pairs = [];
		foreach ( $files as $legacy => $fallback ) {
			$pairs[ (string) $legacy ] = in_array( $legacy, $missing, true )
				? $this->closest( $this->width_of( $old, (string) $legacy ), $widths )
				: (string) $fallback;
		}

		$sizes = $this->unencoded( $metadata );
		foreach ( $sizes as $rel => $entry ) {
			if ( isset( $kept[ $rel ] ) ) {
				$print         = AvifState::fingerprint( $this->abs( $rel ) );
				$sizes[ $rel ] = [
					'bytes'      => $print['bytes'],
					'mtime'      => $print['mtime'],
					'avif_bytes' => $kept[ $rel ],
				];
			}
		}
		$all_kept = ! in_array(
			[
				'bytes'      => null,
				'mtime'      => null,
				'avif_bytes' => null,
			],
			$sizes,
			true
		);

		$legacy = [];
		foreach ( array_keys( $files ) as $rel ) {
			if ( is_file( $this->abs( (string) $rel ) ) ) {
				$legacy[] = (string) $rel;
			}
		}

		$main_now = (string) $metadata['file'];

		$journal['created']     = array_values( array_unique( $this->written ) );
		$journal['pairs']       = $pairs;
		$journal['legacy']      = array_values( array_unique( array_merge( $legacy, (array) $journal['extra_legacy'] ) ) );
		$journal['bytes_after'] = $after_bytes;
		$journal['bytes_avif']  = $avif_bytes;
		$journal['avif']        = [
			'status' => $all_kept && $sizes ? AvifState::DONE : AvifState::PENDING,
			'sizes'  => $sizes,
		];
		$journal['after']       = [
			'attached_file' => $main_now,
			'mime'          => $main_now === $old_main ? (string) $before['mime'] : $mime,
			'guid'          => $this->new_guid( $id, $old_main, $main_now ),
			'metadata'      => $metadata,
		];

		return $journal;
	}

	/**
	 * Decodes a legacy file into a fallback: PNG, or progressive JPEG q90 (alpha flattened on
	 * white). Written to a temporary file of the same folder, then renamed.
	 */
	private function write_fallback( string $legacy, string $dest, string $format ): void {
		$image = new \Imagick( $legacy );
		$tmp   = dirname( $dest ) . '/.' . wp_basename( $dest ) . '.' . wp_generate_password( 8, false ) . '.tmp';

		try {
			if ( $image->getNumberImages() > 1 ) {
				throw new \RuntimeException( sprintf( 'animated image %s', wp_basename( $legacy ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WP-CLI message, never HTML.
			}

			$image->setImageDepth( 8 );
			if ( 'png' === $format ) {
				$image->setImageFormat( 'png' );
			} else {
				if ( $image->getImageAlphaChannel() ) {
					$image->setImageBackgroundColor( 'white' );
					$image->setImageAlphaChannel( \Imagick::ALPHACHANNEL_REMOVE );
				}
				$image->setImageFormat( 'jpeg' );
				$image->setCompressionQuality( 90 );
				$image->setImageCompressionQuality( 90 );
				$image->setInterlaceScheme( \Imagick::INTERLACE_PLANE );
			}

			// Explicit format: the temporary name has no image extension.
			$image->writeImage( $format . ':' . $tmp );

			$perms = fileperms( $legacy );
			chmod( $tmp, false !== $perms ? $perms & 0777 : 0644 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- same permissions as the legacy file.
			if ( ! rename( $tmp, $dest ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- same-folder atomic rename.
				throw new \RuntimeException( sprintf( 'could not write %s', wp_basename( $dest ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WP-CLI message, never HTML.
			}
		} finally {
			$image->clear();
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
		}
	}

	/**
	 * The legacy AVIF becomes the sibling under a second name (hard link: same bytes, no
	 * extra space; a copy when the file system refuses), placed by an atomic rename.
	 */
	private function link_sibling( string $legacy, string $sibling ): void {
		$tmp = dirname( $sibling ) . '/.' . wp_basename( $sibling ) . '.' . wp_generate_password( 8, false ) . '.tmp';

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a refused link falls back to a copy.
		if ( ! @link( $legacy, $tmp ) && ! copy( $legacy, $tmp ) ) {
			throw new \RuntimeException( sprintf( 'could not link %s', wp_basename( $sibling ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WP-CLI message, never HTML.
		}
		if ( ! rename( $tmp, $sibling ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- same-folder atomic rename.
			wp_delete_file( $tmp );
			throw new \RuntimeException( sprintf( 'could not write %s', wp_basename( $sibling ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WP-CLI message, never HTML.
		}
	}

	private function atomic_copy( string $from, string $to ): void {
		$tmp = dirname( $to ) . '/.' . wp_basename( $to ) . '.' . wp_generate_password( 8, false ) . '.tmp';
		if ( ! is_file( $from ) || ! copy( $from, $tmp ) ) {
			throw new \RuntimeException( sprintf( 'could not copy %s', wp_basename( $from ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WP-CLI message, never HTML.
		}
		if ( ! rename( $tmp, $to ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- same-folder atomic rename.
			wp_delete_file( $tmp );
			throw new \RuntimeException( sprintf( 'could not write %s', wp_basename( $to ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WP-CLI message, never HTML.
		}
	}

	private function editor( string $path ): \WP_Image_Editor {
		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			throw new \RuntimeException( $editor->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WP-CLI message, never HTML.
		}

		return $editor;
	}

	/**
	 * @param mixed $result
	 * @return array<string, mixed>
	 */
	private function check( $result ): array {
		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( $result->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WP-CLI message, never HTML.
		}

		return is_array( $result ) ? $result : [];
	}

	/**
	 * Every file this run wrote for the item is there (a resumed `files_written` journal).
	 *
	 * @param array<string, mixed> $journal
	 */
	private function files_present( array $journal ): bool {
		foreach ( (array) $journal['created'] as $rel ) {
			if ( ! is_file( $this->abs( (string) $rel ) ) ) {
				return false;
			}
		}

		return isset( $journal['after'] );
	}

	/**
	 * Before any file is written (first run or resume): a file this item is about to write
	 * that another attachment already uses is a collision, and the item fails without
	 * touching anything. Hours can pass between a killed run and its resume, and an upload may
	 * have taken a name the journal had reserved: renaming over it, or deleting it on a
	 * failure, would break that other media item.
	 *
	 * @param array<string, mixed> $journal
	 */
	private function check_targets( int $id, array $journal ): void {
		$dir     = (string) $journal['dir'];
		$targets = [];

		if ( self::CASE_ORIGINAL === $journal['case'] ) {
			$source = (string) $journal['source'];
			if ( '' !== $journal['copy_from'] ) {
				$targets[] = $source;
			}
			foreach ( $this->family_files( $this->abs( $dir ), pathinfo( $source, PATHINFO_FILENAME ), pathinfo( $source, PATHINFO_EXTENSION ), (array) $journal['own'] ) as $file ) {
				$targets[] = $dir . $file;
			}
		} else {
			foreach ( (array) $journal['files'] as $fallback ) {
				$targets[] = (string) $fallback;
			}
		}

		$taken = [];
		foreach ( $targets as $rel ) {
			if ( is_file( $this->abs( $rel ) ) && 0 !== $this->owner( $rel, $id ) ) {
				$taken[] = wp_basename( $rel );
			}
		}
		if ( $taken ) {
			throw new \RuntimeException( sprintf( 'name collision with %s: run again to pick a free name', $this->describe( $dir, $taken, $id ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WP-CLI message, never HTML.
		}
	}

	/**
	 * The attachment (other than `$id`) whose main file, size or original is this path (an
	 * `.avif` sibling counts as its source), 0 when none.
	 */
	private function owner( string $rel, int $id ): int {
		global $wpdb;

		$rel  = (string) preg_replace( '/(\.(?:jpe?g|png))\.avif$/i', '$1', $rel );
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off check before writing over a file.
			$wpdb->prepare(
				"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
				WHERE post_id <> %d AND ( ( meta_key = '_wp_attached_file' AND meta_value = %s ) OR ( meta_key = '_wp_attachment_metadata' AND meta_value LIKE %s ) )",
				$id,
				$rel,
				'%' . $wpdb->esc_like( wp_basename( $rel ) . '"' ) . '%'
			)
		);

		foreach ( (array) $rows as $row ) {
			if ( '_wp_attached_file' === $row->meta_key ) {
				return (int) $row->post_id;
			}
			$metadata = maybe_unserialize( (string) $row->meta_value );
			if ( is_array( $metadata ) && in_array( $rel, $this->own_files( $metadata ), true ) ) {
				return (int) $row->post_id;
			}
		}

		return 0;
	}

	/**
	 * `photo.jpg (attachment #12), photo-300x225.jpg (orphan)`.
	 *
	 * @param string[] $files Basenames in `$dir`.
	 */
	private function describe( string $dir, array $files, int $id ): string {
		$parts = [];
		foreach ( $files as $file ) {
			$owner   = $this->owner( $dir . $file, $id );
			$parts[] = sprintf( '%s (%s)', $file, $owner > 0 ? 'attachment #' . $owner : 'orphan' );
		}

		return implode( ', ', $parts );
	}

	/**
	 * A failed item: the files this migration wrote go, the journal too. The legacy files,
	 * the metadata and the URLs are as they were.
	 *
	 * @param array<string, mixed> $journal
	 */
	private function discard( int $id, array $journal ): void {
		foreach ( array_unique( array_merge( (array) ( $journal['created'] ?? [] ), $this->written ) ) as $rel ) {
			$path = $this->abs( (string) $rel );
			if ( 0 === validate_file( (string) $rel ) && is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
		if ( self::CASE_ORIGINAL === $journal['case'] ) {
			$this->remove_generated( $journal );
		}
		$this->written = [];
		delete_post_meta( $id, self::JOURNAL );
	}

	/**
	 * Case 1: the family files of the original written since the journal was started (by
	 * this run or a killed one), which the editor may not have reported. The item's own files
	 * and the original are never touched.
	 *
	 * @param array<string, mixed> $journal
	 */
	private function remove_generated( array $journal ): void {
		$dir    = (string) $journal['dir'];
		$source = (string) $journal['source'];

		$files = $this->family_files( $this->abs( $dir ), pathinfo( $source, PATHINFO_FILENAME ), pathinfo( $source, PATHINFO_EXTENSION ), (array) $journal['own'] );
		foreach ( $files as $file ) {
			$path = $this->abs( $dir . $file );
			if ( (int) filemtime( $path ) >= (int) $journal['started'] - 1 && 0 === $this->owner( $dir . $file, (int) ( $journal['id'] ?? 0 ) ) ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * Records a file the editor wrote, unless it is one of the item's own (overwritten in
	 * place: it was there before and must stay on a failure).
	 *
	 * @param array<string, mixed> $journal
	 */
	private function track( string $rel, array $journal ): void {
		if ( ! in_array( wp_basename( $rel ), (array) $journal['own'], true ) ) {
			$this->written[] = $rel;
		}
	}

	/* ================================================================
	 * DATABASE
	 * ================================================================ */

	/**
	 * Attached file, MIME, guid, metadata and the legacy list, in one go; read back.
	 *
	 * @param array<string, mixed> $journal
	 */
	private function write_metas( int $id, array $journal ): void {
		$after = (array) $journal['after'];
		$this->apply_state( $id, $after );

		$known = get_post_meta( $id, AvifState::LEGACY, true );
		$known = is_array( $known ) ? $known : [];
		update_post_meta( $id, AvifState::LEGACY, array_values( array_unique( array_merge( $known, (array) $journal['legacy'] ) ) ) );

		global $wpdb;

		wp_cache_delete( $id, 'post_meta' );
		$metadata = get_post_meta( $id, '_wp_attachment_metadata', true );
		$post     = $wpdb->get_row( $wpdb->prepare( "SELECT post_mime_type, guid FROM {$wpdb->posts} WHERE ID = %d", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read back, past the cache.
		if ( (string) get_post_meta( $id, '_wp_attached_file', true ) !== (string) $after['attached_file']
			|| ! is_array( $metadata ) || ( $metadata['file'] ?? '' ) !== $after['metadata']['file']
			|| ! $post || (string) $post->post_mime_type !== (string) $after['mime'] || (string) $post->guid !== (string) $after['guid'] ) {
			throw new \RuntimeException( 'the database write could not be verified' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WP-CLI message, never HTML.
		}
	}

	/**
	 * Puts back the state recorded before the migration (a failed database step). False when
	 * it could not: the files and the journal must then stay (the metas may point to the new
	 * files), and the next run resumes.
	 *
	 * @param array<string, mixed> $journal
	 */
	private function restore( int $id, array $journal ): bool {
		$before = $this->state( $journal );
		if ( ! $before ) {
			\WP_CLI::warning( sprintf( '#%d: no state recorded before the migration; files and journal kept, run again.', $id ) );
			return false;
		}

		try {
			$this->apply_state( $id, $before );
		} catch ( \Throwable $e ) {
			\WP_CLI::warning( sprintf( '#%d: the state before the migration could not be put back (%s); files and journal kept, run again.', $id, $e->getMessage() ) );
			return false;
		}
		delete_post_meta( $id, AvifState::LEGACY );

		return true;
	}

	/**
	 * @param array<string, mixed> $state attached_file, mime, guid, metadata.
	 */
	private function apply_state( int $id, array $state ): void {
		global $wpdb;

		update_post_meta( $id, '_wp_attached_file', (string) $state['attached_file'] );
		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- wp_update_post() never rewrites the guid, and fires every save hook; the cache is cleaned below.
			$wpdb->posts,
			[
				'post_mime_type' => (string) $state['mime'],
				'guid'           => (string) $state['guid'],
			],
			[ 'ID' => $id ]
		);
		clean_post_cache( $id );
		if ( false === $updated ) {
			throw new \RuntimeException( sprintf( 'could not update the MIME type and guid: %s', $wpdb->last_error ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WP-CLI message, never HTML.
		}
		// Slashed: update_post_meta() unslashes, a backslash in image_meta (caption, copyright) would be lost.
		wp_update_attachment_metadata( $id, wp_slash( (array) $state['metadata'] ) );
	}

	/**
	 * @return array{attached_file: string, mime: string, guid: string, metadata: mixed}
	 */
	private function current_state( int $id ): array {
		return [
			'attached_file' => (string) get_post_meta( $id, '_wp_attached_file', true ),
			'mime'          => (string) get_post_mime_type( $id ),
			'guid'          => (string) get_post_field( 'guid', $id, 'raw' ),
			'metadata'      => wp_get_attachment_metadata( $id ),
		];
	}

	/**
	 * The before-state is stored encoded: the URL rewrite must not reach the guid it holds.
	 *
	 * @param array<string, mixed> $state
	 */
	private function encode_state( array $state ): string {
		return base64_encode( (string) wp_json_encode( $state ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- opaque storage, see above.
	}

	/**
	 * @param array<string, mixed> $journal
	 * @return array<string, mixed>
	 */
	private function state( array $journal ): array {
		$state = json_decode( (string) base64_decode( (string) ( $journal['before'] ?? '' ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- see encode_state().

		return is_array( $state ) ? $state : [];
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function journal( int $id ): ?array {
		wp_cache_delete( $id, 'post_meta' );
		$journal = get_post_meta( $id, self::JOURNAL, true );

		return is_array( $journal ) && isset( $journal['step'], $journal['case'] ) ? $journal : null;
	}

	/**
	 * @param array<string, mixed> $journal
	 */
	private function save_journal( int $id, array $journal ): bool {
		// update_post_meta() unslashes: a backslash in the metadata (image_meta) would be lost.
		update_post_meta( $id, self::JOURNAL, wp_slash( $journal ) );

		return $this->journal( $id ) === $journal;
	}

	private function step( int $id, string $step ): void {
		/**
		 * A migration step of a media item is recorded in its journal.
		 *
		 * @param int    $id   Attachment ID.
		 * @param string $step planned | files_written | db_written.
		 */
		do_action( 'lumia_image_optimizer_migration_step', $id, $step );
	}

	private function fail( int $id, string $message ): void {
		++$this->stats['failed'];
		\WP_CLI::warning( sprintf( '#%d: %s.', $id, $message ) );
	}

	/* ================================================================
	 * HELPERS
	 * ================================================================ */

	/**
	 * Files of the item as its metadata lists them (main, sizes, original unless
	 * `$with_original` is false), relative to uploads, without duplicates.
	 *
	 * @param array<string, mixed> $metadata
	 * @return string[]
	 */
	private function own_files( array $metadata, bool $with_original = true ): array {
		if ( empty( $metadata['file'] ) ) {
			return [];
		}

		$main  = AvifState::rel( (string) $metadata['file'] );
		$dir   = $this->rel_dir( $main );
		$files = [ $main ];
		foreach ( (array) ( $metadata['sizes'] ?? [] ) as $size ) {
			if ( is_array( $size ) && ! empty( $size['file'] ) ) {
				$files[] = $dir . wp_basename( (string) $size['file'] );
			}
		}
		if ( $with_original && ! empty( $metadata['original_image'] ) ) {
			$files[] = $dir . wp_basename( (string) $metadata['original_image'] );
		}

		return array_values( array_unique( $files ) );
	}

	/**
	 * Files the former pipeline left besides the media item (its backup copy, the sources it
	 * kept with `keep_original`): they leave with it once migrated.
	 *
	 * @return string[]
	 */
	private function extra_legacy( int $id ): array {
		$extra = [];

		$backup = $this->backup_file( $id );
		if ( '' !== $backup ) {
			$extra[] = $backup;
		}

		$fallbacks = get_post_meta( $id, '_lumia_fallback_files', true );
		foreach ( is_array( $fallbacks ) ? $fallbacks : [] as $rel ) {
			if ( is_string( $rel ) && 0 === validate_file( $rel ) && is_file( $this->abs( $rel ) ) ) {
				$extra[] = $rel;
			}
		}

		return $extra;
	}

	/**
	 * Every JPEG/PNG of a metadata array, not encoded yet (the queue encodes them).
	 *
	 * @param array<string, mixed> $metadata
	 * @return array<string, array{bytes: null, mtime: null, avif_bytes: null}>
	 */
	private function unencoded( array $metadata ): array {
		$sizes = [];
		$main  = AvifState::rel( (string) ( $metadata['file'] ?? '' ) );
		$dir   = $this->rel_dir( $main );
		$files = [ $main ];
		foreach ( (array) ( $metadata['sizes'] ?? [] ) as $size ) {
			if ( is_array( $size ) && ! empty( $size['file'] ) ) {
				$files[] = $dir . wp_basename( (string) $size['file'] );
			}
		}
		foreach ( $files as $rel ) {
			if ( preg_match( '/\.(?:jpe?g|png)$/i', $rel ) ) {
				$sizes[ $rel ] = [
					'bytes'      => null,
					'mtime'      => null,
					'avif_bytes' => null,
				];
			}
		}

		return $sizes;
	}

	/**
	 * The guid carries the main file's URL when the former pipeline renamed it: renamed
	 * again. Otherwise (the URL of the original upload) it stays.
	 */
	private function new_guid( int $id, string $old_main, string $new_main ): string {
		$guid = (string) get_post_field( 'guid', $id, 'raw' );
		$old  = wp_basename( $old_main );

		if ( $old_main !== $new_main && '' !== $guid && str_ends_with( $guid, '/' . $old ) ) {
			return substr( $guid, 0, -strlen( $old ) ) . wp_basename( $new_main );
		}

		return $guid;
	}

	/**
	 * @param array<string, int> $widths Path => width.
	 */
	private function closest( int $width, array $widths ): string {
		$best = (string) array_key_first( $widths );
		$gap  = PHP_INT_MAX;
		foreach ( $widths as $rel => $candidate ) {
			if ( abs( $candidate - $width ) < $gap ) {
				$gap  = abs( $candidate - $width );
				$best = (string) $rel;
			}
		}

		return $best;
	}

	/**
	 * Width the old metadata gives a file (0 when unknown).
	 *
	 * @param array<string, mixed> $metadata
	 */
	private function width_of( array $metadata, string $rel ): int {
		if ( AvifState::rel( (string) ( $metadata['file'] ?? '' ) ) === $rel ) {
			return (int) ( $metadata['width'] ?? 0 );
		}
		foreach ( (array) ( $metadata['sizes'] ?? [] ) as $size ) {
			if ( is_array( $size ) && wp_basename( (string) ( $size['file'] ?? '' ) ) === wp_basename( $rel ) ) {
				return (int) ( $size['width'] ?? 0 );
			}
		}

		return 0;
	}

	private function uploads(): string {
		return trailingslashit( wp_normalize_path( wp_upload_dir( null, false )['basedir'] ) );
	}

	private function abs( string $rel ): string {
		return $this->uploads() . ltrim( $rel, '/' );
	}

	private function rel_dir( string $rel ): string {
		$dir = dirname( $rel );

		return ( '.' === $dir || '' === $dir || '/' === $dir ) ? '' : trailingslashit( $dir );
	}

	/**
	 * One migration at a time per site (released by MySQL if the process dies).
	 */
	private function acquire_lock(): bool {
		global $wpdb;

		$name = 'lumia_migrate_' . md5( $wpdb->dbname . $wpdb->prefix . home_url() );
		$got  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- advisory lock.
		if ( '1' !== (string) $got ) {
			return false;
		}
		$this->lock = $name;

		return true;
	}

	private function release_lock(): void {
		global $wpdb;

		if ( '' !== $this->lock ) {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $this->lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- advisory lock.
			$this->lock = '';
		}
	}
}
