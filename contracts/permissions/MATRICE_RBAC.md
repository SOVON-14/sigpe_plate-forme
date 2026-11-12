# Matrice RBAC — SIGPÉ

Référence : SIGPE-RBAC-001 — Version 1.0 — 12/11/2026
Source : CDC v1.0 §7 (légende A/C/L/M/V/X, `*` = droits conditionnels). **Base fonctionnelle de référence** — extraite sans modification ; les permissions effectives sont toujours combinées au périmètre institutionnel, à l'unité, à la sensibilité et aux délégations. « Oui » n'accorde jamais un accès national illimité.

| Domaine / action | Admin principal | Admin central | Admin ministériel | Resp. direction | Gestionnaire patrimoine | Agent inventaire | Resp. financier | Resp. maintenance | Autorité approbatrice | Auditeur/contrôleur | Agent consultant |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Institutions nationales | A | M | L | — | — | — | — | — | — | L | — |
| Organisation du ministère | A | M | A | L | L | L | L | L | L | L | L |
| Comptes et rôles | A | M | A* | — | — | — | — | — | — | L | — |
| Référentiels nationaux | A | M | L / proposition | L | L | L | L | L | L | L | L |
| Paramètres ministériels locaux | A | M | A | L | L | L | L | L | L | L | — |
| Registre des biens | L | L | L/M* | L | C/M/X | L/X | L | L | V* | L | L |
| Identifiants patrimoniaux | A | M* | M* | L | C/X* | L | L | L | V* | L | L |
| Inventaires | L | L | A | L/V* | C/M/X | C/X | L | L | V* | L | L/X* |
| Affectations et mouvements | L | L | A* | V* | C/M/X | L/X* | L | L | V* | L | L |
| Transferts interministeriels | L | Coord./V* | V* | L | C/X* | L | L | L | V* | L | — |
| Prêts et biens partagés | L | Coord./V* | V* | V* | C/M/X | L/X* | L | L | V* | L | L |
| Acquisitions et réceptions | L | L | A* | V* | C/X* | L/X* | V* | L | V* | L | — |
| Maintenance et stocks | L | L | A* | L | L/X* | L | L | C/M/X | V* | L | L |
| Documents | A* | A* | A* | L/C* | C/M* | C/M* | L/C* | C/M* | V* | L | L* |
| Données financières | L | L | L | L | L* | L | C/M/X | L | V* | L | — |
| Sorties définitives | L | Coord./V* | V* | C/V* | C/X* | L | L | L | V* | L | — |
| Rapports et exports | A | A* | A* | L/X* | L/X* | L/X* | L/X* | L/X* | L/X* | L/X* | L* |
| Audit et journaux | A* | L | L* | — | — | — | — | — | — | L | — |
| Incidents de sécurité | A | Coord./X* | X* | C* | C* | C* | C* | C* | — | L* | — |
| Notifications | A | A* | A* | L | L | L | L | L | L | L | L |
| Support et tickets | A | A* | A* | C/L | C/L | C/L | C/L | C/L | C/L | L | C/L |

`*` = droits conditionnels : uniquement dans le périmètre attribué, si le workflow le prévoit et après vérification des conflits de séparation des tâches. Les administrateurs techniques ne disposent pas automatiquement d'un pouvoir d'approbation métier.

## Règles particulières par rôle (CDC §7.2)

- **Admin principal** : institutions, gouvernance nationale, habilitations centrales ; pas d'effacement d'historique.
- **Admin central** : fonctions déléguées, supervision ; ne crée pas de ministère sans délégation exceptionnelle tracée.
- **Admin ministériel** : son ministère uniquement ; pas d'auto-élévation.
- **Resp. direction** : supervision de son unité ; valide uniquement les étapes attribuées.
- **Gestionnaire patrimoine** : fiches, affectations, inventaires, mouvements, prêts, sorties, corrections dans sa délégation.
- **Agent inventaire** : relevés, scans QR, constats — ne modifie pas les données maîtres.
- **Resp. financier** : valeurs, évaluations, amortissements, dépenses autorisées.
- **Resp. maintenance** : demandes, plans, interventions, prestataires, stocks, coûts.
- **Autorité approbatrice** : approuve/refuse/suspend uniquement les dossiers affectés par le workflow.
- **Auditeur** : lecture des dossiers/preuves/journaux ; pas de modification ; exports nominatifs journalisés.
- **Agent consultant** : lecture limitée à son périmètre ; soumission de demandes si accordée.
