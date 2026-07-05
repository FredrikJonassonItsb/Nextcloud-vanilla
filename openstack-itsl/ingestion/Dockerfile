# ingestion — native source ingestion (batch/cron), Node ESM, matchar stackens
# konventioner (node:22, ESM, openbrain-svc /ingest-klient). Körs on-demand:
#   docker compose run --rm ingestion extract --source zammad --brain team
FROM node:22-bookworm-slim

ENV NODE_ENV=production
WORKDIR /app

# Inga runtime-deps ännu (Node 22 har inbyggd fetch); steget kvar om deps tillkommer.
COPY package.json ./
RUN npm install --omit=dev --no-audit --no-fund 2>/dev/null || true

COPY src ./src

# Cursors + rådata bevaras i en monterad volym (inkrementell + spårbar).
VOLUME ["/app/data"]

ENTRYPOINT ["node", "src/cli.js"]
