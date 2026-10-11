<?php
/**
 * Tests for the effect catalog and the legacy aliases.
 *
 * @package Lumia\Tools
 */

// Development-only test file: it is not plugin code. Assertions read as
// "actual === expected", so Yoda conditions would only make them harder to read.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.PHP.YodaConditions

declare( strict_types=1 );

use Lumia\Tools\Modules\Motion\Effects;
use Lumia\Tools\Modules\Motion\Legacy;

// PHPCompatibility reads the "family" method name as the removed "fam" extension (false positive).
$lm_family = static function ( string $slug ): ?string {
	return Effects::family( $slug ); // phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.famRemoved
};

return [
	'catalog has the ten effects'                    => static function (): void {
		lm_assert(
			array_keys( Effects::catalog() ) === [
				'fade',
				'reveal',
				'blur-in',
				'scale-in',
				'scale-blur-in',
				'text-lines',
				'text-words',
				'text-chars',
				'counter',
				'underline',
			],
			'unexpected catalog keys'
		);
	},
	'every alias targets a catalog slug'             => static function (): void {
		$catalog = Effects::catalog();
		lm_assert( count( Legacy::CLASS_ALIASES ) === 10, 'expected ten aliases' );
		foreach ( Legacy::CLASS_ALIASES as $alias => $slug ) {
			lm_assert( isset( $catalog[ $slug ] ), "alias {$alias} targets unknown slug {$slug}" );
		}
		lm_assert( count( array_unique( Legacy::CLASS_ALIASES ) ) === 10, 'two aliases share a slug' );
	},
	'slug_for_class matches the new name'            => static function (): void {
		lm_assert( Effects::slug_for_class( 'lm-fade', false ) === 'fade', 'lm-fade' );
		lm_assert( Effects::slug_for_class( 'lm-scale-blur-in', false ) === 'scale-blur-in', 'lm-scale-blur-in' );
		lm_assert( Effects::slug_for_class( 'lm-unknown', false ) === null, 'lm-unknown' );
	},
	'slug_for_class ignores legacy names by default' => static function (): void {
		lm_assert( Effects::slug_for_class( 'sk-fade', false ) === null, 'sk-fade without legacy' );
	},
	'slug_for_class resolves legacy names on demand' => static function (): void {
		lm_assert( Effects::slug_for_class( 'sk-fade', true ) === 'fade', 'sk-fade with legacy' );
		lm_assert( Effects::slug_for_class( 'sk-entry-blur', true ) === 'blur-in', 'sk-entry-blur with legacy' );
		lm_assert( Effects::slug_for_class( 'lm-fade', true ) === 'fade', 'lm-fade with legacy' );
		lm_assert( Effects::slug_for_class( 'sk-nope', true ) === null, 'sk-nope with legacy' );
	},
	'families'                                       => static function () use ( $lm_family ): void {
		lm_assert( $lm_family( 'text-words' ) === 'text', 'text-words' );
		lm_assert( $lm_family( 'fade' ) === 'enter', 'fade' );
		lm_assert( $lm_family( 'scale-blur-in' ) === 'enter', 'scale-blur-in' );
		lm_assert( $lm_family( 'counter' ) === 'counter', 'counter' );
		lm_assert( $lm_family( 'underline' ) === 'underline', 'underline' );
		lm_assert( $lm_family( 'nope' ) === null, 'nope' );
	},
	'scrub effects are the two highlight texts'      => static function (): void {
		$scrubbing = [];
		foreach ( Effects::catalog() as $slug => $def ) {
			if ( $def['scrub'] ) {
				$scrubbing[] = $slug;
			}
		}
		lm_assert( $scrubbing === [ 'text-words', 'text-chars' ], 'scrub list: ' . implode( ',', $scrubbing ) );
		lm_assert( Effects::catalog()['text-chars']['scrub'] === true, 'text-chars scrub' );
	},
	'catalog classes and params'                     => static function (): void {
		$catalog = Effects::catalog();
		lm_assert( $catalog['blur-in']['class'] === 'lm-blur-in', 'blur-in class' );
		lm_assert( $catalog['blur-in']['params'] === [ 'distance', 'blur' ], 'blur-in params' );
		lm_assert( $catalog['scale-blur-in']['params'] === [ 'scale', 'blur' ], 'scale-blur-in params' );
		lm_assert( $catalog['counter']['params'] === [ 'start_value' ], 'counter params' );
		lm_assert( $catalog['underline']['params'] === [], 'underline params' );
	},
	'new_class_name maps legacy to new'              => static function (): void {
		lm_assert( Legacy::new_class_name( 'sk-entry-blur' ) === 'lm-blur-in', 'sk-entry-blur' );
		lm_assert( Legacy::new_class_name( 'sk-text-highlight-words' ) === 'lm-text-words', 'sk-text-highlight-words' );
		lm_assert( Legacy::new_class_name( 'sk-hover-underline' ) === 'lm-underline', 'sk-hover-underline' );
		lm_assert( Legacy::new_class_name( 'lm-fade' ) === null, 'lm-fade is not legacy' );
	},
	'reference values'                               => static function (): void {
		$ref = Effects::reference_values();
		lm_assert( $ref['reveal']['distance'] === 48.0, 'reveal distance' );
		lm_assert( $ref['text-words']['end_at'] === '-120%', 'text-words end_at' );
		lm_assert( $ref['fade']['start_edge'] === 'top' && $ref['fade']['start_at'] === '98%', 'fade start' );
		lm_assert( $ref['fade']['delay'] === 0.0, 'fade delay' );
		lm_assert( $ref['fade']['duration'] === 0.45 && $ref['fade']['stagger'] === 0.06, 'fade timing' );
		lm_assert( $ref['blur-in']['blur'] === 12.0 && $ref['blur-in']['distance'] === 20.0, 'blur-in' );
		lm_assert( $ref['scale-in']['scale'] === 0.9, 'scale-in scale' );
		lm_assert( $ref['scale-blur-in']['scale'] === 0.86 && $ref['scale-blur-in']['blur'] === 10.0, 'scale-blur-in' );
		lm_assert( $ref['text-lines']['distance'] === 120.0 && $ref['text-lines']['duration'] === 0.95, 'text-lines' );
		lm_assert( $ref['text-words']['stagger'] === 0.25 && $ref['text-words']['from_opacity'] === 0.08, 'text-words' );
		lm_assert( $ref['text-chars']['stagger'] === 0.1 && $ref['text-chars']['from_opacity'] === 0.08, 'text-chars' );
		lm_assert( ! array_key_exists( 'duration', $ref['text-words'] ), 'scrub has no duration' );
		lm_assert( $ref['text-chars']['end_edge'] === 'top', 'text-chars end_edge' );
		lm_assert( ! array_key_exists( 'end_at', $ref['fade'] ), 'non-scrub has no end_at' );
		lm_assert( $ref['counter']['duration'] === 1.35 && $ref['counter']['start_value'] === 0.0, 'counter' );
		lm_assert( $ref['underline'] === [], 'underline is empty' );
		lm_assert( array_keys( $ref ) === array_keys( Effects::catalog() ), 'same keys as the catalog' );
	},
	'every catalog param has a reference value'      => static function (): void {
		$ref = Effects::reference_values();
		foreach ( Effects::catalog() as $slug => $def ) {
			foreach ( $def['params'] as $param ) {
				lm_assert( array_key_exists( $param, $ref[ $slug ] ), "{$slug} lacks reference for {$param}" );
			}
		}
	},
	'engine variables'                               => static function (): void {
		lm_assert( count( Effects::ENGINE_VARIABLES ) === 18, 'expected 18 engine variables' );
		lm_assert( count( array_unique( Effects::ENGINE_VARIABLES ) ) === 18, 'duplicate variable' );
		foreach ( Effects::ENGINE_VARIABLES as $name ) {
			lm_assert( strpos( $name, '--' ) !== 0, "{$name} must not carry the leading dashes" );
			lm_assert( strpos( $name, 'underline' ) === false, "{$name} is an underline variable" );
		}
		lm_assert( in_array( 'lm-ease-custom', Effects::ENGINE_VARIABLES, true ), 'lm-ease-custom' );
	},
	'constants'                                      => static function (): void {
		lm_assert(
			Effects::TEMPOS === [
				'fast'   => 0.7,
				'normal' => 1.0,
				'slow'   => 1.4,
			],
			'tempos'
		);
		lm_assert( Effects::TEMPO_KEYWORDS === [ 'fast', 'normal', 'slow', 'exact' ], 'tempo keywords' );
		lm_assert( Effects::EDGES === [ 'top', 'center', 'bottom' ], 'edges' );
		lm_assert( Effects::EASES === [ 'lumia', 'smooth', 'snappy', 'bouncy', 'linear', 'custom' ], 'eases' );
	},
	'underline css'                                  => static function (): void {
		$css = Legacy::underline_css();
		lm_assert( strpos( $css, '.sk-hover-underline' ) === 0, 'selector' );
		lm_assert( strpos( $css, '--lm-underline-thickness: var(--sk-hover-underline-thickness, 2px)' ) !== false, 'thickness' );
		lm_assert( strpos( $css, '--lm-underline-gap: var(--sk-hover-underline-gap, 0.12em)' ) !== false, 'gap' );
		lm_assert( strpos( $css, '--lm-underline-color: var(--sk-hover-underline-color, currentColor)' ) !== false, 'color' );
		lm_assert( strpos( $css, '--lm-underline-duration: var(--sk-hover-underline-duration, 0.4s)' ) !== false, 'duration' );
		lm_assert( Legacy::ENGINE_GLOBAL === 'StudioKyneMotion' && Legacy::SNIPPET_NAME === 'SK Motion', 'legacy names' );
	},
];
