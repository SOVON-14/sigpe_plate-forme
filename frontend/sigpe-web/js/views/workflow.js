/* SIGPÉ — Vue Workflows (workflow-service) : circuits, instances, décisions de tâches */
(function () {
  'use strict';
  const { icon, esc, statusBadge, fmtDate, fmtDateTime, tableSkeleton, pagination, toast, modal, confirmDialog } = window.SigpeUI;

  const DOMAINS = {
    acquisition: 'Acquisition', movement: 'Mouvement', transfer: 'Transfert', loan: 'Prêt',
    reforme: 'Réforme', maintenance: 'Maintenance', inventory: 'Inventaire',
    document: 'Document', finance: 'Finance', other: 'Autre',
  };
  const WF_STATUS = { draft: 'Brouillon', active: 'Actif', archived: 'Archivé' };
  const INST_STATUS = {
    pending: 'En attente', in_progress: 'En cours', approved: 'Approuvée',
    rejected: 'Rejetée', correction_requested: 'Correction demandée', cancelled: 'Annulée',
  };
  const TASK_STATUS = { pending: 'En attente', approved: 'Approuvée', rejected: 'Rejetée', correction_requested: 'Correction', cancelled: 'Annulée' };

  const state = { tab: 'instances', page: 1, perPage: 15, total: 0 };

  async function render() {
    const container = document.getElementById('view');
    container.innerHTML = `
      <div class="page-header">
        <div>
          <div class="page-header__title">Circuits de validation</div>
          <div class="page-header__subtitle">Workflows nationaux, instances et décisions — Workflow Service</div>
        </div>
      </div>
      <div class="btn-row mb-4">
        <button class="btn ${state.tab === 'instances' ? 'btn--primary' : 'btn--outline'} btn--sm" data-tab="instances">Mes instances</button>
        <button class="btn ${state.tab === 'templates' ? 'btn--primary' : 'btn--outline'} btn--sm" data-tab="templates">Circuits (templates)</button>
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
      if (state.tab === 'instances') await loadInstances(wrap);
      else await loadTemplates(wrap);
    } catch (err) {
      wrap.innerHTML = `<div class="card"><div class="state"><div class="state__icon">${icon('alert')}</div><p class="muted">${esc(err.message)}</p></div></div>`;
    }
  }

  /* ================= Instances ================= */

  async function loadInstances(wrap) {
    document.getElementById('tab-filters').innerHTML = '';
    const res = await window.SigpeApi.get('/api/v1/workflow-instances', { page: state.page, per_page: state.perPage });
    const items = res?.data || res?.items || res || [];
    state.total = res?.meta?.total ?? res?.total ?? items.length;
    wrap.innerHTML = `
      <div class="card">
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Opération</th><th>Domaine</th><th>Statut</th><th>Progression</th><th>Référence</th><th>Créée le</th><th></th></tr></thead>
          <tbody>
            ${items.length ? items.map(instRow).join('') : `<tr><td colspan="7"><div class="state" style="padding:var(--space-6)"><div class="state__icon">${icon('inbox')}</div><p class="muted">Aucune instance.</p></div></td></tr>`}
          </tbody>
        </table></div>
        ${pagination({ page: state.page, total: state.total, perPage: state.perPage, onPage: (p) => { state.page = p; loadTab(); } })}
      </div>`;
    wrap.querySelectorAll('[data-detail]').forEach((b) => { b.onclick = () => showInstance(b.dataset.detail); });
    wrap.querySelectorAll('.page-btn[data-page]').forEach((b) => { b.onclick = () => { state.page = parseInt(b.dataset.page, 10); loadTab(); }; });
  }

  function instRow(i) {
    const p = i.progress || {};
    const progress = p.total_groups ? `${p.completed_groups || 0}/${p.total_groups} étapes` : '—';
    return `<tr>
      <td class="mono" style="font-size:var(--text-xs)">${esc(short(i.operation_ref))}</td>
      <td>${esc(DOMAINS[i.operation_type] || i.operation_type)}</td>
      <td>${statusBadge(i.status, INST_STATUS)}</td>
      <td>${esc(progress)}</td>
      <td class="mono" style="font-size:var(--text-xs)">${esc(short(i.workflow_id))}</td>
      <td>${fmtDate(i.created_at)}</td>
      <td class="text-right"><button class="btn btn--ghost btn--sm" data-detail="${esc(i.id)}">${icon('eye')} Ouvrir</button></td>
    </tr>`;
  }

  async function showInstance(id) {
    let d;
    try { d = await window.SigpeApi.get(`/api/v1/workflow-instances/${id}`); }
    catch (err) { toast(err.message, 'error'); return; }
    const inst = d?.data || d;
    const tasks = inst.tasks || [];

    modal({
      title: `Instance ${short(id)}`,
      size: 'lg',
      body: `
        <div class="form-grid mb-4">
          <div class="field"><label>Statut</label><div>${statusBadge(inst.status, INST_STATUS)}</div></div>
          <div class="field"><label>Domaine</label><div>${esc(DOMAINS[inst.operation_type] || inst.operation_type)}</div></div>
          <div class="field"><label>Opération</label><div class="mono">${esc(inst.operation_ref || '—')}</div></div>
          <div class="field"><label>Version du circuit</label><div>v${inst.workflow_version ?? '—'}</div></div>
          <div class="field"><label>Issue</label><div>${esc(inst.decided_outcome || '—')}</div></div>
          <div class="field"><label>Décidé le</label><div>${fmtDateTime(inst.decided_at)}</div></div>
        </div>
        <div class="field"><label>Tâches (${tasks.length})</label>
          <div class="card" style="border-radius:var(--radius)">
            ${tasks.length ? tasks.map((t) => `
              <div class="list-item">
                <div class="list-item__icon" style="${t.status === 'approved' ? 'background:var(--color-success-soft);color:var(--color-success)' : t.status === 'rejected' ? 'background:var(--color-danger-soft);color:var(--color-danger)' : ''}">${icon(t.status === 'approved' ? 'check' : t.status === 'rejected' ? 'alert' : 'info')}</div>
                <div class="list-item__body">
                  <div class="list-item__title">${esc(t.step_name || t.step_id)} — groupe ${t.step_group}</div>
                  <div class="list-item__meta">${esc(t.assignee_type)} · ${esc(short(t.assignee_ref))}${t.is_delegated ? ' · via délégation' : ''}${t.sla_due_at ? ' · SLA ' + fmtDateTime(t.sla_due_at) : ''}</div>
                  ${t.decision_comment ? `<div class="list-item__meta" style="font-style:italic">« ${esc(t.decision_comment)} »</div>` : ''}
                </div>
                ${statusBadge(t.status, TASK_STATUS)}
                ${t.status === 'pending' ? `<button class="btn btn--primary btn--sm" data-decide="${esc(t.id)}">Décider</button>` : ''}
              </div>`).join('') : '<div class="state" style="padding:var(--space-4)"><p class="muted">Aucune tâche.</p></div>'}
          </div>
        </div>`,
      footer: !['approved', 'rejected', 'cancelled', 'correction_requested'].includes(inst.status)
        ? `<button class="btn btn--danger" data-cancel>${icon('close')} Annuler l'instance</button>` : '',
      onOpen: (bd) => {
        bd.querySelectorAll('[data-decide]').forEach((b) => {
          b.onclick = async () => {
            bd.remove();
            await decideTask(b.dataset.decide);
          };
        });
        const cancelBtn = bd.querySelector('[data-cancel]');
        if (cancelBtn) cancelBtn.onclick = async () => {
          bd.remove();
          const motif = await promptText('Motif d\'annulation', '');
          if (!motif) return;
          try {
            await window.SigpeApi.post(`/api/v1/workflow-instances/${id}/cancel`, { motif });
            toast('Instance annulée', 'info');
            loadTab();
          } catch (err) { toast(err.message, 'error'); }
        };
      },
    });
  }

  async function decideTask(taskId) {
    const decision = await choiceDialog('Décision sur la tâche', {
      approve: 'Approuver', reject: 'Rejeter', request_correction: 'Demander correction',
    });
    if (!decision) return;
    const needsComment = decision !== 'approve';
    const comment = await promptText(
      needsComment ? 'Commentaire (obligatoire pour rejet/correction)' : 'Commentaire (optionnel)',
      ''
    );
    if (comment === null) return;
    if (needsComment && !comment) { toast('Commentaire obligatoire', 'error'); return; }
    try {
      await window.SigpeApi.post(`/api/v1/tasks/${taskId}/decision`, { decision, ...(comment ? { comment } : {}) });
      toast('Décision enregistrée', 'success');
      loadTab();
    } catch (err) { toast(err.message, 'error'); }
  }

  /* ================= Templates ================= */

  async function loadTemplates(wrap) {
    document.getElementById('tab-filters').innerHTML = `
      <div class="card mb-6"><div class="card__body" style="display:flex;justify-content:flex-end">
        <button class="btn btn--gold" id="btn-new">${icon('plus')} Nouveau circuit</button>
      </div></div>`;
    document.getElementById('btn-new').onclick = newWorkflowForm;

    const res = await window.SigpeApi.get('/api/v1/workflows', { page: state.page, per_page: state.perPage });
    const items = res?.data || res?.items || res || [];
    state.total = res?.meta?.total ?? res?.total ?? items.length;
    wrap.innerHTML = `
      <div class="card">
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Code</th><th>Nom</th><th>Domaine</th><th>Portée</th><th>Statut</th><th>Étapes</th></tr></thead>
          <tbody>
            ${items.length ? items.map(wfRow).join('') : `<tr><td colspan="6"><div class="state" style="padding:var(--space-6)"><div class="state__icon">${icon('inbox')}</div><p class="muted">Aucun circuit.</p></div></td></tr>`}
          </tbody>
        </table></div>
        ${pagination({ page: state.page, total: state.total, perPage: state.perPage, onPage: (p) => { state.page = p; loadTab(); } })}
      </div>`;
    wrap.querySelectorAll('.page-btn[data-page]').forEach((b) => { b.onclick = () => { state.page = parseInt(b.dataset.page, 10); loadTab(); }; });
  }

  function wfRow(w) {
    return `<tr>
      <td class="mono">${esc(w.code)}</td>
      <td><strong>${esc(w.name)}</strong></td>
      <td>${esc(DOMAINS[w.domain] || w.domain)}</td>
      <td>${w.institution_id ? '<span class="badge badge--info">institution</span>' : '<span class="badge badge--gold">national</span>'}</td>
      <td>${statusBadge(w.status, WF_STATUS)}</td>
      <td class="num">${(w.steps || []).length}</td>
    </tr>`;
  }

  function newWorkflowForm() {
    modal({
      title: 'Nouveau circuit de validation', size: 'lg',
      body: `
        <div class="alert alert--info" style="margin-bottom:var(--space-4)">Un circuit <strong>national</strong> (institution vide) exige la permission centrale <code>workflow.templates.national</code>. Sinon le circuit est rattaché à votre institution.</div>
        <div class="form-grid">
          <div class="field"><label>Code * (minuscules, -, _)</label><input class="input mono" id="w-code" pattern="[a-z0-9_-]+"></div>
          <div class="field"><label>Nom *</label><input class="input" id="w-name" maxlength="255"></div>
          <div class="field"><label>Domaine *</label>
            <select class="select" id="w-domain">${Object.entries(DOMAINS).map(([v, l]) => `<option value="${v}">${l}</option>`).join('')}</select></div>
          <div class="field"><label>Institution (UUID — vide = national)</label><input class="input mono" id="w-inst"></div>
          <div class="field span-2"><label>Description</label><textarea class="textarea" id="w-desc" rows="2" maxlength="2000"></textarea></div>
          <div class="field span-2"><label>Étapes (JSON)</label>
            <textarea class="textarea mono" id="w-steps" rows="8" placeholder='[{"step_group":1,"name":"Validation hiérarchique","assignee_type":"role","assignee_ref":"manager","mode":"all"}]'></textarea>
            <div class="hint">step_group : groupe d'étapes · assignee_type : role|position|account · mode : all|any</div>
          </div>
        </div>`,
      footer: `<button class="btn btn--outline" data-close>Annuler</button><button class="btn btn--primary" id="w-submit">Créer</button>`,
      onOpen: (bd) => {
        bd.querySelector('#w-submit').onclick = async () => {
          let steps;
          try { steps = JSON.parse(bd.querySelector('#w-steps').value); }
          catch { toast('JSON des étapes invalide', 'error'); return; }
          const body = {
            code: bd.querySelector('#w-code').value.trim(),
            name: bd.querySelector('#w-name').value.trim(),
            domain: bd.querySelector('#w-domain').value,
            steps,
          };
          const inst = bd.querySelector('#w-inst').value.trim();
          if (inst) body.institution_id = inst;
          const desc = bd.querySelector('#w-desc').value.trim(); if (desc) body.description = desc;
          if (!body.code || !body.name || !Array.isArray(steps) || !steps.length) { toast('Champs obligatoires manquants', 'error'); return; }
          try {
            await window.SigpeApi.post('/api/v1/workflows', body);
            bd.remove();
            toast('Circuit créé', 'success');
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

  window.SigpeViews.workflow = { render, title: 'Circuits de validation' };
})();
