# Matrice ABAC — SIGPÉ

Référence : SIGPE-ABAC-001 — Version 1.0 — 12/11/2026
L'ABAC s'ajoute au RBAC (ADR-008) : chaque requête est évaluée sur les attributs ci-dessous **par le microservice propriétaire**, après validation du jeton OIDC par la Gateway.

## Attributs d'évaluation

| Attribut | Source | Exemple de règle |
|---|---|---|
| `institution_id` | claim JWT / contexte Identity | Un gestionnaire ne voit que les biens de son ministère |
| `unit_id` / unité organisationnelle | Identity + Organization | Un responsable de direction ne voit que son unité et ses subordonnées |
| `role` | claim JWT (rôle fonctionnel SIGPÉ) | RBAC de base (MATRICE_RBAC) |
| `sensitivity` | métadonnée de la ressource | Document « Hautement sensible » : rôles restreints + journalisation |
| `delegation` | Identity (délégation active) | Valider à la place d'un absent dans le périmètre/durée |
| `workflow_state` | service métier | Approuver uniquement si l'étape est assignée au demandeur |
| `ownership` | ressource | L'auteur d'une demande ne peut pas l'approuver (séparation des tâches) |
| `time` | horodatage | Délégation expirée → refus ; opération sensible → réauthentification/MFA |
| `channel` | requête | Export nominatifs : journalisation renforcée |

## Règles transversales obligatoires

1. **Cloisonnement institutionnel** : refus par défaut hors `institution_id` de l'utilisateur — même pour un admin ministériel.
2. **Périmètre organisationnel** : les unités subordonnées sont incluses selon la hiérarchie Organization.
3. **Séparation des tâches** : créateur ≠ approbateur sur les opérations sensibles ; cumul de rôles incompatibles signalé.
4. **Délégations** : vérification existence, bénéficiaire, portée, dates ; expiration automatique.
5. **MFA/réauthentification** : exigée pour opérations sensibles et exports nominatifs.
6. **Journalisation des refus** : tout refus 403 sur ressource sensible est tracé (Audit).
7. **Auditeur** : lecture seule ; toute écriture → 403.
8. **Aucun pouvoir d'approbation pour les admins techniques** : l'infrastructure ne confère pas de droit métier.

## Format d'évaluation (proposition d'implémentation Laravel)

- Policies par ressource : `before()` pour les droits globaux, méthode par action (`view`, `create`, `update`, `approve`, `export`).
- Contexte ABAC résolu par un middleware/service d'autorisation alimenté par les claims JWT + appels Identity/Organization mis en cache court.
- Décision tracée : `allow|deny` + motif + attributs évalués → log structuré + événement Audit si sensible.
