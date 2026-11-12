# Matrice de propriété des données — SIGPÉ

Référence : SIGPE-DATA-OWN-001 — Version 1.0 — 12/11/2026
Règle absolue (catalogue §1.2) : **un seul service est propriétaire et seul écrivain de chaque donnée métier maîtresse.** Les autres services consultent, projettent ou référencent — jamais d'écriture croisée, jamais d'accès direct aux bases.

| Domaine de données | Propriétaire (seul écrivain) | Base | Consommateurs autorisés (lecture/projection) |
|---|---|---|---|
| Comptes fonctionnels, rôles, permissions, délégations, périmètres | Identity | `sigpe_identity` | Tous (via API/contrats) |
| Institutions, unités organisationnelles, hiérarchie, responsables | Organization | `sigpe_organization` | Identity (mapping), Asset, Movement, tous (références) |
| Fiches patrimoniales maîtresses, `asset_id`, codes, QR, localisations physiques, statuts admin/physiques, dossiers de réforme/sortie, fiche maître biens partagés | Asset | `sigpe_asset` | Movement, Inventory, Maintenance, Finance, Reporting, Audit, Acquisition |
| Campagnes d'inventaire, agents, constats, anomalies | Inventory | `sigpe_inventory` | Asset (corrections demandées), Reporting, Audit |
| Affectations, transferts, prêts, gardiens, bénéficiaires et droits d'usage des biens partagés | Movement | `sigpe_movement` | Asset, Reporting, Notification |
| Circuits, étapes, validations, délégations de workflow, décisions | Workflow | `sigpe_workflow` | Tous les services métier (demandes de validation) |
| Interventions, diagnostics, plans, prestataires (référentiel national), contrats ministériels, pièces, stocks, coûts opérationnels | Maintenance | `sigpe_maintenance` | Asset, Finance (coûts officiels), Reporting |
| Fichiers, versions, métadonnées documentaires, catégories, confidentialité, accès, archives | Document | `sigpe_document` | Tous (références `document_id`) |
| Valeurs officielles, amortissements, coûts financiers, valorisation de sortie, répartition des coûts partagés | Finance | `sigpe_finance` | Asset, Reporting |
| Projections, indicateurs, tableaux de bord, rapports | Reporting | `sigpe_reporting` | Tous (lecture) — jamais source de vérité |
| Journaux d'audit, preuves, traces d'opérations sensibles | Audit | `sigpe_audit` | Administrateurs/auditeurs (lecture contrôlée) |
| Notifications, messages, accusés, relances, préférences | Notification | `sigpe_notification` | Tous (producteur d'événements) |
| Dossier d'acquisition complet : besoin, validations, commande, livraison, réception, réserves, clôture | Acquisition | `sigpe_acquisition` | Asset (biens acceptés), Finance, Movement, Reporting |

## Règles de lecture

- **Références logiques** : les services stockent des UUID (`asset_id`, `institution_id`, `document_id`…) sans FK SQL.
- **Demandes de correction** : Inventory, Workflow ou tout service constatant une anomalie **transmet une demande** au propriétaire ; seul le propriétaire modifie.
- **Données financières** : Maintenance conserve les coûts opérationnels détaillés ; Finance enregistre les valeurs officielles — pas de double saisie contradictoire.
- **Identité** : Keycloak gère l'authentification ; Identity gère les habilitations métier. Aucun service ne duplique ni l'un ni l'autre.
- **Suppression** : uniquement logique, par le propriétaire, avec historisation.
