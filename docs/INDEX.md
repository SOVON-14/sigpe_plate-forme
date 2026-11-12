# Index documentaire officiel — SIGPÉ

Référence : SIGPE-DOC-INDEX-001 — Date : 12 novembre 2026
Hiérarchie de lecture obligatoire : catalogue technique v1.1 → cahier des charges v1.0 → rapport d'audit → diagrammes (secondaire).

## Documents de référence

| ID | Nom | Version | Date | Statut | Niveau d'autorité | Remplace | Emplacement |
|---|---|---|---|---|---|---|---|
| DOC-001 | Catalogue technique SIGPÉ | v1.1 | 12/11/2026 | Référentiel de développement — visa institutionnel requis | **Autorité architecturale et technique** | v1.0 (non conservée) | `docs/technical/SIGPE_Catalogue_Technique_v1.1_2026-11-12.docx` |
| DOC-002 | Cahier des charges fonctionnel et matrice des rôles | v1.0 | 11/11/2026 | Base consolidée à faire approuver | **Autorité métier et fonctionnelle** | — | `docs/technical/SIGPE_Cahier_Des_Charges_v1.0_2026-11-11.docx` |
| DOC-003 | Rapport consolidé d'audit du catalogue | SIGPE-AUD-RAP-001 | 12/11/2026 | Référence des décisions et corrections | Autorité sur le registre des décisions (lots 1-13) | — | `docs/technical/SIGPE_Rapport_Audit_Consolide_2026-11-12.docx` |
| DOC-004 | Diagrammes UML complets (15 diagrammes) | — | ~12/11/2026 | Référence visuelle secondaire, **partiellement obsolète** | Consultatif uniquement | — | `docs/architecture/SIGPE_15_diagrammes_UML.pdf`, `docs/architecture/SIGPE_diagrammes_UML_complet.drawio` |

## Documents obsolètes — exclus du développement

| ID | Nom | Motif d'exclusion | Emplacement |
|---|---|---|---|
| DOC-OLD-001 | Responsabilités détaillées des 12 microservices | 12 services au lieu de 13 ; frontières corrigées par le catalogue v1.1 (AUD-001, COR-001) | `docs/technical/archive/SIGPE_Responsabilites_12_microservices_OBSOLETE.docx` |

## Incohérences connues des diagrammes UML (DOC-004)

- Noms de services différents des 13 noms officiels (d04).
- Inventaire en mode déconnecté — exclu de la V1 (catalogue §7.1, CDC §4.6).
- Transfert sans confirmation Asset obligatoire (COR-007, TEC-017).
- Plusieurs exchanges RabbitMQ — le catalogue impose `sigpe.events.v1` unique.
- Kubernetes/Redis/PostGIS — non retenus ; Docker Compose pour le dev, orchestrateur de production non arrêté.

## Livrables produits par la Phase 0 documentaire

| Livrable | Emplacement |
|---|---|
| Matrice des versions techniques | `docs/technical/MATRICE_VERSIONS.md` |
| Guide des conventions de développement | `docs/technical/CONVENTIONS_DEVELOPPEMENT.md` |
| Matrice de propriété des données | `docs/technical/MATRICE_PROPRIETE_DONNEES.md` |
| ADR-001 à ADR-013 | `docs/adr/` |
| Schéma enveloppe événementielle | `contracts/json-schemas/enveloppe-evenementiel.schema.json` |
| Modèle OpenAPI | `contracts/openapi/_template-service.openapi.yaml` |
| Modèle AsyncAPI | `contracts/asyncapi/_template-service.asyncapi.yaml` |
| Matrice producteurs/consommateurs | `contracts/events/MATRICE_PRODUCTEURS_CONSUMMATEURS.md` |
| Matrice RBAC | `contracts/permissions/MATRICE_RBAC.md` |
| Matrice ABAC | `contracts/permissions/MATRICE_ABAC.md` |

## Règle d'usage

- En cas de divergence entre documents : le catalogue v1.1 prime sur le technique, le CDC v1.0 prime sur le métier.
- Tout point marqué `[À CONFIRMER]` ou `[PROPOSITION]` n'est pas implémentable comme règle acquise.
- Les routes et noms d'événements du catalogue sont des propositions jusqu'à production des contrats OpenAPI/AsyncAPI.
