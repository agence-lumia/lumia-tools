/**
 * Lümia Tools - Notifications
 * Lümia toast + WP notification center
 * Loaded on the whole WordPress admin.
 */
(function () {
  "use strict";

  var TOAST_DURATION = 4000;

  var ICONS = {
    success:
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>',
    error:
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>',
    warning:
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>',
    info: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>',
  };

  var CLOSE_SVG =
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>';

  var BELL_EMPTY_SVG =
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>';

  var notifCount = 0;

  // Translated strings come from PHP: window.lumiaNotifData.i18n, printed by
  // Admin::render_notification_drawer(). lumiaAdmin is not available here, as
  // this script runs on the whole WP admin (see docs/core.md).
  function t(key) {
    var data = window.lumiaNotifData;
    return (data && data.i18n && data.i18n[key]) || "";
  }

  /* ================================================================
   * TOAST
   * ================================================================ */

  function initToasts() {
    var data = window.lumiaToastData;
    if (!data || !data.message) return;
    showToast(data.message, data.type || "success");
  }

  function showToast(message, type) {
    var container = document.getElementById("lumia-toast-container");
    if (!container) return;

    type = type || "success";

    var toast = document.createElement("div");
    toast.className = "lumia-toast lumia-toast--" + type;
    toast.setAttribute("role", "alert");
    toast.setAttribute("aria-live", "assertive");

    toast.innerHTML =
      '<div class="lumia-toast__inner">' +
      '<span class="lumia-toast__dot"></span>' +
      '<p class="lumia-toast__message">' + escapeHtml(message) + "</p>" +
      '<button class="lumia-toast__close" type="button" aria-label="' + escapeAttr(t("close")) + '">' + CLOSE_SVG + "</button>" +
      "</div>" +
      '<div class="lumia-toast__progress-bar"><span class="lumia-toast__progress-fill"></span></div>';

    container.appendChild(toast);

    var closeBtn = toast.querySelector(".lumia-toast__close");
    var fill = toast.querySelector(".lumia-toast__progress-fill");

    var dismissed = false;
    var paused = false;
    var elapsed = 0;
    var lastTick = Date.now();

    // Animated entrance
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        toast.classList.add("is-visible");
      });
    });

    function updateProgress() {
      var pct = Math.max(0, 1 - elapsed / TOAST_DURATION);
      fill.style.transform = "scaleX(" + pct + ")";
    }

    var raf;
    function tick() {
      if (dismissed || paused) return;
      var now = Date.now();
      elapsed += now - lastTick;
      lastTick = now;
      updateProgress();
      if (elapsed >= TOAST_DURATION) {
        dismiss();
        return;
      }
      raf = requestAnimationFrame(tick);
    }

    setTimeout(function () {
      lastTick = Date.now();
      raf = requestAnimationFrame(tick);
    }, 50);

    function dismiss() {
      if (dismissed) return;
      dismissed = true;
      cancelAnimationFrame(raf);
      toast.classList.remove("is-visible");
      toast.classList.add("is-hiding");
      toast.addEventListener(
        "transitionend",
        function () {
          if (toast.parentNode) toast.parentNode.removeChild(toast);
        },
        { once: true },
      );
    }

    closeBtn.addEventListener("click", dismiss);

    // Pause on hover
    toast.addEventListener("mouseenter", function () {
      if (!paused) cancelAnimationFrame(raf);
    });
    toast.addEventListener("mouseleave", function () {
      if (!paused && !dismissed) {
        lastTick = Date.now();
        raf = requestAnimationFrame(tick);
      }
    });
  }

  /* ================================================================
   * NOTIFICATION CENTER
   * ================================================================ */

  function initNotificationCenter() {
    var drawer = document.getElementById("lumia-notif-drawer");
    var overlay = document.getElementById("lumia-notif-overlay");
    var trigger = document.querySelector(
      "#wp-admin-bar-lumia-notif-center > a",
    );
    var closeBtn = document.getElementById("lumia-notif-close");
    var body = document.getElementById("lumia-notif-body");

    if (!drawer) return;

    // Persistent Lümia notices first, then ephemeral WP notices
    var lumiaNotices = (window.lumiaPersistentNotices || []).map(function (n) {
      return { id: n.id, type: n.type || "info", message: n.message, source: "lumia" };
    });
    var wpNotices = parseWpNotices(window.lumiaWpNoticesHtml || "").map(function (n) {
      return { type: n.type, html: n.html, source: "wp" };
    });
    var allNotices = lumiaNotices.concat(wpNotices);

    notifCount = allNotices.length;
    updateBadge(notifCount);
    populateDrawer(body, allNotices);

    function openDrawer() {
      drawer.classList.add("is-open");
      if (overlay) overlay.classList.add("is-open");
      drawer.setAttribute("aria-hidden", "false");
      if (closeBtn) closeBtn.focus();
    }

    function closeDrawer() {
      drawer.classList.remove("is-open");
      if (overlay) overlay.classList.remove("is-open");
      drawer.setAttribute("aria-hidden", "true");
      if (trigger) trigger.focus();
    }

    if (trigger) {
      trigger.addEventListener("click", function (e) {
        e.preventDefault();
        drawer.classList.contains("is-open") ? closeDrawer() : openDrawer();
      });
    }

    if (closeBtn) closeBtn.addEventListener("click", closeDrawer);
    if (overlay) overlay.addEventListener("click", closeDrawer);

    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && drawer.classList.contains("is-open")) {
        closeDrawer();
      }
    });
  }

  var BADGE_MAX = 9;

  function updateBadge(count) {
    var badge = document.getElementById("lumia-notif-badge");
    if (!badge) return;
    if (count > 0) {
      badge.setAttribute("data-count", count);
      badge.textContent = count > BADGE_MAX ? BADGE_MAX + "+" : String(count);
      badge.style.display = "";
    } else {
      badge.textContent = "";
      badge.style.display = "none";
    }
  }

  function parseWpNotices(html) {
    if (!html || !html.trim()) return [];
    var tmp = document.createElement("div");
    tmp.innerHTML = html;
    var nodes = tmp.querySelectorAll(".notice, .updated, .error");
    var notices = [];
    nodes.forEach(function (el) {
      var btn = el.querySelector(".notice-dismiss");
      if (btn) btn.parentNode.removeChild(btn);
      notices.push({ type: detectNoticeType(el), html: el.innerHTML.trim() });
    });
    return notices;
  }

  function detectNoticeType(el) {
    if (el.classList.contains("notice-success") || el.classList.contains("updated")) return "success";
    if (el.classList.contains("notice-error")   || el.classList.contains("error"))   return "error";
    if (el.classList.contains("notice-warning"))  return "warning";
    return "info";
  }

  function populateDrawer(body, notices) {
    if (!body) return;
    if (notices.length === 0) {
      body.innerHTML =
        '<div class="lumia-notif-drawer__empty">' +
        BELL_EMPTY_SVG +
        "<span>" + escapeHtml(t("noNotifications")) + "</span>" +
        "</div>";
      return;
    }

    var html = "";
    notices.forEach(function (n) {
      var sourceLabel = n.source === "lumia" ? escapeHtml(t("brand")) : "WP";
      var sourceClass = "lumia-notif-item--" + n.source;
      var content = n.source === "lumia" ? n.message : n.html;
      var idAttr = n.id ? ' data-notice-id="' + n.id + '"' : "";

      html +=
        '<div class="lumia-notif-item lumia-notif-item--' +
        sanitizeClass(n.type) +
        " " +
        sourceClass +
        '" data-source="' +
        n.source +
        '"' +
        idAttr +
        ">" +
        '<span class="lumia-notif-item__icon">' +
        (ICONS[n.type] || ICONS.info) +
        "</span>" +
        '<div class="lumia-notif-item__content">' +
        '<span class="lumia-notif-item__source">' +
        sourceLabel +
        "</span>" +
        '<div class="lumia-notif-item__body">' +
        content +
        "</div>" +
        "</div>" +
        '<button class="lumia-notif-item__dismiss" type="button" aria-label="' + escapeAttr(t("close")) + '">' +
        CLOSE_SVG +
        "</button>" +
        "</div>";
    });
    body.innerHTML = html;

    body.querySelectorAll(".lumia-notif-item").forEach(function (item) {
      var btn = item.querySelector(".lumia-notif-item__dismiss");
      if (!btn) return;
      btn.addEventListener("click", function () {
        var source = item.getAttribute("data-source");
        var noticeId = item.getAttribute("data-notice-id");
        if (source === "lumia" && noticeId) {
          var notifData = window.lumiaNotifData || window.lumiaAdmin || {};
          var formData = new FormData();
          formData.append("action", "lumia_dismiss_notice");
          formData.append("nonce", notifData.nonce || "");
          formData.append("notice_id", noticeId);
          fetch(notifData.ajaxUrl || "", {
            method: "POST",
            credentials: "same-origin",
            body: formData,
          })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
              if (resp.success) {
                removeNotifItem(item, body);
              }
            });
        } else {
          removeNotifItem(item, body);
        }
      });
    });
  }

  function removeNotifItem(item, body) {
    if (item.parentNode) item.parentNode.removeChild(item);
    notifCount = Math.max(0, notifCount - 1);
    updateBadge(notifCount);
    if (body && body.querySelectorAll(".lumia-notif-item").length === 0) {
      body.innerHTML =
        '<div class="lumia-notif-drawer__empty">' +
        BELL_EMPTY_SVG +
        "<span>" + escapeHtml(t("noNotifications")) + "</span>" +
        "</div>";
    }
  }

  /* ================================================================
   * UTILITIES
   * ================================================================ */

  function escapeHtml(str) {
    var d = document.createElement("div");
    d.textContent = str;
    return d.innerHTML;
  }

  function escapeAttr(str) {
    return escapeHtml(str).replace(/"/g, "&quot;");
  }

  function sanitizeClass(str) {
    return String(str).replace(/[^a-z0-9-]/gi, "");
  }

  /* ================================================================
   * INIT
   * ================================================================ */

  document.addEventListener("DOMContentLoaded", function () {
    initToasts();
    initNotificationCenter();
  });

  // Public API for the Lümia modules
  window.lumiaShowToast = showToast;
})();
