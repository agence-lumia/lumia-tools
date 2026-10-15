<?php
namespace Lumia\Tools\Modules\Motion;

defined( 'ABSPATH' ) || exit;

/**
 * Loading of the Motion files on the public pages and in the Bricks builder.
 *
 * Three moments, each with a different amount of knowledge about the page:
 *
 * - `wp_enqueue_scripts`: before anything is rendered, so only what every page needs
 *   (the styles, printed inline: a linked stylesheet would block the first paint).
 * - `wp_head` (priority 1): the inline script that sets `lm-js` and the 3 s safety.
 * - `wp_footer` (priority 19): the page is rendered, popups included (Bricks prints them
 *   at priority 10), and `Marker::engine_families()` knows which effects the engine plays. On a
 *   public page the scripts are not printed as tags: an inline loader injects them once
 *   the page has painted, see print_loader(). In the canvas they are printed by WordPress
 *   at priority 20, as usual.
 *
 * The counter is a classic script too (motion-counter.js, the adapter and NumberFlow bundled by
 * tools/motion/js/build-counter.mjs), loaded only where a counter is played. It used to be a
 * set of WordPress script modules: nginx serves `.mjs` as application/octet-stream, which
 * browsers refuse for a module script, so no counter rolled on the sites.
 *
 * Nothing is loaded in the admin. In the builder, the main window gets the builder
 * stylesheet and the panel script (Replay, markers, inherited placeholders), and the canvas
 * gets the engine in its builder mode (no smooth scroll).
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
	const HANDLE_PANEL         = 'lumia-motion-panel';
	const HANDLE_COUNTER       = 'lumia-motion-counter';

	/** Seconds after which the safety makes every element visible when the engine did not start. */
	const SAFETY_MS = 3000;

	/**
	 * Milliseconds after DOMContentLoaded after which the loader injects the scripts even
	 * without a paint: a tab opened in the background paints nothing until it is shown, and a
	 * browser with only `first-contentful-paint` waits for content on a page whose every
	 * element is an entrance hidden until the engine plays it.
	 */
	const LOADER_FALLBACK_MS = 1000;

	/**
	 * Milliseconds the loader still waits for a paint entry once two animation frames have run.
	 * Chrome's `first-paint` arrives a few milliseconds after the second frame; a browser with only
	 * `first-contentful-paint` (Safari, Firefox) sends nothing on a first screen that holds only
	 * hidden entrances, and used to wait the whole LOADER_FALLBACK_MS. A background tab runs no
	 * frame, so the fallback still covers it.
	 */
	const LOADER_FRAME_GRACE_MS = 100;

	/** Handles of the scripts the loader may inject, in no particular order. */
	const LOADED_HANDLES = [
		self::HANDLE_GSAP,
		self::HANDLE_SCROLLTRIGGER,
		self::HANDLE_SPLITTEXT,
		self::HANDLE_LENIS,
		self::HANDLE_CORE,
		self::HANDLE_MOTION,
		self::HANDLE_COUNTER,
		self::HANDLE_SCROLL,
	];

	/**
	 * Sanitized settings of the module.
	 *
	 * @var array<string, mixed>
	 */
	private array $settings;

	/**
	 * Builds the loader.
	 *
	 * @param array<string, mixed> $settings Settings of the module, as returned by SettingsSanitizer::sanitize().
	 */
	public function __construct( array $settings ) {
		$this->settings = $settings;
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
	 * Styles: the builder stylesheet in the main builder window; everywhere else the front
	 * styles (and the Lenis ones when smooth scroll is on), printed inline in the head.
	 *
	 * A linked stylesheet blocks the first paint until it has arrived, and Lighthouse's
	 * simulation charges that request to the LCP. Printed inline, the 4 KB (gzip) of styles
	 * travel with the page. The file read is the one that would have been linked, so the
	 * release zip, which minifies motion.css in place, prints the minified styles; lenis.css
	 * is a third-party file, printed as it is shipped.
	 */
	public function enqueue_styles(): void {
		if ( is_admin() ) {
			return;
		}

		if ( self::is_builder_main() ) {
			wp_enqueue_style( self::HANDLE_BUILDER, LUMIA_ASSETS_URL . 'front/css/motion-builder.css', [], LUMIA_VERSION );

			return;
		}

		self::inline_style( self::HANDLE_MOTION, 'front/css/motion.css', [] );

		if ( $this->legacy_enabled() ) {
			wp_add_inline_style( self::HANDLE_MOTION, Legacy::underline_css() );
		}

		if ( $this->scroll_wanted() ) {
			self::inline_style( self::HANDLE_LENIS, 'front/js/vendor/lenis/lenis.css', [ self::HANDLE_MOTION ] );
		}
	}

	/**
	 * Enqueues a style handle with no file and the content of a stylesheet of the plugin as
	 * its inline style (`<style id="{handle}-inline-css">`, where the link would have been).
	 *
	 * @param string   $handle Style handle.
	 * @param string   $file   Path under assets/.
	 * @param string[] $deps   Handles printed before this one.
	 */
	private static function inline_style( string $handle, string $file, array $deps ): void {
		wp_register_style( $handle, false, $deps, LUMIA_VERSION );
		wp_enqueue_style( $handle );

		$path = dirname( LUMIA_PLUGIN_FILE ) . '/assets/' . $file;
		$css  = is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file of the plugin, not a URL.
		if ( is_string( $css ) && '' !== trim( $css ) ) {
			wp_add_inline_style( $handle, trim( $css ) );
		}
	}

	/**
	 * Prints the script that flags the page as scripted and arms the safety.
	 *
	 * `lm-js` switches the pre-hide of the stylesheet on; `lm-ready` switches it off. If the
	 * engine has not added `lm-ready` after SAFETY_MS (blocked file, script error, slow
	 * network), this timer does, and everything becomes visible. Not printed in the builder
	 * canvas: its engine shows every element as it ends, so there is nothing to pre-hide.
	 *
	 * Only while the document is still loading: run later (an optimiser that delays scripts
	 * until the first interaction), it would hide a page the visitor is already reading. The
	 * engine reads the same two classes and leaves what is on screen as it is.
	 */
	public function print_head_script(): void {
		// The canvas never pre-hides: its engine shows every element as it ends.
		if ( is_admin() || self::is_builder_main() || self::is_builder_canvas() ) {
			return;
		}

		wp_print_inline_script_tag(
			"(function(d){if(document.readyState!=='loading'){return;}d.classList.add('lm-js');setTimeout(function(){d.classList.add('lm-ready')}," . (int) self::SAFETY_MS . ');})(document.documentElement);'
		);
	}

	/**
	 * Scripts: the engine and its libraries when the page needs them, smooth scroll when it is on;
	 * the panel script alone in the main window of the builder.
	 */
	public function enqueue_scripts(): void {
		if ( is_admin() ) {
			return;
		}

		if ( self::is_builder_main() ) {
			if ( self::has_script( 'motion-panel.js' ) ) {
				wp_enqueue_script( self::HANDLE_PANEL, LUMIA_ASSETS_URL . 'front/js/motion-panel.js', [], LUMIA_VERSION, self::footer_args() );
			}

			return;
		}

		$canvas    = self::is_builder_canvas();
		$engine    = $canvas || $this->page_needs_engine();
		$splitting = $canvas || in_array( Effects::FAMILY_TEXT, Marker::engine_families(), true );

		$this->register_libraries();

		if ( $engine ) {
			$this->enqueue_engine( $canvas, $splitting );
		}

		if ( $this->scroll_wanted() ) {
			$this->enqueue_scroll( $engine );
		}

		if ( ! $canvas ) {
			$this->print_loader();
		}
	}

	/**
	 * Replaces the script tags of a public page with an inline loader that injects the same
	 * scripts once the page has painted.
	 *
	 * Since the load animations are pure CSS, the first frame needs no script. Printed as
	 * deferred tags, the scripts were still requested while the HTML was parsed, before the
	 * first paint, and Lighthouse's simulated throttling counts every request started before
	 * the observed LCP (and the CPU time of the scripts) in its LCP estimate: +0.7 s on the
	 * mobile LCP for a page Chrome paints at the same time with or without the module.
	 *
	 * What loads is still decided by the enqueues above: the handles queued here, their
	 * dependencies in WordPress order, their URLs as WordPress prints them (`ver`, then the
	 * `script_loader_src` filter) and their inline `before` data, printed at once (the
	 * configuration objects). They are then dequeued, so WordPress prints no tag.
	 *
	 * The loader waits for DOMContentLoaded, then for the first paint entry (`first-paint`, or
	 * `first-contentful-paint` where only that one exists), two animation frames in a browser
	 * without paint timing, LOADER_FALLBACK_MS at most (a tab opened in the background paints
	 * nothing), and LOADER_FRAME_GRACE_MS after two frames (a browser with only
	 * `first-contentful-paint`, on a first screen with nothing contentful). Two frames alone were
	 * not enough: Chrome's paint entry carries the time the frame was presented, measured 2 ms
	 * after the second frame callback had injected the scripts. It then appends the scripts with `async = false`: they are fetched in parallel
	 * and run in order. A script that fails to load adds `lm-ready` at once, so nothing waits
	 * for the 3 s safety.
	 */
	private function print_loader(): void {
		$scripts = wp_scripts();
		$queued  = array_values( array_intersect( $scripts->queue, self::LOADED_HANDLES ) );
		$order   = [];
		foreach ( $queued as $handle ) {
			self::resolve_script( $scripts, $handle, $order );
		}

		// What the loader cannot carry is left to WordPress: the scripts keep their tags.
		if ( self::loader_unsafe( $scripts, $order ) ) {
			return;
		}

		$before = '';
		$list   = [];
		foreach ( $order as $handle ) {
			$item = $scripts->registered[ $handle ];
			$src  = (string) $item->src;
			if ( ! empty( $item->ver ) ) {
				$src = add_query_arg( 'ver', $item->ver, $src );
			}
			$src = apply_filters( 'script_loader_src', $src, $handle ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's filter, as WordPress applies it.

			// Without the sourceURL comment core adds to it: the data ends up in the loader's tag.
			$data = trim( (string) preg_replace( '~^//# sourceURL=.*$~m', '', $scripts->get_inline_script_data( $handle, 'before' ) ) );
			if ( '' !== $data ) {
				$before .= $data . "\n";
			}

			if ( is_string( $src ) && '' !== $src ) {
				$list[] = [
					'id'  => $handle . '-js',
					'src' => esc_url_raw( $src ),
				];
			}
		}

		foreach ( $queued as $handle ) {
			wp_dequeue_script( $handle );
		}

		if ( array() === $list ) {
			return;
		}

		$flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES;

		wp_print_inline_script_tag(
			$before . self::loader_js() . '(' . wp_json_encode( $list, $flags ) . ');',
			[ 'id' => 'lumia-motion-loader' ]
		);
	}

	/**
	 * Adds a handle to the list after its dependencies, depth first, as WordPress orders them.
	 *
	 * @param \WP_Scripts $scripts Registry.
	 * @param string      $handle  Handle to add.
	 * @param string[]    $order   Ordered handles, completed in place.
	 */
	private static function resolve_script( \WP_Scripts $scripts, string $handle, array &$order ): void {
		// A handle WordPress already printed (a dependency of a script of the head) is on the page.
		if ( in_array( $handle, $order, true ) || in_array( $handle, $scripts->done, true ) || ! isset( $scripts->registered[ $handle ] ) ) {
			return;
		}

		foreach ( (array) $scripts->registered[ $handle ]->deps as $dep ) {
			self::resolve_script( $scripts, (string) $dep, $order );
		}

		$order[] = $handle;
	}

	/**
	 * Whether a third party attached to a Motion handle something the loader would drop: an
	 * `after` inline script, `wp_localize_script()` data (kept as `data`), or a change of the
	 * tag through `script_loader_tag` (attributes, `type`, a CSP nonce). The loader prints no tag
	 * and only the `before` data, so the page keeps WordPress's deferred tags in these cases.
	 *
	 * The tag filter is run on a plain tag: a filter that targets other handles returns it as is.
	 *
	 * @param \WP_Scripts $scripts Registry.
	 * @param string[]    $order   Handles the loader would inject.
	 */
	private static function loader_unsafe( \WP_Scripts $scripts, array $order ): bool {
		foreach ( $order as $handle ) {
			if ( '' !== trim( (string) $scripts->get_inline_script_data( $handle, 'after' ) ) ) {
				return true;
			}
			if ( '' !== (string) $scripts->get_data( $handle, 'data' ) ) {
				return true;
			}

			$src = (string) $scripts->registered[ $handle ]->src;
			$tag = '<script src="' . esc_url( $src ) . '" id="' . esc_attr( $handle ) . '-js"></script>' . "\n"; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- never printed: a probe of the script_loader_tag filter.
			if ( apply_filters( 'script_loader_tag', $tag, $handle, $src ) !== $tag ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's filter, as WordPress applies it.
				return true;
			}
		}

		return false;
	}

	/**
	 * The loader, a function expression called with the ordered scripts (`[{id, src}]`).
	 */
	private static function loader_js(): string {
		return '(function(list){'
			. 'var d=document,started=false;'
			. 'function shown(){d.documentElement.classList.add("lm-ready");}'
			. 'function add(){'
			. 'if(started){return;}started=true;'
			. 'list.forEach(function(item){var s=d.createElement("script");s.src=item.src;s.id=item.id;s.async=false;s.onerror=shown;d.body.appendChild(s);});'
			. '}'
			. 'function painted(){'
			. 'setTimeout(add,' . (int) self::LOADER_FALLBACK_MS . ');'
			. 'var P=window.PerformanceObserver,types=P&&P.supportedEntryTypes||[];'
			. 'if(types.indexOf("paint")<0){requestAnimationFrame(function(){requestAnimationFrame(add);});return;}'
			. 'if(performance.getEntriesByType("paint").length){add();return;}'
			. 'requestAnimationFrame(function(){requestAnimationFrame(function(){setTimeout(add,' . (int) self::LOADER_FRAME_GRACE_MS . ');});});'
			. 'var o=new P(function(){o.disconnect();add();});o.observe({type:"paint",buffered:true});'
			. '}'
			. 'if(d.readyState==="loading"){d.addEventListener("DOMContentLoaded",painted,{once:true});}else{painted();}'
			. '})';
	}

	/**
	 * Whether the rendered page holds an effect that needs the engine.
	 *
	 * The underline is drawn by the stylesheet alone: a page that only has underlines
	 * does not need GSAP. Neither do the elements the engine leaves alone: entrances played in
	 * CSS on load (`data-lm-load`) and settled text and counter effects of the first screen
	 * (`data-lm-settled`), see Marker::engine_families(). A page whose effects are all on the
	 * first screen loads no script; its styles stay inline.
	 */
	private function page_needs_engine(): bool {
		$families = array_diff( Marker::engine_families(), [ Effects::FAMILY_UNDERLINE ] );

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

		// The counter adapter and NumberFlow, where a counter is played (any element may become one
		// in the canvas). The engine finds window.lumiaMotionCounter whichever runs first.
		$counters = $canvas || in_array( Effects::FAMILY_COUNTER, Marker::engine_families(), true );
		if ( $counters && self::has_script( 'motion-counter.js' ) ) {
			wp_enqueue_script( self::HANDLE_COUNTER, $front . 'motion-counter.js', [], LUMIA_VERSION, self::footer_args() );
		}

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
