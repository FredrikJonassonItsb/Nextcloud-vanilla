<!--
SPDX-FileCopyrightText: ITSL <info@itsl.se>
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# DEMO-STUBS — det auktoritativa SEAM-registret

Detta är handover-dokumentet för att bygga den **skarpa** lösningen ur prototypen.
Det listar **var alla stubbar och all demodata ligger** och **exakt vad som ersätter
dem** i produktion. Det är fakta-troget mot koden i `hubs_start/src/services` och
`hubs_start/lib/Controller` (inga påhittade filnamn/routes).

> **Princip:** *varje stub har en namngiven SEAM och en prod-ersättare.* I koden är
> stubbarna märkta med en `🔌 SEAM[<id>]`-markör. Sök på `SEAM[` för att hitta dem.
> Varje seam-id i tabellen nedan mappar 1:1 mot en sådan markör.

---

## 1. Översikt — hur demoläget hänger ihop

Demoläget existerar för att hela UI:t ska kunna visas på en **vanilla Nextcloud**
där syskon-apparna (sdkmc, mail, spreed, calendar, securemail) och deras OCS-routes
**inte är installerade**. Kedjan:

```
PageController::index()
  └─ isDemoMode()  → app-config hubs_start/demo_mode ('1' forced ON / '0' forced OFF
                     / tomt = AUTO: ON när data-ägaren sdkmc saknas)
       └─ provideInitialState('boot', { demoMode: true, apps:…, profile:…, prefs:… })
            └─ [frontend] isDemo()  (demoData.js) läser boot.demoMode === true
                 └─ api.js: const DEMO = isDemo()
                      └─ varje exportfunktion: `if (DEMO) return <fixtur/stub>`
                                                else  `await axios(<riktig OCS-route>)`
```

På en **riktig Hubs-install** är `demoMode` `false`, `DEMO`-grenarna körs aldrig och
nätverksvägarna i `api.js` går orörda mot de riktiga sdkmc-OCS-routerna.

Den centrala designen som gör handovern enkel: **demo-grenen och prod-grenen är redan
fysiskt separerade** i `api.js`. Att gå skarpt = ta bort (eller låta `DEMO` bli `false`
för) `if (DEMO) …`-grenen; prod-grenen under den är redan skriven mot den avsedda
OCS-routen. Den stateful affärslogiken som demon behöver (skapa→committa→gallra ska
hänga ihop visuellt) ligger isolerad i `src/services/demo/`.

**Filöversikt (stub-/demo-lagret):**

| Fil | Roll |
|---|---|
| `src/services/demoData.js` | `isDemo()` + övriga personas fixtures (`demo`-objektet) |
| `src/services/demo/treserva.js` | Stateful Treserva/Frends-konnektor-stub (register + kvittenser) |
| `src/services/demo/favoriter.js` | Kontakter-favorit-resolver-stub (3 klasser + tombstone) |
| `src/services/demo/socialsekreterare.js` | Socialsekreterar-demodata + routing till stubbarna |
| `src/services/api.js` | DEMO-short-circuit (`if (DEMO)`) per route; demo-gren vs OCS-gren |
| `src/store/index.js` | Actions som muterar demo-state genom stubbarna |
| `lib/Controller/PageController.php` | `isDemoMode()` + `boot.demoMode`-injektion |

---

## 2. SEAM-tabell

| Seam-id | Vad den stubbar | Demo-beteende (fil) | Prod-ersättning (var/hur) | Status |
|---|---|---|---|---|
| **treserva** | Hela Treserva/Frends-konnektorn + det kanoniska ärenderegistret `hubs_arenden` (Tables) + sdkmc:s atomära "skapa ärende"-orkestrering | Stateful in-memory `REGISTER`/`RECEIPTS`-Map (`demo/treserva.js`). Ingen nätverkstrafik. | `hubs_arenden`-registret i Nextcloud Tables (skrivs **enbart** av sdkmc) + Frends-iPaaS-flöde mot facksystemet Treserva. Routes i `api.js` finns redan. | **blocker (GAP-019)** |
| **treserva.commit** | Committa en handling till Treserva via Frends med **verifierad** återkallning | `commitHandling()` registrerar handling, returnerar verifierat kvitto, startar retention-klockan **först** på callbacken (`demo/treserva.js`). Anropas via `ssDemo.commitToTreserva` i `api.js` `commitToTreserva()`. | `POST {SDKMC_OCS}/treserva/commit` → Frends → Treserva → verifierad callback; sdkmc flippar provenans + sätter retention-tagg **först då**. | **blocker (GAP-019/007)** |
| **treserva.skapa** | Atomär "skapa ärende ur ett inflöde" (mintar `hubsCaseId`+dnr, skapar rum/Deck/chattrum, startar 14-dgr-klockan) | `skapaArende(rad)` returnerar ett **färdigt** ärende-objekt + skriver registerpost (`demo/treserva.js`). Anropas via `ssDemo.skapaArende` i `api.js` `skapaArende()` och från store `inflodeAction('skapa', …)`. | `POST {SDKMC_OCS}/arende` med `{ rad }` — ett enda sdkmc-anrop: register + ärenderum + ACL + Deck + chattrum + klocka i en transaktion (GAP-010). | **blocker (GAP-019)** |
| **treserva.koppla** | Koppla ett inflöde till ett befintligt ärende | `kopplaInflode(rad, hubsCaseId)` noterar handling i registerposten (`demo/treserva.js`). Exponeras som `ssDemo.kopplaInflode`. | sdkmc sätter systemtaggen `case:{hubsCaseId}` på objektet + speglar filen till ärenderummet (del av `inflodeAction('koppla', …)` → `POST {SDKMC_OCS}/inflode/koppla`). | **blocker (GAP-019)** |
| **treserva.seed** | Förladda registret + historiska kvittenser ur demo-ärendena vid uppstart | `seedRegister(arenden)` sätter `hubsCaseId` på varje ärende + seedar upp till 3 historiska kvittenser (`demo/treserva.js`). Anropas en gång i `demo/socialsekreterare.js`. | **Ingen ersättning** — i prod finns registret redan i Tables (`hubs_arenden`); ingen seed behövs. Hela seam:en är ren demo-konstruktion. | **endast demo** |
| **treserva.tombstone** | Register↔objekt-divergens: en pekare i registret (Deck-kort, rum) som inte längre finns | *Ingen explicit tombstone-väg byggd i `demo/treserva.js`* — registret är intern-konsistent i demon (in-memory). | Reconciliation-jobb register↔objekt + integritets-larm (Deck-pekare som pekar på borttaget kort etc.); deny-by-default i aggregeringen. | **blocker (GAP-056)** |
| **favoriter** | Det tunna sdkmc-resolverlagret ovanpå Kontakter-appen (favorit = **pekare**, inte post; föränderliga fält resolvas färskt ur DIGG) | `fetchFavoriter(opts)` returnerar 3 redan-resolvade DTO:er (klass a/b/c) + 1 tombstone (`demo/favoriter.js`). Exponeras som `ssDemo.fetchFavoriter` i `api.js` `fetchFavoriter()`. | `GET {SDKMC_OCS}/favoriter` som kör `IManager::search` över personlig ∪ funktions-delad favorit-adressbok (ett anrop) och batch-resolvar pekarna mot DIGG-cachen → `{färska fält, resolvedAt, stale?, removed?}`. | **major (GAP-061)** |
| **favoriter.tombstone** | En favorit vars pekare inte längre finns i DIGG → icke-väljbar (överstruken) | Hårdkodad post `fav-d-gammal-mott` med `stale:true, removed:true` (`demo/favoriter.js`). | Resolverlagret sätter `removed/stale` från färsk DIGG-resolve; **hård fail-closed** när DIGG ej nås (väljbar blockeras) + tombstone-gallring mot skuggregister. | **major (GAP-061/063)** |
| **api.js DEMO-short-circuit** | Den generella `if (DEMO) return <fixtur>`-grenen i *varje* exportfunktion | `const DEMO = isDemo()` (`api.js`). 27 exportfunktioner returnerar fixtur/stub i demoläge i stället för axios. | Inget byte krävs i koden: när `boot.demoMode=false` blir `DEMO` `false` och prod-grenen (`await axios(<OCS-route>)`) körs. Routes är redan skrivna. | **infrastruktur (alltid demo-grind)** |
| **demodata-fixtures** | Övriga personas triage-/kvittens-/möten-/mottagar-data (icke-socialsekreterare) | `demo`-objektet i `demoData.js` (items, summary, receipts, meetings, lobby, appointmentConfigs, recipients). | De riktiga sdkmc/mail/spreed/calendar-OCS-routerna (`SDKMC_OCS('/summary')`, `/receipts`, `/meetings/today`, `/recipients/search`, …) — alla redan kodade i prod-grenarna. | **endast demo** |
| **PageController demo-boot** | Beslutet *att* köra demoläge + injektionen av flaggan | `isDemoMode()` + `provideInitialState('boot', {demoMode:true, apps: alla true, …})` (`PageController.php`). | I prod: `demo_mode='0'` (eller AUTO med sdkmc installerad) → `boot.demoMode=false`, `apps` = `AppDetectionService::detect()`, profil ur `RoleService`. | **infrastruktur** |
| **deepLinks inerta i demo** | Helsides-navigationer ut i målappar (tråd-/komponist-/Deck-länkar) | `deepLinks.js` genererar URL-strängar; i demoläge är `state.demoMode=true` och anroparen undviker `window.location`-tilldelning (länkarna är **inerta**, se DEMO.md). | I prod pekar länkarna på riktiga app-routes (`/apps/sdkmc/mailbox-link/…`, `/apps/mail/new`, …) och anroparen navigerar; mail-komponist-länken kräver mail-routerhook. | **endast demo** |

---

## 3. Per central stub — så här byter du till skarpt

### `treserva.js` — Treserva/Frends-konnektorn + ärenderegistret

**Nuvarande beteende (demo).** En medvetet stateful in-memory-stub som håller ett litet
"Treserva" i minnet så att demons röda tråd hänger ihop. Två datastrukturer:

- `REGISTER` (`Map<hubsCaseId, registerpost>`) — single source of truth för demons röda
  tråd; speglar Tables-registret `hubs_arenden`. Posterna håller `dnr`, `barnRef`,
  `steg`, två-vägs-pekare till objekt (`deckBoardId`, `deckCardId`, `talkToken`,
  `groupfolderId` — stubbade id:n), `provenance`, `retention` och `handlingar`.
- `RECEIPTS` (array) — verifierade Frends→Treserva-kvittenser som driver kvittens-/
  retention-ytan.

Exporter: `caseIdFor`, `seedRegister` (seam `treserva.seed`), `getEntry`, `skapaArende`
(seam `treserva.skapa`), `commitHandling` (seam `treserva.commit`), `listReceipts`,
`kopplaInflode` (seam `treserva.koppla`), `_dumpRegister` (dev-introspektion).

Det kritiska mönstret: **retention startar ENBART på en verifierad commit**, inte på en
kryssruta. I `commitHandling()` sätts `entry.retention = { state: 'gallras_efter_commit',
verifierad: true, startadAv: 'verifierad Frends-callback', … }` först efter att
handlingen registrerats — exakt det mönster som GAP-007 kräver, i stub-form.

**Så här byter du till skarpt.**

1. **Registret → Tables.** Ersätt `REGISTER`-Map med sdkmc:s `hubs_arenden`-register i
   Nextcloud Tables. sdkmc är **ensam skrivare**. `seedRegister`/`_dumpRegister` faller
   bort (registret finns redan).
2. **Skapa ärende.** `api.js → skapaArende()`: ta bort `if (DEMO) return ssDemo.skapaArende(rad)`.
   Prod-grenen finns redan: `POST {SDKMC_OCS}/arende` med `{ rad }`. sdkmc gör register +
   ärenderum (Groupfolder) + ACL + Deck-kort + chattrum + 14-dgr-klocka **atomärt**.
3. **Committa handling.** `api.js → commitToTreserva()`: prod-grenen finns redan:
   `POST {SDKMC_OCS}/treserva/commit`. Bakom den ska ligga **Frends-flödet**:
   POST → Treserva → **verifierad återkallning (callback)**. Först på callbacken flippar
   sdkmc provenans (`registrerad`) och sätter retention-taggen. Store-action
   `commitArende()` (`store/index.js`) konsumerar redan `{ ok, dnr, gallrasDatum,
   verifierad, receipt }` — samma shape som stubben returnerar, så frontend är oförändrad.
4. **Koppla inflöde.** `api.js → inflodeAction('koppla', …)`: `POST {SDKMC_OCS}/inflode/koppla`;
   sdkmc sätter `case:{hubsCaseId}`-taggen + speglar filen.
5. **Kvittenser.** `api.js → fetchTreservaReceipts()`: prod-grenen `GET {SDKMC_OCS}/treserva/receipts`.

> **Routes som ska in (alla redan refererade i `api.js`):** `POST /arende`,
> `POST /treserva/commit`, `GET /treserva/receipts`, `POST /inflode/{action}` —
> alla under `apps/sdkmc/api/v1` (`SDKMC_OCS`). Tables-registret: `hubs_arenden`.
> Frends: ett iPaaS-flöde per handlingstyp med verifierad callback.

### `favoriter.js` — Kontakter-favorit-resolverlagret

**Nuvarande beteende (demo).** Returnerar de tre vCard:en som live-seedats i Kontakter-
appens "Favoriter"-adressbok, redan som **resolvade DTO:er**. Tre klasser:

- **(a) `sdk-pekare`** — ren DIGG-pekare (`sdkRef` = `X-HUBS-SDK-REF`), föränderliga fält
  resolvade färskt ur DIGG.
- **(b) `extern-funktion`** — Hubs-ägd extern fax-vCard (Hubs äger värdet; `owner` =
  funktion, ej individ).
- **(c) `intern-anvandare`** — pekare till användarkatalogen (`userRef` = `X-HUBS-USER-REF`).

Plus en fjärde post som demonstrerar **tombstone** (`removed:true`). `fetchFavoriter(opts)`
filtrerar på `opts.lista` (`personlig` | `mottagningen@`).

**Så här byter du till skarpt.**

1. `api.js → fetchFavoriter()`: ta bort `if (DEMO) return ssDemo.fetchFavoriter(opts)`.
   Prod-grenen finns redan: `GET {SDKMC_OCS}/favoriter` med `params: opts`.
2. Bakom routen: det **tunna sdkmc-resolverlagret** ovanpå Kontakter-appen (används
   som-den-är). Ett anrop kör `IManager::search` över personlig ∪ funktions-delad
   favorit-adressbok (ingen klient-fan-out) och **batch-resolvar** pekarna mot DIGG-/
   användarkatalog-cachen → `{färska fält, resolvedAt, stale, removed}`.
3. **Fail-closed (GAP-061):** när DIGG ej nås ska favoriten markeras icke-väljbar i stället
   för att visa cachad data — annars kan en handläggare vidarebefordra till en återkallad
   SDK-adress. Tombstone-modellen (`removed:true` → överstruken) finns redan i DTO:n.
4. **Medborgar-PII-spärr (GAP-064):** server-side klass-validering (a/b/c) som avvisar
   fri medborgar-PII-favoriter och styr dem till ärendet.

> Se även `docs/KONTAKTER-FAVORITER.md` för hela favorit-modellen.

---

## 4. DEMODATA-karta — vilken fil driver vad

| Fil | Vad den håller / driver |
|---|---|
| `src/services/demo/socialsekreterare.js` | **Socialsekreterarvyns** hela demodata: `triage` (Zon 1 otrierat inflöde), `arenden` (Zon 2/3 ärendekort, inkl. `stageBulk`-genererade fyllnadsärenden), `enrichments` (lazy per-ärende diskussion + provenanskedja), `korgar` + `inflode` (multi-korg, banden 1a/1b/1c), `fordelningSummary` (gruppledarens fördelningsvy), `team`, `puls` (Dagspulsen), `moten`, `klartIdag`. Routar mutationer till treserva-stubben (`commitToTreserva`, `skapaArende`, `kopplaInflode`, `fetchReceipts`) och till favorit-stubben (`fetchFavoriter`). Kör `treserva.seedRegister(arenden)` vid import. |
| `src/services/demo/treserva.js` (seed) | `seedRegister()` är **demodata-källan för registret + historiska kvittenser** — den deriverar `REGISTER`- och `RECEIPTS`-innehållet ur `arenden`. All "redan registrerad"-status och kvittens-ytans starttillstånd kommer härifrån. |
| `src/services/demo/favoriter.js` | De **4 favorit-DTO:erna** (3 klasser + 1 tombstone) som driver `FavoritValjare` i mottagar-/komponeringsytan. |
| `src/services/demoData.js` | **Övriga personas** (icke-socialsekreterare) fixtures: triage-`items` + `summary` (counts/mailboxes/watching/channelCoverage), `receipts` (KvittensWidget), `meetings` + `lobby`, `appointmentConfigs`, `recipients` (smart-mottagar-sök + kanalklassning). Plus `isDemo()` och hela `demo`-objektet som `api.js` short-circuitar mot. |

**Mutationsväg i demon (var demo-state ändras):** `store/index.js` håller den reaktiva
state-slicen `state.arende` och kör actions som muterar den genom stubbarna —
`commitArende()` (flippar provenans/retention/plikt + lägger kvitto på ytan),
`inflodeAction('skapa'|'koppla'|…)` (lägger nytt ärende överst / tar bort inflöde-rad),
`loadReceipts()`, `loadFavoriter()`, `loadArendeSummary()`, `loadInflodeSummary()`,
`loadFordelningSummary()`, `loadTeam()`. Dessa actions är **identiska** i prod — bara
`api.js`-grenen under dem byter från stub till OCS.

---

## 5. Att göra för full lösning (prioriterad)

Kopplad till de kvarstående blockerarna i `docs/SOCIALSEKRETERARE-WALKTHROUGH-V2.md`.

1. **Frends/Treserva-konnektorn — GAP-019 (blocker, tyngst).** Bygg det riktiga Frends-
   flödet bakom `POST {SDKMC_OCS}/treserva/commit` och `POST {SDKMC_OCS}/arende` med
   **verifierad callback**. Grundorsak bakom commit/gallring/spegling. Seam: `treserva`,
   `treserva.commit`, `treserva.skapa`, `treserva.koppla`.
2. **Gallring bunden till verifierad commit — GAP-007 (blocker).** Flytta retention-starten
   från ett spec-fält till den faktiska verifierade Frends-callbacken (sdkmc sätter
   retention-taggen först där). Stubben gör redan rätt *mönster* i `commitHandling()`;
   prod måste binda det till ett riktigt callback-event, aldrig till tagg+tid. Seam:
   `treserva.commit`.
3. **Registret som single point of truth — GAP-056 (blocker).** `hubs_arenden` i Tables
   blir join-nyckeln för alla appar: transaktionell saga/kompensering vid partiell
   orkestrering + reconciliation-jobb register↔objekt + integritets-larm + arkivkritisk
   backup/återställning. Seam: `treserva`, `treserva.tombstone`.
4. **Fördelnings-ACL-race — GAP-057 (blocker).** Gör `inflodeAction('tilldela', …)` till en
   atomär multi-objekt-commit med lås/idempotens på `hubsCaseId` (revoke→grant utan
   sekretessfönster). Idag optimistisk store-stubb.
5. **Tre-lagers-ACL-koherens — GAP-058 (blocker).** En kanonisk ACL-källa per
   enhet/funktionsadress som genererar alla tre lagren (`case:`-tagg, Groupfolder-ACL,
   Tables-vy) + automatiserat koherens-test + deny-by-default i aggregeringen.
6. **Favorit-resolverlager + fail-closed — GAP-061 (major).** Bygg `GET {SDKMC_OCS}/favoriter`
   (IManager::search + DIGG-batch-resolve) med obligatorisk färsk-resolve-vid-läsning och
   hård fail-closed. Seam: `favoriter`, `favoriter.tombstone`.
7. **Favorit-PII-styrning — GAP-062/063/064 (major).** Favoritlistor som egna handlingstyper
   i DHP + funktions-ägare på klass (b); vidarebefordran via samma "registrera först?"-grind;
   server-side blockering av fri medborgar-PII (klass a/b/c-validering). Seam: `favoriter`.

> Övriga oförändrade blockerare (GAP-001 skyddsbedömnings-tvång, GAP-034/035/037/033
> Inera-AES/LTV, GAP-052 AI på sekretess, GAP-031 retention-paus) ligger utanför detta
> seam-register men antas lösta i walkthrough V2 — se gap-analysen där.

---

*Varumärkesregel: i denna bygg-dokumentation används tekniska app-namn (Nextcloud, Tables,
Groupfolder, Deck) fritt; i UI-citat gentemot slutanvändare ska de aldrig synas.*
