/**
 * Media module — virtual folders in the WordPress media library.
 *
 * Two mount points, one component:
 *   1. Extension of wp.media.view.AttachmentsBrowser → the sidebar appears
 *      wherever WordPress displays a media library (upload.php in grid mode,
 *      insert modals, Gutenberg, frontend builders such as Bricks), without
 *      moving a single node of the WordPress DOM.
 *   2. Standalone mount in upload.php?mode=list, where wp.media is not
 *      loaded at all and the list is a classic WP_List_Table.
 *
 * The panel (FolderPanel) is agnostic: it talks to a "target" that knows how
 * to read and write the current folder. In grid mode it is a prop of the
 * Backbone collection, in list view it is a URL parameter.
 *
 * Filtering is entirely server-side (see Module.php): no ID list travels,
 * native pagination and infinite scroll remain intact.
 *
 * Strings come from window.lumiaMedia.i18n: this script runs on native
 * WordPress screens, where window.lumiaAdmin does not exist.
 *
 * Dependencies: jquery, admin.js (named modals), notifications.js
 * (window.lumiaShowToast), sortable.min.js. media-views only in grid mode.
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
   * May the user change the TREE (create, rename, delete,
   * move, color a folder)?
   *
   * Mirror of Media\Module::CAP_MANAGE. Purely cosmetic: the decision
   * belongs to guard_manage() on the server, which reads nothing of what the
   * client sends. It is used to avoid showing controls that would only
   * return a refusal.
   *
   * PITFALL — wp_localize_script() converts ALL values to strings: a PHP
   * `false` arrives here as `""`, and a `true` as `"1"`. A test such as
   * `cfg.canManage !== false` would therefore always be true, and the flag
   * would never have hidden anything. We compare against both possible forms.
   */
  var CAN_MANAGE = cfg.canManage === true || cfg.canManage === "1";

  /** Filter value meaning "no folder selected". */
  var ALL = "";

  /* ================================================================
   * HELPERS
   * ================================================================ */

  function t(key) {
    return i18n[key] || "";
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

  // The toast container lives in the LUMIA layout, absent from native pages.
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
        var msg = (res && res.data && res.data.message) || t("error");
        toast(msg, "error");
        return Promise.reject(new Error(msg));
      })
      .catch(function (err) {
        if (!(err instanceof Error)) toast(t("networkError"), "error");
        throw err;
      });
  }

  /* ================================================================
   * STORE — a single source of truth for all mounted panels
   * (a page may display two media libraries: the grid and a modal).
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
   * ICONS (Lucide, inline)
   * ================================================================ */

  var ICON_GRID = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/></svg>';
  var ICON_FOLDER = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/></svg>';
  var ICON_DOTS = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></svg>';
  var ICON_PLUS = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="M5 12h14"/></svg>';

  // All these icons are unmodified Lucide (layout-grid, folder, ellipsis,
  // plus). Never tinker with one: ask for the SVG (see CLAUDE.md).
  // "Unfiled" uses the standard folder — folder-dashed does not exist in
  // Lucide; the distinction is made with a muted tint (media.css).
  function folderIcon(value) {
    if (value === ALL) return ICON_GRID;
    return ICON_FOLDER;
  }

  /* ================================================================
   * MODALS — created once, inside <body>
   * ================================================================ */

  var modals = { ready: false, parent: 0, rename: 0, remove: 0, onDone: null };

  function modalBlock(id, titleKey, body, confirmId, confirmKey, danger) {
    return '<div id="' + id + '" class="lumia-modal-overlay lumia-media-modal" role="dialog" aria-modal="true" aria-labelledby="' + id + '-title">' +
      '<div class="lumia-modal">' +
      '<div class="lumia-modal__header"><h3 id="' + id + '-title" class="lumia-modal__title">' + escHtml(t(titleKey)) + "</h3></div>" +
      '<div class="lumia-modal__body">' + body + "</div>" +
      '<div class="lumia-modal__footer">' +
      '<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary lumia-modal-close">' + escHtml(t("cancel")) + "</button>" +
      '<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--' + (danger ? "danger" : "primary") + '" id="' + confirmId + '">' + escHtml(t(confirmKey)) + "</button>" +
      "</div></div></div>";
  }

  function field(inputId) {
    return '<div class="lumia-form__group">' +
      '<label class="lumia-form__label" for="' + inputId + '">' + escHtml(t("folderName")) + "</label>" +
      '<input type="text" class="lumia-input" id="' + inputId + '" autocomplete="off">' +
      "</div>";
  }

  function ensureModals() {
    if (modals.ready) return;
    modals.ready = true;

    var host = document.createElement("div");
    host.id = "lumia-media-modals";
    host.innerHTML =
      modalBlock("lumia-media-modal-new-folder", "newFolder",
        field("lumia-media-new-folder-name"), "lumia-media-create-folder-confirm", "create", false) +
      modalBlock("lumia-media-modal-rename", "rename",
        field("lumia-media-rename-name"), "lumia-media-rename-confirm", "save", false) +
      modalBlock("lumia-media-modal-delete", "deleteFolder",
        "<p>" + escHtml(t("deleteFolderMsg")) + "</p>", "lumia-media-delete-confirm", "delete", true);
    document.body.appendChild(host);

    var nameInput = document.getElementById("lumia-media-new-folder-name");
    var renameInput = document.getElementById("lumia-media-rename-name");

    function submitCreate() {
      var name = nameInput.value.trim();
      if (!name) return;
      ajax("lumia_media_create_folder", { name: name, parent_id: modals.parent }).then(function (data) {
        window.lumiaModalClose("lumia-media-modal-new-folder");
        commit(data);
        toast(t("folderCreated"), "success");
      });
    }

    function submitRename() {
      var name = renameInput.value.trim();
      if (!name || !modals.rename) return;
      ajax("lumia_media_rename_folder", { id: modals.rename, name: name }).then(function (data) {
        window.lumiaModalClose("lumia-media-modal-rename");
        commit(data);
        toast(t("folderRenamed"), "success");
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
        toast(t("folderDeleted"), "success");
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
   * Actions DROPDOWN (color / rename / subfolder / delete)
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
      var label = isNone ? t("defaultColor") : (COLOR_LABELS[c] || c);
      return '<button type="button" class="lumia-media-swatch' + (isNone ? " is-none" : "") + (isSel ? " is-selected" : "") +
        '" data-color="' + escHtml(c) + '" ' + (c ? 'style="background:' + escHtml(c) + '" ' : "") +
        'title="' + escHtml(label) + '" ' +
        'data-lumia-tip="' + escHtml(label) + '" aria-label="' + escHtml(label) + '"></button>';
    }).join("");

    var dd = document.createElement("div");
    dd.className = "lumia-media-dropdown";
    dd.dataset.folderId = String(folderId);
    dd.innerHTML =
      '<div class="lumia-media-dropdown__label">' + escHtml(t("color")) + "</div>" +
      '<div class="lumia-media-dropdown__colors">' + swatches + "</div>" +
      '<div class="lumia-media-dropdown__sep"></div>' +
      '<button type="button" class="lumia-media-dropdown__action" data-action="rename">' + escHtml(t("rename")) + "</button>" +
      '<button type="button" class="lumia-media-dropdown__action" data-action="subfolder">' + escHtml(t("newSubfolder")) + "</button>" +
      '<button type="button" class="lumia-media-dropdown__action is-danger" data-action="delete">' + escHtml(t("delete")) + "</button>";

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
            // If the view was showing the deleted folder, go back to "all".
            if (String(panel.current()) === String(removed)) panel.select(ALL);
          });
        }
      });
    });
  }

  /* ================================================================
   * TARGETS — where the current folder comes from and goes to
   *
   * This is the only thing that changes between the grid (Backbone collection)
   * and the list view (URL parameter). Everything else in the panel is identical.
   * ================================================================ */

  function collectionTarget(collection) {
    return {
      get: function () {
        var v = collection.props.get(QUERY_VAR);
        return v === undefined || v === null ? ALL : v;
      },
      set: function (value) {
        // Backbone fires the request by itself when a prop changes.
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
        // Changing folder resets the pagination.
        url.searchParams.delete("paged");
        window.location.href = url.toString();
      },
      refresh: function () { window.location.reload(); },
    };
  }

  /* ================================================================
   * FOLDER PANEL
   *
   * Deliberately written in plain JS rather than as a Backbone view: it must
   * also work on upload.php?mode=list, where wp.media (hence wp.Backbone)
   * is not loaded.
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
      '<span class="lumia-media-sidebar__title">' + escHtml(t("folders")) + "</span>" +
      (CAN_MANAGE
        ? '<button type="button" class="lumia-media-sidebar__add-btn" title="' + escHtml(t("newFolder")) + '" data-lumia-tip="' + escHtml(t("newFolder")) + '" aria-label="' + escHtml(t("newFolder")) + '">' + ICON_PLUS + "</button>"
        : "") +
      "</div>" +
      '<div class="lumia-media-sidebar__tree"><div class="lumia-media-loading">' + escHtml(t("loading")) + "</div></div>";

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
    var html = this.item({ value: ALL, name: t("allMedia"), count: null });
    html += this.item({ value: UNASSIGNED, name: t("unorganized"), count: store.unorganized });

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
      ? '<button type="button" class="lumia-media-folder__menu-btn" data-folder-id="' + escHtml(value) + '" aria-label="' + escHtml(t("actions")) + '">' + ICON_DOTS + "</button>"
      : "";
    var indent = o.depth ? ' style="--lumia-depth:' + parseInt(o.depth, 10) + '"' : "";

    // "Unfiled" is also a drop target, with id 0: dropping media on it takes
    // them out of every folder, dropping a folder on it moves it up to the
    // root. A single target for both gestures, consistent on the server
    // (folder_id 0 = no term, parent_id 0 = root).
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
   * Applies a media drop resolved by hit-test.
   *
   * @param {number} folderId Target folder (0 = leave every folder).
   * @param {Array}  ids      Attachments concerned.
   * @param {string} mode     replace | add | remove — see ajax_move_items().
   */
  FolderPanel.prototype.dropInto = function (folderId, ids, mode) {
    var self = this;
    if (!ids.length) return;

    mode = mode || "replace";

    ajax("lumia_media_move_items", { ids: ids, folder_id: folderId, mode: mode }).then(function (data) {
      var n = (data && data.moved) || ids.length;
      commit(data);

      var key = mode === "add" ? "itemsAdded" : mode === "remove" ? "itemsRemoved" : "itemsMoved";
      toast(n + " " + t(key), "success");

      // The server discards media the user is not allowed to edit.
      // Staying silent would make a legitimate refusal look like a bug.
      if (data && data.refused) {
        toast(data.refused + " " + t("itemsRefused"), "warning");
      }

      // A media removed from the displayed folder must disappear from the view.
      if (self.current() !== ALL) self.target.refresh();
    });
  };

  FolderPanel.prototype.moveFolder = function (folderId, parentId) {
    ajax("lumia_media_move_folder", { id: folderId, parent_id: parentId }).then(function (data) {
      commit(data);
      toast(t("folderMoved"), "success");
    });
  };

  FolderPanel.prototype.destroyFolderDrag = function () {
    var tree = this.el.querySelector(".lumia-media-sidebar__tree");
    if (tree && tree._lumiaSortable) {
      try { tree._lumiaSortable.destroy(); } catch (e) { /* already detached */ }
      tree._lumiaSortable = null;
    }
  };

  /**
   * Makes the folders themselves draggable (re-parenting with the mouse).
   *
   * As for the grid, SortableJS only carries the gesture and the floating
   * thumbnail: the target is resolved by hit-test. `sort: false` and the
   * absence of `group` prevent any reordering within the list.
   */
  FolderPanel.prototype.bindFolderDrag = function () {
    if (typeof Sortable === "undefined") return;

    // Re-parenting a folder is a mutation of the tree: without the right,
    // the gesture is not even offered (guard_manage() would refuse it anyway).
    // Dropping MEDIA into a folder remains open.
    if (!CAN_MANAGE) return;

    var tree = this.el.querySelector(".lumia-media-sidebar__tree");
    if (!tree) return;

    // renderTree() has just replaced the content: start again from a clean
    // instance rather than leaving one pointing to detached nodes.
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
        // Already at this location: avoid a pointless server round-trip.
        if (folder && parseInt(folder.parent, 10) === parentId) return;

        self.moveFolder(folderId, parentId);
      },
    });
  };

  /* ================================================================
   * UPLOAD TARGET
   *
   * A media uploaded from a folder must be filed into it directly. The
   * current folder is pushed into plupload's multipart_params, which
   * add_attachment reads back on the PHP side.
   *
   * param() is an INSTANCE method (it reads this.uploader.settings): calling
   * it on the prototype throws a TypeError. We therefore target the live
   * uploader of the frame, plus the default settings for those created later.
   *
   * Isolated: a failure here must never prevent navigating between folders.
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
        console.warn("[LUMIA] upload target not synchronized:", e);
      }
    }
  }

  /* ================================================================
   * DRAG & DROP — source (the media grid) and target resolution
   *
   * forceFallback:true → SortableJS handles the drag with mouse events rather
   * than the native HTML5 API, which avoids triggering WordPress's upload
   * dropzone ("Drop files to upload").
   * ================================================================ */

  var dragState = { ids: [], folder: 0, grid: null, point: null, hovered: null };

  /**
   * Resolves the hovered folder from the cursor coordinates.
   *
   * SortableJS is used ONLY for the source: its drop targets are sortable
   * lists, semantics unsuited here. With 34px folders stacked, its
   * insertion heuristic for empty lists (emptyInsertThreshold) made the
   * media land in the neighboring folder.
   *
   * The floating thumbnail is pointer-events:none (media.css), so it is
   * never returned by elementFromPoint.
   */
  function zoneAt(point) {
    if (!point) return null;
    var el = document.elementFromPoint(point.x, point.y);
    return el ? el.closest('[data-droppable="true"]') : null;
  }

  /** A folder cannot be moved into itself or into its descendants. */
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
    if (!dragState.folder) return true; // media drag: any target will do

    var target = parseInt(zone.dataset.dropId, 10);
    if (target === dragState.folder) return false;
    return !isDescendantOf(target, dragState.folder);
  }

  function trackPointer(e) {
    dragState.point = { x: e.clientX, y: e.clientY };

    // Re-read on every move: the key may be pressed mid-gesture.
    // Ctrl (Cmd on Mac) = add to the folder without removing from the others.
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

    // The grid is recreated on every request: without explicit destruction,
    // the old grid's instance stays registered in SortableJS.
    if (dragState.grid) {
      try { dragState.grid.destroy(); } catch (e) { /* already detached */ }
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

        // Dragging an already selected item → take the whole selection along.
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
   * Makes the upload.php?mode=list rows draggable to the panel.
   *
   * Same setup as in grid mode: SortableJS only carries the gesture, the
   * target is resolved by hit-test. `sort: false` prevents any reordering of
   * the table, which would make no sense here.
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

        // Row already checked → take the whole selection along, as in grid mode.
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
   * Applies a media drop, whatever the source (grid or list).
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
      // Drop on "Unfiled". From a displayed folder, the gesture means
      // "leave THIS folder" — otherwise we would also remove the media from
      // the other folders it belongs to. From "All media", there is no
      // ambiguity: take it out of everywhere.
      var currentId = parseInt(panel.current(), 10);
      if (currentId > 0) {
        mode = "remove";
        target = currentId;
      }
    }

    panel.dropInto(target, ids, mode);
  }

  /* ================================================================
   * A MEDIA'S FOLDERS — details panel
   *
   * Drag & drop does not tell WHICH folders a media is in, and does not
   * allow removing it from just one when it has several. This block
   * therefore adds the reverse view: from the media's panel, its folders.
   *
   * The IDs come from the Backbone model itself (lumiaFolders key, injected
   * by wp_prepare_attachment_for_js on the PHP side): no additional request
   * when the panel opens.
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

      // The field goes with the media's other settings. The .settings
      // container only exists in the two-column panel (grid mode);
      // in the modals' sidebar, the settings are direct children of the view,
      // so we go after the last of them.
      // .attachment-compat hosts third-party plugins' fields and sits at the
      // end of the settings: we slip in before it, with the media's own
      // fields.
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
        escHtml(t("folders")) + "</span>" +
        '<div class="lumia-media-loading">' + escHtml(t("loading")) + "</div>";

      // Panel opened before the tree is loaded: re-render once it has
      // arrived, but only if the view is still on screen.
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
      escHtml(t("folders")) + "</span>" +
      '<div class="lumia-media-attachment-folders__list">' +
      (rows || '<span class="lumia-media-attachment-folders__empty">' + escHtml(t("noFolder")) + "</span>") +
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

        // set() is enough: the panel re-renders, and the grid reads the same model.
        model.set("lumiaFolders", next);

        commit(data);
        toast(t("folderUpdated"), "success");
      })
      .catch(function () {
        // The call failed: the checkbox must reflect the real state, not the intent.
        box.checked = !checked;
      })
      .then(function () {
        box.disabled = false;
      });
  }

  /**
   * Grafts the field onto the media panels.
   *
   * Called on DOM ready and not at parse time: the two-column panel
   * (Attachment.Details.TwoColumn) is defined by media-grid.js, which may be
   * printed after us. On ready, both classes exist, and no panel has been
   * instantiated yet.
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

    // Backbone copies the parent's static properties onto the child:
    // Details.TwoColumn survives the replacement of Details, but keeps
    // inheriting from the old class. We therefore fix it separately.
    var TwoColumn = Attachment.Details.TwoColumn;
    patch(Attachment, "Details");
    if (TwoColumn) {
      Attachment.Details.TwoColumn = TwoColumn;
      patch(Attachment.Details, "TwoColumn");
    }
  }

  /* ================================================================
   * MEDIA LIBRARY AREA HEIGHT (grid mode only)
   *
   * In a modal, WordPress already sizes everything: we leave it alone.
   * In grid mode, the area is in normal flow and grows with its content —
   * hence a stretching sidebar and a scrolling page. We give it the height
   * actually available below the header, measured rather than guessed: the
   * top offset depends on the admin bar, the title and any notices.
   * ================================================================ */

  /** Floor: below it, better let the page scroll than squash the area. */
  var MIN_HEIGHT = 360;

  /** True for the full-page media library (upload.php), false in a modal. */
  function isGridFrame(view) {
    if (view && view.el && view.el.closest && view.el.closest(".media-modal")) return false;
    return !!document.querySelector(".media-frame.mode-grid");
  }

  function syncGridHeight() {
    var browser = document.querySelector(".media-frame.mode-grid .attachments-browser.lumia-has-folders");
    if (!browser) return;

    var footer = document.getElementById("wpfooter");

    // The WordPress admin stretches its content column: as long as the content
    // is short, the footer stays stuck to the bottom of the window. Measuring
    // the space below the media library in that state is therefore degenerate
    // — it always equals "what it takes to fill", whatever our height.
    //
    // So we inflate the area for the duration of the measurement: the page
    // overflows for sure, the footer is positioned by the content again, and
    // the space it takes up (bottom padding of #wpbody-content, margins, the
    // footer itself) becomes a real constant. No flicker: the browser only
    // repaints once, at the end of the function.
    browser.style.setProperty("--lumia-media-h", window.innerHeight * 2 + "px");

    // DOCUMENT coordinates (rect + scrollY): correct even when the page is scrolled.
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
   * MOUNT 1 — extension of the WordPress view (grid + modals)
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
        // In grid mode, WordPress scrolls the PAGE and listens for infinite
        // scroll on `document`. We want the grid to scroll in its own column
        // instead, next to a fixed-height sidebar.
        //
        // wp.media.view.Attachments does: scrollElement = scrollElement || this.el.
        // By emptying it, WordPress itself attaches its infinite scroll to
        // ul.attachments — which is already what it does in its modals. We
        // reimplement nothing, we switch to its other native mode.
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

        // Registered with WordPress's view manager: it survives the media
        // browser's re-renders. Its placement is purely CSS (absolute position),
        // so the order in the DOM does not matter.
        this.views.add(this.lumiaSidebar);
        this.$el.addClass("lumia-has-folders");
      },

      createAttachments: function () {
        Browser.prototype.createAttachments.apply(this, arguments);

        // The grid is recreated on every query change: we re-arm the drag
        // afterwards, on the next tick (the DOM is not laid out yet).
        var el = this.el;
        setTimeout(function () {
          makeGridDraggable(el);
          syncGridHeight();
        }, 0);
      },
    });
  }

  /* ================================================================
   * MOUNT 2 — list view (upload.php?mode=list)
   *
   * No wp.media here: the list is a classic WP_List_Table. We insert the
   * panel into .wrap and shift the form. Unlike the grid, this DOM belongs
   * to no view manager — the insertion is therefore safe from being overwritten.
   * ================================================================ */

  function mountListView() {
    var wrap = document.querySelector("body.upload-php .wrap");
    var form = wrap && wrap.querySelector("#posts-filter");
    if (!wrap || !form) return;
    if (wrap.querySelector(".lumia-media-sidebar")) return;

    ensureToastContainer();
    ensureModals();

    wrap.classList.add("lumia-media-list-layout");

    // The panel must be `position: sticky` to follow the page scroll without
    // stretching over the whole table height. Sticky only works in flow: we
    // therefore put panel and form side by side in a flex row. Moving
    // #posts-filter is safe here — unlike the grid, this DOM belongs to no
    // view manager, and its id (used by bulk actions) is preserved.
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
