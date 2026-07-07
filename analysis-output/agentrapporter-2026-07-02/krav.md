# krav

## SUMMARY
Kravställningen (268 krav K-1…K-8) är täckt som en smal men djup vertikal: ärendemotorn (K-3) och socialsekreterar-UI:t (K-6) är till stor del byggda och delvis GUI/DB-verifierade på dev15 (orosanmälan end-to-end, 8/8 ärendetyper på motornivå via smoke, jest 88/phpunit 72), och alla 11 säkerhetsfynd ur SAKERHETSGRANSKNING-MOTOR (H1–H3, M1–M6, L2) är fixade i kod med regressionstester. Klassningslagret (K-4) är byggt men owirat mot den riktiga feeden (tom seam), och hela bredden — 11 av 12 kommunroller, samtliga SoR-/facksystemkonnektorer, externa myndighets-commits, Δ-datafälten (Δ1–Δ6, Δ10–Δ17), PuB-matris, Inera-signering — är orörd eller enbart stubbar. Viktig divergens: bygget avvek från kravdokumentet — motorn bor i en NY app `hubs_arende` (routes `/api/v1`) i stället för sdkmc/M0 (`/api/v2`), vilket gör delar av K-2/K-3-kartan inaktuell som byggkarta men de facto uppfyller separations-/ExApp-rent-målen på annat sätt. Signering: LibreSign finns på dev15 men är EFEMÄR; Inera-adaptern är enbart en port+stub.

## DETAILS
## Täckningsmatris per kravområde (K-1…K-8)

Källor: `C:/Users/fredrik.jonasson/Cursor/Nextcloud-vanilla/hubs_start/docs/HUBS-KRAVSTALLNING-TOTAL.md` (798 rader), HANDOVER-FORTSATTNING.md §4, samt kodverifiering i `hubs_arende/`, `hubs_start/` och `hubs_start/backend-additions/sdkmc/`.

| Område | Krav | Täckning | Bedömning |
|---|---|---|---|
| **K-1 OVERSIKT** (scope, personas, moduler) | K-1.1–1.27 | **DELVIS (~40 %)** | Mellanlager-principen, commitDestination-invarianten, terminerande utfall, graceful degradation = byggda. 12-rollsmodellen, cross-role, aclProfil-bibliotek, M0–M4-paketering = ej byggda |
| **K-2 ARK** (modul/licens/datalager) | K-2.1–2.20 | **DELVIS (~50 %)** | Datalager-beslutet (B-DL-1) de facto verkställt: proper NC-app-DB, ExApp-rent. M0/M1-split (B-MOD-1), ExApp-lyft, licens (B-LIC-1) = öppna |
| **K-3 MOTOR** (register/saga/ArendeTyp) | K-3.1–3.28 | **TILL STOR DEL BYGGD (~80 %)** | Saga+kompensering+idempotens+hooks+8 typ-rader+lifecycle+gallring = byggt & smoke-verifierat. Reconciliation-jobb, frist-spegling, M0-flytt = saknas |
| **K-4 KLASS** (klassificering/routing) | K-4.1–4.30 | **DELVIS (~45 %)** | InnehallsKlassService+ArendeMatchService+SakerhetsskyddGrind byggda med fail-closed-semantik — men owirade mot riktig feed (tom seam). KategoriBadge saknas. LLM-lager medvetet ej byggt |
| **K-5 ROLLER/SoR/INTEGRATIONER** | K-5.1–5.42 | **I HUVUDSAK ORÖRT (~15 %)** | Endast Δ7/Δ8/Δ9 (säkerhetsskydd/felmottaget/vissel) byggda på grind-nivå. Δ1–Δ6, Δ10–Δ17 datafält saknas i schemat. 0 riktiga konnektorer |
| **K-6 UI** (socialsekreterare) | K-6.1–6.30 | **TILL STOR DEL BYGGD (~90 %)** | 37 Vue-komponenter (spec sa 34; +AvslutaGrind, MinaAnteckningar, KopplaValjare, VadVillDuGora, OnboardingTour). KategoriBadge.vue = enda uttryckligt efterfrågade som fortfarande saknas |
| **K-7 JURIDIK/SIGNERING/RETENTION** | K-7.1–7.36 | **DELVIS (~35 %)** | Retention-motor bunden till verifierad callback = byggd (mot stub). PliktGrind (GAP-001) = byggd+GUI-verifierad. Inera-AES, delgivning, maskering, FGS, PuB-matris, temporal sekretess = saknas |
| **K-8 BYGG/GAP** | K-8.1–8.55 | **DELVIS** | Vertikalens gap (GAP-001/007/010/056-register/057/058-delar) stängda på motornivå; GAP-019/031/034-037/052 + Δ-bredden öppna |

---

## Detaljer per område

### K-1/K-2 — Scope, moduler, datalager
- **Verkställt:** `commit_destination NOT NULL` i schemat (`hubs_arende/lib/Migration/Version000000Date20260616000000.php:112-117`, K-1.6/K-2.19/B-INV-1). Registret pseudonymt (aldrig klartext-PII, positiv validering `ArendeService.php:1849 ff`). Opaka pekare i egen tabell `hubs_arende_pekare` (K-2.15), single-writer `ArendeService` (K-2.15b). Tables-underkännandet (K-2.13) är de facto verkställt genom att bygga QBMapper/Migration.
- **VIKTIG DIVERGENS:** Kravställningen föreskriver registret i **sdkmc/M0** med routes `/api/v2/arende*` (K-3.26/K-3.27/B-API-1). Bygget la det i en **ny fristående app `hubs_arende`** med routes `/api/v1/*` (`hubs_arende/appinfo/routes.php:23-128`). Detta uppfyller separationsmålet (motorn dras inte med i sdkmc-monoliten) på ett annat sätt än B-MOD-1 föreskrev — men gör kravdokumentets kodkarta (§3.7, §11) inaktuell. Boundary ratificerad i `hubs_start/backend-additions/MANIFEST.md`: meddelande/kontakt/möte = sdkmc, ärende = hubs_arende.
- **Öppet:** B-MOD-1 (sdkmc M0/M1-split — ej gjord, taggmotorn kvar i sdkmc-monoliten), B-LIC-1 (proprietär ExApp — kräver IP-jurist, `[EXTERN]`), B-EXAPP-1 (lyft (b)→(c) — ej gjort, men schemat är ExApp-rent så vägen är öppen), B-SKU-1 (SKU-definitioner — affärsbeslut).

### K-3 — Ärendemotorn (djupast täckt)
Byggt och verifierat i `hubs_arende` 0.7.5 (deployad dev15, NC 31.0.8):
- Saga R0–R9 med kompensering: `lib/Service/ArendeService.php` (R0-grind rad 179, groupfolder+ACL rad 397-408, Deck rad 432, Spreed rad 473-479, referens-fil via `ReferensFilService`).
- Idempotens: UNIQUE-index på `conversation_id` + `gallras_datum`-kolumn (`Version000100Date20260617120000.php:57-66`).
- Hook-infra datadriven: kat6 `diariefor_direkt` pre-saga (föds registrerad+dnr, `ArendeService.php:259-276`), kat8 `familjeratt_yttrande` post-commit (rad 1555). Live-verifierat: dnr@birth=SN-2026-0101.
- `ArendeTypRegistry` seedar 8 baskategorier (`lib/Service/ArendeTypRegistry.php:164-354`) med fristPolicy-struct {typ, ankare, speglasUrTreserva}, aclProfil, sekretessGrund, dhpHandlingstyp, frendsModul, hooks, partsModell.
- `FacksystemCommitService::resolveModul` fail-closed (kastar i stället för tyst default — felrouting=sekretessincident).
- Livscykel med pliktGrind (ORO-1): `ArendeLifecycleService` blockerar förhandsbedömning→utredning utan explicit `skyddsbedomningKvitterad` — GUI-verifierad.
- **Saknas:** `ArendeReconciliationJob` (K-3.17/GAP-056-reconciliation) — enda BackgroundJob är `GallringJob.php`. Frist-spegling ur facksystem (K-8.35) — stub. dnr-alias-tagg. Automatiserat tre-lagers-koherenstest (GAP-058-testet).

### K-4 — Klassning/routing
- **Byggt (kod + tester):** `InnehallsKlassService`, `ArendeMatchService` (deterministisk kaskad, server-side tröskel, default `ej_kopplat`), `SakerhetsskyddGrind` med retroaktiv karantän (`SakerhetsskyddGrind.php:236`) och breddad kuvert-nyckel-detektering (handlingskod/classification/x-protective-marking, fail-closed, rad 98-102). Anonymitetsgrind TF 2:18 vänd till POSITIV ALLOW-lista/fail-closed (`ArendeMatchService.php:97-98, 539-568`).
- **MEN owirat:** `InfodeController::resolveInflodeRows()` returnerar `[]` (`InfodeController.php:405-407`) — kat-1–8-klassningen och matchkaskaden kör aldrig på riktig data. Den RIKTIGA feeden är sdkmc:s `InflodeFeedService` (backend-additions) som gör Axel B kanal-typ + dedup på thread_root_id + behandlad-exkludering — men ingen innehållskategorisering. `registerPartHook()=null` (`ArendeMatchService.php:601-603`) — SSN-steget är en död seam (medvetet).
- **Saknas:** `KategoriBadge.vue` (K-6.26/P1.7 — bekräftat ej i `hubs_start/src/components/socialsekreterare/`), kat2 sub-typ-router (AB-01 — medvetet ej byggd, kräver migration), LLM-förslagslager (medvetet ej byggt, T-AI-1 `[EXTERN]`).

### K-5 — Roller/SoR/integrationer (STÖRSTA LUCKAN)
- ArendeTyp-entiteten (`hubs_arende/lib/Db/ArendeTyp.php:57-77`) har 16 fält men SAKNAR samtliga Δ-breddfält: `systemOfRecord`/`sorFallback`/`inflodeVia` (Δ1/Δ16), `sekretessGrund[]`-struct med temporalitet (Δ2 — finns bara som skalär sträng 'osl_26'), `riskKlass` (Δ3), `externMyndighetMottagare`+`commitMode` (Δ4), `verksamhetsgren`+`sekretessMur` (Δ5 — HSL/SoL-muren!), `joinNyckel` (Δ6), `karantanKravs`-kolumn (Δ7 — dock täckt funktionellt av grinden), `retentionAnkare`, PuB-fälten (Δ17).
- Konnektorer: 0 riktiga. Endast `FacksystemCommitStub`, `EdiariumStub`, `SigneringStub` i `hubs_arende/lib/Integration/Stub/`. Frends live-port (GAP-019), e-diarium-konnektor (K-5.14, P0 för 9 roller), Sokigo/Appva/HR/e-Wärna (K-5.15), externa myndighets-commits IMY/MSB/IVO (K-5.16-18) — allt saknas.
- Δ7/Δ8/Δ9 (det "lagbrottsgolv" som P0.4 kräver innan roller utanför socialtjänst aktiveras) ÄR byggda på grind-nivå: avvisa före R2, retroaktiv karantän, visselblåsnings-indikator avvisas (`SakerhetsskyddGrind.php:47,56,77,394`). Dock: B-SEC-1 (detektions-semantiken per kommun, regimbyte höjd beredskap) är ofattat beslut.

### K-6 — UI
37 komponenter i `hubs_start/src/components/socialsekreterare/` (hubs_start 1.2.15). Session 3-ändringar: SigneringsGrind BORTTAGEN (modal-stacking-deadlock) — signeringsbekräftelsen inbäddad i CommitGrind (`payload.kraverSignering`); ny AvslutaGrind (hela resan till avslutat, smoke [8b]). FordelningsVy/UtredarLast/FordelaTill finns (UI); `FordelningController` finns i hubs_arende. Demo-grind: `isDemo()` läser `?demo=1/0` + boot-flagga, default AV på skarp instans.

### K-7 — Juridik/signering/retention (fråga 3: SIGNING-INERA)
**Designat (SIGNING-INERA.md):** komplett — adapter-arkitektur val A (två utbytbara backends), Inera API-flöde (mTLS/OOB + SITHS funktionscert + hash-baserad profil), PDF/A-1+LTV-härdning, kravnivå-matris, Sweden Connect-alternativ.
**Byggt:** (a) LibreSign 11.6.0 installerad på dev15 — men **EFEMÄR** (försvinner vid container-recreate/`itsl deploy`, inkl. openjdk/poppler apk-paket; HANDOVER §1). (b) `SigneringPort`/`SigneringStub` i hubs_arende (stateful in-memory PAdES-simulering, `Stub/SigneringStub.php:19-29,117`). (c) Frontend: signerings-kryssruta i CommitGrind (GUI-fix #6).
**Saknas helt:** Inera-adapter (GAP-034), LTV/kvalificerad tidsstämpel (GAP-035), kravnivå-matris per dokumenttyp (K-7.19, per-kund-beslut), SignMessage-juristgranskning (K-7.18), Inera-avtal/SITHS-anslutning (B-SIGN-1, veckor–månader ledtid, `[EXTERN]`).
**Retention:** flip endast på verifierad callback + `gallras_datum` + `GallringJob` som ALDRIG raderar utan registrerad+gallras_efter_commit+deadline passerad (`GallringService.php:26-38`) = GAP-007-mönstret byggt (mot stub). **GAP-031 ÖPPEN:** `retentionState='pausad'` sätts endast av karantän-vägen (`SakerhetsskyddGrind.php:330`) — ingen utlämnandebegäran-hook.

### Fråga 4: SAKERHETSGRANSKNING-MOTOR — öppna fynd
Granskningen (2026-06-17) fann H1–H3 (röd), M1–M6, L1–L7. **Kodverifiering visar att alla materiella fynd är ÅTGÄRDADE:**
- H1 (IDOR): `IUserSession`-authz + `enhetTillaten`-predikat på show/summary/tilldela/commit/list (`ArendeService.php:621,667,751,776`) + `ArendeServiceAuthzTest.php`.
- H2/H3 (fel-wirad commit-route): `ArendeController.php:182` går nu via `arendeService->commit()` (existenskoll+idempotens+flip) + `ArendeServiceCommitIdempotensTest.php`.
- M1/M2 (PII i objektRef/triageRef): positiv pseudonym-validering pre-saga (`ArendeService.php:235-244`); mount/kort/rumsnamn = hubsCaseId (rad 404,440,479) + `ArendeServiceObjektRefValideringTest.php`.
- M3 (ogrindad läsväg): grinden körs som FÖRSTA steg per inflöde-rad (`InfodeController.php:104-112,254`).
- M4 (detektor-nycklar): breddad + fail-closed (`SakerhetsskyddGrind.php:98-102` + `SakerhetsskyddGrindM4Test.php`).
- M5/L5 (anonymitetsgrind fail-open): vänd till ALLOW-lista (`ArendeMatchService.php:539-568` + `ArendeMatchServiceM5Test.php`).
- M6 (TOCTOU): UNIQUE-index conversation_id. L2: gallras_datum-kolumn.
**Kvarvarande öppna punkter ur granskningen:** seams-varningarna i §4 gäller fortfarande — live inflöde-feed till klass/match är owirad, part-registret null, riktig FacksystemCommitPort saknas (dag ett en riktig port registreras måste idempotens-skyddet hålla — det finns nu, men är otestat mot riktig Treserva). L3 (best-effort-flip vid DB-fel) och L6 (orphaned external state) status oklar — ej explicit verifierade som fixade.

### Fråga 5: MODULARISERING-LICENS-DATALAGER — beslutat/öppet
- **BESLUTAT + VERKSTÄLLT:** Tables underkänd (§3.1) → proper relationell DB byggd; ExApp-rent schema (inga oc_*-FK, opaka pekare, single-writer) verkställt i hubs_arende; commitDestination NOT NULL verkställt.
- **BESLUTAT MEN EJ VERKSTÄLLT:** M0/M1-split av sdkmc (§5.1 punkt 1, B-MOD-1) — sdkmc är fortfarande monolit; taggmotorn ej flyttad. AppDetectionService funktions-utökning (§5.1 punkt 4) — ej verifierad gjord.
- **ÖPPET:** B-LIC-1 proprietär M4 (kräver IP-jurist: combined work vs separate program, mail `-only` vs spreed `-or-later`, securemail-licens, §13); B-EXAPP-1 lyft-tidpunkt (b)→(c); B-SKU-1 fyra SKU:er. OBS: att motorn hamnade i `hubs_arende` (fristående app, in-process PHP mot OCP) betyder att den idag är AGPL-pliktig — proprietär-optionen kräver fortfarande ExApp-lyftet.

### Fråga 2: HELT orörda kravområden
1. **11 av 12 kommunroller** — endast socialsekreterare har riktig backend. De 6 personorna i `personaConfig.js` (socialsekreterare, hsl_skoterska, hr_chef, overformyndare, registrator, forvaltare) finns som UI-layouter, men alla persona-widgets med `dataSource: proposed` renderas som `WidgetFallback` ("honest proposed feature card", `WidgetRenderer.vue:110`) — ren mockup. Roller 6,8,9,10,11,12 saknar ens persona-layout.
2. **Alla SoR-/facksystemintegrationer**: Frends/Treserva live, e-diarium (Public360/W3D3/Ciceron/Platina/Evolution), Sokigo, Appva, HR-system, e-Wärna, CRM — 0 byggda.
3. **Externa myndighets-commits** (IMY 72h, MSB/NIS2, IVO Lex Sarah/Maria, FK, länsstyrelse, domstol) — 0.
4. **HSL-domänen**: utskrivningsbevakning (lag 2017:612), samverkansavvikelser, SoL↔HSL-sekretessmur (Δ5), las_konsument Pascal/NPÖ — 0.
5. **Överförmyndare**: årsräkningar, granskningskö, uppdragskontroll, e-Wärna, 2028-ställföreträdarregistret — 0.
6. **PuB-/laglig-grund-matris (Δ17/K-7.32)** — "osäljbart utan den" enligt beslutsloggen — 0.
7. **Temporal sekretess/ACL-degradering (Δ10)**, **cross-role-routing (K-5.35-37)**, **jäv-exkludering (K-7.6)**, **delgivningsmodell (K-7.28)**, **maskering/utlämnandestöd (K-7.29)**, **FGS-e-arkiv (K-7.34)**, **retention-paus TF (GAP-031)** — 0.
8. **AI/LLM-lager + transkribering (GAP-052)** — medvetet ej byggt (inväntar IMY/SKR-vägledning).

### Ofattade beslut som blockerar (ur §9)
B-MOD-1, B-LIC-1, B-SEC-1 (detektions-semantik + regimbyte), B-DOK-1 (var skrivs utredningstexten), B-SIGN-1 (Inera-avtal), T-RET-1 (gallringströskel), T-DHP-1 (DHP-källa för GallringsGrind), T-PUB-1 (PuB-matris-innehåll), B-RET/B-SOR per kommun. Dessa är BESLUT, inte buggar — ska inte fixas ensidigt (HANDOVER §4 "DESLUT").

## DEMO_OR_STUB
- hubs_start/src/services/api.js — DEMO-grind isDemo() (boot.demoMode + ?demo=1 sessionStorage); varje exportfunktion har demo-gren före OCS-gren (26 OCS-anrop: 15 SDKMC_OCS + 11 HUBS_ARENDE_OCS). Default AV på skarp instans; tre-läges app-config hubs_start/demo_mode ('1'/'0'/auto via AppDetectionService)
- hubs_start/src/services/demo/{treserva,favoriter,socialsekreterare}.js — in-memory REGISTER-Map + fixturer, endast demo-grenen (K-8.3/K-8.10)
- hubs_arende/lib/Integration/Stub/FacksystemCommitStub.php — in-memory facksystem: mint:ar dnr, synchronousCallback default, verifierad=true utan riktig Treserva/Frends (GAP-019 EXTERN, 'stub by-design dev15'). Retention-mönstret (verifierad callback → gallrasDatum +90d) är dock kontraktstroget (rad 28-30)
- hubs_arende/lib/Integration/Stub/EdiariumStub.php — in-memory e-diarium för kat6 diariefor_direkt-hooken (introspekterbar via getDiarium i smoke)
- hubs_arende/lib/Integration/Stub/SigneringStub.php:19,117 — stateful in-memory Inera-simulering, syntetisk '%PDF-1.7 % stub-pades'-artefakt; ingen riktig AES
- hubs_arende/lib/Controller/InfodeController.php:405-407 — resolveInflodeRows() returnerar [] (tom seam): hubs_arendes klass/match/grind-pipeline kör aldrig på riktig data; riktiga feeden är sdkmc InflodeFeedService (som saknar kat-1–8-klassning)
- hubs_arende/lib/Service/ArendeMatchService.php:601-603 — registerPartHook() hårdkodat null: SSN/orgId-matchsteget är död seam (medvetet, fail-closed tills part-register wiras)
- hubs_start persona-vyer utom socialsekreterare — 5 personor (hsl_skoterska, hr_chef, overformyndare, registrator, forvaltare) renderar widget-grid där dataSource:'proposed'-widgets faller till WidgetFallback 'honest proposed feature card' (WidgetRenderer.vue:110); real-märkta widgets matas av summary-endpointen men persona-arbetsflödena (årsräkningar, utskrivningsbevakning, MCF-klockor m.m.) är mockups
- hubs_arende/lib/Service/DemoSeedService.php + lib/Command/SeedDemo.php — occ hubs_arende:seed-demo --purge (syntetiska testärenden; smoke lämnar syntetiska per-typ-rader, Smoke.php:290)
- sdkmc app-config hubs_start_inflode_demo — separat demo-grind för inflödet (0 = riktig data på dev15)
- dev15 EFEMÄRT (ej stub men försvinner): libresign 11.6.0 + openjdk/poppler apk + ALLA apps/sdkmc-backend-additions rensas vid container-recreate eller docker restart (NC-entrypoint apps-omsynk); custom_apps (hubs_start/hubs_arende) + DB överlever (HANDOVER §1 + session 3 lärdom 1)

## VERIFIED_WORKING
- Orosanmälan end-to-end i RIKTIGT GUI + DB-verifierat på dev15 (session 2b, inloggad användare): riktig Horde-msg → ta emot → case 224 → ärenderum (groupfolder 159 + talk_room btb4ziet + deck_card 153 + kalender-.ics + 2 mottagningskrets-medlemmar) → pliktGrind låser stepper → kvittera → CommitGrind → registrerad + dnr 2026-IFO-0501 + retention gallras_efter_commit (2026-09-16) → steg→utredning
- Alla 8 ärendetyper kör createCase→(hook)→commit→livscykel end-to-end på motornivå: live `occ hubs_arende:smoke` grön på dev15 (per-typ-loop 8/8, Smoke.php:196; [8b] hela resan utredning→beslut→uppfoljning→avslutat). DB-verifierat: rattsligt_tvang föds registrerad+SN-2026-0101 (diarium), familjeratt post-hook yttrande
- Testgrindar gröna (session 3): jest 88 (inkl. komponent-tester mot PROD-formad data), phpunit 72, php -l, webpack-bygg; hubs_start 1.2.15 + hubs_arende 0.7.5 deployade dev15, OCS-rutter 401-verifierade oautentiserat
- Alla 11 materiella säkerhetsfynd (H1-H3, M1-M6, L2) ur SAKERHETSGRANSKNING-MOTOR.md fixade i kod med regressionstester: authz-predikat enhetTillaten (ArendeService.php:621,667,751,776 + ArendeServiceAuthzTest), commit-route om-wirad via ArendeService::commit (ArendeController.php:178-186 + CommitIdempotensTest), pseudonym-validering (ArendeService.php:235-244 + ObjektRefValideringTest), pseudonyma objektnamn (rad 404,440,479), grind på läsvägen (InfodeController.php:104-112), breddad detektor fail-closed (SakerhetsskyddGrind.php:98-102 + M4Test), anonymitetsgrind ALLOW-lista (ArendeMatchService.php:539-568 + M5Test), UNIQUE conversation_id + gallras_datum (Version000100Date20260617120000.php:57-66)
- commitDestination-invarianten hävdad som NOT NULL i schemat (Version000000Date20260616000000.php:112-117) — B-INV-1/K-1.6/K-2.19 verkställd
- Inflöde-feed mot RIKTIG data: dedup på thread_root_id (4→2 dubbletter), behandlad-exkludering joinar sdkmc:s egna tagg-tabeller oc_sdkmc_itsl_message_tag/oc_sdkmc_itsl_tag, 'Ta emot' taggar källmeddelandet (case:{id}+behandlad, IDOR-säkert) — verifierat mot riktig orosanmälan-korg på dev15 (session 3 + commit 953c4f43)
- pliktGrind (GAP-001/ORO-1) GUI-verifierad: blockerad-utan-kvittering=JA, tillåten-med=JA; steg-advance trådar skyddsbedomningKvitterad controller→api→store
- Gallring fail-safe: GallringJob raderar ALDRIG rad utan provenance=registrerad + retention=gallras_efter_commit + gallras_datum<=now (GallringService.php:26-38) — GAP-007-mönstret på motornivå (mot stub)
- dev15-reset till känt testläge: scripts/dev15-reset.sh (committat, idempotent) — 2 otaggade orosanmälningar i 'Att ta emot'

## RISKS
- Kravdokument-divergens: HUBS-KRAVSTALLNING-TOTAL föreskriver motor i sdkmc/M0 + /api/v2 — bygget la den i hubs_arende + /api/v1. Kravens kodkarta (K-3.26/27, §11) är inaktuell; en kravtäckningsuppföljning mot dokumentet ordagrant ger falska 'saknas'. Dokumentet bör revideras eller en mapping-not skrivas
- Allt facksystem/diarium/signering är stubbar — 'verifierad callback' bevisar mönstret, inte integrationen. Dag ett en riktig FacksystemCommitPort registreras testas idempotens/callback-säkerhet mot verklig Treserva för första gången (säkerhetsgranskningens seam-varning §4)
- Efemärt dev15-läge: libresign + apps/sdkmc-tillägg försvinner vid container-recreate/`docker restart hubs-php` — måste bakas in i imagen/upstream innan någon demo/pilot (backend-additions/MANIFEST.md). En oavsiktlig restart har redan raderat tilläggen en gång
- Klassningslagret är byggt men dött: kat-1–8-klassning + matchkaskad kör mot tom feed (resolveInflodeRows=[]). När riktig feed wiras aktiveras latenta vägar — M3-ordningen (grind först) finns nu i kod men är overifierad mot riktigt PII-flöde
- P0.4-säkerhetsgolvet (Δ7/Δ8/Δ9) finns bara på grind-nivå med generisk detektor; B-SEC-1 (per-kommun-detektion, regimbyte höjd beredskap) ofattat — kravställningen förbjuder släpp utanför socialtjänst innan detta
- Juridiska säljblockerare öppna: PuB-/laglig-grund-matris (Δ17, 'osäljbart utan'), gallringströskel T-RET-1, DHP-källa T-DHP-1, GAP-031 (retention-paus vid TF-utlämnandebegäran har ingen trigger), delgivningsmodell
- Reconciliation-loop (GAP-056) saknas — register↔NC-objekt kan driva isär tyst; enda jobbet är GallringJob
- GUI-klick-verifiering ofullständig (BankID-gated): hela resan efter session 3-fixar, dokument-urval #5, rum-öppningar #10/#18, mail-overlay composer-deep-link (byggd men EJ deployad — egen byggkedja), demo-länken
- AB-01-felroutingrisk vilande: ansokan_bistand utan insats-subtyp-router; fail-closed fångar bara null-modul, ej fel-default — kräver migration + inflödesfältbekräftelse innan kat2 breddas
- hubs_arende är in-process AGPL idag — proprietär M4-option (B-LIC-1) kräver fortfarande ExApp-lyft + IP-jurist; inget beslut fattat

## NEXT_STEPS
- Revidera kravställningens kodkarta (eller skriv mapping-not): sdkmc/M0-motor → hubs_arende, /api/v2 → /api/v1, tabellnamn sdkmc_arende → oc_hubs_arende_case — annars felmäter varje framtida täckningsanalys
- Fatta de blockerande B-besluten (ej ensidigt byggbart): B-MOD-1 (sdkmc M0/M1-split), B-SEC-1 (detektions-semantik + regimbyte), B-LIC-1 (ExApp+IP-jurist), B-SIGN-1 (Inera-avtal — längst ledtid, starta först), T-PUB-1 (PuB-matris), T-RET-1/T-DHP-1 (gallring)
- Persistera dev15-tilläggen: baka in libresign + openjdk/poppler + apps/sdkmc-additions i imagen eller driv upstream-PR (backend-additions/MANIFEST.md har märkningskonventionen klar)
- Bygg ArendeReconciliationJob (GAP-056, mönster GallringJob) + automatiserat tre-lagers-ACL-koherenstest (GAP-058)
- Wira riktig inflöde-feed till klass/match-pipelinen (sdkmc InflodeFeedService → hubs_arende inflodeSummary) — M3-grind-ordningen finns; komplettera med KategoriBadge.vue (K-6.26, enda obyggda UI-atomen) + kat-tagg-konvention
- GAP-031: bygg retention-paus-hook vid registrerad utlämnandebegäran (TF) — idag finns bara enum-värdet 'pausad'
- Δ-breddning som migrations-serie när första icke-soc-roll prioriteras: Δ1 systemOfRecord/sorFallback/inflodeVia, Δ2 sekretessGrund[]-struct, Δ5 verksamhetsgrens-mur (HSL kräver den), Δ6 joinNyckel — mestadels datafält (~85 % enligt K-8.5-noten)
- e-diarium/e-arkiv-konnektor som andra konnektor-instans (K-5.14 — avlastar 9 roller och bevisar modul×produkt-mappningen); EdiariumPort-kontraktet finns redan
- GUI-klick-verifiera session 3-leveranserna med inloggad användare (hela resan till avslut, signeringsbekräftelsen i CommitGrind, demo-länken) + deploya/verifiera mail-overlay-modulen initComposerDeepLink (punkt 4)
- ENGINE-konsumtion av payload.valdaDokument (frontend skickar dokument-urval vid commit; motorn läser det inte än — flaggat produktbeslut session 2d)