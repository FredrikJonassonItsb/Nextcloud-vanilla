# Implementationsstatus — ITSL Open Stack (Agent Engine)

_Datum: 2026-07-05. Underlag: komponentgranskning av 9 bedömare (stack, openbrain-svc, capture-bot, agent_engine, runner, skills, provision, tests-docs) + `openstack-itsl/docs/MORGONRAPPORT-2026-07-05.md` (verifierade smoke-tester) + `openstack-itsl/docs/CONTRACTS.md` + `openstack-itsl/docs/BYGGPLAN.md`._

---

## 0. Uppdatering EFTER granskningen (2026-07-05, senare) — läs detta först

Fyra saker har ändrats/klarnat sedan bedömarna körde. De korrigerar §3/§5/§6 nedan:

1. **Teamet är UPPLAGT.** Rebecca (`rebecca.dumky`), Sandra (`sandra.larsson`), Mattias
   (`mattias.hedman`) finns nu på dev15. `occ-provision.sh` + `deck-bootstrap.mjs --humans …`
   är omkörda: alla fem Talk-minnesrum finns, routingkartan har alla fyra agenter, tavlan är
   delad med de riktiga uid:na, `PENDING-USERS.md` borttagen. **M0-operativt är därmed i mål.**

2. **"Deck-deltagarcachen" var FEL diagnos — det är en äkta Deck-bugg med numeriska uid.**
   Deck `AssignmentService::assignUser` gör `in_array($userId, array_keys(findUsers(...)), true)`.
   PHP tvingar numeriska sträng-nycklar till **integers** i arrayen, och den **strikta** (`===`)
   jämförelsen matchar då aldrig uid-**strängen**. Verifierat: `rebecca.dumky` → assign **200**;
   `197411040293` (BankID-personnummer) → **400**, även efter timmar i ACL:en. **Konsekvens:**
   assignee på engine-kort fungerar för alla normala uid (hela teamet UTOM Fredrik) men INTE för
   BankID-personnummer-uid — vilket skarpa **itsl.hubs.se** använder för alla. Takeovern
   tolererar felet (kortet skapas ändå, utan assignee). Enda kvarvarande "riktiga" blockern;
   bör åtgärdas med en Deck-patch (se §6 ny #1).

3. **smoke-05/06:s recall-fel var testartefakter, inte kodfel.** När runnern är igång claimar
   den engine-kortet (phase=working), så `RecallService` gör korrekt en **kooperativ avbrytning**
   (RECALL REQUESTED) i stället för direkt arkivering — precis enligt design. Testet antar ett
   oclaimat kort. Med runnern stoppad följer recall den omedelbara arkiveringsvägen.

4. **De tre latenta `engine-api.sh` wire-buggarna är FIXADE och verifierade.** `receipt` skickade
   `message`→controllern binder `text`; `origin-note` skickade `note`→`text` (relä-noten var TOM!);
   `ledger` skickade snake_case→controllern läser camelCase (alla liggarfält tappades — den
   VERKLIGA orsaken till smoke-07:s heartbeat-fel, inte bara prompt-tuning). engine-api.sh
   översätter nu snake_case→camelCase och använder `text`. Verifierat e2e: `Last queue result:
   wire-fix-verify` landade korrekt på liggarkortet.

---

## 1. Sammanfattning

**Helhetsbild: hela systemet är byggt och deployat på dev15, och kärnloopen fungerar bevisat end-to-end.** Alla åtta programvarukomponenter är fullständiga, icke-stubbade implementationer — inga `TODO`/`FIXME`/`not implemented`-markörer finns någonstans i någon komponents källkod. Det som återstår är inte grundfunktion utan (a) några Deck-cache-relaterade edge-case i realtidsvägen, (b) prompt-tuning på runnerns bokföring, (c) driftshärdning (restore, larm), och (d) den framåtblickande delen av BYGGPLAN (M8–M12: agent-minne-sidecar/governance, "Min agent"-widget M7.5, underhållsloop-automation).

**Grov position mot BYGGPLAN: ca M0–M7-substratet är byggt och deployat; M0-idrifttagningen och de senare milstolparna (M8+) återstår.** Byggplanens tekniska förutsättningar (M1–M7) är i huvudsak levererade som kod; det som saknas är operativt (lägga upp teamet, utreda Deck-cachen) och de rent framåtblickande milstolparna.

**Bevisat end-to-end (verifierat live/smoke på dev15, per MORGONRAPPORT):**

- **Nyckelisolation** — 5×5-matris, 30/30 grönt (smoke-01). Varje hjärna nås bara med sin egen nyckel.
- **Atomiskt claim-lås** — två parallella claims → exakt en vinnare + en 409, 5/5 grönt (smoke-03).
- **Ledger upsert på plats** — en kommentar per agent, ingen pile-up, 6/6 grönt (smoke-04).
- **Talk-capture + PII-brandvägg + HMAC + dedupe** — personnummer → 422, grönt (smoke-02, brandväggs-/HMAC-delen).
- **Takeover i realtid** — manuellt verifierat: tilldela `bot-atlas` → engine-kort `[agent instructions][atlas-claude][task] …` skapas inom sekunder med komplett 8-sektionsmall, default-deny-Boundaries, `hos-agenten`-label + ⇄-kvitto och card_link (smoke-05: 19/23).
- **Headless runner-cykel** — HMAC-wake → claim → `claude -p` → AGENT DONE → Agent Done, bevisad live (kortet AE-255, 23 turns, $0.08).

**Ärlig brasklapp:** smoke-06 (spegling utan studs) och de sista assertionerna i smoke-05/07 hänger på ett Deck-deltagarcache-beteende (assignee/recall i realtid), och smoke-08 (injektionskort) var ännu inte körd vid rapporttillfället. Inga av dessa är kodfel — de är kartlagda edge-case respektive prompt-tuning.

---

## 2. Fullt implementerat

| Capability | Var i koden | Verifierat |
|---|---|---|
| Compose-stack: 10 tjänster, healthchecks, `depends_on: service_healthy`-ordning | `stack/docker-compose.yml:23-263` | smoke-01 grön; `docker compose ps` alla healthy (MORGONRAPPORT §1) |
| brain-db init: 6 roller + 6 DB, per-agent-isolation, CONNECT revokad från PUBLIC, pgvector-schema | `stack/brain-db/init/00-init.sh`, `10-thoughts.sql`, `20-engine-meta.sql` | smoke-01 (30/30 nyckelisolation) |
| caddy path-routing + TLS-terminering `/reb/*…/team/*` | `stack/caddy/Caddyfile:33-47` | MORGONRAPPORT §1 (rätt hjärna) |
| Nattlig pg_dump-backup, 14 d rotation, partial-dump=fail | `stack/backup/backup.sh:17-32`, `Dockerfile:5-17` | container healthy |
| deploy.sh idempotens + cert-autodetect (LE SAN-match, self-signed fallback) | `stack/deploy.sh:66-136` | — (statisk granskning; idempotens-guards verifierade) |
| 6 MCP-verktyg (search/fetch/search_thoughts/list_thoughts/thought_stats/capture_thought), riktig SQL | `openbrain-svc/src/mcp.js:36-304`, `store.js` | 3 testsuiter mot riktiga pii-patterns.json |
| PII-skrivbrandvägg FÖRST på båda ingress-skrivvägar (/ingest + capture_thought) | `openbrain-svc/src/store.js:94-99`, `app.js:64-133` | `firewall.test.js`, `pending.test.js`, `app.test.js` |
| OpenRouter-embeddings + pending/backfill; vektor-vs-ILIKE-fallback-sök | `openbrain-svc/src/store.js:51-82,158-200`, `openrouter.js` | pending.test.js; **live-verifierad** (MORGONRAPPORT §6: capture→embedding→metadata→semantisk sök) |
| Talk-webhook HMAC-verify (konstant tid), claim-first dedupe, room→brain-routing | `capture-bot/src/verify.js`, `dedupe.js`, `route.js`; `handler.js:87-108` | smoke-02; `verify/dedupe/routing.test.js` |
| `!queue` Deck-Inbox-brygga + `!status` ledger-digest | `capture-bot/src/deck.js`, `engine.js`; `handler.js:52-118` | `handler.test.js` (queue/status/deck-fail) |
| Ingest-before-reply-ordning + fail-closed firewall i capture-bot | `capture-bot/src/handler.js:127-187`, `server.js:29-33` | `handler.test.js` (transient→500+claim released) |
| ClaimService atomiskt mutex (unik `event_key`), 409/422-mappning | `agent_engine/lib/Service/ClaimService.php:54-144` | smoke-03 (5/5); unit `ClaimServiceRaceTest` |
| QueueService server-side-filter (stack/label/titelgrammatik), FIFO | `agent_engine/lib/Service/QueueService.php:41-98` | unit `QueueServiceTitleTest` |
| LedgerService upsert-in-place (en AGENT STATUS-kommentar/agent) | `agent_engine/lib/Service/LedgerService.php:54-180` | smoke-04 (6/6) |
| TakeoverService: PII-firewall FÖRST → open-link-mutex → 8-sektionsmall → assign+label → HMAC-push; kompensation vid fel | `agent_engine/lib/Service/TakeoverService.php:155-259` | smoke-05 (19/23); unit `TakeoverServicePiiTest`; **manuellt verifierad** (MORGONRAPPORT §2) |
| MirrorService loop-prevention (4 broms: actor-filter, ⇄-markör, link-gate, idempotens-key) + label-state-machine | `agent_engine/lib/Service/MirrorService.php:63-240` | unit `MirrorServiceLoopTest` |
| RecallService kooperativ cancel (recall_requested vid working; closeNow annars); completion-beats-recall | `agent_engine/lib/Service/RecallService.php:45-108` | manuellt (unassign→recall, MORGONRAPPORT §2) |
| PiiFirewall (refuse-not-scrub, inbyggda mönster + delad fil som override, innehåll loggas aldrig) | `agent_engine/lib/Service/PiiFirewall.php:33-139` | smoke-05 PII; unit |
| SweepJob 4 pass var 120 s (korrekthetsgolv) | `agent_engine/lib/BackgroundJob/SweepJob.php`; `appinfo/info.xml` | — (schemalagt; NC-cron) |
| 8 OCS-endpoints + BotGuard (identitet==auth, mayActFor) | `agent_engine/appinfo/routes.php`; `lib/Controller/*` | smoke-03/04/05 (via endpoints) |
| Runner: key-gate + arm/disarm (ingen körning utan nyckel) | `runner/entrypoint.sh:25-40`; `run-agent.sh:50-53` | MORGONRAPPORT §3 (RUNNER_ENABLED=1) |
| HMAC wake-listener (timing-safe, ±300 s, per-agent debounce) + staggered cron | `runner/wake-listener.js`; `runner/crontab` | **live**: "wake accepted for reb-claude" (MORGONRAPPORT §3) |
| Per-agent flock + daglig USD-cap läst tillbaka från `run_log` (samma tabell den skriver) | `runner/bin/run-agent.sh:60-262` | **live**: "daily spend $0, cap $10" (MORGONRAPPORT §3) |
| `claude -p` med snävt allowedTools (engine-api.sh/brain-api.sh + Read/Grep + läs-webb WebSearch/WebFetch; Bash-sandlådan snäv) | `runner/bin/run-agent.sh:192-197` | **live** (AE-255 slutförd) |
| HOME/PATH-fix (HOME=/home/runner, /app/bin i PATH) | `runner/bin/run-agent.sh:187-189` | **live**: claude kör som runner (MORGONRAPPORT §4.8) |
| provision: idempotenta occ-provision.sh / deck-bootstrap.mjs / enroll-board.mjs / deploy-app.sh; per-instans-uid (personnummer) | `provision/*` | on-disk `state/bootstrap.json` (board 10), `PENDING-USERS.md` |
| Smoke-svit (8 script + 842-raders lib.sh) + CONTRACTS.md/README/.env.test.example | `tests/*`, `docs/CONTRACTS.md` | self-hosting; körd på dev15 (MORGONRAPPORT §2) |

---

## 3. Delvis implementerat

| Capability | Vad som finns | Vad som saknas / gap | Varför |
|---|---|---|---|
| **Takeover/recall i realtid vid tilldelning** | Listenern hämtar kortet färskt och kör reconcileCard; svep var 2:a min som golv | Assignee sätts inte på engine-kortet i realtid; recall arkiverar inte direkt (smoke-05 4/23, smoke-06 blockerad) | **Deck-deltagarcache** (APCu/Redis) invalideras inte när ACL läggs till → nyss delad bot är inte tilldelningsbar. Deck saknar dessutom assign/unassign-event. Ej kodfel (`DeckCardListener.php:20-42`) |
| **Runner-ledger-heartbeat** | `completed AE-<id>` skrivs när modellen kallar `engine-api.sh ledger` | Heartbeat skrevs inte i smoke-07 (2/7 kvar); ingen oberoende liveness-signal | **Prompt-tuning** — Claude gjorde jobbet men hoppade sista bokföringsraden. Heartbeat är bara så levande som modellens samarbete (`run-agent.sh` ledger-väg) |
| **Full köprotokoll i runnern** | Prompten stavar ut alla 21 steg (claim, recall steg 13, writeback steg 19, 12b takeover-note) | Runnern verifierar bara sista `RUN_RESULT`-raden — ingen kod-nivå-koll att recall/writeback faktiskt kördes | By design: `queue-run.md` är instruktionstext till modellen, inte enforced control flow |
| **smoke-02 semantisk halva** | Firewall/HMAC/dedupe grönt; embeddings live-verifierade | "rad landar i brain-reb"-assertions ej bekräftade i körningen | `rebecca` ej upplagd på dev15 → Reb-capture-rummet finns inte (MORGONRAPPORT §5) |
| **smoke-08 hostile-card** | Testet är fullt implementerat inkl. env-leak-scan mot verkliga secret-värden | Var ännu inte körd vid rapporttillfället | Grön status "by construction", inte observerad (MORGONRAPPORT §2) |
| **Cost-cap robusthet** | Dubbelkoll (pre+post-run) mot run_log; per-agent flock | Cap tyst av om `ENGINE_META_DSN` saknas; run_log-INSERT-fel underräknar spend | Dokumenterat: utan DSN finns ingen run_log; endast `--max-turns 40` bundar då en körning (`run-agent.sh:154-156`) |
| **skills CLI vs engine-api.sh** | Alla 4 SKILL.md + 4 identiteter är substantiella; endpoints/tokens stämmer | SKILL.md dokumenterar `--body`/`--field`/`recall`/`writeback`/`--team` som **inte finns** i scripten (verkliga: `--message`, positionella, JSON, `search`/`create`) | Doc-drift: `queue-run.md` är det korrekta kontraktet; SKILL.md har glidit isär |
| **engine-api.sh wire-buggar (latenta)** | receipt/ledger-vägarna finns | receipt/origin-note skickar `message`/`note` där controllern binder `text` → tyst tappad text; ledger skickar snake_case där servern läser camelCase → default-fält | Smoke-sviten **kringgår** engine-api.sh med egen korrekt-nycklad helper → oupptäckt e2e (skills-granskning) |
| **caddy aggregerad /healthz** | `/healthz` inlinar varje hjärnas body | Ingen automatisk cross-brain-liveness-gate; caddy egen healthcheck testar bara `/ping` | By design (operatör-probe); en nere hjärna gör inte caddy unhealthy |
| **deploy.sh unhealthy-hantering** | Detekterar "starting" vs "unhealthy", skriver varning | Exitar inte nonzero på unhealthy icke-DB-tjänst → "Deploy klart." ändå | Detektering finns, fail-wiring saknas (`deploy.sh:187-189`) |
| **PII-firewall vs bilaga-INNEHÅLL** | Skannar bilage-NAMN på copy-path | Personnummer inuti bifogat dokument kopieras utan inspektion | Konsekvent med spec (copy path = titel/beskrivning/namn), men reell PII-gap för filkroppar |

---

## 4. Ej påbörjat

Följande i BYGGPLAN/INTERAKTIONSDESIGN är inte byggt än (och är i huvudsak framåtblickande milstolpar, inte kärnfunktion):

- **"Min agent"-widget (M7.5)** — daglig överblick per handläggare (INTERAKTIONSDESIGN §5). Ingen kod. Rekommenderad som tidigt värdesteg av MORGONRAPPORT §8.
- **Agent-minne-sidecar / governance (M8/M12)** — styrning och livscykel för agentminne bortom capture/recall. Ej byggt.
- **Underhållsloop-automation / "aiception" (senare M)** — självunderhållande drift-loop. Ej byggt.
- **Smoke-test för människo-arbetsflödets state machine** — BLOCKED→HUMAN ANSWERED→UNBLOCKED→RESUMED→completion; AGENT HUMAN HOLD-vägen; Agent Review approve/rework (3-cykel-cap). Specade i CONTRACTS/BYGGPLAN/IXD men **saknar smoke-täckning** (delvis täckt av unit-tester). `grep` i `tests/smoke-*.sh` hittar inga UNBLOCKED/RESUMED/HUMAN HOLD/rework-assertions.
- **Smoke-test för stall/near-miss-detektorer** — pre-claim-stall >4h, gest-seen-men-ingen-takeover >10 min, andra-bot-på-samma-kort, label-rename-near-miss (INTERAKTIONSDESIGN §2.8). Ingen regressionstäckning.
- **Prioritetsmodell i kön** — FIFO only, medvetet uppskjutet (`QueueService.php:85`, "GAP 11").
- **Automatiserad restore + restore-verifiering** — backup finns, men restore är en manuell pg_restore-runbook (`stack/README.md:74-78`); ingen `restore.sh`, ingen integritetskoll, inget backup-fail-larm.
- **Offsite-backup i stacken** — explicit delegerad utanför stacken (`stack/README.md:81-82`).
- **Oberoende runner-watchdog** — inget externt bevakar crash-loop eller fast-pausad slot; ingen retry/dead-letter för claimat-men-kraschat kort.

**Operativa (M0) uppgifter som väntar (ej kod):**
- Lägg upp `rebecca`, `sandra`, `mattias` på dev15 (`provision/PENDING-USERS.md`), kör om provisionering + `deck-bootstrap.mjs --humans <uid,…>`.
- Kör om bootstrap med korrekta personnummer-uid (fantom-ACL:er från kanoniska namn).

---

## 5. BYGGPLAN-milstolpar M0–M12

| Milstolpe | Status | Kommentar |
|---|---|---|
| **M0** Idrifttagning / routingkarta / team upp | 🟡 Delvis | Stack deployad; team ej upplagt, Deck-cache att utreda, uid-bootstrap att köra om |
| **M1** Compose-stack + brain-db + isolation | ✅ Klar | 10 tjänster healthy, 30/30 nyckelisolation (smoke-01) |
| **M2** Open Brain MCP + capture + PII-firewall | ✅ Klar | smoke-02 (firewall/HMAC/dedupe); embeddings live |
| **M3** capture-bot (Talk→hjärna, !queue/!status) | ✅ Klar | Alla 7 capabilities testade; multi-bot inbound OK |
| **M4** agent_engine core (claim/queue/ledger/takeover/mirror/recall) | ✅ Klar (kod) | smoke-03/04/05; unit-tester; takeover manuellt verifierad |
| **M5** Skills + katalog + review-verdict | 🟡 Delvis | Skills byggda men doc-drift mot CLI; review-path unit-täckt, ej smoke |
| **M6** Runner headless-cykel + cost-cap + heartbeat | 🟡 Delvis | Kärncykel live (AE-255); cap wired; heartbeat prompt-tuning kvar |
| **M7** End-to-end takeover→work→done på riktig tavla | 🟡 Delvis | Fungerar; assignee/recall-realtid blockeras av Deck-cache |
| **M7.5** "Min agent"-widget | 🔴 Ej påbörjad | Ingen kod |
| **M8** Agent-minne-styrning / lifecycle | 🔴 Ej påbörjad | Framåtblickande |
| **M9–M11** (senare produktmilstolpar) | 🔴 Ej påbörjad | Framåtblickande |
| **M12** Governance / underhållsloop-automation | 🔴 Ej påbörjad | Framåtblickande |

_(Not: milstolpe-numreringen mappas grovt mot BYGGPLAN:s kapitel; M1–M4 är substantiellt levererade, M5–M7 levererade som kod med kända operativa/tuning-gap, M7.5+ ej påbörjade.)_

---

## 6. Rekommenderad fortsättning (mest värde först)

1. **Utred Deck-deltagarcachen (assignee/recall i realtid).** Det enda kvarvarande tekniska frågetecknet och det som blockerar smoke-05/06 samt sista assertionerna i smoke-07. Utred Deck-cache-TTL/invalidering (APCu/Redis) eller **förprovisionera board-medlemskap** så boten redan ligger i deltagarlistan innan takeover. Alternativt korta svep-intervallet. Ref: `DeckCardListener.php`, MORGONRAPPORT §4-5.

2. **Fixa liggar-heartbeat (prompt-tuning + robusthet).** Justera `queue-run.md` så modellen alltid skriver sista `completed AE-<id>`-bokföringsraden (steg 20). Överväg dessutom en **oberoende heartbeat** (t.ex. run-agent.sh skriver en liveness-rad till run_log oavsett modellsamarbete) så en pausad/kraschad slot går att skilja från en idle-men-frisk. Ref: `run-agent.sh` ledger-väg, MORGONRAPPORT §2†.

3. **Lägg upp teamet + kör om uid-bootstrap (M0-operativt).** Lägg upp `rebecca/sandra/mattias`, kör om `occ-provision.sh` + `deck-bootstrap.mjs --humans <uid,…>`. Låser upp Reb-rummet → smoke-02 helgrön och riktiga routing-ansvar. Ref: `provision/PENDING-USERS.md`, MORGONRAPPORT §5-6.

4. **Kör smoke-08 (hostile-card) och stäng säkerhetsluckan.** Högst värde av testerna — injektion + inga sidoeffekter + env-leak-scan mot verkliga secret-värden. Var ännu inte körd; kör den och bekräfta grönt observerat, inte antaget.

5. **Rätta engine-api.sh wire-buggar + skills-doc-drift.** Två latenta buggar tappar data tyst: receipt/origin-note skickar `message`/`note` där servern binder `text`; ledger skickar snake_case där servern läser camelCase. Sviten kringgår wrappern så de är otestade e2e. Rätta scripten (eller controllern) och synka SKILL.md mot `queue-run.md` (`--message`/positionellt/JSON/`search`/`create`). Ref: skills-granskning.

6. **Lägg smoke-täckning för människo-state-machine.** BLOCKED→UNBLOCKED→RESUMED, AGENT HUMAN HOLD, Agent Review approve/rework (3-cykel). Dessa är korrekthetskritiska men saknar regressionsskydd (endast unit idag).

7. **Bygg "Min agent"-widget (M7.5).** Daglig överblick per handläggare — första framåtblickande värdesteget när M0 är i mål. Ref: INTERAKTIONSDESIGN §5.

8. **Driftshärdning: restore + larm.** Skriv `restore.sh` + schemalagt restore-test/integritetskoll; lägg backup-fail-larm och cap-pause/billing-larm-garanti (idag log-only om Talk-token saknas). Överväg cron för deploy.sh cert-renewal. Ref: `stack/README.md`, `stack/backup/backup.sh`.

---

_Alla filsökvägar relativa till `openstack-itsl/` där inget annat anges. Engelska protokoll-tokens (AGENT DONE, HUMAN HOLD, RECEIPT_TOKENS m.fl.) behålls på engelska per kontrakt._
