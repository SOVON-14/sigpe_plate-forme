# ADR-005 — Conventions des API REST et RFC 9457

- **Statut** : VALIDÉ (catalogue v1.1 §5, TEC-006/TEC-016)
- **Date** : 12/11/2026
- **Provenance** : Validée dans le catalogue technique v1.1 (§5.1 ; TEC-006, TEC-016 « Arrêtées »).

## Contexte
13 services exposent des API REST via APISIX ; les erreurs doivent être homogènes.

## Problème
Fixer les conventions communes sans figer les endpoints (propositions jusqu'aux openapi.yaml).

## Décision
- Préfixe `/api/v1`, JSON, HTTPS uniquement.
- Authentification OIDC (Keycloak) ; autorisation RBAC + ABAC par service.
- Headers obligatoires : `X-Correlation-ID`, `X-Request-ID` ; `Idempotency-Key` sur opérations sensibles ; `ETag`/`If-Match` pour modifications concurrentes sensibles.
- Pagination obligatoire sur les listes (`page`, `per_page`, bornes max documentées par contrat).
- Erreurs : **RFC 9457 Problem Details** (`application/problem+json`) avec `type`, `title`, `status`, `detail`, `instance` + extensions SIGPÉ (`code`, `correlation_id`).
- Chaque service produit son `openapi.yaml` (OpenAPI 3.1) dans `contracts/openapi/`.
- Versionnement sans rupture silencieuse : `/api/v1` → `/api/v2` le cas échéant.

## Alternatives étudiées
- Format d'erreur maison : rejeté — interopérabilité et outillage.
- GraphQL : hors périmètre du catalogue.

## Conséquences positives
- Clients et gateway homogènes ; monitoring par correlation_id.
- Contrats testables (OpenAPI 3.1 + lint).

## Conséquences négatives / risques
- RFC 9457 exige discipline sur tous les endpoints → tests de contrat obligatoires.
- `Idempotency-Key` à implémenter par service (table de déduplication locale).

## Risques
Divergence de codes d'erreur métier → centraliser la taxonomie des `code` dans `contracts/` (à produire).

## Dépendances

- ADR-008 (RBAC/ABAC appliqué à chaque endpoint).
- ADR-012 (openapi.yaml versionné).
