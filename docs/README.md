# Technical documentation

Internal plugin documentation, excluded from the release ZIPs. `CLAUDE.md` at the root holds the absolute rules and points here for the details.

- [core.md](core.md) — boot sequence, autoloader, settings storage, `AbstractModule` contract, JS strings and translations, forms and AJAX endpoints, icons, notices, updater, tooling (Composer, PHPCS, PHPStan).
- [design-system.md](design-system.md) — CSS classes, tokens, modals, forms, buttons, tooltips, toasts.
- [migration-from-skmt.md](migration-from-skmt.md) — per-site procedure to move a site from Studio Kyne Mini Tools to Lümia Tools: backup, install, activation, checks, deletion of the old plugin, recovery after a failed migration.

## Modules

| Module | Page | What you will find there |
|---|---|---|
| Security | [modules/security.md](modules/security.md) | IP resolution, rate limiter (including application passwords), login URL, anti-enumeration, `X-Powered-By` |
| WhiteLabel | [modules/white-label.md](modules/white-label.md) | admin bar, profile, local avatars, `MenuProfileManager` and its cache |
| ImageOptimizer | [modules/image-optimizer.md](modules/image-optimizer.md) | AVIF siblings served by `Accept` negotiation, delivery self-test, background queue, encoder pitfalls, bulk, legacy migration, uninstall, SVG sanitizer |
| MenuCreator | [modules/menu-creator.md](modules/menu-creator.md) | profile application, access blocking, export, history, icons |
| Login | [modules/login.md](modules/login.md) | `login_*` hooks |
| Files | [modules/files.md](modules/files.md) | `FileManager`, core CodeMirror, `DISALLOW_FILE_*` |
| Media | [modules/media.md](modules/media.md) | folder taxonomy, server-side filtering, capabilities, drag & drop |
| Database | [modules/database.md](modules/database.md) | identifier validation, `normalize_sql()`, SQL editor, export |
| ActivityLog | [modules/activity-log.md](modules/activity-log.md) | dedicated table, logging pitfalls (auto-draft, Gutenberg, Bricks), brute-force ceiling, purge, CSV export |
| Smtp | [modules/smtp.md](modules/smtp.md) | `phpmailer_init` rather than a replaced `wp_mail()`, global PHPMailer, sender and Return-Path, password encrypted and left out of the export, log, transcript masking |

Each module page documents the **pitfalls and decisions** (what broke, why the solution is the one it is), not the code itself: the code reads from `includes/Modules/<Module>/`.
