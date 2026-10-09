/**
 * Lümia Tools - Admin JavaScript
 * Vanilla JS uniquement. Chargé uniquement sur les pages LUMIA.
 * La logique toast/notifications est dans notifications.js (chargé global).
 */
(function () {
  "use strict";

  var isDirty = false;

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
   * VALIDATION FORMULAIRES
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
   * MODAL RÉUTILISABLE
   * Usage : window.lumiaModal.open({ title, message, confirmLabel,
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
    modalConfirmBtn.textContent = options.confirmLabel || "Confirmer";
    modalCancelBtn.textContent  = options.cancelLabel  || "Annuler";

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
   * DÉCLENCHEURS [data-modal-confirm]
   * Boutons qui déclenchent la modal avant de soumettre un form.
   * ================================================================ */

  function initModalTriggers() {
    document.addEventListener("click", function (e) {
      var btn = e.target.closest("[data-modal-confirm]");
      if (!btn) return;
      e.preventDefault();

      var formId = btn.getAttribute("data-modal-form");
      var form   = formId ? document.getElementById(formId) : null;

      openModal({
        title:        btn.getAttribute("data-modal-title")   || "Confirmer",
        message:      btn.getAttribute("data-modal-message") || "",
        confirmLabel: btn.getAttribute("data-modal-confirm-label") || "Confirmer",
        danger:       btn.hasAttribute("data-modal-danger") || btn.classList.contains("lumia-btn--danger"),
        onConfirm: function () {
          if (form) form.submit();
        },
      });
    });
  }

  /* ================================================================
   * AVERTISSEMENT MODIFICATIONS NON SAUVEGARDÉES
   * ================================================================ */

  function initUnsavedWarning() {
    var forms   = document.querySelectorAll(
      ".lumia-form, #lumia-save-settings-form, #lumia-module-form",
    );

    // Seul un champ nommé part à l'enregistrement. Recherche, filtres d'une
    // liste ou champ de test (journaux, SMTP) n'ont pas de name : les
    // manipuler ne laisse rien de non sauvegardé.
    function markDirty(e) {
      if (e.target && e.target.name) isDirty = true;
    }

    forms.forEach(function (form) {
      form.addEventListener("input", markDirty, { passive: true });
      form.addEventListener("change", markDirty, { passive: true });
      // Soumettre le form reset l'état dirty
      form.addEventListener("submit", function () { isDirty = false; });
    });

    // Intercepte les liens de navigation interne (sidebar, menus WP, etc.)
    document.addEventListener("click", function (e) {
      if (!isDirty) return;
      var link = e.target.closest("a[href]");
      if (!link) return;

      var href = link.getAttribute("href");
      if (!href || href.startsWith("#")) return;

      // Ignorer les déconnexions
      if (href.indexOf("action=logout") !== -1) return;

      // Résoudre l'URL absolue pour comparer l'origine
      var resolved;
      try {
        resolved = new URL(href, window.location.href);
      } catch (_) {
        return;
      }
      // Ignorer les liens vers un autre domaine
      if (resolved.origin !== window.location.origin) return;

      e.preventDefault();
      var fullHref = resolved.href;
      openModal({
        title:        "Modifications non sauvegardées",
        message:      "Vous avez des modifications non enregistrées. Quitter sans sauvegarder ?",
        confirmLabel: "Quitter sans sauvegarder",
        cancelLabel:  "Rester sur la page",
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
   * AJAX TOGGLE MODULES
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

      // Feedback visuel immédiat
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
            // Revenir à l'état précédent
            checkbox.checked = !checkbox.checked;
            if (typeof window.lumiaShowToast === "function") {
              window.lumiaShowToast(
                (data.data && data.data.message) || "Erreur",
                "error",
              );
            }
            return;
          }

          var isActive = data.data.active;
          card.classList.toggle("lumia-module-card--active", isActive);

          // Mettre à jour / créer le bouton "Configurer"
          var actions = card.querySelector(".lumia-module-card__actions");
          if (actions) {
            var existingLink = actions.querySelector(".lumia-btn");
            if (isActive) {
              if (!existingLink) {
                var a    = document.createElement("a");
                a.href   = data.data.configure_url;
                a.className = "lumia-btn lumia-btn--sm lumia-btn--secondary";
                a.textContent = "Configurer";
                actions.appendChild(a);
              }
            } else {
              if (existingLink) existingLink.remove();
            }
          }

          if (typeof window.lumiaShowToast === "function") {
            window.lumiaShowToast(data.data.notice, "success");
          }

          // Recharger la page pour mettre à jour la navigation latérale
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
   * ONGLETS — [data-lumia-tabs] + [data-lumia-tab-panel]
   * Sous-onglets d'un écran, côté client : tous les panneaux restent
   * dans le DOM (et dans le formulaire, donc postés à l'enregistrement).
   * Le dernier onglet ouvert est rappelé par sessionStorage : après un
   * enregistrement, la redirection ramène sur l'écran, pas sur l'onglet.
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

        try { sessionStorage.setItem(key, name); } catch (e) { /* stockage indisponible */ }

        list.dispatchEvent(new CustomEvent("lumia:tab", { bubbles: true, detail: { group: group, name: name } }));
      }

      list.addEventListener("click", function (e) {
        var tab = e.target.closest("[data-lumia-tab]");
        if (tab && list.contains(tab)) activate(tab.dataset.lumiaTab, false);
      });

      // Flèches, Début, Fin : motif « tabs » de l'ARIA Authoring Practices.
      list.addEventListener("keydown", function (e) {
        var index = tabs.indexOf(document.activeElement);
        if (index < 0) return;
        var next = { ArrowRight: index + 1, ArrowLeft: index - 1, Home: 0, End: tabs.length - 1 }[e.key];
        if (next === undefined) return;
        e.preventDefault();
        activate(tabs[(next + tabs.length) % tabs.length].dataset.lumiaTab, true);
      });

      // Un champ invalide dans un panneau masqué : le navigateur refuse de
      // soumettre sans pouvoir montrer le champ. On ouvre son onglet.
      panels.forEach(function (panel) {
        panel.addEventListener("invalid", function () {
          if (panel.hidden) activate(panel.dataset.lumiaTabPanel, false);
        }, true);
      });

      var initial = null;
      try { initial = sessionStorage.getItem(key); } catch (e) { /* stockage indisponible */ }
      activate(initial || (list.querySelector("[data-lumia-tab].is-active") || tabs[0]).dataset.lumiaTab, false);

      list.lumiaActivate = activate;
    });
  }

  /** Ouvre un onglet par programme : window.lumiaTabs.activate('smtp', 'log'). */
  window.lumiaTabs = {
    activate: function (group, name) {
      var list = document.querySelector('[data-lumia-tabs="' + group + '"]');
      if (list && list.lumiaActivate) list.lumiaActivate(name, false);
    },
  };

  /* ================================================================
   * UTILITAIRES
   * ================================================================ */

  window.lumiaConfirm = function (message) {
    return confirm(
      message ||
        (window.lumiaAdmin && lumiaAdmin.i18n.confirmAction) ||
        "Êtes-vous sûr ?",
    );
  };

  /* ================================================================
   * MODALS NOMMÉES — lumiaModalOpen / lumiaModalClose
   * Pour les modals avec HTML persistant (form, etc.).
   * Complément à lumiaModal.open() qui est programmatique.
   * Usage : lumiaModalOpen('mon-modal-id')
   * ================================================================ */

  window.lumiaModalOpen = function (id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.classList.add("is-open");
    // Focaliser le premier champ texte si présent
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

  // Délégation globale : click hors du .lumia-modal ou sur .lumia-modal-close
  document.addEventListener("click", function (e) {
    // Clic sur l'overlay lui-même (hors de la boîte)
    if (
      e.target.classList.contains("lumia-modal-overlay") &&
      e.target.id !== "lumia-modal-overlay" // géré par initModal()
    ) {
      e.target.classList.remove("is-open");
      return;
    }
    // Bouton de fermeture explicite
    var closeBtn = e.target.closest && e.target.closest(".lumia-modal-close");
    if (closeBtn) {
      var overlay = closeBtn.closest(".lumia-modal-overlay");
      if (overlay && overlay.id !== "lumia-modal-overlay") {
        overlay.classList.remove("is-open");
      }
    }
  });

  // Échap ferme toutes les modals nommées ouvertes (sauf la principale)
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
   * Usage : <button data-lumia-tip="Texte"> — et, si besoin,
   * data-lumia-tip-placement="top|bottom|left|right" (défaut : top).
   * Aucune initialisation à faire : tout passe par délégation, donc le
   * markup rendu en JS après coup (arbre du créateur de menu, listes
   * rechargées en AJAX) est couvert sans y penser.
   *
   * Le positionnement est en `fixed` + translate3d : un tooltip enfant
   * d'une colonne en overflow:hidden serait rogné, et un tooltip en
   * position absolue devrait connaître les décalages de chacun de ses
   * parents. En contrepartie il faut suivre le défilement — d'où le
   * recalcul sur scroll/resize, throttlé en requestAnimationFrame.
   * ================================================================ */

  var tipEl      = null;
  var tipBox     = null;
  var tipArrow   = null;
  var tipText    = null;
  var tipRef     = null;   // élément actuellement décrit
  var tipShowT   = null;
  var tipHideT   = null;
  var tipRaf     = null;
  var tipVisible = false;

  var TIP_SHOW_DELAY = 140;
  var TIP_HIDE_DELAY = 60;
  var TIP_MARGIN     = 8;  // marge minimale avec le bord de la fenêtre
  var TIP_OFFSET     = 8;  // distance entre l'élément et la boîte

  function initTooltips() {
    // mouseover/mouseout (et non mouseenter/leave) : seuls les premiers
    // remontent, condition d'une délégation unique sur le document.
    document.addEventListener("mouseover", function (e) {
      var el = tipTarget(e.target);
      if (el) tipScheduleShow(el);
    });

    document.addEventListener("mouseout", function (e) {
      var el = tipTarget(e.target);
      if (!el || el !== tipRef) return;
      // Passage sur un enfant de la même cible : ce n'est pas une sortie.
      if (e.relatedTarget && el.contains(e.relatedTarget)) return;
      tipScheduleHide();
    });

    // Clavier : même déclencheur au focus, sinon le tooltip n'existe pas
    // pour qui navigue au Tab — c'est justement ce que `title` fait mal.
    document.addEventListener("focusin", function (e) {
      var el = tipTarget(e.target);
      if (el) tipShow(el);
    });
    document.addEventListener("focusout", function (e) {
      if (tipRef && tipTarget(e.target) === tipRef) tipHide();
    });

    // Un clic ouvre en général un panneau ou une modale : garder le
    // tooltip par-dessus n'a aucun intérêt.
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
    // Un tooltip déjà ouvert : on enchaîne sans délai, comme un menu dont
    // les entrées se survolent (réattendre 140 ms donne une UI molle).
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

    // `title` ferait doublon avec notre boîte : on le retire, en le
    // gardant de côté pour pouvoir le rendre si besoin.
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

    // L'élément a pu disparaître (re-rendu d'une liste) ou sortir de
    // l'écran en défilant dans sa colonne : plus rien à décrire.
    if (!document.contains(tipRef)) { tipHide(); return; }
    var r = tipRef.getBoundingClientRect();
    if (!r.width && !r.height) { tipHide(); return; }
    if (r.bottom < 0 || r.right < 0 ||
        r.top > window.innerHeight || r.left > window.innerWidth) { tipHide(); return; }

    tipBox.style.maxWidth = Math.min(260, window.innerWidth - TIP_MARGIN * 2) + "px";
    var w = tipEl.offsetWidth;
    var h = tipEl.offsetHeight;
    var p = tipPlacementOf(tipRef);

    // Bascule sur le côté opposé quand la place manque — et seulement si
    // l'opposé en offre davantage, pour ne pas osciller.
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

    // Recadrage dans la fenêtre : la boîte glisse, la flèche reste sur
    // l'élément (sinon elle pointe à côté dans les coins).
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
   * API publique — utile quand le DOM bouge sous le tooltip (ligne
   * supprimée, panneau replié) ou pour poser un texte à la volée.
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
