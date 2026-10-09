<?php
/**
 * Constantes que PHPStan ne trouve pas dans les stubs WordPress ni dans le code analysé.
 * Fichier d'outillage uniquement : jamais chargé par l'extension.
 */

define( 'WPINC', 'wp-includes' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- constante du cœur WordPress, déclarée pour PHPStan uniquement.

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
