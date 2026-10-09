#!/bin/sh
# Tests for build-po.php. Run: sh tools/i18n/test.sh
#
# Uses the local php when there is one (CI, composer image). Otherwise it runs
# itself inside wordpress:cli-php8.3 (macOS without php).
set -eu

here=$(cd "$(dirname "$0")" && pwd)
root=$(cd "$here/../.." && pwd)

if ! command -v php >/dev/null 2>&1; then
	command -v docker >/dev/null 2>&1 || { echo "Neither php nor docker found." >&2; exit 2; }
	exec docker run --rm --user "$(id -u):$(id -g)" -v "$root:/app" -w /app \
		wordpress:cli-php8.3 sh tools/i18n/test.sh
fi

tool="$here/build-po.php"
fx="$here/fixtures"
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
failures=0

fail() {
	echo "FAIL: $1" >&2
	failures=$((failures + 1))
}
pass() { echo "ok   $1"; }

# expect_rc <name> <expected rc> <command...>: runs, stores output in $tmp/out.
expect_rc() {
	name=$1
	want=$2
	shift 2
	rc=0
	"$@" >"$tmp/out" 2>&1 || rc=$?
	if [ "$rc" -ne "$want" ]; then
		fail "$name (exit $rc, expected $want)"
		sed 's/^/     | /' "$tmp/out" >&2
		return 1
	fi
	return 0
}
# expect_out <name> <fixed string>: the last output must contain it.
expect_out() {
	if grep -qF -- "$2" "$tmp/out"; then
		pass "$1"
	else
		fail "$1 (output lacks: $2)"
		sed 's/^/     | /' "$tmp/out" >&2
	fi
}

# 1. Collision: build fails and names it, writes nothing.
if expect_rc "collision fails" 1 php "$tool" build "$fx/sample.pot" "$fx/pairs" "$tmp/collision.po"; then
	expect_out "collision names the key" '"button" (context) / "Save"'
	expect_out "collision names both texts" 'Sauvegarder'
	expect_out "collision names the file" 'collision.json'
	[ ! -e "$tmp/collision.po" ] && pass "collision writes nothing" || fail "collision wrote a .po"
fi

# 2. Without the colliding file: success, exact msgstr texts.
mkdir "$tmp/pairs-ok"
cp "$fx/pairs/a.json" "$fx/pairs/b.json" "$tmp/pairs-ok/"
if expect_rc "build succeeds" 0 php "$tool" build "$fx/sample.pot" "$tmp/pairs-ok" "$tmp/out.po"; then
	if cmp -s "$tmp/out.po" "$fx/expected-fr_FR.po"; then
		pass "build output identical to expected-fr_FR.po"
	else
		fail "build output differs from expected-fr_FR.po"
		diff "$fx/expected-fr_FR.po" "$tmp/out.po" | sed 's/^/     | /' >&2 || true
	fi
	# French typography byte for byte: U+00A0 = \302\240, U+202F = \342\200\257.
	printf 'msgstr "Enregistrer"\n' >"$tmp/want"
	printf 'msgstr[0] "%%d fichier t\303\251l\303\251vers\303\251."\n' >>"$tmp/want"
	printf 'msgstr[1] "%%d fichiers t\303\251l\303\251vers\303\251s."\n' >>"$tmp/want"
	printf '"Termin\303\251\302\240: \302\253\302\240cit\303\251\302\240\302\273 texte, anti\\\\slash, tab\\tici\342\200\257!\\n"\n' >>"$tmp/want"
	printf '"Seconde ligne"\n' >>"$tmp/want"
	missing=0
	while IFS= read -r line; do
		grep -qxF -- "$line" "$tmp/out.po" || { fail "expected line absent: $line"; missing=1; }
	done <"$tmp/want"
	[ "$missing" -eq 0 ] && pass "msgstr texts exact (nbsp and narrow nbsp preserved)"
	grep -qxF '#. translators: %d: number of files' "$tmp/out.po" && grep -qxF '#: includes/Media/Module.php:20' "$tmp/out.po" \
		&& pass "#. and #: comments kept" || fail "comments lost"
	grep -qxF '"Language: fr_FR\n"' "$tmp/out.po" && grep -qxF '"Plural-Forms: nplurals=2; plural=(n > 1);\n"' "$tmp/out.po" \
		&& pass "header Language and Plural-Forms" || fail "header fields missing"
fi

# 3. Missing pair: build fails and names the msgid.
mkdir "$tmp/pairs-short"
cp "$fx/pairs/a.json" "$tmp/pairs-short/"
if expect_rc "missing pair fails" 1 php "$tool" build "$fx/sample.pot" "$tmp/pairs-short" "$tmp/short.po"; then
	expect_out "missing pair named" 'msgid without a pair'
	expect_out "missing pair has its msgid" 'Done: \"quoted\"'
	expect_out "missing pair has its reference" 'assets/admin/js/admin.js:30'
fi

# 4. Empty value in a pair file is refused.
mkdir "$tmp/pairs-empty"
printf '{ "button\\u0004Save": "", "%%d file uploaded.|%%d files uploaded.": ["a", "b"] }\n' >"$tmp/pairs-empty/e.json"
cp "$fx/pairs/b.json" "$tmp/pairs-empty/"
expect_rc "empty pair value fails" 1 php "$tool" build "$fx/sample.pot" "$tmp/pairs-empty" "$tmp/e.po" \
	&& expect_out "empty pair value named" 'empty or invalid value'

# 5. check: consistent .po/.mo passes, with msgunfmt (when installed) and with the built-in reader.
expect_rc "check passes" 0 php "$tool" check "$fx/sample.pot" "$fx/expected-fr_FR.po" "$fx/expected-fr_FR.mo" \
	&& pass "check passes"
expect_rc "check passes (built-in .mo reader)" 0 php "$tool" check "$fx/sample.pot" "$fx/expected-fr_FR.po" "$fx/expected-fr_FR.mo" --no-msgunfmt \
	&& pass "check passes (built-in .mo reader)"

# 6. check: an empty msgstr fails.
sed 's/^msgstr "Enregistrer"$/msgstr ""/' "$fx/expected-fr_FR.po" >"$tmp/empty.po"
if expect_rc "check fails on an empty msgstr" 1 php "$tool" check "$fx/sample.pot" "$tmp/empty.po" "$fx/expected-fr_FR.mo"; then
	expect_out "check names the empty msgstr" 'empty msgstr in the .po: "button" (context) / "Save"'
fi

# 7. check: a .mo older than the .po fails (built-in reader and msgunfmt).
sed 's/^msgstr "Enregistrer"$/msgstr "Sauvegarder"/' "$fx/expected-fr_FR.po" >"$tmp/newer.po"
for flag in "" "--no-msgunfmt"; do
	# shellcheck disable=SC2086 # empty flag must vanish.
	if expect_rc "check fails on a stale .mo ($flag)" 1 php "$tool" check "$fx/sample.pot" "$tmp/newer.po" "$fx/expected-fr_FR.mo" $flag; then
		expect_out "check names the stale .mo entry ($flag)" '.mo out of date, different translation'
	fi
done

# 8. check: a msgid missing from the .po fails.
cp "$fx/sample.pot" "$tmp/more.pot"
printf '\n#: includes/New.php:1\nmsgid "Brand new"\nmsgstr ""\n' >>"$tmp/more.pot"
if expect_rc "check fails on a new msgid" 1 php "$tool" check "$tmp/more.pot" "$fx/expected-fr_FR.po" "$fx/expected-fr_FR.mo"; then
	expect_out "check names the missing msgid" 'missing from the .po: "Brand new" [includes/New.php:1]'
fi

# 9. check: a corrupt .mo is an input error, not a pass.
printf 'not a mo file, definitely not a mo file' >"$tmp/bad.mo"
expect_rc "check rejects a corrupt .mo" 2 php "$tool" check "$fx/sample.pot" "$fx/expected-fr_FR.po" "$tmp/bad.mo" --no-msgunfmt \
	&& pass "check rejects a corrupt .mo"

# 10. usage.
expect_rc "usage error" 2 php "$tool" build && pass "usage error"

if [ "$failures" -ne 0 ]; then
	echo "$failures failure(s)." >&2
	exit 1
fi
echo "All i18n tool tests passed."
