#!/usr/bin/env bash
# =============================================================================
# setup-template-folder.sh   (MASKINRUMMET)
#
# Gör .docx-mallarna i mallbibliotek/Mallar/ tillgängliga för handläggarna via
# Nextclouds inbyggda mallfunktion (Filer -> + Ny -> Ny fil från mall), med en
# DELAD MAPP som mallmapp så att biblioteket är LIVE (ändras -> syns direkt).
#
# Steg:
#   1) Ladda upp Mallar/ till ägarens filer (default: admin) + files:scan.
#   2) Skapa gruppen (default: mallar-anvandare).
#   3) Dela Mallar/ SKRIVSKYDDAT med gruppen (OCS Sharing-API).
#   4) Peka varje användares mallmapp till /Mallar
#      (occ user:setting UID core templateDirectory "/Mallar").
#   Mallväljaren läser mallmappen live och rekursivt (searchByMime) -> alla
#   .docx i undermapparna dyker upp.
#
# OBS (verifierat i NC-koden):
#   - En mallmapp i en Group folder/Teammapp triggar inte förslagen tillförlitligt
#     -> använd en vanlig delning (det här skriptet gör det).
#   - skeletondirectory/templatedirectory (systemmallmapp) seedar bara vid
#     kontoskapande -> olämpligt för ett bibliotek som utvecklas. Delad mapp = live.
# =============================================================================
set -euo pipefail

CONTAINER="nextcloud-app"
SSH_HOST=""
OWNER="admin"
GROUP="mallar-anvandare"
TEMPLATE_MOUNT="Mallar"           # namnet mappen får hos mottagarna
USERS=""                          # "uid1 uid2" — sätts mallmapp + läggs i gruppen
ALL_USERS=0
BASE_URL="http://localhost:8080"
ADMIN_PASS="${NC_ADMIN_PASS:-Hubs-demo-2026}"
DRY_RUN=0

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LIB_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
SRC_MALLAR="$LIB_DIR/Mallar"

usage(){ sed -n '2,30p' "$0"; cat <<EOF

Användning: setup-template-folder.sh [flaggor]
  --dry-run           Visa vad som skulle göras.
  --container NAMN     Docker-container (default: $CONTAINER; dev15 t.ex. hubs-php).
  --ssh HOST          Kör docker-anropen över ssh HOST.
  --owner UID         Ägare av Mallar (default: $OWNER).
  --group NAMN        Grupp att dela med (default: $GROUP).
  --users "a b c"     Användare vars mallmapp pekas till /$TEMPLATE_MOUNT (+ läggs i gruppen).
  --all               Alla användare (occ user:list).
  --url URL           NC bas-URL (default: $BASE_URL).
  --password PW       Ägarens lösenord (eller env NC_ADMIN_PASS).
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --dry-run) DRY_RUN=1; shift;;
    --container) CONTAINER="$2"; shift 2;;
    --ssh) SSH_HOST="$2"; shift 2;;
    --owner) OWNER="$2"; shift 2;;
    --group) GROUP="$2"; shift 2;;
    --users) USERS="$2"; shift 2;;
    --all) ALL_USERS=1; shift;;
    --url) BASE_URL="$2"; shift 2;;
    --password) ADMIN_PASS="$2"; shift 2;;
    -h|--help) usage; exit 0;;
    *) echo "Okänd flagga: $1" >&2; usage; exit 1;;
  esac
done

log(){ printf '\033[36m[mallmapp]\033[0m %s\n' "$*"; }
warn(){ printf '\033[33m[varn]\033[0m %s\n' "$*" >&2; }
dexec(){ local i="$*"; if [[ -n "$SSH_HOST" ]]; then MSYS_NO_PATHCONV=1 ssh "$SSH_HOST" "docker exec -u www-data $CONTAINER $i"; else MSYS_NO_PATHCONV=1 docker exec -u www-data "$CONTAINER" bash -lc "$i"; fi; }
dexec_root(){ local i="$*"; if [[ -n "$SSH_HOST" ]]; then MSYS_NO_PATHCONV=1 ssh "$SSH_HOST" "docker exec $CONTAINER $i"; else MSYS_NO_PATHCONV=1 docker exec "$CONTAINER" bash -lc "$i"; fi; }
occ(){ dexec "php occ $*"; }
run(){ if [[ $DRY_RUN -eq 1 ]]; then echo "  DRY: $*"; else eval "$@"; fi; }

[[ -d "$SRC_MALLAR" ]] || { echo "FEL: $SRC_MALLAR saknas. Kör build-docx.sh först." >&2; exit 1; }

# --- 1. ladda upp Mallar/ till ägarens filer ------------------------------
upload() {
  local dest="/var/www/html/data/$OWNER/files/$TEMPLATE_MOUNT"
  log "Laddar upp Mallar/ -> $OWNER:$dest"
  if [[ $DRY_RUN -eq 1 ]]; then echo "  DRY: docker cp Mallar -> $dest ; chown www-data ; files:scan"; return; fi
  # töm ev. tidigare och kopiera in
  dexec_root "rm -rf '$dest' && mkdir -p '$dest'"
  MSYS_NO_PATHCONV=1 tar -C "$SRC_MALLAR" -cf - . | MSYS_NO_PATHCONV=1 docker cp - "$CONTAINER:$dest/"
  dexec_root "chown -R www-data:www-data '$dest'"
  occ "files:scan --path=\"$OWNER/files/$TEMPLATE_MOUNT\""
}

# --- 2. grupp -------------------------------------------------------------
ensure_group() { log "Säkerställer grupp \"$GROUP\""; run "occ group:add \"$GROUP\" || true"; }

# --- 3. dela skrivskyddat med gruppen -------------------------------------
share() {
  log "Delar /$TEMPLATE_MOUNT skrivskyddat med \"$GROUP\""
  local api="$BASE_URL/ocs/v2.php/apps/files_sharing/api/v1/shares?format=json"
  local data="path=/$TEMPLATE_MOUNT&shareType=1&shareWith=$GROUP&permissions=1"
  if [[ $DRY_RUN -eq 1 ]]; then echo "  DRY: POST $api  ($data)"; return; fi
  local resp
  resp="$(dexec "curl -sS -u '$OWNER:$ADMIN_PASS' -H 'OCS-APIRequest: true' -X POST '$api' -d '$data'" || true)"
  if echo "$resp" | grep -qiE '"status"\s*:\s*"ok"|"statuscode":\s*200|"id"'; then
    log "Delning skapad (eller fanns redan)."
  else
    warn "Delnings-API-svar: $resp"
    warn "Om delningen redan finns är detta ofarligt. Annars: dela mappen /$TEMPLATE_MOUNT skrivskyddat med gruppen i GUI."
  fi
}

# --- 4. peka mallmapp per användare ---------------------------------------
set_template_dirs() {
  local list="$USERS"
  if [[ $ALL_USERS -eq 1 ]]; then
    list="$(occ 'user:list --output=json' | node -e 'let d="";process.stdin.on("data",c=>d+=c).on("end",()=>{try{console.log(Object.keys(JSON.parse(d)).join(" "))}catch(e){}})' 2>/dev/null || true)"
  fi
  if [[ -z "$list" ]]; then
    warn "Inga användare angivna (--users \"a b\" eller --all). Hoppar över mallmapp-pekning."
    warn "Användare kan själva välja mallmapp i Inställningar -> Filer -> Mallmapp: /$TEMPLATE_MOUNT"
    return
  fi
  for uid in $list; do
    log "Användare $uid: grupp + mallmapp /$TEMPLATE_MOUNT"
    run "occ group:adduser \"$GROUP\" \"$uid\" || true"
    run "occ user:setting \"$uid\" core templateDirectory \"/$TEMPLATE_MOUNT\""
  done
}

# --- main -----------------------------------------------------------------
upload
ensure_group
share
set_template_dirs
log "Klart. Handläggarna skapar nu dokument via Filer -> + Ny -> Ny fil från mall."
log "Biblioteket är LIVE: uppdatera .docx i $OWNER:/$TEMPLATE_MOUNT (kör build-docx.sh + upload) så ser alla ändringen."
