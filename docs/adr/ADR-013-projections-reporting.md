# ADR-013 — Reconstruction des projections Reporting

- **Statut** : PROPOSÉ (catalogue §5.2 : « mécanismes de reconstruction des projections » = arbitrage encore ouvert)
- **Date** : 12/11/2026

## Contexte
Reporting Service n'est jamais propriétaire des données métier ; il exploite des projections de lecture alimentées par événements (COR-009).

## Problème
Comment (re)construire les projections en cas de perte, de retard ou de changement de schéma ?

## Décision (proposée)
- Reporting conserve ses propres projections dans `sigpe_reporting` (tables de lecture optimisées + `projection_meta` avec `source_event_id`, `event_version`, `applied_at`, `freshness_at`).
- Chaque projection est **reconstructible** : rejouer les événements depuis RabbitMQ (flux conservé N jours — rétention à fixer) ou depuis un export des services sources.
- Indicateurs de fraîcheur exposés dans les API/rapports (`freshness_at` obligatoire dans les payloads de reporting).
- Les projections ne sont jamais la source de vérité : tout chiffre exposé doit être traçable à l'événement source.
- Stratégie de replay documentée par projection ; migrations de projection versionnées.

## Alternatives étudiées
- Reporting interrogeant les API métier en direct : charge et couplage forts → rejeté.
- CDC (Debezium) sur les bases : violerait l'interdiction d'accès direct aux bases → rejeté.

## Conséquences positives
- Lecture découplée et performante ; isolation des pannes sources.
- Rejouabilité = résilience et correction des anomalies.

## Conséquences négatives / risques
- Retard de propagation → UI doit afficher la fraîcheur (exigence CDC §4.17).
- Volume de rejeu → rétention des files et stockage des événements à dimensionner (point ouvert : volumes/RPO-RTO).

## Risques
Projection dérivée divergente → tests de cohérence croisée avec les services sources en recette.

## Dépendances

- ADR-007 (rejeu idempotent des événements).
- ADR-012 (versions de schémas).
- ADR-003 (Reporting possède ses projections dans sigpe_reporting).
