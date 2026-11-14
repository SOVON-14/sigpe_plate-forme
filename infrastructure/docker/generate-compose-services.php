<?php

/**
 * Injecte dans compose.yaml les services applicatifs SIGPE (13 apps + workers),
 * Keycloak, MinIO, APISIX et les initialiseurs one-shot.
 * Idempotent : ne réinjecte pas si le marqueur est présent.
 * Usage : php infrastructure/docker/generate-compose-services.php
 */

$compose = dirname(__DIR__, 2).'/compose.yaml';
$yaml = file_get_contents($compose);

if (str_contains($yaml, '# --- SIGPE services (généré) ---')) {
    echo "Compose déjà généré, rien à faire.\n";
    exit(0);
}

$services = ['identity', 'organization', 'asset', 'inventory', 'movement', 'workflow', 'acquisition', 'maintenance', 'audit', 'document', 'notification', 'finance', 'reporting'];
$consumers = ['acquisition', 'audit', 'finance', 'maintenance', 'movement', 'notification', 'reporting'];

$block = <<<'YAML'

  # --- SIGPE services (généré) ---
  rabbitmq-init:
    image: curlimages/curl:8.10.1
    container_name: sigpe-rabbitmq-init
    restart: "no"
    environment:
      SIGPE_RABBITMQ_PASSWORD: ${RABBITMQ_PASSWORD}
      RABBITMQ_ADMIN_USER: ${RABBITMQ_USER}
      RABBITMQ_ADMIN_PASSWORD: ${RABBITMQ_PASSWORD}
      RABBITMQ_HOST: rabbitmq
    volumes:
      - ./infrastructure/rabbitmq/init:/init:ro
    entrypoint: ["sh", "/init/01-sigpe-rabbitmq.sh"]
    depends_on:
      rabbitmq:
        condition: service_healthy
    networks:
      - sigpe_network

  keycloak:
    image: quay.io/keycloak/keycloak:26.4.6
    container_name: sigpe-keycloak
    restart: unless-stopped
    command: ["start-dev", "--import-realm"]
    environment:
      KC_BOOTSTRAP_ADMIN_USERNAME: ${KEYCLOAK_ADMIN_USERNAME:-admin}
      KC_BOOTSTRAP_ADMIN_PASSWORD: ${KEYCLOAK_ADMIN_PASSWORD}
      KC_HTTP_ENABLED: "true"
      KC_HOSTNAME: http://localhost:8081
      KC_HEALTH_ENABLED: "true"
      SIGPE_ADMIN_PASSWORD: ${SIGPE_ADMIN_PASSWORD}
      SIGPE_KC_SECRET_IDENTITY: ${SIGPE_KC_SECRET_IDENTITY}
      SIGPE_KC_SECRET_ORGANIZATION: ${SIGPE_KC_SECRET_ORGANIZATION}
      SIGPE_KC_SECRET_ASSET: ${SIGPE_KC_SECRET_ASSET}
      SIGPE_KC_SECRET_INVENTORY: ${SIGPE_KC_SECRET_INVENTORY}
      SIGPE_KC_SECRET_MOVEMENT: ${SIGPE_KC_SECRET_MOVEMENT}
      SIGPE_KC_SECRET_WORKFLOW: ${SIGPE_KC_SECRET_WORKFLOW}
      SIGPE_KC_SECRET_ACQUISITION: ${SIGPE_KC_SECRET_ACQUISITION}
      SIGPE_KC_SECRET_MAINTENANCE: ${SIGPE_KC_SECRET_MAINTENANCE}
      SIGPE_KC_SECRET_AUDIT: ${SIGPE_KC_SECRET_AUDIT}
      SIGPE_KC_SECRET_DOCUMENT: ${SIGPE_KC_SECRET_DOCUMENT}
      SIGPE_KC_SECRET_NOTIFICATION: ${SIGPE_KC_SECRET_NOTIFICATION}
      SIGPE_KC_SECRET_FINANCE: ${SIGPE_KC_SECRET_FINANCE}
      SIGPE_KC_SECRET_REPORTING: ${SIGPE_KC_SECRET_REPORTING}
    volumes:
      - ./infrastructure/keycloak:/opt/keycloak/data/import:ro
    ports:
      - "127.0.0.1:8081:8080"
    healthcheck:
      test: ["CMD-SHELL", "exec 3<>/dev/tcp/127.0.0.1/8080 && printf 'GET /realms/sigpe/.well-known/openid-configuration HTTP/1.0\r\n\r\n' >&3 && grep -q 'issuer' <&3"]
      interval: 15s
      timeout: 5s
      retries: 10
      start_period: 60s
    networks:
      - sigpe_network

  minio:
    image: minio/minio:RELEASE.2025-09-07T16-13-09Z
    container_name: sigpe-minio
    restart: unless-stopped
    command: server /data --console-address ":9001"
    environment:
      MINIO_ROOT_USER: ${MINIO_ROOT_USER}
      MINIO_ROOT_PASSWORD: ${MINIO_ROOT_PASSWORD}
    ports:
      - "127.0.0.1:9000:9000"
      - "127.0.0.1:9001:9001"
    volumes:
      - sigpe_minio_data:/data
    healthcheck:
      test: ["CMD", "mc", "ready", "local"]
      interval: 10s
      timeout: 5s
      retries: 5
      start_period: 10s
    networks:
      - sigpe_network

  minio-init:
    image: minio/mc:RELEASE.2025-08-13T08-35-41Z
    container_name: sigpe-minio-init
    restart: "no"
    depends_on:
      minio:
        condition: service_healthy
    entrypoint: >
      /bin/sh -c "
      mc alias set local http://minio:9000 $${MINIO_ROOT_USER} $${MINIO_ROOT_PASSWORD} &&
      mc mb --ignore-existing local/sigpe-documents &&
      mc anonymous set none local/sigpe-documents
      "
    environment:
      MINIO_ROOT_USER: ${MINIO_ROOT_USER}
      MINIO_ROOT_PASSWORD: ${MINIO_ROOT_PASSWORD}
    networks:
      - sigpe_network

  apisix:
    image: apache/apisix:3.14.1-debian
    container_name: sigpe-apisix
    restart: unless-stopped
    volumes:
      - ./gateway/apisix/config.yaml:/usr/local/apisix/conf/config.yaml:ro
      - ./gateway/apisix/apisix.yaml:/usr/local/apisix/conf/apisix.yaml:ro
    ports:
      - "127.0.0.1:9080:9080"
      - "127.0.0.1:9443:9443"
    depends_on:
      - identity-service
    networks:
      - sigpe_network

YAML;

foreach ($services as $svc) {
    $upper = strtoupper($svc);
    $block .= <<<YAML

  {$svc}-service:
    build:
      context: ./services/{$svc}-service
    image: sigpe/{$svc}-service:dev
    container_name: sigpe-{$svc}
    restart: unless-stopped
    env_file:
      - ./services/{$svc}-service/.env
    environment:
      DB_PASSWORD: \${POSTGRES_PASSWORD}
      RABBITMQ_PASSWORD: \${RABBITMQ_PASSWORD}
      KEYCLOAK_ISSUER: http://localhost:8081/realms/sigpe
      KEYCLOAK_JWKS_URI: http://keycloak:8080/realms/sigpe/protocol/openid-connect/certs
      KEYCLOAK_TOKEN_URL: http://keycloak:8080/realms/sigpe/protocol/openid-connect/token
      KEYCLOAK_CLIENT_SECRET: \${SIGPE_KC_SECRET_{$upper}}
      APP_ENV: local
    command: ["sh", "-c", "php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=8080"]
    depends_on:
      postgres:
        condition: service_healthy
      rabbitmq:
        condition: service_healthy
      rabbitmq-init:
        condition: service_completed_successfully
      keycloak:
        condition: service_started
    healthcheck:
      test: ["CMD-SHELL", "php -r \"exit((int)!@fsockopen('127.0.0.1', 8080));\""]
      interval: 30s
      timeout: 5s
      retries: 5
      start_period: 30s
    networks:
      - sigpe_network

  {$svc}-worker:
    image: sigpe/{$svc}-service:dev
    container_name: sigpe-{$svc}-worker
    restart: unless-stopped
    env_file:
      - ./services/{$svc}-service/.env
    environment:
      DB_PASSWORD: \${POSTGRES_PASSWORD}
      RABBITMQ_PASSWORD: \${RABBITMQ_PASSWORD}
      KEYCLOAK_ISSUER: http://localhost:8081/realms/sigpe
      KEYCLOAK_JWKS_URI: http://keycloak:8080/realms/sigpe/protocol/openid-connect/certs
      KEYCLOAK_TOKEN_URL: http://keycloak:8080/realms/sigpe/protocol/openid-connect/token
      KEYCLOAK_CLIENT_SECRET: \${SIGPE_KC_SECRET_{$upper}}
      APP_ENV: local
YAML;

    $block .= "\n";
    $consume = in_array($svc, $consumers, true) ? 'php artisan sigpe:events:consume &' : '';
    $block .= <<<YAML
    command: ["sh", "-c", "php artisan queue:work database --sleep=3 --tries=3 & {$consume} while true; do php artisan sigpe:outbox:publish || true; sleep 5; done"]
    depends_on:
      postgres:
        condition: service_healthy
      rabbitmq:
        condition: service_healthy
      rabbitmq-init:
        condition: service_completed_successfully
      keycloak:
        condition: service_started
    networks:
      - sigpe_network
YAML;
}

// Montage de l'init postgres + volumes
$yaml = str_replace(
    "    volumes:\n      - sigpe_postgres_data:/var/lib/postgresql",
    "    volumes:\n      - sigpe_postgres_data:/var/lib/postgresql\n      - ./infrastructure/postgres/init:/docker-entrypoint-initdb.d:ro",
    $yaml
);
$yaml = str_replace(
    "volumes:\n  sigpe_postgres_data:",
    $block."\nvolumes:\n  sigpe_postgres_data:\n  sigpe_minio_data:",
    $yaml
);

file_put_contents($compose, $yaml);
echo "compose.yaml mis à jour (".count($services)." services + workers).\n";
