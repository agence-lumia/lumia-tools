#!/usr/bin/env bash
#
# Playwright scenarios of the Motion module, against a local Bricks site. See README.md.
#
#   tools/e2e/motion/run.sh setup               # create or replace the lm-e2e-* pages
#   tools/e2e/motion/run.sh <scenario> [...]    # run scenarios/<scenario>.mjs
#   tools/e2e/motion/run.sh all                 # every scenario, in name order
#
# Exit code: 0 when everything passed, 1 on the first failure, 2 on bad usage.

# The node -e programs are JavaScript, not shell: no expansion wanted.
# shellcheck disable=SC2016
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CACHE="${DIR}/.cache"
PLAYWRIGHT_VERSION="1.64.0"

if [ "$#" -eq 0 ]; then
	sed -n '5,7p' "$0" | sed 's/^# \{0,1\}//' >&2
	exit 2
fi

# playwright-core only (no browser download): Chrome comes from the machine.
if [ ! -f "${CACHE}/node_modules/playwright-core/package.json" ]; then
	mkdir -p "${CACHE}"
	npm install --silent --no-audit --no-fund --prefix "${CACHE}" "playwright-core@${PLAYWRIGHT_VERSION}" >&2
fi
mkdir -p "${DIR}/out"

run() {
	node --input-type=module -e '
		const [file, name] = process.argv.slice(1);
		try {
			const mod = await import(file);
			await mod.default();
			console.log(`ok ${name}`);
		} catch (e) {
			console.error(`FAIL ${name}: ${e && e.message ? e.message : e}`);
			process.exit(1);
		}
		process.exit(0);
	' "$1" "$2"
}

if [ "$1" = "setup" ]; then
	node --input-type=module -e '
		const { setup } = await import(process.argv[1]);
		await setup();
		console.log("ok setup");
	' "${DIR}/fixtures.mjs"
	exit 0
fi

if [ "$1" = "all" ]; then
	scenarios=()
	for f in "${DIR}"/scenarios/*.mjs; do
		[ -e "$f" ] || continue
		scenarios+=("$(basename "$f" .mjs)")
	done
	[ "${#scenarios[@]}" -gt 0 ] || { echo "error: no scenario found" >&2; exit 2; }
	set -- "${scenarios[@]}"
fi

for name in "$@"; do
	file="${DIR}/scenarios/${name}.mjs"
	[ -f "$file" ] || { echo "error: no scenario ${name}" >&2; exit 2; }
	run "$file" "$name"
done
