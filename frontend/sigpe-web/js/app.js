/* SIGPÉ — Application shell : routage hash, layout, page de connexion */
(function () {
  'use strict';
  const { icon, esc, initials, toast } = window.SigpeUI;
  const cfg = window.SIGPE_CONFIG;

  /* ---------- Routes ---------- */
  const routes = [
    { path: '#/dashboard', title: 'Tableau de bord', icon: 'dashboard', perm: 'reporting.read', view: () => window.SigpeViews.dashboard },
    { path: '#/assets', title: 'Registre des actifs', icon: 'assets', perm: 'asset.read', view: () => window.SigpeViews.assets },
    { path: '#/notifications', title: 'Notifications', icon: 'notifications', perm: 'notification.read', view: () => window.SigpeViews.notifications },
    { path: '#/movements', title: 'Mouvements', icon: 'movements', perm: 'movement.read', soon: true },
    { path: '#/maintenance', title: 'Maintenance', icon: 'maintenance', perm: 'maintenance.read', soon: true },
    { path: '#/inventory', title: 'Inventaires', icon: 'inventory', perm: 'inventory.read', soon: true },
    { path: '#/documents', title: 'Documents', icon: 'documents', perm: 'document.read', soon: true },
    { path: '#/finance', title: 'Finance', icon: 'finance', perm: 'finance.read', soon: true },
    { path: '#/acquisitions', title: 'Acquisitions', icon: 'acquisition', perm: 'acquisition.read', soon: true },
    { path: '#/workflow', title: 'Circuits de validation', icon: 'workflow', perm: 'workflow.read', soon: true },
    { path: '#/audit', title: 'Audit', icon: 'audit', perm: 'audit.read', soon: true },
    { path: '#/reports', title: 'Rapports', icon: 'reporting', perm: 'reporting.read', soon: true },
    { path: '#/organization', title: 'Organisation', icon: 'organization', perm: 'organization.read', soon: true },
  ];

  /* ---------- Page de connexion ---------- */
  function renderLogin(errorMsg = '') {
    document.getElementById('app').innerHTML = `
      <div class="auth-page">
        <div class="auth-brand">
          <div class="auth-brand__pattern"></div>
          <div class="auth-brand__logo" style="position:relative">
            <div class="sidebar__logo">S</div>
            <div>
              <div class="auth-brand__name">${esc(cfg.appName)}</div>
              <div class="auth-brand__tag">${esc(cfg.appSubtitle)}</div>
            </div>
          </div>
          <div class="auth-brand__hero">
            <h1>La gestion du <em>patrimoine de l'État</em>, enfin unifiée.</h1>
            <p>Registre des actifs, inventaires, mouvements, maintenance, finance et audit — une plateforme nationale, traçable et sécurisée.</p>
            <div class="auth-points">
              ${[
                '13 microservices autonomes, un contrat unique',
                'Traçabilité complète : outbox, inbox, journal d\'audit immuable',
                'Sécurité OIDC/RBAC + cloisonnement par institution',
              ].map((t) => `<div class="auth-point">${icon('check')}<span>${t}</span></div>`).join('')}
            </div>
          </div>
          <div class="auth-brand__foot">République Togolaise — Ministère de l'Économie et des Finances</div>
        </div>
        <div class="auth-panel">
          <div class="auth-card">
            <div class="auth-card__logo">
              <div class="sidebar__logo">S</div>
              <div><strong>${esc(cfg.appName)}</strong><br><span class="muted" style="font-size:var(--text-xs)">${esc(cfg.appSubtitle)}</span></div>
            </div>
            <h2>Connexion</h2>
            <p class="auth-card__sub">Accédez à votre espace de gestion patrimoniale.</p>
            ${errorMsg ? `<div class="alert alert--danger auth-card__error">${icon('alert')} ${esc(errorMsg)}</div>` : ''}
            <button class="btn btn--primary" id="btn-login">
              ${icon('identity')} Se connecter avec Keycloak
            </button>
            <p class="auth-card__alt">Authentification unique — OpenID Connect / PKCE</p>
            <div class="auth-security">${icon('audit')} Session sécurisée · JWT signé · Permissions par institution</div>
          </div>
        </div>
      </div>`;
    document.getElementById('btn-login').onclick = () => window.SigpeAuth.login();
  }

  /* ---------- Layout applicatif ---------- */
  function renderShell(activeHash) {
    const user = window.SigpeAuth.currentUser() || {};
    const permissions = user.permissions || [];
    const isNational = !user.institution_id;
    const displayName = [user.given_name, user.family_name].filter(Boolean).join(' ') || user.preferred_username || user.sub || 'Utilisateur';

    const canSee = (perm) => permissions.includes('*') || permissions.includes(perm);

    const nav = routes.map((r) => {
      const active = window.location.hash === r.path || (!activeHash && r.path === '#/dashboard' && (window.location.hash === '' || window.location.hash === '#/'));
      if (r.soon) {
        return `<button class="nav-item ${active ? 'is-active' : ''}" data-soon="${esc(r.title)}" title="Module en cours de développement">
          ${icon(r.icon)}<span>${esc(r.title)}</span>
          <span style="margin-left:auto;font-size:.6rem;opacity:.6">bientôt</span>
        </button>`;
      }
      if (!canSee(r.perm)) return '';
      return `<a class="nav-item ${active ? 'is-active' : ''}" href="${r.path}">${icon(r.icon)}<span>${esc(r.title)}</span></a>`;
    }).join('');

    const route = routes.find((r) => window.location.hash === r.path) || routes[0];
    const title = route.soon ? route.title : (route.view()?.title || route.title);

    document.getElementById('app').innerHTML = `
      <div class="app">
        <aside class="sidebar" id="sidebar">
          <div class="sidebar__brand">
            <div class="sidebar__logo">S</div>
            <div>
              <div class="sidebar__title">${esc(cfg.appName)}</div>
              <div class="sidebar__subtitle">${esc(cfg.appSubtitle)}</div>
            </div>
          </div>
          <nav class="sidebar__nav">
            <div class="nav-section">
              <div class="nav-section__label">Modules</div>
              ${nav}
            </div>
          </nav>
          <div class="sidebar__footer">
            <div class="user-chip">
              <div class="user-chip__avatar">${esc(initials(displayName))}</div>
              <div style="min-width:0">
                <div class="user-chip__name" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(displayName)}</div>
                <div class="user-chip__role">${isNational ? 'Administration nationale' : 'Agent institutionnel'}</div>
              </div>
            </div>
            <button class="btn-ghost-light" id="btn-logout">${icon('logout')} Déconnexion</button>
          </div>
        </aside>
        <div class="sidebar-backdrop hidden" id="sidebar-backdrop"></div>
        <div class="main">
          <header class="topbar">
            <button class="topbar__menu" id="btn-menu" aria-label="Menu">${icon('menu')}</button>
            <div class="topbar__title">${esc(title)}</div>
            <div class="topbar__spacer"></div>
            ${user.institution_id ? `<span class="topbar__institution mono">${esc(user.institution_id)}</span>` : '<span class="topbar__institution">Portée nationale</span>'}
            <a class="topbar__notif" href="#/notifications" aria-label="Notifications">${icon('bell')}<span class="badge-dot hidden" id="notif-dot"></span></a>
          </header>
          <main class="content" id="view"></main>
        </div>
      </div>`;

    bindShellEvents();
    loadRoute();
    refreshUnreadBadge();
  }

  function bindShellEvents() {
    document.getElementById('btn-logout').onclick = () => window.SigpeAuth.logout();
    document.getElementById('btn-menu').onclick = () => {
      document.getElementById('sidebar').classList.add('is-open');
      document.getElementById('sidebar-backdrop').classList.remove('hidden');
    };
    document.getElementById('sidebar-backdrop').onclick = closeSidebar;
    document.querySelectorAll('[data-soon]').forEach((b) => {
      b.onclick = () => toast(`${b.dataset.soon} — module en cours de développement`, 'info');
    });
  }
  function closeSidebar() {
    document.getElementById('sidebar')?.classList.remove('is-open');
    document.getElementById('sidebar-backdrop')?.classList.add('hidden');
  }

  /* ---------- Route active ---------- */
  async function loadRoute() {
    closeSidebar();
    const view = document.getElementById('view');
    const hash = window.location.hash || '#/dashboard';
    const route = routes.find((r) => r.path === hash);

    if (route?.soon) {
      view.innerHTML = `
        <div class="card"><div class="state" style="padding:var(--space-8)">
          <div class="state__icon">${icon(route.icon)}</div>
          <h3>${esc(route.title)}</h3>
          <p class="muted">Ce module est en cours de développement. Il sera disponible prochainement.</p>
          <a class="btn btn--primary" href="#/dashboard">Retour au tableau de bord</a>
        </div></div>`;
      return;
    }

    const def = route?.view ? route.view() : window.SigpeViews.dashboard;
    await window.SigpeUI.renderView(view, def.render);
  }

  async function refreshUnreadBadge() {
    try {
      const res = await window.SigpeApi.get('/api/v1/notifications/unread-count');
      const count = res?.unread_count ?? res?.count ?? res?.data?.unread_count ?? 0;
      const dot = document.getElementById('notif-dot');
      if (dot && count > 0) { dot.textContent = count > 99 ? '99+' : count; dot.classList.remove('hidden'); }
    } catch { /* silencieux : badge non critique */ }
  }

  /* ---------- Boot ---------- */
  async function boot() {
    try {
      await window.SigpeAuth.handleRedirectCallback();
    } catch (err) {
      renderLogin('Authentification échouée : ' + err.message);
      return;
    }

    if (!window.SigpeAuth.isAuthenticated()) {
      renderLogin();
      return;
    }

    renderShell();
    window.addEventListener('hashchange', loadRoute);
  }

  document.addEventListener('DOMContentLoaded', boot);
})();
