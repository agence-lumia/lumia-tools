#!/usr/bin/env bash
#
# Delivery self-test assertions (DeliveryProbe, HtaccessWriter; spec 1, 9.2, 9.10): on each
# stack, the Image Optimizer module is activated through the admin (HTTP, as a real
# administrator: the probe runs in the web runtime), then the recorded result, the files and
# the responses the clients get are checked.
#
#   nginx        template rule present        -> mode nginx, client matrix conform
#   nginx-plain  template without the rule     -> mode none, snippet shown, upload queued but
#                                                 nothing generated (even on a drain request)
#   apache       .htaccess block               -> mode htaccess, other lines kept, matrix conform;
#                mod_headers off / no FileInfo -> block removed, no 500 left; deactivation removes it
#   ols          OpenLiteSpeed                 -> self-test fails, block removed, mode none,
#                                                 the JPEG/PNG for every client
#   cdn          proxy ignoring Vary (cf-ray)  -> mode none, generated .avif deleted
#   cdn-noquery  same, never caching a URL with a query string (the probe's)
#                                              -> mode none (cdn_unproven), .avif deleted
#   cdn-vary     proxy keeping one entry per Accept value -> mode nginx, proven by cache hits
#   nginx-novary the template rule without its Vary -> mode none (no_vary), .avif deleted
#
# usage: assert-delivery.sh <nginx|nginx-plain|nginx-novary|apache|ols|cdn|cdn-noquery|cdn-vary|all> [--down]
#   --down  stop and delete each stack after its run (`all` runs the stacks one by one)
# Needs what run.sh needs, plus jq. Exit code: 0 when every assertion passed, 1 otherwise,
# 2 on bad usage.

set -euo pipefail

E2E_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RUN="${E2E_DIR}/run.sh"
OUT_DIR="${E2E_DIR}/out"

# shellcheck source=lib.sh
. "${E2E_DIR}/lib.sh"

OPTION=lumia_module_image_optimizer_delivery
FAILS=0
STACK=''
SITE=''
JAR=''
NONCE=''

# The exact block the plugin must write in uploads/.htaccess (spec 9.2).
EXPECTED_BLOCK='# BEGIN Lumia Tools AVIF
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
# END Lumia Tools AVIF'

usage() {
	sed -n '/^# usage:/,/^# 2 on bad usage/p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//' >&2
	exit 2
}

# --- Reporting ----------------------------------------------------------------------

pass() { echo "  ok    $*"; }
fail() {
	echo "  FAIL  $*" >&2
	FAILS=$((FAILS + 1))
}

# expect <label> <actual> <expected>
expect() {
	if [ "$2" = "$3" ]; then pass "$1 ($2)"; else fail "$1: expected '$3', got '$2'"; fi
}

# expect_match <label> <actual> <extended regex>
expect_match() {
	if grep -qE -- "$3" <<<"$2"; then pass "$1"; else fail "$1: '$2' does not match /$3/"; fi
}

# --- Stack helpers ------------------------------------------------------------------

wpx() {
	"${RUN}" wp "${STACK}" "$@" | tr -d '\r'
}

delivery() { # delivery <field>: a field of the recorded self-test result
	wpx eval "\$r = get_option( '${OPTION}', [] ); echo is_array( \$r ) ? (string) ( \$r['$1'] ?? '' ) : '';"
}

htaccess() {
	wpx eval '$f = wp_upload_dir()["basedir"] . "/.htaccess"; echo is_file( $f ) ? file_get_contents( $f ) : "";'
}

# The block between the markers, markers included ('' when absent).
htaccess_block() {
	awk '/^# BEGIN Lumia Tools AVIF$/ { p = 1 } p { print } /^# END Lumia Tools AVIF$/ { p = 0 }' <<<"$(htaccess)"
}

# .avif files under uploads/, outside the plugin's own folder (probe files).
avif_files() {
	wpx eval '$b = wp_upload_dir()["basedir"]; if ( ! is_dir( $b ) ) { return; }
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $b, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( preg_match( "/\.avif$/i", $f->getFilename() ) && false === strpos( $f->getPathname(), "/lumia-tools/" ) ) {
				echo substr( $f->getPathname(), strlen( $b ) + 1 ), "\n";
			}
		}'
}

cron_scheduled() {
	wpx eval 'echo wp_next_scheduled( "lumia_image_optimizer_delivery_check" ) ? "yes" : "no";'
}

# Starts the stack, installs the working tree, and resets everything the module touches.
setup() {
	STACK="$1"
	case "${STACK}" in
		nginx) SITE=http://localhost:8091 ;;
		apache) SITE=http://localhost:8092 ;;
		ols) SITE=http://localhost:8093 ;;
		cdn) SITE=http://localhost:8094 ;;
		nginx-plain) SITE=http://localhost:8095 ;;
		cdn-noquery) SITE=http://localhost:8097 ;;
		cdn-vary) SITE=http://localhost:8099 ;;
		nginx-novary) SITE=http://localhost:8101 ;;
		*) usage ;;
	esac
	echo "== ${STACK} (${SITE})"

	"${RUN}" up "${STACK}" >/dev/null
	"${RUN}" fixtures "${STACK}" >/dev/null
	"${RUN}" install-lumia "${STACK}" >/dev/null 2>&1

	# Module off without its hooks, no result, no probe folder, no uploads/.htaccess.
	wpx eval '
		$s = get_option( "lumia_settings", [] );
		$s["modules"]["image_optimizer"] = false;
		update_option( "lumia_settings", $s );
		delete_option( "lumia_module_image_optimizer_delivery" );
		wp_unschedule_hook( "lumia_image_optimizer_delivery_check" );
		$b = wp_upload_dir()["basedir"];
		@unlink( $b . "/.htaccess" );
		foreach ( (array) glob( $b . "/lumia-tools/{,.}*", GLOB_BRACE ) as $f ) { if ( is_file( $f ) ) { unlink( $f ); } }
		@rmdir( $b . "/lumia-tools" );' >/dev/null

	JAR="$(mktemp)"
	login
}

# Logs in as admin with real cookies (stable session: the nonces stay valid), reads the
# admin nonce of the plugin's screens.
login() {
	local page
	curl -s -o /dev/null -c "${JAR}" "${SITE}/wp-login.php" || true
	curl -s -o /dev/null -b "${JAR}" -c "${JAR}" \
		--data-urlencode log=admin --data-urlencode pwd=admin --data-urlencode testcookie=1 \
		"${SITE}/wp-login.php" || true
	page="$(curl -s -b "${JAR}" "${SITE}/wp-admin/admin.php?page=lumia-tools" || true)"
	NONCE="$(grep -oE '"nonce":"[0-9a-f]+"' <<<"${page}" | head -n 1 | cut -d'"' -f4 || true)"
	[ -n "${NONCE}" ] || {
		echo "error: could not log in to ${SITE} or read the admin nonce" >&2
		exit 1
	}
}

# ajax <action> [key=value...]: admin-ajax call with the admin nonce; prints the response.
ajax() {
	local args=(-s -b "${JAR}" --max-time 300 --data-urlencode "action=$1" --data-urlencode "nonce=${NONCE}") kv
	shift
	for kv in "$@"; do
		args+=(--data-urlencode "${kv}")
	done
	curl "${args[@]}" "${SITE}/wp-admin/admin-ajax.php" || true
}

module() { # module activate|deactivate
	ajax lumia_ajax_toggle_module module=image_optimizer "lumia_action=$1" >/dev/null
}

retest() {
	ajax lumia_image_optimizer_delivery_retest
}

# The module screen as the administrator sees it.
module_screen() {
	curl -s -b "${JAR}" "${SITE}/wp-admin/admin.php?page=lumia-tools&tab=module_image_optimizer" || true
}

# upload <fixture>: upload through async-upload.php like the media library (the web runtime
# runs the upload hooks); prints the attachment ID.
upload() {
	local page wpnonce response
	page="$(curl -s -b "${JAR}" "${SITE}/wp-admin/media-new.php" || true)"
	wpnonce="$(grep -oE '"_wpnonce":"[0-9a-f]+"' <<<"${page}" | head -n 1 | cut -d'"' -f4 || true)"
	response="$(curl -s -b "${JAR}" --max-time 300 \
		-F "async-upload=@${OUT_DIR}/fixtures/$1" -F "name=$1" -F action=upload-attachment -F "_wpnonce=${wpnonce}" \
		"${SITE}/wp-admin/async-upload.php" || true)"
	jq -r '.data.id // empty' <<<"${response}" 2>/dev/null || true
}

# uploaded <id>: the upload worked; otherwise the rest of the scenario is skipped (and the
# stack stopped with --down).
uploaded() {
	if [[ "$1" =~ ^[0-9]+$ ]]; then
		pass "upload through async-upload.php (#$1)"
		return 0
	fi
	fail "upload through async-upload.php: no attachment ID, rest of the ${STACK} scenario skipped"
	finish_stack
	return 1
}

attached_file() { wpx eval "echo get_post_meta( $1, '_wp_attached_file', true );"; }

avif_status() { wpx eval "echo get_post_meta( $1, '_lumia_avif_status', true );"; }

# wait_sibling <path under uploads> <seconds>: until <path>.avif exists.
wait_sibling() {
	local end=$((SECONDS + $2))
	while [ "${SECONDS}" -lt "${end}" ]; do
		[ "$(wpx eval "echo file_exists( wp_upload_dir()['basedir'] . '/$1.avif' ) ? 'yes' : 'no';")" = yes ] && return 0
		sleep 1
	done
	return 1
}

status_code() { # status_code <url> [accept]: HTTP status for a plain client
	status_of "$(headers_of "$1" "${2:-*/*}" 'curl/8.7.1')"
}

# everyone_gets <url> <type>: every client of the matrix (and Chrome with ?original) receives
# <type>. No Vary requirement: this is the fallback when no negotiation is in place.
everyone_gets() {
	local url="$1" type="$2" row name accept ua n=0 sep="?"
	[[ "${url}" == *\?* ]] && sep='&'
	for row in "${CLIENTS[@]}"; do
		IFS="|" read -r name accept ua _ <<<"${row}"
		assert_type "${url}" "${accept}" "${ua}" "${type}" 2>/dev/null || {
			n=$((n + 1))
			echo "        ${name}: not ${type}" >&2
		}
	done
	assert_type "${url}${sep}original" "${CHROME_ACCEPT}" "${CHROME_UA}" "${type}" 2>/dev/null || n=$((n + 1))
	[ "${n}" -eq 0 ]
}

check_everyone_gets() { # check_everyone_gets <label> <url> <type>
	if everyone_gets "$2" "$3"; then pass "$1: every client gets $3"; else fail "$1: some clients do not get $3"; fi
}

check_matrix() { # check_matrix <label> <url>: curl-matrix in `avif` mode
	local out
	if out="$(curl_matrix "$2" avif 2>&1)"; then
		pass "$1: client matrix conform (AVIF to Chrome/Safari/Firefox/Apple Mail, JPEG/PNG to the rest, Vary: Accept)"
	else
		fail "$1: client matrix"
		echo "${out}" >&2
	fi
}

check_result_shape() { # check_result_shape <json response of retest>
	local keys
	keys="$(jq -r '.data.result | keys_unsorted | map(select(. == "mode" or . == "cdn" or . == "reason" or . == "checked_at")) | length' <<<"$1" 2>/dev/null || echo 0)"
	expect "retest answers {mode, cdn, reason, checked_at}" "${keys}" 4
}

probe_url() { echo "${SITE}/wp-content/uploads/lumia-tools/probe.png"; }

check_wordpress_404() { # a missing file under uploads/ still reaches WordPress (RewriteOptions Inherit)
	local headers status type
	headers="$(headers_of "${SITE}/wp-content/uploads/2020/01/missing-$$.jpg" '*/*' 'curl/8.7.1')"
	status="$(status_of "${headers}")"
	type="$(header_value Content-Type "${headers}")"
	if [ "${status}" = 404 ] && grep -qi 'charset=UTF-8' <<<"${type}"; then
		pass "missing upload: WordPress 404 ($type)"
	else
		fail "missing upload: expected the WordPress 404 (text/html; charset=UTF-8), got ${status:-none} ${type:-none}"
	fi
}

finish_stack() {
	rm -f "${JAR}"
	if [ "${DOWN}" = 1 ]; then
		"${RUN}" down "${STACK}" >/dev/null 2>&1 || true
	fi
}

# --- Scenarios ----------------------------------------------------------------------

scenario_nginx() {
	local response id rel url
	setup nginx

	module activate
	expect "activation: mode" "$(delivery mode)" nginx
	expect "activation: reason" "$(delivery reason)" ok
	expect "activation: no CDN" "$(delivery cdn)" ''
	expect_match "activation: checked_at set" "$(delivery checked_at)" '^[1-9][0-9]+$'
	expect "daily check scheduled" "$(cron_scheduled)" yes
	expect "probe AVIF served to Chrome" "$(header_value Content-Type "$(headers_of "$(probe_url)" "${CHROME_ACCEPT}" "${CHROME_UA}")")" image/avif
	expect "no uploads/.htaccess on nginx" "$(htaccess)" ''

	response="$(retest)"
	expect "retest (AJAX): mode" "$(jq -r '.data.result.mode // empty' <<<"${response}" 2>/dev/null || true)" nginx
	check_result_shape "${response}"
	expect "retest without nonce refused" "$(curl -s -b "${JAR}" -d action=lumia_image_optimizer_delivery_retest "${SITE}/wp-admin/admin-ajax.php" | head -c 2 || true)" '-1'

	if grep -q 'data-lumia-tab="delivery"' <<<"$(module_screen)"; then pass "Delivery tab present"; else fail "Delivery tab missing"; fi
	if grep -q 'lumia_avif_suffix' <<<"$(module_screen)"; then fail "nginx snippet shown although AVIF is served"; else pass "no nginx snippet when served"; fi

	id="$(upload photo-p3.jpg)"
	uploaded "${id}" || return 0
	rel="$(attached_file "${id}")"
	url="${SITE}/wp-content/uploads/${rel}"
	# The queue encodes the upload in the background (PHP-FPM, after the response).
	if wait_sibling "${rel}" 30; then pass "queue: ${rel}.avif generated after the upload"; else fail "queue: no ${rel}.avif 30 s after the upload"; fi
	check_matrix "processed media" "${url}"

	# Browser check (spec 9.10): an intermediate cache that serves the AVIF to `*/*` forces none
	# and deletes the generated siblings; a correct result brings the mode back.
	response="$(ajax lumia_image_optimizer_delivery_browser avif_status=200 avif_type=image/avif plain_status=200 plain_type=image/avif)"
	expect "browser leak: mode" "$(delivery mode)" none
	expect "browser leak: reason" "$(delivery reason)" browser
	expect "browser leak: generated .avif deleted" "$(avif_files)" ''
	response="$(ajax lumia_image_optimizer_delivery_browser avif_status=200 avif_type=image/avif plain_status=200 plain_type=image/png)"
	expect "browser ok: mode back" "$(delivery mode)" nginx
	expect "browser ok (AJAX answer)" "$(jq -r '.data.result.mode // empty' <<<"${response}" 2>/dev/null || true)" nginx

	module deactivate
	expect "module off: daily check unscheduled" "$(cron_scheduled)" no
	expect "module off: queue drain unscheduled" "$(wpx eval 'echo wp_next_scheduled( "lumia_image_optimizer_drain" ) ? "yes" : "no";')" no
	finish_stack
}

scenario_nginx_plain() {
	local id screen
	setup nginx-plain

	module activate
	expect "activation: mode" "$(delivery mode)" none
	expect "activation: reason" "$(delivery reason)" no_rule
	expect "no uploads/.htaccess on nginx" "$(htaccess)" ''

	screen="$(module_screen)"
	if grep -q 'lumia_avif_suffix' <<<"${screen}" && grep -q 'try_files' <<<"${screen}"; then
		pass "nginx snippet shown in the Delivery tab"
	else
		fail "nginx snippet missing from the Delivery tab"
	fi

	id="$(upload photo-p3.jpg)"
	uploaded "${id}" || return 0
	# Queued like anywhere else, never processed while the AVIF is not served: neither after
	# the upload, nor by a drain request carrying a valid token (the loopback's).
	expect "upload queued (waits for the delivery)" "$(avif_status "${id}")" pending
	wpx eval '$r = wp_remote_post( admin_url( "admin-ajax.php" ), [ "timeout" => 30, "body" => [ "action" => "lumia_image_optimizer_drain", "token" => Lumia\Tools\Modules\ImageOptimizer\QueueRunner::token() ] ] ); echo wp_remote_retrieve_response_code( $r );' >/dev/null
	sleep 5
	expect "no .avif generated after the upload and a drain request" "$(avif_files)" ''
	expect "still pending" "$(avif_status "${id}")" pending
	check_everyone_gets "uploaded media" "${SITE}/wp-content/uploads/$(attached_file "${id}")" image/jpeg
	finish_stack
}

scenario_apache() {
	local id rel url block
	setup apache
	wpx eval 'file_put_contents( wp_upload_dir()["basedir"] . "/.htaccess", "# keep-me\n" );' >/dev/null

	module activate
	expect "activation: mode" "$(delivery mode)" htaccess
	expect "activation: server" "$(delivery server)" apache
	if [ "$(htaccess_block)" = "${EXPECTED_BLOCK}" ]; then
		pass "uploads/.htaccess: block written exactly"
	else
		fail "uploads/.htaccess: block differs from the expected one"
		diff <(echo "${EXPECTED_BLOCK}") <(htaccess_block) >&2 || true
	fi
	expect "uploads/.htaccess: other lines kept" "$(htaccess | head -n 1)" '# keep-me'

	id="$(upload photo-p3.jpg)"
	uploaded "${id}" || return 0
	rel="$(attached_file "${id}")"
	url="${SITE}/wp-content/uploads/${rel}"
	"${RUN}" make-avif apache "${rel}" >/dev/null
	check_matrix "processed media" "${url}"
	check_wordpress_404

	echo "  -- mod_headers off"
	"${RUN}" apache-mod apache headers off >/dev/null 2>&1
	retest >/dev/null
	expect "no mod_headers: mode" "$(delivery mode)" none
	expect "no mod_headers: reason" "$(delivery reason)" no_vary
	expect "no mod_headers: block removed" "$(htaccess_block)" ''
	expect "no mod_headers: other lines kept" "$(htaccess | head -n 1)" '# keep-me'
	check_everyone_gets "no mod_headers" "${url}" image/jpeg
	expect "no mod_headers: home page" "$(status_code "${SITE}/")" 200
	"${RUN}" apache-mod apache headers on >/dev/null 2>&1
	retest >/dev/null
	expect "mod_headers back: mode" "$(delivery mode)" htaccess

	echo "  -- AllowOverride without FileInfo"
	"${RUN}" apache-override apache nofileinfo >/dev/null 2>&1
	retest >/dev/null
	expect "no FileInfo: mode" "$(delivery mode)" none
	expect "no FileInfo: reason" "$(delivery reason)" server_error
	expect "no FileInfo: block removed" "$(htaccess_block)" ''
	expect "no FileInfo: media answers (no 500 left)" "$(status_code "${url}")" 200
	expect "no FileInfo: probe answers (no 500 left)" "$(status_code "$(probe_url)")" 200
	expect "no FileInfo: home page" "$(status_code "${SITE}/")" 200
	"${RUN}" apache-override apache fileinfo >/dev/null 2>&1
	retest >/dev/null
	expect "FileInfo back: mode" "$(delivery mode)" htaccess

	echo "  -- deactivation"
	module deactivate
	expect "module off: block removed" "$(htaccess_block)" ''
	expect "module off: other lines kept" "$(htaccess | head -n 1)" '# keep-me'
	module activate
	expect "module on again: mode" "$(delivery mode)" htaccess
	block="$(htaccess_block)"
	if [ -n "${block}" ]; then pass "module on again: block back"; else fail "module on again: block missing"; fi
	wpx plugin deactivate lumia-tools >/dev/null 2>&1
	expect "plugin off: block removed" "$(htaccess_block)" ''
	expect "plugin off: other lines kept" "$(htaccess | head -n 1)" '# keep-me'
	wpx plugin activate lumia-tools >/dev/null 2>&1
	finish_stack
}

scenario_ols() {
	local id rel url
	setup ols

	module activate
	expect "activation: mode" "$(delivery mode)" none
	expect_match "activation: reason (no Vary on OpenLiteSpeed, or rule not loaded)" "$(delivery reason)" '^(no_vary|no_rule|leak)$'
	expect "activation: server" "$(delivery server)" litespeed
	expect "block removed after the failed self-test" "$(htaccess_block)" ''
	echo "  (recorded detail: $(delivery detail))"

	# A sibling left from a time AVIF was served (OpenLiteSpeed cannot encode: placed by hand),
	# then AVIF clients on it and on the probe: the workers that loaded the removed block keep
	# rewriting, and serve the AVIF to every client once primed (README, OLS findings).
	id="$(upload logo-flat.png)"
	uploaded "${id}" || return 0
	rel="$(attached_file "${id}")"
	url="${SITE}/wp-content/uploads/${rel}"
	"${RUN}" make-avif ols "${rel}" >/dev/null
	for _ in 1 2 3 4 5 6 7 8; do
		headers_of "${url}" "${CHROME_ACCEPT}" "${CHROME_UA}" >/dev/null
		headers_of "$(probe_url)" "${CHROME_ACCEPT}" "${CHROME_UA}" >/dev/null
	done

	retest >/dev/null
	expect "retest: mode" "$(delivery mode)" none
	expect "retest: block removed" "$(htaccess_block)" ''
	expect "retest: generated .avif deleted" "$(avif_files)" ''
	expect "retest: probe sibling deleted" "$(wpx eval 'echo file_exists( wp_upload_dir()["basedir"] . "/lumia-tools/probe.png.avif" ) ? "present" : "";')" ''
	check_everyone_gets "uploaded media" "${url}" image/png
	check_everyone_gets "probe" "$(probe_url)" image/png
	finish_stack
}

scenario_cdn() {
	local id rel url first second
	setup cdn

	module activate
	expect "activation: mode" "$(delivery mode)" none
	expect "activation: CDN" "$(delivery cdn)" cloudflare
	expect "activation: reason" "$(delivery reason)" cdn_vary

	id="$(upload photo-p3.jpg)"
	uploaded "${id}" || return 0
	rel="$(attached_file "${id}")"
	url="${SITE}/wp-content/uploads/${rel}"
	"${RUN}" make-avif cdn "${rel}" >/dev/null

	# The proxy is cold for this URL: a first Chrome request caches the AVIF, which Outlook
	# then receives. This is the hazard the self-test must catch.
	first="$(header_value Content-Type "$(headers_of "${url}" "${CHROME_ACCEPT}" "${CHROME_UA}")")"
	second="$(header_value Content-Type "$(headers_of "${url}" '*/*' 'Microsoft Office/16.0 (Windows NT 10.0; Microsoft Outlook 16.0.17928; Pro)')")"
	expect "cold proxy: Chrome first" "${first}" image/avif
	expect "cold proxy: then Outlook gets the cached AVIF (proxy ignores Vary)" "${second}" image/avif

	retest >/dev/null
	expect "retest: mode" "$(delivery mode)" none
	expect "retest: CDN" "$(delivery cdn)" cloudflare
	expect "retest: generated .avif deleted" "$(avif_files)" ''
	finish_stack
}

scenario_nginx_novary() {
	local id rel url
	setup nginx-novary

	module activate
	expect "activation: mode" "$(delivery mode)" none
	expect "activation: reason" "$(delivery reason)" no_vary

	# A sibling generated while the rule still had its Vary (placed by hand): the host rule keeps
	# serving it without Vary, so any shared cache could hand it to Outlook. It must go.
	id="$(upload photo-p3.jpg)"
	uploaded "${id}" || return 0
	rel="$(attached_file "${id}")"
	url="${SITE}/wp-content/uploads/${rel}"
	"${RUN}" make-avif nginx-novary "${rel}" >/dev/null
	expect "sibling served without Vary before the retest" "$(header_value Content-Type "$(headers_of "${url}" "${CHROME_ACCEPT}" "${CHROME_UA}")")" image/avif

	retest >/dev/null
	expect "retest: mode" "$(delivery mode)" none
	expect "retest: reason" "$(delivery reason)" no_vary
	expect "retest: generated .avif deleted" "$(avif_files)" ''
	check_everyone_gets "after the retest" "${url}" image/jpeg
	finish_stack
}

scenario_cdn_noquery() {
	local id rel url first second
	setup cdn-noquery

	module activate
	expect "activation: mode" "$(delivery mode)" none
	expect "activation: CDN" "$(delivery cdn)" cloudflare
	expect "activation: reason" "$(delivery reason)" cdn_unproven
	expect_match "activation: cache status recorded (probe URLs bypass the cache)" "$(delivery detail)" 'cache status per request: BYPASS'

	id="$(upload photo-p3.jpg)"
	uploaded "${id}" || return 0
	rel="$(attached_file "${id}")"
	url="${SITE}/wp-content/uploads/${rel}"
	"${RUN}" make-avif cdn-noquery "${rel}" >/dev/null

	# The hazard the probe could not see: the plain image URL is cached with Vary ignored.
	first="$(header_value Content-Type "$(headers_of "${url}" "${CHROME_ACCEPT}" "${CHROME_UA}")")"
	second="$(header_value Content-Type "$(headers_of "${url}" '*/*' 'Microsoft Office/16.0 (Windows NT 10.0; Microsoft Outlook 16.0.17928; Pro)')")"
	expect "plain URL: Chrome first" "${first}" image/avif
	expect "plain URL: then Outlook gets the cached AVIF" "${second}" image/avif

	retest >/dev/null
	expect "retest: mode" "$(delivery mode)" none
	expect "retest: reason" "$(delivery reason)" cdn_unproven
	expect "retest: generated .avif deleted" "$(avif_files)" ''
	finish_stack
}

scenario_cdn_vary() {
	local id rel
	setup cdn-vary

	module activate
	expect "activation: mode" "$(delivery mode)" nginx
	expect "activation: CDN" "$(delivery cdn)" cloudflare
	expect "activation: reason" "$(delivery reason)" ok
	expect_match "activation: proven by cache hits on repeated variants" "$(delivery detail)" 'cache status per request: [A-Z-]+ [A-Z-]+ [A-Z-]+ [A-Z-]+ HIT'

	id="$(upload photo-p3.jpg)"
	uploaded "${id}" || return 0
	rel="$(attached_file "${id}")"
	"${RUN}" make-avif cdn-vary "${rel}" >/dev/null
	check_matrix "processed media through the proxy" "${SITE}/wp-content/uploads/${rel}"
	finish_stack
}

# --- Main -----------------------------------------------------------------------------

[ "$#" -ge 1 ] || usage
target="$1"
shift
DOWN=0
for arg in "$@"; do
	case "${arg}" in
		--down) DOWN=1 ;;
		*) usage ;;
	esac
done
command -v jq >/dev/null 2>&1 || {
	echo "error: jq is required" >&2
	exit 1
}

case "${target}" in
	nginx) scenario_nginx ;;
	nginx-plain) scenario_nginx_plain ;;
	apache) scenario_apache ;;
	ols) scenario_ols ;;
	cdn) scenario_cdn ;;
	cdn-noquery) scenario_cdn_noquery ;;
	cdn-vary) scenario_cdn_vary ;;
	nginx-novary) scenario_nginx_novary ;;
	all)
		scenario_nginx
		scenario_nginx_plain
		scenario_apache
		scenario_ols
		scenario_cdn
		scenario_cdn_noquery
		scenario_cdn_vary
		scenario_nginx_novary
		;;
	*) usage ;;
esac

if [ "${FAILS}" -gt 0 ]; then
	echo "assert-delivery: ${FAILS} assertion(s) failed" >&2
	exit 1
fi
echo "assert-delivery: all assertions passed"
