<!--
SPDX-FileCopyrightText: ITSL <info@itsl.se>
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Hubs-lösningen — STATUS-ÖVERBLICK + PRIORITERAD ROADMAP

> Sammanställd 2026-06-17 ur en kodförankrad audit (fil:rad-evidens) av fem dimensioner:
> ärende-motorn (`hubs_arende`), externa integrationer, sdkmc-domänen, dashboard/frontend
> (`hubs_start`) samt säkerhet/GDPR/drift/paketering. Kalibrerad och ärlig — varje påstående
> nedan är kontrollerat mot faktisk källkod, inte mot dokumentation. Ingen överförsäljning.

---

## 1. Helhetsbild

Hubs är idag **en körande demo med en skarp, defensivt byggd motor-kärna ovanpå ett medvetet
stubbat yttre integrationslager.** Tre appar är deployade och verifierade på dev15 (NC 31.0.8):
ärende-motorn `hubs_arende` (egen kodbas/DB, SAGA R0–R10 kör end-to-end, smoke grön), sdkmc
backend-additions (9 OCS-endpoints, varav 6 skarpa läsningar) och dashboarden `hubs_start`
(komplett Vue 2.7-SPA, alla personas/zoner renderar). Det som skiljer dagens läge från full
produktion är att **nästan all DATA är demo-gated eller tom**, och att **hela det externa
integrationslagret — saga-rummen R3–R9, facksystem/Inera/diarium-live och den event-drivna
inflöde-feeden — är `isAvailable()`+`TODO[auth]`-stubbar eller seams som inte lämnar processen.**
Kärnans logik (fail-closed säkerhetsgrind, objektnivå-authz, verifierad-callback-bunden retention,
GDPR-gallring, symmetrisk saga-kompensering) är skarp och korrekt; men den har **ännu aldrig
exekverat mot riktig indata eller skapat ett enda riktigt rum/kort/facksystem-objekt.** Inget av
detta läcker PII på en live-väg idag, eftersom inflöde-feeden fortfarande är tom.

---

## 2. KLART — skarpt, körande, verifierat på dev15

**Motor-kärnan (`hubs_arende`)**
- **R0 säkerhetsskydds-grind, fail-closed** — `evaluate()` körs FÖRE varje sidoeffekt; avvisning ger inget register, ingen tagg, inget rum (`ArendeService.php:138-159`). Smoke[7]: personnummer avvisat.
- **R1 mint hubsCaseId (UUIDv4, RFC4122)** + **R2 INSERT registerrad** med `hubs_case_id`/`commit_destination` NOT NULL som hård DB-constraint, allowlist + UNIQUE-krock-hantering (TOCTOU) (`ArendeService.php:215-254`).
- **R8 frist + R10 commit-punkt** — ankrad i inkomDatum, riktig DB-update (`ArendeService.php:421-470`).
- **never-SoR-invariant** — schema-enforcad, ej bara applogik (`Version…20260616…php:114-115`; verifierad i `information_schema`).
- **Lifecycle-transitioner** — `ArendeLifecycleService` med tillåtna-övergångar-graf, authz via `show()`, ogiltig→400 (smoke[8]).
- **GDPR-gallring** — `GallringService`/`GallringJob` (TimedJob 1×/dygn, registrerad i info.xml), dubbel säkerhetsvakt (query + oberoende re-check), pekare före register-rad (smoke[9]: rad purgad).
- **Facksystem-commit-MÖNSTRET (GAP-007)** — retention startar ENBART på verifierad callback; kontrakt + stub + ArendeService-flip är skarpa end-to-end (`FacksystemCommitPort.php:44-91`, `ArendeService.php:1001-1010`). Mönstret är klart; den underliggande porten är stub (se §4).

**Säkerhet (de två live-RÖDA fynden ÄR remedierade i kod)**
- **H1 objektnivå-authz** — `assertEnhetAtkomst…` fail-closed (groupManager null/tom enhet/ingen match → neka; deny=404 ej 403), gatar create/show/commit/tilldela (`ArendeService.php:1079-1148`).
- **H2/H3 commit-route** — `ArendeController::commit()` delegerar nu till `ArendeService::commit()` (show→H1-gate→idempotens på `provenanceState='registrerad'`→verifierad-callback-flip) (`ArendeService.php:972-1042`). Smoke[6]: andra commit = samma dnr, ingen dubbel-registrering.
- **Reset-/provisioneringsrutin** — `bootstrap.sh` idempotent, `--dry-run`, prereq-gates, invariant-koll (validerad på dev15).

**sdkmc-domänen (skarpa, OCP/DB-backade läsningar, deployade)**
- **Recipients (search+classify)** och **Summary (summary+receipts)** — skarp kod, korrekt datakälla-mönster, svarar 401 oautentiserat. Tomma på dev15 endast pga saknad provisionering (data-fråga, ej kod-skuld).

**Dashboard (`hubs_start`)**
- **Hela UI:t renderar och är interaktivt** — alla zoner/komponenter/6 personas, persona-/läge-växling.
- **PageController demo-gating** — AUTO (config eller sdkmc-närvaro), ej hårdkodad; korrekt och körande.
- **5 ärende-funktioner är LIVE-wirade mot motorn** — `fetchArendeSummary/fetchArende/skapaArende/tilldela/commitToTreserva` pekar på `HUBS_ARENDE_OCS` med demo-fallback överst (`api.js:274-372`). Slå av demo → dessa fem träffar den verifierat körande motorn.

---

## 3. PARTIELLT / DEMO — finns men förenklat, demo-gated eller tomt i drift

- **SAGA-kompensering** — symmetrisk `array_reverse` best-effort; R2-undo riktig, men R3–R9-undo anropar samma 401-klienter (verifieras först vid wiring).
- **`tilldela()` 3-lagers ACL** + **`commit()` provenans/retention-flip** — SKARP H1+register-flip mot riktig DB, men ACL-applicering/facksystem under är stub (GAP-057 fördelnings-ACL-race kvarstår).
- **Match + klassning** (`ArendeMatchService`, `InnehallsKlassService`) — riktiga register-uppslag + deterministisk 4-lagers-kaskad + fail-mot-människa, MEN körs bara över **tom feed** = noll live-exekvering; `registerPartHook` alltid `null`.
- **sdkmc Team/Favoriter/Meetings** — skarp kod, men medlemskap-räknare hårdkodade 0 (TODO), favorit-pekare alltid `stale:true` (DIGG-resolver ej byggd), tomt på dev15 utan provisionering.
- **Inflöde-feed** — kör i **DEMO-läge** (14 syntetiska PII-bärande rader, `InflodeDemoData`); den skarpa pull-vägen mot `mail_*` är skarp KOD men tom (inga brevlådor) OCH pull- ej event-baserad.
- **Frontend ärende-kort** — designprincipen "aldrig innehåll, bara pekare" är INTE upprätthållen i demo: fixturen lägger dokument/möten/AI-utkast/beslut inline i klienten. Lazy on-demand-innehållshämtning bakom objektnivå-ACL finns ej skarpt.
- **De tre banden / fördelning / mutationer** — fungerar fullt i demo; `fetchInflodeSummary`→sdkmc-route som ej finns skarpt; `fetchFordelningSummary`/`treserva/receipts` pekar på hubs_arende men är EJ bland de smoke-verifierade routerna. Alla success-toasts är optimistiska.
- **CommitGrind 3-stegs Frends-progress** — ren `setTimeout`-animation, inte riktig callback (motorns commit-väg är dock skarp).
- **Tester** — 11 testfiler finns, men **ingen CI-pipeline** (`.github/` saknas helt); de 5 nya enhetstesterna har enligt projektets egen ärliga not ALDRIG körts i denna miljö. Smoke är den enda faktiskt körda regressionen (happy-path + CLI-tillåt-authz, ej deny-vägen).
- **Monitoring** — bara on-demand-aggregat + PSR-3-logg; ingen metrics/alerting/healthcheck/audit-tabell.
- **INTEGRATION_MODE** — DEFEKT nyckelglapp: DI läser `integration_mode_facksystem`, `FacksystemCommitService` läser `integration.facksystem` → två olika nycklar styr samma läge. Dessutom: `FacksystemCommitService` konsumerar ALDRIG den DI-bundna porten (instansierar egen stub), så DI-bindningen för facksystem är delvis död kod.

---

## 4. MÅSTE UTVECKLAS — stubbar / seams (medvetet ej-skarpt)

| # | Komponent | Vad som krävs för skarpt | Insats | Beroenden (externt/system) |
|---|---|---|---|---|
| A | **Integrations-AUTH-seamen (R3–R9)** — `ocsRequest()` saknar `Authorization`, accountId=0, 401 sväljs | Wira service-account/app-password/signerat internt anrop i ocsRequest för alla 5 klienter | **S–M** | NC service-account-policy; sdkmc internal-call-credential |
| B | **Saga-rummen R3–R9** (sdkmc-tagg / Groupfolder+ACL / Deck / Spreed+ACL-krets / Calendar) — skapar INGA riktiga rum/kort/taggar/kalenderobjekt; Calendar har tom CalDAV-kropp | Klistra in forward+comp per wiring-guide; R6 riktig ACL-krets (ej hårdkodat tom); R7 `ICalendarManager` | **M** | Seam A (auth) FÖRST; groupfolders/deck/spreed/calendar aktiverade |
| C | **Skarp event-driven inflöde-feed** — `MessageReceivedEvent` finns INTE (0 träffar i kod, ren `[BYGGS]`-doc) | Bygg Event + Listener i sdkmc → anropar klass/match/grind → bär resultat som taggar; fixa M3/M4-grind FÖRE feeden tänds | **L** | sdkmc-feed; M3/M4-grind-fix; brevlåde-provisionering |
| D | **Live FacksystemCommitPort** (Treserva/Lifecare/Viva via Frends) — ingen `Integration/Live/`-katalog | Skriv `FrendsFacksystemAdapter`; bind verifierad callback till RIKTIGT Frends-event (HMAC/cert); per-modul fältmappning; fixa nyckelglapp + injicera som `$livePort` | **L** | Frends iPaaS-miljö, facksystem-testinstans, callback-verifiering, per-modul-scheman |
| E | **Brevlåde- + NC-användar-provisionering för Bergsby** | Kör upstream provisioneringsmotorn för funktionsadresser; provisionera staff/grupper (SCIM/IdP) | **M** | Upstream sdkmc-services; IMAP-konton; IdP/SCIM |
| F | **Part-/personregister** (`registerPartHook` = hårdkodad `return null`) | Implementera `findKandidatByPart` mot Navet/SPAR/internt register; SSN-matchning aktiveras | **M** | Person-/partsregister-API, LOA3/org-cert, sekretessgranskning (TF 2:18) |
| G | **Inera-signering** (Underskriftstjänst, PAdES-B-LTA) — port+stub bundna, NOLL konsument | Injicera SigneringPort i en signeringstjänst + koppla i beslutsflödet; skriv `IneraSigneringAdapter` | **L** | Inera-avtal/anslutning, e-legitimation LOA3, PAdES-LTV-validering |
| H | **E-diarium/e-arkiv** (FGS) — port+stub bundna, NOLL konsument; `preSagaHook='diariefor_direkt'` dispatchas ALDRIG | Dispatcha preSagaHook i createCase (kat 6); wira `arkivera()`; skriv FGS-adapter | **L** | E-arkiv/e-diarium-system (FGS), FGS-XML-schema |
| I | **ExApp-paketering** — `app_api 3.2.0` kraschar på ITSL NC31, docker.sock ej monterad → kör in-process | app_api-version som funkar på NC31; docker.sock-mount + grupp 'docker'; ExApp-manifest | **L** | ITSL-infra (compose-ändring, itsl-deploy äger den) |
| J | **CI-pipeline** — ingen `.github/`, tester aldrig körda i miljön | GitHub Actions: composer install + phpunit mot `nextcloud/ocp`; lägg deny-vägen i sviten | **S** | — |
| K | **AI/LLM-assist** — ingen kod, medvetet uppskjuten (fas 10) | Lokal/avstängbar modell, människo-i-loopen-gate, DPIA | **L** | Fas 10-beslut; DPIA; modellval (lokal pga OSL) |

---

## 5. PRIORITERAD ROADMAP — den klokaste vägen

**Grundinsikt:** Det enskilt mest foundationella är **integrations-AUTH-seamen (seam A)**. Den är
billig (S–M) men **unblockar samtliga rum-skapande steg** — utan en server-till-server-credential
returnerar varje saga-klient deterministiska placeholders och skapar ingenting. Allt "skarpt rum"
(R3–R9, ärende-chatt, möten, ärenderum) hänger på den. Den andra foundationella biten är **den
event-drivna inflöde-feeden (seam C)** — utan den körs hela det redan-byggda klass/match/grind-lagret
över en tom feed och ger noll verksamhetsvärde. Allt annat (Treserva-live, Inera, ExApp, AI) hänger
på **externa avtal/system** och bör därför sekvenseras EFTER de två interna foundational-bitarna.

### BÖRJA HÄR (Fas 0 — kan göras i veckan, noll externa beroenden)
1. **Fixa INTEGRATION_MODE-nyckelglappet + FacksystemCommitService-port-wiringen** (S). Två olika config-nycklar styr samma läge och servicen ignorerar den DI-bundna porten — detta MÅSTE rättas innan något live-läge går att lita på. Ren teknisk skuld, inga beroenden.
2. **Sätt upp CI-pipelinen (seam J, S)** och kör de 11 testfilerna + lägg till deny-vägen. Idag är "körs i CI" aspirationellt; en pipeline ger regressionsskydd innan man rör motorn vidare.
> Levererar: en pålitlig grund att bygga vidare på. Litet arbete, stort förtroende-värde.

### Fas 1 — Tänd rummen (foundational, mest demo-/verksamhetsvärde tidigast)
3. **Wira integrations-AUTH-seamen (seam A, S–M).** Service-account/app-password i `ocsRequest()`.
4. **Klistra in + aktivera saga R3–R9 (seam B, M)** — riktiga sdkmc-taggar, Groupfolder-ärenderum+ACL, Deck-kort, Spreed-rum med RIKTIG ACL-krets, Calendar via `ICalendarManager`.
> **Motivering:** Detta är hävstången. När A+B är klara skapar `skapaArende` (redan live-wirad i frontend) ett RIKTIGT ärenderum med kort, chattrum och kalenderpost — och de latenta säkerhetsfynden (M2/L1/L6) härdas i samma PR. Ger den första genuint "skarpa" demon utan ett enda externt avtal.

### Fas 2 — Tänd inflödet (gör motorn levande)
5. **Provisionera Bergsby** (seam E, M) — funktionsbrevlådor + NC-användare/grupper, annars är allt tomt.
6. **Bygg den event-drivna inflöde-feeden (seam C, L)** — `MessageReceivedEvent`+Listener i sdkmc, FÖRST efter M3/M4-grind-fix (annars läcker ogrindad PII).
> **Motivering:** Detta aktiverar det redan-byggda men outnyttjade klass/match/grind-lagret på RIKTIG data. Banden, fördelning och triage får verkligt liv. Måste komma efter Fas 1 (rummen) men före facksystem-live (man vill se rätt ärenden bildas innan man skriver till Treserva). Slå av demo-gaten här.

### Fas 3 — Skriv till verksamhetssystemen (kräver externa avtal — sekvenseras sist av det "skarpa")
7. **Live FacksystemCommitPort mot Treserva via Frends (seam D, L).** GAP-007-mönstret är redan klart; det som saknas är adaptern + ett riktigt Frends callback-event. **Ärlig blocker:** kräver Frends iPaaS-miljö, facksystem-testinstans och callback-verifiering — externt beroende, längst ledtid.
8. **Part-/personregister (seam F, M)** — aktiverar SSN-matchningen (anonymitetsgrinden redan fail-closed). Kräver registeranslutning + sekretessgranskning.
9. **Inera-signering (seam G, L)** + **e-diarium/e-arkiv (seam H, L)** — port+stub klara men saknar konsument OCH live-adapter; kräver Inera-avtal resp. FGS-system. preSagaHook-dispatchen (kat 6) är en liten egen S-insats inom denna.
> **Motivering:** Allt här rör verksamhetskritiska, juridiskt reglerade externa system och hänger på avtal/anslutningar med lång ledtid. Stub-först var ett medvetet och korrekt beslut — kör demo/pilot på stubbarna medan avtalen löper.

### Fas 4 — Kan vänta (infra/forskning, ingen blockerare för demo/pilot)
10. **ExApp-paketering (seam I, L)** — in-process räcker för v1; gör detta när app_api fungerar på NC31 och docker.sock kan monteras (itsl-deploy-ägt infra-beslut).
11. **AI/LLM-assist (seam K, L)** — fas 10 per beslut. Klassning/matchning är 100% deterministisk idag (rätt för fail-mot-människa). Kräver DPIA + lokal modell.

---

### Sammanfattande sekvenslogik
- **A (auth) är foundational** → unblockar B, ärende-chatt, möten, ärenderum.
- **C (event-feed) är foundational** → unblockar live klass/match/grind, banden, triage.
- **D/F/G/H väntar på externa avtal/system** → stub-först är rätt; pilotera på stubbar.
- **I/K kan vänta** → ingen demo-/pilot-blockerare.
- **Fas 0 (nyckelglapp + CI) först** → billig, men måste vara på plats innan live-lägen litas på.
