#!/usr/bin/env bash
#
# Helpers of the image delivery bench: the client matrix, content-type and Vary assertions,
# the curl matrix and the page latency probe. Sourced by run.sh; also usable on its own
# against any URL (source it, then call assert_type / assert_vary / curl_matrix).
# Needs only bash and curl.

# --- Client matrix -----------------------------------------------------------
#
# name | Accept | User-Agent | AVIF expected when a sibling exists (1) or not (0)
#
# The Accept values are the real ones for browsers (Chrome, Safari 17, Firefox). Mail
# clients, proxies and bots send `*/*` or `image/*`: none of them announces image/avif,
# so none must ever receive one. The User-Agent is only informative (the server decides on
# Accept alone), but it is sent so that a rule keyed on it would show up in the bench.
CHROME_ACCEPT='image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8'
SAFARI_ACCEPT='image/webp,image/avif,image/jxl,image/heic,image/heic-sequence,video/*;q=0.8,image/png,image/svg+xml,image/*;q=0.8,*/*;q=0.5'
FIREFOX_ACCEPT='image/avif,image/webp,image/png,image/svg+xml,image/*;q=0.8,*/*;q=0.5'

CHROME_UA='Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36'
SAFARI_UA='Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Safari/605.1.15'
FIREFOX_UA='Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:143.0) Gecko/20100101 Firefox/143.0'

# Apple Mail renders with WebKit; its Accept header was not measured: Safari's is assumed.
CLIENTS=(
	"Chrome|${CHROME_ACCEPT}|${CHROME_UA}|1"
	"Safari 17|${SAFARI_ACCEPT}|${SAFARI_UA}|1"
	"Firefox|${FIREFOX_ACCEPT}|${FIREFOX_UA}|1"
	"Apple Mail (assumed)|${SAFARI_ACCEPT}|Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko)|1"
	"Outlook Windows|*/*|Microsoft Office/16.0 (Windows NT 10.0; Microsoft Outlook 16.0.17928; Pro)|0"
	"GoogleImageProxy|*/*|Mozilla/5.0 (Windows NT 5.1; rv:11.0) Gecko Firefox/11.0 (via ggpht.com GoogleImageProxy)|0"
	"Googlebot-Image|image/*|Googlebot-Image/1.0|0"
	"Storebot-Google|*/*|Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko; compatible; Storebot-Google/1.0) Chrome/141.0.0.0 Safari/537.36|0"
	"Google-Shopping|*/*|Google-Shopping/1.0|0"
	"facebookexternalhit|*/*|facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)|0"
	"LinkedInBot|*/*|LinkedInBot/1.0 (compatible; Mozilla/5.0; Apache-HttpClient +http://www.linkedin.com)|0"
	"Mailchimp (stand-in)|*/*|Mozilla/5.0 (compatible; Mailchimp; +https://mailchimp.com)|0"
	"Brevo (stand-in)|*/*|Mozilla/5.0 (compatible; Brevo; +https://www.brevo.com)|0"
	"curl|*/*|curl/8.7.1|0"
)

# --- Single-request helpers --------------------------------------------------

# headers_of <url> <accept> <user-agent>: response headers (CRLF stripped), body discarded.
headers_of() {
	curl -s -o /dev/null -D - --max-time 30 -A "$3" -H "Accept: $2" "$1" | tr -d '\r'
}

# header_value <name> <headers>: value of the last occurrence of a header (case-insensitive).
header_value() {
	local name="$1" headers="$2"
	awk -F': ' -v n="$(tr '[:upper:]' '[:lower:]' <<<"${name}")" \
		'tolower($1) == n { sub(/^[^:]*: */, ""); v = $0 } END { print v }' <<<"${headers}"
}

# status_of <headers>: HTTP status code of the last response in the dump.
status_of() {
	awk '/^HTTP\// { s = $2 } END { print s }' <<<"$1"
}

# assert_type <url> <accept> <user-agent> <expected-content-type>
# Exit code 1 and a message on stderr when the response is not the expected type (or not 200).
assert_type() {
	local url="$1" accept="$2" ua="$3" expected="$4" headers status type
	headers="$(headers_of "${url}" "${accept}" "${ua}")"
	status="$(status_of "${headers}")"
	type="$(header_value Content-Type "${headers}")"
	type="${type%%;*}"
	if [ "${status}" != 200 ] || [ "${type}" != "${expected}" ]; then
		echo "FAIL assert_type ${url}: expected 200 ${expected}, got ${status:-none} ${type:-none} (Accept: ${accept}; UA: ${ua})" >&2
		return 1
	fi
}

# assert_vary <url>: the response carries `Vary: Accept` (alone or in a list).
assert_vary() {
	local url="$1" headers vary
	headers="$(headers_of "${url}" '*/*' 'curl/8.7.1')"
	vary="$(header_value Vary "${headers}")"
	if ! grep -qiE '(^|,[[:space:]]*)accept([[:space:]]*,|$)' <<<"${vary}"; then
		echo "FAIL assert_vary ${url}: Vary is '${vary:-absent}'" >&2
		return 1
	fi
}

# base_type <url>: image/jpeg or image/png from the extension (query string ignored).
base_type() {
	local path="${1%%\?*}"
	case "$(tr '[:upper:]' '[:lower:]' <<<"${path}")" in
		*.jpg | *.jpeg) echo image/jpeg ;;
		*.png) echo image/png ;;
		*) echo "unknown" ;;
	esac
}

# --- Curl matrix --------------------------------------------------------------

# curl_matrix <url> [avif|original|none]
# Prints one row per client, then a `Chrome + ?original` row. Expectation:
#   avif      clients that announce image/avif get image/avif, the others the JPEG/PNG,
#             `?original` the JPEG/PNG; every response carries Vary: Accept;
#   original  everyone gets the JPEG/PNG; every response carries Vary: Accept;
#   none      (default) only prints.
# Exit code 1 when an expectation fails.
curl_matrix() {
	local url="$1" mode="${2:-none}" base failures=0 row name accept ua avif headers status type vary cl expected verdict
	local sep='?'

	case "${mode}" in avif | original | none) ;; *)
		echo "curl_matrix: mode must be avif, original or none" >&2
		return 2
		;;
	esac
	base="$(base_type "${url}")"
	[[ "${url}" == *\?* ]] && sep='&'

	printf '%-22s %-6s %-11s %-10s %-24s %s\n' CLIENT STATUS TYPE LENGTH VARY VERDICT
	row_check() { # row_check <label> <accept> <ua> <url> <expected type or ->
		headers="$(headers_of "$4" "$2" "$3")"
		status="$(status_of "${headers}")"
		type="$(header_value Content-Type "${headers}")"
		type="${type%%;*}"
		vary="$(header_value Vary "${headers}")"
		cl="$(header_value Content-Length "${headers}")"
		verdict=''
		if [ "$5" != - ]; then
			verdict=ok
			[ "${status}" = 200 ] && [ "${type}" = "$5" ] || verdict="FAIL (expected $5)"
			grep -qiE '(^|,[[:space:]]*)accept([[:space:]]*,|$)' <<<"${vary}" || verdict="${verdict/ok/FAIL}; no Vary: Accept"
			[ "${verdict}" = ok ] || failures=$((failures + 1))
		fi
		printf '%-22s %-6s %-11s %-10s %-24s %s\n' "$1" "${status:--}" "${type:--}" "${cl:--}" "${vary:--}" "${verdict}"
	}

	for row in "${CLIENTS[@]}"; do
		IFS='|' read -r name accept ua avif <<<"${row}"
		expected=-
		case "${mode}" in
			original) expected="${base}" ;;
			avif) if [ "${avif}" = 1 ]; then expected=image/avif; else expected="${base}"; fi ;;
		esac
		row_check "${name}" "${accept}" "${ua}" "${url}" "${expected}"
	done

	expected=-
	[ "${mode}" = none ] || expected="${base}"
	row_check 'Chrome + ?original' "${CHROME_ACCEPT}" "${CHROME_UA}" "${url}${sep}original" "${expected}"

	if [ "${failures}" -gt 0 ]; then
		echo "curl-matrix: ${failures} row(s) failed" >&2
		return 1
	fi
}

# --- Latency ------------------------------------------------------------------

# latency_probe <url> <seconds>: sequential requests for <seconds>, then count, median, p95
# and max of the total time in milliseconds. Meant to be run while a bulk is processing,
# to measure what the visitors feel (spec 9.6).
latency_probe() {
	local url="$1" seconds="$2" end times n
	[[ "${seconds}" =~ ^[0-9]+$ ]] && [ "${seconds}" -gt 0 ] || { echo "latency: <seconds> must be a positive integer" >&2; return 2; }

	end=$((SECONDS + seconds))
	times=''
	while [ "${SECONDS}" -lt "${end}" ]; do
		times+="$(curl -s -o /dev/null --max-time 60 -w '%{time_total}' "${url}") "
		times+=$'\n'
		sleep 0.2
	done

	n="$(grep -c . <<<"${times}")"
	grep . <<<"${times}" | sort -n | awk -v n="${n}" '
		{ v[NR] = $1 * 1000 }
		END {
			m = int((n + 1) / 2); p = int(n * 0.95); if (p < 1) p = 1
			printf "latency: %d requests, median %.0f ms, p95 %.0f ms, max %.0f ms\n", n, v[m], v[p], v[n]
		}'
}
