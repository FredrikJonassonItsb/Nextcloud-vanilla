# ingestion — native källinhämtning till openbrain

Native (Node ESM) re-implementation av kb-pipelines inhämtning, mot Hubs-stacken.
Följer [`skills/ingestion/source-connector-contract`](../skills/ingestion/source-connector-contract/SKILL.md):
**inkrementell** (cursor per källa/subscope), **rådata bevaras** (`data/raw/`), **normaliserad**
till Evidence → skrivs till en persons/teamets brain via openbrain-svc `POST /ingest`
(dedup via `content_fingerprint`, `422` = PII-brandvägg = vägran, final).

Status: **Zammad-konnektorn byggd** (pilot, högst signal). Talk/Mail/Meetings/GitLab
enligt skill-katalogen härnäst.

## Struktur
```
src/
  cli.js              extract --source <s> --brain <b> [--since 1y]
  config.js           env + defaults + brain-mappning (BRAIN_URL_*/BRAIN_KEY_*)
  brain.js            openbrain-svc /ingest-klient (Bearer, 422=block)
  httpclient.js       fetch + retry/backoff
  normalize.js        htmlToText / parseTs / maxIso / toBrainPost
  cursors.js          fil-baserad cursor (data/cursors.json)
  rawstore.js         data/raw/<källa>/<id>.json
  connectors/base.js  Connector-kontraktet
  connectors/zammad.js
test/zammad.test.js   enhetstester (utan nätverk)
```

## Köra
```bash
# lokalt (kräver Node 22)
ZAMMAD_TOKEN=... BRAIN_KEY_TEAM=... BRAIN_URL_TEAM=http://localhost:7105 \
  node src/cli.js extract --source zammad --brain team --since 1y

npm test        # enhetstester

# i stacken (batch)
docker compose run --rm ingestion extract --source zammad --brain team
```

Sista raden: `RUN_RESULT: source=… brain=… total=… created=… merged=… blocked=… failed=…`.

## Env
| Var | Vad |
|---|---|
| `ZAMMAD_TOKEN` | Zammad HTTP-token (scope `ticket.agent`) |
| `ZAMMAD_BASE_URL` | default `https://zammad.itsl.se` |
| `BRAIN_URL_<NAME>` / `BRAIN_KEY_<NAME>` | målhjärna (REB/ATLAS/ADA/MARVIN/TEAM) — samma som capture-bot |
| `INGEST_SINCE` | historiskt fönster, default `1y` (`30d`/`6m`/`1y`/ISO) |
| `INGEST_EXPERT_NAMES` | kommaseparerad expertlista för `author_is_expert` (default `johan`) |
| `INGEST_DATA_DIR` | cursors + rådata, default `data` |

## Compose (att wira in — ej gjort än)
```yaml
  ingestion:
    build: ../ingestion
    profiles: ["ingestion"]        # startar inte automatiskt; körs via `docker compose run`
    networks: [openstack]
    depends_on: [brain-team]
    environment:
      ZAMMAD_TOKEN: ${ZAMMAD_TOKEN:-}
      ZAMMAD_BASE_URL: ${ZAMMAD_BASE_URL:-https://zammad.itsl.se}
      BRAIN_URL_REB: http://brain-reb:7101
      BRAIN_URL_ATLAS: http://brain-atlas:7102
      BRAIN_URL_ADA: http://brain-ada:7103
      BRAIN_URL_MARVIN: http://brain-marvin:7104
      BRAIN_URL_TEAM: http://brain-team:7105
      BRAIN_KEY_REB: ${BRAIN_KEY_REB:-}
      BRAIN_KEY_ATLAS: ${BRAIN_KEY_ATLAS:-}
      BRAIN_KEY_ADA: ${BRAIN_KEY_ADA:-}
      BRAIN_KEY_MARVIN: ${BRAIN_KEY_MARVIN:-}
      BRAIN_KEY_TEAM: ${BRAIN_KEY_TEAM:-}
    volumes:
      - ingestion-data:/app/data
# + volume:  ingestion-data:
# cron (daglig 02:30):  docker compose run --rm ingestion extract --source zammad --brain team
```

Referens-implementation (Python) med alla 5 källor: `C:\Users\fredrik.jonasson\Cursor\Kunskapsbanken\kb-pipeline`.
