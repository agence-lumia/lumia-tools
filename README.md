# Lümia Tools

A modular, lightweight and fast WordPress plugin to optimize and improve your site. Formerly Studio Kyne Mini Tools: if you are moving a site from that plugin, follow [docs/migration-from-skmt.md](docs/migration-from-skmt.md).

Requires WordPress 6.9+ and PHP 8.0+. The interface is in English and ships with a French translation (`fr_FR`).

## Main features

- Modular architecture: enable only the modules you need
- Modern, fast custom admin interface
- Updates through GitHub (Stable and Dev channels)
- Ten modules: Image Optimizer, Security, White Label, Menu Creator, Login, Files, Database, Media, Activity Log, SMTP

## Installation

1. Download `lumia-tools-<version>.zip` from the latest [GitHub release](https://github.com/agence-lumia/lumia-tools/releases) (the asset, not the "Source code" archives).
2. Upload it in Plugins > Add New > Upload Plugin.
3. Activate the plugin.
4. Open the LUMIA menu in the admin.

Coming from Studio Kyne Mini Tools? Activating Lümia Tools migrates your data by itself, but back up first: see [docs/migration-from-skmt.md](docs/migration-from-skmt.md).

## Updates

In Settings > Updates:

- Choose the Stable (main) or Dev (pre-release) channel.
- Click Check for updates to force a check.

## Modules

### Image Optimizer

- AVIF version of every JPEG/PNG, generated in the background and served only to the browsers that accept it (the JPEG/PNG stays the file, at the same URL)
- Delivery self-test: nginx rule, or `.htaccess` rules written on Apache/LiteSpeed; nothing is generated until the test passes
- Configurable quality and encoding speed, maximum dimension
- EXIF removal (lossless on the served JPEG files)
- Per-image "serve the original format" switch, exclusion by file name suffix
- Automatic alt text
- Bulk optimization
- `wp lumia images migrate` for the media converted by earlier versions
- Safe SVG upload (allow-list sanitizing, allowed per role)

### Security

- Login attempt limiting (rate limiting)
- Configurable IP origin: direct connection (default), Cloudflare or reverse proxy.
  Proxy headers are sent by the client: only enable them if the site really sits behind
  that proxy, otherwise the block can be bypassed by changing the header.
- Custom login URL.
  If you forget the slug, adding `define( 'LUMIA_DISABLE_LOGIN_URL', true );` to
  `wp-config.php` re-enables `wp-login.php`.
- Hardening (user enumeration protection, REST users endpoint)

### White Label

- Admin bar and footer cleanup
- Profile page cleanup (hides unneeded sections)
- Local avatars (uploaded through the media library, take precedence over Gravatar)
- Menu profiles (stored and resolved per user, role or globally)

### Menu Creator

- Reorder and hide wp-admin menu entries (drag and drop)
- Rename, separators, custom links, icons (Lucide library)
- Optional blocking of direct access to hidden pages, links reserved to some roles
- Menu import / export (globally or menu by menu)
- Keyboard shortcuts: Ctrl/Cmd+S saves, Ctrl+Z / Ctrl+Y undo and redo
- Flags obsolete entries (slug missing from the current WordPress menu)
- Icon search also understands French keywords, accent-insensitive

### Login

- Login page customization (logo, colors, side panel)
- Optional hiding of: language switcher, lost password link, back to site link

### Files

- File manager (list, rename, move, delete)
- Upload, zip/unzip, edit and download, rooted at `ABSPATH`
- Code editor with syntax highlighting and autocompletion (CodeMirror shipped by
  WordPress; follows the syntax highlighting setting of the user profile)

### Database

- Table explorer/editor on `$wpdb` (data, structure)
- Typed row editing, insertion and deletion (NULL supported)
- Safe free-form SQL editor (forbidden keywords, confirmation of writes, row cap)
- Typed `.sql` export, query history (stored in the browser only)

### Media

- Virtual folders in the media library: no file is moved on disk
- Folder panel wherever WordPress shows a media library (grid and list Media page,
  block editor, Customizer, page builders)
- Drag and drop of media and folders, membership of several folders
- Folders of a media item editable from its details panel

### Activity Log

- Who changed what, and when: sign-ins and failed attempts, content, media, users, plugins, themes, options and the plugin's own settings
- Dedicated table, filterable by date, user and event
- Configurable retention (days and row ceiling), optional IP anonymization, roles to track
- CSV export

### SMTP

- Sending through an authenticated SMTP server or the Brevo HTTP API, instead of the host's `mail()`
- Presets for common providers (Brevo, Mailgun, SendGrid, Postmark, SES, Mailjet, OVHcloud, Hostinger, Gmail, Microsoft 365)
- Test email with the SMTP transcript, and a log of every email sent (purged on a schedule)
- The password is encrypted at rest and never part of the settings export. Credentials can instead be defined in `wp-config.php` (`LUMIA_SMTP_USER`, `LUMIA_SMTP_PASSWORD`, `LUMIA_BREVO_API_KEY`, and `LUMIA_ENCRYPTION_KEY` to pin the encryption key)

## Architecture

```
lumia-tools/
├── lumia-tools.php
├── includes/
│   ├── Core/
│   ├── Admin/
│   └── Modules/
├── templates/
│   ├── admin/
│   └── components/
├── languages/
└── assets/
    └── admin/
```

## Development

Working rules, conventions and the checks to run before a pull request are in [CLAUDE.md](CLAUDE.md); the technical documentation (core, design system, one page per module) is in [docs/](docs/README.md).

### Adding a module

1. Create a folder in `includes/Modules/MyModule/`
2. Extend `AbstractModule` (implements `ModuleInterface`)
3. Register the module through the `lumia_module_definitions` filter

```php
<?php
namespace Lumia\Tools\Modules\MyModule;

use Lumia\Tools\Core\AbstractModule;

class Module extends AbstractModule {
    public function init(): void {}
    public function get_settings(): array { return ['enabled' => true]; }
    public function save_settings( array $settings ): bool {
        return update_option( 'lumia_module_my_module', $settings );
    }
    public function get_admin_css(): array {
        return [ LUMIA_ASSETS_URL . 'admin/css/modules/my-module.css' ];
    }
}
```

### Extensible module registration

The core exposes an extensible registry so that `Core/Modules.php` does not have to be edited for every new module.

```php
add_filter( 'lumia_module_definitions', function( array $modules ) {
    $modules['my_module'] = [
        'name'        => __( 'My module', 'lumia-tools' ),
        'description' => __( 'Short description', 'lumia-tools' ),
        'menu_label'  => __( 'My module', 'lumia-tools' ),
        'menu_desc'   => __( 'Main action', 'lumia-tools' ),
        'class'       => 'Lumia\\Tools\\Modules\\MyModule\\Module',
        'icon'        => 'package',
    ];
    return $modules;
} );
```

### Module architecture recommendation (simple -> complex)

- Simple module:
  - `Module.php` (hooks + settings + view)
- Complex module:
  - `Module.php` (orchestration)
  - `Services/` (business logic, API, cron, storage)
  - `Admin/` (UI, handlers, rendering)
  - `Domain/` (DTOs, rules, validation)

Each module must sanitize its own settings in `save_settings()` (the core no longer applies generic sanitizing).

Useful contract points:

- `get_uninstall_keys()` distinguishes `options`, `meta` (post meta) and `user_meta`. A
  user meta declared under `meta` is never deleted: they are two different tables.
- `get_admin_js_deps()` declares handles of already registered scripts (shared
  third-party libraries) rather than returning their URL from `get_admin_js()`.
- `to_form_payload()` converts stored settings into the shape `save_settings()`
  expects. The configuration import uses it, so that it replays the module's own
  sanitizer instead of writing the JSON as is.
- The defaults of `get_defaults()` are merged recursively: a sub-key added in a later
  version therefore reaches existing installs with its default value.

### Translations

Every string in the code is English (text domain `lumia-tools`); French comes from `languages/lumia-tools-fr_FR.po` / `.mo`. The procedure to add a string is in [CLAUDE.md](CLAUDE.md#translations-text-domain-lumia-tools) and the tooling in [tools/i18n/README.md](tools/i18n/README.md).

## Releases and versioning

- Stable: releases from the main branch
- Dev: automatic pre-releases from the dev branch
- The ZIP is attached to each release as `lumia-tools-<version>.zip`

## License

GPL-2.0+
