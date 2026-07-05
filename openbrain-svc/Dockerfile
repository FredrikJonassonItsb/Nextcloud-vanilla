# openbrain-svc — per-brain Open Brain MCP service (CONTRACTS.md §5)
#
# BUILD CONTEXT = this directory (compose: `build: ../openbrain-svc`).
# The PII firewall file is NOT baked in — compose bind-mounts
# ../stack/shared/pii-patterns.json to /app/pii-patterns.json and sets
# PII_PATTERNS_PATH=/app/pii-patterns.json (single source of truth).
#
# Unit tests run from the repo root on the dev machine (they need
# ../stack/shared): cd openbrain-svc && npm test

# ---- deps: production node_modules ------------------------------------------
FROM node:22-alpine AS deps
WORKDIR /srv/openbrain-svc
COPY package.json package-lock.json ./
RUN npm ci --omit=dev

# ---- runtime -----------------------------------------------------------------
FROM node:22-alpine
ENV NODE_ENV=production
WORKDIR /srv/openbrain-svc

COPY --from=deps /srv/openbrain-svc/node_modules ./node_modules
COPY package.json ./
COPY src ./src

USER node
EXPOSE 7100

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD wget -qO- "http://127.0.0.1:${PORT:-7100}/healthz" || exit 1

CMD ["node", "src/index.js"]
