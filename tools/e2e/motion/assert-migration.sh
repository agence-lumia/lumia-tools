#!/usr/bin/env bash
#
# Checks `wp lumia motion classes|migrate` on the local Bricks site. See README.md.
#
#   tools/e2e/motion/assert-migration.sh
#
# The database is exported first and imported back when the script ends, whatever the outcome
# (a trap): the site returns to its exact initial state, which the script proves by comparing a
# checksum of the options and of the Bricks post meta before and after. The export goes to
# tools/e2e/motion/out/ (git-ignored); its path is printed at the start and the end.
#
# Environment: LM_E2E_WP (WP-CLI command, default `wp`), LM_E2E_USER (default local-admin),
# LM_E2E_PLUGIN_DIR (plugin path as WP-CLI sees it), LM_E2E_URL (default http://localhost:8088),
# LM_E2E_SKIP_ENTER=1 to skip the final `run.sh enter` (it needs Chrome).
#
# Exit code: 0 and "ok migration" when everything passed, 1 otherwise.

# The node -e programs are JavaScript, not shell: no expansion wanted.
# shellcheck disable=SC2016
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WP_CMD="${LM_E2E_WP:-wp}"
WP_USER="${LM_E2E_USER:-local-admin}"
PLUGIN_DIR="${LM_E2E_PLUGIN_DIR:-/var/www/html/wp-content/plugins/lumia-tools}"
BASE_URL="${LM_E2E_URL:-http://localhost:8088}"
HELPER="${PLUGIN_DIR}/tools/e2e/motion/assert-migration.php"
BACKUP="${DIR}/out/migration-backup-$(date +%Y%m%d-%H%M%S).sql"
RESTORE=0

mkdir -p "${DIR}/out"

# WP-CLI as the test user. $1... are the arguments.
wpx() {
	sh -c "${WP_CMD} \"\$@\" --user=${WP_USER}" -- "$@"
}

# WP-CLI without --user (to check what the commands say when none is given).
wp_anon() {
	sh -c "${WP_CMD} \"\$@\"" -- "$@"
}

helper() {
	wpx eval-file "${HELPER}" "$@"
}

fail() {
	echo "FAIL migration: $*" >&2
	exit 1
}

# JavaScript assertion. The data comes in the environment variables named after the arguments:
#   js 'code using process.env.A' A="..." B="..."
js() {
	local code="$1"
	shift
	env "$@" node --input-type=module -e "
		const assert = (c, m) => { if (!c) { console.error('FAIL migration: ' + m); process.exit(1); } };
		const env = process.env;
		${code}
	"
}

# The JSON line a command printed (the last line that starts with a brace).
json_of() {
	grep -E '^\{' | tail -n 1
}

# shellcheck disable=SC2329  # Called through the EXIT trap.
restore() {
	local code=$?
	trap - EXIT
	if [ "${RESTORE}" = 1 ]; then
		echo "restoring the database from ${BACKUP}" >&2
		if wpx db import - < "${BACKUP}" >/dev/null && wpx cache flush >/dev/null 2>&1; then
			RESTORED_SUM="$(helper checksum 2>/dev/null || true)"
			if [ -n "${INITIAL_SUM:-}" ] && [ "${RESTORED_SUM}" != "${INITIAL_SUM}" ]; then
				echo "FAIL migration: the restored site differs from the initial one (${INITIAL_SUM} / ${RESTORED_SUM})" >&2
				code=1
			else
				echo "site restored: checksum ${RESTORED_SUM} (same as before the test)" >&2
			fi
		else
			echo "FAIL migration: the import failed, restore by hand: wp db import ${BACKUP}" >&2
			code=1
		fi
	fi
	echo "export kept at ${BACKUP}" >&2
	if [ "${code}" = 0 ] && [ "${LM_E2E_SKIP_ENTER:-0}" != 1 ]; then
		"${DIR}/run.sh" enter || code=1
	fi
	if [ "${code}" = 0 ]; then
		echo "ok migration"
	fi
	exit "${code}"
}

# ---------------------------------------------------------------- export
echo "exporting the database to ${BACKUP}"
wpx db export - > "${BACKUP}"
if [ ! -s "${BACKUP}" ] || ! grep -q 'Dump completed' "${BACKUP}"; then fail "the export is empty or incomplete: ${BACKUP}"; fi
RESTORE=1
trap restore EXIT

INITIAL_SUM="$(helper checksum)"
[ "${INITIAL_SUM}" = "$(helper checksum)" ] || fail 'the checksum is not stable on an untouched site'
echo "initial checksum: ${INITIAL_SUM}"

# ---------------------------------------------------------------- state of a site with the former classes
helper seed
SEEDED="$(helper snapshot)"
js '
	const s = JSON.parse(env.SNAP);
	const names = s.classes.map((c) => c.name);
	const legacy = names.filter((n) => /^sk-/.test(n));
	assert(legacy.length === 10, "the seed should leave ten legacy classes, got " + legacy.join(","));
	assert(!names.some((n) => /^lm-(fade|slide-up|blur-up|zoom|zoom-blur|lines|words|letters|counter|underline)$/.test(n)), "no new class should exist after the seed");
	assert(!s.categories.some((c) => /^motion$/i.test(c.name)), "no Motion category should exist after the seed");
' SNAP="${SEEDED}"
SEED_SUM="$(helper checksum)"

# ---------------------------------------------------------------- dry run: lists everything, writes nothing
echo '--- wp lumia motion migrate (dry run)'
DRY_TEXT="$(wpx lumia motion migrate 2>&1)"
echo "${DRY_TEXT}" | sed -n '1,30p'
for name in sk-fade sk-reveal sk-entry-blur sk-scale-in sk-scale-in-blur sk-text-lines sk-text-highlight-words sk-text-highlight-chars sk-counter sk-hover-underline; do
	echo "${DRY_TEXT}" | grep -q "^${name}[[:space:]]" || fail "the dry run does not list ${name}"
done
echo "${DRY_TEXT}" | grep -q 'Dry run: nothing was written' || fail 'the dry run does not say it wrote nothing'
echo "${DRY_TEXT}" | grep -q 'will stop matching at --apply' || fail 'the dry run should warn about the selectors that will stop matching'
echo "${DRY_TEXT}" | grep -q 'keep working through the compatibility setting' || fail 'the dry run should list what compatibility keeps working'

DRY_JSON="$(wpx lumia motion migrate --format=json | json_of)"
EXPECTED="$(helper expected-usage)"
NOISE="$(helper noise-ids)"
echo "expected usage (computed on real posts only): ${EXPECTED}; noise posts: ${NOISE}"
SNIPPET_STATUS="$(helper snippet-status)"
js '
	const r = JSON.parse(env.R);
	const want = JSON.parse(env.EXPECTED);
	assert(r.applied === false, "applied should be false");
	assert(r.renamed.length === 10 && r.conflicts.length === 0, "ten renames and no conflict, got " + env.R.slice(0, 300));
	assert(want.unfiltered_posts > want.posts, "the bench should hold revisions and the like (unfiltered " + want.unfiltered_posts + ", real " + want.posts + "), or this test proves nothing");
	assert(r.posts === want.posts, "posts: the command says " + r.posts + ", real pages and templates that use a legacy class: " + want.posts + " (unfiltered: " + want.unfiltered_posts + ")");
	assert(r.elements === want.elements, "elements: the command says " + r.elements + ", expected " + want.elements);
	assert(r.elements >= 3 && r.posts >= 1, "elements and posts > 0");

	const all = r.selectors.concat(r.compat, r.snippets);
	assert(JSON.stringify(r.css_hits) === JSON.stringify(all), "css_hits is the three lists together");
	for (const id of env.NOISE.trim().split(/\s+/)) {
		assert(!all.some((h) => h.includes("post " + id + " ")), "post " + id + " (revision, trash or auto-draft) must not be reported");
	}

	const sel = r.selectors.join("\n"), kept = r.compat.join("\n");
	assert(/element mgtext3: selector of a former class name that stops matching/.test(sel), "the selector of a renamed class breaks: " + sel.slice(0, 300));
	assert(/element mgtext6: selector of a former class name that stops matching/.test(sel), "sk-counter is renamed in this dry run, so its selector breaks");
	assert(!/mgtext1|mgtext2|mgtext5/.test(sel), "only mgtext3 and mgtext6 stop matching, got " + sel.slice(0, 300));
	assert(/element mgtext1: legacy name that keeps working/.test(kept), "the underline variable keeps working");
	assert(/element mgtext2: legacy class typed in the CSS classes field/.test(kept), "the class typed by hand keeps working");
	assert(!all.some((h) => /mgtext5/.test(h)), "an agency class like .sk-container must never be reported");
	assert(/class lm-underline: legacy name that keeps working/.test(kept), "the underline variable in the CSS of lm-underline is reported as kept");
	assert(!/class lm-lines/.test(all.join("\n")), "the longer class name in lm-lines is not a legacy class");
	// Fluent snippet: reported only when published.
	const snippet = r.snippets.some((h) => /4-sk-motion\.php/.test(h));
	assert(snippet === (env.SNIPPET === "published"), "the former snippet is reported only when published (status " + env.SNIPPET + "), reported: " + snippet);
' R="${DRY_JSON}" EXPECTED="${EXPECTED}" NOISE="${NOISE}" SNIPPET="${SNIPPET_STATUS}"
[ "$(helper checksum)" = "${SEED_SUM}" ] || fail 'the dry run wrote something (options or Bricks post meta changed)'
echo 'ok dry run lists 10 classes and writes nothing'

# --apply and --dry-run exclude each other; --apply needs a user.
if wpx lumia motion migrate --apply --dry-run >/dev/null 2>&1; then fail '--apply --dry-run should be refused'; fi
APPLY_NO_USER="$(wp_anon lumia motion migrate --apply 2>&1 || true)"
echo "${APPLY_NO_USER}" | grep -q 'Give an administrator with --user' || fail "--apply without --user should be refused, got: ${APPLY_NO_USER}"
[ "$(helper checksum)" = "${SEED_SUM}" ] || fail 'a refused command wrote something'

# ---------------------------------------------------------------- a class made by hand collides
COUNTER_ID="$(helper add-class lm-counter)"
BEFORE_APPLY="$(helper snapshot)"
# The settings screen keeps its numbers five minutes: fill the cache now, the apply has to drop it.
js 'assert(JSON.parse(env.R).classes.length === 10, "ten legacy classes before the apply: " + env.R)' R="$(helper usage-cached | json_of)"
BEFORE_SUM="$(helper checksum)"
PAGE_BEFORE="$(curl -fsS "${BASE_URL}/lm-e2e-migration/")"
echo "${PAGE_BEFORE}" | grep -qE 'class="[^"]*sk-fade' || fail 'the seeded page should carry the legacy class before the migration'

# ---------------------------------------------------------------- apply
echo '--- wp lumia motion migrate --apply --format=json'
APPLY_OUT="$(wpx lumia motion migrate --apply --format=json 2>"${DIR}/out/migration-apply.stderr")"
echo "${APPLY_OUT}" | cut -c1-400
[ "$(echo "${APPLY_OUT}" | grep -c .)" = 1 ] || fail 'the JSON output should be a single line (the CSS regeneration must stay quiet)'
if grep -qi 'not regenerated' "${DIR}/out/migration-apply.stderr"; then fail 'the Bricks CSS files should have been regenerated'; fi
js '
	const r = JSON.parse(env.R);
	assert(r.applied === true, "applied");
	assert(r.renamed.length === 9 && !r.renamed.includes("sk-counter"), "nine renames, not sk-counter: " + JSON.stringify(r.renamed));
	assert(JSON.stringify(r.conflicts) === JSON.stringify(["sk-counter"]), "sk-counter is the one conflict, got " + JSON.stringify(r.conflicts));
	assert(r.elements >= 2, "elements that use a renamed class: " + r.elements);
	const sel = r.selectors.join("\n"), kept = r.compat.join("\n");
	assert(/element mgtext3: selector of a former class name that stops matching/.test(sel), "the selector of a renamed class is still listed after the apply");
	assert(!/mgtext6/.test(sel) && /element mgtext6: legacy name that keeps working/.test(kept), "the selector of sk-counter (a class that stays) keeps matching: it moves to the kept list");
' R="${APPLY_OUT}"
js '
	const c = JSON.parse(env.R).classes;
	assert(JSON.stringify(c) === JSON.stringify(["sk-counter"]), "the cached usage should be dropped by the apply, got " + env.R);
' R="$(helper usage-cached | json_of)"

AFTER_APPLY="$(helper snapshot)"
js '
	const before = JSON.parse(env.BEFORE);
	const after = JSON.parse(env.AFTER);
	const byId = (s) => Object.fromEntries(s.classes.map((c) => [c.id, c]));
	const b = byId(before), a = byId(after);
	assert(Object.keys(a).length === Object.keys(b).length, "the number of classes changed");

	const motion = after.categories.filter((c) => /^motion$/i.test(c.name));
	assert(motion.length === 1, "exactly one Motion category expected, got " + motion.length);
	const motionId = motion[0].id;
	assert(after.categories.length === before.categories.length + 1, "only the Motion category is added");

	const expected = {
		"sk-fade": "lm-fade", "sk-reveal": "lm-slide-up", "sk-entry-blur": "lm-blur-up", "sk-scale-in": "lm-zoom",
		"sk-scale-in-blur": "lm-zoom-blur", "sk-text-lines": "lm-lines", "sk-text-highlight-words": "lm-words",
		"sk-text-highlight-chars": "lm-letters", "sk-hover-underline": "lm-underline",
	};
	for (const [id, was] of Object.entries(b)) {
		const now = a[id];
		assert(now, "class " + was.name + " (" + id + ") disappeared");
		if (expected[was.name]) {
			assert(now.name === expected[was.name], was.name + " should be " + expected[was.name] + ", is " + now.name);
			assert(now.category === motionId, now.name + " should be in the Motion category, is in " + now.category);
		} else {
			assert(JSON.stringify(now) === JSON.stringify(was), was.name + " (" + id + ") should be untouched: " + JSON.stringify(now));
		}
	}
	// The conflict: the legacy class stays as it is, and so does the class made by hand.
	assert(Object.values(a).some((c) => c.name === "sk-counter"), "sk-counter should remain");
	const hand = Object.values(a).find((c) => c.name === "lm-counter");
	assert(hand && hand.id === env.COUNTER_ID && hand.settings._lmDuration === 2 && !hand.category, "the class made by hand must be untouched");

	// The class own CSS: renamed selector, longer name and variables left alone.
	const lines = Object.values(a).find((c) => c.name === "lm-lines").settings._cssCustom;
	assert(lines === ".lm-lines .line { overflow: hidden; }\n.sk-text-lines-extra { color: red; }", "CSS of lm-lines: " + lines);
	const underline = Object.values(a).find((c) => c.name === "lm-underline").settings["_cssCustom:tablet_portrait"];
	assert(underline === ".lm-underline { --sk-hover-underline-gap: 4px; }", "CSS of lm-underline: " + underline);

	assert(JSON.stringify(after.locked) === JSON.stringify(before.locked), "the locked list must not change");
	assert(after.timestamp !== before.timestamp, "the sync timestamp should move so the builder notices");
' BEFORE="${BEFORE_APPLY}" AFTER="${AFTER_APPLY}" COUNTER_ID="${COUNTER_ID}"
[ "$(helper checksum | cut -d' ' -f2)" = "$(echo "${BEFORE_SUM}" | cut -d' ' -f2)" ] || fail 'the apply touched the Bricks post meta (page content)'
echo 'ok apply: same IDs, Motion category, own CSS rewritten, conflict kept, content untouched'

# The site renders the new names through the same IDs.
PAGE_AFTER="$(curl -fsS "${BASE_URL}/lm-e2e-migration/")"
echo "${PAGE_AFTER}" | grep -q 'lm-fade' || fail 'the page should carry lm-fade after the migration'
if echo "${PAGE_AFTER}" | grep -qE 'class="[^"]*sk-fade'; then fail 'the page still carries the legacy class sk-fade'; fi
echo 'ok the page renders lm-fade instead of sk-fade'

# ---------------------------------------------------------------- twice: nothing more happens
APPLIED_SUM="$(helper checksum)"
SECOND_JSON="$(wpx lumia motion migrate --apply --format=json | json_of)"
js '
	const r = JSON.parse(env.R);
	assert(r.applied === true, "applied");
	assert(r.renamed.length === 0, "the second run renamed " + JSON.stringify(r.renamed));
	assert(JSON.stringify(r.conflicts) === JSON.stringify(["sk-counter"]), "the same single conflict, got " + JSON.stringify(r.conflicts));
' R="${SECOND_JSON}"
[ "$(helper checksum)" = "${APPLIED_SUM}" ] || fail 'the second --apply wrote something'
echo '--- wp lumia motion migrate --apply (second run, text)'
SECOND_TEXT="$(wpx lumia motion migrate --apply 2>&1)"
echo "${SECOND_TEXT}" | sed -n '1,12p'
echo "${SECOND_TEXT}" | grep -q '0 class(es) renamed' || fail 'the second run should rename nothing'
echo "${SECOND_TEXT}" | grep -q 'sk-counter is left as it is' || fail 'the conflict on sk-counter should be reported as a warning'
echo "${SECOND_TEXT}" | grep -q 'Deactivate (or delete) the "SK Motion" snippet' || fail 'the reminder about the snippet is missing'
echo "${SECOND_TEXT}" | grep -q 'Purge the page cache' || fail 'the reminder to purge the page cache is missing'
echo "${SECOND_TEXT}" | grep -q 'selector(s) stopped matching' || fail 'the apply should say which selectors stopped matching'
[ "$(helper checksum)" = "${APPLIED_SUM}" ] || fail 'the second --apply (text) wrote something'
echo 'ok a second --apply renames nothing and writes nothing'

# ---------------------------------------------------------------- classes: creates only what is missing
echo '--- wp lumia motion classes'
wpx lumia motion classes
[ "$(helper checksum)" = "${APPLIED_SUM}" ] || fail 'classes created something although every class exists'

# Remove one class altogether (a site that never had it): exactly that one comes back.
helper remove-class lm-blur-up
BEFORE_CREATE="$(helper snapshot)"
CREATED_JSON="$(wpx lumia motion classes --format=json)"
AFTER_CREATE="$(helper snapshot)"
js '
	const rows = JSON.parse(env.ROWS);
	const created = rows.filter((r) => r.state === "created").map((r) => r.class);
	assert(JSON.stringify(created) === JSON.stringify(["lm-blur-up"]), "only lm-blur-up should be created, got " + JSON.stringify(created));
	const b = JSON.parse(env.BEFORE), a = JSON.parse(env.AFTER);
	const made = a.classes.find((c) => c.name === "lm-blur-up");
	const motionId = a.categories.find((c) => /^motion$/i.test(c.name)).id;
	assert(made && /^[a-z]{6}$/.test(made.id), "a six-letter ID");
	assert(made.category === motionId, "in the Motion category");
	assert(Array.isArray(made.settings) ? made.settings.length === 0 : Object.keys(made.settings).length === 0, "empty settings");
	assert(a.locked.includes(made.id), "locked");
	assert(a.classes.length === b.classes.length + 1, "one class added");
	assert(a.categories.length === b.categories.length, "no category added");
' ROWS="${CREATED_JSON}" BEFORE="${BEFORE_CREATE}" AFTER="${AFTER_CREATE}"
CREATED_SUM="$(helper checksum)"
wpx lumia motion classes >/dev/null
[ "$(helper checksum)" = "${CREATED_SUM}" ] || fail 'a second classes run wrote something'
echo 'ok classes creates exactly the missing class (locked, Motion category) once'

# ---------------------------------------------------------------- turning the module on creates the missing classes
helper remove-class lm-underline
helper on-activate
js '
	const a = JSON.parse(env.AFTER);
	const made = a.classes.filter((c) => c.name === "lm-underline");
	assert(made.length === 1, "on_activate should create lm-underline once, got " + made.length);
	assert(a.locked.includes(made[0].id), "locked");
	assert(made[0].category === a.categories.find((c) => /^motion$/i.test(c.name)).id, "in the Motion category");
' AFTER="$(helper snapshot)"

# Not on a Bricks site: nothing at all, even with every class missing.
helper remove-class lm-underline
NOBRICKS_SUM="$(helper checksum)"
[ "$(helper without-bricks | tail -n 1)" = "[]" ] || fail 'without Bricks, create_missing() should return an empty list'
[ "$(helper checksum)" = "${NOBRICKS_SUM}" ] || fail 'the module wrote something although Bricks is not the active theme'
echo 'ok on_activate creates the missing classes, and nothing without Bricks'

# ---------------------------------------------------------------- the usage report after the migration
AFTER_JSON="$(wpx lumia motion migrate --format=json | json_of)"
js '
	const r = JSON.parse(env.R);
	assert(JSON.stringify(r.renamed) === "[]", "nothing left to rename");
	assert(JSON.stringify(r.conflicts) === JSON.stringify(["sk-counter"]), "the conflict is still reported");
' R="${AFTER_JSON}"
echo 'ok report after the migration'

# The trap restores the database, then checks it and runs the animation scenario.
exit 0
