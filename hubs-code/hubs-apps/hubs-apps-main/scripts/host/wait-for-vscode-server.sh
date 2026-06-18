#!/usr/bin/env bash
# Poll for a running vscode-server PROCESS inside the dev-builder
# container, used by `make ide-up` after host VSCode attaches. First
# attach on a wiped dev-builder-home volume installs vscode-server first
# (~30-60s); hence the generous TIMEOUT.
#
# Probe shape — MUST stay process-presence, not binary-presence: probe
# for a process whose /proc/<pid>/exe resolves under .vscode-server/.
# The binary persists in the dev-builder-home volume across restarts, so
# a filesystem-presence check passes on every later invocation even with
# no server running — masking broken-attach as success.
#
# Args:  $1 — container name (required).
# Exits 0 on success, 1 on timeout.

set -euo pipefail

CONTAINER="${1:?usage: $0 <container-name>}"
# Fixed, not env-overridable: nothing tunes these, and the poll loop's
# arithmetic ([ -lt ], $((elapsed + INTERVAL))) is integer-only — a
# fractional override would crash mid-loop. Bump the literals if the
# vscode-server install ever outgrows the window.
TIMEOUT=120
INTERVAL=1

# Inner `readlink ... 2>/dev/null`: /proc/<pid>/exe for other-UID pids
# returns EACCES (normal — skip them). Outer docker exec is NOT
# stderr-suppressed: real failures (container gone, daemon unreachable)
# must surface, not hide as a 120s timeout.
# Inner echoes the process COUNT and always exits 0, so docker exec's rc
# means only "could the exec run". Do NOT fold n>0 into the inner exit:
# that makes a vanished container indistinguishable from "ran, found 0",
# burning the full TIMEOUT on a dead container before a misleading
# "didn't appear". Separating the two aborts a real exec failure at once.
probe() {
    docker exec "$CONTAINER" sh -c '
        n=0
        for pid in /proc/[0-9]*; do
            exe=$(readlink "$pid/exe" 2>/dev/null) || continue
            case "$exe" in
                */.vscode-server/*) n=$((n+1)) ;;
            esac
        done
        echo "$n"
    '
}

elapsed=0
while [ "$elapsed" -lt "$TIMEOUT" ]; do
    if count="$(probe)"; then
        if [ "${count:-0}" -gt 0 ]; then
            # Silent on the already-running fast path; message only when
            # we actually waited.
            [ "$elapsed" -gt 0 ] && echo "    vscode-server appeared after ${elapsed}s"
            exit 0
        fi
    else
        # exec failed, not "not ready yet" — abort rather than retry.
        echo "ERROR: cannot probe ${CONTAINER} for vscode-server — docker exec failed (container vanished or daemon unreachable)." >&2
        exit 1
    fi
    if [ "$elapsed" -gt 0 ] && [ $((elapsed % 5)) -eq 0 ]; then
        printf '    still waiting... (%ds elapsed)\n' "$elapsed"
    fi
    sleep "$INTERVAL"
    elapsed=$((elapsed + INTERVAL))
done

echo "ERROR: vscode-server didn't appear in ${TIMEOUT}s." >&2
echo "  Likely: host VSCode didn't attach to ${CONTAINER}, or Dev Containers extension is missing/broken on host." >&2
echo "  Check the host VSCode window for an attach error; retry \`make ide-up\` once attached." >&2
exit 1
