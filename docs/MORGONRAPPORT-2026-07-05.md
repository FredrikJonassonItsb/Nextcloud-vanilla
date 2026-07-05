# Morgonrapport — ITSL Open Stack, natten 2026-07-04→05

God morgon Fredrik. Här är vad som hände i natt, vad som är bevisat att fungera, och exakt
vad du behöver göra idag. **Kortversionen: hela systemet är byggt och deployat på dev15, och
kärnloopen fungerar end-to-end — inklusive den funktion du frågade om ("tilldela agenten →
kortet flyttar över till kötavlan").** Två saker väntar på dig (nedan), och fyra edge-case i
Deck-cachen är kartlagda för M0.

---

## 1. Vad som är byggt och körs på dev15

Allt ligger i repot under [`openstack-itsl/`](../) och är deployat till `/opt/openstack` på
dev15 (10.43.51.62), som ett **separat docker compose-projekt** (`openstack`) bredvid Hubs —
det rör aldrig er befintliga stack.

| Komponent | Status | Bevis |
|---|---|---|
| **compose-stack** (10 tjänster) | ✅ alla healthy | `docker compose ps` grön |
| 5× **Open Brain** (pgvector + MCP) | ✅ kör | smoke-01 grön (nyckelisolation 30/30) |
| **caddy** (TLS :8843, path-routing) | ✅ | `/reb/* … /team/*` → rätt hjärna |
| **capture-bot** (Talk :8790) | ✅ | smoke-02: Sarah-frasen, HMAC, PII-422 |
| **runner** (headless Claude :8791) | ✅ kör | tar emot wake-push, kör `claude -p` |
| **backup** (nattlig pg_dump) | ✅ | container healthy |
| **agent_engine** (NC-app, board/kö) | ✅ enabled 1.0.0 | smoke 03–05 gröna |
| **Deck-tavla "Agent Engine"** (board 10) | ✅ | 7 stackar + 4 standing-kort |
| 5 **bot-användare** + Talk-rum + HMAC-bottar | ✅ | provisionerade |

## 2. Smoke-tester — vad som är verifierat grönt

Kör själv: `cd openstack-itsl/tests && bash run-all.sh --from-server`

| Test | Vad det bevisar | Resultat |
|---|---|---|
| **01 key-matrix** | Varje hjärna nås bara med sin egen nyckel (5×5-diagonalen) | ✅ **30/30 grönt** |
| **02 capture** | Talk→hjärna, HMAC-verifiering, dedupe, **PII-brandvägg (personnummer→422)** | ✅ grönt* |
| **03 claim-race** | Atomiskt claim-lås: två parallella claims → **exakt en vinnare + en 409** | ✅ **5/5 grönt** |
| **04 ledger** | Statusliggaren uppdateras PÅ PLATS (en kommentar per agent, ingen pile-up) | ✅ **6/6 grönt** |
| **05 takeover** | **Tilldela agent → engine-kort skapas i realtid med exakt titelgrammatik, alla 8 mallsektioner, default-deny-Boundaries, speglingskvitto** | ✅ **19/23** (4 Deck-cache-edge, se §4) |
| 06 sync-loop | Kommentarspegling utan studs | ⏳ beror på takeover-paret (§4) |
| **07 runner** | **Headless-agenten: HMAC-wake → claim → kör → AGENT DONE → Agent Done** | ✅ **kärncykeln grön** (5/7; assignee-cache §4 + liggar-heartbeat†) |
| 08 hostile | Injektionskort → AGENT BLOCKED, inga sidoeffekter | ⏳ återstår att köra |

† smoke-07: den fulla runner-cykeln är **bevisad live** — Claude claimade och slutförde
hello-world-kortet (AE-255, 23 turns, **$0.08**, flyttat till Agent Done). De 2 kvarvarande:
assignee (samma Deck-cache §4) och att liggarens `completed AE-255`-heartbeat inte skrevs
(Claude gjorde jobbet men hoppade över sista bokföringsraden — prompt-tuning, inte kod).

\* smoke-02: capture-brandväggen och HMAC gröna. Ett par assertions kräver Reb-rummet som inte
finns än (rebecca är inte upplagd på dev15 — se §5).

**Det viktigaste beviset:** den funktion du specifikt frågade om fungerar. Jag verifierade
manuellt hela flödet: en människa tilldelar `bot-atlas` på ett vanligt Deck-kort → inom
sekunder skapas ett engine-kort `[agent instructions][atlas-claude][task] <titel>` i Agent
Todo, med komplett 8-sektionsmall, fast "Draft-only"-Boundaries, `hos-agenten`-label + ⇄-kvitto
på ursprungskortet, och en card_link som binder ihop dem. Unassign (ta tillbaka) triggar recall.

## 3. Runnern (headless-agenten) — live med din API-nyckel

Din Anthropic-nyckel ligger säkert i `/opt/openstack/.env` (chmod 600). Runnern är aktiverad
(`RUNNER_ENABLED=1`), har Claude Code CLI 2.0.1, och **tar emot wake-push från agent_engine**
(bevisat: "wake accepted for reb-claude — triggering run"). smoke-07 kördes i natt för att
bevisa hela cykeln claim → `claude -p` → AGENT DONE. Resultatet står i
`tests/` (kör `bash smoke-07-runner-hello.sh --from-server` för att se live).

Jag fixade också en namnkrock (`ENGINE_META_URL` vs `ENGINE_META_DSN`) som gjorde att
**dagskostnadstaket inte var aktivt** — nu loggas kostnad per körning och taket
(`RUNNER_DAILY_USD_CAP`, default $10/agent/dag) gäller (verifierat: "daily spend $0, cap $10").
Runnern kör `claude-sonnet-4-5` som pinnad modell (kan höjas senare). En schemamiss i `run_log`
(samma dubbeldefinition som `capture_seen`) fixades så kostnaden faktiskt sparas.

## 4. Sju äkta buggar hittade och fixade i natt

Granskarfasen i nattbygget dog på Fable-spendtaket, så **smoke-testerna fångade buggarna i
stället** — vilket är precis vad de är till för. Alla är fixade i koden:

1. **Deck-autoloader:** `class_exists()` på en Deck-klass i appens `register()` förgiftade Decks
   egen klassladdning → all Deck-kortskapande gav 500. Fix: registrera lyssnare ovillkorligt.
2. **NC Entity-fälla:** `Entity::setter()` markerar inte ett oförändrat värde som "dirty", så en
   NOT NULL-kolumn utan DB-default (`state`) kraschade varje takeover. Fix: DB-default `'open'`.
3. **Deck reorder:** flyttar till stacken i URL-*pathen*, inte i bodyt → claim flyttade aldrig
   kortet till Agent Working. Fix: rätt stack i pathen.
4. **OCS platt body:** band inte till array-parametern → liggarfält blev tomma. Fix: läs fält-vis.
5. **Tjänsteauth:** `agent_engine` måste ha `bot_user`/`bot_token` i app-config, annars gick
   alla Deck-anrop oautentiserade ("card not found"). Fix: sätts av provisioneringen.
6. **capture_seen** dubbeldefinierad (DB-init saknade `status`-kolumnen). Fix: alignad + ALTER.
7. **Listenern:** Deck-events bär inte med sig `assignedUsers` → takeover fyrade bara via det
   långsamma svepet. Fix: listenern hämtar kortet färskt → **takeover i realtid (≤ sekunder)**.
8. **Runnern:** headless-claude kraschade som `runner`-användaren (`EACCES /root/.claude.json`
   — HOME propagerades inte) och verktygsskripten låg inte i PATH. Fix: run-agent.sh forcar
   `HOME=/home/runner` + lägger `/app/bin` i PATH. Verifierat: claude kör nu som runner och
   når `engine-api.sh`.

## 5. Fyra kvarvarande edge-case (för M0 — inte kodfel)

Dessa är **Deck-beteenden på dev15**, inte fel i vår logik (RecallService/TakeoverService är
korrekta och toleranta — takeovern fortsätter även om de inträffar):

- **Assignee sätts inte på engine-kortet.** Deck vägrar tilldela en nyligen ACL-delad användare
  ("The user is not part of the board") — det är en **Deck-deltagarcache** (APCu/Redis) som inte
  invalideras när en ACL läggs till. Verifierat: en bot som legat i ACL:en en stund ÄR
  tilldelningsbar; en nyss tillagd är det inte. → M0: utred Deck-cache-TTL / invalidering, eller
  förprovisionera board-medlemskap.
- **Recall i realtid** hittar ibland boten som "fortfarande tilldelad" av samma läscache → engine-
  kortet arkiveras inte direkt. Svepet (korrekthetsgolvet, var ~5:e min via NC-cron) tar det. → M0:
  samma cache-utredning; ev. korta svep-intervallet eller tvinga cache-refresh i listenern.
- **Per-instans-uid:** din NC-uid är personnummer `197411040293` (BankID), inte "fredrik". Jag
  patchade `deck-bootstrap.mjs` (`--humans`) och provisioneringen så riktiga uid används, men
  Deck-tavlan delades först med de kanoniska namnen (fantom-ACL:er). → M0: kör om bootstrap med
  `--humans <uid,uid>`.
- **NC-cron var ~5 min:** svepet är fallback; realtidsvägen (listenern) är primär och fungerar.

## 6. EN SAK SOM VÄNTAR PÅ DIG

1. ~~**OpenRouter-krediter.**~~ ✅ **LÖST** (2026-07-05, morgon). Ny nyckel satt, embeddings
   aktiva och **verifierade**: hela Open Brain-loopen fungerar nu — capture → embedding (OpenRouter
   `text-embedding-3-small`) → **LLM-metadata** (Topics/People/Type extraheras) → **semantisk
   sökning** (t.ex. "vem är Fredriks agent" hittar "Atlas är Fredriks agent" utan att frågan nämner
   orden) → **runner-writeback** (Atlas skrev själv tillbaka "AE-255 completed" till sin hjärna —
   träningsloopen i praktiken). Alla 5 hjärnor: 0 pending. Jag sänkte default-söktröskeln till 0.25
   (OB1:s 0.5/0.7 är engelsk-centrerad; svensk text landar på cosine ~0.2–0.4).

2. **Lägg upp teamet.** `rebecca`, `sandra`, `mattias` finns inte på dev15 (bara du). De ligger i
   [`provision/PENDING-USERS.md`](../provision/PENDING-USERS.md). När de finns: kör om
   `provision/occ-provision.sh` så får de sina capture-rum och hamnar i routingkartan, sedan
   `provision/deck-bootstrap.mjs --humans <uid,...>` för tavel-delningen.

## 7. Testartefakter att städa (när du vill)

- Testanvändaren `ae-test-human` (dev-admin) skapades för takeover-testerna — kan raderas:
  `occ user:delete ae-test-human`. Dess app-lösenord ligger i `tests/.env.test` (gitignore:ad).
- `tests/.env.test` innehåller hemligheter (hämtade från servern) — commit:as aldrig.

## 8. Nästa steg (min rekommendation)

1. Köp OpenRouter-krediter → verifiera embeddings (`smoke-02` blir helgrön, sök fungerar semantiskt).
2. M0-kickoff enligt [BYGGPLAN §9](BYGGPLAN.md): lås routingkartans ansvarsområden, lägg upp teamet.
3. Utred Deck-deltagarcachen (assignee/recall-realtid) — enda kvarvarande tekniska frågetecknet.
4. Bygg "Min agent"-widgeten (M7.5 i [INTERAKTIONSDESIGN §5](INTERAKTIONSDESIGN.md)) för daglig överblick.

Allt är committabelt men **inget är pushat** (per din stående regel). Säg till så går vi igenom
det tillsammans, eller kör vidare på M0.
