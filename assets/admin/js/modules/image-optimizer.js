/**
 * Lümia Tools - Image Optimizer admin JS
 * Script dedicated to the Image Optimizer module.
 */
(function () {
  "use strict";

  document.addEventListener("DOMContentLoaded", function () {
    initBulkOptimization();
    initMediaActions();
    initSvgRolesToggle();
    initDelivery();
  });

  // Delivery tab: Retest button, copy of the nginx rule, and the check from the
  // administrator's browser (spec 9.10).
  function initDelivery() {
    const container = document.getElementById("lumia-delivery-status");
    if (!container || typeof lumiaAdmin === "undefined") return;

    const retestBtn = document.getElementById("lumia-delivery-retest");
    const i18n = (lumiaAdmin.i18n && lumiaAdmin.i18n.delivery) || {};
    let browserChecked = false;

    function post(action, fields) {
      const formData = new FormData();
      formData.append("action", action);
      formData.append("nonce", lumiaAdmin.nonce);
      Object.keys(fields || {}).forEach(function (key) {
        formData.append(key, fields[key]);
      });
      return fetch(lumiaAdmin.ajaxUrl, {
        method: "POST",
        credentials: "same-origin",
        body: formData,
      }).then(function (response) {
        return response.json();
      });
    }

    // Replaces the status block with the one the server rendered. False on an error answer.
    function render(data) {
      if (data && data.success && data.data && typeof data.data.html === "string") {
        container.innerHTML = data.data.html;
        return true;
      }
      return false;
    }

    function probe(url, accept) {
      // cache: "default" on purpose: a CDN or proxy between the browser and the server must
      // answer as it does for visitors (no-store would go around the browser cache only, but
      // also send no-cache upstream).
      return fetch(url, { headers: { Accept: accept }, cache: "default", credentials: "omit" }).then(
        function (response) {
          const type = (response.headers.get("content-type") || "").split(";")[0].trim().toLowerCase();
          return { status: response.status, type: type };
        }
      );
    }

    // The server tests itself through a loopback request that may go around a CDN; the
    // browser goes through it like a visitor. Only run when the server-side test passed:
    // the browser can then only veto.
    function browserCheck() {
      const box = container.querySelector(".lumia-delivery");
      if (!box || box.getAttribute("data-server-ok") !== "1") return;

      const url = box.getAttribute("data-probe-url");
      const cell = container.querySelector("[data-lumia-delivery-browser]");
      if (cell) cell.textContent = i18n.checking || "";

      let avif = null;
      probe(url, "image/avif,*/*")
        .then(function (result) {
          avif = result;
          return probe(url, "*/*");
        })
        .then(function (plain) {
          return post("lumia_image_optimizer_delivery_browser", {
            avif_status: avif.status,
            avif_type: avif.type,
            plain_status: plain.status,
            plain_type: plain.type,
          });
        })
        .then(function (data) {
          if (!render(data) && cell) cell.textContent = i18n.unverified || "";
        })
        .catch(function () {
          if (cell) cell.textContent = i18n.unverified || "";
        });
    }

    function browserCheckOnce() {
      if (browserChecked) return;
      browserChecked = true;
      browserCheck();
    }

    document.addEventListener("lumia:tab", function (event) {
      const detail = event.detail || {};
      if (detail.group === "image_optimizer" && detail.name === "delivery") browserCheckOnce();
    });
    const panel = container.closest("[data-lumia-tab-panel]");
    if (panel && !panel.hidden) browserCheckOnce();

    if (retestBtn) {
      retestBtn.addEventListener("click", function () {
        const label = retestBtn.innerHTML;
        retestBtn.disabled = true;
        retestBtn.textContent = i18n.retesting || "";

        post("lumia_image_optimizer_delivery_retest", {})
          .then(function (data) {
            if (!render(data)) {
              toast((data && data.data) || lumiaAdmin.i18n.networkError, "error");
              return;
            }
            browserCheck();
          })
          .catch(function (err) {
            toast(err.message || lumiaAdmin.i18n.networkError, "error");
          })
          .finally(function () {
            retestBtn.disabled = false;
            retestBtn.innerHTML = label;
          });
      });
    }

    // "Copy the rule" (the block is re-rendered: delegation).
    container.addEventListener("click", function (event) {
      const button = event.target.closest("[data-lumia-copy]");
      if (!button) return;
      const source = container.querySelector(button.getAttribute("data-lumia-copy"));
      if (!source) return;
      const text = source.textContent;

      function fallback() {
        const range = document.createRange();
        range.selectNodeContents(source);
        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
        try {
          document.execCommand("copy");
          toast(i18n.copied, "success");
        } catch (e) {
          toast(i18n.copyFailed, "error");
        }
      }

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () {
          toast(i18n.copied, "success");
        }, fallback);
      } else {
        fallback();
      }
    });
  }

  // Greys out the role picker when SVG upload is disabled.
  function initSvgRolesToggle() {
    const master = document.querySelector("[data-svg-master]");
    const roles = document.getElementById("lumia-svg-roles");
    if (!master || !roles) return;

    const sync = function () {
      roles.classList.toggle("is-disabled", !master.checked);
    };
    master.addEventListener("change", sync);
    sync();
  }

  /* ================================================================
   * BULK TAB
   * The progress is the distribution of the statuses the server counts
   * (queue in the background): the screen only polls and displays it.
   * ================================================================ */

  function initBulkOptimization() {
    const root = document.getElementById("lumia-bulk");
    if (!root || typeof lumiaAdmin === "undefined") return;

    const i18n = lumiaAdmin.i18n || {};
    const scanBtn = document.getElementById("lumia-bulk-scan");
    const startBtn = document.getElementById("lumia-bulk-start");
    const stopBtn = document.getElementById("lumia-bulk-stop");
    const barBox = document.getElementById("lumia-bulk-progress");
    const barEl = barBox ? barBox.querySelector(".lumia-progress__bar") : null;
    const messageEl = document.getElementById("lumia-bulk-message");
    const unservedEl = root.querySelector("[data-lumia-bulk-unserved]");
    const untouchedEl = root.querySelector("[data-lumia-bulk-untouched] .lumia-bulk__stat-value");
    const POLL_MS = 3000;
    const POLL_ERROR_MS = 10000;

    const startLabel = startBtn ? startBtn.querySelector(".lumia-btn__label") : null;
    const scanLabel = scanBtn ? scanBtn.querySelector(".lumia-btn__label") : null;
    const startText = startLabel ? startLabel.textContent : "";
    const scanText = scanLabel ? scanLabel.textContent : "";

    let current = lumiaAdmin.bulk || { counts: {}, total: 0, handled: 0, active: false, serving: true };
    let busy = false;
    let timer = null;
    let failedOnce = false;

    function format(value) {
      return Number(value || 0).toLocaleString();
    }

    function post(action) {
      const formData = new FormData();
      formData.append("action", "lumia_image_optimizer_" + action);
      formData.append("nonce", lumiaAdmin.nonce);

      return fetch(lumiaAdmin.ajaxUrl, {
        method: "POST",
        credentials: "same-origin",
        body: formData,
      }).then(function (response) {
        return response.json();
      });
    }

    // Replaces the figures with the ones the server counted.
    function render(snapshot) {
      current = snapshot;
      const counts = snapshot.counts || {};

      root.querySelectorAll("[data-lumia-bulk-status]").forEach(function (tile) {
        const status = tile.getAttribute("data-lumia-bulk-status");
        const value = tile.querySelector(".lumia-bulk__stat-value");
        const n = counts[status] || 0;
        if (value) {
          value.textContent = format(n);
          value.classList.toggle("is-nonzero", n > 0);
        }
      });

      if (untouchedEl && typeof snapshot.untouched === "number") {
        untouchedEl.textContent = format(snapshot.untouched);
      }

      const percent = snapshot.total > 0 ? Math.floor((100 * snapshot.handled) / snapshot.total) : 0;
      if (barEl) barEl.style.width = percent + "%";
      if (barBox) barBox.setAttribute("aria-valuenow", String(percent));
      if (messageEl) {
        messageEl.textContent =
          snapshot.total > 0
            ? (i18n.bulkProgress || "")
                .replace("%1$s", format(snapshot.handled))
                .replace("%2$s", format(snapshot.total))
            : i18n.bulkEmpty || "";
      }

      if (unservedEl) unservedEl.hidden = !!snapshot.serving;
      refreshButtons();
    }

    function refreshButtons() {
      if (startBtn) {
        startBtn.disabled = busy || !current.serving || !!current.active;
        if (startLabel) startLabel.textContent = current.active ? i18n.bulkRunning : startText;
      }
      if (stopBtn) stopBtn.disabled = busy || !((current.counts || {}).pending > 0);
      if (scanBtn) scanBtn.disabled = busy;
    }

    function withBusy(button, label, text, task) {
      busy = true;
      if (label && text) label.textContent = text;
      refreshButtons();

      return task()
        .catch(function (err) {
          toast((err && err.message) || i18n.networkError, "error");
        })
        .finally(function () {
          busy = false;
          if (label && button === scanBtn) label.textContent = scanText;
          refreshButtons();
          schedule();
        });
    }

    // Calls an action and shows what the server answers; false on an error answer.
    function run(action) {
      return post(action).then(function (data) {
        if (!data || !data.success) {
          toast((data && data.data) || i18n.error, "error");
          return null;
        }
        render(data.data);
        return data.data;
      });
    }

    function poll() {
      timer = null;
      if (!current.active) return;
      if (document.hidden || busy) {
        schedule();
        return;
      }

      const wasActive = current.active;
      post("bulk_status")
        .then(function (data) {
          if (!data || !data.success) throw new Error((data && data.data) || i18n.networkError);
          failedOnce = false;
          render(data.data);
          if (wasActive && !data.data.active) toast(i18n.bulkComplete, "success");
          schedule();
        })
        .catch(function (err) {
          if (!failedOnce) toast((err && err.message) || i18n.networkError, "error");
          failedOnce = true;
          schedule(POLL_ERROR_MS);
        });
    }

    // Polls only while items are waiting or being processed.
    function schedule(delay) {
      if (timer || !current.active) return;
      timer = setTimeout(poll, delay || POLL_MS);
    }

    if (scanBtn) {
      scanBtn.addEventListener("click", function () {
        withBusy(scanBtn, scanLabel, i18n.bulkScanning, function () {
          return run("bulk_scan").then(function (result) {
            if (result && result.requeued > 0) {
              toast((i18n.bulkRequeued || "").replace("%s", format(result.requeued)), "info");
            }
          });
        });
      });
    }

    if (startBtn) {
      startBtn.addEventListener("click", function () {
        withBusy(startBtn, startLabel, i18n.bulkRunning, function () {
          return run("bulk").then(function (result) {
            if (!result) return;
            toast(
              result.queued > 0 ? (i18n.bulkQueued || "").replace("%s", format(result.queued)) : i18n.bulkNothing,
              result.queued > 0 ? "success" : "info"
            );
          });
        });
      });
    }

    if (stopBtn) {
      stopBtn.addEventListener("click", function () {
        withBusy(stopBtn, null, null, function () {
          return run("bulk_stop").then(function (result) {
            if (result) toast((i18n.bulkStopped || "").replace("%s", format(result.removed)), "info");
          });
        });
      });
    }

    refreshButtons();
    // A run started before this page was loaded: follow it (the status call also restarts a
    // queue that nobody is working on).
    schedule(500);
  }

  /* ================================================================
   * MEDIA DETAILS PANEL
   * Each action returns the server-rendered panel: we replace it
   * as is instead of recomputing sizes and buttons in JS.
   * ================================================================ */

  function initMediaActions() {
    if (typeof lumiaAdmin === "undefined") return;

    document.addEventListener("click", function (event) {
      var button = event.target.closest("[data-lumia-io-action]");
      if (!button) return;

      var panel = button.closest(".lumia-media-optimizer");
      if (!panel) return;

      var action = button.getAttribute("data-lumia-io-action");
      if (action === "copy-url") {
        copyText(button.getAttribute("data-url") || "", panel).then(
          function () {
            toast(lumiaAdmin.i18n.mediaCopied, "success");
          },
          function () {
            toast(lumiaAdmin.i18n.mediaCopyFail, "error");
          }
        );
      } else if (action === "regenerate") {
        runAction(panel, button, "regenerate", {});
      }
    });

    // Capture phase: the media modal's Backbone view listens to `change` on its fields to
    // save them (save-attachment-compat); this switch is not one of them.
    document.addEventListener(
      "change",
      function (event) {
        var toggle = event.target.closest && event.target.closest("[data-lumia-io-toggle]");
        if (!toggle) return;

        var panel = toggle.closest(".lumia-media-optimizer");
        if (!panel) return;

        event.stopPropagation();
        runAction(panel, toggle, "toggle_original", { enabled: toggle.checked ? "1" : "0" });
      },
      true
    );
  }

  function runAction(panel, control, action, extra) {
    var attachmentId = panel.getAttribute("data-attachment");

    // Only the controls that were enabled come back enabled (a name-excluded image keeps
    // its switch disabled).
    var disabled = [];
    panel.querySelectorAll("[data-lumia-io-action], [data-lumia-io-toggle]").forEach(function (el) {
      if (!el.disabled) {
        el.disabled = true;
        disabled.push(el);
      }
    });
    panel.querySelectorAll("a.lumia-btn").forEach(function (link) {
      link.setAttribute("aria-disabled", "true");
    });

    var isButton = control.tagName === "BUTTON";
    var original = isButton ? control.innerHTML : "";
    if (isButton) control.textContent = lumiaAdmin.i18n.mediaRunning;

    var formData = new FormData();
    formData.append("action", "lumia_image_optimizer_media_" + action);
    formData.append("nonce", lumiaAdmin.nonce);
    formData.append("attachment_id", attachmentId);
    Object.keys(extra || {}).forEach(function (key) {
      formData.append(key, extra[key]);
    });

    fetch(lumiaAdmin.ajaxUrl, {
      method: "POST",
      credentials: "same-origin",
      body: formData,
    })
      .then(function (response) {
        return response.json();
      })
      .then(function (data) {
        if (!data.success) {
          throw new Error(data.data || lumiaAdmin.i18n.mediaError);
        }

        panel.outerHTML = data.data.html;
        toast(data.data.message, data.data.type);

        // Media library grid: the Backbone model keeps the old panel
        // until it is reloaded.
        if (window.wp && wp.media && typeof wp.media.attachment === "function") {
          wp.media.attachment(attachmentId).fetch();
        }
      })
      .catch(function (err) {
        disabled.forEach(function (el) {
          el.disabled = false;
        });
        panel.querySelectorAll("a.lumia-btn").forEach(function (link) {
          link.removeAttribute("aria-disabled");
        });
        if (isButton) control.innerHTML = original;
        if (control.type === "checkbox") control.checked = !control.checked;
        toast(err.message || lumiaAdmin.i18n.mediaError, "error");
      });
  }

  // Copies a text: Clipboard API where the page is secure, a temporary field otherwise
  // (kept inside the panel so that the media modal's focus trap does not reject it).
  function copyText(text, container) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text).catch(function () {
        return legacyCopy(text, container);
      });
    }
    return legacyCopy(text, container);
  }

  function legacyCopy(text, container) {
    return new Promise(function (resolve, reject) {
      var field = document.createElement("textarea");
      field.value = text;
      field.setAttribute("readonly", "");
      field.style.position = "absolute";
      field.style.left = "-9999px";
      container.appendChild(field);
      field.select();

      var ok = false;
      try {
        ok = document.execCommand("copy");
      } catch (e) {
        ok = false;
      }
      container.removeChild(field);

      if (ok) resolve();
      else reject(new Error("copy"));
    });
  }

  // The toast container only exists on the plugin pages.
  function toast(message, type) {
    if (typeof window.lumiaShowToast !== "function") return;
    if (!document.getElementById("lumia-toast-container")) {
      var container = document.createElement("div");
      container.id = "lumia-toast-container";
      container.className = "lumia-toast-container";
      container.setAttribute("role", "region");
      container.setAttribute("aria-live", "polite");
      document.body.appendChild(container);
    }
    window.lumiaShowToast(message, type || "success");
  }
})();
