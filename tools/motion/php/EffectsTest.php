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

return [
	'catalog has the eleven effects'                 => static function (): void {
		lm_assert(
			array_keys( Effects::catalog() ) === [
				'fade',
				'slide-up',
				'blur',
				'blur-up',
				'zoom',
				'zoom-blur',
				'lines',
				'words',
				'letters',
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
		lm_assert( Effects::slug_for_class( 'lm-zoom-blur', false ) === 'zoom-blur', 'lm-zoom-blur' );
		lm_assert( Effects::slug_for_class( 'lm-unknown', false ) === null, 'lm-unknown' );
	},
	'slug_for_class ignores legacy names by default' => static function (): void {
		lm_assert( Effects::slug_for_class( 'sk-fade', false ) === null, 'sk-fade without legacy' );
	},
	'slug_for_class resolves legacy names on demand' => static function (): void {
		lm_assert( Effects::slug_for_class( 'sk-fade', true ) === 'fade', 'sk-fade with legacy' );
		lm_assert( Effects::slug_for_class( 'sk-entry-blur', true ) === 'blur-up', 'sk-entry-blur with legacy' );
		lm_assert( Effects::slug_for_class( 'lm-fade', true ) === 'fade', 'lm-fade with legacy' );
		lm_assert( Effects::slug_for_class( 'sk-nope', true ) === null, 'sk-nope with legacy' );
	},
	'families'                                       => static function (): void {
		lm_assert( Effects::family( 'words' ) === 'text', 'words' );
		lm_assert( Effects::family( 'fade' ) === 'enter', 'fade' );
		lm_assert( Effects::family( 'zoom-blur' ) === 'enter', 'zoom-blur' );
		lm_assert( Effects::family( 'counter' ) === 'counter', 'counter' );
		lm_assert( Effects::family( 'underline' ) === 'underline', 'underline' );
		lm_assert( Effects::family( 'nope' ) === null, 'nope' );
	},
	'scrub effects are the two highlight texts'      => static function (): void {
		$scrubbing = [];
		foreach ( Effects::catalog() as $slug => $def ) {
			if ( $def['scrub'] ) {
				$scrubbing[] = $slug;
			}
		}
		lm_assert( $scrubbing === [ 'words', 'letters' ], 'scrub list: ' . implode( ',', $scrubbing ) );
		lm_assert( Effects::catalog()['letters']['scrub'] === true, 'letters scrub' );
	},
	'catalog classes and params'                     => static function (): void {
		$catalog = Effects::catalog();
		lm_assert( $catalog['blur']['class'] === 'lm-blur' && $catalog['blur']['family'] === Effects::FAMILY_ENTER && false === $catalog['blur']['scrub'], 'blur entry' );
		lm_assert( $catalog['blur']['params'] === [ 'blur' ], 'blur params' );
		lm_assert( $catalog['blur-up']['class'] === 'lm-blur-up', 'blur-up class' );
		lm_assert( $catalog['blur-up']['params'] === [ 'distance', 'blur' ], 'blur-up params' );
		lm_assert( $catalog['zoom-blur']['params'] === [ 'scale', 'blur' ], 'zoom-blur params' );
		lm_assert( $catalog['counter']['params'] === [ 'start_value' ], 'counter params' );
		lm_assert( $catalog['underline']['params'] === [], 'underline params' );
	},
	'new_class_name maps legacy to new'              => static function (): void {
		lm_assert( Legacy::new_class_name( 'sk-entry-blur' ) === 'lm-blur-up', 'sk-entry-blur' );
		lm_assert( Legacy::new_class_name( 'sk-text-highlight-words' ) === 'lm-words', 'sk-text-highlight-words' );
		lm_assert( Legacy::new_class_name( 'sk-hover-underline' ) === 'lm-underline', 'sk-hover-underline' );
		lm_assert( Legacy::new_class_name( 'lm-fade' ) === null, 'lm-fade is not legacy' );
	},
	'reference values'                               => static function (): void {
		$ref = Effects::reference_values();
		lm_assert( $ref['slide-up']['distance'] === 48.0, 'slide-up distance' );
		lm_assert( $ref['words']['end_at'] === '-120%', 'words end_at' );
		lm_assert( $ref['fade']['start_edge'] === 'top' && $ref['fade']['start_at'] === '98%', 'fade start' );
		lm_assert( $ref['fade']['delay'] === 0.0, 'fade delay' );
		lm_assert( $ref['fade']['duration'] === 0.45 && $ref['fade']['stagger'] === 0.06, 'fade timing' );
		lm_assert(
			$ref['blur'] === [
				'start_edge' => 'top',
				'start_at'   => '98%',
				'delay'      => 0.0,
				'duration'   => 0.8,
				'stagger'    => 0.08,
				'blur'       => 12.0,
			],
			'blur reference'
		);
		lm_assert( $ref['blur-up']['blur'] === 12.0 && $ref['blur-up']['distance'] === 20.0, 'blur-up' );
		lm_assert( $ref['zoom']['scale'] === 0.9, 'zoom scale' );
		lm_assert( $ref['zoom-blur']['scale'] === 0.86 && $ref['zoom-blur']['blur'] === 10.0, 'zoom-blur' );
		lm_assert( $ref['lines']['distance'] === 120.0 && $ref['lines']['duration'] === 0.95, 'lines' );
		lm_assert( $ref['words']['stagger'] === 0.25 && $ref['words']['from_opacity'] === 0.08, 'words' );
		lm_assert( $ref['letters']['stagger'] === 0.1 && $ref['letters']['from_opacity'] === 0.08, 'letters' );
		lm_assert( ! array_key_exists( 'duration', $ref['words'] ), 'scrub has no duration' );
		lm_assert( $ref['letters']['end_edge'] === 'top', 'letters end_edge' );
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
			lm_assert( strpos( $name, 'lm-' ) === 0, "{$name} must start with lm-" );
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
	'underline constants share their keys'           => static function (): void {
		lm_assert(
			array_keys( Legacy::UNDERLINE_VARIABLES ) === array_keys( Legacy::UNDERLINE_DEFAULTS ),
			'UNDERLINE_VARIABLES and UNDERLINE_DEFAULTS keys differ'
		);
	},
	'legacy engine and snippet names'                => static function (): void {
		// The underline CSS is tested in LegacyTest.php.
		lm_assert( Legacy::ENGINE_GLOBAL === 'StudioKyneMotion' && Legacy::SNIPPET_NAME === 'SK Motion', 'legacy names' );
	},
];
