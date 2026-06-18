<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Hubs Start — Persona-dashboard-spec (master)

> **Status:** master design spec for the persona-personalised dashboards in `hubs_start`
> ("Hubs Start — Flödesnavet"). This is the single source of truth for the widget catalog,
> the proposed-apps roadmap, and the six persona layouts. The machine-usable counterpart is
> `persona-config.json` (same ids, same structure) which the app loads to render each role view.
>
> **Datum:** 2026-06-13 · **Plattform:** server v32 (Hub 25 Autumn) · **Frontend:** Vue 2.7 + @nextcloud/vue v8
>
> **Varumärkesregel (gäller all produkt-/UI-text, hela detta dokument och koden):** vi säger
> aldrig "Nextcloud" eller "Talk". Vi säger **Hubs**, **Hubs Start**, **säkra meddelanden**,
> **säker e-post**, **digital fax**, **säkert möte / säkert videomöte**, **ärenderum**,
> **säkra filer**, **uppgifts-/bevakningsmodulen**, **e-underskrift**. De underliggande
> plattforms- och appnamnen (sdkmc, securemail, mail/fax, spreed-itsl, calendar, Deck/Tasks,
> Groupfolders, Retention, Tables, Forms, Collectives, Collabora/OnlyOffice, llm2) används
> **bara** i interna byggnoteringar som detta — kolumnen "App/funktion" nedan — för spårbarhet.

---

## 1. Vision — persona-personaliserade dashboards

Hubs Start är inte en inkorg och inte en statistik-yta. Den är en **handlingsförst triagekö**
som samlar alla säkra kanaler (SDK, säker e-post, digital fax, säkert möte, säkra filer,
e-underskrift) till **en ingång** — och renderar den som en **rollhärledd, kuraterad förstavy**
per persona. Designgrunden (se `research-personalisering.md`):

- **Default = roll, inte tom canvas.** Personan härleds automatiskt från grupp-/kontext-medlemskap
  (Nextcloud-grupp → `RoleService`). Användaren möter en färdig vy, inte en byggsats. Detta matchar
  Microsoft Viva Connections (audience targeting), Ineras 1177-enhetlighetslinje och offentliga
  sektorns likabehandlingsprincip.
- **Låst kärna + kuraterat skal.** Varje vy delas i en **låst kärna** (compliance- och
  tillgänglighetskritiska kort som rollen alltid ser — OSL/HSLF-FS/NIS2/WCAG) och ett **kuraterat
  skal** (en vitlistad uppsättning kort användaren får ordna/dölja, med knappbaserad omordning enligt
  WCAG 2.5.7 — aldrig bara drag).
- **Card View + Quick View.** Varje widget följer Viva-mönstret: agera direkt i kortet, expandera för
  detalj utan sidbyte (progressive disclosure). Default visar 5–7 kort, inte 12.
- **GOV.UK-statusmodell, minimal.** `Ny · Påbörjad · Väntar på motpart · Klar för beslut · Klar`
  plus ett rött `Åtgärd krävs`. Verb-inledda titlar ("Granska årsräkning – Andersson", "Skicka
  beslut för underskrift"). Färg är aldrig enda informationsbärare.
- **Mät tid-till-åtgärd, inte tid-på-dashboarden.** Tom kö = inget missat är ett *compliance-värde*,
  inte bara bekvämlighet.
- **Audience targeting = säkerhetsgräns.** En widget får aldrig visa rubriker/avsändare/antal från
  en funktionsbrevlåda användaren saknar OSL-behörighet till. `IConditionalWidget` är här en
  åtkomstgräns, inte UX.
- **Lokal AI-prioritering som transparent, avstängbart lager.** Ovanpå en deterministisk sortering
  (frist → sekretessnivå → oläst) får en lokal, grön-ratad modell (`llm2`, t.ex. OLMo 2) *föreslå*
  ordning och en kort sammanfattning, med synligt "varför". AI får aldrig dölja/avföra ärenden eller
  profilera användaren (GDPR art. 22). Säljs som "AI-prioritering utan att ett enda meddelande lämnar
  er server".

**Teknisk koppling.** Datat härleds ur Hubs egna kanaler via **en** server-side
aggregeringsendpoint `/ocs/v2.php/apps/sdkmc/api/v1/summary` (ingen klient-fan-out; kanalklassning
sker server-side). Kompletterande kort registreras via Dashboard-API:t (`IAPIWidgetV2` +
`IButtonWidget` + `IConditionalWidget`) så de syns även i standardvyn/mobilen — men förstavyn är
Hubs egen "default app"-yta (`hubs_start`), inte den generiska widget-griden.

**Marknadsfönstret är nu.** 2026 pekas ut som året då SDK blir standardrutin (>100 anslutna org
aug 2025, Region Stockholm sep 2025); cybersäkerhetslagen (2025:1506) trädde i kraft 15 jan 2026;
cybermiljarden ger kommuner 200 mkr/år och regioner 50 mkr/år 2026–2028. Den som äger startsidan
äger vanan.

---

## 2. Brand-ordlista (UI vs intern teknik)

| I UI (säg) | Intern teknik (säg aldrig i UI) |
|---|---|
| Säkra meddelanden / säker e-post / digital fax | sdkmc, securemail, mail/fax-brygga |
| Säkert möte / säkert videomöte | spreed-itsl |
| Säkra filer / ärenderum / säker dokumentyta | Groupfolders + ACL + versioner + Retention + Collabora/OnlyOffice |
| Uppgifts-/bevakningsmodulen | Deck / Tasks (kanban/VTODO som datalager) |
| Strukturerat register (osynlig motor) | Tables |
| Säkert formulär / samtycke | Forms |
| Kunskapsbank | Collectives |
| E-underskrift | Inera Underskriftstjänst-API / Sweden Connect-nod (Digg open source) |
| Säkert mötesrum / bokningsbar tid | calendar (Appointments) + auto-videorum |
| Lokal AI-prioritering | llm2 (grön Ethical-AI-rating) |

---

## 3. Widgetkatalog (alla widgetar)

Ordnad: först de **redan byggda kärn-widgetarna** (finns i `hubs_start/src/components/`), sedan de
**nya föreslagna** persona-widgetarna. Widget-id är **camelCase** och **återanvänds** mellan personas
där de delar funktion (en widget byggs en gång, renderas i flera layouter). `dataSource`:
**real** = matas av befintlig sdkmc/summary eller befintlig plattformsapp; **proposed** = kräver
nytt föreslaget datalager (Tables-register, e-signeringskö, integration mot facksystem).

### 3.1 Redan byggda kärn-widgetar

| id | Titel (UI) | Kategori | App/funktion | dataSource | Personas | Beskrivning |
|---|---|---|---|---|---|---|
| `attHantera` | Att hantera | kommunikation | Säkra meddelanden (sdkmc/securemail/fax) | real | alla | Aggregerad triagekö över allt inkommande som kräver åtgärd, med kanalikon (SDK/säker e-post/fax/möte) och fristräknare. Förstavyns nav. Matas av `summary`-endpointen (server-side kanalklassning). |
| `dagensMoten` | Dagens & veckans säkra möten | mote | Kalender + säkert videomöte | real | soc, hsl, hr, ofm, reg | Bokade/kommande säkra videomöten med en-klicks-anslut och lobby-/väntrumsstatus (BankID/Freja-verifierade deltagare, LOA-nivå per person). |
| `kvittenser` | Leveranser & kvittens | kommunikation | Säkra meddelanden (sdkmc receipt) | real | alla | Leveranstidslinje per utgående meddelande/delgivning: Skickad → Levererad → Öppnad → Inloggad (LOA3) → Läst → Besvarad, med feltillstånd (studsad / ej öppnad inom X dgr → eskalera). Den emotionella ersättningen för "ringa och kolla att faxen kom fram". |
| `funktionsbrevlador` | Funktionsbrevlådor | kommunikation | Säkra meddelanden (funktionsadress) | real | reg, soc, hsl, ofm | Delade funktionsadress-köer (SKR:s 2025-rekommendation): oläst/otilldelat per brevlåda, "plocka/fördela ärende", eskalering. Behörighetsstyrd — visar bara brevlådor användaren har OSL-behörighet till. |
| `bevakningar` | Mina bevakningar & frister | uppgifter | Uppgifts-/bevakningsmodulen (Deck/Tasks) | real | alla | Deadline-sorterad lista med eskaleringsfärg (grå→gul ≤3 dgr→röd förfallen). Verb-inledda titlar. "Skapa bevakning från meddelande". Påminnelser T-7/T-3/T-0 bara till tilldelad. Toggle Mina/Enhetens. Strip överst: "4 frister denna vecka". |
| `bokningsbaraTider` | Bokningsbara tider | mote | Kalender (Appointments) + auto-videorum | real | soc, hsl, hr, ofm | Skapa bokningsbar tid → auto-skapat säkert videorum + BankID/Freja-lobby för externa. Översikt över egna bokningssidor. |
| `nytta` | Nytta hittills | statistik | Strukturerat register (Tables) | proposed | chef-läge (forvaltare, + chef-vy hr/ofm/reg) | ROI-räknare: ersatta fax/rek-brev/okrypterad e-post × Diggs schablon ~30 min/ärende → sparad tid; andel SDK vs fax/månad (faxavvecklingskurva). Underlag till nämnd/cybermiljards-äskande. |
| `systemhalsa` | Systemhälsa & leverans | statistik | sdkmc driftstatus (accesspunkt/kvittens) | real | forvaltare | Accesspunkt/SDK-anslutning upptid, meddelanden i fellager, ej kvitterade leveranser, ej hanterade säkra meddelanden över X dagar (internkontroll). |

### 3.2 Nya föreslagna persona-widgetar

| id | Titel (UI) | Kategori | App/funktion | dataSource | Personas | Beskrivning |
|---|---|---|---|---|---|---|
| `attSignera` | Att signera | signering | E-underskrift (Inera Underskriftstjänst-API / Sweden Connect) | proposed | soc, hr, ofm, reg | Personlig + funktionsbaserad kö över dokument som väntar på *min* underskrift, med kravnivå-badge (SES/AES/QES — AES via BankID standard) och deadline. För lågriskhandlingar visas "Godkänn" (loggat) i stället för "Signera" (SKR:s riskmodell). |
| `skickatForSignering` | Skickat för signering | signering | E-underskrift + säkra meddelanden | proposed | soc, hr, ofm, reg | Spegelvy av utgående: Skickat → Öppnat → Signerat av X av Y → Klart/Arkiverat (+ Avvisat / Påminnelse skickad / Utgånget). Per-part-status, Påminn-knapp. Flerpart inkl. externa medborgare via säker länk + BankID. |
| `minaUppgifter` | Mina uppgifter | uppgifter | Uppgifts-/bevakningsmodulen (Deck/Tasks) | real | soc, hr, hsl | "Mina uppgifter" som task-list (GOV.UK): personliga att-göra knutna till ärenden, med status och nästa-åtgärd. Komplement till `bevakningar` (frist-fokus) — denna är arbets-/genomförandefokus. |
| `arenderum` | Mina ärenderum | filer | Säkra filer / ärenderum (Groupfolders + ACL + Retention) | real | soc, hr, ofm, reg, hsl | Översikt över öppna ärenderum (en säker dokumentyta per dnr/barn/uppdrag): status, olästa dokument, väntar-på-signatur, gallrings-countdown, om medborgardelning är aktiv. Bilagor *bor* i rummet; kommunikationen *refererar* dit. |
| `senasteFiler` | Senaste säkra filer | filer | Säkra filer (Groupfolders + versioner) | real | soc, hr, ofm, hsl | "Vad hände med mina dokument senast": delad med medborgare / ny version / väntar på din granskning / signerad / uppladdad av motpart — med ärenderum-kontext och säker-kanal-markering. |
| `orosanmalningar` | Orosanmälningar – förhandsbedömning | arende | Uppgifts-/bevakning + Forms + sdkmc-inflöde | proposed | soc | Dedikerad kö för nya anmälningar med **14-dagars countdown** per anmälan, status (Ny / Under förhandsbedömning / Beslut inleda / Beslut ej inleda), källa (skola/vård/polis/privat) och kanal. Strip: "3 förhandsbedömningar förfaller denna vecka". |
| `utskrivningsbevakning` | Utskrivningar att bevaka | arende | Säkra meddelanden + bevakning (lag 2017:612) | proposed | hsl | Deadline-driven kö över inkommande utskrivningsmeddelanden (inskrivningsmeddelande / planering / **utskrivningsklar** / SIP kallad / hemtagen). Per "utskrivningsklar"-rad: dygnsräknare mot betalningsansvar + kr-riskindikator (grön <3 dygn-snitt / gul / röd överskjutande). Signaturfunktionen för HSL. |
| `samverkansavvikelser` | Samverkansavvikelser | arende | Strukturerat register (Tables) + säkra meddelanden | proposed | hsl | Avvikelse-i-ett-klick direkt från meddelandet: förifyller patient (ärende-id), motpart (region/enhet), bristtyp (saknad läkemedelslista / för sen underrättelse / uteblivet inskrivningsmeddelande), tidsstämplar; skickas säkert till regionens avvikelsefunktion via SDK. MAS följer trender. |
| `arsrakningar` | Årsräkningar – granskningsläget | arende | Uppgifts-/bevakning (kampanjvy) + Provisum/Aider-integration | proposed | ofm | Den deadline-låsta toppen som GOV.UK-kampanj: aggregerad progress "312 av 540 granskade · 18 dagar till 1 mars · 47 saknar verifikat", per-ärende-status, förtursflagga (förstagångsredovisare/tidigare anmärkta). |
| `granskningsko` | Granskningskö – nästa att granska | uppgifter | Uppgifts-/bevakning + ärenderum + facksystem | proposed | ofm | Plockbar kö över otilldelade/tilldelade redovisningar: "ta ansvar", källkanal-ikon (e-tjänst/papper-inskannat/post), saknas-verifikat-markering, "Granska nästa"-primäråtgärd. Visar ärenden nära 7-mån/FL-frist i rött. |
| `uppdragskontroll` | Uppdragsöverblick (flaggning) | compliance | Strukturerat register (Tables-regelmotor) + facksystem | proposed | ofm | JO-kravet (dec 2025) operationaliserat: flaggar ställföreträdare med ovanligt många uppdrag eller upprepade anmärkningar för fördjupad tillsyn/stickprov. |
| `rehabarenden` | Rehab- & personalärenden | arende | Säkra filer / ärenderum + bevakning | proposed | hr | Task-orienterad lista över aktiva personalärenden med rehab-statusflöde (Ny / Pågående / Väntar på motpart / Plan upprättad / Avslutad), deadline-markör, nya dokument, om medarbetardelning/-signatur är aktiv. Avskild från allmän kommunikation. |
| `kansligInkorg` | Känslig inkorg (rehab & personal) | kommunikation | Säkra meddelanden (sdkmc, kontextfiltrerad) | real | hr | Avskild triagekö för säkra meddelanden/SDK/fax som rör personalärenden, separerad från allmän kommunikation. Kanalikon + oläst/kvittens-status per rad. Aldrig i öppen e-post. |
| `fristStrip` | Frister denna vecka | uppgifter | Bevakning + deadline-register (Tables) | proposed | hr, soc | Eskaleringsstrip för lagstadgade tider. För HR: dag 8 (läkarintyg), **dag 30 (plan för återgång)**, 60-dagarströskel, avstämningsmöte, intygsförlängning. Grå→gul (≤3 dgr)→röd (förfallen). |
| `mallarSamtycke` | Mallar & samtycke | filer | Forms (internt) + mallbibliotek + e-underskrift | proposed | hr, soc | Snabbstart av återkommande dokument: samtyckesblankett (vårdgivarkontakt), plan för återgång (FK 7459), rehaböverenskommelse, SIP-samtycke, kallelser. Säkert formulär + BankID/signering. |
| `registreraFordela` | Registrera & fördela | arende | Diarie-/registreringsstöd (förifylld från metadata) | proposed | reg | Card View som öppnar förifyllt registreringsformulär: avsändare, inkommen-datum, föreslaget dnr, ärendemening, sekretessmarkering, tilldela handläggare/nämnd. Stänger gapet meddelande↔diarium (integrerar mot, ersätter inte, W3D3/Public360/Ciceron/Lifecare). |
| `utlamnande` | Lämna ut allmän handling | arende | Diariesök + utlämnandelogg + säker e-post/SDK | proposed | reg | Diariesök + framställan-kö med sekretessprövnings-checklista, maskering och säkert utskick. "Skyndsamt"-timer per begäran. |
| `namndcykel` | Nämndcykeln | arende | Ärenderum + säker delning + kalender + Tables | proposed | reg | Status för kommande sammanträde som GOV.UK-task-list: ärenden på dagordningen, vilka som saknar komplett underlag, kallelse skickad?, handlingar delade?, protokoll att justera, anslag aktivt, expediering kvar. |
| `justeringAnslag` | Justering & anslag | signering | E-underskrift + anslagstavla + säker kanal | proposed | reg | Protokoll som väntar på digital justering (BankID-underskrift av ordförande/justerare); aktiva anslag med **laga-kraft-nedräkning** (3 v); expediering/delgivning kvar. |
| `arkivGallring` | Arkiv & leverans | compliance | Säkra filer + Retention + FGS-export | proposed | reg, forvaltare | Avslutade ärenden med gallringsstatus ("Gallras 2031 enligt handlingstyp X" / "Bevaras"), notis innan radering, "Leverera till e-arkiv (FGS)" för Sydarkivera/e-arkiv. |
| `complianceStatus` | Efterlevnad – cybersäkerhetslagen | compliance | Compliance-modul (härledd ur logg/auth/SDK) | proposed | forvaltare | Sammanvägd grön/gul/röd mot kravområden: anmäld MCF, incidentrutin, logg 12 mån, MFA/LOA3 100 %, data i egen miljö, ledningsgenomgång daterad. Quick View = kravlista mappad mot Infosäkkollen-nivåerna (mål ≥ nivå 3). |
| `incidentrapporter` | Incidenter & MCF-frister | compliance | Incidenthantering (klock-logik ovanpå händelse-feed) | proposed | forvaltare | Triagekö över öppna säkerhetshändelser/incidenter med **nedräkningsklockor** (24 h tidig varning / 72 h anmälan / 1 mån läges-/slutrapport) i färg + ej-bara-färg-markör. Verb-knappar: "Skicka tidig varning", "Komplettera anmälan". Förfyller MCF-rapportgenerator. |
| `sakerhetshandelser` | Säkerhetshändelser | compliance | sdkmc säkerhetshändelse-feed (auth/delning/routing) | real | forvaltare | Aggregerar verksamhetsnära signaler: misslyckade inloggningar, utomgrupps-/avvikande delningar, meddelande till oväntad funktionsadress, inloggning under tröskel-LOA. Knapp "Eskalera till incident" förfyller `incidentrapporter`. |
| `loggSparbarhet` | Loggretention & spårbarhet | compliance | Logg- & spårbarhetspanel (SDK-logg + sökindex) | proposed | forvaltare | SDK-loggretention 12/12 mån, sökbar (Diggs krav, grön bock). Sökruta mot AS4 Message/Conversation ID, avsändare/mottagare, tidpunkt — utan meddelandeinnehåll. Tillsyn + felsökning i ett. |
| `authLoa` | Autentisering & tillitsnivå | compliance | ID-core / auth (sessionsdata) | real | forvaltare | Andel sessioner på LOA3 (BankID/Freja/SITHS), MFA-täckning, "eIDAS2/EUDI-redo"-markör, lista över inloggningar under tröskelnivå. HSLF-FS 2016:40-bevis. |
| `provisionering` | Användare & funktionsadresser | compliance | Användar-/grupphantering + funktionsadress-admin | real | forvaltare | Provisioneringskö: nya som ska in, avslutade som ska av, vilande/överbehöriga konton, funktionsadressmedlemskap. Verb-knappar "Lägg till i funktionsadress", "Avetablera" (loggas som åtkomsthändelse). |
| `dataSuveranitet` | Datasuveränitet | compliance | Compliance-modul (statisk + åtkomstlogg-härledd) | proposed | forvaltare, alla (diskret markör) | "All data i er driftmiljö · 0 tredjelandsöverföringar · senaste externa åtkomst: ingen." Svar på OSL 10:2a + CLOUD Act-oron. Liten diskret variant ("säker kanal") finns i varje persona-vy. |
| `kunskapsbank` | Kunskapsbank & mallar | filer | Kunskapsbank (Collectives) | real | soc, hsl, hr, ofm | Genväg till rutiner, BBIC-/rehab-/granskningsmallar, gallringsplaner, samtyckesmallar — on-prem. Minskar kognitiv börda. Låst utanför det konfigurerbara skalet (WCAG 3.2.6 Consistent Help). |
| `identitetsBadge` | Identitet & leverans | kommunikation | ID-core (BankID/Freja completionData) | real | soc, hr, ofm | Identitets-badge per motpart: metod + LOA + tidpunkt ("Verifierad med BankID · LOA3 · 2026-06-13 14:02"), varningsläge ("Ej verifierad – SMS-kod"), ombud ("Erik E. företräder Karin K."). Leverantörsoberoende, eIDAS2-redo. |

---

## 4. Föreslagna appar / moduler — roadmap (befintlig vs föreslagen)

Princip: **bygg inte om plattformens byggblock — bygg arbetsytan och rättssäkerhetslagret ovanpå**.
Stå på svensk, suverän infrastruktur (Inera/Sweden Connect, BankID/Freja/SITHS); äg köerna,
spårningen, bevarandet. Differentiera på **on-prem** + **samlad arbetsyta**, inte på kärnfunktionen.

| Modul (UI-namn) | Status | Bygg-/integrationsgrund | Marknadsgrund & källa |
|---|---|---|---|
| **Säkra meddelanden** (SDK + säker e-post + digital fax) | **befintlig** | sdkmc, securemail, mail/fax-brygga; `summary`-endpoint | Kärnkanalen. Digg: SDK ersätter fax/rek-brev/bud/telefon → ~1 620 mnkr/år, ~3 500 årsarbetskrafter, ~30 min/ärende. SKR: 2026 = standardrutin. HSLF-FS 2016:40 kräver krypterad, mottagarverifierad kanal. Källor: Digg debatt 2023; SKR "Från pappershantering till digital hantering"; Socialstyrelsen HSLF-FS 2016:40. |
| **Funktionsadresser** (delade verksamhetsbrevlådor) | **befintlig** | sdkmc funktionsadress-stöd | SKR:s 2025-rekommendation gör delad brevlåda (t.ex. `orosanmalan@kommunen`, `hemsjukvard@`) till förstaklassobjekt och registratorns/teamets kärnvy. Källa: SKR funktionsadress-rekommendation 2025. |
| **Uppgifts-/bevakningsmodulen** | **befintlig bas + föreslagen widgetlogik** | Deck/Tasks (kanban/VTODO) som datalager; egen rollstyrd widget ovanpå | "Skapa bevakning från meddelande" (meddelande→uppgift→ärende→påminnelse) är differentieraren ingen vertikal (Provisum/Aider) eller generisk (Planner/Trello) löser i samma sekretessäkra miljö. Bygg påminnelse-före-deadline + avisering bara till tilldelad (täcker kända luckor Deck #1549/#566). Källor: GOV.UK task-list; FL 2017:900 §§11–12; FB 14:15. |
| **Säkra filer / ärenderum** | **befintlig bas + föreslagen orkestrering** | Groupfolders + ACL + versioner + Retention + Collabora/OnlyOffice on-prem | "Ett ärenderum per dnr" är den bärande berättelsen som binder ihop meddelanden, video och filer. On-prem eliminerar OSL 10:2a-lämplighetsbedömningen (eSam ES2023-06). Arkivförordningen (1 aug 2024): export + radering före införande = upphandlingskrav → FGS-export + Retention. Mot Storegate (SaaS) och M365 (CLOUD Act). Källor: eSam ES2023-06; Riksarkivet/Sydarkivera FGS; arkivförordningen 2024. |
| **E-underskrift** (avancerad e-underskrift) | **föreslagen** (kö/spårning/bevarande ovanpå nationell tjänst) | Inera Underskriftstjänst-API **eller** egen Sweden Connect-nod (Digg open source); BankID/Freja/SITHS; PAdES/PDF/A + LTV | SKR:s vägledning "Digitala underskrifter" (dec 2025): riskbaserad nivåval (SES internt lågrisk / **AES arbetshäst** / QES bara där lag kräver), och underskrift behövs inte alltid → "Godkänn" vs "Signera". Bevarande (PAdES/PDF/A/LTV) är den svåra delen ingen säljer bra → "Giltig nu/Giltig då"-panel. Bygg inte kryptokärnan; mot Scrive/Assently/Visma (moln → OSL/CLOUD Act). Källor: SKR vägledning dec 2025; Inera Underskriftstjänsten; Digg/Sweden Connect; eIDAS art. 25/26; Riksarkivet RA-FS. |
| **Kalender-bokning + säkert videomöte** | **befintlig** | calendar (Appointments) + spreed-itsl; auto-videorum + BankID/Freja-lobby | Löser gapet att Region Uppsala 2022 valde "Skype som säkraste plattformen". SIP/klient-/rehab-/ställföreträdarsamtal: bokningslänk → auto säkert videorum → BankID-lobby för externa utan konto. Källor: research-forms-apps (Calendar Appointments); Region Uppsala SIP-projekt 2022. |
| **Säkert formulär / samtycke** (Forms, internt) | **föreslagen, inom sina gränser** | Forms + signeringssteg + fildropp | SIP-/rehab-samtycke och internt anmälnings-/avvikelseformulär. *Konkurrera inte* med publik orosanmälan-e-tjänst (Open ePlatform/Abou) — Forms saknar native filuppladdning + förgrening. Lös signerings-/fildropp-gapet före demo. Källor: research-forms-apps; nextcloud/forms #358. |
| **Strukturerat register** (Tables, osynlig motor) | **föreslagen** | Tables REST-API; renderas som Hubs-widgets, aldrig rå tabell | Backend för triage-status, deadline-/nytto-/avvikelse-/incidentregister, uppdragskontroll-flaggning. Källa: research-forms-apps (Tables v2 API). |
| **Diarie-/registreringsstöd** | **föreslagen** (kärndifferentiator för registrator) | Förifyllning från meddelandemetadata; integration mot diariesystem | Förifylld registrering (avsändare/datum/dnr/ärendemening/sekretess) + tilldelning. Integrerar mot — ersätter inte — W3D3/Public360/Ciceron/Lifecare. Stänger gapet meddelande↔diarium. OSL 5:1–2 + JO (registrering senast nästa arbetsdag). Källor: OSL 5 kap; JO-praxis; Formpipe. |
| **Nämnd-/sammanträdesmodul** | **föreslagen** | Ärenderum + säker delning + kalender + e-underskrift + Tables | Kallelse, beslutsunderlag, protokoll, digital justering (BankID), anslag (laga-kraft-klocka), expediering/delgivning. Gör nämndsekreterar-rollen komplett. Källor: kommunallag 2017:725; eIDAS art. 25; Tyresö digital justering. |
| **Compliance-/NIS2-modul** | **föreslagen** (kärnan för forvaltare) | Härleds ur logg/auth/SDK-status; MCF-klockor; rapportgenerator; suveränitetskort | Cybersäkerhetslagen (2025:1506, i kraft 15 jan 2026) — alla kommuner/regioner; ledningens personliga ansvar; MCF-rapportkedja 24h/72h/1 mån; SDK-logg 12 mån sökbar; cybermiljarden 200/50 mkr/år. Differentiering mot Secify/Secure State Cyber/Purview: **operativ kommunikationsdata → automatisk efterlevnadsbild** (inte tomt GRC-skal). Mappa mot Infosäkkollen-nivåer (31 %/69 %-luckan). Källor: cybersäkerhetslag 2025:1506; MCF/PTS incidentrapportering; Digg SDK-loggkrav; MSB Infosäkkollen; regeringen cybermiljarden 2025-09. |
| **ID-core** (leverantörsoberoende identitet) | **befintlig bas + föreslagen abstraktion** | BankID/Freja/SITHS nu; Sverige-id (dec 2026) + EUDI-plånbok (2026/27) inkopplingsbara | Identitets-badge (metod+LOA+tid), leveranstidslinje, väntrums-kort. SMS-OTP som märkt nödutgång (NIST *restricted*), spärrad för LOA3-flöden. Ombud/anhörigbehörighet för ~5–10 % utan eID. "eIDAS2-redo" = upphandlingsargument. Källor: Digg tillitsramverk; Sverige-id godkänd 2026-04-21; eIDAS2/EUDI; BankID completionData; NIST SP 800-63B-4. |
| **Lokal AI-prioritering** | **föreslagen, valfri & avstängbar** | llm2 (endast lokala, grön-ratade modeller, t.ex. OLMo 2) | Triage-stöd vid hög volym (514k orosanmälningar/år nationellt). Endast lokal modell → suveränitet bevarad; transparent ("varför"), avstängbar, aldrig destruktiv; prioriterar ärendeegenskaper inte användarbeteende (GDPR art. 22). "AI utan att data lämnar er server". Källor: research-personalisering; Nextcloud Ethical-AI; IMY/Digg gen-AI-riktlinjer 2025. |
| **Kunskapsbank** (Collectives) | **befintlig** | Collectives (wiki on-prem) | Rutiner, mallar, gallringsplaner — "en ingång, inte system nummer åtta" (Arbetsmiljöverket/Suntarbetsliv). Källa: research-filer (Collectives). |
| **Mina meddelanden-koppling** (utkanal) | **föreslagen, sikt** | Mina meddelanden / Kivra m.fl. | Dialog ≠ massutskick: positionera Hubs som tvåvägsdialogen, bygg på sikt "skicka beslut till digital brevlåda" från handläggarvyn (SOU 2024:47 sannolikt obligatoriskt). Källa: research-citizen-id; SOU 2024:47. |
| **Maps / Notes** | **avstå nu** | — | Maps saknar v32-stöd, osäker underhållsstatus; Notes för smal. Hembesöksgeografi löses via Tables-fält vid behov. Källa: research-forms-apps (Maps endast v31). |

---

## 5. Personas (6) — en sektion per persona

Gemensamt för alla: härleds från grupp/kontext (`RoleService`); låst kärna + kuraterat skal;
diskret `dataSuveranitet`/"säker kanal"-markör alltid synlig; WCAG 2.2 AA; Ctrl/Cmd+K-palett med
rollfiltrerade åtgärder. Layout nedan anges som **main** (vänster/primär kolumn, prioritetsordnad)
och **side** (höger kolumn). Samma `layout`-struktur finns i `persona-config.json`.

---

### 5.1 Socialsekreterare (`socialsekreterare`)

**Roll:** myndighetsutövare barn & familj (SoL 2025:400, BBIC). Härleds från grupp `socialtjanst`.

**Tagline:** *Triage av orosanmälningar och säkra ärenden — ingen frist i huvudet, inget barn mellan stolarna.*

**Primära åtgärder (verb-först):** Ta emot & fördela orosanmälan · Skapa ärenderum · Skicka säkert
meddelande / svara klient · Kalla till säkert möte (SIP) · Skicka beslut för underskrift.

**Widgetlayout**
- **main:** `attHantera` · `orosanmalningar` · `bevakningar` · `arenderum` · `attSignera`
- **side:** `dagensMoten` · `kvittenser` · `funktionsbrevlador` · `minaUppgifter` · `senasteFiler` · `kunskapsbank`

**KPI:er:** Andel förhandsbedömningar inom 14 dgr · Andel utredningar klara inom 4 mån · Röda
(förfallna) bevakningar (mål 0) · Median tid-till-fördelat för ny orosanmälan · Andel utskick med
läskvittens · Andel beslut e-signerade vs utskrivet.

**Terminologi:** Att hantera · Orosanmälan · Förhandsbedömning (14 dgr) · Utredning (4 mån) · Frist ·
Ärenderum (per dnr/barn) · Delgivning · SIP-möte · Underskrift / Godkänn · Läskvittens · Fördela/Plocka ·
Funktionsbrevlåda (`orosanmalan@kommunen`) · Legitimering (SITHS/BankID, LOA3) · Gallring.

**Signeringsflöde:** Beslut/utredning klar → `attSignera` (AES via BankID/Freja; lågrisk →
"Godkänn" loggat per SKR) → status i `skickatForSignering` → arkiveras PAdES/PDF/A i `arenderum` →
delges klient via säker kanal, `kvittenser` visar levererat→öppnat→läst → bevakning för
överklagandefrist/uppföljning skapas automatiskt.

---

### 5.2 Registrator / nämndsekreterare (`registrator`)

**Roll:** spindeln i informationsflödet — tar emot, registrerar, fördelar; bygger nämndhandlingar,
justerar, anslår, expedierar. Härleds från funktionsadress-grupp (t.ex. `registrator`).

**Tagline:** *Allt inkommande registrerat i tid, fördelat och spårbart — hela beslutskedjan digital och kvitterad.*

**Primära åtgärder:** Registrera & fördela handling · Lämna ut allmän handling · Bygg & skicka
nämndkallelse · Justera & expediera beslut · Leverera ärende till e-arkiv.

**Widgetlayout**
- **main:** `attHantera` · `registreraFordela` · `funktionsbrevlador` · `namndcykel` · `justeringAnslag`
- **side:** `kvittenser` · `bevakningar` · `utlamnande` · `arkivGallring` · `dataSuveranitet` · `nytta`

**KPI:er:** Tid till registrering (mål: nästa arbetsdag) · Oregistrerat över 1 arbetsdag · Otilldelade
i funktionskön · Leverans-/läskvittensgrad · Felskickat undvikt (verifierad funktionsadress vs fax) ·
Nämndcykel-genomströmning (komplett underlag vid kallelse) · Gallrings-/FGS-status.

**Terminologi:** Registrera/diarieföra · Diarienummer (dnr) · Ärendemening · Avsändare/inkommen-datum ·
Fördela · Funktionsadress · Allmän handling · Sekretessmarkering/-prövning · Utlämnande/framställan ·
Kallelse/dagordning · Justering/justerare · Anslag/anslagstavla · Laga kraft · Expediera/delge ·
Delgivningskvittens · Gallra/bevara · Dokumenthanteringsplan · E-arkiv/FGS-leverans.

**Signeringsflöde:** Protokoll → `justeringAnslag`: ordförande + justerare signerar digitalt med
BankID (AES, PAdES/PDF/A via Inera Underskriftstjänst) → anslås → **laga-kraft-nedräkning** (3 v) →
besluten expedieras/delges via säker kanal, `kvittenser` visar delgivningskvittens → e-sigill på
massutgående beslut. Vid laga kraft → `arkivGallring` (FGS).

---

### 5.3 Kommunsjuksköterska (`hsl_skoterska`)

**Roll:** kommunal HSL/hemsjukvård — bevakar utskrivning från slutenvård (lag 2017:612), SIP,
betalningsansvar, region↔kommun-flöden, avvikelser. Härleds från grupp `kommunal-hsl`.

**Tagline:** *Aldrig en missad "utskrivningsklar" — säker kanal och bevakning runt hela utskrivningen.*

**Primära åtgärder:** Kvittera utskrivningsmeddelande · Skapa samverkansavvikelse · Kalla till
SIP-möte · Skicka säkert meddelande till regionen · Skapa bevakning från meddelande.

**Widgetlayout**
- **main:** `attHantera` · `utskrivningsbevakning` · `samverkansavvikelser` · `bevakningar` · `arenderum`
- **side:** `dagensMoten` · `funktionsbrevlador` · `kvittenser` · `minaUppgifter` · `senasteFiler` · `kunskapsbank`

**KPI:er:** Dygn över betalningsansvarsgräns denna månad (kr-exponering) · Antal "utskrivningsklar"
obekräftade · Antal samverkansavvikelser + trend · Obesvarade säkra meddelanden från region > X dgr ·
Andel meddelanden i säker kanal vs fax · Tid-till-kvittens på inkommande.

**Terminologi:** Utskrivningar att bevaka · Inskrivningsmeddelande · Utskrivningsklar ·
Betalningsansvar (3 kalenderdagar / dygnskostnad HSLF-FS) · SIP-möte/SVPL · Samverkansavvikelse ·
Fast vårdkontakt · Säkert meddelande · Funktionsbrevlåda (`hemsjukvard@`/`svpl@`) · Legitimering
(SITHS, LOA3) · Säker kanal (HSLF-FS 2016:40).

**Signeringsflöde:** Primärt kvittens-/avvikelseflöde snarare än signering — men SIP-plan/vårdplan
kan **godkännas** (loggat, SITHS) eller signeras (AES) av flera parter; samtycke (`mallarSamtycke` →
Forms + BankID) bryter sekretess. Dokumenten bor i `arenderum` (HSLF-FS-uppfyllt), kommunikationen
refererar dit.

---

### 5.4 HR / chef – rehab & känsliga personalärenden (`hr_chef`)

**Roll:** HR-partner/enhetschef med personalansvar — hälsodata, läkarintyg, rehab, FK-kontakter,
anställning. Härleds från grupp `hr`.

**Tagline:** *En avskild, sekretessmärkt yta för rehab och personalärenden — rätt frist, rätt kanal, aldrig öppen e-post.*

**Primära åtgärder:** Skapa rehab-ärenderum · Skicka säkert meddelande / delge · Boka & starta säkert
rehabmöte · Begär underskrift · Skapa bevakning från ärende.

**Widgetlayout**
- **main:** `kansligInkorg` · `fristStrip` · `rehabarenden` · `attSignera` · `bevakningar`
- **side:** `dagensMoten` · `skickatForSignering` · `mallarSamtycke` · `kvittenser` · `senasteFiler` · `kunskapsbank` · `nytta`

**KPI:er:** Andel rehab-/personalkommunikation i säker kanal (vs öppen e-post) · Plan för återgång i
tid (senast dag 30) · Aktiva rehabärenden per status · Frister/uppföljningar denna vecka · Dokument
som väntar på signatur / signerade i tid · Ej kvitterade utgående delgivningar.

**Terminologi:** Känslig inkorg (rehab & personal) · Personal-/rehabärende · Rehab-rum / personalakt
(avskild) · Bevakning/uppföljning · Frist (dag 8, dag 30) · Avtal att skriva under / beslut att
signera · Läskvittens · Rehabmöte/avstämningsmöte · Motpart (medarbetare/företagshälsovård/FK/facklig) ·
Samtycke/blankett (plan för återgång, FK 7459) · "Säker kanal · all data i er driftmiljö".

**Signeringsflöde:** Plan/överenskommelse/anställningsavtal → `attSignera` (AES, medarbetare via
BankID även på distans) → `skickatForSignering` spårar Skickat→Öppnat→Signerat per part → signerad
PDF/A + valideringsintyg arkiveras i `rehabarenden` → delges medarbetare/FK via säker kanal med
läskvittens. Samtycke för vårdgivarkontakt via `mallarSamtycke` (Forms + BankID) ersätter "samtycke
per post".

---

### 5.5 Överförmyndarhandläggare (`overformyndare`)

**Roll:** granskar ställföreträdares redovisningar, fattar beslut, utövar tillsyn; deadline-drivet
årshjul med topp 1 mars. Facksystem = Provisum/Aider (Hubs äger flödet runt om). Härleds från grupp
`overformyndare`.

**Tagline:** *Granskningskön i takt mot 1 mars — komplettering, beslut och e-underskrift utan att känsliga uppgifter lämnar er server.*

**Primära åtgärder:** Granska nästa årsräkning · Begär komplettering · Signera & delge beslut · Boka
säkert möte · Skapa bevakning från meddelande/ärende.

**Widgetlayout**
- **main:** `arsrakningar` · `granskningsko` · `attSignera` · `skickatForSignering` · `bevakningar`
- **side:** `funktionsbrevlador` · `arenderum` · `dagensMoten` · `uppdragskontroll` · `kvittenser` · `kunskapsbank` · `nytta`

**KPI:er:** Andel årsräkningar färdiggranskade (mål 80 % per 30 juni) · Granskningskö: ej påbörjade /
under granskning / väntar på komplettering · Dagar till 1 mars + antal utan verifikat · Ärenden över
fristgräns (7 mån / FL 6 mån) · Dokument som väntar på min e-underskrift + utskickade som väntar på
motpartens · Andel digital vs pappersredovisning · Ställföreträdare med ovanligt många uppdrag.

**Terminologi:** Huvudman · Ställföreträdare (god man/förvaltare/förmyndare) · Årsräkning/sluträkning/
förteckning · Verifikat/underlag · Bevakning · Frist (1 mars; laga-kraft) · Begär komplettering ·
Anmärkning · Granskningssäsong · Beslut för underskrift (arvode/uttag spärrat konto) · Ärenderum
(per uppdrag/huvudman) · Tillsyn/granskning.

**Signeringsflöde:** Granskning klar → arvodes-/uttags-/tillsynsbeslut → `attSignera` (AES via BankID;
lågrisk → "Godkänn") → PAdES/PDF/A + valideringsintyg arkiveras i `arenderum` → delges ställföreträdare,
laga-kraft-frist som bevakning → `skickatForSignering` visar öppnat/besvarat med Påminn-knapp. Besked
till bank org-till-org via SDK med kvittens.

---

### 5.6 Förvaltare / IT / informationssäkerhet (`forvaltare`)

**Roll:** CISO/IS-samordnare/systemförvaltare — compliance, incidenter, provisionering, gallring,
logg, ROI uppåt. Härleds från grupp `forvaltning`/`infosak`/`it-drift`/`ledning`.

**Tagline:** *Är vi säkra nu? Kan vi bevisa att vi följer lagen? Är det värt pengarna? — svar i den ordningen, utan sju system.*

**Primära åtgärder:** Eskalera till incident & starta MCF-klockan · Generera MCF-rapportunderlag · Sök
i SDK-loggen / exportera åtkomstlogg · Provisionera/avetablera användare & funktionsadress · Sammanställ
nytta & efterlevnad för ledningen.

**Widgetlayout**
- **main:** `complianceStatus` · `incidentrapporter` · `sakerhetshandelser` · `loggSparbarhet` · `authLoa`
- **side:** `systemhalsa` · `provisionering` · `arkivGallring` · `dataSuveranitet` · `nytta`

**KPI:er:** Compliance-status (sammanvägd, mål grön; mappad mot Infosäkkollen ≥ nivå 3) · Öppna
säkerhetshändelser → incidenter + andel MCF-deadlines hållna (0 missade) · SDK-loggretention 12/12
mån sökbar · Andel sessioner LOA3 + MFA-täckning 100 % · Systemhälsa/upptid + ej hanterade meddelanden
> X dgr · Tredjelandsöverföringar (mål 0) · Gallring satt vs ej · Nytta/ROI (ersatta fax × ~30 min) ·
Provisioneringshygien (avetablering samma dag, inga föräldralösa konton).

**Terminologi:** Säkerhetshändelse → Incident · Tidig varning / Incidentanmälan / Lägesrapport /
Slutrapport (MCF) · Tillitsnivå (LOA3) · Loggretention/spårbarhet · Funktionsadress · Gallring/bevarande/
FGS-leverans · Datasuveränitet / i er driftmiljö · Avetablering/provisionering · Nytta/frigjord tid/
årsarbetskrafter · Säker kanal. UI-copy: "All data i er driftmiljö", "Inloggning på tillitsnivå 3",
"Krypterat till endast avsedd mottagare (HSLF-FS 2016:40)", "eIDAS2-redo", "SDK-loggretention 12/12 mån — sökbar".

**Signeringsflöde:** Inte en signerande roll — men äger **bevarande-/valideringsvyn** "Giltig nu /
Giltig då" (PAdES/PDF/A/LTV per arkiverat signerat dokument, "Verifiera underskrift nu") som
revisions-/överklagandebevis, exponerad inom `arkivGallring`/compliance-export.

---

## 6. Tillgänglighet & sekretess (gäller alla vyer)

**WCAG 2.2 AA (DOS-lagen / EN 301 549):** Target Size ≥24×24 px på status-/klar-/snabbåtgärdsknappar;
Dragging Movements (2.5.7) — omordning av kort måste ha knapp-/tangentbordsalternativ, inte bara drag;
Focus Not Obscured (2.4.11) — sticky frist-/filterpaneler får inte dölja fokus; Consistent Help (3.2.6)
+ Consistent Identification (3.2.4) — `kunskapsbank`/hjälp på fast plats, samma ikon = samma funktion
mellan roller; Accessible Authentication (3.3.8) — BankID/Freja/SITHS utan kognitiva test; Reflow/
Orientation — 400 % zoom + porträtt; nedräkningsklockor (frister, MCF, laga kraft) aldrig enbart färg.

**Sekretess & dataskydd:** OSL 10:2a + eSam ES2023-06 — on-prem eliminerar lämplighetsbedömningen;
behörighet = säkerhetsgräns (en widget visar aldrig innehåll från en funktionsbrevlåda utan
behörighet); HSLF-FS 2016:40 (kryptering + stark autentisering, kan ej avtalas bort); GDPR
dataminimering (korttext default = ärendereferens, inte klartextcitat) + ingen beteendeprofilering;
arkivlagen (1990:782) + arkivförordningen (2024, FGS-export); NIS2/cybersäkerhetslagen (2025:1506) —
spårbara, bevarade händelser; eIDAS/LOA3 + eIDAS2-redo.

---

## 7. Källförteckning (urval — fullständiga referenser i `analysis-output/extended/`)

- **Personas & användningsfall:** `analysis-output/market-personas-anvandningsfall.json` (514k
  orosanmälningar 2024, Socialstyrelsen; Diggs nyttoschablon 1 620 mnkr/år & ~30 min/ärende; SKR
  funktionsadresser 2025; HR-gapet 66 %; SIP/Skype-gapet Region Uppsala 2022).
- **Personalisering:** `research-personalisering.md` (Viva audience targeting; låst kärna + kuraterat
  skal; lokal AI/Ethical-AI; GDPR art. 22).
- **E-signering:** `research-esignering.md` (SKR vägledning dec 2025; Inera Underskriftstjänst/Sweden
  Connect; eIDAS SES/AES/QES; PAdES/PDF/A/LTV; Scrive/Assently-benchmark).
- **Uppgifter/bevakning:** `research-uppgifter.md` (GOV.UK task-list; FB 14:15; FL 2017:900 §§11–12;
  Deck #1549/#566; Provisum/Aider).
- **Säkra filer:** `research-filer.md` (ärenderum/Groupfolders/ACL/Retention; FGS/Sydarkivera;
  arkivförordningen 2024; Storegate-benchmark).
- **Utskrivning/HSL:** `research-utskrivning-hsl.md` (lag 2017:612; betalningsansvar 3 dygn/HSLF-FS
  2025:74; Lifecare SP; samverkansavvikelser; HSLF-FS 2016:40).
- **Compliance/NIS2:** `research-compliance-nis2.md` (cybersäkerhetslag 2025:1506; MCF 24h/72h/1 mån;
  SDK-logg 12 mån; cybermiljarden; Infosäkkollen 31 %/69 %).
- **Forms/Tables/Kalender/Whiteboard/Maps/Notes:** `research-forms-apps.md` (Forms-gränser;
  Tables-motor; Calendar auto-videorum; Maps endast v31).
- **Medborgaridentifiering:** `research-citizen-id-onboarding.md` (BankID/Freja/SITHS; Sverige-id
  dec 2026; EUDI/eIDAS2; SMS-OTP restricted/NIST; ombud; Mina meddelanden/SOU 2024:47).
- **Persona-vyer:** `persona-socialsekreterare.md`, `persona-registrator.md`, `persona-hr_chef.md`,
  `persona-overformyndare.md`, `persona-forvaltare.md` + HSL-personan härledd ur
  `research-utskrivning-hsl.md`.
