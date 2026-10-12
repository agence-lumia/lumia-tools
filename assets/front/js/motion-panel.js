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
 *   placeholder of Controls.php stays when the canvas cannot tell;
 * - a Motion shortcut in the left rail of the element panel (#bricks-panel-element-quick-access).
 *   Bricks 2.4.2 builds that rail from a fixed list of its own groups (the `shortcuts` computed
 *   of a Vue component, no plugin API): the item is a plain <li> appended after the last Bricks
 *   one, with the same markup, and a click sets the same state as Bricks' setActivePanelGroup().
 *   Bricks internals: when they change, the item is simply absent (no error).
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

  var RAIL_ID = "bricks-panel-element-quick-access";
  var RAIL_GROUP = "lm_motion"; // the control group of Controls::add_group()
  var RAIL_ITEM = "lm-rail-motion";

  var timer = 0;
  var firstRequest = 0;
  var watching = false;
  var railWatching = false;
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

  // ------------------------------------------------------------------ rail shortcut

  // The Motion group of the selected element (its config holds the translated title), or null.
  function railGroup(props) {
    var element = props.$_state.activeElement;
    if (!element || typeof props.$_getElementConfig !== "function") {
      return null;
    }
    var config = props.$_getElementConfig(element.name);
    var groups = config && config.controlGroups;
    return groups && groups[RAIL_GROUP] ? groups[RAIL_GROUP] : null;
  }

  // Cheap version of Bricks' controlGroupHasSettings(): a real setting (any breakpoint) on the
  // class being edited, else on the element. Only the css-backed controls of the Motion group
  // count (what Bricks counts too): not the help (_lmHelp), not the separators (*Sep), and not
  // an unknown key, such as the {isTrusted: true} an old page may hold in _lmHelp. When the
  // element config is out of reach, the same is approximated by name: not _lmHelp, not *Sep.
  function railHasSettings(props) {
    var state = props.$_state;
    var source = state.activeClass || state.activeElement;
    var settings = source && source.settings;
    if (!settings || typeof settings !== "object" || Array.isArray(settings)) {
      return false;
    }
    var controls = null;
    if (state.activeElement && typeof props.$_getElementConfig === "function") {
      var config = props.$_getElementConfig(state.activeElement.name);
      controls = config && config.controls && typeof config.controls === "object" ? config.controls : null;
    }
    return Object.keys(settings).some(function (key) {
      if (key.indexOf("_lm") !== 0) {
        return false;
      }
      var base = key.split(":")[0]; // "_lmDelay:mobile_portrait" => "_lmDelay"
      if (base === "_lmHelp" || /Sep$/.test(base)) {
        return false;
      }
      return controls ? !!(controls[base] && controls[base].css) : true;
    });
  }

  // Bricks' own rail items (everything but ours). Bricks builds them all behind the same
  // permission ($_userHasPermission("access_element_content"), `shortcuts` computed, 2.4.2) and
  // never shows the Style groups without it, so our item mirrors them rather than re-checking a
  // permission: no Bricks item, no Motion item. Absent items fail closed, which is also what
  // happens when Bricks changes its markup (the panel group stays reachable from the Style tab).
  function railHasBricksItems(list) {
    return !!list.querySelector("li:not(." + RAIL_ITEM + ")");
  }

  function railActive(state) {
    return state.activePanelTab === "style" && state.activePanelGroup === RAIL_GROUP;
  }

  // What Bricks' setActivePanelGroup(group) does for a group of the Style tab (main.min.js 2.4.2).
  // Its toggle-off branch compares the group with the tab name, so it never runs for a style group.
  function openRailGroup() {
    var props = builderProps();
    if (!props) {
      return;
    }
    var state = props.$_state;
    try {
      if (typeof props.$_clearSearchControls === "function") {
        props.$_clearSearchControls();
      }
      state.activePanelGroup = RAIL_GROUP;
      state.activePanelTab = "style";
      state.showHideElement = false;
      state.showConditions = false;
      state.showInteractions = false;
      state.elementQuickAccessIndex = -1;
    } catch (error) {
      // Bricks changed: the rail item does nothing rather than break the builder.
    }
  }

  // The markup of a Bricks item: <li data-balloon data-balloon-pos> with a .bricks-svg-wrapper.
  // No .index: the digit shortcuts address Bricks' own list by position. The icon is the Lucide
  // sparkles of the group title, a CSS mask (motion-builder.css).
  function createRailItem(list) {
    var item = document.createElement("li");
    item.className = RAIL_ITEM;
    var model = list.querySelector("li[data-balloon-pos]");
    item.setAttribute("data-balloon-pos", model ? model.getAttribute("data-balloon-pos") : "right");
    var wrapper = document.createElement("span");
    wrapper.className = "bricks-svg-wrapper";
    wrapper.setAttribute("data-name", "lm-sparkles");
    var icon = document.createElement("span");
    icon.className = "lm-rail-icon";
    wrapper.appendChild(icon);
    item.appendChild(wrapper);
    item.addEventListener("click", function (event) {
      event.stopPropagation(); // as Bricks' @click.stop
      openRailGroup();
    });
    return item;
  }

  function toggleClass(el, name, on) {
    if (el.classList.contains(name) !== on) {
      el.classList.toggle(name, on);
    }
  }

  function updateRailItem(item, props, group) {
    var state = props.$_state;
    var title = typeof group.title === "string" && group.title !== "" ? group.title : "Motion";
    if (item.getAttribute("data-balloon") !== title) {
      item.setAttribute("data-balloon", title);
    }
    toggleClass(item, "active", railActive(state));
    toggleClass(item, "has-settings", railHasSettings(props));
  }

  // Classes follow the state: tab, group, selection, class and their settings.
  function watchRail(props) {
    if (railWatching || typeof props.$_watch !== "function") {
      return;
    }
    railWatching = true;
    var state = props.$_state;
    props.$_watch(
      function () {
        var element = state.activeElement;
        return [state.activePanelTab, state.activePanelGroup, element && element.id, railHasSettings(props)].join("|");
      },
      function () {
        syncRail();
      }
    );
  }

  // Runs on every mutation of the builder: Vue renders the list again (a new <ul> when its
  // renderKey changes, on every selection) and drops the foreign item. Only childList mutations
  // are observed, so setting classes here never wakes the observer; the insertion does, once,
  // and the second pass finds the item in place.
  function syncRail() {
    try {
      var rail = document.getElementById(RAIL_ID);
      var list = rail && rail.querySelector("ul");
      if (!list) {
        return;
      }
      var props = builderProps();
      var item = list.querySelector("li." + RAIL_ITEM);
      var group = props ? railGroup(props) : null;
      if (!group || !railHasBricksItems(list)) {
        if (item) {
          item.remove();
        }
        return;
      }
      if (!item) {
        item = createRailItem(list);
      }
      if (list.lastElementChild !== item) {
        list.appendChild(item);
      }
      updateRailItem(item, props, group);
      watchRail(props);
    } catch (error) {
      // Bricks changed: no item, no error.
    }
  }

  // ------------------------------------------------------------------ panel

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
        // The checkbox sits in an info control: left to bubble, its event reached the Bricks
        // control, which stored the Event object as the element's _lmHelp setting (saved in the
        // page, and shown as a Motion setting).
        event.stopPropagation();
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

  // Same for the input event of the checkbox.
  document.addEventListener(
    "input",
    function (event) {
      var target = event.target;
      if (target && target.classList && target.classList.contains("lm-panel-markers")) {
        event.stopPropagation();
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
      // The checkbox and the rail item at once, before the panel is painted; the placeholders
      // once it settles.
      syncMarkers();
      syncRail();
      later(SYNC_MS);
    }).observe(document.body, { childList: true, subtree: true });
  }

  if (document.body) {
    observe();
  } else {
    document.addEventListener("DOMContentLoaded", observe, { once: true });
  }
})();
