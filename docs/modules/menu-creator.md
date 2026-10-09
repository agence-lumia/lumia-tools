# MenuCreator module

`includes/Modules/MenuCreator/Module.php` + `assets/admin/js/modules/menu-creator.js`. Applies the profiles of `WhiteLabel\MenuProfileManager` (see [white-label.md](white-label.md)) to the real wp-admin menu. The AJAX endpoints are named `lumia_wl_*` although they live in this module.

## Applying to the menu

`custom_menu_order` (enabled per user through `maybe_enable_custom_order`, not blindly) + the `menu_order` filter for the order, the `admin_menu` action for visibility/renaming/separators/custom links (including the reordering of submenus), `admin_head` for injecting the per-item icon CSS overrides (base64 SVG or URL), the `target` attributes of custom links, and a global fix for the opacity of the menu icons (registered unconditionally). The hooks that transform the menu are only registered in `init()` if at least one profile is `status === 'active'`. `apply_menu_visibility()` takes a snapshot of the intact WP menu in `self::$pristine_menu`/`$pristine_submenu` **before** mutating the globals, so that the editor receives the real WP menu (not our already injected separators/links).

## Access blocking

Hiding an item only removes it from the sidebar: the URL stays openable. The “Block direct access” checkbox (`block_access`, only on an already hidden item — `sanitize_menu_items()` forces `false` on a visible item) adds the server-side denial through `enforce_blocked_pages()` on `admin_init` @1. `request_matches_slug()` rebuilds the expected request from the menu slug (`slug_to_request()` handles the three forms: `upload.php`, `edit.php?post_type=page`, and the plugin page slug served by admin.php, including `wc-admin&path=/analytics/overview`): all the slug's parameters must match, and a slug without `post_type`/`taxonomy`/`page` must not match a request that carries one — otherwise blocking `edit.php` would also block `edit.php?post_type=page`. Never active on `admin-ajax.php`/`admin-post.php`/`async-upload.php` (a denial there would break legitimate requests), and `index.php` returns a `wp_die` 403 instead of the redirect (which points to it — loop). The message goes through a **toast** (`render_denied_toast()` on `admin_footer`), not an `admin_notice`: the latter would be captured by the plugin's notification center and would only appear under the bell, while the user has just been redirected and must understand right away. The `lumia_denied` parameter is removed from the URL in JS so that a reload does not replay the message. **This is not a permissions system**: REST, WP-CLI and WP capabilities are not concerned.

## Export

The profiles live under `lumia_wl_menu_profiles`, outside `lumia_module_menu_creator`: they get into the global export through the `AbstractModule::get_export_extras()` / `import_extras()` extension point (`extras` block of the JSON, next to `modules`). These two methods are generic — any module that keeps its state in an option of its own must override them. The import merges by id (`MenuProfileManager::save()` overwrites the entry with the same id, adds otherwise): a partial file deletes no menu. The editor also has its own export/import: two icon-only buttons in the header of the Menus column — import (upload) and “export all” (download, all the menus read from `mcProfiles` — the state in the database, not the editor, so as not to embed unsaved changes) — and “Export” in the panel footer (the open menu, on-screen state included). `ajax_import_profile` accepts the three forms (`{profiles:[…]}`, `{profile:…}`, bare profile), runs each menu through `sanitize_profile()` again and creates them as drafts.

## Role restriction of custom links

A WP item is already filtered by its own capability; a custom link is attached to nothing, hence the `roles` field (empty = everyone). The filtering is done by **not adding** the entry in `apply_menu_visibility()` (`current_user_has_role()`): the capability of `add_menu_page()` cannot express “these roles”, WordPress only reasoning in capabilities.

## History and shortcuts

Every editor mutation ends with `setDirty(true)`: it is therefore `setDirty()` that pushes the snapshot (`pushHistory()`), rather than one call per handler — the tree, the fields and the picker would forget one. The snapshot reuses `collectProfile()`, because the panel fields live in the DOM and not in `ed.profile`, but keeps the items with their runtime properties (`_uid`, `_wpLabel`), without which going back would break the selection. Two snapshots less than 400 ms apart merge, otherwise every typed character would cost a Ctrl+Z. `applyHistory()` sets `hist.lock` so that the `setDirty()` of the re-render does not push again, and going back to index 0 puts the menu back in the “saved” state. Keyboard side: Ctrl/Cmd+S is always intercepted, Ctrl+Z / Ctrl+Y are **left to the field** when the focus is in an input (native text undo is expected there).

## Stale entries

`mergeWpMenu()` **keeps** the items whose slug no longer exists in the current WP menu and flags them `_stale` (Lucide `triangle-alert` badge in the tree + `#lumia-mc-stale-bar` banner above it, with a “Clean up” action). Purging them silently — what the previous version did — wiped the settings of a plugin merely deactivated for the length of an update. An unknown slug is harmless on the application side: `remove_menu_page()` does nothing and `apply_menu_order()` goes through an `array_diff`.

## Labels

`clean_menu_label()` removes the `<span>` elements **with their content** before `wp_strip_all_tags()`: WP and plugins stick their counters in the title itself (`Comments <span class="awaiting-mod">0</span>`), and a plain strip left labels such as “Comments 00 comments in moderation” in the editor.

## Icon picker

Library of ~150 Lucide SVGs **taken as is from lucide-static v1.34.0** and grouped by category in `get_icon_library()` (`get_lucide_icons()` flattens for the JS, `get_icon_categories()` feeds the headers) — see the “never a hand-drawn SVG” rule: to add one, fetch the official file, do not approximate. Three tabs: Library (search + categories), Media library, SVG code. The latter goes through `ajax_sanitize_svg`, which reuses `ImageOptimizer\SvgHandler::sanitize()` — same risk, same allowlist, no second sanitizer is written. The search queries `get_icon_aliases()` (slug → keywords, served as `iconAliases`) and not only the slug: Lucide's are English and rarely guessable from another language's interface (`funnel` for a filter, `banknote` for a bill, `boxes` for a stock). Each keyword list is a translatable string (`__()`), so the search follows the interface language. On the JS side, `foldAccents()` folds the accents on both sides of the comparison — no need to double the entries — and every typed word must be found, in any order. An icon without an alias stays searchable by its name.

## Icons — two structural pitfalls

`inject_menu_icon_overrides()` targets the `<li>` by its `id` attribute (index 5 of `$menu`, reproduced by `menu_dom_id()`), **never** by a substring search in the `href`: WooCommerce registers “Marketing” under the slug `woocommerce-marketing` and then rewrites the menu URL to `admin.php?page=wc-admin&path=/marketing` — the href no longer contains the slug, and the icon was never applied (same for the top-level `woocommerce` → `page=wc-admin`). The href remains a fallback for entries without a hookname.

Second pitfall: a SVG menu icon is monochrome and painted for the **dark** sidebar (fill `#f3f1f1` for WooCommerce/Bricks, `stroke="currentColor"` in an uploaded Lucide file). Rendered as an `<img>`, `currentColor` inherits nothing and falls back to **black**; rendered in the editor (light background), the white fill is invisible. **Monochrome** SVGs (`svg_is_monochrome()`: `currentColor`, no declared color, or just one — same test duplicated in JS) are therefore rendered as a **CSS mask** (`.lumia-mc-icon` on the real menu side, `.lumia-wl-icon-mask` on the editor side): the source only gives the shape, the color comes from the stylesheet. Other media (PNG, multicolor SVG — the plugin's own logo, white square + black glyph, which a mask would flatten into a solid square) stay `<img>` to keep their colors. `resolve_icon_render()` decides by reading the local file through `read_local_svg()`; on the editor side, an SVG served by URL is fetched same-origin, cached, and the tree redrawn once.

## Editor data

The editor receives its data through `get_admin_js_data()` (keys `mcProfiles`, `wpMenu`, `wpSubmenu`, `wpRoles`, `wpRecentUsers`, `iconLibrary`, `iconCategories`, `iconAliases`) — there is **no** `ajax_get_wp_menu` endpoint. Its translated strings go under the `i18n` key of the same array and are read in JS through `tr("key")` (a thin wrapper over `lumiaAdmin.i18n`); a string with a number goes through `fmt()` (`%d` / `%s`). Drag and drop uses the bundled SortableJS (`assets/admin/js/vendor/sortable.min.js`, handle `lumia-sortable-js` via `get_admin_js_deps()`).

## Persisted data that must not change

The `lumia_mc_editor_excluded_slugs` filter, the `lumia_wl_*` AJAX actions, the profile keys (`include_roles`, `block_access`, `target_blank`…) and the `lien-` prefix of the slug generated for a new custom link are stored or public identifiers: they stay as they are whatever the interface language.
