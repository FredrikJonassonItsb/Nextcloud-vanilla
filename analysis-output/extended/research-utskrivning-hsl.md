# Kommunal hälso- och sjukvård: utskrivningsprocessen och samordnad planering — underlag för Hubs dashboard

> Fokus: vad en **kommunsjuksköterska** (och MAS/planeringssjuksköterska/biståndshandläggare) behöver bevaka på sin dashboard kring utskrivning från slutenvård, samordnad planering (SVPL/SPU), SIP, betalningsansvar, remiss-/meddelandeflöden region↔kommun via SDK, samt avvikelsehantering. Avgränsning: detta är **flödena runt om** det regionala planeringssystemet (Lifecare SP m.fl.) — inte ett försök att ersätta själva planeringsplattformen.

---

## Sammanfattning

Utskrivning från sluten vård är ett av kommunsektorns mest tids- och säkerhetskritiska informationsflöden. Det regleras av **lagen (2017:612) om samverkan vid utskrivning från sluten hälso- och sjukvård** (ersatte betalningsansvarslagen 1990:1404 den 1 januari 2018) och drivs i praktiken av regionala IT-system för **samordnad planering** — främst **Lifecare SP (Tietoevry)**, men också Cosmic Link, SAMSA (VGR), Meddix och Prator. Dessa system bär de strukturerade meddelandetyperna (inskrivningsmeddelande, planeringsmeddelande, underrättelse om utskrivningsklar, kallelse till SIP). De är HSA-/SAMBI-/SITHS-anslutna och utgör en egen inloggning vid sidan av kommunens verksamhetssystem (Treserva, Combine, Viva, LifeCare VoO).

Kärnproblemet för Hubs är **inte** att konkurrera med planeringssystemet, utan att kommunsjuksköterskan idag måste **bevaka flera silor parallellt** och att allt som *inte* ryms i det strukturerade flödet — kompletterande remisser, läkemedelslistor, hjälpmedelsunderlag, frågor mellan vårdgivare, planeringsunderlag till privata utförare, kommunikation med små vårdgivare utan systemanslutning — fortfarande går via **fax, telefon och brevpost**. Det är där SDK-meddelanden och säker e-post kommun↔region kommer in, och det är där brister uppstår som måste hanteras via **avvikelserapporter**.

Tre saker gör detta till ett starkt Hubs-område 2025–2026:

1. **Ekonomisk skarphet.** Missad bevakning av "utskrivningsklar" kostar kommunen direkt pengar: betalningsansvaret bygger på en genomsnittsmodell (kommunen ska i snitt ta hem patienter inom **tre kalenderdagar** per månad, annars betalar kommunen för överskjutande dygn) à en av Socialstyrelsen årligen fastställd dygnskostnad (10 500 kr/vårddygn 2023; årligt belopp för 2026 i HSLF-FS 2025:74). En dashboard som aldrig tappar en "utskrivningsklar"-signal har ett mätbart kronvärde.
2. **Patientsäkerhet och avvikelser.** Brister i informationsöverföring i vårdens övergångar är ett av de största riskområdena (Socialstyrelsen/patientsäkerhet). Varje glapp ska avvikelserapporteras — en pågående, dokumenterad börda.
3. **Rättsligt skarpa krav på kanalen.** HSLF-FS 2016:40 kräver kryptering och stark autentisering för elektroniska meddelanden med personuppgifter i hälso- och sjukvården — kraven kan inte avtalas bort med samtycke. Det diskvalificerar vanlig e-post/fax och legitimerar exakt Hubs kanaltyper (SDK, säker e-post, säker fax-brygga).

**Slutsats:** Hubs ska positionera sig som **"den säkra kanalen och bevakningsytan runt utskrivningen"** — en kommunsjuksköterske-vy som (a) aggregerar inkommande SDK/säker e-post/fax kopplat till utskrivningsärenden, (b) ger en deadline- och statusdriven "att hantera"-kö med tydlig markering av betalningsansvars-risk, och (c) gör avvikelse­rapporten till ett ett-klicks-flöde från meddelandet där bristen syns. Hubs konkurrerar inte med Lifecare SP — Hubs fångar allt som faller utanför det.

---

## Marknad & aktörer

### Regionala system för samordnad planering (de som äger det strukturerade flödet)

Dessa är **inte primära Hubs-konkurrenter** — de är systemen kommunsjuksköterskan redan bevakar och som Hubs ska komplettera/aggregera mot. Marknaden är regionalt fragmenterad:

| System | Leverantör | Var det används | Relevans för Hubs |
|---|---|---|---|
| **Lifecare Samordnad Planering (Lifecare SP)** | Tietoevry Care | Marknadsledande och växande. Region Gotland och Region Västerbotten driftsatte maj 2025; Region Västernorrland senare 2025; tre regioner driftsatte i en gemensam våg hösten 2025 (Tietoevry-pressmeddelande okt 2025). | Det system kommunsjuksköterskan loggar in i separat (HSA/SAMBI/SITHS). Stöd för både **SPU** (samverkan vid utskrivning) och **SIP**. Hubs aggregerar runt det, inte mot det. |
| **Cosmic Link** | Cambio (modul i Cosmic-journalen) | Regioner med Cambio Cosmic (t.ex. Region Blekinge, Region Jämtland Härjedalen). | Ytterligare en silo kommuner bevakar i Cosmic-regioner. |
| **SAMSA** | Gemensam IT-tjänst i Västra Götaland (VGR + 49 kommuner via VästKom/GITS) | Hela Västra Götaland. | VGR-specifik; egna rutiner för meddelandehantering och betalningsansvarsberäkning. |
| **Meddix** | (äldre, fasas ut/ersätts regionvis) | Bl.a. tidigare Region Halland. | Visar att marknaden är mitt i ett **systembyte** — fönster för att etablera nya arbetssätt. |
| **Prator** | Imano/Prator | Bl.a. Uppsala kommun, flera regioner historiskt. | Samma mönster: kommunen har en manual per system och loggar in separat. |

**Marknadssignal:** flera regioner byter samordnad-planering-system just nu (2025), oftast till Lifecare SP. När arbetssätt och rutiner görs om är tröskeln lägst att också införa Hubs som den samlade bevaknings- och säkra-kanal-ytan runt omkring. Ingen av dessa planeringsleverantörer löser kommunens **tvärkanaliga** behov (SDK + säker e-post + fax + video + filer i en vy) — de löser bara det strukturerade SPU/SIP-flödet inom sin egen region.

### Kommunens egna verksamhetssystem (parallella silor)

Kommunsjuksköterskan dokumenterar i kommunens HSL-journal (t.ex. **Treserva** (Evry/Tietoevry), **Combine** (Pulsen), **Viva** (Flexite/CGI), **Lifecare VoO**), medan biståndet och hemtjänsten arbetar i samma eller angränsande system. Poängen för Hubs: sjuksköterskan har **redan minst två system** (regionens planeringssystem + kommunens journal) och vill inte ha ett tredje som *adderar* — Hubs måste *aggregera och bevaka*, inte bli "system nummer åtta" (jfr Arbetsmiljöverkets fynd om digital arbetsmiljö i grundanalysen).

### Konkurrenter på den säkra kanalen (Hubs faktiska konkurrenter)

Från grundanalysen (`market-konkurrenter-meddelanden.json` / `-video.json`), applicerat på utskrivningsområdet:

- **SDK-klienter / säkra meddelanden:** Compodium/**TDialog**, **Sefos** (Meaplus), **CGI** (Messit), **SecureAppbox**, Certezza, Ida Infront — alla kvalificerade i Adda DIS "Säker digital kommunikation" där även **ITSL Solutions** finns med. Ingen av dem paketerar ett utskrivnings-/HSL-specifikt arbetsflöde; de är generella meddelandeklienter.
- **Digital fax (faxersättning/brygga):** Generic (DOCS), TellusTalk, SecureAppbox GDPR e-Fax, Wx3. Relevant eftersom **vårdplaner, läkemedelslistor och hjälpmedelsunderlag fortfarande faxas** mellan region och kommun (VGRfokus 2023). Krympande övergångsmarknad — sälj som migreringsbrygga.
- **Säkra videomöten (för SIP):** **Compodium/Vidicue**, **Digitala Samtal** (integrerar mot Tietoevry Lifecare), Visiba Care (vårdsidan). SIP-möten med patient/anhörig är ett tydligt videoanvändningsfall (se grundanalysen: Region Uppsala bedömde 2022 Skype som "säkraste plattformen" — marknaden saknar ett bra säkert alternativ).

**Vit fläck:** ingen aktör erbjuder en **kommunsjuksköterske-dashboard** som binder ihop bevakning av utskrivningsflödet med den säkra kanalen och avvikelsehanteringen. Det är Hubs öppning.

### Standard-/stödaktörer att förhålla sig till

- **Socialstyrelsen** — föreskriver HSLF-FS 2016:40; fastställer årligt belopp för utskrivningsklara (HSLF-FS 2025:74 för 2026); tillhandahåller ca 60 termer/koder för informationsutbyte vid utskrivning; juridiskt stöd "Samordnad individuell planering vid utskrivning från slutenvård".
- **SKR** — driver "Fakta om utskrivningsklara patienter — från betalningsansvar till sammanhållen vård", väntetidsstatistik om utskrivningsklara, samt SDK-/digitaliseringsstöd.
- **Inera / Digg** — SDK-infrastruktur (Digg federationsägare sedan mars 2024), HSA-katalog, SAMBI-federation, SITHS-eID (Inera) — den identitetsinfrastruktur Hubs måste tala med i vården.
- **Adda** — DIS "Säker digital kommunikation" är den sannolika avropsvägen.

---

## Juridik & krav

### Lagen (2017:612) om samverkan vid utskrivning — processen och tidslinjen

Lagen styr admissions-till-hemma-flödet för personer som efter utskrivning behöver insatser från **både** region (hälso- och sjukvård) **och** kommun (hälso- och sjukvård och/eller socialtjänst). Kärnsteg och tidskrav:

1. **Inskrivningsmeddelande** — slutenvården skickar ett inskrivningsmeddelande till berörda enheter i kommunen och i öppenvården (primärvård) **inom 24 timmar** efter inskrivning, med preliminär utskrivningsdag. Detta är den första signalen kommunsjuksköterskan måste fånga.
2. **Fast vårdkontakt** — primärvården (öppenvården) utser en **fast vårdkontakt** när inskrivningsmeddelandet kommit. Den fasta vårdkontakten ansvarar för att kalla till SIP.
3. **Planering inför utskrivning** — berörda enheter planerar parallellt med slutenvårdens behandling. Det är här kommunens hemsjukvård/biståndshandläggare måste agera tidigt för att hinna ordna insatser (hemsjukvård, hemtjänst, hjälpmedel, korttidsplats, bostadsanpassning).
4. **Underrättelse om utskrivningsklar** — när den behandlande läkaren bedömt patienten utskrivningsklar underrättas kommunen. **Nödvändig information ska vara överförd senast samma dag som utskrivningen** (HSLF-FS-/Vårdhandboken-kravet). Patienten ska få skriftlig sammanfattning (fast vårdkontakt, tidpunkt för SIP, befintliga vård- och omsorgsplaner).
5. **Kallelse till SIP** — den fasta vårdkontakten i öppenvården kallar till samordnad individuell planering (SIP) om patienten efter utskrivning behöver insatser från både region och kommun.

**Betalningsansvarsmodellen (kärnan för en dashboard):** Lagen är **dispositiv** — region och kommun träffar oftast en länsövergripande/regional **överenskommelse** om hur betalningsansvaret beräknas. Den vanligaste modellen (t.ex. Skåne, Dalarna, Norrbotten) är en **genomsnittsmodell per kalendermånad**: kommunen ska i genomsnitt ta hem alla utskrivningsklara patienter inom **tre (3) kalenderdagar** från det att patienten är utskrivningsklar (dygn noll inräknat). Klarar kommunen genomsnittet uppstår **inget** betalningsansvar; överstiger genomsnittet tre dygn betalar kommunen för de **överskjutande** dygnen. Lagens defaultnivåer (om ingen överenskommelse finns) är tuffare för psykiatrisk vård (betalningsansvar tidigast 30 vardagar efter kallelse till planering).

- **Belopp per vårddygn:** Socialstyrelsen fastställer årligt belopp motsvarande genomsnittlig dygnskostnad i sluten vård i riket. **8 200 kr (2020), 10 500 kr (2023)**; årligt belopp för **2026 i HSLF-FS 2025:74** (förordning 2017:617 är bemyndigandet). Beloppet räknas upp årligen. **Implikation:** ett missat/sent hanterat "utskrivningsklar"-meddelande kan kosta kommunen femsiffriga belopp per patient och dygn — bevakning är en ekonomisk kontroll, inte bara en bekvämlighet.

### HSLF-FS 2016:40 — krav på kanalen (den juridiska grunden för Hubs)

Socialstyrelsens föreskrifter och allmänna råd om journalföring och behandling av personuppgifter i hälso- och sjukvården:

- **Kryptering:** elektroniska meddelanden med personuppgifter ska skickas så att **endast avsedd mottagare** kan ta del av dem (i praktiken end-to-end-/transportkryptering med autentiserad mottagare).
- **Stark autentisering:** **flerfaktor / stark autentisering** krävs vid elektronisk åtkomst till uppgifter om patienter. I praktiken **SITHS-eID** för vårdpersonal (LOA3), BankID/Freja för medborgare/anhöriga.
- Kraven **kan inte avtalas bort** med den enskildes samtycke. Detta diskvalificerar okrypterad e-post, sms och oskyddad fax för innehåll som faller utanför det strukturerade planeringssystemet — och legitimerar Hubs säkra kanaler.

### Övriga lagkrav som träffar dashboarden

- **OSL (offentlighets- och sekretesslagen):** uppgifter i kommunal hälso- och sjukvård är sekretessreglerade (25 kap.). 10 kap. 2 a § OSL (sedan 1 juli 2023) + tystnadspliktslagen (2020:914) legaliserar utkontrakterad **teknisk** drift om det inte är olämpligt — men eSam:s vägledning ES2023-06 håller rättsläget restriktivt och **on-prem/kundägd data eliminerar hela lämplighetsbedömningen**. Detta är Hubs starkaste juridiska argument mot SaaS-konkurrenter (Lifecare SP, Compodium, Digitala Samtal, CGI kör leverantörsdrift; Hubs kör på kundens järn).
- **Patientdatalagen (2008:355) + HSLF-FS 2016:40:** styr journalföring och åtkomst; relevant eftersom utskrivningsinformation som hanteras i Hubs gränsar till journaluppgift. Hubs bör vara en **kanal/bevakningsyta**, inte en journal — håll den gränsen tydlig (undvik att bli en patientjournal med dess regelverk), men logga spårbarhet.
- **GDPR:** känsliga personuppgifter (hälsa) — krav på laglig grund (myndighetsutövning/allmänt intresse), dataminimering, gallring, åtkomstloggning. Ingen tredjelandsöverföring (Schrems II) — on-prem löser detta.
- **Arkivlagen (1990:782) + kommunala dokumenthanteringsplaner:** meddelanden som utgör allmän handling ska bevaras/gallras enligt plan. Dashboarden bör visa gallrings-/bevarandestatus och stödja export till e-arkiv.
- **eIDAS / eIDAS2 (EU 2024/1183):** stark e-legitimering. Idag SITHS (LOA3) för personal, BankID/Freja för medborgare; framåt EUDI-plånbok (2026/27→2028) och statlig e-legitimation (Polismyndigheten, senast 2 nov 2026) — arkitekturen bör vara "eIDAS2-redo".
- **DOS-lagen (2018:1937) / WCAG:** kommunal dashboard lyder under DOS-lagen → **EN 301 549**, bygg mot **WCAG 2.2 AA** (klickytor ≥24×24 px, fokus ej dolt av sticky-paneler, alternativ till drag-and-drop, tillgänglig autentisering för SITHS/Freja). Tillgänglighet är även ett upphandlingskriterium.
- **Cybersäkerhetslagen (2025:1506, NIS2, i kraft 15 jan 2026):** alla kommuner/regioner omfattas; ledningsansvar och incidentrapportering till MCF. Stödjer ett "compliance-fönster" i dashboarden (loggning, incidentunderlag).

---

## Funktioner att bygga

Designprincip (från `market-ux-trender.json`): **task-orienterad triage, inte statistik.** GOV.UK-statusar, Linear/Superhuman-triage, Viva Connections Card View + Quick View, command palette (Ctrl+K). Mät **tid-till-åtgärd**, inte tid på dashboarden.

### 1. Widget "Utskrivningar att bevaka" (kärn-widget för kommunsjuksköterskan)

En aggregerad, deadline-driven kö över **inkommande utskrivningsrelaterade meddelanden** oavsett kanal — det som kommer via SDK/säker e-post/fax kopplat till en patient/utskrivningsprocess. Varje rad har:

- **Status** (GOV.UK-mönster): `Nytt inskrivningsmeddelande` · `Planering pågår` · `Utskrivningsklar` · `SIP kallad` · `Hemtagen/Klar` · `Problem`.
- **Tidräknare mot betalningsansvar:** för "utskrivningsklar"-rader visas dygn sedan utskrivningsklar och en tydlig **risk-indikator** (grön < 3 dygn genomsnitt, gul nära gränsen, röd överskjutande = kostar pengar). Detta gör det ekonomiska kravet synligt — unikt mot alla konkurrenter.
- **Kanalindikator** (SDK / säker e-post / fax) per rad.
- **Snabbåtgärder (Card View):** Kvittera · Tilldela kollega · Svara säkert · Skapa avvikelse · Boka SIP-möte.

**Persona som gynnas:** kommunsjuksköterska och **planeringssjuksköterska/SVPL-samordnare** — den primära. Sekundärt **biståndshandläggare** (behöver agera tidigt i planeringen) och **enhetschef hemtjänst/hemsjukvård** (kapacitetsplanering för hemtagning).

### 2. Delad funktionsbrevlåda "Hemsjukvården / SVPL" med ansvarstilldelning

Utskrivningsbevakning är ett **teamflöde**, inte en personlig inkorg. Bygg på **funktionsadresser** (SKR:s 2025-rekommendation): en delad SDK-brevlåda per verksamhet (t.ex. `hemsjukvard@kommunen`, `svpl@kommunen`) med oläst-status, "vem tar detta?", vidaretilldelning och eskalering. Löser kontinuitet vid pass-/semesterbyten där utskrivningar annars tappas.

**Persona:** hela hemsjukvårds-/SVPL-teamet; MAS för uppföljning.

### 3. Avvikelse-i-ett-klick direkt från meddelandet

Brister i informationsöverföring vid övergångar är ett toppriskområde (Socialstyrelsen) och **ska avvikelserapporteras**. Idag är det ett separat, friktionsfyllt flöde i ett annat system. Bygg en knapp "Skapa samverkansavvikelse" på varje meddelande/ärende som förifyller: patient (pseudonymiserat/ärende-id), motpart (region/enhet), brist­typ (saknad läkemedelslista, för sen underrättelse, felaktig info, uteblivet inskrivningsmeddelande), tidsstämplar och bilagor — och kan skickas säkert tillbaka till regionens avvikelsefunktion via SDK. Detta vänder en lagstadgad börda till en differentiator.

**Persona:** kommunsjuksköterska (skapar), **MAS** (följer upp trender, patientsäkerhetsarbete enligt PSL 2010:659).

### 4. SIP-mötesflöde: kallelse + säkert videomöte + delad plan i ett

Paketera SIP end-to-end från dashboarden: **kallelse** (SDK/säker e-post till region, kommun, ev. anhörig) → **säkert videomöte** (Talk-baserat med BankID/Freja-verifiering av anhörig i lobby, SITHS för personal) → **delad planeringsyta/dokument** → **uppföljning/påminnelse**. Marknaden saknar ett bra säkert SIP-videoverktyg (Region Uppsala valde Skype i brist på alternativ; Digitala Samtal/Compodium är de närmaste men SaaS). Hubs on-prem + BankID-lobby är skarpare juridiskt.

**Persona:** fast vårdkontakt / SIP-samordnare; kommunsjuksköterska och biståndshandläggare som deltagare; **anhörig/patient** som extern deltagare utan myndighetskonto.

### 5. "Säker fax-in/ut"-brygga knuten till utskrivningsärendet

Vårdplaner, läkemedelslistor och hjälpmedelsunderlag faxas fortfarande från små vårdgivare/enheter utan SDK. Visa inkommande digital fax i **samma triagekö** som SDK/säker e-post, kopplad till rätt patient/ärende, och visa migreringsdata ("andel fax vs SDK denna månad") så kommunen ser sin egen faxavveckling.

**Persona:** kommunsjuksköterska; **förvaltningschef** (ser faxutfasning och nytta).

### 6. "Nytta & risk"-widget för chef/MAS (ROI + compliance)

Aggregerad: antal hanterade utskrivningar, **dygn över betalningsansvarsgräns denna månad (kr-exponering)**, antal samverkansavvikelser, andel ersatta fax, svarstider. Ger enhetschef/förvaltning ett ekonomi- och patientsäkerhetsunderlag och stödjer NIS2-/budgetmotivering (cybermiljarden).

**Persona:** **enhetschef / MAS / förvaltningschef** — köpar- och uppföljningsnivån.

### Tvärgående

- **Command palette (Ctrl+K):** "Skicka SDK till [funktionsadress]", "Skapa samverkansavvikelse", "Kalla till SIP", "Sök patient/ärende" — för den dagliga storanvändaren.
- **Leveransstatus/kvittens** synlig på varje skickat meddelande (levererat/öppnat/besvarat) — den emotionella ersättningen för "ringa och kolla att faxen kom fram".
- **Säker-kanal-/dataägarskapsmarkering** ("all data på er server, SITHS-inloggad, HSLF-FS 2016:40 uppfyllt") som synligt compliance-värde i gränssnittet.

---

## Rekommendation för Hubs

1. **Positionera som komplement, inte ersättare, till Lifecare SP m.fl.** Säljbudskapet är "Hubs fångar allt runt utskrivningen som inte ryms i ert planeringssystem — säkert, kvitterat och bevakat i en vy". Att uttalat *inte* konkurrera med planeringssystemet sänker köpmotståndet hos regionerna och hos kommunens MAS. (Varumärkesregel: säg "säkra meddelanden / säkert videomöte / Hubs", aldrig "Nextcloud"/"Talk".)

2. **Gör betalningsansvars-räknaren till signaturfunktionen.** Ingen konkurrent kopplar säker kommunikation till den ekonomiska konsekvensen av missad bevakning. En "utskrivningsklar"-rad med dygnsräknare och kr-riskindikator (mot regionens överenskomna genomsnittsmodell, belopp enligt HSLF-FS för året) är ett konkret, kvantifierbart värde som talar direkt till ekonomichef och förvaltningschef.

3. **Bygg funktionsbrevlåda + triage-kö före allt annat.** Kärnvärdet är "inget missat" över skiftbyten — vilket för sekretessbelagd vård är ett patientsäkerhets- och compliance-värde, inte bara bekvämlighet. Detta matchar SKR:s funktionsadress-rekommendation och kommunernas faktiska arbetssätt.

4. **Avvikelse-i-ett-klick är en billig, svårkopierad differentiator.** Den binder ihop kommunikationsbristen med dess obligatoriska uppföljning och ger MAS trenddata — ett argument ingen ren meddelandeklient har.

5. **Led med juridiken Hubs vinner på:** HSLF-FS 2016:40 (kryptering + SITHS-MFA) som *kravet* dagens fax/e-post inte klarar, och **on-prem/kundägd data** som det som eliminerar OSL-lämplighetsbedömningen (eSam ES2023-06) som plågar alla SaaS-konkurrenter (Lifecare SP, Compodium, Digitala Samtal, CGI). Detta är skarpare än "svenskt datacenter".

6. **SITHS-eID först, BankID/Freja för externa.** Vårdpersonal förväntar sig SITHS-inloggning (LOA3) — det är hygienkrav i kommunal HSL. Anhörig/patient i SIP-video verifieras med BankID/Freja i lobby. Förbered eIDAS2/EUDI-plånbok.

7. **Tajma mot systembytesvågen och cybermiljarden.** Flera regioner inför Lifecare SP 2025–2026 och gör om rutiner; kommuner har öronmärkta NIS2-medel 2026–2028. Demonstrera Hubs utskrivnings-vy mot Adda DIS-omfattningen (meddelandeklient, meddelandetjänst, accesspunkt) där ITSL redan är kvalificerad.

8. **Kravställ WCAG 2.2 AA och dokumentera per kriterium** — DOS-lagen gäller och tillgänglighet poängsätts i upphandling.

**Risk att bevaka:** om en region väljer att lägga säkra videomöten direkt i planeringssystemet (Digitala Samtal ↔ Lifecare-integration finns redan), kan delar av SIP-video-värdet ätas inifrån. Hubs motdrag är helheten (SDK + säker e-post + fax + video + avvikelse i en vy) + on-prem.

---

## Källor

Lag, föreskrift och process:
- Lag (2017:612) om samverkan vid utskrivning från sluten hälso- och sjukvård — https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/lag-2017612-om-samverkan-vid-utskrivning-fran_sfs-2017-612/
- Prop. 2016/17:106 Samverkan vid utskrivning från sluten hälso- och sjukvård — https://www.regeringen.se/rattsliga-dokument/proposition/2017/02/prop.-201617106
- Förordning (2017:617) om fastställande av belopp för vård av utskrivningsklara patienter — https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/forordning-2017617-om-faststallande-av-belopp_sfs-2017-617/
- HSLF-FS 2025:74 — belopp för vård av utskrivningsklara patienter för år 2026 (Socialstyrelsen) — https://www.socialstyrelsen.se/publikationer/hslf-fs-202574-socialstyrelsens-foreskrifter-om-belopp-for-vard-av-utskrivningsklara-patienter-for-ar-2026-2025-12-9956/
- HSLF-FS 2022:60 — belopp för år 2023 (10 500 kr/vårddygn) — https://www.socialstyrelsen.se/publikationer/hslf-fs-202260-socialstyrelsens-foreskrifter-om-belopp-for-vard-av-utskrivningsklara-patienter-for-ar-2023--2022-11-8255/
- Samverkanslagen — NU-sjukvården (inskrivningsmeddelande inom 24 h, fast vårdkontakt, tre karens-/kalenderdagar) — https://www.nusjukvarden.se/om-nu-sjukvarden/vardgivare/samverkanslagen/
- Länsövergripande överenskommelse om samverkan vid utskrivning, Region Dalarna 2025 (genomsnittsmodell, tre kalenderdagar) — https://www.regiondalarna.se/contentassets/b6ea59abd0454cf5b371b6c4cc3847dc/lansovergripande-overenskommelse-om-samverkan-vid-utskrivning-fran-sluten-halso--och-sjukvard-250117.pdf
- Samverkan vid vårdens övergångar, Vårdgivare Skåne (genomsnittsmodell, betalningsansvarsberäkning) — https://vardgivare.skane.se/uppdrag-avtal/kommunsamverkan/samverkan-sip-utskrivning-slutenvard/
- Ersättningsnivåer för utskrivningsklara patienter, Region Norrbotten — https://www.nllplus.se/Samverkan-utveckling-och-innovation/Samverkan/Kommunsamverkan/Samordnad-planering/Samordnad-vardplanering/Ersattningsnivaer-for-utskrivningsklara-patienter/
- SKR — Fakta om utskrivningsklara patienter (från betalningsansvar till sammanhållen vård) — https://skr.se/skr/tjanster/rapporterochskrifter/publikationer/faktaomutskrivningsklarapatienterfranbetalningsansvartillsammanhallenvard.81407.html
- SKR — Väntetider/utskrivningsklara patienter (statistik) — https://skr.se/vantetiderivarden/vantetidsstatistik/utskrivningsklarapatienter.54395.html

Informationsöverföring, SIP och journalkrav:
- HSLF-FS 2016:40 (konsoliderad) — Socialstyrelsen — https://www.socialstyrelsen.se/kunskapsstod-och-regler/regler-och-riktlinjer/foreskrifter-och-allmanna-rad/konsoliderade-foreskrifter/201640-om-journalforing-och-behandling-av-personuppgifter-i-halso--och-sjukvarden/
- Vårdhandboken — Informationsöverföring vid utskrivning — https://www.vardhandboken.se/arbetssatt-och-ansvar/samverkan-och-kommunikation/vardsamverkan/informationsoverforing-vid-utskrivning/
- Vårdhandboken — Kallelse till samordnad individuell planering (SIP) — https://www.vardhandboken.se/arbetssatt-och-ansvar/samverkan-och-kommunikation/vardsamverkan/kallelse-till-samordnad-individuell-planering/
- Socialstyrelsen — Samordnad individuell planering vid utskrivning från slutenvård (juridiskt stöd) — https://www.socialstyrelsen.se/kunskapsstod-och-regler/regler-och-riktlinjer/juridiskt-stod-for-dokumentation/samordnad-individuell-planering-vid-utskrivning-fran-slutenvard/
- Socialstyrelsen — Informationsutbyte vid utskrivning från sluten vård (termer/koder) — https://www.socialstyrelsen.se/statistik-och-data/klassifikationer-och-koder/tillampning-av-klassifikationer-urval/informationsutbyte-vid-utskrivning-fran-sluten-vard/

Avvikelsehantering / patientsäkerhet:
- Socialstyrelsen patientsäkerhet — Kommunikation och informationsöverföring (riskområde) — https://patientsakerhet.socialstyrelsen.se/risker-och-vardskador/riskomraden/kommunikation-och-informationsoverforing/
- Avvikelser i samverkan, sammanställning 2025 (VGR) — https://mellanarkiv-offentlig.vgregion.se/alfresco/s/archive/stream/public/v1/source/available/sofia/ssn15265-776135337-28/surrogate
- Region Sörmland — Avvikelser i samverkan — https://samverkan.regionsormland.se/for-vardgivare/nara-vard-och-halsa-i-samverkan/patientsakerhet-i-samverkan/avvikelser/
- Sollentuna kommun (MAS) — Avvikelsehantering HSL — https://www.sollentuna.se/uweb/utforarportalen/mas/rutiner-for-halso--och-sjukvard/1.-avvikelsehantering/

Regionala planeringssystem (Lifecare SP m.fl.):
- Tietoevry — Lifecare Samordnad Planering — https://www.tietoevry.com/se/care/halsa-och-sjukvard/primarvard-och-specialistvard/samordnad-planering/
- Tietoevry pressmeddelande okt 2025 — tre regioner driftsätter Lifecare Samordnad Planering — https://www.tietoevry.com/se/nyhetsrum/alla-nyheter-och-pressmeddelanden/pressmeddelande/2025/10/tre-regioner-tar-nasta-steg-for-en-samordnad-och-trygg-vard---driftsatter-lifecare-samordnad-planering-fran-tietoevry-ca/
- Region Västernorrland inför Lifecare Samordnad planering (okt 2025) — https://www.placera.se/telegram/tietoevry-region-vasternorrland-infor-lifecare-samordnad-planering-fran-tietoevry-20251008
- Region Norrbotten — Samordnad planering — https://vardgivarwebben.norrbotten.se/sv/samverkan-och-avtal/samordnad-planering/
- Region Blekinge — Cosmic Link — https://regionblekinge.se/halsa-och-vard/for-vardgivare/samverkan-blekinge/avtal-overenskommelser-och-rutiner/samordnad-halsa-vard-och-omsorg/verktyg/cosmic-link.html
- VästKom/GITS — SAMSA (ersättningsbelopp och betalningsansvar) — https://www.vastkom.se/gits/nyhetsarkiv/nyhetsarkivsamsa/aktuelltsamsa/informationavseendeersattningsbeloppforutskrivningsklarapatientersamtberakningavbetalningsansvarforpatienterinompsykiatriskvard.5.50cc920016fa8c77c583125e.html
- Region Gotland — Lifecare Samordnad planering (plattform för kommunikation) — https://samarbetswebb.gotland.se/rg/samarbetswebb/for-utforare-inom-socialtjanst-och-omsorg/lifecare-samordnad-planering---plattform-for-kommunikation

MAS / kommunal HSL-roll:
- SKR — Medicinskt ansvarig sjuksköterska (MAS) — https://skr.se/skr/arbetsgivarekollektivavtal/lonebildning/arbetsidentifikationaid/etikettlistaaid/aid/medicinsktansvarigsjukskoterskamas.80591.html

Kanal/regelverk (från grundanalysen, relevant här):
- eSam ES2023-06 — Utkontraktering, sekretess och dataskydd — https://www.esamverka.se/download/18.43a3add4188b9f2345a2fe78/1687332814480/ES2023-06%20V%C3%A4gledning%20Utkontraktering%20-%20sekretess%20och%20dataskydd.pdf
- Digg — SDK (Säker digital kommunikation) — https://www.digg.se/digitala-tjanster/saker-digital-kommunikation-sdk
- Adda — Säker digital kommunikation DIS — https://www.adda.se/upphandling-och-ramavtal/ramavtal-och-avtalskategorier/digitala-tjanster/saker-digital-kommunikation-dis/
- Digitala Samtal — säkra videosamtal för socialtjänsten / Lifecare-integration — https://digitalasamtal.se/sakra-videosamtal-for-socialtjansten/
