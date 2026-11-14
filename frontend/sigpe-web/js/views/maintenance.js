/* SIGPÉ — Vue Maintenance (maintenance-service) : demandes, interventions, stocks */
(function () {
  'use strict';
  const { icon, esc, statusBadge, fmtDate, fmtDateTime, fmtMoney, tableSkeleton, pagination, toast, modal, confirmDialog } = window.SigpeUI;

  const TYPE_LABELS = { corrective: 'Corrective', preventive: 'Préventive', conditionnelle: 'Conditionnelle' };
  const REQ_STATUS = {
    draft: 'Brouillon', pending_validation: 'En validation', validated: 'Validée', rejected: 'Rejetée',
    planned: 'Planifiée', in_progress: 'En cours', completed: 'Terminée', cancelled: 'Annulée',
  };
  const INT_STATUS = {
    planned: 'Planifiée', in_progress: 'En cours', interrupted: 'Interrompue',
    completed: 'Terminée', cancelled: 'Annulée',
  };
  const URGENCY_LABELS = { low: 'Basse', normal: 'Normale', high: 'Haute', urgent: 'Urgente' };

  const state = { tab: 'requests', page: 1, perPage: 15, total: 0, fStatus: '', fType: '' };

  async function render() {
    const container = document.getElementById('view');
    container.innerHTML = `
      <div class="page-header">
        <div>
          <div class="page-header__title">Maintenance</div>
          <div class="page-header__subtitle">Demandes, interventions, coûts et stocks de pièces — Maintenance Service</div>
        </div>
      </div>
      <div class="btn-row mb-4">
        <button class="btn ${state.tab === 'requests' ? 'btn--primary' : 'btn--outline'} btn--sm" data-tab="requests">Demandes</button>
        <button class="btn ${state.tab === 'interventions' ? 'btn--primary' : 'btn--outline'} btn--sm" data-tab="interventions">Interventions</button>
        <button class="btn ${state.tab === 'stock' ? 'btn--primary' : 'btn--outline'} btn--sm" data-tab="stock">Stock pièces</button>
      </div>
      <div id="tab-filters"></div>
      <div id="tab-content">${tableSkeleton(6, 6)}</div>`;

    container.querySelectorAll('[data-tab]').forEach((b) => {
      b.onclick = () => { state.tab = b.dataset.tab; state.page = 1; render(); };
    });

    if (state.tab === 'requests') {
      document.getElementById('tab-filters').innerHTML = `
        <div class="card mb-6"><div class="card__body">
          <div class="form-grid">
            <div class="field"><label>Type</label>
              <select class="select" id="f-type"><option value="">Tous</option>
                ${Object.entries(TYPE_LABELS).map(([v, l]) => `<option value="${v}" ${state.fType === v ? 'selected' : ''}>${l}</option>`).join('')}
              </select></div>
            <div class="field"><label>Statut</label>
              <select class="select" id="f-status"><option value="">Tous</option>
                ${Object.entries(REQ_STATUS).map(([v, l]) => `<option value="${v}" ${state.fStatus === v ? 'selected' : ''}>${l}</option>`).join('')}
              </select></div>
            <div class="field" style="align-self:end">
              <button class="btn btn--primary" id="btn-search">${icon('search')} Filtrer</button>
              <button class="btn btn--gold" id="btn-new">${icon('plus')} Nouvelle demande</button>
            </div>
          </div>
        </div></div>`;
      document.getElementById('btn-search').onclick = () => {
        state.fType = document.getElementById('f-type').value;
        state.fStatus = document.getElementById('f-status').value;
        state.page = 1; loadTab();
      };
      document.getElementById('btn-new').onclick = newRequestForm;
    } else if (state.tab === 'interventions') {
      document.getElementById('tab-filters').innerHTML = '';
    } else {
      document.getElementById('tab-filters').innerHTML = '';
    }
    await loadTab();
  }

  async function loadTab() {
    const wrap = document.getElementById('tab-content');
    wrap.innerHTML = tableSkeleton(6, 6);
    try {
      if (state.tab === 'requests') await loadRequests(wrap);
      else if (state.tab === 'interventions') await loadInterventions(wrap);
      else await loadStock(wrap);
    } catch (err) {
      wrap.innerHTML = `<div class="card"><div class="state"><div class="state__icon">${icon('alert')}</div><p class="muted">${esc(err.message)}</p></div></div>`;
    }
  }

  /* ================= Demandes ================= */

  async function loadRequests(wrap) {
    const params = { page: state.page, per_page: state.perPage };
    if (state.fType) params.type = state.fType;
    if (state.fStatus) params.status = state.fStatus;
    const res = await window.SigpeApi.get('/api/v1/maintenance-requests', params);
    const items = res?.data || res?.items || res || [];
    state.total = res?.meta?.total ?? res?.total ?? items.length;
    wrap.innerHTML = `
      <div class="card">
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Référence</th><th>Bien</th><th>Type</th><th>Urgence</th><th>Statut</th><th>Coût estimé</th><th>Créée le</th><th></th></tr></thead>
          <tbody>
            ${items.length ? items.map(reqRow).join('') : `<tr><td colspan="8"><div class="state" style="padding:var(--space-6)"><div class="state__icon">${icon('inbox')}</div><p class="muted">Aucune demande.</p></div></td></tr>`}
          </tbody>
        </table></div>
        ${pagination({ page: state.page, total: state.total, perPage: state.perPage, onPage: (p) => { state.page = p; loadTab(); } })}
      </div>`;
    wrap.querySelectorAll('[data-detail]').forEach((b) => { b.onclick = () => showRequest(b.dataset.detail); });
    wrap.querySelectorAll('.page-btn[data-page]').forEach((b) => { b.onclick = () => { state.page = parseInt(b.dataset.page, 10); loadTab(); }; });
  }

  function reqRow(r) {
    const urgent = r.is_urgent_track ? ' <span class="badge badge--danger" title="Voie urgente justifiée">urgente</span>' : '';
    return `<tr>
      <td class="mono">${esc(r.reference || short(r.id))}</td>
      <td class="mono" style="font-size:var(--text-xs)">${esc(short(r.asset_id))}</td>
      <td>${esc(TYPE_LABELS[r.type] || r.type)}</td>
      <td>${esc(URGENCY_LABELS[r.urgency] || r.urgency || '—')}${urgent}</td>
      <td>${statusBadge(r.status, REQ_STATUS)}</td>
      <td class="num">${fmtMoney(r.estimated_cost)}</td>
      <td>${fmtDate(r.created_at)}</td>
      <td class="text-right"><button class="btn btn--ghost btn--sm" data-detail="${esc(r.id)}">${icon('eye')} Ouvrir</button></td>
    </tr>`;
  }

  async function showRequest(id) {
    let d;
    try { d = await window.SigpeApi.get(`/api/v1/maintenance-requests/${id}`); }
    catch (err) { toast(err.message, 'error'); return; }
    const r = d?.data || d;

    const actions = [];
    if (['draft', 'rejected'].includes(r.status)) actions.push(['submit', 'Soumettre', 'btn--primary']);
    if (!['completed', 'cancelled', 'rejected'].includes(r.status)) actions.push(['cancel', 'Annuler', 'btn--danger']);

    modal({
      title: `Demande ${r.reference || short(r.id)}`,
      size: 'lg',
      body: `
        <div class="form-grid mb-4">
          <div class="field"><label>Statut</label><div>${statusBadge(r.status, REQ_STATUS)}</div></div>
          <div class="field"><label>Type</label><div>${esc(TYPE_LABELS[r.type] || r.type)}</div></div>
          <div class="field"><label>Bien (asset)</label><div class="mono">${esc(r.asset_id || '—')}</div></div>
          <div class="field"><label>Urgence</label><div>${esc(URGENCY_LABELS[r.urgency] || '—')}${r.is_urgent_track ? ' — voie urgente' : ''}</div></div>
          <div class="field"><label>Coût estimé</label><div>${fmtMoney(r.estimated_cost)}</div></div>
          <div class="field"><label>Workflow</label><div class="mono">${esc(r.workflow_instance_id || '—')}</div></div>
        </div>
        <div class="field"><label>Description</label><p>${esc(r.description || '—')}</p></div>
        ${r.symptoms ? `<div class="field"><label>Symptômes</label><p class="muted">${esc(r.symptoms)}</p></div>` : ''}
        ${r.urgent_justification ? `<div class="alert alert--warning"><strong>Justification voie urgente :</strong> ${esc(r.urgent_justification)}</div>` : ''}`,
      footer: actions.map(([a, l, c]) => `<button class="btn ${c}" data-act="${a}">${esc(l)}</button>`).join(''),
      onOpen: (bd) => {
        bd.querySelectorAll('[data-act]').forEach((b) => {
          b.onclick = async () => {
            bd.remove();
            try {
              if (b.dataset.act === 'submit') {
                await window.SigpeApi.post(`/api/v1/maintenance-requests/${id}/submit`, {});
                toast('Demande soumise en validation', 'success');
              } else {
                const motif = await promptText('Motif d\'annulation', '');
                if (!motif) return;
                await window.SigpeApi.post(`/api/v1/maintenance-requests/${id}/cancel`, { motif });
                toast('Demande annulée', 'info');
              }
              loadTab();
            } catch (err) { toast(err.message, 'error'); }
          };
        });
      },
    });
  }

  function newRequestForm() {
    modal({
      title: 'Nouvelle demande de maintenance', size: 'lg',
      body: `
        <div class="form-grid">
          <div class="field"><label>Bien (UUID asset) *</label><input class="input mono" id="r-asset"></div>
          <div class="field"><label>Type *</label>
            <select class="select" id="r-type">${Object.entries(TYPE_LABELS).map(([v, l]) => `<option value="${v}">${l}</option>`).join('')}</select></div>
          <div class="field"><label>Urgence</label>
            <select class="select" id="r-urgency">${Object.entries(URGENCY_LABELS).map(([v, l]) => `<option value="${v}" ${v === 'normal' ? 'selected' : ''}>${l}</option>`).join('')}</select></div>
          <div class="field"><label>Coût estimé (XOF)</label><input class="input" type="number" min="0" id="r-cost"></div>
          <div class="field span-2"><label>Description *</label><textarea class="textarea" id="r-desc" rows="3" maxlength="4000" required></textarea></div>
          <div class="field span-2"><label>Symptômes / constats</label><textarea class="textarea" id="r-symptoms" rows="2" maxlength="4000"></textarea></div>
          <div class="field"><label class="check"><input type="checkbox" id="r-urgent"> Voie urgente (justification obligatoire)</label></div>
          <div class="field" id="r-justif-wrap" style="display:none"><label>Justification urgente *</label><input class="input" id="r-justif" maxlength="2000"></div>
        </div>`,
      footer: `<button class="btn btn--outline" data-close>Annuler</button><button class="btn btn--primary" id="r-submit">Créer le brouillon</button>`,
      onOpen: (bd) => {
        const urgentCb = bd.querySelector('#r-urgent');
        urgentCb.onchange = () => { bd.querySelector('#r-justif-wrap').style.display = urgentCb.checked ? 'block' : 'none'; };
        bd.querySelector('#r-submit').onclick = async () => {
          const body = {
            asset_id: bd.querySelector('#r-asset').value.trim(),
            type: bd.querySelector('#r-type').value,
            description: bd.querySelector('#r-desc').value.trim(),
            urgency: bd.querySelector('#r-urgency').value,
          };
          const cost = bd.querySelector('#r-cost').value; if (cost) body.estimated_cost = parseFloat(cost);
          const sym = bd.querySelector('#r-symptoms').value.trim(); if (sym) body.symptoms = sym;
          if (urgentCb.checked) {
            body.is_urgent_track = true;
            body.urgent_justification = bd.querySelector('#r-justif').value.trim();
            if (!body.urgent_justification) { toast('Justification urgente requise', 'error'); return; }
          }
          if (!body.asset_id || body.description.length < 3) { toast('Bien et description requis', 'error'); return; }
          try {
            await window.SigpeApi.post('/api/v1/maintenance-requests', body);
            bd.remove();
            toast('Demande créée (brouillon)', 'success');
            state.page = 1; loadTab();
          } catch (err) { toast(err.message, 'error'); }
        };
      },
    });
  }

  /* ================= Interventions ================= */

  async function loadInterventions(wrap) {
    const res = await window.SigpeApi.get('/api/v1/interventions', { page: state.page, per_page: state.perPage });
    const items = res?.data || res?.items || res || [];
    state.total = res?.meta?.total ?? res?.total ?? items.length;
    wrap.innerHTML = `
      <div class="card">
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Référence</th><th>Demande</th><th>Bien</th><th>Type</th><th>Statut</th><th>Coût total</th><th>Début</th><th></th></tr></thead>
          <tbody>
            ${items.length ? items.map(intRow).join('') : `<tr><td colspan="8"><div class="state" style="padding:var(--space-6)"><div class="state__icon">${icon('inbox')}</div><p class="muted">Aucune intervention.</p></div></td></tr>`}
          </tbody>
        </table></div>
        ${pagination({ page: state.page, total: state.total, perPage: state.perPage, onPage: (p) => { state.page = p; loadTab(); } })}
      </div>`;
    wrap.querySelectorAll('[data-detail]').forEach((b) => { b.onclick = () => showIntervention(b.dataset.detail); });
    wrap.querySelectorAll('.page-btn[data-page]').forEach((b) => { b.onclick = () => { state.page = parseInt(b.dataset.page, 10); loadTab(); }; });
  }

  function intRow(i) {
    return `<tr>
      <td class="mono">${esc(i.reference || short(i.id))}</td>
      <td class="mono" style="font-size:var(--text-xs)">${esc(short(i.maintenance_request_id || i.request_id))}</td>
      <td class="mono" style="font-size:var(--text-xs)">${esc(short(i.asset_id))}</td>
      <td>${esc(TYPE_LABELS[i.type] || i.type || '—')}</td>
      <td>${statusBadge(i.status, INT_STATUS)}</td>
      <td class="num">${fmtMoney(i.total_cost ?? i.actual_cost)}</td>
      <td>${fmtDate(i.started_at || i.scheduled_at)}</td>
      <td class="text-right"><button class="btn btn--ghost btn--sm" data-detail="${esc(i.id)}">${icon('eye')} Ouvrir</button></td>
    </tr>`;
  }

  async function showIntervention(id) {
    let d;
    try { d = await window.SigpeApi.get(`/api/v1/interventions/${id}`); }
    catch (err) { toast(err.message, 'error'); return; }
    const i = d?.data || d;

    const actions = [];
    if (['planned', 'scheduled'].includes(i.status)) actions.push(['start', 'Démarrer', 'btn--primary']);
    if (['in_progress', 'started'].includes(i.status)) actions.push(['complete', 'Clôturer', 'btn--primary']);
    if (['in_progress'].includes(i.status)) actions.push(['interrupt', 'Interrompre', 'btn--outline']);
    if (!['completed', 'cancelled'].includes(i.status)) actions.push(['cancel', 'Annuler', 'btn--danger']);

    modal({
      title: `Intervention ${i.reference || short(i.id)}`,
      size: 'lg',
      body: `
        <div class="form-grid mb-4">
          <div class="field"><label>Statut</label><div>${statusBadge(i.status, INT_STATUS)}</div></div>
          <div class="field"><label>Type</label><div>${esc(TYPE_LABELS[i.type] || i.type || '—')}</div></div>
          <div class="field"><label>Bien</label><div class="mono">${esc(i.asset_id || '—')}</div></div>
          <div class="field"><label>Demande associée</label><div class="mono">${esc(i.maintenance_request_id || i.request_id || '—')}</div></div>
          <div class="field"><label>Coût estimé</label><div>${fmtMoney(i.estimated_cost)}</div></div>
          <div class="field"><label>Coût réel</label><div>${fmtMoney(i.total_cost ?? i.actual_cost)}</div></div>
          <div class="field"><label>Début</label><div>${fmtDateTime(i.started_at)}</div></div>
          <div class="field"><label>Fin</label><div>${fmtDateTime(i.completed_at)}</div></div>
        </div>
        ${i.description ? `<div class="field"><label>Description</label><p>${esc(i.description)}</p></div>` : ''}
        ${(i.costs || i.cost_lines || []).length ? `<div class="field"><label>Coûts</label><div class="table-wrap"><table class="table"><thead><tr><th>Catégorie</th><th class="num">Montant</th></tr></thead><tbody>${(i.costs || i.cost_lines).map((c) => `<tr><td>${esc(c.category || c.type || '—')}</td><td class="num">${fmtMoney(c.amount)}</td></tr>`).join('')}</tbody></table></div></div>` : ''}`,
      footer: actions.map(([a, l, c]) => `<button class="btn ${c}" data-act="${a}">${esc(l)}</button>`).join(''),
      onOpen: (bd) => {
        bd.querySelectorAll('[data-act]').forEach((b) => {
          b.onclick = async () => {
            bd.remove();
            try {
              const act = b.dataset.act;
              if (act === 'start') {
                await window.SigpeApi.post(`/api/v1/interventions/${id}/start`, {});
                toast('Intervention démarrée', 'success');
              } else if (act === 'complete') {
                const note = await promptText('Note de clôture (optionnel)', '');
                if (note === null) return;
                await window.SigpeApi.post(`/api/v1/interventions/${id}/complete`, note ? { notes: note } : {});
                toast('Intervention clôturée', 'success');
              } else if (act === 'interrupt') {
                const reason = await promptText('Motif d\'interruption', '');
                if (!reason) return;
                await window.SigpeApi.post(`/api/v1/interventions/${id}/interrupt`, { reason });
                toast('Intervention interrompue', 'warning');
              } else {
                const motif = await promptText('Motif d\'annulation', '');
                if (!motif) return;
                await window.SigpeApi.post(`/api/v1/interventions/${id}/cancel`, { motif });
                toast('Intervention annulée', 'info');
              }
              loadTab();
            } catch (err) { toast(err.message, 'error'); }
          };
        });
      },
    });
  }

  /* ================= Stock pièces ================= */

  async function loadStock(wrap) {
    const res = await window.SigpeApi.get('/api/v1/spare-parts', { page: state.page, per_page: state.perPage });
    const items = res?.data || res?.items || res || [];
    state.total = res?.meta?.total ?? res?.total ?? items.length;
    wrap.innerHTML = `
      <div class="card">
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Référence</th><th>Désignation</th><th>Catégorie</th><th class="num">En stock</th><th class="num">Seuil</th><th>Entrepôt</th></tr></thead>
          <tbody>
            ${items.length ? items.map(stockRow).join('') : `<tr><td colspan="6"><div class="state" style="padding:var(--space-6)"><div class="state__icon">${icon('inbox')}</div><p class="muted">Aucune pièce enregistrée.</p></div></td></tr>`}
          </tbody>
        </table></div>
        ${pagination({ page: state.page, total: state.total, perPage: state.perPage, onPage: (p) => { state.page = p; loadTab(); } })}
      </div>`;
    wrap.querySelectorAll('.page-btn[data-page]').forEach((b) => { b.onclick = () => { state.page = parseInt(b.dataset.page, 10); loadTab(); }; });
  }

  function stockRow(p) {
    const qty = p.quantity_on_hand ?? p.stock_quantity ?? p.quantity ?? '—';
    const threshold = p.reorder_threshold ?? p.min_stock ?? '—';
    const low = typeof qty === 'number' && typeof threshold === 'number' && qty <= threshold;
    return `<tr>
      <td class="mono">${esc(p.reference || p.code || short(p.id))}</td>
      <td><strong>${esc(p.name || p.designation || '—')}</strong></td>
      <td>${esc(p.category || '—')}</td>
      <td class="num">${low ? `<span class="badge badge--danger">${qty}</span>` : qty}</td>
      <td class="num">${threshold}</td>
      <td>${esc(p.warehouse?.name || short(p.warehouse_id))}</td>
    </tr>`;
  }

  const short = (id) => (id ? String(id).slice(0, 8) : '—');

  function promptText(title, placeholder) {
    return new Promise((resolve) => {
      modal({
        title,
        body: `<input class="input" id="pt-input" placeholder="${esc(placeholder)}" autocomplete="off">`,
        footer: `<button class="btn btn--outline" data-close>Annuler</button><button class="btn btn--primary" id="pt-ok">Valider</button>`,
        onOpen: () => {
          const input = document.getElementById('pt-input');
          const ok = document.getElementById('pt-ok');
          ok.onclick = () => {
            const v = input.value.trim();
            document.querySelector('.modal-backdrop')?.remove();
            resolve(v || null);
          };
          input.addEventListener('keydown', (e) => { if (e.key === 'Enter') ok.click(); });
          setTimeout(() => input.focus(), 50);
        },
      });
    });
  }

  window.SigpeViews.maintenance = { render, title: 'Maintenance' };
})();
