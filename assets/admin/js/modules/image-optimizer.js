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

  function initBulkOptimization() {
    const startBtn = document.getElementById("lumia-bulk-start");
    if (!startBtn || typeof lumiaAdmin === "undefined") return;

    const scanBtn = document.getElementById("lumia-bulk-scan");
    const introEl = document.getElementById("lumia-bulk-scan-intro");
    const resultEl = document.getElementById("lumia-bulk-result");
    const potentialTile = document.getElementById("lumia-bulk-potential-tile");
    const progressEl = document.querySelector(".lumia-bulk-status__progress");
    const messageEl = document.querySelector(".lumia-bulk-status__message");
    const barEl = document.querySelector(".lumia-progress__bar");
    const remainingEl = document.getElementById("lumia-bulk-remaining");
    const potentialEl = document.getElementById("lumia-bulk-potential");

    var POLL_MIN = 2000;
    var POLL_MAX = 5000;
    var pollInterval = POLL_MIN;
    var isRunning = false;

    // Switches from the "scan" prompt to the result block (stats + button).
    function revealResult() {
      if (introEl) introEl.style.display = "none";
      if (resultEl) resultEl.style.display = "flex";
    }

    // On-demand scan: counts the images and estimates the savings.
    if (scanBtn) {
      scanBtn.addEventListener("click", function () {
        scanBtn.disabled = true;
        var originalLabel = scanBtn.textContent;
        scanBtn.textContent = lumiaAdmin.i18n.bulkScanning;

        const formData = new FormData();
        formData.append("action", "lumia_image_optimizer_bulk_scan");
        formData.append("nonce", lumiaAdmin.nonce);

        fetch(lumiaAdmin.ajaxUrl, {
          method: "POST",
          credentials: "same-origin",
          body: formData,
        })
          .then(function (response) {
            return response.json();
          })
          .then(function (data) {
            scanBtn.disabled = false;
            scanBtn.textContent = originalLabel;

            if (!data.success) {
              if (typeof window.lumiaShowToast === "function") {
                window.lumiaShowToast(data.data || lumiaAdmin.i18n.error, "error");
              }
              return;
            }

            const result = data.data || {};
            const remaining = parseInt(result.remaining, 10) || 0;
            const estimated = parseInt(result.estimated_bytes_saved, 10) || 0;

            if (remainingEl) remainingEl.textContent = remaining;

            // The estimate is only shown when a history makes it credible.
            if (potentialTile) {
              if (estimated > 0) {
                if (potentialEl) potentialEl.textContent = formatBytes(estimated);
                potentialTile.style.display = "";
              } else {
                potentialTile.style.display = "none";
              }
            }

            startBtn.disabled = remaining === 0;
            revealResult();
          })
          .catch(function (err) {
            scanBtn.disabled = false;
            scanBtn.textContent = originalLabel;
            if (typeof window.lumiaShowToast === "function") {
              window.lumiaShowToast(err.message || lumiaAdmin.i18n.networkError, "error");
            }
          });
      });
    }

    startBtn.addEventListener("click", function () {
      if (isRunning) return;
      isRunning = true;
      pollInterval = POLL_MIN;

      startBtn.disabled = true;
      startBtn.textContent = lumiaAdmin.i18n.bulkRunning;

      if (progressEl) {
        progressEl.style.display = "flex";
      }

      startBulk();
    });

    // Resync: if a bulk run is already going on server-side (started before a
    // reload/page close), we resume the display and the polling
    // instead of leaving the page looking idle.
    var initialState = lumiaAdmin.bulkState;
    if (initialState && initialState.running) {
      isRunning = true;
      pollInterval = POLL_MIN;

      revealResult();

      startBtn.disabled = true;
      startBtn.textContent = lumiaAdmin.i18n.bulkRunning;

      if (progressEl) {
        progressEl.style.display = "flex";
      }

      updateStatus({
        processed: initialState.processed,
        remaining: initialState.remaining,
        total: initialState.total,
      });

      pollStatus();
    }

    function startBulk() {
      const formData = new FormData();
      formData.append("action", "lumia_image_optimizer_bulk");
      formData.append("nonce", lumiaAdmin.nonce);

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
            showError(data.data || lumiaAdmin.i18n.error);
            return;
          }

          const result = data.data;
          updateStatus(result);

          if (result.done && !result.running) {
            finishBulk();
            return;
          }

          pollStatus();
        })
        .catch(function (err) {
          showError(err.message || lumiaAdmin.i18n.networkError);
        });
    }

    function pollStatus() {
      const formData = new FormData();
      formData.append("action", "lumia_image_optimizer_bulk_status");
      formData.append("nonce", lumiaAdmin.nonce);

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
            showError(data.data || lumiaAdmin.i18n.error);
            return;
          }

          const result = data.data;
          updateStatus(result);

          if (result.done && !result.running) {
            finishBulk();
            return;
          }

          setTimeout(pollStatus, pollInterval);
          pollInterval = Math.min(pollInterval + 500, POLL_MAX);
        })
        .catch(function (err) {
          showError(err.message || lumiaAdmin.i18n.networkError);
        });
    }

    function updateStatus(result) {
      if (remainingEl) {
        remainingEl.textContent = result.remaining;
      }
      if (potentialEl && typeof result.estimated_bytes_saved === "number") {
        potentialEl.textContent = formatBytes(result.estimated_bytes_saved);
      }

      if (messageEl) {
        messageEl.textContent =
          lumiaAdmin.i18n.bulkProcessed +
          " " +
          result.processed +
          " — " +
          lumiaAdmin.i18n.bulkRemaining +
          " " +
          result.remaining;
      }

      if (barEl) {
        const total = result.total || result.processed + result.remaining || 1;
        const percent = Math.round(((total - result.remaining) / total) * 100);
        barEl.style.width = percent + "%";
      }
    }

    function finishBulk() {
      isRunning = false;
      startBtn.disabled = false;
      startBtn.textContent = lumiaAdmin.i18n.bulkDone;

      var completeMsg = lumiaAdmin.i18n.bulkComplete;

      if (messageEl) {
        messageEl.textContent = completeMsg;
      }

      if (barEl) {
        barEl.style.width = "100%";
      }

      if (typeof window.lumiaShowToast === "function") {
        window.lumiaShowToast(completeMsg, "success");
      }
    }

    function showError(msg) {
      isRunning = false;
      startBtn.disabled = false;
      startBtn.textContent = lumiaAdmin.i18n.bulkRetry;

      if (messageEl) {
        messageEl.textContent = msg;
        messageEl.style.color = "var(--lumia-danger)";
      }
    }
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

  function formatBytes(bytes) {
    if (!bytes || bytes <= 0) return "0 B";
    const units = ["B", "KB", "MB", "GB"];
    let index = 0;
    let value = bytes;
    while (value >= 1024 && index < units.length - 1) {
      value /= 1024;
      index++;
    }
    return value.toFixed(2) + " " + units[index];
  }
})();
