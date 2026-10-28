(function () {
  "use strict";

  var state = {
    nonce: '',
    rows: [],
    request: 0,
  };

  var el = {};

  var STATUS = {
    active: ['lumia-badge--success', 'mcStatusActive'],
    pending: ['lumia-badge--warning', 'mcStatusPending'],
    revoked: ['lumia-badge--inactive', 'mcStatusRevoked'],
  };

  var REASONS = {
    manual: 'mcReasonManual',
    all: 'mcReasonAll',
    reuse: 'mcReasonReuse',
    user_deleted: 'mcReasonUserDeleted',
    token: 'mcReasonToken',
    expired: 'mcReasonExpired',
  };

  document.addEventListener('DOMContentLoaded', function () {
    var wrap = document.getElementById('lumia-mc');
    if (!wrap) return;

    state.nonce = wrap.dataset.nonce;

    ['rows', 'total', 'empty', 'revoke-all'].forEach(function (id) {
      el[id] = document.getElementById('lumia-mc-' + id);
    });

    el.rows.addEventListener('click', function (e) {
      var button = e.target.closest('[data-action="revoke"]');
      if (button) confirmRevoke(state.rows[+button.dataset.index]);
    });
    el['revoke-all'].addEventListener('click', confirmRevokeAll);

    initCopy(document.getElementById('lumia-module-form'));

    // Connections is the default tab: the list loads with the page.
    load();
  });

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

  function toast(message, type) {
    if (typeof window.lumiaShowToast === 'function') window.lumiaShowToast(message, type);
  }

  function post(action, data) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('nonce', state.nonce);
    Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });

    return fetch(lumiaAdmin.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
      .then(function (r) { return r.json(); });
  }

  /* ================================================================
   * CONNECTIONS
   * ================================================================ */

  function load() {
    var request = ++state.request;

    setState(t('mcLoading'));

    post('lumia_mcp_connect_list')
      .then(function (res) {
        // A revoke reloads the list: only the latest answer is displayed.
        if (request !== state.request) return;
        if (!res.success) throw new Error((res.data && res.data.message) || '');
        render(res.data.rows || []);
      })
      .catch(function (err) {
        if (request !== state.request) return;
        setState(t('mcError'));
        toast((err && err.message) || t('mcError'), 'error');
      });
  }

  function setState(message) {
    el.rows.innerHTML = '';
    el.empty.hidden = true;
    el.rows.closest('.lumia-mc__table-wrap').hidden = false;

    var tr = document.createElement('tr');
    var td = document.createElement('td');
    td.colSpan = 10;
    td.className = 'lumia-mc__state';
    td.textContent = message;
    tr.appendChild(td);
    el.rows.appendChild(tr);
  }

  function render(rows) {
    state.rows = rows;

    var live = rows.filter(function (row) { return row.status !== 'revoked'; }).length;
    if (el.total) el.total.textContent = format(t('mcTotal'), live.toLocaleString());
    el['revoke-all'].disabled = !live;

    // Empty state of the design system instead of an empty table.
    var wrap = el.rows.closest('.lumia-mc__table-wrap');
    wrap.hidden = !rows.length;
    el.empty.hidden = !!rows.length;
    el.rows.innerHTML = '';

    rows.forEach(function (row, index) {
      var tr = document.createElement('tr');
      if (row.status === 'revoked') tr.className = 'lumia-mc__row--revoked';

      tr.appendChild(cell(row.label || t('mcNoLabel'), row.label ? 'lumia-mc__label' : 'lumia-mc__label lumia-mc__muted'));

      var client = cell(row.client, 'lumia-mc__client');
      client.appendChild(badge(row.verified ? 'lumia-badge--success' : 'lumia-badge--warning', row.verified ? t('mcVerified') : t('mcUnverified')));
      tr.appendChild(client);

      var acting = cell(row.acting, 'lumia-mc__acting');
      if (row.acting_approver) acting.appendChild(badge('lumia-badge--warning', t('mcActsAsApprover')));
      tr.appendChild(acting);

      tr.appendChild(cell(row.approved_by));
      tr.appendChild(cell(row.created, 'lumia-mc__date'));
      tr.appendChild(cell(row.last_used || t('mcNever'), 'lumia-mc__date'));
      tr.appendChild(cell(row.last_ip, 'lumia-mc__ip'));
      tr.appendChild(cell(row.status === 'revoked' ? '' : row.refresh_expires, 'lumia-mc__date'));

      var status = STATUS[row.status] || STATUS.revoked;
      var statusCell = cell('', 'lumia-mc__status-cell');
      statusCell.appendChild(badge(status[0], t(status[1])));
      if (row.status === 'revoked' && REASONS[row.revoked_reason]) {
        var reason = document.createElement('span');
        reason.className = 'lumia-mc__reason';
        reason.textContent = t(REASONS[row.revoked_reason]);
        statusCell.appendChild(reason);
      }
      tr.appendChild(statusCell);

      var actions = cell('', 'lumia-mc__actions');
      if (row.status !== 'revoked') {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'lumia-btn lumia-btn--danger lumia-btn--sm';
        button.dataset.action = 'revoke';
        button.dataset.index = index;
        button.textContent = t('mcRevoke');
        actions.appendChild(button);
      }
      tr.appendChild(actions);

      el.rows.appendChild(tr);
    });
  }

  function cell(text, className) {
    var td = document.createElement('td');
    if (className) td.className = className;
    if (text) {
      var span = document.createElement('span');
      span.textContent = text;
      td.appendChild(span);
    }
    return td;
  }

  function badge(variant, text) {
    var span = document.createElement('span');
    span.className = 'lumia-badge lumia-mc__badge ' + variant;
    span.textContent = text;
    return span;
  }

  function confirmRevoke(row) {
    if (!row) return;

    window.lumiaModal.open({
      title: t('mcRevokeTitle'),
      message: format(t('mcRevokeMessage'), row.label || row.client),
      confirmLabel: t('mcRevoke'),
      cancelLabel: t('cancel'),
      danger: true,
      onConfirm: function () {
        post('lumia_mcp_connect_revoke', { id: row.id })
          .then(function (res) {
            toast((res.data && res.data.message) || t('error'), res.success ? 'success' : 'error');
            load();
          })
          .catch(function () { toast(t('error'), 'error'); });
      },
    });
  }

  function confirmRevokeAll() {
    window.lumiaModal.open({
      title: t('mcRevokeAllTitle'),
      message: t('mcRevokeAllMessage'),
      confirmLabel: t('mcRevokeAllConfirm'),
      cancelLabel: t('cancel'),
      danger: true,
      onConfirm: function () {
        post('lumia_mcp_connect_revoke_all')
          .then(function (res) {
            toast((res.data && res.data.message) || t('error'), res.success ? 'success' : 'error');
            load();
          })
          .catch(function () { toast(t('error'), 'error'); });
      },
    });
  }

  /* ================================================================
   * COPY (Help tab)
   * ================================================================ */

  // Same pattern as image-optimizer.js: Clipboard API, with a selection +
  // execCommand fallback (plain-HTTP admin, older browsers).
  function initCopy(container) {
    if (!container) return;

    container.addEventListener('click', function (event) {
      var button = event.target.closest('[data-mc-copy]');
      if (!button) return;

      var source = container.querySelector(button.getAttribute('data-mc-copy'));
      if (!source) return;

      var isField = source.tagName === 'INPUT' || source.tagName === 'TEXTAREA';
      var text = isField ? source.value : source.textContent;

      function fallback() {
        if (isField) {
          source.select();
        } else {
          var range = document.createRange();
          range.selectNodeContents(source);
          var selection = window.getSelection();
          selection.removeAllRanges();
          selection.addRange(range);
        }
        try {
          document.execCommand('copy');
          toast(t('mcCopied'), 'success');
        } catch (e) {
          toast(t('mcCopyFailed'), 'error');
        }
      }

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () { toast(t('mcCopied'), 'success'); }, fallback);
      } else {
        fallback();
      }
    });
  }
})();
