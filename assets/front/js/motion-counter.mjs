/**
 * Lümia Tools - Motion counter adapter
 * Rolls the digits of a counter with NumberFlow (vendor/number-flow, lite build). Not loaded as
 * it is: tools/motion/js/build-counter.mjs bundles it with NumberFlow into motion-counter.js, a
 * classic script loaded only on the pages that have a counter. Uses the public API of the
 * lite build only: define(), formatToData(), canAnimate and the data / animated / timing
 * properties of the element.
 *
 * Exposes window.lumiaMotionCounter, read by the engine (motion.js), then dispatches
 * "lumia-motion-counter-ready" on document:
 *
 * - mount(node, fmt, opts): replaces the text node holding the number with an
 *   <lm-number-flow> element showing opts.from, in the format of fmt (core.parseCounter).
 *   Returns a handle, or null when NumberFlow cannot animate in this browser (the text
 *   is left as written).
 * - play(handle, opts): rolls to the written value. opts: duration and delay in seconds,
 *   easing (a CSS easing), onEnd (called once, when it is over).
 * - reset(handle): stops, and puts the text node back, untouched.
 *
 * opts.fixed (mount): every digit of the final value exists from the start ("0 000" before
 * "1 250") and all digits have the same width, so the number never changes width.
 *
 * The element stays once the animation is over: going back to the text would shift the
 * number by a few pixels. The engine hides it from assistive technologies (its name, from
 * ElementInternals, is the start value until it plays) and gives them a copy of the text.
 */
import NumberFlowLite, { define, formatToData, canAnimate } from "number-flow/lite";

const TAG = "lm-number-flow";
// The opacity of a digit that appears or leaves (NumberFlow's default, not changed here).
const OPACITY_MS = 450;
// Margin over the expected end before onEnd is called anyway (a hidden tab runs no animation).
const END_MARGIN_MS = 150;

define(TAG, NumberFlowLite);

// Number of integer digits of a value written with this many decimals.
function integerDigits(value, decimals) {
  return Math.abs(value).toFixed(decimals).split(".")[0].length;
}

// An Intl formatter that writes the separators of the page, never the locale's ones.
function formatter(fmt, digits) {
  const intl = new Intl.NumberFormat("en-US", {
    minimumFractionDigits: fmt.decimals,
    maximumFractionDigits: fmt.decimals,
    useGrouping: fmt.group !== "",
    minimumIntegerDigits: Math.min(21, Math.max(1, digits)),
  });
  return {
    formatToParts(value) {
      return intl.formatToParts(value).map((part) => {
        if (part.type === "group") {
          return { type: "group", value: fmt.group };
        }
        if (part.type === "decimal") {
          return { type: "decimal", value: fmt.decimal };
        }
        return part;
      });
    },
  };
}

function stop(handle) {
  clearTimeout(handle.timer);
  clearTimeout(handle.endTimer);
  cancelAnimationFrame(handle.frame);
  handle.timer = 0;
  handle.endTimer = 0;
  handle.frame = 0;
  if (handle.onFinish) {
    handle.flow.removeEventListener("animationsfinish", handle.onFinish);
    handle.onFinish = null;
  }
}

function data(handle, value) {
  return formatToData(value, handle.format, handle.fmt.prefix, handle.fmt.suffix);
}

window.lumiaMotionCounter = {
  mount(node, fmt, opts) {
    if (!canAnimate || !node || !node.parentNode || !fmt) {
      return null;
    }
    const o = opts || {};
    const fixed = !!o.fixed;
    const flow = document.createElement(TAG);
    const handle = {
      flow,
      node,
      fmt,
      format: formatter(fmt, fixed ? integerDigits(fmt.value, fmt.decimals) : 1),
      timer: 0,
      endTimer: 0,
      frame: 0,
      onFinish: null,
    };
    if (fixed) {
      flow.style.fontVariantNumeric = "tabular-nums";
    }
    flow.animated = false;
    flow.data = data(handle, typeof o.from === "number" && isFinite(o.from) ? o.from : 0);
    node.parentNode.replaceChild(flow, node);
    return handle;
  },

  play(handle, opts) {
    if (!handle || !handle.flow.isConnected) {
      return;
    }
    stop(handle);
    const o = opts || {};
    const ms = Math.max(0, (Number(o.duration) || 0) * 1000);
    const delay = Math.max(0, (Number(o.delay) || 0) * 1000);
    const timing = { duration: ms, easing: typeof o.easing === "string" && o.easing ? o.easing : "linear" };
    let ended = false;
    const end = () => {
      if (ended) {
        return;
      }
      ended = true;
      stop(handle);
      if (typeof o.onEnd === "function") {
        o.onEnd();
      }
    };
    handle.flow.transformTiming = timing;
    handle.flow.spinTiming = timing;
    handle.timer = setTimeout(() => {
      handle.timer = 0;
      // Two frames: the start value is painted before the change, or nothing would roll.
      handle.frame = requestAnimationFrame(() => {
        handle.frame = requestAnimationFrame(() => {
          handle.frame = 0;
          handle.onFinish = end;
          handle.flow.addEventListener("animationsfinish", end);
          handle.flow.animated = true;
          handle.flow.data = data(handle, handle.fmt.value);
          handle.endTimer = setTimeout(end, Math.max(ms, OPACITY_MS) + END_MARGIN_MS);
        });
      });
    }, delay);
  },

  reset(handle) {
    if (!handle) {
      return;
    }
    stop(handle);
    const flow = handle.flow;
    if (flow.parentNode) {
      flow.parentNode.replaceChild(handle.node, flow);
    }
  },
};

document.dispatchEvent(new Event("lumia-motion-counter-ready"));
