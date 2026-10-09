<?php
namespace Lumia\Tools\Modules\Database;

defined( 'ABSPATH' ) || exit;

use Lumia\Tools\Core\Compat;

/**
 * Cleanup tab of the Database module (replaces WP-Sweep).
 *
 * Each item is counted, then purged in batches of BATCH: a site carrying
 * 80,000 revisions does not fit in a single AJAX call. Objects that have a
 * WordPress API (posts, revisions, comments, transients) go through it, so
 * that hooks and caches follow; orphaned metadata and relations no longer
 * have a parent object, hence no cache to invalidate, and are deleted with
 * direct SQL.
 *
 * Scope: the current site (`$wpdb->prefix`). On multisite, each site is
 * cleaned from its own dashboard.
 */
class Cleanup {

	/** Number of objects processed per call. */
	const BATCH = 200;

	/** Minimum free space (bytes) to report a fragmented table. */
	const MIN_FREE = 1048576;

	/** Minimum share of free space relative to the table size. */
	const MIN_FREE_RATIO = 0.2;

	/**
	 * Known table prefixes that do not look like the name of the plugin that
	 * creates them. Token (first segment of the short name) => possible plugin
	 * folders.
	 */
	const OWNER_ALIASES = [
		'wc'              => [ 'woocommerce' ],
		'actionscheduler' => [ 'woocommerce', 'action-scheduler', 'wp-mail-smtp', 'seo-by-rank-math', 'mailpoet', 'wpforms-lite', 'wpforms' ],
		'e'               => [ 'elementor', 'elementor-pro' ],
		'gf'              => [ 'gravityforms' ],
		'rank'            => [ 'seo-by-rank-math' ],
		'fsmpt'           => [ 'fluent-smtp' ],
		'ewwwio'          => [ 'ewww-image-optimizer' ],
	];

	/**
	 * Cleanable items, in display order.
	 *
	 * @return array<string, array{label: string, description: string}>
	 */
	public static function items(): array {
		return [
			'revisions'                 => [
				'label'       => __( 'Revisions', 'lumia-tools' ),
				'description' => __( 'Previous versions of content, kept each time it is saved.', 'lumia-tools' ),
			],
			'auto_drafts'               => [
				'label'       => __( 'Auto drafts', 'lumia-tools' ),
				'description' => __( 'Created when the editor is opened, never saved.', 'lumia-tools' ),
			],
			'trashed_posts'             => [
				'label'       => __( 'Content in the trash', 'lumia-tools' ),
				'description' => __( 'Posts, pages and custom post types, permanently deleted.', 'lumia-tools' ),
			],
			'spam_comments'             => [
				'label'       => __( 'Spam comments', 'lumia-tools' ),
				'description' => __( 'Comments marked as spam.', 'lumia-tools' ),
			],
			'trashed_comments'          => [
				'label'       => __( 'Comments in the trash', 'lumia-tools' ),
				'description' => __( 'Comments, permanently deleted.', 'lumia-tools' ),
			],
			'expired_transients'        => [
				'label'       => __( 'Expired transients', 'lumia-tools' ),
				'description' => __( 'Temporary cache data whose expiry date has passed.', 'lumia-tools' ),
			],
			'orphan_transient_timeouts' => [
				'label'       => __( 'Orphaned transient timeouts', 'lumia-tools' ),
				'description' => __( 'Expiry dates whose transient no longer exists.', 'lumia-tools' ),
			],
			'orphan_postmeta'           => [
				'label'       => __( 'Orphaned post metadata', 'lumia-tools' ),
				'description' => __( 'Attached to content that no longer exists.', 'lumia-tools' ),
			],
			'orphan_commentmeta'        => [
				'label'       => __( 'Orphaned comment metadata', 'lumia-tools' ),
				'description' => __( 'Attached to a comment that no longer exists.', 'lumia-tools' ),
			],
			'orphan_usermeta'           => [
				'label'       => __( 'Orphaned user metadata', 'lumia-tools' ),
				'description' => __( 'Attached to a user that no longer exists.', 'lumia-tools' ),
			],
			'orphan_termmeta'           => [
				'label'       => __( 'Orphaned term metadata', 'lumia-tools' ),
				'description' => __( 'Attached to a category or tag that no longer exists.', 'lumia-tools' ),
			],
			'orphan_term_relationships' => [
				'label'       => __( 'Orphaned term relationships', 'lumia-tools' ),
				'description' => __( 'Links between a term and content that no longer exists.', 'lumia-tools' ),
			],
		];
	}

	/* ================================================================
	 * COUNTING
	 * ================================================================ */

	public function count( string $item ): int {
		global $wpdb;

		switch ( $item ) {
			case 'revisions':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" );

			case 'auto_drafts':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" );

			case 'trashed_posts':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'" );

			case 'spam_comments':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'" );

			case 'trashed_comments':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash'" );

			case 'expired_transients':
				return $this->count_expired_transients();

			case 'orphan_transient_timeouts':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
				return (int) $wpdb->get_var( 'SELECT COUNT(*) ' . $this->orphan_timeouts_sql() );

			case 'orphan_term_relationships':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
				return (int) $wpdb->get_var( 'SELECT COUNT(*) ' . $this->orphan_relationships_sql() );
		}

		$meta = $this->meta_spec( $item );
		if ( null !== $meta ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			return (int) $wpdb->get_var( 'SELECT COUNT(*) ' . $this->orphan_meta_sql( $meta ) );
		}

		return 0;
	}

	/**
	 * Counts what `delete_expired_transients()` will delete, and only that:
	 * core only deletes PAIRS of value + timeout. A lone timeout is never
	 * touched by it (see the `orphan_transient_timeouts` item); counting it
	 * here would leave a remainder after each cleanup.
	 */
	private function count_expired_transients(): int {
		global $wpdb;
		$now = time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} a
				INNER JOIN {$wpdb->options} b ON b.option_name = CONCAT( '_transient_timeout_', SUBSTRING( a.option_name, 12 ) )
				WHERE a.option_name LIKE %s AND a.option_name NOT LIKE %s AND b.option_value < %d",
				$wpdb->esc_like( '_transient_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				$now
			)
		);

		if ( ! is_multisite() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count += (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->options} a
					INNER JOIN {$wpdb->options} b ON b.option_name = CONCAT( '_site_transient_timeout_', SUBSTRING( a.option_name, 17 ) )
					WHERE a.option_name LIKE %s AND a.option_name NOT LIKE %s AND b.option_value < %d",
					$wpdb->esc_like( '_site_transient_' ) . '%',
					$wpdb->esc_like( '_site_transient_timeout_' ) . '%',
					$now
				)
			);
		} elseif ( is_main_site() && is_main_network() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count += (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->sitemeta} a
					INNER JOIN {$wpdb->sitemeta} b ON b.meta_key = CONCAT( '_site_transient_timeout_', SUBSTRING( a.meta_key, 17 ) )
					WHERE a.meta_key LIKE %s AND a.meta_key NOT LIKE %s AND b.meta_value < %d",
					$wpdb->esc_like( '_site_transient_' ) . '%',
					$wpdb->esc_like( '_site_transient_timeout_' ) . '%',
					$now
				)
			);
		}

		return $count;
	}

	/**
	 * FROM … WHERE of the timeouts without a transient. The opposite (a
	 * transient without a timeout) is NOT an orphan: it is a transient created
	 * without a duration, which never expires. Deleting it would break the
	 * plugin that set it.
	 */
	private function orphan_timeouts_sql(): string {
		global $wpdb;
		return $wpdb->prepare(
			"FROM {$wpdb->options} t
			WHERE ( t.option_name LIKE %s AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->options} v WHERE v.option_name = CONCAT( '_transient_', SUBSTRING( t.option_name, 20 ) )
			) )
			OR ( t.option_name LIKE %s AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->options} v WHERE v.option_name = CONCAT( '_site_transient_', SUBSTRING( t.option_name, 25 ) )
			) )",
			$wpdb->esc_like( '_transient_timeout_' ) . '%',
			$wpdb->esc_like( '_site_transient_timeout_' ) . '%'
		);
	}

	/**
	 * Description of a metadata table, for orphan detection.
	 *
	 * @return array{table: string, id: string, fk: string, parent: string, pk: string}|null
	 */
	private function meta_spec( string $item ): ?array {
		global $wpdb;
		switch ( $item ) {
			case 'orphan_postmeta':
				return [
					'table'  => $wpdb->postmeta,
					'id'     => 'meta_id',
					'fk'     => 'post_id',
					'parent' => $wpdb->posts,
					'pk'     => 'ID',
				];
			case 'orphan_commentmeta':
				return [
					'table'  => $wpdb->commentmeta,
					'id'     => 'meta_id',
					'fk'     => 'comment_id',
					'parent' => $wpdb->comments,
					'pk'     => 'comment_ID',
				];
			case 'orphan_usermeta':
				return [
					'table'  => $wpdb->usermeta,
					'id'     => 'umeta_id',
					'fk'     => 'user_id',
					'parent' => $wpdb->users,
					'pk'     => 'ID',
				];
			case 'orphan_termmeta':
				return [
					'table'  => $wpdb->termmeta,
					'id'     => 'meta_id',
					'fk'     => 'term_id',
					'parent' => $wpdb->terms,
					'pk'     => 'term_id',
				];
		}
		return null;
	}

	/**
	 * @param array{table: string, id: string, fk: string, parent: string, pk: string} $m
	 */
	private function orphan_meta_sql( array $m ): string {
		// Identifiers come from $wpdb and constants: nothing comes from the client.
		return "FROM {$m['table']} m LEFT JOIN {$m['parent']} p ON p.{$m['pk']} = m.{$m['fk']} WHERE p.{$m['pk']} IS NULL";
	}

	/**
	 * FROM … WHERE of the relations whose object is no longer a post.
	 *
	 * `term_relationships.object_id` is not always a post ID: link categories
	 * point to links, a taxonomy can be registered on users (BuddyPress member
	 * types…).
	 *
	 * Hence an ALLOWLIST: only taxonomies that are registered, and attached
	 * only to post types, are purged. A blocklist of the "non-content"
	 * taxonomies only sees those registered at cleanup time: with a plugin
	 * deactivated, its relations to users looked like orphans and were
	 * deleted for good.
	 */
	private function orphan_relationships_sql(): string {
		global $wpdb;

		$allowed    = [];
		$post_types = get_post_types();
		foreach ( get_taxonomies( [], 'objects' ) as $tax ) {
			$types = (array) $tax->object_type;
			if ( $types && ! array_diff( $types, $post_types ) ) {
				$allowed[] = $tax->name;
			}
		}
		if ( ! $allowed ) {
			// No safe taxonomy: an always-false condition.
			return "FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE 1 = 0";
		}
		$placeholders = implode( ', ', array_fill( 0, count( $allowed ), '%s' ) );

		// $placeholders only contains %s, one per allowed taxonomy.
		return $wpdb->prepare(
			"FROM {$wpdb->term_relationships} tr
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id
			WHERE p.ID IS NULL AND tt.taxonomy IN ( {$placeholders} )", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$allowed
		);
	}

	/* ================================================================
	 * PURGE (one batch per call)
	 * ================================================================ */

	/**
	 * Purges a batch and returns the number of objects deleted. 0 means
	 * "nothing left to do or nothing left that can be deleted": the client
	 * stops there, which avoids an endless loop on an object that WordPress
	 * refuses to delete.
	 */
	public function run( string $item ): int {
		global $wpdb;

		switch ( $item ) {
			case 'revisions':
				return $this->delete_posts( "post_type = 'revision'", 'wp_delete_post_revision' );

			case 'auto_drafts':
				return $this->delete_posts( "post_status = 'auto-draft'", 'wp_delete_post' );

			case 'trashed_posts':
				return $this->delete_posts( "post_status = 'trash'", 'wp_delete_post' );

			case 'spam_comments':
				return $this->delete_comments( 'spam' );

			case 'trashed_comments':
				return $this->delete_comments( 'trash' );

			case 'expired_transients':
				$before = $this->count_expired_transients();
				// true: even with an external object cache, these are the
				// database rows that were just counted.
				delete_expired_transients( true );
				return max( 0, $before - $this->count_expired_transients() );

			case 'orphan_transient_timeouts':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
				$names = $wpdb->get_col( 'SELECT t.option_name ' . $this->orphan_timeouts_sql() . ' LIMIT ' . self::BATCH );
				$done  = 0;
				foreach ( $names as $name ) {
					if ( delete_option( $name ) ) {
						++$done;
					}
				}
				return $done;

			case 'orphan_term_relationships':
				return $this->delete_orphan_relationships();
		}

		$meta = $this->meta_spec( $item );
		if ( null !== $meta ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids = array_map( 'intval', $wpdb->get_col( "SELECT m.{$meta['id']} " . $this->orphan_meta_sql( $meta ) . ' LIMIT ' . self::BATCH ) );
			if ( ! $ids ) {
				return 0;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers cast above.
			return (int) $wpdb->query( "DELETE FROM {$meta['table']} WHERE {$meta['id']} IN (" . implode( ',', $ids ) . ')' );
		}

		return 0;
	}

	/**
	 * @param string   $where    Constant clause (never client input).
	 * @param callable $delete   wp_delete_post or wp_delete_post_revision.
	 */
	private function delete_posts( string $where, callable $delete ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids  = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE {$where} ORDER BY ID LIMIT " . self::BATCH );
		$done = 0;
		foreach ( $ids as $id ) {
			// The second argument forces permanent deletion for
			// wp_delete_post; wp_delete_post_revision ignores it.
			if ( $delete( (int) $id, true ) ) {
				++$done;
			}
		}
		return $done;
	}

	private function delete_comments( string $status ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids  = $wpdb->get_col( $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = %s ORDER BY comment_ID LIMIT %d", $status, self::BATCH ) );
		$done = 0;
		foreach ( $ids as $id ) {
			if ( wp_delete_comment( (int) $id, true ) ) {
				++$done;
			}
		}
		return $done;
	}

	/**
	 * Deletes a batch of orphaned relations, then recalculates the counter of
	 * the terms concerned (`count` column, displayed in the admin).
	 */
	private function delete_orphan_relationships(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( 'SELECT tr.object_id, tr.term_taxonomy_id, tt.taxonomy ' . $this->orphan_relationships_sql() . ' LIMIT ' . self::BATCH, ARRAY_A );

		$done    = 0;
		$touched = [];
		foreach ( (array) $rows as $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$deleted = $wpdb->delete(
				$wpdb->term_relationships,
				[
					'object_id'        => (int) $row['object_id'],
					'term_taxonomy_id' => (int) $row['term_taxonomy_id'],
				],
				[ '%d', '%d' ]
			);
			if ( $deleted ) {
				$done                         += (int) $deleted;
				$touched[ $row['taxonomy'] ][] = (int) $row['term_taxonomy_id'];
			}
		}

		foreach ( $touched as $taxonomy => $tt_ids ) {
			// An unregistered taxonomy (plugin removed) has no count
			// callback: wp_update_term_count_now() would read a property on
			// false.
			if ( taxonomy_exists( $taxonomy ) ) {
				wp_update_term_count_now( array_unique( $tt_ids ), $taxonomy );
			}
			clean_term_cache( array_unique( $tt_ids ), $taxonomy, false );
		}

		return $done;
	}

	/* ================================================================
	 * OPTIMIZATION AND ORPHANED TABLES
	 * ================================================================ */

	/**
	 * Site tables whose free space (`Data_free`) is non-zero.
	 *
	 * @return list<array{name: string, free: int}>
	 */
	public function fragmented_tables(): array {
		global $wpdb;

		// Shared InnoDB tablespace: each table reports the free space of the
		// COMMON file, which OPTIMIZE never gives back to the disk. Counting
		// these tables multiplied the total and left them "fragmented" for life.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$per_table = $wpdb->get_var( 'SELECT @@innodb_file_per_table' );
		$shared    = null !== $per_table && ! in_array( strtoupper( (string) $per_table ), [ '1', 'ON' ], true );

		$tables = [];
		foreach ( $this->site_table_status() as $t ) {
			$free = (int) $t['Data_free'];
			$used = (int) $t['Data_length'] + (int) $t['Index_length'];
			if ( $shared && 'InnoDB' === $t['Engine'] ) {
				continue;
			}
			// InnoDB keeps a few MB reserved per table, which OPTIMIZE does
			// not release on a large table: without a relative threshold, it
			// stayed flagged after every optimization.
			if ( $free >= self::MIN_FREE && $free > $used * self::MIN_FREE_RATIO ) {
				$tables[] = [
					'name' => (string) $t['Name'],
					'free' => $free,
				];
			}
		}
		return $tables;
	}

	/**
	 * Prefixed tables that do not belong to core, with a guess at the plugin
	 * that created them. A deliberate heuristic: the list is there to guide,
	 * deletion remains a manual decision, table by table.
	 *
	 * @return list<array{name: string, rows: int, size: int, status: string, owners: list<string>}>
	 */
	public function foreign_tables(): array {
		global $wpdb;

		$core   = $wpdb->tables( 'all', false );
		$prefix = $wpdb->prefix;

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
		/** @var array<string, array{Name: string}> $plugins */

		/**
		 * Filters the aliases token of a table => plugin folders.
		 *
		 * @param array<string, list<string>> $aliases
		 */
		$aliases = (array) Compat::apply_filters( 'db_table_owner_aliases', self::OWNER_ALIASES );

		$tables = [];
		foreach ( $this->site_table_status() as $t ) {
			$name  = (string) $t['Name'];
			$short = substr( $name, strlen( $prefix ) );

			// Sub-site tables (wp_2_posts…) seen from the main site.
			if ( in_array( $short, $core, true ) || ( is_multisite() && preg_match( '/^\d+_/', $short ) ) ) {
				continue;
			}

			$token  = strtolower( (string) strtok( $short, '_' ) );
			$owners = [];
			$active = false;
			foreach ( $plugins as $file => $data ) {
				$dir = strtolower( false !== strpos( $file, '/' ) ? dirname( $file ) : basename( $file, '.php' ) );
				if ( ! $this->token_matches( $token, $dir, (string) $data['Name'], $aliases ) ) {
					continue;
				}
				$owners[] = (string) $data['Name'];
				if ( is_plugin_active( $file ) ) {
					$active = true;
				}
			}

			if ( $active ) {
				$status = 'active';
			} elseif ( $owners ) {
				$status = 'inactive';
			} else {
				$status = 'unknown';
			}

			$tables[] = [
				'name'   => $name,
				'rows'   => (int) $t['Rows'],
				'size'   => (int) $t['Data_length'] + (int) $t['Index_length'],
				'status' => $status,
				'owners' => array_values( array_unique( $owners ) ),
			];
		}

		// The most suspicious first.
		$order = [
			'unknown'  => 0,
			'inactive' => 1,
			'active'   => 2,
		];
		usort(
			$tables,
			static function ( array $a, array $b ) use ( $order ): int {
				return [ $order[ $a['status'] ], $a['name'] ] <=> [ $order[ $b['status'] ], $b['name'] ];
			}
		);

		return $tables;
	}

	/**
	 * @param array<string, mixed> $aliases
	 */
	private function token_matches( string $token, string $dir, string $name, array $aliases ): bool {
		if ( isset( $aliases[ $token ] ) && in_array( $dir, (array) $aliases[ $token ], true ) ) {
			return true;
		}
		// Under three characters, a token ("e", "wc") matches everything.
		if ( strlen( $token ) < 3 ) {
			return false;
		}
		$normalize = static function ( string $s ): string {
			return (string) preg_replace( '/[^a-z0-9]/', '', strtolower( $s ) );
		};
		return false !== strpos( $normalize( $dir ), $token ) || false !== strpos( $normalize( $name ), $token );
	}

	/**
	 * True if the table belongs to the current site (prefix) and exists.
	 */
	public function is_site_table( string $table ): bool {
		foreach ( $this->site_table_status() as $t ) {
			if ( $t['Name'] === $table ) {
				return true;
			}
		}
		return false;
	}

	public function optimize( string $table ): bool {
		global $wpdb;
		// Name checked by is_site_table(): it comes from SHOW TABLE STATUS.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( 'OPTIMIZE TABLE `' . str_replace( '`', '``', $table ) . '`', ARRAY_A );
		if ( '' !== $wpdb->last_error ) {
			return false;
		}
		// MySQL does not raise an SQL error when OPTIMIZE fails (locked or
		// corrupted table): the failure is a row with Msg_type = error.
		foreach ( (array) $rows as $row ) {
			if ( isset( $row['Msg_type'] ) && 'error' === strtolower( (string) $row['Msg_type'] ) ) {
				$wpdb->last_error = (string) ( $row['Msg_text'] ?? '' );
				return false;
			}
		}
		return true;
	}

	/**
	 * SHOW TABLE STATUS restricted to the tables of the current prefix.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function site_table_status(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ), ARRAY_A );
		return array_values( (array) $rows );
	}
}
