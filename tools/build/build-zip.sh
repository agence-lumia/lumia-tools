#!/usr/bin/env bash
#
# Builds the release zip of the plugin: tools/build/build-zip.sh <version> <output-zip>
#
# The single build used by both release workflows (release-dev.yml, release-please.yml):
#
#   1. copies the working tree into a staging lumia-tools/ folder, without what
#      tools/build/zip-excludes.txt lists and without the .po / .pot sources;
#   2. compiles each languages/*.po into a .l10n.php next to the .mo (WordPress >= 6.5
#      reads the PHP file first, the .mo stays as a fallback);
#   3. minifies every assets/**/*.js and *.css except *.min.*, in place, with a pinned
#      esbuild in transform mode (no bundling, no --format: top-level names and every
#      property name are kept, the files stay plain browser scripts);
#   4. checks the result, then zips it with lumia-tools/ as the top folder.
#
# <version> is only used in messages: the version bump of lumia-tools.php is done by the
# workflows before calling this script. Needs bash, rsync, zip, unzip, php (or wp) and
# node/npx. No PHP on the machine: run it through Docker, see docs/core.md.
#
# Exit code: 0 on success, 1 on any failure, 2 on bad usage.

set -euo pipefail

ESBUILD_VERSION="0.28.2"
PLUGIN_SLUG="lumia-tools"

die() {
	echo "build-zip: error: $*" >&2
	exit 1
}

[ "$#" -eq 2 ] || { echo "usage: tools/build/build-zip.sh <version> <output-zip>" >&2; exit 2; }
version="$1"
output="$2"
[ -n "${version}" ] || { echo "build-zip: empty version" >&2; exit 2; }

for tool in rsync zip unzip node npx; do
	command -v "${tool}" >/dev/null 2>&1 || die "${tool} not found"
done

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
excludes="${repo_dir}/tools/build/zip-excludes.txt"
[ -f "${excludes}" ] || die "missing ${excludes}"

# The output path is resolved before leaving the caller's directory.
mkdir -p "$(dirname "${output}")"
output="$(cd "$(dirname "${output}")" && pwd)/$(basename "${output}")"
case "${output}" in
	*.zip) ;;
	*) die "the output must be a .zip: ${output}" ;;
esac

cd "${repo_dir}"

work="$(mktemp -d "${TMPDIR:-/tmp}/lumia-build.XXXXXX")"
trap 'rm -rf "${work}"' EXIT
stage="${work}/${PLUGIN_SLUG}"
mkdir -p "${stage}"

# bytes <path>...: total size of the files found under the paths (0 when none).
bytes() {
	find "$@" -type f -print0 2>/dev/null | xargs -0 cat 2>/dev/null | wc -c | tr -d ' '
}

kb() {
	awk -v b="$1" 'BEGIN { printf "%.1f KB", b / 1024 }'
}

echo "Building ${PLUGIN_SLUG} ${version}"

# 1. Copy. The .po / .pot are translation sources: only the compiled files are read.
rsync -a --exclude-from="${excludes}" --exclude='/languages/*.po' --exclude='/languages/*.pot' ./ "${stage}/"
languages_dropped="$(bytes languages/*.po languages/*.pot)"
plugin_before=$(( $(bytes "${stage}") + languages_dropped ))

# 2. PHP translation files, one per .po of the working tree.
po_files=()
while IFS= read -r -d '' po; do
	po_files+=("${po}")
done < <(find languages -maxdepth 1 -type f -name '*.po' -print0 | sort -z)
[ "${#po_files[@]}" -gt 0 ] || die "no languages/*.po to compile"

for po in "${po_files[@]}"; do
	sh tools/i18n/wp.sh i18n make-php "${po}" "${stage}/languages" >/dev/null \
		|| die "wp i18n make-php failed on ${po}"
done

# 3. Minification. Transform mode (stdin): one file at a time, nothing is bundled or
# wrapped, so the globals each script reads and writes through window are untouched.
assets=()
while IFS= read -r -d '' asset; do
	assets+=("${asset}")
done < <(find "${stage}/assets" -type f \( -name '*.js' -o -name '*.css' \) ! -name '*.min.*' -print0 | sort -z)
[ "${#assets[@]}" -gt 0 ] || die "no asset to minify under assets/"

assets_before="$(bytes "${assets[@]}")"
for asset in "${assets[@]}"; do
	loader="${asset##*.}"
	npx --yes "esbuild@${ESBUILD_VERSION}" --minify --loader="${loader}" --log-level=warning \
		< "${asset}" > "${asset}.min.tmp" \
		|| die "esbuild failed on ${asset#"${stage}/"}"
	[ -s "${asset}.min.tmp" ] || die "esbuild produced an empty ${asset#"${stage}/"}"
	mv "${asset}.min.tmp" "${asset}"
	if [ "${loader}" = js ]; then
		node --check "${asset}" || die "minified ${asset#"${stage}/"} does not parse"
	fi
done
assets_after="$(bytes "${assets[@]}")"

# 4. Checks on the staging copy.
missing=()
while IFS= read -r -d '' file; do
	[ -s "${stage}/${file}" ] || missing+=("${file}")
done < <(find assets -type f ! -name '.DS_Store' -print0)
for file in lumia-tools.php uninstall.php includes templates; do
	[ -e "${stage}/${file}" ] || missing+=("${file}")
done
for po in "${po_files[@]}"; do
	base="${stage}/languages/$(basename "${po}" .po)"
	[ -s "${base}.mo" ] || missing+=("${base#"${stage}/"}.mo")
	[ -s "${base}.l10n.php" ] || missing+=("${base#"${stage}/"}.l10n.php")
done
[ "${#missing[@]}" -eq 0 ] || die "missing from the build: ${missing[*]}"

if command -v php >/dev/null 2>&1; then
	for po in "${po_files[@]}"; do
		l10n="${stage}/languages/$(basename "${po}" .po).l10n.php"
		# shellcheck disable=SC2016 # PHP code: the single quotes are on purpose.
		php -r '$t = include $argv[1]; exit( is_array( $t ) && ! empty( $t["messages"] ) ? 0 : 1 );' "${l10n}" \
			|| die "${l10n#"${stage}/"} holds no translation"
	done
fi

# 5. Zip, then check what it really holds.
rm -f "${output}"
(cd "${work}" && zip -qrX "${output}" "${PLUGIN_SLUG}")

entries="$(unzip -Z1 "${output}")"
outside="$(grep -v "^${PLUGIN_SLUG}/" <<<"${entries}" || true)"
[ -z "${outside}" ] || die "entries outside ${PLUGIN_SLUG}/: ${outside}"
# Root vendor/ only: assets/admin/js/vendor/ is shipped on purpose.
forbidden="$(grep -E "^${PLUGIN_SLUG}/(tools|docs|vendor|node_modules|\.git|\.github)/|\.(po|pot)$|(^|/)e2e-auth\.php$|^${PLUGIN_SLUG}/(composer\.(json|lock)|CLAUDE\.md)$" <<<"${entries}" || true)"
if [ -n "${forbidden}" ]; then
	rm -f "${output}"
	die "development files ended up in the zip: ${forbidden}"
fi

languages_added="$(bytes "${stage}"/languages/*.l10n.php)"

echo "  JS/CSS     ${#assets[@]} files: $(kb "${assets_before}") -> $(kb "${assets_after}")"
echo "  Languages  .po/.pot left out: $(kb "${languages_dropped}"), .l10n.php added: $(kb "${languages_added}")"
echo "  Plugin     unpacked: $(kb "${plugin_before}") -> $(kb "$(bytes "${stage}")"), $(grep -c . <<<"${entries}") zip entries"
echo "  Zip        ${output} ($(kb "$(wc -c < "${output}" | tr -d ' ')"))"
