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
 *   at priority 10), and `Marker::page_families()` knows which effects are on it. On a
 *   public page the scripts are not printed as tags: an inline loader injects them once
 *   the page has painted, see print_loader(). In the canvas they are printed by WordPress
 *   at priority 20, as usual.
 *
 * The counter is the exception: its NumberFlow modules are WordPress script modules, which a
 * classic theme such as Bricks prints at `wp_footer` priority 10 (import map, then the modules).
 * `enqueue_counter()` runs at that same priority, after the popups of Bricks and before the
 * printing of core, see order_module_printing().
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

	/**
	 * Script modules of the counter: import map id => file under assets/front/js/vendor/.
	 * Dependencies first. esm-env resolves its conditional exports through the import map:
	 * a browser, neither in development nor Node.
	 */
	const COUNTER_MODULES = [
		'esm-env/browser'     => 'esm-env/true.js',
		'esm-env/development' => 'esm-env/false.js',
		'esm-env/node'        => 'esm-env/false.js',
		'esm-env'             => 'esm-env/index.js',
		'number-flow/lite'    => 'number-flow/lite.mjs',
	];

	/** Seconds after which the safety makes every element visible when the engine did not start. */
	const SAFETY_MS = 3000;

	/**
	 * Milliseconds after DOMContentLoaded after which the loader injects the scripts even
	 * without a paint: a tab opened in the background paints nothing until it is shown, and a
	 * browser with only `first-contentful-paint` waits for content on a page whose every
	 * element is an entrance hidden until the engine plays it.
	 */
	const LOADER_FALLBACK_MS = 1000;

	/** Handles of the scripts the loader may inject, in no particular order. */
	const LOADED_HANDLES = [
		self::HANDLE_GSAP,
		self::HANDLE_SCROLLTRIGGER,
		self::HANDLE_SPLITTEXT,
		self::HANDLE_LENIS,
		self::HANDLE_CORE,
		self::HANDLE_MOTION,
		self::HANDLE_SCROLL,
	];

	/**
	 * URL of the counter entry module on a public page, as core would print it.
	 *
	 * @var string
	 */
	private string $counter_url = '';

	/**
	 * URL of the counter entry module withheld from the footer, injected by the loader ('' when
	 * core prints it, in the canvas or when the import map is not printed in the footer).
	 *
	 * @var string
	 */
	private string $counter_src = '';

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
		add_action( 'wp_footer', [ $this, 'enqueue_counter' ], 10 );
		$this->order_module_printing();
	}

	/**
	 * Moves the printing of the script modules after enqueue_counter(), at the same priority.
	 *
	 * In a classic theme, core prints the import map, the modules and their preloads at
	 * `wp_footer` priority 10, hooked on `after_setup_theme`; Bricks renders its popups at
	 * that priority too, hooked earlier (theme load). The counter modules can only be
	 * enqueued once the popups are rendered (a counter may sit in one), and must be before
	 * core prints: an import map printed later is ignored by browsers once a module has
	 * started loading. Hooked on `init`, ours would run after core's printers, so these are
	 * hooked again, at the same priority, after ours. Only their order within priority 10
	 * changes; a block theme prints the import map in the head, nothing is moved there.
	 *
	 * Between the import map and the modules, withhold_counter() takes the counter entry out
	 * of the queue on a public page: the loader injects it after the first paint. Only when
	 * the import map is printed in the footer, so that it still lists the counter modules.
	 */
	private function order_module_printing(): void {
		if ( ! function_exists( 'wp_script_modules' ) ) {
			return;
		}

		$modules = wp_script_modules();
		foreach ( [ 'print_import_map', 'print_enqueued_script_modules', 'print_script_module_preloads' ] as $method ) {
			$callback = [ $modules, $method ];
			if ( 10 === has_action( 'wp_footer', $callback ) ) {
				remove_action( 'wp_footer', $callback, 10 );
				add_action( 'wp_footer', $callback, 10 );
				if ( 'print_import_map' === $method ) {
					add_action( 'wp_footer', [ $this, 'withhold_counter' ], 10 );
				}
			}
		}
	}

	/**
	 * Takes the counter entry module out of the queue of a public page, once the import map
	 * that lists its dependencies is printed, and keeps its URL for the loader.
	 *
	 * Printed as a tag, the module and its preloads would be requested while the page is
	 * parsed, before the first paint. Nothing is preloaded either: the preloads only list the
	 * dependencies of queued modules. The canvas keeps the tag.
	 */
	public function withhold_counter(): void {
		if ( is_admin() || self::is_builder_main() || self::is_builder_canvas() || ! function_exists( 'wp_dequeue_script_module' ) ) {
			return;
		}

		if ( '' === $this->counter_url ) {
			return;
		}

		wp_dequeue_script_module( self::HANDLE_COUNTER );
		$this->counter_src = $this->counter_url;
	}

	/**
	 * The NumberFlow modules and the counter adapter, on the pages that have a counter (always
	 * in the canvas, where any element may become one).
	 */
	public function enqueue_counter(): void {
		if ( is_admin() || self::is_builder_main() || ! function_exists( 'wp_register_script_module' ) ) {
			return;
		}

		$canvas = self::is_builder_canvas();
		if ( ! $canvas && ! in_array( Effects::FAMILY_COUNTER, Marker::page_families(), true ) ) {
			return;
		}

		if ( ! self::has_script( 'motion-counter.mjs' ) ) {
			return;
		}

		$vendor = LUMIA_ASSETS_URL . 'front/js/vendor/';
		foreach ( self::COUNTER_MODULES as $id => $file ) {
			if ( ! self::has_script( 'vendor/' . $file ) ) {
				return;
			}
			wp_register_script_module( $id, $vendor . $file, self::module_deps( $id ), LUMIA_VERSION );
		}

		$src = LUMIA_ASSETS_URL . 'front/js/motion-counter.mjs';
		wp_enqueue_script_module( self::HANDLE_COUNTER, $src, [ 'number-flow/lite' ], LUMIA_VERSION );

		if ( ! $canvas ) {
			// Same URL as core would print (WP_Script_Modules::get_src()).
			$src = apply_filters( 'script_module_loader_src', add_query_arg( 'ver', LUMIA_VERSION, $src ), self::HANDLE_COUNTER ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's filter.

			$this->counter_url = is_string( $src ) ? $src : '';
		}
	}

	/**
	 * Static imports of a counter module, as its file writes them.
	 *
	 * @param string $id Module id, a key of COUNTER_MODULES.
	 * @return string[]
	 */
	private static function module_deps( string $id ): array {
		$deps = [
			'esm-env'          => [ 'esm-env/browser', 'esm-env/development', 'esm-env/node' ],
			'number-flow/lite' => [ 'esm-env' ],
		];

		return $deps[ $id ] ?? [];
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
		$splitting = $canvas || in_array( Effects::FAMILY_TEXT, Marker::page_families(), true );

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
	 * nothing). Two frames alone were not enough: Chrome's paint entry carries the time the
	 * frame was presented, measured 2 ms after the second frame callback had injected the
	 * counter module. It then appends the scripts with `async = false`:
	 * they are fetched in parallel and run in order. A script that fails to load adds
	 * `lm-ready` at once, so nothing waits for the 3 s safety. The counter entry module
	 * (withhold_counter()) is injected first; its import map stays in the HTML.
	 */
	private function print_loader(): void {
		$scripts = wp_scripts();
		$queued  = array_values( array_intersect( $scripts->queue, self::LOADED_HANDLES ) );
		$order   = [];
		foreach ( $queued as $handle ) {
			self::resolve_script( $scripts, $handle, $order );
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

		if ( array() === $list && '' === $this->counter_src ) {
			return;
		}

		$flags  = JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES;
		$module = '' === $this->counter_src ? '' : esc_url_raw( $this->counter_src );

		wp_print_inline_script_tag(
			$before . self::loader_js() . '(' . wp_json_encode( $list, $flags ) . ',' . wp_json_encode( $module, $flags ) . ');',
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
		if ( in_array( $handle, $order, true ) || ! isset( $scripts->registered[ $handle ] ) ) {
			return;
		}

		foreach ( (array) $scripts->registered[ $handle ]->deps as $dep ) {
			self::resolve_script( $scripts, (string) $dep, $order );
		}

		$order[] = $handle;
	}

	/**
	 * The loader, a function expression called with the ordered scripts (`[{id, src}]`) and the
	 * URL of the counter entry module ('' when none).
	 */
	private static function loader_js(): string {
		return '(function(list,module){'
			. 'var d=document,started=false;'
			. 'function shown(){d.documentElement.classList.add("lm-ready");}'
			. 'function add(){'
			. 'if(started){return;}started=true;'
			. 'if(module){var m=d.createElement("script");m.type="module";m.src=module;m.id="' . self::HANDLE_COUNTER . '-js-module";d.body.appendChild(m);}'
			. 'list.forEach(function(item){var s=d.createElement("script");s.src=item.src;s.id=item.id;s.async=false;s.onerror=shown;d.body.appendChild(s);});'
			. '}'
			. 'function painted(){'
			. 'setTimeout(add,' . (int) self::LOADER_FALLBACK_MS . ');'
			. 'var P=window.PerformanceObserver,types=P&&P.supportedEntryTypes||[];'
			. 'if(types.indexOf("paint")<0){requestAnimationFrame(function(){requestAnimationFrame(add);});return;}'
			. 'if(performance.getEntriesByType("paint").length){add();return;}'
			. 'var o=new P(function(){o.disconnect();add();});o.observe({type:"paint",buffered:true});'
			. '}'
			. 'if(d.readyState==="loading"){d.addEventListener("DOMContentLoaded",painted,{once:true});}else{painted();}'
			. '})';
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
