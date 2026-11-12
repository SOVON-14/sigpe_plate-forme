# ADR-007 — Outbox, Inbox, idempotence et DLQ

- **Statut** : VALIDÉ (catalogue v1.1 §6.3, COR-008)
- **Date** : 12/11/2026
- **Provenance** : Validée dans le catalogue technique v1.1 (§6.3 ; COR-008, TEC-010 « Arrêtée »).

## Contexte
La publication d'événements et leur consommation doivent survivre aux pannes sans perte ni double traitement.

## Problème
Éviter les anomalies distribuées : événement publié sans commit, doublons, messages perdus, traitements non rejouables.

## Décision
- **Transactional Outbox** : tout événement est écrit dans une table `outbox` dans la même transaction que la donnée métier ; un relay (job/worker) publie vers RabbitMQ puis marque l'outbox.
- **Inbox** : chaque consommateur persiste `event_id` traité (unicité par consommateur) avant/pendant le traitement métier.
- **Idempotence** : les handlers vérifient l'Inbox ; rejouer un message ne doit produire aucun effet de bord supplémentaire.
- **Retry** : backoff exponentiel avec jitter ; **DLQ** après épuisement ; rejeu contrôlé documenté.
- Corrélation : `correlation_id` propagé de la requête initiale à tous les événements causés.

## Alternatives étudiées
- Publish direct après commit : fenêtre de perte/crash → rejeté.
- Exactly-once (transactions RabbitMQ) : non applicable à AMQP 0-9-1 → at-least-once + idempotence retenu.

## Conséquences positives
- Aucune perte silencieuse ; rejeu possible ; auditabilité.
- Comportement prévisible sous charge et pannes.

## Conséquences négatives / risques
- Tables outbox/inbox par service → migrations dédiées, purge à prévoir (rétention à fixer — point ouvert du catalogue).
- Complexité de mise en œuvre → bibliothèque/trait commun **interdit de partager entre services** → implémentation dupliquée par service (assumée) ou package interne non-métier à décider (PROPOSÉ : duplication assumée pour préserver l'autonomie).

## Risques
Traitement partiel avant échec → handlers en une transaction locale quand possible ; compensation/saga pour les processus longs (à documenter par flux).

## Dépendances

- ADR-006 (topologie RabbitMQ sous-jacente).
- ADR-012 (event_id/event_version des contrats).
