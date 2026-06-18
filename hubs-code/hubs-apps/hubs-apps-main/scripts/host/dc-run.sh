#!/usr/bin/env bash
set -euo pipefail

# dc-run.sh — run a command in the dev-builder, routing between the
# long-running build-pool (default, ~100ms via docker exec) and a fresh
# ephemeral (EPHEMERAL=1, ~700ms via compose run --rm).
#
# Usage:
#   APP_ROOT=<path> [APP_NAME=<name>] bash scripts/host/dc-run.sh <cmd> [args...]
#   APP_ROOT=<path> EPHEMERAL=1 bash scripts/host/dc-run.sh <cmd> [args...]
#   bash scripts/host/dc-run.sh <cmd> [args...]   # platform-level (no per-app workdir)
#
# Why two paths: EPHEMERAL is for install-touching recipes that run
# third-party post-install code (assemble MODE=force, deps,
# security-update, setup, new-app, build/package MODE=production). The
# --rm'd fresh container is supply-chain isolation: a hostile post-install
# hook can't persist state into the next invocation. The pool serves
# benign recipes (assemble plain/discard/recover, dev-mode build/package,
# quilt, diff, ide-up tools) and is lazy-started on first call.
#
# Service config (image, build args, /platform ro mount, /platform/apps
# rw nested bind on the pool, IN_BUILDER + PLATFORM_ROOT env, user,
# default workdir) lives in docker/compose.dev-builder.yml. This script
# handles only per-call concerns: APP_ROOT validation, per-call
# workdir + APP env, routing short-circuit, TTY toggle, env forwarding.
#
# Compose auto-applies com.docker.compose.* labels for OrbStack/Lens UI
# grouping (Mac); no manual labels needed.
#
# Env forwarded by default: DEVELOPER_UID/GID, HOST_PROJECT_DIR, and
# DEV_BUILDER_VERSION — each consumed by a compose interpolation or bind
# source that compose evaluates across the WHOLE loaded YAML even when
# running one service (see each export below for the specific consumer).
# This is a dumb wrapper: NO env-var whitelist. Nothing else propagates
# automatically — a recipe needing a var inside the ephemeral forwards it
# explicitly via the `env(1)` shim in its positional args, e.g.:
#   bash dc-run.sh env MODE="$MODE" bash /platform/scripts/assemble.sh
# An allowlist here would be a second, drifting source of truth for which
# vars cross into the container; the explicit shim keeps that decision at
# each call site where the need is visible.
#
# EDITOR + GIT_EDITOR are the two exceptions, propagated automatically so
# interactive editor recipes work without a shim. Resolution lives
# authoritatively in the Makefile (fallback chain at parse time); this
# script is just the propagator, reading from its own process env. So:
#   - Make-invoked paths (daily workflow + tests via make) get the full
#     chain. Make deliberately bypasses host GIT_EDITOR: the Claude Code
#     harness sets GIT_EDITOR=true to suppress editor spawn, and leaking
#     that into containers would break interactive git commit/rebase from
#     the IDE-attach terminal.
#   - Direct `bash dc-run.sh` (no preceding make) gets whatever the
#     calling shell has — which COULD include a leaked harness
#     GIT_EDITOR=true. Niche; daily workflow is via make. Tests override
#     with positional `env EDITOR=...`.
# Both default to empty under set -u (see EDITOR/GIT_EDITOR block); when
# empty the conditional --env appends skip, leaving the container without
# EDITOR so quilt_v2's `os.environ["EDITOR"]` raises KeyError loudly
# rather than landing as an empty string.

PLATFORM_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

# HOST_PROJECT_DIR — absolute host path; bind sources in compose YAML
# and to_host_path() below need it. Make normally exports it; the
# PLATFORM_ROOT fallback covers direct invocation. Inside the IDE-attach
# container the env already carries it (from docker/compose.dev.yml) so
# the `:-` default doesn't fire. Exported before to_host_path's
# definition so it always reads a valid value regardless of caller order.
export HOST_PROJECT_DIR="${HOST_PROJECT_DIR:-$PLATFORM_ROOT}"

# DEV_BUILDER_VERSION — required because compose interpolates the
# `:?`-flagged image: line in docker/compose.dev-builder.yml at YAML-parse
# time (unset crashes). Make normally exports it from the VERSION file;
# reading the file directly is the direct-invocation fallback.
# NEXTCLOUD_IMAGE_VERSION / NODE_SIDECAR_VERSION are NOT exported: this
# script's compose call no longer loads compose.yml or any sidecar
# fragment, so those vars are never interpolated.
export DEV_BUILDER_VERSION="${DEV_BUILDER_VERSION:-$(cat "$PLATFORM_ROOT/docker/dev-builder/VERSION")}"

# COMPOSE_PROJECT_NAME — must be set explicitly: without it compose
# derives the name from the first -f file's parent dir (`docker/`, since
# the -f set is narrowed to compose.dev-builder.yml only), spawning
# `docker-dev-builder-run-...` ephemerals in the wrong project with no
# coordination with the `hubs-apps-*` long-running containers. Make
# normally exports it; the platform-root-basename fallback matches
# compose's pre-narrowing default and status.sh's same fallback.
export COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-$(basename "$PLATFORM_ROOT")}"

# Classic builder, not buildx — the plugin isn't on every host, and a
# buildx-less compose would try to pull our local-only dev-builder image (never
# pushed) and fail. The Dockerfiles use no BuildKit-only feature, so the classic
# builder (ships with every daemon) builds them. Make exports DOCKER_BUILDKIT=0;
# this re-exports for direct invocation (tests calling dc-run.sh without make).
export DOCKER_BUILDKIT="${DOCKER_BUILDKIT:-0}"

# Default to empty under set -u so the conditional `--env` appends below
# can reference $EDITOR / $GIT_EDITOR without crashing on unset. Rationale
# for the empty-then-skip-then-KeyError behavior is in the banner above.
EDITOR="${EDITOR:-}"
GIT_EDITOR="${GIT_EDITOR:-}"

# Translate container paths to host paths for use as --volume sources.
# When dc-run.sh runs inside the IDE-attach container and spawns an
# ephemeral sibling via the host docker socket, bind sources MUST be
# host-resolvable: the tree is at /platform there (bind from
# $HOST_PROJECT_DIR), but the host daemon only knows $HOST_PROJECT_DIR,
# and an unsanitised container path gets silently auto-created as an empty
# host dir (the bind-resolution foot-gun). /platform/X → $HOST_PROJECT_DIR/X;
# outside the IDE-attach container the input is already a host path, no-op.
to_host_path() {
    case "$1" in
        /platform|/platform/*) printf '%s\n' "${HOST_PROJECT_DIR}${1#/platform}" ;;
        *) printf '%s\n' "$1" ;;
    esac
}

APP_ROOT="${APP_ROOT:-}"
# Two parallel arg arrays, one per routing path. Pool path (docker exec):
# the build-pool already has wholesale /platform/apps:rw, so per-call adds
# are just workdir + APP env. Ephemeral path (compose run --rm): fresh
# container with no apps bind, so per-call adds the --volume mount too.
# EXEC_ARGS starts -i because docker exec defaults to no stdin attach
# (unlike compose run); RUN_ARGS stays empty until the per-call adds below.
RUN_ARGS=()
EXEC_ARGS=(-i)
if [ -n "$APP_ROOT" ]; then
    # Validate + absolutify: a missing or relative APP_ROOT used as a
    # --volume source makes docker silently create a named volume / empty
    # dir instead of erroring — the foot-gun the operator never wants.
    [ -d "$APP_ROOT" ] || { echo "ERROR: APP_ROOT is not a directory: $APP_ROOT" >&2; exit 1; }
    APP_ROOT="$(cd "$APP_ROOT" && pwd)"
    APP_NAME="${APP_NAME:-$(basename "$APP_ROOT")}"
    # BIND_APP_ROOT: host-resolvable form for the ephemeral --volume source.
    # APP_ROOT itself stays the container-or-host view (used for APP_NAME
    # basename and the in-container APP_ROOT env). No-op outside IDE-attach.
    BIND_APP_ROOT="$(to_host_path "$APP_ROOT")"
    RUN_ARGS+=(--volume "$BIND_APP_ROOT:/app" --workdir "/app" --env "APP_NAME=$APP_NAME" --env "APP_ROOT=/app")
    EXEC_ARGS+=(--workdir "/platform/apps/$APP_NAME" --env "APP_NAME=$APP_NAME" --env "APP_ROOT=/platform/apps/$APP_NAME")
else
    # Platform-level call (no APP_ROOT): explicit --workdir /platform for
    # the pool path matches the build-pool working_dir and pins cwd
    # regardless of docker-version defaults. The ephemeral path inherits
    # working_dir from the compose YAML.
    EXEC_ARGS+=(--workdir "/platform")
fi

# APPS_RW=1 — bind apps/ rw at /apps on the ephemeral path. `make new-app`
# writes apps/<NAME>/ before it exists, so per-app APP_ROOT can't point at
# it. new-app is install-touching (EPHEMERAL=1), so this only ever pairs
# with the ephemeral path; the pool already has /platform/apps:rw.
# Source host-translated, same reason as APP_ROOT above.
if [ -n "${APPS_RW:-}" ]; then
    RUN_ARGS+=(--volume "$(to_host_path "$PLATFORM_ROOT/apps"):/apps")
fi

# Forward EDITOR + GIT_EDITOR into both routing paths when non-empty.
# When empty these appends skip, leaving the container without EDITOR so
# quilt_v2 raises a clean KeyError rather than an empty-string EDITOR
# reaching shlex.split (rationale in banner).
if [ -n "$EDITOR" ]; then
    RUN_ARGS+=(--env "EDITOR=$EDITOR")
    EXEC_ARGS+=(--env "EDITOR=$EDITOR")
fi
if [ -n "$GIT_EDITOR" ]; then
    RUN_ARGS+=(--env "GIT_EDITOR=$GIT_EDITOR")
    EXEC_ARGS+=(--env "GIT_EDITOR=$GIT_EDITOR")
fi

# Without a positional command, `compose run … dev-builder` falls back to
# the image's (empty) default command and exits 0 silently.
[ "$#" -gt 0 ] || { echo "ERROR: no command given. Usage: APP_ROOT=… $0 <cmd> [args...]" >&2; exit 2; }

# Short-circuit: run in place when the work is already correctly isolated.
# Two cases:
#   1. Nested dc-run.sh inside an ephemeral one-shot (script chains like
#      assemble.sh → another script → dc-run.sh). Already in an isolated
#      container; re-spawning wastes one and breaks the bind layout.
#      Detected by IN_BUILDER=1 (set by docker/compose.dev-builder.yml)
#      AND IDE_ATTACH unset (set only by the IDE-attach overlay
#      docker/compose.dev.yml, which we don't load here).
#   2. CI runner — already a cimg/php:8.3-node container; nesting would be
#      docker-in-docker. Detected by CI (every provider sets it).
#
# NOT short-circuited (the case to watch): `make build` in the IDE-attach
# terminal, where IN_BUILDER=1 but IDE_ATTACH=1 too. The IDE-attach
# container has a bigger blast radius (wholesale apps:rw, docker socket,
# bound host SSH agent socket), so work must route to a fresh sibling
# ephemeral via the socket — same as from-host — not run in place. Falls
# through to the compose-run path below.
if { [ "${IN_BUILDER:-}" = "1" ] && [ "${IDE_ATTACH:-}" != "1" ]; } || [ -n "${CI:-}" ]; then
    exec "$@"
fi

# Forward DEVELOPER_UID/GID when make set them (id -u/id -g). When unset,
# compose's `:-1000` defaults apply downstream (compose.dev-builder.yml
# args + user:); a non-1000 host should run via make, not raw dc-run.sh.
export DEVELOPER_UID DEVELOPER_GID

cd "$PLATFORM_ROOT"
# Compose-scope: dev-builder ONLY. Do NOT add compose.yml (NC + postgres)
# back to the -f set: `compose run` pre-creates project-wide named volumes
# for every declared service even without starting them, so every dc-run.sh
# call (quilt, diff, build, …) would spuriously recreate
# hubs-apps_nextcloud_data / _config / postgres_data when wiped. The
# IGNORE_ORPHANS env var (not a wider -f set) is what suppresses the
# "Found orphan containers" warning, so the narrow -f set loses nothing.
export COMPOSE_IGNORE_ORPHANS=1

# Image-presence check, common to both paths (both need the image before
# `compose run` / `compose up build-pool`). Explicit `docker image inspect`
# instead of letting compose auto-build because compose's build progress
# lands on stdout, which corrupts callers that reserve stdout for the
# command's own output (e.g. `make webpack MODE=hmr` pipes compose.sh's
# stdout into a sidecar fragment). The build below redirects to stderr for
# the same reason. Fires only when the tag is absent: first clone, post
# `make distclean`, or after a VERSION bump (devs pull, build on next call).
#
# Build target is the `dev-builder` service, but either service would build
# the same shared hubs-apps/dev-builder:${VERSION} image build-pool also
# references; dev-builder is the canonical name.
#
# `${arr[@]+"${arr[@]}"}` (used at the exec calls below) — bash 3.2-safe
# (macOS default bash) empty-array expansion under set -u; the bare
# `"${arr[@]}"` errors "unbound variable" on bash 3.2 for a zero-element
# array (the platform-level call with both stdin and stdout TTYs hits this).
if ! docker image inspect "hubs-apps/dev-builder:${DEV_BUILDER_VERSION}" >/dev/null 2>&1; then
    echo "==> dev-builder image ${DEV_BUILDER_VERSION} not found; building (one-time per VERSION bump — future runs skip)" >&2
    docker compose \
        -f docker/compose.dev-builder.yml \
        build dev-builder >&2
fi

# Routing — EPHEMERAL=1 → fresh `compose run --rm`; default → docker exec
# into the long-running build-pool. Each branch handles its own TTY
# semantics (see per-branch notes) and the exec.
if [ "${EPHEMERAL:-}" = "1" ]; then
    # Ephemeral path. -T disables TTY allocation when not interactive;
    # without it `make quilt < /dev/null` (CI / piped) hangs on tty alloc.
    # In the interactive (TTY-allocated) branch, forward TERM — compose
    # doesn't by default, so the container sees TERM=dumb and curses tools
    # (nano for quilt's header edit, less/pager/vi) render broken. Forward
    # ONLY in this branch: under -T there's no terminal, and leaked terminal
    # sequences would corrupt captured output.
    if [ ! -t 0 ] || [ ! -t 1 ]; then
        RUN_ARGS+=(-T)
    elif [ -n "${TERM:-}" ]; then
        RUN_ARGS+=(--env "TERM=$TERM")
    fi

    exec docker compose \
        -f docker/compose.dev-builder.yml \
        run --rm \
        ${RUN_ARGS[@]+"${RUN_ARGS[@]}"} \
        dev-builder \
        "$@"
fi

# Pool path. docker exec defaults to no TTY (opposite of compose run), so
# allocate one when both stdin and stdout are ttys and forward TERM for
# curses rendering — same as the ephemeral branch, inverted default.
if [ -t 0 ] && [ -t 1 ]; then
    EXEC_ARGS+=(-t)
    [ -n "${TERM:-}" ] && EXEC_ARGS+=(--env "TERM=$TERM")
fi

# First-start check + image-bump auto-recycle. Three branches on pool state:
#   - absent (fresh clone / after any teardown — `make down` / clean /
#     distclean all run `$(DC) down`, which removes the pool) → up -d --wait.
#   - present but image stale (VERSION bumped, new tag built above, pool
#     still on old tag) → `rm -fs` + up to replace it against the new image.
#   - present, current → no-op, fall through to docker exec.
#
# `compose ps -q build-pool` gives the container ID (empty if not running).
# COMPOSE_PROJECT_NAME (exported above) lets it resolve via project label
# without a YAML parse. A real ps failure (daemon gone mid-script)
# propagates via set -e; the empty-output case threads into the
# `[ -z "$POOL_CID" ]` branches below for the up / recycle / race handling.
POOL_CID="$(docker compose -f docker/compose.dev-builder.yml ps -q build-pool)"
if [ -z "$POOL_CID" ]; then
    echo "==> bringing up build-pool (lazy first-start)" >&2
    docker compose \
        -f docker/compose.dev-builder.yml \
        up -d --wait build-pool >&2
    POOL_CID="$(docker compose -f docker/compose.dev-builder.yml ps -q build-pool)"
elif [ "$(docker inspect --format '{{.Config.Image}}' "$POOL_CID")" \
       != "hubs-apps/dev-builder:${DEV_BUILDER_VERSION}" ]; then
    echo "==> recycling build-pool for new dev-builder image (${DEV_BUILDER_VERSION})" >&2
    docker compose \
        -f docker/compose.dev-builder.yml \
        rm -fs build-pool >&2
    docker compose \
        -f docker/compose.dev-builder.yml \
        up -d --wait build-pool >&2
    POOL_CID="$(docker compose -f docker/compose.dev-builder.yml ps -q build-pool)"
fi

# Guard: if compose up succeeded but the following ps -q came back empty
# (race, daemon hiccup, container exited during startup despite --wait),
# `docker exec ""` would fail cryptically. Surface the real state instead.
if [ -z "$POOL_CID" ]; then
    echo "ERROR: build-pool container not found after compose up. Check 'docker compose -f docker/compose.dev-builder.yml ps build-pool' and 'docker compose -f docker/compose.dev-builder.yml logs build-pool'." >&2
    exit 1
fi

exec docker exec \
    ${EXEC_ARGS[@]+"${EXEC_ARGS[@]}"} \
    "$POOL_CID" \
    "$@"
