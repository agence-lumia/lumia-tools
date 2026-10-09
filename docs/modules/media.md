# Media module

`includes/Modules/Media/Module.php` + `assets/admin/js/modules/media.js`. Virtual media folders, implemented as the `lumia_media_folder` taxonomy on `attachment` — no file is ever moved on disk.

## Server-side filtering

Filtering is **entirely server-side**: the taxonomy is registered with `'query_var' => 'lumia_folder'`, which `wp_ajax_query_attachments()` allows for any attachment taxonomy, so the view only does `collection.props.set('lumia_folder', id)` and no list of IDs travels. Two registration arguments are mandatory: that `query_var`, and `'update_count_callback' => '_update_generic_term_count'` (the default callback only counts `publish` posts, and media are `inherit`). Always clear the raw query var before `WP_Query::parse_tax_query()` sees it, otherwise WordPress ANDs its own clause, resolved by slug, with ours and the result is always empty.

## Loading the assets

The assets load on the `wp_enqueue_media` action — the only anchor that covers every context (upload.php, block editor, Customizer, widgets, site editor, frontend builders such as Bricks). A second hook, `admin_enqueue_scripts` @100, covers `upload.php?mode=list`, the only screen without `wp.media`.

`media.js` mounts the same vanilla `FolderPanel` in two ways: as an extension of `wp.media.view.AttachmentsBrowser` (grid + all modals), and standalone in the list view. It also patches `wp.media.view.Attachment.Details` **and** `.TwoColumn` to add the per-media folder checkboxes — on DOM ready, not at parse time, because `media-grid.js` defines `TwoColumn` and may be printed after us. Folder IDs reach the Backbone model through a `wp_prepare_attachment_for_js` filter (`lumiaFolders`), so opening a media's details costs no extra request.

## JS strings: `lumiaMedia.i18n`, not `lumiaAdmin.i18n`

`media.js` runs on native WordPress screens (media library, editor modals, Customizer, frontend builders), where `admin.js` is loaded as a dependency but **`window.lumiaAdmin` is never localized**. The module therefore does not use `get_admin_js_data()` (which only feeds the plugin pages): `enqueue_assets()` hands its strings to the script with `wp_localize_script( 'lumia-media-js', 'lumiaMedia', … )`, under the `i18n` key, and the JS reads them through `t( key )`. Every string the JS shows, including the generic ones (`cancel`, `save`, `error`), therefore has its own key in that array — there is no literal fallback in the JS.

## Drag and drop

Drag and drop uses SortableJS **only to carry the gesture** (`forceFallback: true`, `sort: false`); drop targets are resolved by `document.elementFromPoint` hit-testing. Do not turn the folder list into Sortable drop zones — its `emptyInsertThreshold` makes items land in the neighboring folder.

## Two capabilities, not one

`upload_files` (`CAP_USE`) is the capability to **upload**, not to **organize the site's library**: settling for it everywhere let a mere author rename other people's folders and delete one with all its descendants in a single call. Folders are a taxonomy, so the capability that describes this power is `manage_categories` (`CAP_MANAGE`, editor and above): `guard()` for reading and filing, `guard_manage()` for any mutation of the tree (create, rename, delete, move, color). `ajax_move_items()` adds a check **per attachment** (`current_user_can( 'edit_post', $att_id )`) — the entry guard says the caller may use the media library, not that they may touch *this* media; refusals are counted and returned as `refused`, which a toast displays, otherwise a silently partial move reads as a bug.

The `canManage` flag (`lumiaMedia` payload) hides the "+" button, the "…" menus and folder drag and drop on the JS side. **Pitfall**: `wp_localize_script()` converts every value to a **string** — a PHP `false` arrives as `""` and a `true` as `"1"`, so a test such as `cfg.canManage !== false` is always true and never hides anything. Compare against both forms (`=== true || === "1"`). This is only cosmetic anyway: the decision belongs to `guard_manage()`, which reads nothing the client sends.

## Tooltips outside LUMIA pages

`media.js` runs where `admin.js` is absent: keep `title` **and** `data-lumia-tip` on icon-only controls (see [design-system.md](../design-system.md#tooltips)).
