#!/usr/bin/env bash
# =============================================================================
# build-docx.sh
#
# Genererar .docx-dokumentmallar ur Markdown-källorna (per profession) till
#   mallbibliotek/Mallar/<Profession>/NN Titel.docx
#
# Dessa .docx är de RIKTIGA mallarna som läggs i Nextclouds mallmapp (Filer ->
# + Ny -> Ny fil från mall). Markdown-filerna är källan/handboken.
#
# Kräver pandoc (https://pandoc.org). Ange sökväg med --pandoc eller env PANDOC,
# annars söks pandoc i PATH.
#   Portabel pandoc (ingen systeminstallation):
#     ladda ned pandoc-*-windows-x86_64.zip, packa upp, peka ut pandoc.exe.
# =============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LIB_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
OUT_ROOT="$LIB_DIR/Mallar"
PANDOC="${PANDOC:-}"
DRY_RUN=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --pandoc) PANDOC="$2"; shift 2;;
    --out) OUT_ROOT="$2"; shift 2;;
    --dry-run) DRY_RUN=1; shift;;
    -h|--help) sed -n '2,17p' "$0"; exit 0;;
    *) echo "Okänd flagga: $1" >&2; exit 1;;
  esac
done

[[ -z "$PANDOC" ]] && PANDOC="$(command -v pandoc || true)"
if [[ -z "$PANDOC" ]]; then
  echo "FEL: pandoc hittades inte. Installera pandoc eller ange --pandoc /sökväg/pandoc(.exe)." >&2
  exit 1
fi

log(){ printf '\033[36m[docx]\033[0m %s\n' "$*"; }

# profession-katalog -> visningsnamn för mappen i Mallar/
label_for() {
  case "$1" in
    socialsekreterare-barn-familj) echo "Socialsekreterare - barn och familj";;
    *) echo "$1";;
  esac
}

TMP="$(mktemp)"; trap 'rm -f "$TMP"' EXIT
count=0
for profdir in "$LIB_DIR"/*/; do
  prof="$(basename "$profdir")"
  # bara kataloger med numrerade mallkällor
  ls "$profdir"[0-9][0-9]-*.md >/dev/null 2>&1 || continue
  label="$(label_for "$prof")"
  out="$OUT_ROOT/$label"
  log "Profession: $prof -> \"$out\""
  [[ $DRY_RUN -eq 0 ]] && mkdir -p "$out"
  for f in "$profdir"[0-9][0-9]-*.md; do
    base="$(basename "$f")"; [[ "$base" == 00-oversikt.md ]] && continue
    num="${base%%-*}"
    title="$(grep -m1 '^# ' "$f" | sed 's/^# //' | tr '/:' '--')"
    [[ -z "$title" ]] && title="${base%.md}"
    target="$out/$num $title.docx"
    if [[ $DRY_RUN -eq 1 ]]; then echo "  DRY: $base -> $num $title.docx"; count=$((count+1)); continue; fi
    # strip YAML-frontmatter + ta bort GitHub-callout-markörer ([!NOTE] osv.)
    awk 'NR==1&&/^---[[:space:]]*$/{inf=1;next} inf&&/^---[[:space:]]*$/{inf=0;next} !inf{print}' "$f" \
      | sed -E 's/\[!(NOTE|IMPORTANT|TIP|WARNING|CAUTION)\][[:space:]]*//' > "$TMP"
    # -smart: behåll RAKA citattecken (typografiska “” bryter DocxFyllningsMotor:s
    # strängexakta platshållar-matchning — buggen hittad 2026-07-07).
    "$PANDOC" -f markdown+task_lists-smart -t docx "$TMP" -o "$target"
    count=$((count+1))
  done
done
log "Klart: $count .docx under $OUT_ROOT"

# ---- Myndighets-styling (rutor + blå 8pt-handledning) ---------------------
# Kör restyle-docx.php på hela utkatalogen via docker composer:2 (ingen PHP
# behövs på hosten). Idempotent — en omkörning stylar inte om.
if [[ $DRY_RUN -eq 0 ]] && command -v docker >/dev/null 2>&1; then
  log "Blankett-transform (tabeller + dold token + ingress + sidhuvud/sidfot) + malldefinitioner"
  MSYS_NO_PATHCONV=1 docker run --rm \
    -v "$LIB_DIR:/mall" -w /mall composer:2 \
    bash -c "php scripts/restyle-docx.php '/mall/$(basename "$OUT_ROOT")' \
      && php scripts/generera-malldefinitioner.php '/mall/$(basename "$OUT_ROOT")'"
else
  [[ $DRY_RUN -eq 0 ]] && log "VARNING: docker saknas — hoppar blankett-transformen"
fi
