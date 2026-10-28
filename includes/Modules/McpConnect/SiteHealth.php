<?php
namespace Lumia\Tools\Modules\McpConnect;

defined( 'ABSPATH' ) || exit;

/**
 * Site Health tests of MCP Connect: what breaks a connection before Claude
 * even gets to the sign-in page, and that the plugin cannot see from inside
 * PHP on a normal request.
 *
 * - Discovery: the two `/.well-known/` documents, fetched over a loopback.
 *   The nginx template of our hosting answers 403 to every `/.well-known/*`
 *   before PHP (see docs/modules/mcp-connect.md).
 * - Authorization header: Apache drops it unless the `.htaccess` rule copies
 *   it; without it every token is invisible and Claude loops on the sign-in.
 * - Environment: MCP Adapter, permalinks, HTTPS, agent account, Bricks.
 *
 * `direct` tests (run when the Site Health screen loads): each loopback has a
 * 5 s timeout, so the screen waits 15 s at worst.
 */
final class SiteHealth {

	/** Oldest MCP Adapter the hook-in was checked against (spike, 0.7.1). */
	const MIN_ADAPTER_VERSION = '0.7';

	/** Timeout of each loopback request, in seconds. */
	const TIMEOUT = 5;

	/**
	 * `site_status_tests` filter. The two protocol tests only exist while the
	 * protocol does: with LUMIA_DISABLE_MCP_CONNECT they would report the 404
	 * the constant asks for.
	 *
	 * @param mixed $tests
	 * @return mixed
	 */
	public static function register( $tests ) {
		if ( ! is_array( $tests ) ) {
			return $tests;
		}

		if ( Discovery::enabled() ) {
			$tests['direct']['lumia_mcp_discovery'] = [
				'label' => __( 'MCP Connect discovery documents', 'lumia-tools' ),
				'test'  => [ self::class, 'test_discovery' ],
			];

			$tests['direct']['lumia_mcp_authorization_header'] = [
				'label' => __( 'MCP Connect Authorization header', 'lumia-tools' ),
				'test'  => [ self::class, 'test_authorization_header' ],
			];
		}

		$tests['direct']['lumia_mcp_environment'] = [
			'label' => __( 'MCP Connect environment', 'lumia-tools' ),
			'test'  => [ self::class, 'test_environment' ],
		];

		return $tests;
	}

	/* ================================================================
	 * TESTS
	 * ================================================================ */

	/**
	 * Both discovery documents answer 200 JSON, name this site, and use https.
	 *
	 * @return array<string, mixed>
	 */
	public static function test_discovery(): array {
		$problems = [];
		$blocked  = false;
		$insecure = false;

		$documents = [
			[
				'url'   => Discovery::protected_resource_metadata_url(),
				'key'   => 'resource',
				'value' => Discovery::resource_url(),
			],
			[
				// Where the client looks, not where the module serves: for a
				// home URL with a path they differ (RFC 8414 path insertion).
				'url'   => Discovery::authorization_server_metadata_url(),
				'key'   => 'issuer',
				'value' => Discovery::issuer(),
			],
		];

		$subdirectory = '' !== untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
		$rewrite      = false;

		foreach ( $documents as $index => $document ) {
			$result = self::fetch( 'GET', $document['url'] );
			$failed = true;

			if ( '' !== $result['error'] ) {
				/* translators: 1: URL, 2: error message. */
				$problems[] = sprintf( __( '%1$s could not be reached: %2$s', 'lumia-tools' ), $document['url'], $result['error'] );
			} elseif ( 200 !== $result['code'] ) {
				$blocked = $blocked || 403 === $result['code'];
				/* translators: 1: URL, 2: HTTP status code. */
				$problems[] = sprintf( __( '%1$s answered HTTP %2$s instead of 200.', 'lumia-tools' ), $document['url'], (string) $result['code'] );
			} elseif ( null === $result['json'] || ( $result['json'][ $document['key'] ] ?? null ) !== $document['value'] ) {
				/* translators: %s: URL. */
				$problems[] = sprintf( __( '%s did not return the expected document (another plugin or a cache may answer instead).', 'lumia-tools' ), $document['url'] );
			} else {
				$failed   = false;
				$insecure = $insecure || 0 !== strpos( $document['value'], 'https://' );
			}

			if ( $failed && $subdirectory && 1 === $index ) {
				$rewrite = true;
			}
		}

		$result = self::result( 'lumia_mcp_discovery' );

		if ( $problems ) {
			$result['status']      = 'critical';
			$result['label']       = __( 'Claude cannot discover how to sign in to this site', 'lumia-tools' );
			$result['description'] = '<p>' . esc_html__( 'Claude reads two documents under /.well-known/ to find the sign-in page. They are not served correctly:', 'lumia-tools' ) . '</p>'
				. self::list( $problems )
				. ( $blocked ? self::nginx_hint() : '' )
				. ( $rewrite ? self::subdirectory_hint() : '' )
				. '<p>' . esc_html__( 'Pretty permalinks must be enabled: with plain permalinks WordPress never receives these paths.', 'lumia-tools' ) . '</p>';

			return $result;
		}

		if ( $insecure ) {
			$result['status']      = 'recommended';
			$result['label']       = __( 'The MCP Connect discovery documents are served over plain HTTP', 'lumia-tools' );
			$result['description'] = '<p>' . esc_html__( 'The documents answer, but with http:// addresses. Claude only connects to sites served over HTTPS: set the site address to https.', 'lumia-tools' ) . '</p>';

			return $result;
		}

		$result['label']       = __( 'Claude can discover how to sign in to this site', 'lumia-tools' );
		$result['description'] = '<p>' . esc_html__( 'Both discovery documents answer under /.well-known/ with this site\'s addresses.', 'lumia-tools' ) . '</p>';

		return $result;
	}

	/**
	 * A Bearer header sent to the ping route reaches PHP. The route only
	 * reports whether it saw the header; the probe value is not a token.
	 *
	 * @return array<string, mixed>
	 */
	public static function test_authorization_header(): array {
		$response = self::fetch( 'POST', rest_url( 'lumia-mcp/v1/ping' ), [ 'Authorization' => 'Bearer lmc_probe' ] );
		$result   = self::result( 'lumia_mcp_authorization_header' );

		if ( '' === $response['error'] && 200 === $response['code'] && ! empty( $response['json']['authorization'] ) ) {
			$result['label']       = __( 'The Authorization header reaches WordPress', 'lumia-tools' );
			$result['description'] = '<p>' . esc_html__( 'The tokens Claude sends with each request are visible to the site.', 'lumia-tools' ) . '</p>';

			return $result;
		}

		$result['status'] = 'critical';
		$result['label']  = __( 'The web server removes the Authorization header', 'lumia-tools' );

		if ( '' !== $response['error'] || 200 !== $response['code'] ) {
			$detail = '' !== $response['error']
				? $response['error']
				/* translators: %s: HTTP status code. */
				: sprintf( __( 'HTTP %s', 'lumia-tools' ), (string) $response['code'] );

			$result['status']      = 'recommended';
			$result['label']       = __( 'The Authorization header could not be checked', 'lumia-tools' );
			$result['description'] = '<p>'
				/* translators: %s: error message or HTTP status. */
				. esc_html( sprintf( __( 'The site could not call itself to run the check (%s). Loopback requests may be blocked by the host or a firewall.', 'lumia-tools' ), $detail ) )
				. '</p>';

			return $result;
		}

		$result['description'] = '<p>' . esc_html__( 'Claude authenticates every request with an Authorization header, which this server does not pass to PHP: every connection would be refused. Depending on the server:', 'lumia-tools' ) . '</p>'
			. '<ul>'
			. '<li>' . esc_html__( 'Apache or LiteSpeed: in .htaccess, before the WordPress rules, add', 'lumia-tools' ) . ' <code>RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]</code></li>'
			. '<li>' . esc_html__( 'nginx with PHP-FPM: in the PHP location, add', 'lumia-tools' ) . ' <code>fastcgi_param HTTP_AUTHORIZATION $http_authorization;</code></li>'
			. '</ul>';

		return $result;
	}

	/**
	 * Requirements the site can check without a request.
	 *
	 * @return array<string, mixed>
	 */
	public static function test_environment(): array {
		$critical    = [];
		$recommended = [];

		foreach ( self::checks() as $check ) {
			/* translators: 1: label, 2: value. */
			$line = sprintf( __( '%1$s: %2$s', 'lumia-tools' ), $check['label'], $check['value'] );

			if ( 'critical' === $check['state'] ) {
				$critical[] = $line;
			} elseif ( 'recommended' === $check['state'] ) {
				$recommended[] = $line;
			}
		}

		$result = self::result( 'lumia_mcp_environment' );

		if ( ! $critical && ! $recommended ) {
			$result['label']       = __( 'MCP Connect has everything it needs', 'lumia-tools' );
			$result['description'] = '<p>' . esc_html__( 'MCP Adapter, permalinks, HTTPS and the Claude agent account are in place.', 'lumia-tools' ) . '</p>';

			return $result;
		}

		$result['status']      = $critical ? 'critical' : 'recommended';
		$result['label']       = $critical
			? __( 'MCP Connect cannot work on this site yet', 'lumia-tools' )
			: __( 'MCP Connect works with limitations', 'lumia-tools' );
		$result['description'] = self::list( array_merge( $critical, $recommended ) );

		return $result;
	}

	/* ================================================================
	 * ENVIRONMENT CHECKS (also shown in the module's Help tab)
	 * ================================================================ */

	/**
	 * State: `good`, `critical` (connections cannot work), `recommended`
	 * (they work, with a limitation) or `info` (nothing to check).
	 *
	 * @return array<string, array{label: string, value: string, state: string}>
	 */
	public static function checks(): array {
		$checks  = [];
		$version = self::adapter_version();

		if ( null === $version ) {
			$adapter = [ __( 'Not active: install and activate the MCP Adapter plugin.', 'lumia-tools' ), 'critical' ];
		} elseif ( '' === $version ) {
			$adapter = [ __( 'Active (version unknown)', 'lumia-tools' ), 'good' ];
		} elseif ( version_compare( $version, self::MIN_ADAPTER_VERSION, '<' ) ) {
			/* translators: 1: installed version, 2: minimum version. */
			$adapter = [ sprintf( __( 'Version %1$s: version %2$s or later is required.', 'lumia-tools' ), $version, self::MIN_ADAPTER_VERSION ), 'critical' ];
		} else {
			/* translators: %s: version number. */
			$adapter = [ sprintf( __( 'Version %s', 'lumia-tools' ), $version ), 'good' ];
		}

		$checks['adapter'] = [
			'label' => __( 'MCP Adapter', 'lumia-tools' ),
			'value' => $adapter[0],
			'state' => $adapter[1],
		];

		$pretty               = '' !== (string) get_option( 'permalink_structure' );
		$checks['permalinks'] = [
			'label' => __( 'Permalinks', 'lumia-tools' ),
			'value' => $pretty
				? __( 'Pretty permalinks', 'lumia-tools' )
				: __( 'Plain permalinks: the discovery documents cannot be served. Choose any other structure in Settings > Permalinks.', 'lumia-tools' ),
			'state' => $pretty ? 'good' : 'critical',
		];

		$https           = 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME );
		$checks['https'] = [
			'label' => __( 'HTTPS', 'lumia-tools' ),
			'value' => $https
				? __( 'The site address uses HTTPS', 'lumia-tools' )
				: __( 'The site address uses plain HTTP: Claude only connects to HTTPS sites.', 'lumia-tools' ),
			'state' => $https ? 'good' : 'critical',
		];

		$agent_id        = Agent::user_id();
		$agent           = $agent_id > 0 ? get_userdata( $agent_id ) : false;
		$checks['agent'] = [
			'label' => __( 'Claude account', 'lumia-tools' ),
			'value' => $agent
				/* translators: 1: display name of the dedicated account, 2: its login. */
				? sprintf( __( '%1$s (%2$s)', 'lumia-tools' ), $agent->display_name, $agent->user_login )
				: __( 'Missing: it is created again on the next visit of an administrator to the admin.', 'lumia-tools' ),
			'state' => $agent ? 'good' : 'recommended',
		];

		$presets          = Agent::presets();
		$preset           = Agent::current_preset();
		$preset_label     = (string) ( $presets[ $preset ]['label'] ?? '' );
		$checks['preset'] = [
			'label' => __( 'Claude account permissions', 'lumia-tools' ),
			'value' => null !== Agent::forced_preset()
				/* translators: %s: name of the permission preset. */
				? sprintf( __( '%s (set by LUMIA_MCP_AGENT_PRESET in wp-config.php)', 'lumia-tools' ), $preset_label )
				: $preset_label,
			'state' => 'info',
		];

		$checks += self::code_checks( $preset );

		switch ( Agent::bricks_abilities_status() ) {
			case 'ok':
				$abilities = [ __( 'On: Claude can edit pages, templates and the design system with Bricks', 'lumia-tools' ), 'good' ];
				break;
			case 'off':
				$abilities = [ __( 'Off: Claude connects but has no tool to edit the site. Turn on "Enable Bricks abilities" in Bricks > AI.', 'lumia-tools' ), 'recommended' ];
				break;
			case 'unsupported':
				$abilities = [ __( 'Bricks 2.4 or later is needed for Claude to edit with Bricks.', 'lumia-tools' ), 'recommended' ];
				break;
			default:
				$abilities = [ __( 'Bricks is not the active theme', 'lumia-tools' ), 'info' ];
		}

		$checks['bricks_abilities'] = [
			'label' => __( 'Bricks abilities', 'lumia-tools' ),
			'value' => $abilities[0],
			'state' => $abilities[1],
		];

		switch ( Agent::bricks_status() ) {
			case 'ok':
				$bricks = [ __( 'The Claude account can edit with Bricks', 'lumia-tools' ), 'good' ];
				break;
			case 'missing':
				$bricks = [ __( 'Bricks is active but the Claude role has no builder access: grant it in Bricks > Settings > Builder access.', 'lumia-tools' ), 'recommended' ];
				break;
			default:
				$bricks = [ __( 'Bricks is not the active theme', 'lumia-tools' ), 'info' ];
		}

		$checks['bricks'] = [
			'label' => __( 'Bricks builder access', 'lumia-tools' ),
			'value' => $bricks[0],
			'state' => $bricks[1],
		];

		return $checks;
	}

	/**
	 * What the wp-config constants leave of the Administrator preset's code
	 * rights: JavaScript, plugin installs and updates, and the PHP ability of
	 * Bricks. Nothing to check under Editor, which holds none of them.
	 *
	 * @param string $preset Active preset.
	 * @return array<string, array{label: string, value: string, state: string}>
	 */
	private static function code_checks( string $preset ): array {
		if ( Agent::PRESET_ADMIN !== $preset ) {
			return [
				'code' => [
					'label' => __( 'Code', 'lumia-tools' ),
					'value' => __( 'Editor preset: Claude cannot write JavaScript or PHP, change the settings or manage plugins.', 'lumia-tools' ),
					'state' => 'info',
				],
			];
		}

		// map_meta_cap() turns these into do_not_allow, whatever the role holds.
		if ( defined( 'DISALLOW_UNFILTERED_HTML' ) && DISALLOW_UNFILTERED_HTML ) {
			$code = [ __( 'Blocked by DISALLOW_UNFILTERED_HTML in wp-config.php: Claude cannot write JavaScript in pages or Bricks scripts.', 'lumia-tools' ), 'recommended' ];
		} elseif ( is_multisite() ) {
			$code = [ __( 'On a multisite network only super admins may write JavaScript: Claude cannot.', 'lumia-tools' ), 'recommended' ];
		} else {
			$code = [ __( 'Claude can write JavaScript, CSS and HTML in pages and Bricks scripts.', 'lumia-tools' ), 'good' ];
		}

		$checks = [
			'code' => [
				'label' => __( 'Code', 'lumia-tools' ),
				'value' => $code[0],
				'state' => $code[1],
			],
		];

		// The context map_meta_cap() itself passes for install_plugins and the like.
		if ( ! wp_is_file_mod_allowed( 'capability_update_core' ) ) {
			$plugins = [ __( 'File changes are disallowed on this site (DISALLOW_FILE_MODS in wp-config.php): Claude cannot install or update plugins.', 'lumia-tools' ), 'recommended' ];
		} elseif ( is_multisite() ) {
			$plugins = [ __( 'On a multisite network only super admins may install, update or remove plugins: Claude cannot.', 'lumia-tools' ), 'recommended' ];
		} else {
			$plugins = [ __( 'Claude can install, update and remove plugins.', 'lumia-tools' ), 'good' ];
		}

		$checks['plugins'] = [
			'label' => __( 'Plugins', 'lumia-tools' ),
			'value' => $plugins[0],
			'state' => $plugins[1],
		];

		// map_meta_cap() refuses unfiltered_upload without the constant, and to
		// anyone but a super admin on a network. Agent::define_code_constants()
		// sets it unless wp-config.php did.
		if ( is_multisite() ) {
			$uploads = __( 'On a multisite network only super admins may upload any file type: Claude uploads the types WordPress allows.', 'lumia-tools' );
		} elseif ( defined( 'ALLOW_UNFILTERED_UPLOADS' ) && ALLOW_UNFILTERED_UPLOADS ) {
			$uploads = 'lumia' === Agent::constant_source( 'ALLOW_UNFILTERED_UPLOADS' )
				? __( 'Claude can upload any file type (ALLOW_UNFILTERED_UPLOADS set by Lümia Tools).', 'lumia-tools' )
				: __( 'Claude can upload any file type (ALLOW_UNFILTERED_UPLOADS set outside Lümia Tools).', 'lumia-tools' );
		} elseif ( defined( 'ALLOW_UNFILTERED_UPLOADS' ) ) {
			$uploads = __( 'ALLOW_UNFILTERED_UPLOADS is set to false outside Lümia Tools: Claude uploads the file types WordPress allows.', 'lumia-tools' );
		} else {
			$uploads = self::constants_not_defined();
		}

		$checks['uploads'] = [
			'label' => __( 'File types', 'lumia-tools' ),
			'value' => $uploads,
			'state' => 'info',
		];

		if ( 'bricks' === get_template() ) {
			$php           = defined( 'BRICKS_ENABLE_PHP_ABILITIES' ) && BRICKS_ENABLE_PHP_ABILITIES;
			$checks['php'] = [
				'label' => __( 'Bricks PHP ability', 'lumia-tools' ),
				'value' => $php
					? ( 'lumia' === Agent::constant_source( 'BRICKS_ENABLE_PHP_ABILITIES' )
						? __( 'On (BRICKS_ENABLE_PHP_ABILITIES set by Lümia Tools): Claude can run PHP through bricks/execute-php once it is enabled in Bricks > AI.', 'lumia-tools' )
						: __( 'On (BRICKS_ENABLE_PHP_ABILITIES set outside Lümia Tools): Claude can run PHP through bricks/execute-php once it is enabled in Bricks > AI.', 'lumia-tools' ) )
					: ( defined( 'BRICKS_ENABLE_PHP_ABILITIES' )
						? __( 'Off: BRICKS_ENABLE_PHP_ABILITIES is set to false outside Lümia Tools.', 'lumia-tools' )
						: self::constants_not_defined() ),
				'state' => 'info',
			];
		}

		return $checks;
	}

	/**
	 * Why Lümia Tools did not define a code constant (Agent::define_code_constants()).
	 */
	private static function constants_not_defined(): string {
		if ( ! wp_is_file_mod_allowed( 'capability_update_core' ) || ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) ) {
			return __( 'Not set: wp-config.php forbids file changes or the code editors (DISALLOW_FILE_MODS, DISALLOW_FILE_EDIT), so Lümia Tools does not open this.', 'lumia-tools' );
		}

		return __( 'Not set yet: Lümia Tools sets it from the next page load (MCP Connect active, Administrator preset).', 'lumia-tools' );
	}

	/**
	 * Version of the active MCP Adapter: null when it is not loaded, '' when
	 * it is (bundled by another plugin, for instance) but its version cannot
	 * be read.
	 */
	public static function adapter_version(): ?string {
		if ( ! class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
			return null;
		}

		if ( function_exists( 'get_plugins' ) ) {
			foreach ( get_plugins() as $file => $data ) {
				if ( 'mcp-adapter.php' === wp_basename( $file ) && ! empty( $data['Version'] ) ) {
					return (string) $data['Version'];
				}
			}
		}

		$file = WP_PLUGIN_DIR . '/mcp-adapter/mcp-adapter.php';

		if ( is_readable( $file ) ) {
			$data = get_file_data( $file, [ 'Version' => 'Version' ] );

			return (string) $data['Version'];
		}

		return '';
	}

	/* ================================================================
	 * HELPERS
	 * ================================================================ */

	/**
	 * Skeleton of a result, `good` until a test says otherwise.
	 *
	 * @return array<string, mixed>
	 */
	private static function result( string $test ): array {
		return [
			'label'       => '',
			'status'      => 'good',
			'badge'       => [
				'label' => __( 'MCP Connect', 'lumia-tools' ),
				'color' => 'blue',
			],
			'description' => '',
			'actions'     => '',
			'test'        => $test,
		];
	}

	/**
	 * Loopback request. No redirect followed: an http → https redirect or a
	 * login redirect is itself the problem to report.
	 *
	 * @param array<string, string> $headers
	 * @return array{code: int, json: array<string, mixed>|null, error: string}
	 */
	private static function fetch( string $method, string $url, array $headers = [] ): array {
		$response = wp_remote_request(
			$url,
			[
				'method'      => $method,
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'headers'     => array_merge( [ 'Accept' => 'application/json' ], $headers ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return [
				'code'  => 0,
				'json'  => null,
				'error' => $response->get_error_message(),
			];
		}

		$json = json_decode( wp_remote_retrieve_body( $response ), true );

		return [
			'code'  => (int) wp_remote_retrieve_response_code( $response ),
			'json'  => is_array( $json ) ? $json : null,
			'error' => '',
		];
	}

	/**
	 * @param string[] $items
	 */
	private static function list( array $items ): string {
		return '<ul><li>' . implode( '</li><li>', array_map( 'esc_html', $items ) ) . '</li></ul>';
	}

	/**
	 * A home URL with a path: the client asks the origin root for the
	 * authorization server document, which WordPress never sees unless the
	 * web server rewrites that path to this site.
	 */
	private static function subdirectory_hint(): string {
		$path = untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );

		return '<p>'
			. esc_html(
				sprintf(
					/* translators: 1: URL path Claude requests at the server root, 2: URL of the document on this site. */
					__( 'This site lives under a path, so Claude asks the server root for %1$s. Rewrite that path, at the web server level, to %2$s.', 'lumia-tools' ),
					'/.well-known/oauth-authorization-server' . $path,
					Discovery::issuer() . '/.well-known/oauth-authorization-server'
				)
			)
			. '</p>';
	}

	/**
	 * The nginx template denies every path starting with a dot; a `^~` prefix
	 * location wins over that regex location wherever it is written.
	 */
	private static function nginx_hint(): string {
		return '<p>' . esc_html__( 'A 403 usually means the web server blocks /.well-known/ before WordPress. On nginx, add this to the site configuration:', 'lumia-tools' ) . '</p>'
			. '<p><code>location ^~ /.well-known/oauth- { try_files $uri /index.php?$args; }</code></p>'
			. '<p>' . esc_html__( 'On Apache or LiteSpeed, check that no rule of the server or of .htaccess denies paths starting with a dot.', 'lumia-tools' ) . '</p>';
	}
}
