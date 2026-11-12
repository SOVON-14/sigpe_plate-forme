# ADR-003 — Isolation des 13 bases PostgreSQL

- **Statut** : VALIDÉ (catalogue v1.1, TEC-005 « Arrêtée »)
- **Date** : 12/11/2026
- **Provenance** : Validée dans le catalogue technique v1.1 (TEC-005 « Arrêtée »).

## Contexte
Chaque microservice possède exclusivement ses données métier.

## Problème
Comment garantir l'isolation physique et logique des données ?

## Décision
- 13 bases logiques PostgreSQL : `sigpe_identity`, `sigpe_organization`, `sigpe_asset`, `sigpe_inventory`, `sigpe_movement`, `sigpe_workflow`, `sigpe_maintenance`, `sigpe_document`, `sigpe_finance`, `sigpe_reporting`, `sigpe_audit`, `sigpe_notification`, `sigpe_acquisition`.
- Une instance PostgreSQL physique peut les héberger en dev ; en production la topologie est dimensionnée selon les exigences de souveraineté/disponibilité.
- Chaque base : utilisateur PostgreSQL dédié, droits minimaux, migrations propres.
- **Aucune clé étrangère SQL entre bases**, aucun accès croisé.

## Alternatives étudiées
- Base unique multi-schemas : rejetée — isolation insuffisante.
- Une instance par service : possible en production, non requise en dev.

## Conséquences positives
- Autonomie de déploiement et de sauvegarde par service.
- Échec d'un service sans impact sur les données des autres.

## Conséquences négatives / risques
- Pas d'intégrité référentielle inter-services → compensée par IDs logiques (UUIDv7) + validation applicative + événements.
- Requêtes cross-service impossibles → Reporting doit consommer des événements (ADR-013).

## Risques
Contournement par jointures « sauvages » → interdit, revues de code.
