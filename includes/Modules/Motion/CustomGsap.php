<?php
namespace Lumia\Tools\Modules\Motion;

defined( 'ABSPATH' ) || exit;

/**
 * Custom GSAP animations written for a page: whether the page needs GSAP for
 * them, and SplitText.
 *
 * A custom animation calls `lumiaMotion.ready( function ( m ) { … } )` (see
 * docs/modules/motion.md). The engine, hence GSAP and ScrollTrigger, then
 * loads on that page even without any Motion effect, through the same loader
 * after the first paint. Three ways to ask for it:
 *
 * - the call itself, found in the JavaScript of a Bricks Code element of the
 *   page (Marker) or in the custom scripts of the page settings;
 * - the "Load GSAP on every page" setting, for animations kept in a snippet
 *   plugin (FluentSnippets) that no page shows;
 * - the `lumia_motion_custom_gsap` filter (true, or false to turn it off).
 *
 * A mention of SplitText in that code also loads SplitText.
 */
final class CustomGsap {

	/** What a script calls to run a custom animation. */
	const CALL = 'lumiaMotion.ready';

	/** Element settings that hold code (Bricks Code element). */
	const ELEMENT_KEYS = [ 'javascriptCode', 'code' ];

	/** Page settings that hold scripts (Bricks > Page settings > Custom code). */
	const PAGE_KEYS = [ 'customScriptsHeader', 'customScriptsBodyHeader', 'customScriptsBodyFooter' ];

	/** Post meta of the Bricks page settings (BRICKS_DB_PAGE_SETTINGS). */
	const PAGE_SETTINGS_META = '_bricks_page_settings';

	/**
	 * What was found so far on this request: null when nothing asks for GSAP.
	 *
	 * @var array{split: bool}|null
	 */
	private static ?array $found = null;

	/**
	 * Reads code and records what it needs. Pure apart from the recorded state.
	 *
	 * @param mixed $code Code of an element or of a page setting.
	 * @return array{split: bool}|null Null when the code does not call lumiaMotion.ready().
	 */
	public static function scan( $code ): ?array {
		if ( ! is_string( $code ) || false === strpos( $code, self::CALL ) ) {
			return null;
		}

		return [ 'split' => false !== strpos( $code, 'SplitText' ) ];
	}

	/**
	 * Records the needs of an element's code (Marker, for every rendered element).
	 *
	 * @param array<string, mixed> $settings Settings of the element.
	 */
	public static function observe_element( array $settings ): void {
		foreach ( self::ELEMENT_KEYS as $key ) {
			if ( isset( $settings[ $key ] ) ) {
				self::record( self::scan( $settings[ $key ] ) );
			}
		}
	}

	/**
	 * Records the needs of the custom scripts in the page settings.
	 *
	 * @param mixed $page_settings Bricks page settings of the page.
	 */
	public static function observe_page( $page_settings ): void {
		if ( ! is_array( $page_settings ) ) {
			return;
		}

		foreach ( self::PAGE_KEYS as $key ) {
			if ( isset( $page_settings[ $key ] ) ) {
				self::record( self::scan( $page_settings[ $key ] ) );
			}
		}
	}

	/**
	 * What the page needs, null when nothing asks for GSAP.
	 *
	 * @return array{split: bool}|null
	 */
	public static function found(): ?array {
		return self::$found;
	}

	/**
	 * Forgets what was recorded (tests).
	 */
	public static function reset(): void {
		self::$found = null;
	}

	/**
	 * @param array{split: bool}|null $need
	 */
	private static function record( ?array $need ): void {
		if ( null === $need ) {
			return;
		}

		self::$found = [ 'split' => $need['split'] || ! empty( self::$found['split'] ) ];
	}
}
