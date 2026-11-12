# ADR-012 — Stratégie de versionnement des contrats

- **Statut** : VALIDÉ (catalogue v1.1 §5.2, TEC-006/007/008)
- **Date** : 12/11/2026
- **Provenance** : Validée dans le catalogue technique v1.1 (§5.2 ; TEC-006/007/008 « Arrêtées »).

## Contexte
Les contrats API et événementiels doivent être documentés, versionnés et testables.

## Problème
Éviter les ruptures silencieuses entre 13 équipes/services.

## Décision
- **OpenAPI 3.1** : un `openapi.yaml` par service dans `contracts/openapi/<service>.openapi.yaml`, exposant `/api/v1/...`.
- **AsyncAPI 3.0** : un `asyncapi.yaml` par service dans `contracts/asyncapi/` pour ses événements produits/consommés.
- **JSON Schema** : schéma de l'enveloppe (`contracts/json-schemas/enveloppe-evenementiel.schema.json`) + schéma par `data` d'événement.
- **Versionnement** : `event_version` entier dans l'enveloppe ; `event_type` en minuscules `domaine.action` ; routing key suffixée `.vN`.
- **Matrice des flux** : `contracts/events/MATRICE_PRODUCTEURS_CONSUMMATEURS.md` = source de vérité des flux.
- **Tests de contrat** : `tests/contract/` valide schémas, compatibilité et consommateurs.
- Règle : un contrat publié n'est jamais modifié de façon incompatible sans nouvelle version ; les consommateurs doivent tolérer les champs inconnus (`additionalProperties` maîtrisé).

## Alternatives étudiées
- Contrat d'abord (schema registry type Apicurio) : non retenu — fichiers versionnés dans Git + tests suffisent à cette échelle.
- Protobuf/gRPC : hors périmètre.

## Conséquences positives
- Contrats relisibles, diffables, testables en CI.
- Évolution additive possible sans casser les consommateurs.

## Conséquences négatives / risques
- Discipline requise : tout changement de payload = changement de schéma + notification consommateurs.
- Pas de registry runtime → les fichiers Git font foi ; un service peut implémenter un contrat non publié → revues obligatoires.

## Risques
Dérive producteur/consommateur → tests de contrat bloquants en CI.

## Dépendances

- ADR-006/ADR-007 (sémantique des événements).
- ADR-013 (projections alignées sur les versions d’événements).
