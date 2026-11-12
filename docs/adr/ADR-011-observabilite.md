# ADR-011 — Stratégie d'observabilité

- **Statut** : VALIDÉ pour le socle (catalogue v1.1 §2.1 : OpenTelemetry, Prometheus, Grafana, Loki, Tempo) — versions PROPOSÉES dans `MATRICE_VERSIONS.md`
- **Date** : 12/11/2026
- **Provenance** : Validée dans le catalogue technique v1.1 (§2.1 ; TEC-014 « Arrêtée »).

## Contexte
13 services Laravel + gateway + broker + bases ; supervision exigée (catalogue §9.3, ENF-12).

## Problème
Produire logs, métriques et traces corrélés sans outillage propriétaire.

## Décision
- **OpenTelemetry** : instrumentation des services (traces + métriques) ; `correlation_id`/`traceparent` propagés des headers HTTP aux événements RabbitMQ.
- **Prometheus** : collecte des métriques (services, APISIX, RabbitMQ exporter, PostgreSQL exporter).
- **Grafana** : visualisation.
- **Loki** : logs centralisés.
- **Tempo** : traces distribuées corrélées aux logs/métriques.
- Collecte via un collecteur (Grafana Alloy ou otel-collector-contrib — version figée au compose).
- Logs structurés JSON en stdout (12-factor) ; aucun secret ni PII dans les logs.

## Alternatives étudiées
- ELK : plus lourd, non retenu par le catalogue.
- Cloud propriétaire : exclu (hébergement souverain).

## Conséquences positives
- Corrélation bout-en-bout : requête → service → événement → consumer.
- Standard OTel = instrumentation cohérente entre les 13 services.

## Conséquences négatives / risques
- Coût d'instrumentation par service → convention commune dès le premier service (Identity).
- Cardinalité des métriques → labels limités (pas d'IDs métier en label).

## Risques
Panne de l'observabilité masquant une panne métier → superviseur externe minimal (healthchecks + alerting).

## Dépendances

- ADR-005/ADR-006 (propagation correlation_id/traceparent HTTP et AMQP).
- ADR-007 (traçage des retries/DLQ).
