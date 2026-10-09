# Plugin core

Architecture, contracts and pitfalls of the core (`includes/Core/`, `Admin`, `AbstractModule`). Each module has its own page under [modules/](modules/).

## Boot sequence

```
plugins_loaded → Plugin::instance() (singleton)
  └─ init hook → on_init()
       ├─ load_textdomain()
       ├─ Modules::register_default_modules()   ← fires the lumia_module_definitions filter
       └─ Modules::init_active_modules()         ← calls Module::init() on every active module
```

`Admin` is instantiated in the same `Plugin` constructor, only when `is_admin()`.

**On hold.** If Studio Kyne Mini Tools is loaded in the request, or its migration stopped half-way (`FromSkmt::on_hold()`), `Plugin` registers no module and no `Admin`: only the updater, the text domain and an admin notice. See [Migration from SKMT](#migration-from-skmt-coremigrationfromskmt).

## Autoloader

`Lumia\Tools\` maps directly to `includes/`. Example: `Lumia\Tools\Modules\Security\RateLimiter` → `includes/Modules/Security/RateLimiter.php`. No Composer at runtime, no bundled `vendor/`.

Modules may depend on classes of another module (`MenuCreator\Module` uses `WhiteLabel\MenuProfileManager`): the autoloader isolates nothing. The dependent module stops working properly if the other one is deactivated or uninstalled.

## Settings storage

| Scope | Option key | Access |
|---|---|---|
| Global plugin | `lumia_settings` | `Settings::get('global.update_channel')` (dot notation) |
| Module state | `lumia_settings` → `modules.{id}` | `Settings::get('modules.security')` |
| Module settings | `lumia_module_{id}` | `AbstractModule::get_module_settings()` |

The rate limiter entries are **transients**, key `_lumia_rl_{md5(ip)}`, 24 h TTL: they expire on their own and are deliberately absent from `get_uninstall_keys()`. The per-user menu profile cache is also a transient (`lumia_wl_menu_user_{gen}_{id}`, 1 h).

`AbstractModule::get_module_settings()` merges the stored values over the defaults **recursively**: it descends into associative arrays but replaces lists (roles, IP lists) as a whole. `wp_parse_args()` alone only merges the first level: any sub-key added in a later version would be missing from existing installs until the user saves the screen again, and a setting whose default is `true` would silently become `false` everywhere.

## Module system

Each module is a class extending `AbstractModule` (which implements `ModuleInterface`). Required methods:

- `init(): void` — register all the WordPress hooks here
- `get_settings(): array` — current settings
- `save_settings(array $settings): bool` — sanitize and persist; the core applies no sanitization
- `static get_defaults(): array` — nested array of defaults, merged recursively by `get_module_settings()`
- `static get_uninstall_keys(): array` — declares `options`, `meta` (**post** meta), `user_meta`, `post_type`, `taxonomy`, `tables` (own tables, **without prefix**, dropped with `DROP TABLE`) and `cron` (scheduled task hooks) for the uninstall cleanup; every key is optional. The two meta channels live in different tables: a user meta declared under `meta` is never deleted. The `cron` hooks are also unscheduled by `Deactivator` when the plugin is deactivated: without that, WordPress kept firing every day a hook nobody listens to.

Optional overrides: `get_admin_css()`, `get_admin_js()`, `get_admin_js_deps()`, `get_admin_js_data()`, `to_form_payload()`, `get_export_extras()` / `import_extras()`, `get_required_capability()`, `on_activate()`, `on_deactivate()`.

### Required capability

`static get_required_capability(): string` — capability required to open the module screen **and** call its endpoints; `manage_options` by default. To be overridden by any module whose power goes beyond the current site: under **multisite**, `manage_options` is a **per-site** capability, so the administrator of a mere sub-site gets it — a file manager or an SQL editor would then hand them the whole network, files and database being shared. Files and Database therefore return `is_multisite() ? 'manage_network_options' : 'manage_options'`. `Admin` uses it for the `add_submenu_page()` capability (WordPress hides the entry) **and** tests again in `render_page()`: the tab URL remains guessable, this is where the refusal matters.

### `ABSPATH` guard

Every PHP file of the plugin carries `defined( 'ABSPATH' ) || exit;` — after the `namespace` line if there is one, otherwise after the header docblock. Without it, a direct request to the file runs it outside WordPress: `templates/components/sidebar.php` answered 200 with a fatal error disclosing the server's absolute path. It is also blocking for any submission to the WordPress.org repository.

### Export of state kept outside the module option

`get_export_extras()` / `import_extras()` declare module state kept **outside** `lumia_module_{id}` (MenuCreator: the profiles under `lumia_wl_menu_profiles`). Without that, the configuration export believes it is complete while leaving that option aside. The block lands under `extras.{module_id}` in the JSON, and the import hands it back to the module — which re-sanitizes it itself, like `to_form_payload()` does for the settings.

### Shared JS dependencies

`get_admin_js_deps()` returns the already registered script handles the module's JS depends on. Use it for shared third-party libraries rather than returning their URL from `get_admin_js()`: two modules doing that produce two handles for the same file, which WordPress cannot deduplicate. `lumia-sortable-js` is registered by `Admin::enqueue_assets()` and consumed this way by MenuCreator; the Media module enqueues the same handle.

### `to_form_payload()`

`to_form_payload(array $stored): array` converts the **stored** settings to the shape `save_settings()` expects. It exists for the configuration import, which must reuse the module's sanitizer rather than write the JSON straight into the option. The default is the identity — only override it if the two shapes differ (Security stores under `authentication`/`hardening` what the form posts flat).

### Settings template

A module's template lives in `includes/Modules/{ModuleName}/settings-template.php` (loaded by `templates/admin/module-settings.php`). Available variables: `$module_id`, `$module`, `$instance`, `$module_settings`, `$tab`.

Fields must use `name="lumia_module_settings[field_name]"` and the hidden field `lumia_tab=module_{id}` so that `Admin::handle_save_settings()` routes the POST.

### Data passed to JS

The values returned by `get_admin_js_data()` are injected into `window.lumiaAdmin` by `Admin::enqueue_module_assets()`: the `i18n` key is merged into `window.lumiaAdmin.i18n`, any other key is set directly on `window.lumiaAdmin[key]` (JSON-encoded). It carries any module data to the JS (`mcProfiles`, `wpMenu`), not only translations.

#### Translatable strings in JS

Source strings are English (see [Translations](#translations)), and there is a single pipeline: a user-visible string is **never** a literal in a `.js` file. PHP translates it with `__()` and hands it to the script:

- **A module** returns its strings under the `i18n` key of `get_admin_js_data()` (create the method if the module has none), keys in English camelCase:

  ```php
  public function get_admin_js_data(): array {
      return [
          'i18n' => [
              'deleteConfirm' => __( 'Delete this item? This cannot be undone.', 'lumia-tools' ),
          ],
      ];
  }
  ```

  The JS reads `lumiaAdmin.i18n.deleteConfirm`. Do not keep a literal fallback (`|| "Delete?"`): a fallback in one language defeats the translation. Use `|| ""` at most.
- **A string with a placeholder** is built in PHP when the placeholder is known there; otherwise pass the format with numbered placeholders (`%1$s`, with a `/* translators: */` comment) and substitute in JS.
- **The core** (`admin.js`) reads the array built in `Admin::localize_admin_script()`, handed to `wp_localize_script( 'lumia-admin-js', 'lumiaAdmin', … )`.

Generic keys of `lumiaAdmin.i18n` that modules may reuse instead of duplicating them (a module key with the same name overrides them, since the module `i18n` is merged on top):

| Key | English source |
|---|---|
| `saveSuccess` | Settings saved successfully. |
| `saveError` | An error occurred. |
| `confirmAction` | Are you sure? |
| `confirm` | Confirm |
| `cancel` | Cancel |
| `error` | Error |

The other keys (`configure`, `unsavedTitle`, `unsavedText`, `unsavedLeave`, `unsavedStay`) belong to the core screens: do not rely on them.

`lumiaAdmin` exists only on the plugin pages. `notifications.js` loads on the whole WP admin and therefore reads its own strings from `window.lumiaNotifData.i18n` (printed by `Admin::render_notification_drawer()`: `close`, `noNotifications`, `brand`).

## Translations

All strings in the code (PHP and JS) are **English**, text domain `lumia-tools`; the French UI comes from `languages/lumia-tools-fr_FR.po` / `.mo`. A string with a placeholder carries a `/* translators: */` comment and numbered placeholders (`%1$s`) as soon as there are two. Two different French texts for the same English source cannot coexist in a `.po`: disambiguate with `_x()` and a short English context.

Adding a string:

1. Write it in English with `__()` / `_x()` / `_n()` and the `lumia-tools` domain (for JS, see above).
2. `composer i18n:pot` regenerates `languages/lumia-tools.pot`.
3. Add the French translation to `languages/lumia-tools-fr_FR.po`.
4. `composer i18n:mo` compiles the `.mo`, `composer i18n:check` verifies that the `.po` covers the `.pot` and that the `.mo` matches the `.po`. The CI runs the same check.

Tooling details: `tools/i18n/README.md`.

## Compatibility with Studio Kyne Mini Tools (`Core\Compat`)

Sites migrated from the former name may still define `SKMT_*` constants in `wp-config.php` and hook snippets on `skmt_*` filters and actions. Every read of those public names goes through `Compat`, never through `defined()` or a direct `apply_filters()`:

- `Compat::constant( 'SMTP_USER' )` / `Compat::has_constant( 'SMTP_USER' )`: `LUMIA_SMTP_USER` first, `SKMT_SMTP_USER` as a fallback (`null` / `false` if neither exists). Covers `DISABLE_LOGIN_URL`, `SMTP_USER`, `SMTP_PASSWORD`, `BREVO_API_KEY`, `ENCRYPTION_KEY`.
- `Compat::apply_filters( 'custom_login_redirect', $value, ...$args )` / `Compat::do_action( 'register_modules', ...$args )`: if something is hooked on `skmt_*`, it runs first through `apply_filters_deprecated()` / `do_action_deprecated()` (version `2.0.0`, notice in `WP_DEBUG`), then `lumia_*` runs. Covers `module_definitions`, `register_modules`, `custom_login_redirect`, `db_table_owner_aliases`, `activity_log_post_types`, `activity_log_content_meta_keys`, `activity_log_tracked_options`, `activity_log_record`, `mc_editor_excluded_slugs`.

The admin help texts only mention the `LUMIA_*` names. A new public filter, action or wp-config constant is added through `Compat` only if a site could already have used its `skmt_` / `SKMT_` ancestor. Checked on the Docker bench by `tools/e2e/run.sh assert-compat`.

## Migration from SKMT (`Core\Migration\FromSkmt`)

Lümia Tools is Studio Kyne Mini Tools (SKMT) renamed. On the sites that ran SKMT, Lümia is installed next to it and **migrates its data on activation** (`Activator::activate()`, admin or `wp plugin activate`). Condition (`FromSkmt::needed()`): `skmt_settings` exists and the marker `lumia_migrated_from_skmt` does not. Checked on the Docker bench by `tools/e2e/run.sh assert-migration`, `assert-after-uninstall`, `reactivate-lumia` and `assert-partial` (see `tools/e2e/README.md`).

**Migration, then defaults — never the other way round.** `Activator::activate()` calls `FromSkmt::run()` *before* creating `lumia_settings` and the `lumia_module_*` defaults. The copy never overwrites an existing `lumia_*` option: had the defaults been created first, `lumia_settings` would exist with every module off and the site's real settings would be skipped. For the same reason, a migration that fails creates **no** default at all, so that the resumed copy still finds nothing in its way.

Steps, in order, each one checking its own state so that a second run completes a partial one without duplicating anything:

| Step | What |
|---|---|
| `options` | `skmt_settings`, `skmt_module_*`, optimizer stats/bulk state/backup token, menu profiles and cache generation, the two schema options: **copied** to `lumia_*` with their autoload flag. In the menu profiles (and the MenuCreator setting), a string equal to `studio-kyne-mini-tools` or starting with `studio-kyne-mini-tools&` becomes `lumia-tools…` (recursive). The originals stay. |
| `secrets` | `skmt_smtp_password`, `skmt_smtp_brevo_key`: **re-encrypted** (below), then copied. |
| `tables` | `{prefix}skmt_activity_log`, `{prefix}skmt_mail_log`: `RENAME TABLE` if the target does not exist; if both exist, the legacy rows are appended to the Lumia table, then the legacy table is dropped. |
| `activity_rows` | Rows `event = 'skmt_settings'` / `object_type = 'skmt'` rewritten to `lumia_settings` / `lumia`. |
| `post_meta`, `user_meta`, `term_meta` | **Renamed in place**, one exact key per `UPDATE` (closed lists, never a `LIKE`), meta cache of the objects involved dropped. |
| `taxonomy` | `skmt_media_folder` renamed to `lumia_media_folder` in `term_taxonomy`; term caches and both taxonomies' caches cleared. |
| `originals` | `uploads/skmt-originals-{token}` renamed to `lumia-originals-{token}`. A failed `rename()` is **not** a failure: `get_backup_dir()` keeps reading the old folder while the new one does not exist. |
| `crons` | A pending `skmt_image_optimizer_cron` event is scheduled again on `lumia_image_optimizer_cron` with the same arguments and timestamp; every `skmt_*` event is removed. The log purges are rescheduled by their module's `init()`. |
| `deactivate` | SKMT deactivated on this site (not silently: its own deactivation routine runs). |

Then the marker (timestamp), `lumia_migration_error` deleted, and `lumia_migration_notice` set: the first administrator to open an admin page gets the persistent notice "Migration from Studio Kyne Mini Tools complete…".

**Why tables and meta are renamed rather than copied.** Deleting SKMT runs its `uninstall.php`, which drops `{prefix}skmt_*` tables, deletes every `_skmt_*` / `skmt_*` meta it declares and **every term** of `skmt_media_folder`. Copies would leave those for it to delete — the media folders of the site with them. Once renamed, its uninstall only finds the original options, which were copied: nothing of Lümia is touched. Options, on the other hand, are copied: they are the safety net as long as SKMT is not deleted.

**SKMT is live during the activation request.** WordPress loads it before running Lümia's activation hook, so its hooks still fire after the tables are renamed: its activity log records the deactivation of SKMT and the activation of Lümia, and its `Store::insert()` re-creates a missing table before writing (measured on the bench: `wp_skmt_activity_log` came back with those two rows). `migrate_tables()` therefore hooks SKMT's `skmt_activity_log_record` filter: the row is written into `{prefix}lumia_activity_log` and SKMT is told to skip it. The SMTP log of SKMT has no such filter: a mail sent during that very request would re-create `skmt_mail_log` (none is sent by an activation).

**Re-encryption.** The SMTP secrets are AES-256-GCM with a key `sha256( context . material )`. The context changed with the name (`'skmt-smtp|'` → `'lumia-smtp|'`, `Crypto::CONTEXT` / `LEGACY_CONTEXT`); the material did not: `Compat::constant( 'ENCRYPTION_KEY' )` (`LUMIA_ENCRYPTION_KEY`, else `SKMT_ENCRYPTION_KEY`), else `LOGGED_IN_KEY . LOGGED_IN_SALT`, else `wp_salt( 'logged_in' )`. `Crypto::reencrypt_from_legacy()` decrypts with the old context and encrypts with the new one. A site that fixed its key with `SKMT_ENCRYPTION_KEY` keeps decrypting after the migration and in every later request, because the new key reads the same constant. GCM is authenticated, so an unreadable legacy value (salts regenerated before the migration) is detected: it is copied unchanged — the same state as before, the administrator types the password again.

**Failure.** A step fails when it returns false or leaves `$wpdb->last_error` set (reset before each step, checked after each query: `wpdb` clears it at every query). The step name goes to `lumia_migration_error`, the run stops, SKMT stays active. Database errors are logged, not printed (`hide_errors()`): any output during an activation is reported as "unexpected output". Deactivating then reactivating Lümia resumes the migration.

**Hold (`FromSkmt::on_hold()`).** Lümia keeps every module off while SKMT is loaded (both would hook the login URL, SMTP, white label…) **or** while `lumia_migration_error` exists (a module would write `lumia_*` data — a table, default options, a re-optimized image — that the resumed migration must not find). Two traps:

- **Load order.** Active plugins load alphabetically: `lumia-tools/` before `studio-kyne-mini-tools/`. `SKMT_VERSION` does not exist yet when Lümia's main file runs; the test lives in `Plugin::init()`, on `plugins_loaded`, when every plugin is loaded.
- **Cost.** It runs on every request. `lumia_migration_error` (and `lumia_migration_notice`) are stored **autoloaded** and read through `wp_load_alloptions()`: a healthy site pays no query for an absent option.

A stale error whose data is gone (SKMT deleted after a failed attempt) is cleared by the next activation, since `needed()` is then false.

Out of scope: multisite network logic (the sites are single sites). Lümia's `uninstall.php` deletes the marker and the two state options.

## Adding a module

1. Create `includes/Modules/MyModule/Module.php` extending `AbstractModule`
2. Add a `settings-template.php` in the same folder
3. Register through the filter (no core file to touch):

```php
add_filter( 'lumia_module_definitions', function( array $modules ) {
    $modules['my_module'] = [
        'name'    => __( 'My Module', 'lumia-tools' ),
        'class'   => 'Lumia\\Tools\\Modules\\MyModule\\Module',
        'icon'    => 'package',
        // name, description, menu_label, menu_desc, icon
    ];
    return $modules;
} );
```

4. Add the class to `Activator::MODULE_CLASSES` (`includes/Core/Activator.php`) — the single list shared by the activation defaults and `uninstall.php`

## Admin forms

All the settings forms post to `admin-post.php`. The action name determines the handler:

- `lumia_save_settings` → `Admin::handle_save_settings()`
- `lumia_toggle_module` → `Admin::handle_toggle_module()`
- `lumia_update_modules` → `Admin::handle_update_modules()`
- `lumia_check_updates` → `Admin::handle_check_updates()`
- `lumia_reset_settings` → `Admin::handle_reset_settings()`

All of them verify a nonce and the `manage_options` capability, then redirect with `?lumia_notice=...`.

### Settings import

`handle_import_settings()` must never write the uploaded JSON straight into the options — that bypasses every module sanitizer (unfiltered HTML in the white-label footer, arbitrary SVG roles, free login slug). Each module block is replayed through `to_form_payload()` then `save_settings()`, so the file follows exactly the form's path. Two details matter: `false` values are removed before the call, because an HTML form omits its unchecked boxes and some modules test `isset()` rather than the value; and the `lumia_settings` block is merged over the existing option rather than replacing it, so that a partial file does not erase the activation state of the modules it does not mention. Before any read: `is_uploaded_file()` on `$_FILES[…]['tmp_name']` — this value comes from the client, and it is the only thing that attests it designates a file uploaded by *this* request and not an arbitrary server path — then an `IMPORT_MAX_BYTES` ceiling (2 MiB), the file being read whole **then** decoded as JSON, i.e. two copies in memory.

### Downloads

Every `Content-Disposition` header goes through `Admin::content_disposition( $filename )`. The name used to be injected as is between quotes: on Linux a file name can contain a quote — which closes the value and lets parameters be added — or even a line break, which ends the header. The helper emits the two RFC 6266 parameters: `filename=` as sanitized ASCII for old clients, and percent-encoded `filename*=UTF-8''…`, which carries the real name with no quotes to close.

## AJAX endpoints

Module AJAX actions follow the naming `wp_ajax_lumia_{module}_{action}` and an identical guard pattern at the top of every handler:

```php
check_ajax_referer( 'lumia_admin_nonce', 'nonce' ); // or wp_verify_nonce() + manual wp_send_json_error()
if ( ! current_user_can( 'manage_options' ) ) {
    wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'lumia-tools' ) ] );
}
```

Any request data goes through `sanitize_text_field( wp_unslash( $_POST[...] ) )` (or the sanitizer suited to the type) before use; responses go through `wp_send_json_success()` / `wp_send_json_error()`. The nonce is created once via `wp_create_nonce( 'lumia_admin_nonce' )` and shared between modules (`window.lumiaNotifData.nonce` / `window.lumiaAdmin`).

## Icons

Icons are inline SVGs rendered through `Admin::render_icon(string $icon, string $size, string $extra_class)`. The available icons are defined in `Admin::get_icon_paths()`: `layout-dashboard`, `package`, `settings`, `image`, `check-circle`, `info`, `shield`, `bell`, `x`, `log-in`, `folder`, `chevron-down`, `palette`, `menu`, `database`, `eye-off`, `folder-tree`, `history`, `mail`.

**Never invent or hand-draw an SVG.** All the icons come from [Lucide](https://lucide.dev) (lucide-static v1.34.0). For an icon missing from `get_icon_paths()` or from a module's JS, fetch the official file as is. Do not approximate by editing another icon's path, do not make up coordinates: the result looks broken and departs from the rest of the interface. This also applies to the modules' inline JS SVGs (folder icons in `assets/admin/js/modules/media.js`).

## Persistent notices

Distinct from the ephemeral toasts (`lumiaShowToast`), `Admin::add_persistent_notice(string $id, string $message, string $type, int $user_id = 0)` / `Admin::dismiss_persistent_notice(string $id)` store notices in the `lumia_notices` user meta so that they survive reloads. `$user_id` defaults to the current user but can target a specific user from a context with no user (a cron finishing a background job). Rendered in `window.lumiaPersistentNotices` in the notification drawer; dismissed client-side through the `wp_ajax_lumia_dismiss_notice` endpoint (`Admin::handle_dismiss_notice()`).

## Updater

`Core/Updater.php` caches the GitHub response for 12 h **and caches failures for 15 min**. Without this negative cache, an unreachable GitHub or an exhausted anonymous quota (60 req/h) triggers a new 10 s HTTP call on every check, i.e. on almost every admin page load. The "check for updates" button first deletes both transients, so it always bypasses the cache.

- **Changelog.** The API is called with `Accept: application/vnd.github.html+json`: GitHub returns the notes already rendered (`body_html`), no Markdown parser to bundle. The HTML goes through `wp_kses_post()` before landing in the `changelog` tab of `plugins_api`. On the dev channel, the notes of the last 10 versions are cached and the modal shows all those newer than the installed version (an update often skips several pre-releases).
- **Pre-release numbering (#73).** `release-dev.yml` targets the **next patch** after the latest stable: after `v1.1.0` come `1.1.1-dev.1`, `1.1.1-dev.2`… It used to restart from the stable (`1.1.0-dev.N`), but for SemVer as for `version_compare()`, `1.1.0-dev.N` is **older** than `1.1.0`: the updater compensated with per-channel special cases. Now `compare_versions()` is just a `version_compare()`, with no exception.
- **The dev channel also follows stable releases.** It takes the highest version among pre-releases and stable ones (drafts excluded). Looking only at pre-releases, a site on `1.0.13-dev.19` did not see `1.1.0` before the next push to `dev`.
- **Automatic update.** The Settings toggle writes straight into the WordPress option `auto_update_plugins`, the same as the "Automatic updates" column of the plugins list: a single source of truth, nothing in `lumia_settings`, hence neither export nor reset. It is disabled if `wp_is_auto_update_enabled_for_type( 'plugin' )` is false or without `update_plugins` (multisite: super admin only).
- **Dashboard.** `Updater::get_status()` reads **only** the transient: displaying the channel and the remote version must never trigger a 10 s HTTP call. Empty cache → no badge.
- **No ETag, no token (decision of 2026-09-19, #18).** A conditional request that gets a `304` only spares the GitHub quota **if it is authenticated**; anonymously it counts like any other. The repository staying public, no token, so the ETag would only bring a bandwidth gain: discarded. The quota stays protected by the 12 h cache and the negative cache.

## Development tooling

Composer is used **only** for development: no runtime dependency, `vendor/` is ignored by Git and excluded from the release ZIPs (like `composer.*`, `phpcs.*`, `phpstan*`, `tools/`, `CLAUDE.md`, `docs/`).

```bash
composer install          # PHPCS (WPCS + PHPCompatibilityWP), PHPStan (+ WordPress stubs)
composer lint             # phpcs — composer lint:fix for phpcbf
composer analyse          # phpstan, level 8
composer check            # both
```

No PHP on the machine (macOS, Windows): go through the Docker image.

```bash
docker run --rm -v "$PWD:/app" -w /app composer:2 check                 # macOS / Linux
MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd -W):/app" -w /app composer:2 check   # Windows, so Git Bash does not convert the paths
```

The `lint.yml` workflow replays `php -l`, PHPCS and PHPStan on every PR to `dev` and `main`.

**No baseline.** PHPCS and PHPStan both run without a baseline: any finding blocks the CI. PHPStan lost its own in PR #52 and has run **at level 8** since PR #54 (`string|false` unions and nullables handled explicitly). **We stop at level 8.** Measured on 2026-09-19: levels 9 and 10 double then quadruple the total by penalizing `mixed`, yet WordPress returns `mixed` everywhere (`get_option()`, `get_post_meta()`, `$_POST`) — that would be hundreds of defensive casts for no gain. The PHPCS baseline was cleared family by family (PRs #55 to #58, tracked in issue #50) then removed along with the `php-codesniffer-baseline` package. Never recreate one to make a finding pass: fix it, or add a justified `phpcs:ignore` / `@phpstan-ignore` (`-- reason` after the sniff code). When a rule is wrong for a whole part of the code, exclude it in `phpcs.xml.dist` with a comment rather than annotating every line (templates included from an `Admin` method, `FileManager`).

Pitfalls met while clearing the baseline: PHPCS does not see a nonce verified in a helper method (`guard()`, `check_nonce()`), hence the justified `phpcs:ignore` / `phpcs:disable` at the top of the AJAX handlers; it does not recognize the cast `(int) ( $_POST['x'] ?? 0 )`, which has to be written `isset( $_POST['x'] ) ? (int) $_POST['x'] : 0` — and do not replace that `(int)` with `absint()`: `absint(-5)` is 5, whereas the code treats negative identifiers as "no folder". A `phpcs:ignore` only covers the line where it is placed (or the next one if it stands alone on its line): on a multiline call, put it above the line carrying the flagged function. The sniff code in a `phpcs:ignore` must be exact: a wrong code (`file_system_read_readfile` instead of `file_system_operations_readfile`) ignores nothing, with no warning at all. To remove an unused hook parameter, lower `accepted_args` in the matching `add_filter()`. `FileManager` exception messages are not escaped at the source: they go out as JSON and the toast escapes them at display time.

Assumed exclusions in `phpcs.xml.dist`: docblocks (`Squiz.Commenting`), PSR-4 file names (`WordPress.Files.FileName`), line endings (Git normalizes them). The templates (`templates/`, `settings-template.php`) are included from an `Admin` method: PHPStan does not analyze them and PHPCS does not require a prefix on their variables. `tools/phpstan-bootstrap.php` declares the constants missing from the stubs (`WPINC`).
