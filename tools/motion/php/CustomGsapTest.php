<?php
/**
 * Tests of CustomGsap: which code asks for GSAP and SplitText.
 *
 * @package Lumia\Tools
 */

declare( strict_types=1 );

use Lumia\Tools\Modules\Motion\CustomGsap;

return [
	'scan ignores code without lumiaMotion.ready' => static function (): void {
		lm_assert( null === CustomGsap::scan( 'gsap.to(".a", { x: 10 });' ), 'plain GSAP code does not ask' );
		lm_assert( null === CustomGsap::scan( null ), 'a non-string is ignored' );
	},
	'scan finds the call and SplitText'           => static function (): void {
		$plain = CustomGsap::scan( 'lumiaMotion.ready(function (m) { m.gsap.to(".a", { x: 10 }); });' );
		$split = CustomGsap::scan( 'window.lumiaMotion.ready(function (m) { new m.SplitText(".t"); });' );
		lm_assert( null !== $plain && false === $plain['split'], 'the call asks for GSAP without SplitText' );
		lm_assert( null !== $split && true === $split['split'], 'a SplitText mention asks for SplitText' );
	},
	'observations accumulate per request'         => static function (): void {
		CustomGsap::reset();
		CustomGsap::observe_element( [ 'text' => 'lumiaMotion.ready' ] );
		lm_assert( null === CustomGsap::found(), 'only the code settings are read' );
		CustomGsap::observe_element( [ 'javascriptCode' => 'lumiaMotion.ready(function(m){new m.SplitText(".t")})' ] );
		CustomGsap::observe_page( [ 'customScriptsBodyFooter' => '<script>lumiaMotion.ready(function(){})</script>' ] );
		$found = CustomGsap::found();
		lm_assert( null !== $found && true === $found['split'], 'SplitText asked once stays asked' );
		CustomGsap::reset();
		CustomGsap::observe_page( 'not an array' );
		lm_assert( null === CustomGsap::found(), 'page settings that are not an array are ignored' );
	},
];
