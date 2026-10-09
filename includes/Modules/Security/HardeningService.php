<?php
namespace Lumia\Tools\Modules\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Service de hardening WordPress.
 *
 * Désactiver XML-RPC, empêcher énumération utilisateurs, masquer version WP.
 */
class HardeningService {

	// === XML-RPC ===

	/**
	 * Désactive XML-RPC via filtre xmlrpc_enabled.
	 */
	public function filter_xmlrpc_enabled(): bool {
		return false;
	}

	/**
	 * Bloque l'accès au serveur XML-RPC avec un 403.
	 */
	public function block_xmlrpc_server_class(): string {
		http_response_code( 403 );
		exit;
	}

	// === USER ENUMERATION ===

	/**
	 * Hook parse_request @1 : neutralise toute requête d'archive d'auteur.
	 *
	 * L'ancienne version se branchait sur `template_redirect` à la priorité par
	 * défaut, donc APRÈS `redirect_canonical` : WordPress répondait
	 * `301 Location: /author/lumia/` avant que le blocage n'ait la parole,
	 * et l'identifiant était divulgué par l'en-tête `Location` lui-même. Seule
	 * la page d'archive finale était protégée.
	 *
	 * On intervient donc sur `parse_request`, qui court bien avant la boucle et
	 * avant tout redirect canonique, et on traite les DEUX variables : `author`
	 * (?author=N) et `author_name` (/author/slug/).
	 *
	 * La réponse est un 404, pas un 403 : les trois codes distincts d'avant
	 * (301 / 403 / 404) formaient à eux seuls un oracle — on savait qu'un
	 * compte existait sans même lire la page. Un 404 uniforme ne dit plus rien.
	 *
	 * @param \WP $wp Requête en cours d'analyse.
	 */
	public function block_author_query( \WP $wp ): void {
		if ( is_admin() ) {
			return;
		}

		if ( ! isset( $wp->query_vars['author'] ) && ! isset( $wp->query_vars['author_name'] ) ) {
			return;
		}

		unset( $wp->query_vars['author'], $wp->query_vars['author_name'] );

		// Sans ce forçage, la requête dépouillée de son auteur se rabattrait
		// sur la liste des articles et répondrait 200.
		add_action( 'wp', [ $this, 'force_404' ], 1 );
	}

	/**
	 * Force un 404 sur la requête courante (voir block_author_query()).
	 */
	public function force_404(): void {
		global $wp_query;

		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->set_404();
		}

		status_header( 404 );
		nocache_headers();
	}

	/**
	 * Hook template_redirect : filet de sécurité si une archive d'auteur
	 * atteint malgré tout la boucle (règle de réécriture tierce, requête
	 * reconstruite en PHP par une extension).
	 */
	public function prevent_user_enumeration(): void {
		if ( is_admin() || ! is_author() ) {
			return;
		}

		$this->force_404();
	}

	/**
	 * Hook rest_request_before_callbacks : bloque /wp/v2/users pour les non-admins.
	 *
	 * Deux réserves apprises à l'usage :
	 *
	 *  - `/wp/v2/users/me` est la route de l'utilisateur COURANT. L'éditeur de
	 *    blocs, les préférences d'écran et bon nombre d'extensions l'appellent
	 *    au chargement. La bloquer renvoyait un 403 à tout auteur ou
	 *    contributeur — éditeur cassé — alors qu'elle ne divulgue que le compte
	 *    de l'appelant, qui le connaît déjà. Elle passe donc pour tout
	 *    utilisateur connecté.
	 *  - le test portait sur `strpos()`, qui reconnaît la sous-chaîne n'importe
	 *    où dans la route. On l'ancre au début.
	 *
	 * @param mixed            $response
	 * @param mixed            $handler
	 * @param \WP_REST_Request $request
	 * @return mixed
	 */
	public function prevent_rest_user_enumeration( $response, $handler, \WP_REST_Request $request ) {
		$route = $request->get_route();

		if ( is_user_logged_in() && preg_match( '#^/wp/v2/users/me(/|$)#', $route ) ) {
			return $response;
		}

		if ( ! current_user_can( 'list_users' ) && preg_match( '#^/wp/v2/users(/|$)#', $route ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Accès interdit.', 'lumia-tools' ),
				[ 'status' => 403 ]
			);
		}

		return $response;
	}

	/**
	 * Filtre oembed_response_data : retire l'auteur de la réponse oEmbed.
	 *
	 * `prevent_rest_user_enumeration()` ne regardait que `/wp/v2/users`, alors
	 * que `/wp-json/oembed/1.0/embed?url=…` sert `author_name` et surtout
	 * `author_url`, d'où l'identifiant se lit directement — sans
	 * authentification, et pour n'importe quel article. On vide les deux clés
	 * plutôt que de fermer la route : l'oEmbed reste bon pour ce à quoi il sert
	 * (l'aperçu), et l'iframe d'intégration continue de s'afficher.
	 *
	 * @param mixed $data Données oEmbed préparées par le cœur.
	 * @return mixed
	 */
	public function filter_oembed_response_data( $data ) {
		if ( is_array( $data ) ) {
			unset( $data['author_name'], $data['author_url'] );
		}

		return $data;
	}

	/**
	 * Filtre wp_sitemaps_add_provider : retire les auteurs du plan de site.
	 *
	 * `/wp-sitemap-users-1.xml` liste l'URL d'archive de chaque auteur ayant
	 * publié : c'est la divulgation de `?author=N`, servie sur un plateau et
	 * indexée par les moteurs. La bloquer ailleurs sans la retirer d'ici
	 * laisserait la liste accessible dans le cache des moteurs.
	 *
	 * @param mixed  $provider Fournisseur du cœur.
	 * @param string $name     Nom du fournisseur.
	 * @return mixed
	 */
	public function filter_sitemap_providers( $provider, string $name ) {
		return 'users' === $name ? false : $provider;
	}

	/**
	 * Codes d'erreur de connexion qui trahissent l'existence d'un compte.
	 *
	 * `invalid_username` / `invalid_email` disent « ce compte n'existe pas »,
	 * `incorrect_password` dit « il existe, mais pas avec ce mot de passe ».
	 * `invalidcombo` est l'équivalent du formulaire de mot de passe oublié.
	 */
	private const LOGIN_ORACLE_CODES = [
		'invalid_username',
		'invalid_email',
		'incorrect_password',
		'invalidcombo',
	];

	/**
	 * Filtre wp_login_errors : message unique sur les erreurs qui trahissent
	 * l'existence d'un compte.
	 *
	 * Le formulaire de connexion distingue « cet identifiant n'est pas inscrit »
	 * de « ce mot de passe ne correspond pas à l'identifiant X » : c'est l'oracle
	 * d'énumération le plus commode qui soit, et il vivait dans le module qui
	 * promet justement de bloquer l'énumération.
	 *
	 * PIÈGE — on ne peut PAS passer par le filtre `login_errors` (la chaîne
	 * formatée) en lisant le `$errors` global : `LoginUrlHandler::wp_loaded()`
	 * charge wp-login.php avec un `require_once` **depuis une méthode**, si bien
	 * que le `$errors` de wp-login.php est une variable locale de cette méthode
	 * et n'atteint jamais la portée globale. `wp_login_errors` reçoit l'objet
	 * WP_Error en argument : il est indifférent à la portée, et il couvre les
	 * deux formulaires (connexion et mot de passe oublié).
	 *
	 * On remplace le message en CONSERVANT le code : wp-login.php se sert de
	 * `incorrect_password` juste après pour repré-remplir le champ identifiant.
	 * Les codes qui ne disent rien d'un compte — mot de passe vide, cookies
	 * bloqués — sont laissés intacts : l'utilisateur légitime en a besoin.
	 *
	 * @param mixed $errors WP_Error de la page de connexion.
	 * @return mixed
	 */
	public function filter_login_errors( $errors ) {
		if ( ! $errors instanceof \WP_Error ) {
			return $errors;
		}

		$generic = __( 'Identifiant ou mot de passe incorrect.', 'lumia-tools' );

		foreach ( self::LOGIN_ORACLE_CODES as $code ) {
			if ( ! in_array( $code, $errors->get_error_codes(), true ) ) {
				continue;
			}

			$data = $errors->get_error_data( $code );
			$errors->remove( $code );
			$errors->add( $code, '<strong>' . esc_html__( 'Erreur :', 'lumia-tools' ) . '</strong> ' . esc_html( $generic ), $data );
		}

		return $errors;
	}

	/**
	 * Action lost_password : aligne la réponse du formulaire « mot de passe
	 * oublié » sur celle d'un compte existant.
	 *
	 * Réécrire le message ne suffit pas ici, l'oracle est dans la FORME de la
	 * réponse : un compte connu déclenche l'envoi puis une redirection vers
	 * `?checkemail=confirm`, un compte inconnu réaffiche le formulaire avec une
	 * erreur. On voit donc la différence sans même lire le texte.
	 *
	 * Quand `invalidcombo` est la seule erreur, on rejoue la sortie du cas
	 * nominal — même redirection, même page. Aucun e-mail n'est envoyé, et il
	 * n'y a personne à qui en envoyer un.
	 *
	 * Réserve assumée : `retrieve_password_email_failure` reste distinguable.
	 * Le masquer priverait l'administrateur du seul signal qui lui dit que
	 * l'envoi d'e-mails de son site est cassé — et sur un site dont l'envoi
	 * fonctionne, ce code n'apparaît jamais.
	 *
	 * Le filtre `lostpassword_errors` ne convient pas : le cœur ajoute
	 * `invalidcombo` APRÈS l'avoir appliqué.
	 *
	 * @param mixed $errors WP_Error du formulaire.
	 */
	public function mask_lost_password_oracle( $errors ): void {
		if ( ! $errors instanceof \WP_Error || ! $errors->has_errors() ) {
			return;
		}

		if ( [ 'invalidcombo' ] !== $errors->get_error_codes() ) {
			return;
		}

		wp_safe_redirect( add_query_arg( 'checkemail', 'confirm', wp_login_url() ) );
		exit;
	}

	// === HIDE WP VERSION ===

	/**
	 * Hook wp_headers : retire les headers exposant la version.
	 *
	 * @param array<string, string> $headers
	 * @return array<string, string>
	 */
	public function hide_wp_version_headers( array $headers ): array {
		unset( $headers['X-Powered-By'] );
		return $headers;
	}

	/**
	 * Retire l'en-tête X-Powered-By ajouté par PHP lui-même.
	 *
	 * `unset( $headers['X-Powered-By'] )` ne porte que sur le tableau d'en-têtes
	 * que WordPress s'apprête à émettre. Or cet en-tête-là vient de PHP
	 * (`expose_php`), qui l'a déjà posé : le réglage était activé et
	 * `X-Powered-By: PHP/8.5.7` sortait sur toutes les réponses.
	 *
	 * `header_remove()` ne peut agir qu'avant l'envoi des en-têtes, d'où le
	 * branchement au plus tôt. La vraie solution reste `expose_php = Off` dans
	 * la configuration PHP : elle couvre aussi les réponses qui ne passent pas
	 * par WordPress (pages d'erreur du serveur, scripts hors cœur).
	 */
	public function remove_powered_by_header(): void {
		if ( ! headers_sent() ) {
			header_remove( 'X-Powered-By' );
		}
	}

	/**
	 * Hook init : supprime le generator WP de toutes les sorties (head, feeds).
	 */
	public function remove_wp_version_generators(): void {
		$actions = [ 'wp_head', 'rss2_head', 'commentsrss2_head', 'rss_head', 'rdf_header', 'atom_head', 'comments_atom_head', 'opml_head', 'app_head' ];
		foreach ( $actions as $action ) {
			remove_action( $action, 'the_generator' );
			remove_action( $action, 'wp_generator' );
		}
	}

	/**
	 * Filtre script_loader_src / style_loader_src : remplace la version WP par un hash.
	 */
	public function obfuscate_version_in_src( string $src ): string {
		if ( is_admin() ) {
			return $src;
		}
		$version = get_bloginfo( 'version' );
		if ( empty( $version ) ) {
			return $src;
		}
		$hash = substr( md5( $version ), 0, 8 );
		return str_replace( 'ver=' . $version, 'ver=' . $hash, $src );
	}
}
