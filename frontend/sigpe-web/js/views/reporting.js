/* SIGPÉ — Vue Reporting (reporting-service) : indicateurs, vues, rapports nationaux, exports */
(function () {
  'use strict';
  const { icon, esc, statusBadge, fmtDate, fmtDateTime, fmtMoney, tableSkeleton, pagination, toast, modal, confirmDialog } = window.SigpeUI;

  const RUN_STATUS = { pending: 'En cours', completed: 'Terminé', failed: 'Échoué' };

  const state = { tab: 'indicators', page: 1, perPage: 15, total: 0, fStatus: '' };

  async function render() {
    const container = document.getElementById('view');
    container.innerHTML = `
      <div class="page-header">
        <div>
          <div class="page-header__title">Rapports &amp; indicateurs</div>
          <div class="page-header__subtitle">Projections lecture seule, rapports nationaux et exports — Reporting Service</div>
        </div>
      </div>
      <div class="btn-row mb-4">
        <button class="btn ${state.tab === 'indicators' ? 'btn--primary' : 'btn--outline'} btn--sm" data-tab="indicators">Indicateurs</button>
        <button class="btn ${state.tab === 'templates' ? 'btn--primary' : 'btn--outline'} btn--sm" data-tab="templates">Rapports</button>
        <button class="btn ${state.tab === 'runs' ? 'btn--primary' : 'btn--outline'} btn--sm" data-tab="runs">Exécutions</button>
      </div>
      <div id="tab-filters"></div>
      <div id="tab-content">${tableSkeleton(6, 6)}</div>`;

    container.querySelectorAll('[data-tab]').forEach((b) => {
      b.onclick = () => { state.tab = b.dataset.tab; state.page = 1; render(); };
    });
    await loadTab();
  }

  async function loadTab() {
    const wrap = document.getElementById('tab-content');
    wrap.innerHTML = tableSkeleton(6, 6);
    try {
      if (state.tab === 'indicators') await loadIndicators(wrap);
      else if (state.tab === 'templates') await loadTemplates(wrap);
      else await loadRuns(wrap);
    } catch (err) {
      wrap.innerHTML = `<div class="card"><div class="state"><div class="state__icon">${icon('alert')}</div><p class="muted">${esc(err.message)}</p></div></div>`;
    }
  }

  /* ================= Indicateurs ================= */

  async function loadIndicators(wrap) {
    document.getElementById('tab-filters').innerHTML = `
      <div class="card mb-6"><div class="card__body" style="display:flex;justify-content:space-between;align-items:center;gap:var(--space-4);flex-wrap:wrap">
        <div class="muted" style="font-size:var(--text-sm)">Métriques calculées depuis les projections synchronisées (lecture seule).</div>
        <button class="btn btn--outline btn--sm" id="btn-refresh">${icon('refresh')} Actualiser</button>
      </div></div>`;
    document.getElementById('btn-refresh').onclick = () => loadTab();

    const [dash, ind] = await Promise.all([
      window.SigpeApi.get('/api/v1/dashboard'),
      window.SigpeApi.get('/api/v1/indicators').catch(() => null),
    ]);
    const m = dash?.data || dash || {};
    const indData = ind?.data || ind || {};

    const kpis = [
      ['Actifs au total', m.assets_total ?? '—', 'assets', 'var(--color-primary)'],
      ['Mouvements', m.movements_count ?? '—', 'movements', 'var(--color-info)'],
      ['Interventions', m.maintenance_interventions ?? '—', 'maintenance', 'var(--color-warning)'],
      ['Campagnes d\'inventaire', m.inventory_campaigns ?? '—', 'inventory', 'var(--color-success)'],
      ['Anomalies ouvertes', m.anomalies_open ?? '—', 'audit', 'var(--color-danger)'],
      ['Acquisitions', m.acquisitions_count ?? '—', 'acquisition', 'var(--color-gold)'],
    ];

    wrap.innerHTML = `
      <div class="grid grid--3 mb-6">
        ${kpis.map(([l, v, ic, color]) => `
          <div class="card"><div class="card__body" style="display:flex;align-items:center;gap:var(--space-3)">
            <div class="list-item__icon" style="background:${color}1a;color:${color}">${icon(ic)}</div>
            <div><div class="muted" style="font-size:var(--text-xs)">${l}</div><div class="kpi-value" style="font-size:var(--text-2xl);font-weight:700">${v}</div></div>
          </div></div>`).join('')}
      </div>
      <div class="grid grid--2">
        <div class="card"><div class="card__header"><div class="card__title">Actifs par état</div></div>
          <div class="card__body">
            ${Object.keys(m.assets_by_state || {}).length
              ? Object.entries(m.assets_by_state).map(([k, v]) => `<div style="display:flex;justify-content:space-between;padding:var(--space-2) 0;border-bottom:1px solid var(--color-border)"><span>${esc(k)}</span><strong>${v}</strong></div>`).join('')
              : '<p class="muted">Aucune donnée.</p>'}
          </div>
        </div>
        <div class="card"><div class="card__header"><div class="card__title">Synchronisation</div></div>
          <div class="card__body">
            <div style="display:flex;justify-content:space-between;padding:var(--space-2) 0;border-bottom:1px solid var(--color-border)"><span>Événements ingérés</span><strong>${m.events_ingested ?? '—'}</strong></div>
            <div style="display:flex;justify-content:space-between;padding:var(--space-2) 0;border-bottom:1px solid var(--color-border)"><span>Dernier événement</span><strong>${fmtDateTime(m.last_event_at)}</strong></div>
            <div style="display:flex;justify-content:space-between;padding:var(--space-2) 0"><span>Décalage de synchro</span><strong>${m.sync_lag_seconds != null ? m.sync_lag_seconds + ' s' : '—'}</strong></div>
            <div class="muted" style="font-size:var(--text-xs);margin-top:var(--space-3)">Calculé le ${fmtDateTime(m.computed_at)} · portée ${esc(m.scope || '—')}</div>
          </div>
        </div>
      </div>
      ${Object.keys(indData).length ? `
      <div class="card mt-6"><div class="card__header"><div class="card__title">Indicateurs détaillés</div></div>
        <div class="card__body"><pre class="mono" style="font-size:var(--text-xs);overflow:auto;max-height:400px">${esc(JSON.stringify(indData, null, 2))}</pre></div>
      </div>` : ''}`;
  }

  /* ================= Templates ================= */

  async function loadTemplates(wrap) {
    document.getElementById('tab-filters').innerHTML = '';
    const res = await window.SigpeApi.get('/api/v1/reports/templates');
    const items = res?.data || res?.items || res || [];
    wrap.innerHTML = `
      <div class="card">
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Code</th><th>Nom</th><th>Catégorie</th><th>Confidentialité</th><th>Paramètres</th><th></th></tr></thead>
          <tbody>
            ${items.length ? items.map(tplRow).join('') : `<tr><td colspan="6"><div class="state" style="padding:var(--space-6)"><div class="state__icon">${icon('inbox')}</div><p class="muted">Aucun modèle de rapport.</p></div></td></tr>`}
          </tbody>
        </table></div>
      </div>`;
    wrap.querySelectorAll('[data-run]').forEach((b) => { b.onclick = () => runTemplate(b.dataset.run, b.dataset.name); });
  }

  function tplRow(t) {
    const conf = { internal: 'info', restricted: 'warning', confidential: 'danger' };
    return `<tr>
      <td class="mono">${esc(t.code)}</td>
      <td><strong>${esc(t.name)}</strong><div class="muted" style="font-size:var(--text-xs)">${esc((t.description || '').slice(0, 80))}</div></td>
      <td>${esc(t.category || '—')}</td>
      <td><span class="badge badge--${conf[t.confidentiality] || ''}">${esc(t.confidentiality || '—')}</span></td>
      <td class="num">${(t.parameters || []).length}</td>
      <td class="text-right"><button class="btn btn--primary btn--sm" data-run="${esc(t.code)}" data-name="${esc(t.name)}">${icon('reporting')} Exécuter</button></td>
    </tr>`;
  }

  function runTemplate(code, name) {
    modal({
      title: `Exécuter : ${name}`,
      body: `
        <div class="form-grid">
          <div class="field"><label>Période début *</label><input class="input" type="date" id="rr-start"></div>
          <div class="field"><label>Période fin *</label><input class="input" type="date" id="rr-end"></div>
          <div class="field"><label>Institution (UUID — vide = national)</label><input class="input mono" id="rr-inst"></div>
          <div class="field span-2"><label>Paramètres (JSON)</label><textarea class="textarea mono" id="rr-params" rows="3" placeholder='{"foo":"bar"}'></textarea></div>
        </div>`,
      footer: `<button class="btn btn--outline" data-close>Annuler</button><button class="btn btn--primary" id="rr-submit">Lancer</button>`,
      onOpen: (bd) => {
        bd.querySelector('#rr-submit').onclick = async () => {
          const body = {
            period_start: bd.querySelector('#rr-start').value,
            period_end: bd.querySelector('#rr-end').value,
          };
          const inst = bd.querySelector('#rr-inst').value.trim(); if (inst) body.institution_id = inst;
          const params = bd.querySelector('#rr-params').value.trim();
          if (params) { try { body.parameters = JSON.parse(params); } catch { toast('JSON des paramètres invalide', 'error'); return; } }
          if (!body.period_start || !body.period_end) { toast('Période requise', 'error'); return; }
          try {
            await window.SigpeApi.post(`/api/v1/reports/${encodeURIComponent(code)}/run`, body);
            bd.remove();
            toast('Exécution lancée', 'success');
            state.tab = 'runs'; state.page = 1; render();
          } catch (err) { toast(err.message, 'error'); }
        };
      },
    });
  }

  /* ================= Exécutions ================= */

  async function loadRuns(wrap) {
    document.getElementById('tab-filters').innerHTML = `
      <div class="card mb-6"><div class="card__body">
        <div class="form-grid">
          <div class="field"><label>Statut</label>
            <select class="select" id="f-status"><option value="">Tous</option>
              ${Object.entries(RUN_STATUS).map(([v, l]) => `<option value="${v}">${l}</option>`).join('')}
            </select></div>
          <div class="field" style="align-self:end"><button class="btn btn--primary" id="btn-search">${icon('search')} Filtrer</button></div>
        </div>
      </div></div>`;
    document.getElementById('btn-search').onclick = () => {
      state.fStatus = document.getElementById('f-status').value;
      state.page = 1; loadTab();
    };

    const params = { page: state.page, per_page: state.perPage };
    if (state.fStatus) params.status = state.fStatus;
    const res = await window.SigpeApi.get('/api/v1/report-runs', params);
    const items = res?.data || res?.items || res || [];
    state.total = res?.meta?.total ?? res?.total ?? items.length;
    wrap.innerHTML = `
      <div class="card">
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Référence</th><th>Rapport</th><th>Période</th><th>Statut</th><th>Confidentialité</th><th>Généré le</th><th></th></tr></thead>
          <tbody>
            ${items.length ? items.map(runRow).join('') : `<tr><td colspan="7"><div class="state" style="padding:var(--space-6)"><div class="state__icon">${icon('inbox')}</div><p class="muted">Aucune exécution.</p></div></td></tr>`}
          </tbody>
        </table></div>
        ${pagination({ page: state.page, total: state.total, perPage: state.perPage, onPage: (p) => { state.page = p; loadTab(); } })}
      </div>`;
    wrap.querySelectorAll('[data-detail]').forEach((b) => { b.onclick = () => showRun(b.dataset.detail); });
    wrap.querySelectorAll('.page-btn[data-page]').forEach((b) => { b.onclick = () => { state.page = parseInt(b.dataset.page, 10); loadTab(); }; });
  }

  function runRow(r) {
    const conf = { internal: 'info', restricted: 'warning', confidential: 'danger' };
    return `<tr>
      <td class="mono">${esc(r.reference)}</td>
      <td>${esc(r.template_name || r.template_code)}</td>
      <td>${fmtDate(r.period_start)} → ${fmtDate(r.period_end)}</td>
      <td>${statusBadge(r.status, RUN_STATUS)}</td>
      <td><span class="badge badge--${conf[r.confidentiality] || ''}">${esc(r.confidentiality || '—')}</span></td>
      <td>${fmtDateTime(r.generated_at || r.created_at)}</td>
      <td class="text-right">
        <button class="btn btn--ghost btn--sm" data-detail="${esc(r.id)}">${icon('eye')}</button>
        ${r.status === 'completed' ? `<button class="btn btn--ghost btn--sm" data-export="${esc(r.id)}" title="Exporter">${icon('download')}</button>` : ''}
      </td>
    </tr>`;
  }

  async function showRun(id) {
    try {
      const d = await window.SigpeApi.get(`/api/v1/report-runs/${id}`);
      const r = d?.data || d;
      modal({
        title: `Exécution ${r.reference}`,
        size: 'lg',
        body: `
          <div class="form-grid mb-4">
            <div class="field"><label>Statut</label><div>${statusBadge(r.status, RUN_STATUS)}</div></div>
            <div class="field"><label>Rapport</label><div>${esc(r.template_name || r.template_code)}</div></div>
            <div class="field"><label>Période</label><div>${fmtDate(r.period_start)} → ${fmtDate(r.period_end)}</div></div>
            <div class="field"><label>Institution</label><div class="mono">${esc(r.institution_id || 'nationale')}</div></div>
          </div>
          ${r.result_summary ? `<div class="field"><label>Résumé du résultat</label><pre class="mono" style="font-size:var(--text-xs);overflow:auto;max-height:300px;background:var(--color-bg);padding:var(--space-3);border-radius:var(--radius)">${esc(JSON.stringify(r.result_summary, null, 2))}</pre></div>` : '<p class="muted">Aucun résumé disponible.</p>'}`,
      });
    } catch (err) { toast(err.message, 'error'); }
  }

  async function exportRun(id) {
    try {
      const res = await window.SigpeApi.get(`/api/v1/report-runs/${id}/export`);
      const url = res?.url || res?.data?.url || res?.download_url;
      if (url) window.open(url, '_blank');
      else toast('Export demandé — vérifiez les exécutions', 'info');
    } catch (err) { toast(err.message, 'error'); }
  }

  window.SigpeViews.reporting = { render, title: 'Rapports & indicateurs' };
  window.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-export]');
    if (btn) { e.preventDefault(); exportRun(btn.dataset.export); }
  });
})();
