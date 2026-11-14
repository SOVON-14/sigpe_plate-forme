/* SIGPÉ — Vue Registre des actifs (asset-service) */
(function () {
  'use strict';
  const { icon, esc, statusBadge, fmtMoney, fmtDate, tableSkeleton, pagination, renderView, toast } = window.SigpeUI;

  const state = { page: 1, perPage: 15, total: 0, q: '', status: '' };

  const STATUS_LABELS = {
    active: 'Actif', inactive: 'Inactif', disposed: 'Sorti', reform: 'Réformé',
    under_maintenance: 'En maintenance', lost: 'Perdu', stolen: 'Volé',
    pending_rattachement: 'Rattachement en cours',
  };

  async function fetchAssets() {
    // Asset Service : GET /api/v1/assets (recherche paginée) — fallback /institutions si besoin.
    const params = { page: state.page, per_page: state.perPage };
    if (state.q) params.q = state.q;
    if (state.status) params.status = state.status;
    const res = await window.SigpeApi.get('/api/v1/assets', params);
    const items = res?.data || res?.items || res || [];
    state.total = res?.meta?.total ?? res?.total ?? items.length;
    return items;
  }

  async function render() {
    const container = document.getElementById('view');
    container.innerHTML = `
      <div class="page-header">
        <div>
          <div class="page-header__title">Registre des actifs</div>
          <div class="page-header__subtitle">Données maîtres patrimoniales — Asset Service</div>
        </div>
      </div>
      <div class="card mb-6">
        <div class="card__body">
          <div class="form-grid">
            <div class="field"><label>Recherche</label>
              <input class="input" id="f-q" placeholder="Code, désignation, marque…" value="${esc(state.q)}"></div>
            <div class="field"><label>Statut</label>
              <select class="select" id="f-status">
                <option value="">Tous</option>
                ${Object.entries(STATUS_LABELS).map(([v, l]) => `<option value="${v}" ${state.status === v ? 'selected' : ''}>${l}</option>`).join('')}
              </select></div>
            <div class="field" style="align-self:end">
              <button class="btn btn--primary" id="btn-search">${icon('search')} Rechercher</button>
            </div>
          </div>
        </div>
      </div>
      <div id="assets-table">${tableSkeleton(6, 6)}</div>`;

    document.getElementById('btn-search').onclick = () => {
      state.q = document.getElementById('f-q').value.trim();
      state.status = document.getElementById('f-status').value;
      state.page = 1;
      load();
    };
    document.getElementById('f-q').addEventListener('keydown', (e) => { if (e.key === 'Enter') document.getElementById('btn-search').click(); });

    await load();
  }

  async function load() {
    const wrap = document.getElementById('assets-table');
    wrap.innerHTML = tableSkeleton(6, 6);
    try {
      const items = await fetchAssets();
      wrap.innerHTML = `
        <div class="card">
          <div class="table-wrap"><table class="table">
            <thead><tr>
              <th>Code</th><th>Désignation</th><th>Catégorie</th><th>Institution</th>
              <th>État</th><th>Statut</th><th class="num">Valeur</th><th></th>
            </tr></thead>
            <tbody>
              ${items.length ? items.map(assetRow).join('') : `<tr><td colspan="8"><div class="state" style="padding:var(--space-6)">
                <div class="state__icon">${icon('inbox')}</div><p class="muted">Aucun actif trouvé.</p></div></td></tr>`}
            </tbody>
          </table></div>
          ${pagination({ page: state.page, total: state.total, perPage: state.perPage, onPage: (p) => { state.page = p; load(); } })}
        </div>`;
      wrap.querySelectorAll('[data-detail]').forEach((b) => {
        b.onclick = () => showDetail(b.dataset.detail);
      });
      wrap.querySelectorAll('.page-btn[data-page]').forEach((b) => {
        b.onclick = () => { state.page = parseInt(b.dataset.page, 10); load(); };
      });
    } catch (err) {
      wrap.innerHTML = `<div class="card"><div class="state">
        <div class="state__icon">${icon('alert')}</div>
        <h3>Erreur de chargement</h3>
        <p class="muted">${esc(err.message)}</p>
        <button class="btn btn--outline" onclick="document.querySelector('#assets-table') && location.hash='#/assets'">Réessayer</button>
      </div></div>`;
    }
  }

  function assetRow(a) {
    return `<tr>
      <td class="mono">${esc(a.code || a.asset_code || '—')}</td>
      <td><strong>${esc(a.name || a.designation || a.label || '—')}</strong></td>
      <td>${esc(a.category?.name || a.category_name || a.category || '—')}</td>
      <td>${esc(a.institution?.name || a.institution_name || '—')}</td>
      <td>${statusBadge(a.physical_state || a.physicalState, { good: 'Bon', damaged: 'Endommagé', unusable: 'Hors service' })}</td>
      <td>${statusBadge(a.status, STATUS_LABELS)}</td>
      <td class="num">${fmtMoney(a.current_value ?? a.value)}</td>
      <td><button class="btn btn--ghost btn--sm" data-detail="${esc(a.id)}">${icon('eye')} Détail</button></td>
    </tr>`;
  }

  async function showDetail(id) {
    try {
      const a = await window.SigpeApi.get(`/api/v1/assets/${id}`);
      const d = a?.data || a;
      window.SigpeUI.modal({
        title: `Actif ${d.code || d.asset_code || ''}`,
        size: 'lg',
        body: `
          <div class="form-grid">
            <div class="field"><label>Désignation</label><div>${esc(d.name || d.designation || '—')}</div></div>
            <div class="field"><label>Catégorie</label><div>${esc(d.category?.name || d.category_name || '—')}</div></div>
            <div class="field"><label>Marque / Modèle</label><div>${esc([d.brand, d.model].filter(Boolean).join(' ') || '—')}</div></div>
            <div class="field"><label>N° série / inventaire</label><div class="mono">${esc(d.serial_number || d.inventory_number || '—')}</div></div>
            <div class="field"><label>Institution</label><div>${esc(d.institution?.name || d.institution_name || '—')}</div></div>
            <div class="field"><label>Unité organisationnelle</label><div>${esc(d.org_unit?.name || d.org_unit_name || '—')}</div></div>
            <div class="field"><label>État physique</label><div>${statusBadge(d.physical_state, { good: 'Bon', damaged: 'Endommagé', unusable: 'Hors service' })}</div></div>
            <div class="field"><label>Statut</label><div>${statusBadge(d.status, STATUS_LABELS)}</div></div>
            <div class="field"><label>Valeur d'acquisition</label><div>${fmtMoney(d.acquisition_value)}</div></div>
            <div class="field"><label>Valeur actuelle</label><div>${fmtMoney(d.current_value ?? d.value)}</div></div>
            <div class="field"><label>Date d'acquisition</label><div>${fmtDate(d.acquisition_date)}</div></div>
            <div class="field"><label>QR / Code-barres</label><div class="mono">${esc(d.qr_code || d.barcode || '—')}</div></div>
          </div>`,
      });
    } catch (err) {
      toast(err.message, 'error');
    }
  }

  window.SigpeViews.assets = { render, title: 'Registre des actifs' };
})();
