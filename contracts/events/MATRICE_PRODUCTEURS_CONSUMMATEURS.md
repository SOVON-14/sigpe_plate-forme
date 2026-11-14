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
| asset.registered | asset.registered.v1 | asset-service | movement-service, reporting-service, audit-service, notification-service | Enregistrement d'un bien au registre maître | CONTRACTÉ (draft) — voir asset-service.asyncapi.yaml |
| asset.updated | asset.updated.v1 | asset-service | reporting-service, audit-service | Mise à jour des champs descriptifs d'une fiche | CONTRACTÉ (draft) |
| asset.physical_state_changed | asset.physical_state_changed.v1 | asset-service | inventory-service, maintenance-service, reporting-service, audit-service | Changement d'état physique | CONTRACTÉ (draft) |
| asset.status_changed | asset.status_changed.v1 | asset-service | reporting-service, audit-service, notification-service | Changement de statut administratif (en_reforme, sorti…) | CONTRACTÉ (draft) |
| asset.rattachement_confirmed | asset.rattachement_confirmed.v1 | asset-service | movement-service, reporting-service, audit-service | Confirmation TEC-017 — nouveau code patrimonial attribué | CONTRACTÉ (draft) — **critique** : requis avant clôture transfert interministériel |
| inventory.campaign_created | inventory.campaign_created.v1 | inventory-service | reporting-service, audit-service, notification-service | Création d'une campagne d'inventaire | CONTRACTÉ (draft) — voir inventory-service.asyncapi.yaml |
| inventory.campaign_status_changed | inventory.campaign_status_changed.v1 | inventory-service | reporting-service, audit-service, notification-service | Transition de statut (in_progress, under_review, closed, cancelled) | CONTRACTÉ (draft) |
| inventory.agent_assigned | inventory.agent_assigned.v1 | inventory-service | audit-service, notification-service | Affectation d'un agent d'inventaire | CONTRACTÉ (draft) |
| inventory.agent_unassigned | inventory.agent_unassigned.v1 | inventory-service | audit-service, notification-service | Désaffectation d'un agent d'inventaire | CONTRACTÉ (draft) |
| inventory.finding_recorded | inventory.finding_recorded.v1 | inventory-service | reporting-service, audit-service, asset-service | Constat enregistré (trouvé / non trouvé, anomalie éventuelle) | CONTRACTÉ (draft) |
| inventory.finding_verified | inventory.finding_verified.v1 | inventory-service | reporting-service, audit-service, movement-service, notification-service | Rapprochement contrôlé du constat | CONTRACTÉ (draft) |
| workflow.template_created | workflow.template_created.v1 | workflow-service | audit-service | Création d'un workflow (national ou adaptation) | CONTRACTÉ (draft) — voir workflow-service.asyncapi.yaml |
| workflow.instance_started | workflow.instance_started.v1 | workflow-service | audit-service, notification-service | Démarrage d'un circuit d'approbation | CONTRACTÉ (draft) |
| workflow.task_decided | workflow.task_decided.v1 | workflow-service | audit-service, notification-service | Décision sur une tâche d'approbation | CONTRACTÉ (draft) |
| workflow.instance_completed | workflow.instance_completed.v1 | workflow-service | movement-service, acquisition-service, maintenance-service, reporting-service, audit-service, notification-service | Clôture d'instance — outcome approved/rejected/cancelled (**critique** : débloque l'opération métier) | CONTRACTÉ (draft) |
| movement.custody.assigned | movement.custody.assigned.v1 | movement-service | asset-service, reporting-service, audit-service, notification-service | Garde / affectation creee ou transferee | CONTRACTÉ (draft) — voir movement-service.asyncapi.yaml |
| movement.internal_transfer.completed | movement.internal_transfer.completed.v1 | movement-service | reporting-service, audit-service, notification-service | Transfert interne termine (code patrimonial inchange) | CONTRACTÉ (draft) |
| movement.interministerial_transfer.requested | movement.interministerial_transfer.requested.v1 | movement-service | workflow-service, audit-service, notification-service | Transfert interministeriel soumis aux validations | CONTRACTÉ (draft) |
| movement.interministerial_transfer.completed | movement.interministerial_transfer.completed.v1 | movement-service | reporting-service, audit-service, notification-service | Cloture TEC-017 — uniquement apres asset.rattachement_confirmed | CONTRACTÉ (draft) — **critique** |
| movement.loan.requested | movement.loan.requested.v1 | movement-service | workflow-service, audit-service, notification-service | Demande de pret deposee | CONTRACTÉ (draft) |
| movement.loan.activated | movement.loan.activated.v1 | movement-service | reporting-service, audit-service, notification-service | Pret actif apres validations | CONTRACTÉ (draft) |
| movement.loan.returned | movement.loan.returned.v1 | movement-service | reporting-service, audit-service, notification-service | Pret restitue (is_late renseigne) | CONTRACTÉ (draft) |
| movement.shared_right.granted | movement.shared_right.granted.v1 | movement-service | reporting-service, audit-service, notification-service | Droit d'usage accorde sur bien partage | CONTRACTÉ (draft) |
| movement.shared_right.terminated | movement.shared_right.terminated.v1 | movement-service | reporting-service, audit-service, notification-service | Droit d'usage suspendu ou termine | CONTRACTÉ (draft) |
| acquisition.request.created | acquisition.request.created.v1 | acquisition-service | workflow-service, reporting-service, audit-service, notification-service | Expression de besoin creee | CONTRACTÉ (draft) — voir acquisition-service.asyncapi.yaml |
| acquisition.request.submitted | acquisition.request.submitted.v1 | acquisition-service | workflow-service, audit-service, notification-service | Demande soumise au circuit de validation | CONTRACTÉ (draft) |
| acquisition.request.selected | acquisition.request.selected.v1 | acquisition-service | reporting-service, audit-service, notification-service | Offre/fournisseur selectionne | CONTRACTÉ (draft) |
| acquisition.request.ordered | acquisition.request.ordered.v1 | acquisition-service | reporting-service, audit-service, notification-service | Commande emise | CONTRACTÉ (draft) |
| acquisition.delivery.recorded | acquisition.delivery.recorded.v1 | acquisition-service | reporting-service, audit-service, notification-service | Livraison enregistree | CONTRACTÉ (draft) |
| acquisition.reception.recorded | acquisition.reception.recorded.v1 | acquisition-service | reporting-service, audit-service, notification-service | Reception controlee enregistree | CONTRACTÉ (draft) |
| acquisition.item_accepted | acquisition.item_accepted.v1 | acquisition-service | asset-service, reporting-service, audit-service | Bien accepte — eligible a l'enregistrement patrimonial | CONTRACTÉ (draft) |
| acquisition.request.closed | acquisition.request.closed.v1 | acquisition-service | reporting-service, audit-service, notification-service | Dossier d'acquisition cloture | CONTRACTÉ (draft) |
| acquisition.request.cancelled | acquisition.request.cancelled.v1 | acquisition-service | reporting-service, audit-service, notification-service | Demande annulee | CONTRACTÉ (draft) |
| maintenance.request.created | maintenance.request.created.v1 | maintenance-service | workflow-service, reporting-service, audit-service, notification-service | Demande de maintenance creee | CONTRACTÉ (draft) — voir maintenance-service.asyncapi.yaml |
| maintenance.request.submitted | maintenance.request.submitted.v1 | maintenance-service | workflow-service, audit-service, notification-service | Demande soumise au circuit de validation | CONTRACTÉ (draft) |
| maintenance.request.validated | maintenance.request.validated.v1 | maintenance-service | reporting-service, audit-service, notification-service | Demande approuvee par Workflow | CONTRACTÉ (draft) |
| maintenance.request.rejected | maintenance.request.rejected.v1 | maintenance-service | reporting-service, audit-service, notification-service | Demande refusee / correction / annulee | CONTRACTÉ (draft) |
| maintenance.plan.scheduled | maintenance.plan.scheduled.v1 | maintenance-service | reporting-service, audit-service | Plan de maintenance preventive planifie | CONTRACTÉ (draft) |
| maintenance.intervention.started | maintenance.intervention.started.v1 | maintenance-service | reporting-service, audit-service, notification-service | Intervention demarree | CONTRACTÉ (draft) |
| maintenance.intervention.completed | maintenance.intervention.completed.v1 | maintenance-service | finance-service, reporting-service, audit-service, notification-service | Intervention cloturee — couts detailles (alimente Finance) | CONTRACTÉ (draft) — critique |
| maintenance.intervention.cancelled | maintenance.intervention.cancelled.v1 | maintenance-service | reporting-service, audit-service, notification-service | Intervention annulee ou interrompue | CONTRACTÉ (draft) |
| maintenance.spare_part.below_threshold | maintenance.spare_part.below_threshold.v1 | maintenance-service | notification-service, reporting-service, audit-service | Stock sous seuil de reapprovisionnement | CONTRACTÉ (draft) |

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
| audit.integrity_check_completed | audit.integrity_check_completed.v1 | audit-service | reporting-service, notification-service | Verification de chaine de hachage du journal | CONTRACTÉ (draft) |
| document.created | document.created.v1 | document-service | audit-service, reporting-service | Document depose | CONTRACTÉ (draft) |
| document.version_added | document.version_added.v1 | document-service | audit-service, reporting-service | Nouvelle version ajoutee (jamais d'ecrasement) | CONTRACTÉ (draft) |
| document.validated | document.validated.v1 | document-service | audit-service, workflow-service, reporting-service | Validation interne electronique | CONTRACTÉ (draft) |
| document.access_logged | document.access_logged.v1 | document-service | audit-service | Consultation/telechargement journalise | CONTRACTÉ (draft) — critique |
| document.lifecycle_changed | document.lifecycle_changed.v1 | document-service | audit-service, reporting-service | Transition archivistique ou elimination autorisee | CONTRACTÉ (draft) |
| notification.created | notification.created.v1 | notification-service | audit-service, reporting-service | Notification creee (tous canaux) | CONTRACTÉ (draft) |
| notification.acknowledged | notification.acknowledged.v1 | notification-service | audit-service, reporting-service | Accuse de prise en compte critique | CONTRACTÉ (draft) — critique |
| notification.delivery_failed | notification.delivery_failed.v1 | notification-service | audit-service, reporting-service | Echec definitif apres tentatives | CONTRACTÉ (draft) |
| finance.valuation_recorded | finance.valuation_recorded.v1 | finance-service | reporting-service, audit-service, notification-service | Valorisation de bien enregistree (connue ou indeterminee) | CONTRACTÉ (draft) |
| finance.depreciation_recorded | finance.depreciation_recorded.v1 | finance-service | reporting-service, audit-service | Amortissement calcule et enregistre | CONTRACTÉ (draft) |
| finance.cost_recorded | finance.cost_recorded.v1 | finance-service | reporting-service, audit-service, notification-service | Depense/cout associe a un bien (repartition biens partages incluse) | CONTRACTÉ (draft) |
| finance.disposal_approved | finance.disposal_approved.v1 | finance-service | asset-service, reporting-service, audit-service, notification-service | Sortie definitive approuvee par l'autorite competente | CONTRACTÉ (draft) — critique |
| finance.disposal_executed | finance.disposal_executed.v1 | finance-service | asset-service, reporting-service, audit-service, notification-service | Sortie definitive executee | CONTRACTÉ (draft) — critique |
| finance.disposal_closed | finance.disposal_closed.v1 | finance-service | asset-service, reporting-service, audit-service | Dossier de sortie cloture (preuve conservee par Document) | CONTRACTÉ (draft) |
| reporting.report_generated | reporting.report_generated.v1 | reporting-service | audit-service, notification-service | Execution de rapport terminee (reference, perimetre, periode) | CONTRACTÉ (draft) |
