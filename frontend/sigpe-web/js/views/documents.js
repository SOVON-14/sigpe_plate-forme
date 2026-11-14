/* SIGPÉ — Vue Documents (document-service) : dépôt, versions, accès, cycle de vie */
(function () {
  'use strict';
  const { icon, esc, statusBadge, fmtDate, fmtDateTime, tableSkeleton, pagination, toast, modal, confirmDialog } = window.SigpeUI;

  const CONF_LABELS = {
    public_administratif: 'Public administratif', interne: 'Interne',
    confidentiel: 'Confidentiel', hautement_sensible: 'Hautement sensible',
  };
  const CONF_BADGE = { public_administratif: 'info', interne: '', confidentiel: 'warning', hautement_sensible: 'danger' };
  const ACCESS_STATUS = { pending: 'En attente', approved: 'Approuvée', denied: 'Refusée', expired: 'Expirée', revoked: 'Révoquée' };

  const state = { tab: 'documents', page: 1, perPage: 15, total: 0, q: '', conf: '' };

  async function render() {
    const container = document.getElementById('view');
    container.innerHTML = `
      <div class="page-header">
        <div>
          <div class="page-header__title">Documents</div>
          <div class="page-header__subtitle">Dépôt, versions immuables, confidentialité et journal d'accès — Document Service</div>
        </div>
        <button class="btn btn--gold" id="btn-upload">${icon('plus')} Déposer un document</button>
      </div>
      <div class="btn-row mb-4">
        <button class="btn ${state.tab === 'documents' ? 'btn--primary' : 'btn--outline'} btn--sm" data-tab="documents">Documents</button>
        <button class="btn ${state.tab === 'access' ? 'btn--primary' : 'btn--outline'} btn--sm" data-tab="access">Demandes d'accès</button>
      </div>
      <div id="tab-filters"></div>
      <div id="tab-content">${tableSkeleton(6, 6)}</div>`;

    document.getElementById('btn-upload').onclick = uploadForm;
    container.querySelectorAll('[data-tab]').forEach((b) => {
      b.onclick = () => { state.tab = b.dataset.tab; state.page = 1; render(); };
    });
    await loadTab();
  }

  async function loadTab() {
    const wrap = document.getElementById('tab-content');
    wrap.innerHTML = tableSkeleton(6, 6);
    try {
      if (state.tab === 'documents') await loadDocuments(wrap);
      else await loadAccessRequests(wrap);
    } catch (err) {
      wrap.innerHTML = `<div class="card"><div class="state"><div class="state__icon">${icon('alert')}</div><p class="muted">${esc(err.message)}</p></div></div>`;
    }
  }

  /* ================= Documents ================= */

  async function loadDocuments(wrap) {
    document.getElementById('tab-filters').innerHTML = `
      <div class="card mb-6"><div class="card__body">
        <div class="form-grid">
          <div class="field"><label>Recherche</label><input class="input" id="f-q" placeholder="Titre, référence…" value="${esc(state.q)}"></div>
          <div class="field"><label>Confidentialité</label>
            <select class="select" id="f-conf"><option value="">Toutes</option>
              ${Object.entries(CONF_LABELS).map(([v, l]) => `<option value="${v}" ${state.conf === v ? 'selected' : ''}>${l}</option>`).join('')}
            </select></div>
          <div class="field" style="align-self:end"><button class="btn btn--primary" id="btn-search">${icon('search')} Filtrer</button></div>
        </div>
      </div></div>`;
    document.getElementById('btn-search').onclick = () => {
      state.q = document.getElementById('f-q').value.trim();
      state.conf = document.getElementById('f-conf').value;
      state.page = 1; loadDocuments(wrap);
    };

    const params = { page: state.page, per_page: state.perPage };
    if (state.q) params.q = state.q;
    if (state.conf) params.confidentiality = state.conf;
    const res = await window.SigpeApi.get('/api/v1/documents', params);
    const items = res?.data || res?.items || res || [];
    state.total = res?.meta?.total ?? res?.total ?? items.length;

    wrap.innerHTML = `
      <div class="card">
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Titre</th><th>Catégorie</th><th>Confidentialité</th><th>Version</th><th>Cycle de vie</th><th>Déposé le</th><th></th></tr></thead>
          <tbody>
            ${items.length ? items.map(docRow).join('') : `<tr><td colspan="7"><div class="state" style="padding:var(--space-6)"><div class="state__icon">${icon('inbox')}</div><p class="muted">Aucun document.</p></div></td></tr>`}
          </tbody>
        </table></div>
        ${pagination({ page: state.page, total: state.total, perPage: state.perPage, onPage: (p) => { state.page = p; loadDocuments(wrap); } })}
      </div>`;
    wrap.querySelectorAll('[data-detail]').forEach((b) => { b.onclick = () => showDocument(b.dataset.detail); });
    wrap.querySelectorAll('.page-btn[data-page]').forEach((b) => { b.onclick = () => { state.page = parseInt(b.dataset.page, 10); loadDocuments(wrap); }; });
  }

  function docRow(d) {
    return `<tr>
      <td><strong>${esc(d.title)}</strong><br><span class="muted mono" style="font-size:var(--text-xs)">${esc(d.reference || '')}</span></td>
      <td>${esc(d.category?.name || d.category_name || '—')}</td>
      <td><span class="badge badge--${CONF_BADGE[d.confidentiality] ?? ''}">${esc(CONF_LABELS[d.confidentiality] || d.confidentiality || '—')}</span></td>
      <td class="num">v${d.current_version ?? '—'}</td>
      <td>${statusBadge(d.lifecycle_status || d.status)}</td>
      <td>${fmtDate(d.created_at)}</td>
      <td class="text-right"><button class="btn btn--ghost btn--sm" data-detail="${esc(d.id)}">${icon('eye')} Ouvrir</button></td>
    </tr>`;
  }

  async function showDocument(id) {
    let d;
    try { d = await window.SigpeApi.get(`/api/v1/documents/${id}`); }
    catch (err) { toast(err.message, 'error'); return; }
    const doc = d?.data || d;
    const versions = doc.versions || [];

    modal({
      title: doc.title,
      size: 'lg',
      body: `
        <div class="form-grid mb-4">
          <div class="field"><label>Statut</label><div>${statusBadge(doc.lifecycle_status || doc.status)}</div></div>
          <div class="field"><label>Confidentialité</label><div><span class="badge badge--${CONF_BADGE[doc.confidentiality] ?? ''}">${esc(CONF_LABELS[doc.confidentiality] || '—')}</span></div></div>
          <div class="field"><label>Catégorie</label><div>${esc(doc.category?.name || doc.category_name || '—')}</div></div>
          <div class="field"><label>Référence</label><div class="mono">${esc(doc.reference || '—')}</div></div>
          <div class="field"><label>Date du document</label><div>${fmtDate(doc.document_date)}</div></div>
          <div class="field"><label>Émis par</label><div>${esc(doc.issuer || '—')}</div></div>
          <div class="field"><label>Conservation jusqu'au</label><div>${fmtDate(doc.retention_until)}</div></div>
          <div class="field"><label>Empreinte (v${doc.current_version ?? '?'})</label><div class="mono" style="font-size:.7rem;word-break:break-all">${esc(versions[0]?.evidence_hash || versions[0]?.fingerprint || '—')}</div></div>
        </div>
        ${doc.description ? `<div class="field"><label>Description</label><p class="muted">${esc(doc.description)}</p></div>` : ''}
        <div class="field"><label>Versions (${versions.length})</label>
          <div class="card" style="border-radius:var(--radius)">
            ${versions.length ? versions.map((v) => `
              <div class="list-item">
                <div class="list-item__icon">${icon('documents')}</div>
                <div class="list-item__body">
                  <div class="list-item__title">Version ${v.version ?? v.number ?? '—'} — ${esc(v.file_name || v.filename || 'fichier')}</div>
                  <div class="list-item__meta">${fmtDateTime(v.created_at)} · ${esc(v.mime_type || '')} · ${v.size ? Math.round(v.size / 1024) + ' Ko' : ''}</div>
                </div>
                <span class="mono" style="font-size:.65rem;color:var(--color-text-3)">${esc((v.evidence_hash || '').slice(0, 16))}…</span>
              </div>`).join('') : '<div class="state" style="padding:var(--space-4)"><p class="muted">Aucune version.</p></div>'}
          </div>
        </div>`,
      footer: `<button class="btn btn--outline" data-close>Fermer</button>`,
    });
  }

  function uploadForm() {
    modal({
      title: 'Déposer un document', size: 'lg',
      body: `
        <div class="form-grid">
          <div class="field"><label>Titre *</label><input class="input" id="d-title" maxlength="300"></div>
          <div class="field"><label>Catégorie (UUID) *</label><input class="input mono" id="d-category"></div>
          <div class="field"><label>Confidentialité</label>
            <select class="select" id="d-conf">${Object.entries(CONF_LABELS).map(([v, l]) => `<option value="${v}" ${v === 'interne' ? 'selected' : ''}>${l}</option>`).join('')}</select></div>
          <div class="field"><label>Référence</label><input class="input" id="d-ref" maxlength="100"></div>
          <div class="field"><label>Date du document</label><input class="input" type="date" id="d-date"></div>
          <div class="field"><label>Émis par</label><input class="input" id="d-issuer" maxlength="300"></div>
          <div class="field"><label>Conservation jusqu'au</label><input class="input" type="date" id="d-retention"></div>
          <div class="field span-2"><label>Description</label><textarea class="textarea" id="d-desc" rows="2" maxlength="5000"></textarea></div>
          <div class="field span-2"><label>Fichier * (PDF, image, office…)</label><input class="input" type="file" id="d-file"></div>
        </div>`,
      footer: `<button class="btn btn--outline" data-close>Annuler</button><button class="btn btn--primary" id="d-submit">Téléverser</button>`,
      onOpen: (bd) => {
        bd.querySelector('#d-submit').onclick = async () => {
          const fileInput = bd.querySelector('#d-file');
          const file = fileInput.files?.[0];
          const fields = {
            title: bd.querySelector('#d-title').value.trim(),
            category_id: bd.querySelector('#d-category').value.trim(),
            confidentiality: bd.querySelector('#d-conf').value,
            reference: bd.querySelector('#d-ref').value.trim() || undefined,
            document_date: bd.querySelector('#d-date').value || undefined,
            issuer: bd.querySelector('#d-issuer').value.trim() || undefined,
            retention_until: bd.querySelector('#d-retention').value || undefined,
            description: bd.querySelector('#d-desc').value.trim() || undefined,
          };
          if (!fields.title || !fields.category_id || !file) { toast('Titre, catégorie et fichier requis', 'error'); return; }
          try {
            await window.SigpeApi.upload('/api/v1/documents', { fields, file });
            bd.remove();
            toast('Document déposé', 'success');
            state.page = 1; loadTab();
          } catch (err) { toast(err.message, 'error'); }
        };
      },
    });
  }

  /* ================= Demandes d'accès ================= */

  async function loadAccessRequests(wrap) {
    document.getElementById('tab-filters').innerHTML = `
      <div class="card mb-6"><div class="card__body">
        <div class="form-grid">
          <div class="field"><label>Document *</label>
            <select class="select" id="f-doc"><option value="">Chargement…</option></select></div>
          <div class="field" style="align-self:end;display:flex;gap:var(--space-2)">
            <button class="btn btn--primary" id="btn-load">${icon('search')} Charger</button>
            <button class="btn btn--gold" id="btn-request" disabled>${icon('plus')} Demander l'accès</button>
          </div>
        </div>
      </div></div>`;
    document.getElementById('btn-load').onclick = () => { state.page = 1; loadAccessRequests(wrap); };
    const docSel = document.getElementById('f-doc');
    const reqBtn = document.getElementById('btn-request');
    docSel.onchange = () => { state.accessDocId = docSel.value || null; reqBtn.disabled = !state.accessDocId; };
    reqBtn.onclick = () => newAccessRequest(state.accessDocId);

    try {
      const res = await window.SigpeApi.get('/api/v1/documents', { per_page: 100 });
      const docs = res?.data || res?.items || res || [];
      docSel.innerHTML = '<option value="">— sélectionner —</option>' + docs
        .map((d) => `<option value="${esc(d.id)}" ${state.accessDocId === d.id ? 'selected' : ''}>${esc(d.title)}</option>`).join('');
      if (state.accessDocId) reqBtn.disabled = false;
    } catch (err) {
      wrap.innerHTML = `<div class="card"><div class="state"><div class="state__icon">${icon('alert')}</div><p class="muted">${esc(err.message)}</p></div></div>`;
      return;
    }

    if (!state.accessDocId) {
      wrap.innerHTML = `<div class="card"><div class="state"><div class="state__icon">${icon('documents')}</div><p class="muted">Sélectionnez un document pour afficher ses demandes d'accès.</p></div></div>`;
      return;
    }

    const res = await window.SigpeApi.get(`/api/v1/documents/${state.accessDocId}/access-requests`, { page: state.page, per_page: state.perPage });
    const items = res?.data || res?.items || res || [];
    state.total = res?.meta?.total ?? res?.total ?? items.length;
    wrap.innerHTML = `
      <div class="card">
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Demandeur</th><th>Motif</th><th>Portée</th><th>Valide jusqu'au</th><th>Statut</th><th>Demandée le</th><th></th></tr></thead>
          <tbody>
            ${items.length ? items.map(accessRow).join('') : `<tr><td colspan="7"><div class="state" style="padding:var(--space-6)"><div class="state__icon">${icon('inbox')}</div><p class="muted">Aucune demande d'accès.</p></div></td></tr>`}
          </tbody>
        </table></div>
        ${pagination({ page: state.page, total: state.total, perPage: state.perPage, onPage: (p) => { state.page = p; loadTab(); } })}
      </div>`;
    wrap.querySelectorAll('[data-decision]').forEach((b) => { b.onclick = () => decideAccess(b.dataset.decision, b.dataset.id); });
    wrap.querySelectorAll('.page-btn[data-page]').forEach((b) => { b.onclick = () => { state.page = parseInt(b.dataset.page, 10); loadTab(); }; });
  }

  function newAccessRequest(docId) {
    modal({
      title: "Demande d'accès exceptionnel",
      body: `
        <div class="alert alert--warning" style="margin-bottom:var(--space-4)">L'accès à un document confidentiel nécessite une approbation. La demande est tracée dans le journal d'accès.</div>
        <div class="form-grid">
          <div class="field"><label>Portée</label>
            <select class="select" id="ar-scope"><option value="view">Consultation</option><option value="download">Téléchargement</option></select></div>
          <div class="field"><label>Valide jusqu'au *</label><input class="input" type="datetime-local" id="ar-until"></div>
          <div class="field span-2"><label>Motif * (min. 5 caractères)</label><textarea class="textarea" id="ar-motif" rows="3" maxlength="2000"></textarea></div>
        </div>`,
      footer: `<button class="btn btn--outline" data-close>Annuler</button><button class="btn btn--primary" id="ar-submit">Soumettre</button>`,
      onOpen: (bd) => {
        bd.querySelector('#ar-submit').onclick = async () => {
          const body = {
            motif: bd.querySelector('#ar-motif').value.trim(),
            requested_until: bd.querySelector('#ar-until').value ? new Date(bd.querySelector('#ar-until').value).toISOString() : null,
            scope: bd.querySelector('#ar-scope').value,
          };
          if (!body.motif || body.motif.length < 5 || !body.requested_until) { toast('Motif et date de fin requis', 'error'); return; }
          try {
            await window.SigpeApi.post(`/api/v1/documents/${docId}/access-requests`, body);
            bd.remove();
            toast('Demande soumise', 'success');
            state.page = 1; loadTab();
          } catch (err) { toast(err.message, 'error'); }
        };
      },
    });
  }

  function accessRow(a) {
    return `<tr>
      <td class="mono" style="font-size:var(--text-xs)">${esc(short(a.requester_id))}</td>
      <td>${esc((a.motif || '—').slice(0, 70))}</td>
      <td>${esc(a.scope || 'view')}</td>
      <td>${fmtDateTime(a.requested_until)}</td>
      <td>${statusBadge(a.status, ACCESS_STATUS)}</td>
      <td>${fmtDateTime(a.created_at)}</td>
      <td class="text-right">${a.status === 'pending' ? `
        <button class="btn btn--ghost btn--sm" data-decision="approved" data-id="${esc(a.id)}">Approuver</button>
        <button class="btn btn--ghost btn--sm" data-decision="denied" data-id="${esc(a.id)}">Refuser</button>` : ''}</td>
    </tr>`;
  }

  async function decideAccess(decision, id) {
    const motif = await promptText(decision === 'approved' ? 'Motif d\'approbation' : 'Motif de refus', '');
    if (motif === null || motif.length < 3) { if (motif !== null) toast('Motif requis (min. 3 caractères)', 'error'); return; }
    try {
      await window.SigpeApi.post(`/api/v1/access-requests/${id}/decision`, { decision, motif });
      toast('Décision enregistrée', 'success');
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

  window.SigpeViews.documents = { render, title: 'Documents' };
})();
