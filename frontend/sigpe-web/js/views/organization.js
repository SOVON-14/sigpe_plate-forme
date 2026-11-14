/* SIGPÉ — Vue Organisation (organization-service) : institutions, unités, types, postes */
(function () {
  'use strict';
  const { icon, esc, statusBadge, fmtDate, fmtDateTime, tableSkeleton, pagination, toast, modal, confirmDialog } = window.SigpeUI;

  const INST_STATUS = { active: 'Active', suspended: 'Suspendue', disabled: 'Désactivée' };
  const UNIT_STATUS = { active: 'Active', suspended: 'Suspendue', disabled: 'Désactivée' };
  const INST_TYPES = { administration_centrale: 'Administration centrale', ministere: 'Ministère', autre: 'Autre' };

  const state = { tab: 'institutions', page: 1, perPage: 15, total: 0, instId: null, unitId: null };

  async function render() {
    const container = document.getElementById('view');
    container.innerHTML = `
      <div class="page-header">
        <div>
          <div class="page-header__title">Organisation</div>
          <div class="page-header__subtitle">Institutions, unités organisationnelles, types et postes — Organization Service</div>
        </div>
      </div>
      <div class="btn-row mb-4">
        <button class="btn ${state.tab === 'institutions' ? 'btn--primary' : 'btn--outline'} btn--sm" data-tab="institutions">Institutions</button>
        <button class="btn ${state.tab === 'units' ? 'btn--primary' : 'btn--outline'} btn--sm" data-tab="units">Unités organisationnelles</button>
        <button class="btn ${state.tab === 'types' ? 'btn--primary' : 'btn--outline'} btn--sm" data-tab="types">Types d'unités</button>
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
      if (state.tab === 'institutions') await loadInstitutions(wrap);
      else if (state.tab === 'units') await loadUnits(wrap);
      else await loadTypes(wrap);
    } catch (err) {
      wrap.innerHTML = `<div class="card"><div class="state"><div class="state__icon">${icon('alert')}</div><p class="muted">${esc(err.message)}</p></div></div>`;
    }
  }

  /* ================= Institutions ================= */

  async function loadInstitutions(wrap) {
    document.getElementById('tab-filters').innerHTML = `
      <div class="card mb-6"><div class="card__body" style="display:flex;justify-content:flex-end">
        <button class="btn btn--gold" id="btn-new">${icon('plus')} Nouvelle institution</button>
      </div></div>`;
    document.getElementById('btn-new').onclick = newInstitutionForm;

    const res = await window.SigpeApi.get('/api/v1/institutions', { page: state.page, per_page: state.perPage });
    const items = res?.data || res?.items || res || [];
    state.total = res?.meta?.total ?? res?.total ?? items.length;
    wrap.innerHTML = `
      <div class="card">
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Code</th><th>Nom</th><th>Type</th><th>Statut</th><th>Activée le</th><th></th></tr></thead>
          <tbody>
            ${items.length ? items.map(instRow).join('') : `<tr><td colspan="6"><div class="state" style="padding:var(--space-6)"><div class="state__icon">${icon('inbox')}</div><p class="muted">Aucune institution.</p></div></td></tr>`}
          </tbody>
        </table></div>
        ${pagination({ page: state.page, total: state.total, perPage: state.perPage, onPage: (p) => { state.page = p; loadTab(); } })}
      </div>`;
    wrap.querySelectorAll('[data-detail]').forEach((b) => { b.onclick = () => showInstitution(b.dataset.detail); });
    wrap.querySelectorAll('.page-btn[data-page]').forEach((b) => { b.onclick = () => { state.page = parseInt(b.dataset.page, 10); loadTab(); }; });
  }

  function instRow(i) {
    return `<tr>
      <td class="mono">${esc(i.code)}</td>
      <td><strong>${esc(i.name)}</strong>${i.description ? `<div class="muted" style="font-size:var(--text-xs)">${esc(i.description.slice(0, 60))}</div>` : ''}</td>
      <td>${esc(INST_TYPES[i.type] || i.type || '—')}</td>
      <td>${statusBadge(i.status, INST_STATUS)}</td>
      <td>${fmtDate(i.activated_at || i.created_at)}</td>
      <td class="text-right"><button class="btn btn--ghost btn--sm" data-detail="${esc(i.id)}">${icon('eye')} Ouvrir</button></td>
    </tr>`;
  }

  async function showInstitution(id) {
    let d;
    try { d = await window.SigpeApi.get(`/api/v1/institutions/${id}`); }
    catch (err) { toast(err.message, 'error'); return; }
    const i = d?.data || d;

    modal({
      title: `Institution ${i.code}`,
      body: `
        <div class="form-grid mb-4">
          <div class="field"><label>Nom</label><div>${esc(i.name)}</div></div>
          <div class="field"><label>Type</label><div>${esc(INST_TYPES[i.type] || i.type)}</div></div>
          <div class="field"><label>Statut</label><div>${statusBadge(i.status, INST_STATUS)}</div></div>
          <div class="field"><label>Activée le</label><div>${fmtDateTime(i.activated_at)}</div></div>
        </div>
        ${i.description ? `<div class="field"><label>Description</label><p>${esc(i.description)}</p></div>` : ''}`,
      footer: ['active', 'suspended', 'disabled'].filter((s) => s !== i.status).map((s) =>
        `<button class="btn ${s === 'active' ? 'btn--primary' : 'btn--danger'}" data-status="${s}">${esc(INST_STATUS[s])}</button>`).join(''),
      onOpen: (bd) => {
        bd.querySelectorAll('[data-status]').forEach((b) => {
          b.onclick = async () => {
            const s = b.dataset.status;
            const motif = await promptText(`Motif (${INST_STATUS[s].toLowerCase()})`, '');
            if (motif === null) return;
            try {
              await window.SigpeApi.post(`/api/v1/institutions/${id}/status`, { status: s, ...(motif ? { motif } : {}) });
              document.querySelector('.modal-backdrop')?.remove();
              toast('Statut mis à jour', 'success');
              loadTab();
            } catch (err) { toast(err.message, 'error'); }
          };
        });
      },
    });
  }

  function newInstitutionForm() {
    modal({
      title: 'Nouvelle institution',
      body: `
        <div class="form-grid">
          <div class="field"><label>Code *</label><input class="input mono" id="ni-code" placeholder="MINISTERE-EXEMPLE" maxlength="50"></div>
          <div class="field"><label>Nom *</label><input class="input" id="ni-name" maxlength="255"></div>
          <div class="field"><label>Type</label>
            <select class="select" id="ni-type">${Object.entries(INST_TYPES).map(([v, l]) => `<option value="${v}">${l}</option>`).join('')}</select></div>
          <div class="field span-2"><label>Description</label><textarea class="textarea" id="ni-desc" rows="2" maxlength="2000"></textarea></div>
        </div>`,
      footer: `<button class="btn btn--outline" data-close>Annuler</button><button class="btn btn--primary" id="ni-submit">Créer</button>`,
      onOpen: (bd) => {
        bd.querySelector('#ni-submit').onclick = async () => {
          const body = {
            code: bd.querySelector('#ni-code').value.trim().toUpperCase(),
            name: bd.querySelector('#ni-name').value.trim(),
            type: bd.querySelector('#ni-type').value,
          };
          const desc = bd.querySelector('#ni-desc').value.trim(); if (desc) body.description = desc;
          if (!body.code || !body.name) { toast('Code et nom requis', 'error'); return; }
          try {
            await window.SigpeApi.post('/api/v1/institutions', body);
            bd.remove();
            toast('Institution créée', 'success');
            state.page = 1; loadTab();
          } catch (err) { toast(err.message, 'error'); }
        };
      },
    });
  }

  /* ================= Unités organisationnelles ================= */

  async function loadUnits(wrap) {
    const user = window.SigpeAuth.currentUser() || {};
    const instId = state.instId || user.institution_id;
    document.getElementById('tab-filters').innerHTML = `
      <div class="card mb-6"><div class="card__body">
        <div class="form-grid">
          <div class="field"><label>Institution</label>
            <input class="input mono" id="f-inst" value="${esc(instId || '')}" placeholder="UUID institution (vide = nationale)">
          </div>
          <div class="field" style="align-self:end;display:flex;gap:var(--space-2)">
            <button class="btn btn--primary" id="btn-load">${icon('search')} Charger</button>
            ${instId || user.institution_id ? `<button class="btn btn--gold" id="btn-new-unit">${icon('plus')} Nouvelle unité</button>` : ''}
          </div>
        </div>
      </div></div>`;
    document.getElementById('btn-load').onclick = () => { state.instId = document.getElementById('f-inst').value.trim() || null; state.page = 1; loadTab(); };
    const btnNew = document.getElementById('btn-new-unit');
    if (btnNew) btnNew.onclick = () => newUnitForm(state.instId || user.institution_id);

    const base = instId || user.institution_id;
    if (!base) {
      wrap.innerHTML = `<div class="card"><div class="state" style="padding:var(--space-6)"><div class="state__icon">${icon('info')}</div><p class="muted">Sélectionnez une institution pour lister ses unités.</p></div></div>`;
      return;
    }

    const [unitsRes, typesRes] = await Promise.all([
      window.SigpeApi.get(`/api/v1/institutions/${base}/units`, { page: state.page, per_page: state.perPage }).catch((e) => ({ __err: e })),
      window.SigpeApi.get(`/api/v1/institutions/${base}/unit-types`).catch(() => null),
    ]);
    if (unitsRes.__err) throw unitsRes.__err;
    const items = unitsRes?.data || unitsRes?.items || unitsRes || [];
    state.total = unitsRes?.meta?.total ?? unitsRes?.total ?? items.length;
    const types = typesRes?.data || typesRes?.items || [];
    const typeLabel = (id) => { const t = types.find((x) => x.id === id); return t ? `${t.label}` : short(id); };

    wrap.innerHTML = `
      <div class="card">
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Code</th><th>Nom</th><th>Type</th><th>Statut</th><th></th></tr></thead>
          <tbody>
            ${items.length ? items.map((u) => `<tr>
              <td class="mono">${esc(u.code)}</td>
              <td><strong>${esc(u.name)}</strong></td>
              <td>${esc(typeLabel(u.unit_type_id))}</td>
              <td>${statusBadge(u.status, UNIT_STATUS)}</td>
              <td class="text-right"><button class="btn btn--ghost btn--sm" data-unit="${esc(u.id)}">${icon('eye')}</button></td>
            </tr>`).join('') : `<tr><td colspan="5"><div class="state" style="padding:var(--space-6)"><div class="state__icon">${icon('inbox')}</div><p class="muted">Aucune unité.</p></div></td></tr>`}
          </tbody>
        </table></div>
        ${pagination({ page: state.page, total: state.total, perPage: state.perPage, onPage: (p) => { state.page = p; loadTab(); } })}
      </div>`;
    wrap.querySelectorAll('[data-unit]').forEach((b) => { b.onclick = () => showUnit(b.dataset.unit, base, typeLabel); });
    wrap.querySelectorAll('.page-btn[data-page]').forEach((b) => { b.onclick = () => { state.page = parseInt(b.dataset.page, 10); loadTab(); }; });
  }

  async function showUnit(id, instId, typeLabel) {
    try {
      const d = await window.SigpeApi.get(`/api/v1/units/${id}`);
      const u = d?.data || d;
      const positionsRes = await window.SigpeApi.get(`/api/v1/units/${id}/positions`).catch(() => null);
      const positions = positionsRes?.data || positionsRes?.items || [];

      modal({
        title: `Unité ${u.code}`,
        size: 'lg',
        body: `
          <div class="form-grid mb-4">
            <div class="field"><label>Nom</label><div>${esc(u.name)}</div></div>
            <div class="field"><label>Type</label><div>${esc(typeLabel ? typeLabel(u.unit_type_id) : short(u.unit_type_id))}</div></div>
            <div class="field"><label>Statut</label><div>${statusBadge(u.status, UNIT_STATUS)}</div></div>
            <div class="field"><label>Parent</label><div class="mono">${esc(u.parent_id ? short(u.parent_id) : 'racine')}</div></div>
          </div>
          <div class="field"><label>Postes (${positions.length})</label>
            <div class="table-wrap"><table class="table table--compact">
              <thead><tr><th>Code</th><th>Libellé</th><th>Responsable</th></tr></thead>
              <tbody>${positions.length ? positions.map((p) => `<tr><td class="mono">${esc(p.code)}</td><td>${esc(p.label || p.name)}</td><td class="mono">${esc(p.responsible_account_id ? short(p.responsible_account_id) : '—')}</td></tr>`).join('') : '<tr><td colspan="3" class="muted">Aucun poste</td></tr>'}</tbody>
            </table></div>
          </div>`,
        footer: ['active', 'suspended', 'disabled'].filter((s) => s !== u.status).map((s) =>
          `<button class="btn ${s === 'active' ? 'btn--primary' : 'btn--danger'}" data-status="${s}">${esc(UNIT_STATUS[s])}</button>`).join(''),
        onOpen: (bd) => {
          bd.querySelectorAll('[data-status]').forEach((b) => {
            b.onclick = async () => {
              const s = b.dataset.status;
              const motif = await promptText(`Motif (${UNIT_STATUS[s].toLowerCase()})`, '');
              if (motif === null) return;
              try {
                await window.SigpeApi.post(`/api/v1/units/${id}/status`, { status: s, ...(motif ? { motif } : {}) });
                document.querySelector('.modal-backdrop')?.remove();
                toast('Statut mis à jour', 'success');
                loadTab();
              } catch (err) { toast(err.message, 'error'); }
            };
          });
        },
      });
    } catch (err) { toast(err.message, 'error'); }
  }

  function newUnitForm(instId) {
    modal({
      title: 'Nouvelle unité organisationnelle',
      body: `
        <div class="form-grid">
          <div class="field"><label>Type d'unité *</label>
            <select class="select" id="nu-type"><option value="">Chargement…</option></select></div>
          <div class="field"><label>Code *</label><input class="input mono" id="nu-code" maxlength="50"></div>
          <div class="field span-2"><label>Nom *</label><input class="input" id="nu-name" maxlength="255"></div>
          <div class="field"><label>Unité parente (UUID — vide = racine)</label><input class="input mono" id="nu-parent"></div>
        </div>`,
      footer: `<button class="btn btn--outline" data-close>Annuler</button><button class="btn btn--primary" id="nu-submit">Créer</button>`,
      onOpen: async (bd) => {
        try {
          const res = await window.SigpeApi.get(`/api/v1/institutions/${instId}/unit-types`);
          const types = res?.data || res?.items || [];
          bd.querySelector('#nu-type').innerHTML = types.map((t) => `<option value="${esc(t.id)}">${esc(t.label)} (${esc(t.code)})</option>`).join('');
        } catch { bd.querySelector('#nu-type').innerHTML = '<option value="">Erreur de chargement</option>'; }
        bd.querySelector('#nu-submit').onclick = async () => {
          const body = {
            unit_type_id: bd.querySelector('#nu-type').value,
            code: bd.querySelector('#nu-code').value.trim(),
            name: bd.querySelector('#nu-name').value.trim(),
          };
          const parent = bd.querySelector('#nu-parent').value.trim(); if (parent) body.parent_id = parent;
          if (!body.unit_type_id || !body.code || !body.name) { toast('Type, code et nom requis', 'error'); return; }
          try {
            await window.SigpeApi.post(`/api/v1/institutions/${instId}/units`, body);
            bd.remove();
            toast('Unité créée', 'success');
            state.page = 1; loadTab();
          } catch (err) { toast(err.message, 'error'); }
        };
      },
    });
  }

  /* ================= Types d'unités ================= */

  async function loadTypes(wrap) {
    const user = window.SigpeAuth.currentUser() || {};
    const instId = state.instId || user.institution_id;
    document.getElementById('tab-filters').innerHTML = `
      <div class="card mb-6"><div class="card__body">
        <div class="form-grid">
          <div class="field"><label>Institution</label>
            <input class="input mono" id="f-inst" value="${esc(instId || '')}" placeholder="UUID institution (vide = nationale)"></div>
          <div class="field" style="align-self:end;display:flex;gap:var(--space-2)">
            <button class="btn btn--primary" id="btn-load">${icon('search')} Charger</button>
            ${instId || user.institution_id ? `<button class="btn btn--gold" id="btn-new-type">${icon('plus')} Nouveau type</button>` : ''}
          </div>
        </div>
      </div></div>`;
    document.getElementById('btn-load').onclick = () => { state.instId = document.getElementById('f-inst').value.trim() || null; loadTab(); };
    const btnNew = document.getElementById('btn-new-type');
    if (btnNew) btnNew.onclick = () => newTypeForm(state.instId || user.institution_id);

    const base = instId || user.institution_id;
    if (!base) {
      wrap.innerHTML = `<div class="card"><div class="state" style="padding:var(--space-6)"><div class="state__icon">${icon('info')}</div><p class="muted">Sélectionnez une institution pour lister ses types d'unités.</p></div></div>`;
      return;
    }

    const res = await window.SigpeApi.get(`/api/v1/institutions/${base}/unit-types`);
    const items = res?.data || res?.items || [];
    wrap.innerHTML = `
      <div class="card">
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Code</th><th>Libellé</th><th>Portée</th><th>Système</th></tr></thead>
          <tbody>
            ${items.length ? items.map((t) => `<tr>
              <td class="mono">${esc(t.code)}</td>
              <td><strong>${esc(t.label)}</strong></td>
              <td>${t.institution_id ? '<span class="badge badge--info">locale</span>' : '<span class="badge badge--gold">nationale</span>'}</td>
              <td>${t.is_system ? '<span class="badge">système</span>' : '<span class="muted">—</span>'}</td>
            </tr>`).join('') : `<tr><td colspan="4"><div class="state" style="padding:var(--space-6)"><div class="state__icon">${icon('inbox')}</div><p class="muted">Aucun type d'unité.</p></div></td></tr>`}
          </tbody>
        </table></div>
      </div>`;
  }

  function newTypeForm(instId) {
    modal({
      title: 'Nouveau type d\'unité',
      body: `
        <div class="form-grid">
          <div class="field"><label>Code *</label><input class="input mono" id="nt-code" placeholder="direction" maxlength="50"></div>
          <div class="field"><label>Libellé *</label><input class="input" id="nt-label" maxlength="255"></div>
        </div>`,
      footer: `<button class="btn btn--outline" data-close>Annuler</button><button class="btn btn--primary" id="nt-submit">Créer</button>`,
      onOpen: (bd) => {
        bd.querySelector('#nt-submit').onclick = async () => {
          const body = {
            code: bd.querySelector('#nt-code').value.trim().toLowerCase(),
            label: bd.querySelector('#nt-label').value.trim(),
          };
          if (!body.code || !body.label) { toast('Code et libellé requis', 'error'); return; }
          try {
            await window.SigpeApi.post(`/api/v1/institutions/${instId}/unit-types`, body);
            bd.remove();
            toast('Type créé', 'success');
            loadTab();
          } catch (err) { toast(err.message, 'error'); }
        };
      },
    });
  }

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

  const short = (id) => (id ? String(id).slice(0, 8) : '—');

  window.SigpeViews.organization = { render, title: 'Organisation' };
})();
