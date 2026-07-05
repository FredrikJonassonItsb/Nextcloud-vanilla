#!/usr/bin/env bash
# =============================================================================
# smoke-06-sync-loop.sh — tvåvägssynk utan studs (CONTRACTS §9, IXD §2.4)
#
# På ett färskt takeover-par:
#   1. 3 mänskliga kommentarer på origin → EXAKT 3 speglade (⇄-märkta) på
#      engine-kortet, 0 ekon tillbaka till origin
#   2. agent-kvitto (AGENT BLOCKED) via receipt-endpointen → EXAKT 1 ⇄-status-
#      uppdatering på origin
#   3. ingen ping-pong-tillväxt efter 2 svepcykler (kommentarantalen stabila)
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

BOT_PW=$(bot_password_of "$SMOKE_BOT")
[ -n "$BOT_PW" ] || die "app-lösenord saknas för $SMOKE_BOT (BOT_APP_PASSWORD_…)"
BAUTH="$SMOKE_BOT:$BOT_PW"

trap cleanup_takeover_pair EXIT

N=$(nonce)
if setup_takeover_pair "$N"; then
  pass "takeover-paret uppe (engine-kort $ENGINE_CARD_ID)"
else
  fail 'takeover-paret uppe' "inget engine-kort inom ${TAKEOVER_TIMEOUT}s"
  summary
fi

# --- 1. tre mänskliga kommentarer origin → engine ------------------------------
M="komm$N"
for i in 1 2 3; do
  deck_comment_post "$HAUTH" "$ORIGIN_CARD_ID" "Mänsklig kommentar $i ($M-$i)"
  assert_status_in "mänsklig kommentar $i postad på origin" '200,201'
done

# shellcheck disable=SC2329  # anropas indirekt (trap/poll_until)
_mirrored_three() {
  get_comments "$EAUTH" "$ENGINE_CARD_ID" || return 1
  [ "$(comment_count "$M-")" -ge 3 ]
}
if poll_until "$TAKEOVER_TIMEOUT" 5 _mirrored_three; then
  pass "speglingar origin→engine anlände inom ${TAKEOVER_TIMEOUT}s"
else
  fail "speglingar origin→engine anlände inom ${TAKEOVER_TIMEOUT}s" \
    "färre än 3 speglade kommentarer på engine-kortet"
fi

get_comments "$EAUTH" "$ENGINE_CARD_ID" || die 'kan inte läsa engine-kommentarer'
assert_eq 'EXAKT 3 speglade kommentarer på engine-kortet' 3 "$(comment_count "$M-")"
for i in 1 2 3; do
  SPEGEL=$(printf '%s' "$COMMENTS_JSON" | json_tool count_where message "$M-$i")
  assert_eq "kommentar $i speglad exakt en gång" 1 "$SPEGEL"
done
# alla speglingar är ⇄-märkta med attribution
MIRR=$(comment_count '⇄')
if [ "$MIRR" -ge 3 ]; then
  pass 'speglingarna är ⇄-märkta'
else
  fail 'speglingarna är ⇄-märkta' "bara $MIRR av minst 3 kommentarer bär ⇄-markören"
fi
assert_eq "speglingarna attribuerar avsändaren (Från $TEST_HUMAN_USER…)" 3 "$(comment_count 'Från ')"

# inga ekon tillbaka: origin har sina 3 original och INGA ⇄-kopior av dem
get_comments "$HAUTH" "$ORIGIN_CARD_ID" || die 'kan inte läsa origin-kommentarer'
assert_eq 'origin har exakt 3 kommentarer med markören (originalen)' 3 "$(comment_count "$M-")"

# --- 2. agent-kvitto engine → origin -------------------------------------------
Q="fraga$N"
RECEIPT_MSG="AGENT BLOCKED
$AGENT_CODE
Which variant should be used? ($Q)"
engine_api "$BAUTH" POST "/receipt/$ENGINE_CARD_ID" \
  "{\"token\":\"AGENT BLOCKED\",\"message\":$(json_string "$RECEIPT_MSG"),\"move\":\"needs_input\"}"
assert_status "POST /receipt (AGENT BLOCKED, move=needs_input) → 200" 200

# shellcheck disable=SC2329  # anropas indirekt (trap/poll_until)
_question_mirrored() {
  get_comments "$HAUTH" "$ORIGIN_CARD_ID" || return 1
  [ "$(comment_count "$Q")" -ge 1 ]
}
if poll_until "$TAKEOVER_TIMEOUT" 5 _question_mirrored; then
  pass "BLOCKED-frågan speglad till origin inom ${TAKEOVER_TIMEOUT}s"
else
  fail "BLOCKED-frågan speglad till origin inom ${TAKEOVER_TIMEOUT}s" \
    "ingen origin-kommentar med markören $Q"
fi
get_comments "$HAUTH" "$ORIGIN_CARD_ID" || die 'kan inte läsa origin-kommentarer'
assert_eq 'EXAKT 1 ⇄-statusuppdatering på origin för kvittot' 1 "$(comment_count "$Q")"

# --- 3. ingen ping-pong efter 2 svepcykler --------------------------------------
get_comments "$HAUTH" "$ORIGIN_CARD_ID" || die 'kan inte läsa origin-kommentarer'
ORIGIN_T0=$(comment_count '')
get_comments "$EAUTH" "$ENGINE_CARD_ID" || die 'kan inte läsa engine-kommentarer'
ENGINE_T0=$(comment_count '')
WAIT=$((SWEEP_INTERVAL * 2 + 20))
note "ping-pong-vakt: väntar $WAIT s (2 svepcykler à ${SWEEP_INTERVAL}s) …"
sleep "$WAIT"
get_comments "$HAUTH" "$ORIGIN_CARD_ID" || die 'kan inte läsa origin-kommentarer'
ORIGIN_T1=$(comment_count '')
get_comments "$EAUTH" "$ENGINE_CARD_ID" || die 'kan inte läsa engine-kommentarer'
ENGINE_T1=$(comment_count '')
assert_eq "origin-kommentarantalet stabilt efter 2 svep ($ORIGIN_T0)" "$ORIGIN_T0" "$ORIGIN_T1"
assert_eq "engine-kommentarantalet stabilt efter 2 svep ($ENGINE_T0)" "$ENGINE_T0" "$ENGINE_T1"

# recall före städning så länken stängs snyggt
deck_api "$HAUTH" PUT "/boards/$ORIGIN_BOARD_ID/stacks/$ORIGIN_STACK_ID/cards/$ORIGIN_CARD_ID/unassignUser" \
  "{\"userId\":$(json_string "$SMOKE_BOT")}" || true

summary
