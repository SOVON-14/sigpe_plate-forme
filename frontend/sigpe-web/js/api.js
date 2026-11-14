/* SIGPÉ — Client HTTP vers l'API Gateway (APISIX) */
(function () {
  'use strict';

  const base = window.SIGPE_CONFIG.apiBase;

  function uuid() {
    return crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
      const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
    });
  }

  class ApiError extends Error {
    constructor(status, problem) {
      super(problem?.detail || problem?.title || `Erreur HTTP ${status}`);
      this.status = status;
      this.code = problem?.code || `HTTP_${status}`;
      this.problem = problem;
    }
  }

  async function request(path, { method = 'GET', body, idempotent = false, query } = {}) {
    const url = new URL(base + path);
    if (query) Object.entries(query).forEach(([k, v]) => v != null && url.searchParams.set(k, v));

    const headers = { Accept: 'application/json' };
    const token = await window.SigpeAuth.getAccessToken();
    if (token) headers.Authorization = `Bearer ${token}`;
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    if (idempotent && method !== 'GET') headers['Idempotency-Key'] = uuid();

    const res = await fetch(url, { method, headers, body: body !== undefined ? JSON.stringify(body) : undefined });

    if (res.status === 204) return null;

    const text = await res.text();
    let data = null;
    if (text) {
      try { data = JSON.parse(text); } catch { data = { raw: text }; }
    }

    if (!res.ok) {
      const problem = res.headers.get('content-type')?.includes('problem+json') || data?.type?.includes('problem')
        ? data : { title: data?.message || res.statusText, status: res.status };
      throw new ApiError(res.status, problem);
    }
    return data;
  }

  window.SigpeApi = {
    get: (p, q) => request(p, { query: q }),
    post: (p, body, opts = {}) => request(p, { method: 'POST', body, idempotent: true, ...opts }),
    patch: (p, body, opts = {}) => request(p, { method: 'PATCH', body, idempotent: true, ...opts }),
    put: (p, body, opts = {}) => request(p, { method: 'PUT', body, idempotent: true, ...opts }),
    del: (p, opts = {}) => request(p, { method: 'DELETE', ...opts }),
    ApiError,
  };
})();
