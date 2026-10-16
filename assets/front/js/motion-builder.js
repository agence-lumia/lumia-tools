/**
 * Lumia Tools - Motion, Bricks builder canvas
 * Loaded in the canvas only, after the engine (motion.js), which runs in its builder mode:
 * every element in its final state, nothing hidden, nothing following the scroll.
 *
 * This file tells the engine when to read the page again:
 * - Bricks calls window.lumiaMotionBuilder(el) for each element it renders, declared by the
 *   plugin on every element (about 180 calls when a page opens). The calls are grouped;
 * - the panel writes its changes as CSS in a <style> of the <head>, and Bricks renders
 *   elements again in the <body>: a MutationObserver on both asks for a read as well.
 *
 * The ScrollTrigger markers the panel can show are left out: drawing them is not a change.
 *
 * A read is lumiaMotion.scan(). It finds the elements by their computed --lm-effect (an
 * element added in the builder has no data-lm), and replays the ones whose settings changed
 * (the "Animation preview" setting of the module; "off" replays nothing).
 */
(function () {
  "use strict";

  var GROUP_MS = 120; // requests closer than this make one read
  var MAX_WAIT_MS = 1000; // a read is never put off longer than this by a stream of requests

  var timer = 0;
  var firstRequest = 0;
  var observer = null;
  var stopped = false;

  function engine() {
    return window.lumiaMotion && typeof window.lumiaMotion.scan === "function" ? window.lumiaMotion : null;
  }

  function run() {
    timer = 0;
    firstRequest = 0;
    var motion = engine();
    if (motion) {
      motion.scan(document);
    }
  }

  // Waits for a pause of GROUP_MS in the requests, but not for more than MAX_WAIT_MS in all.
  function request() {
    if (stopped) {
      return;
    }
    var now = Date.now();
    if (!firstRequest) {
      firstRequest = now;
    }
    clearTimeout(timer);
    timer = setTimeout(run, Math.max(0, Math.min(GROUP_MS, firstRequest + MAX_WAIT_MS - now)));
  }

  // The engine's own work on an element that is playing (text split in pieces, the digits of a
  // counter) changes the page; it is not a change of the settings.
  function ours(record) {
    var motion = engine();
    if (!motion || typeof motion.playing !== "function") {
      return false;
    }
    return motion.playing(record.target);
  }

  // The ScrollTrigger markers ("Show markers" in the panel) are added to and removed from the
  // body: drawing them is not a change of the page, and reading the page again would draw them
  // again.
  function isMarker(node) {
    var name = node && node.nodeType === 1 && typeof node.className === "string" ? node.className : "";
    return name.indexOf("gsap-marker") === 0;
  }

  function onlyMarkers(record) {
    if (record.type !== "childList") {
      return false;
    }
    var nodes = Array.prototype.slice.call(record.addedNodes).concat(Array.prototype.slice.call(record.removedNodes));
    return nodes.length > 0 && nodes.every(isMarker);
  }

  // Changes inside an element that was playing: the engine's own work, or an edit of a child made
  // during the play (Bricks renders it again). Which one cannot be told apart, so they are read
  // once the play is over instead of being dropped.
  var DEFER_MS = 250;
  var deferred = [];
  var deferTimer = 0;

  function recheckDeferred() {
    deferTimer = 0;
    var motion = engine();
    deferred = deferred.filter(function (node) {
      return node.isConnected && motion && typeof motion.playing === "function" && motion.playing(node);
    });
    if (deferred.length) {
      deferTimer = setTimeout(recheckDeferred, DEFER_MS);
      return;
    }
    request();
  }

  function defer(node) {
    if (deferred.indexOf(node) === -1) {
      deferred.push(node);
    }
    if (!deferTimer) {
      deferTimer = setTimeout(recheckDeferred, DEFER_MS);
    }
  }

  function onMutations(records) {
    var changed = false;
    for (var i = 0; i < records.length; i++) {
      if (onlyMarkers(records[i])) {
        continue;
      }
      if (ours(records[i])) {
        defer(records[i].target);
        continue;
      }
      changed = true;
    }
    if (changed) {
      request();
    }
  }

  function observe() {
    if (observer || stopped || typeof MutationObserver !== "function") {
      return;
    }
    observer = new MutationObserver(onMutations);
    observer.observe(document.head, { childList: true, characterData: true, subtree: true });
    observer.observe(document.body, { childList: true, subtree: true });
  }

  // Called by Bricks with the element it has just rendered or rendered again.
  window.lumiaMotionBuilder = function () {
    observe();
    request();
  };

  // The engine is destroyed: nothing left to read the page for.
  function stop() {
    stopped = true;
    clearTimeout(timer);
    timer = 0;
    firstRequest = 0;
    clearTimeout(deferTimer);
    deferTimer = 0;
    deferred = [];
    if (observer) {
      observer.disconnect();
      observer = null;
    }
  }

  if (engine() && typeof window.lumiaMotion.onDestroy === "function") {
    window.lumiaMotion.onDestroy(stop);
  }

  // The panel can change a stylesheet before Bricks has rendered any element of the page.
  if (document.body) {
    observe();
  }
})();
