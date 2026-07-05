#!/usr/bin/env bash
# =============================================================================
# smoke-02-capture-roundtrip.sh — Talk-capture-rundturen (CONTRACTS §6, §9)
#
# Postar en syntetisk Talk-signerad webhook DIREKT till capture-boten (:8790)
# med giltig HMAC för Sarah-frasen i Rebs rum:
#   1. tanken landar som exakt EN rad i rätt hjärna (verifieras via sök-API:t)
#      och i INGEN annan hjärna; bekräftelse-reply loggad
#   2. samma message-id igen → fortfarande EN rad (dedupe)
#   3. manipulerad HMAC → 401
#   4. personnummer → 422 OCH ingen rad i någon hjärna
# =============================================================================
set -u
SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"
smoke_init "$@"

require_vars TALK_BOT_SECRET_REB BRAIN_KEY_REB BRAIN_KEY_ATLAS BRAIN_KEY_ADA BRAIN_KEY_MARVIN BRAIN_KEY_TEAM

BRAINS=(reb atlas ada marvin team)

key_of() {
  local up
  up=$(printf '%s' "$1" | tr '[:lower:]' '[:upper:]')
  local var="BRAIN_KEY_$up"
  printf '%s' "${!var}"
}

# --- rumstoken för "Reb minne" (ur capture-bot/rooms.json på servern) --------
ROOM_TOKEN=${ROOM_TOKEN_REB:-}
if [ -z "$ROOM_TOKEN" ]; then
  require_vars DEV15_SSH
  ROOMS_JSON=$(remote "cat '$ROOMS_JSON_PATH'") \
    || die "kunde inte läsa $ROOMS_JSON_PATH via ssh (sätt annars ROOM_TOKEN_REB i tests/.env.test)"
  ROOM_TOKEN=$(printf '%s' "$ROOMS_JSON" | json_tool room_find reb | head -n 1)
  [ -n "$ROOM_TOKEN" ] || die "hittade ingen rumstoken för Rebs rum i $ROOMS_JSON_PATH"
fi
note "rumstoken för 'Reb minne': $ROOM_TOKEN"

N1=$(nonce)
N2=$(nonce)
MSGID1="msg-$N1-a"
MSGID3="msg-$N1-hmac"
MSGID4="msg-$N2-pnr"

SARAH="Sarah mentioned she is thinking about leaving her job to start a consulting business ($N1)"

search_count() { # <hjärna> <nål> — förekomster i sökresultatet
  brain_search "$1" "$(key_of "$1")" "$2"
  count_in "$HTTP_BODY" "$2"
}

# shellcheck disable=SC2329  # anropas indirekt (trap/poll_until)
_found_once_in_reb() {
  [ "$(search_count reb "$N1")" -ge 1 ]
}

# --- 1. Sarah-frasen med giltig HMAC -----------------------------------------
BODY1=$(talk_webhook_body "$ROOM_TOKEN" 'Reb minne' "$MSGID1" "$SARAH" rebecca 'Rebecca')
capture_post "$TALK_BOT_SECRET_REB" "$BODY1"
assert_status_in 'Sarah-frasen med giltig HMAC accepteras (2xx)' '200,201,202'

if poll_until 90 5 _found_once_in_reb; then
  pass 'tanken återfinns i brain-reb via sök-API:t'
else
  fail 'tanken återfinns i brain-reb via sök-API:t' "markören $N1 dök inte upp inom 90 s"
fi
assert_eq 'exakt EN rad i brain-reb' 1 "$(search_count reb "$N1")"
for b in atlas ada marvin team; do
  assert_eq "noll rader i brain-$b" 0 "$(search_count "$b" "$N1")"
done

# bekräftelse-reply loggad (capture-botens logg på servern)
if [ -n "${DEV15_SSH:-}" ]; then
  LOGS=$(remote "cd /opt/openstack && docker compose logs --since 15m capture-bot 2>&1" || true)
  if [ "$(count_in "$LOGS" "$MSGID1")" -ge 1 ] || [ "$(count_in "$LOGS" "$N1")" -ge 1 ]; then
    pass 'bekräftelse-reply loggad i capture-boten'
  else
    fail 'bekräftelse-reply loggad i capture-boten' \
      "varken $MSGID1 eller $N1 syns i capture-botens logg (15 min)"
  fi
else
  fail 'bekräftelse-reply loggad i capture-boten' 'DEV15_SSH saknas — kan inte läsa serverloggen'
fi

# --- 2. Dedupe: samma message-id igen → fortfarande EN rad -------------------
capture_post "$TALK_BOT_SECRET_REB" "$BODY1"
assert_status_in 'dubblett-webhook besvaras OK (Talk retry:ar)' '200,201,202'
sleep 10
assert_eq 'fortfarande exakt EN rad efter dubblett (talk_id-dedupe)' 1 "$(search_count reb "$N1")"

# --- 3. Manipulerad HMAC → 401 -----------------------------------------------
BODY3=$(talk_webhook_body "$ROOM_TOKEN" 'Reb minne' "$MSGID3" "HMAC-manipulationstest ($N1)" rebecca 'Rebecca')
capture_post "$TALK_BOT_SECRET_REB" "$BODY3" 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef'
assert_status 'manipulerad HMAC → 401' 401

# --- 4. Personnummer → 422 + ingen rad någonstans ----------------------------
BODY4=$(talk_webhook_body "$ROOM_TOKEN" 'Reb minne' "$MSGID4" \
  "Ring klienten 19850615-1234 imorgon ($N2)" rebecca 'Rebecca')
capture_post "$TALK_BOT_SECRET_REB" "$BODY4"
assert_status 'personnummer → 422 (skriv-brandväggen)' 422
sleep 10
for b in "${BRAINS[@]}"; do
  assert_eq "personnummer-tanken finns INTE i brain-$b" 0 "$(search_count "$b" "$N2")"
done

summary
