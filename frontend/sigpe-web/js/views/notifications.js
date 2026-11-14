/* SIGPÉ — Vue Notifications (notification-service) */
(function () {
  'use strict';
  const { icon, esc, statusBadge, priorityBadge, fmtDateTime, tableSkeleton, pagination, toast } = window.SigpeUI;

  const state = { page: 1, perPage: 15, unreadOnly: false };

  async function fetchNotifications() {
    const res = await window.SigpeApi.get('/api/v1/notifications', {
      page: state.page, per_page: state.perPage, unread: state.unreadOnly ? 1 : undefined,
    });
    const items = res?.data || res?.items || res || [];
    state.total = res?.meta?.total ?? res?.total ?? items.length;
    return items;
  }

  async function render() {
    const container = document.getElementById('view');
    container.innerHTML = `
      <div class="page-header">
        <div>
          <div class="page-header__title">Notifications</div>
          <div class="page-header__subtitle">Alertes métier, accusés de prise en compte et relances</div>
        </div>
        <div class="btn-row">
          <button class="btn ${state.unreadOnly ? 'btn--primary' : 'btn--outline'} btn--sm" id="btn-unread">Non lues</button>
          <button class="btn btn--outline btn--sm" id="btn-refresh">${icon('refresh')} Actualiser</button>
        </div>
      </div>
      <div id="notif-table">${tableSkeleton(6, 4)}</div>`;

    document.getElementById('btn-unread').onclick = () => { state.unreadOnly = !state.unreadOnly; state.page = 1; render(); };
    document.getElementById('btn-refresh').onclick = () => load();
    await load();
  }

  async function load() {
    const wrap = document.getElementById('notif-table');
    wrap.innerHTML = tableSkeleton(6, 4);
    try {
      const items = await fetchNotifications();
      wrap.innerHTML = `
        <div class="card">
          <div class="table-wrap"><table class="table">
            <thead><tr><th>Priorité</th><th>Objet</th><th>Canal</th><th>Statut</th><th>Date</th><th></th></tr></thead>
            <tbody>
              ${items.length ? items.map(notifRow).join('') : `<tr><td colspan="6"><div class="state" style="padding:var(--space-6)">
                <div class="state__icon">${icon('inbox')}</div><p class="muted">Aucune notification.</p></div></td></tr>`}
            </tbody>
          </table></div>
          ${pagination({ page: state.page, total: state.total, perPage: state.perPage, onPage: (p) => { state.page = p; load(); } })}
        </div>`;
      wrap.querySelectorAll('[data-read]').forEach((b) => b.onclick = () => markRead(b.dataset.read));
      wrap.querySelectorAll('[data-ack]').forEach((b) => b.onclick = () => acknowledge(b.dataset.ack));
      wrap.querySelectorAll('.page-btn[data-page]').forEach((b) => { b.onclick = () => { state.page = parseInt(b.dataset.page, 10); load(); }; });
    } catch (err) {
      wrap.innerHTML = `<div class="card"><div class="state"><div class="state__icon">${icon('alert')}</div><p class="muted">${esc(err.message)}</p></div></div>`;
    }
  }

  function notifRow(n) {
    const unread = !n.read_at && !n.is_read;
    return `<tr style="${unread ? 'background:var(--color-primary-soft)' : ''}">
      <td>${priorityBadge(n.priority)}</td>
      <td><strong>${esc(n.title || n.subject || '—')}</strong><br><span class="muted" style="font-size:var(--text-xs)">${esc((n.body || n.message || '').slice(0, 90))}</span></td>
      <td>${esc(n.channel || 'in_app')}</td>
      <td>${statusBadge(n.status)}</td>
      <td>${fmtDateTime(n.created_at)}</td>
      <td class="text-right">
        ${unread ? `<button class="btn btn--ghost btn--sm" data-read="${esc(n.id)}">${icon('check')} Lire</button>` : ''}
        ${n.priority === 'critical' && !n.acknowledged_at ? `<button class="btn btn--gold btn--sm" data-ack="${esc(n.id)}">Accuser réception</button>` : ''}
      </td>
    </tr>`;
  }

  async function markRead(id) {
    try {
      await window.SigpeApi.post(`/api/v1/notifications/${id}/read`, {});
      toast('Notification marquée comme lue', 'success');
      load();
    } catch (err) { toast(err.message, 'error'); }
  }

  async function acknowledge(id) {
    const ok = await window.SigpeUI.confirmDialog('Accusé de réception', 'Confirmez-vous avoir pris connaissance de cette notification critique ?', { confirmLabel: 'Accuser réception' });
    if (ok !== 'ok') return;
    try {
      await window.SigpeApi.post(`/api/v1/notifications/${id}/acknowledge`, {});
      toast('Accusé de réception enregistré', 'success');
      load();
    } catch (err) { toast(err.message, 'error'); }
  }

  window.SigpeViews.notifications = { render, title: 'Notifications' };
})();
