# Smtp module

`includes/Modules/Smtp/` + `assets/admin/js/modules/smtp.js`. Sending through an authenticated SMTP server or through the Brevo HTTP API, test email and email log (issues #20 and #65, replaces FluentSMTP). Six classes:

- `Mailer`: plugs PHPMailer into the configured server (`phpmailer_init`) or installs `BrevoMailer`, and enforces the sender (`wp_mail_from`, `wp_mail_from_name`).
- `BrevoMailer`: PHPMailer subclass that sends through the Brevo API (see [below](#brevo-api)).
- `Crypto`: encryption of the password at rest.
- `Logger`: captures every call to `wp_mail()` and writes it to the table.
- `Providers`: SMTP presets for common providers (Brevo, Mailgun US/EU, SendGrid, Postmark, SES, Mailjet, OVHcloud, Gmail, Microsoft 365).
- `Store`: the `{prefix}lumia_mail_log` table, modeled on the activity log (see [activity-log.md](activity-log.md#storage): `dbDelta`, `maybe_install()` on every load, recreation after a manual drop, purge in batches).

## Sending: `phpmailer_init`, no replaced `wp_mail()`

FluentSMTP redefines the whole pluggable `wp_mail()` function (routing by sender address, provider APIs). For a single SMTP relay, `phpmailer_init` is enough, and the core `wp_mail()` stays intact with its filters and its `wp_mail_succeeded` / `wp_mail_failed` hooks, which the log depends on. If another plugin redefines `wp_mail()`, `Mailer::wp_mail_override()` detects it (through `ReflectionFunction`) and the screen displays it.

- **Global PHPMailer instance**: it survives from one send to the next, and `wp_mail()` only resets the recipients, the body and the transport (`isMail()`). `configure()` therefore assigns **every** connection property on each call, unconditionally, `SMTPDebug = 0` included. Otherwise a value set by the previous send stays in place (the credentials, or the trace of a test email, which would end up in the next HTTP response).
- **20 s connection timeout**: PHPMailer's default is 300 s, and an unreachable host blocked the page that was sending (a contact form, for example).
- SMTP is plugged in only if the switch is on **and** a host is set.

## Providers

A preset **pre-fills** the host, the port, the encryption and, when one is required, the username (`apikey` for SendGrid). It also displays a hint about where to find the credentials. Everything stays editable, and sending goes through the same generic SMTP relay: the `provider` setting is for display only. Going back to "Custom server" empties no field.

## Brevo API

`transport` setting: `smtp` (default) or `brevo`. The `smtp_enabled` switch remains the general switch ("Custom sending"); the API is only plugged in with a saved key. Useful when the host blocks SMTP ports, and the API errors are more explicit than a relay's.

WP Mail SMTP architecture, not FluentSMTP's: `BrevoMailer` extends PHPMailer, and only `send()` is overridden. `wp_mail()` therefore always builds the message, and the log as well as the success and failure hooks stay unchanged. An API refusal throws a `PHPMailer\Exception`, which `wp_mail()` turns into `wp_mail_failed`.

- **Installation in `pre_wp_mail`** (maximum priority, the filter value is returned as is): this filter runs right before `wp_mail()` creates its instance, and `wp_mail()` keeps any existing instance. Only a core instance (`PHPMailer` or `WP_PHPMailer`) is replaced: the one of another sending plugin is left in place, and `configure_brevo()` does not touch it.
- **`$Mailer = 'brevo'` on every send** (`phpmailer_init`): `wp_mail()` sets the instance back to `isMail()` on every call. Without this marker, `send()` falls back on PHPMailer's normal sending, which covers a setting change in the middle of a request.
- **Never touch a `BrevoMailer` constant outside a send.** The class extends PHPMailer, which WordPress only loads on the first `wp_mail()`. Reading `BrevoMailer::MAILER` from `Mailer::TRANSPORTS` triggered the autoload on every page load, hence a fatal error on the whole site. The transport constant lives in `Mailer::BREVO`; `BrevoMailer` is only named after an `instanceof` (which, for its part, does not autoload).
- **`WP_PHPMailer`** (WordPress 6.8+) only translates PHPMailer's error messages in a shared static property. `BrevoMailer` therefore extends `PHPMailer` and calls `WP_PHPMailer::setLanguage()` if it exists, which keeps compatibility with WordPress 6.0.
- `preSend()` is kept: it validates the addresses and reads the attachments, with the translated error messages of an ordinary send (empty body included). The MIME it assembles is not used. **But it modifies the message**: as soon as an `AltBody` exists (set by a mail-template plugin in `phpmailer_init`), it switches `ContentType` to `multipart/alternative`. The HTML type is therefore read **before** `preSend()`. Otherwise the HTML went out as `textContent`, and the recipient saw the tags.
- **Mapping**: a single reply-to address (the first), custom headers go into `headers`, embedded images (`cid:`) go out as ordinary attachments for lack of an equivalent. Return-Path does not apply: Brevo manages the envelope itself.
- **API key**: same treatment as the password (see below): encrypted `lumia_smtp_brevo_key` option, excluded from the export, empty field = unchanged, `LUMIA_BREVO_API_KEY` constant takes precedence. Only letters, digits, `-` and `_` are kept (`xkeysib-…` format).
- **Test email**: on failure, the request, the HTTP code and the response body replace the SMTP transcript. The key only goes in the `api-key` header: it does not appear.
- Not done yet: Mailgun (raw MIME), Postmark, SendGrid, SES (v4 signature). Google and Microsoft (OAuth2) are ruled out.

## Sender

- **Not forced**, the configured address only replaces WordPress's default address (`wordpress@` + network host without `www.`, computed as in core), and the name only if it is "WordPress". A plugin that chooses its own sender therefore keeps control. **Forced**, everything is replaced. The filters run at priority 9999, to come after those of other plugins.
- **Return-Path**: it takes the **configured** address, not the message's `From`. When not forced, the `From` may be a visitor's (contact form): putting it in the envelope is what the first version did, and the relay refuses an envelope outside its domains ("Sender address rejected"), not to mention that SPF fails.

## Password

- **Separate option** (`lumia_smtp_password`, not autoloaded), never in `lumia_module_smtp`. The configuration export writes module options as is into the JSON (see [core.md](../core.md#settings-import)), and the activity log lists the fields they change. The import therefore carries no password: it would not decrypt on another site anyway.
- **Empty field = unchanged**: the form never displays the password again.
- **AES-256-GCM**, key derived from `LOGGED_IN_KEY` + `LOGGED_IN_SALT` (`LUMIA_ENCRYPTION_KEY` replaces it if defined), `v1:` + base64(IV‖tag‖text) format. FluentSMTP uses AES-256-CTR, without authentication, and detects a wrong key thanks to a salt concatenated to the plaintext. GCM achieves the same result properly. The goal: a leak of the database **alone** (dump, backup, Database tab) does not hand over the password. Against whoever reads `wp-config.php`, nothing protects.
- **Regenerated keys** (migration, salt rotation): `Crypto::decrypt()` returns `null`, and the screen asks for the password again instead of failing silently on send.
- **Without OpenSSL**, the password is not saved at all (it is never stored in clear text without the user knowing), and the screen reports it.
- `LUMIA_SMTP_USER` / `LUMIA_SMTP_PASSWORD` in `wp-config.php` take precedence over the database, and the fields become read-only.
- Sanitizing: no `sanitize_text_field()`, which strips `<…>` and `%xx`, two legitimate sequences in a password. Only control characters are removed.

## Log

Three hooks, because none sees everything:

- the `wp_mail` filter (maximum priority) captures the **request**, as the caller made it after the other filters (headers and attachment paths included): this is what a resend replays;
- `phpmailer_init` captures the **final message** (sender kept, content type, transport);
- `wp_mail_succeeded` / `wp_mail_failed` give the **outcome**. A failure can happen before `phpmailer_init` (invalid recipient or sender): the row then only holds the request.

A `pre_wp_mail` that short-circuits sending triggers neither outcome hook: nothing is logged, and nothing was sent.

- **Body limited to 512 KiB** (`mb_strcut`, which does not cut a UTF-8 character). Beyond that, the row is marked `truncated` and resending is refused: resending a cut-off message would be worse than resending nothing.
- The **list** reads neither the body nor the headers (`Store::LIST_COLUMNS`). The detail loads them when opened (`lumia_smtp_log_detail`).
- **HTML preview** in a `<iframe sandbox="" srcdoc>`: no script, no form, no access to the admin page. An email body can come from anyone.
- **Resend**: `wp_mail()` is replayed with the original request. Attachments that have disappeared since (temporary files of forms) are removed and reported. The new row carries `resent_of`.
- **Resend, sender and content type**: the `wp_mail()` arguments are not enough. WooCommerce and others set the sender and the HTML through filters (`wp_mail_from`, `wp_mail_content_type`) that are only active while they send. On resend, those filters no longer exist, and an order went out again from the default sender, as plain text with visible tags. The resend therefore reapplies the **logged** sender and type, through temporary filters at priority 9000, below the forced sender (9999), which keeps the last word.
- The full content is kept, **password reset links included**. Hence a default retention shorter than the activity log's (30 days, 5,000 emails), the "Clear log" button (`TRUNCATE`) and a warning on the screen. Turning logging off does not erase what already exists. The table is only dropped on uninstall.

## Test email

- It goes through `wp_mail()`, therefore through the **saved** settings, and it is logged like any other email.
- On failure, the SMTP transcript (`SMTPDebug = 2`, hook at priority 1000, after `configure()`) is returned. "Could not authenticate" does not say what is wrong, the server's response does.
- **Masking** (`Module::mask_transcript()`): at level 2, PHPMailer writes the client commands as they are, and `AUTH PLAIN <base64>` as well as the answers to `AUTH LOGIN` contain the username and password in base64, hence readable. Every client line is masked from `AUTH` on, as long as the server answers `334`.
- `wp_mail()` only returns `false`, without detail. The error is read in the `WP_Error` of `wp_mail_failed`, captured for the duration of the send (`send_capturing_error()`).

## Interface

Three sub-tabs of the `lumia-tabs` component (see [design-system.md](../design-system.md#tabs)): **Settings** (server, sender), **Test**, **Log** (list and retention). All panels stay inside the form: "Save" posts every field, whichever tab is open. The log only loads the first time its tab is opened (`lumia:tab` event).

Same structure as the activity log: the filters and the test field have **no `name` attribute**, and Enter is intercepted there, otherwise it submits the settings form. The test field is `type="text"` (`inputmode="email"`) and not `email`: the browser validates an email field even without a `name`, and an incomplete address blocked "Save". The "unsaved changes" warning of `admin.js` only counts named fields (see [design-system.md](../design-system.md#forms)). Two CSS pitfalls: `.lumia-input--sm` caps at 140 px (`.lumia-sm__wide` lifts it for the host and the addresses), and the `hidden` attribute loses against the `display:flex` of `.lumia-form__row` / `.lumia-option`, which `smtp.css` restores.

## Translatable strings

The JS strings (`sm*` keys) come from `Module::get_admin_js_data()` under `i18n` (see [core.md](../core.md#translatable-strings-in-js)); the JS has no literal fallback. "Cancel" in the clear-log modal reuses the generic `cancel` key. The labels of the transports and of some providers (`Brevo API`, `Mailgun (EU)`, `OVHcloud (business email / MX Plan)`) go through `__()` like the rest: a plain string in the code would stay untranslated.
