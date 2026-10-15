// Unit tests of the pure Motion helpers (assets/front/js/motion-core.js).
// Run with: node --test tools/motion/js/
import { test } from "node:test";
import assert from "node:assert/strict";
import { createRequire } from "node:module";

const require = createRequire(import.meta.url);
const core = require("../../../assets/front/js/motion-core.js");

const near = (actual, expected, eps, message) =>
  assert.ok(
    Math.abs(actual - expected) <= eps,
    (message || "value") + ": expected " + expected + " +/- " + eps + ", got " + actual,
  );

// Mirrors Effects::reference_values() as the PHP side sanitizes and passes it.
const enter = (duration, stagger, params = {}) => ({
  start_edge: "top",
  start_at: "98%",
  delay: 0,
  duration,
  stagger,
  ...params,
});
const scrub = (stagger, fromOpacity) => ({
  start_edge: "top",
  start_at: "98%",
  delay: 0,
  stagger,
  from_opacity: fromOpacity,
  end_edge: "bottom",
  end_at: "45%",
});
const CFG = {
  effects: {
    fade: enter(0.45, 0.06),
    "slide-up": enter(0.65, 0.1, { distance: 48 }),
    blur: enter(0.8, 0.08, { blur: 12 }),
    "blur-up": enter(0.8, 0.08, { distance: 20, blur: 12 }),
    zoom: enter(0.55, 0.08, { scale: 0.9 }),
    "zoom-blur": enter(0.7, 0.08, { scale: 0.86, blur: 10 }),
    lines: enter(0.95, 0.08, { distance: 120 }),
    words: scrub(0.25, 0.08),
    letters: scrub(0.1, 0.08),
    counter: {
      start_edge: "top",
      start_at: "98%",
      delay: 0,
      duration: 1.35,
      start_value: 0,
      counter_width: "auto",
    },
    underline: {},
  },
  tempo: "normal",
  ease: "smooth",
  easeCustom: "",
};

/* ---------------------------------------------------------------- bezier */

test("bezier: identity curve", () => {
  const f = core.bezier(0, 0, 1, 1);
  for (let i = 0; i <= 20; i++) {
    near(f(i / 20), i / 20, 1e-6, "t=" + i / 20);
  }
});

test("bezier: CSS ease reference point", () => {
  near(core.bezier(0.25, 0.1, 0.25, 1)(0.5), 0.8024, 1e-3);
});

test("bezier: Lumia curve is 0 at 0, 1 at 1 and increasing", () => {
  const f = core.bezier(0.625, 0.05, 0, 1);
  assert.equal(f(0), 0);
  assert.equal(f(1), 1);
  let last = -Infinity;
  for (let i = 0; i <= 100; i++) {
    const y = f(i / 100);
    assert.ok(y >= last, "not increasing at " + i);
    last = y;
  }
});

test("bezier: cached per key", () => {
  assert.equal(core.bezier(0.1, 0.2, 0.3, 0.4), core.bezier(0.1, 0.2, 0.3, 0.4));
  assert.notEqual(core.bezier(0.1, 0.2, 0.3, 0.4), core.bezier(0.1, 0.2, 0.3, 0.5));
});

test("bezier: garbage arguments do not throw", () => {
  const f = core.bezier(NaN, undefined, "x", Infinity);
  assert.equal(typeof f, "function");
  assert.ok(Number.isFinite(f(0.5)));
});

/* ---------------------------------------------------------------- spring */

const maxOf = (fn) => {
  let m = -Infinity;
  for (let i = 0; i <= 2000; i++) m = Math.max(m, fn(i / 2000));
  return m;
};

test("spring(0): critically damped, no overshoot", () => {
  const s = core.spring(0);
  assert.equal(s.ease(1), 1);
  assert.equal(s.ease(0), 0);
  assert.ok(maxOf(s.ease) <= 1.0005);
  assert.ok(s.settle >= 1.4 && s.settle <= 1.55, "settle " + s.settle);
});

test("spring(0.15): tiny overshoot", () => {
  const m = maxOf(core.spring(0.15).ease);
  assert.ok(m >= 1.003 && m <= 1.01, "max " + m);
});

test("spring(0.3): visible overshoot", () => {
  const m = maxOf(core.spring(0.3).ease);
  assert.ok(m >= 1.04 && m <= 1.05, "max " + m);
});

test("spring: cached per bounce, ends exactly on 1", () => {
  assert.equal(core.spring(0.3), core.spring(0.3));
  assert.equal(core.spring(0.3).ease(1), 1);
  assert.equal(core.spring(0.3).ease(2), 1);
  assert.equal(core.spring(0.3).ease(-1), 0);
});

test("spring: out-of-range bounce does not hang or throw", () => {
  for (const b of [-5, 1, 7, NaN, undefined]) {
    const s = core.spring(b);
    assert.ok(Number.isFinite(s.settle) && s.settle > 0);
    assert.equal(s.ease(1), 1);
  }
});

/* ------------------------------------------------------------ resolveEase */

test("resolveEase: counter never bounces", () => {
  assert.equal(core.resolveEase("bouncy", "", "counter", false).key, "smooth");
  assert.equal(core.resolveEase("snappy", "", "counter", false).key, "smooth");
  assert.equal(core.resolveEase("smooth", "", "counter", false).key, "smooth");
  assert.equal(core.resolveEase("lumia", "", "counter", false).key, "smooth");
});

test("resolveEase: scrub is linear whatever the keyword", () => {
  const r = core.resolveEase("snappy", "", "enter", true);
  assert.equal(r.key, "linear");
  assert.equal(r.settle, 1);
  near(r.ease(0.37), 0.37, 1e-9);
});

test("resolveEase: invalid custom curve falls back to smooth", () => {
  const r = core.resolveEase("custom", "cubic-bezier(2,0,0,1)", "enter", false);
  assert.equal(r.key, "smooth");
  assert.equal(r.fallback, true);
});

test("resolveEase: valid custom curve is used", () => {
  const r = core.resolveEase("custom", "cubic-bezier(0.2, 0.8, 0.2, 1)", "enter", false);
  assert.equal(r.key, "custom");
  assert.equal(r.fallback, false);
  assert.equal(r.settle, 1);
  assert.equal(r.ease(1), 1);
});

test("resolveEase: unknown keyword uses the fallback key", () => {
  const a = core.resolveEase("nope", "", "enter", false);
  assert.equal(a.key, "smooth");
  assert.equal(a.fallback, true);
  const b = core.resolveEase("nope", "", "enter", false, "smooth");
  assert.equal(b.key, "smooth");
  assert.equal(b.fallback, true);
});

test("resolveEase: known keyword is not a fallback", () => {
  const r = core.resolveEase("snappy", "", "enter", false);
  assert.equal(r.key, "snappy");
  assert.equal(r.fallback, false);
  assert.ok(r.settle > 1);
});

test("resolveEase: safeEase never overshoots", () => {
  for (const key of ["snappy", "bouncy"]) {
    const r = core.resolveEase(key, "", "enter", false);
    assert.ok(maxOf(r.ease) > 1, key + " should overshoot");
    for (let i = 0; i <= 2000; i++) {
      const y = r.safeEase(i / 2000);
      assert.ok(y <= 1 && y >= 0, key + " safeEase " + y);
    }
    assert.equal(r.safeEase(1), 1);
  }
  const c = core.resolveEase("custom", "cubic-bezier(0.3, -0.5, 0.3, 1.6)", "enter", false);
  for (let i = 0; i <= 200; i++) {
    const y = c.safeEase(i / 200);
    assert.ok(y <= 1 && y >= 0, "custom safeEase " + y);
  }
});

test("resolveEase: custom or garbage as fallback key becomes smooth", () => {
  assert.equal(core.resolveEase("nope", "", "enter", false, "custom").key, "smooth");
  assert.equal(core.resolveEase("nope", "", "enter", false, "zzz").key, "smooth");
});

/* ------------------------------------------------------------ tempoFactor */

test("tempoFactor", () => {
  assert.equal(core.tempoFactor("very-fast"), 0.5);
  assert.equal(core.tempoFactor("fast"), 0.75);
  assert.equal(core.tempoFactor("normal"), 1);
  assert.equal(core.tempoFactor("slow"), 1.5);
  assert.equal(core.tempoFactor("very-slow"), 2);
  assert.equal(core.tempoFactor("custom"), null);
  assert.equal(core.tempoFactor("whatever"), 1);
  assert.equal(core.tempoFactor(undefined), 1);
});

/* ---------------------------------------------------------------- resolve */

test("resolve: tempo scales duration and stagger, never delay", () => {
  const o = core.resolve("slide-up", { tempo: "fast" }, CFG);
  near(o.duration, 0.4875, 1e-9);
  near(o.stagger, 0.075, 1e-9);
  assert.equal(o.delay, 0);
  assert.equal(o.effect, "slide-up");
  assert.equal(o.family, "enter");
  assert.equal(o.scrub, false);
  assert.equal(o.distance, 48);
  assert.deepEqual(o.warnings, []);
});

test("resolve: gives the tempo it used (typed, else the site one)", () => {
  assert.equal(core.resolve("slide-up", { tempo: "fast" }, CFG).tempo, "fast");
  assert.equal(core.resolve("slide-up", {}, CFG).tempo, "normal");
  assert.equal(core.resolve("slide-up", { tempo: "exact" }, CFG).tempo, "custom");
  assert.equal(core.resolve("slide-up", { tempo: "nope" }, { ...CFG, tempo: "slow" }).tempo, "slow");
});

test("resolve: custom tempo uses the typed duration, unscaled", () => {
  assert.equal(core.resolve("slide-up", { tempo: "custom", duration: "1.2" }, CFG).duration, 1.2);
  assert.equal(core.resolve("slide-up", { tempo: "custom", duration: "" }, CFG).duration, 0.65);
});

test("resolve: the legacy exact tempo is read as custom, without a warning", () => {
  const o = core.resolve("fade", { tempo: "exact", duration: "2" }, CFG);
  assert.equal(o.duration, 2);
  assert.deepEqual(o.warnings, []);
  assert.equal(core.resolve("fade", { duration: "2" }, { ...CFG, tempo: "exact" }).duration, 2);
});

test("resolve: very fast and very slow scale the reference", () => {
  near(core.resolve("slide-up", { tempo: "very-fast" }, CFG).duration, 0.325, 1e-9);
  near(core.resolve("slide-up", { tempo: "very-slow" }, CFG).duration, 1.3, 1e-9);
});

test("resolve: invalid custom duration falls back, with a warning", () => {
  const o = core.resolve("slide-up", { duration: "abc", tempo: "custom" }, CFG);
  assert.equal(o.duration, 0.65);
  assert.equal(o.warnings.length, 1);
  assert.match(o.warnings[0], /duration/);
});

test("resolve: a duration set on a class is ignored when the tempo is not custom", () => {
  near(core.resolve("slide-up", { tempo: "slow", duration: "3" }, CFG).duration, 0.975, 1e-9);
});

test("resolve: empty tempo uses the site tempo", () => {
  const cfg = { ...CFG, tempo: "slow" };
  near(core.resolve("slide-up", {}, cfg).duration, 0.975, 1e-9);
  near(core.resolve("slide-up", { tempo: "" }, cfg).duration, 0.975, 1e-9);
});

test("resolve: an empty delay field inherits the site delay, a typed 0 does not", () => {
  const cfg = JSON.parse(JSON.stringify(CFG));
  cfg.effects["slide-up"].delay = 0.4;
  assert.equal(core.resolve("slide-up", { delay: "" }, cfg).delay, 0.4);
  assert.equal(core.resolve("slide-up", { delay: "0" }, cfg).delay, 0);
});

test("resolve: delay is taken as is", () => {
  assert.equal(core.resolve("slide-up", { tempo: "slow", delay: "0.3" }, CFG).delay, 0.3);
});

test("resolve: stagger from the element is also scaled", () => {
  near(core.resolve("slide-up", { tempo: "fast", stagger: "0.2" }, CFG).stagger, 0.15, 1e-9);
});

test("resolve: blur reads its own blur value, default or typed", () => {
  const o = core.resolve("blur", {}, CFG);
  assert.equal(o.effect, "blur");
  assert.equal(o.blur, 12);
  assert.equal(core.resolve("blur", { blur: "6" }, CFG).blur, 6);
});

test("resolve: effect-specific values", () => {
  const b = core.resolve("blur-up", { blur: "6", distance: "30" }, CFG);
  assert.equal(b.blur, 6);
  assert.equal(b.distance, 30);
  assert.equal(core.resolve("zoom-blur", {}, CFG).scale, 0.86);
  assert.equal(core.resolve("lines", {}, CFG).distance, 120);
  const w = core.resolve("words", { "from-opacity": "0.5" }, CFG);
  assert.equal(w.fromOpacity, 0.5);
  assert.equal(core.resolve("counter", { "start-value": "10" }, CFG).startValue, 10);
  assert.equal(core.resolve("counter", {}, CFG).startValue, 0);
});

test("resolve: counter width, auto by default, fixed when asked", () => {
  assert.equal(core.resolve("counter", {}, CFG).counterWidth, "auto");
  assert.equal(core.resolve("counter", { "counter-width": "fixed" }, CFG).counterWidth, "fixed");
  assert.equal(core.resolve("counter", { "counter-width": " FIXED " }, CFG).counterWidth, "fixed");
  const site = { ...CFG, effects: { ...CFG.effects, counter: { ...CFG.effects.counter, counter_width: "fixed" } } };
  assert.equal(core.resolve("counter", {}, site).counterWidth, "fixed");
  assert.equal(core.resolve("counter", { "counter-width": "auto" }, site).counterWidth, "auto");
  const bad = core.resolve("counter", { "counter-width": "wide" }, CFG);
  assert.equal(bad.counterWidth, "auto");
  assert.ok(bad.warnings.some((w) => /counter width/i.test(w)), bad.warnings.join(" "));
  assert.equal(core.resolve("fade", { "counter-width": "fixed" }, CFG).counterWidth, "auto");
  assert.notEqual(
    core.signature(core.resolve("counter", {}, CFG)),
    core.signature(core.resolve("counter", { "counter-width": "fixed" }, CFG)),
  );
});

test("resolve: start position", () => {
  assert.equal(core.resolve("slide-up", { start: "top 50%" }, CFG).start, "top 50%");
  assert.equal(
    core.resolve("slide-up", { "start-edge": "center", "start-at": "80%" }, CFG).start,
    "center 80%",
  );
  assert.equal(core.resolve("slide-up", { "start-at": "80" }, CFG).start, "top 80%");
  assert.equal(core.resolve("slide-up", {}, CFG).start, "top 98%");
  assert.equal(core.resolve("slide-up", { "start-edge": "bottom" }, CFG).start, "bottom 98%");
});

test("resolve: the advanced position wins over edge and at", () => {
  const o = core.resolve(
    "slide-up",
    { start: "bottom 20%", "start-edge": "center", "start-at": "80%" },
    CFG,
  );
  assert.equal(o.start, "bottom 20%");
});

test("resolve: injected position is refused with a warning", () => {
  const o = core.resolve("slide-up", { start: "top 50%; alert(1)" }, CFG);
  assert.equal(o.start, "top 98%");
  assert.equal(o.warnings.length, 1);
  assert.match(o.warnings[0], /start/);
});

test("resolve: an advanced position must read as a ScrollTrigger position", () => {
  // Shared with the PHP sanitizer test: both sides accept and refuse the same values.
  // "--lm-start: foo;bar" typed in the content reaches the browser as "foo" (the CSS stops at ";").
  const cases = require("../positions.json");
  for (const bad of cases.invalid.concat(["foo"])) {
    const o = core.resolve("slide-up", { start: bad }, CFG);
    assert.equal(o.start, "top 98%", JSON.stringify(bad));
    assert.equal(o.warnings.length, 1, JSON.stringify(bad));
  }
  for (const good of cases.valid) {
    assert.equal(core.resolve("slide-up", { start: good }, CFG).start, good, JSON.stringify(good));
  }
  // A relative end ("+=500": 500 px after the start) is a ScrollTrigger position too.
  assert.equal(core.resolve("words", { end: "+=500" }, CFG).end, "+=500");
});

test("resolve: invalid edge or at is refused with a warning", () => {
  const a = core.resolve("slide-up", { "start-edge": "middle", "start-at": "foo;bar" }, CFG);
  assert.equal(a.start, "top 98%");
  assert.equal(a.warnings.length, 2);
});

test("resolve: scrub effects have an end, ignore tempo and ease", () => {
  const o = core.resolve("words", {}, CFG);
  assert.equal(o.end, "bottom 45%");
  assert.equal(o.scrub, true);
  assert.equal(o.family, "text");
  assert.equal(o.easeKey, "linear");
  assert.equal(o.stagger, 0.25);
  const t = core.resolve("letters", { tempo: "slow", ease: "bouncy" }, CFG);
  assert.equal(t.stagger, 0.1);
  assert.equal(t.easeKey, "linear");
  assert.equal(t.end, "bottom 45%");
  assert.equal(
    core.resolve("words", { "end-edge": "center", "end-at": "20" }, CFG).end,
    "center 20%",
  );
  assert.equal(core.resolve("words", { end: "bottom 10%" }, CFG).end, "bottom 10%");
});

test("resolve: tweenDuration follows the settle time of the curve", () => {
  const smooth = core.resolve("slide-up", { ease: "smooth" }, CFG);
  assert.equal(smooth.easeKey, "smooth");
  assert.equal(smooth.tweenDuration, smooth.duration * core.spring(0).settle);
  assert.ok(smooth.tweenDuration > smooth.duration);
  const linear = core.resolve("slide-up", { ease: "linear" }, CFG);
  assert.equal(linear.tweenDuration, linear.duration);
});

test("resolve: ease comes from the element, then from the site", () => {
  assert.equal(core.resolve("slide-up", {}, CFG).easeKey, "smooth");
  assert.equal(core.resolve("slide-up", {}, { ...CFG, ease: "snappy" }).easeKey, "snappy");
  assert.equal(core.resolve("slide-up", { ease: "bouncy" }, CFG).easeKey, "bouncy");
  const custom = core.resolve(
    "slide-up",
    { ease: "custom", "ease-custom": "cubic-bezier(0.2, 0.8, 0.2, 1)" },
    CFG,
  );
  assert.equal(custom.easeKey, "custom");
  const site = core.resolve(
    "slide-up",
    {},
    { ...CFG, ease: "custom", easeCustom: "cubic-bezier(0.2, 0.8, 0.2, 1)" },
  );
  assert.equal(site.easeKey, "custom");
});

test("resolve: bad curve falls back to the site curve, with a warning", () => {
  const o = core.resolve("slide-up", { ease: "nope" }, { ...CFG, ease: "smooth" });
  assert.equal(o.easeKey, "smooth");
  assert.equal(o.warnings.length, 1);
  const c = core.resolve("slide-up", { ease: "custom", "ease-custom": "cubic-bezier(2,0,0,1)" }, CFG);
  assert.equal(c.easeKey, "smooth");
  assert.equal(c.warnings.length, 1);
  // The site curve itself is broken: smooth, never a loop on "custom".
  const d = core.resolve("slide-up", { ease: "nope" }, { ...CFG, ease: "custom", easeCustom: "x" });
  assert.equal(d.easeKey, "smooth");
});

test("resolve: the legacy lumia curve is read as smooth, without a warning", () => {
  const o = core.resolve("fade", { ease: "lumia" }, CFG);
  assert.equal(o.easeKey, "smooth");
  assert.deepEqual(o.warnings, []);
  assert.equal(core.resolve("fade", {}, { ...CFG, ease: "lumia" }).easeKey, "smooth");
});

test("springs: bouncy overshoots more than snappy, which overshoots", () => {
  const snappy = maxOf(core.resolveEase("snappy", "", "enter", false).ease);
  const bouncy = maxOf(core.resolveEase("bouncy", "", "enter", false).ease);
  assert.ok(snappy > 1, "snappy " + snappy);
  assert.ok(bouncy > snappy, "bouncy " + bouncy + " snappy " + snappy);
});

test("resolve: counter never bounces", () => {
  assert.equal(core.resolve("counter", { ease: "bouncy" }, CFG).easeKey, "smooth");
});

test("resolve: safeEase is exposed and bounded", () => {
  const o = core.resolve("slide-up", { ease: "bouncy" }, CFG);
  for (let i = 0; i <= 200; i++) assert.ok(o.safeEase(i / 200) <= 1);
});

test("resolve: unknown slug uses the enter defaults, with a warning", () => {
  const o = core.resolve("wobble", {}, CFG);
  assert.equal(o.family, "enter");
  assert.equal(o.scrub, false);
  assert.equal(o.duration, 0.6);
  assert.equal(o.stagger, 0.08);
  assert.equal(o.distance, 40);
  assert.equal(o.blur, 10);
  assert.equal(o.scale, 0.9);
  assert.equal(o.fromOpacity, 0.1);
  assert.equal(o.startValue, 0);
  assert.equal(o.start, "top 98%");
  assert.ok(o.warnings.length >= 1);
});

test("resolve: never throws on garbage", () => {
  const garbage = [undefined, null, 0, "x", [], {}, NaN];
  for (const slug of [undefined, null, "", "slide-up", "words", "__proto__", "constructor"]) {
    for (const raw of garbage.concat([
      { duration: Infinity, tempo: "custom", delay: NaN, stagger: "1e999", ease: 3 },
      { start: 5, "start-at": {}, "end-at": [], blur: "-4", scale: "x", distance: "" },
    ])) {
      for (const cfg of garbage.concat([{ effects: null, tempo: 7, ease: {} }, { effects: { "slide-up": null } }])) {
        const o = core.resolve(slug, raw, cfg);
        assert.ok(Number.isFinite(o.duration) && o.duration >= 0);
        assert.ok(Number.isFinite(o.tweenDuration) && o.tweenDuration >= 0);
        assert.ok(Number.isFinite(o.stagger) && Number.isFinite(o.delay));
        assert.equal(typeof o.start, "string");
        assert.equal(typeof o.end, "string");
        assert.equal(typeof o.ease, "function");
        assert.ok(Array.isArray(o.warnings));
        assert.equal(typeof core.signature(o), "string");
      }
    }
  }
});

test("resolve: non-finite or out-of-range numbers are refused", () => {
  const o = core.resolve(
    "blur-up",
    { delay: "-2", blur: "Infinity", distance: "NaN", tempo: "custom", duration: "0" },
    CFG,
  );
  assert.equal(o.delay, 0);
  assert.equal(o.blur, 12);
  assert.equal(o.distance, 20);
  assert.equal(o.duration, 0);
  assert.equal(o.warnings.length, 3);
});

test("resolve: values with a unit suffix are accepted", () => {
  const o = core.resolve("slide-up", { tempo: "custom", duration: "1.5s", distance: "30px" }, CFG);
  assert.equal(o.duration, 1.5);
  assert.equal(o.distance, 30);
  assert.deepEqual(o.warnings, []);
});

test("resolve: effect without config uses the internal defaults", () => {
  const o = core.resolve("slide-up", {}, undefined);
  assert.equal(o.duration, 0.6);
  assert.equal(o.distance, 40);
  assert.equal(o.easeKey, "smooth");
});

/* -------------------------------------------------------------- signature */

test("signature: same input, same signature; tempo changes it", () => {
  const a = core.signature(core.resolve("slide-up", { tempo: "fast" }, CFG));
  const b = core.signature(core.resolve("slide-up", { tempo: "fast" }, CFG));
  const c = core.signature(core.resolve("slide-up", { tempo: "slow" }, CFG));
  assert.equal(a, b);
  assert.notEqual(a, c);
});

test("signature: ease, custom curve, position and effect change it", () => {
  const base = core.signature(core.resolve("slide-up", {}, CFG));
  const sig = (slug, raw) => core.signature(core.resolve(slug, raw, CFG));
  assert.notEqual(base, sig("slide-up", { ease: "snappy" }));
  assert.notEqual(base, sig("slide-up", { start: "top 50%" }));
  assert.notEqual(base, sig("fade", {}));
  assert.notEqual(
    sig("slide-up", { ease: "custom", "ease-custom": "cubic-bezier(0.2, 0.8, 0.2, 1)" }),
    sig("slide-up", { ease: "custom", "ease-custom": "cubic-bezier(0.3, 0.8, 0.2, 1)" }),
  );
});

/* ---------------------------------------------------------------- counter */

const NBSP = "\u00a0";
const NNBSP = "\u202f";

test("parseCounter: plain integer", () => {
  const p = core.parseCounter("2024");
  assert.equal(p.value, 2024);
  assert.equal(p.group, "");
  assert.equal(p.decimals, 0);
  assert.equal(core.formatCounter(2024, p), "2024");
});

test("parseCounter: space group", () => {
  for (const sp of [" ", NBSP, NNBSP]) {
    const p = core.parseCounter("1" + sp + "200");
    assert.equal(p.value, 1200);
    assert.equal(p.group, sp);
    assert.equal(p.decimals, 0);
  }
});

test("parseCounter: group, decimal, suffix", () => {
  const p = core.parseCounter("1 200,50 \u20ac");
  assert.equal(p.value, 1200.5);
  assert.equal(p.group, " ");
  assert.equal(p.decimal, ",");
  assert.equal(p.decimals, 2);
  assert.equal(p.suffix, " \u20ac");
  assert.equal(p.prefix, "");
});

test("parseCounter: lone separator followed by three digits is a group", () => {
  const p = core.parseCounter("1,500");
  assert.equal(p.group, ",");
  assert.equal(p.value, 1500);
  assert.equal(p.decimals, 0);
  const q = core.parseCounter("1.500");
  assert.equal(q.group, ".");
  assert.equal(q.value, 1500);
});

test("parseCounter: lone separator otherwise is a decimal", () => {
  const p = core.parseCounter("98,5 %");
  assert.equal(p.decimal, ",");
  assert.equal(p.group, "");
  assert.equal(p.value, 98.5);
  assert.equal(p.decimals, 1);
  assert.equal(p.suffix, " %");
  const q = core.parseCounter("3.14");
  assert.equal(q.decimal, ".");
  assert.equal(q.value, 3.14);
  const r = core.parseCounter("0,500");
  assert.equal(r.value, 0.5);
  assert.equal(r.decimals, 3);
});

test("parseCounter: two kinds, the last one is the decimal", () => {
  const p = core.parseCounter("1.500,75");
  assert.equal(p.group, ".");
  assert.equal(p.decimal, ",");
  assert.equal(p.value, 1500.75);
  assert.equal(p.decimals, 2);
  const q = core.parseCounter("1,500.75");
  assert.equal(q.group, ",");
  assert.equal(q.decimal, ".");
  assert.equal(q.value, 1500.75);
});

test("parseCounter: repeated separator is a group", () => {
  const p = core.parseCounter("1,234,567");
  assert.equal(p.group, ",");
  assert.equal(p.value, 1234567);
});

test("parseCounter: prefix and suffix", () => {
  const p = core.parseCounter("+150 clients");
  assert.equal(p.prefix, "+");
  assert.equal(p.suffix, " clients");
  assert.equal(p.value, 150);
});

test("parseCounter: ambiguous or non numeric text is left alone", () => {
  assert.equal(core.parseCounter("24/7"), null);
  assert.equal(core.parseCounter("abc"), null);
  assert.equal(core.parseCounter(""), null);
  assert.equal(core.parseCounter("2020 - 2024"), null);
  assert.equal(core.parseCounter("1,00,000"), null);
  assert.equal(core.parseCounter("1 2 3"), null);
  assert.equal(core.parseCounter(null), null);
  assert.equal(core.parseCounter(undefined), null);
  assert.equal(core.parseCounter(42), null);
});

test("parseCounter + formatCounter round trip", () => {
  const texts = [
    "2024",
    "1 200",
    "1" + NBSP + "200",
    "1 200,50 \u20ac",
    "1,500",
    "98,5 %",
    "1.500,75",
    "1,500.75",
    "+150 clients",
    "1,234,567",
    "3.14",
    "0,500",
    "-5 \u00b0C",
    "1'200'000",
    "  42  ",
    "7",
    "1 000 000",
  ];
  for (const text of texts) {
    const p = core.parseCounter(text);
    assert.notEqual(p, null, "parse " + JSON.stringify(text));
    assert.equal(p.prefix + core.formatCounter(p.value, p) + p.suffix, text, "round trip " + JSON.stringify(text));
  }
});

test("formatCounter: intermediate values keep the format", () => {
  const p = core.parseCounter("1 200,50 \u20ac");
  assert.equal(core.formatCounter(0, p), "0,00");
  assert.equal(core.formatCounter(999.999, p), "1 000,00");
  assert.equal(core.formatCounter(12345.678, p), "12 345,68");
  assert.equal(core.formatCounter(NaN, p), "0,00");
  const q = core.parseCounter("2024");
  assert.equal(core.formatCounter(12.4, q), "12");
});

/* ------------------------------------------------------------ fix round 1 */

test("resolveEase: a counter never overshoots, whatever the curve", () => {
  const curves = [
    ["custom", "cubic-bezier(.2,1.6,.3,1)"],
    ["custom", "cubic-bezier(.3,-0.5,.3,1.6)"],
    ["bouncy", ""],
    ["snappy", ""],
    ["smooth", ""],
    ["lumia", ""],
  ];
  for (const [key, custom] of curves) {
    const r = core.resolveEase(key, custom, "counter", false);
    let max = -Infinity;
    let min = Infinity;
    for (let i = 0; i <= 1000; i++) {
      const y = r.ease(i / 1000);
      max = Math.max(max, y);
      min = Math.min(min, y);
    }
    assert.ok(max <= 1, key + " " + custom + " peaks at " + max);
    assert.ok(min >= 0, key + " " + custom + " dips to " + min);
    assert.equal(r.ease(1), 1);
  }
  // Other families keep the overshoot on ease.
  const e = core.resolveEase("custom", "cubic-bezier(.2,1.6,.3,1)", "enter", false);
  let m = 0;
  for (let i = 0; i <= 1000; i++) m = Math.max(m, e.ease(i / 1000));
  assert.ok(m > 1);
});

test("resolve: a typed duration ignored by a non custom tempo is reported", () => {
  const o = core.resolve("slide-up", { tempo: "fast", duration: "3" }, CFG);
  near(o.duration, 0.4875, 1e-9);
  assert.equal(o.warnings.length, 1);
  assert.match(o.warnings[0], /duration ignored: tempo is not custom/);
  // Nothing typed, nothing to report; custom uses it.
  assert.deepEqual(core.resolve("slide-up", { tempo: "fast" }, CFG).warnings, []);
  assert.deepEqual(core.resolve("slide-up", { tempo: "custom", duration: "3" }, CFG).warnings, []);
  // Site tempo applies when the element has none.
  assert.equal(core.resolve("slide-up", { duration: "3" }, CFG).warnings.length, 1);
});

test("resolve: tempo is case insensitive", () => {
  near(core.resolve("slide-up", { tempo: "FAST" }, CFG).duration, 0.4875, 1e-9);
  assert.equal(core.resolve("slide-up", { tempo: " Custom ", duration: "1.2" }, CFG).duration, 1.2);
  assert.deepEqual(core.resolve("slide-up", { tempo: "FAST" }, CFG).warnings, []);
});

test("resolve: durations, delays and staggers accept milliseconds", () => {
  const o = core.resolve("slide-up", { tempo: "custom", duration: "300ms", delay: "250ms", stagger: "50ms" }, CFG);
  assert.equal(o.duration, 0.3);
  assert.equal(o.delay, 0.25);
  assert.equal(o.stagger, 0.05);
  assert.deepEqual(o.warnings, []);
  assert.equal(core.resolve("slide-up", { tempo: "custom", duration: "1.5s" }, CFG).duration, 1.5);
  // Not a time setting: milliseconds make no sense.
  const d = core.resolve("slide-up", { distance: "30ms" }, CFG);
  assert.equal(d.distance, 48);
  assert.equal(d.warnings.length, 1);
});

test("parseCounter: a separator touching the number outside it is left alone", () => {
  assert.equal(core.parseCounter(".5"), null);
  assert.equal(core.parseCounter(",5"), null);
  assert.equal(core.parseCounter("~.5"), null);
  assert.equal(core.parseCounter("5."), null);
  assert.equal(core.parseCounter("Total: 5,"), null);
  assert.equal(core.parseCounter("5.%"), null);
  // Punctuation not touching the number is fine.
  assert.notEqual(core.parseCounter("+150 clients."), null);
  assert.notEqual(core.parseCounter("1,5 %"), null);
});

test("counterEase: a custom curve that would overshoot falls back to smooth", () => {
  const smooth = core.cssLinear(core.BOUNCE.smooth).linear;
  const counter = (ease, custom) => core.counterEase(core.resolve("counter", { ease, "ease-custom": custom }, CFG));

  assert.deepEqual(counter("linear", ""), { easing: "linear", warning: "" });
  assert.deepEqual(counter("smooth", ""), { easing: smooth, warning: "" });
  assert.deepEqual(counter("bouncy", ""), { easing: smooth, warning: "" });

  const inside = counter("custom", "cubic-bezier(0.2, 0, 0.3, 1)");
  assert.equal(inside.easing, "cubic-bezier(0.2,0,0.3,1)");
  assert.equal(inside.warning, "");

  for (const curve of ["cubic-bezier(0.3, 1.4, 0.5, 1)", "cubic-bezier(0.3, 0, 0.5, 1.2)", "cubic-bezier(0.3, -0.4, 0.5, 1)", "cubic-bezier(0.3, 0, 0.5, -0.1)"]) {
    const got = counter("custom", curve);
    assert.equal(got.easing, smooth, curve);
    assert.match(got.warning, /smooth/, curve);
  }
});
