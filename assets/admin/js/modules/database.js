(function () {
  "use strict";

  var db = {
    nonce:        '',
    prefix:       '',
    currentTable: null,
    dropTarget:   null,
    tables:       [],
  };

  var dataState = {
    page: 1, perPage: 50, search: '', orderCol: '', orderDir: 'ASC', columns: [], primary: ''
  };

  document.addEventListener('DOMContentLoaded', function () {
    var wrap = document.getElementById('lumia-db-manager');
    if (!wrap) return;
    db.nonce = wrap.dataset.nonce;
    // La hauteur du panneau est entièrement gérée en CSS (flex depuis .lumia-admin-main,
    // pattern :has(.lumia-db) dans database.css) — plus aucun calcul JS ici.
    loadTables();
    initSearch();
    initTabs();
    initHeaderActions();
    var cleanupLink = document.getElementById('lumia-db-cleanup-link');
    if (cleanupLink) cleanupLink.addEventListener('click', openCleanup);
  });

  // Raccourci de traduction : lit window.lumiaAdmin.i18n avec repli.
  function t(key, fallback) {
    return (lumiaAdmin && lumiaAdmin.i18n && lumiaAdmin.i18n[key]) || fallback;
  }

  function ajax(action, data, cb, onError) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('nonce', db.nonce);
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    fetch(lumiaAdmin.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.success) { cb(res.data); return; }
        var msg = (res.data && res.data.message) || t('error', 'Erreur');
        showToast(msg, 'error');
        if (typeof onError === 'function') onError(msg, res.data || {});
      })
      .catch(function () {
        var msg = t('networkError', 'Erreur réseau');
        showToast(msg, 'error');
        if (typeof onError === 'function') onError(msg, {});
      });
  }

  function showToast(msg, type) {
    if (typeof window.lumiaShowToast === 'function') window.lumiaShowToast(msg, type);
  }

  function loadTables() {
    ajax('lumia_db_get_tables', {}, function (data) {
      db.tables = data.tables;
      db.prefix = data.prefix;
      renderTableList(db.tables);
    });
  }

  function renderTableList(tables) {
    var list = document.getElementById('lumia-db-table-list');
    if (!list) return;
    if (!tables.length) {
      list.innerHTML = '<p class="lumia-db__no-tables">' + escHtml(t('noTables', 'Aucune table trouvée.')) + '</p>';
      return;
    }

    // Grouper : tables WP préfixées d'abord, puis les autres
    var wpTables    = tables.filter(function (t) { return t.is_wp_prefix; });
    var otherTables = tables.filter(function (t) { return !t.is_wp_prefix; });

    var html = '';
    if (wpTables.length) {
      html += '<div class="lumia-db__table-group-label">WordPress</div>';
      wpTables.forEach(function (t) { html += renderTableItem(t); });
    }
    if (otherTables.length) {
      html += '<div class="lumia-db__table-group-label">Autres tables</div>';
      otherTables.forEach(function (t) { html += renderTableItem(t); });
    }
    list.innerHTML = html;

    list.querySelectorAll('.lumia-db__table-item').forEach(function (el) {
      el.addEventListener('click', function () {
        selectTable(el.dataset.table);
      });
    });
  }

  function renderTableItem(t) {
    // On garde toujours le nom complet préfixé (ex. wp_users, pas users) pour éviter
    // toute confusion lors de l'écriture d'une requête SQL. Le préfixe reste indiqué
    // dans le libellé de groupe « WordPress (wp_) ».
    var label = t.name;
    var rows  = (t.approx ? '≈ ' : '') + t.rows.toLocaleString();
    return '<div class="lumia-db__table-item" data-table="' + escHtml(t.name) + '" data-lumia-tip="' + escHtml(t.name) + '" data-lumia-tip-placement="right">' +
           '<span class="lumia-db__table-item-name">' + escHtml(label) + '</span>' +
           '<span class="lumia-db__table-item-rows">' + rows + '</span>' +
           '</div>';
  }

  function selectTable(tableName) {
    db.currentTable = tableName;
    // Réinitialiser l'état de la vue données pour la nouvelle table
    dataState.page = 1;
    dataState.search = '';
    dataState.orderCol = '';
    dataState.orderDir = 'ASC';
    // Mettre à jour la sélection visuelle dans la sidebar
    document.querySelectorAll('.lumia-db__table-item').forEach(function (el) {
      el.classList.toggle('is-active', el.dataset.table === tableName);
    });
    // Afficher la vue table, masquer l'état vide et le nettoyage
    setCleanupActive(false);
    document.getElementById('lumia-db-empty').style.display = 'none';
    document.getElementById('lumia-db-table-view').style.display = '';
    // Mettre à jour le nom/meta dans le header
    var t = db.tables.find(function (t) { return t.name === tableName; });
    if (t) {
      document.getElementById('lumia-db-table-name').textContent = t.name;
      document.getElementById('lumia-db-table-meta').textContent =
        (t.approx ? '≈ ' : '') + t.rows.toLocaleString() + ' lignes · ' + formatSize(t.size);
    }
    // Activer l'onglet Données par défaut (implémenté au prompt 1-07)
    switchTab('data');
  }

  // Onglets : composant partagé lumia-tabs (admin.js). On ne fait ici que
  // charger la vue ouverte. Pas de table choisie (restauration de l'onglet
  // mémorisé au chargement de la page) : rien à charger.
  function initTabs() {
    document.addEventListener('lumia:tab', function (e) {
      if (e.detail.group !== 'database' || !db.currentTable) return;
      if (e.detail.name === 'data')      loadData(dataState.page);
      if (e.detail.name === 'structure') loadStructure();
      if (e.detail.name === 'query')     initQueryTab();
    });
  }

  function switchTab(tab) {
    window.lumiaTabs.activate('database', tab);
  }

  /* ================================================================
   * ONGLET DONNÉES
   * ================================================================ */

  function loadData(page) {
    page = page || 1;
    dataState.page = page;
    var content = document.getElementById('lumia-db-tab-data');
    if (!content) return;
    content.innerHTML = '<div class="lumia-db__loading">' + escHtml(t('loading', 'Chargement…')) + '</div>';

    ajax('lumia_db_get_rows', {
      table:     db.currentTable,
      page:      page,
      per_page:  dataState.perPage,
      search:    dataState.search,
      order_col: dataState.orderCol,
      order_dir: dataState.orderDir,
    }, function (data) {
      dataState.columns = data.columns;
      dataState.primary = data.primary;
      // Sans recherche, `total` est le compte exact : il remplace l'estimation
      // affichée pour les grosses tables (liste et en-tête).
      if (!dataState.search) {
        var tbl = db.tables.find(function (t) { return t.name === db.currentTable; });
        if (tbl && (tbl.approx || tbl.rows !== data.total)) {
          tbl.rows = data.total;
          tbl.approx = false;
          document.getElementById('lumia-db-table-meta').textContent =
            data.total.toLocaleString() + ' lignes · ' + formatSize(tbl.size);
          var item = document.querySelector('.lumia-db__table-item[data-table="' + db.currentTable + '"] .lumia-db__table-item-rows');
          if (item) item.textContent = data.total.toLocaleString();
        }
      }
      renderDataTable(content, data);
    });
  }

  function renderDataTable(container, data) {
    var cols    = data.columns;
    var primary = data.primary;

    // Toolbar : recherche + info + sélecteur lignes/page + pagination
    var rowsLabel = t('rowsLabel', 'lignes');
    var html = '<div class="lumia-db__data-toolbar">';
    html += '<div class="lumia-search lumia-search--sm lumia-db__search--data">' +
            '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>' +
            '<input type="search" class="lumia-search__input lumia-db__data-search" ' +
            'placeholder="' + escHtml(t('searchInTable', 'Rechercher dans la table…')) + '" value="' + escHtml(dataState.search) + '">' +
            '</div>';
    html += '<span class="lumia-db__data-count">' + data.total.toLocaleString() + ' ' + escHtml(rowsLabel) + '</span>';
    html += '<div class="lumia-db__toolbar-right">';
    html += renderPerPage();
    html += renderPagination(data.page, data.pages);
    html += '</div>';
    html += '</div>';

    // Tableau
    html += '<div class="lumia-db__data-table-wrap"><table class="lumia-db__data-table"><thead><tr>';
    cols.forEach(function (c) {
      var sortClass = '';
      if (dataState.orderCol === c) {
        sortClass = dataState.orderDir === 'DESC' ? ' is-sorted-desc' : ' is-sorted-asc';
      }
      html += '<th class="' + sortClass.trim() + '" data-col="' + escHtml(c) + '">' + escHtml(c) + '</th>';
    });
    html += '<th class="lumia-db__col-actions"></th>';
    html += '</tr></thead><tbody>';

    if (!data.rows.length) {
      html += '<tr><td colspan="' + (cols.length + 1) + '" class="lumia-db__data-empty">' + escHtml(t('noRows', 'Aucune ligne.')) + '</td></tr>';
    } else {
      data.rows.forEach(function (row) {
        var pval = primary ? row[primary] : '';
        html += '<tr data-pval="' + escHtml(String(pval == null ? '' : pval)) + '">';
        cols.forEach(function (c) {
          var val = row[c];
          html += renderCell(c, val, primary);
        });
        html += '<td class="lumia-db__col-actions">';
        if (primary) {
          html += '<button type="button" class="lumia-db__delete-row" data-lumia-tip="' + escHtml(t('delete', 'Supprimer')) + '" aria-label="' + escHtml(t('delete', 'Supprimer')) + '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3,6 5,6 21,6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg></button>';
        }
        html += '</td></tr>';
      });
    }

    html += '</tbody></table></div>';
    container.innerHTML = html;

    bindDataEvents(container, primary);
  }

  function renderCell(col, val, primary) {
    if (val === null) {
      return '<td class="lumia-db__td-null" data-col="' + escHtml(col) + '" data-raw="">NULL</td>';
    }
    var str = String(val);
    // Détecter les données binaires (caractères de contrôle non imprimables)
    if (/[\x00-\x08\x0E-\x1F]/.test(str)) {
      return '<td class="lumia-db__td-binary" data-col="' + escHtml(col) + '">[BINARY DATA]</td>';
    }
    return '<td data-col="' + escHtml(col) + '" data-raw="' + escHtml(str) + '" title="' + escHtml(str) + '">' +
           escHtml(str) + '</td>';
  }

  function renderPerPage() {
    var opts = [25, 50, 100, 200];
    var html = '<label class="lumia-db__per-page">' + escHtml(t('perPageLabel', 'Lignes / page')) +
               ' <select class="lumia-select lumia-select--sm lumia-db__per-page-select">';
    opts.forEach(function (n) {
      html += '<option value="' + n + '"' + (n === dataState.perPage ? ' selected' : '') + '>' + n + '</option>';
    });
    html += '</select></label>';
    return html;
  }

  function renderPagination(page, pages) {
    if (pages <= 1) return '<div class="lumia-db__pagination"></div>';
    var html = '<div class="lumia-db__pagination">';
    html += '<button type="button" class="lumia-db__page-btn" data-page="' + (page - 1) + '"' +
            (page <= 1 ? ' disabled' : '') + '>‹</button>';

    var start = Math.max(1, page - 2);
    var end   = Math.min(pages, start + 4);
    start = Math.max(1, end - 4);

    if (start > 1) {
      html += '<button type="button" class="lumia-db__page-btn" data-page="1">1</button>';
      if (start > 2) html += '<span class="lumia-db__page-ellipsis">…</span>';
    }
    for (var i = start; i <= end; i++) {
      html += '<button type="button" class="lumia-db__page-btn' + (i === page ? ' is-active' : '') +
              '" data-page="' + i + '">' + i + '</button>';
    }
    if (end < pages) {
      if (end < pages - 1) html += '<span class="lumia-db__page-ellipsis">…</span>';
      html += '<button type="button" class="lumia-db__page-btn" data-page="' + pages + '">' + pages + '</button>';
    }

    html += '<button type="button" class="lumia-db__page-btn" data-page="' + (page + 1) + '"' +
            (page >= pages ? ' disabled' : '') + '>›</button>';
    html += '</div>';
    return html;
  }

  function bindDataEvents(container, primary) {
    // Tri par colonne
    container.querySelectorAll('.lumia-db__data-table th[data-col]').forEach(function (th) {
      th.addEventListener('click', function () {
        var col = th.dataset.col;
        if (dataState.orderCol === col) {
          dataState.orderDir = dataState.orderDir === 'ASC' ? 'DESC' : 'ASC';
        } else {
          dataState.orderCol = col;
          dataState.orderDir = 'ASC';
        }
        loadData(1);
      });
    });

    // Sélecteur lignes / page
    var perPageSelect = container.querySelector('.lumia-db__per-page-select');
    if (perPageSelect) {
      perPageSelect.addEventListener('change', function () {
        dataState.perPage = parseInt(perPageSelect.value, 10) || 50;
        loadData(1);
      });
    }

    // Pagination
    container.querySelectorAll('.lumia-db__page-btn[data-page]').forEach(function (btn) {
      if (btn.disabled) return;
      btn.addEventListener('click', function () {
        loadData(parseInt(btn.dataset.page, 10));
      });
    });

    // Recherche (avec debounce)
    var searchInput = container.querySelector('.lumia-db__data-search');
    if (searchInput) {
      var timer = null;
      searchInput.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () {
          dataState.search = searchInput.value;
          loadData(1);
        }, 350);
      });
    }

    // Édition inline
    if (primary) {
      container.querySelectorAll('.lumia-db__data-table tbody td[data-col]').forEach(function (td) {
        if (td.classList.contains('lumia-db__td-binary')) return;
        td.addEventListener('click', function () {
          startInlineEdit(td, primary);
        });
      });
    }

    // Suppression de ligne
    container.querySelectorAll('.lumia-db__delete-row').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        var tr = btn.closest('tr');
        deleteRow(tr, primary);
      });
    });
  }

  function startInlineEdit(td, primary) {
    if (td.classList.contains('is-editing')) return;
    var col = td.dataset.col;
    var raw = td.dataset.raw != null ? td.dataset.raw : '';
    var tr  = td.closest('tr');
    var pval = tr.dataset.pval;

    var original = td.innerHTML;
    var isNull   = td.classList.contains('lumia-db__td-null');
    var longVal  = raw.length > 100;

    td.classList.add('is-editing');
    var wrap = document.createElement('div');
    wrap.className = 'lumia-db__edit-wrap';
    var field = document.createElement(longVal ? 'textarea' : 'input');
    if (!longVal) field.type = 'text';
    field.value = raw;
    // Barre d'action : bouton « Définir NULL ».
    var actions = document.createElement('div');
    actions.className = 'lumia-db__edit-actions';
    var nullBtn = document.createElement('button');
    nullBtn.type = 'button';
    nullBtn.className = 'lumia-db__edit-null';
    nullBtn.textContent = t('setNull', 'Définir NULL');
    actions.appendChild(nullBtn);
    wrap.appendChild(field);
    wrap.appendChild(actions);
    td.innerHTML = '';
    td.appendChild(wrap);
    field.focus();

    var done = false;
    function cancel() {
      if (done) return;
      done = true;
      td.classList.remove('is-editing');
      td.innerHTML = original;
      if (isNull) td.classList.add('lumia-db__td-null');
    }
    function persist(payload, onOk) {
      done = true;
      ajax('lumia_db_update_row', Object.assign({
        table:       db.currentTable,
        primary_col: primary,
        primary_val: pval,
        col:         col,
      }, payload), onOk);
    }
    function save() {
      if (done) return;
      var newVal = field.value;
      if (newVal === raw && !isNull) { cancel(); return; }
      persist({ value: newVal }, function () {
        td.classList.remove('is-editing', 'lumia-db__td-null');
        td.dataset.raw = newVal;
        td.title = newVal;
        td.textContent = newVal;
        showToast(t('rowUpdated', 'Ligne mise à jour'), 'success');
      });
    }
    function saveNull() {
      if (done) return;
      persist({ value: '', set_null: '1' }, function () {
        td.classList.remove('is-editing');
        td.classList.add('lumia-db__td-null');
        td.dataset.raw = '';
        td.removeAttribute('title');
        td.textContent = 'NULL';
        showToast(t('rowUpdated', 'Ligne mise à jour'), 'success');
      });
    }

    // mousedown (et non click) pour devancer le blur du champ qui annulerait l'édition.
    nullBtn.addEventListener('mousedown', function (e) { e.preventDefault(); saveNull(); });
    field.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !longVal) { e.preventDefault(); save(); }
      else if (e.key === 'Enter' && longVal && (e.ctrlKey || e.metaKey)) { e.preventDefault(); save(); }
      else if (e.key === 'Escape') { e.preventDefault(); cancel(); }
    });
    field.addEventListener('blur', function () {
      // Laisse le temps à un éventuel clic sur « NULL » de s'exécuter avant l'annulation.
      setTimeout(function () { if (!done) save(); }, 120);
    });
  }

  function deleteRow(tr, primary) {
    if (!window.lumiaModal) return;
    window.lumiaModal.open({
      danger: true,
      title: t('confirmDelete', 'Supprimer cette ligne ?'),
      message: t('confirmDelete', 'Supprimer cette ligne ?'),
      confirmLabel: t('delete', 'Supprimer'),
      cancelLabel: t('cancel', 'Annuler'),
      onConfirm: function () {
        ajax('lumia_db_delete_row', {
          table:       db.currentTable,
          primary_col: primary,
          primary_val: tr.dataset.pval,
        }, function () {
          tr.parentNode.removeChild(tr);
          var countEl = document.querySelector('.lumia-db__data-count');
          if (countEl) {
            var n = parseInt(countEl.textContent.replace(/\D/g, ''), 10) || 1;
            countEl.textContent = (n - 1).toLocaleString() + ' ' + t('rowsLabel', 'lignes');
          }
          showToast(t('rowDeleted', 'Ligne supprimée'), 'success');
        });
      },
    });
  }

  function initHeaderActions() {
    var addRowBtn = document.getElementById('lumia-db-add-row-btn');
    if (addRowBtn) addRowBtn.addEventListener('click', openInsertModal);

    var insertConfirm = document.getElementById('lumia-db-insert-confirm-btn');
    if (insertConfirm) insertConfirm.addEventListener('click', submitInsert);

    // Menu d'actions déroulant
    var menuBtn      = document.getElementById('lumia-db-actions-btn');
    var menuDropdown = document.getElementById('lumia-db-actions-dropdown');
    if (menuBtn && menuDropdown) {
      menuBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        toggleActionsMenu();
      });
      menuDropdown.addEventListener('click', function (e) {
        var item = e.target.closest('.lumia-db__menu-item');
        if (!item) return;
        closeActionsMenu();
        handleMenuAction(item.dataset.action);
      });
      // Fermer au clic extérieur
      document.addEventListener('click', function (e) {
        var wrap = document.getElementById('lumia-db-actions-menu');
        if (wrap && !wrap.contains(e.target)) closeActionsMenu();
      });
    }

    // Modal de suppression de table (confirmation par saisie)
    var dropInput   = document.getElementById('lumia-db-drop-confirm-input');
    var dropConfirm = document.getElementById('lumia-db-drop-confirm-btn');
    if (dropInput && dropConfirm) {
      dropInput.addEventListener('input', function () {
        dropConfirm.disabled = dropInput.value.trim() !== db.dropTarget;
      });
      dropConfirm.addEventListener('click', function () {
        if (!db.dropTarget || dropInput.value.trim() !== db.dropTarget) return;
        dropTable();
      });
    }
  }

  function toggleActionsMenu() {
    var btn      = document.getElementById('lumia-db-actions-btn');
    var dropdown = document.getElementById('lumia-db-actions-dropdown');
    if (!btn || !dropdown) return;
    var open = dropdown.hasAttribute('hidden');
    if (open) { dropdown.removeAttribute('hidden'); btn.setAttribute('aria-expanded', 'true'); }
    else      { dropdown.setAttribute('hidden', ''); btn.setAttribute('aria-expanded', 'false'); }
  }

  function closeActionsMenu() {
    var btn      = document.getElementById('lumia-db-actions-btn');
    var dropdown = document.getElementById('lumia-db-actions-dropdown');
    if (dropdown) dropdown.setAttribute('hidden', '');
    if (btn) btn.setAttribute('aria-expanded', 'false');
  }

  function handleMenuAction(action) {
    if (!db.currentTable) return;
    if (action === 'export')   { exportTable(); return; }
    if (action === 'query')    { switchTab('query'); return; }
    if (action === 'truncate') { confirmTruncate(); return; }
    if (action === 'drop')     { openDropModal(db.currentTable); return; }
  }

  function exportTable() {
    if (!db.currentTable) return;
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = lumiaAdmin.ajaxUrl;
    var fields = { action: 'lumia_db_export_sql', nonce: db.nonce, table: db.currentTable };
    Object.keys(fields).forEach(function (k) {
      var input = document.createElement('input');
      input.type = 'hidden';
      input.name = k;
      input.value = fields[k];
      form.appendChild(input);
    });
    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
  }

  function confirmTruncate() {
    if (!window.lumiaModal) return;
    window.lumiaModal.open({
      danger: true,
      title: t('confirmTruncate', 'Vider la table ?'),
      message: t('confirmTruncate', 'Vider la table ?'),
      confirmLabel: t('confirm', 'Confirmer'),
      cancelLabel: t('cancel', 'Annuler'),
      onConfirm: function () {
        ajax('lumia_db_truncate', { table: db.currentTable }, function () {
          showToast(t('tableTruncated', 'Table vidée'), 'success');
          loadData(1);
        });
      },
    });
  }

  // La modale sert à la table ouverte comme aux tables d'extensions listées
  // dans le nettoyage : la cible est donc passée, pas lue dans currentTable.
  function openDropModal(table) {
    if (!table) return;
    db.dropTarget = table;
    var nameEl  = document.getElementById('lumia-db-drop-name');
    var input   = document.getElementById('lumia-db-drop-confirm-input');
    var confirm = document.getElementById('lumia-db-drop-confirm-btn');
    if (nameEl) nameEl.textContent = table;
    if (input) input.value = '';
    if (confirm) confirm.disabled = true;
    if (window.lumiaModalOpen) window.lumiaModalOpen('lumia-db-drop-modal');
    if (input) setTimeout(function () { input.focus(); }, 50);
  }

  function dropTable() {
    var table = db.dropTarget;
    ajax('lumia_db_drop_table', { table: table }, function () {
      if (window.lumiaModalClose) window.lumiaModalClose('lumia-db-drop-modal');
      showToast(t('tableDropped', 'Table supprimée'), 'success');
      db.dropTarget = null;
      // Réinitialiser la vue si la table supprimée était ouverte
      if (table === db.currentTable) {
        db.currentTable = null;
        document.getElementById('lumia-db-table-view').style.display = 'none';
        document.getElementById('lumia-db-empty').style.display = '';
      }
      if (cleanup.open) scanCleanup();
      loadTables();
    });
  }

  /* ================================================================
   * NETTOYAGE (issue #16) — vue globale, hors table sélectionnée
   * ================================================================ */

  var cleanup = { open: false, busy: false, items: [] };

  // sprintf minimal : %s et %1$s, %2$s…
  function fmt(str) {
    var args = Array.prototype.slice.call(arguments, 1);
    var i = 0;
    return String(str).replace(/%(?:(\d+)\$)?[sd]/g, function (m, n) {
      var v = n ? args[parseInt(n, 10) - 1] : args[i++];
      return v === undefined ? m : v;
    });
  }

  function num(n) { return Number(n).toLocaleString(); }

  function setCleanupActive(on) {
    cleanup.open = on;
    var link = document.getElementById('lumia-db-cleanup-link');
    var view = document.getElementById('lumia-db-cleanup-view');
    if (link) link.classList.toggle('is-active', on);
    if (view) view.style.display = on ? '' : 'none';
  }

  function openCleanup() {
    db.currentTable = null;
    document.querySelectorAll('.lumia-db__table-list .lumia-db__table-item').forEach(function (el) {
      el.classList.remove('is-active');
    });
    document.getElementById('lumia-db-empty').style.display = 'none';
    document.getElementById('lumia-db-table-view').style.display = 'none';
    setCleanupActive(true);
    scanCleanup();
  }

  function scanCleanup() {
    var view = document.getElementById('lumia-db-cleanup-view');
    if (!view) return;
    view.innerHTML = '<div class="lumia-db__loading">' + escHtml(t('loading', 'Chargement…')) + '</div>';
    ajax('lumia_db_cleanup_scan', {}, function (data) {
      cleanup.items = data.items;
      renderCleanup(view, data);
    });
  }

  function renderCleanup(view, data) {
    var total = data.items.reduce(function (s, it) { return s + it.count; }, 0);

    var html = '<div class="lumia-db__cleanup-inner">' +
      '<div class="lumia-db__cleanup-head">' +
        '<div><h2 class="lumia-db__table-name">' + escHtml(t('cleanupTitle', 'Nettoyage')) + '</h2>' +
        '<p class="lumia-db__cleanup-intro">' + escHtml(t('cleanupIntro', '')) + '</p></div>' +
        '<div class="lumia-db__table-actions">' +
          '<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary" data-cleanup="rescan">' + escHtml(t('cleanupRescan', 'Recompter')) + '</button>' +
          '<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--primary" data-cleanup="all"' + (total ? '' : ' disabled') + '>' +
            escHtml(t('cleanupAll', 'Tout nettoyer')) + (total ? ' (' + num(total) + ')' : '') + '</button>' +
        '</div>' +
      '</div>';

    // Données superflues
    html += '<section class="lumia-db__cleanup-section"><h3 class="lumia-db__cleanup-title">' + escHtml(t('cleanupItems', 'Données superflues')) + '</h3>' +
      '<ul class="lumia-db__cleanup-list">';
    data.items.forEach(function (it) {
      html += '<li class="lumia-db__cleanup-row" data-item="' + escHtml(it.key) + '">' +
        '<div class="lumia-db__cleanup-text"><strong>' + escHtml(it.label) + '</strong>' +
        '<span>' + escHtml(it.description) + '</span></div>' +
        '<span class="lumia-db__cleanup-count' + (it.count ? '' : ' is-zero') + '">' + num(it.count) + '</span>' +
        '<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary" data-cleanup="item"' + (it.count ? '' : ' disabled') + '>' +
          escHtml(t('cleanupClean', 'Nettoyer')) + '</button>' +
        '</li>';
    });
    html += '</ul></section>';

    // Optimisation
    var free = data.fragmented.reduce(function (s, tb) { return s + tb.free; }, 0);
    html += '<section class="lumia-db__cleanup-section"><h3 class="lumia-db__cleanup-title">' + escHtml(t('optimizeTitle', 'Optimisation des tables')) + '</h3>' +
      '<div class="lumia-db__cleanup-row">' +
        '<div class="lumia-db__cleanup-text"><span>' + escHtml(data.fragmented.length
          ? fmt(t('optimizeSummary', '%1$s table(s), %2$s'), num(data.fragmented.length), formatSize(free))
          : t('optimizeNone', 'Aucune table fragmentée.')) + '</span></div>' +
        '<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary" data-cleanup="optimize"' + (data.fragmented.length ? '' : ' disabled') + '>' +
          escHtml(t('optimizeBtn', 'Optimiser')) + '</button>' +
      '</div></section>';

    // Tables d'extensions
    html += '<section class="lumia-db__cleanup-section"><h3 class="lumia-db__cleanup-title">' + escHtml(t('foreignTitle', 'Tables d\'extensions')) + '</h3>' +
      '<p class="lumia-db__cleanup-intro">' + escHtml(t('foreignIntro', '')) + '</p>';
    if (!data.foreign.length) {
      html += '<p class="lumia-db__cleanup-intro">' + escHtml(t('foreignNone', 'Aucune table d\'extension.')) + '</p>';
    } else {
      html += '<ul class="lumia-db__cleanup-list">';
      data.foreign.forEach(function (tb) {
        var badge;
        if (tb.status === 'active')        badge = '<span class="lumia-badge lumia-badge--success">' + escHtml(fmt(t('foreignActive', '%s'), tb.owners.join(', '))) + '</span>';
        else if (tb.status === 'inactive') badge = '<span class="lumia-badge lumia-badge--warning">' + escHtml(fmt(t('foreignInactive', '%s'), tb.owners.join(', '))) + '</span>';
        else                               badge = '<span class="lumia-badge lumia-badge--danger">' + escHtml(t('foreignUnknown', 'Aucune extension correspondante')) + '</span>';
        html += '<li class="lumia-db__cleanup-row" data-table="' + escHtml(tb.name) + '">' +
          '<div class="lumia-db__cleanup-text"><strong><code>' + escHtml(tb.name) + '</code></strong>' +
          '<span>' + num(tb.rows) + ' ' + escHtml(t('rowsLabel', 'lignes')) + ' · ' + formatSize(tb.size) + '</span></div>' +
          badge +
          '<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary" data-cleanup="open-table">' + escHtml(t('open', 'Ouvrir')) + '</button>' +
          '<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--danger" data-cleanup="drop-table">' + escHtml(t('delete', 'Supprimer')) + '</button>' +
          '</li>';
      });
      html += '</ul>';
    }
    html += '</section></div>';

    view.innerHTML = html;
    view.querySelectorAll('[data-cleanup]').forEach(function (btn) {
      btn.addEventListener('click', function () { onCleanupAction(btn, data); });
    });
  }

  function onCleanupAction(btn, data) {
    if (cleanup.busy) return;
    var action = btn.dataset.cleanup;
    var row    = btn.closest('.lumia-db__cleanup-row');

    if (action === 'rescan')     { scanCleanup(); return; }
    if (action === 'open-table') { selectTable(row.dataset.table); return; }
    if (action === 'drop-table') { openDropModal(row.dataset.table); return; }

    if (action === 'item') {
      var it = findItem(row.dataset.item);
      if (!it) return;
      confirmThen(fmt(t('cleanupConfirm', '%1$s : %2$s ?'), num(it.count), it.label), function () {
        runQueue([it]);
      });
      return;
    }

    if (action === 'all') {
      var todo  = cleanup.items.filter(function (i) { return i.count > 0; });
      var total = todo.reduce(function (s, i) { return s + i.count; }, 0);
      confirmThen(fmt(t('cleanupConfirmAll', '%s ?'), num(total)), function () { runQueue(todo); });
      return;
    }

    if (action === 'optimize') {
      confirmThen(t('optimizeConfirm', 'Optimiser ?'), function () {
        optimizeQueue(data.fragmented.map(function (tb) { return tb.name; }));
      });
    }
  }

  function findItem(key) {
    for (var i = 0; i < cleanup.items.length; i++) {
      if (cleanup.items[i].key === key) return cleanup.items[i];
    }
    return null;
  }

  function confirmThen(message, onConfirm) {
    if (!window.lumiaModal) return;
    window.lumiaModal.open({
      danger: true,
      title: t('cleanupTitle', 'Nettoyage'),
      message: message,
      confirmLabel: t('confirm', 'Confirmer'),
      cancelLabel: t('cancel', 'Annuler'),
      onConfirm: onConfirm,
    });
  }

  function setBusy(on) {
    cleanup.busy = on;
    var view = document.getElementById('lumia-db-cleanup-view');
    if (view) view.classList.toggle('is-busy', on);
  }

  function rowEl(key) {
    return document.querySelector('#lumia-db-cleanup-view .lumia-db__cleanup-row[data-item="' + key + '"]');
  }

  // Purge les éléments un par un, chacun par lots jusqu'à épuisement.
  // Un lot qui ne supprime rien arrête l'élément (objet que WordPress refuse
  // de supprimer) : sans ça, la boucle tournerait indéfiniment.
  function runQueue(queue) {
    setBusy(true);
    var results = [];
    (function next(i) {
      if (i >= queue.length) { finish(); return; }
      var it = queue[i];
      var el = rowEl(it.key);
      var countEl = el && el.querySelector('.lumia-db__cleanup-count');
      if (countEl) countEl.textContent = t('cleanupRunning', 'Nettoyage…');
      var deleted = 0;
      (function batch() {
        ajax('lumia_db_cleanup_run', { item: it.key }, function (d) {
          deleted += d.deleted;
          if (countEl) countEl.textContent = num(d.remaining);
          if (d.deleted > 0 && d.remaining > 0) { batch(); return; }
          results.push({ it: it, deleted: deleted, remaining: d.remaining });
          next(i + 1);
        }, finish);
      })();
    })(0);

    function finish() {
      setBusy(false);
      var deleted = 0, left = 0;
      results.forEach(function (r) { deleted += r.deleted; left += r.remaining; });
      if (results.length === 1) {
        showToast(fmt(t('cleanupDone', '%1$s : %2$s'), num(deleted), results[0].it.label), 'success');
      } else if (results.length > 1) {
        showToast(fmt(t('cleanupDoneTotal', '%s'), num(deleted)), 'success');
      }
      if (left > 0) showToast(fmt(t('cleanupLeft', '%s'), num(left)), 'warning');
      scanCleanup();
      loadTables();
    }
  }

  function optimizeQueue(tables) {
    setBusy(true);
    var done = 0;
    (function next(i) {
      if (i >= tables.length) { finish(); return; }
      ajax('lumia_db_cleanup_optimize', { table: tables[i] }, function () {
        done++;
        next(i + 1);
      }, function () { next(i + 1); });
    })(0);

    function finish() {
      setBusy(false);
      showToast(fmt(t('optimizeDone', '%s'), num(done)), 'success');
      scanCleanup();
    }
  }

  /* ================================================================
   * AJOUT DE LIGNE (modale générée depuis la structure de la table)
   * ================================================================ */

  function openInsertModal() {
    if (!db.currentTable) return;
    var fieldsWrap = document.getElementById('lumia-db-insert-fields');
    if (!fieldsWrap) return;
    fieldsWrap.innerHTML = '<div class="lumia-db__loading">' + escHtml(t('loading', 'Chargement…')) + '</div>';
    if (window.lumiaModalOpen) window.lumiaModalOpen('lumia-db-insert-modal');

    // Récupérer la structure pour construire un champ par colonne.
    ajax('lumia_db_get_structure', { table: db.currentTable }, function (data) {
      renderInsertFields(fieldsWrap, data.columns || []);
    });
  }

  function renderInsertFields(wrap, columns) {
    if (!columns.length) {
      wrap.innerHTML = '<p class="lumia-db__history-empty">' + escHtml(t('noColumn', 'Aucune colonne.')) + '</p>';
      return;
    }
    var html = '';
    columns.forEach(function (c) {
      var extra      = String(c.Extra || '').toLowerCase();
      var isAuto     = extra.indexOf('auto_increment') !== -1;
      var nullable   = c.Null === 'YES';
      var longVal    = /text|blob|json/i.test(c.Type || '');
      var hint       = escHtml(c.Type || '') + (isAuto ? ' · auto' : '') + (c.Key === 'PRI' ? ' · clé primaire' : '');
      var field      = escHtml(c.Field);

      html += '<div class="lumia-form__group lumia-db__insert-field" data-col="' + field + '" data-auto="' + (isAuto ? '1' : '0') + '">';
      html += '<label class="lumia-form__label" for="lumia-db-ins-' + field + '">' + field +
              ' <span class="lumia-db__insert-hint">' + hint + '</span></label>';
      if (longVal) {
        html += '<textarea class="lumia-input lumia-db__insert-input" id="lumia-db-ins-' + field + '" rows="3"' +
                (isAuto ? ' placeholder="(auto)"' : '') + '></textarea>';
      } else {
        html += '<input type="text" class="lumia-input lumia-db__insert-input" id="lumia-db-ins-' + field + '"' +
                (isAuto ? ' placeholder="(auto)"' : '') + '>';
      }
      if (nullable) {
        html += '<label class="lumia-db__insert-null"><input type="checkbox" class="lumia-db__insert-null-cb"> NULL</label>';
      }
      html += '</div>';
    });
    wrap.innerHTML = html;

    // Cocher NULL désactive le champ.
    wrap.querySelectorAll('.lumia-db__insert-null-cb').forEach(function (cb) {
      cb.addEventListener('change', function () {
        var input = cb.closest('.lumia-db__insert-field').querySelector('.lumia-db__insert-input');
        if (input) { input.disabled = cb.checked; }
      });
    });
  }

  function submitInsert() {
    var wrap = document.getElementById('lumia-db-insert-fields');
    if (!wrap) return;
    var fd = { table: db.currentTable };
    var nullIdx = 0;
    wrap.querySelectorAll('.lumia-db__insert-field').forEach(function (group) {
      var col   = group.dataset.col;
      var input = group.querySelector('.lumia-db__insert-input');
      var nullCb = group.querySelector('.lumia-db__insert-null-cb');
      if (nullCb && nullCb.checked) {
        fd['nulls[' + (nullIdx++) + ']'] = col;
        return;
      }
      // Colonne auto-increment laissée vide → ne pas l'envoyer (MySQL gère).
      if (group.dataset.auto === '1' && !input.value) return;
      fd['fields[' + col + ']'] = input.value;
    });

    var btn = document.getElementById('lumia-db-insert-confirm-btn');
    var btnLabel = btn ? btn.textContent : '';
    if (btn) { btn.disabled = true; btn.textContent = t('inserting', 'Insertion…'); }
    function restore() { if (btn) { btn.disabled = false; btn.textContent = btnLabel; } }
    ajax('lumia_db_insert_row', fd, function () {
      if (window.lumiaModalClose) window.lumiaModalClose('lumia-db-insert-modal');
      restore();
      showToast(t('rowAdded', 'Ligne ajoutée'), 'success');
      loadData(1);
    }, restore);
  }

  /* ================================================================
   * ONGLET STRUCTURE
   * ================================================================ */

  function loadStructure() {
    var content = document.getElementById('lumia-db-tab-structure');
    if (!content) return;
    content.innerHTML = '<div class="lumia-db__loading">' + escHtml(t('loading', 'Chargement…')) + '</div>';

    ajax('lumia_db_get_structure', { table: db.currentTable }, function (data) {
      renderStructure(content, data);
    });
  }

  function renderStructure(container, data) {
    var html = '';

    // Colonnes
    html += '<div class="lumia-db__structure-section-title">Colonnes</div>';
    html += '<div class="lumia-db__data-table-wrap"><table class="lumia-db__data-table"><thead><tr>' +
            '<th>Nom</th><th>Type</th><th>Null</th><th>Défaut</th><th>Clé</th><th>Extra</th>' +
            '</tr></thead><tbody>';
    (data.columns || []).forEach(function (c) {
      html += '<tr>' +
        '<td>' + escHtml(c.Field) + '</td>' +
        '<td>' + escHtml(c.Type) + '</td>' +
        '<td>' + escHtml(c.Null) + '</td>' +
        '<td>' + (c.Default == null ? '—' : escHtml(String(c.Default))) + '</td>' +
        '<td>' + escHtml(c.Key || '') + '</td>' +
        '<td>' + escHtml(c.Extra || '') + '</td>' +
        '</tr>';
    });
    html += '</tbody></table></div>';

    // Index / Clés
    html += '<div class="lumia-db__structure-section-title">Index / Clés</div>';
    html += '<div class="lumia-db__data-table-wrap"><table class="lumia-db__data-table"><thead><tr>' +
            '<th>Nom</th><th>Type</th><th>Colonne</th><th>Unique</th>' +
            '</tr></thead><tbody>';
    (data.indexes || []).forEach(function (idx) {
      html += '<tr>' +
        '<td>' + escHtml(idx.Key_name) + '</td>' +
        '<td>' + escHtml(idx.Index_type || '') + '</td>' +
        '<td>' + escHtml(idx.Column_name || '') + '</td>' +
        '<td>' + (String(idx.Non_unique) === '0' ? 'Oui' : 'Non') + '</td>' +
        '</tr>';
    });
    html += '</tbody></table></div>';

    container.innerHTML = html;
  }

  function initSearch() {
    var input = document.getElementById('lumia-db-search-table');
    if (!input) return;
    input.addEventListener('input', function () {
      var q = input.value.toLowerCase();
      var filtered = db.tables.filter(function (t) {
        return t.name.toLowerCase().includes(q);
      });
      renderTableList(filtered);
    });
  }

  /* ================================================================
   * ONGLET REQUÊTE SQL
   * ================================================================ */

  var HISTORY_KEY = 'lumia_db_query_history';
  var MAX_HISTORY = 20;

  function initQueryTab() {
    var content = document.getElementById('lumia-db-tab-query');
    if (!content || content.dataset.initialized) return;
    content.dataset.initialized = '1';

    var warning = (lumiaAdmin.i18n && lumiaAdmin.i18n.queryWarning) || '';

    content.innerHTML =
      '<div class="lumia-db__query-warning">' +
      '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>' +
      '<span>' + escHtml(warning) + '</span>' +
      '</div>' +
      '<div class="lumia-db__query-editor-wrap">' +
      '<textarea id="lumia-db-query-input" class="lumia-db__query-input" ' +
      'placeholder="SELECT * FROM ' + escHtml(db.currentTable || 'ma_table') + ' LIMIT 100;"></textarea>' +
      '<div class="lumia-db__query-toolbar">' +
      '<div class="lumia-db__query-history-wrap">' +
      '<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary" id="lumia-db-history-btn">Historique</button>' +
      '<div class="lumia-db__history-dropdown" id="lumia-db-history-list" style="display:none"></div>' +
      '</div>' +
      '<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--primary" id="lumia-db-run-query">' + escHtml(t('execute', 'Exécuter')) + '</button>' +
      '</div>' +
      '</div>' +
      '<div id="lumia-db-query-result" class="lumia-db__query-result"></div>';

    var runBtn     = content.querySelector('#lumia-db-run-query');
    var queryInput = content.querySelector('#lumia-db-query-input');
    var historyBtn = content.querySelector('#lumia-db-history-btn');

    runBtn.addEventListener('click', runQuery);
    queryInput.addEventListener('keydown', function (e) {
      if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); runQuery(); }
    });
    historyBtn.addEventListener('click', toggleHistory);

    // Fermer le dropdown historique au clic extérieur
    document.addEventListener('click', function (e) {
      var wrap = content.querySelector('.lumia-db__query-history-wrap');
      var list = document.getElementById('lumia-db-history-list');
      if (list && list.style.display !== 'none' && wrap && !wrap.contains(e.target)) {
        list.style.display = 'none';
      }
    });

    renderHistory();
  }

  // Détecte une requête de lecture (miroir de la logique serveur).
  /**
   * Miroir de Module::normalize_sql(). Purement cosmétique : le serveur
   * refait le même travail et ne fait aucune confiance à ce qui arrive.
   * Sans ce miroir, l'utilisateur n'obtiendrait pas la confirmation attendue
   * et le serveur répondrait « needs_confirm » sur une requête que l'interface
   * croyait inoffensive.
   */
  function normalizeSql(sql) {
    return String(sql)
      // Littéraux d'abord : une ouverture de commentaire dans une chaîne
      // n'ouvre pas de commentaire.
      .replace(/'[^']*'/g, "''")
      .replace(/"[^"]*"/g, '""')
      .replace(/`[^`]*`/g, '``')
      .replace(/\/\*[\s\S]*?\*\//g, ' ')
      .replace(/--[^\n]*/g, ' ')
      .replace(/#[^\n]*/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();
  }

  /** Miroir de Module::is_read_query(). */
  function isReadQuery(sql) {
    var q = normalizeSql(sql);

    if (!/^\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN|WITH)\b/i.test(q)) return false;
    if (/\b(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|ALTER|RENAME|INTO\s+(OUTFILE|DUMPFILE)|LOAD\s+DATA)\b/i.test(q)) return false;
    if (/;\s*\S/.test(q)) return false;

    return true;
  }

  function runQuery() {
    var input = document.getElementById('lumia-db-query-input');
    if (!input) return;
    var sql = input.value.trim();
    if (!sql) return;

    // Garde-fou client : les requêtes d'écriture exigent une confirmation explicite.
    if (!isReadQuery(sql) && window.lumiaModal) {
      window.lumiaModal.open({
        danger: true,
        title: t('execute', 'Exécuter'),
        message: t('confirmWrite', 'Cette requête modifie la base de données et est irréversible. Confirmer l\'exécution ?'),
        confirmLabel: t('execute', 'Exécuter'),
        cancelLabel: t('cancel', 'Annuler'),
        onConfirm: function () { execQuery(sql, true); },
      });
      return;
    }
    execQuery(sql, false);
  }

  function execQuery(sql, confirmed) {
    var result = document.getElementById('lumia-db-query-result');
    if (!result) return;
    result.innerHTML = '<div class="lumia-db__loading">' + escHtml(t('executing', 'Exécution…')) + '</div>';

    var payload = { sql: sql };
    if (confirmed) payload.confirm = '1';

    ajax('lumia_db_run_query', payload, function (data) {
      saveToHistory(sql);
      if (data.type === 'select') {
        renderQueryResult(result, data);
      } else {
        result.innerHTML =
          '<div class="lumia-db__query-success">' +
          '<strong>' + escHtml(String(data.affected)) + '</strong> ligne(s) affectée(s). ' +
          (data.insert_id ? 'Dernier ID inséré : <strong>' + escHtml(String(data.insert_id)) + '</strong>.' : '') +
          '</div>';
      }
    }, function (msg) {
      result.innerHTML = '<div class="lumia-db__query-error">' + escHtml(msg) + '</div>';
    });
  }

  function renderQueryResult(container, data) {
    var cols = data.columns || [];
    if (!cols.length) {
      container.innerHTML = '<div class="lumia-db__query-success">Requête exécutée. Aucun résultat.</div>';
      return;
    }
    var html = '<div class="lumia-db__query-result-meta">' + data.total.toLocaleString() + ' ligne(s)</div>';
    if (data.truncated) {
      var warn = (t('queryTruncated', 'Résultat tronqué à %d lignes. Ajoutez une clause LIMIT pour cibler votre requête.'))
                 .replace('%d', data.truncated.toLocaleString());
      html += '<div class="lumia-db__query-warning" style="border-bottom:none;margin-bottom:8px">' +
              '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>' +
              '<span>' + escHtml(warn) + '</span></div>';
    }
    html += '<div class="lumia-db__data-table-wrap"><table class="lumia-db__data-table"><thead><tr>';
    cols.forEach(function (c) { html += '<th class="lumia-db__col-static">' + escHtml(c) + '</th>'; });
    html += '</tr></thead><tbody>';
    (data.rows || []).forEach(function (row) {
      html += '<tr>';
      cols.forEach(function (c) {
        var val = row[c];
        if (val === null) { html += '<td class="lumia-db__td-null">NULL</td>'; return; }
        var str = String(val);
        if (/[\x00-\x08\x0E-\x1F]/.test(str)) { html += '<td class="lumia-db__td-binary">[BINARY DATA]</td>'; return; }
        html += '<td title="' + escHtml(str) + '">' + escHtml(str) + '</td>';
      });
      html += '</tr>';
    });
    html += '</tbody></table></div>';
    container.innerHTML = html;
  }

  function saveToHistory(sql) {
    var h = JSON.parse(localStorage.getItem(HISTORY_KEY) || '[]');
    h = h.filter(function (q) { return q !== sql; });
    h.unshift(sql);
    if (h.length > MAX_HISTORY) h = h.slice(0, MAX_HISTORY);
    localStorage.setItem(HISTORY_KEY, JSON.stringify(h));
    renderHistory();
  }

  function renderHistory() {
    var list = document.getElementById('lumia-db-history-list');
    if (!list) return;
    var h = JSON.parse(localStorage.getItem(HISTORY_KEY) || '[]');
    if (!h.length) { list.innerHTML = '<p class="lumia-db__history-empty">' + escHtml(t('noHistory', 'Aucun historique.')) + '</p>'; return; }
    var html = h.map(function (q, i) {
      return '<div class="lumia-db__history-item" data-index="' + i + '">' +
             escHtml(q.substring(0, 80)) + (q.length > 80 ? '…' : '') + '</div>';
    }).join('');
    // Historique stocké dans le localStorage du navigateur (non partagé entre postes/comptes).
    html += '<div class="lumia-db__history-footer">' +
            '<button type="button" class="lumia-db__history-clear">' + escHtml(t('clearHistory', 'Vider l\'historique')) + '</button>' +
            '</div>';
    list.innerHTML = html;
    list.querySelectorAll('.lumia-db__history-item').forEach(function (el) {
      el.addEventListener('click', function () {
        var idx = parseInt(el.dataset.index, 10);
        var h2  = JSON.parse(localStorage.getItem(HISTORY_KEY) || '[]');
        var input = document.getElementById('lumia-db-query-input');
        if (input) input.value = h2[idx] || '';
        toggleHistory();
      });
    });
    var clearBtn = list.querySelector('.lumia-db__history-clear');
    if (clearBtn) {
      clearBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        localStorage.removeItem(HISTORY_KEY);
        renderHistory();
      });
    }
  }

  function toggleHistory() {
    var list = document.getElementById('lumia-db-history-list');
    if (list) list.style.display = list.style.display === 'none' ? '' : 'none';
  }

  function formatSize(bytes) {
    if (bytes < 1024) return bytes + ' o';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' Ko';
    return (bytes / 1024 / 1024).toFixed(2) + ' Mo';
  }

  // Échappe pour le texte ET les attributs (les guillemets DOIVENT être échappés,
  // sinon une valeur contenant href="…" casse l'attribut data-raw et tronque la donnée).
  function escHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  window.lumiaDb = db; // exposer pour les modules suivants

})();
