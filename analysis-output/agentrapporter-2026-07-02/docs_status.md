# docs_status

## SUMMARY
Statusdokumenten beskriver tre generationer av läget: STATUS-OCH-ROADMAP/AUTONOM-KORNING (2026-06-17) är delvis inaktuella — sessionerna 2–3 (2026-06-18/20, HANDOVER-FORTSATTNING §4) har stängt Fas 0 (INTEGRATION_MODE-nyckelglappet fixat, CI-workflow-fil finns), tänt rummen (seam A/B: riktiga groupfolder/Talk/Deck/kalender/medlemmar GUI-verifierade), byggt hook-infran (kat6/kat8), pliktGrind, alla 19 GUI-fynd samt hela resan till avslut (smoke [8b]). Beslutsloggen är ratificerad 2026-06-16: 22 beslut låsta (varav 01/02/03/06/21 i reviderad form), 3 externt öppna (08 Inera-avtal, 09 AI-vägledning, samt B-PUB-1-INNEHÅLLET). Av de 34 orosanmälan-gapen är ~22 stängda, ~10–12 kvarstår; av GAP-ANALYSIS.md:s blockerare är GAP-007-mönstret och GAP-010/057 stängda i motorn medan GAP-019/031/034/052 kvarstår (extern-avtal/juridik). Det som genuint återstår delas i: tunna buggar/overifierade GUI-ytor (BankID-login-blockerad), obyggda ytor (event-feed, Frends-live, partsregister, Inera-adapter, DIGG-favoritresolver, ReconciliationJob, ExApp), produktbeslut som kräver användaren (AB-01, FAM-2/3, AB-04/06, KOMPL-07, valdaDokument-konsumtion) och juridik/kommersiellt (PuB-matris, Inera-avtal, IMY-vägledning, SKU).

## DETAILS
# Syntes: status-/beslutsdokumenten (hubs_start/docs + roten)

**Viktig läsanvisning:** dokumenten är skrivna vid olika tidpunkter. `STATUS-OCH-ROADMAP.md` + `AUTONOM-KORNING-STATUS.md` = 2026-06-17 (före sessionerna 2–3); `HANDOVER-FORTSATTNING.md` §4 = 2026-06-20 (senast). Flera "öppna" punkter i de äldre dokumenten är redan lösta. Allt nedan är korsverifierat mot kod idag (2026-07-02).

---

## 1. Öppna punkter per dokument — status

### 1a. PUNCHLIST.md (hubs_start-roten)
Alla ✅-sektioner (blockers/majors/minors) är fixade sedan tidigare. Status för `⏳ Remaining` (verifierat mot `TODO(hubs-start)`-markörer i koden idag):

| Punkt | Status | Evidens |
|---|---|---|
| Receipt `updated_at`-kolumn saknas | **ÖPPET** | `hubs_start/backend-additions/sdkmc/lib/Service/SummaryService.php:677`, `Controller/OCS/SummaryController.php:299` |
| PENDING-semantik / MW-statusvokabulär obekräftad | **ÖPPET** | `SummaryService.php:819`, `SummaryController.php:228` |
| SummaryController duplicerar receipt-listning (ska delegera till `buildReceipts`) | **ÖPPET** | Ingen `buildReceipts`-referens i `SummaryController.php`; egna TODO:s kvar rad 228/266/299/343 |
| Fabricerad assignment-label (`$assignee_{userId}`) | **ÖPPET** (inget fix-spår) | PUNCHLIST rad 58–61 |
| INBOX-predikat (`mail_mailboxes.name` vs `special_use`) | **ÖPPET** (obekräftat) | PUNCHLIST rad 65–68; notera dock att session 3 bevisade att sdkmc-tag-joinen bytts till rätt tabeller (commit `953c4f43`) |
| Cross-app-route-säkerhet (widgets → `linkToRouteAbsolute` kastar om hubs_start disabled) | **ÖPPET** (inget fix-spår) | PUNCHLIST rad 65–68 |
| Secure-meeting loopback-credentials | **DELVIS ÅTGÄRDAT** | `createTalkRoom` kör nu IN-PROCESS `\OCA\Talk\Service\RoomService::createConversation` (fix 2026-06-18, se minnet hubs-orosanmalan-livetest); kvar: `addEmailParticipant` m.fl. — `SecureMeetingService.php:499,524` |
| Intent `eventUid`-matchning (SMS/securemail poppas per e-post ur session) | **ÖPPET** | `SecureMeetingService.php:415,440` |
| `ConversationBankIDAuthMapper::findByConversation()` | **ÖPPET** | `MeetingService.php:438` |
| `resolveLoa()` hårdkodad LOA3 | **ÖPPET** | `SummaryService.php:903` |
| SystemHalsa aria-live för "armed"-läget | **ÖPPET** (inget fix-spår) | PUNCHLIST rad 82–83 |
| Mail `?to=` SDK-deep-link kan ej prefylla adresspar | **ÖPPET** | `backend-additions/mail/initITSL-additions.js:142` — MEN session 3 byggde `hubs-code/mail/mail-main/overlay/src/itsl/utils/initComposerDeepLink.js` för `&case=`-koppling (punkt 4) — **byggd, EJ deployad** (egen byggkedja, kräver GUI-verify) |
| Ingen namngiven `/new`-route i mail | **ÖPPET** | `initITSL-additions.js:222` |

### 1b. STATUS-OCH-ROADMAP.md (roadmapen Fas 0–4, seams A–K)
| Punkt | Status idag |
|---|---|
| **Fas 0.1 INTEGRATION_MODE-nyckelglapp + död port-DI** | **ÅTGÄRDAT** — `hubs_arende/lib/Service/FacksystemCommitService.php:34–47` (EN canonical nyckel `integration_mode_facksystem`, dokumenterad "Fixat") + porten DI-injiceras och KONSUMERAS (konstruktor rad 63–67, `port()` rad 138 = lazy fallback endast för DI-lös testharness) |
| **Fas 0.2 CI-pipeline (seam J)** | **DELVIS** — `hubs_arende/.github/workflows/ci.yml` finns (phpunit-matris PHP 8.1–8.3 + php -l). Overifierat att den faktiskt körs mot en GitHub-remote; jest (hubs_start) ingår EJ; testerna körs dock lokalt/gröna (jest 88, phpunit 72 per session 3) |
| **Seam A (integrations-AUTH R3–R9)** | **DELVIS ÅTGÄRDAT** — `hubs_arende/lib/Integration/ServiceAccountAuth.php:33–58` byggd (`sa_user`/`sa_token` app-config, graceful null). `CalendarClient` skriver in-process via `CalDavBackend` (CalendarClient.php:31,200). Riktiga rum SKAPAS på dev15 (session 2b: groupfolder 159 + talk btb4ziet + deck 153 + .ics + medlemmar, DB-verifierat). Kvar-TODO[auth]: GroupfolderClient ACL-enable/delete (rad 136,160), SpreedClient (33,69,258), SdkmcClient tag-delete (250) |
| **Seam B (saga-rummen R3–R9)** | **I HUVUDSAK ÅTGÄRDAT** — GUI-E2E-verifierat session 2b; R6-ACL-kretsen löst via Fas E per-ärende-NC-grupp (`ArenderumGroupService`) + medlemsledger (`hubs_arende_member`, Member/MemberMapper) + handoff-avsmalning (GAP-057) |
| **Seam C (event-driven inflöde-feed, `MessageReceivedEvent`)** | **ÖPPET** — eventet finns fortfarande inte i kod (bara doc-referenser + InfodeController-kommentar). MEN pull-feeden via sdkmc `InflodeFeedService` är LIVE mot riktig data (demo-grind 0, dedup på thread_root_id, behandlade exkluderas via sdkmc:s egna tag-tabeller — session 3) |
| **Seam D (live FacksystemCommitPort / Frends)** | **ÖPPET** — ingen `hubs_arende/lib/Integration/Live/`-katalog finns (verifierat med glob) |
| **Seam E (Bergsby-provisionering)** | **DELVIS** — orosanmälan-korgen (mailbox 4) finns med riktiga meddelanden + hubs-case-grupp; full brevlåde-/användarprovisionering öppen |
| **Seam F (part-/personregister)** | **ÖPPET** — `ArendeMatchService.php:601–605` `registerPartHook()` returnerar hårdkodat `null` (fail-closed by design) |
| **Seam G (Inera-signering)** | **ÖPPET** — `SigneringPort`+`SigneringStub` bundna, ingen `IneraSigneringAdapter`; frontenden har nu bara "Jag har signerat"-gate inbäddad i CommitGrind (session 3, commit `9e4f0645`) |
| **Seam H (e-diarium/FGS)** | **DELVIS ÅTGÄRDAT** — `preSagaHook='diariefor_direkt'` DISPATCHAS nu (session 2 RT-1: kat6 föds registrerad+dnr, live `SN-2026-0101`; kat8 post-commit-hook FAM-1). EdiariumPort har alltså konsument. Kvar: live FGS-adapter + `arkivera()`-wiring |
| **Seam I (ExApp-paketering)** | **ÖPPET/BLOCKERAT** — app_api 3.2.0 disabled (kraschar på ITSL NC31), docker.sock ej monterad; in-process v1 by design |
| **Seam K (AI/LLM)** | **ÖPPET by design** — fas 10 per BESLUT-09 |
| Monitoring (metrics/alerting) | **DELVIS** — admin-statussida + `occ hubs_arende:status` byggda (v0.4.0); ingen metrics/alerting/healthcheck |
| Frontend-kortets "aldrig innehåll, bara pekare"-brott i demo | **ÅTGÄRDAT arkitektoniskt** — hybridbeslutet (#3, gui-decisions.txt): motorn returnerar `pekare`-block i mapToFullCard, PII-berikning i sdkmc `GET /api/v1/arende-enrichment` (session 2d); graceful-empty på tunn dev15-data |
| Tester aldrig körda / ingen regression | **ÅTGÄRDAT** — jest 88 + phpunit 72 körs lokalt gröna, komponenttester med PROD-formad data aktiverade (babel-bridge), smoke utökad ([8b] hela resan) |

### 1c. AUTONOM-KORNING-STATUS.md
Allt "✅ VERIFIERAT" står sig. §9-listan (medvetna seams) = samma som seams A–K ovan; delta sedan dess: rummen tänds (A/B), hookarna dispatchas (H-delen), lifecycle utökad till avslut. Säkerhetsremediering H1/H2/H3 + M1–M6/L1–L7 = **KLAR och verifierad** (smoke + schema).

### 1d. FRONTEND-WIRING.md
Beskrivningen stämmer fortfarande: 5 ärende-funktioner live mot `hubs_arende/api/v1` med `if (DEMO)`-fallback. Delta sedan dokumentet: `transitionSteg` (POST /arende/{id}/steg med `skyddsbedomningKvitterad`-kontext), koppla-flödet (Väg A user-session-tagg + referens-fil), NoteToSelf/enrichment-rutter i sdkmc, samt `isDemo()` med `?demo=1/0`-override (`demoData.js:31–47`). Dokumentets "Fortfarande DEMO/sdkmc"-lista är medvetet design, inte skuld.

### 1e. IMPLEMENTATIONSPLAN-REVISION.md
Alla 7 ratificerade revisionspunkter (R-1…R-7) är **genomförda och styrande**: sdkmc orörd (tillägg = märkta backend-additions), `hubs_arende` standalone med egen DB (verifierad på dev15), dev15 = byggmiljö (bootstrap.sh + dev15-reset.sh), ExApp-rent men in-process, Inera-stub, AI senare fas. §6 P0-stegen 1–6 = klara.

---

## 2. HUBS-BESLUTSLOGG — LÅST vs ÖPPET

Ratificering 2026-06-16: "ja till alla rekommendationer" + 7 revisioner. Netto:

**LÅSTA (22):** BESLUT-01 (reviderad: **datalagret = egen DB i `hubs_arende`**, `oc_hubs_arende_case/_typ/_flagga/_pekare` + `_member`, ExApp-rent, ExApp-DB senare — Tables UNDERKÄND; verifierat i drift), 02 (**STÄNGT: IP-juristen har godtagit ExApp-lösningen**), 03 (**UTGÅR** — ingen sdkmc-split), 04, 05, 06 (reviderad: dev15 ÄR byggmiljön), 07 (Treserva först + Ediarium test-orakel — stub byggd+konsumerad), 10, 11 (NOT NULL byggd/schema-verifierad; per-kommun `sorFallback`-config återstår), 12, 13, 14, 15, 16, 17, 18, 19, 20, 21 (reviderad: `hubs_arende/api/v1`), 22 (**implementerad** session 2 hook-infra), 23, 24, 25 (mönstret implementerat + smoke-verifierat mot stub).

**ÖPPNA (externt underlag):** BESLUT-08 (Inera-avtal/anslutning; inriktningen "stub nu, Inera mål" är låst), BESLUT-09 (AI; inriktningen "senare fas, aldrig autonomt på sekretess" låst, IMY/SKR/Soc-vägledning saknas).

**De specifikt efterfrågade:**
- **B-MOD-1 (sdkmc M0/M1-split):** formellt **INAKTUELL/ERSATT** — BESLUT-03 UTGÅR per ratificeringen; separationen sker via standalone-appen. **OBS diskrepans:** HANDOVER-FORTSATTNING §4 (rad 214–215) listar B-MOD-1 fortfarande som öppet beslut — det som realistiskt kvarstår är SKU-/paketeringsfrågan ("sälj M4 separat"), inte själva splitten. Flagga för användaren snarare än att återöppna.
- **B-LIC-1 (proprietär M4 + IP-jurist):** **LÅST/STÄNGT** (BESLUT-02: ExApp-vägen juridiskt godtagen). Residual = själva ExApp-paketeringen (seam I, infra-blockerad) + ev. formella licensvillkor. HANDOVER §4 listar den ändå — behandla som "stängd med genomförande-rest".
- **B-SEC-1 (säkerhetsskydd):** beslutet **LÅST** (BESLUT-13, fail-closed, ej dev-blockerare) och kärnan **BYGGD+VERIFIERAD** (`SakerhetsskyddGrind` R0 före allt, smoke [7] personnummer avvisat; retroaktiv karantän finns — sätter bl.a. `retention_state='pausad'`, `SakerhetsskyddGrind.php:330`). **Kvarstår:** regimbyte (höjd beredskap/krig) samt prod-kravet "klart FÖRE utrullning till icke-socialtjänst-roller".
- **B-PUB-1 (PuB-/laglig-grund-/DPIA-matris):** beslutet om FORMEN **LÅST** (BESLUT-12: config-fält i ArendeTyp från dag 1). **INNEHÅLLET ÖPPET** — matrisen per kund (nämnd + DSO) finns inte; dokumenten är eniga: "juridiskt osäljbart utan den". Detta är den tyngsta juridiska resten.
- **Datalager-beslutet (B-DL-1/BESLUT-01):** **LÅST och genomfört** (se ovan).
- **FAM-2 (partsmodell-datamodell) / FAM-3 (partsåtskild ACL `familjeratt_inre_sekretess`, Δ5/GAP-058):** **ÖPPNA produktbeslut** — uttryckligen "fixa EJ ensidigt" (HANDOVER §4 rad 212–213). FAM-1 (post-commit-hook) är däremot byggd/verifierad.
- **AB-01 (kat2 insats-sub-typ-router):** **ÖPPET, utpekat som "Top nästa steg"** — kräver (a) bekräftelse att `insatsTyp` finns på inflöde-raden, (b) migration som persisterar resolverad frendsModul/insatsTyp på case-raden (annars felroutingrisk LSS/ek-bistånd → `ifo_vuxen`). MODUL-FAILCLOSED (byggd, session 2) fångar bara null-modul, inte fel default.
- **AB-04/AB-06 (behörighets-grind / diarieför-vid-start):** **ÖPPNA beslut** (samma DESLUT-lista).
- **KOMPL-07 (komplettering tillförs befintligt dnr):** **ÖPPET beslut**. By-design-noterat: pure-attach-typers standalone-create ska gateas i klassningslagret (`ArendeMatchService` [BYGGS]), inte i motorn.
- Även öppet i samma lista: **per-kommun funktionsadresser** (ekonomi@ vs ekb@).

---

## 3. Gap-status — två olika register (viktigt att inte blanda ihop)

### 3a. "De 34 gapen" = orosanmälan-spec-vs-kod-analysen (2026-06-18; 1 blocker, 25 major, 8 minor)
**Stängda (våg 1, v1.2.9, flera GUI-verifierade):** gap1 (BLOCKER steg-lifecycle — GUI-verifierad), gap4 (durabelt conversationId), gap5 (inkomDatum-forward), gap6 (messageIds), gap12 (flip på ok&&verifierad), gap16/18 (möte-shape+dnr-extrakt backend), gap19 (dokument-enum), gap20 (onOpenRum→groupfolder), gap21/22/25/34 (plikt/nästa-åtgärd/puls/klartIdag), gap23 (föds i forhandsbedomning), gap27 (messageType), gap31 (retention-text), gap33 (dagspuls-filter) ≈ 20 st.
**Stängda EFTER våg 1 (session 2–3):** gap28 (preSagaHook kat6 = RT-1, live-verifierad), gap13 (plikt-gate = ORO-1 pliktGrind, GUI-verifierad session 2b), gap9/10 **delvis** (Väg A: user-session-taggning IDOR-säker via `ItslTagService::tagMessages`; koppla-admin-väg gated default-off `koppla_admin_tag`).
**Kvarstår (~10):** gap7 (skyddsbedömnings-materialisering i sagan — kvitteringen finns, dokument-materialiseringen inte), gap11 (Frends live-port — by design), gap17 (**möte-ärende-bindning FRONTEND** — MeetingWizard skickar fortfarande inte dnr; backend redo), gap26 (persona-per-grupp), gap3 (KategoriBadge), gap8/29/30 (koppla/gallra-detaljer — koppla i stort byggd via KopplaValjare/referens-fil, gallra-detaljer kvar).

**19-fyndslistan (session 2c/2d/3, separat numrering #1–#19):** ALLA byggda+deployade (hubs_start 1.2.15 / hubs_arende 0.7.5). Kvarstår endast: (i) GUI-klick-verifiering av #1/#5/#6/#10/#12/#18 (BankID-blocker), (ii) motorn konsumerar EJ `payload.valdaDokument` (#5 — frontend skickar, motorn ignorerar), (iii) #18 grupp→rum-token null tills enhetschatt-rum provisioneras, (iv) punkt 4 mail-overlay byggd men EJ deployad.

### 3b. GAP-ANALYSIS.md-registret (GAP-001…055, walkthrough-registret 2026-06-14 — OBS: skrivet mot gamla premisser Tables/sdkmc-orkestrering)
**Blockers (6):**
- GAP-001 (skyddsbedömningens dokumentation): **DELVIS** — ORO-1 tvingar kvittering före utredning; kanonisk dokumentationsregel (facksystem först) = öppet produkt/juridik-beslut.
- GAP-007 (gallring på verifierad commit): **STÄNGD SOM MÖNSTER i motorn** (retention flippar ENBART på verifierat kvitto; GallringJob dubbelvakt; smoke [9]) — **live-stängning väntar på Frends-callback (= GAP-019)**.
- GAP-019 (Treserva-konnektorn): **ÖPPEN** (ingen Live-adapter; stub default).
- GAP-031 (retention-paus vid utlämnandebegäran): **ÖPPEN** — `retention_state='pausad'` finns som värde men ingen utlämnande-hook (grep bekräftat).
- GAP-034 (Inera-AES): **ÖPPEN** (externt avtal; LibreSign 11.6.0 installerad på dev15 = demo, efemär i containern).
- GAP-052 (AI röd zon): **ÖPPEN by design** (väntar vägledning).

**Majors med rörelse:** GAP-057 (fördelnings-ACL-race): **ÅTGÄRDAD i motorn** (Fas E per-ärende-NC-grupp + handoff-avsmalning, live-verifierad, adversariellt granskad). GAP-058: **DELVIS** (member-ledger + per-case-grupp; koherens-test saknas). GAP-010 (ett-klick-orkestrering): **STÄNGD** (createCase-sagan, GUI-verifierad). GAP-002/046 + GAP-055 (frist från inkom-datum): **ÅTGÄRDADE i motorn** (R8 ankrad i inkomDatum). GAP-005/041 (token↔dnr / conversationId-mappning): **DELVIS** (register parar hubsCaseId↔dnr, UNIQUE på conversation_id; syskon-1:n-modell öppen). GAP-053 (anonym avsändare legitimt): **DELVIS** (identitet-/verifierad-källa-badge i feeden, session 2d). GAP-056 (reconciliation/backup): **ÖPPEN** — ingen `ArendeReconciliationJob` i koden (grep bekräftat) trots låst BESLUT-19.
**Övriga majors/minors** (GAP-003/004/012/017/018/047/038/039/035/037/040/006/013/043/044/045/048/049/016/021/024/025/029/009/032/033 + minors): **ÖPPNA** — nästan alla hänger på konnektor (GAP-019), externa avtal (Inera) eller per-kund-juridik/process (DHP, delgivningssätt, kravnivåmatris). GAP-061–064 (favoriter/DIGG): **ÖPPNA** (favorit-stubben kvar; DIGG-resolvern ej byggd, pekare alltid `stale:true`).

---

## 4. Konsoliderad, deduplicerad KVARSTÅR-lista

### (a) Buggar / tunna gap (byggbart nu, inga beroenden)
1. GUI-klick-verifiering av session 2d/3-ytorna (#1 feed-dedup/excerpt, #5 dokument-urval, #6 signeringssektionen, #10/#18 rum-öppning, #12 anteckningar, demo-länken, hela resan till avslut) — **kräver användarens BankID-login**.
2. Motor-konsumtion av `payload.valdaDokument` (frontend skickar, `ArendeService::commit` läser ej).
3. gap17: MeetingWizard skickar inte dnr → möte-ärende-bindning (backend redo).
4. PUNCHLIST-resterna i sdkmc-additions: receipt `updated_at`, PENDING/MW-vokabulär, SummaryController-duplicering, fabricerad assignment-label, INBOX-predikat, cross-app-route-guard, eventUid-intent, `findByConversation()`, `resolveLoa()`, SystemHalsa-aria.
5. Deploy + verify av mail-overlayn (punkt 4, `initComposerDeepLink.js` — byggd, ej deployad, egen byggkedja).
6. Kvarvarande TODO[auth]-vägar (Groupfolder-ACL-enable/delete, Spreed-vägar, sdkmc tag-delete) + provisionera `sa_user`/`sa_token` formellt.
7. CI: verifiera att `hubs_arende/.github/workflows/ci.yml` faktiskt kör på remote; lägg till jest + deny-vägs-tester; PII-seed-grind (BESLUT-16) ej byggd.
8. gap3 KategoriBadge; gap8/29/30 gallra-detaljer.
9. Persistens: `apps/sdkmc`-tillägg + libresign + apk-paket är EFEMÄRA i containern (rensas vid container-recreate/`itsl deploy`; ALDRIG `docker restart hubs-php`) — måste in i image/upstream (MANIFEST.md).

### (b) Obyggda ytor (större byggen, interna)
1. **Seam C:** event-driven inflöde-feed (`MessageReceivedEvent` + Listener i sdkmc) — pull-feeden fungerar men är poll-baserad.
2. **ArendeReconciliationJob + arkivkritisk backup** (BESLUT-19/GAP-056) — beslut låst, kod saknas.
3. **Retention-paus vid utlämnandebegäran** (GAP-031) — blocker för skarp drift.
4. **DIGG-favoritresolver** (GAP-061–064) + favorit-PII-servervalidering.
5. **Regimbyte** höjd beredskap (B-SEC-1-rest) + skyddsbedömnings-materialisering (gap7).
6. **ExApp-paketering** (seam I — itsl-infra: app_api-version, docker.sock).
7. Persona-per-grupp (gap26); enhetschatt-rum-provisionering (#18); maskerings-/sekretessprövningsstöd vid delning (GAP-017); delgivningsmodellering (GAP-038/039).
8. Bredd-koll: mappa HUBS-KRAVSTALLNING-TOTAL (268 krav) täckt-vs-missat + djuptest av de 7 övriga ärendetyperna i GUI (motor-nivå klar 8/8) — HANDOVER §5.

### (c) Produktbeslut som kräver användaren (fixa EJ ensidigt)
1. **AB-01** kat2-insatsrouter (top nästa steg; kräver insatsTyp-bekräftelse + migration).
2. **FAM-2** partsmodell-datamodell, **FAM-3** partsåtskild ACL (Δ5/GAP-058).
3. **AB-04/AB-06** behörighets-grind / diarieför-vid-start.
4. **KOMPL-07** komplettering→befintligt dnr.
5. Per-kommun funktionsadresser (ekonomi@ vs ekb@).
6. GAP-012/032: var skrivs utredningstexten per kund (BESLUT-24 ger default "facksystemet", per-kund-konnektordesign kvar).
7. #12-anteckningar: case-scoping ja/nej; #5 per-ärendetyp-dokumentpolicy; bevaknings-board per etat/enhet (gui-decisions.txt).
8. B-MOD-1/SKU: bekräfta att standalone-app-strategin även stänger paketeringsfrågan (BESLUT-17-affären).

### (d) Juridik / kommersiellt (extern ledtid — starta tidigt)
1. **PuB-/laglig-grund-/DPIA-matrisen (B-PUB-1-innehållet)** — "juridiskt osäljbart utan den"; nämnd+DSO per kund.
2. **Inera-avtal + Underskriftstjänst-anslutning** (BESLUT-08, GAP-034/033/035/037; mTLS-vs-OOB-profil verifieras vid implementation).
3. **IMY/SKR/Socialstyrelsen-vägledning för AI på sekretess** (BESLUT-09/GAP-052).
4. **Frends/Treserva-miljö + facksystem-testinstans** (seam D/GAP-019 — längst ledtid, grundorsak bakom ~10 följdgap).
5. Part-/personregister-anslutning + sekretessgranskning TF 2:18 (seam F).
6. DHP-förankring (gallringsbeslut GAP-008/026), BBIC-licens (GAP-011), kravnivåmatris per kommun (GAP-036), delgivningsrättsläge (GAP-038).
7. SKU/prissättning + per-konnektor-licens (BESLUT-17 — affärsspår).

## DEMO_OR_STUB
- hubs_start/src/services/demoData.js:31-47 — isDemo(): demo-gate, styrs av ?demo=1/0 (persist i sessionStorage 'hubs_demo') → annars server-injicerad boot.demoMode; default AV på dev15 (demo_mode=0)
- hubs_start/lib/Controller/PageController.php:97-98 — isDemoMode(): app-config hubs_start/demo_mode '1'=på, '0'=av, tomt=AUTO (på när sdkmc saknas)
- hubs_start/src/services/api.js — 31 st `if (DEMO)`-short-circuits (en per exportfunktion); prod-OCS-grenen ligger under varje
- hubs_start/src/services/demo/treserva.js — stateful in-memory Treserva/Frends-stub (REGISTER/RECEIPTS-Map); körs ENDAST i demoläge; seedRegister vid import av socialsekreterare.js
- hubs_start/src/services/demo/favoriter.js — 3 favorit-DTO:er + tombstone; live-vägen GET /favoriter finns men DIGG-resolvern är obyggd (pekare alltid stale:true) → live-läget är i praktiken också tunt
- hubs_start/src/services/demo/socialsekreterare.js + demoData.js — alla persona-fixtures (triage/arenden/puls/moten/receipts/recipients); endast demoläge
- hubs_start/backend-additions/demo-data/InflodeDemoData.php:62 — 14 syntetiska PII-bärande inflöde-rader; gate = sdkmc app-config 'hubs_start_inflode_demo' (InflodeFeedService.php:72, default '0'; dev15=0 → riktig data)
- hubs_arende/lib/Integration/Stub/{FacksystemCommitStub,SigneringStub,EdiariumStub}.php — port-stubbar; gate = app-config integration_mode_{facksystem,signering,ediarium} (Application.php:46 prefix, resolvePort:102-121), default 'stub'; INGEN Integration/Live/ finns → 'live' resolverar ändå till stub
- hubs_arende/lib/Integration/ServiceAccountAuth.php:33-58 — saknad sa_user/sa_token ⇒ authorizationHeader()=null ⇒ OCS-klienterna 401:ar graceful (no-op); rum skapas ändå på dev15 via in-process-vägar/konfig
- hubs_arende/lib/Service/ArendeMatchService.php:601-605 — registerPartHook() hårdkodad `return null` (SSN-matchning avstängd, fail-closed by design tills partsregister wiras)
- hubs_start/backend-additions/sdkmc/lib/Service/SummaryService.php:903 — resolveLoa() hårdkodad LOA3 (SPA:n läser dock live-LOA ur getSettings)
- hubs_start/backend-additions/sdkmc/lib/Service/TeamService.php:188 — team-token/olästa hårdkodat null/0 tills grupp→Talk-rum-mappning provisioneras
- hubs_arende/lib/Command/SeedDemo.php + lib/Service/DemoSeedService.php — occ hubs_arende:seed-demo (syntetisk testdata, --purge för rensning); körs bara manuellt
- CommitGrind 3-stegs Frends-progress (hubs_start/src/components/...) — ren setTimeout-animation, inte riktig callback (per STATUS-OCH-ROADMAP §3; motorns commit-väg är skarp)
- LibreSign 11.6.0 + openjdk/poppler på dev15 — demo-signering med självsignerad rot-CA ('Hubs Demo CA'), EFEMÄR i containern; Inera är beslutad prod-backend (BESLUT-08)

## VERIFIED_WORKING
- Motor end-to-end på dev15: `occ hubs_arende:smoke` GRÖN inkl. [8b] hela resan utredning→beslut→uppfoljning→avslutat + per-typ 8/8 + pliktGrind (HANDOVER §4 session 2/3; hubs_arende 0.7.5)
- GUI-E2E med RIKTIG orosanmälan (session 2b, inloggad användare): ta emot → case 224 med fullt ärenderum (groupfolder 159, talk btb4ziet, deck 153, kalender-.ics, 2 medlemmar) → pliktGrind-lås → commit 'För över' → registrerad + dnr 2026-IFO-0501 + gallras 2026-09-16 → steg→utredning; DB-verifierat i oc_hubs_arende_case/_pekare/_member
- Testgrindar gröna lokalt: jest 88 (inkl. komponenttester med PROD-formad data), phpunit 72 (composer:2-docker), webpack-bygg grönt (session 3)
- Säkerhetsremediering H1/H2/H3 + M1-M6/L1-L7 klar: objektnivå-authz fail-closed (deny-väg adversariellt egen-verifierad), commit-idempotens (smoke [6] samma dnr), PII-pre-flight (smoke [7] personnummer avvisat), UNIQUE conversation_id (schema-verifierat)
- never-SoR-invarianten schema-enforcad: commit_destination + hubs_case_id NOT NULL verifierade i information_schema på dev15
- GDPR-gallring: GallringJob registrerad, dubbel säkerhetsvakt, smoke [9] rad purgad
- INTEGRATION_MODE-nyckelglappet FIXAT i kod: FacksystemCommitService.php:34-47 canonical nyckel + DI-porten konsumeras (konstruktor rad 63-67) — verifierat vid denna genomgång
- Hook-infran live-verifierad (session 2): kat6 diariefor_direkt föds registrerad+dnr SN-2026-0101 (diarium=1), kat8 post-commit-hook, MODUL-FAILCLOSED (ingen tyst ifo_barn-default)
- Feed-korrekthet mot riktig data (session 3): 'Ta emot' taggar källmeddelandet (case:{id}+behandlad, user-session/IDOR-säkert) och behandlade lämnar feeden — joinar sdkmc:s EGNA tag-tabeller (commits 713b63e0 + 953c4f43)
- Ärenderum-ACL Fas E: per-ärende-NC-grupp 'hubs-case-{id}' + handoff-avsmalning (tilldelat ⇒ krets revokeras) live-verifierad; medlemsledger 20 rader + 10 group-grants (arenderum-agarmodell-minnet)
- Reset-rutin: scripts/dev15-reset.sh idempotent (0 ärenden, 2 otaggade orosanmälningar) + provision/bootstrap.sh dry-run-validerad
- CI-workflow-fil finns: hubs_arende/.github/workflows/ci.yml (phpunit-matris 8.1-8.3 + php -l) — OBS körning mot GitHub-remote ej bevisad
- #6 signerings-hang fixad (commit 9e4f0645): en modal i st.f. staplade NcModaler — kodad+deployad, GUI-klick-verifiering återstår

## RISKS
- GAP-019/007 live: gallringsklockan är bara verifierad mot STUB-kvitton — om integration_mode flippas till 'live' utan riktig Frends-callback-verifiering resolverar DI ändå till stubben (tyst), och en framtida slarvig live-adapter utan callback-verifiering vore arkivlagsbrott; blockern kvarstår tills seam D byggs
- apps/sdkmc-tilläggen + libresign + apk-paket är EFEMÄRA: `docker restart hubs-php` eller `itsl deploy` RENSAR dem (kritisk drift-lärdom 1, session 3) — allt måste in i image/upstream innan någon annan rör dev15
- GUI-klick-verifiering är strukturellt blockerad (BankID-login kan inte göras autonomt) → session 2d/3-ytorna är kodade+deployade men flera aldrig klickade i riktigt GUI
- payload.valdaDokument har ingen backend-verkan — handläggarens dokument-urval i CommitGrind ser ut att fungera men ignoreras av motorn (falsk trygghet)
- PuB-/laglig-grund-matrisen saknas → produkten är enligt egna dokument 'juridiskt osäljbart' tills nämnd+DSO-innehållet finns per kund
- ArendeReconciliationJob + arkivkritisk backup (BESLUT-19/GAP-056) är beslutade men obyggda — registerförlust = total ärendekopplingsförlust utan återställning
- GAP-031: ingen retention-paus vid utlämnandebegäran — gallring kan i teorin radera en begärd handling (blocker för skarp drift)
- CI-pipelinen är overifierad på remote och saknar jest + deny-vägs-tester; PII-seed-grinden (BESLUT-16) obyggd
- ?demo=1-länken fungerar på skarp instans (sessionStorage-persist) — låg men reell risk att demoläge visas/lämnas kvar i en skarp session
- Dokument-drift: STATUS-OCH-ROADMAP/GAP-ANALYSIS beskriver delvis inaktuella premisser (Tables-register, sdkmc-orkestrering, 'rummen skapas inte') — risk att gamla dokument styr nya beslut fel; HANDOVER §4 listar B-MOD-1/B-LIC-1 som öppna trots att ratificeringen stängt dem (oavstämd diskrepans)
- Anomalin från session 2c (GUI-skapat case föddes beslut+registrerad) är noterad 'bevaka' men aldrig rotorsaksförklarad

## NEXT_STEPS
- Låt användaren BankID-logga in och GUI-klick-verifiera hela resan (ta emot→tagg→förhandsbed→utredning→beslut→signering→uppföljning→avsluta) + #1/#5/#6/#10/#12/#18 + demo-länken (HANDOVER §4 'KVAR ATT GUI-KLICK-VERIFIERA')
- AB-01 (kat2 insats-router) = utpekat top nästa byggsteg — men börja med de två förutsättningarna: bekräfta insatsTyp på inflöde-raden + migration som persisterar frendsModul på case-raden
- Presentera beslutspaketet för användaren: FAM-2/FAM-3, AB-04/AB-06, KOMPL-07, per-kommun funktionsadresser, valdaDokument-konsumtion, anteckningars case-scoping, bevaknings-board-ägarskap — inget av detta får byggas ensidigt
- Bygg motor-konsumtionen av payload.valdaDokument (flaggad rest från session 2d) — liten, hög förtroende-effekt
- Deploya + GUI-verifiera mail-overlayn (punkt 4, initComposerDeepLink.js — egen byggkedja)
- Persistens-spår mot itsl: baka in apps/sdkmc-additions + libresign i imagen (MANIFEST.md) — annars raderar nästa deploy allt
- Bygg ArendeReconciliationJob + backup-rutin (BESLUT-19, obyggd) och retention-paus-hooken (GAP-031) — båda interna, inga externa beroenden
- Verifiera CI på remote + lägg till jest/deny-tester + PII-grind; kör kravtäcknings-mappningen mot HUBS-KRAVSTALLNING-TOTAL (268 krav) per HANDOVER §5
- Starta de externa ledtiderna nu: Inera-avtal (BESLUT-08), Frends/Treserva-testmiljö (seam D), partsregister-anslutning (seam F), PuB-matris-innehåll med DSO (B-PUB-1)
- Städa dokument-drift: uppdatera STATUS-OCH-ROADMAP/HANDOVER §4 så B-MOD-1/B-LIC-1-statusen stämmer med ratificeringen och seam A/B/H markeras delvis stängda