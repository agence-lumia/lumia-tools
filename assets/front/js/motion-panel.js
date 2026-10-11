/**
 * Lumia Tools - Motion, Bricks builder main window
 * Loaded in the main window of the builder only (the panels), never in the canvas nor on the
 * public site. Three things for the Motion group of the Style tab:
 *
 * - the Replay button (.lm-panel-replay, in the help of the group) plays the selected element
 *   once in the canvas: lumiaMotion.replay(id);
 * - the "Show markers" checkbox (.lm-panel-markers) draws the ScrollTrigger markers in the
 *   canvas: lumiaMotion.markers(on). A preference of this browser (localStorage, read by the
 *   canvas engine when it starts), never saved in the page;
 * - the empty number fields show, as their placeholder, the value the selected element really
 *   gets (its class, else the site value of its effect): lumiaMotion.inspect(id). The static
 *   placeholder of Controls.php stays when the canvas cannot tell.
 *
 * The selected element comes from the state of the builder: Bricks 2.4.2 mounts a Vue 3 app
 * and exposes its reactive state as the global property $_state ($_state.activeElement.id).
 * The canvas is the iframe #bricks-builder-iframe (same origin).
 */
(function () {
  "use strict";

  var MARKERS_KEY = "lmMotionMarkers";
  var SYNC_MS = 150; // panel renders closer than this make one update
  var MAX_WAIT_MS = 600; // an update is never put off longer than this by a stream of renders
  var CHANGE_MS = 300; // a change of a field reaches the canvas CSS, then the engine reads it
  // Number field => value of lumiaMotion.inspect().
  var FIELDS = {
    _lmDuration: "duration",
    _lmDelay: "delay",
    _lmStagger: "stagger",
    _lmDistance: "distance",
    _lmBlur: "blur",
    _lmScale: "scale",
    _lmFromOpacity: "fromOpacity",
    _lmStartValue: "startValue",
  };

  var timer = 0;
  var firstRequest = 0;
  var watching = false;
  var markersOn = readPref();

  function readPref() {
    try {
      return window.localStorage.getItem(MARKERS_KEY) === "1";
    } catch (error) {
      return false;
    }
  }

  function writePref(on) {
    try {
      if (on) {
        window.localStorage.setItem(MARKERS_KEY, "1");
      } else {
        window.localStorage.removeItem(MARKERS_KEY);
      }
    } catch (error) {
      // Storage blocked: the markers still follow the checkbox until the builder is reloaded.
    }
  }

  // Global properties of the builder app ($_state, $_watch), or null before it is mounted.
  function builderProps() {
    var root = document.querySelector("[data-v-app]");
    var app = root && root.__vue_app__;
    var props = app && app.config && app.config.globalProperties;
    return props && props.$_state ? props : null;
  }

  function builderState() {
    var props = builderProps();
    return props ? props.$_state : null;
  }

  // Selecting another element, class or breakpoint may patch the same inputs in place (no node
  // added): watch the state itself too, once it exists.
  function watchState() {
    var props = builderProps();
    if (watching || !props || typeof props.$_watch !== "function") {
      return;
    }
    watching = true;
    var state = props.$_state;
    props.$_watch(
      function () {
        var element = state.activeElement;
        var cls = state.activeClass;
        return [element && element.id, cls && cls.id, state.breakpointActive].join("|");
      },
      function () {
        later(SYNC_MS);
      }
    );
  }

  function activeId() {
    var state = builderState();
    var element = state && state.activeElement;
    return element && typeof element.id === "string" ? element.id : "";
  }

  // The engine of the canvas, or null while the canvas loads.
  function engine() {
    var frame = document.getElementById("bricks-builder-iframe");
    var win = null;
    try {
      win = frame && frame.contentWindow;
    } catch (error) {
      return null;
    }
    var motion = win && win.lumiaMotion;
    return motion && typeof motion.replay === "function" ? motion : null;
  }

  // 0.15000000000000002 -> "0.15".
  function format(value) {
    return typeof value === "number" && isFinite(value) ? String(Math.round(value * 1000) / 1000) : "";
  }

  // Runs on every render of the builder: no storage read here. A checkbox is set once, when it
  // first shows up (all of them when force): a render during a click must not undo the click.
  function syncMarkers(force) {
    var boxes = document.querySelectorAll(".lm-panel-markers");
    for (var i = 0; i < boxes.length; i++) {
      if (force || !boxes[i].hasAttribute("data-lm-synced")) {
        boxes[i].setAttribute("data-lm-synced", "");
        boxes[i].checked = markersOn;
      }
    }
  }

  function syncPlaceholders() {
    var id = activeId();
    var motion = engine();
    var values = null;
    if (id && motion && typeof motion.inspect === "function") {
      try {
        values = motion.inspect(id);
      } catch (error) {
        values = null;
      }
    }
    Object.keys(FIELDS).forEach(function (key) {
      var input = document.querySelector('[data-controlkey="' + key + '"] input');
      if (!input) {
        return;
      }
      // The static placeholder of Controls.php, kept to come back to.
      if (!input.hasAttribute("data-lm-placeholder")) {
        input.setAttribute("data-lm-placeholder", input.getAttribute("placeholder") || "");
      }
      var live = values ? format(values[FIELDS[key]]) : "";
      input.setAttribute("placeholder", live !== "" ? live : input.getAttribute("data-lm-placeholder"));
    });
  }

  function sync() {
    timer = 0;
    firstRequest = 0;
    watchState();
    if (!document.querySelector('[data-control-group="lm_motion"]')) {
      return;
    }
    syncMarkers();
    syncPlaceholders();
  }

  // Waits for a pause of ms, but not for more than MAX_WAIT_MS in all.
  function later(ms) {
    var now = Date.now();
    if (!firstRequest) {
      firstRequest = now;
    }
    clearTimeout(timer);
    timer = setTimeout(sync, Math.max(0, Math.min(ms, firstRequest + MAX_WAIT_MS - now)));
  }

  document.addEventListener(
    "click",
    function (event) {
      var button = event.target && event.target.closest ? event.target.closest(".lm-panel-replay") : null;
      if (!button) {
        return;
      }
      event.preventDefault();
      var motion = engine();
      var id = activeId();
      if (motion && id) {
        motion.replay(id);
      }
    },
    true
  );

  document.addEventListener(
    "change",
    function (event) {
      var target = event.target;
      if (target && target.classList && target.classList.contains("lm-panel-markers")) {
        markersOn = target.checked;
        writePref(markersOn);
        var motion = engine();
        if (motion && typeof motion.markers === "function") {
          motion.markers(target.checked);
        }
        syncMarkers(true);
        return;
      }
      // Another field of the panel: the inherited values may have changed (effect, class, tempo).
      if (target && target.closest && target.closest('[data-control-group="lm_motion"]')) {
        later(CHANGE_MS);
      }
    },
    true
  );

  // The panel renders its fields again on every selection, group, breakpoint or class change.
  function observe() {
    if (typeof MutationObserver !== "function" || !document.body) {
      return;
    }
    new MutationObserver(function () {
      // The checkbox at once, before the panel is painted; the placeholders once it settles.
      syncMarkers();
      later(SYNC_MS);
    }).observe(document.body, { childList: true, subtree: true });
  }

  if (document.body) {
    observe();
  } else {
    document.addEventListener("DOMContentLoaded", observe, { once: true });
  }
})();
