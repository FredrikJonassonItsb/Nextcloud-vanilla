# stack/ — driftrunbook för ITSL Open Stack (compose-stacken)

Compose-stacken enligt [CONTRACTS §4](../CONTRACTS.md). Bor på dev15 i
`/opt/openstack` med projektnamn `openstack`. Endast tre portar publiceras på
hosten: **8843** (caddy, TLS), **8790** (capture-bot), **8791** (runner).
Databasen nås aldrig utifrån.

| Tjänst | Vad | Internt |
|---|---|---|
| `brain-db` | pgvector/pgvector:pg16 — 6 databaser, volym `openstack_braindb` (stackens ENDA state) | 5432 |
| `brain-reb`…`brain-team` | openbrain-svc ×5 (MCP `/mcp`, `POST /ingest`, `GET /healthz`) | 7101–7105 |
| `caddy` | TLS + path-routing `/reb/*`→`brain-reb:7101` osv. | 8843 (host) |
| `capture-bot` | Talk-bot-webhook (`POST /bot`) | 8790 (host) |
| `runner` | agent-slots + wake-listener (`POST /wake/{agentCode}`) | 8791 (host) |
| `backup` | nattlig `pg_dump` 03:30 → `/opt/openstack/backups`, 14 d rotation | — |

Extern URL-yta: `https://dev15.hubs.se:8843/{reb,atlas,ada,marvin,team}/…`
(prefixet strippas; `/reb/mcp` → brain-reb `/mcp`).

## Deploy

Från Windows Git Bash i repo-roten:

```bash
bash stack/deploy.sh                       # default ubuntu@10.43.51.62
OPENSTACK_SSH="ubuntu@annan-host" bash stack/deploy.sh
```

Deployen är **idempotent**: tar-over-ssh av `stack/ openbrain-svc/ capture-bot/ runner/`
till `/opt/openstack`, sedan på servern:

1. `.env` (`/opt/openstack/.env`, chmod 600): saknade hemligheter genereras med
   `openssl rand -hex 32`; **befintliga värden skrivs aldrig över**. Tomma
   Fredrik-nycklar (`OPENROUTER_API_KEY`, `ANTHROPIC_API_KEY`, `BOT_APP_PASSWORD_*`)
   läggs till tomma.
2. TLS: LE-katalogen under `/opt/project_data/proxy/letsencrypt/live/*` vars cert
   täcker `dev15.hubs.se` autodetekteras (openssl) och **kopieras** (`cp -L`) till
   `/opt/openstack/certs` (live-katalogen är symlänkar → kan inte bind-mountas).
   Saknas cert genereras self-signed fallback. **Cert-förnyelse plockas upp genom
   att köra deploy.sh igen** (lägg gärna som månatlig cron).
3. `docker compose up -d --build` + omkörning av idempotent DB-init
   (roller/databaser/schema — nya migrationer och roterade lösenord appliceras).
4. Väntar på healthchecks och skriver statustabell.

Efter första deployen: fyll i `OPENROUTER_API_KEY` och `ANTHROPIC_API_KEY` i
`/opt/openstack/.env`, klistra in `BOT_APP_PASSWORD_*` från `occ-provision`, och
kör `bash stack/deploy.sh` igen (eller `sudo docker compose --env-file /opt/openstack/.env up -d`
i `/opt/openstack/stack`). `RUNNER_ENABLED=1` först när smoke-01…06 är gröna.

## Loggar & status

```bash
ssh ubuntu@10.43.51.62
cd /opt/openstack/stack
sudo docker compose --env-file /opt/openstack/.env ps
sudo docker compose --env-file /opt/openstack/.env logs -f --tail=100 brain-reb
sudo docker compose --env-file /opt/openstack/.env logs -f capture-bot runner
curl -k https://dev15.hubs.se:8843/healthz     # aggregerad hälsa (alla hjärnor)
curl -k https://dev15.hubs.se:8843/reb/healthz # en enskild hjärna
```

Healthcheck-kontrakt: hjärnorna, capture-bot och runner förväntas svara 200 på
`GET /healthz` på sin PORT (node-baserad healthcheck i compose).

## Backup & restore

Nattlig `pg_dump -Fc` per databas kl 03:30 (Europe/Stockholm) till
`/opt/openstack/backups/<db>_<datum>.dump`, rotation 14 dagar.

```bash
# manuell backup nu
sudo docker compose --env-file /opt/openstack/.env exec backup /usr/local/bin/backup.sh

# restore av en hjärna (exempel brain_reb)
sudo docker compose --env-file /opt/openstack/.env exec backup \
  pg_restore --clean --if-exists -h brain-db -U postgres \
  -d brain_reb /backups/brain_reb_2026-07-05_0330.dump
```

Öva restore regelbundet (BYGGPLAN: restore-övningar är del av driftdisciplinen).
Offsite-kopiering av `/opt/openstack/backups` sker med ITSL:s befintliga
backupflöde utanför stacken.

## Databas-åtkomst (felsökning)

```bash
sudo docker compose --env-file /opt/openstack/.env exec brain-db psql -U postgres -d brain_reb
# isolering: u_reb kan bara ansluta till brain_reb, svc_engine bara till engine_meta
```

Schemaändringar görs i `brain-db/init/sql/*.sql` (idempotent SQL) och rullas ut
med `deploy.sh` — aldrig handpåläggning i psql. Rör aldrig `thoughts`-tabellens
befintliga kolumner (guard rail från OB1-dokumentationen).

## Nyckelrotation

- `BRAIN_KEY_*` / `TALK_BOT_SECRET_*` / `ENGINE_PUSH_SECRET`: töm raden i
  `/opt/openstack/.env` (lämna `NYCKEL=`), kör `deploy.sh` → nytt värde genereras.
  Uppdatera sedan motparten (occ talk:bot:install-secret resp.
  `occ config:app:set agent_engine engine_push_secret`).
- `DB_PASS_*`: samma flöde — DB-init kör `ALTER ROLE … PASSWORD` vid varje deploy.

## Flytt till prod (senare — INTE inatt, CONTRACTS §10)

1. Ny host: sätt upp docker + ssh-nyckel, kör
   `OPENSTACK_SSH=ubuntu@prod-host bash stack/deploy.sh` — allt state ligger i
   volymen `openstack_braindb` + `/opt/openstack/.env` + `/opt/openstack/backups`.
2. Flytta data: `pg_dump`-dumparna från backups → `pg_restore` på nya hosten
   (eller `docker volume`-kopia vid full flytt).
3. Cert: deployen autodetekterar prod-hostens LE-katalog; verifiera att certet
   täcker prod-domänen (annars self-signed-fallback + varning i deploy-loggen).
4. Uppdatera Nextcloud-sidan: `occ talk:bot:install`-URL:er (8790), agent_engine:s
   push-URL (8791) och brain-URL:erna i laptop-setupen pekas om till prod-hosten.
5. Kör smoke-01…06 mot prod innan `RUNNER_ENABLED=1`.
