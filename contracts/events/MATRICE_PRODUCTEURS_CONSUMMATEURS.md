# Matrice producteurs / consommateurs — Événements RabbitMQ SIGPÉ

Référence : SIGPE-EVT-MATRIX-001 — Version 0.1 (squelette) — 12/11/2026
**Statut : PROPOSÉ.** Les événements réels doivent être extraits du catalogue v1.1 et du CDC v1.0, puis validés avant implémentation. Ce fichier est la source de vérité des flux ; tout nouvel événement y est déclaré avant codage.

## Conventions

- Exchange : `sigpe.events.v1` (topic) — Routing key : `<domaine>.<action>.v<N>`.
- Enveloppe : `contracts/json-schemas/enveloppe-evenementiel.schema.json`.
- Fiabilité : Outbox/Inbox, at-least-once, idempotence, DLQ.
- Séquence de transfert interministériel : **Asset confirme avant la clôture Movement** (TEC-017).

## Matrice des flux (à compléter par domaine)

| event_type | Routing key | Producteur | Consommateurs | Déclencheur métier | Statut |
|---|---|---|---|---|---|
| *(exemple — à remplacer)* asset.registered | asset.registered.v1 | asset-service | reporting-service, audit-service | Création d'une fiche patrimoniale | PROPOSÉ |
| *(exemple)* movement.transfer.requested | movement.transfer.requested.v1 | movement-service | workflow-service, asset-service | Demande de transfert interministeriel | PROPOSÉ |
| *(exemple)* asset.attachment.confirmed | asset.attachment.confirmed.v1 | asset-service | movement-service | Confirmation du changement de rattachement | PROPOSÉ — requis par TEC-017 |
| *(exemple)* movement.interministerial_transfer.completed | movement.interministerial_transfer.completed.v1 | movement-service | reporting-service, audit-service, notification-service | Clôture après confirmation Asset | PROPOSÉ |
| identity.account.created | identity.account.created.v1 | identity-service | audit-service, notification-service | Création d'un compte fonctionnel | CONTRACTÉ (draft) — voir identity-service.asyncapi.yaml |
| identity.account.status_changed | identity.account.status_changed.v1 | identity-service | audit-service, notification-service | Suspension/désactivation de compte | CONTRACTÉ (draft) |
| identity.role.assigned | identity.role.assigned.v1 | identity-service | audit-service | Attribution de rôle (périmètre + expiration) | CONTRACTÉ (draft) |
| identity.role.revoked | identity.role.revoked.v1 | identity-service | audit-service | Révocation d'attribution de rôle | CONTRACTÉ (draft) |
| identity.delegation.created | identity.delegation.created.v1 | identity-service | audit-service, notification-service | Création d'une délégation | CONTRACTÉ (draft) |
| identity.delegation.revoked | identity.delegation.revoked.v1 | identity-service | audit-service, notification-service | Révocation d'une délégation | CONTRACTÉ (draft) |
| organization.institution.created | organization.institution.created.v1 | organization-service | asset-service, reporting-service, audit-service, notification-service | Création d'une institution | CONTRACTÉ (draft) — voir organization-service.asyncapi.yaml |
| organization.institution.status_changed | organization.institution.status_changed.v1 | organization-service | asset-service, reporting-service, audit-service, notification-service | Activation/suspension/désactivation d'institution | CONTRACTÉ (draft) |
| organization.unit.created | organization.unit.created.v1 | organization-service | asset-service, reporting-service, audit-service | Création d'une unité organisationnelle | CONTRACTÉ (draft) |
| organization.unit.updated | organization.unit.updated.v1 | organization-service | asset-service, reporting-service, audit-service | Réorganisation (renommage, déplacement, changement de chef) | CONTRACTÉ (draft) |
| organization.unit.status_changed | organization.unit.status_changed.v1 | organization-service | asset-service, reporting-service, audit-service | Changement de statut d'une unité | CONTRACTÉ (draft) |
| organization.position.created | organization.position.created.v1 | organization-service | audit-service | Création d'un poste organisationnel | CONTRACTÉ (draft) |
| organization.position.responsible_changed | organization.position.responsible_changed.v1 | organization-service | audit-service, notification-service | Affectation/révocation d'un responsable de poste | CONTRACTÉ (draft) |

## Événements candidats identifiés dans les sources (non contractuels)

Issus du catalogue §7 (scénarios) — **noms indicatifs, à formaliser** :

- Acquisition : besoin exprimé, validation, commande, livraison, réception (partielle/complète), réserves, clôture ; transmission des biens acceptés à Asset.
- Asset : fiche créée, code attribué, QR généré, changement de rattachement confirmé, réforme décidée, sortie définitive, état physique modifié.
- Inventory : campagne planifiée/approuvée, lancée, constats enregistrés, anomalies détectées, clôture.
- Movement : affectation, transfert demandé/approuvé/rejeté/finalisé, prêt accordé/prolongé/restitué, retard, perte/vol déclaré.
- Workflow : tâche assignée, approuvation, refus, demande de correction, escalade, délégation.
- Maintenance : demande, diagnostic, intervention planifiée/démarrée/terminée, clôture, stock mis à jour.
- Document : document déposé, nouvelle version, accès sensible.
- Finance : valeur enregistrée/estimée, amortissement calculé, valorisation de sortie, répartition de coûts.
- Notification : notification créée, envoyée, délivrée, échouée, lue, accusée.
- Audit : événement sensible tracé (consommateur de tous les flux critiques).
- Reporting : (consommateur principal de presque tous les événements).
- Identity/Organization : institution créée/activée/désactivée, rôle/permission modifié, unité réorganisée.

## Règles de remplissage

1. Chaque ligne = un `event_type` + `event_version` + schema JSON dédié dans `contracts/json-schemas/`.
2. Tout nouvel événement exige : producteur, liste des consommateurs, déclencheur, schéma, test de contrat.
3. Les événements marqués `[À CONFIRMER]` dans les sources ne sont pas implémentables.
