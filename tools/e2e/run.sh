#!/usr/bin/env bash
#
# Docker bench for the Lumia rename end-to-end checks. See README.md.
# Needs only bash, docker (with compose), git, rsync and zip. Exit code: 0 on
# success, non-zero (usually 1) on failure, 2 on bad usage, 130 on SIGINT, 143 on SIGTERM.

set -euo pipefail

E2E_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "${E2E_DIR}/../.." && pwd)"
OUT_DIR="${E2E_DIR}/out"

SKMT_COMMIT="8d4cd85"
SKMT_SLUG="studio-kyne-mini-tools"
SKMT_NAME="Studio Kyne Mini Tools"
SITE_URL="http://localhost:8089"
WP_MIN_VERSION="6.9"
ENCRYPTION_KEY="e2e-fixed-key"

# Same exclude list as the release build (tools/build/build-zip.sh), from the working tree.
ZIP_EXCLUDES="${REPO_DIR}/tools/build/zip-excludes.txt"

die() {
	echo "error: $*" >&2
	exit 1
}

# Temporary files of this run, all under one directory created by the main shell:
# removed on exit even when a command substitution ($(cmd_install_lumia)) dies,
# since a subshell does not run the parent's EXIT trap. Commands that write
# snippets set CLEANUP_SNIPPETS=1 rather than installing their own EXIT trap.
TMP_ROOT="$(mktemp -d)"
CLEANUP_SNIPPETS=0

cleanup() {
	rm -rf "${TMP_ROOT}"
	if [ "${CLEANUP_SNIPPETS}" -eq 1 ]; then
		remove_snippets
	fi
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

dc() {
	docker compose -f "${E2E_DIR}/docker-compose.yml" "$@"
}

# wp-cli in the cli container, as www-data, in the WordPress volume.
wp() {
	dc --progress quiet run --rm -T cli wp "$@"
}

# wp-cli with E2E_VALUE passed into the container (wp eval takes no positional argument).
wp_env() {
	dc --progress quiet run --rm -T -e "E2E_VALUE=${E2E_VALUE:-}" cli wp "$@"
}

prepare_out_dir() {
	mkdir -p "${OUT_DIR}"
	# The cli container runs as uid 33 and writes here (zips are read, captures written).
	chmod 777 "${OUT_DIR}"
}

# build_zip <source-dir> <plugin-folder> <zip-path>: zip of <source-dir> with the
# release excludes, rooted at <plugin-folder>/ like a release asset.
build_zip() {
	local src="$1" folder="$2" zip_path="$3" staging entries

	staging="$(mktemp -d "${TMP_ROOT}/staging.XXXXXX")"
	mkdir -p "${staging}/${folder}"
	rsync -a --exclude-from="${ZIP_EXCLUDES}" "${src}/" "${staging}/${folder}/"
	rm -f "${zip_path}"
	(cd "${staging}" && zip -qr "${zip_path}" "${folder}")
	rm -rf "${staging}"

	# The e2e-auth mu-plugin logs in anyone who sends a header: it must never ship.
	# Listing captured first: "unzip | grep -q" fails with SIGPIPE under pipefail
	# when grep exits before unzip has written everything.
	entries="$(unzip -Z1 "${zip_path}")" || die "unreadable zip ${zip_path}"
	if grep -Eq '(^|/)(tools|e2e-auth\.php)(/|$)' <<<"${entries}"; then
		rm -f "${zip_path}"
		die "tools/ or e2e-auth.php ended up in ${zip_path}"
	fi
}

# Name of the main plugin file of the working tree: the root *.php with a
# "Plugin Name:" header, without .php. Also the plugin folder name.
working_tree_plugin() {
	local file found=""

	for file in "${REPO_DIR}"/*.php; do
		if grep -Eq '^[[:space:]]*\*?[[:space:]]*Plugin Name:' "${file}"; then
			[ -z "${found}" ] || die "several root files carry a Plugin Name header"
			found="$(basename "${file}" .php)"
		fi
	done

	[ -n "${found}" ] || die "no root *.php with a Plugin Name header in ${REPO_DIR}"
	echo "${found}"
}

# Folder of the installed SKMT, found by its plugin name (seed-skmt --folder may install it
# elsewhere than studio-kyne-mini-tools/); empty when SKMT is not installed.
skmt_folder() {
	# CSV quotes the title; a folder name never holds a comma.
	wp plugin list --fields=name,title --format=csv | tr -d '\r' \
		| grep -F ",\"${SKMT_NAME}\"" | cut -d, -f1 | head -n 1 || true
}

cmd_up() {
	prepare_out_dir
	dc up -d db wordpress

	echo "Waiting for WordPress files..."
	local tries=0
	until wp config path >/dev/null 2>&1; do
		tries=$((tries + 1))
		[ "${tries}" -lt 60 ] || die "wp-config.php never appeared"
		sleep 2
	done

	tries=0
	until wp db query 'SELECT 1' >/dev/null 2>&1; do
		tries=$((tries + 1))
		[ "${tries}" -lt 30 ] || die "database not reachable"
		sleep 2
	done

	if ! wp core is-installed >/dev/null 2>&1; then
		wp core install --url="${SITE_URL}" --title="SKMT bench" \
			--admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email
	fi

	wp language core install fr_FR --activate
	# Pretty permalinks, as on a real site (the custom login URL is a path).
	wp rewrite structure '/%postname%/' --hard >/dev/null

	local version
	version="$(wp core version | tr -d '\r')"
	if [ "$(printf '%s\n%s\n' "${WP_MIN_VERSION}" "${version}" | sort -V | head -n 1)" != "${WP_MIN_VERSION}" ]; then
		die "WordPress ${version} is older than ${WP_MIN_VERSION}"
	fi

	tries=0
	until curl -fsS -o /dev/null "${SITE_URL}/wp-login.php"; do
		tries=$((tries + 1))
		[ "${tries}" -lt 30 ] || die "${SITE_URL} does not answer"
		sleep 2
	done

	echo "Bench ready: ${SITE_URL} (admin / admin), WordPress ${version}, fr_FR."
}

cmd_down() {
	dc down -v --remove-orphans
}

cmd_seed_skmt() {
	local minimal=0 encryption_key=0 folder="${SKMT_SLUG}" arg

	for arg in "$@"; do
		case "${arg}" in
			--minimal) minimal=1 ;;
			--encryption-key) encryption_key=1 ;;
			# SKMT installed under another folder, as a GitHub "Download ZIP" leaves it.
			--folder=*)
				folder="${arg#--folder=}"
				[[ "${folder}" =~ ^[a-z0-9][a-z0-9-]*$ ]] || { echo "seed-skmt: bad folder name ${folder}" >&2; exit 2; }
				;;
			*) echo "seed-skmt: unknown option ${arg}" >&2; exit 2 ;;
		esac
	done

	# SKMT comes from the repository history, which a shallow clone does not have.
	if ! git -C "${REPO_DIR}" cat-file -e "${SKMT_COMMIT}^{commit}" 2>/dev/null; then
		if [ "$(git -C "${REPO_DIR}" rev-parse --is-shallow-repository 2>/dev/null)" = "true" ]; then
			die "commit ${SKMT_COMMIT} (SKMT before the rename) is missing from this shallow clone: run 'git fetch --unshallow' first"
		fi
		die "commit ${SKMT_COMMIT} (SKMT before the rename) not found in ${REPO_DIR}: run 'git fetch origin' first"
	fi

	prepare_out_dir
	if [ -n "$(skmt_folder)" ]; then
		die "SKMT is already installed: run 'down' then 'up' for a fresh bench"
	fi

	local zip_name="${folder}-${SKMT_COMMIT}.zip" extracted
	extracted="$(mktemp -d "${TMP_ROOT}/skmt.XXXXXX")"
	git -C "${REPO_DIR}" archive "${SKMT_COMMIT}" | tar -x -C "${extracted}"
	build_zip "${extracted}" "${folder}" "${OUT_DIR}/${zip_name}"
	rm -rf "${extracted}"
	chmod 644 "${OUT_DIR}/${zip_name}"

	if [ "${encryption_key}" -eq 1 ]; then
		# Before the secrets are encrypted: the key is part of the cipher.
		wp config set SKMT_ENCRYPTION_KEY "${ENCRYPTION_KEY}" --type=constant
	fi

	wp plugin install "/e2e/out/${zip_name}" --force --activate

	local flag=()
	[ "${minimal}" -eq 0 ] || flag=(minimal)

	wp --user=admin eval-file /e2e/seed-skmt.php modules ${flag[@]+"${flag[@]}"}
	if [ "${minimal}" -eq 0 ]; then
		wp --user=admin eval-file /e2e/seed-skmt.php settings
		wp --user=admin eval-file /e2e/seed-skmt.php content
	fi

	echo "SKMT seeded from ${SKMT_COMMIT} (minimal=${minimal}, encryption-key=${encryption_key}, folder=${folder})."
}

cmd_capture() {
	local out_name="${1:-}" slug="${2:-${SKMT_SLUG}}"
	[[ "${out_name}" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ ]] || { echo "usage: run.sh capture <output-dir> [admin-page-slug]" >&2; exit 2; }

	prepare_out_dir
	# Files written by the cli container (uid 33) may not be removable by the host user.
	# shellcheck disable=SC2016 # $1 is expanded by the container shell on purpose.
	dc --progress quiet run --rm -T cli sh -c 'rm -rf "/e2e/out/$1"' sh "${out_name}"

	dc --progress quiet run --rm -T cli php /e2e/capture.php "${out_name}" "${slug}"
}

cmd_install_lumia() {
	prepare_out_dir

	local folder zip_name
	folder="$(working_tree_plugin)"
	zip_name="${folder}-worktree.zip"

	if [ -n "${LUMIA_ZIP:-}" ]; then
		# A prebuilt zip (tools/build/build-zip.sh, a release asset), installed under the
		# same name so that assert-reinstall reinstalls it too.
		[ -f "${LUMIA_ZIP}" ] || die "LUMIA_ZIP: no such file ${LUMIA_ZIP}"
		local entries
		entries="$(unzip -Z1 "${LUMIA_ZIP}")" || die "LUMIA_ZIP: unreadable zip ${LUMIA_ZIP}"
		if grep -qv "^${folder}/" <<<"${entries}"; then
			die "LUMIA_ZIP: entries outside ${folder}/ in ${LUMIA_ZIP}"
		fi
		cp "${LUMIA_ZIP}" "${OUT_DIR}/${zip_name}"
	else
		build_zip "${REPO_DIR}" "${folder}" "${OUT_DIR}/${zip_name}"
	fi
	chmod 644 "${OUT_DIR}/${zip_name}"

	# First install over a seeded SKMT: record what SKMT holds, so that assert-migration
	# can compare the migrated data with it (row counts, ids, files, cron timestamp).
	if [ "${folder}" != "${SKMT_SLUG}" ] && [ -n "$(skmt_folder)" ] \
		&& ! wp plugin is-installed "${folder}" >/dev/null 2>&1; then
		wp --user=admin eval-file /e2e/assert-migration.php snapshot
	fi

	wp plugin install "/e2e/out/${zip_name}" --force --activate
}

# write_snippet <name>: stdin becomes wp-content/e2e-snippets/<name>.php, loaded by the
# e2e-snippets mu-plugin like a FluentSnippets or theme snippet.
write_snippet() {
	# shellcheck disable=SC2016 # $1 is expanded by the container shell on purpose.
	dc --progress quiet run --rm -T cli sh -c 'mkdir -p /var/www/html/wp-content/e2e-snippets && cat > "/var/www/html/wp-content/e2e-snippets/$1.php"' sh "$1"
}

remove_snippets() {
	dc --progress quiet run --rm -T cli sh -c 'rm -rf /var/www/html/wp-content/e2e-snippets' >/dev/null 2>&1 || true
}

# assert-compat: the SKMT compatibility layer, on a bench where the renamed plugin is
# installed. Legacy SKMT_* constants and skmt_* hooks must keep working, and the
# deprecation of the hooks must reach debug.log.
cmd_assert_compat() {
	local failures=0 folder code location log

	check() { # check <label> <status: 0 = ok>
		if [ "$2" -eq 0 ]; then
			echo "  ok   $1"
		else
			echo "  FAIL $1"
			failures=$((failures + 1))
		fi
	}

	folder="$(working_tree_plugin)"
	wp plugin is-active "${folder}" >/dev/null 2>&1 || die "${folder} is not active: run 'install-lumia' first"
	CLEANUP_SNIPPETS=1
	remove_snippets

	# Security on, with its defaults (custom login URL /connexion): wp-login.php answers 404.
	# shellcheck disable=SC2016 # PHP code: the single quotes are on purpose.
	wp --user=admin eval '$m = \Lumia\Tools\Core\Plugin::instance()->modules; $m->register_default_modules( false ); $m->activate( "security" );' >/dev/null
	wp rewrite flush --hard >/dev/null

	echo "In-process checks"
	wp --user=admin eval-file /e2e/assert-compat.php || failures=$((failures + 1))

	echo "SKMT_DISABLE_LOGIN_URL (wp-config style constant)"
	code="$(curl -s -o /dev/null -w '%{http_code}' "${SITE_URL}/wp-login.php")"
	check "baseline: wp-login.php answers 404 behind the custom login URL (got ${code})" "$([ "${code}" = 404 ] && echo 0 || echo 1)"
	write_snippet skmt-constant <<'EOF'
<?php
define( 'SKMT_DISABLE_LOGIN_URL', true );
EOF
	code="$(curl -s -o /dev/null -w '%{http_code}' "${SITE_URL}/wp-login.php")"
	check "wp-login.php answers 200 with SKMT_DISABLE_LOGIN_URL (got ${code})" "$([ "${code}" = 200 ] && echo 0 || echo 1)"
	remove_snippets

	echo "skmt_custom_login_redirect (theme or snippet filter)"
	write_snippet skmt-filter <<'EOF'
<?php
add_filter(
	'skmt_custom_login_redirect',
	static function () {
		return home_url( '/e2e-skmt-redirect/' );
	}
);
EOF
	dc --progress quiet run --rm -T cli sh -c 'rm -f /var/www/html/wp-content/debug.log'
	location="$(curl -s -o /dev/null -D - -H 'X-E2E-User: admin' "${SITE_URL}/connexion/" | tr -d '\r' | sed -n 's/^[Ll]ocation: //p')"
	check "a logged-in visit of /connexion/ is redirected by the legacy filter (got '${location}')" "$([ "${location}" = "${SITE_URL}/e2e-skmt-redirect/" ] && echo 0 || echo 1)"
	log="$(dc --progress quiet run --rm -T cli sh -c 'cat /var/www/html/wp-content/debug.log 2>/dev/null' || true)"
	check "debug.log carries the deprecation of skmt_custom_login_redirect" "$(grep -q 'skmt_custom_login_redirect' <<<"${log}" && echo 0 || echo 1)"
	check "the notice names the replacement lumia_custom_login_redirect" "$(grep -q 'lumia_custom_login_redirect' <<<"${log}" && echo 0 || echo 1)"
	remove_snippets

	if [ "${failures}" -gt 0 ]; then
		die "assert-compat: ${failures} check(s) failed"
	fi
	echo "assert-compat: all checks passed."
}

# check <label> <status: 0 = ok>, counting failures in the caller's "failures".
check() {
	if [ "$2" -eq 0 ]; then
		echo "  ok   $1"
	else
		echo "  FAIL $1"
		failures=$((failures + 1))
	fi
}

http_code() { # http_code <path> [curl args...]
	local path="$1"
	shift
	curl -s -o /dev/null -w '%{http_code}' "$@" "${SITE_URL}${path}"
}

# login_checks: wp-login.php stays blocked and the custom login URL answers. Uses "failures".
login_checks() {
	local path code
	path="$(wp eval-file /e2e/assert-migration.php login-path | tr -d '\r')"
	if [ -z "${path}" ]; then
		check "a custom login URL is configured" 1
		return
	fi
	code="$(http_code /wp-login.php)"
	check "wp-login.php is blocked (got ${code})" "$([ "${code}" = 404 ] && echo 0 || echo 1)"
	code="$(http_code "${path%/}/")"
	check "${path%/}/ answers 200 (got ${code})" "$([ "${code}" = 200 ] && echo 0 || echo 1)"
}

# fresh_decrypt_checks: the secrets decrypt in a new process, after an object cache flush.
fresh_decrypt_checks() {
	local out
	wp eval 'wp_cache_flush();' >/dev/null
	out="$(wp eval-file /e2e/assert-migration.php decrypt | tr -d '\r')"
	check "fresh request: SMTP password and Brevo key decrypt to what SKMT last held (${out})" \
		"$([ "${out}" = match ] && echo 0 || echo 1)"
}

# option_state <option>: prints "some" if the option exists, "none" otherwise.
option_state() {
	if wp option get "$1" >/dev/null 2>&1; then
		echo some
	else
		echo none
	fi
}

# assert-migration: after seed-skmt then install-lumia (which activates, hence migrates).
cmd_assert_migration() {
	local failures=0 folder notices

	folder="$(working_tree_plugin)"
	wp plugin is-active "${folder}" >/dev/null 2>&1 || die "${folder} is not active: run 'install-lumia' first"
	[ -f "${OUT_DIR}/skmt-snapshot.json" ] || die "no SKMT snapshot: run 'install-lumia' on a seeded bench"

	echo "In-process checks"
	wp --user=admin eval-file /e2e/assert-migration.php migration || failures=$((failures + 1))

	echo "HTTP"
	login_checks

	if [ "$(option_state skmt_smtp_password)" = some ]; then
		fresh_decrypt_checks
		echo "Legacy key"
		wp --user=admin eval-file /e2e/assert-migration.php legacy-key || failures=$((failures + 1))
	fi

	echo "Success notice"
	curl -s -o /dev/null -H 'X-E2E-User: admin' "${SITE_URL}/wp-admin/index.php"
	notices="$(wp eval 'echo wp_json_encode( [ isset( get_user_meta( 1, "lumia_notices", true )["lumia_migrated_from_skmt"] ), get_option( "lumia_migration_notice" ) ] );' | tr -d '\r')"
	check "first admin page: persistent success notice added, pending flag consumed (${notices})" "$([ "${notices}" = '[true,false]' ] && echo 0 || echo 1)"

	echo "Kept original"
	wp --user=admin eval-file /e2e/assert-migration.php kept-original || failures=$((failures + 1))

	wp --user=admin eval-file /e2e/assert-migration.php lumia-snapshot >/dev/null

	[ "${failures}" -eq 0 ] || die "assert-migration: ${failures} check(s) failed"
	echo "assert-migration: all checks passed."
}

# assert-after-uninstall: SKMT's uninstall.php must find nothing that belongs to Lumia.
cmd_assert_after_uninstall() {
	local failures=0 left skmt

	[ -f "${OUT_DIR}/lumia-snapshot.json" ] || die "no Lumia snapshot: run 'assert-migration' first"
	skmt="$(skmt_folder)"
	[ -n "${skmt}" ] || die "SKMT is not installed"

	# Through WordPress (uninstall_plugin()), so that SKMT's uninstall.php runs; then the
	# files are deleted. `wp plugin delete` would only delete the files.
	wp plugin uninstall "${skmt}"

	echo "SKMT uninstalled"
	check "SKMT files deleted" "$(wp plugin is-installed "${skmt}" >/dev/null 2>&1 && echo 1 || echo 0)"
	left="$(option_state skmt_settings)"
	check "uninstall.php ran (skmt_settings: ${left})" "$([ "${left}" = none ] && echo 0 || echo 1)"

	echo "In-process checks"
	wp --user=admin eval-file /e2e/assert-migration.php lumia-compare || failures=$((failures + 1))

	echo "HTTP"
	login_checks
	if [ "$(option_state lumia_smtp_password)" = some ]; then
		fresh_decrypt_checks
	fi

	[ "${failures}" -eq 0 ] || die "assert-after-uninstall: ${failures} check(s) failed"
	echo "assert-after-uninstall: all checks passed."
}

# reactivate-lumia: a deactivation then reactivation does not replay the migration and
# keeps a setting changed since.
cmd_reactivate_lumia() {
	local failures=0 folder before after events
	# shellcheck disable=SC2016 # PHP code: the single quotes are on purpose.
	local read_attempts='$s = get_option( "lumia_module_security" ); echo (int) $s["authentication"]["rate_limit_attempts"];'
	# shellcheck disable=SC2016
	local write_attempts='$s = get_option( "lumia_module_security" ); $s["authentication"]["rate_limit_attempts"] = (int) getenv( "E2E_VALUE" ); update_option( "lumia_module_security", $s );'

	folder="$(working_tree_plugin)"
	[ -f "${OUT_DIR}/lumia-snapshot.json" ] || die "no Lumia snapshot: run 'assert-migration' first"
	wp plugin is-active "${folder}" >/dev/null 2>&1 || die "${folder} is not active"

	before="$(wp eval "${read_attempts}" | tr -d '\r')"
	E2E_VALUE=9 wp_env --user=admin eval "${write_attempts}"

	wp plugin deactivate "${folder}"
	wp plugin activate "${folder}"

	echo "Reactivation"
	after="$(wp eval "${read_attempts}" | tr -d '\r')"
	check "a setting changed after the migration is kept (rate_limit_attempts ${before} -> 9, now ${after})" "$([ "${after}" = 9 ] && echo 0 || echo 1)"
	# shellcheck disable=SC2016 # PHP code: the single quotes are on purpose.
	events="$(wp eval '$n = 0; foreach ( (array) _get_cron_array() as $hooks ) { $n += count( (array) ( $hooks["lumia_image_optimizer_cron"] ?? [] ) ); } echo $n;' | tr -d '\r')"
	check "the deactivation unscheduled lumia_image_optimizer_cron, arguments included (${events} left)" "$([ "${events}" = 0 ] && echo 0 || echo 1)"
	wp --user=admin eval-file /e2e/assert-migration.php lumia-compare lumia_module_security || failures=$((failures + 1))

	# Leave the bench as it was.
	E2E_VALUE="${before}" wp_env --user=admin eval "${write_attempts}"

	[ "${failures}" -eq 0 ] || die "reactivate-lumia: ${failures} check(s) failed"
	echo "reactivate-lumia: all checks passed."
}

# assert-partial: a migration that fails half-way (an SQL failure injected on one post meta
# key) leaves SKMT active and Lumia on hold; reactivating Lumia then resumes and completes it.
# Run on a freshly seeded bench without Lumia; ends like install-lumia + assert-migration.
cmd_assert_partial() {
	local failures=0 folder page out notice before after action first_id

	folder="$(working_tree_plugin)"
	if wp plugin is-installed "${folder}" >/dev/null 2>&1; then
		die "${folder} is already installed: start from a freshly seeded bench"
	fi
	CLEANUP_SNIPPETS=1
	remove_snippets
	write_snippet fail-post-meta <<'PHP'
<?php
add_filter(
	'query',
	static function ( $query ) {
		if ( 0 === strpos( ltrim( $query ), 'UPDATE' ) && false !== strpos( $query, "'_skmt_optimized_mime'" ) ) {
			return 'SELECT e2e_injected_failure FROM e2e_missing_table';
		}
		return $query;
	}
);
PHP

	if ! out="$(cmd_install_lumia 2>&1)"; then
		echo "${out}"
		die "install-lumia failed"
	fi
	echo "${out}"

	first_id="$(wp eval 'echo (int) json_decode( file_get_contents( "/e2e/out/skmt-snapshot.json" ), true )["optimized_post_ids"][0];' | tr -d '\r')"

	# Expected texts in the bench language (fr_FR), read through the plugin's .mo: the
	# English sources are translated on the pages and in the AJAX answers.
	local locale expected_notice expected_json expected_done
	locale="$(wp --user=admin eval-file /e2e/assert-migration.php l10n locale | tr -d '\r')"
	expected_notice="$(wp --user=admin eval-file /e2e/assert-migration.php l10n hold-notice post_meta | tr -d '\r')"
	expected_json="$(wp --user=admin eval-file /e2e/assert-migration.php l10n freeze-json | tr -d '\r')"
	expected_done="$(wp --user=admin eval-file /e2e/assert-migration.php l10n complete | tr -d '\r')"
	echo "Texts expected in ${locale}"
	check "the plugin's translation is loaded (${expected_done})" \
		"$([ "${locale}" = en_US ] || [ "${expected_done}" != 'Migration from Studio Kyne Mini Tools complete. You can delete the old plugin.' ] && echo 0 || echo 1)"

	echo "Failed migration"
	check "wp-cli warns about the failed step" "$(grep -q '^Warning: .*post_meta' <<<"${out}" && echo 0 || echo 1)"
	wp --user=admin eval-file /e2e/assert-migration.php hold post_meta || failures=$((failures + 1))
	login_checks
	page="$(curl -s -H 'X-E2E-User: admin' "${SITE_URL}/wp-admin/index.php")"
	# Rendered inline, not swallowed into SKMT's notification drawer: there, the markup
	# only exists JSON-encoded (id=\"...\"), never with plain quotes.
	notice="$(grep -o 'id="lumia-migration-notice".*' <<<"${page}" || true)"
	check "error notice rendered inline on the dashboard" "$([ -n "${notice}" ] && echo 0 || echo 1)"
	check "the notice names the failed step" "$(grep -q 'post_meta' <<<"${notice}" && echo 0 || echo 1)"
	# The whole message (step, warning not to delete SKMT yet, how to resume), translated.
	check "the notice warns not to delete SKMT yet (full message, ${locale})" "$(grep -qF "${expected_notice}" <<<"${notice}" && echo 0 || echo 1)"
	check "the notice shows the database error" "$(grep -q 'e2e_missing_table' <<<"${notice}" && echo 0 || echo 1)"
	page="$(http_code '/wp-admin/admin.php?page=lumia-tools' -H 'X-E2E-User: admin')"
	check "the Lumia admin page is not registered while on hold (got ${page})" "$([ "${page}" != 200 ] && echo 0 || echo 1)"

	echo "SKMT's image optimizer is frozen during the hold"
	# A valid SKMT nonce cannot be built from here (the bench creates a new session per
	# request): a bench-only override of the pluggable wp_verify_nonce() accepts any, so
	# that without the freeze, SKMT's handlers would really run.
	write_snippet accept-nonces <<'PHP'
<?php
if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( $nonce, $action = -1 ) {
		return 1;
	}
}
PHP
	before="$(wp eval-file /e2e/assert-migration.php freeze-state | tr -d '\r')"
	for action in bulk_scan bulk bulk_status media_optimize media_reoptimize media_convert media_regenerate media_restore; do
		page="$(curl -s -H 'X-E2E-User: admin' --data "action=skmt_image_optimizer_${action}&nonce=e2e&attachment_id=${first_id}&format=avif" "${SITE_URL}/wp-admin/admin-ajax.php")"
		check "skmt_image_optimizer_${action} refused with the JSON error of the freeze (${page:0:120})" \
			"$([ "${page}" = "${expected_json}" ] && echo 0 || echo 1)"
	done
	remove_snippets
	after="$(wp eval-file /e2e/assert-migration.php freeze-state | tr -d '\r')"
	check "no meta or file of the optimized images changed, no skmt_image_optimizer_cron scheduled (${after})" \
		"$([ "${after}" = "${before}" ] && grep -q '"cron":0' <<<"${after}" && echo 0 || echo 1)"

	echo "SKMT keeps working during the hold"
	wp --user=admin eval-file /e2e/assert-migration.php hold-writes || failures=$((failures + 1))

	remove_snippets
	echo "Resume: deactivate and reactivate ${folder}"
	wp plugin deactivate "${folder}"
	out="$(wp plugin activate "${folder}" 2>&1)"
	echo "${out}"
	check "wp-cli reports the completed migration" "$(grep -qF "${expected_done}" <<<"${out}" && echo 0 || echo 1)"

	[ "${failures}" -eq 0 ] || die "assert-partial: ${failures} check(s) failed before the resume"
	cmd_assert_migration
}

# assert-interrupted: a migration killed inside a step (here user_meta, after post_meta: a
# bench snippet exits the process on its UPDATE, as a fatal error or a PHP-FPM timeout
# would) leaves the step recorded without detail, Lumia inactive and SKMT active; the next
# activation resumes in refresh mode and completes. Run on a freshly seeded bench without
# Lumia; ends like install-lumia + assert-migration.
cmd_assert_interrupted() {
	local failures=0 folder out status

	folder="$(working_tree_plugin)"
	if wp plugin is-installed "${folder}" >/dev/null 2>&1; then
		die "${folder} is already installed: start from a freshly seeded bench"
	fi
	CLEANUP_SNIPPETS=1
	remove_snippets
	write_snippet kill-user-meta <<'PHP'
<?php
add_filter(
	'query',
	static function ( $query ) {
		if ( 0 === strpos( ltrim( $query ), 'UPDATE' ) && false !== strpos( $query, "'skmt_local_avatar'" ) ) {
			exit( 255 );
		}
		return $query;
	}
);
PHP

	status=0
	out="$(cmd_install_lumia 2>&1)" || status=$?
	echo "${out}"

	echo "Killed migration"
	check "the activation died (exit status ${status})" "$([ "${status}" -ne 0 ] && echo 0 || echo 1)"
	wp --user=admin eval-file /e2e/assert-migration.php interrupted user_meta || failures=$((failures + 1))

	remove_snippets
	# SKMT stays the live plugin until the retry (and nothing freezes it: Lumia is not
	# active). What it writes meanwhile must win: the retry runs in refresh mode.
	echo "SKMT keeps working until the retry"
	wp --user=admin eval-file /e2e/assert-migration.php hold-writes || failures=$((failures + 1))

	echo "Resume: activate ${folder} again"
	out="$(wp plugin activate "${folder}" 2>&1)" || failures=$((failures + 1))
	echo "${out}"
	check "wp-cli reports the completed migration" \
		"$(grep -qF "$(wp eval-file /e2e/assert-migration.php l10n complete | tr -d '\r')" <<<"${out}" && echo 0 || echo 1)"

	[ "${failures}" -eq 0 ] || die "assert-interrupted: ${failures} check(s) failed before the resume"
	cmd_assert_migration
}

# assert-reinstall: after a migration, SKMT removed with `wp plugin delete` (files only:
# its skmt_* options stay), then Lumia uninstalled and installed again. The marker is a
# tombstone: the reinstall must not migrate the old skmt_* settings again.
cmd_assert_reinstall() {
	local failures=0 folder skmt left

	folder="$(working_tree_plugin)"
	[ -f "${OUT_DIR}/lumia-snapshot.json" ] || die "no Lumia snapshot: run 'assert-migration' first"
	[ -f "${OUT_DIR}/${folder}-worktree.zip" ] || die "no Lumia zip: run 'install-lumia' first"
	skmt="$(skmt_folder)"
	[ -n "${skmt}" ] || die "SKMT is not installed"

	wp plugin delete "${skmt}"
	wp plugin uninstall "${folder}" --deactivate

	echo "Lumia uninstalled"
	left="$(option_state lumia_settings)"
	check "Lumia's uninstall.php ran (lumia_settings: ${left})" "$([ "${left}" = none ] && echo 0 || echo 1)"

	wp plugin install "/e2e/out/${folder}-worktree.zip" --activate
	wp --user=admin eval-file /e2e/assert-migration.php reinstall || failures=$((failures + 1))

	[ "${failures}" -eq 0 ] || die "assert-reinstall: ${failures} check(s) failed"
	echo "assert-reinstall: all checks passed."
}

usage() {
	cat <<'EOF'
Usage: tools/e2e/run.sh <command> [args]

  up                                   start the bench: WordPress 6.9+ in French, admin/admin on http://localhost:8089
  down                                 stop the bench and delete its data
  seed-skmt [--minimal] [--encryption-key] [--folder=<name>]
                                       install SKMT (commit 8d4cd85) and seed realistic data
  capture <output-dir> [page-slug]     write the admin text to out/<output-dir>/ (slug defaults to studio-kyne-mini-tools)
  install-lumia                        install and activate the plugin built from the working tree
                                       (LUMIA_ZIP=<path>: install that prebuilt zip instead)
  assert-compat                        check the SKMT compatibility layer (constants, hooks, tables) on an installed bench
  assert-migration                     check the SKMT -> Lumia migration (after seed-skmt then install-lumia)
  assert-after-uninstall               uninstall SKMT (its uninstall.php runs), then check the Lumia data is intact
  reactivate-lumia                     change a setting, deactivate and reactivate Lumia: no replay, setting kept
  assert-partial                       inject a failure in the migration, check the hold, then resume (seeded bench, no Lumia)
  assert-interrupted                   kill the activation inside a step, check the trace, then resume (seeded bench, no Lumia)
  assert-reinstall                     delete SKMT's files only, uninstall and reinstall Lumia: nothing migrated again
EOF
}

[ "$#" -gt 0 ] || { usage >&2; exit 2; }
command="$1"
shift

case "${command}" in
	up) cmd_up ;;
	down) cmd_down ;;
	seed-skmt) cmd_seed_skmt "$@" ;;
	capture) cmd_capture "$@" ;;
	install-lumia) cmd_install_lumia ;;
	assert-compat) cmd_assert_compat ;;
	assert-migration) cmd_assert_migration ;;
	assert-after-uninstall) cmd_assert_after_uninstall ;;
	reactivate-lumia) cmd_reactivate_lumia ;;
	assert-partial) cmd_assert_partial ;;
	assert-interrupted) cmd_assert_interrupted ;;
	assert-reinstall) cmd_assert_reinstall ;;
	-h | --help | help) usage ;;
	*)
		echo "unknown command: ${command}" >&2
		usage >&2
		exit 2
		;;
esac
