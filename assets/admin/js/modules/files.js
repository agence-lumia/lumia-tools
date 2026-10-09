/**
 * Lümia Tools — Files module
 * Vanilla JS file manager.
 */
(function () {
  "use strict";

  if (typeof lumiaAdmin === "undefined") return;

  /* ================================================================
   * GLOBAL STATE
   * ================================================================ */

  var fm = {
    path: "",
    selected: [],
    ajaxUrl: lumiaAdmin.ajaxUrl,
    nonce: "",
    i18n: lumiaAdmin.i18n || {},
    downloadUrl: "",
    downloadNonce: "",
    editorPath: null,
    editorDirty: false,
    editorInputBound: false,
    editorCm: null,
    movePath: null,
    renamePath: null,
    dragCounter: 0,
  };

  /* ================================================================
   * INITIALIZATION
   * ================================================================ */

  document.addEventListener("DOMContentLoaded", function () {
    var container = document.getElementById("lumia-files-manager");
    if (!container) return;

    fm.nonce        = container.dataset.nonce || "";
    fm.downloadUrl  = fm.i18n.downloadUrl || "";
    fm.downloadNonce = fm.i18n.downloadNonce || "";

    // Hide the header "Save" button (this module has no form)
    var headerSave = document.querySelector('.lumia-page__header-actions [form="lumia-module-form"]');
    if (headerSave) headerSave.style.display = "none";

    loadDirectory("");
    initCheckAll();
    initSelectionBar();
    initToolbar();
    initUpload();
    initDropZone();
    initEditor();
    initModals();
  });

  /* ================================================================
   * AJAX
   * ================================================================ */

  function ajax(action, data, cb) {
    var form = new FormData();
    form.append("action", action);
    form.append("nonce", fm.nonce);

    for (var k in data) {
      if (!Object.prototype.hasOwnProperty.call(data, k)) continue;
      if (Array.isArray(data[k])) {
        data[k].forEach(function (v) { form.append(k + "[]", v); });
      } else {
        form.append(k, data[k]);
      }
    }

    fetch(fm.ajaxUrl, { method: "POST", credentials: "same-origin", body: form })
      .then(function (r) { return r.json(); })
      .then(cb)
      .catch(function () { showToast(t("networkError"), "error"); });
  }

  /* ================================================================
   * NAVIGATION / LOADING
   * ================================================================ */

  function loadDirectory(path) {
    fm.path = path;
    fm.selected = [];
    updateSelectionBar();
    renderBreadcrumb(path);
    renderLoading();

    ajax("lumia_files_list", { path: path }, function (data) {
      if (!data.success) {
        showToast((data.data && data.data.message) || t("error"), "error");
        renderEmpty();
        return;
      }
      renderTable(data.data.items);
    });
  }

  /* ================================================================
   * BREADCRUMB
   * ================================================================ */

  function renderBreadcrumb(path) {
    var bc = document.getElementById("lumia-files-breadcrumb");
    // Keep only the home button
    while (bc.children.length > 1) bc.removeChild(bc.lastChild);

    if (!path) return;

    var parts = path.split("/").filter(Boolean);
    var built = "";

    parts.forEach(function (segment) {
      built = built ? built + "/" + segment : segment;

      var sep = document.createElement("span");
      sep.className = "lumia-files__bc-sep";
      sep.textContent = "/";
      bc.appendChild(sep);

      var btn = document.createElement("button");
      btn.type = "button";
      btn.className = "lumia-files__bc-item";
      btn.textContent = segment;
      (function (p) {
        btn.addEventListener("click", function () { loadDirectory(p); });
      })(built);
      bc.appendChild(btn);
    });
  }

  /* Home button */
  document.addEventListener("DOMContentLoaded", function () {
    var homeBtn = document.querySelector(".lumia-files__bc-home");
    if (homeBtn) {
      homeBtn.addEventListener("click", function () { loadDirectory(""); });
    }
  });

  /* ================================================================
   * TABLE RENDERING
   * ================================================================ */

  function renderLoading() {
    var tbody = document.getElementById("lumia-files-tbody");
    if (!tbody) return;
    tbody.innerHTML =
      '<tr class="lumia-files__row-empty"><td colspan="7">' +
      escHtml(t("loading")) +
      "</td></tr>";
  }

  function renderEmpty() {
    var tbody = document.getElementById("lumia-files-tbody");
    if (!tbody) return;
    tbody.innerHTML =
      '<tr class="lumia-files__row-empty"><td colspan="7">' +
      escHtml(t("emptyFolder")) +
      "</td></tr>";
  }

  function renderTable(items) {
    var tbody = document.getElementById("lumia-files-tbody");
    var checkAll = document.getElementById("lumia-files-check-all");
    if (!tbody) return;

    if (checkAll) { checkAll.checked = false; checkAll.indeterminate = false; }
    fm.selected = [];

    if (!items || items.length === 0) {
      renderEmpty();
      return;
    }

    tbody.innerHTML = "";
    items.forEach(function (item) {
      tbody.appendChild(buildRow(item));
    });
  }

  function buildRow(item) {
    var tr = document.createElement("tr");
    tr.className = "lumia-files__row" + (item.type === "dir" ? " lumia-files__row--dir" : "");
    tr.setAttribute("data-path", item.path);

    // Checkbox
    var tdCb = document.createElement("td");
    tdCb.className = "lumia-files__col-check";
    var cb = document.createElement("input");
    cb.type = "checkbox";
    cb.className = "lumia-files__row-check";
    cb.setAttribute("data-path", item.path);
    cb.addEventListener("change", onRowCheckChange);
    tdCb.appendChild(cb);
    tr.appendChild(tdCb);

    // Name
    var tdName = document.createElement("td");
    tdName.className = "lumia-files__col-name";
    var nameBtn = document.createElement("button");
    nameBtn.type = "button";
    nameBtn.className = "lumia-files__name-btn";
    nameBtn.innerHTML =
      getFileIcon(item) +
      '<span class="lumia-files__name-text">' + escHtml(item.name) + "</span>";
    if (item.type === "dir") {
      nameBtn.addEventListener("click", function () { loadDirectory(item.path); });
    } else {
      nameBtn.addEventListener("click", function () {
        if (isEditable(item.ext)) openEditor(item.path, item.name);
        else triggerDownload(item.path, "file");
      });
    }
    tdName.appendChild(nameBtn);
    tr.appendChild(tdName);

    // Size
    tr.appendChild(cell("lumia-files__col-size", item.size_fmt || (item.type === "dir" ? "—" : "")));

    // Modified
    tr.appendChild(cell("lumia-files__col-modified", item.modified_fmt || ""));

    // Permissions
    var tdPerms = document.createElement("td");
    tdPerms.className = "lumia-files__col-perms";
    if (item.perms) {
      tdPerms.innerHTML = '<span class="lumia-files__perms">' + escHtml(item.perms) + "</span>";
    }
    tr.appendChild(tdPerms);

    // Owner
    tr.appendChild(cell("lumia-files__col-owner", item.owner || ""));

    // Actions
    var tdAct = document.createElement("td");
    tdAct.className = "lumia-files__col-actions";
    tdAct.innerHTML = buildActions(item);
    bindActions(tdAct, item);
    tr.appendChild(tdAct);

    return tr;
  }

  function cell(cls, text) {
    var td = document.createElement("td");
    td.className = cls;
    td.textContent = text;
    return td;
  }

  /* ================================================================
   * ROW ACTIONS
   * ================================================================ */

  function buildActions(item) {
    var btns = "";
    if (item.type === "file" && isEditable(item.ext)) {
      btns += actionBtn("edit", t("actionEdit"), ICON_EDIT, "");
    }
    btns += actionBtn("download", t("actionDownload"), ICON_DOWNLOAD, "");
    btns += actionBtn("copy-link", t("actionCopyLink"), ICON_LINK, "");
    btns += actionBtn("rename", t("actionRename"), ICON_RENAME, "");
    btns += actionBtn("move", t("actionMove"), ICON_MOVE, "");
    if (item.ext === "zip" || item.ext === "gz" || item.ext === "tar") {
      btns += actionBtn("extract", t("actionExtract"), ICON_EXTRACT, "");
    }
    btns += actionBtn("delete", t("actionDelete"), ICON_DELETE, "lumia-files__action-btn--danger");
    return '<div class="lumia-files__actions">' + btns + "</div>";
  }

  function actionBtn(action, title, icon, extra) {
    return (
      '<button type="button" class="lumia-files__action-btn ' +
      extra +
      '" data-action="' +
      action +
      '" data-lumia-tip="' +
      escHtml(title) +
      '">' +
      icon +
      "</button>"
    );
  }

  function bindActions(td, item) {
    td.addEventListener("click", function (e) {
      var btn = e.target.closest("[data-action]");
      if (!btn) return;
      switch (btn.dataset.action) {
        case "edit":      openEditor(item.path, item.name); break;
        case "download":  triggerDownload(item.path, item.type); break;
        case "copy-link": copyLink(item.path); break;
        case "rename":    openRenameModal(item.path, item.name); break;
        case "move":      openMoveModal(item.path); break;
        case "extract":   extractZip(item.path); break;
        case "delete":    deleteItems([item.path]); break;
      }
    });
  }

  /* ================================================================
   * FILE OPERATIONS
   * ================================================================ */

  function deleteItems(paths) {
    window.lumiaModal.open({
      title:        t("actionDelete"),
      message:      t("confirmDelete"),
      confirmLabel: t("actionDelete"),
      cancelLabel:  t("cancel"),
      danger:       true,
      onConfirm:    function () {
        ajax("lumia_files_delete", { paths: paths }, function (data) {
          if (!data.success) {
            showToast((data.data && data.data.message) || t("error"), "error");
            return;
          }
          showToast(t("deleted"), "success");
          loadDirectory(fm.path);
        });
      },
    });
  }

  function extractZip(path) {
    ajax("lumia_files_extract", { path: path }, function (data) {
      if (!data.success) {
        showToast((data.data && data.data.message) || t("error"), "error");
        return;
      }
      showToast(t("archiveExtracted"), "success");
      loadDirectory(fm.path);
    });
  }

  function triggerDownload(path, itemType) {
    if (itemType === "dir") {
      var name = path.split("/").filter(Boolean).pop() || t("defaultFolderName");
      downloadDirAsZip(path, name);
      return;
    }
    var url =
      fm.downloadUrl +
      "&path=" + encodeURIComponent(path) +
      "&_wpnonce=" + encodeURIComponent(fm.downloadNonce);
    window.location.href = url;
  }

  function downloadDirAsZip(path, name) {
    setRowDownloadLoading(path, true);
    showToast(fmt(t("compressing"), name), "info");

    var url =
      fm.downloadUrl +
      "&path=" + encodeURIComponent(path) +
      "&_wpnonce=" + encodeURIComponent(fm.downloadNonce);

    fetch(url, { credentials: "same-origin" })
      .then(function (r) {
        if (!r.ok) throw new Error("HTTP " + r.status);
        return r.blob();
      })
      .then(function (blob) {
        var objUrl = URL.createObjectURL(blob);
        var a = document.createElement("a");
        a.href = objUrl;
        a.download = name + ".zip";
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(function () { URL.revokeObjectURL(objUrl); }, 1000);
        setRowDownloadLoading(path, false);
        showToast(fmt(t("zipDownloaded"), name + ".zip"), "success");
      })
      .catch(function () {
        setRowDownloadLoading(path, false);
        showToast(fmt(t("compressError"), name), "error");
      });
  }

  function setRowDownloadLoading(path, loading) {
    var tbody = document.getElementById("lumia-files-tbody");
    if (!tbody) return;
    var rows = tbody.querySelectorAll("tr[data-path]");
    for (var i = 0; i < rows.length; i++) {
      if (rows[i].getAttribute("data-path") === path) {
        var dlBtn = rows[i].querySelector('[data-action="download"]');
        if (!dlBtn) break;
        dlBtn.innerHTML = loading ? ICON_SPINNER : ICON_DOWNLOAD;
        dlBtn.disabled  = loading;
        break;
      }
    }
  }

  function copyLink(path) {
    var url =
      fm.downloadUrl +
      "&path=" + encodeURIComponent(path) +
      "&_wpnonce=" + encodeURIComponent(fm.downloadNonce);

    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url).then(function () {
        showToast(t("linkCopied"), "success");
      });
    } else {
      var ta = document.createElement("textarea");
      ta.value = url;
      ta.style.position = "fixed";
      ta.style.opacity = "0";
      document.body.appendChild(ta);
      ta.focus();
      ta.select();
      try { document.execCommand("copy"); showToast(t("linkCopied"), "success"); }
      catch (_) { showToast(t("copyFailed"), "error"); }
      document.body.removeChild(ta);
    }
  }

  /* ================================================================
   * SELECTION
   * ================================================================ */

  function initCheckAll() {
    var checkAll = document.getElementById("lumia-files-check-all");
    if (!checkAll) return;

    checkAll.addEventListener("change", function () {
      var all = document.querySelectorAll(".lumia-files__row-check");
      fm.selected = [];
      all.forEach(function (cb) {
        cb.checked = checkAll.checked;
        cb.closest("tr").classList.toggle("lumia-files__row--selected", checkAll.checked);
        if (checkAll.checked) fm.selected.push(cb.getAttribute("data-path"));
      });
      updateSelectionBar();
    });
  }

  function onRowCheckChange() {
    var all = document.querySelectorAll(".lumia-files__row-check");
    fm.selected = [];
    all.forEach(function (cb) {
      cb.closest("tr").classList.toggle("lumia-files__row--selected", cb.checked);
      if (cb.checked) fm.selected.push(cb.getAttribute("data-path"));
    });

    var checkAll = document.getElementById("lumia-files-check-all");
    if (checkAll) {
      checkAll.checked = fm.selected.length > 0 && fm.selected.length === all.length;
      checkAll.indeterminate = fm.selected.length > 0 && fm.selected.length < all.length;
    }
    updateSelectionBar();
  }

  function updateSelectionBar() {
    var bar   = document.getElementById("lumia-files-selection-bar");
    var count = document.getElementById("lumia-files-selection-count");
    if (!bar) return;

    if (fm.selected.length > 0) {
      bar.style.display = "";
      if (count) count.textContent = fmt(t("selectedCount"), fm.selected.length);
    } else {
      bar.style.display = "none";
    }
  }

  /* ================================================================
   * SELECTION BAR (BULK ZIP / DELETE)
   * ================================================================ */

  function initSelectionBar() {
    var zipBtn = document.getElementById("lumia-files-zip-btn");
    var delBtn = document.getElementById("lumia-files-delete-btn");

    if (zipBtn) {
      zipBtn.addEventListener("click", function () {
        if (!fm.selected.length) return;
        var name = "archive-" + Date.now() + ".zip";
        zipBtn.disabled = true;
        ajax("lumia_files_zip", { paths: fm.selected, name: name, parent: fm.path }, function (data) {
          zipBtn.disabled = false;
          if (!data.success) {
            showToast((data.data && data.data.message) || t("error"), "error");
            return;
          }
          showToast(t("archiveCreatedDownload"), "success");
          triggerDownload(data.data.path);
          loadDirectory(fm.path);
        });
      });
    }

    if (delBtn) {
      delBtn.addEventListener("click", function () {
        if (!fm.selected.length) return;
        deleteItems(fm.selected.slice());
      });
    }
  }

  /* ================================================================
   * TOOLBAR (NEW FOLDER)
   * ================================================================ */

  function initToolbar() {
    var mkdirBtn     = document.getElementById("lumia-files-mkdir-btn");
    var mkdirConfirm = document.getElementById("lumia-mkdir-confirm");
    var mkdirInput   = document.getElementById("lumia-mkdir-input");

    if (mkdirBtn) {
      mkdirBtn.addEventListener("click", function () {
        if (mkdirInput) mkdirInput.value = "";
        window.lumiaModalOpen("lumia-modal-mkdir");
      });
    }

    if (mkdirConfirm && mkdirInput) {
      function doMkdir() {
        var name = mkdirInput.value.trim();
        if (!name) return;
        ajax("lumia_files_mkdir", { parent: fm.path, name: name }, function (data) {
          if (!data.success) {
            showToast((data.data && data.data.message) || t("error"), "error");
            return;
          }
          window.lumiaModalClose("lumia-modal-mkdir");
          showToast(t("folderCreated"), "success");
          loadDirectory(fm.path);
        });
      }

      mkdirConfirm.addEventListener("click", doMkdir);
      mkdirInput.addEventListener("keydown", function (e) {
        if (e.key === "Enter") doMkdir();
      });
    }
  }

  /* ================================================================
   * UPLOAD
   * ================================================================ */

  function initUpload() {
    var input = document.getElementById("lumia-files-upload-input");
    if (!input) return;

    input.addEventListener("change", function () {
      if (!this.files || !this.files.length) return;
      uploadFiles(this.files);
      this.value = "";
    });
  }

  function uploadFiles(files) {
    var form = new FormData();
    form.append("action", "lumia_files_upload");
    form.append("nonce", fm.nonce);
    form.append("path", fm.path);

    for (var i = 0; i < files.length; i++) {
      form.append("files[]", files[i], files[i].name);
    }

    showToast(t("uploading"), "info");

    fetch(fm.ajaxUrl, { method: "POST", credentials: "same-origin", body: form })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.success) {
          showToast((data.data && data.data.message) || t("uploadError"), "error");
          return;
        }
        showToast(data.data.message, "success");
        loadDirectory(fm.path);
      })
      .catch(function () { showToast(t("uploadError"), "error"); });
  }

  /* ================================================================
   * DRAG & DROP
   * ================================================================ */

  function initDropZone() {
    var overlay = document.getElementById("lumia-files-drop-overlay");
    if (!overlay) return;

    document.addEventListener("dragenter", function (e) {
      if (!e.dataTransfer || !e.dataTransfer.types) return;
      var hasFiles = Array.prototype.indexOf.call(e.dataTransfer.types, "Files") !== -1;
      if (!hasFiles) return;
      fm.dragCounter++;
      overlay.style.display = "";
    });

    document.addEventListener("dragleave", function () {
      fm.dragCounter = Math.max(0, fm.dragCounter - 1);
      if (fm.dragCounter === 0) overlay.style.display = "none";
    });

    document.addEventListener("dragover", function (e) { e.preventDefault(); });

    document.addEventListener("drop", function (e) {
      e.preventDefault();
      fm.dragCounter = 0;
      overlay.style.display = "none";
      if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
        uploadFiles(e.dataTransfer.files);
      }
    });
  }

  /* ================================================================
   * CODE EDITOR
   * ================================================================ */

  function updateSaveBtn() {
    var btn = document.getElementById("lumia-editor-save");
    if (btn) btn.disabled = !fm.editorDirty;
  }

  function initEditor() {
    var editorEl   = document.getElementById("lumia-files-editor");
    var closeBtn   = document.getElementById("lumia-editor-close");
    var saveBtn    = document.getElementById("lumia-editor-save");
    if (!closeBtn || !saveBtn) return;

    if (saveBtn) saveBtn.disabled = true;

    closeBtn.addEventListener("click", closeEditor);
    saveBtn.addEventListener("click", saveEditorContent);

    // Click on the backdrop (outside the panel) → close
    if (editorEl) {
      editorEl.addEventListener("click", function (e) {
        if (e.target === editorEl) closeEditor();
      });
    }

    document.addEventListener("keydown", function (e) {
      if (!editorEl || editorEl.style.display === "none") return;

      if (e.key === "Escape") {
        // Escape first closes the autocompletion list: closing it AND leaving
        // the editor with a single key press would lose the input in progress.
        if (fm.editorCm && fm.editorCm.codemirror.state.completionActive) return;
        e.preventDefault();
        closeEditor();
      }
      if ((e.ctrlKey || e.metaKey) && e.key === "s") {
        e.preventDefault();
        if (!fm.editorDirty) return;
        saveEditorContent();
      }
    });
  }

  function openEditor(path, name) {
    ajax("lumia_files_get_content", { path: path }, function (data) {
      if (!data.success) {
        showToast((data.data && data.data.message) || t("error"), "error");
        return;
      }

      fm.editorPath = path;
      var editorEl   = document.getElementById("lumia-files-editor");
      var filenameEl = document.getElementById("lumia-editor-filename");
      var textarea   = document.getElementById("lumia-editor-textarea");
      var content    = data.data.content;

      if (filenameEl) filenameEl.textContent = name;
      if (editorEl) editorEl.style.display = "";

      if (textarea) {
        // A previous CodeMirror instance would survive the file change:
        // we always start again from the bare textarea.
        destroyCodeMirror();

        textarea.value = content;
        textarea.style.display = "";

        if (!fm.editorInputBound) {
          fm.editorInputBound = true;
          textarea.addEventListener("input", markEditorDirty);
        }

        if (!initCodeMirror(name)) {
          setTimeout(function () { textarea.focus(); }, 30);
        }
      }

      fm.editorDirty = false;
      updateSaveBtn();
    });
  }

  function markEditorDirty() {
    if (fm.editorDirty) return;
    fm.editorDirty = true;
    updateSaveBtn();
  }

  /**
   * Turns the textarea into CodeMirror.
   *
   * Everything comes from WordPress: wp.codeEditor.initialize() wires up
   * highlighting, linting and as-you-type autocompletion (HTML, CSS, JS, PHP).
   * The per-extension settings are prepared on the server side by
   * Files\Module::enqueue_code_editor().
   *
   * @param {string} name File name, from which the extension is taken.
   * @return {boolean} True if the rich editor was actually mounted.
   */
  function initCodeMirror(name) {
    var settings = (lumiaAdmin.codeEditor || {})[fileExt(name)];
    if (!settings || !window.wp || !wp.codeEditor) return false;

    try {
      fm.editorCm = wp.codeEditor.initialize("lumia-editor-textarea", settings);
    } catch (e) {
      // Highlighting unavailable: the bare textarea remains perfectly usable.
      if (window.console && console.warn) console.warn("[LUMIA] CodeMirror:", e);
      fm.editorCm = null;
      return false;
    }

    var cm = fm.editorCm.codemirror;
    cm.on("change", markEditorDirty);
    // The height is carried by the flex container (see files.css).
    cm.setSize("100%", "100%");
    setTimeout(function () { cm.refresh(); cm.focus(); }, 30);

    return true;
  }

  function destroyCodeMirror() {
    if (!fm.editorCm) return;
    try {
      fm.editorCm.codemirror.toTextArea();
    } catch (e) { /* already detached */ }
    fm.editorCm = null;
  }

  /** Current editor content, rich or bare. */
  function editorValue() {
    if (fm.editorCm) return fm.editorCm.codemirror.getValue();
    var ta = document.getElementById("lumia-editor-textarea");
    return ta ? ta.value : "";
  }

  function fileExt(name) {
    var parts = String(name || "").split(".");
    return parts.length > 1 ? parts.pop().toLowerCase() : "";
  }

  function closeEditor() {
    if (fm.editorDirty && typeof window.lumiaModal !== "undefined") {
      window.lumiaModal.open({
        title:        t("editorUnsavedTitle"),
        message:      t("editorUnsavedMessage"),
        confirmLabel: t("editorUnsavedLeave"),
        cancelLabel:  t("editorUnsavedStay"),
        danger:       true,
        onConfirm:    doCloseEditor,
      });
      return;
    }
    doCloseEditor();
  }

  function doCloseEditor() {
    destroyCodeMirror();
    var editorEl = document.getElementById("lumia-files-editor");
    if (editorEl) editorEl.style.display = "none";
    fm.editorPath  = null;
    fm.editorDirty = false;
    updateSaveBtn();
  }

  function saveEditorContent() {
    if (!fm.editorPath) return;

    var content = editorValue();

    var saveBtn = document.getElementById("lumia-editor-save");
    if (saveBtn) saveBtn.disabled = true;

    ajax("lumia_files_save_content", { path: fm.editorPath, content: content }, function (data) {
      if (!data.success) {
        showToast((data.data && data.data.message) || t("error"), "error");
        // Re-enable the button on error
        if (saveBtn) saveBtn.disabled = false;
        return;
      }
      fm.editorDirty = false;
      updateSaveBtn();
      showToast(t("fileSaved"), "success");
    });
  }

  function isEditable(ext) {
    var list = [
      "php", "js", "ts", "css", "html", "htm", "xml", "svg",
      "json", "txt", "md", "sh", "bash", "sql", "htaccess", "env",
      "yml", "yaml", "ini", "conf", "config", "lock", "log", "htpasswd",
    ];
    return list.indexOf(ext) !== -1;
  }

  /* ================================================================
   * RENAME / MOVE MODALS
   * ================================================================ */

  function initModals() {
    var renameConfirm = document.getElementById("lumia-rename-confirm");
    var renameInput   = document.getElementById("lumia-rename-input");
    var moveConfirm   = document.getElementById("lumia-move-confirm");
    var moveInput     = document.getElementById("lumia-move-input");

    if (renameConfirm) {
      renameConfirm.addEventListener("click", function () {
        var newName = (renameInput && renameInput.value.trim()) || "";
        if (!newName || !fm.renamePath) return;
        ajax("lumia_files_rename", { path: fm.renamePath, new_name: newName }, function (data) {
          if (!data.success) { showToast((data.data && data.data.message) || t("error"), "error"); return; }
          showToast(t("renamed"), "success");
          window.lumiaModalClose("lumia-modal-rename");
          loadDirectory(fm.path);
        });
      });
    }

    if (renameInput) {
      renameInput.addEventListener("keydown", function (e) {
        if (e.key === "Enter") renameConfirm && renameConfirm.click();
      });
    }

    if (moveConfirm) {
      moveConfirm.addEventListener("click", function () {
        var dst = (moveInput && moveInput.value.trim()) || "";
        if (!fm.movePath) return;
        ajax("lumia_files_move", { src: fm.movePath, dst: dst }, function (data) {
          if (!data.success) { showToast((data.data && data.data.message) || t("error"), "error"); return; }
          showToast(t("moved"), "success");
          window.lumiaModalClose("lumia-modal-move");
          loadDirectory(fm.path);
        });
      });
    }

    if (moveInput) {
      moveInput.addEventListener("keydown", function (e) {
        if (e.key === "Enter") moveConfirm && moveConfirm.click();
      });
    }
  }

  function openRenameModal(path, name) {
    fm.renamePath = path;
    var input = document.getElementById("lumia-rename-input");
    if (input) input.value = name || "";
    window.lumiaModalOpen("lumia-modal-rename");
  }

  function openMoveModal(path) {
    fm.movePath = path;
    var input = document.getElementById("lumia-move-input");
    if (input) input.value = fm.path;
    window.lumiaModalOpen("lumia-modal-move");
  }

  /* ================================================================
   * FILE ICONS (inline SVG)
   * ================================================================ */

  var ICON_FOLDER  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>';
  var ICON_CODE    = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14,2 14,8 20,8"/><polyline points="10,15 8,17 10,19"/><polyline points="14,15 16,17 14,19"/></svg>';
  var ICON_IMAGE_F = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.1-3.1a2 2 0 0 0-2.8 0L6 21"/></svg>';
  var ICON_ARCHIVE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M13.659 22H18a2 2 0 0 0 2-2V8a2.4 2.4 0 0 0-.706-1.706l-3.588-3.588A2.4 2.4 0 0 0 14 2H6a2 2 0 0 0-2 2v11.5"/><path d="M14 2v5a1 1 0 0 0 1 1h5"/><path d="M8 12v-1"/><path d="M8 18v-2"/><path d="M8 7V6"/><circle cx="8" cy="20" r="2"/></svg>';
  var ICON_TEXT    = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14,2 14,8 20,8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10,9 9,9 8,9"/></svg>';
  var ICON_FILE    = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14,2 14,8 20,8"/></svg>';
  var ICON_SPINNER = '<svg class="lumia-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 2a10 10 0 1 0 10 10"/></svg>';

  // Map extension → CSS class (color per language)
  var EXT_CLASS = {
    php: "php",    phtml: "php",
    js: "js",      mjs: "js",   cjs: "js",
    ts: "ts",      tsx: "ts",
    css: "css",    scss: "css", sass: "css", less: "css",
    html: "html",  htm: "html",
    json: "json",  jsonc: "json",
    xml: "xml",
    svg: "svg",
    md: "md",      mdx: "md",
    sql: "sql",
    sh: "sh",      bash: "sh",  zsh: "sh",
    yml: "yaml",   yaml: "yaml",
    py: "py",      rb: "rb",
  };

  function getFileIcon(item) {
    if (item.type === "dir") {
      return '<span class="lumia-files__icon lumia-files__icon--folder">' + ICON_FOLDER + "</span>";
    }
    var imgs  = ["jpg", "jpeg", "png", "gif", "svg", "webp", "ico", "bmp", "avif"];
    var arch  = ["zip", "tar", "gz", "bz2", "7z", "rar"];
    var texts = ["txt", "log", "ini", "conf", "env", "htaccess", "htpasswd", "lock"];
    var ext   = item.ext || "";
    var icon, cls;

    if (EXT_CLASS[ext]) {
      icon = ICON_CODE;
      cls  = "lumia-files__icon--lang-" + EXT_CLASS[ext];
    } else if (imgs.indexOf(ext) !== -1) {
      icon = ICON_IMAGE_F;
      cls  = "lumia-files__icon--image";
    } else if (arch.indexOf(ext) !== -1) {
      icon = ICON_ARCHIVE;
      cls  = "lumia-files__icon--archive";
    } else if (texts.indexOf(ext) !== -1) {
      icon = ICON_TEXT;
      cls  = "lumia-files__icon--text";
    } else {
      icon = ICON_FILE;
      cls  = "";
    }

    return '<span class="lumia-files__icon ' + cls + '">' + icon + "</span>";
  }

  /* ================================================================
   * ACTION ICONS
   * ================================================================ */

  var ICON_EDIT    = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>';
  var ICON_DOWNLOAD = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7,10 12,15 17,10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>';
  var ICON_LINK     = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>';
  var ICON_RENAME   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>';
  var ICON_MOVE     = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="5,9 2,12 5,15"/><polyline points="9,5 12,2 15,5"/><line x1="2" y1="12" x2="22" y2="12"/><line x1="12" y1="2" x2="12" y2="22"/></svg>';
  var ICON_EXTRACT  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12H19"/><path d="m12 5 7 7-7 7"/></svg>';
  var ICON_DELETE   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3,6 5,6 21,6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>';

  /* ================================================================
   * UTILITIES
   * ================================================================ */

  function escHtml(str) {
    return String(str)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  /** Translated string of the module, "" when the key is missing. */
  function t(key) {
    return (fm.i18n && fm.i18n[key]) || "";
  }

  /** Substitutes the single placeholder (%s or %d) of a translated format. */
  function fmt(format, value) {
    return String(format).replace(/%(?:\d+\$)?[sd]/, function () { return value; });
  }

  function showToast(message, type) {
    if (typeof window.lumiaShowToast === "function") {
      window.lumiaShowToast(message, type || "success");
    }
  }
})();
