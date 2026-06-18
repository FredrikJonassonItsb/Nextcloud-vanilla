<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Persona-användningsmönster — dag-i-livet + dataflöde per persona

> **Vad detta är:** en syntes av de sex `persona-usage-*.md`-underlagen till ett demo-/UX-/
> integrationsdokument. Per persona: en kort dag-i-livet, widget-öppningsordningen, och — det
> bärande — **mellanlagring → slutlagring-handoffen gjord explicit**: varifrån datan kommer, var den
> mellanlagras i Hubs, och i vilket facksystem den committas.
>
> **Bärande modell (genom hela dokumentet):** Hubs är **mellanlagringen** (middleware/staging) — den
> sekretesssäkra, on-prem-ytan där säker kommunikation, signering, möten och filer tas emot, handläggs
> och bearbetas. **Slutlagringen (system of record) är alltid verksamhetens ärendehanteringssystem.**
> Livscykeln är genomgående: **mottag → handlägg → för över till facksystemet → gallra i Hubs.**
>
> **Brand-regel:** i produkt-/UI-text säger vi aldrig "Nextcloud" eller "Talk". Här namnges app-id
> (sdkmc, spreed-itsl, groupfolders, deck/tasks, tables, libresign, …) för wiring.
> Datum: 2026-06-13 · Plattform: server v32 (Hub 25 Autumn).

---

## Den gemensamma livscykeln (alla personor är varianter av samma flöde)

```
  EXTERN PART                HUBS = MELLANLAGRING (staging, on-prem)              FACKSYSTEM = SLUTLAGRING
  (medborgare, region,   ┌───────────────────────────────────────────────┐      (system of record)
   bank, skola, FK …)    │  1 MOTTAG     2 HANDLÄGG      3 BEARBETA         │
        │                │  säker kanal  triage/frist    möte · signering   │      Treserva / Lifecare
   BankID/Freja/SITHS    │  sdkmc        Deck/Tasks      spreed-itsl        │      Viva / Combine / Cosmic
        │     ───────►   │  securemail   Tables-status   LibreSign/Inera    │      Provisum / Aider
   SDK (AS4) · säker     │  mail/fax     ärenderum       Forms/samtycke     │ ───► W3D3 / Public360° / Platina
   e-post · digital fax  │  (Groupfolders + ACL + Retention)                │      Adato / Personec / MCF
        ◄───────         │            4 FÖR ÖVER ───────────────────────────┼────► + e-arkiv (Sydarkivera, FGS)
   kvittens · delgivning │            5 GALLRA i Hubs (Retention, restricted-tagg)
                         └───────────────────────────────────────────────┘
```

**Provenance-bandet (per rad, i varje ärende-widget) bär tre koordinater:**
1. **Härkomst** — kanalikon + verifierad identitet (*SDK · BankID LOA3*, *Säker e-post*, *Digital fax*, *Forms*).
2. **Tillstånd nu** — *Hubs · mellanlagring* + GOV.UK-status (Ny/Påbörjad/Väntar/Klar) + `dataSuveranitet`-markör.
3. **Slutdestination** — målfacksystem + överföringsstatus (*ej registrerad* → *Förd till Treserva 2026-06-12*) + Hubs rensningscountdown.

**De fyra handoff-mönstren:** **A** = API/REST (Ena REST-profil) · **B** = drag-to-case (registrera i diariet) · **C** = FGS-export till e-arkiv · **D** = manuell (dag-1-fallback). *Designprincip: destinationen modelleras alltid; leveransläget kan vara manuellt först.*

---

## 1. `socialsekreterare` — barn & familj

**System of record:** Treserva / Lifecare / Viva / Combine (socialakten/BBIC-journalen).

**Dag-i-livet (komprimerad):** Anna loggar in med Freja eID Plus (LOA3) och möter `attHantera` — inte en mejlinkorg. Hon **plockar** en ny skol-orosanmälan ur `funktionsbrevlador` (`orosanmalan@`) → en **14-dagars förhandsbedömnings-countdown** startar. Hon öppnar den i `orosanmalningar`, **skapar ärenderum** (Groupfolder + ACL + gallringstagg), dokumenterar via BBIC-mall ur `kunskapsbank`. Säkra svar till BUP/vårdnadshavare via `attHantera`; på en komplettering klickar hon **Skapa bevakning från meddelande**. SIP-möte i `dagensMoten` med BankID-lobby; samtycke via Forms. Beslut: lågrisk **Godkänns** (loggat), utredningsbeslut **skickas för underskrift** (AES) i `attSignera`. Delgivning följs i `kvittenser`. Vid klarmarkering väljer hon **"gallra (personlig notering)"** eller **"för till ärendet"**.

**Widget-öppningsordning:** `attHantera` → `funktionsbrevlador` → `orosanmalningar`/`arenderum` → `attHantera` (utgående) → `bevakningar`/`minaUppgifter`/`todolista` → `dagensMoten` → `attSignera`/`skickatForSignering` → `kvittenser` → `bevakningar`/`senasteFiler`.

**Middleware → case-system-handoff:**
- **Orosanmälan → förhandsbedömning → beslut.** *In:* skola via SDK (BankID/SITHS-verifierad). *Mellanlagring:* förhandsbedömning, ärenderum, dokumentation, `bevakningar`-countdown. *Handoff:* aktualisering/beslut **registreras i Treserva** (mönster B; A via Treservas öppna API hos storkund); "inte inleda" → gallras. *Provenance:* "Orosanmälan inkom via SDK 2026-06-10 · ej registrerad" → "Registrerad i Treserva, dnr 2026-IFO-1234 · Hubs-rum gallras 2026-09".
- **Utredning (BBIC) → SIP → signerat beslut → delgivning.** Beslut **skickas för underskrift** (AES/BankID → PAdES/PDF/A med LTV); utredning + beslut + journal **förs över till Treserva/Lifecare**; mötesanteckning (godkänd) committas, rå-artefakter gallras.
- **Todolista (nytt).** `todolista` (Deck-board per barn/dnr) fångar **inflödet innan det blir ett ärende**; den formella bevakningen/journalen committas i Treserva/Lifecare (som rödmarkerar passerade bevakningar). Hubs dubblerar inte — den **stänger gapet inkorg↔facksystem**.

---

## 2. `registrator` — registrator / nämndsekreterare

**System of record:** W3D3 / Public 360° / Ciceron / Platina / Evolution / LEX (diariet) → e-arkiv (Sydarkivera, FGS).

**Dag-i-livet (komprimerad):** Anna loggar in (BankID, LOA3); `dataSuveranitet`-raden överst. `attHantera`-räknaren: *"19 oregistrerade · 3 med svarsfrist idag · 1 felskickad"*. Hon **batch-registrerar** i `registreraFordela` — förifyllt formulär (avsändare/datum ur metadata, föreslaget dnr, ärendemening, sekretess) → fördelar. Den felskickade **vidarefördelas internt** (loggat) i stället för att bli en felskickad fax. Utlämnande till journalist i `utlamnande` ("skyndsamt"-timer, sekretessprövning, maskering). `namndcykel` visar dagordningen; två ärenden saknar underlag (rött). Efter sammanträdet: `justeringAnslag` — ordförande + justerare **signerar med BankID** på distans → **anslås** → laga-kraft-nedräkning (21 dgr) → expediering med delgivningskvittens. `arkivGallring` förbereder **FGS-leverans till Sydarkivera**.

**Widget-öppningsordning:** `attHantera` (hemmabas) → `registreraFordela` (dagens mest använda) → `funktionsbrevlador` → `utlamnande` (reaktivt) → `namndcykel` → `justeringAnslag` → `bevakningar` → `kvittenser` (reflexmässigt) → `arkivGallring` → `nytta`.

**Middleware → case-system-handoff:**
- **Ta emot → registrera → fördela → bevaka (massflödet).** *In:* SDK/säker e-post/fax. *Mellanlagring:* `registreraFordela` Card View → förifyllt formulär. *Handoff:* registreringen committas i **W3D3/Public 360°/Ciceron/Platina** (mönster B; A hos storkund; D dag 1). *Krav:* OSL 5:1 — registrering senast nästa arbetsdag; räknaren "oregistrerat >1 arbetsdag" är KPI:n.
- **Nämndkallelse → justering → anslag → expediering.** Det justerade protokollet (allmän handling) → **diariet**; vid laga kraft → `arkivGallring` → **FGS-export** (mönster C). Helt digitala sammanträden möjliga från 1 juli 2026 (Prop. 2025/26:164) via det säkra mötesrummet.
- **Utlämnande (omvänt flöde).** Här *läser* Hubs ur slutlagringen (diariet) och stagar den säkra, loggade leveransen — originalet bor redan i diariet.
- **Avsluta → FGS → e-arkiv.** Bevaras-handlingar paketeras enligt **FGS Paketstruktur 2.0** → **Sydarkivera**; Hubs-kopian gallras *efter* bekräftad överföring.
- **Mötesanteckningar (nytt).** Nämndberedning är **minst sekretesskänslig** → idealt **bästa första skarpa körningen** av `motesanteckningar` (recording → KB-Whisper → llm2-utkast → godkänn → spara till diariet/protokoll).

---

## 3. `hsl_skoterska` — kommunsjuksköterska (HSL)

**System of record (tre lager):** Lifecare SP (planering) · Treserva HSL / Lifecare VoO / Combine / Viva (kommunjournal) · regionens avvikelsesystem (RLDatix/Platina).

**Dag-i-livet (komprimerad):** Anna loggar in med SITHS eID (LOA3). `attHantera`: 7 inkommande (SDK från region, säker e-post, fax med läkemedelslista). I `utskrivningsbevakning` ligger **Karin J. · Utskrivningsklar · dygn 0 av 3 · grön** — hon trycker **Kvittera utskrivningsmeddelande** (region får leveranskvittens). **Sven A. · dygn 3 av 3 · gul** → måste hem idag. Hon **skapar bevakningar** så inget tappas över skift. En fax med ofullständig läkemedelslista → **Skapa samverkansavvikelse** i ett klick (~40 s mot 10–15 min). SIP-möte i `dagensMoten` med BankID-lobby för anhörig; SIP-samtycke via `mallarSamtycke`. `arenderum` visar **dubbel countdown**.

**Widget-öppningsordning:** `attHantera` ↔ `utskrivningsbevakning` (axeln hela dagen) → `bevakningar` → `attHantera` (utgående) → `samverkansavvikelser` → `dagensMoten` → `funktionsbrevlador` (`svpl@`) → `arenderum`/`senasteFiler` → `kvittenser` → `bevakningar`/`minaUppgifter` → `kunskapsbank`.

**Middleware → case-system-handoff:**
- **Utskrivningsklar → kvittens → hemtagning → journal.** *In:* underrättelse om utskrivningsklar (Lifecare SP-kopia/SDK). *Mellanlagring:* dygnsräknare mot betalningsansvar (lag 2017:612; belopp HSLF-FS 2025:74), kvittens. *Handoff:* journalanteckning **förs in i Treserva HSL** (D/A); planeringsstatus matas i **Lifecare SP** (separat SITHS-inloggning). Hubs blir inte ett tredje journalsystem. *Kr-värdet:* ett sent hanterat meddelande = överskjutande dygn × dygnsbeloppet = femsiffrigt per patient.
- **Informationsbrist → samverkansavvikelse.** Förifylld avvikelse → **säkert via SDK** till regionens avvikelsefunktion (A/B) → slutlagras i **avvikelsesystemet**; MAS följer trend (PSL 2010:659).
- **SIP → säkert videomöte → samtycke → plan.** Den **formella SIP-planen committas i Lifecare SP/Cosmic**; kommunal HSL-anteckning → Treserva HSL. Hubs löser Skype-ersättnings-gapet.
- **Mötesanteckningar (nytt).** SIP-/vårdplaneringssamtal — **demobart på internt processmöte; skarpt på klientsamtal villkorat** av IMY/SKR/Socialstyrelsen.

---

## 4. `hr_chef` — HR / chef (rehab & känsliga personalärenden)

**System of record:** Adato (rehab-akten) · Personec / Visma HR / Heroma (PA-/lönehändelse).

**Dag-i-livet (komprimerad):** Maria öppnar sin **avskilda HR-vy** (`kansligInkorg`): läkarintyg, FK-kallelse (SDK), företagshälsovård, facklig begäran — inget i Outlook. `fristStrip` varnar i gult: Erik passerar **dag 30** om 4 dagar → **plan för återgång** måste upprättas. Rehabmöte i `dagensMoten` med BankID-lobby; `recording_consent` påtvingat; KB-Whisper-transkript + AI-utkast hon **måste godkänna**. Två signeringsärenden i `attSignera` (anställningsavtal + rehaböverenskommelse). Samtyckesblankett via `mallarSamtycke` (Forms + BankID) ersätter "samtycke per post". Vid dagsslut **för hon över** signerad rehabplan + valideringsintyg till **Adato** ("Överförd till Adato" loggas).

**Widget-öppningsordning:** `kansligInkorg` → `fristStrip` → `bevakningar`/`todolista` → `dagensMoten` → `attSignera`/`skickatForSignering` → `rehabarenden` → `mallarSamtycke` → `bevakningar`/`kvittenser` (överför) → `nytta`.

**Middleware → case-system-handoff:**
- **Läkarintyg → plan för återgång (dag 30) → följ upp → överför till Adato.** *In:* läkarintyg via säker kanal (sjukfrånvarosignal kom från Personec→Adato). *Mellanlagring:* `kansligInkorg`, bevakning, rehab-rum, plan (FK 7459) samredigerad on-prem. *Handoff:* signerad plan + valideringsintyg **förs över till Adato** (D; A om Adato-API); plan delges FK via säker kanal. *Provenance:* "Rehab startad (signal från Personec) · plan ska förvaras i Adato" → "Plan signerad · överförd till Adato".
- **Rehab-/avstämningsmöte → AI-utkast → godkänn → committa.** Den **godkända** mötesanteckningen committas till **Adato**; rå-inspelning + rå-transkript gallras (transient).
- **Anställningsavtal → signera → committa till Personec.** Signerat avtal (PAdES → PDF/A + valideringsintyg) **journalförs i Personec/Visma/Heroma**; "Verifiera underskrift nu/då"-panel ger LTV-bevisbarhet.

---

## 5. `overformyndare` — överförmyndarhandläggare

**System of record:** Provisum (Sambruk/Flowfactory) / Aider (+ Mitt Wärna-inrapportering → nationellt register 2028).

**Dag-i-livet (komprimerad, torsdag 19 feb 2026 — mot 1 mars):** Lena loggar in (BankID, LOA3). `arsrakningar`-kampanjremsan: *"312 av 540 granskade · 10 dagar till 1 mars · 47 saknar verifikat"*. Hon triagerar `funktionsbrevlador` (`overformyndare@`), plockar tre kompletteringssvar. Granskningspass i `granskningsko` → `arenderum` med **årsräkning + verifikat side-by-side**; JO-rimlighetskontroll. Tre utfall: u.a. → arvodesbeslut till `attSignera`; saknar verifikat → **Begär komplettering** (auto-bevakning); orimlig post → anmärkning. `uppdragskontroll` flaggar en god man med ovanligt många uppdrag (JO dec 2025). Säkert möte om bostadsförsäljning i `dagensMoten`. Kl 13:30 — **mellanlagrings-brytpunkten** — **för hon över** morgonens utfall till **Provisum** ("Förd till Provisum 2026-02-19", rensningscountdown startar).

**Widget-öppningsordning:** `arsrakningar`/`bevakningar` (orientering) → `funktionsbrevlador`/`attHantera` → `skickatForSignering`/`arenderum` → `granskningsko` → `attSignera` → `attHantera`/`kvittenser` (delge + bank via SDK) → **`granskningsko`/`arenderum` (för över till Provisum)** → `uppdragskontroll` → `skickatForSignering` (påminn).

**Middleware → case-system-handoff:**
- **Granska årsräkning → komplettering → arvodesbeslut → delgivning.** *In:* Mitt Wärna (→ Provisum) eller papper (skannas). *Mellanlagring:* granskning, komplettering, arvodesbeslut. *Handoff:* granskningsresultat + arvodesbeslut **förs över till Provisum** (D dag 1; A/B storkund). *Krav:* FB 14:15 (årsräkning före 1 mars).
- **Uttag från spärrat konto → beslut → e-underskrift → besked till bank.** Beslut + bankkvittens **förs över till Provisum**; besked till banken **org-till-org via SDK** ersätter fax/post.
- **Nytt godmanskap i samförstånd (reform 1 juli 2026).** Beslutet flyttas tingsrätt→överförmyndare → mer myndighetsutövning; uppgifter **förs till Provisum** → nationellt register (2028).
- **Läsintegration mot Provisum/Aider (högsta värdet).** `arsrakningar`/`granskningsko` är bara trovärdiga om "312 av 540" **speglar Provisum på riktigt** — bygg tunn läskonnektor (mönster A / Tables-spegling). Hubs läser status, äger den inte.

---

## 6. `forvaltare` — förvaltare / IT / informationssäkerhet

**System of record (tredelad och ovanlig):** MCF/PTS (incidentanmälan, cybersäkerhetslagen 2025:1506) · kommunens SIEM/loggsystem · e-arkiv via Sydarkivera/FGS. *Förvaltaren committar inte ärendeutfall — hen committar incidentrapporter till MCF, loggexporter till SIEM, arkivpaket till e-arkivet, och bevakar att de andra personornas överföringar faktiskt skett.*

**Dag-i-livet (komprimerad, fredag 2026-06-12):** Anna legitimerar sig (SITHS, LOA3 → matar `authLoa`). Mental ordning: **"Är vi säkra nu? Kan vi bevisa att vi följer lagen? Är det värt pengarna?"** `complianceStatus` är gul (ledningsgenomgång daterad >12 mån). `sakerhetshandelser`: en auth-spik (14 misslyckade mot `orosanmalan@` i natt, inget LOA3-genombrott) + en avvikande extern delning (handläggare → privat Gmail). Lokal `llm2` flaggar transparent ("varför"). Hon bedömer: **ingen betydande incident**, återkallar delningen, loggar. (Om-värre: **Eskalera till incident** → **24 h-MCF-klockan startar**, generator förfylls.) `provisionering`: ny socialsekreterare in i `orosanmalan@` (LOA3, SMS-OTP spärrad), avslutad medarbetare avetableras samma dag. `arkivGallring`: tre nämndärenden → **FGS-export till Sydarkivera**. `loggSparbarhet`: DSO-begäran → sök mot **AS4 Message ID** (utan innehåll) → PDF + maskinell export till SIEM. `nytta`: paketerar cybermiljards-äskandet.

**Widget-öppningsordning (kärna uppifrån-ned varje morgon, side uppgiftsdrivet):** `complianceStatus` → `incidentrapporter` → `sakerhetshandelser` (→ ev. eskalering) → `provisionering` → `authLoa` → `arkivGallring` → `loggSparbarhet` → `complianceStatus` (åter) → `nytta`/`dataSuveranitet`.

**Middleware → case-system-handoff:**
- **Upptäck säkerhetshändelse → eskalera → rapportera till MCF i tid.** *In:* `sakerhetshandelser` (sdkmc auth/delning/routing + activity). *Handoff:* tidig varning ≤24 h → anmälan ≤72 h → läges-/slutrapport ≤1 mån **förs över till MCF/PTS** (D via IRON/blankett nu; A på sikt); slutrapporten FGS-exporteras till e-arkiv.
- **Provisionera → sätt åtkomst → avetablera.** Auktoritativ identitet bor i **HR-systemet/IAM** (Hubs speglar); åtkomstlivscykeln **slutlagras i åtkomstloggen → SIEM**.
- **Tillsyn/DSO → loggsök → exportera → gallra.** Bevarandepliktigt → **e-arkiv (FGS)**; loggunderlag → **SIEM**; SDK-loggen själv 12 mån (transient).
- **Är det värt pengarna?** `nytta` + `complianceStatus` + `dataSuveranitet` → **kommunstyrelse/nämnd + cybermiljards-äskande** (200 mkr/år kommuner, 50 mkr/år regioner 2026–2028).

---

## Sammanfattande matris (persona → källa → Hubs → slutlagring → mönster)

| Persona | System of record (slutlagring) | Källa (varifrån) | Primärt Hubs-utfall som committas | Handoff |
|---|---|---|---|---|
| `socialsekreterare` | Treserva / Lifecare / Viva / Combine (socialakt/BBIC) | Orosanmälan (skola/vård/polis/privat), klientdialog | Förhandsbedömning, beslut (PDF/A), delgivning, godkänd mötesanteckning | B/A + D |
| `registrator` | W3D3 / Public 360° / Ciceron / Platina / Evolution / LEX → e-arkiv | Allt inkommande till funktionsadresser | Registrerad post, justerat protokoll, anslag, FGS-paket | **B** + **C** |
| `hsl_skoterska` | Lifecare SP + Treserva HSL/Cosmic + avvikelsesystem | Utskrivningsflöde + tvärkanaligt (SDK/fax) | Kvittens, journalanteckning, samverkansavvikelse, SIP-plan | A/D + A/B |
| `hr_chef` | Adato (rehab) + Personec/Heroma/Visma (PA) | Sjukfrånvarosignal, läkarintyg, FK | Plan för återgång (signerad), avtal, godkänd anteckning | D (→Adato), A om API |
| `overformyndare` | Provisum / Aider (+ Mitt Wärna → nat. register 2028) | Mitt Wärna-årsräkning, verifikat | Granskningsbeslut (signerat), komplettering, tillsyn | A/B/D |
| `forvaltare` | MCF (incident) + SIEM (logg) + e-arkiv (FGS) | sdkmc-feed, auth/logg | Incidentanmälan, FGS-paket, loggexport, valideringsbevis | D/A (MCF) + C (FGS) |

**Genomgående engångsmening för demon:** *"Hubs är kommunens säkra mellanlagring — där det känsliga
tas emot, handläggs och signeras utan att lämna er server — och så för ni över det bestående till ert
ärendesystem, som förblir sanningen. Inte system nummer åtta. Limmet som saknades."*
