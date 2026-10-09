/**
 * Lümia Tools — Module Créateur de menu
 * Éditeur intégré 3 colonnes.
 */
(function () {
  "use strict";

  if (typeof lumiaAdmin === "undefined") return;

  var L = window.lumiaLucide || {};

  /* ================================================================
   * ÉTAT
   * ================================================================ */

  var ed = {
    profile:     null,
    dirty:       false,
    selectedUid: null,
  };

  var expandedUids = new Set();

  // Dropdown flottant pour le picker d'icône (singleton, body)
  var iconPickerEl   = null;
  var iconPickerItem = null;

  var ms = { include: null, exclude: null, itemRoles: null };
  var sidebarState = { filter: "all", search: "" };

  /* ================================================================
   * INIT
   * ================================================================ */

  document.addEventListener("DOMContentLoaded", function () {
    if (!document.getElementById("lumia-mc-editor")) return;

    // Masquer le bouton Enregistrer du header module (remplacé par le footer du panel)
    var headerSaveBtn = document.getElementById("lumia-module-save-btn");
    if (headerSaveBtn) {
      headerSaveBtn.style.display = "none";
    }

    // Nouveau menu
    var newBtn = document.getElementById("lumia-mc-new-btn");
    if (newBtn) {
      newBtn.addEventListener("click", function () { confirmDirty(startNewProfile); });
    }

    // Import / export global des menus (en-tête de la colonne de gauche)
    bindProfilesFooter();

    // Bouton retour dans le panel droit
    var backBtn = document.getElementById("lumia-mc-back-btn");
    if (backBtn) {
      backBtn.addEventListener("click", function () { showProfilePanel(); });
    }

    // Boutons du pied de page
    bindPanelFooter();

    // Ctrl/Cmd+S, Ctrl+Z / Ctrl+Y
    bindShortcuts();

    // Boutons +séparateur / +lien
    bindTreeActions();

    // Dropdown flottant icon picker (singleton, appendé au body)
    createFloatingIconPicker();

    // Fermer le dropdown si clic en dehors — capture phase pour résister aux WP stopPropagation
    document.addEventListener("mousedown", function (e) {
      if (iconPickerEl &&
          !iconPickerEl.classList.contains("is-hidden") &&
          !iconPickerEl.contains(e.target) &&
          !e.target.closest(".lumia-wl-icon-btn")) {
        hideIconPicker();
      }
    }, true);

    // Fermer le dropdown avec Échap
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && iconPickerEl && !iconPickerEl.classList.contains("is-hidden")) {
        hideIconPicker();
      }
    });

    // Fermer les multi-selects rôles/utilisateurs (inclure/exclure) au clic
    // en dehors — capture phase pour résister aux stopPropagation de WP.
    document.addEventListener("mousedown", function (e) {
      ["include", "exclude", "itemRoles"].forEach(function (key) {
        var w = ms[key];
        if (w && w.open && w.container && !w.container.contains(e.target)) {
          w.close();
        }
      });
    }, true);

    // …et avec Échap.
    document.addEventListener("keydown", function (e) {
      if (e.key !== "Escape") return;
      ["include", "exclude", "itemRoles"].forEach(function (key) {
        var w = ms[key];
        if (w && w.open) w.close();
      });
    });

    renderProfilesSidebar();
    bindProfilesSidebar();
    showPlaceholder();

    try {
      var lastOpenId = sessionStorage.getItem("lumia_mc_open_profile");
      if (lastOpenId) {
        sessionStorage.removeItem("lumia_mc_open_profile");
        var profileToRestore = findProfileById(lastOpenId);
        if (profileToRestore) { loadProfile(profileToRestore); }
      }
    } catch (e) {}

    window.addEventListener("beforeunload", function (e) {
      if (ed.dirty) { e.preventDefault(); e.returnValue = ""; }
    });
  });

  /* ================================================================
   * DIRTY STATE
   * ================================================================ */

  function setDirty(val) {
    ed.dirty = val;
    var btn = document.getElementById("lumia-mc-save-panel-btn");
    if (btn) btn.disabled = !val;
    if (val) pushHistory();
  }

  /* ================================================================
   * HISTORIQUE — annuler / rétablir
   *
   * Toute mutation de l'éditeur se termine par setDirty(true) : c'est donc là
   * qu'on prend l'instantané, plutôt que d'instrumenter chaque poignée (arbre,
   * champs, picker d'icône…) et d'en oublier une. L'instantané reprend
   * collectProfile() — l'état des champs du panneau, qui vit dans le DOM et pas
   * dans ed.profile — mais garde les items avec leurs propriétés d'exécution
   * (_uid, _wpLabel) pour ne pas casser la sélection au retour en arrière.
   * ================================================================ */

  var hist = { stack: [], index: -1, lock: false, last: 0, ready: false, baseDirty: false };

  function snapshotState() {
    var s = collectProfile();
    s.items = deepCopy(ed.profile.items || []);
    return s;
  }

  function resetHistory() {
    if (!ed.profile) {
      hist.ready = false; hist.stack = []; hist.index = -1;
      return;
    }
    hist.lock      = false;
    hist.ready     = true;
    hist.last      = 0;
    hist.stack     = [snapshotState()];
    hist.index     = 0;
    hist.baseDirty = ed.dirty;
  }

  function pushHistory() {
    if (!hist.ready || hist.lock || !ed.profile) return;
    var snap = snapshotState();
    var now  = Date.now();

    // Coalescence : la frappe déclenche un setDirty par caractère, ce qui
    // donnerait un historique inutilisable (un Ctrl+Z par lettre).
    if (hist.index > 0 && now - hist.last < 400) {
      hist.stack[hist.index] = snap;
      hist.last = now;
      return;
    }

    hist.stack = hist.stack.slice(0, hist.index + 1);
    hist.stack.push(snap);
    if (hist.stack.length > 60) hist.stack.shift();
    hist.index = hist.stack.length - 1;
    hist.last  = now;
  }

  function applyHistory(i) {
    var id = ed.profile.id;
    hist.lock = true;
    ed.profile     = deepCopy(hist.stack[i]);
    ed.profile.id  = id;
    ed.selectedUid = null;
    renderEditor();
    // Revenir à l'instantané de départ, c'est revenir à l'état enregistré :
    // le menu n'est plus « modifié » (sauf s'il n'a jamais été enregistré).
    setDirty(i !== 0 || hist.baseDirty);
    hist.index = i;
    hist.last  = 0;
    hist.lock  = false;
  }

  function undo() {
    if (!ed.profile || !hist.ready) return;
    if (hist.index <= 0) { toast("Rien à annuler.", "info"); return; }
    applyHistory(hist.index - 1);
    toast("Modification annulée.", "info");
  }

  function redo() {
    if (!ed.profile || !hist.ready) return;
    if (hist.index >= hist.stack.length - 1) { toast("Rien à rétablir.", "info"); return; }
    applyHistory(hist.index + 1);
    toast("Modification rétablie.", "info");
  }

  /**
   * Raccourcis clavier de l'éditeur.
   *
   * Ctrl/Cmd+S est toujours intercepté (le dialogue « enregistrer la page » du
   * navigateur n'a aucun sens ici). Ctrl+Z / Ctrl+Y sont en revanche laissés au
   * champ quand le focus est dans une zone de saisie : l'annulation de texte
   * native y est attendue, et une annulation globale ferait perdre bien plus
   * que la lettre que l'utilisateur voulait reprendre.
   */
  function bindShortcuts() {
    document.addEventListener("keydown", function (e) {
      if (!(e.ctrlKey || e.metaKey) || e.altKey) return;
      var key = (e.key || "").toLowerCase();

      if (key === "s") {
        e.preventDefault();
        if (!ed.profile) return;
        if (ed.dirty) onSave();
        else toast("Aucune modification à enregistrer.", "info");
        return;
      }

      if (key !== "z" && key !== "y") return;
      var t = e.target;
      if (t && (t.isContentEditable ||
                /^(input|textarea|select)$/i.test(t.tagName || ""))) return;

      e.preventDefault();
      if (key === "y" || e.shiftKey) redo();
      else undo();
    });
  }

  /* ================================================================
   * CONFIRMATION UNSAVED CHANGES
   * ================================================================ */

  function confirmDirty(callback) {
    if (!ed.dirty) { callback(); return; }
    window.lumiaModal.open({
      title:        "Modifications non sauvegardées",
      message:      "Vos modifications seront perdues. Continuer ?",
      confirmLabel: "Continuer sans enregistrer",
      cancelLabel:  "Annuler",
      danger:       true,
      onConfirm:    function () { setDirty(false); callback(); },
    });
  }

  /* ================================================================
   * PROFIL VIDE
   * ================================================================ */

  function blankProfile() {
    var profiles = lumiaAdmin.mcProfiles || [];
    var names    = profiles.map(function (p) { return p.name || ""; });
    var n = 1;
    while (names.indexOf("Menu " + n) !== -1) { n++; }
    return {
      id: "__new__", name: "Menu " + n, status: "draft", apply_to_all: false,
      include_roles: [], include_users: [], exclude_roles: [], exclude_users: [],
      items: [], updated_at: 0,
    };
  }

  /* ================================================================
   * CHARGEMENT D'UN PROFIL
   * ================================================================ */

  function loadProfile(profile) {
    hideIconPicker();
    ed.profile     = deepCopy(profile);
    ed.selectedUid = null;
    expandedUids.clear();
    setDirty(false);

    ensureUids(ed.profile.items);
    mergeWpMenu();
    hidePlaceholder();
    renderEditor();
    renderProfilesSidebar();
    resetHistory();
  }

  function startNewProfile() {
    hideIconPicker();
    ed.profile     = blankProfile();
    ed.selectedUid = null;
    expandedUids.clear();
    // Un menu tout juste créé n'existe pas encore côté serveur : le bouton
    // Enregistrer doit être utilisable immédiatement, sans exiger que
    // l'utilisateur modifie un champ au préalable.
    setDirty(true);

    ensureUids(ed.profile.items);
    mergeWpMenu();
    hidePlaceholder();
    renderEditor();
    renderProfilesSidebar();
    resetHistory();
  }

  /* ================================================================
   * PLACEHOLDER
   * ================================================================ */

  function showPlaceholder() {
    setDisplay("lumia-mc-placeholder",  "");
    setDisplay("lumia-mc-stale-bar",    "none");
    setDisplay("lumia-mc-tree-actions", "none");
    setDisplay("lumia-wl-tree",         "none");
    setDisplay("lumia-wl-settings-col", "none");
    setDirty(false);
  }

  function hidePlaceholder() {
    setDisplay("lumia-mc-placeholder",  "none");
    setDisplay("lumia-mc-tree-actions", "");
    setDisplay("lumia-wl-tree",         "");
    setDisplay("lumia-wl-settings-col", "");
  }

  /* ================================================================
   * SIDEBAR — liste des profils
   * ================================================================ */

  function renderProfilesSidebar() {
    var container = document.getElementById("lumia-wl-ep-profiles-list");
    if (!container) return;

    var profiles  = lumiaAdmin.mcProfiles || [];
    var currentId = ed.profile ? ed.profile.id : null;
    var isDraft   = !!(ed.profile && ed.profile.id === "__new__");

    var filtered = profiles.filter(function (p) {
      var matchF = sidebarState.filter === "all" || p.status === sidebarState.filter;
      var matchS = !sidebarState.search ||
        (p.name || "").toLowerCase().indexOf(sidebarState.search.toLowerCase()) !== -1;
      return matchF && matchS;
    });

    if (!filtered.length && !isDraft) {
      container.innerHTML = '<p class="lumia-wl-ep-profiles-empty">' +
        esc(profiles.length === 0
          ? "Aucun menu. Utilisez « Nouveau menu » pour en créer un."
          : "Aucun résultat.") + "</p>";
      return;
    }

    var draftHtml = isDraft ? buildDraftRowHtml(ed.profile) : "";

    container.innerHTML = draftHtml + filtered.map(function (p) {
      var isActive  = p.status === "active";
      var dotClass  = isActive ? "lumia-mc-dot--active" : "lumia-mc-dot--draft";
      var isCurrent = p.id === currentId;
      return (
        '<div class="lumia-wl-ep-profile-item' + (isCurrent ? " is-active" : "") +
            '" data-id="' + esc(p.id) + '">' +
          '<span class="lumia-mc-dot ' + dotClass + '" data-lumia-tip="' +
            (isActive ? "Menu actif" : "Brouillon — non appliqué") + '"></span>' +
          '<span class="lumia-wl-ep-profile-item__name">' +
            esc(p.name || "Menu sans nom") + "</span>" +
          '<span class="lumia-mc-item-actions">' +
            '<button type="button" class="lumia-mc-item-action" data-action="duplicate" ' +
              'data-id="' + esc(p.id) + '" data-lumia-tip="Dupliquer ce menu">' +
              (L.copy || "") + "</button>" +
            '<button type="button" class="lumia-mc-item-action lumia-mc-item-action--danger" ' +
              'data-action="delete" data-id="' + esc(p.id) + '" data-lumia-tip="Supprimer ce menu">' +
              (L.trash || "×") + "</button>" +
          "</span>" +
        "</div>"
      );
    }).join("");

    container.querySelectorAll(".lumia-wl-ep-profile-item").forEach(function (item) {
      item.addEventListener("click", function (e) {
        if (e.target.closest(".lumia-mc-item-action")) return;
        var profile = findProfileById(item.dataset.id);
        if (!profile) return;
        confirmDirty(function () { loadProfile(profile); });
      });
    });
    container.querySelectorAll("[data-action='duplicate']").forEach(function (btn) {
      btn.addEventListener("click", function (e) { e.stopPropagation(); onSidebarDuplicate(btn.dataset.id); });
    });
    container.querySelectorAll("[data-action='delete']").forEach(function (btn) {
      btn.addEventListener("click", function (e) { e.stopPropagation(); onSidebarDelete(btn.dataset.id); });
    });
  }

  /**
   * Ligne représentant le menu en cours de création, pas encore enregistré
   * côté serveur (donc absent de lumiaAdmin.mcProfiles) : sans actions
   * dupliquer/supprimer, qui exigent un id réel.
   */
  function buildDraftRowHtml(p) {
    return (
      '<div class="lumia-wl-ep-profile-item is-active" data-id="__new__">' +
        '<span class="lumia-mc-dot lumia-mc-dot--draft" data-lumia-tip="Brouillon — non appliqué"></span>' +
        '<span class="lumia-wl-ep-profile-item__name">' +
          esc(p.name || "Menu sans nom") + "</span>" +
        '<span class="lumia-badge lumia-badge--warning">Non enregistré</span>' +
      "</div>"
    );
  }

  function bindProfilesSidebar() {
    var searchEl = document.getElementById("lumia-wl-ep-search");
    if (searchEl) {
      searchEl.addEventListener("input", function () {
        sidebarState.search = searchEl.value;
        renderProfilesSidebar();
      });
    }
    document.querySelectorAll(".lumia-wl-ep__profiles-tab").forEach(function (tab) {
      tab.addEventListener("click", function () {
        document.querySelectorAll(".lumia-wl-ep__profiles-tab").forEach(function (t) {
          t.classList.remove("is-active");
        });
        tab.classList.add("is-active");
        sidebarState.filter = tab.dataset.filter || "all";
        renderProfilesSidebar();
      });
    });
  }

  /* ================================================================
   * ACTIONS SIDEBAR
   * ================================================================ */

  function onSidebarDelete(profileId) {
    var p    = findProfileById(profileId);
    var name = p ? (p.name || "ce menu") : "ce menu";
    window.lumiaModal.open({
      title: "Supprimer le menu",
      message: 'Supprimer « ' + name + ' » ? Cette action est irréversible.',
      confirmLabel: "Supprimer", cancelLabel: "Annuler", danger: true,
      onConfirm: function () {
        // Un menu actif applique ses personnalisations au menu WP réel :
        // après suppression il faut recharger la page pour que la barre
        // latérale WP revienne à son état natif (sinon résidus à l'écran).
        var wasActive = !!(p && p.status === "active");
        ajaxPost("lumia_wl_delete_profile", { profile_id: profileId }, function (data) {
          if (data && data.success) {
            lumiaAdmin.mcProfiles = (lumiaAdmin.mcProfiles || []).filter(function (x) { return x.id !== profileId; });
            if (ed.profile && ed.profile.id === profileId) { ed.profile = null; showPlaceholder(); }
            renderProfilesSidebar();
            toast("Menu supprimé.", "success");
            if (wasActive) { setTimeout(function () { window.location.reload(); }, 600); }
          } else { toast("Erreur lors de la suppression.", "error"); }
        });
      },
    });
  }

  function onSidebarDuplicate(profileId) {
    ajaxPost("lumia_wl_duplicate_profile", { profile_id: profileId }, function (data) {
      if (data && data.success) {
        lumiaAdmin.mcProfiles = lumiaAdmin.mcProfiles || [];
        lumiaAdmin.mcProfiles.push(data.data.profile);
        renderProfilesSidebar();
        toast("Menu dupliqué.", "success");
      } else { toast("Erreur lors de la duplication.", "error"); }
    });
  }

  /* ================================================================
   * PIED DE PAGE — Enregistrer / Réinitialiser
   * ================================================================ */

  function bindPanelFooter() {
    var saveBtn  = document.getElementById("lumia-mc-save-panel-btn");
    var resetBtn = document.getElementById("lumia-mc-reset-menu-btn");
    var expBtn   = document.getElementById("lumia-mc-export-btn");

    if (expBtn) expBtn.addEventListener("click", exportProfile);

    if (saveBtn) {
      saveBtn.addEventListener("click", function () {
        if (ed.profile && ed.dirty) onSave();
      });
    }

    if (resetBtn) {
      resetBtn.addEventListener("click", function () {
        if (!ed.profile) return;
        window.lumiaModal.open({
          title:        "Réinitialiser le menu",
          message:      "Toutes les modifications non sauvegardées seront perdues et le menu sera rechargé depuis la dernière sauvegarde.",
          confirmLabel: "Réinitialiser",
          cancelLabel:  "Annuler",
          danger:       true,
          onConfirm: function () {
            if (ed.profile.id === "__new__") {
              startNewProfile();
            } else {
              var saved = findProfileById(ed.profile.id);
              if (saved) { loadProfile(saved); }
              else        { startNewProfile(); }
            }
          },
        });
      });
    }
  }

  /* ================================================================
   * FUSION AVEC LE MENU WP
   * ================================================================ */

  function mergeWpMenu() {
    var wpMenu = lumiaAdmin.wpMenu || [];
    if (!ed.profile.items.length && wpMenu.length) {
      ed.profile.items = wpMenu.map(function (m) { return wpItemToEditorItem(m); }).filter(Boolean);
    } else {
      var seen = {};
      // On ne purge les références WP disparues que si l'on connaît réellement
      // le menu WP courant : sans wpMenu (donnée absente), on ne supprime rien.
      var wpKnown = wpMenu.length > 0;
      ed.profile.items = ed.profile.items.filter(function (item) {
        if (!item._uid) item._uid = genUid();
        // Séparateurs et liens personnalisés n'existent pas dans le menu WP :
        // ils sont propres au profil, toujours conservés.
        if (item.type === "separator" || item.type === "custom_link") {
          seen[item.slug] = true;
          return true;
        }
        var wp = findWpItem(item.slug);
        // Élément WP qui ne correspond à plus rien dans le menu réel (extension
        // désactivée, fonctionnalité coupée comme le Gestionnaire de liens, ou
        // résidu d'un ancien profil). On le garde mais on le signale : le
        // supprimer en silence ferait disparaître un réglage volontaire dès
        // qu'une extension est désactivée le temps d'une mise à jour.
        item._stale   = !wp && wpKnown;
        item._wpLabel = wp ? stripTags(wp.label) : (item._wpLabel || item.slug);
        item._wpIcon  = wp ? (wp.icon || "") : (item._wpIcon || "");
        seen[item.slug] = true;
        item.children = (item.children || []).map(function (c) {
          if (!c._uid) c._uid = genUid();
          var sub = findWpSub(item.slug, c.slug);
          c._stale   = !sub && wpKnown;
          c._wpLabel = sub ? stripTags(sub.label) : (c._wpLabel || c.slug);
          c._wpIcon  = "";
          return c;
        });
        return true;
      });
      wpMenu.forEach(function (m) {
        if (m.slug && !seen[m.slug]) {
          var item = wpItemToEditorItem(m);
          if (item) ed.profile.items.push(item);
        }
      });
    }
  }

  function wpItemToEditorItem(m) {
    if (!m.slug) return null;
    // Séparateurs WP : slug commençant par "separator" (label toujours vide)
    if (/^separator/.test(m.slug)) {
      return {
        type: "separator", slug: m.slug, _uid: genUid(),
        visible: true, label: null, icon: null,
        url: "", target_blank: false, children: [],
      };
    }
    return {
      type: "wp_item", slug: m.slug, label: null, icon: null,
      visible: true, target_blank: false, url: "",
      children: buildWpChildren(m.slug),
      _uid: genUid(),
      _wpLabel: stripTags(m.label || m.slug),
      _wpIcon:  m.icon || "",
    };
  }

  function buildWpChildren(parentSlug) {
    return ((lumiaAdmin.wpSubmenu || {})[parentSlug] || []).map(function (s) {
      if (!s.slug) return null;
      return {
        type: "wp_item", slug: s.slug, label: null, icon: null,
        visible: true, target_blank: false, url: "", children: [],
        _uid: genUid(),
        _wpLabel: stripTags(s.label || s.slug),
        _wpIcon: "",
      };
    }).filter(Boolean);
  }

  /* ================================================================
   * RENDU GLOBAL
   * ================================================================ */

  function renderEditor() {
    populateProfilePanel();
    showProfilePanel();
    renderTree();
    ms.include = createMultiSelect("lumia-wl-include-select", buildInitialChips(
      ed.profile.include_roles || [], ed.profile.include_users || []
    ));
    ms.exclude = createMultiSelect("lumia-wl-exclude-select", buildInitialChips(
      ed.profile.exclude_roles || [], ed.profile.exclude_users || []
    ));
    syncApplyAll();
    updateStatusBadge();
  }

  /* ================================================================
   * NAVIGATION DU PANEL DROIT (sans tabs)
   * ================================================================ */

  function showProfilePanel() {
    hideIconPicker();
    ed.selectedUid = null;
    setDisplay("lumia-mc-back-btn", "none");
    // L'export porte sur le menu entier : il n'a rien à faire sur la vue d'un
    // élément, où le bouton laisserait croire qu'on exporte cet élément-là.
    setDisplay("lumia-mc-export-btn", "");
    var titleEl = document.getElementById("lumia-mc-panel-title");
    if (titleEl) titleEl.textContent = "Paramètres du menu";
    show("lumia-wl-profile-settings");
    hide("lumia-wl-item-settings");
    renderTree();
  }

  function showItemPanel(item) {
    hideIconPicker();
    ed.selectedUid = item._uid;
    setDisplay("lumia-mc-back-btn", "");
    setDisplay("lumia-mc-export-btn", "none");
    var titleEl = document.getElementById("lumia-mc-panel-title");
    if (titleEl) {
      titleEl.textContent = item.label || item._wpLabel || prettifySlug(item.slug) || "Élément";
    }
    hide("lumia-wl-profile-settings");
    show("lumia-wl-item-settings");
    var isChild = isChildItem(item._uid);
    var fields = document.getElementById("lumia-wl-item-fields");
    if (fields) { fields.innerHTML = buildItemFields(item, isChild); bindItemFields(item); }
    renderTree();
  }

  /**
   * Un enfant n'a jamais d'icône WP (WordPress ne fournit aucune icône
   * pour les sous-menus dans $submenu, contrairement à $menu) : on masque
   * donc le picker d'icône pour ces items plutôt que de laisser un
   * contrôle qui ne peut jamais rien afficher de pertinent.
   */
  function isChildItem(uid) {
    if (!ed.profile) return false;
    return !ed.profile.items.some(function (i) { return i._uid === uid; });
  }

  /* ================================================================
   * ARBRE
   * ================================================================ */

  function renderTree() {
    var tree = document.getElementById("lumia-wl-tree");
    if (!tree) return;
    var items = ed.profile.items;
    tree.innerHTML = items.map(function (item, i) {
      return buildItemHtml(item, i, items.length, "");
    }).join("");
    bindTree(tree);
    renderStaleBar();
  }

  /* ================================================================
   * ENTRÉES OBSOLÈTES
   * ================================================================ */

  /**
   * Liste des items (parents et enfants) dont le slug n'existe plus dans le
   * menu WP courant. On les garde dans le profil — une extension désactivée
   * le temps d'une mise à jour ne doit pas effacer son paramétrage — mais on
   * le dit, sinon ces réglages sans effet passent inaperçus.
   */
  function staleItems() {
    if (!ed.profile) return [];
    var out = [];
    (ed.profile.items || []).forEach(function (item) {
      if (item._stale) out.push(item);
      (item.children || []).forEach(function (c) { if (c._stale) out.push(c); });
    });
    return out;
  }

  function renderStaleBar() {
    var bar = document.getElementById("lumia-mc-stale-bar");
    if (!bar) return;
    var stale = staleItems();
    if (!stale.length) { bar.style.display = "none"; bar.innerHTML = ""; return; }

    var names = stale.map(function (i) {
      return i.label || i._wpLabel || prettifySlug(i.slug);
    });
    bar.innerHTML =
      '<span class="lumia-mc-stale-bar__icon">' + (L.warn || "!") + "</span>" +
      '<span class="lumia-mc-stale-bar__text">' +
        "<strong>" + stale.length +
        (stale.length > 1 ? " entrées obsolètes" : " entrée obsolète") + "</strong> — " +
        esc(names.slice(0, 4).join(", ")) +
        (names.length > 4 ? " et " + (names.length - 4) + " autre(s)" : "") +
        ". Ces slugs ne correspondent à aucun menu WordPress actuel." +
      "</span>" +
      '<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary" ' +
        'id="lumia-mc-stale-clean">Nettoyer</button>';
    bar.style.display = "";

    var btn = document.getElementById("lumia-mc-stale-clean");
    if (btn) btn.addEventListener("click", onCleanStale);
  }

  function onCleanStale() {
    var stale = staleItems();
    if (!stale.length) return;
    window.lumiaModal.open({
      title:        "Nettoyer les entrées obsolètes",
      message:      "Retirer " + stale.length + " entrée(s) de ce menu ? " +
                    "Si l'extension concernée est réactivée, l'entrée reviendra avec ses réglages par défaut.",
      confirmLabel: "Nettoyer",
      cancelLabel:  "Annuler",
      danger:       true,
      onConfirm: function () {
        ed.profile.items = (ed.profile.items || []).filter(function (item) {
          item.children = (item.children || []).filter(function (c) { return !c._stale; });
          return !item._stale;
        });
        ed.selectedUid = null;
        showProfilePanel();
        setDirty(true);
        toast("Entrées obsolètes retirées. Pensez à enregistrer.", "success");
      },
    });
  }

  function buildItemHtml(item, idx, total, parentUid) {
    var uid = item._uid;

    /* --- SÉPARATEUR : juste une ligne, aucun texte --- */
    if (item.type === "separator") {
      return (
        '<div class="lumia-wl-tree-item lumia-wl-tree-item--sep" data-uid="' + esc(uid) + '">' +
          '<div class="lumia-wl-tree-item__row">' +
            '<span class="lumia-wl-tree-item__handle">' + (L.grip || "") + "</span>" +
            '<span class="lumia-wl-tree-item__toggle-ph"></span>' +
            '<span class="lumia-wl-tree-sep-line"></span>' +
            '<div class="lumia-wl-tree-item__btns">' +
              mvBtn(uid, parentUid, idx, total) +
              '<button type="button" class="lumia-wl-tree-item__del" ' +
                'data-uid="' + esc(uid) + '" data-parent="' + esc(parentUid) + '">' +
                (L.trash || "×") + "</button>" +
            "</div>" +
          "</div>" +
        "</div>"
      );
    }

    /* --- ITEM NORMAL --- */
    var label    = item.label || item._wpLabel || prettifySlug(item.slug);
    var hidden   = item.visible === false;
    var selected = ed.selectedUid === uid;
    var hasChild = item.children && item.children.length > 0;
    var expanded = expandedUids.has(uid);

    var toggleHtml = hasChild
      ? '<button type="button" class="lumia-wl-tree-item__toggle" data-uid="' + esc(uid) + '">' +
          (expanded ? (L.chevronD || "▾") : (L.chevronR || "▸")) + "</button>"
      : '<span class="lumia-wl-tree-item__toggle-ph"></span>';

    var childrenHtml = "";
    if (hasChild) {
      var childList = item.children;
      childrenHtml = '<div class="lumia-wl-tree-item__children"' +
        (expanded ? "" : ' style="display:none"') + ">" +
        childList.map(function (c, ci) {
          return buildChildHtml(c, ci, childList.length, uid);
        }).join("") + "</div>";
    }

    return (
      '<div class="lumia-wl-tree-item' + (selected ? " is-selected" : "") +
          (item._stale ? " is-stale" : "") +
          '" data-uid="' + esc(uid) + '">' +
        '<div class="lumia-wl-tree-item__row">' +
          '<span class="lumia-wl-tree-item__handle">' + (L.grip || "") + "</span>" +
          toggleHtml +
          buildIconEl(item) +
          '<span class="lumia-wl-tree-item__label' + (hidden ? " is-hidden" : "") + '">' +
            esc(label) + "</span>" +
          staleBadge(item) +
          lockBadge(item) +
          '<div class="lumia-wl-tree-item__btns">' +
            '<button type="button" class="lumia-wl-tree-item__vis" data-uid="' + esc(uid) + '" ' +
              'data-lumia-tip="' + (hidden ? "Afficher dans le menu" : "Masquer du menu") + '">' +
              (hidden ? (L.eyeOff || "") : (L.eye || "")) + "</button>" +
            mvBtn(uid, parentUid, idx, total) +
            (item.type === "custom_link"
              ? '<button type="button" class="lumia-wl-tree-item__del" ' +
                  'data-uid="' + esc(uid) + '" data-parent="' + esc(parentUid) + '">' +
                  (L.trash || "×") + "</button>"
              : "") +
          "</div>" +
        "</div>" +
        childrenHtml +
      "</div>"
    );
  }

  function buildChildHtml(c, ci, total, parentUid) {
    var uid    = c._uid;
    var label  = c.label || c._wpLabel || prettifySlug(c.slug);
    var hidden = c.visible === false;
    var selected = ed.selectedUid === uid;
    return (
      '<div class="lumia-wl-tree-item lumia-wl-tree-item--child' +
          (selected ? " is-selected" : "") + (c._stale ? " is-stale" : "") +
          '" data-uid="' + esc(uid) + '">' +
        '<div class="lumia-wl-tree-item__row">' +
          '<span class="lumia-wl-tree-item__handle">' + (L.grip || "") + "</span>" +
          '<span class="lumia-wl-tree-item__toggle-ph"></span>' +
          '<span class="lumia-wl-tree-item__icon-ph"></span>' +
          '<span class="lumia-wl-tree-item__label' + (hidden ? " is-hidden" : "") + '">' +
            esc(label) + "</span>" +
          staleBadge(c) +
          lockBadge(c) +
          '<div class="lumia-wl-tree-item__btns">' +
            '<button type="button" class="lumia-wl-tree-item__vis" data-uid="' + esc(uid) + '" ' +
              'data-lumia-tip="' + (hidden ? "Afficher dans le menu" : "Masquer du menu") + '">' +
              (hidden ? (L.eyeOff || "") : (L.eye || "")) + "</button>" +
            mvBtn(uid, parentUid, ci, total) +
          "</div>" +
        "</div>" +
      "</div>"
    );
  }

  function mvBtn(uid, parentUid, idx, total) {
    var up = idx === 0 ? ' style="opacity:.3;pointer-events:none"' : "";
    var dn = idx === total - 1 ? ' style="opacity:.3;pointer-events:none"' : "";
    return (
      '<button type="button" class="lumia-wl-tree-item__mv" data-mv="up" ' +
        'data-uid="' + esc(uid) + '" data-parent="' + esc(parentUid) + '" data-lumia-tip="Monter"' + up + '>' +
        (L.chevronU || "↑") + "</button>" +
      '<button type="button" class="lumia-wl-tree-item__mv" data-mv="down" ' +
        'data-uid="' + esc(uid) + '" data-parent="' + esc(parentUid) + '" data-lumia-tip="Descendre"' + dn + '>' +
        (L.chevronD || "↓") + "</button>"
    );
  }

  /**
   * Source affichable d'une valeur d'icône stockée ("svg:<base64>" ou URL).
   */
  function iconSrc(icon) {
    return icon.indexOf("svg:") === 0 ? "data:image/svg+xml;base64," + icon.slice(4) : icon;
  }

  function isSvgSrc(src) {
    return src.indexOf("data:image/svg+xml") === 0 || /\.svg([?#]|$)/i.test(src);
  }

  // Texte des SVG servis par URL, résolu une fois puis mémorisé.
  // null = en cours / échec, string = contenu.
  var svgTextCache = {};

  /**
   * Contenu d'une source SVG, quand il est lisible sans requête.
   * Pour une URL du site, lance un fetch et redessine l'arbre à l'arrivée
   * (une seule fois par URL) plutôt que de bloquer le rendu.
   */
  function svgTextOf(src) {
    if (src.indexOf("data:image/svg+xml;base64,") === 0) {
      try { return decodeURIComponent(escape(atob(src.slice(26)))); } catch (e) { return null; }
    }
    if (src.indexOf("data:image/svg+xml") === 0) {
      try { return decodeURIComponent(src.slice(src.indexOf(",") + 1)); } catch (e) { return null; }
    }
    if (Object.prototype.hasOwnProperty.call(svgTextCache, src)) return svgTextCache[src];

    svgTextCache[src] = null;
    fetch(src, { credentials: "same-origin" })
      .then(function (r) { return r.ok ? r.text() : null; })
      .then(function (text) {
        if (!text) return;
        svgTextCache[src] = text;
        if (ed.profile) renderTree();
      })
      .catch(function () {});
    return null;
  }

  /**
   * Un SVG est-il monochrome, donc recolorisable par masque sans rien perdre ?
   * Même règle que Module::svg_is_monochrome() côté PHP : une icône bicolore
   * (le logo du plugin : carré blanc + glyphe noir) serait aplatie en carré
   * plein par un masque, elle garde donc son <img>.
   */
  function isMonochromeSvg(text) {
    if (!text) return false;
    if (/currentcolor/i.test(text)) return true;
    var colors = {};
    var re = /(?:fill|stroke|stop-color)\s*[:=]\s*["']?\s*(#[0-9a-f]{3,8}|rgba?\([^)]*\)|[a-z]+)/gi;
    var m;
    while ((m = re.exec(text))) {
      var c = m[1].toLowerCase().trim();
      if (c === "none" || c === "transparent" || c === "inherit" || c === "currentcolor") continue;
      colors[c] = true;
    }
    return Object.keys(colors).length <= 1;
  }

  /**
   * Rend une icône de menu dans l'éditeur (fond clair).
   *
   * Les SVG d'icône de menu sont monochromes et peints pour la barre latérale
   * SOMBRE de wp-admin : WooCommerce et Bricks embarquent un fill #f3f1f1
   * (invisible sur fond clair), les fichiers Lucide un stroke currentColor
   * (noir dans un <img>, faute de couleur héritée). Un <img> affiche donc soit
   * rien, soit une icône hors thème. On les rend en masque CSS : la source ne
   * fournit que la forme, la couleur vient de l'éditeur (currentColor).
   * Les images non-SVG (PNG/JPG) gardent un <img> classique.
   */
  function iconMarkup(icon, cls) {
    if (icon.indexOf("dashicons-") === 0) {
      return '<span class="' + cls + ' dashicons ' + esc(icon) + '" aria-hidden="true"></span>';
    }
    var src = iconSrc(icon);
    // Les guillemets / parenthèses casseraient le url() inline : repli <img>.
    if (isSvgSrc(src) && !/["'()\\]/.test(src) && isMonochromeSvg(svgTextOf(src))) {
      var u = 'url("' + src + '")';
      return '<span class="' + cls + ' lumia-wl-icon-mask" aria-hidden="true" ' +
        'style="-webkit-mask-image:' + esc(u) + ';mask-image:' + esc(u) + '"></span>';
    }
    return '<img class="' + cls + '" src="' + esc(src) + '" aria-hidden="true" alt="">';
  }

  /**
   * Cadenas sur un item masqué ET bloqué : sans marqueur, rien dans l'arbre ne
   * distingue « retiré du menu » de « page refusée ».
   */
  function lockBadge(item) {
    if (item.visible !== false || !item.block_access) return "";
    return '<span class="lumia-wl-tree-item__lock" data-lumia-tip="Masqué et accès direct bloqué">' +
      (L.lock || "") + "</span>";
  }

  /**
   * Marqueur d'aide (icône Lucide `info` + tooltip), pour une réserve
   * secondaire qui alourdirait la ligne si elle était écrite en toutes
   * lettres. Équivalent JS de `Admin::render_help_tip()`.
   */
  function helpTip(text) {
    return '<button type="button" class="lumia-tip-info" tabindex="0" data-lumia-tip="' +
      esc(text) + '" aria-label="' + esc(text) + '">' + (L.info || "") + "</button>";
  }

  /**
   * Marqueur « obsolète » : le slug ne correspond à aucune entrée du menu WP
   * courant. Le réglage est conservé (une extension peut être réactivée) mais
   * il ne produit plus rien tant que l'entrée n'existe pas.
   */
  function staleBadge(item) {
    if (!item._stale) return "";
    return '<span class="lumia-wl-tree-item__stale" ' +
      'data-lumia-tip="Entrée absente du menu WordPress actuel — extension désactivée ou supprimée.">' +
      (L.warn || "!") + "</span>";
  }

  function buildIconEl(item) {
    var icon = item.icon || item._wpIcon || "";
    if (!icon) return '<span class="lumia-wl-tree-item__icon-ph"></span>';
    return iconMarkup(icon, "lumia-wl-tree-item__icon");
  }

  /* ================================================================
   * BIND ARBRE
   * ================================================================ */

  function bindTree(tree) {
    initTreeSortable(tree);

    /* --- CLIC SUR ROW → sélection --- */
    tree.querySelectorAll(
      ".lumia-wl-tree-item:not(.lumia-wl-tree-item--sep) .lumia-wl-tree-item__row"
    ).forEach(function (row) {
      row.addEventListener("click", function (e) {
        if (e.target.closest(".lumia-wl-tree-item__btns") ||
            e.target.closest(".lumia-wl-tree-item__toggle") ||
            e.target.closest(".lumia-wl-tree-item__handle")) return;
        var uid = row.parentElement.dataset.uid;
        if (!uid) return;
        var item = findByUid(uid);
        if (!item || item.type === "separator") return;
        showItemPanel(item);
      });
    });

    /* --- TOGGLE COLLAPSE --- */
    tree.querySelectorAll(".lumia-wl-tree-item__toggle").forEach(function (btn) {
      btn.addEventListener("click", function (e) {
        e.stopPropagation();
        var uid = btn.dataset.uid;
        if (expandedUids.has(uid)) expandedUids.delete(uid);
        else expandedUids.add(uid);
        renderTree();
      });
    });

    /* --- VISIBILITÉ --- */
    tree.querySelectorAll(".lumia-wl-tree-item__vis").forEach(function (btn) {
      btn.addEventListener("click", function (e) {
        e.stopPropagation();
        var item = findByUid(btn.dataset.uid);
        if (!item) return;
        item.visible = item.visible === false;
        setDirty(true);
        renderTree();
      });
    });

    /* --- MONTER / DESCENDRE --- */
    tree.querySelectorAll(".lumia-wl-tree-item__mv").forEach(function (btn) {
      btn.addEventListener("click", function (e) {
        e.stopPropagation();
        var uid       = btn.dataset.uid;
        var parentUid = btn.dataset.parent || "";
        var dir       = btn.dataset.mv;
        var list      = parentUid ? ((findByUid(parentUid) || {}).children || null) : ed.profile.items;
        if (!list) return;
        var idx    = list.findIndex(function (i) { return i._uid === uid; });
        var newIdx = dir === "up" ? idx - 1 : idx + 1;
        if (idx === -1 || newIdx < 0 || newIdx >= list.length) return;
        var tmp       = list[idx];
        list[idx]     = list[newIdx];
        list[newIdx]  = tmp;
        setDirty(true);
        renderTree();
      });
    });

    /* --- SUPPRIMER (séparateurs et liens custom) --- */
    tree.querySelectorAll(".lumia-wl-tree-item__del").forEach(function (btn) {
      btn.addEventListener("click", function (e) {
        e.stopPropagation();
        var uid       = btn.dataset.uid;
        var parentUid = btn.dataset.parent || "";
        var list      = parentUid ? ((findByUid(parentUid) || {}).children || null) : ed.profile.items;
        if (!list) return;
        var idx = list.findIndex(function (i) { return i._uid === uid; });
        if (idx === -1) return;
        list.splice(idx, 1);
        setDirty(true);
        if (ed.selectedUid === uid) showProfilePanel();
        renderTree();
      });
    });
  }

  /* ================================================================
   * DRAG & DROP (SortableJS) — un instance par niveau, aucun
   * croisement racine ↔ enfants (pas de "group" partagé)
   * ================================================================ */

  function initTreeSortable(tree) {
    if (typeof Sortable === "undefined") return;

    var sortableOpts = {
      handle:     ".lumia-wl-tree-item__handle",
      animation:  150,
      chosenClass: "is-dragging",
      ghostClass:  "lumia-mc-sortable-ghost",
    };

    // "tree" (#lumia-wl-tree) est un noeud persistant entre les rendus
    // (seul son innerHTML change) : n'instancier Sortable dessus qu'une fois,
    // sinon chaque renderTree() empilerait une nouvelle instance dessus.
    if (!tree._lumiaSortable) {
      tree._lumiaSortable = new Sortable(tree, Object.assign({}, sortableOpts, {
        onEnd: function (evt) {
          if (evt.oldIndex === evt.newIndex) return;
          var moved = ed.profile.items.splice(evt.oldIndex, 1)[0];
          ed.profile.items.splice(evt.newIndex, 0, moved);
          setDirty(true);
          renderTree();
        },
      }));
    }

    // Les conteneurs d'enfants, eux, sont recréés à chaque rendu (innerHTML
    // remplacé) : une nouvelle instance à chaque fois est donc correcte.

    tree.querySelectorAll(".lumia-wl-tree-item__children").forEach(function (childrenEl) {
      var parentEl  = childrenEl.closest(".lumia-wl-tree-item");
      var parentUid = parentEl ? parentEl.dataset.uid : "";

      new Sortable(childrenEl, Object.assign({}, sortableOpts, {
        onEnd: function (evt) {
          if (evt.oldIndex === evt.newIndex) return;
          var parent = findByUid(parentUid);
          if (!parent || !parent.children) return;
          var moved = parent.children.splice(evt.oldIndex, 1)[0];
          parent.children.splice(evt.newIndex, 0, moved);
          setDirty(true);
          renderTree();
        },
      }));
    });
  }

  /* ================================================================
   * ACTIONS ARBRE (+séparateur / +lien) — ajout EN HAUT
   * ================================================================ */

  function bindTreeActions() {
    var sepBtn  = document.getElementById("lumia-wl-add-sep");
    var linkBtn = document.getElementById("lumia-wl-add-link");
    if (sepBtn) {
      sepBtn.addEventListener("click", function () {
        if (!ed.profile) return;
        ed.profile.items.unshift({
          type: "separator", slug: "sep-" + genUid(), _uid: genUid(),
          visible: true, label: null, icon: null, url: "", target_blank: false, children: [],
        });
        setDirty(true);
        renderTree();
      });
    }
    if (linkBtn) {
      linkBtn.addEventListener("click", function () {
        if (!ed.profile) return;
        ed.profile.items.unshift({
          type: "custom_link", slug: "lien-" + genUid(), label: "Nouveau lien",
          _uid: genUid(), _wpLabel: "Nouveau lien", _wpIcon: "",
          visible: true, target_blank: false, url: "", icon: null, roles: [], children: [],
        });
        setDirty(true);
        renderTree();
      });
    }
  }

  /* ================================================================
   * PANEL PROFIL
   * ================================================================ */

  function populateProfilePanel() {
    var nameEl = document.getElementById("lumia-wl-profile-name");
    if (nameEl) {
      nameEl.value = ed.profile.name || "";
      nameEl.oninput = function () { ed.profile.name = nameEl.value; setDirty(true); };
    }

    var applyAll = document.getElementById("lumia-wl-apply-all");
    if (applyAll) {
      applyAll.checked = !!ed.profile.apply_to_all;
      applyAll.onchange = function () {
        ed.profile.apply_to_all = applyAll.checked;
        setDirty(true);
        syncApplyAll();
      };
    }

    setSegment("lumia-wl-status-", ed.profile.status || "draft");

    ["lumia-wl-status-draft", "lumia-wl-status-active"].forEach(function (id) {
      var btn = document.getElementById(id);
      if (!btn) return;
      var fresh = btn.cloneNode(true);
      btn.parentNode.replaceChild(fresh, btn);
      fresh.addEventListener("click", function () {
        setSegment("lumia-wl-status-", fresh.dataset.value);
        setDirty(true);
        updateStatusBadge();
      });
    });
  }

  function syncApplyAll() {
    var applyAll = document.getElementById("lumia-wl-apply-all");
    var checked  = !!(applyAll && applyAll.checked);
    ["lumia-wl-targeting-rows", "lumia-wl-targeting-rows-ex"].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.style.display = checked ? "none" : "";
    });
  }

  function setSegment(prefix, value) {
    document.querySelectorAll('[id^="' + prefix + '"]').forEach(function (b) {
      b.classList.toggle("is-active", b.dataset.value === value);
    });
  }

  function updateStatusBadge() {
    var badge = document.getElementById("lumia-mc-status-badge");
    if (!badge) return;
    var activeBtn = document.getElementById("lumia-wl-status-active");
    var isActive  = activeBtn && activeBtn.classList.contains("is-active");
    badge.className  = "lumia-mc-status-badge " + (isActive ? "is-active" : "is-draft");
    badge.textContent = isActive ? "Actif" : "Brouillon";
  }

  /* ================================================================
   * CHAMPS D'ITEM
   * ================================================================ */

  function buildItemFields(item, isChild) {
    if (item.type === "separator") {
      return '<p class="lumia-wl-note" style="padding:16px">Séparateur — aucun paramètre.</p>';
    }
    var html = "";
    if (item.type === "custom_link") {
      html += settingsRow("URL",
        '<input type="url" class="lumia-input" id="lumia-wl-item-url" value="' + esc(item.url || "") + '">', "");
      // Les items WP sont déjà filtrés par leurs propres capacités ; un lien
      // personnalisé, lui, n'est rattaché à rien — d'où cette restriction.
      html +=
        '<div class="lumia-wl-settings-row lumia-wl-settings-row--col">' +
          '<div class="lumia-wl-settings-row__label"><span>Réservé aux rôles</span>' +
            '<p class="lumia-form__help">Laisser vide pour afficher ce lien à tous ceux qui voient ce menu.</p>' +
          "</div>" +
          '<div class="lumia-wl-multiselect" id="lumia-wl-item-roles-select"></div>' +
        "</div>";
    }
    html += settingsRow("Label",
      '<input type="text" class="lumia-input" id="lumia-wl-item-label" value="' + esc(item.label || "") + '" ' +
        'placeholder="' + esc(item._wpLabel || prettifySlug(item.slug)) + '">',
      "Laisser vide pour conserver le label d'origine.");
    html += (
      '<div class="lumia-wl-settings-row lumia-wl-settings-row--inline">' +
        '<div class="lumia-wl-settings-row__label"><span>Icône</span>' +
          (isChild
            ? '<p class="lumia-form__help">Appliquée uniquement lorsque cet élément devient un menu de premier niveau (rôles à capacités réduites, ex. « Profil » pour les auteurs).</p>'
            : "") +
        "</div>" +
        buildIconBtnHtml(item) +
      "</div>"
    );
    html += settingsRow("Visible",
      '<label class="lumia-toggle">' +
        '<input type="checkbox" id="lumia-wl-item-visible"' + (item.visible !== false ? " checked" : "") + '>' +
        '<span class="lumia-toggle__slider"></span></label>', "", true);
    // Masquer ne fait que retirer l'entrée du menu : l'URL reste ouvrable.
    // L'option n'a donc de sens — et n'est affichée — que sur un item masqué.
    if (item.type === "wp_item") {
      html +=
        '<div class="lumia-wl-settings-row lumia-wl-settings-row--inline" id="lumia-wl-block-row"' +
          (item.visible === false ? "" : ' style="display:none"') + ">" +
          '<div class="lumia-wl-settings-row__label">' +
            // La réserve importante (ce n'est pas un système de permissions)
            // passe sous un marqueur d'aide : elle doit rester lisible sans
            // allonger une ligne déjà dense.
            "<span>Bloquer l'accès direct" + helpTip("Ce n'est pas un système de " +
              "permissions : l'API REST, WP-CLI et les capacités WordPress ne sont pas " +
              "concernés.") + "</span>" +
            '<p class="lumia-form__help">Masquer retire seulement le lien : la page reste ' +
              'accessible par son URL. Cochez pour la refuser aussi (redirection vers le ' +
              'tableau de bord).</p>' +
          "</div>" +
          '<label class="lumia-toggle">' +
            '<input type="checkbox" id="lumia-wl-item-block"' + (item.block_access ? " checked" : "") + ">" +
            '<span class="lumia-toggle__slider"></span></label>' +
        "</div>";
    }
    // "Nouvel onglet" n'a de sens que pour un item de premier niveau : les
    // sous-menus pointent vers des pages admin WP, aucun intérêt à les ouvrir
    // dans un onglet séparé (et l'attribut target n'y est pas appliqué).
    if (!isChild) {
      html += settingsRow("Ouvrir dans un nouvel onglet",
        '<label class="lumia-toggle">' +
          '<input type="checkbox" id="lumia-wl-item-target"' + (item.target_blank ? " checked" : "") + '>' +
          '<span class="lumia-toggle__slider"></span></label>', "", true);
    }
    if (item.type === "wp_item") {
      html += (
        '<div class="lumia-wl-settings-row lumia-wl-item-reset-row">' +
          '<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--danger" id="lumia-wl-item-reset">' +
            'Réinitialiser l\'élément</button>' +
        "</div>"
      );
    }
    return html;
  }

  function settingsRow(label, control, help, inline) {
    var cls = "lumia-wl-settings-row" + (inline ? " lumia-wl-settings-row--inline" : "");
    return (
      '<div class="' + cls + '">' +
        '<div class="lumia-wl-settings-row__label">' +
          '<span>' + esc(label) + "</span>" +
          (help ? '<p class="lumia-form__help">' + esc(help) + "</p>" : "") +
        "</div>" + control +
      "</div>"
    );
  }

  function buildIconBtnHtml(item) {
    var icon  = item.icon;
    var thumb = buildIconThumbInner(icon, item._wpIcon);
    return (
      '<button type="button" class="lumia-wl-icon-btn" id="lumia-wl-icon-open">' +
        '<span class="lumia-wl-icon-btn-thumb">' + thumb + "</span>" +
        '<span>' + (icon ? "Modifier" : "Choisir une icône") + "</span>" +
      "</button>"
    );
  }

  function buildIconThumbInner(icon, fallbackWpIcon) {
    var val = icon || fallbackWpIcon || "";
    if (!val) return "";
    return iconMarkup(val, "lumia-wl-icon-btn-thumb__i");
  }

  function bindItemFields(item) {
    var urlEl = document.getElementById("lumia-wl-item-url");
    if (urlEl) urlEl.addEventListener("input", function () { item.url = urlEl.value; setDirty(true); });

    ms.itemRoles = null;
    if (document.getElementById("lumia-wl-item-roles-select")) {
      var roleNames = lumiaAdmin.wpRoles || {};
      ms.itemRoles = createMultiSelect(
        "lumia-wl-item-roles-select",
        (item.roles || []).map(function (r) {
          return { id: "role:" + r, rawId: r, label: roleNames[r] || r, type: "role" };
        }),
        {
          rolesOnly: true,
          onChange: function (value) { item.roles = value.roles; },
        }
      );
    }

    var lblEl = document.getElementById("lumia-wl-item-label");
    if (lblEl) lblEl.addEventListener("input", function () {
      item.label = lblEl.value || null;
      setDirty(true);
      var titleEl = document.getElementById("lumia-mc-panel-title");
      if (titleEl) titleEl.textContent = item.label || item._wpLabel || prettifySlug(item.slug) || "Élément";
      renderTree();
    });

    var visEl   = document.getElementById("lumia-wl-item-visible");
    var blockEl = document.getElementById("lumia-wl-item-block");
    var blockRow = document.getElementById("lumia-wl-block-row");
    if (visEl) visEl.addEventListener("change", function () {
      item.visible = visEl.checked;
      // Un item redevenu visible ne peut pas rester bloqué : le lien serait
      // affiché mais mènerait à un refus.
      if (item.visible) {
        item.block_access = false;
        if (blockEl) blockEl.checked = false;
      }
      if (blockRow) blockRow.style.display = item.visible ? "none" : "";
      setDirty(true);
      renderTree();
    });
    if (blockEl) blockEl.addEventListener("change", function () {
      item.block_access = blockEl.checked;
      setDirty(true);
      renderTree(); // fait apparaître / disparaître le cadenas dans l'arbre
    });

    var tgtEl = document.getElementById("lumia-wl-item-target");
    if (tgtEl) tgtEl.addEventListener("change", function () { item.target_blank = tgtEl.checked; setDirty(true); });

    var rstBtn = document.getElementById("lumia-wl-item-reset");
    if (rstBtn) rstBtn.addEventListener("click", function () {
      item.label = null; item.icon = null; item.visible = true;
      item.block_access = false; item.target_blank = false;
      setDirty(true);
      showItemPanel(item);
      toast("Élément réinitialisé.", "success");
    });

    var iconBtn = document.getElementById("lumia-wl-icon-open");
    if (iconBtn) iconBtn.addEventListener("click", function (e) {
      e.stopPropagation();
      openIconPicker(iconBtn, item);
    });
  }

  /* ================================================================
   * EXPORT / IMPORT D'UN MENU
   * ================================================================ */

  /**
   * Exporte le menu courant en .json. On sérialise l'état de l'éditeur (donc
   * y compris les modifications non enregistrées) : ce que l'utilisateur voit
   * est ce qu'il exporte.
   */
  function downloadJson(data, filename) {
    var url = URL.createObjectURL(new Blob(
      [JSON.stringify(data, null, 2)], { type: "application/json" }
    ));
    var a = document.createElement("a");
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    // Libère l'URL au tour de boucle suivant : la révoquer tout de suite
    // annulerait le téléchargement dans certains navigateurs.
    setTimeout(function () { URL.revokeObjectURL(url); }, 0);
  }

  function slugifyName(name, fallback) {
    return (name || "").toLowerCase()
      .replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "") || fallback;
  }

  function exportProfile() {
    if (!ed.profile) return;
    var payload = collectProfile();
    payload.id = ed.profile.id === "__new__" ? "" : (ed.profile.id || "");

    downloadJson({
      lumia:     "menu_profile",
      version:  1,
      exported: new Date().toISOString(),
      profile:  payload,
    }, "lumia-menu-" + slugifyName(payload.name, "menu") + ".json");
    toast("Menu exporté.", "success");
  }

  /**
   * Exporte tous les menus enregistrés. On part de lumiaAdmin.mcProfiles (l'état
   * en base), pas de l'éditeur : le menu ouvert peut avoir des modifications
   * non enregistrées, qu'il serait trompeur d'inclure dans un export « tout ».
   */
  function exportAllProfiles() {
    var profiles = lumiaAdmin.mcProfiles || [];
    if (!profiles.length) { toast("Aucun menu à exporter.", "error"); return; }

    if (ed.dirty) {
      toast("Modifications non enregistrées : elles ne sont pas dans l'export.", "warning");
    }
    downloadJson({
      lumia:     "menu_profiles",
      version:  1,
      exported: new Date().toISOString(),
      profiles: profiles,
    }, "lumia-menus.json");
    toast(profiles.length + (profiles.length > 1 ? " menus exportés." : " menu exporté."), "success");
  }

  function bindProfilesFooter() {
    var btn   = document.getElementById("lumia-mc-import-btn");
    var input = document.getElementById("lumia-mc-import-file");
    var all   = document.getElementById("lumia-mc-export-all-btn");

    if (all) all.addEventListener("click", exportAllProfiles);
    if (!btn || !input) return;

    btn.addEventListener("click", function () { confirmDirty(function () { input.click(); }); });

    input.addEventListener("change", function () {
      var file = input.files && input.files[0];
      if (!file) return;
      var reader = new FileReader();
      reader.onload = function () {
        // Le fichier ne fait que transiter : c'est le serveur qui valide et
        // assainit (sanitize_profile), jamais ce parse côté client.
        input.value = "";
        ajaxPost("lumia_wl_import_profile", { profile: String(reader.result || "") }, function (data) {
          if (!data || !data.success) {
            toast((data && data.data && data.data.message) || "Import impossible.", "error");
            return;
          }
          var imported = data.data.profiles || [data.data.profile];
          var ids = imported.map(function (p) { return p.id; });
          lumiaAdmin.mcProfiles = (lumiaAdmin.mcProfiles || []).filter(function (p) {
            return ids.indexOf(p.id) === -1;
          }).concat(imported);
          renderProfilesSidebar();
          loadProfile(imported[0]);

          var updated = data.data.updated || 0;
          var msg = imported.length > 1
            ? imported.length + " menus importés en brouillon."
            : "Menu importé en brouillon.";
          if (updated) {
            msg += " " + (updated > 1 ? updated + " menus existants mis à jour." : "1 menu existant mis à jour.");
          }
          toast(msg, "success");
        });
      };
      reader.onerror = function () { toast("Lecture du fichier impossible.", "error"); };
      reader.readAsText(file);
    });
  }

  /* ================================================================
   * FLOATING ICON PICKER
   * ================================================================ */

  /**
   * Ferme le picker explicitement lors de toute navigation (changement
   * d'item, de panel, de profil) : ne pas se reposer uniquement sur le
   * mousedown document-level, qui peut laisser le picker ouvert et bloquer
   * l'UX si la navigation est déclenchée par autre chose qu'un simple clic
   * en dehors (ex. sélection d'un autre item, changement de menu).
   */
  function hideIconPicker() {
    if (iconPickerEl) iconPickerEl.classList.add("is-hidden");
  }

  function createFloatingIconPicker() {
    iconPickerEl = document.createElement("div");
    iconPickerEl.className = "lumia-wl-icon-float is-hidden";
    document.body.appendChild(iconPickerEl);
  }

  function openIconPicker(triggerBtn, item) {
    iconPickerItem = item;
    iconPickerEl.innerHTML = buildIconDropdownHtml(item);
    iconPickerEl.classList.remove("is-hidden");

    var rect = triggerBtn.getBoundingClientRect();
    var w = 300, maxH = 392;
    var top  = rect.bottom + 4;
    var left = rect.left;
    if (left + w > window.innerWidth - 8)  left = window.innerWidth - w - 8;
    if (top + maxH > window.innerHeight - 8) top = rect.top - maxH - 4;
    iconPickerEl.style.top  = top + "px";
    iconPickerEl.style.left = left + "px";

    bindIconDropdown(iconPickerEl, item, triggerBtn);
  }

  /**
   * Repli d'accents : les slugs Lucide sont anglais, les alias français.
   * Sans ça, « etoile » ne trouverait pas « étoile » et l'utilisateur devrait
   * deviner l'accent exact du mot-clé.
   */
  function foldAccents(str) {
    return (str || "").toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, "");
  }

  /**
   * Termes indexés d'une icône : son slug (tirets remplacés par des espaces,
   * pour que « chart column » marche aussi) et ses alias FR/EN.
   */
  function iconSearchTerms(name) {
    var aliases = (lumiaAdmin && lumiaAdmin.iconAliases) || {};
    return foldAccents(name + " " + name.replace(/-/g, " ") + " " + (aliases[name] || ""));
  }

  /**
   * Grille de la bibliothèque, groupée par catégorie.
   *
   * Les catégories viennent de `lumiaAdmin.iconCategories` (ordre d'affichage
   * fait côté PHP). Si la donnée manque — payload d'une version antérieure —
   * on retombe sur une grille à plat de toute la bibliothèque.
   */
  function buildIconGridHtml() {
    var lib = (lumiaAdmin && lumiaAdmin.iconLibrary) || window.lumiaWlIconLibrary || {};
    var names = Object.keys(lib);
    if (!names.length) return '<p class="lumia-wl-icon-lib-empty">Bibliothèque vide.</p>';

    var cats = (lumiaAdmin && lumiaAdmin.iconCategories) || null;
    if (!cats || !cats.length) cats = [{ id: "all", label: "Icônes", icons: names }];

    function cell(name) {
      if (!lib[name]) return "";
      var b64 = btoa(unescape(encodeURIComponent(lib[name])));
      return (
        '<button type="button" class="lumia-wl-icon-grid-item" data-icon-name="' + esc(name) + '" ' +
          'data-icon-terms="' + esc(iconSearchTerms(name)) + '" ' +
          'data-icon-val="svg:' + esc(b64) + '" data-lumia-tip="' + esc(name) + '">' +
          iconMarkup("svg:" + b64, "lumia-wl-icon-grid-item__i") +
        "</button>"
      );
    }

    return cats.map(function (cat) {
      return (
        '<div class="lumia-wl-icon-cat" data-cat="' + esc(cat.id) + '">' +
          '<p class="lumia-wl-icon-cat__title">' + esc(cat.label) + "</p>" +
          '<div class="lumia-wl-icon-grid">' + (cat.icons || []).map(cell).join("") + "</div>" +
        "</div>"
      );
    }).join("") + '<p class="lumia-wl-icon-lib-empty" id="lumia-ip-no-result" style="display:none">Aucune icône.</p>';
  }

  function buildIconDropdownHtml(item) {
    var icon   = item.icon;
    var imgSrc = "";
    if (icon && icon.indexOf("dashicons-") !== 0) {
      imgSrc = iconSrc(icon);
    }

    // Repli explicite : on montre ce que « rétablir » va effectivement donner,
    // plutôt qu'un « Icône par défaut » qui n'annonce rien.
    var native = item._wpIcon || "";
    var resetLabel = native ? "Rétablir l'icône d'origine" : "Retirer l'icône";
    var resetPreview = native ? iconMarkup(native, "lumia-wl-icon-reset__i") : "";

    return (
      '<div class="lumia-wl-icon-picker-tabs">' +
        '<button type="button" class="lumia-wl-icon-tab is-active" data-tab="library">Bibliothèque</button>' +
        '<button type="button" class="lumia-wl-icon-tab" data-tab="media">Médiathèque</button>' +
        '<button type="button" class="lumia-wl-icon-tab" data-tab="code">Code SVG</button>' +
      "</div>" +

      '<div class="lumia-wl-icon-pane" data-pane="library">' +
        '<div class="lumia-wl-icon-search-wrap">' +
          '<input type="search" class="lumia-input lumia-wl-icon-search" id="lumia-ip-search" ' +
            'placeholder="Rechercher une icône…" autocomplete="off">' +
        "</div>" +
        '<div class="lumia-wl-icon-scroll" id="lumia-ip-lib">' + buildIconGridHtml() + "</div>" +
      "</div>" +

      '<div class="lumia-wl-icon-pane" data-pane="media" style="display:none"><div class="lumia-wl-icon-pane__body">' +
        '<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary" id="lumia-ip-media-btn">' +
          "Ouvrir la médiathèque</button>" +
        '<div class="lumia-wl-icon-media-preview" id="lumia-ip-media-prev"' +
          (imgSrc ? "" : ' style="display:none"') + ">" +
          (imgSrc ? iconMarkup(imgSrc, "lumia-wl-icon-media-preview__i") : "") +
        "</div>" +
      "</div></div>" +

      '<div class="lumia-wl-icon-pane" data-pane="code" style="display:none"><div class="lumia-wl-icon-pane__body">' +
        '<textarea class="lumia-input lumia-wl-icon-code" id="lumia-ip-code" rows="5" ' +
          'placeholder="&lt;svg …&gt;…&lt;/svg&gt;"></textarea>' +
        '<p class="lumia-form__help">Collez le code d\'un SVG (Lucide, Heroicons…). Il est nettoyé ' +
          'côté serveur : scripts, liens externes et entités sont retirés. Un tracé en ' +
          '<code>currentColor</code> se colore automatiquement au thème du menu.</p>' +
        '<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--primary" id="lumia-ip-code-btn">' +
          "Utiliser ce SVG</button>" +
      "</div></div>" +

      '<div class="lumia-wl-icon-picker-footer">' +
        '<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary" id="lumia-ip-default-btn">' +
          (resetPreview ? '<span class="lumia-wl-icon-reset-thumb">' + resetPreview + "</span>" : "") +
          esc(resetLabel) +
        "</button>" +
      "</div>"
    );
  }

  function bindIconDropdown(el, item, triggerBtn) {
    el.querySelectorAll(".lumia-wl-icon-tab").forEach(function (tab) {
      tab.addEventListener("click", function () {
        el.querySelectorAll(".lumia-wl-icon-tab").forEach(function (t) { t.classList.remove("is-active"); });
        tab.classList.add("is-active");
        el.querySelectorAll(".lumia-wl-icon-pane").forEach(function (p) { p.style.display = "none"; });
        var pane = el.querySelector('[data-pane="' + tab.dataset.tab + '"]');
        if (pane) pane.style.display = "";
        if (tab.dataset.tab === "library") {
          var s = el.querySelector("#lumia-ip-search");
          if (s) s.focus();
        }
      });
    });

    function applyIcon(value) {
      item.icon = value;
      setDirty(true);
      refreshIconBtn(triggerBtn, item);
      renderTree();
      hideIconPicker();
    }

    el.querySelectorAll(".lumia-wl-icon-grid-item").forEach(function (btn) {
      btn.addEventListener("click", function () { applyIcon(btn.dataset.iconVal); });
    });

    // Recherche : on masque/affiche les cellules déjà rendues plutôt que de
    // reconstruire la grille — 150 icônes, chaque frappe recréerait autant
    // de nœuds et perdrait le focus.
    var search = el.querySelector("#lumia-ip-search");
    if (search) {
      search.addEventListener("input", function () {
        // Chaque mot saisi doit être trouvé : « carte bancaire » ne doit pas
        // ramener toutes les cartes, mais « bancaire carte » doit marcher.
        var words = foldAccents(search.value.trim()).split(/\s+/).filter(Boolean);
        var none = true;
        el.querySelectorAll(".lumia-wl-icon-cat").forEach(function (cat) {
          var shown = 0;
          cat.querySelectorAll(".lumia-wl-icon-grid-item").forEach(function (b) {
            var terms = b.dataset.iconTerms || b.dataset.iconName || "";
            var hit = !words.length || words.every(function (w) { return terms.indexOf(w) !== -1; });
            b.style.display = hit ? "" : "none";
            if (hit) shown++;
          });
          cat.style.display = shown ? "" : "none";
          // Pendant une recherche, les en-têtes de catégorie n'apportent rien.
          var title = cat.querySelector(".lumia-wl-icon-cat__title");
          if (title) title.style.display = words.length ? "none" : "";
          if (shown) none = false;
        });
        var empty = el.querySelector("#lumia-ip-no-result");
        if (empty) empty.style.display = none ? "" : "none";
      });
      search.addEventListener("keydown", function (e) {
        // Entrée = choisir la première icône visible.
        if (e.key !== "Enter") return;
        e.preventDefault();
        var first = Array.prototype.find.call(
          el.querySelectorAll(".lumia-wl-icon-grid-item"),
          function (b) { return b.style.display !== "none"; }
        );
        if (first) applyIcon(first.dataset.iconVal);
      });
    }

    var codeBtn = el.querySelector("#lumia-ip-code-btn");
    var codeEl  = el.querySelector("#lumia-ip-code");
    if (codeBtn && codeEl) {
      codeBtn.addEventListener("click", function () {
        var raw = codeEl.value.trim();
        if (!raw) { toast("Collez le code d'un SVG.", "error"); return; }
        codeBtn.disabled = true;
        // L'assainissement est fait côté serveur : ce que l'éditeur stocke est
        // le SVG nettoyé qu'il renvoie, jamais la chaîne collée telle quelle.
        ajaxPost("lumia_wl_sanitize_svg", { svg: raw }, function (data) {
          codeBtn.disabled = false;
          if (!data || !data.success) {
            toast((data && data.data && data.data.message) || "SVG refusé.", "error");
            return;
          }
          applyIcon(data.data.icon);
          toast("Icône SVG appliquée.", "success");
        });
      });
    }
    var defBtn = el.querySelector("#lumia-ip-default-btn");
    if (defBtn) defBtn.addEventListener("click", function () { applyIcon(null); });
    var mediaBtn  = el.querySelector("#lumia-ip-media-btn");
    var mediaPrev = el.querySelector("#lumia-ip-media-prev");
    if (mediaBtn && typeof wp !== "undefined" && wp.media) {
      mediaBtn.addEventListener("click", function () {
        var frame = wp.media({ title: "Choisir une icône", multiple: false });
        frame.on("select", function () {
          var att = frame.state().get("selection").first().toJSON();
          if (mediaPrev) {
            mediaPrev.innerHTML = iconMarkup(att.url, "lumia-wl-icon-media-preview__i");
            mediaPrev.style.display = "";
          }
          applyIcon(att.url);
        });
        frame.open();
      });
    }
  }

  function refreshIconBtn(btn, item) {
    if (!btn) return;
    var thumb = btn.querySelector(".lumia-wl-icon-btn-thumb");
    var label = btn.querySelector("span:last-child");
    if (thumb) thumb.innerHTML = buildIconThumbInner(item.icon, item._wpIcon);
    if (label) label.textContent = item.icon ? "Modifier" : "Choisir une icône";
  }

  /* ================================================================
   * SAUVEGARDE
   * ================================================================ */

  function onSave() {
    if (!ed.profile) return;
    var saveBtn = document.getElementById("lumia-mc-save-panel-btn");
    if (saveBtn) saveBtn.disabled = true;

    ajaxPost("lumia_wl_save_profile", { profile: JSON.stringify(collectProfile()) }, function (data) {
      if (data && data.success) {
        var saved  = data.data.profile;
        var wasNew = ed.profile.id === "__new__";
        setDirty(false);

        ensureUids(saved.items || []);
        restoreRuntimeProps(saved.items || [], ed.profile.items || []);
        ed.profile = saved;

        if (wasNew) {
          lumiaAdmin.mcProfiles = lumiaAdmin.mcProfiles || [];
          lumiaAdmin.mcProfiles.push(saved);
        } else {
          lumiaAdmin.mcProfiles = (lumiaAdmin.mcProfiles || []).map(function (p) {
            return p.id === saved.id ? saved : p;
          });
        }
        renderProfilesSidebar();
        toast("Menu enregistré.", "success");

        try { sessionStorage.setItem("lumia_mc_open_profile", saved.id); } catch (e) {}
        setTimeout(function () { window.location.reload(); }, 800);
      } else {
        if (saveBtn) saveBtn.disabled = false;
        toast("Erreur lors de la sauvegarde.", "error");
      }
    });
  }

  /* ================================================================
   * COLLECTE
   * ================================================================ */

  function collectProfile() {
    var nameEl    = document.getElementById("lumia-wl-profile-name");
    var statusAct = document.getElementById("lumia-wl-status-active");
    var applyAll  = document.getElementById("lumia-wl-apply-all");
    var incVals   = ms.include ? ms.include.getValue() : { roles: [], users: [] };
    var excVals   = ms.exclude ? ms.exclude.getValue() : { roles: [], users: [] };
    return {
      id:            ed.profile.id === "__new__" ? "" : (ed.profile.id || ""),
      name:          nameEl ? nameEl.value.trim() : (ed.profile.name || ""),
      status:        statusAct && statusAct.classList.contains("is-active") ? "active" : "draft",
      apply_to_all:  !!(applyAll && applyAll.checked),
      include_roles: incVals.roles,
      include_users: incVals.users,
      exclude_roles: excVals.roles,
      exclude_users: excVals.users,
      items:         ed.profile.items.map(serializeItem),
      updated_at:    0,
    };
  }

  function serializeItem(item) {
    return {
      type:         item.type,
      slug:         item.slug,
      label:        item.label || null,
      icon:         item.icon  || null,
      visible:      item.visible !== false,
      block_access: item.visible === false && !!item.block_access,
      target_blank: !!item.target_blank,
      url:          item.url   || "",
      roles:        item.type === "custom_link" ? (item.roles || []) : [],
      children: (item.children || []).map(function (c) {
        return {
          type: c.type, slug: c.slug, label: c.label || null, icon: c.icon || null,
          visible: c.visible !== false,
          block_access: c.visible === false && !!c.block_access,
          target_blank: !!c.target_blank, url: "", children: [],
        };
      }),
    };
  }

  /* ================================================================
   * MULTI-SELECT
   * ================================================================ */

  function buildInitialChips(roles, users) {
    var wpRoles = lumiaAdmin.wpRoles     || {};
    var recent  = lumiaAdmin.wpRecentUsers || [];
    var sel = [];
    roles.forEach(function (k) { sel.push({ id: "role:" + k, rawId: k, label: wpRoles[k] || k, type: "role" }); });
    users.forEach(function (uid) {
      var u = recent.find(function (r) { return r.id === uid; });
      sel.push({ id: "user:" + uid, rawId: uid, label: u ? u.label : "#" + uid, type: "user" });
    });
    return sel;
  }

  /**
   * @param {object} [opts] rolesOnly : n'expose que les rôles (restriction d'un
   *   lien personnalisé — un lien de menu ne se cible pas par utilisateur).
   *   onChange : appelé après chaque ajout/retrait, avec la valeur courante.
   */
  function createMultiSelect(containerId, initialSelected, opts) {
    var container = document.getElementById(containerId);
    if (!container) return null;
    opts = opts || {};
    var widget = { selected: initialSelected || [], results: [], open: false, timer: null,
                   container: container, rolesOnly: !!opts.rolesOnly };

    // Structure persistante. L'input n'est JAMAIS recréé : le rebuild complet
    // de l'ancienne version détruisait l'input focalisé, ce qui déclenchait un
    // blur → le dropdown se refermait aussitôt (« pas le temps de cliquer »).
    container.innerHTML =
      '<div class="lumia-wl-ms-tags"></div>' +
      '<div class="lumia-wl-ms-dropdown" style="display:none"></div>';
    var tagsEl     = container.querySelector(".lumia-wl-ms-tags");
    var dropdownEl = container.querySelector(".lumia-wl-ms-dropdown");
    var input      = document.createElement("input");
    input.type        = "text";
    input.className   = "lumia-wl-ms-input";
    input.placeholder = "Rechercher…";
    tagsEl.appendChild(input);

    widget.getValue = function () {
      return {
        roles: widget.selected.filter(function (s) { return s.type === "role"; }).map(function (s) { return s.rawId; }),
        users: widget.selected.filter(function (s) { return s.type === "user"; }).map(function (s) { return s.rawId; }),
      };
    };

    function renderChips() {
      Array.prototype.slice.call(tagsEl.querySelectorAll(".lumia-wl-chip")).forEach(function (c) { c.remove(); });
      widget.selected.forEach(function (s) {
        var chip = document.createElement("span");
        chip.className = "lumia-wl-chip";
        chip.innerHTML = esc(s.label) +
          '<button type="button" class="lumia-wl-chip__remove" data-id="' + esc(s.id) + '">' + (L.x || "×") + "</button>";
        tagsEl.insertBefore(chip, input);
      });
    }

    function renderDropdown() {
      dropdownEl.innerHTML = msDropdownHtml(widget);
      dropdownEl.style.display = widget.open ? "" : "none";
    }

    widget.render = function () { renderChips(); renderDropdown(); };
    widget.close  = function () { if (!widget.open) return; widget.open = false; renderDropdown(); };

    // Délégation : chips et options sont recréés à chaque rendu, on écoute
    // donc au niveau du container (une seule fois, pas de fuite de listeners).
    container.addEventListener("mousedown", function (e) {
      var rm = e.target.closest(".lumia-wl-chip__remove");
      if (rm) {
        e.preventDefault();
        widget.selected = widget.selected.filter(function (s) { return s.id !== rm.dataset.id; });
        setDirty(true);
        if (opts.onChange) opts.onChange(widget.getValue());
        widget.render();
        input.focus();
        return;
      }
      var opt = e.target.closest(".lumia-wl-ms-option");
      if (opt) {
        e.preventDefault(); // conserve le focus de l'input
        if (!widget.selected.find(function (s) { return s.id === opt.dataset.id; })) {
          var rawId = opt.dataset.type === "user" ? parseInt(opt.dataset.raw, 10) : opt.dataset.raw;
          widget.selected.push({ id: opt.dataset.id, rawId: rawId, label: opt.dataset.label, type: opt.dataset.type });
          setDirty(true);
          if (opts.onChange) opts.onChange(widget.getValue());
        }
        widget.render(); // reste ouvert pour permettre les ajouts multiples
        input.focus();
        return;
      }
      // Clic dans la zone de tags (hors chip) → focus l'input.
      if (e.target === tagsEl || e.target === container) {
        input.focus();
      }
    });

    input.addEventListener("focus", function () { widget.open = true; widget.search(input.value || ""); });
    input.addEventListener("click", function () { if (!widget.open) { widget.open = true; widget.search(input.value || ""); } });
    input.addEventListener("input", function () {
      clearTimeout(widget.timer);
      widget.timer = setTimeout(function () { widget.search(input.value); }, 300);
    });
    widget.search = function (q) {
      var wpRoles = lumiaAdmin.wpRoles || {};
      var res = [];
      for (var k in wpRoles) {
        if (!Object.prototype.hasOwnProperty.call(wpRoles, k)) continue;
        if (!q || wpRoles[k].toLowerCase().indexOf(q.toLowerCase()) !== -1) {
          res.push({ id: "role:" + k, rawId: k, label: wpRoles[k], type: "role" });
        }
      }
      if (widget.rolesOnly) {
        widget.results = res;
        widget.render();
        return;
      }
      (lumiaAdmin.wpRecentUsers || []).filter(function (u) {
        return !q || u.label.toLowerCase().indexOf(q.toLowerCase()) !== -1;
      }).forEach(function (u) {
        res.push({ id: "user:" + u.id, rawId: u.id, label: u.label, type: "user" });
      });
      widget.results = res;
      widget.render();
      if (q.length >= 2) {
        var xhr = new XMLHttpRequest();
        xhr.open("GET", lumiaAdmin.ajaxUrl + "?action=lumia_wl_search_users&nonce=" +
          encodeURIComponent(lumiaAdmin.nonce) + "&q=" + encodeURIComponent(q));
        xhr.onload = function () {
          try {
            var d = JSON.parse(xhr.responseText);
            if (d.success && d.data) {
              d.data.forEach(function (u) {
                if (!res.find(function (r) { return r.id === "user:" + u.id; })) {
                  res.push({ id: "user:" + u.id, rawId: u.id, label: u.label, type: "user" });
                }
              });
              widget.results = res;
              widget.render();
            }
          } catch (e) { /* ignore */ }
        };
        xhr.send();
      }
    };
    widget.render();
    return widget;
  }

  function msDropdownHtml(widget) {
    var selIds  = widget.selected.map(function (s) { return s.id; });
    var options = widget.results.filter(function (r) { return selIds.indexOf(r.id) === -1; });
    if (!options.length) return '<div class="lumia-wl-ms-empty">Aucun résultat.</div>';
    return options.map(function (r) {
      var badge = r.type === "role"
        ? '<span class="lumia-badge lumia-badge--info">Rôle</span>'
        : '<span class="lumia-badge lumia-badge--inactive">Utilisateur</span>';
      return '<div class="lumia-wl-ms-option" data-id="' + esc(r.id) + '" data-label="' + esc(r.label) +
        '" data-type="' + esc(r.type) + '" data-raw="' + esc(String(r.rawId)) + '">' +
        esc(r.label) + " " + badge + "</div>";
    }).join("");
  }

  /* ================================================================
   * UTILITAIRES
   * ================================================================ */

  function ajaxPost(action, data, cb) {
    var body = "action=" + encodeURIComponent(action) + "&nonce=" + encodeURIComponent(lumiaAdmin.nonce);
    for (var k in data) {
      if (Object.prototype.hasOwnProperty.call(data, k)) {
        body += "&" + encodeURIComponent(k) + "=" + encodeURIComponent(data[k]);
      }
    }
    var xhr = new XMLHttpRequest();
    xhr.open("POST", lumiaAdmin.ajaxUrl);
    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    xhr.onload  = function () { try { cb(JSON.parse(xhr.responseText)); } catch (e) { cb(null); } };
    xhr.onerror = function () { cb(null); };
    xhr.send(body);
  }

  function toast(msg, type) {
    if (typeof window.lumiaShowToast === "function") window.lumiaShowToast(msg, type || "success");
  }

  function findProfileById(id) {
    return (lumiaAdmin.mcProfiles || []).find(function (p) { return p.id === id; }) || null;
  }

  function findByUid(uid) {
    if (!ed.profile) return null;
    for (var i = 0; i < ed.profile.items.length; i++) {
      if (ed.profile.items[i]._uid === uid) return ed.profile.items[i];
      var ch = ed.profile.items[i].children || [];
      for (var j = 0; j < ch.length; j++) {
        if (ch[j]._uid === uid) return ch[j];
      }
    }
    return null;
  }

  function findWpItem(slug) {
    return (lumiaAdmin.wpMenu || []).find(function (m) { return m.slug === slug; }) || null;
  }

  function findWpSub(parentSlug, slug) {
    return ((lumiaAdmin.wpSubmenu || {})[parentSlug] || [])
      .find(function (s) { return s.slug === slug; }) || null;
  }

  function ensureUids(items) {
    (items || []).forEach(function (i) { if (!i._uid) i._uid = genUid(); ensureUids(i.children || []); });
  }

  function restoreRuntimeProps(newItems, prevItems) {
    newItems.forEach(function (item, i) {
      var prev = prevItems[i] || {};
      item._uid     = item._uid     || prev._uid     || genUid();
      item._wpLabel = item._wpLabel || prev._wpLabel || "";
      item._wpIcon  = item._wpIcon  || prev._wpIcon  || "";
      (item.children || []).forEach(function (c, j) {
        var pc = (prev.children || [])[j] || {};
        c._uid     = c._uid     || pc._uid     || genUid();
        c._wpLabel = c._wpLabel || pc._wpLabel || "";
      });
    });
  }

  function setDisplay(id, v) { var el = document.getElementById(id); if (el) el.style.display = v; }
  function show(id) { var el = document.getElementById(id); if (el) el.style.display = ""; }
  function hide(id) { var el = document.getElementById(id); if (el) el.style.display = "none"; }

  var _uid = 0;
  function genUid() { return "u" + (++_uid); }
  function deepCopy(o) { return JSON.parse(JSON.stringify(o)); }
  function stripTags(s) { return String(s || "").replace(/<[^>]*>/g, "").trim(); }

  /**
   * Fallback lisible quand ni label ni _wpLabel ne sont disponibles
   * (item masqué et mergeWpMenu pas encore passé, ou menu WP introuvable) :
   * extrait le morceau utile du slug ("edit.php?post_type=product" → "Product")
   * plutôt que d'afficher la chaîne technique brute.
   */
  function prettifySlug(slug) {
    var raw = String(slug || "");
    if (!raw) return "";

    var qIndex = raw.indexOf("?");
    var base   = qIndex === -1 ? raw : raw.slice(0, qIndex);
    var query  = qIndex === -1 ? ""  : raw.slice(qIndex + 1);
    var label  = base.replace(/\.php$/, "");

    if (query) {
      var params = {};
      query.split("&").forEach(function (pair) {
        var kv = pair.split("=");
        if (kv[0]) params[decodeURIComponent(kv[0])] = decodeURIComponent(kv[1] || "");
      });
      if (params.post_type) label = params.post_type;
      else if (params.page) label = params.page;
    }

    label = label.replace(/[-_]+/g, " ").trim();
    if (!label) label = raw;

    return label.replace(/\b\w/g, function (c) { return c.toUpperCase(); });
  }
  function esc(s) {
    return String(s || "")
      .replace(/&/g, "&amp;").replace(/</g, "&lt;")
      .replace(/>/g, "&gt;").replace(/"/g, "&quot;");
  }
})();
