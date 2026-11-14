/* SIGPÉ — Vue Tableau de bord (projections reporting-service) */
(function () {
  'use strict';
  const { icon, esc, statusBadge, fmtMoney, fmtDate } = window.SigpeUI;

  async function render() {
    const [dash, notif] = await Promise.allSettled([
      window.SigpeApi.get('/api/v1/dashboard'),
      window.SigpeApi.get('/api/v1/notifications', { per_page: 5 }),
    ]);

    const d = dash.status === 'fulfilled' ? dash.value : null;
    const kpis = extractKpis(d);

    const notifList = notif.status === 'fulfilled'
      ? (notif.value?.data || notif.value || [])
      : [];

    return `
      <div class="page-header">
        <div>
          <div class="page-header__title">Tableau de bord</div>
          <div class="page-header__subtitle">Vue d'ensemble du patrimoine national — données issues des projections Reporting</div>
        </div>
        <button class="btn btn--outline" id="btn-refresh">${icon('refresh')} Actualiser</button>
      </div>

      <div class="kpi-grid">
        ${kpis.length ? kpis.map(kpiCard).join('') : `
          <div class="card" style="grid-column:1/-1"><div class="state">
            <div class="state__icon">${icon('inbox')}</div>
            <h3>Aucune donnée de projection</h3>
            <p class="muted">Les indicateurs apparaîtront après ingestion des événements métier (Reporting Service).</p>
          </div></div>`}
      </div>

      <div class="grid-2">
        <div class="card">
          <div class="card__header">
            <div><div class="card__title">Répartition du parc</div><div class="card__subtitle">Par statut patrimonial</div></div>
          </div>
          <div class="card__body"><div class="chart-placeholder">Graphique — à connecter à un moteur de chart (ex. Chart.js vanilla)</div></div>
        </div>
        <div class="card">
          <div class="card__header">
            <div><div class="card__title">Notifications récentes</div><div class="card__subtitle">Dernières alertes</div></div>
            <a href="#/notifications" class="btn btn--ghost btn--sm">Tout voir</a>
          </div>
          <div class="card__body--flush">
            ${notifList.length ? notifList.map(notifItem).join('') : `<div class="state" style="padding:var(--space-6)"><p class="muted">Aucune notification.</p></div>`}
          </div>
        </div>
      </div>`;
  }

  function extractKpis(d) {
    // Le contrat Reporting expose des indicateurs ; on accepte plusieurs formes.
    const candidates = d?.indicators || d?.data?.indicators || d?.kpis || d?.data || [];
    const arr = Array.isArray(candidates) ? candidates : Object.entries(candidates).map(([key, v]) => ({ key, ...(typeof v === 'object' ? v : { value: v }) }));
    return arr.slice(0, 6).map((k) => ({
      label: k.label || k.name || k.key || 'Indicateur',
      value: k.value ?? k.count ?? k.total ?? '—',
      hint: k.hint || k.unit || '',
      accent: k.accent || pickAccent(k.key || ''),
    }));
  }

  function pickAccent(key) {
    const k = String(key).toLowerCase();
    if (/valeur|finance|coût|cost/.test(k)) return 'gold';
    if (/actif|asset|parc|total/.test(k)) return 'blue';
    if (/maintenance|intervention/.test(k)) return 'red';
    if (/inventaire|inventory/.test(k)) return 'green';
    return '';
  }

  function kpiCard(k) {
    const cls = { gold: 'kpi--gold', green: 'kpi--green', blue: 'kpi--blue', red: 'kpi--red' }[k.accent] || '';
    const value = typeof k.value === 'number' ? k.value.toLocaleString('fr-FR') : esc(k.value);
    return `<div class="kpi ${cls}">
      <div class="kpi__label">${esc(k.label)}</div>
      <div class="kpi__value">${value}</div>
      ${k.hint ? `<div class="kpi__hint">${esc(k.hint)}</div>` : ''}
    </div>`;
  }

  function notifItem(n) {
    return `<div class="list-item">
      <div class="list-item__icon">${icon('bell')}</div>
      <div class="list-item__body">
        <div class="list-item__title">${esc(n.title || n.subject || 'Notification')}</div>
        <div class="list-item__meta">${esc(n.created_at ? fmtDate(n.created_at) : '')}</div>
      </div>
      ${statusBadge(n.priority || n.status)}
    </div>`;
  }

  window.SigpeViews.dashboard = { render, title: 'Tableau de bord' };
})();
