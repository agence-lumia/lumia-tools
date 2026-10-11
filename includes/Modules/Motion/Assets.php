<?php
namespace Lumia\Tools\Modules\Motion;

defined( 'ABSPATH' ) || exit;

/**
 * Loading of the Motion files on the public pages and in the Bricks builder.
 *
 * Three moments, each with a different amount of knowledge about the page:
 *
 * - `wp_enqueue_scripts`: before anything is rendered, so only what every page needs
 *   (the stylesheet).
 * - `wp_head` (priority 1): the inline script that sets `lm-js` and the 3 s safety.
 * - `wp_footer` (priority 19): the page is rendered, popups included (Bricks prints them
 *   at priority 10), and `Marker::page_families()` knows which effects are on it. The
 *   scripts of the footer are printed at priority 20.
 *
 * Nothing is loaded in the admin. In the builder, the main window gets the builder
 * stylesheet only, and the canvas gets the engine in its builder mode (no smooth scroll).
 */
final class Assets {

	const HANDLE_GSAP          = 'lumia-gsap';
	const HANDLE_SCROLLTRIGGER = 'lumia-scrolltrigger';
	const HANDLE_SPLITTEXT     = 'lumia-splittext';
	const HANDLE_LENIS         = 'lumia-lenis';
	const HANDLE_CORE          = 'lumia-motion-core';
	const HANDLE_MOTION        = 'lumia-motion';
	const HANDLE_BUILDER       = 'lumia-motion-builder';
	const HANDLE_SCROLL        = 'lumia-motion-scroll';

	/** Seconds after which the safety makes every element visible when the engine did not start. */
	const SAFETY_MS = 3000;

	/**
	 * Sanitized settings of the module.
	 *
	 * @var array<string, mixed>
	 */
	private array $settings;

	/**
	 * Builds the loader.
	 *
	 * @param array<string, mixed> $settings Settings of the module; sanitized again here, so a hand-edited option cannot reach a script.
	 */
	public function __construct( array $settings ) {
		$this->settings = SettingsSanitizer::sanitize( $settings, Module::get_defaults() );
	}

	/**
	 * Registers the hooks.
	 */
	public function hook(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_styles' ] );
		add_action( 'wp_head', [ $this, 'print_head_script' ], 1 );
		add_action( 'wp_footer', [ $this, 'enqueue_scripts' ], 19 );
	}

	/**
	 * Stylesheets: the builder one in the main builder window, the front one everywhere else.
	 */
	public function enqueue_styles(): void {
		if ( is_admin() ) {
			return;
		}

		if ( self::is_builder_main() ) {
			wp_enqueue_style( self::HANDLE_BUILDER, LUMIA_ASSETS_URL . 'front/css/motion-builder.css', [], LUMIA_VERSION );

			return;
		}

		wp_enqueue_style( self::HANDLE_MOTION, LUMIA_ASSETS_URL . 'front/css/motion.css', [], LUMIA_VERSION );

		if ( $this->legacy_enabled() ) {
			wp_add_inline_style( self::HANDLE_MOTION, Legacy::underline_css() );
		}

		if ( $this->scroll_wanted() ) {
			wp_enqueue_style( self::HANDLE_LENIS, LUMIA_ASSETS_URL . 'front/js/vendor/lenis/lenis.css', [ self::HANDLE_MOTION ], LUMIA_VERSION );
		}
	}

	/**
	 * Prints the script that flags the page as scripted and arms the safety.
	 *
	 * `lm-js` switches the pre-hide of the stylesheet on; `lm-ready` switches it off. If the
	 * engine has not added `lm-ready` after SAFETY_MS (blocked file, script error, slow
	 * network), this timer does, and everything becomes visible. Not printed in the builder
	 * canvas: its engine shows every element as it ends, so there is nothing to pre-hide.
	 */
	public function print_head_script(): void {
		// The canvas never pre-hides: its engine shows every element as it ends.
		if ( is_admin() || self::is_builder_main() || self::is_builder_canvas() ) {
			return;
		}

		wp_print_inline_script_tag(
			"var d=document.documentElement;d.classList.add('lm-js');setTimeout(function(){d.classList.add('lm-ready')}," . (int) self::SAFETY_MS . ');'
		);
	}

	/**
	 * Scripts: the engine and its libraries when the page needs them, smooth scroll when it is on.
	 */
	public function enqueue_scripts(): void {
		if ( is_admin() || self::is_builder_main() ) {
			return;
		}

		$canvas    = self::is_builder_canvas();
		$engine    = $canvas || $this->page_needs_engine();
		$splitting = $canvas || in_array( Effects::FAMILY_TEXT, Marker::page_families(), true );

		$this->register_libraries();

		if ( $engine ) {
			$this->enqueue_engine( $canvas, $splitting );
		}

		if ( $this->scroll_wanted() ) {
			$this->enqueue_scroll( $engine );
		}
	}

	/**
	 * Whether the rendered page holds an effect that needs the engine.
	 *
	 * The underline is drawn by the stylesheet alone: a page that only has underlines
	 * does not need GSAP.
	 */
	private function page_needs_engine(): bool {
		$families = array_diff( Marker::page_families(), [ Effects::FAMILY_UNDERLINE ] );

		return array() !== $families;
	}

	/**
	 * Registers the bundled libraries, without loading them.
	 */
	private function register_libraries(): void {
		$base = LUMIA_ASSETS_URL . 'front/js/vendor/';

		wp_register_script( self::HANDLE_GSAP, $base . 'gsap/gsap.min.js', [], LUMIA_VERSION, self::footer_args() );
		wp_register_script( self::HANDLE_SCROLLTRIGGER, $base . 'gsap/ScrollTrigger.min.js', [ self::HANDLE_GSAP ], LUMIA_VERSION, self::footer_args() );
		wp_register_script( self::HANDLE_SPLITTEXT, $base . 'gsap/SplitText.min.js', [ self::HANDLE_GSAP ], LUMIA_VERSION, self::footer_args() );
		wp_register_script( self::HANDLE_LENIS, $base . 'lenis/lenis.min.js', [], LUMIA_VERSION, self::footer_args() );
	}

	/**
	 * Loads the engine, its configuration and, in the canvas, the builder script.
	 *
	 * @param bool $canvas    Whether this is the Bricks canvas.
	 * @param bool $splitting Whether SplitText is needed.
	 */
	private function enqueue_engine( bool $canvas, bool $splitting ): void {
		$front = LUMIA_ASSETS_URL . 'front/js/';
		$deps  = [ self::HANDLE_CORE, self::HANDLE_GSAP, self::HANDLE_SCROLLTRIGGER ];

		if ( $splitting ) {
			$deps[] = self::HANDLE_SPLITTEXT;
		}

		wp_enqueue_script( self::HANDLE_CORE, $front . 'motion-core.js', [], LUMIA_VERSION, self::footer_args() );
		wp_enqueue_script( self::HANDLE_MOTION, $front . 'motion.js', $deps, LUMIA_VERSION, self::footer_args() );
		wp_add_inline_script(
			self::HANDLE_MOTION,
			'window.lumiaMotionConfig=' . wp_json_encode( $this->config( $canvas ), JSON_HEX_TAG | JSON_HEX_AMP ) . ';',
			'before'
		);

		if ( $canvas && self::has_script( 'motion-builder.js' ) ) {
			wp_enqueue_script( self::HANDLE_BUILDER, $front . 'motion-builder.js', [ self::HANDLE_MOTION ], LUMIA_VERSION, self::footer_args() );
		}
	}

	/**
	 * Loads Lenis and its script, after GSAP when GSAP is on the page so both can share the ticker.
	 *
	 * @param bool $with_gsap Whether the engine, hence GSAP, is loaded.
	 */
	private function enqueue_scroll( bool $with_gsap ): void {
		$deps = [ self::HANDLE_LENIS ];
		if ( $with_gsap ) {
			$deps[] = self::HANDLE_GSAP;
			$deps[] = self::HANDLE_SCROLLTRIGGER;
		}

		wp_enqueue_script( self::HANDLE_SCROLL, LUMIA_ASSETS_URL . 'front/js/motion-scroll.js', $deps, LUMIA_VERSION, self::footer_args() );
		wp_add_inline_script(
			self::HANDLE_SCROLL,
			'window.lumiaMotionScroll=' . wp_json_encode(
				[
					'lerp'         => $this->lenis_lerp(),
					'legacyGlobal' => Legacy::ENGINE_GLOBAL,
				],
				JSON_HEX_TAG | JSON_HEX_AMP
			) . ';',
			'before'
		);
	}

	/**
	 * Whether smooth scroll is loaded on this request: on, script shipped, and a page visitors see.
	 *
	 * Never in the main window of the builder (it has no scripts of the front) nor in the
	 * canvas, which keeps the native scroll of the builder. A handle is loaded only when its
	 * file is there, so a file that is not shipped (yet) is never requested.
	 */
	private function scroll_wanted(): bool {
		return $this->lenis_enabled()
			&& ! is_admin()
			&& ! self::is_builder_main()
			&& ! self::is_builder_canvas()
			&& self::has_script( 'motion-scroll.js' );
	}

	/**
	 * Intensity of the smooth scroll (the `lerp` of Lenis), within the range the sanitizer allows.
	 */
	private function lenis_lerp(): float {
		$lenis = $this->settings['lenis'] ?? [];

		return is_array( $lenis ) && isset( $lenis['lerp'] ) ? (float) $lenis['lerp'] : 0.1;
	}

	/**
	 * The configuration read by the engine as `window.lumiaMotionConfig`.
	 *
	 * @param bool $canvas Whether this is the Bricks canvas.
	 * @return array<string, mixed>
	 */
	public function config( bool $canvas ): array {
		$classes = [];
		foreach ( Effects::catalog() as $slug => $effect ) {
			$classes[ $effect['class'] ] = $slug;
		}

		$effects = [];
		foreach ( (array) $this->settings['effects'] as $slug => $values ) {
			// An effect with no setting (the underline) must be an object in JSON, not [].
			$effects[ $slug ] = empty( $values ) ? (object) [] : $values;
		}

		return [
			'effects'      => $effects,
			'tempo'        => $this->settings['tempo'],
			'ease'         => $this->settings['ease'],
			'easeCustom'   => $this->settings['ease_custom'],
			'classes'      => $classes,
			'aliases'      => $this->legacy_enabled() ? Legacy::CLASS_ALIASES : (object) [],
			'legacyGlobal' => Legacy::ENGINE_GLOBAL,
			'builder'      => $canvas,
			'preview'      => $this->settings['builder_preview'],
			'variables'    => Effects::ENGINE_VARIABLES,
		];
	}

	/**
	 * Whether the legacy class names count.
	 */
	private function legacy_enabled(): bool {
		return ! empty( $this->settings['legacy_classes'] );
	}

	/**
	 * Whether smooth scroll is on.
	 */
	private function lenis_enabled(): bool {
		$lenis = $this->settings['lenis'] ?? [];

		return is_array( $lenis ) && ! empty( $lenis['enabled'] );
	}

	/**
	 * Arguments of a script printed in the footer, deferred.
	 *
	 * @return array{strategy: 'defer', in_footer: true}
	 */
	private static function footer_args(): array {
		return [
			'strategy'  => 'defer',
			'in_footer' => true,
		];
	}

	/**
	 * Whether a script of assets/front/js/ is in the plugin.
	 *
	 * A handle is enqueued only when its file is there, so a file that is not shipped
	 * (yet) is never requested.
	 *
	 * @param string $file File name under assets/front/js/.
	 */
	private static function has_script( string $file ): bool {
		return is_readable( dirname( LUMIA_PLUGIN_FILE ) . '/assets/front/js/' . $file );
	}

	/**
	 * Whether this is the main window of the Bricks builder (`?bricks=run`, no preview parameter).
	 */
	private static function is_builder_main(): bool {
		return function_exists( 'bricks_is_builder_main' ) && bricks_is_builder_main();
	}

	/**
	 * Whether this is the canvas of the Bricks builder (the iframe, `?bricks=run&brickspreview`).
	 */
	private static function is_builder_canvas(): bool {
		return function_exists( 'bricks_is_builder_iframe' ) && bricks_is_builder_iframe();
	}
}
