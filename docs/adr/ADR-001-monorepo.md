# ADR-001 — Architecture monorepo et organisation des applications

- **Statut** : VALIDÉ (catalogue v1.1, TEC-011 « Retenue »)
- **Date** : 12/11/2026
- **Provenance** : Validée dans le catalogue technique v1.1 (TEC-011 « Retenue »).

## Contexte
SIGPÉ compte 13 microservices Laravel + 1 frontend + infrastructure. Le catalogue impose un monorepo de référence sans partager les classes métier.

## Problème
Comment organiser le code source sans créer de couplage entre services ?

## Décision
Monorepo `sigpe-platform/` avec :
- `services/<nom>-service/` : chaque application Laravel autonome (composer.json, composer.lock, .env.example, Dockerfile, migrations, tests propres).
- `frontend/sigpe-web/` : application React/Vite.
- `gateway/apisix/`, `infrastructure/`, `contracts/`, `tests/`, `docs/`.
- Aucun package PHP partagé entre microservices ; aucun modèle Eloquent mutualisé.
- `docker-compose.yml` unique à la racine pour le développement.

## Alternatives étudiées
- Multi-repo : plus de contrôle de version par service mais synchronisation pénible pour une équipe initiale unique.
- Monolithe modulaire : rejeté par le catalogue (microservices imposés).

## Conséquences positives
- Vision globale, conventions communes, CI/CD unifiée, onboarding simple.
- Isolation préservée : chaque service a son propre cycle de vie et sa base.

## Conséquences négatives / risques
- Tentation de partager du code → compensée par règle explicite « aucune classe métier partagée ».
- Builds plus lourds → builds Docker par service en parallèle.

## Risques
Dérive vers un « monolithe distribué » si les frontières ne sont pas respectées. Mitigation : revues de contrats, tests d'isolation.
