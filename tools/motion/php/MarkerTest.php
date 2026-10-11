<?php
/**
 * Tests for the Motion markup helpers: families seen on an element and inline overrides.
 *
 * @package Lumia\Tools
 */

// Development-only test file: it is not plugin code. Assertions read as
// "actual === expected", so Yoda conditions would only make them harder to read.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.PHP.YodaConditions

declare( strict_types=1 );

require_once __DIR__ . '/wp-stubs.php';

use Lumia\Tools\Modules\Motion\Marker;

return [
	'an element effect gives its family'              => static function (): void {
		lm_assert( Marker::families( [ '_lmEffect' => 'slide-up' ], [], false ) === [ 'enter' ], 'slide-up' );
		lm_assert( Marker::families( [ '_lmEffect' => 'counter' ], [], false ) === [ 'counter' ], 'counter' );
		lm_assert( Marker::families( [ '_lmEffect' => 'underline' ], [], false ) === [ 'underline' ], 'underline' );
	},
	'a breakpoint effect and a class combine'         => static function (): void {
		$families = Marker::families(
			[ '_lmEffect:mobile_portrait' => 'words' ],
			[
				[
					'name'     => 'lm-fade',
					'settings' => [],
				],
			],
			false
		);
		lm_assert( $families === [ 'enter', 'text' ], 'got ' . implode( ',', $families ) );
	},
	'families come in a fixed order'                  => static function (): void {
		$families = Marker::families(
			[ '_lmEffect' => 'underline' ],
			[
				[
					'name'     => 'lm-counter',
					'settings' => [],
				],
				[
					'name'     => 'lm-lines',
					'settings' => [],
				],
				[
					'name'     => 'lm-fade',
					'settings' => [],
				],
			],
			false
		);
		lm_assert( $families === [ 'enter', 'text', 'counter', 'underline' ], 'got ' . implode( ',', $families ) );
	},
	'none contributes nothing'                        => static function (): void {
		lm_assert( Marker::families( [ '_lmEffect' => 'none' ], [], false ) === [], 'none' );
		lm_assert( Marker::families( [ '_lmEffect' => '' ], [], false ) === [], 'empty' );
		lm_assert( Marker::families( [ '_lmEffect' => 'unknown' ], [], false ) === [], 'unknown' );
		lm_assert( Marker::families( [], [], false ) === [], 'no settings' );
	},
	'a legacy class counts only with the setting'     => static function (): void {
		$classes = [
			[
				'name'     => 'sk-counter',
				'settings' => [],
			],
		];
		lm_assert( Marker::families( [], $classes, true ) === [ 'counter' ], 'legacy on' );
		lm_assert( Marker::families( [], $classes, false ) === [], 'legacy off' );
	},
	'a class with its own effect gives its family'    => static function (): void {
		$classes = [
			[
				'name'     => 'titre-hero',
				'settings' => [ '_lmEffect' => 'zoom' ],
			],
		];
		lm_assert( Marker::families( [], $classes, false ) === [ 'enter' ], 'class setting' );

		$classes[0]['settings'] = [ '_lmEffect:tablet_portrait' => 'letters' ];
		lm_assert( Marker::families( [], $classes, false ) === [ 'text' ], 'class breakpoint setting' );

		$classes[0]['settings'] = [ '_lmEffect' => 'none' ];
		lm_assert( Marker::families( [], $classes, false ) === [], 'class none' );
	},
	'a class without a name or settings is skipped'   => static function (): void {
		$classes = [ [ 'name' => 'plain' ], [] ];
		lm_assert( Marker::families( [], $classes, true ) === [], 'plain classes' );
	},
	'a non string effect is ignored'                  => static function (): void {
		lm_assert( Marker::families( [ '_lmEffect' => [ 'fade' ] ], [], false ) === [], 'array value' );
	},
	'inline_style keeps base values in a fixed order' => static function (): void {
		$style = Marker::inline_style(
			[
				'_lmEndAt'                 => '10%',
				'_lmTempo'                 => 'fast',
				'_lmEndAt:mobile_portrait' => '5%',
			]
		);
		lm_assert( $style === '--lm-end-at:10%;--lm-tempo:fast;', 'got ' . $style );
	},
	'a bare number gets the unit Bricks appends'      => static function (): void {
		lm_assert( Marker::inline_style( [ '_lmEndAt' => '10' ] ) === '--lm-end-at:10%;', 'bare end' );
		lm_assert( Marker::inline_style( [ '_lmStartAt' => 40 ] ) === '--lm-start-at:40%;', 'integer start' );
		lm_assert( Marker::inline_style( [ '_lmEndAt' => '-120' ] ) === '--lm-end-at:-120%;', 'negative' );
		lm_assert( Marker::inline_style( [ '_lmEndAt' => '-120%' ] ) === '--lm-end-at:-120%;', 'already with unit' );
	},
	'a number without a unit control stays bare'      => static function (): void {
		lm_assert( Marker::inline_style( [ '_lmDuration' => '0.8' ] ) === '--lm-duration:0.8;', 'duration' );
		lm_assert( Marker::inline_style( [ '_lmDistance' => 24 ] ) === '--lm-distance:24;', 'distance' );
	},
	'a bare underline length gets px'                 => static function (): void {
		lm_assert( Marker::inline_style( [ '_lmUnderlineThickness' => '3' ] ) === '--lm-underline-thickness:3px;', 'bare' );
		lm_assert( Marker::inline_style( [ '_lmUnderlineGap' => '0.2em' ] ) === '--lm-underline-gap:0.2em;', 'with unit' );
	},
	'a color setting is read as Bricks does'          => static function (): void {
		lm_assert(
			Marker::inline_style( [ '_lmUnderlineColor' => [ 'hex' => '#ff0000' ] ] ) === '--lm-underline-color:#ff0000;',
			'hex'
		);
		lm_assert(
			Marker::inline_style(
				[
					'_lmUnderlineColor' => [
						'hex' => '#ff0000',
						'rgb' => 'rgba(255, 0, 0, 0.5)',
					],
				]
			) === '--lm-underline-color:rgba(255, 0, 0, 0.5);',
			'rgb over hex'
		);
		lm_assert(
			Marker::inline_style(
				[
					'_lmUnderlineColor' => [
						'hex' => '#ff0000',
						'raw' => 'var(--brand)',
					],
				]
			) === '--lm-underline-color:var(--brand);',
			'raw over hex'
		);
		lm_assert( Marker::inline_style( [ '_lmUnderlineColor' => [] ] ) === '', 'empty color' );
	},
	'unsafe or empty values are dropped'              => static function (): void {
		lm_assert( Marker::inline_style( [ '_lmStart' => 'top 10%;} body{display:none' ] ) === '', 'injection' );
		lm_assert( Marker::inline_style( [ '_lmStart' => 'top 90%' ] ) === '--lm-start:top 90%;', 'valid start' );
		lm_assert( Marker::inline_style( [ '_lmTempo' => '' ] ) === '', 'empty' );
		lm_assert( Marker::inline_style( [ '_lmTempo' => [ 'fast' ] ] ) === '', 'array' );
		lm_assert( Marker::inline_style( [ '_lmTempo' => 'fast"onload="x' ] ) === '', 'quote' );
		lm_assert( Marker::inline_style( [ '_lmEaseCustom' => 'cubic-bezier(0.2, 0.8, 0.2, 1)' ] ) === '--lm-ease-custom:cubic-bezier(0.2, 0.8, 0.2, 1);', 'cubic-bezier' );
	},
	'keys that write no variable are ignored'         => static function (): void {
		$style = Marker::inline_style(
			[
				'_lmParamsSep' => 'x',
				'_cssClasses'  => 'a',
			]
		);
		lm_assert( $style === '', 'foreign keys' );
	},
	'the off effect is kept so it overrides a class'  => static function (): void {
		lm_assert( Marker::inline_style( [ '_lmEffect' => 'none' ] ) === '--lm-effect:none;', 'none' );
	},
	'a class typed in the CSS classes field counts'   => static function (): void {
		lm_assert( Marker::families( [ '_cssClasses' => 'lm-slide-up' ], [], false ) === [ 'enter' ], 'lm-slide-up typed' );
		lm_assert( Marker::families( [ '_cssClasses' => "  hero\tlm-counter  big " ], [], false ) === [ 'counter' ], 'among other classes, any white space' );
		lm_assert( Marker::families( [ '_cssClasses' => 'lm-fade lm-underline' ], [], false ) === [ 'enter', 'underline' ], 'two effects' );
		lm_assert( Marker::families( [ '_cssClasses' => 'sk-fade' ], [], true ) === [ 'enter' ], 'legacy name typed, compatibility on' );
		lm_assert( Marker::families( [ '_cssClasses' => 'sk-fade' ], [], false ) === [], 'legacy name typed, compatibility off' );
		lm_assert( Marker::families( [ '_cssClasses' => 'lm-fadex xlm-fade' ], [], true ) === [], 'a longer name is not the class' );
		lm_assert( Marker::families( [ '_cssClasses' => [ 'lm-fade' ] ], [], true ) === [], 'not a string' );
	},
	'a scroll position with += survives inline'       => static function (): void {
		lm_assert( Marker::inline_style( [ '_lmStart' => 'top+=50 80%' ] ) === '--lm-start:top+=50 80%;', 'top+=50 80%' );
		lm_assert( Marker::inline_style( [ '_lmEnd' => 'bottom-=20 top' ] ) === '--lm-end:bottom-=20 top;', 'bottom-=20 top' );
		lm_assert( Marker::inline_style( [ '_lmStart' => 'top+=50;color:red' ] ) === '', 'still no semicolon or colon' );
		lm_assert( Marker::inline_style( [ '_lmStart' => 'top+=50}' ] ) === '', 'still no brace' );
		lm_assert( Marker::inline_style( [ '_lmStart' => 'top+=50<' ] ) === '', 'still no angle bracket' );
	},
	'the loop state comes from the running loop only' => static function (): void {
		require_once __DIR__ . '/bricks-stubs.php';

		// Bricks forces is_looping() for an AJAX popup (`bricks/query/force_is_looping`) without any loop running:
		// its elements keep their ID selector, so they need no inline style.
		\Bricks\Query::$forced       = true;
		\Bricks\Query::$looping      = false;
		\Bricks\Query::$loop_element = false;
		lm_assert( Marker::uses_class_selector( [ 'id' => 'abcdef' ] ) === false, 'forced is_looping, no loop running' );

		\Bricks\Query::$forced       = false;
		\Bricks\Query::$looping      = 'q1';
		\Bricks\Query::$loop_element = 'loopel';
		lm_assert( Marker::uses_class_selector( [ 'id' => 'loopel' ] ) === true, 'the element that holds the loop' );
		lm_assert( Marker::uses_class_selector( [ 'id' => 'inside' ] ) === false, 'an element inside the loop' );

		\Bricks\Query::$looping = false;
		lm_assert( Marker::uses_class_selector( [ 'id' => 'outside' ] ) === false, 'no loop' );
		lm_assert(
			Marker::uses_class_selector(
				[
					'id'  => 'comp',
					'cid' => 'x',
				]
			) === true,
			'a component'
		);
	},
];
