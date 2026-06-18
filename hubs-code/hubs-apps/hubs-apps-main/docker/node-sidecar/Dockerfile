# Base image for per-app `sidecar-<app>` webpack containers. Pure
# watcher: dependency installation happens in dev-builder containers,
# not here; this image only runs webpack against the bind-mounted
# .build/ (quilt apps) or apps/<app>/ (standalone) tree.
#
# No ENTRYPOINT, no baked tini: webpack invocation comes from the
# compose `command:`, and PID-1 reaping + signal forwarding for
# webpack's child tree come from compose's `init: true` (docker-init).
# The only non-base concern this image carries is the developer-user
# provisioning below.

FROM node:24-slim

USER root

# Run every RUN under bash with pipefail, so a pipe whose left side fails
# fails the build instead of being masked by the right side's status.
SHELL ["/bin/bash", "-o", "pipefail", "-c"]

# Provision a `developer` user at the host's UID/GID so webpack's writes
# into the bind-mounted .build/ land as the host user, not root.
#
# A real passwd entry (not just `USER <uid>`) is required: Node's
# os.userInfo() throws ENOENT for a UID with no passwd entry.
#
# node:24-slim ships a `node` user at UID/GID 1000. Remove it
# unconditionally so the `developer` slot is free for any DEVELOPER_UID/
# GID — a collision-only conditional is fragile. `userdel --remove`
# cascades to deleting the `node` group (Debian USERGROUPS_ENAB=yes), so
# a host GID of 1000 lands a clean `groupadd`. A *system* group can still
# own DEVELOPER_GID, though — macOS GID 20 is `dialout` here — which
# userdel won't touch and `groupadd --gid` exits 4 on; rename whatever
# group holds the GID to `developer` in that case rather than create one.
#
# No ARG defaults: build fails loudly at useradd if either is unset,
# rather than silently building at UID 1000.
ARG DEVELOPER_UID
ARG DEVELOPER_GID

RUN userdel --remove node \
 && if existing="$(getent group "${DEVELOPER_GID}")"; then \
        groupmod --new-name developer "${existing%%:*}"; \
    else \
        groupadd --gid "${DEVELOPER_GID}" developer; \
    fi \
 && useradd  --uid "${DEVELOPER_UID}" --gid "${DEVELOPER_GID}" \
             --create-home \
             developer

ENV HOME=/home/developer
USER ${DEVELOPER_UID}:${DEVELOPER_GID}
WORKDIR /app

# No ENTRYPOINT: webpack invocation comes from the compose per-service
# `command:`; PID 1 is docker-init via compose `init: true`.
