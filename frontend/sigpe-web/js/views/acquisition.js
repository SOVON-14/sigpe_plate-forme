/* SIGPÉ — Vue Acquisitions (acquisition-service) : demandes, offres, livraisons, réceptions */
(function () {
  'use strict';
  const { icon, esc, statusBadge, fmtDate, fmtDateTime, fmtMoney, tableSkeleton, pagination, toast, modal, confirmDialog } = window.SigpeUI;

  const STATUS = {
    draft: 'Brouillon', pending_validation: 'En validation', validated: 'Validée',
    selected: 'Offre sélectionnée', ordered: 'Commandée', partially_received: 'Réception partielle',
    received: 'Réceptionnée', closed: 'Clôturée', rejected: 'Rejetée', cancelled: 'Annulée',
  };
  const PRIORITY = { low: 'Basse', normal: 'Normale', high: 'Haute', urgent: 'Urgente' };

  const state = { page: 1, perPage: 15, total: 0, fStatus: '', fPriority: '' };

  async function render() {
    const container = document.getElementById('view');
    container.innerHTML = `
      <div class="page-header">
        <div>
          <div class="page-header__title">Acquisitions</div>
          <div class="page-header__subtitle">Demandes d'acquisition, offres, livraisons et réceptions — Acquisition Service</div>
        </div>
      </div>
      <div class="card mb-6"><div class="card__body">
        <div class="form-grid">
          <div class="field"><label>Statut</label>
            <select class="select" id="f-status"><option value="">Tous</option>
              ${Object.entries(STATUS).map(([v, l]) => `<option value="${v}">${l}</option>`).join('')}
            </select></div>
          <div class="field"><label>Priorité</label>
            <select class="select" id="f-prio"><option value="">Toutes</option>
              ${Object.entries(PRIORITY).map(([v, l]) => `<option value="${v}">${l}</option>`).join('')}
            </select></div>
          <div class="field" style="align-self:end;display:flex;gap:var(--space-2)">
            <button class="btn btn--primary" id="btn-search">${icon('search')} Filtrer</button>
            <button class="btn btn--gold" id="btn-new">${icon('plus')} Nouvelle demande</button>
          </div>
        </div>
      </div></div>
      <div id="list">${tableSkeleton(7, 6)}</div>`;

    document.getElementById('btn-search').onclick = () => {
      state.fStatus = document.getElementById('f-status').value;
      state.fPriority = document.getElementById('f-prio').value;
      state.page = 1; loadList();
    };
    document.getElementById('btn-new').onclick = newRequestForm;
    await loadList();
  }

  async function loadList() {
    const wrap = document.getElementById('list');
    wrap.innerHTML = tableSkeleton(7, 6);
    try {
      const params = { page: state.page, per_page: state.perPage };
      if (state.fStatus) params.status = state.fStatus;
      if (state.fPriority) params.priority = state.fPriority;
      const res = await window.SigpeApi.get('/api/v1/acquisition-requests', params);
      const items = res?.data || res?.items || res || [];
      state.total = res?.meta?.total ?? res?.total ?? items.length;
      wrap.innerHTML = `
        <div class="card">
          <div class="table-wrap"><table class="table">
            <thead><tr><th>Référence</th><th>Objet</th><th>Priorité</th><th>Statut</th><th>Valeur estimée</th><th>Créée le</th><th></th></tr></thead>
            <tbody>
              ${items.length ? items.map(row).join('') : `<tr><td colspan="7"><div class="state" style="padding:var(--space-6)"><div class="state__icon">${icon('inbox')}</div><p class="muted">Aucune demande d'acquisition.</p></div></td></tr>`}
            </tbody>
          </table></div>
          ${pagination({ page: state.page, total: state.total, perPage: state.perPage, onPage: (p) => { state.page = p; loadList(); } })}
        </div>`;
      wrap.querySelectorAll('[data-detail]').forEach((b) => { b.onclick = () => showDetail(b.dataset.detail); });
      wrap.querySelectorAll('.page-btn[data-page]').forEach((b) => { b.onclick = () => { state.page = parseInt(b.dataset.page, 10); loadList(); }; });
    } catch (err) {
      wrap.innerHTML = `<div class="card"><div class="state"><div class="state__icon">${icon('alert')}</div><p class="muted">${esc(err.message)}</p></div></div>`;
    }
  }

  function row(r) {
    return `<tr>
      <td class="mono">${esc(r.reference)}</td>
      <td>${esc((r.title || '—').slice(0, 60))}</td>
      <td>${priorityBadge(r.priority, PRIORITY)}</td>
      <td>${statusBadge(r.status, STATUS)}</td>
      <td class="num">${r.total_estimated_value != null ? fmtMoney(r.total_estimated_value) : '—'}</td>
      <td>${fmtDate(r.created_at)}</td>
      <td class="text-right"><button class="btn btn--ghost btn--sm" data-detail="${esc(r.id)}">${icon('eye')} Ouvrir</button></td>
    </tr>`;
  }

  function priorityBadge(p, labels) {
    const map = { low: 'info', normal: '', high: 'warning', urgent: 'danger' };
    return `<span class="badge badge--${map[p] || ''}">${esc(labels[p] || p)}</span>`;
  }

  const ACTIONS = {
    draft: [['submit', 'Soumettre', 'btn--primary']],
    pending_validation: [['cancel', 'Annuler', 'btn--danger']],
    validated: [['select', 'Sélectionner l\'offre', 'btn--primary'], ['cancel', 'Annuler', 'btn--danger']],
    selected: [['order', 'Commander', 'btn--primary'], ['cancel', 'Annuler', 'btn--danger']],
    ordered: [['deliveries', 'Ajouter livraison', 'btn--primary'], ['cancel', 'Annuler', 'btn--danger']],
    partially_received: [['deliveries', 'Ajouter livraison', 'btn--primary'], ['close', 'Clôturer', 'btn--gold']],
    received: [['close', 'Clôturer', 'btn--gold']],
  };

  async function showDetail(id) {
    let d;
    try { d = await window.SigpeApi.get(`/api/v1/acquisition-requests/${id}`); }
    catch (err) { toast(err.message, 'error'); return; }
    const r = d?.data || d;
    const items = r.items || [];
    const deliveries = r.deliveries || [];
    const actions = (ACTIONS[r.status] || []).map(([a, l, c]) => [a, l, c]);

    modal({
      title: `Demande ${r.reference}`,
      size: 'lg',
      body: `
        <div class="form-grid mb-4">
          <div class="field"><label>Statut</label><div>${statusBadge(r.status, STATUS)}</div></div>
          <div class="field"><label>Priorité</label><div>${esc(PRIORITY[r.priority] || r.priority)}</div></div>
          <div class="field"><label>Objet</label><div>${esc(r.title)}</div></div>
          <div class="field"><label>Fournisseur</label><div>${esc(r.supplier_name || '—')} ${r.order_ref ? `· commande ${esc(r.order_ref)}` : ''}</div></div>
          <div class="field"><label>Valeur estimée totale</label><div>${r.total_estimated_value != null ? fmtMoney(r.total_estimated_value) : '—'}</div></div>
          <div class="field"><label>Workflow</label><div class="mono" style="font-size:var(--text-xs)">${esc(short(r.workflow_instance_id))}</div></div>
        </div>
        ${r.motif ? `<div class="field"><label>Motif</label><p>${esc(r.motif)}</p></div>` : ''}
        ${r.decision_comment ? `<div class="field"><label>Décision</label><p class="muted">« ${esc(r.decision_comment)} »</p></div>` : ''}
        <div class="field"><label>Lignes (${items.length})</label>
          <div class="table-wrap"><table class="table table--compact">
            <thead><tr><th>Article</th><th class="num">Qté</th><th>Unité</th><th class="num">Valeur unit.</th></tr></thead>
            <tbody>${items.length ? items.map(i => `<tr><td>${esc(i.description)}</td><td class="num">${i.quantity_ordered}</td><td>${esc(i.unit || '—')}</td><td class="num">${fmtMoney(i.estimated_unit_value)}</td></tr>`).join('') : '<tr><td colspan="4" class="muted">Aucune ligne</td></tr>'}</tbody>
          </table></div>
        </div>
        ${deliveries.length ? `<div class="field"><label>Livraisons (${deliveries.length})</label>
          <div class="table-wrap"><table class="table table--compact">
            <thead><tr><th>Livraison</th><th>Statut</th><th>Prévue</th><th>Reçue le</th></tr></thead>
            <tbody>${deliveries.map(dl => `<tr><td class="mono">${esc(short(dl.id))}</td><td>${esc(dl.status || '—')}</td><td>${fmtDate(dl.expected_at || dl.scheduled_at)}</td><td>${fmtDate(dl.received_at)}</td></tr>`).join('')}</tbody>
          </table></div>
        </div>` : ''}`,
      footer: actions.map(([a, l, c]) => `<button class="btn ${c}" data-act="${a}">${esc(l)}</button>`).join(''),
      onOpen: (bd) => {
        bd.querySelectorAll('[data-act]').forEach((b) => {
          b.onclick = async () => {
            const act = b.dataset.act;
            bd.remove();
            try {
              if (act === 'select') return selectOffer(id);
              if (act === 'deliveries') return addDelivery(id);
              if (act === 'cancel') {
                const ok = await confirmDialog('Annuler la demande', 'Annuler cette demande d\'acquisition ?', { confirmLabel: 'Annuler la demande', danger: true });
                if (ok !== 'ok') return;
              }
              if (act === 'close') {
                const ok = await confirmDialog('Clôturer', 'Clôturer cette demande ? (des biens acceptés non enregistrés bloqueront la clôture)', { confirmLabel: 'Clôturer' });
                if (ok !== 'ok') return;
              }
              const body = {};
              if (act === 'order') {
                body.supplier_name = await promptText('Nom du fournisseur', '');
                if (!body.supplier_name) return;
                const ref = await promptText('Référence de commande (optionnel)', '');
                if (ref) body.order_ref = ref;
              }
              if (act === 'submit' || act === 'cancel') {
                const motif = await promptText(act === 'cancel' ? 'Motif d\'annulation' : 'Commentaire (optionnel)', '');
                if (motif) body.motif = motif;
              }
              await window.SigpeApi.post(`/api/v1/acquisition-requests/${id}/${act}`, body);
              toast('Demande mise à jour', 'success');
              state.page = 1; loadList();
            } catch (err) { toast(err.message, 'error'); }
          };
        });
      },
    });
  }

  function selectOffer(id) {
    return modal({
      title: 'Sélection de l\'offre retenue', size: 'lg',
      body: `
        <div class="form-grid">
          <div class="field"><label>Fournisseur *</label><input class="input" id="so-name"></div>
          <div class="field"><label>Référence fournisseur</label><input class="input" id="so-ref"></div>
          <div class="field"><label>Montant total estimé</label><input class="input" type="number" min="0" step="0.01" id="so-total"></div>
          <div class="field"><label>Justification de sélection *</label><textarea class="textarea" id="so-just" rows="3" maxlength="2000"></textarea></div>
        </div>`,
      footer: `<button class="btn btn--outline" data-close>Annuler</button><button class="btn btn--primary" id="so-submit">Sélectionner</button>`,
      onOpen: (bd) => {
        bd.querySelector('#so-submit').onclick = async () => {
          const body = {
            supplier_name: bd.querySelector('#so-name').value.trim(),
            justification: bd.querySelector('#so-just').value.trim(),
          };
          const ref = bd.querySelector('#so-ref').value.trim(); if (ref) body.supplier_ref = ref;
          const tot = bd.querySelector('#so-total').value; if (tot) body.total_estimated_value = parseFloat(tot);
          if (!body.supplier_name || !body.justification) { toast('Fournisseur et justification requis', 'error'); return; }
          try {
            await window.SigpeApi.post(`/api/v1/acquisition-requests/${id}/select`, body);
            document.querySelector('.modal-backdrop')?.remove();
            toast('Offre sélectionnée', 'success');
            state.page = 1; loadList();
          } catch (err) { toast(err.message, 'error'); }
        };
      },
    });
  }

  function addDelivery(id) {
    return modal({
      title: 'Nouvelle livraison',
      body: `
        <div class="form-grid">
          <div class="field"><label>Date prévue *</label><input class="input" type="date" id="dl-date"></div>
          <div class="field"><label>Transporteur / référence</label><input class="input" id="dl-carrier"></div>
          <div class="field"><label>Notes</label><textarea class="textarea" id="dl-notes" rows="2" maxlength="2000"></textarea></div>
        </div>`,
      footer: `<button class="btn btn--outline" data-close>Annuler</button><button class="btn btn--primary" id="dl-submit">Créer</button>`,
      onOpen: (bd) => {
        bd.querySelector('#dl-submit').onclick = async () => {
          const body = { expected_at: bd.querySelector('#dl-date').value };
          const carrier = bd.querySelector('#dl-carrier').value.trim(); if (carrier) body.carrier = carrier;
          const notes = bd.querySelector('#dl-notes').value.trim(); if (notes) body.notes = notes;
          if (!body.expected_at) { toast('Date prévue requise', 'error'); return; }
          try {
            await window.SigpeApi.post(`/api/v1/acquisition-requests/${id}/deliveries`, body);
            document.querySelector('.modal-backdrop')?.remove();
            toast('Livraison créée', 'success');
            state.page = 1; loadList();
          } catch (err) { toast(err.message, 'error'); }
        };
      },
    });
  }

  function newRequestForm() {
    modal({
      title: 'Nouvelle demande d\'acquisition', size: 'lg',
      body: `
        <div class="form-grid">
          <div class="field"><label>Objet *</label><input class="input" id="nr-title" maxlength="255"></div>
          <div class="field"><label>Priorité</label>
            <select class="select" id="nr-prio">${Object.entries(PRIORITY).map(([v, l]) => `<option value="${v}">${l}</option>`).join('')}</select></div>
          <div class="field span-2"><label>Motif</label><textarea class="textarea" id="nr-motif" rows="2" maxlength="2000"></textarea></div>
          <div class="field"><label>Date de besoin</label><input class="input" type="date" id="nr-need"></div>
          <div class="field span-2"><label>Lignes d'articles *</label>
            <div id="nr-lines"></div>
            <button class="btn btn--outline btn--sm mt-2" id="nr-add-line">${icon('plus')} Ajouter une ligne</button>
          </div>
        </div>`,
      footer: `<button class="btn btn--outline" data-close>Annuler</button><button class="btn btn--primary" id="nr-submit">Créer le brouillon</button>`,
      onOpen: (bd) => {
        const linesBox = bd.querySelector('#nr-lines');
        const addLine = () => {
          const i = linesBox.children.length;
          linesBox.insertAdjacentHTML('beforeend', `
            <div class="card mb-2" data-line style="border-radius:var(--radius)">
              <div class="card__body" style="padding:var(--space-3)">
                <div class="form-grid">
                  <div class="field span-2"><label>Description *</label><input class="input" data-l-desc maxlength="500"></div>
                  <div class="field"><label>Qté *</label><input class="input" type="number" min="0" step="0.01" data-l-qty></div>
                  <div class="field"><label>Unité</label><input class="input" data-l-unit maxlength="50"></div>
                  <div class="field"><label>Valeur unit. estimée</label><input class="input" type="number" min="0" step="0.01" data-l-val></div>
                  <div class="field"><label>Spécifications</label><input class="input" data-l-spec maxlength="4000"></div>
                </div>
                <button class="btn btn--ghost btn--sm" data-del>Supprimer</button>
              </div>
            </div>`);
          linesBox.lastElementChild.querySelector('[data-del]').onclick = (e) => e.target.closest('[data-line]').remove();
        };
        addLine(); addLine();
        bd.querySelector('#nr-add-line').onclick = addLine;
        bd.querySelector('#nr-submit').onclick = async () => {
          const items = [];
          for (const line of linesBox.querySelectorAll('[data-line]')) {
            const desc = line.querySelector('[data-l-desc]').value.trim();
            const qty = parseFloat(line.querySelector('[data-l-qty]').value);
            if (!desc || !qty) continue;
            const it = { description: desc, quantity_ordered: qty };
            const unit = line.querySelector('[data-l-unit]').value.trim(); if (unit) it.unit = unit;
            const val = line.querySelector('[data-l-val]').value; if (val) it.estimated_unit_value = parseFloat(val);
            const spec = line.querySelector('[data-l-spec]').value.trim(); if (spec) it.specifications = spec;
            items.push(it);
          }
          const body = {
            title: bd.querySelector('#nr-title').value.trim(),
            priority: bd.querySelector('#nr-prio').value,
            items,
          };
          const motif = bd.querySelector('#nr-motif').value.trim(); if (motif) body.motif = motif;
          const need = bd.querySelector('#nr-need').value; if (need) body.needed_by = new Date(need).toISOString();
          if (!body.title || !items.length) { toast('Objet et au moins une ligne valides requis', 'error'); return; }
          try {
            await window.SigpeApi.post('/api/v1/acquisition-requests', body);
            bd.remove();
            toast('Demande créée', 'success');
            state.page = 1; loadList();
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

  window.SigpeViews.acquisition = { render, title: 'Acquisitions' };
})();
