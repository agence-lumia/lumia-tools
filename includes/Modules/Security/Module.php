<?php
namespace StudioKyne\MiniTools\Modules\Security;

defined( 'ABSPATH' ) || exit;

use StudioKyne\MiniTools\Core\AbstractModule;

/**
 * Module Sécurité.
 *
 * Authentification (rate limiting, URL custom).
 * Hardening (XML-RPC, énumération users, version WP).
 */
class Module extends AbstractModule {

	/**
	 * Premiers segments interdits pour l'URL de connexion personnalisée.
	 *
	 * `admin` et `login` sont redirigés par le cœur vers wp-admin / wp-login
	 * (wp_redirect_admin_locations) ; `index` et `xmlrpc` sont des fichiers ;
	 * `feed`, `embed`, `comments` sont des bases de réécriture. Tout segment en
	 * `wp-` est refusé d'un bloc (wp-admin, wp-login, wp-content, wp-json…).
	 * Un slug réservé enferme l'administrateur hors du site, sans autre issue
	 * que la constante SKMT_DISABLE_LOGIN_URL.
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
	 * Initialise le module et enregistre tous les hooks.
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

			// Les mots de passe d'application ne traversent PAS wp_authenticate() :
			// wp_validate_application_password() appelle directement
			// wp_authenticate_application_password(). Le filtre `authenticate` —
			// donc maybe_block_login() — n'est jamais consulté, et aucun
			// `wp_login_failed` n'est déclenché. Ils étaient donc devinables sans
			// aucune limite, y compris depuis une IP déjà bloquée sur le formulaire.
			//
			// Le cœur n'offre pas de filtre de blocage sur ce chemin ; le seul
			// point d'arrêt antérieur à la vérification du mot de passe est
			// `application_password_is_api_request`. Répondre `false` fait sortir
			// la fonction avant toute comparaison : la requête redevient anonyme
			// et repart en 401, ce qui est exactement le refus voulu.
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
			// parse_request @1 : avant redirect_canonical, qui divulguait
			// l'identifiant dans l'en-tête Location. Voir HardeningService.
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
			// Priorité 0 : header_remove() n'a d'effet que tant que les en-têtes
			// ne sont pas partis.
			add_action( 'init', [ $this->hardening, 'remove_powered_by_header' ], 0 );
			add_action( 'send_headers', [ $this->hardening, 'remove_powered_by_header' ], 0 );
			add_action( 'init', [ $this->hardening, 'remove_wp_version_generators' ] );
			add_filter( 'the_generator', '__return_empty_string', PHP_INT_MAX );
			add_filter( 'script_loader_src', [ $this->hardening, 'obfuscate_version_in_src' ], PHP_INT_MAX );
			add_filter( 'style_loader_src', [ $this->hardening, 'obfuscate_version_in_src' ], PHP_INT_MAX );
		}

		// Les transients du rate limiter expirent automatiquement — pas besoin de cron.
	}

	/**
	 * Hook wp_login — reset le compteur de tentatives après connexion réussie.
	 *
	 * @param string   $user_login
	 * @param \WP_User $user
	 * @return void
	 */
	public function handle_login_success( string $user_login, \WP_User $user ): void {
		$this->rate_limiter->log_successful_login( $this->get_client_ip() );
	}

	/**
	 * Hook wp_login_failed — incrémente le compteur de tentatives échouées.
	 *
	 * @param string $username
	 * @return void
	 */
	public function handle_login_failed( string $username ): void {
		$this->rate_limiter->log_failed_attempt( $this->get_client_ip() );
	}

	/**
	 * Filtre application_password_is_api_request — refuse l'authentification par
	 * mot de passe d'application tant que l'IP est bloquée.
	 *
	 * Répondre `false` fait sortir wp_authenticate_application_password() avant
	 * la moindre comparaison de mot de passe : rien n'est vérifié, donc rien
	 * n'est devinable. Voir le commentaire du branchement dans init().
	 *
	 * @param bool $is_api_request Décision du cœur.
	 * @return bool
	 */
	public function filter_application_password_allowed( $is_api_request ): bool {
		if ( ! $is_api_request ) {
			return (bool) $is_api_request;
		}

		return ! $this->rate_limiter->is_locked();
	}

	/**
	 * Hook application_password_failed_authentication — compte l'échec.
	 *
	 * Ce chemin ne déclenche aucun `wp_login_failed` : sans ce branchement, mille
	 * essais de mot de passe d'application laisseraient le compteur à zéro.
	 *
	 * @param \WP_Error $error Erreur d'authentification remontée par le cœur.
	 * @return void
	 */
	public function handle_application_password_failed( $error = null ): void {
		$this->rate_limiter->log_failed_attempt( $this->get_client_ip() );
	}

	/**
	 * Porte de sortie : desactive l'URL de connexion personnalisee.
	 *
	 * A poser dans wp-config.php quand le slug a ete oublie ou mal saisi —
	 * sans quoi wp-login.php reste bloque et le site devient inaccessible,
	 * sans aucun recours depuis le navigateur.
	 *
	 *     define( 'SKMT_DISABLE_LOGIN_URL', true );
	 */
	public static function login_url_disabled(): bool {
		return defined( 'SKMT_DISABLE_LOGIN_URL' ) && SKMT_DISABLE_LOGIN_URL;
	}

	/**
	 * IP du client — déléguée au rate limiter, qui applique la source déclarée.
	 *
	 * Il y avait ici une seconde implémentation qui retenait la PREMIÈRE entrée
	 * de X-Forwarded-For quand RateLimiter retenait la dernière : les tentatives
	 * étaient donc comptées sous une clé que le blocage ne relisait jamais.
	 *
	 * @return string
	 */
	private function get_client_ip(): string {
		return $this->rate_limiter->get_client_ip();
	}

	/**
	 * Aplatit les réglages stockés vers la forme du formulaire.
	 *
	 * Le module stocke sous authentication/hardening mais save_settings() lit
	 * des clés à plat : sans cette conversion, un import écraserait tout par
	 * les valeurs par défaut.
	 *
	 * @param array<string, mixed> $stored Réglages tels qu'ils sont en base.
	 * @return array<string, mixed>
	 */
	public function to_form_payload( array $stored ): array {
		$auth      = is_array( $stored['authentication'] ?? null ) ? $stored['authentication'] : [];
		$hardening = is_array( $stored['hardening'] ?? null ) ? $stored['hardening'] : [];

		$payload = array_merge( $auth, $hardening );

		// La liste blanche arrive du formulaire sous forme de textarea.
		if ( isset( $payload['rate_limit_whitelist'] ) && is_array( $payload['rate_limit_whitelist'] ) ) {
			$payload['rate_limit_whitelist'] = implode( PHP_EOL, $payload['rate_limit_whitelist'] );
		}

		return $payload;
	}

	/**
	 * Retourne les settings du module.
	 *
	 * @return array<string, mixed>
	 */
	public function get_settings(): array {
		// Une instance créée hors du cycle d'initialisation (import de
		// configuration, module désactivé) n'a jamais rempli $this->settings :
		// on lit alors la base, comme le font les autres modules.
		if ( ! $this->settings ) {
			$this->settings = $this->get_module_settings( self::get_defaults() );
		}

		return $this->settings;
	}

	/**
	 * Sauvegarde les settings du module.
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
			// Un slug refusé laisse la valeur précédente en place : mieux vaut
			// un réglage inchangé qu'une connexion impossible à router.
			$slug = self::sanitize_login_slug( (string) wp_unslash( $settings['custom_login_url'] ) );
			if ( null !== $slug ) {
				$current['authentication']['custom_login_url'] = '/' . $slug;
			}
		}

		$current['authentication']['ip_source'] = ClientIp::sanitize_source( $settings['ip_source'] ?? '' );

		if ( isset( $settings['rate_limit_whitelist'] ) ) {
			// Seules de vraies IP sont retenues : une entrée invalide ne
			// correspondrait à rien et donnerait une whitelist qu'on croit active.
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
	 * Normalise un slug de connexion saisi par l'utilisateur, ou null s'il est
	 * inutilisable.
	 *
	 * Chaque segment passe par sanitize_title() : seuls `[a-z0-9-]` et le
	 * séparateur `/` survivent, donc rien qui ne puisse être comparé au chemin
	 * d'une requête. Le premier segment ne doit pas être réservé (voir
	 * RESERVED_LOGIN_SEGMENTS).
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
	 * Retourne les CSS du module.
	 *
	 * @return string[]
	 */
	public function get_admin_css(): array {
		return [
			SKMT_ASSETS_URL . 'admin/css/modules/security-admin.css',
		];
	}

	/**
	 * Retourne les JS du module.
	 *
	 * @return string[]
	 */
	public function get_admin_js(): array {
		return [
			SKMT_ASSETS_URL . 'admin/js/modules/security-admin.js',
		];
	}

	/**
	 * Retourne les données JS du module.
	 *
	 * @return array<string, mixed>
	 */
	public function get_admin_js_data(): array {
		return [
			'i18n' => [
				'settings' => __( 'Paramètres de sécurité mis à jour', 'studio-kyne-mini-tools' ),
			],
		];
	}

	/**
	 * Retourne les defaults du module.
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
				// REMOTE_ADDR par défaut : c'est la seule valeur qu'un client ne
				// peut pas falsifier. Voir ClientIp.
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
	 * Retourne les clés à supprimer à la désinstallation.
	 *
	 * @return array{options?: string[], meta?: string[], user_meta?: string[], post_type?: string[], taxonomy?: string[]}
	 */
	public static function get_uninstall_keys(): array {
		return [
			'options' => [
				'skmt_module_security',
			],
		];
	}
}
