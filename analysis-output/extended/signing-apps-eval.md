# Signing-app-utvärdering för Hubs (Nextcloud 32) — vilken e-underskriftsapp ska vi installera, och varför

*Underlag för Hubs (ITSL). Datum: 2026-06-14. Intern teknisk analys. Appnamn (LibreSign, OnlyOffice, Collabora, eID Easy) får nämnas här för att kunna wira och fatta installationsbeslut; i produkt-/UI-text gäller fortfarande brandregeln — säg "e-underskrift", aldrig "Nextcloud"/"Talk"/appnamn.*

**Arkitektonisk ram:** Hubs är MELLANLAGRING (mellanarkiv). Slutarkivet/system of record är verksamhetens ärendehanteringssystem (socialtjänst: Treserva/Lifecare/Viva/Combine; arkiv: Sydarkivera FGS). Signeringskön iscensätts i Hubs; den signerade handlingen + valideringsintyg committas tillbaka in i facksystemet/diariet/e-arkivet som den bevarade allmänna handlingen.

**Detta dokument korsrefererar:** `esign-todo-native.md` (DEL 1 — LibreSign-modellen och AES-gapet i detalj) och Inera-dyket (Inera Underskriftstjänsten API: mTLS + SITHS funktionscert, BankID/Freja/SITHS, PAdES + PDF/A-1). Detta dokument är *appvals*-lagret: vilken NC-app vi faktiskt installerar och hur vi sätter upp den för demo.

---

## TL;DR — rekommendation

**Installera LibreSign (`libresign`) — det är den enda mogna, on-prem, native PDF-signeringsappen för NC 32, och den enda som ger hela kö-/spårnings-UI:t (Skickat → Öppnat → Signerat X av Y → Klart) utan att skicka dokumentet ut ur instansen.** Använd den i demo för att visa hela kedjan — men etikettera identiteten ärligt (konto/e-post/SMS, **inte** BankID) och kör den bara för internt lågrisk-"Godkänn". För skarpa myndighetsbeslut ska signeringssteget gå via **Inera Underskriftstjänsten API** (BankID/Freja/SITHS-AES) — se Inera-dyket. LibreSign är arbetsytan; Inera är trust-ankaret i produktion.

---

## DEL 1 — Kandidaterna i NC 32-ekosystemet

Fyra vägar finns att signera dokument i/runt Nextcloud 32. Bara en av dem ger native, on-prem, fullständig signeringskö.

### 1.1 LibreSign (`libresign`) — VALD

LibreCode Coop, AGPL-3.0. Den enda native, on-prem PDF-signeringsappen med köer, flöden och signerad PDF.

**NC-version (verifierat 2026-06-14 mot appstore):**

| Nextcloud-server | Rätt LibreSign-gren |
|---|---|
| NC 34 | v14.0.0 |
| NC 33 | v13.2.5 |
| **NC 32 (vår pinning)** | **v12.4.5** |
| NC 31 | v11.6.0 |
| NC 30 | v10.10.1 |

⚠ ANTAGANDE: appstore-sidan (`apps.nextcloud.com/apps/libresign`) anger NC 32 → v12.4.5. Den äldre `libresign.github.io/releases.html`-tabellen visade en cachad/inaktuell bild (toppade vid v8/NC 28) och ska INTE användas — historiskt har LibreSign legat strax efter senaste server, men för NC 32 finns en matchande gren. Verifiera mot er faktiska v32-instans vid install: `occ app:install libresign` plockar rätt gren automatiskt utifrån serverns version.

**Kapacitet (se `esign-todo-native.md` 1.1 för fulltext):**
- Signerar PDF kryptografiskt via **JSignPDF** (Java-motor). Output = **PAdES-likt** (digital signatur inbäddad i PDF:en; ändring efter signering bryter sigillet, syns i valideringsvyn).
- **Multi-signatär** i samma begäran (interna + externa), definierbar signeringsordning/roller/regler per flöde, audit trail.
- **Publika/gäst-signeringslänkar** (extern part signerar via e-postlänk utan eget konto).
- **OCS/REST-API** + Vue-frontend, validerings-URL per dokument, dashboard-integration, direkt koppling till Files/delning.

**Certifikatmodell — egen lokal rot-CA (INTE eID):**
- Två certifikatmotorer: **CFSSL** (Cloudflares PKI-verktyg, standardvalet, kräver `cfssl`-binär) och **OpenSSL** (alternativ i nyare versioner, slipper cfssl-binären).
- **PKCS#11/HSM** stöds för att skydda nyckeln i hårdvara/FIPS — men ändrar inte trust-modellen: roten är fortfarande er egen självsignerade, inte en betrodd QTSP/eID.

**Identify methods (hur signatär bevisar identitet före signering):** `account` (NC-inloggning), `email`, `sms`, `click-to-sign`. Den starkaste inbyggda för en *extern medborgare* är SMS — vilket inte håller för myndighetsbeslut.

### 1.2 OnlyOffice — INTE för server-/myndighetssignering

OnlyOffice connector för NC redigerar/sambearbetar dokument (Docs 8.2+ har signaturfält i PDF-formulär; connector 9.5+ har PDF-sambearbetning; 9.9 har DocuSign-integration).
- ⚠ LUCKA: **Digital signering finns bara i OnlyOffice Desktop Editors, inte i den server-/web-integrerade NC-versionen.** DocuSign-integrationen (9.9) är dessutom en extern moln-SaaS → samma OSL 10:2a-/CLOUD Act-problem vi bygger bort.
- **Roll i Hubs:** dokument-*redigering* (om OnlyOffice väljs framför Collabora för co-editing), **inte** signering.

### 1.3 Collabora Online — signerar via eID Easy (extern), inte native

Collabora kan signera ODF (built-in) och PDF — men **PDF-signering går via eID Easy-integration**, inte en built-in motor.
- Arkitektur (faktiskt verifierat): Collabora extraherar dokument-hash lokalt → skickar **bara hashen** till eID Easy-tjänsten → popup för eID-autentisering → signatur tillbaka. eID-"secret" stannar server-side; bara hash lämnar instansen.
- ⚠ LUCKA: eID Easy är en **betald moln-SaaS** (~€0,50/dokument). Även om bara hashen skickas är det en tredjepartsrunda som återinför molnbedömningen (eSam ES2023-06) — och Collabora-dokumentationen anger inte att svenska BankID/Freja stöds via deras eID Easy-flöde.
- **Roll i Hubs:** on-prem co-editing (Collabora är vårt produktionsantagande för säker sambearbetning), **inte** den signeringsväg vi förlitar oss på.

### 1.4 eID Easy (separat NC-app) — extern eID/QES, men moln

Sedan NC 22 kan eID Easy-appen begära signatur direkt i NC-gränssnittet och stödjer nationella eID, signaturkort, USB-token + AES/QES.
- ⚠ LUCKA (se `esign-todo-native.md` 1.4): **betald moln-SaaS**, dokument/hash tar en runda till tredje part → OSL 10:2a-/CLOUD Act-/eSam-bedömning återinförs. BankID/Freja-täckningen är inte svensk offentlig sektors etablerade väg. **OpenOTP Sign** (RCDevs) finns också men är extern server med användartak i gratisläget.
- ⚠ LUCKA: ingen native app ger BankID/Freja-AES on-prem out of the box.

### 1.5 "approval"-appen — INTE en signeringsapp

Nextclouds `approval`-app (godkänn/avvisa filer i en arbetsflödesregel) ger en *godkännandestämpel*, **inte** en kryptografisk underskrift eller PAdES-PDF. Den kan bära ett internt "Godkänn"-loggspår men ersätter inte LibreSign. ⚠ ANTAGANDE: relevant bara som komplement till intern-godkännandeflödet, inte som signeringsmotor.

---

## DEL 2 — Jämförelse

| Krav | **LibreSign** | OnlyOffice | Collabora | eID Easy |
|---|---|---|---|---|
| Native NC-app, NC 32 | ✅ v12.4.5 | redigering, ej server-sign | ✅ (sign via eID Easy) | ✅ |
| On-prem, inget dokument lämnar instansen | ✅ | (desktop-sign lokal) | hash lämnar | dok/hash lämnar |
| Full signeringskö (multi-part, gästlänk, audit) | ✅ | ❌ | begränsat | ✅ |
| PAdES/PDF-signatur | ✅ (JSignPDF) | desktop only | ✅ | ✅ |
| Kostnad | gratis (AGPL) | gratis connector | gratis | ~€0,50/dok |
| BankID/Freja-AES | ❌ (konto/SMS/klick) | ❌ | via eID Easy (ej svensk-bekräftat) | AES/QES men moln |
| Trust-ankare | egen lokal rot-CA | — | extern | extern QTSP |

**Slutsats:** För Hubs demo + intern-godkännande är **LibreSign** ensam vinnare — enda native, on-prem, fullständiga kön utan tredjepartsrunda. För skarp myndighets-AES vinner ingen NC-app; det måste gå via Inera (se DEL 4).

---

## DEL 3 — Setup för demo (occ-kommandon, NC 32)

Förutsättningar i NC-PHP-containern: **Java (JRE)** för JSignPDF, och `cfssl`-binären om CFSSL-motorn väljs. LibreSigns install-kommando kan hämta binärerna; i Docker lägger man annars Java + JSignPDF i Dockerfilen.

```bash
# 1) Installera appen (plockar rätt gren v12.x för NC 32 automatiskt)
occ app:install libresign
occ app:enable libresign

# 2) Hämta/installera alla beroenden (java, jsignpdf, cfssl, pdftk)
#    Alternativt i admin-UI: Inställningar → Administration → LibreSign → "Download binaries"
occ libresign:install --all
#    eller selektivt:
#    occ libresign:install --java
#    occ libresign:install --jsignpdf
#    occ libresign:install --cfssl
#    occ libresign:install --pdftk

# 3) Konfigurera certifikatmotorn + generera lokal rot-CA
#    CFSSL-motorn (standard):
occ libresign:configure:cfssl --cn="Hubs Demo CA" --ou="ITSL Hubs" --o="Kommun Demo" --c="SE" --st="Skane" --l="Malmo"
#    ELLER OpenSSL-motorn (slipper cfssl-binären):
occ libresign:configure:openssl --cn="Hubs Demo CA" --ou="ITSL Hubs" --o="Kommun Demo" --c="SE"

# 4) Verifiera att allt är grönt (binärer + rot-cert)
occ libresign:configure:check
```

Efter steg 3 ska statusvyn (admin-UI eller `configure:check`) vara grön på alla rader inklusive root certificate. ⚠ ANTAGANDE: exakta flaggor (`--st`/`--l`) kan variera mellan grenar — kör `occ libresign:configure:cfssl --help` på er faktiska v12-instans för auktoritativ flagglista. Rot-CA kan alternativt genereras i admin-UI ("Name (CN)" → *Generate root certificate*).

**Identity method för demo:** sätt `account` (signatär = inloggad Hubs-användare) som primär — då är bevisvärdet kopplat till Hubs-inloggningen, som i produktion är LOA3 (BankID/Freja/SITHS framför inloggningen). Använd `email`/`sms`/gästlänk bara för att demonstrera externt gäst-flöde, **tydligt märkt som svag identitet**.

**Wirning mot Hubs-widgetar:** `attSignera`/`skickatForSignering` → signeringsadapter med LibreSign-backend (se `esign-todo-native.md` 1.7). Visa hela kedjan Skickat → Öppnat → Signerat av X av Y → Klart redan i demo.

---

## DEL 4 — BankID/AES-gapet och Inera-vägen (icke förhandlingsbart)

⚠ LUCKA (det centrala): **LibreSign-AES ≠ svensk myndighets-AES.** LibreSigns starkaste interna identitetsfaktor för en extern medborgare är SMS (NIST *restricted*, otillräckligt för LOA3). Den har **ingen native BankID, ingen Freja eID, ingen SITHS eID, ingen Sweden Connect/eIDAS-anslutning, ingen QTSP-tidsstämpel, ingen QES**. Signaturerna är tekniskt giltiga digitala signaturer men knutna till er egen självsignerade rot som ingen utomstående validerar mot en betrodd lista. Identiteten bevisas bara av att personen var inloggad/klickade.

**Konsekvens — två tydligt åtskilda spår (se `esign-todo-native.md` 1.5–1.6 och Inera-dyket för fulltext):**

- **Internt lågrisk-"Godkänn":** LibreSigns konto-baserade signering + audit trail räcker — **så länge Hubs-inloggningen i sig är LOA3** (BankID/Freja/SITHS framför inloggningen). SKR:s vägledning skiljer "Godkänn" (loggat) från "Signera". Detta är LibreSigns rätta plats.
- **Allt externt + alla myndighetsbeslut (SIP, årsräkning, anställningsavtal, delgivning av beslut):** signeringssteget MÅSTE gå via **Inera Underskriftstjänsten API** — mTLS + SITHS funktionscertifikat (HSA-id), signering med **SITHS eID/BankID/Freja eID Plus**, resultat **PAdES + PDF/A-1** + LTV/tidsstämpel. Alternativt egen **Sweden Connect-nod** på Diggs öppna källkod för rena on-prem-kunder utan Inera-avtal. **Korsreferens: Inera-dyket** för anslutnings- och API-detaljer.

**Bygg arbetsytan + bevarandepanelen, inte kryptokärnan.** Samma kö-UI, två backends bakom signeringsadaptern: LibreSign (demo/internt) och Inera/Sweden Connect (produktion). Bygg "Giltig nu / Giltig då"-bevarandepanelen (PAdES/PDF/A/LTV, "Verifiera underskrift nu") — Riksarkivets tyngsta krav: bevisvärdet kräver att man arkiverar *beviset* om underskriften.

---

## Sammanfattande rekommendation

1. **Installera LibreSign (`libresign`, gren v12.4.5 för NC 32)** — enda native, on-prem, fullständiga signeringskön. Setup: `occ app:install libresign` → `occ libresign:install --all` → `occ libresign:configure:cfssl --cn=... --o=... --c=SE`.
2. **Demo:** wira `attSignera`/`skickatForSignering` mot LibreSign, identity method `account`, visa hela kedjan — **etikettera identiteten ärligt (konto/SMS, ej BankID)**, bara internt lågrisk-"Godkänn".
3. **OnlyOffice/Collabora:** för co-editing, **inte** signering (OnlyOffice server-sign saknas; Collabora signerar bara via eID Easy moln-SaaS).
4. **Undvik eID Easy/OpenOTP som primär väg** — moln-SaaS, återinför OSL/CLOUD Act/eSam-bedömningen.
5. **Produktion = Inera Underskriftstjänsten API** (BankID/Freja/SITHS-AES, PAdES + PDF/A-1) bakom samma signeringsadapter — korsref. Inera-dyket. LibreSign är arbetsytan; Inera är trust-ankaret.

---

## Källor

**LibreSign (vald app)**
- LibreSign på NC appstore (NC 32 → v12.4.5, NC 33 → v13.2.5, NC 34 → v14.0.0): https://apps.nextcloud.com/apps/libresign
- LibreSign GitHub (multi-signer, flöden, validering): https://github.com/LibreSign/libresign
- LibreSign dokumentation (User/Developer/Admin manual): https://docs.libresign.coop/
- LibreSign releases-kompatibilitet (notera: kan vara cachad/inaktuell — använd appstore som auktoritativ): https://libresign.github.io/releases.html
- occ-kommandon (`libresign:install`, `configure:cfssl --cn/--ou/--o/--c`, `configure:check`) — issues #1108/#1157/#564: https://github.com/LibreSign/libresign/issues/1108 · https://github.com/LibreSign/libresign/issues/564
- Java/JSignPDF/CFSSL-beroenden (Cloudron): https://forum.cloudron.io/topic/6716/nextcloud-libresign-need-java-how-i-can-install-it

**Alternativen**
- OnlyOffice digital signatur (endast Desktop Editors): https://helpcenter.onlyoffice.com/installation/desktop-digital-signature.aspx
- OnlyOffice + DocuSign (9.9, extern moln): https://www.linuxbabe.com/linux-server/onlyoffice-9-0-0-docusign
- Collabora Online signerar PDF via eID Easy (hash skickas externt): https://www.collaboraonline.com/blog/sign-pdfs-from-collabora-online-secure-your-documents-now/
- Nextcloud + eID Easy (NC 22, AES/QES, moln-SaaS): https://nextcloud.com/blog/nextcloud-22-makes-getting-your-document-signatures-easy/
- eID Easy NC-app docs: https://docs.eideasy.com/nextcloud/
- NC-forum: e-signaturlösningar LibreSign/OpenOTP/eIDEasy (extern eID, moln-tradeoff): https://help.nextcloud.com/t/electronic-signature-openotp-libresign-eideasy/132203

**Svensk e-underskrift — produktionsvägen (korsref. Inera-dyket + `esign-todo-native.md`)**
- Inera Underskriftstjänsten (Webb/API/Bas; SITHS/BankID/Freja; PDF/PAdES/PDF-A-1): https://www.inera.se/tjanster/alla-tjanster-a-o/underskriftstjansten/
- Inera Anslutningsguide (mTLS, SITHS funktionscert/HSA-id): https://inera.atlassian.net/wiki/spaces/UTJ/pages/3501787183/Anslutningsguide+-+Underskriftstj+nsten+Webb+och+API
- Digg Underskriftstjänst (öppen källkod, Sweden Connect): https://www.digg.se/digitala-tjanster/e-underskrift/underskriftstjanst
- SKR Vägledning "Digitala underskrifter" (Godkänn vs Signera, SES/AES/QES, PAdES/LTV): https://skr.se/download/18.383b393a19afcdc7ea383305/1765380441264/Vagledning-Digitala%20underskrifter-2025.pdf
- Riksarkivet Elektroniska underskrifter (bevarande): https://riksarkivet.se/resurser/elektroniska-underskrifter
