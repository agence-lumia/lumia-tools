<?php
/**
 * Tests for the Bricks panel controls: placeholders that name the site value.
 *
 * @package Lumia\Tools
 */

// Development-only test file: it is not plugin code. Assertions read as
// "actual === expected", so Yoda conditions would only make them harder to read.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.PHP.YodaConditions

declare( strict_types=1 );

require_once __DIR__ . '/wp-stubs.php';

use Lumia\Tools\Modules\Motion\Controls;

return [
	'empty easing and tempo name the module defaults'   => static function (): void {
		$GLOBALS['lm_test_options'] = [];
		$controls                   = Controls::definitions();
		lm_assert( $controls['_lmEase']['placeholder'] === 'Smooth (site default)', 'ease: ' . $controls['_lmEase']['placeholder'] );
		lm_assert( $controls['_lmTempo']['placeholder'] === 'Normal (site default)', 'tempo: ' . $controls['_lmTempo']['placeholder'] );
		lm_assert( $controls['_lmCounterWidth']['placeholder'] === 'Dynamic width (site default)', 'width: ' . $controls['_lmCounterWidth']['placeholder'] );
	},
	'empty easing and tempo follow the site settings'   => static function (): void {
		$GLOBALS['lm_test_options'] = [
			'lumia_module_motion' => [
				'ease'    => 'bouncy',
				'tempo'   => 'slow',
				'effects' => [ 'counter' => [ 'counter_width' => 'fixed' ] ],
			],
		];
		$controls                   = Controls::definitions();
		$GLOBALS['lm_test_options'] = [];
		lm_assert( $controls['_lmEase']['placeholder'] === 'Bouncy (site default)', 'ease: ' . $controls['_lmEase']['placeholder'] );
		lm_assert( $controls['_lmTempo']['placeholder'] === 'Slow (site default)', 'tempo: ' . $controls['_lmTempo']['placeholder'] );
		lm_assert( $controls['_lmCounterWidth']['placeholder'] === 'Fixed width (site default)', 'width: ' . $controls['_lmCounterWidth']['placeholder'] );
	},
	'an unknown stored value falls back to the default' => static function (): void {
		$GLOBALS['lm_test_options'] = [ 'lumia_module_motion' => [ 'ease' => 'wobbly' ] ];
		$controls                   = Controls::definitions();
		$GLOBALS['lm_test_options'] = [];
		lm_assert( $controls['_lmEase']['placeholder'] === 'Smooth (site default)', 'ease: ' . $controls['_lmEase']['placeholder'] );
	},
];
