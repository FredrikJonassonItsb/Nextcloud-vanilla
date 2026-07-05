#!/usr/bin/env bash
# 00-init.sh — create databases, roles and schemas for the ITSL Open Stack.
#
# IDEMPOTENT: runs on first boot (postgres docker entrypoint sources it from
# /docker-entrypoint-initdb.d) AND on every deploy via
#   docker compose exec -T brain-db bash /docker-entrypoint-initdb.d/00-init.sh
# so schema additions and password rotations in .env are picked up.
#
# Isolation model (CONTRACTS §4): one Postgres, six databases.
#   u_reb    -> brain_reb      (LOGIN, owner, can connect ONLY to its own DB)
#   u_atlas  -> brain_atlas
#   u_ada    -> brain_ada
#   u_marvin -> brain_marvin
#   u_team   -> brain_team
#   svc_engine -> engine_meta  (runner cost log + capture-bot dedupe + kv)
# CONNECT is revoked from PUBLIC on every stack DB, so no cross-DB access.
set -euo pipefail

SQL_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/sql"

# q — psql as superuser over the local socket (trust auth inside the container)
q() { psql -v ON_ERROR_STOP=1 -U postgres --no-psqlrc "$@"; }

role_exists() { [ "$(q -tAc "SELECT 1 FROM pg_roles WHERE rolname='$1'")" = "1" ]; }
db_exists()   { [ "$(q -tAc "SELECT 1 FROM pg_database WHERE datname='$1'")" = "1" ]; }

# ensure_role NAME PASSWORD — create if missing, always sync password
ensure_role() {
  local role="$1" pass="$2"
  if [ -z "$pass" ]; then
    echo "ERROR: empty password for role $role (check DB_PASS_* in .env)" >&2
    exit 1
  fi
  role_exists "$role" || q -c "CREATE ROLE $role LOGIN"
  q -c "ALTER ROLE $role LOGIN PASSWORD '$pass' NOSUPERUSER NOCREATEDB NOCREATEROLE"
}

# ensure_db NAME OWNER — create if missing, lock down connect rights
ensure_db() {
  local db="$1" owner="$2"
  db_exists "$db" || q -c "CREATE DATABASE $db OWNER $owner"
  q -c "ALTER DATABASE $db OWNER TO $owner"
  q -c "REVOKE CONNECT ON DATABASE $db FROM PUBLIC"
  q -c "GRANT CONNECT ON DATABASE $db TO $owner"
}

# ── the five brains ──────────────────────────────────────────────────────────
for agent in reb atlas ada marvin team; do
  role="u_${agent}"
  db="brain_${agent}"
  pass_var="DB_PASS_$(echo "$agent" | tr '[:lower:]' '[:upper:]')"
  pass="${!pass_var:-}"

  echo "init: $db / $role"
  ensure_role "$role" "$pass"
  ensure_db "$db" "$role"
  # pgvector needs superuser; everything else runs as the owning role so
  # tables/functions are owned per-brain.
  q -d "$db" -c "CREATE EXTENSION IF NOT EXISTS vector"
  q -d "$db" -c "SET ROLE $role" -f "$SQL_DIR/10-thoughts.sql"
done

# ── engine_meta ──────────────────────────────────────────────────────────────
echo "init: engine_meta / svc_engine"
ensure_role "svc_engine" "${DB_PASS_ENGINE:-}"
ensure_db "engine_meta" "svc_engine"
q -d engine_meta -c "SET ROLE svc_engine" -f "$SQL_DIR/20-engine-meta.sql"

echo "init: done."
