/* SIGPÉ — Composants UI (vanilla JS) : icônes SVG, toast, modal, badges, helpers */
(function () {
  'use strict';

  /* ---------- Icônes SVG (stroke, style Heroicons-like) ---------- */
  const P = 'viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"';
  const icons = {
    dashboard: `<svg ${P}><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg>`,
    assets: `<svg ${P}><path d="M21 8l-9-5-9 5v8l9 5 9-5V8z"/><path d="M3 8l9 5 9-5"/><path d="M12 13v8"/></svg>`,
    movements: `<svg ${P}><path d="M8 3l4 4 4-4"/><path d="M12 7v14"/><path d="M4 21h16"/></svg>`,
    maintenance: `<svg ${P}><path d="M14.7 6.3a4.5 4.5 0 0 0-6 5.6L3 17.6V21h3.4l5.7-5.7a4.5 4.5 0 0 0 5.6-6L14 13l-3-3 3.7-3.7z"/></svg>`,
    inventory: `<svg ${P}><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12h6M9 16h4"/></svg>`,
    documents: `<svg ${P}><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8M8 17h5"/></svg>`,
    finance: `<svg ${P}><circle cx="12" cy="12" r="9"/><path d="M12 7v10M15 9.5c-.8-1-1.8-1.5-3-1.5-1.7 0-3 .9-3 2.2 0 2.8 6 1.6 6 4.4 0 1.3-1.3 2.2-3 2.2-1.2 0-2.2-.5-3-1.5"/></svg>`,
    notifications: `<svg ${P}><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>`,
    audit: `<svg ${P}><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/></svg>`,
    acquisition: `<svg ${P}><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"/></svg>`,
    workflow: `<svg ${P}><path d="M4 6h6M4 12h10M4 18h14"/><circle cx="18" cy="6" r="2"/><circle cx="20" cy="12" r="2"/><circle cx="22" cy="18" r="2"/></svg>`,
    organization: `<svg ${P}><rect x="3" y="3" width="8" height="8" rx="1"/><rect x="13" y="3" width="8" height="8" rx="1"/><rect x="3" y="13" width="8" height="8" rx="1"/><rect x="13" y="13" width="8" height="8" rx="1"/></svg>`,
    reporting: `<svg ${P}><path d="M3 3v18h18"/><path d="M7 15l4-5 3 3 5-7"/></svg>`,
    identity: `<svg ${P}><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-6.5 8-6.5s8 2.5 8 6.5"/></svg>`,
    search: `<svg ${P}><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>`,
    bell: `<svg ${P}><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>`,
    menu: `<svg ${P}><path d="M3 6h18M3 12h18M3 18h18"/></svg>`,
    logout: `<svg ${P}><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>`,
    check: `<svg ${P}><path d="M20 6L9 17l-5-5"/></svg>`,
    alert: `<svg ${P}><path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>`,
    info: `<svg ${P}><circle cx="12" cy="12" r="9"/><path d="M12 8h.01M12 11v5"/></svg>`,
    inbox: `<svg ${P}><path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.5 5.1L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.5-6.9A2 2 0 0 0 16.7 4H7.3a2 2 0 0 0-1.8 1.1z"/></svg>`,
    plus: `<svg ${P}><path d="M12 5v14M5 12h14"/></svg>`,
    refresh: `<svg ${P}><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 3v6h-6"/></svg>`,
    download: `<svg ${P}><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>`,
    eye: `<svg ${P}><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>`,
    close: `<svg ${P}><path d="M18 6L6 18M6 6l12 12"/></svg>`,
    chevronLeft: `<svg ${P}><path d="M15 18l-6-6 6-6"/></svg>`,
    chevronRight: `<svg ${P}><path d="M9 18l6-6-6-6"/></svg>`,
  };

  function icon(name, cls = '') {
    return `<span class="icon ${cls}" aria-hidden="true">${icons[name] || icons.info}</span>`;
  }

  /* ---------- Toast ---------- */
  function toast(message, type = 'info', timeout = 4000) {
    const root = document.getElementById('toast-root');
    const el = document.createElement('div');
    el.className = `toast toast--${type}`;
    el.textContent = message;
    root.appendChild(el);
    setTimeout(() => { el.style.opacity = '0'; el.style.transition = 'opacity .3s'; setTimeout(() => el.remove(), 300); }, timeout);
  }

  /* ---------- Modal ---------- */
  function modal({ title, body, footer, size = '' }) {
    return new Promise((resolve) => {
      const backdrop = document.createElement('div');
      backdrop.className = 'modal-backdrop';
      backdrop.innerHTML = `
        <div class="modal ${size === 'lg' ? 'modal--lg' : ''}" role="dialog" aria-modal="true" aria-label="${title}">
          <div class="modal__header">
            <div class="modal__title">${title}</div>
            <button class="modal__close" aria-label="Fermer">${icons.close}</button>
          </div>
          <div class="modal__body">${body}</div>
          ${footer ? `<div class="modal__footer">${footer}</div>` : ''}
        </div>`;
      document.body.appendChild(backdrop);
      const close = () => { backdrop.remove(); resolve(null); };
      backdrop.querySelector('.modal__close').onclick = close;
      backdrop.querySelectorAll('[data-close]').forEach((b) => { b.onclick = close; });
      backdrop.addEventListener('mousedown', (e) => { if (e.target === backdrop) close(); });
      const confirmBtn = backdrop.querySelector('[data-confirm]');
      if (confirmBtn) confirmBtn.onclick = () => { const v = confirmBtn.dataset.value; backdrop.remove(); resolve(v); };
    });
  }
  const confirmDialog = (title, message, { confirmLabel = 'Confirmer', danger = false } = {}) =>
    modal({
      title,
      body: `<p class="muted">${message}</p>`,
      footer: `
        <button class="btn btn--outline" data-close>Annuler</button>
        <button class="btn ${danger ? 'btn--danger' : 'btn--primary'}" data-confirm data-value="ok">${confirmLabel}</button>`,
    });

  /* ---------- Helpers ---------- */
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  function statusBadge(status, labels = {}) {
    const map = {
      active: 'success', completed: 'success', validated: 'success', approved: 'success',
      paid: 'success', delivered: 'info', sent: 'info', in_progress: 'info', scheduled: 'info',
      pending: 'warning', pending_validation: 'warning', draft: '', submitted: 'info',
      rejected: 'danger', cancelled: 'danger', failed: 'danger', abandoned: 'danger',
      overdue: 'danger', lost: 'danger', stolen: 'danger', broken: 'danger',
      under_maintenance: 'warning', inactive: '', archived: '',
    };
    const cls = map[status] ?? '';
    const label = labels[status] || status?.replace(/_/g, ' ') || '—';
    return `<span class="badge ${cls ? `badge--${cls}` : ''}">${esc(label)}</span>`;
  }

  function priorityBadge(p) {
    const map = { critical: 'danger', important: 'warning', normal: '' };
    return `<span class="badge badge--${map[p] ?? ''}">${esc(p || 'normal')}</span>`;
  }

  function fmtDate(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    return isNaN(d) ? '—' : d.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' });
  }
  function fmtDateTime(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    return isNaN(d) ? '—' : d.toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
  }
  function fmtMoney(v, currency = 'XOF') {
    if (v == null) return '—';
    return new Intl.NumberFormat('fr-FR', { style: 'currency', currency, maximumFractionDigits: 0 }).format(v);
  }

  function initials(name = '') {
    return name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase() || '?';
  }

  /** Charge une vue dans #view avec skeleton puis contenu. */
  async function renderView(container, loader) {
    container.innerHTML = `<div class="state"><div class="spinner"></div><p>Chargement…</p></div>`;
    try {
      container.innerHTML = await loader();
    } catch (err) {
      const msg = err instanceof window.SigpeApi.ApiError
        ? `[${err.code}] ${err.message}`
        : err.message;
      container.innerHTML = `
        <div class="card"><div class="state">
          <div class="state__icon">${icons.alert}</div>
          <h3>Chargement impossible</h3>
          <p class="muted">${esc(msg)}</p>
          <button class="btn btn--outline" onclick="location.reload()">Réessayer</button>
        </div></div>`;
    }
  }

  function tableSkeleton(rows = 5, cols = 5) {
    let html = '<div class="table-wrap"><table class="table"><tbody>';
    for (let r = 0; r < rows; r++) {
      html += '<tr>';
      for (let c = 0; c < cols; c++) html += `<td><div class="skeleton" style="height:14px;width:${55 + ((r * 13 + c * 29) % 40)}%"></div></td>`;
      html += '</tr>';
    }
    return html + '</tbody></table></div>';
  }

  function pagination({ page, total, perPage, onPage }) {
    const pages = Math.max(1, Math.ceil(total / perPage));
    if (pages <= 1) return '';
    const btn = (p, label, disabled, current) =>
      `<button class="page-btn ${current ? 'is-current' : ''}" data-page="${p}" ${disabled ? 'disabled' : ''}>${label}</button>`;
    let nums = '';
    const range = [];
    for (let i = Math.max(1, page - 2); i <= Math.min(pages, page + 2); i++) range.push(i);
    if (range[0] > 1) nums += btn(1, '1', false, page === 1) + (range[0] > 2 ? '<span class="muted">…</span>' : '');
    range.forEach((i) => { nums += btn(i, i, false, i === page); });
    if (range[range.length - 1] < pages) nums += (range[range.length - 1] < pages - 1 ? '<span class="muted">…</span>' : '') + btn(pages, pages, false, page === pages);
    return `<div class="pagination">
      <span class="pagination__info">Page ${page} / ${pages} — ${total} élément${total > 1 ? 's' : ''}</span>
      ${btn(page - 1, '‹', page <= 1)}${nums}${btn(page + 1, '›', page >= pages)}
    </div>`;
  }

  window.SigpeUI = { icon, icons, toast, modal, confirmDialog, esc, statusBadge, priorityBadge, fmtDate, fmtDateTime, fmtMoney, initials, renderView, tableSkeleton, pagination };
})();
