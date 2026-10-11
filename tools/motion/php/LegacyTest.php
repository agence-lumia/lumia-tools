<?php
/**
 * Tests for the legacy names of the Motion module: the underline fallbacks.
 *
 * @package Lumia\Tools
 */

// Development-only test file: it is not plugin code. Assertions read as
// "actual === expected", so Yoda conditions would only make them harder to read.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.PHP.YodaConditions

declare( strict_types=1 );

use Lumia\Tools\Modules\Motion\Legacy;

/**
 * Declarations of the first rule of some CSS whose selector is exactly $selector, by property.
 *
 * @param string $css      CSS text.
 * @param string $selector Selector, as written.
 * @param string $media    Media query that wraps the rule, '' for none.
 * @return array<string, string>|null Null when there is no such rule.
 */
function lm_legacy_rule( string $css, string $selector, string $media = '' ): ?array {
	if ( '' !== $media ) {
		$start = strpos( $css, '@media ' . $media );
		if ( false === $start ) {
			return null;
		}
		$css = substr( $css, $start );
	}
	$at = strpos( $css, $selector . '{' );
	if ( false === $at ) {
		return null;
	}
	$body  = substr( $css, $at + strlen( $selector ) + 1 );
	$body  = substr( $body, 0, (int) strpos( $body, '}' ) );
	$rules = [];
	foreach ( array_filter( array_map( 'trim', explode( ';', $body ) ) ) as $declaration ) {
		$parts                      = explode( ':', $declaration, 2 );
		$rules[ trim( $parts[0] ) ] = trim( $parts[1] ?? '' );
	}

	return $rules;
}

return [
	'underline fallbacks sit on the drawing rules'   => static function (): void {
		$css   = Legacy::underline_css();
		$box   = lm_legacy_rule( $css, '.lm-underline,[data-lm~="underline"]' );
		$line  = lm_legacy_rule( $css, '.lm-underline::after,[data-lm~="underline"]::after' );
		$still = lm_legacy_rule( $css, '.lm-underline,[data-lm~="underline"]', '(prefers-reduced-motion: reduce)' );
		lm_assert( null !== $box && null !== $line && null !== $still, 'missing rule in: ' . $css );

		$v = static function ( string $key ): string {
			return 'var(' . Legacy::UNDERLINE_VARIABLES[ $key ]['current'] . ', var(' . Legacy::UNDERLINE_VARIABLES[ $key ]['legacy'] . ', ' . Legacy::UNDERLINE_DEFAULTS[ $key ] . '))';
		};
		lm_assert( ( $box['padding-bottom'] ?? '' ) === 'calc(' . $v( 'gap' ) . ' * 0.45)', 'padding-bottom: ' . ( $box['padding-bottom'] ?? 'none' ) );
		lm_assert( ( $line['bottom'] ?? '' ) === 'calc(-1 * ' . $v( 'gap' ) . ')', 'bottom: ' . ( $line['bottom'] ?? 'none' ) );
		lm_assert( ( $line['height'] ?? '' ) === $v( 'thickness' ), 'height: ' . ( $line['height'] ?? 'none' ) );
		lm_assert( ( $line['background-color'] ?? '' ) === $v( 'color' ), 'background-color: ' . ( $line['background-color'] ?? 'none' ) );
		lm_assert( ( $line['transition-duration'] ?? '' ) === $v( 'duration' ), 'transition-duration: ' . ( $line['transition-duration'] ?? 'none' ) );
		lm_assert( ( $still['padding-bottom'] ?? '' ) === $v( 'gap' ), 'reduced motion padding-bottom: ' . ( $still['padding-bottom'] ?? 'none' ) );
	},
	'underline fallbacks never set the lm variables' => static function (): void {
		// Setting --lm-underline-* on the element would hide a value inherited from a wrapper.
		lm_assert( 1 !== preg_match( '/--lm-underline-[a-z]+\s*:/', Legacy::underline_css() ), 'an --lm-underline-* variable is assigned: ' . Legacy::underline_css() );
	},
	'underline fallbacks keep the reference values'  => static function (): void {
		$css = Legacy::underline_css();
		foreach ( Legacy::UNDERLINE_VARIABLES as $key => $names ) {
			$fallback = 'var(' . $names['current'] . ', var(' . $names['legacy'] . ', ' . Legacy::UNDERLINE_DEFAULTS[ $key ] . '))';
			lm_assert( false !== strpos( $css, $fallback ), $key . ': no ' . $fallback );
		}
	},
	'underline fallbacks mirror the drawing of the stylesheet' => static function (): void {
		// Same values as motion.css when no variable is set: the stylesheet and the fallbacks must not drift.
		$sheet = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/front/css/motion.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file, not a URL.
		foreach ( [ 'calc(var(--lm-underline-gap, 0.12em) * 0.45)', 'calc(-1 * var(--lm-underline-gap, 0.12em))', 'var(--lm-underline-thickness, 2px)', 'var(--lm-underline-color, currentColor)', 'var(--lm-underline-duration, 0.4s)', 'padding-bottom: var(--lm-underline-gap, 0.12em)' ] as $needle ) {
			lm_assert( false !== strpos( $sheet, $needle ), 'motion.css no longer has ' . $needle . ': update Legacy::underline_css()' );
		}
	},
];
