# CLAUDE.md

Working guide for Claude Code on this repository. The rules below are absolute; the detail (full architecture, per-module pitfalls, design system) lives in [docs/](docs/README.md) and is read **on demand**, when the task touches the subject.

## The project

**Lümia Tools** (repository `agence-lumia/lumia-tools`, text domain and slug `lumia-tools`) is a modular WordPress plugin (PHP 8.0+, WP 6.9+). It is the former Studio Kyne Mini Tools (SKMT), renamed in 2.0.0. No build step in development, no runtime Composer, no npm: plain PHP with a home-grown PSR-4 autoloader (`Lumia\Tools\` → `includes/`). The source assets stay readable; only the release zip is minified (see below). No automated tests of the plugin itself (the only checks are `composer check`, the language-file gate and the Docker bench, see below). Third-party JS is shipped as is under `assets/admin/js/vendor/`, never bundled.

Ten modules under `includes/Modules/`: Security, WhiteLabel, ImageOptimizer, MenuCreator, Login, Files, Media, Database, ActivityLog, Smtp. Each extends `AbstractModule`, registers its hooks in `init()`, sanitizes what it persists itself (`save_settings()` — the core applies nothing) and declares its uninstall keys. Settings: `lumia_settings` (global + module state) and `lumia_module_{id}` (per module), merged **recursively** over the defaults.

Lifecycle: `plugins_loaded` → `Plugin::instance()` → `init` → text domain loading, module definitions (filter `lumia_module_definitions`), `Module::init()` on every active module. `Admin` exists only under `is_admin()`. Every form posts to `admin-post.php` with a nonce + `manage_options`; every AJAX endpoint (`wp_ajax_lumia_{module}_{action}`) checks `lumia_admin_nonce` then the module capability.

## Absolute rules

- **Never bump the version by hand.** CI does it (`* Version:` and `LUMIA_VERSION` in `lumia-tools.php`, always in step). A push to `dev` → automatic pre-release; stable → `workflow_dispatch` on `main`, input `bump` = `patch` (default), `minor` or `major` (`gh workflow run release-please.yml -f bump=minor`). The release asset is `lumia-tools-<version>.zip` (folder `lumia-tools/`), built by `tools/build/build-zip.sh` in both release workflows (and on every PR by `lint.yml`): excludes from `tools/build/zip-excludes.txt`, `.po`/`.pot` left out, a `.l10n.php` compiled next to the `.mo`, JS/CSS minified with a pinned esbuild ([docs/core.md](docs/core.md#release-zip)).
- **`ABSPATH` guard** on every PHP file: `defined( 'ABSPATH' ) || exit;` after `namespace`, otherwise after the docblock.
- **Never a drawn or approximated SVG.** Every icon comes from [Lucide](https://lucide.dev) (lucide-static v1.34.0), the official file fetched as is — in PHP (`Admin::get_icon_paths()`) as in the modules' JS.
- **Design-system components only** (`components.css` + `admin.js`): modals, tooltips, toasts, buttons, forms, tabs. Do not code an equivalent. See [docs/design-system.md](docs/design-system.md).
- **Never trust the client**: every `$_POST` / `$_GET` / `$_FILES` goes through the matching sanitizer after `wp_unslash()`; paths, SQL identifiers, IPs and imported JSON are validated server-side (see the module pages).
- **No PHPCS/PHPStan baseline**: never recreate one to make a finding pass. Fix it, or add a justified `phpcs:ignore` / `@phpstan-ignore`.
- **Document pitfalls in `docs/`**, not here: when a fix comes from a counter-intuitive WordPress behavior or from a bug that cost something, write it in the module page (what broke, why this solution).
- **Everything is English**: identifiers, comments, docblocks, UI strings in the code, `docs/`, this file, the README and the GitHub templates. French lives only in `languages/lumia-tools-fr_FR.po` / `.mo` (see Translations).

## Working convention

Tracking lives **in GitHub issues** (`gh issue list`): always read them before proposing a plan.

1. **One issue per subject.** Templates in `.github/ISSUE_TEMPLATE/`.
2. **One branch per issue**, created from `dev`: `feat/<n>-<subject>`, `fix/<n>-<subject>`, `docs/<n>-<subject>`, `chore/<n>-<subject>`.
3. **Commits are Conventional Commits, in English**: `type(scope): description` — types `feat`, `fix`, `docs`, `style`, `refactor`, `chore`, `ci`; scope = module or area (`security`, `media`, `core`, `i18n`, `lint`…). (Earlier history is in French; from 2.0.0 on, everything new is English.)
4. **PR to `dev`**, title in Conventional Commits, body from `.github/PULL_REQUEST_TEMPLATE.md`, with `Closes #n`. Read the automatic Copilot review (about 2 min) before merging.
5. **Batch the merges locally, then a single push** of `dev`, so that only one pre-release is triggered per batch. `Closes #n` only closes the issue when it reaches `main`: after merging into `dev`, close it by hand with `gh issue close`.
6. **`composer check` before the PR** (PHPCS + PHPStan). No PHP on the machine: run it through Docker, from the repository root.
   - macOS / Linux: `docker run --rm -v "$PWD:/app" -w /app composer:2 check`
   - Windows, PowerShell: `docker run --rm -v "${PWD}:/app" -w /app composer:2 check`
   - Windows, cmd: `docker run --rm -v "%cd%:/app" -w /app composer:2 check`
   - Windows, Git Bash: `MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd -W):/app" -w /app composer:2 check` (so the paths are not converted)

   The `lint.yml` workflow replays it on every PR, plus the language-file gate.

## Translations (text domain `lumia-tools`)

All strings in the code, PHP and JS, are **English**. JS strings are passed from PHP (`lumiaAdmin.i18n` for the core, `get_admin_js_data()` for modules), so a single `.mo` serves both: no `wp.i18n`, no JSON translation files (see [docs/core.md](docs/core.md#translations)). A string with a placeholder carries a `/* translators: */` comment, and numbered placeholders (`%1$s`) as soon as there are two. Two different French texts for one English source cannot coexist in a `.po`: disambiguate with `_x()` and a short English context.

Adding a string:

1. Write it in English with `__()` / `_x()` / `_n()` and the `lumia-tools` domain.
2. `composer i18n:pot` regenerates `languages/lumia-tools.pot`.
3. Add the French translation to `languages/lumia-tools-fr_FR.po` (the `.po` is the only source of the French text).
4. `composer i18n:mo` compiles the `.mo`.
5. `composer i18n:check` verifies that the `.po` covers the `.pot` and that the `.mo` matches the `.po`. The `i18n` job of `lint.yml` runs the same check: a string added without a `.po` entry fails the CI.

The `i18n:*` scripts need WP-CLI, which the `composer:2` image does not have: `tools/i18n/wp.sh` uses `wp` if present, otherwise downloads the pinned WP-CLI phar once into `tools/i18n/.cache/` (git-ignored, checked against its SHA-512). So the same Docker form works everywhere:

```bash
docker run --rm -v "$PWD:/app" -w /app composer:2 i18n:pot
docker run --rm -v "$PWD:/app" -w /app composer:2 i18n:mo
docker run --rm -v "$PWD:/app" -w /app composer:2 i18n:check
docker run --rm -v "$PWD:/app" -w /app composer:2 i18n:test    # tests of the tooling
```

(Windows: replace `"$PWD:/app"` with `"${PWD}:/app"` in PowerShell, `"%cd%:/app"` in cmd.) Details, the WP-CLI-image alternative and the tool's exit codes: [tools/i18n/README.md](tools/i18n/README.md).

## Legacy names: SKMT compatibility and migration

No `skmt` / `SKMT` / `studio-kyne` / `StudioKyne` name survives on a Lümia site except in the two mechanisms below, which exist for sites that ran SKMT:

- **`Core\Compat`** — `SKMT_*` wp-config constants and `skmt_*` hooks are **still honored**, deprecated since 2.0.0 (`apply_filters_deprecated()` / `do_action_deprecated()`, notice in `WP_DEBUG`). Every read of a public constant, filter or action goes through `Compat`, never through `defined()` or a direct `apply_filters()`. The admin help texts mention only the `LUMIA_*` names.
- **`Core\Migration\FromSkmt`** — the **only** place for legacy data names. On activation (before the defaults are created) it copies the options, renames tables / meta / the media taxonomy / the originals folder, re-encrypts the SMTP secrets and deactivates SKMT. Never replace it with ad-hoc legacy handling elsewhere. Behavior and pitfalls: [docs/core.md](docs/core.md#migration-from-skmt-coremigrationfromskmt); per-site procedure: [docs/migration-from-skmt.md](docs/migration-from-skmt.md).

Allow-list of files that may contain a legacy name in code or data (anything else is a leftover to remove; prose that merely names the former plugin, as in the README or the docs, is fine):

- `includes/Core/Compat.php`
- `includes/Core/Migration/FromSkmt.php`
- `includes/Modules/Smtp/Crypto.php` (legacy key-derivation context, needed to re-encrypt)
- `includes/Modules/ImageOptimizer/Module.php` (`LEGACY_BACKUP_DIR`)
- `includes/Core/Plugin.php` (the hold branch)
- `includes/Core/Activator.php`
- `uninstall.php`
- `docs/core.md` and `docs/migration-from-skmt.md`
- `tools/e2e/` (the bench installs the real SKMT)

(`docs/superpowers/` is a design archive: never renamed or translated.) No accented French character belongs in `includes/`, `templates/`, `assets/`, `docs/`, `README.md`, `CLAUDE.md` or `.github/`; the only exception is "Lümia".

## Docker bench (`tools/e2e/`)

A disposable WordPress + MariaDB + wp-cli bench that installs SKMT as it was before the rename (`8d4cd85`) with realistic data, captures the visible admin text, then installs the working tree to check the migration, the compatibility layer and the texts. Nothing in `tools/` is shipped (`tools/build/zip-excludes.txt`). `LUMIA_ZIP=<zip> tools/e2e/run.sh install-lumia` installs a prebuilt zip (a `build-zip.sh` output) instead of the working tree. The mu-plugin `mu-plugins/e2e-auth.php` logs in any request carrying `X-E2E-User`: **it must never leave the bench**.

```bash
tools/e2e/run.sh up                       # start (http://localhost:8089, admin / admin)
tools/e2e/run.sh seed-skmt                # SKMT 8d4cd85 + data (--minimal, --encryption-key, --folder=<name> variants)
tools/e2e/run.sh capture baseline         # admin text → tools/e2e/out/baseline/
tools/e2e/run.sh install-lumia            # zip of the working tree, installed and activated
tools/e2e/run.sh assert-migration         # also: assert-compat, reactivate-lumia, assert-after-uninstall, assert-partial, assert-interrupted, assert-reinstall
tools/e2e/run.sh down                     # stop and delete the data
```

When to run it: before merging any change to `Core\Migration\FromSkmt`, `Core\Compat`, `Core\Activator`, `Smtp\Crypto`, the ImageOptimizer backup folder, or the table / meta / option names; and for the text comparison, after a bulk change of UI strings. A change to an ordinary module screen does not need it. Commands, scenarios, seeded data and exit codes: [tools/e2e/README.md](tools/e2e/README.md).

## Where to read the detail

- [docs/core.md](docs/core.md) — boot, autoloader, storage, the `AbstractModule` contract (required capability, `to_form_payload()`, `get_export_extras()`, `get_admin_js_deps()`, `get_admin_js_data()`), translations, SKMT compatibility and migration, adding a module, forms, settings import, downloads, AJAX, icons, persistent notices, updater, tooling.
- [docs/design-system.md](docs/design-system.md) — BEM classes `lumia-`, tokens, modals, forms, buttons, tooltips, toasts.
- [docs/modules/](docs/README.md#modules) — one page per module, to open before touching that module.
- [docs/migration-from-skmt.md](docs/migration-from-skmt.md) — moving a site from SKMT to Lümia Tools.
