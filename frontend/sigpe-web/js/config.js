/* SIGPÉ — configuration frontend (à adapter par environnement) */
window.SIGPE_CONFIG = {
  // API Gateway APISIX
  apiBase: 'http://localhost:9080',

  // Keycloak (URL publique pour le navigateur)
  keycloak: {
    url: 'http://localhost:8081',
    realm: 'sigpe',
    clientId: 'sigpe-web',
  },

  appName: 'SIGPÉ',
  appSubtitle: "Gestion du Patrimoine de l'État",
};
