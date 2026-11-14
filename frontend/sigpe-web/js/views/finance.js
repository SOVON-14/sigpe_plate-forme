/* SIGPÉ — Vue Finance (finance-service) : valorisations, coûts, sorties définitives */
(function () {
  'use strict';
  const { icon, esc, statusBadge, fmtDate, fmtDateTime, fmtMoney, tableSkeleton, pagination, toast, modal, confirmDialog } = window.SigpeUI;

  const VAL_TYPE = { acquisition: 'Acquisition', current: 'Actuelle', net_book: 'Nette comptable', replacement: 'Remplacement' };
  const DISP_TYPE = { reform: 'Réforme', sale: 'Vente', destruction: 'Destruction', loss: 'Perte', theft: 'Vol', donation: 'Don/cession', other: 'Autre' };
  const DISP_STATUS = {
    draft: 'Brouillon', pending_validation: 'En validation', under_investigation: 'Enquête',
    approved: 'Approuvée', executed: 'Exécutée', closed: 'Clôturée', rejected: 'Rejetée', cancelled: 'Annulée',
  };

  const state = { tab: 'valuations', page: 1, perPage: 15, total: 0, fType: '', fStatus: '' };

  async function render() {
    const container = document.getElementById('view');
    container.innerHTML = `
      <div class="page-header">
        <div>
          <div class="page-header__title">Finance patrimoniale</div>
          <div class="page-header__subtitle">Valorisations, amortissements, coûts et sorties définitives — Finance Service</div>
        </div>
      </div>
      <div class="btn-row mb-4">
        <button class="btn ${state.tab === 'valuations' ? 'btn--primary' : 'btn--outline'} btn--sm" data-tab="valuations">Valorisations</button>
        <button class="btn ${state.tab === 'costs' ? 'btn--primary' : 'btn--outline'} btn--sm" data-tab="costs">Coûts</button>
        <button class="btn ${state.tab === 'disposals' ? 'btn--primary' : 'btn--outline'} btn--sm" data-tab="disposals">Sorties définitives</button>
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
      if (state.tab === 'valuations') await loadValuations(wrap);
      else if (state.tab === 'costs') await loadCosts(wrap);
      else await loadDisposals(wrap);
    } catch (err) {
      wrap.innerHTML = `<div class="card"><div class="state"><div class="state__icon">${icon('alert')}</div><p class="muted">${esc(err.message)}</p></div></div>`;
    }
  }

  /* ================= Valorisations ================= */

  async function loadValuations(wrap) {
    document.getElementById('tab-filters').innerHTML = `
      <div class="card mb-6"><div class="card__body" style="display:flex;justify-content:flex-end">
        <button class="btn btn--gold" id="btn-new">${icon('plus')} Nouvelle valorisation</button>
      </div></div>`;
    document.getElementById('btn-new').onclick = newValuationForm;

    const params = { page: state.page, per_page: state.perPage };
    const res = await window.SigpeApi.get('/api/v1/asset-valuations', params);
    const items = res?.data || res?.items || res || [];
    state.total = res?.meta?.total ?? res?.total ?? items.length;
    wrap.innerHTML = `
      <div class="card">
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Bien</th><th>Type</th><th>Date</th><th class="num">Montant</th><th>Dépréciation</th><th>Enregistrée le</th></tr></thead>
          <tbody>
            ${items.length ? items.map(valRow).join('') : `<tr><td colspan="6"><div class="state" style="padding:var(--space-6)"><div class="state__icon">${icon('inbox')}</div><p class="muted">Aucune valorisation.</p></div></td></tr>`}
          </tbody>
        </table></div>
        ${pagination({ page: state.page, total: state.total, perPage: state.perPage, onPage: (p) => { state.page = p; loadTab(); } })}
      </div>`;
    wrap.querySelectorAll('.page-btn[data-page]').forEach((b) => { b.onclick = () => { state.page = parseInt(b.dataset.page, 10); loadTab(); }; });
  }

  function valRow(v) {
    const amount = v.is_determinate === false || v.amount == null ? '<span class="badge">indéterminée</span>' : fmtMoney(v.amount, v.currency);
    const depr = v.depreciation_method ? `${v.depreciation_method}${v.depreciation_rate_annual ? ` ${v.depreciation_rate_annual}%` : ''}` : '—';
    return `<tr>
      <td class="mono" style="font-size:var(--text-xs)">${esc(short(v.asset_id))}</td>
      <td>${esc(VAL_TYPE[v.valuation_type] || v.valuation_type)}</td>
      <td>${fmtDate(v.valuation_date)}</td>
      <td class="num">${amount}</td>
      <td>${esc(depr)}</td>
      <td>${fmtDate(v.created_at)}</td>
    </tr>`;
  }

  function newValuationForm() {
    modal({
      title: 'Nouvelle valorisation', size: 'lg',
      body: `
        <div class="form-grid">
          <div class="field"><label>Bien (UUID asset) *</label><input class="input mono" id="v-asset"></div>
          <div class="field"><label>Type *</label>
            <select class="select" id="v-type">${Object.entries(VAL_TYPE).map(([k, l]) => `<option value="${k}">${l}</option>`).join('')}</select></div>
          <div class="field"><label>Date de valorisation *</label><input class="input" type="date" id="v-date"></div>
          <div class="field"><label>Montant (vide = indéterminé)</label><input class="input" type="number" min="0" id="v-amount"></div>
          <div class="field"><label>Devise</label><input class="input" id="v-currency" value="XOF" maxlength="3"></div>
          <div class="field"><label>Méthode d'amortissement</label>
            <select class="select" id="v-depr"><option value="">—</option><option value="linear">Linéaire</option><option value="declining_balance">Dégressif</option><option value="none">Aucun</option></select></div>
          <div class="field"><label>Taux annuel (%)</label><input class="input" type="number" min="0" max="100" step="0.01" id="v-rate"></div>
          <div class="field"><label>Durée de vie (mois)</label><input class="input" type="number" min="1" id="v-life"></div>
          <div class="field span-2"><label>Notes</label><textarea class="textarea" id="v-notes" rows="2" maxlength="2000"></textarea></div>
        </div>`,
      footer: `<button class="btn btn--outline" data-close>Annuler</button><button class="btn btn--primary" id="v-submit">Enregistrer</button>`,
      onOpen: (bd) => {
        bd.querySelector('#v-submit').onclick = async () => {
          const body = {
            asset_id: bd.querySelector('#v-asset').value.trim(),
            valuation_type: bd.querySelector('#v-type').value,
            valuation_date: bd.querySelector('#v-date').value,
          };
          const amount = bd.querySelector('#v-amount').value;
          if (amount) body.amount = parseFloat(amount);
          body.currency = bd.querySelector('#v-currency').value.trim() || 'XOF';
          const depr = bd.querySelector('#v-depr').value; if (depr) body.depreciation_method = depr;
          const rate = bd.querySelector('#v-rate').value; if (rate) body.depreciation_rate_annual = parseFloat(rate);
          const life = bd.querySelector('#v-life').value; if (life) body.useful_life_months = parseInt(life, 10);
          const notes = bd.querySelector('#v-notes').value.trim(); if (notes) body.notes = notes;
          if (!body.asset_id || !body.valuation_date) { toast('Bien et date requis', 'error'); return; }
          try {
            await window.SigpeApi.post('/api/v1/asset-valuations', body);
            bd.remove();
            toast('Valorisation enregistrée', 'success');
            state.page = 1; loadTab();
          } catch (err) { toast(err.message, 'error'); }
        };
      },
    });
  }

  /* ================= Coûts ================= */

  async function loadCosts(wrap) {
    document.getElementById('tab-filters').innerHTML = `
      <div class="card mb-6"><div class="card__body" style="display:flex;justify-content:flex-end">
        <button class="btn btn--gold" id="btn-new">${icon('plus')} Nouveau coût</button>
      </div></div>`;
    document.getElementById('btn-new').onclick = newCostForm;

    const res = await window.SigpeApi.get('/api/v1/asset-costs', { page: state.page, per_page: state.perPage });
    const items = res?.data || res?.items || res || [];
    state.total = res?.meta?.total ?? res?.total ?? items.length;
    wrap.innerHTML = `
      <div class="card">
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Bien</th><th>Catégorie</th><th>Source</th><th class="num">Montant</th><th>Date</th><th>Description</th></tr></thead>
          <tbody>
            ${items.length ? items.map(costRow).join('') : `<tr><td colspan="6"><div class="state" style="padding:var(--space-6)"><div class="state__icon">${icon('inbox')}</div><p class="muted">Aucun coût.</p></div></td></tr>`}
          </tbody>
        </table></div>
        ${pagination({ page: state.page, total: state.total, perPage: state.perPage, onPage: (p) => { state.page = p; loadTab(); } })}
      </div>`;
    wrap.querySelectorAll('.page-btn[data-page]').forEach((b) => { b.onclick = () => { state.page = parseInt(b.dataset.page, 10); loadTab(); }; });
  }

  function costRow(c) {
    return `<tr>
      <td class="mono" style="font-size:var(--text-xs)">${esc(short(c.asset_id))}</td>
      <td>${esc(c.category || '—')}</td>
      <td><span class="badge">${esc(c.source_type || '—')}</span></td>
      <td class="num">${fmtMoney(c.amount, c.currency)}</td>
      <td>${fmtDate(c.incurred_at)}</td>
      <td>${esc((c.description || '—').slice(0, 60))}</td>
    </tr>`;
  }

  function newCostForm() {
    modal({
      title: 'Nouveau coût patrimonial',
      body: `
        <div class="form-grid">
          <div class="field"><label>Bien (UUID asset) *</label><input class="input mono" id="c-asset"></div>
          <div class="field"><label>Catégorie *</label><input class="input" id="c-category" placeholder="maintenance, acquisition, transport…"></div>
          <div class="field"><label>Montant *</label><input class="input" type="number" min="0" step="0.01" id="c-amount"></div>
          <div class="field"><label>Devise</label><input class="input" id="c-currency" value="XOF" maxlength="3"></div>
          <div class="field"><label>Date *</label><input class="input" type="date" id="c-date"></div>
          <div class="field span-2"><label>Description *</label><textarea class="textarea" id="c-desc" rows="2" maxlength="2000"></textarea></div>
        </div>`,
      footer: `<button class="btn btn--outline" data-close>Annuler</button><button class="btn btn--primary" id="c-submit">Enregistrer</button>`,
      onOpen: (bd) => {
        bd.querySelector('#c-submit').onclick = async () => {
          const body = {
            asset_id: bd.querySelector('#c-asset').value.trim(),
            category: bd.querySelector('#c-category').value.trim(),
            amount: parseFloat(bd.querySelector('#c-amount').value),
            currency: bd.querySelector('#c-currency').value.trim() || 'XOF',
            incurred_at: bd.querySelector('#c-date').value,
            description: bd.querySelector('#c-desc').value.trim(),
          };
          if (!body.asset_id || !body.category || !body.amount || !body.incurred_at || body.description.length < 3) {
            toast('Champs obligatoires manquants', 'error'); return;
          }
          try {
            await window.SigpeApi.post('/api/v1/asset-costs', body);
            bd.remove();
            toast('Coût enregistré', 'success');
            state.page = 1; loadTab();
          } catch (err) { toast(err.message, 'error'); }
        };
      },
    });
  }

  /* ================= Sorties définitives ================= */

  async function loadDisposals(wrap) {
    document.getElementById('tab-filters').innerHTML = `
      <div class="card mb-6"><div class="card__body">
        <div class="form-grid">
          <div class="field"><label>Type</label>
            <select class="select" id="f-dtype"><option value="">Tous</option>
              ${Object.entries(DISP_TYPE).map(([v, l]) => `<option value="${v}">${l}</option>`).join('')}
            </select></div>
          <div class="field"><label>Statut</label>
            <select class="select" id="f-dstatus"><option value="">Tous</option>
              ${Object.entries(DISP_STATUS).map(([v, l]) => `<option value="${v}">${l}</option>`).join('')}
            </select></div>
          <div class="field" style="align-self:end">
            <button class="btn btn--primary" id="btn-search">${icon('search')} Filtrer</button>
            <button class="btn btn--gold" id="btn-new">${icon('plus')} Nouveau dossier</button>
          </div>
        </div>
      </div></div>`;
    document.getElementById('btn-search').onclick = () => {
      state.fType = document.getElementById('f-dtype').value;
      state.fStatus = document.getElementById('f-dstatus').value;
      state.page = 1; loadTab();
    };
    document.getElementById('btn-new').onclick = newDisposalForm;

    const params = { page: state.page, per_page: state.perPage };
    if (state.fType) params.disposal_type = state.fType;
    if (state.fStatus) params.status = state.fStatus;
    const res = await window.SigpeApi.get('/api/v1/disposals', params);
    const items = res?.data || res?.items || res || [];
    state.total = res?.meta?.total ?? res?.total ?? items.length;
    wrap.innerHTML = `
      <div class="card">
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Bien</th><th>Type</th><th>Statut</th><th>Compétence</th><th class="num">Valeur estimée</th><th>Créé le</th><th></th></tr></thead>
          <tbody>
            ${items.length ? items.map(dispRow).join('') : `<tr><td colspan="7"><div class="state" style="padding:var(--space-6)"><div class="state__icon">${icon('inbox')}</div><p class="muted">Aucun dossier de sortie.</p></div></td></tr>`}
          </tbody>
        </table></div>
        ${pagination({ page: state.page, total: state.total, perPage: state.perPage, onPage: (p) => { state.page = p; loadTab(); } })}
      </div>`;
    wrap.querySelectorAll('[data-detail]').forEach((b) => { b.onclick = () => showDisposal(b.dataset.detail); });
    wrap.querySelectorAll('.page-btn[data-page]').forEach((b) => { b.onclick = () => { state.page = parseInt(b.dataset.page, 10); loadTab(); }; });
  }

  function dispRow(d) {
    return `<tr>
      <td class="mono" style="font-size:var(--text-xs)">${esc(short(d.asset_id))}</td>
      <td>${esc(DISP_TYPE[d.disposal_type] || d.disposal_type)}</td>
      <td>${statusBadge(d.status, DISP_STATUS)}</td>
      <td><span class="badge badge--${d.required_competence_level === 'national' ? 'danger' : d.required_competence_level === 'ministerial' ? 'warning' : 'info'}">${esc(d.required_competence_level || '—')}</span></td>
      <td class="num">${d.is_value_determinate === false || d.estimated_value == null ? '<span class="badge">indét.</span>' : fmtMoney(d.estimated_value, d.currency)}</td>
      <td>${fmtDate(d.created_at)}</td>
      <td class="text-right"><button class="btn btn--ghost btn--sm" data-detail="${esc(d.id)}">${icon('eye')} Ouvrir</button></td>
    </tr>`;
  }

  async function showDisposal(id) {
    let d;
    try { d = await window.SigpeApi.get(`/api/v1/disposals/${id}`); }
    catch (err) { toast(err.message, 'error'); return; }
    const x = d?.data || d;

    const TRANSITIONS = {
      draft: [['submit', 'Soumettre en validation', 'btn--primary']],
      pending_validation: [['cancel', 'Annuler', 'btn--danger']],
      under_investigation: [['resolve-investigation', 'Clôturer l\'enquête', 'btn--primary'], ['cancel', 'Annuler', 'btn--danger']],
      approved: [['execute', 'Exécuter la sortie', 'btn--primary'], ['cancel', 'Annuler', 'btn--danger']],
      executed: [['close', 'Clôturer le dossier', 'btn--primary']],
    };
    const actions = (TRANSITIONS[x.status] || []).map(([a, l, c]) => [a, l, c]);

    modal({
      title: `Sortie ${DISP_TYPE[x.disposal_type] || ''} — ${short(x.id)}`,
      size: 'lg',
      body: `
        <div class="alert alert--warning"><strong>Règle métier :</strong> une perte ou un vol ne retire jamais automatiquement le bien du patrimoine — l'enquête contradictoire reste ouverte jusqu'à décision habilitée.</div>
        <div class="form-grid mb-4">
          <div class="field"><label>Statut</label><div>${statusBadge(x.status, DISP_STATUS)}</div></div>
          <div class="field"><label>Type</label><div>${esc(DISP_TYPE[x.disposal_type] || x.disposal_type)}</div></div>
          <div class="field"><label>Bien</label><div class="mono">${esc(x.asset_id || '—')}</div></div>
          <div class="field"><label>Niveau de compétence requis</label><div><span class="badge badge--${x.required_competence_level === 'national' ? 'danger' : 'warning'}">${esc(x.required_competence_level || '—')}</span></div></div>
          <div class="field"><label>Valeur estimée</label><div>${x.is_value_determinate === false || x.estimated_value == null ? 'indéterminée' : fmtMoney(x.estimated_value, x.currency)}</div></div>
          <div class="field"><label>Valeur nette comptable</label><div>${fmtMoney(x.net_book_value, x.currency)}</div></div>
          <div class="field"><label>Exécutée le</label><div>${fmtDateTime(x.executed_at)}</div></div>
          <div class="field"><label>Clôturée le</label><div>${fmtDateTime(x.closed_at)}</div></div>
        </div>
        <div class="field"><label>Motif</label><p>${esc(x.reason || '—')}</p></div>
        ${x.incident_reported_at ? `<div class="field"><label>Incident déclaré le</label><div>${fmtDateTime(x.incident_reported_at)}</div></div>` : ''}`,
      footer: actions.map(([a, l, c]) => `<button class="btn ${c}" data-act="${a}">${esc(l)}</button>`).join(''),
      onOpen: (bd) => {
        bd.querySelectorAll('[data-act]').forEach((b) => {
          b.onclick = async () => {
            bd.remove();
            try {
              const act = b.dataset.act;
              const confirmMap = {
                execute: 'Exécuter définitivement la sortie ? Cette action sort le bien du patrimoine actif.',
                close: 'Clôturer définitivement ce dossier de sortie ?',
                'resolve-investigation': 'Clôturer l\'enquête ? Le dossier passera en instruction.',
              };
              if (confirmMap[act]) {
                const ok = await confirmDialog('Confirmation', confirmMap[act], { confirmLabel: 'Confirmer', danger: act === 'execute' });
                if (ok !== 'ok') return;
              }
              const body = {};
              if (act === 'cancel') { body.reason = await promptText('Motif d\'annulation', ''); if (!body.reason) return; }
              await window.SigpeApi.post(`/api/v1/disposals/${id}/${act}`, body);
              toast('Dossier mis à jour', 'success');
              loadTab();
            } catch (err) { toast(err.message, 'error'); }
          };
        });
      },
    });
  }

  function newDisposalForm() {
    modal({
      title: 'Nouveau dossier de sortie définitive',
      body: `
        <div class="alert alert--warning" style="margin-bottom:var(--space-4)">Une approbation est obligatoire avant exécution. Perte/vol : le bien n'est PAS retiré automatiquement — enquête requise.</div>
        <div class="form-grid">
          <div class="field"><label>Bien (UUID asset) *</label><input class="input mono" id="dp-asset"></div>
          <div class="field"><label>Type de sortie *</label>
            <select class="select" id="dp-type">${Object.entries(DISP_TYPE).map(([v, l]) => `<option value="${v}">${l}</option>`).join('')}</select></div>
          <div class="field"><label>Valeur estimée (vide = indéterminée)</label><input class="input" type="number" min="0" id="dp-value"></div>
          <div class="field"><label>Devise</label><input class="input" id="dp-currency" value="XOF" maxlength="3"></div>
          <div class="field span-2"><label>Motif détaillé * (min. 10 caractères)</label><textarea class="textarea" id="dp-reason" rows="3" maxlength="4000"></textarea></div>
          <div class="field"><label>Date de déclaration d'incident (perte/vol)</label><input class="input" type="datetime-local" id="dp-incident"></div>
        </div>`,
      footer: `<button class="btn btn--outline" data-close>Annuler</button><button class="btn btn--primary" id="dp-submit">Créer le brouillon</button>`,
      onOpen: (bd) => {
        bd.querySelector('#dp-type').onchange = (e) => {
          bd.querySelector('#dp-incident').closest('.field').style.display = ['loss', 'theft'].includes(e.target.value) ? 'block' : 'none';
        };
        bd.querySelector('#dp-submit').onclick = async () => {
          const body = {
            asset_id: bd.querySelector('#dp-asset').value.trim(),
            disposal_type: bd.querySelector('#dp-type').value,
            reason: bd.querySelector('#dp-reason').value.trim(),
          };
          const val = bd.querySelector('#dp-value').value;
          if (val) body.estimated_value = parseFloat(val);
          body.currency = bd.querySelector('#dp-currency').value.trim() || 'XOF';
          const inc = bd.querySelector('#dp-incident').value;
          if (inc) body.incident_reported_at = new Date(inc).toISOString();
          if (!body.asset_id || body.reason.length < 10) { toast('Bien et motif (min. 10 car.) requis', 'error'); return; }
          try {
            await window.SigpeApi.post('/api/v1/disposals', body);
            bd.remove();
            toast('Dossier de sortie créé', 'success');
            state.page = 1; loadTab();
          } catch (err) { toast(err.message, 'error'); }
        };
      },
    });
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

  window.SigpeViews.finance = { render, title: 'Finance patrimoniale' };
})();
