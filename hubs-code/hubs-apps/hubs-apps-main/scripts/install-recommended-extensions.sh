#!/usr/bin/env bash
# Silently install extensions.recommendations from hubs-apps.code-workspace
# into vscode-server (no on-attach "click Install" prompt). Called from
# `make ide-up` after workspace.sh + wait-for-vscode-server.sh.
# Must run via `$(DC) exec dev-builder`, never dc-run.sh: needs
# /home/developer/.vscode-server/ from the dev-builder-home named volume,
# unreachable from ephemeral one-shots.

set -euo pipefail

ws=/platform/hubs-apps.code-workspace
[ -f "$ws" ] || { echo "ERROR: $ws not found" >&2; exit 1; }

# nullglob -> empty array if no code-server binary. wait-for-vscode-server.sh
# guarantees the binary is present before ide-up reaches here; an empty
# array means that contract is broken (or the wait was skipped) — crash loud.
shopt -s nullglob
cli_candidates=(/home/developer/.vscode-server/bin/*/bin/code-server)
shopt -u nullglob
if [ "${#cli_candidates[@]}" -eq 0 ]; then
    echo "ERROR: no code-server CLI found under /home/developer/.vscode-server/bin/*/bin/code-server." >&2
    echo "  Expected wait-for-vscode-server.sh to have gated ide-up on a running vscode-server — re-run \`make ide-up\` from host." >&2
    exit 1
fi
cli="${cli_candidates[0]}"

# Command substitution, not process substitution: `while read < <(jq ...)`
# can't propagate jq's RC to `set -e`, so malformed workspace JSON would
# exit 0 with a false "0 installed, 0 skipped" success.
ext_list=$(jq -r '.extensions.recommendations[]' "$ws")

installed=0; skipped=0
while IFS= read -r ext; do
    [ -n "$ext" ] || continue
    # VSCode lowercases extension dirs (`Vue.volar` -> `vue.volar-X.Y.Z-...`),
    # so lowercase the ID for the filesystem check. Install still uses the
    # original casing (CLI's marketplace lookup is case-insensitive).
    ext_lower="${ext,,}"
    if compgen -G "/home/developer/.vscode-server/extensions/${ext_lower}-*" > /dev/null; then
        skipped=$((skipped + 1))
        continue
    fi
    echo "    installing $ext..."
    "$cli" --install-extension "$ext" >/dev/null
    installed=$((installed + 1))
done <<< "$ext_list"
echo "    extensions: ${installed} installed, ${skipped} already present"
