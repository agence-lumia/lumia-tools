<?php
namespace Lumia\Tools\Modules\McpConnect;

defined( 'ABSPATH' ) || exit;

/**
 * OAuth 2.1 endpoints of the site's authorization server, under the REST
 * namespace `lumia-mcp/v1`: authorization (RFC 6749 + PKCE S256 + RFC 8707
 * resource + RFC 9207 `iss`), token (code exchange, refresh with rotation and
 * reuse detection), revocation (RFC 7009), registration (RFC 7591, only when
 * DCR is on) and the Site Health probe.
 *
 * - Every endpoint is public (`permission_callback` `__return_true`): an OAuth
 *   client is anonymous by definition, the protection is the protocol itself
 *   (PKCE, single-use codes, hashed tokens, exact redirect URIs).
 * - Every response is `Cache-Control: no-store` with `DONOTCACHEPAGE`: a page
 *   cache or proxy serving a stored token response would hand a token to
 *   someone else, or replay a code.
 * - Errors of `/token` and `/revoke` are RFC 6749 §5.2 JSON documents. Claude
 *   only recovers on those codes, so a rate limit or a pause answers
 *   `invalid_request`, never `invalid_grant` (which makes it drop the
 *   connection).
 * - Codes and refresh tokens are consumed with an atomic UPDATE in the table
 *   (Tokens::consume()), never through transients: with Redis as object
 *   cache a check-then-delete would be racy.
 */
final class AuthorizationServer {

	/** Lifetime of an authorization request, from `/authorize` to the consent decision. */
	const REQUEST_TTL = 600;

	/** Shape of a PKCE code challenge or code verifier (RFC 7636). */
	const PKCE_PATTERN = '/^[A-Za-z0-9\-._~]{43,128}$/';

	/** Value of the Authorization header sent by the Site Health probe. */
	const PROBE_HEADER = 'Bearer lmc_probe';

	/** Transient prefix of the per-IP `/authorize` counter (followed by md5 of the IP). */
	const AUTHORIZE_RATE_PREFIX = 'lumia_mcp_authz_';

	/** Authorization requests stored per IP and per window. */
	const AUTHORIZE_RATE_LIMIT = 30;

	/** Window of the `/authorize` counter, in seconds. */
	const AUTHORIZE_RATE_WINDOW = 600;

	/** Expired authorization requests deleted on each `/authorize`. */
	const AUTHORIZE_PURGE_LIMIT = 100;

	/**
	 * Seconds during which a refresh token presented again after its rotation
	 * is a retry, not a theft (see refresh()).
	 */
	const REFRESH_REUSE_GRACE = 60;

	/** Error codes RFC 7591 defines for the registration endpoint. */
	const REGISTRATION_ERRORS = [ 'invalid_redirect_uri', 'invalid_client_metadata', 'invalid_software_statement', 'unapproved_software_statement' ];

	/**
	 * `rest_api_init`: registers the endpoints.
	 */
	public static function register_routes(): void {
		// Public OAuth endpoints: see the class docblock.
		register_rest_route(
			Discovery::OAUTH_NAMESPACE,
			'/authorize',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'authorize' ],
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			Discovery::OAUTH_NAMESPACE,
			'/token',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'token' ],
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			Discovery::OAUTH_NAMESPACE,
			'/revoke',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'revoke' ],
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			Discovery::OAUTH_NAMESPACE,
			'/ping',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'ping' ],
				'permission_callback' => '__return_true',
			]
		);

		$settings = Module::settings();
		if ( ! empty( $settings['dcr_enabled'] ) ) {
			register_rest_route(
				Discovery::OAUTH_NAMESPACE,
				'/register',
				[
					'methods'             => 'POST',
					'callback'            => [ self::class, 'register' ],
					'permission_callback' => '__return_true',
				]
			);
		}
	}

	/* ===== AUTHORIZATION ENDPOINT ===== */

	/**
	 * Authorization endpoint: validates the request, stores it, then sends the
	 * browser to the consent page in wp-admin (where auth_redirect() handles
	 * the login, custom login slug included).
	 *
	 * Until the client and its redirect URI are validated, an error is shown
	 * on an HTML page and never redirected: redirecting to an unchecked URI
	 * would make this endpoint an open redirector.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function authorize( \WP_REST_Request $request ): \WP_REST_Response {
		$client_id = self::string_param( $request, 'client_id' );
		if ( '' === $client_id ) {
			self::html_error( __( 'The request does not say which application is connecting (client_id is missing).', 'lumia-tools' ) );
		}

		$client = ClientRegistry::resolve( $client_id );
		if ( is_wp_error( $client ) ) {
			self::html_error( $client->get_error_message() );
		}

		$redirect_uri = self::string_param( $request, 'redirect_uri' );
		if ( '' === $redirect_uri || ! ClientRegistry::redirect_uri_allowed( $client, $redirect_uri ) ) {
			self::html_error( __( 'The return address of this request is not one the application registered. The connection was not started.', 'lumia-tools' ) );
		}

		// From here on, errors go back to the client.
		$state = self::string_param( $request, 'state' );

		if ( 'code' !== self::string_param( $request, 'response_type' ) ) {
			return self::error_redirect( $redirect_uri, $state, 'unsupported_response_type', __( 'Only response_type=code is supported.', 'lumia-tools' ) );
		}

		$code_challenge = self::string_param( $request, 'code_challenge' );
		if ( 'S256' !== self::string_param( $request, 'code_challenge_method' ) || ! preg_match( self::PKCE_PATTERN, $code_challenge ) ) {
			return self::error_redirect( $redirect_uri, $state, 'invalid_request', __( 'PKCE with code_challenge_method=S256 is required.', 'lumia-tools' ) );
		}

		$resource = self::string_param( $request, 'resource' );
		if ( Discovery::resource_url() !== $resource ) {
			return self::error_redirect( $redirect_uri, $state, 'invalid_target', __( 'The resource parameter must be the MCP server URL of this site.', 'lumia-tools' ) );
		}

		$scope = self::normalize_scope( self::string_param( $request, 'scope' ) );
		if ( '' === $scope ) {
			return self::error_redirect( $redirect_uri, $state, 'invalid_scope', __( 'Only the mcp and offline_access scopes are supported.', 'lumia-tools' ) );
		}

		$settings = Module::settings();

		if ( ! empty( $settings['trusted_only'] ) && ! ClientRegistry::is_trusted( $client ) ) {
			return self::error_redirect( $redirect_uri, $state, 'access_denied', __( 'This site only accepts applications from trusted publishers.', 'lumia-tools' ) );
		}

		if ( ! empty( $settings['paused'] ) ) {
			return self::error_redirect( $redirect_uri, $state, 'temporarily_unavailable', __( 'Connections to this site are paused.', 'lumia-tools' ) );
		}

		// Each request stores a row, and the endpoint is public: a per-IP cap
		// bounds the rows one caller can add, and every call clears a batch of
		// expired ones, so the table drains at the pace it fills.
		Store::purge_expired_requests( self::AUTHORIZE_PURGE_LIMIT );

		if ( ! Security::take_counter( Security::ip_key( self::AUTHORIZE_RATE_PREFIX ), self::AUTHORIZE_RATE_LIMIT, self::AUTHORIZE_RATE_WINDOW ) ) {
			self::html_error( __( 'Too many connection attempts from this address. Wait a few minutes and try again.', 'lumia-tools' ), 429 );
		}

		$request_id = Tokens::issue(
			Tokens::REQUEST,
			0,
			self::REQUEST_TTL,
			[
				'client_id'      => $client_id,
				'redirect_uri'   => $redirect_uri,
				'state'          => $state,
				'code_challenge' => $code_challenge,
				'resource'       => $resource,
				'scope'          => $scope,
			]
		);

		if ( '' === $request_id ) {
			return self::error_redirect( $redirect_uri, $state, 'server_error', __( 'The authorization request could not be stored.', 'lumia-tools' ) );
		}

		return self::redirect( admin_url( 'admin.php?page=lumia-mcp-consent&request=' . rawurlencode( $request_id ) ) );
	}

	/**
	 * Scope granted for an authorization request: always `mcp offline_access`,
	 * whatever known subset was asked for (`mcp` is implied when the client
	 * sends none). Every connection gets a refresh token: one that could not
	 * refresh would need a new consent every hour. Granting `offline_access`
	 * explicitly keeps the returned scope true to what the tokens can do, and
	 * the consent screen always says so ("stay connected").
	 *
	 * @param string $scope Space-separated scopes as sent.
	 * @return string Granted scope, '' if an unknown scope was asked for.
	 */
	private static function normalize_scope( string $scope ): string {
		$known = [ Discovery::SCOPE, Discovery::SCOPE_OFFLINE ];

		foreach ( self::split_scope( $scope ) as $item ) {
			if ( ! in_array( $item, $known, true ) ) {
				return '';
			}
		}

		return implode( ' ', $known );
	}

	/**
	 * Scope string → list of scopes.
	 *
	 * @return string[]
	 */
	private static function split_scope( string $scope ): array {
		$items = preg_split( '/\s+/', trim( $scope ), -1, PREG_SPLIT_NO_EMPTY );

		return is_array( $items ) ? $items : [];
	}

	/**
	 * Redirect to the client with an RFC 6749 §4.1.2.1 error, `state` and `iss`.
	 *
	 * @param string $redirect_uri Validated redirect URI.
	 * @param string $state        State sent by the client ('' if none).
	 * @param string $error        Error code.
	 * @param string $description  Human-readable description.
	 */
	private static function error_redirect( string $redirect_uri, string $state, string $error, string $description ): \WP_REST_Response {
		$params = [
			'error'             => $error,
			'error_description' => $description,
		];

		if ( '' !== $state ) {
			$params['state'] = $state;
		}

		// RFC 9207: tells the client which server answered (mix-up attacks).
		$params['iss'] = Discovery::issuer();

		return self::redirect( add_query_arg( array_map( 'rawurlencode', $params ), $redirect_uri ) );
	}

	/**
	 * `302` response, not cached.
	 *
	 * A REST response carrying `Location` rather than wp_redirect() + exit: the
	 * REST server still sends it, and `rest_post_dispatch` stays in the loop.
	 * The target is either wp-admin or a redirect URI the client registered.
	 *
	 * @param string $url Absolute URL.
	 */
	private static function redirect( string $url ): \WP_REST_Response {
		$response = new \WP_REST_Response( null, 302 );
		$response->header( 'Location', $url );

		return self::no_store( $response );
	}

	/**
	 * Shows an error page and stops. Used only before the redirect URI is
	 * validated.
	 *
	 * @param string $message Plain-text message.
	 * @param int    $status  HTTP status (400, 429 for the rate limit).
	 * @return never
	 */
	private static function html_error( string $message, int $status = 400 ): void {
		Security::no_store_headers();

		wp_die( esc_html( $message ), esc_html__( 'Connection refused', 'lumia-tools' ), [ 'response' => $status ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $status is an integer HTTP status code, not output.
	}

	/* ===== TOKEN ENDPOINT ===== */

	/**
	 * Token endpoint (`application/x-www-form-urlencoded`, Claude's format).
	 * Answers fast: no external fetch here.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function token( \WP_REST_Request $request ): \WP_REST_Response {
		$params = self::body_params( $request );

		$settings = Module::settings();
		if ( ! empty( $settings['paused'] ) ) {
			return self::oauth_error( 'invalid_request', __( 'Connections to this site are paused.', 'lumia-tools' ) );
		}

		$limiter = Security::limiter();
		if ( null !== $limiter && $limiter->is_locked() ) {
			return self::oauth_error( 'invalid_request', __( 'Too many attempts. Try again later.', 'lumia-tools' ) );
		}

		$grant_type = self::value( $params, 'grant_type' );

		if ( 'authorization_code' === $grant_type ) {
			return self::exchange_code( $params );
		}

		if ( 'refresh_token' === $grant_type ) {
			return self::refresh( $params );
		}

		if ( '' === $grant_type ) {
			return self::oauth_error( 'invalid_request', __( 'The grant_type parameter is missing.', 'lumia-tools' ) );
		}

		return self::oauth_error( 'unsupported_grant_type', __( 'Only the authorization_code and refresh_token grants are supported.', 'lumia-tools' ) );
	}

	/**
	 * `authorization_code` grant.
	 *
	 * Everything is checked before the code is consumed, so a request without
	 * the right verifier cannot burn the legitimate client's code. A code seen
	 * twice is a replay: the connection is revoked, which also deletes any
	 * token issued from it (OAuth 2.1 §4.1.3).
	 *
	 * @param array<string, mixed> $params Body parameters.
	 */
	private static function exchange_code( array $params ): \WP_REST_Response {
		$invalid = __( 'The authorization code is invalid, expired or already used.', 'lumia-tools' );

		$value = self::value( $params, 'code' );
		$code  = Tokens::find( $value, Tokens::CODE );
		if ( null === $code ) {
			// A well-formed code that is unknown was issued and deleted since
			// (connection revoked, purge): not an attempt to guess one.
			if ( ! Tokens::is_well_formed( $value, Tokens::CODE ) ) {
				self::log_failed_attempt();
			}
			return self::oauth_error( 'invalid_grant', $invalid );
		}

		$connection_id = $code['connection_id'];

		if ( 0 !== $code['used'] ) {
			Connections::revoke( $connection_id, 'reuse' );
			return self::oauth_error( 'invalid_grant', $invalid );
		}

		if ( Tokens::is_expired( $code ) ) {
			return self::oauth_error( 'invalid_grant', $invalid );
		}

		$bound = $code['data'];

		if ( self::value( $params, 'client_id' ) !== (string) ( $bound['client_id'] ?? '' )
			|| self::value( $params, 'redirect_uri' ) !== (string) ( $bound['redirect_uri'] ?? '' ) ) {
			return self::oauth_error( 'invalid_grant', $invalid );
		}

		$resource = self::value( $params, 'resource' );
		if ( '' !== $resource && (string) ( $bound['resource'] ?? '' ) !== $resource ) {
			return self::oauth_error( 'invalid_target', __( 'The resource parameter does not match the authorization request.', 'lumia-tools' ) );
		}

		$verifier = self::value( $params, 'code_verifier' );
		if ( ! preg_match( self::PKCE_PATTERN, $verifier ) || ! hash_equals( (string) ( $bound['code_challenge'] ?? '' ), self::pkce_challenge( $verifier ) ) ) {
			self::log_failed_attempt();
			return self::oauth_error( 'invalid_grant', __( 'The code verifier does not match the code challenge.', 'lumia-tools' ) );
		}

		$connection = Connections::get( $connection_id );
		if ( null === $connection || 'pending' !== ( $connection['status'] ?? '' ) ) {
			return self::oauth_error( 'invalid_grant', $invalid );
		}

		// Two exchanges racing past the checks above: only one consumes.
		if ( ! Tokens::consume( $code['id'] ) ) {
			Connections::revoke( $connection_id, 'reuse' );
			return self::oauth_error( 'invalid_grant', $invalid );
		}

		// The losing side of a race may have revoked the connection between
		// our consume() and here: then nothing is issued, nothing is logged.
		if ( ! Connections::activate( $connection_id, time() + Tokens::refresh_ttl() ) ) {
			return self::oauth_error( 'invalid_grant', $invalid );
		}

		$scope    = (string) ( $bound['scope'] ?? Discovery::SCOPE );
		$response = self::issue_tokens( $connection_id, $scope );

		// The connection becomes real here, not at the consent: a code never
		// exchanged leaves no connection behind.
		if ( 200 === $response->get_status() ) {
			do_action( 'lumia_mcp_connect_authorized', $connection_id );
		}

		return $response;
	}

	/**
	 * `refresh_token` grant, with rotation: every use returns a new refresh
	 * token and marks the old one used. Presenting a used one again means two
	 * parties hold it (theft): the whole connection is revoked.
	 *
	 * Except within REFRESH_REUSE_GRACE seconds of the rotation, for the same
	 * client: then it is far more likely a retry than a theft. The response of
	 * the first refresh can be lost after PHP committed it (proxy 502/504,
	 * client timeout), and two tool calls hitting a 401 at once both refresh
	 * with the same token. Revoking there would drop a healthy connector and
	 * log a theft for a network hiccup. Inside the window the answer is a
	 * plain `invalid_grant`, nothing is revoked: the client that holds the
	 * successor keeps working, the other one reconnects. A thief racing the
	 * legitimate client inside the window gains nothing either: the token was
	 * already consumed, no new one is issued to them.
	 *
	 * Optional parameters (RFC 6749 §6, RFC 8707): `resource` must be the
	 * connection's resource (`invalid_target`), `scope` a subset of what was
	 * granted (`invalid_scope`); both are checked before the token is consumed.
	 *
	 * @param array<string, mixed> $params Body parameters.
	 */
	private static function refresh( array $params ): \WP_REST_Response {
		$invalid = __( 'The refresh token is invalid, expired or revoked.', 'lumia-tools' );

		$value = self::value( $params, 'refresh_token' );
		$token = Tokens::find( $value, Tokens::REFRESH );
		if ( null === $token ) {
			// Well-formed but unknown: a token we issued, deleted since by a
			// revocation or the purge. Claude sends it in good faith.
			if ( ! Tokens::is_well_formed( $value, Tokens::REFRESH ) ) {
				self::log_failed_attempt();
			}
			return self::oauth_error( 'invalid_grant', $invalid );
		}

		$connection_id = $token['connection_id'];
		$connection    = Connections::get( $connection_id );

		if ( null === $connection || ! Connections::is_active( $connection ) ) {
			return self::oauth_error( 'invalid_grant', $invalid );
		}

		// Before the reuse test: a request naming another client cannot
		// revoke this connection.
		if ( self::value( $params, 'client_id' ) !== (string) ( $connection['client_id'] ?? '' ) ) {
			return self::oauth_error( 'invalid_grant', $invalid );
		}

		if ( 0 !== $token['used'] ) {
			return self::refresh_used( $token, $connection_id, $invalid );
		}

		if ( Tokens::is_expired( $token ) ) {
			return self::oauth_error( 'invalid_grant', $invalid );
		}

		// A user deleted while the module was off (no delete_user listener).
		if ( Connections::user_missing( $connection ) ) {
			Connections::revoke( $connection_id, 'user_deleted' );
			return self::oauth_error( 'invalid_grant', $invalid );
		}

		$resource = self::value( $params, 'resource' );
		if ( '' !== $resource && (string) ( $connection['resource'] ?? '' ) !== $resource ) {
			return self::oauth_error( 'invalid_target', __( 'The resource parameter does not match this connection.', 'lumia-tools' ) );
		}

		// offline_access is implied: the client holds a refresh token. Older
		// connections stored `mcp` alone.
		$granted = array_merge( self::split_scope( (string) ( $connection['scope'] ?? '' ) ), [ Discovery::SCOPE_OFFLINE ] );
		if ( array_diff( self::split_scope( self::value( $params, 'scope' ) ), $granted ) ) {
			return self::oauth_error( 'invalid_scope', __( 'The requested scope exceeds what was granted to this connection.', 'lumia-tools' ) );
		}

		// Two refreshes racing with the same token: the loser read the row
		// before the winner consumed it. Same rule as a used token.
		if ( ! Tokens::consume( $token['id'] ) ) {
			$again = Tokens::find( $value, Tokens::REFRESH );

			return null === $again ? self::oauth_error( 'invalid_grant', $invalid ) : self::refresh_used( $again, $connection_id, $invalid );
		}

		// The loser of such a race may have revoked the connection meanwhile
		// (outside the grace window): issue nothing for a dead connection.
		if ( ! Connections::is_active( Connections::get( $connection_id ) ) ) {
			return self::oauth_error( 'invalid_grant', $invalid );
		}

		Connections::set_refresh_expiry( $connection_id, time() + Tokens::refresh_ttl() );

		return self::issue_tokens( $connection_id, (string) ( $connection['scope'] ?? Discovery::SCOPE ) );
	}

	/**
	 * A used refresh token presented again. Within the grace window of its
	 * rotation, a retry: refused, nothing revoked. Outside it, a reuse:
	 * reported, then the connection is revoked (the action fires first, while
	 * the connection is still active).
	 *
	 * @param array<string, mixed> $token         Token row (used).
	 * @param int                  $connection_id Connection.
	 * @param string               $description   Error description.
	 */
	private static function refresh_used( array $token, int $connection_id, string $description ): \WP_REST_Response {
		if ( Tokens::used_within( $token, self::REFRESH_REUSE_GRACE ) ) {
			return self::oauth_error( 'invalid_grant', $description );
		}

		do_action( 'lumia_mcp_connect_refresh_reuse', $connection_id );
		Connections::revoke( $connection_id, 'reuse' );

		return self::oauth_error( 'invalid_grant', $description );
	}

	/**
	 * Issues an access token and a refresh token for a connection.
	 *
	 * A refresh token is always issued, and the granted scope always carries
	 * `offline_access` (see normalize_scope()): a connection that cannot
	 * refresh would need a new consent every hour.
	 *
	 * @param int    $connection_id Connection.
	 * @param string $scope         Granted scope.
	 */
	private static function issue_tokens( int $connection_id, string $scope ): \WP_REST_Response {
		$access_ttl = Tokens::access_ttl();
		$access     = Tokens::issue( Tokens::ACCESS, $connection_id, $access_ttl );
		$refresh    = Tokens::issue( Tokens::REFRESH, $connection_id, Tokens::refresh_ttl() );

		if ( '' === $access || '' === $refresh ) {
			return self::oauth_error( 'server_error', __( 'The tokens could not be stored.', 'lumia-tools' ), 500 );
		}

		return self::no_store(
			new \WP_REST_Response(
				[
					'access_token'  => $access,
					'token_type'    => 'Bearer',
					'expires_in'    => $access_ttl,
					'refresh_token' => $refresh,
					'scope'         => $scope,
				],
				200
			)
		);
	}

	/**
	 * PKCE S256 challenge of a verifier: BASE64URL( SHA256( verifier ) ).
	 *
	 * @param string $verifier Code verifier.
	 */
	private static function pkce_challenge( string $verifier ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- RFC 7636 S256 transform, not obfuscation.
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	}

	/* ===== REVOCATION ENDPOINT ===== */

	/**
	 * Revocation endpoint (RFC 7009). Always `200`, unknown token included, so
	 * the endpoint tells nothing about which tokens exist.
	 *
	 * A refresh token revokes its whole connection; an access token is deleted
	 * alone (the client keeps its refresh token, as RFC 7009 allows).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function revoke( \WP_REST_Request $request ): \WP_REST_Response {
		$params = self::body_params( $request );

		$limiter = Security::limiter();
		if ( null !== $limiter && $limiter->is_locked() ) {
			return self::oauth_error( 'invalid_request', __( 'Too many attempts. Try again later.', 'lumia-tools' ) );
		}

		$token     = self::value( $params, 'token' );
		$client_id = self::value( $params, 'client_id' );

		// Tokens::find() answers null without a query when the prefix does not
		// match, so trying both types costs one query at most.
		$refresh = Tokens::find( $token, Tokens::REFRESH );
		if ( null !== $refresh ) {
			if ( self::client_matches( $refresh['connection_id'], $client_id ) ) {
				Connections::revoke( $refresh['connection_id'], 'token' );
			}
		} else {
			$access = Tokens::find( $token, Tokens::ACCESS );
			if ( null !== $access && self::client_matches( $access['connection_id'], $client_id ) ) {
				Tokens::delete( $access['id'] );
			}
		}

		return self::no_store( new \WP_REST_Response( (object) [], 200 ) );
	}

	/**
	 * Whether a revocation may act on a connection: a public client is not
	 * authenticated, but when it names itself it must be the connection's
	 * client.
	 *
	 * @param int    $connection_id Connection.
	 * @param string $client_id     client_id sent ('' if none).
	 */
	private static function client_matches( int $connection_id, string $client_id ): bool {
		if ( '' === $client_id ) {
			return true;
		}

		$connection = Connections::get( $connection_id );

		return null !== $connection && (string) ( $connection['client_id'] ?? '' ) === $client_id;
	}

	/* ===== REGISTRATION ENDPOINT ===== */

	/**
	 * Dynamic Client Registration (RFC 7591), registered only when DCR is on.
	 * Validation, rate limit and storage live in ClientRegistry::register_dcr().
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function register( \WP_REST_Request $request ): \WP_REST_Response {
		$metadata = $request->get_json_params();
		if ( ! is_array( $metadata ) ) {
			return self::oauth_error( 'invalid_client_metadata', __( 'The registration request must be a JSON object.', 'lumia-tools' ) );
		}

		$result = ClientRegistry::register_dcr( $metadata, Security::client_ip() );

		if ( is_wp_error( $result ) ) {
			$code   = (string) $result->get_error_code();
			$data   = $result->get_error_data();
			$code   = in_array( $code, self::REGISTRATION_ERRORS, true ) || 'invalid_request' === $code || 'temporarily_unavailable' === $code ? $code : 'invalid_client_metadata';
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;

			return self::oauth_error( $code, $result->get_error_message(), $status >= 400 && $status < 600 ? $status : 400 );
		}

		return self::no_store( new \WP_REST_Response( $result, 201 ) );
	}

	/* ===== SITE HEALTH PROBE ===== */

	/**
	 * Tells whether the probe's `Authorization` header reached PHP, through
	 * the same reader as the Bearer authentication. Never authenticates.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function ping( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );

		$received = 0 === strcasecmp( self::PROBE_HEADER, trim( BearerAuth::authorization_header() ) );

		return self::no_store( new \WP_REST_Response( [ 'authorization' => $received ], 200 ) );
	}

	/* ===== HELPERS ===== */

	/**
	 * Parameters of the POST body: form-encoded (the OAuth format), else a
	 * JSON body. Never the query string: codes, verifiers and refresh tokens
	 * sent there end up in the access logs of every proxy on the way (RFC 6749
	 * §3.2 and OAuth 2.1 require them in the body), so a value sent in the URL
	 * is simply not seen and the request fails as if it were missing.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private static function body_params( \WP_REST_Request $request ): array {
		$params = $request->get_body_params();

		if ( empty( $params ) ) {
			$params = $request->get_json_params();
		}

		return is_array( $params ) ? $params : [];
	}

	/**
	 * A single-valued request parameter, or ''.
	 *
	 * Validated rather than sanitized: an OAuth value (state, URI, code) must
	 * come back to the client byte for byte, which sanitize_text_field() would
	 * not guarantee (it strips percent-encoded octets). Every value read here
	 * is then compared with stored or computed values, matched against a
	 * strict pattern, or echoed URL-encoded into a redirect. Arrays, control
	 * characters and oversized values are refused. The REST server has already
	 * unslashed the parameters.
	 *
	 * @param array<string, mixed> $params Parameters.
	 * @param string               $key    Name.
	 */
	private static function value( array $params, string $key ): string {
		$value = $params[ $key ] ?? '';

		if ( ! is_string( $value ) || strlen( $value ) > 2048 || ! preg_match( '/^[\x20-\x7E]*$/', $value ) ) {
			return '';
		}

		return $value;
	}

	/**
	 * value() for a parameter of the request (query or body).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string           $key     Name.
	 */
	private static function string_param( \WP_REST_Request $request, string $key ): string {
		return self::value( [ $key => $request->get_param( $key ) ], $key );
	}

	/**
	 * RFC 6749 §5.2 error document.
	 *
	 * @param string $error       Error code.
	 * @param string $description Human-readable description.
	 * @param int    $status      HTTP status (400, 401 for invalid_client).
	 */
	private static function oauth_error( string $error, string $description, int $status = 400 ): \WP_REST_Response {
		if ( 'invalid_client' === $error ) {
			$status = 401;
		}

		return self::no_store(
			new \WP_REST_Response(
				[
					'error'             => $error,
					'error_description' => $description,
				],
				$status
			)
		);
	}

	/**
	 * Adds the no-cache headers (RFC 6749 §5.1) to a response, and tells page
	 * caches to keep away.
	 *
	 * @param \WP_REST_Response $response Response.
	 */
	private static function no_store( \WP_REST_Response $response ): \WP_REST_Response {
		Security::no_store_headers();

		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );

		return $response;
	}

	/**
	 * Counts a failed attempt (malformed code or token, wrong verifier) for
	 * the client IP in the Security module's limiter, when it is on.
	 */
	private static function log_failed_attempt(): void {
		$limiter = Security::limiter();

		if ( null !== $limiter ) {
			$limiter->log_failed_attempt( $limiter->get_client_ip() );
		}
	}
}
