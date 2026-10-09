<?php
/**
 * Constants PHPStan finds neither in the WordPress stubs nor in the analysed code.
 * Tooling file only: never loaded by the plugin.
 */

define( 'WPINC', 'wp-includes' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core constant, declared for PHPStan only.

if ( ! class_exists( 'WP_CLI' ) ) {
	/**
	 * Minimal WP-CLI stub (no stubs package for it): the plugin only calls these methods.
	 */
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- WP-CLI class, declared for PHPStan only.
	class WP_CLI {
		public static function log( string $message ): void {}

		public static function warning( string $message ): void {}
	}
}
