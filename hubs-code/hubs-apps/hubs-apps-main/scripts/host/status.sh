#!/usr/bin/env bash
set -Eeuo pipefail

# status.sh — operator-facing diagnostic: dev environment + IDE + per-app state.
#
# Three probe classes, each degrading explicitly when prerequisites fail:
#   - Host-only: filesystem/.env/git/ssh/TCP reads. Always work, even
#     with docker wholly unavailable.
#   - Compose-mediated: docker compose ps / inspect. Need daemon.
#   - In-container: docker exec into nextcloud / dev-builder. Need
#     daemon + the relevant container/image.
#
# Doctrinal invariant — host-only is the operator's lifeline: even with
# docker entirely broken, §C still shows every cloned app's build state
# from filesystem reads alone.
#
# Doctrinal invariant — always exits zero. Findings are operator info,
# not script-failure signals (git status precedent). set -euo pipefail
# stays on so genuine bugs surface during development testing.
#
# Doctrinal invariant — read-only / side-effect-free. The batched lock
# probe opens .build/.lock with LOCK_NB and releases on close; status
# never holds a lock past its own probe, and never lazy-starts anything.
#
# Host floor: bash + git + docker compose v2 + ssh + make + POSIX
# coreutils only (no flock(1)/jq/python3 on host). python3 + polib +
# yaml are `make l10n` deps only; their absence is an advisory, not a
# hard dep. The lock + composer.json probe uses Python (fcntl.flock +
# json.load) because it runs in-container where Python exists.
#
# Bash 3.2-compatible throughout (macOS default /bin/bash, untestable
# host): no [[ ]], ${var,,}, declare -A, mapfile, wait -n, &>, |&,
# ${var@Q}. Empty-array expansion under set -u uses ${arr[@]+"${arr[@]}"}
# — bare "${arr[@]}" is an unbound-variable error on bash 3.2 when empty.

# --- Always-zero exit + crash backstop ---
#
# Two traps: ERR catches a set -e termination that escaped per-probe
# defenses (a bare cmdsubst returning nonzero outside if/&&/||/!) and
# prints "STATUS CRASHED" so the operator knows the report is partial,
# not clean. set -E (above) makes it fire inside functions/subshells.
# EXIT forces rc=0 regardless (always-zero invariant).
#
# Color vars (${RED:-}) are read at trap-fire time, by when the ANSI
# block below has set them — hence the :- guard for the early-crash case.
trap '_rc=$?; printf "\n%sSTATUS CRASHED%s — report is partial. rc=%s, cmd: %s\n" "${RED:-}" "${RESET:-}" "$_rc" "$BASH_COMMAND"' ERR
trap 'exit 0' EXIT

# --- Path resolution ---
#
# Two dirnames up from $0 is the platform root — knowable from any
# invocation context (make doesn't pass paths in).
PLATFORM_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

# HOST_PROJECT_DIR — required by docker/compose.yml's apps bind source.
# Normally set by make's export; fallback covers direct invocation.
export HOST_PROJECT_DIR="${HOST_PROJECT_DIR:-$PLATFORM_ROOT}"

# --- TTY-only ANSI colour ---
#
# stdout a terminal → colour; piped/captured → empty so less/redirect/CI
# output stays clean.
if [ -t 1 ]; then
    BOLD=$'\033[1m'
    DIM=$'\033[2m'
    RED=$'\033[31m'
    GREEN=$'\033[32m'
    YELLOW=$'\033[33m'
    RESET=$'\033[0m'
else
    BOLD=''; DIM=''; RED=''; GREEN=''; YELLOW=''; RESET=''
fi

# --- .env reader ---
#
# Value of KEY, .env first then .env.example — matches the Makefile's
# include precedence (.env, per-operator and gitignored, overrides
# .env.example). Empty when neither defines the key; caller treats
# empty-vs-non-empty as the signal, not file/key presence.
#
# Naive KEY=value parse (docker-compose convention): no quote stripping
# or mid-line comments — those edge cases aren't observed here.
read_env_var() {
    local key="$1"
    local value=""
    # First non-empty wins; .env (override) before .env.example (default).
    for file in "$PLATFORM_ROOT/.env" "$PLATFORM_ROOT/.env.example"; do
        [ -f "$file" ] || continue
        # Capture into a var so grep's no-match rc=1 doesn't trip set -e.
        # No stderr suppression: the [ -f ] gate already filtered absence,
        # so a grep rc=2 (permission/encoding) is a real condition the
        # operator should see leak to the terminal, not be hidden.
        local lines
        if lines="$(grep -E "^${key}=" "$file")"; then
            value="$(printf '%s\n' "$lines" | tail -n 1 | cut -d= -f2-)"
            [ -n "$value" ] && break
        fi
    done
    printf '%s' "$value"
}

# --- App discovery ---
#
# Walks apps/*/, classifying quilt (has upstream/) vs standalone. Skips
# apps/server: NC core is bind-mounted live at /var/www/html/, not a
# pluggable apps-extra entry — every apps/ iteration in the platform
# skips it for the same reason.
#
# Parallel arrays (bash 3.2 has no associative arrays).
APPS=()
APP_TYPES=()  # parallel to APPS: "quilt" or "standalone"
QUILT_APPS=() # subset of APPS, used for the batched lock probe

if [ -d "$PLATFORM_ROOT/apps" ]; then
    for app_dir in "$PLATFORM_ROOT"/apps/*/; do
        # Glob may not match (fresh checkout pre-setup) → literal pattern;
        # the [ -d ] guard rejects it.
        [ -d "$app_dir" ] || continue
        app_dir="${app_dir%/}"
        app="$(basename "$app_dir")"
        if [ "$app" = "server" ]; then
            continue
        fi
        APPS+=("$app")
        if [ -d "$app_dir/upstream" ]; then
            APP_TYPES+=("quilt")
            QUILT_APPS+=("$app")
        else
            APP_TYPES+=("standalone")
        fi
    done
fi

# --- Docker reachability cache ---
#
# Single docker info call. DOCKER_OK=0 degrades every compose/in-container
# probe; host-only probes don't consult it.
#
# PROBE_REASON is the degradation reason for "cannot probe — <reason>"
# tails. Set most-specific-first: PATH subsumes daemon-down subsumes
# compose-ps failure.
DOCKER_OK=0
PROBE_REASON=""
if ! command -v docker >/dev/null 2>&1; then
    PROBE_REASON="docker not on PATH"
elif ! docker info >/dev/null 2>&1; then
    PROBE_REASON="docker daemon unreachable"
else
    DOCKER_OK=1
fi

# --- Compose project name + DC argument list ---
#
# Mirrors the Makefile's $(DC) + seed.sh: basename of PLATFORM_ROOT,
# env-overridable. Feeds the ephemeral-container name pattern.
COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-$(basename "$PLATFORM_ROOT")}"

# Explicit fragment enumeration (base, dev-builder, IDE overlay, sidecars)
# — same set as the Makefile's $(DC), keep in sync; excludes editor
# backups the broader globs would catch. Order matters: dev.yml's nested
# rw binds layer onto dev-builder.yml's /platform:ro, so dev.yml MUST
# follow dev-builder.yml. Including dev.yml mirrors `make ide-up` so the
# §B IDE-wiring probes compare against what it actually brought up.
DC_ARGS=(docker compose -f "$PLATFORM_ROOT/docker/compose.yml")
if [ -f "$PLATFORM_ROOT/docker/compose.dev-builder.yml" ]; then
    DC_ARGS+=(-f "$PLATFORM_ROOT/docker/compose.dev-builder.yml")
fi
if [ -f "$PLATFORM_ROOT/docker/compose.dev.yml" ]; then
    DC_ARGS+=(-f "$PLATFORM_ROOT/docker/compose.dev.yml")
fi
for frag in "$PLATFORM_ROOT"/docker/sidecars/*.yml; do
    if [ -f "$frag" ]; then
        DC_ARGS+=(-f "$frag")
    fi
done

# --- Compose ps cache ---
#
# One `docker compose ps --all`; per-service lookups read the cache.
# --all so stopped containers show (the Sidecar half-completion finding
# needs "exited"). Pipe-delimited so awk splits without quoting concerns.
#
# COMPOSE_PS_OK distinguishes "ps succeeded but empty" (project down →
# "not running") from "ps failed" (→ "cannot probe"); without it a
# stopped project would falsely degrade every service line.
COMPOSE_PS=""
COMPOSE_PS_OK=0
if [ "$DOCKER_OK" = "1" ]; then
    # stderr to /dev/null: docker complaints (dirty compose state) would
    # pollute the report. The rare daemon-up-but-ps-failed case falls
    # through to PROBE_REASON degradation.
    if PS_OUT="$("${DC_ARGS[@]}" ps --all --format '{{.Service}}|{{.State}}|{{.Health}}|{{.Name}}' 2>/dev/null)"; then
        COMPOSE_PS="$PS_OUT"
        COMPOSE_PS_OK=1
    else
        # Don't overwrite a more specific reason already set.
        if [ -z "$PROBE_REASON" ]; then
            PROBE_REASON="compose ps unavailable"
        fi
    fi
fi

# service_state SERVICE → "state|health", or "not running" (ps ok but
# service absent), or "cannot probe" (ps failed).
service_state() {
    local svc="$1"
    if [ "$COMPOSE_PS_OK" != "1" ]; then
        printf 'cannot probe'
        return
    fi
    # Exact service-name match in column 1; first wins.
    local line
    line="$(printf '%s\n' "$COMPOSE_PS" | awk -F'|' -v s="$svc" '$1==s {print; exit}')"
    if [ -z "$line" ]; then
        printf 'not running'
        return
    fi
    printf '%s' "$line" | awk -F'|' '{printf "%s|%s", $2, $3}'
}

# service_container_name SERVICE → container name (COMPOSE_PS column 4),
# or empty. Lets `docker inspect` run without hardcoding the
# "<project>-<service>-<index>" naming convention.
service_container_name() {
    local svc="$1"
    if [ "$COMPOSE_PS_OK" != "1" ]; then
        return
    fi
    printf '%s\n' "$COMPOSE_PS" | awk -F'|' -v s="$svc" '$1==s {print $4; exit}'
}

# --- Dev-builder image presence cache ---
#
# Anti-footgun: a status probe must NOT trigger a multi-minute image
# rebuild. dc-run.sh builds when the versioned tag is missing (fresh
# clone, distclean, VERSION bump); pre-checking presence here lets the
# dev-builder probes degrade cleanly instead of hanging status for
# minutes.
#
# Per-image VERSION fallbacks (normally from make's export; fallback
# covers make-bypassing invocations). All three required even though
# only dev-builder is inspected here: every compose call loads the whole
# DC_ARGS set, and the nextcloud/sidecar fragments' image: lines use
# ${VAR:?...} interpolations that fail compose parse if unset. export
# so dc-run.sh inherits.
export DEV_BUILDER_VERSION="${DEV_BUILDER_VERSION:-$(cat "$PLATFORM_ROOT/docker/dev-builder/VERSION")}"
export NODE_SIDECAR_VERSION="${NODE_SIDECAR_VERSION:-$(cat "$PLATFORM_ROOT/docker/node-sidecar/VERSION")}"
export NEXTCLOUD_IMAGE_VERSION="${NEXTCLOUD_IMAGE_VERSION:-$(cat "$PLATFORM_ROOT/docker/nextcloud/VERSION")}"
DEVBUILDER_IMAGE_OK=0
if [ "$DOCKER_OK" = "1" ]; then
    if docker image inspect "hubs-apps/dev-builder:${DEV_BUILDER_VERSION}" >/dev/null 2>&1; then
        DEVBUILDER_IMAGE_OK=1
    fi
fi

# --- Build-pool image-axis cache ---
#
# Does the running build-pool's image tag match docker/dev-builder/VERSION?
# Mismatch = a VERSION bump landed, pool not yet recycled — dc-run.sh
# recycles on the next routed call, so this is informational, not a warning.
#
# POOL_IMAGE_STATE: "" (not running / docker unreachable — state line
# surfaces that), "current", or "stale" (recycle pending).
POOL_IMAGE_STATE=""
POOL_STATE_PRE="$(service_state build-pool)"
case "$POOL_STATE_PRE" in
    running*)
        _pool_name="$(service_container_name build-pool)"
        if [ -n "$_pool_name" ]; then
            _pool_image="$(docker inspect --format '{{.Config.Image}}' "$_pool_name" 2>/dev/null || true)"
            if [ "$_pool_image" = "hubs-apps/dev-builder:${DEV_BUILDER_VERSION}" ]; then
                POOL_IMAGE_STATE="current"
            elif [ -n "$_pool_image" ]; then
                POOL_IMAGE_STATE="stale"
            fi
        fi
        unset _pool_name _pool_image
        ;;
esac

# --- Dev-builder container runtime inspect cache ---
#
# Single `docker inspect` gathers env/mounts/group_add in one call;
# §B IDE-wiring probes read this cache. Only fires when the container
# is up. Sectioned output (==ENV==/==MOUNTS==/==GROUPS==/==END==), same
# shape as NC_FACTS; host-side awk splits by tag (db_inspect_section).
# MOUNTS rows are type|source|destination|mode|rw.
#
# DEVBUILDER_INSPECT_OK gates readers; a missing ==END== marker counts
# as failure (rc=0 but template crashed mid-emit — same defense as NC_FACTS).
DEVBUILDER_INSPECT=""
DEVBUILDER_INSPECT_OK=0
DEVBUILDER_INSPECT_ERR=""
DEVBUILDER_NAME=""
DEVBUILDER_STATE_PRE="$(service_state dev-builder)"
case "$DEVBUILDER_STATE_PRE" in
    running*)
        DEVBUILDER_NAME="$(service_container_name dev-builder)"
        if [ -n "$DEVBUILDER_NAME" ]; then
            # `{{println}}` (no args) emits a bare "\n"; `{{.|println}}`
            # would prepend a leading space for non-strings.
            if DBI_OUT="$(docker inspect "$DEVBUILDER_NAME" --format '==ENV==
{{range .Config.Env}}{{.}}{{println}}{{end}}==MOUNTS==
{{range .Mounts}}{{.Type}}|{{.Source}}|{{.Destination}}|{{.Mode}}|{{.RW}}{{println}}{{end}}==GROUPS==
{{range .HostConfig.GroupAdd}}{{.}}{{println}}{{end}}==END==' 2>&1)"; then
                if printf '%s\n' "$DBI_OUT" | grep -q '^==END==$'; then
                    DEVBUILDER_INSPECT="$DBI_OUT"
                    DEVBUILDER_INSPECT_OK=1
                else
                    DEVBUILDER_INSPECT_ERR="inspect output missing ==END== marker; tail: $(printf '%s' "$DBI_OUT" | tail -3 | tr '\n' ' ')"
                fi
            else
                DEVBUILDER_INSPECT_ERR="$(printf '%s' "$DBI_OUT" | head -1)"
            fi
        else
            DEVBUILDER_INSPECT_ERR="compose ps reported dev-builder running but didn't yield a container name"
        fi
        ;;
esac

# db_inspect_section TAG → content between "==TAG==" and the next "==..."
# line. Empty when the probe failed OR the section is legitimately empty
# (e.g. no group_add) — callers check DEVBUILDER_INSPECT_OK to tell apart.
db_inspect_section() {
    local tag="$1"
    if [ -z "$DEVBUILDER_INSPECT" ]; then
        return
    fi
    printf '%s\n' "$DEVBUILDER_INSPECT" | awk -v t="==${tag}==" '
        $0 == t { in_section = 1; next }
        in_section && /^==[A-Z_]+==$/ { exit }
        in_section { print }
    '
}

# --- vscode-server attach probe ---
#
# Count of vscode-server processes inside the dev-builder — nonzero iff
# an IDE is attached (Dev Containers runs vscode-server for the life of
# the connection). Annotates the §B Dev-builder line so the dev knows
# whether `make ide-down` would kill an active session. Empty when the
# probe couldn't run → falls back to bare "running".
#
# Anti-footgun — /proc/*/exe resolution, NOT `pgrep -f`: run from inside
# the container, our `docker exec ... pgrep -f PATTERN` would self-match
# (PATTERN is a literal substring of the exec process's own argv) and
# false-positive a count of 1. Matching exe symlinks against
# .vscode-server/ is immune — the spawning processes resolve to
# docker/sh, never under .vscode-server/.
DEVBUILDER_VSCODE_COUNT=""
if [ -n "$DEVBUILDER_NAME" ]; then
    # `_vsc_out=$(cmd) && rc=0 || rc=$?`: captures rc without tripping
    # set -e on the assignment-with-cmdsubst. Recurs throughout this script.
    _vsc_out="$(docker exec "$DEVBUILDER_NAME" sh -c '
n=0
for pid in /proc/[0-9]*; do
    exe=$(readlink "$pid/exe" 2>/dev/null) || continue
    case "$exe" in
        */.vscode-server/*) n=$((n+1)) ;;
    esac
done
echo $n
' 2>/dev/null)" && _vsc_rc=0 || _vsc_rc=$?
    if [ "$_vsc_rc" = "0" ] && [ -n "$_vsc_out" ]; then
        DEVBUILDER_VSCODE_COUNT="$_vsc_out"
    fi
    unset _vsc_out _vsc_rc
fi

# --- Host identity cache ---
#
# Operator UID/GID — read by §B (Dev UID line vs .env) and §C (.build/
# ownership probe). Cached once so §B and §C share one source, no
# ordering dependency.
HOST_UID="$(id -u)"
HOST_GID="$(id -g)"

# --- Docker-socket cache ---
#
# Two facts: does /var/run/docker.sock exist, and is the host user in
# its owning group (so host docker CLI works sans sudo AND compose's
# group_add resolves to a real GID). Read by §B + the IDE-wiring probe.
#
# `ls -nd` not `stat`: portable across GNU (Linux) and BSD (macOS)
# coreutils, which differ on stat. grep -qx matches the full GID token
# so 14 doesn't substring-match 114.
DOCKER_SOCK_PATH="/var/run/docker.sock"
DOCKER_SOCK_GID=""
DOCKER_SOCK_USER_IN_GROUP=0
if [ -S "$DOCKER_SOCK_PATH" ]; then
    DOCKER_SOCK_GID="$(ls -nd "$DOCKER_SOCK_PATH" 2>/dev/null | awk '{print $4}')" || DOCKER_SOCK_GID=""
    if [ -n "$DOCKER_SOCK_GID" ]; then
        if id -G | tr ' ' '\n' | grep -qx "$DOCKER_SOCK_GID"; then
            DOCKER_SOCK_USER_IN_GROUP=1
        fi
    fi
fi

# Image-baked apps-extra entries — the stale-enable check subtracts
# these from APPS_ENABLED so they don't false-fire as "enabled but not
# cloned". Hardcoded: exactly one entry (hmr_enabler, baked in
# docker/nextcloud/Dockerfile), only ever. Space-padded for case match.
NC_IMAGE_BAKED_EXTRA=" hmr_enabler "

# --- In-flight (running) one-off dev-builder containers, per app ---
#
# The EPHEMERAL=1 dc-run.sh path (`compose run --rm`: assemble MODE=force,
# deps, security-update, setup, new-app, build/package MODE=production)
# spawns "<project>-dev-builder-run-<random>" containers with APP_NAME in
# their env; the name-shape filter excludes the IDE-attach
# "<project>-dev-builder-1". `compose run --rm` sets HostConfig.AutoRemove,
# so a one-off self-removes the moment its command ends — a stopped one
# never lingers; only genuinely-running ops appear, and they vanish on
# completion. Two products from one `docker ps` + per-container inspect:
#
#   IN_FLIGHT_APPS    space-padded app set. is_dev_builder_running_for()
#                     reads it for the Sidecar half-completion finding and
#                     the Lock-line holder naming — per-app precision so an
#                     op for app Y doesn't mask app X.
#   EPHEMERAL_RUNNING "name|status|app" per running one-off (app empty for
#                     platform-level ops like make new-app). Read by the §A
#                     Ephemerals line and lock_holder() (Lock-line naming).
#
# ps/inspect failures aren't tracked: a container vanishing mid-probe has
# already closed its lock fd, so a missed entry can't mislead the sidecar
# finding or the holder naming.
IN_FLIGHT_APPS=" "
EPHEMERAL_RUNNING=""
if [ "$DOCKER_OK" = "1" ]; then
    # Capture rc so a transient ps failure doesn't terminate the report
    # (worst case is the harmless missed entry noted above). Status field
    # is "Up 4 minutes"-style; neither Names nor Status contains a pipe.
    if db_rows="$(docker ps --filter 'name=-dev-builder-run-' --format '{{.Names}}|{{.Status}}' 2>/dev/null)"; then
        while IFS='|' read -r db_container db_status; do
            [ -n "$db_container" ] || continue
            # Inspect for APP_NAME. Inspect failing means the container
            # vanished between our ps and now (AutoRemove raced us) — skip it,
            # same missed-entry semantics as IN_FLIGHT_APPS. A live one-off
            # with no APP_NAME (platform-level ops like make new-app) inspects
            # fine and is kept with an empty app field.
            db_env="$(docker inspect "$db_container" --format '{{range .Config.Env}}{{println .}}{{end}}' 2>/dev/null)" || continue
            db_app="$(printf '%s\n' "$db_env" | awk -F= '$1=="APP_NAME" {print $2; exit}')"
            EPHEMERAL_RUNNING="${EPHEMERAL_RUNNING}${db_container}|${db_status}|${db_app}
"
            if [ -n "$db_app" ]; then
                case "$IN_FLIGHT_APPS" in
                    *" $db_app "*) ;;
                    *) IN_FLIGHT_APPS="${IN_FLIGHT_APPS}${db_app} " ;;
                esac
            fi
        done <<EOF
$db_rows
EOF
    fi
fi

# is_dev_builder_running_for APP → rc 0 if a dc-run.sh container for
# this app is in flight (lock holder is legitimate); rc 1 otherwise.
is_dev_builder_running_for() {
    case "$IN_FLIGHT_APPS" in
        *" $1 "*) return 0 ;;
        *) return 1 ;;
    esac
}

# lock_holder APP → human description of what holds APP's .build/.lock.
# Only meaningful when the probe reported "held" (callers gate on that):
# a held flock always has a live holder, and the holder is either a running
# one-off for APP (named from EPHEMERAL_RUNNING) or — held but no one-off —
# a build-pool docker-exec op (plain assemble / quilt / diff) or
# tests/_reset.sh. The platform can't tell a wanted op from an interrupted
# one (both look identical); it states the fact, the operator judges it.
lock_holder() {
    local app="$1" line
    line="$(printf '%s\n' "$EPHEMERAL_RUNNING" | awk -F'|' -v a="$app" '$3==a {print; exit}')"
    if [ -n "$line" ]; then
        printf 'an ephemeral op (%s, %s)' \
            "$(printf '%s' "$line" | cut -d'|' -f1)" \
            "$(printf '%s' "$line" | cut -d'|' -f2)"
    else
        printf 'a build-pool operation'
    fi
}

# --- Batched per-app probe (single dc-run.sh invocation) ---
#
# Amortises N apps' lock + Mozart probes into one container start
# (compose-run + start is a few hundred ms per spawn).
#
# Per app: .build/.lock state (free/held/absent/error) and Mozart wiring
# (composer.json's extra.mozart key). Inline Python is the cleanest
# in-container primitive — fcntl.flock for non-polling contention,
# json.load over a host-side string-grep that would false-positive on a
# dep literally named "mozart". Output: "<app>|<lock>|<mozart>" per line
# (pipe, not space, since error messages contain spaces).
#
# Doctrinal invariant — side-effect-free: LOCK_EX|LOCK_NB so contention
# surfaces as BlockingIOError without waiting, and `with open(...)`
# closes the fd (releasing the flock) on exit — status NEVER holds the
# lock past its probe. "r" mode so it never creates/truncates .lock
# (absent → FileNotFoundError → "absent"); read-mode flock works on
# Linux and passes through OrbStack.
APP_PROBE_RESULTS=""
APP_PROBE_ERR=""
if [ "$DOCKER_OK" = "1" ] && [ "$DEVBUILDER_IMAGE_OK" = "1" ] && [ "${#QUILT_APPS[@]}" -gt 0 ]; then
    # Combined 2>&1 merges dc-run.sh BuildKit noise (success-case stderr)
    # with python's probe results; a sentinel before the results lets the
    # host split cleanly (everything after = output, before = noise).
    #
    # Doctrinal invariant — side-effect-free: EPHEMERAL=1 forces the
    # ephemeral path so the probe doesn't lazy-start the build-pool,
    # which would flip the operator's visible state from "down" to
    # "running" just for asking. ~700ms ephemeral vs ~100ms pool exec —
    # fine for a once-per-status probe.
    if APR_RAW="$(printf '%s\n' "${QUILT_APPS[@]}" | EPHEMERAL=1 bash "$PLATFORM_ROOT/scripts/host/dc-run.sh" python3 -c '
import errno
import fcntl
import json
import sys

# Sentinel separating build noise from probe results in the host-captured stream.
print("===STATUS_RESULTS===")
for app in sys.stdin.read().split():
    # Lock state
    lock_path = "/platform/apps/" + app + "/.build/.lock"
    try:
        with open(lock_path, "r") as f:
            fcntl.flock(f, fcntl.LOCK_EX | fcntl.LOCK_NB)
            lock = "free"
    except FileNotFoundError:
        lock = "absent"
    except (IOError, OSError) as e:
        if getattr(e, "errno", None) in (errno.EAGAIN, errno.EWOULDBLOCK):
            lock = "held"
        else:
            lock = "error: " + str(e)

    # Mozart wiring: composer.json extra.mozart key.
    mozart = "no"
    composer_path = "/platform/apps/" + app + "/.build/composer.json"
    try:
        with open(composer_path) as f:
            data = json.load(f)
        if "mozart" in (data.get("extra") or {}):
            mozart = "yes"
    except FileNotFoundError:
        # No composer.json -> genuinely no Mozart (no PHP deps).
        pass
    except json.JSONDecodeError:
        # Corrupt composer.json: report "error", not silently "no" —
        # "no" would suppress the lib/Vendor finding on a real Mozart app.
        # (No apostrophes here: block is inside python3 -c '...'.)
        mozart = "error"

    print(app + "|" + lock + "|" + mozart)
' 2>&1)"; then
        APP_PROBE_RESULTS="$(printf '%s\n' "$APR_RAW" | awk '/^===STATUS_RESULTS===$/{f=1; next} f')"
        if [ -z "$APP_PROBE_RESULTS" ]; then
            # rc=0 but no sentinel — python crashed before its first
            # print, or a build line masked the sentinel.
            APP_PROBE_ERR="probe rc=0 but no ===STATUS_RESULTS=== sentinel; tail: $(printf '%s' "$APR_RAW" | tail -3 | tr '\n' ' ')"
        fi
    else
        APP_PROBE_ERR="dc-run.sh / python probe failed: $(printf '%s' "$APR_RAW" | tail -3 | tr '\n' ' ')"
    fi
fi

# Internal helper: return the matching probe line's field N, or empty
# if no result for this app.
_app_probe_field() {
    local app="$1" field="$2"
    printf '%s\n' "$APP_PROBE_RESULTS" | awk -F'|' -v a="$app" -v f="$field" '$1==a {print $f; exit}'
}

# lock_state APP → "free"/"held"/"absent"/"error: ..."/"cannot probe —
# <reason>". Names the actual probe-failure reason (APP_PROBE_ERR) when
# known — the operator needs the actionable detail.
lock_state() {
    local app="$1"
    if [ "$DOCKER_OK" != "1" ]; then
        printf 'cannot probe — %s' "$PROBE_REASON"
        return
    fi
    if [ "$DEVBUILDER_IMAGE_OK" != "1" ]; then
        printf 'cannot probe — dev-builder image not built'
        return
    fi
    if [ -z "$APP_PROBE_RESULTS" ]; then
        if [ -n "$APP_PROBE_ERR" ]; then
            printf 'cannot probe — %s' "$APP_PROBE_ERR"
        else
            printf 'cannot probe — dev-builder probe unavailable'
        fi
        return
    fi
    local v
    v="$(_app_probe_field "$app" 2)"
    if [ -z "$v" ]; then
        printf 'cannot probe — no result for %s' "$app"
        return
    fi
    printf '%s' "$v"
}

# mozart_wired APP → "yes"/"no"/"unknown" (probe didn't run). Anti-footgun:
# callers MUST handle "unknown" explicitly — treating it as "no" silently
# drops the lib/Vendor finding on real Mozart-wired apps.
mozart_wired() {
    local v
    v="$(_app_probe_field "$1" 3)"
    if [ -z "$v" ]; then
        printf 'unknown'
    else
        printf '%s' "$v"
    fi
}

# --- NC-side multi-fact probe ---
#
# One `docker compose exec` gathers every NC-side fact (sdkmc config,
# app enable list, hmr_enabler, NEXTCLOUD_VERSION marker, .htaccess HMR
# block, autohandlaggare presence). Single round-trip, sectioned by
# "==TAG==" delimiters; host-side awk splits by tag.
NC_FACTS=""
NC_GATHER_OK=0
NC_GATHER_ERR=""
NC_STATE="$(service_state nextcloud)"
NC_UP=0
case "$NC_STATE" in
    running*) NC_UP=1 ;;
esac

if [ "$NC_UP" = "1" ]; then
    # Single docker exec — eliminates the N+3 calls a naive version does.
    # Single-quoted heredoc to bash -s (no host-side $ expansion, no tmp
    # file); -T since stdout is captured.
    #
    # APPS_ENABLED/DISABLED emit one app per line so host-side `case`
    # matches without re-parsing JSON; the app:list JSON parse happens
    # in-container where python3 exists (host floor has neither python3
    # nor jq).
    #
    # Three failure modes, all surfaced: exec rc!=0 (stderr → NC_GATHER_ERR
    # via 2>&1); rc=0 but truncated (no ==END== marker — gather crashed
    # mid-run); rc=0 with ==END== (NC_GATHER_OK=1, cache trusted).
    if NC_OUT="$("${DC_ARGS[@]}" exec -T nextcloud bash -s 2>&1 <<'GATHERSCRIPT'
# NC-side bash, set -e off (bash -s doesn't inherit it): a failing
# command (missing config key) just continues, and empty stdout for
# that section is a legitimate state the parser handles. The &&/||
# chains below carry presence/absence logic, not error suppression.
echo "==SDKMC_ORG=="
php occ config:app:get sdkmc organizationExtension
echo "==SDKMC_IMAP=="
php occ config:app:get sdkmc imapHost
echo "==SDKMC_SMTP=="
php occ config:app:get sdkmc smtpHost
echo "==USER_AUTO1=="
php occ user:info autohandlaggare1 >/dev/null 2>&1 && echo present || echo absent
echo "==USER_AUTO2=="
php occ user:info autohandlaggare2 >/dev/null 2>&1 && echo present || echo absent
# Parse app:list JSON once; emit APPS_ENABLED + APPS_DISABLED sections.
# Sorted for deterministic output (eases diffing the gather between runs).
php occ app:list --output=json | python3 -c '
import json, sys
try:
    data = json.loads(sys.stdin.read() or "{}")
except (ValueError, json.JSONDecodeError):
    data = {}
print("==APPS_ENABLED==")
print("\n".join(sorted(data.get("enabled", {}).keys())))
print("==APPS_DISABLED==")
print("\n".join(sorted(data.get("disabled", {}).keys())))
'
echo "==NC_VERSION_ENV=="
printf '%s\n' "${NEXTCLOUD_VERSION:-}"
echo "==NC_VERSION_INSTALLED_MARKER=="
cat /var/www/html/config/.installed-version
# NC core apps (upstream tarball) — appear in `enabled` though we never
# cloned them. The stale-enable probe subtracts this live set so it
# tracks NC version changes instead of a drifting hardcoded guess.
echo "==NC_CORE_APPS=="
ls /var/www/html/apps
# Apps NC actually sees via apps-extra/ symlinks. A host-cloned app
# missing here means the wire-up hasn't run since the clone — NC can't
# enable what it can't see. (Image-baked entries are tracked host-side
# as NC_IMAGE_BAKED_EXTRA, not here.)
echo "==NC_APPS_EXTRA=="
ls /var/www/html/apps-extra
echo "==HTACCESS_BLOCK=="
sed -n '/### HMR-PROXY-START ###/,/### HMR-PROXY-END ###/p' /var/www/html/.htaccess
echo "==END=="
GATHERSCRIPT
    )"; then
        NC_FACTS="$NC_OUT"
        if printf '%s\n' "$NC_FACTS" | grep -q '^==END==$'; then
            NC_GATHER_OK=1
        else
            # rc=0 but no end marker — gather crashed mid-run; tail is
            # the highest-signal diagnostic (php fatal, occ trace).
            NC_GATHER_ERR="gather truncated (no ==END== marker); tail: $(printf '%s' "$NC_FACTS" | tail -3 | tr '\n' ' ')"
        fi
    else
        # docker exec itself failed; NC_OUT holds stderr (2>&1).
        NC_GATHER_ERR="$(printf '%s' "$NC_OUT" | head -1)"
        # Drop partial output — downstream must treat an exec-layer
        # failure as wholly unavailable.
        NC_FACTS=""
    fi
fi

# NC_REASON — degradation reason for every NC-dependent probe. Empty
# when NC is up AND the gather succeeded. Surfacing a specific reason
# here is what stops those probes from silently reading a gather failure
# as "absent" / "never seeded" / "not enabled".
#
# Three modes, resolved once: NC state uncertain → pass through
# PROBE_REASON; not running → "nc down"; running but gather failed →
# name the gather error.
if [ "$NC_STATE" = "cannot probe" ]; then
    NC_REASON="${PROBE_REASON:-nc state unknown}"
elif [ "$NC_UP" != "1" ]; then
    NC_REASON="nc down"
elif [ "$NC_GATHER_OK" != "1" ]; then
    NC_REASON="nc gather failed${NC_GATHER_ERR:+: $NC_GATHER_ERR}"
else
    NC_REASON=""
fi

# nc_section TAG → content between "==TAG==" and the next "==..." line.
# Empty when the gather failed OR the section is legitimately empty —
# callers check NC_REASON to tell apart.
nc_section() {
    local tag="$1"
    if [ -z "$NC_FACTS" ]; then
        return
    fi
    printf '%s\n' "$NC_FACTS" | awk -v t="==${tag}==" '
        $0 == t { in_section = 1; next }
        in_section && /^==[A-Z_0-9]+==$/ { exit }
        in_section { print }
    '
}

# --- Tunnel probe (compose service state + label extraction) ---
#
# State from `service_state tunnel` (same path as the other services).
# The SERVER target is on the `hubs-apps.tunnel.server` label, written by
# compose from `make tunnel SERVER=<host>` — read via docker inspect, not
# pgrep. Works identically from host and inside the IDE container (the
# socket bind gives the same view).
TUNNEL_STATE_PRE="$(service_state tunnel)"
TUNNEL_TARGET=""
TUNNEL_CONTAINER_NAME=""
case "$TUNNEL_STATE_PRE" in
    'cannot probe'|'not running')
        ;;
    *)
        # service_container_name resolves the project-prefixed name so
        # this probe and the `docker logs` hints below never hardcode
        # `<project>-tunnel-1`.
        TUNNEL_CONTAINER_NAME="$(service_container_name tunnel)"
        if [ -n "$TUNNEL_CONTAINER_NAME" ]; then
            TUNNEL_TARGET="$(docker inspect "$TUNNEL_CONTAINER_NAME" --format '{{index .Config.Labels "hubs-apps.tunnel.server"}}' 2>/dev/null || true)"
        fi
        ;;
esac

# --- Section emitters ---

# emit_dev_kv LABEL VALUE [EXTRA]
# §A/§B KV line. 15-char label field fits the longest ("Docker socket:"
# = 14) with one pad char.
emit_dev_kv() {
    local label="$1" value="$2" extra="${3:-}"
    if [ -n "$extra" ]; then
        # Align the hint ($extra) column on the value's VISIBLE width. A plain
        # %-30s pads on byte length, which counts $value's ANSI colour bytes
        # (DIM=4, RED/GREEN/YELLOW=5, an embedded colour more) — so the hint
        # drifts a column or two per colour. Strip the SGR escapes to measure,
        # then pad by hand. ESC built via printf so the sed pattern is portable
        # (GNU + BSD sed). Literal ' ' before $extra keeps a separator even when
        # the value overflows the field (pad clamped to 0).
        local esc plain pad
        esc=$(printf '\033')
        plain=$(printf '%s' "$value" | sed "s/${esc}\[[0-9;]*m//g")
        pad=$(( 30 - ${#plain} ))
        [ "$pad" -lt 0 ] && pad=0
        printf '  %-15s%s%*s %s\n' "${label}:" "$value" "$pad" '' "$extra"
    else
        printf '  %-15s%s\n' "${label}:" "$value"
    fi
}

# CC_FINDINGS — §D cross-cutting buffer. Defined early so the IDE-wiring
# compute (runs before §A emits) can populate it via cc_finding without
# ordering games.
CC_FINDINGS=""

# cc_finding SEVERITY CONDITION [REST]
# Same two-line shape + colors as per-app emit_finds.
cc_finding() {
    local severity="$1" condition="$2" rest="${3:-}"
    # Pad width = 8 + len(severity); see emit_finds for derivation.
    local pad
    pad="$(printf '%*s' $((8 + ${#severity})) '')"
    local color="$YELLOW"
    case "$severity" in
        RECOVER) color="$RED" ;;
        ADVISORY) color="$DIM" ;;
    esac
    CC_FINDINGS="${CC_FINDINGS}  → ${color}${severity}${RESET}: ${condition}
"
    if [ -n "$rest" ]; then
        CC_FINDINGS="${CC_FINDINGS}${pad}${rest}
"
    fi
}

# --- IDE wiring compute ---
#
# Seven probes comparing compose.dev.yml's declared wiring to what
# `docker inspect` reports on the running dev-builder. Each mismatch
# surfaces both in the §B summary line AND as one §D MISMATCH finding
# (named so the operator knows WHICH piece is wrong). Each probe's why
# is at its own site below.
#
# Degradation: no findings emitted unless the service is running with a
# successful inspect — when it can't probe, the §A/§B Dev-builder line
# already shows the same reason, and a not-up IDE workflow has nothing
# to validate. WIRING_SUMMARY is read by §B.
WIRING_SUMMARY=""
WIRING_OK_COUNT=0
WIRING_TOTAL=7

# service_state is cheap (awk over cached COMPOSE_PS), so re-read here.
_wiring_db_state="$(service_state dev-builder)"
_wiring_db_running=0
case "$_wiring_db_state" in
    running*) _wiring_db_running=1 ;;
esac

if [ "$_wiring_db_state" = "cannot probe" ]; then
    WIRING_SUMMARY="${DIM}cannot probe — ${PROBE_REASON}${RESET}"
elif [ "$_wiring_db_running" != "1" ]; then
    WIRING_SUMMARY="${DIM}cannot probe — dev-builder not running${RESET}"
elif [ "$DEVBUILDER_INSPECT_OK" != "1" ]; then
    WIRING_SUMMARY="${YELLOW}cannot probe — ${DEVBUILDER_INSPECT_ERR:-inspect unavailable}${RESET}"
else
    # Per-probe helpers, scope-local. Mount records are
    # "type|source|destination|mode|rw" from the inspect template.
    # _db_env_value KEY → value from the ENV section, empty if unset.
    _db_env_value() {
        db_inspect_section ENV | awk -F= -v k="$1" '$1==k {sub(/^[^=]*=/,""); print; exit}'
    }
    # _db_mount_field DEST FIELD → field N (1=type,2=source,5=rw) of the
    # first mount at DEST.
    _db_mount_field() {
        db_inspect_section MOUNTS | awk -F'|' -v d="$1" -v f="$2" '$3==d {print $f; exit}'
    }
    # _db_volume_source DEST → Source of the volume mount at DEST. Adds
    # a type=volume check (a bind at the same dest looks identical in
    # fields 2-5).
    _db_volume_source() {
        db_inspect_section MOUNTS | awk -F'|' -v d="$1" '$1=="volume" && $3==d {print $2; exit}'
    }
    # _check_bind_rw DEST EXPECTED_SRC FAILURE_DESC — assert a type=bind,
    # rw=true bind; FAILURE_DESC (the operator-visible consequence) goes
    # in the finding. EXPECTED_SRC is always the HOST path: docker reports
    # mount sources as host paths, and callers build via ${HOST_PROJECT_DIR}
    # which is the host path whether status runs from host or in-container.
    _check_bind_rw() {
        local dest="$1" expected_src="$2" failure_desc="$3"
        local _ctype _csrc _crw
        _ctype="$(_db_mount_field "$dest" 1)"
        _csrc="$(_db_mount_field "$dest" 2)"
        _crw="$(_db_mount_field "$dest" 5)"
        if [ "$_ctype" = "bind" ] && [ "$_csrc" = "$expected_src" ] && [ "$_crw" = "true" ]; then
            WIRING_OK_COUNT=$((WIRING_OK_COUNT + 1))
        else
            cc_finding 'MISMATCH' \
                "dev-builder ${dest} bind drift (got type='${_ctype:-none}' source='${_csrc:-none}' rw='${_crw:-none}'; expected type='bind' source='${expected_src}' rw='true'; ${failure_desc})." \
                "Run: make ide-down && make ide-up"
        fi
    }

    # --- Probe 1: IDE_ATTACH=1 ---
    _v="$(_db_env_value IDE_ATTACH)"
    if [ "$_v" = "1" ]; then
        WIRING_OK_COUNT=$((WIRING_OK_COUNT + 1))
    else
        cc_finding 'MISMATCH' \
            "dev-builder IDE_ATTACH env is '${_v:-unset}' (expected 1; docker/compose.dev.yml didn't load on container start)." \
            "Run: make ide-down && make ide-up"
    fi

    # --- Probe 2: dev-builder HOST_PROJECT_DIR env ---
    # Compare against $HOST_PROJECT_DIR, NOT $PLATFORM_ROOT: from inside
    # the container PLATFORM_ROOT is /platform while HOST_PROJECT_DIR is
    # the host path, so comparing to PLATFORM_ROOT would false-positive.
    _v="$(_db_env_value HOST_PROJECT_DIR)"
    if [ "$_v" = "$HOST_PROJECT_DIR" ]; then
        WIRING_OK_COUNT=$((WIRING_OK_COUNT + 1))
    else
        cc_finding 'MISMATCH' \
            "dev-builder HOST_PROJECT_DIR env is '${_v:-unset}' (expected '${HOST_PROJECT_DIR}'; bind sources from inside the container won't resolve to the host tree)." \
            "Run: make ide-down && make ide-up (from ${HOST_PROJECT_DIR})"
    fi

    # --- Probe 3: /var/run/docker.sock bind present ---
    _type="$(_db_mount_field /var/run/docker.sock 1)"
    _src="$(_db_mount_field /var/run/docker.sock 2)"
    if [ "$_type" = "bind" ] && [ "$_src" = "/var/run/docker.sock" ]; then
        WIRING_OK_COUNT=$((WIRING_OK_COUNT + 1))
    else
        cc_finding 'MISMATCH' \
            "dev-builder /var/run/docker.sock bind missing or wrong source (compose orchestration + dc-run.sh routing from the integrated terminal won't work)." \
            "Run: make ide-down && make ide-up"
    fi

    # --- Probe 4: /platform/apps rw bind from host apps/ ---
    _check_bind_rw "/platform/apps" "${HOST_PROJECT_DIR}/apps" \
        "IDE edits won't land on the host tree"

    # --- Probe 5: /platform/docker/sidecars rw bind from host docker/sidecars/ ---
    _check_bind_rw "/platform/docker/sidecars" "${HOST_PROJECT_DIR}/docker/sidecars" \
        "\`make webpack\` from inside the integrated terminal can't write the fragment"

    # --- Probe 6: dev-builder-home volume at /home/developer ---
    # Match on path-suffix (<project>_dev-builder-home/_data), not full
    # path — rootless docker uses a different volume root.
    _vsrc="$(_db_volume_source /home/developer)"
    case "$_vsrc" in
        */${COMPOSE_PROJECT_NAME}_dev-builder-home/_data)
            WIRING_OK_COUNT=$((WIRING_OK_COUNT + 1))
            ;;
        '')
            cc_finding 'MISMATCH' \
                "dev-builder /home/developer has no volume mount (vscode-server will reinstall on every ide-up; .bash_history won't persist)." \
                "Run: make ide-down && make ide-up"
            ;;
        *)
            cc_finding 'MISMATCH' \
                "dev-builder /home/developer mounted from unexpected volume source '${_vsrc}' (expected the dev-builder-home named volume for this project)." \
                "Run: make ide-down && make ide-up"
            ;;
    esac

    # --- Probe 7: HostConfig.GroupAdd contains host docker socket GID ---
    # If the socket isn't on host at all, we can't even compute the
    # expected GID — skip with a finding describing the upstream gap.
    # (The §B Docker socket line will already show "not present".)
    if [ -z "$DOCKER_SOCK_GID" ]; then
        cc_finding 'MISMATCH' \
            "dev-builder group_add membership can't be verified — host /var/run/docker.sock absent (see the Docker socket line above)." \
            "install docker on the host before attaching the IDE"
    elif db_inspect_section GROUPS | grep -qxF -- "$DOCKER_SOCK_GID"; then
        WIRING_OK_COUNT=$((WIRING_OK_COUNT + 1))
    else
        cc_finding 'MISMATCH' \
            "dev-builder group_add does not include host docker socket GID ${DOCKER_SOCK_GID} (developer user inside the container can't write to /var/run/docker.sock; compose calls from the integrated terminal will EACCES)." \
            "Run: make ide-down && make ide-up"
    fi

    # Drop the wiring-local helpers — no callers below.
    unset -f _db_env_value _db_mount_field _db_volume_source _check_bind_rw

    if [ "$WIRING_OK_COUNT" = "$WIRING_TOTAL" ]; then
        WIRING_SUMMARY="${GREEN}OK (${WIRING_OK_COUNT}/${WIRING_TOTAL} — env + binds + volume + group_add)${RESET}"
    else
        _drift=$((WIRING_TOTAL - WIRING_OK_COUNT))
        _plural=issues
        [ "$_drift" = "1" ] && _plural=issue
        WIRING_SUMMARY="${YELLOW}DRIFT (${_drift} ${_plural}; see findings)${RESET}"
    fi
fi
unset _wiring_db_state _wiring_db_running _v _type _src _vsrc _drift _plural

# --- §A Dev environment ---

printf '\n%sDev environment%s\n' "$BOLD" "$RESET"

# Nextcloud
case "$NC_STATE" in
    running\|healthy)
        emit_dev_kv 'Nextcloud' "${GREEN}running, healthy${RESET}" 'http://localhost:8080'
        ;;
    running\|)
        # NC declares a healthcheck (docker/compose.yml), so empty health
        # shouldn't fire — surface honestly if it does.
        emit_dev_kv 'Nextcloud' "${YELLOW}running, no healthcheck${RESET}" 'http://localhost:8080'
        ;;
    running\|*)
        # running + non-healthy (starting / unhealthy)
        emit_dev_kv 'Nextcloud' "${YELLOW}running, $(printf '%s' "$NC_STATE" | cut -d'|' -f2)${RESET}" 'http://localhost:8080'
        ;;
    'cannot probe')
        emit_dev_kv 'Nextcloud' "${DIM}cannot probe — ${PROBE_REASON}${RESET}"
        ;;
    'not running')
        emit_dev_kv 'Nextcloud' "${RED}not running${RESET}" '→ run: make nc-up'
        ;;
    *)
        # Non-running, no health (exited/created/...). %%|* strips the
        # trailing '|<health>' since health doesn't apply; same idiom in
        # the other service catch-alls below.
        emit_dev_kv 'Nextcloud' "${RED}${NC_STATE%%|*}${RESET}" '→ run: make nc-up'
        ;;
esac

# Postgres
PG_STATE="$(service_state postgres)"
case "$PG_STATE" in
    running\|healthy)
        emit_dev_kv 'Postgres' "${GREEN}running, healthy${RESET}"
        ;;
    running\|*)
        emit_dev_kv 'Postgres' "${YELLOW}running, $(printf '%s' "$PG_STATE" | cut -d'|' -f2)${RESET}"
        ;;
    'cannot probe')
        emit_dev_kv 'Postgres' "${DIM}cannot probe — ${PROBE_REASON}${RESET}"
        ;;
    'not running')
        emit_dev_kv 'Postgres' "${RED}not running${RESET}"
        ;;
    *)
        emit_dev_kv 'Postgres' "${RED}${PG_STATE%%|*}${RESET}"
        ;;
esac

# Build-pool — long-running dev-builder for benign make targets,
# lazy-started on dc-run.sh demand. "Not running" is the normal steady
# state → DIM (informational), not RED. Running states carry a
# `make down` hint because the operator may not realise the lazy pool
# is up consuming resources.
case "$POOL_STATE_PRE" in
    running*)
        case "$POOL_IMAGE_STATE" in
            current)
                emit_dev_kv 'Build-pool' "${GREEN}running${RESET}" '→ run: make down to stop'
                ;;
            stale)
                emit_dev_kv 'Build-pool' "${YELLOW}running, stale image${RESET}" '(recycle pending on next build command) → run: make down to stop'
                ;;
            *)
                # Running but image-axis probe yielded nothing (inspect
                # failed) — don't fabricate a current/stale verdict.
                emit_dev_kv 'Build-pool' "${YELLOW}running, image probe failed${RESET}" '→ run: make down to stop'
                ;;
        esac
        ;;
    'cannot probe')
        emit_dev_kv 'Build-pool' "${DIM}cannot probe — ${PROBE_REASON}${RESET}"
        ;;
    'not running')
        emit_dev_kv 'Build-pool' "${DIM}not running${RESET}" '(lazy — starts on next build command)'
        ;;
    *)
        emit_dev_kv 'Build-pool' "${YELLOW}${POOL_STATE_PRE%%|*}${RESET}"
        ;;
esac

# Ephemerals — running one-off dev-builder containers (install-touching
# ops via EPHEMERAL=1: assemble MODE=force, deps, setup, ...). Listed only
# when ≥1 is running: they're transient (`compose run --rm` AutoRemove
# self-removes them on completion), so a steady-state "none" would be
# noise. Neutral, not coloured: an in-flight install is normal, not a
# fault — but it holds the app's .build/.lock, so the operator wedged on a
# lock sees the holder here and on the app's Lock line. Force-killable with
# `make down EPHEMERALS=1` if the operator knows it's unwanted (down is the
# lightest teardown that reaps it; clean/distclean reap too but lose more).
if [ -n "$EPHEMERAL_RUNNING" ]; then
    eph_count="$(printf '%s\n' "$EPHEMERAL_RUNNING" | grep -c .)"
    eph_detail=""
    while IFS='|' read -r eph_name eph_status eph_app; do
        [ -n "$eph_name" ] || continue
        eph_detail="${eph_detail}${eph_detail:+, }${eph_app:-no app} (${eph_name}, ${eph_status})"
    done <<EOF
$EPHEMERAL_RUNNING
EOF
    emit_dev_kv 'Ephemerals' "${eph_count} running" "$eph_detail"
fi

# Tunnel — compose service running ssh -L. Health from the service's
# healthcheck (TCP-probes the ssh -L listener in-container), so
# "healthy" iff ssh authenticated and forwards are up — which is why no
# NC-side reachability probe is needed (it would only add an NC-up
# dependency to a tunnel row). TUNNEL_TARGET (which dev1, off the
# hubs-apps.tunnel.server label) is spliced via _tunnel_to_frag so the
# operator knows where seed/test traffic routes.
_tunnel_to_frag=""
[ -n "$TUNNEL_TARGET" ] && _tunnel_to_frag=" → ${TUNNEL_TARGET}"
case "$TUNNEL_STATE_PRE" in
    running\|healthy)
        emit_dev_kv 'Tunnel' "${GREEN}running${_tunnel_to_frag}, healthy${RESET}" 'ssh forwards 10143/10025/10026 established'
        ;;
    running\|starting)
        # Inside the healthcheck start_period — ssh handshake not done.
        # Transient: flips to healthy or escalates to unhealthy.
        emit_dev_kv 'Tunnel' "${YELLOW}running${_tunnel_to_frag}, starting${RESET}" '(ssh handshake in progress)'
        ;;
    running\|unhealthy|running\|)
        # Healthcheck failing — ssh listener not accepting, so ssh hasn't
        # authenticated or lost the remote. Empty health shouldn't fire
        # (healthcheck is declared) but is surfaced if it does.
        emit_dev_kv 'Tunnel' "${RED}running${_tunnel_to_frag}, ssh forwards down${RESET}" "check \`docker logs ${TUNNEL_CONTAINER_NAME}\`"
        ;;
    running\|*)
        # Unrecognized health value (newer compose) — emit honestly.
        emit_dev_kv 'Tunnel' "${YELLOW}running${_tunnel_to_frag}, $(printf '%s' "$TUNNEL_STATE_PRE" | cut -d'|' -f2)${RESET}"
        ;;
    'cannot probe')
        emit_dev_kv 'Tunnel' "${DIM}cannot probe — ${PROBE_REASON}${RESET}"
        ;;
    'not running')
        emit_dev_kv 'Tunnel' "${DIM}not running${RESET}" '→ run: make tunnel SERVER=<host>'
        ;;
    *)
        # Non-running (exited/created/...); the restart policy may be
        # cycling it — point the operator at the logs.
        emit_dev_kv 'Tunnel' "${YELLOW}${TUNNEL_STATE_PRE%%|*}${_tunnel_to_frag}${RESET}" "(check \`docker logs ${TUNNEL_CONTAINER_NAME}\` if persistent)"
        ;;
esac
unset _tunnel_to_frag

# Seeded state — sdkmc config + autohandlaggare1/2 presence. The seed
# SERVER=<host> isn't stored, so org code (sdkmc organizationExtension)
# stands in as the seed identifier. Bail on "never seeded" when the
# gather failed: empty SDKMC_ORG then means "couldn't ask", not "not seeded".
nc_reason="$NC_REASON"
if [ -n "$nc_reason" ]; then
    emit_dev_kv 'Seeded' "${DIM}cannot probe — ${nc_reason}${RESET}"
else
    SDKMC_ORG="$(nc_section SDKMC_ORG | head -n 1)"
    SDKMC_IMAP="$(nc_section SDKMC_IMAP | head -n 1)"
    SDKMC_SMTP="$(nc_section SDKMC_SMTP | head -n 1)"
    USER_A1="$(nc_section USER_AUTO1 | head -n 1)"
    USER_A2="$(nc_section USER_AUTO2 | head -n 1)"
    if [ -z "$SDKMC_ORG" ]; then
        emit_dev_kv 'Seeded' "${YELLOW}never seeded${RESET}" '→ run: make seed SERVER=<host>'
    else
        USERS_DESC=""
        if [ "$USER_A1" = "present" ] && [ "$USER_A2" = "present" ]; then
            USERS_DESC='autohandlaggare1+2 present'
        elif [ "$USER_A1" = "present" ] || [ "$USER_A2" = "present" ]; then
            USERS_DESC='autohandlaggare1+2 partial'
        else
            USERS_DESC='autohandlaggare1+2 absent'
        fi
        # Expected sdkmc imap/smtpHost is the literal "tunnel" (compose
        # service-name DNS from inside NC). Pre-rebuild seeds wrote the
        # bridge gateway IP, so an un-re-seeded post-rebuild deployment
        # surfaces an IP-looking value in the "was" position.
        if [ "$SDKMC_IMAP" != "tunnel" ]; then
            emit_dev_kv 'Seeded' "${YELLOW}sdkmc imapHost drifted${RESET}" "(was ${SDKMC_IMAP:-empty}, expected tunnel) → run: make seed SERVER=<host>"
        elif [ "$SDKMC_SMTP" != "tunnel" ]; then
            emit_dev_kv 'Seeded' "${YELLOW}sdkmc smtpHost drifted${RESET}" "(was ${SDKMC_SMTP:-empty}, expected tunnel) → run: make seed SERVER=<host>"
        elif [ "$USER_A1" != "present" ] || [ "$USER_A2" != "present" ]; then
            emit_dev_kv 'Seeded' "${YELLOW}seeded (org: ${SDKMC_ORG}), ${USERS_DESC}${RESET}" '→ run: make seed SERVER=<host>'
        else
            emit_dev_kv 'Seeded' "${GREEN}seeded (org: ${SDKMC_ORG}), ${USERS_DESC}, sdkmc imap/smtp = tunnel${RESET}"
        fi
    fi
fi

# --- §B IDE ---

printf '\n%sIDE%s\n' "$BOLD" "$RESET"

# Dev-builder long-running endpoint — shared idle container hosting the
# IDE-attach toolchain. "Not running" is the steady state when nobody's
# attaching → DIM (informational), not YELLOW.
DB_STATE="$(service_state dev-builder)"
# Attach annotation — "(IDE attached)"/"(no IDE attached)" so the
# operator knows whether `make ide-down` kills an active session. Empty
# when the vscode-server probe couldn't run.
_db_attach=""
if [ -n "$DEVBUILDER_VSCODE_COUNT" ]; then
    if [ "$DEVBUILDER_VSCODE_COUNT" -gt 0 ]; then
        _db_attach="(IDE attached)"
    else
        _db_attach="(no IDE attached)"
    fi
fi
case "$DB_STATE" in
    running*)
        emit_dev_kv 'Dev-builder' "${GREEN}running${RESET}" "$_db_attach"
        ;;
    'cannot probe')
        emit_dev_kv 'Dev-builder' "${DIM}cannot probe — ${PROBE_REASON}${RESET}"
        ;;
    'not running')
        emit_dev_kv 'Dev-builder' "${DIM}not running${RESET}" '→ run: make ide-up to start'
        ;;
    *)
        emit_dev_kv 'Dev-builder' "${YELLOW}${DB_STATE%%|*}${RESET}"
        ;;
esac
unset _db_attach

# IDE wiring — roll-up of the seven §B wiring probes; details land as
# §D MISMATCH findings.
emit_dev_kv 'IDE wiring' "$WIRING_SUMMARY"

# Workspace file — regenerated on every `make ide-up`. Missing = no
# ide-up has run on this checkout; informational ("not present" is the
# fresh-clone steady state), not a finding.
WORKSPACE_FILE="$PLATFORM_ROOT/hubs-apps.code-workspace"
if [ -f "$WORKSPACE_FILE" ]; then
    emit_dev_kv 'Workspace' "${GREEN}present (hubs-apps.code-workspace)${RESET}"
else
    emit_dev_kv 'Workspace' "${DIM}not present${RESET}" '→ run: make ide-up (regenerates)'
fi

# Developer UID/GID — host UID/GID vs .env's DEVELOPER_UID/GID. Mismatch
# means container bind-mounts land as the wrong owner, producing
# wrong-owner artifacts in apps/<X>/.build/. The dev-builder image's
# baked UID and the NC container UID are deliberately NOT checked: the
# former self-corrects on the next VERSION bump, the latter is bind-mount
# write behavior the user can verify directly.
ENV_UID="$(read_env_var DEVELOPER_UID)"
ENV_GID="$(read_env_var DEVELOPER_GID)"
if [ -n "$ENV_UID" ] && [ "$ENV_UID" != "$HOST_UID" ]; then
    emit_dev_kv 'Dev UID' "${YELLOW}mismatch: host=${HOST_UID}, .env=${ENV_UID}${RESET}" 'align .env to host (or host to .env)'
elif [ -n "$ENV_GID" ] && [ "$ENV_GID" != "$HOST_GID" ]; then
    emit_dev_kv 'Dev UID' "${YELLOW}mismatch: host GID=${HOST_GID}, .env GID=${ENV_GID}${RESET}" 'align .env to host (or host to .env)'
else
    emit_dev_kv 'Dev UID' "${GREEN}OK (${HOST_UID}:${HOST_GID})${RESET}"
fi

# `make l10n` host-floor carve-out. l10n is the one target that runs
# host-direct (not via dc-run.sh): its `fix` subcommand spawns the
# operator's local Claude CLI, which needs host auth + MCP config.
# Deps: python3 + polib + yaml (the l10n script) + claude (Anthropic CLI).
#
# Skipped in-container (IN_BUILDER): claude is host-only by design, so
# the probe would fire a misleading "missing: claude" — and `make l10n`
# from inside is blocked by _REQUIRE_HOST anyway.
L10N_MISSING=()
if [ -z "${IN_BUILDER:-}" ]; then
    if ! command -v claude >/dev/null 2>&1; then
        L10N_MISSING+=("claude")
    fi
    # polib/yaml are Python modules — importability is the only honest
    # test, and it needs python3 first (missing → both modules missing).
    if ! command -v python3 >/dev/null 2>&1; then
        L10N_MISSING+=("python3")
        L10N_MISSING+=("python3-polib")
        L10N_MISSING+=("python3-yaml")
    else
        if ! python3 -c 'import polib' >/dev/null 2>&1; then
            L10N_MISSING+=("python3-polib")
        fi
        if ! python3 -c 'import yaml' >/dev/null 2>&1; then
            L10N_MISSING+=("python3-yaml")
        fi
    fi
    if [ "${#L10N_MISSING[@]}" -gt 0 ]; then
        miss_str="$(printf '%s, ' "${L10N_MISSING[@]+"${L10N_MISSING[@]}"}")"
        miss_str="${miss_str%, }"
        emit_dev_kv 'l10n host' "${YELLOW}missing: ${miss_str}${RESET}" '(needed by make l10n)'
    fi
fi

# SSH agent — two carve-outs forward the host agent socket into a
# container: (a) git from inside the IDE dev-builder (bound at
# /ssh-agent, bypassing Dev Containers' unreliable SSH_AUTH_SOCK
# forwarding); (b) `make tunnel`'s ssh -L. Both need a host agent with
# keys; this row surfaces its state for either.
#
# Probe-socket selection, priority order:
#   1. SSH_AUTH_SOCK + real socket — host shell with a session agent,
#      and the in-container case (the /ssh-agent bind makes it probeable).
#   2. else HOST_SSH_AUTH_SOCK + real socket — host shell where login
#      didn't export SSH_AUTH_SOCK (Claude Code's bash, cron) but the
#      Makefile auto-detected and exported it. Never fires in-container
#      (tier 1 wins, or HOST_SSH_AUTH_SOCK is empty there).
#   3. else /run/user/<uid>/openssh_agent — self-derived fallback
#      mirroring the Makefile auto-detect, so a direct `bash status.sh`
#      (no make export) still finds the agent. Linux-only; macOS has no
#      host-shell agent at this path (it reaches containers via
#      /run/host-services), so no Mac path change.
#
# ssh-add rc=2 (agent unreachable) is distinct from rc=1 (no keys);
# both YELLOW, different text.
SSH_AGENT_RC=255
SSH_AGENT_OUT=""
SSH_KEY_COUNT=0
SSH_PROBE_SOCK=""
if [ -n "${SSH_AUTH_SOCK:-}" ] && [ -S "$SSH_AUTH_SOCK" ]; then
    SSH_PROBE_SOCK="$SSH_AUTH_SOCK"
elif [ -n "${HOST_SSH_AUTH_SOCK:-}" ] && [ -S "$HOST_SSH_AUTH_SOCK" ]; then
    SSH_PROBE_SOCK="$HOST_SSH_AUTH_SOCK"
elif [ -S "/run/user/$(id -u)/openssh_agent" ]; then
    SSH_PROBE_SOCK="/run/user/$(id -u)/openssh_agent"
fi
if [ -n "$SSH_PROBE_SOCK" ]; then
    # `&& ... || SSH_AGENT_RC=$?` captures ssh-add's rc without tripping
    # set -e; 2>&1 grabs both keys (stdout) and errors (stderr). The
    # subshell SSH_AUTH_SOCK override probes the selected socket without
    # disturbing the script's env.
    SSH_AGENT_OUT="$(SSH_AUTH_SOCK="$SSH_PROBE_SOCK" ssh-add -l 2>&1)" && SSH_AGENT_RC=0 || SSH_AGENT_RC=$?
    if [ "$SSH_AGENT_RC" = "0" ]; then
        # Key lines start "<bits> ..." — the leading bit-count separates
        # them from error lines. grep -c always emits a count (rc=1 on
        # no match; || true for set -e).
        SSH_KEY_COUNT="$(printf '%s\n' "$SSH_AGENT_OUT" | grep -cE '^[0-9]+ ' || true)"
    fi
fi
# Host-path hint on every branch: HOST_SSH_AUTH_SOCK is what make tunnel
# binds and compose.dev.yml sources, so showing it catches a stale or
# mis-resolved value in one place.
SSH_HOST_HINT=" ${DIM}(host: ${HOST_SSH_AUTH_SOCK:-none configured})${RESET}"
if [ "$SSH_KEY_COUNT" -gt 0 ]; then
    emit_dev_kv 'SSH agent' "${GREEN}reachable, ${SSH_KEY_COUNT} key(s) loaded${RESET}${SSH_HOST_HINT}"
elif [ "$SSH_AGENT_RC" = "1" ] || [ "$SSH_AGENT_RC" = "0" ]; then
    # rc=1 = no identities. rc=0 with 0 keys (reachable but no parseable
    # key lines) gets the same message.
    emit_dev_kv 'SSH agent' "${YELLOW}reachable, no keys loaded${RESET}${SSH_HOST_HINT}" '→ run: ssh-add ~/.ssh/<your-key>'
elif [ -n "$SSH_PROBE_SOCK" ] || [ -n "${SSH_AUTH_SOCK:-}" ]; then
    # A path is configured but no agent answered (rc=2/other, or
    # SSH_AUTH_SOCK set but not a probeable socket so rc stayed 255).
    # Keying on SSH_PROBE_SOCK is what makes a host-fallback rc=2 read
    # as "unreachable" rather than collapsing into "not running".
    emit_dev_kv 'SSH agent' "${YELLOW}agent socket present but unreachable${RESET}${SSH_HOST_HINT}" '(stale/dead socket?)'
else
    emit_dev_kv 'SSH agent' "${YELLOW}not running${RESET}${SSH_HOST_HINT}" '→ start agent + run: ssh-add ~/.ssh/<your-key>'
fi

# Docker socket — compose.dev.yml binds /var/run/docker.sock into the
# dev-builder so compose-orchestrating make targets work from VSCode's
# integrated terminal. Two preconditions: socket exists, and the host
# user is in its owning group (so compose group_add gets a real GID AND
# host docker CLI works sans sudo). Facts computed in the Docker-socket
# cache above.
if [ ! -S "$DOCKER_SOCK_PATH" ]; then
    emit_dev_kv 'Docker socket' "${YELLOW}not present at ${DOCKER_SOCK_PATH}${RESET}" '(IDE-attach make commands need this)'
elif [ "$DOCKER_SOCK_USER_IN_GROUP" = "1" ]; then
    emit_dev_kv 'Docker socket' "${GREEN}OK (GID ${DOCKER_SOCK_GID})${RESET}"
else
    emit_dev_kv 'Docker socket' "${YELLOW}host user not in docker group (GID ${DOCKER_SOCK_GID})${RESET}" '→ add user to docker group'
fi

# --- §C Apps ---

printf '\n%sApps%s\n' "$BOLD" "$RESET"

if [ "${#APPS[@]}" -eq 0 ]; then
    printf '  %s(none cloned — run: make setup)%s\n\n' "$DIM" "$RESET"
else
    # Two passes: pass 1 probes each app into APP_BLOCK[i] (KV lines) +
    # APP_FINDS[i] (tab-delimited finding records); pass 2 emits the
    # severity-sorted scan header, then per-app blocks. The block/finds
    # split lets all KV print before any finding regardless of which
    # probe wrote which (probes interleave KV + findings). Tier numeric
    # prefix gives a stable severity sort (RECOVER > DRIFT > MISMATCH).
    #
    # Helpers read CUR_I (the loop index) via global — bash 3.2 has no
    # closures.

    APP_BLOCK=()
    APP_FINDS=()
    CUR_I=0

    add_kv() {
        APP_BLOCK[$CUR_I]="${APP_BLOCK[$CUR_I]}$(printf '    %-11s%s' "${1}:" "$2")
"
    }

    # severity_tier SEVERITY → 0/1/2/3 (RECOVER/DRIFT/MISMATCH/ADVISORY),
    # the sort key.
    severity_tier() {
        case "$1" in
            RECOVER)  printf '0' ;;
            DRIFT)    printf '1' ;;
            MISMATCH) printf '2' ;;
            ADVISORY) printf '3' ;;
            *)        printf '9' ;;
        esac
    }

    add_finding() {
        local severity="$1" condition="$2" rest="${3:-}"
        # Tab-delimited record (emit_finds reformats, scan header counts
        # column 2). condition/rest contain no tabs — all call sites use
        # plain English + paths.
        APP_FINDS[$CUR_I]="${APP_FINDS[$CUR_I]}$(severity_tier "$severity")	${severity}	${condition}	${rest}
"
    }

    # emit_finds → APP_FINDS[$CUR_I], tier-sorted, as the two-line
    # "→ SEVERITY: condition / pad rest" shape.
    emit_finds() {
        local raw="${APP_FINDS[$CUR_I]}"
        [ -n "$raw" ] || return 0
        # LC_ALL=C for byte-ordered sort stability across locales.
        printf '%s' "$raw" | LC_ALL=C sort -t$'\t' -k1,1n -s | while IFS=$'\t' read -r tier severity condition rest; do
            # Continuation pad = 8 + len(severity) cells ("    → " + ": ").
            # The arrow is 1 display cell despite 3 UTF-8 bytes.
            local pad
            pad="$(printf '%*s' $((8 + ${#severity})) '')"
            local color="$YELLOW"
            case "$severity" in
                RECOVER) color="$RED" ;;
                ADVISORY) color="$DIM" ;;
            esac
            printf '    → %s%s%s: %s\n' "$color" "$severity" "$RESET" "$condition"
            if [ -n "$rest" ]; then
                printf '%s%s\n' "$pad" "$rest"
            fi
        done
    }

    i=0
    while [ "$i" -lt "${#APPS[@]}" ]; do
        app="${APPS[$i]}"
        type="${APP_TYPES[$i]}"
        CUR_I=$i
        APP_BLOCK[$i]=""
        APP_FINDS[$i]=""
        app_dir="$PLATFORM_ROOT/apps/$app"
        build_dir="$app_dir/.build"

        # Newline separates this block from the prior app's in the emit.
        APP_BLOCK[$i]="$(printf '\n  %s (%s)' "$app" "$type")
"

        # --- Type-agnostic checks (run before the type-if so neither
        # branch duplicates them) ---

        # NAME-vs-info.xml-<id>. NC enables by <id>, NOT dirname — a
        # mismatch silently half-installs: the apps-extra symlink lands
        # but `occ app:enable <dirname>` finds no such <id>. Quilt <id>
        # is under upstream/ (not ours); standalone is at app root.
        if [ "$type" = "quilt" ]; then
            info_xml="$app_dir/upstream/appinfo/info.xml"
        else
            info_xml="$app_dir/appinfo/info.xml"
        fi
        if [ -f "$info_xml" ]; then
            # -F'<id>|</id>' lands the value in $2; <id> is unique so
            # first match wins. `|| upstream_id=""` keeps an awk failure
            # (file vanished) from tripping set -e — empty skips the check.
            upstream_id="$(awk -F '<id>|</id>' '/<id>/{print $2; exit}' "$info_xml" 2>/dev/null)" || upstream_id=""
            if [ -n "$upstream_id" ] && [ "$upstream_id" != "$app" ]; then
                add_finding 'MISMATCH' \
                    "apps/${app}/ directory name doesn't match info.xml <id>='${upstream_id}' (NC enables apps by <id>; this app can't be loaded under '${app}')." \
                    "rename apps/${app}/ → apps/${upstream_id}/ (or remove apps/${app}/ and re-clone via: make new-app UPSTREAM=<url> VERSION=<v> — name is derived from upstream <id>)"
            fi
        fi

        # state-integrity-arc branch — the chaos-arc wrapper creates it
        # on apps/mail; a killed suite leaves the WT stuck on it and the
        # wrapper's next pre-check refuses until cleaned. ADVISORY: most
        # apps never see it, and it's a hand-cleanup, not a recovery.
        if [ -d "$app_dir/.git" ]; then
            # `|| current_branch=""` keeps a corrupt .git (rev-parse
            # rc=128) from tripping set -e — empty skips the check;
            # corruption surfaces via the quilt git-failed findings.
            current_branch="$(git -C "$app_dir" rev-parse --abbrev-ref HEAD 2>/dev/null)" || current_branch=""
            if [ "$current_branch" = "state-integrity-arc" ]; then
                add_finding 'ADVISORY' \
                    "apps/${app}/ is on branch state-integrity-arc (chaos arc test was killed mid-run, leaving this WT in an arc-mid-state)." \
                    "Run: git -C apps/${app} checkout <main-branch> && git -C apps/${app} branch -D state-integrity-arc && rm -f apps/${app}/scratch-thinking.md && cd apps/${app} && make assemble MODE=force"
            fi
        fi

        if [ "$type" = "quilt" ]; then
            # --- Source line ---
            # patches/series count (non-blank, non-comment) + baseline
            # timestamp. awk-counts (not wc -l) for series files with no
            # trailing newline.
            if [ -f "$app_dir/patches/series" ]; then
                series_count="$(awk 'NF && !/^[[:space:]]*#/ {n++} END {print n+0}' "$app_dir/patches/series")"
            else
                series_count=0
            fi
            # Every series entry needs a patch file on disk. A missing one
            # = series/patches/ drift, which aborts make diff and make
            # quilt — surface it here so the cause shows in status, not
            # just when those commands crash.
            if [ -f "$app_dir/patches/series" ]; then
                while read -r _series_patch; do
                    [ -n "$_series_patch" ] || continue
                    case "$_series_patch" in \#*) continue ;; esac
                    if [ ! -f "$app_dir/patches/$_series_patch" ]; then
                        add_finding 'DRIFT' \
                            "patches/series references '${_series_patch}' but patches/${_series_patch} doesn't exist — series and patches/ are out of sync (make diff / make quilt abort on this)." \
                            "restore the patch (git -C apps/${app} checkout -- patches/${_series_patch}) or remove the stale series entry"
                    fi
                done < "$app_dir/patches/series"
            fi
            # Baseline: readable with a timestamp, or not. Missing and
            # corrupt both land in not-readable; the cascading-recovery
            # block below emits the RECOVER finding.
            baseline_present=0
            baseline_ts=""
            if [ -d "$build_dir/.git" ] \
              && git -C "$build_dir" rev-parse --verify --quiet baseline >/dev/null 2>&1 \
              && ts="$(git -C "$build_dir" log -1 --format=%cd --date=format:'%Y-%m-%d %H:%M:%S' baseline 2>/dev/null)"; then
                baseline_present=1
                baseline_ts="$ts"
            fi
            if [ "$baseline_present" = "1" ]; then
                add_kv 'Source' "${series_count} patches, baseline ${baseline_ts}"
            else
                add_kv 'Source' "${series_count} patches, ${DIM}baseline not present${RESET}"
            fi

            # --- Build line + drift findings ---
            # Carriers (node_modules, vendor, lib/Vendor, webpack configs)
            # gated on existence; a missing carrier an app needs raises a
            # finding.
            if [ ! -d "$build_dir" ]; then
                # Whole tree gone (never assembled, or moved/deleted). Name the
                # root cause once — the per-carrier / webpack-config "absent"
                # findings below would otherwise cascade, each missing only
                # because the dir is.
                build_pieces="${RED}.build/ absent — not assembled${RESET}"
                add_finding 'DRIFT' \
                    ".build/ is absent — ${app} isn't assembled (a quilt app needs .build/ to build or be served)." \
                    "Run: cd apps/${app} && make assemble MODE=force"
            else
            build_pieces=""
            sep=""
            if [ -d "$build_dir/node_modules" ]; then
                build_pieces="${sep}node_modules ${GREEN}✓${RESET}"
                sep=", "
            elif [ -f "$build_dir/package.json" ]; then
                build_pieces="${sep}node_modules ${RED}absent${RESET}"
                sep=", "
            fi
            if [ -d "$build_dir/vendor" ]; then
                build_pieces="${build_pieces}${sep}vendor ${GREEN}✓${RESET}"
                sep=", "
            elif [ -f "$build_dir/composer.json" ]; then
                build_pieces="${build_pieces}${sep}vendor ${RED}absent${RESET}"
                sep=", "
            fi
            # Mozart wiring from the batched probe. yes → check lib/Vendor
            # (DRIFT if absent); no → no expectation; unknown → inline
            # "cannot probe" only, no per-app finding (the §B Dev-builder
            # line + §D RECOVER already explain the degradation — per-app
            # advisories would repeat it N times).
            mozart_state="$(mozart_wired "$app")"
            case "$mozart_state" in
                yes)
                    if [ -d "$build_dir/lib/Vendor" ]; then
                        build_pieces="${build_pieces}${sep}lib/Vendor ${GREEN}✓${RESET}"
                    else
                        build_pieces="${build_pieces}${sep}lib/Vendor ${RED}absent${RESET}"
                        add_finding 'DRIFT' \
                            "${build_dir#$PLATFORM_ROOT/}/lib/Vendor/ absent on a Mozart-wiring app (composer post-install hook failed silently)." \
                            "Run: cd apps/${app} && make assemble MODE=force"
                    fi
                    sep=", "
                    ;;
                unknown)
                    build_pieces="${build_pieces}${sep}lib/Vendor ${DIM}cannot probe${RESET}"
                    sep=", "
                    ;;
                error)
                    build_pieces="${build_pieces}${sep}lib/Vendor ${YELLOW}cannot probe (composer.json malformed)${RESET}"
                    add_finding 'DRIFT' \
                        ".build/composer.json is present but not valid JSON — Mozart wiring (and the lib/Vendor check) can't be determined." \
                        "Run: cd apps/${app} && make assemble MODE=force (or fix overlay/composer.json)"
                    sep=", "
                    ;;
                # "no" → omit the lib/Vendor segment entirely.
            esac
            # Webpack config drift — content cmp against platform.
            webpack_drift_pieces=""
            for wp in webpack.itsl.js webpack.hmr.js; do
                if [ -f "$build_dir/$wp" ]; then
                    if cmp -s "$build_dir/$wp" "$PLATFORM_ROOT/webpack/$wp"; then
                        :  # matches
                    else
                        webpack_drift_pieces="${webpack_drift_pieces} ${wp}"
                        add_finding 'DRIFT' \
                            ".build/${wp} differs from webpack/${wp}." \
                            "Run: cd apps/${app} && make assemble MODE=force"
                    fi
                else
                    webpack_drift_pieces="${webpack_drift_pieces} ${wp}(absent)"
                    add_finding 'DRIFT' \
                        "Platform file .build/${wp} absent (MODE=force is the sole installer)." \
                        "Run: cd apps/${app} && make assemble MODE=force"
                fi
            done
            if [ -z "$webpack_drift_pieces" ]; then
                build_pieces="${build_pieces}${sep}webpack configs match platform"
            else
                build_pieces="${build_pieces}${sep}webpack configs ${YELLOW}DRIFT${RESET}"
            fi
            fi  # close the ".build/ absent" guard around the carrier checks
            add_kv 'Build' "$build_pieces"

            # --- vendor-bin/<tool>/vendor presence vs composer.lock ---
            # One DRIFT finding per tool whose vendor/ is absent.
            if [ -d "$build_dir/vendor-bin" ]; then
                for vb_lock in "$build_dir"/vendor-bin/*/composer.lock; do
                    [ -f "$vb_lock" ] || continue
                    vb_dir="$(dirname "$vb_lock")"
                    tool="$(basename "$vb_dir")"
                    if [ ! -d "$vb_dir/vendor" ]; then
                        add_finding 'DRIFT' \
                            ".build/vendor-bin/${tool}/vendor/ absent (composer install failed silently)." \
                            "Run: cd apps/${app} && make assemble MODE=force"
                    fi
                done
            fi

            # --- Composer / package lockfile supply-chain drift ---
            # .build/<lock> must byte-match its source. Comparison target:
            # overlay/<lock> if present (ITSL-vetted pins, copied not
            # symlinked since composer/npm won't follow symlinked
            # lockfiles), else upstream/<lock>. Drift means the installer
            # regenerated the lock — a supply-chain leak (it pulled current
            # registry versions instead of the pinned set).
            for lockf in composer.lock package-lock.json; do
                [ -f "$build_dir/$lockf" ] || continue
                src=""
                src_label=""
                if [ -f "$app_dir/overlay/$lockf" ]; then
                    src="$app_dir/overlay/$lockf"
                    src_label="overlay/${lockf}"
                elif [ -f "$app_dir/upstream/$lockf" ]; then
                    src="$app_dir/upstream/$lockf"
                    src_label="upstream/${lockf}"
                fi
                if [ -n "$src" ] && ! cmp -s "$build_dir/$lockf" "$src"; then
                    add_finding 'DRIFT' \
                        ".build/${lockf} differs from ${src_label} (supply-chain leak — installer regenerated the lock)." \
                        "investigate before re-running; make diff shows the change"
                fi
            done

            # --- .git/info/exclude content check ---
            # MODE=force seeds four canonical entries; drift means
            # platform files won't be filtered from baseline. grep -qxF
            # in the if-test so a no-match rc=1 doesn't trip set -e.
            if [ -d "$build_dir/.git" ]; then
                exclude_file="$build_dir/.git/info/exclude"
                exclude_ok=1
                if [ ! -f "$exclude_file" ]; then
                    exclude_ok=0
                else
                    for entry in '/.lock' '/Makefile' '/webpack.itsl.js' '/webpack.hmr.js'; do
                        if ! grep -qxF "$entry" "$exclude_file"; then
                            exclude_ok=0
                            break
                        fi
                    done
                fi
                if [ "$exclude_ok" != "1" ]; then
                    add_finding 'DRIFT' \
                        ".build/.git/info/exclude missing or drifted from canonical entries (/.lock, /Makefile, /webpack.itsl.js, /webpack.hmr.js)." \
                        "Run: cd apps/${app} && make assemble MODE=force"
                fi
            fi

            # --- .build/.git presence + baseline ancestry ---
            # Cascading (mutually exclusive) recovery findings: no .git,
            # then unreadable baseline, then baseline-not-ancestor-of-HEAD.
            # All recover via MODE=force.
            if [ -d "$build_dir" ]; then
                if [ ! -d "$build_dir/.git" ]; then
                    add_finding 'RECOVER' \
                        ".build/ present but .build/.git/ missing (interrupted MODE=force)." \
                        "Run: cd apps/${app} && make assemble MODE=force"
                elif [ "$baseline_present" != "1" ]; then
                    add_finding 'RECOVER' \
                        ".build/.git/ present but baseline tag missing or unreadable (interrupted MODE=force, or corrupt tag/object)." \
                        "Run: cd apps/${app} && make assemble MODE=force"
                elif ! git -C "$build_dir" merge-base --is-ancestor baseline HEAD 2>/dev/null; then
                    add_finding 'RECOVER' \
                        "baseline tag in .build/.git not reachable from HEAD (corrupt history)." \
                        "Run: cd apps/${app} && make assemble MODE=force"
                fi
            fi

            # --- .build/ ownership ---
            # Container bind-mount writes must land as host UID:GID (the
            # rebuild's UID/GID-propagation invariant). A MODE=force run
            # with wrong DEVELOPER_UID/GID (or raw docker bypassing make's
            # auto-detect) leaves .build/ wrong-owned → cryptic EACCES on
            # later edits. The .build/ root stat is authoritative (whole
            # tree shares it). `ls -nd` not stat: portable across GNU/BSD.
            if [ -d "$build_dir" ]; then
                # `|| build_owner=""` for the [ -d ]-to-ls race window
                # (build_dir vanished); empty skips the comparison.
                build_owner="$(ls -nd "$build_dir" 2>/dev/null | awk '{print $3":"$4}')" || build_owner=""
                expected_owner="${HOST_UID}:${HOST_GID}"
                if [ -n "$build_owner" ] && [ "$build_owner" != "$expected_owner" ]; then
                    add_finding 'DRIFT' \
                        ".build/ owned by ${build_owner} (expected ${expected_owner} — host UID:GID; bind-mount writes from containers will land as the wrong user, edits will EACCES)." \
                        "Run: cd apps/${app} && make assemble MODE=force (from host with matching DEVELOPER_UID/GID in .env)"
                fi
            fi

            # --- Pending tag (RECOVER) ---
            # Tag present + readable → normal MODE=recover; present but
            # log fails → .git corruption needing MODE=force.
            if [ -d "$build_dir/.git" ]; then
                if git -C "$build_dir" rev-parse --verify --quiet refs/tags/make-quilt-pending >/dev/null 2>&1; then
                    if age_out="$(git -C "$build_dir" log -1 --format=%cr make-quilt-pending 2>&1)"; then
                        add_finding 'RECOVER' \
                            "make-quilt-pending tag present (${age_out})." \
                            "Run: cd apps/${app} && make assemble MODE=recover"
                    else
                        add_finding 'RECOVER' \
                            "make-quilt-pending tag present but \`git log\` failed (.git corruption — MODE=recover would also fail)." \
                            "Run: cd apps/${app} && make assemble MODE=force"
                    fi
                fi
            fi

            # --- Stash list entries (RECOVER — SIGKILL'd plain assemble) ---
            # Non-zero rc = git corruption (own finding, not silent "no
            # stashes"). Recovery is MODE=discard, not MODE=force:
            # discard's `git stash clear` is the cheap, semantically-correct
            # fix; force would clear them too but at cache-nuke + reinstall
            # cost. The corruption case below still wants MODE=force.
            if [ -d "$build_dir/.git" ]; then
                if stash_out="$(git -C "$build_dir" stash list 2>&1)"; then
                    stash_count="$(printf '%s' "$stash_out" | awk 'END {print NR}')"
                    if [ "$stash_count" -gt 0 ]; then
                        plural=entries
                        [ "$stash_count" = "1" ] && plural=entry
                        add_finding 'RECOVER' \
                            "Orphan stash entries in .build/.git's stash list (SIGKILL'd plain assemble left ${stash_count} ${plural} mid-walk)." \
                            "Run: cd apps/${app} && make assemble MODE=discard"
                    fi
                else
                    add_finding 'RECOVER' \
                        "\`git stash list\` failed on .build/.git (corruption): $(printf '%s' "$stash_out" | head -1)" \
                        "Run: cd apps/${app} && make assemble MODE=force"
                fi
            fi

            # --- .pc/applied-patches vs series count (RECOVER — incomplete push) ---
            # applied == series → normal; applied == 0 → legitimate
            # post-pop / fresh new-app; anything else → incomplete /
            # cross-branch push (the finding). Recovery is plain
            # `make assemble`, not MODE=force: plain assemble's reconcile
            # re-derives .pc/applied-patches to the current series — exactly
            # the case it's designed for — without the force reinstall cost.
            if [ -d "$build_dir/.git" ] && [ -f "$app_dir/patches/series" ] && [ -f "$build_dir/.pc/applied-patches" ]; then
                applied_n="$(awk 'END {print NR}' "$build_dir/.pc/applied-patches")"
                if [ "$applied_n" -ne "$series_count" ] && [ "$applied_n" -ne 0 ]; then
                    add_finding 'RECOVER' \
                        ".pc/applied-patches line count (${applied_n}) differs from patches/series non-blank-non-comment count (${series_count}) (incomplete push)." \
                        "Run: cd apps/${app} && make assemble"
                fi
            fi

            # --- Conflict markers in tracked files (RECOVER) ---
            # diff --check baseline: rc=0 clean, rc=2 issues found (markers
            # + ws on stdout), rc=128+ git failed. Untracked-not-ignored
            # files (ls-files --others --exclude-standard) get grepped too.
            # git-failed is its own finding so corruption doesn't silently
            # mask markers.
            if [ -d "$build_dir/.git" ] && [ "$baseline_present" = "1" ]; then
                conflict_found=0
                if check_out="$(git -C "$build_dir" diff --check baseline 2>&1)"; then
                    : # rc=0, clean
                else
                    check_rc=$?
                    if [ "$check_rc" -ge 128 ]; then
                        add_finding 'RECOVER' \
                            "\`git diff --check baseline\` failed on .build/.git (corruption): $(printf '%s' "$check_out" | head -1)" \
                            "Run: cd apps/${app} && make assemble MODE=force"
                    elif printf '%s' "$check_out" | grep -qE 'conflict marker'; then
                        conflict_found=1
                    fi
                    # ws-only warnings aren't a finding.
                fi
                # Untracked scan. Two-call shape is mandatory: capturing
                # the -z (null-separated) list into a bash var strips the
                # null bytes and corrupts it before xargs -0. First call
                # gets rc/stderr (corruption branch); second streams -z
                # straight into the marker-scan pipeline. No `-r` on xargs:
                # --no-run-if-empty is a GNU extension that errors on BSD
                # xargs (macOS, the untestable host), and it's redundant —
                # on empty input BSD xargs skips the utility entirely while
                # GNU runs grep once against /dev/null stdin; either way no
                # match and no hang, so omitting -r is correct on both.
                if [ "$conflict_found" = "0" ]; then
                    ls_err="$(git -C "$build_dir" ls-files --others --exclude-standard 2>&1 >/dev/null)"
                    ls_rc=$?
                    if [ "$ls_rc" = "0" ]; then
                        if git -C "$build_dir" ls-files --others --exclude-standard -z 2>/dev/null \
                            | (cd "$build_dir" && xargs -0 grep -lE '^(<<<<<<<|=======|>>>>>>>) ' 2>/dev/null) \
                            | grep -q . ; then
                            conflict_found=1
                        fi
                    else
                        add_finding 'RECOVER' \
                            "\`git ls-files\` failed on .build/.git (corruption): $(printf '%s' "$ls_err" | head -1)" \
                            "Run: cd apps/${app} && make assemble MODE=force"
                    fi
                fi
                if [ "$conflict_found" = "1" ]; then
                    add_finding 'RECOVER' \
                        "Conflict markers in tracked or untracked files under .build/ (paused mid-cascading-conflict)." \
                        "resolve markers, then: cd apps/${app} && make quilt"
                fi
            fi

            # --- Dirty patches/ + overlay/ (DRIFT — captured save not committed) ---
            # A cloned app should be a git repo; not-a-repo is its own
            # corruption finding (don't suppress the git absence).
            if [ ! -d "$app_dir/.git" ]; then
                add_finding 'DRIFT' \
                    "apps/${app}/ exists but isn't a git repo (clone broken or scaffold incomplete)." \
                    "investigate apps/${app}/ — re-clone or re-run make new-app"
            else
                for sub in patches overlay; do
                    if [ -d "$app_dir/$sub" ]; then
                        # Capture rc so a corrupt parent .git doesn't
                        # truncate the report; surface it as its own finding.
                        if dirty_out="$(git -C "$app_dir" status --short -- "$sub" 2>&1)"; then
                            if [ -n "$dirty_out" ]; then
                                add_finding 'DRIFT' \
                                    "apps/${app}/${sub}/ working tree dirty vs HEAD (captured save not committed)." \
                                    "Run: git -C apps/${app} add ${sub}/ && git -C apps/${app} commit"
                            fi
                        else
                            add_finding 'DRIFT' \
                                "apps/${app}/.git failed git status on ${sub}/: $(printf '%s' "$dirty_out" | head -1)" \
                                "investigate apps/${app}/.git for corruption"
                        fi
                    fi
                done
            fi

            # --- Sidecar line + sidecar-related findings ---
            sidecar_frag="$PLATFORM_ROOT/docker/sidecars/${app}.yml"
            sidecar_value=""
            if [ ! -f "$sidecar_frag" ]; then
                sidecar_value='not declared'
            else
                # Read declared mode from fragment.
                declared_mode=""
                if grep -qF 'webpack.hmr.js' "$sidecar_frag"; then
                    declared_mode='hmr'
                elif grep -qF 'webpack.itsl.js' "$sidecar_frag"; then
                    declared_mode='watch'
                fi
                # Container state.
                sidecar_state="$(service_state "sidecar-${app}")"
                case "$sidecar_state" in
                    'cannot probe')
                        sidecar_value="${declared_mode:-?} declared; ${DIM}cannot probe — ${PROBE_REASON}${RESET}"
                        ;;
                    'not running')
                        sidecar_value="${declared_mode:-?} declared; ${YELLOW}container not running${RESET}"
                        ;;
                    *)
                        # state|health — running, exited, etc.
                        state_only="$(printf '%s' "$sidecar_state" | cut -d'|' -f1)"
                        health_only="$(printf '%s' "$sidecar_state" | cut -d'|' -f2)"
                        case "$state_only" in
                            running)
                                if [ "$declared_mode" = "hmr" ]; then
                                    if [ "$health_only" = "healthy" ]; then
                                        sidecar_value="hmr running, port 3000 reachable"
                                    elif [ "$health_only" = "starting" ]; then
                                        sidecar_value="${YELLOW}hmr running, healthcheck starting${RESET}"
                                    else
                                        sidecar_value="${YELLOW}hmr running, healthcheck ${health_only:-unknown}${RESET}"
                                    fi
                                else
                                    sidecar_value="${declared_mode:-?} running"
                                fi
                                ;;
                            exited)
                                sidecar_value="${YELLOW}${declared_mode:-?} declared; container exited${RESET}"
                                # Half-completion finding: fragment + exited container + no in-flight
                                # dev-builder op for THIS app (per-app precision — an unrelated
                                # in-flight op for a different app must not suppress this finding).
                                if ! is_dev_builder_running_for "$app"; then
                                    add_finding 'MISMATCH' \
                                        "sidecar coordination half-completed: fragment exists + container exited + no in-flight assemble (MODE=force's restart-after-wipe didn't run)." \
                                        "Run: cd apps/${app} && make webpack MODE=${declared_mode:-hmr}"
                                fi
                                ;;
                            *)
                                sidecar_value="${YELLOW}${declared_mode:-?} declared; ${state_only}${RESET}"
                                ;;
                        esac
                        # Cross-check the running container's command vs
                        # the fragment mode. Inspect failing on a container
                        # compose ps calls "running" is anomalous — surface
                        # it (consistency unverified), don't silently skip.
                        if [ "$DOCKER_OK" = "1" ] && [ "$state_only" = "running" ] && [ -n "$declared_mode" ]; then
                            if cmd_out="$(docker inspect "sidecar-${app}" --format '{{join .Config.Cmd " "}}' 2>&1)"; then
                                actual_cmd="$cmd_out"
                                if [ "$declared_mode" = "hmr" ] && case "$actual_cmd" in *webpack.hmr.js*) false ;; *) true ;; esac; then
                                    add_finding 'MISMATCH' \
                                        "sidecar-${app} fragment declares hmr mode but running container's command disagrees (fragment edited without re-running make webpack)." \
                                        "Run: cd apps/${app} && make webpack MODE=hmr"
                                elif [ "$declared_mode" = "watch" ] && case "$actual_cmd" in *webpack.itsl.js*) false ;; *) true ;; esac; then
                                    add_finding 'MISMATCH' \
                                        "sidecar-${app} fragment declares watch mode but running container's command disagrees (fragment edited without re-running make webpack)." \
                                        "Run: cd apps/${app} && make webpack MODE=watch"
                                fi
                            else
                                add_finding 'ADVISORY' \
                                    "sidecar-${app} container reports running but \`docker inspect\` failed: $(printf '%s' "$cmd_out" | head -1) (fragment-vs-cmd consistency unverified)" \
                                    "investigate; retry \`make status\`"
                            fi
                        fi
                        ;;
                esac
                # Sidecar-declared-but-no-node_modules MISMATCH
                if [ ! -d "$build_dir/node_modules" ]; then
                    add_finding 'MISMATCH' \
                        "sidecar-${app} declared but .build/node_modules/ absent (webpack will fail on first compile)." \
                        "Run: cd apps/${app} && make assemble MODE=force"
                fi
                # Sidecar-declared-but-no-upstream MISMATCH (stale fragment)
                if [ ! -d "$app_dir/upstream" ]; then
                    add_finding 'MISMATCH' \
                        "sidecar-${app} declared but apps/${app}/upstream/ missing (stale fragment from a deleted app)." \
                        "Run: cd apps/${app} && make webpack MODE=off (or remove apps/${app}/ if obsolete)"
                fi
            fi
            add_kv 'Sidecar' "$sidecar_value"

            # --- Lock line ---
            # Held names its holder (ephemeral / build-pool op) — neutral,
            # not a finding: a held lock is normal in-flight work, and the
            # platform can't tell wanted from interrupted. Other states
            # (free/absent/cannot probe) pass through verbatim.
            lock_v="$(lock_state "$app")"
            if [ "$lock_v" = "held" ]; then
                add_kv 'Lock' "held — $(lock_holder "$app")"
            else
                add_kv 'Lock' "$lock_v"
            fi
        else
            # --- Standalone app: Build line ---
            # No .build/ overlay flow. vendor gated on vendor/autoload.php,
            # not the dir: composer creates the dir mid-install, autoload.php
            # is the final artifact — the honest "vendor usable" signal.
            build_pieces=""
            sep=""
            if [ -d "$app_dir/node_modules" ]; then
                build_pieces="${sep}node_modules ${GREEN}✓${RESET}"
                sep=", "
            elif [ -f "$app_dir/package.json" ]; then
                build_pieces="${sep}node_modules ${RED}absent${RESET}"
                sep=", "
            fi
            if [ -f "$app_dir/vendor/autoload.php" ]; then
                build_pieces="${build_pieces}${sep}vendor ${GREEN}✓${RESET}"
                sep=", "
            elif [ -f "$app_dir/composer.json" ]; then
                build_pieces="${build_pieces}${sep}vendor ${RED}absent${RESET}"
                sep=", "
            fi
            # js/ is the JS build output for standalone apps, built via
            # dc-run.sh under make setup (host runs no npm/npx) and served
            # directly by Apache. make setup's `[ ! -d js ]` gate re-fires
            # when js/ is missing, so it's the supported re-run.
            if [ -d "$app_dir/js" ]; then
                build_pieces="${build_pieces}${sep}js ${GREEN}✓${RESET}"
            elif [ -f "$app_dir/package.json" ]; then
                build_pieces="${build_pieces}${sep}js ${YELLOW}absent${RESET}"
                add_finding 'DRIFT' \
                    "js/ absent (the standalone-app build step in make setup never produced it)." \
                    "Run: make setup (the standalone-build path routes through dc-run.sh — never run npm/npx on host)"
            fi
            add_kv 'Build' "$build_pieces"
        fi

        # --- In NC line (both quilt + standalone) ---
        # Reads the cached APPS_ENABLED / APPS_DISABLED / NC_APPS_EXTRA
        # sections — no per-app exec. Four states: enabled (+HMR-live tag
        # if proxy entry present), disabled (symlink seen but app off),
        # apps-extra symlink present but not registered (aborted wire-up
        # or manual symlink), apps-extra symlink missing (wire-up hasn't
        # run since the clone — NC can't see the app).
        nc_reason="$NC_REASON"
        if [ -n "$nc_reason" ]; then
            # Skip when NC unreachable: empty lists from a failed gather
            # would false-positive every app to "symlink missing".
            add_kv 'In NC' "${DIM}cannot probe — ${nc_reason}${RESET}"
        else
            # APPS_ENABLED/DISABLED/EXTRA are newline-delimited; flatten
            # to space-padded so `case " $list " in *" $app "*)` works.
            enabled_list=" $(nc_section APPS_ENABLED | tr '\n' ' ') "
            disabled_list=" $(nc_section APPS_DISABLED | tr '\n' ' ') "
            apps_extra_list=" $(nc_section NC_APPS_EXTRA | tr '\n' ' ') "
            case "$enabled_list" in
                *" $app "*)
                    # HMR-live iff the .htaccess proxy block has sidecar-<app>:3000.
                    htaccess_block="$(nc_section HTACCESS_BLOCK)"
                    if printf '%s' "$htaccess_block" | grep -qF "sidecar-${app}:3000" ; then
                        add_kv 'In NC' "enabled, HMR live (.htaccess proxy entry present)"
                    else
                        add_kv 'In NC' "enabled"
                    fi
                    ;;
                *)
                    case "$disabled_list" in
                        *" $app "*)
                            add_kv 'In NC' "${YELLOW}disabled${RESET}"
                            add_finding 'MISMATCH' \
                                "App cloned at apps/${app}/ but disabled in NC's app list." \
                                "Run: make nc-down && make nc-up"
                            ;;
                        *)
                            case "$apps_extra_list" in
                                *" $app "*)
                                    add_kv 'In NC' "${YELLOW}apps-extra symlink present but not registered${RESET}"
                                    add_finding 'MISMATCH' \
                                        "apps-extra/${app} symlink present but NC didn't register the app (interrupted apps-extra wire-up or manual symlink)." \
                                        "Run: make nc-down && make nc-up"
                                    ;;
                                *)
                                    # Symlink missing. For quilt this can mean
                                    # .build/ is absent (the wire-up's --strict
                                    # refuses a symlink to a nonexistent
                                    # .build/) — needs assemble first, nc-up
                                    # alone won't fix it.
                                    add_kv 'In NC' "${YELLOW}apps-extra symlink missing${RESET}"
                                    if [ "$type" = "quilt" ] && [ ! -d "$build_dir" ]; then
                                        add_finding 'MISMATCH' \
                                            "Quilt app cloned but .build/ doesn't exist — apps-extra symlink cannot be created without an assembled .build/ (nc-down/nc-up alone won't fix this)." \
                                            "Run: cd apps/${app} && make assemble MODE=force && make nc-down && make nc-up"
                                    else
                                        add_finding 'MISMATCH' \
                                            "apps-extra symlink missing for cloned app (apps-extra wire-up didn't pick it up)." \
                                            "Run: make nc-down && make nc-up"
                                    fi
                                    ;;
                            esac
                            ;;
                    esac
                    ;;
            esac
        fi

        i=$((i + 1))
    done

    # --- Emit §C scan header ---
    # One line per app, sorted worst-severity-first (0=RECOVER, 1=DRIFT,
    # 2=MISMATCH, 3=ok), alpha within tier. Built as one newline-delimited
    # string sorted in a single pass.
    scan_lines=""
    i=0
    while [ "$i" -lt "${#APPS[@]}" ]; do
        app="${APPS[$i]}"
        type="${APP_TYPES[$i]}"
        # Empty APP_FINDS[i] → all counts zero → "ok".
        counts="$(printf '%s' "${APP_FINDS[$i]}" | awk -F'\t' '
            $2 == "RECOVER"  {r++}
            $2 == "DRIFT"    {d++}
            $2 == "MISMATCH" {m++}
            $2 == "ADVISORY" {a++}
            END {printf "%d %d %d %d", r+0, d+0, m+0, a+0}
        ')"
        # `read` (not `set --`, which would clobber the script's $@)
        # splits the counts into named vars.
        read -r rec dri mis adv <<EOF
$counts
EOF
        # Sort key: tier digit + app name (alpha within tier).
        if [ "$rec" -gt 0 ]; then
            tier=0
        elif [ "$dri" -gt 0 ]; then
            tier=1
        elif [ "$mis" -gt 0 ]; then
            tier=2
        else
            tier=3
        fi
        sev_str=""
        if [ "$rec" -gt 0 ]; then sev_str="${sev_str}${RED}RECOVER${RESET} (${rec}) "; fi
        if [ "$dri" -gt 0 ]; then sev_str="${sev_str}${YELLOW}DRIFT${RESET} (${dri}) "; fi
        if [ "$mis" -gt 0 ]; then sev_str="${sev_str}${YELLOW}MISMATCH${RESET} (${mis}) "; fi
        if [ "$adv" -gt 0 ]; then sev_str="${sev_str}${DIM}ADVISORY${RESET} (${adv}) "; fi
        if [ -z "$sev_str" ]; then sev_str="${GREEN}ok${RESET}"; fi
        sev_str="${sev_str% }"
        scan_lines="${scan_lines}$(printf '%s\t%s\t%s\t%s' "$tier" "$app" "$type" "$sev_str")
"
        i=$((i + 1))
    done

    # Sort by tier then app name; 19-char app-with-type column.
    printf '%s' "$scan_lines" | LC_ALL=C sort -t$'\t' -k1,1n -k2,2 | awk -F'\t' '
        { printf "  %-19s %s\n", $2 " (" $3 ")", $4 }
    '
    printf '\n'

    # --- Emit per-app blocks in alphabetical order ---
    # APP_BLOCK[i] (KV) then emit_finds (severity-sorted findings): every
    # KV line precedes any finding regardless of which probe wrote it.
    i=0
    while [ "$i" -lt "${#APPS[@]}" ]; do
        CUR_I=$i
        printf '%s' "${APP_BLOCK[$i]}"
        emit_finds
        i=$((i + 1))
    done
    printf '\n'
fi

# --- §D Cross-cutting findings ---
# CC_FINDINGS / cc_finding are defined up by emit_dev_kv (the IDE-wiring
# compute populates findings before §A emits); the checks below append.

printf '%sCross-cutting findings%s\n' "$BOLD" "$RESET"

# NC reachability meta-finding. When NC is unreachable the four
# NC-dependent §D checks below silently no-op; without this aggregate,
# §D would render "none" and falsely read as all-clear. So surface the
# gap so the silence reads as absence-of-probe.
if [ "$NC_STATE" = "cannot probe" ]; then
    # NC state unknown — already surfaced by the §A Nextcloud line and
    # bigger than "NC down"; don't duplicate as a §D advisory.
    :
elif [ "$NC_UP" != "1" ]; then
    cc_finding 'ADVISORY' \
        "NC down — these cross-cutting checks skipped this run: HMR proxy drift, hmr_enabler enable state, NEXTCLOUD_VERSION chain, stale-enable detection. (Bring NC up to surface findings in any of these.)" \
        "see the Nextcloud line above for the fix-hint"
elif [ "$NC_GATHER_OK" != "1" ]; then
    cc_finding 'RECOVER' \
        "NC reachable but gather failed — every NC-dependent probe in this report is degraded. Reason: ${NC_GATHER_ERR:-unknown (no stderr captured)}" \
        "investigate NC container (\`docker compose logs nextcloud\`); re-run \`make status\` once recovered"
fi

# Dev-builder probe failure. When APP_PROBE_ERR is set, every per-app
# Lock + Mozart check ran on incomplete data — surface the root cause
# once rather than as N per-app "cannot probe" lines.
if [ -n "$APP_PROBE_ERR" ]; then
    cc_finding 'RECOVER' \
        "Dev-builder probe failed: ${APP_PROBE_ERR}" \
        "investigate \`bash scripts/host/dc-run.sh python3 -c 'pass'\` directly to see the error"
fi

# localhost:8080 reachable from host — the honest "can my browser see
# this" probe. NC's in-container healthcheck can't catch a broken
# port-publish or another process holding :8080. /dev/tcp keeps the host
# floor clean (no curl/timeout — timeout(1) is missing on macOS);
# loopback connects or refuses immediately, so no timeout wrapper is
# needed. `(: <redirect)` subshell keeps set -e from propagating.
#
# Skipped in-container (IN_BUILDER): the container's loopback is its own
# netns, so this can't be answered from inside.
if [ "$NC_UP" = "1" ] && [ -z "${IN_BUILDER:-}" ]; then
    if (: </dev/tcp/127.0.0.1/8080) >/dev/null 2>&1; then
        : # reachable; silent
    else
        cc_finding 'DRIFT' \
            "NC reports healthy but localhost:8080 is not reachable from host (docker port-publish broken, or another process bound :8080 first)." \
            "check \`docker compose port nextcloud 80\` + \`ss -tlnp 'sport = :8080'\`; restart NC with \`make nc-down && make nc-up\` after freeing the port"
    fi
fi

# NEXTCLOUD_VERSION chain (silent when OK). Three links: resolved .env
# value → running container's NEXTCLOUD_VERSION env → config/.installed-version
# marker. Mismatch 1→2 = container needs recreation; 2→3 = upgrade tasks
# pending. Unresolvable .env (broken checkout) and a failed gather each
# surface their own finding so the check never silently no-ops.
expected_env="$(read_env_var NEXTCLOUD_VERSION)"
if [ -z "$expected_env" ]; then
    cc_finding 'DRIFT' \
        "NEXTCLOUD_VERSION not resolvable — neither .env nor .env.example defines it (broken checkout)." \
        "restore .env.example from git, set NEXTCLOUD_VERSION in .env"
elif [ "$NC_UP" = "1" ]; then
    nc_reason="$NC_REASON"
    if [ -n "$nc_reason" ]; then
        cc_finding 'ADVISORY' \
            "NEXTCLOUD_VERSION chain cannot be verified (${nc_reason}); expected=${expected_env} from .env / .env.example."
    else
        IMG_NC_VER="$(nc_section NC_VERSION_ENV | head -n 1)"
        INSTALLED_MARKER="$(nc_section NC_VERSION_INSTALLED_MARKER | head -n 1)"
        if [ -z "$IMG_NC_VER" ]; then
            cc_finding 'ADVISORY' \
                "NEXTCLOUD_VERSION chain link 2 empty (NC container's NEXTCLOUD_VERSION env not set); cannot verify against expected=${expected_env}."
        elif [ "$IMG_NC_VER" != "$expected_env" ]; then
            cc_finding 'DRIFT' \
                "NEXTCLOUD_VERSION chain broken: expected=${expected_env} (.env / .env.example), nextcloud container env=${IMG_NC_VER} (image needs rebuild)." \
                "Run: make distclean && make setup && make nc-up (full version reset)"
        elif [ -n "$INSTALLED_MARKER" ] && [ "$IMG_NC_VER" != "$INSTALLED_MARKER" ]; then
            cc_finding 'DRIFT' \
                "NC config/.installed-version marker (${INSTALLED_MARKER}) stale vs NEXTCLOUD_VERSION env (${IMG_NC_VER}); upgrade tasks pending." \
                "accept next make nc-up will run upgrade tasks, or: make distclean && make setup && make nc-up (full reset)"
        fi
    fi
fi

# apps/server commit pin vs NEXTCLOUD_VERSION. NC core is live-bound
# from apps/server into /var/www/html, so the checked-out commit IS what
# the running NC serves — bumping .env without re-checking-out apps/server
# (or vice versa) diverges what NC runs from what .env names.
# describe --tags --exact-match lands on the version tag; no exact tag
# = drift from any release (rev-parse for the informational value).
if [ -n "$expected_env" ] && [ -d "$PLATFORM_ROOT/apps/server/.git" ]; then
    # Non-zero when no tag at HEAD; capture so set -e doesn't trip.
    if server_ver="$(git -C "$PLATFORM_ROOT/apps/server" describe --tags --exact-match 2>/dev/null)"; then
        :
    else
        server_ver=""
    fi
    if [ -z "$server_ver" ]; then
        server_ver_disp="$(git -C "$PLATFORM_ROOT/apps/server" rev-parse --short HEAD 2>/dev/null || printf 'unknown')"
        cc_finding 'DRIFT' \
            "apps/server/ HEAD is not at a tagged release (currently ${server_ver_disp}); .env NEXTCLOUD_VERSION=${expected_env} but NC is live-bound from this commit, so running NC differs from the .env-named version." \
            "Run: git -C apps/server fetch --tags && git -C apps/server checkout ${expected_env} (changes propagate live to NC; restart NC if any cached state needs flushing)"
    elif [ "$server_ver" != "$expected_env" ]; then
        cc_finding 'DRIFT' \
            "apps/server/ checked out at ${server_ver} but .env NEXTCLOUD_VERSION=${expected_env}; NC is live-bound from apps/server so running NC is at ${server_ver}, not ${expected_env}." \
            "Run: git -C apps/server fetch --tags && git -C apps/server checkout ${expected_env} (changes propagate live to NC; restart NC if any cached state needs flushing)"
    fi
fi

# HMR proxy block stale — diff the current .htaccess block against
# regen-htaccess --dry-run. Reusing regen's own code means no parallel
# reachability logic that could disagree at sidecar-startup; watch-mode
# sidecars (no port 3000) are excluded by its TCP probe. NC-down/gather
# fail → ADVISORY; --dry-run fail → RECOVER; drift → DRIFT.
if [ "$NC_UP" = "1" ] && [ "$DOCKER_OK" = "1" ]; then
    nc_reason="$NC_REASON"
    if [ -n "$nc_reason" ]; then
        cc_finding 'ADVISORY' \
            "HMR proxy block drift cannot be checked (${nc_reason})."
    else
        # regen-htaccess rejects unknown args with rc=2, so a pre-flag
        # image surfaces as a clean rc=2 + diagnostic (2>&1) — no
        # destructive path to defend against.
        if dry_out="$("${DC_ARGS[@]}" exec -T nextcloud /usr/local/bin/regen-htaccess --dry-run 2>&1)"; then
            expected_block="$dry_out"
            current_block="$(nc_section HTACCESS_BLOCK)"
            if [ "$expected_block" != "$current_block" ]; then
                cc_finding 'DRIFT' \
                    "HMR proxy block in .htaccess differs from what a fresh regen would emit." \
                    "Run: make nc-down && make nc-up"
            fi
        else
            cc_finding 'RECOVER' \
                "HMR proxy block drift probe failed (regen-htaccess --dry-run): $(printf '%s' "$dry_out" | head -1)" \
                "investigate; retry \`make status\`"
        fi
    fi
fi

# hmr_enabler not enabled — it provides HMR's unsafe-eval CSP relaxation,
# without which browser HMR silently fails. Reads cached enable lists.
if [ "$NC_UP" = "1" ]; then
    nc_reason="$NC_REASON"
    if [ -n "$nc_reason" ]; then
        cc_finding 'ADVISORY' \
            "hmr_enabler enable-state cannot be verified (${nc_reason})."
    else
        enabled_list=" $(nc_section APPS_ENABLED | tr '\n' ' ') "
        disabled_list=" $(nc_section APPS_DISABLED | tr '\n' ' ') "
        case "$enabled_list" in
            *" hmr_enabler "*)
                : # ok
                ;;
            *)
                hmr_state='absent'
                case "$disabled_list" in
                    *" hmr_enabler "*) hmr_state='disabled' ;;
                esac
                cc_finding 'MISMATCH' \
                    "hmr_enabler not enabled in NC (${hmr_state}); browser HMR clients silently fail without it." \
                    "Run: make nc-down && make nc-up"
                ;;
        esac
    fi
fi

# App enabled in NC but no longer cloned. Subtract three sets from
# APPS_ENABLED: cloned (apps/ on host), NC_CORE_APPS (core tarball,
# derived live so it tracks NC versions), NC_IMAGE_BAKED_EXTRA
# (hmr_enabler). What's left is genuinely stale.
if [ "$NC_UP" = "1" ]; then
    nc_reason="$NC_REASON"
    if [ -n "$nc_reason" ]; then
        cc_finding 'ADVISORY' \
            "Stale-enable check cannot run (${nc_reason}); a stale app:enable entry would not be surfaced this run."
    else
        # Build space-padded sets for `case ... in *" $app "*` matching.
        cloned_list=" "
        for a in ${APPS[@]+"${APPS[@]}"}; do
            cloned_list="${cloned_list}${a} "
        done
        nc_core_list=" $(nc_section NC_CORE_APPS | tr '\n' ' ')"
        enabled_apps="$(nc_section APPS_ENABLED)"
        while IFS= read -r ea; do
            [ -n "$ea" ] || continue
            case "$cloned_list"            in *" $ea "*) continue ;; esac
            case "$nc_core_list"           in *" $ea "*) continue ;; esac
            case "$NC_IMAGE_BAKED_EXTRA"   in *" $ea "*) continue ;; esac
            cc_finding 'MISMATCH' \
                "App '${ea}' enabled in NC but no longer cloned at apps/${ea}/ (stale enable from prior session)." \
                "Run: make nc-down && make nc-up (re-runs the entrypoint, which disables apps whose code was removed)"
        done <<EOF
$enabled_apps
EOF
    fi
fi

# Ghost sidecar fragments — docker/sidecars/<X>.yml whose <X> isn't in
# APPS. The per-app stale-fragment check can't catch this: the per-app
# loop only iterates apps on disk, so a fragment for a removed app slips
# past it.
for frag in "$PLATFORM_ROOT"/docker/sidecars/*.yml; do
    [ -f "$frag" ] || continue
    frag_base="${frag##*/}"
    frag_app="${frag_base%.yml}"
    case " ${APPS[@]+${APPS[*]}} " in
        *" $frag_app "*) continue ;;
    esac
    cc_finding 'MISMATCH' \
        "Sidecar fragment docker/sidecars/$frag_base exists but apps/${frag_app}/ doesn't (stale fragment from a removed app, or fragment created in error)." \
        "Run: make down (sweeps all sidecar fragments); afterwards re-run make webpack MODE=<mode> from each app dir you're developing"
done

# No "hung holder" finding: a held .build/.lock is an flock, which the
# kernel releases the instant its holder dies (crash, SIGKILL, power loss
# included) — so "held" always means a live holder, never a stale lock.
# Whether that holder is wanted (a build the operator started) or unwanted
# (a one-off whose launching client was interrupted, still running its
# install) is not observable: both are an identical live process holding
# the lock. The platform can't tell them apart, so it doesn't guess — the
# per-app Lock line names the holder (§C), the §A Ephemerals line lists
# running one-offs, and the operator's own knowledge supplies the verdict.
# `make down EPHEMERALS=1` is the lightest make-surface override when they
# decide a one-off is unwanted (clean/distclean reap too, but lose more).
# (The prior finding here mislabelled in-flight pool
# ops as "hung" and recommended `rm .build/.lock`, which is dangerous: rm
# unlinks the path but the holder keeps its flock on the now-anonymous
# inode, so the next op opens a fresh inode and acquires it — two writers
# on .build/, the exact corruption the lock prevents.)

# Emit findings or "none". CC_FINDINGS already ends in a newline; the
# closing \n matches the "none" branch's bottom blank line.
if [ -z "$CC_FINDINGS" ]; then
    printf '  %snone%s\n\n' "$DIM" "$RESET"
else
    printf '%s\n' "$CC_FINDINGS"
fi

# --- §E Advisory ---
# Placeholder for future findings that don't belong under §D; nothing
# claims it in steady state, so no emit.

# EXIT trap forces rc=0.
