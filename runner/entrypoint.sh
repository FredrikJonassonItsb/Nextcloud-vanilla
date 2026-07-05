#!/bin/bash
# entrypoint.sh — runner container PID 1 (CONTRACTS §7)
#
# Gate: the runner only arms itself when RUNNER_ENABLED=1 AND ANTHROPIC_API_KEY
# is set. Otherwise it sits in an idle loop with a clear log line every 10 min,
# so the compose stack can be deployed complete before Fredrik has filled the
# keys into /opt/openstack/.env (CONTRACTS §8: RUNNER_ENABLED=0 until then).
#
# When armed:
#   1. snapshot the relevant env vars to /run/runner-env (cron strips env)
#   2. install the staggered crontab for user `runner` and start cron (root)
#   3. exec the wake listener on :8791 as user `runner` (container lives and
#      dies with the listener)
#
# Env consumed here: RUNNER_ENABLED, ANTHROPIC_API_KEY, ENGINE_PUSH_SECRET.
# Everything else is passed through to run-agent.sh / wake-listener.js.

set -euo pipefail

log() {
  printf '%s runner[entrypoint]: %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"
}

# ---------------------------------------------------------------- gate ------
if [[ "${RUNNER_ENABLED:-0}" != "1" || -z "${ANTHROPIC_API_KEY:-}" ]]; then
  key_state="missing"
  [[ -n "${ANTHROPIC_API_KEY:-}" ]] && key_state="set"
  # Disarmed listener: answers /healthz (compose healthcheck stays green) and
  # 503s every wake. No cron, no runs — nothing can spend money without keys.
  if [[ -n "${ENGINE_PUSH_SECRET:-}" ]]; then
    RUNNER_ARMED=0 setpriv --reuid runner --regid runner --init-groups \
      node /app/wake-listener.js &
  else
    log "ENGINE_PUSH_SECRET unset — wake listener not started in disabled mode"
  fi
  while true; do
    log "runner disabled — waiting for keys (RUNNER_ENABLED=${RUNNER_ENABLED:-0}, ANTHROPIC_API_KEY=${key_state})"
    sleep 600
  done
fi

: "${ENGINE_PUSH_SECRET:?ENGINE_PUSH_SECRET is required when RUNNER_ENABLED=1 (CONTRACTS §8)}"
: "${NC_BASE:?NC_BASE is required (e.g. https://dev15.hubs.se)}"

# ------------------------------------------- env snapshot for cron jobs -----
# Debian cron gives jobs an empty environment; run-agent.sh sources this file
# when its key vars are missing. chmod 600 + owned by the run user only.
ENVFILE=/run/runner-env
: > "$ENVFILE"
chmod 600 "$ENVFILE"
chown runner:runner "$ENVFILE"
while IFS= read -r v; do
  printf 'export %s=%q\n' "$v" "${!v}" >> "$ENVFILE"
done < <(compgen -v | grep -E '^(ANTHROPIC_API_KEY$|RUNNER_|NC_BASE$|ENGINE_PUSH_SECRET$|ENGINE_META_URL$|ENGINE_META_DSN$|TALK_AGENT_OPS_TOKEN$|EMBED_MODEL$|BOT_APP_PASSWORD_|BRAIN_KEY_|BRAIN_URL_)')
log "env snapshot written to ${ENVFILE} for cron jobs"

# --------------------------------------------------------------- cron -------
crontab -u runner /app/crontab
cron
log "cron armed — staggered slots :00 reb / :07 atlas / :15 ada / :22 marvin, every 30 min"

# ------------------------------------------------------- wake listener ------
log "starting wake-listener on :8791 as user runner"
export RUNNER_ARMED=1
exec setpriv --reuid runner --regid runner --init-groups \
  node /app/wake-listener.js
