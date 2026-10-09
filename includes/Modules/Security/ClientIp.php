<?php
namespace Lumia\Tools\Modules\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Client IP resolution — single source of truth for the whole module.
 *
 * Proxy headers (CF-Connecting-IP, X-Forwarded-For) are sent by the client: on
 * a site that is NOT behind a proxy, anyone can forge them. Reading them
 * unconditionally makes rate limiting useless — changing the header on every
 * attempt is enough to start from a fresh counter — and lets an attacker
 * spoof an IP from the whitelist.
 *
 * They are therefore only read when the administrator has explicitly declared
 * the site topology (the "IP address source" setting). Default: REMOTE_ADDR,
 * the only value the client cannot forge.
 */
class ClientIp {

	/** IP sources offered in the settings. */
	const SOURCE_REMOTE_ADDR = 'remote_addr';
	const SOURCE_CLOUDFLARE  = 'cloudflare';
	const SOURCE_FORWARDED   = 'x_forwarded_for';

	const SOURCES = [
		self::SOURCE_REMOTE_ADDR,
		self::SOURCE_CLOUDFLARE,
		self::SOURCE_FORWARDED,
	];

	/** Value returned when no usable IP is available. */
	const UNKNOWN = '0.0.0.0';

	/**
	 * Returns the client IP according to the declared source.
	 *
	 * Any value that is not a valid IP falls back to REMOTE_ADDR: a missing or
	 * malformed proxy header must never produce an arbitrary counter key.
	 *
	 * @param string $source One of the SOURCE_* constants.
	 */
	public static function resolve( string $source = self::SOURCE_REMOTE_ADDR ): string {
		$remote = self::valid_ip( self::server( 'REMOTE_ADDR' ) );

		$candidate = '';

		if ( self::SOURCE_CLOUDFLARE === $source ) {
			$candidate = self::valid_ip( self::server( 'HTTP_CF_CONNECTING_IP' ) );
		} elseif ( self::SOURCE_FORWARDED === $source ) {
			$candidate = self::from_forwarded_for( self::server( 'HTTP_X_FORWARDED_FOR' ) );
		}

		if ( '' !== $candidate ) {
			return $candidate;
		}

		return '' !== $remote ? $remote : self::UNKNOWN;
	}

	/**
	 * Normalizes a source read from a form or an option.
	 *
	 * @param mixed $source
	 */
	public static function sanitize_source( $source ): string {
		$source = is_string( $source ) ? sanitize_key( $source ) : '';

		return in_array( $source, self::SOURCES, true ) ? $source : self::SOURCE_REMOTE_ADDR;
	}

	/**
	 * Proxy headers actually present on the current request.
	 *
	 * Only used to inform the administrator on the settings screen: they can
	 * pick their topology knowingly instead of guessing. NEVER use it to pick
	 * the source automatically — their presence is exactly what an attacker
	 * controls.
	 *
	 * @return array<string, string> Header name => raw value.
	 */
	public static function detected_headers(): array {
		$found = [];

		foreach ( [
			'HTTP_CF_CONNECTING_IP' => 'CF-Connecting-IP',
			'HTTP_X_FORWARDED_FOR'  => 'X-Forwarded-For',
		] as $key => $label ) {
			$value = self::server( $key );
			if ( '' !== $value ) {
				$found[ $label ] = $value;
			}
		}

		return $found;
	}

	/**
	 * Extracts the client IP from an X-Forwarded-For header.
	 *
	 * The header reads "client, proxy1, proxy2…": each intermediary appends on
	 * the right the address it saw. The LAST entry is therefore the one written
	 * by the proxy closest to us — the only one the client cannot have written
	 * itself. The entries on the left come as is from the incoming request.
	 */
	private static function from_forwarded_for( string $header ): string {
		if ( '' === $header ) {
			return '';
		}

		$parts = array_filter( array_map( 'trim', explode( ',', $header ) ) );
		if ( ! $parts ) {
			return '';
		}

		return self::valid_ip( (string) end( $parts ) );
	}

	/**
	 * Returns '' if the value is not a valid IP.
	 */
	private static function valid_ip( string $value ): string {
		return filter_var( $value, FILTER_VALIDATE_IP ) ? $value : '';
	}

	private static function server( string $key ): string {
		if ( empty( $_SERVER[ $key ] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
	}
}
