#!/usr/bin/env bash
# =============================================================================
# publish-handbook.sh   (SKYLTFÖNSTRET)
#
# Publicerar mallbibliotekets HANDBOK i Nextcloud Collectives som collective:t
# "Kunskapsbank och mallar" med sidträdet  profession -> dokument.
#
# VIKTIGT: Collectives är HANDBOKEN/översikten (skyltfönstret) — en snyggt
# formaterad beskrivning av varje mall (när den används, lagrum, rubriker).
# De RIKTIGA ifyllbara .docx-mallarna ligger i Filer via mallfunktionen; kör
# build-docx.sh + setup-template-folder.sh för det (maskinrummet). Handläggarna
# SKAPAR dokument via Filer -> + Ny -> Ny fil från mall, inte härifrån.
#
# Kör från vilken katalog som helst; skriptet hittar sin egen plats.
#
# Metod (robust, icke-destruktiv):
#   1) Aktivera apparna circles + collectives (installera vid behov).
#   2) Skapa collective:t via Collectives REST-API (om det inte redan finns).
#   3) Bygg ett Collectives-format sidträd lokalt (build/collective-tree/).
#   4) Hitta den mapp som appen själva skapade i containern och lägg in träden.
#   5) occ files:scan så att Collectives läser in sidorna.
#
# Sidträdet i build/collective-tree/ är ALLTID korrekt Collectives-format och
# kan dras in manuellt om en instans avviker (--tree-only).
#
# Windows/Git Bash: docker-anrop körs med MSYS_NO_PATHCONV=1.
# =============================================================================
set -euo pipefail

# ---- standardvärden -------------------------------------------------------
CONTAINER="nextcloud-app"          # lokal Docker-container (dev15: t.ex. hubs-php)
SSH_HOST=""                        # sätt med --ssh för att köra mot fjärrvärd
NC_USER="admin"                    # ägare av collective:t
COLLECTIVE="Kunskapsbank och mallar"
BASE_URL="http://localhost:8080"   # nås inifrån/utifrån containern
ADMIN_PASS="${NC_ADMIN_PASS:-Hubs-demo-2026}"   # sätt env NC_ADMIN_PASS i skarp miljö
DRY_RUN=0
TREE_ONLY=0

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LIB_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"           # .../mallbibliotek
SRC_PROF="$LIB_DIR/socialsekreterare-barn-familj"
BUILD_DIR="$SCRIPT_DIR/build/collective-tree"

usage() {
  sed -n '2,20p' "$0"
  cat <<EOF

Användning: import-collectives.sh [flaggor]
  --dry-run              Visa vad som skulle göras, skriv inget.
  --tree-only            Bygg bara build/collective-tree/ (ingen container/NC).
  --container NAMN       Docker-container (default: $CONTAINER).
  --ssh HOST             Kör docker-anropen över ssh HOST (t.ex. dev15).
  --user USER            Ägare av collective:t (default: $NC_USER).
  --collective NAMN      Collective-namn (default: "$COLLECTIVE").
  --url URL              Bas-URL till NC (default: $BASE_URL).
  --password PW          Admin-lösenord (eller env NC_ADMIN_PASS).
  -h | --help            Denna hjälp.
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --dry-run) DRY_RUN=1; shift;;
    --tree-only) TREE_ONLY=1; shift;;
    --container) CONTAINER="$2"; shift 2;;
    --ssh) SSH_HOST="$2"; shift 2;;
    --user) NC_USER="$2"; shift 2;;
    --collective) COLLECTIVE="$2"; shift 2;;
    --url) BASE_URL="$2"; shift 2;;
    --password) ADMIN_PASS="$2"; shift 2;;
    -h|--help) usage; exit 0;;
    *) echo "Okänd flagga: $1" >&2; usage; exit 1;;
  esac
done

log()  { printf '\033[36m[import]\033[0m %s\n' "$*"; }
warn() { printf '\033[33m[varn]\033[0m %s\n' "$*" >&2; }
run()  { if [[ $DRY_RUN -eq 1 ]]; then echo "  DRY: $*"; else eval "$@"; fi; }

# docker-anrop, ev. över ssh, med MSYS_NO_PATHCONV för Git Bash
dexec() {
  local inner="$*"
  if [[ -n "$SSH_HOST" ]]; then
    MSYS_NO_PATHCONV=1 ssh "$SSH_HOST" "docker exec -u www-data $CONTAINER $inner"
  else
    MSYS_NO_PATHCONV=1 docker exec -u www-data "$CONTAINER" bash -lc "$inner"
  fi
}
occ() { dexec "php occ $*"; }

# ---- 1. Bygg Collectives-format sidträd -----------------------------------
# Collectives-konvention:  Readme.md = sidans/mappens landningssida,
# leaf-sida = "Titel.md",  sida med undersidor = mapp "Titel/" med Readme.md.
build_tree() {
  log "Bygger sidträd i $BUILD_DIR"
  local prof="$BUILD_DIR/Socialsekreterare - barn och familj"
  if [[ $DRY_RUN -eq 1 ]]; then echo "  DRY: mkdir -p \"$prof\" + kopiera sidor"; return; fi
  rm -rf "$BUILD_DIR"; mkdir -p "$prof"

  # Collective-landning + biblioteksmeta-sidor
  cp "$LIB_DIR/README.md"                                  "$BUILD_DIR/Readme.md"
  cp "$LIB_DIR/ANALYS-dokumenttyper-socialsekreterare.md"  "$BUILD_DIR/Om biblioteket - analys.md"
  cp "$LIB_DIR/MALL-STANDARD.md"                           "$BUILD_DIR/Mallstandard.md"

  # Professionens landningssida
  cp "$SRC_PROF/00-oversikt.md" "$prof/Readme.md"

  # De 18 mallarna -> "NN Titel.md" (numret ger ordning; titel ur H1)
  local f base title
  for f in "$SRC_PROF"/[0-9][0-9]-*.md; do
    base="$(basename "$f")"
    [[ "$base" == 00-oversikt.md ]] && continue
    num="${base%%-*}"
    # Titel = första H1-raden utan '# '
    title="$(grep -m1 '^# ' "$f" | sed 's/^# //')"
    [[ -z "$title" ]] && title="${base%.md}"
    # rensa tecken som är olämpliga i filnamn
    title="$(printf '%s' "$title" | tr '/:' '--')"
    cp "$f" "$prof/$num $title.md"
  done
  log "Sidträd klart: $(find "$BUILD_DIR" -name '*.md' | wc -l) sidor."
}

# ---- 2. Aktivera appar ----------------------------------------------------
enable_apps() {
  log "Aktiverar circles + collectives"
  run "occ app:enable circles || occ app:install circles || true"
  run "occ app:enable collectives || occ app:install collectives || true"
}

# ---- 3. Skapa collective via REST-API -------------------------------------
create_collective() {
  log "Skapar collective \"$COLLECTIVE\" (om det inte finns)"
  local api="$BASE_URL/index.php/apps/collectives/api/v1.0/collectives"
  local curl_cmd="curl -sS -u '$NC_USER:$ADMIN_PASS' -H 'OCS-APIRequest: true' -H 'Content-Type: application/json'"
  if [[ $DRY_RUN -eq 1 ]]; then
    echo "  DRY: POST $api  {\"name\":\"$COLLECTIVE\"}"
    return
  fi
  # körs inifrån containern så localhost:8080 stämmer
  local resp
  resp="$(dexec "$curl_cmd -X POST '$api' --data '{\"name\":\"$COLLECTIVE\"}'" || true)"
  if echo "$resp" | grep -qi 'error\|"data":\[\]\|already'; then
    warn "API-svar: $resp"
    warn "Kunde inte bekräfta skapande via API. Skapa collective:t \"$COLLECTIVE\" manuellt i Collectives-appen och kör om med --tree-only + manuell import, eller fortsätt — steg 4 letar ändå upp mappen."
  else
    log "Collective skapat/finns."
  fi
}

# ---- 4. Hitta collective-mappen i containern och lägg in träden ------------
inject_tree() {
  log "Letar upp collective-mappen i containern"
  local found
  found="$(dexec "find /var/www/html/data -type d -name '$COLLECTIVE' 2>/dev/null | head -1" || true)"
  if [[ -z "$found" ]]; then
    warn "Hittade ingen mapp med namnet \"$COLLECTIVE\" i containerns datakatalog."
    warn "Skapa collective:t i appen först, eller importera build/collective-tree/ manuellt via Filer/WebDAV."
    return 1
  fi
  log "Collective-mapp: $found"
  local scan_path="${found#/var/www/html/data/}"

  if [[ $DRY_RUN -eq 1 ]]; then
    echo "  DRY: kopiera $BUILD_DIR/* -> container:$found ; occ files:scan --path=\"$scan_path\""
    return
  fi

  # kopiera sidträdets innehåll in i collective-mappen (icke-destruktivt: skriver över Readme.md, lägger till sidor)
  local tmp="/tmp/hubs-mallar-$$"
  if [[ -n "$SSH_HOST" ]]; then
    tar -C "$BUILD_DIR" -cf - . | ssh "$SSH_HOST" "cat > $tmp.tar && docker cp $tmp.tar $CONTAINER:$tmp.tar && docker exec -u www-data $CONTAINER bash -lc 'mkdir -p $tmp && tar -C $tmp -xf $tmp.tar && cp -r $tmp/. \"$found/\" && rm -rf $tmp $tmp.tar'"
  else
    MSYS_NO_PATHCONV=1 tar -C "$BUILD_DIR" -cf - . | MSYS_NO_PATHCONV=1 docker cp - "$CONTAINER:$found/"
  fi
  log "Sidor inlagda. Skannar filer..."
  occ "files:scan --path=\"$scan_path\""
  log "Klart. Öppna Collectives-appen -> \"$COLLECTIVE\" (handboken/skyltfönstret)."
  log "De ifyllbara .docx-mallarna: kör build-docx.sh + setup-template-folder.sh."
}

# ---- main ------------------------------------------------------------------
build_tree
if [[ $TREE_ONLY -eq 1 ]]; then
  log "--tree-only: hoppar över container-steg. Sidträd: $BUILD_DIR"
  exit 0
fi
enable_apps
create_collective
inject_tree || warn "Injektionssteget kunde inte slutföras automatiskt — se meddelanden ovan; sidträdet finns i $BUILD_DIR för manuell import."
