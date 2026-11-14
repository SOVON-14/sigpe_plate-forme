#!/usr/bin/env bash
# SIGPE — RabbitMQ : vhost `sigpe`, utilisateur `sigpe`, exchange topic `sigpe.events.v1`.
# Conteneur one-shot : utilise l'API de management HTTP (port 15672).
set -euo pipefail

: "${SIGPE_RABBITMQ_PASSWORD:?SIGPE_RABBITMQ_PASSWORD requis}"
: "${RABBITMQ_ADMIN_USER:?RABBITMQ_ADMIN_USER requis}"
: "${RABBITMQ_ADMIN_PASSWORD:?RABBITMQ_ADMIN_PASSWORD requis}"

API="http://${RABBITMQ_HOST:-rabbitmq}:15672/api"
AUTH="${RABBITMQ_ADMIN_USER}:${RABBITMQ_ADMIN_PASSWORD}"

api() { # method path [json-body]
  local method="$1" path="$2" body="${3:-}"
  if [ -n "$body" ]; then
    curl -fsS -u "$AUTH" -H 'content-type: application/json' -X "$method" "$API$path" -d "$body" -o /dev/null -w '%{http_code}\n'
  else
    curl -fsS -u "$AUTH" -X "$method" "$API$path" -o /dev/null -w '%{http_code}\n'
  fi
}

# Attente du broker
for i in $(seq 1 60); do
  curl -fsS -u "$AUTH" "$API/overview" -o /dev/null 2>/dev/null && break
  sleep 2
done

# Vhost sigpe (201 = créé, 204 = existe déjà)
api PUT "/vhosts/sigpe" || true

# Utilisateur sigpe + mot de passe
api PUT "/users/sigpe" '{"password":"'"${SIGPE_RABBITMQ_PASSWORD}"'","tags":""}' || true

# Permissions complètes sur le vhost sigpe
api PUT "/permissions/sigpe/sigpe" '{"configure":".*","write":".*","read":".*"}'

# Exchange topic durable sigpe.events.v1
api PUT "/exchanges/sigpe/sigpe.events.v1" '{"type":"topic","durable":true,"auto_delete":false,"internal":false,"arguments":{}}'

echo "RabbitMQ SIGPE initialisé (vhost=sigpe, exchange=sigpe.events.v1)"
