<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Hubs-internals — Ärende-motorn

**Intern arkitekturdokumentation (utvecklartext).** Syntes av fyra tekniska PM, verifierad mot verklig kod i `hubs-code/sdkmc/sdkmc-main`, `hubs_start/lib` och designdokumenten i `hubs_start/docs`.

**Scope:** hur ett ärende *föds*, *klassas*, *hålls ihop* och *avslutas* — och exakt *var* mekaniken bor (eller ska bo) i koden, fas för fas.

**Ärlighetsmarkörer genomgående:**
`[FINNS]` = verifierad, körande kod jag läst i sdkmc/hubs_start idag.
`[BYGGS]` = designat i `HUBS-ARKITEKTUR-SOCIALTJANST.md`, ingen kod existerar.
`[KONFIG]` = befintlig app/mekanism, kräver deklarativ konfiguration (Flow, ACL, IMAP, schema).
`[FORK]` = kräver ändring i en native/forkad app (t.ex. `spreed-itsl`).
`[ANTAS]` = blockerare som walkthrough V2 antar löst (Frends/Treserva, Inera, KB-Whisper, retention-paus).

> **Terminologi:** Facksystem = Treserva/Lifecare/Viva (system of record, slutlagring). Hubs = mellanlagring. I kod- och routnamn används tekniska app-namn (Talk, Circles, Tables) fritt — det är en utvecklartext. I UI-citat används aldrig leverantörens app-namn.

---

## 0. Sammanfattning + tre arkitekturlöften

### 0.1 De tre arkitekturlöftena (verifierade mot kod)

**(i) `hubs_start` lagrar INGEN verksamhetsdata — den fördelar och håller koll.**
Dashboardappens hela PHP-backend (`hubs_start/lib`) är fyra tunna klasser: `PageController.php` (sidan + `boot`-initialstate + `isDemoMode()`), `PreferencesController.php` + `Service/PreferencesService.php` (**enbart** per-användar-UI-preferenser), `RoleService.php`, `AppDetectionService.php`. Den *renderar aggregat* (`fetchArendeSummary`, `fetchInflodeSummary`, `fetchFordelningSummary`) och *skickar kommandon* (`skapaArende`, `kopplaInflode`, `commitToTreserva`, `tilldela`) mot sdkmc. All verksamhetsdata bor i **sdkmc / Tables (`hubs_arenden`) / Groupfolders (ärenderum) / Spreed (chatt) / Deck / Treserva**. `[FINNS]`

**(ii) ALL chatt sker i Spreed-rummet — aldrig i dashboarden.**
Medbedömning, ärendechatt, enhetschatt och möteschatt går genom ett Spreed-rum (`spreed-itsl`-forken), bundet av registrets `talkToken`-pekare. `ArendeDiskussion.vue` renderar bara en lättviktig trådvy och lagrar inget; sdkmc `TalkController` sköter authorize/callback/guest-identity. `[FINNS]` för TalkController; `[BYGGS]` för rum-skapande + `talkToken`-pekaren (kräver registret).

**(iii) Ärende-identiteten `hubsCaseId` är navet, ägd av sdkmc/Tables.**
Den enda joinnyckeln är `hubsCaseId` (UUID v4). Den bärs av varje objekt i varje app: som `case:{hubsCaseId}`-tagg på fil-/meddelandeobjekt, och som strukturerad pekare i registret för icke-taggbara objekt (Deck-kort, Talk-rum, kalender). Registret är **sdkmc:s ensamma skrivansvar**.

### 0.2 Den brutala grundregeln

"Appen installerad" ≠ "ärende-funktionen byggd". sdkmc är en **mogen kanal-/tagg-/typ-/retention-/Flow-motor med riktiga OCS-routes** — men det kanoniska **ärenderegistret `hubs_arenden` finns inte**, och **ingen** atomär "skapa/fördela ärende"-orkestrering eller Treserva-konnektor finns. Allt sådant lever idag som demo-stubbar i `hubs_start/src/services/demo/`.

- **FINNS i dag:** en fullt körande taggmotor för meddelanden (`ItslTagService` + `sdkmc_itsl_tag`/`sdkmc_itsl_message_tag`), en fil-taggningsväg via native systemtags (`TagFileController`), deterministisk kanal-/typklassning (`MessageTypeService`, **ingen LLM**), trådning, kvittenser, retention/gallring, DIGG-katalogsynk, korgar, och Flow-registreringen.
- **BYGGS:** registret `hubs_arenden`, `ArendeService` (single writer + saga), `ArendeMatchService` (ärendekoppling), Frends/Treserva-konnektorn, och reconciliation register↔objekt.

### 0.3 Topp-5 vad-som-måste-byggas (prioriterat)

1. **Frends/Treserva-konnektorn** (GAP-019, tyngst) — verifierad callback bakom `POST .../treserva/commit` + `POST .../arende`.
2. **`hubs_arenden`-registret i Tables** (GAP-056, blocker) — join-nyckeln för hela stacken.
3. **Atomär `createArende()`-orkestrering** (GAP-010/057) — register + Groupfolder+ACL + Deck + Talk-rum + `case:`-tagg + klocka, allt-eller-inget.
4. **Gallring bunden till verifierad commit** (GAP-007, blocker) — flytta retention-start från tid/tagg till faktisk Frends-callback.
5. **Tre-lagers-ACL-koherens** (GAP-058, blocker) — `case:`-tagg ∩ Groupfolder-ACL ∩ Tables-vy = samma sanning, deny-by-default.

> **Not om route-versioner (löst motsägelse):** designdoken talar om `/api/v1/arende*`. Den **verkliga** OCS-prefixen i `sdkmc/appinfo/routes.php` är `/api/v2/...` (taggrouterna ligger på bara `/api/tags`, `/api/messages`, `/api/thread`). Detta dokument följer den verifierade koden: nya ärende-routes ska registreras som `/api/v2/arende*` bredvid de befintliga, inte `/api/v1/`.

---

## 1. Ärende-motorn i detalj

### 1.1 `hubs_arenden`-registret (Tables) — navet

#### 1.1.1 Status: registret FINNS INTE som kod `[BYGGS]`

Det finns **ingen** Tables-tabell, ingen `Entity`, ingen `Mapper`, ingen migration för `hubs_arenden` i sdkmc. `grep` på `hubs_arenden` i `sdkmc-main/lib` ger noll träffar. Registret är designat i `HUBS-ARKITEKTUR-SOCIALTJANST.md` §1.2; idag lever det som en in-memory `REGISTER`-Map i `hubs_start/src/services/demo/treserva.js`. När texten nedan beskriver registret är det en **byggspec**, inte existerande kod.

#### 1.1.2 Exakt rad-shape (designad, §1.2) `[BYGGS]`

```
hubsCaseId       UUID        — kanonisk token (PK)
triageRef        text        — 'SN 2026-0142' (kommunal ref före aktualisering)
barnRef          text        — pseudonym, ALDRIG klartext-PII
enhet            text        — ägande team/funktionsadress (barn-familj@) — ACL-gräns
agareUid         user        — tilldelad handläggare (null = otilldelat)
status           select      — otilldelat | tilldelat
steg             select      — inflode|forhandsbedomning|utredning|beslut|uppfoljning|avslutat
dnr              text        — Treserva-dnr (null tills registrerad via Frends)
provenanceState  select      — ej_registrerad | registrerad
conversationIds  text[]      — alla sdkmc/mail/fax-referenser (1:n)
groupfolderId    int         — ärenderummet (Groupfolder)        ─┐
deckBoardId      int         — enhetens board                     │ two-way-
deckCardId       int         — ärendets kort på den boarden        │ pekare
talkToken        text        — ärendets chattrum (spreed-itsl)     │ {…}
calendarObjUri   text        — ev. seriekalender för möten         │
caseTagId        int         — systemtag-id för 'case:{hubsCaseId}'─┘ (restricted)
retentionState   select      — aktiv | pausad | gallras_efter_commit
fristDue         date        — speglad ur Treserva (Frends), ej självständigt räknad
skapad           datetime
```

#### 1.1.3 Ägarskap, identitets-trippeln, two-way-pekaren

- **Single writer:** Raden skrivs **uteslutande av sdkmc-orkestreringen** (blivande `ArendeService`), aldrig av handläggaren rått och aldrig av `hubs_start`. Samma single-writer-disciplin som taggmotorn redan praktiserar (`ItslTagService` är enda vägen in i `sdkmc_itsl_*`).
- **Identitets-trippeln (§1.1)** — tre identifierare hålls medvetet isär:
  - `conversationId` — föds när meddelandet **inkommer**, ägs av sdkmc, är provenans-ankaret (14-dagarsklockan startar här). Finns i koden idag som IMAP `Message-Id` och `MessageThread`-trådankaret. `[FINNS]`
  - `hubsCaseId` — föds när ärendet **skapas**, ägs av registret. Existerar även utan dnr.
  - `dnr` — föds när ärendet **registreras** i Treserva, ägs av facksystemet, paras 1:1 (1:n vid syskon) med `hubsCaseId`.
- **`hubsCaseId` ↔ `dnr` är 1:n-syskon (§1.3):** en orosanmälan om flera barn → ett `hubsCaseId` per barn som delar samma `conversationId`. Frends returnerar `[{hubsCaseId, dnr}, …]`. `conversationIds[]` är `text[]` på *varje* syskon-rad; `dnr` är 1:1 per rad.
- **Two-way-pekaren** gör "öppna ärende" till ett **O(1)-uppslag** i registret i stället för en fan-out-sökning över sju appar. Kardinalitet: `conversationIds[]` 1:n, `talkToken` 1:1, `deckBoardId` delad (enhetens board), `deckCardId` 1:1.

#### 1.1.4 Vad som FINNS i stället — de närliggande tag-tabellerna `[FINNS]`

Verifierat i migration `Version020008Date20251229000000.php` (+ `…0009` som lägger `is_assignment_tag`; senare migrationer lägger `username`/`deleted_at`):

**`sdkmc_itsl_tag`** (tag-katalogen, email-scopad):
```
id               INTEGER  autoincrement PK
email_address    STRING(255)  notnull            ← scope = funktionsadress (delad korg), INTE userId
imap_label       STRING(64)   notnull            ← t.ex. '$follow_up'; ett 'case:{id}' bor här
display_name     STRING(128)  notnull
color            STRING(9)    notnull
is_default_tag   BOOLEAN  default false
is_assignment_tag BOOLEAN default false          ← '$assignee_*' tilldelningstaggar
username         STRING (nullable)                ← tilldelad användare (assignment tags)
deleted_at       DATETIME (nullable)              ← soft-delete
UNIQUE(email_address, imap_label)  INDEX(email_address)
```

**`sdkmc_itsl_message_tag`** (objekt↔tagg-relationen för meddelanden):
```
id               INTEGER  autoincrement PK
imap_message_id  STRING(1023) notnull            ← bär IMAP Message-Id (= conversationId-ankaret)
tag_id           INTEGER  notnull
email_address    STRING(255)  notnull
INDEX(imap_message_id, email_address)  INDEX(tag_id)
```

**Slutsats:** objekt↔tagg-relationen för meddelanden finns och är produktionsmässig. Registret som binder `case:`-taggen till de övriga sex apparna saknas — det är klyftan `ArendeService` ska fylla.

### 1.2 SKAPA ÄRENDE — den atomära orkestreringen som en SAGA

#### 1.2.1 Status: orkestreringen FINNS INTE `[BYGGS]`

Ingen `ArendeService`, ingen "skapa ärende"-route, ingen saga i sdkmc idag. Designspecen är §3.2-B. Vad som *finns* är de enskilda byggstenarna sagan kommer att anropa: `ItslTagService->tagMessage()` (meddelandetaggning), `TagFileController` (filtaggning via systemtags), och native API:er (Deck, Spreed, groupfolders, CalDAV).

#### 1.2.2 Varför detta MÅSTE vara en saga, inte en DB-transaktion (GAP-057)

Stegen spänner över **olika system med olika persistenslager**: en `tables`-rad, en groupfolder på disk, en Deck-rad, ett Spreed-rum, ett CalDAV-objekt, en systemtag. **Ingen `IDBConnection`-transaktion kan rulla tillbaka ett Spreed-rum eller en skapad mapp.** Därför är "skapa ärende" en **distribuerad saga**: varje steg har en **kompenserande motåtgärd**, och misslyckas steg *n* körs kompensering för *n-1…1* i omvänd ordning. Det är den exakta innebörden av GAP-057.

#### 1.2.3 Sagan, steg för steg (med kompensering)

| # | Forward-steg (vad sdkmc gör) | Mekanism / API | Kompensering vid fel |
|---|---|---|---|
| 1 | **Mint `hubsCaseId`** (UUID v4) | i minnet | (ingen — ingen sidoeffekt än) |
| 2 | **INSERT `hubs_arenden`-rad** status=`otilldelat`, steg=`inflode`, provenanceState=`ej_registrerad` `[BYGGS]` | Tables OCS rows-API | DELETE raden |
| 3 | **Skapa systemtag** `case:{hubsCaseId}` (restricted/invisible) → `caseTagId` | native `ISystemTagManager` (samma som `TagFileController`) | radera taggen |
| 4 | **Skapa groupfolder** (ärenderum) + ACL least-permission + BBIC-struktur → `groupfolderId`; lägg Automated-Tagging-regel mapp→`case:` | groupfolders-API + Flow-regelgenerering | ta bort groupfolder + ACL + regel |
| 5 | **Skapa Deck-kort** (2-stegs: POST kort → PUT label `case:{id}`/due) → `{deckBoardId, deckCardId}` | Deck-API | DELETE kortet |
| 6 | **Skapa Spreed-rum** (deltagare = ACL-krets) → `talkToken` | spreed-itsl room-API | radera/arkivera rummet |
| 7 | **Förbered kalender** (CATEGORIES=`hubsCaseId`) → `calendarObjUri` | CalDAV | ta bort objektet |
| 8 | **Starta 14-dgr-frist** bunden till **inkom-datum** (`conversationId`), inte `now()` (GAP-002) | registret/BackgroundJob | nollställ fristfältet |
| 9 | **Tagga utlösande meddelande(n)** `case:{id}`, append `conversationId` till `conversationIds[]`, flytta "Att ta emot"→ärendekort | **`ItslTagService->tagMessage()`** `[FINNS]` | `ItslTagService->untagMessage()` |
| 10 | **UPDATE registret** med alla pekare från steg 3–7 (commit-punkt: raden blir "komplett") | Tables | (om detta faller, kompensera 9→3) |

**dnr-parningen är INTE en del av denna saga.** Den sker **senare och asynkront**: vid `CommitGrind`-"För över" anropas `Frends.commitToTreserva({hubsCaseId, payload})`, och **först vid verifierad callback** `{hubsCaseId, dnr}` skriver sdkmc `dnr` + `provenanceState='registrerad'` + kompletterar systemtaggen med en dnr-alias + speglar `fristDue` + sätter `retentionState='gallras_efter_commit'` (§3.2-D). Medvetet frikopplat: ärendet *lever och arbetas* under ett `hubsCaseId` långt innan dnr existerar.

#### 1.2.4 Föreslagen NY klass och exponering `[BYGGS]`

**Ägarklass:** `lib/Service/ArendeService.php` i sdkmc.
- **Ansvar:** single writer mot `hubs_arenden`; kör sagan i 1.2.3; äger kompenseringslogiken; håller en idempotensnyckel (t.ex. utlösande `conversationId`) så att dubbelklick inte skapar två ärenden.
- **Stödklasser:** `lib/Db/Arende.php` (Entity), `lib/Db/ArendeMapper.php` (Mapper, single point of write), `lib/Migration/Version02xxxx…CreateArendeTable.php`. (Om registret bor i `tables` snarare än egen sdkmc-tabell går skrivningen via Tables OCS rows-API i stället för egen Mapper — designvalet är öppet, single-writer-disciplinen densamma.)
- **Exponering (OCS-route, `/api/v2/`-konvention):** `POST /api/v2/arende` (skapa), `GET /api/v2/arende/{hubsCaseId|dnr}` (öppna), `GET /api/v2/arende-summary` (dashboard-aggregat), `POST /api/v2/arende/{ref}/tilldela` (fördelning), `POST /api/v2/treserva/commit`. Registreras i `appinfo/routes.php` bredvid de befintliga `itsl_tag#*`-routerna (rad 82–91); controllern blir `lib/Controller/ArendeController.php`.

### 1.3 HÅLLA IHOP — `case:{hubsCaseId}` propagerad + reconciliation

#### 1.3.1 Två bärartyper för samma token

| Objekttyp | Bärare | Mekanism | Status |
|---|---|---|---|
| **Meddelande / kvittens** | `case:{id}` som **imap_label** i `sdkmc_itsl_tag` + rad i `sdkmc_itsl_message_tag` | `ItslTagService->tagMessage()` sätter IMAP-flagga på servern OCH speglar i DB | `[FINNS]` |
| **Fil** | `case:{id}` som **native systemtag** på file-id | `TagFileController` → `ISystemTagManager`/`ISystemTagObjectMapper`; löpande via Flow Automated Tagging | `[FINNS]` (taggvägen) |
| **Signeringsbegäran** | `case:{id}` på dokumentet + metadata på begäran | libresign + tag | `[BYGGS]` |
| **Deck-kort** | **register-pekare** `{deckBoardId, deckCardId}` + Deck-label `case:{id}` | kort kan **inte** fil-taggas → pekare i registret | `[BYGGS]` |
| **Talk-rum** | **register-pekare** `talkToken` | Talk saknar native objektbindning till dnr | `[BYGGS]` (`[FORK]` för inbäddad `objectType='hubs_case'`) |
| **Kalender** | `hubsCaseId` i `CATEGORIES`/`X-HUBS-CASE` | CalDAV-property | `[BYGGS]` |

**Nyckelinsikt om taggmotorn som FINNS:** `ItslTagService` är medvetet **email-scopad, inte user-scopad** (`ItslTag extends Mail\Tag` men byter `userId` mot `emailAddress`, `lib/Db/ItslTag.php:31`). En `case:`-tagg satt på en **funktionsadress/gruppkorg** delas därför av *alla* handläggare som har korgen — exakt vad ett delat ärende kräver. `tagMessage()` gör dubbelskrivningen rätt: sätter IMAP-flaggan på servern (via `Horde_Imap_Client->store`, gated på `isPermflagsEnabled`) **och** speglar i `sdkmc_itsl_message_tag`, och dispatchar `MessageFlaggedEvent` så mail-appens DB hålls i synk. Trådsammanslagning (`getTagsForMessagesByMailboxId`) gör att en `case:`-tagg på *ett* meddelande syns på *hela* tråden.

#### 1.3.2 Flow (deklarativt) vs programmatiskt (kod)

**Vad Flow gör — det deklarativa lagret (§3.1):** auto-tagga **filer** i ärenderum (Files Automated Tagging); sätt retention-tagg vid `state:committed`; File Access Control på `sekretess:hög`; notifiera vid filhändelse; posta systemmeddelande i Talk-rummet. Custom `IEntity`-brygga: sdkmc *kan* exponera `MessageReceivedEvent`/`CaseCommittedEvent` som Flow-triggers.

**Vad koden FINNS av Flow-lagret `[FINNS]`:** `RegisterOperationsListener` och `RegisterChecksListener` är registrerade i `AppInfo/Application.php` (rad 126–133) mot `RegisterOperationsEvent`/`RegisterChecksEvent`. Den verifierade operationen idag är dock **smal**: `RegisterOperationsListener->handle()` laddar bara `loa3`-scriptet (`Check/Loa3.php`). Det finns alltså en registrerad krok in i workflowengine, men **ingen färdig "tagga fil med case:{id}"-operation som kod** — den konfigureras deklarativt i `/settings/admin/workflow` per kund `[KONFIG]`, eller genereras av `ArendeService` i sagans steg 4.

**Vad som görs programmatiskt (kod-ägt):** all meddelandetaggning (`ItslTagService`), hela skapande-sagan (`ArendeService` `[BYGGS]`), fil-/bilageroutning till rätt rum, Frends-commit, fördelnings-ACL-omskrivning. **Tumregel: Flow är fil-/tagg-centrisk och deklarativ; allt som rör icke-fil-objekt eller flerobjekts-orkestrering är programmatiskt.**

#### 1.3.3 Reconciliation register↔objekt (GAP-056) `[BYGGS]`

Eftersom token bärs på **två ställen** (registrets pekare *och* objektets tagg) kan de driva isär. **GAP-056 = en reconciliation-loop** som periodiskt verifierar att:
1. varje `groupfolderId`/`deckCardId`/`talkToken`/`caseTagId` i registret **fortfarande existerar** (annars: larma/självläk),
2. varje objekt taggat `case:X` har en **motsvarande registerrad** (annars: föräldralöst objekt),
3. `conversationIds[]` matchar de meddelanden som faktiskt bär `case:X` i `sdkmc_itsl_message_tag`.

**Föreslagen placering:** `lib/BackgroundJob/ArendeReconciliationJob.php` + metoder på `ArendeService`. Mönstret finns att kopiera: `ItslTagService->processAllPendingDeletions()` driven av `DeleteTagsJob` är exakt en sådan "städa drift mellan DB och IMAP"-loop, och `BackgroundJobService->executeNow()` är den befintliga triggern.

### 1.4 KODKARTA — funktion → klass/fil

#### 1.4.1 Det som FINNS (verifierat, körande kod)

| Funktion | Klass / fil | Status |
|---|---|---|
| Meddelandetaggning (sätt/ta bort `case:`-tagg, IMAP + DB-spegling) | `lib/Service/ItslTagService.php` | `[FINNS]` |
| Tag-katalog (email-scopad), soft-delete, assignment-taggar | `lib/Db/ItslTag.php` (+ `ItslTagMapper`) | `[FINNS]` |
| Objekt↔tagg-relation för meddelanden | `lib/Db/ItslMessageTag.php` (+ `ItslMessageTagMapper`) | `[FINNS]` |
| OCS-routes för meddelandetaggning | `lib/Controller/ItslTagController.php` (`routes.php:82–91`) | `[FINNS]` |
| Tagg-sökning (thread-AND, email-scope, `none`=assignment) | `lib/Service/TagSearchHelper.php` | `[FINNS]` |
| **Fil**taggning via native systemtags | `lib/Controller/TagFileController.php` (`ISystemTagManager`) | `[FINNS]` |
| Tabellschema för tag-motorn | `lib/Migration/Version020008…0009…` | `[FINNS]` |
| Deterministisk kanal-/typklassning | `lib/Service/MessageTypeService.php` | `[FINNS]` |
| "Viktig"-klassning | `lib/Listener/MessageImportantClassifiedListener.php` + `lib/Event/MessageImportantClassifiedEvent.php` | `[FINNS]` |
| Trådankare (conversationId-grund) | `lib/Db/MessageThread.php` (+Mapper) | `[FINNS]` |
| Kvittenser | `lib/Db/MessageReceipt.php` (+Mapper) + `lib/Controller/MessageReceiptController.php` | `[FINNS]` |
| Retention/gallring | `Service/MailboxRetentionService.php`, `ExpungeService.php`, `BackgroundJob/ExpungeJob.php`, `DeleteTagsJob.php`, `Db/MailboxRetention.php` | `[FINNS]` |
| Flow-registrering (operations/checks) | `lib/Listener/RegisterOperationsListener.php`, `RegisterChecksListener.php`, `Check/Loa3.php` (reg. i `AppInfo/Application.php:126–133`) | `[FINNS]` (smal: laddar loa3) |
| DIGG/SDK-katalogsynk | `lib/Service/UpdateAddressBookService.php` + `BackgroundJob/UpdateAddressBookBackgroundJob.php` | `[FINNS]` |
| Korgar/funktionsadresser | `Service/ConsolidateMailboxesService.php`, `ProvisionPersonligAccountsService.php`, `Activity/{Personlig,Grupp,Sdk,Fax,Sms}Setting.php` | `[FINNS]` |
| Tilldelnings-tagg-avisering | `ItslTagService->publishAssignmentTagActivity()` + `Activity/AssignmentTagSetting.php` | `[FINNS]` |
| Talk-koppling (authorize/callback/guest) | `lib/Controller/TalkController.php` | `[FINNS]` |

#### 1.4.2 Det som måste BYGGAS

| Funktion | Föreslagen klass / fil | Status |
|---|---|---|
| Ärenderegistret (rad-shape §1.1.2) | `tables`-tabell `hubs_arenden` **eller** `lib/Db/Arende.php` + `ArendeMapper.php` + migration | `[BYGGS]` |
| Single writer + UUID-mint | `lib/Service/ArendeService.php` | `[BYGGS]` |
| "Skapa ärende"-saga + kompensering (GAP-057) | `ArendeService::createCase()` (steg 1.2.3) | `[BYGGS]` |
| OCS-exponering | `lib/Controller/ArendeController.php` + routes `/api/v2/arende*` | `[BYGGS]` |
| Matchningsmotor (inkommande → ärende) | `lib/Service/ArendeMatchService.php` (lyssnar på `MessageReceivedEvent`) | `[BYGGS]` |
| Frends-commit + dnr/frist-spegling + provenans-flip | `lib/Service/TreservaCommitService.php` + Frends-konnektor | `[BYGGS]` |
| Fördelning (assignee + ACL-omskrivning) | `ArendeService::tilldela()` | `[BYGGS]` |
| Reconciliation register↔objekt (GAP-056) | `lib/BackgroundJob/ArendeReconciliationJob.php` | `[BYGGS]` |
| Deck/Spreed/kalender-skapande | klienter anropade *från* sagan (`DeckClient`, `SpreedClient`, CalDAV) | `[BYGGS]` |
| Talk inbäddad objektbindning `objectType='hubs_case'` | spreed-itsl-fork | `[FORK]` |

---

## 2. Kategoriserings-/grupperingsmotorn (steg 1)

### 2.1 De tre ortogonala axlarna

Grupperingen i första steget är **inte** "per typ". Den är **per tre oberoende axlar** (§5.1: *"Triagen klassar varje rad längs tre ortogonala axlar, och Axel C avgör zon."*). Att blanda ihop dem ger "13 inkorgar"; att hålla isär dem ger en ärende-först-vy.

#### Axel A — KORG (härkomst/behörighet) → *filter och etikett, aldrig sortering*
En korg är en behörighetsstyrd ström av inkommande sdkmc-objekt (personlig brevlåda, gruppkorg/funktionsadress, fax, SDK) — **inte en mapp**. Provisioneras/konsolideras av `ProvisionPersonligAccountsService.php` och `ConsolidateMailboxesService.php`; `Activity/{Personlig,Grupp,Sdk,Fax,Sms}Setting.php` registrerar korg-typerna. `[FINNS]` I UI:t blir detta `KorgValjare.vue`-pillren, server-filtrerade till behöriga korgar (OSL-gränsen, `IConditionalWidget`). Korg är **filter + etikett**, aldrig primär sortering.

#### Axel B — TYP (`messageType`) → *DETERMINISTISKT, styr åtgärdsknappar*
Motorns kärna idag — **finns och är helt deterministisk, ingen LLM**. `lib/Service/MessageTypeService.php` klassar på två rena regelsätt:

**(i) Adress-suffix-mappning** — `getMessageTypeFromEmail($fromEmail, $toEmail)` mappar avsändarens TLD/suffix mot en fast tabell (verbatim ur koden, rad 70–77):
```php
$map = [
    'sdk'        => 'sdk_message',
    'fax'        => 'fax_message',
    'sms'        => 'sms_message',
    'personlig'  => 'internal_message',
    'gruppbox'   => 'internal_message',
    'securemail' => 'secure_email',
];
```
`getTldFromEmail()` plockar suffixet ur `from`-adressen. Okänt suffix → faller tillbaka till `'personlig'` (= `internal_message`). Är resultatet `internal_message` *och* en `to`-adress finns, klassas på `to`-adressens suffix (rad 84–90) — så att internpost till funktionsadress får funktionsadressens typ. Saknas mappning där kastas `Exception('Message type does not exist')` (rad 87) — **fail-closed, ingen gissning**.

**(ii) IMAP-header-läsning** — `enhanceMessages()` (rad 32–67) berikar varje meddelande via `fetchHeader()`: `X-Sdk` (JSON-payload), `X-MessageType` (explicit typ), `Received` (inkom-tid/provenans), `X-NoReply` (normaliseras `'0'`/`'1'`). Resultatet hängs på `$result['itsl']` och `SerializeMailMessageEvent` dispatchas (rad 59).

> **Glapp kod↔UI-spec (löst):** koden producerar **fem** kanaltyper (`sdk_message`, `fax_message`, `sms_message`, `internal_message`, `secure_email`). UI-specen talar om **åtta** verksamhetstyper (`orosanmalan, komplettering, fraga, remiss, internpost, fax, sdk_myndighet, skrap`). De åtta är en *finare verksamhets-/avsiktsklassning ovanpå* de fem kanaltyperna och **finns inte i koden** — det är BYGG-arbete (innehålls-/avsiktsklassning, inte bara kanal). `[BYGGS]`

#### Axel C — ÄRENDEKOPPLING (`nytt | hör_till | ej_kopplat`) → *avgör BAND*
Axeln som routar till zon. I `MinaArenden.vue`s `zonOf()`-selector:
```js
if (item.arendekoppling === 'hor_till')  return 'attHantera'  // Band 1b
if (item.arendekoppling === 'ej_kopplat') return 'ejKopplad'  // Band 1c
return 'attTaEmot'                                            // 'nytt' → Band 1a
```

| `arendekoppling` | Band | Kognitiv uppgift |
|---|---|---|
| `nytt` | **1a "Att ta emot"** (`AttTaEmotSektion`, oförändrad) | ärende*beslut* |
| `hor_till` | **1b "Att hantera (mina korgar)"** (`AttHanteraSektion`, ny) | ärende*arbete* |
| `ej_kopplat` | **1c "Ej ärendekopplat"** (`EjKoppladSektion`, ny) | registrering/gallring |

**Detta är axeln motorn saknar idag.** Korg och typ finns; ärendekopplingen bär hela bandindelningen och måste byggas.

#### Undergruppering per ärende (`barnRef`) i "Att hantera"
Inom band 1b sker en **andra grupperingsnivå**: default `gruppering: 'arende'` (`AttHanteraSektion.vue`), så att allt som hör till samma barn syns ihop **oavsett korg**. Varje ärendegrupp ärver frist + ärendechip; en `KopplingBadge` ("Kopplad till Barn 2026-0142") sätts per rad. Alternativ batch-gruppering per korg/typ finns för massåtgärder. Nyckeln är `koppling.barnRef` (pseudonym), aldrig rå `hubsCaseId`.

### 2.2 Vem/vad gör kategoriseringen — och är den automatisk?

**Kort svar:** första halvan (kanal + typ) är **automatisk, FINNS, deterministisk**. Andra halvan (ärendekoppling) **finns inte och måste byggas**.

**FINNS och automatiskt idag:**
- `MessageTypeService` klassar kanal/typ vid inflöde, deterministiskt, utan LLM (Axel A+B).
- `MessageImportantClassifiedListener` + `…Event` är ett separat, existerande auto-klassningsspår: när mail-sidans `NewMessagesClassifier` flaggar "viktigt" dispatchas eventet (bär `Account`, `Mailbox`, `Message`, `Tag`), och listenern (rad 34–65) taggar via `ItslTagService->tagMessage(...)`. Listenern använder `getMessageId()` (IMAP-strängen), **inte** `getId()` (DB-int) — kommentar rad 44. Detta är en **binär "viktig"-flagga**, inte ärendematchning — men visar att mönstret "event → listener → tagg i sdkmc server-side" redan är etablerat och är exakt mallen ärendematchningen ska följa.
- `TagSearchHelper::processAndClearTags()` gör korrekt trådbred, konto-skopad AND-sökning på `imap_label` mot `sdkmc_itsl_message_tag ⨝ sdkmc_itsl_tag`, med specialfallet `'none'` = "inga tilldelningstaggar". Detta är **läs-/sökvägen** för en redan satt koppling.

**INTE byggt (måste byggas):** att matcha en inkommande rad mot `hubs_arenden` och sätta `arendekoppling`. Verifierat: `hubs_arenden` ger 0 grep-träffar; ingen `ArendeMatchService`/`*match*`-klass finns; `MessageThread` bär trådnings-ankaret (`conversationId`, `inReplyTo`, `isNewConversation = is_null(sdkInReplyTo)`) men ingen kod kopplar `conversationId` till ett ärende, eftersom registret att slå upp mot inte finns.

### 2.3 LLM eller fasta regler? — ställningstagande

**DETERMINISTISK MATCHNING FÖRST, med en konfidenspoäng. LLM endast som valfritt, människo-bekräftat förslagslager — aldrig skarpt/autonomt på sekretessbelagt innehåll.** Detta är symmetriskt med att kanal/typ redan är deterministiskt, och *enforced* av gap-analysen.

**Den deterministiska matchningskaskaden (fallande styrka):**
1. **Explicit case-tagg / dnr i meddelandet** — `case:{hubsCaseId}` redan satt (via `ItslMessageTag`), eller dnr i ämne/ärendemening. *Starkast, exakt match.*
2. **Trådning / `conversationId`** — slå upp meddelandets `conversationId` (ur `MessageThread`, finns) i `hubs_arenden.conversationIds[]`. Träff = befintligt ärende (§3.2-A steg 1).
3. **Avsändar-SSN / orgId mot register** — org-cert/LOA (SITHS/LOA3) + funktionsadress matchat mot ärendets parter. Svagare, heuristisk.

Utfall mot **server-side tröskel:** ≥ tröskel → `hor_till`, auto-kopplad (men bilaga speglas *inte* automatiskt); under tröskel men träff → `foreslagen` (människa bekräftar); ingen träff → `ej_kopplat`/`nytt` (det legitima otaggade tillståndet — §3.2-A steg 4: *"bättre obesvarat än feltaggat; felkoppling är en sekretessincident"*).

**LLM:s roll — strikt avgränsad (GAP-052 / GAP-060):**
- **GAP-052** (blocker): *"Skarp drift på sekretessbelagt klientsamtal transkriberat med AI = röd zon … dokumentera, kör inte skarpt än."* — tills IMY/SKR/Socialstyrelsen gett vägledning. LLM får aldrig köra autonomt skarpt på sekretessbelagt innehåll.
- **GAP-060** (major): *"Auto-kopplings-konfidens som tyst sekretessflytt … Tröskeln (≥0.9 → kopplad) är klient-/demo-logik, inte en granskad server-policy."* Åtgärd: server-side konfidenströskel + obligatorisk människo-bekräftelse över tröskel; bilaga speglas först vid bekräftad koppling; logga avvisade förslag.

Tre hårda regler för LLM-lagret: (a) **människo-bekräftat** alltid när det rör vart sekretess hamnar; (b) **bilagan speglas vid bekräftelse, inte vid förslag** (annars felkopplad sekretess i fel akt, GAP-043); (c) **avvisade förslag loggas** (`activity`).

**Var matchningen exekveras / kodplacering `[BYGGS]`:** server-side i sdkmc vid `MessageReceivedEvent` (samma event-driven mall som `MessageImportantClassifiedListener`). Ingen klient-fan-out (CONTRACTS regel 6: *"allt ur server-side-aggregat"*). Klienten tar emot ett färdigt `arendekoppling`/`koppling`-fält via `fetchInflodeSummary()`/`/api/v2/inflode-summary`. Ny kod: `lib/Service/ArendeMatchService.php` (kaskaden + konfidens), `lib/Db/Arende(.php/Mapper)` + Tables-tabellen, en `MessageReceivedListener` som anropar matcharen och vid träff ≥tröskel anropar **befintliga** `ItslTagService->tagMessage()`, samt OCS-routen `/api/v2/inflode-summary`.

### 2.4 Beslutslogik-tabell: signal → konfidens → utfall

| # | Signal (deterministisk, fallande styrka) | Konfidens | `arendekoppling` | Utfall / band | Bilaga speglas? |
|---|---|---|---|---|---|
| 1 | **Explicit `case:{hubsCaseId}`-tagg** redan på meddelandet (`ItslMessageTag`) | Exakt (1.0) | `hor_till` | Auto-kopplad → band 1b, ärendechip förkryssat | Ja (redan kopplat) |
| 2 | **dnr i ämne/ärendemening** matchar `hubs_arenden.dnr` | Mycket hög | `hor_till` | Auto-kopplad → band 1b | Vid bekräftelse |
| 3 | **`conversationId`-träff** i `conversationIds[]` (via `MessageThread`) | Hög, **≥ tröskel** | `hor_till` | Auto-kopplad → band 1b | Vid bekräftelse |
| 4 | **Avsändar-SSN/orgId + funktionsadress** matchar ärendepart | Medel, **< tröskel** | `hor_till` (svag) | `foreslagen` → `KopplingBadge` "bekräfta?" | Först vid bekräftad koppling |
| 5 | **LLM-förslag** (`llm2`, avstängbart) ovanpå svag/ingen signal | Låg, **alltid < tröskel** | `foreslagen` (om åtkomst), annars "eskalera till gruppledare" | **Aldrig auto.** Människo-bekräftat. GAP-052/060. Avvisat loggas | Aldrig vid förslag |
| 6 | **Ingen träff**, ny avsändare/ärende | Noll | `nytt` | Band 1a "Att ta emot" | — |
| 7 | **Ingen träff**, löst inflöde | Noll | `ej_kopplat` | Band 1c "Ej ärendekopplat" | — |

**Tröskelprincip:** tröskeln är **server-side policy** (granskad, per-kund via Windmill, §3.3), inte klientlogik. Demovärden idag (`≥0.9 → kopplad`, `0.62 → föreslagen`) är just demo och pekas ut av GAP-060. Vid felklassning är default-säkerheten **`ej_kopplat`** (fail-open mot människa), aldrig en tyst auto-koppling — felkoppling är en sekretessincident, inte ett UX-fel.

---

## 3. Alla ärendefaser — per-fas internals

**STEG-listan** (`demo/socialsekreterare.js`): `['inflode','forhandsbedomning','utredning','beslut','uppfoljning','avslutat']`. Faserna nedan vecklar ut den listan.

**Statuslegend:** ✅ **Finns idag** (verifierad kod) · ⚙️ **Måste konfigureras** (befintlig app, deklarativ) · 🔨 **Måste byggas** (kod/route/orkestrering saknas) · 🧩 **Antas löst** (blockerare V2 antar löst: Frends/Treserva, Inera, KB-Whisper, retention-paus).

**Tre genomgående regler:** (a) ALL chatt = Spreed-rummet (`talkToken`-pekare), aldrig i dashboarden; (b) `hubs_start` lagrar **inget** — visar tal/frist-färg, skickar kommandon; (c) gruppledaren ser **all** ärendeinfo i fas 4 för att fördela, men i belastningspanelen bara **tal + frist-färg, aldrig innehåll** (OSL 26 kap.).

### Fas 0 — Inflöde / mottagning
Ett meddelande inkommer (SDK/AS4, fax, securemail, internpost) till en **korg**. sdkmc fångar provenans (kanal · avsändar-LOA · tidsstämpel · korg · `conversationId`) och klassar kanal/typ deterministiskt — **ingen LLM**. Raden landar otaggad i band **1a** eller **1c**. **Ingen `hubsCaseId` ännu** — bara `conversationId` (provenans-ankaret).

| Aktiva Hubs-delar | Lagring | Exekverad kod | Status |
|---|---|---|---|
| sdkmc meddelandemottagning; korg-modell; kanal-/typklassning | Meddelandet i sdkmc/mail-lagret (IMAP per korg); `conversationId` | `Service/MessageTypeService.php`→`getMessageTypeFromEmail()`; `Db/MessageThread`; `Service/ConsolidateMailboxesService.php` + `ProvisionPersonligAccountsService.php` | ✅ klassning/trådning/korgar. 🔨 `MessageReceivedEvent`-brygga + OCS `/api/v2/inflode-summary` |
| `hubs_start`-vyn: `MinaArenden.vue`→`KorgValjare`, band 1a/1c | **Inget i `hubs_start`** (regel b) | `lib/Controller/PageController.php` (`boot`); frontend `fetchInflodeSummary()` (prod axios, idag DEMO-stubb) | ✅ UI byggt & LIVE. 🔨 prod-OCS bakom DEMO-short-circuit |
| `conversationId`→ärende-uppslag | Uppslag mot `hubs_arenden.conversationIds[]` | §3.2-A | 🔨 matchningsmotorn; `hubs_arenden` finns ej |
| Provenans-/mottag-logg | `activity` | NC `activity` | ✅ app aktiv ⚙️ koppla händelser |

### Fas 1 — Kategorisering & triage
Triagen klassar varje rad längs **tre ortogonala axlar** (se §2). Matchningskonfidens avgör auto-koppling (hög) / `foreslagen` (låg) / `nytt`/`ej_kopplat` (ingen). **System-stödd men människo-bekräftad** — bilagan speglas **vid bekräftelse, inte vid förslag**.

| Aktiva Hubs-delar | Lagring | Exekverad kod | Status |
|---|---|---|---|
| Deterministisk typklassning (Axel B); "viktig"-flagga | sdkmc-metadata; `ItslMessageTag` vid koppling | `Service/MessageTypeService.php`; `Listener/MessageImportantClassifiedListener.php` + `Event/…` | ✅ typ + viktig-flagga. LLM-förslag (`llm2`) finns ej/avstängbart |
| Tagg-motorn (Axel C) — `case:{id}` på meddelanden | `ItslTag` + `ItslMessageTag` (sdkmc-DB); systemtag för filer | `Service/ItslTagService.php`; `Controller/ItslTagController.php`; `Service/TagSearchHelper.php`; `Controller/TagFileController.php` | ✅ tagg-motorn finns. 🔨 matchningsregeln registret→tagg |
| `KorgValjare` + tre band; `KopplingBadge` | **Inget i `hubs_start`**; kopplingsstatus i `hubs_arenden` | `MinaArenden.vue` (sektioner); `IConditionalWidget` (server-side filter) | ✅ UI byggt & LIVE. 🔨 server-side konfidenströskel + bekräftelse (GAP-060) |
| Auto-koppling med konfidens; spegla bilaga vid bekräftelse | `hubs_arenden.conversationIds[]`; fil till ärenderum vid bekräftad koppling | §5.1 + §3.2-A/C | 🔨 tröskeln (≥0.9) är idag klient-/demo-logik (GAP-060) |

### Fas 2 — Förhandsbedömning (skyddsbedömning, 14 dgr)
Anmälan plockas och **ärendeidentiteten föds** (skapa-sagan, §1.2). Den **omedelbara skyddsbedömningen** (11 kap. 1 a § SoL) är en **pliktmarkör** (`plikt.typ:'skyddsbedomning'`, `kvitterad:false`) som **blockerar stepper-flytt** tills den **committas**. Den **14-dgrs-klockan** binds till **inkom-datum** (`conversationId`), inte plocktidpunkt. Medbedömning sker i **Spreed-rummet** (regel a).

| Aktiva Hubs-delar | Lagring | Exekverad kod | Status |
|---|---|---|---|
| Ärenderegistret föds (`steg:'forhandsbedomning'`, `fristDue`, `plikt`) | **`hubs_arenden` (Tables)** — sdkmc ensam skrivare | §1.2 + §3.2-B; `skapaArende(rad)`→`POST .../arende` | 🔨 `hubs_arenden` finns ej; idag in-memory `REGISTER` (`demo/treserva.js`). Blocker GAP-019/056 |
| Ärenderum (Groupfolder) + ACL + struktur | `groupfolders`/`files` | sdkmc ett-klicks-orkestrering (§3.2-B); Automated Tagging mapp→`case:` | ✅ apparna finns. 🔨 orkestrering (GAP-010); ⚙️ Flow-regeln |
| Skyddsbedömnings-plikt (fas-spärr) + 14-dgr-klocka | Plikt + `fristDue` i `hubs_arenden`; klockan ur `conversationId` | `zonOf()` håller kortet i "Kräver åtgärd nu"; `frist().start`=inkom-datum | 🔨 `frist.start` sätts för hand i demodata; ingen tvingande commit-regel (GAP-001/002) 🧩 |
| Medbedömnings-chatt = **Spreed-rummet** (`talkToken`) | `spreed-itsl`-rum; inget i `hubs_start` (regel a) | `Controller/TalkController.php`; registrets `talkToken`-pekare; `ArendeDiskussion.vue` (trådvy) | ✅ Spreed/`TalkController` finns. 🔨 rum-skapande + `talkToken`-pekare |
| `FristChip`/`ProvenansChip` (gul, "→ Treserva — ej registrerad") | Inget i `hubs_start`; state ur `hubs_arenden` | `MinaArenden.vue`/`ArendeKort.vue` | ✅ UI byggt & LIVE |

### Fas 3 — Beslut inleda / inte inleda
Beslutet "inleda utredning" (11 kap. 1 § SoL, inom 14 dgr) går genom **`CommitGrind`** → aktualiseras i **Treserva via Frends**. På **verifierad callback** `{hubsCaseId, dnr}` flippar `provenanceState` `ej_registrerad → registrerad`, dnr paras 1:1, steppern flyttar `forhandsbedomning → utredning`, Deck-kortet flyttas till stack *Utredning*, och 4-mån-klockan startar (speglad ur Treserva). AI/system fattar aldrig beslutet (GDPR art. 22).

| Aktiva Hubs-delar | Lagring | Exekverad kod | Status |
|---|---|---|---|
| `CommitGrind` → commit; provenans-flip; dnr-parning | Beslut → **Treserva**; `dnr`/`provenanceState`/`fristDue` i `hubs_arenden` | §3.2-D `Frends.commitToTreserva(...)`; `commitToTreserva()`→`POST .../treserva/commit` | 🧩/🔨 `commitToTreserva()` hårdkodad stubb (`{ok:true, dnr:'…NEW'}`); ingen Frends-adapter (GAP-019, tyngst) |
| Stepper-flytt + Deck-kort→stack *Utredning* | Deck-kort; `deckCardId`/`steg` i `hubs_arenden` | Deck-API (POST kort→PUT label/due); `ProcessStepper` ⇄ stacks (§4) | ✅ Deck finns. 🔨 programmatisk kort-flytt |
| 4-mån-utredningsfrist (speglad) | `fristDue` i `hubs_arenden` (ej självständigt räknad) | §1.2; FristChip | 🔨 läskonnektor saknas; demon räknar `daysLeft` lokalt (GAP-018) 🧩 |
| Aktualiserings-/beslutslogg; retention-flagga på verifierad callback | `activity`; `retentionState='gallras_efter_commit'` | `files_retention`; §1.3 GAP-007 | ✅ `files_retention`/`activity` finns. 🔨 binda till **verifierad** callback (GAP-007, blocker) |
| Deklarativt: FLOW-operationer/checks (LOA3) | workflow_engine | `Listener/RegisterOperationsListener.php` + `RegisterChecksListener.php` + `Check/Loa3.php` | ✅ **Finns** (deklarativa lagret registreras) |

### Fas 4 — Tilldelning / fördelning (gruppledare)
Mellan beslut-inleda och fördelad är ärendet **`otilldelat`** (egen behörighetsstyrd kö). Gruppledaren växlar till **fördelningsläget** (`lage: utredning → fordelning`, bakom `IConditionalWidget` = OSL-gränsen). **Regel (c):** gruppledaren ser **all** ärendeinfo i Zon A "Att fördela", men i Zon B "Utredarnas belastning" bara **tal + frist-färg, ALDRIG barn/innehåll** (OSL 26 kap.). Fördelning = **atomär ACL-omskrivning** (mottagning revoke → utredare write) + assignee + Deck + flytt + notis. **Fristen flyttas inte.**

| Aktiva Hubs-delar | Lagring | Exekverad kod | Status |
|---|---|---|---|
| `FordelningsVy` (Zon A all info, Zon B tal+färg); `FordelaTill` | Inget i `hubs_start`; `fetchFordelningSummary()` server-aggregat | `MinaArenden.vue` `lageVaxel`; `fetchFordelningSummary()`→`.../fordelning-summary` | ✅ UI byggt & LIVE. 🔨 prod-OCS-route |
| Atomär fördelning: assignee + ACL + Deck + flytt + notis | `agareUid`/`status` i `hubs_arenden`; ACL på rummets Groupfolder; Deck `assigned:NN` | §5.3 `@fordela(...)`→`POST .../arende/{ref}/tilldela` | 🔨 idag optimistisk store-stubb; ingen lås/idempotens på `hubsCaseId` → sekretessfönster (GAP-057, blocker) |
| ACL som sekretessgräns (least permission) | `groupfolders` Advanced ACL | Groupfolder-ACL koherent med `case:`-tagg + Tables-vy | ✅ `groupfolders` finns. 🔨 tre-lagers-ACL-koherens (GAP-058, blocker) |
| Belastningstal (aktiva/röda/nära-tak) — aldrig innehåll | Aggregat ur Deck `assignee` + `hubs_arenden` | `fetchFordelningSummary().utredare` (server-side) | ✅ UI byggt. 🔨 server-aggregat-route |
| `TilldelningBand` (24h-markör); tilldelningstaggar | Arbetsmetadata; `activity` | `Activity/AssignmentTagSetting.php` | ✅ `AssignmentTagSetting` finns. 🔨 24h-avklingning + riv av avträdande läs-ACL (GAP-057) |

### Fas 5 — Utredning (ärenderum, BBIC, kommunikation, möten)
Utredaren öppnar **ärenderummet** (Groupfolder), instansierar **BBIC-mallar** ur `collectives`, samredigerar via **Collabora (richdocuments/WOPI)** med `files_versions`. Inkommande säkra filer kopplas via `KopplingBadge` och **speglas vid bekräftelse**. Säkra meddelanden via securemail/sdkmc där **Kontakter/favoriter** (pekare, resolvad färskt ur DIGG) styr mottagarval; säkert möte via Spreed-fork (BankID-lobby → KB-Whisper → AI-utkast → human-in-the-loop → commit). **All ärendechatt = Spreed-rummet** (regel a).

| Aktiva Hubs-delar | Lagring | Exekverad kod | Status |
|---|---|---|---|
| Ärenderum + BBIC + Collabora-samredigering | `groupfolders`/`files`; `files_versions`; `richdocuments` | Mallar ur `collectives`; Automated Tagging mapp→`case:` | ✅ apparna finns. ⚙️ BBIC-mall (GAP-011); 🔨 var texten skrivs ej beslutat (GAP-012) |
| Säker kommunikation; **favorit-resolver** mot DIGG (pekare) | Meddelande i securemail/sdkmc (`case:{id}`); favorit-vCard i Kontakter (favoriten taggas ALDRIG `hubsCaseId`) | `Service/UpdateAddressBookService.php` + `BackgroundJob/UpdateAddressBookBackgroundJob.php`; `GET .../favoriter`; `FavoritValjare.vue` | ✅ DIGG-synk + Kontakter finns. 🔨 tunt resolverlager + fail-closed (GAP-061) |
| Tagga inkommande bilaga + routa till rätt rum | Fil till Groupfolder (WebDAV); `case:{id}`-tag | `Service/ItslTagService.php`; §3.2-C | ✅ tagg-motor finns. 🔨 fil-/bilageroutning |
| **All ärendechatt = Spreed-rummet** (regel a); omnämnande-puls | `spreed-itsl`-rum; inget i `hubs_start` | `Controller/TalkController.php`; `talkToken`; `ArendeDiskussion.vue` | ✅ Spreed/`TalkController` finns. 🔨 native objektbindning till dnr saknas; chatt-retention (GAP-059) |
| Säkert möte + BankID-lobby + lokal transkribering + AI-utkast | WebM i Groupfolder (restricted-tagg); transkript/utkast transient | `spreed-itsl`-fork (`recording_consent`); `stt_whisper2` (KB-Whisper)→`llm2`; `forms`+`libresign` | 🧩 antas löst (KB-Whisper, BankID). 🔨 fork-komponenter (GAP-024/025); AI på sekretess = röd zon (GAP-052) |
| Bevakning (delad board) | Deck-kort; `tasks`/VTODO; CalDAV | `deck`; `tasks`; CalDAV-spegling | ✅ finns. 🔨 påminnelse-motor (GAP-045) |

### Fas 6 — Beslut (insats, signering)
Den färdiga BBIC-journalen **committas till Treserva** via `CommitGrind` — sdkmc väntar på **verifierad Frends-callback**, först då flippar provenans och sätts retention. Beslutsmall → **PDF/A-1**; kravnivå per SKR:s riskmodell (överklagbart → **AES**; gynnande lågrisk → "Godkänt", loggat). Signering via **libresign** (demo) eller **Inera Underskriftstjänst** (prod): BankID/Freja/SITHS (LOA3) → **PAdES** + valideringsintyg, **PDF/A-1 + LTV + kvalificerad tidsstämpel**. Signerad PDF tillbaka till rummet, taggad `case:{hubsCaseId}`.

| Aktiva Hubs-delar | Lagring | Exekverad kod | Status |
|---|---|---|---|
| `CommitGrind` "Färdigställ & för till Treserva" | **Treserva** (system of record); `files_versions` följer ej med | `Frends.commitToTreserva` (§3.2-D); `commitArende()` | 🧩/🔨 Frends-konnektor ej byggd (GAP-019, blocker) |
| Beslutsmall → PDF/A-1; kravnivå-badge (AES/Godkänt) | Beslut-PDF i ärenderum | Mall ur `collectives`; widget `attSignera` (`ncAppId: libresign`) | ✅ `libresign`/`collectives` finns. 🔨 kravnivå-matris (GAP-036) |
| Signering (PAdES/LTV) | Signerad PDF i Groupfolder, taggad `case:{id}` | `onSignera`→`libresign`; prod **Inera Underskriftstjänst**/Sweden Connect | ✅ `libresign` (demo). 🧩 Inera-AES/LTV ej integrerat; `ltv:true` demoflagga (GAP-033/034/035/037, blocker) |
| Bevarandepanel (PAdES ✓ PDF/A-1 ✓ LTV ✓) | Metadata på signerad handling | `beslut.bevarande` | ✅ UI byggt. 🔨 robust LTV gäller realistiskt bara Inera-vägen |

### Fas 7 — Delgivning & kommunicering (överklagandefrist)
Beslutet **delges** medborgaren via securemail/sdkmc; mottagar-väljaren är **`FavoritValjare`** (union [Mina] ∪ [funktions-delad]). **Vårdnadshavaren favoritmarkeras aldrig fritt** — adresseras via ärenderummets BankID-länk (ärenderums-scoped undantag). Signerad PDF/A bifogas (bär `case:{id}`; favoriten taggas aldrig). Mottagaren legitimerar med BankID → läskvittens (Skickad→Levererad→Notis öppnad→Inloggad LOA3→Läst). **Överklagandefristen** (3 v, FL 44 §) som bevakning T-7/T-3/T-0 **bara till tilldelad** — men den rättsligt bindande fristen ägs av Treserva.

| Aktiva Hubs-delar | Lagring | Exekverad kod | Status |
|---|---|---|---|
| "Delge beslut" → komponeringsyta; `FavoritValjare` | Utgående via securemail/sdkmc (`case:{id}`); favorit-vCard i Kontakter | `GET .../favoriter` (`IManager::search` + DIGG-batch-resolve); `Service/UpdateAddressBookService.php` | ✅ securemail/Kontakter/DIGG-synk finns. 🔨 resolverlager + fail-closed (GAP-061); vidarebefordra-grind (GAP-063) |
| Läskvittens-tidslinje | Kvittenser i sdkmc | `Db/MessageReceipt.php` (+Mapper) + `Controller/MessageReceiptController.php`; widget `kvittenser` | ✅ **Kvittens-modellen finns** |
| Medborgar-PII-spärr (favoriter får ej innehålla medborgare) | Server-side klass-validering (a/b/c) | `GallringsGrind` server-side analog; §5.4 | 🔨 idag bara UI-regeln (GAP-064) |
| Överklagandefrist som bevakning (bara till tilldelad) | Deck-kort + `tasks`/VTODO; `FristChip`→`overklagande` | `deck`+`tasks`; start ur delgivningssätt | ✅ finns. 🔨 delgivningssätt-flöde ej modellerat; läskvittens ≠ juridisk delgivning (GAP-038/039, major) |

### Fas 8 — Uppföljning
Beslutet är delgivet, under uppföljning. **Tidsbegränsade beslut** och **insatsuppföljning** bärs av delade bevakningar på barnets Deck-board. `FristChip` växlar till `overklagande`/`tidsbegransat`. Inkommande svar fortsätter landa i band 1b med `KopplingBadge`. All samordning i **Spreed-rummet** (regel a). `hubs_start` visar bara aggregat (regel b).

| Aktiva Hubs-delar | Lagring | Exekverad kod | Status |
|---|---|---|---|
| Uppföljnings-/tidsbegränsade bevakningar (delade) | Deck-kort; `tasks`/VTODO; `steg:'uppfoljning'` | `deck`; `tasks`; CalDAV-spegling | ✅ finns. 🔨 riv-mekanism när facksystemet tar över fristen (GAP-018/044) |
| Inkommande svar → band 1b → spara i ärenderum | Fil till Groupfolder; `case:{id}`-tag | `AttHanteraSektion.vue`/`InflodeRad`; `Service/ItslTagService.php`; `kopplaInflode()`→`POST .../inflode/koppla` | ✅ tagg-motor + UI finns. 🔨 koppla-route i prod (GAP-019) |
| Uppföljnings-möten (SIP) + transkribering | `calendar`; WebM/anteckning i Groupfolder | `calendar`; Spreed-fork; `stt_whisper2`/`llm2`; `CommitGrind` | 🧩 antas löst. 🔨 fork-komponenter (GAP-024/025) |
| All samordning = Spreed-rummet; dubbel countdown | `spreed-itsl`; provenans-fält i `hubs_arenden` | `talkToken`; `ArendeKort.vue` provenans-rendering | ✅ Spreed finns; UI byggt |

### Fas 9 — Avslut & gallring av Hubs-kopian (commit → Treserva)
Efter den **verifierade Frends-callbacken** sätter en **FLOW-regel** den **restricted** retention-taggen `retention:hubs-30d` (`retentionState='gallras_efter_commit'`); `files_retention` gallrar **Hubs-kopian på tid** med ägarnotis — **originalet bevaras i Treserva → e-arkiv** (Sydarkivera/FGS, facksystemets ansvar). Retention-klockan kan **pausas** vid utlämnandebegäran (TF). Ej-kopplat-rester städas via **`GallringsGrind`** (handlingstyp ur DHP → visat gallringsbeslut → bekräfta; aldrig naket radera-klick). Lärdom lyfts **avidentifierat** till enhetschatten i Spreed (regel a). **Avsikten var transient i Hubs; varaktigheten ligger i Treserva och e-arkivet.**

| Aktiva Hubs-delar | Lagring | Exekverad kod | Status |
|---|---|---|---|
| Retention-tagg på **verifierad commit** → tidsgallring | Restricted retention-tag på Groupfolder-filer; radering i Hubs, bevarande i Treserva | **FLOW**: `state:committed`→`retention:hubs-30d` (§3.1.2); `files_retention` | ✅ `files_retention`/`systemtags` finns. ⚙️ Flow-regeln. 🔨 binda till **verifierad** callback (GAP-007, blocker) |
| sdkmc retention/gallring-motor (korgnivå) | `MailboxRetention`-poster; expunge-jobb | `Db/MailboxRetention.php` + `Service/MailboxRetentionService.php` + `Service/ExpungeService.php` + `BackgroundJob/ExpungeJob.php` + `DeleteTagsJob.php` | ✅ **Retention/gallrings-motorn finns** (sdkmc) |
| Retention-**paus** vid utlämnandebegäran (TF) | `retentionState:'pausad'` i `hubs_arenden` | Hook saknas; enum-värde utan trigger | 🧩/🔨 ingen paus-hook byggd (GAP-031, blocker) |
| `GallringsGrind` för ej-kopplat-rester | Radering med dokumenterat stöd; `activity`-logg | `GallringsGrind.vue` (`NcDialog`); arkivlag 1990:782 §10; OSL 5:1 | ✅ byggt på interaktionsnivå & LIVE; DHP-förankring = policy (GAP-008/015/020/050) |
| Kontaktfavorit-översyn (tombstone) | Kontakter-app; `removed:true`→överstruken | `Service/UpdateAddressBookService.php` (DIGG-spegel) | ✅ DIGG-synk finns. 🔨 resolver/fail-closed + klass-validering (GAP-061/062/064) |
| Avidentifierad lärdom → enhetschatt i Spreed (regel a) | `spreed-itsl` team-rum (Circles-team); committas via `CommitGrind` | `EnhetschattPanel`/`ArendeDiskussion.vue`; `circles`; `talkToken` | ✅ Spreed/Circles finns. 🔨 avidentifiering är policy/UI, ej server-spärr; chatt-retention (GAP-059) |

---

## 4. Readiness-matris över Hubs-stacken

**Verifierat mot kod:** sdkmc `lib/` (Controller/Service/Db/Listener/BackgroundJob/Check/Activity), `appinfo/routes.php` (93 rader, `/api/v2/...`-OCS + tag-routes på `/api/tags|messages|thread`), `hubs_start/lib` (4 klasser), `DEMO-STUBS.md` (seam-registret), `SOCIALSEKRETERARE-WALKTHROUGH-V2.md` (gap-analysen), `HUBS-ARKITEKTUR-SOCIALTJANST.md` §§0–7.

| Del | Roll i ärendeflödet | Finns idag (verklig kod/app) | Måste konfigureras | Måste byggas | Beroende-gap |
|---|---|---|---|---|---|
| **hubs_start (dashboard)** | Fördelar/överblickar via aggregat + kommandon; lagrar **ingen** verksamhetsdata | TUNT PHP-backend: `PageController` (sida+boot+`isDemoMode()`), `PreferencesController`+`Service/PreferencesService` (**bara** UI-prefs), `RoleService`, `AppDetectionService`. Vue-vyn (32 komponenter) LIVE i demoläge | `demo_mode='0'`/AUTO→`boot.demoMode=false`→axios-grenarna aktiveras; `AppDetectionService::detect()` | **Inget verksamhets-backend** (per krav) — men **27 OCS-anrop i `api.js` mot routes som inte finns** (`POST /arende`, `/treserva/commit`, `/inflode/{action}`, `GET /favoriter`, `/treserva/receipts`, `/summary`, `/fordelning-summary`) | GAP-019/056/057/058/061 |
| **sdkmc (tagg/typ/korg/retention/Flow)** | **Ärende-motorns hem** — ensam skrivare av registret; ägare av tagg/typ/korg/gallring/Flow | **Brett byggt:** TAGG (`ItslTagService`, `ItslTag(.php/Mapper)`, `ItslMessageTag(.php/Mapper)`, `ItslTagController`, `TagFileController`, `TagSearchHelper`; routes `/api/tags/*`, `/api/messages/{id}/tags/*`, `/api/thread/tags/*`, `/api/v2/tag/assign`). TYP (`MessageTypeService::getMessageTypeFromEmail()`, deterministisk, ingen LLM). TRÅD (`MessageThread`). KVITTENS (`MessageReceipt`). RETENTION (`MailboxRetention`, `MailboxRetentionService`, `ExpungeService`, `ExpungeJob`, `DeleteTagsJob`). FLOW (`RegisterOperationsListener`+`RegisterChecksListener`+`Check/Loa3`). KATALOGSYNK (`UpdateAddressBookService`). KORGAR (`ConsolidateMailboxesService`, `ProvisionPersonligAccountsService`, Activity-settings). "Viktig"-klassning (`MessageImportantClassifiedListener`/Event) | TLD-konvention (sdk/fax/sms/personlig/gruppbox/securemail); DIGG-synk-credentials; Sieve/IMAP per korg; retention-perioder; Flow-regler ur `Loa3` | **Atomär `createArende()`** (register-INSERT + Groupfolder+ACL+Deck+Talk+caseTag+klocka). **`hubsCaseId↔dnr`-matchningsmotor**. **`/api/v2/arende`, `/treserva/commit`, `/inflode/{action}`, `/favoriter`-routes** (refererade i `api.js`, ej i `routes.php`). **Registret-skrivlagret.** **Retention bunden till verifierad commit** | GAP-019/007/056/057/058/060/061 |
| **mail (kanaler)** | Bär fysiska in-/utkorgar som sdkmc klassar/taggar | NC Mail aktiv; sdkmc hookar in (`IMailManager`, `IMAPClientFactory`, `getSource()` för header-läsning) | IMAP-konton per funktionsadress; X-headers från upstream; Sieve via `ImplementSieve` | Inget i mail-appen — klassningen lever i sdkmc | GAP-054 (delad-adress-routing) |
| **securemail** | Säker e-post UT/IN (BankID, läskvittens) | OCS-spår i sdkmc (`/api/v2/securemail/*`; `secureMailData`, `internalMailboxes`, `sms/send-auth-code`); `CustomInvitationMailerService` | SMS-auth-provider (`Service/Sms/*`); securemail-config; BankID-lobby | Delgivnings-flöde (läskvittens ≠ juridisk delgivning); fristhärledning ur delgivningssätt | GAP-038/039 (major) |
| **spreed-itsl (ALL chatt + möte)** | **All** chatt = ärendechatt, enhetschatt, säkert möte m. transkribering | ITSL-fork (`hubs-code/spreed-itsl`); sdkmc `TalkController` (`/api/v2/spreed/*`); `DeleteInactiveTalkRoomBackgroundJob`; gäst-/LOA3-flöde | Talk-server (HPB/TURN); recording-server; fork-bygge; gäst-policy | **Ärenderum-koppling** (`talkToken`-pekare, kräver registret). **Chatt-retention** + "är detta en handling?"-grind. Server-side avidentifiering. Svensk live-STT (KB-Whisper) | GAP-059 (major), GAP-024/025/045/052 |
| **Tables (`hubs_arenden`)** | **Kanoniska registret** — join-nyckel för ALLA appar | Tables-appen aktiv. Rad-shape **designad** (§1.2) | Schema/kolumntyper; sdkmc-tjänstanvändare ensam skrivare; ACL på vyn | **HELA registret — finns INTE.** Idag in-memory `REGISTER` (`demo/treserva.js`). Skrivlager, saga/kompensering, reconciliation, integritets-larm, backup | **GAP-056 (blocker)** + bär 019/057/058 |
| **Deck** | Board/enhet · kort/ärende; assignee; stack=fas | Deck aktiv. Modell designad (§4): label `case:{id}` + pekare `{boardId,cardId}`, native assignee, stack=stepper | Board per enhet; stack-namn = faser; label-konvention | 2-stegs kort-skapande i orkestreringen; tvåvägs-pekare; Deck-flytt bunden till fas-spärr; påminnelse-motor | GAP-056/057, 045 |
| **Groupfolders** | Ärenderum + ACL = **OSL-säkerhetsgränsen** | Groupfolders aktiv (Advanced ACL) | Gruppmappar; ACL-grupper; Automated-Tagging mapp→`case:` | **Atomär ACL-omskrivning vid fördelning** (revoke→write utan sekretessfönster). **Tre-lagers-ACL-koherens** (tagg↔mapp-ACL↔Tables-vy) + koherens-test + deny-by-default | **GAP-057 + GAP-058 (blockers)**, 010 |
| **workflow_engine / Flow** | Deklarativa per-objekt-regler: mapp→tagg, retention→gallring, LOA3 | **Registrering byggd:** `RegisterOperationsListener`+`RegisterChecksListener`; `Check/Loa3.php` | Flow-regler i admin (mapp→tagg, retention→gallra, LOA3) | Konkreta regel-instanser per enhet (genereras av orkestreringen). Skapande/fler-objekt ligger **utanför** Flow (programmatiskt) | GAP-007, 058 |
| **Circles** | Team = medlemskapssanning delad med ACL; enhetschatt-krets | Circles aktiv; sdkmc `GroupLifecycleListener`/`GroupMembershipListener`/`UserLifecycleListener` | Team per enhet; team↔Groupfolder-ACL↔Talk-deltagare | Synk team→ACL→chattdeltagare som **en** kanonisk källa (del av GAP-058) | GAP-058, 051 |
| **Activity (audit)** | Spårbarhet för alla ärende-händelser | **Byggt:** `Activity/Provider`, `Filter`, settings; `MailboxNotificationService`+`MailboxNotificationLog` | Activity-defaults per korg/användare | Audit-kopplingar för **nya** händelser (skapa/fördela/commit/gallra/vidarebefordra) | GAP-063 |
| **LibreSign** | Signera överklagbara beslut (PAdES/LTV) | LibreSign aktiv; demoväg via widget `attSignera` (`ncAppId:libresign`), lokal rot-CA | LibreSign-CA; PDF/A-export; signeringskö | **Inera Underskriftstjänst / Sweden Connect** (LibreSign-AES ≠ svensk myndighets-AES). Robust LTV + kvalificerad tidsstämpel | **GAP-034/035/037/033 (blocker)** |
| **richdocuments / Collabora** | Samredigering on-prem (WOPI) i ärenderummet | Aktiv; sdkmc `WopiTokenMiddleware` | Collabora-server (CODE); WOPI-host; versionshantering | Produktbeslut **var texten skrivs** (Collabora i Hubs vs Treserva-journal) — dubbel-författande-risk | GAP-012/032/014 (major) |
| **calendar** | Möten/SIP, säkert-möte-bokning, frist-VTODO | Calendar aktiv; sdkmc `CalendarEventProcessorService`, `IcsParserService`, intent-controllers (`/api/v2/calendar/*`) | Kalender per enhet; intent-hooks; SMS/BankID-providers | Oautentiserad bokning (Forms-brygga); BankID-lobby för gästmöte; Deck/VTODO-bevakning bunden till frist | GAP-016/021/023 |
| **Contacts (favoriter)** | Kontakter som-den-är = favoritlager; favorit = **pekare, ej post**, resolvad ur DIGG | Contacts aktiv; DIGG-synk via `UpdateAddressBookService`. Demo: 4 favorit-DTO:er | Favorit-adressböcker; DIGG-cache | **Tunt sdkmc-resolverlager** `GET /favoriter` (`IManager::search` + DIGG-batch) med färsk-resolve + **hård fail-closed**. Server-side klass-validering (a/b/c) som **blockerar fri medborgar-PII**. Tombstone-gallring | **GAP-061 + 062/063/064 (major)** |
| **Frends/Treserva-konnektor** | Bryggan Hubs→facksystem (commit, aktualisering, dnr-paring, retention-start) | **Finns INTE.** `commitToTreserva()` hårdkodad stubb (`{ok:true, dnr:'…NEW'}`); stateful in-memory `REGISTER`/`RECEIPTS` (`demo/treserva.js`) — rätt **mönster** (retention startar först på verifierad callback) | Frends-iPaaS; Treserva-endpoints; ett flöde per handlingstyp; callback-URL | **Hela Frends-flödet** bakom `POST /treserva/commit` + `POST /arende` med **verifierad callback**; provenans-flip + retention-tagg **först då**; `GET /treserva/receipts` | **GAP-019 (tyngst) + 007/001** |
| **e-arkiv (Sydarkivera FGS)** | Slutarkiv efter Treserva (facksystemets ansvar) | **Inget i Hubs** | FGS-paketering; Sydarkivera-anslutning | FGS-gräns/överlämning hos facksystemet; Hubs gallrar bara sin kopia efter verifierad commit | GAP-040 (per kund) |
| **AI / transkribering** | Möte-transkript + AI-utkast (human-in-the-loop), aldrig auto-commit | Demodata `transkript`/`aiUtkast`; "Gör detta till en handling"→CommitGrind | — (juridiskt blockerat skarpt) | **Lokal STT (KB-Whisper)** + recording-server; transkript+utkast sida-vid-sida; loggat aktivt godkännande. Skarp körning **blockerad** tills IMY/SKR/Socialstyrelsen | **GAP-052 (blocker)** + 029/028/045 |

### Prioriterad bygglista

1. **Frends/Treserva-konnektorn — GAP-019 (blocker, tyngst).** Frends-flödet bakom `POST .../treserva/commit` + `POST .../arende` med **verifierad callback**. Grundorsak bakom commit/gallring/spegling.
2. **`hubs_arenden`-registret i Tables — GAP-056 (blocker).** Schema + sdkmc ensam skrivare + saga/kompensering + reconciliation + arkivkritisk backup. Join-nyckeln för hela stacken.
3. **Atomär `createArende()`-orkestrering — GAP-010.** Ett sdkmc-anrop: register + Groupfolder+ACL + Deck + Talk + `case:`-tagg + klocka, allt-eller-inget. Plus OCS-routerna `api.js` redan kallar.
4. **Gallring bunden till verifierad commit — GAP-007 (blocker).** Flytta retention-start från tid/tagg (`ExpungeJob`) till den verifierade Frends-callbacken.
5. **Fördelnings-ACL-race + tre-lagers-koherens — GAP-057/058 (blockers).** Atomär multi-objekt-commit (revoke→grant utan sekretessfönster) med lås/idempotens på `hubsCaseId`; **en** kanonisk ACL-källa → tagg + Groupfolder-ACL + Tables-vy + koherens-test + deny-by-default.
6. **Skyddsbedömningens commit-tvång — GAP-001 (blocker).** Tvingande regel att skyddsbedömningen committas (UI-pliktmarkören finns; backend-grinden saknas).
7. **Inera Underskriftstjänst — GAP-034/035/037/033 (blocker).** LibreSign-AES räcker inte för överklagbart beslut; bygg Inera/Sweden Connect + robust LTV.
8. **Favorit-resolverlager + fail-closed + PII-spärr — GAP-061/064 (major).** `GET /favoriter` (IManager::search + DIGG-batch), hård fail-closed, server-side klass-validering; favoritlistor som DHP-handlingstyper.
9. **AI/transkribering & Retention-paus — GAP-052/031 (blockers).** Lokal KB-Whisper + recording-server; "pausa retention vid utlämnandebegäran"-hook (idag enum-värde utan trigger).

**Nettobild:** sdkmc är en **mogen kanal/tagg/typ/retention/Flow-motor med riktiga OCS-routes** — men **ärende-funktionen** (registret, den atomära skapa/fördela-orkestreringen, Treserva-konnektorn, commit-bunden gallring, tre-lagers-ACL-koherens) är **inte byggd**; den lever som demo-stubbar i `hubs_start/src/services/demo/`. UI:t (32 komponenter) är LIVE och prod-grenarna i `api.js` är redan skrivna mot routes som ännu inte finns — handovern = **bygg backend bakom de namngivna seams, inte rita om ytan**.

---

## 5. Öppna punkter & blockerare

| GAP | Klass | Rubrik | Status / vad som krävs |
|---|---|---|---|
| **GAP-019** | blocker (tyngst) | Frends/Treserva-konnektor saknas | `commitToTreserva()` är hårdkodad stubb. Bygg hela Frends-flödet med **verifierad callback**; provenans-flip + dnr-paring + retention först då. Grundorsak bakom commit/gallring/spegling. `[BYGGS]`/`[ANTAS]` |
| **GAP-056** | blocker | `hubs_arenden`-registret finns inte | Hela Tables-registret + sdkmc ensam skrivare + saga/kompensering + **reconciliation register↔objekt** + arkivkritisk backup. Join-nyckeln för stacken. `[BYGGS]` |
| **GAP-057** | blocker | Skapa/fördela ej atomär; ACL-race | "Skapa ärende" och "fördela" är distribuerade sagor utan gemensam rollback. Atomär multi-objekt-commit med lås/idempotens på `hubsCaseId`; revoke→grant utan sekretessfönster. `[BYGGS]` |
| **GAP-058** | blocker | Tre-lagers-ACL-koherens | `case:`-tagg ∩ Groupfolder-ACL ∩ Tables-vy måste vara samma sanning. **En** kanonisk ACL-källa + automatiserat koherens-test + deny-by-default. Circles-team↔ACL↔Talk-deltagare synkad. `[BYGGS]` |
| **GAP-007** | blocker | Gallring ej bunden till verifierad commit | Idag tid-/tagg-baserad `ExpungeJob`. Flytta retention-start till faktisk verifierad Frends-callback (stubben gör rätt mönster). `[BYGGS]` |
| **GAP-001** | blocker | Skyddsbedömningens commit-tvång | UI-pliktmarkören blockerar stepper-flytt; backend-grinden som tvingar commit till facksystemet saknas. `[BYGGS]` |
| **GAP-060** | major | Auto-kopplings-konfidens = tyst sekretessflytt | Tröskeln (≥0.9) är klient-/demo-logik. Flytta server-side; obligatorisk människo-bekräftelse över tröskel; bilaga vid bekräftelse; logga avvisade förslag. `[BYGGS]` |
| **GAP-052** | blocker (policy) | AI på sekretessbelagt = röd zon | LLM/transkribering får inte köra skarpt på sekretess förrän IMY/SKR/Socialstyrelsen gett vägledning. Lokal KB-Whisper + human-in-the-loop som förutsättning. `[ANTAS]`/`[BYGGS]` |
| **GAP-061** | major | Favorit-resolver + fail-closed saknas | Tunt sdkmc-resolverlager `GET /favoriter` (`IManager::search` + DIGG-batch-resolve) med färsk-resolve och **hård fail-closed**. `[BYGGS]` |
| **GAP-062** | major | Favorit-tombstone/inaktualitetsgallring | Tombstone-driven inaktualitet mot DIGG (`removed:true`). `[BYGGS]` |
| **GAP-063** | major | Vidarebefordra-favorit prövas ej | Vidarebefordran loggas men prövas inte; bygg vidarebefordra-grind + audit. `[BYGGS]` |
| **GAP-064** | major | Medborgar-PII-spärr på favoriter | Server-side klass-validering (a/b/c) som blockerar fri medborgar-PII i favoritlager; favoritlistor som DHP-handlingstyper. `[BYGGS]` |
| GAP-010 | major | Atomär `createArende()`-orkestrering | Ett sdkmc-anrop: register + Groupfolder+ACL + Deck + Talk + tagg + klocka. `[BYGGS]` |
| GAP-031 | blocker (drift) | Retention-paus vid TF-begäran | `retentionState:'pausad'` är enum-värde utan trigger; bygg paus-hook. `[BYGGS]` |
| GAP-033/034/035/037 | blocker | Inera-signering / LTV | LibreSign-AES ≠ svensk myndighets-AES för överklagbart beslut; `ltv:true` är demoflagga. Bygg Inera Underskriftstjänst/Sweden Connect + robust LTV. `[ANTAS]`/`[BYGGS]` |
| GAP-038/039 | major | Delgivning: läskvittens ≠ juridisk delgivning | Modellera delgivningssätt; fristhärledning ur delgivningssätt. `[BYGGS]` |
| GAP-059 | major | Ärendechatt-retention + handlings-grind | Spreed-chatt-retention och "är detta en handling?"-grind saknas; avidentifiering vid "Lyft till enhetschatt" är policy/UI, ej server-spärr. `[BYGGS]`/`[FORK]` |
| GAP-018/044 | minor/major | Frist speglas ej / riv-mekanism | `fristDue` ska speglas ur Treserva (Frends), ej självständigt räknas; demon räknar `daysLeft` lokalt. `[BYGGS]` |
| GAP-012/032/014 | major | Var texten skrivs (Collabora vs Treserva-journal) | Produktbeslut; dubbel-författande-risk. (policy) |
| GAP-040 | major | e-arkiv-överlämning (FGS) | Ligger hos facksystemet; Hubs gallrar bara sin kopia efter verifierad commit. (per kund) |

**Genomgående bekräftat (de tre löftena, kod-verifierade):**
(a) **all chatt** går via Spreed-rummet bundet av registrets `talkToken`-pekare — `ArendeDiskussion.vue` renderar bara en lättviktig trådvy, lagrar inget;
(b) **`hubs_start` lagrar ingen verksamhetsdata** — hela PHP-backend är `PageController` + `Preferences*` + `RoleService` + `AppDetectionService`, och `api.js`-grenarna är rena aggregat-läsningar/kommandon mot sdkmc;
(c) **gruppledaren ser all ärendeinfo i fas 4** (Zon A) för att fördela, men belastningspanelen (Zon B) exponerar bara tal + frist-färg, aldrig barn eller innehåll (OSL 26 kap.).

---

## Relevanta filer (absoluta sökvägar)

**Tagg-motorn (FINNS):**
- `C:/Users/fredrik.jonasson/Cursor/Nextcloud-vanilla/hubs-code/sdkmc/sdkmc-main/lib/Service/ItslTagService.php`
- `…/lib/Db/ItslTag.php`, `…/lib/Db/ItslMessageTag.php`
- `…/lib/Controller/ItslTagController.php`, `…/lib/Controller/TagFileController.php`
- `…/lib/Service/TagSearchHelper.php`
- `…/lib/Migration/Version020008Date20251229000000.php` (+ `…0009`)
- `…/appinfo/routes.php` (rad 82–91 tag-routes; `/api/v2/...`-OCS-prefix)

**Klassning/trådning/kvittens/retention/Flow/synk/korgar (FINNS):**
- `…/lib/Service/MessageTypeService.php` (rad 70–92, typ-map)
- `…/lib/Listener/MessageImportantClassifiedListener.php` + `…/lib/Event/MessageImportantClassifiedEvent.php`
- `…/lib/Db/MessageThread.php`, `…/lib/Db/MessageReceipt.php` + `…/lib/Controller/MessageReceiptController.php`
- `…/lib/Service/MailboxRetentionService.php`, `…/lib/Service/ExpungeService.php`, `…/lib/BackgroundJob/ExpungeJob.php`, `…/lib/BackgroundJob/DeleteTagsJob.php`, `…/lib/Db/MailboxRetention.php`
- `…/lib/Listener/RegisterOperationsListener.php`, `…/lib/Listener/RegisterChecksListener.php`, `…/lib/Check/Loa3.php`, `…/lib/AppInfo/Application.php` (rad 126–133)
- `…/lib/Service/UpdateAddressBookService.php` + `…/lib/BackgroundJob/UpdateAddressBookBackgroundJob.php`
- `…/lib/Service/ConsolidateMailboxesService.php`, `…/lib/Service/ProvisionPersonligAccountsService.php`, `…/lib/Activity/AssignmentTagSetting.php`
- `…/lib/Controller/TalkController.php`

**Dashboard (FINNS, tunt):**
- `C:/Users/fredrik.jonasson/Cursor/Nextcloud-vanilla/hubs_start/lib/Controller/PageController.php`
- `…/hubs_start/lib/Service/PreferencesService.php`, `…/lib/Controller/PreferencesController.php`, `…/lib/Service/RoleService.php`, `…/lib/Service/AppDetectionService.php`

**Att bygga (FINNS EJ):**
- `…/sdkmc/sdkmc-main/lib/Service/ArendeService.php`, `…/lib/Service/ArendeMatchService.php`, `…/lib/Service/TreservaCommitService.php`
- `…/lib/Db/Arende.php` + `…/lib/Db/ArendeMapper.php`, `…/lib/Controller/ArendeController.php`
- `…/lib/BackgroundJob/ArendeReconciliationJob.php`
- Tables-tabellen `hubs_arenden` (eller migration `Version02xxxx…CreateArendeTable.php`)

**Design & seams:**
- `C:/Users/fredrik.jonasson/Cursor/Nextcloud-vanilla/hubs_start/docs/HUBS-ARKITEKTUR-SOCIALTJANST.md` §1.2 (registrets rad-shape), §3 (Flow vs programmatiskt), §3.2-A/B/C/D, §5.1 (tre axlar), §6 (sekvensdiagram)
- `…/docs/UI-EVOLUTION-SOCIALSEKRETERARE.md` (tre band, gruppering, `InflodeRad`-shape, CONTRACTS regel 6)
- `…/docs/SOCIALSEKRETERARE-WALKTHROUGH-V2.md` (akter I–V, gap-analys, KVARSTÅENDE-tabell)
- `…/docs/DEMO-STUBS.md` (SEAM-registret, prod-ersättare, status)
- `…/docs/GAP-ANALYSIS.md` (GAP-052 m.fl.)
- `…/src/services/demo/socialsekreterare.js` (STEG-listan), `…/src/services/demo/treserva.js` (in-memory `REGISTER`/`RECEIPTS`)
