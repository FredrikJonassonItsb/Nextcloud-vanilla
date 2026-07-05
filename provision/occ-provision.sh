#!/usr/bin/env bash
#
# occ-provision.sh — idempotent provisioning of the ITSL Open Stack Nextcloud side
# on dev15, per CONTRACTS §1 (identities), §2 (Deck prerequisites) and §6 (capture rooms).
#
# Runs from Windows Git Bash over ssh; ALL server logic executes remotely via the
# quoted-heredoc pattern (same as scripts/dev15-reset.sh). Safe to re-run any time.
#
#   provision/occ-provision.sh
#   HUBS_SSH="ubuntu@10.43.51.62" provision/occ-provision.sh
#
# Steps:
#   1. Verify human uids (rebecca fredrik sandra mattias) via `occ user:list`.
#      Missing humans are NEVER created — they land in provision/PENDING-USERS.md
#      and are excluded from the routing map (CONTRACTS §1).
#   2. Create bot users bot-reb/atlas/ada/marvin/engine (OC_PASS + occ user:add
#      --display-name), membership in group `agent-bots`.
#   3. App passwords via `occ user:auth-tokens:add` → appended to /opt/openstack/.env
#      as BOT_APP_PASSWORD_* (chmod 600; existing values are KEPT).
#   4. Talk: rooms "Reb minne" "Atlas minne" "Ada minne" "Marvin minne" "Team minne"
#      "Agent Ops"; install the 4 agent bots (secrets from .env, generated if absent)
#      pointing at http://10.43.51.62:8790/bot; talk:bot:setup into the right rooms;
#      write /opt/openstack/capture-bot/rooms.json (room-token → brain routing).
#   5. agent_engine app config: push_secret (= ENGINE_PUSH_SECRET), routing_map
#      (verified uids only), board_id (from /opt/openstack/state/bootstrap.json when
#      present — run deck-bootstrap.mjs first, then re-run this script).
#   6. Print a verification table.
#
set -euo pipefail

SSH_TARGET="${HUBS_SSH:-ubuntu@10.43.51.62}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PENDING_FILE="${SCRIPT_DIR}/PENDING-USERS.md"
TMP_OUT="$(mktemp)"
trap 'rm -f "$TMP_OUT"' EXIT

echo "→ Provisionerar ${SSH_TARGET} …"

ssh -o BatchMode=yes -o ConnectTimeout=15 "${SSH_TARGET}" bash -s <<'REMOTE' | tee "$TMP_OUT"
set -euo pipefail

occ() { sudo docker exec -u www-data hubs-php php /var/www/html/occ "$@"; }
occ_env() { local pass="$1"; shift; sudo docker exec -u www-data -e OC_PASS="$pass" hubs-php php /var/www/html/occ "$@"; }
psql_ro() { sudo docker exec hubs-postgres psql -U oc_hubs -d hubs -t -A -c "$1"; }

command -v python3 >/dev/null 2>&1 || { echo "##FATAL python3 saknas på servern"; exit 1; }

ENV_FILE=/opt/openstack/.env
sudo mkdir -p /opt/openstack /opt/openstack/capture-bot /opt/openstack/state
sudo touch "$ENV_FILE"
sudo chmod 600 "$ENV_FILE"

# LAST non-empty match wins (deploy.sh may have seeded empty placeholders).
env_get() { sudo grep -E "^${1}=..*" "$ENV_FILE" 2>/dev/null | tail -n1 | cut -d= -f2- || true; }
env_append() {  # replace-or-append so re-runs never duplicate keys
  if sudo grep -qE "^${1}=" "$ENV_FILE"; then
    sudo sed -i "s|^${1}=.*|${1}=${2}|" "$ENV_FILE"
  else
    printf '%s=%s\n' "$1" "$2" | sudo tee -a "$ENV_FILE" >/dev/null
  fi
}
env_ensure() {
  if [ -z "$(env_get "$1")" ]; then
    env_append "$1" "$2"
    echo "  + .env: $1 genererad"
  else
    echo "  = .env: $1 behållen"
  fi
}

echo "── 1) Människor (verifieras via occ user:list — skapas ALDRIG) ──"
USERS_JSON="$(occ user:list --output=json --limit 10000)"
user_known() {
  printf '%s' "$USERS_JSON" | python3 -c 'import json,sys; sys.exit(0 if sys.argv[1] in json.load(sys.stdin) else 1)' "$1"
}

# Per-instans-uid (CONTRACTS §1 + docs/DEV15-FACTS.md): BankID-instanser använder
# personnummer som uid. Override i /opt/openstack/.env: HUMAN_UID_FREDRIK=197411040293 osv.
resolve_uid() {
  local canon="$1" up ovr
  up="$(printf '%s' "$canon" | tr '[:lower:]' '[:upper:]')"
  ovr="$(env_get "HUMAN_UID_${up}")"
  if [ -n "$ovr" ] && user_known "$ovr"; then printf '%s' "$ovr"; return 0; fi
  if user_known "$canon"; then printf '%s' "$canon"; return 0; fi
  printf ''
}

UID_REBECCA="$(resolve_uid rebecca)"
UID_FREDRIK="$(resolve_uid fredrik)"
UID_SANDRA="$(resolve_uid sandra)"
UID_MATTIAS="$(resolve_uid mattias)"

VERIFIED=""
VERIFIED_UIDS=""
for u in rebecca fredrik sandra mattias; do
  up="$(printf '%s' "$u" | tr '[:lower:]' '[:upper:]')"
  eval "ruid=\$UID_${up}"
  if [ -n "$ruid" ]; then
    VERIFIED="$VERIFIED $u"
    VERIFIED_UIDS="$VERIFIED_UIDS $ruid"
    echo "##HUMAN_OK $u -> $ruid"
  else
    echo "##HUMAN_MISSING $u"
  fi
done
has() { case " $VERIFIED " in *" $1 "*) return 0 ;; *) return 1 ;; esac; }

echo "── 2) Bot-användare + grupp agent-bots ──"
if occ group:list --output=json | python3 -c 'import json,sys; sys.exit(0 if "agent-bots" in json.load(sys.stdin) else 1)'; then
  echo "  = grupp agent-bots finns"
else
  occ group:add agent-bots >/dev/null
  echo "  + grupp agent-bots skapad"
fi

bot_display() {
  case "$1" in
    reb)    echo "Reb (agent)" ;;
    atlas)  echo "Atlas (agent)" ;;
    ada)    echo "Ada (agent)" ;;
    marvin) echo "Marvin (agent)" ;;
    engine) echo "Agent Engine" ;;
  esac
}

for b in reb atlas ada marvin engine; do
  uid="bot-$b"
  disp="$(bot_display "$b")"
  suf="$(printf '%s' "$b" | tr '[:lower:]' '[:upper:]')"
  key="BOT_APP_PASSWORD_${suf}"
  fresh_pass=""
  if occ user:info "$uid" >/dev/null 2>&1; then
    echo "  = användare $uid finns"
  else
    fresh_pass="$(openssl rand -hex 16)"
    occ_env "$fresh_pass" user:add --password-from-env --display-name "$disp" "$uid" >/dev/null
    echo "  + användare $uid skapad ($disp)"
  fi
  occ group:adduser agent-bots "$uid" >/dev/null 2>&1 || true

  # 3) App password — KEEP existing .env value; only mint when absent.
  if [ -n "$(env_get "$key")" ]; then
    echo "  = .env: $key behållen"
  else
    if [ -z "$fresh_pass" ]; then
      # Existing bot, unknown login password: reset it so auth-tokens:add can authenticate.
      fresh_pass="$(openssl rand -hex 16)"
      occ_env "$fresh_pass" user:resetpassword --password-from-env "$uid" >/dev/null
    fi
    out="$(occ_env "$fresh_pass" user:auth-tokens:add "$uid" --password-from-env)"
    tok="$(printf '%s\n' "$out" | grep -E '^[A-Za-z0-9]{40,}$' | head -n1 || true)"
    if [ -z "$tok" ]; then
      tok="$(printf '%s\n' "$out" | awk 'NF { line = $0 } END { print line }')"
    fi
    if [ -z "$tok" ]; then
      echo "##FATAL app-lösenord för $uid kunde inte skapas. occ-utdata: $out"
      exit 1
    fi
    env_append "$key" "$tok"
    echo "  + .env: $key — nytt app-lösenord skapat"
  fi
done

echo "── 3) Secrets i .env (genereras endast om de saknas) ──"
for s in REB ATLAS ADA MARVIN; do
  env_ensure "TALK_BOT_SECRET_${s}" "$(openssl rand -hex 32)"
done
env_ensure ENGINE_PUSH_SECRET "$(openssl rand -hex 32)"

echo "── 4) Talk-rum ──"
room_token() {
  # Primary: occ talk:room:list; fallback: direct read of oc_talk_rooms (fixed names, no injection risk).
  local name="$1" json=""
  if json="$(occ talk:room:list --output=json 2>/dev/null)"; then
    printf '%s' "$json" | python3 -c '
import json, sys
data = json.load(sys.stdin)
if isinstance(data, dict):
    data = list(data.values())
for r in data:
    if r.get("name") == sys.argv[1] or r.get("displayName") == sys.argv[1]:
        print(r.get("token") or "")
        break
' "$1"
  else
    psql_ro "SELECT token FROM oc_talk_rooms WHERE name = '$name' ORDER BY id LIMIT 1"
  fi
}

ensure_room() {  # $1=name $2=owner, rest=extra members. Prints token on stdout, logs on stderr.
  local name="$1" owner="$2"; shift 2
  local tok u
  tok="$(room_token "$name")"
  if [ -n "$tok" ]; then
    echo "  = rum \"$name\" finns (token $tok)" >&2
  else
    local args=(talk:room:create "$name" --owner "$owner" --user "$owner")
    for u in "$@"; do
      [ "$u" = "$owner" ] && continue
      args+=(--user "$u")
    done
    occ "${args[@]}" >/dev/null
    tok="$(room_token "$name")"
    echo "  + rum \"$name\" skapat (token $tok)" >&2
  fi
  printf '%s' "$tok"
}

SHARED_OWNER=""
if has fredrik; then
  SHARED_OWNER="$UID_FREDRIK"
else
  SHARED_OWNER="$(printf '%s' "$VERIFIED_UIDS" | awk '{print $1}')"
fi

TOK_REB=""; TOK_ATLAS=""; TOK_ADA=""; TOK_MARVIN=""; TOK_TEAM=""; TOK_OPS=""
if has rebecca; then TOK_REB="$(ensure_room "Reb minne" "$UID_REBECCA")"; else echo "##ROOM_DEFERRED Reb minne (rebecca saknas)"; fi
if has fredrik; then TOK_ATLAS="$(ensure_room "Atlas minne" "$UID_FREDRIK")"; else echo "##ROOM_DEFERRED Atlas minne (fredrik saknas)"; fi
if has sandra; then TOK_ADA="$(ensure_room "Ada minne" "$UID_SANDRA")"; else echo "##ROOM_DEFERRED Ada minne (sandra saknas)"; fi
if has mattias; then TOK_MARVIN="$(ensure_room "Marvin minne" "$UID_MATTIAS")"; else echo "##ROOM_DEFERRED Marvin minne (mattias saknas)"; fi
if [ -n "$SHARED_OWNER" ]; then
  # shellcheck disable=SC2086  # word-splitting of $VERIFIED_UIDS is intended
  TOK_TEAM="$(ensure_room "Team minne" "$SHARED_OWNER" $VERIFIED_UIDS)"
  # bot-engine is a member of Agent Ops so runner/engine alarms can be posted there.
  # shellcheck disable=SC2086
  TOK_OPS="$(ensure_room "Agent Ops" "$SHARED_OWNER" $VERIFIED_UIDS bot-engine)"
else
  echo "##ROOM_DEFERRED Team minne (inga verifierade människor)"
  echo "##ROOM_DEFERRED Agent Ops (inga verifierade människor)"
fi

echo "── 5) Talk-bottar (capture-bot-webhook) ──"
# Talk kräver UNIK url per installerad bot → per-bot-path (/bot/reb …);
# capture-bot löser secret ur sluggen (multi-bot-rum signerar per leverans).
BOT_URL_BASE="http://10.43.51.62:8790/bot"

bot_id_by_name() {
  local json=""
  if json="$(occ talk:bot:list --output=json 2>/dev/null)"; then
    printf '%s' "$json" | python3 -c '
import json, sys
data = json.load(sys.stdin)
if isinstance(data, dict):
    data = list(data.values())
for b in data:
    if b.get("name") == sys.argv[1]:
        print(b.get("id"))
        break
' "$1"
  else
    psql_ro "SELECT id FROM oc_talk_bots_server WHERE name = '$1' ORDER BY id LIMIT 1"
  fi
}

ensure_talk_bot() {  # $1=bot name $2=secret $3=url-slug. Prints bot id on stdout, logs on stderr.
  local id
  id="$(bot_id_by_name "$1")"
  if [ -n "$id" ]; then
    echo "  = talk-bot \"$1\" finns (id $id)" >&2
  else
    occ talk:bot:install --feature webhook,response "$1" "$2" "${BOT_URL_BASE}/$3" "ITSL Open Stack capture-bot" >/dev/null
    id="$(bot_id_by_name "$1")"
    [ -n "$id" ] || { echo "##FATAL talk-bot \"$1\" kunde inte installeras" >&2; exit 1; }
    echo "  + talk-bot \"$1\" installerad (id $id, url ${BOT_URL_BASE}/$3)" >&2
  fi
  printf '%s' "$id"
}

bot_in_room() {  # $1=bot id $2=room token
  local json=""
  if json="$(occ talk:bot:list --output=json "$2" 2>/dev/null)"; then
    printf '%s' "$json" | python3 -c '
import json, sys
data = json.load(sys.stdin)
if isinstance(data, dict):
    data = list(data.values())
sys.exit(0 if any(str(b.get("id")) == sys.argv[1] for b in data) else 1)
' "$1"
  else
    [ -n "$(psql_ro "SELECT 1 FROM oc_talk_bots_conversation WHERE bot_id = $1 AND token = '$2' LIMIT 1")" ]
  fi
}

ensure_bot_setup() {  # $1=bot id $2=room token $3=room name
  [ -n "$2" ] || return 0
  if bot_in_room "$1" "$2"; then
    echo "  = bot $1 redan aktiv i \"$3\""
  else
    occ talk:bot:setup "$1" "$2" >/dev/null
    echo "  + bot $1 aktiverad i \"$3\""
  fi
}

ID_REB="$(ensure_talk_bot "Reb (agent)" "$(env_get TALK_BOT_SECRET_REB)" reb)"
ID_ATLAS="$(ensure_talk_bot "Atlas (agent)" "$(env_get TALK_BOT_SECRET_ATLAS)" atlas)"
ID_ADA="$(ensure_talk_bot "Ada (agent)" "$(env_get TALK_BOT_SECRET_ADA)" ada)"
ID_MARVIN="$(ensure_talk_bot "Marvin (agent)" "$(env_get TALK_BOT_SECRET_MARVIN)" marvin)"

ensure_bot_setup "$ID_REB" "$TOK_REB" "Reb minne"
ensure_bot_setup "$ID_ATLAS" "$TOK_ATLAS" "Atlas minne"
ensure_bot_setup "$ID_ADA" "$TOK_ADA" "Ada minne"
ensure_bot_setup "$ID_MARVIN" "$TOK_MARVIN" "Marvin minne"
# Team minne: all four agent bots — capture-bot dedupes on message-id (CONTRACTS §6),
# so multi-delivery is safe and !queue works with each agent's own bot identity.
for id in "$ID_REB" "$ID_ATLAS" "$ID_ADA" "$ID_MARVIN"; do
  ensure_bot_setup "$id" "$TOK_TEAM" "Team minne"
done
# Agent Ops is alarm-only (no capture) — no bot setup there.

echo "── 6) capture-bot/rooms.json ──"
# FLAT format per capture-bot route.js: { "<token>": {brain, botEnv, mode} }.
# "__meta" ignoreras av boten (nycklar med __-prefix filtreras bort).
# Team-rummet: verifiering sker per LEVERERANDE bot (url-slug); botEnv=REB
# används endast för svar (Reb (agent) svarar i teamrummet, v1).
python3 - "$TOK_REB" "$TOK_ATLAS" "$TOK_ADA" "$TOK_MARVIN" "$TOK_TEAM" <<'PY' | sudo tee /opt/openstack/capture-bot/rooms.json >/dev/null
import datetime, json, sys

toks = (sys.argv[1:] + [""] * 5)[:5]
spec = [
    ("Reb minne", "reb", "REB", "personal"),
    ("Atlas minne", "atlas", "ATLAS", "personal"),
    ("Ada minne", "ada", "ADA", "personal"),
    ("Marvin minne", "marvin", "MARVIN", "personal"),
    ("Team minne", "team", "REB", "team"),
]
rooms = {}
for tok, (name, brain, bot_env, mode) in zip(toks, spec):
    if not tok:
        continue
    rooms[tok] = {"name": name, "brain": brain, "botEnv": bot_env, "mode": mode}
rooms["__meta"] = {
    "version": 1,
    "generatedAt": datetime.datetime.now(datetime.timezone.utc).isoformat(timespec="seconds"),
}
print(json.dumps(rooms, ensure_ascii=False, indent=2))
PY
sudo chmod 600 /opt/openstack/capture-bot/rooms.json
echo "  ✓ /opt/openstack/capture-bot/rooms.json skriven ($(sudo grep -c '"name"' /opt/openstack/capture-bot/rooms.json) rum)"

echo "── 7) agent_engine-appkonfig ──"
occ config:app:set agent_engine push_secret --value "$(env_get ENGINE_PUSH_SECRET)" >/dev/null
echo "  ✓ push_secret satt (= ENGINE_PUSH_SECRET ur .env)"

# bot-engine-tjänstekontot: agent_engine anropar Deck/Talk-API:t som bot-engine
# (BotServiceAuth läser bot_user/bot_token). Utan detta går ALLA Deck-anrop
# (claim/ledger/takeover/mirror) oautentiserade → null → "card not found".
occ config:app:set agent_engine bot_user  --value bot-engine >/dev/null
occ config:app:set agent_engine bot_token --value "$(env_get BOT_APP_PASSWORD_ENGINE)" >/dev/null
if [ -n "$(env_get BOT_APP_PASSWORD_ENGINE)" ]; then
  echo "  ✓ bot_user=bot-engine + bot_token satt (Deck/Talk-tjänsteauth)"
else
  echo "  ! BOT_APP_PASSWORD_ENGINE saknas i .env — bot_token TOMT (Deck-anrop kommer 401:a)"
fi

# Routing map contains VERIFIED, RESOLVED human uids only (CONTRACTS §1: uid är
# per-instans-konfig — på BankID-instanser är uid personnummer, se DEV15-FACTS).
ROUTING_JSON="$(python3 - "rebecca=$UID_REBECCA" "fredrik=$UID_FREDRIK" "sandra=$UID_SANDRA" "mattias=$UID_MATTIAS" <<'PY'
import json, sys
uids = dict(a.split("=", 1) for a in sys.argv[1:])
table = [
    ("reb-claude", "rebecca", "bot-reb", "Reb"),
    ("atlas-claude", "fredrik", "bot-atlas", "Atlas"),
    ("ada-claude", "sandra", "bot-ada", "Ada"),
    ("marvin-claude", "mattias", "bot-marvin", "Marvin"),
]
agents = {
    code: {"human": uids[human], "bot": bot, "agent": name}
    for code, human, bot, name in table
    if uids.get(human)
}
print(json.dumps({"version": "v1", "agents": agents}, ensure_ascii=False))
PY
)"
occ config:app:set agent_engine routing_map --value "$ROUTING_JSON" >/dev/null
echo "  ✓ routing_map satt: $ROUTING_JSON"

if sudo test -s /opt/openstack/state/bootstrap.json; then
  BOARD_ID="$(sudo cat /opt/openstack/state/bootstrap.json | python3 -c 'import json,sys; print(json.load(sys.stdin).get("boardId", ""))')"
  if [ -n "$BOARD_ID" ]; then
    occ config:app:set agent_engine engine_board_id --value "$BOARD_ID" >/dev/null
    echo "  ✓ engine_board_id satt: $BOARD_ID"
  else
    echo "  ! bootstrap.json saknar boardId — board_id ej satt"
  fi
else
  echo "  ! /opt/openstack/state/bootstrap.json saknas — kör deck-bootstrap.mjs och sedan detta skript igen (board_id)"
fi

echo ""
echo "=== VERIFIERING ==="
printf '%-36s %s\n' "Objekt" "Status"
printf '%-36s %s\n' "------------------------------------" "--------------------------------"
for u in rebecca fredrik sandra mattias; do
  if has "$u"; then st="OK"; else st="SAKNAS → PENDING-USERS.md"; fi
  printf '%-36s %s\n' "människa $u" "$st"
done
for b in reb atlas ada marvin engine; do
  uid="bot-$b"
  suf="$(printf '%s' "$b" | tr '[:lower:]' '[:upper:]')"
  if occ user:info "$uid" >/dev/null 2>&1; then st="finns"; else st="SAKNAS"; fi
  if [ -n "$(env_get "BOT_APP_PASSWORD_${suf}")" ]; then ap="JA"; else ap="NEJ"; fi
  printf '%-36s %s\n' "bot $uid" "$st, app-lösenord i .env: $ap"
done
printf '%-36s %s\n' "rum Reb minne" "${TOK_REB:-— (uppskjutet)}"
printf '%-36s %s\n' "rum Atlas minne" "${TOK_ATLAS:-— (uppskjutet)}"
printf '%-36s %s\n' "rum Ada minne" "${TOK_ADA:-— (uppskjutet)}"
printf '%-36s %s\n' "rum Marvin minne" "${TOK_MARVIN:-— (uppskjutet)}"
printf '%-36s %s\n' "rum Team minne" "${TOK_TEAM:-— (uppskjutet)}"
printf '%-36s %s\n' "rum Agent Ops" "${TOK_OPS:-— (uppskjutet)}"
printf '%-36s %s\n' "talk-bottar (id)" "reb=$ID_REB atlas=$ID_ATLAS ada=$ID_ADA marvin=$ID_MARVIN"
if occ config:app:get agent_engine push_secret >/dev/null 2>&1; then ps="satt"; else ps="saknas"; fi
printf '%-36s %s\n' "agent_engine push_secret" "$ps"
printf '%-36s %s\n' "agent_engine board_id" "$(occ config:app:get agent_engine board_id 2>/dev/null || echo '— (kör deck-bootstrap.mjs)')"
REMOTE

# ── Local post-processing: PENDING-USERS.md from ##HUMAN_MISSING markers ──────
mapfile -t MISSING < <(grep '^##HUMAN_MISSING ' "$TMP_OUT" | awk '{print $2}')
if [ "${#MISSING[@]}" -gt 0 ]; then
  {
    echo "# PENDING-USERS — mänskliga konton som saknas på servern"
    echo ""
    echo "Genererad av \`provision/occ-provision.sh\` $(date -u +%Y-%m-%dT%H:%M:%SZ)."
    echo "Provisioneringen skapar ALDRIG mänskliga konton (CONTRACTS §1)."
    echo ""
    echo "Skapa dessa uid manuellt (admin-GUI eller \`occ user:add\`) och kör om"
    echo "\`provision/occ-provision.sh\` — först då tas de med i routingkartan och"
    echo "får sina capture-rum:"
    echo ""
    for u in "${MISSING[@]}"; do echo "- \`$u\`"; done
  } > "$PENDING_FILE"
  echo "! ${#MISSING[@]} människor saknas → ${PENDING_FILE}"
else
  if [ -f "$PENDING_FILE" ]; then
    rm -f "$PENDING_FILE"
    echo "✓ Alla människor verifierade — PENDING-USERS.md borttagen."
  fi
fi

echo "✓ Provisionering klar."
