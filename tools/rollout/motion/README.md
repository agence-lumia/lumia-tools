# Motion rollout (one site at a time)

Replaces the "SK Motion" FluentSnippets snippet with the Motion module on a site hosted with the
Dokploy WordPress template. Run from a machine with SSH access as `admin`; nothing is copied to the
server except through `docker exec` (the PHP helpers go on stdin).

```bash
tools/rollout/motion/motion-rollout.sh <host> <cron-container>                       # check only, writes nothing
tools/rollout/motion/motion-rollout.sh <host> <cron-container> --apply               # existing site
tools/rollout/motion/motion-rollout.sh <host> <cron-container> --apply --motion-tab  # boilerplate / new site
# --zip <file>: install that zip instead of `wp plugin update` (lab, before the release)
```

`--apply`, in order, stopping at the first problem:

1. Backup: `~admin/lumia-backup/<site>-before-motion-<date>.sql` and the snippet folder (`.tgz`).
2. Plugin update (`wp plugin update lumia-tools`, or `--zip`), then version ≥ 2.2.0 and active.
3. Motion turned on (`activate.php`).
   Former class names used outside the class fields (a Bricks interaction adding
   `sk-hover-underline` to menu items, as on maison-aline) stop the run: nothing renames them and
   nothing styles them once the snippet is off (`legacy-scan.php`).
4. A published snippet that drives `window.StudioKyneMotion` itself (other than SK Motion) stops the
   run before anything is migrated: it would break silently (facpa's "Interactions" snippet).
5. Existing site: `wp lumia motion migrate` (dry run, then `--apply`: `sk-*` renamed in place, same ID,
   no page rewritten). With `--motion-tab`: `to-motion-tab.php` moves each effect class into the
   Motion tab of its elements, then deletes the classes (revisions are left alone).
6. SK Motion snippet in draft (`snippet-off.php`, found by the library it loads, its name differs
   between sites) and the FluentSnippets index rebuilt: without it, the drafted snippet keeps loading.
7. Object cache and Cache Enabler purged, then the home page must answer without the former library.

Traps met while writing it (all handled):

- Without `--user`, Bricks refuses to write page content without a word (`update_post_metadata`:
  no builder access): `to-motion-tab.php` needs an administrator and reads back every write before
  deleting any class.
- `docker exec` without `-i` passes no stdin: an archive piped to it arrives empty.
- `printf … | grep -q` under `pipefail` fails on SIGPIPE when grep matches: outputs are captured,
  then searched with here-strings.
- UFW `limit` cuts the 6th SSH connection within 30 s: one multiplexed connection for the run.
- The plugin's updater caches the last GitHub release for 12 h: its transient and WordPress's
  `update_plugins` are deleted before `wp plugin update`, or a fresh release is not offered.
- PHP-FPM re-reads changed files every `opcache.revalidate_freq` (60 s in the template): the
  rebuilt snippet index can take that long to reach the pages, so the front check retries for 90 s.
- A site in Bricks maintenance mode answers 503 to visitors: check its pages logged in.

Not handled by the script (seen during the 15/10/2026 rollout):

- A site with its own animation snippet loading GSAP and Lenis from a CDN (coyac,
  "3-scroll-animations-batch"): turn the module's smooth scroll off there, or two Lenis fight.
- facpa: its "Interactions" snippet drives `window.StudioKyneMotion`; the script refuses the site.

Restore: `docker exec -i <cron> wp --allow-root db import - < <backup>.sql`, then the snippets with
`docker exec -i <cron> sh -c 'tar -xzf - -C /var/www/html/wp-content' < <snippets>.tgz`, then
`wp cache flush`.
