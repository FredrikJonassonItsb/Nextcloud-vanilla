<!--
  SPDX-FileCopyrightText: ITSL <info@itsl.se>
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

# hubs_arende — Hubs ärende-motor

> Bygg-/deploy-/verifierings-guide. Hur frontend kopplas. ExApp-paketeringsvägen.

- **App-id:** `hubs_arende`
- **Namespace-rot:** `OCA\HubsArende`
- **Vendor:** ITSL · **Licens:** AGPL-3.0-or-later (SPDX-header i varje fil)
- **NC-kompat:** 30–32 (dev15 kör NC 31.0.8) · **PHP:** 8.1+
- **Status:** in-process NC-app NU; **designad ExApp-rent** → paketeras som AppAPI-ExApp senare (se §5).

---

## 1. Vad appen är

`hubs_arende` är en **standalone ÄRENDE-MOTOR** i egen kodbas med **egen DB**. Den är den
saknade join-nyckeln för socialtjänst-stacken (registret som tidigare bara fanns som
in-memory `REGISTER` i `hubs_start/src/services/demo/treserva.js`).

**Tre saker som måste sitta i ryggmärgen:**

1. **sdkmc lämnas ORÖRD.** sdkmc äger *meddelanden* — skicka/ta emot, taggar, korgar,
   retention. `hubs_arende` rör inte sdkmc:s kod, schema eller DB. Den **konsumerar**
   sdkmc via OCS/events (anropar t.ex. sdkmc tag-API för `case:`-taggar, läser inflöde).
   Att bygga ärende-motorn i en egen app är den exakta poängen: sdkmc-snittet är ett
   *kontrakt över appgränsen*, inte en intern beroende-väv.

2. **hubs_arende lagrar bara KOORDINATIONS-STATE** — register, pekare, routing.
   **ALDRIG verksamhetsdata.** Verksamhetsdatan (utredningstext, beslut, journal) bor i
   facksystemet (Treserva). Motorn vet *att* ett ärende finns, *var* dess objekt ligger
   (Deck-kort, Talk-rum, groupfolder, kalender, case-tag) och *vart* det ska committas —
   men aldrig *innehållet*.

3. **Invariant: `commit_destination` NOT NULL.** Varje ärende vet från födseln vart det
   till slut hör hemma (`facksystem | diarium | e_arkiv | extern_myndighet |
   triage_forward | karantan`). Ett ärende utan commit-destination får aldrig existera.

### Datamodell (egna tabeller, prefix `oc_` sätts av NC)

| Tabell | Roll |
|---|---|
| `hubs_arende_case` | Registret. `hubs_case_id` (UUID, UNIQUE) är join-nyckeln. Status/steg/provenance/retention + `commit_destination` NOT NULL + FK→`hubs_arende_typ`. |
| `hubs_arende_typ`  | Ärendetyps-katalogen (8 typer). Policy per typ: `plikt_grind`, `frist_policy`, `commit_destination`, `dhp_handlingstyp`, `frends_modul`, hooks. |
| `hubs_arende_flagga` | Pliktmarkörer/flaggor på ett ärende (`hubs_case_id`, `flagga`, `satt_av`, `satt_at`). |
| `hubs_arende_pekare` | Pekare till externa objekt (`objekt_typ`: `deck_card | talk_room | groupfolder | calendar | case_tag`, `objekt_id`, `riktning`). |

### Klasskarta (kontrakt — refereras över filgränser)

```
OCA\HubsArende\
  AppInfo\Application                         registrerar tjänster/DI
  Db\Arende  (Entity)        + ArendeMapper   (QBMapper, tabell hubs_arende_case)
  Db\ArendeTyp (Entity)      + ArendeTypMapper (tabell hubs_arende_typ)
  Service\SakerhetsskyddGrind                 evaluate(array $rad): array — FAIL-CLOSED, körs FÖRST
  Service\ArendeTypRegistry                   get(string $arendeTypId): ?ArendeTyp
  Service\ArendeService                       createCase() SAGA R1–R10 + kompensering; tilldela(); commit()
  Service\FacksystemCommitService             commit(): VERIFIERAT kvitto; väljer port
  Integration\Port\FacksystemCommitPort       (+ SigneringPort, EdiariumPort) — interfaces
  Integration\Stub\*                          verifierad async callback-simulering, deterministisk data
  Controller\ArendeController                 extends OCSController
```

Mönstren är grundade i den **verkliga sdkmc-koden**:
`ItslTag`/`ItslTagMapper` (QBMapper + `getOrCreate`-mönster),
`Version020008Date20251229000000` (migrationsmönster: `SimpleMigrationStep` +
`hasTable`-vakt + unik index), `AppInfo/Application` (DI-registrering).
Saga-/register-shapen kommer från
`hubs_start/docs/HUBS-INTERNALS-ARENDEMOTOR.md` §1.2.3 och ärendetyps-fälten från
`hubs_start/docs/ARENDETYPER-FLODESANALYS.md`.

---

## 2. Lokal- / dev15-deploy

> Provisioneringsskript och seed-SQL ligger i
> [`./provision/`](./provision/). Använd dem i stället för att klippa ihop occ-rader för hand.

`hubs_arende` är en vanlig in-process NC-app: läggs i `custom_apps`, aktiveras, migrationen
kör automatiskt vid `app:enable`.

### 2a. Lokal (docker-compose i denna repo)

Containern heter `nextcloud-app` och `custom_apps` är monterad som named volume
(se `docker-compose.yml`). Lägg appen där och aktivera:

```bash
# 1. Lägg appen i custom_apps (kopiera eller bind-mounta hubs_arende/ → /var/www/html/custom_apps/hubs_arende)
docker cp ./hubs_arende nextcloud-app:/var/www/html/custom_apps/hubs_arende
docker exec -u www-data nextcloud-app php occ app:enable hubs_arende   # → migration körs här

# 2. Verifiera (se §2c)
docker exec -u www-data nextcloud-app php occ app:list | grep hubs_arende
docker exec -u www-data nextcloud-app php occ migrations:status hubs_arende
```

### 2b. dev15

På dev15 heter PHP-containern `hubs-php` och allt occ körs som `www-data`. Samma form:

```bash
# 1. Synka appen till custom_apps på dev15 (rsync/scp till volymen, eller docker cp)
sudo docker exec -u www-data hubs-php php /var/www/html/occ app:enable hubs_arende   # → migration körs

# 2. Seed ärendetyps-katalogen (8 typer) — idempotent, kan köras om
sudo docker exec -u www-data hubs-php php /var/www/html/occ hubs_arende:seed-typer
#    (eller kör provision/seed-arendetyper.sql om CLI-kommandot inte finns ännu)
```

> **`occ app:enable` är migrationsgrinden.** NC kör automatiskt alla
> `lib/Migration/Version*`-steg vid aktivering. Det finns inget separat
> "kör migration"-kommando — aktivering räcker. Vill du tvinga om en migration
> manuellt: `occ migrations:migrate hubs_arende`.

### 2c. Verifiera deploy

```bash
# Appen aktiv?
occ app:list | grep hubs_arende                       # → ska listas under "Enabled"

# Migrationen körd? (alla Version*-steg ska stå som "migrated")
occ migrations:status hubs_arende

# OCS-snittet svarar? (admin-rutt, kräver OCS-APIRequest-header)
curl -u admin:adminpass -H 'OCS-APIRequest: true' -H 'Accept: application/json' \
  https://dev15.itsl.se/ocs/v2.php/apps/hubs_arende/api/v1/arende-summary
# → {"ocs":{"meta":{"status":"ok",...},"data":{...}}}
```

(Lokalt: byt host mot `http://localhost:8080` och credentials mot din admin-användare.)

---

## 3. Verifiering — hel ärende-livscykel mot stubbarna

Hela livscykeln kan köras **utan en enda extern integration**. Varje port
(`FacksystemCommitPort`, `SigneringPort`, `EdiariumPort`) har en stub vald via
`INTEGRATION_MODE` (default `'stub'`, läses per port via `IAppConfig`). Stubbarna är
**deterministiska** (syntetisk data) och simulerar **verifierad async callback** — så
provenance flippar och kvittot blir `verifierad:true` precis som mot ett riktigt facksystem,
men allt körs in-process.

### 3a. php -l på allt (syntaxgrind före allt annat)

```bash
# Kör i container (samma PHP-version som NC). Stoppa vid första felet.
docker exec -u www-data nextcloud-app sh -c \
  'find /var/www/html/custom_apps/hubs_arende -name "*.php" -print0 | xargs -0 -n1 php -l'
# Lokalt utan container: provision/lint.sh gör samma sak mot ./lib.
```

Alla skelettfiler ska vara `No syntax errors detected`. Metoder får ha tydliga
TODO-markörer för delar som kräver sdkmc-/Deck-/Spreed-anrop, men strukturen måste vara
komplett och syntaktiskt giltig.

### 3b. Kör hela livscykeln (stub-läge, inga externa anrop)

Sekvensen nedan motsvarar saga R1–R10 + commit. Kör via OCS (curl) eller via
`provision/smoke-livscykel.sh` som kedjar samma anrop och asserterar shaperna.

```bash
H='-u admin:adminpass -H OCS-APIRequest:true -H Accept:application/json'
BASE=http://localhost:8080/ocs/v2.php/apps/hubs_arende/api/v1

# 1) createCase — säkerhetsskydd-grinden körs FÖRST (fail-closed).
#    En "ren" rad släpps igenom → ärende mintas (hubs_case_id), register-rad,
#    case-tag, pekare. commit_destination enforce:as NOT NULL.
curl $H -X POST "$BASE/arende" -H 'Content-Type: application/json' \
  -d '{"rad":{"arende_typ":"orosanmalan_barn","objekt_ref":"obj-pseudo-001","enhet":"mottagning","conversationId":"conv-abc-123"}}'
# → {ok:true, hubs_case_id:"<uuid>", status:"otilldelat", steg:"inflode",
#    provenance_state:"ej_registrerad", commit_destination:"facksystem"}

#    Säkerhetsskydd-grinden FAIL-CLOSED: en rad med skyddsindikator avvisas
#    INNAN något skapas (ingen rad/tagg/rum). Verifiera att inget läcker:
curl $H -X POST "$BASE/arende" -H 'Content-Type: application/json' \
  -d '{"rad":{"arende_typ":"orosanmalan_barn","sakerhetsskydd_indikator":true}}'
# → {ok:false, avvisad:true, reason:"<skäl>"}  (HTTP 200 OCS, men avvisningskvitto)

# 2) tilldela — sätt ägare; status otilldelat → tilldelat
REF=<hubs_case_id-från-steg-1>
curl $H -X POST "$BASE/arende/$REF/tilldela" -H 'Content-Type: application/json' \
  -d '{"uid":"handlaggare1"}'

# 3) Läs tillbaka ärendet (register-state + pekare)
curl $H "$BASE/arende/$REF"

# 4) commit — FacksystemCommitService väljer stub-porten, simulerar verifierad
#    callback, returnerar VERIFIERAT kvitto. Provenance flippar registrerad.
curl $H -X POST "$BASE/treserva/commit" -H 'Content-Type: application/json' \
  -d "{\"hubs_case_id\":\"$REF\",\"payload\":{\"typ\":\"beslut_inled_utredning\"}}"
# → {ok:true, dnr:"<syntetiskt dnr>", committedAt:"<iso>",
#    gallrasDatum:"<iso>", verifierad:true}

# 5) Verifierat kvitto — läs summary, provenance ska nu vara "registrerad"
curl $H "$BASE/arende-summary"
```

**Grindkedjan som verifieras:** `createCase` → **säkerhetsskydd-grind (FÖRST, fail-closed)**
→ register (insert + pekare) → `commit` → **verifierat kvitto**. Avvisas grinden skapas
varken rad, tagg eller rum — det är hela poängen med fail-closed.

---

## 4. Frontend-wiring (hubs_start → hubs_arende OCS)

`hubs_start` är en Vue-demon med 32 LIVE-komponenter. Idag **kortsluter** den varje
nätverksanrop till in-memory fixtures: `src/services/api.js` har en
`const DEMO = isDemo()` och varje funktion börjar med `if (DEMO) return ...`. Wiringen är
att **byta DEMO-grenen mot riktiga anrop mot `hubs_arende` OCS** — och **behålla
sdkmc-anropen för meddelanden orörda**.

### 4a. Princip

- **Ärende-funktioner** → peka mot `hubs_arende` OCS
  (`/ocs/v2.php/apps/hubs_arende/api/v1/...`).
- **Meddelande-/triage-/mötes-funktioner** → **lämna på sdkmc** (`SDKMC_OCS`). Dessa rör
  inkorg, taggar, korgar, recipients, secure-meeting — sdkmc äger dem.

Lägg till en bas-helper bredvid de befintliga i `api.js`:

```js
// src/services/api.js
const HUBS_ARENDE_OCS = (path) => generateOcsUrl('apps/hubs_arende/api/v1' + path)
```

Byt sedan **bara ärende-grenarna**. Exempel (`skapaArende`):

```js
export async function skapaArende(rad) {
  if (DEMO) return ssDemo.skapaArende(rad)                         // ← behåll demo-fallback
  const res = await axios.post(HUBS_ARENDE_OCS('/arende'), { rad }) // ← var: SDKMC_OCS('/arende')
  return ocsData(res)
}
```

### 4b. Mappning — api.js-funktion → hubs_arende-route

| api.js-funktion | Var (idag, sdkmc) | Ny route (hubs_arende OCS) | hubs_arende-metod |
|---|---|---|---|
| `fetchArendeSummary()` | `GET SDKMC_OCS('/arende-summary')` | `GET /arende-summary` | `ArendeController::summary()` |
| `fetchArende(dnr)` | `GET SDKMC_OCS('/arende/{dnr}')` | `GET /arende/{ref}` | `ArendeController::show()` |
| `skapaArende(rad)` | `POST SDKMC_OCS('/arende')` | `POST /arende` | `ArendeController::create()` → `ArendeService::createCase()` |
| `commitToTreserva(payload)` | `POST SDKMC_OCS('/treserva/commit')` | `POST /treserva/commit` | `ArendeController::commit()` → `FacksystemCommitService::commit()` |
| *(ny)* tilldela | — | `POST /arende/{ref}/tilldela` | `ArendeController::tilldela()` → `ArendeService::tilldela()` |

> **Routes-prefix:** allt under `appinfo/routes.php` `'ocs' =>` blir
> `/ocs/v2.php/apps/hubs_arende/api/v1/...`.

### 4c. Lämna ORÖRT på sdkmc (meddelanden — bevisar att sdkmc är orörd)

`fetchSummary`, `fetchReceipts`, `searchRecipients`, `classifyRecipient`,
`fetchTodaysMeetings`, `createSecureMeeting`, `fetchLobbyStatus`, `fetchGuestIdentity`,
`takeThread`, `setMessageTag`, `getSettings` — **byt inte dessa.** De fortsätter mot
`SDKMC` / `SDKMC_OCS`. Ärende-motorn konsumerar sdkmc-meddelanden server-side, inte genom
att flytta dessa frontend-anrop.

Funktioner som `fetchInflodeSummary`, `fetchFordelningSummary`, `inflodeAction` rör både
inflöde (sdkmc) och ärende-koppling (hubs_arende) — flytta dem stegvis och bara den del
som faktiskt skriver register-state; inflödet i sig läses fortsatt ur sdkmc.

---

## 5. ExApp-paketeringsvägen

`hubs_arende` är **idag en in-process NC-app** men **designad ExApp-rent** så att lyftet
till en AppAPI-ExApp inte ändrar kontraktet. Detta realiserar besluten i
`HUBS-IMPLEMENTATIONSPLAN.md` (BESLUT-01/04/05/15): bygg i NC-app-DB nu (fas b),
lyft till egen ExApp-container + egen DB senare (fas c).

```
NU (fas b)                          SENARE (fas c)
─────────                           ─────────────
hubs_start (Vue) ─┐                 hubs_start (Vue) ─┐
                  │ in-process OCS                    │  OCS mot tunn AGPL-bro
   hubs_arende  ──┘  (samma PHP-process som NC)          │
   egen NC-app-DB                   tunn bro (in-process) ─ HTTP/AppAPI ─→ hubs_arende ExApp
                                                                            egen container
                                                                            egen Postgres
```

### Vad ÄNDRAS vid lyftet

- **Transport:** in-process PHP-anrop → **HTTP-bro** över AppAPI (arm's length,
  app-nivå-JSON). Frontend pratar då med en tunn AGPL-bro-app som vidarebefordrar till
  ExAppen över HTTP.
- **Runtime:** egen kodprocess i **egen container** via AppAPI deploy-daemon
  (Docker-socket; socket-proxy obligatorisk i stage/prod).
- **DB:** flyttas från NC-app-DB till **egen Postgres** — egen container eller ny DB+role
  i `hubs-postgres` (egen role, inte Hubs-rollen). ExApp-DB:n överlever `itsl deploy`
  (registrera med `--keep-data`).
- **Registrering:** `occ app_api:app:register hubs_arende hubs_docker --info-xml <...>`
  i stället för `occ app:enable`.

### Vad är OFÖRÄNDRAT (designat ExApp-rent)

- **DB-schemat** — `hubs_arende_case/typ/flagga/pekare` flyttar container, inte form.
- **OCS-kontraktet** — samma routes, samma JSON-shaper. Frontend ser ingen skillnad
  (bron speglar prefix `/api/v1/...`).
- **Klass-/metod-signaturer** — `ArendeService::createCase()`, `FacksystemCommitService::commit()`,
  `SakerhetsskyddGrind::evaluate()` ändras inte. Inga sdkmc-/Deck-/Spreed-anrop i
  konstruktorer; alla externa beroenden går via **portar** (interfaces) — exakt det som gör
  flytten över HTTP-snittet möjlig utan kodändring.
- **Port-/stub-kontraktet** — `*StubAdapter` ↔ `*LiveAdapter` väljs av `INTEGRATION_MODE`;
  samma kontraktstester körs mot båda i båda faserna.

**Designregeln som håller snittet rent:** inga in-process-genvägar till sdkmc/Deck/Spreed
i affärslogiken — bara via portar och OCS. Bryts den regeln blir ExApp-lyftet en
omskrivning i stället för en flytt.

---

## 6. Nästa-steg-checklista (dev15)

- [ ] Scaffolda skelettet: `appinfo/info.xml` (app-id `hubs_arende`, NC 30–32, AGPL),
      `appinfo/routes.php` (OCS-routes §4b), `lib/AppInfo/Application.php` (DI).
- [ ] Entities + Mappers: `Arende`/`ArendeMapper`, `ArendeTyp`/`ArendeTypMapper`
      (QBMapper-mönstret från `ItslTagMapper`).
- [ ] Migration `Version0001Date...CreateArendeTables.php` (mönstret från
      `Version020008...`): 4 tabeller, `hubs_case_id` UNIQUE, `commit_destination` NOT NULL.
- [ ] `SakerhetsskyddGrind::evaluate()` — fail-closed, körs FÖRST i `createCase`.
- [ ] `ArendeTypRegistry` + seed av de 8 ärendetyperna (`provision/seed-arendetyper.sql`).
- [ ] `ArendeService::createCase()` (SAGA R1–R10 + kompensering, idempotent på
      `conversationId`), `tilldela()`, `commit()`.
- [ ] `FacksystemCommitService` + portar + stubbar (`INTEGRATION_MODE` per port via `IAppConfig`).
- [ ] `php -l` på alla filer (§3a) — grön.
- [ ] Lägg i `custom_apps` → `occ app:enable hubs_arende` → migration kör (§2b).
- [ ] Verifiera: `occ app:list`, `occ migrations:status hubs_arende`, OCS
      `GET /arende-summary` (§2c).
- [ ] Kör hel livscykel mot stubbarna: createCase → säkerhetsskydd-grind → register →
      tilldela → commit → verifierat kvitto (§3b).
- [ ] Frontend: lägg `HUBS_ARENDE_OCS`-helper, byt ärende-grenarna i `api.js`, lämna
      sdkmc-meddelande-anropen orörda (§4).
- [ ] (Senare) ExApp-lyft: `app_api` registrerad, egen Postgres provisionerad, bro-app +
      `--keep-data` (§5).
