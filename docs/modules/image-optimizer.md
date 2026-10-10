# ImageOptimizer module

The JPEG or PNG WordPress produces stays **the** image file, at a URL that never changes. For each of its files (main file and every size), the module writes an AVIF version next to it, `photo.jpg.avif`, in the background. The web server answers `photo.jpg` with the AVIF only to a client whose `Accept` header names `image/avif`, always with `Vary: Accept`; every other client (mail clients, social and shopping bots, `?original`) gets the JPEG/PNG. Whatever goes wrong on a host, the worst case is "heavier images", never "broken images": nothing is generated until a self-test proves the delivery, and a failing test deletes what was generated.

`includes/Modules/ImageOptimizer/`:

| Class | Role |
|---|---|
| `Module` | Orchestrator: settings, hooks, lifecycle, uninstall. |
| `ImageProcessor` | Server capabilities (real trial encodings), animation check, alt text from the file name. |
| `AvifEncoder`, `EncodeResult`, `JpegMetadata` | Encoding of one sibling; lossless EXIF/XMP removal from a JPEG. |
| `AvifState` | Per-media state metas. |
| `FileLifecycle` | Queueing on metadata changes, sibling deletion, name registry, uploaded AVIF/WebP conversion, fingerprint reconciliation, purge. |
| `QueueRunner` | Background drain: triggers, lock, time budget. |
| `DeliveryProbe`, `HtaccessWriter`, `delivery-status.php` | Delivery self-test, `uploads/.htaccess` block, Delivery tab. |
| `BulkProcessor` | Bulk tab: puts the library in the queue, shows its progress. |
| `MediaLibrary` | AVIF column, attachment panel, "serve the original format" switch. |
| `MigrationCommand`, `UrlRewriter` | `wp lumia images migrate`: media converted by the former pipeline. |
| `SvgHandler` | Secure SVG upload. |

The former pipeline (the file **replaced** by its AVIF/WebP conversion, URLs rewritten in the database, "restore / re-optimize / convert" actions, `format_mode`, `keep_original`) is gone. What remains of it is read-only: the backup folder constants (`BACKUP_DIR`, `LEGACY_BACKUP_DIR`, used by `Core\Migration\FromSkmt`), `get_backup_path()` (a source for the migration), and the deletion of a not-yet-migrated item's kept original and fallback files when it is permanently deleted.

## Settings

`lumia_module_image_optimizer`, schema version 2 (`settings_version`):

| Key | Default | Note |
|---|---|---|
| `optimize_on_upload` | true | Queue every new JPEG/PNG. |
| `quality` | 70 | AVIF quality (Imagick). The version 1 value was never applied (see the encoder pitfalls): it is replaced by 70 **once**, by `migrate_settings()`, also for a version 1 settings file imported later (`to_form_payload()`). |
| `speed` | `balanced` | `balanced` = `heic:speed` 8, `fast` = 9. |
| `max_dimension` | 2560 | Applied through `big_image_size_threshold`; 0 disables WordPress's threshold. Replaces `max_width` / `max_height` (migrated as the larger of the two). The plugin no longer resizes anything itself. |
| `exclude_suffixes` | `["-noopt"]` | Name suffixes (before the extension, case-insensitive, WordPress's `-N` uniqueness suffix allowed after them) whose images keep their original format. |
| `convert_modern_uploads` | true | Uploaded still AVIF/WebP converted to PNG/JPEG (see the lifecycle). |
| `strip_exif`, `generate_alt`, `svg_upload`, `svg_roles` | | `strip_exif` applies to the AVIF and, losslessly, to the served JPEG files. |

Saving the settings runs the delivery self-test again (active module only: the settings import also calls `save_settings()`).

WordPress 7.1 processes the media in the browser by default (HTTPS, block editor): the browser makes the sizes, the REST `create` forces `big_image_size_threshold` to `false`, and `finalize` triggers the generation. The module turns that off (`wp_client_side_media_processing_enabled` → `false`), so that the fallbacks stay WordPress's (q82, threshold applied). `image_save_progressive` is forced to `true` for `image/jpeg` only (an interlaced PNG is heavier).

**A main file at most 2560 px is kept raw.** Below the threshold, WordPress neither recompresses the uploaded main file nor makes it progressive: the fallback served for it is the uploaded file as is, with its quality and its EXIF (GPS included, removed by `strip_exif` in the queue). Only the sizes and a `-scaled` main file are WordPress's q82 progressive JPEG.

## Delivery

### nginx (Dokploy template)

The rule lives in the template (`wp-dokploy-template`, see its `CLAUDE.md`), not in the plugin:

```nginx
map $args $lumia_force_original { default 0; "~(^|&)original(=|&|$)" 1; }
map "$lumia_force_original:$http_accept" $lumia_avif_suffix { default ""; "~^0:.*image/avif" ".avif"; }

location ~* ^/wp-content/uploads/.+\.(?:jpe?g|png)$ {
    add_header Vary "Accept" always;
    add_header Cache-Control "public, max-age=31536000" always;
    try_files $uri$lumia_avif_suffix $uri =404;
    access_log off;
}
```

- **Strict match**: only an explicit `image/avif` gets the AVIF. `*/*` and `image/*` (Outlook, Brevo, Mailchimp, GoogleImageProxy, Googlebot-Image, Storebot-Google, Merchant/Meta feeds, LinkedInBot, facebookexternalhit, curl) always get the JPEG/PNG. Do not relax the pattern to `image/*`.
- **`Vary: Accept` on every JPEG/PNG answer** of the uploads, sibling present or not: the answer at that URL changes the day the AVIF appears, and a shared cache must keep the two variants apart.
- **No `immutable`** on these files (the template's generic image location has it): with `immutable`, a reload never revalidates, so a browser that cached the JPEG would never see the AVIF, nor the JPEG again after the AVIF is deleted. `max-age` stays at one year (Lighthouse cache audit); a reload revalidates through the ETag.
- **One-year cache after "serve the original format"**: a visitor who already has the AVIF in their browser cache keeps it until it expires, up to a year. The switch says so. Same after a purge (delivery test failed, deactivation): only the server side changes at once.
- `?original` (or `&original=1`) forces the JPEG/PNG: "Save image as", third-party tools, the "Copy original URL" button. A `.avif` requested directly (legacy media) is served by the generic location.
- Another nginx host without the rule: the self-test answers `none`, nothing is generated, and the Delivery tab shows the rule to hand over to the host.

### `.htaccess` block (Apache, LiteSpeed)

```apache
# BEGIN Lumia Tools AVIF
<IfModule mod_mime.c>
AddType image/avif .avif
</IfModule>
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteOptions Inherit
RewriteCond %{QUERY_STRING} !(^|&)original(=|&|$)
RewriteCond %{HTTP_ACCEPT} image/avif
RewriteCond %{REQUEST_FILENAME}.avif -f
RewriteRule ^(.+\.(?:jpe?g|png))$ $1.avif [NC,T=image/avif,L]
</IfModule>
<IfModule mod_headers.c>
<FilesMatch "\.(?i:jpe?g|png)(\.avif)?$">
Header merge Vary Accept
</FilesMatch>
</IfModule>
# END Lumia Tools AVIF
```

- In `wp-content/uploads/.htaccess` only, appended after the other lines (kept), written through a temporary file and `rename()`; the file is deleted when nothing else is left in it. Written only on Apache / LiteSpeed (`SERVER_SOFTWARE`, or mod_php's `apache_get_modules()`). Removed on module and plugin deactivation and on uninstall (`DeliveryProbe::reset()`).
- **`RewriteOptions Inherit`**: an `.htaccess` that turns the rewrite engine on stops WordPress's root rules from applying below it. Without it, a missing file of the uploads gets Apache's 404 (`charset=iso-8859-1`) instead of WordPress's.
- **`FilesMatch` with `(\.avif)?`**: the rewritten answer is the `.avif` file, which needs its `Vary` too (checked on Apache 2.4: present on both answers, no `env=` condition needed). `merge` rather than `append`: no duplicate `Accept`.
- **`<IfModule>` does not prevent every 500.** Without `mod_headers` the block is harmless but sends no `Vary` (test fails, block removed). A host whose `AllowOverride` lacks `FileInfo` answers **500 for every file of the uploads** as soon as the block exists (`RewriteEngine not allowed here`): the test sees the 500 in the same request and removes the block at once.

### Self-test (`DeliveryProbe`)

No AVIF is generated until a test proves the delivery (`DeliveryProbe::is_serving()`, read by the queue before every image). The result lives in the option `lumia_module_image_optimizer_delivery` (`mode` nginx | htaccess | none, `server_mode`, `cdn`, `reason`, `detail`, `checked_at`, `failures`, `server`, `browser`). It runs on module activation, on every settings save, from the Delivery tab ("Retest", AJAX `lumia_image_optimizer_delivery_retest`) and daily (`lumia_image_optimizer_delivery_check`). Going from `none` to served fires `lumia_image_optimizer_enqueued` with ID 0: the library is queued again. Bench: `tools/e2e-images/assert-delivery.sh`.

- **The probe does not depend on the encoder.** `uploads/lumia-tools/probe.png`, its sibling `probe.png.avif` and `probe-plain.png` (no sibling) are embedded in the class (base64; the AVIF was encoded once by the production image). The test requests them through the site's public URL (loopback), each request twice in alternating order (AVIF Accept first, then plain first) on one URL per run (`?lumia_probe=<token>`): a cache that ignores `Vary` hands the first variant it stored to the next client, whichever came first. Expected: AVIF for `image/avif`, PNG for `image/png,image/*` and for `?original`, `Vary: Accept` on all three, 200 PNG for the file without a sibling.
- **`Vary` is required on the PNG answer too**, not only on the AVIF one: see LiteSpeed below.
- **The block is never left without a successful test.** It is written before the test, and removed when the test fails. WP-CLI and a system cron have no `SERVER_SOFTWARE`: the last web server seen is kept in the result (and survives a deactivation) for them.
- **Failures.** Wrong type, missing `Vary` or HTTP 500: `none` at once. The generated siblings are **also deleted** whenever they may still reach a client that cannot display them: an AVIF served where the PNG was expected (`leak`); `Vary` missing with no block of ours in place (a host rule we cannot remove keeps serving them without `Vary`, and any shared cache can hand them to Outlook); any incorrect delivery behind a CDN, recognized (Cloudflare, Fastly, Sucuri, Hostinger CDN, by their headers: `cdn_vary`) or not (`Via`, `X-Cache`: `cdn_unknown`). The CDN cache itself has to be purged by the user: the screen says so. Unreachable (network error, timeout, 4xx, 502/503, an HTML answer): the previous verdict is kept up to 3 consecutive failures, except right after the block was written (removed at once).
- **A recognized CDN must prove itself.** Passing the type and `Vary` checks is not enough: the probe URLs carry a query string, and a zone that does not cache those would pass while the plain image URLs visitors fetch are cached with `Vary` ignored (bench `cdn-noquery`). The cache status of every request (`cf-cache-status`, `X-Cache`, Sucuri's, Hostinger's) goes into `detail`; `ok` needs at least one repeated variant (second pass) answered as a cache `HIT`, with the right type. Otherwise `none` (`cdn_unproven`), siblings deleted.
- **Browser check.** The loopback may go around a CDN (internal resolution). The Delivery tab fetches the probe from the administrator's browser (`Accept: image/avif,*/*`, then `*/*`, `cache: 'default'` so that an intermediate cache answers) when the server-side test passed, and posts the types to `lumia_image_optimizer_delivery_browser`. It is only a veto: a wrong answer forces `none` and deletes the siblings until a browser check passes again; the server-side runs keep the veto.

### LiteSpeed and OpenLiteSpeed

OpenLiteSpeed reports `LiteSpeed`, like LiteSpeed Enterprise (the engine of shared hosts such as Hostinger), but reads `.htaccess` through its rewrite parser only. Measured on the bench (OpenLiteSpeed 1.9.3, details in `tools/e2e-images/README.md`):

- it never sends a header from `.htaccess` (`Header ...` is an "invalid rewrite directive"): no `Vary`, so the test fails and the block is removed (JPEG only, the safe outcome);
- it caches the rewrite target **per URL and per worker, regardless of `Accept`**: once a Chrome request was rewritten, an Outlook-type client got the AVIF at the same URL. Hence the `Vary` requirement on the PNG answer and the alternating order;
- it **keeps applying a removed `.htaccess`** in the workers that had loaded it (measured: 15/16 `*/*` requests got the AVIF minutes after the removal). Only a missing sibling stops it: when a test fails with the block in place, the probe's sibling is deleted, and on LiteSpeed every generated sibling too (`FileLifecycle::purge_all()`).

LiteSpeed Enterprise has its own `.htaccess` engine (Apache `Header` support, change detection): what happens there can only be established by the self-test on a real host.

## AVIF siblings: state and file lifecycle (`AvifState`, `FileLifecycle`)

`AvifState` keeps the per-media state in scalar metas, so that the queue can select and order with SQL:

| Meta | Value |
|---|---|
| `_lumia_avif_status` | `pending`, `processing`, `done` (every source file has its AVIF), `partial` (some files skipped), `skipped` (none kept, or a format that cannot be converted), `failed`, `excluded` |
| `_lumia_avif_queued_at` | queueing time (processing order) |
| `_lumia_avif_origin` | `upload`, `bulk`, `manual`, `reconcile` |
| `_lumia_avif_gen` | generation counter, incremented on every queueing |
| `_lumia_avif` | detail: per source file `{bytes, mtime, avif_bytes}` (the fingerprint at encoding time), `error`, `attempts`, `updated`, `worker` |
| `_lumia_avif_legacy` | legacy files a migrated item keeps on disk |

An AVIF is only valid while the fingerprint matches its source. A sibling is kept only when it weighs at most 90% of its source, size by size.

- **`wp_update_attachment_metadata` runs several times during one upload.** `wp_create_image_subsizes()` saves the metadata once **before** `intermediate_image_sizes_advanced`, then once per size. Queueing on each call would encode a half-made layout. The "generating" flag is therefore raised on `big_image_size_threshold`, which runs first, and lowered at the end of `wp_generate_attachment_metadata` (late priority): an upload gives a single queueing. The flag is per attachment; a path that raises it without calling `wp_generate_attachment_metadata()` must call `on_generate_metadata()` itself. The queue never rewrites the attachment metadata (only `_lumia_avif*`): no loop.
- **Diff, not wipe.** Each queueing compares the recorded file list with the new one (union of old and new): siblings of vanished files go, only new files and files whose fingerprint changed are re-encoded. This covers the upload, a thumbnail regeneration (WP-CLI or a plugin), a crop in the WordPress editor and a file replacement plugin. `wp media regenerate` deletes with `unlink()` (no `wp_delete_file` filter): the fingerprints catch it. A non-scaled main file is not rewritten by a regeneration, so its AVIF stays valid.
- **Reconciliation.** On module activation and on every bulk scan, the fingerprints are checked against the files (`reconcile()`, event `lumia_image_optimizer_reconcile` with a cursor option): a source changed by FTP, the Files module or another tool while nobody watched is queued again.
- **Deletion.** The `wp_delete_file` filter takes the sibling of every JPEG/PNG WordPress deletes; `delete_attachment` deletes the siblings of the item (even of sources already gone) and its legacy files. The Files module moves, renames and deletes the sibling of a JPEG/PNG of the uploads with it (`FileManager`).
- **Names.** `_wp_check_existing_file_names()` only matches sub-size patterns (`name-WxH.ext`, `-scaled`, `-rotated`), never the exact name. A taken name (an `.avif` of the family on disk, or an entry of the registry of deleted names, option `lumia_module_image_optimizer_tombstones`, kept one year, 20 000 entries at most) is therefore declared through `pre_wp_unique_filename_file_list` as a virtual `base-scaled.ext`: WordPress then applies its own numeric suffix (`photo-1.jpg`). A re-upload under the name of an image deleted less than a year ago would otherwise hit the year-long browser cache of the former AVIF.
- **Exclusion.** The media switch and the name suffixes give `excluded`: siblings deleted, never queued (bulk included).
- **Uploaded AVIF / WebP** (`convert_modern_uploads`, filter `wp_handle_upload`, before WordPress makes its sizes): a still image is converted to PNG (alpha used or at most 256 colors) or progressive JPEG q90, ICC kept, EXIF removed per `strip_exif`, then follows the normal path. Only where Imagick encodes in this process (`can_encode_here`); otherwise, and for animated files, the file stays as uploaded (`skipped`, the media library says "Incompatible with some email clients"). Imagick reports a progressive JPEG as interlace `6` on read; the bench checks the SOF2 marker instead.

## Background queue (`QueueRunner`)

An upload only queues the media item; the AVIF is encoded after the response, one worker per site. `_lumia_avif` records the worker (`worker`: `<SAPI>:<PID>`). Bench: `tools/e2e-images/assert-queue.php`, `upload-timing.sh`.

- **Triggers.** `lumia_image_optimizer_enqueued` raises a flag; at `shutdown` (priority `PHP_INT_MAX`), PHP-FPM calls `session_write_close()`, `ignore_user_abort( true )` and `fastcgi_finish_request()`, then drains in the same process. Elsewhere (Apache mod_php, the CLI) a loopback POST to `admin-ajax.php?action=lumia_image_optimizer_drain` carries an HMAC token (`hash_hmac( sha256, floor( time() / 300 ), wp_salt( 'nonce' ) . 'lumia-avif' )`, current and previous window accepted): the `nopriv` exception to the nonce rule ([core.md](../core.md#ajax-endpoints)). A recurring event (`lumia_image_optimizer_drain`, schedule `lumia_five_minutes`) catches the rest, and the Bulk tab restarts a stalled queue. With ID 0 (delivery back after a purge), every eligible item is queued again (`enqueue_all_eligible( 'reconcile' )`: JPEG/PNG without state or `failed`, never `excluded`).
- **Never in the CLI.** The official `cli` image (the template's `cron` container) has an Imagick without any codec, and GD loses the ICC profile: under the CLI (`wp`, system cron), `drain()` only triggers the loopback, so the encoding always happens in PHP-FPM. A web runtime whose `can_encode_here` is false does nothing (a loopback would land in the same runtime, endlessly).
- **The "non-blocking" loopback blocks up to 1 s.** Requests raises any timeout to 1 s (cURL's resolver uses `alarm()`), so `blocking => false, timeout => 0.01` waits for the response up to that long, in the request that triggered it. The drain endpoint therefore answers first (complete JSON, `Content-Length`, `Connection: close`, flushed; `fastcgi_finish_request()` under FPM), then drains with `ignore_user_abort( true )`. Measured on the bench before the fix: the trigger returned after the whole drain (0.6 s for a 4000 px photo).
- **Lock.** MySQL `GET_LOCK( 'lumia_avif_' . md5( DB_NAME . prefix . home_url() ), 0 )`: global to the MySQL server and limited to 64 characters, hence the hash (shared hosting); released by the server if the process dies. Its ownership is checked before every image (`IS_USED_LOCK() = CONNECTION_ID()`: a `$wpdb` reconnection loses it). The lock holder puts every `processing` item back to `pending` first: it is the trace of a process that died (its attempt still counts). `processing` and `attempts + 1` are written **before** the encoding; the third attempt, a failure or a missing source file gives `failed` with a message, and the item no longer blocks the next ones.
- **Long-running worker, stale caches.** A drain lives up to 20 s (wall time, at most `max_execution_time - 5`; `set_time_limit( 120 )` before every image, Linux counts the CPU of the aom threads). Within one request the option and meta caches never see another request's writes: module state and delivery mode are read from the database before every image and right before each rename, the item's meta cache is dropped before it is processed, status and generation are read with SQL.
- **Generation.** `can_commit` (called by the encoder right before its `rename()`) compares `_lumia_avif_gen` with the value read at the start; the final status is written only if the generation is the same and the item is still `processing`. Otherwise the result is dropped: queued again, the item goes back to `pending` and is processed again; excluded or purged meanwhile, the siblings just written are deleted.
- **Bulk pace.** For items of origin `bulk`, the worker sleeps as long as the image took (about half the CPU left to the pages: the production container is limited to 2 CPU and aom uses about 2).
- **EXIF.** With `strip_exif`, the APP1 segments of the served JPEG files (never `original_image`) are removed losslessly (`JpegMetadata::strip_app1()`) before the fingerprint is taken. The attachment metadata (`filesize`) is not rewritten: saving it would queue the item again.

## AVIF encoder (`AvifEncoder`, `EncodeResult`, `JpegMetadata`)

`AvifEncoder::encode( $path, $can_commit )` writes `<file>.<ext>.avif` next to a WordPress-made JPEG/PNG and never throws: the outcome is an `EncodeResult` (`done` / `skipped` / `failed`, source and AVIF sizes, message). The MIME is decided by `wp_get_image_mime()` on the file itself (a HEIC upload converted to JPEG by WordPress 7.1 keeps `image/heic` on its attachment). The write goes to a hidden `.<name>.avif.tmp-XXXX` in the same folder and is `rename()`d, so the final name never designates a partial file; `$can_commit()` is called right before the rename and a `false` abandons the file (`skipped`, `stale`). A `skipped` result removes any previous sibling. Bench: `tools/e2e-images/assert-encoder.php`.

Pitfalls, each measured on the bench:

- **Imagick ignores `setImageCompressionQuality()` for AVIF.** The former pipeline set only that one: every AVIF it made was q50 / speed 6 whatever the setting (identical bytes from q30 to q95; SSIMULACRA2 13 points below WordPress's JPEG q82). The encoder calls `setCompressionQuality()` **and** `setImageCompressionQuality()`. Measured at q70 / speed 8 / 4:4:4: better than the JPEG q82 at 0.71 times its weight.
- **`heic:speed=10` is refused** by libheif (exception): the presets stop at 9. An option the encoder refuses is dropped (chroma first, then speed) and named in `error` of a `done` result, never a failure.
- **`heic:chroma=444`**: on flat-colored visuals, 4:2:0 loses 7 points at the 10th percentile for 3% fewer bytes.
- **ICC profiles: never `stripImage()`**: it removes the ICC profile too, so Display P3 photos lost their colours (5 photos out of 14 on the client sites). EXIF, XMP and IPTC are removed profile by profile. A profile above 4096 bytes is **converted** with `profileImage( 'icc', <sRGB> )` (lcms) then dropped (an Apple profile of 60 KB was copied into every size: a 150 px thumbnail weighed 65.6 KB, 60 of them profile); `transformImageColorspace()` alone does not convert P3 to sRGB. The sRGB profile is `assets/icc/srgb.icc` (compact v2, CC0, see `assets/icc/README.md`). A small profile (Display P3 is 516 bytes) is kept as is. CMYK goes through the same conversion, or `transformImageColorspace()` without a profile; failure means `skipped`.
- **`setImageDepth( 8 )`** before encoding: a 16-bit PNG would otherwise give a 12-bit AVIF.
- **Orientation**: the pixels are rotated when the EXIF orientation is not 1 (an AVIF has no EXIF block to read), and the EXIF profile is then dropped even without `strip_exif`.
- **Truncated files**: Imagick reads a truncated JPEG without an exception (the missing part comes out grey), so a JPEG without an end-of-image marker (`JpegMetadata::is_complete()`) or a PNG without `IEND` is `failed`.
- **Files are read through a handle** (`readImageFile`), because ImageMagick parses a path as a file specification (`[0]`, `@list`, `fmt:`).
- **GD** (only when `avif_engine` is `gd`) keeps no profile and ignores the EXIF orientation: it encodes a picture without a profile or with an sRGB one (the colorants are compared, since compact sRGB profiles are named "uRGB"), and answers `skipped` ("GD cannot preserve the color profile") for the rest. Quality is the setting minus 5.
- **Presets**: `balanced` is speed 8, `fast` is speed 9. On the bench, 9 saves about 20% of the CPU time (user time, 3 runs) but almost nothing on the wall clock, since aom runs several threads: measure CPU, not seconds. Speed 9 costs +5% on photos but +37% on PNG visuals.

`JpegMetadata::strip_app1()` removes the EXIF and XMP APP1 segments of a served JPEG **without recompressing**: it walks the markers and copies every other byte (the ICC profile in APP2 included), then renames a temporary over the file. Nothing is removed when the EXIF orientation is not 1 (the browser turns the picture from that tag). WordPress keeps the GPS block in its sub-sizes and in an unscaled main file.

### Lossless PNG rewrite: measured, not adopted

Rewriting the PNGs WordPress produces with Imagick (`png:compression-level=9`, `png:compression-filter=5`, `png:compression-strategy=1`, `png:exclude-chunks=date,time,tEXt,zTXt,iTXt`; pixels verified identical, `compareImages( METRIC_ABSOLUTEERRORMETRIC )` = 0) gains **0.1%** over the main file and all the sizes of a 1200x800 transparent PNG and of a flat logo (210 128 bytes to 209 908, never rewriting a file that gets bigger). Individual files go from -12.7% (an 864-byte logo) to +15.7% (a 362-byte size). A PNG written by GD is already smaller (52 001 bytes against 52 881 after the rewrite). Far below the 5% threshold: no `optimize_png()`. The measurement used synthetic images; a PNG exported by a design tool with metadata could gain more, which would justify measuring again on a real upload before reopening this.

## Server capabilities (`ImageProcessor`)

`ImageProcessor::get_capabilities()` probes the encoders with **real encodings** (`queryFormats()` and `gd_info()` lie on some builds, and `imagewebp()` / `imageavif()` exist in PHP 8.1+ even without the underlying library) and stores the result in a 24 h transient, whose key is tied to the PHP and GD versions, the ImageMagick build (version string **and** a digest of the formats it lists) and `PHP_SAPI`. This is the only detection: `templates/admin/settings.php` reuses it.

Keys: `imagick`, `gd`, `editor`, `avif` / `webp` and their per-engine variants (`imagick_webp` decides whether an uploaded WebP can be decoded), `avif_engine` (`imagick`, `gd` or empty), `heic_speed` / `heic_chroma` (probed: speed 2 and 9 must give different bytes, `heic:chroma=444` must give an av1C box without subsampling) and `can_encode_here` (Imagick encodes AVIF **and** decodes JPEG, PNG and AVIF in this process). The settings screen's "AVIF: Supported" badge is `can_encode_here`; the "AVIF delivery" badge is the last self-test verdict.

**"The AVIF delegate is missing" is true for the CLI image only.** The official `cli` image (`wordpress:cli`, the template's `cron` container) has an Imagick that lists formats it can neither read nor write (0 usable formats): `can_encode_here` is false there, and GD would lose the ICC profile. The production FPM image (`wordpress:7-php8.5-fpm-alpine`, ImageMagick 7.1.2, libheif, aom) encodes AVIF with Imagick. A cache shared through the database or Redis must not hand the CLI the answer of PHP-FPM, hence the format digest and the SAPI in the transient key. `is_animated()` short-circuits by MIME type (`ANIMATABLE_MIMES`): a JPEG is never loaded into Imagick to count its frames.

## Bulk (`BulkProcessor`)

The bulk encodes nothing itself: it fills the AVIF queue (origin `bulk`) and the `QueueRunner` drains it, one worker per site, like for an upload. `BulkProcessor` owns the four AJAX endpoints of the Bulk tab (`lumia_image_optimizer_bulk_scan`, `bulk`, `bulk_stop`, `bulk_status`; nonce + `Module::get_required_capability()`):

- **Scan** reconciles the fingerprints first (`FileLifecycle::reconcile()` over the whole library, cursor reset, 20 s budget): a source changed behind WordPress is queued again. Then it counts the media items per status and the JPEG/PNG ones without state (`QueueRunner::count_without_state()`). It queues nothing by itself.
- **Start** calls `QueueRunner::enqueue_all_eligible( 'bulk' )`: every JPEG/PNG without state **and every `failed` one** (`AvifState::enqueue()` resets `attempts`, which is why a manual relaunch gets three new tries), never an `excluded` one, never a GIF. Refused while `DeliveryProbe::is_serving()` is false (the tab says so and disables the button: nothing would be generated).
- **Stop** gives back the `pending` items whose origin is `bulk` (status, origin and queue date are deleted: "no state"; the generation counter stays so that it keeps growing). An item queued by an upload, and an item being processed, are left alone. The status is removed with `delete_post_meta( ..., 'pending' )`, i.e. only while it is still `pending`; an item a worker picks in the same instant is processed anyway.
- **Status** (polled every 3 s by the tab while items are waiting) returns the counts, `active` (pending + processing > 0), `running` (the queue lock is held) and, only when the queue is quiet, `untouched` (the library without state is a heavier query).

The progress is the **distribution of the statuses** (handled = done + partial + skipped + failed + excluded, over all media items with a state): no counter of its own that could drift. The only state kept is the option `lumia_module_image_optimizer_bulk_state` = `{ user_id, started_at }`, there to tell the user who started the run when it is over: `maybe_complete()` runs when a drain leaves the queue empty (action `lumia_image_optimizer_queue_empty`) and when the status endpoint sees no item waiting; it deletes the option **before** calling `notify_bulk_complete()`, so the persistent notice is sent once. A stop deletes the option without a notice. Reading that option bypasses the object cache (`wp_cache_delete`): a drain that started before the run did not see it. The former fixed-batch event `lumia_image_optimizer_cron` (possibly carried over from SKMT by `FromSkmt`) has no handler any more: a leftover one fires into the void, and deactivation and uninstall unschedule it.

**Restart from the screen.** The status endpoint calls `QueueRunner::trigger()` when items are waiting, the AVIF is served, no worker holds the lock and the transient `lumia_image_optimizer_bulk_kick` is absent. The transient lasts 60 s and is refreshed whenever a worker is seen running, set by Start and set by the restart itself: a worker seen less than a minute ago, or a restart that has just happened, is never doubled. The loopback trigger is harmless when a worker exists anyway (the lock lets only one in).

Bench: `tools/e2e-images/assert-bulk.php`.

## Media library (`MediaLibrary`)

- **AVIF column** (list mode, after the title): `AVIF -63%` (weight saved on the served sizes), `Pending`, `Processing`, `Failed` (tooltip with the error), `Original format`, `Not generated`, `Not applicable`.
- **Attachment panel** (`attachment_fields_to_edit`, server-rendered, replaced as is by the JS after each action): status, fallback / AVIF weights for the main file and in total, the switch **"Serve the original format"** (on: siblings deleted, `excluded`, generation moved so that an encode in flight is dropped; off: `pending`), **"Copy original URL"** (URL + `?original`), **"Download original"** (a `download` link to the same URL), **"Regenerate AVIF"** (`pending`, origin `manual`, siblings and fingerprints reset), and a reminder when the AVIF is not served on this server. An image excluded by its name suffix cannot be switched back from the panel.
- Endpoints `lumia_image_optimizer_media_toggle_original` and `lumia_image_optimizer_media_regenerate`: nonce, module capability, a JPEG/PNG attachment that is not an animated PNG.

Bench: `tools/e2e-images/assert-media.php`.

## Migration of legacy media (`MigrationCommand`, `UrlRewriter`)

`wp lumia images migrate [--dry-run] [--ids=<list>] [--limit=<n>]` converts the media items the former pipeline replaced with an AVIF / WebP (`_lumia_optimized_format` avif or webp, or `_lumia_optimized` with a `.avif` / `.webp` file in the metadata) to the sibling layout. Run site by site, `--dry-run` first; never automatic. Bench: `tools/e2e-images/assert-migration.sh` (stack `nginx-mig`, port 8102) and `crawl-check.sh`.

- **Only where Imagick encodes.** The command refuses when `can_encode_here` is false (the template's `cron` container): run it in the `wordpress` container with a WP-CLI phar (`docker exec -u www-data <project>-wordpress-1 php /tmp/wp-cli.phar lumia images migrate`). The module must be active (the command is registered by its `init()`), and the lifecycle hooks are suspended while it runs (`FileLifecycle::suspend()` / `resume()`; the name check and progressive JPEG stay).
- **Case 1, original on disk** (`original_image`, or the former backup copy `_lumia_backup_file` in `lumia-originals-<token>/`, copied next to the item under a free name): the main file (`-scaled` / `-rotated` as WordPress would) and every registered size are made again with `WP_Image_Editor` (Imagick), the metadata is written once. Not `wp_create_image_subsizes()`: it saves the metadata after each size, so a crash would leave half a layout. The AVIF are left to the queue (`pending`, origin `reconcile`). A legacy size with no new equivalent is redirected to the new file closest in width.
- **Case 2, no original**: each legacy file is decoded into a fallback with the same base name. PNG when the alpha channel is used, the image has at most 256 colors, or it is made of flat colours (lossless PNG at most twice the JPEG q90 on a 512 px copy); else progressive JPEG q90. The exact color count alone misses logos: the legacy AVIF is lossy, and a 29-color logo decodes to 1 801 colors. A legacy AVIF at most 90% of its fallback becomes the sibling `fallback.ext.avif` through a **hard link** (`link()`, `copy()` when refused), never a second lossy encoding; status `done` when every file got one, else `pending`. A WebP is never a sibling. Nothing becomes a sibling while delivery is not proven (`DeliveryProbe::is_serving()`). These AVIF stay at their former quality (q50, the originals are lost).
- **Names.** The whole family moves together: `wp_unique_filename()` on the base name (with the module's family check and registry), the item's own files excluded from the list. `collide.avif` next to another item's `collide.jpg` gives `collide-1.jpg`, `collide-1-300x225.jpg`...
- **The legacy files are never deleted by the migration**: newsletters already sent, caches, tables `UrlRewriter` does not cover (`termmeta`, `usermeta`, third-party tables) keep working. They are listed in `_lumia_avif_legacy` (with the former backup copy and the `_lumia_fallback_files`) and deleted with the item (`FileLifecycle::on_delete_attachment()`, unless the path became another item's main file). Neither a purge nor the uninstall touches them: the sibling is a hard link, deleting it leaves the legacy file.
- **URL rewriting** (`UrlRewriter`, only used here). The old/new path pairs, **relative to the uploads folder**, are applied to `posts` (content, excerpt), `postmeta` (Bricks, Rank Math, ACF) and `options`. Three rules: search for `/{path}` with a boundary after the extension (independent of the scheme, the domain and a CDN, and `other-photo.jpg` is not touched); also handle the escaped-slash form `\/...` (Gutenberg block attributes, JSON); **unserialize** serialized values before replacing, otherwise the `s:N:` lengths are wrong and the value is corrupted (whitelisted unserialization: `stdClass` only, no `__wakeup` of a third-party class). One LIKE query per table and per call, the stems gathered in one OR: the pairs of a batch of 20 items go in one call.
- **Journal and resume.** `_lumia_migration` is written before any file operation: step (`planned`, `files_written`, `db_written`), the planned names, the files written, the state before (attached file, MIME, guid, metadata; base64-encoded so that the URL rewrite cannot reach the guid it holds) and after. Order: files, then metas (attached file, MIME and guid through `$wpdb`, metadata, legacy list, read back), then the URL rewrite, then the AVIF state, the legacy metas and the journal. The legacy metas go last: their absence is what "migrated" means. A killed run resumes from the journal: `files_written` skips straight to the database, `planned` rewrites the files (case 1 first removes the family files written since the journal started). Before writing anything (first run or resume), every file the item is about to write that another attachment uses (its `_wp_attached_file` or metadata) is a collision: the item fails with the owner's ID ("orphan" when none) and nothing is touched; hours can pass between a killed run and its resume, and an upload may take a reserved name meanwhile. A failure removes only the files this item wrote in this attempt (the list is reset per item: a shared list once deleted the previous item's live files) and, in case 1, family files written since the journal started that no attachment uses. It restores the state before when the database step had started or the journal was resumed at `files_written`; if that restore fails, files and journal stay and the next run resumes. The journal and the metadata are written slashed (`update_post_meta()` unslashes: a backslash in `image_meta` would be lost). Exit code 1 on any failure.
- **Bricks CSS.** The pairs of migrated items accumulate in the option `lumia_module_image_optimizer_migration_pairs` until the end of the run rewrites `uploads/bricks/css/*.css` (exact replacement, temporary file + rename), then Cache Enabler and the object cache are flushed. A killed run leaves the option: the next run, even with nothing left to migrate, does the CSS pass.

## Deactivation and uninstall

- **Module deactivation** (`on_deactivate()`): the queue, reconcile and former bulk events are unscheduled, the bulk state goes, `DeliveryProbe::reset()` removes the `.htaccess` block, the daily check and the verdict (the last web server seen is kept), and `purge_all()` deletes **every generated sibling** and resets the state metas; a manual exclusion is kept. An nginx rule outside WordPress would otherwise keep serving AVIF files nobody keeps up to date. The bulk regenerates them after a reactivation; the self-test going back to "served" queues the library by itself.
- **Plugin deactivation** (`Core\Deactivator`) does not go through the module: it unschedules every hook of every module's `get_uninstall_keys()['cron']` with `wp_unschedule_hook()` (`wp_clear_scheduled_hook()` only removes events scheduled without arguments), removes the `.htaccess` block and calls `Module::purge_generated_siblings()`, which needs no booted module. The module stays on in `lumia_settings`.
- **Uninstall** (`uninstall.php` calls `Module::uninstall_files()` before deleting the keys, since the purge reads the state metas): `.htaccess` block, every generated sibling, `uploads/lumia-tools/`; then the options (settings, delivery, tombstones, bulk state, reconcile cursor, migration pairs, the former `_stats` and `_backup_token`), the metas (`_lumia_avif*`, `_lumia_avif_legacy`, `_lumia_migration`, the former `_lumia_optimized*`, `_lumia_*bytes*`, `_lumia_backup_file`, `_lumia_fallback_files`) and the events. **Left on disk**: the legacy files of migrated items and `lumia-originals-<token>/`, the client's images.
- Deleting the plugin folder without going through WordPress cannot be handled: the siblings and the `.htaccess` block stay. Deactivate first.

Bench: `tools/e2e-images/assert-uninstall.sh`, and `tools/e2e/run.sh assert-migration` for the backup folder carried over from SKMT.

## SVG (`SvgHandler`)

`SvgHandler` only registers its filters if the `svg_upload` setting is active. It restricts the upload by role (`svg_roles`, sanitized against the real WP roles in `Module::sanitize_roles()`), adds `image/svg+xml` to `upload_mimes`, fixes WordPress's MIME/extension detection (`wp_check_filetype_and_ext`), and sanitizes every SVG on upload (`wp_handle_upload_prefilter`) through a whitelist-based DOMDocument pass — it removes the tags outside the list, the event handlers, the dangerous `href`/`xlink:href` (only internal anchors and `data:image/*` are allowed), the attributes/styles carrying script, and rejects DOCTYPE+ENTITY (XXE). It never loads `LIBXML_NOENT`. A file that fails the sanitization is rejected with an error rather than stored.

`<style>` is on the whitelist, and its **text content** is sanitized by `clean_style_element()` → `sanitize_css()`. Only the *attributes* used to be: an `@import url("//evil.tld/x.css")` went through the sanitizer intact and triggered an outgoing request on every render of the SVG (tracking, context exfiltration through `url()`, arbitrary CSS if the SVG is inlined). Two ordering precautions, the same as for `normalize_sql()` in the Database module: **CSS comments go first**, and **hexadecimal escapes are decoded before any test** — `\40 import` *is* `@import` for the browser, not decoding it only recognizes the naive form of the attack. Resources (`url()`) go through `is_safe_href()`, the same whitelist as the `href` attributes: we do not maintain two definitions of "safe" that would end up diverging. `expression()` and `-moz-binding` make the **whole declaration** be removed, not just the keyword — erasing `expression(` left `alert(1))` behind, invalid CSS in a file we have just declared clean.

`SvgHandler::sanitize()` is also reused by the icon picker of [MenuCreator](menu-creator.md) (`ajax_sanitize_svg`): same risk, same whitelist, no second cleaner.

## Pitfalls at a glance

Each is detailed in its section above.

- Imagick ignores `setImageCompressionQuality()` alone for AVIF: set `setCompressionQuality()` too (encoder).
- `heic:speed=10` is refused by libheif (encoder).
- `stripImage()` removes the ICC profile; large profiles must be converted, not dropped (encoder).
- `Vary: Accept` on every JPEG/PNG answer, AVIF or not, and required by the self-test on the PNG answer too (delivery).
- No `immutable` on the negotiated files; the one-year `max-age` stays, so a cached AVIF outlives "serve the original format" (delivery).
- `wp_update_attachment_metadata` runs several times during one upload: queue at the end of the generation only (lifecycle).
- LiteSpeed / OpenLiteSpeed: no headers from `.htaccess`, rewrite target cached per URL regardless of `Accept`, a removed `.htaccess` keeps applying (delivery).
- "AVIF delegate missing" is the CLI image only: never encode in the CLI (capabilities, queue).
- A main file at most 2560 px is the raw upload, not WordPress's q82 progressive JPEG (settings).
