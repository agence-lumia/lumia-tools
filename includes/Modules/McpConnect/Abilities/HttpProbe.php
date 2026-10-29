<?php
namespace Lumia\Tools\Modules\McpConnect\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * `lumia/http-request`: Claude requests a page of the site and reads what a
 * visitor (or a signed-in user) gets: status, headers, redirections, timing,
 * HTML or an SEO extract. It checks its own work without a browser.
 *
 * Only the site itself can be requested: the host must be the one of
 * home_url() or site_url(), and a redirection to another host is reported,
 * never followed. Otherwise the site would become a proxy towards any
 * address its server reaches, internal ones included (SSRF). The requests go
 * through wp_safe_remote_request(), which still lets the site's own host
 * through when it resolves to a private address (a container network).
 */
final class HttpProbe implements Family {

	/** Redirections followed at most. */
	const MAX_REDIRECTS = 5;

	/** Bytes of HTML returned by default, and at most. */
	const DEFAULT_BYTES = 100000;
	const MAX_BYTES     = 1000000;

	/** Request timeout, in seconds. */
	const TIMEOUT = 20;

	public static function label(): string {
		return __( 'Page checks', 'lumia-tools' );
	}

	public static function description(): string {
		return __( 'Request a page of this site (this site only) and read its status, headers, redirections, HTML or SEO tags.', 'lumia-tools' );
	}

	public static function available(): bool {
		return true;
	}

	public static function definitions(): array {
		return [
			'http-request' => [
				'label'       => __( 'Request a page of the site', 'lumia-tools' ),
				'description' => 'Requests a URL of this site (GET or HEAD) the way a visitor does, and returns the status, the response headers, the redirection chain and the time taken, plus the page itself: `format` summary (title, size, main tags), seo (title, meta description, canonical, robots, headings, Open Graph, JSON-LD, images without alt, links) or html (the raw HTML, truncated to max_bytes). Only URLs of this site are allowed; a relative path such as /contact/ is resolved against the home URL. By default the page cache is bypassed and the request is anonymous; `as_agent` requests it signed in as the Claude account (drafts, private pages).',
				'input'       => [
					'url'              => [
						'type'        => 'string',
						'description' => 'Absolute URL of this site, or a path such as /contact/.',
					],
					'method'           => [
						'type'    => 'string',
						'enum'    => [ 'GET', 'HEAD' ],
						'default' => 'GET',
					],
					'format'           => [
						'type'    => 'string',
						'enum'    => [ 'summary', 'seo', 'html' ],
						'default' => 'summary',
					],
					'as_agent'         => [
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Send the request signed in as the Claude account instead of anonymously.',
					],
					'bypass_cache'     => [
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Add a cache-busting query argument and no-cache headers.',
					],
					'follow_redirects' => [
						'type'    => 'boolean',
						'default' => true,
					],
					'max_bytes'        => [
						'type'    => 'integer',
						'minimum' => 1000,
						'maximum' => self::MAX_BYTES,
						'default' => self::DEFAULT_BYTES,
					],
				],
				'required'    => [ 'url' ],
				'execute'     => [ self::class, 'request' ],
				'capability'  => 'manage_options',
				'readonly'    => true,
				'idempotent'  => true,
			],
		];
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function request( array $args ) {
		$url = self::resolve( (string) ( $args['url'] ?? '' ) );
		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$method = 'HEAD' === ( $args['method'] ?? '' ) ? 'HEAD' : 'GET';
		$format = in_array( $args['format'] ?? '', [ 'seo', 'html' ], true ) ? (string) $args['format'] : 'summary';
		$bytes  = min( self::MAX_BYTES, max( 1000, (int) ( $args['max_bytes'] ?? self::DEFAULT_BYTES ) ) );
		$follow = ! isset( $args['follow_redirects'] ) || ! empty( $args['follow_redirects'] );
		$bypass = ! isset( $args['bypass_cache'] ) || ! empty( $args['bypass_cache'] );

		$headers = [ 'User-Agent' => 'LumiaTools-MCP/' . LUMIA_VERSION . '; ' . home_url() ];
		if ( $bypass ) {
			$headers['Cache-Control'] = 'no-cache';
			$headers['Pragma']        = 'no-cache';
		}

		$session = null;
		$cookies = [];
		if ( ! empty( $args['as_agent'] ) ) {
			[ $cookies, $session ] = self::agent_cookies();
		}

		$chain    = [];
		$response = [];
		$status   = 0;
		$too_many = false;
		$current  = $bypass ? add_query_arg( 'lumia_nocache', (string) wp_rand(), $url ) : $url;
		$start    = microtime( true );

		try {
			for ( $hop = 0; $hop <= self::MAX_REDIRECTS; $hop++ ) {
				$response = wp_safe_remote_request(
					$current,
					[
						'method'              => $method,
						'timeout'             => self::TIMEOUT,
						'redirection'         => 0,
						'headers'             => $headers,
						'cookies'             => $cookies,
						'limit_response_size' => self::MAX_BYTES + 1,
						// Loopback: the site's own certificate may be one the
						// server does not trust (internal CA, self-signed staging).
						'sslverify'           => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter, applied as core does for loopbacks.
					]
				);

				if ( is_wp_error( $response ) ) {
					return Registry::error( 'request_failed', 'The request failed: ' . $response->get_error_message() );
				}

				$status   = (int) wp_remote_retrieve_response_code( $response );
				$location = wp_remote_retrieve_header( $response, 'location' );
				$location = is_array( $location ) ? (string) reset( $location ) : (string) $location;

				if ( $status < 300 || $status >= 400 || '' === $location || ! $follow ) {
					break;
				}

				$next    = \WP_Http::make_absolute_url( $location, $current );
				$chain[] = [
					'url'      => $current,
					'status'   => $status,
					'location' => $next,
				];

				if ( ! self::is_own( $next ) ) {
					return [
						'url'       => $url,
						'status'    => $status,
						'redirects' => $chain,
						'note'      => 'Redirects to another host: not followed.',
						'time_ms'   => (int) round( ( microtime( true ) - $start ) * 1000 ),
					];
				}

				// Out of hops: final_url stays the last URL actually fetched.
				if ( self::MAX_REDIRECTS === $hop ) {
					$too_many = true;
					break;
				}

				$current = $next;
			}
		} finally {
			if ( null !== $session ) {
				\WP_Session_Tokens::get_instance( (int) $session[0] )->destroy( (string) $session[1] );
			}
		}

		$body   = (string) wp_remote_retrieve_body( $response );
		$result = [
			'url'       => $url,
			'final_url' => remove_query_arg( 'lumia_nocache', $current ),
			'status'    => $status,
			'redirects' => $chain,
			'time_ms'   => (int) round( ( microtime( true ) - $start ) * 1000 ),
			'size'      => strlen( $body ),
			'headers'   => self::headers( $response ),
		];
		if ( $too_many ) {
			$result['note'] = 'Too many redirects: stopped after ' . self::MAX_REDIRECTS . ', final_url is the last URL fetched and still redirects (a loop?).';
		}

		if ( 'HEAD' === $method || '' === $body ) {
			return $result;
		}

		if ( 'html' === $format ) {
			$result['html']      = substr( $body, 0, $bytes );
			$result['truncated'] = strlen( $body ) > $bytes;

			return $result;
		}

		$seo = self::extract( $body );
		if ( 'seo' === $format ) {
			$result['seo'] = $seo;

			return $result;
		}

		$result['title']            = $seo['title'];
		$result['meta_description'] = $seo['meta_description'];
		$result['canonical']        = $seo['canonical'];
		$result['robots']           = $seo['robots'];
		$result['h1']               = $seo['headings']['h1'];

		return $result;
	}

	/**
	 * Absolute URL of the site, from a URL or a path; an error for any other
	 * host or scheme.
	 *
	 * @return string|\WP_Error
	 */
	private static function resolve( string $url ) {
		$url = trim( $url );
		if ( '' === $url ) {
			return Registry::error( 'invalid_url', 'A URL or a path of this site is required.' );
		}

		if ( '/' === $url[0] && ( ! isset( $url[1] ) || '/' !== $url[1] ) ) {
			$url = home_url( $url );
		}

		if ( ! self::is_own( $url ) ) {
			return Registry::error( 'foreign_url', 'Only URLs of this site can be requested (' . home_url( '/' ) . ').' );
		}

		return $url;
	}

	/**
	 * Whether a URL is http(s) on the host (and port) of the home or site URL.
	 */
	private static function is_own( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), [ 'http', 'https' ], true ) ) {
			return false;
		}

		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		$port = (int) ( $parts['port'] ?? 0 );
		if ( '' === $host || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}

		foreach ( [ home_url(), site_url() ] as $own ) {
			$own_parts = wp_parse_url( $own );
			if ( is_array( $own_parts ) && strtolower( (string) ( $own_parts['host'] ?? '' ) ) === $host && (int) ( $own_parts['port'] ?? 0 ) === $port ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Logged-in cookie of the current user (the Claude account) for one
	 * request, and the session to destroy afterwards.
	 *
	 * @return array{0: \WP_Http_Cookie[], 1: array{0: int, 1: string}|null}
	 */
	private static function agent_cookies(): array {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return [ [], null ];
		}

		$expiration = time() + MINUTE_IN_SECONDS * 5;
		$manager    = \WP_Session_Tokens::get_instance( $user_id );
		$token      = $manager->create( $expiration );
		$cookie     = wp_generate_auth_cookie( $user_id, $expiration, 'logged_in', $token );
		$host       = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		return [
			[
				new \WP_Http_Cookie(
					[
						// Defined by wp_cookie_constants() before plugins load.
						'name'   => (string) constant( 'LOGGED_IN_COOKIE' ),
						'value'  => $cookie,
						'domain' => $host,
					]
				),
			],
			[ $user_id, $token ],
		];
	}

	/**
	 * Response headers worth reading, lowercased.
	 *
	 * @param array<string, mixed> $response
	 * @return array<string, string>
	 */
	private static function headers( array $response ): array {
		$headers = wp_remote_retrieve_headers( $response );
		$out     = [];
		$keep    = [ 'content-type', 'content-length', 'cache-control', 'expires', 'last-modified', 'etag', 'location', 'x-robots-tag', 'link', 'server', 'x-cache', 'x-cache-handler', 'x-litespeed-cache', 'cf-cache-status', 'age', 'vary', 'content-security-policy', 'x-frame-options', 'strict-transport-security', 'set-cookie' ];

		if ( $headers instanceof \WpOrg\Requests\Utility\CaseInsensitiveDictionary || is_array( $headers ) ) {
			foreach ( $headers as $name => $value ) {
				$name = strtolower( (string) $name );
				if ( in_array( $name, $keep, true ) ) {
					$out[ $name ] = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
				}
			}
		}

		// Cookie values of a signed-in request are secrets: names only.
		if ( isset( $out['set-cookie'] ) ) {
			$out['set-cookie'] = (string) preg_replace( '/=[^;,]*/', '=…', $out['set-cookie'] );
		}

		return $out;
	}

	/**
	 * SEO extract of an HTML page.
	 *
	 * @return array{title: string, meta_description: string, canonical: string, robots: string, headings: array{h1: string[], h2: string[], h3: string[]}, open_graph: array<string, string>, json_ld: string[], hreflang: array<string, string>, images: array{total: int, without_alt: string[]}, links: array{internal: int, external: int, nofollow: int}, lang: string}
	 */
	public static function extract( string $html ): array {
		$seo = [
			'title'            => '',
			'meta_description' => '',
			'canonical'        => '',
			'robots'           => '',
			'lang'             => '',
			'headings'         => [
				'h1' => [],
				'h2' => [],
				'h3' => [],
			],
			'open_graph'       => [],
			'json_ld'          => [],
			'hreflang'         => [],
			'images'           => [
				'total'       => 0,
				'without_alt' => [],
			],
			'links'            => [
				'internal' => 0,
				'external' => 0,
				'nofollow' => 0,
			],
		];

		if ( ! class_exists( '\DOMDocument' ) ) {
			return $seo;
		}

		$dom      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded   = $dom->loadHTML( '<?xml encoding="utf-8"?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded ) {
			return $seo;
		}

		$xpath = new \DOMXPath( $dom );
		$text  = static function ( ?\DOMNode $node ): string {
			return null === $node ? '' : trim( (string) preg_replace( '/\s+/u', ' ', (string) $node->textContent ) );
		};
		$attr  = static function ( string $query, string $name ) use ( $xpath ): string {
			$nodes = $xpath->query( $query );
			$node  = false !== $nodes ? $nodes->item( 0 ) : null;

			return $node instanceof \DOMElement ? trim( $node->getAttribute( $name ) ) : '';
		};

		$titles                  = $dom->getElementsByTagName( 'title' );
		$seo['title']            = $text( $titles->item( 0 ) );
		$seo['meta_description'] = $attr( '//meta[@name="description"]', 'content' );
		$seo['canonical']        = $attr( '//link[@rel="canonical"]', 'href' );
		$seo['robots']           = $attr( '//meta[@name="robots"]', 'content' );
		$seo['lang']             = $attr( '//html', 'lang' );

		foreach ( [ 'h1', 'h2', 'h3' ] as $tag ) {
			foreach ( $dom->getElementsByTagName( $tag ) as $index => $node ) {
				if ( $index >= 30 ) {
					break;
				}
				$seo['headings'][ $tag ][] = mb_substr( $text( $node ), 0, 200 );
			}
		}

		$metas = $xpath->query( '//meta[starts-with(@property, "og:") or starts-with(@name, "twitter:")]' );
		foreach ( false !== $metas ? $metas : [] as $node ) {
			if ( $node instanceof \DOMElement ) {
				$key = $node->getAttribute( 'property' ) ? $node->getAttribute( 'property' ) : $node->getAttribute( 'name' );

				$seo['open_graph'][ $key ] = mb_substr( $node->getAttribute( 'content' ), 0, 500 );
			}
		}

		$scripts = $xpath->query( '//script[@type="application/ld+json"]' );
		foreach ( false !== $scripts ? $scripts : [] as $index => $node ) {
			if ( $index >= 10 ) {
				break;
			}
			if ( $node instanceof \DOMElement ) {
				$seo['json_ld'][] = mb_substr( trim( $node->textContent ), 0, 20000 );
			}
		}

		$alternates = $xpath->query( '//link[@rel="alternate" and @hreflang]' );
		foreach ( false !== $alternates ? $alternates : [] as $node ) {
			if ( $node instanceof \DOMElement ) {
				$seo['hreflang'][ $node->getAttribute( 'hreflang' ) ] = $node->getAttribute( 'href' );
			}
		}

		foreach ( $dom->getElementsByTagName( 'img' ) as $node ) {
			++$seo['images']['total'];
			if ( ! $node->hasAttribute( 'alt' ) && count( $seo['images']['without_alt'] ) < 50 ) {
				$seo['images']['without_alt'][] = $node->getAttribute( 'src' );
			}
		}

		$home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		foreach ( $dom->getElementsByTagName( 'a' ) as $node ) {
			$href = $node->getAttribute( 'href' );
			if ( '' === $href || '#' === $href[0] || 0 === strpos( $href, 'mailto:' ) || 0 === strpos( $href, 'tel:' ) ) {
				continue;
			}
			$host = strtolower( (string) wp_parse_url( $href, PHP_URL_HOST ) );
			if ( '' === $host || $host === $home_host ) {
				++$seo['links']['internal'];
			} else {
				++$seo['links']['external'];
			}
			if ( false !== stripos( $node->getAttribute( 'rel' ), 'nofollow' ) ) {
				++$seo['links']['nofollow'];
			}
		}

		return $seo;
	}
}
