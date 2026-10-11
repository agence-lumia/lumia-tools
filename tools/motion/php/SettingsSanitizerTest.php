<?php
/**
 * Tests for the Motion settings sanitizer.
 *
 * @package Lumia\Tools
 */

// Development-only test file: it is not plugin code. Assertions read as
// "actual === expected", so Yoda conditions would only make them harder to read.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.PHP.YodaConditions

declare( strict_types=1 );

use Lumia\Tools\Modules\Motion\Effects;
use Lumia\Tools\Modules\Motion\SettingsSanitizer;

/**
 * Factory defaults, as Module::get_defaults() returns them.
 *
 * @return array<string, mixed>
 */
$lm_defaults = static function (): array {
	return [
		'effects'         => Effects::reference_values(),
		'tempo'           => 'normal',
		'ease'            => 'lumia',
		'ease_custom'     => '',
		'lenis'           => [
			'enabled' => true,
			'lerp'    => 0.1,
		],
		'legacy_classes'  => true,
		'builder_preview' => 'replay',
	];
};

/**
 * Sanitizes an input holding one effect override on top of nothing else.
 *
 * @param array<string, mixed> $effects Effect overrides, keyed by slug.
 * @param callable             $defaults Factory defaults provider.
 * @return array<string, mixed>
 */
$lm_effects = static function ( array $effects, callable $defaults ): array {
	$result = SettingsSanitizer::sanitize( [ 'effects' => $effects ], $defaults() );
	return $result['effects'];
};

return [
	'duration is clamped to 0..10'                  => static function () use ( $lm_defaults, $lm_effects ): void {
		$high = $lm_effects( [ 'reveal' => [ 'duration' => '99' ] ], $lm_defaults );
		$low  = $lm_effects( [ 'reveal' => [ 'duration' => '-3' ] ], $lm_defaults );
		lm_assert( 10.0 === $high['reveal']['duration'], 'duration 99 must become 10' );
		lm_assert( 0.0 === $low['reveal']['duration'], 'duration -3 must become 0' );
	},
	'delay is clamped to 0..10'                     => static function () use ( $lm_defaults, $lm_effects ): void {
		$high = $lm_effects( [ 'fade' => [ 'delay' => '11' ] ], $lm_defaults );
		$low  = $lm_effects( [ 'fade' => [ 'delay' => '-1' ] ], $lm_defaults );
		lm_assert( 10.0 === $high['fade']['delay'], 'delay 11 must become 10' );
		lm_assert( 0.0 === $low['fade']['delay'], 'delay -1 must become 0' );
	},
	'stagger is clamped to 0..2'                    => static function () use ( $lm_defaults, $lm_effects ): void {
		$high = $lm_effects( [ 'fade' => [ 'stagger' => '5' ] ], $lm_defaults );
		$low  = $lm_effects( [ 'fade' => [ 'stagger' => '-5' ] ], $lm_defaults );
		lm_assert( 2.0 === $high['fade']['stagger'], 'stagger 5 must become 2' );
		lm_assert( 0.0 === $low['fade']['stagger'], 'stagger -5 must become 0' );
	},
	'distance is clamped to -500..500'              => static function () use ( $lm_defaults, $lm_effects ): void {
		$high = $lm_effects( [ 'reveal' => [ 'distance' => '900' ] ], $lm_defaults );
		$low  = $lm_effects( [ 'reveal' => [ 'distance' => '-900' ] ], $lm_defaults );
		$kept = $lm_effects( [ 'reveal' => [ 'distance' => '-20' ] ], $lm_defaults );
		lm_assert( 500.0 === $high['reveal']['distance'], 'distance 900 must become 500' );
		lm_assert( -500.0 === $low['reveal']['distance'], 'distance -900 must become -500' );
		lm_assert( -20.0 === $kept['reveal']['distance'], 'a negative distance in range is kept' );
	},
	'blur is clamped to 0..100'                     => static function () use ( $lm_defaults, $lm_effects ): void {
		$high = $lm_effects( [ 'blur-in' => [ 'blur' => '500' ] ], $lm_defaults );
		$low  = $lm_effects( [ 'blur-in' => [ 'blur' => '-1' ] ], $lm_defaults );
		lm_assert( 100.0 === $high['blur-in']['blur'], 'blur 500 must become 100' );
		lm_assert( 0.0 === $low['blur-in']['blur'], 'blur -1 must become 0' );
	},
	'scale is clamped to 0..3'                      => static function () use ( $lm_defaults, $lm_effects ): void {
		$high = $lm_effects( [ 'scale-in' => [ 'scale' => '8' ] ], $lm_defaults );
		$low  = $lm_effects( [ 'scale-in' => [ 'scale' => '-8' ] ], $lm_defaults );
		lm_assert( 3.0 === $high['scale-in']['scale'], 'scale 8 must become 3' );
		lm_assert( 0.0 === $low['scale-in']['scale'], 'scale -8 must become 0' );
	},
	'from_opacity is clamped to 0..1'               => static function () use ( $lm_defaults, $lm_effects ): void {
		$high = $lm_effects( [ 'text-words' => [ 'from_opacity' => '4' ] ], $lm_defaults );
		$low  = $lm_effects( [ 'text-chars' => [ 'from_opacity' => '-4' ] ], $lm_defaults );
		lm_assert( 1.0 === $high['text-words']['from_opacity'], 'from_opacity 4 must become 1' );
		lm_assert( 0.0 === $low['text-chars']['from_opacity'], 'from_opacity -4 must become 0' );
	},
	'start_value is clamped to +-1e9'               => static function () use ( $lm_defaults, $lm_effects ): void {
		$high = $lm_effects( [ 'counter' => [ 'start_value' => '5e12' ] ], $lm_defaults );
		$low  = $lm_effects( [ 'counter' => [ 'start_value' => '-5e12' ] ], $lm_defaults );
		lm_assert( 1e9 === $high['counter']['start_value'], 'start_value 5e12 must become 1e9' );
		lm_assert( -1e9 === $low['counter']['start_value'], 'start_value -5e12 must become -1e9' );
	},
	'lerp is clamped to 0.01..1'                    => static function () use ( $lm_defaults ): void {
		$high = SettingsSanitizer::sanitize( [ 'lenis' => [ 'lerp' => '7' ] ], $lm_defaults() );
		$low  = SettingsSanitizer::sanitize( [ 'lenis' => [ 'lerp' => '0' ] ], $lm_defaults() );
		lm_assert( 1.0 === $high['lenis']['lerp'], 'lerp 7 must become 1' );
		lm_assert( 0.01 === $low['lenis']['lerp'], 'lerp 0 must become 0.01' );
	},
	'a non numeric value falls back to the default' => static function () use ( $lm_defaults, $lm_effects ): void {
		$effects = $lm_effects(
			[
				'reveal' => [
					'duration' => 'abc',
					'delay'    => '',
					'stagger'  => 'NaN',
					'distance' => 'INF',
				],
			],
			$lm_defaults
		);
		$lenis   = SettingsSanitizer::sanitize( [ 'lenis' => [ 'lerp' => 'fast' ] ], $lm_defaults() );
		lm_assert( 0.65 === $effects['reveal']['duration'], 'duration abc must keep the reference value' );
		lm_assert( 0.0 === $effects['reveal']['delay'], 'an empty delay must keep the reference value' );
		lm_assert( 0.1 === $effects['reveal']['stagger'], 'stagger NaN must keep the reference value' );
		lm_assert( 48.0 === $effects['reveal']['distance'], 'distance INF must keep the reference value' );
		lm_assert( 0.1 === $lenis['lenis']['lerp'], 'lerp fast must keep the default' );
	},
	'a non scalar value falls back to the default'  => static function () use ( $lm_defaults, $lm_effects ): void {
		$effects = $lm_effects( [ 'reveal' => [ 'duration' => [ '1' ] ] ], $lm_defaults );
		lm_assert( 0.65 === $effects['reveal']['duration'], 'an array value must keep the reference value' );
	},
	'start_edge accepts the three edges only'       => static function () use ( $lm_defaults, $lm_effects ): void {
		$bad  = $lm_effects( [ 'fade' => [ 'start_edge' => 'middle' ] ], $lm_defaults );
		$good = $lm_effects( [ 'fade' => [ 'start_edge' => 'bottom' ] ], $lm_defaults );
		lm_assert( 'top' === $bad['fade']['start_edge'], 'middle must become top' );
		lm_assert( 'bottom' === $good['fade']['start_edge'], 'bottom must be kept' );
	},
	'end_edge accepts the three edges only'         => static function () use ( $lm_defaults, $lm_effects ): void {
		$bad  = $lm_effects( [ 'text-words' => [ 'end_edge' => 'left' ] ], $lm_defaults );
		$good = $lm_effects( [ 'text-words' => [ 'end_edge' => 'center' ] ], $lm_defaults );
		lm_assert( 'top' === $bad['text-words']['end_edge'], 'left must become top' );
		lm_assert( 'center' === $good['text-words']['end_edge'], 'center must be kept' );
	},
	'start_at needs a percentage'                   => static function () use ( $lm_defaults, $lm_effects ): void {
		$bare    = $lm_effects( [ 'fade' => [ 'start_at' => '90' ] ], $lm_defaults );
		$percent = $lm_effects( [ 'fade' => [ 'start_at' => '90%' ] ], $lm_defaults );
		$code    = $lm_effects( [ 'fade' => [ 'start_at' => '90%;color:red' ] ], $lm_defaults );
		lm_assert( '98%' === $bare['fade']['start_at'], '90 must become the default 98%' );
		lm_assert( '90%' === $percent['fade']['start_at'], '90% must be kept' );
		lm_assert( '98%' === $code['fade']['start_at'], 'a value with extra characters must be refused' );
	},
	'end_at needs a percentage and may be negative' => static function () use ( $lm_defaults, $lm_effects ): void {
		$bare     = $lm_effects( [ 'text-words' => [ 'end_at' => '20' ] ], $lm_defaults );
		$negative = $lm_effects( [ 'text-words' => [ 'end_at' => '-35.5%' ] ], $lm_defaults );
		lm_assert( '-120%' === $bare['text-words']['end_at'], '20 must become the default -120%' );
		lm_assert( '-35.5%' === $negative['text-words']['end_at'], '-35.5% must be kept' );
	},
	'tempo accepts fast, normal and slow'           => static function () use ( $lm_defaults ): void {
		foreach ( [ 'fast', 'normal', 'slow' ] as $tempo ) {
			$result = SettingsSanitizer::sanitize( [ 'tempo' => $tempo ], $lm_defaults() );
			lm_assert( $tempo === $result['tempo'], "{$tempo} must be kept" );
		}
	},
	'exact tempo is refused at the global level'    => static function () use ( $lm_defaults ): void {
		$exact = SettingsSanitizer::sanitize( [ 'tempo' => 'exact' ], $lm_defaults() );
		$junk  = SettingsSanitizer::sanitize( [ 'tempo' => 'turbo' ], $lm_defaults() );
		lm_assert( 'normal' === $exact['tempo'], 'exact must become normal' );
		lm_assert( 'normal' === $junk['tempo'], 'an unknown tempo must become normal' );
	},
	'ease keywords are kept, others fall back'      => static function () use ( $lm_defaults ): void {
		foreach ( [ 'lumia', 'smooth', 'snappy', 'bouncy', 'linear' ] as $ease ) {
			$result = SettingsSanitizer::sanitize( [ 'ease' => $ease ], $lm_defaults() );
			lm_assert( $ease === $result['ease'], "{$ease} must be kept" );
		}
		$junk = SettingsSanitizer::sanitize( [ 'ease' => 'wobbly' ], $lm_defaults() );
		lm_assert( 'lumia' === $junk['ease'], 'an unknown ease must become lumia' );
	},
	'custom ease with an invalid curve is refused'  => static function () use ( $lm_defaults ): void {
		$result = SettingsSanitizer::sanitize(
			[
				'ease'        => 'custom',
				'ease_custom' => 'cubic-bezier(2,0,0,1)',
			],
			$lm_defaults()
		);
		lm_assert( 'lumia' === $result['ease'], 'custom with x1 = 2 must become lumia' );
		lm_assert( '' === $result['ease_custom'], 'the invalid curve must be cleared' );
	},
	'custom ease without a curve is refused'        => static function () use ( $lm_defaults ): void {
		$result = SettingsSanitizer::sanitize( [ 'ease' => 'custom' ], $lm_defaults() );
		lm_assert( 'lumia' === $result['ease'], 'custom without a curve must become lumia' );
		lm_assert( '' === $result['ease_custom'], 'ease_custom must stay empty' );
	},
	'a valid custom curve is kept as typed'         => static function () use ( $lm_defaults ): void {
		$result = SettingsSanitizer::sanitize(
			[
				'ease'        => 'custom',
				'ease_custom' => 'cubic-bezier(.2, .8, .2, 1)',
			],
			$lm_defaults()
		);
		lm_assert( 'custom' === $result['ease'], 'custom must be kept' );
		lm_assert( 'cubic-bezier(.2, .8, .2, 1)' === $result['ease_custom'], 'the curve must be kept as typed' );
	},
	'an unchecked box is false'                     => static function () use ( $lm_defaults ): void {
		$result = SettingsSanitizer::sanitize( [ 'tempo' => 'fast' ], $lm_defaults() );
		lm_assert( false === $result['lenis']['enabled'], 'a missing lenis[enabled] must be false' );
		lm_assert( false === $result['legacy_classes'], 'a missing legacy_classes must be false' );
	},
	'a checked box is true'                         => static function () use ( $lm_defaults ): void {
		$result = SettingsSanitizer::sanitize(
			[
				'lenis'          => [ 'enabled' => '1' ],
				'legacy_classes' => '1',
			],
			$lm_defaults()
		);
		lm_assert( true === $result['lenis']['enabled'], 'lenis[enabled] = 1 must be true' );
		lm_assert( true === $result['legacy_classes'], 'legacy_classes = 1 must be true' );
	},
	'builder_preview accepts replay and off only'   => static function () use ( $lm_defaults ): void {
		$off  = SettingsSanitizer::sanitize( [ 'builder_preview' => 'off' ], $lm_defaults() );
		$junk = SettingsSanitizer::sanitize( [ 'builder_preview' => 'x' ], $lm_defaults() );
		lm_assert( 'off' === $off['builder_preview'], 'off must be kept' );
		lm_assert( 'replay' === $junk['builder_preview'], 'x must become replay' );
	},
	'an unknown effect is ignored'                  => static function () use ( $lm_defaults, $lm_effects ): void {
		$effects = $lm_effects( [ 'spin' => [ 'duration' => '1' ] ], $lm_defaults );
		lm_assert( ! isset( $effects['spin'] ), 'the unknown effect must not be stored' );
		lm_assert( array_keys( $effects ) === array_keys( Effects::reference_values() ), 'the effect list must stay the catalog' );
	},
	'a key foreign to the effect is ignored'        => static function () use ( $lm_defaults, $lm_effects ): void {
		$effects = $lm_effects(
			[
				'fade'       => [
					'distance' => '10',
					'bogus'    => '1',
					'end_at'   => '-10%',
				],
				'text-words' => [ 'duration' => '2' ],
			],
			$lm_defaults
		);
		lm_assert( ! isset( $effects['fade']['distance'] ), 'fade has no distance' );
		lm_assert( ! isset( $effects['fade']['bogus'] ), 'a key outside the catalog must not be stored' );
		lm_assert( ! isset( $effects['fade']['end_at'] ), 'fade is not a scrub effect' );
		lm_assert( ! isset( $effects['text-words']['duration'] ), 'a scrub effect has no duration' );
	},
	'an empty input gives the defaults'             => static function () use ( $lm_defaults ): void {
		$result   = SettingsSanitizer::sanitize( [], $lm_defaults() );
		$expected = $lm_defaults();
		// The two boxes are absent from an empty input, hence false.
		$expected['lenis']['enabled'] = false;
		$expected['legacy_classes']   = false;
		lm_assert( $result === $expected, 'an empty input must give the defaults, boxes unchecked' );
	},
	'the sanitized result is stable'                => static function () use ( $lm_defaults ): void {
		$first  = SettingsSanitizer::sanitize(
			[
				'effects'         => [ 'reveal' => [ 'duration' => '0.8' ] ],
				'tempo'           => 'slow',
				'ease'            => 'custom',
				'ease_custom'     => 'cubic-bezier(0.1, 0.2, 0.3, 0.4)',
				'lenis'           => [
					'enabled' => '1',
					'lerp'    => '0.2',
				],
				'legacy_classes'  => '1',
				'builder_preview' => 'off',
			],
			$lm_defaults()
		);
		$second = SettingsSanitizer::sanitize( $first, $lm_defaults() );
		lm_assert( $first === $second, 'sanitizing a sanitized value must change nothing' );
	},
	'is_position checks the character set'          => static function (): void {
		lm_assert( SettingsSanitizer::is_position( 'top 80%' ), 'top 80% is a position' );
		lm_assert( SettingsSanitizer::is_position( 'center 50.5%' ), 'center 50.5% is a position' );
		lm_assert( ! SettingsSanitizer::is_position( '' ), 'empty is not a position' );
		lm_assert( ! SettingsSanitizer::is_position( 'foo;bar' ), 'a semicolon is not allowed' );
		lm_assert( ! SettingsSanitizer::is_position( "top 80%\n" ), 'a trailing newline is not allowed' );
		lm_assert( ! SettingsSanitizer::is_position( str_repeat( 'a', 41 ) ), '41 characters is too long' );
	},
	'is_percent checks the format'                  => static function (): void {
		lm_assert( SettingsSanitizer::is_percent( '98%' ), '98% is a percentage' );
		lm_assert( SettingsSanitizer::is_percent( '-120%' ), '-120% is a percentage' );
		lm_assert( SettingsSanitizer::is_percent( '12.5%' ), '12.5% is a percentage' );
		lm_assert( ! SettingsSanitizer::is_percent( '90' ), '90 has no percent sign' );
		lm_assert( ! SettingsSanitizer::is_percent( '12.345%' ), 'three decimals are refused' );
		lm_assert( ! SettingsSanitizer::is_percent( '+5%' ), 'a plus sign is refused' );
		lm_assert( ! SettingsSanitizer::is_percent( "5%\n" ), 'a trailing newline is refused' );
	},
	'parse_bezier reads four numbers'               => static function (): void {
		lm_assert( SettingsSanitizer::parse_bezier( 'cubic-bezier(0.625, 0.05, 0, 1)' ) === [ 0.625, 0.05, 0.0, 1.0 ], 'the Lumia curve must parse' );
		lm_assert( SettingsSanitizer::parse_bezier( 'cubic-bezier(.2,.8,.2,1)' ) === [ 0.2, 0.8, 0.2, 1.0 ], 'leading dots must parse' );
		lm_assert( SettingsSanitizer::parse_bezier( 'cubic-bezier(0.3, -0.5, 0.7, 1.6)' ) === [ 0.3, -0.5, 0.7, 1.6 ], 'y values may leave [0, 1]' );
	},
	'parse_bezier refuses what is not a curve'      => static function (): void {
		lm_assert( null === SettingsSanitizer::parse_bezier( 'cubic-bezier(2,0,0,1)' ), 'x1 above 1 is refused' );
		lm_assert( null === SettingsSanitizer::parse_bezier( 'cubic-bezier(0,0,-0.1,1)' ), 'x2 below 0 is refused' );
		lm_assert( null === SettingsSanitizer::parse_bezier( 'cubic-bezier(0,0,1)' ), 'three numbers are refused' );
		lm_assert( null === SettingsSanitizer::parse_bezier( 'cubic-bezier(0,0,1,1,1)' ), 'five numbers are refused' );
		lm_assert( null === SettingsSanitizer::parse_bezier( 'cubic-bezier(a,0,1,1)' ), 'a letter is refused' );
		lm_assert( null === SettingsSanitizer::parse_bezier( 'ease-in' ), 'a keyword is refused' );
		lm_assert( null === SettingsSanitizer::parse_bezier( 'cubic-bezier(0,0,1,1);x' ), 'trailing code is refused' );
		lm_assert( null === SettingsSanitizer::parse_bezier( "cubic-bezier(0,0,1,1)\n" ), 'a trailing newline is refused' );
		lm_assert( null === SettingsSanitizer::parse_bezier( '' ), 'empty is refused' );
	},
];
