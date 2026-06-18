<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Persona-usage: HR / chef (rehab & känsliga personalärenden) — en verklig arbetsdag

> **Persona-id:** `hr_chef` · **Datum:** 2026-06-13 · **Plattform:** server v32 (Hub 25 Autumn)
>
> **Arkitektonisk ram (kundens egen):** Hubs är **mellanlagring (staging)** — den sekretessmärkta,
> on-prem-ytan där rehab- och personalkommunikation tas emot, handläggs, möts, signeras och bevakas.
> **Slutlagring (system of record) är alltid verksamhetens facksystem:** rehab-dokumentationen bor i
> **Adato** (Miljödata, rehab-systemet of record — arbetsförmågebedömningar, läkarintyg och utredningar
> ska förvaras i Adato), anställnings-/lönehändelsen i **Personec P / Visma HR / Heroma**. Hubs *stagar*,
> handläggaren/chefen *committar* utfallet (den signerade planen, det signerade avtalet,
> journalanteckningen) in i facksystemet. Varje widget nedan svarar på två frågor: **varifrån kommer
> datat** och **var hamnar det till slut**.
>
> **Brand-regel:** i produkt-/UI-text säger vi aldrig "Nextcloud" eller "Talk". I detta interna underlag
> namnges appar (sdkmc, securemail, mail/fax, spreed-itsl, calendar, Deck/Tasks, Groupfolders/ACL,
> Retention, Tables, Forms, Collectives, LibreSign, llm2, stt_whisper2) för att kunna wire:a flödet.
>
> **Vem:** HR-partner/HR-konsult eller enhets-/verksamhetschef med personalansvar i kommun/region. Bär
> ofta flera hattar (chef *och* HR-stöd) i en mindre kommun. Vardagen kretsar kring den **känsligaste
> kategorin personuppgifter** — hälsodata, sjukfrånvaroorsaker, läkarintyg, rehabdokumentation,
> arbetsanpassning, missbruks-/avslutsärenden och fackliga förhandlingar. Det dokumenterade gapet: bara
> **34 %** av cheferna har ett verktyg för säker hantering av läkarintyg/rehabmöten/FK-kontakter, och
> **35 %** av organisationerna saknar digitalt systemstöd för rehab. Vårdgivare och Försäkringskassan får
> av sekretesskäl **inte mejla** om rehabärenden; samtycke har historiskt skickats per post. Detta är
> Hubs öppning.

---

## En dag i arbetet (08:00 → 17:00)

Maria är HR-partner med chefsansvar i en mellanstor kommun (~3 000 anställda). Hon driver ett trettiotal
aktiva rehab-/personalärenden parallellt. Allt känsligt går genom Hubs — aldrig öppen e-post.

**08:00 — Triage av den känsliga inkorgen.** Maria öppnar Hubs Start och möts av sin **avskilda HR-vy**
(härledd ur grupp `hr`). Överst summeringen *"Min HR-dag"*: **4 nya känsliga meddelanden · 2 frister idag
· 3 väntar på motpart · 1 att signera"*, med markören *"Säker kanal · all data i er driftmiljö"*. I
`kansligInkorg` ligger: (1) ett **läkarintyg** inkommet via säkert meddelande från en medarbetare (Erik,
sjukskriven sedan tre veckor), (2) ett svar från **företagshälsovården** om en arbetsförmågebedömning, (3)
en **kallelse till avstämningsmöte** från Försäkringskassan (inkommen via SDK org-till-org), och (4) en
**facklig begäran** om förhandling i ett avslutsärende. Inget av detta finns i Outlook. Tom kö = inget
missat (compliance-värde).

**08:25 — Provenance-läsning.** På FK-kallelsen visar provenance-bandet: *"Inkom via SDK från
Försäkringskassan 07:51 · avsändare verifierad (org-till-org) · ska föras till Adato-rehabärendet"*. På
läkarintyget: *"Inkom via säker e-post 06:40 · rehab startad — sjukfrånvarosignal kom från Personec ·
plan ska förvaras i Adato"*. Maria vet direkt varifrån varje rad kom och vart den ska.

**08:35 — Fristbevakning.** `fristStrip` (låst kärna) varnar i gult: medarbetaren Erik passerar **dag 30**
om 4 dagar → **plan för återgång i arbete** måste upprättas (krav vid förväntad sjukskrivning ≥60 dgr,
senast dag 30). En annan medarbetares **läkarintyg går ut om 3 dagar** (begär förlängning/uppföljning). En
tredje rad: **avstämningsmöte** bokat men saknar agenda. Maria skapar **bevakning från FK-kallelsen** med
ett klick — Hubs förifyller titel ("Avstämningsmöte FK – Erik N."), länkar tillbaka till meddelandet och
föreslår frist.

**09:00 — Rehabmöte (säkert videomöte).** Från `hr_moten` ansluter Maria till ett **säkert rehabmöte** med
en medarbetare + företagshälsovården. Deltagarna släpps in via BankID-verifierat väntrum; Maria ser i
lobbyn vilka som identifierats (LOA3). Mötet är knutet till **rehab-ärenderummet**; plan-utkast och
anteckningar ligger redan i rummet. Vid mötesstart kryssar deltagarna i **samtycke till inspelning**
(`recording_consent` påtvingat); efterhands-transkribering sker lokalt med **KB-Whisper** och ett
**AI-utkast** till mötesanteckning + åtgärdslista föreslås — som Maria måste *godkänna* innan något sparas.

**10:30 — Signera & delge.** Två signeringsärenden ligger i `attSignera`: (1) ett **anställningsavtal** till
en nyrekryterad ska skickas för avancerad e-underskrift (BankID, på distans innan tillträde), och (2) en
**rehaböverenskommelse om arbetsanpassning** ska delges Erik via säkert meddelande med läskvittens. Maria
trycker "Begär underskrift"; status börjar tickas i `skickatForSignering` (Skickat → Öppnat → Signerat).

**11:15 — Dokumentation i rummet.** Maria öppnar rehab-rummet, samredigerar **plan för återgång (FK 7459)**
med ansvarig enhetschef i den on-prem-dokumentytan (Collabora/OnlyOffice). ACL begränsar rummet till HR +
ansvarig chef; gallringsregeln är satt per dokumenthanteringsplanen.

**13:00 — Samtycke & FK.** Maria behöver kontakta Eriks **vårdgivare** men får inte utan samtycke. Hon
skickar en **samtyckesblankett** från `mallarSamtycke` (säkert formulär + BankID) — Erik legitimerar sig
och signerar; det ersätter "samtycke per post". Det signerade samtycket arkiveras i rehab-rummet. Därefter
skickar hon **plan för återgång till Försäkringskassan** via säker kanal (SDK).

**14:00 — Facklig förhandling & avslut.** Maria förbereder ett avslutsärende: bokar ett säkert möte med
facklig part, lägger underlaget i ett avskilt personalärende-rum (separat ACL). Inget medarbetarnamn
hamnar i öppen e-post.

**15:00 — Uppföljning & överföring.** Maria klarmarkerar bevakningar, sätter nästa uppföljningsdatum på två
rehabärenden, och **för över** den nu signerade rehabplanen + valideringsintyg till **Adato** (markerar
"Överförd till Adato" — händelsen loggas). Anställningsavtalet, när det signerats, journalförs i
**Personec**. `rehabarenden`-vyn visar att enhetens samlade rehab-kö inte har något "rött" som fallit
mellan stolarna.

**16:30 — Dagsavslut.** Maria tittar på `hr_nytta` (chef-läge) inför ett budgetsamtal med
förvaltningschefen: antal ersatta papperssamtycken/fax, andel i säker kanal, sparad tid (~30 min/ärende).
Det blir underlaget till cybermiljard-/budgetdialogen. Tom triage-kö, inga röda frister → dagen är klar.

**Designkonsekvens:** HR-vyn måste vara **avskild, sekretessmärkt och deadline-medveten** — den får aldrig
blanda personalärenden med allmän kommunikation, och den måste göra lagstadgade frister (dag 8, dag 30,
60-dagarströskeln) synliga som "nästa åtgärd". Och varje rad måste bära provenance → destination: *kom från
X · ligger i Hubs medan vi jobbar · förs till Adato/Personec när klart*.

---

## Hur Hubs + dashboarden faktiskt används (öppningsordning & åtgärder)

Förstavyn är **handlingsförst**, inte en inkorg. Layouten (från `personaConfig.js`):
- **main:** `kansligInkorg` · `fristStrip` · `rehabarenden` · `attSignera` · `bevakningar`
- **side:** `dagensMoten` · `skickatForSignering` · `mallarSamtycke` · `kvittenser` · `senasteFiler` · `kunskapsbank` · `nytta`

Faktisk användningssekvens under dagen:

| Tid | Widget(ar) som öppnas | Användarens åtgärd | Var åtgärden landar |
|---|---|---|---|
| 08:00 | `kansligInkorg` (+ "Min HR-dag"-summering överst) | Skumma triage-kön, läsa provenance per rad | Inget committas än — allt i mellanlagring |
| 08:35 | `fristStrip` → klick på rad → `bevakningar` | "Skapa bevakning från meddelande" (förifylld frist) | Bevakning i Deck (mellanlagring); formell frist i Adato vid överföring |
| 09:00 | `dagensMoten` → säkert möte | "Anslut", godkänn inspelning, godkänn AI-utkast | Mötesanteckning (godkänd) → committas till Adato; rå-inspelning gallras |
| 10:30 | `attSignera` → `skickatForSignering` | "Begär underskrift" (anställningsavtal + rehaböverenskommelse) | Signeringskö (LibreSign demo / Inera-Sweden Connect prod) |
| 11:15 | `rehabarenden` → öppna rehab-rum | Samredigera plan för återgång (FK 7459) | Plan-utkast i ärenderum (Groupfolders, on-prem) |
| 13:00 | `mallarSamtycke` → säkert meddelande | Skicka samtyckesblankett (Forms+BankID), skicka plan till FK | Signerat samtycke i rehab-rum; plan via SDK till FK |
| 15:00 | `bevakningar` · `rehabarenden` · `kvittenser` | Klarmarkera, sätta uppföljning, **"Överför till Adato"** | **Adato (rehab) / Personec (PA)** = slutlagring |
| 16:30 | `nytta` (chef-läge) | Sammanställ ROI för ledningen | Tables-register (mellanlagring), export till budgetdialog |

Återkommande interaktionsmönster:
- **Card View + Quick View:** Maria agerar i kortet (kvittera, skapa bevakning, begär underskrift) och
  expanderar för detalj utan sidbyte.
- **Tom-kö-principen:** mätvärdet är *tid-till-åtgärd och fristträffsäkerhet*, inte tid på dashboarden. En
  tom `kansligInkorg` + inga röda frister = inget missat = compliance-värde.
- **Provenance-band på varje ärende-rad:** kanal in → status nu (Hubs/mellanlagring) → slutdestination
  (Adato/Personec) + Hubs egen rensningscountdown efter överföring.
- **Behörighet = säkerhetsgräns:** vyn visar aldrig rubrik/namn/antal från en HR-kö Maria saknar behörighet
  till (`IConditionalWidget` som åtkomstgräns, inte UX).

---

## Widget → app → system-of-record-karta

För **varje widget** i `hr_chef`-layouten: vilken Nextcloud-app/funktion driver den, var datat kommer
ifrån, och i vilket facksystem resultatet slutlagras. Mellanlagringsmodellen görs explicit per rad.

### Main-kolumnen (låst kärna + rekommenderat skal)

| # | Widget | UI-titel | Driv-app (intern) | dataSource | Data FRÅN (källa) | Hubs stagar (mellanlagring) | Förs över TILL (slutlagring) | Handoff-mönster |
|---|---|---|---|---|---|---|---|---|
| 1 | `kansligInkorg` | Känslig inkorg (rehab & personal) | `sdkmc` + `securemail` + `mail`/fax (summary-endpoint, server-side kanalklassning, HR-kontextfiltrerad) | real | Läkarintyg/medarbetarsvar (säker e-post/SDK/fax), FK-kallelse (SDK), företagshälsovård, facklig part | Avskild triage-kö, oläst/kvittens-status, kanalikon per rad | Innehållet förs vidare per ärende; **Adato** (rehab) / **Personec/Visma/Heroma** (PA) | D (manuell), A om Adato-API |
| 2 | `fristStrip` | Frister denna vecka | Deadline-register (`tables`) + bevakning (`deck`/`tasks`) | proposed | Härleds ur intygsdatum, sjukperiodens längd, FK-kallelser, mötesdatum | Eskaleringsstrip: dag 8 (läkarintyg), **dag 30 (plan)**, 60-dagar, avstämningsmöte, intygsförlängning (grå→gul→röd) | Formell frist-/aktivitetsbevakning bor i **Adato** (PA-integrerad, bevakar sjukfrånvaro automatiskt) | A/D vid registrering i Adato |
| 3 | `rehabarenden` | Rehab- & personalärenden | Säkra filer / ärenderum (`files`+`groupfolders`+ACL+`files_retention`) + statusregister (`tables`) | proposed | Sjukfrånvarosignal (Personec→Adato), intyg, mötesunderlag, plan-utkast | Avskilt rum per ärende; statusflöde (Ny/Pågående/Väntar på motpart/Plan upprättad/Avslutad); dubbel retention | Rehab-akten (dokumentationen) = **Adato**; anställningshändelse = **Personec/Visma/Heroma** | D (→Adato), A om API |
| 4 | `attSignera` | Att signera | E-underskrift (`libresign` demo / **Inera Underskriftstjänst-API** eller **Sweden Connect-nod** prod) | proposed | Dokument skapat i rehab-rum **eller** exporterat ur Visma/Heroma (avtalsmall) | Min-signeringskö + utgående; kravnivå-badge (AES via BankID standard); lågrisk → "Godkänn" loggat | Signerad PAdES/PDF/A + valideringsintyg committas till **Adato** (plan/beslut) / **Personec** (avtal) | D/A |
| 5 | `bevakningar` | Mina bevakningar & frister | Uppgifts-/bevakning (`deck` delad / `tasks` personlig) | real | "Skapa bevakning från meddelande" på inkommande rad | Deadline-sorterad lista, eskaleringsfärg, påminnelse T-7/T-3/T-0 bara till tilldelad | Formell bevakning förs till **Adato**; Hubs-bevakningen gallras/länkas efter överföring | A/D |

### Side-kolumnen (kuraterat skal)

| # | Widget | UI-titel | Driv-app (intern) | dataSource | Data FRÅN | Hubs stagar | Förs över TILL | Mönster |
|---|---|---|---|---|---|---|---|---|
| 6 | `dagensMoten` | Dagens & veckans säkra möten | `calendar` (Appointments) + `spreed`-itsl (+ BankID/Freja-lobby; ev. `stt_whisper2`/KB-Whisper + `llm2`) | real | Bokade rehab-/avstämnings-/medarbetarmöten | Säkert videorum, väntrumsstatus (LOA), inspelning+transkript+AI-utkast (lokalt) | **Godkänd** mötesanteckning → **Adato**; rå-inspelning/-transkript gallras (transient) | D + lokal AI |
| 7 | `skickatForSignering` | Skickat för signering | E-underskrift (`libresign`/Inera) + säkra meddelanden (`sdkmc`) | proposed | Utgående signeringsbegäran (avtal, plan, överenskommelse) | Spegelvy: Skickat→Öppnat→Signerat av X av Y→Klart; Påminn-knapp per part | Klart → signerad handling committas till **Adato/Personec** | D/A |
| 8 | `mallarSamtycke` | Mallar & samtycke | `forms` (internt) + mallbibliotek (`collectives`/`files`) + e-underskrift (`libresign`/Inera) | proposed | Återkommande dokumentbehov (samtycke, FK 7459, rehaböverenskommelse, kallelse) | Säkert formulär + BankID-signering; ersätter "samtycke per post" | Signerat samtycke/plan → rehab-rum → **Adato** | D |
| 9 | `kvittenser` | Leveranser & kvittens | Säkra meddelanden (`sdkmc` receipt) | real | Utgående delgivning/meddelande till medarbetare/FK/vårdgivare | Leveranstidslinje: Skickad→Levererad→Öppnad→Inloggad(LOA3)→Läst→Besvarad | Bevis på delgivning loggas i ärendet → **Adato** | C/E (kvitto-loop) |
| 10 | `senasteFiler` | Senaste säkra filer | Säkra filer (`files`+`groupfolders`+`files_versions`) | real | Nya/ändrade dokument i rehab-/personalrummen | "Vad hände senast": delad/ny version/väntar granskning/signerad | Bestående handling förs till **Adato**; Hubs-kopia gallras | D |
| 11 | `kunskapsbank` | HR-kunskapsbank & mallar | `collectives` (wiki on-prem) | real | Rutiner, lathund "vad får jag skicka var", gallringsplan, AFS 2023:2-checklista | Referensyta (låst utanför konfigurerbart skal, WCAG 3.2.6) | — (referens, ej ärendedata) | — |
| 12 | `nytta` | Nytta hittills | Strukturerat register (`tables`) | proposed | Räknat utfall: ersatta fax/papperssamtycken, andel säker kanal | ROI-räknare för chef-/budgetdialog | Underlag exporteras till ledning/nämnd | — |

**Genomgående markör (alla vyer):** den diskreta `dataSuveranitet`-/"säker kanal"-chippen: *"Hubs är er
säkra mellanlagring. Den bestående handlingen bevaras i Adato/Personec. Inget lämnar er driftmiljö."* Det
är svaret på CLOUD Act-/OSL 10:2a-frågan på en rad.

---

## Typiska arbetsmönster & återkommande flöden (end-to-end)

### Flöde 1 — Läkarintyg → bedöm → plan för återgång (dag 30) → följ upp → överför till Adato
1. **Data tas emot:** Medarbetaren skickar läkarintyg via säkert meddelande (eller fax-in från vårdgivare);
   sjukfrånvarosignalen kom ursprungligen från **Personec** in i **Adato**. Landar i `kansligInkorg` —
   aldrig öppen e-post.
2. **Mellanlagring/triage:** HR läser, **skapar bevakning från meddelandet** (Hubs förifyller titel, länkar
   tillbaka, föreslår frist). Systemet flaggar **dag 30**-tröskeln om sjukskrivning väntas ≥60 dgr.
3. **Ärenderum:** intyget arkiveras i rehab-rummet (`rehabarenden`/Groupfolders) med gallringsregel; ACL =
   HR + ansvarig chef.
4. **Plan:** mallen **"Plan för återgång i arbete" (FK 7459)** startas i rummet (`mallarSamtycke`),
   samredigeras on-prem (Collabora/OnlyOffice).
5. **Signera & delge:** planen skickas för **avancerad e-underskrift** (medarbetare via BankID, även på
   distans) via `attSignera`; signerad PDF/A + valideringsintyg arkiveras.
6. **Slutlagring:** den signerade planen + valideringsintyg **förs över till Adato-rehabärendet** (mönster
   D, eller A om Adato-API); plan delges FK via säker kanal. `fristStrip` röjer nästa uppföljningsdatum.
   Hubs-kopian får rensningscountdown.
- *Compliance:* OSL/GDPR (hälsodata = särskild kategori), FK-krav plan senast dag 30, AFS 2023:2 rehabansvar,
  arkivlagen (Adato/PA-systemet bevarar), NIS2-spårbarhet.
- *Provenance-band:* *"Rehab startad (signal från Personec) · plan ska förvaras i Adato"* → *"Plan
  signerad · överförd till Adato 2026-06-13"*.

### Flöde 2 — Boka rehab-/avstämningsmöte → genomför säkert → AI-utkast → godkänn → committa
1. **Data tas emot/initieras:** FK-kallelse till avstämningsmöte inkommer via SDK, eller HR bokar
   rehabmöte. Från `dagensMoten` skapas bokningsbar tid → **säkert videorum** auto-genereras; kallelse till
   medarbetare + företagshälsovård (länk, inget konto krävs).
2. **Insläpp:** deltagare verifieras med BankID/Freja; väntrumsstatus i lobbyn (LOA3). Samtycke till
   inspelning (`recording_consent`) kryssas innan insläpp.
3. **Genomför + bearbeta:** mötet hålls; inspelning (WebM) sparas i rehab-rummet; **KB-Whisper**
   (`stt_whisper2`) transkriberar lokalt; **`llm2`** föreslår ett utkast till mötesanteckning + åtgärdslista.
4. **Human-in-the-loop:** HR **granskar och godkänner** sammanfattningen (GDPR/OSL — aldrig auto-spara av
   känsliga uppgifter).
5. **Signera/delge:** överenskommelse om arbetsanpassning skickas för e-underskrift; delges via säkert
   meddelande med läskvittens (`kvittenser`).
6. **Slutlagring:** den **godkända** mötesanteckningen committas till **Adato**; rå-inspelning + rå-transkript
   gallras (transient mellanlagring, kort retention).
- *Compliance:* sekretess-säkert insläpp (eID), `recording_consent` loggat, eIDAS art. 26 (AES), HSLF-FS
  2016:40 där HSL-nära (företagshälsovård).
- *Provenance-band:* *"Avstämningsmöte (FK-kallelse via SDK) · inspelning transient · anteckning förs till
  Adato"*.

### Flöde 3 — Inhämta samtycke för vårdgivarkontakt → kontakta → dokumentera
1. **Behov:** HR behöver kontakta medarbetarens vårdgivare men får inte utan **samtycke** (och
   vårdgivare/FK får ej mejla om rehab).
2. **Begär samtycke:** från `mallarSamtycke` skickas **samtyckesblankett** (Forms + BankID) via säkert
   meddelande; medarbetaren legitimerar sig och signerar — ersätter "samtycke per post".
3. **Mellanlagring:** det signerade samtycket arkiveras i rehab-rummet (tidsstämplat, spårbart).
4. **Kontakta:** HR kontaktar vårdgivaren via säker kanal/SDK eller bokat säkert möte; allt knyts till
   rummet.
5. **Slutlagring:** samtycket + dokumentationen **förs över till Adato**; bevakning skapas för svar.
- *Compliance:* GDPR (laglig grund/samtycke för hälsodata), OSL, dataminimering (korttext = ärendereferens,
  inte klartext-diagnos).
- *Provenance-band:* *"Samtycke inhämtat (Forms+BankID) · arkiveras i Adato"*.

### Flöde 4 — Anställningsavtal / personalbeslut: upprätta → signera → committa till Personec
1. **Data tas emot:** rekryteringsbeslut/avtalsmall hämtas (mall i Hubs Filer eller exporterad ur
   **Visma/Heroma**).
2. **Mellanlagring:** HR upprättar avtalet, **skickar för avancerad e-underskrift** till den nyrekryterade
   (BankID, även på distans innan tillträde). Statuskedja i `skickatForSignering`; påminnelse vid behov.
3. **Slutlagring:** signerat avtal (PAdES → PDF/A + valideringsintyg) **journalförs i Personec/Visma/Heroma**
   (personalakten); Hubs loggar att överföring skett och behåller leverans-/läskvittens, inte originalet
   som arkivsanning. "Verifiera underskrift nu/då"-panel ger bevisbarhet över tid (LTV).
- *Compliance:* eIDAS art. 26 (giltigt/bindande), arkivlagen (PA-systemet bevarar), bevisvärde via
  LTV/tidsstämpling.
- *Provenance-band:* *"Avtal signerat · journalförs i Personec · Hubs-kopia gallras 30 dgr efter
  överföring"*.

---

## Saknade funktioner för denna persona — och hur de byggs/wire:as

### 1. Mötestranskribering + lokal AI-sammanfattning av rehab-/avstämningsmöten (kärngap)
**Behov:** rehab- och avstämningsmöten producerar idag handskrivna anteckningar som lätt blir tunna och
personberoende; ett strukturerat, sökbart underlag (med beslut + åtgärdslista) saknas — men får inte läcka
till tredje part.
**Wire:** `spreed`-itsl recording server (kräver HPB) → WebM i rehab-rummet (ärver ACL+Retention) →
efterhands-transkript via **`stt_whisper2` med KB-Whisper** (Apache-2.0, svensk-tränad, ~47 % lägre WER än
large-v3, drop-in i datavolymen) → **`llm2`** (llama.cpp/GGUF, lokal) + Assistant/`call_summary_bot` ger
**AI-utkast** till anteckning + åtgärdslista. **Human-in-the-loop:** HR godkänner; **rå-inspelning och
rå-transkript gallras**, bara den godkända sammanfattningen committas till **Adato**. `recording_consent`
sätts påtvingat och samtyckestidsstämpeln loggas. Bygg-uppgift: map-reduce-chunkning för transkript som
överskrider llm2:s kontextfönster (4–8k tokens). *Demobart nu för interna/icke-sekretessbelagda möten;
skarpt på känsliga möten inväntar IMY/SKR-vägledning.*

### 2. Todolista/bevakning för personalärenden med påminnelse *före* frist (utan att dubblera Adato)
**Behov:** en deadline-bärande "vad måste jag göra och när"-lista för rehab/personal som inte ligger i
huvudet eller i osäker e-post — men som inte konkurrerar med Adatos automatiska sjukfrånvarobevakning.
**Wire:** `minaUppgifter` (personlig) på **Tasks/VTODO** (native påminnelsetider) + `bevakningar` (delad
enhets-kö) på **Deck**. Hubs bygger det Deck-kärnan saknar: påminnelse-före-deadline (T-7/T-3/T-0) **bara
till tilldelad** (täcker Deck #1549/#566) och knapp-/tangentbordsalternativ till drag (WCAG 2.5.7).
Signaturfunktion: **"Skapa bevakning från meddelande"**. **System-of-record-disciplin:** Hubs-listan fångar
det inkommande *innan* det blir ett formellt Adato-ärende; vid klarmarkering väljer HR "gallra (personlig
notering)" eller "för till Adato/PA-ärendet" — håller isär gallringsbara att-göra-lappar från
ärendebundna allmänna handlingar.

### 3. BankID/Freja-AES-signering on-prem (LibreSign räcker inte för externt/myndighetsbeslut)
**Gap:** LibreSign ger lokala köer + signerad PAdES-likt PDF on-prem, men dess identitetsfaktorer är
konto/e-post/SMS/klick — **ingen native BankID/Freja/SITHS**, självsignerad rot-CA. Otillräckligt för
anställningsavtal, rehabbeslut och delgivning till externa parter.
**Wire:** bygg en **signeringsadapter med två backends bakom samma kö-UI** (`attSignera`/`skickatForSignering`):
LibreSign för internt lågrisk-"Godkänn" (ärligt etiketterat "konto/SMS, ej BankID"), och **Inera
Underskriftstjänst-API** (mTLS + SITHS funktionscert, BankID/Freja/SITHS, PAdES + PDF/A-1) **eller egen
Sweden Connect-nod** (Digg open source) för allt externt/myndighetsbeslut. Bygg **inte** kryptokärnan —
äg köerna, spårningen och bevarandet ("Giltig nu / Giltig då"-panel, PAdES/PDF/A/LTV) som ingen
konkurrent (Scrive/Assently/Visma Addo) säljer tydligt. Den bevarade signerade handlingen committas till
**Adato/Personec**.

### 4. Adato/PA-konnektor (gör "för över till facksystemet" till ett klick)
**Gap:** överföringen av signerad plan/beslut/avtal till Adato respektive Personec/Visma/Heroma är idag
manuell (mönster D) — fungerar dag 1, men utan automatik.
**Wire:** en tunn konnektor per facksystem bakom en `IButtonWidget`-action "Överför till facksystemet",
byggd mot **standarden** (Ena REST-API-profil) snarare än mot varje system en-och-en, så Adato/Personec
blir konfiguration ovanpå A-mönsteradaptern. Tills dess: alltid-tillgänglig D (ladda ner signerad PDF/A +
kvittens, "Markera som överförd till Adato" loggas som händelse) + dubbel retention-vy i `rehabarenden`
(facksystemets bevarande + Hubs rensningscountdown).

---

`hr_chef` · system of record: **Adato (rehab) + Personec / Visma HR / Heroma (PA-/lönesystem)** · saknad funktion: **mötestranskribering + lokal AI-sammanfattning (KB-Whisper + llm2, human-in-the-loop, godkänd anteckning committas till Adato, rå-data gallras)**.
