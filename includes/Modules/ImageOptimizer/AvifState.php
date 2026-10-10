<?php
namespace Lumia\Tools\Modules\ImageOptimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Per-media AVIF state, stored in post metas (spec 9.5).
 *
 * Scalar metas, so that the queue can be read with plain SQL:
 *
 * - `_lumia_avif_status`    pending | processing | done | partial | skipped | failed | excluded
 * - `_lumia_avif_queued_at` timestamp of the last enqueue (processing order)
 * - `_lumia_avif_origin`    upload | bulk | manual | reconcile
 * - `_lumia_avif_gen`       generation counter, incremented on every enqueue
 * - `_lumia_avif`           detail: `sizes` (per source file, relative to uploads:
 *                           `{bytes, mtime, avif_bytes}`), `error`, `attempts`, `updated`
 * - `_lumia_avif_legacy`    legacy paths kept by the migration (never touched here)
 *
 * A `sizes` entry whose `bytes` is null is a known source file that has not been encoded
 * yet. Once encoded, `bytes` / `mtime` are the fingerprint of the source at encoding time
 * and `avif_bytes` the size of the sibling (null: no sibling kept, the size was skipped).
 */
final class AvifState {

	public const META       = '_lumia_avif';
	public const STATUS     = '_lumia_avif_status';
	public const QUEUED_AT  = '_lumia_avif_queued_at';
	public const ORIGIN     = '_lumia_avif_origin';
	public const GEN        = '_lumia_avif_gen';
	public const LEGACY     = '_lumia_avif_legacy';
	public const PENDING    = 'pending';
	public const PROCESSING = 'processing';
	public const DONE       = 'done';
	public const PARTIAL    = 'partial';
	public const SKIPPED    = 'skipped';
	public const FAILED     = 'failed';
	public const EXCLUDED   = 'excluded';

	public const STATUSES = [ self::PENDING, self::PROCESSING, self::DONE, self::PARTIAL, self::SKIPPED, self::FAILED, self::EXCLUDED ];
	public const ORIGINS  = [ 'upload', 'bulk', 'manual', 'reconcile' ];

	/** Every state meta except the legacy list. */
	public const STATE_KEYS = [ self::META, self::STATUS, self::QUEUED_AT, self::ORIGIN, self::GEN ];

	/**
	 * Full state of a media item. `status` is '' when the item has none.
	 *
	 * @return array{status: string, queued_at: int, origin: string, gen: int, sizes: array<string, array{bytes: int|null, mtime: int|null, avif_bytes: int|null}>, error: string, attempts: int, updated: int}
	 */
	public static function get( int $id ): array {
		$detail = self::detail( $id );

		return [
			'status'    => (string) get_post_meta( $id, self::STATUS, true ),
			'queued_at' => (int) get_post_meta( $id, self::QUEUED_AT, true ),
			'origin'    => (string) get_post_meta( $id, self::ORIGIN, true ),
			'gen'       => (int) get_post_meta( $id, self::GEN, true ),
			'sizes'     => $detail['sizes'],
			'error'     => $detail['error'],
			'attempts'  => $detail['attempts'],
			'updated'   => $detail['updated'],
		];
	}

	/**
	 * Puts a media item in the queue: new generation, `pending`, attempts and error reset.
	 *
	 * @return int The new generation.
	 */
	public static function enqueue( int $id, string $origin ): int {
		$gen = self::gen( $id ) + 1;

		update_post_meta( $id, self::GEN, $gen );
		update_post_meta( $id, self::STATUS, self::PENDING );
		update_post_meta( $id, self::QUEUED_AT, time() );
		update_post_meta( $id, self::ORIGIN, in_array( $origin, self::ORIGINS, true ) ? $origin : 'manual' );

		$detail             = self::detail( $id );
		$detail['attempts'] = 0;
		$detail['error']    = '';
		self::save_detail( $id, $detail );

		return $gen;
	}

	/**
	 * Current generation, read from the database and not from the meta cache: the queue
	 * runner compares it during an encode, while another request may have bumped it.
	 */
	public static function gen( int $id ): int {
		global $wpdb;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the cached value is precisely what must be bypassed.
			$wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1", $id, self::GEN )
		);
	}

	public static function set_status( int $id, string $status, string $error = '' ): void {
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return;
		}

		update_post_meta( $id, self::STATUS, $status );

		$detail          = self::detail( $id );
		$detail['error'] = $error;
		self::save_detail( $id, $detail );
	}

	/**
	 * Merges per-file results into the state.
	 *
	 * @param array<string, array<string, int|null>> $sizes Keyed by source path, absolute or
	 *        relative to the uploads folder: `{bytes, mtime, avif_bytes}`.
	 */
	public static function record_sizes( int $id, array $sizes ): void {
		$detail = self::detail( $id );

		foreach ( $sizes as $path => $entry ) {
			$rel = self::rel( (string) $path );
			if ( '' === $rel || ! is_array( $entry ) ) {
				continue;
			}
			$detail['sizes'][ $rel ] = self::entry( $entry );
		}

		self::save_detail( $id, $detail );
	}

	/**
	 * Replaces the whole per-file map (lifecycle bookkeeping).
	 *
	 * @param array<string, array<string, int|null>> $sizes Keyed by path relative to uploads.
	 */
	public static function replace_sizes( int $id, array $sizes ): void {
		$detail          = self::detail( $id );
		$detail['sizes'] = [];
		foreach ( $sizes as $rel => $entry ) {
			$detail['sizes'][ (string) $rel ] = self::entry( (array) $entry );
		}
		self::save_detail( $id, $detail );
	}

	/**
	 * `processing` and attempts + 1, written BEFORE the encode: a killed process leaves a trace.
	 *
	 * @return int Attempts after the increment.
	 */
	public static function begin_attempt( int $id ): int {
		update_post_meta( $id, self::STATUS, self::PROCESSING );

		$detail = self::detail( $id );
		++$detail['attempts'];
		self::save_detail( $id, $detail );

		return $detail['attempts'];
	}

	/**
	 * The pending media item queued first (oldest `queued_at`, then lowest ID), or null.
	 */
	public static function next_pending(): ?int {
		global $wpdb;

		$id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- queue read, must see the other processes' writes.
			$wpdb->prepare(
				"SELECT s.post_id FROM {$wpdb->postmeta} s
				LEFT JOIN {$wpdb->postmeta} q ON q.post_id = s.post_id AND q.meta_key = %s
				WHERE s.meta_key = %s AND s.meta_value = %s
				ORDER BY CAST( q.meta_value AS UNSIGNED ) ASC, s.post_id ASC
				LIMIT 1",
				self::QUEUED_AT,
				self::STATUS,
				self::PENDING
			)
		);

		return null === $id ? null : (int) $id;
	}

	/**
	 * Number of media items per status (every status present, 0 when none).
	 *
	 * @return array<string, int>
	 */
	public static function count_by_status(): array {
		global $wpdb;

		$counts = array_fill_keys( self::STATUSES, 0 );
		$rows   = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- live progress figures.
			$wpdb->prepare( "SELECT meta_value AS status, COUNT(*) AS n FROM {$wpdb->postmeta} WHERE meta_key = %s GROUP BY meta_value", self::STATUS )
		);

		foreach ( (array) $rows as $row ) {
			if ( isset( $counts[ $row->status ] ) ) {
				$counts[ $row->status ] = (int) $row->n;
			}
		}

		return $counts;
	}

	/**
	 * Removes every `_lumia_avif*` meta of a media item except the legacy list.
	 */
	public static function clear( int $id ): void {
		foreach ( self::STATE_KEYS as $key ) {
			delete_post_meta( $id, $key );
		}
	}

	/**
	 * Fingerprint of a source file (0 / 0 when it does not exist).
	 *
	 * @return array{bytes: int, mtime: int}
	 */
	public static function fingerprint( string $path ): array {
		clearstatcache( true, $path );
		if ( ! is_file( $path ) ) {
			return [
				'bytes' => 0,
				'mtime' => 0,
			];
		}

		return [
			'bytes' => (int) filesize( $path ),
			'mtime' => (int) filemtime( $path ),
		];
	}

	/**
	 * True when the source has been encoded, has not changed since, and its result is still
	 * on disk (an `.avif` sibling, or none when the size was skipped).
	 */
	public static function is_fresh( int $id, string $path ): bool {
		$entry = self::detail( $id )['sizes'][ self::rel( $path ) ] ?? null;

		if ( null === $entry || null === $entry['bytes'] ) {
			return false;
		}

		$now = self::fingerprint( self::abs( $path ) );
		if ( 0 === $now['bytes'] || $now['bytes'] !== $entry['bytes'] || $now['mtime'] !== $entry['mtime'] ) {
			return false;
		}

		$has_sibling = is_file( self::abs( $path ) . '.avif' );

		return null === $entry['avif_bytes'] ? ! $has_sibling : $has_sibling;
	}

	/**
	 * Path relative to the uploads folder ('' when outside it).
	 */
	public static function rel( string $path ): string {
		$path = wp_normalize_path( $path );
		$base = trailingslashit( wp_normalize_path( wp_upload_dir()['basedir'] ) );

		if ( str_starts_with( $path, $base ) ) {
			return substr( $path, strlen( $base ) );
		}

		return str_starts_with( $path, '/' ) ? '' : ltrim( $path, '/' );
	}

	/**
	 * Absolute path of a path relative to the uploads folder (absolute paths unchanged).
	 */
	public static function abs( string $path ): string {
		$path = wp_normalize_path( $path );

		return str_starts_with( $path, '/' ) || preg_match( '#^[A-Za-z]:/#', $path )
			? $path
			: trailingslashit( wp_normalize_path( wp_upload_dir()['basedir'] ) ) . ltrim( $path, '/' );
	}

	/**
	 * @return array{sizes: array<string, array{bytes: int|null, mtime: int|null, avif_bytes: int|null}>, error: string, attempts: int, updated: int}
	 */
	private static function detail( int $id ): array {
		$raw = get_post_meta( $id, self::META, true );
		$raw = is_array( $raw ) ? $raw : [];

		$sizes = [];
		foreach ( (array) ( $raw['sizes'] ?? [] ) as $rel => $entry ) {
			if ( is_array( $entry ) ) {
				$sizes[ (string) $rel ] = self::entry( $entry );
			}
		}

		return [
			'sizes'    => $sizes,
			'error'    => (string) ( $raw['error'] ?? '' ),
			'attempts' => (int) ( $raw['attempts'] ?? 0 ),
			'updated'  => (int) ( $raw['updated'] ?? 0 ),
		];
	}

	/**
	 * @param array{sizes: array<string, array<string, int|null>>, error: string, attempts: int, updated: int} $detail
	 */
	private static function save_detail( int $id, array $detail ): void {
		$detail['updated'] = time();
		update_post_meta( $id, self::META, $detail );
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array{bytes: int|null, mtime: int|null, avif_bytes: int|null}
	 */
	private static function entry( array $entry ): array {
		$int_or_null = static fn( $value ): ?int => null === $value ? null : (int) $value;

		return [
			'bytes'      => $int_or_null( $entry['bytes'] ?? null ),
			'mtime'      => $int_or_null( $entry['mtime'] ?? null ),
			'avif_bytes' => $int_or_null( $entry['avif_bytes'] ?? null ),
		];
	}
}
