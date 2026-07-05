#!/usr/bin/env bash
# =============================================================================
# smoke-03-claim-race.sh — atomiska claimen bevisad (CONTRACTS §3, §9)
#
# Skapar ett slängkort på Agent Engine-tavlan som bot-engine och skickar TVÅ
# parallella POST /claim/{cardId} som samma agent (bot-atlas). Exakt EN ska få
# 200 och EN 409. Kortet ska stå i Agent Working med exakt EN AGENT CLAIMED-
# kommentar. Städar efter sig.
# =============================================================================
set -u
SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"
smoke_init "$@"

require_vars BOT_APP_PASSWORD_ENGINE BOT_APP_PASSWORD_ATLAS

EAUTH=$(engine_auth)
AAUTH="bot-atlas:$BOT_APP_PASSWORD_ATLAS"

resolve_engine_board
LABEL_ID=$(engine_label_id 'agent-instructions')
[ -n "$LABEL_ID" ] || die "labeln 'agent-instructions' saknas på Agent Engine-tavlan"

N=$(nonce)
TITLE="[agent instructions][atlas-claude][task] Smoke claim race $N"
DESC=$(card_description_8s \
  'smoke-03 (automatiserat test)' \
  'Throwaway card for the claim-race smoke test. No work expected.' \
  'This card exists only to prove the atomic claim (exactly one 200 + one 409).' \
  'Nothing. The test deletes this card.' \
  'The card is deleted by the test.')

TODO_ID=${STACK_ID['Agent Todo']}
WORK_ID=${STACK_ID['Agent Working']}
TEST_CARD=''

# shellcheck disable=SC2329  # anropas indirekt (trap/poll_until)
cleanup() {
  if [ -n "$TEST_CARD" ]; then
    deck_api "$EAUTH" DELETE "/boards/$ENGINE_BOARD_ID/stacks/$WORK_ID/cards/$TEST_CARD" || true
    deck_api "$EAUTH" DELETE "/boards/$ENGINE_BOARD_ID/stacks/$TODO_ID/cards/$TEST_CARD" || true
  fi
  return 0
}
trap cleanup EXIT

deck_create_card "$EAUTH" "$ENGINE_BOARD_ID" "$TODO_ID" "$TITLE" "$DESC" \
  || die "kunde inte skapa slängkortet (HTTP $HTTP_STATUS): ${HTTP_BODY:0:200}"
TEST_CARD=$CARD_ID
note "slängkort $TEST_CARD skapat i Agent Todo"

deck_api "$EAUTH" PUT "/boards/$ENGINE_BOARD_ID/stacks/$TODO_ID/cards/$TEST_CARD/assignLabel" \
  "{\"labelId\":$LABEL_ID}"
assert_status "labeln 'agent-instructions' satt på slängkortet" 200

# --- två parallella claims som SAMMA agent -----------------------------------
TMP1=$(mktemp) TMP2=$(mktemp)

claim_once() { # <outprefix>
  curl -sS ${CURL_TLS[@]+"${CURL_TLS[@]}"} --max-time 60 -u "$AAUTH" \
    -H 'OCS-APIRequest: true' -H 'Accept: application/json' \
    -X POST -o "$1.body" -w '%{http_code}' \
    "$NC_BASE/ocs/v2.php/apps/agent_engine/api/v1/claim/$TEST_CARD?format=json" \
    >"$1.code" 2>/dev/null
}

claim_once "$TMP1" & P1=$!
claim_once "$TMP2" & P2=$!
wait "$P1" 2>/dev/null || true
wait "$P2" 2>/dev/null || true

CODE1=$(cat "$TMP1.code" 2>/dev/null || printf '000')
CODE2=$(cat "$TMP2.code" 2>/dev/null || printf '000')
BODY_200=''
if [ "$CODE1" = 200 ]; then BODY_200=$(cat "$TMP1.body" 2>/dev/null || true); fi
if [ "$CODE2" = 200 ]; then BODY_200=$(cat "$TMP2.body" 2>/dev/null || true); fi
rm -f "$TMP1" "$TMP1.code" "$TMP1.body" "$TMP2" "$TMP2.code" "$TMP2.body"

note "parallella claims gav: $CODE1 och $CODE2"
if { [ "$CODE1" = 200 ] && [ "$CODE2" = 409 ]; } || { [ "$CODE1" = 409 ] && [ "$CODE2" = 200 ]; }; then
  pass 'två parallella claims → exakt en 200 + en 409'
else
  fail 'två parallella claims → exakt en 200 + en 409' "fick $CODE1 + $CODE2"
fi
if [ -n "$BODY_200" ]; then
  assert_contains 'vinnarens 200-svar innehåller cardId' "$TEST_CARD" "$BODY_200"
fi

# --- efterläge: Agent Working + exakt EN AGENT CLAIMED -----------------------
refresh_engine_stacks
LINE=$(printf '%s' "$ENGINE_STACKS_JSON" | json_tool cards_find "Smoke claim race $N" | head -n 1)
assert_eq 'kortet står i Agent Working efter claim' 'Agent Working' "$(printf '%s' "$LINE" | cut -f1)"

if get_comments "$EAUTH" "$TEST_CARD"; then
  assert_eq 'exakt EN AGENT CLAIMED-kommentar' 1 "$(comment_count 'AGENT CLAIMED')"
else
  fail 'exakt EN AGENT CLAIMED-kommentar' "kunde inte läsa kommentarer (HTTP $HTTP_STATUS)"
fi

summary
