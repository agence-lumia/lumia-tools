<?php
namespace Lumia\Tools\Modules\ImageOptimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Rewrites the URLs of a renamed media item wherever they have already been inserted.
 *
 * Only the legacy migration (`wp lumia images migrate`) uses it: the former pipeline
 * replaced each file by its AVIF/WebP conversion (photo.jpg → photo.avif), and the
 * migration gives the media item a JPEG/PNG again (photo.avif → photo.jpg). Every
 * occurrence already inserted — post content, page builder metas (Bricks, ACF…), theme
 * options, srcset — is pointed at the new file.
 *
 * Principles:
 *  - we look for the path RELATIVE to the uploads folder, preceded by a "/" and
 *    followed by a boundary (`/2024/01/photo.jpg`): independent of the scheme, the
 *    domain and a CDN; `other-photo.jpg` and `photo.jpg-old.jpg` are
 *    not touched;
 *  - the escaped-slash variant (`\/2024\/01\/photo.jpg`) is handled
 *    too: it is the form of Gutenberg block attributes and JSON;
 *  - serialized values are unserialized, walked, then
 *    re-serialized — a textual replacement would corrupt the string
 *    lengths (`s:42:"…"`) and break the value. Only stdClass is
 *    instantiated: any other class stays incomplete (no __wakeup) and
 *    is re-serialized under its original name, intact;
 *  - a single query per table and per call, whatever the number of
 *    media items: the stems (`/2024/01/photo`) are gathered in one OR.
 *    The migration thus groups the pairs of a whole batch before calling
 *    rewrite() — five images, three queries, not fifteen.
 */
class UrlRewriter {

	/**
	 * Rewrites a set of matches in the contents, the metas and the options.
	 *
	 * @param array<string, string> $pairs Paths relative to the uploads folder,
	 *                                     old => new (e.g. `2024/01/photo.jpg`
	 *                                     => `2024/01/photo.webp`).
	 * @return int Number of rows actually updated.
	 */
	public function rewrite( array $pairs ): int {
		$pairs = $this->normalize_pairs( $pairs );
		if ( ! $pairs ) {
			return 0;
		}

		$stems = $this->stems( array_keys( $pairs ) );
		if ( ! $stems ) {
			return 0;
		}

		global $wpdb;

		$updated  = 0;
		$updated += $this->rewrite_table( $wpdb->posts, 'ID', [ 'post_content', 'post_excerpt' ], $stems, $pairs );
		$updated += $this->rewrite_table( $wpdb->postmeta, 'meta_id', [ 'meta_value' ], $stems, $pairs );
		$updated += $this->rewrite_table( $wpdb->options, 'option_id', [ 'option_value' ], $stems, $pairs );

		if ( $updated > 0 ) {
			// The object caches of these rows are stale.
			wp_cache_flush();
		}

		return $updated;
	}

	/**
	 * Keeps only valid, distinct pairs, without a leading slash.
	 *
	 * @param array<string, string> $pairs
	 * @return array<string, string>
	 */
	private function normalize_pairs( array $pairs ): array {
		$clean = [];
		foreach ( $pairs as $old => $new ) {
			$old = ltrim( str_replace( '\\', '/', (string) $old ), '/' );
			$new = ltrim( str_replace( '\\', '/', (string) $new ), '/' );
			if ( '' === $old || '' === $new || $old === $new ) {
				continue;
			}
			$clean[ $old ] = $new;
		}
		return $clean;
	}

	/**
	 * Distinct stems of the paths: `2024/01/photo` for `photo.jpg` and
	 * `photo-300x200.jpg`. Each stem becomes a LIKE pattern.
	 *
	 * @param string[] $paths
	 * @return string[]
	 */
	private function stems( array $paths ): array {
		$stems = [];
		foreach ( $paths as $path ) {
			$stem = (string) preg_replace( '/(-\d+x\d+)?\.[a-z0-9]+$/i', '', $path );
			if ( '' !== $stem ) {
				$stems[ $stem ] = true;
			}
		}
		return array_keys( $stems );
	}

	/**
	 * Walks the rows of a table containing one of the stems and rewrites
	 * those that actually change.
	 *
	 * @param string   $table   Table name.
	 * @param string   $id_col  Primary key.
	 * @param string[] $columns Text columns to process.
	 * @param string[] $stems   Stems (relative to uploads, without a leading slash).
	 * @param array<string, string> $pairs   old => new.
	 */
	private function rewrite_table( string $table, string $id_col, array $columns, array $stems, array $pairs ): int {
		global $wpdb;

		$where = [];
		$args  = [];
		foreach ( $columns as $col ) {
			foreach ( $stems as $stem ) {
				$where[] = "`{$col}` LIKE %s";
				$args[]  = '%' . $wpdb->esc_like( '/' . $stem ) . '%';
				$where[] = "`{$col}` LIKE %s";
				$args[]  = '%' . $wpdb->esc_like( '\/' . str_replace( '/', '\/', $stem ) ) . '%';
			}
		}
		$cols_sql = '`' . implode( '`, `', $columns ) . '`';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT `{$id_col}`, {$cols_sql} FROM `{$table}` WHERE " . implode( ' OR ', $where ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- identifiers hard-coded by rewrite(), %s placeholders carried by $where.
				$args
			),
			ARRAY_A
		);

		$updated = 0;
		foreach ( (array) $rows as $row ) {
			$changes = [];
			foreach ( $columns as $col ) {
				$new = $this->replace_in_value( $row[ $col ], $pairs );
				if ( $new !== $row[ $col ] ) {
					$changes[ $col ] = $new;
				}
			}
			if ( ! $changes ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$result = $wpdb->update( $table, $changes, [ $id_col => $row[ $id_col ] ] );
			if ( false === $result ) {
				// The file has already been renamed: a row that is not rewritten is a
				// broken link. We cannot go back from here, but we say so,
				// with what is needed to fix it by hand.
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate error log, no UI to display it.
				error_log(
					sprintf(
						'[LUMIA Image Optimizer] URL rewrite failed in %s (%s=%s): %s',
						$table,
						$id_col,
						(string) $row[ $id_col ],
						$wpdb->last_error
					)
				);
				continue;
			}
			++$updated;
		}

		return $updated;
	}

	/**
	 * Replaces in a raw database value: string, or PHP-serialized.
	 *
	 * @param mixed $value
	 * @return mixed
	 * @param array<string, string> $pairs
	 */
	public function replace_in_value( $value, array $pairs ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}

		if ( is_serialized( $value ) ) {
			// Whitelist: only stdClass is instantiated. Any other class
			// arrives as __PHP_Incomplete_Class — no __wakeup/__unserialize
			// is run (no object injection from a meta or an
			// option), and it is re-serialized under its original name.
			$data = @unserialize( $value, [ 'allowed_classes' => [ 'stdClass' ] ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false !== $data || 'b:0;' === $value ) {
				$replaced = $this->replace_recursive( $data, $pairs );
				return serialize( $replaced ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
			}
		}

		return $this->replace_in_string( $value, $pairs );
	}

	/**
	 * @param mixed $data
	 * @return mixed
	 * @param array<string, string> $pairs
	 */
	private function replace_recursive( $data, array $pairs ) {
		if ( is_string( $data ) ) {
			return $this->replace_in_string( $data, $pairs );
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $k => $v ) {
				$data[ $k ] = $this->replace_recursive( $v, $pairs );
			}
			return $data;
		}
		if ( $data instanceof \stdClass ) {
			foreach ( get_object_vars( $data ) as $k => $v ) {
				$data->$k = $this->replace_recursive( $v, $pairs );
			}
			return $data;
		}
		return $data; // Scalars, null values and incomplete objects are left intact.
	}

	/**
	 * @param array<string, string> $pairs
	 */
	private function replace_in_string( string $value, array $pairs ): string {
		foreach ( $pairs as $old => $new ) {
			$old_esc = str_replace( '/', '\/', $old );
			$new_esc = str_replace( '/', '\/', $new );

			// Callbacks rather than a replacement string: preg_replace interprets
			// the backslash there, and `\/` would come out doubled.
			$value = (string) preg_replace_callback(
				'#\\\\/' . preg_quote( $old_esc, '#' ) . '(?![A-Za-z0-9._-])#',
				static function () use ( $new_esc ) {
					return '\\/' . $new_esc;
				},
				$value
			);
			$value = (string) preg_replace_callback(
				'#/' . preg_quote( $old, '#' ) . '(?![A-Za-z0-9._-])#',
				static function () use ( $new ) {
					return '/' . $new;
				},
				$value
			);
		}
		return $value;
	}
}
