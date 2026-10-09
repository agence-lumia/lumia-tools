<?php
namespace Lumia\Tools\Modules\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Email log table: schema, writing, filtered reading, purge.
 *
 * Same design as the activity log (see ActivityLog\Store): dedicated table,
 * interpolated name (no `%i` before WP 6.2), installation checked on every
 * load, purge in batches.
 */
class Store {

	/** Table name, without prefix. */
	const TABLE = 'lumia_mail_log';

	/** Schema version; any change to CREATE TABLE increments it. */
	const SCHEMA_VERSION = '1';

	/** Option remembering the installed schema version. */
	const SCHEMA_OPTION = 'lumia_mail_log_schema';

	/**
	 * Maximum message body kept (bytes). An email with inline base64 images
	 * weighs several megabytes; beyond that, the body is truncated and
	 * resending is disabled — resending a cut-off message would be worse than
	 * nothing.
	 */
	const MAX_MESSAGE_BYTES = 524288;

	const PURGE_BATCH       = 2000;
	const PURGE_MAX_BATCHES = 200;

	/** Possible statuses. */
	const STATUS_SENT   = 'sent';
	const STATUS_FAILED = 'failed';

	/** List columns: the body and headers only come with the detail. */
	const LIST_COLUMNS = 'id, created_at, status, transport, from_address, to_address, subject, error, resent_of';

	const SEARCH_COLUMNS = [ 'subject', 'to_address', 'from_address' ];

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Creates or updates the table if the installed schema is not the right one.
	 * One autoloaded option read per request.
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

		// dbDelta(): two spaces after PRIMARY KEY, one field per line.
		dbDelta(
			"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			status varchar(10) NOT NULL DEFAULT '',
			transport varchar(20) NOT NULL DEFAULT '',
			from_address varchar(255) NOT NULL DEFAULT '',
			to_address text NULL,
			subject text NULL,
			message longtext NULL,
			content_type varchar(40) NOT NULL DEFAULT '',
			headers text NULL,
			attachments text NULL,
			error text NULL,
			truncated tinyint(1) unsigned NOT NULL DEFAULT 0,
			resent_of bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY status (status)
			) {$charset};"
		);

		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, true );
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function insert( array $row ): void {
		global $wpdb;

		$message   = (string) ( $row['message'] ?? '' );
		$truncated = strlen( $message ) > self::MAX_MESSAGE_BYTES;

		if ( $truncated ) {
			// mb_strcut() cuts by bytes without breaking a UTF-8 character.
			$message = mb_strcut( $message, 0, self::MAX_MESSAGE_BYTES, 'UTF-8' );
		}

		$data   = [
			'created_at'   => current_time( 'mysql', true ),
			'status'       => self::STATUS_SENT === ( $row['status'] ?? '' ) ? self::STATUS_SENT : self::STATUS_FAILED,
			'transport'    => mb_substr( (string) ( $row['transport'] ?? '' ), 0, 20 ),
			'from_address' => mb_substr( (string) ( $row['from_address'] ?? '' ), 0, 255 ),
			'to_address'   => mb_substr( (string) ( $row['to_address'] ?? '' ), 0, 20000 ),
			'subject'      => mb_substr( (string) ( $row['subject'] ?? '' ), 0, 2000 ),
			'message'      => $message,
			'content_type' => mb_substr( (string) ( $row['content_type'] ?? '' ), 0, 40 ),
			'headers'      => wp_json_encode( array_values( (array) ( $row['headers'] ?? [] ) ) ),
			'attachments'  => wp_json_encode( array_values( (array) ( $row['attachments'] ?? [] ) ) ),
			'error'        => mb_substr( (string) ( $row['error'] ?? '' ), 0, 5000 ),
			'truncated'    => $truncated ? 1 : 0,
			'resent_of'    => absint( $row['resent_of'] ?? 0 ),
		];
		$format = [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d' ];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- table owned by the module.
		if ( false !== $wpdb->insert( self::table(), $data, $format ) ) {
			return;
		}

		// Table dropped by hand while the schema option says "installed": the
		// check is only paid for on failure (see ActivityLog\Store::insert()).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema read.
		if ( self::table() !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( self::table() ) ) ) ) {
			self::install();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- see above.
			$wpdb->insert( self::table(), $data, $format );
		}
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Filtered rows, most recent first, without body or headers.
	 *
	 * @param array<string, mixed> $filters See where().
	 * @return array<int, array<string, mixed>>
	 */
	public static function query( array $filters, int $limit, int $offset = 0 ): array {
		global $wpdb;

		[ $where, $args ] = self::where( $filters );
		$table            = self::table();
		$columns          = self::LIST_COLUMNS;
		$args[]           = $limit;
		$args[]           = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- internal table name and columns; the WHERE clause only assembles placeholders from where().
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT {$columns} FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $args ), ARRAY_A );

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
	 * Empties the log. TRUNCATE also resets the auto-increment, without locking
	 * row by row.
	 */
	public static function clear(): void {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}

	/**
	 * Deletes rows older than `$days` days, then everything beyond the most
	 * recent `$max_rows`.
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
			// MySQL refuses LIMIT in a subquery on the table being modified.
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
	 * @param array<string, mixed> $filters status, from, to (Y-m-d, site time), search — already sanitized.
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private static function where( array $filters ): array {
		global $wpdb;

		$clauses = [ '1=1' ];
		$args    = [];

		if ( ! empty( $filters['status'] ) ) {
			$clauses[] = 'status = %s';
			$args[]    = (string) $filters['status'];
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
}
