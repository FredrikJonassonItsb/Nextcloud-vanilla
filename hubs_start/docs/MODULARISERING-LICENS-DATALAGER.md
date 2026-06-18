<!--
SPDX-FileCopyrightText: 2026 ITSL
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Målarkitektur: Modularisering, licens och internt datalager

**Till:** Produkt / Arkitektur · **Från:** Arkitekturutredning (konsoliderar tre PM) · **Datum:** 2026-06-16
**Status:** Beslutsunderlag — syntetiserar (1) modul-paketering, (2) AGPLv3-licensanalys, (3) internt datalager vs Tables till EN sammanhängande målarkitektur.
**Scope:** Hur Hubs paketeras i separat säljbara moduler, vilken licens varje del tvingas under, och var ärende-motorns state ska ligga — och varför dessa tre frågor har **ett gemensamt svar**.

> **Terminologi:** tekniska namn (Talk/Tables/Deck/AppAPI/ExApp/Flow/Groupfolders) används fritt — detta är en utvecklar-/arkitekturtext. Aldrig leverantörsnamn i kund-UI.
> **Ärlighetsmarkörer:** `[FINNS]` = verifierad körande kod jag läst · `[BYGGS]` = designat, ingen kod · `[KONFIG]` = befintlig mekanism, kräver konfiguration · `[FORK]` = ändring i forkad app.

---

## 0. Sammanfattning + den förenande insikten

De tre frågorna — **(A)** "hur säljer vi delarna separat?", **(B)** "måste verksamhetslogiken vara AGPL?", **(C)** "var ska ärenderegistret ligga?" — har visat sig peka mot **exakt samma målarkitektur**:

> **En tunn AGPL-bro-app i Nextcloud + en proprietär-kapabel verksamhets-ExApp med egen intern DB + säljbara moduler (M1–M4) ovanpå en osynlig plattformskärna (M0).**

**Varför det är ETT svar och inte tre:**

| Frågan | Svaret | Samma mekanism |
|---|---|---|
| **(A) Modularisering** | M4 = motor + valbara konnektorer; degraderar via `AppDetectionService` `[FINNS]` | ExApp gör konnektorerna separat prissatta artefakter |
| **(B) Licens** | Processgränsen ÄR licensgränsen; ExApp + egen DB = "separate program" = kan vara proprietär | ExApp-tjänsten flyttar verksamhetslogiken ut ur AGPL-processen |
| **(C) Datalager** | Tables underkänd; proper DB med single-writer; ExApp-rent schema | Den egna DB:n bor i ExApp-tjänsten — samma container |

**Den förenande insikten:** ExApp-modellen löser modul-, licens- OCH datalagerfrågan i ett enda arkitekturval. En separat container med egen process, egen databas och ett app-nivå-API över HTTP är samtidigt: (i) en separat säljbar/licensierbar artefakt, (ii) ett "separate program" som inte smittas av AGPL, och (iii) en proper relationell DB med single-writer-garanti som Tables aldrig kunde ge. **Licens, modularisering och datalager pekar åt samma håll.**

**Brytpunkt:** dagens `sdkmc` är en monolit som blandar två ansvar (plattform + meddelandelogik). Den måste delas i **M0 (sdkmc-core, plattformskärna)** + **sdkmc-msg (M1-specifikt)** — annars är "sälj M4 separat" en lögn, eftersom ärende-motorn föds inuti sdkmc och skulle draga med sig hela meddelande-stacken.

**Invariant som överlever allt:** Hubs är **ALDRIG System of Record**. Den interna DB:n lagrar *var saker ligger* (pekare/koordination), inte *sakerna själva* (verksamhetsinnehållet bor i facksystemet). Varje `ArendeTyp`-rad måste ha icke-null `commitDestination` — nu hävdat som **NOT NULL-constraint**, inte bara kod-konvention.

> **⚠️ Licens-disclaimer (se §2.6):** Detta är teknisk/arkitektonisk analys, INTE juridisk rådgivning. Gränsen "combined work" vs "separate program" är en rättsfråga. Inget proprietärt-licens-beslut får fattas på enbart detta PM — en IP-jurist måste verifiera mot faktisk kod och distributionsmodell först.

---

## 1. Modul-paketering (frågan om att sälja separat)

### 1.1 Kärnmodell: kärna-plus-tillägg, inte fyra likvärdiga block

Hubs kan säljas som fyra moduler, men kod-verkligheten tvingar fram en **kärna-plus-tillägg-modell** av tre verifierade skäl:

1. **sdkmc är redan idag ett delat plattformslager**, inte en del av "Meddelanden". Det äger egna DB-tabeller (`sdkmc_itsl_tag` m.fl., 19+ migrationer), kör tagg-/typ-/retention-/korg-motorn `[FINNS]`, OCH är det utpekade hemmet för den framtida ärende-motorn (`ArendeService`, `hubs_arenden`) som M4 behöver `[BYGGS]`. Samma binär betjänar M1 och M4.
2. **`AppDetectionService` finns redan** `[FINNS]` och är hela det tekniska fundamentet för "sälj separat med graceful degradation". Den letar idag efter `['sdkmc','mail','spreed','calendar']` och tänder kanaler efter vad som finns.
3. **Verksamhetsmodulen (M4) lagrar ingen verksamhetsdata** — den renderar aggregat och skickar kommandon mot grannmodulerna. Den är per konstruktion en orkestrerare ovanpå M1–M3, inte en självförsörjande produkt.

**Slutsats:** Sälj **M1 (Meddelanden) som ankarprodukt** (självförsörjande). M2/M3 som självförsörjande tillägg. **M4 (Verksamhet) säljs som "motor + valbara konnektorer"** ovanpå en obligatorisk **plattformskärna (M0)** som bryts ut ur dagens sdkmc.

### 1.2 Dependency-graf

```
                         ┌─────────────────────────────────────────────┐
                         │  M0  PLATTFORMSKÄRNA  (obligatorisk, osynlig) │
                         │  sdkmc-core: register hubs_arenden,           │
                         │  ArendeService (saga), case:{id}-taggmotor,   │
                         │  AppDetectionService-kontrakt, OCS /api/v2    │
                         │  [taggmotor FINNS] [register+saga BYGGS]      │
                         └───────────────▲─────────────────────────────┘
                                         │ alla moduler talar mot M0:s
                                         │ case:{hubsCaseId}-token + OCS-aggregat
        ┌────────────────┬──────────────┼───────────────┬────────────────┐
        │                │              │               │                │
 ┌──────┴──────┐  ┌──────┴──────┐ ┌─────┴──────┐  ┌──────┴──────┐  ┌──────┴───────┐
 │ M1 MEDDEL.  │  │ M2 VIDEO    │ │ M3 FILER   │  │ KONTAKTER   │  │ M4 VERKSAMHET │
 │ mail-fork + │  │ &CHAT       │ │ Files/     │  │ (Contacts   │  │ hubs_start + │
 │ securemail +│  │ spreed-itsl │ │ Groupfolders│ │  som-den-är │  │ ärende-motor │
 │ sdkmc-msg   │  │ + calendar  │ │            │  │  + resolver)│  │ (ExApp/M0)   │
 └─────────────┘  └─────────────┘ └────────────┘  └─────────────┘  └──────┬───────┘
   självförsörj.    självförsörj.   självförsörj.    tunt lager          │
                                                                          │ BEROR PÅ (graceful)
                                                       ┌──────────────────┼──────────────────┐
                                                       ▼ chatt            ▼ filer            ▼ kanaler
                                                     M2 (Spreed)        M3 (ärenderum)      M1 (inflöde)
```

### 1.3 Riktade beroenden

| Beroende | Riktning | Hård/mjuk | Verifierat |
|---|---|---|---|
| Allt → **M0** | uppåt | **HÅRD** — alla delar `case:{hubsCaseId}` + sdkmc OCS | Taggmotor `[FINNS]`; register `[BYGGS]` |
| **M1** → M0 | uppåt | HÅRD (M1:s tagg/typ/retention ÄR M0:s motor idag) | `ItslTagService`, `MessageTypeService` `[FINNS]` |
| **M4** → M1 | nedåt | **MJUK** (graceful) — inflöde/kanaler | `AppDetectionService::channelCoverage()` `[FINNS]` |
| **M4** → M2 | nedåt | **MJUK** — all chatt via `talkToken`-pekare | `TalkController` `[FINNS]`; rum-skapande `[BYGGS]` |
| **M4** → M3 | nedåt | **MJUK** — ärenderum = Groupfolder | saga-steg `[BYGGS]` |
| **M4** → Kontakter | nedåt | **MJUK** — favoriter/motpart | resolver-design, ingen fork |
| **M2** → M1 | sidled | INGEN hård — Spreed/calendar står själva | `info.xml` fristående |
| **M3** → M1 | sidled | INGEN hård — Files/Groupfolders nativa | nativa NC-appar |

### 1.4 Hur säljs M4 separat trots beroenden? Tre mekanismer

**(a) Obligatorisk kärna.** M4 kan aldrig säljas utan M0 — ärende-registret och saga-orkestreringen *bor* i M0. M0 är därför en del av M4:s minsta säljbara enhet.

**(b) Graceful degradation via `AppDetectionService` — verifierat mönster** `[FINNS]`. `detect()` returnerar `appId → enabled`; `channelCoverage()` bygger listan av tillgängliga kanaler. Måste **utökas** från kanal-detektering till **funktions-detektering** (lägg `calendar`, `groupfolders`, `files`, `contacts`) `[BYGGS]`, men mönstret och placeringen finns.

**(c) Vad tänds/släcks per granne** (saga R1–R9 är stegvis degraderbar):

| Granne saknas | M4 fungerar? | Vad släcks | Vad ersätter |
|---|---|---|---|
| **M1** | Ja, men tomt | Inget meddelandeinflöde → tom triage; 0 kanaler | Ren ärende-/fördelnings-vy för manuella ärenden |
| **M2** | Ja | Ärende-/enhetschatt + `talkToken`-pekare (saga-steg hoppas) | "Chatt ej tillgänglig"; ärendet lever utan rum |
| **M3** | Ja | Ärenderum (Groupfolder) skapas ej | Ärende får register + tagg men ingen fil-yta |
| **Kontakter** | Ja | `FavoritValjare` tom; motparts-resolver tom | Fri-text-mottagare utan favorit-stöd |

**Bärande princip:** varje grannberoende saga-steg hoppas om grannen saknas, utan att invarianten (register + `case:`-tagg + `commitDestination`) bryts. M4:s kärnlöfte (fördela, ärendekoppla, committa till facksystem) kräver bara **M0 + M1**.

### 1.5 sdkmc-uppdelningen: M0 (plattform) vs M1 (meddelande)

Två appar/namespaces ur dagens en. Single-writer-disciplinen bevaras — den flyttar bara till rätt lager.

| Nytt: **sdkmc-core / `sdkmc_core`** (M0, plattform) | Stannar i: **sdkmc-msg** (M1-specifikt) |
|---|---|
| `hubs_arenden`-registret + `Arende`/`ArendeMapper` `[BYGGS]` | `MessageTypeService` (kanal-/typklassning) `[FINNS]` |
| `ArendeService` (skapa-saga + kompensering) `[BYGGS]` | `MailboxRetentionService`/`ExpungeService`/`ExpungeJob` `[FINNS]` |
| `case:{id}`-taggmotorn `ItslTagService`+`ItslTag` `[FINNS]`* | `ConsolidateMailboxesService`/korg-provisioning `[FINNS]` |
| `ArendeMatchService` (ärendekoppling) `[BYGGS]` | `MessageThread`/`MessageReceipt` (trådning/kvittenser) `[FINNS]` |
| `FacksystemCommitService` (per-produkt-konnektorer) `[BYGGS]` | `UpdateAddressBookService` (DIGG-synk) `[FINNS]` |
| `AppDetectionService`-kontrakt + OCS-aggregat `[FINNS/utökas]` | `MessageImportantClassifiedListener`, Flow-`Loa3`-reg `[FINNS]` |
| `ArendeReconciliationJob` (GAP-056) `[BYGGS]` | securemail-bryggan `[FINNS]` |

\* **Taggmotorn flyttar till M0** — den är join-mekanismen (`case:{id}`-token), inte mail-logik. M0 blir det enda stället som äger `case:`-token, vilket är hela poängen. **Brytningen är en kod-refaktor, inte en data-migration** (`sdkmc_itsl_*`-tabellerna kan ligga kvar, ägandet flyttar). Måste göras **FÖRE M4 byggs**, annars cementeras monoliten.

### 1.6 Modul-matris

| | **M0 PLATTFORM** | **M1 MEDDELANDEN** | **M2 VIDEO & CHAT** | **M3 FILER** | **M4 VERKSAMHET** |
|---|---|---|---|---|---|
| **Ingående appar** | sdkmc-core | mail-fork + securemail + sdkmc-msg | spreed-itsl + calendar | Files + Groupfolders | hubs_start + ärende-motor |
| **Ger fristående** | join-token, register, saga-API | säkra meddelanden/fax/internpost/SMS, kvittenser, korgar, retention | säkert möte, all chatt, bokning | ärenderum/säkra filer, ACL | fördelning, ärendekoppling, triage, commit |
| **Hårda beroenden** | — (är basen) | **M0** | inga hårda | inga hårda | **M0** + **M1** |
| **Mjuka beroenden** | — | — | — | — | M2, M3, Kontakter |
| **Status i kod** | taggmotor `[FINNS]`, register+saga `[BYGGS]` | merparten `[FINNS]` | `TalkController` `[FINNS]`, rum `[BYGGS]` | nativt `[FINNS]`, saga-steg `[BYGGS]` | UI `[FINNS]`, motor `[BYGGS]` |
| **Min. säljbar enhet** | följer M1 & M4 | M0 + M1 | M2 (+M0 om ärendekoppling) | M3 (+M0 om ärenderum) | **M0 + M1 + M4** (M2/M3 valbara) |

---

## 2. AGPL-licensposition (frågan om proprietär verksamhetslogik)

### 2.1 Vad triggar AGPL-copyleft

AGPLv3 har två oberoende triggers:

- **Copyleft-triggern (§ärvd från GPL): "combined work".** FSF:s GPL-FAQ: delad adressrymd / länkning → "almost surely one program". Pipes/sockets/HTTP → "normally separate programs". **Men** semantik kan väga upp mekanik — intim utväxling av interna datastrukturer kan göra två delar till ett verk.
- **Nätverks-copyleft (§13).** Utlöses bara *"if you modify the Program"*, och binder *det modifierade verkets* källa — **inte** en separat tjänst som bara talar med det över nätet. §13-skyldigheten vilar på den som *driftar* den modifierade NC:n (kommun/driftleverantör) avseende NC + forkade appars källa.

**Praktisk slutsats: processgränsen ÄR licensgränsen.** Allt som körs i NC:s process eller modifierar en NC-app → AGPL. Allt som körs i egen process/DB och talar app-nivå-HTTP → kan vara proprietärt.

### 2.2 De tre gränssnittsfallen

| Fall | Var koden körs | Koppling till NC | FSF-kategori | AGPL-tvång? | Nyckelförbehåll |
|---|---|---|---|---|---|
| **(a)** In-process PHP-app + OCP | NC:s PHP-process | Delad adressrymd, intim (DI, IDBConnection, events) | Combined work | **Ja, troligt** | OCP "public" ändrar inte upphovsrätten. *Detta är dagens hubs_start och sdkmc — korrekt AGPL.* |
| **(b)** Vue/SPA-frontend | Webbläsaren | HTTP → OCS/REST | Separat program | **Nej, sannolikt** | Ingen `@nextcloud/*`-bundling (AGPL); separat leverans; app store = AGPL |
| **(c)** ExApp/microservice + egen DB | Egen container/process | HTTP/AppAPI, JSON på app-nivå | Separat program | **Nej, sannolikt** | ExApp-*skalet* (NC-sidig registrering) kan vara AGPL; API på app-nivå, ej intern-RPC |

### 2.3 Vad MÅSTE vara AGPL vs vad KAN vara proprietärt

**MÅSTE vara AGPL:**
- **Bro-appen i NC** (hubs_start-skalet: navigation, OCP-anrop, `AppDetectionService`, ExApp-registrering) — in-process PHP = combined work.
- **All OCP-användande PHP** generellt.
- **sdkmc om den förblir in-process** (QBMapper/Migration mot `sdkmc_itsl_*`) — in-process datalager-app = combined work.
- **Forkade appar M1/M2** — `mail` (`AGPL-3.0-only`, verifierat) och `spreed-itsl` (`AGPL-3.0-or-later`, verifierat) ÄR modifierade AGPL NC-appar. AGPL ärvt, ingen valfrihet.

**KAN vara proprietärt (villkorat):**
- **Verksamhetslogiken (M4)** som ExApp-tjänst — egen process + egen DB, app-nivå-API, ExApp-skal minimalt/AGPL, ej app store.
- **Frontend-SPA** — endast via OCS/REST, rå `fetch`, ingen `@nextcloud/*`-bundling, separat leverans.
- **Per-produkt-facksystemkonnektorer** (`FacksystemCommitService`-konnektorer) — lever i ExApp-tjänsten.
- **Ärenderegister-datalagret** — om i ExApp-DB, utanför AGPL-processen.

### 2.4 App store-kravet

Publicering på apps.nextcloud.com **kräver AGPL-3.0-or-later eller kompatibel licens** ("Apps must be licensed under AGPL-3.0-or-later or any compatible license"). Privat distribution (direktleverans till kommun) tar bort *store-politiken* men **inte** copyleft-mekaniken — ett combined work är AGPL p.g.a. upphovsrätten oavsett kanal. **Proprietär M4 förutsätter privat distribution + arm's-length-arkitektur.**

### 2.5 Licens-mappning per modul (verifierat)

| Del | Licens (verifierad) | Säljbar separat under AGPL? |
|---|---|---|
| M1 mail-fork | `AGPL-3.0-only` | Ja — källa måste erbjudas |
| M1 securemail | egen container (Node/Express+Vue), ej NC-app — verifiera egen licens | Ja — separat tjänst, ej via `occ` |
| M1/M0 sdkmc | `licence=agpl` (info.xml) | Ja |
| M2 spreed-itsl | `AGPL-3.0-or-later` | Ja |
| M2 calendar / M3 Files+Groupfolders | AGPL (NC) | Ja (följer NC) |
| M4 hubs_start | `AGPL-3.0-or-later` (info.xml) | Ja (bro-app, korrekt AGPL) |
| **M4 ärende-motor** (`ArendeService`, register, konnektorer) | **fritt val — ej skriven** | **AGPL om in-process; KAN vara proprietär som ExApp** |

> **Versionsskillnad att flagga:** mail `-only` vs spreed `-or-later` är inte trivial — `-only` kan inte uppgraderas till en framtida AGPLv4; kombinerad distribution måste hantera båda.

### 2.6 ⚠️ Obligatorisk licens-disclaimer

Allt ovan är **teknisk arkitekturanalys, INTE juridisk rådgivning.** Gränsen "combined work" vs "separate program" är en rättsfråga som FSF själva säger ytterst avgörs av domstol, och beror på faktiska detaljer (vad som korsar gränssnittet, paketering, vilka bibliotek som bundlas). **Innan bindande beslut om proprietär komponent MÅSTE en IP-/upphovsrättsjurist verifiera:** (i) att ExApp-gränssnittet är "arm's length", (ii) att inga AGPL-bibliotek bundlas på proprietär sida, (iii) distributionsmodellen, (iv) §13-skyldigheterna för driftande part avseende forkarna. **Fatta inga proprietära-licens-beslut på enbart detta PM.**

---

## 3. Internt datalager (frågan om ärenderegistrets hem)

### 3.1 Tables underkänns som motor-backend

Tables är en *användar-vänd kalkylarks-app* och bryter mot varje krav en saga med single-writer ställer:

| Krav (saga R1–R9) | Tables-verklighet | Konsekvens |
|---|---|---|
| **Single-writer** | Rader användar-redigerbara via UI/OCS | En användare kan korrumpera `dnr`/`provenanceState`/pekare. Sekretess-haveri. |
| **Riktiga transaktioner** | Ingen rollback över rows-API | Halvskrivna ärenden vid saga-fel; ingen atomär commit-punkt. |
| **Constraints/FK/unique** | Svag/ingen constraint på `hubsCaseId` | Dubbletter, dinglande pekare, `dnr`-kollision. |
| **Schema-/migrationskontroll** | Användar-/admin-konfigurerbart | Schema driver isär mellan miljöer. |
| **Prestanda vid volym** | EAV-aktigt bakom UI-API | Dashboard-aggregat/reconciliation skalar dåligt. |
| **Idempotens/lås** | Inget rad-lås | GAP-057-racet (sekretessfönster) går ej att stänga rent. |

**Slutsats:** stänger den öppna frågan i `HUBS-INTERNALS-ARENDEMOTOR.md §1.2.4` till förmån för **proper relationell DB med single-writer via QBMapper/Migration** — exakt mönstret sdkmc *redan* kör (`ItslTagMapper extends QBMapper`, `Types::JSON`, `addUniqueIndex`, soft-delete, `getOrCreate`-idempotens).

### 3.2 Tre placeringsalternativ

| Dimension | (a) NC Tables | **(b) sdkmc:s NC-app-DB** | **(c) Separat ExApp-DB** |
|---|---|---|---|
| Single-writer | ❌ Ingen | ✅ Mapper-disciplin | ✅ Process-isolerad |
| Transaktion/constraint/FK | ❌ Saknas | ✅ Full SQL via `ISchemaWrapper` | ✅ Full SQL + egen tuning |
| Migrations-/schemakontroll | ❌ Ingen | ✅ `Version02xxxx`-spår | ✅ Egen kedja |
| Prestanda vid volym | ❌ UI-overhead | 🟡 Bra (NC:s DB-pool) | ✅ Bäst (egen skalning) |
| Användar-korruptionsrisk | ❌ Hög | ✅ Ingen UI-väg in | ✅ Fysiskt utanför NC |
| Licens / AGPL | ❌ NC-internt | 🟡 NC-internt (AGPL) | ✅ Licens-ren, frikopplad |
| Implementationskostnad NU | (befintlig) | ✅ **Låg** (copy-paste mönster) | ❌ Hög (container/API/drift/backup) |
| Närhet till NC-objekt | I NC | ✅ Direkt `ISystemTagManager`/Deck/ACL | 🟡 Via AppAPI/OCS över nät |
| **Verdict** | **Underkänd** | **Rekommenderad START** | **Mål om licens/modul kräver det** |

**Beslut:** **börja i (b)** — noll ny infrastruktur, identiskt befintligt mönster, löser 100 % av Tables-problemen idag. **Designa schemat ExApp-rent** så att lyft till (c) blir en data-migrering, inte en omskrivning.

**Brygga (b)→(c):**
- **Inga FK:er ut mot `oc_*`.** Pekare (`deckCardId`, `talkToken`, `groupfolderId`, `caseTagId`, `calendarObjUri`) lagras som **opaka strängar/int**.
- **All skrivning via ETT service-lager** (`ArendeService` single writer), aldrig Mapper direkt från controllers. Då blir lyften en utbytt persistens-adapter bakom samma service.

### 3.3 Behåll-i-NC (användarnytta) vs flytta-till-DB (motor-mekanik)

**Testfråga per objekt:** *"Ser/använder en människa detta direkt, eller är det ren motor-koordination?"*

**BEHÅLL I NC (användarnytta):** Ärende-kort & stack (**Deck**) · ärenderum & dokument (**Files/Groupfolders**, ACL = OSL-sekretessgräns) · ärende-chatt (**Spreed**) · kontakter/favoriter (**Kontakter** — favorit-vCard taggas ALDRIG `hubsCaseId`) · säker e-post (**mail/securemail/sdkmc**) · `case:{hubsCaseId}`-systemtaggen (NC `ISystemTagManager` — bär kopplingen på objektet, måste bo där objektet bor).

**FLYTTA TILL INTERN DB (motor-mekanik):** Registret `hubsCaseId↔dnr↔pekare` · pekar-tabellen · routing-/matchnings-state · `ArendeTyp`-config/registry · frist-spegling (`fristDue`, `provenanceState`, `retentionState`) · kvittens-/audit-logg (append-only) · idempotens-/lås-nycklar (GAP-057).

**Enradsregeln:** *Det användaren rör → NC. Det motorn räknar och pekar med → intern DB.*

### 3.4 NC-objekt blir VYER/projektioner

```
                 ┌─────────────────────────────────────────────┐
                 │   INTERN DB (sdkmc_arende / ExApp)           │
                 │   hubsCaseId (PK)  ↔  dnr                    │
                 │   deckCardId · talkToken · groupfolderId ·   │  ← single source
                 │   caseTagId · calendarObjUri · fristDue ·    │    of pekare
                 │   provenanceState · commitDestination (NOT NULL)│
                 └──────┬───────┬────────┬────────┬─────────────┘
            projektion  │       │        │        │  projektion
                        ▼       ▼        ▼        ▼
                     Deck    Files/   Spreed   Kontakter
                     (kort)  Groupf.  (rum)    (favoriter)
              tvåvägs: NC-objektet bär case:{hubsCaseId}-tagg/CATEGORIES
              DB-raden bär objektets id (deckCardId, talkToken, …)
```

- **Framåt (DB→NC):** motorn projicerar state (flyttar Deck-kort, skriver ACL, speglar frist).
- **Bakåt (NC→DB):** objektet bär `case:{hubsCaseId}` → slå upp ärendet från vilket objekt som helst.
- **Reconciliation (GAP-056)** blir robustare mot proper DB: indexerad `SELECT`, constraints fångar dinglande pekare vid skrivning, schemalagt `BackgroundJob` (mönster: `UpdateAddressBookBackgroundJob`).

### 3.5 Never-SoR-gränsen: intern DB ≠ verksamhets-SoR

> **Den interna DB:n håller MELLANLAGRETS koordinations-state — *var saker ligger*, inte *sakerna själva*.**

Verksamhetsdatan (utredning, beslut, journal) bor i **facksystemet** (Treserva/Lifecare/Viva) = System of Record. Intern DB innehåller **aldrig** verksamhetsinnehåll, bara pekare + koordination. Att byta Tables → intern DB ändrar **inte** Hubs SoR-status — Hubs var aldrig SoR. Risken att bli *tyst de-facto-SoR* hanteras av **`commitDestination`-invarianten**: varje `ArendeTyp`-rad måste ha icke-null `commitDestination` (facksystem/diarium/e-arkiv/extern_myndighet/triage_forward/karantan), nu hävdad som **NOT NULL-constraint** — något Tables aldrig kunde garantera. Proper DB *stärker* invarianten.

---

## 4. Målarkitekturen (det förenade svaret)

```
   ┌─────────────────────────── Nextcloud (AGPL-ZON) ──────────────────────────┐
   │                                                                            │
   │  [ MÅSTE vara AGPL — in-process PHP mot OCP = combined work ]               │
   │                                                                            │
   │  ┌── M0 sdkmc-core (tunn plattformskärna) ──┐   ┌── M1 MEDDELANDEN ──┐      │
   │  │ • case:{id}-taggmotor (ISystemTagManager) │   │ sdkmc-msg + mail-  │      │
   │  │ • AppDetectionService (graceful degr.)    │   │ fork + securemail* │      │
   │  │ • ExApp-registrering + OCS-aggregat       │   └────────────────────┘      │
   │  │ • bro: registrerar, autentiserar,         │   ┌── M2 VIDEO&CHAT ───┐      │
   │  │   djuplänkar, vidarebefordrar             │   │ spreed-itsl +      │      │
   │  │   (INGEN affärslogik — "tunn & dum")      │   │ calendar (forks)   │      │
   │  └───────────────────────────────────────────┘   └────────────────────┘      │
   │                                                  ┌── M3 FILER ────────┐      │
   │  hubs_start UI: Vue 2.7 (om bundlar @nextcloud/* │ Files+Groupfolders │      │
   │  → AGPL; alt. rå-OCS-SPA → kan vara propr.)      │ (nativa)           │      │
   │                                                  └────────────────────┘      │
   └────────────────────────────────┬───────────────────────────────────────────┘
                                     │  HTTP / AppAPI (definierat app-nivå-API)
                                     │  arm's length · JSON · verksamhetsbegrepp
                                     │  (ärende/åtgärd) · INGEN intern-RPC
   ┌──────────────────────────────────▼─────────────────────────────────────────┐
   │           M4 VERKSAMHETS-ExApp  (PROPRIETÄR-ZON — KAN vara proprietär)        │
   │                                                                              │
   │  • Ärende-motor: saga R1–R9 + kompensering, single-writer                    │
   │  • datadriven ArendeTyp-registry (commitDestination NOT NULL)                │
   │  • ArendeMatchService (ärendekoppling)                                        │
   │  • FacksystemCommitService + per-produkt-konnektorer (Treserva/Sokigo/…)     │
   │    = separat prissatta/licensierbara artefakter (5–10× GAP-019)              │
   │                                                                              │
   │  ┌── EGEN INTERN DB (Postgres/MySQL) ──────────────────────────────┐         │
   │  │ sdkmc_arende: hubsCaseId(PK)↔dnr↔opaka pekare · routing ·        │         │
   │  │ frist-spegling · kvittens-logg (append-only) · idempotens-lås    │         │
   │  │ INGA oc_*-FK:er — pekare som opaka strängar (ExApp-rent)          │         │
   │  └──────────────────────────────────────────────────────────────────┘         │
   └──────────────────────────────────────────────────────────────────────────────┘

   FACKSYSTEM (System of Record — verksamhetsinnehållet):  Treserva / Lifecare / Viva
   ▲ Hubs är ALDRIG SoR · intern DB = "kartan", facksystemet = "territoriet"
   * securemail = redan idag separat container (Node/Express), ej NC-app
```

**Dataflöde (skapa-ärende, saga):**
1. M1 inflöde (meddelande) → `case:`-tagg via M0-taggmotorn `[FINNS]`.
2. M0-bron vidarebefordrar app-nivå-event till M4-ExApp över HTTP.
3. M4-motorn (single-writer) skapar ärende-rad i egen DB, kör saga: Deck-kort (steg ~3), Groupfolder/ärenderum (steg 4, hoppas om M3 saknas), Spreed-rum (steg 6, hoppas om M2 saknas), kalender (steg 7).
4. Pekare lagras opakt i intern DB; NC-objekten bär `case:{hubsCaseId}` tillbaka.
5. Vid commit: `FacksystemCommitService`-konnektor skriver till facksystemet (SoR); kvittens `{hubsCaseId,dnr}` loggas append-only.
6. `AppDetectionService` `[FINNS]` styr vilka saga-steg som körs (graceful degradation).

**Tre licensgränser utritade:** (i) NC-processgränsen = AGPL-zonen (allt in-process PHP). (ii) HTTP/AppAPI-gränsen = den potentiella licensgränsen (M4 ExApp på andra sidan KAN vara proprietär). (iii) facksystemgränsen = SoR-gränsen (`commitDestination`-invarianten).

**Varför detta löser alla tre frågorna samtidigt:** ExApp-containern är *samtidigt* den separat säljbara modulen (§1), det "separate program" som inte smittas av AGPL (§2) och den egna interna DB:n med single-writer (§3). Ett arkitekturval, tre problem lösta.

---

## 5. Beslut + migrationsväg

### 5.1 Beslut som måste fattas

1. **Bryt sdkmc i M0 (sdkmc-core) + sdkmc-msg FÖRE M4 byggs** (§1.5). Annars är "sälj M4 separat" inte sant. Kod-refaktor utan data-migration, risk medel.
2. **Underkänn Tables som motor-backend; implementera (b) sdkmc-DB nu**, ExApp-rent schema (§3.1–3.2). Låg kostnad, löser GAP-056/057/058-grunden.
3. **`commitDestination` som NOT NULL-constraint** i `sdkmc_arende_typ` (§3.5) — hävdar invarianten i schemat.
4. **Utöka `AppDetectionService`** från kanal- till funktions-detektering (lägg `calendar`/`groupfolders`/`files`/`contacts`) (§1.4b).
5. **Definiera fyra SKU:er** ovanpå obligatorisk M0: M1 (ankare) · M2 · M3 · M4 (= M0+M1+motor, M2/M3/Kontakter tillval) (§1.6).
6. **(c) ExApp — håll som målbild**, besluta tillsammans med licens-/affärsmålet. Bygg inte i förskott, MEN designa (b) ExApp-rent från dag ett så lyften blir billig.
7. **Eskalera till jurist** (§2.6): proprietär M4-status, mail `-only` vs spreed `-or-later`, securemail egen licens, §13-skyldigheter. **Inget proprietärt beslut utan juridisk verifiering.**

### 5.2 Migrationsväg

**Steg 0→1: demo (in-memory `REGISTER`-Map, `demo/treserva.js`) → intern DB (b).**
1. `lib/Migration/Version02xxxxCreateArendeTable.php` — `sdkmc_arende` + `sdkmc_arende_typ` + `sdkmc_arende_receipt` (mönster: `Version020000…`, `Types::JSON` för pekar-bagen, `addUniqueIndex(['hubs_case_id'])`, `NOT NULL` på `commit_destination`).
2. `lib/Db/Arende.php` + `ArendeMapper.php` — kopiera `ItslTag`/`ItslTagMapper` (`findByHubsCaseId`, `findByDnr`, `getOrCreate`-idempotens).
3. `ArendeService` (single writer + saga R1–R9 + kompensering; idempotensnyckel på `conversationId` à la `findUniqueImapLabel`). Controllers rör **aldrig** Mapper direkt.
4. `ArendeController` + routes: `POST /api/v2/arende`, `GET /api/v2/arende/{ref}`, `GET /api/v2/arende-summary`, `POST /api/v2/arende/{ref}/tilldela`, `POST /api/v2/treserva/commit`.
5. Frontend-seam: `demo/treserva.js` pekas om från in-memory till OCS-routerna. Demons röda tråd bevaras, bara backend byts.

**Steg 1→ev. 2: (b) sdkmc-DB → (c) ExApp-DB (om licens/modul kräver).**
Tack vare ExApp-ren design: inga `oc_*`-FK:er, opaka pekare. Migreringen = stå upp ExApp-container + egen DB → kör samma migrationskedja → `ArendeService` byter persistens-adapter (Mapper → ExApp-DB-klient) → NC-sidan anropar motorn över AppAPI i stället för in-process. **NC-projektionerna (Deck/Files/Spreed/Kontakter) ändras inte** — de var redan bara vyer; `AppDetectionService` styr graceful degradation oförändrat. **Data- + adapter-migrering, inte omskrivning** — hela poängen med ExApp-rent från start.

---

## Relevanta källfiler (absoluta sökvägar)

- `C:\Users\fredrik.jonasson\Cursor\Nextcloud-vanilla\hubs_start\lib\Service\AppDetectionService.php` — graceful-degradation `[FINNS]`
- `C:\Users\fredrik.jonasson\Cursor\Nextcloud-vanilla\hubs_start\appinfo\info.xml` — hubs_start `AGPL-3.0-or-later`, NC 30–32
- `C:\Users\fredrik.jonasson\Cursor\Nextcloud-vanilla\hubs-code\sdkmc\sdkmc-main\appinfo\info.xml` — sdkmc `licence=agpl`, `/api/v2/`
- `C:\Users\fredrik.jonasson\Cursor\Nextcloud-vanilla\hubs-code\sdkmc\sdkmc-main\lib\Db\ItslTag.php` + `ItslTagMapper.php` — mönster att kopiera för `ArendeMapper`
- `C:\Users\fredrik.jonasson\Cursor\Nextcloud-vanilla\hubs-code\sdkmc\sdkmc-main\lib\Migration\Version020000Date20250213143200.php` — migrations-mönster
- `C:\Users\fredrik.jonasson\Cursor\Nextcloud-vanilla\hubs-code\mail\mail-main\overlay\appinfo\info.xml` — mail-fork `AGPL-3.0-only`
- `C:\Users\fredrik.jonasson\Cursor\Nextcloud-vanilla\hubs-code\spreed-itsl\spreed-itsl-main\appinfo\info.xml` — spreed-fork `AGPL-3.0-or-later`
- `C:\Users\fredrik.jonasson\Cursor\Nextcloud-vanilla\hubs-code\securemail\securemail-main\README.md` — securemail = separat container, ej NC-app
- `C:\Users\fredrik.jonasson\Cursor\Nextcloud-vanilla\hubs_start\src\services\demo\treserva.js` — in-memory `REGISTER` (migrationskälla)
- `C:\Users\fredrik.jonasson\Cursor\Nextcloud-vanilla\hubs_start\docs\HUBS-INTERNALS-ARENDEMOTOR.md` §1.1/§1.2.4 — öppen designfråga (stängs här)
- `C:\Users\fredrik.jonasson\Cursor\Nextcloud-vanilla\hubs_start\docs\KOMMUNROLLER-SOR-INTEGRATIONER.md` — `commitDestination`-invarianten
- `C:\Users\fredrik.jonasson\Cursor\Nextcloud-vanilla\hubs_start\docs\HUBS-ARKITEKTUR-SOCIALTJANST.md` §3.3 — Windmill ExApp-gränsen

## Källor (licens)

- AGPLv3 §13 (SPDX, AGPL-3.0-or-later); GNU GPL FAQ (FSF, combined work vs aggregation); "Reading AGPL" (Kyle Mitchell, §13 "if you modify"); Nextcloud app store-regler (Developer Manual); Nextcloud AppAPI/ExApp (admin manual).
