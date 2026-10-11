/**
 * Lümia Tools - Motion core
 * Pure helpers of the Motion engine: easing curves, springs, settings
 * resolution and counter formatting. No DOM, no GSAP: the engine reads the
 * result through window.lumiaMotionCore, the unit tests through require().
 */
(function (root, factory) {
  "use strict";

  var api = factory();
  if (typeof module === "object" && module.exports) {
    module.exports = api;
  } else {
    root.lumiaMotionCore = api;
  }
})(typeof self !== "undefined" ? self : this, function () {
  "use strict";

  /* ================================================================
   * HELPERS
   * ================================================================ */

  function isObject(value) {
    return value !== null && typeof value === "object" && !Array.isArray(value);
  }

  function has(object, key) {
    return Object.prototype.hasOwnProperty.call(object, key);
  }

  function clamp(value, min, max) {
    return value < min ? min : value > max ? max : value;
  }

  function identity(t) {
    return t;
  }

  /* ================================================================
   * BEZIER (UnitBezier, as in WebKit and Firefox)
   * ================================================================ */

  var BEZIER_EPSILON = 1e-6;
  var bezierCache = {};

  function bezier(x1, y1, x2, y2) {
    var args = [x1, y1, x2, y2];
    for (var i = 0; i < 4; i++) {
      if (typeof args[i] !== "number" || !isFinite(args[i])) {
        return identity;
      }
    }
    // Outside [0, 1] the curve is no longer a function of time.
    x1 = clamp(x1, 0, 1);
    x2 = clamp(x2, 0, 1);

    var key = x1 + "," + y1 + "," + x2 + "," + y2;
    if (has(bezierCache, key)) {
      return bezierCache[key];
    }

    var cx = 3 * x1;
    var bx = 3 * (x2 - x1) - cx;
    var ax = 1 - cx - bx;
    var cy = 3 * y1;
    var by = 3 * (y2 - y1) - cy;
    var ay = 1 - cy - by;

    function sampleX(t) {
      return ((ax * t + bx) * t + cx) * t;
    }

    function sampleY(t) {
      return ((ay * t + by) * t + cy) * t;
    }

    function slopeX(t) {
      return (3 * ax * t + 2 * bx) * t + cx;
    }

    function solveT(x) {
      var t = x;
      var i;
      var err;
      var slope;

      // Newton's method.
      for (i = 0; i < 8; i++) {
        err = sampleX(t) - x;
        if (Math.abs(err) < BEZIER_EPSILON) {
          return t;
        }
        slope = slopeX(t);
        if (Math.abs(slope) < 1e-6) {
          break;
        }
        t -= err / slope;
      }

      // Bisection when Newton's method does not converge.
      var lo = 0;
      var hi = 1;
      t = x;
      while (lo < hi) {
        err = sampleX(t);
        if (Math.abs(err - x) < BEZIER_EPSILON) {
          return t;
        }
        if (x > err) {
          lo = t;
        } else {
          hi = t;
        }
        t = (hi - lo) * 0.5 + lo;
        if (hi - lo < 1e-12) {
          break;
        }
      }
      return t;
    }

    var fn = function (x) {
      if (x <= 0) {
        return 0;
      }
      if (x >= 1) {
        return 1;
      }
      return sampleY(solveT(x));
    };

    bezierCache[key] = fn;
    return fn;
  }

  /* ================================================================
   * SPRING (SwiftUI parameters: perceived duration 1, bounce)
   * ================================================================ */

  var SPRING_SAMPLES = 240;
  var SPRING_THRESHOLD = 0.001;
  var OMEGA0 = 2 * Math.PI;
  var springCache = {};

  function spring(bounce) {
    // Bounce 1 would never settle: keep a margin.
    bounce = typeof bounce === "number" && isFinite(bounce) ? clamp(bounce, 0, 0.9) : 0;
    var key = String(bounce);
    if (has(springCache, key)) {
      return springCache[key];
    }

    var zeta = 1 - bounce;
    var zw = zeta * OMEGA0;
    var wd = zeta < 1 ? OMEGA0 * Math.sqrt(1 - zeta * zeta) : 0;
    var ratio = zeta < 1 ? zw / wd : 0;
    var amplitude = zeta < 1 ? Math.sqrt(1 + ratio * ratio) : 1;

    function envelope(tau) {
      if (zeta < 1) {
        return Math.exp(-zw * tau) * amplitude;
      }
      return Math.exp(-OMEGA0 * tau) * (1 + OMEGA0 * tau);
    }

    function position(tau) {
      if (zeta < 1) {
        return 1 - Math.exp(-zw * tau) * (Math.cos(wd * tau) + ratio * Math.sin(wd * tau));
      }
      return 1 - Math.exp(-OMEGA0 * tau) * (1 + OMEGA0 * tau);
    }

    // The envelope only decreases: bisect for the first time it is under the threshold.
    var lo = 0;
    var hi = 60;
    for (var i = 0; i < 80; i++) {
      var mid = (lo + hi) / 2;
      if (envelope(mid) > SPRING_THRESHOLD) {
        lo = mid;
      } else {
        hi = mid;
      }
    }
    var settle = hi;

    var samples = new Array(SPRING_SAMPLES + 1);
    for (var s = 0; s < SPRING_SAMPLES; s++) {
      samples[s] = position((s / SPRING_SAMPLES) * settle);
    }
    samples[SPRING_SAMPLES] = 1;

    var result = {
      settle: settle,
      ease: function (p) {
        if (!(p > 0)) {
          return 0;
        }
        if (p >= 1) {
          return 1;
        }
        var x = p * SPRING_SAMPLES;
        var index = Math.floor(x);
        var frac = x - index;
        return samples[index] + (samples[index + 1] - samples[index]) * frac;
      },
    };

    springCache[key] = result;
    return result;
  }

  /* ================================================================
   * CSS linear() EXPORT (spring -> CSS easing, for the generated tokens)
   * ================================================================ */

  var LINEAR_TOLERANCE = 0.002;

  function round(value, decimals) {
    var f = Math.pow(10, decimals);
    return Math.round(value * f) / f;
  }

  // The spring over its whole settle time as a CSS linear() easing, plus the tween length
  // divided by the perceived duration. Stops carry an explicit position (simplification
  // removes some, so the remaining ones are no longer evenly spaced).
  function cssLinear(bounce, points) {
    var sp = spring(bounce);
    var count = typeof points === "number" && isFinite(points) ? Math.max(3, Math.floor(points)) : 40;
    var xs = [];
    var ys = [];
    for (var i = 0; i < count; i++) {
      xs.push(i / (count - 1));
      ys.push(round(sp.ease(i / (count - 1)), 4));
    }
    ys[count - 1] = 1;

    // From the last kept stop, go as far as every skipped stop stays within the tolerance of the straight line.
    var kept = [0];
    var from = 0;
    while (from < count - 1) {
      var to = from + 1;
      for (var j = from + 2; j < count; j++) {
        var ok = true;
        for (var k = from + 1; k < j; k++) {
          var line = ys[from] + ((ys[j] - ys[from]) * (xs[k] - xs[from])) / (xs[j] - xs[from]);
          if (Math.abs(ys[k] - line) > LINEAR_TOLERANCE) {
            ok = false;
            break;
          }
        }
        if (!ok) {
          break;
        }
        to = j;
      }
      kept.push(to);
      from = to;
    }

    var parts = kept.map(function (index) {
      if (index === 0 || index === count - 1) {
        return String(ys[index]);
      }
      return ys[index] + " " + round(xs[index] * 100, 2) + "%";
    });

    return { linear: "linear(" + parts.join(", ") + ")", settle: sp.settle };
  }

  /* ================================================================
   * EASING PROFILES
   * ================================================================ */

  var EASE_KEYS = ["smooth", "snappy", "bouncy", "linear", "custom"];
  var BOUNCE = { smooth: 0, snappy: 0.25, bouncy: 0.45 };
  // Values saved by earlier trials: "lumia" became "smooth", "exact" became "custom". Read silently.
  var LEGACY_EASE = { lumia: "smooth" };
  var LEGACY_TEMPO = { exact: "custom" };
  var BEZIER_RE =
    /^cubic-bezier\(\s*(-?(?:\d+(?:\.\d+)?|\.\d+))\s*,\s*(-?(?:\d+(?:\.\d+)?|\.\d+))\s*,\s*(-?(?:\d+(?:\.\d+)?|\.\d+))\s*,\s*(-?(?:\d+(?:\.\d+)?|\.\d+))\s*\)$/;
  var profileCache = {};

  // Reads "cubic-bezier(x1, y1, x2, y2)". x1 and x2 must be in [0, 1], as in CSS.
  function parseBezier(text) {
    var m = BEZIER_RE.exec(typeof text === "string" ? text.trim() : "");
    if (!m) {
      return null;
    }
    var v = [parseFloat(m[1]), parseFloat(m[2]), parseFloat(m[3]), parseFloat(m[4])];
    if (v[0] < 0 || v[0] > 1 || v[2] < 0 || v[2] > 1) {
      return null;
    }
    return v;
  }

  // The same curve without overshoot: stays in [0, 1] and holds 1 from the first time it gets there.
  function makeSafe(ease) {
    var reach = 1;
    for (var i = 0; i <= SPRING_SAMPLES; i++) {
      if (ease(i / SPRING_SAMPLES) >= 1) {
        reach = i / SPRING_SAMPLES;
        break;
      }
    }
    return function (p) {
      if (p >= reach) {
        return 1;
      }
      return clamp(ease(p), 0, 1);
    };
  }

  function buildProfile(key, curve) {
    var cacheKey = key + "|" + (curve ? curve.join(",") : "");
    if (has(profileCache, cacheKey)) {
      return profileCache[cacheKey];
    }

    var ease;
    var settle = 1;
    if (key === "custom") {
      ease = bezier(curve[0], curve[1], curve[2], curve[3]);
    } else if (key === "linear") {
      ease = identity;
    } else {
      var sp = spring(BOUNCE[key]);
      ease = sp.ease;
      settle = sp.settle;
    }

    var profile = {
      key: key,
      ease: ease,
      safeEase: makeSafe(ease),
      settle: settle,
      curve: curve ? curve.join(",") : "",
    };
    profileCache[cacheKey] = profile;
    return profile;
  }

  function resolveEase(keyword, custom, family, scrub, fallbackKey) {
    var fallback = EASE_KEYS.indexOf(fallbackKey) > -1 && fallbackKey !== "custom" ? fallbackKey : "smooth";
    var key;
    var curve = null;
    var usedFallback = false;

    if (scrub) {
      // A scrub follows the scroll: no curve of its own.
      key = "linear";
    } else {
      key = typeof keyword === "string" ? keyword.trim().toLowerCase() : "";
      if (has(LEGACY_EASE, key)) {
        key = LEGACY_EASE[key];
      }
      if (EASE_KEYS.indexOf(key) === -1) {
        key = fallback;
        usedFallback = true;
      } else if (key === "custom") {
        curve = parseBezier(custom);
        if (!curve) {
          key = fallback;
          usedFallback = true;
        }
      }
      // A counter showing 101 then 100 would be wrong: no overshoot.
      if (family === "counter" && (key === "snappy" || key === "bouncy")) {
        key = "smooth";
      }
    }

    var profile = buildProfile(key, key === "custom" ? curve : null);
    return {
      key: profile.key,
      // A counter must not go past its final value, whatever the curve.
      ease: family === "counter" ? profile.safeEase : profile.ease,
      safeEase: profile.safeEase,
      settle: profile.settle,
      fallback: usedFallback,
      curve: profile.curve,
    };
  }

  /* ================================================================
   * TEMPO
   * ================================================================ */

  var TEMPOS = { "very-fast": 0.5, fast: 0.75, normal: 1, slow: 1.5, "very-slow": 2 };

  // Multiplier of a tempo keyword; null for "custom" (unscaled); 1 for anything unknown.
  function tempoFactor(keyword) {
    if (keyword === "custom") {
      return null;
    }
    return has(TEMPOS, keyword) ? TEMPOS[keyword] : 1;
  }

  /* ================================================================
   * SETTINGS RESOLUTION
   * ================================================================ */

  // Keep in sync with Effects::catalog() (includes/Modules/Motion/Effects.php).
  var EFFECTS = {
    fade: { family: "enter", scrub: false },
    "slide-up": { family: "enter", scrub: false },
    blur: { family: "enter", scrub: false },
    "blur-up": { family: "enter", scrub: false },
    zoom: { family: "enter", scrub: false },
    "zoom-blur": { family: "enter", scrub: false },
    lines: { family: "text", scrub: false },
    words: { family: "text", scrub: true },
    letters: { family: "text", scrub: true },
    counter: { family: "counter", scrub: false },
    underline: { family: "underline", scrub: false },
  };

  // Used when neither the element nor the module settings provide a value.
  var INTERNAL = {
    duration: 0.6,
    stagger: 0.08,
    delay: 0,
    distance: 40,
    blur: 10,
    scale: 0.9,
    from_opacity: 0.1,
    start_value: 0,
  };

  var RANGES = {
    duration: [0, 60],
    stagger: [0, 10],
    delay: [0, 60],
    distance: [-2000, 2000],
    blur: [0, 200],
    scale: [0, 10],
    from_opacity: [0, 1],
    start_value: [-1e12, 1e12],
  };

  var INTERNAL_START = { edge: "top", at: "98%" };
  var INTERNAL_END = { edge: "top", at: "-120%" };
  var EDGES = ["top", "center", "bottom"];
  // A ScrollTrigger position: one or two tokens, each an edge keyword or a length, with an
  // optional "+=" / "-=" offset ("top 80%", "top+=50 center", "+=500"); "foo" is refused.
  // Same rule as SettingsSanitizer::is_position(): tools/motion/positions.json holds the
  // cases both test suites check.
  var POSITION_LENGTH = "(?:\\d+(?:\\.\\d+)?|\\.\\d+)(?:px|%)?";
  var POSITION_OFFSET = "[+-]=" + POSITION_LENGTH;
  var POSITION_TOKEN =
    "(?:(?:top|center|bottom|left|right)(?:" + POSITION_OFFSET + ")?|-?" + POSITION_LENGTH + "(?:" + POSITION_OFFSET + ")?|" + POSITION_OFFSET + ")";
  var POSITION_RE = new RegExp("^" + POSITION_TOKEN + "(?: +" + POSITION_TOKEN + ")?$", "i");
  var POSITION_MAX = 40;
  var AT_RE = /^[+-]?(?:\d+(?:\.\d+)?|\.\d+)(?:%|px)?$/i;
  var NUMBER_RE = /^([+-]?(?:\d+(?:\.\d*)?|\.\d+))\s*(ms|s|px|%|deg)?$/i;
  var TIME_SETTINGS = ["duration", "delay", "stagger"];
  var BARE_NUMBER_RE = /^[+-]?(?:\d+(?:\.\d+)?|\.\d+)$/;

  // A value as the browser hands it over: a string, or a number from the JSON configuration.
  function text(value) {
    if (typeof value === "string") {
      return value.trim();
    }
    if (typeof value === "number" && isFinite(value)) {
      return String(value);
    }
    return "";
  }

  // NaN when the value is not a plain number; a unit suffix (1.2s, 30px, 120%) is tolerated.
  // Milliseconds are read for time settings only and converted to seconds.
  function readNumber(value, time) {
    if (typeof value === "number") {
      return isFinite(value) ? value : NaN;
    }
    var m = NUMBER_RE.exec(text(value));
    if (!m) {
      return NaN;
    }
    var n = parseFloat(m[1]);
    if (m[2] && m[2].toLowerCase() === "ms") {
      return time ? n / 1000 : NaN;
    }
    return n;
  }

  function inRange(name, n) {
    return isFinite(n) && n >= RANGES[name][0] && n <= RANGES[name][1];
  }

  // raw (when valid) > module settings > internal value.
  function pickNumber(name, raw, settings, warnings) {
    var time = TIME_SETTINGS.indexOf(name) > -1;
    var typed = text(raw[name.replace(/_/g, "-")]);
    if (typed !== "") {
      var n = readNumber(typed, time);
      if (inRange(name, n)) {
        return n;
      }
      warnings.push("Invalid " + name.replace(/_/g, "-") + " " + JSON.stringify(typed) + ": reference value used.");
    }
    var configured = readNumber(settings[name], time);
    return inRange(name, configured) ? configured : INTERNAL[name];
  }

  function validEdge(value) {
    var edge = text(value).toLowerCase();
    return EDGES.indexOf(edge) > -1 ? edge : "";
  }

  // "80" and "80%" both give "80%"; "" when the value is not a position offset.
  function normalizeAt(value) {
    var at = text(value);
    if (!AT_RE.test(at)) {
      return "";
    }
    return BARE_NUMBER_RE.test(at) ? at + "%" : at;
  }

  // Full position (advanced) > edge + offset > module settings > internal value.
  function pickPosition(name, raw, settings, internal, warnings) {
    var full = text(raw[name]);
    if (full !== "") {
      if (full.length <= POSITION_MAX && POSITION_RE.test(full)) {
        return full;
      }
      warnings.push("Invalid " + name + " position " + JSON.stringify(full) + ": reference value used.");
    }

    var typedEdge = text(raw[name + "-edge"]);
    var typedAt = text(raw[name + "-at"]);
    var edge = validEdge(typedEdge);
    var at = normalizeAt(typedAt);

    if (typedEdge !== "" && edge === "") {
      warnings.push("Invalid " + name + "-edge " + JSON.stringify(typedEdge) + ": reference value used.");
    }
    if (typedAt !== "" && at === "") {
      warnings.push("Invalid " + name + "-at " + JSON.stringify(typedAt) + ": reference value used.");
    }

    return (
      (edge || validEdge(settings[name + "_edge"]) || internal.edge) +
      " " +
      (at || normalizeAt(settings[name + "_at"]) || internal.at)
    );
  }

  function resolve(slug, raw, config) {
    var warnings = [];
    raw = isObject(raw) ? raw : {};
    config = isObject(config) ? config : {};

    var effect = typeof slug === "string" ? slug : "";
    var known = has(EFFECTS, effect);
    if (!known) {
      warnings.push("Unknown effect " + JSON.stringify(effect) + ": enter defaults used.");
    }
    var def = known ? EFFECTS[effect] : EFFECTS.fade;
    var family = def.family;
    var scrub = def.scrub;

    var effects = isObject(config.effects) ? config.effects : {};
    var settings = known && has(effects, effect) && isObject(effects[effect]) ? effects[effect] : {};

    var start = pickPosition("start", raw, settings, INTERNAL_START, warnings);
    var end = scrub ? pickPosition("end", raw, settings, INTERNAL_END, warnings) : "top -120%";

    var configTempo = has(LEGACY_TEMPO, config.tempo) ? LEGACY_TEMPO[config.tempo] : config.tempo;
    var siteTempo = has(TEMPOS, configTempo) || configTempo === "custom" ? configTempo : "normal";
    var typedTempo = text(raw.tempo).toLowerCase();
    if (has(LEGACY_TEMPO, typedTempo)) {
      typedTempo = LEGACY_TEMPO[typedTempo];
    }
    var tempo = siteTempo;
    if (typedTempo !== "") {
      if (has(TEMPOS, typedTempo) || typedTempo === "custom") {
        tempo = typedTempo;
      } else {
        warnings.push("Invalid tempo " + JSON.stringify(typedTempo) + ": site tempo used.");
      }
    }

    var delay = pickNumber("delay", raw, settings, warnings);
    var stagger = pickNumber("stagger", raw, settings, warnings);
    var duration;

    if (scrub) {
      // A scrub follows the scroll: no duration of its own, no tempo.
      duration = 1;
    } else {
      var factor = tempoFactor(tempo);
      if (factor === null) {
        duration = pickNumber("duration", raw, settings, warnings);
      } else {
        // The duration typed on a class is for "custom" only; other tempos scale the reference.
        if (text(raw.duration) !== "") {
          warnings.push("Typed duration ignored: tempo is not custom.");
        }
        duration = pickNumber("duration", {}, settings, warnings) * factor;
        // Unlike the duration, a typed stagger stays in force whatever the tempo: it is a spacing
        // between items, so the tempo scales it like the reference one ("fast" tightens the lot).
        stagger *= factor;
      }
    }

    var configEase = has(LEGACY_EASE, config.ease) ? LEGACY_EASE[config.ease] : config.ease;
    var siteEase = has(BOUNCE, configEase) || configEase === "linear" ? configEase : "smooth";
    var typedEase = text(raw.ease).toLowerCase();
    if (has(LEGACY_EASE, typedEase)) {
      typedEase = LEGACY_EASE[typedEase];
    }
    var easeKeyword = typedEase !== "" ? typedEase : config.ease === "custom" ? "custom" : siteEase;
    var custom = text(raw["ease-custom"]) || text(config.easeCustom);
    var curve = resolveEase(easeKeyword, custom, family, scrub, siteEase);
    if (curve.fallback && !scrub) {
      warnings.push("Invalid easing " + JSON.stringify(typedEase || easeKeyword) + ": " + curve.key + " curve used.");
    }

    return {
      effect: effect,
      family: family,
      scrub: scrub,
      start: start,
      end: end,
      duration: duration,
      tweenDuration: duration * curve.settle,
      delay: delay,
      stagger: stagger,
      easeKey: curve.key,
      ease: curve.ease,
      safeEase: curve.safeEase,
      curve: curve.curve,
      distance: pickNumber("distance", raw, settings, warnings),
      blur: pickNumber("blur", raw, settings, warnings),
      scale: pickNumber("scale", raw, settings, warnings),
      fromOpacity: pickNumber("from_opacity", raw, settings, warnings),
      startValue: pickNumber("start_value", raw, settings, warnings),
      warnings: warnings,
    };
  }

  // Everything that changes how an element moves, as a string: equal when nothing visible changed.
  function signature(o) {
    return JSON.stringify([
      o.effect,
      o.start,
      o.end,
      o.duration,
      o.delay,
      o.stagger,
      o.easeKey,
      o.curve,
      o.distance,
      o.blur,
      o.scale,
      o.fromOpacity,
      o.startValue,
    ]);
  }

  /* ================================================================
   * COUNTER
   * ================================================================ */

  // One run of digits and separators, between two digits (or a single digit).
  var COUNTER_RE = /\d[\d.,\s\u00a0\u202f']*\d|\d/;

  function isSpaceLike(ch) {
    return ch === "'" || /\s/.test(ch);
  }

  function groupDigits(digits, group) {
    if (group === "") {
      return digits;
    }
    var out = "";
    for (var i = 0; i < digits.length; i++) {
      if (i > 0 && (digits.length - i) % 3 === 0) {
        out += group;
      }
      out += digits.charAt(i);
    }
    return out;
  }

  function formatCounter(value, fmt) {
    var n = typeof value === "number" && isFinite(value) ? value : 0;
    var decimals = fmt && fmt.decimals > 0 ? fmt.decimals : 0;
    var fixed = Math.abs(n).toFixed(decimals);
    var parts = fixed.split(".");
    var out = groupDigits(parts[0], (fmt && fmt.group) || "");
    if (parts.length > 1) {
      out += (fmt && fmt.decimal) || ".";
      out += parts[1];
    }
    // A negative value keeps its sign unless it rounds to zero.
    return n < 0 && /[1-9]/.test(fixed) ? "-" + out : out;
  }

  // Splits the numeric run into integer digits, fraction digits and the two separators; null when unsure.
  function readNumeric(run) {
    var kinds = [];
    var lastKind = "";
    var count = {};
    var i;
    var ch;
    for (i = 0; i < run.length; i++) {
      ch = run.charAt(i);
      if (/\d/.test(ch)) {
        continue;
      }
      if (kinds.indexOf(ch) === -1) {
        kinds.push(ch);
        count[ch] = 0;
      }
      count[ch]++;
      lastKind = ch;
    }

    var group = "";
    var decimal = "";

    if (kinds.length === 1) {
      var only = kinds[0];
      if (isSpaceLike(only)) {
        group = only;
      } else if (count[only] > 1) {
        group = only;
      } else {
        var pos = run.indexOf(only);
        var after = run.length - pos - 1;
        var integer = run.slice(0, pos);
        // One separator followed by exactly three digits is a group, unless the integer part is a lone 0.
        if (after === 3 && integer !== "0") {
          group = only;
        } else {
          decimal = only;
        }
      }
    } else if (kinds.length === 2) {
      decimal = lastKind;
      group = kinds[0] === decimal ? kinds[1] : kinds[0];
      if ((decimal !== "." && decimal !== ",") || count[decimal] !== 1) {
        return null;
      }
    } else if (kinds.length > 2) {
      return null;
    }

    var digits = run;
    var fraction = "";
    if (decimal !== "") {
      var d = run.lastIndexOf(decimal);
      digits = run.slice(0, d);
      fraction = run.slice(d + 1).replace(/\D/g, "");
    }
    digits = digits.replace(/\D/g, "");

    return { digits: digits, fraction: fraction, group: group, decimal: decimal };
  }

  function parseCounter(source) {
    if (typeof source !== "string") {
      return null;
    }
    var m = COUNTER_RE.exec(source);
    if (!m) {
      return null;
    }

    var prefix = source.slice(0, m.index);
    var suffix = source.slice(m.index + m[0].length);
    // A second number ("24/7", "2020 - 2024") makes the text ambiguous: leave it alone.
    if (/\d/.test(prefix) || /\d/.test(suffix)) {
      return null;
    }
    // A separator hugging the number from outside (".5", "5.") is not ours to rewrite.
    if (/[.,]$/.test(prefix) || /^[.,]/.test(suffix)) {
      return null;
    }

    var parts = readNumeric(m[0]);
    if (!parts) {
      return null;
    }

    var fmt = {
      prefix: prefix,
      value: parseFloat(parts.digits + (parts.fraction ? "." + parts.fraction : "")),
      decimals: parts.fraction.length,
      group: parts.group,
      decimal: parts.decimal,
      suffix: suffix,
    };
    if (!isFinite(fmt.value)) {
      return null;
    }

    // The text must come back identical once rebuilt, otherwise the counter would rewrite it.
    if (prefix + formatCounter(fmt.value, fmt) + suffix !== source) {
      return null;
    }
    return fmt;
  }

  return {
    bezier: bezier,
    spring: spring,
    cssLinear: cssLinear,
    resolveEase: resolveEase,
    tempoFactor: tempoFactor,
    resolve: resolve,
    signature: signature,
    parseCounter: parseCounter,
    formatCounter: formatCounter,
  };
});
