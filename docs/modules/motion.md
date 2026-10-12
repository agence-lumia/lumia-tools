# Motion module

`includes/Modules/Motion/`. Settings stored under `lumia_module_motion`. Replaces the former "SK Motion" FluentSnippets snippet (GSAP from a CDN on every page) with entrance and scroll effects for Bricks, adjustable per element. The module does nothing unless Bricks is the active theme.

| File | Role |
|---|---|
| `Effects.php`, `Legacy.php` | Effect catalog and reference values; every former name (`sk-*`, `--sk-hover-underline-*`, the former global and snippet name) lives in `Legacy.php` only |
| `SettingsSanitizer.php`, `settings-template.php` | Admin screen: default tempo and curve, reference values per effect, Lenis, builder preview, compatibility |
| `Controls.php` | "Motion" group on the Style tab of every Bricks element |
| `Marker.php` | `data-lm` on animated elements, inline overrides where the class would win, families used by the page |
| `Assets.php` | Conditional loading, inline styles, loader of the engine after the first paint, configuration JSON |
| `ClassManager.php`, `Command.php` | `lm-*` global classes, `wp lumia motion classes|migrate` |
| `assets/front/js/motion-core.js` | Pure helpers (curves, springs, settings resolution, counter), unit tested with `node --test` |
| `assets/front/js/motion.js`, `motion-scroll.js`, `motion-builder.js` | Engine, Lenis, builder preview |
| `assets/front/js/motion-counter.mjs` | Counter adapter (script module): NumberFlow behind `window.lumiaMotionCounter` |
| `assets/front/js/motion-panel.js` | Builder main window: Replay, "Show markers", inherited placeholders |

## Using it in Bricks

- **One click:** add a global class from the "Motion" category (`lm-fade`, `lm-slide-up`, `lm-blur`, `lm-blur-up`, `lm-zoom`, `lm-zoom-blur`, `lm-lines`, `lm-words`, `lm-letters`, `lm-counter`, `lm-underline`).
- **Per element:** Style tab > Motion. Every control writes a `--lm-*` CSS variable, so the usual Bricks cascade applies: element > class > module defaults (passed as JSON, not through `:root`). Breakpoint values work like any Bricks control.
- **One effect per element.** With two Motion classes, the first one in the class list wins (`?lmDebug` logs it).
- **Typed class names count too:** a Motion class typed in the element's "CSS classes" field (`_cssClasses`) is marked like a global class (`lm-*` always, `sk-*` while the compatibility setting is on). Before, `Marker` read only the global classes, so a typed name was never pre-hidden and did not load the engine on its own.
- **LCP:** entrance effects in the header or the first section play in CSS from the first frame (see "Load animations" below), so they never wait for the scripts. Elsewhere, entrance effects pre-hide the element until the engine is ready; text and counters are never pre-hidden.
- **On load** (`_lmLoad`, `--lm-load`): `auto` (default), `always` (played on load wherever the element is), `never` (always the scroll path). Only the base value counts (element, else its first class with one); breakpoint values are ignored.

### Names

| Class / `--lm-effect` | Admin label (fr) | Family | Settings it reads |
|---|---|---|---|
| `lm-fade` | Fondu | entrance | none |
| `lm-slide-up` | Montée | entrance | distance |
| `lm-blur` | Flou | entrance | blur |
| `lm-blur-up` | Flou montant | entrance | distance, blur |
| `lm-zoom` | Zoom | entrance | scale |
| `lm-zoom-blur` | Zoom flou | entrance | scale, blur |
| `lm-lines` | Lignes | text | distance |
| `lm-words` | Mots au défilement | text, scrub | start opacity |
| `lm-letters` | Lettres au défilement | text, scrub | start opacity |
| `lm-counter` | Compteur | counter | start value, width |
| `lm-underline` | Soulignement au survol | underline (CSS) | `--lm-underline-*` |

| Tempo | Factor | Label (fr) | Curve | Label (fr) |
|---|---|---|---|---|
| `very-fast` | ×0.5 | Très rapide | `smooth` (default) | Douce |
| `fast` | ×0.75 | Rapide | `snappy` | Vive |
| `normal` | ×1 | Normal | `bouncy` | Rebond |
| `slow` | ×1.5 | Lent | `linear` | Linéaire |
| `very-slow` | ×2 | Très lent | `custom` | Personnalisée |
| `custom` | typed duration | Personnalisé | | |

The names of the first trials (before iteration 2) have no alias: the module was never released with them. The only former names still understood are the `sk-*` ones, in `Legacy.php`.

### Variables

Engine variables are registered with `@property … inherits: false`, so a child never inherits its section's settings: `--lm-effect`, `--lm-start-edge`, `--lm-start-at`, `--lm-end-edge`, `--lm-end-at`, `--lm-start`, `--lm-end`, `--lm-tempo`, `--lm-duration`, `--lm-delay`, `--lm-stagger`, `--lm-ease`, `--lm-ease-custom`, `--lm-distance`, `--lm-blur`, `--lm-scale`, `--lm-from-opacity`, `--lm-start-value`, `--lm-counter-width`, `--lm-load`. `tools/motion/php/EffectsTest.php` checks that the `@property` list of `motion.css` is `Effects::ENGINE_VARIABLES`, in order.

The underline variables are **inheritable** on purpose (a wrapper can restyle every link inside it): `--lm-underline-thickness` (`round(max(1px, 0.06em), 1px)`, so about 1 px on body text and 2 px on a 32 px title; the base rule declares `1px` and the `round()` variant sits in an `@supports (height: round(1px, 1px))` block, because a second `var()` declaration would win everywhere and compute to `auto` without `round()`), `--lm-underline-gap` (0.12em), `--lm-underline-color` (currentColor), `--lm-underline-duration` (0.4s). Never declare them with `@property`.

While the compatibility setting is on, the former `--sk-hover-underline-*` variables are read as a second fallback (`Legacy::underline_css()`, printed after `motion.css`): the drawing rules are repeated with `var(--lm-underline-gap, var(--sk-hover-underline-gap, 0.12em))` and so on. They sit on the drawing rules, not on the former class: a first version assigned `--lm-underline-*` on `.sk-hover-underline`, which no element carries once the migration has renamed the class, and which hid a value inherited from a wrapper. `tools/motion/php/LegacyTest.php` checks that the fallbacks still mirror `motion.css`.

Positions accept ScrollTrigger syntax only (`top 80%`, `center center`, `bottom-=100`); the same rule is tested in PHP and JS from `tools/motion/positions.json`. Durations accept `s` and `ms`. An inline override (`Marker::VALUE_PATTERN`) keeps `+` and `=` for relative positions (`top+=50 80%`) and refuses `;`, `:`, quotes, `<` and braces.

### Tempo and curves

Two independent settings, so an element can change its timing or feel without changing its effect:

- **Tempo:** `very-fast` ×0.5, `fast` ×0.75, `normal` ×1, `slow` ×1.5, `very-slow` ×2 on the duration and the stagger, never on the delay. `custom` uses `--lm-duration` (a typed duration is ignored otherwise; `?lmDebug` says so). The value `exact` of the first trials is still read as `custom`.
- **Curve:** `smooth` (default), `snappy`, `bouncy` (SwiftUI-style springs, bounce 0 / 0.25 / 0.45, computed in `motion-core.js`, no dependency), `linear`, `custom` (`--lm-ease-custom`, a `cubic-bezier()` with x1 and x2 in [0, 1]; invalid → `smooth`). The `lumia` curve of the first trials is still read as `smooth`.
- Opacity and blur always use a no-overshoot variant of the curve; the counter never overshoots whatever the curve; scrub effects (`words`, `letters`) ignore tempo and curve.

### Load animations

Entrance effects (`fade`, `slide-up`, `blur`, `blur-up`, `zoom`, `zoom-blur`) of the first screen are played by `motion.css` from the first frame instead of waiting for the deferred engine: a pre-hidden hero made Chrome pick an invisible LCP, or paint it only after the scripts.

- **Zone** (`LoadAnimation::observe()`, called by `Marker` for every rendered element): every element of the header area, and the first root element (parent `0`) rendered in the content area with all its descendants. A child is in the zone when its parent is (Bricks prints a nestable's root attributes before rendering its children, measured in `container.php` 2.4.2); a component child follows its instance through `parentComponent`.
- **Render area:** Bricks 2.4.2 keeps it in `\Bricks\Frontend::$area` (`header`, `content`, `footer`, `popup`), set by `Frontend::render_data()` for the tree being rendered and put back to the outer area after a nested render (template element, popup, reading time in a loop). `Marker::render_area()` reads it during `bricks/element/render_attributes`. Popups (`popup`) and the footer are never in the zone. Each `render_data()` also pushes its area on `Frontend::$render_context_stack` (outermost first): a nested render (stack deeper than 1) never claims the first root, and a nested render inside a header render counts as header. Without this, a Template element in the header (mega menu, off-canvas built from a section template) rendered its tree with the area `content` and took the first-root slot: the real first section lost its load animation. `Marker::render_context()` reads both statics; `LoadAnimation::observe()` receives them as arguments.
- **Marking:** an element whose base effect is an entrance, with "On load" `always`, or `auto` and in the zone, gets `data-lm-load="<slug>"` and inline values resolved by `LoadAnimation::attributes()` with the rules of `resolve()` in `motion-core.js` (element > first class with the setting > module values > internal values; tempo scales the duration and the stagger, never the delay; `custom` takes the typed duration and scales nothing; invalid values fall back): `--lm-load-duration`, `--lm-load-move-duration` (duration × `--lm-settle-<curve>` for a spring, the same duration otherwise), `--lm-load-delay` (delay + rank × stagger, the rank counting the earlier load elements with the same effect and values), `--lm-load-ease` (`var(--lm-curve-<curve>)`, `linear`, or the custom `cubic-bezier()`), `--lm-load-safe-ease` (`var(--lm-curve-smooth)` for a spring, so opacity and blur never overshoot; the curve itself for `linear` / custom), `--lm-distance`, `--lm-blur`, `--lm-scale`. Never in the builder: the canvas shows the final state and replays through the engine.
- **Stylesheet:** `[data-lm-load]` is left out of the pre-hide rule. One `@keyframes lm-load-<slug>-move` per moving effect (translate or scale), plus `lm-load-fade` and `lm-load-blur`. The fade starts at opacity **0.01, not 0**: Chrome ignores a paint at opacity 0 for the LCP. Keyframes give the start only (the end is the element's own opacity, filter and transform) and the fill is `backwards`: the start state holds during the delay, and once the animation ends it stops applying, so an author's transform or a hover transition is not frozen by a forwards fill. Nothing plays under `prefers-reduced-motion: reduce`.
- **Engine:** `skipReason()` returns `load` for a `[data-lm-load]` element: no ScrollTrigger, no initial state.
- **Limits:** breakpoint values (and "On load" per breakpoint) do not apply to the load animation; it uses the wide-screen values. In a very tall first section, its lower elements animate off screen: set them to "On load: never". A first root that is a Template element is in the zone, but the elements of the template are rendered as a separate tree (their parent is `0` there) and are not; the same goes for page content shown through a Post Content element of a template. With two classes setting the same value, the first class of the element wins (the CSS cascade may pick the other one for the engine path).

## Decisions and pitfalls

- **Components, global elements, the element holding a query loop:** Bricks writes their settings under `.brxe-{id}` (0,1,0), which loses to the class (0,2,0). `Marker` copies the element's own `_lm*` values into an inline `style`, which wins, at the cost of the element's breakpoint values in these cases. Loop **children** do not need it (`.brxe-loop .brxe-{id}.brxe-{type}`, 0,3,0, measured) and must not get it: it would erase their breakpoint values. Also losing to the class, not handled: nested slider slides (`[data-id].splide__slide`, tie) and a few WooCommerce form elements. The loop test is `Query::is_any_looping()`, as in Bricks' own selector code: `Query::is_looping()` is forced true by the `bricks/query/force_is_looping` filter (AJAX popups) while Bricks still writes the ID selector.
- **Number controls with a fixed unit** are stored bare (`"40"`) and Bricks adds the unit only in its CSS; `Marker::inline_style()` appends it the same way.
- **The `required` conditions of the panel cannot see an effect set by a class**, so the effect settings are always shown. The underline section is the exception (three controls of no use to any other effect): it shows only when the element itself sets the underline effect, so an underline coming from a class is adjusted on the class.
- **Controls are added at `init` priority 100**, after Bricks registers its elements, for every element including add-ons. The builder script is declared only in builder requests: declared on the public site, Bricks prints a `data-script-id` (random inside loops) on every element.
- **`cssLoading = file`:** after changing classes from code, run `wp bricks regenerate_assets` (the migration does it).
- **Counter modules (WordPress Script Modules):** `esm-env/browser` → `vendor/esm-env/true.js`, `esm-env/development` and `esm-env/node` → `false.js`, `esm-env` → `index.js`, `number-flow/lite` → `vendor/number-flow/lite.mjs` (registered), and `lumia-motion-counter` → `motion-counter.mjs` (enqueued), only when `Marker::page_families()` holds `counter`, always in the canvas. A page without counter loads none of them (e2e). Core prints the import map and the modules of a classic theme at `wp_footer` priority 10, hooked on `after_setup_theme`; Bricks renders its popups at 10 too, hooked earlier. `Assets::enqueue_counter()` must run after the popups (a counter may only sit in one) and before core: an import map printed after a module started loading is ignored by browsers. Hooked on `init`, it would come after core's printers, so `Assets::order_module_printing()` hooks `print_import_map`, `print_enqueued_script_modules` and `print_script_module_preloads` again at priority 10, after ours; only their order within priority 10 changes. A block theme prints the import map in the head: nothing is moved there, and counters would stay as written (the module needs Bricks anyway). On a public page the entry module is not printed: `Assets::withhold_counter()`, hooked between `print_import_map` and `print_enqueued_script_modules`, dequeues it once the import map (which lists its dependencies) is out, and the loader injects it after the first paint with the engine (see Loading); with nothing queued, core prints no module tag and no `modulepreload`. Only when the import map is printed in the footer: elsewhere core prints the tag as before. The canvas keeps the tag. The engine waits for `lumia-motion-counter-ready`, so the order in which the adapter and the engine run does not matter.
- **`.mjs` MIME type:** browsers refuse a module served with a non-JavaScript type. Apache (the `wordpress` image) serves `.mjs` as `text/javascript`. nginx needs `mjs` in its `types` (`application/javascript js mjs;` in `mime.types`; recent nginx releases list it, an older or hand-written `mime.types` may not, and then serves `application/octet-stream`). Check with `curl -I …/vendor/number-flow/lite.mjs`: with a wrong type, counters stay as written.
- **Loading:** nothing is loaded on a page without an effect. GSAP, ScrollTrigger and the engine load in the footer (priority 19, after Bricks renders popups at 10) only when the page uses an effect, SplitText only for text effects. An underline-only page loads no engine (the underline is CSS; Lenis may still load, it is on every page when enabled). A 3000 ms safety in the `<head>` removes the pre-hide even if the engine never runs.
- **Nothing of the module before the first paint (public pages):**
  - **Styles inline.** `motion.css` (and `lenis.css` when smooth scroll is on) are printed in `<style id="lumia-motion-inline-css">` / `<style id="lumia-lenis-inline-css">` in the head, through handles with no file (`wp_register_style( $handle, false )` + `wp_add_inline_style()`), read once per request. The file read is the one that used to be linked: the release zip minifies `motion.css` in place, so production prints the minified styles; `lenis.css` is a vendor file and is printed as shipped. Same in the canvas; the main builder window keeps `motion-builder.css` linked.
  - **Engine after the first paint.** The scripts are still enqueued exactly as before (same handles, conditions, dependencies, URLs with `?ver=`); on a public page `Assets::print_loader()` then takes the queued Motion handles, orders them with their dependencies as WordPress would, applies `script_loader_src`, prints their inline `before` data (`window.lumiaMotionConfig`, `window.lumiaMotionScroll`) and an inline loader with the ordered URLs as JSON, and dequeues them: no `<script src>` of the module in the HTML. The loader waits for `DOMContentLoaded`, then for the first paint entry (`PerformanceObserver`, type `paint`, buffered: `first-paint`, or `first-contentful-paint` in browsers that only have that one; two `requestAnimationFrame` where paint timing is missing; 1000 ms at most, for a tab opened in the background or a page whose every element is a hidden entrance in a browser with only `first-contentful-paint`), then appends the scripts with `async = false` (fetched in parallel, run in order) and the same ids as WordPress (`lumia-motion-js`…). A script that fails to load adds `lm-ready` at once: the page is shown without waiting for the 3 s safety (a script that loads but never starts the engine still waits for it). Smooth scroll starts a frame or two later than before. Two frames alone were the first idea and were not enough: Chrome's paint entry carries the time the frame was presented, measured 2 ms after the second frame callback had already injected the counter module (e2e).
  - **Why:** since the load animations are pure CSS, the first frame needs no script. Chrome painted the module page at the same time as a page without animation (observed LCP 317 ms home, 217 ms contact), but Lighthouse's simulated throttling (Lantern) charges every request started before the observed LCP, and the CPU time of those scripts on its 4x slower CPU, to its LCP estimate: as deferred tags, the 7 scripts (85 KB) started at 267 to 273 ms, and the two render-blocking stylesheets before them.
  - **Builder canvas:** unchanged, the scripts are printed by WordPress (no loader): Bricks calls the builder hooks of the canvas at its own time, and nothing there is measured.
- **Late start:** the engine can start after the safety has shown the page (a slow phone: `DOMContentLoaded` plus the font wait), or after the load when a "delay JS until interaction" optimiser holds the scripts. Its first build made the entrances of what was on screen, so content the visitor was reading vanished and replayed. Now the first build is quiet (on-screen items final, below-the-fold items still animate) when `<html>` already has `lm-ready` or has no `lm-js`, and the head script adds `lm-js` only while `document.readyState === 'loading'`: run late, it pre-hides nothing.
- **Content is never left hidden:** an entrance whose start the page can never scroll to (footer CTA at `top 60%`, mobile address bar collapsing) plays when the page end is reached; scrubs are clamped to the page end; every build error settles the element in its final state.
- **`html { scroll-behavior: smooth }` broke reloads** (the former snippet forced the page back to the top): the stylesheet sets `scroll-behavior: auto !important`. Entrance elements also get `overflow-anchor: none`: Chrome's scroll anchoring followed the 20 px start offset of a blur-up and landed 19 px off after a reload. Side effect: those elements are not used as scroll anchors.
- **Lenis** loads on every public page when enabled (never in the builder, never under `prefers-reduced-motion: reduce`), driven by the GSAP ticker when GSAP is present, by its own loop otherwise. It stops while a Bricks popup is open or while Bricks puts `no-scroll` on the body (off-canvas, mobile menu), and stands down if the former snippet is still active. Popups and off-canvases get `data-lenis-prevent` at load; menus (`.brx-nav-nested-items`, `.bricks-mobile-menu-wrapper`) only while the page is locked, and lose it on unlock: on desktop the first one is the bar of the nested nav (and its mega menu), and marked at load it let the browser scroll natively under Lenis whenever the wheel was over it. Anchors are offset by `--navbar-height` (measured, so `clamp()` and `rem` work); a cross-page anchor is corrected once after `load`. A root `scroll-padding-top` would be added twice by Lenis: do not combine it with `--navbar-height`.
- **Text effects and screen readers:** headings keep SplitText's `aria-label`; other elements get a visually hidden copy (styled inline, so a "remove unused CSS" optimiser cannot reveal it, and not selectable) next to a split box hidden with `aria-hidden` (ARIA 1.2 forbids `aria-label` on a paragraph). Elements containing a link or a control keep their pieces readable. `lines` restores the original HTML at the end; scrubs stay split while the page is open.
- **Counter (NumberFlow):** a single run of digits in a single text node is animated; the text is rebuilt from the parsed format and refused unless it gives back the original byte for byte ("24/7", "007" are never touched). The digits roll with NumberFlow 0.6.2 (lite build), embedded as is: a home-made roll left traces of neighbouring digits and shifted at the end. `motion-counter.mjs` uses only its public API (`define('lm-number-flow', …)`, `formatToData`, `canAnimate`, `data`, `animated`, the timings) with an Intl formatter whose group and decimal parts are replaced by the separators of the page, so the format is the written one (no grouping added: `2024` never gets a separator).
  - **Mount and end:** the text node is replaced by `<lm-number-flow>` at the start value while the counter waits for its start, and the element **stays** once the value is written: going back to the text shifted the number by a few pixels. `final` / `kill` (destroy, builder change, reduced motion switched on) put the original text node back.
  - **Duration and curve:** duration of the effect × tempo (`tweenDuration`), curve as CSS `linear()` from `cssLinear()` (snappy and bouncy are already smooth for a counter, it never passes its value), `linear`, or the custom `cubic-bezier()`. Passed to `transformTiming` and `spinTiming`; `opacityTiming` is NumberFlow's.
  - **Width (`--lm-counter-width`, site default in the reference values):** `auto` (default) widens as digits appear; `fixed` formats every value with the integer digit count of the final one (`minimumIntegerDigits`: "0 000" before "1 250") and `tabular-nums` on the element, so its width never changes (checked on every frame by the e2e scenario). The final number is a little wider than in proportional digits.
  - **No layout shift:** NumberFlow pads its number for the fade mask, which made the line taller (61.6 px to 84 px measured). `motion.css` gives the space back (`lm-number-flow { line-height: inherit; vertical-align: top; margin-block: calc(-2 * round(nearest, calc(var(--number-flow-mask-height, .25em) / 2), 1px)); }`); the e2e scenario checks a constant height on every frame, in both widths. The former `[data-lm~="counter"] { font-variant-numeric: tabular-nums }` rule is gone: dynamic width keeps the font's own digits.
  - **Accessibility and crawlers:** screen readers always get the written value, never the start value. NumberFlow names its element after the value it shows (`ElementInternals`, role image), which is the start value ("0") until the counter plays, so the engine sets `aria-hidden="true"` on `<lm-number-flow>` and puts a visually hidden copy of the written text (`.lm-sr`) next to it: that copy is what assistive technologies read, from the mount on (e2e: the ARIA snapshot of every counter equals the one of the plain page while pending, while playing and at the end). The copy also keeps the number in `innerText`: NumberFlow's digits live in the shadow DOM, which `innerText` leaves out (measured empty on a mounted counter), so a rendering crawler would have lost the number. The copy is inline-level (`display: inline-block`, no width): an absolute box counts as a block in `innerText`, which read "350\n projets". The served HTML always holds the number as written. (The design spec planned to rely on NumberFlow's own label; changed for the pending state.)
  - **Fallbacks:** `prefers-reduced-motion`, `canAnimate` false, or the adapter absent after 3000 ms (blocked file, no module support): the text stays as written, no element is mounted.
- **Builder:** no pre-hide and no ScrollTrigger in the canvas; everything shows its final state; a change made in the panel replays the effect once (setting "Replay on change"), except under reduced motion. Elements added during the session have no `data-lm` and are found by their computed `--lm-effect`.
- **Panel** (`Controls::definitions()`): six sections in this order, each opened by a separator: Effect (effect, help with the Replay button and the "Show markers" checkbox), Trigger, Timing, Feel, Effect settings, Underline. Bricks shows a group's controls in array order: a new control goes in its section's method. Each number field carries a sentence and its unit (`description`) and a static placeholder, the reference value of `fade`, else of the first effect with that setting.
- **`motion-panel.js`** (main window only, `Assets::enqueue_scripts()`) talks to the canvas engine through `window.lumiaMotion` of `iframe#bricks-builder-iframe` (same origin): `replay(id)`, `markers(on)`, `inspect(id)`. The selected element comes from the builder state: Bricks 2.4.2 mounts a Vue 3 app on `[data-v-app]` and exposes its reactive state as the global property `$_state` (`root.__vue_app__.config.globalProperties.$_state.activeElement.id`; `$_watch` is exposed too and is used to follow the selection, the class and the breakpoint, since Vue may patch the same inputs in place).
  - **Placeholders:** an empty number field shows what the element inherits (its class, else the site value of its effect), from `inspect(id)`. Time values are given before the tempo factor, as a field holds them: a "fast" element with a class stagger of 0.15 shows 0.15, not 0.1125. Limit: while a class is being edited, the placeholders still show the selected element's values.
  - **Markers:** "Show markers" is a preference of the browser (`localStorage.lmMotionMarkers = '1'`), read by the canvas engine when it starts; never saved in the page, never on the public site (`?lmDebug` stays there). In the canvas, where nothing follows the scroll, the engine creates marker-only triggers (start, plus the end of a scrub; a one-shot effect's end marker is transparent), drawn again whenever the items change. No `ScrollTrigger.refresh()` after drawing them: it adds a measuring `<div>` to the body, which `motion-builder.js` took for a change of the page and answered with a new scan. The builder script also ignores the `gsap-marker-*` nodes.
  - **Checkbox:** set from the preference once, when it first renders (`data-lm-synced`): setting it on every render undid the click, because Bricks re-renders during the click, before the `change` event.
- **Group icon:** the group declares the Bricks icon `star` (`assets/svg/builder/star.svg`), shown in its title; `motion-builder.css` hides that SVG (`visibility`, to keep the box) and draws the Lucide `sparkles` (lucide-static 1.34.0, the official file as is, base64 in a CSS mask) over it. The left rail of shortcuts ("quick access") is a fixed list of the Bricks groups in 2.4.2 (`shortcuts` in `main.min.js`): a group added by a plugin never appears there.
- **GSAP license:** GSAP 3.15 is distributed under the Standard "No Charge" License, which restricts tools that let users build animations visually without code. The module is for sites built and maintained by the agency; it is never distributed (not on WordPress.org, not sold). Whether this use fits the license is still to be confirmed by the agency.

## Bundled files

Copied unmodified from the npm tarballs `gsap-3.15.0.tgz` (d2e33ad202d4811e9084883f7ff9a27967ac4095caef51ca4c9d20697a253b1c), `lenis-1.3.26.tgz` (f8930e6b3e26a88f7ccd9ec45f1a11c85d2118cd14673b304204d59e49a68415), `number-flow-0.6.2.tgz` (`dist/*.mjs` listed below and `LICENSE.md`, MIT, Maxwell Barvian) and `esm-env-1.2.2.tgz` (MIT, Ben McCann). No CDN. Only `lite.mjs`, `plugins.mjs`, `ssr-DvIINv8w.mjs` and the esm-env files are loaded; `index.mjs`, `csp.mjs` and `group.mjs` are kept so the package stays whole. About 7 KB gzipped on a page with a counter.

| File | SHA-256 |
|---|---|
| `assets/front/js/vendor/gsap/gsap.min.js` | `92bb9a96476f983d212a2bc4f54c889039c1696dd4461d40a736860938570fbb` |
| `assets/front/js/vendor/gsap/ScrollTrigger.min.js` | `b0b14d67b55b0c43c756ac0b106cfcb09d0879945f6ead64451065b0672916a2` |
| `assets/front/js/vendor/gsap/SplitText.min.js` | `419f7027a5f086a12cb7988736d8fdd3a6ed2200229661de25b6628ca7ced344` |
| `assets/front/js/vendor/lenis/lenis.min.js` | `53195c9797e7ce7bf9d7fa9242b08209e57f46de4c9dac126a6494fa780e3346` |
| `assets/front/js/vendor/lenis/lenis.css` | `2f668ae84a668327f246faf8a770383a2bf69196d214290fbc4d5548910606c5` |
| `assets/front/js/vendor/number-flow/lite.mjs` | `3154fb0dfe2983c68a216bf5552f7d8e537759b2ccef3988e8b32aedd2a16b10` |
| `assets/front/js/vendor/number-flow/index.mjs` | `df08f25a6a37f0d30ba045caaab66c87ba95172df2ed5e9261e2b090aa8a9b8d` |
| `assets/front/js/vendor/number-flow/plugins.mjs` | `5189ebb234bbd1cfcd4a6f1f02da8a8dfb36163784fbbe52a744331587127eac` |
| `assets/front/js/vendor/number-flow/csp.mjs` | `ebd1628af43db47322906d277a21328bcc7c4585f8a5e9539fb948d035171807` |
| `assets/front/js/vendor/number-flow/group.mjs` | `2eb5992303a2e51cbc2eafde6708dcf12cc4c2cc85fb33b95c8e118848b593bf` |
| `assets/front/js/vendor/number-flow/ssr-DvIINv8w.mjs` | `fed0a42ce8dd17a833ab6cadb04d0a5cefb55498c4110916fe60d31a2435f0a3` |
| `assets/front/js/vendor/number-flow/LICENSE.md` | `f8a3eac9fd2ae94f18a2bf0459e57d90da539f015a7ec4ad6186a5ca33a6aa9a` |
| `assets/front/js/vendor/esm-env/index.js` | `3a08611ecddf9a951bf6db388428f894a50a87a243f8811b43a92e332cd9d5a1` |
| `assets/front/js/vendor/esm-env/true.js` | `1f09e0f9cdf221279401eb3fdf83e6b411c46981b27ebb2451460009d7f1e429` |
| `assets/front/js/vendor/esm-env/false.js` | `187aea5dbf198bf211cc9d099aa500f9119eef33eae6303e5fbe43f901c0e5e1` |
| `assets/front/js/vendor/esm-env/browser-fallback.js` | `baa579ced22e3e0cf42a526ed91b66ec127883d39b1f5ba50fab51dbbd6bfd44` |
| `assets/front/js/vendor/esm-env/dev-fallback.js` | `9a8b82930d9fe18c0ff5aa20b8a3166ca60d75902e4d9676d101abfc6f4f9452` |
| `assets/front/js/vendor/esm-env/LICENSE` | `6df5276ee7531fcc5446f19082c12e77bb6c5f081fd2b6d6cf88180a5ee36acd` |

The release build leaves every file under a `vendor/` folder as it is and fails if one differs in the zip (`tools/build/build-zip.sh`): `lenis.css`, which it used to re-minify, now ships as published too. `motion-counter.mjs` is minified like the other scripts of the module.

## Migrating a site from the snippet

1. Back up the database. Install Lümia Tools and enable Motion: activation creates the missing `lm-*` classes in a "Motion" category (also `wp lumia motion classes --user=<admin>`), except where the `sk-*` equivalent exists.
2. `wp lumia motion migrate --user=<admin>` (dry run, writes nothing): lists the `sk-*` classes to rename, conflicts (an `lm-*` class already exists: the `sk-*` one is left alone), the pages and elements using them, and the CSS to check.
3. `wp lumia motion migrate --apply --user=<admin>`: renames each class in place (**same ID**, so no page content is rewritten), moves it to "Motion", rewrites the class's own custom CSS, then regenerates the Bricks CSS files. Running it twice changes nothing.
4. Right after: fix the `.sk-*` selectors the report lists in element, page, template or global custom CSS — they stop matching at `--apply`, the compatibility setting does not bring the old names back. Deactivate (or delete) the "SK Motion" snippet, purge the page cache, check the site.
5. What the report lists as "kept working" goes on working while the compatibility setting is on: former names typed in a "CSS classes" field, the `--sk-hover-underline-*` variables (read as a fallback by the underline of the renamed class), and the selectors of classes that keep their name (conflicts). Replace them at leisure.
6. When the Compatibility tab reports nothing left, turn off "Former class names".

The scan ignores revisions, trashed posts and auto-drafts; published FluentSnippets files are reported, never modified.

## Performance (Lighthouse, local copy, 12/10/2026)

Local Docker copy of the lab (original dump, `wp lumia motion migrate --apply` run with the current code), gzip on, Lighthouse performance only, median of 3 runs. Mobile uses Lighthouse's default simulated throttling.

| Page (mobile) | Old snippet (recorded earlier, original dump) | No animation (same state) | Module, iteration 2 | Module, devtools throttling (applied) | No animation, devtools throttling |
|---|---|---|---|---|---|
| Home | 95 / LCP 2.58 s | 96 / 2.35 s | **92 / LCP 3.02 s** | 89 / 2.54 s | 77 / 3.70 s |
| Contact | 99 / 1.97 s | 98 / 1.97 s | **95 / LCP 2.73 s** | 92 / 2.45 s | 90 / 2.69 s |
| Legal notice (underline only) | 97 | | 97 / LCP 2.28 s | | |

Desktop: 100 on all three pages (LCP 0.67 s home, 0.62 s contact). Iteration 1 was 89 / 3.45 s (home) and 94 / 2.96 s (contact). Pages carry the animations (home: 33 `data-lm`, 3 `data-lm-load`; contact: 11 and 5).

The figures above were measured before the styles were inlined and the engine moved after the first paint (next section); they explain why that change was made.

Same-conditions measurement of the old snippet on this machine (the original dump with `sk-*` classes, snippet published, module off): home 96 / 2.34 s, contact 99 / 1.97 s (simulated), hence the criterion "LCP at most 0.2 s above the old snippet": **2.54 s home, 2.17 s contact**.

Before the change the criterion failed on the simulated figure (3.02 s and 2.73 s). The LCP element is the hero paragraph (a `blur-up` load element, painted from the first frame); the breakdown was TTFB 0.45 s, load delay 0, load time 0, render delay 2.56 s (85 %). Chrome's observed FCP and LCP were the same with and without the module (317 ms home, 217 ms contact): the gap was Lantern's, which counted the 7 deferred scripts (85 KB, requests starting at 267 to 273 ms, before the observed LCP) and the two render-blocking stylesheets.

### Styles inline, engine after the first paint (12/10/2026)

Same local copy (module on, Lenis on), mobile, median of 3 runs, before and after the change measured back to back:

| Page (mobile) | Before, simulated | **After, simulated** | Before, devtools throttling | After, devtools throttling |
|---|---|---|---|---|
| Home | 92 / LCP 3.02 s | **96 / LCP 2.35 s** | 89 / 2.55 s | 92 / 2.30 s |
| Contact | 95 / LCP 2.73 s | **98 / LCP 1.97 s** | 92 / 2.45 s | 90 / 2.69 s |

Criterion met: 2.35 s ≤ 2.54 s (home), 1.97 s ≤ 2.17 s (contact); the module now equals the old snippet (2.34 s / 1.97 s) and the page without animation. In the trace every module script starts at 335 ms, after the observed LCP (322 ms), and no module stylesheet is requested. The devtools-throttled runs are bimodal (home after: 2.30, 3.70, 2.30 s; contact after: 2.26, 2.69, 2.69 s; the old snippet gave 3.70, 3.71, 2.28 s on contact): that row is noise either way.

## Tests

- `composer motion:test` — pure PHP (catalog, sanitizer, marker, load animations, class manager, legacy underline CSS, uninstall keys), no WordPress (`tools/motion/php/bricks-stubs.php` stands in for the two Bricks classes the loop test calls).
- `node --test tools/motion/js/*.test.mjs` — curves, springs, settings resolution, counter.
- `node tools/motion/js/build-curves.mjs --check` — the `--lm-curve-*` / `--lm-settle-*` tokens of `motion.css` (between `lm-curves:start` and `lm-curves:end`) are generated from the engine springs (`cssLinear()`); run it without `--check` to rewrite the block after changing a spring. The plain declaration (`cubic-bezier(0.22, 1, 0.36, 1)`) is the fallback of browsers without `linear()`. Also run by `curves.test.mjs`.
- `tools/e2e/motion/run.sh` — Playwright scenarios against a local Bricks site (Bricks is licensed, so not in CI); see `tools/e2e/motion/README.md`.
