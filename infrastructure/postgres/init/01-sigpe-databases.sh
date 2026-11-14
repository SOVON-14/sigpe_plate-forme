#!/usr/bin/env bash
# SIGPE — création des 13 bases et utilisateurs (un par microservice).
# Exécuté une seule fois par le point d'entrée officiel de l'image postgres.
set -euo pipefail

: "${POSTGRES_PASSWORD:?POSTGRES_PASSWORD requis}"

SERVICES=(
  identity organization asset inventory movement workflow
  acquisition maintenance audit document notification finance reporting
)

for svc in "${SERVICES[@]}"; do
  db="sigpe_${svc}"
  user="sigpe_${svc}"
  psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<SQL
DO \$\$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '${user}') THEN
        CREATE ROLE ${user} LOGIN PASSWORD '${POSTGRES_PASSWORD}';
    END IF;
END
\$\$;
SQL
  psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
    -tc "SELECT 1 FROM pg_database WHERE datname = '${db}'" | grep -q 1 \
    || psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
       -c "CREATE DATABASE ${db} OWNER ${user};"
  psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
    -c "GRANT ALL PRIVILEGES ON DATABASE ${db} TO ${user};"
done
