<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Inera Underskriftstjänsten (API) — exakt vad som krävs för AES-e-underskrift med BankID/Freja/SITHS i en kommun, och hur det wiras in i Hubs

> **Syfte:** teknisk djupdykning bakom widgetarna `attSignera` / `skickatForSignering` / `justeringAnslag` /
> `mallarSamtycke` när de körs i **produktion** (inte LibreSign-demo). Datum: 2026-06-14. Plattform: server v32.
> Brand-regel följer `PERSONA-DASHBOARD-SPEC.md` — i produkt-/UI-text säger vi "e-underskrift", aldrig
> "Nextcloud"/"LibreSign"; här i det interna underlaget namnger vi appar och exakta Inera-/Sweden Connect-/
> Digg-komponenter för spårbarhet. Kompletterar `esign-todo-native.md` (DEL 1) och `middleware-architecture.md`
> (mönster A/D, signering = mellanlagring, signerad handling committas till facksystemet/diariet).
>
> **Arkitektonisk ram:** Hubs är MELLANLAGRING. Den signerade PAdES/PDF/A-handlingen + valideringsintyg är ett
> *utfall* som **committas in i slutlagringen** (Treserva/Lifecare/Viva/Combine för soc; W3D3/Public 360°/
> Ciceron/Platina + e-arkiv/Sydarkivera för diariet). Underskriftstjänsten själv lagrar **inte** dokumentet —
> den signerar en hash och returnerar signaturen; dokumentet stannar hela tiden i Hubs tills det förs över.

---

## 0. TL;DR

Inera **Underskriftstjänsten** är den nationella, vård/omsorgs-förankrade e-underskriftstjänsten som ger
**avancerad elektronisk underskrift (AES) enligt eIDAS** med **SITHS eID, BankID, Freja eID Plus** (och utländsk
eID via eIDAS) på ett **PDF/PAdES**-dokument. Den finns i tre varianter — **Webb**, **API** (det Hubs integrerar
mot), **Bas** (ensignering, t.ex. läkarintyg). API-varianten anropas med **mutual TLS (mTLS)** och ett **SITHS
funktionscertifikat** (klientcertifikat) som bär aktörens **HSA-id**; utifrån certet är API:t konfigurerat med
aktörens organisationsnummer. Det skiljer sig fundamentalt från **LibreSign**, som har **egen självsignerad
rot-CA + konto/e-post/SMS-identitet och ingen BankID/Freja/SITHS** — alltså inte en svensk myndighets-AES.

**Den enda raden (svaret på slutfrågan står sist i dokumentet):** den enskilt största blockeraren är att
**kommunen måste vara Inera-kund med tecknat kundavtal OCH ansluten till SITHS + HSA-katalogen** (funktionscert
+ mTLS hänger på det) — utan den förankringen finns ingen anslutningsväg alls, och det är en organisatorisk/
avtalsmässig process (veckor–månader), inte en teknisk konfig.

---

## 1. Vad Underskriftstjänsten är — tre varianter

Inera Underskriftstjänsten är byggd på **Sweden Connects tekniska ramverk** för fristående underskriftstjänst
(samma DSS/Signature-Service-protokoll som Digg:s öppna kod — se §6). Tre leveransformer:

| Variant | Vad | Vem legitimerar sig | Vem signerar | Format | För vem |
|---|---|---|---|---|---|
| **Webb** | Fristående webb-UI: ladda upp PDF, peka ut signatärer | Beställaren med **SITHS eID** | **SITHS eID / BankID / Freja eID Plus / utländsk eID** | PDF + PAdES | Regioner, kommuner, kommunala bolag & förbund |
| **API** ← *Hubs integrerar mot denna* | Tekniskt gränssnitt: verksamhetssystemet startar och driver signeringsflödet | Verksamhetssystemets egen autentisering (Hubs-sessionen, LOA3) | **SITHS eID / BankID / Freja eID Plus / utländsk eID** | **PDF + PAdES** | Regioner, kommuner |
| **Bas** | Ensignering, ett dokument en undertecknare (t.ex. läkarintyg) | **SITHS eID** | **SITHS eID** | PDF **och XML** | Regioner, statliga myndigheter, privata offentligt finansierade vårdgivare |

**eIDAS-nivå:** Inera anger uttryckligen att tjänsten *"uppfyller kraven för så kallad avancerad elektronisk
underskrift enligt eIDAS"* — dvs **AES**, inte QES. Det är precis "arbetshästen" `esign-todo-native.md` pekar ut:
BankID/Freja-AES för myndighetsbeslut, SIP-planer, justering av protokoll, samtycken; QES sparas för de få fall
lag uttryckligen kräver det.

> ⚠ ANTAGANDE: produktionsmålbilden i Hubs är **API-varianten** (integrerad kö i dashboarden). **Webb-varianten
> är fallback dag 1** om API-anslutningen inte är klar — den kräver bara SITHS-kort för beställaren och inget
> systemcert, och Hubs kan deep-linka till den. Det bör dokumenteras som en mellanstation i leveransplanen.

---

## 2. Signeringsflödet (end-to-end), API-varianten

Konceptuellt följer Inera-API:t Sweden Connect-mönstret **"autentisering (IdP) skild från signering (central
underskriftstjänst)"**. Den centrala tjänsten **äger ingen permanent signeringsnyckel per användare** — den
**genererar en engångsnyckel + ett engångscertifikat** vid varje signeringstillfälle, knutet till den identitet
eID:t bevisar. Steg för steg, med Hubs-rollen explicit:

1. **Handläggar-action:** i Hubs öppnar handläggaren ett beslut/utredning/SIP-plan/samtycke i `attSignera` och
   klickar **"Skicka för underskrift"** (`skickaForUnderskrift` / `begarUnderskrift` / `signeraDelgeBeslut`).
2. **Hubs (signeringsadapter, prod-backend):** beräknar **checksumma/hash** på PDF:en och **skapar ett
   signeringsuppdrag** via ett **HTTP-anrop mot Underskriftstjänsten-API** över **mTLS** (SITHS funktionscert som
   klientcert). Anropet anger **signaturprofil** (vilket eID som ska användas), dokument(hash) och retur-URL.
   - Svaret bär ett **`Location`-headerfält** som pekar direkt på det skapade signeringsuppdraget — den URI:n blir
     **bas-URI för alla följande anrop** (statuskoll, hämta resultat). (Detta är ett konkret REST-mönster Inera
     dokumenterar.)
3. **Underskriftstjänsten → Sweden Connect/eID:** tjänsten dirigerar undertecknaren till rätt **IdP** —
   **BankID, Freja eID Plus eller SITHS eID** — för **legitimering + godkännande av signeringsmeddelandet**
   (`SignMessage`: "Du skriver under *Beslut om bistånd, barn 2026-0412*"). Undertecknaren ser och godkänner
   exakt vad som signeras. ⚠ LUCKA: signeringsmeddelandets *exakta* text måste sättas av Hubs-adaptern och
   granskas juridiskt — fel/vag text underminerar bevisvärdet.
4. **Signaturframställning (central tjänst):** efter lyckad legitimering **genererar tjänsten nyckel + cert**,
   plockar in undertecknarens attribut (namn, personnummer/HSA-id) i certets subject ur eID-assertion, och
   **signerar hashen** → **PAdES**-signatur.
5. **Underskriftstjänsten → Hubs:** signaturen + **certifikatkedjan** + **assertion/undertecknarbevis** returneras.
   Hubs (eller tjänsten) **assemblerar den färdiga signerade PDF:en (PAdES)**. Hubs **pollar `Location`-URI:n**
   (status: skapad → legitimerad → signerad → klar) eller tar emot callback till retur-URL.
   ⚠ ANTAGANDE: poll vs callback — Inera-API:t exponerar `Location` för polling; om webhook/callback finns bör den
   föredras för `skickatForSignering`-statusuppdatering. Verifiera i Utvecklarinfo vid implementation.
6. **Efterbearbetning i Hubs (mellanlagring):** konvertera/säkerställ **PDF/A-1** (Riksarkivet, långtidsbevarande),
   lägg på **kvalificerad tidsstämpel + LTV** (validerbart efter cert-utgång), spara **valideringsintyg**. Detta är
   bevarandepanelen "Giltig nu / Giltig då" som `esign-todo-native.md` pekar ut som differentieraren.
7. **För över till slutlagring (mönster D/A, `middleware-architecture.md`):** den signerade PAdES/PDF/A-handlingen +
   valideringsintyg **committas till facksystemet/diariet/e-arkivet** (Treserva-akt / W3D3-dnr / FGS→Sydarkivera).
   Hubs behåller referens + leverans-/läskvittens, **inte** originalet som arkivsanning; mellanlagringskopian
   gallras (Retention).

**Datariktning:** Dokumentet (data) går **IN** i flödet från Hubs och **stannar i Hubs** — bara **hashen** + ett
*signeringsmeddelande* exponeras mot Underskriftstjänsten; signaturen kommer **tillbaka IN** till Hubs. Det är
arkitektoniskt rent: ingen sekretessbärande dokumenttext lämnar driftmiljön till Inera (tjänsten signerar en
checksumma, inte innehållet). ⚠ ANTAGANDE: bekräfta att API-profilen verkligen är *hash-/checksummebaserad* och
inte kräver uppladdning av hela PDF:en till Inera — det är avgörande för OSL 10:2a-berättelsen och bör verifieras
i signaturprofilen innan vi påstår det i sälj-/juristmaterial.

---

## 3. Tekniska förutsättningar (det som måste finnas på plats)

### 3.1 mTLS + SITHS funktionscertifikat (klientcert) — kärnan i API-anslutningen

- API:t anropas med **mutual TLS (mTLS)**: Hubs/verksamhetssystemet presenterar ett **SITHS funktionscertifikat**
  (system-/serveridentitet, inte personcert) som **klientcertifikat** — **ett per verksamhetssystem**.
- Funktionscertifikatet **bär aktörens HSA-id**. Utifrån certet är API:t konfigurerat med aktörens
  **organisationsnummer** — dvs certet *är* aktörsbindningen.
- Certifikatet exporteras i **X.509-format**, sparas som **PEM/Base64 (.pem)**; **den publika nyckeln skickas in
  i förstudien** vid anslutning (whitelistas hos Inera).
- SITHS skiljer på **SITHS e-legitimation** (identifierar *personer* — kort/eID) och **SITHS funktionscertifikat**
  (identifierar *system*). Det är funktionscertet API-integrationen behöver.

### 3.2 HSA-katalog + SITHS-anslutning (förutsättningen under funktionscertet)

- För att över huvud taget kunna utfärda/använda SITHS måste organisationen vara **ansluten till Katalogtjänst
  HSA**; HSA-id är den unika identifieraren och serverobjektet läggs upp i HSA.
- Funktionscert beställs/livscykelhanteras i **SITHS eID Portal** (administrationsverktyget) av behöriga personer
  (RA/utgivare/ombud). Kortbeställningar går till Inera, leverans ~3 veckor.
- Organisationer utan egen direktanslutning kan ansluta via **SITHS-ombud / tredjepartsanslutning** (Svensk
  e-identitet m.fl. erbjuder HSA-/SITHS-ombudstjänst).

### 3.3 Inera-kundavtal + behörighet att beställa

- Organisationen måste vara **Inera-kund** med tecknat **kundavtal** och genomgången **kundkvalificering**.
  Behörigheten styrs av **marknadsbegränsningen i Ineras bolagsordning + ägardirektiv**: regioner, kommuner,
  kommunala bolag/förbund (och vissa statliga/privata offentligt finansierade för Bas).
- **Underskriftstjänsten – API** är i Ineras kundmodell tillgänglig för **regioner och kommuner**. (Webb även för
  kommunala bolag/förbund; Bas även för statliga myndigheter/privata vårdgivare.)
- Anslutningssättet kan vara **direktanslutning** (kommunens system mot Inera) eller **via ombud/systemleverantör**.

### 3.4 Signaturprofiler, format, miljöer

- **Signaturprofil** styr vilket eID som tillåts (SITHS/BankID/Freja/utländsk) och certifikat-/nivåkrav.
- **Format:** **PDF in → PAdES ut** (API/Webb). Bas stödjer även XML. För arkiv: säkerställ **PDF/A-1** + LTV i
  Hubs efterbearbetning (Inera levererar PAdES, Hubs gör arkivhärdningen).
- **eIDAS-nivå:** **AES** (advanced). QES ligger utanför denna tjänst.
- **Miljöer:** test/QA-miljö finns för anslutning före produktion (kräver test-funktionscert). ⚠ ANTAGANDE: exakta
  test-endpoints/host och om separat test-HSA-id krävs framgår i **Utvecklarinfo – Underskriftstjänsten API** och
  **Anslutningsguide – Webb och API** (Inera Confluence); dessa sidor lät sig inte fullständigt hämtas maskinellt
  (truncerade) och bör läsas direkt av integratören.

---

## 4. Anslutningsprocessen för en kommun (steg)

1. **Bli/vara Inera-kund:** teckna kundavtal, genomgå kundkvalificering (om inte redan kund för andra Inera-tjänster
   — många kommuner är det redan via SITHS/HSA/NPÖ).
2. **Säkerställ SITHS + HSA:** organisationen ansluten till Katalogtjänst HSA + Identifieringstjänst/Autentiseringstjänst
   SITHS (direkt eller via ombud). Detta är *grundplåten* — funktionscert hänger på det.
3. **Beställ tjänsten Underskriftstjänsten – API** i Ineras kundportal (`kundportal.inera.se`).
4. **Förstudie/anslutning:** beställ **SITHS funktionscertifikat** för verksamhetssystemet, exportera publik nyckel
   (X.509/PEM), skicka in i förstudien så Inera whitelistar certet och knyter organisationsnummer.
5. **Integrera mot API:t** (test/QA → produktion): implementera signeringsadapterns prod-backend (skapa uppdrag →
   `Location` → poll/callback → hämta PAdES), välj signaturprofil(er).
6. **Sätt signeringsmeddelanden + bevarande:** definiera `SignMessage`-texter per dokumenttyp; bygg PDF/A-1- +
   LTV-härdning + valideringsintyg.
7. **Verifiera juridiskt:** att AES räcker för de aktuella beslutstyperna (SKR:s vägledning "Digitala underskrifter"
   2025: riskmodell SES/AES/QES, "Godkänn vs Signera").

---

## 5. Hur det skiljer sig från LibreSign (och varför LibreSign inte räcker till svensk myndighets-AES)

| Dimension | **LibreSign** (`libresign`, NC-app) | **Inera Underskriftstjänsten – API** |
|---|---|---|
| Identitet på undertecknaren | **Konto / e-post / SMS / "click-to-sign"** — ingen BankID | **SITHS eID / BankID / Freja eID Plus / utländsk eID** (LOA3) |
| Trust-ankare | **Egen självsignerad lokal rot-CA** (CFSSL/OpenSSL), ingen utomstående validerar mot betrodd lista | Sweden Connect-federationen / nationell betrodd tjänst; eID-utfärdaren bevisar identiteten |
| Signaturmotor | JSignPDF (lokalt), engångs lokalt cert per konto | Central tjänst **genererar engångsnyckel+cert** bundet till eID-bevisad identitet |
| eIDAS i praktiken | SES (klick) → svag AES (lokal rot) | **AES** enligt eIDAS, validerbar externt |
| Format | PDF-signatur (PAdES-likt) | **PDF + PAdES**; Hubs härdar till **PDF/A-1 + LTV** |
| Anslutning | `occ libresign:install` (Java/JSignPDF/cfssl/pdftk) — ingen extern part | **mTLS + SITHS funktionscert**, kundavtal, HSA/SITHS |
| Dokumentet lämnar miljön? | Nej (helt on-prem) | Nej (endast **hash/checksumma** + signeringsmeddelande mot tjänsten) ⚠ verifiera profil §2 |
| Var det passar | **Internt lågrisk "Godkänn"** (loggat) **när Hubs-inloggningen i sig är LOA3** | **Allt externt + alla myndighetsbeslut** (bistånd, SIP, justering, samtycke, delgivning) |

**Konsekvens för Hubs:** en **signeringsadapter med två backends bakom samma kö-UI** (`attSignera`/
`skickatForSignering`). Demo/internt = LibreSign-backend (visa hela kedjan *Skickat→Öppnat→Signerat X av Y→Klart*
on-prem, men **etikettera identiteten ärligt som konto/SMS, inte BankID** — annars luras kommunjuristen).
Produktion = Inera-API-backend (BankID/Freja/SITHS → PAdES → PDF/A-1 + LTV). UI:t och provenance-modellen är
identiska; bara identitets-/trust-lagret byts.

---

## 6. Alternativet: egen Sweden Connect-/Digg-nod (för kunder utan Inera-avtal)

Digg publicerar **öppen källkod** för en **fristående underskriftstjänst** byggd på **Sweden Connects tekniska
ramverk** (samma DSS-protokoll Inera står på):

- Kod: **`github.com/swedenconnect/signservice`** (tjänsten) + **`github.com/idsec-solutions/signservice-integration`**
  (integrations-SDK). Ramverk: `swedenconnect.se/tekniskt-ramverk`.
- **Protokollet (DSS Extension for Federated Central Signing Services):** verksamhetssystemet skickar en **signerad
  `<dss:SignRequest>`** med **`<SignRequestExtension>`** (anger **IdP-entityID** för BankID/Freja, `<SignRequester>`/
  `<SignService>`, valfritt **`<SignMessage>`**) och **`<SignTasks>`/`<SignTaskData>`/`<ToBeSignedBytes>`** (hashen
  som ska signeras; `SigType` = PDF/XML/CMS/ASiC). Tjänsten **re-autentiserar mot IdP**, **genererar nyckel+cert**
  med användarattribut ur SAML-assertion, signerar hashen, och svarar med **`<dss:SignResponse>`** som bär
  **`<Base64Signature>`** + **`<SignatureCertificateChain>`** + **`<SignerAssertionInfo>`**. Transport: **HTTP POST**;
  tjänster identifieras via **SAML entityID i federationsmetadata**; requests/responses **MÅSTE signeras**.
  Verksamhetssystemet **assemblerar den färdiga PAdES/XAdES**-handlingen.
- **Vad en organisation då behöver:** en e-tjänst med **e-autentisering (Sweden Connect-anslutning, SAML-metadata)**,
  koppling till en **certifikattjänst (CA)**, och integration e-tjänst↔underskriftstjänst (SDK:t). Användaren ska vara
  **identifierad *innan*** hen skickas till underskriftstjänsten.
- **Skillnad mot Inera:** Inera är **managed** (du köper tjänsten, SITHS-förankrad, snabbast för vård/omsorg). Digg-spåret
  är **self-hosted** (du driver noden, äger Sweden Connect-anslutning + CA själv) — mer kontroll/suveränitet, mer
  ansvar/drift. ⚠ ANTAGANDE: BankID/Freja-täckning via egen nod kräver egen anslutning till respektive eID i
  federationen; det är en separat avtals-/teknisk insats utöver att köra koden.

**Rekommendation:** för svensk **kommun inom vård/omsorg** är **Inera-API:t den primära vägen** (SITHS/HSA finns
oftast redan, AES, BankID/Freja/SITHS i en profil). Egen Sweden Connect-nod är spåret för rena on-prem-kunder utan
Inera-avtal, eller där maximal suveränitet/kontroll väger tyngre än time-to-market.

---

## 7. Hur det wiras in i Nextcloud/Hubs — tre arkitekturval

| Val | Vad | För/Nack |
|---|---|---|
| **A. Egen signeringsadapter-app (NC-app i Hubs)** ← **rekommenderad** | En tunn NC-app som exponerar `attSignera`/`skickatForSignering`-API:t mot frontend och har **två utbytbara backends** (LibreSign / Inera-API resp. Sweden Connect-nod). Hubs äger UI, kö, provenance, PDF/A-1+LTV-härdning, "för över till facksystem"-steget. mTLS-anropet mot Inera görs server-side från appen (funktionscert i NC:s `config`/secret-store). | **+** Full kontroll på kö-UI, brand-regel, provenance, bevarandepanel; eID/trust-lagret blir ett bytbart adapterinterface. **−** Mest egen kod; måste hantera funktionscert/secret + mTLS i PHP/server. |
| **B. LibreSign-extension (identify method-plugin)** | Skriva en LibreSign "identify method"/signeringsmotor som ringer Inera/Sweden Connect. | **+** Återanvänder LibreSigns kö/flöde/validering. **−** LibreSigns modell är lokal rot-CA + JSignPDF; att tvinga in en *central* eID-signering (engångsnyckel hos extern tjänst, PAdES tillbaka) bryter dess kärnantagande — sannolikt mer friktion än egen adapter. ⚠ ANTAGANDE: kräver utredning av LibreSign-API:ts utbyggbarhet innan man lovar detta. |
| **C. Extern signeringsmikrotjänst (sidecar) + Hubs deep-link/callback** | En separat (on-prem) tjänst håller mTLS + funktionscert mot Inera; Hubs anropar den och tar emot callback. | **+** Isolerar funktionscert/mTLS från NC-appen, lättare att dela mellan flera system. **−** En komponent till att drifta/härda; callback-/säkerhetsdesign. Bra om kommunen redan har en signerings-sidecar. |

**För Hubs-demon nu:** behåll `attSignera`/`skickatForSignering` mot **LibreSign-backend** (visar hela kedjan
on-prem, ärligt etiketterad). **För produktion:** bygg **val A** — signeringsadapter-appen med Inera-API som
prod-backend och Sweden Connect-nod som alternativ. Bygg **arbetsytan + bevarandepanelen ("Giltig nu / Giltig
då")**, inte kryptokärnan.

**Provenance per signeringsflöde (måste synas i widgeten, jfr `middleware-architecture.md` §4):**
- *Data FRÅN:* dokument skapat i Hubs ärenderum (Groupfolders) eller exporterat ur facksystemet (beslut/utredning
  ur Treserva/Lifecare; justerat protokoll ur diariet).
- *Signering iscensätts i:* Hubs (LibreSign demo / Inera-API prod) — **mellanlagring**. Bara hash + signeringsmeddelande
  mot tjänsten.
- *Data TILL (slutlagring):* signerad **PAdES/PDF/A** + **valideringsintyg** committas till facksystemet/diariet/
  e-arkivet (mönster **D**, ev. **A** via API). Hubs-kopia gallras (Retention).

---

## 8. Öppna punkter / luckor att stänga före produktion

- ⚠ LUCKA: **API-detaljerna** (exakt REST-resurslista, callback vs polling, exakta signaturprofil-id, test-host)
  måste läsas i **Inera Confluence: "2.1 Utvecklarinfo – Underskriftstjänsten API"** + **"Anslutningsguide – Webb
  och API"** — dessa truncerades i maskinell hämtning. Bekräftas av integratören med kundportal-access.
- ⚠ LUCKA: **bekräfta hash-/checksummebaserad signering** (att hela PDF:en inte laddas till Inera). Avgörande för
  OSL 10:2a-/suveränitetsberättelsen.
- ⚠ ANTAGANDE: **PDF/A-1 + LTV + kvalificerad tidsstämpel** läggs på i **Hubs** efter PAdES-svar (Inera levererar
  PAdES; arkivhärdningen är vårt ansvar). Verifiera om Inera-profilen kan leverera LTV/tidsstämpel direkt.
- ⚠ ANTAGANDE: **funktionscert-hantering i NC** (var nyckeln bor, rotation, HSM/PKCS#11) måste säkerhetsdesignas —
  ett komprometterat funktionscert = aktörens systemidentitet mot Inera.
- ⚠ LUCKA: **juridisk grind** — för varje dokumenttyp avgöra **AES räcker / kräver QES / räcker "Godkänn"** (SKR:s
  vägledning). Hubs bygger kapaciteten; verksamheten/juristen sätter regeln per beslutstyp.

---

## Källor

**Inera Underskriftstjänsten (tjänst, varianter, eID, format, AES)**
- Inera – Underskriftstjänsten (Webb/API/Bas; SITHS/BankID/Freja/utländsk; PDF/PAdES/XML; AES enligt eIDAS) — https://www.inera.se/tjanster/alla-tjanster-a-o/underskriftstjansten/
- Inera Confluence – Anslutningsguide, Underskriftstjänsten Webb och API (mTLS, SITHS funktionscert/HSA-id, PEM, förstudie) — https://inera.atlassian.net/wiki/spaces/UTJ/pages/3501787183/Anslutningsguide+-+Underskriftstj+nsten+Webb+och+API
- Inera Confluence – 2.3 Anslutningsguide – Underskriftstjänsten Webb och API — https://inera.atlassian.net/wiki/spaces/UTJ/pages/4198170625/
- Inera Confluence – 2.1 Utvecklarinfo – Underskriftstjänsten API (Location-header/bas-URI, signeringsuppdrag) — https://inera.atlassian.net/wiki/spaces/UTJ/pages/3501785147/
- Inera Confluence – Dokumentation Underskriftstjänsten (översikt) — https://inera.atlassian.net/wiki/spaces/UTJ/pages/3501787164/
- Inera – Nu lanseras Ineras utvidgade underskriftstjänst (BankID/Freja-tillägg) — https://www.inera.se/aktuellt/nyheter/nu-lanseras-ineras-utvidgade-underskriftstjanst/

**Inera kundmodell / avtal / SITHS / HSA (förutsättningarna)**
- Inera – Ineras kundmodell (kundavtal, kundkvalificering, anslutning, marknadsbegränsning) — https://www.inera.se/kontakta-oss/avtal-bestallning-anslutning/ineras-kundmodell/
- Inera – Teckna kundavtal — https://www.inera.se/kontakta-oss/avtal-bestallning-anslutning/ineras-kundavtal/teckna-kundavtal/
- Inera – Identifieringstjänst SITHS (e-legitimation = person, funktionscert = system; HSA-krav; SITHS eID Portal) — https://www.inera.se/tjanster/alla-tjanster-a-o/siths-identifieringstjanst/
- Inera – Autentiseringstjänst SITHS (kräver HSA-katalog + lokal IdP) — https://www.inera.se/tjanster/alla-tjanster-a-o/autentiseringstjanst-siths/
- Inera – Publicering till HSA (HSA-id som unik identifierare, serverobjekt) — https://inera.atlassian.net/wiki/spaces/IAM/pages/3123347587/Publicering+till+HSA
- Inera – Tredjepartsanslutning via SITHS-ombud — https://www.inera.se/kundservice/bestall--andra/gammal-bestall--andra-siths/tredjepartanslutning/
- Svensk e-identitet – HSA och SITHS ombudstjänst — https://e-identitet.se/auth/hsa-och-siths-ombudstjanst/
- VGR eTjänstekort – Beställ funktionscertifikat (X.509/PEM, klientcert) — https://www.vgregion.se/ov/etjanstekort/boka-och-bestall/funktionscertifikat/

**Sweden Connect / Digg (protokoll + öppen kod = alternativet)**
- Digg – Underskriftstjänst (öppen källkod, Sweden Connect-flöde) — https://www.digg.se/digitala-tjanster/e-underskrift/underskriftstjanst
- GitHub – swedenconnect/signservice (öppen källkod underskriftstjänst) — https://github.com/swedenconnect/signservice
- GitHub – idsec-solutions/signservice-integration (integrations-SDK) — https://github.com/idsec-solutions/signservice-integration
- Sweden Connect – Tekniskt ramverk (DSS, SignRequest/SignResponse) — https://docs.swedenconnect.se/technical-framework/
- DSS Extension for Federated Central Signing Services (SignRequestExtension, SignTasks, ToBeSignedBytes, IdP, PAdES) — https://docs.swedenconnect.se/technical-framework/latest/09_-_DSS_Extension_for_Federated_Signing_Services.html
- Implementation Profile for using DSS in Central Signing Services — https://github.com/swedenconnect/technical-framework/blob/master/07%20-%20Implementation%20Profile%20for%20using%20DSS%20in%20Central%20Signing%20Services.md
- Sweden Connect – signservice (vad det är) — http://docs.swedenconnect.se/signservice/what-is.html

**Bakgrund: AES/QES, LibreSign-gapet, bevarande (jfr esign-todo-native.md)**
- PTS – Elektroniska underskrifter (eIDAS-nivåer SES/AES/QES) — https://pts.se/internet-och-telefoni/elektroniska-underskrifter/
- SKR – Vägledning "Digitala underskrifter" (2025; SES/AES/QES, Godkänn vs Signera, PAdES/LTV) — https://skr.se/download/18.383b393a19afcdc7ea383305/1765380441264/Vagledning-Digitala%20underskrifter-2025.pdf
- Riksarkivet – Elektroniska underskrifter (bevarande, PDF/A, bevisvärde) — https://riksarkivet.se/resurser/elektroniska-underskrifter
- Digg – Tillitsnivåer för e-legitimering (LOA, BankID/Freja/SITHS) — https://www.digg.se/digitala-tjanster/e-legitimering/om-e-legitimering/tillitsnivaer-for-e-legitimering
- LibreSign (lokal rot-CA, konto/SMS-identitet, ingen BankID) — https://github.com/LibreSign/libresign · https://libresign.coop/

**Interna underlag (samma analyspaket)**
- `analysis-output/extended/esign-todo-native.md` (DEL 1 — LibreSign vs svensk AES, signeringsadapter)
- `analysis-output/extended/middleware-architecture.md` (mönster A/D, signering = mellanlagring, provenance)
- `hubs_start/src/services/widgetApps.js` (attSignera/skickatForSignering/justeringAnslag/mallarSamtycke backing)
