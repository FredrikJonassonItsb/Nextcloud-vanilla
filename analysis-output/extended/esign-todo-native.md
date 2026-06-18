# Native e-signering (LibreSign) + Todolista för socialtjänsten — vad finns native, vad är gapet, och hur det wiras mot facksystemet som slutlagring

*Underlag för Hubs (ITSL). Datum: 2026-06-13. Intern teknisk analys — appnamn (LibreSign, Deck, Tasks, Forms, Files/Groupfolders) får nämnas här för att kunna wira; i produkt-/UI-text gäller fortfarande brandregeln (säg "e-underskrift", "uppgifts-/bevakningsmodulen", aldrig "Nextcloud"/"Talk").*

**Arkitektonisk ram (kundens egen):** Hubs är MIDDLEWARE / mellanlagring. Slutlagring (system of record) är alltid verksamhetens ärendehanteringssystem — för socialtjänst: **Treserva (CGI)**, **Lifecare/Procapita (Tietoevry)**, **Viva (Pulsen Combine)**, **Combine**. Hubs iscensätter den säkra kommunikationen, signeringen och uppgiftslistan; handläggaren *committar* utfallet (beslutet, den signerade handlingen, journalanteckningen) in i facksystemet. Varje widget i detta dokument måste svara på två frågor: **varifrån kommer datat** och **var hamnar det till slut**.

---

## DEL 1 — Native e-signering: LibreSign idag + gapet till svensk BankID/Freja-AES

### 1.1 Vad LibreSign faktiskt är och kan (app id: `libresign`)

LibreSign (LibreCode Coop, AGPL) är den enda mogna, on-prem, native PDF-signeringsappen i ekosystemet. Den ger köer, flöden och en signerad PDF — utan moln. Senaste serie är v14 (jan 2026). Relevant kapacitet:

- **Signerar PDF kryptografiskt via JSignPDF** (Java-baserad PDF-signeringsmotor). Output är en **PAdES-liknande PDF-signatur** (digital signatur inbäddad i PDF:en, manipulationskänslig — ändring efter signering bryter sigillet och syns i valideringsvyn).
- **Multi-signatär** i samma begäran (interna + externa signatärer), definierbar **signeringsordning, roller och regler per dokumentflöde**, audit trail (vem signerade, när, hur begäran rörde sig).
- **Publika/gäst-signeringslänkar**: en extern part får e-postlänk → signerar utan eget konto (skapar tillfälligt konto eller "click to sign").
- **OCS/REST-API** + Vue-frontend, validerings-URL per dokument, dashboard-integration. Direkt koppling till Files/användare/delning i instansen.
- **Nextcloud-version**: följer aktuell server (v14-serien stödjer v30–v31+; verifiera mot er v32-pinning vid install — historiskt har LibreSign legat någon version efter senaste server).

### 1.2 Certifikatmodellen — och varför den inte är en svensk e-underskrift

LibreSign har **ingen extern PKI/eID i botten**. Den **genererar sin egen rot-CA lokalt** och utfärdar signeringscertifikat per konto från den roten. Två certifikatmotorer (certificate engine) finns:

- **CFSSL** (Cloudflares PKI-verktyg) — standardvalet, kräver `cfssl`-binär.
- **OpenSSL** — alternativ motor i nyare versioner (slipper cfssl-binären).
- (**PKCS#11/HSM** stöds för att skydda nyckeln i hårdvara/FIPS — men det ändrar inte *trust-modellen*: roten är fortfarande er egen, inte en betrodd QTSP eller eID-utfärdare.)

Rot-CA skapas i `Inställningar → Administration → LibreSign` ("Name (CN)" → *Generate root certificate*) eller via `occ`. **Konsekvens:** de signaturer LibreSign producerar är tekniskt giltiga digitala signaturer, men **knutna till en självsignerad organisationsrot som ingen utomstående validerar mot en betrodd lista**. Identiteten på undertecknaren bevisas inte av BankID — den bevisas bara av att personen var inloggad/klickade.

### 1.3 Identitetsfaktorerna — här är det verkliga gapet

LibreSigns "identify methods" / "identity factors" (hur en signatär bevisar vem hen är *innan* signering) är:

| Faktor | Vad det är | LOA / bevisvärde |
|---|---|---|
| **Account** (`nextcloud`) | Signatären är inloggad Hubs-användare | Beror på Hubs-inloggningen (kan vara LOA3 om BankID/Freja/SITHS skyddar själva inloggningen) |
| **Email** | Länk till e-postadress → klick/lösenord | Lågt — e-postinnehav |
| **SMS** | Engångskod till mobilnummer | Lågt — NIST *restricted*, **otillräckligt för LOA3** |
| **Click to sign** | "Jag godkänner"-klick utan stark identifiering | Lägst — i praktiken SES |

**Det LibreSign INTE har:** ingen native BankID, ingen Freja eID, ingen SITHS eID, ingen Sweden Connect-/eIDAS-anslutning, ingen QTSP-tidsstämpling, ingen kvalificerad signatur (QES). Den starkaste inbyggda identitetsfaktorn för en *extern medborgare* är SMS — vilket inte håller för myndighetsbeslut, SIP-planer, anställningsavtal eller årsräkningsbeslut i svensk offentlig sektor.

### 1.4 Det enda native-spåret som når externa eID — och varför det inte räcker

Inom ekosystemet finns **eIDEasy** (separat NC-app) som *kan* koppla nationella eID (Smart-ID, Mobile-ID, vissa eIDAS-noder) och leverera QES. Men: (a) det är en **betald moln-SaaS** (~€0,50/dokument) — dokumentet/hashen tar en runda ut till tredje part, vilket återinför exakt den **OSL 10:2a-/CLOUD Act-/eSam ES2023-06-bedömning** vi bygger bort; (b) BankID-/Freja-täckningen är inte den svenska offentliga sektorns etablerade väg. **OpenOTP Sign** (RCDevs) finns också men är en extern server med användartak i gratisläget. Slutsats: **ingen native app ger BankID/Freja-AES on-prem out of the box.**

### 1.5 Gapet, sammanfattat: LibreSign-AES ≠ svensk myndighets-AES

| Krav i svensk offentlig sektor | LibreSign native | Det riktiga kravet |
|---|---|---|
| Undertecknarens identitet | Konto/e-post/SMS/klick | **BankID, Freja eID Plus, SITHS eID** (LOA3) |
| eIDAS-nivå i praktiken | SES (klick) → svag AES (lokal rot) | **AES** (BankID/Freja) som arbetshäst; **QES** där lag kräver |
| Trust-ankare | Egen självsignerad rot-CA | Sweden Connect-federationen / nationell betrodd tjänst |
| Format | PDF-signatur (PAdES-likt) | **PAdES + PDF/A-1** (Riksarkivet/RA-FS för långtidsbevarande) |
| Tidsstämpling/LTV | Saknas robust | **Kvalificerad tidsstämpel + LTV** (validerbar efter cert-utgång) |
| Externt validerbar | Nej (lokal rot) | Ja (mot Digg/Sweden Connect valideringsprofil) |

### 1.6 Den verkliga integrationen att dokumentera (det Hubs bygger ovanpå)

Bygg **inte** kryptokärnan. Stå på svensk, suverän infrastruktur. Två alternativ — dokumentera båda som leveransvägar:

1. **Inera Underskriftstjänsten — API-varianten** (snabbaste vägen för vård/omsorg, redan SITHS-förankrad). Tekniskt, för spec:
   - Verksamhetssystemet anropar API:t med **mutual TLS (mTLS)** och ett **SITHS funktionscertifikat** (klientcertifikat) som bär aktörens **HSA-id**; API:t är konfigurerat med aktörens organisationsnummer utifrån det certet.
   - Signering sker med **SITHS eID, BankID eller Freja eID Plus** beroende på signaturprofil.
   - Format: **PDF + PAdES** (stödjer Riksarkivets långtidsarkivering om dokumentet är **PDF/A-1**) samt XML.
   - Varianter: **Webb** (fristående, SITHS för uppladdning), **API** (det vi integrerar mot), **Bas** (enkelsignering, t.ex. läkarintyg).
2. **Egen Sweden Connect-nod på Digg:s öppna källkod** (för rena on-prem-kunder utan Inera-avtal). Flöde: Hubs visar dokument → skapar signeringsbegäran med checksumma → användaren legitimerar sig (BankID/Freja) och godkänner **signeringsmeddelandet** → noden genererar nyckel+cert → signerat dokument tillbaka. Sweden Connect-profilen är upphandlingsmässigt "rätt".

**Var LibreSign fortfarande passar:** internt lågrisk-flöde där SKR säger att formell underskrift *inte* behövs — "Godkänn" (loggat) i stället för "Signera". LibreSigns konto-baserade signering + audit trail kan bära det interna godkännandet **så länge Hubs-inloggningen i sig är LOA3** (BankID/Freja/SITHS framför inloggningen). För allt externt och alla myndighetsbeslut: Inera/Sweden Connect.

### 1.7 Hur "Att signera" / "Skickat för signering" wiras — idag vs målbild

Widgetarna (`attSignera`, `skickatForSignering`, primäråtgärder `skickaForUnderskrift`/`begarUnderskrift`/`signeraDelgeBeslut`) ska byggas mot en **signeringsadapter** med två backends bakom samma kö-UI:

- **Demo/internt-idag (LibreSign-backend):** `libresign`-appen utfärdar lokal rot, signerar PDF via JSignPDF, multi-part + gästlänk fungerar → vi kan visa hela kedjan *Skickat → Öppnat → Signerat av X av Y → Klart* i `skickatForSignering` redan nu. **Märk tydligt i UI/demo att identiteten är konto/SMS, inte BankID** — annars luras kommunjuristen.
- **Produktion (Inera/Sweden Connect-backend):** samma kö, men signeringssteget öppnar BankID/Freja/SITHS via API:t; resultat-PDF kommer tillbaka som PAdES, konverteras till **PDF/A-1**, LTV + tidsstämpel läggs på, valideringsintyg sparas.

**Provenance/system-of-record per signeringsflöde (måste synas i widgeten):**

- *Data FRÅN:* dokumentet skapas i Hubs ärenderum (Groupfolders) eller exporteras ur facksystemet (beslut/utredning ur Treserva/Lifecare; årsräkningsbeslut ur Provisum/Aider; anställningsavtal ur Visma/Heroma).
- *Signering iscensätts i:* Hubs (LibreSign idag / Inera-Sweden Connect i prod) — **mellanlagring**.
- *Data TILL (slutlagring):* den signerade PAdES/PDF/A-handlingen + valideringsintyg **committas tillbaka in i facksystemet/diariet/e-arkivet** som den bevarade allmänna handlingen. Hubs behåller en referens + leverans-/läskvittens, inte originalet som arkivsanning.

Bygg **bevarande-/valideringspanelen "Giltig nu / Giltig då"** (PAdES/PDF/A/LTV, "Verifiera underskrift nu") — det är den funktion ingen konkurrent (Scrive/Assently/Visma Addo) säljer tydligt, och Riksarkivets tyngsta krav: en handling är allmän oavsett om signaturen går att verifiera, men **bevisvärdet kräver att man arkiverar *beviset* om underskriften**.

---

## DEL 2 — Todolista för socialtjänsten (de bad uttryckligen om en att-göra-lista)

### 2.1 Vad socialsekreterare faktiskt ber om

De lever i två skilda världar: **inflödet** (orosanmälningar via funktionsbrevlåda, SDK-meddelanden, säker e-post, fax, remisser) och **bevakningen** (vad måste jag göra och *när*). Inflödet finns i meddelandeklienter; bevakningen ligger i facksystemets fristlistor, i kalendern, på post-it-lappar och i huvudet. Mot en bakgrund där **34 % av socialsekreterare rapporterat arbetsmiljöbesvär** (Arbetsmiljöverket) och ihållande **kognitiv belastning** är välbelagd, är "en lista så jag inte glömmer" inte lyx — det är ett rättssäkerhets- och arbetsmiljökrav. Det de ber om är konkret:

1. **En personlig att-göra-lista** ("mina grejer denna vecka") — kopplad till barn/ärende, inte en lös anteckning.
2. **En delad lista per utredning/ärende** så att teamet/2:a-handläggaren ser samma sak (vid frånvaro faller inget mellan stolarna).
3. **Deadlines med påminnelser** *före* fristen — inte bara en röd siffra på förfallodagen.
4. **Knyt till BBIC-/utredningsmomenten** (inhämta uppgifter, samtal, bedömning) så listan speglar hur arbetet faktiskt struktureras.

### 2.2 De lagstadgade fristerna listan måste bära (varför detta inte är generisk todo)

- **Förhandsbedömning av orosanmälan: 14 dagar** (beslut inleda/ej inleda utredning).
- **Barnutredning: klar inom 4 månader** (11 kap. SoL), med möjlighet till förlängning.
- **Tidsbegränsade beslut:** uppföljning **"i god tid innan beslutet upphör"** (Socialstyrelsen) — nytt beslut innan det gamla löper ut.
- **Förvaltningslagen (2017:900) §§ 11–12:** dröjsmålsunderrättelse + partens rätt att efter **6 månader** begära avgörande → myndigheten har **4 veckor**.
- **Ny SoL (i kraft 1 juli 2025):** skärpt uppföljning + "lätt tillgänglig" socialtjänst.

En todolista för soc är därför en **deadline-bärande bevakningslista**, inte en kanban-tavla.

### 2.3 Deck vs Tasks — rätt datalager bakom widgeten

| | **Deck** (kanban) | **Tasks** (VTODO/CalDAV) |
|---|---|---|
| Modell | Board → listor → kort; etiketter, due date, tilldelning (flera), kommentarer, bilagor, **kort↔kort-relation**, delning till team/grupp | Personlig att-göra; titel, start-/**förfallodatum**, **påminnelsetider**, prioritet, subtasks, CalDAV-sync (DAVx5/Thunderbird/Apple) |
| Styrka | **Delad** utrednings-/teamvy; "vem tar detta" på funktionskön | **Personlig** lista; har **separat påminnelsetid** native |
| Svaghet för soc | Saknar separat "reminder före due date" i kärnan (öppen issue #1549); aviseringar går historiskt till alla boardmedlemmar, inte bara tilldelad (#566); drag-and-drop bryter WCAG 2.5.7 utan knappalternativ | Ingen delad team-vy; ingen kanban/funktionskö |

**Rekommendation — använd båda som osynligt datalager, exponera en widget:**

- **`minaUppgifter`** (personlig, arbets-/genomförandefokus) → **Tasks/VTODO** (har native påminnelsetider).
- **`bevakningar`** + delad funktionskö ("vem tar detta") → **Deck** (delad board, tilldelning, kort↔kort-relation till ärendet).
- Hubs bygger ovanpå **det Deck-kärnan saknar**: påminnelse *före* deadline (T-7/T-3/T-0) som går **bara till tilldelad** (täcker #1549/#566), samt knapp-/tangentbordsalternativ till drag (WCAG 2.5.7). UI-mönstret är **GOV.UK task-list**: minimal statusmodell (`Ny · Påbörjad · Väntar på motpart · Klar` + rött `Åtgärd krävs`), verb-inledda titlar ("Inhämta uppgifter från skola – Barn X", "Förhandsbedömning klar – Anmälan 2026-0412").

### 2.4 Signaturfunktionen: "Skapa bevakning från meddelande"

Den enda differentieraren ingen vertikal (Provisum/Aider) eller generisk (Planner/Trello) klarar *i samma sekretessäkra miljö*: på varje inkommande säkert meddelande/orosanmälan en knapp **"Skapa bevakning"** som förifyller titel (avsändare + ämne), länkar till meddelandet, föreslår deadline (t.ex. 14-dagars förhandsbedömning), och kopplar till **ärendereferens** (dnr/barn-token) så meddelanden + uppgifter hänger ihop. Stäng loopen: klarmarkering visar "svar skickat, kvittens mottagen" — den känslomässiga ersättningen för "ringa och kolla att faxen kom fram".

### 2.5 Knytning till BBIC/utredning

BBIC (Socialstyrelsen) ger strukturen för handläggning–genomförande–uppföljning. Todolistan ska kunna **instansiera en checklista per utredning** ur BBIC-momenten (inhämta uppgifter, barnsamtal, nätverkskarta, bedömning, beslut) som en delad Deck-board per barn/dnr — men **utan att bli journal**: korttext default = ärendereferens, inte klartextcitat (GDPR-dataminimering). Mallarna bor i `kunskapsbank` (Collectives).

### 2.6 Mappning mot system of record — todon är mellanlagring, inte slutlagring

Detta är den känsligaste punkten och måste vara explicit i datamodellen:

- **Treserva (CGI)** och **Lifecare/Procapita (Tietoevry)** har **redan inbyggd "bevakning"**: registrerade bevakningar visas på handläggarens skrivbord, **texten blir röd när bevakningsdatumet passerats**, andra kan lägga bevakning i ens ärenden, och man kan **ange antal dagar före förfallodatum som varningen ska visas**. Dvs facksystemet *äger* den formella fristbevakningen och den arkivpliktiga aktiviteten.
- **Därför får Hubs todolista inte konkurrera med eller dubblera facksystemets bevakning.** Hubs äger:
  - *Data FRÅN:* inflödet som **ännu inte är registrerat** i facksystemet (ny orosanmälan i funktionsbrevlådan, säkert meddelande från regionen, fax) → den latenta uppgiften innan den blir ett ärende.
  - *Mellanlagring:* den personliga/delade arbetslistan + påminnelser under den säkra kommunikationsfasen.
  - *Data TILL (slutlagring):* när uppgiften blir en formell handling/aktivitet → handläggaren **registrerar/committar den i Treserva/Lifecare** (beslut, aktivitet, journalanteckning, formell bevakning). Hubs-uppgiften får då status "förd till ärendet" och **gallras eller länkas** — den blir inte en konkurrerande, oarkiverad fristlista.
- **Arkiv-/gallringsmedvetenhet vid klarmarkering:** val mellan "gallra (personlig notering)" och "för till ärendet/facksystemet" — håller isär gallringsbara att-göra-lappar från ärendebundna allmänna handlingar (arkivlagen 1990:782 / OSL). Felaktig hopblandning skapar arkiv- och offentlighetsproblem och är första frågan en kommunjurist ställer.

**Positionering:** *"Hubs todolista fångar det inkommande innan det blir ett ärende, och bevakar det säkra flödet runt omkring — den formella fristen och journalen bor i Treserva/Lifecare. Vi dubblerar inte facksystemet; vi stänger gapet mellan inkorgen och facksystemet."*

---

## Sammanfattande rekommendation

1. **E-signering, demo-idag:** wira `attSignera`/`skickatForSignering` mot **LibreSign** (`libresign`) för att visa hela kö-/spårningskedjan on-prem — men **etikettera identiteten ärligt** (konto/SMS, ej BankID) och kör det bara för internt lågrisk-"Godkänn".
2. **E-signering, produktion:** dokumentera den riktiga integrationen mot **Inera Underskriftstjänsten API** (mTLS + SITHS funktionscert, BankID/Freja/SITHS, PAdES + PDF/A-1) eller **egen Sweden Connect-nod** (Digg open source). Bygg arbetsytan + bevarandepanelen, inte kryptokärnan.
3. **Todolista:** bygg en **rollstyrd `minaUppgifter` (Tasks/VTODO) + delad `bevakningar` (Deck)**-widget med GOV.UK-statusmodell, påminnelser-före-deadline (egen logik, täcker Deck #1549/#566) och "Skapa bevakning från meddelande" som signaturfunktion.
4. **System of record genomgående:** todon och signaturkön är **mellanlagring**; den formella bevakningen, journalen och den bevarade signerade handlingen committas in i **Treserva/Lifecare/Viva** (soc) respektive diariet/e-arkivet. Varje widget märks med varifrån data kommer och vart det committas.

---

## Källor

**LibreSign (native e-signering)**
- LibreSign GitHub (app, multi-signer, flöden, validering): https://github.com/LibreSign/libresign
- LibreSign officiell sajt / dokumentation: https://libresign.coop/ · https://docs.libresign.coop/
- LibreSign API/Getting started (identify methods, signeringsflöde): https://libresign.github.io/Getting-started.html
- LibreSign issue #564 (install: java/jsignpdf/cfssl, occ-namespace): https://github.com/LibreSign/libresign/issues/564
- LibreSign issue #172 (förenkla rot-certifikatgenerering, självsignerad rot): https://github.com/LibreSign/libresign/issues/172
- LibreSign issue #2356 (e-postsignering): https://github.com/LibreSign/libresign/issues/2356
- Nextcloud-forum: e-signaturlösningar LibreSign/OpenOTP/eIDEasy (extern eID, moln-tradeoff): https://help.nextcloud.com/t/electronic-signature-openotp-libresign-eideasy/132203
- Cloudron-forum: LibreSign kräver Java/JSignPDF/CFSSL: https://forum.cloudron.io/topic/6716/nextcloud-libresign-need-java-how-i-can-install-it
- OpenPDF/JSignPDF bakgrund: https://en.wikipedia.org/wiki/OpenPDF

**Svensk e-underskrift — nationell infrastruktur (det Hubs står på)**
- Inera Underskriftstjänsten (Webb/API/Bas; SITHS/BankID/Freja; PDF/PAdES/PDF-A-1): https://www.inera.se/tjanster/alla-tjanster-a-o/underskriftstjansten/
- Inera Anslutningsguide – Underskriftstjänsten Webb och API (mTLS, SITHS funktionscert/HSA-id): https://inera.atlassian.net/wiki/spaces/UTJ/pages/3501787183/Anslutningsguide+-+Underskriftstj+nsten+Webb+och+API
- Inera – "Ökad flexibilitet med nya underskriftstjänsten": https://www.inera.se/aktuellt/nyheter/okad-flexibilitet-med-nya-underskriftstjansten/
- Digg – Underskriftstjänst (öppen källkod, Sweden Connect-flöde): https://www.digg.se/digitala-tjanster/e-underskrift/underskriftstjanst
- Sweden Connect (federation e-leg + underskrift): https://www.swedenconnect.se/om-sweden-connect
- SKR – Vägledning "Digitala underskrifter" (dec 2025; riskmodell SES/AES/QES, Godkänn vs Signera, PAdES/LTV): https://skr.se/download/18.383b393a19afcdc7ea383305/1765380441264/Vagledning-Digitala%20underskrifter-2025.pdf
- Riksarkivet – Elektroniska underskrifter (bevarande, allmän handling-principen): https://riksarkivet.se/resurser/elektroniska-underskrifter
- BankID/Freja översikt (LOA3, AES, svensk/utländsk): https://frejaeid.com/en/whats-the-difference-between-freja-and-bankid/ · https://www.scrive.com/products/eid-hub/freja-eid
- PTS – Elektroniska underskrifter (eIDAS-nivåer): https://pts.se/internet-och-telefoni/elektroniska-underskrifter/
- eSam ES2023-06 (utkontraktering/molnbedömning som on-prem eliminerar): https://www.esamverka.se/download/18.43a3add4188b9f2345a2fe78/1687332814480/ES2023-06%20V%C3%A4gledning%20Utkontraktering%20-%20sekretess%20och%20dataskydd.pdf

**Todolista / bevakning socialtjänst + facksystem (system of record)**
- GOV.UK Design System – Task list (statusmodell, sessioner): https://design-system.service.gov.uk/components/task-list/
- Nextcloud Deck (kort/deadline/tilldelning/relation): https://deck.readthedocs.io/ · https://github.com/nextcloud/deck
- Deck issue #1549 (separat påminnelse före deadline) · #566 (avisering bara till tilldelad): https://github.com/nextcloud/deck/issues/1549 · https://github.com/nextcloud/deck/issues/566
- Nextcloud Tasks (VTODO/CalDAV, påminnelsetider): https://github.com/nextcloud/tasks
- Treserva (CGI) – verksamhetssystem socialtjänst, bevakning/påminnelse: https://www.cgi.com/se/sv/treserva
- Treserva handbok handläggare (bevakning blir röd vid passerat datum, antal dagar före varning): https://docplayer.se/30371646-Datum-sida-55-treserva-handbok-for-handlaggare.html
- Lifecare/Procapita (Tietoevry) – handbok utförare/handläggare: https://www.soderkoping.se/globalassets/documents/04-stod-o-omsorg/13-utforarwebb/04-it-och-system/lifecare-utforare-handbok.pdf
- Socialstyrelsen – BBIC (struktur handläggning/genomförande/uppföljning): https://www.socialstyrelsen.se/kunskapsstod-och-regler/omraden/barn-och-unga/barn-och-unga-i-socialtjansten/barns-behov-i-centrum/material/
- Kunskapsguiden – Inhämta uppgifter / Besluta (tidsbegränsade beslut): https://kunskapsguiden.se/omraden-och-teman/barn-och-unga/handlaggning-och-dokumentation-med-barnet-i-centrum/utreda/inhamta-uppgifter/ · https://kunskapsguiden.se/omraden-och-teman/barn-och-unga/handlaggning-och-dokumentation-med-barnet-i-centrum/besluta/
- Förvaltningslag (2017:900) §§ 11–12 (dröjsmål/frister): https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/forvaltningslag-2017900_sfs-2017-900/
- Socialstyrelsen – nya socialtjänstlagen (1 juli 2025): https://www.socialstyrelsen.se/kunskapsstod-och-regler/omraden/en-socialtjanst-i-forandring/vad-ar-egentligen-nytt-i-nya-socialtjanstlagen/
- Arbetsmiljöverket – ohälsosam arbetsbelastning (socialsekreterares belastning): https://www.av.se/halsa-och-sakerhet/organisatorisk-och-social-arbetsmiljo/forebygg-ohalsosam-arbetsbelastning/
- Provisum (överförmyndar-facksystem, bevakning) · Aider Bevakning 2025: https://www.provisum.se/ · https://support.aider.nu/sv/articles/6884612-overformyndare-och-aider
