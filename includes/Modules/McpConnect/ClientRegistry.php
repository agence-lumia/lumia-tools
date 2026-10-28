<?php
namespace Lumia\Tools\Modules\McpConnect;

use Lumia\Tools\Core\Compat;

defined( 'ABSPATH' ) || exit;

/**
 * OAuth clients: Client ID Metadata Documents (CIMD) and Dynamic Client
 * Registration (DCR).
 *
 * CIMD first (MCP spec 2026-07-28): a `client_id` that is an HTTPS URL is the
 * address of a JSON document describing the client. Fetching it means making
 * the site issue an HTTP request to a URL chosen by whoever opens the
 * authorize endpoint, i.e. anyone: every SSRF guard below exists for that.
 * In order, before any byte leaves the server: URL shape (https, port 443,
 * DNS name), trusted publisher, negative cache, per-IP budget, site-wide
 * budget (unknown clients only), DNS resolution with every address checked,
 * then a request pinned to the checked address.
 *
 * DCR (RFC 7591) is Claude's fallback when CIMD is not advertised. It is off
 * by default, rate-limited per IP, and its clients are never trusted.
 *
 * Client array shape, as returned by resolve():
 * `[ 'client_id', 'type' ('cimd'|'dcr'), 'name', 'logo_uri', 'client_uri',
 * 'publisher' (CIMD host, '' for DCR), 'redirect_uris' => string[] ]`.
 *
 * @phpstan-type Client array{client_id: string, type: string, name: string, logo_uri: string, client_uri: string, publisher: string, redirect_uris: string[]}
 */
final class ClientRegistry {

	/** Client types stored in the `type` column. */
	const TYPE_CIMD = 'cimd';
	const TYPE_DCR  = 'dcr';

	/** Prefix of the client ids issued by DCR. */
	const DCR_PREFIX = 'lmc_cl_';

	/**
	 * Longest client id accepted. The unique key of the clients table covers
	 * the first 191 characters only (utf8mb4 index limit): two longer URLs
	 * sharing that prefix would collide, so nothing longer is accepted.
	 */
	const MAX_CLIENT_ID_LENGTH = 191;

	/** Largest metadata document accepted, in bytes. */
	const MAX_DOCUMENT_SIZE = 65536;

	/** Seconds a failing CIMD URL is not fetched again. */
	const NEGATIVE_CACHE_TTL = 60;

	/** Transient prefix of the negative cache (followed by md5 of the URL). */
	const NEGATIVE_CACHE_PREFIX = 'lumia_mcp_cimd_fail_';

	/** Transient counting the CIMD fetches of the current minute. */
	const BUDGET_TRANSIENT = 'lumia_mcp_cimd_budget';

	/**
	 * CIMD fetches allowed per minute for the whole site: the authorize
	 * endpoint is public, and without a cap it would turn the site into a
	 * request relay towards any HTTPS host.
	 */
	const BUDGET_PER_MINUTE = 20;

	/** Transient prefix of the per-IP CIMD fetch counter (followed by md5 of the IP). */
	const IP_BUDGET_PREFIX = 'lumia_mcp_cimd_ip_';

	/** CIMD fetches allowed per IP and per minute, in front of the site-wide budget. */
	const IP_BUDGET_PER_MINUTE = 5;

	/** Seconds a known client's expired row is still served when it cannot be refetched. */
	const STALE_GRACE = DAY_IN_SECONDS;

	/** Cache lifetime bounds of a metadata document, in seconds. */
	const CACHE_TTL_MIN     = 300;
	const CACHE_TTL_MAX     = 86400;
	const CACHE_TTL_DEFAULT = 3600;

	/** Transient prefix of the DCR per-IP counter (followed by md5 of the IP). */
	const DCR_RATE_PREFIX = 'lumia_mcp_dcr_';

	/** DCR registrations allowed per IP and per hour. */
	const DCR_RATE_LIMIT = 10;

	/** Most redirect URIs accepted in one client description. */
	const MAX_REDIRECT_URIS = 20;

	/** Longest redirect URI accepted. */
	const MAX_REDIRECT_URI_LENGTH = 2000;

	/** Longest client name kept. */
	const MAX_NAME_LENGTH = 100;

	/** Hosts of a loopback redirect URI (RFC 8252 section 7.3), as wp_parse_url() returns them. */
	const LOOPBACK_HOSTS = [ 'localhost', '127.0.0.1', '[::1]' ];

	/**
	 * Resolves a client id to its description.
	 *
	 * An HTTPS URL is a CIMD (cached in the clients table, fetched otherwise),
	 * an `lmc_cl_...` id a DCR registration. Anything else is refused. Error
	 * messages are meant for the person who opened the authorize link: the
	 * authorization server shows them on an HTML page, never in a redirect.
	 *
	 * @return array<string, mixed>|\WP_Error Client array (see the class docblock), or the reason of the refusal.
	 */
	public static function resolve( string $client_id ) {
		if ( 1 === preg_match( '/^' . self::DCR_PREFIX . '[0-9a-f]{32}$/', $client_id ) ) {
			return self::resolve_dcr( $client_id );
		}

		$host = self::cimd_host( $client_id );
		if ( '' === $host ) {
			return new \WP_Error(
				'invalid_client',
				__( 'The application did not identify itself with a valid client ID. Only HTTPS client metadata URLs on the standard port are accepted.', 'lumia-tools' ),
				[ 'status' => 400 ]
			);
		}

		// Before any fetch: with the default setting, a URL outside the trusted
		// publishers never makes the site issue a request.
		$settings = Module::settings();
		if ( ! empty( $settings['trusted_only'] ) && ! in_array( $host, self::trusted_publishers(), true ) ) {
			return new \WP_Error(
				'untrusted_client',
				sprintf(
					/* translators: %s: host name of the application that asked for the connection. */
					__( 'This site only accepts connections from Claude. The application asking to connect is published by %s, which is not a trusted publisher.', 'lumia-tools' ),
					$host
				),
				[ 'status' => 400 ]
			);
		}

		$now    = time();
		$cached = self::find_row( $client_id, self::TYPE_CIMD, $now );
		if ( null !== $cached ) {
			self::touch( $client_id );
			return $cached;
		}

		// A client already in the table (its cache expired) is a known one:
		// it is refetched outside the site-wide budget, which anonymous
		// callers can exhaust with random claude.ai paths, and its stale row
		// keeps working for a while when the refetch cannot happen or fails.
		$known = null !== self::find_row( $client_id, self::TYPE_CIMD, null );
		$stale = $known ? self::find_row( $client_id, self::TYPE_CIMD, $now - self::STALE_GRACE ) : null;

		$failure = get_transient( self::NEGATIVE_CACHE_PREFIX . md5( $client_id ) );
		if ( is_array( $failure ) && isset( $failure['code'], $failure['message'] ) ) {
			return $stale ?? new \WP_Error( (string) $failure['code'], (string) $failure['message'], [ 'status' => 400 ] );
		}

		$busy = new \WP_Error(
			'temporarily_unavailable',
			__( 'Too many connection attempts right now. Wait a minute and try again.', 'lumia-tools' ),
			[ 'status' => 503 ]
		);

		// Per address first, so one caller cannot spend the site-wide budget
		// alone; then the site-wide budget, for unknown clients only.
		if ( ! Security::take_counter( Security::ip_key( self::IP_BUDGET_PREFIX ), self::IP_BUDGET_PER_MINUTE, MINUTE_IN_SECONDS ) ) {
			return $stale ?? $busy;
		}

		if ( ! $known && ! self::take_budget() ) {
			return $busy;
		}

		$client = self::fetch_cimd( $client_id, $host );
		if ( is_wp_error( $client ) ) {
			// A failing URL is not fetched again for a minute: without this,
			// every reload of the authorize link would spend the budget.
			set_transient(
				self::NEGATIVE_CACHE_PREFIX . md5( $client_id ),
				[
					'code'    => $client->get_error_code(),
					'message' => $client->get_error_message(),
				],
				self::NEGATIVE_CACHE_TTL
			);
			return $stale ?? $client;
		}

		return $client;
	}

	/**
	 * Trusted publishers: hosts whose metadata documents identify Claude.
	 *
	 * @return string[] Lowercased host names.
	 */
	public static function trusted_publishers(): array {
		$hosts = Compat::apply_filters( 'mcp_connect_trusted_publishers', [ 'claude.ai' ] );
		if ( ! is_array( $hosts ) ) {
			return [];
		}

		$clean = [];
		foreach ( $hosts as $host ) {
			if ( is_string( $host ) && '' !== trim( $host ) ) {
				$clean[] = strtolower( trim( $host ) );
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Whether a client is served by a trusted publisher. A DCR client is never
	 * trusted: anyone can register one under any name.
	 *
	 * @param array<string, mixed> $client Client array.
	 */
	public static function is_trusted( array $client ): bool {
		if ( self::TYPE_CIMD !== ( $client['type'] ?? '' ) ) {
			return false;
		}

		$publisher = strtolower( (string) ( $client['publisher'] ?? '' ) );

		return '' !== $publisher && in_array( $publisher, self::trusted_publishers(), true );
	}

	/**
	 * Whether a redirect URI is one the client registered.
	 *
	 * Exact string match, except loopback URIs: a native app (Claude Code)
	 * listens on an ephemeral port, so RFC 8252 section 7.3 asks the server to
	 * ignore the port. Host, path and query must still match exactly.
	 *
	 * @param array<string, mixed> $client Client array.
	 */
	public static function redirect_uri_allowed( array $client, string $uri ): bool {
		$registered = self::client_redirect_uris( $client );
		if ( '' === $uri || [] === $registered ) {
			return false;
		}

		if ( in_array( $uri, $registered, true ) ) {
			return true;
		}

		$candidate = self::parse_loopback( $uri );
		if ( null === $candidate ) {
			return false;
		}

		foreach ( $registered as $allowed ) {
			$expected = self::parse_loopback( $allowed );
			if ( null === $expected ) {
				continue;
			}

			if ( $candidate['host'] === $expected['host'] && $candidate['path'] === $expected['path'] && $candidate['query'] === $expected['query'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether every redirect URI of the client is a loopback one: the code then
	 * goes to an application running on the user's computer, which the consent
	 * screen says.
	 *
	 * @param array<string, mixed> $client Client array.
	 */
	public static function only_loopback( array $client ): bool {
		$uris = self::client_redirect_uris( $client );
		if ( [] === $uris ) {
			return false;
		}

		foreach ( $uris as $uri ) {
			if ( null === self::parse_loopback( $uri ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Registers a client (RFC 7591). The caller checks that DCR is enabled.
	 *
	 * @param array<string, mixed> $metadata Decoded JSON body of the request.
	 * @param string               $ip       Client IP, for the rate limit and the record.
	 * @return array<string, mixed>|\WP_Error RFC 7591 response body (sent with status 201), or an error whose data carries the HTTP status.
	 */
	public static function register_dcr( array $metadata, string $ip ) {
		$limiter = Security::limiter();
		if ( null !== $limiter && $limiter->is_locked() ) {
			return new \WP_Error(
				'temporarily_unavailable',
				__( 'Too many attempts. Try again later.', 'lumia-tools' ),
				[ 'status' => 429 ]
			);
		}

		// Counted on every attempt, valid or not: the counter bounds the work
		// an anonymous caller can make the site do, not only the rows it adds.
		if ( ! Security::take_counter( self::DCR_RATE_PREFIX . md5( $ip ), self::DCR_RATE_LIMIT, HOUR_IN_SECONDS ) ) {
			return new \WP_Error(
				'temporarily_unavailable',
				__( 'Too many client registrations from this address. Try again later.', 'lumia-tools' ),
				[ 'status' => 429 ]
			);
		}

		$redirect_uris = self::validate_redirect_uris( $metadata['redirect_uris'] ?? null );
		if ( null === $redirect_uris ) {
			return new \WP_Error(
				'invalid_redirect_uri',
				__( 'redirect_uris must be a non-empty list of HTTPS URLs or loopback HTTP URLs.', 'lumia-tools' ),
				[ 'status' => 400 ]
			);
		}

		if ( isset( $metadata['token_endpoint_auth_method'] ) && 'none' !== $metadata['token_endpoint_auth_method'] ) {
			return self::invalid_metadata( __( 'Only public clients are supported: token_endpoint_auth_method must be "none".', 'lumia-tools' ) );
		}

		if ( isset( $metadata['grant_types'] ) && ! self::is_subset( $metadata['grant_types'], [ 'authorization_code', 'refresh_token' ] ) ) {
			return self::invalid_metadata( __( 'Only the authorization_code and refresh_token grant types are supported.', 'lumia-tools' ) );
		}

		if ( isset( $metadata['response_types'] ) && ! self::is_subset( $metadata['response_types'], [ 'code' ] ) ) {
			return self::invalid_metadata( __( 'Only the code response type is supported.', 'lumia-tools' ) );
		}

		$fallback = (string) wp_parse_url( $redirect_uris[0], PHP_URL_HOST );
		$name     = self::clean_name( $metadata['client_name'] ?? null, $fallback );

		global $wpdb;

		$client_id = self::DCR_PREFIX . bin2hex( random_bytes( 16 ) );
		$now       = time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the module's own table, no WordPress API to write to it.
		$inserted = $wpdb->insert(
			Store::table( Store::TABLE_CLIENTS ),
			[
				'client_id'       => $client_id,
				'type'            => self::TYPE_DCR,
				'name'            => $name,
				'logo_uri'        => '',
				'client_uri'      => '',
				'publisher'       => '',
				'redirect_uris'   => (string) wp_json_encode( $redirect_uris ),
				'created_at'      => gmdate( 'Y-m-d H:i:s', $now ),
				'registration_ip' => substr( $ip, 0, 45 ),
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		if ( false === $inserted ) {
			return new \WP_Error(
				'server_error',
				__( 'The client could not be registered.', 'lumia-tools' ),
				[ 'status' => 500 ]
			);
		}

		do_action( 'lumia_mcp_connect_client_registered', $client_id, $name );

		return [
			'client_id'                  => $client_id,
			'client_id_issued_at'        => $now,
			'client_name'                => $name,
			'redirect_uris'              => $redirect_uris,
			'grant_types'                => [ 'authorization_code', 'refresh_token' ],
			'response_types'             => [ 'code' ],
			'token_endpoint_auth_method' => 'none',
		];
	}

	/**
	 * Host of a CIMD client id, or '' if the URL is not acceptable.
	 *
	 * https only, no userinfo, no fragment, port absent or 443, a path that is
	 * more than `/` without dot segments, and a DNS name: an IP literal would
	 * skip the resolution check and is never what a publisher uses.
	 */
	private static function cimd_host( string $url ): string {
		if ( strlen( $url ) > self::MAX_CLIENT_ID_LENGTH || 1 === preg_match( '/[\s\x00-\x1f\x7f\\\\]/', $url ) ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) ) {
			return '';
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) || false !== strpos( $url, '#' ) ) {
			return '';
		}

		if ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) {
			return '';
		}

		$path = (string) ( $parts['path'] ?? '' );
		if ( '' === $path || '/' === $path || 1 === preg_match( '#(^|/)\.{1,2}(/|$)#', $path ) ) {
			return '';
		}

		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( ! self::is_dns_name( $host ) ) {
			return '';
		}

		return $host;
	}

	/**
	 * Whether a host is a fully qualified DNS name: dotted labels and an
	 * alphabetic (or punycode) top-level label, so IPv4 literals, bracketed
	 * IPv6 literals and single-label names such as `localhost` all fail.
	 */
	private static function is_dns_name( string $host ): bool {
		if ( '' === $host || strlen( $host ) > 253 ) {
			return false;
		}

		return 1 === preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/', $host );
	}

	/**
	 * Fetches, validates and caches a metadata document.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function fetch_cimd( string $url, string $host ) {
		$unreachable = new \WP_Error(
			'invalid_client',
			sprintf(
				/* translators: %s: host name of the application that asked for the connection. */
				__( 'The description of the application published by %s could not be retrieved.', 'lumia-tools' ),
				$host
			),
			[ 'status' => 400 ]
		);

		// CURLOPT_RESOLVE is the only way to pin the address the HTTP API
		// connects to. Without cURL the request would resolve the host again,
		// after our check: a DNS rebinding window we refuse to open.
		if ( ! function_exists( 'curl_init' ) ) {
			return $unreachable;
		}

		$ip = self::resolve_public_ip( $host );
		if ( '' === $ip ) {
			return new \WP_Error(
				'invalid_client',
				sprintf(
					/* translators: %s: host name of the application that asked for the connection. */
					__( 'The application address %s does not resolve to a public server.', 'lumia-tools' ),
					$host
				),
				[ 'status' => 400 ]
			);
		}

		$pinned = false;
		$pin    = static function ( $handle, $parsed_args, $request_url ) use ( $host, $ip, &$pinned ): void {
			// The action is only attached around our own request, but a
			// mismatched host must never be pinned to this address.
			if ( ! is_string( $request_url ) || strtolower( (string) wp_parse_url( $request_url, PHP_URL_HOST ) ) !== $host ) {
				return;
			}

			$address = false !== strpos( $ip, ':' ) ? '[' . $ip . ']' : $ip;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- pins the resolved address on the handle the HTTP API built; no WordPress API exposes CURLOPT_RESOLVE.
			$pinned = curl_setopt( $handle, CURLOPT_RESOLVE, [ $host . ':443:' . $address ] );
		};

		add_action( 'http_api_curl', $pin, 10, 3 );
		try {
			// wp_safe_remote_get() also rejects private hosts, but it only
			// checks IPv4 and resolves on its own: the checks above are the
			// real guard, this one is a second layer.
			$response = wp_safe_remote_get(
				$url,
				[
					'timeout'             => 5,
					'redirection'         => 0,
					'limit_response_size' => self::MAX_DOCUMENT_SIZE,
					'sslverify'           => true,
					'headers'             => [ 'Accept' => 'application/json' ],
					'user-agent'          => 'Lumia-Tools-MCP-Connect',
				]
			);
		} finally {
			remove_action( 'http_api_curl', $pin, 10 );
		}

		// Not pinned (another transport answered, or curl_setopt failed): the
		// connection went to an address we did not check, so the answer is
		// discarded.
		if ( ! $pinned || is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return $unreachable;
		}

		$body = wp_remote_retrieve_body( $response );
		// limit_response_size truncates silently: a body that fills the limit
		// may be cut, so it is refused rather than parsed.
		if ( '' === $body || strlen( $body ) >= self::MAX_DOCUMENT_SIZE ) {
			return $unreachable;
		}

		$document = json_decode( $body, true );
		if ( ! is_array( $document ) || ( [] !== $document && array_values( $document ) === $document ) ) {
			return $unreachable;
		}

		$client = self::validate_document( $document, $url, $host );
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$ttl = self::cache_ttl( wp_remote_retrieve_header( $response, 'cache-control' ) );
		self::save_cimd( $client, $ttl );

		return $client;
	}

	/**
	 * Validates a metadata document and builds the client array.
	 *
	 * Unknown fields and unsupported grant types are ignored, never rejected:
	 * claude.ai lists `urn:ietf:params:oauth:grant-type:jwt-bearer`, which we
	 * simply never honor.
	 *
	 * @param array<mixed> $document Decoded JSON object.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function validate_document( array $document, string $url, string $host ) {
		if ( ! isset( $document['client_id'] ) || $url !== $document['client_id'] ) {
			return new \WP_Error(
				'invalid_client',
				__( 'The application description does not match its client ID.', 'lumia-tools' ),
				[ 'status' => 400 ]
			);
		}

		$redirect_uris = self::validate_redirect_uris( $document['redirect_uris'] ?? null );
		if ( null === $redirect_uris ) {
			return new \WP_Error(
				'invalid_client',
				__( 'The application description has no valid redirect URI.', 'lumia-tools' ),
				[ 'status' => 400 ]
			);
		}

		if ( isset( $document['token_endpoint_auth_method'] ) && 'none' !== $document['token_endpoint_auth_method'] ) {
			return new \WP_Error(
				'invalid_client',
				__( 'The application requires a client authentication method this site does not support. Only public clients can connect.', 'lumia-tools' ),
				[ 'status' => 400 ]
			);
		}

		return [
			'client_id'     => $url,
			'type'          => self::TYPE_CIMD,
			'name'          => self::clean_name( $document['client_name'] ?? null, $host ),
			'logo_uri'      => self::clean_https_url( $document['logo_uri'] ?? null ),
			'client_uri'    => self::clean_https_url( $document['client_uri'] ?? null ),
			'publisher'     => $host,
			'redirect_uris' => $redirect_uris,
		];
	}

	/**
	 * Resolves a host and returns the address to connect to, or '' when the
	 * host resolves to nothing or to ANY non-public address: one private A or
	 * AAAA record is enough to refuse, since the resolver could hand out that
	 * one at connection time.
	 *
	 * IPv4 is preferred for the pinned address (more hosts have IPv4 egress).
	 */
	private static function resolve_public_ip( string $host ): string {
		$addresses = [];

		// dns_get_record() emits a warning on resolution failure; the failure
		// itself is handled through its return value.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
		$records = @dns_get_record( $host, DNS_A | DNS_AAAA );
		if ( is_array( $records ) ) {
			foreach ( $records as $record ) {
				if ( isset( $record['ip'] ) && is_string( $record['ip'] ) ) {
					$addresses[] = $record['ip'];
				} elseif ( isset( $record['ipv6'] ) && is_string( $record['ipv6'] ) ) {
					$addresses[] = $record['ipv6'];
				}
			}
		}

		if ( [] === $addresses ) {
			$fallback = gethostbynamel( $host );
			if ( is_array( $fallback ) ) {
				$addresses = $fallback;
			}
		}

		if ( [] === $addresses ) {
			return '';
		}

		$ipv4 = '';
		$ipv6 = '';
		foreach ( $addresses as $address ) {
			if ( ! self::is_public_ip( $address ) ) {
				return '';
			}
			if ( false === strpos( $address, ':' ) ) {
				$ipv4 = '' === $ipv4 ? $address : $ipv4;
			} else {
				$ipv6 = '' === $ipv6 ? $address : $ipv6;
			}
		}

		return '' !== $ipv4 ? $ipv4 : $ipv6;
	}

	/**
	 * Whether an IP address is publicly routable.
	 *
	 * filter_var() covers the private and reserved ranges, but its lists vary
	 * between PHP versions and it does not look inside IPv6 addresses that
	 * embed an IPv4 one (`::ffff:10.0.0.1` reaches 10.0.0.1). The explicit
	 * ranges below close both gaps: carrier-grade NAT, documentation and
	 * benchmark ranges, and the IPv4-mapped, NAT64 and 6to4 forms, whose
	 * embedded address is checked in turn.
	 */
	public static function is_public_ip( string $ip ): bool {
		$binary = inet_pton( $ip );
		if ( false === $binary || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		if ( 16 === strlen( $binary ) ) {
			// IPv4-mapped ::ffff:0:0/96 and NAT64 64:ff9b::/96: the last 32 bits.
			if ( self::in_cidr( $binary, '::ffff:0:0', 96 ) || self::in_cidr( $binary, '64:ff9b::', 96 ) ) {
				return self::is_public_ip( (string) inet_ntop( substr( $binary, 12, 4 ) ) );
			}
			// 6to4 2002::/16: bits 16 to 48.
			if ( self::in_cidr( $binary, '2002::', 16 ) ) {
				return self::is_public_ip( (string) inet_ntop( substr( $binary, 2, 4 ) ) );
			}
		}

		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}

		$blocked = 4 === strlen( $binary )
			? [
				[ '0.0.0.0', 8 ],
				[ '10.0.0.0', 8 ],
				[ '100.64.0.0', 10 ],
				[ '127.0.0.0', 8 ],
				[ '169.254.0.0', 16 ],
				[ '172.16.0.0', 12 ],
				[ '192.0.0.0', 24 ],
				[ '192.0.2.0', 24 ],
				[ '192.88.99.0', 24 ],
				[ '192.168.0.0', 16 ],
				[ '198.18.0.0', 15 ],
				[ '198.51.100.0', 24 ],
				[ '203.0.113.0', 24 ],
				[ '224.0.0.0', 4 ],
				[ '240.0.0.0', 4 ],
			]
			: [
				[ '::', 96 ],        // Unspecified, loopback and the deprecated IPv4-compatible form.
				[ '100::', 64 ],     // Discard prefix.
				[ '2001::', 23 ],    // IETF protocol assignments, Teredo included.
				[ '2001:db8::', 32 ], // Documentation.
				[ 'fc00::', 7 ],     // Unique local.
				[ 'fe80::', 10 ],    // Link-local.
				[ 'fec0::', 10 ],    // Deprecated site-local.
				[ 'ff00::', 8 ],     // Multicast.
			];

		foreach ( $blocked as $range ) {
			if ( self::in_cidr( $binary, $range[0], $range[1] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a packed address belongs to a range of the same family.
	 */
	private static function in_cidr( string $binary, string $network, int $bits ): bool {
		$net = inet_pton( $network );
		if ( false === $net || strlen( $net ) !== strlen( $binary ) ) {
			return false;
		}

		$bytes = intdiv( $bits, 8 );
		if ( 0 < $bytes && substr( $binary, 0, $bytes ) !== substr( $net, 0, $bytes ) ) {
			return false;
		}

		$rest = $bits % 8;
		if ( 0 === $rest ) {
			return true;
		}

		$mask = ( 0xff << ( 8 - $rest ) ) & 0xff;

		return ( ord( $binary[ $bytes ] ) & $mask ) === ( ord( $net[ $bytes ] ) & $mask );
	}

	/**
	 * Validates a list of redirect URIs: each one an absolute https URL, or a
	 * loopback http URL (RFC 8252). No userinfo, no fragment (RFC 6749 3.1.2).
	 *
	 * @param mixed $uris Raw value from the document or the request.
	 * @return string[]|null The list, or null if anything is wrong.
	 */
	private static function validate_redirect_uris( $uris ): ?array {
		if ( ! is_array( $uris ) || [] === $uris || array_values( $uris ) !== $uris || count( $uris ) > self::MAX_REDIRECT_URIS ) {
			return null;
		}

		$clean = [];
		foreach ( $uris as $uri ) {
			if ( ! is_string( $uri ) || ! self::is_valid_redirect_uri( $uri ) ) {
				return null;
			}
			$clean[] = $uri;
		}

		return array_values( array_unique( $clean ) );
	}

	private static function is_valid_redirect_uri( string $uri ): bool {
		if ( '' === $uri || strlen( $uri ) > self::MAX_REDIRECT_URI_LENGTH || 1 === preg_match( '/[\s\x00-\x1f\x7f\\\\]/', $uri ) || false !== strpos( $uri, '#' ) ) {
			return false;
		}

		$parts = wp_parse_url( $uri );
		if ( ! is_array( $parts ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || '' === (string) ( $parts['host'] ?? '' ) ) {
			return false;
		}

		$scheme = $parts['scheme'] ?? '';
		if ( 'https' === $scheme ) {
			return true;
		}

		return 'http' === $scheme && null !== self::parse_loopback( $uri );
	}

	/**
	 * Parts of a loopback http redirect URI used for the port-agnostic match,
	 * or null if the URI is not one.
	 *
	 * Also the consent screen's loopback test (notice and interstitial): one
	 * rule for validation and rendering, host compared case-insensitively.
	 *
	 * @return array{host: string, path: string, query: string}|null
	 */
	public static function parse_loopback( string $uri ): ?array {
		if ( false !== strpos( $uri, '#' ) ) {
			return null;
		}

		$parts = wp_parse_url( $uri );
		if ( ! is_array( $parts ) || 'http' !== ( $parts['scheme'] ?? '' ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}

		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( ! in_array( $host, self::LOOPBACK_HOSTS, true ) ) {
			return null;
		}

		return [
			'host'  => $host,
			'path'  => (string) ( $parts['path'] ?? '' ),
			'query' => (string) ( $parts['query'] ?? '' ),
		];
	}

	/**
	 * Redirect URIs of a client array, strings only.
	 *
	 * @param array<string, mixed> $client
	 * @return string[]
	 */
	private static function client_redirect_uris( array $client ): array {
		$uris = $client['redirect_uris'] ?? [];
		if ( ! is_array( $uris ) ) {
			return [];
		}

		return array_values( array_filter( $uris, 'is_string' ) );
	}

	/**
	 * Display name of a client: sanitized, at most 100 characters, the
	 * fallback (a host) when missing.
	 *
	 * @param mixed $name Raw value.
	 */
	private static function clean_name( $name, string $fallback ): string {
		$clean = is_string( $name ) ? trim( sanitize_text_field( $name ) ) : '';
		if ( '' === $clean ) {
			$clean = $fallback;
		}

		return mb_substr( $clean, 0, self::MAX_NAME_LENGTH );
	}

	/**
	 * An https URL fit for the 255-character columns, or ''.
	 *
	 * @param mixed $url Raw value.
	 */
	private static function clean_https_url( $url ): string {
		if ( ! is_string( $url ) || strlen( $url ) > 255 || 0 !== strpos( $url, 'https://' ) ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || '' === (string) ( $parts['host'] ?? '' ) ) {
			return '';
		}

		$clean = esc_url_raw( $url, [ 'https' ] );

		return strlen( $clean ) <= 255 ? $clean : '';
	}

	/**
	 * Cache lifetime from a Cache-Control header: max-age clamped to
	 * [5 min, 1 day], one hour without it. The floor protects the budget from
	 * a publisher sending max-age=0.
	 *
	 * @param string|string[] $header Header value(s), as wp_remote_retrieve_header() returns it.
	 */
	private static function cache_ttl( $header ): int {
		$value = is_array( $header ) ? implode( ',', $header ) : (string) $header;
		if ( 1 !== preg_match( '/(?:^|[\s,])max-age\s*=\s*"?(\d+)/i', $value, $match ) ) {
			return self::CACHE_TTL_DEFAULT;
		}

		return max( self::CACHE_TTL_MIN, min( self::CACHE_TTL_MAX, (int) $match[1] ) );
	}

	/**
	 * Takes one fetch from the site-wide budget of the current minute.
	 */
	private static function take_budget(): bool {
		return Security::take_counter( self::BUDGET_TRANSIENT, self::BUDGET_PER_MINUTE, MINUTE_IN_SECONDS );
	}

	/**
	 * Resolves a DCR client id. DCR clients are refused while DCR is off: the
	 * setting is the switch for the whole mechanism, not only for new
	 * registrations.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function resolve_dcr( string $client_id ) {
		$settings = Module::settings();
		$client   = empty( $settings['dcr_enabled'] ) ? null : self::find_row( $client_id, self::TYPE_DCR, null );

		if ( null === $client ) {
			return new \WP_Error(
				'invalid_client',
				__( 'This application is not registered on this site.', 'lumia-tools' ),
				[ 'status' => 400 ]
			);
		}

		self::touch( $client_id );

		return $client;
	}

	/**
	 * Reads a client row.
	 *
	 * @param int|null $expires_after CIMD: only a row whose cache expires after this Unix time; null for any row.
	 * @return array<string, mixed>|null Client array.
	 */
	private static function find_row( string $client_id, string $type, ?int $expires_after ): ?array {
		global $wpdb;

		$table = Store::table( Store::TABLE_CLIENTS );

		if ( null !== $expires_after ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the module's own table; its name comes from $wpdb->prefix only.
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE client_id = %s AND type = %s AND expires_at > %s LIMIT 1", $client_id, $type, gmdate( 'Y-m-d H:i:s', $expires_after ) ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see above.
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE client_id = %s AND type = %s LIMIT 1", $client_id, $type ), ARRAY_A );
		}

		// The SQL comparison is case-insensitive under the usual collations:
		// the id must match byte for byte.
		if ( ! is_array( $row ) || ! hash_equals( (string) ( $row['client_id'] ?? '' ), $client_id ) ) {
			return null;
		}

		$uris = json_decode( (string) ( $row['redirect_uris'] ?? '' ), true );

		return [
			'client_id'     => $client_id,
			'type'          => (string) $row['type'],
			'name'          => (string) ( $row['name'] ?? '' ),
			'logo_uri'      => (string) ( $row['logo_uri'] ?? '' ),
			'client_uri'    => (string) ( $row['client_uri'] ?? '' ),
			'publisher'     => (string) ( $row['publisher'] ?? '' ),
			'redirect_uris' => is_array( $uris ) ? array_values( array_filter( $uris, 'is_string' ) ) : [],
		];
	}

	/**
	 * Inserts or refreshes the cached row of a CIMD client.
	 *
	 * @param array<string, mixed> $client Validated client array.
	 */
	private static function save_cimd( array $client, int $ttl ): void {
		global $wpdb;

		$table = Store::table( Store::TABLE_CLIENTS );
		$now   = time();
		$data  = [
			'type'          => self::TYPE_CIMD,
			'name'          => (string) $client['name'],
			'logo_uri'      => (string) $client['logo_uri'],
			'client_uri'    => (string) $client['client_uri'],
			'publisher'     => (string) $client['publisher'],
			'redirect_uris' => (string) wp_json_encode( $client['redirect_uris'] ),
			'fetched_at'    => gmdate( 'Y-m-d H:i:s', $now ),
			'expires_at'    => gmdate( 'Y-m-d H:i:s', $now + $ttl ),
			'last_used_at'  => gmdate( 'Y-m-d H:i:s', $now ),
		];

		// The client_id comparison of the SQL is case-insensitive: a row found
		// for a URL differing only in case belongs to another client and must
		// not receive this document (its redirect URIs would replace the
		// other's). Such a URL is then simply not cached (the unique key
		// refuses the insert below).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the module's own table; its name comes from $wpdb->prefix only.
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, client_id FROM {$table} WHERE client_id = %s LIMIT 1", (string) $client['client_id'] ), ARRAY_A );
		if ( is_array( $existing ) ) {
			if ( hash_equals( (string) $existing['client_id'], (string) $client['client_id'] ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the module's own table, no WordPress API to write to it; the cache is the row itself.
				$wpdb->update( $table, $data, [ 'id' => (int) $existing['id'] ], null, [ '%d' ] );
			}
			return;
		}

		$data['client_id']  = (string) $client['client_id'];
		$data['created_at'] = gmdate( 'Y-m-d H:i:s', $now );

		// A concurrent authorize may have inserted the row in between: the
		// unique key then refuses this insert, and the other row is as good.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- see above.
		$wpdb->insert( $table, $data );
	}

	/**
	 * Marks a client as used, at most once an hour: the DCR purge relies on
	 * last_used_at, and a write per authorize would be wasted.
	 */
	private static function touch( string $client_id ): void {
		global $wpdb;

		$table = Store::table( Store::TABLE_CLIENTS );
		$now   = time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the module's own table; its name comes from $wpdb->prefix only.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET last_used_at = %s WHERE client_id = %s AND ( last_used_at IS NULL OR last_used_at < %s )", gmdate( 'Y-m-d H:i:s', $now ), $client_id, gmdate( 'Y-m-d H:i:s', $now - HOUR_IN_SECONDS ) ) );
	}

	/**
	 * Whether a value is a list of strings, all within the allowed set.
	 *
	 * @param mixed    $values  Raw value.
	 * @param string[] $allowed Accepted values.
	 */
	private static function is_subset( $values, array $allowed ): bool {
		if ( ! is_array( $values ) ) {
			return false;
		}

		foreach ( $values as $value ) {
			if ( ! is_string( $value ) || ! in_array( $value, $allowed, true ) ) {
				return false;
			}
		}

		return true;
	}

	private static function invalid_metadata( string $message ): \WP_Error {
		return new \WP_Error( 'invalid_client_metadata', $message, [ 'status' => 400 ] );
	}
}
