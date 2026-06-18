<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# UX-koncept: **Guidad** — den uppgiftsorienterade socialsekreterarvyn (learnability-first)

> **Koncept-id:** `guidad` · **Persona:** `socialsekreterare` (barn & familj, SoL 2025:400, BBIC)
> **Vinkel:** en NY socialsekreterare ska förstå *hur hen arbetar* inom 30 sekunder.
> **Plattform:** server v32 · **Frontend:** Vue 2.7 + @nextcloud/vue v8 · **Datum:** 2026-06-14
> **Varumärkesregel:** aldrig "Nextcloud"/"Talk" i UI — Hubs, säkra meddelanden, ärenderum, säkert möte, e-underskrift.
> **Antagande:** alla blockers lösta — Treserva via **Frends** (iPaaS), **Inera Underskriftstjänst** för signering,
> laglig+lokal **transkribering** klarställd, **Retention-paus** finns. Detta är ett *skarpt* verktyg — åtgärderna är riktiga.

---

## Bärande idé (och varför den är lätt att förstå)

**Vyn är inte en samling widgetar — den är en arbetsgång som ställt sig på högkant.** Idag möter
socialsekreteraren ~13 parallella kort utan inbördes berättelse ("widget-soppa"). Konceptet **Guidad** ersätter
det med **en enda vertikal kolumn av fem uppgiftszoner, namngivna med verb i den ordning arbetet faktiskt sker**:
*Ta emot ny anmälan → Arbeta med pågående utredning → Genomför möte → Fatta & signera beslut → Följ upp & bevaka.*
Det är samma fem akter som walkthrough:en (Akt I–V), gjorda till **rubriker man kan klicka på**. Varje zon är en
**GOV.UK-task-list** (verb-inledda rader, minimal statusmodell, "nästa åtgärd" framhävd), och varje zon kan vara
*ihopfälld* tills den är relevant — så en ny handläggare ser **berättelsen först, detaljerna sen** (progressive
disclosure). Överst sitter en orienteringsrad som svarar på den enda fråga en stressad handläggare har på morgonen
("**Vad behöver jag göra nu, och brinner någon frist?**"), och en stor **"Vad vill du göra?"-ingång** med de fem
verben som knappar. Det är lätt att förstå därför att gränssnittet **läser som en mening, inte som ett kontrollrum**:
man kan peka på vyn och säga *"så här går ett barnärende — uppifrån och ned"*. Den mentala modellen — *Hubs är
mellanlagringen, Treserva är facit* — bärs visuellt av ett **destinations-chip på varje rad** ("→ Treserva — ej
registrerad" / "Registrerad i Treserva, dnr 2026-IFO-1234"), så att även en nyanställd ser *vart* arbetet tar vägen
utan att det stör.

---

## Informationsarkitektur (zoner uppifrån och ned)

En kolumn, läst uppifrån och ned. Allt nedanför **Orienteringsraden** är de fem akterna i kronologisk ordning.
Default visas **Akt 1 + Akt 5 + Idag-strecket** öppna (det man rör varje morgon); Akt 2–4 är **ihopfällda
sammanfattningskort** som öppnas vid behov. Det ger en startvy på 5–7 synliga block, aldrig 13.

```
┌────────────────────────────────────────────────────────────────────────────┐
│  A. ORIENTERINGSRAD  ("Hej Anna — 2 frister inom 3 dagar · 1 klar för beslut")│  ← låst kärna, alltid överst
│     [ Fristmätare grå/gul/röd ]   [ Hjälp ? ]   [ Datasuveränitet-prick ]      │
├────────────────────────────────────────────────────────────────────────────┤
│  B. "VAD VILL DU GÖRA?"  — 5 verbknappar (primäråtgärder)                       │  ← ingången för nya & för Cmd+K
│   [Ta emot anmälan] [Arbeta med utredning] [Boka möte] [Signera beslut] [Följ upp]│
├────────────────────────────────────────────────────────────────────────────┤
│  C. IDAG  — task-list: dagens konkreta steg, deadlinesorterad (nästa åtgärd)    │  ← Akt V personligt, öppen default
├────────────────────────────────────────────────────────────────────────────┤
│  AKT 1 ▸ TA EMOT NY ANMÄLAN        (Inflöde & triage)            [öppen]        │
│  AKT 2 ▸ ARBETA MED PÅGÅENDE UTREDNING  (Ärenderum)             [ihopfälld ▸]   │
│  AKT 3 ▸ GENOMFÖR MÖTE & DOKUMENTERA   (Möte/transkribering)    [ihopfälld ▸]   │
│  AKT 4 ▸ FATTA & SIGNERA BESLUT    (Beslut/signering/delgivning) [ihopfälld ▸]  │
│  AKT 5 ▸ FÖLJ UPP & BEVAKA         (Frister & todo)             [öppen]         │
├────────────────────────────────────────────────────────────────────────────┤
│  Z. HJÄLP & KUNSKAPSBANK  (BBIC-mallar, rutiner — fast plats, WCAG 3.2.6)       │  ← låst kärna, alltid sist
└────────────────────────────────────────────────────────────────────────────┘
```

**Varför just denna ordning:**
1. **Orienteringsraden (A)** först därför att det är frågan handläggaren bär in genom dörren — *brinner något?*
   Den är **låst kärna** (kan inte döljas), eftersom "inget barn mellan stolarna" är ett compliance-värde, inte
   bekvämlighet.
2. **"Vad vill du göra?" (B)** näst, som en uttalad **ingång**: fem stora verbknappar. För den vane är det
   genvägar; för den nye är det *självaste kartan över arbetet* — fem verb som sammanfattar yrket.
3. **Idag (C)** — den personliga task-listan (Akt V, `minaUppgifter`): "det jag ska göra idag, i tur och ordning".
   Den ligger högt för att vyn ska kännas som en *att-göra-lista*, inte en inkorg.
4. **Akt 1–5** är arbetsgången i kronologi. De är **ackordeon-sektioner**: rubrik + en-radssummering syns alltid,
   innehållet fälls ut. Detta är progressive disclosure på *akt-nivå* — man behöver aldrig se utredningsverktygen
   medan man triagear inflöde.
5. **Hjälp & Kunskapsbank (Z)** sist och på **fast plats** i alla lägen (WCAG 3.2.6 Consistent Help) — `kunskapsbank`
   ligger utanför det konfigurerbara skalet så hjälpen aldrig flyttar sig.

**Låst kärna vs kuraterat skal:** A, C, Z + fristmätaren och destinations-chipsen är **låst kärna** (alltid synliga,
gråtonat hänglås om man försöker dölja, med förklaring "krävs för din roll"). Ordningen och ihop/utfällt-läget på
Akt 2–4 får handläggaren spara som personlig preferens — med **knappbaserad omordning** ("flytta upp/ned"), aldrig
bara drag (WCAG 2.5.7).

---

## Nyckelkomponenter (byggbara i Vue)

Varje komponent följer **Card View + Quick View**-mönstret: agera direkt i kortet/raden, expandera för detalj utan
sidbyte. Statusmodellen är minimal och delad: `Ny · Påbörjad · Väntar på motpart · Klar för beslut · Klar` + rött
`Åtgärd krävs`. Färg är aldrig enda informationsbärare (ikon + text + färg).

| # | Komponent (Vue) | Backing widget/app | Syfte | Vad den visar | Åtgärder (verb) |
|---|---|---|---|---|---|
| A1 | **`OrienteringsRad.vue`** | härledd ur `attHantera`/`bevakningar` summary | Morgonens "brinner något?" på en rad | Hälsning + "X frister inom 3 dgr · Y klara för beslut · Z röda" + global fristmätare grå/gul/röd | Klick → scrollar/öppnar relevant akt; "Visa alla frister" → Akt 5 |
| A2 | **`HjalpKnapp.vue`** | `kunskapsbank` (Collectives) | Mikrohjälp alltid nåbar | "?" som öppnar kontextuell hjälp-popover + länk till kunskapsbanken | "Visa hur det här fungerar"; "Öppna rutin" |
| A3 | **`SuveranitetsPrick.vue`** | `dataSuveranitet` (diskret variant) | Lugnande "datan stannar hos er" | Liten grön prick + tooltip "All data i er driftmiljö · 0 tredjelandsöverföringar" | Hover/klick → detalj |
| B | **`VadVillDuGora.vue`** | primäråtgärds-router | Ingången & den synliga arbetskartan | 5 stora verbknappar med ikon + undertext ("Ta emot ny anmälan — starta förhandsbedömning") | De 5 primäråtgärderna (se nedan); öppnar rätt akt/dialog |
| C | **`IdagLista.vue`** | `minaUppgifter` (Tasks/VTODO) | "Vad gör jag idag" | GOV.UK task-list: dagens steg deadlinesorterat, status + **nästa-åtgärd**-etikett per rad | "Markera klar"; "Öppna ärenderum"; "Skjut upp"; klarmarkering frågar **gallra / för till ärendet** |
| 1a | **`AnmalningsTriage.vue`** | `attHantera` + `funktionsbrevlador` + `orosanmalningar` | Akt 1: ta emot & triagera | Inkommande rader: kanalikon, oläst-markör, **14-dgr-countdown**, källa (skola/polis/vård/privat), avsändar-LOA-badge, destinations-chip | "Plocka/Ta ärendet"; "Skyddsbedömning"; "Skapa ärenderum"; "Registrera i Treserva" |
| 1b | **`SkyddsbedomningsKort.vue`** | `forms`/`arenderum`-notering | Tvinga fram det dokumentationspliktiga momentet | Mall-checklista "Behöver barnet omedelbart skydd?" + tidsstämpel (bunden till **inkom-datum**) | "Dokumentera skyddsbedömning" → committas direkt till Treserva via Frends |
| 2 | **`UtredningsArenderum.vue`** | `arenderum` (Groupfolders+ACL+Retention+Collabora) | Akt 2: arbeta i ärenderummet | Per öppet rum: barn/dnr, **4-mån-frist**, olästa dok, väntar-på-signatur, **dubbel countdown** (Treserva-bevarande + Hubs-rensning), om medborgardelning aktiv | "Öppna ärenderum"; "Lägg BBIC-struktur"; "Samla in handling"; "Begär samtycke"; "Dela utvalt säkert"; "För utredning till Treserva" |
| 3 | **`MoteOchAnteckning.vue`** | `dagensMoten` + `bokningsbaraTider` + `motesanteckningar` (spreed-itsl + stt_whisper2 + llm2) | Akt 3: möte, transkribering, AI-utkast, godkännande | Dagens möten + lobbystatus (BankID/Freja-verifierade deltagare per LOA), efter mötet: transkript ↔ AI-utkast **sida vid sida** | "Boka säker tid"; "Anslut"; "Släpp in"; "Transkribera & sammanfatta (lokalt)"; "Granska & **Godkänn**" |
| 4a | **`BeslutOchSignering.vue`** | `attSignera` + `skickatForSignering` (Inera Underskriftstjänst) | Akt 4: ta fram, signera, följ medsignering | Beslut i kö med kravnivå-badge (SES/AES/QES) + deadline; spegelvy "Skickat→Öppnat→Signerat X/Y" | "Skicka för underskrift"; "Signera (BankID)"; "Godkänn (lågrisk)"; "Påminn" |
| 4b | **`DelgivningOchKvittens.vue`** | `kvittenser` + `securemail`/`sdkmc` | Akt 4: delge & bevisa | Delgivningssätt (vanlig/förenklad/digital brevlåda) + tidslinje Skickad→Levererad→Öppnad→Inloggad(LOA3)→Läst | "Delge beslut"; "Visa kvittens"; auto-skapar överklagandefrist-bevakning |
| 4c | **`BevarandePanel.vue`** | signeringsadapter | Kvalitetsgrind före commit | "PAdES ✓ · PDF/A-1 ✓ · LTV ✓ · tidsstämpel ✓ — Giltig nu / Giltig då" | "Verifiera underskrift nu"; "För beslut till Treserva" |
| 5 | **`BevakningarFrister.vue`** | `bevakningar` (Deck + Tasks/VTODO) | Akt 5: frister & bevakning | Fristsorterad lista, eskaleringsfärg grå→gul(≤3 dgr)→röd, toggle Mina/Enhetens, fristtyp-etikett | "Skapa bevakning från meddelande"; "Markera klar (gallra / för till ärendet)" |
| Z | **`SenasteFiler.vue`** (sekundärt) | `senasteFiler` | "Vad hände senast med mina dokument" | Delad/ny version/uppladdad av motpart + ärenderum-kontext | "Öppna fil"; "Spara i ärenderum" |
| — | **`DestinationsChip.vue`** (atom) | provenance-lager (Frends) | Bär mellanlagring→facit-känslan | "→ Treserva — ej registrerad" (öppen åtgärd) → "Registrerad, dnr X · Hubs-rum gallras Y" | Klick → registrerings-/överföringsdialog |
| — | **`TomtTillstand.vue`** (atom) | per zon | Förklarande tomma tillstånd (onboarding) | Ikon + en mening som **lär ut**, inte bara "inget här" | Primärknapp som startar rätt åtgärd |

---

## Hur arbetsgången stöds — Akt I–V mappad till UI

Principen: **varje akt = en zon; varje walkthrough-steg = en rad eller en åtgärd i den zonen; varje "committa till
facksystem" = ett tryck på destinations-chipet som anropar Frends mot Treserva och flippar chipet när Frends
kvitterar tillbaka.** Eftersom Frends-konnektorn nu är skarp är "Förd över" inte längre en manuell kryssruta utan en
**verifierad commit-händelse** — och Hubs-rummets Retention-countdown startar *först* när den kvittensen kommit
(GAP-007/GAP-019 lösta). Handläggaren leds vidare genom att **nästa-åtgärd-etiketten** på varje rad pekar på nästa
steg, och genom att en avslutad åtgärd i en akt automatiskt *föder* nästa (t.ex. en signerad delgivning skapar
överklagandefrist-bevakningen i Akt 5).

**Akt I — Ta emot ny anmälan (Inflöde & triage, steg 1–10).**
Sker i zon **Akt 1** (`AnmalningsTriage.vue`). En orosanmälan landar i funktionsbrevlådan och dyker upp som rad med
kanalikon, oläst-markör och **14-dgr-countdown bunden till inkom-datum** (inte plock — GAP-002 löst). Handläggaren:
*Plocka* → raden tilldelas henne (assignee); *Skyddsbedömning* → `SkyddsbedomningsKort` tvingar fram det
dokumentationspliktiga momentet och **committar det direkt till Treserva via Frends** (GAP-001 löst — skyddsbedömningen
hamnar i facit, inte bara i ett Hubs-Forms-svar); *Skapa ärenderum* → ett klick orkestrerar Groupfolder + ACL + tagg +
BBIC-grund. Nästa-åtgärd-etiketten leder *Plocka → Skyddsbedöm → Skapa rum → Besluta inleda*. Vid **Aktualisering**
trycker hon på destinations-chipet "→ Treserva — ej registrerad" → förifyllt registreringsformulär POST:as via Frends →
chipet flippar "Registrerad i Treserva, dnr 2026-IFO-1234". **Det är det synliga ögonblicket "var hamnar det".**

**Akt II — Arbeta med pågående utredning (Ärenderum, steg 11–22).**
Sker i zon **Akt 2** (`UtredningsArenderum.vue`). Rummet från Akt 1 återanvänds i utredningsläge; **4-mån-fristen** visas
men **speglas från Treserva via Frends** (read-back), inte räknas självständigt (GAP-018 löst — ingen divergerande
dubbelräkning). Verb-raderna: *Lägg BBIC-struktur* (mall ur `kunskapsbank`), *Samla in handling* (säker fil → rummet),
*Begär samtycke* (Forms + BankID-steg), *Dela utvalt säkert* (kommunicering med läskvittens — med maskeringsstöd,
GAP-017 löst). När utredningstexten är klar: *För utredning till Treserva* → Frends skriver BBIC-journalanteckningen,
chipet flippar, och **dubbel countdown** på rummet börjar räkna Hubs-rensning *efter* Frends-kvittensen.

**Akt III — Genomför möte & dokumentera (Möte/transkribering, steg 23–34).**
Sker i zon **Akt 3** (`MoteOchAnteckning.vue`). *Boka säker tid* → bokningslänk + BankID-lobby; *Anslut* + *Släpp in*
per verifierad deltagare (LOA-badge); inspelning med påtvingat samtycke. Efter mötet: *Transkribera & sammanfatta
(lokalt)* kör KB-Whisper + llm2 on-prem (juridiskt klarställt — GAP-052 löst). Det avgörande UI-greppet: **transkript
och AI-utkast visas sida vid sida** och **Godkänn är tekniskt påtvingat** (man kan inte committa utan aktivt,
loggat godkännande — GAP-029 löst). *Granska & Godkänn* → den godkända anteckningen *förs till Treserva* via Frends;
rå-WebM + rått transkript får kort Retention (som kan **pausas** vid utlämnandebegäran — GAP-031 löst).

**Akt IV — Fatta & signera beslut (Beslut/signering/delgivning, steg 35–44).**
Sker i zon **Akt 4** (`BeslutOchSignering` + `DelgivningOchKvittens` + `BevarandePanel`). Beslutet tas fram på mall;
*Skicka för underskrift* → **Inera Underskriftstjänst** med rätt kravnivå-badge (AES för myndighetsbeslut — GAP-034
löst); *Signera (BankID/Freja/SITHS)* → PAdES/PDF/A-1 + LTV; `BevarandePanel` är kvalitetsgrinden "Giltig nu/Giltig
då" innan commit. *Delge beslut* → väljer delgivningssätt; `kvittenser` följer tidslinjen — och Hubs **härleder
överklagandefristens startdatum ur valt delgivningssätt** och skapar bevakningen automatiskt i Akt 5 (GAP-038/039
hanterade i modellen). *För beslut till Treserva* → Frends paketerar signerad PDF/A + valideringsintyg +
delgivningsbevis. **System of record-ögonblicket.**

**Akt V — Följ upp & bevaka (Frister & todo, steg 45–51).**
Sker i zonerna **Idag (C)** och **Akt 5** (`BevakningarFrister.vue`), plus signaturfunktionen *Skapa bevakning från
meddelande* (finns även som radåtgärd i Akt 1). De fyra lagstadgade klockorna modelleras med fristtyp + DUE +
eskaleringsfärg; påminnelser T-7/T-3/T-0 **bara till tilldelad**. När en Hubs-bevakning blir en formell aktivitet
registreras den i Treserva via Frends, och **kvarvarande Hubs-påminnelser rivs** så Treserva blir ensam fristägare
(GAP-044 löst). Vid klarmarkering frågar Hubs alltid: **"Gallra (personlig notering)"** eller **"För till
ärendet/facksystemet"** — den juridiskt känsligaste interaktionen, här med tydlig default och mikrohjälp.

---

## Frister & sekretess i UI

**De fyra (+1) klockorna — alltid synliga, aldrig bara i huvudet:**

| Frist | Var den visas | Hur den visas | Garanti "inget missas" |
|---|---|---|---|
| **Förhandsbedömning 14 dgr** | Varje rad i Akt 1 + Orienteringsraden | Countdown-badge **bunden till inkom-datum** (GAP-002 löst); grå→gul ≤3 dgr→röd förfallen | Plock påverkar bara tilldelning, inte klockan; röd rad kan inte döljas (låst kärna) |
| **Utredning 4 mån** | Per rum i Akt 2 + Akt 5 | Eskaleringsfärg + T-7/T-3/T-0; **speglad från Treserva** via Frends | Hubs räknar inte egen divergerande frist; förlängningsbeslut synkas (GAP-018/047 lösta) |
| **Tidsbegränsat beslut — uppföljning** | Akt 5 (+ Idag) | Bevakning på slutdatum "Följ upp – insats upphör 30/6" + påminnelse | "Skapa bevakning"-knapp föreslår delad board som default för fristbärande poster (GAP-042) |
| **Överklagandefrist 3 v (FL 44 §)** | Akt 4 → auto till Akt 5 | Skapas automatiskt; **startdatum härlett ur delgivningssättet** | Delgivning (steg 41) och frist (steg 42) hårt kopplade i UI — väljer du sätt, sätts rätt start |
| **FL 6 mån / 4 v** | Akt 5 | Fristtyp-etikett när parten kan begära avgörande | Modelleras som egen klocka i `bevakningar` |

**Orienteringsradens fristmätare** är vyns *tidigaste varning*: "2 förhandsbedömningar förfaller inom 3 dagar". Den
är låst kärna och ligger pixel 1, så att det första ögat ser är *om något brinner*. **Tom kö = grönt = inget barn
mellan stolarna** — visas som ett uttalat positivt tillstånd, inte tomhet.

**Sekretess & LOA-markering:**
- **Behörighet = säkerhetsgräns.** En funktionsbrevlåda eller ett ärenderum man saknar OSL-behörighet till syns inte
  alls (`IConditionalWidget`) — inte ens som rubrik/antal. Detta är en åtkomstgräns, inte UX.
- **Avsändar-LOA-badge** per rad/motpart: "Verifierad med BankID · LOA3 · 2026-06-14 08:14", varningsläge "Ej
  verifierad — anonym anmälan" som ett **legitimt** tillstånd (GAP-053), och ombudsmarkering "Erik E. företräder
  Karin K.".
- **Dataminimering i radtext:** korttitlar default = **ärendereferens/metadata**, aldrig klartextcitat (GDPR art. 5).
- **Fasmarkör** på ärenderummet: en synlig "Under förhandsbedömning"-etikett varnar när handläggaren är på väg att
  kontakta fel part ("endast vårdnadshavare/anmälare/barn i denna fas") — fasvalidering i datamodellen (GAP-006/013).
- **Maskeringsstöd** vid "Dela utvalt säkert": handläggaren väljer *utvalda* handlingar (inte hela rummet) och varnas
  för tredjemansuppgifter (GAP-017).
- **Diskret suveränitetsprick** i orienteringsraden + "säker kanal"-märke som lågmäld konstant — lugnar utan att störa.

---

## Lättbegriplighet & onboarding

**Första 30 sekunderna för en nyanställd (Anna, dag 1):**
1. **0–5 s:** Hon ser en hälsning och en mening på vanlig svenska: *"Hej Anna — 2 frister inom 3 dagar, 1 ärende klart
   för beslut."* Inget jargong, ingen widget-grid. Hon förstår direkt: *det här är min arbetslista, och något är
   nästan försenat.*
2. **5–15 s:** Hennes blick faller på **"Vad vill du göra?"** med fem stora verbknappar. Det är hela yrket sammanfattat
   i fem verb: *Ta emot anmälan · Arbeta med utredning · Boka möte · Signera beslut · Följ upp.* Hon har just lärt sig
   arbetsgången utan att läsa en manual.
3. **15–30 s:** Hon scrollar och ser samma fem verb återkomma som **rubriker** (Akt 1–5) i kronologisk ordning, med
   en mening under varje ("Akt 1 — Ta emot ny anmälan: triagera inflödet och starta förhandsbedömningen inom 14
   dagar"). Tomma akter visar ett **förklarande tomt tillstånd** istället för en tom yta: *"Här landar nya
   orosanmälningar. Du har inga just nu. När en kommer ser du en 14-dagars nedräkning här."* Hon har nu en korrekt
   mental modell av hela ärendelivscykeln — på en halv minut.

**Etiketter:** alltid **verb-först och konkreta** ("Skicka beslut för underskrift", inte "Signeringskö"; "Ta emot ny
anmälan", inte "Inflöde"). UI-svenska, aldrig teknik- eller appnamn. Statusord i klartext (`Väntar på motpart`, inte
ikon ensam).

**Tomma tillstånd (`TomtTillstand.vue`)** lär ut i varje zon: ikon + en mening som förklarar *vad zonen är till för*
och *vad som händer härnäst*, med en primärknapp. Exempel Akt 4: *"När ett beslut är klart att skriva under hamnar
det här. Då signerar du med BankID."* + knapp "Läs om e-underskrift".

**Mikrohjälp:** ett fast **"?"** i orienteringsraden (Consistent Help, samma plats alltid) öppnar en kontextuell
popover ("Vad betyder den röda fristen?") med länk till `kunskapsbank`. Inline-hjälptexter vid första mötet med en
åtgärd ("Att 'plocka' ett ärende betyder att du blir ansvarig — då startar inte 14-dagarsklockan om, den räknas från
när anmälan kom in"). En **dismissbar onboarding-banner** dag 1: "Ny här? Så här hänger vyn ihop →" som highlight:ar
de fem akterna i tur.

**WCAG 2.2 AA (DOS-lagen / EN 301 549):**
- **2.5.7 Dragging Movements:** kortomordning sker med "flytta upp/ned"-knappar, aldrig bara drag.
- **2.5.8 / Target Size ≥24×24 px** på alla status-, klar- och snabbåtgärdsknappar.
- **2.4.11 Focus Not Obscured:** den sticky orienteringsraden och utfällda Quick Views får inte dölja fokus.
- **3.2.6 Consistent Help + 3.2.4 Consistent Identification:** hjälp/kunskapsbank på fast plats, samma ikon = samma
  funktion mellan vyer.
- **3.3.8 Accessible Authentication:** BankID/Freja/SITHS utan kognitiva test.
- **Reflow/Orientation:** fungerar i porträtt och vid 400 % zoom (en-kolumns-arkitekturen är reflow-vänlig per design).
- **Nedräkningsklockor aldrig enbart färg:** alltid ikon + dagar-text + färg.

---

## Primära åtgärder (verb-först)

De fem knapparna i **"Vad vill du göra?"** — också Cmd/Ctrl+K-palettens topprader:

1. **Ta emot & fördela orosanmälan** → öppnar Akt 1, plocka/skyddsbedöm/skapa rum/registrera.
2. **Arbeta med pågående utredning** → öppnar Akt 2 (väljer ärenderum), samla in/samredigera/dela/för över.
3. **Kalla till & genomför säkert möte** → öppnar Akt 3, boka tid / anslut / transkribera & godkänn.
4. **Fatta & signera beslut** → öppnar Akt 4, skicka för underskrift (AES via Inera) / signera / delge.
5. **Följ upp & bevaka frister** → öppnar Akt 5/Idag, skapa bevakning från meddelande / klarmarkera.

---

## Konkret exempel-scenario — ärende **SN 2026-0142** genom vyn

*Anna, nyanställd socialsekreterare, dag 4. Klockan är 08:05.*

1. **Loggar in (Freja eID Plus, LOA3).** Orienteringsraden överst: *"Hej Anna — 1 ny anmälan, inga röda frister
   idag."* Suveränitetsprick grön. Hon känner sig orienterad på 3 sekunder.
2. **Akt 1 är öppen** och visar en ny rad: kanalikon **SDK · skola**, oläst-prick, badge **"Verifierad · LOA3 ·
   08:01"**, countdown **"14 dagar kvar"**, destinations-chip **"→ Treserva — ej registrerad"**. Nästa-åtgärd-etikett:
   *"Plocka för att börja"*.
3. Hon klickar **"Ta/plocka ärendet"**. En inline-hjälptext (första gången): *"Du är nu ansvarig. 14-dagarsklockan
   räknas från att anmälan kom in (08:01 idag), inte från nu."* Raden får hennes namn; en post dyker upp i **Idag**:
   *"Gör skyddsbedömning — SN 2026-0142 (idag)"*.
4. Hon öppnar raden och klickar **"Skyddsbedömning"**. `SkyddsbedomningsKort` ställer den mallstyrda frågan *"Behöver
   barnet omedelbart skydd?"*. Hon dokumenterar "Nej — men förhandsbedömning krävs", trycker **Spara**. UI:t visar
   *"Skyddsbedömning dokumenterad och förd till Treserva 08:09"* (Frends-commit, GAP-001 löst). Hon såg aldrig ett
   facksystem-fönster — bryggan skötte det.
5. Hon klickar **"Skapa ärenderum"**. Ett klick → säker dokumentyta för barnet skapas (ACL: hon skriver, gruppledaren
   läser). Akt 2 får ett nytt rum-kort med **"4-mån-frist: ej startad"** och **fas-etikett "Under förhandsbedömning"**.
   En BBIC-förhandsbedömningsmall ur kunskapsbanken ligger redan i rummet.
6. Under dagen kontaktar hon vårdnadshavaren via **"Skicka säkert meddelande"**. Fas-etiketten gör att UI:t *inte*
   föreslår att kontakta skolan igen ("endast vårdnadshavare/anmälare/barn i denna fas") — hon slipper ett juridiskt
   misstag utan att veta regeln utantill.
7. **Dag 9.** Förhandsbedömningen är klar. I Akt 1 klickar hon **"Beslut: inleda utredning"**. Lågrisk → **"Godkänn"**
   (loggat, ingen signatur). Hon trycker på destinations-chipet **"→ Treserva — ej registrerad"** → ett förifyllt
   registreringsformulär (avsändare/inkom-datum/föreslaget dnr/ärendemening/sekretess) → **Registrera** → Frends svarar
   och chipet flippar till **"Registrerad i Treserva, dnr 2026-IFO-1142 · Hubs-rum gallras 2026-09"**. Akt 2-rummet
   växlar till utredningsläge, fas-etiketten blir "Utredning", och **4-mån-fristen speglas nu från Treserva**.
8. **Tre veckor senare.** Hon bokar ett SIP-möte i Akt 3 (**"Kalla till säkert möte"**), släpper in vårdnadshavaren
   (BankID-verifierad i lobbyn) och skolkuratorn (SITHS). Efter mötet klickar hon **"Transkribera & sammanfatta
   (lokalt)"**. Transkript och AI-utkast visas **sida vid sida**; hon rättar en felhörd detalj och trycker
   **"Godkänn"** (knappen var spärrad tills hon faktiskt scrollat igenom — GAP-029). Den godkända anteckningen förs
   till Treserva; rå-inspelningen får en synlig kort gallrings-countdown.
9. **När utredningen är klar** skriver hon beslutet i Akt 4, **"Skicka för underskrift"** → **AES via Inera** →
   signerar med BankID. `BevarandePanel` visar **"PAdES ✓ · PDF/A-1 ✓ · LTV ✓ — Giltig då"**. Hon klickar **"Delge
   beslut"**, väljer *förenklad delgivning*; `kvittenser` börjar följa tidslinjen, och **överklagandefristen (3 v) dyker
   upp automatiskt i Akt 5** med startdatum härlett ur delgivningssättet. **"För beslut till Treserva"** → Frends
   paketerar signerad PDF/A + valideringsintyg + delgivningsbevis.
10. **16:30, stänga loopen.** I **Idag** klarmarkerar hon dagens steg. Vid en bevakning frågar Hubs **"Gallra
    (personlig notering)"** eller **"För till ärendet/facksystemet"** — med mikrohjälp om skillnaden. Orienteringsraden
    är grön: *"Inga röda frister."* Hon vet, efter bara fyra dagar, exakt var ärende SN 2026-0142 står — för vyn
    berättade hela vägen som en mening uppifrån och ned.

---

*Grundas i `SOCIALSEKRETERARE-WALKTHROUGH.md` (51 steg, Akt I–V), `GAP-ANALYSIS.md`,
`persona-usage-socialsekreterare.md`, `WIDGET-APP-MAP.md`, `PERSONA-DASHBOARD-SPEC.md` (§5.1) och UX-mönstren i
`research-uppgifter.md` (GOV.UK task-list) + `research-personalisering.md` (Card View/Quick View, progressive
disclosure, låst kärna + kuraterat skal, WCAG 2.2 AA). Antagandet "alla blockers lösta" (Frends/Inera/transkribering/
Retention-paus) gör att GAP-001/002/007/017/018/019/029/031/034/044/052 behandlas som åtgärdade i designen.*
