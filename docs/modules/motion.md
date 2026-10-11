# Motion module

`includes/Modules/Motion/`. Settings stored under `lumia_module_motion`. Replaces the former "SK Motion" FluentSnippets snippet (GSAP from a CDN on every page) with entrance and scroll effects for Bricks, adjustable per element. The module does nothing unless Bricks is the active theme.

| File | Role |
|---|---|
| `Effects.php`, `Legacy.php` | Effect catalog and reference values; every former name (`sk-*`, `--sk-hover-underline-*`, the former global and snippet name) lives in `Legacy.php` only |
| `SettingsSanitizer.php`, `settings-template.php` | Admin screen: default tempo and curve, reference values per effect, Lenis, builder preview, compatibility |
| `Controls.php` | "Motion" group on the Style tab of every Bricks element |
| `Marker.php` | `data-lm` on animated elements, inline overrides where the class would win, families used by the page |
| `Assets.php` | Conditional loading, configuration JSON |
| `ClassManager.php`, `Command.php` | `lm-*` global classes, `wp lumia motion classes|migrate` |
| `assets/front/js/motion-core.js` | Pure helpers (curves, springs, settings resolution, counter), unit tested with `node --test` |
| `assets/front/js/motion.js`, `motion-scroll.js`, `motion-builder.js` | Engine, Lenis, builder preview |

## Using it in Bricks

- **One click:** add a global class from the "Motion" category (`lm-fade`, `lm-slide-up`, `lm-blur`, `lm-blur-up`, `lm-zoom`, `lm-zoom-blur`, `lm-lines`, `lm-words`, `lm-letters`, `lm-counter`, `lm-underline`).
- **Per element:** Style tab > Motion. Every control writes a `--lm-*` CSS variable, so the usual Bricks cascade applies: element > class > module defaults (passed as JSON, not through `:root`). Breakpoint values work like any Bricks control.
- **One effect per element.** With two Motion classes, the first one in the class list wins (`?lmDebug` logs it).
- **Typed class names count too:** a Motion class typed in the element's "CSS classes" field (`_cssClasses`) is marked like a global class (`lm-*` always, `sk-*` while the compatibility setting is on). Before, `Marker` read only the global classes, so a typed name was never pre-hidden and did not load the engine on its own.
- **LCP:** on the main heading above the fold, prefer a text effect. Entrance effects pre-hide the element until the engine is ready; text and counters are never pre-hidden.

### Variables

Engine variables are registered with `@property … inherits: false`, so a child never inherits its section's settings: `--lm-effect`, `--lm-start-edge`, `--lm-start-at`, `--lm-end-edge`, `--lm-end-at`, `--lm-start`, `--lm-end`, `--lm-tempo`, `--lm-duration`, `--lm-delay`, `--lm-stagger`, `--lm-ease`, `--lm-ease-custom`, `--lm-distance`, `--lm-blur`, `--lm-scale`, `--lm-from-opacity`, `--lm-start-value`.

The underline variables are **inheritable** on purpose (a wrapper can restyle every link inside it): `--lm-underline-thickness` (2px), `--lm-underline-gap` (0.12em), `--lm-underline-color` (currentColor), `--lm-underline-duration` (0.4s). Never declare them with `@property`.

While the compatibility setting is on, the former `--sk-hover-underline-*` variables are read as a second fallback (`Legacy::underline_css()`, printed after `motion.css`): the drawing rules are repeated with `var(--lm-underline-gap, var(--sk-hover-underline-gap, 0.12em))` and so on. They sit on the drawing rules, not on the former class: a first version assigned `--lm-underline-*` on `.sk-hover-underline`, which no element carries once the migration has renamed the class, and which hid a value inherited from a wrapper. `tools/motion/php/LegacyTest.php` checks that the fallbacks still mirror `motion.css`.

Positions accept ScrollTrigger syntax only (`top 80%`, `center center`, `bottom-=100`); the same rule is tested in PHP and JS from `tools/motion/positions.json`. Durations accept `s` and `ms`. An inline override (`Marker::VALUE_PATTERN`) keeps `+` and `=` for relative positions (`top+=50 80%`) and refuses `;`, `:`, quotes, `<` and braces.

### Tempo and curves

Two independent settings, so an element can change its timing or feel without changing its effect:

- **Tempo:** `fast` ×0.7, `normal` ×1, `slow` ×1.4 on the duration and the stagger, never on the delay. `exact` uses `--lm-duration` (a typed duration is ignored otherwise; `?lmDebug` says so).
- **Curve:** `lumia` = `cubic-bezier(0.625, 0.05, 0, 1)` (default), `smooth`, `snappy`, `bouncy` (SwiftUI-style springs, bounce 0 / 0.15 / 0.3, computed in `motion-core.js`, no dependency), `linear`, `custom` (`--lm-ease-custom`, a `cubic-bezier()` with x1 and x2 in [0, 1]; invalid → `lumia`).
- Opacity and blur always use a no-overshoot variant of the curve; the counter never overshoots whatever the curve; scrub effects (`words`, `letters`) ignore tempo and curve.

## Decisions and pitfalls

- **Components, global elements, the element holding a query loop:** Bricks writes their settings under `.brxe-{id}` (0,1,0), which loses to the class (0,2,0). `Marker` copies the element's own `_lm*` values into an inline `style`, which wins, at the cost of the element's breakpoint values in these cases. Loop **children** do not need it (`.brxe-loop .brxe-{id}.brxe-{type}`, 0,3,0, measured) and must not get it: it would erase their breakpoint values. Also losing to the class, not handled: nested slider slides (`[data-id].splide__slide`, tie) and a few WooCommerce form elements. The loop test is `Query::is_any_looping()`, as in Bricks' own selector code: `Query::is_looping()` is forced true by the `bricks/query/force_is_looping` filter (AJAX popups) while Bricks still writes the ID selector.
- **Number controls with a fixed unit** are stored bare (`"40"`) and Bricks adds the unit only in its CSS; `Marker::inline_style()` appends it the same way.
- **The `required` conditions of the panel cannot see an effect set by a class**, so the effect settings are always shown.
- **Controls are added at `init` priority 100**, after Bricks registers its elements, for every element including add-ons. The builder script is declared only in builder requests: declared on the public site, Bricks prints a `data-script-id` (random inside loops) on every element.
- **`cssLoading = file`:** after changing classes from code, run `wp bricks regenerate_assets` (the migration does it).
- **Loading:** nothing is loaded on a page without an effect. GSAP, ScrollTrigger and the engine load in the footer (priority 19, after Bricks renders popups at 10) only when the page uses an effect, SplitText only for text effects. An underline-only page loads no engine (the underline is CSS; Lenis may still load, it is on every page when enabled). A 3000 ms safety in the `<head>` removes the pre-hide even if the engine never runs.
- **Late start:** the engine can start after the safety has shown the page (a slow phone: `DOMContentLoaded` plus the font wait), or after the load when a "delay JS until interaction" optimiser holds the scripts. Its first build made the entrances of what was on screen, so content the visitor was reading vanished and replayed. Now the first build is quiet (on-screen items final, below-the-fold items still animate) when `<html>` already has `lm-ready` or has no `lm-js`, and the head script adds `lm-js` only while `document.readyState === 'loading'`: run late, it pre-hides nothing.
- **Content is never left hidden:** an entrance whose start the page can never scroll to (footer CTA at `top 60%`, mobile address bar collapsing) plays when the page end is reached; scrubs are clamped to the page end; every build error settles the element in its final state.
- **`html { scroll-behavior: smooth }` broke reloads** (the former snippet forced the page back to the top): the stylesheet sets `scroll-behavior: auto !important`. Entrance elements also get `overflow-anchor: none`: Chrome's scroll anchoring followed the 20 px start offset of a blur-up and landed 19 px off after a reload. Side effect: those elements are not used as scroll anchors.
- **Lenis** loads on every public page when enabled (never in the builder, never under `prefers-reduced-motion: reduce`), driven by the GSAP ticker when GSAP is present, by its own loop otherwise. It stops while a Bricks popup is open or while Bricks puts `no-scroll` on the body (off-canvas, mobile menu), and stands down if the former snippet is still active. Popups and off-canvases get `data-lenis-prevent` at load; menus (`.brx-nav-nested-items`, `.bricks-mobile-menu-wrapper`) only while the page is locked, and lose it on unlock: on desktop the first one is the bar of the nested nav (and its mega menu), and marked at load it let the browser scroll natively under Lenis whenever the wheel was over it. Anchors are offset by `--navbar-height` (measured, so `clamp()` and `rem` work); a cross-page anchor is corrected once after `load`. A root `scroll-padding-top` would be added twice by Lenis: do not combine it with `--navbar-height`.
- **Text effects and screen readers:** headings keep SplitText's `aria-label`; other elements get a visually hidden copy (styled inline, so a "remove unused CSS" optimiser cannot reveal it, and not selectable) next to a split box hidden with `aria-hidden` (ARIA 1.2 forbids `aria-label` on a paragraph). Elements containing a link or a control keep their pieces readable. `lines` restores the original HTML at the end; scrubs stay split while the page is open.
- **Counter:** a single run of digits in a single text node is animated; the text is rebuilt from the parsed format and refused unless it gives back the original byte for byte ("24/7", "007" are never touched). The original text node is put back at the end. A crawler that never scrolls reads the start value.
- **Builder:** no pre-hide and no ScrollTrigger in the canvas; everything shows its final state; a change made in the panel replays the effect once (setting "Replay on change"), except under reduced motion. Elements added during the session have no `data-lm` and are found by their computed `--lm-effect`.
- **GSAP license:** GSAP 3.15 is distributed under the Standard "No Charge" License, which restricts tools that let users build animations visually without code. The module is for sites built and maintained by the agency; it is never distributed (not on WordPress.org, not sold). Whether this use fits the license is still to be confirmed by the agency.

## Bundled files

Copied unmodified from the npm tarballs `gsap-3.15.0.tgz` (d2e33ad202d4811e9084883f7ff9a27967ac4095caef51ca4c9d20697a253b1c) and `lenis-1.3.26.tgz` (f8930e6b3e26a88f7ccd9ec45f1a11c85d2118cd14673b304204d59e49a68415). No CDN.

| File | SHA-256 |
|---|---|
| `assets/front/js/vendor/gsap/gsap.min.js` | `92bb9a96476f983d212a2bc4f54c889039c1696dd4461d40a736860938570fbb` |
| `assets/front/js/vendor/gsap/ScrollTrigger.min.js` | `b0b14d67b55b0c43c756ac0b106cfcb09d0879945f6ead64451065b0672916a2` |
| `assets/front/js/vendor/gsap/SplitText.min.js` | `419f7027a5f086a12cb7988736d8fdd3a6ed2200229661de25b6628ca7ced344` |
| `assets/front/js/vendor/lenis/lenis.min.js` | `53195c9797e7ce7bf9d7fa9242b08209e57f46de4c9dac126a6494fa780e3346` |
| `assets/front/js/vendor/lenis/lenis.css` | `2f668ae84a668327f246faf8a770383a2bf69196d214290fbc4d5548910606c5` |

The release build re-minifies `lenis.css` (no `.min.` in its name); the `*.min.js` files are left as they are.

## Migrating a site from the snippet

1. Back up the database. Install Lümia Tools and enable Motion: activation creates the missing `lm-*` classes in a "Motion" category (also `wp lumia motion classes --user=<admin>`), except where the `sk-*` equivalent exists.
2. `wp lumia motion migrate --user=<admin>` (dry run, writes nothing): lists the `sk-*` classes to rename, conflicts (an `lm-*` class already exists: the `sk-*` one is left alone), the pages and elements using them, and the CSS to check.
3. `wp lumia motion migrate --apply --user=<admin>`: renames each class in place (**same ID**, so no page content is rewritten), moves it to "Motion", rewrites the class's own custom CSS, then regenerates the Bricks CSS files. Running it twice changes nothing.
4. Right after: fix the `.sk-*` selectors the report lists in element, page, template or global custom CSS — they stop matching at `--apply`, the compatibility setting does not bring the old names back. Deactivate (or delete) the "SK Motion" snippet, purge the page cache, check the site.
5. What the report lists as "kept working" goes on working while the compatibility setting is on: former names typed in a "CSS classes" field, the `--sk-hover-underline-*` variables (read as a fallback by the underline of the renamed class), and the selectors of classes that keep their name (conflicts). Replace them at leisure.
6. When the Compatibility tab reports nothing left, turn off "Former class names".

The scan ignores revisions, trashed posts and auto-drafts; published FluentSnippets files are reported, never modified.

## Tests

- `composer motion:test` — pure PHP (catalog, sanitizer, marker, class manager, legacy underline CSS, uninstall keys), no WordPress (`tools/motion/php/bricks-stubs.php` stands in for the two Bricks classes the loop test calls).
- `node --test tools/motion/js/*.test.mjs` — curves, springs, settings resolution, counter.
- `tools/e2e/motion/run.sh` — Playwright scenarios against a local Bricks site (Bricks is licensed, so not in CI); see `tools/e2e/motion/README.md`.
