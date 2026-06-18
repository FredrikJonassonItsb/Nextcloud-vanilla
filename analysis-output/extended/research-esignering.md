# Research: Digital e-signering / e-underskrift för svensk offentlig sektor

*Underlag för Hubs (ITSL) — säker-kommunikationssvit för svensk offentlig sektor. Brand-regel: säg aldrig "Nextcloud"/"Talk" i produktnära text. Datum för analysen: juni 2026.*

---

## Sammanfattning

E-underskrift har gått från "trevligt att ha" till **standardrutin med statligt sanktionerade ramar 2025-2026**. Den enskilt viktigaste händelsen är **SKR:s vägledning "Digitala underskrifter" (december 2025)** som ger kommuner och regioner en gemensam, riskbaserad modell för att välja underskriftsnivå, upphandla och införa e-underskrifter rättssäkert. Den slår fast tre saker som styr hela produktdesignen:

1. **Underskrift behövs inte alltid.** För interna lågriskhandlingar (tjänsteanteckningar, IT-beställningar, enkla delegationsbeslut i inloggade system) räcker *digitalt godkännande* där bevisvärdet bärs av logg, spårbarhet och e-legitimering — inte av en formell signatur. Det är ett avgörande budskap: man ska inte tvinga fram QES överallt.
2. **Riskbaserad nivåval enligt eIDAS** — SES (enkel) för internt lågrisk, **AdES/AES (avancerad)** som arbetshäst för avtal, anställningsavtal och myndighetsbeslut där identitet och integritet är viktigt, och **QES (kvalificerad)** endast där lag kräver det eller vid gränsöverskridande ärenden. I praktiken är **avancerad e-underskrift via BankID/Freja den de facto-standard** som täcker nästan alla svenska kommunala behov.
3. **Bevarande är den svåra delen, inte signeringen.** Signaturen är trivial; att kunna *validera den om 10-50 år* är problemet. SKR och Riksarkivet pekar på **PAdES-format, PDF/A, långtidsvalidering (LTV), tidsstämpling och bevarade valideringsintyg/metadata**. Riksarkivets centrala princip: en handling är en allmän handling oavsett om signaturen går att verifiera — men bevisvärdet kräver att man arkiverar *beviset* om underskriften, inte förlitar sig på att kryptografin förblir giltig.

Marknaden är **molntung och SaaS-dominerad** (Scrive, Assently, Visma Addo/Sign, GetAccept, Verified, Comfact, Egreement). Det skapar exakt samma OSL/CLOUD Act-/datasuveränitetsfriktion som plågar Microsoft-spåret för meddelanden — och det är **Hubs öppning**: en **on-prem-signeringsfunktion på kundens egna järn, integrerad i samma dashboard som SDK, säker e-post, fax, video och filer**, så att "att signera" och "skickat för signering" blir köer bredvid säkra-meddelanden-inkorgen i stället för ett sjunde fristående system. Två nationella delade tjänster (**Inera Underskriftstjänsten** och **Digg:s öppna källkod för underskriftstjänst via Sweden Connect**) gör att Hubs inte behöver bygga signeringskärnan från grunden — den kan stå på svensk, suverän infrastruktur och äga *arbetsytan ovanpå*.

**Headline:** Hubs bör inte konkurrera med Scrive/Assently som en fristående signeringsprodukt — den bör bygga en **on-prem "Att signera"-kö och "Skickat för signering"-spårning** ovanpå Sweden Connect/Inera-underskriftstjänst, med PAdES/PDF/A-bevarande som inbyggt värde, och vinna på att signaturen lever *i* handläggarens säkra arbetsyta i stället för i ännu ett moln.

---

## Marknad & aktörer

### Nationell infrastruktur (det Hubs bör stå på, inte konkurrera med)

| Aktör / tjänst | Vad det är | Relevans för Hubs |
|---|---|---|
| **Inera Underskriftstjänsten** | Nationell delad underskriftstjänst för regioner, kommuner, kommunala bolag/förbund, statliga myndigheter och offentligt finansierade vårdgivare. Tre varianter: **Webb** (fristående, SITHS för uppladdning, valfri e-leg för signering), **API** (integreras i verksamhetssystem) och **Bas** (enkelsignering, t.ex. läkarintyg, SITHS eID). Stödjer **SITHS eID, BankID, Freja eID Plus och utländska eIDAS-godkända e-leg**. Format: **PDF (alla varianter), XML (Bas)**. Avancerad e-underskrift; ändringar i undertecknat dokument bryter underskriften. Följer Digg:s tekniska ramverk och RIVTA. | **Färdig, suverän signeringskärna med API.** Hubs kan integrera mot API-varianten och slippa bygga signeringsmotorn — särskilt stark för vård/omsorg där SITHS redan finns. Prissatt per kundtyp. |
| **Digg — Underskriftstjänst (öppen källkod)** | Digg **driver inte** en central signeringstjänst åt alla; istället tillhandahålls **öppen källkod på GitHub** för att bygga en egen fristående underskriftstjänst enligt **Sweden Connect** tekniska ramverk. Flödet: e-tjänst visar dokument → skapar signeringsbegäran med checksumma → användaren legitimerar sig och godkänner signeringsmeddelandet → tjänsten genererar nyckel+certifikat → signerat dokument returneras. | **Suverän, gratis grund att bygga egen signeringsnod på** för Hubs on-prem-erbjudande. Sweden Connect-profilen är upphandlingsmässigt "rätt" och eIDAS-förankrad. |
| **Sweden Connect (Digg)** | Federation för e-legitimering och underskrift; offentliga aktörer ansluter e-tjänster och får tillgång till BankID, Freja eID+ m.fl. via auktorisationssystemet och Digg:s förbetalda avtal. | Anslutningskravet i praktiken för all offentlig e-underskrift. Hubs underskriftsflöde måste tala Sweden Connect-profilen. |
| **BankID-signering** | Skapar **avancerad elektronisk signatur** likställd handskriven underskrift enligt eIDAS; signaturen appliceras på PDF i **PAdES-format**; minsta ändring bryter det kryptografiska sigillet (syns direkt i verifieringsvyn). 9+ miljoner användare. | Den e-leg medborgare och handläggare faktiskt har. Måste vara förstavalet. |
| **Freja eID Plus / Freja OrgID** | Godkänd på tillitsnivå 3 (även för icke-bofasta sedan dec 2024); **Freja OrgID** ger organisationsidentitet för tjänstesignering. | Andra-ben för e-leg; OrgID relevant för tjänsteunderskrifter (handläggare i tjänsten). |

### Kommersiella signeringsleverantörer (konkurrenter / referenser)

Marknaden är bred och **genomgående moln/SaaS** med icke-publika priser. De som syns mot svensk offentlig sektor:

- **Scrive** (svenskt) — uttalad **offentlig sektor-satsning**: stödjer BankID, Freja eID+ och Freja OrgID; **Scrive eIDAS Compliance (EC)** lagrar data inom EU på europeisk-ägd infrastruktur (uttrycklig CLOUD Act-/tredjelandsargumentation); marknadsför **AES och QES**, **PDF/A-arkivering enligt Digg:s riktlinjer**, **elektroniska sigill och tidsstämpling** som "centralt för offentlig sektor". Den mest sofistikerade offentlig-sektor-paketeringen — och därmed Hubs tydligaste benchmark för budskap.
- **Assently** (svenskt, E-sign) — flera e-leg-metoder, händelsespårning (audit trail), arkivering; nordisk offentlig kundbas.
- **Visma Addo / Visma Sign** — bland Sveriges mest använda; BankID + Freja; integreras mot CRM/ERP och verksamhetssystem; egen offentlig-sektor-sida. Addo distribueras bl.a. via Svensk e-identitet.
- **Comfact** — svensk underskriftsleverantör med stark profil mot **offentlig sektor och e-arkiv**; betonar elektroniska sigill + tidsstämpling för långsiktig giltighet. Mindre publik info, men återkommer i offentliga signeringssammanhang.
- **Egreement** (numera del av **Svensk e-identitet**, sammanslagning 2024) — avtal signerade med BankID är bindande enligt eIDAS art. 26 (avancerad nivå); "dokument lagras på dina villkor i ditt arkiv". Svensk e-identitet är landets största **HSA/SITHS-ombud** med kommuner, myndigheter och vårdgivare som kunder — en relevant integrationspartner-/konkurrentaxel.
- **GetAccept**, **Verified**, **Oneflow**, **Penneo** (nordisk), **Zigned**, **Sign On** (30-årig svensk aktör), **Signicat** (KYC/onboarding), **eAvtal/eSkd**, **TellusTalk** (digital fax + e-signering, redan kartlagd som meddelandekonkurrent) — bredare avtals-/säljfokuserade plattformar; mindre offentlig-sektor-djup.

### Svensk offentlig-sektor-adoption (mönster)

- **SKR:s vägledning (dec 2025)** + **Adda** är de facto upphandlingsstyrning; Sambruks nätverk har samlat **praktiska erfarenheter kring e-underskrifter** (kommungemensamt erfarenhetsutbyte).
- **Vård/omsorg** lutar mot **Inera Underskriftstjänsten** (SITHS-förankrad) för intyg, beslut och anställningsavtal.
- **Kommuner** kör en blandning av Inera-tjänsten, Scrive/Assently/Visma och egna Sweden Connect-noder. **Ingen aktör äger "signeringen *i* den säkra arbetsytan"** — det är gapet.

---

## Juridik & krav

### eIDAS / eIDAS2 — de tre nivåerna och vad de duger till

EU 910/2014 (eIDAS) + EU 2024/1183 (eIDAS2) reglerar e-underskrift. **En e-underskrift får inte underkännas enbart för att den är elektronisk** (icke-diskrimineringsprincipen, art. 25).

- **SES — enkel/simpel elektronisk underskrift.** Allt från en inskannad namnteckning till "jag godkänner"-klick. Lågt bevisvärde men juridiskt giltig. SKR: räcker för **interna lågriskhandlingar** — och ofta behövs ingen underskrift alls, bara ett loggat godkännande.
- **AdES / AES — avancerad elektronisk underskrift** (art. 26): unikt knuten till undertecknaren, identifierar denne, skapas med medel undertecknaren har egen kontroll över, och **all efterföljande ändring kan upptäckas**. **BankID och Freja skapar AES.** Detta är **arbetshästen för svensk offentlig sektor** — avtal, anställningsavtal, myndighetsbeslut, protokoll.
- **QES — kvalificerad elektronisk underskrift.** AES + kvalificerat certifikat från en kvalificerad betrodd tjänsteleverantör (QTSP) + säker signeringsanordning. **Enda nivån som per automatik är likställd handskriven underskrift i hela EU.** SKR: reservera för **det lag kräver eller gränsöverskridande**. BankID är *inte* QES i grunden.

**eIDAS2 / EUDI-plånbok (relevant 2026-2027):** Varje medlemsstat ska tillhandahålla en **EUDI-plånbok ungefär årsskiftet 2026/27**, som kan bära ett **kvalificerat certifikat och utfärda QES direkt från telefonen utan fysisk signeringsdosa**. Obligatorisk acceptans för offentlig sektor fasas in mot 2027. **Konsekvens för Hubs:** QES blir snart billig och allmänt tillgänglig för medborgare. Arkitekturen bör vara förberedd att ta emot EUDI-/QES-underskrifter, inte bara BankID-AES — det blir ett differentierande upphandlingsargument ("eIDAS2-redo") redan 2026, i linje med regulatorik-analysens slutsats.

### Svensk förvaltningsrätt — när krävs underskrift?

- **Förvaltningslagen / kommunallagen (2017:725):** Det finns **inget generellt krav på underskrift för myndighetsbeslut**. Ett delegationsbeslut behöver kunna **dokumenteras, anmälas och spåras** — men "underskrift" kan vara ett loggat godkännande i ett inloggat system. Delegationsbeslut ska anmälas och vinner laga kraft tre veckor efter justering/anslag. SKR: **enkla delegationsbeslut i inloggade verksamhetssystem behöver ingen formell signatur** — bevisvärdet bärs av logg + e-legitimering. Detta begränsar hur mycket "signera"-tvång som är rätt att bygga.
- **Avtal & anställningsavtal:** Inget formkrav på papper i svensk rätt; AES via BankID är giltigt och vanligt.
- **Intyg / läkarintyg / beslut inom vård och omsorg:** Hanteras ofta via Inera Underskriftstjänsten (Bas-variant, SITHS) — AES räcker.
- **Årsräkningar (överförmyndare, gode män/förvaltare, in före 1 mars):** Historiskt **krav på originalunderskrift** + bilagda verifikat. Ställföreträdaren får **lagstadgat välja papper** (Provisum/Aider digitaliserar granskningen). En e-underskriftskedja måste därför samexistera med pappersinlämning under lång tid.
- **SIP (samordnad individuell plan):** Planen ska godkännas/undertecknas av flera parter inkl. anhöriga utan myndighetskonto — ett **flerpartssigneringsflöde mot externa medborgare** är behovet, inte intern signering.

### OSL, GDPR och datasuveränitet

- **OSL:** Underskriftshandlingar innehåller ofta sekretessreglerade uppgifter (socialtjänst, HSL, personal). **Molnsignering = samma OSL 10:2a-/eSam ES2023-06-lämplighetsbedömning** som plågar Microsoft-spåret. En SaaS-signeringstjänst som ser dokumentinnehållet är en utkontraktering som måste bedömas. **On-prem/svensk drift eliminerar bedömningen** — Hubs starkaste argument.
- **GDPR:** Personnummer + personuppgifter i underskriftsmetadata; tredjelandsöverföring via amerikanska moln är en CLOUD Act-/Schrems-risk (jfr regulatorik-analysen). Scrive svarar med "EU-only EC"; Hubs svarar med "på kundens egna servrar".
- **HSLF-FS 2016:40:** Kräver kryptering så att bara avsedd mottagare läser + **flerfaktorsautentisering** vid elektronisk åtkomst till personuppgifter i vård. Signeringsflödet (LOA3-inloggning via BankID/Freja/SITHS) uppfyller detta by design.

### Arkivlagen & Riksarkivet — bevarande är kärnkravet

- **Riksarkivets princip:** "En handling med en elektronisk underskrift är en allmän handling oavsett om det går att kontrollera om handlingen har giltiga eller ogiltiga underskrifter." Innehållet är det centrala. Men **bevisvärdet** kräver att man bevarar *beviset* om underskriften.
- **RA-FS 2009:1 / 2009:2** (elektroniska handlingar, tekniska krav): skydd mot manipulation, obehörig åtkomst, och överföring till bevarandeformat.
- **Praktisk konsekvens (SKR + Riksarkivet):** signera i **PAdES**, arkivera i **PDF/A**, lägg på **långtidsvalidering (LTV)** + **tidsstämpling** + **bevarat valideringsintyg/metadata** så att underskriften går att bevisa även när certifikat löpt ut. Kryptografin förfaller; valideringsintyget är det som överlever. **Detta är den funktion ingen säljer bra och som Hubs bör bygga in.**

### DOS-lagen / WCAG 2.2

Signeringsgränssnittet är en digital tjänst i offentlig sektor → omfattas av **lagen om tillgänglighet till digitala offentliga tjänster (DOS-lagen)** och **WCAG 2.2 AA** (jfr UX-trender-analysen). Signeringssteg (välj dokument, granska, legitimera, bekräfta) måste vara tangentbordsnavigerbara, tydligt fokusmarkerade och skärmläsarvänliga — särskilt eftersom medborgare/anhöriga signerar SIP-planer m.m.

---

## Funktioner att bygga

Designprincip (från ekosystem-analysen): bygg inte om standard-dashboarden — bygg en **egen "default app"-vy** med rollstyrda köer, och registrera kompletterande widgets via widget-API:t. Signering ska vara **köer bredvid den säkra inkorgen**, aldrig ett sjunde fristående system. Stå på Inera Underskriftstjänsten (API) eller en egen Sweden Connect-nod (Digg open source) — bygg arbetsytan, inte kryptokärnan.

### 1. Widget "Att signera" (Att-göra-kö för inkommande signering)

En personlig + funktionsadress-baserad kö över dokument som väntar på *min* underskrift.

- **Innehåll per rad:** dokumenttitel, avsändare/ärende, **kravnivå (SES/AES/QES) med tydlig badge**, deadline (t.ex. årsräkning före 1 mars, beslut med laga-kraft-frist), antal övriga signatärer och deras status.
- **Åtgärd:** "Granska & signera" → öppnar dokumentet, visar **signeringsmeddelande/checksumma**, legitimering via BankID/Freja/SITHS (synlig **LOA3-markering**), bekräfta. PAdES-underskrift appliceras, PDF/A-kopia + valideringsintyg arkiveras automatiskt.
- **Riskmedveten design:** för rader som SKR säger *inte* kräver formell underskrift, visa "Godkänn" (loggat godkännande) i stället för "Signera" — undvik QES-tvång.
- **Persona som gynnas:** **överförmyndarhandläggaren** (deadline-driven årsräkningsgranskning), **socialsekreteraren** (beslut/utredningar), **kommunsjuksköterskan/HSL** (intyg, vårdplaner via SITHS), **enhetschef/HR** (anställningsavtal, rehab-beslut).

### 2. Widget "Skickat för signering" (utgående spårning)

Spegelbild av kön ovan — vad *jag* skickat ut och var det ligger. Detta är den direkta känslomässiga ersättningen för "ringa och kolla att faxen kom fram" (jfr personas-analysen).

- **Statuskedja per dokument:** *Skickat → Öppnat → Signerat av X av Y → Klart/Arkiverat* — eller *Avvisat / Påminnelse skickad / Utgånget*. Tydlig färgkodad tidslinje.
- **Flerpartsstöd:** visa varje signatär (intern handläggare, extern part, medborgare/anhörig) med individuell status; **påminnelse-knapp** per part.
- **Externa signatärer utan myndighetskonto:** notis via säker e-post/SDK-länk → BankID-signering i webbläsaren (samma mottagarmönster som säkra meddelanden, som medborgare redan känner igen). Ingen kontoregistrering.
- **Persona som gynnas:** alla avsändare; särskilt **SIP-samordnaren** (flera parter inkl. anhöriga) och **HR** (avtal till nyanställd + facklig part).

### 3. Bevarande- & valideringspanel ("Giltig nu / Giltig då")

Den funktion ingen konkurrent gör tydligt.

- Visar per arkiverat dokument: **signaturnivå, tidsstämpel, LTV-status, valideringsintyg** och en knapp "Verifiera underskrift nu" som kör validering mot Sweden Connect/Digg:s valideringstjänst och visar grönt/rött.
- **Compliance-värde:** direkt stöd för Riksarkivets bevarandekrav och blir bevis i en revision/överklagan. Knyter ihop med NIS2-/compliance-fönstret från regulatorik-analysen.
- **Persona:** **registrator/arkivarie**, **förvaltningschef/dataskyddsombud** (revisionsunderlag).

### 4. "Signera från ärende"-flöde (integration mot ärendehantering)

- Knapp "Skicka för signering" direkt från ett ärende/meddelande/dokument i Hubs (SDK-meddelande → beslut → signering → arkiv utan att lämna arbetsytan).
- API-koppling mot verksamhetssystem (Lifecare, ärendesystem) där möjligt; annars dokument-in/dokument-ut.
- **Mall-/policystöd:** organisationen definierar "dokumenttyp X kräver nivå Y" enligt SKR:s riskmodell, så handläggaren slipper välja eIDAS-nivå själv. Det operationaliserar SKR-vägledningen i produkten.

### 5. Tjänsteunderskrift / e-sigill (organisationssigill)

- Stöd för **organisationens e-sigill (försegling)** på utgående handlingar/beslut — bevisar att dokumentet kommer från myndigheten och inte ändrats, även när ingen fysisk person signerar (massbeslut, utskick). Kopplar till Riksarkivets/SKR:s e-sigill+tidsstämpling-rekommendation.
- **Persona:** **registrator**, **myndighetsutövande enheter** med volymbeslut.

### 6. Nytto-/migreringsmätare (säljbar ROI)

- Räkna **antal e-signerade dokument, ersatta utskrivna/postade/faxade originalunderskrifter och sparad tid** (jfr Diggs schablon ~30 min/ärende). En "nytta hittills"-widget ger förvaltningschefen ROI-underlag och stöder merförsäljning — samma mönster som föreslogs för fax/SDK-avveckling.

---

## Rekommendation för Hubs

1. **Bygg inte en egen signeringsmotor — stå på svensk, suverän infrastruktur.** Integrera primärt mot **Inera Underskriftstjänstens API** (redan godkänd, SITHS-/BankID-/Freja-stödd, nationell delad tjänst) och/eller res en egen nod på **Digg:s öppna källkod via Sweden Connect** för on-prem-kunder. Det ger kryptografisk korrekthet, eIDAS-förankring och upphandlingslegitimitet utan att Hubs äger den risken. **Hubs värde är arbetsytan ovanpå** — köerna, spårningen, bevarandet, integrationen.

2. **Differentiera på on-prem + samlad arbetsyta, inte på signeringen i sig.** Scrive/Assently/Visma är molntjänster som ser dokumentinnehållet → OSL 10:2a-/CLOUD Act-friktion. Hubs erbjuder **signering där dokumentet aldrig lämnar kundens driftmiljö**, *och* i samma vy som SDK, säker e-post, fax, video och filer. Det är en kombination ingen konkurrent har. Sälj "en arbetsyta för hela det säkra flödet — inklusive underskrift", inte "ännu en signeringstjänst".

3. **Implementera SKR:s riskmodell i produkten, inte bara i en manual.** Förkonfigurera dokumenttyp→nivå-mappning (SES för internt lågrisk, **AES via BankID/Freja som standard**, QES bara där lag kräver). Visa "Godkänn" vs "Signera" enligt SKR:s budskap att **underskrift inte alltid behövs** — det bygger förtroende hos kommunjurister och undviker QES-overkill.

4. **Gör bevarande till en synlig funktion, inte en eftertanke.** PAdES vid signering → PDF/A vid arkivering → **LTV + tidsstämpling + bevarat valideringsintyg**, med en "verifiera nu/då"-panel. Detta adresserar Riksarkivets och SKR:s tyngsta krav och är där marknaden är svagast. Det blir både compliance-bevis och säljpunkt.

5. **Designa flerpartssignering mot externa/medborgare som förstklassflöde.** SIP-planer, avtal med medborgare, gode män — notis via säker e-post/SDK-länk + BankID i webbläsaren, samma mönster medborgare redan känner igen. Inga konton. Detta knyter signering till de persona-flöden Hubs redan adresserar (SIP, HR, överförmyndare).

6. **Var eIDAS2-redo.** Förbered arkitekturen att ta emot **QES från EUDI-plånboken (2026/27)** och statlig e-legitimation (nov 2026). "eIDAS2-/EUDI-redo" är ett differentierande upphandlingsargument 2026, parallellt med LOA3-kravet från regulatorik-analysen.

7. **Respektera DOS-lagen/WCAG 2.2 AA i signeringsstegen** — tangentbord, fokus, skärmläsare — eftersom även medborgare och anhöriga signerar.

8. **Paketera som NIS2-/cybermiljard-kvalificerande compliance-funktion.** Spårbar, loggad, bevarad signering med data på egna servrar passar direkt in i ledningens systematiska säkerhetsarbete och budget-/bidragsmotiveringar 2026-2028 (jfr regulatorik-analysen).

**Sammanfattad positionering:** *"Hubs e-underskrift — avancerad e-underskrift med BankID, Freja och SITHS, på era egna servrar, i samma säkra arbetsyta som era meddelanden. Att signera och Skickat för signering bredvid inkorgen. Arkivklart med PAdES/PDF/A och bevisbar validering. eIDAS2-redo."*

---

## Källor

**Nationell vägledning & infrastruktur**
- SKR, Vägledning "Digitala underskrifter" (dec 2025): https://skr.se/download/18.383b393a19afcdc7ea383305/1765380441264/Vagledning-Digitala%20underskrifter-2025.pdf
- Knowit/Signport, sammanfattning av SKR:s nya riktlinjer (PAdES, LTV, riskmodell): https://blogg.knowit.se/signport-blog-vad-betyder-skrs-nya-riktlinjer-f%C3%B6r-e-underskrifter
- Digg, Underskriftstjänst (öppen källkod, Sweden Connect-flöde): https://www.digg.se/digitala-tjanster/e-underskrift/underskriftstjanst
- Inera, Underskriftstjänsten (Webb/API/Bas, SITHS/BankID/Freja, PDF/XML): https://www.inera.se/tjanster/alla-tjanster-a-o/underskriftstjansten/
- Sweden Connect, om federationen för e-leg och underskrift: https://www.swedenconnect.se/om-sweden-connect
- Sweden Connect, tekniska anslutningsregler: https://docs.swedenconnect.se/technical-framework/Tekniska_anslutningsregler.html
- Sambruk, Praktiska erfarenheter kring e-underskrifter (kommungemensamt): https://sambruk.se/wp-content/uploads/2020/06/Sambruks-n%C3%A4tverk-Praktiska-erfarenheter-kring-e-signering.pdf

**Juridik & arkivering**
- PTS, Elektroniska underskrifter (eIDAS-nivåer, tillsyn): https://pts.se/internet-och-telefoni/elektroniska-underskrifter/
- eSam, Juridisk vägledning för införande av e-legitimering och e-underskrifter 1.1: https://www.esamverka.se/download/18.1d126bc174ad1e6c39c8ca/1598467569167/eSam%20-%20V%C3%A4gledning%20E-legitimation%20och%20E-underskrift%201.1.pdf
- Riksarkivet, Elektroniska underskrifter (bevarande, allmän handling-principen): https://riksarkivet.se/resurser/elektroniska-underskrifter
- Riksarkivet, RA-FS 2009:1 konsoliderad (elektroniska handlingar): https://riksarkivet.se/files/2024/09/rafs_konsoliderad-ra-fs-2009-1.pdf
- Bolagsverket, Elektroniska underskrifter: https://bolagsverket.se/foretag/elektroniskaunderskrifter.3041.html
- Kommunallag (2017:725): https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/kommunallag-2017725_sfs-2017-725/
- Karolinska Institutet, Riktlinjer för elektroniska underskrifter (exempel på myndighetspolicy): https://medarbetare.ki.se/media/161126/download

**eIDAS / eIDAS2 / EUDI-plånbok**
- Digg, eIDAS-förordningen: https://www.digg.se/kunskap-och-stod/eu-rattsakter/eidas-forordningen
- fynk, EUDI Wallet & qualified e-signatures: https://fynk.com/en/blog/eudi-wallet-qualified-electronic-signatures/
- Entrust, What is eIDAS 2: https://www.entrust.com/resources/learn/eidas-2
- e-signature.eu, Three types of eIDAS signature (SES/AES/QES): https://www.e-signature.eu/en/3-types-of-eidas-signature-simple-advanced-and-qualified/

**Leverantörer**
- Scrive, E-underskrift för offentlig sektor: https://www.scrive.com/solutions/industries/public-sector
- Scrive, Offentlig sektor och e-underskrifter — checklista (AES/QES, BankID/Freja/OrgID, EU-datalagring, e-sigill/tidsstämpling): https://www.scrive.com/sv/resurser/kunskapscenter/nyheter/offentlig-sektor-och-e-underskrifter-checklista
- Assently / Visma Addo / GetAccept / Verified m.fl. — jämförelse svensk marknad: https://businesswith.se/digital-signering/
- Visma Sign, Lagstiftning & giltighet vid elektronisk signering: https://vismasign.se/blogg/lagstiftning-giltighet-elektronisk-signering/
- Egreement / Svensk e-identitet (eIDAS art. 26, BankID, eget arkiv): https://e-identitet.se/e-signering-e-avtal-egreement/
- Svensk e-identitet förvärvar Egreement (2024): https://e-identitet.se/news/svensk-e-identitet-forvarvar-egreement/
- BankID-signering (PAdES, AES): https://www.sajn.se/blog/bank-id
