/**
 * Lümia Tools - Motion engine
 * Entry point of the engine. It reads window.lumiaMotionConfig, printed by the plugin
 * before this file, window.lumiaMotionCore (the pure helpers), and GSAP + ScrollTrigger.
 *
 * Every element marked data-lm becomes an item: its effect comes from the computed
 * --lm-effect (element > class, per breakpoint, by the CSS cascade), else from the first
 * effect class of its class attribute. Its settings are the engine variables (config.variables), read
 * once when the item is built and again on refresh(), never per frame.
 *
 * Each family of effects has a builder (FAMILIES below): entrance effects, text effects
 * (SplitText) and the counter (NumberFlow, through motion-counter.js), all behind the same hooks.
 *
 * In the Bricks canvas (config.builder) nothing is hidden and nothing follows the scroll:
 * every element is in its final state. motion-builder.js calls scan(), which reads the page
 * again; an element whose settings changed since the last read plays once (preview hook of
 * its family), unless the site setting says "never animate" (config.preview).
 */
(function () {
  "use strict";

  // Loaded twice (a page builder preview, a caching plugin that merges files): the first one wins.
  if (window.lumiaMotion) {
    return;
  }

  var config = window.lumiaMotionConfig || {};
  var root = document.documentElement;

  // The engine this one replaces is still on the page: two engines would animate the same
  // elements twice. Leave the page readable and say why.
  if (config.legacyGlobal && window[config.legacyGlobal]) {
    if (window.console && console.warn) {
      console.warn(
        "Lumia Motion did not start: window." +
          config.legacyGlobal +
          " is already loaded. Deactivate the former Motion snippet."
      );
    }
    root.classList.add("lm-ready");
    return;
  }

  /* ================================================================
   * CONSTANTS AND STATE
   * ================================================================ */

  var core = window.lumiaMotionCore;
  var gsap = window.gsap;
  var ScrollTrigger = window.ScrollTrigger;
  var SplitText = window.SplitText; // loaded only on pages with a text effect

  var DEBUG = /[?&]lmDebug(?:[=&]|$)/.test(window.location.search);
  var FONT_WAIT_MS = 1500;
  var RESIZE_MS = 200;
  var SELECTOR = "[data-lm]";
  var BRICKS_CONTENT_EVENTS = [
    "bricks/ajax/query_result/displayed",
    "bricks/ajax/load_page/completed",
    "bricks/ajax/pagination/completed",
    "bricks/ajax/popup/loaded",
  ];
  var BRICKS_POPUP_OPEN = "bricks/popup/open";
  // Inline properties an entrance effect writes; put back as they were once it is over.
  var ANIMATED = ["transform", "opacity", "filter", "willChange"];
  var BUILDER = !!config.builder;
  var PREVIEW_ON = BUILDER && config.preview === "replay";
  var replayOn = false; // PREVIEW_ON, and the visitor has not asked for reduced motion (set per matchMedia branch)
  var motionOk = false; // the visitor has not asked for reduced motion (set per matchMedia branch)
  // Builder only: the ScrollTrigger markers asked by the panel ("Show markers"), a preference of
  // the browser kept by motion-panel.js in the main window (same origin, same storage).
  var MARKERS_KEY = "lmMotionMarkers";
  var markersOn = BUILDER && readMarkersPref();
  var markerTriggers = []; // triggers that only draw markers: nothing follows the scroll in the canvas
  var destroyHooks = [];
  // Seconds a text scrub takes when it is replayed in the builder: 0 to 1, whatever its length.
  var PREVIEW_SCRUB_S = 1.2;
  // The builder canvas: every element with a Bricks class, whether the PHP marker saw it or not.
  var BUILDER_SELECTOR = '[data-lm], [class*="brxe-"]';

  var VARIABLES = Array.isArray(config.variables) ? config.variables : [];
  var CLASSES = isObject(config.classes) ? config.classes : {};
  var ALIASES = isObject(config.aliases) ? config.aliases : {};
  var SLUGS = {};
  Object.keys(CLASSES).forEach(function (name) {
    SLUGS[CLASSES[name]] = true;
  });

  var items = [];
  var itemOf = typeof WeakMap === "function" ? new WeakMap() : null;
  var told = typeof WeakMap === "function" ? new WeakMap() : null;
  var serial = 0;
  var media = null; // gsap.matchMedia(), null once destroyed
  var running = false; // a matchMedia branch is active
  var animate = false; // true in the no-preference branch, outside the builder
  var resizeTimer = 0;
  var contentTimer = 0;
  var CONTENT_MS = 50; // refresh of the triggers after content events, grouped (a page, then its results)
  var lastWidth = window.innerWidth;
  var listening = false;
  var branches = 0; // matchMedia branches run so far
  var endWatch = null; // ScrollTrigger at the end of the page, see watchPageEnd()
  var END_MARGIN = 2; // px: the end of the page as ScrollTrigger rounds it
  var stats = { scans: 0, replays: 0 };
  // Builder only: what each element looked like at the last read, by element id (its
  // signature, "" when it had no effect). Bricks renders an element again as a new node
  // when a setting changes, so the id is the only thing that survives.
  var lastSeen = {};

  /* ================================================================
   * HELPERS
   * ================================================================ */

  function isObject(value) {
    return value !== null && typeof value === "object" && !Array.isArray(value);
  }

  function has(object, key) {
    return Object.prototype.hasOwnProperty.call(object, key);
  }

  function describe(el) {
    if (el.id) {
      return "#" + el.id;
    }
    var name = el.tagName.toLowerCase();
    return el.classList.length ? name + "." + Array.prototype.join.call(el.classList, ".") : name;
  }

  function readMarkersPref() {
    try {
      return window.localStorage.getItem(MARKERS_KEY) === "1";
    } catch (error) {
      return false;
    }
  }

  function log(message) {
    if (DEBUG && window.console && console.info) {
      console.info("Lumia Motion: " + message);
    }
  }

  function report(message, error) {
    if (DEBUG && window.console && console.error) {
      console.error("Lumia Motion: " + message, error);
    }
  }

  // Logs a message once per element, so a refresh does not repeat what the load already said.
  function logOnce(el, message) {
    if (!DEBUG) {
      return;
    }
    if (told) {
      var said = told.get(el) || {};
      if (has(said, message)) {
        return;
      }
      said[message] = true;
      told.set(el, said);
    }
    log(message);
  }

  function lookup(el) {
    if (itemOf) {
      return itemOf.get(el) || null;
    }
    for (var i = 0; i < items.length; i++) {
      if (items[i].el === el) {
        return items[i];
      }
    }
    return null;
  }

  // The key of an element across renders in the builder, or "" when it has none.
  function builderKey(el) {
    var id = (el.dataset && el.dataset.scriptId) || el.id || "";
    var index = el.getAttribute("data-query-loop-index");
    return id && index ? id + ":" + index : id;
  }

  // Whether an element is the Bricks element of this id (the key of a loop item counts too).
  function isBricksId(el, id) {
    var base = builderKey(el).split(":")[0];
    return base === id || base === "brxe-" + id;
  }

  // The canvas element of a Bricks id: its data-script-id (printed in the builder), else #brxe-<id>.
  function elementOf(id) {
    var list = document.querySelectorAll("[data-script-id]");
    for (var i = 0; i < list.length; i++) {
      if (list[i].getAttribute("data-script-id") === id) {
        return list[i];
      }
    }
    return document.getElementById("brxe-" + id);
  }

  // The canvas: an element added there has no data-lm (the marker ran when the page was
  // rendered), so any Bricks element whose computed --lm-effect or class gives an effect is
  // a candidate, and so is every known item (to notice an effect that was taken away). The
  // others are noted as seen without effect: an effect given to them later is a change.
  function builderCandidates(scope) {
    var list = [];
    var nodes = [];
    if (scope && scope.nodeType === 1 && scope.matches && scope.matches(BUILDER_SELECTOR)) {
      nodes.push(scope);
    }
    if (scope && scope.querySelectorAll) {
      Array.prototype.push.apply(nodes, scope.querySelectorAll(BUILDER_SELECTOR));
    }
    nodes.forEach(function (el) {
      var effect = window.getComputedStyle(el).getPropertyValue("--lm-effect").trim();
      if (effect !== "" || el.hasAttribute("data-lm") || lookup(el) || classEffect(el) !== "") {
        list.push(el);
        return;
      }
      var key = builderKey(el);
      if (key) {
        lastSeen[key] = "";
      }
    });
    return list;
  }

  function candidates(scope) {
    if (BUILDER) {
      return builderCandidates(scope);
    }
    var list = [];
    if (scope && scope.nodeType === 1 && scope.matches && scope.matches(SELECTOR)) {
      list.push(scope);
    }
    if (scope && scope.querySelectorAll) {
      Array.prototype.push.apply(list, scope.querySelectorAll(SELECTOR));
    }
    return list;
  }

  /* ================================================================
   * READING AN ELEMENT
   * One getComputedStyle per element and per build or refresh.
   * ================================================================ */

  // The first effect class of the class attribute, in its order.
  function classEffect(el) {
    var found = [];
    for (var i = 0; i < el.classList.length; i++) {
      var name = el.classList[i];
      if (has(CLASSES, name)) {
        found.push({ name: name, slug: CLASSES[name] });
      } else if (has(ALIASES, name)) {
        found.push({ name: name, slug: ALIASES[name] });
      }
    }
    if (!found.length) {
      return "";
    }
    if (found.length > 1) {
      logOnce(
        el,
        describe(el) +
          " has several effect classes (" +
          found
            .map(function (f) {
              return f.name;
            })
            .join(", ") +
          "): " +
          found[0].name +
          " (" +
          found[0].slug +
          ") is used, the first one in its class attribute."
      );
    }
    return found[0].slug;
  }

  // Effect slug and raw settings of an element, or null with the reason it is left alone.
  function read(el) {
    var style = window.getComputedStyle(el);
    var raw = {};
    for (var i = 0; i < VARIABLES.length; i++) {
      // "lm-start-edge" is read as --lm-start-edge and handed over as "start-edge".
      raw[VARIABLES[i].replace(/^lm-/, "")] = style.getPropertyValue("--" + VARIABLES[i]).trim();
    }
    var effect = (raw.effect || "").toLowerCase() || classEffect(el);
    var rect = el.getBoundingClientRect();
    // A box-less element (display: none, a closed popup) is neither above nor on screen: its
    // rectangle is all zeros, which used to read as "on screen" and settled it in a quiet build.
    var boxed = el.getClientRects().length > 0;
    return {
      el: el,
      effect: effect,
      raw: raw,
      above: boxed && rect.bottom < 0,
      onScreen: boxed && rect.top < window.innerHeight,
      end: authorEnd(style),
    };
  }

  // Opacity and filter an entrance ends at: the element's own values, as the load animation of
  // the stylesheet does. Read before the engine writes anything (the pre-hide is off by then).
  function authorEnd(style) {
    var opacity = parseFloat(style.opacity);
    var filter = (style.filter || "").trim();
    return {
      opacity: isFinite(opacity) ? opacity : 1,
      filter: filter === "" || filter === "none" ? "" : filter,
    };
  }

  // Blur list of an entrance, composed with the author's filter so both ends have the same functions.
  function blurFilter(px, end) {
    return "blur(" + px + "px)" + (end && end.filter ? " " + end.filter : "");
  }

  // Why an effect is not built, or null when it is ("" = skipped without a message).
  function skipReason(effect, options, el) {
    if (effect === "" || effect === "none") {
      return effect === "none" ? "effect set to none" : "no effect";
    }
    // Played by the stylesheet from the first frame (first screen, see Marker): no trigger, no
    // initial state. The canvas never gets the attribute: the builder replays through the engine.
    if (el && el.hasAttribute("data-lm-load")) {
      return "load";
    }
    // Text and counter effects of the first screen (see Marker): they have no CSS version, and
    // splitting the painted text, or showing the counter at its start value, would make it flash.
    // Left as written, which is their final state (a scrub included: every word fully visible).
    // Never set in the canvas either: Replay still plays them there.
    if (el && el.hasAttribute("data-lm-settled")) {
      return "settled";
    }
    if (!has(SLUGS, effect)) {
      return "unknown effect " + JSON.stringify(effect);
    }
    if (!has(FAMILIES, options.family)) {
      // The underline is drawn by the stylesheet.
      return options.family === "underline" ? "" : options.family + " effects are not handled by this engine";
    }
    if (options.family === "text" && !SplitText) {
      return "SplitText is not loaded on this page";
    }
    return null;
  }

  /* ================================================================
   * ITEMS
   * ================================================================ */

  function saveInline(el) {
    var saved = {};
    for (var i = 0; i < ANIMATED.length; i++) {
      saved[ANIMATED[i]] = el.style[ANIMATED[i]];
    }
    return saved;
  }

  // Removes what GSAP wrote (its transform cache included), then puts back the author's inline values.
  function restoreInline(item) {
    gsap.set(item.el, { clearProps: "transform,opacity,filter,willChange" });
    for (var i = 0; i < ANIMATED.length; i++) {
      if (item.inline[ANIMATED[i]]) {
        item.el.style[ANIMATED[i]] = item.inline[ANIMATED[i]];
      }
    }
  }

  function create(entry, options, end) {
    var item = {
      id: entry.el.id || "lm-" + ++serial,
      el: entry.el,
      effect: entry.effect,
      family: options.family,
      options: options,
      signature: core.signature(options),
      state: "pending",
      trigger: null,
      tweens: [],
      inline: saveInline(entry.el),
      end: end || entry.end || { opacity: 1, filter: "" },
    };
    items.push(item);
    if (itemOf) {
      itemOf.set(entry.el, item);
    }
    return item;
  }

  function forget(item) {
    var index = items.indexOf(item);
    if (index > -1) {
      items.splice(index, 1);
    }
    if (itemOf) {
      itemOf.delete(item.el);
    }
  }

  // Shows the element as it ends, at once, and stops watching it.
  function settle(item) {
    try {
      FAMILIES[item.family].final(item);
    } catch (error) {
      report("could not show " + describe(item.el) + " in its final state.", error);
    }
    item.state = "final";
  }

  // Runs one step of an item's animation; on error the element is shown as it ends.
  function attempt(item, step) {
    try {
      step(item);
    } catch (error) {
      report("animation failed: " + describe(item.el) + " shown as it ends.", error);
      if (item.state !== "final") {
        settle(item);
      }
    }
  }

  // Builder: plays an item once, from its start state to its final one. The element is final
  // (settled) when this is called, and ends final again; a change that comes first kills the
  // item, which puts the element back as it ends.
  // Whether the item has something to replay (underline has no preview: it is CSS on hover).
  function replay(item) {
    var family = FAMILIES[item.family];
    if (typeof family.preview !== "function") {
      return false;
    }
    stats.replays++;
    attempt(item, family.preview);
    return true;
  }

  // Undoes everything the item did: the element goes back to how the page rendered it.
  function kill(item) {
    try {
      FAMILIES[item.family].kill(item);
    } catch (error) {
      report("could not reset " + describe(item.el) + ".", error);
    }
    forget(item);
  }

  /* ================================================================
   * FAMILY: ENTER (fade, slide-up, blur, blur-up, zoom, zoom-blur)
   * Two tweens per lot: the transforms follow the curve, overshoot included; opacity and
   * blur follow its safe version, which never goes past 1 or below 0. Same duration,
   * delay and stagger, so both stay in step.
   * ================================================================ */

  function enterFrom(o, end) {
    var move = {};
    var look = { opacity: 0 };
    if (o.effect === "slide-up" || o.effect === "blur-up") {
      move.y = o.distance;
    }
    if (o.effect === "zoom" || o.effect === "zoom-blur") {
      move.scale = o.scale;
    }
    if (o.effect === "blur" || o.effect === "blur-up" || o.effect === "zoom-blur") {
      look.filter = blurFilter(o.blur, end);
    }
    return { move: move, look: look };
  }

  function willChange(from) {
    var list = Object.keys(from.move).length ? ["transform", "opacity"] : ["opacity"];
    if (from.look.filter) {
      list.push("filter");
    }
    return list.join(", ");
  }

  function enterFinish() {
    var item = lookup(this.targets()[0]);
    if (item && item.state === "playing" && --item.running <= 0) {
      item.tweens = [];
      restoreInline(item);
      item.state = "done";
    }
  }

  function enterPlay(options, elements) {
    var from = enterFrom(options, null);
    var moves = Object.keys(from.move).length > 0;
    var bottoms = elements.map(function (el) {
      return el.getBoundingClientRect().bottom;
    });
    var lot = [];

    elements.forEach(function (el, i) {
      var item = lookup(el);
      if (!item || item.state !== "pending") {
        return;
      }
      if (item.trigger) {
        item.trigger.kill();
        item.trigger = null;
      }
      // Already above the screen (scroll restored after the build, a jump past it): no animation.
      if (bottoms[i] < 0) {
        settle(item);
        return;
      }
      item.state = "playing";
      item.running = moves ? 2 : 1;
      lot.push(item);
    });
    if (!lot.length) {
      return;
    }

    var targets = lot.map(function (item) {
      return item.el;
    });
    gsap.set(targets, { willChange: willChange(from) });
    var tweens = [];

    if (moves) {
      var move = {
        duration: options.tweenDuration,
        ease: options.ease,
        delay: options.delay,
        stagger: { each: options.stagger, onComplete: enterFinish },
      };
      Object.keys(from.move).forEach(function (key) {
        move[key] = key === "scale" ? 1 : 0;
      });
      tweens.push(gsap.to(targets, move));
    }

    var look = {
      opacity: function (i, el) {
        var item = lookup(el);
        return item ? item.end.opacity : 1;
      },
      duration: options.tweenDuration,
      ease: options.safeEase,
      delay: options.delay,
      stagger: { each: options.stagger, onComplete: enterFinish },
    };
    if (from.look.filter) {
      look.filter = function (i, el) {
        var item = lookup(el);
        return blurFilter(0, item && item.end);
      };
    }
    tweens.push(gsap.to(targets, look));

    // Each item keeps the lot's tweens, to stop its own part of them and nothing else.
    lot.forEach(function (item) {
      item.tweens = tweens;
    });
  }

  var enter = {
    // Sets the start state of the new pending items and watches them, one batch per signature.
    start: function (list) {
      var groups = {};
      var order = [];
      list.forEach(function (item) {
        try {
          var from = enterFrom(item.options, item.end);
          gsap.set(item.el, Object.assign({}, from.move, from.look));
          if (!has(groups, item.signature)) {
            groups[item.signature] = [];
            order.push(item.signature);
          }
          groups[item.signature].push(item);
        } catch (error) {
          report("could not prepare " + describe(item.el) + ": shown as is.", error);
          settle(item);
        }
      });

      order.forEach(function (signature) {
        var group = groups[signature];
        var options = group[0].options;
        try {
          var triggers = ScrollTrigger.batch(
            group.map(function (item) {
              return item.el;
            }),
            {
              start: options.start,
              once: true,
              markers: DEBUG,
              onEnter: function (elements) {
                try {
                  enterPlay(options, elements);
                } catch (error) {
                  report("animation failed: elements shown as they end.", error);
                  elements.forEach(function (el) {
                    var item = lookup(el);
                    if (item && item.state !== "final") {
                      settle(item);
                    }
                  });
                }
              },
            }
          );
          group.forEach(function (item, i) {
            item.trigger = triggers[i] || null;
          });
        } catch (error) {
          report("could not watch " + group.length + " element(s) for " + signature + ": shown as they end.", error);
          group.forEach(settle);
        }
      });
    },

    // Plays pending items now, without their trigger (a start the page cannot reach).
    play: function (list) {
      var groups = {};
      list.forEach(function (item) {
        (groups[item.signature] = groups[item.signature] || []).push(item);
      });
      Object.keys(groups).forEach(function (signature) {
        enterPlay(
          groups[signature][0].options,
          groups[signature].map(function (item) {
            return item.el;
          })
        );
      });
    },

    // Builder: the entrance, from the start state, now.
    preview: function (item) {
      var from = enterFrom(item.options, item.end);
      gsap.set(item.el, Object.assign({}, from.move, from.look));
      item.state = "pending";
      enterPlay(item.options, [item.el]);
    },

    final: function (item) {
      if (item.trigger) {
        item.trigger.kill();
        item.trigger = null;
      }
      // Only the engine's own tweens, and only this element's part of a lot.
      (item.tweens || []).forEach(function (tween) {
        tween.kill(item.el);
      });
      item.tweens = [];
      restoreInline(item);
    },

    kill: function (item) {
      enter.final(item);
    },
  };

  /* ================================================================
   * FAMILY: TEXT (lines, words, letters)
   * SplitText cuts the text into lines, words or characters. The animation is created and
   * returned in onSplit(): when the text is split again (autoSplit, after a font load or a
   * change of width), SplitText reverts the old animation and gives the new one the same
   * time, on the new pieces. lines plays once, then gives the markup back untouched;
   * words and letters follow the scroll (scrub) for as long as the page lives.
   *
   * Screen readers: SplitText's aria "auto" names the element after its text (aria-label)
   * and hides the pieces. A heading may be named that way; a p or a div may not (ARIA 1.2).
   * Its aria "hidden" hides the element itself, text included. So a heading keeps "auto";
   * any other element gets a visually hidden copy of its content (.lm-sr) next to a box
   * holding the split text, which SplitText hides ("hidden" on the box). Content with a
   * link or a control is never hidden: it is split with aria "none", as it is.
   * ================================================================ */

  var HEADING = /^H[1-6]$/;
  var FOCUSABLE = "a[href], button, input, select, textarea, iframe, [tabindex], [contenteditable]";
  // Displays whose content can be moved into a block box without changing the layout.
  var WRAPPABLE = /^(block|flow-root|list-item|inline-block|table-cell)$/;
  // Scroll time of each word or character, against its stagger: GSAP's default, as in the
  // former snippet (0.25 s apart, a word lights up over the scroll of two).
  var SCRUB_EACH = 0.5;

  // Visually hidden, read aloud, never selected. Styled inline as well as by motion.css: an
  // "unused CSS" optimiser scans the server HTML, where .lm-sr never appears, and may drop
  // the rule; the copy would then show next to the text it duplicates.
  var SR_STYLE =
    "position:absolute;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;" +
    "clip-path:inset(50%);white-space:nowrap;border:0;-webkit-user-select:none;user-select:none";

  function srCopy() {
    var copy = document.createElement("span");
    copy.className = "lm-sr";
    copy.style.cssText = SR_STYLE;
    return copy;
  }

  /* onSplit of an item: records the split first, so a failure can still be reverted, then
   * builds the animation. A failure on the first split goes up to start(), which shows the
   * element as written; on a later split (font load, width), the same happens one tick later,
   * once SplitText is done with that split. */
  function onSplitOf(item, build) {
    return function (self) {
      item.split = self;
      try {
        return build(self);
      } catch (error) {
        if (item.splitting) {
          throw error;
        }
        report("could not split " + describe(item.el) + " again: shown as written.", error);
        setTimeout(function () {
          if (lookup(item.el) === item && item.state !== "final") {
            settle(item);
          }
        }, 0);
      }
    };
  }

  // Splits the target, the first onSplit included.
  function splitText(item, node, vars) {
    item.splitting = true;
    try {
      SplitText.create(node, vars);
    } finally {
      item.splitting = false;
    }
  }

  // The node to split and the SplitText aria mode; moves the content into a box when needed.
  function textTarget(item) {
    var el = item.el;
    if (el.querySelector(FOCUSABLE)) {
      return { node: el, aria: "none" };
    }
    if (HEADING.test(el.tagName)) {
      return { node: el, aria: "auto" };
    }
    if (!WRAPPABLE.test(window.getComputedStyle(el).display)) {
      return { node: el, aria: "none" };
    }
    var copy = srCopy();
    var box = document.createElement("span");
    box.style.display = "block";
    while (el.firstChild) {
      copy.appendChild(el.firstChild.cloneNode(true));
      box.appendChild(el.firstChild);
    }
    // The copy keeps the inline semantics (strong, em, lang); an id must stay unique.
    Array.prototype.forEach.call(copy.querySelectorAll("[id]"), function (node) {
      node.removeAttribute("id");
    });
    el.appendChild(copy);
    el.appendChild(box);
    item.copy = copy;
    item.box = box;
    return { node: box, aria: "hidden" };
  }

  // Puts the content back where it was, once SplitText has reverted it.
  function unwrapText(item) {
    var copy = item.copy;
    var box = item.box;
    item.copy = item.box = null;
    if (!box) {
      return;
    }
    if (box.parentNode === item.el) {
      while (box.firstChild) {
        item.el.insertBefore(box.firstChild, copy && copy.parentNode === item.el ? copy : box);
      }
      item.el.removeChild(box);
    }
    if (copy && copy.parentNode) {
      copy.parentNode.removeChild(copy);
    }
  }

  // Stops everything and gives the markup back as it was written.
  function textReset(item) {
    if (item.trigger) {
      item.trigger.kill();
      item.trigger = null;
    }
    var split = item.split;
    var tweens = item.tweens || [];
    item.split = null;
    item.tweens = [];
    if (split) {
      // Also reverts the last animation returned by onSplit.
      split.revert();
    }
    tweens.forEach(function (tween) {
      tween.kill();
    });
    unwrapText(item);
  }

  // live: the builder preview, which plays at once and follows no scroll.
  function linesStart(item, live) {
    var o = item.options;
    var target = textTarget(item);
    if (live) {
      item.state = "playing";
    }
    splitText(item, target.node, {
      type: "lines",
      mask: "lines",
      linesClass: "lm-line",
      autoSplit: true,
      aria: target.aria,
      onSplit: onSplitOf(item, function (self) {
        var playing = item.state === "playing";
        if (playing) {
          gsap.set(self.lines, { willChange: "transform" });
        }
        // Renders the start state at once (a from tween), plays on enter.
        var tween = gsap.from(self.lines, {
          yPercent: o.distance,
          duration: o.tweenDuration,
          ease: o.ease,
          delay: o.delay,
          stagger: o.stagger,
          paused: !playing,
          onComplete: function () {
            attempt(item, linesDone);
          },
        });
        item.tweens = [tween];
        return tween;
      }),
    });
    if (live) {
      return;
    }
    item.trigger = ScrollTrigger.create({
      trigger: item.el,
      start: o.start,
      once: true,
      markers: DEBUG,
      onEnter: function () {
        attempt(item, linesPlay);
      },
    });
  }

  function linesPlay(item) {
    if (item.state !== "pending") {
      return;
    }
    if (item.trigger) {
      item.trigger.kill();
      item.trigger = null;
    }
    // Already above the screen (a jump past it): no animation.
    if (item.el.getBoundingClientRect().bottom < 0) {
      settle(item);
      return;
    }
    item.state = "playing";
    gsap.set(item.split.lines, { willChange: "transform" });
    item.tweens.forEach(function (tween) {
      tween.play();
    });
  }

  // The mask clips descenders and the split lines would stay in the page: give the markup back.
  function linesDone(item) {
    if (item.state === "playing") {
      textReset(item);
      item.state = "done";
    }
  }

  // live: the builder preview, 0 to 1 over PREVIEW_SCRUB_S, then the text as written.
  function scrubStart(item, live) {
    var o = item.options;
    var chars = o.effect === "letters";
    var target = textTarget(item);
    item.state = live ? "playing" : "scrub";
    splitText(item, target.node, {
      type: chars ? "words, chars" : "words",
      wordsClass: "lm-word",
      charsClass: "lm-char",
      autoSplit: true,
      aria: target.aria,
      onSplit: onSplitOf(item, function (self) {
        var pieces = chars ? self.chars : self.words;
        gsap.set(pieces, { opacity: o.fromOpacity });
        if (live) {
          var preview = gsap.to(pieces, {
            opacity: 1,
            ease: "none",
            duration: SCRUB_EACH,
            stagger: o.stagger,
          });
          // A stagger makes a timeline: duration() scales it to the time asked.
          preview.duration(PREVIEW_SCRUB_S);
          preview.eventCallback("onComplete", function () {
            attempt(item, linesDone);
          });
          item.tweens = [preview];
          return preview;
        }
        var tween = gsap.to(pieces, {
          opacity: 1,
          ease: "none",
          duration: SCRUB_EACH,
          stagger: o.stagger,
          scrollTrigger: {
            trigger: item.el,
            start: o.start,
            end: o.end,
            scrub: true,
            invalidateOnRefresh: true,
            markers: DEBUG,
            // will-change only while the scroll is inside the scrub.
            onToggle: function (st) {
              gsap.set(pieces, st.isActive ? { willChange: "opacity" } : { clearProps: "willChange" });
            },
          },
        });
        item.trigger = tween.scrollTrigger;
        item.tweens = [tween];
        return tween;
      }),
    });
  }

  var text = {
    start: function (list) {
      list.forEach(function (item) {
        try {
          if (item.options.scrub) {
            scrubStart(item);
          } else {
            linesStart(item);
          }
        } catch (error) {
          report("could not split " + describe(item.el) + ": shown as is.", error);
          settle(item);
        }
      });
    },

    // Scrubs are never late: only lines items come here.
    play: function (list) {
      list.forEach(function (item) {
        if (!item.options.scrub) {
          attempt(item, linesPlay);
        }
      });
    },

    // Builder: plays the effect once, whatever the scroll.
    preview: function (item) {
      if (item.options.scrub) {
        scrubStart(item, true);
      } else {
        linesStart(item, true);
      }
    },

    final: textReset,
    kill: textReset,
  };

  /* ================================================================
   * FAMILY: COUNTER
   * Rolls the digits from the start value to the number written in the HTML, in the format
   * it is written in (core.parseCounter), with NumberFlow: motion-counter.js (generated from
   * motion-counter.mjs), loaded on the pages that have a counter, exposes window.lumiaMotionCounter. The number
   * must sit in a single text node; anything else ("24/7", digits split by markup) is left
   * as is. The NumberFlow element replaces the text node and stays once the number is
   * written. A visually hidden copy of the written text (.lm-sr) is what assistive
   * technologies read, from the mount on (the element itself is aria-hidden), and keeps the
   * number in innerText for crawlers.
   *
   * Without the adapter after COUNTER_WAIT_MS (blocked file, browser without module
   * support), or when NumberFlow cannot animate in this browser, the text stays as written.
   *
   * The count style (options.counterStyle "count") counts the value up instead, written frame
   * by frame by countAdapter below, with the same interface: it never waits for NumberFlow.
   * ================================================================ */

  var COUNTER_WAIT_MS = 3000;
  var COUNTER_READY = "lumia-motion-counter-ready";
  var counterWaiting = []; // pending items started before the adapter was there
  var counterTimer = 0;
  var counterGaveUp = false;
  var COUNTER_COPY_STYLE = ";position:static;display:inline-block;margin:0 -1px 0 0;vertical-align:top";

  function counterAdapter() {
    var adapter = window.lumiaMotionCounter;
    return adapter && typeof adapter.mount === "function" && typeof adapter.play === "function" ? adapter : null;
  }

  // Margin over the expected end before the count writes its value anyway (a hidden tab runs no frame).
  var COUNT_END_MARGIN_MS = 150;

  function countStop(handle) {
    clearTimeout(handle.timer);
    clearTimeout(handle.endTimer);
    cancelAnimationFrame(handle.frame);
    handle.timer = 0;
    handle.endTimer = 0;
    handle.frame = 0;
  }

  // Writes a value in the format of the page (core.formatCounter, the parser's own formatter),
  // only when the text changes.
  function countWrite(handle, value) {
    var fmt = handle.fmt;
    var next = fmt.prefix + core.formatCounter(value, fmt, handle.digits) + fmt.suffix;
    if (handle.text.nodeValue !== next) {
      handle.text.nodeValue = next;
    }
  }

  /* Count style: a <span> replaces the text node and shows the value, counted from the start
   * value to the written one on every frame with the counter curve (core.counterProgress, the
   * curve the odometer gets as CSS). The span stays once the value is written, as the NumberFlow
   * element does, and is hidden from assistive technologies the same way (handle.flow). Digits
   * are tabular, so the number does not jitter while it changes; fixed width pads the integer
   * part with zeros to the digits of the written value, as the odometer does. */
  var countAdapter = {
    mount: function (node, fmt, opts) {
      if (!node || !node.parentNode || !fmt) {
        return null;
      }
      var o = opts || {};
      var span = document.createElement("span");
      span.className = "lm-count";
      span.style.fontVariantNumeric = "tabular-nums";
      // The digits live in a shadow root, as NumberFlow's do: innerText leaves them out, so a
      // rendering crawler reads the number once, from the .lm-sr copy, not "620620".
      var root = typeof span.attachShadow === "function" ? span.attachShadow({ mode: "open" }) : span;
      var handle = {
        flow: span,
        node: node,
        fmt: fmt,
        text: root.appendChild(document.createTextNode("")),
        from: typeof o.from === "number" && isFinite(o.from) ? o.from : 0,
        digits: o.fixed ? Math.abs(fmt.value).toFixed(fmt.decimals).split(".")[0].length : 0,
        timer: 0,
        endTimer: 0,
        frame: 0,
      };
      countWrite(handle, handle.from);
      node.parentNode.replaceChild(span, node);
      return handle;
    },

    play: function (handle, opts) {
      if (!handle || !handle.flow.isConnected) {
        return;
      }
      countStop(handle);
      var o = opts || {};
      var ms = Math.max(0, (Number(o.duration) || 0) * 1000);
      var delay = Math.max(0, (Number(o.delay) || 0) * 1000);
      var ease =
        typeof o.ease === "function"
          ? o.ease
          : function (p) {
              return p;
            };
      var from = handle.from;
      var to = handle.fmt.value;
      var ended = false;
      // The last write is the parsed value itself: the text ends exactly as written.
      var end = function () {
        if (ended) {
          return;
        }
        ended = true;
        countStop(handle);
        countWrite(handle, to);
        if (typeof o.onEnd === "function") {
          o.onEnd();
        }
      };
      handle.timer = setTimeout(function () {
        handle.timer = 0;
        var t0 = -1;
        var tick = function (now) {
          handle.frame = 0;
          if (t0 < 0) {
            t0 = now;
          }
          var p = ms > 0 ? (now - t0) / ms : 1;
          if (p >= 1) {
            end();
            return;
          }
          countWrite(handle, from + (to - from) * ease(p));
          handle.frame = requestAnimationFrame(tick);
        };
        handle.frame = requestAnimationFrame(tick);
        handle.endTimer = setTimeout(end, ms + COUNT_END_MARGIN_MS);
      }, delay);
    },

    reset: function (handle) {
      if (!handle) {
        return;
      }
      countStop(handle);
      if (handle.flow.parentNode) {
        handle.flow.parentNode.replaceChild(handle.node, handle.flow);
      }
    },
  };

  function isCount(item) {
    return item.options.counterStyle === "count";
  }

  // The adapter of an item: the count style is always there, the odometer once motion-counter.js ran.
  function counterAdapterFor(item) {
    return isCount(item) ? countAdapter : counterAdapter();
  }

  // The text node holding the number and its format, or { reason } when there is none.
  function counterSource(el) {
    var walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
    var found = null;
    var node;
    while ((node = walker.nextNode())) {
      if (/\d/.test(node.nodeValue)) {
        if (found) {
          return { reason: "its digits are spread over several text nodes" };
        }
        found = node;
      }
    }
    if (!found) {
      return { reason: "no number in its text" };
    }
    var fmt = core.parseCounter(found.nodeValue);
    if (!fmt) {
      return { reason: "no single number in " + JSON.stringify(found.nodeValue.trim()) };
    }
    return { node: found, fmt: fmt };
  }

  // The curve of the counter as a CSS easing (core.counterEase): a custom curve that would go
  // past the value falls back to smooth, said once in debug mode.
  function counterEasing(item) {
    var ease = core.counterEase(item.options);
    if (ease.warning) {
      logOnce(item.el, describe(item.el) + ": " + ease.warning);
    }
    return ease.easing;
  }

  // Replaces the number with its NumberFlow element, at the start value. False when it stays as written.
  function counterMount(item) {
    var adapter = counterAdapterFor(item);
    var source = counterSource(item.el);
    if (source.reason) {
      logOnce(item.el, describe(item.el) + " left as written: " + source.reason + ".");
      return false;
    }
    // NumberFlow draws the digits in its shadow DOM, which innerText, hence a crawler that
    // renders the page, does not read: a visually hidden copy keeps the written text in the
    // page. It is also what assistive technologies read, at every moment: the NumberFlow
    // element is hidden from them (its name would be the start value until it plays).
    var copy = srCopy();
    // Inline-level, unlike the copy of a split text: an absolute box counts as a block in
    // innerText, which would read "350\n projets". It takes no width (1px less 1px of margin).
    copy.style.cssText = SR_STYLE + COUNTER_COPY_STYLE;
    copy.textContent = source.node.nodeValue;
    source.node.parentNode.insertBefore(copy, source.node);
    var handle = null;
    try {
      handle = adapter.mount(source.node, source.fmt, {
        from: item.options.startValue,
        fixed: item.options.counterWidth === "fixed",
      });
    } finally {
      // Not mounted (or the adapter threw): the text stays alone, as written.
      if (!handle && copy.parentNode) {
        copy.parentNode.removeChild(copy);
      }
    }
    if (!handle) {
      logOnce(
        item.el,
        describe(item.el) +
          (isCount(item) ? " left as written: the counter could not be mounted." : " left as written: NumberFlow cannot animate in this browser.")
      );
      return false;
    }
    handle.flow.setAttribute("aria-hidden", "true");
    item.count = { adapter: adapter, handle: handle, copy: copy };
    return true;
  }

  // Stops rolling and puts the written text node back, untouched.
  function counterReset(item) {
    if (item.trigger) {
      item.trigger.kill();
      item.trigger = null;
    }
    var waiting = counterWaiting.indexOf(item);
    if (waiting > -1) {
      counterWaiting.splice(waiting, 1);
    }
    var c = item.count;
    item.count = null;
    if (c) {
      if (c.copy.parentNode) {
        c.copy.parentNode.removeChild(c.copy);
      }
      c.adapter.reset(c.handle);
    }
  }

  function counterPlay(item) {
    if (item.state !== "pending" || !item.count) {
      return;
    }
    if (item.trigger) {
      item.trigger.kill();
      item.trigger = null;
    }
    if (item.el.getBoundingClientRect().bottom < 0) {
      settle(item);
      return;
    }
    item.state = "playing";
    var o = item.options;
    item.count.adapter.play(item.count.handle, {
      duration: o.tweenDuration,
      delay: o.delay,
      easing: counterEasing(item),
      ease: core.counterProgress(o),
      onEnd: function () {
        if (item.state === "playing") {
          item.state = "done";
        }
      },
    });
  }

  // Mounts a pending item and watches its start.
  function counterPrepare(item) {
    try {
      if (!counterMount(item)) {
        settle(item);
        return;
      }
      item.trigger = ScrollTrigger.create({
        trigger: item.el,
        start: item.options.start,
        once: true,
        markers: DEBUG,
        onEnter: function () {
          attempt(item, counterPlay);
        },
      });
    } catch (error) {
      report("could not prepare " + describe(item.el) + ": shown as written.", error);
      settle(item);
    }
  }

  // The adapter arrived: the items that waited for it are mounted, unless they moved on.
  function counterArrived() {
    clearTimeout(counterTimer);
    counterTimer = 0;
    document.removeEventListener(COUNTER_READY, counterArrived);
    var list = counterWaiting;
    counterWaiting = [];
    if (!counterAdapter()) {
      // The event came without a usable adapter: the waiting items are given up on now, as the
      // timeout would have done, instead of staying pending forever.
      counterWaiting = list;
      counterGiveUp();
      return;
    }
    list.forEach(function (item) {
      if (item.state === "pending" && !item.count && items.indexOf(item) > -1) {
        counterPrepare(item);
      }
    });
    if (list.length) {
      measure();
    }
  }

  // Too late: the waiting items stay as written, and so does every later one.
  function counterGiveUp() {
    counterTimer = 0;
    counterGaveUp = true;
    document.removeEventListener(COUNTER_READY, counterArrived);
    var list = counterWaiting;
    counterWaiting = [];
    list.forEach(function (item) {
      if (item.state === "pending") {
        logOnce(item.el, describe(item.el) + " left as written: the counter script did not load.");
        settle(item);
      }
    });
  }

  var counter = {
    start: function (list) {
      list.forEach(function (item) {
        if (counterAdapterFor(item)) {
          counterPrepare(item);
        } else if (counterGaveUp) {
          settle(item);
        } else {
          counterWaiting.push(item);
        }
      });
      if (counterWaiting.length && !counterTimer) {
        document.addEventListener(COUNTER_READY, counterArrived);
        counterTimer = setTimeout(counterGiveUp, COUNTER_WAIT_MS);
      }
    },

    play: function (list) {
      list.forEach(function (item) {
        attempt(item, counterPlay);
      });
    },

    // Builder: rolls again from the start value.
    preview: function (item) {
      if (!counterAdapterFor(item) || !counterMount(item)) {
        return;
      }
      item.state = "pending";
      counterPlay(item);
    },

    final: counterReset,
    kill: counterReset,
  };

  /* Builders by family. Each one has:
   * - start(items): animate the new pending items (they may be settled instead), each
   *   watched by a one-shot item.trigger, or a scrub (options.scrub, state "scrub", its
   *   ScrollTrigger in item.trigger);
   * - play(items): play pending one-shot items now, whatever their trigger (see below);
   *   optional: without it, such items are shown as they end;
   * - preview(item): builder only, an item shown as it ends plays once, now, and ends final;
   * - final(item): show the element as it ends, without animation;
   * - kill(item): undo everything (the element is removed, rebuilt or the engine destroyed). */
  var FAMILIES = {
    enter: enter,
    text: text,
    counter: counter,
  };

  /* ================================================================
   * STARTS THE PAGE CANNOT REACH
   * A one-shot item whose start lies at or past the end of the page (near the bottom of a
   * short page, a footer) would never fire: the page cannot scroll there. Such items play
   * when the visitor reaches the end of the page, or at once when they are already there
   * or the page does not scroll. Scrubs follow the scroll and are never late.
   * ================================================================ */

  // The maximum scroll right now; ScrollTrigger's own value can be older (see onScrollEnd).
  function liveMax() {
    var scroller = document.scrollingElement || root;
    return Math.max(0, scroller.scrollHeight - window.innerHeight);
  }

  function unreachable(max) {
    return items.filter(function (item) {
      return item.state === "pending" && item.trigger && !item.options.scrub && item.trigger.start >= max;
    });
  }

  function playLate(max) {
    if (!running || !animate || window.scrollY < max - END_MARGIN) {
      return;
    }
    var byFamily = {};
    unreachable(max).forEach(function (item) {
      (byFamily[item.family] = byFamily[item.family] || []).push(item);
    });
    Object.keys(byFamily).forEach(function (family) {
      // A family that cannot play on demand shows its items as they end.
      if (typeof FAMILIES[family].play !== "function") {
        byFamily[family].forEach(function (item) {
          if (item.state !== "final") {
            settle(item);
          }
        });
        return;
      }
      try {
        FAMILIES[family].play(byFamily[family]);
      } catch (error) {
        report("animation failed: elements shown as they end.", error);
        byFamily[family].forEach(function (item) {
          if (item.state !== "final") {
            settle(item);
          }
        });
      }
    });
  }

  /* A scrub whose end lies past the end of the page would stop part-way, its last words
   * dim for good (the reference end, bottom 45%, is out of reach for a text in the last half
   * screen of a page). Its end is moved to the end of the page; its start, when it lies
   * past that too or within the last half screen, to half a screen before, so the text
   * still lights up as the visitor scrolls down. setPositions() lasts until the next
   * refresh, which comes back here. */
  function fitScrubs(max) {
    // ScrollTrigger reads an end of 0 as "no end" (its default) and rounds tiny values: a page
    // that does not scroll gets its scrubs before 0, where they are over.
    var end = max - END_MARGIN > 0 ? max - END_MARGIN : -1;
    items.forEach(function (item) {
      var t = item.trigger;
      if (item.state !== "scrub" || !t || t.end <= end + 0.5) {
        return;
      }
      var start = Math.min(t.start, Math.max(0, end - window.innerHeight / 2), end - 1);
      try {
        t.setPositions(start, end);
      } catch (error) {
        report("could not fit the scrub of " + describe(item.el) + " to the page.", error);
      }
    });
  }

  // After every refresh, once the page has loaded: before that, an image without
  // dimensions could still push these items down (the load refresh comes back here).
  function onTriggersRefreshed() {
    fitScrubs(ScrollTrigger.maxScroll(window));
    if (document.readyState === "complete") {
      playLate(ScrollTrigger.maxScroll(window));
    }
  }

  // The visitor reached the end of the page as measured by the last refresh.
  function onPageEnd() {
    playLate(ScrollTrigger.maxScroll(window));
  }

  // Scrolling stopped. A phone that hides its address bar makes the screen taller and the
  // page shorter without any refresh (ignoreMobileResize): measure the end again.
  function onScrollEnd() {
    if (items.length) {
      var max = liveMax();
      fitScrubs(max);
      playLate(max);
    }
  }

  // One trigger and two listeners for the whole page, created with the first animated item.
  function watchPageEnd() {
    if (endWatch) {
      return;
    }
    endWatch = ScrollTrigger.create({
      start: function () {
        return Math.max(0, ScrollTrigger.maxScroll(window) - END_MARGIN);
      },
      end: "max",
      onEnter: onPageEnd,
    });
    ScrollTrigger.addEventListener("refresh", onTriggersRefreshed);
    ScrollTrigger.addEventListener("scrollEnd", onScrollEnd);
  }

  function unwatchPageEnd() {
    if (!endWatch) {
      return;
    }
    endWatch.kill();
    endWatch = null;
    ScrollTrigger.removeEventListener("refresh", onTriggersRefreshed);
    ScrollTrigger.removeEventListener("scrollEnd", onScrollEnd);
  }

  /* ================================================================
   * BUILD AND REFRESH
   * ================================================================ */

  /**
   * Builds the items of the marked elements of a scope.
   *
   * @param {Element|Document} scope   Where to look.
   * @param {boolean}          recheck Known items are read again and rebuilt when their effect or signature changed.
   * @param {boolean}          quiet   What is on screen is shown at once instead of making its entrance
   *                                   (a refresh, a switch of reduced motion, a first build on a page already
   *                                   seen; not a normal first build, not new content).
   * @return {boolean} Whether an item was created or removed.
   */
  function sync(scope, recheck, quiet) {
    var changed = false;

    // Items whose element left the page (AJAX pagination replaces its results, for one).
    items.slice().forEach(function (item) {
      if (!item.el.isConnected) {
        kill(item);
        changed = true;
      }
    });

    // Read every element first, then write: no style recalculation between two reads.
    var entries = [];
    candidates(scope).forEach(function (el) {
      var known = lookup(el);
      if (known && !recheck) {
        return;
      }
      try {
        entries.push(read(el));
      } catch (error) {
        report("could not read " + describe(el) + ".", error);
      }
    });

    var fresh = {};
    entries.forEach(function (entry) {
      var el = entry.el;
      var known = lookup(el);
      // Builder: what this element looked like at the last read ("" = no effect, undefined = never seen).
      var key = BUILDER ? builderKey(el) : "";
      var before = key ? lastSeen[key] : undefined;
      try {
        var options = entry.effect === "" || entry.effect === "none" ? null : core.resolve(entry.effect, entry.raw, config);
        var reason = skipReason(entry.effect, options, el);

        if (reason !== null) {
          if (key) {
            lastSeen[key] = "";
          }
          if (known) {
            kill(known);
            changed = true;
          }
          if (reason) {
            logOnce(el, describe(el) + " left alone: " + reason + ".");
          }
          return;
        }

        var signature = core.signature(options);
        if (key) {
          lastSeen[key] = signature;
        }
        if (known && known.effect === entry.effect && known.signature === signature) {
          return;
        }

        // What has already shown itself is not hidden again. A scrub is never shown once
        // and for all: it follows the scroll, rebuilt as a scrub.
        var seen = !options.scrub && quiet && entry.onScreen;
        // A known element that is pending or playing carries the engine's state: its end was read
        // before that. Done or final, it shows the author's values again, which may have changed
        // (an opacity edited in the builder panel).
        var end = known && (known.state === "pending" || known.state === "playing") ? known.end : entry.end;
        if (known) {
          seen = seen || (!options.scrub && known.state !== "pending");
          kill(known);
        }

        if (options.warnings.length) {
          logOnce(el, describe(el) + ": " + options.warnings.join(" "));
        }

        var item = create(entry, options, end);
        changed = true;
        if (!animate || entry.above || seen) {
          settle(item);
          // Builder: the element changed since the last read, or just got its effect.
          if (replayOn && !entry.above && before !== undefined && before !== signature) {
            replay(item);
          }
          return;
        }
        (fresh[item.family] = fresh[item.family] || []).push(item);
      } catch (error) {
        report("could not build " + describe(el) + ": shown as is.", error);
        var broken = lookup(el);
        if (broken) {
          settle(broken);
        }
      }
    });

    Object.keys(fresh).forEach(function (family) {
      try {
        watchPageEnd();
        FAMILIES[family].start(fresh[family]);
      } catch (error) {
        report("could not start the " + family + " effects: shown as they end.", error);
        fresh[family].forEach(function (item) {
          if (item.state === "pending") {
            settle(item);
          }
        });
      }
    });

    if (items.length) {
      listen();
    }
    return changed;
  }

  function clearMarkers() {
    markerTriggers.forEach(function (trigger) {
      try {
        trigger.kill();
      } catch (error) {
        report("could not remove a marker.", error);
      }
    });
    markerTriggers = [];
  }

  // Builder: one marker-only trigger per item, with GSAP's own markers, the ones ?lmDebug shows on
  // the public site: the start and the end on the element, the scroller-start and scroller-end
  // lines on the viewport. A scrub gets its end; a one-shot effect (entrance, text, counter) is
  // created with no end, as the engine creates it, so the end shown is ScrollTrigger's default
  // ("bottom top"), where its trigger kills itself. Drawn again whenever the items change; they
  // follow nothing and play nothing.
  function drawMarkers() {
    clearMarkers();
    if (!BUILDER || !markersOn || !running) {
      return;
    }
    items.forEach(function (item) {
      var vars = {
        trigger: item.el,
        start: item.options.start,
        markers: { startColor: "green", endColor: "red", fontSize: "12px", indent: 0 },
      };
      if (item.options.scrub) {
        vars.end = item.options.end;
      }
      try {
        markerTriggers.push(ScrollTrigger.create(vars));
      } catch (error) {
        report("could not draw the markers of " + describe(item.el) + ".", error);
      }
    });
    // No ScrollTrigger.refresh(): each trigger measures itself when it is created, and a full
    // refresh adds a measuring node to the body, which the builder script would take for a change.
  }

  function teardown() {
    items.slice().forEach(kill);
    items = [];
    unwatchPageEnd();
    clearMarkers();
  }

  // Runs outside the matchMedia context: the engine tracks its own tweens and triggers,
  // and a later scan() could not be recorded there anyway.
  function build(context, scope, recheck, quiet) {
    var changed = false;
    var run = function () {
      changed = sync(scope, recheck, quiet);
      // Builder: drawn again on every read, not only when an item changed. A layout edit (a
      // margin, a padding, an element added above) moves the elements without changing any
      // signature, and the markers stayed where the elements used to be.
      if (markersOn && (changed || BUILDER)) {
        drawMarkers();
      }
    };
    if (context && context.ignore) {
      context.ignore(run);
    } else {
      run();
    }
    return changed;
  }

  var current = null; // context of the active matchMedia branch

  // Nothing follows the scroll in the builder: no trigger to measure again.
  function measure() {
    if (!BUILDER) {
      ScrollTrigger.refresh();
    }
  }

  // In the builder a scan reads the known elements again too: that is how a change made in
  // the panel is noticed (the stylesheet changed, not the page).
  function scan(scope) {
    if (!running) {
      return;
    }
    stats.scans++;
    if (build(current, scope || document, BUILDER, false)) {
      measure();
    }
  }

  function refresh() {
    if (!running) {
      return;
    }
    if (build(current, document, true, true)) {
      measure();
    }
  }

  /* ================================================================
   * EVENTS
   * ================================================================ */

  function onResize() {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function () {
      // A phone hiding its address bar changes the height only: no breakpoint changed.
      if (window.innerWidth === lastWidth) {
        return;
      }
      lastWidth = window.innerWidth;
      refresh();
    }, RESIZE_MS);
  }

  // New content changes the height of the page, hence every start below it: ScrollTrigger
  // 3.15 does not notice a height change by itself (it refreshes on load and resize only).
  // The build runs at once: Bricks inserts the results and sends the event in the same task, and the
  // start states must be set before the next paint (no pre-hide after lm-ready: a later build let the
  // new entrances paint in full, then vanish and come back). Only the refresh of every trigger, the
  // costly part, waits for the events of one change to be over.
  function onContent() {
    if (!running) {
      return;
    }
    build(current, document, BUILDER, false);
    clearTimeout(contentTimer);
    contentTimer = setTimeout(function () {
      if (running) {
        measure();
      }
    }, CONTENT_MS);
  }

  function onPopupOpen() {
    if (running) {
      measure();
    }
  }

  // Bricks events are cheap to listen to; the resize handler only once there is something to rebuild.
  function listen() {
    if (listening) {
      return;
    }
    listening = true;
    window.addEventListener("resize", onResize);
  }

  function unlisten() {
    window.removeEventListener("resize", onResize);
    clearTimeout(resizeTimer);
    clearTimeout(contentTimer);
    listening = false;
    BRICKS_CONTENT_EVENTS.forEach(function (name) {
      document.removeEventListener(name, onContent);
    });
    document.removeEventListener(BRICKS_POPUP_OPEN, onPopupOpen);
  }

  /* ================================================================
   * START
   * ================================================================ */

  function ready() {
    root.classList.add("lm-ready");
  }

  function start() {
    if (!media) {
      return;
    }
    ScrollTrigger.config({ ignoreMobileResize: true });

    // The page has been seen already when the safety showed it (a slow phone: the engine starts
    // after 3 s) or when nothing pre-hid it (the head script ran after the load, as a "delay JS
    // until interaction" optimiser does): what is on screen stays as it is, what is below still
    // makes its entrance.
    var seenAlready = root.classList.contains("lm-ready") || !root.classList.contains("lm-js");

    media.add(
      {
        motion: "(prefers-reduced-motion: no-preference)",
        reduce: "(prefers-reduced-motion: reduce)",
      },
      function (context) {
        // Reduced motion, or the builder canvas: every element in its final state, nothing hidden.
        animate = !!context.conditions.motion && !config.builder;
        // The canvas honours reduced motion too: no replay, every change shows its final state.
        motionOk = !!context.conditions.motion;
        replayOn = PREVIEW_ON && motionOk;
        current = context;
        running = true;
        var built = false;
        // The pre-hide goes before the build, in the same task (nothing is painted in between):
        // read() must see the author's opacity and filter, which an entrance ends at. The build
        // writes the start states right after.
        ready();
        try {
          // The first build makes the entrances of what is on screen, unless the page was seen
          // before the engine started; a later one (reduced motion switched during the visit)
          // shows it as it is.
          built = build(context, document, false, branches++ > 0 || seenAlready);
        } catch (error) {
          report("start failed: elements shown as they end.", error);
        }
        if (built) {
          measure();
        }
        return function () {
          running = false;
          current = null;
          teardown();
        };
      }
    );

    BRICKS_CONTENT_EVENTS.forEach(function (name) {
      document.addEventListener(name, onContent);
    });
    document.addEventListener(BRICKS_POPUP_OPEN, onPopupOpen);
  }

  function destroy() {
    if (media) {
      media.revert();
      media = null;
    }
    running = false;
    teardown();
    unlisten();
    var hooks = destroyHooks;
    destroyHooks = [];
    hooks.forEach(function (hook) {
      try {
        hook();
      } catch (error) {
        report("a destroy hook failed.", error);
      }
    });
  }

  window.lumiaMotion = {
    refresh: refresh,
    scan: scan,
    destroy: destroy,
    // Whether a node sits in an element that is playing now: its own changes (split text, the
    // digits of a counter) are not changes of the page. Used by motion-builder.js.
    playing: function (node) {
      return items.some(function (item) {
        return item.state === "playing" && item.el.contains(node);
      });
    },
    // Runs fn once, when the engine is destroyed (the builder script stops its observers).
    onDestroy: function (fn) {
      if (typeof fn === "function") {
        destroyHooks.push(fn);
      }
    },
    // Builder (the Replay button of the panel): plays the element of this Bricks id once, every
    // item of a query loop included. False when it is not an animated item of the canvas, or
    // under reduced motion (the element stays as it ends).
    replay: function (id) {
      if (!BUILDER || !running || !motionOk || typeof id !== "string" || id === "") {
        return false;
      }
      var found = items.filter(function (item) {
        return isBricksId(item.el, id);
      });
      var played = 0;
      found.forEach(function (item) {
        // From its final state, whatever it was doing: a replay still playing is cut short.
        settle(item);
        if (replay(item)) {
          played++;
        }
      });
      return played > 0;
    },
    // Builder (the "Show markers" checkbox): draws or removes the ScrollTrigger markers.
    markers: function (on) {
      if (!BUILDER) {
        return;
      }
      markersOn = !!on;
      if (markersOn) {
        drawMarkers();
      } else {
        clearMarkers();
      }
    },
    // Builder (placeholders of the panel): the values the element of this Bricks id gets when its
    // fields are empty, or null when it has no effect. Time values are given before the tempo
    // factor, as a field holds them (the tempo scales what is typed there too).
    inspect: function (id) {
      if (!BUILDER || typeof id !== "string" || id === "") {
        return null;
      }
      var el = elementOf(id);
      if (!el) {
        return null;
      }
      var entry = read(el);
      if (entry.effect === "" || entry.effect === "none" || !has(SLUGS, entry.effect)) {
        return null;
      }
      var o = core.resolve(entry.effect, entry.raw, config);
      var factor = o.scrub ? null : core.tempoFactor(o.tempo);
      var unscale = function (value) {
        return factor ? value / factor : value;
      };
      return {
        effect: o.effect,
        duration: unscale(o.duration),
        delay: o.delay,
        stagger: unscale(o.stagger),
        distance: o.distance,
        blur: o.blur,
        scale: o.scale,
        fromOpacity: o.fromOpacity,
        startValue: o.startValue,
        tempo: o.tempo,
        easeKey: o.easeKey,
      };
    },
    _debug: {
      items: function () {
        return items.map(function (item) {
          return {
            id: item.id,
            effect: item.effect,
            signature: item.signature,
            options: item.options,
            state: item.state,
          };
        });
      },
      stats: function () {
        return { scans: stats.scans, replays: stats.replays };
      },
    },
  };

  if (!core || !gsap || !ScrollTrigger) {
    if (window.console && console.warn) {
      console.warn("Lumia Motion did not start: GSAP, ScrollTrigger or the Motion core is missing.");
    }
    ready();
    return;
  }

  gsap.registerPlugin(ScrollTrigger);
  if (SplitText) {
    gsap.registerPlugin(SplitText);
  }
  media = gsap.matchMedia();

  function boot() {
    var fonts = document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve();
    // A web font changes line heights, hence positions: wait for it, but not for long.
    var cap = new Promise(function (resolve) {
      setTimeout(resolve, FONT_WAIT_MS);
    });
    Promise.race([fonts, cap]).then(start, start);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot, { once: true });
  } else {
    boot();
  }
})();
