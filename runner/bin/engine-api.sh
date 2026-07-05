#!/bin/bash
# engine-api.sh — the ONLY Deck/engine tool surface the LLM gets (CONTRACTS §7)
#
# Thin curl wrapper around the agent_engine OCS API (CONTRACTS §3). Auth is the
# calling agent's bot app password; the LLM never sees credentials, only this
# script's name in its Bash allowlist.
#
# Usage:
#   engine-api.sh queue
#   engine-api.sh claim <engineCardId>
#   engine-api.sh ledger '<json status fields>'          # KARTLAGGNING §4.8 keys
#   engine-api.sh receipt <engineCardId> '<TOKEN>' [--move needs_input|review|done|working] [--message '<text>']
#   engine-api.sh origin-note <engineCardId> '<text>'    # relay to the origin card (≤900 chars)
#
# Env (exported by run-agent.sh):
#   NC_BASE            e.g. https://dev15.hubs.se
#   AGENT_CODE         e.g. reb-claude
#   BOT_USER           e.g. bot-reb   (derived from AGENT_CODE when unset)
#   BOT_APP_PASSWORD   app password for BOT_USER
#
# Output: one JSON object {"http_status": <int>, "data": <ocs data or raw body>}
# Exit:   0 on 2xx, 1 otherwise (body is still printed so the model can react,
#         e.g. a 409 {claimedBy} on claim).

set -euo pipefail

die() { echo "engine-api.sh: $*" >&2; exit 2; }

: "${NC_BASE:?engine-api.sh: NC_BASE is not set}"
: "${AGENT_CODE:?engine-api.sh: AGENT_CODE is not set}"
BOT_USER="${BOT_USER:-bot-${AGENT_CODE%-claude}}"
: "${BOT_APP_PASSWORD:?engine-api.sh: BOT_APP_PASSWORD is not set}"

API="${NC_BASE%/}/ocs/v2.php/apps/agent_engine/api/v1"

# CONTRACTS §2 receipt vocabulary — validated here as defense in depth
# (the server validates too).
RECEIPT_TOKENS=(
  "AGENT CLAIMED" "AGENT DONE" "AGENT BLOCKED" "AGENT UNBLOCKED"
  "AGENT HUMAN HOLD" "AGENT HUMAN ANSWERED" "AGENT RESUMED" "AGENT FAILED"
  "AGENT APPLIED" "AGENT SKILL SUBSCRIBED" "AGENT SKILL INSTALLED"
  "AGENT SKILL UPDATED" "AGENT SKILL DECLINED" "AGENT FOLLOW-UP" "AGENT STATUS"
)

# Deck comments cap at 1000 chars; all receipts/notes are written ≤900
# (CONTRACTS §2).
truncate900() {
  local s="$1"
  if [[ ${#s} -gt 900 ]]; then
    printf '%s…' "${s:0:899}"
  else
    printf '%s' "$s"
  fi
}

request() {
  local method="$1" path="$2" body="${3:-}"
  local curl_args=(
    -sS -X "$method"
    -u "${BOT_USER}:${BOT_APP_PASSWORD}"
    -H "OCS-APIRequest: true"
    -H "Accept: application/json"
    -w $'\n%{http_code}'
  )
  if [[ -n "$body" ]]; then
    curl_args+=(-H "Content-Type: application/json" --data "$body")
  fi

  local resp code out
  resp=$(curl "${curl_args[@]}" "${API}${path}") || die "curl failed against ${API}${path}"
  code="${resp##*$'\n'}"
  out="${resp%$'\n'*}"

  # Unwrap the OCS envelope when present; pass raw body through otherwise.
  if jq -e . >/dev/null 2>&1 <<<"$out"; then
    jq -c --argjson s "$code" '{http_status: $s, data: (if type == "object" and has("ocs") then .ocs.data else . end)}' <<<"$out"
  else
    jq -nc --argjson s "$code" --arg raw "$out" '{http_status: $s, data: {raw: $raw}}'
  fi

  [[ "$code" =~ ^2[0-9][0-9]$ ]]
}

cmd="${1:-}"
shift || true

case "$cmd" in
  queue)
    request GET "/queue/${AGENT_CODE}"
    ;;

  claim)
    card_id="${1:?engine-api.sh claim: missing <engineCardId>}"
    [[ "$card_id" =~ ^[0-9]+$ ]] || die "claim: engineCardId must be numeric, got '$card_id'"
    request POST "/claim/${card_id}"
    ;;

  ledger)
    fields_json="${1:?engine-api.sh ledger: missing JSON body of status fields}"
    jq -e 'type == "object"' >/dev/null 2>&1 <<<"$fields_json" \
      || die "ledger: argument must be a JSON object"
    # The prompt/skill use snake_case keys (last_queue_result, …) but the OCS
    # LedgerController binds camelCase — translate here so fields actually land
    # (otherwise every ledger field silently defaults). Inject agent + heartbeat
    # (camelCase); caller-provided values win.
    body=$(jq -c \
      --arg agent "$AGENT_CODE" \
      --arg hb "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
      '({agent: $agent, lastHeartbeat: $hb} + .)
       | with_entries(.key |= gsub("_(?<c>[a-z])"; (.c | ascii_upcase)))' <<<"$fields_json")
    request PUT "/ledger/${AGENT_CODE}" "$body"
    ;;

  receipt)
    card_id="${1:?engine-api.sh receipt: missing <engineCardId>}"
    token="${2:?engine-api.sh receipt: missing '<TOKEN>'}"
    shift 2
    [[ "$card_id" =~ ^[0-9]+$ ]] || die "receipt: engineCardId must be numeric, got '$card_id'"

    valid=0
    for t in "${RECEIPT_TOKENS[@]}"; do
      [[ "$token" == "$t" ]] && valid=1 && break
    done
    [[ $valid -eq 1 ]] || die "receipt: '$token' is not in the receipt vocabulary (CONTRACTS §2)"

    move=""
    message=""
    while [[ $# -gt 0 ]]; do
      case "$1" in
        --move)
          move="${2:?receipt: --move needs a value}"
          case "$move" in needs_input|review|done|working) ;; *) die "receipt: --move must be needs_input|review|done|working" ;; esac
          shift 2 ;;
        --message)
          message="${2:?receipt: --message needs a value}"
          shift 2 ;;
        *) die "receipt: unknown option '$1'" ;;
      esac
    done

    # Controller binds `text` (not `message`); wrong key silently drops the note.
    body=$(jq -nc \
      --arg token "$token" \
      --arg move "$move" \
      --arg text "$(truncate900 "$message")" \
      '{token: $token}
       + (if $move != "" then {move: $move} else {} end)
       + (if $text != "" then {text: $text} else {} end)')
    request POST "/receipt/${card_id}" "$body"
    ;;

  origin-note)
    card_id="${1:?engine-api.sh origin-note: missing <engineCardId>}"
    note="${2:?engine-api.sh origin-note: missing '<text>'}"
    [[ "$card_id" =~ ^[0-9]+$ ]] || die "origin-note: engineCardId must be numeric, got '$card_id'"
    [[ -n "${note// /}" ]] || die "origin-note: text must not be empty"
    # Controller binds `text` (not `note`) — wrong key relays an EMPTY note.
    body=$(jq -nc --arg text "$(truncate900 "$note")" '{text: $text}')
    request POST "/origin-note/${card_id}" "$body"
    ;;

  *)
    die "unknown subcommand '${cmd}' — use: queue | claim | ledger | receipt | origin-note"
    ;;
esac
