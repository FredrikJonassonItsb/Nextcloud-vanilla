# demodata

## SUMMARY
Projektet har ett medvetet, väl dokumenterat demo-/stublager i tre skikt: (1) hubs_start-SPA:ns `if (DEMO)`-short-circuit i api.js mot fixtures i src/services/demoData.js + src/services/demo/* (gated av app-config hubs_start/demo_mode + ?demo=-override), (2) sdkmc-backend-gaten `hubs_start_inflode_demo` som byter riktig inflöde-feed mot 14 syntetiska rader (InflodeDemoData.php), och (3) hubs_arende-motorns port/stub-arkitektur (FacksystemCommitStub/SigneringStub/EdiariumStub bakom INTEGRATION_MODE, default `stub` — inga live-implementationer finns). Utöver det gatade finns fyra o-gatade demoartefakter som körs även i live-läge: de 29 widget-descriptorerna i demoWidgets.js (WidgetRenderer.vue:96 kollar aldrig demoMode), hårdkodade namnet 'Anna' (TilldelningBand.vue:67, MinDagHeader.vue:99), NyttaWidgets placeholder-volymer (NyttaWidget.vue:75) och GallringsGrinds inbäddade handlingstypslista (GallringsGrind.vue:146). Farligaste enskilda fyndet: FacksystemCommitService faller tyst tillbaka till stubben om läget är 'live' utan registrerad live-port, och hubs_start:s admin-reseed-knapp sätter sdkmc.hubs_start_inflode_demo='1' med ett klick även på en live-instans.

## DETAILS
## 1. hubs_start/demo-data/ — filerna och konsumtionen

**11 vCard-filer i `hubs_start/demo-data/favoriter/`** (identisk kopia i `hubs_start/backend-additions/demo-data/favoriter/` + README med curl-seed-instruktioner). Alla märkta `PRODID:-//ITSL//Hubs Start SYNTHETIC DEMO//SV` + `NOTE:SYNTETISK DEMO-DATA`:

| Fil | Klass (X-HUBS-FAVORIT-KLASS) | Innehåll |
|---|---|---|
| fav-a-bup-malmo.vcf | sdk-pekare | BUP Malmö, `X-HUBS-SDK-REF:SE2321000255-bup-malmo` |
| fav-a-forsakringskassan.vcf | sdk-pekare | FK samverkanskontor |
| fav-a-polis-syd.vcf | sdk-pekare | Polisen ungdomssektionen Syd |
| fav-a-region-vuxenpsyk.vcf | sdk-pekare | Vuxenpsyk (prov-case vidarebefordran inf-9) |
| fav-a-socialjour-malmo.vcf | sdk-pekare | Socialjouren Malmö |
| fav-a-tombstone-gammal-mottagning.vcf | sdk-pekare | TOMBSTONE-testfixtur — pekaren finns avsiktligt INTE i DIGG → resolvern ska ge removed:true |
| fav-b-kronofogden-fax.vcf / fav-b-lindangsskolan-fax.vcf / fav-b-region-skane-fax.vcf | extern-funktion | Fax-nummer, Hubs äger värdet, `X-HUBS-OWNER:funktion:*@` |
| fav-c-funktionsteam-barn-familj.vcf / fav-c-gruppledare-eva.vcf | intern-anvandare | `X-HUBS-USER-REF:barn-familj` resp `eva` |

**Konsumtion:** `hubs_start/lib/Service/FavoriterSeedService.php:151` läser `__DIR__.'/../../demo-data/favoriter'` och skapar korten idempotent i användarens "Favoriter"-adressbok (CardDAV). Triggas av (a) `occ hubs_start:seed-favoriter [--user]` (`lib/Command/SeedFavoriter.php:20`), (b) admin-reseed-endpointen (`lib/Controller/AdminController.php:88`). Frontendens `FavoritValjare.vue` läser dem i demo via `src/services/demo/favoriter.js` och i live via sdkmc `GET /favoriter` (`backend-additions/sdkmc/lib/Controller/OCS/FavoriterController.php`).

## 2. Dokumenten

- **`hubs_start/DEMO.md`** — bruksanvisning för demoläget: `PageController::isDemoMode()` (app-config `hubs_start/demo_mode`: '1' tvingat PÅ / '0' AV / tomt = AUTO PÅ när sdkmc saknas). Tabell över allt stubbat (data, nätverkslager, app-detektering, roll `forvaltare`, LOA3 statiskt, inerta djuplänkar, fejkat `createSecureMeeting`-svar). Noterar att backend-additions INTE är installerade i vanilla-demon.
- **`hubs_start/docs/DEMO-STUBS.md`** — det auktoritativa SEAM-registret. Varje stub har en `🔌 SEAM[<id>]`-markör i koden. Seams: `treserva`, `treserva.commit`, `treserva.skapa`, `treserva.koppla`, `treserva.seed` (endast demo), `treserva.tombstone` (saknas, GAP-056), `favoriter`, `favoriter.tombstone` (GAP-061/063), `api.js DEMO-short-circuit` (27 exportfunktioner), `demodata-fixtures`, `PageController demo-boot`, `deepLinks inerta`. Per stub: exakt prod-ersättning + prioriterad blockerlista (GAP-019 Frends tyngst, GAP-007 retention-på-callback, GAP-056 register-reconciliation, GAP-057/058 ACL, GAP-061–064 favoriter).
- **`hubs_start/docs/DEMO-WIDGETS-CONTRACT.md`** — 37 persona-widgets: 8 riktiga (attHantera, dagensMoten, kvittenser, funktionsbrevlador, bevakningar, bokningsbaraTider, nytta, systemhalsa), 29 renderas via 4 presentational variants (queue/progress/stat/files) drivna av demo-descriptors i `src/services/demoWidgets.js` + `src/services/demo/*.js`. Definierar descriptor-shapes, tone/channel-enums.

## 3. Grep-resultat hubs_start (src, lib, backend-additions) — bedömning per träff

### Demodata som visas för användare, KORREKT GATED (endast demoläge)
- `src/services/api.js:60` `const DEMO = isDemo()`; 27 exportfunktioner med `if (DEMO) return …` (rad 83–646). Prod-grenarna (axios mot SDKMC_OCS/HUBS_ARENDE_OCS) ligger direkt under.
- `src/services/demoData.js` (hela filen) — övriga personas fixtures: 9 triage-items (`demo:1..9`), summary, receipts, 3 möten (`demomotea/b/c`), lobby, 2 appointmentConfigs (`demoapptA/B`, bookingUrl `https://demo.hubs.se/...`), recipients, notes (`demo-note-1/2`), fejkat `createSecureMeeting` (rad 324: token `demonewmt`). `isDemo()` (rad 31–44): ?demo=1/0-override i sessionStorage (`hubs_demo`) + boot.demoMode.
- `src/services/demo/socialsekreterare.js` — socialsekreterarvyns HELA demodata: 4 triage-rader (tri-1..4, pseudonymiserade "Barn 2026-XXXX"), ärendekort (HOT1–3 + stageBulk-fyllnad), enrichments, korgar/inflöde, fordelningSummary, team, puls, moten. Rad 290: `treserva.seedRegister(arenden)` vid import.
- `src/services/demo/treserva.js` — SEAM[treserva]: stateful in-memory REGISTER/RECEIPTS; `skapaArende`, `commitHandling` (retention startar ENBART på verifierad callback — GAP-007-mönstret, rad 168), `kopplaInflode`, `listReceipts`, `_dumpRegister`. Stubbade pekare: `talkToken:'demo'+id` (rad 78, 136).
- `src/services/demo/favoriter.js` — SEAM[favoriter]: 4 resolvade DTO:er (3 klasser + tombstone `fav-d-gammal-mott` removed:true).
- Optimistiska no-op-grenar i api.js: rad 306 (`kopplaMeddelandeTillArende`), 502 (`taggaCaseMeddelande`), 574 (`transitionSteg`), 590 (`tilldela`), 646 (`inflodeAction`).
- `src/views/Start.vue:240–243` `demoBlocked()` — djuplänkar inerta i demo; `src/components/PersonaSwitcher.vue` + `HeaderBar.vue:19` — persona-växlaren renderas bara i demoMode; `MinaArenden.vue:167–170,389–392` — "Visa i demoläge"-länken (?demo=1/0) syns dock ÄVEN live (medveten preview-funktion).

### Demodata som visas för användare ÄVEN I LIVE-LÄGE (o-gatad — viktigaste fyndet)
- `src/components/WidgetRenderer.vue:96` — `descriptorFor(id)` anropas OVILLKORAT (ingen demoMode-koll). De 29 widget-descriptorerna i `src/services/demoWidgets.js` (importerar `demo/queues-a.js`, `queues-b.js`, `progress.js`, `stats.js`, `files.js`, `extra.js`, `newcases.js`) renderas alltså med statiska svenska fixtures ("Utskrivningsklar patient dygn 4 · ~11 200 kr", "6 dokument väntar på min e-underskrift", årsräknings-progress 312/540 osv.) för varje live-användare vars persona-layout innehåller widget-id:t. `personaConfig.js` markerar 22 widgets `"dataSource":"proposed"` mot 17 `"real"` — men rendrering skiljer inte.
- `src/components/NyttaWidget.vue:75–80` — `PLACEHOLDER_VOLUME {sdk:142, secure:86, internal:54, fax:0, sms:23}` + Digg-schablon 30 min; körs live men med synlig disclaimer (rad 53: "Siffrorna är indikativa och ersätts av verklig statistik…").
- `src/components/socialsekreterare/TilldelningBand.vue:67` — `DEMO_MIG_NAMN = 'Anna'`; rad 108 avgör "är ägaren jag?" genom namnjämförelse mot 'Anna'. Körs live.
- `src/components/socialsekreterare/MinDagHeader.vue:97–100` — `namn()` returnerar hårdkodat `'Anna'` ("God morgon, Anna"). Körs live.
- `src/components/socialsekreterare/GallringsGrind.vue:141–152` — inbäddad demo-lista med 4 handlingstyper (reklam/autosvar/ringa/handling) som fallback när kommunens dokumenthanteringsplan är tom; `EjKoppladSektion.vue:121` skickar default `[]` → fallbacken är den effektiva listan live.

### Dev-stubs / kända förenklingar i skarp kod (inte demodata)
- `TODO(hubs-start)` i backend-additions/sdkmc: `SummaryService.php:535,649,677,762,819,903` (unread-API, mail-join, receipt updated_at, mailbox-spårning, MW-statusenum, loa3Tag), `SecureMeetingService.php:175,300,415,440,499,524` (spreed Manager, kalenderprovisionering, intent-sessionsnyckel, loopback-credentials), `MeetingService.php:438`, `OCS/SummaryController.php:228,266,299,343`, `TeamService.php:188` (Talk-unread per grupp), `mail/initITSL-additions.js:142,222` (SDK-komponistroute).
- `src/components/MeetingWizard.vue:316` — stubbad kollegakatalog i wizarden.
- `src/services/deepLinks.js:48,71` — kommentarer "never a hardcoded board/room" (motsats till hårdkodning; OK).

### hubs_start backend-gates (lib/)
- `lib/Controller/PageController.php:97–99` — `isDemoMode()`-resolutionen; rad 48–64 injicerar `boot.demoMode` + tvingar apps/kanaler i demo.
- `lib/Controller/AdminController.php:70–75` — `POST /ocs/v2.php/apps/hubs_start/api/v1/admin/reseed` (ADMIN-ONLY) sätter baseline: `hubs_start.demo_mode='0'`, `default_persona='socialsekreterare'`, **`sdkmc.hubs_start_inflode_demo='1'`** + favoritseed. Knappen renderas på adminsidan (`lib/Settings/Admin.php:30–32`, `js/hubs_start-admin-reseed`).

### backend-additions demo-gate (sdkmc)
- `backend-additions/demo-data/InflodeDemoData.php` — 14 syntetiska inflöde-rader (`demo-inf-01..14`), ankardatum `DEMO_TODAY='2026-06-17'` (rad 69); stort varningsblock "SYNTETISK DEMO-DATA — INGEN VERKLIG INFORMATION".
- `backend-additions/sdkmc/lib/Service/InflodeFeedService.php:71–73` — gate `sdkmc/hubs_start_inflode_demo`, default `'0'` (riktig källbackad feed); rad 129–130 returnerar `InflodeDemoData::summary()` när '1'; gate-läsfel degraderar till riktig feed (rad 164).

## 4. hubs_arende — stubbat by-design vs måste ersättas

**Port/stub-arkitekturen** (`lib/Integration/README.md`): tre portar, varje med stateful in-memory-stub, styrda per port av app-config `hubs_arende integration.{facksystem|signering|ediarium}` = `stub`|`live`, **default `stub`** (`lib/AppInfo/Application.php:49,71–90`; `lib/Service/FacksystemCommitService.php:128–129`).

| Port | Stub | Live-mål | Live-impl finns? |
|---|---|---|---|
| FacksystemCommitPort | `lib/Integration/Stub/FacksystemCommitStub.php` (dnr-sekvens från 500, synchronousCallback default, fel-/timeout-injektion) | Frends iPaaS → Treserva/Lifecare/Viva med verifierad callback (GAP-019) | **NEJ — saknas helt** |
| SigneringPort | `lib/Integration/Stub/SigneringStub.php` (syntetisk PAdES: rad 117 `"%PDF-1.7\n% stub-pades …"`) | Inera Underskriftstjänst PAdES-B-LTA | **NEJ** |
| EdiariumPort | `lib/Integration/Stub/EdiariumStub.php` (diarienummer, FGS-SIP-paket) | e-diarium/e-arkiv (FGS) | **NEJ** |

Kritiskt mönster bevarat i stub: retention/provenans flippas FÖRST i `verifyCallback()` (GAP-007). **Fail-safe-fälla:** läge `live` utan DI-registrerad live-port → tyst fallback till stubben med endast loggvarning (README §2; `FacksystemCommitService.php:135–139` lazy stub-fallback).

**Seed-verktyg (dev/demo by-design):**
- `lib/Service/DemoSeedService.php:59–70` — 10 kurerade CASES (`demo-barn-001` …) seedas genom den RIKTIGA motorn (createCase→transitions→tilldela→commit); `DEMO_HANDLAGGARE='197411040293'` (rad 51, riktig dev15-användare); purge via prefix `demo-` (`lib/Db/ArendeMapper.php:179`).
- `lib/Command/SeedDemo.php` — `occ hubs_arende:seed-demo [--purge]`; `lib/Controller/AdminController.php:55` — `POST /api/v1/admin/seed-demo` (reseed-knapp).
- `lib/Command/Smoke.php` — `occ`-smoke E2E mot stubbarna, alla 8 ärendetyper (rad 289); lämnar syntetiska rader (rad 290 hänvisar till `seed-demo --purge`).
- `lib/Migration/RegisterArendeTyper.php` — seedar de 8 default-ärendetyperna: **detta är PRODUKTIONS-konfigdata, inte demo**.
- `lib/Controller/InfodeController.php:96–100` — `resolveInflodeRows()` är tom: motorn har INGEN rå-inflödesfeed wirad; api.js:603–608 dokumenterar att live-inflödet därför läses från sdkmc.
- `lib/Service/InnehallsKlassService.php:49` — org-registret är en demo-konstant/TODO[konfig]-hook.
- `provision/bootstrap.sh:55` — `INSTALL_LIBRESIGN=0` default (SigneringPort-stub räcker; Inera är beslutad backend).

**Dev15-läget enligt repo:** `docs/HANDOVER-FORTSATTNING.md:27,57` — kör `demo_mode=0` OCH `hubs_start_inflode_demo=0` mot riktiga SDKMC-meddelanden; `scripts/dev15-reset.sh` återställer till känt testläge (0 ärenden, 2 riktiga otaggade orosanmälningar) och verifierar med SQL-räkningar. `docs/STATUS-OCH-ROADMAP.md:64` (äldre) beskrev inflödet i demo-läge — superseded av handovern.

## 5. Uttömmande tabell: demodata/stub → var → avstängning → krav för riktig data

| # | Artefakt | Var | Hur den stängs av | Krav för riktig data |
|---|---|---|---|---|
| 1 | SPA-fixtures (alla personas) | `src/services/demoData.js` via `api.js:60` | `occ config:app:set hubs_start demo_mode --value=0` (eller sdkmc installerad + tom flagga); ?demo=0 per session | sdkmc OCS `/summary`,`/receipts`,`/meetings/today`,`/recipients/*` (backend-additions, skrivna) |
| 2 | Socialsekreterar-demodata | `src/services/demo/socialsekreterare.js` | samma DEMO-flagga | hubs_arende `/arende-summary` + sdkmc `/inflode-summary` (byggda, i drift på dev15) |
| 3 | Treserva/Frends-stub (frontend) | `src/services/demo/treserva.js` | samma DEMO-flagga | ersatt i live av hubs_arende-motorn — som själv default-stubbar (rad 23–25 nedan) |
| 4 | Favorit-resolver-stub | `src/services/demo/favoriter.js` | samma DEMO-flagga | sdkmc `GET /favoriter` (FavoriterController finns; DIGG-resolvercache EJ byggd — FavoriterService.php:39, GAP-061) |
| 5 | 29 widget-descriptors | `src/services/demoWidgets.js` + `demo/{queues-a,queues-b,progress,stats,files,extra,newcases}.js`, renderade av `WidgetRenderer.vue:96` | **STÄNGS INTE AV — o-gatad** | riktiga backingservices per widget ELLER demoMode-gate i WidgetRenderer |
| 6 | Nytta-placeholder | `NyttaWidget.vue:75–80` | stängs inte av (disclaimer i UI rad 53) | statistik-tjänst + `nytta`-payload i store (CONTRACTS.md) |
| 7 | 'Anna' hårdkodat | `TilldelningBand.vue:67,108`; `MinDagHeader.vue:99` | stängs inte av | jämför mot inloggad uid/displayName |
| 8 | Handlingstyps-fallback | `GallringsGrind.vue:146–151` | stängs inte av (åsidosätts om prop skickas) | wira kommunens dokumenthanteringsplan till `EjKoppladSektion` |
| 9 | Favorit-vCards (seed) | `demo-data/favoriter/*.vcf` (11 st) | seedas bara via occ/reseed; ta bort korten ur adressboken | riktiga favoriter skapade av användare + DIGG-resolve |
| 10 | Favoritseeder | `lib/Service/FavoriterSeedService.php`, `lib/Command/SeedFavoriter.php` | körs ej automatiskt | tas bort/behålls som dev-verktyg |
| 11 | Admin-reseed | `lib/Controller/AdminController.php:69` + `lib/Settings/Admin.php:30` | admin-only; men sätter `hubs_start_inflode_demo='1'` | villkora bort på prod (eller ändra baseline till '0') |
| 12 | Syntetiskt inflöde (backend) | `backend-additions/demo-data/InflodeDemoData.php` bakom `InflodeFeedService.php:129` | `occ config:app:set sdkmc hubs_start_inflode_demo --value 0` (default 0) | riktiga funktionsbrevlådors INBOX (feeden finns, i drift på dev15) |
| 13 | FacksystemCommitStub | `hubs_arende/lib/Integration/Stub/FacksystemCommitStub.php` | `occ config:app:set hubs_arende integration.facksystem --value live` | **live-adapter saknas helt** — Frends-flöde + verifierad callback (GAP-019/007) |
| 14 | SigneringStub | `…/Stub/SigneringStub.php` | `integration.signering --value live` | **live-adapter saknas** — Inera Underskriftstjänst PAdES-B-LTA |
| 15 | EdiariumStub | `…/Stub/EdiariumStub.php` | `integration.ediarium --value live` | **live-adapter saknas** — e-diarium/e-arkiv FGS |
| 16 | Demo-ärenden (motor) | `hubs_arende/lib/Service/DemoSeedService.php:59` (10 CASES) | `occ hubs_arende:seed-demo --purge` | inget — riktiga ärenden skapas ur inflödet |
| 17 | Smoke-rester | `hubs_arende/lib/Command/Smoke.php:290` | `seed-demo --purge` | — |
| 18 | Motor-inflödesfeed | `InfodeController::resolveInflodeRows()` = tom | n/a | cross-app-enrichment sdkmc-feed → hubs_arende klass/match (api.js:603–608) |
| 19 | Org-register klass-tjänst | `InnehallsKlassService.php:49` | n/a | TODO[konfig]-hook → riktigt org-register |
| 20 | Mötesbokning demo-svar | `demoData.js:324` (`createSecureMeeting`) | DEMO-flaggan | sdkmc SecureMeetingService (skriven, backend-additions) |

## DEMO_OR_STUB
- src/services/demoData.js (hela filen, ~342 rader) — SPA-fixtures for alla personas (triage demo:1..9, receipts, moten demomotea/b/c, lobby, appointmentConfigs demoapptA/B, recipients, notes). Gate: api.js:60 const DEMO=isDemo(); isDemo (demoData.js:31-44) = ?demo=1/0-override (sessionStorage hubs_demo) ELLER boot.demoMode fran PageController.
- src/services/demo/socialsekreterare.js — hela socialsekreterarvyns demodata (triage tri-1..4, arenden HOT1-3+stageBulk, korgar/inflode, fordelningSummary, team, puls, moten); rad 290 seedar treserva-stubbens register vid import. Gate: DEMO-flaggan i api.js.
- src/services/demo/treserva.js — SEAM[treserva]: stateful in-memory Frends/Treserva-stub (REGISTER/RECEIPTS, skapaArende, commitHandling med retention-pa-verifierad-callback, kopplaInflode). Gate: DEMO-flaggan.
- src/services/demo/favoriter.js — SEAM[favoriter]: 4 resolvade favorit-DTO:er (klass a/b/c + tombstone fav-d-gammal-mott removed:true). Gate: DEMO-flaggan (api.js:406).
- src/services/demoWidgets.js + src/services/demo/{queues-a,queues-b,progress,stats,files,extra,newcases}.js — 29+ statiska widget-descriptors. INTE GATED: WidgetRenderer.vue:96 anropar descriptorFor(id) utan demoMode-koll — visas for live-anvandare vars persona-layout innehaller widget-id:t.
- src/components/NyttaWidget.vue:75-80 — PLACEHOLDER_VOLUME {sdk:142, secure:86, internal:54, fax:0, sms:23} + 30-min-schablon. Kors alltid (aven live); synlig disclaimer 'Siffrorna ar indikativa' pa rad 53.
- src/components/socialsekreterare/TilldelningBand.vue:67 — DEMO_MIG_NAMN='Anna'; rad 108 avgor agarskap via namnjamforelse. Ingen gating — kors live.
- src/components/socialsekreterare/MinDagHeader.vue:97-100 — halsningsnamnet hardkodat 'Anna'. Ingen gating — kors live.
- src/components/socialsekreterare/GallringsGrind.vue:141-152 — inbaddad demo-lista med 4 handlingstyper som fallback nar kommunens dokumenthanteringsplan ar tom (EjKoppladSektion.vue:121 skickar default []). Kors live.
- src/components/MeetingWizard.vue:316 — stubbad kollegakatalog i motes-wizarden.
- src/services/api.js optimistiska demo-grenar: rad 306 (kopplaMeddelandeTillArende), 502 (taggaCaseMeddelande), 574 (transitionSteg), 590 (tilldela), 646 (inflodeAction) — alla gated pa DEMO.
- src/views/Start.vue:240-243 demoBlocked() — djuplankar inerta i demolage; PersonaSwitcher.vue/HeaderBar.vue:19 — personavaljaren renderas endast i demoMode; MinaArenden.vue:169/391 — 'Visa i demolage'-lank (?demo=1/0) synlig aven live (avsiktlig preview).
- hubs_start/lib/Controller/PageController.php:97-99 — isDemoMode(): app-config hubs_start/demo_mode '1' PA / '0' AV / tomt AUTO (PA nar sdkmc saknas); rad 48-64 injicerar boot.demoMode + tvingar apps/roll/LOA i demo.
- hubs_start/demo-data/favoriter/*.vcf (11 syntetiska vCards, klass a=6 inkl tombstone, b=3 fax, c=2 interna) — seedas av lib/Service/FavoriterSeedService.php:151; occ hubs_start:seed-favoriter (lib/Command/SeedFavoriter.php); identisk kopia i backend-additions/demo-data/favoriter/.
- hubs_start/lib/Controller/AdminController.php:70-75 — POST /admin/reseed (admin-only, knapp via lib/Settings/Admin.php:30): satter demo_mode='0', default_persona='socialsekreterare' och sdkmc.hubs_start_inflode_demo='1' + favoritseed. OBS: baseline slar PA syntetiskt inflode.
- hubs_start/backend-additions/demo-data/InflodeDemoData.php — 14 syntetiska inflode-rader (demo-inf-01..14), DEMO_TODAY='2026-06-17'. Gate: sdkmc app-config hubs_start_inflode_demo, default '0' (InflodeFeedService.php:71-73,129-130); '1' → demodata, annars riktig feed. Enligt HANDOVER-FORTSATTNING.md:57 ar gaten AV pa dev15.
- TODO(hubs-start) dev-stubs i backend-additions/sdkmc (skarp kod, kanda forenklingar): SummaryService.php:535,649,677,762,819,903; SecureMeetingService.php:175,300,415,440,499,524; MeetingService.php:438; OCS/SummaryController.php:228,266,299,343; TeamService.php:188; mail/initITSL-additions.js:142,222.
- hubs_arende/lib/Integration/Stub/FacksystemCommitStub.php — stateful Frends/Treserva-stub (dnr-seq fran 500, synkron verifierad callback, fel/timeout-injektion). Gate: occ config:app:set hubs_arende integration.facksystem stub|live, DEFAULT stub (Application.php:49,71; FacksystemCommitService.php:128). Live-adapter SAKNAS.
- hubs_arende/lib/Integration/Stub/SigneringStub.php — Inera-stub, syntetisk PAdES (rad 117 '%PDF-1.7 % stub-pades'). Gate: integration.signering, default stub. Live-adapter SAKNAS.
- hubs_arende/lib/Integration/Stub/EdiariumStub.php — e-diarium/e-arkiv-stub (FGS). Gate: integration.ediarium, default stub. Live-adapter SAKNAS.
- hubs_arende/lib/Service/FacksystemCommitService.php:135-139 + Integration/README.md par.2 — FAIL-SAFE-FALLA: lage 'live' utan registrerad live-port → tyst fallback till stubben (endast loggvarning).
- hubs_arende/lib/Service/DemoSeedService.php:59-70 — 10 kurerade syntetiska CASES (prefix demo-, DEMO_HANDLAGGARE='197411040293') seedade genom RIKTIGA motorn; purge exakt via prefix (ArendeMapper.php:179). Verktyg: occ hubs_arende:seed-demo [--purge] (lib/Command/SeedDemo.php) + POST /admin/seed-demo (AdminController.php:55).
- hubs_arende/lib/Command/Smoke.php — occ-smoke E2E mot stubbarna (8 arendetyper); lamnar syntetiska rader (rad 290, rensas med seed-demo --purge).
- hubs_arende/lib/Controller/InfodeController.php:96-100 — resolveInflodeRows() ar TOM: motorn har ingen ra-inflodesfeed; live-inflodet gar via sdkmc (api.js:603-608).
- hubs_arende/lib/Service/InnehallsKlassService.php:49 — org-registret ar demo-konstant/TODO[konfig]-hook.
- hubs_arende/provision/bootstrap.sh:55 — INSTALL_LIBRESIGN=0 default (SigneringPort-stubben racker; Inera beslutad backend).
- scripts/dev15-reset.sh — aterstaller dev15 till kant testlage: raderar alla arenden/pekare/taggar/arenderum sa de 2 RIKTIGA orosanmalningarna ligger otaggade i 'Att ta emot'.

## VERIFIED_WORKING
- Demo-gatingkedjan i SPA:n ar fysiskt separerad (evidens: kodlasning) — api.js:60 + 27 st 'if (DEMO) return' med prod-axios-gren direkt under; PageController.php:97-99 resolution; demoData.js:31-44 ?demo-override. Att sla av demolage kraver noll kodandring.
- sdkmc-inflodesgaten defaultar till riktig feed (evidens: kod) — InflodeFeedService.php:73 DEMO_GATE_DEFAULT='0'; gate-lasfel degraderar till riktig feed (rad 164).
- hubs_arende-motorn kor E2E mot stubbarna (evidens: occ hubs_arende:smoke finns, Smoke.php:289 'SMOKE OK — motorn kor end-to-end mot stubbarna (alla 8 arendetyper)'; enhetstester finns: tests/Unit/Integration/FacksystemCommitStubTest.php, FacksystemCommitServiceModulTest.php m.fl. — testkorning ej upprepad i denna session, kors lokalt via composer:2-dockerimage enligt minnesanteckning hubs-local-tests).
- Dev15 kor LIVE-lage, inte demo (evidens: docs/HANDOVER-FORTSATTNING.md:27,57 — demo_mode=0 och hubs_start_inflode_demo=0, riktiga SDKMC-meddelanden; scripts/dev15-reset.sh verifierar 'inbox>=2' riktiga meddelanden efter reset; live GUI-E2E av orosanmalan-create-flodet 2026-06-18 per minnesanteckning hubs-orosanmalan-livetest — ej omverifierat nu).
- Demo-seed/purge ar idempotent och exakt avgransad (evidens: kod) — DemoSeedService prefix 'demo-' pa conversationId, purge river externa objekt forst; createCase idempotent pa conversationId.
- Favorit-seedern ar idempotent och PII-fri (evidens: kod + vCard-innehall) — alla 11 vCards markta SYNTHETIC DEMO i PRODID+NOTE; FavoriterSeedService hoppar befintliga kort.

## RISKS
- De 29 demo-widget-descriptorerna renderas AVEN i live-lage: WidgetRenderer.vue:96 kollar aldrig state.demoMode — en live-anvandare med t.ex. forvaltare-/gruppledarpersona ser statiska fixtures (utskrivningsklara patienter, signeringskoer, MCF-klockor) som ser ut som riktig data. Ingen demo-markning pa korten (endast WidgetProvenance).
- FacksystemCommitService faller TYST tillbaka till stubben nar integration.facksystem='live' men ingen live-port ar DI-registrerad (FacksystemCommitService.php:135-139) — en commit kan se verifierad ut utan att nagot natt facksystemet. I prod maste detta vara fail-closed.
- hubs_start admin-reseed-knappen (AdminController.php:74) satter sdkmc.hubs_start_inflode_demo='1' — ett admin-klick pa en LIVE-instans byter det riktiga inflodet mot 14 syntetiska rader och gommer riktiga orosanmalningar.
- Hardkodat 'Anna' (TilldelningBand.vue:67/108, MinDagHeader.vue:99) i live: fel agar-attribution ('mig') for alla anvandare, och alla anvandare halsas som Anna.
- GallringsGrind-fallbacken (GallringsGrind.vue:146-151) later live-anvandare fatta gallringsbeslut mot en inbaddad demo-lista i stallet for kommunens dokumenthanteringsplan.
- ?demo=1-overriden (demoData.js:31-44) fungerar pa live-instans och persisteras i sessionStorage — avsiktlig preview, men demolagets mutationer ar no-ops vilket kan forvirra om anvandaren inte marker lage-bytet.
- Inga live-adaptrar existerar for nagon av de tre portarna (Frends/Treserva GAP-019, Inera signering, e-diarium) — allt facksystem-/signerings-/arkivflode ar stub aven om alla andra delar gar live.
- InflodeDemoData beskrivs i STATUS-OCH-ROADMAP.md:64 som 'PII-barande' (syntetisk men realistisk) — om gaten slas pa i fel miljo visas dessa rader som riktiga arenden.
- DemoSeedService::DEMO_HANDLAGGARE ar hardkodad till dev15-uid '197411040293' — seedning i annan miljo degraderar (tom grupp) men kan ge missvisande handoff-demo.

## NEXT_STEPS
- Gata de 29 widget-descriptorerna pa demoMode (eller personaConfig dataSource): i WidgetRenderer.vue:96, hoppa descriptorFor() nar !state.demoMode och falla till WidgetFallback ('foreslagen funktion') — arligt lage for live-anvandare.
- Ersatt hardkodade 'Anna': jamfor agareUid mot inloggad uid (getCurrentUser) i TilldelningBand.vue och hamta displayName i MinDagHeader.vue.
- Andra admin-reseed-baseline (hubs_start/lib/Controller/AdminController.php:74) till hubs_start_inflode_demo='0' eller villkora hela reseed-endpointen bakom demo-/dev-miljoflagga.
- Gor live-laget fail-closed i FacksystemCommitService: kasta exception i stallet for stub-fallback nar mode='live' utan registrerad port.
- Bygg live-adaptrarna per port (prioritet enligt DEMO-STUBS.md par.5): FrendsFacksystemAdapter (GAP-019/007), Inera SigneringPort, EdiariumPort; registrera i AppInfo/Application::register och toggla via occ.
- Wira kommunens dokumenthanteringsplan som handlingstyper-prop till EjKoppladSektion/GallringsGrind och ta bort den inbaddade fallback-listan (eller markera den tydligt i UI).
- Bygg DIGG-resolvercachen bakom sdkmc GET /favoriter med hard fail-closed (GAP-061) — controller/service-skalet finns i backend-additions/sdkmc (FavoriterService.php noterar att cachen ej ar byggd).
- Bygg cross-app-enrichmenten sdkmc-feed → hubs_arende klass/match sa motorns InfodeController far ra-rader (resolveInflodeRows ar tom idag) och sla darefter over api.js fetchInflodeSummary till hubs_arende.