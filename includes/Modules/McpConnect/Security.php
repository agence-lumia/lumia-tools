<?php
namespace Lumia\Tools\Modules\McpConnect;

use Lumia\Tools\Modules\Security\ClientIp;
use Lumia\Tools\Modules\Security\RateLimiter;

defined( 'ABSPATH' ) || exit;

/**
 * Helpers shared by the token endpoints and the Bearer check: client address,
 * rate limiter, no-store headers.
 *
 * The Security module keeps its RateLimiter private and may be inactive, so
 * MCP Connect builds its own instance from the same option: same thresholds,
 * same IP source, and the same per-IP transient, so a lockout earned on the
 * login form also holds on the token endpoint, and the other way round.
 */
final class Security {

	/** Per-request memo of the limiter. */
	private static ?RateLimiter $limiter = null;

	/** True once limiter() has run (null is a valid memoized answer). */
	private static bool $limiter_built = false;

	/**
	 * Client address according to the source declared in the Security module,
	 * read even when that module is inactive: that is where the site's proxy
	 * configuration lives, and trusting forwarded headers without it would let
	 * anyone choose the address that gets counted or locked.
	 *
	 * @return string '' when unknown (WP-CLI, no REMOTE_ADDR).
	 */
	public static function client_ip(): string {
		// WP-CLI sets REMOTE_ADDR to 127.0.0.1 itself: a dummy address.
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ! isset( $_SERVER['REMOTE_ADDR'] ) ) {
			return '';
		}

		$security = get_option( 'lumia_module_security', [] );
		$source   = is_array( $security ) ? ( $security['authentication']['ip_source'] ?? '' ) : '';
		$ip       = ClientIp::resolve( ClientIp::sanitize_source( $source ) );

		return ClientIp::UNKNOWN === $ip ? '' : $ip;
	}

	/**
	 * Rate limiter built from the Security settings, or null when the site
	 * turned rate limiting off there (respected here too: an admin who
	 * disabled it, for instance behind a shared proxy address, does not want
	 * it back through this module).
	 *
	 * Callers check is_locked() before a token lookup and call
	 * log_failed_attempt() only for values that could never have been issued
	 * (wrong shape, see Tokens::is_well_formed()) and for a wrong PKCE
	 * verifier, never for a well-formed token that is merely unknown, expired
	 * or used. Never log_successful_login() either: that would wipe the
	 * address's failed login-form attempts.
	 */
	public static function limiter(): ?RateLimiter {
		if ( self::$limiter_built ) {
			return self::$limiter;
		}

		self::$limiter_built = true;

		$settings = Module::security_settings();
		$auth     = is_array( $settings['authentication'] ?? null ) ? $settings['authentication'] : [];

		if ( empty( $auth['rate_limiting'] ) ) {
			return null;
		}

		$whitelist = is_array( $auth['rate_limit_whitelist'] ?? null )
			? array_values( array_filter( $auth['rate_limit_whitelist'], 'is_string' ) )
			: [];

		self::$limiter = new RateLimiter(
			$whitelist,
			(int) ( $auth['rate_limit_attempts'] ?? 5 ),
			(int) ( $auth['rate_limit_window'] ?? 900 ),
			(int) ( $auth['rate_limit_lockout'] ?? 1800 ),
			(string) ( $auth['ip_source'] ?? ClientIp::SOURCE_REMOTE_ADDR )
		);

		return self::$limiter;
	}

	/**
	 * Fixed-window counter in a transient: takes one unit, false once `$limit`
	 * units were taken in the current window. The window start is stored with
	 * the count, so a write does not push the expiry back (set_transient()
	 * resets the TTL on every call). Not atomic: an approximate cap is enough
	 * for the throttles of the public endpoints.
	 *
	 * @param string $key    Transient name.
	 * @param int    $limit  Units per window.
	 * @param int    $window Window length in seconds.
	 */
	public static function take_counter( string $key, int $limit, int $window ): bool {
		$now   = time();
		$state = get_transient( $key );
		if ( ! is_array( $state ) || ! isset( $state['start'], $state['count'] ) || $now - (int) $state['start'] >= $window ) {
			$state = [
				'start' => $now,
				'count' => 0,
			];
		}

		if ( (int) $state['count'] >= $limit ) {
			return false;
		}

		$state['count'] = (int) $state['count'] + 1;
		set_transient( $key, $state, max( 1, $window - ( $now - (int) $state['start'] ) ) );

		return true;
	}

	/**
	 * Transient key of a per-IP counter: the prefix plus md5 of the address
	 * (`unknown` when there is none, so such requests share one counter).
	 */
	public static function ip_key( string $prefix ): string {
		$ip = self::client_ip();

		return $prefix . md5( '' !== $ip ? $ip : 'unknown' );
	}

	/**
	 * Forbids any cache from keeping the response: OAuth responses carry
	 * secrets (RFC 6749 section 5.1), and a page cache serving one user's
	 * consent screen or token to another would be a leak.
	 */
	public static function no_store_headers(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the cross-plugin page-cache convention (WP Rocket, W3TC, LiteSpeed...), its name is fixed.
		}

		if ( headers_sent() ) {
			return;
		}

		header( 'Cache-Control: no-store' );
		header( 'Pragma: no-cache' );
	}
}
