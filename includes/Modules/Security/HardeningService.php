<?php
namespace Lumia\Tools\Modules\Security;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress hardening service.
 *
 * Disable XML-RPC, prevent user enumeration, hide the WP version.
 */
class HardeningService {

	// === XML-RPC ===

	/**
	 * Disables XML-RPC through the xmlrpc_enabled filter.
	 */
	public function filter_xmlrpc_enabled(): bool {
		return false;
	}

	/**
	 * Blocks access to the XML-RPC server with a 403.
	 */
	public function block_xmlrpc_server_class(): string {
		http_response_code( 403 );
		exit;
	}

	// === USER ENUMERATION ===

	/**
	 * Hook parse_request @1: neutralizes any author archive request.
	 *
	 * The previous version hooked `template_redirect` at the default priority,
	 * hence AFTER `redirect_canonical`: WordPress answered
	 * `301 Location: /author/lumia/` before the block could act, and the
	 * identifier was leaked by the `Location` header itself. Only the final
	 * archive page was protected.
	 *
	 * We therefore act on `parse_request`, which runs well before the loop and
	 * before any canonical redirect, and handle BOTH variables: `author`
	 * (?author=N) and `author_name` (/author/slug/).
	 *
	 * The answer is a 404, not a 403: the three distinct codes of before
	 * (301 / 403 / 404) were an oracle on their own — one could tell an account
	 * existed without even reading the page. A uniform 404 says nothing.
	 *
	 * @param \WP $wp Request being parsed.
	 */
	public function block_author_query( \WP $wp ): void {
		if ( is_admin() ) {
			return;
		}

		if ( ! isset( $wp->query_vars['author'] ) && ! isset( $wp->query_vars['author_name'] ) ) {
			return;
		}

		unset( $wp->query_vars['author'], $wp->query_vars['author_name'] );

		// Without this forcing, the request stripped of its author would fall
		// back to the post list and answer 200.
		add_action( 'wp', [ $this, 'force_404' ], 1 );
	}

	/**
	 * Forces a 404 on the current request (see block_author_query()).
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
	 * Hook template_redirect: safety net in case an author archive still
	 * reaches the loop (third-party rewrite rule, request rebuilt in PHP by a
	 * plugin).
	 */
	public function prevent_user_enumeration(): void {
		if ( is_admin() || ! is_author() ) {
			return;
		}

		$this->force_404();
	}

	/**
	 * Hook rest_request_before_callbacks: blocks /wp/v2/users for non-admins.
	 *
	 * Two caveats learned the hard way:
	 *
	 *  - `/wp/v2/users/me` is the route of the CURRENT user. The block editor,
	 *    screen preferences and many plugins call it on load. Blocking it
	 *    returned a 403 to every author or contributor — broken editor — while
	 *    it only discloses the caller's own account, which they already know.
	 *    It therefore passes for any logged-in user.
	 *  - the test used `strpos()`, which matches the substring anywhere in the
	 *    route. It is now anchored at the start.
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
				__( 'Access forbidden.', 'lumia-tools' ),
				[ 'status' => 403 ]
			);
		}

		return $response;
	}

	/**
	 * Filter oembed_response_data: removes the author from the oEmbed response.
	 *
	 * `prevent_rest_user_enumeration()` only looked at `/wp/v2/users`, whereas
	 * `/wp-json/oembed/1.0/embed?url=…` serves `author_name` and above all
	 * `author_url`, from which the identifier can be read directly — without
	 * authentication, and for any post. Both keys are emptied rather than
	 * closing the route: oEmbed stays good for what it is used for (the
	 * preview), and the embed iframe keeps being displayed.
	 *
	 * @param mixed $data oEmbed data prepared by the core.
	 * @return mixed
	 */
	public function filter_oembed_response_data( $data ) {
		if ( is_array( $data ) ) {
			unset( $data['author_name'], $data['author_url'] );
		}

		return $data;
	}

	/**
	 * Filter wp_sitemaps_add_provider: removes authors from the sitemap.
	 *
	 * `/wp-sitemap-users-1.xml` lists the archive URL of every author who has
	 * published: it is the disclosure of `?author=N`, served on a plate and
	 * indexed by search engines. Blocking it elsewhere without removing it from
	 * here would leave the list available in the search engines' cache.
	 *
	 * @param mixed  $provider Core provider.
	 * @param string $name     Provider name.
	 * @return mixed
	 */
	public function filter_sitemap_providers( $provider, string $name ) {
		return 'users' === $name ? false : $provider;
	}

	/**
	 * Login error codes that betray the existence of an account.
	 *
	 * `invalid_username` / `invalid_email` say "this account does not exist",
	 * `incorrect_password` says "it exists, but not with this password".
	 * `invalidcombo` is the equivalent on the lost password form.
	 */
	private const LOGIN_ORACLE_CODES = [
		'invalid_username',
		'invalid_email',
		'incorrect_password',
		'invalidcombo',
	];

	/**
	 * Filter wp_login_errors: a single message for the errors that betray the
	 * existence of an account.
	 *
	 * The login form tells "this identifier is not registered" apart from "this
	 * password does not match identifier X": it is the most convenient
	 * enumeration oracle there is, and it lived in the very module that
	 * promises to block enumeration.
	 *
	 * PITFALL — we can NOT go through the `login_errors` filter (the formatted
	 * string) by reading the global `$errors`: `LoginUrlHandler::wp_loaded()`
	 * loads wp-login.php with a `require_once` **from a method**, so the
	 * `$errors` of wp-login.php is a local variable of that method and never
	 * reaches the global scope. `wp_login_errors` receives the WP_Error object
	 * as an argument: it does not care about scope, and it covers both forms
	 * (login and lost password).
	 *
	 * The message is replaced while KEEPING the code: wp-login.php uses
	 * `incorrect_password` right after to pre-fill the identifier field. Codes
	 * that say nothing about an account — empty password, blocked cookies — are
	 * left intact: the legitimate user needs them.
	 *
	 * @param mixed $errors WP_Error of the login page.
	 * @return mixed
	 */
	public function filter_login_errors( $errors ) {
		if ( ! $errors instanceof \WP_Error ) {
			return $errors;
		}

		$generic = __( 'Incorrect username or password.', 'lumia-tools' );

		foreach ( self::LOGIN_ORACLE_CODES as $code ) {
			if ( ! in_array( $code, $errors->get_error_codes(), true ) ) {
				continue;
			}

			$data = $errors->get_error_data( $code );
			$errors->remove( $code );
			$errors->add( $code, '<strong>' . esc_html__( 'Error:', 'lumia-tools' ) . '</strong> ' . esc_html( $generic ), $data );
		}

		return $errors;
	}

	/**
	 * Action lost_password: aligns the response of the "lost password" form
	 * with that of an existing account.
	 *
	 * Rewriting the message is not enough here, the oracle is in the SHAPE of
	 * the response: a known account triggers the sending then a redirect to
	 * `?checkemail=confirm`, an unknown account redisplays the form with an
	 * error. The difference is visible without even reading the text.
	 *
	 * When `invalidcombo` is the only error, the output of the nominal case is
	 * replayed — same redirect, same page. No e-mail is sent, and there is
	 * nobody to send one to.
	 *
	 * Accepted caveat: `retrieve_password_email_failure` remains
	 * distinguishable. Masking it would deprive the administrator of the only
	 * signal telling them their site's e-mail sending is broken — and on a
	 * site whose sending works, that code never appears.
	 *
	 * The `lostpassword_errors` filter is not suitable: the core adds
	 * `invalidcombo` AFTER applying it.
	 *
	 * @param mixed $errors WP_Error of the form.
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
	 * Hook wp_headers: removes the headers exposing the version.
	 *
	 * @param array<string, string> $headers
	 * @return array<string, string>
	 */
	public function hide_wp_version_headers( array $headers ): array {
		unset( $headers['X-Powered-By'] );
		return $headers;
	}

	/**
	 * Removes the X-Powered-By header added by PHP itself.
	 *
	 * `unset( $headers['X-Powered-By'] )` only acts on the header array that
	 * WordPress is about to emit. That header comes from PHP (`expose_php`),
	 * which has already set it: the setting was enabled and
	 * `X-Powered-By: PHP/8.5.7` went out on every response.
	 *
	 * `header_remove()` can only act before the headers are sent, hence the
	 * earliest possible hook. The real solution remains `expose_php = Off` in
	 * the PHP configuration: it also covers responses that do not go through
	 * WordPress (server error pages, scripts outside the core).
	 */
	public function remove_powered_by_header(): void {
		if ( ! headers_sent() ) {
			header_remove( 'X-Powered-By' );
		}
	}

	/**
	 * Hook init: removes the WP generator from all outputs (head, feeds).
	 */
	public function remove_wp_version_generators(): void {
		$actions = [ 'wp_head', 'rss2_head', 'commentsrss2_head', 'rss_head', 'rdf_header', 'atom_head', 'comments_atom_head', 'opml_head', 'app_head' ];
		foreach ( $actions as $action ) {
			remove_action( $action, 'the_generator' );
			remove_action( $action, 'wp_generator' );
		}
	}

	/**
	 * Filter script_loader_src / style_loader_src: replaces the WP version with a hash.
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
