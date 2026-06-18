# Ärendehanteringssystem (system of record) per verksamhet — och hur Hubs som mellanlagring lämnar över till slutlagringen

> **Fokus:** den arkitektoniska sanningen kunden insisterar på — **Hubs är middleware / mellanlagring; systemet of record (slutlagring) är alltid verksamhetens ärendehanteringssystem.** Det här dokumentet kartlägger, per verksamhet och persona, *vilket* facksystem som äger slutlagringen, var data kommer **ifrån**, var den **slutligt lagras**, och **hur** en handläggare commit:ar Hubs-utfallet (säkert meddelande, möte, signerat beslut, fil) in i facksystemet. Det namnger exakta svenska produkter, exakta Nextcloud-app-ids och deep-link-rutter, och de fyra integrationsmönstren Hubs↔facksystem.
>
> **Varumärkesregel:** I produkt-/UI-text säger vi aldrig "Nextcloud" eller "Talk". I *denna interna analys* namnger vi de underliggande apparna (sdkmc, securemail, spreed-itsl, Files/Groupfolders, Deck/Tasks, Tables, Forms, Collectives, LibreSign, Calendar, Retention) så att vi kan wire:a dem.
>
> **Datum:** 2026-06-13 · **Server:** v32 (Hub 25). Kompletterar `research-utskrivning-hsl.md`, `research-compliance-nis2.md`, `research-esignering.md`, `research-filer.md` och persona-specarna.

---

## 0. Den bärande modellen: mellanlagring vs slutlagring (och varför arkivvärlden redan bevisat den)

Kunden ber oss aldrig bli systemet of record. Det är en **styrka**, inte en begränsning — och svensk arkivteori har redan ett ord för exakt Hubs roll. I kommunens e-arkiv skiljer man på **mellanarkiv** (verksamheten äger och har åtkomst, handlingar är tillgängliga men inte i daglig produktion) och **slutarkiv** (slutlig, oföränderlig bevaring). Hubs är samma idé ett steg tidigare i kedjan:

```
 KÄLLA (varifrån data kommer)        MELLANLAGRING (Hubs)                    SLUTLAGRING (system of record)
 ─────────────────────────────       ─────────────────────────────────      ────────────────────────────────────
 • Medborgare/ombud (BankID)          Hubs stagar säker kommunikation,       Verksamhetens ärendehanterings-
 • Region ↔ kommun (SDK/fax)          signering, möte och filer i ett        system / facksystem / diarium.
 • Annan myndighet (SDK)              ärenderum per dnr — kvitterat,         Här fattas/journalförs det
 • Skola/vård/polis (orosanmälan)     spårat, bevakat — tills handläggaren   *rättsliga* beslutet och här sker
 • Facksystemet självt (utkast)       *commit:ar* utfallet vidare.           den arkivpliktiga slutlagringen.
```

**Tre konsekvenser som måste genomsyra varje widget:**

1. **Provenance (varifrån).** Varje rad ska kunna svara "varifrån kom det här?" — kanal (SDK/säker e-post/fax/Forms), avsändarens verifierade identitet (BankID/Freja/SITHS + LOA), och om det härstammar från facksystemet (utkast hämtat) eller utifrån (nytt inflöde).
2. **Destination (varthän).** Varje *avslutat* Hubs-flöde ska kunna svara "var hamnar det här slutligt?" — exakt namngivet facksystem (Treserva-akten, W3D3-dnr, Provisum-ärendet, Adato-rehabärendet) och med vilket mönster (API / drag-to-case / FGS-export / manuell). En grön "commit:ad till facksystemet"-markör är compliance-värde, inte pynt.
3. **Hubs får aldrig bli den enda kopian av en allmän handling.** Eftersom verksamhetssystem (och därmed Hubs som ligger *före* dem) har begränsad livslängd och inte är byggda för långtidsbevaring, måste varje arkivpliktig handling ha en definierad väg ut — via facksystemets diarium/akt och i slutänden FGS-paket till e-arkiv. Det löser också Retention/gallring: Hubs gallrar sin mellanlagrade kopia *efter* bekräftad överföring.

**Designkonsekvens i `hubs_start`:** Lägg ett **provenance-/destination-band** överst i varje ärende-widget (`attHantera`, `arenderum`, `registreraFordela`, `arsrakningar`, `utskrivningsbevakning`, `rehabarenden`): *"Inkom via SDK från Region X · ska registreras i W3D3"* respektive *"Klart → överför till Treserva-akten"*. Detta operationaliserar task #10 (widget→app-provenance + system-of-record-layer) och task #12 (data-flow-mapping).

---

## 1. De fyra integrationsmönstren Hubs↔facksystem

Alla överföringar Hubs→system-of-record faller i ett av fyra mönster. Rangordnade efter integrationsdjup (och säljmotstånd):

| # | Mönster | Hur det funkar | När det passar | Hubs-byggblock |
|---|---|---|---|---|
| **A** | **API/REST** (djupast) | Hubs anropar facksystemets öppna API (eller tvärtom) och skapar/uppdaterar ärende, bilaga, anteckning, status. Treserva och flera diariesystem exponerar öppna API:er; den nationella riktlinjen är Diggs **REST-API-profil inom Ena**. | Storkund, modernt facksystem med API, hög volym (orosanmälningar, utskrivningar). | sdkmc summary-endpoint + en tunn konnektor per facksystem; `IButtonWidget`-action "Överför till facksystemet". |
| **B** | **Drag-to-case / "skicka till diariet"** | Handläggaren drar/klickar ett Hubs-objekt (meddelande + bilagor + kvittensmetadata) och det landar som ny post i diariet med förifyllda metadata. Formpipe **Teams för W3D3/Platina** är exakt detta mönster fast från Teams — Hubs gör det från en sekretessäker, on-prem-yta i stället. | Diarium/registratur (W3D3, Public360, Platina, Ciceron). | `registreraFordela`-widget: Card View → förifyllt registreringsformulär → POST till diariet (eller export-fil diariet sväljer). |
| **C** | **FGS-export (e-arkiv-paket)** | När ärendet avslutas paketeras handlingar + metadata enligt **FGS Paketstruktur 2.0 (E-ARK CSIP/SIP)** och **FGS Ärendehantering 2.0 (CITS ERMS)** och levereras till e-arkiv (Sydarkivera m.fl.). Detta är inte "till facksystemet" utan "förbi det, till slutarkivet" — relevant där Hubs-ärenderummet *självt* innehåller bevarandepliktiga original. | Avslut/gallring, registrator, förvaltare. | `arkivGallring`-widget + Files/Groupfolders + Retention → FGS-byggare. |
| **D** | **Manuell överföring** (grunt, fallback) | Handläggaren laddar ner signerad PDF/A + kvittens och laddar upp/klistrar in i facksystemet för hand; Hubs loggar att överföring skedde. Alltid tillgängligt, kräver ingen integration, men ingen automatik. | Litet facksystem utan API, pilot, "dag 1"-läge före integration. | Ladda-ner-knapp + "Markera som överförd till facksystemet"-status (loggas som händelse). |

**Säljbudskap:** Hubs *integrerar mot — ersätter inte* — facksystemet. Mönster D funkar dag 1 utan integration; A/B/C byggs per kund och facksystem. Det sänker köpmotståndet hos både IT (ingen rip-and-replace) och hos facksystemsleverantören (Hubs är inte konkurrent till Treserva/W3D3/Provisum — Hubs är den säkra ytan *runt* dem).

---

## 2. Per verksamhet / persona — system of record och handoff

### 2.1 Socialtjänst → persona `socialsekreterare`

**System of record (slutlagring):**

| Produkt | Leverantör | Status 2025–2026 |
|---|---|---|
| **Treserva** | CGI | Marknadsledande; web-/API-baserat med inbyggt processtöd; anpassat för nya socialtjänstlagen (SoL i kraft **1 juli 2025**). Partille bland de första att koppla Treserva→e-arkiv för 100 % digital ärendeprocess (registrering→handläggning→arkivering). |
| **Lifecare / Procapita** | Tietoevry | Procapita-arvet migreras till Lifecare-sviten (VoO/IFO). |
| **Viva** | CGI (tidigare Flexite) | Aktivt **utbytesfönster**: t.ex. 14 Norrbottenskommuner byter Viva→Combine i gemensam upphandling. |
| **Combine** | Pulsen Omsorg | "Combine Core" — heltäckande ekosystem inkl. stöd för privata utförare; vinner Viva-ersättningsupphandlingar. |

**Var data kommer ifrån:** orosanmälan från skola/vård/polis/privatperson (kanal: e-tjänst, säker e-post, SDK, fax, papper); medborgarens/ombudets säkra svar (BankID/Freja, LOA3); SDK org-till-org.
**Vad Hubs mellanlagrar:** triage av orosanmälan (`orosanmalningar`, 14-dagars förhandsbedömning), ärenderum per barn/dnr (`arenderum`), säker dialog med klient (`attHantera`/`kvittenser`), SIP-/möteskallelse (`dagensMoten`), beslut för e-underskrift (`attSignera`/LibreSign).
**Var det slutlagras:** **Treserva/Lifecare/Viva/Combine-akten** — utredning, beslut enligt SoL/LVU, journalanteckning. Hubs är *inte* socialakten.

**Handoff:**
- Förhandsbedömning klar → **mönster B** ("Registrera i facksystemet"): inkommande orosanmälan + metadata (källa, kanal, inkom-datum, sekretess) POST:as som ny aktualisering/ärende. Treservas öppna API gör **mönster A** möjligt hos storkund.
- Beslut e-signerat i `attSignera` (LibreSign, AES via BankID; lågrisk → "Godkänn" loggat per SKR:s riskmodell) → PAdES/PDF/A arkiveras i ärenderummet → **överförs till Treserva-akten** (A/B/D) → delges klient via säker kanal, `kvittenser` visar levererat→öppnat→läst → överklagandefrist sätts som bevakning.
- Vid avslut: Treserva→e-arkiv är facksystemets ansvar; Hubs-ärenderummet gallras via Retention *efter* bekräftad överföring.

**Provenance-band (UI):** *"Orosanmälan inkom via [kanal] 2026-06-10 · ej registrerad i facksystemet"* → efter handoff: *"Registrerad i Treserva, dnr 2026-IFO-1234 · Hubs-rum gallras 2026-09"*.

---

### 2.2 Registratur / nämnd / diarium → persona `registrator`

**System of record (slutlagring) — diariesystemet ÄR själva registret:**

| Produkt | Leverantör | Not |
|---|---|---|
| **W3D3** | Formpipe | Bredast spridda kommun-/myndighetsdiariet; modulärt; Teams-integration (Teams för W3D3) visar drag-to-case-mönstret. |
| **Platina** | Formpipe | Ärende-/dokumenthantering inkl. e-arkivering; Teams-integration. |
| **Public 360°** | Tieto/Sokigo | Stort i kommun och stat. |
| **Ciceron** | (CGdok/Sokigo-sfären) | Diarie-/dokumenthantering. |
| **Evolution, LEX** | Sokigo | Kommunala ärende-/dokumentsystem. |

**Var data kommer ifrån:** allt inkommande till funktionsadresser (`registrator@`, `namnd@`), SDK/säker e-post/fax, e-tjänster.
**Vad Hubs mellanlagrar:** funktionsbrevlåde-triage (`funktionsbrevlador`), förifylld registrering (`registreraFordela`), nämndcykel-underlag (`namndcykel`), digital justering + anslag (`justeringAnslag`, LibreSign + laga-kraft-klocka), utlämnande av allmän handling (`utlamnande`), arkivleverans (`arkivGallring`).
**Var det slutlagras:** **diariesystemet** (dnr, ärendemening, allmän handling, sekretessmarkering) och i slutänden **e-arkiv** (FGS).

**Handoff — detta är Hubs *renodlade* mellanlagrings-roll:**
- **Mönster B är kärndifferentiatorn.** `registreraFordela` öppnar ett förifyllt registreringsformulär (avsändare, inkom-datum, föreslaget dnr, ärendemening, sekretessmarkering, tilldela handläggare/nämnd) och POST:ar till W3D3/Public360/Ciceron/Platina — eller, för system utan API, genererar en importfil. Stänger gapet **meddelande↔diarium** som idag är manuellt. Juridik: OSL 5:1–2 + JO-praxis (registrering **senast nästa arbetsdag**) gör tid-till-registrering till KPI.
- Protokoll → `justeringAnslag`: ordförande + justerare signerar digitalt (LibreSign, AES via BankID, PAdES/PDF/A) → anslås → **laga-kraft-nedräkning 3 v** → expediering/delgivning via säker kanal med `kvittenser`-delgivningskvittens. Det justerade protokollet är allmän handling som **slutlagras i diariet**.
- Avslut → **mönster C (FGS)**: `arkivGallring` paketerar enligt FGS Paketstruktur 2.0 + FGS Ärendehantering 2.0 och levererar till Sydarkivera/e-arkiv. Här är Hubs/diariet *mellanarkiv*, e-arkivet *slutarkiv*.

**Provenance-band:** *"Inkom via säker e-post 2026-06-12 14:03 · oregistrerad (1 arbetsdag kvar)"* → *"Registrerad i W3D3, dnr KS-2026-0456 · fördelad till handläggare AB"*.

---

### 2.3 Kommunal HSL / hemsjukvård → persona `hsl_skoterska`

**System of record — TVÅ lager (regionens planeringssystem + kommunens HSL-journal):**

| Lager | Produkt | Leverantör | Status 2025–2026 |
|---|---|---|---|
| **Samordnad planering** (det strukturerade utskrivningsflödet) | **Lifecare SP** | Tietoevry | Marknadsledande, växande; flera regioner driftsatte 2025 (Gotland, Västerbotten, Västernorrland m.fl.). |
| | Cosmic Link | Cambio | I Cosmic-regioner. |
| | SAMSA / Meddix / Prator | regionala | VGR (SAMSA); Meddix fasas ut; Prator i bl.a. Uppsala. |
| **Region-journal** | **Cosmic** | Cambio | **Sussa 9/9 regioner i drift 2025**; **Region Stockholm tecknade avtal nov 2025** (huvudjournal, ~¼ av Sveriges befolkning); NLL-anslutning lagkrav **1 dec 2025**. |
| **Kommunens HSL-journal** | Treserva HSL / Treserva Hälsoärende, Lifecare VoO, Combine, Viva | CGI/Tietoevry/Pulsen | Kommunens egen slutlagring av HSL-journal. |

**Var data kommer ifrån:** inskrivnings-/planerings-/utskrivningsklar-meddelanden (Lifecare SP, strukturerat); allt *runt om* (kompletterande remiss, läkemedelslista, hjälpmedelsunderlag, frågor mellan vårdgivare) via **SDK/säker e-post/fax** — ofta från små vårdgivare utan systemanslutning.
**Vad Hubs mellanlagrar:** utskrivningsbevakning med betalningsansvars-dygnsräknare (`utskrivningsbevakning`, lag 2017:612; belopp HSLF-FS 2025:74 för 2026), samverkansavvikelse-i-ett-klick (`samverkansavvikelser`), SIP-möte (`dagensMoten`/spreed-itsl + BankID-lobby), funktionsbrevlåda `hemsjukvard@`/`svpl@`.
**Var det slutlagras:** strukturerade SPU/SIP-data → **Lifecare SP**; HSL-journalanteckning → **kommunens HSL-journal (Treserva HSL m.fl.)**; avvikelse → **regionens/kommunens avvikelsesystem** (ofta RLDatix/Platina-baserat). Hubs ersätter **ingen** av dem — Hubs fångar det tvärkanaliga glappet (HSLF-FS 2016:40 kräver krypterad, mottagarverifierad kanal, kan ej avtalas bort).

**Handoff:**
- Inkommande "utskrivningsklar" kvitteras i Hubs → journalförs i kommunens HSL-journal (**mönster D/A**); planeringssvar matas in i **Lifecare SP** (separat inloggning, SITHS) — Hubs *aggregerar och bevakar*, blir inte tredje journalsystemet.
- `samverkansavvikelser` förifyller (patient-id, motpart, bristtyp, tidsstämplar) och skickar säkert via **SDK** till regionens avvikelsefunktion (**mönster A/B**) → MAS följer trend.

**Provenance-band:** *"Utskrivningsklar inkom via Lifecare SP-kopia/SDK · dygn 2 av 3 · journalförs i Treserva HSL"*.

---

### 2.4 HR / chef — rehab & känsliga personalärenden → persona `hr_chef`

**System of record (slutlagring):**

| Lager | Produkt | Leverantör | Not |
|---|---|---|---|
| **PA-/lönesystem** (anställningsdata) | **Personec P**, **Visma HR/HR+** | Visma | >80 % av kommunerna använder Visma-tjänster. |
| | **Heroma** | CGI (molntjänst) | Stort i kommun/region. |
| | Adato (PA-integrerat) | — | — |
| **Rehab-ärendet** (slutlagring av rehab-dokumentation) | **Adato** | Miljödata | **Rehab-systemet of record.** PA-integrerat, bevakar automatiskt sjukfrånvaro, signalerar chefen när rehabärende ska startas; **arbetsförmågebedömningar, läkarintyg, utredningar ska förvaras i Adato.** |

**Var data kommer ifrån:** sjukfrånvarosignal från PA-systemet (driver Adato); läkarintyg/medarbetardialog; FK-kontakt; företagshälsovård; facklig motpart.
**Vad Hubs mellanlagrar:** avskild känslig inkorg (`kansligInkorg`), rehab-ärenderum (`rehabarenden`), fristStrip (dag 8 läkarintyg, **dag 30 plan för återgång**, 60-dagar, avstämningsmöte), e-underskrift av plan/överenskommelse/avtal (`attSignera`, medarbetare via BankID på distans), samtycke/blanketter (`mallarSamtycke`, Forms + BankID, t.ex. FK 7459).
**Var det slutlagras:** **Adato** (rehab-dokumentationen) + **Personec/Heroma/Visma** (anställnings-/lönehändelse). Hubs är den **sekretessmärkta kommunikations- och signeringsytan** — aldrig öppen e-post — men **Adato äger akten**.

**Handoff:**
- Plan för återgång signerad i Hubs (LibreSign, AES) → signerad PDF/A + valideringsintyg → **överförs till Adato-rehabärendet** (**mönster D**, ev. A om Adato-API finns) → delges medarbetare/FK via säker kanal med läskvittens.
- Anställningsavtal/beslut signerat → journalförs i PA-systemet; Hubs loggar att överföring skett.

**Provenance-band:** *"Rehab startad (sjukfrånvarosignal från Personec) · plan ska förvaras i Adato"* → *"Plan för återgång signerad · överförd till Adato"*.

---

### 2.5 Överförmyndare → persona `overformyndare`

**System of record (slutlagring):**

| Produkt | Leverantör | Not |
|---|---|---|
| **Provisum** | Sambruk (utvecklat av 17 ÖF-enheter / ~50 kommuner; teknik via Flowfactory) | Verksamhetsstöd för handläggare **+** e-tjänst för ställföreträdare. **"Mitt Wärna"** är inrapporteringstjänsten där ställföreträdare lämnar årsräkning digitalt → in i Provisum. |
| **Aider** | — | Konkurrerande ÖF-verksamhetssystem. |
| **Wärna / Mitt Wärna** | (kopplat till Provisum-flödet) | Inrapportering av årsräkning. |

**Var data kommer ifrån:** ställföreträdarens årsräkning/sluträkning/förteckning + verifikat (kanal: e-tjänst Mitt Wärna/Provisum, papper-inskannat, post); bank/Skatteverket-underlag.
**Vad Hubs mellanlagrar:** granskningssäsongen mot **1 mars** (`arsrakningar` kampanjvy, `granskningsko` plockbar kö), begär-komplettering-flöde, beslut (arvode/uttag spärrat konto) för e-underskrift (`attSignera`), uppdragskontroll-flaggning (`uppdragskontroll`, JO-kravet dec 2025 om många uppdrag/upprepade anmärkningar), ärenderum per uppdrag/huvudman.
**Var det slutlagras:** **Provisum/Aider-ärendet** — granskningsresultat, anmärkning, beslut, tillsyn. Provisum/Wärna äger redovisningsdatat; Hubs äger **flödet runt om** (säker dialog, komplettering, e-underskrift, bevakning) utan att känsliga uppgifter lämnar er server.

**Handoff:**
- Granskning klar → beslut e-signeras i Hubs (LibreSign, AES via BankID; lågrisk → "Godkänn") → PAdES/PDF/A + valideringsintyg → **överförs till Provisum-ärendet** (**mönster A/B/D**) → delges ställföreträdare, laga-kraft-frist som bevakning → `skickatForSignering` visar öppnat/besvarat. Besked till bank org-till-org via SDK med kvittens.
- Komplettering begärd via säker kanal → svar/verifikat mellanlagras i ärenderummet → matas in i Provisum.

**Provenance-band:** *"Årsräkning inkom via Mitt Wärna 2026-02-20 · granskning pågår · beslut journalförs i Provisum"*.

---

### 2.6 Förvaltare / IT / informationssäkerhet → persona `forvaltare`

**System of record:** här är Hubs självt delvis system of record för *säkerhets-/efterlevnadshändelser*, men slutlagringen av incidentanmälan sker hos **MCF/PTS** (NIS2/cybersäkerhetslagen 2025:1506, i kraft 15 jan 2026; kedja 24h/72h/1 mån), och arkivpliktiga handlingar går till **e-arkiv via FGS**.
**Var data kommer ifrån:** sdkmc säkerhetshändelse-feed (auth/delning/routing), SDK-driftstatus, auth/LOA-sessionsdata.
**Vad Hubs mellanlagrar/äger:** `complianceStatus`, `incidentrapporter` (MCF-klockor), `sakerhetshandelser`, `loggSparbarhet` (SDK-logg 12/12 mån sökbar — Diggs krav), `authLoa`, `provisionering`, `arkivGallring`, `dataSuveranitet`.
**Var det slutlagras:** incidentanmälan → **MCF** (mönster D/A: rapportgenerator förfyller, handläggaren skickar in); arkivpaket → **e-arkiv (FGS)**; logg → bevaras i Hubs 12 mån för tillsyn.
**Handoff:** `forvaltare` äger **bevarande-/valideringsvyn** "Giltig nu / Giltig då" (PAdES/PDF/A/LTV per arkiverat signerat dokument) som revisions-/överklagandebevis — själva överlämnandet till facksystem/e-arkiv sker i de övriga personornas flöden, men förvaltaren ser att det skett (provisioneringshygien, gallring satt, 0 tredjelandsöverföringar).

---

## 3. Nextcloud-app-ids och deep-link-rutter (för wiring)

UI-namn (säg) → app-id (intern) → typisk rutt. Deep-links används både för att *öppna* underlaget i Hubs och som mål när facksystemet pekar tillbaka.

| UI-namn | app-id | Roll i mellanlagring → slutlagring | Deep-link-rutt (exempel) |
|---|---|---|---|
| Säkra meddelanden / säker e-post / digital fax | `sdkmc`, `securemail`, `mail` | Källan: inflöde + kvittens; summary-endpoint klassar kanal | `/ocs/v2.php/apps/sdkmc/api/v1/summary`; `/apps/sdkmc/...` |
| Säkert möte / videomöte | `spreed` (spreed-itsl) | SIP/rehab/klientmöte; BankID/Freja-lobby | `/call/{token}`; `/apps/spreed/...` |
| Säkra filer / ärenderum | `files` + `groupfolders` (+ ACL, `files_versions`) | Mellanlagrar bilagor/original per dnr | `/apps/files/?dir=/{ärenderum}&fileid={id}`; `/f/{fileid}` |
| Uppgifts-/bevakningsmodulen | `deck`, `tasks` | Bevakning/frist/påminnelse knuten till ärende | `/apps/deck/board/{boardId}/card/{cardId}`; `/apps/deck/#/board/{id}` |
| Strukturerat register (osynlig motor) | `tables` | Backend för triage-/deadline-/avvikelse-/incidentregister; renderas som widget | Tables OCS API `/ocs/v2.php/apps/tables/api/2/tables/{id}/rows` |
| Säkert formulär / samtycke | `forms` | SIP-/rehab-samtycke, internt avvikelse-/anmälningsformulär | `/apps/forms/{hash}` |
| Kunskapsbank | `collectives` | Rutiner/mallar/gallringsplaner on-prem | `/apps/collectives/{collective}/{page}` |
| E-underskrift | `libresign` | Signeringssteg före slutlagring; PAdES/PDF/A | `/apps/libresign/p/{uuid}`; `/ocs/v2.php/apps/libresign/api/v1/...` |
| Kalender / bokningsbar tid | `calendar` (Appointments) | Auto-videorum + lobby | `/apps/calendar/...`; appointment booking-URL |
| Arkiv/gallring | `files_retention` (Retention) + FGS-byggare | Mellanlagrad kopia gallras efter handoff; FGS-export till e-arkiv | Retention-regel per Groupfolder |
| Dashboard-registrering | Dashboard-API (`IAPIWidgetV2`/`IButtonWidget`/`IConditionalWidget`) | Kort syns även i standardvy/mobil; `IConditionalWidget` = åtkomstgräns | `/apps/dashboard/` |

**Viktigt:** Tasklista (todo), AI-sammanfattning och transkribering (task #11) wire:as som: `tasks`/`deck` (todo), `llm2`/lokal modell (sammanfattning, grön-ratad, avstängbar), och möte-transkribering via spreed-itsl + lokal STT — alla **lokala**, så att mellanlagringen inte läcker till tredje part innan slutlagring i facksystemet.

---

## 4. Integrationsbackbone: Ena, SDK och nationella grunddata (2025–2026)

Det som gör handoff-mönstren realistiska 2026 är att Sverige *just nu* etablerar en gemensam integrationsväg:

- **Ena — Sveriges digitala infrastruktur** (Digg, etablerad jan 2025): byggblock för identifiering/behörighet, **säkra meddelanden**, API-hantering med en **rekommenderad REST-API-profil** (förvaltningsgemensam), och **nationella grunddata** (person-, företags-, hälsodata). Detta är den långsiktiga A-mönster-ryggraden Hubs-konnektorer bör följa.
- **SDK (Säker digital kommunikation):** AS4 (eDelivery-profil) + XHE-envelopering; två meddelandetyper idag (SDK-meddelande + kvittens); accesspunkter sköter transport org-till-org. Diggs riktlinjer för **nya meddelandetyper** är vägen för att standardisera t.ex. en "registrera i diariet"- eller "samverkansavvikelse"-meddelandetyp på sikt. ITSL är kvalificerad i Adda DIS "Säker digital kommunikation".
- **FGS 2.0** (Riksarkivet återupptog arbetet 2023→): **FGS Paketstruktur 2.0 = E-ARK CSIP/SIP**, **FGS Ärendehantering 2.0 = CITS ERMS**. Sydarkivera tar emot leveranser på FGS Paketstruktur. Detta är mönster-C-formatet och bör vara mål för `arkivGallring`.

**Konsekvens för roadmap:** bygg konnektorerna mot **standarden** (Ena REST-profil, SDK-meddelandetyper, FGS 2.0), inte mot varje facksystem en-och-en. Då blir "integrera mot Treserva/W3D3/Provisum/Adato" en konfiguration ovanpå tre standardadaptrar (A/B/C) + den alltid-tillgängliga D.

---

## 5. Sammanfattande matris (verksamhet → källa → Hubs → slutlagring → mönster)

| Persona | System of record (slutlagring) | Källa (varifrån) | Primärt Hubs-utfall som ska commit:as | Handoff-mönster |
|---|---|---|---|---|
| `socialsekreterare` | Treserva / Lifecare / Viva / Combine (socialakt) | Orosanmälan (skola/vård/polis/privat), klientdialog | Förhandsbedömning, beslut (PDF/A), delgivning | B/A (registrera), D för signerat beslut |
| `registrator` | W3D3 / Public360 / Ciceron / Platina / Evolution / LEX (diarium) + e-arkiv | Allt inkommande till funktionsadresser | Registrerad post, justerat protokoll, anslag | **B** (drag-to-case) + **C** (FGS) |
| `hsl_skoterska` | Lifecare SP (planering) + Cosmic/region + Treserva HSL m.fl. (kommun-journal) | Utskrivningsflöde + tvärkanaligt (SDK/fax) | Kvittens, journalanteckning, samverkansavvikelse | A/D (journal) + A/B (avvikelse via SDK) |
| `hr_chef` | **Adato** (rehab) + Personec/Heroma/Visma (PA) | Sjukfrånvarosignal, läkarintyg, FK | Plan för återgång (signerad), avtal | D (→Adato), A om API |
| `overformyndare` | Provisum / Aider (+ Wärna inrapportering) | Mitt Wärna-årsräkning, verifikat | Granskningsbeslut (signerat), komplettering | A/B/D |
| `forvaltare` | MCF (incident) + e-arkiv (FGS) | sdkmc-feed, auth/logg | Incidentanmälan, FGS-paket, valideringsbevis | D/A (MCF) + C (FGS) |

---

## Huvudfynd

**Svensk arkivteori har redan ett etablerat namn för exakt Hubs roll — `mellanarkiv` vs `slutarkiv` — och kunden bör adopt:a det vokabuläret rakt av: Hubs är "mellanlagringen / mellanarkivet" som stagar säker kommunikation, signering, möte och filer per ärende, men varje arkivpliktig handling måste ha en *definierad, synlig väg ut* (provenance-/destination-band per widget) till verksamhetens system of record (Treserva/Lifecare/Viva/Combine, W3D3/Public360/Platina/Ciceron, Lifecare SP/Cosmic, Adato/Personec/Heroma, Provisum/Aider) via fyra mönster: A=API/REST (Ena REST-profil), B=drag-to-case (som Formpipe Teams-W3D3, fast on-prem), C=FGS 2.0-export (E-ARK CSIP / CITS ERMS) till e-arkiv, D=manuell (dag-1-fallback). Marknadsfönstret är skarpt: nya SoL 1 juli 2025, Viva→Combine- och Lifecare SP-vågorna, Cosmic till Region Stockholm (nov 2025), NLL-lagkrav 1 dec 2025 och cybersäkerhetslagen 15 jan 2026 gör att facksystem-rutiner görs om nu — vilket är exakt när en mellanlagringsyta enklast etableras.**

---

## Källor

Socialtjänst (Treserva / Lifecare / Viva / Combine):
- Treserva — verksamhetssystem (öppna API:er, processtöd) — https://www.cgi.com/se/sv/treserva
- Treserva som hållbar plattform — https://www.cgi.com/se/sv/blogg/welfare-management-treserva/treserva-en-hallbar-plattform-framtidens-sociala-omsorg
- Treserva Hälsoärende (HSL i Treserva) — https://www.cgi.com/se/sv/treserva/treserva-halsoarende
- Partille: Treserva→e-arkiv, 100 % digital ärendeprocess — https://www.partille.se/arbetsmarknad--jobb/fakta-om-kommunen-som-arbetsgivare/kika-in-hos-verksamheterna/digital-socialtjanst/
- Nya socialtjänstlagen 2025 (CGI) — https://www.cgi.com/se/sv/offentlig-sektor/nya-socialtjanstlagen
- Pulsen Combine Core (ekosystem) — https://pulsenomsorg.se/product/combine-core/
- Pulsen Combine — verksamhetssystem socialtjänst — https://pulsenomsorg.se/verksamhetssystem-socialtjansten/
- Pulsen Combine i Nacka — https://www.nacka.se/medarbetare/system/pulsen-combine---verksamhetssystem-for-socialtjansten/
- Älvsbyn: byter Viva→Combine (14 kommuner) — https://www.alvsbyn.se/nyheter/socialtjansten-byter-ut-hela-verksamhetssystemet/
- Nya socialtjänstlagen i kraft 1 juli 2025 (Region Gotland) — https://gotland.se/rg/samarbetswebb/for-utforare-inom-socialtjanst-och-omsorg/nya-socialtjanstlagen

Registratur / diarium (W3D3 / Public360 / Platina / Ciceron / Evolution / LEX):
- Formpipe W3D3 (offentlig sektor) — https://www.formpipe.com/products/w3d3/
- Formpipe — effektiv ärende- och dokumenthantering — https://www.formpipe.com/sv/offentlig-sektor/losningar/effektiv-arende-och-dokumenthantering/
- Formpipe Teams för Platina/W3D3 (drag-to-case-mönstret) — https://www.formpipe.com/products/teams-platina-w3d3/
- Content by Sigma — Platina/W3D3 e-arkivering — https://www.contentbysigma.se/sv-SE/ServicesAndProducts/SubPages/Dokumentsystem
- MKSE — Public 360° översikt — https://www.mkse.com/affarssystem-dokumenthantering/public-360
- MKSE — Formpipe W3D3 översikt — https://www.mkse.com/affarssystem-dokumenthantering/w3d3-dokumenthantering

Kommunal HSL / region-journal (Lifecare SP / Cosmic / Treserva HSL):
- Tietoevry Lifecare Samordnad Planering — https://www.tietoevry.com/se/care/halsa-och-sjukvard/primarvard-och-specialistvard/samordnad-planering/
- Tietoevry pressmeddelande okt 2025 (tre regioner driftsätter Lifecare SP) — https://www.tietoevry.com/se/nyhetsrum/alla-nyheter-och-pressmeddelanden/pressmeddelande/2025/10/tre-regioner-tar-nasta-steg-for-en-samordnad-och-trygg-vard---driftsatter-lifecare-samordnad-planering-fran-tietoevry-ca/
- Cambio Cosmic — journalsystem region och kommun — https://www.cambio.se/vi-erbjuder/cambio-cosmic-journalsystem/
- Sussa Samverkan — införandet av Cambio Cosmic (9/9 regioner) — https://www.cambio.se/sussa/
- Region Stockholm tecknar avtal om huvudjournalsystem (Cosmic), nov 2025 — https://www.regionstockholm.se/nyheter/2025/11/region-stockholm-har-tecknat-avtal-om-huvudjournalsystem/
- Beslut om nytt huvudjournalsystem Region Stockholm (feb 2025) — https://www.regionstockholm.se/nyheter/2025/02/beslut-om-nytt-huvudjournalsystem-for-region-stockholm/
- Cambio — NLL-anslutning godkänd, lagkrav 1 dec 2025 — https://www.cambio.se/e-halsomyndigheten-godkanner-anslutning-nll/
- Lag (2017:612) om samverkan vid utskrivning — https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/lag-2017612-om-samverkan-vid-utskrivning-fran_sfs-2017-612/
- HSLF-FS 2025:74 — belopp för utskrivningsklara 2026 — https://www.socialstyrelsen.se/publikationer/hslf-fs-202574-socialstyrelsens-foreskrifter-om-belopp-for-vard-av-utskrivningsklara-patienter-for-ar-2026-2025-12-9956/

HR / rehab (Adato / Personec / Heroma / Visma):
- Miljödata Adato — rehabstöd (rehab-dokumentation förvaras i Adato) — https://www.miljodata.se/losningar/adato
- Visma Personec P — HR-system offentlig sektor — https://visma.se/hrm/personecp-suite/personecp-hr-system
- Visma HRM-/lönesystem (>80 % av kommunerna) — https://www.visma.se/hrm-system/
- Time Care / RLDatix integrationer (PA-system kommun) — https://www.allocatesoftware.se/kommun/time-care-kommun-suite/integrationer/

Överförmyndare (Provisum / Aider / Wärna):
- Provisum — verksamhetssystem för överförmyndarförvaltningen — https://www.provisum.se/
- Provisum för ställföreträdare (e-tjänst) — https://www.provisum.se/foumlr-staumlllfoumlretraumldare.html
- Sambruk — Provisum, effektiva rutiner för överförmyndarenheten — https://sambruk.se/overformyndare-provisum/
- Sambruk — Provisum verksamhetsstöd (Mitt Wärna-inrapportering) — https://sambruk.se/provisum-overformyndarens-verksamhetsstod/
- Flowfactory — Provisum (teknikplattform) — https://www.flowfactory.com/sv/provisum

Integrationsbackbone (Ena / SDK / FGS / e-arkiv):
- Ena — Sveriges digitala infrastruktur (Digg) — https://www.digg.se/styrning-och-samordning/ena---sveriges-digitala-infrastruktur
- Ena — API-hantering (rekommenderad REST-API-profil) — https://www.digg.se/styrning-och-samordning/ena---sveriges-digitala-infrastruktur/byggblock/api-hantering
- Ena — nationella grunddata — https://www.digg.se/styrning-och-samordning/ena---sveriges-digitala-infrastruktur/nationella-grunddata
- Digg — SDK System Architecture Design (AS4/XHE, meddelandetyper) — https://www.digg.se/saker-digital-kommunikation/sdk-for-leverantorer-av-meddelandesystem/tekniska-beskrivningar/sdk-system-architecture-design-sad
- Digg — Riktlinjer för meddelandetyper (SDK) — https://www.digg.se/saker-digital-kommunikation/sdk-for-leverantorer-av-meddelandesystem/nya-meddelandetyper/riktlinjer-for-meddelandetyper
- ITSL Solution — SDK (Adda DIS-kvalificerad) — https://itsl.se/secure-digital-communication/
- Riksarkivet — Förvaltningsgemensamma specifikationer (FGS) — https://riksarkivet.se/arkivera-och-forvalta/medium-och-format/forvaltningsgemensamma-specifikationer
- Sydarkivera — Riksarkivet återupptar FGS-arbetet (FGS Paketstruktur 2.0 = E-ARK CSIP/SIP; FGS Ärendehantering 2.0 = CITS ERMS) — https://www.sydarkivera.se/riksarkivet-aterupptar-arbetet-med-forvaltningsgemensamma-specifikationer-fgs/
- Sydarkivera Wiki — FGS Ärendehantering — https://wiki.sydarkivera.se/wiki/%C3%84rende-_och_dokumenthantering
- microdata.nu — e-arkiv / mellanarkiv / långtidsarkiv / FGS (mellanarkiv vs slutarkiv) — https://microdata.nu/sv/e-arkiv-mellanarkiv-langtidsarkiv-och-fgs-vad-ar-vad/
- Arkivlagen (1990:782) / Statens arkiv om e-arkiv — https://statensarkiv.se/e-arkiv/
- eSam ES2023-06 — utkontraktering, sekretess och dataskydd (on-prem eliminerar OSL 10:2a-bedömningen) — https://www.esamverka.se/download/18.43a3add4188b9f2345a2fe78/1687332814480/ES2023-06%20V%C3%A4gledning%20Utkontraktering%20-%20sekretess%20och%20dataskydd.pdf
