/**
 * Lümia Tools - Motion engine
 * Entry point of the engine. It reads window.lumiaMotionConfig, printed by the plugin
 * before this file, window.lumiaMotionCore (the pure helpers), and GSAP + ScrollTrigger.
 *
 * Every element marked data-lm becomes an item: its effect comes from the computed
 * --lm-effect (element > class, per breakpoint, by the CSS cascade), else from the first
 * effect class of its class attribute. Its settings are the 18 engine variables, read
 * once when the item is built and again on refresh(), never per frame.
 *
 * Each family of effects has a builder (FAMILIES below): the entrance effects are here,
 * the text and counter families plug into the same three hooks.
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
  var lastWidth = window.innerWidth;
  var listening = false;
  var branches = 0; // matchMedia branches run so far

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

  function candidates(scope) {
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
    return { el: el, effect: effect, raw: raw, above: rect.bottom < 0, onScreen: rect.top < window.innerHeight };
  }

  // Why an effect is not built, or "" when it is.
  function skipReason(effect, options) {
    if (effect === "" || effect === "none") {
      return effect === "none" ? "effect set to none" : "no effect";
    }
    if (!has(SLUGS, effect)) {
      return "unknown effect " + JSON.stringify(effect);
    }
    if (!has(FAMILIES, options.family)) {
      // The underline is drawn by the stylesheet; the text and counter builders come with their own files.
      return options.family === "underline" ? "" : options.family + " effects are not handled by this engine";
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

  function create(entry, options) {
    var item = {
      id: entry.el.id || "lm-" + ++serial,
      el: entry.el,
      effect: entry.effect,
      family: options.family,
      options: options,
      signature: core.signature(options),
      state: "pending",
      trigger: null,
      inline: saveInline(entry.el),
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
   * FAMILY: ENTER (fade, reveal, blur-in, scale-in, scale-blur-in)
   * Two tweens per lot: the transforms follow the curve, overshoot included; opacity and
   * blur follow its safe version, which never goes past 1 or below 0. Same duration,
   * delay and stagger, so both stay in step.
   * ================================================================ */

  function enterFrom(o) {
    var move = {};
    var look = { opacity: 0 };
    if (o.effect === "reveal" || o.effect === "blur-in") {
      move.y = o.distance;
    }
    if (o.effect === "scale-in" || o.effect === "scale-blur-in") {
      move.scale = o.scale;
    }
    if (o.effect === "blur-in" || o.effect === "scale-blur-in") {
      look.filter = "blur(" + o.blur + "px)";
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
      restoreInline(item);
      item.state = "done";
    }
  }

  function enterPlay(options, elements) {
    var from = enterFrom(options);
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
      gsap.to(targets, move);
    }

    var look = {
      opacity: 1,
      duration: options.tweenDuration,
      ease: options.safeEase,
      delay: options.delay,
      stagger: { each: options.stagger, onComplete: enterFinish },
    };
    if (from.look.filter) {
      look.filter = "blur(0px)";
    }
    gsap.to(targets, look);
  }

  var enter = {
    // Sets the start state of the new pending items and watches them, one batch per signature.
    start: function (list) {
      var groups = {};
      var order = [];
      list.forEach(function (item) {
        try {
          var from = enterFrom(item.options);
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

    final: function (item) {
      if (item.trigger) {
        item.trigger.kill();
        item.trigger = null;
      }
      gsap.killTweensOf(item.el);
      restoreInline(item);
    },

    kill: function (item) {
      enter.final(item);
    },
  };

  /* Builders by family. Each one has:
   * - start(items): animate the new pending items (they may be settled instead);
   * - final(item): show the element as it ends, without animation;
   * - kill(item): undo everything (the element is removed, rebuilt or the engine destroyed). */
  var FAMILIES = {
    enter: enter,
  };

  /* ================================================================
   * BUILD AND REFRESH
   * ================================================================ */

  /**
   * Builds the items of the marked elements of a scope.
   *
   * @param {Element|Document} scope   Where to look.
   * @param {boolean}          recheck Known items are read again and rebuilt when their effect or signature changed.
   * @param {boolean}          quiet   What is on screen is shown at once instead of making its entrance
   *                                   (a refresh, a switch of reduced motion; not the first build, not new content).
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
      try {
        var options = entry.effect === "" || entry.effect === "none" ? null : core.resolve(entry.effect, entry.raw, config);
        var reason = skipReason(entry.effect, options);

        if (reason !== null) {
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
        if (known && known.effect === entry.effect && known.signature === signature) {
          return;
        }

        // What has already shown itself is not hidden again.
        var seen = quiet && entry.onScreen;
        if (known) {
          seen = seen || known.state !== "pending";
          kill(known);
        }

        if (options.warnings.length) {
          logOnce(el, describe(el) + ": " + options.warnings.join(" "));
        }

        var item = create(entry, options);
        changed = true;
        if (!animate || entry.above || seen) {
          settle(item);
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

  function teardown() {
    items.slice().forEach(kill);
    items = [];
  }

  // Runs outside the matchMedia context: the engine tracks its own tweens and triggers,
  // and a later scan() could not be recorded there anyway.
  function build(context, scope, recheck, quiet) {
    var changed = false;
    var run = function () {
      changed = sync(scope, recheck, quiet);
    };
    if (context && context.ignore) {
      context.ignore(run);
    } else {
      run();
    }
    return changed;
  }

  var current = null; // context of the active matchMedia branch

  function scan(scope) {
    if (!running) {
      return;
    }
    if (build(current, scope || document, false, false)) {
      ScrollTrigger.refresh();
    }
  }

  function refresh() {
    if (!running) {
      return;
    }
    if (build(current, document, true, true)) {
      ScrollTrigger.refresh();
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

  function onContent() {
    scan(document);
  }

  function onPopupOpen() {
    if (running) {
      ScrollTrigger.refresh();
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

    media.add(
      {
        motion: "(prefers-reduced-motion: no-preference)",
        reduce: "(prefers-reduced-motion: reduce)",
      },
      function (context) {
        // Reduced motion, or the builder canvas: every element in its final state, nothing hidden.
        animate = !!context.conditions.motion && !config.builder;
        current = context;
        running = true;
        var built = false;
        try {
          // The first build makes the entrances of what is on screen; a later one (reduced
          // motion switched during the visit) shows it as it is.
          built = build(context, document, false, branches++ > 0);
        } catch (error) {
          report("start failed: elements shown as they end.", error);
        }
        ready();
        if (built) {
          ScrollTrigger.refresh();
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
  }

  window.lumiaMotion = {
    refresh: refresh,
    scan: scan,
    destroy: destroy,
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
