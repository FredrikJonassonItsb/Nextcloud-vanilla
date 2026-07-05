# capture-bot — Talk capture webhook (CONTRACTS §4, port 8790), Node 22, non-root.
FROM node:22-alpine

ENV NODE_ENV=production
WORKDIR /app

COPY package.json ./
RUN npm install --omit=dev --no-audit --no-fund && npm cache clean --force

COPY src ./src
COPY pii-patterns.json rooms.json.template ./

# rooms.json + the canonical pii-patterns.json + bootstrap.json are mounted
# read-only by compose; the bundled pii-patterns.json is only a fallback.

USER node
EXPOSE 8790

HEALTHCHECK --interval=30s --timeout=3s --start-period=10s \
  CMD wget -qO- http://127.0.0.1:8790/healthz || exit 1

CMD ["node", "src/server.js"]
