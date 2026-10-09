<?php
namespace Lumia\Tools\Modules\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Gestionnaire de l'URL de connexion.
 *
 * Intercepte les requêtes vers l'URL personnalisée et les traite comme wp-login.php
 * sans dépendre de la configuration du serveur (Nginx, Apache).
 */
class LoginUrlHandler {

	/**
	 * Actions de wp-login.php qui ne présentent aucun formulaire de connexion
	 * et doivent rester servies à leur adresse d'origine.
	 *
	 * `postpass` : le formulaire des contenus protégés par mot de passe poste
	 * sur `wp-login.php?action=postpass`, et filter_site_url() laisse cette
	 * URL intacte à dessein. La bloquer avec le reste rendait 404 à tout
	 * visiteur qui déverrouille un article protégé.
	 * `confirmaction` : confirmation des demandes de données personnelles.
	 */
	private const PASSTHROUGH_ACTIONS = [ 'postpass', 'confirmaction' ];

	private string $custom_login_url;

	/**
	 * Constructeur.
	 *
	 * @param string $custom_login_url URL de connexion personnalisée (ex: /connexion).
	 */
	public function __construct( string $custom_login_url = '/connexion' ) {
		$this->custom_login_url = ltrim( $custom_login_url, '/' );
	}

	/**
	 * Racine de l'installation WordPress, sans slash final.
	 *
	 * Vaut '' à la racine du domaine, '/wp' pour une installation en
	 * sous-répertoire. On lit l'option brute plutôt que site_url() : cette
	 * classe filtre justement 'site_url', l'appeler ici boucherait à l'infini.
	 */
	private function install_path(): string {
		$path = (string) wp_parse_url( (string) get_option( 'siteurl' ), PHP_URL_PATH );
		$path = trim( $path, '/' );

		return '' === $path ? '' : '/' . $path;
	}

	/** Chemin absolu de la page de connexion, ex '/connexion' ou '/wp/connexion'. */
	private function login_path(): string {
		return $this->install_path() . '/' . $this->custom_login_url;
	}

	/** URL absolue de la page de connexion, avec slash final. */
	private function login_url(): string {
		return trailingslashit( (string) get_option( 'siteurl' ) ) . $this->custom_login_url . '/';
	}

	/**
	 * Vérifie si un CHEMIN correspond à la connexion personnalisée.
	 *
	 * Le chemin est comparé en absolu, racine d'installation comprise : sur un
	 * WordPress en sous-répertoire, la requête arrive sur '/wp/connexion' et un
	 * motif ancré sur '/connexion' ne reconnaîtrait jamais rien — la page de
	 * connexion deviendrait inaccessible alors que wp-login.php est bloqué.
	 *
	 * @param string $path Chemin de la requête, déjà extrait de l'URI.
	 */
	private function is_custom_login_uri( string $path ): bool {
		$path   = '/' . ltrim( $path, '/' );
		$target = $this->login_path();

		return $path === $target || 0 === strpos( $path, $target . '/' );
	}

	/**
	 * Hook wp_loaded : gère à la fois le blocage de wp-login.php et le service de l'URL personnalisée.
	 *
	 * wp_loaded fire dans les deux cas de figure :
	 * - Requête via index.php (URL custom /connexion)
	 * - Requête directe wp-login.php (celui-ci charge wp-load.php qui fire tous les hooks)
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
		$action      = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routage de wp-login.php, l'action est rejouée par WordPress qui vérifie ses propres nonces.

		if ( in_array( $action, self::PASSTHROUGH_ACTIONS, true ) ) {
			return;
		}

		// Bloquer l'accès direct à wp-login.php : remplacer l'URI par une URL
		// inexistante et laisser WordPress générer un vrai 404 via son template.
		//
		// On teste le nom de fichier du CHEMIN, jamais l'URI entière : un
		// simple ?redirect_to=…/wp-login.php — que WordPress produit lui-même —
		// suffisait à faire répondre 404 à des pages parfaitement légitimes.
		if ( 'wp-login.php' === basename( $path ) && ! is_admin() ) {
			global $pagenow;
			$pagenow = 'index.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- voulu : WordPress doit traiter la requête comme le front pour servir son 404.

			if ( ! defined( 'WP_USE_THEMES' ) ) {
				define( 'WP_USE_THEMES', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
			}

			$_SERVER['REQUEST_URI'] = '/' . str_repeat( '-/', 10 );

			wp();
			require_once ABSPATH . WPINC . '/template-loader.php';
			die;
		}

		// Servir l'URL de connexion personnalisée (/connexion)
		if ( empty( $path ) || ! $this->is_custom_login_uri( $path ) ) {
			return;
		}

		if ( is_user_logged_in() && 'logout' !== $action ) {
			$user        = wp_get_current_user();
			$redirect_to = apply_filters( 'lumia_custom_login_redirect', admin_url(), $user );
			wp_safe_redirect( $redirect_to );
			die();
		}

		// wp-login.php lit ces globales sans les initialiser : on les pose comme
		// le ferait un accès direct, sinon notices « undefined variable ».
		global $error, $user_login;
		$error      = ''; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- initialisation attendue par wp-login.php.
		$user_login = ''; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- initialisation attendue par wp-login.php.

		require_once ABSPATH . 'wp-login.php';
		die;
	}

	/**
	 * Filtre site_url() et network_site_url() pour remplacer wp-login.php
	 * par l'URL personnalisée. Couvre notamment l'action du formulaire de connexion.
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

			// site_url() et non home_url() : la connexion vit dans le répertoire
			// d'installation de WordPress, qui diffère de l'adresse du site dès
			// que le cœur est installé dans un sous-dossier.
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
	 * Filtre les URLs de connexion pour pointer vers l'URL personnalisée.
	 *
	 * @param string $login_url
	 * @return string
	 */
	public function filter_login_url( string $login_url ): string {
		return $this->filter_site_url( $login_url );
	}
}
