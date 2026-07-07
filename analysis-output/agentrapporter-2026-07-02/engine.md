# engine

## SUMMARY
hubs_arende v0.7.5 är en komplett, körbar ärendemotor med saga-orkestrerad createCase (R0–R10 + kompensering), datadrivet typregister (8 ärendetyper), fail-closed säkerhetsskyddsgrind, pliktgrind (ORO-1), verifierad commit-kedja (GAP-007) och GDPR-gallring. Motorn är verifierad på motornivå: 72 enhetstester (237 assertions) passerar just nu lokalt, och occ-smoke-kommandot kör hela resan inkl. [8b] utredning→beslut→uppföljning→avslutat för alla 8 ärendetyper — men ALLT mot in-memory-stubbar. Samtliga tre integrationsportar (Frends/facksystem, e-diarium, Inera-signering) har ENDAST stub-implementationer; ingen live-adapter finns i koden och 'live'-läge faller tyst tillbaka till stub. SIGNING-porten (SigneringPort/SigneringStub) är DI-registrerad men konsumeras inte av någon motorkod; libresign är INTE installerad (blockerad prereq: java saknas i hubs-php, beslut: Inera är den riktiga backend:en, senareläggs). Grannklienterna (sdkmc/groupfolders/deck/spreed/kalender) är riktiga OCS-anrop via service-konto (Seam A live på dev15 enligt HANDOVER), och Fas A1–F är enligt HANDOVER deployade + verifierade live 2026-06-17.

## DETAILS
# Djupanalys hubs_arende (ärendemotorn)

## 1. Version och datamodell

**Version: 0.7.5** — `hubs_arende/appinfo/info.xml:29`. NC 30–32, PHP 8.1+. 3 occ-kommandon (Smoke/Status/SeedDemo), 1 bakgrundsjobb (GallringJob), repair-step RegisterArendeTyper, adminsektion.

**5 tabeller, 3 migrations:**
- `Version000000Date20260616000000.php` — skapar 4 tabeller:
  - `hubs_arende_case` (registret): hubs_case_id (UUID v4, UNIQUE), triage_ref, objekt_ref (pseudonym, ALDRIG PII), enhet, agare_uid, status (otilldelat|tilldelat), steg (inflode→…→avslutat), dnr, provenance_state (ej_registrerad|registrerad), **commit_destination NOT NULL (schemainvariant, rad 114–117)**, retention_state, frist_due, arende_typ, conversation_id (idempotensankare), skapad.
  - `hubs_arende_typ` — datadrivet typregister (PK arende_typ_id) med plikt_grind, frist_policy (JSON), acl_profil, commit_destination NOT NULL, frends_modul, pre_saga_hook, post_commit_hook, parts_modell.
  - `hubs_arende_flagga` — cross-cutting flaggor per case.
  - `hubs_arende_pekare` — tvåvägspekare (objekt_typ: case_tag|groupfolder|deck_card|talk_room|calendar|conversation|groupfolder_ref).
- `Version000100Date20260617120000.php` — M6: UNIQUE-index på conversation_id (stänger TOCTOU-idempotensrace); L2: `gallras_datum` (verkställbar gallringsdeadline ur kvittot).
- `Version000200Date20260617140000.php` — `hubs_arende_member` (förstaklassigt medlemskap): hubs_case_id+uid+roll (mottagningskrets|handlaggare|co_handlaggare|observator), UNIQUE (case,uid,roll).

## 2. Livscykel, saga, hooks, grindar

**ArendeService** (`lib/Service/ArendeService.php`, 2392 rader) — enda skrivaren:
- **R0** SakerhetsskyddGrind FÖRE all sidoeffekt (rad 179); avvisad → AvvisadException, ev. retroaktiv karantän.
- **Idempotens** på conversationId (rad 203–214) + DB-race-fångst vid R2-INSERT (rad 296–314).
- **Pre-saga-hook** `diariefor_direkt` (kat6, rad 267–285): fail-closed — saknas EdiariumPort kastas (rad 1784–1798); ärendet FÖDS registrerat med dnr ur diariekvittot (buildEntity rad 2090–2098).
- **R2** INSERT (commit_destination-invariant valideras mot allowlist VALID_DESTINATIONS, rad 1824–1846), **R3** case:-tag via SdkmcClient, **M** medlemsledger + per-case-NC-grupp (ArenderumGroupService), **R4** groupfolder + per-case-grupp-grant, **R5** Deck-kort, **R6** Spreed-rum, **R7** kalenderobjekt (SA-ägt vid födsel, re-homas vid tilldela), **R8** frist ur inkomDatum (14d/30d/21d per fristPolicy; speglasUrTreserva ⇒ null), **R9** tagga meddelanden + referensfil, **R10** status-finalisering. Varje steg pushar kompenserande closure; fel ⇒ omvänd kompensering (rad 2194–2212).
- **dispatchHook** (rad 1754–1768): datadriven hook-dispatch, okänt hook-id = loggad no-op. Två hooks: `diariefor_direkt` (fail-closed pre-saga) och `familjeratt_yttrande` (best-effort post-commit, rad 1560–1571 — får aldrig fälla verifierat kvitto).
- **commit()** (rad 1480–1575): H3-idempotens (redan registrerad ⇒ receiptFromRegistered, porten anropas ej igen), stabil correlationId `hubs-case:{id}`, provenans/dnr/gallrasDatum flippas ENDAST på verifierat kvitto (GAP-007).
- **H1 objektnivå-authz**: assertEnhetAtkomst/enhetTillaten (rad 1656–1725) — nekad = DoesNotExistException (404, aldrig 403); system/CLI-kontext (ingen session) tillåts. **Fail-closed** utan groupManager.
- **M1/M2 PII-validering**: objektRef positiv pseudonymvalidering (personnummer-regex avvisas, rad 1857–1883), triageRef mjukvalidering.
- Handoff-avsmalning: atkomstUids (rad 2342–2360) — otilldelat ⇒ krets; tilldelat ⇒ endast handläggare (GAP-057).

**ArendeLifecycleService** (`lib/Service/ArendeLifecycleService.php`): kanonisk transitionsgraf ALLOWED_TRANSITIONS (rad 50–57), idempotent no-op på samma steg, **pliktGrind fas-spärr** (rad 112–123): pliktGrind=true (orosanmälan) blockerar forhandsbedomning→utredning utan explicit `skyddsbedomningKvitterad=true` i kontext; "inte inleda" (→avslutat) ogated. Per-steg-frist-omräkning via fristPolicy.perStegFrist.

**FacksystemCommitService** (`lib/Service/FacksystemCommitService.php`): enda vägen till facksystem-commit. assertCommittable (triage_forward/karantan avvisas), **resolveModul FAIL-CLOSED** (rad 172–181): tom frends_modul (ärv-typerna komplettering/verkstallighet) ⇒ IntegrationException, aldrig gissad modul (felrouting = sekretessincident). Kanonisk config-nyckel `integration_mode_facksystem` (tidigare bugg med avvikande nyckel fixad, dokumenterat i klassdoc rad 34–41).

**SakerhetsskyddGrind** (`lib/Service/SakerhetsskyddGrind.php`): fail-closed på tom rad, detektorfel, strukturerade klassnycklar (M4: handlingskod/classification/x-protective-marking m.fl., rad 95–104 — närvaro av nyckel med icke-explicit-öppet värde ⇒ avvisad), nyckelordsfallback (diakrit-normaliserad), visselblåsning = samma hårda gräns. Retroaktiv karantän (evaluateRetroaktiv, rad 255–350): 5 R-retro-steg, registerflip till karantan körs alltid; externa steg graceful med auditbart kvitto (fullstandig=true/false).

## 3. Integrationer — vad är stub, vad är kontrakt

**Arkitektur:** Port-interface + stub + (framtida) live, valda per port via app-config `integration_mode_{facksystem|signering|ediarium}` i `lib/AppInfo/Application.php:67–93`. **modeMap innehåller ENDAST 'stub'** — okänt/'live'-värde faller tillbaka till stubben (resolvePort rad 125). **Ingen Live/-katalog eller live-adapter finns i koden.**

- **FacksystemCommitPort** (`lib/Integration/Port/FacksystemCommitPort.php`) — kontraktet mot Frends iPaaS → Treserva/Lifecare/Viva: commit() (preliminärt kvitto i async), registerCallback() (korrelationsnyckel), verifyCallback() (ENDA punkten där retention startar, idempotent på token). Kvitto-shape: {ok,dnr,committedAt,gallrasDatum,verifierad,hubsCaseId,modul,receipt}. Moduler: ifo_barn|ifo_vuxen|ao|lss|ek_bistand|familjeratt.
- **FacksystemCommitStub** (`lib/Integration/Stub/FacksystemCommitStub.php`) — stateful in-memory, deterministisk dnr '2026-IFO-NNNN' (seq från 500), synchronousCallback=true default (DI bygger med defaults ⇒ synkron), fel-/timeout-injektion via csv-listor. OBS: state är per-PHP-process — async-pending-tokens överlever inte mellan HTTP-requests.
- **EdiariumPort/EdiariumStub** — FGS-kontrakt (registrera=diarieföring med diarienummer 'SN-2026-NNNN', arkivera=SIP-paket). Konsumeras av kat6 pre-saga-hook och kat8 post-commit-hook.
- **SigneringPort/SigneringStub** — Inera Underskriftstjänst-kontrakt (requestSignature/pollStatus/fetchSignedDocument, PAdES-B-LTA). **DI-registrerad men INGEN konsument i motorkoden** (grep: enbart Application.php refererar SigneringPort). Signering är alltså kontrakt-only.
- **SIGNING-INERA/libresign:** libresign finns INTE i koden (0 träffar i lib/). `provision/manifest.yaml:149–167`: libresign är **blockerad prereq** (java saknas i hubs-php), beslut 2026-06-17: SENARELÄGGS — stubben räcker i v1 och **Inera Underskriftstjänst (EJ libresign) är den beslutade riktiga backend:en**.
- **Grannklienter (R3–R9)** — `lib/Integration/Client/*.php` (Sdkmc/Groupfolder/Deck/Spreed/Calendar, 1890 rader): RIKTIGA OCS/HTTP-anrop via IClientService med **ServiceAccountAuth** (Basic-auth, app-config sa_user/sa_token, `lib/Integration/ServiceAccountAuth.php`). Utan credential ⇒ 401 sväljs graceful. Enligt HANDOVER är Seam A LIVE på dev15 (uid hubs-arende-svc) och sagan skapar riktiga rum/kort/taggar där.
- **Ingen async-callback-route:** `appinfo/routes.php` saknar endpoint för Frends verifyCallback — endast den synkrona stub-vägen är exekverbar idag.

## 4. Smoke + tester

**`lib/Command/Smoke.php`** (occ hubs_arende:smoke) bevisar mot stubbarna: [1] createCase orosanmälan (frist=+14d), [2] idempotens på conversationId, [3] commit → verifierat kvitto, [4] provenansflip + retention + gallras_datum, [5] säkerhetsskyddsavvisning (sakerhetsklass=hemlig), [6] idempotent commit H3 (samma dnr, ingen dubbelregistrering), [7] M1 personnummer-objektRef avvisas före sagan, [8] pliktgrindad transition till utredning, **[8b] HELA RESAN utredning→beslut→uppfoljning→avslutat (rad 144–157)** — bevisar att livscykeln kan slutföras hela vägen; avslut = ren stegövergång, ingen ny facksystemregistrering, [9] GDPR-gallring (purge vid now=+100d). Därefter **PER-TYP-loop över alla 8 ärendetyper** (rad 184–261): förväntad commit_destination, kat6 föds-registrerad+dnr+diarieförd (introspektion diariumCount mot EdiariumStub), kat6 commit dubbel-diarieför EJ, kat8 post-hook diarieför yttrande, komplettering/verkstallighet commit **FAIL-CLOSED** (ärv-modul saknas), + pliktgrind ORO-1 (blockerad utan kvittens / tillåten med).

**tests/**: 14 testfiler, **72 testmetoder — kördes nu lokalt (docker composer:2, PHP 8.5.7, PHPUnit 10.5.63): OK, 72 tester, 237 assertions**. Täcker: lifecycle-graf+pliktgrind (8), match-kaskad TF 2:18-anonymitet (4), H1-authz inkl. deny-som-404 (5), commit-idempotens H3 (3), hooks kat6/kat8 inkl. fail-closed/graceful/no-refire (7), objektRef-PII-validering (5), pekarblock (4), createCase-invarianter+grind+retroaktiv (8), typregister 8 rader (7), gallring säkerhetsvakt (4), M4 strukturerade klassnycklar (5), grind-basfall (6), modul-failclosed (2), stub GAP-007 (4). CI (`.github/workflows/ci.yml`): php-lint + phpunit på PHP 8.1/8.2/8.3. OBS: `tests/README.md:47` säger "25 tester" — inaktuellt.

## 5. Docs

- **`docs/HANDOVER.md`** (2026-06-17): Fas A1–F byggda, "allt deployat + verifierat live" på dev15 — member-tabell, mottagningskrets (20 medlemmar), handläggarägd kalender (SA→admin re-home), ärlig koppling, adversariell sekretessgranskning (11 äkta fynd, 0 FP) + 5 fixar, per-ärende-isolering (Fas E), referensfiler (F1), synliga taggar i sdkmc (F2 — visuell mailbekräftelse ÅTERSTÅR), KopplaValjare (F3). Öppna trådar: F2 sista milen, F4 (NewMessagesClassifier saknas i sdkmc), härdning (kvot/rate-limit, unik-constraint pekare), produktion (SA med lägsta rättighet — idag ADMIN; credential ur vault; gallring river ej externa rum).
- **`docs/FAS-F-DESIGN.md`**: rubriken säger "DESIGN, ej byggd" men F1–F3 är byggda enligt HANDOVER — statusraden är inaktuell. Kärninsikt: IDOR-blockeraren var transporten (service-kontot), fix = Väg A (user-session-tagg i frontend). Referens ≠ kopia (NEVER-SoR), hashade filnamn.

## 6. De 8 ärendetyperna

Seedas INTE via provision/ (den katalogen är miljö-bootstrap: appar, DB, service-konto) utan via **`ArendeTypRegistry::defaultRows()`** (`lib/Service/ArendeTypRegistry.php:159–360`) + repair-step `RegisterArendeTyper` (post-migration, idempotent, bevarar lokala ändringar): orosanmalan (pliktGrind, 14d-frist, ifo_barn), ansokan_bistand (ifo_vuxen), ekonomi (ek_bistand), komplettering (frendsModul=null ⇒ ärver, commit fail-closed), vard_samverkan (ao, koordinering ej frist), rattsligt_tvang (diarium, preSagaHook=diariefor_direkt), verkstallighet (frendsModul=null), familjeratt (flerpartsarende, postCommitHook=familjeratt_yttrande).

**Körda end-to-end:** alla 8 på MOTOR-nivå (smoke per-typ-loop, mot stubbar, verifierat körbart via testsviten + smoke-kodens deterministiska assertions). **Live/GUI end-to-end: endast orosanmälan** (live GUI E2E på dev15 2026-06-18 enligt minnesanteckning hubs-orosanmalan-livetest: create-flödet verifierat, 3 fixar deployade). DemoSeedService kör 10 demo-ärenden över alla 8 typer genom den riktiga motorn (createCase→transitions→tilldela→commit) på dev15 — det är motornivå med riktiga grannappar, inte GUI-flöden per typ.

## DEMO_OR_STUB
- FacksystemCommitStub (lib/Integration/Stub/FacksystemCommitStub.php) — hela Treserva/Frends-commiten är in-memory-stub med syntetiska dnr '2026-IFO-NNNN'; gateas via app-config integration_mode_facksystem (default 'stub'); INGEN live-adapter finns, 'live'-värde faller tyst tillbaka till stub (Application.php:125)
- EdiariumStub (lib/Integration/Stub/EdiariumStub.php) — e-diarium/e-arkiv (FGS) stub, syntetiska diarienummer 'SN-2026-NNNN'; gateas via integration_mode_ediarium; konsumeras av kat6/kat8-hooks
- SigneringStub (lib/Integration/Stub/SigneringStub.php) — Inera Underskriftstjänst-stub (syntetisk PAdES-PDF); gateas via integration_mode_signering; porten har INGEN konsument i motorkoden alls (endast DI-registrerad)
- libresign — INTE installerad/wirad; provision/manifest.yaml:149–167 markerar den blockerad-prereq (java saknas) och senarelagd; Inera (ej libresign) är beslutad riktig backend
- InfodeController::resolveKorgar()/resolveInflodeRows() (lib/Controller/InfodeController.php:394–407) — returnerar [] (inget live-inflödesflöde är wirat; feed:en lever i sdkmc/mail); inflode-summary blir därmed tom struktur
- ArendeService::dashboardSummary() (lib/Service/ArendeService.php:711–739) — puls-nycklarna motenIdag/attSignera/nyaInflode/omnamnanden hårdkodade 0 ('honest zeros', medvetet ej fabricerade); triage/moten alltid []
- ArendeService::treservaReceipts() (rad 953–988) — committedAt approximeras med skapad (ingen separat commit-tidsstämpel), kalla-strängen 'Frends → facksystem (verifierad commit)' är etikett, datat kommer ur stub-commit
- DemoSeedService (lib/Service/DemoSeedService.php) — 10 syntetiska demo-ärenden (prefix 'demo-'), hårdkodad demo-handläggare '197411040293' (rad 53); körs via occ hubs_arende:seed-demo [--purge] och admin-OCS-endpoint
- kopplaMeddelande durabel admin-tagg (ArendeService.php:1416, 1463–1468) — AVSTÄNGD by default (IDOR-skydd); aktiveras via config koppla_admin_tag=1; default ger verifierad=false
- ServiceAccountAuth (lib/Integration/ServiceAccountAuth.php) — utan sa_user/sa_token i app-config degraderar alla grannanrop till graceful 401-no-op (stub-liknande beteende lokalt; LIVE på dev15 enligt HANDOVER)
- SakerhetsskyddGrind::detectIndicator (rad 353–418) — TODO[detection]: konservativ nyckelords-/strukturfälts-heuristik, ingen riktig klassificerare/org-register än (fail-closed default)
- ArendeMatchService steg 3 (SSN/orgId-part-matchning) — TODO[register]-hook med lokal konfidensheuristik, fail-closed bakom allow-grind tills partsregister wiras
- Ingen async-callback-route i appinfo/routes.php — FacksystemCommitPort::verifyCallback har ingen HTTP-endpoint; endast synkron stub-väg är exekverbar (stub-state dessutom in-memory per request)

## VERIFIED_WORKING
- Hela testsviten passerar NU: 72 tester, 237 assertions, OK — körd lokalt i denna analys via docker composer:2 (PHP 8.5.7, PHPUnit 10.5.63) mot hubs_arende/phpunit.xml
- Saga-invarianter enhetstestade: commit_destination NOT NULL, idempotens på conversationId, R0-grind avvisar utan INSERT, retroaktiv karantän, verifierat kvitto flippar provenans / overifierat gör det INTE (ArendeServiceTest, 8 tester)
- PliktGrind ORO-1: blockerar forhandsbedomning→utredning utan kvittens, släpper med, gatar aldrig 'inte inleda' eller icke-plikttyper (ArendeLifecycleServiceTest, 8 tester)
- Hooks kat6/kat8: diariefor_direkt föds-registrerad + fail-closed utan port; familjeratt_yttrande fyrar post-commit, är graceful vid fel, re-fyrar ej vid idempotent commit (ArendeServiceHookTest, 7 tester)
- Fail-closed modulrouting: commit utan frends_modul kastar IntegrationException (FacksystemCommitServiceModulTest) — ärv-typerna komplettering/verkstallighet kan inte felroutas
- GAP-007 i stubben: retention/gallrasDatum sätts ENDAST på verifierad callback, verifyCallback idempotent (FacksystemCommitStubTest, 4 tester)
- H1-authz: fel grupp nekas som 404 (ingen existens-läcka), system/CLI tillåts, rätt grupp släpps (ArendeServiceAuthzTest, 5 tester)
- M1/M4 PII/klass-validering: personnummer-objektRef avvisas, handlingskod/classification/x-protective-marking-närvaro avvisas fail-closed (ObjektRefValidering + SakerhetsskyddGrindM4, 10 tester)
- GDPR-gallring med dubbel säkerhetsvakt: purgar endast registrerad+deadline-passerad rad, raderar pekare+member+per-case-grupp före registerraden (GallringServiceTest, 4 tester)
- Smoke-kommandots kod (lib/Command/Smoke.php) implementerar deterministiska pass/fail-checks för hela resan inkl. [8b] och alla 8 typer — enligt docs/HANDOVER.md och git-historik körd grön på dev15 (motornivå mot stubbar; ej omkörd i denna analys)
- Enligt docs/HANDOVER.md (2026-06-17): Fas A1–F deployade + verifierade LIVE på dev15 med riktigt service-konto (Seam A) — mottagningskrets 20 medlemmar, kalender-re-home SA→admin, per-case-grupp revokerar admin vid tilldelning, referensfil landar+städas; live GUI-E2E för orosanmälan-create verifierad 2026-06-18 (minnesanteckning)

## RISKS
- 'live'-INTEGRATION_MODE är en tyst no-op: Application::resolvePort (lib/AppInfo/Application.php:125) faller tillbaka till stubben för alla okända lägen — en felkonfigurerad prod-miljö skulle minta syntetiska dnr som ser verifierade ut (kvittot säger 'verifierad':true, kalla 'Frends → facksystem')
- Ingen HTTP-endpoint för async-callbacken (verifyCallback) finns i routes.php och stub-state är in-memory per request — den asynkrona Frends-modellen är kontrakt-only och helt oövad utanför synkron in-process-väg
- SigneringPort har noll konsumenter — hela signeringsflödet (beslut→PAdES) är okopplat till livscykeln; 'attSignera' i dashboarden är hårdkodad 0
- Dokumentationsdrift: Integration/README.md §2 anger fel config-nycklar ('integration.facksystem' i stället för kanoniska 'integration_mode_facksystem') och beskriver en live-fallback-mekanism som inte finns i koden; tests/README.md säger 25 tester (är 72); FAS-F-DESIGN.md-rubriken säger 'ej byggd' fast F1–F3 är byggda; provision/manifest.yaml anger version 0.1.0 (är 0.7.5)
- Service-kontot på dev15 ligger i admin-gruppen och credential i app-config (ej vault) — HANDOVER flaggar själv detta som produktionsrisk
- Gallring river pekare+grupp+member men INTE de externa rummen (groupfolder/talk) via klienterna — kvarlämnade rum efter gallring (känt, pre-existing enligt HANDOVER §Öppna trådar 4)
- enhet→grupp-mappningen är konventionsbaserad (normaliserat gruppnamn = enhet, TODO[konfig] i ArendeService.php:1697) — namnkollision i NC-grupper kan ge fel mottagningskrets; larm saknas (HANDOVER härdningspunkt 3)
- Ingen unik-constraint på (hubs_case_id, objekt_typ) för deck/talk/calendar-pekare — dubbletter möjliga (känd härdningspunkt)
- Endast orosanmälan är GUI/live-verifierad end-to-end; övriga 7 typer är enbart bevisade på motornivå mot stubbar — särskilt ärv-vägen (komplettering/verkstallighet) saknar hela attach-flödet (värd-ärendets modul trådas aldrig in i payload)

## NEXT_STEPS
- Bygg live-FacksystemCommitPort (Frends-adapter, IClientService) + OCS-callback-route för verifyCallback med persistent pending-state (DB, inte in-memory) — och gör 'live'-läge utan registrerad adapter till ett hårt fel i stället för tyst stub-fallback
- Wira SigneringPort in i livscykeln (beslutssteget) eller ta bort den ur DI tills den behövs; besluta Inera-adapterns plats i saga/commit-flödet
- Synka dokumentationen: Integration/README.md-nycklarna, tests/README-testantal, FAS-F-DESIGN-statusrubrik, manifest.yaml-version
- Kör GUI/live-E2E för minst en typ per särfall: rattsligt_tvang (pre-saga-diarieföring), familjeratt (post-commit-yttrande), komplettering (attach/ärv-modul — kräver att attach-vägen byggs)
- Härdning från granskningen: kvot/rate-limit på laggTillTalkrum/laggTillGroupfolder, larm vid enhet-grupp-namnkollision, unik-constraint på pekare (case, objekt_typ) för 1:1-typerna
- Produktionsförberedelse service-konto: lägsta rättighet (ej admin), credential ur vault, samt gallring som även river externa rum via klienterna
- F2 sista milen: koppla ett riktigt mail och bekräfta visuellt 'Ärende {ref}'/'Behandlad'-taggarna i sdkmc-klienten; därefter F4 (NewMessagesClassifier) för live-inflödesfeeden så inflode-summary slutar vara tom