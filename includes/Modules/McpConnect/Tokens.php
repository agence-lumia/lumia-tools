<?php
namespace Lumia\Tools\Modules\McpConnect;

defined( 'ABSPATH' ) || exit;

/**
 * Opaque tokens: generation, storage by hash, lookup, single use.
 *
 * Every secret this module hands out (authorization request id, code, access
 * and refresh tokens) is a prefix plus 256 random bits. Only its SHA-256 hash
 * reaches the database: a leaked dump or backup gives nothing that can be
 * replayed. The prefixes make a leaked token greppable in logs and pastes.
 *
 * A plain hash, not a password hash: the secret already carries 256 bits of
 * entropy, so a slow hash adds nothing, and the hash has to be an exact,
 * indexed lookup key.
 *
 * Token values are never logged, never written to options, transients or
 * URLs. The only exceptions are the request id and the code, which travel in
 * redirect URLs because that is the protocol.
 */
final class Tokens {

	const ACCESS  = 'access';
	const REFRESH = 'refresh';
	const CODE    = 'code';
	const REQUEST = 'request';

	/** Prefix of each token type. */
	const PREFIXES = [
		self::ACCESS  => 'lmc_at_',
		self::REFRESH => 'lmc_rt_',
		self::CODE    => 'lmc_ac_',
		self::REQUEST => 'lmc_rq_',
	];

	/**
	 * A new secret: the prefix followed by 64 hex characters (256 bits).
	 */
	public static function generate( string $prefix ): string {
		return $prefix . bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Whether a value has the exact shape of a token of the given type: the
	 * prefix plus 64 lowercase hex characters.
	 *
	 * Only a value of the wrong shape counts as a failed attempt in the rate
	 * limiter. A well-formed value carries 256 random bits and cannot be
	 * guessed: when it is unknown, it is a token we issued and deleted since
	 * (revocation, purge), which Claude keeps sending until it refreshes or
	 * reconnects. Counting those would lock Claude's shared egress addresses
	 * out of the site during normal use.
	 */
	public static function is_well_formed( string $value, string $type ): bool {
		return isset( self::PREFIXES[ $type ] ) && 1 === preg_match( '/^' . preg_quote( self::PREFIXES[ $type ], '/' ) . '[0-9a-f]{64}$/', $value );
	}

	/**
	 * Stored form of a token (64 hex characters).
	 */
	public static function hash( string $token ): string {
		return hash( 'sha256', $token );
	}

	/**
	 * Creates a token of the given type and stores its hash.
	 *
	 * @param string               $type          One of the type constants.
	 * @param int                  $connection_id 0 for an authorization request (no connection yet).
	 * @param int                  $ttl_seconds   Lifetime.
	 * @param array<string, mixed> $data          Bound parameters (request, code), stored as JSON.
	 * @return string The plain token, to hand to the client once; '' if the type is unknown or the write failed.
	 */
	public static function issue( string $type, int $connection_id, int $ttl_seconds, array $data = [] ): string {
		global $wpdb;

		if ( ! isset( self::PREFIXES[ $type ] ) ) {
			return '';
		}

		$token = self::generate( self::PREFIXES[ $type ] );
		$now   = time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the module's own table, no WordPress API to write to it.
		$inserted = $wpdb->insert(
			Store::table( Store::TABLE_TOKENS ),
			[
				'connection_id' => max( 0, $connection_id ),
				'type'          => $type,
				'token_hash'    => self::hash( $token ),
				'expires_at'    => gmdate( 'Y-m-d H:i:s', $now + max( 1, $ttl_seconds ) ),
				'used'          => 0,
				'created_at'    => gmdate( 'Y-m-d H:i:s', $now ),
				'data'          => empty( $data ) ? null : (string) wp_json_encode( $data ),
			],
			[ '%d', '%s', '%s', '%s', '%d', '%s', '%s' ]
		);

		return false === $inserted ? '' : $token;
	}

	/**
	 * Row of a token, whatever its expiry or use: the callers tell an expired
	 * token (normal, Claude refreshes) from a replayed one (theft, revoke the
	 * connection), so this lookup must not hide either.
	 *
	 * @return array{id: int, connection_id: int, type: string, token_hash: string, expires_at: string, used: int, used_at: string, created_at: string, data: array<string, mixed>}|null
	 */
	public static function find( string $token, string $type ): ?array {
		global $wpdb;

		// A value without the type's shape cannot be one of ours: no query.
		if ( ! self::is_well_formed( $token, $type ) ) {
			return null;
		}

		$hash  = self::hash( $token );
		$table = Store::table( Store::TABLE_TOKENS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name; a token must never be served from a cache.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token_hash = %s AND type = %s LIMIT 1", $hash, $type ), ARRAY_A );

		// The index lookup is not constant-time; the final comparison is, so
		// a collation quirk (case-insensitive match) cannot pass for a match.
		if ( ! is_array( $row ) || ! hash_equals( (string) $row['token_hash'], $hash ) ) {
			return null;
		}

		$data = is_string( $row['data'] ) ? json_decode( $row['data'], true ) : null;

		return [
			'id'            => (int) $row['id'],
			'connection_id' => (int) $row['connection_id'],
			'type'          => (string) $row['type'],
			'token_hash'    => (string) $row['token_hash'],
			'expires_at'    => (string) $row['expires_at'],
			'used'          => (int) $row['used'],
			'used_at'       => (string) ( $row['used_at'] ?? '' ),
			'created_at'    => (string) $row['created_at'],
			'data'          => is_array( $data ) ? $data : [],
		];
	}

	/**
	 * Marks a token used, once, and records when (`used_at`, UTC: the refresh
	 * grant tells a retried rotation from a replay by it).
	 *
	 * A single conditional UPDATE, not a read then a write: two requests
	 * racing with the same code or refresh token both read `used = 0`, but
	 * only one of them changes the row. The affected-row count says which;
	 * no object cache is involved.
	 *
	 * @return bool True if this call consumed the token, false if it was already used or does not exist.
	 */
	public static function consume( int $id ): bool {
		global $wpdb;

		$table = Store::table( Store::TABLE_TOKENS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name; atomic single-use check.
		$result = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET used = 1, used_at = %s WHERE id = %d AND used = 0", gmdate( 'Y-m-d H:i:s' ), $id ) );

		return false !== $result && 1 === (int) $wpdb->rows_affected;
	}

	/**
	 * Whether a used token was consumed at most `$seconds` ago. A token used
	 * before `used_at` existed (schema 1) has no date: never recent.
	 *
	 * @param array<string, mixed> $row A row from find().
	 */
	public static function used_within( array $row, int $seconds ): bool {
		$used_at = (string) ( $row['used_at'] ?? '' );
		if ( '' === $used_at || 0 === strpos( $used_at, '0000' ) ) {
			return false;
		}

		$used = strtotime( $used_at . ' UTC' );

		return false !== $used && time() - $used <= $seconds;
	}

	/**
	 * True when the token's expiry date (UTC) is reached.
	 *
	 * @param array<string, mixed> $row A row from find().
	 */
	public static function is_expired( array $row ): bool {
		$expires = strtotime( (string) ( $row['expires_at'] ?? '' ) . ' UTC' );

		return false === $expires || $expires <= time();
	}

	/**
	 * Deletes one token (RFC 7009 revocation of an access token).
	 */
	public static function delete( int $id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the module's own table.
		$wpdb->delete( Store::table( Store::TABLE_TOKENS ), [ 'id' => $id ], [ '%d' ] );
	}

	/**
	 * Deletes every token of a connection (codes, access, refresh).
	 *
	 * Connection 0 is the bucket of pending authorization requests: never
	 * swept from here.
	 */
	public static function revoke_connection_tokens( int $connection_id ): void {
		global $wpdb;

		if ( $connection_id <= 0 ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the module's own table.
		$wpdb->delete( Store::table( Store::TABLE_TOKENS ), [ 'connection_id' => $connection_id ], [ '%d' ] );
	}

	/**
	 * Access token lifetime in seconds (setting in minutes, 5 to 1440).
	 */
	public static function access_ttl(): int {
		$settings = Module::settings();

		return min( 1440, max( 5, (int) ( $settings['access_token_ttl'] ?? 60 ) ) ) * MINUTE_IN_SECONDS;
	}

	/**
	 * Refresh token lifetime in seconds (setting in days, 1 to 365), sliding:
	 * every rotation starts a new period.
	 */
	public static function refresh_ttl(): int {
		$settings = Module::settings();

		return min( 365, max( 1, (int) ( $settings['refresh_token_ttl'] ?? 30 ) ) ) * DAY_IN_SECONDS;
	}
}
