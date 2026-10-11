// Rewrites the generated curve block of assets/front/css/motion.css from the engine springs.
//   node tools/motion/js/build-curves.mjs           rewrite the block
//   node tools/motion/js/build-curves.mjs --check   exit 1 if the file differs
import { readFileSync, writeFileSync } from "node:fs";
import { createRequire } from "node:module";
import { fileURLToPath, pathToFileURL } from "node:url";

const require = createRequire(import.meta.url);
const core = require("../../../assets/front/js/motion-core.js");
const cssPath = fileURLToPath(new URL("../../../assets/front/css/motion.css", import.meta.url));

const START = "/* lm-curves:start */";
const END = "/* lm-curves:end */";
const FALLBACK = "cubic-bezier(0.22, 1, 0.36, 1)";
const CURVES = { smooth: 0, snappy: 0.25, bouncy: 0.45 };

export function buildBlock() {
	const names = Object.keys(CURVES);
	const built = names.map((name) => ({ name, ...core.cssLinear(CURVES[name]) }));
	const lines = [START, ":root {"];
	for (const c of built) {
		lines.push(`\t--lm-curve-${c.name}: ${FALLBACK};`);
	}
	for (const c of built) {
		lines.push(`\t--lm-settle-${c.name}: ${Math.round(c.settle * 10000) / 10000};`);
	}
	lines.push("}", "", "@supports (animation-timing-function: linear(0, 1)) {", "\t:root {");
	for (const c of built) {
		lines.push(`\t\t--lm-curve-${c.name}: ${c.linear};`);
	}
	lines.push("\t}", "}", END);
	return lines.join("\n");
}

export function render(css) {
	const a = css.indexOf(START);
	const b = css.indexOf(END);
	if (a === -1 || b === -1 || b < a) {
		throw new Error("motion.css has no " + START + " ... " + END + " block");
	}
	return css.slice(0, a) + buildBlock() + css.slice(b + END.length);
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
	const current = readFileSync(cssPath, "utf8");
	const next = render(current);
	if (process.argv.includes("--check")) {
		if (next !== current) {
			console.error("motion.css curve block is out of date: run node tools/motion/js/build-curves.mjs");
			process.exit(1);
		}
	} else if (next !== current) {
		writeFileSync(cssPath, next);
		console.log("motion.css curve block rewritten");
	}
}
