// Builds assets/front/js/motion-counter.js: the counter adapter (motion-counter.mjs) and the
// NumberFlow lite build it imports, bundled by esbuild into one classic script.
//   node tools/motion/js/build-counter.mjs           rewrite the file
//   node tools/motion/js/build-counter.mjs --check   exit 1 if the file differs
//
// Why one classic script and not script modules: nginx (1.31 still) has no MIME type for
// .mjs and serves it as application/octet-stream, which browsers refuse for a module script.
// The vendor files stay byte for byte in vendor/ (sources, licenses); only the bundle loads.
// Same pinned esbuild as tools/build/build-zip.sh; run from the repository root so the
// path comments esbuild writes are the same on every machine.
import { execFileSync } from "node:child_process";
import { readFileSync, realpathSync, writeFileSync, mkdtempSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { fileURLToPath } from "node:url";

const ESBUILD_VERSION = "0.28.2";
const root = fileURLToPath(new URL("../../../", import.meta.url));
const out = join(root, "assets/front/js/motion-counter.js");
const vendor = "./assets/front/js/vendor";

const BANNER = `/*!
 * Lümia Tools - Motion counter (generated: do not edit, see tools/motion/js/build-counter.mjs).
 * Source: assets/front/js/motion-counter.mjs, bundled with NumberFlow lite
 * (vendor/number-flow, MIT, see LICENSE.md there) and esm-env (vendor/esm-env, MIT).
 */
`;

export function build() {
	const dir = mkdtempSync(join(tmpdir(), "lm-counter-"));
	const file = join(dir, "motion-counter.js");
	try {
		execFileSync(
			"npx",
			[
				"--yes",
				`esbuild@${ESBUILD_VERSION}`,
				"assets/front/js/motion-counter.mjs",
				"--bundle",
				"--format=iife",
				"--target=es2019",
				"--log-level=warning",
				// Bare imports, resolved as the import map used to: a browser, neither in
				// development nor Node.
				`--alias:number-flow/lite=${vendor}/number-flow/lite.mjs`,
				`--alias:esm-env/browser=${vendor}/esm-env/true.js`,
				`--alias:esm-env/development=${vendor}/esm-env/false.js`,
				`--alias:esm-env/node=${vendor}/esm-env/false.js`,
				`--alias:esm-env=${vendor}/esm-env/index.js`,
				`--outfile=${file}`,
			],
			{ cwd: root, stdio: ["ignore", "inherit", "inherit"] },
		);
		const code = readFileSync(file, "utf8");
		if (/^\s*(import|export)\s/m.test(code)) {
			throw new Error("the bundle still holds an import or export statement");
		}
		return BANNER + code;
	} finally {
		rmSync(dir, { recursive: true, force: true });
	}
}

// Real paths on both sides: a symlinked or relative argv[1] still counts as a direct run.
function isMain() {
	try {
		return !!process.argv[1] && realpathSync(process.argv[1]) === realpathSync(fileURLToPath(import.meta.url));
	} catch {
		return false;
	}
}

const direct = isMain();
if (direct) {
	const next = build();
	if (process.argv.includes("--check")) {
		let current = "";
		try {
			current = readFileSync(out, "utf8");
		} catch {
			// Missing: reported below.
		}
		if (current !== next) {
			console.error("assets/front/js/motion-counter.js is out of date: run node tools/motion/js/build-counter.mjs");
			process.exit(1);
		}
		console.log("motion-counter.js is up to date");
	} else {
		writeFileSync(out, next);
		console.log("wrote assets/front/js/motion-counter.js");
	}
}
