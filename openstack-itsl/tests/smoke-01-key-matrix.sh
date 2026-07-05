#!/usr/bin/env bash
# =============================================================================
# smoke-01-key-matrix.sh — nyckel-isolationsmatrisen 5×5 (CONTRACTS §9)
#
# Varje BRAIN_KEY provas mot varje /brain-endpoint via https://dev15.hubs.se:8843.
# Exakt DIAGONALEN ska lyckas (rätt nyckel mot rätt hjärna → 200), alla andra
# kombinationer ska nekas (401/403). Utan auth → 401.
# =============================================================================
set -u
SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"
smoke_init "$@"

require_vars BRAIN_KEY_REB BRAIN_KEY_ATLAS BRAIN_KEY_ADA BRAIN_KEY_MARVIN BRAIN_KEY_TEAM

BRAINS=(reb atlas ada marvin team)

key_of() {
  local up
  up=$(printf '%s' "$1" | tr '[:lower:]' '[:upper:]')
  local var="BRAIN_KEY_$up"
  printf '%s' "${!var}"
}

log "${C_BOLD}smoke-01: 5×5-nyckelmatris mot $BRAIN_BASE${C_RESET}"

for brain in "${BRAINS[@]}"; do
  for keyname in "${BRAINS[@]}"; do
    brain_tools_list "$brain" "$(key_of "$keyname")"
    if [ "$brain" = "$keyname" ]; then
      if [ "$HTTP_STATUS" = 200 ]; then
        assert_contains "diagonal: nyckel $keyname → /$brain svarar med verktygslista" '"tools"' "$HTTP_BODY"
      else
        fail "diagonal: nyckel $keyname → /$brain ger 200" \
          "förväntade HTTP 200, fick $HTTP_STATUS: ${HTTP_BODY:0:200}"
      fi
    else
      assert_status_in "korsnyckel nekas: nyckel $keyname → /$brain" '401,403'
    fi
  done
done

# Utan auth → 401 (CONTRACTS §9)
for brain in "${BRAINS[@]}"; do
  brain_rpc_noauth "$brain" '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}'
  assert_status "utan auth: /$brain → 401" 401
done

summary
