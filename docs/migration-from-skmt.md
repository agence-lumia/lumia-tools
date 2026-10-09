# Migrating a site from Studio Kyne Mini Tools (SKMT) to Lümia Tools

Per-site procedure. Lümia Tools is SKMT renamed: it is installed **next to** SKMT (a different folder, `lumia-tools/`), and **migrates the data by itself when it is activated**. Nothing is imported by hand. What the migration does, step by step, and why: [core.md](core.md#migration-from-skmt-coremigrationfromskmt).

Do the sites one at a time. Commands below use WP-CLI from the site's root; every step also exists in wp-admin.

## 1. Before

1. **Export the database.** This is the last-resort rollback, do not skip it.

   ```bash
   wp db export ~/backup-before-lumia-$(date +%F).sql
   ```

2. **Copy the original images folder**, if the Image Optimizer kept originals: `wp-content/uploads/skmt-originals-<token>/`. The migration renames this folder (it is not copied), so the copy is your only safety net for those files.

   ```bash
   cp -a wp-content/uploads/skmt-originals-* ~/
   ```

3. **Look for `SKMT_*` constants** in `wp-config.php` (`SKMT_ENCRYPTION_KEY`, `SKMT_SMTP_USER`, `SKMT_SMTP_PASSWORD`, `SKMT_BREVO_API_KEY`, `SKMT_DISABLE_LOGIN_URL`):

   ```bash
   grep -n "SKMT_" wp-config.php
   ```

   Leave them as they are. They keep working: Lümia reads `LUMIA_*` first and falls back to the `SKMT_*` one. Renaming them is optional and must wait until **after** the migration is checked (section 4). In particular `SKMT_ENCRYPTION_KEY` must keep the same value when it becomes `LUMIA_ENCRYPTION_KEY`, otherwise the stored SMTP password cannot be decrypted any more.

4. Note the current custom login URL (Security module) and whether a test email goes out (SMTP module), to compare in section 4.

## 2. Install Lümia Tools

Download `lumia-tools-<version>.zip` from the [release](https://github.com/agence-lumia/lumia-tools/releases) (the asset, not the "Source code" archive). Keep SKMT **active** for now.

- wp-admin: Plugins > Add New > Upload Plugin, then install (activation is the next section).
- WP-CLI: `wp plugin install ./lumia-tools-<version>.zip` (or the asset URL, `https://github.com/agence-lumia/lumia-tools/releases/download/v<version>/lumia-tools-<version>.zip`).

## 3. Activate (this runs the migration)

```bash
wp plugin activate lumia-tools
```

or Activate in wp-admin. Under WP-CLI, success is a log line, `Migration from Studio Kyne Mini Tools complete. You can delete the old plugin.`; a failure prints a **warning** naming the step (see "If it fails" below), even though the command may still report the plugin as activated.

What the migration does, in this order:

1. **Copies** the settings, the module options, the menu profiles (page slug rewritten to `lumia-tools`) and the optimizer state to `lumia_*`. The `skmt_*` originals stay in place.
2. **Re-encrypts** the SMTP password and the Brevo key with the new key (same material, new context).
3. **Moves** the image optimizer's scheduled bulk run to its new name.
4. **Renames in place** the activity-log and mail-log tables, the image meta (`_skmt_*` to `_lumia_*`), the user meta (notices, local avatars), the media-folder colors and the media-folder taxonomy.
5. **Renames** `uploads/skmt-originals-<token>` to `uploads/lumia-originals-<token>`.
6. **Deactivates SKMT** and sets a success notice for the first administrator who opens an admin page.

A site where SKMT was active but never configured (no `skmt_module_*` option): the migration copies what exists and invents nothing; the Lümia defaults apply to the rest (rate limiting is on by default).

The migration runs once (a marker, `lumia_migrated_from_skmt`, is set at the end). Deactivating and reactivating Lümia later does not replay it and does not overwrite settings changed since.

## 4. Check

```bash
wp plugin list --fields=name,status | grep -E "lumia|studio-kyne"   # lumia-tools active, studio-kyne-mini-tools inactive
wp option get lumia_migrated_from_skmt                              # a timestamp
```

Then, in wp-admin and in a private window:

1. **Plugin pages**: the LUMIA menu opens; the Modules page shows the same modules enabled as before; each enabled module's screen shows its settings. The green "Migration ... complete" notice appears once.
2. **Login**: the custom login URL still works, and `/wp-login.php` is still blocked (a visitor gets a 404).
3. **SMTP** (if used): SMTP module > Send a test email. The password does not have to be typed again, and the email arrives.
4. **Images** (if the Image Optimizer was used): open an optimized image in the Media library, run **Restore original** (available when the Keep original setting was on), check the original is back, then optimize it again.
5. **Media folders**: the folders and their colors are there, with their images.
6. **Activity log**: the old entries are listed, and a new one appears when you change a setting.

Only when all of this is right, optionally rename the `SKMT_*` constants of `wp-config.php` to `LUMIA_*` (same values), and check again that the SMTP test still works.

Do not reactivate SKMT afterwards: while it is loaded, Lümia keeps all its modules off.

## 5. Delete SKMT

Only after a successful migration.

- wp-admin: Plugins > Delete on Studio Kyne Mini Tools. This runs SKMT's `uninstall.php`.
- WP-CLI: `wp plugin uninstall studio-kyne-mini-tools` (add `--deactivate` if needed). This also runs `uninstall.php`.

SKMT's uninstall only finds the original `skmt_*` options (everything else was renamed at activation), so nothing of Lümia is touched. `wp plugin delete studio-kyne-mini-tools` removes the files **without** running `uninstall.php`: the site works the same, but SKMT's original `skmt_*` options stay in the database as unused rows (harmless; list them with `wp option list --search='skmt_*' --fields=option_name`).

## If it fails

The activation stops at the first failing step. SKMT stays active and Lümia stays **on hold**: it loads none of its modules, so nothing writes data the resumed migration must not find. SKMT's image optimizer is frozen meanwhile (its uploads stay unprocessed, its buttons answer with an error), otherwise it would re-encode images whose meta is already migrated.

1. **Read the notice.** Every wp-admin page shows an inline error naming the step (e.g. `post_meta`) and the database error. Under WP-CLI, the same appears as a warning at activation.
2. **Read the same information from the CLI:**

   ```bash
   wp option get lumia_migration_error           # the failed step
   wp option get lumia_migration_error_detail    # the database error ('' if the failure was not a query)
   ```

3. **Do not delete SKMT.** Its `uninstall.php` would erase the data that is not migrated yet.
4. **Fix the cause** (typically a database permission, a full disk, a table in a broken state, a lock held by another process), then **deactivate and reactivate Lümia Tools** to resume. Steps check their own state: a second run completes the first without duplicating anything, and values SKMT changed in the meantime win.

   ```bash
   wp plugin deactivate lumia-tools && wp plugin activate lumia-tools
   ```

5. **Last resort**: restore the export from section 1 (`wp db import ~/backup-before-lumia-<date>.sql`), put the `skmt-originals-*` folder back if it was renamed, deactivate Lümia Tools, and report the step and the error so it can be fixed before trying again.

If Lümia only shows the warning "Studio Kyne Mini Tools is still active", SKMT is still loaded and no migration ran (SKMT had no settings to migrate): deactivate SKMT, which is enough to release the modules of Lümia.
