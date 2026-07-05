#!/usr/bin/env bash
# =============================================================================
# smoke-04-ledger-upsert.sh — liggar-upsert på plats (CONTRACTS §3, §9)
#
# PUT /ledger/{agentCode} två gånger → fortfarande exakt EN AGENT STATUS-
# kommentar för agenten på liggarkortet, uppdaterad PÅ PLATS (andra PUT:ens
# innehåll syns, första PUT:ens markör är borta).
# =============================================================================
set -u
SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"
smoke_init "$@"

require_vars BOT_APP_PASSWORD_ENGINE BOT_APP_PASSWORD_ATLAS

AAUTH="bot-atlas:$BOT_APP_PASSWORD_ATLAS"
AGENT='atlas-claude'

resolve_engine_board
LEDGER_CARD=$(standing_card_id "$LEDGER_CARD_TITLE")
[ -n "$LEDGER_CARD" ] || die "liggarkortet saknas i Standing-stacken: $LEDGER_CARD_TITLE"
note "liggarkort: $LEDGER_CARD"

N=$(nonce)
TS1=$(iso_now)

ledger_body() { # <heartbeat> <notes>
  printf '%s' "{\
\"runtime\":\"Claude Code (headless runner + interactive)\",\
\"automation\":\"deck-queue-runner v1\",\
\"automationState\":\"installed\",\
\"lastHeartbeat\":$(json_string "$1"),\
\"lastQueueResult\":\"checking\",\
\"lastSuccessfulRun\":$(json_string "$1"),\
\"localContext\":\"engine v1; routing map v1\",\
\"optionalSkills\":\"none\",\
\"notes\":$(json_string "$2")}"
}

# --- PUT nr 1 -----------------------------------------------------------------
engine_api "$AAUTH" PUT "/ledger/$AGENT" "$(ledger_body "$TS1" "smoke-04 first $N")"
assert_status "PUT /ledger/$AGENT (första) → 200" 200

sleep 2
TS2=$(iso_now)

# --- PUT nr 2 -----------------------------------------------------------------
engine_api "$AAUTH" PUT "/ledger/$AGENT" "$(ledger_body "$TS2" "smoke-04 second $N")"
assert_status "PUT /ledger/$AGENT (andra) → 200" 200

sleep 3

# --- exakt EN AGENT STATUS-kommentar för agenten, uppdaterad på plats ---------
if get_comments "$AAUTH" "$LEDGER_CARD"; then
  assert_eq "exakt EN 'Agent: $AGENT'-kommentar på liggarkortet" 1 "$(comment_count "Agent: $AGENT")"
  assert_eq "exakt EN kommentar med testets markör ($N)" 1 "$(comment_count "$N")"
  assert_eq 'andra PUT:ens innehåll syns (uppdaterad på plats)' 1 "$(comment_count "smoke-04 second $N")"
  assert_eq 'första PUT:ens innehåll är ersatt (ingen pile-up)' 0 "$(comment_count "smoke-04 first $N")"
  LEDGER_COMMENT=$(printf '%s' "$COMMENTS_JSON" | json_tool find_obj actorId bot-atlas | json_tool get message)
  if [ -n "$LEDGER_COMMENT" ]; then
    assert_contains 'kommentaren börjar med AGENT STATUS-formatet' 'AGENT STATUS' "$LEDGER_COMMENT"
  fi
else
  fail 'kommentarer på liggarkortet kan läsas' "HTTP $HTTP_STATUS: ${HTTP_BODY:0:200}"
fi

summary
