<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# E-underskrift i Hubs — LibreSign idag, Inera Underskriftstjänst för skarp AES, och vägen framåt

> **⚠ KORRIGERING 2026-07-14 (läs först):** Adversariellt verifierad omvärldsanalys har
> **motbevisat §2.2:s hash-antagande för API-varianten** — Ineras Underskriftstjänst API
> kräver att HELA dokumentet laddas upp (dokumentobjekt + octet-stream; inget
> hash-alternativ finns; lagras hos Inera till gallring, default 30 dagar). Endast
> **Bas**-varianten är hash-only (men SITHS-only, en undertecknare). Sekretessberättelsen
> går i stället via OSL 10:2a (sekretessgenombrott för teknisk bearbetning, ikraft
> 2023-07-01) + kort gallringsfrist. mTLS är bekräftad för API-åtkomsten (GAP-033 kan
> stängas). Aktuellt beslutsunderlag och kravställning:
> `itsl-open-stack/docs/signering/OMVARLDSANALYS-SIGNERING-2026-07.md` + `KRAV-SIGNERING-2026-07.md`.

> **Vad detta är:** den sammanslagna referensen för e-underskrift i Hubs. Den svarar på tre frågor:
> (1) **hur signerar vi i Hubs idag** (LibreSign — vald app, vad den klarar, hur den sätts upp),
> (2) **exakt vad som krävs för Inera Underskriftstjänst-API** (skarp svensk AES med BankID/Freja/SITHS —
> mTLS/OOB, SITHS funktionscert, Sweden Connect-flöde, avtal, format), och
> (3) **en rekommenderad väg framåt + setup-checklista för demo vs prod.**
>
> **Datum:** 2026-06-14. **Plattform:** server v32 (Hub 25 Autumn). Backar widgetarna `attSignera`,
> `skickatForSignering`, `justeringAnslag`, `mallarSamtycke`.
>
> **Arkitektonisk ram:** Hubs är **MELLANLAGRING**. Signeringskön iscensätts i Hubs; den signerade
> **PAdES/PDF/A**-handlingen + valideringsintyg **committas in i slutlagringen** (socialtjänst:
> Treserva/Lifecare/Viva/Combine; diarie: W3D3/Public 360°/Ciceron/Platina + e-arkiv/Sydarkivera FGS).
> Underskriftstjänsten lagrar **inte** dokumentet — den signerar en **hash** och returnerar signaturen;
> dokumentet stannar i Hubs tills det förs över. Handoff: **D** (manuell, dag 1) → **A** (API) i prod.
>
> **Brand-regel:** i produkt-/UI-text säger vi "e-underskrift", aldrig "Nextcloud"/"LibreSign"/appnamn. Här i
> det interna underlaget namnger vi appar och Inera-/Sweden Connect-/Digg-komponenter för spårbarhet.

---

## 0. TL;DR — den enda raden

**Installera LibreSign (`libresign`, gren v12.4.5 för NC 32)** för demo + internt lågrisk-"Godkänn" — det är den
enda mogna, on-prem, native PDF-signeringsappen för NC 32 som ger hela kö-/spårnings-UI:t (Skickat → Öppnat →
Signerat X av Y → Klart) **utan att skicka dokumentet ut ur instansen**. Men **etikettera identiteten ärligt**
(konto/e-post/SMS, **inte** BankID) och kör den bara för internt lågrisk.

**För skarpa myndighetsbeslut** (avslag, bistånd, SIP, justering av protokoll, samtycke, delgivning) **MÅSTE**
signeringssteget gå via **Inera Underskriftstjänst-API** (BankID/Freja/SITHS-AES → PAdES + PDF/A-1 + LTV) bakom
samma signeringsadapter. **LibreSign är arbetsytan; Inera är trust-ankaret i produktion.**

**Den enskilt största blockeraren** är inte teknik utan avtal: **kommunen måste vara Inera-kund med tecknat
kundavtal OCH ansluten till SITHS + HSA-katalogen** (funktionscert + mTLS hänger på det) — en organisatorisk/
avtalsmässig process (veckor–månader), inte en konfigurationsdetalj.

---

## DEL 1 — Hur man signerar i Hubs idag (LibreSign)

### 1.1 Varför LibreSign är vald

Fyra vägar finns att signera dokument i/runt NC 32. Bara **en** ger native, on-prem, fullständig signeringskö:

| Krav | **LibreSign** | OnlyOffice | Collabora | eID Easy |
|---|---|---|---|---|
| Native NC-app, NC 32 | ✅ v12.4.5 | redigering, ej server-sign | ✅ (sign via eID Easy) | ✅ |
| On-prem, inget dokument lämnar instansen | ✅ | (desktop-sign lokal) | hash lämnar | dok/hash lämnar |
| Full signeringskö (multi-part, gästlänk, audit) | ✅ | ❌ | begränsat | ✅ |
| PAdES/PDF-signatur | ✅ (JSignPDF) | desktop only | ✅ | ✅ |
| Kostnad | gratis (AGPL) | gratis connector | gratis | ~€0,50/dok |
| BankID/Freja-AES | ❌ (konto/SMS/klick) | ❌ | via eID Easy (ej svensk-bekräftat) | AES/QES men moln |
| Trust-ankare | egen lokal rot-CA | — | extern | extern QTSP |

- **OnlyOffice:** digital signering finns bara i Desktop Editors, inte i den server-/web-integrerade
  NC-versionen; DocuSign-integrationen (9.9) är extern moln-SaaS (OSL 10:2a/CLOUD Act-problem). Roll i Hubs =
  *co-editing*, inte signering.
- **Collabora:** signerar PDF bara via **eID Easy**-integration (betald moln-SaaS, ~€0,50/dok; hash lämnar
  instansen; svenska BankID/Freja ej bekräftat). Roll i Hubs = on-prem co-editing, inte signering.
- **eID Easy / OpenOTP:** betald moln-SaaS → återinför OSL 10:2a-/CLOUD Act-/eSam-bedömningen. Ingen native app
  ger BankID/Freja-AES on-prem out of the box.
- **`approval`-appen:** ger en *godkännandestämpel*, inte en kryptografisk underskrift/PAdES — komplement till
  intern-godkännandeflödet, inte signeringsmotor.

**NC-version-pinning (verifierat 2026-06-14 mot appstore):** NC 32 → **LibreSign v12.4.5** (NC 31 → v11.6.0,
NC 33 → v13.2.5, NC 34 → v14.0.0). *⚠ Använd appstore-sidan som auktoritativ; den äldre
`libresign.github.io/releases.html`-tabellen var cachad/inaktuell. `occ app:install libresign` plockar rätt
gren automatiskt utifrån serverns version — verifiera mot er faktiska v32-instans vid install.*

### 1.2 Vad LibreSign klarar

- Signerar PDF kryptografiskt via **JSignPDF** (Java-motor). Output = **PAdES-likt** (digital signatur inbäddad;
  ändring efter signering bryter sigillet, syns i valideringsvyn).
- **Multi-signatär** i samma begäran (interna + externa), definierbar signeringsordning/roller/regler per flöde,
  audit trail.
- **Publika/gäst-signeringslänkar** (extern part signerar via e-postlänk utan eget konto).
- **OCS/REST-API** + Vue-frontend, validerings-URL per dokument, dashboard-integration, koppling till Files.

**Certifikatmodell — egen lokal rot-CA (INTE eID):** två motorer — **CFSSL** (Cloudflares PKI-verktyg,
standardvalet) och **OpenSSL** (slipper cfssl-binären). PKCS#11/HSM kan skydda nyckeln i hårdvara, men ändrar
**inte** trust-modellen: roten är fortfarande er egen självsignerade, inte en betrodd QTSP/eID.

**Identify methods** (hur signatär bevisar identitet): `account` (NC-inloggning), `email`, `sms`,
`click-to-sign`. Den starkaste *inbyggda* för en extern medborgare är **SMS** — vilket **inte** håller för
myndighetsbeslut.

### 1.3 Setup för demo (occ-kommandon, NC 32)

Förutsättningar i NC-PHP-containern: **Java (JRE)** för JSignPDF, och `cfssl`-binären om CFSSL-motorn väljs.
LibreSigns install-kommando kan hämta binärerna; i Docker lägger man annars Java + JSignPDF i Dockerfilen.

```bash
# 1) Installera appen (plockar rätt gren v12.x för NC 32 automatiskt)
occ app:install libresign
occ app:enable libresign

# 2) Hämta/installera alla beroenden (java, jsignpdf, cfssl, pdftk)
#    Alternativt i admin-UI: Inställningar → Administration → LibreSign → "Download binaries"
occ libresign:install --all
#    eller selektivt: --java / --jsignpdf / --cfssl / --pdftk

# 3) Konfigurera certifikatmotorn + generera lokal rot-CA
#    CFSSL-motorn (standard):
occ libresign:configure:cfssl --cn="Hubs Demo CA" --ou="ITSL Hubs" --o="Kommun Demo" --c="SE" --st="Skane" --l="Malmo"
#    ELLER OpenSSL-motorn (slipper cfssl-binären):
occ libresign:configure:openssl --cn="Hubs Demo CA" --ou="ITSL Hubs" --o="Kommun Demo" --c="SE"

# 4) Verifiera att allt är grönt (binärer + rot-cert)
occ libresign:configure:check
```

Efter steg 3 ska statusvyn (admin-UI eller `configure:check`) vara grön på alla rader inklusive root
certificate. *⚠ Exakta flaggor (`--st`/`--l`) kan variera mellan grenar — kör `occ
libresign:configure:cfssl --help` på er faktiska v12-instans. Rot-CA kan alternativt genereras i admin-UI
("Name (CN)" → Generate root certificate).*

**Identity method för demo:** sätt `account` (signatär = inloggad Hubs-användare) som primär — då är bevisvärdet
kopplat till Hubs-inloggningen, som i produktion är LOA3 (BankID/Freja/SITHS framför inloggningen). Använd
`email`/`sms`/gästlänk bara för att demonstrera externt gäst-flöde, **tydligt märkt som svag identitet**.

**Wirning mot Hubs-widgetar:** `attSignera`/`skickatForSignering` → signeringsadapter med LibreSign-backend.
Visa hela kedjan *Skickat → Öppnat → Signerat av X av Y → Klart* redan i demo.

---

## DEL 2 — Inera Underskriftstjänst-API (skarp svensk AES)

### 2.1 Vad tjänsten är — tre varianter

Inera **Underskriftstjänsten** är den nationella, vård/omsorgs-förankrade e-underskriftstjänsten som ger
**avancerad elektronisk underskrift (AES) enligt eIDAS** med **SITHS eID, BankID, Freja eID Plus** (+ utländsk
eID via eIDAS) på ett **PDF/PAdES**-dokument. Den är byggd på **Sweden Connects tekniska ramverk** (samma
DSS/Signature-Service-protokoll som Diggs öppna kod). Tre leveransformer:

| Variant | Vad | Vem legitimerar sig | Vem signerar | Format | För vem |
|---|---|---|---|---|---|
| **Webb** | Fristående webb-UI: ladda upp PDF, peka ut signatärer | Beställaren med **SITHS eID** | SITHS/BankID/Freja/utländsk eID | PDF + PAdES | Regioner, kommuner, kommunala bolag & förbund |
| **API** ← *Hubs integrerar mot denna* | Tekniskt gränssnitt: verksamhetssystemet driver flödet | Verksamhetssystemets egen auth (Hubs-sessionen, LOA3) | SITHS/BankID/Freja/utländsk eID | **PDF + PAdES** | Regioner, kommuner |
| **Bas** | Ensignering, ett dokument en undertecknare (t.ex. läkarintyg) | **SITHS eID** | SITHS eID | PDF **och XML** | Regioner, statliga myndigheter, privata offentligt finansierade vårdgivare |

**eIDAS-nivå:** Inera anger uttryckligen **AES** (advanced), **inte** QES. Det är "arbetshästen" för
myndighetsbeslut, SIP-planer, justering av protokoll, samtycken; QES sparas för de få fall lag uttryckligen
kräver det.

> ⚠ **Webb-varianten är fallback dag 1** om API-anslutningen inte är klar — den kräver bara SITHS-kort för
> beställaren och inget systemcert, och Hubs kan deep-linka till den. Dokumentera som mellanstation i
> leveransplanen.

### 2.2 Signeringsflödet (end-to-end), API-varianten

Inera-API:t följer Sweden Connect-mönstret **"autentisering (IdP) skild från signering (central
underskriftstjänst)"**. Den centrala tjänsten äger **ingen permanent signeringsnyckel per användare** — den
**genererar en engångsnyckel + ett engångscertifikat** vid varje signeringstillfälle, knutet till den identitet
eID:t bevisar.

1. **Handläggar-action:** i Hubs öppnar handläggaren beslut/utredning/SIP/samtycke i `attSignera` och klickar
   **"Skicka för underskrift"**.
2. **Hubs (signeringsadapter, prod-backend):** beräknar **checksumma/hash** på PDF:en och **skapar ett
   signeringsuppdrag** via ett **HTTP-anrop mot Underskriftstjänsten-API** över **mTLS** (SITHS funktionscert
   som klientcert). Anropet anger **signaturprofil** (vilket eID), dokument(hash) och retur-URL.
   - Svaret bär ett **`Location`-headerfält** som pekar på det skapade signeringsuppdraget — den URI:n blir
     **bas-URI för alla följande anrop** (statuskoll, hämta resultat).
3. **Underskriftstjänsten → Sweden Connect/eID:** tjänsten dirigerar undertecknaren till rätt **IdP** (BankID/
   Freja/SITHS) för **legitimering + godkännande av signeringsmeddelandet** (`SignMessage`: "Du skriver under
   *Beslut om bistånd, barn 2026-0412*"). *⚠ Signeringsmeddelandets exakta text måste sättas av Hubs-adaptern
   och granskas juridiskt — fel/vag text underminerar bevisvärdet.*
4. **Signaturframställning (central tjänst):** efter lyckad legitimering **genererar tjänsten nyckel + cert**,
   plockar in undertecknarens attribut (namn, personnummer/HSA-id) i certets subject ur eID-assertion, och
   **signerar hashen** → **PAdES**-signatur.
5. **Underskriftstjänsten → Hubs:** signaturen + certifikatkedjan + assertion/undertecknarbevis returneras.
   Hubs assemblerar den färdiga signerade PDF:en (PAdES). Hubs **pollar `Location`-URI:n** (skapad → legitimerad
   → signerad → klar) eller tar emot callback till retur-URL. *⚠ Poll vs callback: verifiera i Utvecklarinfo —
   webhook/callback föredras för `skickatForSignering`-statusuppdatering.*
6. **Efterbearbetning i Hubs (mellanlagring):** konvertera/säkerställ **PDF/A-1** (Riksarkivet), lägg på
   **kvalificerad tidsstämpel + LTV**, spara **valideringsintyg**. Detta är bevarandepanelen "Giltig nu /
   Giltig då".
7. **För över till slutlagring (mönster D/A):** den signerade PAdES/PDF/A + valideringsintyg **committas till
   facksystemet/diariet/e-arkivet**. Hubs behåller referens + kvittens, inte originalet; mellanlagringskopian
   gallras (Retention).

**Datariktning:** dokumentet **stannar i Hubs** — bara **hashen** + ett signeringsmeddelande exponeras mot
Underskriftstjänsten; signaturen kommer tillbaka IN. Ingen sekretessbärande dokumenttext lämnar driftmiljön
till Inera. *⚠ Bekräfta att API-profilen verkligen är hash-/checksummebaserad (att hela PDF:en inte laddas upp)
— avgörande för OSL 10:2a-berättelsen innan vi påstår det i sälj-/juristmaterial.*

### 2.3 Tekniska förutsättningar (det som måste finnas på plats)

**mTLS + SITHS funktionscertifikat (klientcert) — kärnan i API-anslutningen:**
- API:t anropas med **mutual TLS**: Hubs presenterar ett **SITHS funktionscertifikat** (system-/serveridentitet,
  inte personcert) som klientcert — **ett per verksamhetssystem**.
- Funktionscertet **bär aktörens HSA-id**; utifrån certet är API:t konfigurerat med aktörens
  **organisationsnummer** — certet *är* aktörsbindningen.
- Exporteras i **X.509-format**, sparas som **PEM/Base64 (.pem)**; **den publika nyckeln skickas in i
  förstudien** vid anslutning (whitelistas hos Inera).
- SITHS skiljer **e-legitimation** (personer — kort/eID) från **funktionscertifikat** (system). API-integrationen
  behöver **funktionscertet**.

> ⚠ **mTLS vs OOB (GAP-033):** tidigare underlag säger Inera-API = mTLS. Aktuell anslutningsguide (webbsökning
> 2026-06) indikerar att **nya Underskriftstjänsten för SITHS eID kräver OOB (out-of-band), inte den äldre
> mTLS-tekniken**, medan **funktionscertifikat per integrerande verksamhetssystem fortfarande gäller**.
> BankID/Freja-signering påverkas troligen inte (OOB-bytet rör hur *verksamhetssystemet* autentiserar
> uppladdning). **Exakt anslutningsprofil måste verifieras mot aktuell Inera-anslutningsguide vid
> implementation** — konfigurationsdetalj, inte arkitekturlucka.

**HSA-katalog + SITHS-anslutning (förutsättningen under funktionscertet):**
- Organisationen måste vara **ansluten till Katalogtjänst HSA**; HSA-id är den unika identifieraren och
  serverobjektet läggs upp i HSA.
- Funktionscert beställs/livscykelhanteras i **SITHS eID Portal** av behöriga (RA/utgivare/ombud);
  kortbeställningar går till Inera, leverans ~3 veckor.
- Organisationer utan egen direktanslutning kan ansluta via **SITHS-ombud / tredjepartsanslutning** (Svensk
  e-identitet m.fl.).

**Inera-kundavtal + behörighet:**
- Organisationen måste vara **Inera-kund** med tecknat **kundavtal** och genomgången **kundkvalificering**.
  Behörigheten styrs av marknadsbegränsningen i Ineras bolagsordning: regioner, kommuner, kommunala bolag/
  förbund (Bas även statliga/privata offentligt finansierade vårdgivare).
- **Underskriftstjänsten – API** är tillgänglig för **regioner och kommuner**.
- Anslutning kan ske **direkt** eller **via ombud/systemleverantör**.

**Signaturprofiler, format, miljöer:**
- **Signaturprofil** styr vilket eID som tillåts (SITHS/BankID/Freja/utländsk) + cert-/nivåkrav.
- **Format:** **PDF in → PAdES ut** (API/Webb; Bas även XML). För arkiv: Hubs säkerställer **PDF/A-1 + LTV** i
  efterbearbetning (Inera levererar PAdES, Hubs gör arkivhärdningen).
- **Miljöer:** test/QA finns före produktion (kräver test-funktionscert). *⚠ Exakta test-endpoints/host och om
  separat test-HSA-id krävs framgår i Ineras Confluence (Utvecklarinfo + Anslutningsguide) — sidorna lät sig
  inte fullständigt hämtas maskinellt; läs direkt av integratören med kundportal-access.*

### 2.4 Skillnaden mot LibreSign (varför LibreSign inte räcker för myndighets-AES)

| Dimension | **LibreSign** (`libresign`) | **Inera Underskriftstjänsten – API** |
|---|---|---|
| Identitet på undertecknaren | Konto / e-post / SMS / click-to-sign — **ingen BankID** | **SITHS eID / BankID / Freja eID Plus / utländsk eID** (LOA3) |
| Trust-ankare | **Egen självsignerad lokal rot-CA**, ingen utomstående validerar mot betrodd lista | Sweden Connect-federationen / nationell betrodd tjänst; eID-utfärdaren bevisar identiteten |
| Signaturmotor | JSignPDF (lokalt), engångs lokalt cert per konto | Central tjänst **genererar engångsnyckel+cert** bundet till eID-bevisad identitet |
| eIDAS i praktiken | SES (klick) → svag AES (lokal rot) | **AES** enligt eIDAS, validerbar externt |
| Format | PDF (PAdES-likt) | **PDF + PAdES**; Hubs härdar till **PDF/A-1 + LTV** |
| Anslutning | `occ libresign:install` — ingen extern part | **mTLS/OOB + SITHS funktionscert**, kundavtal, HSA/SITHS |
| Dokumentet lämnar miljön? | Nej (helt on-prem) | Nej (endast **hash** + signeringsmeddelande) ⚠ verifiera profil |
| Var det passar | **Internt lågrisk "Godkänn"** (när Hubs-inloggningen i sig är LOA3) | **Allt externt + alla myndighetsbeslut** |

### 2.5 Alternativet: egen Sweden Connect-/Digg-nod (kunder utan Inera-avtal)

Digg publicerar **öppen källkod** för en fristående underskriftstjänst på Sweden Connects ramverk:
- Kod: **`github.com/swedenconnect/signservice`** + **`github.com/idsec-solutions/signservice-integration`**.
- **Protokoll (DSS Extension for Federated Central Signing Services):** verksamhetssystemet skickar en signerad
  `<dss:SignRequest>` med `<SignRequestExtension>` (IdP-entityID för BankID/Freja, `<SignRequester>`/
  `<SignService>`, valfritt `<SignMessage>`) + `<SignTasks>`/`<ToBeSignedBytes>` (hashen). Tjänsten
  re-autentiserar mot IdP, genererar nyckel+cert ur SAML-assertion, signerar hashen, svarar med
  `<dss:SignResponse>` (`<Base64Signature>` + `<SignatureCertificateChain>` + `<SignerAssertionInfo>`).
  Verksamhetssystemet assemblerar den färdiga PAdES/XAdES.
- **Behövs:** e-tjänst med Sweden Connect-anslutning (SAML-metadata), koppling till en CA, och integration
  e-tjänst↔underskriftstjänst. Användaren ska vara identifierad **innan** hen skickas till underskriftstjänsten.
- **Inera vs Digg:** Inera = **managed** (köpt tjänst, SITHS-förankrad, snabbast för vård/omsorg). Digg =
  **self-hosted** (du driver noden, äger Sweden Connect-anslutning + CA — mer suveränitet/kontroll, mer
  drift/ansvar). *⚠ BankID/Freja-täckning via egen nod kräver egen anslutning till respektive eID i
  federationen.*

**Val:** för svensk **kommun inom vård/omsorg** är **Inera-API:t den primära vägen** (SITHS/HSA finns oftast
redan, AES, BankID/Freja/SITHS i en profil). Egen Sweden Connect-nod är spåret för rena on-prem-kunder utan
Inera-avtal eller där maximal suveränitet väger tyngre än time-to-market.

---

## DEL 3 — Hur det wiras in i Hubs (arkitekturval)

| Val | Vad | För/Nack |
|---|---|---|
| **A. Egen signeringsadapter-app (NC-app i Hubs)** ← **rekommenderad** | Tunn NC-app som exponerar `attSignera`/`skickatForSignering`-API:t mot frontend, med **två utbytbara backends** (LibreSign / Inera-API resp. Sweden Connect-nod). Hubs äger UI, kö, provenance, PDF/A-1+LTV-härdning, "för över till facksystem". mTLS-anropet mot Inera görs server-side (funktionscert i NC:s secret-store). | **+** Full kontroll på kö-UI, brand-regel, bevarandepanel; eID/trust-lagret blir ett bytbart adapterinterface. **−** Mest egen kod; måste hantera funktionscert/secret + mTLS i PHP/server. |
| **B. LibreSign-extension (identify method-plugin)** | Skriva en LibreSign "identify method"/motor som ringer Inera/Sweden Connect. | **+** Återanvänder LibreSigns kö/flöde. **−** LibreSigns modell (lokal rot-CA + JSignPDF) bryter mot central eID-signering; sannolikt mer friktion än egen adapter. ⚠ Kräver utredning av LibreSign-API:ts utbyggbarhet. |
| **C. Extern signeringsmikrotjänst (sidecar) + deep-link/callback** | Separat on-prem-tjänst håller mTLS + funktionscert mot Inera; Hubs anropar + tar emot callback. | **+** Isolerar funktionscert/mTLS från NC-appen, delbar mellan flera system. **−** En komponent till att drifta/härda; callback-säkerhet. Bra om kommunen redan har en signerings-sidecar. |

**Princip:** **bygg arbetsytan + bevarandepanelen ("Giltig nu / Giltig då"), inte kryptokärnan.** Samma kö-UI,
två backends bakom signeringsadaptern. UI:t och provenance-modellen är identiska; bara identitets-/trust-lagret
byts.

**Provenance per signeringsflöde (måste synas i widgeten):**
- *Data FRÅN:* dokument skapat i Hubs ärenderum (Groupfolders) eller exporterat ur facksystemet.
- *Signering iscensätts i:* Hubs (LibreSign demo / Inera-API prod) — **mellanlagring**. Bara hash +
  signeringsmeddelande mot tjänsten.
- *Data TILL (slutlagring):* signerad **PAdES/PDF/A** + **valideringsintyg** committas till facksystemet/
  diariet/e-arkivet (mönster **D**, ev. **A**). Hubs-kopia gallras (Retention).

---

## DEL 4 — Rekommenderad väg framåt

1. **Demo / dag 1:** wira `attSignera`/`skickatForSignering` mot **LibreSign-backend**, identity method
   `account`, visa hela kedjan on-prem — **etikettera identiteten ärligt** (konto/SMS, ej BankID), bara internt
   lågrisk-"Godkänn". Bygg **bevarandepanelen** redan här (men påstå inte LTV i LibreSign-demon — GAP-035).
2. **Mellanstation:** om Inera-API-anslutningen drar ut på tiden, deep-linka till **Inera Webb-varianten**
   (kräver bara SITHS-kort för beställaren, inget systemcert) för skarpa enstaka underskrifter.
3. **Produktion:** bygg **arkitekturval A** — egen signeringsadapter-app med **Inera-API som prod-backend**
   (BankID/Freja/SITHS → PAdES → Hubs härdar PDF/A-1 + LTV) och **Sweden Connect-nod som alternativ** för rena
   on-prem-kunder. LibreSign blir kvar för internt lågrisk; Inera är trust-ankaret för allt externt + alla
   myndighetsbeslut.
4. **Juridisk grind (per dokumenttyp):** mappa beslutstyp → kravnivå (AES / "Godkänn" / QES) mot **SKR:s
   vägledning "Digitala underskrifter" 2025** (riskmodell SES/AES/QES, "Godkänn vs Signera"). Hubs **visar
   kravnivå-badge**, gissar den inte; verksamheten/juristen sätter regeln per kund (GAP-036).
5. **Undvik** eID Easy/OpenOTP/Collabora-eID Easy som primär väg — moln-SaaS som återinför OSL 10:2a/CLOUD
   Act/eSam-bedömningen.

---

## DEL 5 — Setup-checklista (demo vs prod)

### Demo (LibreSign, on-prem, internt lågrisk)

- [ ] Java (JRE) + ev. `cfssl` i NC-PHP-containern (Dockerfile eller `occ libresign:install`).
- [ ] `occ app:install libresign` → `occ app:enable libresign` (gren v12.4.5 för NC 32, auto-plockad).
- [ ] `occ libresign:install --all` (java, jsignpdf, cfssl, pdftk) — eller "Download binaries" i admin-UI.
- [ ] `occ libresign:configure:cfssl --cn=… --o=… --c=SE` (eller `configure:openssl`) → generera lokal rot-CA.
- [ ] `occ libresign:configure:check` grön på alla rader inkl. root certificate.
- [ ] Identity method = `account` primär; `email`/`sms`/gästlänk **märkt som svag identitet**.
- [ ] Wira `attSignera`/`skickatForSignering` mot LibreSign-backend; visa Skickat→Öppnat→Signerat X av Y→Klart.
- [ ] Bevarandepanel "Giltig nu / Giltig då" — men **påstå inte LTV** i LibreSign-demon (etikettera ärligt).
- [ ] UI-text: "e-underskrift" (brand-regel); identitet etiketterad "konto/SMS, ej BankID".

### Prod (Inera Underskriftstjänst-API, skarp AES)

**Organisatoriskt/avtal (den långa ledtiden — veckor–månader, börja först):**
- [ ] Inera-kund: teckna **kundavtal** + genomgå **kundkvalificering** (många kommuner är redan kund via SITHS/
      HSA/NPÖ).
- [ ] **SITHS + HSA** på plats: organisationen ansluten till Katalogtjänst HSA + Identifierings-/
      Autentiseringstjänst SITHS (direkt eller via ombud) — *grundplåten; funktionscert hänger på det.*
- [ ] Beställ tjänsten **Underskriftstjänsten – API** i Ineras kundportal (`kundportal.inera.se`).

**Tekniskt:**
- [ ] Beställ **SITHS funktionscertifikat** för verksamhetssystemet (SITHS eID Portal; leverans ~3 v).
- [ ] Exportera publik nyckel (**X.509/PEM**), skicka in i **förstudien** → Inera whitelistar certet + knyter
      organisationsnummer.
- [ ] **Verifiera anslutningsprofil: mTLS vs OOB** mot aktuell Inera-anslutningsguide (GAP-033).
- [ ] Säkerställ **funktionscert-hantering i NC** (var nyckeln bor, rotation, ev. HSM/PKCS#11) — ett komprometterat
      funktionscert = aktörens systemidentitet.
- [ ] Implementera signeringsadapterns prod-backend (skapa uppdrag → `Location`-URI → poll/callback → hämta
      PAdES); välj **signaturprofil(er)** (vilket eID).
- [ ] **Bekräfta hash-/checksummebaserad signering** (hela PDF:en laddas inte upp till Inera) — OSL 10:2a.
- [ ] Definiera **`SignMessage`-texter** per dokumenttyp (juridiskt granskade).
- [ ] Bygg **PDF/A-1 + LTV + kvalificerad tidsstämpel + valideringsintyg**-härdning i Hubs efter PAdES-svar
      (verifiera om Inera-profilen kan leverera LTV/tidsstämpel direkt).
- [ ] Testa i **test/QA-miljö** (test-funktionscert) före produktion.

**Juridiskt (per dokumenttyp):**
- [ ] Mappa beslutstyp → kravnivå (AES / "Godkänn" / QES) mot SKR:s vägledning (GAP-036).
- [ ] Externa signatärer + alla myndighetsbeslut → **Inera-AES**, inte LibreSign gästlänk (GAP-037).

---

## Källor

**LibreSign (vald app)**
- LibreSign på NC appstore (NC 32 → v12.4.5) — https://apps.nextcloud.com/apps/libresign
- LibreSign GitHub / docs — https://github.com/LibreSign/libresign · https://docs.libresign.coop/
- occ-kommandon (`libresign:install`, `configure:cfssl/openssl`, `configure:check`) — issues #1108/#564

**Alternativen**
- OnlyOffice digital signatur (endast Desktop) — https://helpcenter.onlyoffice.com/installation/desktop-digital-signature.aspx
- Collabora signerar PDF via eID Easy (hash externt) — https://www.collaboraonline.com/blog/sign-pdfs-from-collabora-online-secure-your-documents-now/
- Nextcloud + eID Easy (moln-SaaS) — https://nextcloud.com/blog/nextcloud-22-makes-getting-your-document-signatures-easy/ · https://docs.eideasy.com/nextcloud/

**Inera Underskriftstjänsten (tjänst, varianter, eID, AES)**
- Inera – Underskriftstjänsten (Webb/API/Bas; SITHS/BankID/Freja/utländsk; PDF/PAdES/XML; AES enligt eIDAS) — https://www.inera.se/tjanster/alla-tjanster-a-o/underskriftstjansten/
- Inera Confluence – Anslutningsguide, Underskriftstjänsten Webb och API (mTLS, SITHS funktionscert/HSA-id, PEM, förstudie) — https://inera.atlassian.net/wiki/spaces/UTJ/pages/3501787183/Anslutningsguide+-+Underskriftstj+nsten+Webb+och+API
- Inera Confluence – 2.1 Utvecklarinfo – Underskriftstjänsten API (Location-header/bas-URI) — https://inera.atlassian.net/wiki/spaces/UTJ/pages/3501785147/
- Inera – Nu lanseras Ineras utvidgade underskriftstjänst (BankID/Freja-tillägg) — https://www.inera.se/aktuellt/nyheter/nu-lanseras-ineras-utvidgade-underskriftstjanst/

**Inera kundmodell / avtal / SITHS / HSA (förutsättningarna)**
- Inera – Ineras kundmodell / Teckna kundavtal — https://www.inera.se/kontakta-oss/avtal-bestallning-anslutning/ineras-kundmodell/
- Inera – Identifieringstjänst SITHS (e-legitimation = person, funktionscert = system; HSA-krav) — https://www.inera.se/tjanster/alla-tjanster-a-o/siths-identifieringstjanst/
- Inera – Publicering till HSA — https://inera.atlassian.net/wiki/spaces/IAM/pages/3123347587/Publicering+till+HSA
- Inera – Tredjepartsanslutning via SITHS-ombud — https://www.inera.se/kundservice/bestall--andra/gammal-bestall--andra-siths/tredjepartanslutning/
- VGR eTjänstekort – Beställ funktionscertifikat (X.509/PEM, klientcert) — https://www.vgregion.se/ov/etjanstekort/boka-och-bestall/funktionscertifikat/

**Sweden Connect / Digg (protokoll + öppen kod = alternativet)**
- Digg – Underskriftstjänst (öppen källkod, Sweden Connect) — https://www.digg.se/digitala-tjanster/e-underskrift/underskriftstjanst
- GitHub – swedenconnect/signservice + idsec-solutions/signservice-integration — https://github.com/swedenconnect/signservice
- Sweden Connect – Tekniskt ramverk (DSS, SignRequest/SignResponse) — https://docs.swedenconnect.se/technical-framework/
- DSS Extension for Federated Central Signing Services — https://docs.swedenconnect.se/technical-framework/latest/09_-_DSS_Extension_for_Federated_Signing_Services.html

**AES/QES, bevarande, riskmodell**
- PTS – Elektroniska underskrifter (SES/AES/QES) — https://pts.se/internet-och-telefoni/elektroniska-underskrifter/
- SKR – Vägledning "Digitala underskrifter" (2025; Godkänn vs Signera, PAdES/LTV) — https://skr.se/download/18.383b393a19afcdc7ea383305/1765380441264/Vagledning-Digitala%20underskrifter-2025.pdf
- Riksarkivet – Elektroniska underskrifter (bevarande, PDF/A, bevisvärde) — https://riksarkivet.se/resurser/elektroniska-underskrifter
- Digg – Tillitsnivåer för e-legitimering (LOA, BankID/Freja/SITHS) — https://www.digg.se/digitala-tjanster/e-legitimering/om-e-legitimering/tillitsnivaer-for-e-legitimering

**Interna underlag (samma analyspaket)**
- `analysis-output/extended/signing-apps-eval.md` (appvals-lagret)
- `analysis-output/extended/signing-inera-api.md` (Inera API-djupdyk)
- `analysis-output/extended/esign-todo-native.md` (DEL 1 — LibreSign vs svensk AES, signeringsadapter)
- `analysis-output/extended/middleware-architecture.md` (mönster A/D, signering = mellanlagring, provenance)
- `hubs_start/src/services/widgetApps.js` (attSignera/skickatForSignering/justeringAnslag/mallarSamtycke)
- `hubs_start/docs/WIDGET-APP-MAP.md` · `GAP-ANALYSIS.md` (GAP-033/034/035/036/037)
