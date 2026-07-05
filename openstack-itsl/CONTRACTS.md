# CONTRACTS — integrationskontrakt för ITSL Open Stack (v1, natten 2026-07-04)

**Detta dokument är lag för alla komponenter.** Ändringar här kräver att alla berörda
komponenter uppdateras i samma commit. Engelska protokoll-tokens är byte-identiska
med Nates Open Engine-spec (se docs/KARTLAGGNING.md §4.10).

## 1. Identiteter

| Människa (NC-uid) | Agent | Agentkod | Bot-NC-användare | Visningsnamn |
|---|---|---|---|---|
| rebecca | Reb | `reb-claude` | `bot-reb` | Reb (agent) |
| fredrik | Atlas | `atlas-claude` | `bot-atlas` | Atlas (agent) |
| sandra | Ada | `ada-claude` | `bot-ada` | Ada (agent) |
| mattias | Marvin | `marvin-claude` | `bot-marvin` | Marvin (agent) |
| — (system) | — | — | `bot-engine` | Agent Engine |

Bot→agentkod: strippa `bot-`, suffixa `-claude`. Bot→ägare enligt tabellen (= routingkartan v1).
**Människo-uid är PER-INSTANS-KONFIG, aldrig hårdkodade** (BankID-instanser använder
personnummer som uid — verifierat på dev15: Fredrik = `197411040293`; rebecca/sandra/mattias
finns ej ännu → PENDING-USERS.md). Routingkartan lagras som JSON i
`occ config:app:set agent_engine routing_map` med verifierade uid.
**uid-i-text-REGELN (bindande, se docs/DEV15-FACTS.md):** NC-uid får ALDRIG förekomma i
agentläsbar/agentgenererad text (kortbeskrivningar, kommentarer, thoughts-content) — där
används displaynamn. uid endast i DB-kolumner och strukturerade metadatafält. PII-brandväggen
skannar content/titel/beskrivning, INTE strukturerade metadatafält — annars 422:ar
personnummerregexen varje åtgärd av en BankID-användare.

## 2. Deck

- Tavla: **"Agent Engine"**, ägare `bot-engine`, delad med alla människor (edit) + alla bottar (edit).
- Stackar (exakt ordning): `Inbox`, `Standing`, `Agent Todo`, `Agent Working`, `Agent Needs Input`, `Agent Review`, `Agent Done`.
- Label på tavlan: `agent-instructions` (#B22222). Ursprungstavlor får labels `hos-agenten` (#1E66D0), `agent-fråga` (#E6A700), `agent-klar` (#2E7D32) vid enrollment.
- Titelgrammatik: `[agent instructions][<agentkod>][task] <titel>` · `[agent instructions][all agents][standing_skill|standing_status|standing_routing] <titel>`.
- Standing-kort (skapas av deck-bootstrap, id:n skrivs till `stack/state/bootstrap.json` på dev15):
  1. `[agent instructions][all agents][standing_skill] Install ITSL Agent Engine core context v1`
  2. `[agent instructions][all agents][standing_status] Agent Engine status ledger`
  3. `[agent instructions][all agents][standing_routing] Agent routing map v1`
  4. `[agent instructions][all agents][standing_skill] Optional standing skill directory`
- Kvittovokabulär (kommentarer, exakt): AGENT CLAIMED · AGENT DONE · AGENT BLOCKED · AGENT UNBLOCKED · AGENT HUMAN HOLD · AGENT HUMAN ANSWERED · AGENT RESUMED · AGENT FAILED · AGENT APPLIED · AGENT SKILL SUBSCRIBED · AGENT SKILL INSTALLED · AGENT SKILL UPDATED · AGENT SKILL DECLINED · AGENT FOLLOW-UP · AGENT STATUS · (ITSL-tillägg, endast ursprungskort: prefix `⇄ `).
- Deck-kommentarer: max 1000 tecken → alla kvitton/statusar skrivs ≤900 tecken; längre innehåll läggs i kortbeskrivning eller bilaga.

## 3. agent_engine (NC-app, PHP, NC31)

App-id `agent_engine`. Följer hubs_arende-konventioner (OCS controllers, Db\Entity+Mapper,
migrations, IEventListener). Tabeller: `oc_agent_engine_links` (card_links: origin_board,
origin_card, engine_card, agent_code, owner_uid, requester_uid, reviewer_uid, state
[open|review|done|recalled|refused], per-riktning-cursors, idempotency, timestamps, UNIQUE
öppen länk per origin_card), `oc_agent_engine_boards` (enrolled_boards + per-tavla-flaggor),
`oc_agent_engine_events` (idempotens/audit för webhooks och sweeps).

OCS-bas: `/ocs/v2.php/apps/agent_engine/api/v1`. Endpoints (alla kräver auth; "bot" = bot-användare via app-lösenord):

| Metod+Path | Vem | Semantik |
|---|---|---|
| `POST /claim/{engineCardId}` | bot | Atomisk claim i EN transaktion: verifiera stack=Agent Todo + label + titelkod=anroparens agentkod → flytta till Agent Working → posta `AGENT CLAIMED` → 200 `{cardId, reread}`. Annars 409 `{claimedBy}` / 422. |
| `GET /queue/{agentCode}` | bot | Nästa eligible kort (äldst först) + öppna BLOCKED/HOLD/Review-resumés för agenten. Serversidig filtrering (Deck saknar det). |
| `PUT /ledger/{agentCode}` | bot | Upsert AGENT STATUS-kommentaren på liggarkortet, på plats. Body = statusfälten (KARTLAGGNING §4.8-format). |
| `POST /receipt/{engineCardId}` | bot | Posta kvitto-kommentar + ev. stackflytt (`move`: needs_input|review|done|working). Validerar token ∈ vokabulären. |
| `POST /origin-note/{engineCardId}` | bot | Relä: skriv/uppdatera ⇄-kommentar på ursprungskortet via card_links (tolkning/fråga/klart). Uppdaterar labels enligt state-maskinen. |
| `GET /takeover/config` · `PUT /boards/{boardId}/enroll` | admin | Enrollment-administration. |
| `POST /push-test` | admin | Fan-out-test till runner-listenern. |

Event-lyssnare (in-process, NC event dispatcher — VERIFIERAT på dev15, se docs/DEV15-FACTS.md):
Deck 1.15.9 har INGEN separat assignment-event, men `AssignmentService::assignUser/unassignUser`
dispatchar `CardUpdatedEvent` → lyssna på `CardCreatedEvent`+`CardUpdatedEvent`+`CardDeletedEvent`
och diffa `assignedUsers` mot senast kända state (oc_agent_engine_events) → takeover-pipeline (INTERAKTIONSDESIGN §2.3: PII-firewall
FÖRST, sedan engine-kort i Agent Todo, spegelkommentar, label, card_links, närvarokoll,
HMAC-push). Kommentar på länkat kort → spegling enligt §2.4 (aktörsfilter: bot-authored
ignoreras strukturellt; ⇄-prefix = engine-ursprung; idempotencynyckel per kommentar-id).
2-min-svep som BackgroundJob (korrekthetsgolv för missade events).

HMAC-push till runner: `POST http://10.43.51.62:8791/wake/{agentCode}`,
headers `X-AE-Timestamp`, `X-AE-Signature = hex(hmac_sha256(ENGINE_PUSH_SECRET, timestamp + "." + agentCode))`.
Tolerans ±300 s. Ingen payload (runnern hämtar själv via /queue).

PII-brandväggen (delad regexlista, källa `stack/shared/pii-patterns.json`, genereras från
hubs_arende-mönstren): personnummer (`\b(19|20)?\d{6}[-+]?\d{4}\b`), `sk-ant-`, `sk-or-v1-`,
`AKIA[0-9A-Z]{16}`, hubsCaseId-UUID:er i case-kontext, BankID-nr. Träff ⇒ 422 + mänskligt
läsbar vägran. Samma lista används av openbrain-svc (ingest) och capture-bot.

## 4. Compose-stacken (dev15: `/opt/openstack`, projektnamn `openstack`)

| Tjänst | Image/bygge | Port (host) | Anteckning |
|---|---|---|---|
| `brain-db` | pgvector/pgvector:pg16 | — (internt) | Volym `openstack_braindb`. DB:er: brain_reb, brain_atlas, brain_ada, brain_marvin, brain_team, engine_meta. Roller: `u_reb`…`u_team` (LOGIN, endast egen DB), `svc_engine` (endast engine_meta). init-SQL idempotent. |
| `brain-reb` … `brain-team` | `openbrain-svc` (eget bygge, ×5 instanser) | 7101–7105 → endast via caddy | MCP (streamable HTTP `/mcp`) + `POST /ingest` + `GET /healthz`. Bearer `BRAIN_KEY_<NAME>`. |
| `caddy` | caddy:2 | **8843** (TLS) | Path-routing: `/reb/* → brain-reb` osv. Cert: read-only-mount av dev15:s LE-cert (`/opt/project_data/proxy/letsencrypt/...`, exakt path verifieras vid deploy; fallback: caddy internal CA + CA-fil i setup-laptop). |
| `capture-bot` | eget bygge (Node 22) | **8790** | Talk-bot-webhook. HMAC per rum-bot (`TALK_BOT_SECRET`). Rum→brain-routing i `capture-bot/rooms.json` (genereras av occ-provision). |
| `runner` | eget bygge (Node 22 + claude CLI) | **8791** | 4 agent-slots, flock per agentkod, cron (staggrad :00/:07/:15/:22 var 30:e min) **gated på `RUNNER_ENABLED=1` OCH satt `ANTHROPIC_API_KEY`**, wake-listener enligt §3. Persistens i engine_meta (kostnadslogg, run-journal). |
| `backup` | eget bygge (alpine+pg_dump+cron) | — | Nattlig pg_dump per DB → `/opt/openstack/backups` (+ rotation 14 d). |

Nät: alla i compose-nätet `openstack`; endast 8843/8790/8791 publiceras på host.
`.env` på servern (`/opt/openstack/.env`, chmod 600) — nycklar läses ALDRIG in i git.
`.env.example` listar alla variabler. `deploy.sh` = rsync + `docker compose up -d --build`
+ migrations + healthchecks; idempotent.

## 5. openbrain-svc (per-hjärna-tjänst)

Bas: vendorad OB1 `server/` (MCP-verktygen thought_create/search/fetch/list_recent m.fl.
enligt digest ob1-core-docs) anpassad: Postgres-URL ur env (ej Supabase), bearer-auth,
`POST /ingest {content, source, author?, metadata?}` (för capture-bot + hooks),
**skriv-brandvägg** (pii-patterns.json → 422 FÖRE embedding-anrop), embeddings via
OpenRouter (`text-embedding-3-small`, 1536 dim) med **pending-läge**: om
`OPENROUTER_API_KEY` saknas/fallerar sparas tanken med `embedding=NULL, metadata.embed_pending=true`;
backfill-worker var 5:e min embeddar pending när nyckel finns. Sök: vektor om embedding
finns, annars ILIKE-fallback + varning i svaret. Env: `DATABASE_URL`, `BRAIN_KEY`,
`OPENROUTER_API_KEY?`, `EMBED_MODEL`.

## 6. capture-bot

Ett Talk-bot-webhook-endpoint (`POST /bot`): verifiera HMAC (`X-Nextcloud-Talk-Signature`,
per-bot-secret ur env), dedupe på message-id (engine_meta.capture_seen), routing:
rum "Reb minne" → brain-reb osv., "Team minne" → brain-team (author = talarens uid).
Svar: 👍-reaktion + trådad bekräftelse "Sparat i <hjärna>" (ELLER "Blockerat: …" vid 422).
`!queue <text>` i valfritt agentrum → skapa Inbox-kort på Agent Engine-tavlan via Deck REST
(som bot-engine) + svara med kortlänk. `!status` → läs liggaren via agent_engine och svara
kompakt. Bots registreras: `occ talk:bot:install "Reb (agent)" <secret> http://10.43.51.62:8790/bot`
+ `talk:bot:setup <botId> <roomToken>`.

## 7. runner

Loop per slot (körs endast om nyckel finns): flock → `GET /queue/{agentCode}` →
om resumable/eligible: `claude -p` (headless) med `runner/prompts/queue-run.md`
(Nates 19 steg Deck-adapterade + INTERAKTIONSDESIGN 12b tolknings-checkpoint) +
`--allowedTools` whitelist (Bash begränsad till `engine-api.sh`, `brain-api.sh`;
läsverktyg `Read`/`Grep` + läs-webb `WebSearch`/`WebFetch` — Bash-sandlådan förblir
snäv, så ett fientligt kort ändå inte kan shell:a ut eller läsa hemligheter) +
`--max-turns 40`. Verktygsskal: `runner/bin/engine-api.sh` (claim/receipt/ledger/origin-note
via curl mot agent_engine, bot-app-lösenord ur env), `runner/bin/brain-api.sh`
(recall/writeback mot egen hjärna). Exakt EN task per körning. Kostnadslogg per körning
→ engine_meta.run_log (tokens, USD-estimat); dagstak `RUNNER_DAILY_USD_CAP` (default 10)
per agent → överskridet ⇒ slot pausad + larm i Talk-rummet "Agent Ops".

## 8. Miljövariabler (fullständig lista = stack/.env.example)

`BRAIN_KEY_REB|ATLAS|ADA|MARVIN|TEAM` (32B hex, genereras vid deploy) ·
`OPENROUTER_API_KEY` (**TOM — Fredrik**) · `ANTHROPIC_API_KEY` (**TOM — Fredrik**) ·
`ENGINE_PUSH_SECRET` (32B hex, delas agent_engine↔runner via occ config:app:set) ·
`TALK_BOT_SECRET_REB|ATLAS|ADA|MARVIN` · `NC_BASE=https://dev15.hubs.se` ·
`BOT_APP_PASSWORD_REB|ATLAS|ADA|MARVIN|ENGINE` (genereras av occ-provision via user:auth-tokens:add) ·
`RUNNER_ENABLED` (0 tills nycklar satta) · `RUNNER_DAILY_USD_CAP=10` · `EMBED_MODEL=openai/text-embedding-3-small`.

## 9. Smoke tests (tests/, alla körbara från repo mot dev15, exit≠0 = rött)

`smoke-01-key-matrix.sh` (5×5-diagonalen) · `smoke-02-capture-roundtrip.sh` (Sarah-frasen,
dedupe, HMAC-manipulation→401, personnummer→422) · `smoke-03-claim-race.sh` (2 parallella
claims → exakt en 200+en 409) · `smoke-04-ledger-upsert.sh` (×2 → EN kommentar) ·
`smoke-05-takeover.sh` (assign bot → engine-kort ≤2 min + spegel + label; unassign → recall) ·
`smoke-06-sync-loop.sh` (spegling studsar aldrig: N kommentarer in ⇒ exakt N ut, 0 extra) ·
`smoke-07-runner-hello.sh` (**kräver ANTHROPIC_API_KEY**; hello-world-kortet CLAIMED→DONE→liggare) ·
`smoke-08-hostile-card.sh` (injektionskort → AGENT BLOCKED, inga sidoeffekter).

## 10. Vad som INTE byggs inatt (medvetet)

Agent-memory-sidecar (M12) · "Min agent"-widget (M7.5) · aiception/auto-capture-hooks (M8) ·
optional skill-katalogens innehåll · prod-cutover. Allt annat i M1–M6 + M4.5 byggs.
