#!/usr/bin/env bash
# setup-laptop.sh — ITSL Open Stack laptop onboarding (BYGGPLAN M2).
#
# Target environment: Git Bash on Windows (also works on macOS/Linux bash).
#
# What it does (idempotent — safe to re-run):
#   1. Verifies the claude CLI is installed.
#   2. Installs the core skills to ~/.claude/skills/<name>/SKILL.md,
#      substituting {{AGENT_CODE}} (and card ids, if provided).
#   3. Writes the personal identity block into ~/.claude/CLAUDE.md
#      BETWEEN markers — existing content outside the markers is never touched.
#   4. Tests brain MCP reachability (own brain + team brain) via curl.
#   5. Prints Swedish next steps, incl. the exact `claude mcp add` commands.
#
# Usage:
#   ./setup-laptop.sh <reb|atlas|ada|marvin>
#
# Optional env (card ids from stack/state/bootstrap.json on dev15; if unset the
# {{...}} placeholders stay in the installed skill and must be filled manually):
#   AE_SETUP_CARD, AE_LEDGER_CARD, AE_ROUTING_CARD, AE_CATALOG_CARD
#
# NO secrets are handled by this script. Brain keys and NC app passwords live in
# the person's own password manager / keychain — never in files, never in git.

set -euo pipefail

# --- constants (per CONTRACTS.md — do not change names/ports) -----------------
NC_BASE="${NC_BASE:-https://dev15.hubs.se}"
BRAIN_BASE="${BRAIN_BASE:-https://dev15.hubs.se:8843}"
CORE_SKILLS=(open-agent-engine brain-recall deck-conventions itsl-guardrails)
MARK_BEGIN="<!-- >>> itsl-openstack identity (managed by setup-laptop.sh) >>> -->"
MARK_END="<!-- <<< itsl-openstack identity <<< -->"

# --- helpers -------------------------------------------------------------------
say()  { printf '%s\n' "$*"; }
ok()   { printf '  [OK]   %s\n' "$*"; }
warn() { printf '  [VARN] %s\n' "$*"; }
die()  { printf '  [FEL]  %s\n' "$*" >&2; exit 1; }

# --- 0. argument ---------------------------------------------------------------
NAME="${1:-}"
case "$NAME" in
  reb)    AGENT_CODE="reb-claude";    KEYVAR="BRAIN_KEY_REB";    HUMAN="Rebecca (rebecca)"; ROOM="Reb minne" ;;
  atlas)  AGENT_CODE="atlas-claude";  KEYVAR="BRAIN_KEY_ATLAS";  HUMAN="Fredrik (fredrik)"; ROOM="Atlas minne" ;;
  ada)    AGENT_CODE="ada-claude";    KEYVAR="BRAIN_KEY_ADA";    HUMAN="Sandra (sandra)";   ROOM="Ada minne" ;;
  marvin) AGENT_CODE="marvin-claude"; KEYVAR="BRAIN_KEY_MARVIN"; HUMAN="Mattias (mattias)"; ROOM="Marvin minne" ;;
  *) die "Användning: $0 <reb|atlas|ada|marvin>" ;;
esac

# Card ids: use env values when provided, otherwise keep the literal placeholders.
SETUP_ID="${AE_SETUP_CARD:-}";     [ -n "$SETUP_ID" ]   || SETUP_ID='{{SETUP_CARD_ID}}'
LEDGER_ID="${AE_LEDGER_CARD:-}";   [ -n "$LEDGER_ID" ]  || LEDGER_ID='{{LEDGER_CARD_ID}}'
ROUTING_ID="${AE_ROUTING_CARD:-}"; [ -n "$ROUTING_ID" ] || ROUTING_ID='{{ROUTING_CARD_ID}}'
CATALOG_ID="${AE_CATALOG_CARD:-}"; [ -n "$CATALOG_ID" ] || CATALOG_ID='{{CATALOG_CARD_ID}}'

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CLAUDE_DIR="${HOME}/.claude"
SKILLS_DIR="${CLAUDE_DIR}/skills"
CLAUDE_MD="${CLAUDE_DIR}/CLAUDE.md"
IDENTITY_SRC="${SCRIPT_DIR}/per-person/${NAME}/CLAUDE-identity.md"

say ""
say "=== ITSL Open Stack — laptop-setup för ${NAME} (${AGENT_CODE}) ==="
say ""

# Environment sanity (informational only — the script is plain POSIX-ish bash).
case "$(uname -s 2>/dev/null || echo unknown)" in
  MINGW*|MSYS*|CYGWIN*) ok "Git Bash/MSYS-miljö upptäckt." ;;
  *) warn "Inte Git Bash — fortsätter ändå (fungerar på macOS/Linux)." ;;
esac

[ -f "$IDENTITY_SRC" ] || die "Hittar inte identitetsfilen: $IDENTITY_SRC"

# --- 1. claude CLI ---------------------------------------------------------------
if command -v claude >/dev/null 2>&1; then
  ok "claude CLI hittad: $(claude --version 2>/dev/null | head -n1 || echo 'version okänd')"
else
  die "claude CLI saknas. Installera Claude Code först: https://claude.com/claude-code (npm install -g @anthropic-ai/claude-code) och kör om skriptet."
fi

# --- 2. install core skills ------------------------------------------------------
mkdir -p "$SKILLS_DIR"
for skill in "${CORE_SKILLS[@]}"; do
  src="${SCRIPT_DIR}/core/${skill}/SKILL.md"
  dst_dir="${SKILLS_DIR}/${skill}"
  [ -f "$src" ] || die "Källfil saknas: $src (kör skriptet från en komplett utcheckning)"
  mkdir -p "$dst_dir"
  # Substitute agent code; card ids only when provided via env.
  sed \
    -e "s/{{AGENT_CODE}}/${AGENT_CODE}/g" \
    -e "s/{{SETUP_CARD_ID}}/${SETUP_ID}/g" \
    -e "s/{{LEDGER_CARD_ID}}/${LEDGER_ID}/g" \
    -e "s/{{ROUTING_CARD_ID}}/${ROUTING_ID}/g" \
    -e "s/{{CATALOG_CARD_ID}}/${CATALOG_ID}/g" \
    "$src" > "${dst_dir}/SKILL.md"
  ok "Skill installerad: ${dst_dir}/SKILL.md"
done
if [ -z "${AE_LEDGER_CARD:-}" ]; then
  warn "Kort-id:n (AE_SETUP_CARD m.fl.) ej angivna — {{...}}-platshållare kvar i open-agent-engine/SKILL.md. Hämta id:n ur stack/state/bootstrap.json på dev15 och kör om, eller fyll i för hand."
fi

# --- 3. identity block in ~/.claude/CLAUDE.md (idempotent, between markers) ------
mkdir -p "$CLAUDE_DIR"
touch "$CLAUDE_MD"
TMP_FILE="$(mktemp)"
# Drop any existing managed block (inclusive of markers), keep everything else,
# and trim trailing blank lines so re-runs never grow the file.
awk -v b="$MARK_BEGIN" -v e="$MARK_END" '
  index($0, b) == 1 { inblock = 1; next }
  index($0, e) == 1 { inblock = 0; next }
  !inblock { lines[++n] = $0; if ($0 != "") last = n }
  END { for (i = 1; i <= last; i++) print lines[i] }
' "$CLAUDE_MD" > "$TMP_FILE"
{
  cat "$TMP_FILE"
  [ -s "$TMP_FILE" ] && printf '\n'
  printf '%s\n' "$MARK_BEGIN"
  cat "$IDENTITY_SRC"
  printf '%s\n' "$MARK_END"
} > "$CLAUDE_MD"
rm -f "$TMP_FILE"
ok "Identitetsblock skrivet mellan markörer i ${CLAUDE_MD}"

# --- 4. brain MCP reachability ---------------------------------------------------
check_brain() {
  # $1 = path segment (e.g. "reb" or "team"); prints status. Never fails the script.
  local seg="$1" url code
  url="${BRAIN_BASE}/${seg}/healthz"
  code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 10 "$url" 2>/dev/null || true)"
  if [ -z "$code" ] || [ "$code" = "000" ]; then
    # Retry accepting the fallback internal CA (CONTRACTS §4: caddy internal CA fallback).
    code="$(curl -sSk -o /dev/null -w '%{http_code}' --max-time 10 "$url" 2>/dev/null || true)"
    if [ -n "$code" ] && [ "$code" != "000" ]; then
      warn "Hjärnan '${seg}' nås endast med -k (intern CA?) — HTTP ${code} på ${url}. Be Fredrik om CA-filen."
      return 0
    fi
    warn "Hjärnan '${seg}' nås INTE (${url}). Kontrollera VPN/nät och att stacken kör på dev15."
    return 0
  fi
  case "$code" in
    200)        ok "Hjärnan '${seg}' svarar (HTTP 200) på ${url}" ;;
    401|403)    ok "Hjärnan '${seg}' nåbar (HTTP ${code} — auth krävs, väntat utan nyckel)" ;;
    *)          warn "Hjärnan '${seg}' svarade HTTP ${code} på ${url} — undersök." ;;
  esac
}
say ""
say "--- Nåbarhetstest mot hjärnorna (utan nycklar) ---"
check_brain "$NAME"
check_brain "team"

# --- 5. Swedish next steps -------------------------------------------------------
say ""
say "=== KLART. Nästa steg (görs av dig, ${HUMAN%% *}): ==="
say ""
say "1. Hämta dina två hjärnnycklar ur lösenordshanteraren: ${KEYVAR} och BRAIN_KEY_TEAM."
say "   (Fredrik delar dem säkert — aldrig via mail/Talk i klartext.)"
say ""
say "2. Anslut hjärnorna till Claude Code (klistra in nyckelvärdet i stället för <nyckel>):"
say ""
say "   claude mcp add --transport http brain-${NAME} ${BRAIN_BASE}/${NAME}/mcp --header \"Authorization: Bearer <${KEYVAR}>\""
say "   claude mcp add --transport http brain-team ${BRAIN_BASE}/team/mcp --header \"Authorization: Bearer <BRAIN_KEY_TEAM>\""
say ""
say "3. Skapa ett personligt NC-app-lösenord på ${NC_BASE} (Inställningar → Säkerhet →"
say "   'Skapa nytt applösenord', döp det 'agent-engine-laptop') och spara det i din"
say "   lösenordshanterare. Det används när du postar som dig själv (t.ex. AGENT HUMAN ANSWERED)."
say ""
say "4. Verifiera i en ny Claude Code-session:"
say "   - 'Spara en testtanke i min hjärna' → capture_thought via brain-${NAME}."
say "   - 'Sök i min hjärna efter testtanke' → search_thoughts hittar den."
say "   - 'Sök i teamhjärnan' → brain-team svarar (och din kollegas privata tankar syns INTE)."
say ""
say "5. Capture från mobilen: skriv i Talk-rummet '${ROOM}' — du får"
say "   en trådad bekräftelse när tanken sparats. '!queue <text>' skapar ett Inbox-kort,"
say "   '!status' ger din digest."
say ""
say "6. Läge M5+: be din agent 'kolla kön' — skillen open-agent-engine styr protokollet."
say "   Kom ihåg: itsl-guardrails gäller alltid (ingen PII i hjärnor/kort, deploy är"
say "   alltid människogrindad)."
say ""
