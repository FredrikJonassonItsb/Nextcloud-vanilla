#!/usr/bin/env bash
set -euo pipefail

# teardown-guard.sh <EPHEMERALS-flag>
#
# Gate for `make down` / `make clean` / `make distclean`: refuse to tear down
# while a .build/ operation is in flight, unless the caller passes EPHEMERALS=1.
# A teardown removes the build-pool (killing any pool op) and, for clean/
# distclean, wipes volumes / .dist/ — so barging over an in-flight op silently
# is exactly the foot-gun the refuse exists to stop.
#
# "In flight" is two distinct signals because the two op classes look different:
#   - a RUNNING one-off dev-builder container — any EPHEMERAL=1 op (assemble
#     MODE=force, deps, security-update, setup, new-app, build/package
#     MODE=production). Detected host-side by the compose oneoff label.
#   - a HELD apps/<app>/.build/.lock — catches a build-pool op (plain assemble,
#     dev build, quilt, diff): those run as `docker exec` inside the long-
#     running build-pool, not as their own container, so the lock is the only
#     signal. (It also sees a one-off's lock — the probe can't tell who holds
#     it — but a one-off already shows above; a pool op shows ONLY here.)
#     It's probed with fcntl from inside the pool: the host has no flock(1)
#     (host floor), and the lock is held from inside a container against the
#     bind-mounted file, so the test must run container-side too. The kernel
#     VFS lock spans both, so the pool sees a one-off's lock as well.
#
# With EPHEMERALS=1 the running one-offs are reaped here; any pool op then dies
# when the recipe's `$(DC) down` removes the build-pool a moment later. Without
# it, refuse and name exactly what's running.
#
# Crash-fast — no error hiding: the probes never assume "nothing in flight" on a
# failed docker call. A docker error (daemon unreachable, exec failure, compose
# parse error) surfaces and aborts the teardown via set -e. Refusing to tear
# down on an UNKNOWN state is the safe default for a gate whose entire job is to
# know that state; the only thing that lets a teardown proceed is a probe that
# SUCCEEDS and reports nothing running.

PLATFORM_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
EPHEMERALS="${1:-}"
# Project name as compose resolves it (make exports COMPOSE_PROJECT_NAME from
# .env; basename fallback for direct invocation), so the label filters scope to
# this project's containers.
PROJECT="${COMPOSE_PROJECT_NAME:-$(basename "$PLATFORM_ROOT")}"

# compose.dev-builder.yml (loaded below for the build-pool probe) interpolates
# ${HOST_PROJECT_DIR:?} and ${DEV_BUILDER_VERSION:?} at parse time, so both must
# be set or `compose ps` aborts. make exports them; these fallbacks cover direct
# invocation (same pattern as status.sh / dc-run.sh). Without them the pre-fix
# probe's swallowed compose error silently skipped the pool-lock check entirely.
export HOST_PROJECT_DIR="${HOST_PROJECT_DIR:-$PLATFORM_ROOT}"
export DEV_BUILDER_VERSION="${DEV_BUILDER_VERSION:-$(cat "$PLATFORM_ROOT/docker/dev-builder/VERSION")}"

# Running one-off ephemerals in this project (compose oneoff=True label).
running_oneoffs() {
    docker ps --filter "label=com.docker.compose.project=$PROJECT" \
              --filter "label=com.docker.compose.oneoff=True" \
              --filter "status=running" --format '{{.Names}}'
}

# App names whose .build/.lock is held, probed from inside the running
# build-pool. Empty when the pool is down — no build-pool op can be running
# then. `compose ps -q` never starts the pool, so a teardown can't lazy-start
# it just to ask. `-p "$PROJECT"` pins the project the same way running_oneoffs
# scopes it (without it, compose derives the project from the -f file's docker/
# dir basename when COMPOSE_PROJECT_NAME is unset, and would miss the pool). No
# apostrophes in the python (it's inside single quotes).
held_locks() {
    local pool
    # Explicit exit, not bare propagation: held_locks runs inside a command
    # substitution (`held="$(held_locks)"`), and bash suppresses set -e for a
    # cmdsub-invoked function's NON-final commands — so a failed compose ps would
    # silently yield pool="" ("no pool, nothing held"), the exact skip this guard
    # must never do. The exit makes the subshell fail so the outer set -e catches
    # it. (The final docker exec propagates on its own — it's the last command.)
    pool="$(docker compose -p "$PROJECT" -f "$PLATFORM_ROOT/docker/compose.dev-builder.yml" ps -q build-pool)" \
        || { echo "ERROR: teardown-guard could not probe the build-pool — refusing to tear down on an unknown state." >&2; exit 1; }
    [ -n "$pool" ] || return 0
    docker exec "$pool" python3 -c '
import fcntl, glob, os
for p in sorted(glob.glob("/platform/apps/*/.build/.lock")):
    try:
        f = open(p)
    except FileNotFoundError:
        # Raced: the app + its .build/ were removed between glob and open. No
        # lock at this path, so nothing to report — a real state, not a hidden
        # error. Any other open error (e.g. PermissionError) is uncaught and
        # crashes the probe, which aborts the teardown (crash-fast).
        continue
    try:
        fcntl.flock(f, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        # EWOULDBLOCK only: a live holder. Any other OSError propagates and
        # crashes the probe rather than being miscounted as "free".
        print(p.split(os.sep)[3])
    finally:
        f.close()
'
}

eph="$(running_oneoffs)"
held="$(held_locks)"

if [ -z "$eph" ] && [ -z "$held" ]; then
    exit 0
fi

if [ "$EPHEMERALS" = "1" ]; then
    # Announce what's being overridden — EPHEMERALS=1 can take down a sibling's
    # in-flight install, so name it rather than reap silently (mirrors the
    # refuse message's shape).
    {
        echo "EPHEMERALS=1 — tearing down over in-flight .build/ op(s):"
        [ -z "$eph" ]  || echo "       one-off(s): $(printf '%s' "$eph" | tr '\n' ' ')"
        [ -z "$held" ] || echo "       .build/.lock held for: $(printf '%s' "$held" | tr '\n' ' ')"
    } >&2
    # Reap the running one-offs now; any pool op dies on the `$(DC) down` the
    # recipe runs next. -aq (not -q): also clears a stopped husk if one somehow
    # lingers. `docker rm -f` is idempotent on an already-gone container (exits
    # 0 — verified), so a one-off that AutoRemove swept between the listing and
    # the rm needs no special-casing; a genuine daemon failure still surfaces
    # (non-zero → set -e aborts the teardown rather than hiding it).
    ids="$(docker ps -aq --filter "label=com.docker.compose.project=$PROJECT" --filter "label=com.docker.compose.oneoff=True")"
    [ -z "$ids" ] || docker rm -f $ids >/dev/null
    exit 0
fi

{
    echo "ERROR: a .build/ operation is in flight — refusing to tear down."
    if [ -n "$eph" ]; then
        echo "       install one-off(s) running: $(printf '%s' "$eph" | tr '\n' ' ')"
    fi
    if [ -n "$held" ]; then
        echo "       .build/.lock held for: $(printf '%s' "$held" | tr '\n' ' ')"
    fi
    echo "       Wait for them to finish, or pass EPHEMERALS=1 to kill them and tear down anyway."
} >&2
exit 1
