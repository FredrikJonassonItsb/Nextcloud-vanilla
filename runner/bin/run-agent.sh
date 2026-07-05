#!/bin/bash
# run-agent.sh — one headless queue run for one agent slot (CONTRACTS §7)
#
#   run-agent.sh <agentCode>          agentCode ∈ reb-claude|atlas-claude|ada-claude|marvin-claude
#
# Flow:
#   flock /locks/<agentCode>.lock  (cron tick and wake push never overlap)
#   → pause-marker + daily USD cap check BEFORE the run (RUNNER_DAILY_USD_CAP,
#     default 10; exceeded ⇒ pause the slot until tomorrow + Talk alert to
#     "Agent Ops" as bot-engine)
#   → claude -p prompts/queue-run.md with a Bash allowlist limited to
#     engine-api.sh / brain-api.sh (plus read-only Read/Grep and read-only web
#     research WebSearch/WebFetch — the Bash sandbox stays tight, so a hostile
#     card still cannot shell out or read secrets), --max-turns 40
#   → parse the CLI's JSON result, INSERT engine_meta.run_log via psql
#
# Env (from compose / /run/runner-env):
#   ANTHROPIC_API_KEY        required — the run is skipped without it
#   RUNNER_ENABLED           must be "1"
#   NC_BASE                  e.g. https://dev15.hubs.se
#   BOT_APP_PASSWORD_<NAME>  bot app password per agent (REB|ATLAS|ADA|MARVIN)
#   BRAIN_KEY_<NAME>         bearer key per brain
#   BRAIN_URL_<NAME>         optional override; default http://brain-<name>:8000
#   ENGINE_META_DSN          postgres://svc_engine:…@brain-db:5432/engine_meta
#                            (cost log + run journal; runs proceed with a loud
#                            warning if unset, but the daily cap needs it)
#   RUNNER_DAILY_USD_CAP     default 10 (per agent per day)
#   RUNNER_MODEL             default claude-sonnet-4-5
#   TALK_AGENT_OPS_TOKEN     Talk room token for "Agent Ops" (alerts)
#   BOT_APP_PASSWORD_ENGINE  bot-engine app password (sender of Talk alerts)

set -uo pipefail

log() {
  printf '%s runner[%s]: %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "${AGENT_CODE:-?}" "$*"
}

# ------------------------------------------------------------ arguments -----
AGENT_CODE="${1:-}"
case "$AGENT_CODE" in
  reb-claude|atlas-claude|ada-claude|marvin-claude) ;;
  *) echo "run-agent.sh: unknown agent code '${AGENT_CODE}'" >&2; exit 2 ;;
esac
export AGENT_CODE

# Cron strips the environment — restore the snapshot written by entrypoint.sh.
if [[ -z "${ANTHROPIC_API_KEY:-}" && -r /run/runner-env ]]; then
  # shellcheck disable=SC1091
  source /run/runner-env
fi

if [[ "${RUNNER_ENABLED:-0}" != "1" || -z "${ANTHROPIC_API_KEY:-}" ]]; then
  log "skipping run — runner disabled or ANTHROPIC_API_KEY missing"
  exit 0
fi
: "${NC_BASE:?run-agent.sh: NC_BASE is not set}"

NAME="${AGENT_CODE%-claude}"                       # reb
UPPER=$(printf '%s' "$NAME" | tr '[:lower:]' '[:upper:]')  # REB

LOG_FILE="/var/log/runner/${AGENT_CODE}.log"
CAP="${RUNNER_DAILY_USD_CAP:-10}"

# ------------------------------------------------------------ run lock ------
LOCK_FILE="/locks/${AGENT_CODE}.lock"
exec 9>"$LOCK_FILE"
if ! flock -n 9; then
  log "another run holds ${LOCK_FILE} — skipping (this is normal for wake-during-run)"
  exit 0
fi

# --------------------------------------------------------- talk alert -------
talk_alert() {
  local msg="$1"
  log "ALERT: ${msg}"
  if [[ -n "${TALK_AGENT_OPS_TOKEN:-}" && -n "${BOT_APP_PASSWORD_ENGINE:-}" ]]; then
    curl -sS -o /dev/null -X POST \
      "${NC_BASE%/}/ocs/v2.php/apps/spreed/api/v1/chat/${TALK_AGENT_OPS_TOKEN}" \
      -u "bot-engine:${BOT_APP_PASSWORD_ENGINE}" \
      -H "OCS-APIRequest: true" \
      -H "Accept: application/json" \
      --data-urlencode "message=${msg}" \
      || log "WARNING: failed to deliver Talk alert to Agent Ops"
  else
    log "WARNING: Talk alert not delivered — TALK_AGENT_OPS_TOKEN/BOT_APP_PASSWORD_ENGINE unset"
  fi
}

# ----------------------------------------------------- pause-slot check -----
# A paused slot carries today's date in the marker; it expires by itself
# at midnight UTC (next run removes a stale marker).
PAUSE_FILE="/locks/${AGENT_CODE}.paused"
TODAY=$(date -u +%Y-%m-%d)
if [[ -f "$PAUSE_FILE" ]]; then
  if [[ "$(cat "$PAUSE_FILE" 2>/dev/null)" == "$TODAY" ]]; then
    log "slot is paused for today (daily USD cap) — skipping"
    exit 0
  fi
  rm -f "$PAUSE_FILE"
  log "pause marker expired — slot resumed"
fi

# ------------------------------------------------------ engine_meta SQL -----
DSN="${ENGINE_META_DSN:-}"

ensure_run_log() {
  [[ -n "$DSN" ]] || return 0
  PGCONNECT_TIMEOUT=10 psql "$DSN" -q -v ON_ERROR_STOP=1 <<'SQL' || log "WARNING: could not ensure engine_meta.run_log exists"
CREATE TABLE IF NOT EXISTS run_log (
  id          bigserial PRIMARY KEY,
  agent_code  text        NOT NULL,
  started_at  timestamptz NOT NULL,
  finished_at timestamptz NOT NULL DEFAULT now(),
  result      text,
  card_id     text,
  num_turns   integer,
  cost_usd    numeric(10,4) NOT NULL DEFAULT 0,
  session_id  text,
  is_error    boolean     NOT NULL DEFAULT false
);
CREATE INDEX IF NOT EXISTS run_log_agent_day_idx ON run_log (agent_code, started_at);
SQL
}

spent_today() {
  [[ -n "$DSN" ]] || { echo 0; return; }
  local v
  v=$(PGCONNECT_TIMEOUT=10 psql "$DSN" -tA -v ON_ERROR_STOP=1 \
    -v ac="$AGENT_CODE" <<'SQL' 2>/dev/null
SELECT COALESCE(SUM(cost_usd), 0)
  FROM run_log
 WHERE agent_code = :'ac'
   AND started_at >= date_trunc('day', now() AT TIME ZONE 'utc');
SQL
  ) || v=""
  [[ -n "$v" ]] && echo "$v" || echo 0
}

pause_slot() {
  local spent="$1"
  echo "$TODAY" > "$PAUSE_FILE"
  talk_alert "⚠️ Dagstak nått: ${AGENT_CODE} har använt \$${spent} av \$${CAP} USD idag. Sloten pausas till imorgon (UTC). Höj RUNNER_DAILY_USD_CAP i /opt/openstack/.env om taket är fel."
}

ensure_run_log

# ------------------------------------- daily USD cap check BEFORE run -------
if [[ -n "$DSN" ]]; then
  SPENT=$(spent_today)
  if awk -v s="$SPENT" -v c="$CAP" 'BEGIN { exit !(s+0 >= c+0) }'; then
    log "daily cap reached BEFORE run (spent=\$${SPENT}, cap=\$${CAP}) — pausing slot"
    pause_slot "$SPENT"
    exit 0
  fi
  log "daily spend so far: \$${SPENT} (cap \$${CAP})"
else
  log "WARNING: ENGINE_META_DSN unset — no cost log, daily cap NOT enforced"
fi

# ----------------------------------------------------- per-agent env --------
pw_var="BOT_APP_PASSWORD_${UPPER}"
key_var="BRAIN_KEY_${UPPER}"
url_var="BRAIN_URL_${UPPER}"

export BOT_USER="bot-${NAME}"
export BOT_APP_PASSWORD="${!pw_var:-}"
export BRAIN_KEY="${!key_var:-}"
export BRAIN_URL="${!url_var:-http://brain-${NAME}:8000}"

if [[ -z "$BOT_APP_PASSWORD" ]]; then
  log "ERROR: ${pw_var} is not set — cannot talk to agent_engine, aborting run"
  exit 1
fi
[[ -n "$BRAIN_KEY" ]] || log "WARNING: ${key_var} unset — brain recall/writeback will fail"

# ------------------------------------------------------------ the run -------
PROMPT_FILE=$(mktemp /tmp/queue-run.XXXXXX.md)
trap 'rm -f "$PROMPT_FILE"' EXIT
sed "s/{{AGENT_CODE}}/${AGENT_CODE}/g" /app/prompts/queue-run.md > "$PROMPT_FILE"

STARTED_AT=$(date -u +%Y-%m-%dT%H:%M:%SZ)
log "starting queue run (model ${RUNNER_MODEL:-claude-sonnet-4-5}, max 40 turns)"

cd /app
# claude writes ~/.claude.json — force a writable HOME (cron/wake-listener may
# inherit HOME=/root → EACCES crash). And put the tool wrappers on PATH so the
# prompt's `engine-api.sh …` / `brain-api.sh …` calls resolve (the allowedTools
# whitelist names them bare).
export HOME=/home/runner
export PATH="/app/bin:$PATH"
[[ -w "$HOME" ]] || { mkdir -p "$HOME" 2>/dev/null || true; }

set +e
OUT_JSON=$(claude -p "$(cat "$PROMPT_FILE")" \
  --model "${RUNNER_MODEL:-claude-sonnet-4-5}" \
  --max-turns 40 \
  --output-format json \
  --allowedTools "Bash(engine-api.sh:*),Bash(brain-api.sh:*),Bash(/app/bin/engine-api.sh:*),Bash(/app/bin/brain-api.sh:*),Read,Grep,WebSearch,WebFetch" \
  2>>"$LOG_FILE")
CLAUDE_EXIT=$?
set -e 2>/dev/null || true

FINISHED_AT=$(date -u +%Y-%m-%dT%H:%M:%SZ)

# ------------------------------------------------------ parse the result ----
COST="0"
NUM_TURNS="0"
SESSION_ID=""
IS_ERROR="true"
RESULT_TEXT=""

if jq -e . >/dev/null 2>&1 <<<"$OUT_JSON"; then
  COST=$(jq -r '.total_cost_usd // .cost_usd // 0' <<<"$OUT_JSON")
  NUM_TURNS=$(jq -r '.num_turns // 0' <<<"$OUT_JSON")
  SESSION_ID=$(jq -r '.session_id // ""' <<<"$OUT_JSON")
  IS_ERROR=$(jq -r 'if (.is_error // false) then "true" else "false" end' <<<"$OUT_JSON")
  RESULT_TEXT=$(jq -r '.result // ""' <<<"$OUT_JSON")
else
  log "ERROR: claude CLI produced no parseable JSON (exit ${CLAUDE_EXIT}) — see ${LOG_FILE}"
fi
[[ "$CLAUDE_EXIT" -ne 0 ]] && IS_ERROR="true"

# The prompt ends every run with a machine-readable line:
#   RUN_RESULT: <none|completed|blocked|holding|observed|resumed|recalled|failed> [AE-<id>]
RUN_RESULT="unknown"
CARD_ID=""
SUMMARY_LINE=$(grep -oE 'RUN_RESULT: [a-z]+( AE-[0-9]+)?' <<<"$RESULT_TEXT" | tail -n 1)
if [[ -n "$SUMMARY_LINE" ]]; then
  RUN_RESULT=$(awk '{print $2}' <<<"$SUMMARY_LINE")
  CARD_ID=$(awk '{print $3}' <<<"$SUMMARY_LINE")
fi

log "run finished: result=${RUN_RESULT} card=${CARD_ID:-none} cost=\$${COST} turns=${NUM_TURNS} exit=${CLAUDE_EXIT}"

# Quota/billing-type API failures must never be silent (BYGGPLAN §5.2).
if [[ "$IS_ERROR" == "true" ]] && grep -qiE 'credit|billing|quota|rate.?limit|insufficient' <<<"$RESULT_TEXT"; then
  talk_alert "🔴 Runner-fel för ${AGENT_CODE}: API-svaret ser ut som ett kvot-/faktureringsfel. Kontrollera Anthropic-workspacen. Detalj: $(printf '%s' "$RESULT_TEXT" | head -c 300)"
fi

# ------------------------------------------------- run_log INSERT -----------
if [[ -n "$DSN" ]]; then
  PGCONNECT_TIMEOUT=10 psql "$DSN" -q -v ON_ERROR_STOP=1 \
    -v ac="$AGENT_CODE" \
    -v st="$STARTED_AT" \
    -v fin="$FINISHED_AT" \
    -v res="$RUN_RESULT" \
    -v card="$CARD_ID" \
    -v turns="$NUM_TURNS" \
    -v cost="$COST" \
    -v sid="$SESSION_ID" \
    -v err="$IS_ERROR" <<'SQL' || log "WARNING: run_log INSERT failed — cost NOT recorded"
INSERT INTO run_log (agent_code, started_at, finished_at, result, card_id,
                     num_turns, cost_usd, session_id, is_error)
VALUES (:'ac', :'st'::timestamptz, :'fin'::timestamptz, :'res',
        NULLIF(:'card', ''), :'turns'::integer, :'cost'::numeric,
        NULLIF(:'sid', ''), :'err'::boolean);
SQL

  # Post-run cap check: pause NOW so the next tick does not start at all.
  SPENT=$(spent_today)
  if awk -v s="$SPENT" -v c="$CAP" 'BEGIN { exit !(s+0 >= c+0) }'; then
    log "daily cap reached AFTER run (spent=\$${SPENT}, cap=\$${CAP}) — pausing slot"
    pause_slot "$SPENT"
  fi
fi

exit 0
