<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Genomgång 4 — Beslut, e-signering & delgivning (socialsekreterare, barn & familj)

> **Vad detta är:** en numrerad, steg-för-steg-genomgång av **ETT** flöde — handläggaren tar fram ett beslut (Collabora), skickar det för underskrift, signerar, delger medborgaren säkert med läskvittens, och committar den signerade handlingen + delgivningsbeviset till facksystemet (Treserva) och vidare till e-arkiv (FGS). Målet är att en granskare ska kunna avgöra om resonemanget **HÅLLER** eller har **LUCKOR**. Därför bär varje steg: exakt handläggar-action, exakt Hubs-widget/-app + API/route + status, vad som händer i facksystemet (mönster A/B/C/D), datariktning + sekretess/LOA + gallring, och en explicit ⚠-flagga.
>
> **Bärande modell:** Hubs är **mellanlagring (mellanarkiv)**. Slutlagring (system of record / slutarkiv) är **Treserva/Lifecare/Viva/Combine** (socialakten/BBIC-journalen) och i slutänden e-arkiv via **FGS** (Sydarkivera). Hubs äger arbetsytan + signeringskön + delgivningskvittensen — **aldrig** originalet som arkivsanning.
>
> **Brand-regel:** i produkt-/UI-text säger vi aldrig "Nextcloud"/"Talk". I denna interna genomgång namnger vi apparna (Collabora, libresign, sdkmc, securemail, groupfolders, files_retention, deck/tasks) för att kunna wire:a.
>
> **PRODUKTIONSANTAGANDE (gäller hela flödet):** Spreed har HPB + recording-server, Collabora/OnlyOffice är uppsatt on-prem (WOPI). Detta dokument beskriver **BÅDE** LibreSign-vägen (demo/internt lågrisk — vad den klarar) **OCH** Inera Underskriftstjänst-vägen (prod, AES med BankID — vad som krävs).
>
> **Datum:** 2026-06-14 · **Server:** v32 (Hub 25 Autumn) · **Persona:** `socialsekreterare`. Kompletterar genomgång 1–3 och `esign-todo-native.md`, `arendehantering-map.md`.

---

## Förutsättning för flödet (var vi börjar)

Utredningen (SoL 11 kap.) är klar i ärenderummet (Groupfolder per barn/dnr, ACL: handläggare skriver, gruppledare läser). Beslutet som ska fattas: **avslag på ansökan om insats enligt 4 kap. 1 § SoL** (ett överklagbart myndighetsbeslut — kräver alltså formell underrättelse, fullföljdshänvisning och delgivning, till skillnad från ett gynnande beslut). Detta är medvetet det "tunga" fallet: ett **bifall/lågrisk** hade i stället följt SKR:s riskmodell och bara "Godkänts" (loggat) utan formell underskrift — den varianten noteras vid steg 5.

⚠ ANTAGANDE: vem som *fattar* beslutet (delegat enligt nämndens delegationsordning — handläggare själv vid avslag på vissa insatser, annars 1:e socialsekreterare/utskott/nämnd) avgör vem som ska signera i steg 5–6. Jag antar genomgående **delegat = ansvarig handläggare själv** för avslaget. Är beslutsfattaren en annan, byter steg 6 till medsignerings-spegelvyn (`skickatForSignering`).

---

**Steg 1 — Ta fram beslutshandlingen (Collabora)**

- **Handläggaren:** öppnar ärenderummet via widget **`arenderum`** (kort → "Öppna ärenderum"), väljer beslutsmallen ur **`kunskapsbank`** (BBIC-/beslutsmall), och skriver beslutet on-prem i webbordbehandlaren. Fälten: sökande, beslut (avslag), motivering, tillämpat lagrum, **fullföljdshänvisning** (hur man överklagar, till förvaltningsrätten, inom 3 veckor), beslutsfattare/delegation.
- **I Hubs (mellanlagring):** dokumentet skapas/redigeras i Groupfolder-ärenderummet. App: `files` + `groupfolders` (+ `files_versions`), samredigering via **Collabora/OnlyOffice (WOPI)**. Route: `/apps/files/?dir=/{ärenderum}` → öppnas i Collabora-frame. Mall hämtas ur `collectives` (`/apps/collectives/{collective}/{page}`). Status på handlingen: **utkast** (ej signerad, ej allmän handling i diariemening än).
- **I facksystemet (slutlagring):** inget ännu — committas i steg 8–9. ⚠ Observera: Treservas inbyggda beslutsmotor/journalmall *kan* vara den plats där beslutet egentligen "borde" skrivas. Här produceras handlingen i Hubs och förs över efteråt — se LUCKA nedan.
- **Data:** ingen utgående riktning; sekretess **OSL 26 kap.** (socialtjänstsekretess), allt on-prem (ingen OSL 10:2a-bedömning, eSam ES2023-06). LOA på handläggarsessionen: **LOA3** (Freja/SITHS framför inloggningen). Gallring: utkast i ärenderummet styrs av Retention-tagg för handlingstypen; originalet gallras i Hubs **efter** bekräftad överföring (steg 8).
- ⚠ LUCKA: **Dubbel-författande-risken.** Skrivs beslutet i Collabora *och* måste finnas i Treserva-journalen uppstår två versioner. Det renaste vore att exportera beslutsutkastet *ur* Treserva (mönster A, facksystemet äger texten), signera i Hubs, och föra tillbaka den signerade PDF:en. Beskrivningen här (skriv i Collabora → för över) fungerar men förutsätter att handläggaren inte också skriver av beslutet i Treserva — annars divergerar texterna. Detta måste lösas i konnektordesignen (Treserva öppet API), inte av användardisciplin.

---

**Steg 2 — Lägg beslutet i signeringskön ("Skicka för underskrift")**

- **Handläggaren:** i `arenderum` (eller direkt i **`attSignera`**) klickar primäråtgärden **"Skicka beslut för underskrift"**. Väljer i dialogen: kravnivå-badge (**AES** för myndighetsbeslut), undertecknare (sig själv som delegat), och — för PDF — exportformat **PDF/A-1**.
- **I Hubs (mellanlagring):** Collabora-dokumentet exporteras/konverteras till **PDF (helst PDF/A-1)** och läggs i ärenderummet. En signeringsbegäran skapas i signeringsadaptern. Widget: **`attSignera`** (`ncAppId: libresign`, status `proposed-integration`).
  - **LibreSign-vägen (demo/internt):** `POST /ocs/v2.php/apps/libresign/api/v1/request-signature` (eller v1-motsvarande) skapar en request med fil + signatär(er) + ev. signeringsordning. Status blir **"Att signera"** för undertecknaren. Förutsätter `occ libresign:install` (Java/JSignPDF + cfssl **eller** OpenSSL-engine + pdftk) och en genererad **lokal rot-CA** (`occ libresign:configure:cfssl` / admin-vyn "Generate root certificate").
  - **Inera-vägen (prod):** Hubs signeringsadapter skapar i stället ett signeringsärende mot **Inera Underskriftstjänst API** med vald **signaturprofil** (AES). Kräver: **SITHS funktionscertifikat** (klientcert som bär aktörens **HSA-id**/orgnr) och anslutning via Ineras anvisade teknik. ⚠ Se LUCKA om mTLS vs OOB.
- **I facksystemet (slutlagring):** inget ännu — committas i steg 8.
- **Data:** internt i Hubs (ingen extern riktning i LibreSign-fallet). I Inera-fallet går **dokument-hash/dokument** ut till Inera Underskriftstjänst (nationell tjänst, inte tredjelands-moln) — provenance måste visa "signeras via nationell underskriftstjänst". Sekretess OSL 26 kap.; LOA3. Gallring: signeringsbegäran är transient i Hubs.
- ⚠ LUCKA / ANTAGANDE: **mTLS vs OOB.** Tidigare underlag (`esign-todo-native.md`) säger Inera API = "mTLS + SITHS funktionscert". Webbsökning 2026-06 mot Ineras anslutningsguide indikerar att **nya Underskriftstjänsten för SITHS eID kräver OOB (out-of-band), inte den äldre mTLS-tekniken**, medan **funktionscertifikat per integrerande verksamhetssystem fortfarande gäller**. ⚠ ANTAGANDE: BankID/Freja som signeringsmetod påverkas troligen inte av SITHS-OOB-bytet (det rör hur *verksamhetssystemet* autentiserar uppladdning), men exakt anslutningsprofil **måste verifieras mot aktuell Inera-anslutningsguide vid implementation** — detta är en konfigurationsdetalj, inte en arkitekturlucka.

---

**Steg 3 — Signera beslutet (undertecknarens action)**

- **Handläggaren (= delegat):** öppnar **`attSignera`** → ser kortet "Beslut – avslag insats, Barn 2026-0412" med deadline-badge. Klickar **"Signera"**.
  - **LibreSign-vägen:** öppnar signeringsvyn (`/apps/libresign/p/{uuid}`), ser PDF:en, placerar/bekräftar signatur, autentiserar med sin **identifieringsmetod**. För en *intern inloggad* användare är metoden `account` (Hubs-kontot) — bevisvärdet ärvs av att **inloggningen själv är LOA3** (Freja/SITHS framför Hubs). LibreSign signerar PDF:en kryptografiskt via **JSignPDF** → **PAdES-likt** sigill mot den **lokala rot-CA:n**.
  - **Inera-vägen:** signeringssteget startar **BankID/Freja eID Plus/SITHS eID** (LOA3). Användaren legitimerar sig och godkänner **signeringsmeddelandet**; Inera genererar nyckel + cert och returnerar en **PAdES**-signatur. Validering sker vid signeringen och dokumenteras i ett **valideringsintyg/valideringsrapport** som del av utförd underskrift.
- **I Hubs (mellanlagring):** signerad PDF skrivs tillbaka till ärenderummet (Groupfolder). LibreSign: status → **"Signerat"** (audit trail: vem, när, hur). Inera: signerad PAdES + valideringsintyg hämtas tillbaka av adaptern och läggs i rummet. Hubs konverterar/säkrar **PDF/A-1** och (prod) lägger på **LTV + kvalificerad tidsstämpel** så underskriften går att verifiera även efter cert-utgång.
- **I facksystemet (slutlagring):** inget ännu — committas i steg 8.
- **Data:** signerad handling skapas i Hubs; sekretess OSL 26 kap.; identitetsbevis (completionData) bevaras. Gallring: den signerade handlingen är nu en **allmän handling** → får inte gallras godtyckligt; bevaras tills överförd + därefter enligt dokumenthanteringsplan.
- ⚠ LUCKA: **LibreSign-AES ≠ svensk myndighets-AES.** LibreSigns starkaste *inbyggda* externa identitetsfaktor är SMS (NIST *restricted*, otillräckligt för LOA3) och dess trust-ankare är en **självsignerad lokal rot-CA** som ingen utomstående validerar mot en betrodd lista. För ett **internt** beslut där undertecknaren är en LOA3-inloggad handläggare *kan* LibreSign bära ett "Godkänn"/intern underskrift — men för ett **överklagbart avslagsbeslut** som ska kunna stå sig i förvaltningsrätt är LibreSign-vägen **inte tillräcklig**; produktion **kräver** Inera/Sweden Connect. Demo får visa kedjan men måste etikettera identiteten ärligt ("konto/SMS, ej BankID").

---

**Steg 4 — Bevarande-/valideringskontroll ("Giltig nu / Giltig då")**

- **Handläggaren:** ser i `attSignera`/`arenderum` en **bevarandepanel**: format (PAdES + PDF/A-1 ✓), LTV ✓, tidsstämpel ✓, "Verifiera underskrift nu". Klickar "Verifiera" för att se att sigillet håller och valideringsintyget finns.
- **I Hubs (mellanlagring):** panelen läser PDF-signaturens status + det sparade valideringsintyget. Detta är **mellanlagringens kvalitetsgrind** innan commit — det får inte gå en obevarbar handling vidare till arkiv. App-lager: signeringsadapter + `files`. (Detta är `forvaltare`-personans "Giltig nu/Giltig då"-vy återanvänd här.)
- **I facksystemet (slutlagring):** inget ännu.
- **Data:** ingen extern riktning; intern verifiering. Bevarandekrav: **Riksarkivet** — en handling är allmän oavsett om signaturen går att verifiera, men **bevisvärdet kräver att man arkiverar *beviset* om underskriften** (valideringsintyget + LTV). Gallring: valideringsintyget bevaras *tillsammans med* handlingen.
- ⚠ LUCKA: **LibreSign saknar robust LTV/kvalificerad tidsstämpel.** "Giltig då"-löftet (validerbar efter cert-utgång) gäller realistiskt bara **Inera-/Sweden Connect-vägen**. I LibreSign-demon blir panelen delvis grön men "Giltig då" kan inte garanteras — måste märkas så i UI, annars luras kommunjuristen.

---

**Steg 5 — (Avgränsning) Lågrisk-alternativet "Godkänn" vs "Signera"**

- **Handläggaren:** *om* beslutet hade varit lågrisk/gynnande (t.ex. "inleda utredning", bifall enkel insats) hade hon i stället klickat **"Godkänn"** (loggat) i `attSignera` — ingen formell underskrift, enligt **SKR:s vägledning Digitala underskrifter (riskmodell SES/AES/QES)**.
- **I Hubs (mellanlagring):** "Godkänn" registreras som loggad händelse (LibreSign account-baserad signering / aktivitetslogg) — räcker för internt lågrisk. Inget BankID krävs.
- **I facksystemet (slutlagring):** beslutet committas ändå i steg 8, men utan separat PAdES-sigill — godkännandeloggen är beviset.
- **Data:** intern logg; OSL 26 kap.; LOA3-session. Gallring: loggen bevaras med beslutet.
- ⚠ ANTAGANDE: gränsdragningen "vilket beslut kräver AES vs räcker Godkänn" är en **policyfråga** kommunjuristen/nämnden sätter (mappad mot SKR:s riskmodell), inte något Hubs avgör. Hubs ska **visa kravnivå-badge per beslutstyp** men inte gissa den. ⚠ LUCKA: denna mappning (beslutstyp → kravnivå) är inte definierad i underlaget och måste tas fram per kund.

---

**Steg 6 — (Vid annan beslutsfattare) Medsignering & spegelvy**

- **Handläggaren:** om delegationen kräver att **1:e socialsekreterare/utskott** signerar, skickar handläggaren beslutet till den personen och följer **`skickatForSignering`** (spegelvy): *Skickat → Öppnat → Signerat 1 av N → Klart*. "Påminn"-knapp finns.
- **I Hubs (mellanlagring):** samma signeringsadapter, multi-signatär. LibreSign stödjer **signeringsordning, roller, interna + externa signatärer i samma request** och **gäst-/publika signeringslänkar** (extern part får e-postlänk, signerar utan konto). Inera: flera signaturer i samma ärende per profil. App: `libresign` (+ `sdkmc` för säkert utskick av länk). Route: `/apps/libresign/`.
- **I facksystemet (slutlagring):** inget ännu — committas i steg 8 när alla signerat.
- **Data:** signeringslänk ut till intern (eller extern) signatär; sekretess OSL 26 kap.; för extern signatär krävs LOA3-metod (BankID/Freja via Inera), **inte** LibreSigns SMS. Gallring: spegelstatus transient.
- ⚠ LUCKA: **LibreSign gäst-signering = svag identitet.** Gästlänk + konto/SMS håller för intern medsignering men **inte** för en extern part i ett myndighetsbeslut — där måste medsigneringen gå via Inera-AES. (För *detta* flödes huvudfall, delegat = handläggaren själv, är steg 6 inte aktivt.)

---

**Steg 7 — Delge medborgaren säkert (med läskvittens)**

- **Handläggaren:** i `arenderum`/`attHantera` klickar **"Delge beslut"**. Väljer kanal och **delgivningssätt** (vanlig delgivning / förenklad delgivning / ev. digital brevlåda). Den signerade PDF/A:n bifogas.
  - **Säker e-post + BankID-länk (`securemail`)** till vårdnadshavaren (LOA3 vid öppning), **eller**
  - **SDK** org-till-org om mottagaren är en myndighet/ombud, **eller**
  - **Mina meddelanden / Kivra** (digital brevlåda) som utkanal på sikt.
- **I Hubs (mellanlagring):** utgående delgivning skapas i `sdkmc`/`securemail`; **`kvittenser`** (`/apps/sdkmc/` receipt-vy) följer tidslinjen: **Skickad → Levererad → Notis öppnad → Inloggad (LOA3) → Läst**. Detta **läskvittenset** är delgivningsbeviset. App-route: `/ocs/v2.php/apps/sdkmc/api/v1/...` (utskick) + receipt-data.
- **I facksystemet (slutlagring):** inget ännu — beslutet + delgivningsbeviset committas i steg 8 **efter** att delgivning bekräftats (eller markerats som förenklad delgivning med kontrollmeddelande).
- **Data:** **UTGÅENDE** (Hubs → medborgare/ombud). Innehåll: signerat avslagsbeslut + fullföljdshänvisning. Sekretess OSL 26 kap.; mottagaren legitimerar sig **LOA3** för att läsa (säker e-post-vägen). Gallring: delgivningskvittensen bevaras med handlingen; SDK-logg 12 mån.
- ⚠ LUCKA: **Hubs läskvittens ≠ juridisk delgivning per automatik.** En teknisk "Läst"-notis är *bevisning* om mottagande, men formell delgivning enligt **delgivningslagen (2010:1932)** har egna former:
  - **Vanlig delgivning:** mottagaren bekräftar mottagande (delgivningskvitto) — Hubs "Läst"-kvittens kan *stödja* detta men ersätter inte ett underskrivet delgivningskvitto utan att en jurist accepterar likställigheten.
  - **Förenklad delgivning:** handlingen skickas + ett **kontrollmeddelande** närmast därpå; mottagaren anses delgiven **två veckor** efter avsändandet — kräver att parten i förväg upplysts om att förenklad delgivning kan komma att användas. Detta är ett **tidsstyrt** flöde Hubs måste modellera (skicka handling, schemalägg kontrollmeddelande, starta 2-veckorsfiktion), inte bara en läsnotis.
  - **Digital brevlåda (Mina meddelanden/Kivra):** användbart för utskick men **villkoren/begränsningarna för delgivning** via Mina meddelanden styrs av kompetenscentret Digital Rättssäkerhet/SOU 2024:47 — ⚠ ANTAGANDE: får inte antas vara formell delgivning utan att det rättsläget verifieras per beslutstyp.
  ⚠ Sammantaget: Hubs ska **erbjuda och spåra** delgivningssättet men **inte** påstå att en läsnotis i sig fullbordar delgivning. Vilket delgivningssätt som gäller är ett juridiskt val per ärende.

---

**Steg 8 — Sätt överklagandefrist som bevakning**

- **Handläggaren:** när delgivning skett (eller 2-veckorsfiktionen vid förenklad delgivning) skapar Hubs automatiskt en **bevakning** "Överklagandefrist – Barn 2026-0412 löper ut {datum}". Hon ser den i **`bevakningar`**.
- **I Hubs (mellanlagring):** bevakning skapas i **`deck`** (delad board) + **`tasks`/VTODO** (personlig påminnelse), med Hubs egen **påminnelse-före-deadline (T-7/T-3/T-0) bara till tilldelad** (täcker Deck #1549/#566). Fristen = **3 veckor** från delgivningsdatum (FL 44 §). Route: `/apps/deck/board/{boardId}`.
- **I facksystemet (slutlagring):** ⚠ den **formella** fristbevakningen bor i **Treserva/Lifecare** (som rödmarkerar passerade bevakningsdatum och kan varna X dagar före). Hubs-bevakningen **dubblerar inte** — den bevakar det säkra flödet runt om tills aktiviteten committas; sedan äger facksystemet fristen. Mönster **D** (Hubs-kort gallras/länkas efter commit).
- **Data:** intern bevakning, ärendereferens (inte klartextcitat — GDPR-dataminimering). Sekretess OSL 26 kap. Gallring: Hubs-kortet gallras som personlig notering eller länkas till ärendet.
- ⚠ LUCKA: **fristens startpunkt beror på delgivningssättet** (steg 7). Vid förenklad delgivning startar 3-veckorsfristen efter 2-veckorsfiktionen; vid vanlig delgivning vid bekräftat mottagande. Hubs måste härleda startdatum **från valt delgivningssätt**, annars blir fristen fel. Detta kopplar steg 7 ↔ 8 hårt.

---

**Steg 9 — Committa signerad handling + delgivningsbevis till Treserva**

- **Handläggaren:** klickar **"För över till Treserva"** (destinations-chip i `arenderum`/`attHantera`: "→ Treserva — ej registrerad" → efter commit "Förd till Treserva, dnr 2026-IFO-1234").
- **I Hubs (mellanlagring):** den signerade **PAdES/PDF/A-1**-handlingen + **valideringsintyget** + **delgivningskvittensen** paketeras och förs över.
  - **Mönster A (API):** tunn Treserva-konnektor (Treservas öppna API / Ena REST-profil) skapar/uppdaterar akten med beslutet som bilaga + journalanteckning + status. Storkund.
  - **Mönster B (drag-to-case):** förifyllt registreringsobjekt → POST till facksystemet (eller exportfil det sväljer).
  - **Mönster D (manuell, dag 1):** handläggaren laddar ner PDF/A + kvittens och laddar upp i Treserva för hand; Hubs loggar **"Markera som överförd"**.
- **I facksystemet (slutlagring):** **Treserva-akten** får nu det rättsliga beslutet, den bevarade signerade handlingen, valideringsintyget, delgivningsbeviset och journalanteckningen. **Detta är system of record-ögonblicket.**
- **Data:** **UTGÅENDE** Hubs → Treserva (internt, on-prem). Sekretess OSL 26 kap.; identitets-/leveransbevis följer med. Gallring: **efter bekräftad överföring** får Hubs-ärenderummets kopia en rensnings-countdown (Retention) — "Rensas ur Hubs 30 dgr efter överföring".
- ⚠ LUCKA: **mönster A mot Treserva är inte byggt än** (status `proposed-integration`). Dag 1 är detta **mönster D (manuell)** — vilket fungerar men bryter automatikkedjan och introducerar handhavande-fel (fel akt, glömd kvittens). "Hubs-kopian gallras efter bekräftad överföring" förutsätter att överföringen **bekräftas** — vid manuell D är "bekräftat" bara en kryssruta handläggaren klickar, inte ett API-svar. ⚠ ANTAGANDE: gallring sker först efter att den allmänna handlingen bevisligen finns i facksystemet; en felaktig gallring av enda kopian av en allmän handling är ett arkiv-/offentlighetsbrott (arkivlagen 1990:782).

---

**Steg 10 — Arkivera till e-arkiv (FGS) vid ärendeavslut**

- **Handläggaren / registrator / förvaltare:** vid ärendeavslut (eller enligt dokumenthanteringsplanens bevarandekrav) ska de bevarandepliktiga handlingarna nå **slutarkivet**.
- **I Hubs (mellanlagring):** *normalt äger Treserva* vägen vidare till e-arkiv. Men där Hubs-ärenderummet **självt** innehåller bevarandepliktiga original (t.ex. om något bara finns i rummet) paketeras de enligt **FGS Paketstruktur 2.0 (E-ARK CSIP/SIP)** + **FGS Ärendehantering 2.0 (CITS ERMS)** via `arkivGallring` (`files_retention` + FGS-byggare) och levereras till **Sydarkivera/e-arkiv**. Mönster **C**.
- **I facksystemet (slutlagring):** Treserva → e-arkiv är **facksystemets** ansvar (Partille-exemplet: Treserva→e-arkiv, 100 % digital ärendeprocess). Hubs/diariet är *mellanarkiv*, e-arkivet *slutarkiv*.
- **Data:** **UTGÅENDE** (Hubs/Treserva → e-arkiv). Sekretessmarkering följer med i FGS-metadata. Gallring: efter bekräftad FGS-leverans gallras Hubs mellanlagrade kopia.
- ⚠ LUCKA: **ansvarsgränsen Treserva→e-arkiv vs Hubs→e-arkiv är inte skarp i detta flöde.** För det normala avslagsbeslutet (committat till Treserva i steg 9) bör e-arkiveringen ske *via Treserva*, och Hubs FGS-export (mönster C) blir då **inte** den primära vägen — den är reserv för det som bara bor i Hubs. ⚠ ANTAGANDE: kommunens dokumenthanteringsplan + Treservas e-arkiv-koppling avgör; Hubs ska inte FGS-paketera handlingar som redan arkiveras via facksystemet (dubbelarkivering).

---

## Systemöversikt för detta flöde

| Steg | Hubs-app (NC-app-id) | Facksystem / slutlagring | Handoff |
|---|---|---|---|
| 1 Ta fram beslut | `files`/`groupfolders` + Collabora (WOPI) + `collectives` (mall) | — (committas steg 9) | — |
| 2 Skicka för underskrift | `attSignera` → `libresign` / **Inera API** | — | — |
| 3 Signera | `libresign` (PAdES, lokal rot) / **Inera** (BankID/Freja/SITHS, PAdES) | — | — |
| 4 Validera/bevara | signeringsadapter + `files` ("Giltig nu/då") | — | — |
| 5 (Lågrisk) Godkänn | `attSignera` (loggad) / `activity` | — | — |
| 6 (Ev.) Medsignering | `skickatForSignering` → `libresign`/Inera (+ `sdkmc`) | — | — |
| 7 Delge medborgaren | `securemail`/`sdkmc` + `kvittenser` (receipt) | — (committas steg 9) | — |
| 8 Sätt överklagandefrist | `bevakningar` → `deck` + `tasks` | Treserva/Lifecare äger formell frist | **D** |
| 9 Committa till facksystem | destinations-chip i `arenderum`/`attHantera` | **Treserva** (akt, beslut, journal, bevis) | **A** / B / **D** |
| 10 Arkivera (FGS) | `arkivGallring` → `files_retention` + FGS-byggare | **e-arkiv** (Sydarkivera) / via Treserva | **C** |

**Modellmening:** *Hubs stagar beslutshandlingen, e-signeringen, den säkra delgivningen och leveranskvittensen → handläggaren för över den signerade PAdES/PDF/A + valideringsintyg + delgivningsbevis till Treserva-akten (system of record), och Hubs-kopian gallras efter bekräftad överföring; bevarandepliktiga original når e-arkivet via FGS.*

---

## Identifierade luckor

1. **Dubbel-författande (steg 1):** beslut skrivet i Collabora *och* i Treserva-journalen ger två divergerande versioner. Renast: exportera utkast ur Treserva (mönster A), signera i Hubs, för tillbaka. Måste lösas i konnektordesign, inte av användardisciplin.
2. **Inera anslutningsprofil — mTLS vs OOB (steg 2):** tidigare underlag säger mTLS; aktuell Inera-guide indikerar **OOB krävs för SITHS eID** (mTLS utfasad) medan funktionscertifikat per system kvarstår. Exakt profil + om BankID/Freja-signering påverkas **måste verifieras mot aktuell anslutningsguide** vid implementation.
3. **LibreSign-AES ≠ svensk myndighets-AES (steg 3):** lokal självsignerad rot-CA + svagaste externa faktor SMS. Håller för internt "Godkänn", **inte** för överklagbart avslagsbeslut. Produktion kräver **Inera/Sweden Connect**; demo måste etikettera identiteten ärligt.
4. **LTV/tidsstämpel saknas i LibreSign (steg 4):** "Giltig då" (validerbar efter cert-utgång) går realistiskt bara via Inera/Sweden Connect. LibreSign-demons bevarandepanel får inte påstå LTV.
5. **Kravnivå-mappning per beslutstyp odefinierad (steg 5):** vilket beslut kräver AES vs räcker "Godkänn" är en policy-/juristfråga (SKR:s riskmodell) som inte är specificerad i underlaget — måste tas fram per kund. Hubs visar badge, gissar inte.
6. **Svag gäst-/extern-identitet i LibreSign (steg 6):** gästlänk + konto/SMS räcker för intern medsignering men inte för extern part i myndighetsbeslut — där krävs Inera-AES.
7. **Läskvittens ≠ juridisk delgivning (steg 7):** en teknisk "Läst"-notis är bevisning, inte automatiskt fullbordad delgivning per delgivningslagen (2010:1932). Vanlig delgivning kräver delgivningskvitto; förenklad delgivning kräver kontrollmeddelande + 2-veckorsfiktion + förhandsupplysning; Mina meddelanden/Kivra-delgivningens rättsläge (SOU 2024:47/Digital Rättssäkerhet) måste verifieras. Hubs ska spåra delgivningssätt, inte påstå delgivning.
8. **Fristens startpunkt kopplad till delgivningssätt (steg 8):** 3-veckorsfristen (FL 44 §) startar olika vid vanlig vs förenklad delgivning. Hubs måste härleda startdatum ur valt delgivningssätt (steg 7↔8 hårt kopplade), annars blir fristen fel.
9. **Treserva mönster A inte byggt (steg 9):** dag 1 är överföringen **manuell (mönster D)** — bryter automatik, inför handhavandefel (fel akt, glömd kvittens), och "bekräftad överföring" är en kryssruta, inte ett API-svar. Gallring av enda kopian av en allmän handling före verifierad commit är ett arkiv-/offentlighetsbrott (arkivlagen 1990:782).
10. **FGS-ansvarsgräns oskarp (steg 10):** för beslut som committats till Treserva bör e-arkivering ske *via Treserva*, inte via Hubs FGS-export — annars dubbelarkivering. Hubs FGS (mönster C) är reserv för det som bara bor i Hubs; gränsen avgörs av dokumenthanteringsplan + Treservas e-arkivkoppling.
11. **Delegationsordning antagen (förutsättning):** vem som *får* fatta/signera avslaget (delegat) är antaget = handläggaren själv. Verklig delegationsordning avgör om steg 6 (medsignering) aktiveras och vem som legitimerar sig.

---

*Grundas i `persona-usage-socialsekreterare.md`, `persona-socialsekreterare.md`, `arendehantering-map.md`, `esign-todo-native.md`, `middleware-architecture.md`, `transcription-ai.md`, `native-apps-map.md`, `widgetApps.js`, `WIDGET-APP-MAP.md`. Svenska specifika frister/regler verifierade via webbsökning 2026-06: FL (2017:900) 44 § (överklagande 3 v), delgivningslag (2010:1932) förenklad delgivning (kontrollmeddelande + 2-veckorsfiktion), Inera Underskriftstjänst (SITHS eID/BankID/Freja, PAdES + PDF/A-1, funktionscert, OOB), LibreSign (PAdES-likt, lokal rot-CA, multi-signatär, gästlänk, audit trail), Mina meddelanden/Kivra (digital brevlåda, e-leg-inloggning).*
