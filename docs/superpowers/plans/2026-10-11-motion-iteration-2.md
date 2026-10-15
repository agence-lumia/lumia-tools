# Motion iteration 2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Apply the iteration 2 decisions to the Motion module: renamed effects plus a Blur effect, new curves and tempos, dynamic underline thickness, CSS load animations for the LCP, a clearer Bricks panel, and a NumberFlow counter.

**Architecture:** Same module (`includes/Modules/Motion/`, `assets/front/`). The effect catalog stays the single PHP source (`Effects::catalog()`); `motion-core.js` stays the single source of curve math. A new generator writes CSS `linear()` curve tokens from `motion-core.js` into `motion.css`; a new PHP class resolves load-animation values server-side; a new ES module adapts NumberFlow to the engine's counter family.

**Tech Stack:** PHP 8.0 (WordPress 6.9, Bricks 2.4.2), plain JS (no bundler), GSAP 3.15, NumberFlow 0.6.2 + esm-env 1.2.2 as native WP Script Modules, Node `node:test` for core tests, the CLI PHP runner `php tools/motion/php/run.php` (through Docker: `docker run --rm -v "$PWD:/app" -w /app composer:2 motion:test`), Playwright e2e in `tools/e2e/motion/`.

**Spec:** `docs/superpowers/specs/2026-10-11-motion-module-design.md`, section « Itération 2 » (it overrides sections 1–5 where they differ).

## Global Constraints

- Everything in code is English; French only in `languages/lumia-tools-fr_FR.po`. Every new or changed UI string: `composer i18n:pot`, add the French to the `.po`, `composer i18n:mo`, `composer i18n:check` (all through `docker run --rm -v "$PWD:/app" -w /app composer:2 <script>`).
- French labels to use, verbatim: effects Fondu, Montée, Flou, Flou montant, Zoom, Zoom flou, Lignes, Mots au défilement, Lettres au défilement, Compteur, Soulignement au survol; curves Douce, Vive, Rebond, Linéaire, Personnalisée; tempos Très rapide, Rapide, Normal, Lent, Très lent, Personnalisé.
- No alias for the v1 `lm-*` names (never released). Legacy `sk-*` names only in `includes/Modules/Motion/Legacy.php`, `tools/motion/`, `tools/e2e/motion/`.
- Third-party files copied unmodified (vendor folders), licences kept, SHA-256 listed in `docs/modules/motion.md`.
- Never leave content at `opacity: 0`: every failure path ends in the final state.
- `prefers-reduced-motion: reduce`: no animation, no pre-hide, counters at their final value.
- Before each commit: `composer check` clean (Docker), `node --test tools/motion/js/` green, PHP runner green.
- Conventional Commits in English, scope `motion`, ending with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Bricks source for verification: `/private/tmp/claude-501/-Users-alban-Documents-DEV-init-vps/4facac7a-2892-4aad-9a93-fc6c9cad2988/scratchpad/local-lab/site/wp-content/themes/bricks/` (read-only). Local site: http://localhost:8088, `local-admin` / `local-admin`; WP-CLI via `scratchpad/local-lab/wp.sh … --user=local-admin`; the repo is mounted as the plugin.

## Review Focus

1. A page whose first section is very tall, or whose first root element is not a `section` (container, div, template, popup) — the load zone must still be exactly the first root element of the content and nothing after it.
2. An element with `--lm-load: never` inside the first section, and `always` far below — must follow the normal scroll path / animate on load respectively.
3. Counters with prefixes, suffixes, decimals and custom group separators (`1 250`, `98,5 %`, `+150 clients`, `2024`) — final text and separators identical to the HTML, no group added to `2024`.
4. NumberFlow module failing to load (404, old browser, `canAnimate` false) — the counter keeps its original text, never stuck at the start value.
5. Settings saved during the trials with `ease: lumia` or tempo `exact` — read as `smooth` / `custom`, never an error.

Each item has a test in the owning task (Tasks 2, 5, 7).

---

### Task 1: Rename effects and add the Blur effect

**Files:**
- Modify: `includes/Modules/Motion/Effects.php` (catalog 78-87, `reference_values()` 141-170), `Legacy.php` (`CLASS_ALIASES` 17-28), `Controls.php` (`effect_options()` 458-470), `settings-template.php` (`$effect_labels` 71-81, the `text-lines` distance special case ~221), `assets/front/js/motion-core.js` (`EFFECTS` 341-352), `assets/front/js/motion.js` (`enterFrom()` 383-396, `chars` flag 800), `assets/front/css/motion-builder.css` (all `.lm-thumb--<slug>` rules and `lm-thumb-<slug>` keyframes), `docs/modules/motion.md`, `languages/*`
- Test: `tools/motion/php/EffectsTest.php`, `tools/motion/php/ClassManagerTest.php`, `tools/motion/js/core.test.mjs`, `tools/e2e/motion/fixtures.mjs`, `assert-migration.php` (table 32-41), `scenarios/builder-preview.mjs` (label at 256), `scenarios/enter.mjs`, `scenarios/text.mjs`

**Interfaces:**
- Produces: slugs `fade, slide-up, blur, blur-up, zoom, zoom-blur, lines, words, letters, counter, underline`; classes `lm-<slug>`; English labels Fade, Slide up, Blur, Blur up, Zoom, Zoom blur, Lines, Words on scroll, Letters on scroll, Counter, Hover underline.

Rename map (old → new): `reveal→slide-up`, `blur-in→blur-up`, `scale-in→zoom`, `scale-blur-in→zoom-blur`, `text-lines→lines`, `text-words→words`, `text-chars→letters`. Aliases: `sk-reveal→slide-up`, `sk-entry-blur→blur-up`, `sk-scale-in→zoom`, `sk-scale-in-blur→zoom-blur`, `sk-text-lines→lines`, `sk-text-highlight-words→words`, `sk-text-highlight-chars→letters` (others unchanged). New `blur`: family enter, not scrub, params `['blur']`, reference values duration 0.8, stagger 0.08, blur 12.

- [ ] **Step 1: Update the tests first**: `EffectsTest` slug list (11 slugs in the order above), `blur` family `enter` with params `['blur']` and reference `{duration:0.8, stagger:0.08, blur:12.0}`, `Legacy::new_class_name('sk-entry-blur') === 'lm-blur-up'`; `core.test.mjs` reference config and a case `resolve('blur', {}, config)` → `distance` 0 and `blur` 12; `assert-migration.php` table; fixtures and scenarios use the new slugs/classes (keep `sk-*` legacy cases as they are).
- [ ] **Step 2: Run** `node --test tools/motion/js/` and the PHP runner. Expected: FAIL on the new slugs.
- [ ] **Step 3: Implement** the rename everywhere listed in Files; `enterFrom()`: `slide-up`/`blur-up` move y, `zoom`/`zoom-blur` scale, `blur`/`blur-up`/`zoom-blur` filter blur, `fade` default; `chars = o.effect === "letters"`. Builder thumbnail for `blur`: copy of the `blur-up` thumbnail without the translation.
- [ ] **Step 4: Run** both suites. Expected: PASS. `grep -rnE "reveal|blur-in|scale-in|scale-blur-in|text-lines|text-words|text-chars" includes assets tools docs/modules` returns only `sk-*` legacy names and unrelated words.
- [ ] **Step 5: i18n** (labels above, French from Global Constraints), `composer check`.
- [ ] **Step 6: e2e** `tools/e2e/motion/run.sh setup` then `run.sh enter text builder-preview`. Expected: `ok` for each.
- [ ] **Step 7: Commit** `feat(motion): rename effects to plain names and add a Blur effect`

### Task 2: Curves and tempos

**Files:**
- Modify: `Effects.php` (`TEMPOS` 20-24, `TEMPO_KEYWORDS` 27, `EASES` 30), `SettingsSanitizer.php` (64-75), `Module.php` (defaults 78-80), `Controls.php` (`_lmTempo` 252-268, `_lmDuration` required 277, `_lmEase` 301-319), `settings-template.php` (49-62, 142-165 including the description texts), `motion-core.js` (216-217, 261, 284, 326-329, 505-541), `docs/modules/motion.md` (34-40), `languages/*`
- Test: `EffectsTest.php` 130-138, `SettingsSanitizerTest.php` 155-205, `MarkerTest.php` 104, `core.test.mjs` (137-254, 337-380), `fixtures.mjs` (108, 110, 124, 217), `scenarios/builder-panel.mjs` 48, `scenarios/counter.mjs` 131-135

**Interfaces:**
- Produces: `Effects::TEMPOS = ['very-fast'=>0.5,'fast'=>0.75,'normal'=>1.0,'slow'=>1.5,'very-slow'=>2.0]`; `Effects::TEMPO_KEYWORDS = [...keys of TEMPOS, 'custom']`; `Effects::EASES = ['smooth','snappy','bouncy','linear','custom']`; JS `BOUNCE = {smooth:0, snappy:0.25, bouncy:0.45}`, `TEMPOS` same as PHP; `tempoFactor('custom') === null`.
- Legacy values: `lumia` → `smooth`, `exact` → `custom`, in the sanitizer (stored settings) and in `motion-core.js` `resolve()` (typed values from classes saved during trials), without a warning.

- [ ] **Step 1: Tests**: sanitizer `ease:'lumia'` → `'smooth'`; custom ease with invalid curve → `'smooth'`; global tempo `'custom'` refused → `'normal'` (as `exact` was); `tempoFactor` for each of the five keys; `resolve('fade', {tempo:'exact', duration:'2'}, config).duration === 2`; `resolve('fade', {ease:'lumia'}, config).easeKey === 'smooth'`; spring overshoot of `bouncy` strictly greater than `snappy`, itself greater than 0; counter with `bouncy` → `easeKey 'smooth'` (existing test kept).
- [ ] **Step 2: Run**, expect FAIL.
- [ ] **Step 3: Implement.** Labels: tempo `Very fast, Fast, Normal, Slow, Very slow, Custom`; ease `Smooth, Snappy, Bouncy, Linear, Custom` (drop the `Lümia` ease option; the shared admin string "Lümia" stays). Settings descriptions rewritten to the new multipliers (×0.5 … ×2) and curves. Remove the `bezier(0.625,0.05,0,1)` lumia profile from `motion-core.js`.
- [ ] **Step 4: Run** both suites, PASS. **Step 5:** i18n + `composer check`. **Step 6:** e2e `run.sh enter counter builder-panel`. **Step 7: Commit** `feat(motion): smooth, snappy, bouncy curves and five tempos`

### Task 3: CSS curve tokens generated from motion-core

**Files:**
- Create: `tools/motion/js/build-curves.mjs`, `tools/motion/js/curves.test.mjs`
- Modify: `assets/front/js/motion-core.js` (new export), `assets/front/css/motion.css` (generated block), `docs/modules/motion.md`

**Interfaces:**
- Produces: `lumiaMotionCore.cssLinear(bounce: number, points?: number) -> { linear: string, settle: number }` — `linear` is a CSS `linear(...)` easing of the spring over its whole settle time (values rounded to 4 decimals, at most `points` stops, default 40, simplified by dropping stops that lie within 0.002 of the straight line between their neighbours); `settle` is the tween length divided by the perceived duration (same value as `spring(bounce).settle`).
- Produces in `motion.css`, between `/* lm-curves:start */` and `/* lm-curves:end */`, on `:root`: `--lm-curve-smooth`, `--lm-curve-snappy`, `--lm-curve-bouncy` (the `linear()` strings) and `--lm-settle-smooth`, `--lm-settle-snappy`, `--lm-settle-bouncy` (unitless numbers). Each curve token is preceded by a fallback declaration `cubic-bezier(0.22, 1, 0.36, 1)` for browsers without `linear()`, using `@supports (animation-timing-function: linear(0, 1))` around the `linear()` values.
- `node tools/motion/js/build-curves.mjs` rewrites the block; `--check` exits 1 if the file differs.

- [ ] **Step 1: Test** `curves.test.mjs`: `cssLinear(0)` starts with `linear(0` and ends with `1)`; `cssLinear(0.45).linear` contains a value > 1 (overshoot); sampling `cssLinear(b)` at its stops matches `spring(b).ease` at the same progress within 0.003 for b in {0, 0.25, 0.45}; running `build-curves.mjs --check` against the committed `motion.css` exits 0.
- [ ] **Step 2: Run** `node --test tools/motion/js/`, FAIL. **Step 3: Implement**, then run the generator once. **Step 4:** PASS. **Step 5:** add the `--check` line to the tests section of `docs/modules/motion.md`. **Step 6: Commit** `feat(motion): CSS linear() curve tokens generated from the engine springs`

### Task 4: Dynamic underline thickness

**Files:**
- Modify: `assets/front/css/motion.css` (underline `::after` height, line ~164), `includes/Modules/Motion/Legacy.php` (`underline_css()` and the default `'thickness' => '2px'` at 52), `Controls.php` (`_lmUnderlineThickness` placeholder/description), `docs/modules/motion.md`, `languages/*`
- Test: `tools/motion/php/LegacyTest.php` (76-80 keeps the mirror check)

**Interfaces:** default thickness token `round(max(1px, 0.06em), 1px)` with a preceding `1px` declaration as fallback for browsers without `round()`.

- [ ] **Step 1: Test** `LegacyTest`: generated legacy CSS contains `round(max(1px, 0.06em), 1px)` and a `1px` fallback before it; the mirror check with `motion.css` still passes.
- [ ] **Step 2: Run**, FAIL. **Step 3: Implement**: `height: var(--lm-underline-thickness, 1px); height: var(--lm-underline-thickness, round(max(1px, 0.06em), 1px));`, same order in the legacy chain (`--lm-underline-thickness`, then `--sk-hover-underline-thickness`, then the default). Control description: "Line thickness. Empty = scales with the text size (1 px for body text, about 2 px on a 32 px title)." **Step 4:** PASS. **Step 5:** i18n, `composer check`. **Step 6: Commit** `feat(motion): underline thickness follows the text size`

### Task 5: Load animations for the first screen (LCP)

**Files:**
- Create: `includes/Modules/Motion/LoadAnimation.php`, `tools/motion/php/LoadAnimationTest.php`, `tools/e2e/motion/scenarios/load.mjs`
- Modify: `Marker.php` (`filter_attributes()` 86, families), `Effects.php` (`ENGINE_VARIABLES` + `load`), `Controls.php` (new `_lmLoad`), `assets/front/css/motion.css` (`@property --lm-load`, pre-hide exclusion, keyframes), `assets/front/js/motion.js` (`skipReason` 263), `tools/e2e/motion/fixtures.mjs` (new page), `docs/modules/motion.md`, `languages/*`

**Interfaces:**
- Consumes: Task 3 tokens `--lm-curve-*`, `--lm-settle-*`; Task 1 slugs; Task 2 `Effects::TEMPOS`.
- Produces:
  - Control `_lmLoad` → `--lm-load`, select `auto` (Auto, default, placeholder) / `always` (Always) / `never` (Never), in the Trigger section.
  - `LoadAnimation::zone_tracker()`-style state inside the class: `LoadAnimation::observe(array $element, string $area): bool` returns true when the element is in the load zone (area `header`, or a descendant-or-self of the **first root element** (parent `0`) rendered in area `content`). Track zone ids in a static set: an element is in the zone if its `parent` id is in the set.
  - `LoadAnimation::attributes(string $slug, array $settings, array $config): array` → `['data-lm-load' => $slug, 'style' => '--lm-load-duration:…s;--lm-load-move-duration:…;--lm-load-delay:…s;--lm-load-ease:…;--lm-load-safe-ease:…;--lm-distance:…px;--lm-blur:…px;--lm-scale:…']`. `$settings` is the merged base-breakpoint settings (element over its global classes, in class order); `$config` is `['effects'=>reference values merged with module overrides, 'tempo'=>…, 'ease'=>…, 'ease_custom'=>…]`. Duration = reference (or typed if tempo `custom`) × tempo factor; delay = delay + rank × stagger × tempo factor, rank counting zone elements with the same slug **and** same settings already rendered; ease `var(--lm-curve-<key>)` (`linear` → `linear`, custom → the cubic-bezier string); safe ease always `var(--lm-curve-smooth)` unless ease is `linear`/custom (then same as ease); move duration `calc(<duration>s * var(--lm-settle-<key>))` for springs, else equal to duration.
  - Marker: for an element of family `enter` (not in the builder: `bricks_is_builder_iframe()`/`bricks_is_builder()` false) with `--lm-load` base value `always`, or `auto` and in the zone → merge `LoadAnimation::attributes()` into the root attributes (style merged with `merge_style()`); `never` → unchanged.
  - CSS: `[data-lm-load]` excluded from the pre-hide rule; per slug `@keyframes lm-load-<slug>-move` (transform: translateY(var(--lm-distance)) / scale(var(--lm-scale)) → none) and `lm-load-fade` (opacity 0 → 1) / `lm-load-blur` (filter blur(var(--lm-blur)) → blur(0)), applied as `animation: <move> var(--lm-load-move-duration) var(--lm-load-ease) var(--lm-load-delay) both, lm-load-fade var(--lm-load-duration) var(--lm-load-safe-ease) var(--lm-load-delay) both[, lm-load-blur …]`; nothing under `prefers-reduced-motion: reduce`.
  - Engine: `skipReason` returns `"load"` for `[data-lm-load]` (no ScrollTrigger, no pre-set state).

Bricks area: find in the Bricks source how the current render area is known during `bricks/element/render_attributes` (e.g. `\Bricks\Frontend::$area` or the `$element->area`/template type) and use it; document the finding in `docs/modules/motion.md`.

- [ ] **Step 1: PHP tests** `LoadAnimationTest`: first root in `content` and its descendants in zone, the second root and its descendants not; a `header` element in zone; `slide-up` default settings → `--lm-load-duration:0.65s`, delay `0s`, ease `var(--lm-curve-smooth)`; tempo `slow` → `0.975s`; third same-signature element → delay `0.2s` (stagger 0.1); ease `bouncy` → move duration `calc(0.65s * var(--lm-settle-bouncy))` and safe ease smooth; custom tempo with typed duration 2 → `2s`. Marker test: `never` in zone → no `data-lm-load`; `always` outside zone → present.
- [ ] **Step 2: Run**, FAIL. **Step 3: Implement.** **Step 4:** PASS, `composer check`.
- [ ] **Step 5: e2e** `scenarios/load.mjs` with a fixture page: hero section (heading `lm-blur-up`, text `lm-fade`, one element `_lmLoad: never`), a tall second section with an `always` element. Assertions: on the first animation frame (`page.goto` with `waitUntil:'commit'`, then sample) hero elements have computed `animation-name` set and are never at `opacity` < 0.01 after 100 ms; `never` element is pre-hidden then animated by the engine on scroll; `always` element has `data-lm-load`; second-section elements have no `data-lm-load`; with `reducedMotion:'reduce'` no animation. Run `run.sh setup load enter loading`, all `ok`.
- [ ] **Step 6:** i18n (`Auto`, `Always`, `Never`, description), docs (zone rule, limits from the spec). **Step 7: Commit** `feat(motion): first-screen elements animate in CSS from the first frame`

### Task 6: Bricks panel

**Files:**
- Create: `assets/front/js/motion-panel.js` (builder main window)
- Modify: `Controls.php` (`add_group` 57 icon, `definitions()` order, separators, descriptions, placeholders), `Assets.php` (enqueue `motion-panel.js` in the builder main window only), `assets/front/css/motion-builder.css` (rail icon), `assets/front/js/motion.js` (public API), `assets/front/js/motion-builder.js`, `tools/e2e/motion/assert-controls.php`, `scenarios/builder-panel.mjs`, `scenarios/builder-preview.mjs`, `languages/*`

**Interfaces:**
- Produces in `window.lumiaMotion` (canvas): `replay(id: string) -> boolean` (replays the element whose Bricks id is `id`, builder only, false if unknown); `markers(on: boolean) -> void` (rebuilds the triggers with `markers: on`, builder only); `inspect(id: string) -> object|null` (resolved options of that element: `duration, delay, stagger, distance, blur, scale, fromOpacity, startValue, tempo, easeKey`).
- Separators, in order: Effect (`_lmEffect`, help info with the two buttons), Trigger (`_lmLoad`, start edge/at, end edge/at, advanced start/end), Timing (`_lmTempo`, `_lmDuration`, `_lmDelay`, `_lmStagger`), Feel (`_lmEase`, `_lmEaseCustom`), Effect settings (distance, blur, scale, from opacity, start value, counter width from Task 7), Underline (`required` `_lmEffect = underline`).
- Descriptions (English, with unit), at least: Stagger "Time between two elements that enter together, in seconds."; Scale "Starting size from 0 to 1: 0.9 starts at 90%."; Distance "Starting offset, in px."; Blur "Starting blur, in px."; From opacity "Starting opacity of the words or letters, from 0 to 1."; Start "The animation starts when this edge of the element reaches this height of the screen (0% = top, 100% = bottom)."; Delay "Wait before the animation, in seconds."; Duration "Exact duration, in seconds."; Load "Auto: animated as soon as the page opens when the element is in the header or the first section."
- Help info: buttons `<button type="button" class="lm-panel-replay">Replay</button>` and a checkbox `<label><input type="checkbox" class="lm-panel-markers"> Show markers</label>`.
- `motion-panel.js` (main window): delegated click on `.lm-panel-replay` → active element id from the Bricks builder state (find the accessor in the Bricks source, e.g. the Vue global state's active element) → `iframe#bricks-builder-iframe`.contentWindow.lumiaMotion.replay(id). `.lm-panel-markers` change → `localStorage['lmMotionMarkers']` (`'1'`/absent, wrapped in try/catch) and `lumiaMotion.markers(checked)`; the canvas engine reads that key at start in builder mode. On active element change: fill the `placeholder` of the empty `_lmDelay`, `_lmStagger`, `_lmDuration`, `_lmDistance`, `_lmBlur`, `_lmScale`, `_lmFromOpacity`, `_lmStartValue` inputs from `lumiaMotion.inspect(id)`; static placeholders in `Controls.php` stay as fallback.
- Rail icon: group `'icon' => 'star'` in `add_group`; `motion-builder.css` overlays Lucide `sparkles` (official lucide-static 1.34.0 SVG, as a CSS mask data URI) on that group's rail button, hiding the native glyph. Verify the rail markup in the Bricks source/builder DOM.

- [ ] **Step 1: Tests**: `assert-controls.php` expects the new order, six separators, `_lmLoad`, descriptions non-empty on the listed controls, group icon `star`; `builder-panel.mjs`: the Motion rail button shows the sparkles mask; clicking Replay changes `lumiaMotion._debug.stats().replays` by 1; checking Show markers adds `.gsap-marker-start` elements in the canvas, unchecking removes them, and the state survives a builder reload; an empty Stagger input shows the class value as placeholder for an element with class `lm-e2e-preset`.
- [ ] **Step 2: Run**, FAIL. **Step 3: Implement.** **Step 4:** `run.sh builder-panel builder-preview` ok, `assert-controls.php` ok, `composer check`. **Step 5:** i18n. **Step 6: Commit** `feat(motion): clearer Bricks panel with sections, units, replay and markers`

### Task 7: NumberFlow counter

**Files:**
- Create: `assets/front/js/vendor/number-flow/` (`lite.mjs`, `index.mjs`, `plugins.mjs`, `csp.mjs`, `group.mjs`, `ssr-DvIINv8w.mjs`, `LICENSE`), `assets/front/js/vendor/esm-env/` (`index.js`, `true.js`, `false.js`, `browser-fallback.js`, `dev-fallback.js`, `LICENSE`), `assets/front/js/motion-counter.mjs`, `tools/e2e/motion/scenarios/counter.mjs` (rewrite)
- Modify: `Assets.php`, `Effects.php` (`ENGINE_VARIABLES` + `counter-width`; `counter` params add `counter_width`), `Controls.php` (`_lmCounterWidth` → `--lm-counter-width`, select `auto` Dynamic width (default) / `fixed` Fixed width), `motion.css` (`@property`, the no-CLS rule, remove the `[data-lm~="counter"]{font-variant-numeric:tabular-nums}` rule), `motion.js` (counter family 890-1047), `motion-core.js` (if needed: `parseCounter` unchanged), `tools/build/zip-excludes.txt` / build script (`.mjs` must be shipped and not minified if esbuild cannot), `docs/modules/motion.md` (files, SHA-256, licences), `languages/*`

Sources (unmodified copies): `scratchpad/nf/nfpkg/package/dist/*.mjs` and `LICENSE` (number-flow 0.6.2), `scratchpad/nf/esm/package/*.js` and `LICENSE` (esm-env 1.2.2); scratchpad = `/private/tmp/claude-501/-Users-alban-Documents-DEV-init-vps/4facac7a-2892-4aad-9a93-fc6c9cad2988/scratchpad`.

**Interfaces:**
- Consumes: `lumiaMotionCore.parseCounter`, `cssLinear` (Task 3), resolved options (`tweenDuration`, `easeKey`, `startValue`, `delay`).
- Produces:
  - Script modules, registered only when the page has the `counter` family (and always in the canvas): `esm-env/browser`→`true.js`, `esm-env/development`→`false.js`, `esm-env/node`→`false.js`, `esm-env`→`index.js`, `number-flow/lite`→`lite.mjs`; `wp_enqueue_script_module('lumia-motion-counter', …/motion-counter.mjs, ['number-flow/lite'])`. `.mjs` served as JavaScript (check the local Apache; document the MIME requirement).
  - `motion-counter.mjs` sets `window.lumiaMotionCounter = { mount(el, fmt, opts) -> handle|null, play(handle, opts) -> void, reset(handle) -> void }` then dispatches `document` event `lumia-motion-counter-ready`. `opts = { from:number, fixed:boolean, duration:number(s), delay:number(s), easing:string(CSS linear()) }`. `mount` returns null when `canAnimate` is false.
  - Adapter rules (from the validated prototype): `define('lm-number-flow', NumberFlowLite)`; formatter = `Intl.NumberFormat('en-US', {min/maxFractionDigits: fmt.decimals, useGrouping: fmt.group !== '', minimumIntegerDigits: fixed ? integer digit count of the final value : 1})` whose `formatToParts` replaces `group`/`decimal` parts by `fmt.group`/`fmt.decimal`; data via `formatToData(v, formatter, fmt.prefix, fmt.suffix)`; fixed mode sets `font-variant-numeric: tabular-nums` on the element; mount writes `animated=false` + start data, play sets `transformTiming`/`spinTiming` `{duration: ms, easing}`, `opacityTiming` unchanged, then after two animation frames `animated=true` and final data. The element is kept after the animation (no swap back to text).
  - Engine counter family: wait for `window.lumiaMotionCounter` (or the ready event) up to the 3 s safety; without it, or `mount` null, or reduced motion → original text untouched (final value). Remove `counterWrap`'s `.lm-sr` copy and the GSAP number tween; keep `counterSource()` / `parseCounter`. Easing: `cssLinear(BOUNCE[easeKey]).linear` where `easeKey` is already `smooth` for counters; `linear` → `'linear'`; custom → the cubic-bezier string.
  - CSS: `lm-number-flow{line-height:inherit;vertical-align:top;margin-block:calc(-2 * round(nearest, calc(var(--number-flow-mask-height, .25em) / 2), 1px))}`.

- [ ] **Step 1: e2e test** `counter.mjs` (fixture page with `1 250`, `98,5 %`, `+150 clients`, `2024`, each in dynamic and fixed width): final text content of the accessible name (`el.querySelector('lm-number-flow')` `ariaLabel` via `page.accessibility.snapshot()`) equals the HTML text; element height constant across 60 sampled frames in both modes; in fixed mode the `getBoundingClientRect().width` of the `lm-number-flow` constant across frames; `2024` never shows a group separator; blocking `**/number-flow/lite.mjs` with `page.route` → text stays the original HTML; reduced motion → original text, no `lm-number-flow`; a page without counter loads no `number-flow` or `esm-env` request.
- [ ] **Step 2: Run** `run.sh setup counter`, FAIL. **Step 3: Implement** (vendor copy, SHA-256 into the docs, assets, adapter, engine). **Step 4:** `run.sh counter loading enter` ok; check the zip: `tools/build/build-zip.sh` output contains the `.mjs` files byte-identical for the vendor ones. **Step 5:** SEO check: fetch the page HTML without JS (`curl`) and confirm the number is in it; check Google's view by rendering with Playwright and reading `document.body.innerText` (shadow DOM text excluded) — if the number is absent from `innerText`, add a visually hidden copy of the original text (`.lm-sr`) next to the element and record the result in the docs. **Step 6:** i18n, `composer check`. **Step 7: Commit** `feat(motion): counters roll with NumberFlow, dynamic or fixed width`

### Task 8: Docs, full verification, performance

**Files:**
- Modify: `docs/modules/motion.md`, `README.md` (Motion section: class examples `lm-fade`, `lm-slide-up`; tempos; curves Smooth/Snappy/Bouncy/Linear/Custom; NumberFlow), `tools/e2e/motion/README.md`

- [ ] **Step 1:** Docs and README brought in line with the spec iteration 2 (names table, curves, tempos, load animations and their limits, counter, panel).
- [ ] **Step 2:** Full checks: `composer check`, `composer i18n:check`, PHP runner, `node --test tools/motion/js/`, `node tools/motion/js/build-curves.mjs --check`, `tools/e2e/motion/run.sh all`, `assert-controls.php`, `assert-migration.sh`. All green.
- [ ] **Step 3:** Lighthouse mobile, home and contact, against the local site with gzip (runner: `scratchpad/lh/run.sh`), compare with the old snippet figures (home LCP 2.58 s, contact 1.97 s). Pass: LCP ≤ old + 0.2 s on both. Record the figures in `docs/modules/motion.md`.
- [ ] **Step 4: Commit** `docs(motion): iteration 2 documentation`
