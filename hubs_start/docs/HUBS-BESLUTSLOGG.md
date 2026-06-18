<!--
SPDX-FileCopyrightText: 2026 ITSL
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# HUBS — Ratificerbar beslutslogg (BESLUT-01…25)

**Datum:** 2026-06-16 · **Syfte:** ratificera ALLA öppna beslut innan/under bygget av `HUBS-KRAVSTALLNING-TOTAL.md` (268 krav) på en riktig itsl-managed Hubs-instans. · **Implementationsplan:** `HUBS-IMPLEMENTATIONSPLAN.md`.

**Statuskoll (verifierad dev15, read-only 2026-06-16):** NC 31.0.8.1, sdkmc 2.2.25, itsl-managed, appstore AV, **app_api SAKNAS**, Docker-socket FINNS, hubs-postgres FINNS, contacts DISABLED, tables ABSENT.

---

## ✅ RATIFICERAT 2026-06-16 (Fredrik) — "ja till alla rekommendationer" med följande REVISIONER

Alla `REK`-beslut antas. Följande revideras/stängs enligt ägarens direktiv:

- **BESLUT-03 UTGÅR (ingen sdkmc-split):** sdkmc lämnas **orörd** i största möjliga mån och behåller allt som rör **skicka/ta emot meddelanden** (kanaler, taggar, korgar, kvittenser, retention). **Allt vi utvecklar nu ligger i en EGEN standalone-app i egen kodbas** (`hubs_arende` — ärende-motorn) som *konsumerar* sdkmc via OCS/events. Ingen M0/M1-refaktor av sdkmc.
- **BESLUT-01 REVIDERAT:** registret bor i **den nya standalone-appens egen DB** (egen QBMapper/Migration, ExApp-rent schema), **inte** i sdkmc-DB och **inte** i Tables. Mål = ExApp-egen-DB.
- **BESLUT-06 REVIDERAT:** **dev15 ÄR utvecklings-/byggmiljön** — ren, återställbar, komplett nuvarande Hubs-version, och **merge-målet**. All utveckling utgår från dev15. Ingen separat bygg-instans. (Ersätts av BESLUT-05-rutinen nedan.)
- **BESLUT-05/04 REVIDERAT:** bygg en **repeterbar provisioneringsrutin "på sidan"** (versionerad, idempotent) som återinstallerar våra tillägg (appar + app_api + `hubs_arende` + dess DB) efter en dev15-reset, med ett **"överlever-reset"-manifest**. Rutinen mergas i slutändan in i den gemensamma deployment-/itsl-rutinen.
- **BESLUT-02 STÄNGT:** **IP-juristen har godtagit ExApp-lösningen** → proprietär M4-som-ExApp är klarlagd (standalone, arm's-length, egen DB).
- **BESLUT-08 STÄNGT:** utgå från att vi **har Inera-signering**; **börja med en stub** i harness (Inera-backend som mål).
- **BESLUT-09 STÄNGT:** **räkna med AI**, men lägg implementeringen i **senare fas** (behåll människo-bekräftat, aldrig autonomt på sekretess).
- **BESLUT-13/16 förtydligat:** **ingen säkerhetskänslig/PII-data under utvecklingen** → säkerhetsskydds-grinden byggs ändå (prod-krav före utrullning till berörda roller) men är **inte en dev-blockerare**.
- Övriga REK (11, 25, 18, 19, 21, 22, 23, 24, 10, 12, 14, 15, 17, 20) — **ratificerade**.

> **Implementation startad** enligt denna ratificering. Se `HUBS-IMPLEMENTATIONSPLAN.md` (reviderad) + den nya appen `hubs_arende/` + provisioneringsrutinen `provision/`.

---

## Hur loggen ratificeras

Varje beslut har en rad i sammanfattningstabellen och en utvecklande paragraf. **Status** är `REK` (rekommendation klar att ratificera) eller `ÖPPET` (kräver externt underlag innan den kan låsas).

- **Ratificera allt:** svara **"ja till alla rekommendationer"** → alla `REK`-rader antas som beslut, alla `ÖPPET`-rader förblir öppna men med rekommenderad inriktning noterad.
- **Avvikelse:** markera per rad **R** (ratificera rek) · **A** (välj annat alternativ — ange vilket) · **H** (håll öppet).

Källbesluten ur underlagsdokumenten (B-\*/T-\*/J-\*) anges i spårningskolumnen. Beslut utan B-\*-spår är nya, resta av den verifierade dev15-verkligheten (infra/harness/kod).

---

## Sammanfattningstabell

| ID | Område | Kort fråga | REKOMMENDATION | Beroende | Spårar | Status |
|---|---|---|---|---|---|---|
| **BESLUT-01** | Datalager | sdkmc-DB vs ExApp-egen-DB? | (b) sdkmc-DB NU, schema ExApp-rent, (c) som mål | — (blockerar allt) | B-DL-1 | REK |
| **BESLUT-02** | Licens / ExApp-snitt | Får M4 vara proprietär? | Designa ExApp-rent + boka IP-jurist; inget propr.-beslut nu | 01 | B-LIC-1 | ÖPPET |
| **BESLUT-03** | sdkmc M0/M1-split | Bryta monoliten — när? | FÖRE M4-motorn byggs (kod-refaktor, ej data) | 01 | B-MOD-1 | REK |
| **BESLUT-04** | Appstore-strategi | Sidoladda vs privat registry? | Sidoladda nu; cr.itsl.se-registry som drift-mål | 06 | NY | REK |
| **BESLUT-05** | Deploy-överlevnad | Hur överlever bro/ExApp `itsl deploy`? | M4 som ExApp (överlever) + bro i bundle/itsl config | 04 | NY | REK |
| **BESLUT-06** | Bygg-/demoinstans | Experimentera i dev15? | Separat ren bygg-instans; dev15 = read-only referens | — | NY | REK |
| **BESLUT-07** | Referenskonnektor | Vilken konnektor först? | Treserva/Lifecare som referens, EdiariumConnector som test-orakel | 01,03 | K-5.10 | REK |
| **BESLUT-08** | Signerings-kravnivå | SES/AES/QES, Inera vs LibreSign? | Adapter, 2 backends; Inera-AES skarpt, LibreSign demo; matris per kund | 10 | B-SIGN-1 | ÖPPET |
| **BESLUT-09** | AI/transkribering | Skarp AI på sekretess? | Blockerad tills IMY/SKR/Soc-vägledning; lokal KB-Whisper + HITL | — | T-AI-1 | ÖPPET |
| **BESLUT-10** | Identitet/SSO | sociallogin/SCIM/oauth2/BankID? | Återanvänd befintliga (FINNS); BankID/Sweden Connect via Inera | — | NY | REK |
| **BESLUT-11** | commitDestination | per-kommun fallback-SoR? | NOT NULL-constraint nu; per-kommun fallback i config | 01 | B-INV-1/T-SOR-1 | REK |
| **BESLUT-12** | PuB/laglig grund/DPIA | Vem äger matrisen? | Config-fält från dag 1; innehåll = nämnden+DSO per kund | 01 | T-PUB-1 | REK |
| **BESLUT-13** | Säkerhetsskydd | Detektion + avvisning + regimbyte? | Fail-closed avvisa, terminerande preSagaHook, retroaktiv karantän | 01,03 | B-SEC-1 | REK |
| **BESLUT-14** | Versionsuppdatering | sdkmc-lyft timing? | Lyft till senaste pinnade FÖRE motor-bygg, på bygg-instansen | 06 | NY | REK |
| **BESLUT-15** | Hosting/drift ExApp+DB | Egen container vs hubs-postgres? | sdkmc-DB nu; vid (c) egen ExApp-container + egen DB+role | 01,02,04,05 | B-EXAPP-1 | REK |
| **BESLUT-16** | Testdata | Syntetisk PII-policy? | Endast syntetisk; CI-grind avvisar PII; ingen dev15-export | — | NY | REK |
| **BESLUT-17** | Prissättning/paketering | SKU-modell? | M0 obligatorisk · M1 ankare · M2 · M3 · M4(=M0+M1+motor) | 02,03 | B-SKU-1 | REK |
| **BESLUT-18** | Deck/Files/Spreed/Kontakter | Vyer-beslutet? | Strikt vyer/projektioner; registret = single source | 01 | K-2.17 | REK |
| **BESLUT-19** | Reconciliation/backup | Av registret? | ArendeReconciliationJob + arkivkritisk backup från dag 1 | 01,18 | GAP-056 | REK |
| **BESLUT-20** | Bemanning/kompetens | Vem bygger? | PHP/OCP + Vue 2.7 + DevOps(itsl) + IP-jurist + arkivarie | — | NY | REK |
| **BESLUT-21** | Route-versionering | /api/v1 vs /api/v2? | /api/v2/arende* (verifierad prefix), patcha api.js v1→v2 | — | B-API-1 | REK |
| **BESLUT-22** | Hooks vs fork (kat 6/8) | Gränsfall i samma motor? | Deklarerade pre/post-hooks, ingen fork | — | B-HOOK-1 | REK |
| **BESLUT-23** | Konfidenströskel | Klient ≥0.9 → server? | Granskad server-policy + obligatorisk människo-bekräftelse | — | B-MATCH-1 | REK |
| **BESLUT-24** | Var skrivs utredningstext | Collabora vs facksystem? | Skriv i facksystemets journal; Hubs speglar (ej dubbel-författa) | 18 | B-DOK-1 | REK |
| **BESLUT-25** | Retention-tröskel | Manuell vs callback? | Alltid verifierad commit-callback; andrahandläggar-bekräftan fristbärande | 07,11 | T-RET-1/B-RET-1 | REK |

---

## Utvecklande beslut

### BESLUT-01 — Internt datalager: sdkmc-DB vs ExApp-egen-DB
**Fråga:** Var bor ärenderegistret (`sdkmc_arende*`) — intern NC-app-DB i sdkmc, eller egen DB i en ExApp?
**Alternativ:** (a) NC Tables [underkänd: ingen single-writer/transaktion/constraint/rad-lås]; (b) sdkmc:s NC-app-DB via QBMapper/Migration; (c) separat ExApp-DB (egen container/Postgres).
**REKOMMENDATION:** Bygg i **(b) NU**, designa schemat **ExApp-rent** (inga `oc_*`-FK, opaka pekare, all skrivning via ETT service-lager), håll **(c) som mål**. Bekräfta att Tables underkänns.
**Motiv:** (b) = noll ny infrastruktur, kopierar exakt `ItslTagMapper extends QBMapper`, löser 100 % av Tables-bristerna idag. ExApp-rent schema gör (b)→(c) till data-/adapter-migrering, inte omskrivning. dev15: `tables` ABSENT → bekräftar att registret aldrig låg i Tables.
**Konsekvens om uppskjuts:** Blockerar all motor-implementation (saga, reconciliation, commit). Fel backend cementerar omskrivning. Det enda beslut allt annat hänger på.
**Beroende:** Blockerar 03, 07, 19; förutsätts av 15 och 02. **FATTA FÖRST.**

### BESLUT-02 — Licens / ExApp-snitt + IP-jurist
**Fråga:** Får M4-verksamhetslogiken vara proprietär, och vad krävs då?
**Alternativ:** (a) allt AGPL (säkrast, inget affärsvärde i separat motor); (b) M4 proprietär som ExApp (arm's-length HTTP/AppAPI, egen DB, privat distribution) efter juristverifiering.
**REKOMMENDATION:** Designa allt ExApp-rent och arm's-length nu, men fatta **INGET** proprietärt-licens-beslut förrän IP-/upphovsrättsjurist verifierat. Boka juristen tidigt (parallellt med P0).
**Motiv:** "Combined work" vs "separate program" är en rättsfråga. Juristen måste verifiera arm's-length-snittet, ingen AGPL-bundling på propr. sida, §13-skyldigheter, mail `-only` vs spreed `-or-later` (kan ej uppgraderas till framtida AGPLv4), securemails egen licens. Forkade `mail`/`spreed-itsl` ÄR AGPL oavsett kanal.
**Konsekvens om uppskjuts:** Bygger man propr. utan verifiering = potentiellt copyleft-brott → hela M4 måste öppnas/skrivas om. Att skjuta *juristen* är dyrt; att skjuta *beslutet* är gratis så länge designen hålls ExApp-rent.
**Beroende:** Beror på 01. Styr 15 och 17. **Status ÖPPET** (kräver jurist).

### BESLUT-03 — sdkmc M0/M1-split timing
**Fråga:** När bryts sdkmc-monoliten i M0 (sdkmc-core) + sdkmc-msg (M1)?
**Alternativ:** (a) FÖRE M4 byggs; (b) efteråt/parallellt; (c) aldrig.
**REKOMMENDATION:** **FÖRE M4 byggs.** Flytta `case:{id}`-taggmotorn + register + ArendeService till M0. Kod-refaktor med `class_alias`-facade (A0/A1), **inte** data-migration (`sdkmc_itsl_*` ligger kvar, ägandet flyttar). Rulla som patch-release 2.3.0.
**Motiv:** Byggs motorn i monoliten drar den med hela meddelande-stacken → "sälj M4 separat" blir osant och M0 cementeras.
**Konsekvens om uppskjuts:** Varje rad motor-kod i monoliten ökar refaktor-skulden; modulgränsen (= licensgränsen) går inte att dra i efterhand utan omskrivning.
**Beroende:** Beror på 01. Blockerar P0.2/P0.3. Stödjer 17.

### BESLUT-04 — Appstore-strategi (sidoladda vs privat registry)
**Fråga:** Hur installeras saknade appar (deck, tasks, forms, libresign, notes, **app_api**, aktivera contacts) när `appstoreenabled=false`?
**Alternativ:** (a) sidoladda i custom_apps + `occ app:enable`; (b) återaktivera appstore mot privat registry cr.itsl.se; (c) baka in i Hubs-imagen/bundle.
**REKOMMENDATION:** **Sidoladda NU** för snabb framdrift (custom_apps + occ), **planera privat registry (cr.itsl.se) som drift-mål**. För det som ska överleva deploy: se BESLUT-05. Hybrid: store-signerade tarballs (nextcloud-releases), libresign som specialfall.
**Motiv:** Sidoladdning är omedelbart möjlig och blockerar inget. Privat registry ger reproducerbar hantering + signering men kräver itsl-konfiguration. app_api MÅSTE in oavsett (förutsättning för ExApp).
**Konsekvens om uppskjuts:** Sidoladdade appar riskerar skrivas över vid `itsl deploy` (BESLUT-05). Ad hoc utan registry-plan ger drift-skuld.
**Beroende:** Hård koppling till 05 och 06. app_api blockerar all ExApp/(c).

### BESLUT-05 — Hur custom-appar/ExApp överlever `itsl deploy`
**Fråga:** `itsl deploy` bygger om hubs-php från pinnade images. Hur överlever vår kod?
**Alternativ:** (a) baka in i Hubs-imagen/bundlen; (b) persistent custom_apps-volym + itsl config-persistens; (c) separat ExApp (deployas av AppAPI → överlever).
**REKOMMENDATION:** **Motorn/M4 som ExApp (c)** — överlever per konstruktion. Tunna AGPL-bron (hubs_start + M0) bakas i Hubs-imagen/bundle (a) ELLER ligger i itsl-persisterad custom_apps-volym (b). libresign KRÄVER image-bakning (binärer). Bekräfta persistensväg med itsl.
**Motiv:** ExApp deployas utanför hubs-php → immun mot deploy-omskrivning. Tredje oberoende skäl (utöver licens och datalager) att lägga M4 i ExApp.
**Konsekvens om uppskjuts:** Nästa `itsl deploy` raderar tyst all sidoladdad kod → bygget är inte reproducerbart/driftsättbart. Livscykel-blockerare, inte finess.
**Beroende:** Beror på 04, 06, 15. Kräver itsl-dialog om config/image-persistens.

### BESLUT-06 — Bygg-/demoinstans (experimentera EJ i kund-dev15)
**Fråga:** Byggs och experimenteras det i kund-dev15 eller på separat ren instans?
**Alternativ:** (a) experimentera i dev15; (b) separat ren bygg-instans (NC 31 + Docker), dev15 = read-only.
**REKOMMENDATION:** **Separat ren bygg-instans.** dev15 förblir read-only referensverklighet. Installera/forka/testa destruktivt enbart på bygg-instansen. Bygg-instansen speglar **NC 31** (inte 32) för att fånga 31-specifika app-versionsproblem.
**Motiv:** dev15 är itsl-managed kundmiljö; install-experiment/app_api-deploy/version-lyft där riskerar kund-driften och bryter "aldrig riktig PII i dev/test". Ren instans ger fri experiment-yta + reproducerbar image-pipeline.
**Konsekvens om uppskjuts:** Risk att destruktiva occ/itsl-kommandon körs i kundmiljö; ingen kontrollerad bas att validera deploy-överlevnad (BESLUT-05) mot.
**Beroende:** Förutsättning för 04, 05, 14, 16. **Fatta NU innan första install.**

### BESLUT-07 — Vilken facksystem-/diariekonnektor FÖRST (referens)
**Fråga:** Vilken konnektor byggs först som referensimplementation?
**Alternativ:** (a) Treserva/Lifecare (socialtjänst-SoR); (b) generaliserad e-diarium/e-arkiv; (c) Sokigo-kluster.
**REKOMMENDATION:** **Treserva/Lifecare-konnektorn först** som referens (primär byggpersona = socialsekreterare committar dit). Men bygg **`EdiariumConnector` som test-orakel/referens-implementation** (enklast payload) och e-diarium/e-arkiv som ANDRA instansen — bevisar (modul × produkt)-mappningen. `TreservaCommitService` → **`FacksystemCommitService`** från start (rätt abstraktionsnivå).
**Motiv:** All UX/GAP-019 är skriven mot socialsekreteraren. En vertikal skiva skarp före breddning. Verifierad callback är grundorsak bakom commit/gallring/spegling.
**Konsekvens om uppskjuts:** Utan skarp referenskonnektor kan retention-bindning (GAP-007), provenans-flip och dnr-parning inte verifieras end-to-end → motorn förblir demo.
**Beroende:** Beror på 01/03. P0.2.

### BESLUT-08 — Signerings-kravnivåmatris (SES/AES/QES, Inera vs LibreSign)
**Fråga:** Hur sätts kravnivå per dokumenttyp och vilken signeringsbackend?
**Alternativ:** (a) bara LibreSign; (b) bara Inera Underskriftstjänst; (c) adapter med båda bakom samma kö-UI.
**REKOMMENDATION:** **(c) signeringsadapter med två utbytbara backends.** Skarpa myndighetsbeslut + externa signatärer → **Inera-AES**; internt lågrisk/demo → LibreSign (etiketterad ärligt "konto/SMS, ej BankID", ingen falsk LTV). **Kravnivå-badge (SES/Godkänn/AES/QES) per dokumenttyp = kund-/juristsatt matris** — Hubs visar, gissar ej. Endast hash exponeras (OSL 10:2a).
**Motiv:** LibreSign-AES ≠ svensk myndighets-AES; ett SMS-signerat avslag står sig inte i förvaltningsrätt. Mappning beslutstyp→kravnivå är policy/juridik per kommun.
**Konsekvens om uppskjuts:** Skarpa beslut signeras på fel nivå → ogiltiga i domstol. dev15 saknar libresign → måste installeras (BESLUT-04).
**Beroende:** Inera kundavtal + SITHS/HSA-anslutning (veckor–månader), mTLS vs OOB (GAP-033). Kopplar till 10. **Status ÖPPET** (Inera-avtal lång ledtid).

### BESLUT-09 — AI/transkribering-policy
**Fråga:** Får AI/LLM/transkribering köra skarpt på sekretessbelagt innehåll?
**Alternativ:** (a) skarp AI nu; (b) blockerad tills myndighetsvägledning; (c) lokal modell + human-in-the-loop, aldrig autonom på sekretess.
**REKOMMENDATION:** **Blockerad för skarp/autonom körning på sekretess tills IMY/SKR/Socialstyrelsen gett vägledning.** LLM = valfritt, avstängbart, människo-bekräftat förslagslager. Medborgar-PII/ärendetext lämnar aldrig huset. Lokal KB-Whisper endast efter vägledning. Demo-konstanten `≥0.9` får ALDRIG nå produktion som klientlogik.
**Motiv:** Skarp drift på sekretessbelagt klientsamtal med AI = röd zon (GAP-052). dev15 har fulltextsearch+elasticsearch men ingen AI-pipeline → inget skarpt AI-flöde att av misstag aktivera.
**Konsekvens om uppskjuts:** Risk att transkribering/sammanfattning aktiveras utan rättslig grund → sekretess-/GDPR-incident.
**Beroende:** T-AI-1 (extern vägledning). Kopplar till 23. **Status ÖPPET** (extern vägledning).

### BESLUT-10 — Identitet/SSO
**Fråga:** Hur löses inloggning, provisionering och e-legitimation?
**Alternativ:** (a) bygg nytt; (b) återanvänd befintliga dev15-appar (sociallogin, scimserviceprovider, oauth2 — alla ENABLED); (c) addera BankID/Sweden Connect.
**REKOMMENDATION:** **Återanvänd FINNS-appar** (sociallogin för IdP-SSO, scimserviceprovider för provisionering, oauth2 för app-auth — verifierat enabled). **BankID/Sweden Connect endast via Inera Underskriftstjänst** för signering/identitetsintygande (ej vardagsinlogg) — koppla till BESLUT-08. ExApp autentiserar via AppAPI mot NC-session, inte egen IdP.
**Motiv:** Inloggnings-/SCIM-/oauth2-stacken är redan körande produktion → noll nybygge. BankID-inlogg är separat tyngre fråga som inte blockerar motorn.
**Konsekvens om uppskjuts:** Mindre — befintlig stack räcker för P0/P1. BankID-lobby för Spreed (GAP-021) och Sweden Connect för signering planeras till P2.
**Beroende:** Kopplar till 08. ExApp-auth kopplar till 05/15.

### BESLUT-11 — Per-kommun commitDestination/fallback-SoR
**Fråga:** commitDestination som NOT NULL — och hur sätts fallback-SoR per kommun?
**Alternativ (constraint):** (a) hård DB-NOT-NULL; (b) validerings-grind i ArendeService. **(fallback):** allmän handling→diarium ELLER arbetsmaterial→tidsbegränsat mellanlager med gallringsregel.
**REKOMMENDATION:** **Hård NOT NULL-constraint NU** (DB stärker invarianten Tables aldrig kunde garantera). **Fallback-SoR per (c)-kategori sätts per kommun i config** (T-SOR-1) innan retention-flippen tillåts. `sorFallback` ∈ {diarium, e_arkiv, ingen_route, null} datadrivet och granskningsbart.
**Motiv:** Dödligaste felmoden (9/12 roller): ärende som varken committas eller medvetet avslutas → Hubs blir SoR genom passivitet (otillåten gallring av allmän handling, TF).
**Konsekvens om uppskjuts:** Utan constraint blir Hubs tyst de-facto-SoR — skuggregister utan gallringsklocka. Enskilt viktigaste regeln.
**Beroende:** Beror på 01. Kopplar till 25.

### BESLUT-12 — PuB-/laglig-grund-/DPIA-ägarskap
**Fråga:** Vem äger PuB-/laglig-grund-/ändamåls-matrisen, och var bor den?
**Alternativ:** (a) hårdkoda; (b) `ArendeTyp`-config-fält från dag 1 (`lagligGrund`, `personuppgiftsansvarig`, `andamaalsbegransning`, `gdpr_art9`), innehåll per kund.
**REKOMMENDATION:** **Config-fält i ArendeTyp-registry från dag 1; innehållet ägs av nämnden (personuppgiftsansvarig) + DSO per kund** (T-PUB-1). Biträdesavtal mot driftleverantör reglerar PuB-förhållandet; activity-logg minimeras (referens/ID, ej PII) + egen gallringsfrist.
**Motiv:** Utan matrisen är Hubs juridiskt osäljbart (PuB oundertecknbart, RoPA omöjlig). dev15 har admin_audit + activity enabled → audit-bas finns men måste minimeras.
**Konsekvens om uppskjuts:** Schemat måste byggas om om fälten saknas; ingen kund kan signera PuB.
**Beroende:** Schema-fält NU (med 01); innehåll per-kund. Kopplar till 16.

### BESLUT-13 — Säkerhetsskydds-detektion/avvisning + regimbyte
**Fråga:** Hur detekteras säkerhetsskyddsklassificerat *före* R2, och hur avvisas/karantänsätts det?
**Alternativ:** (a) deterministisk regel; (b) manuell flagga; (c) avsändar-LOA; + (d) retroaktiv karantän; (e) regimbyte vid höjd beredskap.
**REKOMMENDATION:** **Fail-closed (default = avvisa på indikator), terminerande `preSagaHook='avvisa_sakerhetsskydd'` som led −1 FÖRE R1** (ingen Groupfolder/DB-rad-med-innehåll/case-tagg/Spreed-rum/commit — bara spårbart avvisningskvitto). **Retroaktiv karantän byggs OCH testas** (radera ur index/Spreed/Groupfolder/tag-DB efter mottagning). **Regimbyte** (höjd_beredskap/krig flyttar hela kategorier) stöds. Skild från `skyddade_personuppgifter` (snävare ACL inom systemet).
**Motiv:** Att behandla säkerhetsskyddsklassat (SäkL 2018:585 + förordn. 2021:955) som skyddade PU = **lagbrott**. Den enda hårda gräns som inverterar mellanlager-principen. Lagbrottsgolv, ej UX.
**Konsekvens om uppskjuts:** Hubs får INTE släppas på roller utanför socialtjänst (8/12 roller flaggar detta) förrän klart. P0.4 blockerar all breddning.
**Beroende:** Beror på 01/03. P0-SÄK. Kopplar till visselblåsning (NON-route, samma hårda gräns).

### BESLUT-14 — Versionsuppdatering timing
**Fråga:** När lyfts sdkmc/komponenter till senaste pinnade version? (dev15 kör 2.2.25.)
**Alternativ:** (a) lyft före motor-bygg; (b) bygg mot nuvarande, lyft sen; (c) frys.
**REKOMMENDATION:** **Lyft till senaste pinnade version FÖRE motor-bygg — på bygg-instansen, inte dev15.** Bekräfta att hubs_start (NC 30–32) och M0-mönstren (ItslTag/Migration) är stabila i målversionen. dev15:s 2.2.25 är referens; bygg-instansen kör målversion.
**Motiv:** Bygga motorn mot en version som strax byts ger dubbelt valideringsarbete + risk att mönster (QBMapper/Migration-API) skiftar. Version-lyft i kundmiljö hör inte hemma här.
**Konsekvens om uppskjuts:** Motor-kod kan behöva omtestas mot ny version; migrations-API-skillnader upptäcks sent.
**Beroende:** Beror på 06. Före P0.2/P0.3.

### BESLUT-15 — Hosting/drift av ExApp + DB
**Fråga:** Var körs ExAppen och dess DB? (dev15: /var/run/docker.sock root:docker; hubs-postgres med eget Hubs-role.)
**Alternativ:** (a) egen Postgres-container; (b) ny DB+role i hubs-postgres; (c) ExApp via Docker deploy-daemon (AppAPI).
**REKOMMENDATION:** **I (b)-fasen: registret i sdkmc:s NC-app-DB (ingen egen DB).** Vid (c)-lyft: **egen ExApp-container via AppAPI/Docker-socket + EGEN DB som ny DB+role i hubs-postgres** (egen role, inte "postgres", inte Hubs-rollen). Egen Postgres-container endast om isolering kräver. **Reservera DB-role-namn + container-resurser nu** så (c)-lyftet inte stoppas på drift-detaljer.
**Motiv:** Docker-socket + hubs-postgres finns → ExApp-deploy möjlig utan ny infrastruktur. ExAppen behöver EGEN DB (ExApp-rent, ingen oc_*-koppling). app_api först.
**Konsekvens om uppskjuts:** Bygg inte i förskott (B-EXAPP-1), men reservera namn/resurser tidigt.
**Beroende:** Beror på 01 (mål c), 02 (licens), 04 (app_api), 05 (deploy-överlevnad).

### BESLUT-16 — Testdata-policy (syntetisk PII)
**Fråga:** Vilken testdata används i dev/test/bygg?
**Alternativ:** (a) anonymiserad kunddata; (b) endast syntetisk; (c) maskerad export.
**REKOMMENDATION:** **Endast syntetisk PII. Hård regel.** Ingen export från dev15 eller kundmiljö. Inför **CI-/seed-grind som avvisar PII-mönster** (personnummerformat, riktiga namn). Demo-fixtures (treserva.js seedRegister) förblir syntetiska och faller bort i prod.
**Motiv:** Hårt krav: aldrig riktig PII i dev/test. Anonymisering är aldrig fullständig för sekretessdata; syntetisk data eliminerar risken.
**Konsekvens om uppskjuts:** En enda riktig-PII-läcka i test = sekretess-/GDPR-incident och förtroendeskada.
**Beroende:** Gäller NU, oberoende. Kopplar till 06, 12.

### BESLUT-17 — Modul-prissättning/paketering (SKU)
**Fråga:** Hur paketeras de fyra säljbara modulerna?
**Alternativ:** (a) fyra likvärdiga block; (b) kärna-plus-tillägg.
**REKOMMENDATION:** **Fyra SKU:er ovanpå obligatorisk M0: M1 Meddelanden (ankare, självförsörjande) · M2 Video&Chat · M3 Filer · M4 Verksamhet (= M0+M1+motor; M2/M3/Kontakter tillval).** Beroenden mot M2/M3/Kontakter mjuka (graceful via AppDetectionService). Per-konnektor-prissättning (facksystemkonnektorer = separat licensierbara artefakter).
**Motiv:** Kod-verkligheten tvingar kärna-plus-tillägg (sdkmc är redan delat plattformslager; M4 lagrar ingen egen NC-data). Affärsbeslut, inte kod-blockerande.
**Konsekvens om uppskjuts:** Påverkar inte kritiska vägen, men prissättning/avtal kan inte slutföras.
**Beroende:** Beror på 02/03. Affärsspår parallellt.

### BESLUT-18 — Deck/Files/Spreed/Kontakter som vyer-beslut
**Fråga:** Är NC-objekten vyer/projektioner eller källa?
**Alternativ:** (a) NC-objekt som källa (state i Deck/Files); (b) NC-objekt som rena vyer, registret = single source of truth.
**REKOMMENDATION:** **Strikt (b): NC-objekten är projektioner/vyer.** DB-raden bär objektets opaka id; NC-objektet bär `case:{id}`-tagg tillbaka. Single-writer = ArendeService. Favorit-vCard taggas ALDRIG `hubsCaseId`. Aktivera contacts (DISABLED på dev15); deck/tasks/calendar installeras/finns.
**Motiv:** Garanterar register som single point of truth → Deck/Files/Spreed kan bytas/saknas (graceful) utan att ärendet förloras. dev15 har spreed/calendar/collectives/whiteboard + groupfolders/files_* enabled → vy-infrastrukturen finns.
**Konsekvens om uppskjuts:** Smyger state in i NC-objekten bryts single-writer och reconciliation blir omöjlig; modulgränsen kollapsar.
**Beroende:** Beror på 01. Stödjer 19. AppDetectionService utökas kanal→funktion.

### BESLUT-19 — Reconciliation/backup av registret
**Fråga:** Hur säkras registrets integritet och överlevnad?
**Alternativ:** (a) ingen aktiv reconciliation; (b) `ArendeReconciliationJob` (BackgroundJob) + arkivkritisk backup från dag 1.
**REKOMMENDATION:** **Bygg ArendeReconciliationJob + arkivkritisk backup från start (GAP-056).** Jobbet verifierar periodiskt: (1) varje pekare existerar, (2) varje `case:X`-objekt har registerrad, (3) `conversationIds[]` matchar taggade meddelanden. Deny-by-default i aggregeringen vid divergens. Mönster: `processAllPendingDeletions` driven av `DeleteTagsJob`.
**Motiv:** Token bärs på två ställen (DB-pekare + objekt-tagg) → kan driva isär. Registerförlust = alla appar tappar ärendekoppling samtidigt → backup är arkivkritisk.
**Konsekvens om uppskjuts:** Dinglande pekare upptäcks aldrig; registerförlust = total ärendekopplings-förlust utan återställning.
**Beroende:** Beror på 01/18. P0.3 (backup) + P1.5 (reconciliation).

### BESLUT-20 — Bemanning/kompetens
**Fråga:** Vilken kompetens krävs och finns den?
**Alternativ:** (a) befintligt team; (b) komplettera med specialkompetens.
**REKOMMENDATION:** Säkra minst: **PHP/OCP-utvecklare** (sdkmc/QBMapper/saga), **Vue 2.7/@nextcloud/vue-frontend** (1 obyggd komponent + seams), **DevOps med itsl/Docker/AppAPI-vana** (deploy-överlevnad, ExApp), **IP-/upphovsrättsjurist** (BESLUT-02), samt **arkivarie/kommunjurist-rådgivning** (DHP, retention, sekretessgrunder) och **säkerhetsskyddskompetens** (BESLUT-13).
**Motiv:** Bygget spänner backend-saga, NC-app-mönster, container-drift OCH tung juridik. Saknad DevOps-/itsl-vana gör BESLUT-05 ogenomförbar; saknad jurist blockerar BESLUT-02.
**Konsekvens om uppskjuts:** Flaskhalsar mitt i kritisk väg; juristledtid (veckor) och Inera-anslutning (veckor–månader) måste startas tidigt.
**Beroende:** Tvärgående. Starta juristspår (02/08) och Inera-avtal (08) NU.

### BESLUT-21 — Route-versionering (/api/v1 vs /api/v2)
**Fråga:** Vilket route-prefix för nya ärende-routes?
**Alternativ:** (a) /api/v1/ (designdokens antagande); (b) /api/v2/ (verifierad i sdkmc/appinfo/routes.php).
**REKOMMENDATION:** **/api/v2/arende\*** (`POST /api/v2/arende`, `GET /api/v2/arende/{ref}`, `/arende-summary`, `/arende/{ref}/tilldela`, `/treserva/commit`). Patcha `api.js:63` `SDKMC_OCS` v1→v2 i samma PR som kopplar bort demo (load-bearing — annars 404).
**Motiv:** hubs_start kallar redan 27 OCS-anrop mot v2-konventionen; controllern läggs bredvid `itsl_tag#*`-routerna.
**Konsekvens om uppskjuts:** Frontend-/backend-kontraktsmismatch; lågt risk men bekräfta vid bygg.
**Beroende:** P1.1.

### BESLUT-22 — Hooks vs fork för kat 6 & 8
**Fråga:** Löses gränsfallen (LVU/LVM, familjerätt, bygglov) med hooks eller egen kod?
**Alternativ:** (a) deklarerade pre/post-hooks inom samma motor; (b) forka/separat kodflöde.
**REKOMMENDATION:** **(a) deklarerade pre/post-hooks, ingen fork.** Kat 6: `preSagaHook='diariefor_direkt'` + `fristPolicy.typ='domstol'`. Kat 8: `partsModell='flerpartsärende'` + `aclProfil='familjeratt_inre_sekretess'` + `postCommitHook`. Sagans kompensering oförändrad.
**Motiv:** Två kategorier böjer men bryter inte hypotesen "en motor, en saga, N config-rader". Fork = kombinatorisk explosion.
**Konsekvens om uppskjuts:** Motor-design-risk om hooks inte bekräftas; bekräftelse är billig.
**Beroende:** Motor-design (P1.1).

### BESLUT-23 — Server-side konfidenströskel
**Fråga:** Var bor auto-kopplings-/klassningströskeln?
**Alternativ:** (a) klient-/demo-konstant (`≥0.9`); (b) granskad server-side policy per kund + obligatorisk människo-bekräftelse.
**REKOMMENDATION:** **(b): flytta tröskeln till granskad server-policy.** Tre utfall: ≥tröskel→`klassad`/`kopplad` (auto, redigerbar); <tröskel m. kandidat→`föreslagen` (människa bekräftar; **bilaga speglas vid bekräftelse, inte förslag**); noll/motstridig→`oklassad`/`ej_kopplat` (manuell triage). Default vid otillräcklig signal = fail mot människa.
**Motiv:** Felkoppling = sekretessincident. SSN-steget måste vara `joinNyckel`-betingat avstängbart (objektärenden/anonyma TF 2:18).
**Konsekvens om uppskjuts:** Om demo-konstanten `≥0.9` når produktion som klientlogik = okontrollerad auto-koppling över sekretessgränser.
**Beroende:** GAP-060. P1.3. Kopplar till 09.

### BESLUT-24 — Var skrivs utredningstexten
**Fråga:** Skrivs utredningstext i Collabora-i-Hubs eller direkt i facksystemets journal?
**Alternativ:** (a) Collabora-i-Hubs, spegla; (b) skriv direkt i facksystemets journal, Hubs speglar pekare; (c) hybrid.
**REKOMMENDATION:** **(b) skriv i facksystemets journal — Hubs dubbel-författar aldrig.** Hubs koordinerar/speglar (`fristDue` ur facksystemet), äger aldrig verksamhetssanningen. dev15 har richdocuments (Collabora) enabled → håll till arbetsmaterial/intern medbedömning, inte journaltext.
**Motiv:** Två konkurrerande källor till samma utredning bryter SoR-doktrinen och skapar gallrings-/bevismismatch.
**Konsekvens om uppskjuts:** Avgör vad som speglas och hur CommitGrind fungerar; oklart ägarskap ger dubbla sanningar.
**Beroende:** Beror på 18. P1.

### BESLUT-25 — Retention-/gallringströskel
**Fråga:** Får Hubs gallra på manuell "markera överförd", eller krävs alltid verifierad commit-callback?
**Alternativ:** (a) manuell "markera överförd"-kryssruta; (b) alltid verifierad facksystem-callback; (c) callback + andrahandläggar-bekräftan för fristbärande.
**REKOMMENDATION:** **(b)+(c): gallring triggas N dagar efter VERIFIERAD commit-callback — aldrig på tagg+tid eller manuell kryssruta ensam.** Andrahandläggar-bekräftan för fristbärande poster. Retention pausas automatiskt vid registrerad utlämnandebegäran (TF). `retentionAnkare` lösgjord från commit för kamera/lagstadgad tid.
**Motiv:** Worst case: "överförd" markeras utan registrering → Hubs gallrar enda kopian → bevarandepliktig handling/frist försvinner = arkiv-/offentlighetsbrott (GAP-007). Mönstret finns redan bevarat i stubben `commitHandling()`.
**Konsekvens om uppskjuts:** Om gallring binds till kryssruta i produktion kan allmän handling raderas otillåtet.
**Beroende:** Beror på 07 (konnektor levererar callback), 11 (commitDestination). P1.2.

---

## Ratificeringsordning (kritisk väg)

1. **NU, oberoende:** BESLUT-06 (bygg-instans), BESLUT-16 (syntetisk PII), BESLUT-20 (bemanning + starta jurist/Inera-ledtid).
2. **FÖRST (blockerar allt):** BESLUT-01 (datalager) → BESLUT-03 (M0/M1-split), BESLUT-11 (NOT NULL), BESLUT-12 (PuB-fält), BESLUT-18 (vyer).
3. **Infra-grind före install/deploy:** BESLUT-04 (sidoladda) → BESLUT-05 (deploy-överlevnad) → BESLUT-14 (versionslyft) → BESLUT-15 (ExApp-hosting, reservera).
4. **P0-säkerhetsgolv:** BESLUT-07 (referenskonnektor), BESLUT-13 (säkerhetsskydd — innan icke-socialtjänst-roller).
5. **P1:** BESLUT-19 (reconciliation/backup), BESLUT-21 (routes), BESLUT-22 (hooks), BESLUT-23 (tröskel), BESLUT-24 (utredningstext), BESLUT-25 (retention).
6. **Extern/lång ledtid (starta tidigt, ratificeras när underlag finns):** BESLUT-02 (IP-jurist), BESLUT-08 (Inera-avtal), BESLUT-09 (myndighetsvägledning), BESLUT-10 (BankID/Sweden Connect), BESLUT-17 (SKU/affär).

**Kärnkedja:** `BESLUT-06/16 (miljö+data) → BESLUT-01 (datalager) → BESLUT-03 (split) + BESLUT-04/05 (install överlever deploy) → BESLUT-07 (konnektor) + BESLUT-13 (säkerhetsgolv) → BESLUT-19/23/24/25 (motor skarp) → bredd`.

---

## Ratificering

> **[ ] Ja till alla rekommendationer** — alla `REK`-rader antas som beslut; `ÖPPET`-rader (02, 08, 09) behåller rekommenderad inriktning men låses först när externt underlag (IP-jurist / Inera-avtal / myndighetsvägledning) finns.
>
> **Avvikelser (per rad):** _______________________________________________
>
> Ratificerad av: ____________________  Datum: ____________

---

**Källfiler (absoluta):**
- `C:/Users/fredrik.jonasson/Cursor/Nextcloud-vanilla/hubs_start/docs/HUBS-KRAVSTALLNING-TOTAL.md` (§9 beslutslogg B-/T-/J-, §10 roadmap)
- `C:/Users/fredrik.jonasson/Cursor/Nextcloud-vanilla/hubs_start/docs/MODULARISERING-LICENS-DATALAGER.md` (§5 beslut + migrationsväg)
- `C:/Users/fredrik.jonasson/Cursor/Nextcloud-vanilla/hubs_start/docs/KOMMUNROLLER-SOR-INTEGRATIONER.md` (per-kommun/SoR/fackverktyg, säkerhetsskydd-gränsen)
- `C:/Users/fredrik.jonasson/Cursor/Nextcloud-vanilla/hubs_start/docs/HUBS-IMPLEMENTATIONSPLAN.md` (master-implementationsplanen denna logg stödjer)
