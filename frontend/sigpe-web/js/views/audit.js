/* SIGPÉ — Vue Audit (audit-service) : journal immuable, chaîne de hachage, statistiques */
(function () {
  'use strict';
  const { icon, esc, statusBadge, fmtDate, fmtDateTime, tableSkeleton, pagination, toast, modal } = window.SigpeUI;

  const RESULT = { success: 'Succès', failure: 'Échec', denied: 'Refusé' };
  const SENSITIVITY = { normal: 'Normale', sensitive: 'Sensible', critical: 'Critique' };

  const state = {
    tab: 'entries', page: 1, perPage: 15, total: 0,
    fProducer: '', fEvent: '', fResult: '', fQ: '', fFrom: '', fTo: '',
  };

  async function render() {
    const container = document.getElementById('view');
    container.innerHTML = `
      <div class="page-header">
        <div>
          <div class="page-header__title">Journal d'audit</div>
          <div class="page-header__subtitle">Registre immuable, chaîne de hachage SHA-256 — Audit Service</div>
        </div>
      </div>
      <div class="alert alert--info mb-4">${icon('audit')} Ce journal est <strong>immuable</strong> : aucune entrée ne peut être modifiée ou supprimée. Chaque entrée est chaînée par empreinte cryptographique.</div>
      <div class="btn-row mb-4">
        <button class="btn ${state.tab === 'entries' ? 'btn--primary' : 'btn--outline'} btn--sm" data-tab="entries">Entrées</button>
        <button class="btn ${state.tab === 'stats' ? 'btn--primary' : 'btn--outline'} btn--sm" data-tab="stats">Statistiques</button>
        <button class="btn btn--gold" id="btn-verify">${icon('check')} Vérifier la chaîne</button>
      </div>
      <div id="tab-filters"></div>
      <div id="tab-content">${tableSkeleton(7, 6)}</div>`;

    container.querySelectorAll('[data-tab]').forEach((b) => {
      b.onclick = () => { state.tab = b.dataset.tab; state.page = 1; render(); };
    });
    document.getElementById('btn-verify').onclick = verifyChain;
    await loadTab();
  }

  async function loadTab() {
    const wrap = document.getElementById('tab-content');
    wrap.innerHTML = tableSkeleton(7, 6);
    try {
      if (state.tab === 'entries') await loadEntries(wrap);
      else await loadStats(wrap);
    } catch (err) {
      wrap.innerHTML = `<div class="card"><div class="state"><div class="state__icon">${icon('alert')}</div><p class="muted">${esc(err.message)}</p></div></div>`;
    }
  }

  /* ================= Entrées ================= */

  async function loadEntries(wrap) {
    document.getElementById('tab-filters').innerHTML = `
      <div class="card mb-6"><div class="card__body">
        <div class="form-grid">
          <div class="field"><label>Producteur</label><input class="input" id="f-producer" value="${esc(state.fProducer)}" placeholder="asset-service"></div>
          <div class="field"><label>Type d'événement</label><input class="input mono" id="f-event" value="${esc(state.fEvent)}" placeholder="asset.asset_created.v1"></div>
          <div class="field"><label>Résultat</label>
            <select class="select" id="f-result"><option value="">Tous</option>
              ${Object.entries(RESULT).map(([v, l]) => `<option value="${v}" ${state.fResult === v ? 'selected' : ''}>${l}</option>`).join('')}
            </select></div>
          <div class="field"><label>Recherche</label><input class="input" id="f-q" value="${esc(state.fQ)}" placeholder="opération, ressource…"></div>
          <div class="field"><label>Du</label><input class="input" type="datetime-local" id="f-from" value="${esc(state.fFrom)}"></div>
          <div class="field"><label>Au</label><input class="input" type="datetime-local" id="f-to" value="${esc(state.fTo)}"></div>
          <div class="field" style="align-self:end"><button class="btn btn--primary" id="btn-search">${icon('search')} Filtrer</button></div>
        </div>
      </div></div>`;
    document.getElementById('btn-search').onclick = () => {
      state.fProducer = document.getElementById('f-producer').value.trim();
      state.fEvent = document.getElementById('f-event').value.trim();
      state.fResult = document.getElementById('f-result').value;
      state.fQ = document.getElementById('f-q').value.trim();
      state.fFrom = document.getElementById('f-from').value;
      state.fTo = document.getElementById('f-to').value;
      state.page = 1; loadTab();
    };

    const params = { page: state.page, per_page: state.perPage };
    if (state.fProducer) params.producer = state.fProducer;
    if (state.fEvent) params.event_type = state.fEvent;
    if (state.fResult) params.result = state.fResult;
    if (state.fQ) params.q = state.fQ;
    if (state.fFrom) params.occurred_from = new Date(state.fFrom).toISOString();
    if (state.fTo) params.occurred_to = new Date(state.fTo).toISOString();

    const res = await window.SigpeApi.get('/api/v1/audit-entries', params);
    const items = res?.data || res?.items || res || [];
    state.total = res?.meta?.total ?? res?.total ?? items.length;

    wrap.innerHTML = `
      <div class="card">
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Séq.</th><th>Date</th><th>Producteur</th><th>Opération</th><th>Ressource</th><th>Résultat</th><th>Sensibilité</th><th>Empreinte</th><th></th></tr></thead>
          <tbody>
            ${items.length ? items.map(entryRow).join('') : `<tr><td colspan="9"><div class="state" style="padding:var(--space-6)"><div class="state__icon">${icon('inbox')}</div><p class="muted">Aucune entrée d'audit.</p></div></td></tr>`}
          </tbody>
        </table></div>
        ${pagination({ page: state.page, total: state.total, perPage: state.perPage, onPage: (p) => { state.page = p; loadTab(); } })}
      </div>`;
    wrap.querySelectorAll('[data-detail]').forEach((b) => { b.onclick = () => showEntry(b.dataset.detail); });
    wrap.querySelectorAll('.page-btn[data-page]').forEach((b) => { b.onclick = () => { state.page = parseInt(b.dataset.page, 10); loadTab(); }; });
  }

  function entryRow(e) {
    const resType = e.resource_type ? `${e.resource_type}${e.resource_id ? ':' + short(e.resource_id) : ''}` : '—';
    const sens = e.sensitivity === 'critical' ? 'danger' : e.sensitivity === 'sensitive' ? 'warning' : '';
    return `<tr>
      <td class="num mono" style="font-size:var(--text-xs)">${e.sequence ?? '—'}</td>
      <td>${fmtDateTime(e.occurred_at)}</td>
      <td><span class="badge">${esc(e.producer)}</span></td>
      <td>${esc(e.operation || e.event_type || '—')}</td>
      <td class="mono" style="font-size:var(--text-xs)">${esc(resType)}</td>
      <td>${statusBadge(e.result, RESULT)}</td>
      <td><span class="badge badge--${sens}">${esc(SENSITIVITY[e.sensitivity] || e.sensitivity || '—')}</span></td>
      <td class="mono" style="font-size:var(--text-xs)" title="${esc(e.entry_hash)}">${esc(short(e.entry_hash))}</td>
      <td class="text-right"><button class="btn btn--ghost btn--sm" data-detail="${esc(e.id)}">${icon('eye')}</button></td>
    </tr>`;
  }

  async function showEntry(id) {
    try {
      const d = await window.SigpeApi.get(`/api/v1/audit-entries/${id}`);
      const e = d?.data || d;
      modal({
        title: `Entrée d'audit #${e.sequence ?? short(e.id)}`,
        size: 'lg',
        body: `
          <div class="form-grid mb-4">
            <div class="field"><label>Date</label><div>${fmtDateTime(e.occurred_at)}</div></div>
            <div class="field"><label>Producteur</label><div>${esc(e.producer)}</div></div>
            <div class="field"><label>Événement</label><div class="mono">${esc(e.event_type)} (${esc(e.event_version)})</div></div>
            <div class="field"><label>Opération</label><div>${esc(e.operation || '—')}</div></div>
            <div class="field"><label>Ressource</label><div>${esc(e.resource_type || '—')} ${e.resource_id ? `<span class="mono">${esc(short(e.resource_id))}</span>` : ''}</div></div>
            <div class="field"><label>Acteur</label><div>${esc(e.actor_type || '—')} ${e.actor_id ? `<span class="mono">${esc(short(e.actor_id))}</span>` : ''}</div></div>
            <div class="field"><label>Résultat</label><div>${statusBadge(e.result, RESULT)}</div></div>
            <div class="field"><label>Sensibilité</label><div>${esc(SENSITIVITY[e.sensitivity] || '—')}</div></div>
          </div>
          ${e.reason ? `<div class="field"><label>Motif</label><p>${esc(e.reason)}</p></div>` : ''}
          <div class="form-grid">
            <div class="field"><label>Empreinte précédente</label><div class="mono" style="font-size:var(--text-xs);word-break:break-all">${esc(e.previous_hash || '—')}</div></div>
            <div class="field"><label>Empreinte</label><div class="mono" style="font-size:var(--text-xs);word-break:break-all">${esc(e.entry_hash)}</div></div>
            ${e.evidence_hash ? `<div class="field span-2"><label>Empreinte de preuve</label><div class="mono" style="font-size:var(--text-xs);word-break:break-all">${esc(e.evidence_hash)}</div></div>` : ''}
          </div>
          ${(e.old_value || e.new_value) ? `
          <div class="form-grid mt-4">
            ${e.old_value ? `<div class="field"><label>Anciennes valeurs</label><pre class="mono" style="font-size:var(--text-xs);overflow:auto;max-height:200px;background:var(--color-bg);padding:var(--space-3);border-radius:var(--radius)">${esc(JSON.stringify(e.old_value, null, 2))}</pre></div>` : ''}
            ${e.new_value ? `<div class="field"><label>Nouvelles valeurs</label><pre class="mono" style="font-size:var(--text-xs);overflow:auto;max-height:200px;background:var(--color-bg);padding:var(--space-3);border-radius:var(--radius)">${esc(JSON.stringify(e.new_value, null, 2))}</pre></div>` : ''}
          </div>` : ''}
          ${e.payload ? `<div class="field mt-4"><label>Payload complet</label><pre class="mono" style="font-size:var(--text-xs);overflow:auto;max-height:300px;background:var(--color-bg);padding:var(--space-3);border-radius:var(--radius)">${esc(JSON.stringify(e.payload, null, 2))}</pre></div>` : ''}`,
      });
    } catch (err) { toast(err.message, 'error'); }
  }

  /* ================= Vérification de chaîne ================= */

  async function verifyChain() {
    const btn = document.getElementById('btn-verify');
    btn.disabled = true; btn.innerHTML = `${icon('refresh')} Vérification…`;
    try {
      const d = await window.SigpeApi.get('/api/v1/audit-entries/chain/verify');
      const r = d?.data || d;
      if (r.valid) {
        toast(`Chaîne intègre — ${r.entries_checked} entrées vérifiées`, 'success');
      } else {
        toast(`Chaîne rompue à la séquence ${r.first_invalid_sequence}`, 'error');
      }
    } catch (err) {
      toast(err.message, 'error');
    } finally {
      btn.disabled = false; btn.innerHTML = `${icon('check')} Vérifier la chaîne`;
    }
  }

  /* ================= Statistiques ================= */

  async function loadStats(wrap) {
    document.getElementById('tab-filters').innerHTML = '';
    const res = await window.SigpeApi.get('/api/v1/audit-entries/stats');
    const s = res?.data || res || {};
    const topList = (obj, limit = 10) => Object.entries(obj || {}).slice(0, limit)
      .map(([k, v]) => `<div style="display:flex;justify-content:space-between;padding:var(--space-2) 0;border-bottom:1px solid var(--color-border)"><span class="mono" style="font-size:var(--text-xs)">${esc(k)}</span><strong>${v}</strong></div>`).join('');

    wrap.innerHTML = `
      <div class="grid grid--3">
        <div class="card"><div class="card__header"><div class="card__title">Total</div></div>
          <div class="card__body"><div class="kpi-value">${s.total_entries ?? '—'}</div>
            <div class="muted" style="font-size:var(--text-xs)">du ${fmtDateTime(s.first_entry_at)} au ${fmtDateTime(s.last_entry_at)}</div></div>
        </div>
        <div class="card"><div class="card__header"><div class="card__title">Par résultat</div></div>
          <div class="card__body">${topList(s.by_result)}</div>
        </div>
        <div class="card"><div class="card__header"><div class="card__title">Top producteurs</div></div>
          <div class="card__body">${topList(s.by_producer)}</div>
        </div>
      </div>
      <div class="grid grid--2 mt-6">
        <div class="card"><div class="card__header"><div class="card__title">Top opérations</div></div>
          <div class="card__body">${topList(s.by_operation)}</div>
        </div>
        <div class="card"><div class="card__header"><div class="card__title">Volume quotidien</div></div>
          <div class="card__body">
            ${(s.daily || []).length ? (s.daily || []).slice(-30).map((d) => `
              <div style="display:flex;justify-content:space-between;padding:var(--space-2) 0;border-bottom:1px solid var(--color-border)">
                <span>${esc(d.date)}</span><strong>${d.count}</strong>
              </div>`).join('') : '<p class="muted">Aucune donnée.</p>'}
          </div>
        </div>
      </div>`;
  }

  const short = (id) => (id ? String(id).slice(0, 8) : '—');

  window.SigpeViews.audit = { render, title: 'Journal d\'audit' };
})();
