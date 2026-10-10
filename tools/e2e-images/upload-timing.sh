#!/usr/bin/env bash
#
# Background AVIF queue, as an administrator and a visitor see it (QueueRunner; spec 3, 9.1,
# 9.6). On a stack where the delivery self-test passes:
#
#   1. upload response time: photo-4000.jpg through async-upload.php without the plugin, then
#      with it: the upload must not wait for the encoding (less than the plain upload + 1 s);
#   2. the uploaded -scaled.jpg, polled every 500 ms with Chrome's Accept: the JPEG first
#      (never a 404), then the AVIF in less than 30 s;
#   3. (nginx) the template's cron container (CLI image, no codec) runs the drain hook: it
#      encodes nothing itself, the AVIF is written later by PHP-FPM (worker recorded in
#      _lumia_avif);
#   4. (nginx, unless --skip-bulk) page latency while a bulk of 20 photo-4000.jpg is processed,
#      against the idle latency (printed; spec 9.6 expects less than twice the idle median).
#
# usage: upload-timing.sh <nginx|apache> [--down] [--skip-bulk]
#   --down       stop and delete the stack afterwards
#   --skip-bulk  skip step 4 (it imports 20 images of 11 MB)
# Needs what run.sh needs, plus jq. Exit code: 0 when every assertion passed, 1 otherwise,
# 2 on bad usage.

set -euo pipefail

E2E_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RUN="${E2E_DIR}/run.sh"
OUT_DIR="${E2E_DIR}/out"

# shellcheck source=lib.sh
. "${E2E_DIR}/lib.sh"

FAILS=0
STACK=''
SITE=''
JAR=''
NONCE=''
DOWN=0
BULK=1

usage() {
	sed -n '/^# usage:/,/^# 2 on bad usage/p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//' >&2
	exit 2
}

pass() { echo "  ok    $*"; }
fail() {
	echo "  FAIL  $*" >&2
	FAILS=$((FAILS + 1))
}
info() { echo "  info  $*"; }

wpx() {
	"${RUN}" wp "${STACK}" "$@" | tr -d '\r'
}

# Logs in with real cookies; reads the plugin's admin nonce when the plugin is active.
login() {
	local page
	curl -s -o /dev/null -c "${JAR}" "${SITE}/wp-login.php" || true
	curl -s -o /dev/null -b "${JAR}" -c "${JAR}" \
		--data-urlencode log=admin --data-urlencode pwd=admin --data-urlencode testcookie=1 \
		"${SITE}/wp-login.php" || true
	page="$(curl -s -b "${JAR}" "${SITE}/wp-admin/admin.php?page=lumia-tools" || true)"
	NONCE="$(grep -oE '"nonce":"[0-9a-f]+"' <<<"${page}" | head -n 1 | cut -d'"' -f4 || true)"
}

ajax() { # ajax <action> [key=value...]
	local args=(-s -b "${JAR}" --max-time 300 --data-urlencode "action=$1" --data-urlencode "nonce=${NONCE}") kv
	shift
	for kv in "$@"; do
		args+=(--data-urlencode "${kv}")
	done
	curl "${args[@]}" "${SITE}/wp-admin/admin-ajax.php" || true
}

# upload <fixture> <name>: async-upload.php as the media library does it. Prints
# "<seconds> <id> <url>" (id and url empty on failure).
upload() {
	local page wpnonce out body seconds
	page="$(curl -s -b "${JAR}" "${SITE}/wp-admin/media-new.php" || true)"
	wpnonce="$(grep -oE '"_wpnonce":"[0-9a-f]+"' <<<"${page}" | head -n 1 | cut -d'"' -f4 || true)"
	out="$(curl -s -b "${JAR}" --max-time 300 -w '\n%{time_total}' \
		-F "async-upload=@${OUT_DIR}/fixtures/$1" -F "name=$2" -F action=upload-attachment -F "_wpnonce=${wpnonce}" \
		"${SITE}/wp-admin/async-upload.php" || true)"
	seconds="$(tail -n 1 <<<"${out}")"
	body="$(sed '$d' <<<"${out}")"
	echo "${seconds} $(jq -r '.data.id // empty' <<<"${body}" 2>/dev/null || true) $(jq -r '.data.url // empty' <<<"${body}" 2>/dev/null || true)"
}

# chrome_type <url>: "<status> <content-type>" for Chrome.
chrome_type() {
	local headers type
	headers="$(headers_of "$1" "${CHROME_ACCEPT}" "${CHROME_UA}")"
	type="$(header_value Content-Type "${headers}")"
	echo "$(status_of "${headers}") ${type%%;*}"
}

state() { # state <id> <field>: a field of AvifState::get()
	wpx eval "wp_cache_delete( $1, 'post_meta' ); \$s = Lumia\\Tools\\Modules\\ImageOptimizer\\AvifState::get( $1 ); echo is_array( \$s['$2'] ) ? wp_json_encode( \$s['$2'] ) : \$s['$2'];"
}

wait_done() { # wait_done <id> <seconds>: until the status leaves pending / processing
	local end=$((SECONDS + $2)) status
	while [ "${SECONDS}" -lt "${end}" ]; do
		status="$(state "$1" status)"
		case "${status}" in
			pending | processing) sleep 1 ;;
			*) echo "${status}"; return 0 ;;
		esac
	done
	echo timeout
}

# --- Steps ---------------------------------------------------------------------------

setup() {
	echo "== ${STACK} (${SITE})"
	"${RUN}" up "${STACK}" >/dev/null
	"${RUN}" fixtures "${STACK}" >/dev/null
	"${RUN}" install-lumia "${STACK}" >/dev/null 2>&1
	JAR="$(mktemp)"
}

step_upload_timing() {
	local plain1 plain2 with id url t0 now seq last first avif_at='' row status type base

	# Without the plugin.
	wpx plugin deactivate lumia-tools >/dev/null 2>&1
	login
	read -r plain1 _ _ <<<"$(upload photo-4000.jpg "timing-plain-a-$$.jpg")"
	read -r plain2 _ _ <<<"$(upload photo-4000.jpg "timing-plain-b-$$.jpg")"
	base="$(awk -v a="${plain1:-0}" -v b="${plain2:-0}" 'BEGIN { print (a < b ? a : b) }')"
	info "upload of photo-4000.jpg without the plugin: ${plain1:-?} s, ${plain2:-?} s (best ${base} s)"

	# With the plugin, the module on and the delivery self-test passing.
	wpx plugin activate lumia-tools >/dev/null 2>&1
	login
	[ -n "${NONCE}" ] || { fail "could not read the plugin's admin nonce"; return 0; }
	ajax lumia_ajax_toggle_module module=image_optimizer lumia_action=activate >/dev/null
	ajax lumia_image_optimizer_delivery_retest >/dev/null
	mode="$(wpx eval 'echo Lumia\Tools\Modules\ImageOptimizer\DeliveryProbe::result()["mode"];')"
	if [ "${mode}" = none ]; then
		fail "delivery self-test not passing on ${STACK} (mode none): nothing to time"
		return 0
	fi
	pass "delivery served (${mode})"

	read -r with id url <<<"$(upload photo-4000.jpg "timing-$$.jpg")"
	t0="$(date +%s.%N 2>/dev/null || date +%s)"
	if ! [[ "${id}" =~ ^[0-9]+$ ]]; then
		fail "upload with the plugin: no attachment ID"
		return 0
	fi
	UPLOAD_ID="${id}"
	info "upload of photo-4000.jpg with the plugin: ${with} s (#${id}, ${url})"
	if awk -v w="${with}" -v b="${base}" 'BEGIN { exit !(w < b + 1) }'; then
		pass "upload response not held by the encoding (${with} s < ${base} s + 1 s)"
	else
		fail "upload response ${with} s, not below ${base} s + 1 s: the encoding is in the request"
	fi
	if [[ "${url}" == *-scaled.jpg ]]; then pass "attachment URL is the -scaled.jpg"; else fail "attachment URL is not -scaled.jpg: ${url}"; fi

	# Visitor's view: the JPEG at once, the AVIF once the queue is through.
	seq=''
	first=''
	for _ in $(seq 1 61); do
		row="$(chrome_type "${url}")"
		read -r status type <<<"${row}"
		[ -n "${first}" ] || first="${status} ${type}"
		if [ "${status}" != 200 ]; then
			seq+=" ${status}"
			break
		fi
		[ "${type}" = "${last:-}" ] || seq+=" ${type}"
		last="${type}"
		if [ "${type}" = image/avif ]; then
			now="$(date +%s.%N 2>/dev/null || date +%s)"
			avif_at="$(awk -v a="${now}" -v b="${t0}" 'BEGIN { printf "%.1f", a - b }')"
			break
		fi
		sleep 0.5
	done
	info "Chrome on the -scaled.jpg:${seq}"
	if [ "${first}" = "200 image/jpeg" ]; then pass "first answer: the JPEG (200)"; else fail "first answer: expected 200 image/jpeg, got ${first}"; fi
	if grep -qE '(^| )[0-9]{3}( |$)' <<<"${seq}"; then fail "an error status was served while the queue worked:${seq}"; else pass "never an error status while waiting"; fi
	if [ -n "${avif_at}" ] && awk -v t="${avif_at}" 'BEGIN { exit !(t < 30) }'; then
		pass "AVIF served ${avif_at} s after the upload response (< 30 s)"
	else
		fail "no AVIF within 30 s of the upload response"
	fi
	info "worker: $(state "${id}" worker), status $(state "${id}" status)"
}

step_cron_container() {
	local id="${UPLOAD_ID:-}" out elapsed cli_pid sapi exists status worker mtime started
	[ -n "${id}" ] || { fail "cron container: no media from the upload step"; return 0; }

	# Queued again with its siblings gone, without firing the action (no trigger from here).
	wpx eval "
		\$m = Lumia\\Tools\\Core\\Plugin::instance()->modules->get_active_instances()['image_optimizer'];
		\$m->get_lifecycle()->delete_siblings( ${id} );
		Lumia\\Tools\\Modules\\ImageOptimizer\\AvifState::enqueue( ${id}, 'manual' );" >/dev/null
	started="$(date +%s)"
	out="$("${RUN}" wp-cron "${STACK}" eval "
		\$t = microtime( true );
		do_action( 'lumia_image_optimizer_drain' );
		\$f = get_attached_file( ${id} ) . '.avif';
		clearstatcache();
		printf( '%s %d %.3f %s', PHP_SAPI, getmypid(), microtime( true ) - \$t, file_exists( \$f ) ? 'yes' : 'no' );" 2>/dev/null | tr -d '\r' | tail -n 1 || true)"
	read -r sapi cli_pid elapsed exists <<<"${out}"
	info "cron container: sapi ${sapi:-?}, pid ${cli_pid:-?}, drain hook returned in ${elapsed:-?} s, sibling right after: ${exists:-?}"
	if [ "${sapi}" = cli ] && awk -v e="${elapsed:-99}" 'BEGIN { exit !(e < 2) }'; then
		pass "cron container: the drain hook returns at once (no encoding in the CLI)"
	else
		fail "cron container: the drain hook took ${elapsed:-?} s (or did not run: '${out}')"
	fi

	status="$(wait_done "${id}" 60)"
	worker="$(state "${id}" worker)"
	mtime="$(wpx eval "clearstatcache(); \$f = get_attached_file( ${id} ) . '.avif'; echo file_exists( \$f ) ? filemtime( \$f ) : 0;")"
	if [[ "${status}" =~ ^(done|partial)$ ]] && [[ "${worker}" =~ ^fpm-fcgi:[0-9]+$ ]] && [ "${worker#fpm-fcgi:}" != "${cli_pid}" ]; then
		pass "AVIF produced by PHP-FPM after the cron trigger (${status}, worker ${worker})"
	else
		fail "after the cron trigger: status ${status}, worker '${worker}' (expected fpm-fcgi:<pid>)"
	fi
	if [ "${mtime:-0}" -ge "${started}" ]; then pass ".avif written after the cron run started (${mtime} >= ${started})"; else fail ".avif mtime ${mtime:-0} before the cron run (${started})"; fi
}

step_bulk_latency() {
	local idle busy ids queued i saved
	idle="$("${RUN}" latency "${STACK}" / 30 | tail -n 1)"
	info "idle: ${idle}"

	# 20 photo-4000.jpg without state (automatic processing off during the import), then
	# queued as a bulk: the worker pauses after each image as long as the image took.
	saved="$(wpx eval 'echo wp_json_encode( get_option( "lumia_module_image_optimizer", null ) );')"
	wpx eval '$o = (array) get_option( "lumia_module_image_optimizer", [] ); $o["optimize_on_upload"] = false; update_option( "lumia_module_image_optimizer", $o );' >/dev/null
	ids=''
	for i in $(seq 1 20); do
		ids+="$(wpx media import "/bench/out/fixtures/photo-4000.jpg" --title="bulk-${i}" --porcelain 2>/dev/null | tail -n 1) "
	done
	wpx eval "\$o = json_decode( '${saved}', true ); if ( is_array( \$o ) ) { update_option( 'lumia_module_image_optimizer', \$o ); } else { delete_option( 'lumia_module_image_optimizer' ); }" >/dev/null
	queued="$(wpx eval '$q = Lumia\Tools\Core\Plugin::instance()->modules->get_active_instances()["image_optimizer"]->get_queue(); echo $q->enqueue_all_eligible( "bulk" ); $q->trigger();')"
	info "bulk: ${queued} media queued (origin bulk)"
	sleep 3
	busy="$("${RUN}" latency "${STACK}" / 30 | tail -n 1)"
	info "during the bulk: ${busy}"
	info "bulk progress after 30 s: $(wpx eval 'echo wp_json_encode( Lumia\Tools\Modules\ImageOptimizer\AvifState::count_by_status() );')"

	local m_idle m_busy
	m_idle="$(sed -E 's/.*median ([0-9]+) ms.*/\1/' <<<"${idle}")"
	m_busy="$(sed -E 's/.*median ([0-9]+) ms.*/\1/' <<<"${busy}")"
	if [ "${m_busy:-0}" -lt $((2 * ${m_idle:-0} + 1)) ]; then
		pass "median during the bulk ${m_busy} ms < 2 x idle ${m_idle} ms"
	else
		info "median during the bulk ${m_busy} ms >= 2 x idle ${m_idle} ms (spec 9.6: recorded, not blocking)"
	fi

	# shellcheck disable=SC2086 # word splitting of the ID list is intended.
	wpx post delete ${ids} --force >/dev/null 2>&1 || true
}

# --- Main ------------------------------------------------------------------------------

STACK="${1:-}"
[ -n "${STACK}" ] || usage
shift
for arg in "$@"; do
	case "${arg}" in
		--down) DOWN=1 ;;
		--skip-bulk) BULK=0 ;;
		*) usage ;;
	esac
done
case "${STACK}" in
	nginx) SITE=http://localhost:8091 ;;
	apache) SITE=http://localhost:8092 ;;
	*) usage ;;
esac
command -v jq >/dev/null 2>&1 || {
	echo "error: jq is required" >&2
	exit 1
}

UPLOAD_ID=''
setup
step_upload_timing
if [ "${STACK}" = nginx ]; then
	step_cron_container
	[ "${BULK}" = 0 ] || step_bulk_latency
fi

rm -f "${JAR}"
[ "${DOWN}" = 0 ] || "${RUN}" down "${STACK}" >/dev/null 2>&1 || true

if [ "${FAILS}" -gt 0 ]; then
	echo "upload-timing: ${FAILS} assertion(s) failed" >&2
	exit 1
fi
echo "upload-timing: all assertions passed"
