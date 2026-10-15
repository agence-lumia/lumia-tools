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
 * - Popups and menus: while a Bricks popup is open, or a menu that locks the page
 *   (body.no-scroll: off-canvas, mobile menu), the page behind does not scroll, and they
 *   scroll natively (data-lenis-prevent). A menu is marked only while it locks the page:
 *   the bar of a desktop nav is part of the page, and the wheel over it goes through Lenis.
 * - Desktop only (syncTouch off): touch devices keep their native, inertial scroll.
 * - Stands down: with reduced motion (native scroll), and when the former Motion snippet
 *   (its global is named in window.lumiaMotionScroll.legacyGlobal) owns the page.
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
  // What scrolls by itself inside a popup or an off-canvas: marked at load, and again when it
  // locks the page, so an element added after the load is covered too.
  var NATIVE_SCROLL = ".brx-popup, .brxe-offcanvas";
  // Menus: the same nodes are the bar of a desktop nav, part of the page. Marked only while the
  // page is locked (the mobile menu is open), unmarked when it is unlocked.
  var MENU_SCROLL = ".brx-nav-nested-items, .bricks-mobile-menu-wrapper";

  var gsap = window.gsap;
  var ScrollTrigger = window.ScrollTrigger;
  var settings = window.lumiaMotionScroll || {};

  // The former Motion snippet runs its own smooth scroll: two instances would fight over the
  // scroll position. Its global is named by the plugin, not here.
  function formerSnippetLoaded() {
    return !!(settings.legacyGlobal && window[settings.legacyGlobal]);
  }

  // Reduced motion: the visitor asked for no animated movement, so the scroll stays native.
  // <html> never gets the `lenis` class, and the stylesheet's scroll-margin-top places anchors.
  function reducedMotion() {
    return !!(window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches);
  }

  // window.lenis is set by the constructor of any Lenis on the page.
  if (formerSnippetLoaded() || window.lenis || reducedMotion()) {
    return;
  }

  /* ================================================================
   * SETTINGS
   * ================================================================ */

  // window.lumiaMotionScroll = { lerp } is printed by the plugin from the sanitized settings;
  // a missing or hand-edited value falls back to the Lenis default.
  function intensity() {
    var lerp = typeof settings.lerp === "number" ? settings.lerp : DEFAULT_LERP;
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

  var destroyed = false; // set when this file stands down for the former snippet

  var lenis = new Lenis({
    lerp: intensity(),
    autoRaf: !gsap,
    syncTouch: false,
    allowNestedScroll: true,
    stopInertiaOnNavigate: true,
    anchors: anchors,
  });
  window.lumiaLenis = lenis;

  // GSAP gives seconds, Lenis wants milliseconds.
  function tick(time) {
    lenis.raf(time * 1000);
  }

  if (gsap && gsap.ticker) {
    gsap.ticker.add(tick);
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
    // A target without a box (display:none) was not scrolled to by the browser either.
    if (!target || !target.getClientRects().length) {
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
      // Stood down meanwhile (the former snippet showed up at load): its scroll, not ours.
      if (destroyed) {
        return;
      }
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
   * BRICKS POPUPS AND MENUS
   * ================================================================ */

  // Popups holding the scroll. An event without a popup (a script that fires it by hand)
  // counts as one popup of its own.
  var ANONYMOUS = {};
  var holders = [];
  var observer = null;

  function popupOf(event) {
    return (event && event.detail && event.detail.popupElement) || null;
  }

  var markedMenus = []; // menus this file marked: the only ones it unmarks

  function markNativeScroll() {
    Array.prototype.forEach.call(document.querySelectorAll(NATIVE_SCROLL), function (node) {
      node.setAttribute("data-lenis-prevent", "");
    });
  }

  function markMenus() {
    Array.prototype.forEach.call(document.querySelectorAll(MENU_SCROLL), function (node) {
      if (!node.hasAttribute("data-lenis-prevent")) {
        node.setAttribute("data-lenis-prevent", "");
        markedMenus.push(node);
      }
    });
  }

  function unmarkMenus() {
    markedMenus.forEach(function (node) {
      node.removeAttribute("data-lenis-prevent");
    });
    markedMenus = [];
  }

  // Bricks puts body.no-scroll while an off-canvas ("No scroll"), the mobile menu of a nested
  // nav or of a nav menu, or a popup is open. Only the first two fire no event.
  function pageLocked() {
    return !!document.body && document.body.classList.contains("no-scroll");
  }

  // Stopped while a popup holds the scroll or the page is locked, running otherwise.
  function sync() {
    if (holders.length || pageLocked()) {
      markNativeScroll();
      markMenus();
      lenis.stop();
    } else {
      unmarkMenus();
      lenis.start();
    }
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
    sync();
  }

  function onPopupClose(event) {
    var index = holders.indexOf(popupOf(event) || ANONYMOUS);
    if (index !== -1) {
      holders.splice(index, 1);
    }
    sync();
  }

  // The popups and off-canvases are in the page from the start (Bricks prints them in the
  // footer): the attribute is set now, so they scroll natively even before their first opening.
  markNativeScroll();
  document.addEventListener(BRICKS_POPUP_OPEN, onPopupOpen);
  document.addEventListener(BRICKS_POPUP_CLOSE, onPopupClose);
  if (window.MutationObserver && document.body) {
    observer = new MutationObserver(sync);
    observer.observe(document.body, { attributes: true, attributeFilter: ["class"] });
  }
  sync();

  /* ================================================================
   * STANDING DOWN
   * ================================================================ */

  // The former snippet may load after this file: look again once the page has loaded.
  window.addEventListener(
    "load",
    function () {
      if (!formerSnippetLoaded()) {
        return;
      }
      document.removeEventListener(BRICKS_POPUP_OPEN, onPopupOpen);
      document.removeEventListener(BRICKS_POPUP_CLOSE, onPopupClose);
      if (observer) {
        observer.disconnect();
      }
      if (gsap && gsap.ticker) {
        gsap.ticker.remove(tick);
      }
      destroyed = true;
      unmarkMenus();
      lenis.destroy();
      delete window.lumiaLenis;
    },
    { once: true }
  );
})();
