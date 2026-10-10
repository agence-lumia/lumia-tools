#!/usr/bin/env bash
#
# Docker bench for the image delivery checks (AVIF siblings served by Accept negotiation).
# See README.md. Needs bash, docker (with compose), curl, rsync, zip, unzip and, for the
# nginx stack, Python >= 3.11 (or Docker, as a fallback) to render the Dokploy template.
# Exit code: 0 on success, non-zero (usually 1) on failure, 2 on bad usage, 130 on SIGINT,
# 143 on SIGTERM.

set -euo pipefail

E2E_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "${E2E_DIR}/../.." && pwd)"
OUT_DIR="${E2E_DIR}/out"

# shellcheck source=lib.sh
. "${E2E_DIR}/lib.sh"

# The Dokploy template repository (nginx.conf is rendered from it, as the template's CI does).
TEMPLATE_DIR="${TEMPLATE_DIR:-${E2E_DIR}/../../../wp-dokploy-template}"

# Same exclude list as the release build (tools/build/build-zip.sh), from the working tree.
ZIP_EXCLUDES="${REPO_DIR}/tools/build/zip-excludes.txt"

die() {
	echo "error: $*" >&2
	exit 1
}

usage() {
	cat >&2 <<'USAGE'
usage: run.sh <command> [args]

  up <stack>                      start a stack and install WordPress (stack: nginx | nginx-plain |
                                  nginx-novary | nginx-mig | cdn | cdn-noquery | cdn-vary | apache | ols)
  down <stack>                    stop it and delete its data
  wp <stack> <args...>            WP-CLI under the stack's PHP-FPM / mod_php / lsphp (the web runtime)
  wp-cron <nginx-stack> <args...> WP-CLI in the template's cron container (wordpress:cli image)
  install-lumia <stack>           zip of the working tree, installed and activated
  import-fixtures <stack>         generate the synthetic images and import them as media
  fixtures <stack>                only generate them (out/fixtures/), nothing imported
  make-avif <stack> <path> [q]    hand-place <path>.avif next to a JPEG/PNG under wp-content/uploads
  htaccess <apache|ols> on|off    put / remove the spec 9.2 .htaccess block in wp-content/uploads
  assert <stack> <script.php> [args...]
                                  run a PHP assertion script with `wp eval-file` (as admin)
  curl-matrix <stack> <url> [avif|original|none]
                                  the client matrix against a path or URL (see README)
  latency <stack> <url> <seconds> median response time of a page over a duration
  apache-mod apache <module> on|off
                                  enable or disable an Apache module (a2enmod / a2dismod) and reload
  apache-override apache fileinfo|nofileinfo
                                  AllowOverride of wp-content/uploads with or without FileInfo
                                  (nofileinfo: a host that forbids RewriteEngine, Header, AddType)

Set BRICKS_ZIP=<path> to install and activate the Bricks theme on `up`.
USAGE
	exit 2
}

# Temporary files of this run, removed on exit.
TMP_ROOT="$(mktemp -d)"
cleanup() {
	rm -rf "${TMP_ROOT}"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

# --- Per-stack settings ---------------------------------------------------------

STACK=''
PORT=''
PROFILES=()      # compose profiles of the stack
SITE_URL=''
PHP_SERVICE=''   # container that runs PHP for the web (and for WP-CLI)
WP_PATH=''
WP_USER=''       # user the web server runs PHP as
PHP_BIN='php'

use_stack() {
	STACK="${1:-}"
	PROFILES=("${STACK}")
	case "${STACK}" in
		nginx | nginx-plain | nginx-novary | nginx-mig | cdn | cdn-noquery | cdn-vary)
			# nginx-plain: the template without its AVIF rule (a third-party nginx host).
			# nginx-novary: the rule without its `add_header Vary`.
			# nginx-mig: the nginx stack as it is, for the legacy media migration (own data).
			# cdn*: the template behind a caching proxy (cdn/default.conf.template); the
			# loopback of the PHP container goes through the proxy too, as a production
			# container would through a CDN in front of its domain. cdn ignores Vary,
			# cdn-noquery also never caches a URL with a query string, cdn-vary keeps Vary.
			local origin=''
			case "${STACK}" in
				nginx) PORT=8091 ;;
				nginx-plain) PORT=8095 ;;
				nginx-novary) PORT=8101 ;;
				nginx-mig) PORT=8102 ;;
				cdn) PORT=8094 origin=8096 ;;
				cdn-noquery) PORT=8097 origin=8098 ;;
				cdn-vary) PORT=8099 origin=8100 ;;
			esac
			PROFILES=(nginx)
			export E2E_NGINX_PORT="${PORT}" E2E_SITE_PORT="${PORT}" E2E_LOOPBACK_TARGET=web-nginx:80
			if [ -n "${origin}" ]; then
				PROFILES=(nginx cdn)
				export E2E_NGINX_PORT="${origin}" E2E_LOOPBACK_TARGET=proxy-cdn:80 E2E_CDN_PORT="${PORT}"
				export E2E_CDN_IGNORE='Vary Cache-Control Expires Set-Cookie' E2E_CDN_SKIP=0
				# shellcheck disable=SC2016 # $args: an nginx variable, for the proxy's template.
				case "${STACK}" in
					cdn-noquery) export E2E_CDN_SKIP='$args' ;;
					cdn-vary) export E2E_CDN_IGNORE='Cache-Control Expires Set-Cookie' ;;
				esac
			fi
			PHP_SERVICE=wp-nginx
			WP_PATH=/var/www/html
			WP_USER=www-data
			export E2E_DISABLE_WP_CRON=true
			;;
		apache)
			PORT=8092
			PHP_SERVICE=wp-apache
			WP_PATH=/var/www/html
			WP_USER=www-data
			export E2E_DISABLE_WP_CRON=false
			;;
		ols)
			PORT=8093
			PHP_SERVICE=ols
			WP_PATH=/usr/local/lsws/Example/html
			WP_USER=nobody
			PHP_BIN=/usr/local/lsws/lsphp85/bin/php
			export E2E_DISABLE_WP_CRON=false
			;;
		*) echo "unknown stack '${STACK}' (nginx | nginx-plain | nginx-novary | nginx-mig | cdn | cdn-noquery | cdn-vary | apache | ols)" >&2; exit 2 ;;
	esac
	SITE_URL="http://localhost:${PORT}"
	# One rendering per stack: the variants run side by side and must not share (or delete)
	# each other's mounted files.
	export RENDERED_DIR="${OUT_DIR}/${STACK}-rendered"
}

is_nginx_stack() {
	[ "${PHP_SERVICE}" = wp-nginx ]
}

dc() {
	local profile args=()
	for profile in "${PROFILES[@]}"; do
		args+=(--profile "${profile}")
	done
	docker compose -f "${E2E_DIR}/docker-compose.yml" -p "lumia-img-${STACK}" "${args[@]}" "$@"
}

prepare_out_dir() {
	mkdir -p "${OUT_DIR}"
	# The PHP containers run as www-data / nobody and write here (zips, fixtures).
	chmod 777 "${OUT_DIR}"
}

# WP-CLI phar of the official cli image, run by the stack's own PHP (spec 9.1: the web runtime
# is the one that encodes). Extracted once.
ensure_wp_phar() {
	prepare_out_dir
	if [ ! -s "${OUT_DIR}/wp-cli.phar" ]; then
		docker run --rm --entrypoint cat wordpress:cli-2-php8.5 /usr/local/bin/wp >"${OUT_DIR}/wp-cli.phar"
		chmod 644 "${OUT_DIR}/wp-cli.phar"
	fi
}

# php_exec <user> <args...>: PHP of the stack's web runtime, as <user>.
php_exec() {
	local user="$1"
	shift
	dc exec -T -u "${user}" -e HOME=/tmp -e WP_CLI_CONFIG_PATH=/bench/wp-cli.yml "${PHP_SERVICE}" "${PHP_BIN}" "$@"
}

# wp_as <user> <args...>
wp_as() {
	local user="$1"
	shift
	# WP-CLI 2.12 under PHP 8.5 prints a deprecation from php-cli-tools on every colourised
	# line: noise, filtered out (the stream is stderr, so command substitutions stay clean).
	php_exec "${user}" -d memory_limit=512M /bench/out/wp-cli.phar --no-color --path="${WP_PATH}" "$@" \
		2> >(grep -v 'php-cli-tools/lib/cli/Colors.php' >&2)
}

wp() {
	wp_as "${WP_USER}" "$@"
}

# --- Template rendering (nginx) ---------------------------------------------------

# A Python able to read TOML (tomllib, 3.11+).
find_python() {
	local candidate
	for candidate in python3 python3.13 python3.12 python3.11; do
		if command -v "${candidate}" >/dev/null 2>&1 && "${candidate}" -c 'import tomllib' >/dev/null 2>&1; then
			echo "${candidate}"
			return
		fi
	done
}

# render_template: nginx.conf and the other mounts, rendered from the template SOURCES. The
# payload (dokploy-template.b64) is regenerated in a copy first, so that an edit of
# template.toml is picked up without touching the template repository (its CI regenerates the
# payload on main; the working tree may hold a stale one).
render_template() {
	local copy py rendered="${RENDERED_DIR}"

	[ -f "${TEMPLATE_DIR}/template.toml" ] || die "no Dokploy template in ${TEMPLATE_DIR} (set TEMPLATE_DIR)"
	copy="$(mktemp -d "${TMP_ROOT}/template.XXXXXX")"
	rsync -a --exclude .git "${TEMPLATE_DIR}/" "${copy}/"
	rm -rf "${rendered}"

	py="$(find_python)"
	if [ -n "${py}" ]; then
		(cd "${copy}" && "${py}" generate_base64.py >/dev/null && "${py}" ci/render-payload.py "${rendered}" >/dev/null)
	else
		echo "No Python >= 3.11 on the host: rendering the template with python:3.12-alpine."
		docker run --rm -v "${copy}:/t" -v "${OUT_DIR}:/out" -w /t python:3.12-alpine \
			sh -c 'python generate_base64.py >/dev/null && python ci/render-payload.py /out/nginx-rendered >/dev/null && chmod -R a+rX /out/nginx-rendered'
	fi

	[ -s "${rendered}/files/nginx.conf" ] || die "the template did not render ${rendered}/files/nginx.conf"
	grep -q 'lumia_avif_suffix' "${rendered}/files/nginx.conf" \
		|| die "the rendered nginx.conf has no AVIF negotiation (\$lumia_avif_suffix): is ${TEMPLATE_DIR} on the feat/avif-negotiation branch?"

	case "${STACK}" in
		nginx-plain) strip_avif_rule "${rendered}/files/nginx.conf" ;;
		nginx-novary) strip_avif_vary "${rendered}/files/nginx.conf" ;;
	esac
}

# strip_avif_vary <nginx.conf>: keeps the AVIF location but drops its `add_header Vary`, as a
# host that copied the rule incompletely: the AVIF is negotiated without Vary.
strip_avif_vary() {
	local conf="$1" tmp
	tmp="$(mktemp "${TMP_ROOT}/nginx-novary.XXXXXX")"
	awk '
		index($0, "location ~* ^/wp-content/uploads/.+") && index($0, "jpe?g|png") { inside = 1 }
		inside && index($0, "add_header Vary") { next }
		inside && /^    }$/ { inside = 0 }
		{ print }
	' "${conf}" >"${tmp}"
	if ! grep -qE '^[[:space:]]*try_files .*lumia_avif_suffix' "${tmp}" || [ "$(grep -c 'add_header Vary' "${tmp}")" -ge "$(grep -c 'add_header Vary' "${conf}")" ]; then
		die "nginx-novary: could not remove the Vary header from the AVIF location in ${conf}"
	fi
	cat "${tmp}" >"${conf}"
}

# strip_avif_rule <nginx.conf>: removes the AVIF location (JPEG/PNG of the uploads), as on an
# nginx host that never received the template's rule. The maps stay (unused, harmless): the
# JPEG/PNG then fall into the generic image location (no Vary, immutable).
strip_avif_rule() {
	local conf="$1" tmp
	tmp="$(mktemp "${TMP_ROOT}/nginx-plain.XXXXXX")"
	awk '
		index($0, "location ~* ^/wp-content/uploads/.+") && index($0, "jpe?g|png") { skip = 1 }
		skip && /^    }$/ { skip = 0; next }
		!skip
	' "${conf}" >"${tmp}"
	if grep -qE '^[[:space:]]*try_files .*lumia_avif_suffix' "${tmp}" || ! grep -qF '(jpg|jpeg|png|gif|ico|svg|webp|avif)$' "${tmp}"; then
		die "nginx-plain: could not remove the AVIF location from ${conf}"
	fi
	cat "${tmp}" >"${conf}"
}

# --- up / down ---------------------------------------------------------------------

wait_for() { # wait_for <label> <tries> <command...>
	local label="$1" tries="$2" n=0
	shift 2
	until "$@" >/dev/null 2>&1; do
		n=$((n + 1))
		[ "${n}" -lt "${tries}" ] || die "${label}: not ready after ${tries} tries"
		sleep 2
	done
}

# OpenLiteSpeed: the container ships no WordPress. Download it into the vhost root (as root,
# the volume starts with the stock Example files), create wp-config.php, then hand the files
# to the user lsphp runs as.
ols_bootstrap() {
	local wp_root=(php_exec root /bench/out/wp-cli.phar --allow-root "--path=${WP_PATH}")

	if dc exec -T -u root "${PHP_SERVICE}" test -f "${WP_PATH}/wp-config.php"; then
		return
	fi
	"${wp_root[@]}" core download --force --version="${E2E_WP_VERSION:-latest}"
	"${wp_root[@]}" config create --dbhost=db --dbname=wordpress --dbuser=wordpress --dbpass=wordpress --skip-check
	"${wp_root[@]}" config set WP_DEBUG true --raw
	"${wp_root[@]}" config set WP_DEBUG_LOG true --raw
	"${wp_root[@]}" config set WP_DEBUG_DISPLAY false --raw
	"${wp_root[@]}" config set WP_MEMORY_LIMIT 256M
	"${wp_root[@]}" config set FS_METHOD direct
	# The mu-plugins directory is a read-only bind mount: leave it alone.
	dc exec -T -u root "${PHP_SERVICE}" sh -c \
		"find '${WP_PATH}' -path '${WP_PATH}/wp-content/mu-plugins' -prune -o -exec chown nobody:nogroup {} +"
}

cmd_up() {
	use_stack "${1:-}"
	prepare_out_dir
	ensure_wp_phar

	if is_nginx_stack; then
		render_template
	fi

	dc up -d --wait
	echo "Waiting for WordPress files..."

	if [ "${STACK}" = ols ]; then
		wait_for "OpenLiteSpeed container" 30 dc exec -T "${PHP_SERVICE}" test -d "${WP_PATH}"
		wait_for "database" 40 dc exec -T "${PHP_SERVICE}" "${PHP_BIN}" -r 'exit(@mysqli_connect("db","wordpress","wordpress","wordpress")?0:1);'
		ols_bootstrap
	else
		wait_for "wp-config.php" 60 wp config path
	fi

	if ! wp core is-installed >/dev/null 2>&1; then
		wp core install --url="${SITE_URL}" --title="Image delivery bench (${STACK})" \
			--admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email
	fi
	# Pretty permalinks, as on a real site. Apache and OpenLiteSpeed need the root .htaccess
	# (hard flush); nginx routes through try_files and has no use for it.
	if is_nginx_stack; then
		wp rewrite structure '/%postname%/' >/dev/null
	else
		wp rewrite structure '/%postname%/' --hard >/dev/null
	fi

	if [ "${STACK}" = ols ]; then
		# The root .htaccess (WordPress permalinks) is bound to the vhost context and read when
		# OpenLiteSpeed starts: the one written just now needs a restart to apply.
		dc exec -T "${PHP_SERVICE}" /usr/local/lsws/bin/lswsctrl restart >/dev/null
		sleep 3
	fi

	wait_for "${SITE_URL}" 30 curl -fsS -o /dev/null "${SITE_URL}/wp-login.php"

	install_bricks

	echo "Bench ready: ${SITE_URL} (admin / admin), stack ${STACK}, WordPress $(wp core version | tr -d '\r')."
}

install_bricks() {
	if [ -z "${BRICKS_ZIP:-}" ]; then
		echo "BRICKS_ZIP not set: the Bricks tests will be skipped."
		return
	fi
	[ -f "${BRICKS_ZIP}" ] || die "BRICKS_ZIP: no such file ${BRICKS_ZIP}"
	cp "${BRICKS_ZIP}" "${OUT_DIR}/bricks.zip"
	chmod 644 "${OUT_DIR}/bricks.zip"
	wp theme install /bench/out/bricks.zip --force --activate
}

cmd_down() {
	use_stack "${1:-}"
	dc down -v --remove-orphans
}

# --- Commands ------------------------------------------------------------------------

cmd_wp() {
	use_stack "${1:-}"
	shift
	ensure_wp_phar
	wp "$@"
}

cmd_wp_cron() {
	use_stack "${1:-}"
	is_nginx_stack || die "wp-cron: only the nginx stacks have the template's cron container"
	shift
	dc exec -T cron-nginx wp --path=/var/www/html "$@"
}

# build_zip <source-dir> <plugin-folder> <zip-path>: zip of <source-dir> with the release
# excludes, rooted at <plugin-folder>/ like a release asset. Same as tools/e2e/run.sh.
build_zip() {
	local src="$1" folder="$2" zip_path="$3" staging entries

	staging="$(mktemp -d "${TMP_ROOT}/staging.XXXXXX")"
	mkdir -p "${staging}/${folder}"
	rsync -a --exclude-from="${ZIP_EXCLUDES}" "${src}/" "${staging}/${folder}/"
	rm -f "${zip_path}"
	(cd "${staging}" && zip -qr "${zip_path}" "${folder}")
	rm -rf "${staging}"

	# The e2e-auth mu-plugin logs in anyone who sends a header: it must never ship.
	# Listing captured first: "unzip | grep -q" fails with SIGPIPE under pipefail.
	entries="$(unzip -Z1 "${zip_path}")" || die "unreadable zip ${zip_path}"
	if grep -Eq '(^|/)(tools|e2e-auth\.php)(/|$)' <<<"${entries}"; then
		rm -f "${zip_path}"
		die "tools/ or e2e-auth.php ended up in ${zip_path}"
	fi
}

# Name of the main plugin file of the working tree: the root *.php with a "Plugin Name:"
# header, without .php. Also the plugin folder name.
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

cmd_install_lumia() {
	use_stack "${1:-}"
	prepare_out_dir
	ensure_wp_phar

	local folder zip_name
	folder="$(working_tree_plugin)"
	zip_name="${folder}-worktree.zip"
	build_zip "${REPO_DIR}" "${folder}" "${OUT_DIR}/${zip_name}"
	chmod 644 "${OUT_DIR}/${zip_name}"

	wp plugin install "/bench/out/${zip_name}" --force --activate

	# The template's OPcache revalidates files every 60 s: PHP-FPM would keep running the
	# previous build for up to a minute after the install. Reset through a throwaway script
	# (an FPM reload would re-read config files whose bind mounts `up` may have replaced).
	if is_nginx_stack; then
		local reset="lumia-bench-opcache-reset-$$.php"
		dc exec -T -u "${WP_USER}" "${PHP_SERVICE}" sh -c "printf '%s' '<?php echo opcache_reset() ? \"reset\" : \"no\";' >'${WP_PATH}/${reset}'"
		curl -s --max-time 30 "${SITE_URL}/${reset}?t=$$${RANDOM}" >/dev/null || true
		dc exec -T -u "${WP_USER}" "${PHP_SERVICE}" rm -f "${WP_PATH}/${reset}"
	fi
}

# ensure_fixtures: generate the synthetic images into out/fixtures/ once (inside the stack's PHP).
# visual-alpha-noopt.png is the newest file of the generator: its absence means an older set.
ensure_fixtures() {
	if [ ! -s "${OUT_DIR}/fixtures/photo-4000.jpg" ] || [ ! -s "${OUT_DIR}/fixtures/visual-alpha-noopt.png" ] \
		|| [ "${REGENERATE_FIXTURES:-0}" = 1 ]; then
		rm -rf "${OUT_DIR}/fixtures"
		echo "Generating the fixtures (synthetic images, no client data)..."
		php_exec "${WP_USER}" /bench/fixtures/make-fixtures.php /bench/out/fixtures
	fi
}

cmd_fixtures() {
	use_stack "${1:-}"
	prepare_out_dir
	ensure_fixtures
}

cmd_import_fixtures() {
	use_stack "${1:-}"
	prepare_out_dir
	ensure_wp_phar

	local dir=/bench/out/fixtures file name id url
	ensure_fixtures

	echo "Importing the fixtures..."
	printf '%-20s %-6s %s\n' FIXTURE ID URL
	for file in photo-4000.jpg photo-p3.jpg photo-bigicc.jpg visual-alpha.png logo-flat.png anim.gif corrupt.jpg; do
		name="${file}"
		if ! id="$(wp media import "${dir}/${file}" --porcelain 2>/dev/null | tr -d '\r' | tail -n 1)" || [[ ! "${id}" =~ ^[0-9]+$ ]]; then
			printf '%-20s %-6s %s\n' "${name}" - "(import refused by WordPress)"
			continue
		fi
		url="$(wp eval "echo wp_get_attachment_url( ${id} );" | tr -d '\r')"
		printf '%-20s %-6s %s\n' "${name}" "${id}" "${url}"
	done
}

# make-avif <stack> <path under wp-content/uploads> [quality]: hand-place <path>.avif next to a
# JPEG/PNG, for checks that need a sibling without the plugin. Encoded by the production FPM
# image (wordpress:7-php8.5-fpm-alpine) in a throwaway container sharing the stack's volume:
# the OpenLiteSpeed image's Imagick has no AVIF encoder, the cli image's has no codec at all.
cmd_make_avif() {
	use_stack "${1:-}"
	local rel="${2:-}" quality="${3:-70}" container uid gid
	[[ "${rel}" =~ ^[A-Za-z0-9._/-]+$ ]] && [[ "${quality}" =~ ^[0-9]+$ ]] || usage

	container="$(dc ps -q "${PHP_SERVICE}")"
	[ -n "${container}" ] || die "make-avif: the ${STACK} stack is not running"
	uid="$(dc exec -T "${PHP_SERVICE}" id -u "${WP_USER}" | tr -d '\r')"
	gid="$(dc exec -T "${PHP_SERVICE}" id -g "${WP_USER}" | tr -d '\r')"

	docker run --rm --volumes-from "${container}" --user "${uid}:${gid}" \
		-e "AVIF_SRC=${WP_PATH}/wp-content/uploads/${rel}" -e "AVIF_Q=${quality}" \
		--entrypoint php wordpress:7-php8.5-fpm-alpine -r '
			$src = getenv( "AVIF_SRC" );
			$im  = new Imagick( $src );
			$im->setImageFormat( "avif" );
			$im->setCompressionQuality( (int) getenv( "AVIF_Q" ) );
			$im->setImageCompressionQuality( (int) getenv( "AVIF_Q" ) );
			$im->setOption( "heic:speed", "8" );
			$im->setOption( "heic:chroma", "444" );
			$im->writeImage( $src . ".avif" );
			chmod( $src . ".avif", 0644 );
			printf( "%s.avif: %d bytes (source %d)\n", basename( $src ), filesize( $src . ".avif" ), filesize( $src ) );
		'
}

# htaccess <apache|ols> on|off: the hand-written block of spec 9.2 (htaccess/avif-block.htaccess)
# in wp-content/uploads/.htaccess, until the plugin writes it itself.
cmd_htaccess() {
	use_stack "${1:-}"
	! is_nginx_stack || die "htaccess: nginx does not read .htaccess"
	local state="${2:-}"
	case "${state}" in
		on) wp eval 'file_put_contents( wp_upload_dir()["basedir"] . "/.htaccess", file_get_contents( "/bench/htaccess/avif-block.htaccess" ) );' ;;
		off) wp eval '@unlink( wp_upload_dir()["basedir"] . "/.htaccess" );' ;;
		*) usage ;;
	esac
	if [ "${STACK}" = ols ]; then
		# Workers read a changed .htaccess lazily and unevenly: restart (README, OpenLiteSpeed findings).
		dc exec -T "${PHP_SERVICE}" /usr/local/lsws/bin/lswsctrl restart >/dev/null
		sleep 3
	fi
	echo "uploads/.htaccess: ${state}"
}

cmd_assert() {
	use_stack "${1:-}"
	local script="${2:-}" tmp rc=0
	[ -f "${script}" ] || die "assert: no such script '${script}'"
	shift 2
	prepare_out_dir
	ensure_wp_phar

	# Assertion scripts may read the fixtures (/bench/out/fixtures).
	ensure_fixtures

	tmp="assert-$$.php"
	cp "${script}" "${OUT_DIR}/${tmp}"
	chmod 644 "${OUT_DIR}/${tmp}"
	# Extra arguments reach the script as $args.
	wp --user=admin eval-file "/bench/out/${tmp}" "$@" || rc=$?
	rm -f "${OUT_DIR}/${tmp}"
	return "${rc}"
}

# resolve_url <path-or-url>: a leading / is relative to the stack's site.
resolve_url() {
	case "$1" in
		/*) echo "${SITE_URL}$1" ;;
		http://* | https://*) echo "$1" ;;
		*) echo "bad URL '$1' (a path starting with / or an http(s) URL)" >&2; exit 2 ;;
	esac
}

cmd_curl_matrix() {
	use_stack "${1:-}"
	[ -n "${2:-}" ] || usage
	curl_matrix "$(resolve_url "$2")" "${3:-none}"
}

cmd_latency() {
	use_stack "${1:-}"
	[ -n "${2:-}" ] && [ -n "${3:-}" ] || usage
	latency_probe "$(resolve_url "$2")" "$3"
}

cmd_apache_mod() {
	use_stack "${1:-}"
	[ "${STACK}" = apache ] || die "apache-mod: apache stack only"
	local module="${2:-}" state="${3:-}"
	[[ "${module}" =~ ^[a-z_]+$ ]] || usage
	case "${state}" in
		on) dc exec -T "${PHP_SERVICE}" a2enmod "${module}" ;;
		off) dc exec -T "${PHP_SERVICE}" a2dismod -f "${module}" ;;
		*) usage ;;
	esac
	dc exec -T "${PHP_SERVICE}" apache2ctl graceful
}

# apache-override apache fileinfo|nofileinfo: `nofileinfo` gives wp-content/uploads the
# AllowOverride of a host that forbids FileInfo directives: every RewriteEngine / Header /
# AddType line of uploads/.htaccess then answers 500 (the plugin must remove its block).
cmd_apache_override() {
	use_stack "${1:-}"
	[ "${STACK}" = apache ] || die "apache-override: apache stack only"
	local conf=/etc/apache2/conf-enabled/zz-bench-uploads-override.conf
	case "${2:-}" in
		fileinfo) dc exec -T "${PHP_SERVICE}" rm -f "${conf}" ;;
		nofileinfo)
			dc exec -T "${PHP_SERVICE}" sh -c "printf '%s\n' '<Directory ${WP_PATH}/wp-content/uploads>' '    AllowOverride AuthConfig Indexes Limit Options' '</Directory>' >'${conf}'"
			;;
		*) usage ;;
	esac
	dc exec -T "${PHP_SERVICE}" apache2ctl graceful
	sleep 1
}

# --- Dispatch --------------------------------------------------------------------------

[ "$#" -ge 1 ] || usage
command="$1"
shift

case "${command}" in
	up) cmd_up "$@" ;;
	down) cmd_down "$@" ;;
	wp) cmd_wp "$@" ;;
	wp-cron) cmd_wp_cron "$@" ;;
	install-lumia) cmd_install_lumia "$@" ;;
	import-fixtures) cmd_import_fixtures "$@" ;;
	fixtures) cmd_fixtures "$@" ;;
	make-avif) cmd_make_avif "$@" ;;
	htaccess) cmd_htaccess "$@" ;;
	assert) cmd_assert "$@" ;;
	curl-matrix) cmd_curl_matrix "$@" ;;
	latency) cmd_latency "$@" ;;
	apache-mod) cmd_apache_mod "$@" ;;
	apache-override) cmd_apache_override "$@" ;;
	*) usage ;;
esac
