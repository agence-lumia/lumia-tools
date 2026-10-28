<?php
namespace Lumia\Tools\Modules\McpConnect\Abilities;

defined( 'ABSPATH' ) || exit;

use Lumia\Tools\Core\Compat;
use Lumia\Tools\Modules\McpConnect\Module;

/**
 * The `lumia/*` abilities: site tools for Claude, registered through the
 * WordPress Abilities API (6.9+) and served by the MCP Adapter like the
 * Bricks ones.
 *
 * Each family (Diagnostics, HttpProbe, Cache, Plugins, Options, Snippets,
 * Seo) returns its definitions; this class turns them into abilities with the
 * shared parts: the category, the capability check, the MCP exposure and the
 * Activity Log entry of every ability that changes something. A family can be
 * turned off in the MCP Connect settings; Snippets and Seo are only
 * registered when their plugin is active.
 */
final class Registry {

	/** Category of every ability of the plugin. */
	const CATEGORY = 'lumia-tools';

	/** Prefix of the ability names. */
	const PREFIX = 'lumia/';

	/**
	 * Families, by settings key.
	 *
	 * @var array<string, class-string<Family>>
	 */
	const FAMILIES = [
		'diagnostics' => Diagnostics::class,
		'http'        => HttpProbe::class,
		'cache'       => Cache::class,
		'plugins'     => Plugins::class,
		'options'     => Options::class,
		'snippets'    => Snippets::class,
		'seo'         => Seo::class,
	];

	/**
	 * Hooks the registration. The Abilities API builds its registry lazily, on
	 * first use: when that already happened (another plugin used it during
	 * `init`, before this module), the abilities are registered at once,
	 * straight into the registries (wp_register_ability() and
	 * wp_register_ability_category() refuse anything outside their action).
	 */
	public static function boot(): void {
		if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		if ( did_action( 'wp_abilities_api_categories_init' ) ) {
			self::register_category();
		} else {
			add_action( 'wp_abilities_api_categories_init', [ self::class, 'register_category' ] );
		}

		if ( did_action( 'wp_abilities_api_init' ) ) {
			self::register_abilities();
		} else {
			add_action( 'wp_abilities_api_init', [ self::class, 'register_abilities' ] );
		}
	}

	public static function register_category(): void {
		$args = [
			'label'       => __( 'Lümia Tools', 'lumia-tools' ),
			'description' => __( 'Site tools for Claude: diagnostics, page checks, caches, plugins, options, snippets and SEO.', 'lumia-tools' ),
		];

		if ( doing_action( 'wp_abilities_api_categories_init' ) ) {
			wp_register_ability_category( self::CATEGORY, $args );
			return;
		}

		$registry = \WP_Ability_Categories_Registry::get_instance();
		if ( null !== $registry && ! $registry->is_registered( self::CATEGORY ) ) {
			$registry->register( self::CATEGORY, $args );
		}
	}

	/**
	 * Registers the abilities of every family turned on and available.
	 */
	public static function register_abilities(): void {
		foreach ( self::enabled_families() as $class ) {
			foreach ( $class::definitions() as $name => $definition ) {
				self::register( $name, $definition );
			}
		}
	}

	/**
	 * Family classes turned on in the settings and whose plugin is active.
	 *
	 * @return array<string, class-string<Family>>
	 */
	public static function enabled_families(): array {
		$settings = Module::settings()['abilities'] ?? [];
		$settings = is_array( $settings ) ? $settings : [];
		$enabled  = [];

		foreach ( self::FAMILIES as $key => $class ) {
			if ( empty( $settings[ $key ] ) || ! $class::available() ) {
				continue;
			}
			$enabled[ $key ] = $class;
		}

		return $enabled;
	}

	/**
	 * Family labels and their state, for the settings screen.
	 *
	 * @return array<string, array{label: string, description: string, available: bool}>
	 */
	public static function describe_families(): array {
		$info = [];
		foreach ( self::FAMILIES as $key => $class ) {
			$info[ $key ] = [
				'label'       => $class::label(),
				'description' => $class::description(),
				'available'   => $class::available(),
			];
		}

		return $info;
	}

	/**
	 * Registers one ability from a family definition.
	 *
	 * @param string               $name       Name without the prefix.
	 * @param array<string, mixed> $definition {label, description, input?, execute, capability, readonly, destructive?, idempotent?}.
	 */
	private static function register( string $name, array $definition ): void {
		$full       = strtolower( self::PREFIX . $name );
		$execute    = $definition['execute'];
		$capability = (string) $definition['capability'];
		$readonly   = ! empty( $definition['readonly'] );
		$input      = $definition['input'] ?? [];

		if ( ! is_callable( $execute ) ) {
			return;
		}

		$schema = [
			'type'                 => 'object',
			'properties'           => is_array( $input ) ? $input : [],
			'additionalProperties' => false,
		];
		if ( ! empty( $definition['required'] ) && is_array( $definition['required'] ) ) {
			$schema['required'] = $definition['required'];
		} else {
			// No required field: the call may come without any input.
			$schema['default'] = [];
		}

		$args = [
			'label'               => (string) $definition['label'],
			'description'         => (string) $definition['description'],
			'category'            => self::CATEGORY,
			'input_schema'        => $schema,
			'execute_callback'    => static function ( $args = [] ) use ( $full, $execute, $readonly ) {
				$args   = is_array( $args ) ? $args : [];
				$result = $execute( $args );

				if ( ! $readonly && ! is_wp_error( $result ) ) {
					self::log( $full, $args );
				}

				return $result;
			},
			'permission_callback' => static function () use ( $capability ): bool {
				return current_user_can( $capability );
			},
			'meta'                => [
				'annotations'  => [
					'readonly'    => $readonly,
					'destructive' => ! empty( $definition['destructive'] ),
					'idempotent'  => ! empty( $definition['idempotent'] ),
				],
				'show_in_rest' => true,
				'mcp'          => [
					'public' => true,
					'type'   => 'tool',
				],
			],
		];

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			wp_register_ability( $full, $args );
			return;
		}

		$registry = \WP_Abilities_Registry::get_instance();
		if ( null !== $registry && ! $registry->is_registered( $full ) ) {
			$registry->register( $full, $args );
		}
	}

	/**
	 * Fires `lumia_mcp_ability_executed` (Activity Log) after an ability that
	 * changes something. Only short scalar arguments are passed on: code,
	 * option values and snippet bodies stay out of the log.
	 *
	 * @param string               $ability Full ability name.
	 * @param array<string, mixed> $args    Input of the call.
	 */
	private static function log( string $ability, array $args ): void {
		$details = [];
		foreach ( $args as $key => $value ) {
			// Code and values stay out; a zip URL may carry a signed token.
			if ( in_array( $key, [ 'code', 'value', 'content', 'zip_url' ], true ) ) {
				continue;
			}
			if ( is_scalar( $value ) ) {
				$details[ (string) $key ] = mb_substr( (string) $value, 0, 200 );
			} elseif ( is_array( $value ) && count( $value ) <= 10 && array_filter( $value, 'is_scalar' ) === $value ) {
				$details[ (string) $key ] = mb_substr( implode( ', ', array_map( 'strval', $value ) ), 0, 200 );
			}
		}

		Compat::do_action( 'mcp_ability_executed', $ability, $details );
	}

	/**
	 * Standard error of an ability.
	 *
	 * @param string $code    Error code, without the prefix.
	 * @param string $message Message for Claude, in English.
	 */
	public static function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( 'lumia_' . $code, $message );
	}
}
