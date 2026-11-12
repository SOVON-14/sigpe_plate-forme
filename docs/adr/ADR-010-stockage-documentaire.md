# ADR-010 — Stratégie de stockage documentaire

- **Statut** : PROPOSÉ — décision finale réservée au maître d'ouvrage
- **Date** : 12/11/2026 (révisé lors de l'audit correctif)

## Contexte
Document Service stocke fichiers, versions, 8 catégories, 4 niveaux de confidentialité. Le catalogue impose un « stockage objet privé compatible S3 », MinIO étant cité pour le développement local.

## Problème — état réel de MinIO Community Edition

Constats vérifiés le 12/11/2026 :

- **Code source** : dépôt GitHub `minio/minio` **archivé en lecture seule le 25 avril 2026** — plus de développement.
- **Binaires** : les versions historiques restent téléchargeables, mais **sans maintenance ni correctifs**.
- **Images Docker** : `minio/minio` et `minio/mc` **retirées de Docker Hub** (~septembre 2026) ; Quay refuse les pulls anonymes depuis le 24/09/2026.
- **Build depuis les sources** : techniquement possible, sans intérêt (code figé).
- **Sécurité** : CVE-2025-62506 (élévation de privilèges via service accounts/STS) corrigée dans les sources mais **jamais publiée dans une image** ; la dernière image `RELEASE.2025-10-15T17-29-55Z` ne contient pas ce correctif.
- **Licence** : AGPLv3 pour le code ; AIStor = produit propriétaire par abonnement.

## Étude comparative des candidats

| Critère | A. Garage | B. SeaweedFS | C. MinIO AIStor |
|---|---|---|---|
| Licence | AGPL-3.0 | Apache-2.0 (cœur) ; Enterprise payant | Propriétaire (abonnement ; Free mono-nœud, Lite <400 Tio, Enterprise) |
| Gouvernance | Deuxfleurs (association à but non lucratif), financé NGI/NLnet | Communauté active + éditeur commercial (seaweedfs.com) | MinIO Inc. |
| Maintenance | ✅ Active, en production chez Deuxfleurs depuis 2020 | ✅ Très active | ✅ Commerciale |
| Compatibilité S3 | Bonne (noyau) ; **manques** : versioning bucket, Object Lock, ACL/bucket policies, SSE-S3 (SSE-C + chiffrement disque OK) | **La plus complète** : versioning, Object Lock, SSE-KMS, IAM/OIDC, bucket policies, ~73 ops S3 | S3 complet + Iceberg |
| Exploitation Docker | ✅ Binaire unique, 1 conteneur (~1 Go RAM, 16 Go disque) | ⚠️ Plus lourd : master + volume + filer + gateway S3 | ⚠️ Distribution propriétaire |
| Auth/contrôle d'accès | Clés par bucket via CLI/API admin ; pas de bucket policies | Complet (policies, IAM, OIDC) | Complet |
| Chiffrement | SSE-C + chiffrement disque ; pas de SSE-S3 | SSE-S3/KMS/C | Complet |
| Réplication/sauvegarde | Geo-réplication native multi-sites, déduplication, Zstd | Réplication EC configurable, tiering cloud | Réplication erasure coding |
| Exploitation | ✅ Simple | ⚠️ Plus de composants à superviser | ✅ Simple mais dépendant |
| Coût | Gratuit (AGPL) | Cœur gratuit ; **production >dev/test ≤25 To = licence Enterprise ~$2/TB/mois** | Abonnement (Free/Lite/Enterprise) |
| Risque dépendance | Faible (code ouvert, association) | Moyen (modèle open-core) | **Élevé** (propriétaire, coût récurrent) |

## Décision proposée

- **Contrat d'interface** : API compatible S3 stricte — aucun appel propriétaire.
- **Développement/recette** : **Garage** — maintenu, mono-conteneur, suffisant pour la V1 (versions documentaires gérées au niveau applicatif par Document Service ; chiffrement = TLS + disque ; Object Lock non requis en V1 car l'audit repose sur l'Application, pas sur le stockage).
- **Alternative** : SeaweedFS si une exigence S3 (versioning, Object Lock, SSE-KMS) devient bloquante.
- **Production** : stockage souverain compatible S3 ou solution retenue par le MOA — **`[À CONFIRMER HÉBERGEMENT]`**.
- **MinIO AIStor** : uniquement sur décision budgétaire/institutionnelle explicite.

## Conséquences

- **Positives** : développement débloqué sur un backend maintenu ; interface S3 préserve la portabilité ; coût nul en dev.
- **Négatives** : les manques S3 de Garage devront être compensés applicativement (versioning applicatif, journalisation des accès — déjà prévus par le catalogue pour Document Service).
- **Risques** : choix production reporté → ne pas introduire de dépendance à des API non-S3 ; si MinIO AIStor est retenu plus tard, la couche d'abstraction S3 absorbe le changement.

## Dépendances

- ADR-003 (isolation des bases : les fichiers ne transitent jamais par PostgreSQL).
- ADR-009 (secrets/chiffrement : clés d'accès S3 par environnement).
- ADR-011 (observabilité : métriques du backend objet).
