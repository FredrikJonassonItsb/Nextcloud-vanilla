<!--
SPDX-FileCopyrightText: 2026 ITSL
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# HUBS — TOTAL KRAVSTÄLLNING (handover)

**Till:** Bygg-tråden (ny tråd som ska bygga den skarpa lösningen ur prototypen) · **Från:** Konsolideringen av målarkitektur-syntesen + 8 kravsektioner · **Datum:** 2026-06-16 · **Plattform:** Nextcloud server v32 (Hub 25 Autumn)

> **Vad detta dokument är.** EN självbärande handover som konsoliderar all tidigare utredning till en konsekvent, omnumrerad kravställning. Den är navigerbar (§0 Executive Summary → §1–§8 krav per område → §9 Beslutslogg → §10 Roadmap → §11 Dokumentregister). Den löser motsägelser mellan tidigare PM och behåller status-taggarna.
>
> **Status-taggar (genomgående):** `[FINNS]` = verifierad körande kod · `[BYGGS]` = designat, ingen kod · `[KONFIG]` = befintlig mekanism, kräver konfiguration · `[BESLUT KRÄVS]` = öppen fråga som blockerar/styr bygget · `[EXTERN]` = ligger utanför Hubs domän (facksystem/myndighet/jurist/3:e-part).
>
> **Varumärkesregel.** I denna arkitektur-/utvecklartext används tekniska namn (Talk/Tables/Deck/AppAPI/ExApp/Circles/Spreed/Groupfolders) fritt. I **kund-/produkt-UI** gäller motsatt regel — aldrig "Nextcloud"/"Talk"/"Circles"; använd *Hubs, korg, funktionsadress, säkert meddelande, ärenderum, ärendechatt, enhetschatt, team, bevakning, fördelningsvy, e-underskrift, facksystemet/Treserva*.
>
> **Disclaimer.** Licens-/upphovsrättsresonemangen nedan är arkitekturunderlag, **inte juridisk rådgivning**. Inget proprietärt licensbeslut får fattas utan IP-/upphovsrättsjurist (se §9 B-LIC-1).

---

## 0. EXECUTIVE SUMMARY

### 0.1 Vad Hubs är

Hubs är en **säker kommunikations- och ärende-koordinationssvit för kommunal verksamhet under offentlighets- och sekretesslagen (OSL)**, byggd på en härdad Nextcloud-plattform (server v32 / Hub 25) med forkade och egenbyggda appar. Den bärande tesen, verifierad mot 12 kommunala roller, är att **skillnaderna mellan kommunens verksamheter är DATA, inte kontrollflöde**: en motor, en saga, N datadrivna `ArendeTyp`-config-rader, M ortogonala flaggor.

Hubs är ett **mellanlager** mellan inflöde och facksystem. Den **äger aldrig verksamhetssanningen** — den koordinerar inflöde, samverkan och e-underskrift fram tills informationen committas till facksystemet (Treserva/Lifecare/Viva/diarium/e-arkiv), som förblir **System of Record (SoR)**. **Utanför scope:** att vara verksamhetens SoR.

### 0.2 Målarkitekturen (ett val löser tre frågor)

De tre till synes separata frågorna — *hur säljer vi delarna var för sig?* (modul), *måste verksamhetslogiken vara AGPL?* (licens), *var ska ärenderegistrets state ligga?* (datalager) — har **ett gemensamt svar**: en **ExApp** (Nextclouds AppAPI-ramverk för appar som körs som separata containrar/tjänster över ett definierat HTTP-API, eget språk, egen DB).

> **Tunn AGPL-bro-app i NC** (hubs_start + M0 `sdkmc-core`, "tunn & dum", ingen affärslogik) ↔ **HTTP/AppAPI** ↔ **proprietär-kapabel M4-verksamhets-ExApp** med egen intern DB ↔ moduler M1–M3.

- **Processgränsen ÄR licensgränsen.** In-process PHP mot OCP = "combined work" → AGPL. ExApp i egen process/DB med app-nivå-HTTP-API = "separate program" → potentiellt proprietär.
- **Modul-paketering:** fem moduler i en **kärna-plus-tillägg-modell** — **M0** plattformskärna (obligatorisk, osynlig), **M1** Meddelanden (ankarprodukt), **M2** Video & chat, **M3** Filer, **M4** Verksamhet (motor + valbara konnektorer).
- **Datalager:** Tables **underkänd** som motor-backend (ingen single-writer/transaktion/constraint/rad-lås). Börja i **(b) sdkmc proper NC-app-DB** (QBMapper/Migration), schema **ExApp-rent** (inga `oc_*`-FK:er, opaka pekare) → lyft till **(c) ExApp-DB** blir data-/adapter-migrering, inte omskrivning.

### 0.3 De bärande principerna (invarianter som aldrig får brytas)

1. **Hubs är ALDRIG System of Record.** Intern DB håller *var saker ligger* (pekare/koordination), aldrig *sakerna själva* (utredning/beslut/journal bor i facksystemet). "Kartan, inte territoriet."
2. **commitDestination-invarianten.** Varje `ArendeTyp`-rad MÅSTE ha icke-null `commitDestination` (`facksystem · diarium · e_arkiv · extern_myndighet · triage_forward · karantan`), hävdat som NOT NULL i schemat. Annars blir Hubs tyst de-facto-SoR (skuggregister utan gallringsklocka) — den dödligaste felmoden (9/12 roller).
3. **Ärende-först, aldrig kanal-först.** `hubsCaseId` är den röda tråden; "öppna ärende" är server-side-aggregering över `case:{id}`-tagg, aldrig klient-fan-out.
4. **Single-writer.** Registret skrivs uteslutande av `ArendeService` (sagan), aldrig av handläggaren rått eller controllers direkt. NC-objekten (Deck/Files/Spreed/Kontakter) är **vyer/projektioner**.
5. **Retention bunden till verifierad commit**, aldrig tid/kryssruta.
6. **Terminerande "föds inte"-utfall.** Sagan kan idag bara *föda*; säkerhetsskydd + visselblåsning kräver att motorn kan *vägra* (avvisa/karantän, även retroaktivt).
7. **Graceful degradation.** Varje grannberoende saga-steg hoppas om grannappen saknas, utan att invarianterna bryts (`AppDetectionService`).

### 0.4 Omfattning

**EN handover · 8 kravområden · ~200 konsoliderade krav** (omnumrerade i §1–§8) · **17 bygg-delta Δ1–Δ17** · **GAP-001–066** (varav 9 blockerare) · **12 kommunala roller** · **fem moduler M0–M4** · **8 `ArendeTyp`-baskategorier**.

### 0.5 Topp-beslut som MÅSTE fattas innan bygget (sammanfattning, full lista §9)

| # | Beslut | Tagg |
|---|---|---|
| **B-DL-1** | **Datalager-valet** — bekräfta Tables underkänns; bygg registret i (b) sdkmc-DB nu, ExApp-rent schema, (c) ExApp-DB som mål. **Allt bygg hänger på detta — fatta först.** | `[BESLUT KRÄVS]` |
| **B-MOD-1** | **Bryt sdkmc i M0 + sdkmc-msg FÖRE M4 byggs** (kod-refaktor, ej data-migration). Annars är "sälj M4 separat" osant. | `[BESLUT KRÄVS]` |
| **B-LIC-1** | **Proprietär M4?** Kräver ExApp arm's-length + privat distribution + **IP-jurist-verifiering**. Inget proprietärt beslut på enbart arkitektur-PM. | `[BESLUT KRÄVS]` / `[EXTERN]` |
| **B-INV-1** | **commitDestination som NOT NULL-constraint** i `sdkmc_arende_typ` — hävda invarianten i schemat. | `[BESLUT KRÄVS]`→`[BYGGS]` |
| **B-SEC-1** | **Säkerhetsskydds-detektion + retroaktiv karantän** (per kommun): vilka funktionsadresser är högrisk; är abort/karantän testad; regimbyte vid höjd beredskap. Lagbrottsgolv, ej UX. | `[BESLUT KRÄVS]` |
| **B-RET-1** | **Gallringströskeln** — får Hubs gallra på manuell "markera överförd" eller krävs alltid verifierad commit-callback? | `[BESLUT KRÄVS]` |
| **B-SOR-1** | **Fallback-SoR per (c)-kategori + fackverktyg-köp vs diarium-fallback** (per kommun). | `[BESLUT KRÄVS]` / `[KONFIG]` |
| **B-DOK-1** | **Var skrivs utredningstexten** — Collabora-i-Hubs vs direkt i facksystemets journal (dubbel-författande-risk). | `[BESLUT KRÄVS]` |

### 0.6 P0-byggsteg (kritisk väg, full roadmap §10)

1. **B-DL-1 datalager-valet** (blockerar allt). 2. **GAP-019 FacksystemCommitService** med verifierad callback (tyngst). 3. **GAP-056 `sdkmc_arende`-registret** + single-writer + saga/kompensering + reconciliation + backup. 4. **GAP-010 atomär `createCase()`-saga** + OCS-routes. 5. **GAP-007 gallring bunden till verifierad callback.** 6. **GAP-057/058 atomär fördelning + tre-lagers-ACL-koherens.** 7. **Δ7/Δ8/Δ9 säkerhetsskydds-/visselblåsnings-terminering** (motor-kärnan) — INNAN Hubs släpps utanför socialtjänst.

---

## 1. SCOPE, VISION, PERSONAS/ROLLER & MODUL-PAKETERING

> **Områdeskod:** `OVERSIKT`. Konsoliderar `HUBS-ARKITEKTUR-SOCIALTJANST.md`, `KOMMUNROLLER-SOR-INTEGRATIONER.md`, `MODULARISERING-LICENS-DATALAGER.md`.

### 1.1 Vision & scope

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-1.1** | Hubs SKA vara en säker kommunikations- och ärende-koordinationssvit för kommunal verksamhet under OSL, byggd på NC v32 (Hub 25). Scope = *mellanlager* mellan inflöde och facksystem; **utanför scope** = att vara verksamhetens SoR. | Etablerad målmodell över fem arkitektur-varv; scope-gränsen är förutsättning för var-saker-bor-besluten. | `[BYGGS]` (plattform `[FINNS]`) |
| **K-1.2** | Hubs SKA hålla isär **tre identifierare per ärende**: `conversationId` (provenans-ankare, föds vid inflöde), `hubsCaseId` (kanonisk UUID, föds vid ärendeskapande, bärs av varje objekt), `dnr` (facksystemets nyckel, föds vid commit). `hubsCaseId` är röda tråden; `dnr` kan saknas utan att ärendet upphör. | Löser GAP-005 (token↔dnr) och GAP-002 (fristens start). Ärendet finns *innan* dnr existerar. | `[BYGGS]` |
| **K-1.3** | "Öppna ärende" SKA vara **server-side aggregering** ("alla objekt taggade `case:{hubsCaseId}`"), aldrig klient-fan-out. Fil-taggbara objekt bär systemtaggen; icke-taggbara (Deck-kort/Talk-rum/kalender) via strukturerad register-pekare. | O(1)-uppslag; samlar OSL-sekretessgränsen på ett ställe. Taggmotorn (`ItslTagService`) finns. | Taggmotor `[FINNS]`, aggregat `[BYGGS]` |
| **K-1.4** | Hubs SKA stödja **graceful degradation** — varje grannberoende funktion ska kunna släckas om grannappen saknas, utan att kärninvarianterna bryts. | `AppDetectionService` finns och är fundamentet för "sälj separat"; måste utökas från kanal- till funktions-detektering. | `[FINNS]` (utökas `[BYGGS]`) |

### 1.2 Mellanlager-principen & SoR-doktrinen (aldrig System of Record)

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-1.5** | Hubs SKA **aldrig vara SoR för verksamhetsdata**. Intern DB håller endast mellanlagrets koordinations-state (var saker ligger), aldrig sakerna själva. Att byta backend (Tables→DB) ändrar inte SoR-statusen. | "Kartan, inte territoriet." | `[BYGGS]` |
| **K-1.6** | Varje `ArendeTyp`-rad SKA ha **icke-null `commitDestination`** (`facksystem · diarium · e_arkiv · extern_myndighet · triage_forward · karantan`), hävdat som **NOT NULL-constraint** — inte bara kod-konvention. | Dödligaste felmoden (9/12 roller): varken commit eller medvetet avslut → Hubs blir SoR genom passivitet (otillåten gallring av allmän handling, TF). | `[BESLUT KRÄVS]`→`[BYGGS]` |
| **K-1.7** | SoR-doktrinen SKA realiseras som **fyrvägs-utfall** per ärendetyp: (i) Fallback-SoR (diarium/e-arkiv när facksystem saknas), (ii) Nytt register/pekare (Hubs pekar, blir ej SoR), (iii) Tidsbegränsat mellanlager med spårbarhet (kräver uttryckligt beslut + gallringsregel + rättslig grund), (iv) AVVISA/KARANTÄN. | Modellen antog tyst att SoR alltid finns och att Hubs alltid får röra informationen (A1, A4) — båda bryts. `sorFallback` gör destinationen datadriven. | `[BYGGS]` |
| **K-1.8** | Retention-flippen (R8 aktiv→gallras) SKA **aldrig ske** utan att ett av de fyra utfallen är explicit registrerat OCH (för commit-fallen) en **verifierad facksystem-callback** mottagits. | Löser GAP-007 (gallring bunden till verifierad commit, aldrig kryssruta). | `[BYGGS]` |
| **K-1.9** | Den **enda legitima SoR-roll** Hubs får ta SKA vara det smala **audit-/provenans-spåret** (handover/eskaleringskvittens skedde korrekt — "skickat" ≠ "mottaget") — aldrig verksamhetsdata. | Två genuint nya smala SoR-fall (F42, F45). `postCommitHook='handover_kvittens'`. | `[BYGGS]` |
| **K-1.10** | **Säkerhetsskyddsklassificerat material SKA avvisas/karantänsättas** — får inte ligga i Hubs alls (terminerande utfall, ej snävare ACL). Skild från `skyddade_personuppgifter` (= snävare ACL *inom* systemet). | Säkerhetsskyddslagen (2018:585) + förordning (2021:955): att behandla säkerhetsskyddsklassat som skyddade PU är **lagbrott**. Den enda hårda gräns som inverterar mellanlager-principen. | `[BESLUT KRÄVS]`→`[BYGGS]` |
| **K-1.11** | **Visselblåsning (lag 2021:890) SKA isoleras och får ej passera sagan** — separat restricted SoR utanför Hubs domän (Lantero/WhistleB), tvingande opt-out ur `ArendeMatchService`, `ConsolidateMailboxesService` och alla aggregat. Integration mot visselblåsarsystem ska medvetet **utebli**. | Matchningsmotorn (case-tagg→conversationId→SSN) är direkt oförenlig med identitetsskyddet. | `[KONFIG]` + `[EXTERN]` |

### 1.3 De 12 rollerna + personas

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-1.12** | Hubs SKA stödja **12 roller** som datadrivna config-profiler ovanpå oförändrad ryggrad (R1–R9): **1.** Registrator/Nämndsekreterare · **2.** Kommunsjuksköterska/HSL · **3.** HR/Chef · **4.** Överförmyndarhandläggare · **5.** IT/Informationssäkerhet/Dataskydd · **6.** Skola/Förskola/Elevhälsa/Rektor · **7.** Omsorgsutförare/Enhetschef · **8.** Bygglov/Plan/Miljö/Livsmedel · **9.** Upphandling/Inköp/Ekonomi · **10.** Säkerhetssamordnare/Beredskap/Räddningstjänst · **11.** Kommunjurist/Visselblåsarfunktion · **12.** Medborgarservice/Kontaktcenter/Växel. | Verifierat: ingen roll motiverar eget kodflöde; skillnaderna är data. 0/12 är ren config rakt av (config-vokabulär utökas, 7 nya fält). | `[BYGGS]` |
| **K-1.13** | **Primär byggpersona** SKA vara `socialsekreterare` (barn & familj, SoL 2025:400, BBIC) — referensimplementationen mot vilken motor, UI och Treserva-konnektorn först byggs. Övriga 11 = config-profiler + konnektorfamiljer. | All UX/UI-spec + GAP-019 skrivna mot socialsekreteraren; e-diarium = andra instansen som bevisar (modul × produkt)-mappningen. | UI `[FINNS]`, motor `[BYGGS]` |
| **K-1.14** | Rollerna SKA klassas på **SoR-typ (a–e)** som driver integrationsmönstret: (a) tydlig enkel SoR · (b) fragmenterad · (c) inget bra SoR · (d) triage-only · (e) separat restricted. Klassningen styr `commitDestination`, fallback och om matchningsmotorn körs. | SoR-typen, inte rollnamnet, avgör arkitekturkraven (roll 12 = (d); roll 5 = (c) hårdast; roll 4 = (a) men e-tjänst-inflöde går förbi Hubs). | `[BYGGS]` |
| **K-1.15** | Hubs SKA hantera **cross-role-routing som mönster**: ett `hubsCaseId` kan ha `primarRoll` + `medmottagare[]` (eskalering/parallell flermottagare/transit). Medmottagares akter SKA **aldrig slås ihop** — varje får egen ACL-krets + egen commit. | Tre arketyper återkommer i 9+ roller. Sammanslagning över sekretessgränser = inbyggd incident. | `[BYGGS]` |
| **K-1.16** | Behörighet SKA skala via **Circles + `aclProfil`-bibliotek** (≥8 profiler). ACL-default SKA vara **per-roll konfigurerbar**, ej hårdkodad deny-by-default. | A2 bryts: för roll 8/11/12 är offentlighet norm. `skyddade_personuppgifter` överstyr alla profiler hårdare. | `[KONFIG]` + `[BYGGS]` |

### 1.4 Modul-paketering (M0–M4)

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-1.17** | Hubs SKA paketeras som **fem moduler i kärna-plus-tillägg-modell**: **M0** plattformskärna (obligatorisk, osynlig — `sdkmc-core`) · **M1** Meddelanden (ankare) · **M2** Video & Chat · **M3** Filer · **M4** Verksamhet (motor + valbara konnektorer). | M4 lagrar ingen verksamhetsdata. Kärna-plus-tillägg är kod-tvingad. | `[BYGGS]` |
| **K-1.18** | Dagens `sdkmc`-monolit SKA **brytas i M0 (sdkmc-core) + sdkmc-msg (M1) FÖRE M4 byggs**. Taggmotorn flyttar till M0. Kod-refaktor, **inte** data-migration. | Annars är "sälj M4 separat" en lögn. | `[BESLUT KRÄVS]`→`[BYGGS]` |
| **K-1.19** | Minsta säljbara enheter: M1 = M0+M1 · **M4 = M0+M1+motor** (M2/M3/Kontakter valbara). Beroenden mot M2/M3/Kontakter SKA vara **mjuka (graceful)**. | M4:s kärnlöfte kräver bara M0+M1. | `[BYGGS]` |
| **K-1.20** | Modul-/licensgränsen SKA dras vid **processgränsen**. M4-motorn SKA designas **ExApp-rent** (inga `oc_*`-FK:er, opaka pekare, all skrivning via service-lager). | "Processgränsen ÄR licensgränsen." Rättsfråga — inget proprietärt beslut utan IP-jurist. | `[BYGGS]` |

### 1.5 Bärande designprinciper

| KRAV | Krav | Status |
|---|---|---|
| **K-1.21** | **Ärende-först.** Meningsbärande axel = ärendekoppling (`nytt · hör-till · ej-kopplat`), inte korg. | `[FINNS]` (UI) / `[BYGGS]` |
| **K-1.22** | **All chatt lever i Spreed (M2)** — flik på ärendekortet + räknare i pulsen, aldrig en andra inkorg. `talkToken`-pekare i registret. | `[BYGGS]` (M2 `[FINNS]`) |
| **K-1.23** | **M4 lagrar ingen verksamhetsdata** — renderar aggregat, skickar kommandon. NC-objekten är vyer/projektioner. | `[BYGGS]` |
| **K-1.24** | **Flow vs programmatiskt hålls isär:** core-Flow = fil-/tagg-centrisk, deklarativ. Icke-fil-objekt + fler-objekts-orkestrering = programmatiskt. | `[KONFIG]` + `[BYGGS]` |
| **K-1.25** | **commitDestination-invarianten är en bärande designprincip:** ingen rad lever utan destination, ingen gallras utan registrerat utfall + verifierad callback, "Ej ärendekopplat" har registrerings-/gallringsgrind. | `[BYGGS]` |
| **K-1.26** | `ArendeTyp`-raden SKA **utökas med 7 strukturella fält** (data, ej kontrollflöde). Sex datafält; ett (`karantanKravs`) rör motorn. | `[BYGGS]` |
| **K-1.27** | Sagan SKA få **terminerande "föds inte"-utfall** (`avvisad`/karantän) som led -1, FÖRE R1, med abort-semantik + **retroaktiv** karantän. Enda genuina motor-utökningen. | `[BYGGS]` (hög risk) |

---

## 2. MODUL-, SYSTEM-, LICENS- & DATALAGERARKITEKTUR

> **Områdeskod:** `ARK`. Djupdok: `MODULARISERING-LICENS-DATALAGER.md`. Två bärande invarianter genomsyrar: (1) Hubs är ALDRIG SoR; (2) processgränsen ÄR licensgränsen.

### 2.1 Modul- & systemarkitektur

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-2.1** | Hubs SKA paketeras enligt **kärna-plus-tillägg** (ej fyra likvärdiga block): obligatorisk osynlig **M0** + fyra säljbara SKU:er M1 (ankare)/M2/M3/M4. | `sdkmc` är redan delat plattformslager (egna DB-tabeller, taggmotor) och utpekat hem för ärende-motorn. M4 lagrar ingen egen verksamhetsdata. | `[BYGGS]` |
| **K-2.2** | Dagens `sdkmc`-monolit SKA brytas i **M0 (`sdkmc-core`/namespace `sdkmc_core`)** + **`sdkmc-msg` (M1)** **FÖRE** M4 byggs. M0 äger: `case:{id}`-taggmotorn, `sdkmc_arende`-registret, `ArendeService` (saga), `AppDetectionService`-kontraktet, OCS-aggregat. `sdkmc-msg` behåller: `MessageTypeService`, retention-stacken, korg-provisioning, trådning/kvittenser, DIGG-synk. | **Kod-refaktor, inte data-migration** (`sdkmc_itsl_*`-tabellerna ligger kvar, ägandet flyttar). Görs det ej först cementeras monoliten. | `[BESLUT KRÄVS]`/`[BYGGS]` |
| **K-2.3** | Taggmotorn (`ItslTagService` + `Db/ItslTag`, `case:{id}`-token) SKA flyttas till **M0**. M0 SKA vara **enda** stället som äger `case:`-token. | Join-mekanismen mellan alla moduler, inte mail-logik. Verifierat: `ItslTagMapper extends QBMapper` mot `sdkmc_itsl_tag`. | `[FINNS]`→`[BYGGS]` (flytt) |
| **K-2.4** | Beroenderiktning: **allt → M0 = HÅRD**; **M1 → M0 = HÅRD**; **M4 → M1/M2/M3/Kontakter = MJUK (graceful)**; **M2, M3 = inga hårda beroenden**. M4:s minsta säljbara enhet = **M0+M1+M4**. | Definierar systemgränser och vad som får säljas separat. | `[BYGGS]` |
| **K-2.5** | M4 SKA degradera **per saknad granne** via saga-steg-skip utan att invarianten bryts: saknas M1→tom triage men ärendevy lever; M2→chatt/`talkToken`-steg hoppas; M3→ärenderum (Groupfolder) skapas ej; Kontakter→`FavoritValjare`/motpartsresolver tom. | Stegvis degraderbar saga R1–R9 = fundamentet för "sälj separat". | `[BYGGS]` |
| **K-2.6** | `AppDetectionService` SKA **utökas från kanal- till funktions-detektering** — lägg `calendar`, `groupfolders`, `files`, `contacts` och låt saga-motorn fråga den per steg. | Verifierat: idag `HUBS_APPS = ['sdkmc','mail','spreed','calendar']`, `CHANNEL_BY_APP` täcker bara `sdkmc`/`mail`. Hela fundamentet för graceful degradation. | `[FINNS]`→`[BYGGS]` |
| **K-2.7** | Kontakter SKA konsumeras **som-den-är** via tunt sdkmc-resolverlager — **ingen fork**. Mjukt M4-beroende, inte egen SKU. | Contacts v8.3.12 nativt CardDAV/vCard + resolver räcker; favorit = pekare (`X-HUBS-SDK-REF`), ej kopia. | `[FINNS]` (resolver `[BYGGS]`) |

### 2.2 Licensarkitektur (AGPL-positionen)

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-2.8** | Arkitekturen SKA placera **processgränsen som licensgräns**: in-process PHP mot OCP = combined work → AGPL; egen process/DB + app-nivå-HTTP = separate program → kan vara proprietärt. | AGPLv3 copyleft-trigger = "combined work"; §13-nätverkscopyleft utlöses bara *om man modifierar*. FSF: pipes/sockets/HTTP = "normally separate programs". | `[BYGGS]` |
| **K-2.9** | Följande SKA vara/förbli **AGPL** (ingen valfrihet): bro-appen i NC (`hubs_start`-skalet); all OCP-användande PHP; `sdkmc` så länge in-process; forkade appar **`mail`** (`AGPL-3.0-only`, verifierat) och **`spreed-itsl`** (`AGPL-3.0-or-later`, verifierat). | Verifierat: `hubs_start/appinfo/info.xml` `licence=agpl`, NC 30–32. Forkar ärver AGPL via upphovsrätten oavsett distributionskanal. | `[FINNS]` |
| **K-2.10** | Följande KAN vara **proprietärt** — villkorat, endast efter juristverifiering: verksamhetslogiken (M4) som **ExApp-tjänst** (egen process+DB, app-nivå-API, minimalt/AGPL ExApp-skal, ej app store); frontend-SPA endast om den talar **rå OCS/REST utan `@nextcloud/*`-bundling**; per-produkt-facksystemkonnektorer; ärenderegister-datalagret i ExApp-DB. | Affärsvärdet: M4-motorn + konnektorer = separat prissatta artefakter (5–10× GAP-019). Förutsätter privat distribution + arm's-length. | `[BESLUT KRÄVS]`/`[BYGGS]` |
| **K-2.11** | Publiceras något på **apps.nextcloud.com** SKA det vara AGPL-3.0-or-later/kompatibelt. Proprietär M4 FÅR endast distribueras **privat** — privat distribution tar bort store-politiken men **inte** copyleft-mekaniken för combined work. | App store-regeln är explicit. Att kringgå store löser inte upphovsrätten för in-process-kod. | `[EXTERN]` (NC-policy) |
| **K-2.12** | **Inget proprietärt-licens-beslut FÅR fattas utan IP-/upphovsrättsjurist** som verifierar: (i) ExApp-gränssnittet är "arm's length"; (ii) inga AGPL-bibliotek bundlas proprietärt; (iii) distributionsmodellen; (iv) §13-skyldigheterna för driftande part; (v) `mail` `-only` vs `spreed` `-or-later` (`-only` kan ej uppgraderas till framtida AGPLv4); (vi) securemails egna licens (separat Node/Express-container). | "Combined work" vs "separate program" är ytterst en rättsfråga. Hela proprietär-tesen står och faller på juridisk verifiering. | `[EXTERN]` (jurist) |

### 2.3 Internt datalager (ärenderegistrets hem)

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-2.13** | **Nextcloud Tables SKA underkännas** som motor-backend. Detta stänger den öppna designfrågan i `HUBS-INTERNALS §1.2.4` till förmån för proper relationell DB. | Tables bryter mot **varje** saga-krav: ingen single-writer (rader användar-redigerbara → sekretess-haveri), ingen transaktion/rollback, svaga constraints/FK/unique, ingen schema-/migrationskontroll, dålig volym-prestanda (EAV), inget rad-lås (GAP-057 kan ej stängas). | `[BESLUT KRÄVS]` (vänder tidigare design) |
| **K-2.14** | Ärenderegistret SKA implementeras som **proper NC-app-DB i M0/sdkmc** (alt. b) — `Migration` + `QBMapper` + `IDBConnection`, single-writer via service-lager. Tabeller: `sdkmc_arende`, `sdkmc_arende_typ`, `sdkmc_arende_receipt`. | Exakt mönstret sdkmc **redan kör** (`ItslTagMapper extends QBMapper`, `Types::JSON`, `addUniqueIndex`, soft-delete, `getOrCreate`-idempotens). Noll ny infrastruktur. | `[BYGGS]` (mönster `[FINNS]`) |
| **K-2.15** | Schemat SKA designas **ExApp-rent från dag ett**: (a) **inga FK:er ut mot `oc_*`** — alla NC-pekare (`deckCardId`, `talkToken`, `groupfolderId`, `caseTagId`, `calendarObjUri`) som **opaka strängar/int**; (b) **all skrivning genom ETT service-lager** (`ArendeService` = single writer), aldrig Mapper direkt från controllers. | Gör senare lyft (b)→(c) till utbytt persistens-adapter + data-migrering, inte omskrivning. | `[BYGGS]` |
| **K-2.16** | Fält-allokeringen SKA följa enradsregeln *"det användaren rör → NC; det motorn räknar och pekar med → intern DB"*. **BEHÅLL I NC:** ärendekort (Deck), ärenderum/dokument (Files/Groupfolders, ACL=OSL-gräns), ärende-chatt (Spreed), kontakter/favoriter, `case:{id}`-systemtaggen. **FLYTTA TILL INTERN DB:** registret `hubsCaseId↔dnr↔pekare`, routing-/matchnings-state, `ArendeTyp`-config, frist-spegling, kvittens-/audit-logg (append-only), idempotens-/lås-nycklar. | Skiljer projektion från sanning. NC-objekten blir vyer. | `[BYGGS]` |
| **K-2.17** | NC-objekten SKA vara **projektioner/vyer**, aldrig SoR för ärendet. DB-raden bär objektets id (opak pekare); NC-objektet bär `case:{id}`-tagg. `ArendeReconciliationJob` (GAP-056) SKA köras som `BackgroundJob` mot proper DB (mönster: `UpdateAddressBookBackgroundJob`) med indexerad SELECT + constraints som fångar dinglande pekare. Favorit-vCard taggas **ALDRIG** `hubsCaseId`. | Garanterar register som single point of truth; Deck/Files/Spreed kan bytas/saknas utan att ärendet förloras. | `[BYGGS]` |

### 2.4 Never-SoR-gränsen + de tre licensgränserna

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-2.18** | Intern DB SKA hålla **endast koordinations-state** (pekare + koordination), aldrig verksamhetsinnehåll. Att byta Tables→intern DB ändrar **inte** SoR-status. | "Intern DB = kartan, facksystemet = territoriet." | `[BYGGS]` |
| **K-2.19** | `commitDestination` SKA hävdas som **NOT NULL-constraint** på varje rad i `sdkmc_arende_typ`. | Utan icke-null blir Hubs tyst de-facto-SoR. Proper DB *stärker* invarianten (Tables kunde aldrig garantera den på schema-nivå). | `[BYGGS]` |
| **K-2.20** | Tre licensgränser SKA vara utritade i dataflödet: (i) **NC-processgränsen** = AGPL-zonen; (ii) **HTTP/AppAPI-gränsen** = potentiell licensgräns (M4-ExApp kan vara proprietär; trafiken bär verksamhetsbegrepp i JSON — ingen intern-RPC); (iii) **facksystemgränsen** = SoR-gränsen (commitDestination-invarianten). | Dataflöde skapa-ärende: M1-inflöde → `case:`-tagg (M0) → M0-bron vidarebefordrar app-event över HTTP → M4 skapar DB-rad + saga → opaka pekare, NC-objekt bär `case:`-tagg → commit via konnektor → append-only kvittens. De tre gränserna sammanfaller med modul-/licens-/SoR-gränsen. | `[BYGGS]` |

**Migrationsväg (referens):** *Steg 0→1:* in-memory `REGISTER`-Map i `services/demo/treserva.js` → `Version02xxxxCreateArendeTable.php` (`sdkmc_arende`+`_typ`+`_receipt`; `Types::JSON`; `addUniqueIndex(['hubs_case_id'])`; `NOT NULL` på `commit_destination`) → `lib/Db/Arende.php`+`ArendeMapper.php` (kopiera `ItslTag`-mönstret) → `ArendeService` (single writer + saga + kompensering) → `ArendeController` + routes → peka om `treserva.js` från in-memory till OCS. *Steg 1→ev. 2:* ExApp-ren design = stå upp ExApp-container + egen DB → `ArendeService` byter persistens-adapter → NC-sidan anropar över AppAPI. **NC-projektionerna ändras inte.**

---

## 3. ÄRENDE-MOTORN (register, saga, hålla-ihop, ArendeTyp)

> **Områdeskod:** `MOTOR`. Djupdok: `HUBS-INTERNALS-ARENDEMOTOR.md`, `ARENDETYPER-FLODESANALYS.md`. **Nuläge:** taggmotor, kanal-/typklassning, trådning, kvittenser, retention, Flow-reg **FINNS** i sdkmc; **hela ärende-motorn BYGGS** (lever idag som in-memory `REGISTER`-Map i `treserva.js`).

### 3.1 Identitet — `hubsCaseId` och identitets-trippeln

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-3.1** | `hubsCaseId` (UUID v4) SKA vara **enda joinnyckeln** för hela stacken. Mintas av motorn (saga R1), PK i registret, lever **utan** dnr, bärs av varje objekt. | En token → "öppna ärende" = O(1) i stället för fan-out över sju appar. | `[BYGGS]` |
| **K-3.2** | Identitets-trippeln SKA hållas isär: **`conversationId`** (föds vid inflöde, ägs av sdkmc, provenans-ankare + 14-dgr-klockans start — finns som IMAP `Message-Id`/`MessageThread`), **`hubsCaseId`** (föds vid skapande), **`dnr`** (föds vid facksystem-registrering). `hubsCaseId ↔ dnr` är **1:n** (syskon-fall: ett `hubsCaseId` per barn som delar `conversationId`). | Frikopplingen tillåter att ärendet arbetas innan dnr finns; syskonmodellen kräver `conversationIds[]` på varje rad. | `[FINNS]` (conversationId)/`[BYGGS]` |

### 3.2 Registret — placering, rad-shape, single writer

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-3.3** | Registret SKA implementeras som **proper relationell app-DB via QBMapper/Migration/`ISchemaWrapper`**, inte Tables. Tables underkänns (se K-2.13). | Mönstret finns att kopiera: `ItslTagMapper extends QBMapper`, `Types::JSON`, `addUniqueIndex`, soft-delete, `getOrCreate`. | `[BESLUT KRÄVS]` → ja |
| **K-3.4** | Placering SKA **starta i (b) sdkmc:s NC-app-DB** men schemat **ExApp-rent** så lyft till (c) ExApp-DB blir data-/adapter-migrering. ExApp-rent = inga `oc_*`-FK + all skrivning via ETT service-lager. | (c) är målbild om licens/modul kräver proprietär M4. Tidpunkt beror på affärs-/licensbeslut. | `[BESLUT KRÄVS]` (start b, mål c) |
| **K-3.5** | Registret SKA bära rad-shapen: `hubsCaseId` (PK), `triageRef`, `barnRef`/`objektRef` (pseudonym, **aldrig** klartext-PII), `enhet`, `agareUid`, `status`, `steg`, `dnr`, `provenanceState`, `conversationIds[]`, two-way-pekarna (`groupfolderId`/`deckBoardId`/`deckCardId`/`talkToken`/`calendarObjUri`/`caseTagId`), `retentionState`, `fristDue` (speglad ur facksystemet, **ej självständigt räknad**), `flaggor[]`, `skapad`. | Pekarna gör NC-objekten till projektioner. `fristDue` får aldrig vara lokalt räknad sanning (GAP-018). | `[BYGGS]` |
| **K-3.6** | Registret SKA skrivas **uteslutande av motorn** (`ArendeService`), aldrig av handläggaren rått eller `hubs_start`. Controllers rör **aldrig** Mapper direkt. | Samma single-writer-disciplin som taggmotorn. Single-writer-lagret gör (b)→(c)-lyften till utbytt persistens-adapter. | `[BYGGS]` |
| **K-3.7** | Pekar-/koordinations-state, routing-/matchnings-state, `ArendeTyp`-config, frist-spegling, kvittens-/audit-logg (append-only) och idempotens-/lås-nycklar SKA bo i intern DB. **Användar-vänd data behålls i NC.** Enradsregeln: *det användaren rör → NC; det motorn räknar → intern DB.* | Bevarar NC-objektens nytta + ger motor-mekaniken transaktionsgaranti. `case:`-taggen bor där objektet bor. | `[BYGGS]` |

### 3.3 Skapa-sagan R1–R9 (atomär, med kompensering)

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-3.8** | "Skapa ärende" SKA implementeras som **distribuerad saga**, inte DB-transaktion. Stegen spänner över olika persistenslager — **ingen `IDBConnection`-transaktion kan rulla tillbaka ett Spreed-rum eller en mapp**. | Exakt innebörden av GAP-057. | `[BYGGS]` |
| **K-3.9** | Sagan SKA köra leden: mint → INSERT register-rad → systemtag `case:{id}` → groupfolder+ACL+Automated-Tagging-regel → Deck-kort → Spreed-rum → kalender → 14-dgr-klocka bunden till **inkom-datum** → tagga utlösande meddelande via befintliga `ItslTagService->tagMessage()` → UPDATE registret med alla pekare (commit-punkt). | Återanvänder maximalt det som FINNS (meddelandetaggning, `TagFileController`, native Deck/Spreed/groupfolders/CalDAV). | `[BYGGS]` (R9 tagg-led `[FINNS]`) |
| **K-3.10** | **Varje forward-steg SKA ha kompenserande motåtgärd**; misslyckas steg *n* körs kompensering *n-1…1* i omvänd ordning. Skrivs och verifieras **en gång** (en motor, inte åtta). | Allt-eller-inget: halvskapade ärenden = sekretess- och konsistensrisk. | `[BYGGS]` |
| **K-3.11** | Sagan SKA bära **idempotensnyckel** (utlösande `conversationId`) så dubbelklick/retry ej föder två ärenden. Idempotens + rad-lås på `hubsCaseId` krävs även vid fördelning (stänger GAP-057-fönstret). | Mönstret finns: `findUniqueImapLabel`/`getOrCreate`. | `[BYGGS]` |
| **K-3.12** | Varje grannberoende saga-steg SKA **hoppas (graceful)** om grannmodulen saknas, utan att invarianten bryts. M4:s kärnlöfte kräver bara **M0+M1**. | Bygger på verifierade `AppDetectionService`-mönstret; måste utökas till funktions-detektering. | `[FINNS]`/`[BYGGS]` |
| **K-3.13** | **dnr-parningen SKA INTE vara del av skapa-sagan.** Den sker senare/asynkront: vid "För över" anropas facksystem-commit; **först vid verifierad callback** `{hubsCaseId, dnr}` skrivs `dnr` + `provenanceState='registrerad'` + dnr-alias-tagg + speglad `fristDue` + `retentionState='gallras_efter_commit'`. | Medvetet frikopplat. Retention aldrig bunden till tid/kryssruta (GAP-007). | `[BYGGS]` |

### 3.4 Hålla ihop — `case:`-propagering, two-way-pekare, reconciliation

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-3.14** | `case:{id}` SKA propageras med **två bärartyper**: tagg på taggbara objekt (meddelande via `ItslTagService`; fil via `TagFileController`/`ISystemTagManager`; löpande via Flow Automated Tagging) och **register-pekare för icke-taggbara** (Deck-kort, Talk-rum, kalender). | Deck-kort/Talk-rum kan inte fil-taggas → pekas ur registret. | `[FINNS]` (taggbärare)/`[BYGGS]` (pekare) |
| **K-3.15** | `case:`-taggen SKA vara **email-/funktionsadress-scopad, inte user-scopad**, så taggen delas av alla handläggare med korgen. Trådsammanslagning gör en tagg på *ett* meddelande synlig på *hela* tråden. | Verifierat: `ItslTag extends Mail\Tag` byter `userId` mot `emailAddress`; `tagMessage()` dubbelskriver (IMAP-flagga + DB-spegling) + dispatchar `MessageFlaggedEvent`. | `[FINNS]` |
| **K-3.16** | **Flow vs programmatiskt** SKA följa tumregeln: Flow = fil-/tagg-centriskt/deklarativt; icke-fil-objekt + flerobjekts-orkestrering = programmatiskt. Flow gör auto-fil-taggning, retention-tagg vid commit, File Access Control, Talk-systemmeddelanden. Sagan, meddelandetaggning, fördelnings-ACL, facksystem-commit = programmatiska. | Flow-registreringen FINNS men smal (`RegisterOperationsListener` laddar bara `loa3`). | `[FINNS]`/`[KONFIG]` |
| **K-3.17** | **Reconciliation-loop** (GAP-056) SKA periodiskt verifiera: (1) varje pekare existerar, (2) varje `case:X`-objekt har registerrad, (3) `conversationIds[]` matchar de meddelanden som bär `case:X`. Placering: `lib/BackgroundJob/ArendeReconciliationJob.php`. | Token bärs på två ställen → kan driva isär. Mönster: `ItslTagService->processAllPendingDeletions()` driven av `DeleteTagsJob`. | `[BYGGS]` |

### 3.5 ArendeTyp-registryn — en motor parameteriserad per typ

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-3.18** | Det SKA vara **EN generisk motor / EN saga**, parameteriserad av **datadriven `ArendeTyp`-registry** + ortogonala flaggor — inte åtta kodflöden, inte ett odifferentierat flöde. Formel: *en motor · en saga · N config-rader · ~3 hooks · M flaggor.* Ny typ = ny config-rad, inte ny PHP. | Skillnaderna mellan 8 kategorierna är data, inte kontrollflöde. Symmetriskt med `MessageTypeService` (deterministisk tabell-mappning). | `[BYGGS]` |
| **K-3.19** | `ArendeTyp`-raden (`sdkmc_arende_typ`) SKA bära: `arendeTypId` (PK), `displayName`, `kanalHint[]`, `defaultEnhet`/`funktionsadress`, `forstaAtgard`+`forstaAtgardMall`+`pliktGrind`, `kopplingDefault`, `fristPolicy{typ,ankare,speglasUrTreserva}`, `aclProfil`+`sekretessNiva`, `diariePlikt`, `dhpHandlingstyp`+`retentionMall`, `frendsModul`+`frendsMappning`, `preSagaHook`/`postCommitHook`, `partsModell`. Seed: 8 rader. | Registry-raden *är* dokumentationen av handläggningsregeln — reviderbar, versionerbar, granskningsbar av kommunjurist. | `[BYGGS]` |
| **K-3.20** | Två kategorier SKA realiseras via **deklarerade hooks** (samma motor, ingen fork): **Kat 6 (LVU/LVM)** vänder ordningen → `preSagaHook='diariefor_direkt'` (led 0 före register-INSERT) + `diariePlikt='direkt'` + `fristPolicy.typ='domstol'`. **Kat 8 (familjerätt)** → `partsModell='flerpartsärende'` + `aclProfil='familjeratt_inre_sekretess'` + `postCommitHook='familjeratt_yttrande'`. | Två kategorier böjer hypotesen men bryter den inte — sagans kompensering oförändrad. | `[BYGGS]` |
| **K-3.21** | Cross-cutting-flaggor (`akut_fara`, `barn_berörs`, `skyddade_personuppgifter`, `våld_hot`, `frist_kritisk`) SKA ligga på **ärendeinstansen (`flaggor[]`), inte typen**, beräknas parallellt/oberoende, bäras som `imap_label`-taggar (`flag:akut_fara`). **Dubbelmärkning** får aldrig tvinga ett kategorival. `skyddade_personuppgifter` SKA **överstyra** ACL hårdare oavsett kategori. | "Akut" i typen ger kombinatorisk explosion. Ortogonaliteten bevisar att kategori är en etikett-axel. | `[BYGGS]` |

### 3.6 Facksystem-commit + terminerande "föds inte"-utfall

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-3.22** | `TreservaCommitService` SKA generaliseras till **`FacksystemCommitService`** med **per-produkt-konnektorer**; `ArendeTyp.frendsModul` väljer flöde, `frendsMappning` väljer payload-schema. Mönster: `commitToFacksystem({hubsCaseId, arendetyp, payload})` → **verifierad callback** → provenans-flip + dnr-paring + retention. | Per-produkt-konnektorer = 5–10× GAP-019, de separat prissatta/licensierbara artefakterna. | `[BYGGS]`/`[EXTERN]` |
| **K-3.23** | **`commitDestination`-invarianten SKA hävdas som NOT NULL-constraint** i `sdkmc_arende_typ`. | Utan icke-null destination blir Hubs tyst de-facto-SoR. Proper DB stärker invarianten. | `[BESLUT KRÄVS]` → NOT NULL |
| **K-3.24** | Motorn SKA stödja **terminerande "föds inte"-utfall**: vissa inflöden (säkerhetsskydd, visselblåsning) dirigeras via `commitDestination='triage_forward'`/`'karantan'`/`'extern_myndighet'`. Sagan terminerar före register-INSERT; inflödet loggas som hanterat utan ärende-objekt eller `case:`-tagg i fel akt. | Felkoppling = sekretessincident, ej UX-fel. | `[BYGGS]` |
| **K-3.25** | Retention SKA sättas **enbart** av verifierad facksystem-callback (`retentionState='gallras_efter_commit'`), aldrig av tid/kryssruta. Retention-paus vid utlämnandebegäran (TF) SKA ha faktisk hook (idag enum-värde utan trigger, GAP-031). | Hubs-kopian gallras på tid efter commit; originalet bevaras i facksystemet → e-arkiv. | `[FINNS]` (retention-motor)/`[BYGGS]` (bindning) |

### 3.7 Kodplacering (handover-karta)

| KRAV | Krav | Status |
|---|---|---|
| **K-3.26** | Motorns klasser SKA placeras i M0/sdkmc-core: `lib/Service/ArendeService.php` (single writer + saga + kompensering + idempotens), `ArendeMatchService.php` (inkommande → ärende, kaskad case-tagg→conversationId→SSN + server-side konfidenströskel), `FacksystemCommitService.php`, `lib/Db/Arende.php`+`ArendeMapper.php`, `lib/Controller/ArendeController.php`, `lib/BackgroundJob/ArendeReconciliationJob.php`, migration `Version02xxxxCreateArendeTable.php`. | `[BYGGS]` |
| **K-3.27** | OCS-routes SKA registreras på **verifierade `/api/v2/`-konventionen**: `POST /api/v2/arende`, `GET /api/v2/arende/{hubsCaseId\|dnr}`, `GET /api/v2/arende-summary`, `POST /api/v2/arende/{ref}/tilldela`, `POST /api/v2/treserva/commit`. Designdokens `/api/v1/`-prefix är felaktig. `hubs_start` ska anropa dessa (idag 27 OCS-anrop i `api.js` mot routes som ännu inte finns). | `[BYGGS]` |
| **K-3.28** | **sdkmc SKA brytas i M0 + sdkmc-msg FÖRE M4-motorn byggs.** Taggmotorn flyttar till M0 — kod-refaktor, **inte** data-migration. | `[BESLUT KRÄVS]` |

---

## 4. KATEGORISERING, KLASSIFICERING & ROUTING

> **Områdeskod:** `KLASS`. Djupdok: `ARENDETYPER-FLODESANALYS.md`, `HUBS-INTERNALS §2`, `KOMMUNROLLER §2`. Kärnprincip: **klassning som rör vart sekretess routas är aldrig autonom** — deterministisk regelkaskad + konfidens först, LLM endast som människo-bekräftat förslagslager, fail-closed mot människa. Felklassning = sekretessincident.

### 4.1 De tre ortogonala axlarna (grupperingsmotorn)

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-4.1** | Triagen SKA klassa varje rad längs **tre oberoende axlar** i en server-side-passage: **A KORG** (behörighet), **B KANALTYP** (5 typer), **C ÄRENDEKOPPLING** (nytt/hör_till/ej_kopplat). Ingen axel skriver över en annan → triage-shape `{korg, kanalTyp, primärKat, flaggor[], arendekoppling, konfidens}`. | Att blanda axlarna ger "13 inkorgar"; isärhållna ger ärende-först-vy. | `[FINNS]` (A+B)/`[BYGGS]` (C) |
| **K-4.2** | **Axel B (kanaltyp) SKA förbli helt deterministisk, ingen LLM.** `MessageTypeService::getMessageTypeFromEmail()` mappar adress-suffix → 5 kanaltyper (`sdk_message`, `fax_message`, `sms_message`, `internal_message`, `secure_email`) + IMAP-headers `X-Sdk`/`X-MessageType`. Okänt suffix → `throw Exception` (**fail-closed**). | Symmetri-ankaret: innehållsklassningen byggs ovanpå en bevisat deterministisk botten. | `[FINNS]` |
| **K-4.3** | **Axel C (ärendekoppling) SKA byggas** som `ArendeMatchService`, server-side på `MessageReceivedEvent`, sätter `arendekoppling ∈ {nytt, hör_till, ej_kopplat}` som styr band via `MinaArenden.vue zonOf()` (`hör_till`→1b, `ej_kopplat`→1c, annars→1a). UI-banden ändras INTE. | Korg+typ finns; ärendekopplingen är axeln motorn saknar. Mall: `MessageImportantClassifiedListener`. | `[BYGGS]` |
| **K-4.4** | Axel A (korg) SKA förbli **filter + etikett, aldrig primär sortering**, server-filtrerad till behöriga korgar (OSL-gränsen) via `ConsolidateMailboxesService`/`ProvisionPersonligAccountsService`. | Korg = behörighetsstyrd ström, inte mapp; sortering på korg återskapar silos. | `[FINNS]` |

### 4.2 Innehållsklassificeringen — `InnehallsKlassService`

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-4.5** | En ny **`InnehallsKlassService`** SKA producera `{primärKat 1–8, konfidens, flaggor[]}`, anropad från `lib/Listener/MessageReceivedListener.php` på `MessageReceivedEvent`, efter mallen `MessageImportantClassifiedListener::handle()`. Resultatet bärs som `kat:{n}`-tagg via **befintliga** `ItslTagService->tagMessageWithMetadata()`. | Klassningslagret ovanpå de 5 kanaltyperna; mönstret "event→listener→server-side-tagg" är etablerat. | `[BYGGS]` |
| **K-4.6** | Klassningen SKA följa en **fallande deterministisk signalkaskad som stannar på första säkra träffen**: (a) strukturerade SDK-fält/X-headers (konfidens 1.0) → (b) avsändartyp/org mot konfigurerbart org-register, LOA3-stärkt (`Check/Loa3.php`) → (c) blankett-/formulärtyp → (d) nyckelord/handlingstyp (svagast, **får aldrig ensam** auto-applicera på sekretess) → (e) LLM-förslag (utanför kaskaden). | Innehållsklassning svårare än kanalklassning → måste vara *mer* försiktig. Org-typ = stark prior, inte facit. | `[BYGGS]`/`[KONFIG]` (org-register) |
| **K-4.7** | **LLM SKA vara valfritt, avstängbart, människo-bekräftat förslagslager — aldrig autonomt/skarpt på sekretess** (GAP-052/060). (a) människo-bekräftat alltid när klassningen rör vart sekretess routas; (b) medborgar-PII/ärendetext lämnar **aldrig** huset — ev. modell-assist lokalt på människo-begäran; (c) avvisade/korrigerade förslag loggas i `activity`. | "Skarp drift på sekretessbelagt klientsamtal med AI = röd zon" tills IMY/SKR/Socialstyrelsen gett vägledning. | `[BYGGS]`/`[EXTERN]` |
| **K-4.8** | Klassningströskeln SKA vara **server-side policy (granskad, per kund)**, aldrig klientlogik/demo-konstant (`≥0.9`). Tre utfall: **≥tröskel → `klassad`** (auto-applicerad men redigerbar); **<tröskel m. kandidat → `föreslagen`** (människa bekräftar; bilaga speglas **vid bekräftelse, inte förslag**); **noll/motstridig → `oklassad`** (band 1c, manuell triage). | Default vid otillräcklig signal = fail mot människa. Ärver auto-kopplingströskeln (GAP-060). | `[BYGGS]` |
| **K-4.9** | `InnehallsKlassService` SKA stödja ett **fjärde, terminerande `avvisa/isolera`-utfall**, triggat av `sekretessGrund='sakerhetsskydd'` eller `felmottaget_men_kansligt`. Kör terminerande `preSagaHook` **FÖRE** register-rad mintas + MÅSTE kunna isolera **retroaktivt** (radera ur index/tagg-DB/Groupfolder). | Sagan antar att alla ärenden *skapas* — säkerhetsskyddsklassat får ofta inte ligga i moln-IT alls. | `[BYGGS]` |

### 4.3 Ärendekopplingens matchningskaskad (Axel C-internals)

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-4.10** | `ArendeMatchService` SKA matcha mot `sdkmc_arende` via **fallande deterministisk kaskad med konfidens**: (1) explicit `case:{id}`-tagg (1.0, auto) → (2) dnr i ämne mot `dnr` → (3) `conversationId`-träff i `conversationIds[]` via `MessageThread` (≥tröskel) → (4) avsändar-`joinNyckel` mot ärendepart (svag, <tröskel → `föreslagen`). | Symmetriskt med kanalklassningens determinism. Bilaga speglas aldrig vid förslag. | `[BYGGS]` |
| **K-4.11** | Matchningskaskadens SSN-steg (4) SKA vara **`joinNyckel`-betingat avstängbart**. Objektärenden (`joinNyckel ∈ {objektRef, upphandlingsRef, avtalsRef}`) → steget hoppas. Anonyma (`partsModell='anonym_begaran'`, TF 2:18) → **förbjudet** att efterfråga identitet → steget MÅSTE stängas av. | Person-/`barnRef`-antagandet bryter för objektärenden, uppdrag, anonyma, myndighetssamverkan. | `[BYGGS]` |
| **K-4.12** | Klassningen FÅR **utnyttja korrelationen** mellan axlarna som svag signal (kat 4 & 7 ⇒ oftast `hör_till`; kat 1 & 2 ⇒ oftast `nytt`) men ALDRIG hårdkoda den. Banden ändras aldrig av innehållskategorin. | Axlarna korrelerade men ortogonala — en orosanmälan KAN röra ett barn med pågående ärende. | `[BYGGS]` |

### 4.4 Dubbelmärkning — primär kategori + cross-cutting flaggor

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-4.13** | Varje rad SKA bära **exakt EN primär kategori (1–8)** + ett **SET oberoende cross-cutting flaggor** beräknade parallellt/oberoende. Primärkategorin styr process-mall/routing/SoR; flaggorna är modifierare på **instansen** (`flaggor[]`), aldrig på `ArendeTyp`. | Kategorierna ej ömsesidigt uteslutande. Flaggor på typen → kombinatorisk explosion. | `[BYGGS]` |
| **K-4.14** | Flagg-effekter SKA realiseras: `akut_fara`/`våld_hot`/`frist_kritisk` → prioritets-höjning; `akut_fara` → routing-override (mottagning/jour, hård) + trigga parallell skyddsbedömning; `skyddade_personuppgifter` → behörighets-gate (snävare ACL, deny-by-default, döljs i aggregat per OSL 26 kap.); `barn_berörs` → barnskyddsspår + påverkar facksystem-modul. | Akut slår alltid igenom; skyddade PU måste reflekteras i tre-lagers-koherensen (GAP-058). | `[BYGGS]` |
| **K-4.15** | **Dubbelmärkningen SKA bevisa kategori-som-etikettaxel**: en kat-7-månadsrapport med `akut_fara` förblir primärt kat 7 **och** startar parallell kat-1-skyddsbedömning via flaggan — inte "omklassa till kat 1". I UI = **två chips, inte två rader**. | Starkaste argumentet för att primärkategorin är en process-mall-parameter, inte en exklusiv switch. | `[BYGGS]` |
| **K-4.16** | Primärkategori + varje flagga SKA bäras som egna `imap_label`-taggar (`kat:7`, `flag:akut_fara`), email-/funktionsadress-scopade så klassningen delas av alla med korgen. | Återanvänder den email-scopade single-writer-taggmotorn. | `[FINNS]` (bärare)/`[BYGGS]` (konvention) |

### 4.5 Risk- och säkerhetsaxlar (ortogonala mot ärendetyp)

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-4.17** | Systemet SKA bära en **härledd, explicit `riskKlass`-axel** (`normal\|forhojd\|hog\|kritisk`) ortogonal mot ärendetyp, härledd ur flaggor + `sekretessGrund`. Styr aggregat-synlighet, audit-skärpa, eskaleringsväg — **INTE routing**. | Lågrisk-typ kan bära högrisk-instans. Risk OCH ärendetyp fångas ortogonalt. | `[BYGGS]` |
| **K-4.18** | `verksamhetsgren` + `sekretessMur` SKA modelleras som **mur, inte gradient**: `{delningKraverMenprovning:bool, getsEjBlandasMed:enum[]}`. Klassningen får ALDRIG route:a HSL-innehåll (PDL, OSL 25) in i SoL-akt (OSL 26) eller EMI/HSL-journal ihop med skoldok (OSL 23). | `aclProfil`-gradienten kan inte uttrycka en mur mellan självständiga verksamhetsgrenar — utan fältet är sekretessincidenten inbyggd. | `[BYGGS]` |
| **K-4.19** | `karantanKravs:bool` + `sakerhetsskyddRegim` (`nej\|mojlig\|klassificerad`) SKA styra terminerande negativt saga-utfall: `true` → `preSagaHook='avvisa_sakerhetsskydd'` **FÖRE R2** (vägrar mint:a, vägrar Spreed-rum/Groupfolder, lämnar bara avvisningskvitto); `klassificerad` → får EJ ligga i moln → avvisa. | **Det enda klassnings-/config-fältet som rör motorn, inte bara data.** 9/12 roller flaggar säkerhetsskyddsklassat som hård gräns. | `[BYGGS]` (motor-utökning) |

### 4.6 ArendeTyp-config-fälten — registry som parameteriserar motorn

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-4.20** | `ArendeTyp`-registryn SKA seedas med de **8 baskategorierna** + basfälten per axel a–h (se K-3.19). | En motor att testa, inte åtta; registry-raden *är* dokumentationen av handläggningsregeln. | `[BYGGS]` |
| **K-4.21** | **`commitDestination`-INVARIANTEN:** varje rad MÅSTE ha **icke-null `commitDestination` (≡ `systemOfRecord`)** ∈ `{facksystem, diarium, e_arkiv, extern_myndighet, triage_forward, karantan}`. Retention-flippen (R8) FÅR ALDRIG ske utan att ett SoR-utfall är explicit registrerat. Klassning som lämnar en rad utan commit-mål MÅSTE blockeras vid seed/validering. | Dödligaste felmoden (9/12 roller). Den enskilt viktigaste regeln. | `[BYGGS]` |
| **K-4.22** | **`systemOfRecord`** + **`sorFallback`** (`diarium\|e_arkiv\|ingen_route\|null`) + **`inflodeVia`** (`funktionsadress\|e_tjanst\|muntlig_kontakt`) SKA ersätta antagandet "alltid Treserva". `inflodeVia='e_tjanst'` = informationen går FÖRBI Hubs → får **inte** dubbel-lagra. | Roll 1/8/11 → diarium, roll 12 → CRM, roll 5/9 inget SoR. A1 bröts av 8/12 roller. | `[BYGGS]` |
| **K-4.23** | `sekretessNiva`-skalär SKA ersättas av **`sekretessGrund[]`-struct** `{grund, omfattning:'hel_akt'\|'delfalt', temporal:{utgangsTrigger,utgangsDatum}\|null}` + nivå-enum (`offentlig_tills_menprovad\|normal\|hog\|restricted\|sakerhetsskydd`). **Temporal sekretess:** anbudssekretess (OSL 19:3) flippar vid `utgangsTrigger='tilldelningsbeslut'` → post-commit-hook `slapp_anbudssekretess` **degraderar hela `case:`-klustret** (även Talk-historik + Deck-kort), ACL-flippen läcker **bakåt**. | A2+A3 bröts: temporal sekretess, `restricted` ovanför `hog`, delfältsmaskning, `offentlig_tills_menprovad`. | `[BYGGS]` |
| **K-4.24** | **`externMyndighetMottagare`** (`IMY\|MSB\|IVO\|Lansstyrelsen\|polis\|sakerhetspolis\|tingsratt\|mark_miljodomstol\|forsakringskassan\|null`) + **`commitMode`** (`commit\|referens_lank\|las_konsument\|extern_anmalan`) SKA modellera commit-mål utanför kommunen. `extern_anmalan` → Hubs *förbereder*, människa lämnar in, `retentionState` flippar **inte**. `las_konsument` (Pascal/NPÖ) → Hubs får ALDRIG skriva. | R7-commit antar internt facksystem; IMY 72h / MSB NIS2 / IVO Lex Sarah har externa mål utan maskin-API. | `[BYGGS]`/`[EXTERN]` |
| **K-4.25** | **`partsModell`** SKA utökas (`enskild_klient\|flerpartsärende\|vardnadshavare_godman\|uppdrag\|objektsärende\|anonym_begaran\|myndighetssamverkan\|ingen_part`) + **`joinNyckel`** (`ssn\|barnRef\|objektRef\|upphandlingsRef\|avtalsRef\|conversationId`). `joinNyckel` MÅSTE styra vilken matchningsnyckel `ArendeMatchService` använder (K-4.11). | `barnRef`-SSN-antagandet bryter för objektärenden, uppdrag, anonyma, myndighetssamverkan. | `[BYGGS]` |
| **K-4.26** | `forstaAtgard`-enumet SKA utöver grundvärdena (`skyddsbedomning\|behovsbedomning\|registrering\|koppla_befintligt`) inkludera **terminerande/icke-födande** `ta_emot_uppdrag`, `karantan_vidarebefordra`, `menprovning`, `triage_vidareformedla`. `karantan_vidarebefordra` = **anti-saga**: ta emot → klassa → vidarebefordra → kvittera, utan att föda `hubsCaseId`. | Roller 4,5,7,8,9,10,11,12 behöver "vägra"/"ta emot redan fattat beslut"/"triage-only utan commit". | `[BYGGS]` |
| **K-4.27** | Modul-/feature-tillgänglighet SKA detekteras (`AppDetectionService`) och styra både widget-rendering och **saga-utfall**: saknas facksystemskonnektor → `commitDestination` får **ej** peka dit. | Kopplar modul-paketering (graceful degradation) till commitDestination-invarianten. | `[FINNS]`/`[BYGGS]` |

### 4.7 Routing-realisering (kategori/flagga → mål)

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-4.28** | Routing-regeln "kategori → mål-enhet" SKA byggas ovanpå `ConsolidateMailboxesService` (`calculateEffectiveUsers()`/`syncMailboxAccess()`/`syncAssignmentTags()`) via uppslag i `ArendeTyp.defaultEnhet`. Kat 2 SKA stödja **sub-klassificering** (insatstyp → 5 mål-enheter: ÄO/LSS/vuxen/ekb/barn). | Korg-/behörighetsmaskineriet moget; det som byggs är *regeln*, inte motorn. | `[FINNS]`/`[BYGGS]` |
| **K-4.29** | Flaggor SKA kunna **flytta** routingen utan att ändra primärkategorin: `skyddade_pu` → skyddad krets, `akut_fara` → prioritetskö/jour. Override-väg, inte komplement. | Bekräftar dubbelmärknings-modellen; skyddade PU överstyr `aclProfil`. | `[BYGGS]` |
| **K-4.30** | Klassningsresultatet SKA levereras **uteslutande via server-side-aggregat** (`GET /api/v2/inflode-summary`) — ingen klient-fan-out. Dashboarden tar emot färdiga fält; `InflodeRad.vue` renderar `KategoriBadge` *bredvid* typ-chip. | CONTRACTS-regel: allt ur server-side-aggregat; `hubs_start` lagrar inget. | `[BYGGS]` |

---

## 5. ROLLER, SYSTEM OF RECORD, INTEGRATIONER & SÄKERHETSSKYDD

> **Områdeskod:** `ROLLER`. Djupdok: `KOMMUNROLLER-SOR-INTEGRATIONER.md` (12 roller, fyrvägs-doktrin, 7 Δ-fält, aclProfil-bibliotek, system-/integrationsmatris, fallinventering F1–F46, säkerhetsskydd-gränsen, blinda fläckar P1–P15, Δ9–Δ17). **Invariant:** Hubs är ALDRIG SoR; den enda legitima SoR-rollen = audit-/handover-spåret.

### 5.1 De 12 rollerna — modellen håller, config-raden utökas

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-5.1** | Hubs SKA stödja alla 12 roller genom **EN** ärende-motor + **N** datadrivna `ArendeTyp`-rader — aldrig per-roll-kodflöde. | Ryggraden R1–R9 oförändrad i alla 12; 0/12 ren config rakt av, men ingen motiverar eget kontrollflöde. | `[BYGGS]` |
| **K-5.2** | `ArendeTyp`-raden SKA utökas med 7 nya/ombyggda fält: `systemOfRecord`+`sorFallback`+`inflodeVia`; `sekretessGrund[]`(struct, temporal); `riskKlass`; `externMyndighetMottagare`+`commitMode`; `verksamhetsgren`+`sekretessMur`; utökad `partsModell`+`joinNyckel`; `karantanKravs`. 6 rena datafält; endast `karantanKravs` berör motorn. | Socialtjänst-config-raden antog tyst fyra premisser (A1–A4) som bryts kommun-brett. | `[BYGGS]` |
| **K-5.3** | Skillnaden mellan roller SKA bäras av enum-värden/flaggor/`aclProfil`-id, INTE av nya tjänster eller `if roll == X`. `AppDetectionService` styr graceful degradation. | Kärntesen (datadriven, ej fork) bekräftad kommun-brett. | `[FINNS]`/`[BYGGS]` |
| **K-5.4** | `riskKlass` (normal/förhöjd/hög/kritisk) SKA vara **härledd, ortogonal axel** ovanpå ärendetypen — flera roller har lågrisk-*typ* men högrisk-*instans*. | Risk OCH ärendetyp fångas separat. | `[BYGGS]` |

### 5.2 SoR-doktrinen — fyra utfall + commitDestination-invarianten

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-5.5** | "Hubs är ALDRIG SoR" SKA realiseras som **fyrvägs-doktrin** per `ArendeTyp`-rad: (i) Fallback-SoR, (ii) Nytt register/pekare, (iii) Tidsbegränsat mellanlager med spårbarhet (uttryckligt beslut + gallringsregel + rättslig grund), (iv) Avvisa/karantän. | Modellen antog tyst att SoR alltid finns och att Hubs alltid får röra informationen. | `[BYGGS]` |
| **K-5.6** | `commitDestination` SKA vara **NOT NULL** i `sdkmc_arende_typ` (DB-constraint). Värden: `facksystem · diarium · e_arkiv · extern_myndighet · triage_forward · karantan`. | Centrala invarianten mot "Hubs blir SoR genom passivitet" — dödligaste felmoden i 9/12 roller. | `[BYGGS]` |
| **K-5.7** | Retention-flippen (R8) SKA aldrig ske utan att ett av de fyra utfallen är **explicit registrerat**. Kluster A (utredning/arbetsmaterial): retention får ej flippa till "klart" förrän handlingen committats ELLER ett uttryckligt icke-diarieföringsbeslut spårats. | För Registrator är tyst icke-diarieföring **otillåten gallring av allmän handling** (TF). `diariePlikt='registreringsbeslut'/'bedoms'`. | `[BYGGS]` |
| **K-5.8** | Den **enda** legitima SoR-roll Hubs får ta SKA vara det smala **audit-/provenans-spåret** (handover skedde korrekt; mottagningskvittens fanns — F42/F45) via `postCommitHook='handover_kvittens'`. Aldrig verksamhetsdata. | "Skickat ≠ mottaget". Två genuint nya smala SoR-fall (transit-/eskaleringsprovenans). | `[BYGGS]` |
| **K-5.9** | `inflodeVia` (`funktionsadress · e_tjanst · muntlig_kontakt`) SKA avgöra om Hubs mellanlagrar alls. `e_tjanst` = informationen går **förbi** Hubs (Överförmyndare R4: årsräkning via e-Wärna → facksystemet äger både inlämningskanal OCH SoR → Hubs får **inte** dubbel-lagra). | E-tjänsten konkurrerar med Hubs som mellanlager — gränsen ritas i data. | `[BYGGS]` |

### 5.3 FacksystemCommitService + konnektorfamiljen

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-5.10** | `TreservaCommitService` SKA generaliseras till **`FacksystemCommitService`** som väljer **konnektorfamilj** via `systemOfRecord`+`frendsModul`. Treserva/Lifecare-konnektorn (GAP-019) byggs **först som referensimplementation**. Tjänsten + konnektorer bor i M4-zonen (ExApp om proprietär). | Dagens motor har EN väg `commitToTreserva()`. `frendsModul` är fel abstraktionsnivå. | `[BYGGS]` |
| **K-5.11** | `frendsMappning` SKA bli **2-dimensionell (modul × produkt)** — samma modul (`ediarium`) har olika schema per produkt (Public360 ≠ W3D3 ≠ Platina ≠ Ciceron ≠ Evolution). Mappnings-*id* i config; varje mappning = riktig integrationskod per produkt × kund. | 5–10× socialtjänstens GAP-019 — tyngsta integrationsbördan (Δ15). | `[BYGGS]` |
| **K-5.12** | En `frendsModul` SKA kunna bli **`frendsModul[]`** (multi-commit, `commitPlan[]`): R2 journal+samordning, R7 verkställighet+Appva, R8 ByggR+Ecos — **flera commit-mål, ingen akt-sammanslagning**. | Flera SoR per händelse = mönster, inte kantfall. | `[BYGGS]` |
| **K-5.13** | `commitMode` (`commit · referens_lank · las_konsument · extern_anmalan`) SKA styra commit-riktning. `las_konsument` = Hubs får ALDRIG skriva (Pascal/NPÖ/SVOD — kommunsjuksköterskan är *konsument* av annan vårdgivares data; aldrig cacha). | Läs-vs-skriv-asymmetri = principiellt skydd. | `[BYGGS]` |
| **K-5.14** | **P0:** En **generaliserad e-diarium/e-arkiv-konnektor** SKA byggas först — primär-SoR för R1/R11 OCH laglig fallback-SoR för varje (c)-zon i R5/R6/R7. EN konnektor avlastar 9 roller. Inkl. `postCommitHook='arkivera_fgs'`. | Största enskilda hävstången (Public360/W3D3/Ciceron/Platina/Evolution/Castor/Sydarkivera/iipax). | `[BYGGS]` |
| **K-5.15** | **P1:** Sokigo-kluster (ByggR/Nova + Ecos 2 + Daedalos) R8/R10; Appva MCSS read/notify R2/R7; HR (Heroma/Personec) R3; Överförmyndare (e-Wärna Go/Provisum/Gö, `sorProdukt` per kund ×3) R4. | Konnektor-gruppering visar var EN konnektor återanvänds. | `[BYGGS]`/`[KONFIG]` |

### 5.4 Externa myndighets-commits — egen integrationsklass (≠ facksystem)

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-5.16** | Rapport till tillsynsmyndighet SKA vara **egen integrationsklass** `commitDestination.typ='extern_myndighet'` med `commitMode='extern_anmalan'`: **ingen dnr-callback**, **ankare = vetskap/kännedom**, **ofta PII-maskning**, **flippar INTE** `retentionState`. Hubs *förbereder* payload; människa lämnar in. | Rapport till tillsyn ≠ commit till facksystem. | `[BYGGS]` |
| **K-5.17** | **P0-extern:** **IMY** PU-incident (GDPR art. 33, **72h ur vetskap**); **MSB/CERT-SE** NIS2 (**24h→72h→30d**); **IVO** Lex Sarah (≤5v, maska PII) + Lex Maria ("snarast", maska) R2/R7. | De mest universella externa myndighets-commit-målen. | `[BYGGS]` |
| **K-5.18** | **Parallell flermottagare (Arketyp B)** SKA stödjas: *en* händelse föder *flera* commits till *flera* SoR med *olika* sekretessregim/klockor. Kanon: R5 ransomware-med-PU = NIS2 (MSB 24h) **OCH** PU-incident (IMY 72h) → två commits, en händelse. | Två klockor från en händelse. | `[BYGGS]` |
| **K-5.19** | Övriga externa mottagare SKA finnas i `externMyndighetMottagare`-enum brett: Försäkringskassan (plan dag-30, R3), Länsstyrelsen (R4/R8/R10), polis/åklagare (anmälningsplikt **bryter** sekretess, R4/R7/R10/R11), domstol (FR/MMD/TR; överklagande 3v). + reservera integrationspunkt mot **nationellt ställföreträdarregister fr.o.m. 2028** (R4). | Avsändar-/mottagar-register måste täcka alla externa parter med rätt LOA. | `[BYGGS]`/`[BESLUT KRÄVS]` |

### 5.5 Säkerhetsskydd-gränsen — terminerande utfall (enda motor-utökningen)

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-5.20** | Sagan SKA få ETT nytt **terminerande** utfall: **"föds inte"** (`avvisad_sakerhetsskydd`). `preSagaHook='avvisa_sakerhetsskydd'` körs som **led −1, FÖRE R1 (mint)** med **abort-semantik**. Default på säkerhetsskydds-indikator = **avvisa** (fail-closed, samma disciplin som `MessageTypeService` kastar Exception på okänt suffix). | A4 — det **enda** som rör motorn, inte bara config. Gäller 8–9/12 roller. Säkerhetskritiskt (Δ10). | `[BYGGS]` |
| **K-5.21** | Vid avvisning SKA utfallet vara: **ingen Groupfolder, ingen DB-rad med innehåll, ingen `case:`-tagg, inget Spreed-rum, ingen Frends-commit** — bara ett **spårbart avvisningskvitto**. | Säkerhetsskyddsklassat (SäkL 2018:585 + förordning 2021:955) får ofta inte ligga i internetexponerad NC/Spreed alls. | `[BYGGS]` |
| **K-5.22** | Säkerhetsskydd-gränsen SKA vara **kvalitativt skild** från `skyddade_personuppgifter`: skyddade PU → snävare ACL *inom* systemet; säkerhetsskyddsklassat → får **inte vara i systemet alls**. `sekretessNiva='sakerhetsskydd'` är därför **inte en ACL-profil** utan ett **avvisnings-utfall**. | Viktigaste enskilda arkitekturregeln. Att behandla det förra som det senare = lagbrott. | `[BYGGS]` |
| **K-5.23** | **Retroaktiv karantän** vid felmottagning SKA stödjas: radera ur index, Spreed, Groupfolder, tag-DB **efter** mottagning; triggas på **svaga** signaler (fail-closed); stoppa propagering retroaktivt (svårt när taggen redan spridits trådbrett). Upptäckten kan vara en **säkerhetsskyddsincident** (strängare än PU 72h). `InnehallsKlassService` utökas med `avvisa/isolera` (Δ11). | "Farligaste vägen är inkommande" (R10/R12 — KC vet sällan vad de håller i). | `[BYGGS]` |
| **K-5.24** | Ett **läges-/regimbyte** SKA stödjas: `höjd_beredskap`/`krig` kan flytta **hela kategorier** från normal regim till säkerhetsskydd-regim. Vid regimbyte kan hela Hubs bli olämpligt för vissa kategorier (R10). | Säkerhetsskydd-regim är dynamisk, inte bara statisk per ärende. | `[BESLUT KRÄVS]`/`[BYGGS]` |
| **K-5.25** | Säkerhetsskydds-integrationer SKA **medvetet INTE byggas**: WIS (MSB-ägt, manuell referens), FM-godkänd krypto/signalskydd. Klassad RSA/krisledning/SUA/säkerhetsprövning via avvisa-vägen. | "ICKE-bygg medvetet." | `[EXTERN]` |

### 5.6 Visselblåsning — isolerad NON-route

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-5.26** | Visselblåsning (lag 2021:890) SKA vara **NON-route** som **inte ens får passera sagan** — utfall `avvisa/isolera`. Separat restricted SoR utanför Hubs domän (Lantero/WhistleB/Draftit Whistle). Hubs roll = avvisa/isolera felmottaget, aldrig route:a. | Berör R1/R3/R9/R11. Reconciliation, `ConsolidateMailboxesService`, `ArendeMatchService` är **oförenliga** med isoleringskravet. | `[BYGGS]`/`[EXTERN]` |
| **K-5.27** | `aclProfil='vissel_isolerad'` SKA ge **tre hårda opt-outs**: (1) ur `ArendeMatchService` (ingen SSN/case-tagg-matchning), (2) ur `ConsolidateMailboxesService`, (3) egen ACL-domän som **döljs även för gruppledare** och alla aggregat. | Matchningsmotorn är fienden vid isolering (SSN→anmäldes personalärende vore katastrof). | `[BYGGS]` |
| **K-5.28** | **Jäv-baserad intra-Circle-exkludering** SKA stödjas (`exkludera_uid[]` på instansen): juristen/handläggaren kan vara *part/motpart* till en kollega → isolering **utanför rollens egen krets**. GAP-058-koherenstestet måste täcka detta. | Samma klass som visselblåsning men inom ordinarie roll (R11). | `[BYGGS]` |

### 5.7 PuB-/laglig-grund-matris + temporal sekretess + verksamhetsgrens-mur

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-5.29** | En **PuB-/laglig-grund-/ändamålsmatris** SKA ligga i config från dag ett, per `ArendeTyp`-rad: `lagligGrund`, `personuppgiftsansvarig`, `andamaalsbegransning`, `gdpr_art9`. | Utan matrisen blir Hubs juridiskt **osäljbart** (PuB-avtal oundertecknbart, RoPA omöjlig). Δ17. | `[BYGGS]` |
| **K-5.30** | `sekretessGrund[]` SKA vara **struct med temporalitet** + `sekretessNiva` utökas till `offentlig_tills_menprovad \| normal \| hog \| restricted \| sakerhetsskydd`. `omfattning='delfalt'` täcker anmälaridentitet (R8 asymmetrisk sekretess). | Tre brutna antaganden (A2+A3): offentlighet som norm, temporal sekretess, restricted ovanför hög. | `[BYGGS]` |
| **K-5.31** | **Temporal ACL-degradering** SKA stödjas (Δ13): absolut anbudssekretess (OSL 19:3) gäller *tills* tilldelningsbeslut, sedan **flippar** offentligheten via `postCommitHook='slapp_anbudssekretess'`. Flippen måste degradera **HELA `case:`-klustret** (även Spreed-historik och Deck-kort — bakåt-läckage). Glöm ej avtalsspärr/överprövning 10 dgr. | Temporal sekretess = modellgap, ej SoR-gap; i 5 roller oberoende (R1/R5/R8/R9/R11). | `[BYGGS]` |
| **K-5.32** | `sekretessMur` (verksamhetsgrens-mur, **inte** ACL-gradient) SKA stödjas: hård mur mellan självständiga verksamhetsgrenar i samma fysiska person/akt — EMI/HSL (PDL, OSL 25) ↔ skoldok (OSL 23) R6; SoL (OSL 26) ↔ HSL (PDL) R2/R7 via `vardgivarSpar` (`SoL\|HSL\|bada`). | **Utan detta route:ar modellen HSL-innehåll in i SoL-akt — sekretessincident inbyggd.** Enda *hårda* nya fält-utökningen. | `[BYGGS]` |
| **K-5.33** | `aclProfil` SKA bli ett **bibliotek** (≥8 profiler: `socialtjanst_deny_default`, `offentlig_tills_menprovad`, `vissel_isolerad`, `partsatskillnad`, `verksamhetsgrens_mur`, `extern_part_scoped`, `dso_oberoende`, `temporal_degraderbar`). `aclProfil`-id väljer Circles-mönster (konfiguration, ej ny kod). ACL-default **per-roll**, ej hårdkodad deny-by-default. `skyddade_personuppgifter` överstyr alla profiler hårdare; `dso_oberoende` = avskild krets även mot egen IT-chef (R5, art. 38). | Skalar via Circles + GAP-058 tre-lagers-koherens. Δ14. | `[BYGGS]`/`[KONFIG]` |
| **K-5.34** | `partsModell` SKA utökas + `joinNyckel` (se K-4.25). SSN-steget i `ArendeMatchService` SKA kunna **stängas av kategori-specifikt** för objektärenden (R8/R9), anonym begäran (TF 2:18, R1/R12), myndighetssamverkan (R10). | SSN-matchning antar personärenden; objekt-/anonym-/uppdragsärenden bryter det. | `[BYGGS]` |

### 5.8 Cross-role-routing

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-5.35** | Cross-role SKA bäras på **instansen**, ej typen: `primarRoll` + `medmottagare[]` (`{roll, sekretessGrund, commitMode, aclScope:'egen_kerna'}`) + `korsAxel` (`eskalering \| parallell_flermottagare \| transit`). Tre arketyper: A eskaleringskedja (R6), B parallell flermottagare (R5), C felmottaget/transit (R12/R1/R11). | Mönster, inte kantfall. Undviker kombinatorisk explosion. Δ12. | `[BYGGS]` |
| **K-5.36** | Medmottagares akter SKA **ALDRIG** slås ihop: delat `hubsCaseId` för koordinering men **separata commit-mål med olika ACL-kretsar** (sekretessmur). | Sammanslagning över sekretessgränser = inbyggd incident. | `[BYGGS]` |
| **K-5.37** | `felmottaget_men_känsligt` SKA **fail-closed på svaga signaler** och kunna isolera **retroaktivt** (opt-out ur `ArendeMatchService` *innan* SSN-ankring, stoppa propagering, riva taggar — GAP-063). `forstaAtgard='karantan_vidarebefordra'` (anti-saga, R12) vidarebefordrar oöppnat. | Känsligt landar fel och ska vidare oöppnat; visselblåsning läcker in på `juridik@` (R11). | `[BYGGS]` |

### 5.9 forstaAtgard / fristPolicy / retentionAnkare — saga-utfall bortom "föd"

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-5.38** | `forstaAtgard` SKA stödja icke-födande/icke-commit-utfall: `ta_emot_uppdrag` (R7 — sagan körs *baklänges* via `preSagaHook='spegla_myndighetsbeslut'`), `karantan_vidarebefordra` (R12), `menprovning`/`radgivning` (R11), `triage_vidareformedla` + `frendsModul=null` (R6/R12/R10), `sekretessprovning`/`rattidsprovning`/`diariefor_direkt` (R1). | Sagan kan idag bara *föda*; tre utfall saknas. | `[BYGGS]` |
| **K-5.39** | `retentionAnkare` SKA **lösgöras från commit** (`commit \| lagstadgad_tid \| vidaresant_kvittens`): kamerabevakning gallras per kamerabevakningslagen, RSA per 2-årscykel — inte vid facksystem-commit (R10). | Annars bryter gallring av kamera/RSA R8-flippen. | `[BYGGS]` |
| **K-5.40** | `fristPolicy` SKA vara **struct** (ej skalär): `{typ, ankare, milstolpar[], forlangningsbar, bindande:'lag'\|'avtal'}`. Måste täcka: 72h ur *kännedom* (IMY), NIS2 multi-milstolpe (24h→72h→30d), sub-dygn/skyndsamt (icke-numerisk, TF 2:16), komplett-ansökan-ankare (PBL 10 v), avtalslivscykel (framtida datum), säsongsklump (1 mars årsräkning R4). Avtalsfrister ≠ lagfrister (`bindande`). | Frister har olika ankare och multi-milstolpar i 7 roller. Δ16. | `[BYGGS]` |

### 5.10 Datalager-/process-/licensgräns (binder till §2)

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-5.41** | `ArendeTyp`-registry + `commitDestination`-constraint + `FacksystemCommitService`-konnektorer SKA bo i **M4-zonen** med single-writer-garanti. Start i **(b) sdkmc:s NC-app-DB** (`sdkmc_arende*`), schema **ExApp-rent** så lyft till (c) ExApp-DB blir data-migrering. | Tables underkänd. ExApp-modellen löser modul-, licens- OCH datalagerfråga i ett val. | `[BYGGS]`/`[BESLUT KRÄVS]` |
| **K-5.42** | Taggmotorn (`case:{id}`-token) SKA flyttas till plattformskärnan (M0) **FÖRE** M4 byggs (kod-refaktor, ej data-migration). | Annars cementeras monoliten. | `[BYGGS]` |

---

## 6. VERKSAMHETSMODULENS UI/UX & DEMOLÄGE

> **Områdeskod:** `UI`. Persona i centrum: `socialsekreterare`; gruppledare via `fordelning`-läge. **Stack (verifierad):** Vue 2.7 + `@nextcloud/vue` v8, standalone `hubs_start` (namespace `HubsStart`, `AGPL-3.0-or-later`, NC 30–32). All ärendedata ägs av sdkmc/M0. Djupdok: `UI-EVOLUTION-SOCIALSEKRETERARE.md`, `UX-REDESIGN-SOCIALSEKRETERARE.md`, `SOCIALSEKRETERARE-WALKTHROUGH-V2.md`. **Verifierat:** 34 Vue-komponenter LIVE i demo (`src/components/socialsekreterare/`); det som återstår är datamotorn bakom seams + den enda obyggda komponenten `KategoriBadge`.

### 6.1 Container, zon-arkitektur & den orörliga kärnan

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-6.1** | `MinaArenden.vue` SKA vara enda containern för `socialsekreterare`, rendera de sju zonerna i flex-kolumn, äga summary-state + `zonOf`-fördelningen, routa alla events till tjänster. `Start.vue` grenar `v-if="activePersona==='socialsekreterare'"` → `<MinaArenden/>`, annars befintlig grid. | Isolerar redesignen till en persona; minsta diff. | `[FINNS]` container; `[KONFIG]` vy-växel + flag `socialsekreterareNewView` |
| **K-6.2** | `zonOf(item)` SKA vara **ren selector** (ingen fan-out): inflöde på `arendekoppling` (`nytt→attTaEmot`, `hor_till→attHantera`, `ej_kopplat→ejKopplad`); ärendekort på frist-ton/plikt/`vantarPaMig`/omnämnande → `het`/`aktiv`. | Testbar funktion över EN payload; deterministiskt compliance-ankare. | `[FINNS]` |
| **K-6.3** | `NastaAtgardKnapp` SKA härleda etikett ur **state machine** `(steg, tillstånd)→åtgärd`, med `vantar`-overrides. Servern validerar att åtgärden är tillåten i fasen (fas-spärr). | Låg kognitiv last; server är auktoritet, klient speglar. | `[FINNS]` frontend; `[BYGGS]` server-side fas-validering |
| **K-6.4** | `ProcessStepper` (Förhandsbedömning→Utredning→Beslut→Uppföljning→Avslutat) SKA vara presentational, **ikon + text per steg** (aldrig bara färg), `aria-current="step"`, pil-navigerbar, **blockera framåt-övergång om `plikt` okvitterad**. | Lär ut ärendelivscykeln; pliktspärren = strukturell fristgaranti. | `[FINNS]` |
| **K-6.5** | `ArendeKort` SKA vara kärnkomponenten: kollapsat (Zon 3) vs expanderat Quick View (Zon 2) **utan sidbyte**, flikar *Dokument · Säkra meddelanden · Diskussion · Möten · Bevakningar · Beslut*, lazy via `fetchArende(ref)`. | Samlar de gamla ~13 widgetarna per ärende. | `[FINNS]` |

### 6.2 Multi-korg + tre band

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-6.6** | `KorgValjare.vue` SKA visa korg-piller med `NcCounterBubble` + typ-filter-rad (8 `messageType`), kombinera korg ∩ typ, vara knapp-/tangentbordsstyrd (`aria-pressed`, WCAG 2.5.7), **bara rendera behöriga korgar** (server-filtrerat = OSL-gräns). Default `aktivKorg=null`. | 4–6 korgar utan navigeringskostnad; en korg man ej får läsa renderas inte. | `[FINNS]`; `[EXTERN]` behörighetsfiltrerade korgar ur `fetchInflodeSummary()` |
| **K-6.7** | Tre band SKA dela **samma datakälla** (`InflodeRad`-shape) + samma filter, separeras på kognitiv uppgift: **1a `AttTaEmotSektion`** (`nytt`), **1b `AttHanteraSektion`** (`hor_till`, gruppering per ärende), **1c `EjKoppladSektion`** (`ej_kopplat`). | Triage ≠ ärendearbete ≠ löst inflöde. | `[FINNS]` |
| **K-6.8** | Banden 1b/1c SKA vara **kollapsbara och tomma-som-default**, med undervisande `NcEmptyContent` som compliance-kvitto, `aria-live="polite"`. 1c:s räknare SKA vara **röd-när-gammal** (rader >1 arbetsdag, OSL 5:1). | Tom skärm = trygghetssignal. Compliance-KPI mot registreringsplikten. | `[FINNS]` |
| **K-6.9** | `EjKoppladSektion` SKA exponera **de sex åtgärderna** (Koppla · Skapa nytt · Besvara · Vidarebefordra · Gallra · Registrera) + auto-förslag + synligt "varför". Ingen rad får försvinna utan att kopplas, registreras eller gallras med dokumenterat stöd. | Den juridiska mellanstationen. | `[FINNS]` UI; `[EXTERN]` riktiga effekter i sdkmc |
| **K-6.10** | `GallringsGrind.vue` SKA tvinga val av **handlingstyp ur kommunens DHP** → visa gallrings-/bevarandebeslut → först då aktivera "Gallra". Saknas gallringsgrund → "Registrera utan ärende". Bekräftelse loggas i `activity`. | "Gallra" får aldrig vara naket klick (arkivlag 1990:782 §10; OSL 5:1). | `[FINNS]`; `[KONFIG]` DHP-källa; `[BESLUT KRÄVS]` DHP-källa |

### 6.3 Ärendekoppling — KopplingBadge

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-6.11** | `KopplingBadge.vue` SKA visa tre tillstånd **alltid ikon + text + färg**: `kopplad`, `foreslagen` (bekräfta/avvisa endast om åtkomst till målärendet, annars "eskalera till gruppledare"), `ej`. | Gör ärende-identiteten synlig **på objektet** utan rå `hubsCaseId` (UUID = join-nyckel, pseudonym = vad människan ser). | `[FINNS]` |
| **K-6.12** | `KopplingBadge` SKA ha **samma grammatik överallt** (WCAG 3.2.4): `InflodeRad` + objekt i `ArendeKort`s flikar. Mappar tekniskt till `case:{id}`-tagg resp. registerpekare. `konfidens≥0.9 → kopplad` i demo. | Konsekvent "vad hör detta till"-signal; tekniken dold. | `[FINNS]` UI; `[EXTERN]` `case:`-tagg `[FINNS]`, `ArendeMatchService` `[BYGGS]` |

### 6.4 Chatt — tre distinkta ytor

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-6.13** | `Dagspulsen` SKA ha **femte räknare `💬 omnämnanden`** (mentions + 1:1 riktade till mig) — aldrig rå olästa. Klick filtrerar "väntar på mig". | Chatt = pull, inte push; enda push-signalen = omnämnande till mig. | `[FINNS]` |
| **K-6.14** | `DiskussionChip` SKA visa `💬 3` (olästa) eller `💬 @1` (omnämnande-till-mig) på kollapsat `ArendeKort`. Endast `omnamnandeTillMig===true` lyfter kortet till Zon 2. Aldrig röda badge-moln. | Surfar ärende-chatt på ärendet, utan inkorgskänsla. | `[FINNS]` |
| **K-6.15** | `ArendeKort`s Meddelande-yta SKA delas i **"Säkra meddelanden"** (extern, kvittensbärande) och **"Diskussion"** (`ArendeDiskussion.vue`, intern). Diskussion-fliken: `SekretessRad` överst, trådvy med `@`-omnämnanden, per-meddelande **"Gör detta till en handling"** → mall → human-in-the-loop → `CommitGrind` → systemkvitto, samt **"Lyft till enhetschatt"**. | Intern medbedömning hör på ärendet men committas aldrig tyst. | `[FINNS]`; `[EXTERN]` rummet = spreed-rum via `talkToken`-pekare `[BYGGS]` |
| **K-6.16** | `ArendeDiskussion` SKA rendera **lättviktig trådvy** — inte bädda in hela Talk-UI:t. Demo: `fetchArende(ref).diskussion`; prod: `talkToken` ur registret. | Designvakten "ingen kanal-först". | `[FINNS]` demo; `[EXTERN]` prod via `talkToken` |
| **K-6.17** | `EnhetschattPanel.vue` SKA vara **lugn sidoyta** (Zon 5-ingång), aldrig kort i ärendeströmmen. Team-trådar (Circles-team) + `SekretessRad` per tråd + "Starta fördelningsmöte". | Team-/enhetschatt får inte tävla med ärendekort. | `[FINNS]`; `[EXTERN]` Circles-team + spreed |

### 6.5 Tilldelning & FordelningsVy

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-6.18** | `TilldelningBand.vue` SKA vara **diskret rad** på `ArendeKort`: `tilldelat` *"Tilldelad mig av Eva 14/6"*, första 24h accent-markör **"NY"** (`nyFor24h`), `fran:'mottagning'` ursprungschip. | En nyfördelning får inte drunkna; ursprunget synligt utan att fråga. | `[FINNS]` |
| **K-6.19** | Roll-läget `fordelning` SKA aktiveras via läge-växel i Zon 0, **endast renderad för den med fördelarroll** i korgens team (åtkomstgränsen ÄR OSL-gränsen). `FordelningsVy.vue` ersätter zon-listan ovanpå **samma** `zonOf`/`ArendeKort`/`FristChip`. | Chefens lättviktiga fördelningsyta utan nytt mentalt verktyg. | `[FINNS]`; `[EXTERN]` rollkälla (`RoleService` + team-medlemskap) |
| **K-6.20** | `FordelningsVy` Zon A "Att fördela" SKA bära `FordelaTill.vue` (utredar-väljare **med last i parentes**, `Sara (8)`) + mottagningssekreterarens förslag + "ofördelad i N dagar". Räknaren SKA **aldrig nå noll med kort kvar**. | Chefen ser belastning vid valet; inget barn mellan stolarna. | `[FINNS]`; `[EXTERN]` `fetchFordelningSummary()` |
| **K-6.21** | `FordelningsVy` Zon B `UtredarLast.vue` SKA visa **tal + frist-färg + "nära tak"-markör per utredare, ALDRIG innehåll/vilka barn** (gruppledare har ej automatiskt sekretess-åtkomst, OSL 26 kap.). Siffrorna ur `fetchFordelningSummary()`. | Hårdaste sekretessgränsen i UI:t — får inte läcka innehåll via chefsvy. | `[FINNS]` UI; `[EXTERN]` server-aggregat utan PII |
| **K-6.22** | Fördelningshandlingen SKA gå genom **bekräftelse-dialog** som stavar ut konsekvensen → `@fordela(arende, uid)` → **server orkestrerar atomärt** (assignee + ACL-omskrivning + Deck-kort + flytt + notis). | Halvgenomförd tilldelning = sekretess-/spårbarhetshaveri (GAP-057). | `[FINNS]` UI; `[BYGGS]` **atomär saga-orkestrering (kritisk, GAP-057)** |

### 6.6 Favoriter (FavoritValjare ovanpå Kontakter)

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-6.23** | `FavoritValjare.vue` SKA i Besvara/Vidarebefordra visa **resolvad union [Mina favoriter] ∪ [funktions-delad lista]** ovanpå **Kontakter som-den-är**, varje rad med klass-markör + `ProvenansChip`. Favorit = **pekare, inte post** (`X-HUBS-SDK-REF` + visningscache, föränderliga fält resolvas färskt ur DIGG). Listan: **bara funktion/myndighet/fax — aldrig en medborgare**; borttagen DIGG-källa → överstruken (`removed:true`). | Säkra ett-kliks-handlingar; medborgar-PII hör i ärendet. Favoriten taggas aldrig `hubsCaseId`. | `[FINNS]`; `[EXTERN]` sdkmc-resolverlager mot DIGG + Kontakter (ingen fork) |

### 6.7 Treserva-kvittens, KategoriBadge & övriga chips

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-6.24** | `TreservaKvittens.vue` SKA vara **read-only** kvittens-/retention-yta som visar **verifierade** Treserva-commits + gallringsklockan för Hubs-kopian. Ingen rad får antyda gallring på kryssruta. Signal = ikon + text ("Verifierad", `CheckDecagram`). Identitet = `barnRef` + `dnr`. | Materialiserar "Hubs = mellanlagring, facksystemet = slutlagring"; binder gallring till verifierad commit (GAP-007). | `[FINNS]`; `[EXTERN]` verifierad commit-callback via Frends/`FacksystemCommitService` `[BYGGS]` |
| **K-6.25** | `CommitGrind.vue` SKA vara det **synliga, verifierade commit-ögonblicket** (Skickat → bekräftat API-svar → Registrerat). Vid bekräftat svar: `ProvenansChip` flippar, stepper flyttas fram, **pliktmarkör släcks**, **retention-countdown startar** — aldrig vid kryssruta. Vid fel: stannar på "skickat". | Ersätter tyst auto-flip; kopplar stepper-framsteg + retention-start till verifierad commit (GAP-007/019). | `[FINNS]` UI; `[EXTERN]` `commitToTreserva` → Frends `[BYGGS]` |
| **K-6.26** | **`KategoriBadge.vue` SKA byggas** (saknas idag) som atom i `FristChip`/`ProvenansChip`/`KopplingBadge`-familjen (`.hs-chip`-grammatik, ikon + text + färg), visar ärendekategori/`messageType` (en av 8) konsekvent på `InflodeRad` + kortobjekt. | Konsekvent kategorivisning (WCAG 3.2.4). **Den enda uttryckligt efterfrågade komponenten som inte finns.** | `[BYGGS]` |
| **K-6.27** | `FristChip`/`FristPanel` SKA visa **fristtyp + dagar kvar + datum** med eskaleringsfärg grå→gul(≤3)→röd(förfallen), `tone` **server-beräknad och speglad ur Treserva** (Hubs räknar aldrig själv → ingen falsk-röd vid förlängning). `ProvenansChip` visar `ej_registrerad`/`registrerad` med dubbel countdown. | Garanterar att ingen lagstadgad klocka missas utan att Hubs divergerar (GAP-018). | `[FINNS]`; `[EXTERN]` frist-spegling via Frends |

### 6.8 WCAG, varumärke & datakontrakt (tvärgående)

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-6.28** | Alla chips/knappar/räknare SKA ha **Target Size ≥24×24 px**; filtrering via knapp/tangentbord (aldrig drag, WCAG 2.5.7); `aria-live="polite"` på de tre banden; `aria-pressed` på piller; `aria-current="step"` i steppern; **färg aldrig enda informationsbärare**; reflow i porträtt/400 %; Focus Not Obscured (2.4.11). | Lagstadgad tillgänglighet (WCAG 2.2 AA) — fältarbete/hembesök i porträtt. | `[FINNS]` mönstret; `[KONFIG]` verifiera per ny komponent |
| **K-6.29** | **Varumärkesregel (enforced):** UI-text säger **aldrig** "Nextcloud"/"Talk"/"Circles". Tillåtna ord: *korg, funktionsadress, säkert meddelande, ärendechatt, enhetschatt, team, omnämnande, ärenderum, bevakning, ärende-koppling, fördela, säkert möte, e-underskrift, facksystemet/Treserva*. Alla strängar via `t('hubs_start', …)`. | Konsekvent kommun-/myndighetston; leverantörsnamn når aldrig slutanvändaren. | `[FINNS]` regel; `[KONFIG]` lint av nya strängar |
| **K-6.30** | Hela vyn SKA drivas av **server-side aggregat — ingen klient-fan-out**: `fetchInflodeSummary()`, `fetchArendeSummary()`, `fetchArende(ref)` (lazy), `fetchFordelningSummary()`. Demo-seams i `demo/socialsekreterare.js`/`treserva.js`/`favoriter.js`; prod = OCS-routes i sdkmc/M0. | Frontend-kontraktet låst mot dessa fyra funktioner; en ny tråd bygger routes bakom seamen utan att röra UI:t. | `[FINNS]` demo-feeds; `[EXTERN]` riktiga OCS-routes `[BYGGS]` |

---

## 7. SEKRETESS, JURIDIK, SIGNERING, RETENTION & COMPLIANCE

> **Områdeskod:** `JURIDIK`. Scope: OSL/GDPR/säkerhetsskyddslag/arkivlag som styr vad Hubs får ta emot, lagra, dela, signera, gallra. Premiss: Hubs är ALDRIG SoR; enda legitima SoR-roll = audit-/handover-spåret. Djupdok: signering → `SIGNING-INERA.md`; SoR/sekretess/PuB → `KOMMUNROLLER §2–6`; favoriter/GallringsGrind → `KONTAKTER-FAVORITER.md §4`; datalager/licensgräns → `MODULARISERING-LICENS-DATALAGER.md`; gap → `GAP-ANALYSIS.md`.

### 7.1 Sekretess, inre sekretess & behörighet (OSL, GDPR-grund)

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-7.1** | Varje `ArendeTyp`-rad SKA bära strukturerat `sekretessGrund[]` (`{grund, omfattning:'hel_akt'\|'delfalt', temporal}`) i stället för skalär `sekretessNiva`. Grund-enum SKA minst täcka OSL 26 (soc), 39 (HR), 19:3/31:16 (anbud/affär), 32:4 (ÖF), 18 (brott/skydd), 23/25 + PDL (skola/HSL), GDPR art. 9, TF (offentlig). | Skalär `hög\|normal` bryter mot 4/12 roller; utan struktur kan menprövning/maskning/PuB-matris ej härledas datadrivet. | `[BYGGS]` |
| **K-7.2** | `sekretessNiva` SKA bli enum med fem lägen: `offentlig_tills_menprovad \| normal \| hog \| restricted \| sakerhetsskydd`. ACL-default SKA vara **per-roll konfigurerbar**, ej hårdkodad deny-by-default. | Bygglov/miljö/KC/diarium har offentlighet som norm; hårdkodad deny-by-default är fel och blockerar legitim sökbarhet. | `[BYGGS]`/`[KONFIG]` |
| **K-7.3** | Inre sekretess SKA upprätthållas av **tre-lagers-ACL-koherens** (GAP-058): `case:`-tagg ∩ Groupfolder-ACL ∩ Tables/aggregat-vy = samma sanning. Koherenstest SKA gälla även aggregeringslagret (räknare/frist-färg/summary får ej läcka det ACL döljer). | Inre sekretess bryts annars via en räknare även om akten är skyddad. | `[BYGGS]` |
| **K-7.4** | Behörighetsmatrisen SKA realiseras som **`aclProfil`-bibliotek** (≥8 profiler) ovanpå **Circles** som kanonisk medlemskapssanning. `aclProfil`-id väljer Circle-mönster per funktionsadress. | `aclProfil` som gradient kan ej uttrycka partsåtskillnad/murar/oberoende-kretsar. Circles-synk finns. | `[FINNS]` (Circles)/`[BYGGS]` (bibliotek) |
| **K-7.5** | Datamodellen SKA stödja `verksamhetsgren` + `sekretessMur` (hård mur, ej gradient) mellan självständiga regimer i samma fysiska person/akt. Routing SKA aldrig kunna placera HSL-innehåll i SoL-akt. | Utan detta är en sekretessincident **inbyggd i datamodellen**. | `[BYGGS]` |
| **K-7.6** | ACL SKA stödja **jäv-/intressekonflikt-exkludering** på instansnivå (`exkludera_uid[]`). GAP-058-koherenstestet SKA täcka exkluderingen. | Roll 11 (jurist) och roll 3 (chef-är-part): personen som *är* part får inte se sitt eget ärende via default "ägande enhet får insyn". | `[BYGGS]` |
| **K-7.7** | Favoritlistor (Kontakter) SKA gå genom GallringsGrind server-side: medborgar-PII blockeras och styrs till ärenderummet under `hubsCaseId`; klass (b) externt fax kräver `X-HUBS-OWNER` = funktion. Favoriten = pekare mot DIGG/SDK, aldrig auktoritativ kopia. | Hindrar att fri favoritlista blir oregistrerat personregister / ändamålsbrott. Laglig grund = art. 6.1.e, ej samtycke (EU-dom C-77/21). | `[BYGGS]` |

### 7.2 Skyddade personuppgifter & säkerhetsskydd som tvärgående gate

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-7.8** | Flaggan `skyddade_personuppgifter` (OSL 22 kap.) SKA fungera som **hård ACL-override** som överstyr alla `aclProfil` till snävast krets och döljer posten i ALLA aggregat/räknare/frist-färger (även aggregeringslagret, K-7.3). | Folkbokföringssekretess/skyddsidentitet får inte läcka via enhetsvy/summary. | `[BYGGS]` |
| **K-7.9** | Säkerhetsskyddsklassificerad information (SäkL 2018:585 + förordn. 2021:955) SKA hanteras som **terminerande utfall, inte sekretessnivå**. `preSagaHook='avvisa_sakerhetsskydd'` SKA köra som led -1, FÖRE R1, med abort-semantik: **ingen Groupfolder, ingen rad med innehåll, ingen `case:`-tagg, inget Talk-rum, ingen commit** — endast spårbart avvisningskvitto. | "Säkerhetsskyddsklassat ≠ skyddade PU": det förra får inte vara i molnsystemet alls; att behandla det som vanlig sekretess = **lagbrott**. Den enda genuina motor-utökningen. | `[BESLUT KRÄVS]` + `[BYGGS]` |
| **K-7.10** | Karantängrinden SKA verka **retroaktivt** på svaga signaler (fail-closed): radera ur index/Talk/Groupfolder/tagg-DB även efter mottagning, stoppa tagg-propagering bakåt. Default vid säkerhetsskydds-indikator = **avvisa**. | Farligaste vägen = inkommande felmottagning; upptäckten kan själv vara säkerhetsskyddsincident strängare än 72h-PU. | `[BYGGS]` |
| **K-7.11** | Ett **regim-/lägesbyte** (`höjd_beredskap`/krig) SKA kunna flytta hela kategorier från normal regim till säkerhetsskydds-regim. | Roll 10: under höjd beredskap blir krisledningsmaterial klassat en masse. | `[BESLUT KRÄVS]`/`[BYGGS]` |
| **K-7.12** | Visselblåsning (lag 2021:890) SKA behandlas som **NON-route** som ej får passera sagan. Tre hårda opt-outs: ur `ArendeMatchService`, ur `ConsolidateMailboxesService`, egen ACL-domän `vissel_isolerad`. Hubs integrerar **medvetet inte** mot visselblåsarsystem. | SSN-matchning av visselblåsning mot anmäldes personalärende = katastrof. Restricted SoR utanför Hubs domän. | `[BYGGS]`/`[EXTERN]` |

### 7.3 Signering (e-underskrift)

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-7.13** | Signering SKA byggas som **signeringsadapter-app (arkitekturval A)** med två utbytbara backends bakom samma kö-UI/provenance-modell: LibreSign (internt lågrisk) och Inera Underskriftstjänst-API (skarp AES). UI/kö/bevarandepanel identiska; bara identitets-/trust-lagret byts. | "Bygg arbetsytan + bevarandepanelen, inte kryptokärnan." | `[BYGGS]` |
| **K-7.14** | För skarpa myndighetsbeslut (avslag, bistånd, SIP, justering, samtycke, delgivning) och **alla externa signatärer** SKA signeringssteget gå via Inera Underskriftstjänst-API (BankID/Freja/SITHS-AES → PAdES). LibreSign-AES SKA aldrig användas för dessa. | LibreSign ≠ svensk myndighets-AES; ett SMS-/kontosignerat avslagsbeslut står sig inte i förvaltningsrätt (GAP-034/037). | `[BYGGS]`/`[EXTERN]` (Inera-avtal) |
| **K-7.15** | I demo/internt lågrisk-läge SKA identiteten **etiketteras ärligt** ("konto/SMS, ej BankID") och bevarandepanelen SKA **inte** påstå LTV. | LibreSign saknar robust LTV/kvalificerad tidsstämpel; falsk "Giltig då" = vilseledande bevisvärde (GAP-035). | `[KONFIG]` |
| **K-7.16** | Efter PAdES-svar SKA Hubs härda dokumentet i mellanlagret till **PDF/A-1 + LTV + kvalificerad tidsstämpel + valideringsintyg** ("Giltig nu / Giltig då"-panelen) före slutlagring. | Inera levererar PAdES; arkivhärdningen är Hubs ansvar. Verifiera om Inera-profilen kan leverera LTV/tidsstämpel direkt. | `[BYGGS]`/`[EXTERN]` |
| **K-7.17** | Endast **hash/checksumma + signeringsmeddelande** SKA exponeras mot Underskriftstjänsten; ingen sekretessbärande dokumenttext får lämna driftmiljön. Hash-baserad profil SKA verifieras mot Inera (OSL 10:2a). | Bevarar OSL 10:2a-/CLOUD Act-berättelsen — dokumentet stannar i Hubs. | `[BYGGS]`/`[EXTERN]` |
| **K-7.18** | `SignMessage`-texten per dokumenttyp SKA sättas av adaptern och **juridiskt granskas**; vag/felaktig text underminerar bevisvärdet. | Signeringsmeddelandet är det undertecknaren godkänner — bär rättshandlingen. | `[BESLUT KRÄVS]` (juristgranskning per typ) |
| **K-7.19** | Per dokumenttyp SKA en **kravnivå-badge** (SES / "Godkänn" / AES / QES) visas, härledd ur kund-/jurist-satt matris (SKR:s vägledning 2025). Hubs **visar** kravnivån, **gissar** den inte. | Mappningen beslutstyp→kravnivå = policy-/juristfråga per kommun. | `[BESLUT KRÄVS]` per kund |

### 7.4 Retention, gallring & frister

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-7.20** | Gallring av Hubs-mellanlagringskopian SKA triggas **N dagar efter verifierad commit-händelse** från facksystemet — **aldrig** på "tagg + tid" eller manuell "markera överförd"-kryssruta ensam. Tills verifierad commit finns: ingen gallring vid manuell markering; andrahandläggar-bekräftan för fristbärande poster. | Worst case: "överförd" markeras utan registrering → Hubs gallrar enda kopian → bevarandepliktig handling/frist försvinner = arkiv-/offentlighetsbrott. Tyngsta retention-blockern (GAP-007). | `[BESLUT KRÄVS]` + `[BYGGS]` |
| **K-7.21** | Retention SKA realiseras via **GallringsGrind** bunden till **handlingstyp** mot kommunens **dokumenthanteringsplan (DHP)**: varje handlingstyp → bevaras/gallras + frist + ansvar. Rå mötesinspelning/transkript, favoritlistor, utredningsmaterial SKA in i DHP som egna handlingstyper. | Även kort rensning av Hubs är formellt oreglerad gallring utan dokumenterat gallringsbeslut (GAP-008/026). | `[KONFIG]` per kommun / `[BYGGS]` (grind) |
| **K-7.22** | **Varje `ArendeTyp`-rad MÅSTE ha icke-null `commitDestination`** (`facksystem \| diarium \| e_arkiv \| extern_myndighet \| triage_forward \| karantan`). `retentionState`-flippen (R8) får aldrig ske utan att ett av de fyra SoR-utfallen är explicit registrerat. | Dödligaste felmoden (9/12 roller): Hubs blir SoR genom passivitet = skuggregister utan gallringsklocka. | `[BYGGS]` / `[KONFIG]` (destination per typ) |
| **K-7.23** | Retention SKA kunna **pausas** automatiskt vid registrerad utlämnandebegäran (TF). En gallringsklocka får aldrig radera en handling som någon begärt ut innan prövning. | TF: begärd allmän handling får inte gallras under pågående prövning (GAP-031, blocker). | `[BYGGS]` |
| **K-7.24** | `retentionAnkare` SKA vara **lösgjort från commit** för fall där bevarandet styrs av annat än överföring (kamera-/lagstadgad lagringstid, R10). Ankaret datadrivet per handlingstyp. | Säkerhets-/beredskapsdomänen har retention bunden till lagstadgad tid, inte commit. | `[BYGGS]` |
| **K-7.25** | Frister SKA modelleras som **`fristPolicy`-struct** `{typ, ankare, milstolpar[], forlangningsbar, bindande:'lag'\|'avtal'}`. Ankaret SKA vara **verksamhetskorrekt**: 14-dgr förhandsbedömning + skyddsbedömning ankras i **inkom-datum** (ej tilldelning/plock); PBL-frist i `komplett_ansokan`; överklagandefrist (FL 44 §) ur valt **delgivningssätt**. | Fel referenspunkt ger falsk trygghet; fristen löper från att anmälan inkom (JO-praxis, GAP-002/046/055/039). | `[BYGGS]` |
| **K-7.26** | `fristPolicy` SKA stödja **multi-milstolpe med ankare = vetskap/kännedom**: PU-incident 72h (art. 33) ur kännedom, NIS2 24h→72h→30d, Lex Sarah ≤5v, FK-plan dag-30, visselblåsning (bekräfta ≤7 dgr / återkoppla ≤3 mån). En händelse kan starta **två klockor** (ransomware m. PU = IMY 72h **och** MSB 24h). | Externa frister har olika ankare och kan vara parallella. | `[BYGGS]` |
| **K-7.27** | När fristbärande post markeras "förd till facksystemet" SKA kvarvarande Hubs-påminnelser (VALARM/Deck/VTODO) **avaktiveras** så facksystemet blir ensam fristägare; Hubs SKA spegla, inte räkna egen frist parallellt. | Dubbelbevakning ger två konkurrerande röda datum; Hubs-räknad frist kan divergera från giltig förlängning (GAP-018/044/047). | `[BYGGS]` |

### 7.5 Delgivning, kommunicering & utlämnande

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-7.28** | Hubs SKA **spåra delgivningssätt** (vanlig / förenklad / digital brevlåda), inte påstå att en teknisk "Läst"-notis fullbordar delgivning. Stödja tidsstyrt flöde (kontrollmeddelande, 2-veckorsfiktion, förhandsupplysning) per delgivningslagen (2010:1932). | Läsnotis = bevisning, inte fullbordad delgivning. Mina meddelanden/Kivra-rättsläget (SOU 2024:47) ska verifieras. | `[BESLUT KRÄVS]` + `[BYGGS]` |
| **K-7.29** | Vid kommunicering/säker delning SKA `arenderum` ge **maskerings-/sekretessprövningsstöd**: välj utvalda handlingar, varna för tredjemansuppgifter. Delning av hela rummet får inte vara default. | Att dela fel fil ur ett känsligt rum = flödets allvarligaste enskilda felrisk (GAP-017, major). | `[BYGGS]` |
| **K-7.30** | UI SKA formulera samtycke korrekt: samtycket dokumenterar *information/transparens*, det **häver inte sekretess** och är **inte** rättslig grund (rättslig grund = myndighetsutövning, art. 6.1.e). | OSL/sekretess kan inte avtalas bort; fel UI-formulering bygger in juridisk felföreställning (GAP-023). | `[KONFIG]` (UI-text) |
| **K-7.31** | Fasvalidering SKA finnas i datamodellen: under förhandsbedömningsfas SKA widgeten varna/spärra mot uppgiftsinhämtning från otillåten part (endast vårdnadshavare/anmälare/barn). | Den rättsliga begränsningen vilar annars helt på handläggarens kunskap (GAP-006/013). | `[BYGGS]` |

### 7.6 PuB, personuppgiftsansvar, e-arkiv & datalagrets juridiska gräns

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-7.32** | En **PuB-/ändamåls-/regim-matris** SKA ligga i `ArendeTyp`-config från dag ett: per rad `lagligGrund`, `personuppgiftsansvarig` (nämnd/myndighet, ej handläggare), `andamaalsbegransning`, `gdpr_art9`, samt `vardgivarSpar`/`regimAxel` som håller HSL/SoL fysiskt åtskilda. | Utan matrisen är Hubs juridiskt osäljbart (PuB oundertecknbart, RoPA omöjlig); regim-blandning blir inbyggd incident. Δ17. | `[BYGGS]`/`[BESLUT KRÄVS]` (innehåll per kund) |
| **K-7.33** | PuB-förhållandet till driftleverantören SKA regleras i **biträdesavtal**; Hubs-/favoritdata får inte användas för andra ändamål. Loggrader (activity) SKA minimeras (referens/ID + händelse, ej PII) + **egen gallringsfrist** så loggen ej blir PII-skuggregister. | Personuppgiftsansvar = nämnden; biträdet regleras avtalsmässigt. Full audit-logg utan minimering = parallellt PII-register. | `[KONFIG]` (avtal)/`[BYGGS]` (logg-minimering) |
| **K-7.34** | E-arkivering SKA ske via **FGS-paketering** med tydlig **ansvarsgräns**: beslut committade till facksystemet e-arkiveras *via facksystemet* (Treserva→e-arkiv); Hubs FGS-export (mönster C) = reserv endast för det som *bara* bor i Hubs. Hubs får aldrig dubbelarkivera. | Oskarp ansvarsgräns ger dubbelarkivering eller dubbla sanningar (GAP-040). | `[KONFIG]` per kund / `[BYGGS]` (FGS-export) |
| **K-7.35** | Externa myndighets-"commits" (IMY/MSB/IVO/FK/Länsstyrelse/polis) SKA behandlas som **egen integrationsklass ≠ facksystem**: `commitMode='extern_anmalan'` (Hubs förbereder, människa lämnar in), ingen dnr-callback, ankare = vetskap, ofta PII-maskning, flippar **inte** `retentionState`. | En tillsynsrapport är inte commit till SoR — får inte trigga gallring av Hubs-kopian. | `[BYGGS]` |
| **K-7.36** | Det juridiska kravet på **single-writer + spårbar, append-only kvittens-/audit-logg** för registret SKA respekteras oavsett om registret bor i sdkmc:s NC-app-DB (start) eller separat ExApp-DB (mål). Audit-/handover-spåret = **enda** legitima SoR-roll. | Retention-/gallrings-/bevisgarantierna hänger på en betrodd, oförvanskbar logg; Tables underkänt. Var registret bor = även AGPL-gräns. | `[BYGGS]` (sdkmc-DB)/`[BESLUT KRÄVS]` (ExApp-lyft) |

---

## 8. DEMO/STUBBAR, GAP/BLOCKERARE & PRIORITERAD BYGGPLAN

> **Områdeskod:** `BYGG`. Handover-underlaget för bygg-tråden. Djupdok: `DEMO-STUBS.md` (seam-registret), `HUBS-INTERNALS §1–§5` (motorn, kodkartan, readiness), `SOCIALSEKRETERARE-WALKTHROUGH-V2.md` (GAP-001–066). **Nettobild:** sdkmc är en mogen kanal-/tagg-/typ-/retention-/Flow-/korg-motor med riktiga OCS-routes — men **ärende-funktionen** (registret, atomär skapa/fördela-orkestrering, facksystem-konnektorn, commit-bunden gallring, tre-lagers-ACL-koherens) är **inte byggd**; den lever som demo-stubbar i `hubs_start/src/services/demo/`. **Handover = bygg backend bakom de namngivna seams, inte rita om ytan.**

### 8.1 Demo/stubbar — vad FINNS, vad är stub, hur demoläget grindas

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-8.1** | Behåll den fysiska separationen demo-gren ↔ prod-gren i `api.js`: varje av de 27 exportfunktionerna har `if (DEMO) return <fixtur>` följt av redan skriven `await axios(<OCS-route>)`-gren. Att gå skarpt = låta `DEMO` bli `false`; **rör inte ytan**. | `DEMO = isDemo()` läser `boot.demoMode`. Prod-grenarna pekar redan på avsedda sdkmc-OCS-routerna. | `[FINNS]` (grind)/`[BYGGS]` (routes bakom) |
| **K-8.2** | Behåll tre-läges demo-grinden i `PageController::isDemoMode()`: app-config `hubs_start/demo_mode` `'1'`=forced ON, `'0'`=forced OFF, tomt=AUTO (ON när sdkmc saknas via `AppDetectionService`). | Gör att hela UI:t kan visas på vanilla NC utan syskon-apparna. | `[FINNS]` |
| **K-8.3** | Stateful affärslogik demon behöver SKA förbli isolerad i `src/services/demo/` (`treserva.js`, `favoriter.js`, `socialsekreterare.js`) och får **aldrig** smyga in i prod-grenarna. | `treserva.js` håller in-memory `REGISTER`-Map + `RECEIPTS`-array; `seedRegister`/`_dumpRegister` faller bort i prod. | `[FINNS]` (endast demo) |
| **K-8.4** | Det kritiska *mönstret* i stubben `commitHandling()` — retention startar ENBART på verifierad commit-callback, aldrig på kryssruta — SKA bevaras som referens-kontrakt (GAP-007). Store-action `commitArende()` konsumerar `{ok, dnr, gallrasDatum, verifierad, receipt}` — **samma shape** som prod ska returnera. | Frontend oförändrad när backend byts. | `[FINNS]` (mönster)/`[BYGGS]` (skarp bindning) |

### 8.2 Seam-registret — var varje stub byts mot skarpt

Auktoritativ källa: `DEMO-STUBS.md §2–§4`. Varje seam-id mappar 1:1 mot en `🔌 SEAM[<id>]`-markör (sök på `SEAM[`).

| KRAV | Seam | Prod-ersättning | Status |
|---|---|---|---|
| **K-8.5** | `treserva` + `treserva.skapa` | `POST {SDKMC_OCS}/arende` med `{rad}` — ETT anrop: register-INSERT + ärenderum + ACL + Deck-kort + chattrum + 14-dgr-klocka **atomärt** (sagan). | `[BYGGS]` (GAP-019/056/010) |
| **K-8.6** | `treserva.commit` | `POST {SDKMC_OCS}/treserva/commit` → Frends → facksystem → **verifierad callback**; sdkmc flippar `provenanceState='registrerad'` + sätter retention-tagg **först då**. Konvention `/api/v2/...`. | `[BYGGS]` `[EXTERN]` (GAP-019/007) |
| **K-8.7** | `treserva.koppla` | `POST {SDKMC_OCS}/inflode/koppla` — sätter systemtaggen `case:{id}` + speglar filen till ärenderummet. | `[BYGGS]` (GAP-019) |
| **K-8.8** | `treserva.tombstone` | Reconciliation-jobb register↔objekt + integritets-larm; deny-by-default i aggregeringen. | `[BYGGS]` (GAP-056) |
| **K-8.9** | `favoriter` + `favoriter.tombstone` | `GET {SDKMC_OCS}/favoriter` — tunt resolverlager: `IManager::search` över personlig ∪ funktions-delad favorit-adressbok + batch-resolve mot DIGG-cachen → `{färska fält, resolvedAt, stale, removed}`. **Hård fail-closed** när DIGG ej nås. | `[BYGGS]` (GAP-061/063/064) |
| **K-8.10** | `treserva.seed`, `demodata-fixtures`, `PageController demo-boot`, `deepLinks inerta i demo` | **Ingen prod-ersättning** — rena demo-konstruktioner. | `[FINNS]` (endast demo) |
| **K-8.11** | Övriga OCS-aggregat-routes `api.js` redan kallar: `GET /arende-summary`, `/inflode-summary`, `/fordelning-summary`, `/treserva/receipts`, `POST /arende/{ref}/tilldela`. | Alla under `apps/sdkmc/api/v2`. Controllern = `lib/Controller/ArendeController.php`, bredvid `itsl_tag#*`-routerna. | `[BYGGS]` |

### 8.3 Vad som FINNS i sdkmc vs vad som BYGGS

| KRAV | Krav | Motivering | Status |
|---|---|---|---|
| **K-8.12** | Bygg **inte** om det som finns: tagg-motorn (`ItslTagService` + `sdkmc_itsl_tag`/`sdkmc_itsl_message_tag` + `ItslTagController`/`TagFileController`/`TagSearchHelper`), deterministisk typklassning (`MessageTypeService`, **ingen LLM**), trådning (`MessageThread`), kvittenser (`MessageReceipt`), retention/gallring (`MailboxRetention`/`ExpungeService`/`ExpungeJob`/`DeleteTagsJob`), Flow-reg (`RegisterOperationsListener`/`RegisterChecksListener`/`Loa3`), DIGG-synk (`UpdateAddressBookService`), korgar (`ConsolidateMailboxesService`/`ProvisionPersonligAccountsService`), "viktig"-klassning (`MessageImportantClassifiedListener`). | Produktionsmässig körande kod. Återanvänd som-den-är; ny orkestrering anropar dessa. Email-scopningen i `ItslTag` är medvetet rätt för delade ärenden. | `[FINNS]` |
| **K-8.13** | Bygg `sdkmc_arende`-registret med rad-shapen (se K-3.5). sdkmc = **ensam skrivare** (single-writer, samma som `ItslTagService`). | Join-nyckeln för hela stacken; `grep sdkmc_arende` ger 0 träffar idag. Lever som in-memory `REGISTER`. | `[BYGGS]` (GAP-056) |
| **K-8.14** | **[BESLUT KRÄVS]** Datalager: Tables (rows-API) **vs** egen sdkmc-app-DB (`lib/Db/Arende.php` + `ArendeMapper.php` + `Migration`) **vs** AppAPI/ExApp. Single-writer-disciplinen densamma; transaktions-/backup-/ACL-egenskaperna skiljer. **Rekommendation: egen sdkmc-DB nu (alt. b), ExApp-rent schema, ExApp-DB (c) som mål.** **Fatta detta först — allt annat hänger på det.** | sdkmc använder redan proper NC-app-DB för tag-tabellerna → egen Mapper ger transaktionell INSERT i sagan; Tables ger admin-vy men svagare transaktion. | `[BESLUT KRÄVS]` |
| **K-8.15** | Bygg `ArendeService::createCase()` som **distribuerad saga** med kompensering (se K-3.9/3.10). Idempotensnyckel = utlösande `conversationId`. 14-dgr-frist bunden till **inkom-datum** (`conversationId`, ej `now()`). | Ingen `IDBConnection`-transaktion kan rulla tillbaka Spreed-rum/mapp → måste vara saga. | `[BYGGS]` (GAP-010/057) |
| **K-8.16** | Bygg `ArendeMatchService` (lyssnar på `MessageReceivedEvent`) med deterministiska matchningskaskaden (se K-4.10). Utfall mot **server-side** tröskel → `hor_till`/`foreslagen`/`ej_kopplat`/`nytt`. **Bilaga speglas vid bekräftelse, inte förslag.** LLM-lagret valfritt, alltid <tröskel, aldrig autonomt på sekretess. | Axel C är enda axeln motorn saknar. Felkoppling = sekretessincident → default `ej_kopplat`. | `[BYGGS]` (GAP-060) |
| **K-8.17** | Bygg `TreservaCommitService` → **`FacksystemCommitService`** (per-produkt-konnektorer): Frends-flöde bakom `POST /treserva/commit` med verifierad callback → flippa provenans + para dnr 1:1 (1:n syskon) + spegla `fristDue` + `retentionState='gallras_efter_commit'` **först då**. dnr-parning är **inte** del av skapa-sagan; sker asynkront vid CommitGrind. | `commitToTreserva()` är hårdkodad stubb. En Treserva-konnektor blir mall för 5–10 produktkonnektorer → `Treserva`-namnet är fel. | `[BYGGS]` `[EXTERN]` (GAP-019, tyngst) |
| **K-8.18** | Bygg `ArendeReconciliationJob` (`lib/BackgroundJob/`): (1) varje pekare existerar, (2) varje `case:X`-objekt har registerrad, (3) `conversationIds[]` matchar `sdkmc_itsl_message_tag`. Mönster: `ItslTagService->processAllPendingDeletions()` driven av `DeleteTagsJob`. Deny-by-default vid divergens. | Token bärs på två ställen → kan driva isär. | `[BYGGS]` (GAP-056) |

### 8.4 Konsoliderad gap-/blockerar-lista

**Blocker** = skarp körning kan ge rättslig/sekretess-skada. Severitet/status verifierade mot `WALKTHROUGH-V2 §gap-analys` + `HUBS-INTERNALS §5`.

#### 8.4.1 De sex ursprungliga blockerarna (oförändrat icke-byggda)

| KRAV | GAP | Krav | Status |
|---|---|---|---|
| **K-8.19** | **GAP-019** (tyngst) | Bygg hela Frends/facksystem-flödet med **verifierad callback**. Grundorsak bakom commit/gallring/spegling. | `[BYGGS]` `[EXTERN]` |
| **K-8.20** | **GAP-007** | Flytta retention-start från tid/tagg (`ExpungeJob`) till faktisk verifierad callback. | `[BYGGS]` |
| **K-8.21** | **GAP-001** | Tvingande backend-grind att skyddsbedömningen (11 kap. 1 a § SoL) **committas** till facksystemet. UI-pliktmarkören finns; backend-grinden saknas. | `[BYGGS]` |
| **K-8.22** | **GAP-034/035/037/033** | Bygg Inera Underskriftstjänst / Sweden Connect + robust LTV + kvalificerad tidsstämpel. LibreSign-AES ≠ svensk myndighets-AES; `ltv:true` är demoflagga. | `[BYGGS]` `[EXTERN]` |
| **K-8.23** | **GAP-052** (policy) | AI/transkribering får **ej** köra skarpt på sekretessbelagt innehåll förrän IMY/SKR/Socialstyrelsen gett vägledning. Lokal KB-Whisper + human-in-the-loop. | `[BYGGS]` `[EXTERN]` |
| **K-8.24** | **GAP-031** (drift) | Bygg paus-hook "pausa retention vid registrerad utlämnandebegäran (TF)". `retentionState='pausad'` är idag enum-värde utan trigger. | `[BYGGS]` |

#### 8.4.2 De tre nya arkitektur-blockerarna (registret som single point of truth)

| KRAV | GAP | Krav | Status |
|---|---|---|---|
| **K-8.25** | **GAP-056** | Transaktionell saga/kompensering + reconciliation + integritets-larm + arkivkritisk backup/återställning av `sdkmc_arende`. Om registret driftar tappar *alla* appar ärendekopplingen samtidigt. | `[BYGGS]` |
| **K-8.26** | **GAP-057** | Gör `tilldela`/fördelning till atomär multi-objekt-commit med lås/idempotens på `hubsCaseId`: ACL revoke (mottagning) → grant (utredare) **utan sekretessfönster**; serialisera per ärende; verifiera ACL-slutläge innan kortet lämnar "Att fördela". | `[BYGGS]` |
| **K-8.27** | **GAP-058** | **En** kanonisk ACL-källa per enhet/funktionsadress som genererar alla tre lagren (`case:`-tagg ∩ Groupfolder-ACL ∩ Tables-vy = samma sanning) + automatiserat koherens-test + deny-by-default i aggregeringen. Circles-team↔ACL↔Talk-deltagare synkad. | `[BYGGS]` |

#### 8.4.3 Favorit-/kontakt-gapen (major)

| KRAV | GAP | Krav | Status |
|---|---|---|---|
| **K-8.28** | **GAP-061** | Tunt resolverlager `GET /favoriter` + obligatorisk färsk-resolve-vid-läsning + **hård fail-closed** när DIGG ej nås. Tombstone-gallring mot skuggregister. | `[BYGGS]` |
| **K-8.29** | **GAP-062** | Favoritlistor som egna handlingstyper i DHP (arkivlag §10); tvinga funktions-ägare på klass (b) fax-vCard; årlig översyn; laglig grund art. 6.1.e. | `[BYGGS]` (policy+process) |
| **K-8.30** | **GAP-063** | Vidarebefordra-via-favorit måste passera samma "registrera först?"-bedömning som `GallringsGrind`; logga mottagar-LOA + provenans; blockera vidarebefordran av ej-resolvbar favorit. | `[BYGGS]` |
| **K-8.31** | **GAP-064** | Server-side klass-validering (a/b/c) som **avvisar fri medborgar-PII-favoriter** och styr dem till ärendet; villkorat ärenderums-scoped undantag; aktivitetslogg. | `[BYGGS]` |

#### 8.4.4 Övriga gap (major/minor — adresserbara, ej skarp-blockerande)

| KRAV | GAP | Krav | Status |
|---|---|---|---|
| **K-8.32** | **GAP-010** | Atomär `createArende()`-orkestrering (ett sdkmc-anrop). Delvis stängd på spec/UI; backend-sagan kvarstår. | `[BYGGS]` |
| **K-8.33** | **GAP-059** | Ärendechatt-retention bunden till ärenderummet + "är detta en handling?"-grind vid avslut + server-side avidentifiering vid "Lyft till enhetschatt". | `[BYGGS]` `[FORK]` |
| **K-8.34** | **GAP-038/039** | Modellera delgivningssätt; härled överklagandefristens startdatum ur valt sätt. Läskvittens ≠ juridisk delgivning. | `[BYGGS]` |
| **K-8.35** | **GAP-018/044/047** | `fristDue` ska speglas ur facksystemet (läskonnektor), ej självständigt räknas; riv Hubs-påminnelse när facksystemet tar över. | `[BYGGS]` |
| **K-8.36** | **GAP-012/032/014** | **[BESLUT KRÄVS]** Var skrivs utredningstexten — Collabora-i-Hubs vs direkt i facksystemets journal? Dubbel-författande-risk. | `[BESLUT KRÄVS]` |
| **K-8.37** | **GAP-065/066** | Server-side omnämnande-aggregat + kvittensmodell (puls får ej bli andra inkorg); globalt frist-larm som överlever korg-/typ-filter. | `[BYGGS]` (minor) |
| **K-8.38** | **GAP-016/021/024/025/030/045 + GAP-040/011/023/036** | Forms-brygga, oautentiserad bokning, BankID-lobby (`spreed-itsl`-fork), recording-server, svensk live-STT, Deck-påminnelse-motor; FGS-e-arkivgräns, BBIC-licens, kravnivå-matris. Drift-/integrations-/per-kund-beroenden. | `[BYGGS]`/`[KONFIG]`/`[EXTERN]` |

### 8.5 Bygg-deltat Δ1–Δ17 — generaliseringen till flera kommunroller

Källa: `KOMMUNROLLER §2/§5/§7`. Δ-fälten gör den socialtjänst-specifika motorn datadriven över 12 roller. **~85 % rena datafält, ~15 % avgränsad motor-utökning.** Den hårda nyheten = de **terminerande "föds inte"-utfallen** (Δ7/Δ8) + **temporal sekretess** (Δ10) — dessa berör motorns kärna.

| KRAV | Δ | Krav | Status |
|---|---|---|---|
| **K-8.39** | **Δ1 `systemOfRecord`** | Varje `ArendeTyp`-rad MÅSTE ha icke-null `commitDestination` ∈ {facksystem, diarium, e-arkiv, extern_myndighet, triage_forward, karantan, ingen}. Ersätt implicit "alltid Treserva". Retention-flippen (R8) får aldrig ske utan registrerat utfall. | `[BYGGS]` (motor+data) |
| **K-8.40** | **Δ2 `sekretessGrund[]` + temporalitet** | Ersätt skalär `sekretessNiva` med struct `{grund, niva, temporal:{utgangsTrigger, utgangsDatum}}`. | `[BYGGS]` (data) |
| **K-8.41** | **Δ3 `riskKlass`** | Risknivå ortogonalt mot ärendetyp; styr eskalering, ej bara åtgärdsknappar. | `[BYGGS]` (data) |
| **K-8.42** | **Δ4 `externMyndighetMottagare` + `commitMode`** | Commit-mål utanför kommunen (IMY/MSB/JO). `commitMode='extern_myndighet'` flippar **inte** `retentionState`; ingen dnr-callback, ankare=vetskap, ofta PII-maskning. | `[BYGGS]` (data) |
| **K-8.43** | **Δ5 `verksamhetsgren` + `sekretessMur`** | Mur (binär gräns), inte gradient, mellan verksamhetsgrenar (IFO↔skola, SoL↔HSL). | `[BYGGS]` (data) |
| **K-8.44** | **Δ6 `partsModell` utökad + `joinNyckel`** | Nya värden: `objektsärende` (`upphandlingsRef`/`avtalsRef`/`objektRef`), `flerpartsärende`, `anonym_begaran`, `handling_med_sekretessbilaga`. `joinNyckel` generaliserar `barnRef`. | `[BYGGS]` (data) |
| **K-8.45** | **Δ7 `karantanKravs` / säkerhetsskydds-grind** | `karantanKravs=true` → terminerande `preSagaHook='avvisa_sakerhetsskydd'` **FÖRE R2** (vägrar mint:a, Spreed-rum, groupfolder). **Den enda Δ som berör motorn hårt.** Säkerhetsskyddsklassat får **inte vara i systemet alls** — lagbrott att behandla som skyddade PU. Roll 1/3/5/8/9/10/11/12. | `[BYGGS]` (motor) |
| **K-8.46** | **Δ8 `felmottaget_men_kansligt`** | Bool → karantän-vidarebefordra-grind (anti-saga) + **retroaktiv isolering** (river redan satta taggar ur index/Spreed/Groupfolder/tag-DB). Farligaste vägen = inkommande. Roll 1/10/11/12. | `[BYGGS]` (motor) |
| **K-8.47** | **Δ9 Visselblåsning som NON-route** | Tre hårda opt-outs (ArendeMatchService, ConsolidateMailboxesService, egen ACL-domän `vissel_isolerad`). Får ej passera sagan. **Medveten ICKE-integration** mot Lantero/WhistleB. Roll 1/3/9/11. | `[BYGGS]` (motor, opt-out) |
| **K-8.48** | **Δ10 Temporal ACL** | `postCommitHook='slapp_anbudssekretess'` + temporal degradering av **hela `case:`-klustret** (Spreed-historik + Deck-kort) vid extern händelse (tilldelningsbeslut). Bakåt-läckan. Roll 1/8/9/11. | `[BYGGS]` (motor) |
| **K-8.49** | **Δ11 `fristPolicy` multi-milstolpe + ankare** | `{typ, ankare, forlangningsbar}`: ankare ∈ {inkom-datum, kännedom, komplett_ansokan, delgivning}; multi-milstolpe (NIS2 `24/72/30`), `avtalslivscykel`, `skyndsamt` (TF 2:16). Roll 5/8/9/11. | `[BYGGS]` (data+motor) |
| **K-8.50** | **Δ12 `forstaAtgard`-enum utökad** | `menprovning`, `radgivning`, `rattidsprovning`, `triage`, `karantan_vidarebefordra`, `sekretessprovning`. Roll 1/11/12. | `[BYGGS]` (data) |
| **K-8.51** | **Δ13 `frendsModul` öppen enum** | Per-modul → **per-system** (2-dim: modul × produkt). Värden bortom socialtjänst: `diarium`, `avtal`, `byggr`, `ecos_*`, `crm_kontaktcenter`, `ingen`. `FacksystemCommitService` = bibliotek av per-produkt-konnektorer (GAP-019-klass ×5–10). Roll 1/8/9/11/12. | `[BYGGS]` `[EXTERN]` |
| **K-8.52** | **Δ14 `diariePlikt`** | ∈ {direkt, bedoms, villkorlig, registreringsbeslut}. Tyst icke-diarieföring = spårad åtgärd (annars otillåten gallring av allmän handling, TF). Roll 1/8/11/12. | `[BYGGS]` (data+grind) |
| **K-8.53** | **Δ15 Dubbel commit-destination** | Diarium → e-arkiv som sekventiella commits (`postCommitHook='arkivera_fgs'`); samordnad tillsyn PBL↔MB = **två commits utan akt-sammanslagning**. Roll 1/8. | `[BYGGS]` (motor) |
| **K-8.54** | **Δ16 `sorFallback`** | När kategori saknar facksystem (DPIA, OSA, MBL-protokoll, RSA) → datadriven, granskningsbar fallback (diarium/e-arkiv) **uttryckligen flaggad** + tvingande exit-krav. Roll 3/5/9/10/11. | `[BYGGS]` (data+grind) |
| **K-8.55** | **Δ17 PuB/RoPA + `leverantorskontroll`-hook** | Datadriven hook för PuB-avtal/RoPA (roll 5) + leverantörskontroll/Inyett-flagga (roll 9) som `preSagaHook`. | `[BYGGS]` (data) |

---

## 9. BESLUTSLOGG — alla "BESLUT KRÄVS" samlade

Alla öppna beslut, samlade. **Arkitektur-/affärsbeslut** (B-*) måste fattas innan/tidigt i bygget; **per-kommun-/tenant-beslut** (T-*) kan fattas per kund vid driftsättning; **jurist/extern** (J-*) är externa förutsättningar.

### 9.1 Arkitektur- & affärsbeslut (fattas FÖRE/tidigt i bygget)

| ID | Beslut | Beror på / blockerar | Källa-krav |
|---|---|---|---|
| **B-DL-1** | **Internt datalager:** bekräfta Tables underkänns; bygg registret i **(b) sdkmc:s NC-app-DB nu** (QBMapper/Migration, ExApp-rent schema), (c) ExApp-DB som mål. **Blockerar all motor-implementation. Fatta FÖRST.** | GAP-056/057/058 | K-2.13/14, K-3.3/4, K-8.14 |
| **B-MOD-1** | **sdkmc-uppdelning M0/M1 FÖRE M4:** bryt monoliten innan ärende-motorn byggs (kod-refaktor, ej data-migration). | Annars cementeras monoliten; "sälj M4 separat" osant | K-1.18, K-2.2, K-3.28 |
| **B-INV-1** | **`commitDestination` som NOT NULL-constraint** i `sdkmc_arende_typ` (hård DB-constraint vs validerings-grind i `ArendeService`). | Tvingande invarianten mot tyst de-facto-SoR | K-1.6, K-2.19, K-3.23, K-4.21, K-7.22, K-8.39 |
| **B-SEC-1** | **Säkerhetsskydds-grindens semantik + detektion:** hur klassificeras inkommande som säkerhetsskydd *före* R2 (deterministisk regel vs manuell flagga vs avsändar-LOA)? Är retroaktiv karantän testad? Inkl. regimbyte höjd beredskap. **Lagbrottsgolv, ej UX-fråga.** | Hårdaste arkitektoniska gränsen (8 roller) | K-1.10, K-4.19, K-5.20–24, K-7.9–11, K-8.45/46 |
| **B-DOK-1** | **Var skrivs utredningstexten** — Collabora-i-Hubs vs direkt i facksystemets journal (dubbel-författande-risk). | Avgör vad som speglas | K-8.36 (GAP-012/032/014) |
| **B-API-1** | **Route-versionering:** nya ärende-routes som `/api/v2/arende*` (verifierad prefix i `sdkmc/appinfo/routes.php`), **inte** `/api/v1/` som designdoken antydde. | Bekräfta vid bygg | K-3.27, K-8.11 |
| **B-HOOK-1** | **Hooks vs fork för kat 6 & 8:** bekräfta att gränsfallen löses med deklarerade pre/post-hooks inom samma motor (rekommenderat). | Motor-design | K-3.20 |
| **B-MATCH-1** | **Server-side konfidenströskel:** flytta auto-koppling/-klassning från klient-/demo-logik (`≥0.9`) till granskad server-policy med obligatorisk människo-bekräftelse; bilaga speglas vid bekräftelse, ej förslag. | Säker "hör_till"-utfall (GAP-060) | K-4.8, K-8.16 |

### 9.2 Licens-/juristbeslut (externt, blockerar proprietär modell)

| ID | Beslut | Tagg | Källa-krav |
|---|---|---|---|
| **B-LIC-1** | **Proprietär M4-licensstatus:** om M4-motorn ska kunna vara proprietär krävs ExApp arm's-length-arkitektur + privat distribution + **IP-jurist-verifiering** (combined work vs separate program; mail `-only` vs spreed `-or-later`; securemail egen licens; §13-skyldigheter; ingen AGPL-bundling). **Inget proprietärt beslut på enbart arkitektur-PM.** | `[BESLUT KRÄVS]` / `[EXTERN]` (jurist) | K-2.10/12, K-5.41 |
| **B-EXAPP-1** | **(c) ExApp-lyft tidpunkt:** bygg inte i förskott, men designa (b) ExApp-rent. När/om lyftet sker kopplas till licens-/affärsmålet i B-LIC-1. | `[BESLUT KRÄVS]` | K-2.15, K-3.4 |
| **B-SKU-1** | **Fastställ de fyra SKU:erna** ovanpå obligatorisk M0: M1 (ankare) · M2 · M3 · M4 (= M0+M1+motor; M2/M3/Kontakter tillval). | `[BESLUT KRÄVS]` (affär) | K-1.17/19, K-2.1/4 |
| **B-SIGN-1** | **Inera-anslutningens avtals- & profilfråga:** kundavtal + SITHS/HSA-anslutning (veckor–månader); mTLS vs OOB (GAP-033); hash-baserad profil verifieras mot Inera. + kravnivå-matris per dokumenttyp + `SignMessage`-texter (juristgranskning). | `[BESLUT KRÄVS]` / `[EXTERN]` | K-7.14/17/18/19 |

### 9.3 Per-kommun-/tenant-beslut & extern myndighetsvägledning

| ID | Beslut | Tagg | Källa-krav |
|---|---|---|---|
| **T-SOR-1** | **Fallback-SoR per (c)-kategori per kommun** — allmän handling→diarium ELLER arbetsmaterial→(iii) tidsbegränsat mellanlager med gallringsregel + rättslig grund. Måste sättas innan retention-flippen får ske. | `[KONFIG]`/`[BESLUT KRÄVS]` | K-5.5/7, K-7.20, K-8.54 |
| **T-VERKTYG-1** | **Köp av fackverktyg vs diarium-fallback** — Draftit/DPOrganizer för DPIA (R5); avvikelsemodul för MAS (R2); incidentmodul (R6). | `[BESLUT KRÄVS]` | K-5.14, K-8.54 |
| **T-RET-1** | **Gallringströskeln (B-RET):** får Hubs gallra på manuell "markera överförd", eller krävs alltid verifierad commit-callback? Säkerhetsmarginal/andrahandläggar-bekräftan för fristbärande poster. | `[BESLUT KRÄVS]` | K-7.20, K-8.20 |
| **T-DHP-1** | **DHP-källa för `GallringsGrind`** (kommunens dokumenthanteringsplan): konfig-fil, sdkmc-tabell, eller extern integration? Blockerar att "Gallra"-vägen blir skarp. | `[BESLUT KRÄVS]`/`[KONFIG]` | K-6.10, K-7.21 |
| **T-VISSEL-1** | **Visselblåsar-isolering** — verifiera opt-out ur `ArendeMatchService` + `ConsolidateMailboxesService` + aggregat är **testad** (inte bara konfigurerad). | `[BESLUT KRÄVS]`/`[KONFIG]` | K-5.26/27, K-7.12 |
| **T-PUB-1** | **PuB-/ändamåls-/regim-matrisens innehåll** — laglig grund, personuppgiftsansvarig, art. 9-flagga per `ArendeTyp` × kund. | `[BESLUT KRÄVS]` | K-5.29, K-7.32 |
| **T-LAS-1** | **`commitMode='las_konsument'` default-deny för skriv** mot Pascal/NPÖ/SVOD — bekräfta att ingen konnektor får skriv-API mot annan vårdgivares SoR. | `[BESLUT KRÄVS]` | K-5.13 |
| **T-2028-1** | **2028-skiftet (R4)** — reservera integrationspunkt mot nationellt ställföreträdarregister (prop. 2025/26:92); behörighets-SoR flyttar ut ur kommunen. | `[BESLUT KRÄVS]`/`[EXTERN]` | K-5.19 |
| **T-SORPROD-1** | **`sorProdukt` per tenant** (R2/R4/R7/R8) — multi-tenant: små kommuner slår ihop roller, SoR-produkt varierar. Per-tenant ACL-defaults + funktionsadresser. | `[KONFIG]`/`[BESLUT KRÄVS]` | K-5.15/33 |
| **T-DELG-1** | **Delgivningssätt-modellen** — rättsläget för digital brevlåda (Mina meddelanden/Kivra, SOU 2024:47) verifieras per beslutstyp innan UI påstår delgivning. | `[BESLUT KRÄVS]`/`[EXTERN]` | K-7.28, K-8.34 |
| **T-AI-1** | **Klassningströskel + lokal LLM-assist** per kund — beror på IMY/SKR/Socialstyrelse-vägledning för AI på sekretessbelagt innehåll. Demo-konstanten `≥0.9` får ALDRIG nå produktion som klientlogik. | `[BESLUT KRÄVS]`/`[EXTERN]` | K-4.7/8, K-8.23 |
| **T-ORG-1** | **Org-/avsändarregistrets källa** (Axel B-signal b) — DIGG-synk à la `UpdateAddressBookService`, manuellt kuraterat, eller hybrid? Avgör hur stark avsändartyp-priorn får vara. | `[BESLUT KRÄVS]`/`[KONFIG]` | K-4.6 |
| **T-SAMTYCKE-1** | **Begränsningsåtgärder/samtycke (R7)** — bekräfta att registret är bevis-för-frivillighet, ej legitimering av tvång; eskalera till Lex Sarah; Hubs aldrig auktoritativt samtyckesregister. | `[BESLUT KRÄVS]` | K-7.30 (KOMMUNROLLER §5.3 Kluster B) |

---

## 10. ROADMAP — prioriterad byggordning med beroenden

Härledd ur `HUBS-INTERNALS §4` + `DEMO-STUBS §5` + `KOMMUNROLLER §7`. **Allt hänger på registret + konnektorn; bredd (Δ-fält) kommer efter att en vertikal är skarp.** En vertikal skiva (socialsekreterare end-to-end) före breddning till övriga roller.

### P0 — Fundament & säkerhetsgolv (blockerar allt annat)

| Steg | Vad | Krav | Beror på |
|---|---|---|---|
| **P0.0** | **Datalager-beslut** (B-DL-1) — Tables underkänns, registret i (b) sdkmc-DB, ExApp-rent schema. | K-8.14 | — (fatta först) |
| **P0.1** | **sdkmc-brytning M0/M1** (B-MOD-1) + flytta taggmotorn till M0. Kod-refaktor. | K-1.18, K-2.2/3 | P0.0 |
| **P0.2** | **`FacksystemCommitService` + Treserva-konnektor** (GAP-019) med verifierad callback. Tyngst; grundorsak bakom commit/gallring/spegling. | K-8.17/19 | P0.0 |
| **P0.3** | **`sdkmc_arende`-registret** + single-writer + Migration + Mapper + backup. | K-8.13/25 | P0.0 |
| **P0.4** | **Säkerhetsskydds-/visselblåsnings-terminering** (Δ7/Δ8/Δ9) — `preSagaHook` avvisa/karantän FÖRE R1/R2 + retroaktiv isolering. **Lagbrottsgolv — innan Hubs släpps på roller utanför socialtjänst.** | K-5.20–23, K-7.9/10, K-8.45/46/47 | P0.3 |

### P1 — Ärende-motorn skarp (socialsekreterare-vertikalen)

| Steg | Vad | Krav | Beror på |
|---|---|---|---|
| **P1.1** | **Atomär `createCase()`-saga** R1–R9 + kompensering + idempotens + OCS-routes (`/api/v2/arende*`). | K-3.8–12, K-8.15 | P0.3 |
| **P1.2** | **Gallring bunden till verifierad callback** (GAP-007) — flytta retention-start från `ExpungeJob`-tid. | K-7.20, K-8.20 | P0.2 |
| **P1.3** | **`ArendeMatchService`** (Axel C) + server-side konfidenströskel (B-MATCH-1). | K-4.10/11, K-8.16 | P0.3 |
| **P1.4** | **Atomär fördelning** (GAP-057, revoke→grant utan sekretessfönster) + **tre-lagers-ACL-koherens** (GAP-058) + koherens-test + deny-by-default. | K-6.22, K-7.3, K-8.26/27 | P1.1 |
| **P1.5** | **Reconciliation-loop** (GAP-056). | K-3.17, K-8.18 | P0.3, P1.1 |
| **P1.6** | **Skyddsbedömningens commit-tvång** (GAP-001, backend-grind) + fas-validering. | K-7.31, K-8.21 | P1.2 |
| **P1.7** | **`KategoriBadge.vue`** (enda obyggda UI-komponenten) + `InnehallsKlassService`. | K-4.5, K-6.26 | — (isolerad, kan börja parallellt) |

### P2 — Compliance & favoriter

| Steg | Vad | Krav | Beror på |
|---|---|---|---|
| **P2.1** | **Inera Underskriftstjänst** + robust LTV + arkivhärdning (GAP-034/035/037). | K-7.13–17, K-8.22 | B-SIGN-1 |
| **P2.2** | **Favorit-resolverlager** `GET /favoriter` + fail-closed + medborgar-PII-spärr (GAP-061/064). | K-6.23, K-8.28/31 | P0.1 |
| **P2.3** | **Retention-paus vid utlämnandebegäran** (GAP-031) + delgivningssätt-modell (GAP-038/039). | K-7.23/28, K-8.24/34 | P1.2 |
| **P2.4** | **Lokal KB-Whisper + recording-server** (GAP-052) — endast efter myndighetsvägledning. | K-4.7, K-8.23 | T-AI-1 `[EXTERN]` |

### P3 — Breddning till övriga 11 roller (Δ-fält + per-produkt-konnektorer)

| Steg | Vad | Krav | Beror på |
|---|---|---|---|
| **P3.1** | **Δ-bredd datafält** (Δ1–Δ6, Δ10–Δ12, Δ14, Δ16, Δ17) — datadriven `ArendeTyp`-registry + `sekretessGrund[]`-struct + `fristPolicy`-struct + `aclProfil`-bibliotek. Mestadels data. | K-5.2/30/33/40, K-8.40–55 | P1 klart |
| **P3.2** | **e-diarium/e-arkiv-konnektor** (P0-integration i KOMMUNROLLER) — avlastar 9 roller. Andra instansen som bevisar (modul × produkt). | K-5.14, K-8.51 | P0.2-mönstret |
| **P3.3** | **Per-produkt-konnektorer** (Δ13/Δ15) — Sokigo (ByggR/Ecos), Appva, HR, Överförmyndare; multi-commit (`commitPlan[]`); externa myndighets-commits (IMY/MSB/IVO). | K-5.11/12/15/17/18, K-8.51/53 | P3.2 |
| **P3.4** | **Temporal ACL-degradering** (Δ10) + cross-role-routing (Δ12) + verksamhetsgrens-mur. | K-5.31/32/35/36, K-8.48 | P1.4 |

**Beroendekedjans kärna:** `P0.0 (datalager) → P0.2 (konnektor) + P0.3 (register) → P1 (motor skarp) → P3 (bredd)`. Säkerhetsgolvet `P0.4` måste vara klart innan någon roll utanför socialtjänst aktiveras. UI:t (`P1.7`) är isolerat och kan byggas parallellt.

---

## 11. DOKUMENTREGISTER — underliggande detaljdok

Alla i `C:/Users/fredrik.jonasson/Cursor/Nextcloud-vanilla/hubs_start/docs/`. För djupdykning bortom denna handover.

| Dok | Innehåll (en rad) |
|---|---|
| **MODULARISERING-LICENS-DATALAGER.md** | Målarkitekturen: M0–M4-paketering (§1), AGPL-licensposition/processgräns (§2), internt datalager (b)/(c) + Tables-underkännande (§3), ExApp-målbild (§4), beslut + migrationsväg (§5). Detaljdok för §1.4, §2. |
| **HUBS-INTERNALS-ARENDEMOTOR.md** | Ärende-motorn i detalj: registrets rad-shape (§1.1), sagan R1–R9 + kompensering (§1.2, §1.2.4 öppna datalager-frågan), hålla-ihop + reconciliation (§1.3), kodkarta (§1.4), tre klassnings-axlar + beslutslogik (§2), readiness-matris/bygglista (§4), GAP-tabell (§5). Detaljdok för §3, §4. |
| **ARENDETYPER-FLODESANALYS.md** | 8 ärendetyper × 9 axlar; ryggrad R1–R9; `ArendeTyp`-fältlista (§2.3); per-kategori-matris (§3); cross-cutting-flaggor (§4); klassningskaskad (§5); FINNS-vs-BYGGS (§6). Detaljdok för §3, §4. |
| **KOMMUNROLLER-SOR-INTEGRATIONER.md** | 12 roller (§3), fyrvägs-SoR-doktrin (§0.3, §5), 7 Δ-fält (§2.2–2.8), aclProfil-bibliotek (§2.10), system-/integrationsmatris (§4), fallinventering F1–F46 (§5.1), säkerhetsskydd-gränsen (§5.4), blinda fläckar P1–P15 (§6), Δ9–Δ17 (§7), commitDestination-invarianten (§8). Detaljdok för §5. |
| **KONTAKTER-FAVORITER.md** | Favoriter som pekare ovanpå Contacts (ingen fork); favoritlistor som allmän handling/DHP (§4.1); GallringsGrind (§4.2); laglig grund art. 6.1.e + EU-dom C-77/21 (§4.4). Detaljdok för K-6.23, K-7.7. |
| **UI-EVOLUTION-SOCIALSEKRETERARE.md** | Komponent-kontrakt (props/events), zon-routing/store, a–e-evolutionerna (multi-korg, chatt, tilldelning, ärende-tagg, ej-kopplat). Detaljdok för §6. |
| **UX-REDESIGN-SOCIALSEKRETERARE.md** | Zon-arkitektur, `zonOf`, state machine, WCAG-spec. Detaljdok för §6. |
| **SOCIALSEKRETERARE-WALKTHROUGH-V2.md** | 51 steg / Akt I–V; var varje komponent används skarpt; gap-analysen GAP-001–066. Detaljdok för §8. |
| **GAP-ANALYSIS.md** | Spårning av samtliga GAP med severitet/status (closed/mitigated/remaining). Detaljdok för §8.4. |
| **SIGNING-INERA.md** | Signeringsadapter-app (val A), LibreSign vs Inera Underskriftstjänst, PAdES/LTV/PDF-A, kravnivå-matris, hash-baserad datariktning. Detaljdok för §7.3. |
| **DEMO-STUBS.md** | Seam-registret (§1–§4): demo↔prod-grenar i `api.js`, `🔌 SEAM[<id>]`-markörer, prod-ersättningar, byggordning (§5). Detaljdok för §8.1–8.2. |
| **CONTRACTS.md** | Frontend↔backend-kontrakt: server-side-aggregat, ingen klient-fan-out, varumärkesregel, WCAG-regler. Tvärgående. |
| **HUBS-ARKITEKTUR-SOCIALTJANST.md** | Grundvarvet: ärende-identitet (§1.1), Flow vs programmatiskt (§2–§3), persona socialsekreterare (§0). Detaljdok för §1, §3. |
| *(Stödjande)* | `PERSONA-DASHBOARD-SPEC.md`, `PERSONA-USAGE-PATTERNS.md`, `WIDGET-APP-MAP.md`, `NATIVE-APPS-INSTALL.md`, `INSTALL.md`, `SOCIALSEKRETERARE-WALKTHROUGH.md` (V1), `DEMO-WIDGETS-CONTRACT.md` — bakgrund/installation/widget-mappning. |

### Nyckelfiler i koden (absoluta sökvägar)

- **FINNS — taggmotor (mönster för Arende/ArendeMapper):** `…/hubs-code/sdkmc/sdkmc-main/lib/Db/ItslTag.php` + `ItslTagMapper.php`; migration `…/lib/Migration/Version020000Date20250213143200.php`
- **FINNS — kanalklassning:** `…/sdkmc-main/lib/Service/MessageTypeService.php`; korg/behörighet: `ConsolidateMailboxesService.php`; LOA3: `lib/Check/Loa3.php`; klassnings-listener-mall: `lib/Listener/MessageImportantClassifiedListener.php`
- **FINNS — graceful degradation:** `…/hubs_start/lib/Service/AppDetectionService.php`; licens: `…/hubs_start/appinfo/info.xml` (`licence=agpl`, NC 30–32)
- **FINNS — forkar:** `…/hubs-code/mail/mail-main/overlay/appinfo/info.xml` (`AGPL-3.0-only`); `…/spreed-itsl/.../appinfo/info.xml` (`AGPL-3.0-or-later`)
- **FINNS — UI (34 komponenter):** `…/hubs_start/src/components/socialsekreterare/`; demo-feeds: `…/src/services/demo/{treserva,favoriter,socialsekreterare}.js`; seam-grind: `…/src/services/api.js`
- **ATT BYGGA (finns ej):** `…/sdkmc-main/lib/Service/{ArendeService,ArendeMatchService,FacksystemCommitService,InnehallsKlassService}.php`, `lib/Controller/ArendeController.php`, `lib/BackgroundJob/ArendeReconciliationJob.php`, `lib/Db/Arende.php`+`ArendeMapper.php`, `lib/Listener/MessageReceivedListener.php`, `lib/Migration/Version02xxxxCreateArendeTable.php`; UI: `…/hubs_start/src/components/socialsekreterare/KategoriBadge.vue`

---

*Slut på TOTAL kravställning. Denna handover ska räcka för att en ny tråd ska kunna ta vid och bygga. Börja med §0.6 + §9.1 (B-DL-1) → §10 P0.*
