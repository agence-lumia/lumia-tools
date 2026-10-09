/**
 * Lümia Tools — Menu Creator module
 * Built-in 3-column editor.
 */
(function () {
  "use strict";

  if (typeof lumiaAdmin === "undefined") return;

  var L = window.lumiaLucide || {};

  // Translated strings: module `i18n` merged on top of the core one (PHP side,
  // Module::get_admin_js_data()). No literal fallback: a fallback in one
  // language would defeat the translation.
  var I = lumiaAdmin.i18n || {};

  /** Translated string for a key ("" when the key is unknown). */
  function tr(key) { return I[key] || ""; }

  /**
   * Substitutes %s / %d / %1$s placeholders in a translated format string.
   * Unnumbered placeholders consume the arguments in order.
   */
  function fmt(format) {
    var args = Array.prototype.slice.call(arguments, 1);
    var next = 0;
    return String(format).replace(/%(?:(\d+)\$)?[sd]/g, function (m, pos) {
      var i = pos ? parseInt(pos, 10) - 1 : next++;
      return i < args.length ? String(args[i]) : m;
    });
  }

  /* ================================================================
   * STATE
   * ================================================================ */

  var ed = {
    profile:     null,
    dirty:       false,
    selectedUid: null,
  };

  var expandedUids = new Set();

  // Floating dropdown for the icon picker (singleton, appended to body)
  var iconPickerEl   = null;
  var iconPickerItem = null;

  var ms = { include: null, exclude: null, itemRoles: null };
  var sidebarState = { filter: "all", search: "" };

  /* ================================================================
   * INIT
   * ================================================================ */

  document.addEventListener("DOMContentLoaded", function () {
    if (!document.getElementById("lumia-mc-editor")) return;

    // Hide the module header's Save button (replaced by the panel footer)
    var headerSaveBtn = document.getElementById("lumia-module-save-btn");
    if (headerSaveBtn) {
      headerSaveBtn.style.display = "none";
    }

    // New menu
    var newBtn = document.getElementById("lumia-mc-new-btn");
    if (newBtn) {
      newBtn.addEventListener("click", function () { confirmDirty(startNewProfile); });
    }

    // Global menu import / export (header of the left column)
    bindProfilesFooter();

    // Back button in the right panel
    var backBtn = document.getElementById("lumia-mc-back-btn");
    if (backBtn) {
      backBtn.addEventListener("click", function () { showProfilePanel(); });
    }

    // Footer buttons
    bindPanelFooter();

    // Ctrl/Cmd+S, Ctrl+Z / Ctrl+Y
    bindShortcuts();

    // +separator / +link buttons
    bindTreeActions();

    // Floating icon picker dropdown (singleton, appended to body)
    createFloatingIconPicker();

    // Close the dropdown on an outside click — capture phase to survive WP's stopPropagation
    document.addEventListener("mousedown", function (e) {
      if (iconPickerEl &&
          !iconPickerEl.classList.contains("is-hidden") &&
          !iconPickerEl.contains(e.target) &&
          !e.target.closest(".lumia-wl-icon-btn")) {
        hideIconPicker();
      }
    }, true);

    // Close the dropdown with Escape
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && iconPickerEl && !iconPickerEl.classList.contains("is-hidden")) {
        hideIconPicker();
      }
    });

    // Close the role/user multi-selects (include/exclude) on an outside
    // click — capture phase to survive WP's stopPropagation.
    document.addEventListener("mousedown", function (e) {
      ["include", "exclude", "itemRoles"].forEach(function (key) {
        var w = ms[key];
        if (w && w.open && w.container && !w.container.contains(e.target)) {
          w.close();
        }
      });
    }, true);

    // …and with Escape.
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
   * HISTORY — undo / redo
   *
   * Every editor mutation ends with setDirty(true): that is where the snapshot
   * is taken, rather than instrumenting each handler (tree, fields, icon
   * picker…) and forgetting one. The snapshot reuses collectProfile() — the
   * state of the panel fields, which lives in the DOM and not in ed.profile —
   * but keeps the items with their runtime properties (_uid, _wpLabel) so the
   * selection is not broken when going back.
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

    // Coalescing: typing triggers one setDirty per character, which would
    // make the history unusable (one Ctrl+Z per letter).
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
    // Going back to the initial snapshot means going back to the saved state:
    // the menu is no longer "modified" (unless it was never saved).
    setDirty(i !== 0 || hist.baseDirty);
    hist.index = i;
    hist.last  = 0;
    hist.lock  = false;
  }

  function undo() {
    if (!ed.profile || !hist.ready) return;
    if (hist.index <= 0) { toast(tr("nothingToUndo"), "info"); return; }
    applyHistory(hist.index - 1);
    toast(tr("changeUndone"), "info");
  }

  function redo() {
    if (!ed.profile || !hist.ready) return;
    if (hist.index >= hist.stack.length - 1) { toast(tr("nothingToRedo"), "info"); return; }
    applyHistory(hist.index + 1);
    toast(tr("changeRedone"), "info");
  }

  /**
   * Editor keyboard shortcuts.
   *
   * Ctrl/Cmd+S is always intercepted (the browser's "save page" dialog makes no
   * sense here). Ctrl+Z / Ctrl+Y, on the other hand, are left to the field
   * when the focus is in an input area: native text undo is expected there,
   * and a global undo would lose far more than the letter the user wanted to
   * take back.
   */
  function bindShortcuts() {
    document.addEventListener("keydown", function (e) {
      if (!(e.ctrlKey || e.metaKey) || e.altKey) return;
      var key = (e.key || "").toLowerCase();

      if (key === "s") {
        e.preventDefault();
        if (!ed.profile) return;
        if (ed.dirty) onSave();
        else toast(tr("nothingToSave"), "info");
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
   * UNSAVED CHANGES CONFIRMATION
   * ================================================================ */

  function confirmDirty(callback) {
    if (!ed.dirty) { callback(); return; }
    window.lumiaModal.open({
      title:        tr("unsavedChanges"),
      message:      tr("leaveConfirm"),
      confirmLabel: tr("continueWithoutSaving"),
      cancelLabel:  tr("cancel"),
      danger:       true,
      onConfirm:    function () { setDirty(false); callback(); },
    });
  }

  /* ================================================================
   * BLANK PROFILE
   * ================================================================ */

  function blankProfile() {
    var profiles = lumiaAdmin.mcProfiles || [];
    var names    = profiles.map(function (p) { return p.name || ""; });
    var n = 1;
    while (names.indexOf(fmt(tr("menuNumbered"), n)) !== -1) { n++; }
    return {
      id: "__new__", name: fmt(tr("menuNumbered"), n), status: "draft", apply_to_all: false,
      include_roles: [], include_users: [], exclude_roles: [], exclude_users: [],
      items: [], updated_at: 0,
    };
  }

  /* ================================================================
   * LOADING A PROFILE
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
    // A newly created menu does not exist on the server yet: the Save button
    // must be usable right away, without requiring the user to edit a field
    // first.
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
   * SIDEBAR — profile list
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
        esc(profiles.length === 0 ? fmt(tr("noMenuYet"), tr("newMenu")) : tr("noResults")) + "</p>";
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
            (isActive ? tr("menuActive") : tr("draftNotApplied")) + '"></span>' +
          '<span class="lumia-wl-ep-profile-item__name">' +
            esc(p.name || tr("unnamedMenu")) + "</span>" +
          '<span class="lumia-mc-item-actions">' +
            '<button type="button" class="lumia-mc-item-action" data-action="duplicate" ' +
              'data-id="' + esc(p.id) + '" data-lumia-tip="' + esc(tr("duplicateMenu")) + '">' +
              (L.copy || "") + "</button>" +
            '<button type="button" class="lumia-mc-item-action lumia-mc-item-action--danger" ' +
              'data-action="delete" data-id="' + esc(p.id) + '" data-lumia-tip="' + esc(tr("deleteMenu")) + '">' +
              (L.trash || "&times;") + "</button>" +
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
   * Row representing the menu being created, not yet saved on the server
   * (hence absent from lumiaAdmin.mcProfiles): no duplicate/delete actions,
   * which require a real id.
   */
  function buildDraftRowHtml(p) {
    return (
      '<div class="lumia-wl-ep-profile-item is-active" data-id="__new__">' +
        '<span class="lumia-mc-dot lumia-mc-dot--draft" data-lumia-tip="' + esc(tr("draftNotApplied")) + '"></span>' +
        '<span class="lumia-wl-ep-profile-item__name">' +
          esc(p.name || tr("unnamedMenu")) + "</span>" +
        '<span class="lumia-badge lumia-badge--warning">' + esc(tr("notSaved")) + "</span>" +
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
   * SIDEBAR ACTIONS
   * ================================================================ */

  function onSidebarDelete(profileId) {
    var p    = findProfileById(profileId);
    var name = p ? (p.name || tr("thisMenu")) : tr("thisMenu");
    window.lumiaModal.open({
      title: tr("deleteMenuTitle"),
      message: fmt(tr("deleteMenuPrompt"), name) + " " + tr("deleteConfirmMsg"),
      confirmLabel: tr("delete"), cancelLabel: tr("cancel"), danger: true,
      onConfirm: function () {
        // An active menu applies its customizations to the real WP menu:
        // after deletion the page must be reloaded so the WP sidebar goes
        // back to its native state (otherwise leftovers stay on screen).
        var wasActive = !!(p && p.status === "active");
        ajaxPost("lumia_wl_delete_profile", { profile_id: profileId }, function (data) {
          if (data && data.success) {
            lumiaAdmin.mcProfiles = (lumiaAdmin.mcProfiles || []).filter(function (x) { return x.id !== profileId; });
            if (ed.profile && ed.profile.id === profileId) { ed.profile = null; showPlaceholder(); }
            renderProfilesSidebar();
            toast(tr("menuDeleted"), "success");
            if (wasActive) { setTimeout(function () { window.location.reload(); }, 600); }
          } else { toast(tr("deleteError"), "error"); }
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
        toast(tr("menuDuplicated"), "success");
      } else { toast(tr("duplicateError"), "error"); }
    });
  }

  /* ================================================================
   * FOOTER — Save / Reset
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
          title:        tr("resetMenuTitle"),
          message:      tr("resetMenuMessage"),
          confirmLabel: tr("reset"),
          cancelLabel:  tr("cancel"),
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
   * MERGE WITH THE WP MENU
   * ================================================================ */

  function mergeWpMenu() {
    var wpMenu = lumiaAdmin.wpMenu || [];
    if (!ed.profile.items.length && wpMenu.length) {
      ed.profile.items = wpMenu.map(function (m) { return wpItemToEditorItem(m); }).filter(Boolean);
    } else {
      var seen = {};
      // Vanished WP references are only purged when the current WP menu is
      // really known: without wpMenu (missing data), nothing is removed.
      var wpKnown = wpMenu.length > 0;
      ed.profile.items = ed.profile.items.filter(function (item) {
        if (!item._uid) item._uid = genUid();
        // Separators and custom links do not exist in the WP menu: they belong
        // to the profile and are always kept.
        if (item.type === "separator" || item.type === "custom_link") {
          seen[item.slug] = true;
          return true;
        }
        var wp = findWpItem(item.slug);
        // WP item that matches nothing in the real menu any more (plugin
        // deactivated, feature turned off such as the Link Manager, or leftover
        // from an old profile). It is kept but flagged: silently deleting it
        // would wipe a deliberate setting as soon as a plugin is deactivated
        // for the length of an update.
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
    // WP separators: slug starting with "separator" (label always empty)
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
   * GLOBAL RENDERING
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
   * RIGHT PANEL NAVIGATION (no tabs)
   * ================================================================ */

  function showProfilePanel() {
    hideIconPicker();
    ed.selectedUid = null;
    setDisplay("lumia-mc-back-btn", "none");
    // The export applies to the whole menu: it has no business on an item's
    // view, where the button would suggest that this item alone is exported.
    setDisplay("lumia-mc-export-btn", "");
    var titleEl = document.getElementById("lumia-mc-panel-title");
    if (titleEl) titleEl.textContent = tr("menuSettings");
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
      titleEl.textContent = item.label || item._wpLabel || prettifySlug(item.slug) || tr("item");
    }
    hide("lumia-wl-profile-settings");
    show("lumia-wl-item-settings");
    var isChild = isChildItem(item._uid);
    var fields = document.getElementById("lumia-wl-item-fields");
    if (fields) { fields.innerHTML = buildItemFields(item, isChild); bindItemFields(item); }
    renderTree();
  }

  /**
   * A child never has a WP icon (WordPress provides no icon for submenus in
   * $submenu, unlike $menu): the icon picker is therefore hidden for these
   * items rather than leaving a control that can never display anything
   * relevant.
   */
  function isChildItem(uid) {
    if (!ed.profile) return false;
    return !ed.profile.items.some(function (i) { return i._uid === uid; });
  }

  /* ================================================================
   * TREE
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
   * STALE ENTRIES
   * ================================================================ */

  /**
   * List of the items (parents and children) whose slug no longer exists in
   * the current WP menu. They are kept in the profile — a plugin deactivated
   * for the length of an update must not wipe its settings — but flagged,
   * otherwise these ineffective settings would go unnoticed.
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
        "<strong>" + esc(fmt(stale.length > 1 ? tr("staleEntriesMany") : tr("staleEntriesOne"), stale.length)) +
        "</strong> — " +
        esc(names.slice(0, 4).join(", ")) +
        (names.length > 4 ? " " + esc(fmt(tr("staleAndMore"), names.length - 4)) : "") +
        ". " + esc(tr("staleHint")) +
      "</span>" +
      '<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary" ' +
        'id="lumia-mc-stale-clean">' + esc(tr("cleanUp")) + "</button>";
    bar.style.display = "";

    var btn = document.getElementById("lumia-mc-stale-clean");
    if (btn) btn.addEventListener("click", onCleanStale);
  }

  function onCleanStale() {
    var stale = staleItems();
    if (!stale.length) return;
    window.lumiaModal.open({
      title:        tr("cleanStaleTitle"),
      message:      fmt(tr("cleanStaleMessage"), stale.length),
      confirmLabel: tr("cleanUp"),
      cancelLabel:  tr("cancel"),
      danger:       true,
      onConfirm: function () {
        ed.profile.items = (ed.profile.items || []).filter(function (item) {
          item.children = (item.children || []).filter(function (c) { return !c._stale; });
          return !item._stale;
        });
        ed.selectedUid = null;
        showProfilePanel();
        setDirty(true);
        toast(tr("staleRemoved"), "success");
      },
    });
  }

  function buildItemHtml(item, idx, total, parentUid) {
    var uid = item._uid;

    /* --- SEPARATOR: just a line, no text --- */
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
                (L.trash || "&times;") + "</button>" +
            "</div>" +
          "</div>" +
        "</div>"
      );
    }

    /* --- REGULAR ITEM --- */
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
              'data-lumia-tip="' + esc(hidden ? tr("showInMenu") : tr("hideFromMenu")) + '">' +
              (hidden ? (L.eyeOff || "") : (L.eye || "")) + "</button>" +
            mvBtn(uid, parentUid, idx, total) +
            (item.type === "custom_link"
              ? '<button type="button" class="lumia-wl-tree-item__del" ' +
                  'data-uid="' + esc(uid) + '" data-parent="' + esc(parentUid) + '">' +
                  (L.trash || "&times;") + "</button>"
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
              'data-lumia-tip="' + esc(hidden ? tr("showInMenu") : tr("hideFromMenu")) + '">' +
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
        'data-uid="' + esc(uid) + '" data-parent="' + esc(parentUid) + '" data-lumia-tip="' + esc(tr("moveUp")) + '"' + up + '>' +
        (L.chevronU || "↑") + "</button>" +
      '<button type="button" class="lumia-wl-tree-item__mv" data-mv="down" ' +
        'data-uid="' + esc(uid) + '" data-parent="' + esc(parentUid) + '" data-lumia-tip="' + esc(tr("moveDown")) + '"' + dn + '>' +
        (L.chevronD || "↓") + "</button>"
    );
  }

  /**
   * Displayable source of a stored icon value ("svg:<base64>" or URL).
   */
  function iconSrc(icon) {
    return icon.indexOf("svg:") === 0 ? "data:image/svg+xml;base64," + icon.slice(4) : icon;
  }

  function isSvgSrc(src) {
    return src.indexOf("data:image/svg+xml") === 0 || /\.svg([?#]|$)/i.test(src);
  }

  // Text of the SVGs served by URL, resolved once then memoized.
  // null = pending / failed, string = content.
  var svgTextCache = {};

  /**
   * Content of an SVG source, when readable without a request.
   * For a site URL, starts a fetch and redraws the tree on arrival (once per
   * URL) rather than blocking the rendering.
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
   * Is an SVG monochrome, hence recolorable through a mask without losing
   * anything? Same rule as Module::svg_is_monochrome() on the PHP side: a
   * two-color icon (the plugin logo: white square + black glyph) would be
   * flattened into a solid square by a mask, so it keeps its <img>.
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
   * Renders a menu icon in the editor (light background).
   *
   * Menu icon SVGs are monochrome and painted for the DARK wp-admin sidebar:
   * WooCommerce and Bricks ship a #f3f1f1 fill (invisible on a light
   * background), Lucide files a currentColor stroke (black in an <img>, for
   * lack of an inherited color). An <img> thus shows either nothing or an
   * off-theme icon. They are rendered as a CSS mask: the source only provides
   * the shape, the color comes from the editor (currentColor).
   * Non-SVG images (PNG/JPG) keep a regular <img>.
   */
  function iconMarkup(icon, cls) {
    if (icon.indexOf("dashicons-") === 0) {
      return '<span class="' + cls + ' dashicons ' + esc(icon) + '" aria-hidden="true"></span>';
    }
    var src = iconSrc(icon);
    // Quotes / parentheses would break the inline url(): fall back to <img>.
    if (isSvgSrc(src) && !/["'()\\]/.test(src) && isMonochromeSvg(svgTextOf(src))) {
      var u = 'url("' + src + '")';
      return '<span class="' + cls + ' lumia-wl-icon-mask" aria-hidden="true" ' +
        'style="-webkit-mask-image:' + esc(u) + ';mask-image:' + esc(u) + '"></span>';
    }
    return '<img class="' + cls + '" src="' + esc(src) + '" aria-hidden="true" alt="">';
  }

  /**
   * Padlock on a hidden AND blocked item: without a marker, nothing in the
   * tree tells "removed from the menu" apart from "page denied".
   */
  function lockBadge(item) {
    if (item.visible !== false || !item.block_access) return "";
    return '<span class="lumia-wl-tree-item__lock" data-lumia-tip="' + esc(tr("hiddenAndBlocked")) + '">' +
      (L.lock || "") + "</span>";
  }

  /**
   * Help marker (Lucide `info` icon + tooltip), for a secondary caveat that
   * would weigh the line down if written out in full. JS equivalent of
   * `Admin::render_help_tip()`.
   */
  function helpTip(text) {
    return '<button type="button" class="lumia-tip-info" tabindex="0" data-lumia-tip="' +
      esc(text) + '" aria-label="' + esc(text) + '">' + (L.info || "") + "</button>";
  }

  /**
   * "Stale" marker: the slug matches no entry of the current WP menu. The
   * setting is kept (a plugin may be reactivated) but has no effect as long as
   * the entry does not exist.
   */
  function staleBadge(item) {
    if (!item._stale) return "";
    return '<span class="lumia-wl-tree-item__stale" ' +
      'data-lumia-tip="' + esc(tr("staleTip")) + '">' +
      (L.warn || "!") + "</span>";
  }

  function buildIconEl(item) {
    var icon = item.icon || item._wpIcon || "";
    if (!icon) return '<span class="lumia-wl-tree-item__icon-ph"></span>';
    return iconMarkup(icon, "lumia-wl-tree-item__icon");
  }

  /* ================================================================
   * TREE BINDINGS
   * ================================================================ */

  function bindTree(tree) {
    initTreeSortable(tree);

    /* --- ROW CLICK → selection --- */
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

    /* --- VISIBILITY --- */
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

    /* --- MOVE UP / DOWN --- */
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

    /* --- DELETE (separators and custom links) --- */
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
   * DRAG & DROP (SortableJS) — one instance per level, no
   * root ↔ children crossing (no shared "group")
   * ================================================================ */

  function initTreeSortable(tree) {
    if (typeof Sortable === "undefined") return;

    var sortableOpts = {
      handle:     ".lumia-wl-tree-item__handle",
      animation:  150,
      chosenClass: "is-dragging",
      ghostClass:  "lumia-mc-sortable-ghost",
    };

    // "tree" (#lumia-wl-tree) is a node that persists between renders (only
    // its innerHTML changes): instantiate Sortable on it only once, otherwise
    // every renderTree() would stack a new instance on it.
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

    // The children containers, on the other hand, are recreated on every
    // render (innerHTML replaced): a new instance each time is therefore
    // correct.

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
   * TREE ACTIONS (+separator / +link) — added AT THE TOP
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
          // "lien-" is a persisted slug prefix: it must not change.
          type: "custom_link", slug: "lien-" + genUid(), label: tr("newLink"),
          _uid: genUid(), _wpLabel: tr("newLink"), _wpIcon: "",
          visible: true, target_blank: false, url: "", icon: null, roles: [], children: [],
        });
        setDirty(true);
        renderTree();
      });
    }
  }

  /* ================================================================
   * PROFILE PANEL
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
    badge.textContent = isActive ? tr("active") : tr("draft");
  }

  /* ================================================================
   * ITEM FIELDS
   * ================================================================ */

  function buildItemFields(item, isChild) {
    if (item.type === "separator") {
      return '<p class="lumia-wl-note" style="padding:16px">' + esc(tr("separatorNoSettings")) + "</p>";
    }
    var html = "";
    if (item.type === "custom_link") {
      html += settingsRow(tr("urlField"),
        '<input type="url" class="lumia-input" id="lumia-wl-item-url" value="' + esc(item.url || "") + '">', "");
      // WP items are already filtered by their own capabilities; a custom
      // link, on the other hand, is attached to nothing — hence this
      // restriction.
      html +=
        '<div class="lumia-wl-settings-row lumia-wl-settings-row--col">' +
          '<div class="lumia-wl-settings-row__label"><span>' + esc(tr("restrictToRoles")) + "</span>" +
            '<p class="lumia-form__help">' + esc(tr("restrictToRolesHelp")) + "</p>" +
          "</div>" +
          '<div class="lumia-wl-multiselect" id="lumia-wl-item-roles-select"></div>' +
        "</div>";
    }
    html += settingsRow(tr("labelField"),
      '<input type="text" class="lumia-input" id="lumia-wl-item-label" value="' + esc(item.label || "") + '" ' +
        'placeholder="' + esc(item._wpLabel || prettifySlug(item.slug)) + '">',
      tr("labelHelp"));
    html += (
      '<div class="lumia-wl-settings-row lumia-wl-settings-row--inline">' +
        '<div class="lumia-wl-settings-row__label"><span>' + esc(tr("iconField")) + "</span>" +
          (isChild
            ? '<p class="lumia-form__help">' + esc(tr("iconChildHelp")) + "</p>"
            : "") +
        "</div>" +
        buildIconBtnHtml(item) +
      "</div>"
    );
    html += settingsRow(tr("visible"),
      '<label class="lumia-toggle">' +
        '<input type="checkbox" id="lumia-wl-item-visible"' + (item.visible !== false ? " checked" : "") + '>' +
        '<span class="lumia-toggle__slider"></span></label>', "", true);
    // Hiding only removes the entry from the menu: the URL stays reachable.
    // The option therefore only makes sense — and is only shown — on a hidden
    // item.
    if (item.type === "wp_item") {
      html +=
        '<div class="lumia-wl-settings-row lumia-wl-settings-row--inline" id="lumia-wl-block-row"' +
          (item.visible === false ? "" : ' style="display:none"') + ">" +
          '<div class="lumia-wl-settings-row__label">' +
            // The important caveat (this is not a permissions system) goes
            // under a help marker: it must stay readable without lengthening an
            // already dense line.
            "<span>" + esc(tr("blockAccess")) + helpTip(tr("blockAccessTip")) + "</span>" +
            '<p class="lumia-form__help">' + esc(tr("blockAccessHelp")) + "</p>" +
          "</div>" +
          '<label class="lumia-toggle">' +
            '<input type="checkbox" id="lumia-wl-item-block"' + (item.block_access ? " checked" : "") + ">" +
            '<span class="lumia-toggle__slider"></span></label>' +
        "</div>";
    }
    // "New tab" only makes sense for a top-level item: submenus point to WP
    // admin pages, there is no point in opening them in a separate tab (and the
    // target attribute is not applied to them).
    if (!isChild) {
      html += settingsRow(tr("openInNewTab"),
        '<label class="lumia-toggle">' +
          '<input type="checkbox" id="lumia-wl-item-target"' + (item.target_blank ? " checked" : "") + '>' +
          '<span class="lumia-toggle__slider"></span></label>', "", true);
    }
    if (item.type === "wp_item") {
      html += (
        '<div class="lumia-wl-settings-row lumia-wl-item-reset-row">' +
          '<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--danger" id="lumia-wl-item-reset">' +
            esc(tr("resetItem")) + "</button>" +
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
        '<span>' + esc(icon ? tr("change") : tr("chooseIcon")) + "</span>" +
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
      if (titleEl) titleEl.textContent = item.label || item._wpLabel || prettifySlug(item.slug) || tr("item");
      renderTree();
    });

    var visEl   = document.getElementById("lumia-wl-item-visible");
    var blockEl = document.getElementById("lumia-wl-item-block");
    var blockRow = document.getElementById("lumia-wl-block-row");
    if (visEl) visEl.addEventListener("change", function () {
      item.visible = visEl.checked;
      // An item that becomes visible again cannot stay blocked: the link would
      // be displayed but lead to a denial.
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
      renderTree(); // shows / hides the padlock in the tree
    });

    var tgtEl = document.getElementById("lumia-wl-item-target");
    if (tgtEl) tgtEl.addEventListener("change", function () { item.target_blank = tgtEl.checked; setDirty(true); });

    var rstBtn = document.getElementById("lumia-wl-item-reset");
    if (rstBtn) rstBtn.addEventListener("click", function () {
      item.label = null; item.icon = null; item.visible = true;
      item.block_access = false; item.target_blank = false;
      setDirty(true);
      showItemPanel(item);
      toast(tr("itemReset"), "success");
    });

    var iconBtn = document.getElementById("lumia-wl-icon-open");
    if (iconBtn) iconBtn.addEventListener("click", function (e) {
      e.stopPropagation();
      openIconPicker(iconBtn, item);
    });
  }

  /* ================================================================
   * MENU EXPORT / IMPORT
   * ================================================================ */

  /**
   * Exports the current menu as .json. The editor state is serialized (hence
   * including unsaved changes): what the user sees is what they export.
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
    // Release the URL on the next loop turn: revoking it right away would
    // cancel the download in some browsers.
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
    toast(tr("menuExported"), "success");
  }

  /**
   * Exports all saved menus. It starts from lumiaAdmin.mcProfiles (the state in
   * the database), not from the editor: the open menu may have unsaved changes,
   * which it would be misleading to include in an "export all".
   */
  function exportAllProfiles() {
    var profiles = lumiaAdmin.mcProfiles || [];
    if (!profiles.length) { toast(tr("noMenuToExport"), "error"); return; }

    if (ed.dirty) {
      toast(tr("unsavedNotExported"), "warning");
    }
    downloadJson({
      lumia:     "menu_profiles",
      version:  1,
      exported: new Date().toISOString(),
      profiles: profiles,
    }, "lumia-menus.json");
    toast(fmt(profiles.length > 1 ? tr("menusExportedMany") : tr("menusExportedOne"), profiles.length), "success");
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
        // The file only passes through: the server validates and sanitizes
        // (sanitize_profile), never this client-side parse.
        input.value = "";
        ajaxPost("lumia_wl_import_profile", { profile: String(reader.result || "") }, function (data) {
          if (!data || !data.success) {
            toast((data && data.data && data.data.message) || tr("importFailed"), "error");
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
            ? fmt(tr("menusImported"), imported.length)
            : tr("menuImported");
          if (updated) {
            msg += " " + (updated > 1 ? fmt(tr("existingUpdatedMany"), updated) : tr("existingUpdatedOne"));
          }
          toast(msg, "success");
        });
      };
      reader.onerror = function () { toast(tr("fileReadFailed"), "error"); };
      reader.readAsText(file);
    });
  }

  /* ================================================================
   * FLOATING ICON PICKER
   * ================================================================ */

  /**
   * Closes the picker explicitly on any navigation (item, panel or profile
   * change): do not rely only on the document-level mousedown, which can leave
   * the picker open and block the UX if the navigation is triggered by
   * something other than a plain outside click (e.g. selecting another item,
   * switching menu).
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
   * Accent folding: Lucide slugs are English, the aliases may be accented.
   * Without it, a search typed without accents would not find an accented
   * keyword and the user would have to guess its exact accents.
   */
  function foldAccents(str) {
    return (str || "").toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, "");
  }

  /**
   * Indexed terms of an icon: its slug (dashes replaced by spaces, so that
   * "chart column" works too) and its aliases.
   */
  function iconSearchTerms(name) {
    var aliases = (lumiaAdmin && lumiaAdmin.iconAliases) || {};
    return foldAccents(name + " " + name.replace(/-/g, " ") + " " + (aliases[name] || ""));
  }

  /**
   * Library grid, grouped by category.
   *
   * The categories come from `lumiaAdmin.iconCategories` (display order set on
   * the PHP side). If the data is missing — payload from an earlier version —
   * it falls back to a flat grid of the whole library.
   */
  function buildIconGridHtml() {
    var lib = (lumiaAdmin && lumiaAdmin.iconLibrary) || window.lumiaWlIconLibrary || {};
    var names = Object.keys(lib);
    if (!names.length) return '<p class="lumia-wl-icon-lib-empty">' + esc(tr("libraryEmpty")) + "</p>";

    var cats = (lumiaAdmin && lumiaAdmin.iconCategories) || null;
    if (!cats || !cats.length) cats = [{ id: "all", label: tr("icons"), icons: names }];

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
    }).join("") + '<p class="lumia-wl-icon-lib-empty" id="lumia-ip-no-result" style="display:none">' + esc(tr("noIcon")) + "</p>";
  }

  function buildIconDropdownHtml(item) {
    var icon   = item.icon;
    var imgSrc = "";
    if (icon && icon.indexOf("dashicons-") !== 0) {
      imgSrc = iconSrc(icon);
    }

    // Explicit fallback: show what "restore" will actually give, rather than a
    // "Default icon" that announces nothing.
    var native = item._wpIcon || "";
    var resetLabel = native ? tr("restoreOriginalIcon") : tr("removeIcon");
    var resetPreview = native ? iconMarkup(native, "lumia-wl-icon-reset__i") : "";

    return (
      '<div class="lumia-wl-icon-picker-tabs">' +
        '<button type="button" class="lumia-wl-icon-tab is-active" data-tab="library">' + esc(tr("tabLibrary")) + "</button>" +
        '<button type="button" class="lumia-wl-icon-tab" data-tab="media">' + esc(tr("tabMedia")) + "</button>" +
        '<button type="button" class="lumia-wl-icon-tab" data-tab="code">' + esc(tr("tabCode")) + "</button>" +
      "</div>" +

      '<div class="lumia-wl-icon-pane" data-pane="library">' +
        '<div class="lumia-wl-icon-search-wrap">' +
          '<input type="search" class="lumia-input lumia-wl-icon-search" id="lumia-ip-search" ' +
            'placeholder="' + esc(tr("searchIcon")) + '" autocomplete="off">' +
        "</div>" +
        '<div class="lumia-wl-icon-scroll" id="lumia-ip-lib">' + buildIconGridHtml() + "</div>" +
      "</div>" +

      '<div class="lumia-wl-icon-pane" data-pane="media" style="display:none"><div class="lumia-wl-icon-pane__body">' +
        '<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary" id="lumia-ip-media-btn">' +
          esc(tr("openMediaLibrary")) + "</button>" +
        '<div class="lumia-wl-icon-media-preview" id="lumia-ip-media-prev"' +
          (imgSrc ? "" : ' style="display:none"') + ">" +
          (imgSrc ? iconMarkup(imgSrc, "lumia-wl-icon-media-preview__i") : "") +
        "</div>" +
      "</div></div>" +

      '<div class="lumia-wl-icon-pane" data-pane="code" style="display:none"><div class="lumia-wl-icon-pane__body">' +
        '<textarea class="lumia-input lumia-wl-icon-code" id="lumia-ip-code" rows="5" ' +
          'placeholder="&lt;svg …&gt;…&lt;/svg&gt;"></textarea>' +
        '<p class="lumia-form__help">' + tr("svgHelp") + "</p>" +
        '<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--primary" id="lumia-ip-code-btn">' +
          esc(tr("useThisSvg")) + "</button>" +
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

    // Search: already rendered cells are hidden/shown rather than rebuilding
    // the grid — 150 icons, every keystroke would recreate as many nodes and
    // lose the focus.
    var search = el.querySelector("#lumia-ip-search");
    if (search) {
      search.addEventListener("input", function () {
        // Every typed word must be found: "credit card" must not bring back
        // every card, but "card credit" must work.
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
          // During a search, the category headers add nothing.
          var title = cat.querySelector(".lumia-wl-icon-cat__title");
          if (title) title.style.display = words.length ? "none" : "";
          if (shown) none = false;
        });
        var empty = el.querySelector("#lumia-ip-no-result");
        if (empty) empty.style.display = none ? "" : "none";
      });
      search.addEventListener("keydown", function (e) {
        // Enter = pick the first visible icon.
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
        if (!raw) { toast(tr("pasteSvg"), "error"); return; }
        codeBtn.disabled = true;
        // Sanitizing is done server-side: what the editor stores is the cleaned
        // SVG it returns, never the pasted string as is.
        ajaxPost("lumia_wl_sanitize_svg", { svg: raw }, function (data) {
          codeBtn.disabled = false;
          if (!data || !data.success) {
            toast((data && data.data && data.data.message) || tr("svgRejected"), "error");
            return;
          }
          applyIcon(data.data.icon);
          toast(tr("svgApplied"), "success");
        });
      });
    }
    var defBtn = el.querySelector("#lumia-ip-default-btn");
    if (defBtn) defBtn.addEventListener("click", function () { applyIcon(null); });
    var mediaBtn  = el.querySelector("#lumia-ip-media-btn");
    var mediaPrev = el.querySelector("#lumia-ip-media-prev");
    if (mediaBtn && typeof wp !== "undefined" && wp.media) {
      mediaBtn.addEventListener("click", function () {
        var frame = wp.media({ title: tr("chooseIcon"), multiple: false });
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
    if (label) label.textContent = item.icon ? tr("change") : tr("chooseIcon");
  }

  /* ================================================================
   * SAVING
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
        toast(tr("menuSaved"), "success");

        try { sessionStorage.setItem("lumia_mc_open_profile", saved.id); } catch (e) {}
        setTimeout(function () { window.location.reload(); }, 800);
      } else {
        if (saveBtn) saveBtn.disabled = false;
        toast(tr("saveFailed"), "error");
      }
    });
  }

  /* ================================================================
   * COLLECTION
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
   * @param {object} [opts] rolesOnly: only exposes the roles (restriction of a
   *   custom link — a menu link is not targeted per user).
   *   onChange: called after every addition/removal, with the current value.
   */
  function createMultiSelect(containerId, initialSelected, opts) {
    var container = document.getElementById(containerId);
    if (!container) return null;
    opts = opts || {};
    var widget = { selected: initialSelected || [], results: [], open: false, timer: null,
                   container: container, rolesOnly: !!opts.rolesOnly };

    // Persistent structure. The input is NEVER recreated: the full rebuild of
    // the old version destroyed the focused input, which triggered a blur → the
    // dropdown closed immediately ("no time to click").
    container.innerHTML =
      '<div class="lumia-wl-ms-tags"></div>' +
      '<div class="lumia-wl-ms-dropdown" style="display:none"></div>';
    var tagsEl     = container.querySelector(".lumia-wl-ms-tags");
    var dropdownEl = container.querySelector(".lumia-wl-ms-dropdown");
    var input      = document.createElement("input");
    input.type        = "text";
    input.className   = "lumia-wl-ms-input";
    input.placeholder = tr("search");
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
          '<button type="button" class="lumia-wl-chip__remove" data-id="' + esc(s.id) + '">' + (L.x || "&times;") + "</button>";
        tagsEl.insertBefore(chip, input);
      });
    }

    function renderDropdown() {
      dropdownEl.innerHTML = msDropdownHtml(widget);
      dropdownEl.style.display = widget.open ? "" : "none";
    }

    widget.render = function () { renderChips(); renderDropdown(); };
    widget.close  = function () { if (!widget.open) return; widget.open = false; renderDropdown(); };

    // Delegation: chips and options are recreated on every render, so we listen
    // at the container level (once, no listener leak).
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
        e.preventDefault(); // keeps the input focus
        if (!widget.selected.find(function (s) { return s.id === opt.dataset.id; })) {
          var rawId = opt.dataset.type === "user" ? parseInt(opt.dataset.raw, 10) : opt.dataset.raw;
          widget.selected.push({ id: opt.dataset.id, rawId: rawId, label: opt.dataset.label, type: opt.dataset.type });
          setDirty(true);
          if (opts.onChange) opts.onChange(widget.getValue());
        }
        widget.render(); // stays open to allow multiple additions
        input.focus();
        return;
      }
      // Click in the tags area (outside a chip) → focus the input.
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
    if (!options.length) return '<div class="lumia-wl-ms-empty">' + esc(tr("noResults")) + "</div>";
    return options.map(function (r) {
      var badge = r.type === "role"
        ? '<span class="lumia-badge lumia-badge--info">' + esc(tr("role")) + "</span>"
        : '<span class="lumia-badge lumia-badge--inactive">' + esc(tr("user")) + "</span>";
      return '<div class="lumia-wl-ms-option" data-id="' + esc(r.id) + '" data-label="' + esc(r.label) +
        '" data-type="' + esc(r.type) + '" data-raw="' + esc(String(r.rawId)) + '">' +
        esc(r.label) + " " + badge + "</div>";
    }).join("");
  }

  /* ================================================================
   * UTILITIES
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
   * Readable fallback when neither label nor _wpLabel is available (hidden
   * item with mergeWpMenu not run yet, or WP menu not found): extracts the
   * useful part of the slug ("edit.php?post_type=product" → "Product") rather
   * than displaying the raw technical string.
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
