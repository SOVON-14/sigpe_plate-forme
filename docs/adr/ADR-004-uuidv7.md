# ADR-004 — Stratégie UUIDv7 pour les identifiants

- **Statut** : VALIDÉ (catalogue v1.1, TEC-004 « Arrêtée »)
- **Date** : 12/11/2026
- **Provenance** : Validée dans le catalogue technique v1.1 (TEC-004 « Arrêtée »).

## Contexte
Les identifiants doivent être générés de façon distribuée sans coordination centrale.

## Problème
Choisir un format d'identifiant technique stable, unique et(index-friendly).

## Décision
- UUIDv7 (RFC 9562) pour tous les identifiants techniques : `asset_id`, `user_id`, `institution_id`, `event_id`, etc.
- Génération côté application (ou `uuidv7()` PostgreSQL 18 natif pour les defaults).
- `asset_id` : stable, immuable, indépendant du `code_patrimonial`.
- `code_patrimonial` : identifiant métier lisible, `UNIQUE(institution_id, code_patrimonial)` — jamais utilisé comme clé technique.

## Alternatives étudiées
- UUIDv4 : aléatoire, index B-tree fragmentés — rejeté.
- Auto-incrément : prévisible, non portable entre services — rejeté.
- ULID : proche de UUIDv7 mais non standard RFC pour nos contrats — rejeté.

## Conséquences positives
- Tri chronologique naturel, génération distribuée sans séquence centrale.
- Opaque (pas de fuite d'information métier).

## Conséquences négatives / risques
-UUIDv7 expose l'horodatage de création → acceptable pour des IDs internes (pas des secrets).
- Nécessite une bibliothèque de génération dans chaque service (ex. `symfony/uid` côté Laravel).

## Risques
Usage accidentel de l'ID comme secret → interdit ; les secrets sont des tokens distincts.
