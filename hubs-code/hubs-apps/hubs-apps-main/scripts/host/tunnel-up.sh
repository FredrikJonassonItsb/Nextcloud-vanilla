#!/usr/bin/env bash
#
# tunnel-up.sh — bring up the `tunnel` compose service.
#
# HOST_SSH_AUTH_SOCK is the host path to the SSH agent socket bound into
# the container. The Makefile owns the platform-aware default
# ($SSH_AUTH_SOCK on Linux, /run/host-services/ssh-auth.sock on macOS)
# and exports it; docker/compose.dev.yml propagates it into the
# IDE-attach dev-builder so `make tunnel` from the integrated terminal
# sees the host shell's value. This script only reads it — no
# per-platform fallback here. `make tunnel SERVER=…` is the sanctioned
# path; direct invocation requires setting HOST_SSH_AUTH_SOCK by hand.
#
# compose.tunnel.yml interpolation is strict (`${VAR:?}`), so the exports
# here are what reaches the container — docker errors loud on a missing
# one. That YAML is loaded ONLY here, not via the platform $(DC) macro,
# so the strictness doesn't bleed into unrelated recipes.

set -euo pipefail

PLATFORM_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

SERVER="${SERVER:-}"
if [ -z "$SERVER" ]; then
    echo "ERROR: SERVER not specified." >&2
    echo "  Usage: make tunnel SERVER=<ip-or-hostname>" >&2
    exit 1
fi

if [ -z "${HOST_SSH_AUTH_SOCK:-}" ]; then
    echo "ERROR: HOST_SSH_AUTH_SOCK is empty." >&2
    echo "  On Linux this means SSH_AUTH_SOCK isn't set in the shell that" >&2
    echo "  invoked make — start ssh-agent and load your key:" >&2
    echo "    eval \"\$(ssh-agent -s)\"" >&2
    echo "    ssh-add ~/.ssh/<your-key>" >&2
    echo "  If you started the agent AFTER \`make ide-up\` and are running" >&2
    echo "  this from the IDE integrated terminal, the value compose" >&2
    echo "  captured at ide-up is stale — \`make ide-down && make ide-up\`" >&2
    echo "  from a host shell to repropagate." >&2
    exit 1
fi

# DEV_BUILDER_VERSION + HOST_PROJECT_DIR — compose-interpolation deps the
# loaded YAML needs; make normally exports them, the fallbacks cover
# direct invocation.
export DEV_BUILDER_VERSION="${DEV_BUILDER_VERSION:-$(cat "$PLATFORM_ROOT/docker/dev-builder/VERSION")}"
export HOST_PROJECT_DIR="${HOST_PROJECT_DIR:-$PLATFORM_ROOT}"
export HOST_SSH_AUTH_SOCK SERVER

cd "$PLATFORM_ROOT"

# COMPOSE_IGNORE_ORPHANS suppresses the "orphan containers" warning:
# running services (nextcloud, postgres, dev-builder, …) aren't orphans
# of the project, just absent from this script's narrow tunnel.yml-only
# -f set. Same primitive scripts/host/dc-run.sh uses.
export COMPOSE_IGNORE_ORPHANS=1

TUNNEL_DC=(docker compose -f docker/compose.tunnel.yml --profile tunnel)

# TUNNEL_CONTAINER: derived via `compose ps` `{{.Name}}` (not hardcoded)
# so the project+service+index naming convention isn't baked in here.
# Empty when no container ever existed (build-stage failure, daemon
# outage, sibling agent removed it); downstream blocks branch on empty
# rather than run `docker logs `/`docker rm -f ` against an empty string.
# Filled by resolve_tunnel_container() from both branches below.
TUNNEL_CONTAINER=""
resolve_tunnel_container() {
    # head -1: single-replica service, at most one line, but cheapest
    # robust shape. 2>/dev/null hides compose's stderr chatter when no
    # container exists or the daemon is unreachable — the surrounding
    # `compose up` already gave the operator that error context. `|| true`
    # is the set -e escape: a non-zero pipeline rc would otherwise exit
    # the script; empty TUNNEL_CONTAINER flows through the fallbacks.
    TUNNEL_CONTAINER="$("${TUNNEL_DC[@]}" ps --format '{{.Name}}' tunnel 2>/dev/null | head -1 || true)"
}

echo "==> Bringing tunnel up: sdkmc connects to tunnel:10143/10025/10026 → ${SERVER}"
# `--wait` blocks until the healthcheck passes (or terminally fails), so
# the recipe doesn't return success on a container whose ssh is silently
# cycling. The healthcheck TCP-probes the -L listener, which exists only
# once ssh has authenticated AND established forwards. `--wait-timeout 30`
# bounds the wait.
#
# The container's command wrapper prints an `ssh-add -l` enumeration
# between AGENT_KEYS markers before exec'ing ssh, so docker logs carry
# both the enumeration and any ssh errors. The awk caps to the first
# block: the restart policy re-runs the wrapper on each bounce, so an
# unrestricted match would emit duplicates.
extract_agent_keys() {
    docker logs "$TUNNEL_CONTAINER" 2>&1 \
        | awk '/==AGENT_KEYS_BEGIN==/{flag=1; next} /==AGENT_KEYS_END==/{exit} flag'
}

# `if`, not capturing $? after: with `set -e`, compose-up failure would
# exit before the rc capture.
if "${TUNNEL_DC[@]}" up -d --wait --wait-timeout 30 tunnel; then
    # Success path: show which keys the tunnel reached the agent with.
    resolve_tunnel_container
    # `|| true`: an extract failure (daemon race, container vanished)
    # must not `set -e`-exit with no diagnostic — empty agent_keys flows
    # to the [ -n ] guard below.
    agent_keys="$(extract_agent_keys || true)"
    if [ -n "$agent_keys" ]; then
        key_count="$(printf '%s\n' "$agent_keys" | awk 'NF{n++} END{print n+0}')"
        echo "    agent has ${key_count} key(s):"
        printf '%s\n' "$agent_keys" | sed 's/^/      /'
        echo "    If ${SERVER} ever rejects all of these, load the right one on your host:"
        echo "      ssh-add ~/.ssh/<right-key>"
    fi
    echo
    echo "Tunnel is up (detached). Useful commands:"
    # %-55s, not hand-counted spaces: keeps the # column aligned despite
    # ${TUNNEL_CONTAINER}'s varying length (tracks COMPOSE_PROJECT_NAME).
    printf '  %-55s # %s\n' 'make status' 'tunnel state + SERVER target + healthcheck'
    printf '  %-55s # %s\n' "docker logs ${TUNNEL_CONTAINER}" 'ssh output (debugging)'
    printf '  %-55s # %s\n' 'make down' 'stop tunnel + everything else'
    exit 0
fi

# Failure path: extract whatever the wrapper printed (keys may or may
# not have been captured before ssh failed) for the diagnostic. `|| true`
# as on the success path — a failing capture must not exit before the
# diagnostic runs, which is this branch's whole point. Empty
# TUNNEL_CONTAINER falls through the same way: agent_keys stays empty.
resolve_tunnel_container
agent_keys="$(extract_agent_keys || true)"

# Two diagnostic blocks: the agent enumeration (what keys the container
# saw) and the ssh tail (what failed after that). Tear the cycling
# container down before erroring — `--wait` already gave up. Cleanup
# failures are reported, not suppressed.
agent_block="${agent_keys:-(no agent enumeration captured — container may not have started)}"

if ssh_err="$(docker logs "$TUNNEL_CONTAINER" 2>&1 \
        | awk '/==AGENT_KEYS_END==/{after=1; next} after && /==AGENT_KEYS_BEGIN==/{after=0; next} after' \
        | tail -8)"; then
    log_block="ssh's last words (from \`docker logs ${TUNNEL_CONTAINER}\`):
${ssh_err}"
else
    log_block="(could not read tunnel container's logs)"
fi
if [ -z "$TUNNEL_CONTAINER" ]; then
    # No container exists (build/image-stage failure): nothing to remove,
    # and "remove failed" would point at a phantom.
    rm_block="No container was created (compose-up failed before container creation — see compose output above)."
elif docker rm -f "$TUNNEL_CONTAINER" >/dev/null 2>&1; then
    rm_block="The failing container has been removed; nothing left flapping."
else
    rm_block="WARNING: \`docker rm -f ${TUNNEL_CONTAINER}\` failed too; inspect with \`docker ps -a\` and remove manually."
fi

cat >&2 <<EOF

ERROR: tunnel container's healthcheck never went green.

  Agent keys the tunnel container saw:
${agent_block}

  ${log_block}

  HOST_SSH_AUTH_SOCK was: ${HOST_SSH_AUTH_SOCK}

  Most likely fixes:
    - Agent has no keys (or wrong keys for ${SERVER}): load the right one
        ssh-add ~/.ssh/<your-key>
      (Operators who use \`IdentityFile\` in ~/.ssh/config to point ssh
      at a key may never have loaded it into the agent — the tunnel
      container has no ~/.ssh/config and relies on the forwarded agent.)
    - No agent running: start one + load a key
        eval "\$(ssh-agent -s)"
        ssh-add ~/.ssh/<your-key>
    - ${SERVER} unreachable: try \`ssh ${SERVER} echo ok\` from your host shell.

${rm_block}
EOF
exit 1
