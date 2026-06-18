# Dev-builder image — runs the dev pipeline (assemble, build, quilt,
# deps, security-update, l10n, diff) in a container.
# Base is cimg/php:8.3-node to match CI, so the in-container toolchain
# is a strict superset of CI's runtime; we don't build our own CI image.
#
# Multi-stage (not curl|bash) so the bun version is pinned by image tag
# — deterministic across rebuilds, not whatever bun.sh/install ships at
# build time. oven/bun supplies the bun binary npm-registry-shield needs.
ARG BUN_VERSION=1.3.14
FROM oven/bun:${BUN_VERSION}-slim AS bun-stage

FROM cimg/php:8.3-node

USER root

# Run every RUN under bash with pipefail, so a pipe whose left side fails
# fails the build instead of being masked by the right side's status.
SHELL ["/bin/bash", "-o", "pipefail", "-c"]

# Only the packages cimg base lacks: quilt + rsync for assemble; pigz for
# parallel tar compression in package.sh (keeps the CI toolchain a subset);
# python3-polib + python3-yaml for l10n-tools; tmux for the IDE-attach
# integrated terminal (sessions survive vscode-server reconnects).
# php 8.3, node, composer, git, jq, make, flock are already in base.
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
        quilt \
        rsync \
        pigz \
        python3-polib \
        python3-yaml \
        tmux \
 && rm -rf /var/lib/apt/lists/*

# Bun runtime + npm-registry-shield: an npm supply-chain quarantine
# proxy (scripts/deps.sh launches it inline around each npm operation to
# filter packages younger than the quarantine window). Bun is the
# shield's only runtime dependency.
#
# Shield version pinned for determinism: a moving target means an image
# rebuild could swap the very tool that guards against npm supply-chain
# compromise. Bumps follow the VERSION-file workflow: edit the
# NPM_REGISTRY_SHIELD_VERSION ARG, bump docker/dev-builder/VERSION,
# commit both atomically. Forced rebuild without a VERSION bump:
# `docker compose build dev-builder`.
#
# BUN_INSTALL/BUN_INSTALL_BIN scoped to this RUN only: steer bun's dirs
# into /opt (world-readable) and /usr/local/bin (on PATH). Bun's default
# $HOME/.bun is /root/.bun mode-700 here — unreadable after the USER
# switch below, so the developer user couldn't run the shield. Kept out
# of the runtime env so later in-container `bun install` calls aren't
# steered by them.
COPY --from=bun-stage /usr/local/bin/bun /usr/local/bin/bun
ARG NPM_REGISTRY_SHIELD_VERSION=0.1.1
RUN BUN_INSTALL=/opt/bun BUN_INSTALL_BIN=/usr/local/bin \
    bun install -g "@itsl-solutions/npm-registry-shield@${NPM_REGISTRY_SHIELD_VERSION}"

# Provision a `developer` user at the host's UID/GID so bind-mounted
# writes land as the host user. A real /etc/passwd entry (not a bare
# numeric USER) is needed by tools that call getpwuid()/getgrgid() —
# nano during `make quilt`'s editor step, git's pager, ssh.
#
# cimg/php:8.3-node ships a `circleci` user at UID 1001, primary group
# `circleci` at GID 1002. Remove it unconditionally so the `developer`
# slot is free whatever DEVELOPER_UID/GID the host passes — no fragile
# collision-only conditional. `userdel --remove` cascades to dropping
# the `circleci` group (login.defs USERGROUPS_ENAB=yes, cimg default), so
# a host GID of 1002 lands a clean `groupadd`. A *system* group can still
# own DEVELOPER_GID, though — macOS GID 20 is `dialout` here — which
# userdel won't touch and `groupadd --gid` exits 4 on; rename whatever
# group holds the GID to `developer` in that case rather than create one.
#
# No ARG defaults — they belong upstream in the compose layer.
# compose.dev-builder.yml supplies `${DEVELOPER_UID:-1000}` for raw
# `docker compose` on the common 1000-host; the make path overrides via
# `?= $(shell id -u)` + export for non-1000 hosts. Direct `docker build`
# with no --build-arg fails loudly at useradd below (empty UID →
# `useradd --uid ""` errors): that path bypasses both, so loud failure
# is correct.
ARG DEVELOPER_UID
ARG DEVELOPER_GID

RUN userdel --remove circleci \
 && if existing="$(getent group "${DEVELOPER_GID}")"; then \
        groupmod --new-name developer "${existing%%:*}"; \
    else \
        groupadd --gid "${DEVELOPER_GID}" developer; \
    fi \
 && useradd  --uid "${DEVELOPER_UID}" --gid "${DEVELOPER_GID}" \
             --create-home --home-dir /home/developer \
             --shell /bin/bash \
             developer

# NOPASSWD sudo for the developer user. The IDE-attach container must
# add a name entry to /etc/group for the host's docker GID at start
# (compose.dev.yml's group_add adds the GID but no name, so
# `groups`/`id`/getgrgid() emit "cannot find name for group ID <gid>"
# on every shell startup). That add runs in compose.dev.yml's command
# wrapper so it covers every launch path, not just the make recipe.
# Threat model: this container already binds /var/run/docker.sock
# (RCE-equivalent — root is reachable via a privileged sibling spawn),
# so NOPASSWD sudo doesn't widen the surface. Ephemeral one-shots reuse
# the image but don't load compose.dev.yml (no docker.sock); the grant
# exists but is benign there.
RUN echo 'developer ALL=(ALL) NOPASSWD: ALL' > /etc/sudoers.d/50-developer \
 && chmod 440 /etc/sudoers.d/50-developer

ENV HOME=/home/developer
USER ${DEVELOPER_UID}:${DEVELOPER_GID}
WORKDIR /app

# Global git excludes for transient editor/extension state. Placed at
# git's XDG default path ($HOME/.config/git/ignore), not `[core]
# excludesFile` in /etc/gitconfig: the Dev Containers extension copies
# the host's ~/.gitconfig on attach, and a host-side `core.excludesFile`
# would clobber a system-level setting. The XDG-default path is read
# only when no user override exists, and host configs don't set it.
#
# Volume-init: the dev-builder-home named volume mounts onto
# /home/developer; when empty (first ide-up, or post-clean/post-distclean —
# both run `down -v`, which removes the volume) docker seeds it from image
# content, so this file reaches fresh volumes too.
RUN mkdir -p $HOME/.config/git && \
    printf '%s\n' \
        '# Anthropic Claude Code extension per-workspace state.' \
        '.claude/' \
        > $HOME/.config/git/ignore

# No ENTRYPOINT — invoked by scripts/host/dc-run.sh which passes the
# command as positional args.
