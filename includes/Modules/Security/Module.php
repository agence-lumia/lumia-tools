<?php
namespace Lumia\Tools\Modules\Security;

defined( 'ABSPATH' ) || exit;

use Lumia\Tools\Core\AbstractModule;

/**
 * Security module.
 *
 * Authentication (rate limiting, custom URL).
 * Hardening (XML-RPC, user enumeration, WP version).
 */
class Module extends AbstractModule {

	/**
	 * Forbidden first segments for the custom login URL.
	 *
	 * `admin` and `login` are redirected by the core to wp-admin / wp-login
	 * (wp_redirect_admin_locations); `index` and `xmlrpc` are files;
	 * `feed`, `embed`, `comments` are rewrite bases. Any segment starting with
	 * `wp-` is refused as a whole (wp-admin, wp-login, wp-content, wp-json…).
	 * A reserved slug locks the administrator out of the site, with no way out
	 * other than the LUMIA_DISABLE_LOGIN_URL constant.
	 */
	private const RESERVED_LOGIN_SEGMENTS = [ 'admin', 'login', 'index', 'index-php', 'xmlrpc', 'xmlrpc-php', 'feed', 'embed', 'comments' ];

	private RateLimiter $rate_limiter;
	private HardeningService $hardening;
	private LoginUrlHandler $login_handler;
	/**
	 * @var array<string, mixed>
	 */
	private array $settings = [];

	/**
	 * Initializes the module and registers all the hooks.
	 */
	public function init(): void {
		$this->settings = $this->get_module_settings( self::get_defaults() );

		$auth                = $this->settings['authentication'];
		$this->rate_limiter  = new RateLimiter(
			$auth['rate_limit_whitelist'] ?? [],
			$auth['rate_limit_attempts'] ?? 5,
			$auth['rate_limit_window'] ?? 900,
			$auth['rate_limit_lockout'] ?? 1800,
			$auth['ip_source'] ?? ClientIp::SOURCE_REMOTE_ADDR
		);
		$this->hardening     = new HardeningService();
		$this->login_handler = new LoginUrlHandler( $this->settings['authentication']['custom_login_url'] ?? '/connexion' );

		// === AUTHENTICATION HOOKS ===

		if ( $this->settings['authentication']['rate_limiting'] ?? true ) {
			add_filter( 'authenticate', [ $this->rate_limiter, 'maybe_block_login' ], 999 );
			add_action( 'wp_login', [ $this, 'handle_login_success' ], 10, 2 );
			add_action( 'wp_login_failed', [ $this, 'handle_login_failed' ] );

			// Application passwords do NOT go through wp_authenticate():
			// wp_validate_application_password() calls
			// wp_authenticate_application_password() directly. The `authenticate`
			// filter — hence maybe_block_login() — is never consulted, and no
			// `wp_login_failed` is fired. They were therefore guessable with no
			// limit at all, even from an IP already blocked on the form.
			//
			// The core offers no blocking filter on this path; the only stopping
			// point before the password check is
			// `application_password_is_api_request`. Answering `false` makes the
			// function return before any comparison: the request becomes anonymous
			// again and goes out as a 401, which is exactly the intended refusal.
			add_filter( 'application_password_is_api_request', [ $this, 'filter_application_password_allowed' ], 999 );
			add_action( 'application_password_failed_authentication', [ $this, 'handle_application_password_failed' ] );
		}

		if ( ( $this->settings['authentication']['enable_custom_login_url'] ?? true ) && ! self::login_url_disabled() ) {
			add_action( 'wp_loaded', [ $this->login_handler, 'wp_loaded' ], 10 );
			add_filter( 'login_url', [ $this->login_handler, 'filter_login_url' ] );
			add_filter( 'site_url', [ $this->login_handler, 'filter_site_url' ], 10 );
			add_filter( 'network_site_url', [ $this->login_handler, 'filter_site_url' ], 10 );
			add_filter( 'wp_redirect', [ $this->login_handler, 'filter_site_url' ], 10 );
		}

		// === HARDENING HOOKS ===

		if ( $this->settings['hardening']['disable_xmlrpc'] ?? false ) {
			add_filter( 'xmlrpc_enabled', [ $this->hardening, 'filter_xmlrpc_enabled' ] );
			add_filter( 'wp_xmlrpc_server_class', [ $this->hardening, 'block_xmlrpc_server_class' ] );
		}

		if ( $this->settings['hardening']['prevent_user_enum'] ?? false ) {
			// parse_request @1: before redirect_canonical, which leaked the
			// identifier in the Location header. See HardeningService.
			add_action( 'parse_request', [ $this->hardening, 'block_author_query' ], 1 );
			add_action( 'template_redirect', [ $this->hardening, 'prevent_user_enumeration' ], 1 );
			add_filter( 'rest_request_before_callbacks', [ $this->hardening, 'prevent_rest_user_enumeration' ], 10, 3 );
			add_filter( 'oembed_response_data', [ $this->hardening, 'filter_oembed_response_data' ], PHP_INT_MAX );
			add_filter( 'wp_sitemaps_add_provider', [ $this->hardening, 'filter_sitemap_providers' ], 10, 2 );
			add_filter( 'wp_login_errors', [ $this->hardening, 'filter_login_errors' ], PHP_INT_MAX );
			add_action( 'lost_password', [ $this->hardening, 'mask_lost_password_oracle' ], PHP_INT_MAX );
		}

		if ( $this->settings['hardening']['hide_wp_version'] ?? false ) {
			add_filter( 'wp_headers', [ $this->hardening, 'hide_wp_version_headers' ] );
			// Priority 0: header_remove() only has an effect as long as the
			// headers have not been sent.
			add_action( 'init', [ $this->hardening, 'remove_powered_by_header' ], 0 );
			add_action( 'send_headers', [ $this->hardening, 'remove_powered_by_header' ], 0 );
			add_action( 'init', [ $this->hardening, 'remove_wp_version_generators' ] );
			add_filter( 'the_generator', '__return_empty_string', PHP_INT_MAX );
			add_filter( 'script_loader_src', [ $this->hardening, 'obfuscate_version_in_src' ], PHP_INT_MAX );
			add_filter( 'style_loader_src', [ $this->hardening, 'obfuscate_version_in_src' ], PHP_INT_MAX );
		}

		// The rate limiter transients expire on their own — no cron needed.
	}

	/**
	 * Hook wp_login — resets the attempt counter after a successful login.
	 *
	 * @param string   $user_login
	 * @param \WP_User $user
	 * @return void
	 */
	public function handle_login_success( string $user_login, \WP_User $user ): void {
		$this->rate_limiter->log_successful_login( $this->get_client_ip() );
	}

	/**
	 * Hook wp_login_failed — increments the failed attempt counter.
	 *
	 * @param string $username
	 * @return void
	 */
	public function handle_login_failed( string $username ): void {
		$this->rate_limiter->log_failed_attempt( $this->get_client_ip() );
	}

	/**
	 * Filter application_password_is_api_request — refuses application
	 * password authentication while the IP is blocked.
	 *
	 * Answering `false` makes wp_authenticate_application_password() return
	 * before any password comparison: nothing is checked, so nothing can be
	 * guessed. See the comment on the hook in init().
	 *
	 * @param bool $is_api_request Core decision.
	 * @return bool
	 */
	public function filter_application_password_allowed( $is_api_request ): bool {
		if ( ! $is_api_request ) {
			return (bool) $is_api_request;
		}

		return ! $this->rate_limiter->is_locked();
	}

	/**
	 * Hook application_password_failed_authentication — counts the failure.
	 *
	 * This path fires no `wp_login_failed`: without this hook, a thousand
	 * application password attempts would leave the counter at zero.
	 *
	 * @param \WP_Error $error Authentication error raised by the core.
	 * @return void
	 */
	public function handle_application_password_failed( $error = null ): void {
		$this->rate_limiter->log_failed_attempt( $this->get_client_ip() );
	}

	/**
	 * Escape hatch: disables the custom login URL.
	 *
	 * To be set in wp-config.php when the slug was forgotten or mistyped —
	 * otherwise wp-login.php stays blocked and the site becomes inaccessible,
	 * with no recourse from the browser.
	 *
	 *     define( 'LUMIA_DISABLE_LOGIN_URL', true );
	 */
	public static function login_url_disabled(): bool {
		return defined( 'LUMIA_DISABLE_LOGIN_URL' ) && LUMIA_DISABLE_LOGIN_URL;
	}

	/**
	 * Client IP — delegated to the rate limiter, which applies the declared source.
	 *
	 * There used to be a second implementation here that kept the FIRST entry
	 * of X-Forwarded-For while RateLimiter kept the last: attempts were thus
	 * counted under a key the lockout never read back.
	 *
	 * @return string
	 */
	private function get_client_ip(): string {
		return $this->rate_limiter->get_client_ip();
	}

	/**
	 * Flattens the stored settings into the form shape.
	 *
	 * The module stores under authentication/hardening but save_settings() reads
	 * flat keys: without this conversion, an import would overwrite everything
	 * with the default values.
	 *
	 * @param array<string, mixed> $stored Settings as they are in the database.
	 * @return array<string, mixed>
	 */
	public function to_form_payload( array $stored ): array {
		$auth      = is_array( $stored['authentication'] ?? null ) ? $stored['authentication'] : [];
		$hardening = is_array( $stored['hardening'] ?? null ) ? $stored['hardening'] : [];

		$payload = array_merge( $auth, $hardening );

		// The whitelist comes from the form as a textarea.
		if ( isset( $payload['rate_limit_whitelist'] ) && is_array( $payload['rate_limit_whitelist'] ) ) {
			$payload['rate_limit_whitelist'] = implode( PHP_EOL, $payload['rate_limit_whitelist'] );
		}

		return $payload;
	}

	/**
	 * Returns the module settings.
	 *
	 * @return array<string, mixed>
	 */
	public function get_settings(): array {
		// An instance created outside the initialization cycle (settings
		// import, disabled module) never filled $this->settings: the database
		// is then read, as the other modules do.
		if ( ! $this->settings ) {
			$this->settings = $this->get_module_settings( self::get_defaults() );
		}

		return $this->settings;
	}

	/**
	 * Saves the module settings.
	 *
	 * @param array<string, mixed> $settings
	 * @return bool
	 */
	public function save_settings( array $settings ): bool {
		$current = $this->get_module_settings( self::get_defaults() );

		// Authentication
		$current['authentication']['rate_limiting']           = ! empty( $settings['rate_limiting'] );
		$current['authentication']['rate_limit_attempts']     = isset( $settings['rate_limit_attempts'] ) ? min( 20, max( 1, absint( $settings['rate_limit_attempts'] ) ) ) : 5;
		$current['authentication']['rate_limit_window']       = isset( $settings['rate_limit_window'] ) ? max( 60, absint( $settings['rate_limit_window'] ) ) : 900;
		$current['authentication']['rate_limit_lockout']      = isset( $settings['rate_limit_lockout'] ) ? max( 60, absint( $settings['rate_limit_lockout'] ) ) : 1800;
		$current['authentication']['enable_custom_login_url'] = ! empty( $settings['enable_custom_login_url'] );

		if ( isset( $settings['custom_login_url'] ) ) {
			// A refused slug leaves the previous value in place: better an
			// unchanged setting than a login that cannot be routed.
			$slug = self::sanitize_login_slug( (string) wp_unslash( $settings['custom_login_url'] ) );
			if ( null !== $slug ) {
				$current['authentication']['custom_login_url'] = '/' . $slug;
			}
		}

		$current['authentication']['ip_source'] = ClientIp::sanitize_source( $settings['ip_source'] ?? '' );

		if ( isset( $settings['rate_limit_whitelist'] ) ) {
			// Only real IPs are kept: an invalid entry would match nothing and
			// give a whitelist that is believed to be active.
			$lines = explode( "\n", (string) wp_unslash( $settings['rate_limit_whitelist'] ) );
			$ips   = array_filter(
				array_map( 'trim', $lines ),
				static function ( $ip ) {
					return (bool) filter_var( $ip, FILTER_VALIDATE_IP );
				}
			);
			$current['authentication']['rate_limit_whitelist'] = array_values( $ips );
		}

		// Hardening
		$current['hardening']['disable_xmlrpc']    = ! empty( $settings['disable_xmlrpc'] );
		$current['hardening']['prevent_user_enum'] = ! empty( $settings['prevent_user_enum'] );
		$current['hardening']['hide_wp_version']   = ! empty( $settings['hide_wp_version'] );

		return $this->save_module_settings( $current );
	}

	/**
	 * Normalizes a login slug typed by the user, or null if it is unusable.
	 *
	 * Each segment goes through sanitize_title(): only `[a-z0-9-]` and the `/`
	 * separator survive, so nothing that cannot be compared to a request path.
	 * The first segment must not be reserved (see RESERVED_LOGIN_SEGMENTS).
	 */
	public static function sanitize_login_slug( string $raw ): ?string {
		$segments = array_values( array_filter( array_map( 'sanitize_title', explode( '/', trim( $raw ) ) ) ) );
		if ( ! $segments ) {
			return null;
		}

		$first = $segments[0];
		if ( 0 === strpos( $first, 'wp-' ) || in_array( $first, self::RESERVED_LOGIN_SEGMENTS, true ) ) {
			return null;
		}

		return implode( '/', $segments );
	}

	/**
	 * Returns the module CSS files.
	 *
	 * @return string[]
	 */
	public function get_admin_css(): array {
		return [
			LUMIA_ASSETS_URL . 'admin/css/modules/security-admin.css',
		];
	}

	/**
	 * Returns the module JS files.
	 *
	 * @return string[]
	 */
	public function get_admin_js(): array {
		return [
			LUMIA_ASSETS_URL . 'admin/js/modules/security-admin.js',
		];
	}

	/**
	 * Returns the module JS data.
	 *
	 * @return array<string, mixed>
	 */
	public function get_admin_js_data(): array {
		return [
			'i18n' => [
				'settings'      => __( 'Security settings updated', 'lumia-tools' ),
				/* translators: %d: number of seconds, shown next to a delay field. */
				'secondsFormat' => __( '%d s', 'lumia-tools' ),
				/* translators: %d: number of minutes, shown next to a delay field. */
				'minutesFormat' => __( '%d min', 'lumia-tools' ),
			],
		];
	}

	/**
	 * Returns the module defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_defaults(): array {
		return [
			'authentication' => [
				'enable_custom_login_url' => true,
				'custom_login_url'        => '/connexion',
				'rate_limiting'           => true,
				'rate_limit_attempts'     => 5,
				'rate_limit_window'       => 900,
				'rate_limit_lockout'      => 1800,
				'rate_limit_whitelist'    => [],
				// REMOTE_ADDR by default: it is the only value a client cannot
				// forge. See ClientIp.
				'ip_source'               => ClientIp::SOURCE_REMOTE_ADDR,
			],
			'hardening'      => [
				'disable_xmlrpc'    => false,
				'prevent_user_enum' => true,
				'hide_wp_version'   => true,
			],
		];
	}

	/**
	 * Returns the keys to delete on uninstall.
	 *
	 * @return array{options?: string[], meta?: string[], user_meta?: string[], post_type?: string[], taxonomy?: string[]}
	 */
	public static function get_uninstall_keys(): array {
		return [
			'options' => [
				'lumia_module_security',
			],
		];
	}
}
