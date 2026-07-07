# Hubs — Komplett projektanalys & handover (2026-07-02)

> Sammanställd ur en 8-agents kodförankrad genomgång (frontend, ärendemotor, sdkmc-tillägg,
> demodata-inventering, statusdokument, kravtäckning, drift/infra, faktisk testkörning) plus
> korsläsning av HANDOVER-FORTSATTNING.md (2026-06-20), STATUS-OCH-ROADMAP.md (2026-06-17),
> GAP-ANALYSIS.md (2026-06-14) och HUBS-BESLUTSLOGG (ratificerad 2026-06-16).
> Testsviterna är OMKÖRDA 2026-07-02 — inte bara citerade.

---

## 1. Sammanfattning

Hubs är idag **en skarp, defensivt byggd vertikal för socialsekreterar-flödet ovanpå ett
medvetet stubbat externt integrationslager**, deployad och verifierad på dev15 (NC 31.0.8.1):

- **Motorn (`hubs_arende` 0.7.5)** kör hela livscykeln — saga R0–R10 med kompensering,
  fail-closed säkerhetsskyddsgrind, pliktgrind, verifierad-commit-mönstret (GAP-007),
  GDPR-gallring — och alla 8 ärendetyper går end-to-end på motornivå (smoke grön inkl.
  [8b] hela resan till avslut). Alla 11 materiella säkerhetsfynd är åtgärdade med regressionstester.
- **Dashboarden (`hubs_start` 1.2.15)** är live-wirad mot motorn och sdkmc; orosanmälan är
  **GUI-E2E-verifierad med riktig data** (session 2b: riktigt meddelande → ärende → komplett
  ärenderum → pliktgrind → commit med dnr → retention → utredning).
- **Grindarna är gröna, verifierat idag:** jest 88/88, phpunit 72/72 (237 assertions),
  webpack-bygget färskt och konsistent med 1.2.15.
- **Men:** samtliga tre integrationsportar (Frends/Treserva, Inera-signering, e-diarium) är
  **enbart stubbar — inga live-adaptrar finns i koden**, och hela sdkmc-tilläggsleveransen på
  dev15 är **efemär** (raderas vid container-restart; har hänt en gång).
- Bredden (11 av 12 kommunroller, SoR-konnektorer, Δ-datafälten, PuB-matrisen) är i huvudsak
  orörd — medvetet: strategin har varit smal-men-djup vertikal.

**De tre största hoten just nu:** (1) persistensen på dev15, (2) det tysta stub-fallback-beteendet
i "live"-läge, (3) fyra o-gatade demodata-ytor som visas även i skarpt läge.

---

## 2. Vad projektet är (arkitektur)

Tre leveranser i ett monorepo (`Nextcloud-vanilla`):

| Del | Version | Var den bor | Roll |
|---|---|---|---|
| `hubs_start` | 1.2.15 | `custom_apps/hubs_start` (dev15) | Fristående dashboard-app, Vue 2.7 + @nextcloud/vue v8, 64 Vue-komponenter (25 generella + 37 socialsekreterare + skal). Blir förstavy via `defaultapp`. |
| `hubs_arende` | 0.7.5 | `custom_apps/hubs_arende` (dev15) | Ärendemotorn: egen DB (5 tabeller), saga-orkestrerad createCase, typregister (8 typer), livscykel, commit, gallring. Enda skrivaren. |
| sdkmc backend-additions | — | **`apps/sdkmc`** (dev15) ⚠ efemärt | ~6 900 rader additiv kod: 9 tjänster, 9 OCS-controllers (14 routes), 3 widgets (obyggda i register), demo-data. Källa-till-sanning: `hubs_start/backend-additions/`. |

Boundary (ratificerad i MANIFEST.md): **meddelande/kontakt/möte = sdkmc; ärende = hubs_arende.**
Viktig divergens mot kravdokumentet: motorn byggdes som fristående app (`/api/v1`), INTE i
sdkmc/M0 (`/api/v2`) som HUBS-KRAVSTALLNING-TOTAL föreskrev — separationsmålet uppfyllt på annat
sätt, men kravkartans kodreferenser är inaktuella.

Övrigt i repot: `hubs-code/` (referens-checkouts av forkarna; OBS mail-overlay-ändringen bor här),
`nextcloud/` (vanilla NC 32.0.1-källa, driver repostorleken 253 MiB), `gov-portal/` +
`docker-compose.yml` (död januari-prototyp), 6 redundanta zip-ar i roten, två tomma trasiga
kataloger `hubs_arende;C` / `hubs_start;C`.

---

## 3. Handover — nuläget i siffror

- **dev15:** NC 31.0.8.1, containrar hubs-php/hubs-postgres/hubs-apache. Reset till känt
  testläge: `scripts/dev15-reset.sh` (2 otaggade riktiga orosanmälningar i "Att ta emot").
- **Grindar (omkörda 2026-07-02):** jest 11/11 sviter, 88/88 tester (32 s); phpunit 72/72,
  237 assertions (composer:2-docker). Matchar dokumentationens påståenden exakt.
- **Bygge:** `hubs_start/js/hubs_start-main.js` 2.18 MB, byggd 2026-06-21 14:54, en minut före
  commit 9e4f0645 (1.2.15); inga källfiler nyare än bygget. OBS `js/` är gitignorerad —
  artefakten finns bara lokalt.
- **Git:** allt väsentligt är trackat (HANDOVER-uppgiften "hubs_arende/hubs_start untracked" är
  inaktuell). Untracked: `analysis-output/VARDE-OPERATIVA-VERKSAMHETSLAGRET.md` + `analysis-output/rapport/`.
- **Deploy-recept:** tar-pipe över ssh enligt HANDOVER-FORTSATTNING §3. **Kör ALDRIG
  `docker restart hubs-php`** (NC-entrypointens apps-omsynk raderar apps/sdkmc-tilläggen).
- **Två kritiska drift-lärdomar** (session 3) står i HANDOVER-FORTSATTNING §4 — läs dem först.

### Dokumentkarta (vad som är aktuellt)

| Dokument | Datum | Status |
|---|---|---|
| `hubs_start/docs/HANDOVER-FORTSATTNING.md` | 2026-06-20 | **Auktoritativ** operativ handover (sessionerna 1–3) |
| `hubs_start/docs/DEMO-STUBS.md` | — | **Auktoritativt** SEAM-register (`SEAM[...]`-markörer i kod) |
| `backend-additions/MANIFEST.md` | 2026-06-17 | Deploy-karta — **släpar**: fas 2d-filerna (NoteToSelf, ArendeEnrichment, InflodeDemoData, demo-favoriter) + 3 routes saknas |
| `docs/STATUS-OCH-ROADMAP.md` | 2026-06-17 | Delvis inaktuell: Fas 0 + seam A/B är stängda sedan dess (rummen skapas riktigt, GUI-verifierat) |
| `GAP-ANALYSIS.md` (55 gap) | 2026-06-14 | Register gäller, men skrivet mot gamla premisser; se §6 nedan för dagsstatus |
| `hubs_arende/docs/FAS-F-DESIGN.md` | — | Statusrubriken "ej byggd" är fel — F1–F3 är byggda |
| `tests/README.md`, `Integration/README.md`, `provision/manifest.yaml`, `INSTALL.md` | — | Dokumentdrift (fel testantal, fel config-nycklar, fel version, fel NC-version) |

---

## 4. Vad fungerar — per evidensnivå

### A. GUI-E2E-verifierat med riktig data (starkaste evidens)
- **Hela orosanmälan-create-flödet** (session 2b, inloggad användare, demo AV): riktig
  Horde-orosanmälan → "Ta emot" → case med komplett ärenderum (groupfolder + Talk-rum +
  Deck-kort + kalender-.ics + mottagningskrets-medlemmar) → pliktgrind låser steppern →
  kvittera skyddsbedömning → CommitGrind → **registrerad + dnr + retention (gallras-datum)** →
  steg→utredning. DB-verifierat i `oc_hubs_arende_case/_pekare/_member`.
- **Inflödesfeeden mot riktig data** (session 3): dedup 4→2 (thread_root_id), "Ta emot" taggar
  källmeddelandet (IDOR-säkert i användarsession), behandlade lämnar feeden (join mot sdkmc:s
  EGNA taggtabeller).
- Ärenderums-ACL Fas E: per-ärende-NC-grupp + handoff-avsmalning (tilldelat ⇒ krets revokeras).

### B. Motor-nivå (smoke + enhetstester, mot stubbar)
- `occ hubs_arende:smoke` grön: idempotens, säkerhetsavvisning, commit-idempotens (samma dnr),
  PII-avvisning, pliktgrind, gallring, **[8b] hela resan utredning→beslut→uppföljning→avslutat**,
  per-typ-loop **8/8 ärendetyper** inkl. kat6 föds-registrerad (diarieförd) och kat8 post-hook.
- 72 phpunit-tester täcker alla säkerhetsinvarianter (H1-authz som 404, M1/M4 fail-closed,
  GAP-007-mönstret, hook-infra, modul-fail-closed, gallringens dubbelvakt).

### C. Route/kod-verifierat (svagare — bevisar wiring, inte data)
- 14 sdkmc-OCS-routes svarar 401 oautentiserat (route+DI+auth OK); re-verifierade efter wipe-incidenten.
- INTEGRATION_MODE-nyckelglappet ur STATUS-auditen är **fixat** (en kanonisk nyckel, porten DI-konsumeras).
- CI-workflow-fil finns (`hubs_arende/.github/workflows/ci.yml`) — körning mot remote obevisad.

### D. Byggt men OVERIFIERAT (BankID-blockern: autonom GUI-inloggning är förbjuden)
- Session 2d/3-ytorna aldrig GUI-klickade: #1 feed-dedup i UI, #5 dokument-urval,
  #6 signeringssektionen i CommitGrind, #10/#18 rum-öppning, #12 anteckningar, demo-länken,
  hela resan till avslut i GUI.
- Mötesbokning end-to-end (submit kräver personnummer = gated; medborgar-inbjudan går dessutom
  via credential-lös loopback-OCS → sannolikt tyst 401).

### E. Byggt men EJ deployat / inaktivt
- **Punkt 4 mail-overlay:** `hubs-code/mail/mail-main/overlay/src/itsl/utils/initComposerDeepLink.js`
  — wirad i initITSL, egen byggkedja, EJ ombyggd/deployad.
- **3 dashboard-widgets** (AttHantera/Kvittenser/DagensMoten) — färdig kod, aldrig registrerade
  i sdkmc:s Application.php (medvetet utelämnat i MANIFEST).
- `payload.valdaDokument` — frontend skickar handläggarens dokumenturval vid commit,
  **motorn läser det aldrig** (falsk trygghet i UI).

---

## 5. Vad är demodata / stub — komplett skiktad inventering

### Skikt 1 — Korrekt gatad demo (AV på dev15)
| Artefakt | Gate | Kommentar |
|---|---|---|
| SPA-fixtures alla personas (`src/services/demoData.js`, `demo/socialsekreterare.js`, `demo/treserva.js`, `demo/favoriter.js`) | `hubs_start/demo_mode` ('1'/'0'/AUTO utan sdkmc) + klient-override `?demo=1/0` (sessionStorage) | api.js kortsluter 27+ funktioner; mutationer = optimistiska no-ops; djuplänkar inerta |
| Syntetiskt inflöde, 14 rader (`backend-additions/demo-data/InflodeDemoData.php`) | sdkmc-config `hubs_start_inflode_demo`, **default '0'** | Syntetisk men realistisk PII — får aldrig slås på i skarp miljö |
| Demo-ärenden i motorn (`DemoSeedService`, 10 case) | manuell: `occ hubs_arende:seed-demo [--purge]` | Kör genom RIKTIGA motorn; prefix `demo-` |
| 11 syntetiska favorit-vCards (`demo-data/favoriter/`) | seedas bara manuellt (occ/reseed) | märkta SYNTHETIC DEMO i PRODID+NOTE |

### Skikt 2 — ⚠ O-GATAD demodata som kan visas ÄVEN i skarpt läge (åtgärdas snarast)
*(adversariellt faktakollade mot kod 2026-07-02)*
1. **Hårdkodat "Anna"** *(live-synligt på dev15 IDAG)* — `MinDagHeader.vue:97–99` hälsar alla
   användare "God morgon, Anna" i live-defaultvyn, och `TilldelningBand.vue:67,106–109` avgör
   "är ägaren jag?" via namnjämförelse mot 'Anna' på live-ärendekorten (egna ärenden visar
   namn i st.f. "mig"; en verklig Anna får "mig" på fel kort).
2. **GallringsGrind** *(live-aktiv)* — inbäddad 4-punkts handlingstypslista är den effektiva
   listan (ingen kod skickar någonsin en riktig DHP-lista; `EjKoppladSektion` defaultar `[]`),
   och gallra-verbet går mot riktig backend → skarpa gallringsbeslut fattas mot demolistan.
3. **30 persona-widget-descriptors** (`demoWidgets.js` + `demo/*.js`) — `WidgetRenderer.vue:96`
   kollar aldrig demoMode, så fixtures ("Utskrivningsklar patient dygn 4 · ~11 200 kr") renderas
   som riktig data. *Nyans:* nås i live bara om admin sätter `default_persona` till en
   icke-socialsekreterar-persona (persona-växlaren är demo-gated); provenance-footern flaggar
   "Föreslagen integration" men själva raddatan är omärkt.
4. **NyttaWidget** — `PLACEHOLDER_VOLUME` + 318 fax/207 brev hårdkodat; `state.nytta` produceras
   aldrig av någon kod (repo-wide verifierat). Synlig disclaimer finns; ligger i
   registrator/hr/överförmyndare/förvaltar-layouterna, inte i socialsekreterarens live-vy.

Dessutom: **admin-reseed-knappen** (`AdminController.php:74`, admin-only) sätter
`hubs_start_inflode_demo='1'` — ett admin-klick på en skarp instans byter riktiga inflödet
mot syntetiska rader.

### Skikt 3 — Port-stubbar by-design (inga live-adaptrar existerar)
| Port | Stub | Live-mål | Läge |
|---|---|---|---|
| FacksystemCommitPort | FacksystemCommitStub (dnr-sekvens, synkron "verifierad" callback) | Frends iPaaS → Treserva/Lifecare/Viva (GAP-019) | `integration_mode_facksystem`, default stub |
| SigneringPort | SigneringStub (syntetisk PAdES) — **noll konsumenter i motorkoden** | Inera Underskriftstjänst (GAP-034) | default stub |
| EdiariumPort | EdiariumStub (SN-dnr, FGS) — konsumeras av kat6/kat8-hooks | e-diarium/e-arkiv FGS | default stub |

**⚠ Tyst fallback (kodverifierat):** `Application::resolvePort` (`Application.php:125`) mappar
ENDAST 'stub' — värdet `live` faller tillbaka till stubben **utan exception och utan ens en
varningslogg** (enbart en info-logg-etikett `mode=live, port=FacksystemCommitStub` vid commit
avslöjar diskrepansen). En felkonfigurerad prod skulle minta syntetiska dnr som ser verifierade
ut. Ingen HTTP-route för async-callbacken finns; stub-state är in-memory per request.
LibreSign 11.6.0 på dev15 = demo-signering (självsignerad CA), efemär; **Inera är beslutad backend**.

### Skikt 4 — Ärliga nollor / kända TODO-förenklingar i skarp kod
`resolveLoa()`=LOA3 alltid; receipt `updated_at`=null; team-räknare olästa/omnämnanden=0,
närvaro='unknown'; favoriter alltid `stale:true` (DIGG-resolvern obyggd); dashboardSummary-puls
hårdkodade nollor; `registerPartHook()`=null (SSN-matchning avstängd fail-closed);
`resolveInflodeRows()`=[] i motorn (klassningslagret kör aldrig på riktig data — riktiga feeden
är sdkmc:s, som saknar kat 1–8-klassning); CommitGrinds 3-stegs Frends-progress = setTimeout-animation.

---

## 6. Gap-/beslutsläget (konsoliderat)

**Ratificeringen 2026-06-16:** 22 beslut LÅSTA (inkl. datalager = egen DB i hubs_arende,
Tables underkänd; B-LIC-1/ExApp juridiskt godtagen; B-MOD-1 UTGÅR — separation via standalone-app).
Externt öppna: BESLUT-08 (Inera-avtal), BESLUT-09 (AI-vägledning), B-PUB-1-**innehållet**.

**GAP-ANALYSIS-blockerarna idag:** GAP-007 stängd som mönster (live-stängning = GAP-019);
GAP-001 delvis (pliktgrind byggd; kanonisk dokumentationsregel = öppet beslut); GAP-010/057
stängda; **GAP-019, GAP-031 (retention-paus vid utlämnandebegäran), GAP-034 (Inera), GAP-052
(AI röd zon) ÖPPNA**. GAP-056: ArendeReconciliationJob beslutad (BESLUT-19) men **obyggd**.

**Av de 34 orosanmälan-gapen:** ~22 stängda, ~10 kvar (tyngst: gap17 MeetingWizard skickar inte
dnr; gap7 skyddsbedömnings-materialisering; gap26 persona-per-grupp; gap3 KategoriBadge).
**19-fyndslistan:** allt byggt+deployat; kvar = GUI-klick-verifiering + valdaDokument-konsumtion
+ mail-overlay-deploy.

**Kravtäckning K-1…K-8 (268 krav), grovt:** K-3 motor ~80 %, K-6 UI ~90 % (KategoriBadge enda
uttryckligt saknade atomen), K-4 klassning ~45 % (byggd men owirad mot riktig feed), K-2 ~50 %,
K-1 ~40 %, K-7 juridik/signering ~35 %, **K-5 roller/SoR ~15 % (största luckan)** — Δ-breddfälten
(Δ1–Δ6, Δ10–Δ17) saknas i schemat, 0 riktiga konnektorer, 11 av 12 roller utan backend.

---

## 7. Risker (rangordnade)

1. **Persistens (akut):** apps/sdkmc-tillägg + libresign + apk-paket raderas vid container-recreate/
   `itsl deploy`/`docker restart hubs-php` (inträffat en gång). Ingen komplett deploybar
   `routes.php` finns i repot (måste handbyggas ur 3 spridda snippets; `.hubsbak` på dev15 saknar
   fas 2d-rutterna). Inget upstreamat, inget i image, MANIFEST släpar.
2. **Tyst stub-fallback:** 'live'-läge utan adapter → stub, bara loggvarning. Måste bli fail-closed
   innan någon litar på ett "live"-läge.
3. **Juridiska säljblockerare:** PuB-/laglig-grund-matrisen ("osäljbart utan den"), GAP-031,
   Inera-AES för myndighetsbeslut, Frends-konnektorn (grundorsak bakom ~10 följdgap), AI röd zon.
4. **O-gatad demodata live** (skikt 2 ovan; 'Anna' + gallringslistan är live-synliga idag) —
   riskerar kundförtroendet i demo/pilot och gallringsbeslut mot fel underlag.
5. **GUI-verifiering strukturellt blockerad** (BankID) — flera deployade ytor aldrig klickade.
6. **Register-motståndskraft:** ReconciliationJob + arkivkritisk backup obyggda; gallring river
   inte externa rum; ingen unik-constraint på vissa pekare.
7. **Versioner/EOL:** spreed-itsl hårdpinnad NC min=max=31 blockerar NC 32-lyft; dev15:s NC 31 har
   tidigast EOL; referens-checkout sdkmc 2.2.21 vs dev15 2.2.25.
8. **Dokumentdrift** (se §3-tabellen) — nästa person kan agera på fel premiss.
9. Oförklarad anomali (session 2c): GUI-skapat case föddes beslut+registrerad — "bevaka", aldrig rotorsakad.

---

## 8. Vägen framåt — prioriterad plan

### Spår 0 — Robusthet & hygien (internt, kan börja NU)
1. **Persistensspåret (viktigast):** committa en komplett deploybar `routes.php`-kopia i
   backend-additions; uppdatera MANIFEST med fas 2d; upstreama additionsfilerna till
   sdkmc-forken per märkningskonventionen; registrera hubs_start+hubs_arende i
   `hubs-apps/setup-apps.list` + GitLab-CI-artefakt; baka libresign+openjdk/poppler i hubs-php-imagen;
   pinna i versions.yaml.
2. **Fail-closed 'live'-läge** i resolvePort/FacksystemCommitService (kasta i st.f. stub-fallback).
3. **Skikt 2-demodatan:** gata widget-descriptors på demoMode, ersätt 'Anna' med inloggad
   användare, koppla/göm NyttaWidget, wira DHP-listan till GallringsGrind, ändra
   admin-reseed-baseline till inflode_demo='0'.
4. Repo-städ: radera `;C`-katalogerna, `git rm` zip-arna, committa VARDE-dokumentet + rapport,
   arkivera gov-portal/docker-compose, synka hubs-code/sdkmc till 2.2.25.
5. Dokumentsynk: MANIFEST, Integration/README-nycklarna, tests/README, FAS-F-status,
   INSTALL-NC-versionen, package.json-versionen.

### Spår 1 — Verifiera det byggda (kräver dig + BankID)
6. GUI-klicka hela resan (ta emot→…→avsluta) + #1/#5/#6/#10/#12/#18 + demo-länken mot 1.2.15.
7. Bygg + deploya mail-overlayn (punkt 4) och verifiera composer-deep-link + sändkoppling.
8. Verifiera CI på GitHub-remote; lägg till jest + deny-vägstester.

### Spår 2 — Små byggen med hög effekt (internt)
9. Motor-konsumtion av `payload.valdaDokument` (liten, stänger falsk trygghet).
10. gap17: MeetingWizard skickar dnr (backend redo).
11. **ArendeReconciliationJob + backup-rutin** (BESLUT-19 — låst men obyggd).
12. **GAP-031 retention-paus-hook** vid utlämnandebegäran (skarp-drift-blocker, internt byggbar).
13. Wira sdkmc-feeden → hubs_arende klass/match (M3-ordningen finns) + KategoriBadge.
14. PUNCHLIST-resterna i sdkmc-additions (receipt updated_at, assignment-label, PENDING-vokabulär,
    SummaryController-dedup, loopback-credentials för deltagar-inbjudan).

### Spår 3 — Beslut som kräver DIG (byggs ej ensidigt)
15. **AB-01** kat2-insatsrouter (utpekat top nästa byggsteg; kräver insatsTyp-bekräftelse + migration).
16. FAM-2 partsmodell / FAM-3 partsåtskild ACL; AB-04/AB-06; KOMPL-07; per-kommun funktionsadresser;
    anteckningars case-scoping; dokumentpolicy per ärendetyp; B-SEC-1-resten (regimbyte).
17. Städa beslutslogg-diskrepansen (HANDOVER listar B-MOD-1/B-LIC-1 som öppna trots ratificering).

### Spår 4 — Externa ledtider (STARTA NU, längst tid)
18. **Inera-avtal + Underskriftstjänst-anslutning** (BESLUT-08; GAP-034/035/037).
19. **Frends iPaaS-miljö + Treserva-testinstans** (seam D/GAP-019 — grundorsaken; först då kan
    GAP-007 stängas live och gallringsklockan litas på).
20. **PuB-/laglig-grund-/DPIA-matrisens innehåll** med nämnd+DSO (B-PUB-1 — "osäljbart utan").
21. Partsregister-anslutning (seam F) + IMY/SKR-vägledning för AI (BESLUT-09).

### Spår 5 — Bredd (efter vertikalen är bevisad)
22. Δ-breddfälten som migrations-serie (Δ1, Δ2, Δ5 HSL-muren, Δ6) när första icke-soc-roll prioriteras.
23. E-diarium-konnektorn som konnektor #2 (avlastar 9 roller; EdiariumPort-kontraktet finns).
24. Kravkarte-revision (sdkmc/M0→hubs_arende-mappingnot) + GUI-djuptest av rattsligt_tvang,
    familjeratt och komplettering (attach-vägen saknas helt).
25. NC-uppgraderingsfönster: spreed-itsl-rebase bort från min=max=31; bekräfta EOL-datum externt.

---

## 9. Agentrapporternas råmaterial

De åtta detaljrapporterna (frontend, demodata, engine, sdkmc, docs_status, krav, ops, tests) med
fil:rad-evidens ligger i `analysis-output/agentrapporter-2026-07-02/`.
