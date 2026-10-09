<?php
namespace Lumia\Tools\Modules\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Login URL handler.
 *
 * Intercepts requests to the custom URL and handles them like wp-login.php,
 * without depending on the server configuration (Nginx, Apache).
 */
class LoginUrlHandler {

	/**
	 * wp-login.php actions that show no login form and must keep being served
	 * at their original address.
	 *
	 * `postpass`: the form of password-protected content posts to
	 * `wp-login.php?action=postpass`, and filter_site_url() leaves that URL
	 * untouched on purpose. Blocking it with the rest returned a 404 to every
	 * visitor unlocking a protected post.
	 * `confirmaction`: confirmation of personal data requests.
	 */
	private const PASSTHROUGH_ACTIONS = [ 'postpass', 'confirmaction' ];

	private string $custom_login_url;

	/**
	 * Constructor.
	 *
	 * @param string $custom_login_url Custom login URL (e.g. /connexion).
	 */
	public function __construct( string $custom_login_url = '/connexion' ) {
		$this->custom_login_url = ltrim( $custom_login_url, '/' );
	}

	/**
	 * Root of the WordPress installation, without trailing slash.
	 *
	 * '' at the domain root, '/wp' for a subdirectory install. The raw option
	 * is read rather than site_url(): this class filters 'site_url', calling it
	 * here would loop forever.
	 */
	private function install_path(): string {
		$path = (string) wp_parse_url( (string) get_option( 'siteurl' ), PHP_URL_PATH );
		$path = trim( $path, '/' );

		return '' === $path ? '' : '/' . $path;
	}

	/** Absolute path of the login page, e.g. '/connexion' or '/wp/connexion'. */
	private function login_path(): string {
		return $this->install_path() . '/' . $this->custom_login_url;
	}

	/** Absolute URL of the login page, with trailing slash. */
	private function login_url(): string {
		return trailingslashit( (string) get_option( 'siteurl' ) ) . $this->custom_login_url . '/';
	}

	/**
	 * Checks whether a PATH matches the custom login.
	 *
	 * The path is compared as an absolute one, installation root included: on a
	 * subdirectory WordPress the request arrives on '/wp/connexion' and a
	 * pattern anchored on '/connexion' would never match anything — the login
	 * page would become unreachable while wp-login.php is blocked.
	 *
	 * @param string $path Request path, already extracted from the URI.
	 */
	private function is_custom_login_uri( string $path ): bool {
		$path   = '/' . ltrim( $path, '/' );
		$target = $this->login_path();

		return $path === $target || 0 === strpos( $path, $target . '/' );
	}

	/**
	 * Hook wp_loaded: handles both the blocking of wp-login.php and the serving of the custom URL.
	 *
	 * wp_loaded fires in both cases:
	 * - Request through index.php (custom URL /connexion)
	 * - Direct wp-login.php request (it loads wp-load.php, which fires all the hooks)
	 *
	 * @return void
	 */
	public function wp_loaded(): void {
		if ( defined( 'WP_CLI' ) || wp_doing_cron() || wp_doing_ajax() || defined( 'REST_REQUEST' ) ) {
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$request     = wp_parse_url( rawurldecode( $request_uri ) );
		$path        = $request['path'] ?? '';
		$action      = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- wp-login.php routing, the action is replayed by WordPress, which checks its own nonces.

		if ( in_array( $action, self::PASSTHROUGH_ACTIONS, true ) ) {
			return;
		}

		// Block direct access to wp-login.php: replace the URI with a
		// non-existent URL and let WordPress generate a real 404 through its template.
		//
		// The file name of the PATH is tested, never the whole URI: a plain
		// ?redirect_to=…/wp-login.php — which WordPress itself produces —
		// was enough to answer 404 on perfectly legitimate pages.
		if ( 'wp-login.php' === basename( $path ) && ! is_admin() ) {
			global $pagenow;
			$pagenow = 'index.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- intended: WordPress must treat the request as the front end to serve its 404.

			if ( ! defined( 'WP_USE_THEMES' ) ) {
				define( 'WP_USE_THEMES', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
			}

			$_SERVER['REQUEST_URI'] = '/' . str_repeat( '-/', 10 );

			wp();
			require_once ABSPATH . WPINC . '/template-loader.php';
			die;
		}

		// Serve the custom login URL (/connexion)
		if ( empty( $path ) || ! $this->is_custom_login_uri( $path ) ) {
			return;
		}

		if ( is_user_logged_in() && 'logout' !== $action ) {
			$user        = wp_get_current_user();
			$redirect_to = apply_filters( 'lumia_custom_login_redirect', admin_url(), $user );
			wp_safe_redirect( $redirect_to );
			die();
		}

		// wp-login.php reads these globals without initializing them: set them as
		// a direct access would, otherwise "undefined variable" notices appear.
		global $error, $user_login;
		$error      = ''; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- initialization expected by wp-login.php.
		$user_login = ''; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- initialization expected by wp-login.php.

		require_once ABSPATH . 'wp-login.php';
		die;
	}

	/**
	 * Filters site_url() and network_site_url() to replace wp-login.php
	 * with the custom URL. Notably covers the login form action.
	 *
	 * @param string $url
	 * @return string
	 */
	public function filter_site_url( string $url ): string {
		if ( strpos( $url, 'wp-login.php?action=postpass' ) !== false ) {
			return $url;
		}

		if ( strpos( $url, 'wp-login.php' ) !== false ) {
			$parts = explode( '?', $url, 2 );

			// site_url() and not home_url(): the login lives in the WordPress
			// installation directory, which differs from the site address as soon
			// as the core is installed in a subfolder.
			$base = $this->login_url();

			if ( isset( $parts[1] ) ) {
				parse_str( $parts[1], $params );
				return add_query_arg( $params, $base );
			}

			return $base;
		}

		return $url;
	}

	/**
	 * Filters login URLs to point to the custom URL.
	 *
	 * @param string $login_url
	 * @return string
	 */
	public function filter_login_url( string $login_url ): string {
		return $this->filter_site_url( $login_url );
	}
}
