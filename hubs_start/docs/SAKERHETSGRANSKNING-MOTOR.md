# Säkerhets- och sekretessgranskning — hubs_arende-motorn

**System:** `hubs_arende` (`OCA\HubsArende`) — fristående ärende-motor
**Miljö:** Deployad och körande på dev15 (Nextcloud 31.0.8). Smoke grön, 44 PHP-filer lint-rena, alla OCS-routes svarar 401 oautentiserat.
**Metod:** Adversariell granskning med kod-verifiering (fil:rad citeras). Varje fynd har bedömts mot nåbarhet: **live** (nåbar nu via deployad API-yta), **seam** (latent — aktiveras när en framtida koppling wiras) eller **teoretisk**.
**Datum:** 2026-06-17

---

## 1. Sammanfattning + verdikt per invariant

Motorn är arkitektoniskt välbyggd i sin kärna: R0-säkerhetsskyddsgrinden körs fail-closed före all sido-effekt på födelsevägen (`ArendeService.php:110-131`), sagan har symmetrisk kompensering, integrationsklienterna är `isAvailable()`-gatade graceful no-ops, och den verkliga inflöde-feeden är fortfarande en tom seam (`resolveInflodeRows()` returnerar `[]`, `InfodeController.php:231-233`). Det dämpar den faktiska blast-radien idag avsevärt.

Men granskningen hittade **två allvarliga klasser av live-nåbara brister**: (1) **total avsaknad av objektnivå-auktorisation** över sekretessgräns (horisontell IDOR på alla skriv/läs-verb), och (2) en **commit-route som är fel-wirad** så att den kringgår hela provenans-/retention-/existens-/idempotens-logiken. Dessutom finns flera latenta fail-open i sekretess- och säkerhetsgrindar som är ofarliga idag men aktiveras i exakt det ögonblick respektive seam wiras.

| Invariant | Status | Mening |
|---|---|---|
| **Never-SoR** (motorn lagrar bara koordinations-state, aldrig verksamhetsdata) | 🟡 **GUL** | Registret bär korrekt bara pseudonym routing-state, MEN live-commit-routen kan ge föräldralös/dubblerad facksystem-registrering utan att registret någonsin uppdateras (`ArendeController.php:165`). |
| **OSL-sekretess** (ingen PII lagras/loggas/returneras; objektRef/triageRef = pseudonymer) | 🔴 **RÖD** | `objektRef`/`triageRef` kopieras verbatim utan PII-validering och ekas i API-svar (`ArendeService.php:599,605-608`), och varje inloggad handläggare kan läsa/skriva annan enhets ärende (ingen ACL). |
| **Säkerhetsskydd fail-closed** (klassat material föds inte / avvisas före all sido-effekt) | 🟡 **GUL** | Födelsevägen (createCase) ÄR fail-closed och intakt, men grinden körs ENBART på write-vägen — triage/läs-vägen är ogrindad, och detektorn missar klassmarkeringar under andra kuvert-nycklar (`InfodeController.php:89-100`, `SakerhetsskyddGrind.php:316-324`). |
| **Auto-koppling** (ingen auto-koppling över sekretessgräns utan människa; anonymitet bevaras; tröskel server-side) | 🟡 **GUL** | Tröskeln är korrekt server-side och kopplings-default är `ej_kopplat`, MEN anonymitetsgrinden (TF 2:18) är strukturellt fail-open och styrs av klient-input — neutraliserad idag av en hård null-hook, latent när part-registret wiras (`ArendeMatchService.php:519-538,561-565`). |
| **GDPR/gallring** (retention bunden till verifierat kvitto; ingen föräldralös PII) | 🔴 **RÖD** | Den enda live commit-routen flippar ALDRIG `retentionState`/`provenanceState` (`ArendeController.php:165` → `FacksystemCommitService` rör aldrig mappern), och även den korrekta vägen persisterar aldrig gallrings-deadline (ingen kolumn). |

---

## 2. Bekräftade fynd (grupperade per severity)

### 🔴 HÖG

#### H1 — Total avsaknad av enhets-/ägar-ACL: horisontell IDOR på alla case-routes
- **Fil:** `ArendeController.php:85-102` (show), `:130-147` (tilldela), `:158-175` (commit); `InfodeController.php:195-211` (doKoppla); `ArendeService.php:416-426,474-527,539-572`; `ArendeMapper.php:38-93`
- **Lagrum:** OSL 2009:400 26 kap (sekretess socialtjänst); least-privilege / broken access control
- **Nåbarhet:** **LIVE**
- **Beskrivning:** Varje route bär `#[NoAdminRequired]` — det verifierar endast att *någon* är inloggad. Grep över hela appen ger noll träffar på `IUserSession`/`getUID`/`IGroupManager`/`isInGroup`: anroparens identitet eller enhets-tillhörighet läses aldrig. `findByCaseId`/`findByDnr` filtrerar bara på objektreferensen, aldrig ägare/enhet (`ArendeMapper.php:42-44,83-84`). Vilken autentiserad handläggare som helst kan därmed: läsa annan enhets ärende, **tilldela** valfritt ärende till valfri uid (+ trigga ACL-omskrivning på ärenderummet), och **driva valfritt ärende till facksystem-commit**. Det allvarligaste är skriv/commit-IDOR över sekretessgräns. Servicen *dokumenterar* t.o.m. den saknade kontrollen (`ArendeService.php:431`: "the OCS layer is expected to scope to the caller's authorised enheter") men implementerar den inte.
- **Kalibrering:** Nedjusterad från "kritisk" till "hög" eftersom läs-läckan är pseudonymt koordinations-state (inte rått PII-innehåll), och primärnyckeln för levande state är CSPRNG-UUIDv4 (`mintUuidV4`), inte praktiskt uppräkningsbar (`dnr` sätts först efter verifierad commit). Skriv-IDOR på en live-väg över OSL-gräns kvarstår som hög.
- **Åtgärd:** Injicera `IUserSession` + `IGroupManager` (eller en registrerad Middleware via `Application::register()`). Inför objektnivå-authz FÖRE varje läs/skriv: härled användarens auktoriserade enheter och verifiera att `$arende->getEnhet()` ingår. Returnera **404** (ej 403, för att inte läcka existens). Prioritera skriv-verben (tilldela/commit). Lägg samma enhets-predikat i `ArendeMapper`. Regressionstest per route.

#### H2 — Live commit-route flippar ALDRIG retention/provenans i registret (motorn blir de-facto SoR)
- **Fil:** `ArendeController.php:160-175`; jfr `ArendeService.php:554-569`
- **Lagrum:** GDPR art. 5.1.e (lagringsminimering/gallring); never-SoR-invarianten
- **Nåbarhet:** **LIVE**
- **Beskrivning:** Den enda HTTP-vägen att committa (route `Arende#commit`, `routes.php:44-49`) anropar på `ArendeController.php:165` `$this->facksystemCommitService->commit($hubsCaseId, $payload)` **direkt** och returnerar kvittot — den rör aldrig `ArendeMapper`/registret. Hela provenans-/retention-flippen (`setProvenanceState('registrerad')`, `setRetentionState('gallras_efter_commit')`, `setDnr`) ligger i `ArendeService::commit()` (`ArendeService.php:554-569`), som **bara anropas av `Smoke.php:67` och enhetstesterna** — ingen produktionsväg. Konsekvens: efter en verifierad commit via det deployade API:t står raden kvar permanent på `retention_state='aktiv'`/`provenance_state='ej_registrerad'` (entitet-/migrationsdefaults). GDPR-gallrings-invarianten uppfylls ENBART på CLI/test-vägen.
- **Kalibrering:** Nedjusterad hög→medel av verifieraren eftersom det är en retention-bokföringsbrist (inte en konfidentialitetsläcka), och det skarpa PII-inflödet fortfarande är en tom seam — men mekanismen är ett deployat live API som bryter retention-invarianten by construction. Behandlas som hög-prioriterad fix.
- **Åtgärd:** Wira om `ArendeController::commit()` rad 165 till `$this->arendeService->commit(...)` (som gör existenskoll via `show()`, payload-anrikning och register-flippen). Ta bort `FacksystemCommitService`-beroendet ur controllern. Integrationstest som POST:ar mot OCS-commit-routen och asserterar `retentionState='gallras_efter_commit'`/`provenanceState='registrerad'` efteråt. (Bekräfta `hubsCaseId`-vs-`ref`-semantiken: `ArendeService::commit()` tar `ref` och löser via `show()`.)

#### H3 — Live commit-route saknar idempotens + existenskontroll: dubbel-/föräldralös facksystem-registrering
- **Fil:** `ArendeController.php:160-166`
- **Lagrum:** GDPR art. 5.1.d (riktighet); never-SoR
- **Nåbarhet:** **LIVE**
- **Beskrivning:** Samma fel-wiring som H2, sedd från korrekthets-vinkeln. Eftersom controllern går runt `ArendeService::commit()` görs **ingen** `show()`-existenskontroll, **ingen** "redan registrerad"-spärr och **ingen** idempotensnyckel bärs. `FacksystemCommitService::commit()` rör aldrig mappern — den läser bara `$payload['commit_destination']`. En POST med godtyckligt `hubsCaseId` + anropar-satt `commit_destination=facksystem` ger ett verifierat kvitto + mintad dnr utan att raden ens finns → föräldralös facksystem-registrering. Default `synchronousCallback=true` kör `verifyCallback` in-process och default `correlationId` härleds ur `committedAt` → unik callback-token per anrop → två POST = två verifierade kvitton. Kontrast: `createCase` ÄR idempotent på `conversationId` (`ArendeService.php:133-145`); commit-vägen har ingen motsvarande spärr.
- **Kalibrering:** Verifieraren höjde medel→hög: två kärn-invarianter (never-SoR + GDPR-retention) bryts på en live autentiserad väg. Ej "kritisk" endast för att commit-målet idag är stubben (ingen verklig Treserva-skrivning); dag ett en live `FacksystemCommitPort` registreras blir det en verklig dubbel-skrivning.
- **Åtgärd:** (1) Wira om rad 165 till `arendeService->commit()` (löser H2+H3 i ett drag). (2) Lägg idempotens-spärr i `ArendeService::commit()`: efter `show()`, om `provenanceState==='registrerad'`, returnera kvitto härlett ur befintlig dnr utan att anropa `commitService` igen. (3) Bär en stabil `correlationId` (hubsCaseId + klient-idempotensnyckel). (4) Överväg att inte injicera `FacksystemCommitService` i controllern alls.

### 🟡 MEDEL

#### M1 — objektRef/barnRef kopieras verbatim till registret utan pseudonym-validering (PII kan födas in)
- **Fil:** `ArendeService.php:605-608` (sätts), `Arende.php:118` (ekas i svar)
- **Lagrum:** OSL 2009:400 26 kap (pseudonym-principen)
- **Nåbarhet:** **LIVE**
- **Beskrivning:** `buildEntity()` sätter `objektRef` från `$rad['objektRef'] ?? $rad['barnRef']` med en ren `(string)`-cast och **ingen** validering att värdet är en pseudonym. Invarianten "NEVER PII" (kommentar `:604`, fält-doc `Arende.php:65`, migration-kommentar) hävdas bara av konvention. En klient som POST:ar `{objektRef:'19850101-1234'}` eller `{barnRef:'Anna Andersson'}` till `POST /api/v1/arende` (eller via `InfodeController::doSkapa`) får detta persisterat i `hubs_arende_case.objekt_ref` OCH returnerat i 201-svaret och i `GET /api/v1/arende/{ref}` (`Arende::jsonSerialize`, `Arende.php:118`). R0-grinden mitigerar inte — `detectIndicator` läser aldrig objektRef/barnRef och gör ingen personnummer-kontroll (`SakerhetsskyddGrind.php:313-357`). Detta är motorns enda materiella PII-riskpunkt och spec kräver server-side tröskel.
- **Kalibrering:** Triggern är en intern autentiserad klient som *bryter kontraktet* genom att skicka PII (motorns anropare ska skicka pseudonymer) — inte en angripar-styrd auth-bypass. Latent fail-open i en sekretessgrind.
- **Åtgärd:** Lägg en server-side `validateObjektRef`-hjälpare som anropas i `buildEntity()` och återanvänds av `doSkapa`-vägen. Föredra **positiv** validering: kräv hash/UUID-format och avvisa allt annat (whitespace, `\d{6,8}[-+]?\d{4}`, för långt) med `\InvalidArgumentException` → 400 (controllern mappar redan). Överväg att placera kontrollen i `SakerhetsskyddGrind::evaluate` (R0). Verifiera att objektRef inte ekas i klartext om strikt format inte kan garanteras.

#### M2 — triageRef kopieras verbatim och propageras som synligt namn till groupfolder/deck/spreed
- **Fil:** `ArendeService.php:599` (sätts), `:221,255,292` (används som namn)
- **Lagrum:** OSL 2009:400 26 kap (ingen PII i koordinations-ytor/objektnamn)
- **Nåbarhet:** Registerfältet: **LIVE**. Cross-app-spridningen: **SEAM** (dubbel-gatad av `isAvailable()` + oimplementerad auth).
- **Beskrivning:** `triageRef` sätts verbatim utan formatvalidering (`:599`) och används i R4/R5/R6 som det **människo-synliga** namnet på externa objekt: groupfolder-mountpoint (`:221`), deck-card-titel (`:255`), spreed-rumsnamn (`:292`), med fallback `hubsCaseId`. Om en klient lägger PII (klientnamn) i triageRef blir det ett synligt mapp-/kort-/rumsnamn över flera appar. Register-raden (triageRef-kolumnen) persisterar verbatim redan idag på live-väg; cross-app-namngivningen aktiveras först när grann-apparna är aktiva OCH `TODO[auth]` wiras (klienterna skickar ingen Authorization-header och sväljer fel, så objektet skapas sannolikt inte ännu).
- **Åtgärd:** Använd **aldrig** triageRef som synligt objektnamn. Minsta åtgärd: byt namn-argumentet i R4/R5/R6 (`:221,255,292`) till `$hubsCaseId` (pseudonym); behåll triageRef enbart som registerfält. Härda dessutom `buildEntity()`: validera triageRef mot ett dnr/referens-mönster. Valfritt: inkludera triageRef i `concatTextFields` så grinden ser fältet.

#### M3 — Säkerhetsskydd-grinden körs ENBART på write-vägen (createCase); läs/klassa/matcha-vägen är ogrindad
- **Fil:** `InfodeController.php:80-110`; enda `->evaluate()`-callsite är `ArendeService.php:110`
- **Lagrum:** SäkL 2018:585 (avvisa+karantän före all bearbetning); HUBS-invariant "föds inte"
- **Nåbarhet:** **SEAM** (load-bärande vägen `inflodeSummary` matas av den tomma feeden; `doKoppla→match` är live men sido-effektsfri)
- **Beskrivning:** `SakerhetsskyddGrind::evaluate()` anropas på exakt EN plats (`ArendeService.php:110`, verifierat med grep). `inflodeSummary()` kör `klassService->klassificera()` + `matchService->match()` **per rad utan att grinden körts först** (`:89-100`), och `doKoppla()` kör `match()` ogrindat (`:206`). Invarianten är att klassat material ska avvisas före "all annan sido-effekt". MEN granskningen av vad de ogrindade lagren faktiskt gör sänker allvaret: `klassificera()` är ren in-memory deterministisk klassning (ingen persistens/loggning/index); `match()`s enda sido-effekt är en parametriserad SELECT mot appens eget koordinationsregister (`registerPartHook()` returnerar null). Inget indexeras eller persisteras på läsvägen, och createCase (enda födelse/persistens) ÄR grindad. Worst case live (`doKoppla`): en koppling beräknas och returneras, inget föds.
- **Åtgärd:** Centralisera grinden i ett inflöde-normaliseringslager som BÅDA vägarna passerar. Konkret: kör `evaluate($rad)` som första steg i `inflodeSummary`-loopen (`:89`) och i `doKoppla()` (före `:206`); vid avvisad → utelämna raden och returnera neutral karantän-markör. Gör detta INNAN `resolveInflodeRows()` wiras till en riktig feed. Regressionstest.

#### M4 — Detektorn missar klassmarkeringar i andra kuvert-nycklar + saknar normalisering
- **Fil:** `SakerhetsskyddGrind.php:313-357`
- **Lagrum:** SäkL 2018:585 (fail-closed vid minsta tvivel); detektor-täckning
- **Nåbarhet:** **SEAM** (live create-POST finns men kräver auth + en producent som skickar fältet; ingen live-feed wirad)
- **Beskrivning:** `detectIndicator()` läser strukturerad signal ENBART från `sdkFields`/`itsl` och ENBART nycklarna `sakerhetsklass`/`securityClass` + `visselblasning`/`whistleblower` (`:316-324`). En klassmarkering under annan känd kuvert-nyckel (`handlingskod`, `classification`, `x-protective-marking`) går rakt igenom till `IND_NONE` → ärende skapas. Asymmetrin är skarp: `InnehallsKlassService.php:331` läser `handlingskod` som auktoritativ — samma nyckel är osynlig för säkerhetsgrinden. `concatTextFields()` skannar en fast vit-lista (ingen attachments/okänd nyckel); `str_contains` på `strtolower`-haystack saknar diakrit-/whitespace-normalisering och listan har `nato restricted` men ej `top secret`/`nato secret`.
- **Kalibrering:** Detektorn är en uttalad `TODO`-hook (`:300-308`) avsedd att ersättas. Den strukturerade `sakerhetsklass`-vägen ÄR stark fail-closed (vilket icke-tomt/icke-öppet värde som helst avvisar). Vissa av råfyndets exempel höll inte (`totalförsvar` har inget å; ASCII `sakerhetsskydd` finns redan i listan). Genuin men dämpad fail-open.
- **Åtgärd:** Behandla **närvaron** av ett klass-/handlingskod-fält som indikator oavsett värde (utom explicit oklassad/öppen); utöka strukturerad-nyckel-listan (`handlingskod`, `classification`, `x-protective-marking`, nästlad header-walk); vid okänt/icke-tolkbart värde → `IND_SAKERHETSSKYDD` (fail-closed). Återanvänd `InnehallsKlassService::handlingskod`-läsningen. Normalisera haystacken. Unit-test: `handlingskod=HEMLIG` utan nyckelord → `avvisad=true`.

#### M5 — Anonymitetsgrinden (TF 2:18) är fail-OPEN: SSN/orgId-steget körs som default
- **Fil:** `ArendeMatchService.php:519-538`; klient-input `:519-535`; null-hook `:561-565`
- **Lagrum:** TF 2:18 (meddelarfrihet/anonymitetsskydd); OSL 26 kap; never-de-anonymise
- **Nåbarhet:** **SEAM** (dubbelt grindad: null-hook + tröskel; aktiveras när part-registret wiras)
- **Beskrivning:** `partStegAvstangt()` stänger AV SSN/orgId-matchningen ENDAST om minst en klient-levererad signal finns (`partsModell` i undantagslistan, `joinNyckel != ssn/personnummer`, eller bool-flaggan `anonym`/`anonymitetsskydd`/`sekretessSokande`). Alla tre läses ur `$rad` och är tomma/false som default. En anonym avsändare UTAN dessa fält men MED `fromSsn`/`fromOrgId` ger `partStegAvstangt()=false` → `matchaPart()` försöker matcha personidentiteten. Skyddet är opt-in via osäker klientsignal — default-vid-osäkerhet är "matcha SSN", inte "hoppa över", vilket bryter "fail mot människa / default = ej-röjt". **Ej live idag:** `registerPartHook()` returnerar hårdkodat null (`:561-565`), så ingen kandidatRef produceras och ingen re-identifiering lämnar funktionen. Även när hooken wiras blir utfallet max `foreslagen` (`KONF_PART_TAK 0.7 < DEFAULT_TROSKEL 0.9`) — aldrig tyst auto-koppling.
- **Åtgärd:** Vänd grinden till fail-closed FÖRE `registerPartHook()` wiras: kör SSN/orgId-steget ENDAST vid en positiv allow-signal (t.ex. `partsModell` i explicit ALLOW-lista OCH `joinNyckel ∈ {ssn,personnummer}`). Saknad/okänd `partsModell` → steg avstängt. Härled helst anonymitetsstatus server-side. Enhetstest: `$rad` med `fromSsn`+funktionsadress men utan flaggor → `matchaPart()=null`.

#### M6 — Idempotens-race: findByConversationId TOCTOU → dubbel-ärende
- **Fil:** `ArendeService.php:136`; migration `Version000000Date20260616000000.php:146`
- **Lagrum:** Idempotens-invarianten (`ArendeService.php:44`); GDPR/gallring (föräldralös rad)
- **Nåbarhet:** **LIVE**
- **Beskrivning:** Idempotensgrinden är ren TOCTOU: `:134-145` gör en SELECT (`findByConversationId`) och vid null fortsätter sagan och INSERT:ar (R2, `:173`). Det finns **ingen unik constraint** på `conversation_id` — migrationen lägger bara ett vanligt index (`:146`); enda UNIQUE är på `hubs_case_id` (`:142`) som myntas färskt per anrop och aldrig krockar. Två samtidiga skapa-anrop för samma conversationId passerar båda null-kollen och INSERT:ar båda → dubbel register-rad. Vägen är live via `POST /api/v1/arende` och `POST /api/v1/inflode/skapa`. **Ingen PII** dubbleras (buildEntity lagrar bara pseudonymer/routing) — det är koordinations-state, inte verksamhetsdata.
- **Åtgärd:** Lägg partiellt UNIQUE-index på `conversation_id` (`WHERE conversation_id IS NOT NULL`) i migrationen. Fånga unique-constraint-överträdelsen i R2-insert och behandla som idempotent re-läsning (`findByConversationId` och returnera vinnande raden). Alternativt DB-advisory-lock per conversationId. Applagret ensamt kan inte stänga fönstret.

### 🟢 LÅG

#### L1 — triageRef/title/label loggas på info-nivå i integrationsklienterna
- **Fil:** `DeckClient.php:82,113`; `GroupfolderClient.php:75`; `SpreedClient.php:88`
- **Lagrum:** OSL 26 kap (PII får inte loggas); GDPR art. 5.1.c (dataminimering)
- **Nåbarhet:** **SEAM** (info-loggen ligger efter `isAvailable()`-early-return; PII hamnar i logg endast om PII besegrat upstream-guarden — noll inkrementell läcka utöver M2)
- **Åtgärd:** Logga `hubsCaseId` + längd/hash i stället för råvärde, eller sänk till debug. Blir överflödig så snart M1+M2-guarden införs — knyt åtgärderna i samma PR.

#### L2 — gallrasDatum från kvittot persisteras ALDRIG på registerraden (ingen kolumn, ingen setter)
- **Fil:** `ArendeService.php:553-569`; migration `:118-126`
- **Lagrum:** GDPR art. 5.1.e (verkställbar gallrings-deadline)
- **Nåbarhet:** **TEORETISK/SEAM** (gallringsjobbet är inte byggt; nuvarande tillstånd är fail-safe)
- **Beskrivning:** Även på den korrekta vägen sätts bara `dnr` + `provenanceState` + `retentionState`. Kvittots `gallrasDatum` (committedAt+90d) skrivs aldrig — case-tabellen saknar gallrings-/retention-deadline-kolumn (`frist_due` är en handläggnings-SLA-frist, inte gallring). Motorn vet ATT raden ska gallras men inte NÄR.
- **Åtgärd:** Vid bygget av gallringsytan (eller proaktivt): lägg `gallras_datum`-kolumn (DATE, nullable) + setter, persistera `$kvitto['gallrasDatum']` i `ArendeService::commit()`. Test.

#### L3 — commit() persisterar register-flippen "best-effort" och sväljer DB-fel (state-divergens)
- **Fil:** `ArendeService.php:560-568`
- **Lagrum:** GDPR art. 5.1.e; never-SoR
- **Nåbarhet:** **TEORETISK** (ej live-nåbar idag — den enda commit-routen går runt `ArendeService::commit()`, se H2)
- **Beskrivning:** `try/catch` på `arendeMapper->update()` loggar bara `error` och fortsätter; kvittot returneras ändå som verifierat. Vid DB-fel kan facksystemet ha startat retention medan motorns rad står kvar som `ej_registrerad`/`aktiv`. Fail-safe-riktning (raden raderas inte för tidigt).
- **Åtgärd:** Gör flippen transaktionell eller signalera persist-fel till anroparen (`$kvitto['registerPersisted']=false`). Lägg idempotent reconciliation-sweep. Måste åtgärdas före en live-route wiras till `ArendeService::commit()` (H2).

#### L4 — commitDestination-override accepteras utan allowlist i createCase
- **Fil:** `ArendeService.php:582-594`
- **Lagrum:** never-SoR (commit_destination ska vara giltig, committbar rutt)
- **Nåbarhet:** **LIVE** (override-vägen) — men konsekvensen inkapslad
- **Beskrivning:** `resolveCommitDestination()` låter godtyckligt `rad['commitDestination']` vinna och validerar bara NOT NULL — ingen allowlist (`NON_FACKSYSTEM_DESTINATIONS` är deklarerad men oanvänd). Ett ogiltigt värde består INSERT men avvisas senare av `assertCommittable` (fail-closed) → kan aldrig nå ett facksystem; bryter inte never-SoR. Värsta utfall: en rad som fastnar `aktiv`/ogallrad (datakvalitet/self-DoS), ingen PII/sekretessläcka.
- **Åtgärd:** Validera mot en kanonisk `VALID_DESTINATIONS`-mängd server-side vid R2 (`:582`); kasta `InvalidArgumentException` → 400. Fail-fast vid gränsen.

#### L5 — partsModell/joinNyckel/anonym-flaggorna är klient-input, inte server-side policy
- **Fil:** `ArendeMatchService.php:519-535`
- **Lagrum:** TF 2:18; OSL 26 kap; server-side-policy-invarianten
- **Nåbarhet:** **SEAM** (neutraliserad av samma null-hook som M5; ingen effekt nås nu)
- **Beskrivning:** Endast den numeriska tröskeln är server-side (IAppConfig). Själva on/off-beslutet för SSN-steget styrs av klientlevererade `$rad`-fält. Klienten kan bara *lätta på* skyddet, aldrig skärpa — bryter "tröskel/grind ska vara server-policy". Men `registerPartHook()=null` gör operationen till en hård no-op idag.
- **Åtgärd:** Hör ihop med M5. När `registerPartHook` wiras: härled anonymitetsstatus server-side; klientfält får bara skärpa. Regressionstest.

#### L6 — Orphaned external state om pekareMapper->record() kastar efter att klienten skapat objektet (R3-R9)
- **Fil:** `ArendeService.php:252` (m.fl. R3/R4/R6/R7)
- **Lagrum:** Saga-korrekthet (`ArendeService.php:34-36`)
- **Nåbarhet:** **SEAM** (inget verkligt externt objekt skapas idag — `isAvailable()` false eller `TODO[auth]` oimplementerad)
- **Beskrivning:** Varje sido-effekt-steg skapar FÖRST det externa objektet och skriver pekaren EFTERÅT, men kompenseringen är pekar-driven. Om `record()` kastar efter `createCard` hittar kompenseringen noll pekare → kortet rivs aldrig → föräldralöst objekt. Ingen säkerhets-/sekretessgrind påverkas; objektet är koordinations-state, inte PII.
- **Åtgärd:** Vid wiring: skriv pekaren i samma try med "pending"-state INNAN det externa anropet, eller låt kompenseringen vara självförsörjande på klient-retur-iden (`card['cardId']` etc.) som redan finns i scope. Integrationstest som injicerar `DBException` i `record()` efter lyckat `createCard`.

#### L7 — inflode-action driver LIVE side-effekter med klientauktoritativ enhet/commitDestination
- **Fil:** `InfodeController.php:46-53,128-146,182-185`; `ArendeService.php:106-408`
- **Lagrum:** Least-privilege; OSL 26 kap (enhets-routing)
- **Nåbarhet:** **LIVE** — men underordnad facett av H1
- **Beskrivning:** Action-verbet är strikt allowlistat (`in_array(strict)`) före all sido-effekt — okänt verb → 400, ingen smyg-väg. Ingen SQL-injektion (QBMapper parametriserar genomgående). MEN `skapa`/`registrera` kör hela createCase-sagan med klientstyrd `$rad`, och eftersom objektnivå-auktorisation saknas (H1) kan en användare skapa ärenden för **godtycklig enhet** via `rad['enhet']`/`rad['commitDestination']`. R0-grinden körs dock fail-closed först. Detta är en andra dörr till samma rotorsak som H1.
- **Åtgärd:** Konsolidera under H1. Den gemensamma fixen (server-side enhets-authz i createCase-sagan + neka klient-override av commitDestination) täcker båda dörrarna automatiskt.

### ℹ️ INFO (positiva verifieringar + noteringar)

- **conversationId och dnr loggas/returneras som klartext-referenser** — `conversationId` är en provenans-token (mail-tråd-id), `dnr` ett facksystem-diarienummer; båda är pseudonyma routing-referenser, ingen PII. Notering, inget åtgärdsbehov.
- **401-grinden sitter i NC-kärnans middleware FÖRE controller-logik** — verifierat: alla routes svarar 401 oautentiserat, ingen `#[PublicPage]`. Korrekt.
- **createCase-vägen är genuint fail-closed mot de fyra klassiska vektorerna** — R0 körs före all sido-effekt (`ArendeService.php:110-131`); avvisad rad ger ingen register-rad, ingen tagg, inget rum. Positiv verifiering.
- **Kopplings-default vid osäkerhet är `ej_kopplat`, bilaga-spegling kräver bekräftelse** — dessa två auto-koppling-invarianter HÅLLER.
- **Retroaktiv karantän river INTE bevis** — chain-of-custody ok; pekare-rad raderas men bevismaterial bevaras.

---

## 3. Avfärdade påståenden (spårbarhet)

| Påstående | Varför avfärdat |
|---|---|
| **Auto-koppling (hor_till/STATUS_AUTO) kan ske utan att säkerhetsskydd-grinden körts** | Auto-koppling sker bara på createCase-vägen som ÄR grindad (R0 före allt); läsvägens `match()` ger aldrig auto-koppling (default `ej_kopplat`, null-hook). Nedjusterat till lag och täcks av M3. |
| **Kompenserings-no-op när klient finns men pekareMapper saknas kan dölja redan skapat objekt** | I deployad config injiceras både klient och `pekareMapper` non-null (autowiring); den asymmetriska gating-grenen nås inte. Inte ett reellt problem. |

---

## 4. Kvarvarande seams (medvetet ej implementerat) + risk tills de wiras

| Seam | Plats | Risk tills den wiras |
|---|---|---|
| **Live inflöde-feed** | `resolveInflodeRows()=[]` (`InfodeController.php:231-233`) | Idag matas `inflodeSummary` med noll rader. När `MessageReceivedEvent` (sdkmc/mail) wiras öppnas den ogrindade läsvägen (M3) och PII-bärande inflöde börjar flöda — fixa M3+M4 INNAN feeden tänds. |
| **Integrationsklienternas auth** | `TODO[auth]` i Deck/Groupfolder/Spreed/Sdkmc-klienterna (sväljer fel, ingen Authorization-header) | Idag skapas inga verkliga externa objekt (POST 401:ar → no-op). När auth wiras aktiveras M2 (PII-namngivna objekt), L1 (PII i logg) och L6 (orphaned state) på riktigt. |
| **Part-/personregister** | `registerPartHook()=null` (`ArendeMatchService.php:561-565`) | Idag matchar SSN-steget mot ingenting. När registret wiras aktiveras anonymitets-fail-open (M5) och klient-styrd policy (L5) — fixa dessa FÖRE wiring. |
| **Gallringsjobb** | Finns inte (ingen BackgroundJob läser `retention_state`) | Ingen rad gallras idag (fail-safe). När jobbet byggs behövs L2 (gallras-deadline-kolumn) och H2 (retention-flippen måste faktiskt landa) först, annars gallras inget eller godtyckligt. |
| **Live FacksystemCommitPort** | Idag stub (`FacksystemCommitStub`) | Idag skriver commit till en in-memory-stub. Dag ett en riktig port registreras blir H3 (dubbel-registrering) en verklig dubbel-skrivning mot Treserva. |

---

## 5. Rekommenderade nästa åtgärder (prioriterade)

1. **[H1 — gör först] Inför objektnivå-auktorisation över sekretessgräns.** Injicera `IUserSession`+`IGroupManager` (eller Middleware), härled auktoriserade enheter, validera `$arende->getEnhet()` FÖRE varje läs/skriv, returnera 404 vid icke-auktoriserad. Prioritera skriv-verben (tilldela/commit) och `summary()`. Lägg enhets-predikat i `ArendeMapper`. Detta stänger H1 **och** L7 (samma rotorsak via två dörrar).
2. **[H2+H3 — ett drag] Wira om commit-routen.** `ArendeController::commit()` rad 165 → `arendeService->commit(...)`. Återställer existenskoll, payload-anrikning och den verifierings-bundna provenans/retention-flippen. Lägg idempotens-spärr på `provenanceState==='registrerad'` och en stabil correlationId. Integrationstest mot OCS-routen.
3. **[M1] Server-side pseudonym-validering av objektRef/barnRef** i `buildEntity()` (positiv: kräv hash/UUID), återanvänd i `doSkapa`. Stäng samtidigt M2 (sluta använda triageRef som synligt objektnamn → använd `hubsCaseId`) och L1 (logg-disciplin) i samma PR.
4. **[M3+M4 — före feeden tänds] Centralisera R0-grinden** i ett inflöde-normaliseringslager som både write- och läs/triage-vägen passerar; utöka detektorn (handlingskod/classification/x-protective-marking, fail-closed vid okänt värde, normalisering).
5. **[M5+L5 — före part-registret wiras] Vänd anonymitetsgrinden till fail-closed** (positiv allow-signal krävs; server-side härledd status).
6. **[M6] Partiellt UNIQUE-index på conversation_id** + idempotent re-läsning vid constraint-överträdelse.
7. **[L2/L3/L6 — vid wiring av respektive seam] Gallrings-deadline-kolumn, transaktionell/signalerad commit-flipp, självförsörjande saga-kompensering.**

---

### Slutomdöme

Kärnarkitekturen är **solid och defensivt designad** — fail-closed födelseväg, symmetrisk saga, pseudonymt register, graceful no-ops och en medvetet tom feed. Den är **inte** RÖD på grund av sin design utan på grund av **två konkreta, live-nåbara wiring-/täcknings-brister**: avsaknaden av objektnivå-auktorisation (H1) och den fel-wirade commit-routen (H2/H3). Båda är väl avgränsade och åtgärdas i ett fåtal riktade ändringar. De resterande sekretess-/säkerhetsgrindarna är till stor del **latenta fail-open som idag neutraliseras av seams** — de måste härdas i samma PR som respektive seam wiras, annars öppnas de tyst. Ingen av dem läcker PII eller kringgår säkerhetsskydds-grinden på en live-väg idag.
