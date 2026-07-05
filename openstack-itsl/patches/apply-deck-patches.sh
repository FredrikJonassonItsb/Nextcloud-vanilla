#!/usr/bin/env bash
#
# apply-deck-patches.sh — apply ITSL's local Deck patches. IDEMPOTENT.
# MUST be re-run after every Deck app update (upgrades overwrite custom_apps/deck).
#
# Runs from the Windows host over ssh (default) or directly on the server.
#   patches/apply-deck-patches.sh
#   HUBS_SSH="ubuntu@10.43.51.62" patches/apply-deck-patches.sh
#   ON_SERVER=1 bash apply-deck-patches.sh          # when already on the server
#
# See patches/README.md for what each patch fixes.
set -euo pipefail

SSH_TARGET="${HUBS_SSH:-ubuntu@10.43.51.62}"

run_remote() {
  if [ "${ON_SERVER:-0}" = "1" ]; then
    bash -c "$1"
  else
    # bash -s via stdin — the remote login shell may be dash; force bash.
    ssh -o BatchMode=yes -o ConnectTimeout=15 "$SSH_TARGET" bash -s <<<"$1"
  fi
}

# The remote routine is a self-contained bash script (quoted heredoc → no local
# expansion). It patches AssignmentService via php (str_replace), so there is no
# fragile shell/sed escaping of PHP source.
read -r -d '' REMOTE_SCRIPT <<'REMOTE' || true
set -euo pipefail
CONTAINER=hubs-php
F=/var/www/html/custom_apps/deck/lib/Service/AssignmentService.php

occ_php() { sudo docker exec "$CONTAINER" php "$@"; }

echo "→ Patch 1: deck-numeric-uid-assignuser"
if ! sudo docker exec "$CONTAINER" test -f "$F"; then
  echo "  ! $F saknas — är Deck installerad? Hoppar över."; exit 0
fi
if sudo docker exec "$CONTAINER" grep -q "array_map('strval', \$boardUsers)" "$F"; then
  echo "  = redan patchad"; exit 0
fi
# backup once
sudo docker exec "$CONTAINER" sh -c "[ -f '$F.itsl-bak' ] || cp '$F' '$F.itsl-bak'"
# apply via php str_replace (exact literal match)
sudo docker exec "$CONTAINER" php -r '
  $f = $argv[1];
  $s = file_get_contents($f);
  $old = "if (!in_array(\$userId, \$boardUsers, true) && count(\$groups) !== 1) {";
  $new = "if (!in_array((string)\$userId, array_map(\"strval\", \$boardUsers), true) && count(\$groups) !== 1) {";
  if (strpos($s, $old) === false) {
    fwrite(STDERR, "  ! patch-monstret hittades inte (Deck-version andrad?) — ingen andring\n");
    exit(2);
  }
  file_put_contents($f, str_replace($old, $new, $s));
' "$F"
# lint
if sudo docker exec "$CONTAINER" php -l "$F" >/dev/null 2>&1; then
  sudo docker exec "$CONTAINER" chown www-data:www-data "$F"
  echo "  + applicerad + php -l OK"
else
  echo "  !! php -l FEL efter patch — återställer backup"
  sudo docker exec "$CONTAINER" cp "$F.itsl-bak" "$F"
  exit 1
fi
REMOTE

run_remote "$REMOTE_SCRIPT"
echo "✓ Deck-patchar klara. (opcache plockar upp ändringen; ingen restart av hubs-php krävs.)"
