/**
 * Module Médias — dossiers virtuels dans la médiathèque WordPress.
 *
 * Deux points de montage, un seul composant :
 *   1. Extension de wp.media.view.AttachmentsBrowser → la sidebar apparaît
 *      partout où WordPress affiche une médiathèque (upload.php en grille,
 *      modales d'insertion, Gutenberg, constructeurs frontend type Bricks),
 *      sans qu'on déplace le moindre nœud du DOM de WordPress.
 *   2. Montage autonome dans upload.php?mode=list, où wp.media n'est pas
 *      chargé du tout et où la liste est une WP_List_Table classique.
 *
 * Le panneau (FolderPanel) est agnostique : il parle à une « cible » qui sait
 * lire et écrire le dossier courant. En grille c'est un prop de la collection
 * Backbone, en vue liste c'est un paramètre d'URL.
 *
 * Le filtrage est intégralement serveur (voir Module.php) : aucune liste d'IDs
 * ne transite, la pagination et le scroll infini natifs restent intacts.
 *
 * Dépendances : jquery, admin.js (modales nommées), notifications.js
 * (window.lumiaShowToast), sortable.min.js. media-views uniquement en grille.
 */
(function ($) {
  "use strict";

  var cfg = window.lumiaMedia || {};
  if (!cfg.ajaxUrl) return;

  var i18n = cfg.i18n || {};
  var QUERY_VAR = cfg.queryVar || "lumia_folder";
  var UNASSIGNED = cfg.unassigned || "__none__";
  var COLORS = cfg.colors || [];
  var COLOR_LABELS = cfg.colorLabels || {};

  /**
   * L'utilisateur peut-il modifier l'ARBORESCENCE (créer, renommer, supprimer,
   * déplacer, colorer un dossier) ?
   *
   * Miroir de Media\Module::CAP_MANAGE. Purement cosmétique : la décision
   * appartient à guard_manage() côté serveur, qui ne lit rien de ce que le
   * client envoie. On s'en sert pour ne pas montrer des commandes qui ne
   * feraient que renvoyer un refus.
   *
   * PIÈGE — wp_localize_script() convertit TOUTES les valeurs en chaînes : un
   * `false` PHP arrive ici en `""`, et un `true` en `"1"`. Un test du genre
   * `cfg.canManage !== false` serait donc toujours vrai, et le drapeau
   * n'aurait jamais rien masqué. On compare aux deux formes possibles.
   */
  var CAN_MANAGE = cfg.canManage === true || cfg.canManage === "1";

  /** Valeur du filtre « aucun dossier sélectionné ». */
  var ALL = "";

  /* ================================================================
   * HELPERS
   * ================================================================ */

  function t(key, fallback) {
    return i18n[key] || fallback || key;
  }

  function escHtml(str) {
    var d = document.createElement("div");
    d.textContent = str == null ? "" : String(str);
    return d.innerHTML;
  }

  function toast(message, type) {
    if (typeof window.lumiaShowToast === "function") {
      window.lumiaShowToast(message, type || "success");
    }
  }

  // Le conteneur de toasts vit dans le layout LUMIA, absent des pages natives.
  function ensureToastContainer() {
    if (document.getElementById("lumia-toast-container")) return;
    var c = document.createElement("div");
    c.id = "lumia-toast-container";
    c.className = "lumia-toast-container";
    c.setAttribute("role", "region");
    c.setAttribute("aria-live", "polite");
    document.body.appendChild(c);
  }

  function ajax(action, data) {
    var fd = new FormData();
    fd.append("action", action);
    fd.append("nonce", cfg.nonce);
    Object.keys(data || {}).forEach(function (k) {
      if (Array.isArray(data[k])) {
        data[k].forEach(function (v) { fd.append(k + "[]", v); });
      } else {
        fd.append(k, data[k]);
      }
    });

    return fetch(cfg.ajaxUrl, { method: "POST", credentials: "same-origin", body: fd })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res && res.success) return res.data || {};
        var msg = (res && res.data && res.data.message) || "Erreur";
        toast(msg, "error");
        return Promise.reject(new Error(msg));
      })
      .catch(function (err) {
        if (!(err instanceof Error)) toast("Erreur réseau", "error");
        throw err;
      });
  }

  /* ================================================================
   * STORE — une seule source de vérité pour tous les panneaux montés
   * (une page peut afficher deux médiathèques : la grille et une modale).
   * ================================================================ */

  var store = { folders: [], unorganized: 0, loading: null, listeners: [] };

  function onChange(fn) {
    store.listeners.push(fn);
    return function () {
      store.listeners = store.listeners.filter(function (f) { return f !== fn; });
    };
  }

  function commit(data) {
    if (data && data.folders) {
      store.folders = data.folders;
      store.unorganized = data.unorganized || 0;
    }
    store.listeners.forEach(function (fn) { fn(); });
    return data;
  }

  function loadFolders() {
    if (store.loading) return store.loading;
    store.loading = ajax("lumia_media_get_folders", {})
      .then(commit)
      .catch(function () { return null; })
      .then(function (r) { store.loading = null; return r; });
    return store.loading;
  }

  function findFolder(id) {
    id = parseInt(id, 10);
    return store.folders.filter(function (f) { return parseInt(f.id, 10) === id; })[0] || null;
  }

  /* ================================================================
   * ICÔNES (Lucide, inline)
   * ================================================================ */

  var ICON_GRID = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/></svg>';
  var ICON_FOLDER = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/></svg>';
  var ICON_DOTS = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></svg>';
  var ICON_PLUS = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="M5 12h14"/></svg>';

  // Toutes ces icônes sont du Lucide non modifié (layout-grid, folder, ellipsis,
  // plus). Ne jamais en bricoler une : demander le SVG (voir CLAUDE.md).
  // « Non classés » utilise le folder standard — folder-dashed n'existe pas chez
  // Lucide ; la distinction se fait par la teinte atténuée (media.css).
  function folderIcon(value) {
    if (value === ALL) return ICON_GRID;
    return ICON_FOLDER;
  }

  /* ================================================================
   * MODALES — créées une seule fois, dans <body>
   * ================================================================ */

  var modals = { ready: false, parent: 0, rename: 0, remove: 0, onDone: null };

  function modalBlock(id, titleKey, titleFallback, body, confirmId, confirmKey, confirmFallback, danger) {
    return '<div id="' + id + '" class="lumia-modal-overlay lumia-media-modal" role="dialog" aria-modal="true" aria-labelledby="' + id + '-title">' +
      '<div class="lumia-modal">' +
      '<div class="lumia-modal__header"><h3 id="' + id + '-title" class="lumia-modal__title">' + escHtml(t(titleKey, titleFallback)) + "</h3></div>" +
      '<div class="lumia-modal__body">' + body + "</div>" +
      '<div class="lumia-modal__footer">' +
      '<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary lumia-modal-close">' + escHtml(t("cancel", "Annuler")) + "</button>" +
      '<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--' + (danger ? "danger" : "primary") + '" id="' + confirmId + '">' + escHtml(t(confirmKey, confirmFallback)) + "</button>" +
      "</div></div></div>";
  }

  function field(inputId) {
    return '<div class="lumia-form__group">' +
      '<label class="lumia-form__label" for="' + inputId + '">' + escHtml(t("folderName", "Nom du dossier")) + "</label>" +
      '<input type="text" class="lumia-input" id="' + inputId + '" autocomplete="off">' +
      "</div>";
  }

  function ensureModals() {
    if (modals.ready) return;
    modals.ready = true;

    var host = document.createElement("div");
    host.id = "lumia-media-modals";
    host.innerHTML =
      modalBlock("lumia-media-modal-new-folder", "newFolder", "Nouveau dossier",
        field("lumia-media-new-folder-name"), "lumia-media-create-folder-confirm", "create", "Créer", false) +
      modalBlock("lumia-media-modal-rename", "rename", "Renommer",
        field("lumia-media-rename-name"), "lumia-media-rename-confirm", "save", "Enregistrer", false) +
      modalBlock("lumia-media-modal-delete", "deleteFolder", "Supprimer le dossier ?",
        "<p>" + escHtml(t("deleteFolderMsg", "")) + "</p>", "lumia-media-delete-confirm", "delete", "Supprimer", true);
    document.body.appendChild(host);

    var nameInput = document.getElementById("lumia-media-new-folder-name");
    var renameInput = document.getElementById("lumia-media-rename-name");

    function submitCreate() {
      var name = nameInput.value.trim();
      if (!name) return;
      ajax("lumia_media_create_folder", { name: name, parent_id: modals.parent }).then(function (data) {
        window.lumiaModalClose("lumia-media-modal-new-folder");
        commit(data);
        toast(t("folderCreated", "Dossier créé."), "success");
      });
    }

    function submitRename() {
      var name = renameInput.value.trim();
      if (!name || !modals.rename) return;
      ajax("lumia_media_rename_folder", { id: modals.rename, name: name }).then(function (data) {
        window.lumiaModalClose("lumia-media-modal-rename");
        commit(data);
        toast(t("folderRenamed", "Dossier renommé."), "success");
      });
    }

    function submitDelete() {
      if (!modals.remove) return;
      var removed = modals.remove;
      ajax("lumia_media_delete_folder", { id: removed }).then(function (data) {
        window.lumiaModalClose("lumia-media-modal-delete");
        modals.remove = 0;
        commit(data);
        if (typeof modals.onDone === "function") modals.onDone(removed);
        toast(t("folderDeleted", "Dossier supprimé."), "success");
      });
    }

    document.getElementById("lumia-media-create-folder-confirm").addEventListener("click", submitCreate);
    document.getElementById("lumia-media-rename-confirm").addEventListener("click", submitRename);
    document.getElementById("lumia-media-delete-confirm").addEventListener("click", submitDelete);

    [[nameInput, submitCreate], [renameInput, submitRename]].forEach(function (pair) {
      pair[0].addEventListener("keydown", function (e) {
        if (e.key === "Enter") { e.preventDefault(); pair[1](); }
      });
    });
  }

  function openCreateModal(parentId) {
    ensureModals();
    modals.parent = parentId || 0;
    var input = document.getElementById("lumia-media-new-folder-name");
    input.value = "";
    window.lumiaModalOpen("lumia-media-modal-new-folder");
    input.focus();
  }

  function openRenameModal(folderId) {
    ensureModals();
    modals.rename = folderId;
    var folder = findFolder(folderId);
    var input = document.getElementById("lumia-media-rename-name");
    input.value = folder ? folder.name : "";
    window.lumiaModalOpen("lumia-media-modal-rename");
    input.focus();
    input.select();
  }

  function openDeleteModal(folderId, onDone) {
    ensureModals();
    modals.remove = folderId;
    modals.onDone = onDone;
    window.lumiaModalOpen("lumia-media-modal-delete");
  }

  /* ================================================================
   * DROPDOWN d'actions (couleur / renommer / sous-dossier / supprimer)
   * ================================================================ */

  function closeDropdown() {
    var existing = document.querySelector(".lumia-media-dropdown");
    if (existing && existing.parentNode) existing.parentNode.removeChild(existing);
  }

  document.addEventListener("click", function (e) {
    if (!e.target.closest(".lumia-media-folder__menu-btn") && !e.target.closest(".lumia-media-dropdown")) {
      closeDropdown();
    }
  });
  window.addEventListener("scroll", closeDropdown, true);

  function openDropdown(btn, folderId, panel) {
    var already = document.querySelector(".lumia-media-dropdown");
    var wasSame = already && already.dataset.folderId === String(folderId);
    closeDropdown();
    if (wasSame) return; // toggle

    var folder = findFolder(folderId) || {};

    var swatches = COLORS.map(function (c) {
      var isNone = c === "";
      var isSel = (folder.color || "") === c;
      var label = isNone ? t("defaultColor", "Par défaut") : (COLOR_LABELS[c] || c);
      return '<button type="button" class="lumia-media-swatch' + (isNone ? " is-none" : "") + (isSel ? " is-selected" : "") +
        '" data-color="' + escHtml(c) + '" ' + (c ? 'style="background:' + escHtml(c) + '" ' : "") +
        'title="' + escHtml(label) + '" ' +
        'data-lumia-tip="' + escHtml(label) + '" aria-label="' + escHtml(label) + '"></button>';
    }).join("");

    var dd = document.createElement("div");
    dd.className = "lumia-media-dropdown";
    dd.dataset.folderId = String(folderId);
    dd.innerHTML =
      '<div class="lumia-media-dropdown__label">' + escHtml(t("color", "Couleur")) + "</div>" +
      '<div class="lumia-media-dropdown__colors">' + swatches + "</div>" +
      '<div class="lumia-media-dropdown__sep"></div>' +
      '<button type="button" class="lumia-media-dropdown__action" data-action="rename">' + escHtml(t("rename", "Renommer")) + "</button>" +
      '<button type="button" class="lumia-media-dropdown__action" data-action="subfolder">' + escHtml(t("newSubfolder", "Nouveau sous-dossier")) + "</button>" +
      '<button type="button" class="lumia-media-dropdown__action is-danger" data-action="delete">' + escHtml(t("delete", "Supprimer")) + "</button>";

    document.body.appendChild(dd);

    var rect = btn.getBoundingClientRect();
    var left = window.scrollX + rect.right - dd.getBoundingClientRect().width;
    dd.style.left = Math.max(8, left) + "px";
    dd.style.top = window.scrollY + rect.bottom + 4 + "px";

    dd.querySelectorAll(".lumia-media-swatch").forEach(function (sw) {
      sw.addEventListener("click", function () {
        var color = sw.dataset.color;
        closeDropdown();
        ajax("lumia_media_set_folder_color", { id: folderId, color: color }).then(function () {
          var f = findFolder(folderId);
          if (f) f.color = color;
          commit();
        });
      });
    });

    dd.querySelectorAll(".lumia-media-dropdown__action").forEach(function (b) {
      b.addEventListener("click", function () {
        var action = b.dataset.action;
        closeDropdown();
        if (action === "rename") openRenameModal(folderId);
        else if (action === "subfolder") openCreateModal(folderId);
        else if (action === "delete") {
          openDeleteModal(folderId, function (removed) {
            // Si la vue affichait le dossier supprimé, on revient à « tous ».
            if (String(panel.current()) === String(removed)) panel.select(ALL);
          });
        }
      });
    });
  }

  /* ================================================================
   * CIBLES — d'où vient et où va le dossier courant
   *
   * C'est la seule chose qui change entre la grille (collection Backbone) et
   * la vue liste (paramètre d'URL). Tout le reste du panneau est identique.
   * ================================================================ */

  function collectionTarget(collection) {
    return {
      get: function () {
        var v = collection.props.get(QUERY_VAR);
        return v === undefined || v === null ? ALL : v;
      },
      set: function (value) {
        // Backbone déclenche la requête tout seul sur changement de prop.
        if (value === ALL) collection.props.unset(QUERY_VAR);
        else collection.props.set(QUERY_VAR, value);
      },
      refresh: function () {
        if (typeof collection._requery === "function") collection._requery(true);
      },
    };
  }

  function urlTarget() {
    return {
      get: function () {
        var params = new URLSearchParams(window.location.search);
        return params.get(QUERY_VAR) || ALL;
      },
      set: function (value) {
        var url = new URL(window.location.href);
        if (value === ALL) url.searchParams.delete(QUERY_VAR);
        else url.searchParams.set(QUERY_VAR, value);
        // Changer de dossier remet la pagination à zéro.
        url.searchParams.delete("paged");
        window.location.href = url.toString();
      },
      refresh: function () { window.location.reload(); },
    };
  }

  /* ================================================================
   * PANNEAU DE DOSSIERS
   *
   * Volontairement en JS natif plutôt qu'en vue Backbone : il doit aussi
   * fonctionner sur upload.php?mode=list, où wp.media (et donc wp.Backbone)
   * n'est pas chargé.
   * ================================================================ */

  function FolderPanel(target, el) {
    this.target = target;
    this.el = el || document.createElement("div");
    this.el.classList.add("lumia-media-sidebar");
    this.el._lumiaPanel = this;
    this.unsubscribe = onChange(this.renderTree.bind(this));
    loadFolders();
  }

  FolderPanel.prototype.destroy = function () {
    if (this.unsubscribe) this.unsubscribe();
    this.destroyFolderDrag();
  };

  FolderPanel.prototype.current = function () {
    return this.target.get();
  };

  FolderPanel.prototype.select = function (value) {
    this.target.set(value);
    syncUploadTarget(value);
    this.renderTree();
  };

  FolderPanel.prototype.render = function () {
    this.el.innerHTML =
      '<div class="lumia-media-sidebar__header">' +
      '<span class="lumia-media-sidebar__title">' + escHtml(t("folders", "Dossiers")) + "</span>" +
      (CAN_MANAGE
        ? '<button type="button" class="lumia-media-sidebar__add-btn" title="' + escHtml(t("newFolder", "Nouveau dossier")) + '" data-lumia-tip="' + escHtml(t("newFolder", "Nouveau dossier")) + '" aria-label="' + escHtml(t("newFolder", "Nouveau dossier")) + '">' + ICON_PLUS + "</button>"
        : "") +
      "</div>" +
      '<div class="lumia-media-sidebar__tree"><div class="lumia-media-loading">' + escHtml(t("loading", "Chargement…")) + "</div></div>";

    var addBtn = this.el.querySelector(".lumia-media-sidebar__add-btn");
    if (addBtn) {
      addBtn.addEventListener("click", function () { openCreateModal(0); });
    }

    this.renderTree();
    return this;
  };

  FolderPanel.prototype.renderTree = function () {
    var tree = this.el.querySelector(".lumia-media-sidebar__tree");
    if (!tree) return;

    var self = this;
    var html = this.item({ value: ALL, name: t("allMedia", "Tous les médias"), count: null });
    html += this.item({ value: UNASSIGNED, name: t("unorganized", "Non classés"), count: store.unorganized });

    function walk(parentId, depth) {
      var out = "";
      store.folders
        .filter(function (f) { return parseInt(f.parent, 10) === parseInt(parentId, 10); })
        .forEach(function (child) {
          out += self.item({ value: child.id, name: child.name, count: child.count, color: child.color, depth: depth });
          out += walk(child.id, depth + 1);
        });
      return out;
    }
    html += walk(0, 0);

    tree.innerHTML = html;
    this.bindTree();
    this.bindFolderDrag();
  };

  FolderPanel.prototype.item = function (o) {
    var value = o.value;
    var isFolder = value !== ALL && value !== UNASSIGNED;
    var isActive = String(this.current()) === String(value);
    var iconStyle = o.color ? ' style="color:' + escHtml(o.color) + '"' : "";
    var count = o.count === null || o.count === undefined
      ? ""
      : '<span class="lumia-media-folder__count">' + parseInt(o.count, 10) + "</span>";
    var menu = isFolder && CAN_MANAGE
      ? '<button type="button" class="lumia-media-folder__menu-btn" data-folder-id="' + escHtml(value) + '" aria-label="Actions">' + ICON_DOTS + "</button>"
      : "";
    var indent = o.depth ? ' style="--lumia-depth:' + parseInt(o.depth, 10) + '"' : "";

    // « Non classés » est aussi une cible de dépôt, avec l'id 0 : y lâcher des
    // médias les sort de tout dossier, y lâcher un dossier le remonte à la
    // racine. Une seule cible pour les deux gestes, cohérente côté serveur
    // (folder_id 0 = aucun terme, parent_id 0 = racine).
    var droppable = isFolder || value === UNASSIGNED;
    var attrs = ' data-value="' + escHtml(value) + '"';
    if (droppable) attrs += ' data-droppable="true" data-drop-id="' + (isFolder ? parseInt(value, 10) : 0) + '"';
    if (isFolder) attrs += ' data-folder-id="' + escHtml(value) + '"';

    return '<div class="lumia-media-folder-item' + (isActive ? " is-active" : "") + '"' + indent + attrs + ">" +
      '<span class="lumia-media-folder__icon"' + iconStyle + ">" + folderIcon(value) + "</span>" +
      '<span class="lumia-media-folder__name">' + escHtml(o.name) + "</span>" +
      count + menu +
      "</div>";
  };

  FolderPanel.prototype.bindTree = function () {
    var self = this;

    this.el.querySelectorAll(".lumia-media-folder-item").forEach(function (el) {
      el.addEventListener("click", function (e) {
        if (e.target.closest(".lumia-media-folder__menu-btn")) return;
        var raw = el.dataset.value;
        self.select(raw === ALL || raw === UNASSIGNED ? raw : parseInt(raw, 10));
      });
    });

    this.el.querySelectorAll(".lumia-media-folder__menu-btn").forEach(function (btn) {
      btn.addEventListener("click", function (e) {
        e.stopPropagation();
        openDropdown(btn, parseInt(btn.dataset.folderId, 10), self);
      });
    });
  };

  /**
   * Applique un dépôt de médias résolu par hit-test.
   *
   * @param {number} folderId Dossier cible (0 = sortir de tout dossier).
   * @param {Array}  ids      Pièces jointes concernées.
   * @param {string} mode     replace | add | remove — voir ajax_move_items().
   */
  FolderPanel.prototype.dropInto = function (folderId, ids, mode) {
    var self = this;
    if (!ids.length) return;

    mode = mode || "replace";

    ajax("lumia_media_move_items", { ids: ids, folder_id: folderId, mode: mode }).then(function (data) {
      var n = (data && data.moved) || ids.length;
      commit(data);

      var key = mode === "add" ? "itemsAdded" : mode === "remove" ? "itemsRemoved" : "itemsMoved";
      toast(n + " " + t(key, "média(s) déplacé(s)."), "success");

      // Le serveur écarte les médias que l'utilisateur n'a pas le droit
      // d'éditer. Le taire ferait passer un refus légitime pour un bug.
      if (data && data.refused) {
        toast(data.refused + " " + t("itemsRefused", "média(s) ignoré(s)."), "warning");
      }

      // Un média sorti du dossier affiché doit disparaître de la vue.
      if (self.current() !== ALL) self.target.refresh();
    });
  };

  FolderPanel.prototype.moveFolder = function (folderId, parentId) {
    ajax("lumia_media_move_folder", { id: folderId, parent_id: parentId }).then(function (data) {
      commit(data);
      toast(t("folderMoved", "Dossier déplacé."), "success");
    });
  };

  FolderPanel.prototype.destroyFolderDrag = function () {
    var tree = this.el.querySelector(".lumia-media-sidebar__tree");
    if (tree && tree._lumiaSortable) {
      try { tree._lumiaSortable.destroy(); } catch (e) { /* déjà détachée */ }
      tree._lumiaSortable = null;
    }
  };

  /**
   * Rend les dossiers eux-mêmes déplaçables (re-parentage à la souris).
   *
   * Comme pour la grille, SortableJS ne sert qu'à porter le geste et la
   * vignette flottante : la cible est résolue par hit-test. `sort: false` et
   * l'absence de `group` empêchent tout réordonnancement dans la liste.
   */
  FolderPanel.prototype.bindFolderDrag = function () {
    if (typeof Sortable === "undefined") return;

    // Re-parenter un dossier est une mutation de l'arborescence : sans droit,
    // le geste n'est même pas proposé (guard_manage() le refuserait de toute
    // façon). Le dépôt de MÉDIAS dans un dossier reste ouvert, lui.
    if (!CAN_MANAGE) return;

    var tree = this.el.querySelector(".lumia-media-sidebar__tree");
    if (!tree) return;

    // renderTree() vient de remplacer le contenu : on repart d'une instance
    // propre plutôt que d'en laisser une pointer vers des nœuds détachés.
    this.destroyFolderDrag();

    var self = this;
    tree._lumiaSortable = new Sortable(tree, {
      sort: false,
      draggable: ".lumia-media-folder-item[data-folder-id]",
      forceFallback: true,
      fallbackOnBody: true,
      fallbackTolerance: 4,
      fallbackClass: "lumia-media-folder-drag",

      onStart: function (evt) {
        document.body.classList.add("lumia-media-dragging");
        dragState.folder = parseInt(evt.item.dataset.folderId, 10) || 0;
        startTracking();
      },

      onEnd: function () {
        document.body.classList.remove("lumia-media-dragging");

        var zone = stopTracking();
        var folderId = dragState.folder;
        dragState.folder = 0;

        if (!zone || !folderId) return;

        var parentId = parseInt(zone.dataset.dropId, 10);
        var folder = findFolder(folderId);
        // Déjà à cet emplacement : on évite un aller-retour serveur inutile.
        if (folder && parseInt(folder.parent, 10) === parentId) return;

        self.moveFolder(folderId, parentId);
      },
    });
  };

  /* ================================================================
   * CIBLE D'UPLOAD
   *
   * Un média téléversé depuis un dossier doit y être rangé directement. On
   * pousse le dossier courant dans les multipart_params de plupload, que
   * add_attachment relit côté PHP.
   *
   * param() est une méthode d'INSTANCE (elle lit this.uploader.settings) :
   * l'appeler sur le prototype lève une TypeError. On vise donc l'uploader
   * vivant de la frame, plus les réglages par défaut pour ceux créés ensuite.
   *
   * Isolé : un échec ici ne doit jamais empêcher la navigation entre dossiers.
   * ================================================================ */

  function syncUploadTarget(value) {
    var id = parseInt(value, 10);
    var payload = id > 0 ? String(id) : "";

    try {
      if (window._wpPluploadSettings && _wpPluploadSettings.defaults) {
        _wpPluploadSettings.defaults.multipart_params = _wpPluploadSettings.defaults.multipart_params || {};
        _wpPluploadSettings.defaults.multipart_params[QUERY_VAR] = payload;
      }

      var live = window.wp && wp.media && wp.media.frame && wp.media.frame.uploader && wp.media.frame.uploader.uploader;
      if (live && typeof live.param === "function") {
        live.param(QUERY_VAR, payload);
      }
    } catch (e) {
      if (window.console && console.warn) {
        console.warn("[LUMIA] cible d'upload non synchronisée :", e);
      }
    }
  }

  /* ================================================================
   * DRAG & DROP — source (la grille de médias) et résolution des cibles
   *
   * forceFallback:true → SortableJS gère le drag par événements souris plutôt
   * que par l'API HTML5 native, ce qui évite de déclencher le dropzone d'upload
   * de WordPress (« Déposez vos fichiers pour les téléverser »).
   * ================================================================ */

  var dragState = { ids: [], folder: 0, grid: null, point: null, hovered: null };

  /**
   * Résout le dossier survolé à partir des coordonnées du curseur.
   *
   * SortableJS n'est utilisé QUE pour la source : ses cibles de dépôt sont des
   * listes triables, sémantique inadaptée ici. Avec des dossiers de 34 px
   * empilés, son heuristique d'insertion pour listes vides
   * (emptyInsertThreshold) faisait atterrir le média dans le dossier voisin.
   *
   * La vignette flottante est en pointer-events:none (media.css), elle n'est
   * donc jamais retournée par elementFromPoint.
   */
  function zoneAt(point) {
    if (!point) return null;
    var el = document.elementFromPoint(point.x, point.y);
    return el ? el.closest('[data-droppable="true"]') : null;
  }

  /** Un dossier ne peut pas être déplacé dans lui-même ni dans sa descendance. */
  function isDescendantOf(candidateId, ancestorId) {
    var current = findFolder(candidateId);
    var guard = 0;
    while (current && guard++ < 100) {
      var parent = parseInt(current.parent, 10);
      if (parent === ancestorId) return true;
      if (!parent) return false;
      current = findFolder(parent);
    }
    return false;
  }

  function isValidTarget(zone) {
    if (!zone) return false;
    if (!dragState.folder) return true; // drag de médias : toute cible convient

    var target = parseInt(zone.dataset.dropId, 10);
    if (target === dragState.folder) return false;
    return !isDescendantOf(target, dragState.folder);
  }

  function trackPointer(e) {
    dragState.point = { x: e.clientX, y: e.clientY };

    // Relu à chaque mouvement : la touche peut être enfoncée en cours de geste.
    // Ctrl (Cmd sur Mac) = ajouter au dossier sans retirer des autres.
    dragState.additive = !!(e.ctrlKey || e.metaKey);
    document.body.classList.toggle("lumia-media-additive", dragState.additive && !dragState.folder);

    var zone = zoneAt(dragState.point);
    if (!isValidTarget(zone)) zone = null;

    if (zone === dragState.hovered) return;
    if (dragState.hovered) dragState.hovered.classList.remove("is-drop-target");
    dragState.hovered = zone;
    if (zone) zone.classList.add("is-drop-target");
  }

  function startTracking() {
    document.addEventListener("pointermove", trackPointer, true);
    document.addEventListener("mousemove", trackPointer, true);
  }

  function stopTracking() {
    document.removeEventListener("pointermove", trackPointer, true);
    document.removeEventListener("mousemove", trackPointer, true);
    if (dragState.hovered) dragState.hovered.classList.remove("is-drop-target");
    document.body.classList.remove("lumia-media-additive");

    var zone = dragState.hovered;
    dragState.hovered = null;
    dragState.point = null;
    return zone;
  }

  function makeGridDraggable(browserEl) {
    if (typeof Sortable === "undefined") return;
    var grid = browserEl.querySelector("ul.attachments");
    if (!grid || grid._lumiaSortable) return;

    // La grille est recréée à chaque requête : sans destruction explicite,
    // l'instance de l'ancienne grille reste enregistrée dans SortableJS.
    if (dragState.grid) {
      try { dragState.grid.destroy(); } catch (e) { /* déjà détachée */ }
      dragState.grid = null;
    }

    dragState.grid = grid._lumiaSortable = new Sortable(grid, {
      sort: false,
      animation: 0,
      draggable: "li.attachment",
      forceFallback: true,
      fallbackOnBody: true,
      fallbackTolerance: 4,
      fallbackClass: "lumia-media-drag-fallback",

      onStart: function (evt) {
        document.body.classList.add("lumia-media-dragging");
        var el = evt.item;
        var id = parseInt(el.dataset.id, 10);
        var selected = browserEl.querySelectorAll("li.attachment.selected");

        // Drag d'un élément déjà sélectionné → on emmène toute la sélection.
        if (selected.length > 0 && el.classList.contains("selected")) {
          dragState.ids = Array.prototype.slice.call(selected)
            .map(function (s) { return parseInt(s.dataset.id, 10); })
            .filter(Boolean);
        } else {
          dragState.ids = id ? [id] : [];
        }

        startTracking();
      },

      onEnd: function () {
        document.body.classList.remove("lumia-media-dragging");

        var zone = stopTracking();
        var ids = dragState.ids.slice();
        var additive = dragState.additive;
        dragState.ids = [];
        dragState.additive = false;

        applyItemDrop(zone, ids, additive);
      },
    });
  }

  /**
   * Rend les lignes de upload.php?mode=list déplaçables vers le panneau.
   *
   * Même dispositif qu'en grille : SortableJS ne porte que le geste, la cible
   * est résolue par hit-test. `sort: false` empêche tout réordonnancement du
   * tableau, qui n'aurait aucun sens ici.
   */
  function makeListDraggable() {
    if (typeof Sortable === "undefined") return;

    var body = document.getElementById("the-list");
    if (!body || body._lumiaSortable) return;

    body._lumiaSortable = new Sortable(body, {
      sort: false,
      animation: 0,
      draggable: "tr",
      filter: "a, input, button, label, .row-actions",
      preventOnFilter: false,
      forceFallback: true,
      fallbackOnBody: true,
      fallbackTolerance: 4,
      fallbackClass: "lumia-media-row-drag",

      onStart: function (evt) {
        document.body.classList.add("lumia-media-dragging");

        var id = rowId(evt.item);
        var checked = body.querySelectorAll('input[name="media[]"]:checked');

        // Ligne déjà cochée → on emmène toute la sélection, comme en grille.
        var isChecked = evt.item.querySelector('input[name="media[]"]:checked');
        if (checked.length > 0 && isChecked) {
          dragState.ids = Array.prototype.slice.call(checked)
            .map(function (c) { return parseInt(c.value, 10); })
            .filter(Boolean);
        } else {
          dragState.ids = id ? [id] : [];
        }

        startTracking();
      },

      onEnd: function () {
        document.body.classList.remove("lumia-media-dragging");

        var zone = stopTracking();
        var ids = dragState.ids.slice();
        var additive = dragState.additive;
        dragState.ids = [];
        dragState.additive = false;

        applyItemDrop(zone, ids, additive);
      },
    });
  }

  /** `<tr id="post-123">` → 123. */
  function rowId(tr) {
    var m = /(\d+)$/.exec(tr && tr.id ? tr.id : "");
    return m ? parseInt(m[1], 10) : 0;
  }

  /**
   * Applique un dépôt de médias, quelle que soit la source (grille ou liste).
   */
  function applyItemDrop(zone, ids, additive) {
    if (!zone || !ids.length) return;

    var sidebarEl = zone.closest(".lumia-media-sidebar");
    var panel = sidebarEl && sidebarEl._lumiaPanel;
    if (!panel) return;

    var dropId = parseInt(zone.dataset.dropId, 10);
    var mode = "replace";
    var target = dropId;

    if (dropId > 0) {
      mode = additive ? "add" : "replace";
    } else {
      // Dépôt sur « Non classés ». Depuis un dossier affiché, le geste veut
      // dire « sortir de CE dossier » — sinon on retirerait aussi le média
      // des autres dossiers auxquels il appartient. Depuis « Tous les
      // médias », il n'y a pas d'ambiguïté : on le sort de partout.
      var currentId = parseInt(panel.current(), 10);
      if (currentId > 0) {
        mode = "remove";
        target = currentId;
      }
    }

    panel.dropInto(target, ids, mode);
  }

  /* ================================================================
   * DOSSIERS D'UN MÉDIA — panneau de détails
   *
   * Le drag & drop ne dit pas dans QUELS dossiers se trouve un média, et ne
   * permet pas de l'en retirer d'un seul quand il en a plusieurs. Ce bloc
   * ajoute donc la vue inverse : depuis la fiche du média, ses dossiers.
   *
   * Les identifiants viennent du modèle Backbone lui-même (clé lumiaFolders,
   * injectée par wp_prepare_attachment_for_js côté PHP) : aucune requête
   * supplémentaire à l'ouverture de la fiche.
   * ================================================================ */

  var FIELD_CLASS = "lumia-media-attachment-folders";

  function attachmentFolderIds(model) {
    var raw = model && model.get ? model.get("lumiaFolders") : null;
    return Array.isArray(raw) ? raw.map(Number).filter(Boolean) : [];
  }

  function renderFolderField(view) {
    if (!view.model || !view.$el) return;

    var host = view.$el.find("." + FIELD_CLASS)[0];
    if (!host) {
      host = document.createElement("div");
      host.className = FIELD_CLASS;

      // Le champ se place avec les autres réglages du média. Le conteneur
      // .settings n'existe que dans la fiche deux colonnes (mode grille) ;
      // dans la barre latérale des modales, les réglages sont des enfants
      // directs de la vue, on se pose donc après le dernier d'entre eux.
      // .attachment-compat accueille les champs des extensions tierces et se
      // place en fin de réglages : on se glisse avant, avec les champs du
      // média proprement dits.
      var settings = view.$el.find(".settings").first();
      var compat = view.$el.find(".attachment-compat").first();

      if (compat.length) {
        compat.before(host);
      } else if (settings.length) {
        settings.append(host);
      } else {
        var last = view.$el.find("label.setting, .setting").last();
        if (last.length) last.after(host);
        else view.$el.append(host);
      }
    }

    var current = attachmentFolderIds(view.model);

    if (!store.folders.length) {
      host.innerHTML = '<span class="lumia-media-attachment-folders__label">' +
        escHtml(t("folders", "Dossiers")) + "</span>" +
        '<div class="lumia-media-loading">' + escHtml(t("loading", "Chargement…")) + "</div>";

      // Fiche ouverte avant que l'arborescence soit chargée : on re-rend une
      // fois arrivée, mais seulement si la vue est encore à l'écran.
      loadFolders().then(function () {
        if (view.el && view.el.isConnected) renderFolderField(view);
      });
      return;
    }

    var rows = "";
    (function walk(parentId, depth) {
      store.folders
        .filter(function (f) { return parseInt(f.parent, 10) === parseInt(parentId, 10); })
        .forEach(function (f) {
          var id = parseInt(f.id, 10);
          var checked = current.indexOf(id) !== -1 ? " checked" : "";
          rows += '<label class="lumia-media-attachment-folders__item" style="--lumia-depth:' + depth + '">' +
            '<input type="checkbox" value="' + id + '"' + checked + ">" +
            '<span class="lumia-media-folder__icon"' + (f.color ? ' style="color:' + escHtml(f.color) + '"' : "") + ">" + ICON_FOLDER + "</span>" +
            '<span class="lumia-media-attachment-folders__name">' + escHtml(f.name) + "</span>" +
            "</label>";
          walk(id, depth + 1);
        });
    })(0, 0);

    host.innerHTML = '<span class="lumia-media-attachment-folders__label">' +
      escHtml(t("folders", "Dossiers")) + "</span>" +
      '<div class="lumia-media-attachment-folders__list">' +
      (rows || '<span class="lumia-media-attachment-folders__empty">' + escHtml(t("noFolder", "Aucun dossier")) + "</span>") +
      "</div>";

    host.querySelectorAll('input[type="checkbox"]').forEach(function (box) {
      box.addEventListener("change", function () {
        toggleAttachmentFolder(view.model, parseInt(box.value, 10), box.checked, box);
      });
    });
  }

  function toggleAttachmentFolder(model, folderId, checked, box) {
    var id = parseInt(model.get("id"), 10);
    if (!id || !folderId) return;

    box.disabled = true;

    ajax("lumia_media_move_items", {
      ids: [id],
      folder_id: folderId,
      mode: checked ? "add" : "remove",
    })
      .then(function (data) {
        var next = attachmentFolderIds(model).filter(function (f) { return f !== folderId; });
        if (checked) next.push(folderId);

        // set() suffit : la fiche se re-rend, et la grille lit le même modèle.
        model.set("lumiaFolders", next);

        commit(data);
        toast(t("folderUpdated", "Dossiers mis à jour."), "success");
      })
      .catch(function () {
        // L'appel a échoué : la case doit refléter l'état réel, pas l'intention.
        box.checked = !checked;
      })
      .then(function () {
        box.disabled = false;
      });
  }

  /**
   * Greffe le champ sur les fiches de média.
   *
   * Appelé au DOM ready et non au parse : la fiche deux colonnes
   * (Attachment.Details.TwoColumn) est définie par media-grid.js, qui peut être
   * imprimé après nous. À ready, les deux classes existent, et aucune fiche
   * n'a encore été instanciée.
   */
  function patchDetailsViews() {
    var Attachment = window.wp && wp.media && wp.media.view && wp.media.view.Attachment;
    if (!Attachment || !Attachment.Details) return;

    function patch(owner, key) {
      var Base = owner[key];
      if (!Base || Base.prototype._lumiaFolders) return;

      owner[key] = Base.extend({
        _lumiaFolders: true,
        render: function () {
          Base.prototype.render.apply(this, arguments);
          renderFolderField(this);
          return this;
        },
      });
    }

    // Backbone recopie les propriétés statiques du parent sur l'enfant :
    // Details.TwoColumn survit au remplacement de Details, mais continue
    // d'hériter de l'ancienne classe. On la corrige donc séparément.
    var TwoColumn = Attachment.Details.TwoColumn;
    patch(Attachment, "Details");
    if (TwoColumn) {
      Attachment.Details.TwoColumn = TwoColumn;
      patch(Attachment.Details, "TwoColumn");
    }
  }

  /* ================================================================
   * HAUTEUR DE LA ZONE MÉDIATHÈQUE (mode grille uniquement)
   *
   * En modale, WordPress dimensionne déjà tout : on n'y touche pas.
   * En grille, la zone est en flux normal et grandit avec son contenu — d'où
   * une sidebar qui s'étire et une page qui défile. On lui donne la hauteur
   * réellement disponible sous l'en-tête, mesurée plutôt que devinée : le
   * décalage haut dépend de la barre d'admin, du titre et des avis éventuels.
   * ================================================================ */

  /** Plancher : en deçà, mieux vaut laisser la page défiler qu'écraser la zone. */
  var MIN_HEIGHT = 360;

  /** Vrai pour la médiathèque pleine page (upload.php), faux en modale. */
  function isGridFrame(view) {
    if (view && view.el && view.el.closest && view.el.closest(".media-modal")) return false;
    return !!document.querySelector(".media-frame.mode-grid");
  }

  function syncGridHeight() {
    var browser = document.querySelector(".media-frame.mode-grid .attachments-browser.lumia-has-folders");
    if (!browser) return;

    var footer = document.getElementById("wpfooter");

    // L'admin WordPress étire sa colonne de contenu : tant que le contenu est
    // court, le pied de page reste collé au bas de la fenêtre. Mesurer l'espace
    // sous la médiathèque dans cet état est donc dégénéré — il vaut toujours
    // « ce qu'il faut pour remplir », quelle que soit notre hauteur.
    //
    // On gonfle donc la zone le temps de la mesure : la page déborde à coup
    // sûr, le pied de page redevient positionné par le contenu, et l'espace
    // qu'il occupe (padding-bas de #wpbody-content, marges, pied lui-même)
    // devient une vraie constante. Aucun scintillement : le navigateur ne
    // repeint qu'une fois, à la fin de la fonction.
    browser.style.setProperty("--lumia-media-h", window.innerHeight * 2 + "px");

    // Coordonnées DOCUMENT (rect + scrollY) : justes même page défilée.
    var rect = browser.getBoundingClientRect();
    var docTop = rect.top + window.scrollY;

    var below = footer
      ? Math.max(0, Math.round(
          footer.getBoundingClientRect().bottom + window.scrollY - (docTop + browser.offsetHeight)
        ))
      : 24;

    var height = Math.max(MIN_HEIGHT, Math.round(window.innerHeight - docTop - below));
    browser.style.setProperty("--lumia-media-h", height + "px");
  }

  var heightTimer = null;
  function scheduleHeightSync() {
    window.clearTimeout(heightTimer);
    heightTimer = window.setTimeout(syncGridHeight, 60);
  }

  window.addEventListener("resize", scheduleHeightSync);

  /* ================================================================
   * MONTAGE 1 — extension de la vue WordPress (grille + modales)
   * ================================================================ */

  if (window.wp && wp.media && wp.media.view && wp.media.view.AttachmentsBrowser) {
    var Browser = wp.media.view.AttachmentsBrowser;

    var SidebarView = wp.media.View.extend({
      className: "lumia-media-sidebar",

      initialize: function () {
        this.panel = new FolderPanel(collectionTarget(this.collection), this.el);
      },

      render: function () {
        this.panel.render();
        return this;
      },

      remove: function () {
        this.panel.destroy();
        return wp.media.View.prototype.remove.apply(this, arguments);
      },
    });

    wp.media.view.AttachmentsBrowser = Browser.extend({
      initialize: function () {
        // En mode grille, WordPress fait défiler la PAGE et écoute le scroll
        // infini sur `document`. On veut au contraire que la grille défile dans
        // sa propre colonne, à côté d'une sidebar de hauteur fixe.
        //
        // wp.media.view.Attachments fait : scrollElement = scrollElement || this.el.
        // En le vidant, WordPress attache donc lui-même son scroll infini à
        // ul.attachments — c'est déjà ce qu'il fait dans ses modales. On ne
        // réimplémente rien, on bascule sur son autre mode natif.
        if (isGridFrame(this)) {
          this.options.scrollElement = null;
        }

        Browser.prototype.initialize.apply(this, arguments);

        ensureToastContainer();
        ensureModals();

        this.lumiaSidebar = new SidebarView({
          controller: this.controller,
          collection: this.collection,
        });

        // Enregistrée auprès du gestionnaire de vues de WordPress : elle survit
        // aux re-render du navigateur de médias. Son placement est purement CSS
        // (position absolue), l'ordre dans le DOM n'a donc pas d'importance.
        this.views.add(this.lumiaSidebar);
        this.$el.addClass("lumia-has-folders");
      },

      createAttachments: function () {
        Browser.prototype.createAttachments.apply(this, arguments);

        // La grille est recréée à chaque changement de requête : on ré-arme le
        // drag après coup, sur le tick suivant (le DOM n'est pas encore posé).
        var el = this.el;
        setTimeout(function () {
          makeGridDraggable(el);
          syncGridHeight();
        }, 0);
      },
    });
  }

  /* ================================================================
   * MONTAGE 2 — vue liste (upload.php?mode=list)
   *
   * Ici pas de wp.media : la liste est une WP_List_Table classique. On insère
   * le panneau dans .wrap et on décale le formulaire. Contrairement à la
   * grille, ce DOM n'appartient à aucun gestionnaire de vues — l'insertion est
   * donc sans risque de se faire écraser.
   * ================================================================ */

  function mountListView() {
    var wrap = document.querySelector("body.upload-php .wrap");
    var form = wrap && wrap.querySelector("#posts-filter");
    if (!wrap || !form) return;
    if (wrap.querySelector(".lumia-media-sidebar")) return;

    ensureToastContainer();
    ensureModals();

    wrap.classList.add("lumia-media-list-layout");

    // Le panneau doit être `position: sticky` pour suivre le défilement de la
    // page sans s'étirer sur toute la hauteur du tableau. Sticky n'agit que
    // dans le flux : on met donc panneau et formulaire côte à côte dans une
    // rangée flex. Déplacer #posts-filter est sans risque ici — contrairement
    // à la grille, ce DOM n'appartient à aucun gestionnaire de vues, et son id
    // (utilisé par les actions groupées) est préservé.
    var row = document.createElement("div");
    row.className = "lumia-media-list-row";
    wrap.insertBefore(row, form);

    var panel = new FolderPanel(urlTarget());
    row.appendChild(panel.el);
    row.appendChild(form);
    panel.render();
    makeListDraggable();
  }

  if (window.wp && wp.media && wp.media.view && wp.media.view.Attachment) {
    $(patchDetailsViews);
  }

  if (!(window.wp && wp.media && wp.media.view && wp.media.view.AttachmentsBrowser)) {
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", mountListView);
    } else {
      mountListView();
    }
  }

})(jQuery);
