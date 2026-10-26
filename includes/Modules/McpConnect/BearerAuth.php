<?php
namespace Lumia\Tools\Modules\McpConnect;

use Lumia\Tools\Core\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Bearer authentication on the MCP route, and on it only.
 *
 * - The access token is looked up on `determine_current_user` (@30, after the
 *   core cookie and application password handlers) when the raw request
 *   targets the MCP route. The same token on `/wp/v2/*` or anywhere else is
 *   ignored: MCP Connect gives no general REST access.
 * - A Bearer that fails answers `401 invalid_token` from
 *   `rest_authentication_errors` instead of falling back to an anonymous
 *   request; Discovery::challenge() then adds the `WWW-Authenticate` pointer.
 * - The outcome is kept for the request: the token is looked up once, whichever
 *   of the two hooks runs first.
 * - Only a malformed Bearer (not `lmc_at_` + 64 hex) counts as a failed
 *   attempt in the Security rate limiter. A well-formed one that is unknown,
 *   expired or revoked is a token we issued: revocations and the purge
 *   delete rows Claude still holds, an expired token is the normal case
 *   (Claude refreshes on the `401`), and the hosted Claude apps share one
 *   egress range, so counting them would lock Claude itself out. 256 random
 *   bits cannot be brute-forced anyway. log_successful_login() is never
 *   called: it would wipe the IP's failed login-form attempts.
 * - The raw-request test (Discovery::is_protected_request()) decides whether
 *   the token is looked up; restrict_dispatch() then refuses, at dispatch
 *   time, any route other than the MCP one for a request authenticated by a
 *   token, whatever the way the route was chosen.
 */
final class BearerAuth {

	/** Shape of a well-formed access token: prefix + 256 bits in hex. */
	const TOKEN_PATTERN = '/^lmc_at_[0-9a-f]{64}$/';

	/** Failure reasons kept for reject(). */
	const FAILURE_INVALID = 'invalid';
	const FAILURE_PAUSED  = 'paused';
	const FAILURE_LOCKED  = 'locked';

	/** Whether the Bearer of this request has been checked. */
	private static bool $checked = false;

	/** User id the Bearer authenticated, 0 if none. */
	private static int $user_id = 0;

	/** Why the Bearer was refused ('' when it was not, or not checked). */
	private static string $failure = '';

	/**
	 * Connection authenticated in this request.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $connection = null;

	/**
	 * `determine_current_user` @30: the connection's acting user when the
	 * request targets the MCP route with a valid access token.
	 *
	 * @param int|false|mixed $user_id User already identified, or false.
	 * @return int|false|mixed
	 */
	public static function authenticate( $user_id ) {
		if ( ! empty( $user_id ) ) {
			return $user_id;
		}

		if ( ! self::$checked ) {
			self::$checked = true;
			self::$user_id = self::check();
		}

		return self::$user_id > 0 ? self::$user_id : $user_id;
	}

	/**
	 * `rest_authentication_errors` @90: a Bearer that failed answers `401
	 * invalid_token` rather than an anonymous request.
	 *
	 * @param \WP_Error|null|true|mixed $result Result of the previous handlers.
	 * @return \WP_Error|null|true|mixed
	 */
	public static function reject( $result ) {
		if ( null !== $result || ! Discovery::is_protected_request() || ! self::has_bearer() ) {
			return $result;
		}

		// Resolving the current user runs determine_current_user, hence
		// authenticate(), if nothing did yet. A user found means the request is
		// authenticated, by our Bearer or by something else (cookie,
		// application password): left alone.
		if ( 0 !== get_current_user_id() ) {
			return $result;
		}

		// Anonymous. When the user was resolved before our filter could run,
		// the check has not happened: run it now (memoized, so a no-op when it
		// already ran), so a bad token still gets a clear `invalid_token`.
		self::authenticate( 0 );

		if ( '' === self::$failure ) {
			return $result;
		}

		switch ( self::$failure ) {
			case self::FAILURE_PAUSED:
				$message = __( 'Connections to this site are paused.', 'lumia-tools' );
				break;
			case self::FAILURE_LOCKED:
				$message = __( 'Too many failed attempts from this IP address. Try again later.', 'lumia-tools' );
				break;
			default:
				$message = __( 'The access token is invalid or expired.', 'lumia-tools' );
		}

		return new \WP_Error( 'invalid_token', $message, [ 'status' => 401 ] );
	}

	/**
	 * `rest_pre_dispatch` (late): a request authenticated by an MCP Connect
	 * token may only reach the MCP route. Second line behind
	 * Discovery::is_protected_request(): WordPress picks the REST route from
	 * several sources (path, `?rest_route=`, a POST `rest_route`, method
	 * override, `/batch/v1`), and the route it finally dispatches is the only
	 * one that matters.
	 *
	 * @param mixed                  $result  Response so far (null to dispatch).
	 * @param \WP_REST_Server|mixed  $server  Server.
	 * @param \WP_REST_Request|mixed $request Request.
	 * @return mixed
	 */
	public static function restrict_dispatch( $result, $server, $request ) {
		unset( $server );

		if ( null === self::$connection || ! $request instanceof \WP_REST_Request || Discovery::is_mcp_route( $request->get_route() ) ) {
			return $result;
		}

		return new \WP_Error( 'invalid_token', __( 'This access token is only valid for the MCP server of this site.', 'lumia-tools' ), [ 'status' => 401 ] );
	}

	/**
	 * The connection authenticated in this request, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function current_connection(): ?array {
		return self::$connection;
	}

	/**
	 * Access token of the request, or '' when there is no Bearer or when its
	 * value cannot be one of our access tokens (then no query is made).
	 */
	public static function bearer_token(): string {
		if ( ! preg_match( '/^Bearer\s+(\S+)$/i', trim( self::authorization_header() ), $matches ) ) {
			return '';
		}

		return preg_match( self::TOKEN_PATTERN, $matches[1] ) ? $matches[1] : '';
	}

	/**
	 * Raw `Authorization` header of the request, or ''.
	 *
	 * PHP-FPM behind nginx gets `HTTP_AUTHORIZATION`. Apache drops the header
	 * unless a rewrite rule copies it (`REDIRECT_HTTP_AUTHORIZATION`); under
	 * mod_php it is still readable through getallheaders(). Also read by the
	 * Site Health probe (`/ping`), so it tests exactly this path.
	 */
	public static function authorization_header(): string {
		foreach ( [ 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ] as $key ) {
			if ( isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) && '' !== $_SERVER[ $key ] ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Matched against a strict pattern before any use, never output or stored.
				return (string) wp_unslash( $_SERVER[ $key ] );
			}
		}

		if ( function_exists( 'getallheaders' ) ) {
			foreach ( (array) getallheaders() as $name => $value ) {
				if ( is_string( $name ) && 'authorization' === strtolower( $name ) && is_string( $value ) ) {
					return $value;
				}
			}
		}

		return '';
	}

	/**
	 * Whether the request carries a Bearer credential, well-formed or not.
	 */
	private static function has_bearer(): bool {
		return 1 === preg_match( '/^Bearer(\s|$)/i', trim( self::authorization_header() ) );
	}

	/**
	 * Checks the Bearer of the request. Records the failure reason, or the
	 * connection on success.
	 *
	 * @return int User id, 0 if the request is not authenticated by a Bearer.
	 */
	private static function check(): int {
		if ( ! Discovery::is_protected_request() || ! self::has_bearer() ) {
			return 0;
		}

		// Plugin on hold (the former plugin still active, or a failed
		// migration): no module runs, so neither does the rest of MCP Connect.
		// The filter was added at file load, before the hold could be known:
		// refuse here.
		if ( Plugin::is_on_hold() ) {
			self::$failure = self::FAILURE_PAUSED;
			return 0;
		}

		$settings = Module::settings();
		if ( ! empty( $settings['paused'] ) ) {
			self::$failure = self::FAILURE_PAUSED;
			return 0;
		}

		$limiter = Security::limiter();
		if ( null !== $limiter && $limiter->is_locked() ) {
			self::$failure = self::FAILURE_LOCKED;
			return 0;
		}

		$token = self::bearer_token();

		// Malformed: the only case counted as a failed attempt.
		if ( '' === $token ) {
			if ( null !== $limiter ) {
				$limiter->log_failed_attempt( $limiter->get_client_ip() );
			}
			self::$failure = self::FAILURE_INVALID;
			return 0;
		}

		$row = Tokens::find( $token, Tokens::ACCESS );

		// Unknown (revoked or purged), expired, used, or connection gone:
		// Claude refreshes or reconnects, not a failed attempt.
		if ( null === $row || 0 !== $row['used'] || Tokens::is_expired( $row ) ) {
			self::$failure = self::FAILURE_INVALID;
			return 0;
		}

		$connection = Connections::get( $row['connection_id'] );
		if ( null === $connection || ! Connections::is_active( $connection ) ) {
			self::$failure = self::FAILURE_INVALID;
			return 0;
		}

		// A user deleted while the module was off: the delete_user listener
		// did not run, so the rule is enforced here. The revocation waits for
		// `shutdown`: its listeners (Activity Log) resolve the current user,
		// which must not happen inside determine_current_user.
		if ( Connections::user_missing( $connection ) ) {
			$connection_id = (int) $connection['id'];
			add_action(
				'shutdown',
				static function () use ( $connection_id ): void {
					Connections::revoke( $connection_id, 'user_deleted' );
				}
			);
			self::$failure = self::FAILURE_INVALID;
			return 0;
		}

		$user = self::acting_user( $connection );
		if ( null === $user ) {
			self::$failure = self::FAILURE_INVALID;
			return 0;
		}

		$connection_id = (int) $connection['id'];

		Connections::touch( $connection_id, Security::client_ip() );
		self::$connection = $connection;
		self::tag_activity_log( $connection );

		return $user->ID;
	}

	/**
	 * User the connection acts as, if it still may.
	 *
	 * An agent connection must still point to a user holding the agent role
	 * (an account changed by hand must not inherit the connection). An
	 * "act as me" connection must still belong to an approver: a demoted
	 * administrator loses it.
	 *
	 * @param array<string, mixed> $connection Connection row.
	 */
	private static function acting_user( array $connection ): ?\WP_User {
		$user = get_userdata( (int) ( $connection['acting_user_id'] ?? 0 ) );
		if ( ! $user instanceof \WP_User ) {
			return null;
		}

		if ( empty( $connection['act_as_approver'] ) ) {
			return Agent::is_agent( $user ) ? $user : null;
		}

		return user_can( $user, Module::approver_capability() ) ? $user : null;
	}

	/**
	 * Marks every Activity Log row of this request as made through MCP, with
	 * the connection label and id (never the token). Hooked only after a
	 * successful authentication, so it costs nothing elsewhere and needs no
	 * guard when the Activity Log is inactive.
	 *
	 * @param array<string, mixed> $connection Connection row.
	 */
	private static function tag_activity_log( array $connection ): void {
		$label = (string) ( $connection['label'] ?? '' );
		if ( '' === $label ) {
			$label = (string) ( $connection['client_name'] ?? '' );
		}
		$id = (int) $connection['id'];

		add_filter(
			'lumia_activity_log_record',
			static function ( $row ) use ( $label, $id ) {
				if ( is_array( $row ) ) {
					$details = isset( $row['details'] ) && is_array( $row['details'] ) ? $row['details'] : [];

					$details['via']           = 'mcp';
					$details['connection']    = $label;
					$details['connection_id'] = $id;

					$row['details'] = $details;
				}

				return $row;
			}
		);
	}
}
