/* SIGPÉ — Authentification Keycloak (Authorization Code + PKCE, vanilla JS) */
(function () {
  'use strict';

  const { url, realm, clientId } = window.SIGPE_CONFIG.keycloak;
  const issuer = `${url}/realms/${realm}`;
  const STORAGE_KEY = 'sigpe.tokens';

  const b64url = (buf) => btoa(String.fromCharCode(...new Uint8Array(buf)))
    .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

  function generateCodeVerifier() {
    const arr = new Uint8Array(64);
    crypto.getRandomValues(arr);
    return b64url(arr.buffer);
  }

  async function generateCodeChallenge(verifier) {
    const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(verifier));
    return b64url(digest);
  }

  function randomState() {
    const arr = new Uint8Array(16);
    crypto.getRandomValues(arr);
    return b64url(arr.buffer);
  }

  function saveTokens(tokens) {
    tokens.expires_at = Date.now() + (tokens.expires_in || 300) * 1000;
    sessionStorage.setItem(STORAGE_KEY, JSON.stringify(tokens));
  }

  function loadTokens() {
    try { return JSON.parse(sessionStorage.getItem(STORAGE_KEY)); } catch { return null; }
  }

  function clearTokens() {
    sessionStorage.removeItem(STORAGE_KEY);
  }

  async function exchangeCode(code, codeVerifier) {
    const body = new URLSearchParams({
      grant_type: 'authorization_code',
      client_id: clientId,
      code,
      redirect_uri: window.location.origin + '/',
      code_verifier: codeVerifier,
    });
    const res = await fetch(`${issuer}/protocol/openid-connect/token`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body,
    });
    if (!res.ok) throw new Error('Échange du code impossible');
    return res.json();
  }

  async function refreshTokens() {
    const tokens = loadTokens();
    if (!tokens?.refresh_token) return null;
    const body = new URLSearchParams({
      grant_type: 'refresh_token',
      client_id: clientId,
      refresh_token: tokens.refresh_token,
    });
    const res = await fetch(`${issuer}/protocol/openid-connect/token`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body,
    });
    if (!res.ok) { clearTokens(); return null; }
    const data = await res.json();
    saveTokens({ ...tokens, ...data });
    return loadTokens();
  }

  async function getAccessToken() {
    const tokens = loadTokens();
    if (!tokens) return null;
    if (tokens.expires_at && Date.now() > tokens.expires_at - 15000) {
      return (await refreshTokens())?.access_token ?? null;
    }
    return tokens.access_token;
  }

  /** Décode le payload JWT (sans vérification — la signature est validée côté services). */
  function decodeJwt(token) {
    try {
      return JSON.parse(atob(token.split('.')[1].replace(/-/g, '+').replace(/_/g, '/')));
    } catch { return null; }
  }

  async function login() {
    const verifier = generateCodeVerifier();
    const challenge = await generateCodeChallenge(verifier);
    const state = randomState();
    sessionStorage.setItem('sigpe.pkce', JSON.stringify({ verifier, state }));

    const params = new URLSearchParams({
      client_id: clientId,
      redirect_uri: window.location.origin + '/',
      response_type: 'code',
      scope: 'openid profile email',
      state,
      code_challenge: challenge,
      code_challenge_method: 'S256',
    });
    window.location.href = `${issuer}/protocol/openid-connect/auth?${params}`;
  }

  function logout() {
    clearTokens();
    const params = new URLSearchParams({ client_id: clientId, post_logout_redirect_uri: window.location.origin + '/' });
    window.location.href = `${issuer}/protocol/openid-connect/logout?${params}`;
  }

  /**
   * À appeler au démarrage : gère le callback ?code=... puis renvoie true si
   * l'utilisateur est authentifié.
   */
  async function handleRedirectCallback() {
    const url = new URL(window.location.href);
    const code = url.searchParams.get('code');
    const state = url.searchParams.get('state');
    if (!code) return !!loadTokens();

    const pkce = JSON.parse(sessionStorage.getItem('sigpe.pkce') || '{}');
    sessionStorage.removeItem('sigpe.pkce');
    if (state && pkce.state && state !== pkce.state) {
      throw new Error('State PKCE invalide');
    }

    const tokens = await exchangeCode(code, pkce.verifier);
    saveTokens(tokens);
    // Nettoie l'URL (code + state) sans recharger
    history.replaceState(null, '', window.location.origin + '/');
    return true;
  }

  function currentUser() {
    const tokens = loadTokens();
    return tokens ? decodeJwt(tokens.access_token) : null;
  }

  window.SigpeAuth = { login, logout, getAccessToken, handleRedirectCallback, currentUser, isAuthenticated: () => !!loadTokens() };
})();
