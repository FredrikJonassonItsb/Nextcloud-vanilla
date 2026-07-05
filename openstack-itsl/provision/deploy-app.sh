#!/usr/bin/env bash
#
# deploy-app.sh — hubs-style deploy of the agent_engine Nextcloud app to dev15.
#
# Pattern (per the hubs-ops runbook): tar the app dir from the Windows host,
# stream it over ssh into the hubs-php container's custom_apps, chown to
# www-data, enable the app, run `occ upgrade` (migrations), then verify that
# the OCS route answers 401 (auth required) — NOT 404 (route missing).
# No container restart — opcache picks up PHP changes by itself.
#
# Usage:
#   provision/deploy-app.sh
#   APP_DIR=/path/to/apps/agent_engine HUBS_SSH=ubuntu@10.43.51.62 provision/deploy-app.sh
#
set -euo pipefail

SSH_TARGET="${HUBS_SSH:-ubuntu@10.43.51.62}"
SSH_OPTS=(-o BatchMode=yes -o ConnectTimeout=15)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${APP_DIR:-${SCRIPT_DIR}/../apps/agent_engine}"
APP_ID=agent_engine

if [ ! -d "$APP_DIR" ]; then
  echo "FEL: appkatalogen saknas: $APP_DIR (sätt APP_DIR)" >&2
  exit 1
fi
if [ ! -f "$APP_DIR/appinfo/info.xml" ]; then
  echo "FEL: $APP_DIR/appinfo/info.xml saknas — är detta verkligen appen?" >&2
  exit 1
fi
if [ "$(basename "$APP_DIR")" != "$APP_ID" ]; then
  echo "FEL: appkatalogen måste heta '$APP_ID' (är: $(basename "$APP_DIR"))" >&2
  exit 1
fi

echo "→ Packar och laddar upp ${APP_ID} → ${SSH_TARGET} …"
# shellcheck disable=SC2029  # client-side expansion of APP_ID is intended
(
  cd "$(dirname "$APP_DIR")" &&
    MSYS_NO_PATHCONV=1 tar czf - "$APP_ID"
) | ssh "${SSH_OPTS[@]}" "$SSH_TARGET" \
  "sudo docker exec -i hubs-php tar xzf - -C /var/www/html/custom_apps &&
   sudo docker exec hubs-php chown -R www-data:www-data /var/www/html/custom_apps/${APP_ID}"

echo "→ Aktiverar appen + kör migrationer (occ upgrade)…"
ssh "${SSH_OPTS[@]}" "$SSH_TARGET" bash -s <<'REMOTE'
set -euo pipefail
occ() { sudo docker exec -u www-data hubs-php php /var/www/html/occ "$@"; }
occ app:enable agent_engine
occ upgrade
ver="$(occ config:app:get agent_engine installed_version 2>/dev/null || echo '?')"
echo "  ✓ agent_engine aktiverad, installed_version=${ver}"
REMOTE

echo "→ Verifierar OCS-rutt (förväntar 401 = registrerad + auth krävs, INTE 404)…"
code="$(ssh "${SSH_OPTS[@]}" "$SSH_TARGET" \
  "sudo docker exec hubs-php curl -s -o /dev/null -w '%{http_code}' -H 'OCS-APIRequest: true' \
   http://hubs-apache/ocs/v2.php/apps/agent_engine/api/v1/takeover/config")"
case "$code" in
  401)
    echo "  ✓ OCS svarar 401 — rutten är registrerad och kräver auth. OK."
    ;;
  404)
    echo "  ✗ OCS svarar 404 — rutten är INTE registrerad (routes.php/appen trasig?)." >&2
    exit 1
    ;;
  *)
    echo "  ! OCS svarar ${code} (förväntade 401) — kontrollera manuellt."
    ;;
esac

echo "✓ Deploy av ${APP_ID} klar."
