/**
 * Lümia Tools - Admin JavaScript
 * Vanilla JS only. Loaded only on the Lümia pages.
 * The toast/notifications logic lives in notifications.js (loaded globally).
 */
(function () {
  "use strict";

  var isDirty = false;

  // Translated strings come from PHP: lumiaAdmin.i18n, built in
  // Admin::localize_admin_script() (see docs/core.md).
  function t(key) {
    return (window.lumiaAdmin && lumiaAdmin.i18n && lumiaAdmin.i18n[key]) || "";
  }

  document.addEventListener("DOMContentLoaded", function () {
    initToggles();
    initFormValidation();
    initModal();
    initModalTriggers();
    initUnsavedWarning();
    initModuleAjaxToggles();
    initTooltips();
    initTabs();
  });

  /* ================================================================
   * TOGGLES
   * ================================================================ */

  function initToggles() {
    var toggles = document.querySelectorAll(
      '.lumia-toggle input[type="checkbox"]',
    );

    toggles.forEach(function (toggle) {
      toggle.addEventListener("change", function () {
        var label = this.closest(".lumia-form__group--toggle");
        if (label) {
          label.classList.toggle("is-active", this.checked);
        }
      });
    });
  }

  /* ================================================================
   * FORM VALIDATION
   * ================================================================ */

  function initFormValidation() {
    var forms = document.querySelectorAll(".lumia-form");

    forms.forEach(function (form) {
      form.addEventListener("submit", function (e) {
        var requiredInputs = form.querySelectorAll("[required]");
        var isValid = true;

        requiredInputs.forEach(function (input) {
          if (!input.value.trim()) {
            isValid = false;
            input.classList.add("is-invalid");
          } else {
            input.classList.remove("is-invalid");
          }
        });

        if (!isValid) {
          e.preventDefault();
        }
      });
    });
  }

  /* ================================================================
   * REUSABLE MODAL
   * Usage: window.lumiaModal.open({ title, message, confirmLabel,
   *         cancelLabel, onConfirm, danger })
   * ================================================================ */

  var modalOverlay, modalEl, modalTitle, modalMessage, modalConfirmBtn, modalCancelBtn;

  function initModal() {
    modalOverlay = document.getElementById("lumia-modal-overlay");
    if (!modalOverlay) return;

    modalEl         = modalOverlay.querySelector(".lumia-modal");
    modalTitle      = modalOverlay.querySelector(".lumia-modal__title");
    modalMessage    = modalOverlay.querySelector(".lumia-modal__message");
    modalConfirmBtn = modalOverlay.querySelector(".lumia-modal__confirm");
    modalCancelBtn  = modalOverlay.querySelector(".lumia-modal__cancel");

    modalCancelBtn.addEventListener("click", closeModal);
    modalOverlay.addEventListener("click", function (e) {
      if (e.target === modalOverlay) closeModal();
    });
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && modalOverlay.classList.contains("is-open")) {
        closeModal();
      }
    });
  }

  function openModal(options) {
    if (!modalOverlay) return;

    options = options || {};
    modalTitle.textContent      = options.title   || "";
    modalMessage.textContent    = options.message || "";
    modalConfirmBtn.textContent = options.confirmLabel || t("confirm");
    modalCancelBtn.textContent  = options.cancelLabel  || t("cancel");

    modalConfirmBtn.className = "lumia-btn lumia-btn--sm " +
      (options.danger ? "lumia-btn--danger" : "lumia-btn--primary");

    var handler = options.onConfirm || function () {};
    var newBtn  = modalConfirmBtn.cloneNode(true);
    newBtn.textContent = modalConfirmBtn.textContent;
    newBtn.className   = modalConfirmBtn.className;
    newBtn.addEventListener("click", function () {
      closeModal();
      handler();
    });
    modalConfirmBtn.parentNode.replaceChild(newBtn, modalConfirmBtn);
    modalConfirmBtn = newBtn;

    modalOverlay.classList.add("is-open");
    modalOverlay.setAttribute("aria-hidden", "false");
    modalConfirmBtn.focus();
  }

  function closeModal() {
    if (!modalOverlay) return;
    modalOverlay.classList.remove("is-open");
    modalOverlay.setAttribute("aria-hidden", "true");
  }

  window.lumiaModal = { open: openModal, close: closeModal };

  /* ================================================================
   * [data-modal-confirm] TRIGGERS
   * Buttons that open the modal before submitting a form.
   * ================================================================ */

  function initModalTriggers() {
    document.addEventListener("click", function (e) {
      var btn = e.target.closest("[data-modal-confirm]");
      if (!btn) return;
      e.preventDefault();

      var formId = btn.getAttribute("data-modal-form");
      var form   = formId ? document.getElementById(formId) : null;

      openModal({
        title:        btn.getAttribute("data-modal-title")   || t("confirm"),
        message:      btn.getAttribute("data-modal-message") || "",
        confirmLabel: btn.getAttribute("data-modal-confirm-label") || t("confirm"),
        danger:       btn.hasAttribute("data-modal-danger") || btn.classList.contains("lumia-btn--danger"),
        onConfirm: function () {
          if (form) form.submit();
        },
      });
    });
  }

  /* ================================================================
   * UNSAVED CHANGES WARNING
   * ================================================================ */

  function initUnsavedWarning() {
    var forms   = document.querySelectorAll(
      ".lumia-form, #lumia-save-settings-form, #lumia-module-form",
    );

    // Only a named field is sent on save. Search, list filters or test fields
    // (logs, SMTP) have no name: using them leaves nothing unsaved.
    function markDirty(e) {
      if (e.target && e.target.name) isDirty = true;
    }

    forms.forEach(function (form) {
      form.addEventListener("input", markDirty, { passive: true });
      form.addEventListener("change", markDirty, { passive: true });
      // Submitting the form resets the dirty state
      form.addEventListener("submit", function () { isDirty = false; });
    });

    // Intercept internal navigation links (sidebar, WP menus, etc.)
    document.addEventListener("click", function (e) {
      if (!isDirty) return;
      var link = e.target.closest("a[href]");
      if (!link) return;

      var href = link.getAttribute("href");
      if (!href || href.startsWith("#")) return;

      // Ignore logouts
      if (href.indexOf("action=logout") !== -1) return;

      // Resolve the absolute URL to compare the origin
      var resolved;
      try {
        resolved = new URL(href, window.location.href);
      } catch (_) {
        return;
      }
      // Ignore links to another domain
      if (resolved.origin !== window.location.origin) return;

      e.preventDefault();
      var fullHref = resolved.href;
      openModal({
        title:        t("unsavedTitle"),
        message:      t("unsavedText"),
        confirmLabel: t("unsavedLeave"),
        cancelLabel:  t("unsavedStay"),
        danger:       true,
        onConfirm: function () {
          isDirty = false;
          window.location.href = fullHref;
        },
      });
    });

    window.addEventListener("beforeunload", function (e) {
      if (isDirty) {
        e.preventDefault();
        e.returnValue = "";
      }
    });
  }

  /* ================================================================
   * AJAX MODULE TOGGLES
   * ================================================================ */

  function initModuleAjaxToggles() {
    var moduleGrid = document.querySelector(".lumia-module-grid");
    if (!moduleGrid || typeof lumiaAdmin === "undefined") return;

    moduleGrid.addEventListener("change", function (e) {
      var checkbox = e.target.closest(
        '.lumia-module-card .lumia-toggle input[type="checkbox"]',
      );
      if (!checkbox) return;

      var card     = checkbox.closest(".lumia-module-card");
      var moduleId = checkbox.getAttribute("data-module-id");
      if (!card || !moduleId) return;

      var action  = checkbox.checked ? "activate" : "deactivate";
      var formData = new FormData();
      formData.append("action",       "lumia_ajax_toggle_module");
      formData.append("nonce",        lumiaAdmin.nonce);
      formData.append("module",       moduleId);
      formData.append("lumia_action",  action);

      // Immediate visual feedback
      checkbox.disabled = true;

      fetch(lumiaAdmin.ajaxUrl, {
        method:      "POST",
        credentials: "same-origin",
        body:        formData,
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          checkbox.disabled = false;
          if (!data.success) {
            // Revert to the previous state
            checkbox.checked = !checkbox.checked;
            if (typeof window.lumiaShowToast === "function") {
              window.lumiaShowToast(
                (data.data && data.data.message) || t("error"),
                "error",
              );
            }
            return;
          }

          var isActive = data.data.active;
          card.classList.toggle("lumia-module-card--active", isActive);

          // Update / create the "Configure" button
          var actions = card.querySelector(".lumia-module-card__actions");
          if (actions) {
            var existingLink = actions.querySelector(".lumia-btn");
            if (isActive) {
              if (!existingLink) {
                var a    = document.createElement("a");
                a.href   = data.data.configure_url;
                a.className = "lumia-btn lumia-btn--sm lumia-btn--secondary";
                a.textContent = t("configure");
                actions.appendChild(a);
              }
            } else {
              if (existingLink) existingLink.remove();
            }
          }

          if (typeof window.lumiaShowToast === "function") {
            window.lumiaShowToast(data.data.notice, "success");
          }

          // Reload the page to update the side navigation
          isDirty = false;
          setTimeout(function () {
            window.location.reload();
          }, 1200);
        })
        .catch(function () {
          checkbox.disabled = false;
          checkbox.checked  = !checkbox.checked;
        });
    });
  }

  /* ================================================================
   * TABS — [data-lumia-tabs] + [data-lumia-tab-panel]
   * Client-side sub-tabs of a screen: all the panels stay in the DOM (and
   * in the form, hence posted on save). The last opened tab is remembered
   * through sessionStorage: after a save, the redirect brings back to the
   * screen, not to the tab.
   * ================================================================ */

  function initTabs() {
    document.querySelectorAll("[data-lumia-tabs]").forEach(function (list) {
      var group = list.dataset.lumiaTabs;
      var tabs = Array.prototype.slice.call(list.querySelectorAll("[data-lumia-tab]"));
      var panels = document.querySelectorAll('[data-lumia-tab-panel][data-lumia-tabs-group="' + group + '"]');
      var key = "lumia-tab:" + group;

      if (!tabs.length) return;

      function activate(name, focus) {
        var found = tabs.some(function (tab) { return tab.dataset.lumiaTab === name; });
        if (!found) name = tabs[0].dataset.lumiaTab;

        tabs.forEach(function (tab) {
          var on = tab.dataset.lumiaTab === name;
          tab.classList.toggle("is-active", on);
          tab.setAttribute("aria-selected", on ? "true" : "false");
          tab.tabIndex = on ? 0 : -1;
          if (on && focus) tab.focus();
        });
        panels.forEach(function (panel) {
          panel.hidden = panel.dataset.lumiaTabPanel !== name;
        });

        try { sessionStorage.setItem(key, name); } catch (e) { /* storage unavailable */ }

        list.dispatchEvent(new CustomEvent("lumia:tab", { bubbles: true, detail: { group: group, name: name } }));
      }

      list.addEventListener("click", function (e) {
        var tab = e.target.closest("[data-lumia-tab]");
        if (tab && list.contains(tab)) activate(tab.dataset.lumiaTab, false);
      });

      // Arrows, Home, End: the "tabs" pattern of the ARIA Authoring Practices.
      list.addEventListener("keydown", function (e) {
        var index = tabs.indexOf(document.activeElement);
        if (index < 0) return;
        var next = { ArrowRight: index + 1, ArrowLeft: index - 1, Home: 0, End: tabs.length - 1 }[e.key];
        if (next === undefined) return;
        e.preventDefault();
        activate(tabs[(next + tabs.length) % tabs.length].dataset.lumiaTab, true);
      });

      // An invalid field in a hidden panel: the browser refuses to submit
      // without being able to show the field. Open its tab.
      panels.forEach(function (panel) {
        panel.addEventListener("invalid", function () {
          if (panel.hidden) activate(panel.dataset.lumiaTabPanel, false);
        }, true);
      });

      var initial = null;
      try { initial = sessionStorage.getItem(key); } catch (e) { /* storage unavailable */ }
      activate(initial || (list.querySelector("[data-lumia-tab].is-active") || tabs[0]).dataset.lumiaTab, false);

      list.lumiaActivate = activate;
    });
  }

  /** Opens a tab programmatically: window.lumiaTabs.activate('smtp', 'log'). */
  window.lumiaTabs = {
    activate: function (group, name) {
      var list = document.querySelector('[data-lumia-tabs="' + group + '"]');
      if (list && list.lumiaActivate) list.lumiaActivate(name, false);
    },
  };

  /* ================================================================
   * UTILITIES
   * ================================================================ */

  window.lumiaConfirm = function (message) {
    return confirm(message || t("confirmAction"));
  };

  /* ================================================================
   * NAMED MODALS — lumiaModalOpen / lumiaModalClose
   * For modals with persistent HTML (form, etc.).
   * Complements lumiaModal.open(), which is programmatic.
   * Usage: lumiaModalOpen('my-modal-id')
   * ================================================================ */

  window.lumiaModalOpen = function (id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.classList.add("is-open");
    // Focus the first text field if present
    var input = el.querySelector("input[type='text'], input[type='number'], textarea");
    if (input) {
      setTimeout(function () {
        input.select();
        input.focus();
      }, 60);
    }
  };

  window.lumiaModalClose = function (id) {
    var el = document.getElementById(id);
    if (el) el.classList.remove("is-open");
  };

  // Global delegation: click outside .lumia-modal or on .lumia-modal-close
  document.addEventListener("click", function (e) {
    // Click on the overlay itself (outside the box)
    if (
      e.target.classList.contains("lumia-modal-overlay") &&
      e.target.id !== "lumia-modal-overlay" // handled by initModal()
    ) {
      e.target.classList.remove("is-open");
      return;
    }
    // Explicit close button
    var closeBtn = e.target.closest && e.target.closest(".lumia-modal-close");
    if (closeBtn) {
      var overlay = closeBtn.closest(".lumia-modal-overlay");
      if (overlay && overlay.id !== "lumia-modal-overlay") {
        overlay.classList.remove("is-open");
      }
    }
  });

  // Escape closes all the open named modals (except the main one)
  document.addEventListener("keydown", function (e) {
    if (e.key !== "Escape") return;
    var open = document.querySelectorAll(
      ".lumia-modal-overlay.is-open:not(#lumia-modal-overlay)",
    );
    open.forEach(function (el) {
      el.classList.remove("is-open");
    });
  });

  /* ================================================================
   * TOOLTIPS
   *
   * Usage: <button data-lumia-tip="Text"> — and, if needed,
   * data-lumia-tip-placement="top|bottom|left|right" (default: top).
   * No initialization needed: everything goes through delegation, so markup
   * rendered in JS afterwards (menu creator tree, lists reloaded through
   * AJAX) is covered without a second thought.
   *
   * Positioning is `fixed` + translate3d: a tooltip that is a child of an
   * overflow:hidden column would be clipped, and an absolutely positioned
   * tooltip would have to know the offsets of each of its parents. In return
   * scrolling must be tracked — hence the recalculation on scroll/resize,
   * throttled with requestAnimationFrame.
   * ================================================================ */

  var tipEl      = null;
  var tipBox     = null;
  var tipArrow   = null;
  var tipText    = null;
  var tipRef     = null;   // element currently described
  var tipShowT   = null;
  var tipHideT   = null;
  var tipRaf     = null;
  var tipVisible = false;

  var TIP_SHOW_DELAY = 140;
  var TIP_HIDE_DELAY = 60;
  var TIP_MARGIN     = 8;  // minimum margin with the window edge
  var TIP_OFFSET     = 8;  // distance between the element and the box

  function initTooltips() {
    // mouseover/mouseout (not mouseenter/leave): only the former bubble, which
    // is the condition for a single delegation on the document.
    document.addEventListener("mouseover", function (e) {
      var el = tipTarget(e.target);
      if (el) tipScheduleShow(el);
    });

    document.addEventListener("mouseout", function (e) {
      var el = tipTarget(e.target);
      if (!el || el !== tipRef) return;
      // Moving onto a child of the same target: not an exit.
      if (e.relatedTarget && el.contains(e.relatedTarget)) return;
      tipScheduleHide();
    });

    // Keyboard: same trigger on focus, otherwise the tooltip does not exist
    // for those who navigate with Tab — which is exactly what `title` does badly.
    document.addEventListener("focusin", function (e) {
      var el = tipTarget(e.target);
      if (el) tipShow(el);
    });
    document.addEventListener("focusout", function (e) {
      if (tipRef && tipTarget(e.target) === tipRef) tipHide();
    });

    // A click usually opens a panel or a modal: keeping the tooltip on top
    // is pointless.
    document.addEventListener("mousedown", function () { tipHide(); }, true);
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape") tipHide();
    });

    window.addEventListener("scroll", tipReposition, true);
    window.addEventListener("resize", tipReposition);
  }

  function tipTarget(node) {
    if (!node || !node.closest) return null;
    var el = node.closest("[data-lumia-tip]");
    if (!el || !el.getAttribute("data-lumia-tip")) return null;
    if (el.disabled) return null;
    return el;
  }

  function tipEnsureEl() {
    if (tipEl) return;
    tipEl = document.createElement("div");
    tipEl.className = "lumia-tooltip";
    tipEl.setAttribute("role", "tooltip");
    tipEl.innerHTML =
      '<div class="lumia-tooltip__box">' +
        '<span class="lumia-tooltip__text"></span>' +
        '<span class="lumia-tooltip__arrow"></span>' +
      "</div>";
    document.body.appendChild(tipEl);
    tipBox   = tipEl.querySelector(".lumia-tooltip__box");
    tipText  = tipEl.querySelector(".lumia-tooltip__text");
    tipArrow = tipEl.querySelector(".lumia-tooltip__arrow");
  }

  function tipScheduleShow(el) {
    if (el === tipRef && tipVisible) { clearTimeout(tipHideT); return; }
    clearTimeout(tipHideT);
    clearTimeout(tipShowT);
    // A tooltip already open: chain without delay, like a menu whose entries
    // are hovered (waiting 140 ms again gives a sluggish UI).
    if (tipVisible) { tipShow(el); return; }
    tipShowT = setTimeout(function () { tipShow(el); }, TIP_SHOW_DELAY);
  }

  function tipScheduleHide() {
    clearTimeout(tipShowT);
    clearTimeout(tipHideT);
    tipHideT = setTimeout(tipHide, TIP_HIDE_DELAY);
  }

  function tipShow(el) {
    var text = el.getAttribute("data-lumia-tip");
    if (!text) return;
    tipEnsureEl();
    clearTimeout(tipShowT);
    clearTimeout(tipHideT);

    // `title` would duplicate our box: remove it, keeping it aside so it can
    // be given back if needed.
    var native = el.getAttribute("title");
    if (native) {
      el.setAttribute("data-lumia-tip-title", native);
      el.removeAttribute("title");
    }

    tipRef = el;
    tipText.textContent = text;
    tipEl.setAttribute("data-placement", tipPlacementOf(el));
    tipEl.classList.add("is-visible");
    tipVisible = true;
    tipPlace();
  }

  function tipHide() {
    clearTimeout(tipShowT);
    clearTimeout(tipHideT);
    if (!tipEl) { tipRef = null; return; }
    tipEl.classList.remove("is-visible");
    tipVisible = false;
    tipRef = null;
  }

  function tipPlacementOf(el) {
    var p = el.getAttribute("data-lumia-tip-placement") || "top";
    return /^(top|bottom|left|right)$/.test(p) ? p : "top";
  }

  function tipReposition() {
    if (!tipVisible || tipRaf) return;
    tipRaf = window.requestAnimationFrame(function () {
      tipRaf = null;
      tipPlace();
    });
  }

  function tipPlace() {
    if (!tipVisible || !tipRef) return;

    // The element may have disappeared (list re-render) or left the screen by
    // scrolling in its column: nothing left to describe.
    if (!document.contains(tipRef)) { tipHide(); return; }
    var r = tipRef.getBoundingClientRect();
    if (!r.width && !r.height) { tipHide(); return; }
    if (r.bottom < 0 || r.right < 0 ||
        r.top > window.innerHeight || r.left > window.innerWidth) { tipHide(); return; }

    tipBox.style.maxWidth = Math.min(260, window.innerWidth - TIP_MARGIN * 2) + "px";
    var w = tipEl.offsetWidth;
    var h = tipEl.offsetHeight;
    var p = tipPlacementOf(tipRef);

    // Flip to the opposite side when room is lacking — and only if the
    // opposite offers more, to avoid oscillating.
    var space = {
      top:    r.top - TIP_OFFSET - TIP_MARGIN,
      bottom: window.innerHeight - r.bottom - TIP_OFFSET - TIP_MARGIN,
      left:   r.left - TIP_OFFSET - TIP_MARGIN,
      right:  window.innerWidth - r.right - TIP_OFFSET - TIP_MARGIN,
    };
    var opposite = { top: "bottom", bottom: "top", left: "right", right: "left" };
    var need = (p === "top" || p === "bottom") ? h : w;
    if (space[p] < need && space[opposite[p]] > space[p]) p = opposite[p];

    var left, top;
    if (p === "top" || p === "bottom") {
      left = r.left + r.width / 2 - w / 2;
      top  = p === "top" ? r.top - h - TIP_OFFSET : r.bottom + TIP_OFFSET;
    } else {
      top  = r.top + r.height / 2 - h / 2;
      left = p === "left" ? r.left - w - TIP_OFFSET : r.right + TIP_OFFSET;
    }

    // Clamp within the window: the box slides, the arrow stays on the
    // element (otherwise it points next to it in the corners).
    var maxLeft = window.innerWidth - w - TIP_MARGIN;
    var maxTop  = window.innerHeight - h - TIP_MARGIN;
    left = Math.max(TIP_MARGIN, Math.min(left, Math.max(TIP_MARGIN, maxLeft)));
    top  = Math.max(TIP_MARGIN, Math.min(top,  Math.max(TIP_MARGIN, maxTop)));

    if (p === "top" || p === "bottom") {
      tipArrow.style.top  = "";
      tipArrow.style.left = clampArrow(r.left + r.width / 2 - left, w) + "px";
    } else {
      tipArrow.style.left = "";
      tipArrow.style.top  = clampArrow(r.top + r.height / 2 - top, h) + "px";
    }

    tipEl.setAttribute("data-placement", p);
    tipEl.style.transform = "translate3d(" + Math.round(left) + "px," + Math.round(top) + "px,0)";
  }

  function clampArrow(pos, size) {
    return Math.max(10, Math.min(pos, size - 10));
  }

  /**
   * Public API — useful when the DOM moves under the tooltip (row removed,
   * panel collapsed) or to set a text on the fly.
   */
  window.lumiaTooltip = {
    hide: tipHide,
    refresh: tipPlace,
    set: function (el, text) {
      if (!el) return;
      if (text) el.setAttribute("data-lumia-tip", text);
      else      el.removeAttribute("data-lumia-tip");
      if (tipRef === el) {
        if (text) tipShow(el);
        else      tipHide();
      }
    },
  };

})();
