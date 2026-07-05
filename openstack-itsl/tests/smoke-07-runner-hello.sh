#!/usr/bin/env bash
# =============================================================================
# smoke-07-runner-hello.sh — hello-world genom hela kön (CONTRACTS §9, Nates smoke-spec)
#
# GUARD: hoppar över med tydligt besked om ANTHROPIC_API_KEY inte är satt på
# servern (eller RUNNER_ENABLED != 1). Annars: skapar hello-world-kortet,
# triggar wake och pollar CLAIMED → DONE → Agent Done + liggaren
# `completed AE-<id>`.
# =============================================================================
set -u
SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"
smoke_init "$@"

require_vars DEV15_SSH BOT_APP_PASSWORD_ENGINE

# --- guard: nyckel + runner aktiverad på servern (värdet hämtas ALDRIG hit) ---
if ! remote "grep -Eq '^ANTHROPIC_API_KEY=..' '$SERVER_ENV_PATH'"; then
  skip_all "ANTHROPIC_API_KEY är inte satt i $SERVER_ENV_PATH på servern — runnertesterna kan inte köras. Sätt nyckeln (Fredrik) och RUNNER_ENABLED=1, kör sedan om med --with-runner."
fi
if ! remote "grep -Eq '^RUNNER_ENABLED=1' '$SERVER_ENV_PATH'"; then
  skip_all "RUNNER_ENABLED är inte 1 i $SERVER_ENV_PATH på servern — runnern är avstängd (CONTRACTS §8). Aktivera och kör om."
fi
require_vars ENGINE_PUSH_SECRET

EAUTH=$(engine_auth)
AGENT='atlas-claude'
resolve_engine_board
LABEL_ID=$(engine_label_id 'agent-instructions')
[ -n "$LABEL_ID" ] || die "labeln 'agent-instructions' saknas på Agent Engine-tavlan"
LEDGER_CARD=$(standing_card_id "$LEDGER_CARD_TITLE")
[ -n "$LEDGER_CARD" ] || die 'liggarkortet saknas i Standing-stacken'

N=$(nonce)
TITLE="[agent instructions][$AGENT][task] Say hello from the queue ($N)"
DESC=$(card_description_8s \
  'smoke-07 (automated smoke test, operator fredrik)' \
  'A hello receipt from the queue runner.' \
  "Canonical hello-world smoke card (Nate's smoke spec). No real work." \
  'Say hello from the queue: post one short greeting comment on this card, then finish with AGENT DONE.' \
  'AGENT CLAIMED and AGENT DONE receipts on this card; the card ends in Agent Done; the ledger shows completed for this card.')

TODO_ID=${STACK_ID['Agent Todo']}
TEST_CARD=''

# shellcheck disable=SC2329  # anropas indirekt (trap/poll_until)
cleanup() {
  # spara kortet för triage vid rött; radera vid grönt
  if [ -n "$TEST_CARD" ] && [ "$SMOKE_FAILED" = 0 ]; then
    refresh_engine_stacks 2>/dev/null || return 0
    local line sid
    line=$(printf '%s' "$ENGINE_STACKS_JSON" | json_tool cards_find "($N)" | head -n 1)
    if [ -n "$line" ]; then
      sid=$(printf '%s' "$ENGINE_STACKS_JSON" | json_tool find title "$(printf '%s' "$line" | cut -f1)" id)
      deck_api "$EAUTH" DELETE "/boards/$ENGINE_BOARD_ID/stacks/$sid/cards/$TEST_CARD" || true
    fi
  elif [ -n "$TEST_CARD" ]; then
    note "hello-kortet $TEST_CARD lämnas kvar för triage (testet blev rött)"
  fi
  return 0
}
trap cleanup EXIT

deck_create_card "$EAUTH" "$ENGINE_BOARD_ID" "$TODO_ID" "$TITLE" "$DESC" \
  || die "kunde inte skapa hello-kortet (HTTP $HTTP_STATUS)"
TEST_CARD=$CARD_ID
deck_api "$EAUTH" PUT "/boards/$ENGINE_BOARD_ID/stacks/$TODO_ID/cards/$TEST_CARD/assignLabel" \
  "{\"labelId\":$LABEL_ID}"
assert_status 'agent-instructions-labeln satt' 200
# körbarhetsregeln: assignee = människan som äger målagenten (fredrik)
deck_api "$EAUTH" PUT "/boards/$ENGINE_BOARD_ID/stacks/$TODO_ID/cards/$TEST_CARD/assignUser" \
  '{"userId":"fredrik"}'
assert_status 'assignee fredrik satt (eligibility)' 200
note "hello-kort AE-$TEST_CARD skapat"

# --- wake ----------------------------------------------------------------------
wake_agent "$AGENT"
assert_status_in "HMAC-wake till runnern (/wake/$AGENT) → 2xx" '200,202,204'

# --- CLAIMED → DONE → Agent Done → liggare --------------------------------------
# shellcheck disable=SC2329  # anropas indirekt (trap/poll_until)
_has_receipt() { # <token>
  get_comments "$EAUTH" "$TEST_CARD" || return 1
  [ "$(comment_count "$1")" -ge 1 ]
}

if poll_until "$RUNNER_TIMEOUT" 10 _has_receipt 'AGENT CLAIMED'; then
  pass "AGENT CLAIMED inom ${RUNNER_TIMEOUT}s"
else
  fail "AGENT CLAIMED inom ${RUNNER_TIMEOUT}s" 'inget claim-kvitto på kortet'
fi

if poll_until "$RUNNER_TIMEOUT" 10 _has_receipt 'AGENT DONE'; then
  pass "AGENT DONE inom ${RUNNER_TIMEOUT}s"
else
  fail "AGENT DONE inom ${RUNNER_TIMEOUT}s" 'inget done-kvitto på kortet'
fi

# shellcheck disable=SC2329  # anropas indirekt (trap/poll_until)
_in_done_stack() {
  refresh_engine_stacks
  local line
  line=$(printf '%s' "$ENGINE_STACKS_JSON" | json_tool cards_find "($N)" | head -n 1)
  [ "$(printf '%s' "$line" | cut -f1)" = 'Agent Done' ]
}
if poll_until 90 10 _in_done_stack; then
  pass 'kortet står i Agent Done'
else
  fail 'kortet står i Agent Done' 'kortet nådde aldrig Agent Done-stacken'
fi

# shellcheck disable=SC2329  # anropas indirekt (trap/poll_until)
_ledger_completed() {
  get_comments "$EAUTH" "$LEDGER_CARD" || return 1
  local msg
  msg=$(printf '%s' "$COMMENTS_JSON" | json_tool count_where message "completed AE-$TEST_CARD")
  [ "$msg" -ge 1 ]
}
if poll_until 90 10 _ledger_completed; then
  pass "liggaren visar completed AE-$TEST_CARD"
else
  fail "liggaren visar completed AE-$TEST_CARD" 'Last queue result uppdaterades inte'
fi

summary
