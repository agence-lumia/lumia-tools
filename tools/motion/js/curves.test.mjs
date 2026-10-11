// Tests of the generated CSS curve tokens (cssLinear and tools/motion/js/build-curves.mjs).
// Run with: node --test tools/motion/js/*.test.mjs
import { test } from "node:test";
import assert from "node:assert/strict";
import { createRequire } from "node:module";
import { spawnSync } from "node:child_process";
import { fileURLToPath } from "node:url";

const require = createRequire(import.meta.url);
const core = require("../../../assets/front/js/motion-core.js");
const builder = fileURLToPath(new URL("./build-curves.mjs", import.meta.url));

// Reads "linear(0, 0.1 5%, 1)" into [{ x, y }] (missing positions: first 0, last 1, others even between neighbours).
const parseLinear = (text) => {
  const inner = text.replace(/^linear\(/, "").replace(/\)$/, "");
  const stops = inner.split(",").map((part) => {
    const [y, pos] = part.trim().split(/\s+/);
    return { y: parseFloat(y), x: pos ? parseFloat(pos) / 100 : null };
  });
  stops[0].x = stops[0].x ?? 0;
  stops[stops.length - 1].x = stops[stops.length - 1].x ?? 1;
  return stops;
};

test("cssLinear starts at 0 and ends at 1", () => {
  const { linear } = core.cssLinear(0);
  assert.ok(linear.startsWith("linear(0"), linear);
  assert.ok(linear.endsWith("1)"), linear);
});

test("a bouncy spring overshoots", () => {
  const stops = parseLinear(core.cssLinear(0.45).linear);
  assert.ok(stops.some((s) => s.y > 1));
});

test("settle matches the spring", () => {
  for (const b of [0, 0.25, 0.45]) {
    assert.equal(core.cssLinear(b).settle, core.spring(b).settle);
  }
});

test("no more stops than asked", () => {
  assert.ok(parseLinear(core.cssLinear(0.45, 12).linear).length <= 12);
  assert.ok(parseLinear(core.cssLinear(0.45).linear).length <= 40);
});

test("the stops follow the engine spring", () => {
  for (const b of [0, 0.25, 0.45]) {
    for (const stop of parseLinear(core.cssLinear(b).linear)) {
      const expected = core.spring(b).ease(stop.x);
      assert.ok(Math.abs(stop.y - expected) <= 0.003, `bounce ${b} at ${stop.x}: ${stop.y} vs ${expected}`);
    }
  }
});

test("motion.css holds the current generated block", () => {
  const run = spawnSync(process.execPath, [builder, "--check"], { encoding: "utf8" });
  assert.equal(run.status, 0, run.stdout + run.stderr);
});
