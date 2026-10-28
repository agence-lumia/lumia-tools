<?php
namespace Lumia\Tools\Modules\McpConnect;

defined( 'ABSPATH' ) || exit;

/**
 * MCP Connect tables: schema, install, purge.
 *
 * Three tables rather than options or transients: tokens are looked up by
 * their hash on every MCP request (an indexed column, not a serialized
 * blob), and the issue forbids keeping any token, even hashed, in an option
 * or a transient.
 *
 * - `lumia_mcp_clients`: CIMD documents cached after a fetch, and the clients
 *   registered through DCR.
 * - `lumia_mcp_connections`: one row per approval, the unit the admin sees and
 *   revokes.
 * - `lumia_mcp_tokens`: authorization requests, codes, access and refresh
 *   tokens, stored as SHA-256 hashes only.
 *
 * Dates are UTC (`gmdate()`). Table names are interpolated into the SQL: `%i`
 * only exists in wpdb::prepare() since WordPress 6.2, and the names never
 * come from a request, only from `$wpdb->prefix` and the constants below.
 */
final class Store {

	/** Table names, without prefix. */
	const TABLE_CLIENTS     = 'lumia_mcp_clients';
	const TABLE_CONNECTIONS = 'lumia_mcp_connections';
	const TABLE_TOKENS      = 'lumia_mcp_tokens';

	/**
	 * Schema version; any change to a CREATE TABLE increments it.
	 * 2: `used_at` on the tokens (grace window of the refresh rotation).
	 */
	const SCHEMA_VERSION = '2';

	/** Option storing the installed schema version (autoloaded: read on every load). */
	const SCHEMA_OPTION = 'lumia_mcp_schema';

	/**
	 * Rows deleted per query during a purge: a single large DELETE locks the
	 * token table, and every MCP request waits behind it.
	 */
	const PURGE_BATCH = 2000;

	/** Cap on batches per purge, so a cron never runs endlessly. */
	const PURGE_MAX_BATCHES = 50;

	/** Days a revoked connection stays visible (the list shows the last 30) before deletion. */
	const REVOKED_RETENTION_DAYS = 90;

	/** Days a CIMD cache row survives past its expiry before deletion. */
	const CIMD_RETENTION_DAYS = 30;

	/**
	 * Prefixed table name.
	 *
	 * @param string $base One of the TABLE_* constants.
	 */
	public static function table( string $base ): string {
		global $wpdb;
		return $wpdb->prefix . $base;
	}

	/**
	 * Creates or updates the tables if the installed schema is not the right
	 * one.
	 *
	 * Called on every module load: the test only costs one autoloaded option
	 * read. Activation alone is not enough: a module enabled by a configuration
	 * import does not go through it.
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

		$clients     = self::table( self::TABLE_CLIENTS );
		$connections = self::table( self::TABLE_CONNECTIONS );
		$tokens      = self::table( self::TABLE_TOKENS );
		$charset     = $wpdb->get_charset_collate();

		// dbDelta() is picky: two spaces after PRIMARY KEY, one field per line,
		// no backticks around column names. The 191-character prefix on
		// client_id keeps the unique index under the utf8mb4 limit of old
		// MySQL versions (767 bytes).
		dbDelta(
			[
				"CREATE TABLE {$clients} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				client_id varchar(255) NOT NULL,
				type varchar(10) NOT NULL,
				name varchar(191) NOT NULL DEFAULT '',
				logo_uri varchar(255) NOT NULL DEFAULT '',
				client_uri varchar(255) NOT NULL DEFAULT '',
				publisher varchar(191) NOT NULL DEFAULT '',
				redirect_uris text NOT NULL,
				fetched_at datetime NULL,
				expires_at datetime NULL,
				created_at datetime NOT NULL,
				last_used_at datetime NULL,
				registration_ip varchar(45) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				UNIQUE KEY client_id (client_id(191))
				) {$charset};",
				"CREATE TABLE {$connections} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				client_id varchar(255) NOT NULL,
				client_name varchar(191) NOT NULL DEFAULT '',
				publisher varchar(191) NOT NULL DEFAULT '',
				label varchar(100) NOT NULL DEFAULT '',
				acting_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
				act_as_approver tinyint(1) NOT NULL DEFAULT 0,
				scope varchar(255) NOT NULL DEFAULT '',
				resource varchar(255) NOT NULL DEFAULT '',
				status varchar(10) NOT NULL,
				created_at datetime NOT NULL,
				last_used_at datetime NULL,
				last_ip varchar(45) NOT NULL DEFAULT '',
				refresh_expires_at datetime NULL,
				revoked_at datetime NULL,
				revoked_reason varchar(20) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY acting_user_id (acting_user_id),
				KEY approved_by (approved_by),
				KEY status (status)
				) {$charset};",
				"CREATE TABLE {$tokens} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				connection_id bigint(20) unsigned NOT NULL DEFAULT 0,
				type varchar(10) NOT NULL,
				token_hash char(64) NOT NULL,
				expires_at datetime NOT NULL,
				used tinyint(1) NOT NULL DEFAULT 0,
				used_at datetime NULL,
				created_at datetime NOT NULL,
				data longtext NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY token_hash (token_hash),
				KEY connection_id (connection_id),
				KEY expires_at (expires_at)
				) {$charset};",
			]
		);

		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, true );
	}

	/**
	 * Drops the three tables and forgets the schema version.
	 *
	 * uninstall.php drops the tables listed in the module's uninstall keys by
	 * itself; this helper is for code paths that need a clean slate.
	 */
	public static function drop_all(): void {
		global $wpdb;

		foreach ( [ self::TABLE_TOKENS, self::TABLE_CONNECTIONS, self::TABLE_CLIENTS ] as $base ) {
			$table = self::table( $base );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name, no external value.
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}

		delete_option( self::SCHEMA_OPTION );
	}

	/**
	 * Daily purge (cron `lumia_mcp_connect_purge`).
	 *
	 * - active connections whose refresh token has expired: revoked with the
	 *   reason `expired` (logged), so they leave the live list and count;
	 * - authorization requests as soon as they expire, with no batch cap: the
	 *   endpoint creating them is public, and a backlog must not outlive the
	 *   run (AuthorizationServer::authorize() also clears a few on each call);
	 * - other tokens expired for more than a day (the day of grace keeps a
	 *   replayed code or refresh token recognisable as a replay, not as an
	 *   unknown token, for a while);
	 * - pending connections older than a day: a code lives 60 seconds, so a
	 *   connection still pending after a day was never exchanged;
	 * - revoked connections older than 90 days (their tokens went at
	 *   revocation time);
	 * - DCR clients unused for `dcr_purge_days`, unless a live connection
	 *   still uses them;
	 * - CIMD cache rows expired for more than 30 days (an expired row is
	 *   refetched anyway; it is only kept a while for the list's client names).
	 */
	public static function purge_expired(): void {
		global $wpdb;

		$tokens      = self::table( self::TABLE_TOKENS );
		$connections = self::table( self::TABLE_CONNECTIONS );
		$clients     = self::table( self::TABLE_CLIENTS );
		$now         = time();
		$day_ago     = gmdate( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS );

		// One revoke() per connection: each expiry is logged with its row.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		$expired = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$connections} WHERE status = %s AND refresh_expires_at IS NOT NULL AND refresh_expires_at < %s", Connections::STATUS_ACTIVE, gmdate( 'Y-m-d H:i:s', $now ) ) );
		foreach ( $expired as $id ) {
			Connections::revoke( (int) $id, 'expired' );
		}

		self::delete_in_batches(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
			$wpdb->prepare( "DELETE FROM {$tokens} WHERE type = %s AND expires_at < %s LIMIT %d", Tokens::REQUEST, gmdate( 'Y-m-d H:i:s', $now ), self::PURGE_BATCH ),
			0
		);

		self::delete_in_batches(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
			$wpdb->prepare( "DELETE FROM {$tokens} WHERE expires_at < %s LIMIT %d", $day_ago, self::PURGE_BATCH )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$connections} WHERE status = %s AND created_at < %s", 'pending', $day_ago ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$connections} WHERE status = %s AND revoked_at < %s", 'revoked', gmdate( 'Y-m-d H:i:s', $now - self::REVOKED_RETENTION_DAYS * DAY_IN_SECONDS ) ) );

		$settings  = Module::settings();
		$dcr_days  = min( 365, max( 1, (int) ( $settings['dcr_purge_days'] ?? 30 ) ) );
		$dcr_limit = gmdate( 'Y-m-d H:i:s', $now - $dcr_days * DAY_IN_SECONDS );

		// A DCR client never used still has a NULL last_used_at: its creation
		// date counts instead.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table names.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$clients} WHERE type = %s AND COALESCE(last_used_at, created_at) < %s AND client_id NOT IN (SELECT client_id FROM {$connections} WHERE status <> %s)", 'dcr', $dcr_limit, 'revoked' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$clients} WHERE type = %s AND expires_at < %s", 'cimd', gmdate( 'Y-m-d H:i:s', $now - self::CIMD_RETENTION_DAYS * DAY_IN_SECONDS ) ) );
	}

	/**
	 * Deletes up to `$limit` expired authorization requests. Called on every
	 * `/authorize`: the rows that endpoint adds are cleared at the pace they
	 * are created, without waiting for the daily purge.
	 */
	public static function purge_expired_requests( int $limit ): void {
		global $wpdb;

		$tokens = self::table( self::TABLE_TOKENS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$tokens} WHERE type = %s AND expires_at < %s LIMIT %d", Tokens::REQUEST, gmdate( 'Y-m-d H:i:s' ), max( 1, $limit ) ) );
	}

	/**
	 * Replays a DELETE … LIMIT until it no longer deletes a full batch.
	 *
	 * @param int $max_batches Cap on the batches, 0 for none (the rows to delete
	 *                         then only decrease: nothing inserts expired rows).
	 */
	private static function delete_in_batches( string $sql, int $max_batches = self::PURGE_MAX_BATCHES ): int {
		global $wpdb;

		$total = 0;

		for ( $i = 0; 0 === $max_batches || $i < $max_batches; $i++ ) {
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
