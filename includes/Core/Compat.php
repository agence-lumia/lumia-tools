<?php
namespace Lumia\Tools\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Compatibility layer with the former name of the plugin (Studio Kyne Mini Tools).
 *
 * A site that migrates from it may still define `SKMT_*` constants in
 * wp-config.php and hook snippets or theme code on `skmt_*` filters and actions.
 * Every read of those public names goes through here, so that both spellings keep
 * working: `LUMIA_*` and `lumia_*` first, `SKMT_*` and `skmt_*` as a fallback.
 *
 * This file is one of the few places where the legacy names may appear.
 */
final class Compat {

	/**
	 * Version in which the legacy hook names are announced as deprecated.
	 */
	private const DEPRECATED_SINCE = '2.0.0';

	/**
	 * Value of the `LUMIA_{$name}` constant, otherwise of `SKMT_{$name}`.
	 *
	 * @param string $name Constant name without prefix, e.g. `SMTP_USER`.
	 * @return mixed Null if neither constant is defined.
	 */
	public static function constant( string $name ) {
		foreach ( [ 'LUMIA_', 'SKMT_' ] as $prefix ) {
			if ( defined( $prefix . $name ) ) {
				return constant( $prefix . $name );
			}
		}

		return null;
	}

	/**
	 * Whether `LUMIA_{$name}` or `SKMT_{$name}` is defined.
	 *
	 * @param string $name Constant name without prefix.
	 */
	public static function has_constant( string $name ): bool {
		return defined( 'LUMIA_' . $name ) || defined( 'SKMT_' . $name );
	}

	/**
	 * Applies `lumia_{$suffix}`, after the legacy `skmt_{$suffix}` filter if
	 * something is hooked on it (with a deprecation notice in WP_DEBUG).
	 *
	 * @param string $suffix Filter name without prefix.
	 * @param mixed  $value  Value to filter.
	 * @param mixed  ...$args Extra arguments passed to the callbacks.
	 * @return mixed Filtered value.
	 */
	public static function apply_filters( string $suffix, $value, ...$args ) {
		if ( has_filter( 'skmt_' . $suffix ) ) {
			$value = apply_filters_deprecated(
				'skmt_' . $suffix,
				array_merge( [ $value ], $args ),
				self::DEPRECATED_SINCE,
				'lumia_' . $suffix
			);
		}

		return apply_filters( 'lumia_' . $suffix, $value, ...$args );
	}

	/**
	 * Fires the legacy `skmt_{$suffix}` action if something is hooked on it
	 * (with a deprecation notice in WP_DEBUG), then `lumia_{$suffix}`.
	 *
	 * @param string $suffix Action name without prefix.
	 * @param mixed  ...$args Arguments passed to the callbacks.
	 */
	public static function do_action( string $suffix, ...$args ): void {
		if ( has_action( 'skmt_' . $suffix ) ) {
			do_action_deprecated( 'skmt_' . $suffix, $args, self::DEPRECATED_SINCE, 'lumia_' . $suffix );
		}

		do_action( 'lumia_' . $suffix, ...$args );
	}
}
