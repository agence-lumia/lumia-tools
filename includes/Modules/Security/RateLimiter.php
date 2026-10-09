<?php
namespace Lumia\Tools\Modules\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Per-IP rate limiter.
 * Stores attempts in WordPress transients (self-expiring TTL).
 */
class RateLimiter {

	private const TRANSIENT_PREFIX = '_lumia_rl_';
	private const DEFAULT_ATTEMPTS = 5;
	private const DEFAULT_WINDOW   = 900;   // 15 min
	private const DEFAULT_LOCKOUT  = 1800;  // 30 min
	private const TRANSIENT_TTL    = 86400; // 24 h max lifetime

	private int $max_attempts;
	private int $window;
	private int $lockout;
	/**
	 * @var string[]
	 */
	private array $whitelist;
	private string $ip_source;

	/**
	 * @param string[] $whitelist
	 */
	public function __construct( array $whitelist = [], int $max_attempts = self::DEFAULT_ATTEMPTS, int $window = self::DEFAULT_WINDOW, int $lockout = self::DEFAULT_LOCKOUT, string $ip_source = ClientIp::SOURCE_REMOTE_ADDR ) {
		$this->whitelist    = $whitelist;
		$this->max_attempts = max( 1, $max_attempts );
		$this->window       = max( 60, $window );
		$this->lockout      = max( 60, $lockout );
		$this->ip_source    = ClientIp::sanitize_source( $ip_source );
	}

	/**
	 * Client IP, resolved by ClientIp.
	 *
	 * A single implementation for the whole module: the counter must be
	 * incremented on exactly the same key the lockout reads, otherwise we
	 * count attempts that are never read back.
	 */
	public function get_client_ip(): string {
		return ClientIp::resolve( $this->ip_source );
	}

	public function is_whitelisted(): bool {
		return in_array( $this->get_client_ip(), $this->whitelist, true );
	}

	private function get_transient_key( string $ip ): string {
		return self::TRANSIENT_PREFIX . md5( $ip );
	}

	/**
	 * @return array<string, int>
	 */
	private function get_attempt_data( string $ip ): array {
		$data = get_transient( $this->get_transient_key( $ip ) );
		if ( ! is_array( $data ) ) {
			return [
				'count'        => 0,
				'last_attempt' => 0,
				'locked_until' => 0,
			];
		}
		return [
			'count'        => $data['count'] ?? 0,
			'last_attempt' => $data['last_attempt'] ?? 0,
			'locked_until' => $data['locked_until'] ?? 0,
		];
	}

	private function record_attempt( string $ip, bool $success = false ): void {
		$key  = $this->get_transient_key( $ip );
		$data = $this->get_attempt_data( $ip );
		$now  = time();

		if ( $success ) {
			delete_transient( $key );
			return;
		}

		// Window expired → reset the counter
		if ( $data['last_attempt'] > 0 && ( $now - $data['last_attempt'] ) > $this->window ) {
			$data = [
				'count'        => 0,
				'last_attempt' => 0,
				'locked_until' => 0,
			];
		}

		++$data['count'];
		$data['last_attempt'] = $now;
		$data['locked_until'] = ( $data['count'] >= $this->max_attempts ) ? $now + $this->lockout : 0;

		set_transient( $key, $data, self::TRANSIENT_TTL );
	}

	/**
	 * @param null|\WP_User|\WP_Error $user
	 * @return null|\WP_User|\WP_Error
	 */
	public function maybe_block_login( $user ) {
		if ( $this->is_whitelisted() ) {
			return $user;
		}

		$ip   = $this->get_client_ip();
		$data = $this->get_attempt_data( $ip );
		$now  = time();

		if ( $data['locked_until'] > 0 && $now < $data['locked_until'] ) {
			$remaining_minutes = (int) ceil( ( $data['locked_until'] - $now ) / 60 );
			return new \WP_Error(
				'too_many_attempts',
				sprintf(
					/* translators: %d: minutes left before the lockout ends. */
					__( '<b>Access blocked:</b> Too many login attempts. Try again in %d minute(s).', 'lumia-tools' ),
					$remaining_minutes
				)
			);
		}

		return $user;
	}

	/**
	 * Is the current IP locked out?
	 *
	 * Extracted from maybe_block_login() for the authentication paths that do
	 * not go through the `authenticate` filter and so have no WP_Error to
	 * return — application passwords, notably.
	 */
	public function is_locked(): bool {
		if ( $this->is_whitelisted() ) {
			return false;
		}

		$data = $this->get_attempt_data( $this->get_client_ip() );

		return $data['locked_until'] > 0 && time() < $data['locked_until'];
	}

	public function log_failed_attempt( string $ip ): void {
		$this->record_attempt( $ip, false );
	}

	public function log_successful_login( string $ip ): void {
		$this->record_attempt( $ip, true );
	}
}
