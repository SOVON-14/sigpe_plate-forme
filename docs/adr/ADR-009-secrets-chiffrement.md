# ADR-009 — Gestion des secrets et clés de chiffrement

- **Statut** : PROPOSÉ (catalogue §5.2 liste « stratégie de gestion des clés » comme arbitrage encore ouvert)
- **Date** : 12/11/2026

## Contexte
TLS, chiffrement au repos, sauvegardes chiffrées, aucun secret dans Git — mais le catalogue laisse la stratégie de clés ouverte.

## Problème
Où et comment stocker les secrets en dev, intégration, préproduction et production ?

## Décision (proposée)
- **Développement local** : fichiers `.env` jamais versionnés (`.env.example` versionné avec valeurs factices) ; `.gitignore` couvre `.env*` sauf `.env.example`.
- **Environnements mutualisés** : variables d'environnement injectées par l'orchestrateur ; secrets dans un gestionnaire dédié (Vault ou équivalent souverain — choix dépendant de l'hébergement national, point `[À CONFIRMER]`).
- **Clés de chiffrement données** : clé par domaine critique (documents, audit, sauvegardes), rotation planifiée, jamais dans les dépôts ni les images.
- **Rotation** : procédure documentée ; version des secrets pour permettre la relecture.
- **Interdictions absolues** : secrets dans Git, dans les logs, dans les événements RabbitMQ, dans les payloads OpenAPI d'exemple.

## Alternatives étudiées
- `.env` versionnés chiffrés (ex. SOPS/age) : acceptable en dev, à évaluer pour la prod selon souveraineté.
- Secrets en base : rejeté (surface d'attaque).

## Conséquences positives
- Réversibilité des compromissions ; conformité aux exigences du catalogue.

## Conséquences négatives / risques
- Dépendance à un gestionnaire de secrets à choisir selon l'infrastructure nationale → point soumis à validation institutionnelle (catalogue §12).
- Rotation de clés = opération à tester en recette.

## Risques
Fuite par image Docker ou log → scans de secrets en CI obligatoires.

## Dépendances

- ADR-010 (identifiants d’accès au stockage objet).
- ADR-011 (aucun secret dans les logs/mesures).
