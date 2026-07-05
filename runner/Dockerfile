# runner — headless Claude Code queue runner for ITSL Open Stack (CONTRACTS §7)
#
# 4 agent slots in one container: staggered cron + HMAC-verified wake listener
# (:8791). Debian slim chosen over alpine: flock/setpriv ship with util-linux,
# and Debian cron + `crontab -u` gives per-user job execution (jobs run as the
# unprivileged `runner` user; only the cron daemon itself needs root).
#
# Build:  docker compose build runner   (from /opt/openstack)
# Env:    see entrypoint.sh header — everything comes from /opt/openstack/.env

FROM node:22-bookworm-slim

# Pin the CLI. Override at build time if the pin must move:
#   docker compose build --build-arg CLAUDE_CODE_VERSION=x.y.z runner
ARG CLAUDE_CODE_VERSION=2.0.1

ENV DISABLE_AUTOUPDATER=1 \
    DISABLE_TELEMETRY=1 \
    DISABLE_ERROR_REPORTING=1 \
    LANG=C.UTF-8

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      ca-certificates \
      curl \
      jq \
      cron \
      postgresql-client \
      util-linux \
      procps \
 && rm -rf /var/lib/apt/lists/*

RUN npm install -g "@anthropic-ai/claude-code@${CLAUDE_CODE_VERSION}" \
 && npm cache clean --force

# Unprivileged run user. Cron daemon runs as root (Debian cron requirement);
# every job and the wake listener run as `runner`.
RUN useradd --create-home --uid 10001 --shell /bin/bash runner \
 && mkdir -p /locks /var/log/runner \
 && chown runner:runner /locks /var/log/runner

WORKDIR /app
COPY entrypoint.sh wake-listener.js crontab ./
COPY bin/ ./bin/
COPY prompts/ ./prompts/
RUN chmod 0755 /app/entrypoint.sh /app/bin/*.sh \
 && chmod 0644 /app/wake-listener.js /app/crontab /app/prompts/queue-run.md

EXPOSE 8791

# Healthy = listener answers when the runner is enabled; a disabled runner
# (idle loop waiting for keys) is deliberately healthy too.
HEALTHCHECK --interval=60s --timeout=5s --start-period=15s \
  CMD sh -c 'if [ "${RUNNER_ENABLED:-0}" = "1" ] && [ -n "${ANTHROPIC_API_KEY:-}" ]; then curl -fsS http://127.0.0.1:8791/healthz >/dev/null; else exit 0; fi'

ENTRYPOINT ["/app/entrypoint.sh"]
