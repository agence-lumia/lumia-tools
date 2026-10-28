<?php
namespace Lumia\Tools\Modules\McpConnect\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Plugins: list, install (wordpress.org slug or zip URL), update, activate,
 * deactivate, delete.
 *
 * Lümia Tools and the MCP Adapter carry the connection Claude uses: they can
 * be updated, never deactivated nor deleted through it (Claude would cut the
 * branch it sits on, mid-task, with nobody to restore it).
 */
final class Plugins implements Family {

	public static function label(): string {
		return __( 'Plugins', 'lumia-tools' );
	}

	public static function description(): string {
		return __( 'List, install, update, activate, deactivate and delete plugins (never Lümia Tools nor the MCP Adapter).', 'lumia-tools' );
	}

	public static function available(): bool {
		return true;
	}

	public static function definitions(): array {
		$plugin = [
			'type'        => 'string',
			'description' => 'Plugin file as listed by lumia/list-plugins (e.g. seo-by-rank-math/rank-math.php), or its folder (seo-by-rank-math).',
		];

		return [
			'list-plugins'      => [
				'label'       => __( 'List the plugins', 'lumia-tools' ),
				'description' => 'Lists the installed plugins: file, name, version, active state, available update. The plugin file is what the other plugin abilities take.',
				'input'       => [
					'refresh_updates' => [
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Ask wordpress.org for updates first (slower).',
					],
				],
				'execute'     => [ self::class, 'list' ],
				'capability'  => 'activate_plugins',
				'readonly'    => true,
				'idempotent'  => true,
			],
			'install-plugin'    => [
				'label'       => __( 'Install a plugin', 'lumia-tools' ),
				'description' => 'Installs a plugin from wordpress.org (`slug`, e.g. fluent-smtp) or from the https URL of a zip (`zip_url`, e.g. a GitHub release asset), and activates it when `activate` is true.',
				'input'       => [
					'slug'     => [
						'type'        => 'string',
						'description' => 'wordpress.org slug.',
					],
					'zip_url'  => [
						'type'        => 'string',
						'description' => 'https URL of a plugin zip.',
					],
					'activate' => [
						'type'    => 'boolean',
						'default' => false,
					],
				],
				'execute'     => [ self::class, 'install' ],
				'capability'  => 'install_plugins',
				'readonly'    => false,
				'destructive' => false,
			],
			'update-plugin'     => [
				'label'       => __( 'Update a plugin', 'lumia-tools' ),
				'description' => 'Updates a plugin to the version its update source offers (wordpress.org or the plugin\'s own updater). Its active state is kept.',
				'input'       => [ 'plugin' => $plugin ],
				'required'    => [ 'plugin' ],
				'execute'     => [ self::class, 'update' ],
				'capability'  => 'update_plugins',
				'readonly'    => false,
				'destructive' => false,
			],
			'activate-plugin'   => [
				'label'       => __( 'Activate a plugin', 'lumia-tools' ),
				'description' => 'Activates an installed plugin. An activation error the plugin reports is returned; a fatal error in its main file ends the request instead (the plugin then stays inactive): check lumia/read-error-log.',
				'input'       => [ 'plugin' => $plugin ],
				'required'    => [ 'plugin' ],
				'execute'     => [ self::class, 'activate' ],
				'capability'  => 'activate_plugins',
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			],
			'deactivate-plugin' => [
				'label'       => __( 'Deactivate a plugin', 'lumia-tools' ),
				'description' => 'Deactivates a plugin. Lümia Tools and the MCP Adapter are refused: they carry this connection.',
				'input'       => [ 'plugin' => $plugin ],
				'required'    => [ 'plugin' ],
				'execute'     => [ self::class, 'deactivate' ],
				'capability'  => 'activate_plugins',
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			],
			'delete-plugin'     => [
				'label'       => __( 'Delete a plugin', 'lumia-tools' ),
				'description' => 'Deletes an inactive plugin and its files (its uninstall routine runs, which may delete its data). Lümia Tools and the MCP Adapter are refused.',
				'input'       => [ 'plugin' => $plugin ],
				'required'    => [ 'plugin' ],
				'execute'     => [ self::class, 'delete' ],
				'capability'  => 'delete_plugins',
				'readonly'    => false,
				'destructive' => true,
			],
		];
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public static function list( array $args ): array {
		self::load_admin();

		if ( ! empty( $args['refresh_updates'] ) ) {
			wp_update_plugins();
		}

		$updates = get_site_transient( 'update_plugins' );
		$updates = is_object( $updates ) && isset( $updates->response ) && is_array( $updates->response ) ? $updates->response : [];
		$list    = [];

		foreach ( get_plugins() as $file => $data ) {
			$update = $updates[ $file ] ?? null;
			$list[] = [
				'file'      => $file,
				'name'      => (string) ( $data['Name'] ?? '' ),
				'version'   => (string) ( $data['Version'] ?? '' ),
				'active'    => is_plugin_active( $file ),
				'update'    => is_object( $update ) && isset( $update->new_version ) ? (string) $update->new_version : null,
				'protected' => self::is_protected( $file ),
			];
		}

		return [
			'plugins'  => $list,
			'mu'       => array_keys( get_mu_plugins() ),
			'dropins'  => array_keys( get_dropins() ),
			'can_mods' => wp_is_file_mod_allowed( 'capability_update_core' ),
		];
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function install( array $args ) {
		self::load_admin();

		$slug = isset( $args['slug'] ) ? sanitize_key( (string) $args['slug'] ) : '';
		$zip  = isset( $args['zip_url'] ) ? esc_url_raw( (string) $args['zip_url'], [ 'https' ] ) : '';

		if ( ( '' === $slug ) === ( '' === $zip ) ) {
			return Registry::error( 'invalid_source', 'Give either `slug` (wordpress.org) or `zip_url` (https), not both.' );
		}

		$ready = self::filesystem_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		if ( '' !== $slug ) {
			$api = plugins_api(
				'plugin_information',
				[
					'slug'   => $slug,
					'fields' => [ 'sections' => false ],
				]
			);
			if ( is_wp_error( $api ) ) {
				return Registry::error( 'not_found', 'wordpress.org does not know this plugin: ' . $api->get_error_message() );
			}
			$package = is_object( $api ) && isset( $api->download_link ) ? (string) $api->download_link : '';
		} else {
			$package = $zip;
		}

		$before   = array_keys( get_plugins() );
		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $package );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( true !== $result ) {
			return self::skin_error( $skin, 'The plugin could not be installed.' );
		}

		wp_clean_plugins_cache();
		$file = $upgrader->plugin_info();
		if ( ! is_string( $file ) || '' === $file ) {
			$new  = array_diff( array_keys( get_plugins() ), $before );
			$file = (string) reset( $new );
		}

		$out = [
			'installed' => $file,
			'active'    => false,
		];

		if ( ! empty( $args['activate'] ) && '' !== $file ) {
			$activated = activate_plugin( $file );
			if ( is_wp_error( $activated ) ) {
				$out['activation_error'] = $activated->get_error_message();
			} else {
				$out['active'] = true;
			}
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function update( array $args ) {
		self::load_admin();

		$file = self::resolve( (string) ( $args['plugin'] ?? '' ) );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$ready = self::filesystem_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		wp_update_plugins();
		$before   = (string) ( get_plugins()[ $file ]['Version'] ?? '' );
		$active   = is_plugin_active( $file );
		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );

		try {
			$result = $upgrader->upgrade( $file );
		} finally {
			// Outside cron, the upgrader deactivates the plugin silently before
			// the copy and leaves the reactivation to the update screen in a
			// browser; a failed copy is rolled back but stays deactivated. It is
			// put back the same silent way whatever happened: no activation
			// hook, and no include of a main file this request may already have
			// loaded (Lümia Tools itself, the MCP Adapter, which carry this
			// connection).
			wp_clean_plugins_cache();
			if ( $active && ! is_plugin_active( $file ) ) {
				$plugins   = (array) get_option( 'active_plugins', [] );
				$plugins[] = $file;
				sort( $plugins );
				update_option( 'active_plugins', array_values( array_unique( $plugins ) ) );
			}
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( true !== $result ) {
			return self::skin_error( $skin, 'No update was installed (already up to date, or the update source refused).' );
		}

		return [
			'plugin' => $file,
			'from'   => $before,
			'to'     => (string) ( get_plugins()[ $file ]['Version'] ?? '' ),
			'active' => is_plugin_active( $file ),
		];
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function activate( array $args ) {
		self::load_admin();

		$file = self::resolve( (string) ( $args['plugin'] ?? '' ) );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$result = activate_plugin( $file );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return [
			'plugin' => $file,
			'active' => is_plugin_active( $file ),
		];
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function deactivate( array $args ) {
		self::load_admin();

		$file = self::resolve( (string) ( $args['plugin'] ?? '' ) );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( self::is_protected( $file ) ) {
			return Registry::error( 'protected_plugin', 'This plugin carries the MCP connection and cannot be deactivated through it. Do it in the WordPress admin.' );
		}

		deactivate_plugins( $file );

		return [
			'plugin' => $file,
			'active' => is_plugin_active( $file ),
		];
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function delete( array $args ) {
		self::load_admin();

		$file = self::resolve( (string) ( $args['plugin'] ?? '' ) );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( self::is_protected( $file ) ) {
			return Registry::error( 'protected_plugin', 'This plugin carries the MCP connection and cannot be deleted through it. Do it in the WordPress admin.' );
		}
		if ( is_plugin_active( $file ) ) {
			return Registry::error( 'plugin_active', 'Deactivate the plugin before deleting it (lumia/deactivate-plugin).' );
		}

		$ready = self::filesystem_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$result = delete_plugins( [ $file ] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( true !== $result ) {
			return Registry::error( 'delete_failed', 'The plugin files could not be deleted.' );
		}

		return [
			'plugin'  => $file,
			'deleted' => true,
		];
	}

	/**
	 * Plugin file from a file or a folder name; an error when not installed.
	 *
	 * @return string|\WP_Error
	 */
	private static function resolve( string $plugin ) {
		$plugin  = trim( wp_normalize_path( $plugin ), '/' );
		$plugins = get_plugins();

		if ( '' !== $plugin && isset( $plugins[ $plugin ] ) ) {
			return $plugin;
		}

		foreach ( array_keys( $plugins ) as $file ) {
			if ( dirname( $file ) === $plugin || basename( $file, '.php' ) === $plugin ) {
				return $file;
			}
		}

		return Registry::error( 'unknown_plugin', 'No installed plugin matches "' . $plugin . '". See lumia/list-plugins.' );
	}

	/**
	 * Lümia Tools itself, and the plugin that provides the MCP Adapter.
	 */
	public static function is_protected( string $file ): bool {
		$protected = [ plugin_basename( LUMIA_PLUGIN_FILE ) ];

		// The plugin that loaded the Adapter's class, whatever its folder.
		$source = class_exists( '\WP\MCP\Core\McpAdapter' ) ? ( new \ReflectionClass( \WP\MCP\Core\McpAdapter::class ) )->getFileName() : false;
		if ( is_string( $source ) ) {
			$source = wp_normalize_path( $source );
			$root   = wp_normalize_path( WP_PLUGIN_DIR ) . '/';
			$folder = 0 === strpos( $source, $root ) ? strtok( substr( $source, strlen( $root ) ), '/' ) : false;
			foreach ( array_keys( get_plugins() ) as $plugin ) {
				if ( false !== $folder && dirname( $plugin ) === $folder ) {
					$protected[] = $plugin;
				}
			}
		}

		return in_array( $file, $protected, true );
	}

	/**
	 * The upgrader needs direct file access: an FTP-credentials prompt cannot
	 * be answered through MCP.
	 *
	 * @return true|\WP_Error
	 */
	private static function filesystem_ready() {
		if ( ! wp_is_file_mod_allowed( 'capability_update_core' ) ) {
			return Registry::error( 'file_mods_disallowed', 'File changes are disallowed on this site (DISALLOW_FILE_MODS).' );
		}

		if ( 'direct' !== get_filesystem_method() || ! WP_Filesystem() ) {
			return Registry::error( 'filesystem', 'WordPress cannot write the plugin files directly on this server (it would ask for FTP credentials).' );
		}

		return true;
	}

	/**
	 * Error collected by the upgrader skin, or a default message.
	 */
	private static function skin_error( \WP_Ajax_Upgrader_Skin $skin, string $fallback ): \WP_Error {
		$errors = $skin->get_errors();
		if ( $errors->has_errors() ) {
			return $errors;
		}

		return Registry::error( 'upgrader', $fallback );
	}

	private static function load_admin(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
	}
}
