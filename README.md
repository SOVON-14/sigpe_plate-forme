# SIGPÉ — Système intégré de gestion du patrimoine de l'État

SIGPÉ est une plateforme institutionnelle de gestion du patrimoine de l'État
togolais, construite autour d'une architecture à 13 microservices.

> Référentiel de développement : **catalogue technique v1.1 du 12/11/2026**.
> Référence métier : **cahier des charges fonctionnel v1.0 du 11/11/2026**.
> Index documentaire : [`docs/INDEX.md`](docs/INDEX.md).

## Architecture

- Backend : Laravel (PHP) — applications indépendantes par service
- Frontend : React.js + Vite
- API Gateway : Apache APISIX Community Edition
- Authentification : Keycloak / OpenID Connect (infrastructure, pas un service)
- Bases de données : PostgreSQL — une base logique `sigpe_*` par microservice
- Messagerie : RabbitMQ (AMQP 0-9-1), exchange topic `sigpe.events.v1`
- Stockage documentaire : API compatible S3 (MinIO en dev — voir ADR-010)
- Observabilité : OpenTelemetry, Prometheus, Grafana, Loki, Tempo
- Documentation : OpenAPI 3.1 (REST) / AsyncAPI 3.0 (événements) / JSON Schema

## Microservices (13)

1. identity-service — comptes fonctionnels, rôles, permissions, délégations
2. organization-service — institutions et structures organisationnelles
3. asset-service — registre patrimonial maître, codes, QR, localisations, réforme
4. inventory-service — campagnes et constats d'inventaire
5. movement-service — affectations, transferts, prêts, gardiens
6. workflow-service — circuits et validations
7. maintenance-service — interventions, stocks, prestataires
8. document-service — fichiers, versions, accès, archives
9. finance-service — valorisation, amortissements, coûts
10. reporting-service — projections et rapports
11. audit-service — traces et preuves d'audit
12. notification-service — notifications, accusés, relances
13. acquisition-service — dossier d'acquisition de bout en bout

## Principes d'architecture

- Un seul propriétaire/écrivain par donnée métier ; aucune base partagée.
- Aucun accès direct à la base d'un autre microservice.
- Communications synchrones : API contractuelles (OpenAPI 3.1) ; asynchrones : RabbitMQ.
- Événements : livraison at-least-once, Outbox/Inbox, consommateurs idempotents, DLQ.
- Autorisation métier (RBAC + ABAC) contrôlée par chaque service — la Gateway ne s'y substitue pas.
- Secrets et identifiants jamais versionnés dans Git.

## Organisation du dépôt

- `services/` : applications backend indépendantes.
- `frontend/` : interface utilisateur (`sigpe-web`).
- `gateway/` : configuration APISIX.
- `infrastructure/` : docker, postgres, rabbitmq, keycloak, storage, monitoring, deployment.
- `contracts/` : OpenAPI, AsyncAPI, JSON Schema, événements, permissions.
- `docs/` : index documentaire, technique, architecture, ADR, guides.
- `tests/` : contrats, intégration, sécurité, E2E.

## État du projet

Backend : les **13 microservices sont implémentés** (domaine + API REST
`/api/v1` + événements outbox/inbox RabbitMQ + tests feature), leurs contrats
OpenAPI/AsyncAPI/JSON Schema sont validés, et toutes les branches
`service/*` + `contracts` sont fusionnées sur `main`.

Infrastructure de développement : `compose.yaml` orchestre PostgreSQL
(13 bases), RabbitMQ (vhost `sigpe`, exchange `sigpe.events.v1`), Keycloak
(realm `sigpe` importé), MinIO (bucket `sigpe-documents`), APISIX
(142 routes générées depuis les OpenAPI) et les 13 services + workers.

### Démarrage local

```bash
cp .env.example .env   # puis renseigner les mots de passe et secrets
# POSTGRES_PASSWORD, RABBITMQ_PASSWORD, KEYCLOAK_ADMIN_PASSWORD,
# SIGPE_ADMIN_PASSWORD, SIGPE_KC_SECRET_* (13), MINIO_ROOT_PASSWORD

docker compose up -d --build        # toute la pile
docker compose logs -f apisix       # gateway : http://localhost:9080
docker compose logs -f keycloak     # console : http://localhost:8081
```

- Gateway APISIX : `http://localhost:9080/api/v1/...` (routes générées par
  `php gateway/apisix/generate-routes.php` depuis `contracts/openapi/`).
- Realm Keycloak `sigpe` : client public `sigpe-web`, audience `sigpe-api`,
  13 clients `*-service` en client_credentials, claim `sigpe-claims`
  (permissions + institution_id + audience).
- Worker par service : `queue:work` + boucle `sigpe:outbox:publish` (+ 
  `sigpe:events:consume` pour les 7 consommateurs).
- Exception de routage : la projection reporting des actifs est exposée via
  `/api/v1/reporting/assets/{assetId}` (réécriture proxy) pour lever
  l'ambiguïté avec le registre maître `/api/v1/assets/{assetId}`.

Reste à faire : frontend React (`frontend/sigpe-web`), observabilité
(`infrastructure/monitoring`), CI/CD et validation E2E de la pile.
