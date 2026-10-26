<?php
namespace Lumia\Tools\Modules\McpConnect;

defined( 'ABSPATH' ) || exit;

/**
 * Connections: one row per approval on the consent screen.
 *
 * A connection is the unit the admin sees and revokes; its tokens hang off it.
 * Life cycle:
 * - `pending`: approved, a code was issued but not exchanged yet;
 * - `active`: the code was exchanged at the token endpoint (only then is the
 *   connection real, and only then is it logged as authorized);
 * - `revoked`: by hand, all at once, on refresh token reuse, when its user
 *   is deleted, through the revocation endpoint, or by the daily purge once
 *   its refresh token has expired. Its tokens are deleted at that moment;
 *   the row stays for the list and the Activity Log.
 *
 * Actions fired (listened to by the Activity Log):
 * - `lumia_mcp_connect_authorized` ( int $connection_id ): code exchanged.
 * - `lumia_mcp_connect_denied` ( string $client_name, string $client_id ): Deny clicked on the consent screen.
 * - `lumia_mcp_connect_revoked` ( int $connection_id, string $reason 'manual'|'all'|'reuse'|'user_deleted'|'token'|'expired' ).
 * - `lumia_mcp_connect_refresh_reuse` ( int $connection_id ): an already rotated refresh token came back (likely theft).
 * - `lumia_mcp_connect_client_registered` ( string $client_id, string $client_name ): Dynamic Client Registration.
 *
 * Only `lumia_mcp_connect_revoked` is fired from this class; the others are
 * fired where the event happens (token endpoint, consent handler, client
 * registry) and are listed here so the whole contract lives in one place.
 */
final class Connections {

	const STATUS_PENDING = 'pending';
	const STATUS_ACTIVE  = 'active';
	const STATUS_REVOKED = 'revoked';

	/** Minimum interval between two writes of last_used_at for one connection. */
	const TOUCH_INTERVAL = 60;

	/** Days a revoked connection stays in the admin list. */
	const LIST_REVOKED_DAYS = 30;

	/**
	 * Creates a pending connection when the approver clicks Approve.
	 *
	 * @param array<string, mixed> $client          A client from ClientRegistry::resolve() (client_id, name, publisher).
	 * @param int                  $approver_id     The user who approved.
	 * @param int                  $acting_user_id  The user the connection acts as (agent user or the approver).
	 * @param bool                 $act_as_approver True when the approver chose to let Claude act in their name.
	 * @param string               $label           Free label, already sanitized by the caller.
	 * @param string               $scope           Granted scopes, space separated.
	 * @return int Connection id, 0 on failure.
	 */
	public static function create_pending( array $client, int $approver_id, int $acting_user_id, bool $act_as_approver, string $label, string $scope ): int {
		global $wpdb;

		// Values are cut to the column width: in strict SQL mode an overlong
		// value makes the whole INSERT fail.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the module's own table, no WordPress API to write to it.
		$inserted = $wpdb->insert(
			Store::table( Store::TABLE_CONNECTIONS ),
			[
				'client_id'       => self::cut( (string) ( $client['client_id'] ?? '' ), 255 ),
				'client_name'     => self::cut( (string) ( $client['name'] ?? '' ), 191 ),
				'publisher'       => self::cut( (string) ( $client['publisher'] ?? '' ), 191 ),
				'label'           => self::cut( $label, 100 ),
				'acting_user_id'  => max( 0, $acting_user_id ),
				'approved_by'     => max( 0, $approver_id ),
				'act_as_approver' => $act_as_approver ? 1 : 0,
				'scope'           => self::cut( $scope, 255 ),
				'resource'        => self::cut( Discovery::resource_url(), 255 ),
				'status'          => self::STATUS_PENDING,
				'created_at'      => gmdate( 'Y-m-d H:i:s' ),
			],
			[ '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s' ]
		);

		return false === $inserted ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Turns a pending connection active, at the code exchange.
	 *
	 * Conditional on `pending`: a revoked connection is never brought back.
	 *
	 * @param int $refresh_expires_ts Expiry of the first refresh token (Unix timestamp).
	 * @return bool True only if this call turned the connection active (one row
	 *              changed); false when it was revoked in between, e.g. by a
	 *              concurrent exchange of the same code.
	 */
	public static function activate( int $id, int $refresh_expires_ts ): bool {
		global $wpdb;

		$table = Store::table( Store::TABLE_CONNECTIONS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		$result = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s, refresh_expires_at = %s WHERE id = %d AND status = %s", self::STATUS_ACTIVE, gmdate( 'Y-m-d H:i:s', $refresh_expires_ts ), $id, self::STATUS_PENDING ) );

		return false !== $result && 1 === (int) $wpdb->rows_affected;
	}

	/**
	 * Whether the acting user or the approver of a connection no longer
	 * exists. The `delete_user` listener only runs while the module is
	 * active: a user deleted while it was off is caught here, at use time.
	 *
	 * @param array<string, mixed> $connection Connection row.
	 */
	public static function user_missing( array $connection ): bool {
		$acting   = (int) ( $connection['acting_user_id'] ?? 0 );
		$approver = (int) ( $connection['approved_by'] ?? 0 );

		return ! get_userdata( $acting ) instanceof \WP_User
			|| ( $approver > 0 && ! get_userdata( $approver ) instanceof \WP_User );
	}

	/**
	 * A connection row, with integer columns cast.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;

		if ( $id <= 0 ) {
			return null;
		}

		$table = Store::table( Store::TABLE_CONNECTIONS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name; a revocation must take effect at once, no cache.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? self::normalize( $row ) : null;
	}

	/**
	 * @param array<string, mixed>|null $row
	 */
	public static function is_active( ?array $row ): bool {
		return null !== $row && self::STATUS_ACTIVE === ( $row['status'] ?? '' );
	}

	/**
	 * Records the last use of a connection.
	 *
	 * Every MCP request authenticates, and a write per request would be a
	 * waste: the throttle sits in the WHERE clause of the same UPDATE, so it
	 * costs no extra read.
	 */
	public static function touch( int $id, string $ip ): void {
		global $wpdb;

		$table = Store::table( Store::TABLE_CONNECTIONS );
		$now   = time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET last_used_at = %s, last_ip = %s WHERE id = %d AND (last_used_at IS NULL OR last_used_at < %s)", gmdate( 'Y-m-d H:i:s', $now ), self::cut( $ip, 45 ), $id, gmdate( 'Y-m-d H:i:s', $now - self::TOUCH_INTERVAL ) ) );
	}

	/**
	 * New refresh expiry after a rotation (sliding lifetime).
	 *
	 * @param int $ts Unix timestamp.
	 */
	public static function set_refresh_expiry( int $id, int $ts ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the module's own table.
		$wpdb->update(
			Store::table( Store::TABLE_CONNECTIONS ),
			[ 'refresh_expires_at' => gmdate( 'Y-m-d H:i:s', $ts ) ],
			[ 'id' => $id ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Revokes a connection and deletes its tokens.
	 *
	 * The status change is a conditional UPDATE: when two paths revoke the
	 * same connection at once (a reuse detection and a manual click), only one
	 * of them wins, so the action fires once.
	 *
	 * @param string $reason 'manual', 'all', 'reuse', 'user_deleted', 'token' or 'expired'.
	 * @return bool False if the connection does not exist or was already revoked.
	 */
	public static function revoke( int $id, string $reason ): bool {
		global $wpdb;

		$table = Store::table( Store::TABLE_CONNECTIONS );
		$cut   = self::cut( sanitize_key( $reason ), 20 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		$result = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s, revoked_at = %s, revoked_reason = %s WHERE id = %d AND status <> %s", self::STATUS_REVOKED, gmdate( 'Y-m-d H:i:s' ), $cut, $id, self::STATUS_REVOKED ) );

		if ( false === $result || 1 !== (int) $wpdb->rows_affected ) {
			return false;
		}

		Tokens::revoke_connection_tokens( $id );

		do_action( 'lumia_mcp_connect_revoked', $id, $cut );

		return true;
	}

	/**
	 * Revokes every connection still pending or active.
	 *
	 * One revoke() per row rather than one UPDATE: each revocation is logged
	 * on its own, with its connection.
	 *
	 * @return int Connections revoked.
	 */
	public static function revoke_all( string $reason = 'all' ): int {
		global $wpdb;

		$table = Store::table( Store::TABLE_CONNECTIONS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE status <> %s", self::STATUS_REVOKED ) );

		return self::revoke_ids( $ids, $reason );
	}

	/**
	 * Revokes the connections acting as, or approved by, a user (on user
	 * deletion: neither the account nor its approval exists any more).
	 *
	 * @return int Connections revoked.
	 */
	public static function revoke_for_user( int $user_id ): int {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return 0;
		}

		$table = Store::table( Store::TABLE_CONNECTIONS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE status <> %s AND (acting_user_id = %d OR approved_by = %d)", self::STATUS_REVOKED, $user_id, $user_id ) );

		return self::revoke_ids( $ids, 'user_deleted' );
	}

	/**
	 * Rows for the admin list: everything not revoked, plus the revocations
	 * of the last 30 days, newest first.
	 *
	 * `client_type` comes from the clients table (`cimd`, `dcr`, or '' when the
	 * client row was purged): the module needs it with `publisher` to show
	 * the verified badge. User names are resolved by the module.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_for_admin( int $limit = 200 ): array {
		global $wpdb;

		$connections = Store::table( Store::TABLE_CONNECTIONS );
		$clients     = Store::table( Store::TABLE_CLIENTS );
		$cutoff      = gmdate( 'Y-m-d H:i:s', time() - self::LIST_REVOKED_DAYS * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table names.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT c.*, COALESCE(cl.type, '') AS client_type, COALESCE(cl.logo_uri, '') AS client_logo FROM {$connections} c LEFT JOIN {$clients} cl ON cl.client_id = c.client_id WHERE c.status <> %s OR c.revoked_at >= %s ORDER BY c.id DESC LIMIT %d", self::STATUS_REVOKED, $cutoff, max( 1, $limit ) ), ARRAY_A );

		$list = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			if ( is_array( $row ) ) {
				$list[] = self::normalize( $row );
			}
		}

		return $list;
	}

	/**
	 * @param array<int|string, mixed> $ids
	 */
	private static function revoke_ids( array $ids, string $reason ): int {
		$count = 0;

		foreach ( $ids as $id ) {
			if ( self::revoke( (int) $id, $reason ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Casts the integer columns of a row; the others stay strings (or null
	 * for the empty dates).
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function normalize( array $row ): array {
		foreach ( [ 'id', 'acting_user_id', 'approved_by', 'act_as_approver' ] as $column ) {
			$row[ $column ] = (int) ( $row[ $column ] ?? 0 );
		}

		return $row;
	}

	/**
	 * Truncates by characters, not bytes: the columns are utf8mb4.
	 */
	private static function cut( string $value, int $length ): string {
		return mb_substr( $value, 0, $length );
	}
}
