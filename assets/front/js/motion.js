/**
 * Lümia Tools - Motion engine
 * Entry point of the engine. It reads window.lumiaMotionConfig, printed by the plugin
 * before this file, and window.lumiaMotionCore, the pure helpers.
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

  window.lumiaMotion = {};
  root.classList.add("lm-ready");
})();
