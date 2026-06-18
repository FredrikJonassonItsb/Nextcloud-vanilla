# Personalisering & rollbaserade dashboards för Hubs

*Fördjupningsanalys — kuraterade vs konfigurerbara vyer, persona-styrning, lokal AI-prioritering, progressive disclosure och WCAG 2.2. Underlag för Hubs (ITSL), den Nextcloud-baserade säkra kommunikationssviten för svensk offentlig sektor. Brand-regel: i produktnära text säger vi aldrig "Nextcloud" eller "Talk" — i denna interna analys används plattformsnamnen för precision.*

---

## Sammanfattning

Den centrala designfrågan för Hubs startsida är inte *om* den ska personaliseras, utan *hur långt* personaliseringen ska gå och *vem* som styr den. Marknadens mogna referensmodell — Microsoft Viva Connections — och de svenska offentliga aktörernas (Inera 1177 för vårdpersonal, Försäkringskassans designsystem) riktning pekar entydigt åt samma håll: **roll-/gruppbaserad standardlayout som default, med ett begränsat, kuraterat utrymme för egen anpassning ovanpå**. Det är "auto från roll/grupp + viss egen anpassning", inte en tom canvas där varje handläggare bygger sin egen vy.

Tre skäl gör den hållningen rätt för just Hubs:

1. **Enhetlighet är ett compliance- och arbetsmiljövärde, inte bara estetik.** För sekretessbelagda flöden (orosanmälningar, rehabärenden, SIP) är "inget missat" ett rättsligt krav (HSLF-FS 2016:40, OSL). En kurerad rollvy som garanterar att rätt kö, kvittensstatus och tidsfrister alltid syns skyddar bättre än en fritt konfigurerbar vy där en användare kan råka dölja den kritiska widgeten. Arbetsmiljöverkets fynd om kognitiv överbelastning av "många krånglande system" talar dessutom emot tomma-canvas-paradigmet.

2. **Den offentliga sektorns likabehandlingsprincip** gör att personalisering ska sänka tröskeln, inte skapa A- och B-användare. Forskning om personalisering i offentlig sektor betonar att medborgare/användare ska få likvärdig service — anpassningen får inte bli att den datorvane handläggaren ser mer.

3. **AI-prioritering måste vara lokal.** Hela Hubs värdeerbjudande är datasuveränitet (on-prem, kunden äger datan, ingen CLOUD Act-exponering). Adaptiva/AI-prioriterade vyer är en stark 2025–2026-trend (Gartner: 40 % av enterprise-appar har uppgiftsspecifika AI-agenter före slutet av 2026), men för Hubs får de bara byggas på lokala modeller (Nextclouds `llm2`-app, grön Ethical-AI-rating). Det vänder en begränsning till en differentiator: "AI-prioritering utan att ett enda meddelande lämnar er server."

**Kärnrekommendation:** Bygg persona-vyer som **deterministiska, rollhärledda standardlayouter** (registrator/funktionsbrevlåda, socialsekreterare, kommunsjuksköterska, HR, överförmyndarhandläggare, chef/ledning). Härled rollen automatiskt från Nextclouds grupp-/kontext-medlemskap. Tillåt **kuraterad** egen anpassning (visa/dölj en vitlistad uppsättning kort, ändra ordning, sätta egna sparade filter/sökningar) men lås de kort som rollen kräver för efterlevnad. Lägg AI-prioritering som ett *transparent, avstängbart* lager ovanpå en deterministisk grundsortering — aldrig som svart låda och aldrig som automatiserat beslut i GDPR-mening.

---

## Marknad & aktörer

### Microsoft Viva Connections — referensmodellen för kuraterad, rollstyrd dashboard

Viva Connections är den mest dokumenterade modellen för en kuraterad intranät-dashboard och den enda Hubs-relevanta produkten som har löst rollstyrning i skala.

- **Adaptive Card Extensions (ACE)** byggda på SharePoint Framework (SPFx) är dashboardens byggsten. Varje kort har två interaktionsnivåer: **Card View** (utför uppgiften direkt i kortet — t.ex. godkänn en ledighetsansökan) och **Quick View** (expanderad detaljvy utan sidbyte). Det är progressive disclosure inbyggt i komponentmodellen.
- **Audience targeting** är kärnan i rollstyrningen: kort, nyhetsinlägg och resурslänkar riktas mot specifika Microsoft 365-grupper, så att "en ACE som låter chefer granska ledighetsansökningar bara visas för chefer". Redaktören (inte slutanvändaren) konfigurerar vilken målgrupp som ser vilket kort.
- **Default-layout per roll, kuraterad av en redaktör** är alltså grundprincipen — användaren får en färdig vy, inte en byggsats. Microsofts uttalade best practice är ett gemensamt komponentbibliotek och designspråk så att användaren inte möter "10 olika gränssnitt" på samma dashboard.
- **2025–2026-riktning:** Viva fortsätter mot mer finkornig, attributbaserad målgruppsstyrning (Viva Engage Storyline Announcements med custom audiences via organisationsattribut/grupper/individer, public preview nov 2025, GA feb 2026). Trenden är *mer* kuratering och målgruppsprecision, inte mer fri-konfiguration.

**Lärdom för Hubs:** kopiera Card View + Quick View-mönstret och "redaktör/admin konfigurerar målgrupp, användaren får färdig vy". Nextclouds `IConditionalWidget` ger motsvarande gruppvillkorad visning gratis.

### Inera — 1177 för vårdpersonal (svensk offentlig referens)

Inera bygger nu ut 1177 till en **portal även för vårdpersonal** ("Sveriges samlingsplats för kvalitetssäkrat kunskapsstöd och tjänster" för anställda inom hälsa, vård, omsorg och tandvård). Målbilden (publicerad 2024–2025, mål 2030) betonar:

- **Samlingsplats med en inloggning** som ger åtkomst till Ineras autentiserade tjänster och integrerar med regionernas verksamhetssystem — dvs. samma "aggregera, inte addera"-logik som Hubs.
- **Integration med regionala verksamhetssystem** så att portalen blir ett samlat stöd snarare än ännu en silo.
- Etapp 2 av "Sammanhållen planering på 1177" startar feb 2026 och pågår till mitten av 2027 — överlappar Hubs SIP-/utskrivningsflöden direkt.

Målbilden är ännu inte uttalat roll-personaliserad i publika dokument (fokus ligger på *samling* och *enhetlighet*), vilket i sig är en signal: svensk offentlig sektor leder med enhetlighet och bygger personaliseringen ovanpå den, inte tvärtom. **Inera Design System (IDS)** (Figma + `ids-react`/`ids-angular`, obligatoriskt för Ineras tjänster sedan 2020) är den rätta referensen för komponent- och språkkonventioner om Hubs säljs mot regioner/vård.

### Svenska offentliga designsystem — enhetlighet som default

- **Försäkringskassans designsystem** (öppen källkod sedan okt 2024) och **Arbetsförmedlingens designsystem** är de starkaste svenska källorna för komponentkonventioner, formulärmönster och språk som myndighetsanvändare känner igen. Tolv myndigheter driver ett gemensamt projekt kring delade designresurser med FK och AF som initiativtagare.
- Ingen av dessa erbjuder fri dashboard-konfiguration för slutanvändaren — de levererar *konsekventa, kuraterade* gränssnitt. Det stärker rekommendationen att Hubs leder med rollvyer, inte med en personlig widget-canvas.
- **Digg** samordnar förvaltningsgemensam infrastruktur men har inte publicerat ett eget "Sveriges designsystem".

### Generiska referensprodukter (mönster, inte konkurrenter)

- **Notion / modern arbetsyta:** anpassningsbar Home med widgets — men i konsumentsegmentet, där fri konfiguration accepteras. Inte rätt förlaga för en sekretessbärande myndighetsvy.
- **Linear (Triage-inkorg) och Superhuman (Split Inbox):** kuraterade, opinionerade default-vyer med begränsad konfiguration — närmare Hubs rätta nivå än Notion.
- **openDesk (ZenDiS, Tyskland) och La Suite numérique (DINUM, Frankrike):** de nationella suveräna sviterna lägger ett **eget portal-/skal-lager** (Univention Nubus respektive egna förstapartsappar) ovanpå Nextcloud, med central navigation och SSO. Ingen av dem erbjuder fri dashboard-konfiguration — de levererar en kuraterad startyta. Det bekräftar Hubs arkitekturval: bygg en egen "default app" som startyta snarare än att försöka göra Nextclouds widget-grid rollstyrd.

### Lokal AI på plattformen (möjliggör adaptiva vyer utan suveränitetsförlust)

- **Nextcloud `llm2`-appen** kör enbart öppna modeller helt on-prem och fungerar som text-processing-backend för Assistant, Mail m.fl. via core Text Processing API.
- **Ethical AI Rating** (röd/orange/gul/grön) ger en omedelbar trafikljussignal för suveränitet/transparens/datakontroll; en fullt öppen modell (t.ex. **OLMo 2 7B/13B**) når **grön** rating. Nextcloud är dessutom första molnplattform med **Blauer Engel**-miljömärkning.
- Konsekvens: Hubs kan erbjuda AI-driven prioritering/sammanfattning i triage-kön och *dokumentera* att modellen är grön-ratad och körs lokalt — ett konkret upphandlingsargument.

---

## Juridik & krav

Personalisering och rollvyer är inte juridiskt neutrala. Flera regelverk träffar designen direkt.

### GDPR — profilering, automatiserade beslut och dataminimering (störst påverkan)

- **Artikel 22 + artikel 4(4) (profilering):** "Profilering" är automatisk behandling som *bedömer personliga egenskaper* (arbetsprestation, beteende, preferenser m.m.). En **AI-prioriterad vy** som rangordnar *handläggarens* arbete utifrån dennes beteende kan i värsta fall tolkas som profilering av den anställde. Avgränsning: Hubs AI ska prioritera **ärenden/meddelanden** efter sakegenskaper (tidsfrist, avsändartyp, sekretessnivå, oläst-status) — *inte* bygga beteendeprofiler av användaren. Dokumentera detta.
- **Automatiserat beslutsfattande:** IMY/Digg är tydliga — om AI bara ger *underlag* och en människa fattar beslutet är det inte automatiserat beslut enligt GDPR, **men** om personen "förlitar sig på AI-systemets underlag på ett avgörande sätt" kan det ändå räknas som automatiserat. Därför: AI-prioritering i Hubs får aldrig *gömma* eller *avföra* ärenden, bara *föreslå ordning*. Den deterministiska kön ska alltid vara åtkomlig, och AI-lagret ska gå att stänga av.
- **Dataminimering & ändamål (art. 5):** sparade personaliseringsinställningar (layout, filter, dolda kort) är personuppgifter knutna till en anställd. Behåll dem minimala, syftesbundna och raderbara. Profildata om en handläggare ska aldrig användas för annat än att rendera dennes vy.
- **IMY:s riktlinjer för generativ AI (2025)** och Diggs vägledning för generativ AI i offentlig förvaltning är de referensdokument kommunjurister kommer kräva att Hubs förhåller sig till.

### OSL & behörighet — vyn får inte avslöja det användaren inte får se

- **Rollbaserad åtkomstkontroll är en OSL-fråga, inte bara UX.** En kurerad rollvy måste vara hårt kopplad till behörighet: en widget får aldrig visa ärenderubriker, avsändare eller antal från en funktionsbrevlåda (t.ex. `orosanmalan@kommunen`) för någon som saknar behörighet till just den. "Audience targeting" i Viva-mening är här en *säkerhetsgräns*, inte en bekvämlighet.
- **10 kap. 2 a § OSL + tystnadspliktslagen (2020:914)** legaliserar utkontrakterad teknisk drift men kräver lämplighetsbedömning. Hubs on-prem-modell eliminerar bedömningen — och rollvyn bör visa att data ligger i kundens egen miljö (förstärker "säker kanal"-känslan).

### DOS-lagen / EN 301 549 / WCAG 2.2 — tillgänglighet i personaliserade vyer

Kommunala/regionala kunder lyder under **DOS-lagen (2018:1937)** och **EN 301 549**. Bygg mot **WCAG 2.2 AA redan nu** (EN 301 549 v4.1.1 med WCAG 2.2 väntas i kraft tidigast 2026). Personalisering aktualiserar specifika kriterier:

- **3.2.3 Consistent Navigation & 3.2.4 Consistent Identification (AA):** även när layouten är roll-personaliserad måste återkommande element (samma ikon = samma funktion, samma navigationsordning) vara konsekventa *mellan roller och sessioner*. Personaliseringen får inte bryta igenkänning — ett kort som flyttas av användaren ska behålla identitet och etikett.
- **3.2.6 Consistent Help (AA, ny i 2.2):** hjälpfunktionen ska ligga på samma relativa plats i varje vy, oavsett persona. Lås hjälp-/supportkortet utanför det fritt konfigurerbara utrymmet.
- **2.5.7 Dragging Movements (AA, ny):** om användaren får ändra kortordning med drag-and-drop **måste** det finnas ett icke-drag-alternativ (t.ex. "flytta upp/ner"-knappar eller en ordningslista). Nextclouds default-dashboard klarar inte detta — Hubs egen vy måste lösa det.
- **2.5.8 Target Size Minimum 24×24 px (AA, ny):** alla widgetknappar/ikoner (visa/dölj kort, snabbåtgärder i Card View) ska vara minst 24×24 px.
- **2.4.11 Focus Not Obscured (AA, ny):** fokuserad komponent får inte döljas av sticky paneler — relevant när kort expanderar (Quick View) eller när ett personaliserings-läge öppnar en panel.
- **1.3.4 Orientation & 1.4.10 Reflow:** rollvyn måste fungera lika väl i porträtt och vid 400 % zoom — viktigt eftersom hemtjänst/fältpersonal ofta är på mobil.
- **3.3.8 Accessible Authentication (AA, ny):** inloggning till vyn (SITHS/Freja/BankID) utan kognitiva test.

**Princip:** dela vyn i en **låst kärna** (compliance- och tillgänglighetskritiska kort som rollen alltid ser) och ett **konfigurerbart skal** (vitlistade kort användaren får ordna/dölja). Det löser både OSL-/HSLF-kraven och WCAG:s konsekvenskrav.

### HSLF-FS 2016:40 — kryptering & stark autentisering

Gäller flöden med personuppgifter i vård/omsorg: kryptering så att bara avsedd mottagare kan läsa, och **flerfaktor/stark autentisering vid elektronisk åtkomst**. Rollvyn ska vara åtkomlig endast efter LOA3-inloggning (BankID/Freja eID Plus/SITHS) — vilket också uppfyller WCAG 3.3.8.

### Arkivlagen / spårbarhet & NIS2

- **Arkivlagen (1990:782) & OSL:** själva *dashboardlayouten* är inte en allmän handling, men **åtgärderna i den är det** — när ett SDK-meddelande öppnas, kvitteras eller besvaras från ett kort ska det generera spårbara, bevarade händelser i underliggande system. Personaliseringen får inte kringgå diarieföring/bevarande.
- **Cybersäkerhetslagen (2025:1506)/NIS2 (i kraft 15 jan 2026):** alla kommuner/regioner omfattas; ledningen har personligt ansvar. En **chef-/ledningspersona** med compliance-vy (incidentstatus, ej hanterade säkra meddelanden, utgående svar med tidsfrist, åtkomstlogg) är därför inte bara en bekvämlighet utan ett konkret stöd för det systematiska cybersäkerhetsarbetet — och ett säljargument mot ledningsnivå.

---

## Funktioner att bygga

Konkreta dashboard-/persona-koncept. Varje persona får en **låst kärna** (alltid synlig, compliance/tillgänglighet) och ett **kuraterat skal** (vitlistat, omordningsbart). Roll härleds automatiskt från Nextcloud-grupp/kontext; admin sätter standardlayout per roll (analogt med Viva audience targeting / `IConditionalWidget`).

### Personabibliotek (auto från roll/grupp)

| Persona | Härleds från (grupp/kontext) | Låst kärna (alltid synlig) | Kuraterat skal (valbart) |
|---|---|---|---|
| **Registrator / funktionsbrevlåda** | medlem i funktionsadress-grupp (t.ex. `orosanmalan`) | Delad triage-kö (oläst/påbörjad/väntar/klar), kvittensstatus, "fördela ärende" | Egna sparade filter, fax-in-kort, "nytta hittills" |
| **Socialsekreterare** | grupp `socialtjanst` | Inkommande SDK/orosanmälningar med tidsfrist, mina ärenden | Kommande SIP-möten, dokumentmallar, sökta funktionsadresser |
| **Kommunsjuksköterska** | grupp `kommunal-hsl` | Bevakning av meddelanden från regionen (utskrivning/SVPL), avvikelser | Dagens möten, säker e-post-kö |
| **HR / personalärenden** | grupp `hr` | Avskild "känsliga personalärenden"-vy (rehab/läkarintyg/FK), kvittenser | Mallar för samtycke, kommande rehabmöten |
| **Överförmyndarhandläggare** | grupp `overformyndare` | Deadline-driven årsräkningslista (före 1 mars), inkommande från ställföreträdare | Granskningsstatus, fax/papper-brygga |
| **Chef / ledning** | grupp `chef`/`ledning` | NIS2-compliancevy: incidentstatus, ej hanterade meddelanden, svarstider, åtkomstlogg | "Nytta hittills"-ROI, volymtrender |

### Widget- och flödesidéer

1. **"Min dag"-kort (rollkurerat, Card View + Quick View).** Översta kortet sammanfattar rollens kritiska kö: antal nya, antal med tidsfrist idag, antal som väntar på motpart. Klick expanderar (Quick View) utan sidbyte. *Gynnar:* registrator/socialsekreterare som behöver "vad måste jag göra nu".

2. **Persona-väljare med "föreslagen roll".** Vid första inloggningen visas den auto-härledda rollens vy; en diskret väljare låter användare med flera roller (vanligt i små kommuner: samma person är registrator + handläggare) byta. Valet sparas men auto-default kvarstår. *Gynnar:* små kommuner med personer som bär flera hattar.

3. **Kuraterat "anpassa vy"-läge.** En explicit redigeringsknapp öppnar ett läge där användaren kan visa/dölja kort *ur en vitlistad uppsättning* och ändra ordning — med **knappbaserad** omordning (WCAG 2.5.7), inte bara drag. Låsta kärnkort visas gråtonade med hänglås och förklaring ("krävs för din roll"). *Gynnar:* alla; uppfyller WCAG och OSL utan att offra anpassning.

4. **Lokal AI-prioritering som transparent, avstängbart lager.** Ovanpå den deterministiska sorteringen (tidsfrist → sekretessnivå → oläst) lägger en lokal modell (`llm2`, grön rating) ett *förslag* på ordning och en kort sammanfattning per inkommande SDK-meddelande/säker e-post. Varje AI-sorterad rad visar **varför** ("föreslagen hög prio: tidsfrist imorgon + okänd avsändare"). En knapp "visa odredigerad kö" återställer deterministisk ordning. AI raderar/döljer aldrig. *Gynnar:* registrator/handläggare med hög inkommande volym (jfr 514 000 orosanmälningar/år). *Compliance:* prioriterar ärendeegenskaper, inte användarbeteende → ingen profilering enligt art. 22.

5. **"Säker kanal"- och datasuveränitetsmarkör.** Varje persona-vy bär en diskret markör "all data i er driftmiljö" och, där AI används, en grön Ethical-AI-badge med modellnamn. *Gynnar:* chef/ledning och upphandlare; gör suveräniteten synlig i demo.

6. **Compliance-/NIS2-kort (chef-persona).** Aggregerar säkerhetshändelser (misslyckade inloggningar, avvikande delningar), ej hanterade säkra meddelanden och utgående svar med tidsfrist; "exportera incidentunderlag" förenklar rapport till MCF. *Gynnar:* ledning med personligt NIS2-ansvar.

7. **Progressive disclosure genomgående.** Summeringskort → Quick View → full vy. Avancerade inställningar och sällan-funktioner döljs tills de behövs. Default-vyn per roll visar 4–6 kort, inte 12. *Gynnar:* hemtjänst/fältpersonal och ovana användare (motverkar kognitiv överbelastning).

8. **Ctrl/Cmd+K command palette (rollanpassade åtgärder).** Åtgärderna i paletten filtreras per roll ("Skicka SDK till funktionsadress", "Starta säkert möte", "Skapa SIP-kallelse"). Skalar från nybörjare (ignorerar) till expert (muskelminne). *Gynnar:* frekventa användare som registratorer.

---

## Rekommendation för Hubs

1. **Default = roll, inte tom canvas.** Härled personan automatiskt från Nextcloud-grupp/kontext-medlemskap och rendera en färdig, kuraterad vy. Detta matchar Viva (audience targeting), Inera 1177:s enhetlighetslinje och den offentliga likabehandlingsprincipen. Bygg vyn som en **egen "default app"-startyta** (som openDesk/Murena/La Suite gör) snarare än att försöka rollstyra Nextclouds generiska widget-grid — men registrera även Hubs-kort via `IConditionalWidget`/`IAPIWidgetV2` så data syns i standard-dashboarden och mobilklienter.

2. **Dela varje vy i låst kärna + kuraterat skal.** Den låsta kärnan bär compliance- (OSL/HSLF/NIS2) och tillgänglighetskritiska kort. Skalet är en *vitlistad* uppsättning kort användaren får ordna och dölja. Detta är den enda hållningen som samtidigt ger personlig anpassning *och* uppfyller WCAG 2.2:s konsekvenskrav och OSL:s behörighetsgränser.

3. **Konfigurerbarhet med knappar, inte bara drag.** Implementera omordning/visa-dölj med tangentbords- och knappbaserade kontroller (WCAG 2.5.7 Dragging Movements). Klickytor ≥ 24×24 px (2.5.8), fokus aldrig dolt (2.4.11), hjälp på fast plats (3.2.6). Dokumentera efterlevnad per kriterium — tillgänglighet är ett upphandlingsargument.

4. **AI-prioritering: lokal, transparent, avstängbar, aldrig destruktiv.** Lägg lokal AI (`llm2`, grön Ethical-AI-rating, t.ex. OLMo 2) som ett *förslagslager* ovanpå en deterministisk sortering. Visa alltid *varför* en rad prioriterats, gör det avstängbart, och låt AI aldrig dölja/avföra ärenden. Prioritera ärendeegenskaper (tidsfrist, sekretess, oläst), inte användarbeteende — så undviks profilering enligt GDPR art. 22 och positionen "AI utan att data lämnar er server" blir en differentiator.

5. **Minimera och syftesbind sparade inställningar.** Layout/filter/dolda kort är personuppgifter — håll dem minimala, raderbara och knutna enbart till renderingen. Ingen beteendeprofilering av anställda.

6. **Lås personaliseringen till behörighet.** En widget får aldrig avslöja innehåll (rubriker, avsändare, antal) från en funktionsbrevlåda användaren saknar OSL-behörighet till. Audience targeting är här en säkerhetsgräns.

7. **Designspråk:** bygg på `@nextcloud/vue` för att följa uppströms design (v32+), och låna språk/formulärkonventioner från Försäkringskassans och Arbetsförmedlingens öppna designsystem (IDS om mot region/vård) så vyn känns "byggd för svensk offentlig sektor".

8. **Sälj rollvyn mot rätt nivå:** registrator/handläggare-personorna säljer på vardagsnytta och tidsbesparing; chef-/ledningspersonan säljer på NIS2-ansvar och ROI. Persona-väljaren bör hantera multi-roll-verkligheten i små kommuner.

**Antimönster att undvika:** (a) tom widget-canvas som tvingar varje handläggare att bygga sin egen vy — bryter enhetlighet, riskerar dold compliance-kö, ökar kognitiv börda; (b) svart-låda-AI som omrangordnar utan förklaring eller avstängning — juridisk och förtroenderisk; (c) drag-only-konfiguration — WCAG-brott; (d) statistik-tunga vyer i stället för "nästa åtgärd" — mät tid-till-åtgärd, inte tid-på-dashboarden.

---

## Källor

**Microsoft Viva Connections / adaptive cards / audience targeting**
- https://learn.microsoft.com/en-us/viva/connections/available-dashboard-cards
- https://learn.microsoft.com/en-us/viva/connections/use-audience-targeting-in-viva-connections
- https://learn.microsoft.com/en-us/viva/connections/create-dashboard
- https://learn.microsoft.com/training/modules/viva-connections-extend-with-adaptive-card-extensions/3-extend-viva-connections-with-adaptive-card-extensions
- https://learn.microsoft.com/en-us/sharepoint/dev/spfx/viva/design/design-intro
- https://teams.handsontek.net/2025/12/08/whats-new-microsoft-viva-november-2025/

**Inera / 1177 för vårdpersonal / designsystem**
- https://www.inera.se/aktuellt/nyheter/malbild-for-1177-for-vardpersonal-ger-stod-for-utveckling/
- https://www.inera.se/tjanster/1177/malbild-for-1177/
- https://www.inera.se/utveckling/status-aktuella-initiativ/pagaende-utveckling/sammanhallen-planering-pa-1177/
- https://www.inera.se/aktuellt/nyheter/1177-vaxer-och-blir-aven-portal-for-vardpersonal/
- https://inera.atlassian.net/wiki/spaces/USI/pages/227213974/Komponentbibliotek

**Svenska offentliga designsystem**
- https://designsystem.forsakringskassan.se/latest/
- https://www.forsakringskassan.se/nyhetsarkiv/nyheter-press/2024-10-25-forsakringskassans-designsystem-kan-nu-anvandas-av-andra-myndigheter
- https://designsystem.arbetsformedlingen.se/

**GDPR / profilering / automatiserade beslut / AI i offentlig sektor**
- https://www.imy.se/privatperson/dataskydd/dina-rattigheter/automatiserade-beslut/
- https://www.imy.se/globalassets/dokument/riktlinjer-om-automatiserat-individuellt-beslutsfattande-och-profilering.pdf
- https://www.digg.se/ai-for-offentlig-forvaltning/riktlinjer-for-generativ-ai/overvag-om-automatiserat-beslutsfattande-och-overforing-till-tredje-land-forekommer
- https://www.digg.se/ai-for-offentlig-forvaltning/riktlinjer-for-generativ-ai/beakta-dataskyddsregelverket-som-utgangspunkt
- https://techlaw.se/sverige-imy-har-publicerat-vagledande-riktlinjer-for-gdpr-vid-anvandning-av-generativ-ai/
- https://blogg.sh.se/forvaltningsakademin/artikel/den-digitala-statsforvaltningen-rattsliga-forutsattningar-for-automatiserade-beslut-profilering-och-ai/
- https://www.regeringen.se/artiklar/2025/10/myndigheters-ai-anvandning-okar/

**WCAG 2.2 / tillgänglighet i personaliserade vyer**
- https://www.w3.org/TR/WCAG22/
- https://dequeuniversity.com/resources/wcag-2.2/
- https://silktide.com/accessibility-guide/the-wcag-standard/3-2/predictable/3-2-4-consistent-identification/
- https://www.digg.se/webbriktlinjer/lagar-och-krav/det-har-ar-en-301-549-och-wcag

**Lokal AI / suverän AI på plattformen**
- https://nextcloud.com/assistant/
- https://docs.nextcloud.com/server/latest/admin_manual/ai/app_llm2.html
- https://nextcloud.com/blog/nextcloud-ethical-ai-rating/
- https://nextcloud.com/blog/how-open-source-ai-models-can-help-you-take-control-of-your-privacy/

**Plattform / dashboard-API / suveräna portaler (kontext)**
- https://docs.nextcloud.com/server/latest/developer_manual/digging_deeper/dashboard.html
- https://www.opendesk.eu/en/product
- https://docs.opendesk.eu/operations/architecture/
- https://murena.com/discover-murena-workspace-your-private-all-in-one-online-suite-without-big-techs/

**Personalisering i offentlig sektor (princip)**
- https://www.researchgate.net/publication/222723624_Personalization_in_the_public_sector_An_inventory_of_organizational_and_user_obstacles_towards_personalization_of_electronic_services_in_the_public_sector

**Regulatorisk kontext (från grundningsanalyser)**
- https://www.imy.se/verksamhet/dataskydd/det-har-galler-enligt-gdpr/introduktion-till-gdpr/dataskyddsforordningen-i-fulltext/
- https://www.socialstyrelsen.se/kunskapsstod-och-regler/regler-och-riktlinjer/juridiskt-stod-for-dokumentation/kommunicera-over-internet-eller-andra-oppna-nat/
- https://www.msb.se/sv/amnesomraden/informationssakerhet-cybersakerhet-och-sakra-kommunikationer/krav-och-regler-inom-informationssakerhet-och-cybersakerhet/nis-direktivet/det-har-ar-nis2-direktivet/
