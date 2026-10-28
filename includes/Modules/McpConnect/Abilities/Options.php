<?php
namespace Lumia\Tools\Modules\McpConnect\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress options: read, write, delete. The settings screens of any plugin
 * (Rank Math, FluentSMTP, WooCommerce…) end up in options, so this reaches
 * what no dedicated ability covers.
 *
 * A few options are refused for writing: those that would cut the
 * connection or lock the site (addresses, active plugins, roles, the MCP
 * Connect settings) and the Lümia Tools ones, whose screens sanitize what
 * they store.
 */
final class Options implements Family {

	/** Options never written nor deleted through MCP. */
	const PROTECTED = [
		'siteurl',
		'home',
		'active_plugins',
		'active_sitewide_plugins',
		'template',
		'stylesheet',
		'db_version',
		'initial_db_version',
		'cron',
		'auth_key',
		'auth_salt',
		'logged_in_key',
		'logged_in_salt',
		'nonce_key',
		'nonce_salt',
		'secret_key',
	];

	/** Option name prefixes refused the same way. */
	const PROTECTED_PREFIXES = [ 'lumia_', 'skmt_', '_transient_', '_site_transient_' ];

	/** Characters of a value returned at most. */
	const MAX_CHARS = 200000;

	public static function label(): string {
		return __( 'Options', 'lumia-tools' );
	}

	public static function description(): string {
		return __( 'Read, write and delete WordPress options (plugin settings), except those that would cut the connection.', 'lumia-tools' );
	}

	public static function available(): bool {
		return true;
	}

	public static function definitions(): array {
		$name = [
			'type'        => 'string',
			'description' => 'Option name, e.g. blogname or rank-math-options-titles.',
		];

		return [
			'get-option'    => [
				'label'       => __( 'Read an option', 'lumia-tools' ),
				'description' => 'Returns the value of a WordPress option (string, number, array or object, as stored), or the names of the options starting with `search` when `name` is not given. Plugin settings live here: Rank Math (rank-math-options-*), WooCommerce (woocommerce_*), FluentSMTP (fluentmail-settings)…',
				'input'       => [
					'name'   => $name,
					'search' => [
						'type'        => 'string',
						'description' => 'List the option names starting with this prefix (at most 200).',
					],
				],
				'execute'     => [ self::class, 'get' ],
				'capability'  => 'manage_options',
				'readonly'    => true,
				'idempotent'  => true,
			],
			'update-option' => [
				'label'       => __( 'Write an option', 'lumia-tools' ),
				'description' => 'Writes a WordPress option. `value` replaces the whole value; with `merge` true and an array value, only the given keys are changed (recursively), which is what to use for plugin settings stored as arrays. Read it first with lumia/get-option. Refused for siteurl, home, active_plugins, template, stylesheet, cron, the salts and the lumia_* options.',
				'input'       => [
					'name'     => $name,
					'value'    => [
						// A type list: rest_validate_value_from_schema() warns on a schema without `type`.
						'type'        => [ 'string', 'number', 'integer', 'boolean', 'array', 'object', 'null' ],
						'description' => 'New value: string, number, boolean, array or object.',
					],
					'merge'    => [
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Merge an array value into the stored array instead of replacing it.',
					],
					'autoload' => [
						'type'        => 'boolean',
						'description' => 'Autoload setting of a new option; left as is for an existing one.',
					],
				],
				'required'    => [ 'name', 'value' ],
				'execute'     => [ self::class, 'update' ],
				'capability'  => 'manage_options',
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			],
			'delete-option' => [
				'label'       => __( 'Delete an option', 'lumia-tools' ),
				'description' => 'Deletes a WordPress option. Same refusals as lumia/update-option.',
				'input'       => [ 'name' => $name ],
				'required'    => [ 'name' ],
				'execute'     => [ self::class, 'delete' ],
				'capability'  => 'manage_options',
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			],
		];
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function get( array $args ) {
		$name = trim( (string) ( $args['name'] ?? '' ) );

		if ( '' === $name ) {
			$search = trim( (string) ( $args['search'] ?? '' ) );
			if ( '' === $search ) {
				return Registry::error( 'invalid_input', 'Give `name`, or `search` to list option names.' );
			}

			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a one-off listing of names, nothing to cache.
			$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name LIMIT 200", $wpdb->esc_like( $search ) . '%' ) );

			return [ 'names' => array_map( 'strval', (array) $names ) ];
		}

		$sentinel = new \stdClass();
		$value    = get_option( $name, $sentinel );
		if ( $value === $sentinel ) {
			return Registry::error( 'unknown_option', 'No option named "' . $name . '".' );
		}

		$encoded = wp_json_encode( $value );
		if ( is_string( $encoded ) && strlen( $encoded ) > self::MAX_CHARS ) {
			return [
				'name'      => $name,
				'truncated' => true,
				'json'      => substr( $encoded, 0, self::MAX_CHARS ),
			];
		}

		return [
			'name'  => $name,
			'value' => $value,
		];
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function update( array $args ) {
		$name    = trim( (string) ( $args['name'] ?? '' ) );
		$refused = self::refuse( $name );
		if ( null !== $refused ) {
			return $refused;
		}

		if ( ! array_key_exists( 'value', $args ) ) {
			return Registry::error( 'invalid_input', '`value` is required.' );
		}

		$value    = $args['value'];
		$sentinel = new \stdClass();
		$current  = get_option( $name, $sentinel );

		if ( ! empty( $args['merge'] ) ) {
			if ( ! is_array( $value ) || ( $current !== $sentinel && ! is_array( $current ) ) ) {
				return Registry::error( 'invalid_merge', '`merge` needs an array value and an option stored as an array.' );
			}
			$base  = is_array( $current ) ? $current : [];
			$value = array_replace_recursive( $base, $value );
		}

		if ( $current === $sentinel ) {
			$autoload = isset( $args['autoload'] ) ? (bool) $args['autoload'] : null;
			$saved    = add_option( $name, $value, '', $autoload );
		} else {
			$saved = update_option( $name, $value ) || $current === $value;
		}

		if ( ! $saved ) {
			return Registry::error( 'not_saved', 'WordPress did not save the option (a filter of the plugin that owns it may have refused the value).' );
		}

		return [
			'name'    => $name,
			'created' => $current === $sentinel,
			'value'   => get_option( $name ),
		];
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function delete( array $args ) {
		$name    = trim( (string) ( $args['name'] ?? '' ) );
		$refused = self::refuse( $name );
		if ( null !== $refused ) {
			return $refused;
		}

		return [
			'name'    => $name,
			'deleted' => delete_option( $name ),
		];
	}

	/**
	 * Error when an option may not be written or deleted, null otherwise.
	 */
	private static function refuse( string $name ): ?\WP_Error {
		global $wpdb;

		if ( '' === $name ) {
			return Registry::error( 'invalid_input', '`name` is required.' );
		}

		$lower = strtolower( $name );
		// The roles option is named after the table prefix: wp_user_roles.
		$roles = $wpdb instanceof \wpdb ? strtolower( $wpdb->get_blog_prefix() . 'user_roles' ) : '';

		if ( in_array( $lower, self::PROTECTED, true ) || $lower === $roles ) {
			return Registry::error( 'protected_option', 'The option "' . $name . '" cannot be changed through MCP (it would cut the connection or lock the site). Use the WordPress admin.' );
		}

		foreach ( self::PROTECTED_PREFIXES as $prefix ) {
			if ( 0 === strpos( $lower, $prefix ) ) {
				return Registry::error( 'protected_option', 'The option "' . $name . '" cannot be changed through MCP: Lümia Tools settings go through their own screens, transients through their owners.' );
			}
		}

		return null;
	}
}
