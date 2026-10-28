<?php
namespace Lumia\Tools\Modules\McpConnect;

use Lumia\Tools\Core\Compat;

defined( 'ABSPATH' ) || exit;

/**
 * OAuth discovery for the MCP Adapter default server: the two metadata
 * documents, the `401` challenge that points Claude to them, and the early boot
 * of the Bearer authentication.
 *
 * - The protected resource is the Adapter default server URL, exactly as the
 *   user pastes it into Claude (Claude requires `resource` to equal it, path
 *   included).
 * - The issuer is the site's home URL: one authorization server per site, so a
 *   token of site A means nothing to site B.
 * - Every URL is built from home_url() / rest_url(), never from the Host
 *   header: behind Traefik only those carry the public `https` origin.
 */
final class Discovery {

	/** Scope advertised for the MCP resource. */
	const SCOPE = 'mcp';

	/** Scope that asks for a refresh token (authorization server only). */
	const SCOPE_OFFLINE = 'offline_access';

	/** REST namespace and route of the MCP Adapter default server. */
	const MCP_NAMESPACE = 'mcp';
	const MCP_ROUTE     = 'mcp-adapter-default-server';

	/** REST namespace of the OAuth endpoints. */
	const OAUTH_NAMESPACE = 'lumia-mcp/v1';

	/** Module id, as stored in `lumia_settings['modules']`. */
	const MODULE_ID = 'mcp_connect';

	/**
	 * Hooks the Bearer authentication, at plugin file load.
	 *
	 * Not on `plugins_loaded`, nor in Module::init(): the first
	 * wp_get_current_user() caches its result for the whole request, an
	 * anonymous user included, and a plugin resolving the user earlier than us
	 * would lock every MCP call to `401` whatever the token. Only the filter is
	 * added here; the rest waits for Module::init().
	 */
	public static function boot(): void {
		if ( ! self::enabled() ) {
			return;
		}

		// After the core cookie (10) and application password (20) handlers: a
		// request already authenticated by them is left untouched.
		add_filter( 'determine_current_user', [ BearerAuth::class, 'authenticate' ], 30 );
	}

	/**
	 * Whether the module is active and not disabled from wp-config.php.
	 *
	 * Reads the raw `lumia_settings` option (autoloaded, so no query): this runs
	 * at file load, before the module registry exists.
	 */
	public static function enabled(): bool {
		if ( Compat::constant( 'DISABLE_MCP_CONNECT' ) ) {
			return false;
		}

		$settings = get_option( 'lumia_settings', [] );

		return is_array( $settings ) && isset( $settings['modules'] ) && is_array( $settings['modules'] ) && ! empty( $settings['modules'][ self::MODULE_ID ] );
	}

	/**
	 * The protected resource: the Adapter default server URL, without a
	 * trailing slash.
	 */
	public static function resource_url(): string {
		return untrailingslashit( rest_url( self::MCP_NAMESPACE . '/' . self::MCP_ROUTE ) );
	}

	/**
	 * Issuer: the site's public URL. Built from home_url(), never from the Host
	 * header.
	 */
	public static function issuer(): string {
		return untrailingslashit( home_url() );
	}

	/**
	 * URL given in `resource_metadata`: the RFC 9728 path-insertion form, under
	 * the home URL. On a root install it is exactly the RFC form; on a site whose
	 * home URL has a path, the origin-root form would never reach WordPress.
	 */
	public static function protected_resource_metadata_url(): string {
		return home_url( '/.well-known/oauth-protected-resource/' . self::resource_relative_path() );
	}

	/**
	 * URL where an OAuth client looks for the authorization server metadata:
	 * the RFC 8414 path-insertion form at the origin root,
	 * `{origin}/.well-known/oauth-authorization-server{issuer path}`. On a root
	 * install it is the URL this module serves; when the home URL has a path
	 * (subdirectory install or subsite) it only reaches this site through a
	 * server-level rewrite, which the Site Health test checks.
	 */
	public static function authorization_server_metadata_url(): string {
		$parts  = wp_parse_url( home_url() );
		$parts  = is_array( $parts ) ? $parts : [];
		$origin = ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );

		return $origin . '/.well-known/oauth-authorization-server' . untrailingslashit( (string) ( $parts['path'] ?? '' ) );
	}

	/**
	 * RFC 9728 Protected Resource Metadata.
	 *
	 * @return array{resource: string, authorization_servers: string[], scopes_supported: string[], bearer_methods_supported: string[], resource_name: string}
	 */
	public static function protected_resource_metadata(): array {
		return [
			'resource'                 => self::resource_url(),
			// Claude only reads the first entry.
			'authorization_servers'    => [ self::issuer() ],
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
	 * `client_id_metadata_document_supported` and `none` in the auth methods are
	 * both required for Claude to use its published metadata document (CIMD);
	 * without them it falls back to Dynamic Client Registration.
	 *
	 * @return array<string, mixed>
	 */
	public static function authorization_server_metadata(): array {
		$metadata = [
			'issuer'                                     => self::issuer(),
			'authorization_endpoint'                     => rest_url( self::OAUTH_NAMESPACE . '/authorize' ),
			'token_endpoint'                             => rest_url( self::OAUTH_NAMESPACE . '/token' ),
			'revocation_endpoint'                        => rest_url( self::OAUTH_NAMESPACE . '/revoke' ),
			'response_types_supported'                   => [ 'code' ],
			'grant_types_supported'                      => [ 'authorization_code', 'refresh_token' ],
			'code_challenge_methods_supported'           => [ 'S256' ],
			'token_endpoint_auth_methods_supported'      => [ 'none' ],
			'revocation_endpoint_auth_methods_supported' => [ 'none' ],
			'scopes_supported'                           => [ self::SCOPE, self::SCOPE_OFFLINE ],
			'client_id_metadata_document_supported'      => true,
			'authorization_response_iss_parameter_supported' => true,
		];

		// Advertised only when DCR is on: Claude tries it whenever it is listed.
		$settings = Module::settings();
		if ( ! empty( $settings['dcr_enabled'] ) ) {
			$metadata['registration_endpoint'] = rest_url( self::OAUTH_NAMESPACE . '/register' );
		}

		return $metadata;
	}

	/**
	 * `parse_request` @0: serves the discovery documents. Runs before any
	 * template is chosen, so neither `redirect_canonical` nor a 404 interferes.
	 *
	 * `$wp->request` is relative to the home URL and is only filled with pretty
	 * permalinks: with plain permalinks the documents are not served at all (the
	 * Site Health test reports it).
	 *
	 * @param \WP $wp Current request.
	 */
	public static function serve_well_known( \WP $wp ): void {
		$path = trim( (string) $wp->request, '/' );

		if ( 0 !== strpos( $path, '.well-known/oauth-' ) ) {
			return;
		}

		$document = null;

		// The bare form is the path Claude probes when the challenge carries no
		// pointer; the path-insertion form is the one the challenge gives.
		if ( '.well-known/oauth-protected-resource' === $path || '.well-known/oauth-protected-resource/' . self::resource_relative_path() === $path ) {
			$document = self::protected_resource_metadata();
		} elseif ( '.well-known/oauth-authorization-server' === $path ) {
			$document = self::authorization_server_metadata();
		}

		if ( null === $document ) {
			return;
		}

		self::send_json( $document );
	}

	/**
	 * `rest_post_dispatch`: adds the OAuth challenge to every `401` of the MCP
	 * route, whether the token is missing (the Adapter's `rest_forbidden`) or
	 * invalid (BearerAuth::reject()). Claude ignores a `401` without the
	 * `resource_metadata` pointer, and ignores the header on any other status.
	 *
	 * @param \WP_HTTP_Response|mixed $response Response.
	 * @param \WP_REST_Server|mixed   $server   Server.
	 * @param \WP_REST_Request|mixed  $request  Request.
	 * @return \WP_HTTP_Response|mixed
	 */
	public static function challenge( $response, $server, $request ) {
		unset( $server );

		if ( ! $response instanceof \WP_HTTP_Response || ! $request instanceof \WP_REST_Request ) {
			return $response;
		}

		if ( 401 !== $response->get_status() || ! self::is_mcp_route( $request->get_route() ) ) {
			return $response;
		}

		$challenge = sprintf(
			'Bearer resource_metadata="%1$s", scope="%2$s"',
			self::protected_resource_metadata_url(),
			self::SCOPE
		);

		$data = $response->get_data();
		if ( is_array( $data ) && isset( $data['code'] ) && 'invalid_token' === $data['code'] ) {
			$challenge .= ', error="invalid_token"';
		}

		$response->header( 'WWW-Authenticate', $challenge );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );

		return $response;
	}

	/**
	 * Whether the current HTTP request targets the MCP route, read from the raw
	 * request so it works before `parse_request`: a plugin calling
	 * wp_get_current_user() on `init` resolves the user before the REST route
	 * is parsed. Covers the pretty form (`/wp-json/…`), the index form
	 * (`/index.php/wp-json/…`) and `?rest_route=`.
	 *
	 * Mirrors how WP::parse_request() resolves the `rest_route` query var: a
	 * POST `rest_route` wins over the query string and over the path (the
	 * rewrite rule), and a GET and a POST value that differ stop the request.
	 * Testing the path alone let `POST /wp-json/mcp/…` with a form body
	 * `rest_route=/wp/v2/users` authenticate the token and dispatch the users
	 * route. BearerAuth::restrict_dispatch() backs this test up at dispatch
	 * time.
	 */
	public static function is_protected_request(): bool {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $uri ) {
			return false;
		}

		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$path = untrailingslashit( rawurldecode( $path ) );

		$home_path = untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );

		// phpcs:disable WordPress.Security.NonceVerification -- Routing test only, nothing is processed.
		$from_get  = isset( $_GET['rest_route'] ) ? wp_unslash( $_GET['rest_route'] ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared as is, then matched against a fixed route.
		$from_post = isset( $_POST['rest_route'] ) ? wp_unslash( $_POST['rest_route'] ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- see above.
		// phpcs:enable WordPress.Security.NonceVerification

		if ( null !== $from_get || null !== $from_post ) {
			// WordPress dies on a mismatch: never authenticate such a request.
			if ( null !== $from_get && null !== $from_post && $from_get !== $from_post ) {
				return false;
			}

			// Only the front controller routes `rest_route`. Tested on the value
			// alone, admin-ajax.php, admin-post.php or wp-comments-post.php
			// called with `?rest_route=/mcp/…` would run as the token's user.
			// A pretty MCP path with a `rest_route` on top is refused too: the
			// value, not the path, is what WordPress dispatches.
			if ( $home_path !== $path && $home_path . '/index.php' !== $path ) {
				return false;
			}

			$route = null !== $from_post ? $from_post : $from_get;

			return is_string( $route ) && self::is_mcp_route( sanitize_text_field( $route ) );
		}

		$route = '/' . rest_get_url_prefix() . '/' . self::MCP_NAMESPACE . '/' . self::MCP_ROUTE;

		return $home_path . $route === $path || $home_path . '/index.php' . $route === $path;
	}

	/**
	 * Whether a parsed REST route is the MCP route.
	 *
	 * @param string $route REST route, e.g. `/mcp/mcp-adapter-default-server`.
	 */
	public static function is_mcp_route( string $route ): bool {
		return '/' . self::MCP_NAMESPACE . '/' . self::MCP_ROUTE === untrailingslashit( $route );
	}

	/**
	 * Path of the resource relative to the home URL, e.g.
	 * `wp-json/mcp/mcp-adapter-default-server`.
	 */
	private static function resource_relative_path(): string {
		$resource = trim( (string) wp_parse_url( self::resource_url(), PHP_URL_PATH ), '/' );
		$home     = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );

		if ( '' !== $home && 0 === strpos( $resource, $home . '/' ) ) {
			$resource = substr( $resource, strlen( $home ) + 1 );
		}

		return $resource;
	}

	/**
	 * Sends a JSON document that no cache may keep, then stops.
	 *
	 * The CORS header lets a browser-based client read the public metadata.
	 *
	 * @param array<string, mixed> $document Document.
	 */
	private static function send_json( array $document ): void {
		status_header( 200 );
		nocache_headers();
		// After nocache_headers(), so its Cache-Control is replaced by the strict one.
		Security::no_store_headers();
		header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
		header( 'Access-Control-Allow-Origin: *' );

		echo wp_json_encode( $document, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		exit;
	}
}
