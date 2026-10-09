<?php
namespace Lumia\Tools\Modules\ActivityLog;

defined( 'ABSPATH' ) || exit;

/**
 * Activity log table: schema, writing, filtered reading, purge.
 *
 * A dedicated table rather than postmeta or an option: a log keeps growing,
 * is filtered by date and by user, and is purged in batches, three things a
 * serialized option or a post type do poorly.
 *
 * The table name is interpolated into the SQL: `%i` (identifier) only exists
 * in wpdb::prepare() since WordPress 6.2, and the plugin supports 6.0. The
 * name never comes from a request, only from `$wpdb->prefix`.
 */
class Store {

	/** Table name, without prefix. */
	const TABLE = 'lumia_activity_log';

	/** Schema version; any change to CREATE TABLE increments it. */
	const SCHEMA_VERSION = '1';

	/** Option storing the installed schema version. */
	const SCHEMA_OPTION = 'lumia_activity_log_schema';

	/**
	 * Rows deleted per query during a purge: a single DELETE of a hundred
	 * thousand rows locks the table for the duration of the operation, and every
	 * write on the site waits behind it.
	 */
	const PURGE_BATCH = 5000;

	/** Cap on batches per purge, so a cron never runs endlessly. */
	const PURGE_MAX_BATCHES = 200;

	/** Columns matched by the full-text search. */
	const SEARCH_COLUMNS = [ 'object_label', 'user_login', 'ip' ];

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Creates or updates the table if the installed schema is not the right one.
	 *
	 * Called on every module load: the test only costs one autoloaded option
	 * read. Activation alone is not enough: a module enabled by a configuration
	 * import does not go through it. A table deleted by hand (Database tab)
	 * leaves the option up to date: that case is caught by insert(), on the
	 * first failed write.
	 */
	public static function maybe_install(): void {
		if ( self::SCHEMA_VERSION === get_option( self::SCHEMA_OPTION ) ) {
			return;
		}

		self::install();
	}

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		// dbDelta() is picky: two spaces after PRIMARY KEY, one field per line,
		// no backticks around column names.
		dbDelta(
			"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_login varchar(60) NOT NULL DEFAULT '',
			user_role varchar(64) NOT NULL DEFAULT '',
			ip varchar(45) NOT NULL DEFAULT '',
			event_group varchar(20) NOT NULL DEFAULT '',
			event varchar(40) NOT NULL DEFAULT '',
			object_type varchar(20) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			object_label varchar(255) NOT NULL DEFAULT '',
			details longtext NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY user_id (user_id),
			KEY event_group (event_group)
			) {$charset};"
		);

		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, true );
	}

	/**
	 * Inserts a row. Values are truncated to the column width: in strict SQL
	 * mode, a 300-character title made the whole INSERT fail, and the event was
	 * lost.
	 *
	 * @param array<string, mixed> $row
	 */
	public static function insert( array $row ): void {
		global $wpdb;

		$details = $row['details'] ?? [];
		$data    = [
			'created_at'   => current_time( 'mysql', true ),
			'user_id'      => absint( $row['user_id'] ?? 0 ),
			'user_login'   => self::cut( (string) ( $row['user_login'] ?? '' ), 60 ),
			'user_role'    => self::cut( (string) ( $row['user_role'] ?? '' ), 64 ),
			'ip'           => self::cut( (string) ( $row['ip'] ?? '' ), 45 ),
			'event_group'  => self::cut( (string) ( $row['event_group'] ?? '' ), 20 ),
			'event'        => self::cut( (string) ( $row['event'] ?? '' ), 40 ),
			'object_type'  => self::cut( (string) ( $row['object_type'] ?? '' ), 20 ),
			'object_id'    => absint( $row['object_id'] ?? 0 ),
			'object_label' => self::cut( (string) ( $row['object_label'] ?? '' ), 255 ),
			'details'      => empty( $details ) ? null : wp_json_encode( $details ),
		];
		$format  = [ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the module's own table, no WordPress API to write to it.
		if ( false !== $wpdb->insert( self::table(), $data, $format ) ) {
			return;
		}

		// Table deleted from the Database tab: the schema option still says
		// "installed", so maybe_install() does not recreate it, and every write
		// failed silently, leaving the log mute until a reactivation. Testing
		// that the table exists on every request would cost one SQL query per
		// page; we only pay on failure.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema read, no relevant cache.
		if ( self::table() !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( self::table() ) ) ) ) {
			self::install();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- see above.
			$wpdb->insert( self::table(), $data, $format );
		}
	}

	/**
	 * Filtered rows, most recent first.
	 *
	 * @param array<string, mixed> $filters See where().
	 * @return array<int, array<string, mixed>>
	 */
	public static function query( array $filters, int $limit, int $offset = 0 ): array {
		global $wpdb;

		[ $where, $args ] = self::where( $filters );
		$table            = self::table();
		$args[]           = $limit;
		$args[]           = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- internal table name; the WHERE clause only assembles placeholders from where(), whose values arrive in $args along with LIMIT and OFFSET.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $args ), ARRAY_A );

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * @param array<string, mixed> $filters
	 */
	public static function count( array $filters ): int {
		global $wpdb;

		[ $where, $args ] = self::where( $filters );
		$table            = self::table();
		$sql              = "SELECT COUNT(*) FROM {$table} WHERE {$where}";

		if ( $args ) {
			$sql = $wpdb->prepare( $sql, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql only assembles placeholders from where().
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- prepared just above when it carries values.
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Users present in the log, for the list filter.
	 *
	 * Read from the table and not from wp_users: a deleted account must remain
	 * filterable, it is often the one being looked for.
	 *
	 * @return array<int, array{user_id: int, user_login: string}>
	 */
	public static function users(): array {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name, no external value.
		$rows = $wpdb->get_results( "SELECT user_id, MAX(user_login) AS user_login FROM {$table} WHERE user_id > 0 GROUP BY user_id ORDER BY user_login LIMIT 500", ARRAY_A );

		$users = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$users[] = [
				'user_id'    => (int) $row['user_id'],
				'user_login' => (string) $row['user_login'],
			];
		}

		return $users;
	}

	/**
	 * Deletes the rows older than `$days` days, then everything beyond the
	 * `$max_rows` most recent ones.
	 *
	 * @return int Rows deleted.
	 */
	public static function purge( int $days, int $max_rows ): int {
		global $wpdb;

		$table   = self::table();
		$deleted = 0;

		if ( $days > 0 ) {
			$cutoff   = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
			$deleted += self::delete_in_batches(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
				$wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s LIMIT %d", $cutoff, self::PURGE_BATCH )
			);
		}

		if ( $max_rows > 0 ) {
			// MySQL rejects LIMIT in a subquery on the table being modified: we
			// first read the ID of the most recent excess row, then delete
			// everything older.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
			$threshold = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", $max_rows ) );

			if ( $threshold > 0 ) {
				$deleted += self::delete_in_batches(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
					$wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d LIMIT %d", $threshold, self::PURGE_BATCH )
				);
			}
		}

		return $deleted;
	}

	/**
	 * WHERE clause and its values from filters ALREADY sanitized by the module
	 * (integers, keys, `Y-m-d` dates): here we only put them behind
	 * placeholders.
	 *
	 * @param array<string, mixed> $filters before_id, user_id, group, event, from, to (Y-m-d, site time), search.
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private static function where( array $filters ): array {
		global $wpdb;

		$clauses = [ '1=1' ];
		$args    = [];

		// Keyset pagination for the export: an OFFSET shifts if rows arrive
		// while we are reading.
		if ( ! empty( $filters['before_id'] ) ) {
			$clauses[] = 'id < %d';
			$args[]    = (int) $filters['before_id'];
		}

		if ( ! empty( $filters['user_id'] ) ) {
			$clauses[] = 'user_id = %d';
			$args[]    = (int) $filters['user_id'];
		}

		if ( ! empty( $filters['group'] ) ) {
			$clauses[] = 'event_group = %s';
			$args[]    = (string) $filters['group'];
		}

		if ( ! empty( $filters['event'] ) ) {
			$clauses[] = 'event = %s';
			$args[]    = (string) $filters['event'];
		}

		// The dates entered are site dates; the table is in UTC.
		if ( ! empty( $filters['from'] ) ) {
			$clauses[] = 'created_at >= %s';
			$args[]    = get_gmt_from_date( $filters['from'] . ' 00:00:00' );
		}

		if ( ! empty( $filters['to'] ) ) {
			$clauses[] = 'created_at <= %s';
			$args[]    = get_gmt_from_date( $filters['to'] . ' 23:59:59' );
		}

		if ( isset( $filters['search'] ) && '' !== $filters['search'] ) {
			$like  = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
			$parts = [];
			foreach ( self::SEARCH_COLUMNS as $column ) {
				$parts[] = "{$column} LIKE %s";
				$args[]  = $like;
			}
			$clauses[] = '(' . implode( ' OR ', $parts ) . ')';
		}

		return [ implode( ' AND ', $clauses ), $args ];
	}

	/**
	 * Replays a DELETE … LIMIT until it no longer deletes anything.
	 */
	private static function delete_in_batches( string $sql ): int {
		global $wpdb;

		$total = 0;

		for ( $i = 0; $i < self::PURGE_MAX_BATCHES; $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- prepared by the caller.
			$affected = (int) $wpdb->query( $sql );
			$total   += $affected;

			if ( $affected < self::PURGE_BATCH ) {
				break;
			}
		}

		return $total;
	}

	/**
	 * Truncates by characters, not bytes: the column is utf8mb4.
	 */
	private static function cut( string $value, int $length ): string {
		return mb_substr( $value, 0, $length );
	}
}
