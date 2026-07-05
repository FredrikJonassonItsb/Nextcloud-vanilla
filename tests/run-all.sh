#!/usr/bin/env bash
# =============================================================================
# run-all.sh — kör hela smoke-sviten (CONTRACTS §9)
#
#   ./run-all.sh                 kör smoke-01 → smoke-06
#   ./run-all.sh --with-runner   kör även smoke-07 + smoke-08 (kräver
#                                ANTHROPIC_API_KEY + RUNNER_ENABLED=1 på servern)
#   ./run-all.sh --from-server   hämta hemligheter ur serverns .env via ssh
#
# Exit-kod 0 endast om inget skript blev rött. Överhoppade (exit 3) räknas
# inte som fel men markeras gult i sammanfattningen.
# =============================================================================
set -u

SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)

if [ -t 1 ]; then
  C_RED=$'\e[31m'; C_GREEN=$'\e[32m'; C_YELLOW=$'\e[33m'; C_BOLD=$'\e[1m'; C_RESET=$'\e[0m'
else
  C_RED=''; C_GREEN=''; C_YELLOW=''; C_BOLD=''; C_RESET=''
fi

WITH_RUNNER=0
PASSTHRU=()
for arg in "$@"; do
  case "$arg" in
    --with-runner) WITH_RUNNER=1 ;;
    -h|--help)
      sed -n '2,12p' "$0"
      exit 0
      ;;
    *) PASSTHRU+=("$arg") ;;
  esac
done

SCRIPTS=(
  smoke-01-key-matrix.sh
  smoke-02-capture-roundtrip.sh
  smoke-03-claim-race.sh
  smoke-04-ledger-upsert.sh
  smoke-05-takeover.sh
  smoke-06-sync-loop.sh
)
if [ "$WITH_RUNNER" = 1 ]; then
  SCRIPTS+=(smoke-07-runner-hello.sh smoke-08-hostile-card.sh)
fi

RESULT_NAMES=()
RESULT_STATES=()
ANY_RED=0
START=$(date +%s)

for s in "${SCRIPTS[@]}"; do
  echo
  printf '%b\n' "${C_BOLD}=== kör $s ===${C_RESET}"
  if bash "$SCRIPT_DIR/$s" ${PASSTHRU[@]+"${PASSTHRU[@]}"}; then
    RESULT_NAMES+=("$s"); RESULT_STATES+=(GRON)
  else
    rc=$?
    if [ "$rc" -eq 3 ]; then
      RESULT_NAMES+=("$s"); RESULT_STATES+=(HOPPAD)
    else
      RESULT_NAMES+=("$s"); RESULT_STATES+=(ROD)
      ANY_RED=1
    fi
  fi
done

ELAPSED=$(($(date +%s) - START))
echo
printf '%b\n' "${C_BOLD}================= SMOKE-SVITEN — SAMMANFATTNING =================${C_RESET}"
printf '%-10s %s\n' 'STATUS' 'SKRIPT'
printf '%s\n' '------------------------------------------------------------------'
for i in "${!RESULT_NAMES[@]}"; do
  case "${RESULT_STATES[$i]}" in
    GRON)   printf '%b %s\n' "${C_GREEN}GRÖN   ${C_RESET}" "${RESULT_NAMES[$i]}" ;;
    HOPPAD) printf '%b %s\n' "${C_YELLOW}HOPPAD ${C_RESET}" "${RESULT_NAMES[$i]}" ;;
    ROD)    printf '%b %s\n' "${C_RED}RÖD    ${C_RESET}" "${RESULT_NAMES[$i]}" ;;
  esac
done
printf '%s\n' '------------------------------------------------------------------'
printf 'Tid: %d min %d s\n' $((ELAPSED / 60)) $((ELAPSED % 60))
if [ "$ANY_RED" = 1 ]; then
  printf '%b\n' "${C_RED}RÖTT — minst ett smoke-test föll. Gå ALDRIG vidare på rött (BYGGPLAN §9).${C_RESET}"
  exit 1
fi
printf '%b\n' "${C_GREEN}GRÖNT — hela sviten passerade.${C_RESET}"
exit 0
