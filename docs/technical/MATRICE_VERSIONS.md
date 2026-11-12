# Matrice des versions techniques — SIGPÉ

Référence : SIGPE-TECH-VERS-002 — Date : 12 novembre 2026
Statut global : **PROPOSÉ** — soumise à validation du maître d'ouvrage.
Règle : aucun tag Docker `latest`. Versions vérifiées le **12/11/2026** sur les sources officielles listées en §10.
Légende statut : **VALIDÉ** = choix produit arrêté par le catalogue v1.1 ; **PROPOSÉ** = version recommandée à valider ; **À CONFIRMER** = information insuffisante.

> Distinction dev/prod : en dev, une même version est utilisée partout via Docker Compose. En production, les versions exactes seront figées après étude d'hébergement (point `[À CONFIRMER]` du catalogue §12).

## 1. Backend

### PHP

| Champ | Valeur |
|---|---|
| Version exacte recommandée | **8.5.12** |
| Date de publication | 21 octobre 2026 (correctif sécu critique CVE-2026-109618/109619) |
| Statut de maintenance | Active — branche 8.5 = version stable courante |
| Durée de support | Correctifs jusqu'au **31/12/2029** (php.net) ; 8.6.0 GA annoncé pour le 19/11/2026 |
| Compatibilité | Laravel 13 exige PHP 8.3–8.5 ✅ ; extensions : `pdo_pgsql`, `pgsql`, `bcmath`, `intl`, `zip`, `gd`, `redis` |
| Image Docker officielle | `php:8.5.12-cli`, `php:8.5.12-fpm` (variants `-alpine`/`-bookworm` à choisir) |
| Sources | php.net/ChangeLog-8.php · github.com/php/php-src/releases |
| Vérifié le | 12/11/2026 |
| Statut | **PROPOSÉ** (produit VALIDÉ par le catalogue ; version à figer) |

### Laravel

| Champ | Valeur |
|---|---|
| Version exacte recommandée | **13.42.0** (`^13.0` dans composer.json, version figée par `composer.lock`) |
| Date de publication | 10 novembre 2026 (dernière mineure de la série 13) |
| Statut de maintenance | Active — releases mensuelles |
| Durée de support | Sortie initiale 17/03/2026 ; bug fixes ~T3 2027 ; **correctifs sécurité jusqu'au 17/03/2028** (laravel.com/docs/releases) |
| Compatibilité | PHP 8.3–8.5 ✅ ; pas de version LTS — rester sur la dernière mineure patchée |
| Image Docker | Aucune officielle — `composer create-project` + Dockerfile maison par service |
| Sources | github.com/laravel/framework/tags · laravel.com/framework/docs/releases |
| Vérifié le | 12/11/2026 |
| Statut | **PROPOSÉ** |

### Composer

| Champ | Valeur |
|---|---|
| Version exacte recommandée | **2.11.2** |
| Date de publication | 10 novembre 2026 (correctifs sécu : GHSA-jhfp-4ppm-crmm et 4 autres) |
| Statut de maintenance | Active — 2.11.x = bug + sécurité ; 2.2.x LTS = sécurité seule jusqu'au 31/12/2026 |
| Durée de support | Voir ci-dessus (getcomposer.org/download) |
| Compatibilité | PHP 7.2.5+ ; en pratique PHP 8.5 ; `composer.lock` versionné obligatoire |
| Image Docker officielle | `composer:2.11.2` |
| Sources | getcomposer.org/download · getcomposer.org/changelog/2.11.2 |
| Vérifié le | 12/11/2026 |
| Statut | **PROPOSÉ** (2.11.2 a 2 jours — critique car correctif sécu ; repli 2.11.1) |

## 2. Données et messagerie

### PostgreSQL

| Champ | Valeur |
|---|---|
| Version exacte recommandée | **18.7** |
| Date de publication | **12 novembre 2026** (annonce officielle du jour — 52 vulnérabilités corrigées + >140 bugs ; PostgreSQL 14 passe EOL le même jour) |
| Statut de maintenance | Active — mineure de la série 18 |
| Durée de support | Série 18 supportée jusqu'au **14/11/2030** (postgresql.org/support/versioning) ; mineures correctives ~trimestrielles |
| Compatibilité | Laravel 13 : pgsql/`pdo_pgsql` ✅ ; `uuidv7()` natif disponible depuis PG 18 ✅ ; 13 bases `sigpe_*` sur une instance en dev |
| Image Docker officielle | `postgres:18.7` (alias `postgres:18`, variants `-alpine`/`-bookworm`) — **vérifier la disponibilité du tag mineur 18.7 à l'écriture du compose** (image publiée le jour même) ; repli documenté : `postgres:18.6` |
| Sources | postgresql.org/about/news/postgresql-187-1712-1616-1520-1425-and-19-rc-2-released-3442 · ftp.postgresql.org/pub/source · endoflife.date/postgresql |
| Vérifié le | 12/11/2026 |
| Statut | **PROPOSÉ** — ⚠️ version publiée le jour de la vérification : adoption justifiée par les correctifs sécu, tag Docker à confirmer |

### RabbitMQ

| Champ | Valeur |
|---|---|
| Version exacte recommandée | **4.4.0** |
| Date de publication | 20 octobre 2026 (annonce « RabbitMQ 4.4 Highlights ») |
| Statut de maintenance | Série 4.4.x active ; 4.3.x = repli mature |
| Durée de support | Releases communautaires ; suivre les séries supportées (rabbitmq.com/release-information) |
| Compatibilité | AMQP 0-9-1 ✅ ; requiert **Erlang/OTP 27–29** (27 min, 29 max) ; quorum queues pour files durables ; management plugin pour dev |
| Image Docker officielle | `rabbitmq:4.4.0-management` (vérifier le tag exact à l'écriture du compose) |
| Sources | rabbitmq.com/docs/which-erlang · rabbitmq.com/blog/2026/10/20/rabbitmq-4.4-release · github.com/rabbitmq/rabbitmq-server |
| Vérifié le | 12/11/2026 |
| Statut | **PROPOSÉ** (produit VALIDÉ par le catalogue ; version à figer) |

### Erlang/OTP

| Champ | Valeur |
|---|---|
| Version recommandée | **28.x** (embarqué dans l'image `rabbitmq:*`) — OTP 29.x possible pour le chiffrement post-quantique TLS |
| Compatibilité | RabbitMQ 4.4 : OTP 27–29 ; OTP 26 = EOL non supporté |
| Image Docker | Incluse dans `rabbitmq:4.4.0-management` — **ne pas installer Erlang séparément en dev** |
| Sources | rabbitmq.com/docs/which-erlang |
| Vérifié le | 12/11/2026 |
| Statut | **PROPOSÉ** (détail d'exploitation, pas un composant déployé seul) |

## 3. Edge et identité

### Apache APISIX (Community Edition)

| Champ | Valeur |
|---|---|
| Version exacte recommandée | **3.19.0** |
| Date de publication | 28 septembre 2026 |
| Statut de maintenance | Active — releases ~mensuelles |
| Durée de support | Pas de LTS publique APISIX — suivre la série 3.x |
| Compatibilité | Plugins `openid-connect`/`jwt-auth` pour les jetons Keycloak ; config déclarative YAML en dev ; 3.20.0 (11/11/2026) contient des changements non rétrocompatibles à évaluer avant adoption |
| Image Docker officielle | `apache/apisix:3.19.0-debian` (vérifier le tag exact : variantes `-debian`/`-centos`/`-ubuntu`) |
| Sources | apisix.apache.org/blog · github.com/apache/apisix/releases · CHANGELOG.md |
| Vérifié le | 12/11/2026 |
| Statut | **PROPOSÉ** — 3.20.0 écartée (<7 jours + changements cassants) ; réévaluer après rodage |

### Keycloak

| Champ | Valeur |
|---|---|
| Version exacte recommandée | **26.8.2** |
| Date de publication | 5 novembre 2026 |
| Statut de maintenance | Active — releases mensuelles, pas de LTS |
| Compatibilité | OIDC/OAuth2 ✅ ; realm `sigpe` à créer en Phase 0 ; PostgreSQL comme base Keycloak en dev |
| Image Docker officielle | `quay.io/keycloak/keycloak:26.8.2` |
| Sources | keycloak.org/downloads · github.com/keycloak/keycloak/releases |
| Vérifié le | 12/11/2026 |
| Statut | **PROPOSÉ** — `start-dev` en dev uniquement ; mode `start` + base dédiée requis en préprod/prod |

## 4. Frontend

### Node.js

| Champ | Valeur |
|---|---|
| Version exacte recommandée | **26.13.1 LTS** (codename « Lithium », LTS depuis le 28/10/2026) |
| Date de publication | 11 novembre 2026 (patch LTS) |
| Statut de maintenance | **LTS** jusqu'à ~avril 2029 |
| Compatibilité | Requis par Vite 8 (`@types/node` ^20.19 \|\| >=22.12 ✅) ; usage = build frontend uniquement, jamais runtime métier |
| Distribution | nodejs.org (installeur/paquets) — pas d'image runtime nécessaire en dev si build local ; image `node:26.13.1-alpine` possible pour CI |
| Sources | nodejs.org/en/blog/release |
| Vérifié le | 12/11/2026 |
| Statut | **PROPOSÉ** |

### React

| Champ | Valeur |
|---|---|
| Version exacte recommandée | **19.3.1** |
| Date de publication | ~mi-octobre 2026 |
| Statut de maintenance | Stable — releases régulières |
| Compatibilité | Node ≥18 ; Vite 8 ✅ ; canaux canary/experimental interdits |
| Distribution | npm `react@19.3.1` / `react-dom@19.3.1` — figé dans `package-lock.json` |
| Sources | reactjs.dev (release tracker) · npmjs.com |
| Vérifié le | 12/11/2026 |
| Statut | **PROPOSÉ** (produit VALIDÉ par le catalogue) |

### Vite

| Champ | Valeur |
|---|---|
| Version exacte recommandée | **8.3.7** (repli) / 8.3.8 publiée le 11/11/2026 — <7 jours, réévaluer |
| Date de publication | 8.3.8 : 11/11/2026 ; 8.3.7 : ~10/11/2026 |
| Statut de maintenance | Active |
| Compatibilité | Node ≥22.12 ✅ (Node 26 LTS) ; esbuild ^0.27–0.28 ; jiti ≥1.21 |
| Distribution | npm `vite` — figé dans `package-lock.json` |
| Sources | npmjs.com/package/vite |
| Vérifié le | 12/11/2026 |
| Statut | **À CONFIRMER** (version exacte au scaffold frontend — Phase ultérieure) |

### Vitest / React Testing Library / Playwright

| Champ | Valeur |
|---|---|
| Version recommandée | Dernières stables au moment du scaffold, figées dans `package-lock.json` |
| Statut | **À CONFIRMER** — non évaluées dans cette mission (hors périmètre Phase 0 infra) |

## 5. Conteneurisation

### Docker Engine

| Champ | Valeur |
|---|---|
| Version exacte recommandée | **29.9.3** (dernière stable 29.x) |
| Date de publication | 3 novembre 2026 |
| Statut de maintenance | Active — correctifs mensuels ; majeure 29 = rupture vs 28 (à traiter comme upgrade majeur sur les postes existants) |
| Compatibilité | Docker Desktop (Windows/WSL2) et docker-ce (Linux) ; API 1.56, minimum 1.40 |
| Sources | docs.docker.com/engine/release-notes/29 |
| Vérifié le | 12/11/2026 |
| Statut | **PROPOSÉ** — en pratique la version suit Docker Desktop ; documenter la version minimale requise |

### Docker Compose

| Champ | Valeur |
|---|---|
| Version exacte recommandée | **v5.7.x** (plugin `docker compose`, v5.7.1 publiée le 11/11/2026 — <7 jours ; v5.7.0 du 02/11 acceptable) |
| Date de publication | v5.7.0 : 02/11/2026 ; v5.7.1 : 11/11/2026 |
| Compatibilité | Incluse dans Docker Desktop ; `docker compose version` pour vérifier ; `docker-compose.yml` unique racine |
| Sources | github.com/docker/compose/releases |
| Vérifié le | 12/11/2026 |
| Statut | **À CONFIRMER** — la version effective dépend de l'installation locale ; consigne : `version` non requise dans le fichier, syntaxe Compose v2+ |

## 6. Stockage objet — DÉCISION OUVERTE (voir ADR-010)

| Candidat | Licence | État | Image / déploiement | Statut |
|---|---|---|---|---|
| **MinIO CE** | AGPLv3 (code source archivé) | ❌ Archivé le 25/04/2026 ; maintenance mode ; images retirées de Docker Hub (~09/2026) ; Quay rejette les pulls anonymes depuis le 24/09/2026 ; dernière image `RELEASE.2025-10-15T17-29-55Z` ; correctif CVE-2025-62506 jamais publié en image ; build possible depuis les sources | `alpine/minio:RELEASE.2025-10-15T17-29-55Z` (miroir communautaire) — non recommandé | **REJETÉ pour le dev** — non maintenu, CVE connues |
| **Garage** | AGPL-3.0 | ✅ Maintenu (Deuxfleurs, association sans but lucratif, utilisé en production depuis 2020, financé NGI/NLnet) ; binaire unique, ~1 Go RAM / 16 Go disque par nœud ; geo-réplication sans consensus ; limites S3 : pas de versioning bucket, Object Lock, ACL/bucket policies, SSE-S3 (SSE-C + chiffrement disque OK) | Image officielle : **À CONFIRMER** (dépôt `deuxfleurs-org/garage`, doc garagehq.deuxfleurs.fr) | **PROPOSÉ pour le dev** |
| **SeaweedFS** | Apache-2.0 (cœur) ; Enterprise payant | ✅ Très actif ; S3 le plus complet (versioning, Object Lock, SSE-KMS, IAM/OIDC, bucket policies) ; architecture multi-composants (master/volume/filer/s3) plus lourde ; licence Enterprise requise pour la production (>dev/test sous 25 To) | `chrislusf/seaweedfs:<version figée>` — version exacte **À CONFIRMER** | **PROPOSÉ en alternative** si les limites S3 de Garage bloquent |
| **MinIO AIStor** | Propriétaire (abonnement) ; Free = mono-nœud, Lite <400 Tio, Enterprise = support | ⚠️ Produit commercial actif — mais dépendance fournisseur et coût ; le code AGPL reste disponible sans maintenance | Distribution propriétaire (hors Docker Hub public pour AIStor) | **À CONFIRMER par le MOA** — pertinent seulement si budget et support officiels retenus |

**Recommandation argumentée (à valider)** : **Garage pour le développement et la recette locale** — maintenu, simple (un conteneur), licence AGPL acceptable pour un usage interne, S3 suffisant pour les besoins V1 (les versions documentaires sont gérées au niveau applicatif par Document Service ; le chiffrement repose sur TLS + chiffrement disque). **SeaweedFS** en alternative si une fonctionnalité S3 manquante devient bloquante. **MinIO AIStor** uniquement sur décision budgétaire/institutionnelle du MOA. La production reste soumise au choix d'hébergement souverain `[À CONFIRMER]`.

## 7. Observabilité

| Composant | Version exacte recommandée | Date publication | Maintenance | Image Docker officielle | Statut |
|---|---|---|---|---|---|
| Prometheus | **3.16.0** (LTS : 3.13.5 du 21/10/2026) | 04/11/2026 | Active ; LTS 3.13.x pour la stabilité | `prom/prometheus:v3.16.0` | PROPOSÉ |
| Grafana | **13.2.2** | 15/09/2026 | Active | `grafana/grafana:13.2.2` | PROPOSÉ |
| Loki | **3.7.8** | 17/09/2026 | Active (3.8 non publiée) | `grafana/loki:3.7.8` | PROPOSÉ |
| Tempo | **3.1.1** | 21/10/2026 | Active | `grafana/tempo:3.1.1` | PROPOSÉ |
| OpenTelemetry Collector (contrib) | **À CONFIRMER** — version figée au compose (alternative : Grafana Alloy 1.21.0 du 22/10/2026, `grafana/alloy:v1.21.0`) | — | — | `otel/opentelemetry-collector-contrib:<pin>` | À CONFIRMER |

## 8. Synthèse des écarts vs versions candidates du prompt

| Candidate fournie | Constat vérifié au 12/11/2026 | Décision |
|---|---|---|
| PHP 8.5.12 | ✅ Confirmée (21/10/2026) | Retenue |
| Laravel 13.x | ✅ 13.42.0 (10/11/2026) | Retenue |
| PostgreSQL 18.6 | ⚠️ **18.7 publiée le 12/11/2026** (52 CVE) — les archives consultées par le MOA datent d'avant l'annonce | **18.7 recommandée** ; si le tag Docker `postgres:18.7` est indisponible à l'écriture du compose, repli `postgres:18.6` documenté |
| RabbitMQ 4.4.x | ✅ 4.4.0 (20/10/2026) | Retenue |
| APISIX 3.19.x | ✅ 3.19.0 (28/09) ; 3.20.0 du 11/11 = changements cassants | 3.19.0 recommandée |
| Keycloak 26.8.2 | ✅ (05/11/2026) | Retenue |
| Node.js 26 LTS | ✅ 26.13.1 LTS | Retenue |
| Docker Engine / Compose | 29.9.3 / v5.7.x | À confirmer selon installation |
| MinIO | ❌ CE archivé ; AIStor = commercial | Décision ouverte — ADR-010 |

## 9. Règles de révision

- Toute montée de version = ADR ou note de décision + mise à jour de cette matrice + validation MOA.
- Les images Docker sont toujours épinglées sur un tag exact (jamais `latest`, jamais de tag flottant).
- Les dépendances applicatives (composer.json, package.json) sont figées par leurs fichiers de lock.

## 10. Sources officielles consultées (12/11/2026)

php.net/ChangeLog-8.php · github.com/php/php-src/releases · github.com/laravel/framework/tags · laravel.com/framework/docs/releases · getcomposer.org/download + /changelog/2.11.2 · **postgresql.org/about/news/postgresql-187-1712-1616-1520-1425-and-19-rc-2-released-3442** · postgresql.org/support/versioning · ftp.postgresql.org/pub/source · endoflife.date/postgresql · rabbitmq.com/docs/which-erlang · rabbitmq.com/blog/2026/10/20/rabbitmq-4.4-release · github.com/rabbitmq/rabbitmq-server · apisix.apache.org/blog · github.com/apache/apisix/releases · keycloak.org/downloads · github.com/keycloak/keycloak/releases · nodejs.org/en/blog/release · reactjs.dev · npmjs.com/package/vite · docs.docker.com/engine/release-notes/29 · github.com/docker/compose/releases · github.com/deuxfleurs-org/garage + garagehq.deuxfleurs.fr · github.com/seaweedfs/seaweedfs + seaweedfs.com · min.io/pricing + github.com/minio/minio · prometheus.io/download · grafana.com release notes · github.com/grafana/tempo.
