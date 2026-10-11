<?php
/**
 * Tests for the load animations: the first-screen zone and the resolved inline values.
 *
 * @package Lumia\Tools
 */

// Development-only test file: it is not plugin code. Assertions read as
// "actual === expected", so Yoda conditions would only make them harder to read.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.PHP.YodaConditions, WordPress.WP.AlternativeFunctions

declare( strict_types=1 );

require_once __DIR__ . '/wp-stubs.php';

use Lumia\Tools\Modules\Motion\Effects;
use Lumia\Tools\Modules\Motion\LoadAnimation;
use Lumia\Tools\Modules\Motion\Marker;

/**
 * Module configuration as the sanitizer leaves it, with some keys replaced.
 *
 * @param array<string, mixed> $over Keys to replace.
 * @return array<string, mixed>
 */
function lm_load_config( array $over = [] ): array {
	return array_merge(
		[
			'effects'     => Effects::reference_values(),
			'tempo'       => 'normal',
			'ease'        => 'smooth',
			'ease_custom' => '',
		],
		$over
	);
}

/**
 * Declarations of a style attribute, by property.
 *
 * @param array<string, string> $attributes Attributes returned by LoadAnimation::attributes().
 * @return array<string, string>
 */
function lm_load_style( array $attributes ): array {
	$out = [];
	foreach ( explode( ';', $attributes['style'] ?? '' ) as $declaration ) {
		if ( '' === trim( $declaration ) ) {
			continue;
		}
		[ $property, $value ] = explode( ':', $declaration, 2 );
		$out[ $property ]     = $value;
	}

	return $out;
}

return [
	'the first content root and its descendants are in the zone' => static function (): void {
		LoadAnimation::reset();
		lm_assert(
			LoadAnimation::observe(
				[
					'id'     => 'hdr001',
					'parent' => 0,
				],
				'header'
			) === true,
			'header root'
		);
		lm_assert(
			LoadAnimation::observe(
				[
					'id'     => 'hdr002',
					'parent' => 'hdr001',
				],
				'header'
			) === true,
			'header child'
		);
		lm_assert(
			LoadAnimation::observe(
				[
					'id'     => 'sec001',
					'parent' => 0,
				],
				'content'
			) === true,
			'first root'
		);
		lm_assert(
			LoadAnimation::observe(
				[
					'id'     => 'con001',
					'parent' => 'sec001',
				],
				'content'
			) === true,
			'child'
		);
		lm_assert(
			LoadAnimation::observe(
				[
					'id'     => 'hd0001',
					'parent' => 'con001',
				],
				'content'
			) === true,
			'grandchild'
		);
		lm_assert(
			LoadAnimation::observe(
				[
					'id'     => 'sec002',
					'parent' => 0,
				],
				'content'
			) === false,
			'second root'
		);
		lm_assert(
			LoadAnimation::observe(
				[
					'id'     => 'con002',
					'parent' => 'sec002',
				],
				'content'
			) === false,
			'child of the second root'
		);
		lm_assert(
			LoadAnimation::observe(
				[
					'id'     => 'hd0002',
					'parent' => 'con002',
				],
				'content'
			) === false,
			'grandchild of the second root'
		);
		lm_assert(
			LoadAnimation::observe(
				[
					'id'     => 'ftr001',
					'parent' => 0,
				],
				'footer'
			) === false,
			'footer'
		);
		lm_assert(
			LoadAnimation::observe(
				[
					'id'     => 'pop001',
					'parent' => 0,
				],
				'popup'
			) === false,
			'popup'
		);
		// Observed again (a loop renders the same element several times): same answer.
		lm_assert(
			LoadAnimation::observe(
				[
					'id'     => 'sec001',
					'parent' => 0,
				],
				'content'
			) === true,
			'first root again'
		);
		lm_assert(
			LoadAnimation::observe(
				[
					'id'     => 'sec002',
					'parent' => 0,
				],
				'content'
			) === false,
			'second root again'
		);
	},
	'a template nested in the header never takes the first root' => static function (): void {
		LoadAnimation::reset();
		lm_assert(
			LoadAnimation::observe(
				[
					'id'     => 'hdr001',
					'parent' => 0,
				],
				'header',
				1,
				'header'
			) === true,
			'header root'
		);
		lm_assert(
			LoadAnimation::observe(
				[
					'id'     => 'hdrtpl',
					'parent' => 'hdr001',
				],
				'header',
				1,
				'header'
			) === true,
			'template element in the header'
		);
		// The section template of the Template element: area content, rendered inside the header render.
		lm_assert(
			LoadAnimation::observe(
				[
					'id'     => 'mega01',
					'parent' => 0,
				],
				'content',
				2,
				'header'
			) === true,
			'nested root counts as header'
		);
		lm_assert(
			LoadAnimation::observe(
				[
					'id'     => 'mega02',
					'parent' => 'mega01',
				],
				'content',
				2,
				'header'
			) === true,
			'nested child counts as header'
		);
		// The real content.
		lm_assert(
			LoadAnimation::observe(
				[
					'id'     => 'sec001',
					'parent' => 0,
				],
				'content',
				1,
				'content'
			) === true,
			'real first root'
		);
		lm_assert(
			LoadAnimation::observe(
				[
					'id'     => 'hd0001',
					'parent' => 'sec001',
				],
				'content',
				1,
				'content'
			) === true,
			'real first root child'
		);
		lm_assert(
			LoadAnimation::observe(
				[
					'id'     => 'sec002',
					'parent' => 0,
				],
				'content',
				1,
				'content'
			) === false,
			'second root'
		);
	},
	'a nested content render never takes the first root'  => static function (): void {
		LoadAnimation::reset();
		// A tree rendered inside another content render (reading time in a loop, Template element) first.
		lm_assert(
			LoadAnimation::observe(
				[
					'id'     => 'nest01',
					'parent' => 0,
				],
				'content',
				2,
				'content'
			) === false,
			'nested root'
		);
		lm_assert(
			LoadAnimation::observe(
				[
					'id'     => 'sec001',
					'parent' => 0,
				],
				'content',
				1,
				'content'
			) === true,
			'top-level first root'
		);
	},
	'a component child follows its instance'              => static function (): void {
		LoadAnimation::reset();
		lm_assert(
			LoadAnimation::observe(
				[
					'id'     => 'inst01',
					'parent' => 0,
					'cid'    => 'cmp001',
				],
				'content'
			) === true,
			'instance'
		);
		lm_assert(
			LoadAnimation::observe(
				[
					'id'              => 'cmpch1',
					'parent'          => 'cmproot',
					'parentComponent' => 'inst01',
				],
				'content'
			) === true,
			'component child'
		);
	},
	'slide-up with the default settings'                  => static function (): void {
		LoadAnimation::reset();
		$attributes = LoadAnimation::attributes( 'slide-up', [], lm_load_config() );
		lm_assert( ( $attributes['data-lm-load'] ?? null ) === 'slide-up', 'data-lm-load' );
		$style = lm_load_style( $attributes );
		lm_assert( $style['--lm-load-duration'] === '0.65s', 'duration ' . $style['--lm-load-duration'] );
		lm_assert( $style['--lm-load-delay'] === '0s', 'delay ' . $style['--lm-load-delay'] );
		lm_assert( $style['--lm-load-ease'] === 'var(--lm-curve-smooth)', 'ease ' . $style['--lm-load-ease'] );
		lm_assert( $style['--lm-load-safe-ease'] === 'var(--lm-curve-smooth)', 'safe ease' );
		lm_assert( $style['--lm-load-move-duration'] === 'calc(0.65s * var(--lm-settle-smooth))', 'move ' . $style['--lm-load-move-duration'] );
		lm_assert( $style['--lm-distance'] === '48px', 'distance ' . $style['--lm-distance'] );
		lm_assert( $style['--lm-blur'] === '0px', 'no blur for slide-up: ' . $style['--lm-blur'] );
		lm_assert( $style['--lm-scale'] === '1', 'no scale for slide-up: ' . $style['--lm-scale'] );
	},
	'the effect parameters come from the effect'          => static function (): void {
		LoadAnimation::reset();
		$style = lm_load_style( LoadAnimation::attributes( 'zoom-blur', [], lm_load_config() ) );
		lm_assert( $style['--lm-blur'] === '10px' && $style['--lm-scale'] === '0.86', 'zoom-blur ' . json_encode( $style ) );
		$style = lm_load_style(
			LoadAnimation::attributes(
				'blur-up',
				[
					'_lmDistance' => '30',
					'_lmBlur'     => '6',
				],
				lm_load_config()
			)
		);
		lm_assert( $style['--lm-distance'] === '30px' && $style['--lm-blur'] === '6px', 'typed values ' . json_encode( $style ) );
	},
	'the site tempo scales the duration'                  => static function (): void {
		LoadAnimation::reset();
		$style = lm_load_style( LoadAnimation::attributes( 'slide-up', [], lm_load_config( [ 'tempo' => 'slow' ] ) ) );
		lm_assert( $style['--lm-load-duration'] === '0.975s', 'slow ' . $style['--lm-load-duration'] );
		$style = lm_load_style( LoadAnimation::attributes( 'slide-up', [ '_lmTempo' => 'fast' ], lm_load_config( [ 'tempo' => 'slow' ] ) ) );
		lm_assert( $style['--lm-load-duration'] === '0.4875s', 'element tempo wins ' . $style['--lm-load-duration'] );
	},
	'the rank in the lot adds the stagger'                => static function (): void {
		LoadAnimation::reset();
		$config = lm_load_config();
		LoadAnimation::attributes( 'slide-up', [], $config );
		$second = lm_load_style( LoadAnimation::attributes( 'slide-up', [], $config ) );
		// A different signature has its own rank.
		$other = lm_load_style( LoadAnimation::attributes( 'slide-up', [ '_lmDistance' => '20' ], $config ) );
		$third = lm_load_style( LoadAnimation::attributes( 'slide-up', [], $config ) );
		lm_assert( $second['--lm-load-delay'] === '0.1s', 'second ' . $second['--lm-load-delay'] );
		lm_assert( $other['--lm-load-delay'] === '0s', 'other ' . $other['--lm-load-delay'] );
		lm_assert( $third['--lm-load-delay'] === '0.2s', 'third ' . $third['--lm-load-delay'] );
	},
	'delay plus rank, the stagger scaled by the tempo'    => static function (): void {
		LoadAnimation::reset();
		$config = lm_load_config( [ 'tempo' => 'very-slow' ] );
		LoadAnimation::attributes( 'fade', [ '_lmDelay' => '0.3' ], $config );
		$second = lm_load_style( LoadAnimation::attributes( 'fade', [ '_lmDelay' => '0.3' ], $config ) );
		// The delay is not scaled (as in the engine), the stagger 0.06 is: 0.3 + 1 x 0.12.
		lm_assert( $second['--lm-load-delay'] === '0.42s', 'delay ' . $second['--lm-load-delay'] );
	},
	'a bouncy curve stretches the move, the fade stays smooth' => static function (): void {
		LoadAnimation::reset();
		$style = lm_load_style( LoadAnimation::attributes( 'slide-up', [ '_lmEase' => 'bouncy' ], lm_load_config() ) );
		lm_assert( $style['--lm-load-ease'] === 'var(--lm-curve-bouncy)', 'ease ' . $style['--lm-load-ease'] );
		lm_assert( $style['--lm-load-move-duration'] === 'calc(0.65s * var(--lm-settle-bouncy))', 'move ' . $style['--lm-load-move-duration'] );
		lm_assert( $style['--lm-load-safe-ease'] === 'var(--lm-curve-smooth)', 'safe ' . $style['--lm-load-safe-ease'] );
		$style = lm_load_style( LoadAnimation::attributes( 'slide-up', [], lm_load_config( [ 'ease' => 'snappy' ] ) ) );
		lm_assert( $style['--lm-load-ease'] === 'var(--lm-curve-snappy)', 'site ease ' . $style['--lm-load-ease'] );
	},
	'linear and custom curves are used as they are'       => static function (): void {
		LoadAnimation::reset();
		$style = lm_load_style( LoadAnimation::attributes( 'slide-up', [ '_lmEase' => 'linear' ], lm_load_config() ) );
		lm_assert( $style['--lm-load-ease'] === 'linear' && $style['--lm-load-safe-ease'] === 'linear', 'linear ' . json_encode( $style ) );
		lm_assert( $style['--lm-load-move-duration'] === '0.65s', 'linear move ' . $style['--lm-load-move-duration'] );

		$curve = 'cubic-bezier(0.2, 0.8, 0.2, 1)';
		$style = lm_load_style(
			LoadAnimation::attributes(
				'slide-up',
				[
					'_lmEase'       => 'custom',
					'_lmEaseCustom' => $curve,
				],
				lm_load_config()
			)
		);
		lm_assert( $style['--lm-load-ease'] === $curve && $style['--lm-load-safe-ease'] === $curve, 'custom ' . json_encode( $style ) );
		lm_assert( $style['--lm-load-move-duration'] === '0.65s', 'custom move' );

		$style = lm_load_style(
			LoadAnimation::attributes(
				'slide-up',
				[],
				lm_load_config(
					[
						'ease'        => 'custom',
						'ease_custom' => $curve,
					]
				)
			)
		);
		lm_assert( $style['--lm-load-ease'] === $curve, 'site custom curve ' . $style['--lm-load-ease'] );

		// An invalid curve falls back to the site curve, as in the engine.
		$style = lm_load_style(
			LoadAnimation::attributes(
				'slide-up',
				[
					'_lmEase'       => 'custom',
					'_lmEaseCustom' => 'cubic-bezier(2,0,0,1)',
				],
				lm_load_config( [ 'ease' => 'snappy' ] )
			)
		);
		lm_assert( $style['--lm-load-ease'] === 'var(--lm-curve-snappy)', 'invalid custom ' . $style['--lm-load-ease'] );
		$style = lm_load_style( LoadAnimation::attributes( 'slide-up', [ '_lmEase' => 'lumia' ], lm_load_config() ) );
		lm_assert( $style['--lm-load-ease'] === 'var(--lm-curve-smooth)', 'legacy lumia ' . $style['--lm-load-ease'] );
	},
	'a custom tempo takes the typed duration as it is'    => static function (): void {
		LoadAnimation::reset();
		$settings = [
			'_lmTempo'    => 'custom',
			'_lmDuration' => '2',
		];
		LoadAnimation::attributes( 'slide-up', $settings, lm_load_config( [ 'tempo' => 'slow' ] ) );
		$style = lm_load_style( LoadAnimation::attributes( 'slide-up', $settings, lm_load_config( [ 'tempo' => 'slow' ] ) ) );
		lm_assert( $style['--lm-load-duration'] === '2s', 'duration ' . $style['--lm-load-duration'] );
		// Custom: the stagger is not scaled either (engine rule).
		lm_assert( $style['--lm-load-delay'] === '0.1s', 'stagger ' . $style['--lm-load-delay'] );
		// Legacy "exact" is read as custom; a typed duration in ms is converted.
		$style = lm_load_style(
			LoadAnimation::attributes(
				'fade',
				[
					'_lmTempo'    => 'exact',
					'_lmDuration' => '1500ms',
				],
				lm_load_config()
			)
		);
		lm_assert( $style['--lm-load-duration'] === '1.5s', 'exact ' . $style['--lm-load-duration'] );
		// Not custom: a typed duration is ignored.
		$style = lm_load_style( LoadAnimation::attributes( 'fade', [ '_lmDuration' => '3' ], lm_load_config() ) );
		lm_assert( $style['--lm-load-duration'] === '0.45s', 'typed ignored ' . $style['--lm-load-duration'] );
	},
	'invalid typed values fall back to the module values' => static function (): void {
		LoadAnimation::reset();
		$style = lm_load_style(
			LoadAnimation::attributes(
				'slide-up',
				[
					'_lmDistance' => '30;color:red',
					'_lmDelay'    => '-1',
					'_lmTempo'    => 'warp',
				],
				lm_load_config()
			)
		);
		lm_assert( $style['--lm-distance'] === '48px', 'distance ' . $style['--lm-distance'] );
		lm_assert( $style['--lm-load-delay'] === '0s', 'delay ' . $style['--lm-load-delay'] );
		lm_assert( $style['--lm-load-duration'] === '0.65s', 'tempo ' . $style['--lm-load-duration'] );
	},
	'the base settings: element over its classes, in order' => static function (): void {
		$merged = LoadAnimation::merged_settings(
			[
				'_lmDelay'                    => '0.2',
				'_lmDistance:mobile_portrait' => '10',
			],
			[
				[
					'name'     => 'first',
					'settings' => [
						'_lmDelay'    => '0.5',
						'_lmDistance' => '30',
					],
				],
				[
					'name'     => 'second',
					'settings' => [
						'_lmDistance' => '60',
						'_lmBlur'     => '4',
					],
				],
			]
		);
		lm_assert( ( $merged['_lmDelay'] ?? null ) === '0.2', 'element wins' );
		lm_assert( ( $merged['_lmDistance'] ?? null ) === '30', 'first class wins' );
		lm_assert( ( $merged['_lmBlur'] ?? null ) === '4', 'second class fills' );
		lm_assert( ! isset( $merged['_lmDistance:mobile_portrait'] ), 'breakpoint values left out' );
	},
	'the load setting and the effect of an element'       => static function (): void {
		lm_assert( LoadAnimation::mode( [], [] ) === 'auto', 'default auto' );
		lm_assert( LoadAnimation::mode( [ '_lmLoad' => 'never' ], [] ) === 'never', 'element' );
		lm_assert(
			LoadAnimation::mode(
				[ '_lmLoad:mobile_portrait' => 'never' ],
				[
					[
						'name'     => 'c',
						'settings' => [ '_lmLoad' => 'always' ],
					],
				]
			) === 'always',
			'class, breakpoint ignored'
		);
		lm_assert( LoadAnimation::mode( [ '_lmLoad' => 'sometimes' ], [] ) === 'auto', 'unknown value' );

		lm_assert( LoadAnimation::effect( [ '_lmEffect' => 'zoom' ], [], true ) === 'zoom', 'element setting' );
		lm_assert(
			LoadAnimation::effect(
				[],
				[
					[
						'name'     => 'lm-fade',
						'settings' => [],
					],
				],
				true
			) === 'fade',
			'class name'
		);
		lm_assert(
			LoadAnimation::effect(
				[],
				[
					[
						'name'     => 'lm-fade',
						'settings' => [ '_lmEffect' => 'blur' ],
					],
				],
				true
			) === 'blur',
			'class setting over class name'
		);
		lm_assert( LoadAnimation::effect( [ '_cssClasses' => 'hero lm-blur-up' ], [], true ) === 'blur-up', 'typed class' );
		lm_assert( LoadAnimation::effect( [ '_lmEffect:mobile_portrait' => 'fade' ], [], true ) === null, 'breakpoint only' );
		lm_assert(
			LoadAnimation::effect(
				[ '_lmEffect' => 'none' ],
				[
					[
						'name'     => 'lm-fade',
						'settings' => [],
					],
				],
				true
			) === 'none',
			'none on the element'
		);
	},
	'marker: never in the zone, always outside'           => static function (): void {
		LoadAnimation::reset();
		LoadAnimation::configure( lm_load_config() );
		$hero = [
			'id'     => 'sec001',
			'parent' => 0,
		];
		lm_assert( Marker::load_attributes( $hero, [ '_lmEffect' => 'fade' ], [], 'content', true ) !== [], 'auto in the zone' );

		$never = [
			'id'     => 'hd0001',
			'parent' => 'sec001',
		];
		$got   = Marker::load_attributes(
			$never,
			[
				'_lmEffect' => 'fade',
				'_lmLoad'   => 'never',
			],
			[],
			'content',
			true
		);
		lm_assert( $got === [], 'never in the zone: ' . json_encode( $got ) );

		Marker::load_attributes(
			[
				'id'     => 'sec002',
				'parent' => 0,
			],
			[],
			[],
			'content',
			true
		);
		$auto = [
			'id'     => 'hd0002',
			'parent' => 'sec002',
		];
		lm_assert( Marker::load_attributes( $auto, [ '_lmEffect' => 'fade' ], [], 'content', true ) === [], 'auto outside the zone' );

		$always = [
			'id'     => 'hd0003',
			'parent' => 'sec002',
		];
		$got    = Marker::load_attributes(
			$always,
			[
				'_lmEffect' => 'fade',
				'_lmLoad'   => 'always',
			],
			[],
			'content',
			true
		);
		lm_assert( ( $got['data-lm-load'] ?? null ) === 'fade', 'always outside the zone: ' . json_encode( $got ) );

		// Only the enter family plays in CSS.
		$text = [
			'id'     => 'hd0004',
			'parent' => 'sec001',
		];
		lm_assert( Marker::load_attributes( $text, [ '_lmEffect' => 'lines' ], [], 'content', true ) === [], 'text effect' );
	},
];
