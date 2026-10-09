/**
 * Lümia Tools - Image Optimizer admin JS
 * Script dédié au module Image Optimizer.
 */
(function () {
  "use strict";

  document.addEventListener("DOMContentLoaded", function () {
    initBulkOptimization();
    initMediaActions();
    initSvgRolesToggle();
  });

  // Grise le sélecteur de rôles quand l'upload SVG est désactivé.
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

    // Bascule de l'invite « scanner » vers le bloc résultat (stats + bouton).
    function revealResult() {
      if (introEl) introEl.style.display = "none";
      if (resultEl) resultEl.style.display = "flex";
    }

    // Scan à la demande : compte les images et estime les gains.
    if (scanBtn) {
      scanBtn.addEventListener("click", function () {
        scanBtn.disabled = true;
        var originalLabel = scanBtn.textContent;
        scanBtn.textContent = lumiaAdmin.i18n.bulkScanning || "Analyse…";

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
                window.lumiaShowToast(data.data || "Erreur", "error");
              }
              return;
            }

            const result = data.data || {};
            const remaining = parseInt(result.remaining, 10) || 0;
            const estimated = parseInt(result.estimated_bytes_saved, 10) || 0;

            if (remainingEl) remainingEl.textContent = remaining;

            // On n'affiche l'estimation que si un historique la rend crédible.
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
              window.lumiaShowToast(err.message || "Erreur réseau", "error");
            }
          });
      });
    }

    startBtn.addEventListener("click", function () {
      if (isRunning) return;
      isRunning = true;
      pollInterval = POLL_MIN;

      startBtn.disabled = true;
      startBtn.textContent =
        lumiaAdmin.i18n.bulkRunning || "Optimisation en cours…";

      if (progressEl) {
        progressEl.style.display = "flex";
      }

      startBulk();
    });

    // Resync : si un bulk tourne déjà côté serveur (lancé avant un
    // rechargement/une fermeture de page), on reprend l'affichage et le
    // polling au lieu de laisser la page paraître inactive.
    var initialState = lumiaAdmin.bulkState;
    if (initialState && initialState.running) {
      isRunning = true;
      pollInterval = POLL_MIN;

      revealResult();

      startBtn.disabled = true;
      startBtn.textContent =
        lumiaAdmin.i18n.bulkRunning || "Optimisation en cours…";

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
            showError(data.data || "Erreur");
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
          showError(err.message || "Erreur réseau");
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
            showError(data.data || "Erreur");
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
          showError(err.message || "Erreur réseau");
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
          (lumiaAdmin.i18n.bulkProcessed || "Traité : ") +
          result.processed +
          " — " +
          (lumiaAdmin.i18n.bulkRemaining || "Restant : ") +
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
      startBtn.textContent = lumiaAdmin.i18n.bulkDone || "Optimisation terminée";

      var completeMsg =
        lumiaAdmin.i18n.bulkComplete || "Toutes les images ont été optimisées.";

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
      startBtn.textContent = lumiaAdmin.i18n.bulkRetry || "Réessayer";

      if (messageEl) {
        messageEl.textContent = msg;
        messageEl.style.color = "var(--lumia-danger)";
      }
    }
  }

  /* ================================================================
   * PANNEAU DE LA FICHE MÉDIA
   * Chaque action renvoie le panneau rendu côté serveur : on le remplace
   * tel quel au lieu de recalculer tailles et boutons en JS.
   * ================================================================ */

  var MODAL_ID = "lumia-io-modal";
  var FORMAT_LABELS = { webp: "WebP", avif: "AVIF" };

  function initMediaActions() {
    document.addEventListener("click", function (event) {
      var button = event.target.closest("[data-lumia-io-action]");
      if (!button || typeof lumiaAdmin === "undefined") return;

      var panel = button.closest(".lumia-media-optimizer");
      if (!panel) return;

      var action = button.getAttribute("data-lumia-io-action");
      var i18n = lumiaAdmin.i18n || {};
      var run = function (extra) {
        runAction(panel, button, action, extra);
      };

      if (action === "reoptimize") {
        var hasBackup = button.getAttribute("data-has-backup") === "1";
        confirmAction({
          title: i18n.reoptimize.title,
          message: hasBackup ? i18n.reoptimize.backup : i18n.reoptimize.nobackup,
          confirm: i18n.reoptimize.confirm,
          onConfirm: run,
        });
      } else if (action === "convert") {
        confirmAction({
          title: i18n.convert.title,
          message: i18n.convert.message,
          confirm: i18n.convert.confirm,
          formats: (button.getAttribute("data-formats") || "").split(",").filter(Boolean),
          onConfirm: run,
        });
      } else if (action === "restore") {
        confirmAction({
          title: i18n.restore.title,
          message: i18n.restore.message,
          confirm: i18n.restore.confirm,
          danger: true,
          onConfirm: run,
        });
      } else {
        run({});
      }
    });
  }

  function runAction(panel, button, action, extra) {
    var attachmentId = panel.getAttribute("data-attachment");
    var buttons = panel.querySelectorAll("[data-lumia-io-action]");
    buttons.forEach(function (b) {
      b.disabled = true;
    });
    var label = button.textContent;
    button.textContent = lumiaAdmin.i18n.mediaRunning || "…";

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

        // Grille de la médiathèque : le modèle Backbone garde l'ancienne URL
        // (et l'ancien panneau) tant qu'on ne le recharge pas.
        if (window.wp && wp.media && typeof wp.media.attachment === "function") {
          wp.media.attachment(attachmentId).fetch();
        }
      })
      .catch(function (err) {
        buttons.forEach(function (b) {
          b.disabled = false;
        });
        button.textContent = label;
        toast(err.message || lumiaAdmin.i18n.mediaError, "error");
      });
  }

  // Modale nommée du design system, créée une fois dans <body> : les écrans
  // de médias n'ont pas le singleton #lumia-modal-overlay des pages du plugin.
  function confirmAction(options) {
    var modal = document.getElementById(MODAL_ID);
    if (!modal) {
      modal = document.createElement("div");
      modal.id = MODAL_ID;
      modal.className = "lumia-modal-overlay";
      modal.setAttribute("role", "dialog");
      modal.setAttribute("aria-modal", "true");
      modal.setAttribute("aria-labelledby", MODAL_ID + "-title");
      modal.innerHTML =
        '<div class="lumia-modal">' +
        '<div class="lumia-modal__header"><h3 id="' + MODAL_ID + '-title" class="lumia-modal__title"></h3></div>' +
        '<div class="lumia-modal__body">' +
        '<p class="lumia-io-modal__message"></p>' +
        '<div class="lumia-form__group lumia-io-modal__format">' +
        '<label class="lumia-form__label" for="' + MODAL_ID + '-format"></label>' +
        '<select class="lumia-select" id="' + MODAL_ID + '-format"></select>' +
        "</div></div>" +
        '<div class="lumia-modal__footer">' +
        '<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary lumia-modal-close"></button>' +
        '<button type="button" class="lumia-btn lumia-btn--sm lumia-io-modal__confirm"></button>' +
        "</div></div>";
      document.body.appendChild(modal);

      modal.querySelector(".lumia-modal-close").textContent = lumiaAdmin.i18n.cancel;
      modal.querySelector(".lumia-form__label").textContent = lumiaAdmin.i18n.format;
      modal.querySelector(".lumia-io-modal__confirm").addEventListener("click", function () {
        window.lumiaModalClose(MODAL_ID);
        if (typeof modal.onConfirm === "function") modal.onConfirm();
      });
    }

    var formats = options.formats || [];
    var select = modal.querySelector("select");
    select.innerHTML = "";
    formats.forEach(function (format) {
      var option = document.createElement("option");
      option.value = format;
      option.textContent = FORMAT_LABELS[format] || format;
      select.appendChild(option);
    });
    modal.querySelector(".lumia-io-modal__format").style.display = formats.length ? "" : "none";

    modal.querySelector(".lumia-modal__title").textContent = options.title;
    modal.querySelector(".lumia-io-modal__message").textContent = options.message;

    var confirmBtn = modal.querySelector(".lumia-io-modal__confirm");
    confirmBtn.textContent = options.confirm;
    confirmBtn.className =
      "lumia-btn lumia-btn--sm lumia-io-modal__confirm " +
      (options.danger ? "lumia-btn--danger" : "lumia-btn--primary");

    modal.onConfirm = function () {
      options.onConfirm(formats.length ? { format: select.value } : {});
    };

    window.lumiaModalOpen(MODAL_ID);
    confirmBtn.focus();
  }

  // Le conteneur de toasts n'existe que sur les pages du plugin.
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
