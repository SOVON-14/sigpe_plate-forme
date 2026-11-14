/* SIGPÉ — Vue Inventaires (inventory-service) : campagnes et constats */
(function () {
  'use strict';
  const { icon, esc, statusBadge, fmtDate, fmtDateTime, tableSkeleton, pagination, toast, modal, confirmDialog } = window.SigpeUI;

  const TYPE_LABELS = { general_annual: 'Général annuel', periodic: 'Périodique', exceptional: 'Exceptionnelle' };
  const CAMP_STATUS = { draft: 'Brouillon', in_progress: 'En cours', under_review: 'En revue', closed: 'Clôturée', cancelled: 'Annulée' };
  const RESULT_LABELS = { trouve: 'Trouvé', non_trouve: 'Non trouvé' };
  const ANOMALY_LABELS = {
    endommage: 'Endommagement', mal_localise: 'Mal localisé', non_enregistre: 'Non enregistré',
    mal_identifie: 'Mal identifié', utilisation_non_declaree: 'Utilisation non déclarée', autre: 'Autre',
  };
  const VERIF_LABELS = { pending: 'En attente', confirmed: 'Confirmé', rejected: 'Rejeté', resolved: 'Résolu' };

  const state = { tab: 'campaigns', page: 1, perPage: 15, total: 0, institutionId: null };

  function userInstitution() {
    const u = window.SigpeAuth.currentUser() || {};
    return u.institution_id || null;
  }

  async function render() {
    const container = document.getElementById('view');
    state.institutionId = userInstitution();
    container.innerHTML = `
      <div class="page-header">
        <div>
          <div class="page-header__title">Inventaires</div>
          <div class="page-header__subtitle">Campagnes d'inventaire physique et constats — Inventory Service</div>
        </div>
      </div>
      <div class="btn-row mb-4">
        <button class="btn ${state.tab === 'campaigns' ? 'btn--primary' : 'btn--outline'} btn--sm" data-tab="campaigns">Campagnes</button>
        <button class="btn ${state.tab === 'findings' ? 'btn--primary' : 'btn--outline'} btn--sm" data-tab="findings">Constats</button>
      </div>
      ${!state.institutionId ? `<div class="alert alert--info">${icon('info')} Votre compte n'est pas rattaché à une institution : saisissez l'UUID de l'institution pour consulter ses campagnes.</div>` : ''}
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
      if (state.tab === 'campaigns') await loadCampaigns(wrap);
      else await loadFindings(wrap);
    } catch (err) {
      wrap.innerHTML = `<div class="card"><div class="state"><div class="state__icon">${icon('alert')}</div><p class="muted">${esc(err.message)}</p></div></div>`;
    }
  }

  /* ================= Campagnes ================= */

  function campaignFiltersHtml() {
    return `
      <div class="card mb-6"><div class="card__body">
        <div class="form-grid">
          ${!state.institutionId ? `<div class="field"><label>Institution (UUID) *</label><input class="input mono" id="f-inst" placeholder="uuid de l'institution"></div>` : ''}
          <div class="field" style="align-self:end">
            <button class="btn btn--primary" id="btn-load">${icon('search')} Charger</button>
            <button class="btn btn--gold" id="btn-new">${icon('plus')} Nouvelle campagne</button>
          </div>
        </div>
      </div></div>`;
  }

  async function loadCampaigns(wrap) {
    document.getElementById('tab-filters').innerHTML = campaignFiltersHtml();
    const instInput = document.getElementById('f-inst');
    if (instInput) {
      instInput.value = state.institutionId || '';
      document.getElementById('btn-load').onclick = () => {
        state.institutionId = instInput.value.trim() || null;
        state.page = 1; loadCampaigns(wrap);
      };
    } else {
      document.getElementById('btn-load').onclick = () => { state.page = 1; loadCampaigns(wrap); };
    }
    document.getElementById('btn-new').onclick = newCampaignForm;

    if (!state.institutionId) {
      wrap.innerHTML = `<div class="card"><div class="state"><div class="state__icon">${icon('organization')}</div><p class="muted">Sélectionnez une institution pour lister ses campagnes.</p></div></div>`;
      return;
    }

    const res = await window.SigpeApi.get(`/api/v1/institutions/${state.institutionId}/inventory-campaigns`, { page: state.page, per_page: state.perPage });
    const items = res?.data || res?.items || res || [];
    state.total = res?.meta?.total ?? res?.total ?? items.length;
    wrap.innerHTML = `
      <div class="card">
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Désignation</th><th>Type</th><th>Période</th><th>Statut</th><th>Constats</th><th></th></tr></thead>
          <tbody>
            ${items.length ? items.map(campRow).join('') : `<tr><td colspan="6"><div class="state" style="padding:var(--space-6)"><div class="state__icon">${icon('inbox')}</div><p class="muted">Aucune campagne.</p></div></td></tr>`}
          </tbody>
        </table></div>
        ${pagination({ page: state.page, total: state.total, perPage: state.perPage, onPage: (p) => { state.page = p; loadCampaigns(wrap); } })}
      </div>`;
    wrap.querySelectorAll('[data-detail]').forEach((b) => { b.onclick = () => showCampaign(b.dataset.detail); });
    wrap.querySelectorAll('.page-btn[data-page]').forEach((b) => { b.onclick = () => { state.page = parseInt(b.dataset.page, 10); loadCampaigns(wrap); }; });
  }

  function campRow(c) {
    return `<tr>
      <td><strong>${esc(c.designation)}</strong></td>
      <td>${esc(TYPE_LABELS[c.type] || c.type)}</td>
      <td>${fmtDate(c.period_start)} → ${fmtDate(c.period_end)}</td>
      <td>${statusBadge(c.status, CAMP_STATUS)}</td>
      <td class="num">${c.findings_count ?? '—'}</td>
      <td class="text-right"><button class="btn btn--ghost btn--sm" data-detail="${esc(c.id)}">${icon('eye')} Ouvrir</button></td>
    </tr>`;
  }

  async function showCampaign(id) {
    let d;
    try { d = await window.SigpeApi.get(`/api/v1/inventory-campaigns/${id}`); }
    catch (err) { toast(err.message, 'error'); return; }
    const c = d?.data || d;

    const TRANSITIONS = {
      draft: [['in_progress', 'Démarrer']],
      in_progress: [['under_review', 'Soumettre en revue'], ['cancelled', 'Annuler']],
      under_review: [['closed', 'Clôturer (+ rapport)'], ['in_progress', 'Rouvrir']],
      closed: [], cancelled: [],
    };
    const actions = (TRANSITIONS[c.status] || []).map(([s, l]) => [s, l, s === 'cancelled' ? 'btn--danger' : 'btn--primary']);

    modal({
      title: `Campagne — ${esc(c.designation)}`,
      size: 'lg',
      body: `
        <div class="form-grid mb-4">
          <div class="field"><label>Statut</label><div>${statusBadge(c.status, CAMP_STATUS)}</div></div>
          <div class="field"><label>Type</label><div>${esc(TYPE_LABELS[c.type] || c.type)}</div></div>
          <div class="field"><label>Période</label><div>${fmtDate(c.period_start)} → ${fmtDate(c.period_end)}</div></div>
          <div class="field"><label>Localisation</label><div>${esc(c.location || '—')}</div></div>
          <div class="field"><label>Responsable</label><div class="mono">${esc(c.responsible_account_id || '—')}</div></div>
          <div class="field"><label>Institution</label><div class="mono">${esc(c.institution_id || '—')}</div></div>
        </div>
        <div class="field"><label>Agents affectés</label><div class="mono" style="font-size:var(--text-xs)">${esc((c.agents || []).map((a) => a.account_id || a.id).join(', ') || '—')}</div></div>`,
      footer: actions.map(([s, l, cls]) => `<button class="btn ${cls}" data-act="${s}">${esc(l)}</button>`).join(''),
      onOpen: (bd) => {
        bd.querySelectorAll('[data-act]').forEach((b) => {
          b.onclick = async () => {
            bd.remove();
            try {
              const target = b.dataset.act;
              let motif;
              if (target === 'cancelled') {
                motif = await promptText('Motif d\'annulation', '');
                if (!motif) return;
              } else if (target === 'closed') {
                const ok = await confirmDialog('Clôturer la campagne', 'La clôture génère le rapport d\'écarts. Confirmer ?');
                if (ok !== 'ok') return;
              }
              await window.SigpeApi.post(`/api/v1/inventory-campaigns/${id}/status`, { status: target, ...(motif ? { motif } : {}) });
              toast('Campagne mise à jour', 'success');
              loadTab();
            } catch (err) { toast(err.message, 'error'); }
          };
        });
      },
    });
  }

  function newCampaignForm() {
    modal({
      title: 'Nouvelle campagne d\'inventaire', size: 'lg',
      body: `
        <div class="form-grid">
          <div class="field"><label>Désignation *</label><input class="input" id="cp-designation" maxlength="255"></div>
          <div class="field"><label>Type *</label>
            <select class="select" id="cp-type">${Object.entries(TYPE_LABELS).map(([v, l]) => `<option value="${v}">${l}</option>`).join('')}</select></div>
          <div class="field"><label>Début *</label><input class="input" type="date" id="cp-start"></div>
          <div class="field"><label>Fin *</label><input class="input" type="date" id="cp-end"></div>
          <div class="field"><label>Compte responsable (UUID) *</label><input class="input mono" id="cp-resp"></div>
          <div class="field"><label>Localisation</label><input class="input" id="cp-loc" maxlength="500"></div>
          <div class="field span-2"><label>Unités organisationnelles (UUID, séparés par des virgules)</label><input class="input mono" id="cp-units" placeholder="uuid1, uuid2…"></div>
        </div>`,
      footer: `<button class="btn btn--outline" data-close>Annuler</button><button class="btn btn--primary" id="cp-submit">Créer</button>`,
      onOpen: (bd) => {
        bd.querySelector('#cp-submit').onclick = async () => {
          const body = {
            designation: bd.querySelector('#cp-designation').value.trim(),
            type: bd.querySelector('#cp-type').value,
            period_start: bd.querySelector('#cp-start').value,
            period_end: bd.querySelector('#cp-end').value,
            responsible_account_id: bd.querySelector('#cp-resp').value.trim(),
          };
          const loc = bd.querySelector('#cp-loc').value.trim(); if (loc) body.location = loc;
          const units = bd.querySelector('#cp-units').value.split(',').map((s) => s.trim()).filter(Boolean);
          if (units.length) body.organizational_unit_ids = units;
          if (!body.designation || !body.period_start || !body.period_end || !body.responsible_account_id) {
            toast('Champs obligatoires manquants', 'error'); return;
          }
          const path = state.institutionId
            ? `/api/v1/institutions/${state.institutionId}/inventory-campaigns`
            : null;
          if (!path) { toast('Institution requise', 'error'); return; }
          try {
            await window.SigpeApi.post(path, body);
            bd.remove();
            toast('Campagne créée', 'success');
            state.page = 1; loadTab();
          } catch (err) { toast(err.message, 'error'); }
        };
      },
    });
  }

  /* ================= Constats ================= */

  async function loadFindings(wrap) {
    document.getElementById('tab-filters').innerHTML = `
      <div class="card mb-6"><div class="card__body">
        <div class="form-grid">
          ${!state.institutionId ? `<div class="field"><label>Institution (UUID) *</label><input class="input mono" id="f-inst" placeholder="uuid de l'institution"></div>` : ''}
          <div class="field"><label>Campagne *</label>
            <select class="select" id="f-campaign"><option value="">— sélectionner —</option></select></div>
          <div class="field" style="align-self:end;display:flex;gap:var(--space-2)">
            <button class="btn btn--primary" id="btn-load">${icon('search')} Charger</button>
            <button class="btn btn--gold" id="btn-record" disabled>${icon('plus')} Saisir un constat</button>
          </div>
        </div>
      </div></div>`;
    const instInput = document.getElementById('f-inst');
    if (instInput) {
      instInput.value = state.institutionId || '';
      document.getElementById('btn-load').onclick = async () => {
        state.institutionId = instInput.value.trim() || null;
        state.page = 1; await loadFindings(wrap);
      };
    } else {
      document.getElementById('btn-load').onclick = async () => { state.page = 1; await loadFindings(wrap); };
    }

    const campSel = document.getElementById('f-campaign');
    const recBtn = document.getElementById('btn-record');
    campSel.onchange = () => { state.campaignId = campSel.value || null; recBtn.disabled = !state.campaignId; };
    recBtn.onclick = () => newFindingForm(state.campaignId);

    if (!state.institutionId) {
      wrap.innerHTML = `<div class="card"><div class="state"><div class="state__icon">${icon('organization')}</div><p class="muted">Sélectionnez une institution pour lister ses constats.</p></div></div>`;
      return;
    }

    try {
      const res = await window.SigpeApi.get(`/api/v1/institutions/${state.institutionId}/inventory-campaigns`, { per_page: 100 });
      const campaigns = res?.data || res?.items || res || [];
      campSel.innerHTML = '<option value="">— sélectionner —</option>' + campaigns
        .map((c) => `<option value="${esc(c.id)}" ${state.campaignId === c.id ? 'selected' : ''}>${esc(c.designation)} (${esc(CAMP_STATUS[c.status] || c.status)})</option>`).join('');
      if (state.campaignId) recBtn.disabled = false;
    } catch (err) {
      wrap.innerHTML = `<div class="card"><div class="state"><div class="state__icon">${icon('alert')}</div><p class="muted">${esc(err.message)}</p></div></div>`;
      return;
    }

    if (!state.campaignId) {
      wrap.innerHTML = `<div class="card"><div class="state"><div class="state__icon">${icon('inventory')}</div><p class="muted">Sélectionnez une campagne pour afficher ses constats.</p></div></div>`;
      return;
    }

    const res = await window.SigpeApi.get(`/api/v1/inventory-campaigns/${state.campaignId}/findings`, { page: state.page, per_page: state.perPage });
    const items = res?.data || res?.items || res || [];
    state.total = res?.meta?.total ?? res?.total ?? items.length;
    wrap.innerHTML = `
      <div class="card">
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Bien</th><th>Résultat</th><th>Anomalie</th><th>Vérification</th><th>Relevé le</th><th></th></tr></thead>
          <tbody>
            ${items.length ? items.map(findingRow).join('') : `<tr><td colspan="6"><div class="state" style="padding:var(--space-6)"><div class="state__icon">${icon('inbox')}</div><p class="muted">Aucun constat pour cette campagne.</p></div></td></tr>`}
          </tbody>
        </table></div>
        ${pagination({ page: state.page, total: state.total, perPage: state.perPage, onPage: (p) => { state.page = p; loadTab(); } })}
      </div>`;
    wrap.querySelectorAll('[data-verify]').forEach((b) => { b.onclick = () => verifyFinding(b.dataset.verify); });
    wrap.querySelectorAll('.page-btn[data-page]').forEach((b) => { b.onclick = () => { state.page = parseInt(b.dataset.page, 10); loadTab(); }; });
  }

  function newFindingForm(campaignId) {
    modal({
      title: 'Saisir un constat', size: 'lg',
      body: `
        <div class="form-grid">
          <div class="field"><label>Résultat *</label>
            <select class="select" id="nf-result"><option value="trouve">Trouvé</option><option value="non_trouve">Non trouvé</option></select></div>
          <div class="field"><label>Méthode d'identification *</label>
            <select class="select" id="nf-method"><option value="manual">Saisie manuelle</option><option value="qr_code">QR code</option></select></div>
          <div class="field"><label>Bien (UUID asset — vide si non enregistré)</label><input class="input mono" id="nf-asset"></div>
          <div class="field"><label>Référence libre</label><input class="input" id="nf-free" maxlength="255" placeholder="QR scanné hors registre…"></div>
          <div class="field"><label>Désignation relevée</label><input class="input" id="nf-desig" maxlength="255"></div>
          <div class="field"><label>Anomalie</label>
            <select class="select" id="nf-anomaly"><option value="">—</option>
              ${Object.entries(ANOMALY_LABELS).map(([v, l]) => `<option value="${v}">${l}</option>`).join('')}
            </select></div>
          <div class="field"><label>Localisation relevée</label><input class="input" id="nf-loc" maxlength="500"></div>
          <div class="field"><label>Agent/utilisateur observé</label><input class="input" id="nf-custodian" maxlength="255"></div>
          <div class="field span-2"><label>Notes</label><textarea class="textarea" id="nf-notes" rows="2" maxlength="2000"></textarea></div>
        </div>`,
      footer: `<button class="btn btn--outline" data-close>Annuler</button><button class="btn btn--primary" id="nf-submit">Enregistrer</button>`,
      onOpen: (bd) => {
        bd.querySelector('#nf-submit').onclick = async () => {
          const body = {
            result: bd.querySelector('#nf-result').value,
            identification_method: bd.querySelector('#nf-method').value,
          };
          const asset = bd.querySelector('#nf-asset').value.trim(); if (asset) body.asset_id = asset;
          const free = bd.querySelector('#nf-free').value.trim(); if (free) body.free_reference = free;
          const desig = bd.querySelector('#nf-desig').value.trim(); if (desig) body.designation_found = desig;
          const anom = bd.querySelector('#nf-anomaly').value; if (anom) body.anomaly_type = anom;
          const loc = bd.querySelector('#nf-loc').value.trim(); if (loc) body.location_found = loc;
          const cust = bd.querySelector('#nf-custodian').value.trim(); if (cust) body.custodian_observed = cust;
          const notes = bd.querySelector('#nf-notes').value.trim(); if (notes) body.notes = notes;
          try {
            await window.SigpeApi.post(`/api/v1/inventory-campaigns/${campaignId}/findings`, body);
            bd.remove();
            toast('Constat enregistré', 'success');
            state.page = 1; loadTab();
          } catch (err) { toast(err.message, 'error'); }
        };
      },
    });
  }

  function findingRow(f) {
    const resultBadge = f.result === 'trouve'
      ? '<span class="badge badge--success">Trouvé</span>'
      : '<span class="badge badge--danger">Non trouvé</span>';
    return `<tr>
      <td class="mono" style="font-size:var(--text-xs)">${esc(f.asset_id ? short(f.asset_id) : (f.free_reference || '—'))}</td>
      <td>${resultBadge}</td>
      <td>${esc(ANOMALY_LABELS[f.anomaly_type] || f.anomaly_type || '—')}</td>
      <td>${statusBadge(f.verification_status, VERIF_LABELS)}</td>
      <td>${fmtDateTime(f.recorded_at)}</td>
      <td class="text-right">${f.verification_status === 'pending' ? `<button class="btn btn--ghost btn--sm" data-verify="${esc(f.id)}">Vérifier</button>` : ''}</td>
    </tr>`;
  }

  async function verifyFinding(id) {
    const decision = await choiceDialog('Décision de vérification', {
      confirmed: 'Confirmer le constat', rejected: 'Rejeter', resolved: 'Marquer résolu',
    });
    if (!decision) return;
    const notes = await promptText('Notes de vérification (optionnel)', '');
    if (notes === null) return;
    try {
      await window.SigpeApi.post(`/api/v1/findings/${id}/verify`, { decision, ...(notes ? { notes } : {}) });
      toast('Constat vérifié', 'success');
      loadTab();
    } catch (err) { toast(err.message, 'error'); }
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
        },
      });
    });
  }

  window.SigpeViews.inventory = { render, title: 'Inventaires' };
})();
