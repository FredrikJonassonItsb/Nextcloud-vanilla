#!/usr/bin/env bash
#
# deploy.sh — deploy the ITSL Open Stack compose stack to dev15.
#
# Runs FROM Windows Git Bash (or any POSIX shell with ssh + tar + GNU tar).
# Pattern: tar-over-ssh (same style as scripts/dev15-reset.sh in the main repo).
#
# IDEMPOTENT:
#   - existing values in /opt/openstack/.env are NEVER overwritten
#   - missing secrets are generated with `openssl rand -hex 32`
#   - LE cert dir is re-detected and re-copied on every run (picks up renewals)
#   - `docker compose up -d --build` only recreates what changed
#   - DB init (roles/DBs/schema) is re-applied via the idempotent 00-init.sh
#
# Usage:
#   bash stack/deploy.sh
#   OPENSTACK_SSH="ubuntu@10.43.51.62" bash stack/deploy.sh
set -euo pipefail
export MSYS_NO_PATHCONV=1

SSH_TARGET="${OPENSTACK_SSH:-ubuntu@10.43.51.62}"
REMOTE_DIR="/opt/openstack"
SSH_OPTS=(-o BatchMode=yes -o ConnectTimeout=15)

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(dirname "$SCRIPT_DIR")"

# ── 1) Sync sources: tar-over-ssh ────────────────────────────────────────────
COMPONENTS=(stack openbrain-svc capture-bot runner ingestion)
present=()
for c in "${COMPONENTS[@]}"; do
  if [[ -d "$REPO_ROOT/$c" ]]; then
    present+=("$c")
  else
    echo "WARN: $REPO_ROOT/$c saknas — hoppar över (compose build för tjänsten misslyckas tills den finns)"
  fi
done
[[ ${#present[@]} -gt 0 ]] || { echo "ERROR: inget att deploya"; exit 1; }

echo "→ Syncar ${present[*]} → ${SSH_TARGET}:${REMOTE_DIR} …"
tar czf - -C "$REPO_ROOT" \
  --exclude='node_modules' \
  --exclude='.git' \
  --exclude='*.env' \
  --exclude='.env.*.local' \
  --exclude='dist' \
  "${present[@]}" \
| ssh "${SSH_OPTS[@]}" "$SSH_TARGET" \
    "sudo mkdir -p '$REMOTE_DIR' && sudo chown \"\$(id -un)\": '$REMOTE_DIR' && tar xzf - -C '$REMOTE_DIR'"

# ── 2) Remote: .env, certs, compose up, healthchecks ────────────────────────
echo "→ Remote provisioning + docker compose up …"
ssh "${SSH_OPTS[@]}" "$SSH_TARGET" bash -s <<'REMOTE'
set -euo pipefail
REMOTE_DIR=/opt/openstack
ENV_FILE="$REMOTE_DIR/.env"
cd "$REMOTE_DIR"

# Normalize line endings on shell scripts (source tree may come from Windows)
find "$REMOTE_DIR" -maxdepth 5 \( -name '*.sh' -o -name '*.sql' \) -exec sed -i 's/\r$//' {} +

touch "$ENV_FILE"
chmod 600 "$ENV_FILE"

# --- .env helpers: keep existing values, only fill what is missing -----------
has_val() { grep -Eq "^$1=..*" "$ENV_FILE"; }   # key present with non-empty value
has_key() { grep -Eq "^$1="    "$ENV_FILE"; }   # key present at all
gen_secret() {                                  # generate 32B hex if empty/missing
  local k="$1" v
  has_val "$k" && return 0
  v="$(openssl rand -hex 32)"
  if has_key "$k"; then sed -i "s|^$k=.*|$k=$v|" "$ENV_FILE"; else echo "$k=$v" >> "$ENV_FILE"; fi
  echo "  + genererade $k"
}
ensure_var() {                                  # append default if key missing (may be empty)
  local k="$1" v="${2:-}"
  has_key "$k" || echo "$k=$v" >> "$ENV_FILE"
}
set_var() {                                     # always set (used for CERT_DIR)
  local k="$1" v="$2"
  if has_key "$k"; then sed -i "s|^$k=.*|$k=$v|" "$ENV_FILE"; else echo "$k=$v" >> "$ENV_FILE"; fi
}

# --- secrets: generated once, then kept (CONTRACTS §8) ------------------------
for a in REB ATLAS ADA MARVIN TEAM; do
  gen_secret "BRAIN_KEY_$a"
  gen_secret "DB_PASS_$a"
done
for a in REB ATLAS ADA MARVIN; do
  gen_secret "TALK_BOT_SECRET_$a"
done
gen_secret ENGINE_PUSH_SECRET
gen_secret POSTGRES_PASSWORD
gen_secret DB_PASS_ENGINE

# --- externally provided values: created empty, filled in by Fredrik/occ-provision
ensure_var OPENROUTER_API_KEY ""
ensure_var ANTHROPIC_API_KEY ""
for a in REB ATLAS ADA MARVIN ENGINE; do ensure_var "BOT_APP_PASSWORD_$a" ""; done
ensure_var NC_BASE "https://dev15.hubs.se"
ensure_var ZAMMAD_TOKEN ""
ensure_var ZAMMAD_BASE_URL "https://zammad.itsl.se"
ensure_var RUNNER_ENABLED "0"
ensure_var RUNNER_DAILY_USD_CAP "10"
ensure_var EMBED_MODEL "openai/text-embedding-3-small"

# --- TLS: autodetect the LE cert dir that covers dev15.hubs.se ----------------
# LE 'live' dirs contain symlinks into ../../archive, so we COPY (-L) the pair
# into /opt/openstack/certs instead of bind-mounting the live dir (symlinks
# would be dangling inside the container). Re-copied every deploy => renewals
# are picked up by re-running deploy.sh.
CERT_SRC=""
for d in $(sudo sh -c 'ls -d /opt/project_data/proxy/letsencrypt/live/*/ 2>/dev/null' || true); do
  sudo test -e "$d/fullchain.pem" || continue
  if sudo openssl x509 -in "$d/fullchain.pem" -noout -subject -ext subjectAltName 2>/dev/null \
     | grep -q 'dev15\.hubs\.se'; then
    CERT_SRC="${d%/}"
    break
  fi
done

CERT_DIR="$REMOTE_DIR/certs"
mkdir -p "$CERT_DIR"
if [ -n "$CERT_SRC" ]; then
  echo "  + LE-cert: $CERT_SRC → $CERT_DIR"
  sudo cp -L "$CERT_SRC/fullchain.pem" "$CERT_SRC/privkey.pem" "$CERT_DIR/"
  sudo chown "$(id -un)": "$CERT_DIR"/fullchain.pem "$CERT_DIR"/privkey.pem
  chmod 600 "$CERT_DIR"/privkey.pem
else
  echo "  ! Inget LE-cert som täcker dev15.hubs.se hittades — self-signed fallback"
  if [ ! -f "$CERT_DIR/fullchain.pem" ]; then
    openssl req -x509 -newkey rsa:2048 -nodes -days 825 \
      -keyout "$CERT_DIR/privkey.pem" -out "$CERT_DIR/fullchain.pem" \
      -subj "/CN=dev15.hubs.se" -addext "subjectAltName=DNS:dev15.hubs.se"
    chmod 600 "$CERT_DIR/privkey.pem"
  fi
fi
set_var CERT_DIR "$CERT_DIR"

# --- runtime dirs / placeholder files -----------------------------------------
mkdir -p "$REMOTE_DIR/backups"
if [ -d "$REMOTE_DIR/capture-bot" ] && [ ! -f "$REMOTE_DIR/capture-bot/rooms.json" ]; then
  echo '{}' > "$REMOTE_DIR/capture-bot/rooms.json"   # real content comes from occ-provision
fi

# --- compose up ----------------------------------------------------------------
dc() { sudo docker compose --env-file "$ENV_FILE" "$@"; }
cd "$REMOTE_DIR/stack"

echo "→ docker compose up -d --build …"
dc up -d --build

echo "→ väntar på brain-db …"
db_ok=0
for i in $(seq 1 60); do
  cid="$(dc ps -q brain-db 2>/dev/null || true)"
  if [ -n "$cid" ]; then
    s="$(sudo docker inspect -f '{{.State.Health.Status}}' "$cid" 2>/dev/null || echo starting)"
    if [ "$s" = "healthy" ]; then db_ok=1; break; fi
  fi
  sleep 2
done
[ "$db_ok" = 1 ] || { echo "ERROR: brain-db blev aldrig healthy"; dc ps; exit 1; }

echo "→ återapplicerar idempotent DB-init (roller, databaser, schema) …"
dc exec -T brain-db bash /docker-entrypoint-initdb.d/00-init.sh

echo "→ väntar på healthchecks (max 240 s) …"
deadline=$(( $(date +%s) + 240 ))
unhealthy=""
while :; do
  pending=0
  unhealthy=""
  for id in $(dc ps -q); do
    st="$(sudo docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$id")"
    name="$(sudo docker inspect -f '{{.Name}}' "$id" | sed 's|^/||')"
    case "$st" in
      starting)  pending=$((pending + 1)) ;;
      unhealthy) unhealthy="$unhealthy $name" ;;
    esac
  done
  [ "$pending" -eq 0 ] && break
  if [ "$(date +%s)" -ge "$deadline" ]; then
    echo "  ! timeout — några containrar är fortfarande 'starting'"
    break
  fi
  sleep 5
done
if [ -n "$unhealthy" ]; then
  echo "  ! UNHEALTHY:$unhealthy"
fi

echo ""
echo "=== STATUS (/opt/openstack/stack) ==="
dc ps
echo ""
echo "Aggregerad hälsa:  curl -k https://dev15.hubs.se:8843/healthz"
echo "Kom ihåg: OPENROUTER_API_KEY / ANTHROPIC_API_KEY / BOT_APP_PASSWORD_* fylls i i /opt/openstack/.env"
REMOTE

echo "✓ Deploy klart."
