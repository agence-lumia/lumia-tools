#!/usr/bin/env bash
#
# Crawls a bench site like a visitor: every page of the WordPress sitemap (/wp-sitemap.xml),
# every image it references (src, srcset, url() in the page and in the stylesheets it links),
# each image fetched twice: with Chrome's Accept, then with `Accept: */*` (mail clients,
# bots). Every image must answer 200 with an image type consistent with its URL and the
# Accept header: a .jpg/.png may be AVIF for Chrome only, never for */*; an .avif / .webp URL
# is its own type for both. Bench only, never shipped.
#
#   tools/e2e-images/crawl-check.sh <stack|http://site>
#
# Exit code: 0 all good, 1 a failing image (or nothing found), 2 usage.

set -euo pipefail

E2E_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
. "${E2E_DIR}/lib.sh"

case "${1:-}" in
	nginx) SITE=http://localhost:8091 ;;
	apache) SITE=http://localhost:8092 ;;
	ols) SITE=http://localhost:8093 ;;
	cdn) SITE=http://localhost:8094 ;;
	nginx-plain) SITE=http://localhost:8095 ;;
	cdn-noquery) SITE=http://localhost:8097 ;;
	cdn-vary) SITE=http://localhost:8099 ;;
	nginx-novary) SITE=http://localhost:8101 ;;
	nginx-mig) SITE=http://localhost:8102 ;;
	http://* | https://*) SITE="${1%/}" ;;
	*)
		echo "usage: crawl-check.sh <stack|http://site>" >&2
		exit 2
		;;
esac

fetch() { # fetch <url>: body, empty on error
	curl -fsS --max-time 30 "$1" 2>/dev/null || true
}

# absolute <url> <base-url>: absolute form of a URL found in a page or a stylesheet.
absolute() {
	local url="$1" base="$2"
	url="${url//&amp;/&}"
	case "${url}" in
		http://* | https://*) echo "${url}" ;;
		//*) echo "http:${url}" ;;
		/*) echo "${SITE}${url}" ;;
		data:*) ;;
		*) echo "${base%/*}/${url}" ;;
	esac
}

# Image URLs of an HTML page or a stylesheet: src="", srcset="" (every candidate), url().
extract_images() {
	local text="$1"
	{
		grep -oE '[[:space:]]src="[^"]+"' <<<"${text}" | sed -E 's/^[[:space:]]src="//; s/"$//' || true
		grep -oE '[[:space:]]srcset="[^"]+"' <<<"${text}" | sed -E 's/^[[:space:]]srcset="//; s/"$//' | tr ',' '\n' | awk '{ print $1 }' || true
		grep -oE "url\([^)]+\)" <<<"${text}" | sed -E "s/^url\(['\"]?//; s/['\"]?\)$//" || true
	} | grep -iE '\.(jpe?g|png|gif|webp|avif|svg)(\?[^ ]*)?$' || true
}

stylesheets() {
	grep -oE '<link[^>]+rel=.?stylesheet[^>]*>' <<<"$1" | grep -oE 'href="[^"]+"' | sed -E 's/^href="//; s/"$//' || true
}

own_type() { # type an image URL must have whatever the Accept header
	local path="${1%%\?*}"
	case "$(tr '[:upper:]' '[:lower:]' <<<"${path}")" in
		*.jpg | *.jpeg) echo image/jpeg ;;
		*.png) echo image/png ;;
		*.gif) echo image/gif ;;
		*.webp) echo image/webp ;;
		*.avif) echo image/avif ;;
		*.svg) echo image/svg+xml ;;
		*) echo unknown ;;
	esac
}

# --- Pages from the sitemap ---------------------------------------------------------------

index="$(fetch "${SITE}/wp-sitemap.xml")"
[ -n "${index}" ] || {
	echo "FAIL no sitemap at ${SITE}/wp-sitemap.xml" >&2
	exit 1
}
pages=()
while IFS= read -r sitemap; do
	[ -n "${sitemap}" ] || continue
	while IFS= read -r page; do
		[ -n "${page}" ] && pages+=("${page}")
	done < <(fetch "${sitemap}" | grep -oE '<loc>[^<]+</loc>' | sed -E 's#</?loc>##g' || true)
done < <(grep -oE '<loc>[^<]+</loc>' <<<"${index}" | sed -E 's#</?loc>##g' || true)
pages+=("${SITE}/")

images_file="$(mktemp)"
trap 'rm -f "${images_file}"' EXIT

for page in "${pages[@]}"; do
	html="$(fetch "${page}")"
	if [ -z "${html}" ]; then
		echo "FAIL page ${page}: no response" >&2
		echo "__page_failed__" >>"${images_file}"
		continue
	fi
	while IFS= read -r url; do
		[ -n "${url}" ] && absolute "${url}" "${page}" >>"${images_file}"
	done < <(extract_images "${html}")
	while IFS= read -r css; do
		[ -n "${css}" ] || continue
		css="$(absolute "${css}" "${page}")"
		[[ "${css}" == "${SITE}"* ]] || continue
		while IFS= read -r url; do
			[ -n "${url}" ] && absolute "${url}" "${css}" >>"${images_file}"
		done < <(extract_images "$(fetch "${css}")")
	done < <(stylesheets "${html}")
done

# --- Images --------------------------------------------------------------------------------

failures=0
grep -q '__page_failed__' "${images_file}" && failures=1
checked=0
while IFS= read -r url; do
	[[ "${url}" == "${SITE}"* ]] || continue # other hosts are not ours to judge
	expected="$(own_type "${url}")"

	for client in chrome other; do
		if [ "${client}" = chrome ]; then accept="${CHROME_ACCEPT}" ua="${CHROME_UA}"; else accept='*/*' ua='curl/8.7.1'; fi
		headers="$(headers_of "${url}" "${accept}" "${ua}")"
		status="$(status_of "${headers}")"
		type="$(header_value Content-Type "${headers}")"
		type="${type%%;*}"
		ok=0
		if [ "${status}" = 200 ]; then
			if [ "${type}" = "${expected}" ]; then
				ok=1
			elif [ "${client}" = chrome ] && [ "${type}" = image/avif ] && [[ "${expected}" =~ ^image/(jpeg|png)$ ]]; then
				ok=1 # the negotiated sibling
			elif [ "${expected}" = unknown ] && [[ "${type}" == image/* ]]; then
				ok=1
			fi
		fi
		if [ "${ok}" = 1 ]; then
			printf 'ok   %-6s %s %s %s\n' "${client}" "${status}" "${type}" "${url#"${SITE}"}"
		else
			printf 'FAIL %-6s %s %s %s (expected %s)\n' "${client}" "${status:-none}" "${type:-none}" "${url#"${SITE}"}" "${expected}"
			failures=$((failures + 1))
		fi
	done
	checked=$((checked + 1))
done < <(grep -v '__page_failed__' "${images_file}" | sort -u)

echo "crawl-check: ${#pages[@]} page(s), ${checked} image URL(s), ${failures} failure(s)"
if [ "${checked}" -eq 0 ]; then
	echo "FAIL no image found: nothing was checked" >&2
	exit 1
fi
[ "${failures}" -eq 0 ]
