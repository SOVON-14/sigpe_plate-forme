# ADR-002 — Séparation Keycloak / Identity Service

- **Statut** : VALIDÉ (catalogue v1.1, TEC-002/TEC-003 « Arrêtées »)
- **Date** : 12/11/2026
- **Provenance** : Validée dans le catalogue technique v1.1 (TEC-002, TEC-003 « Arrêtées »).

## Contexte
Keycloak est retenu comme fournisseur d'identité OIDC. Le catalogue définit Identity Service comme propriétaire des habilitations métier.

## Problème
Où s'arrêtent les responsabilités de Keycloak et où commencent celles d'Identity ?

## Décision
- **Keycloak** (infrastructure, pas un microservice) : authentification, fédération d'identité, MFA, sessions, émission des jetons OIDC/OAuth2.
- **Identity Service** (`sigpe_identity`) : comptes fonctionnels SIGPÉ, profils, rôles métier, permissions, délégations, périmètres d'accès.
- **API Gateway (APISIX)** : validation des jetons à l'entrée.
- **Chaque microservice** : contrôle final d'autorisation (RBAC + ABAC) sur ses ressources.

## Alternatives étudiées
- Keycloak comme référentiel des rôles : rejeté — les rôles métier SIGPÉ sont riches (délégations, périmètres, séparation des tâches) et ne correspondent pas au modèle RBAC natif de Keycloak.
- Identity comme simple proxy Keycloak : insuffisant pour les habilitations métier.

## Conséquences positives
- Authentification standardisée (OIDC) sans réinventer MFA/sessions.
- Logique métier d'habilitation maîtrisée dans le domaine, testable et versionnée.

## Conséquences négatives / risques
- Double source de vérité (compte Keycloak + compte fonctionnel) → nécessité d'une stratégie de synchronisation (voir statut : mécanisme exact à détailler dans openapi/asyncapi d'Identity — PROPOSÉ).
- Claims JWT à définir : `sub` = identifiant Keycloak, mapping vers `user_id` fonctionnel.

## Risques
Dérive si des services font confiance au seul JWT sans contrôle ABAC → interdit par le catalogue.
