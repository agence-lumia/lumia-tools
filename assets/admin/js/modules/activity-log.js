(function () {
  "use strict";

  var state = {
    nonce: '',
    page: 1,
    pages: 1,
    rows: [],
    timer: null,
    request: 0,
  };

  var el = {};

  document.addEventListener('DOMContentLoaded', function () {
    var wrap = document.getElementById('lumia-al');
    if (!wrap) return;

    state.nonce = wrap.dataset.nonce;

    ['search', 'user', 'type', 'from', 'to', 'reset', 'export', 'rows', 'total', 'page', 'prev', 'next'].forEach(function (id) {
      el[id] = document.getElementById('lumia-al-' + id);
    });

    // Enter in any filter (search, dates) would submit the settings form that
    // wraps the list: page reload, lost filters and a false "Settings
    // changed" entry in the log.
    wrap.querySelector('.lumia-al__filters').addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && e.target.tagName === 'INPUT') { e.preventDefault(); reload(); }
    });
    el.search.addEventListener('input', function () {
      clearTimeout(state.timer);
      state.timer = setTimeout(reload, 300);
    });
    [el.user, el.type, el.from, el.to].forEach(function (input) {
      input.addEventListener('change', reload);
    });

    el.reset.addEventListener('click', function () {
      el.search.value = '';
      el.user.value = '';
      el.type.value = '';
      el.from.value = '';
      el.to.value = '';
      reload();
    });

    el.prev.addEventListener('click', function () { if (state.page > 1) load(state.page - 1); });
    el.next.addEventListener('click', function () { if (state.page < state.pages) load(state.page + 1); });
    el.export.addEventListener('click', exportCsv);

    el.rows.addEventListener('click', function (e) {
      var tr = e.target.closest('tr[data-index]');
      if (tr) openDetail(state.rows[+tr.dataset.index]);
    });
    el.rows.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter' && e.key !== ' ') return;
      var tr = e.target.closest('tr[data-index]');
      if (tr) { e.preventDefault(); openDetail(state.rows[+tr.dataset.index]); }
    });

    load(1);
  });

  /** String translated by PHP (Module::get_admin_js_data()); empty if missing. */
  function t(key) {
    return (window.lumiaAdmin && lumiaAdmin.i18n && lumiaAdmin.i18n[key]) || '';
  }

  function format(str) {
    var args = Array.prototype.slice.call(arguments, 1);
    var i = 0;
    return str.replace(/%(\d+\$)?s/g, function (m, pos) {
      return pos ? args[parseInt(pos, 10) - 1] : args[i++];
    });
  }

  /** Current filters, in the shape the server expects. */
  function filters() {
    var type = el.type.value;
    return {
      search: el.search.value.trim(),
      user_id: el.user.value,
      group: type.indexOf('group:') === 0 ? type.slice(6) : '',
      event: type.indexOf('event:') === 0 ? type.slice(6) : '',
      from: el.from.value,
      to: el.to.value,
    };
  }

  function reload() { load(1); }

  function load(page) {
    var data = filters();
    var fd = new FormData();
    var request = ++state.request;

    fd.append('action', 'lumia_activity_log_list');
    fd.append('nonce', state.nonce);
    fd.append('page', page);
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });

    setState(t('alLoading'));

    fetch(lumiaAdmin.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        // Fast typing in the search box fires several requests: only the last
        // one must be displayed, even if an older one answers afterwards.
        if (request !== state.request) return;
        if (!res.success) throw new Error((res.data && res.data.message) || '');
        render(res.data);
      })
      .catch(function (err) {
        if (request !== state.request) return;
        setState(t('alError'));
        if (typeof window.lumiaShowToast === 'function') {
          window.lumiaShowToast((err && err.message) || t('alError'), 'error');
        }
      });
  }

  function setState(message) {
    el.rows.innerHTML = '';
    var tr = document.createElement('tr');
    var td = document.createElement('td');
    td.colSpan = 5;
    td.className = 'lumia-al__state';
    td.textContent = message;
    tr.appendChild(td);
    el.rows.appendChild(tr);
  }

  function render(data) {
    state.rows = data.rows;
    state.page = data.page;
    state.pages = data.pages;

    el.total.textContent = format(t('alTotal'), data.total.toLocaleString());
    el.page.textContent = format(t('alPage'), data.page, data.pages);
    el.prev.disabled = data.page <= 1;
    el.next.disabled = data.page >= data.pages;

    if (!data.rows.length) {
      setState(t('alEmpty'));
      return;
    }

    el.rows.innerHTML = '';
    data.rows.forEach(function (row, index) {
      var tr = document.createElement('tr');
      tr.className = 'lumia-al__row';
      tr.dataset.index = index;
      tr.tabIndex = 0;

      tr.appendChild(cell(row.date, 'lumia-al__date'));

      var user = cell(row.user, 'lumia-al__user');
      if (row.role) {
        var role = document.createElement('span');
        role.className = 'lumia-al__muted';
        role.textContent = row.role;
        user.appendChild(role);
      }
      tr.appendChild(user);

      var event = cell('', 'lumia-al__event');
      var badge = document.createElement('span');
      badge.className = 'lumia-badge lumia-al__badge ' + badgeClass(row);
      badge.textContent = row.group;
      event.appendChild(badge);
      event.appendChild(document.createTextNode(row.event));
      tr.appendChild(event);

      tr.appendChild(cell(row.object, 'lumia-al__object'));
      tr.appendChild(cell(row.ip, 'lumia-al__ip'));

      el.rows.appendChild(tr);
    });
  }

  /** Design system badge variant by group; red for what deletes or fails. */
  function badgeClass(row) {
    if (row.event_key === 'login_failed' || /_deleted$/.test(row.event_key)) return 'lumia-badge--danger';
    return {
      auth: 'lumia-badge--info',
      content: 'lumia-badge--success',
      users: 'lumia-badge--warning',
      options: 'lumia-badge--warning',
      settings: 'lumia-badge--info',
    }[row.group_key] || 'lumia-badge--neutral';
  }

  function cell(text, className) {
    var td = document.createElement('td');
    td.className = className;
    if (text) {
      var span = document.createElement('span');
      span.textContent = text;
      td.appendChild(span);
    }
    return td;
  }

  function openDetail(row) {
    if (!row) return;

    document.getElementById('lumia-al-detail-title').textContent = row.event + (row.object ? ' — ' + row.object : '');

    var body = document.getElementById('lumia-al-detail-body');
    body.innerHTML = '';

    var lines = [
      [t('alDate'), row.date],
      [t('alUser'), row.user],
      [t('alRole'), row.role],
      [t('alIp'), row.ip],
      [t('alEvent'), row.group + ' — ' + row.event],
      [t('alObject'), row.object],
    ].concat(row.details);

    lines.forEach(function (pair) {
      if (!pair[1]) return;
      var dt = document.createElement('dt');
      var dd = document.createElement('dd');
      dt.textContent = pair[0];
      dd.textContent = pair[1];
      body.appendChild(dt);
      body.appendChild(dd);
    });

    var link = document.getElementById('lumia-al-detail-link');
    if (row.link) {
      link.href = row.link;
      link.hidden = false;
    } else {
      link.removeAttribute('href');
      link.hidden = true;
    }

    window.lumiaModalOpen('lumia-al-detail-modal');
  }

  /**
   * Download through a temporary POST form: the browser handles the returned
   * file, which fetch() cannot do without going through a Blob kept entirely
   * in memory.
   */
  function exportCsv() {
    var form = document.createElement('form');
    var data = filters();

    form.method = 'post';
    form.action = lumiaAdmin.alExportUrl;
    form.hidden = true;

    data.action = 'lumia_activity_log_export';
    data.lumia_nonce = lumiaAdmin.alExportNonce;

    Object.keys(data).forEach(function (k) {
      var input = document.createElement('input');
      input.type = 'hidden';
      input.name = k;
      input.value = data[k];
      form.appendChild(input);
    });

    document.body.appendChild(form);
    form.submit();
    form.remove();
  }
})();
