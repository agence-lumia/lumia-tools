# ActivityLog module

`includes/Modules/ActivityLog/` + `assets/admin/js/modules/activity-log.js`. Who changed what, and when (issue #19). Four classes:

- `Events`: the catalogue, i.e. groups (`auth`, `content`, `media`, `users`, `plugins`, `themes`, `options`, `settings`, `files`, `mcp`) and event labels. The table only stores the event **key**; the label is translated at display time, so a log written in one language reads back in the new language after a language change.
- `Store`: the `{prefix}lumia_activity_log` table: schema (`dbDelta`), insertion, filtered reading, purge.
- `Recorder`: the WordPress hooks and `record()`, which applies exclusions, IP, author and deduplication.
- `Module`: settings, cron, AJAX list (`lumia_activity_log_list`), CSV export (`admin_post_lumia_activity_log_export`).

## Storage

- **Dedicated table**, no option or postmeta: a log keeps growing, is filtered by date and by user, and is purged in batches. Dates in **UTC** (`created_at`); the "from / to" filters arrive as site dates and go through `get_gmt_from_date()`.
- **Table name interpolated** into the SQL: `%i` only exists in `wpdb::prepare()` since WP 6.2, and the plugin supports 6.0. The name only comes from `$wpdb->prefix`.
- **Installed on every load** (`Store::maybe_install()`, one read of the autoloaded option `lumia_activity_log_schema`) and not only in `on_activate()`: a module enabled by a configuration import does not call `on_activate()`. Any change to the `CREATE TABLE` increments `Store::SCHEMA_VERSION`.
- **Table deleted by hand** (Database tab): the schema option still says "installed", so `maybe_install()` sees nothing and every `INSERT` failed silently, leaving the log mute until a reactivation. `Store::insert()` checks that the table exists **only when the write fails**, recreates it and retries once: a `SHOW TABLES` on every request would cost one SQL query per page for a very rare case.
- **Values truncated** to the column width before the `INSERT`: in strict SQL mode, a 300-character title made the whole insertion fail and the event was lost.
- Disabling the module **keeps the table** (the history must not disappear on a click); only uninstalling deletes it, through the `tables` and `cron` keys of `get_uninstall_keys()` (see [core.md](../core.md#module-system)).
- The user_login and the role are **copied** into the row: a deleted account stays readable and filterable, and it is often the one being looked for. The "user" filter therefore reads the table, not `wp_users`.

## Logging pitfalls

- **Auto-draft**: WordPress creates an automatic draft when the editor opens, then the first save goes through `wp_update_post()`. Creation is read from `transition_post_status` (`new`/`auto-draft` to anything else), and `post_updated` ignores everything that starts from an auto-draft. Without this, every creation appeared twice ("created" + "updated"), and every abandoned editor opening as "created".
- **Gutenberg saves twice** (post, then meta boxes): `post_updated` compares the fields and writes nothing if none changed.
- **Trash**: `wp_trash_post()` and `wp_untrash_post()` also go through `wp_update_post()`. `post_updated` therefore discards any transition from/to `trash`, which has its own events.
- **Page builders**: Bricks and Elementor store the content in postmeta and do not call `wp_update_post()`. A page redone in Bricks showed up nowhere; `added/updated_post_meta` watches `_bricks_page_content_2`, `_bricks_page_header_2`, `_bricks_page_footer_2` and `_elementor_data` (filter `lumia_activity_log_content_meta_keys`).
- **Per-request deduplication** (`Recorder::$seen`, key event + object): Bricks writes several metas in the same request. Two exceptions: `login_failed` (every attempt counts) and `option_updated` (the hook only fires when the value changes, so two rows are two real changes).
- **Role set before `user_register`**: `wp_insert_user()` calls `set_role()` before firing `user_register`. `set_user_role` therefore ignores an empty list of old roles, otherwise every creation also produced a "role changed".
- **Alt text**: Image Optimizer generates it on upload; it is ignored if a `media_added` for the same media item was already written in the request.
- **Explicit author** for `wp_login` (the current user is not set yet), `wp_logout` (it is already reset; the ID arrives as an argument since WP 5.5) and `after_password_reset` (the reset happens while logged out).
- **Plugin or theme deletion**: the file header no longer exists afterwards. The name is captured on `delete_plugin` / `delete_theme`, and read back on `deleted_*` if the deletion succeeded.
- **Lümia Tools settings** (`lumia_settings`, `lumia_module_*`): we list the changed **paths**, never the values, as a module may store a secret (SMTP, to come). Only the module on/off switches, which are booleans, are shown. Writes with no logged-in user (updater, cron) are ignored. The first save of a screen goes through `added_option`, not `updated_option`.
- **Files danger mode** (group `files`): `files_unlocked` (`until`, site time), `files_locked` (`reason` = `manual` / `expired`, `enabled_by`), `file_written` (path, `operation` = `save` / `upload`), fed by the `lumia_files_danger_mode_*` actions of `Files\DangerMode`. An expiry is written **without author**, even when a logged-in page load noticed it: `record()` treats an explicit non-existent user (`new \WP_User( 0 )`) as "nobody" instead of falling back to the current user. These three events skip the **role** exclusion (as failed logins do): they are a security audit trail, and excluding administrators would hide every unlock. The group exclusion still applies.
- **MCP Connect** (group `mcp`, "Claude (MCP)"): `mcp_connection_authorized`, `mcp_connection_denied`, `mcp_connection_revoked`, `mcp_refresh_reuse_detected`, `mcp_client_registered`, `mcp_ability_executed` (a `lumia/*` ability that changed something: object type `mcp_ability`, label the ability name, details its short arguments, authored by the acting user), fed by the `lumia_mcp_connect_*` actions documented in `McpConnect\Connections` (see [mcp-connect.md](mcp-connect.md#activity-log)). Object type `mcp_connection`, object id the connection id (0 for a denial or a registration, which have no connection), label the connection label or else the client name; details `client`, `acting_user` (login) and `reason`, never a token. Most of them fire on the OAuth endpoints, where nobody is logged in, so the author is explicit: **authorized** is written at the code exchange (the connection only becomes real then, a code never exchanged leaves nothing) and authored by the **approver**; a revocation by hand (`manual`, `all`) or on user deletion (`user_deleted`) by the current user; the automatic ones (`reuse`, `token` = revoked by the client through RFC 7009, `expired`) and `mcp_refresh_reuse_detected` / `mcp_client_registered` by nobody (`new \WP_User( 0 )`, "System"). **Every** MCP event skips the role exclusion, like failed logins: an external agent gaining or losing access to the site is a security trail, and a site that untracks administrators to cut noise would otherwise lose every approval and manual revocation (an administrator is the usual approver). The group exclusion still applies. The revocation reasons share keys (`manual`, `expired`) with the Files danger mode but not their text: `detail_lines()` takes the event group to pick the right labels.
- **Channel** (`details.via`): web, AJAX, REST, cron, WP-CLI, XML-RPC, and `mcp`. An automatic update appears without an author, as "Scheduled task": that is what it is. `mcp` is not detected by `Recorder::request_channel()`: after a successful Bearer authentication, `McpConnect\BearerAuth` adds a `lumia_activity_log_record` filter for the rest of the request that sets `via = mcp`, `connection` (the label, else the client name) and `connection_id` on every row. The detail modal folds the label into the source line, "Claude (MCP) (Clement, MacBook)", and does not repeat it as its own line. Content written through MCP is thus authored by the `claude-mcp` account (or the approver for an "act as me" connection) and says which connection made it.

## IP and brute force

- IP resolved by `ClientIp` with the source declared in the Security module (`lumia_module_security` → `authentication.ip_source`), **read even if Security is inactive**: that is where the proxy configuration lives, and trusting the headers without it would let anyone write the IP of their choice into the log. Empty under WP-CLI, which sets a dummy `REMOTE_ADDR` of `127.0.0.1` itself.
- Optional anonymization through `wp_privacy_anonymize_ip()` (the one used by the core privacy tools), applied at write time.
- **Failed-login cap**: 10 per IP and per hour (transient `_lumia_al_fail_{md5(ip)}`), the tenth row carrying `capped`. Without it, an attack of ten thousand tries filled the row cap in one night and the volume purge erased all the useful history.
- The role exclusion does not apply to failed logins: excluding administrators must not hide the attacks against them.

## Settings

The form posts the **tracked** groups and roles (checked boxes); the storage keeps the **exclusions** (`excluded_groups`, `excluded_roles`). A group added by a later version is thus tracked by default instead of arriving excluded. `to_form_payload()` does the reverse conversion for the configuration import.

## Purge

Daily cron `lumia_activity_log_purge`, scheduled in `init()` if missing (same reason as the table), unscheduled by `on_deactivate()` and by the deactivation of the plugin. By age (`retention_days`, 90 by default) then by volume (`max_rows`, 10,000). Deletion in batches of 5,000 (`DELETE … LIMIT`) so the table is not locked; the volume threshold is read first (`ORDER BY id DESC LIMIT 1 OFFSET n`) because MySQL rejects `LIMIT` in a subquery on the table being modified.

## CSV export

Manual, from the list, with the filters applied: nothing is archived automatically on the server (decision of issue #19: no IPs piling up on disk).

- **Keyset** pagination (`before_id`) and not `OFFSET`: rows arriving during the export would shift the pages.
- **Formula injection**: any cell starting with `=`, `+`, `-`, `@`, tab or carriage return is prefixed with an apostrophe. Titles, attempted login names and emails are typed by anyone, and Excel runs `=…` when the file is opened.
- UTF-8 BOM (otherwise Excel reads Windows-1252); `;` separator; empty escape character passed explicitly to `fputcsv()`: the default `\` is deprecated since PHP 8.4 and the warning ended up in the file.
- Download through a temporary POST form rather than `fetch()`, which would force keeping the whole file in memory in a Blob.
- File name: translatable string `activity-log-%s.csv` (`%s` = `Y-m-d-His`); fr_FR keeps the original `journal-activite-%s.csv`. It goes through `Admin::content_disposition()`, which sanitizes it.

## Interface

The list and the settings share the module form: the filters have **no `name` attribute** (they are not sent with the save), and Enter is intercepted in **all** filter fields (search and dates), otherwise it submitted the settings: page reloaded, filters lost, and a false "Settings changed" entry in the log. Only the last list request is displayed (`state.request` counter): fast typing fires several, which can answer out of order. The detail of an event is formatted server-side (`Module::detail_lines()`, label/value pairs) and displayed as is in a named modal.

## Translations

All strings are English in the code (text domain `lumia-tools`); the French texts live in `languages/lumia-tools-fr_FR.po`.

- Event and group labels, detail labels (`detail_lines()`), channels and the tracked WordPress option labels are translated **at display time** (or, for `option_updated`, at write time: the `label` detail stores the label as translated then).
- The `label: value` joiner is itself a string (`%1$s: %2$s`, `Module::label_value()`): French puts a space before the colon, so the separator cannot be hard-coded.
- JS strings come from `Module::get_admin_js_data()['i18n']` (keys `alLoading`, `alEmpty`, `alError`, `alTotal`, `alPage`, `alDate`, `alUser`, `alRole`, `alIp`, `alEvent`, `alObject`); the JS keeps no literal fallback.
- "Content" is both a group and a post field: the field uses `_x( 'Content', 'post content field' )` so the two French texts ("Contenus" / "Contenu") do not collide in the `.po`.
