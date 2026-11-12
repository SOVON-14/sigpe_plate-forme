# Guide des conventions de développement — SIGPÉ

Référence : SIGPE-CONV-001 — Version 1.0 — 12/11/2026
S'applique à tous les microservices et au frontend. Priorité : catalogue technique v1.1.

## 1. Données (tous services)

| Convention | Règle |
|---|---|
| Identifiants | UUIDv7 (RFC 9562) — jamais d'auto-incrément exposé |
| Nommage | Tables `snake_case` au pluriel ; colonnes `snake_case` |
| Dates/heures | `timestamptz`, UTC, ISO 8601 à l'API |
| Montants | `NUMERIC`/`decimal` — **jamais FLOAT/DOUBLE** ; JSON = chaîne décimale |
| Devise | XOF (sous réserve règles financières officielles) |
| Suppression | Logique (`deleted_at`) ; jamais physique pour données sensibles |
| Historisation | Avant/après, auteur, date, motif sur toute correction sensible |
| Clés étrangères | **Aucune FK SQL entre bases de services** ; références = UUID logiques |
| Unicité métier | `UNIQUE(institution_id, code_patrimonial)` pour les codes patrimoniaux |

## 2. API REST (tous services)

- HTTPS uniquement, préfixe `/api/v1`, JSON.
- OpenAPI 3.1 obligatoire (`contracts/openapi/<service>.openapi.yaml`).
- Erreurs : RFC 9457 `application/problem+json` + `code` métier + `correlation_id`.
- Headers : `X-Correlation-ID` (requis), `X-Request-ID`, `Idempotency-Key` (opérations sensibles), `ETag`/`If-Match` (concurrence).
- Pagination obligatoire sur les listes (`page`, `per_page` avec `max` documenté, métadonnées `total`, `links`).
- Autorisation : RBAC + ABAC évalués par le service — jamais par la seule Gateway.
- Toute route doit respecter la frontière du service (catalogue §3) ; les routes du catalogue sont indicatives jusqu'aux openapi.yaml.

## 3. Événements RabbitMQ (tous services)

- AMQP 0-9-1, exchange topic `sigpe.events.v1`, routing keys `domaine.action.vN`.
- Messages persistants, publisher confirms, ack manuels, retry backoff + jitter, DLQ.
- **Transactional Outbox** pour publier ; **Inbox** (`event_id` unique/consommateur) pour consommer ; idempotence obligatoire.
- Livraison at-least-once — les consommateurs doivent être rejouables sans effet de bord.
- Enveloppe obligatoire : voir `contracts/json-schemas/enveloppe-evenementiel.schema.json`.
- **Aucun secret, mot de passe, document confidentiel ou donnée personnelle inutile dans `data`.**

## 4. Code backend (Laravel)

- Chaque service : `composer.json`, `composer.lock`, `.env.example`, `Dockerfile`, migrations, tests propres. Aucun partage de classes métier.
- Couches : Controller → FormRequest/DTO → Domain Service → Repository/Model.
- Validation d'entrée stricte (FormRequest) ; autorisation via Policies (RBAC+ABAC).
- Migrations versionnées ; seeds de dev sans données réelles.
- Logs JSON structurés, niveau approprié, `correlation_id` propagé ; jamais de secret/PII en log.
- Tests : PHPUnit/Pest ; les règles métier critiques, permissions et migrations doivent être testées (catalogue §10).

## 5. Frontend (React/Vite)

- Appels API **uniquement via APISIX** — jamais directement aux services/bases/broker.
- `X-Correlation-ID` généré par requête ; gestion centralisée des erreurs RFC 9457.
- État serveur via TanStack Query ou équivalent ; formulaires validés côté client **et** serveur.
- Tests : Vitest + React Testing Library ; E2E : Playwright.
- i18n : français par défaut ; montants/dates au format national cohérent.

## 6. Contrats

- Tout endpoint → `openapi.yaml` du service ; tout événement → schéma JSON + entrée dans `contracts/events/MATRICE_PRODUCTEURS_CONSUMMATEURS.md` + `contracts/asyncapi/`.
- Un contrat publié n'est pas modifié de façon incompatible sans nouvelle version.
- Consommateurs : tolérer les champs inconnus ; producteurs : n'émettre que les champs documentés.

## 7. Git et qualité

- Jamais de secret dans Git (`.env`, clés, certificats) ; `.env.example` obligatoire par service.
- Conventions de commit : `type(scope): description` (feat, fix, docs, chore, test, refactor).
- CI : lint, tests unitaires, tests de contrat, analyse statique — avant merge.
- ADR obligatoire pour toute décision structurante nouvelle (`docs/adr/`).
