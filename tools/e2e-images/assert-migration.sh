#!/usr/bin/env bash
# shellcheck disable=SC2016 # PHP code for `wp eval`, in single quotes on purpose.
#
# End-to-end check of `wp lumia images migrate` (spec 5, 9.1, 9.3, 9.9) on its own stack
# (nginx-mig, http://localhost:8102: the nginx stack, template conf with the AVIF rule, FPM
# image, cron container). Bench only, never shipped.
#
#   tools/e2e-images/assert-migration.sh [--down]
#
# 1. stack up, plugin installed, delivery self-test run (siblings may be kept), legacy media
#    seeded (seed-legacy.php);
# 2. the cron container (wordpress:cli, Imagick without codecs) refuses, with the message;
# 3. --dry-run writes nothing (database and uploads fingerprints identical);
# 4. --limit=1 killed (kill -9) once its journal reaches "files_written": every URL still
#    answers, the item resumes;
# 5. full run, assertions (assert-migration.php final), crawl of the site (crawl-check.sh);
# 6. a second run processes nothing; permanent deletion takes the legacy files along.
#
# Exit code: 0 success, 1 failure. --down stops the stack and deletes its data at the end.

set -euo pipefail

E2E_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RUN="${E2E_DIR}/run.sh"
STACK=nginx-mig
PHP_CONTAINER="lumia-img-${STACK}-wp-nginx-1"
OUT="${E2E_DIR}/out"
DOWN=0
[ "${1:-}" = --down ] && DOWN=1

FAILURES=0
pass() { echo "  ok   $*"; }
fail() {
	echo "  FAIL $*"
	FAILURES=$((FAILURES + 1))
}

wpx() { "${RUN}" wp "${STACK}" "$@"; }

journal_step() {
	wpx eval 'global $wpdb; $v = $wpdb->get_var( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = \"_lumia_migration\" LIMIT 1" ); $j = maybe_unserialize( (string) $v ); echo is_array( $j ) ? $j["step"] : "";' 2>/dev/null | tr -d '\r' || true
}

echo "== setup (${STACK})"
"${RUN}" up "${STACK}" >/dev/null
"${RUN}" fixtures "${STACK}" >/dev/null
"${RUN}" install-lumia "${STACK}" >/dev/null 2>&1
# Module on (as on the production sites), then the delivery self-test: siblings are kept only
# when AVIF delivery is proven.
wpx eval '$s = get_option( "lumia_settings", [] ); $s["modules"]["image_optimizer"] = true; update_option( "lumia_settings", $s );' >/dev/null
wpx eval '
	$m = \Lumia\Tools\Core\Plugin::instance()->modules->get_active_instances()["image_optimizer"] ?? null;
	if ( ! $m ) { WP_CLI::error( "Image Optimizer module not active" ); }
	$r = $m->get_delivery_probe()->run();
	echo "delivery: " . $r["mode"] . " (" . $r["reason"] . ")\n";' | tr -d '\r'
"${RUN}" assert "${STACK}" "${E2E_DIR}/seed-legacy.php"

echo "== the cron container refuses (spec 9.1)"
rc=0
out="$("${RUN}" wp-cron "${STACK}" lumia images migrate 2>&1)" || rc=$?
expected='This command needs Imagick with AVIF, JPEG and PNG support in this PHP process. On the Dokploy template, run it in the wordpress container: docker exec -u www-data <project>-wordpress-1 php /tmp/wp-cli.phar lumia images migrate'
if [ "${rc}" -ne 0 ] && grep -qF "${expected}" <<<"${out}"; then pass "cron container: exit ${rc}, refusal message"; else fail "cron container: exit ${rc}, output: ${out}"; fi

echo "== --dry-run writes nothing"
"${RUN}" assert "${STACK}" "${E2E_DIR}/assert-migration.php" snapshot mig-before >/dev/null
rc=0
out="$(wpx lumia images migrate --dry-run 2>&1)" || rc=$?
echo "${out}" | sed 's/^/    /'
"${RUN}" assert "${STACK}" "${E2E_DIR}/assert-migration.php" snapshot mig-after >/dev/null
if [ "${rc}" -eq 0 ] && grep -q 'Would process: 8' <<<"${out}"; then pass "dry run: 8 items planned"; else fail "dry run: exit ${rc}"; fi
if cmp -s "${OUT}/mig-before.json" "${OUT}/mig-after.json"; then pass "dry run: database and uploads unchanged"; else fail "dry run changed something: $(cat "${OUT}/mig-before.json") vs $(cat "${OUT}/mig-after.json")"; fi

echo "== --limit=1 killed at files_written, then resumed"
rm -f "${OUT}/mig-interrupted.json"
# Holds the process right after the step is recorded, so that the kill lands there.
cat >"${OUT}/mig-pause.php" <<'PHP'
<?php
WP_CLI::add_wp_hook(
	'lumia_image_optimizer_migration_step',
	static function ( $id, $step ) {
		if ( 'files_written' === $step ) {
			sleep( 300 );
		}
	},
	10,
	2
);
PHP
chmod 644 "${OUT}/mig-pause.php"
wpx --require=/bench/out/mig-pause.php lumia images migrate --limit=1 >"${OUT}/mig-killed.log" 2>&1 &
bg=$!
step=''
for _ in $(seq 1 120); do
	step="$(journal_step)"
	[ "${step}" = files_written ] && break
	kill -0 "${bg}" 2>/dev/null || break # the run ended (or never started)
	sleep 1
done
if [ "${step}" = files_written ]; then
	docker exec "${PHP_CONTAINER}" pkill -9 -f 'mig-pause.php' || true
	pass "journal at files_written, process killed (kill -9)"
else
	fail "journal never reached files_written (last: '${step}')"
	docker exec "${PHP_CONTAINER}" pkill -9 -f 'mig-pause.php' || true
fi
wait "${bg}" 2>/dev/null || true
sed 's/^/    /' "${OUT}/mig-killed.log"
"${RUN}" assert "${STACK}" "${E2E_DIR}/assert-migration.php" interrupted || FAILURES=$((FAILURES + 1))

echo "== full run"
rc=0
out="$(wpx lumia images migrate 2>&1)" || rc=$?
echo "${out}" | sed 's/^/    /'
if [ "${rc}" -eq 0 ] && grep -q 'resuming after step "files_written"' <<<"${out}" && grep -q 'Processed: 8' <<<"${out}"; then pass "8 items migrated, the interrupted one resumed"; else fail "full run: exit ${rc}"; fi
"${RUN}" assert "${STACK}" "${E2E_DIR}/assert-migration.php" final || FAILURES=$((FAILURES + 1))

echo "== crawl"
if "${E2E_DIR}/crawl-check.sh" "${STACK}" | sed 's/^/    /'; then pass "every image of the site answers (Chrome and */*)"; else fail "crawl-check"; fi

echo "== second run"
rc=0
out="$(wpx lumia images migrate 2>&1)" || rc=$?
if [ "${rc}" -eq 0 ] && grep -q 'Processed: 0' <<<"${out}"; then pass "second run: 0 processed"; else fail "second run: exit ${rc}: ${out}"; fi

echo "== permanent deletion"
"${RUN}" assert "${STACK}" "${E2E_DIR}/assert-migration.php" delete || FAILURES=$((FAILURES + 1))

if [ "${DOWN}" = 1 ]; then
	"${RUN}" down "${STACK}" >/dev/null 2>&1 || true
fi

if [ "${FAILURES}" -gt 0 ]; then
	echo "assert-migration: ${FAILURES} failure(s)"
	exit 1
fi
echo "assert-migration: all checks passed"
