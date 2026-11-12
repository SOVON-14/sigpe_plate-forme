# ADR-008 — Sécurité, RBAC et ABAC

- **Statut** : VALIDÉ (catalogue v1.1 §8, TEC-002 ; CDC §3.5, §7)
- **Date** : 12/11/2026
- **Provenance** : Validée dans le catalogue technique v1.1 (§8 ; TEC-002 « Arrêtée ») et CDC v1.0 §3.5/§7.

## Contexte
Données sensibles cloisonnées par institution ; 11 profils métier ; délégations temporaires.

## Problème
Contrôler l'accès à chaque ressource sans dépendre uniquement du rôle.

## Décision
- **RBAC + ABAC combinés** : rôle (qui) + attributs (institution, unité organisationnelle, sensibilité, délégation, contexte).
- Le JWT Keycloak prouve l'authentification ; **chaque service évalue les autorisations métier** — la Gateway ne remplace pas ces contrôles.
- MFA pour comptes et opérations sensibles ; délégations limitées (bénéficire, portée, durée) et révocables.
- Séparation des tâches : pas d'auto-approbation, pas d'auto-élévation de privilèges, rôles incompatibles signalés.
- Auditeur = lecture seule ; exports nominatifs contrôlés et journalisés.
- Matrices de référence : `contracts/permissions/MATRICE_RBAC.md` et `MATRICE_ABAC.md` (extraites du CDC §7).

## Alternatives étudiées
- RBAC pur : insuffisant pour le cloisonnement institutionnel → rejeté.
- ABAC pur : trop verbeux pour les droits de base → combiné.
-OPA/Cedar externe : non retenu par le catalogue ; middleware Laravel maison + policies.

## Conséquences positives
- Cloisonnement garanti au plus près de la donnée.
- Traçabilité des refus (journalisation).

## Conséquences négatives / risques
- Complexité d'évaluation → policy classes Laravel dédiées + tests d'isolation obligatoires (niveau 4 des tests).
- Performances : claims JWT à concevoir pour limiter les allers-retours Identity.

## Risques
Contournement via API Gateway → chaque service re-vérifie ; tests de pénétration requis avant recette.

## Dépendances

- ADR-002 (jetons OIDC Keycloak).
- ADR-005 (contrôles à chaque endpoint).
