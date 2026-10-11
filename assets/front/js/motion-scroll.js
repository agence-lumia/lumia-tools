/**
 * Lümia Tools - Motion smooth scroll
 * Starts Lenis (window.Lenis, loaded before this file) on every public page when the
 * setting is on, and exposes the instance as window.lumiaLenis.
 *
 * - Clock: with GSAP on the page, the GSAP ticker drives Lenis and ScrollTrigger is told
 *   about every Lenis scroll, as the Lenis documentation prescribes. Without GSAP, Lenis
 *   runs its own requestAnimationFrame loop. The test is made here, at load, on
 *   window.gsap: the order in which the engine and this file run does not matter.
 * - Anchors: a click on a link to the same page scrolls with the height of the header
 *   (--navbar-height) above the target. Lenis owns same-page anchors, so the stylesheet
 *   rule that does the same without Lenis (scroll-margin-top) is switched off by the
 *   `lenis` class of <html>.
 * - Popups: while a Bricks popup is open the page behind it does not scroll, and the
 *   popup scrolls natively (data-lenis-prevent).
 * - Desktop only (syncTouch off): touch devices keep their native, inertial scroll.
 */
(function () {
  "use strict";

  var Lenis = window.Lenis;

  // Loaded twice (a caching plugin that merges files): the first instance wins.
  if (typeof Lenis !== "function" || window.lumiaLenis) {
    return;
  }

  var DEFAULT_LERP = 0.1;
  var BRICKS_POPUP_OPEN = "bricks/popup/open";
  var BRICKS_POPUP_CLOSE = "bricks/popup/close";

  var gsap = window.gsap;
  var ScrollTrigger = window.ScrollTrigger;

  /* ================================================================
   * SETTINGS
   * ================================================================ */

  // window.lumiaMotionScroll = { lerp } is printed by the plugin from the sanitized settings;
  // a missing or hand-edited value falls back to the Lenis default.
  function intensity() {
    var config = window.lumiaMotionScroll;
    var lerp = config && typeof config.lerp === "number" ? config.lerp : DEFAULT_LERP;
    return lerp >= 0.01 && lerp <= 1 ? lerp : DEFAULT_LERP;
  }

  /* ================================================================
   * HEADER HEIGHT
   * ================================================================ */

  // --navbar-height is whatever the site wrote: px, rem, a clamp() that changes with the
  // screen. A throwaway element whose height is the variable gives the computed pixels.
  function navbarHeight() {
    var body = document.body;
    if (!body) {
      return 0;
    }
    var probe = document.createElement("div");
    probe.setAttribute("aria-hidden", "true");
    probe.style.cssText =
      "position:absolute;top:0;left:0;width:1px;height:var(--navbar-height, 0px);visibility:hidden;pointer-events:none";
    body.appendChild(probe);
    var height = probe.getBoundingClientRect().height;
    body.removeChild(probe);
    return height > 0 ? height : 0;
  }

  // Lenis reads `offset` of this object each time an anchor is clicked (it hands the object
  // to scrollTo), so a getter measures the header at that moment: a breakpoint crossed
  // since the page loaded, or a header that grows or shrinks, is accounted for.
  var anchors = {
    get offset() {
      return -navbarHeight();
    },
  };

  /* ================================================================
   * INSTANCE
   * ================================================================ */

  var lenis = new Lenis({
    lerp: intensity(),
    autoRaf: !gsap,
    syncTouch: false,
    allowNestedScroll: true,
    stopInertiaOnNavigate: true,
    anchors: anchors,
  });
  window.lumiaLenis = lenis;

  if (gsap && gsap.ticker) {
    // GSAP gives seconds, Lenis wants milliseconds.
    gsap.ticker.add(function (time) {
      lenis.raf(time * 1000);
    });
    // Without it GSAP skips the frames it thinks were lost, and Lenis stutters after a slow one.
    gsap.ticker.lagSmoothing(0);
    if (ScrollTrigger && typeof ScrollTrigger.update === "function") {
      lenis.on("scroll", ScrollTrigger.update);
    }
  }

  /* ================================================================
   * LANDING ON AN ANCHOR
   * ================================================================ */

  // A link from another page lands on its target through the browser, which places it with
  // the scroll-margin-top the stylesheet switches off under Lenis: the header would cover
  // it. Once the page has loaded (the browser aims at the fragment until then), the target
  // is put where a click on the same page would have put it. Only for a visit that
  // navigates: a reload or the back button restore the position the visitor left, and a
  // visitor who has already scrolled by hand is not taken back.
  function landOnAnchor() {
    var hash = window.location.hash;
    if (hash.length < 2) {
      return;
    }
    var entries = window.performance && performance.getEntriesByType ? performance.getEntriesByType("navigation") : [];
    if (entries.length && entries[0].type !== "navigate") {
      return;
    }

    var target = null;
    try {
      target = document.getElementById(decodeURIComponent(hash.slice(1)));
    } catch (error) {
      return;
    }
    if (!target) {
      return;
    }

    var moved = false;
    var INPUTS = ["wheel", "touchstart", "mousedown", "keydown"];
    function onInput() {
      moved = true;
    }
    INPUTS.forEach(function (name) {
      window.addEventListener(name, onInput, { passive: true, once: true });
    });

    function land() {
      INPUTS.forEach(function (name) {
        window.removeEventListener(name, onInput);
      });
      if (!moved) {
        lenis.scrollTo(target, { immediate: true, force: true, offset: anchors.offset });
      }
    }

    // One frame after the load: the scroll event of the browser's own landing is dispatched
    // by then, and Lenis knows where the page is (it measures from there).
    function afterFrame() {
      window.requestAnimationFrame(land);
    }

    if (document.readyState === "complete") {
      afterFrame();
    } else {
      window.addEventListener("load", afterFrame, { once: true });
    }
  }

  landOnAnchor();

  /* ================================================================
   * BRICKS POPUPS
   * ================================================================ */

  // Popups holding the scroll. An event without a popup (a script that fires it by hand)
  // counts as one popup of its own.
  var ANONYMOUS = {};
  var holders = [];

  function popupOf(event) {
    return (event && event.detail && event.detail.popupElement) || null;
  }

  // A popup the visitor can scroll the page behind (the Bricks "body scroll" option) or a
  // map info box is not a lock.
  function locks(popup) {
    return !popup || !(popup.hasAttribute("data-popup-body-scroll") || popup.classList.contains("brx-infobox-popup"));
  }

  function onPopupOpen(event) {
    var popup = popupOf(event);
    if (popup) {
      popup.setAttribute("data-lenis-prevent", "");
    }
    if (!locks(popup)) {
      return;
    }
    var key = popup || ANONYMOUS;
    if (holders.indexOf(key) === -1) {
      holders.push(key);
    }
    lenis.stop();
  }

  function onPopupClose(event) {
    var index = holders.indexOf(popupOf(event) || ANONYMOUS);
    if (index !== -1) {
      holders.splice(index, 1);
    }
    if (holders.length === 0) {
      lenis.start();
    }
  }

  // The popups are in the page from the start (Bricks prints them in the footer): the
  // attribute is set now, so a popup scrolls natively even before its first opening.
  Array.prototype.forEach.call(document.querySelectorAll(".brx-popup"), function (popup) {
    popup.setAttribute("data-lenis-prevent", "");
  });
  document.addEventListener(BRICKS_POPUP_OPEN, onPopupOpen);
  document.addEventListener(BRICKS_POPUP_CLOSE, onPopupClose);
})();
