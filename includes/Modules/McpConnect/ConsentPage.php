<?php
namespace Lumia\Tools\Modules\McpConnect;

defined( 'ABSPATH' ) || exit;

use Lumia\Tools\Admin\Admin;

/**
 * Consent screen of the OAuth flow: the page `/authorize` sends the browser to,
 * where an approver sees who asks for access and approves or denies it.
 *
 * - A wp-admin page rather than a front-end one: the sign-in (custom login slug
 *   of the Login module included) comes for free through `auth_redirect()`, and
 *   the approval is an ordinary `admin-post.php` form with a nonce.
 * - Hidden page (empty parent) with the `read` capability, so that any signed-in
 *   user reaches it: the approver capability is checked inside, to show a clear
 *   refusal instead of WordPress's generic "not allowed" page.
 * - The nonce is bound to the authorization request (hash of its id) on top of
 *   the user: a nonce shown for one request cannot approve another one.
 * - The request id is consumed atomically on submit: a double click, a second
 *   tab or a replayed POST cannot mint two codes.
 * - Its URL never goes through `wp-login.php`: the Security module's `site_url`
 *   filter re-parses such URLs and would mangle the redirect.
 */
final class ConsentPage {

	/** Page slug (`admin.php?page=…`). */
	const SLUG = 'lumia-mcp-consent';

	/** `admin-post.php` action of the consent form. */
	const ACTION = 'lumia_mcp_consent';

	/** Name of the nonce field. */
	const NONCE_FIELD = 'lumia_nonce';

	/** Maximum length of the connection label. */
	const LABEL_MAX = 100;

	/** Lifetime of the authorization code, in seconds (exchanged at once by the client). */
	const CODE_TTL = 60;

	/**
	 * `admin_menu`: registers the hidden page and its `load-` hook.
	 */
	public static function register(): void {
		// Empty string, not null, as parent: null triggers deprecation notices on
		// PHP 8.1+ in plugin_basename(). The page has no menu entry either way.
		$hook = add_submenu_page(
			'',
			__( 'Connect to Claude', 'lumia-tools' ),
			'',
			'read',
			self::SLUG,
			[ self::class, 'render' ]
		);

		if ( is_string( $hook ) && '' !== $hook ) {
			add_action( 'load-' . $hook, [ self::class, 'load' ] );
		}
	}

	/**
	 * `load-admin_page_lumia-mcp-consent`: security headers and styles, before
	 * any output.
	 */
	public static function load(): void {
		// A hidden page has no menu entry for get_admin_page_title() to read the
		// title from: admin-header.php would then pass null to strip_tags().
		global $title;
		$title = __( 'Connect to Claude', 'lumia-tools' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- title of a hidden admin page, read by admin-header.php.

		// Never framed (clickjacking of the Approve button), never cached, and no
		// Referer carrying the request id to the client's logo host. These
		// replace the SAMEORIGIN sent by core on admin_init.
		if ( ! headers_sent() ) {
			header( 'X-Frame-Options: DENY' );
			header( "Content-Security-Policy: frame-ancestors 'none'" );
			header( 'Referrer-Policy: same-origin' );
		}
		Security::no_store_headers();

		self::enqueue_styles();
	}

	/**
	 * Enqueues the design-system styles. Admin::enqueue_assets() only loads them
	 * on screens whose hook contains `lumia-tools`, which this one does not: the
	 * handles and paths are the same, so a screen loading both gets each file once.
	 */
	public static function enqueue_styles(): void {
		wp_enqueue_style( 'lumia-tokens-css', LUMIA_ASSETS_URL . 'admin/css/tokens.css', [], LUMIA_VERSION );
		wp_enqueue_style( 'lumia-reset-css', LUMIA_ASSETS_URL . 'admin/css/reset.css', [ 'lumia-tokens-css' ], LUMIA_VERSION );
		wp_enqueue_style( 'lumia-components-css', LUMIA_ASSETS_URL . 'admin/css/components.css', [ 'lumia-tokens-css' ], LUMIA_VERSION );
		wp_enqueue_style( 'lumia-buttons-css', LUMIA_ASSETS_URL . 'admin/css/buttons.css', [ 'lumia-components-css' ], LUMIA_VERSION );
		wp_enqueue_style( 'lumia-mcp-consent-css', LUMIA_ASSETS_URL . 'admin/css/modules/mcp-connect.css', [ 'lumia-components-css', 'lumia-buttons-css' ], LUMIA_VERSION );
	}

	/**
	 * Renders the consent screen.
	 */
	public static function render(): void {
		echo '<div class="lumia-admin-wrap lumia-mcp-consent">';
		echo '<div class="lumia-card lumia-mcp-consent__panel">';

		if ( ! Discovery::enabled() ) {
			self::render_message( __( 'MCP Connect is disabled on this site.', 'lumia-tools' ) );
		} elseif ( ! current_user_can( Module::approver_capability() ) ) {
			self::render_message( __( 'Your account is not allowed to connect Claude to this site.', 'lumia-tools' ) );
		} else {
			self::render_request();
		}

		echo '</div></div>';
	}

	/**
	 * Body of the screen for an approver: the request, or why it cannot be shown.
	 */
	private static function render_request(): void {
		$request = self::request_param( 'GET' );
		$row     = '' === $request ? null : self::find_request( $request );

		if ( null === $row ) {
			self::render_message( __( 'This connection request has expired. Start again from Claude.', 'lumia-tools' ) );
			return;
		}

		$data   = self::row_data( $row );
		$client = ClientRegistry::resolve( self::str( $data, 'client_id' ) );

		if ( is_wp_error( $client ) ) {
			self::render_message( $client->get_error_message() );
			return;
		}

		$redirect_uri = self::str( $data, 'redirect_uri' );
		if ( ! ClientRegistry::redirect_uri_allowed( $client, $redirect_uri ) ) {
			self::render_message( __( 'This connection request is not valid. Start again from Claude.', 'lumia-tools' ) );
			return;
		}

		$client_name   = self::str( $client, 'name' );
		$logo_uri      = self::str( $client, 'logo_uri' );
		$publisher     = self::str( $client, 'publisher' );
		$redirect_host = strtolower( (string) wp_parse_url( $redirect_uri, PHP_URL_HOST ) );
		$site_name     = get_bloginfo( 'name' );
		$user          = wp_get_current_user();
		$can_delegate  = Agent::can_delegate( $user );

		// The dedicated account may not exist yet (approver without manage_options
		// on a fresh install): it is created on approval, under these names.
		$agent       = get_userdata( Agent::user_id() );
		$agent_name  = $agent instanceof \WP_User ? $agent->display_name : __( 'Claude (MCP)', 'lumia-tools' );
		$agent_login = $agent instanceof \WP_User ? $agent->user_login : Agent::LOGIN;

		$presets     = Agent::presets();
		$preset_name = $presets[ Agent::current_preset() ]['label'] ?? '';
		?>
		<div class="lumia-card__header lumia-mcp-consent__client">
			<?php if ( 0 === strpos( $logo_uri, 'https://' ) ) : ?>
				<img class="lumia-mcp-consent__logo" src="<?php echo esc_url( $logo_uri, [ 'https' ] ); ?>" width="40" height="40" alt="" referrerpolicy="no-referrer">
			<?php endif; ?>
			<div>
				<h1 class="lumia-card__title">
					<?php
					/* translators: 1: client name (e.g. Claude), 2: site name. */
					echo esc_html( sprintf( __( '%1$s wants to connect to %2$s', 'lumia-tools' ), $client_name, $site_name ) );
					?>
				</h1>
				<p class="lumia-card__desc"><?php echo esc_html( self::str( $data, 'resource' ) ); ?></p>
			</div>
		</div>

		<div class="lumia-card__body">
			<?php if ( ClientRegistry::is_trusted( $client ) ) : ?>
				<p class="lumia-mcp-consent__verified">
					<span class="lumia-badge lumia-badge--success">
						<?php echo Admin::icon( 'badge-check', 'sm' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static Lucide markup. ?>
						<?php
						if ( 'claude.ai' === $publisher ) {
							esc_html_e( 'Verified: Claude', 'lumia-tools' );
						} else {
							/* translators: %s: host name of the client's publisher (e.g. example.com). */
							echo esc_html( sprintf( __( 'Verified publisher: %s', 'lumia-tools' ), $publisher ) );
						}
						?>
					</span>
				</p>
			<?php else : ?>
				<div class="lumia-notice lumia-notice--warning">
					<?php echo Admin::icon( 'triangle-alert', 'sm' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static Lucide markup. ?>
					<?php esc_html_e( 'Unverified client: only connect if you started this from a tool you trust.', 'lumia-tools' ); ?>
				</div>
			<?php endif; ?>

			<table class="lumia-server-table">
				<tbody>
					<tr>
						<td class="lumia-server-table__label"><?php esc_html_e( 'Returns to', 'lumia-tools' ); ?></td>
						<td>
							<strong><?php echo esc_html( $redirect_host ); ?></strong>
							<?php if ( self::is_loopback_uri( $redirect_uri ) ) : ?>
								<p class="lumia-form__help">
									<?php
									/* translators: %s: loopback host name (e.g. localhost). */
									echo esc_html( sprintf( __( 'Claude will return to an application running on your computer (%s).', 'lumia-tools' ), $redirect_host ) );
									?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td class="lumia-server-table__label"><?php esc_html_e( 'Access', 'lumia-tools' ); ?></td>
						<td>
							<?php
							// Every connection gets a refresh token (see AuthorizationServer::normalize_scope()).
							esc_html_e( 'Use the tools this site exposes to Claude (MCP), and stay connected (refresh).', 'lumia-tools' );
							?>
						</td>
					</tr>
					<tr>
						<td class="lumia-server-table__label"><?php esc_html_e( 'Site', 'lumia-tools' ); ?></td>
						<td><?php echo esc_html( $site_name ); ?></td>
					</tr>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lumia-form lumia-mcp-consent__form">
				<?php wp_nonce_field( self::nonce_action( $request ), self::NONCE_FIELD ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="request" value="<?php echo esc_attr( $request ); ?>">

				<div class="lumia-form__group">
					<label for="lumia-mcp-consent-label" class="lumia-form__label"><?php esc_html_e( 'Connection name', 'lumia-tools' ); ?></label>
					<input type="text" id="lumia-mcp-consent-label" name="label" class="lumia-input" maxlength="<?php echo esc_attr( (string) self::LABEL_MAX ); ?>" placeholder="<?php esc_attr_e( 'e.g. Claire, MacBook', 'lumia-tools' ); ?>">
					<p class="lumia-form__help"><?php esc_html_e( 'Shown in the list of connections and in the activity log.', 'lumia-tools' ); ?></p>
				</div>

				<fieldset class="lumia-form__group lumia-mcp-consent__choices">
					<legend class="lumia-form__label"><?php esc_html_e( 'Claude will act as', 'lumia-tools' ); ?></legend>

					<?php if ( ! $can_delegate ) : ?>
						<p class="lumia-form__help">
							<?php
							/* translators: %s: display name of the dedicated account. */
							echo esc_html( sprintf( __( 'The dedicated account %s has permissions your own account does not have, so Claude can only act as you.', 'lumia-tools' ), $agent_name ) );
							?>
						</p>
					<?php else : ?>
						<label class="lumia-mcp-consent__choice">
							<input type="radio" name="acting" value="agent" checked>
							<span>
								<strong>
									<?php
									/* translators: 1: display name of the dedicated account, 2: its login. */
									echo esc_html( sprintf( __( '%1$s (%2$s)', 'lumia-tools' ), $agent_name, $agent_login ) );
									?>
								</strong>
								<span class="lumia-form__help">
									<?php
									if ( Agent::PRESET_ADMIN === Agent::current_preset() ) {
										esc_html_e( 'Dedicated account with every right of an administrator: whoever holds this connection controls the whole site.', 'lumia-tools' );
									} elseif ( '' !== $preset_name ) {
										/* translators: %s: name of the permission preset (Editor or Administrator). */
										echo esc_html( sprintf( __( 'Dedicated account, permissions: %s. Recommended.', 'lumia-tools' ), $preset_name ) );
									} else {
										esc_html_e( 'Dedicated account with limited permissions. Recommended.', 'lumia-tools' );
									}
									?>
								</span>
							</span>
						</label>
					<?php endif; ?>

					<label class="lumia-mcp-consent__choice">
						<input type="radio" name="acting" value="me" <?php checked( ! $can_delegate ); ?>>
						<span>
							<strong>
								<?php
								/* translators: %s: display name of the current user. */
								echo esc_html( sprintf( __( 'You (%s)', 'lumia-tools' ), $user->display_name ) );
								?>
							</strong>
							<span class="lumia-form__help"><?php esc_html_e( 'Changes will be made in your name, with all your permissions. Use only for one-off administration tasks.', 'lumia-tools' ); ?></span>
						</span>
					</label>
				</fieldset>

				<div class="lumia-form__actions">
					<button type="submit" name="decision" value="deny" class="lumia-btn lumia-btn--secondary"><?php esc_html_e( 'Deny', 'lumia-tools' ); ?></button>
					<button type="submit" name="decision" value="approve" class="lumia-btn lumia-btn--primary"><?php esc_html_e( 'Approve', 'lumia-tools' ); ?></button>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Panel holding a single message (refusal, expired request, error).
	 */
	private static function render_message( string $message ): void {
		?>
		<div class="lumia-card__header">
			<h1 class="lumia-card__title"><?php esc_html_e( 'Connect to Claude', 'lumia-tools' ); ?></h1>
		</div>
		<div class="lumia-card__body">
			<div class="lumia-notice lumia-notice--warning"><?php echo esc_html( $message ); ?></div>
		</div>
		<?php
	}

	/**
	 * `admin_post_lumia_mcp_consent`: approval or denial of a request.
	 *
	 * Errors before the redirect URI is known to be valid end on a wp_die()
	 * page, never on a redirect: an unchecked URI would make this an open
	 * redirector carrying an authorization code.
	 */
	public static function handle(): void {
		Security::no_store_headers();

		if ( ! Discovery::enabled() ) {
			wp_die( esc_html__( 'MCP Connect is disabled on this site.', 'lumia-tools' ), '', [ 'response' => 404 ] );
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		if ( 'POST' !== $method ) {
			wp_die( esc_html__( 'Invalid request.', 'lumia-tools' ), '', [ 'response' => 405 ] );
		}

		$request = self::request_param( 'POST' );
		if ( '' === $request ) {
			wp_die( esc_html__( 'This connection request has expired. Start again from Claude.', 'lumia-tools' ), '', [ 'response' => 400 ] );
		}

		check_admin_referer( self::nonce_action( $request ), self::NONCE_FIELD );

		if ( ! current_user_can( Module::approver_capability() ) ) {
			wp_die( esc_html__( 'Your account is not allowed to connect Claude to this site.', 'lumia-tools' ), '', [ 'response' => 403 ] );
		}

		$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		$acting   = isset( $_POST['acting'] ) ? sanitize_key( wp_unslash( $_POST['acting'] ) ) : 'agent';
		$as_me    = 'me' === $acting;

		// The form does not offer the dedicated account to an approver lacking
		// its capabilities; a forged POST is refused before the request is
		// consumed, so the approver can still choose again.
		if ( 'approve' === $decision && ! $as_me && ! Agent::can_delegate( wp_get_current_user() ) ) {
			wp_die( esc_html__( 'The dedicated account has permissions your own account does not have: choose to let Claude act as you.', 'lumia-tools' ), '', [ 'response' => 403 ] );
		}

		// Single use: whoever consumes the row first wins, the other submit stops here.
		$row = self::find_request( $request );
		if ( null === $row || ! Tokens::consume( (int) $row['id'] ) ) {
			wp_die( esc_html__( 'This connection request has expired. Start again from Claude.', 'lumia-tools' ), '', [ 'response' => 400 ] );
		}

		$data   = self::row_data( $row );
		$client = ClientRegistry::resolve( self::str( $data, 'client_id' ) );
		if ( is_wp_error( $client ) ) {
			wp_die( esc_html( $client->get_error_message() ), esc_html__( 'Connection refused', 'lumia-tools' ), [ 'response' => 400 ] );
		}

		$redirect_uri = self::str( $data, 'redirect_uri' );
		if ( ! ClientRegistry::redirect_uri_allowed( $client, $redirect_uri ) ) {
			wp_die( esc_html__( 'This connection request is not valid. Start again from Claude.', 'lumia-tools' ), esc_html__( 'Connection refused', 'lumia-tools' ), [ 'response' => 400 ] );
		}

		$state = self::str( $data, 'state' );

		// From here the redirect URI is trusted: errors go back to the client, as
		// RFC 6749 §4.1.2.1 wants. Settings may have changed since /authorize.
		$settings = Module::settings();
		if ( ! empty( $settings['paused'] ) ) {
			self::redirect_error( $redirect_uri, $state, 'temporarily_unavailable', 'Connections to this site are paused.' );
		}
		if ( ! empty( $settings['trusted_only'] ) && ! ClientRegistry::is_trusted( $client ) ) {
			self::redirect_error( $redirect_uri, $state, 'access_denied', 'This site only accepts clients from trusted publishers.' );
		}

		if ( 'approve' !== $decision ) {
			do_action( 'lumia_mcp_connect_denied', self::str( $client, 'name' ), self::str( $client, 'client_id' ) );
			self::redirect_error( $redirect_uri, $state, 'access_denied', 'The user denied the request.' );
		}

		$label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		$label = mb_substr( $label, 0, self::LABEL_MAX );

		$approver  = get_current_user_id();
		$acting_id = $as_me ? $approver : Agent::ensure();

		if ( $acting_id <= 0 ) {
			wp_die( esc_html__( 'The Claude (MCP) account could not be created.', 'lumia-tools' ), '', [ 'response' => 500 ] );
		}

		$scope         = self::str( $data, 'scope' );
		$connection_id = Connections::create_pending( $client, $approver, $acting_id, $as_me, $label, $scope );
		if ( $connection_id <= 0 ) {
			wp_die( esc_html__( 'The connection could not be saved.', 'lumia-tools' ), '', [ 'response' => 500 ] );
		}

		$code = Tokens::issue(
			Tokens::CODE,
			$connection_id,
			self::CODE_TTL,
			[
				'client_id'      => self::str( $client, 'client_id' ),
				'redirect_uri'   => $redirect_uri,
				'code_challenge' => self::str( $data, 'code_challenge' ),
				'resource'       => self::str( $data, 'resource' ),
				'scope'          => $scope,
			]
		);

		// Without a stored code the client would get an empty one and fail at
		// the token endpoint with no explanation: drop the connection and say so.
		if ( '' === $code ) {
			Connections::revoke( $connection_id, 'expired' );
			self::redirect_error( $redirect_uri, $state, 'server_error', 'The authorization code could not be stored.' );
		}

		$params = [ 'code' => $code ];
		if ( '' !== $state ) {
			$params['state'] = $state;
		}
		$params['iss'] = Discovery::issuer();

		self::redirect( $redirect_uri, $params );
	}

	/**
	 * Sends the browser back to the client with an OAuth error.
	 *
	 * @return never
	 */
	private static function redirect_error( string $redirect_uri, string $state, string $error, string $description ): void {
		// Protocol values, read by the client and not shown in WordPress: English, untranslated.
		$params = [
			'error'             => $error,
			'error_description' => $description,
		];
		if ( '' !== $state ) {
			$params['state'] = $state;
		}
		$params['iss'] = Discovery::issuer();

		self::redirect( $redirect_uri, $params );
	}

	/**
	 * Sends the browser to the client's redirect URI with the given parameters.
	 *
	 * `https` URIs get a plain 302. Loopback `http` URIs (Claude Code) get an
	 * interstitial page instead: the CSP of our nginx template carries
	 * `form-action 'self' https:`, which Chromium also applies to the redirects
	 * that follow a form POST, so a 302 to `http://localhost:…` would be blocked.
	 * A navigation started by the page itself is not subject to `form-action`.
	 *
	 * @param string                $redirect_uri Redirect URI, already checked against the client.
	 * @param array<string, string> $params       Query parameters to add.
	 * @return never
	 */
	private static function redirect( string $redirect_uri, array $params ): void {
		// add_query_arg() does not encode values: encode them first.
		$url = add_query_arg( array_map( 'rawurlencode', $params ), $redirect_uri );

		if ( self::is_loopback_uri( $redirect_uri ) ) {
			self::render_interstitial( $url );
			exit;
		}

		// wp_redirect(), not wp_safe_redirect(): the host is the client's, and the
		// URI was matched exactly against the client's registered redirect URIs.
		wp_redirect( $url, 302 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- redirect URI checked against the client registration.
		exit;
	}

	/**
	 * Minimal standalone page that sends the browser to a loopback URI with a
	 * meta refresh (no inline script, so no CSP `script-src` concern), plus a
	 * link in case the refresh is blocked.
	 */
	private static function render_interstitial( string $url ): void {
		status_header( 200 );
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
			header( 'X-Frame-Options: DENY' );
			header( "Content-Security-Policy: frame-ancestors 'none'" );
			header( 'Referrer-Policy: no-referrer' );
		}

		self::enqueue_styles();
		$href = esc_url( $url, [ 'http' ] );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php echo esc_attr( (string) get_option( 'blog_charset' ) ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<meta http-equiv="refresh" content="0;url=<?php echo $href; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by esc_url() above. ?>">
	<title><?php esc_html_e( 'Connect to Claude', 'lumia-tools' ); ?></title>
		<?php wp_print_styles( [ 'lumia-tokens-css', 'lumia-reset-css', 'lumia-components-css', 'lumia-buttons-css', 'lumia-mcp-consent-css' ] ); ?>
</head>
<body>
	<div class="lumia-admin-wrap lumia-mcp-consent">
		<div class="lumia-card lumia-mcp-consent__panel">
			<div class="lumia-card__header">
				<h1 class="lumia-card__title"><?php esc_html_e( 'Connect to Claude', 'lumia-tools' ); ?></h1>
				<p class="lumia-card__desc"><?php esc_html_e( 'Returning to the application on your computer…', 'lumia-tools' ); ?></p>
			</div>
			<div class="lumia-card__body">
				<a class="lumia-btn lumia-btn--primary" href="<?php echo $href; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by esc_url() above. ?>"><?php esc_html_e( 'Return to the application', 'lumia-tools' ); ?></a>
			</div>
		</div>
	</div>
</body>
</html>
		<?php
	}

	/**
	 * Request id from the query string or the form, if well formed. Anything
	 * else is dropped without a database lookup.
	 *
	 * @param string $source 'GET' or 'POST'.
	 */
	private static function request_param( string $source ): string {
		// phpcs:disable WordPress.Security.NonceVerification -- GET: display only; POST: the nonce is checked right after, bound to this value.
		if ( 'POST' === $source ) {
			$value = isset( $_POST['request'] ) ? sanitize_text_field( wp_unslash( $_POST['request'] ) ) : '';
		} else {
			$value = isset( $_GET['request'] ) ? sanitize_text_field( wp_unslash( $_GET['request'] ) ) : '';
		}
		// phpcs:enable WordPress.Security.NonceVerification

		return 1 === preg_match( '/^lmc_rq_[0-9a-f]{64}$/', $value ) ? $value : '';
	}

	/**
	 * Authorization request row, if it exists, is unused and has not expired.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function find_request( string $request ): ?array {
		$row = Tokens::find( $request, Tokens::REQUEST );

		if ( null === $row || ! empty( $row['used'] ) || Tokens::is_expired( $row ) ) {
			return null;
		}

		return $row;
	}

	/**
	 * Decoded `data` of a token row.
	 *
	 * @param array<string, mixed> $row Token row.
	 * @return array<string, mixed>
	 */
	private static function row_data( array $row ): array {
		return isset( $row['data'] ) && is_array( $row['data'] ) ? $row['data'] : [];
	}

	/**
	 * Nonce action bound to the request: hashed, so the plain id is not reused
	 * as key material.
	 */
	private static function nonce_action( string $request ): string {
		return self::ACTION . '|' . Tokens::hash( $request );
	}

	/**
	 * Whether a redirect URI targets the user's own machine over plain HTTP.
	 */
	private static function is_loopback_uri( string $uri ): bool {
		// The same rule as the redirect URI validation (host case-insensitive):
		// `http://LOCALHOST:…` passes it, so it must get the interstitial too.
		return null !== ClientRegistry::parse_loopback( $uri );
	}

	/**
	 * String value of an array key, '' if absent or not a string.
	 *
	 * @param array<string, mixed> $data Array to read.
	 * @param string               $key  Key.
	 */
	private static function str( array $data, string $key ): string {
		return isset( $data[ $key ] ) && is_string( $data[ $key ] ) ? $data[ $key ] : '';
	}
}
