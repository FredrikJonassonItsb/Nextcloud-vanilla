# frontend

## SUMMARY
hubs_start är på version 1.2.15 (appinfo/info.xml:21) med 62 Vue-komponenter under src/components (25 generella + 37 i socialsekreterare/), allt monterat via en tunn PHP-backend (PageController injicerar initial state 'boot'). Demoläget styrs i tre lager: app-config hubs_start/demo_mode ('1'/'0'/AUTO när sdkmc saknas) i PageController::isDemoMode(), plus en klient-override ?demo=1/0 som persisteras i sessionStorage('hubs_demo') i demoData.js::isDemo(); api.js kortsluter varje nätverksfunktion till fixtures när DEMO är sant. Ärende-funktionerna är live-wirade mot hubs_arende OCS och meddelande-/mötesytan mot sdkmc, men tre saker är demodata oavsett läge: NyttaWidgets "indikativa" siffror, persona-dashboardens variantwidgets (demoWidgets.js) och sdkmc-feedens egen flagga hubs_start_inflode_demo. 88/88 jest-tester passerar (kört i denna session).

## DETAILS
## 1. Version och komponentinventering

**Version:** `appinfo/info.xml:21` = **1.2.15**. NC min 30 / max 32, PHP >= 8.1. Navigation registrerad med order=1 (rad 38–46), admin-settings (rad 47–50), occ-kommando `OCA\HubsStart\Command\SeedFavoriter` (rad 56–58, DEV/DEMO-seed av syntetiska favoriter). OBS: `package.json:3` säger version 1.0.0 — inte synkad med info.xml.

**Komponentantal:** 64 .vue-filer totalt = `src/App.vue` + `src/views/Start.vue` + **25 i src/components/** + **37 i src/components/socialsekreterare/**.

**src/components/ (25), kort:**
- `WidgetRenderer.vue` — resolver: 8 "riktiga" widgets (live från store) → variantkomponent med demo-descriptor → `WidgetFallback` ("föreslagen funktion"). Se rad 89–116.
- Riktiga (live via store): `AttHanteraQueue` (triage-kön, state.items/counts), `DagensMoten` (state.meetings), `KvittensWidget` (state.receipts), `FunktionsbrevladorWidget` (state.mailboxes), `BevakningarWidget` (state.watching), `BokningsbaraTider` (state.appointmentConfigs), `NyttaWidget` (se §4 — placeholder), `SystemHalsa` (egna axios-anrop, se §4).
- Skal/UI: `HeaderBar` (hälsning, LOA-chip, personaswitcher — switcher visas bara i demo, HeaderBar.vue:19), `ActionBar`, `LoaChip`, `Onboarding`, `PersonaSwitcher`, `CommandPalette` (Ctrl+K), `QueueItem`/`QueueSection`, `SmartMottagare` (mottagarsök + server-klassning), `MeetingWizard` (boka säkert möte).
- Variant/demo: `WidgetQueue`, `WidgetProgress`, `WidgetStat`, `WidgetFiles`, `WidgetFallback`, `WidgetProvenance` (visar backing-app + system-of-record).

**src/components/socialsekreterare/ (37), kort:** `MinaArenden` (huvudvyn, zon 0–5), `MinDagHeader`+`Dagspulsen` (puls-räknare), `VadVillDuGora` (verbingång), `KorgValjare` (korg-piller), `AttTaEmotSektion`+`AttTaEmotRad` (band 1a), `AttHanteraSektion` (1b), `EjKoppladSektion`+`EjKoppladRad` (1c), `InflodeRad` (delad radkomponent 1b/1c), `KopplaValjare`, `KopplingBadge`, `FristChip`, `FristPanel`, `ArendeZon`+`ArendeKort`+`ArendeDiskussion`+`DiskussionChip`+`ProcessStepper`+`ProvenansChip`+`SekretessRad`+`NastaAtgardKnapp` (ärendekorten), `MotesRemsa`+`MotesRad` (zon 4), `TreservaKvittens` (kvittens/retention-ytan), `CommitGrind` (Treserva-commit med inbäddad signering, #6), `AvslutaGrind`, `GallringsGrind`, `MinaAnteckningar` (#12 privata anteckningar), `EnhetschattPanel`, `FavoritValjare`, `FordelningsVy`+`FordelaTill`+`TilldelningBand`+`UtredarLast` (gruppledarläge), `OnboardingTour`, `TreservaKvittens`.

## 2. Boot / initial state / demoläge

**Server:** `lib/Controller/PageController.php:47–89` — `index()` injicerar initial state **'boot'** via `IInitialState::provideInitialState('boot', $boot)` (rad 78). Live-gren (rad 62–76): `apps` från `AppDetectionService::detect()` (IAppManager, lib/Service/AppDetectionService.php:38–46), `profile` från `RoleService::getProfile()` (gruppmappning: admin→forvaltare, hubs-registrator→registrator, annars handlaggare; lib/Service/RoleService.php:42–57), `channelCoverage`, `prefs` (PreferencesService), `loa: 'LOA3'` (säkert default, uppfräschas live via sdkmc getSettings), `persona` ur app-config `default_persona` (rad 75). Demo-gren (rad 48–61): allt tvingat — alla appar =true, profile=forvaltare, full channelCoverage, onboardingSeen=true.

**Demo-flaggans resolution (server):** `PageController::isDemoMode()` rad 97–107: app-config `hubs_start/demo_mode` `'1'`→PÅ, `'0'`→AV, annars **AUTO = PÅ när sdkmc inte är installerad**.

**Demo-flaggans resolution (klient):** `src/services/demoData.js:31–47` `isDemo()`: (1) URL `?demo=1`/`?demo=0` skrivs till `sessionStorage['hubs_demo']` och overriden vinner; (2) annars `boot.demoMode === true`. "Visa i demoläge"/"Lämna demoläge"-länken i footern (`MinaArenden.vue:169–171`, computed `demoLank` rad 391–393) togglar via `?demo=1/0` + full omladdning.

**Kritisk mekanik:** `api.js:60` — `const DEMO = isDemo()` evalueras EN gång vid modulladdning; varje exportfunktion börjar med `if (DEMO) return <stub>`. Store läser samma flagga i `bootFromInitialState()` (`src/store/index.js:95` `state.demoMode = isDemo()`).

**Vad demoläget påverkar:** (a) all data ersätts av fixtures (`demoData.js`, `demo/socialsekreterare.js`); (b) utgående djuplänkar blockeras med notis (`Start.vue:240–246` `demoBlocked()`); (c) persona-växlare + läge-växlare (utredning/fördelning) visas bara i demo (`MinaArenden.vue:9`, `HeaderBar.vue:19`); (d) mutationer blir optimistiska no-ops.

**Reset:** `lib/Controller/AdminController.php:69–103` `reseed()` (ADMIN-ONLY OCS POST /api/v1/admin/reseed) sätter baseline: `hubs_start/demo_mode='0'`, `default_persona='socialsekreterare'`, **`sdkmc/hubs_start_inflode_demo='1'`** — dvs sdkmc:s inflödesfeed har en EGEN demo-flagga utanför hubs_start — plus idempotent favorit-seed.

## 3. Store, api.js-kontraktet, deepLinks

**Store** (`src/store/index.js`): Vue 2.7 `Vue.observable`, inget Pinia/Vuex. State-shapen är kontrakt (docs/CONTRACTS.md). Två datadomäner: den generella (items/counts/mailboxes/receipts/meetings, polling 30 s, rad 18/422–428) och `state.arende` (socialsekreterar-slicen, rad 46–71). `refreshSummary()` är icke-fatal (rad 119–129) — på dev15 där sdkmc saknar aggregatendpoints förblir widget-summaryn tom utan att blocka. Viktiga actions: `loadArende(ref)` med stabil cache-nyckel triageRef (rad 203–217), `enrichArende` (sdkmc-berikning via talkToken, merge utan att skriva över motor-fält, rad 227–242), `commitArende` (provenans/retention flippas ENDAST på `r.verifierad`, rad 249–277), `inflodeAction` med honest-error på skapa (rad 366–392).

**Endpoints frontend förväntar sig** (alla i `src/services/api.js`, tre baser rad 62–69):
- **hubs_start egen OCS** (finns i denna app, `appinfo/routes.php`): GET/PUT `/apps/hubs_start/api/v1/preferences`; POST `/api/v1/admin/reseed`.
- **hubs_arende OCS** (🔌 LIVE, ärendedomänen): GET `/arende-summary` (rad 361), GET `/arende/{ref}` (375), POST `/arende` (540, saga R0–R10), POST `/arende/{ref}/steg` (575), POST `/arende/{ref}/tilldela` (591), POST `/treserva/commit` (394), GET `/treserva/receipts` (419), GET `/fordelning-summary` (622), POST `/inflode/koppla` (317) och `/inflode/{skapa|koppla|registrera|gallra}` (650–652 boundary-routing).
- **sdkmc OCS**: `/summary`, `/receipts`, `/recipients/search`, `/recipients/classify`, `/meetings/today`, `/secure-meeting`, `/meetings/{token}/lobby`, `/note-to-self` (GET/POST), `/arende-enrichment`, `/favoriter`, **`/inflode-summary`** (rad 609 — medvetet sdkmc, INTE hubs_arende: motorns `resolveInflodeRows()` är tom, se kommentar rad 603–608), `/team`, `/inflode/{besvara|vidarebefordra|…}`.
- **sdkmc legacy index.php**: `/api/v2/frontend/getSettings`, `/api/thread/tags/{label}`, `/api/messages/{id}/tags/{label}`, `/api/v2/spreed/guest-identity/...`, samt SystemHalsas `/api/v2/iipax/sdkLog`, `/api/v2/admin/activityNotificationStatus`, `/api/v2/admin/runExpungeNow`.
- **calendar**: `/apps/calendar/v1/appointment_configs` (rad 344).

`kopplaMeddelandeTillArende` (rad 305–331) och `skapaArende` (rad 514–561) innehåller genomarbetad boundary-logik: 'inf:'-prefix-strippning, PII-fri payload (motorn avvisar personnummer), korg→arendeTyp-keywordrouting (rad 462–489), best-effort user-session-taggning (aldrig fäller skapat ärende).

**deepLinks.js:** `threadLink` (sdkmc mailbox-link-redirect), `composerLink` (`/apps/mail/new?type=&to=&case=` — kräver mail-routerhooken i `backend-additions/mail`), `deckLink` (404-säker fallback till boardlistan), `callLink`/`spreedRoomLink` (null-safe — ärligt tomt i stället för hårdkodat rum), `arenderumLink` (Files dir=hubsCaseId), `fileLink`, `loa3UpgradeLink`, `resolve()` för QueueItem.deepLink-descriptorer.

## 4. Riktig data vs demo per komponent

| Komponent | Datakälla | Läge |
|---|---|---|
| `NyttaWidget` | `PLACEHOLDER_VOLUME`/318/207 hårdkodat (rad 75–84); `state.nytta` föredras men **ingen kod producerar den någonsin** | **Stub i BÅDA lägena**; UI deklarerar ärligt "Siffrorna är indikativa…" (rad 51–54) |
| `MotesRemsa` | props `meetings` = `state.meetings` ← `fetchTodaysMeetings()` (sdkmc `/meetings/today` live / fixtures demo). MinaArenden binder medvetet den LIVE-ytan, inte arende-summary.moten (#17, MinaArenden.vue:319–323) | Riktig i live, demo i demo |
| `InflodeRad` + 1a/1b/1c-sektionerna | `fetchInflodeSummary()` → **sdkmc** `/inflode-summary` live / ssDemo demo. Kommentar InflodeRad.vue:221: excerpt "Saknas på live-feeden idag". sdkmc-sidan har egen demo-flagga `hubs_start_inflode_demo` | Blandat: rätt endpoint live, men feedens innehåll kan självt vara sdkmc-demo |
| `Dagspulsen`/`MinDagHeader`/`ArendeZon`/`ArendeKort` | `A.puls`/`A.arenden` ← hubs_arende `/arende-summary` live / ssDemo | Riktig i live |
| `TreservaKvittens` | `A.receipts` ← hubs_arende `/treserva/receipts` live / treserva-stub demo | Riktig i live |
| `FavoritValjare` | `A.favoriter` ← sdkmc `/favoriter` live / `demo/favoriter.js` | Riktig i live |
| `FordelningsVy` | hubs_arende `/fordelning-summary` live / ssDemo | Riktig i live |
| `EnhetschattPanel` | sdkmc `/team` live / ssDemo | Riktig i live |
| `SystemHalsa` | **Direkta axios-anrop** (går förbi api.js, ingen demo-gren) mot sdkmc iipax/admin-endpoints (rad 177, 192, 206, 227) | Alltid live-försök; i demo/utan sdkmc → felstates ("Status ej tillgänglig") |
| `AttHanteraQueue`/`KvittensWidget`/`DagensMoten`/`FunktionsbrevladorWidget`/`BevakningarWidget`/`BokningsbaraTider` | store ← sdkmc `/summary`, `/receipts`, `/meetings/today` resp. calendar — kräver `backend-additions/sdkmc` installerad | Riktig i live OM sdkmc-aggregaten finns; annars ärligt tomt (icke-fatalt) |
| `WidgetQueue`/`WidgetProgress`/`WidgetStat`/`WidgetFiles` (persona-dashboards ~29 widgets) | `demoWidgets.js` (queues-a/b, progress, stats, files, extra, newcases) via `WidgetRenderer.vue:96–107` | **Alltid demo-descriptors, gateas INTE av demoMode** — "föreslagna" funktioner; `WidgetFallback` när descriptor saknas |

Personor: 6 st i `personaConfig.js` (socialsekreterare, registrator, hsl_skoterska, hr_chef, overformyndare, forvaltare). Live landar på `boot.persona || defaultPersonaId` (store rad 103) = socialsekreterare (default_persona på dev15) → den motor-backade MinaArenden-vyn; övriga personor är i praktiken demo-showcase.

## 5. Öppna punkter i PUNCHLIST/README/DEMO

`PUNCHLIST.md` (⏳ rad 44–91, alla blockers/majors markerade fixade): backend-sidan — receipt `updated_at`-kolumn saknas; PENDING-semantik ej bekräftad mot MW; SummaryController/Service-receipt-duplicering; fabricerad `$assignee_{userId}`-label ("Ta ärendet" kan targeta obefintlig tagg); INBOX-predikatkolumn overifierad; cross-app route-safety (linkToRouteAbsolute kastar om hubs_start disablas); secure-meeting loopback-credentials; intent eventUid-matchning; `ConversationBankIDAuthMapper::findByConversation()` saknas; `resolveLoa()` hårdkodad LOA3. Frontend — SystemHalsa aria-live för gallrings-"armed". Mail-hook — SDK `?to=` kan inte prefilla adresspar; ingen named `/new`-route. OBS: PUNCHLIST daterar sig till byggets slut (13 juni) och gäller primärt `backend-additions/sdkmc`.

`README.md`: arkitekturbeskrivning (standalone-app, sdkmc äger datat, en aggregerings-endpoint, Vue 2.7); inga egna öppna punkter. `DEMO.md`: dokumenterar demoläget för vanilla-NC-instansen (stub-tabellen, av/på via occ) och noterar att backend-additions INTE är installerade där. `docs/FRONTEND-WIRING.md` och `docs/DEMO-STUBS.md` är de aktuella auktoritativa dokumenten för live-wiring resp. SEAM-registret (sök `SEAM[` i koden).

## DEMO_OR_STUB
- src/services/demoData.js (hela filen) — fixtures för generella vyn; gateas av isDemo(): boot.demoMode (server: app-config hubs_start/demo_mode '1'/'0'/AUTO-på-sdkmc-frånvaro, PageController.php:97-107) med klient-override ?demo=1/0 persisterad i sessionStorage['hubs_demo'] (demoData.js:31-47)
- src/services/demo/socialsekreterare.js + demo/treserva.js + demo/favoriter.js — stateful ärende-/Treserva-/favorit-stubbar; gateas av DEMO-konstanten i api.js:60 (if (DEMO) return ssDemo.* på rad 358, 371, 387, 406, 417, 515, 602, 620, 632)
- src/services/demoWidgets.js + src/services/demo/{queues-a,queues-b,progress,stats,files,extra,newcases}.js — persona-dashboardens ~29 variantwidgets; gateas INTE av demoMode: WidgetRenderer.vue:96-107 använder descriptorFor() alltid när widget-id saknar real-komponent
- src/components/NyttaWidget.vue:75-84 — PLACEHOLDER_VOLUME {sdk:142, secure:86, internal:54, fax:0, sms:23} + PLACEHOLDER_REPLACED_FAX=318 + PLACEHOLDER_REPLACED_LETTERS=207; gateas bara av att state.nytta finns — men ingen kod sätter state.nytta ⇒ aktiv ÄVEN i live-läge; UI-disclaimer 'indikativa' på rad 51-54
- lib/Controller/PageController.php:48-61 — demo-boot-stub (alla appar true, profile forvaltare, full channelCoverage, onboardingSeen true); gateas av isDemoMode()
- src/services/api.js:306 — kopplaMeddelandeTillArende demo-kvitto { ok:true, verifierad:false, demo:true }; api.js:574 transitionSteg { ok:true }; api.js:590 tilldela { ok:true }; api.js:646 inflodeAction optimistisk { ok:true } — alla gated av DEMO
- lib/Controller/AdminController.php:71-75 — reseed skriver sdkmc/hubs_start_inflode_demo='1': sdkmc-inflödesfeeden har en EGEN demo-flagga utanför hubs_start (live /inflode-summary kan alltså själv servera demo-rader)
- lib/Command/SeedFavoriter.php + lib/Service/FavoriterSeedService.php — DEV/DEMO occ-seed av syntetiska favoriter (deklarerat i info.xml:51-58)
- src/views/Start.vue:230-246 — proposedNotice()/demoBlocked(): i demo visas notis i stället för navigation; 'föreslagna' widgets/åtgärder visar alltid demonstrations-notis
- Demo-persona-UI: PersonaSwitcher + läge-växlaren utredning/fördelning renderas bara när state.demoMode (MinaArenden.vue:9, HeaderBar.vue:19,56)

## VERIFIED_WORKING
- Jest-sviten: 11/11 suites, 88/88 tester PASSERAR — kört i denna session (npx jest i hubs_start/): store, storeEnrich, skapaArende (typ-mappning/prefix-strip), deepLinks, channels, sections, commitGrind, avslutaGrind, inflodeRad, arendeDiskussion, minaAnteckningar
- Produktionsbundle finns byggd: hubs_start/js/hubs_start-main.js + async-chunks (senast byggd 2026-06-21), monteras via templates/index.php (#hubs-start) + Util::addScript i PageController.php:80
- Demo-toggle-kedjan är komplett i kod (läst och verifierad väg): PageController::isDemoMode → boot.demoMode → demoData.isDemo (?demo=1/0 + sessionStorage) → api.js DEMO-grenar → footer-länken i MinaArenden.vue:392
- Live-wiring mot hubs_arende OCS finns i api.js (🔌 LIVE-markerade: /arende-summary, /arende/{ref}, POST /arende, /steg, /tilldela, /treserva/commit, /treserva/receipts, /fordelning-summary, /inflode/koppla) — dokumenterad 1:1 i docs/FRONTEND-WIRING.md; enligt memory GUI-E2E-verifierad på dev15 2026-06-18 (v1.2.5, create-flödet) men INTE omverifierad i denna session och nuvarande version är 1.2.15
- hubs_start:s egen backend (routes.php: page#index, OCS preferences GET/PUT, admin/reseed; AppDetectionService, RoleService) är verklig kod utan stubbar — läst rad för rad

## RISKS
- NyttaWidget visar hårdkodade siffror även i skarpt läge — disclaimern finns, men en kund kan läsa 318 ersatta fax som verklig statistik; ingen statistik-endpoint existerar (state.nytta produceras aldrig)
- fetchInflodeSummary läser sdkmc (api.js:609) och sdkmc-feeden gateas av sdkmc/hubs_start_inflode_demo — riskerar att live-instansen visar sdkmc-demorader i inflödet utan att hubs_start-demoläget är på (reseed sätter flaggan till '1')
- api.js:60 fryser DEMO vid modulladdning — konsekvent design (toggle kräver full reload), men all kod som importerar api.js efter en SPA-intern demo-växling utan reload skulle läsa fel läge
- SystemHalsa.vue anropar axios direkt (rad 177/192/206/227) och bryter mot api.js-konventionen 'components must NOT call axios directly'; ingen demo-gren → felstates i demo; runExpungeNow är destruktiv (gallring) bakom endast type-to-confirm i frontend
- Widget-varianterna (persona-dashboards utom socialsekreterare) är permanent demodata — om default_persona ändras eller boot.persona sätts till en annan persona på en skarp instans renderas demo-innehåll utan demo-badge på datat självt (endast WidgetProvenance/notiser signalerar)
- PUNCHLIST ⏳-posterna i backend-additions/sdkmc är overifierade i skarp miljö: fabricerad $assignee-label kan göra 'Ta ärendet' verkningslös, receipt-updated_at saknas, PENDING-semantiken obekräftad — dessa slår igenom i AttHanteraQueue/KvittensWidget när sdkmc-aggregaten tas i drift
- Versionsdrift: package.json 1.0.0 vs info.xml 1.2.15; PUNCHLIST/DEMO/HANDOVER daterade 13 juni medan koden ändrats t.o.m. 19-21 juni (t.ex. #6-signeringsfixen) — dokumenten släpar
- GUI-auth-blockern på dev15 (BankID-inloggning) kvarstod enligt senaste live-testet — frontenden kan inte E2E-verifieras i GUI förrän den är löst

## NEXT_STEPS
- Bygg/wira statistik-endpointen för NyttaWidget (state.nytta + store-fetch) eller ta bort widgeten ur live-layouts tills tjänsten finns
- Flytta fetchInflodeSummary till hubs_arende när motorns resolveInflodeRows() får riktig feed (cross-app-enrichment sdkmc-feed → klassning+ärende-match), och rensa sdkmc/hubs_start_inflode_demo-flaggan ur skarp konfiguration
- Refaktorera SystemHalsa till api.js-funktioner med demo-gren + åtgärda aria-live-punkten från PUNCHLIST
- Beta av PUNCHLIST ⏳-listan i Linux-miljön: assignment-label, receipt updated_at, PENDING-vokabulär, SummaryController-deduplicering, INBOX-predikat
- Omverifiera live-flödet på dev15 mot v1.2.15 (senast GUI-verifierat var v1.2.5) — kräver att BankID/GUI-auth-blockern löses först
- Synka package.json-versionen med info.xml (1.2.15) och uppdatera PUNCHLIST/HANDOVER till post-19-juni-läget