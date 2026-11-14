/* SIGPÉ — Vue Mouvements (movement-service) : opérations, gardes, droits partagés */
(function () {
  'use strict';
  const { icon, esc, statusBadge, fmtDate, fmtDateTime, tableSkeleton, pagination, toast, modal, confirmDialog } = window.SigpeUI;

  const TYPE_LABELS = {
    assignment: 'Affectation', internal_transfer: 'Transfert interne',
    interministerial_transfer: 'Transfert interministeriel', loan: 'Prêt',
  };
  const TYPE_BADGE = {
    assignment: '', internal_transfer: 'info', interministerial_transfer: 'gold', loan: 'warning',
  };
  const STATUS_LABELS = {
    draft: 'Brouillon', pending_validation: 'En validation', pending_departure: 'Départ à confirmer',
    pending_reception: 'Réception à confirmer', pending_asset_confirmation: 'Confirmation Asset (TEC-017)',
    active: 'Actif', return_pending: 'Retour en attente', completed: 'Terminé',
    returned: 'Restitué', cancelled: 'Annulé', rejected: 'Rejeté', incident_reported: 'Incident',
  };
  const ROLE_LABELS = {
    administrative_responsible: 'Responsable administratif',
    physical_custodian: 'Gardien physique', authorized_user: 'Utilisateur autorisé',
  };
  const HOLDER_LABELS = { institution: 'Institution', organizational_unit: 'Unité', account: 'Agent' };
  const INCIDENT_LABELS = { loss: 'Perte', theft: 'Vol', damage: 'Dommage', other: 'Autre' };

  const state = {
    tab: 'operations', page: 1, perPage: 15, total: 0,
    fType: '', fStatus: '', fQ: '',
  };

  /* ================= Onglet Opérations ================= */

  async function fetchOperations() {
    const params = { page: state.page, per_page: state.perPage };
    if (state.fType) params.type = state.fType;
    if (state.fStatus) params.status = state.fStatus;
    const res = await window.SigpeApi.get('/api/v1/operations', params);
    const items = res?.data || res?.items || res || [];
    state.total = res?.meta?.total ?? res?.total ?? items.length;
    return items;
  }

  function opFiltersHtml() {
    return `
      <div class="card mb-6"><div class="card__body">
        <div class="form-grid">
          <div class="field"><label>Type</label>
            <select class="select" id="f-type">
              <option value="">Tous</option>
              ${Object.entries(TYPE_LABELS).map(([v, l]) => `<option value="${v}" ${state.fType === v ? 'selected' : ''}>${l}</option>`).join('')}
            </select></div>
          <div class="field"><label>Statut</label>
            <select class="select" id="f-status">
              <option value="">Tous</option>
              ${Object.entries(STATUS_LABELS).map(([v, l]) => `<option value="${v}" ${state.fStatus === v ? 'selected' : ''}>${l}</option>`).join('')}
            </select></div>
          <div class="field" style="align-self:end">
            <button class="btn btn--primary" id="btn-search">${icon('search')} Filtrer</button>
            <button class="btn btn--gold" id="btn-new-op">${icon('plus')} Nouvelle opération</button>
          </div>
        </div>
      </div></div>`;
  }

  async function render() {
    const container = document.getElementById('view');
    container.innerHTML = `
      <div class="page-header">
        <div>
          <div class="page-header__title">Mouvements patrimoniaux</div>
          <div class="page-header__subtitle">Affectations, transferts, prêts et gardes — Movement Service</div>
        </div>
      </div>
      <div class="btn-row mb-4">
        <button class="btn ${state.tab === 'operations' ? 'btn--primary' : 'btn--outline'} btn--sm" data-tab="operations">Opérations</button>
        <button class="btn ${state.tab === 'custody' ? 'btn--primary' : 'btn--outline'} btn--sm" data-tab="custody">Gardes actuelles</button>
        <button class="btn ${state.tab === 'shared' ? 'btn--primary' : 'btn--outline'} btn--sm" data-tab="shared">Droits d'usage partagés</button>
      </div>
      <div id="tab-filters"></div>
      <div id="tab-content">${tableSkeleton(6, 6)}</div>`;

    container.querySelectorAll('[data-tab]').forEach((b) => {
      b.onclick = () => { state.tab = b.dataset.tab; state.page = 1; render(); };
    });

    if (state.tab === 'operations') {
      document.getElementById('tab-filters').innerHTML = opFiltersHtml();
      document.getElementById('btn-search').onclick = () => {
        state.fType = document.getElementById('f-type').value;
        state.fStatus = document.getElementById('f-status').value;
        state.page = 1; loadTab();
      };
      document.getElementById('btn-new-op').onclick = newOperationForm;
      await loadTab();
    } else if (state.tab === 'custody') {
      document.getElementById('tab-filters').innerHTML = `
        <div class="card mb-6"><div class="card__body">
          <div class="form-grid">
            <div class="field"><label>Rôle de garde</label>
              <select class="select" id="f-role">
                <option value="">Tous</option>
                ${Object.entries(ROLE_LABELS).map(([v, l]) => `<option value="${v}">${l}</option>`).join('')}
              </select></div>
            <div class="field" style="align-self:end">
              <button class="btn btn--gold" id="btn-new-custody">${icon('plus')} Nouvelle garde</button>
            </div>
          </div>
        </div></div>`;
      document.getElementById('btn-new-custody').onclick = newCustodyForm;
      await loadTab();
    } else {
      document.getElementById('tab-filters').innerHTML = `
        <div class="card mb-6"><div class="card__body" style="display:flex;justify-content:flex-end">
          <button class="btn btn--gold" id="btn-new-shared">${icon('plus')} Nouveau droit partagé</button>
        </div></div>`;
      document.getElementById('btn-new-shared').onclick = newSharedForm;
      await loadTab();
    }
  }

  async function loadTab() {
    const wrap = document.getElementById('tab-content');
    wrap.innerHTML = tableSkeleton(6, 6);
    try {
      if (state.tab === 'operations') await loadOperations(wrap);
      else if (state.tab === 'custody') await loadCustody(wrap);
      else await loadShared(wrap);
    } catch (err) {
      wrap.innerHTML = `<div class="card"><div class="state"><div class="state__icon">${icon('alert')}</div><p class="muted">${esc(err.message)}</p></div></div>`;
    }
  }

  /* ---------- Opérations : liste ---------- */
  async function loadOperations(wrap) {
    const items = await fetchOperations();
    wrap.innerHTML = `
      <div class="card">
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Type</th><th>Statut</th><th>Biens</th><th>Origine → Destination</th><th>Motif</th><th>Créée le</th><th></th></tr></thead>
          <tbody>
            ${items.length ? items.map(opRow).join('') : `<tr><td colspan="7"><div class="state" style="padding:var(--space-6)"><div class="state__icon">${icon('inbox')}</div><p class="muted">Aucune opération.</p></div></td></tr>`}
          </tbody>
        </table></div>
        ${pagination({ page: state.page, total: state.total, perPage: state.perPage, onPage: (p) => { state.page = p; loadTab(); } })}
      </div>`;
    wrap.querySelectorAll('[data-detail]').forEach((b) => { b.onclick = () => showOperation(b.dataset.detail); });
    wrap.querySelectorAll('.page-btn[data-page]').forEach((b) => { b.onclick = () => { state.page = parseInt(b.dataset.page, 10); loadTab(); }; });
  }

  function opRow(o) {
    const nb = Array.isArray(o.asset_ids) ? o.asset_ids.length : '—';
    return `<tr>
      <td><span class="badge badge--${TYPE_BADGE[o.type] || ''}">${esc(TYPE_LABELS[o.type] || o.type)}</span></td>
      <td>${statusBadge(o.status, STATUS_LABELS)}</td>
      <td class="num">${nb}</td>
      <td class="mono" style="font-size:var(--text-xs)">${esc(short(o.institution_id))} → ${esc(short(o.destination_institution_id))}</td>
      <td>${esc((o.motif || '—').slice(0, 60))}</td>
      <td>${fmtDate(o.created_at)}</td>
      <td class="text-right"><button class="btn btn--ghost btn--sm" data-detail="${esc(o.id)}">${icon('eye')} Ouvrir</button></td>
    </tr>`;
  }
  const short = (id) => (id ? String(id).slice(0, 8) : '—');

  /* ---------- Opérations : détail + actions ---------- */
  async function showOperation(id) {
    let detail;
    try { detail = await window.SigpeApi.get(`/api/v1/operations/${id}`); }
    catch (err) { toast(err.message, 'error'); return; }
    const o = detail?.data || detail;

    const assets = (o.assets || []).map((a) => `<span class="badge" style="margin:2px">${esc(a.patrimonial_code || short(a.asset_id))}</span>`).join('') || '—';
    const steps = (o.steps || []).map((s) => `
      <div class="list-item" style="padding:var(--space-2) var(--space-3)">
        <div class="list-item__icon" style="width:26px;height:26px;${s.status === 'completed' ? 'background:var(--color-success-soft);color:var(--color-success)' : ''}">${icon(s.status === 'completed' ? 'check' : 'info')}</div>
        <div class="list-item__body"><div class="list-item__title" style="font-size:var(--text-sm)">${esc(s.label || s.key)}</div>
        <div class="list-item__meta">${s.completed_at ? fmtDateTime(s.completed_at) : 'en attente'}</div></div>
        ${statusBadge(s.status)}
      </div>`).join('');

    // Boutons d'action selon la machine à états
    const actions = [];
    const can = (s) => o.status === s;
    if (['draft', 'rejected'].includes(o.status) && !o.workflow_instance_id) actions.push(['submit', 'Soumettre', 'btn--primary']);
    if (o.status === 'pending_validation') actions.push(['history', 'Historique', 'btn--outline']);
    if (can('pending_departure')) actions.push(['confirm-departure', 'Confirmer le départ', 'btn--primary']);
    if (can('pending_reception')) actions.push(['confirm-reception', 'Confirmer la réception', 'btn--primary']);
    if (['active', 'pending_reception', 'pending_departure'].includes(o.status)) actions.push(['return', 'Enregistrer le retour/restitution', 'btn--outline']);
    if (!['completed', 'returned', 'cancelled', 'rejected'].includes(o.status)) {
      actions.push(['incident', 'Déclarer un incident', 'btn--outline']);
      actions.push(['cancel', 'Annuler', 'btn--danger']);
    }
    actions.push(['history', 'Historique', 'btn--ghost']);

    modal({
      title: `${TYPE_LABELS[o.type] || o.type} — ${short(o.id)}`,
      size: 'lg',
      body: `
        <div class="form-grid mb-4">
          <div class="field"><label>Statut</label><div>${statusBadge(o.status, STATUS_LABELS)}</div></div>
          <div class="field"><label>Type</label><div>${esc(TYPE_LABELS[o.type] || o.type)}</div></div>
          <div class="field"><label>Institution d'origine</label><div class="mono">${esc(o.institution_id || '—')}</div></div>
          <div class="field"><label>Destination</label><div class="mono">${esc(o.destination_institution_id || '—')}</div></div>
          <div class="field"><label>Motif</label><div>${esc(o.motif || '—')}</div></div>
          <div class="field"><label>Référence workflow</label><div class="mono">${esc(o.workflow_instance_id || '—')}</div></div>
          ${o.loan_start_at ? `<div class="field"><label>Début prêt</label><div>${fmtDateTime(o.loan_start_at)}</div></div>` : ''}
          ${o.loan_end_at ? `<div class="field"><label>Fin prêt</span></label><div>${fmtDateTime(o.loan_end_at)}</div></div>` : ''}
          ${o.convention_ref ? `<div class="field"><label>Convention</label><div>${esc(o.convention_ref)}</div></div>` : ''}
        </div>
        <div class="field"><label>Biens (${(o.asset_ids || []).length})</label><div>${assets}</div></div>
        <div class="field"><label>Étapes</label>
          <div class="card" style="border-radius:var(--radius)">${steps || '<div class="state" style="padding:var(--space-4)"><p class="muted">Aucune étape.</p></div>'}</div>
        </div>`,
      footer: actions.map(([a, l, c]) => `<button class="btn ${c}" data-act="${a}">${esc(l)}</button>`).join(''),
      onOpen: (bd) => {
        bd.querySelectorAll('[data-act]').forEach((b) => {
          b.onclick = async () => {
            bd.remove();
            await opAction(o.id, b.dataset.act);
          };
        });
      },
    });
  }

  /* ---------- Actions d'opération ---------- */
  async function opAction(id, action) {
    try {
      switch (action) {
        case 'submit': {
          const r = await window.SigpeApi.post(`/api/v1/operations/${id}/submit`, {});
          toast('Opération soumise', 'success'); break;
        }
        case 'confirm-departure':
        case 'confirm-reception':
        case 'return': {
          const label = { 'confirm-departure': 'départ', 'confirm-reception': 'réception', return: 'retour' }[action];
          const comment = await promptText(`Confirmer le ${label}`, 'Commentaire (optionnel)');
          if (comment === null) return;
          await window.SigpeApi.post(`/api/v1/operations/${id}/${action}`, { comment });
          toast(`Opération : ${label} confirmé`, 'success'); break;
        }
        case 'incident': {
          const type = await choiceDialog('Type d\'incident', INCIDENT_LABELS);
          if (!type) return;
          const desc = await promptText('Description détaillée de l\'incident (min. 10 caractères)', '');
          if (!desc || desc.length < 10) { toast('Description trop courte', 'error'); return; }
          await window.SigpeApi.post(`/api/v1/operations/${id}/incident`, { incident_type: type, description: desc });
          toast('Incident déclaré', 'warning'); break;
        }
        case 'cancel': {
          const motif = await promptText('Motif d\'annulation (obligatoire)', '');
          if (!motif) return;
          const ok = await confirmDialog('Annuler l\'opération', 'Cette action est définitive pour cette opération.', { confirmLabel: 'Annuler l\'opération', danger: true });
          if (ok !== 'ok') return;
          await window.SigpeApi.post(`/api/v1/operations/${id}/cancel`, { motif });
          toast('Opération annulée', 'info'); break;
        }
        case 'history': {
          const res = await window.SigpeApi.get(`/api/v1/operations/${id}/history`);
          const entries = res?.data || res || [];
          await modal({
            title: 'Historique de l\'opération', size: 'lg',
            body: entries.length ? `<div>${entries.map((h) => `
              <div class="list-item">
                <div class="list-item__icon">${icon('audit')}</div>
                <div class="list-item__body">
                  <div class="list-item__title">${esc(h.action)}</div>
                  <div class="list-item__meta">${esc(h.motif || '')} — ${fmtDateTime(h.occurred_at)}</div>
                </div>
              </div>`).join('')}</div>` : '<p class="muted">Aucun historique.</p>',
          });
          break;
        }
      }
      loadTab();
    } catch (err) { toast(err.message, 'error'); }
  }

  /* ---------- Création d'opération ---------- */
  function newOperationForm() {
    modal({
      title: 'Nouvelle opération de mouvement', size: 'lg',
      body: `
        <div class="form-grid">
          <div class="field"><label>Type d'opération *</label>
            <select class="select" id="op-type">
              ${Object.entries(TYPE_LABELS).map(([v, l]) => `<option value="${v}">${l}</option>`).join('')}
            </select></div>
          <div class="field"><label>IDs des biens * (UUID, séparés par des virgules)</label>
            <input class="input mono" id="op-assets" placeholder="uuid1, uuid2…"></div>
          <div class="field"><label>Institution de destination (UUID)</label>
            <input class="input mono" id="op-dest"></div>
          <div class="field"><label>Unité d'origine (UUID)</label>
            <input class="input mono" id="op-unit-o"></div>
          <div class="field"><label>Unité de destination (UUID)</label>
            <input class="input mono" id="op-unit-d"></div>
          <div class="field"><label>Référence externe (workflow/acquisition, UUID)</label>
            <input class="input mono" id="op-ref"></div>
          <div class="field span-2" id="op-loan-fields" style="display:none">
            <div class="form-grid">
              <div class="field"><label>Début du prêt</label><input class="input" type="datetime-local" id="op-loan-start"></div>
              <div class="field"><label>Fin du prêt</label><input class="input" type="datetime-local" id="op-loan-end"></div>
              <div class="field"><label>Compte gardien (UUID)</label><input class="input mono" id="op-custodian"></div>
              <div class="field"><label>Référence convention</label><input class="input" id="op-convention"></div>
            </div>
          </div>
          <div class="field span-2"><label>Motif</label>
            <textarea class="textarea" id="op-motif" rows="2" maxlength="1000"></textarea></div>
          <div class="field"><label class="check"><input type="checkbox" id="op-workflow"> Soumettre à un circuit Workflow</label></div>
        </div>`,
      footer: `<button class="btn btn--outline" data-close>Annuler</button><button class="btn btn--primary" id="op-submit">Créer le brouillon</button>`,
      onOpen: (bd) => {
      const typeEl = bd.querySelector('#op-type');
      const toggleLoan = () => {
        document.getElementById('op-loan-fields').style.display = typeEl.value === 'loan' ? 'block' : 'none';
      };
      typeEl.onchange = toggleLoan; toggleLoan();
      document.getElementById('op-submit').onclick = async () => {
        const assetIds = document.getElementById('op-assets').value.split(',').map((s) => s.trim()).filter(Boolean);
        if (!assetIds.length) { toast('Au moins un ID de bien est requis', 'error'); return; }
        const body = {
          type: typeEl.value,
          asset_ids: assetIds,
          motif: document.getElementById('op-motif').value || undefined,
        };
        const dest = document.getElementById('op-dest').value.trim();
        if (dest) body.destination_institution_id = dest;
        const uo = document.getElementById('op-unit-o').value.trim(); if (uo) body.origin_unit_id = uo;
        const ud = document.getElementById('op-unit-d').value.trim(); if (ud) body.destination_unit_id = ud;
        const ref = document.getElementById('op-ref').value.trim(); if (ref) body.operation_ref = ref;
        if (document.getElementById('op-workflow').checked) body.require_workflow = true;
        if (typeEl.value === 'loan') {
          const ls = document.getElementById('op-loan-start').value; if (ls) body.loan_start_at = new Date(ls).toISOString();
          const le = document.getElementById('op-loan-end').value; if (le) body.loan_end_at = new Date(le).toISOString();
          const cust = document.getElementById('op-custodian').value.trim(); if (cust) body.custodian_account_id = cust;
          const conv = document.getElementById('op-convention').value.trim(); if (conv) body.convention_ref = conv;
        }
        try {
          await window.SigpeApi.post('/api/v1/operations', body);
          document.querySelector('.modal-backdrop')?.remove();
          toast('Opération créée (brouillon)', 'success');
          state.page = 1; loadTab();
        } catch (err) {
          toast(err.message, 'error');
        }
      };
      }});
  }

  /* ================= Onglet Gardes ================= */

  async function loadCustody(wrap) {
    const res = await window.SigpeApi.get('/api/v1/custody', { page: state.page, per_page: state.perPage });
    const items = res?.data || res?.items || res || [];
    state.total = res?.meta?.total ?? res?.total ?? items.length;
    wrap.innerHTML = `
      <div class="card">
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Bien</th><th>Détenteur</th><th>Rôle</th><th>Institution</th><th>Depuis</th><th>Jusqu'au</th><th></th></tr></thead>
          <tbody>
            ${items.length ? items.map(custodyRow).join('') : `<tr><td colspan="7"><div class="state" style="padding:var(--space-6)"><div class="state__icon">${icon('inbox')}</div><p class="muted">Aucune garde courante.</p></div></td></tr>`}
          </tbody>
        </table></div>
        ${pagination({ page: state.page, total: state.total, perPage: state.perPage, onPage: (p) => { state.page = p; loadTab(); } })}
      </div>`;
    wrap.querySelectorAll('[data-close-custody]').forEach((b) => { b.onclick = () => closeCustody(b.dataset.closeCustody); });
    wrap.querySelectorAll('.page-btn[data-page]').forEach((b) => { b.onclick = () => { state.page = parseInt(b.dataset.page, 10); loadTab(); }; });
  }

  function custodyRow(c) {
    return `<tr>
      <td class="mono">${esc(short(c.asset_id))}</td>
      <td><span class="badge">${esc(HOLDER_LABELS[c.holder_type] || c.holder_type)}</span> <span class="mono" style="font-size:var(--text-xs)">${esc(short(c.holder_ref))}</span></td>
      <td>${esc(ROLE_LABELS[c.custody_role] || c.custody_role)}</td>
      <td class="mono" style="font-size:var(--text-xs)">${esc(short(c.institution_id))}</td>
      <td>${fmtDate(c.valid_from)}</td>
      <td>${c.valid_to ? fmtDate(c.valid_to) : '<span class="badge badge--success">courante</span>'}</td>
      <td class="text-right">${!c.valid_to ? `<button class="btn btn--ghost btn--sm" data-close-custody="${esc(c.id)}">Clôturer</button>` : ''}</td>
    </tr>`;
  }

  function newCustodyForm() {
    modal({
      title: 'Nouvelle garde (affectation directe)',
      body: `
        <div class="form-grid">
          <div class="field"><label>Bien (UUID asset) *</label><input class="input mono" id="c-asset"></div>
          <div class="field"><label>Institution (UUID)</label><input class="input mono" id="c-inst"></div>
          <div class="field"><label>Type de détenteur *</label>
            <select class="select" id="c-holder-type">
              ${Object.entries(HOLDER_LABELS).map(([v, l]) => `<option value="${v}">${l}</option>`).join('')}
            </select></div>
          <div class="field"><label>Référence détenteur (UUID) *</label><input class="input mono" id="c-holder-ref"></div>
          <div class="field"><label>Rôle *</label>
            <select class="select" id="c-role">
              ${Object.entries(ROLE_LABELS).map(([v, l]) => `<option value="${v}">${l}</option>`).join('')}
            </select></div>
          <div class="field"><label>Valide à partir de</label><input class="input" type="datetime-local" id="c-from"></div>
          <div class="field span-2"><label>Motif</label><textarea class="textarea" id="c-motif" rows="2" maxlength="500"></textarea></div>
        </div>`,
      footer: `<button class="btn btn--outline" data-close>Annuler</button><button class="btn btn--primary" id="c-submit">Créer la garde</button>`,
      onOpen: () => {
      document.getElementById('c-submit').onclick = async () => {
        const body = {
          asset_id: document.getElementById('c-asset').value.trim(),
          holder_type: document.getElementById('c-holder-type').value,
          holder_ref: document.getElementById('c-holder-ref').value.trim(),
          custody_role: document.getElementById('c-role').value,
        };
        const inst = document.getElementById('c-inst').value.trim(); if (inst) body.institution_id = inst;
        const from = document.getElementById('c-from').value; if (from) body.valid_from = new Date(from).toISOString();
        const motif = document.getElementById('c-motif').value.trim(); if (motif) body.motif = motif;
        if (!body.asset_id || !body.holder_ref) { toast('Bien et référence détenteur requis', 'error'); return; }
        try {
          await window.SigpeApi.post('/api/v1/custody', body);
          document.querySelector('.modal-backdrop')?.remove();
          toast('Garde créée', 'success'); loadTab();
        } catch (err) { toast(err.message, 'error'); }
      };
      }});
  }

  async function closeCustody(id) {
    const motif = await promptText('Motif de clôture de la garde (obligatoire)', '');
    if (!motif) return;
    const ok = await confirmDialog('Clôturer la garde', 'La garde sera marquée comme terminée à la date indiquée.', { confirmLabel: 'Clôturer' });
    if (ok !== 'ok') return;
    try {
      await window.SigpeApi.post(`/api/v1/custody/${id}/close`, { motif });
      toast('Garde clôturée', 'success'); loadTab();
    } catch (err) { toast(err.message, 'error'); }
  }

  /* ================= Onglet Droits partagés ================= */

  async function loadShared(wrap) {
    const res = await window.SigpeApi.get('/api/v1/shared-rights', { page: state.page, per_page: state.perPage });
    const items = res?.data || res?.items || res || [];
    state.total = res?.meta?.total ?? res?.total ?? items.length;
    wrap.innerHTML = `
      <div class="card">
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Bien</th><th>Responsable → Bénéficiaire</th><th>Droits d'usage</th><th>Statut</th><th>Valide</th><th></th></tr></thead>
          <tbody>
            ${items.length ? items.map(sharedRow).join('') : `<tr><td colspan="6"><div class="state" style="padding:var(--space-6)"><div class="state__icon">${icon('inbox')}</div><p class="muted">Aucun droit partagé.</p></div></td></tr>`}
          </tbody>
        </table></div>
        ${pagination({ page: state.page, total: state.total, perPage: state.perPage, onPage: (p) => { state.page = p; loadTab(); } })}
      </div>`;
    wrap.querySelectorAll('[data-shared-act]').forEach((b) => { b.onclick = () => sharedAction(b.dataset.sharedAct, b.dataset.id); });
    wrap.querySelectorAll('.page-btn[data-page]').forEach((b) => { b.onclick = () => { state.page = parseInt(b.dataset.page, 10); loadTab(); }; });
  }

  function sharedRow(s) {
    const actions = s.status === 'active'
      ? `<button class="btn btn--ghost btn--sm" data-shared-act="suspend" data-id="${esc(s.id)}">Suspendre</button>
         <button class="btn btn--ghost btn--sm" data-shared-act="terminate" data-id="${esc(s.id)}">Terminer</button>`
      : s.status === 'suspended'
        ? `<button class="btn btn--ghost btn--sm" data-shared-act="terminate" data-id="${esc(s.id)}">Terminer</button>` : '';
    return `<tr>
      <td class="mono">${esc(short(s.asset_id))}</td>
      <td class="mono" style="font-size:var(--text-xs)">${esc(short(s.responsible_institution_id))} → ${esc(short(s.beneficiary_institution_id))}</td>
      <td>${esc((s.usage_scope || '—').slice(0, 80))}</td>
      <td>${statusBadge(s.status, { active: 'Actif', suspended: 'Suspendu', terminated: 'Terminé' })}</td>
      <td>${fmtDate(s.valid_from)} → ${s.valid_to ? fmtDate(s.valid_to) : '∞'}</td>
      <td class="text-right">${actions}</td>
    </tr>`;
  }

  function newSharedForm() {
    modal({
      title: 'Nouveau droit d\'usage partagé',
      body: `
        <div class="form-grid">
          <div class="field"><label>Bien (UUID) *</label><input class="input mono" id="s-asset"></div>
          <div class="field"><label>Institution responsable (UUID) *</label><input class="input mono" id="s-resp"></div>
          <div class="field"><label>Institution bénéficiaire (UUID) *</label><input class="input mono" id="s-benef"></div>
          <div class="field"><label>Référence convention</label><input class="input" id="s-conv"></div>
          <div class="field span-2"><label>Droits d'usage accordés *</label><textarea class="textarea" id="s-scope" rows="2" maxlength="500" placeholder="Ex : usage du véhicule pour missions officielles…"></textarea></div>
          <div class="field span-2"><label>Répartition des responsabilités et coûts</label><textarea class="textarea" id="s-resp2" rows="2" maxlength="2000"></textarea></div>
        </div>`,
      footer: `<button class="btn btn--outline" data-close>Annuler</button><button class="btn btn--primary" id="s-submit">Créer</button>`,
      onOpen: () => {
      document.getElementById('s-submit').onclick = async () => {
        const body = {
          asset_id: document.getElementById('s-asset').value.trim(),
          responsible_institution_id: document.getElementById('s-resp').value.trim(),
          beneficiary_institution_id: document.getElementById('s-benef').value.trim(),
          usage_scope: document.getElementById('s-scope').value.trim(),
        };
        const conv = document.getElementById('s-conv').value.trim(); if (conv) body.convention_ref = conv;
        const resp = document.getElementById('s-resp2').value.trim(); if (resp) body.responsibilities = resp;
        if (!body.asset_id || !body.responsible_institution_id || !body.beneficiary_institution_id || body.usage_scope.length < 3) {
          toast('Champs obligatoires manquants', 'error'); return;
        }
        try {
          await window.SigpeApi.post('/api/v1/shared-rights', body);
          document.querySelector('.modal-backdrop')?.remove();
          toast('Droit partagé créé', 'success'); loadTab();
        } catch (err) { toast(err.message, 'error'); }
      };
      }});
  }

  async function sharedAction(action, id) {
    try {
      if (action === 'suspend') {
        const ok = await confirmDialog('Suspendre le droit', 'Le bénéficiaire perd temporairement l\'usage du bien.');
        if (ok !== 'ok') return;
        await window.SigpeApi.post(`/api/v1/shared-rights/${id}/suspend`, {});
        toast('Droit suspendu', 'warning');
      } else if (action === 'terminate') {
        const ok = await confirmDialog('Terminer le droit', 'Résiliation définitive du droit d\'usage partagé.', { confirmLabel: 'Terminer', danger: true });
        if (ok !== 'ok') return;
        await window.SigpeApi.post(`/api/v1/shared-rights/${id}/terminate`, {});
        toast('Droit terminé', 'info');
      }
      loadTab();
    } catch (err) { toast(err.message, 'error'); }
  }

  /* ---------- Helpers UI ---------- */
  function promptText(title, placeholder) {
    return new Promise((resolve) => {
      modal({
        title,
        body: `<input class="input" id="pt-input" placeholder="${esc(placeholder)}" autocomplete="off">`,
        footer: `<button class="btn btn--outline" data-close>Annuler</button><button class="btn btn--primary" id="pt-ok">Valider</button>`,
        onOpen: () => {
        const input = document.getElementById('pt-input');
        const ok = document.getElementById('pt-ok');
        if (!input || !ok) { resolve(null); return; }
        ok.onclick = () => {
          const v = input.value.trim();
          document.querySelector('.modal-backdrop')?.remove();
          resolve(v || null);
        };
        input.addEventListener('keydown', (e) => { if (e.key === 'Enter') ok.click(); });
        setTimeout(() => input.focus(), 50);
      }});
    });
  }

  function choiceDialog(title, options) {
    return new Promise((resolve) => {
      modal({
        title,
        body: `<div class="btn-row" style="flex-direction:column;align-items:stretch">${Object.entries(options).map(([v, l]) =>
          `<button class="btn btn--outline" data-choice="${v}">${esc(l)}</button>`).join('')}</div>`,
        footer: `<button class="btn btn--outline" data-close>Annuler</button>`,
        onOpen: () => {
        document.querySelectorAll('[data-choice]').forEach((b) => {
          b.onclick = () => {
            const v = b.dataset.choice;
            document.querySelector('.modal-backdrop')?.remove();
            resolve(v);
          };
        });
      }});
    });
  }

  window.SigpeViews.movements = { render, title: 'Mouvements patrimoniaux' };
})();
