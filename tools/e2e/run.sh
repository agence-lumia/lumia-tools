#!/usr/bin/env bash
#
# Docker bench for the Lumia rename end-to-end checks. See README.md.
# Needs only bash, docker (with compose), git, rsync and zip. Exit code: 0 on
# success, 1 on any failure, 2 on bad usage.

set -euo pipefail

E2E_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "${E2E_DIR}/../.." && pwd)"
OUT_DIR="${E2E_DIR}/out"

SKMT_COMMIT="8d4cd85"
SKMT_SLUG="studio-kyne-mini-tools"
SITE_URL="http://localhost:8089"
WP_MIN_VERSION="6.9"
ENCRYPTION_KEY="e2e-fixed-key"

# Same list as the release workflows (.github/workflows/release-*.yml), anchored
# at the root: an unanchored 'vendor' would also drop assets/admin/js/vendor/.
# .superpowers is local working-tree noise that the workflows never see.
ZIP_EXCLUDES=(
	/.git /.github /.vscode /node_modules /dist /.claude /.mcp.json /.gitignore /vendor
	/composer.json /composer.lock /phpcs.xml.dist /phpstan.neon.dist /phpstan-baseline.neon
	/phpcs.baseline.xml /.phpcs.cache /tools /CLAUDE.md /docs /.superpowers /.DS_Store
)

die() {
	echo "error: $*" >&2
	exit 1
}

dc() {
	docker compose -f "${E2E_DIR}/docker-compose.yml" "$@"
}

# wp-cli in the cli container, as www-data, in the WordPress volume.
wp() {
	dc --progress quiet run --rm -T cli wp "$@"
}

prepare_out_dir() {
	mkdir -p "${OUT_DIR}"
	# The cli container runs as uid 33 and writes here (zips are read, captures written).
	chmod 777 "${OUT_DIR}"
}

# build_zip <source-dir> <plugin-folder> <zip-path>: zip of <source-dir> with the
# release excludes, rooted at <plugin-folder>/ like a release asset.
build_zip() {
	local src="$1" folder="$2" zip_path="$3" staging args=() item

	for item in "${ZIP_EXCLUDES[@]}"; do
		args+=("--exclude=${item}")
	done

	staging="$(mktemp -d)"
	mkdir -p "${staging}/${folder}"
	rsync -a "${args[@]}" "${src}/" "${staging}/${folder}/"
	rm -f "${zip_path}"
	(cd "${staging}" && zip -qr "${zip_path}" "${folder}")
	rm -rf "${staging}"

	# The e2e-auth mu-plugin logs in anyone who sends a header: it must never ship.
	if unzip -Z1 "${zip_path}" | grep -Eq '(^|/)(tools|e2e-auth\.php)(/|$)'; then
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
	local minimal=0 encryption_key=0 arg

	for arg in "$@"; do
		case "${arg}" in
			--minimal) minimal=1 ;;
			--encryption-key) encryption_key=1 ;;
			*) echo "seed-skmt: unknown option ${arg}" >&2; exit 2 ;;
		esac
	done

	prepare_out_dir
	if wp plugin is-installed "${SKMT_SLUG}" >/dev/null 2>&1; then
		die "SKMT is already installed: run 'down' then 'up' for a fresh bench"
	fi

	local zip_name="${SKMT_SLUG}-${SKMT_COMMIT}.zip" extracted
	extracted="$(mktemp -d)"
	git -C "${REPO_DIR}" archive "${SKMT_COMMIT}" | tar -x -C "${extracted}"
	build_zip "${extracted}" "${SKMT_SLUG}" "${OUT_DIR}/${zip_name}"
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

	echo "SKMT seeded from ${SKMT_COMMIT} (minimal=${minimal}, encryption-key=${encryption_key})."
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

	build_zip "${REPO_DIR}" "${folder}" "${OUT_DIR}/${zip_name}"
	chmod 644 "${OUT_DIR}/${zip_name}"

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
	trap remove_snippets EXIT
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

usage() {
	cat <<'EOF'
Usage: tools/e2e/run.sh <command> [args]

  up                                   start the bench: WordPress 6.9+ in French, admin/admin on http://localhost:8089
  down                                 stop the bench and delete its data
  seed-skmt [--minimal] [--encryption-key]
                                       install SKMT (commit 8d4cd85) and seed realistic data
  capture <output-dir> [page-slug]     write the admin text to out/<output-dir>/ (slug defaults to studio-kyne-mini-tools)
  install-lumia                        install and activate the plugin built from the working tree
  assert-compat                        check the SKMT compatibility layer (constants, hooks, tables) on an installed bench
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
	-h | --help | help) usage ;;
	*)
		echo "unknown command: ${command}" >&2
		usage >&2
		exit 2
		;;
esac
