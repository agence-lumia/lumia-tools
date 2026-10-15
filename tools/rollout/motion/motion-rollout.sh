#!/bin/bash
# Motion rollout, one site at a time, run from the Mac.
#
#   ./motion-rollout.sh <host> <cron-container>                       check only (writes nothing)
#   ./motion-rollout.sh <host> <cron-container> --apply               backup, update, migrate, snippet off
#   ./motion-rollout.sh <host> <cron-container> --apply --motion-tab  same, but effects go to the Motion tab
#                                                                     and the classes are deleted (boilerplate)
#   --zip <file>   install this zip instead of `wp plugin update` (lab tests before the release)
#
# Stops at the first problem. Backup: ~admin/lumia-backup/<site>-before-motion-<date>.sql + snippets .tgz.
set -euo pipefail

MIN_VERSION=2.2.0
HERE="$(cd "$(dirname "$0")" && pwd)"
HOST="${1:?host}"; C="${2:?cron container}"; shift 2
APPLY=0; TAB=0; ZIP=""
while [ $# -gt 0 ]; do
	case "$1" in
		--apply) APPLY=1 ;;
		--motion-tab) TAB=1 ;;
		--zip) ZIP="${2:?zip}"; shift ;;
		*) echo "unknown option $1" >&2; exit 2 ;;
	esac
	shift
done

# One SSH connection for the whole run: UFW `limit` cuts the 6th connection in 30 s.
SOCK="$(mktemp -u /tmp/lm-rollout.XXXXXX)"
ssh -o BatchMode=yes -o ControlMaster=yes -o ControlPath="$SOCK" -o ControlPersist=600 -Nf "admin@$HOST"
trap 'ssh -o ControlPath="$SOCK" -O exit "admin@$HOST" 2>/dev/null || true' EXIT
R() { ssh -o ControlPath="$SOCK" "admin@$HOST" "$@"; }
WP() { R "docker exec -i $C wp --allow-root $*"; }
PHP() { R "docker exec -i $C wp --allow-root eval-file - ${2:-} --user=$ADMIN" < "$HERE/$1" || die "$1 failed"; }
step() { printf '\n== %s\n' "$*"; }
die() { printf '\nSTOP: %s\n' "$*" >&2; exit 1; }

URL="$(WP option get home)"
SITE="$(printf '%s' "$URL" | sed -E 's#https?://##; s#/.*##')"
# By ID: a login may hold spaces, which would split over SSH.
ADMIN="$(WP user list --role=administrator --field=ID --orderby=ID --number=1)"
[[ "$ADMIN" =~ ^[0-9]+$ ]] || die "no administrator found"
step "$SITE ($C on $HOST), admin $ADMIN"
R "docker exec -i $C wp --allow-root eval-file -" < "$HERE/survey.php"
[ "$(WP option get template)" = bricks ] || die "Bricks is not the active theme"

# A published snippet that drives the former engine itself (facpa) would break silently.
OTHERS="$(PHP snippet-off.php check)"
if [ "${OTHERS#ok}" = "$OTHERS" ]; then
	printf 'WARNING: %s\nAdapt them first.\n' "$OTHERS"
	[ "$APPLY" = 0 ] || die "snippets use the former engine"
fi

if [ "$APPLY" = 0 ]; then
	step "Check only: nothing written. Add --apply to run the rollout."
	WP plugin list --name=lumia-tools --fields=version,update,update_version
	exit 0
fi

step "Backup"
STAMP="$(date +%Y%m%d-%H%M%S)"
R "mkdir -p ~/lumia-backup && docker exec $C wp --allow-root db export - > ~/lumia-backup/$SITE-before-motion-$STAMP.sql && test -s ~/lumia-backup/$SITE-before-motion-$STAMP.sql"
if R "docker exec $C test -d /var/www/html/wp-content/fluent-snippet-storage"; then
	R "docker exec $C tar -czf - -C /var/www/html/wp-content fluent-snippet-storage > ~/lumia-backup/$SITE-snippets-$STAMP.tgz"
else
	echo "no fluent-snippet-storage folder: no snippet to back up"
fi
R "ls -la ~/lumia-backup/ | grep $SITE"

step "Plugin"
if [ -n "$ZIP" ]; then
	R "cat > /tmp/lm-rollout.zip" < "$ZIP"
	R "docker cp /tmp/lm-rollout.zip $C:/tmp/lm-rollout.zip && rm -f /tmp/lm-rollout.zip"
	WP plugin install /tmp/lm-rollout.zip --force
	R "docker exec -u 0 $C rm -f /tmp/lm-rollout.zip"
else
	# The plugin's updater caches the last GitHub release it saw (12 h): forget it, and the
	# WordPress list of updates, or a fresh release is not offered.
	WP transient delete lumia_github_update_stable >/dev/null 2>&1 || true
	WP transient delete update_plugins --network >/dev/null 2>&1 || true
	WP plugin list --name=lumia-tools --fields=version,update_version
	WP plugin update lumia-tools
fi
VERSION="$(WP plugin get lumia-tools --field=version)"
WP plugin is-active lumia-tools || die "lumia-tools is not active"
[ -n "$ZIP" ] || [ "$(printf '%s\n%s\n' "$MIN_VERSION" "$VERSION" | sort -V | head -1)" = "$MIN_VERSION" ] \
	|| die "lumia-tools $VERSION < $MIN_VERSION (release not published, or update not found)"
echo "lumia-tools $VERSION"

step "Motion module"
OUT="$(PHP activate.php)"
grep -q 'bool(true)' <<< "$OUT" || die "Motion could not be turned on: $OUT"


step "Former names outside the class fields"
# A Bricks interaction that adds sk-hover-underline to menu items (maison-aline) is renamed by
# nothing and styled by nothing once the snippet is off: fix such places first.
OUT="$(PHP legacy-scan.php)"; echo "$OUT"
grep -q '^0 hit(s)$' <<< "$OUT" || die "former class names used outside the class fields: rename them first"

if [ "$TAB" = 1 ]; then
	step "Effects to the Motion tab (dry run)"
	OUT="$(PHP to-motion-tab.php)"; echo "$OUT"
	grep -q '^STOP' <<< "$OUT" && die "conversion refused"
	step "Effects to the Motion tab (apply)"
	OUT="$(PHP to-motion-tab.php apply)"; echo "$OUT"
	grep -q '^Applied:' <<< "$OUT" || die "conversion failed"
	WP bricks regenerate_assets
else
	step "Class migration (dry run)"
	WP lumia motion migrate --user="$ADMIN"
	# A conflict (sk-x and lm-x both exist) leaves sk-x alone: its elements would lose their
	# effect once the snippet is off. The command only warns, so the JSON is read here.
	JSON="$(WP lumia motion migrate --format=json --user="$ADMIN")"
	# WP-CLI warnings may come before the JSON: it starts at the first "{". Unreadable: stop.
	CONFLICTS="$(python3 -c 'import json, sys
t = sys.stdin.read()
print(",".join(json.loads(t[t.index("{"):]).get("conflicts", [])))' <<< "$JSON")" || die "unreadable migrate report: $JSON"
	[ -z "$CONFLICTS" ] || die "conflicts (an lm-* class already exists for): $CONFLICTS"
	step "Class migration (apply)"
	WP lumia motion migrate --apply --user="$ADMIN"
	WP bricks regenerate_assets
fi

step "Snippet off"
OUT="$(PHP snippet-off.php)"; echo "$OUT"
grep -q '^STOP' <<< "$OUT" && die "snippet"

step "Cache"
WP cache flush
R "docker exec $C sh -c 'rm -rf /var/www/html/wp-content/cache/cache-enabler/* 2>/dev/null; true'"

step "Front check"
# PHP-FPM re-reads changed files only every opcache.revalidate_freq (60 s in the template): the
# snippet index rebuilt above may take that long to reach the pages. Up to 90 s, then stop.
fetch_home() {
	HTML="$(curl -fsS --compressed "$URL/?lm-rollout=$STAMP-$1")" || die "home page does not answer"
	# Without a query string too: the copy a page cache serves to visitors.
	HTML="$HTML$(curl -fsS --compressed "$URL/")" || die "home page (cached copy) does not answer"
}
for TRY in 1 2 3 4 5 6 7 8 9 10; do
	fetch_home "$TRY"
	# Captured first, then searched: `printf | grep -q` under pipefail fails on SIGPIPE when grep matches.
	grep -q 'sk-motion' <<< "$HTML" || break
	[ "$TRY" = 10 ] && die "the former engine is still loaded on the home page after 90 s"
	echo "former engine still served (PHP cache), new try in 10 s"
	sleep 10
done
grep -q 'lumiaMotionConfig\|data-lm' <<< "$HTML" || echo "note: no Motion effect on the home page"
echo "home: ok, former engine gone"

step "Done: $SITE. Backup ~/lumia-backup/$SITE-before-motion-$STAMP.sql. Check the site, then report."
