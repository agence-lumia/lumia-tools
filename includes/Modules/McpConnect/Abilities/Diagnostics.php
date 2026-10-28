<?php
namespace Lumia\Tools\Modules\McpConnect\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Diagnostics: the environment of the site, and the debug log Claude reads
 * after a change to see what broke.
 */
final class Diagnostics implements Family {

	/** Lines returned by default, and at most. */
	const DEFAULT_LINES = 100;
	const MAX_LINES     = 2000;

	/** Bytes read at most from the end of a log. */
	const MAX_READ_BYTES = 2 * MB_IN_BYTES;

	public static function label(): string {
		return __( 'Diagnostics', 'lumia-tools' );
	}

	public static function description(): string {
		return __( 'Read the environment (versions, plugins, constants, limits) and the debug log, clear the debug log.', 'lumia-tools' );
	}

	public static function available(): bool {
		return true;
	}

	public static function definitions(): array {
		return [
			'get-environment' => [
				'label'       => __( 'Get the site environment', 'lumia-tools' ),
				'description' => 'Returns the technical environment of the site: PHP, WordPress, theme and Bricks versions, active plugins with their versions, PHP limits, debug and file constants, caches in place, cron state. Use it before diagnosing a problem or installing something.',
				'execute'     => [ self::class, 'environment' ],
				'capability'  => 'manage_options',
				'readonly'    => true,
				'idempotent'  => true,
			],
			'read-error-log'  => [
				'label'       => __( 'Read the error log', 'lumia-tools' ),
				'description' => 'Returns the last lines of the WordPress debug log (WP_DEBUG_LOG) or of the PHP error log, newest last. Read it after a change to check that nothing broke. Filter with `contains` (case-insensitive text) and `since` (date, e.g. 2026-10-28 14:00, in UTC as the log writes it).',
				'input'       => [
					'source'   => [
						'type'        => 'string',
						'enum'        => [ 'debug', 'php' ],
						'default'     => 'debug',
						'description' => 'debug: the WordPress debug log. php: the log set by the error_log PHP setting.',
					],
					'lines'    => [
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => self::MAX_LINES,
						'default'     => self::DEFAULT_LINES,
						'description' => 'Number of lines to return, counted from the end.',
					],
					'contains' => [
						'type'        => 'string',
						'description' => 'Keep only the lines containing this text (case-insensitive).',
					],
					'since'    => [
						'type'        => 'string',
						'description' => 'Keep only the entries logged at or after this date (any format strtotime() reads, UTC).',
					],
				],
				'execute'     => [ self::class, 'read_log' ],
				'capability'  => 'manage_options',
				'readonly'    => true,
				'idempotent'  => true,
			],
			'clear-error-log' => [
				'label'       => __( 'Clear the debug log', 'lumia-tools' ),
				'description' => 'Empties the WordPress debug log (WP_DEBUG_LOG), so the next read only shows what happens after it. The PHP error log is never touched.',
				'execute'     => [ self::class, 'clear_log' ],
				'capability'  => 'manage_options',
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function environment(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = [];
		foreach ( get_plugins() as $file => $data ) {
			if ( is_plugin_active( $file ) ) {
				$plugins[ $file ] = (string) ( $data['Version'] ?? '' );
			}
		}

		$theme     = wp_get_theme();
		$constants = [];
		foreach ( [ 'WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY', 'SCRIPT_DEBUG', 'WP_CACHE', 'DISABLE_WP_CRON', 'DISALLOW_FILE_MODS', 'DISALLOW_FILE_EDIT', 'DISALLOW_UNFILTERED_HTML', 'ALLOW_UNFILTERED_UPLOADS', 'WP_ENVIRONMENT_TYPE', 'WP_MEMORY_LIMIT', 'WP_MAX_MEMORY_LIMIT', 'BRICKS_ENABLE_PHP_ABILITIES' ] as $name ) {
			$constants[ $name ] = defined( $name ) ? constant( $name ) : null;
		}

		$opcache = function_exists( 'opcache_get_status' ) ? @opcache_get_status( false ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- restricted by opcache.restrict_api on some hosts.

		return [
			'site'      => [
				'home'        => home_url(),
				'siteurl'     => site_url(),
				'multisite'   => is_multisite(),
				'locale'      => get_locale(),
				'timezone'    => wp_timezone_string(),
				'permalinks'  => (string) get_option( 'permalink_structure' ),
				'environment' => wp_get_environment_type(),
			],
			'versions'  => [
				'php'         => PHP_VERSION,
				'wordpress'   => get_bloginfo( 'version' ),
				'lumia_tools' => LUMIA_VERSION,
				'bricks'      => defined( 'BRICKS_VERSION' ) ? (string) constant( 'BRICKS_VERSION' ) : null,
				'theme'       => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ),
				'template'    => get_template(),
				'mysql'       => self::db_version(),
			],
			'plugins'   => $plugins,
			'php'       => [
				'memory_limit'        => ini_get( 'memory_limit' ),
				'max_execution_time'  => ini_get( 'max_execution_time' ),
				'upload_max_filesize' => ini_get( 'upload_max_filesize' ),
				'post_max_size'       => ini_get( 'post_max_size' ),
				'extensions'          => array_values( array_intersect( [ 'curl', 'gd', 'imagick', 'intl', 'mbstring', 'redis', 'sodium', 'zip', 'opcache', 'Zend OPcache' ], get_loaded_extensions() ) ),
				'sapi'                => PHP_SAPI,
			],
			'constants' => $constants,
			'caches'    => [
				'object_cache' => wp_using_ext_object_cache(),
				'opcache'      => is_array( $opcache ) && ! empty( $opcache['opcache_enabled'] ),
				'page_cache'   => Cache::detected_page_caches(),
			],
			'server'    => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
			'cron'      => [
				'disabled'  => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				'scheduled' => count( (array) _get_cron_array() ),
			],
			'debug_log' => self::log_path( 'debug' ),
			'php_log'   => self::log_path( 'php' ),
		];
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function read_log( array $args ) {
		$source = 'php' === ( $args['source'] ?? '' ) ? 'php' : 'debug';
		$path   = self::log_path( $source );

		if ( '' === $path ) {
			return Registry::error(
				'no_log',
				'debug' === $source
					? 'No debug log: WP_DEBUG_LOG is not set. Add define( \'WP_DEBUG\', true ); define( \'WP_DEBUG_LOG\', true ); define( \'WP_DEBUG_DISPLAY\', false ); to wp-config.php.'
					: 'No PHP error log is set (error_log PHP setting).'
			);
		}

		if ( ! is_file( $path ) ) {
			return [
				'path'  => $path,
				'size'  => 0,
				'lines' => [],
				'note'  => 'The log file does not exist yet: nothing was logged since it was last cleared.',
			];
		}

		if ( ! is_readable( $path ) ) {
			return Registry::error( 'unreadable_log', 'The log file exists but PHP cannot read it: ' . $path );
		}

		$wanted   = min( self::MAX_LINES, max( 1, (int) ( $args['lines'] ?? self::DEFAULT_LINES ) ) );
		$contains = isset( $args['contains'] ) ? (string) $args['contains'] : '';
		$since    = isset( $args['since'] ) && '' !== $args['since'] ? strtotime( (string) $args['since'] . ' UTC' ) : false;
		$size     = (int) filesize( $path );
		$lines    = self::tail( $path, $size );

		if ( '' !== $contains ) {
			$lines = array_values(
				array_filter(
					$lines,
					static function ( string $line ) use ( $contains ): bool {
						return false !== stripos( $line, $contains );
					}
				)
			);
		}

		if ( false !== $since ) {
			$lines = self::since( $lines, $since );
		}

		return [
			'path'      => $path,
			'size'      => $size,
			'modified'  => gmdate( 'Y-m-d H:i:s', (int) filemtime( $path ) ) . ' UTC',
			'truncated' => $size > self::MAX_READ_BYTES,
			'lines'     => array_slice( $lines, -$wanted ),
		];
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function clear_log() {
		$path = self::log_path( 'debug' );

		if ( '' === $path ) {
			return Registry::error( 'no_log', 'No debug log: WP_DEBUG_LOG is not set.' );
		}

		if ( ! is_file( $path ) ) {
			return [
				'path'    => $path,
				'cleared' => 0,
			];
		}

		$size = (int) filesize( $path );
		// Truncated rather than deleted: the server may hold it open, and a
		// deleted file would keep receiving the writes.
		$handle = fopen( $path, 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- truncating the local debug log.
		if ( false === $handle || ! ftruncate( $handle, 0 ) ) {
			return Registry::error( 'clear_failed', 'The debug log could not be emptied (not writable): ' . $path );
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.

		return [
			'path'    => $path,
			'cleared' => $size,
		];
	}

	/**
	 * Path of a log, '' when there is none: WP_DEBUG_LOG can be true
	 * (wp-content/debug.log) or a path.
	 *
	 * @param string $source debug | php.
	 */
	public static function log_path( string $source ): string {
		if ( 'php' === $source ) {
			$path = (string) ini_get( 'error_log' );

			return '' !== $path && 'syslog' !== $path ? $path : '';
		}

		if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return '';
		}

		return is_string( WP_DEBUG_LOG ) && ! in_array( strtolower( WP_DEBUG_LOG ), [ '1', 'true' ], true )
			? WP_DEBUG_LOG
			: WP_CONTENT_DIR . '/debug.log';
	}

	/**
	 * Lines of the end of a file, at most MAX_READ_BYTES read; the first line
	 * is dropped when the read starts in the middle of it.
	 *
	 * @return string[]
	 */
	private static function tail( string $path, int $size ): array {
		$read   = min( $size, self::MAX_READ_BYTES );
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- reading the end of a local log.
		if ( false === $handle || $read <= 0 ) {
			return [];
		}

		fseek( $handle, -$read, SEEK_END );
		$data = (string) fread( $handle, $read ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- see above.
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.

		$lines = preg_split( '/\r\n|\n|\r/', $data );
		$lines = is_array( $lines ) ? $lines : [];
		if ( $read < $size ) {
			array_shift( $lines );
		}

		return array_values(
			array_filter(
				$lines,
				static function ( string $line ): bool {
					return '' !== trim( $line );
				}
			)
		);
	}

	/**
	 * Entries logged at or after a time. A line without a `[date]` prefix (a
	 * stack trace) follows the entry above it.
	 *
	 * @param string[] $lines
	 * @return string[]
	 */
	private static function since( array $lines, int $since ): array {
		$kept = [];
		$keep = false;
		foreach ( $lines as $line ) {
			if ( preg_match( '/^\[([^\]]+)\]/', $line, $match ) ) {
				$time = strtotime( $match[1] );
				$keep = false !== $time && $time >= $since;
			}
			if ( $keep ) {
				$kept[] = $line;
			}
		}

		return $kept;
	}

	private static function db_version(): string {
		global $wpdb;

		return $wpdb instanceof \wpdb ? (string) $wpdb->db_version() : '';
	}
}
