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

		public static function success( string $message ): void {}

		public static function warning( string $message ): void {}

		public static function error( string $message, bool $halt = true ): void {}

		public static function add_command( string $name, object $handler ): void {}

		/**
		 * @param mixed                $value      Value to print.
		 * @param array<string, mixed> $assoc_args Options (`format`).
		 */
		public static function print_value( $value, array $assoc_args = [] ): void {}

		/**
		 * @param string               $command Command to run, without the leading `wp`.
		 * @param array<string, mixed> $options Options of the call.
		 * @return mixed
		 */
		public static function runcommand( string $command, array $options = [] ) {}
	}
}
