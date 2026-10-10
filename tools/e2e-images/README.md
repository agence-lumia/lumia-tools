# Image delivery bench (AVIF siblings served by `Accept` negotiation)

Throwaway WordPress stacks to check, on real web servers, that `photo.jpg` is answered with
`photo.jpg.avif` **only** to clients that announce `image/avif`, always with `Vary: Accept`, and
that everyone else (mail clients, bots, proxies, `?original`) gets the JPEG/PNG. Used by the
Image Optimizer work (spec `docs/superpowers/specs/2026-10-10-image-optimizer-avif-negotiation-design.md`,
sections 1, 8, 9.2, 9.12).

Nothing here ships: `tools/` is excluded from the release zip (`tools/build/zip-excludes.txt`),
and `phpcs.xml.dist` skips this folder. The authentication mu-plugin is the one of the existing
bench (`tools/e2e/mu-plugins/e2e-auth.php`, `X-E2E-User` header): **it must never leave the
bench**; `run.sh` refuses to build a zip that contains `tools/` or that file. The images are
generated (`fixtures/make-fixtures.php`), never taken from a client.

Requirements: bash, docker (with compose), curl, rsync, zip, unzip (and jq for `assert-delivery.sh`). The `nginx` stack also needs
the Dokploy template repository next to this one (`TEMPLATE_DIR`, default
`../../../wp-dokploy-template`, on the branch that carries the AVIF rule) and Python >= 3.11 to
render it (otherwise `python:3.12-alpine` is used through Docker). No PHP on the host.

## Stacks

Each stack is an independent compose project (`lumia-img-<stack>`, named volumes per stack); they
can run side by side. Administrator: `admin` / `admin`.

| Stack | Web server + PHP | URL | What it mirrors |
|---|---|---|---|
| `nginx` | `nginx:1-alpine` with the template's **rendered** `nginx.conf` (`ci/render-payload.py`), `wordpress:7-php8.5-fpm-alpine` (limits 2 CPU / 1536M, the template's `uploads.ini`, `opcache.ini`, `fpm-performance.conf`), MariaDB 12, plus the template's `cron` container (`wordpress:cli-2-php8.5`) | http://localhost:8091 | the production sites |
| `apache` | `wordpress:php8.5-apache` (Apache 2.4, mod_php, `AllowOverride All`), `mod_headers` enabled | http://localhost:8092 | a shared host on Apache |
| `ols` | `litespeedtech/openlitespeed:1.9.3-lsphp85` | http://localhost:8093 | a LiteSpeed host, **approximately** (see Limits) |
| `nginx-plain` | the `nginx` stack with the template's AVIF location removed from the rendered `nginx.conf` | http://localhost:8095 | an nginx host without the rule |
| `nginx-novary` | the `nginx` stack with the AVIF location kept but its `add_header Vary` removed | http://localhost:8101 | a host that copied the rule incompletely |
| `nginx-mig` | the `nginx` stack as it is, own data | http://localhost:8102 | the production sites, for the legacy media migration (`assert-migration.sh`) |
| `cdn` | the `nginx` stack behind `proxy-cdn` (`cdn/default.conf.template`): a caching proxy that ignores `Vary` and sends `cf-ray` / `cf-cache-status`; the PHP container's loopback goes through it too (origin on 8096) | http://localhost:8094 | a site behind Cloudflare without "Vary for images" |
| `cdn-noquery` | the same proxy, which never caches a URL with a query string (origin on 8098) | http://localhost:8097 | a zone whose cache skips query strings: the probe bypasses it, the image URLs do not |
| `cdn-vary` | the same proxy, keeping one cache entry per `Accept` value (origin on 8100) | http://localhost:8099 | a CDN that honours `Vary` |

- The nginx snippets `init.sh` writes in production (`nginx-servername.conf` = `server_name localhost;`,
  `nginx-security.conf`, `nginx-redirects.conf`) are stubs in `nginx/`, copied into the webroot
  by a one-shot container before nginx starts.
- Every stack has a `loopback-*` sidecar (socat in the PHP container's network namespace) so that
  `http://localhost:<port>` also works **from inside** the PHP container, like a production
  container reaching itself through its public domain: the plugin's delivery self-test needs it.
- `apache`: the official image has no `mod_headers`. It is enabled here; `run.sh apache-mod apache
  headers off` removes it to test the "no `mod_headers`" scenario. `run.sh apache-override apache
  nofileinfo` gives `wp-content/uploads` an `AllowOverride` without `FileInfo` (a host where every
  `RewriteEngine` / `Header` / `AddType` line of `.htaccess` answers 500); `fileinfo` restores it.
- `nginx-plain`, `nginx-novary`, `nginx-mig` and the `cdn*` stacks are compose projects of their own
  (`lumia-img-<stack>`) built from the `nginx` services: ports, loopback target and proxy
  behaviour come from `E2E_NGINX_PORT`, `E2E_SITE_PORT`, `E2E_LOOPBACK_TARGET`, `E2E_CDN_PORT`,
  `E2E_CDN_IGNORE` and `E2E_CDN_SKIP`, set by `run.sh` (the proxy's template is rendered by the
  nginx image's envsubst, limited to `CDN_*`).
- The image tags are the closest available ones: there is no `wordpress:7-php8.5-apache` pair, so
  `php8.5-apache` ships its own (newer) WordPress, and its Imagick is 7.1.1-43 (production: 7.1.2-30).

## Commands

```bash
tools/e2e-images/run.sh up <nginx|apache|ols>            # start + install WordPress (idempotent)
tools/e2e-images/run.sh import-fixtures <stack>          # generate the synthetic images, import them as media
tools/e2e-images/run.sh fixtures <stack>                 # only generate them (out/fixtures/)
tools/e2e-images/run.sh install-lumia <stack>            # zip of the working tree (release excludes), installed and activated
tools/e2e-images/run.sh wp <stack> <args...>             # WP-CLI under the stack's web PHP (FPM / mod_php / lsphp)
tools/e2e-images/run.sh wp-cron nginx <args...>          # WP-CLI in the template's cron container (cli image)
tools/e2e-images/run.sh assert <stack> <script.php> [args]  # `wp --user=admin eval-file` of a PHP assertion script ($args)
tools/e2e-images/run.sh make-avif <stack> 2026/10/x.jpg  # hand-place x.jpg.avif next to a JPEG (no plugin needed)
tools/e2e-images/run.sh htaccess <apache|ols> on|off     # hand-written spec 9.2 block in uploads/.htaccess
tools/e2e-images/run.sh curl-matrix <stack> <path|url> [avif|original|none]
tools/e2e-images/run.sh latency <stack> <path|url> <seconds>   # median / p95 / max of a page over a duration
tools/e2e-images/run.sh apache-mod apache headers on|off
tools/e2e-images/run.sh apache-override apache fileinfo|nofileinfo
tools/e2e-images/run.sh down <stack>                     # stop and delete the data (images are kept)
```

`BRICKS_ZIP=<path> run.sh up <stack>` installs and activates the Bricks theme (proprietary, not
versioned). Without it the Bricks checks must be skipped, with a message.

Exit codes: 0 success, non-zero (usually 1) failure, 2 usage, 130 SIGINT, 143 SIGTERM.

### Which PHP runs what

`wp` runs the official WP-CLI **phar** (extracted from `wordpress:cli-2-php8.5`) under the PHP of
the stack's **web** container, as the web user: it is the runtime that encodes in production
(spec 9.1: the `cron` image has Imagick without any codec: 0 formats, so nothing is encoded in
the CLI). `wp-cron` is the template's cron container as it is. Both see the same volume.

```bash
run.sh wp nginx eval 'echo count( Imagick::queryFormats() );'        # 234 (AVIF, JPEG, PNG encoders)
run.sh wp-cron nginx eval 'echo count( Imagick::queryFormats() );'   # 0
```

### Fixtures (`import-fixtures`)

Generated once into `out/fixtures/` (`REGENERATE_FIXTURES=1` to redo), imported with `wp media
import`; the command prints id and URL of each.

| File | Content |
|---|---|
| `photo-4000.jpg` | 4000x3000 gradient + plasma + noise (~11 MB; WordPress scales it to `-scaled`, 2560 px) |
| `photo-p3.jpg` | 1600x1200, small Display P3 profile (516 B) embedded |
| `photo-bigicc.jpg` | 1600x1200, 60 KB ICC profile (above the 4 KB threshold) |
| `visual-alpha.png` | 1200x800, soft transparent shapes |
| `logo-flat.png` | 300x100, flat colours |
| `anim.gif` | two frames |
| `corrupt.jpg` | valid JPEG header, data cut after 700 bytes |
| `photo-16bit.png` | 800x600, 16 bits per channel (encoder fixture, not imported) |
| `photo-exif6.jpg` | 800x600 stored, EXIF orientation 6 + GPS, small P3 profile (encoder fixture, not imported) |
| `photo-gps.jpg` | 800x600, orientation 1, EXIF with GPS + XMP APP1 segments, small P3 profile (encoder fixture, not imported) |
| `photo-cmyk.jpg` | 600x400, CMYK JPEG without a profile (encoder fixture, not imported) |
| `visual-alpha-noopt.png` | copy of `visual-alpha.png` under an excluded name (not imported) |
| `modern-opaque.avif`, `modern-alpha.webp`, `modern-anim.webp` | opaque AVIF, WebP with transparency, two-frame WebP: uploads converted (or not) by spec 9.13 (not imported; skipped with a message where Imagick cannot write the format) |

`run.sh assert` generates the fixtures too when they are missing: `assert-lifecycle.php` sideloads
its own copies (spec 3, 4, 9.4, 9.5, 9.7, 9.8, 9.13: state, queueing, siblings, name registry,
settings v2, AVIF/WebP upload conversion, Files module, deactivation purge). It needs the plugin
installed and the Image Optimizer module active; it encodes nothing (fake siblings).

The P3 profile is built by hand (matrix/TRC v2.4 profile, Apple's D50-adapted primaries): valid for
ImageMagick and lcms, not byte-identical to Apple's.

## Encoder assertions (`assert-encoder.php`, `assert-encoder-cli.php`)

`AvifEncoder`, `JpegMetadata` and the capabilities of `ImageProcessor`, in the runtime that encodes
in production (the FPM image's PHP; needs `import-fixtures` and `install-lumia` first):

```bash
tools/e2e-images/run.sh install-lumia nginx
tools/e2e-images/run.sh assert nginx tools/e2e-images/assert-encoder.php
tools/e2e-images/run.sh wp-cron nginx eval "$(tail -n +2 tools/e2e-images/assert-encoder-cli.php)"
```

The second script runs in the template's `cron` container (`wp eval` takes code without the opening
tag): `can_encode_here` must be false there. It also checks that the capabilities cache of the two
runtimes does not collide.

## Delivery self-test assertions (`assert-delivery.sh`)

`DeliveryProbe` / `HtaccessWriter` (spec 1, 9.2, 9.10), driven like an administrator: real login
cookies, the module activated through the admin AJAX toggle (the probe runs in the web runtime,
where `SERVER_SOFTWARE` is known), uploads through `async-upload.php`.

```bash
tools/e2e-images/assert-delivery.sh nginx          # one stack (started and installed if needed)
tools/e2e-images/assert-delivery.sh all --down     # every stack in turn, each one stopped after its run
```

| Stack | Asserted |
|---|---|
| `nginx` | mode `nginx`, daily check scheduled, Retest answers `{mode, cdn, reason, checked_at}` (and refuses a missing nonce), Delivery tab without the nginx rule, client matrix conform on an uploaded media (sibling placed with `make-avif`: the queue does not encode yet), browser check veto (`none`, siblings deleted) and its lifting |
| `nginx-plain` | mode `none` (`no_rule`), the nginx rule shown in the Delivery tab, no `.avif` after an upload, the JPEG for every client |
| `apache` | `# keep-me` kept above the exact block, mode `htaccess`, matrix conform, WordPress's 404 for a missing upload (`RewriteOptions Inherit`); `mod_headers` off: `none` (`no_vary`), block removed, the JPEG for every client; `AllowOverride` without `FileInfo`: `none` (`server_error`), block removed, no 500 left; module and plugin deactivation remove the block |
| `ols` | the self-test fails (`none`), the block is removed; with a hand-placed sibling and AVIF clients priming the workers, a retest deletes the siblings (probe and media) and every client gets the PNG |
| `cdn` | cold proxy: Chrome first, then Outlook gets the cached AVIF; the self-test says `none`, CDN `cloudflare` (`cdn_vary`), and the generated `.avif` are deleted |
| `cdn-noquery` | `none` (`cdn_unproven`, cache status `BYPASS` recorded) although types and Vary are right; on the plain image URL Chrome then Outlook gets the cached AVIF; a retest deletes the `.avif` |
| `cdn-vary` | mode `nginx` behind CDN `cloudflare`, proven by a cache `HIT` on a repeated variant; client matrix conform through the proxy |
| `nginx-novary` | `none` (`no_vary`); a sibling served without Vary is deleted by the retest, then the JPEG for every client |

## Legacy media migration (`assert-migration.sh`)

`wp lumia images migrate` (spec 5, 9.1, 9.3, 9.9) on the `nginx-mig` stack, end to end:

```bash
tools/e2e-images/assert-migration.sh            # setup, seed, every scenario below
tools/e2e-images/assert-migration.sh --down     # same, then the stack is deleted
tools/e2e-images/crawl-check.sh nginx-mig       # alone: every image of the site, Chrome and */*
```

`seed-legacy.php` (run with `run.sh assert`) recreates, in `uploads/e2e-legacy/`, media items as
the former module left them (commit 7a606e2: AVIF next to the source, extension replaced, source
deleted, `original_image` untouched, metadata / attached file / MIME / guid rewritten,
`_lumia_optimized*` metas): `big-photo` (scaled, original on disk: case 1), `big-lost` (scaled,
original deleted), `photo`, `alpha` (transparency), `logo` (flat colours), `webp-photo` (WebP),
`collide` (its name taken since by another upload `collide.jpg`) and `backup` (former backup copy
in `lumia-originals-<token>/`). Their URLs go into a page (`/legacy-gallery/`: block image with
`srcset`, inline `background-image`, a `<link>` to the Bricks CSS file), a Bricks-like serialized
meta, a Rank Math meta, an escaped JSON meta, an option and `uploads/bricks/css/post-<id>.min.css`.
It writes `out/mig-manifest.json`. Re-running it replaces everything it seeded.

| Scenario | Asserted |
|---|---|
| cron container (`wordpress:cli`) | exit code 1 and the exact refusal message |
| `--dry-run` | "Would process: 8"; database (posts, postmeta, options without transients) and uploads (path, size, mtime, md5) fingerprints identical |
| `--limit=1`, `kill -9` once the journal reads `files_written` (`--require` of a file that sleeps on the step action) | one journal at that step, legacy metas and attached file unchanged, the files written on disk, every URL of the page 200 |
| full run | the interrupted item resumes, 8 processed; per item: legacy metas and journal gone, attached file / metadata / MIME / guid / sizes / filesizes right, `original_image` kept (case 1) or dropped (missing), siblings hard-linked to the legacy AVIF and recorded fresh (`done`) or none (`pending`: case 1, WebP), legacy files on disk and listed, every old `.avif` / `.webp` URL 200 with its type, every new URL 200 JPEG/PNG for `*/*`; the `collide` family moved to `collide-1`, the other `collide.jpg` untouched; every URL rewritten (content, Bricks meta, Rank Math, escaped JSON, option, Bricks CSS) |
| `crawl-check.sh` | every `src` / `srcset` / `url()` of the sitemap pages and their stylesheets: 200, AVIF only for Chrome on a `.jpg` / `.png`, its own type for `*/*` |
| second run | "Processed: 0" |
| permanent deletion of `photo` | fallbacks, siblings and legacy files gone |

## Client matrix (`lib.sh`)

`curl-matrix` sends these exact `Accept` / `User-Agent` pairs, then `Chrome + ?original`.
`assert_type <url> <accept> <user-agent> <expected-content-type>` and `assert_vary <url>` (exit
code 1 and a message on stderr) can be sourced from `lib.sh` in any script.

| Client | `Accept` | AVIF sibling present |
|---|---|---|
| Chrome | `image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8` | **avif** |
| Safari 17 | `image/webp,image/avif,image/jxl,image/heic,image/heic-sequence,video/*;q=0.8,image/png,image/svg+xml,image/*;q=0.8,*/*;q=0.5` | **avif** |
| Firefox | `image/avif,image/webp,image/png,image/svg+xml,image/*;q=0.8,*/*;q=0.5` | **avif** |
| Apple Mail | Safari's (assumed: WebKit; **not measured**) | **avif** |
| Outlook Windows | `*/*` | JPEG/PNG |
| GoogleImageProxy | `*/*` (UA `GoogleImageProxy`) | JPEG/PNG |
| Googlebot-Image | `image/*` (UA `Googlebot-Image/1.0`) | JPEG/PNG |
| Storebot-Google, Google-Shopping | `*/*` (assumed, not measured) | JPEG/PNG |
| facebookexternalhit, LinkedInBot | `*/*` | JPEG/PNG |
| Mailchimp, Brevo | `*/*` (stand-in UAs: the real ones were not captured) | JPEG/PNG |
| curl | `*/*` | JPEG/PNG |
| Chrome + `?original` | Chrome's | JPEG/PNG |

Modes: `avif` (clients announcing `image/avif` expect `image/avif`, the others the JPEG/PNG
type deduced from the extension, `?original` too, and every response must carry `Vary: Accept`),
`original` (everyone expects the JPEG/PNG; `Vary: Accept` still required) and `none` (default,
prints only). A failing row makes the command exit 1.

Known and harmless: `Accept: image/avif;q=0` still gets the AVIF (no real client sends it).

## Latency (`latency`)

`run.sh latency nginx / 60` fires sequential requests for 60 s and prints the count, median, p95
and max in ms. Run it once idle and once while a bulk runs (spec 9.6: the WordPress container is
limited to 2 CPU here as in production, and an encode takes about 2 of them).

## Limits

- **OpenLiteSpeed is not LiteSpeed Enterprise** (the engine of shared hosts such as Hostinger).
  OLS has no `.htaccess`-wide semantics guarantee: see the findings below before trusting a
  pass on this stack. The plugin's delivery self-test remains the safety net in production.
- The OpenLiteSpeed image's Imagick **lists** AVIF but has **no AVIF encoder** ("no encode
  delegate for this image format `AVIF'"): the plugin cannot encode on this stack, so siblings are
  placed with `make-avif` (encoded by the production FPM image in a throwaway container).
- The `cli` image has Imagick without codecs (0 formats): GD only. This is the spec 9.1 finding,
  reproduced by `wp-cron nginx eval`.
- `apache` and `ols` run a different WordPress/PHP/ImageMagick build than production
  (`wordpress:php8.5-apache`: Imagick 7.1.1-43; lsphp: 7.1.2-18).
- The nginx stack has no Redis, no Cache Enabler, no TLS: the matrix runs over plain HTTP on
  `localhost`, `Host: localhost:8091`.
- Port 8091 / 8092 / 8093 (8102 for `nginx-mig`) must be free on the host.

## OpenLiteSpeed findings (1.9.3, lsphp 8.5)

Measured on this bench with a hand-placed sibling and the `.htaccess` block of spec 9.2 in
`uploads/`. They explain why the plugin must keep its delivery self-test as the only judge, and
why a green `ols` run proves nothing about LiteSpeed Enterprise.

1. **No `Vary` at all.** OpenLiteSpeed reads `.htaccess` through its *rewrite* parser only: the log
   says `Invalid rewrite directive: Header append Vary Accept` (also `RewriteOptions Inherit` and
   `AddType`, at INFO level). Every variant tried is ignored for a static file: `Header append/set/
   always`, with or without `<FilesMatch>` / `<IfModule>`, `[E=Vary:Accept]`, `[E=X-Test:...]`.
   `Vary: Accept` is never sent, so the self-test must fail here and the block must be removed
   (JPEG only, which is the safe outcome).
2. **The rewrite target is cached per URL, per worker, and ignores `Accept`.** After an AVIF-capable
   client has been rewritten to `photo.jpg.avif`, other clients on the same URL get the AVIF too
   (Outlook's `*/*` got `image/avif`; `?original` did too). Measured after a restart: 40 requests
   with `Accept: */*` first -> 0/40 AVIF; then 40 Chrome -> 40/40; then `*/*` again -> 33/40 AVIF
   (the 7 others hit workers that had not seen a Chrome request yet). The reverse order caches
   nothing. With one worker per CPU, results look random until every worker has seen an AVIF
   client. This is exactly "images broken in a mail client": do not enable the rewrite on OLS.
3. A `uploads/.htaccess` written while the server runs is parsed lazily, worker by worker (one
   `RewriteFile ... parsed` log line per worker): a probe right after the write may or may not see
   it. `lswsctrl restart` makes all workers load it.
4. The root WordPress `.htaccess` (permalinks) is bound to the vhost context and read at start:
   `run.sh up ols` restarts the server after the permalink flush, otherwise `/hello-world/` is 404.
5. The OLS image's Imagick lists AVIF but cannot encode it (see Limits): use `make-avif`.
6. **A removed `.htaccess` keeps applying** in the workers that had loaded it: after the plugin
   removed its block (failed self-test), AVIF clients were still rewritten and, once primed, the
   AVIF went to every client (15/16 `*/*` requests), for minutes. Deleting the `.avif` sibling
   stops it at once: the plugin deletes the probe's sibling, and on LiteSpeed every generated one,
   whenever a test fails with its block in place.

LiteSpeed Enterprise has its own `.htaccess` engine (Apache `Header` support, change detection):
what happens there can only be established by the self-test on a real host.
