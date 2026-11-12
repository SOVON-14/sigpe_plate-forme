# ADR-006 — Architecture événementielle RabbitMQ

- **Statut** : VALIDÉ (catalogue v1.1 §6, TEC-009/TEC-010)
- **Date** : 12/11/2026
- **Provenance** : Validée dans le catalogue technique v1.1 (§6.1 ; TEC-009 « Arrêtée »).

## Contexte
Les communications asynchrones inter-services passent par RabbitMQ (AMQP 0-9-1).

## Problème
Garantir des échanges fiables, traçables et découplés.

## Décision
- Exchange principal **topic `sigpe.events.v1`** ; un seul exchange métier.
- Routing keys structurées : `domaine.action.vN` (ex. `asset.registered.v1`, `movement.transfer.requested.v1`).
- Files **durables** par consommateur, messages **persistents**, publisher confirms, accusés manuels.
- Files de retry + **Dead Letter Queues** ; backoff exponentiel.
- Droits RabbitMQ limités par service (vhost ou utilisateur dédié par service).
- Enveloppe événementielle commune obligatoire (voir `contracts/json-schemas/enveloppe-evenementiel.schema.json`).
- Livraison **at-least-once** ; jamais de promesse exactly-once.

## Alternatives étudiées
- Kafka : rejeté — catalogue impose RabbitMQ (AMQP), simplicité d'exploitation pour le contexte.
- Exchanges multiples par domaine : rejeté — complexité inutile, un topic suffit avec les routing keys.

## Conséquences positives
- Découplage temporel, résilience aux indisponibilités.
- Traçabilité via `correlation_id`/`causation_id`.

## Conséquences négatives / risques
- Ordre non garanti entre producteurs différents → consommateurs idempotents + vérification d'état.
- DLQ à surveiller → métriques et alertes obligatoires.

## Risques
Messages sans secret ni donnée confidentielle (règle du catalogue) → revues de schéma.

## Dépendances

- ADR-007 (Outbox/Inbox/DLQ obligatoire sur chaque flux).
- ADR-012 (schémas et matrice des flux).
