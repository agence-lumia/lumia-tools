<?php
namespace Lumia\Tools\Modules\McpConnect;

defined( 'ABSPATH' ) || exit;

/**
 * MCP Connect spike (issue #46, task 1): proves the hook-in point on the
 * WordPress MCP Adapter 0.7.x before the module is built.
 *
 * - A Bearer token is accepted on the Adapter default server route only, through
 *   `determine_current_user`. The same token anywhere else is ignored.
 * - A 401 on that route carries `WWW-Authenticate: Bearer resource_metadata=…`,
 *   the pointer Claude follows to start its OAuth discovery (it ignores the
 *   header on any other status).
 * - The two discovery documents (RFC 9728 protected resource, RFC 8414
 *   authorization server) are served with the values Claude requires.
 * - The authorization endpoint only records what the client sent: no code, no
 *   token is ever issued. The single accepted token is a wp-config constant.
 *
 * Loaded only when `LUMIA_MCP_SPIKE_TOKEN` (32+ characters) and
 * `LUMIA_MCP_SPIKE_USER` (an existing login) are defined in wp-config.php:
 * without them nothing is hooked. Throwaway code, replaced by the module.
 */
class Spike {

	/** REST namespace and route of the MCP Adapter default server. */
	const MCP_NAMESPACE = 'mcp';
	const MCP_ROUTE     = 'mcp-adapter-default-server';

	/** REST namespace of the OAuth endpoints. */
	const OAUTH_NAMESPACE = 'lumia-mcp/v1';

	/** Scope advertised for the MCP resource. */
	const SCOPE = 'mcp';

	/** Token prefix, so a leaked token is greppable. */
	const TOKEN_PREFIX = 'lmc_at_';

	/** Last authorization requests kept for inspection (option, not autoloaded). */
	const AUTHORIZE_LOG_OPTION = 'lumia_mcp_spike_authorize_log';

	/**
	 * Whether the spike is configured on this site.
	 */
	public static function enabled(): bool {
		return '' !== self::configured_token() && '' !== self::configured_login();
	}

	/**
	 * Registers the hooks.
	 */
	public function init(): void {
		// After the core cookie (10) and application password (20) handlers: a
		// request already authenticated by them is left untouched.
		add_filter( 'determine_current_user', [ $this, 'authenticate_bearer' ], 30 );
		add_filter( 'rest_authentication_errors', [ $this, 'reject_invalid_bearer' ], 90 );
		add_filter( 'rest_post_dispatch', [ $this, 'add_challenge' ], 10, 3 );
		add_action( 'parse_request', [ $this, 'serve_well_known' ], 0 );
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * `determine_current_user`: the spike user when the request targets the MCP
	 * route with the configured Bearer token.
	 *
	 * The route is read from the request URI, not from the parsed REST route: a
	 * plugin calling `wp_get_current_user()` on `init` resolves the user before
	 * `parse_request`, and the result is cached for the whole request.
	 *
	 * @param int|false $user_id User already identified, or false.
	 * @return int|false
	 */
	public function authenticate_bearer( $user_id ) {
		if ( ! empty( $user_id ) || ! $this->is_protected_request() ) {
			return $user_id;
		}

		$token = $this->bearer_token();
		if ( '' === $token || ! $this->token_is_valid( $token ) ) {
			return $user_id;
		}

		$user = get_user_by( 'login', self::configured_login() );

		return $user instanceof \WP_User ? $user->ID : $user_id;
	}

	/**
	 * `rest_authentication_errors`: a Bearer token that does not match answers
	 * 401 `invalid_token` instead of falling back to an anonymous request.
	 *
	 * @param \WP_Error|null|true $result Result of the previous handlers.
	 * @return \WP_Error|null|true
	 */
	public function reject_invalid_bearer( $result ) {
		if ( null !== $result || ! $this->is_protected_request() ) {
			return $result;
		}

		$token = $this->bearer_token();
		if ( '' === $token || $this->token_is_valid( $token ) ) {
			return $result;
		}

		return new \WP_Error( 'invalid_token', 'The access token is invalid or expired.', [ 'status' => 401 ] );
	}

	/**
	 * `rest_post_dispatch`: adds the OAuth challenge to every 401 of the MCP
	 * route, whether the token is missing (the Adapter's `rest_forbidden`) or
	 * invalid (`invalid_token` above).
	 *
	 * @param \WP_HTTP_Response $response Response.
	 * @param \WP_REST_Server   $server   Server.
	 * @param \WP_REST_Request  $request  Request.
	 * @return \WP_HTTP_Response
	 */
	public function add_challenge( $response, $server, $request ) {
		unset( $server );

		if ( 401 !== $response->get_status() || ! $this->is_mcp_route( $request->get_route() ) ) {
			return $response;
		}

		$challenge = sprintf(
			'Bearer resource_metadata="%1$s", scope="%2$s"',
			$this->protected_resource_metadata_url(),
			self::SCOPE
		);

		$data = $response->get_data();
		if ( is_array( $data ) && isset( $data['code'] ) && 'invalid_token' === $data['code'] ) {
			$challenge .= ', error="invalid_token"';
		}

		$response->header( 'WWW-Authenticate', $challenge );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * `parse_request`: serves the discovery documents. Runs before any template
	 * is chosen, so neither `redirect_canonical` nor a 404 interferes.
	 *
	 * `$wp->request` is relative to the home URL and is only filled with pretty
	 * permalinks: with plain permalinks the documents are not served at all.
	 *
	 * @param \WP $wp Current request.
	 */
	public function serve_well_known( $wp ): void {
		$path = trim( (string) $wp->request, '/' );

		if ( 0 !== strpos( $path, '.well-known/oauth-' ) ) {
			return;
		}

		$document = null;

		if ( '.well-known/oauth-protected-resource' === $path || '.well-known/oauth-protected-resource/' . $this->resource_relative_path() === $path ) {
			$document = $this->protected_resource_metadata();
		} elseif ( '.well-known/oauth-authorization-server' === $path ) {
			$document = $this->authorization_server_metadata();
		}

		if ( null === $document ) {
			return;
		}

		$this->send_json( $document, 200 );
	}

	/**
	 * `rest_api_init`: OAuth endpoints. Only the authorization endpoint does
	 * something here, and it never grants anything.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::OAUTH_NAMESPACE,
			'/authorize',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'authorize' ],
				'permission_callback' => '__return_true',
			]
		);

		foreach ( [ 'token', 'revoke' ] as $route ) {
			register_rest_route(
				self::OAUTH_NAMESPACE,
				'/' . $route,
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'not_implemented' ],
					'permission_callback' => '__return_true',
				]
			);
		}
	}

	/**
	 * Authorization endpoint: records the parameters the client sent (the
	 * `client_id` tells which metadata document it uses, `redirect_uri` which
	 * callback), then refuses.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function authorize( \WP_REST_Request $request ): \WP_REST_Response {
		$keys     = [ 'response_type', 'client_id', 'redirect_uri', 'scope', 'state', 'resource', 'code_challenge', 'code_challenge_method' ];
		$received = [];

		foreach ( $keys as $key ) {
			$value            = $request->get_param( $key );
			$received[ $key ] = is_string( $value ) ? sanitize_text_field( $value ) : '';
		}
		$received['state']      = '' !== $received['state'] ? '(present)' : '';
		$received['user_agent'] = sanitize_text_field( (string) $request->get_header( 'user_agent' ) );
		$received['time']       = gmdate( 'c' );

		$log = get_option( self::AUTHORIZE_LOG_OPTION, [] );
		$log = is_array( $log ) ? array_slice( $log, -19 ) : [];

		$log[] = $received;
		update_option( self::AUTHORIZE_LOG_OPTION, $log, false );

		$response = new \WP_REST_Response(
			[
				'error'             => 'temporarily_unavailable',
				'error_description' => 'MCP Connect spike: the authorization request was recorded, no code is issued.',
				'received'          => $received,
			],
			503
		);
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Token and revocation endpoints of the spike: not implemented.
	 */
	public function not_implemented(): \WP_REST_Response {
		$response = new \WP_REST_Response(
			[
				'error'             => 'invalid_grant',
				'error_description' => 'MCP Connect spike: no token is issued.',
			],
			400
		);
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * RFC 9728 Protected Resource Metadata.
	 *
	 * @return array<string, mixed>
	 */
	public function protected_resource_metadata(): array {
		return [
			'resource'                 => $this->resource_url(),
			// Claude only reads the first entry.
			'authorization_servers'    => [ $this->issuer() ],
			// No offline_access here: MCP 2026-07-28 says a resource SHOULD NOT
			// list it; clients add it from the authorization server metadata.
			'scopes_supported'         => [ self::SCOPE ],
			'bearer_methods_supported' => [ 'header' ],
			'resource_name'            => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		];
	}

	/**
	 * RFC 8414 Authorization Server Metadata.
	 *
	 * @return array<string, mixed>
	 */
	public function authorization_server_metadata(): array {
		return [
			'issuer'                                     => $this->issuer(),
			'authorization_endpoint'                     => rest_url( self::OAUTH_NAMESPACE . '/authorize' ),
			'token_endpoint'                             => rest_url( self::OAUTH_NAMESPACE . '/token' ),
			'revocation_endpoint'                        => rest_url( self::OAUTH_NAMESPACE . '/revoke' ),
			'response_types_supported'                   => [ 'code' ],
			'grant_types_supported'                      => [ 'authorization_code', 'refresh_token' ],
			'code_challenge_methods_supported'           => [ 'S256' ],
			'token_endpoint_auth_methods_supported'      => [ 'none' ],
			'revocation_endpoint_auth_methods_supported' => [ 'none' ],
			'scopes_supported'                           => [ self::SCOPE, 'offline_access' ],
			'client_id_metadata_document_supported'      => true,
			'authorization_response_iss_parameter_supported' => true,
		];
	}

	/**
	 * The protected resource: the Adapter default server URL, exactly as the
	 * user pastes it into Claude.
	 */
	public function resource_url(): string {
		return untrailingslashit( rest_url( self::MCP_NAMESPACE . '/' . self::MCP_ROUTE ) );
	}

	/**
	 * Issuer: the site's public URL. Built from home_url(), never from the Host
	 * header.
	 */
	public function issuer(): string {
		return untrailingslashit( home_url() );
	}

	/**
	 * URL given in `resource_metadata`: the RFC 9728 path-insertion form, under
	 * the home URL. On a root install it is exactly the RFC form; on a site whose
	 * home URL has a path, the origin-root form would never reach WordPress.
	 */
	public function protected_resource_metadata_url(): string {
		return home_url( '/.well-known/oauth-protected-resource/' . $this->resource_relative_path() );
	}

	/**
	 * Path of the resource relative to the home URL, e.g.
	 * `wp-json/mcp/mcp-adapter-default-server`.
	 */
	private function resource_relative_path(): string {
		$resource = trim( (string) wp_parse_url( $this->resource_url(), PHP_URL_PATH ), '/' );
		$home     = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );

		if ( '' !== $home && 0 === strpos( $resource, $home . '/' ) ) {
			$resource = substr( $resource, strlen( $home ) + 1 );
		}

		return $resource;
	}

	/**
	 * Whether the current HTTP request targets the MCP route, read from the raw
	 * request so it works before `parse_request`. Covers the pretty form
	 * (`/wp-json/…`), the index form (`/index.php/wp-json/…`) and `?rest_route=`.
	 */
	private function is_protected_request(): bool {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $uri ) {
			return false;
		}

		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$path = untrailingslashit( rawurldecode( $path ) );

		$home_path = untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing test only, nothing is processed.
		if ( isset( $_GET['rest_route'] ) ) {
			// Only the front controller routes `rest_route`. Tested on the query
			// alone, admin-ajax.php, admin-post.php or wp-comments-post.php
			// called with `?rest_route=/mcp/…` would run as the token's user.
			if ( $home_path !== $path && $home_path . '/index.php' !== $path ) {
				return false;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing test only, nothing is processed.
			$route = is_string( $_GET['rest_route'] ) ? sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ) : '';

			return $this->is_mcp_route( $route );
		}

		$route = '/' . rest_get_url_prefix() . '/' . self::MCP_NAMESPACE . '/' . self::MCP_ROUTE;

		return $home_path . $route === $path || $home_path . '/index.php' . $route === $path;
	}

	/**
	 * Whether a parsed REST route is the MCP route.
	 *
	 * @param string $route REST route, e.g. `/mcp/mcp-adapter-default-server`.
	 */
	private function is_mcp_route( string $route ): bool {
		return '/' . self::MCP_NAMESPACE . '/' . self::MCP_ROUTE === untrailingslashit( $route );
	}

	/**
	 * Bearer token of the request, or ''.
	 *
	 * PHP-FPM behind nginx gets `HTTP_AUTHORIZATION`. Apache drops the header
	 * unless a rewrite rule copies it (`REDIRECT_HTTP_AUTHORIZATION`); under
	 * mod_php it is still readable through getallheaders().
	 */
	private function bearer_token(): string {
		$header = '';

		foreach ( [ 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ] as $key ) {
			if ( isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) && '' !== $_SERVER[ $key ] ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared with hash_equals() only, never output or stored.
				$header = wp_unslash( $_SERVER[ $key ] );
				break;
			}
		}

		if ( '' === $header && function_exists( 'getallheaders' ) ) {
			foreach ( (array) getallheaders() as $name => $value ) {
				if ( is_string( $name ) && 'authorization' === strtolower( $name ) && is_string( $value ) ) {
					$header = $value;
					break;
				}
			}
		}

		if ( ! preg_match( '/^Bearer\s+(\S+)$/i', trim( $header ), $matches ) ) {
			return '';
		}

		return $matches[1];
	}

	/**
	 * Constant-time comparison with the configured token.
	 *
	 * @param string $token Token from the request.
	 */
	private function token_is_valid( string $token ): bool {
		return hash_equals( self::configured_token(), $token );
	}

	/**
	 * Configured token, or '' when missing, too short or without the prefix.
	 */
	private static function configured_token(): string {
		if ( ! defined( 'LUMIA_MCP_SPIKE_TOKEN' ) ) {
			return '';
		}

		$token = constant( 'LUMIA_MCP_SPIKE_TOKEN' );
		if ( ! is_string( $token ) || 0 !== strpos( $token, self::TOKEN_PREFIX ) || strlen( $token ) < strlen( self::TOKEN_PREFIX ) + 32 ) {
			return '';
		}

		return $token;
	}

	/**
	 * Login of the user the spike token acts as, or ''.
	 */
	private static function configured_login(): string {
		if ( ! defined( 'LUMIA_MCP_SPIKE_USER' ) ) {
			return '';
		}

		$login = constant( 'LUMIA_MCP_SPIKE_USER' );

		return is_string( $login ) ? $login : '';
	}

	/**
	 * Sends a JSON document that no cache may keep, then stops.
	 *
	 * @param array<string, mixed> $document Document.
	 * @param int                  $status   HTTP status.
	 */
	private function send_json( array $document, int $status ): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Shared convention read by page caches.
		}

		status_header( $status );
		nocache_headers();
		header( 'Cache-Control: no-store' );
		header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
		header( 'Access-Control-Allow-Origin: *' );

		echo wp_json_encode( $document, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		exit;
	}
}
