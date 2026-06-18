# Compliance- och säkerhetsdashboard för förvaltare — NIS2/cybersäkerhetslagen, ledningsansvar, incidentrapportering och nyttomätning

*Researchunderlag för Hubs (ITSL). Fokus: vilka KPI:er och widgetar en informationssäkerhetsansvarig (CISO/IS-samordnare) och en förvaltningschef faktiskt vill se i en säker-kommunikationsplattform — och hur Hubs gör efterlevnad av cybersäkerhetslagen till ett synligt produktvärde. Datum: 2026-06-13.*

---

## Sammanfattning

Den 15 januari 2026 trädde **cybersäkerhetslagen (2025:1506)** i kraft och genomför NIS2 i svensk rätt. Sverige gick längre än direktivet: **alla** kommuner och regioner omfattas oavsett storlek (ca 8 000 organisationer i 18 sektorer totalt). Tre saker förändrar köpbeteendet just nu:

1. **Ledningen har personligt ansvar.** Styrelse/nämnd och förvaltningsledning ska godkänna och följa upp cybersäkerhetsarbetet, genomgå utbildning, och kan vid allvarliga brister beläggas med **sanktionsavgift** (för väsentliga verksamhetsutövare upp till det högsta av 2 % av global årsomsättning eller 10 mkr euro) samt **förbud att utöva ledningsfunktion**. Det flyttar köpbeslutet från IT-avdelningen upp till ledningsnivå — och skapar efterfrågan på ett "compliance-fönster" som visar ledningen att arbetet faktiskt bedrivs.

2. **Incidentrapportering till MCF blir en löpande, tidsstyrd skyldighet** (24 h tidig varning → 72 h incidentanmälan → lägesrapport/slutrapport inom en månad). Få verktyg i kommunens dagliga arbetsyta hjälper handläggaren och CISO:n att producera detta underlag i tid.

3. **Pengarna finns men förmågan saknas.** Cybermiljarden ger kommuner **200 mkr/år** och regioner **50 mkr/år** 2026–2028 öronmärkt för NIS2-genomförande — samtidigt visar MSB/MCF:s **Infosäkkollen** att endast **31 %** av offentliga organisationer når någon mognadsnivå alls (69 % saknar de mest grundläggande delarna). Det finns alltså budget, ett lagkrav med datum, och en stor dokumenterad kompetens-/mognadslucka.

Den centrala produktinsikten: Hubs dashboard ska inte vara *ännu ett GRC-system* för CISO:n vid sidan om. Den ska göra de **säkra-kommunikationsflöden Hubs redan äger** (SDK, säker e-post, digital fax, säkert video, säkra filer) mätbara och rapporterbara, så att förvaltaren får (a) **bevis på efterlevnad** (kryptering, LOA3, dataägarskap, fullständig logg, 12 mån sökbarhet), (b) **incidentstöd** (säkerhetshändelser samlade, MCF-rapportunderlag förifyllt), och (c) **ROI-/nyttomätning** (antal ersatta fax/brev × Diggs 30-min-schablon, mot sektorsnyttan 1 620 mnkr/år). Det gör dashboarden till säljargument mot ledningen och till en motiverad post i cybermiljards-budgeten — inte bara ett IT-verktyg.

---

## Marknad & aktörer

### Tillsyns- och normgivande aktörer (vad de kräver av en dashboard)

| Aktör | Roll | Vad det betyder för en compliance-dashboard |
|---|---|---|
| **MCF** (Myndigheten för civilt försvar, f.d. MSB) | Central tillsyns- och föreskriftsmyndighet för cybersäkerhetslagen; tar emot incidentrapporter | Dashboarden bör producera **MCF-rapportunderlag** enligt rapporteringskedjan och visa **anmälningsstatus** (är verksamheten anmäld till MCF?). MCF förvaltar även **Infosäkkollen/It-säkkollen** (mognadsmätning) och **KLASSA/metodstödet** — naturliga ramverk att mappa KPI:er mot. |
| **Digg** | Federationsägare för SDK; ställer logg-/spårbarhetskrav på deltagarorganisationer | **Loggkravet (minst 12 mån, läsbar/sökbar form)** är ett konkret, mätbart krav Hubs kan visualisera som en grön/röd compliance-indikator. |
| **IMY** | Dataskyddstillsyn, tredjelandsöverföring | Dashboarden bör kunna visa **var data ligger** och **vem som haft åtkomst** (GDPR art. 5, 30, 32 — registerförteckning, säkerhet, ansvarsskyldighet). |
| **eSam** | Vägledningar (ES2023-06 utkontraktering, ES2025-05 ledningens förberedelser) | eSam:s "Ledningens förberedelser inför ny säkerhetslagstiftning 2025" (ES2025-05) är de facto-checklistan ledningen använder — bra mappningsmål för ledningsvyn. |
| **SKR / Adda / Inera** | Stöd, ramavtal, SDK-införande, nyttokalkyler | Sannolik upphandlingsväg (Adda "Säker digital kommunikation DIS"). Nyttokalkylen (Inera/Sigtuna, break-even år 3) är källan till ROI-widgetens schabloner. |
| Sektorsmyndigheter (**PTS, FI, IVO, Energimynd., Transportstyrelsen** m.fl.) | Sektorstillsyn + publicerar rapporteringsvägledning | PTS och Transportstyrelsen har redan publicerat de konkreta rapporteringsstegen/tidsfristerna — användbara primärkällor. |

### Konkurrenter och angränsande verktyg

- **Renodlade GRC-/LIS-plattformar** (governance, risk, compliance): t.ex. svenska aktörer som marknadsför NIS2-stöd — Secure State Cyber, Secify, eBuilder Security, samt generiska GRC-system (IntegritySpot, open source OpenGRC). Dessa hanterar **policyer, riskregister, åtgärdsplaner, mognadsmätning** men sitter *vid sidan om* den operativa kommunikationen. De vet inte hur många sekretessmeddelanden som väntar obesvarade eller om en delning gick till fel mottagare.
- **MSB/MCF:s egna verktyg**: **Infosäkkollen** (självskattning, fyrgradig mognadsskala; MSB:s föreskriftskrav ≈ nivå 3), **It-säkkollen** (teknisk förmåga) och **KLASSA** (informationsklassning, byggt på LIS-metodstödet). Dessa är gratis och normsättande — Hubs ska **mappa mot dem, inte konkurrera med dem**.
- **SIEM/loggplattformar** (Microsoft Sentinel, Splunk, open source Wazuh/Graylog): samlar säkerhetshändelser för hela IT-miljön. Hubs är inte ett SIEM, men kan **exportera** sina kommunikationsloggar dit och samtidigt visa de mest verksamhetsnära händelserna direkt i förvaltarvyn.
- **Microsoft Purview / Compliance Manager**: den dominerande "compliance score"-modellen — men förknippad med just den CLOUD Act-/tredjelandsexponering kommunerna försöker komma ifrån. Hubs differentierar med "compliance score utan att data lämnar er server".

**Adoption/marknadssignal (svensk offentlig sektor):** SDK passerade 100 anslutna organisationer aug 2025 (mål: majoritet av 290 kommuner under 2025; Region Stockholm anslöt sep 2025). Cybermiljarden börjar betalas ut 2026. Infosäkkollen visar att **endast 4 av 120 statliga myndigheter** når MSB:s definierade föreskriftsnivå och att **69 %** av offentliga organisationer saknar grundläggande systematiskt arbete — dvs. en stor adresserbar mognadslucka precis när lagkravet slår till.

---

## Juridik & krav (det dashboarden ska bevisa efterlevnad av)

### Cybersäkerhetslagen (2025:1506) — kärnan

- **Omfattning:** alla kommuner och regioner (Sverige gick längre än NIS2), 18 sektorer, ca 8 000 organisationer. **Anmälningsplikt** till MCF (föreskrifter, jfr MCFFS).
- **Systematiskt riskbaserat cybersäkerhetsarbete** (motsv. art. 21 NIS2): riskanalys, säkerhetsåtgärder, kontinuitetshantering, leverantörskedja, kryptering, åtkomststyrning, MFA, incidenthantering, utbildning. Föreskrifter om **säkerhetsåtgärder och utbildning samt incidentrapportering träder i kraft april 2026.**
- **Ledningens ansvar:** ledningen ska godkänna och följa upp åtgärderna och utbilda sig. **Sanktionsavgift** (minst 5 000 kr; väsentliga verksamhetsutövare upp till max av 2 % av global årsomsättning eller 10 mkr €; viktiga: 1,4 % / 7 mkr €). Tillsynsmyndighet kan via domstol begära **förbud att utöva ledningsfunktion**.

**Incidentrapportering till MCF — den tidsstyrda kedjan (källa: PTS/MCF):**

| Steg | Tidsfrist | Innehåll |
|---|---|---|
| **Tidig varning (förvarning)** | så snart som möjligt, **senast 24 timmar** efter upptäckt | att en betydande incident inträffat/kan inträffa |
| **Incidentanmälan** | så snart som möjligt, **senast 72 timmar** efter upptäckt (24 h för betrodda tillhandahållare) | initial bedömning, allvarlighet, påverkan, ev. indikatorer på angrepp |
| **Lägesrapport** | **inom en månad** om incidenten fortfarande pågår | uppdaterad statusbild |
| **Slutrapport** | **senast inom en månad** (efter avslut) | detaljerade omständigheter, konsekvenser, trolig orsak, vidtagna åtgärder |

> *Designkonsekvens:* dessa fyra deadlines är **nedräknande klockor**. En widget som visar "incident upptäckt 14:20 — tidig varning till MCF inom **18 h 40 min**" är exakt den sorts beslutsstöd ledningens personliga ansvar skapar efterfrågan på.

### SDK — loggkrav (Digg, verbatim)

> *"Deltagarorganisation ska säkerställa att alla meddelandeöverföringar via dess lokala komponenter loggas samt att logginformationen bevaras i minst 12 månader."*
> *"Deltagarorganisation ska säkerställa att loggar kan tas fram i läsbar form under hela denna tid"* (undantag endast om radering krävs av dataskyddsskäl eller lag).

Loggen ska enligt **Bilaga för IT-säkerhet inom SDK** innehålla: meddelandetyp/dokumenttyp, identifierare för accesspunkt, avsändnings-/mottagandetidpunkt, identifierare för avsändande/mottagande deltagare, AS4 Message ID och AS4 Conversation ID. **Loggar ska INTE omfatta meddelandeinnehåll.** Dessutom bör obehörig åtkomst/försök och åtkomst till information med utökat skyddsbehov loggas. → Hubs kan visa "**SDK-loggretention: 12/12 mån uppfylld, sökbar**" som grön compliance-indikator och erbjuda **sökgränssnitt mot AS4 Message/Conversation ID** (felsökning + tillsynsunderlag).

### Övrig relevant rätt (mappa till widgetar)

- **OSL** (offentlighets- och sekretesslagen) inkl. **10 kap. 2 a §** (sekretessgenombrott vid teknisk bearbetning/lagring hos leverantör) + **eSam ES2023-06**: motiverar "säker kanal"-markering och dataägarskaps-bevis i UI.
- **GDPR** art. 5/30/32: registerförteckning, **säkerhet i behandlingen**, ansvarsskyldighet — dashboarden levererar åtkomst-/delningslogg som bevis. **HSLF-FS 2016:40** (Socialstyrelsen): elektroniska meddelanden med personuppgifter ska krypteras till endast avsedd mottagare + **stark autentisering** (MFA) — Hubs uppfyller detta och bör säga det i klartext i UI.
- **eIDAS / eIDAS2 (EU 2024/1183)** och **Diggs tillitsramverk (LOA2–4)**: inloggning på **tillitsnivå 3** (BankID, Freja eID Plus, SITHS) är de facto-krav för känsliga uppgifter; arkitekturen ska vara EUDI-wallet-redo (2026/27) och förberedd för statlig e-leg (Polisen, senast 2 nov 2026). En widget kan visa **inloggad tillitsnivå** per användare/session.
- **Arkivlagen (1990:782) / RA-FS**: gallrings- och bevarandekrav på allmänna handlingar — relevant för hur länge meddelanden/loggar bevaras (samspelar med SDK:s 12 mån och med gallring av personuppgifter).
- **DOS-lagen (2018:1937) + EN 301 549 → WCAG 2.2 AA**: dashboarden själv måste vara tillgänglig (klickytor ≥ 24×24 px, fokus aldrig dold, alternativ till drag-and-drop, hjälp på konsekvent plats, inloggning utan kognitiva test). Tillgänglighet är även ett upphandlingskriterium — dokumentera efterlevnad per kriterium.

---

## Funktioner att bygga (widgetar + flöden)

Princip (från UX-grundningen): **task- och statusorienterat, inte grafdumpar.** Mät "tid till åtgärd", inte "tid på dashboarden". Rollstyrda standardlayouter (Viva-modellen Card View + Quick View), GOV.UK-statusar, progressive disclosure. Bygg som egen default-app + registrera widgetar via Nextcloud Dashboard-API (IAPIWidgetV2/IConditionalWidget för rollstyrning) — utan att säga "Nextcloud/Talk" i produkttexten.

### A. Ledningens compliance-översikt (persona: förvaltningschef / nämnd / CISO som rapporterar uppåt)

1. **Compliance-status-kort ("Cybersäkerhetslagen — efterlevnad")** — en sammanvägd indikator (grön/gul/röd) mappad mot kravområdena: anmäld till MCF ✓, incidentrutin aktiv ✓, loggretention 12 mån ✓, MFA/LOA3 på alla flöden ✓, datalagring i egen miljö ✓, senaste ledningsgenomgång daterad. Quick View expanderar till kravlista med status per punkt (GOV.UK-statusar: *Klar / Påbörjad / Saknas / Problem*). **Mappa explicit mot Infosäkkollen-nivåerna** så ledningen ser var de står mot MSB:s nivå 3.
2. **"Ledningens åtgärder"-kort** — bevis för det personliga ansvaret: datum för senaste ledningsbeslut, genomförd ledningsutbildning (per ledamot), godkänd åtgärdsplan, nästa uppföljningsdatum. Direkt motvärde mot sanktions-/ledningsförbudsrisken; underlag till nämndprotokoll.
3. **Datasuveränitets-kort** — "All data lagras i er driftmiljö. 0 tredjelandsöverföringar. Senaste externa åtkomst: ingen." Svar på OSL 10:2a-bedömningen och Microsoft-/CLOUD Act-oron, visualiserat.

### B. Incidenthantering & MCF-rapportering (persona: CISO / IS-samordnare / registrator)

4. **Incident-triagekö med nedräkningsklockor** — varje öppen säkerhetshändelse visar de fyra MCF-deadlinesen (24 h tidig varning / 72 h anmälan / 1 mån läges-/slutrapport) som nedräknande tidsfönster i färg. Verb-baserade åtgärder ("Skicka tidig varning", "Komplettera anmälan").
5. **MCF-rapportgenerator (förifylld)** — samlar relevanta händelser och genererar utkast till de fyra rapporttyperna med fält enligt MCF:s mall (omständigheter, konsekvenser, trolig orsak, åtgärder). Sparar timmar i en stressad situation och adresserar ett lagkrav få konkurrenter löser. Export/överföring till MCF:s rapporteringsväg.
6. **Säkerhetshändelse-feed** — aggregerar verksamhetsnära signaler från Hubs egna kanaler: misslyckade inloggningar, avvikande/utomgruppsdelningar, meddelanden till oväntad mottagare (SDK fel-funktionsadress), inloggning under låg tillitsnivå. Card View med "Eskalera till incident"-knapp som förfyller widget 5.

### C. Loggning, spårbarhet & åtkomst (persona: CISO / dataskyddsombud / registrator)

7. **Loggretentions-/spårbarhetspanel** — visar **SDK-loggretention 12/12 mån, sökbar** (Diggs krav uppfyllt), sökruta mot AS4 Message ID/Conversation ID, avsändare/mottagare, tidpunkt (utan meddelandeinnehåll, per Digg). Tillsyns- och felsökningsläge i ett.
8. **Åtkomst- och delningslogg ("Vem har sett vad")** — GDPR art. 30/32-bevis: åtkomst till handlingar med utökat skyddsbehov, delningar utanför organisationen, exporter. Filtrerbar per ärende/handläggare; export till SIEM.
9. **Tillitsnivå-/autentiseringskort** — andel sessioner på LOA3 (BankID/Freja/SITHS), MFA-täckning, "eIDAS2/EUDI-redo"-markering. Direkt HSLF-FS 2016:40- och tillitsramverks-bevis.

### D. Nytto-/ROI-mätning (persona: förvaltningschef — bygger budgetmotivering och cybermiljards-äskande)

10. **"Nytta hittills"-kort** — räknar antal ersatta fax / rekommenderade brev / okrypterade e-postmeddelanden via Hubs säkra kanaler, multiplicerar med Diggs **schablon ~30 min/ärende** → sparad tid och frigjorda årsarbetskrafter; relaterar till sektorsnyttan **1 620 mnkr/år ≈ 3 500 årsarbetskrafter** och Inera/Sigtuna-kalkylen (break-even år 3). Ger förvaltningschefen ROI-underlag och stödjer merförsäljning.
11. **"Riskreduktion"-kort** — antal felskickade-fax-tillfällen som undvikits (verifierad mottagare via funktionsadress vs. faxnummer), antal sekretessmeddelanden som *inte* gick i okrypterad e-post. Översätter Hubs till mätbar riskreduktion — språket ledningen och MCF förstår.
12. **Cybermiljards-/budgetkort (valfritt, för demo)** — paketerar ovanstående till en exporterbar "NIS2-åtgärd: kostnad vs. nytta vs. efterlevnad"-sammanställning som kan klistras in i bidragsmotivering (200 mkr/år-potten).

> **Persona-koppling i korthet:** kort A1–A3 + D10–D12 säljer mot **ledning/förvaltningschef**; B4–B6 + C7–C9 är **CISO:ns/IS-samordnarens** dagliga verktyg; C7–C8 betjänar även **registrator och dataskyddsombud**. Rollstyrning via IConditionalWidget gör att rätt persona ser rätt kort som standard.

---

## Rekommendation för Hubs

1. **Bygg en "Compliance & säkerhet"-vy som andra rollvy** i dashboarden (vid sidan av den operativa ärende-/inkorgsvyn), riktad till förvaltare/CISO och ledning. Den ska *härleda* allt ur Hubs egna kommunikationsflöden — inte bli ett tomt GRC-skal som kräver manuell datainmatning. Det är differentieringen mot Secify/Secure State Cyber/Purview: **operativ data → automatisk efterlevnadsbild**.

2. **Prioritera tre widgetar till första leveransen** (störst köp- och demovärde, lägst byggkostnad):
   - **Compliance-status-kort** (A1) mappat mot Infosäkkollen-nivåerna och cybersäkerhetslagens kravområden — säljargumentet mot ledningen.
   - **MCF-incidentkedja med nedräkningsklockor + rapportgenerator** (B4–B5) — adresserar ett nytt, tidsstyrt lagkrav som nästan ingen konkurrent löser i den dagliga arbetsytan.
   - **"Nytta hittills"-ROI-kort** (D10) — gör Hubs till en självmotiverande post i cybermiljards-budgeten.

3. **Gör loggkravet (Digg 12 mån, sökbar) till en synlig grön bock** (C7). Det är ett konkret, verifierbart krav Hubs redan måste uppfylla för SDK — visa det, så blir teknisk efterlevnad ett säljargument istället för en osynlig leverans.

4. **Led med dataägarskap och LOA3 i UI-copy** (A3, C9): "All data i er driftmiljö", "Inloggning på tillitsnivå 3", "Krypterat till endast avsedd mottagare (HSLF-FS 2016:40)", "eIDAS2-redo". Detta är skälet att inte använda Teams/Outlook för dessa flöden — gör det explicit i gränssnittet.

5. **Mappa, konkurrera inte, mot MSB/MCF-ramverken.** Använd Infosäkkollens fyrgradiga skala och KLASSA-/LIS-metodstödets struktur som rubriker i compliance-vyn. Då känner CISO:n igen sig, och Hubs blir ett verktyg som *fyller i* myndighetens egna självskattningar med riktiga data.

6. **Tillgänglighet som kravbevis:** bygg compliance-vyn mot **WCAG 2.2 AA** från start (nedräkningsklockor får inte enbart förlita sig på färg; klickytor ≥ 24×24 px; tabellvyer för loggar). Dokumentera efterlevnad per kriterium — det är ett upphandlingskrav under DOS-lagen och ett extra säljargument.

7. **Varumärkesregel:** all produkt-/UI-text säger "Hubs säkra kanaler", "säkert videomöte", "säker fil-delning" — aldrig "Nextcloud"/"Talk". Loggkällor och AS4-begrepp är tekniska och kan exponeras, men inramas som Hubs/SDK-funktioner.

**Sammanfattad positionering:** *"Hubs gör cybersäkerhetslagen synlig. Ledningen ser sin efterlevnad, CISO:n rapporterar incidenter i tid till MCF, och förvaltningschefen kan räkna hem nyttan — allt utan att en enda sekretessuppgift lämnar kommunens egna servrar."*

---

## Källor

**Cybersäkerhetslagen, incidentrapportering, ledningsansvar, sanktioner**
- Cybersäkerhetslag (2025:1506), Sveriges riksdag — https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/cybersakerhetslag-20251506_sfs-2025-1506/
- MCF, Incidentrapportering enligt cybersäkerhetslagen — https://www.mcf.se/sv/amnesomraden/informationssakerhet-och-cybersakerhet/krav-och-regler-inom-informationssakerhet-och-cybersakerhet/nis-direktivet/cybersakerhetslagen-nis2/incidentrapportering-enligt-cybersakerhetslagen/
- PTS, Incidentrapportera enligt cybersäkerhetslagen (tidsfrister 24/72 h, lägesrapport/slutrapport) — https://pts.se/sakerhet-och-integritet/cybersakerhetslagen/incidentrapportera-enligt-cybersakerhetslagen/
- MSB, Det här är cybersäkerhetslagen — https://www.msb.se/sv/amnesomraden/informationssakerhet-cybersakerhet-och-sakra-kommunikationer/krav-och-regler-inom-informationssakerhet-och-cybersakerhet/nis-direktivet/det-har-ar-nis2-direktivet/
- FI, Det här gäller för nya cybersäkerhetslagen — https://www.fi.se/sv/publicerat/nyheter/2026/det-har-galler-for-nya-cybersakerhetslagen/
- Advokatfirman Lindahl, En ny cybersäkerhetslag (ledningsansvar, ledningsförbud) — https://www.lindahl.se/expertis/nationell-sakerhet-handelskontroll/en-ny-cybersakerhetslag-implementeringen-av-nis2-direktivet-i-svensk-ratt/
- Lawbox, NIS2-direktivet och cybersäkerhetslagen 2026 (sanktionsavgifter) — https://lawbox.se/eu/nis2-direktivet-och-cybersakerhetslagen-2026/
- Knowit, Incidentrapportering och informationsskyldighet i CSL — https://blogg.knowit.se/incidentrapportering-och-informationsskyldighet-i-csl-vad-som-komma-skall
- eSam ES2025-05, Ledningens förberedelser inför ny säkerhetslagstiftning 2025 — https://www.esamverka.se/download/18.a75eeb9197a1c1b55214e/1750835174641/ES2025-05%20Ledningens%20f%C3%B6rberedelser%20inf%C3%B6r%20ny%20s%C3%A4kerhetslagstiftning%202025.pdf

**SDK loggkrav & spårbarhet**
- Digg, Regelverk för deltagarorganisationer inom SDK (minst 12 mån, läsbar form) — https://www.digg.se/saker-digital-kommunikation/sdk-for-deltagarorganisationer/anslutningsavtal-regelverk-samt-bilagor/regelverk-for-deltagarorganisationer-inom-sdk
- Digg, Bilaga för IT-säkerhet inom SDK (loggens innehåll, AS4, ej meddelandeinnehåll) — https://www.digg.se/saker-digital-kommunikation/sdk-for-deltagarorganisationer/anslutningsavtal-regelverk-samt-bilagor/regelverk-for-deltagarorganisationer-inom-sdk/bilaga-for-it-sakerhet-inom-sdk
- Digg/Ena, Kravspecifikation loggning och spårbarhet — https://www.digg.se/styrning-och-samordning/ena---sveriges-digitala-infrastruktur/byggblock/sparbarhet/kravspecifikation-loggning-och-sparbarhet
- Digg, Säker digital kommunikation — https://www.digg.se/saker-digital-kommunikation

**Mätning, mognad & metodstöd**
- MSB, Resultatredovisning av Infosäkkollen och It-säkkollen (31 % / 69 %, 4 av 120 myndigheter) — https://www.msb.se/sv/publikationer/det-systematiska-informations--och-cybersakerhetsarbetet-i-den-offentliga-forvaltningen--resultatredovisning-av-infosakkollen-och-it-sakkollen-/
- MSB, Metodstöd för informationssäkerhetsarbete (LIS, KLASSA) — https://metodstod-informationssakerhet.msb.se/
- MCF, MSB:s metodstöd ur ett användarperspektiv — https://www.mcf.se/sv/publikationer/msbs-metodstod-for-systematiskt-informationssakerhetsarbete---ur-ett-anvandarperspektiv/

**Cybermiljarden & nyttokalkyl/ROI**
- Regeringen, Nationell satsning på cybersäkerhet (200 mkr kommuner / 50 mkr regioner / 2026–2028) — https://www.regeringen.se/pressmeddelanden/2025/09/nationell-satsning-pa-cybersakerhet/
- CGI, Få störst värde och säkerhet med Cybermiljarden — https://www.cgi.com/se/sv/blogg/offentlig-sektor/fa-storst-varde-och-sakerhet-med-cybermiljarden
- Digg debatt, "Visst är det dags att skrota faxen" (1 620 mnkr/år, 30-min-schablon) — https://www.digg.se/om-oss/nyheter/sdk---saker-digital-kommunikation/nyheter/2023-10-05-debatt-visst-ar-det-dags-att-skrota-faxen
- SKR, Säker digital kommunikation (SDK) — https://skr.se/digitaliseringivalfarden/digitalinfrastruktur/sakerdigitalkommunikationsdk.9116.html

**GRC-/konkurrentlandskap**
- Secure State Cyber, NIS2-direktivet — https://www.securestatecyber.se/nis2direktivet
- Secify, NIS2-direktivet — https://www.secify.com/artiklar/nis2-direktivet-vad-innebar-det-nya-direktivet/
- eBuilder Security, NIS2 Sverige 2026 — https://ebuildersecurity.se/articles/nis2-sverige-2026-guide/

**Tillgänglighet & identitet (kompletterande krav)**
- Digg, EN 301 549 och WCAG — https://www.digg.se/webbriktlinjer/lagar-och-krav/det-har-ar-en-301-549-och-wcag
- Digg, Tillitsnivåer för e-legitimering — https://www.digg.se/digitala-tjanster/e-legitimering/om-e-legitimering/tillitsnivaer-for-e-legitimering
- Socialstyrelsen, Kommunicera över internet eller andra öppna nät (HSLF-FS 2016:40) — https://www.socialstyrelsen.se/kunskapsstod-och-regler/regler-och-riktlinjer/juridiskt-stod-for-dokumentation/kommunicera-over-internet-eller-andra-oppna-nat/
