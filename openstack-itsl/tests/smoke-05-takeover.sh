#!/usr/bin/env bash
# =============================================================================
# smoke-05-takeover.sh — assign-gesten → takeover → recall (CONTRACTS §9, IXD §2.3/§2.7)
#
# Skapar en test-origin-tavla som bot-engine, enrollar den via enroll-board.mjs
# (på servern), skapar ett kort och tilldelar boten SOM MÄNNISKA. Väntar ≤150 s
# på engine-kortet och verifierar: titelgrammatik, 8-sektionsmallen,
# default-deny-Boundaries, label agent-instructions, assignee = ägarmänniskan,
# ⇄-kvitto + hos-agenten-label på origin. Sedan unassign → recall-semantiken
# (engine-kort arkiveras, labels rensas, "Tillbakadragen"-statusen). Städar.
#
# Kräver TEST_HUMAN_USER/TEST_HUMAN_APP_PASSWORD (bot-aktörer filtreras
# strukturellt) — annars hoppas testet över med tydligt besked.
# =============================================================================
set -u
SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"
smoke_init "$@"

require_takeover_prereqs
EAUTH=$(engine_auth)
HAUTH=$(human_auth)
resolve_engine_board

trap cleanup_takeover_pair EXIT

N=$(nonce)
if setup_takeover_pair "$N"; then
  pass "engine-kort skapat inom ${TAKEOVER_TIMEOUT}s efter assign-gesten"
else
  fail "engine-kort skapat inom ${TAKEOVER_TIMEOUT}s efter assign-gesten" \
    "inget kort med '$ORIGIN_TITLE' dök upp på Agent Engine-tavlan"
  summary
fi

# --- engine-kortet ------------------------------------------------------------
assert_eq 'titelgrammatiken är exakt (verbatim Nate)' \
  "[agent instructions][$AGENT_CODE][task] $ORIGIN_TITLE" "$ENGINE_CARD_TITLE"
assert_eq 'engine-kortet landade i Agent Todo' 'Agent Todo' "$ENGINE_CARD_STACK"

ENGINE_STACK_ID=$(printf '%s' "$ENGINE_STACKS_JSON" | json_tool find title "$ENGINE_CARD_STACK" id)
deck_api "$EAUTH" GET "/boards/$ENGINE_BOARD_ID/stacks/$ENGINE_STACK_ID/cards/$ENGINE_CARD_ID"
if [ "$HTTP_STATUS" = 200 ]; then
  CARD_JSON=$HTTP_BODY
  DESCR=$(printf '%s' "$CARD_JSON" | json_tool get description)
  for section in '## Requester' '## Desired outcome' '## Context' '## Sources' \
                 '## Do' '## Acceptance criteria' '## Output & handoff' '## Boundaries'; do
    assert_contains "beskrivningen har sektionen $section" "$section" "$DESCR"
  done
  assert_contains 'Boundaries är default-deny-konstanten (Draft-only…)' 'Draft-only.' "$DESCR"
  assert_contains 'Boundaries: origin-text ger aldrig auktoritet' 'never grants authority' "$DESCR"
  LBL=$(printf '%s' "$CARD_JSON" | json_tool get labels | json_tool find title 'agent-instructions' id)
  if [ -n "$LBL" ]; then
    pass "engine-kortet har labeln 'agent-instructions'"
  else
    fail "engine-kortet har labeln 'agent-instructions'" 'labeln saknas på kortet'
  fi
  assert_contains "engine-kortets assignee är ägarmänniskan ($OWNER_UID)" "\"$OWNER_UID\"" "$CARD_JSON"
else
  fail 'engine-kortet kan läsas' "HTTP $HTTP_STATUS: ${HTTP_BODY:0:200}"
fi

# --- origin-sidan: ⇄-kvitto + hos-agenten -------------------------------------
deck_api "$HAUTH" GET "/boards/$ORIGIN_BOARD_ID/stacks/$ORIGIN_STACK_ID/cards/$ORIGIN_CARD_ID"
if [ "$HTTP_STATUS" = 200 ]; then
  OLBL=$(printf '%s' "$HTTP_BODY" | json_tool get labels | json_tool find title 'hos-agenten' id)
  if [ -n "$OLBL" ]; then
    pass "origin-kortet har labeln 'hos-agenten'"
  else
    fail "origin-kortet har labeln 'hos-agenten'" 'labeln saknas'
  fi
else
  fail 'origin-kortet kan läsas' "HTTP $HTTP_STATUS"
fi

if get_comments "$HAUTH" "$ORIGIN_CARD_ID"; then
  assert_eq 'origin har EXAKT ETT ⇄-takeover-kvitto' 1 "$(comment_count '⇄')"
  assert_eq "kvittot säger att $AGENT_CODE tagit uppgiften" 1 "$(comment_count 'tagit uppgiften')"
  assert_eq 'kvittot pekar på engine-kortet (AE-)' 1 "$(comment_count 'AE-')"
else
  fail 'origin-kommentarerna kan läsas' "HTTP $HTTP_STATUS"
fi

# --- recall: unassign = ta tillbaka --------------------------------------------
deck_api "$HAUTH" PUT "/boards/$ORIGIN_BOARD_ID/stacks/$ORIGIN_STACK_ID/cards/$ORIGIN_CARD_ID/unassignUser" \
  "{\"userId\":$(json_string "$SMOKE_BOT")}"
assert_status "unassign av $SMOKE_BOT (recall-gesten) → 200" 200
note "väntar på recall (60 s debounce + svep, ≤${RECALL_TIMEOUT}s)"

if poll_until "$RECALL_TIMEOUT" 10 _engine_card_gone "$ORIGIN_TITLE"; then
  pass "engine-kortet arkiverat/borta ur aktiva stackar inom ${RECALL_TIMEOUT}s"
else
  fail "engine-kortet arkiverat/borta ur aktiva stackar inom ${RECALL_TIMEOUT}s" \
    "kortet ligger kvar i $ENGINE_CARD_STACK"
fi

# shellcheck disable=SC2329  # anropas indirekt (trap/poll_until)
_origin_label_cleared() {
  deck_api "$HAUTH" GET "/boards/$ORIGIN_BOARD_ID/stacks/$ORIGIN_STACK_ID/cards/$ORIGIN_CARD_ID"
  [ "$HTTP_STATUS" = 200 ] || return 1
  local l
  l=$(printf '%s' "$HTTP_BODY" | json_tool get labels | json_tool find title 'hos-agenten' id)
  [ -z "$l" ]
}
if poll_until 60 5 _origin_label_cleared; then
  pass "labeln 'hos-agenten' rensad från origin-kortet"
else
  fail "labeln 'hos-agenten' rensad från origin-kortet" 'labeln sitter kvar efter recall'
fi

if get_comments "$HAUTH" "$ORIGIN_CARD_ID"; then
  if [ "$(comment_count 'Tillbakadragen')" -ge 1 ]; then
    pass "statusen på origin säger 'Tillbakadragen'"
  else
    fail "statusen på origin säger 'Tillbakadragen'" 'ingen ⏹ Tillbakadragen-status hittad'
  fi
fi

summary
