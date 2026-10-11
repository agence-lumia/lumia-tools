<?php
/**
 * Minimal WordPress stand-ins for the tests that reach Controls::definitions().
 *
 * The control definitions build their labels with the translation and escaping
 * functions. The tests only read keys and units, so identity stubs are enough.
 * Development only, never shipped.
 *
 * @package Lumia\Tools
 */

// Development-only file: the point is to declare WordPress core functions.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, Universal.Files.SeparateFunctionsFromOA, WordPress.Security.EscapeOutput

declare( strict_types=1 );

if ( ! function_exists( '__' ) ) {
	/**
	 * Translation stand-in.
	 *
	 * @param string $text Text.
	 */
	function __( string $text ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * Translation and escaping stand-in.
	 *
	 * @param string $text Text.
	 */
	function esc_html__( string $text ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Escaping stand-in.
	 *
	 * @param string $text Text.
	 */
	function esc_html( string $text ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * Escaping stand-in.
	 *
	 * @param string $text Text.
	 */
	function esc_attr( string $text ): string {
		return $text;
	}
}

if ( ! function_exists( 'determine_locale' ) ) {
	/**
	 * Locale stand-in.
	 */
	function determine_locale(): string {
		return 'en_US';
	}
}
