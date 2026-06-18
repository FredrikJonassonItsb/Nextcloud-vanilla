<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# UX-koncept: "Min dag" — socialsekreterarens omdesignade Hubs Start-vy

> **Koncept-id:** `mindag` · **Vinkel:** dag-/tidsstyrd ("Min dag") · **Persona:** `socialsekreterare`
> (barn & familj, SoL 2025:400, BBIC) · **Plattform:** server v32 (Hub 25 Autumn) · **Frontend:** Vue 2.7
> + @nextcloud/vue v8 · **Datum:** 2026-06-14.
>
> **Utgångsproblem:** dagens vy är "widget-soppa" — ~13 parallella widgetar (`attHantera`,
> `orosanmalningar`, `bevakningar`, `arenderum`, `attSignera`, `dagensMoten`, `kvittenser`,
> `funktionsbrevlador`, `minaUppgifter`, `senasteFiler`, `kunskapsbank` + nya `todolista`,
> `motesanteckningar`) som alla skriker samtidigt. Socialsekreteraren måste själv väva ihop dem till en
> arbetsgång. `Min dag` vänder på det: **tiden** (inte widgeten) blir organiserande princip, och vyn
> visar **nästa åtgärd** i stället för allt på en gång.
>
> **Antagande (per uppdrag):** alla blockers är lösta. Treserva-integration via **Frends** (iPaaS) är
> klar (mönster A — verifierad commit-återkoppling finns), **Inera Underskriftstjänst** signering finns,
> laglig + lokal **transkribering** är juridiskt klarställd, **Retention-paus** finns i Hubs. Vyn designas
> därför som ett **skarpt verktyg** — alla åtgärder är riktiga, inga "föreslagen funktion"-reservationer.
>
> **Varumärkesregel:** aldrig "Nextcloud"/"Talk" i UI-text. Interna app-id (sdkmc, spreed-itsl, deck,
> tasks, groupfolders, libresign, stt_whisper2, llm2, files_retention …) används bara i byggnoteringar.

---

## Bärande idé (1 stycke) & varför det är lätt att förstå

**`Min dag` är en kalender-i-prosa för rättssäker handläggning: en enda tidslinje uppifrån och ned —
"nu → idag → snart → bevakat" — där varje rad är en konkret, verb-inledd åtgärd som leder rakt in i rätt
ärende.** I stället för tretton kort som tävlar om uppmärksamheten ser Anna *en* berättelse om sin dag:
överst de frister som brinner och de möten som börjar strax, sedan en prioriterad "Att göra idag"-lista
sorterad efter brådska, och längst ned en lugn "Bevakat & framåt"-horisont som bekräftar att inget
bortom idag är glömt. Det är lätt att förstå därför att **det härmar hur en människa redan tänker på en
arbetsdag** ("vad måste jag göra nu, vad hinner jag idag, vad ligger och väntar?") — samma mentala modell
som en kalender, en GOV.UK task-list och en triage-inkorg, men sammanslagen till en. Den nya
socialsekreteraren behöver inte lära sig vad tretton widgetar gör; hon behöver bara läsa listan uppifrån
och ned och klicka på det översta. Brådskan bärs av **tid till frist** (inte av widget-tillhörighet), så
"varför ligger detta överst?" besvarar sig självt: det förfaller snart. Tom dag är inte ett tomt
gränssnitt utan ett **uttalat kvitto**: *"Inga frister brinner. Inget barn mellan stolarna."*

---

## Informationsarkitektur (zoner uppifrån och ned)

Vyn är **en vertikal kolumn med fyra horisontella zoner** (inte en två-kolumns widget-grid). Ordningen är
strikt tidsstyrd: ju mer akut/nära i tid, desto högre upp. Allt bygger på *en* server-side
aggregeringsendpoint (`/ocs/v2.php/apps/sdkmc/api/v1/summary`, utökad med frist-/mötes-/signerings-feeds)
— ingen klient-fan-out. Progressive disclosure genomgående: varje rad är ett Card View (agera i raden),
klick expanderar Quick View (detalj utan sidbyte), och först "Öppna ärenderum" lämnar `Min dag`.

```
┌───────────────────────────────────────────────────────────────────────┐
│  HÄLSNINGSRAD   "God morgon, Anna · måndag 14 juni"     [säker kanal ●] │  ← identitet + suveränitet
├───────────────────────────────────────────────────────────────────────┤
│  ZON 1 — DAGSPULSEN  (sticky, alltid synlig)                           │
│  ⏰ 2 frister brinner   📹 1 möte 10:00   ✍ 1 att signera   📥 4 nya   │  ← 4 räknare = dagens läge
├───────────────────────────────────────────────────────────────────────┤
│  ZON 2 — NU & STRAX  (tidslinje, det som har en klockslag/deadline idag)│
│   ▸ 09:30  KRITISK · Förhandsbedömning förfaller imorgon — SN 2026-0142│
│   ▸ 10:00  Säkert SIP-möte börjar om 38 min — Barn 2026-0412           │
│   ▸ 13:00  Beslut väntar din underskrift (frist idag) — avslag insats  │
├───────────────────────────────────────────────────────────────────────┤
│  ZON 3 — ATT GÖRA IDAG  (EN prioriterad lista, brådska-sorterad)        │
│   [röd]  Gör förhandsbedömning — SN 2026-0142            frist imorgon  │
│   [gul]  Godkänn mötesanteckning — Barn 2026-0412        idag           │
│   [gul]  Svara BUP (säkert) — Barn 2026-0301             svarsfrist 2d  │
│   [grå]  Skriv utredningsbedömning — Barn 2026-0277      utredn. 12 d   │
├───────────────────────────────────────────────────────────────────────┤
│  ZON 4 — BEVAKAT & FRAMÅT  (lugn horisont, kollapsad som default)       │
│   4-mån-frister · tidsbegränsade beslut · överklagandefrister · möten   │
│   "12 bevakningar framåt — nästa röd om 9 dagar."                       │
└───────────────────────────────────────────────────────────────────────┘
```

**Zon-för-zon — vad man ser, i vilken ordning, varför:**

1. **Hälsningsrad (kontext, inte åtgärd).** Namn + dagens datum/veckodag + en diskret
   `dataSuveranitet`-prick ("Säker kanal · all data i er driftmiljö"). Etablerar *vem*, *när* och
   *att det är säkert* på en rad. Bär även persona-väljaren (multi-roll-kommuner) och Ctrl/Cmd+K.

2. **Zon 1 — Dagspulsen (orienteringen, 3 sek).** En **sticky strip med fyra räknare**: *frister som
   brinner*, *dagens möten*, *att signera*, *nya i inflödet*. Detta är hela dagen komprimerad till fyra
   tal. Klick på en räknare hoppar/filtrerar till motsvarande rader nedan. Ersätter de gamla widget-
   striparna ("2 förhandsbedömningar förfaller inom 3 dagar") med en enhetlig puls. Aldrig enbart färg —
   ikon + tal + text.

3. **Zon 2 — Nu & strax (det klockslags-/deadline-bundna idag).** En **tidslinje** över allt som har en
   tidpunkt idag: frister som förfaller idag/imorgon, dagens säkra möten med nedräkning ("börjar om 38
   min"), dokument vars signeringsfrist är idag. Detta är "det jag inte får missa just nu". Sorteras på
   klockslag/frist stigande. Här bor det gamla `dagensMoten` + de mest akuta raderna ur `bevakningar`
   och `attSignera` — sammanvävda till en linje i stället för tre kort.

4. **Zon 3 — Att göra idag (motorn).** **EN prioriterad arbetslista** — sammansmältningen av `attHantera`
   (inflöde som kräver åtgärd), `minaUppgifter` (arbetssteg), `orosanmalningar` (förhandsbedömningar) och
   `bevakningar` (frister utan fast klockslag). Varje rad är verb-inledd, bär en **brådske-chip** (frist),
   en **kanal-/källikon** och en **destinations-chip** mot Treserva. Sorterad efter brådska (frist →
   sekretessnivå → oläst). Detta är där 80 % av dagen spenderas. Lokal AI (`llm2`) får *föreslå* ordningen
   med synligt "varför" — men den deterministiska sorteringen ligger alltid kvar under.

5. **Zon 4 — Bevakat & framåt (tryggheten, kollapsad).** Allt bortom idag: 4-mån-utredningsfrister,
   tidsbegränsade beslut som ska följas upp, överklagandefrister, kommande möten. **Kollapsad som
   default** (progressive disclosure) — den ska *finnas* men inte *störa*. En rad sammanfattar: "12
   bevakningar framåt — nästa röd om 9 dagar." Expanderar till en mini-tidslinje/kalenderremsa. Detta är
   den emotionella ryggraden: beviset att inget längre fram är glömt.

**Varför denna ordning:** den speglar avtagande brådska (nu → idag → framåt), vilket är exakt den
prioritering en handläggare gör i huvudet. Sticky Zon 1 garanterar att läget alltid syns även vid scroll
(WCAG 2.4.11 Focus Not Obscured respekteras — striplayouten får inte dölja fokuserad rad). Funktioner
utan tidsdimension (`kunskapsbank`/mallar, `senasteFiler`, `funktionsbrevlador`/plocka) flyttas ut ur
huvudflödet till en **diskret verktygsrad/sidofält** — de är *verktyg man når*, inte *dagen man läser*.

---

## Nyckelkomponenter (byggbara i Vue)

> Konvention: varje komponent följer Card View + Quick View, exponerar verb-inledda titlar, har
> ≥24×24 px klickytor (WCAG 2.5.8), och knappbaserad omordning där relevant (2.5.7). Datat kommer ur
> summary-endpointen; status enligt GOV.UK-modellen `Ny · Påbörjad · Väntar på motpart · Klar för beslut
> · Klar` + rött `Åtgärd krävs`.

| # | Komponent (UI-namn / fil) | Syfte | Visar | Åtgärder (verb) |
|---|---|---|---|---|
| 1 | **`MinDagHeader.vue`** (Hälsningsrad) | Identitet, datum, suveränitet, global navigering | "God morgon, Anna · mån 14 juni", `dataSuveranitet`-prick, persona-väljare, Ctrl/Cmd+K-knapp | Öppna kommandopalett · Byt vy (multi-roll) |
| 2 | **`Dagspulsen.vue`** (Zon 1 sticky strip) | 3-sekunders dagsöverblick | 4 räknare: ⏰ frister brinner · 📹 möten idag · ✍ att signera · 📥 nya i inflöde. Varje = ikon+tal+text | Klicka räknare → filtrera/scrolla till rader · "Visa allt inflöde" |
| 3 | **`NuOchStrax.vue`** (Zon 2 tidslinje) | Det klockslags-/deadline-bundna idag | Tidslinje-rader med klockslag/nedräkning: möten ("om 38 min · Anslut"), frister idag/imorgon, signeringsfrist idag. Varje rad = `TidslinjeRad.vue` | **Anslut säkert möte** · **Gör förhandsbedömning** · **Signera** · Öppna ärenderum |
| 4 | **`AttGoraIdag.vue`** (Zon 3, motorn) | Den prioriterade arbetslistan | Verb-rader, brådske-chip, kanalikon, destinations-chip, status. Toggle **Mina / Enhetens**. AI-förslagsbanner (avstängbar) | **Svara säkert** · **Skapa ärenderum** · **Skapa bevakning** · **Plocka** · Klarmarkera · Öppna ärende |
| 5 | **`AttGoraRad.vue`** (radkomponent) | Atomär åtgärdsrad (återanvänds i Zon 2–4) | Titel (verb), `FristChip`, `KanalIkon`, `DestinationsChip`, sekretess-/LOA-badge, "varför"-tooltip vid AI-prio | Primär verb-knapp (kontextuell) · expandera Quick View · Öppna ärenderum |
| 6 | **`FristChip.vue`** | Visualisera brådska/frist enhetligt överallt | Frist-typ + tid kvar + eskaleringsfärg grå→gul(≤3d)→röd(förfallen) + **ikon + text** (aldrig bara färg). T-7/T-3/T-0-markör | Hover/fokus → "Frist: förhandsbedömning 14 dgr, inkom 31/5, förfaller 14/6" |
| 7 | **`DestinationsChip.vue`** | Mellanlagring→facksystem-känslan per rad | "→ Treserva — ej registrerad" / "Registrerad i Treserva, dnr 2026-IFO-1234 · Hubs-rum gallras 2026-09" | Klick → **För över till Treserva** (öppnar registreringsformulär, mönster A via Frends) |
| 8 | **`BevakatFramat.vue`** (Zon 4, kollapsad) | Lugn horisont bortom idag | Mini-tidslinje/kalenderremsa: 4-mån-frister, tidsbegränsade beslut, överklagandefrister, möten. Sammanfattningsrad | Expandera · filtrera på fristtyp · Öppna ärende · Skapa bevakning |
| 9 | **`MotesanteckningKort.vue`** | Transkribering→AI-utkast→godkänn i flödet | Efter möte: "Transkript klart · AI-utkast väntar granskning". Sida-vid-sida transkript ↔ utkast i Quick View | **Granska & godkänn** (tvingad sida-vid-sida) · Redigera · Committa till Treserva |
| 10 | **`SignaturKort.vue`** | Signera/godkänn i flödet | Kravnivå-badge (AES/QES via Inera; lågrisk → "Godkänn"), frist, bevarandepanel "Giltig nu/Giltig då" (PAdES/PDF/A + LTV ✓) | **Signera** (Inera, BankID/Freja/SITHS) · **Godkänn** (loggat) · Delge |
| 11 | **`TomDag.vue`** (empty state) | Förklara tomt tillstånd positivt | "Inga frister brinner idag. Inget barn mellan stolarna." + diskreta genvägar till bevakat/inflöde | Visa veckans bevakningar · Gå till inflöde |
| 12 | **`Verktygsrad.vue`** (sidofält/diskret) | Tidlösa verktyg ut ur huvudflödet | Genvägar: `kunskapsbank` (mallar, låst plats WCAG 3.2.6), `senasteFiler`, `funktionsbrevlador`, `kvittenser` | Öppna mall · Plocka ur funktionsbrevlåda · Visa kvittenstidslinje |

**Tekniska noter för bygget:** alla zoner är barn till en `MinDag.vue`-container som hämtar *en*
summary-payload och fördelar rader till zoner via en ren `zonOf(item)`-selector (klockslag idag → Zon 2;
frist/åtgärd utan klockslag → Zon 3; frist bortom idag → Zon 4). `FristChip`/`DestinationsChip`/
`KanalIkon` är delade presentationskomponenter. AI-lagret är en `aiOrder`-prop som bara *omsorterar* Zon
3 och aldrig filtrerar bort rader; en "Visa oredigerad kö"-knapp återställer deterministisk ordning
(GDPR art. 22, transparens). Persona-väljaren och låst-kärna/kuraterat-skal-logiken ärvs från
`personaConfig.js`/`RoleService` — `Min dag` är socialsekreterarens layout, inte en ny app.

---

## Hur arbetsgången stöds (Akt 1–5 → UI)

`Min dag` är medvetet byggd så att **walkthrough:ens 51 steg aldrig kräver att Anna letar reda på rätt
widget** — varje akt har en naturlig plats i tidslinjen, och varje commit till Treserva sker via samma
`DestinationsChip` (mönster A, Frends), så provenance-känslan är identisk genom hela ärendet.

### Akt 1 — Inflöde & triage (steg 1–10)
- **Var i UI:** En ny orosanmälan (steg 1–2) materialiseras som en **röd/oläst rad i Zon 3 "Att göra
  idag"** med kanalikon (SDK), oläst-markör, `FristChip` ("Förhandsbedömning 14 dgr") och
  `DestinationsChip` ("→ Treserva — ej registrerad"). Otilldelade anmälningar ur funktionsadressen syns
  via **Mina/Enhetens-toggeln** (Enhetens) och i `Verktygsrad → funktionsbrevlador`.
- **Hur Anna leds vidare:** raden bär primärknappen **"Plocka & starta förhandsbedömning"** (steg 4) →
  öppnar Quick View med omedelbar **skyddsbedömning** (steg 3, mallstyrd, statusbunden) och knappen
  **"Skapa ärenderum"** (steg 6). Fristen binds till **inkom-datum** (steg 1), inte plock — `FristChip`
  räknar från provenance-tidsstämpeln. Under förhandsbedömningen visar Quick View en **fas-badge**
  ("Förhandsbedömning — endast vårdnadshavare/anmälare/barn") som varnar vid otillåten kontakt (steg 7).
- **Commit till Treserva:** beslutet "inleda/inte inleda" (steg 8) → `SignaturKort` ("Godkänn" lågrisk /
  AES). Aktualiseringen (steg 9) sker när Anna klickar `DestinationsChip` → **förifyllt
  registreringsformulär POST:as till Treserva via Frends** (mönster A). Chipen flippar till "Registrerad
  i Treserva, dnr 2026-IFO-1234" och **Retention-countdownen startar först på verifierad commit-händelse**
  (steg 10) — inte på en kryssruta.

### Akt 2 — Utredning & ärenderum (steg 11–22)
- **Var i UI:** utredningen lever i **`arenderum`** (nås via "Öppna ärenderum" från valfri ärenderad),
  men `Min dag` håller arbetsstegen synliga som **Zon 3-rader**: "Inhämta uppgifter från skola — Barn
  2026-0412", "Skriv utredningsbedömning". Inkomna säkra filer (steg 14) dyker upp som rader "Spara i
  ärenderum" + i `Verktygsrad → senasteFiler`.
- **Hur Anna leds vidare:** **4-mån-fristen (steg 19)** bor i **Zon 4 "Bevakat & framåt"** med
  eskaleringsfärg och T-7/T-3/T-0; den klättrar upp till Zon 2/3 när den närmar sig. Samtycke (steg 17)
  och säker delning/kommunicering (steg 18) startas via radens verb-knappar; maskerings-/
  sekretessprövningssteget är inbyggt i delningsdialogen.
- **Commit till Treserva:** när utredningstexten är klar (steg 20–21) klickar Anna `DestinationsChip` i
  ärenderummet → BBIC-journalen + bilagor committas via Frends; slutversionen markeras "Förd till
  Treserva", utkasthistoriken gallras, **dubbel countdown** (facksystemets bevarande + Hubs-rensning)
  visas på ärenderum-kortet (steg 22).

### Akt 3 — Möte & transkribering (steg 23–34)
- **Var i UI:** Dagens säkra möte ligger i **Zon 2 "Nu & strax"** med nedräkning ("om 38 min · Anslut")
  och lobby-status (steg 23–25). En-klicks-anslut; BankID-lobby visar "1 i väntrum — vårdnadshavare,
  LOA3 verifierad", Anna släpper in (steg 25). `recording_consent` påtvingat + synlig inspelningsnotis
  (steg 26).
- **Hur Anna leds vidare:** efter mötet (steg 28) landar WebM i ärenderummet och ett
  **`MotesanteckningKort`** dyker upp i Zon 3: "Transkript klart · AI-utkast väntar granskning". Klick →
  Quick View med **transkript och AI-utkast sida vid sida** (steg 29–31, human-in-the-loop tekniskt
  tvingat). Anna rättar, trycker **"Godkänn"** (loggad händelse).
- **Commit till Treserva:** **"För över till Treserva"** på kortet (steg 33) committar den godkända
  anteckningen via Frends; rå-WebM + rått transkript får Retention-gallring (steg 34), och
  **Retention-pausen** aktiveras automatiskt om en utlämnandebegäran registreras.

### Akt 4 — Beslut, signering, delgivning (steg 35–44)
- **Var i UI:** Ett beslut som väntar Annas underskrift ligger i **Zon 2** (om signeringsfrist är idag)
  eller Zon 3, som **`SignaturKort`** med kravnivå-badge (**AES via Inera** för överklagbart avslag).
  Beslutshandlingen tas fram i ärenderummet (steg 35); "Skicka för underskrift" (steg 36) exporterar
  PDF/A-1 och skapar Inera-signeringsärende.
- **Hur Anna leds vidare:** **"Signera"** → BankID/Freja/SITHS (steg 37) → bevarandepanelen "Giltig nu /
  Giltig då" bekräftar PAdES + PDF/A-1 + LTV + tidsstämpel (steg 38). Medsignering följs i Quick View
  (Skickat → Öppnat → Signerat 1 av N, steg 40). **"Delge beslut"** (steg 41) → säker kanal; `kvittenser`-
  tidslinjen (Skickad→Levererad→Öppnad→Inloggad LOA3→Läst) syns i radens Quick View. **Överklagandefristen
  (3 v, steg 42)** skapas automatiskt som Zon 4-bevakning, med startdatum härlett ur delgivningssättet.
- **Commit till Treserva:** **"För över till Treserva"** (steg 43) paketerar signerad PAdES/PDF/A +
  valideringsintyg + delgivningsbevis via Frends → Treserva-akten; FGS-export till e-arkiv vid avslut
  (steg 44) sköts via Treserva.

### Akt 5 — Bevakning & todo (steg 45–51, tvärsnittet)
- **Var i UI:** Detta är **Zon 3 + Zon 4:s själva logik**. Ett inkommande meddelande (steg 45) blir en
  Zon 3-rad; primärknappen **"Skapa bevakning från meddelande"** (steg 46, signaturfunktionen) öppnar
  Quick View med förifylld titel, dnr-länk och föreslagen frist, och **föreslår delad board som default**
  för fristbärande poster (så ingen faller mellan stolarna). Bevakningen refererar till ärenderummet
  (steg 47) men är arbetsmetadata, inte handling.
- **Hur Anna leds vidare:** Hubs lägger T-7/T-3/T-0-påminnelser bara till tilldelad (steg 48); de fyra
  lagstadgade klockorna (steg 49: 14 dgr, 4 mån, tidsbegränsat beslut, FL 6 mån/4 v) modelleras med
  `FristChip` och bor i Zon 4 tills de blir akuta. Vid klarmarkering (steg 51) frågar Hubs **"Gallra
  (personlig notering)"** eller **"För till ärendet/facksystemet"** — och när "för till ärendet" väljs
  committas den formella bevakningen i Treserva (steg 50) och kvarvarande Hubs-påminnelser **avaktiveras**
  så Treserva blir ensam fristägare (riv-mekanismen är byggd).

**Genomgående:** commit sker alltid via `DestinationsChip` (mönster A, Frends, verifierad återkoppling),
så Anna ser samma "→ Treserva — ej registrerad" → "Registrerad, dnr X · gallras Y" på orosanmälan,
utredning, mötesanteckning, signerat beslut och bevakning. Mellanlagring→facksystem-känslan är *en* vana,
lärd en gång.

---

## Frister & sekretess i UI

**Frister — hur de visas (en enda visuell grammatik, `FristChip`):**

| Frist | Lagrum | Startpunkt | Hur den syns i `Min dag` |
|---|---|---|---|
| **Förhandsbedömning 14 dgr** | 11 kap. 1 a §/JO-praxis | **Inkom-datum** (provenance, ej plock) | Zon 3-rad med röd/gul chip; klättrar till Zon 2 dag 13–14. "Inkom 31/5 · förfaller 14/6" |
| **Utredning 4 mån** | 11 kap. 2 § SoL 2025:400 | Utredningsstart | Zon 4 (lugn) tills T-7; speglas från Treserva via Frends så Hubs-frist aldrig divergerar; förlängning syncas (ingen falsk-röd) |
| **Tidsbegränsat beslut — uppföljning** | SoL/Socialstyrelsen | Beslutets slutdatum | Zon 4-rad "Följ upp — insats upphör 30/6"; T-7/T-3 |
| **FL 6 mån + 4 v** | FL 11–12 §§ | Ärendets ålder | Zon 4; röser upp ärenden mot 6-mån-gränsen; "underrättelse om dröjsmål skickad?" |
| **Överklagandefrist 3 v** | FL 44 § | Delgivningsdatum (härlett ur delgivningssätt) | Auto-skapad Zon 4-bevakning vid delgivning (steg 41↔42 kopplade) |

- **Eskalering:** grå (>3 dgr) → gul (≤3 dgr) → röd (förfallen/idag), **alltid ikon + text + färg**
  (WCAG 1.4.1 — färg aldrig enda bärare). Nedräkningsklockor (frister, laga kraft) följer samma regel.
- **Brådska driver position:** en frist *klättrar uppåt* genom zonerna när den närmar sig (Zon 4 → Zon 3
  → Zon 2). Användaren behöver aldrig sortera själv — tiden gör det.
- **"Inget missas"-garantin (compliance-värde):** (1) Zon 1-räknaren *frister brinner* är sticky och kan
  aldrig nå noll utan att alla röda är åtgärdade; (2) Zon 4 sammanfattar "nästa röd om N dagar" så
  horisonten alltid är synlig; (3) varje fristbärande post **föreslås som delad board** (inte privat
  VTODO) så teamet ser den vid frånvaro; (4) 4-mån-fristen **speglas från Treserva** (ägaren), så Hubs
  och facksystemet aldrig visar två olika datum; (5) Retention gallrar **först efter verifierad
  commit**, så en frist/handling aldrig raderas innan den finns i Treserva.

**Sekretess & LOA-markering:**
- **Korttext = ärendereferens, aldrig klartextcitat** (GDPR dataminimering, art. 5). En rad säger "Ny
  orosanmälan — SN 2026-0142", inte innehållet.
- **Behörighet = säkerhetsgräns (`IConditionalWidget`):** en rad från en funktionsbrevlåda Anna saknar
  OSL-behörighet till visas aldrig — inte ens antal/rubrik. Enhetens-toggeln respekterar board-ACL per
  ärende.
- **LOA-/identitets-badge per motpart** (`identitetsBadge`): "Verifierad med BankID · LOA3 · 14:02",
  varningsläge "Ej verifierad — anonym anmälan" (legitimt tillstånd, inte fel), ombud "Erik E. företräder
  Karin K.".
- **Fas-spärr:** under förhandsbedömning markerar fas-badgen tillåtna kontakter; UI varnar innan
  otillåten uppgiftsinhämtning.
- **Diskret suveränitetsprick** i hälsningsraden: "Säker kanal · all data i er driftmiljö · 0
  tredjelandsöverföringar".

---

## Lättbegriplighet & onboarding

**Första 30 sekunderna för en ny socialsekreterare:**
1. **0–5 s:** Hon ser sitt namn, dagens datum och **fyra tal** (Dagspulsen). Hon förstår omedelbart:
   "två frister brinner, ett möte, ett att signera, fyra nya." Ingen jargong, inga widget-namn.
2. **5–15 s:** Hon läser tidslinjen uppifrån. Översta raden är röd och säger med verb vad hon ska göra:
   **"Gör förhandsbedömning — SN 2026-0142 · frist imorgon."** Hon behöver inte veta vilken "widget" det
   är; hon klickar på den översta.
3. **15–30 s:** Quick View öppnas i samma vy (inget sidbyte) med ärendekontext och en tydlig primärknapp.
   Hon agerar. Listan kortas med en. Mönstret är självförklarande och upprepningsbart.

**Etiketter (klarspråk, verb-först, svensk myndighetston):** "Gör förhandsbedömning", "Skapa ärenderum",
"Svara säkert", "Kalla till säkert möte", "Skicka för underskrift", "För över till Treserva", "Skapa
bevakning". Inga interna app-namn, aldrig "Nextcloud"/"Talk". Status enligt GOV.UK minimalmodell.

**Tomma tillstånd (`TomDag`):** aldrig en tom yta. "Inga frister brinner idag. Inget barn mellan
stolarna." + diskreta genvägar (veckans bevakningar / inflöde). Tom kö ramas som **compliance-prestation**,
inte som att appen är trasig. Per-zon: tom Zon 2 → "Inga möten eller deadlines idag"; tom Zon 3 → "Allt
hanterat — bra jobbat."

**Mikrohjälp (progressive disclosure):** varje `FristChip`/`DestinationsChip` har en hover/fokus-tooltip
som förklarar fristen/destinationen i klartext. AI-prioriterade rader visar **"varför"** ("hög prio:
frist imorgon + okänd avsändare"). `kunskapsbank` ligger på **fast plats** i Verktygsraden (WCAG 3.2.6
Consistent Help). En diskret "?"-rundtur kan spela upp 30-sekunders-onboardingen igen.

**WCAG 2.2 AA:** Target Size ≥24×24 px på alla verb-/status-/klarknappar (2.5.8); omordning av kort i
"anpassa vy"-läget har knapp-/tangentbordsalternativ, aldrig bara drag (2.5.7); sticky Dagspulsen får
inte dölja fokuserad rad (2.4.11 Focus Not Obscured); samma ikon = samma funktion mellan roller (3.2.4);
hjälp på fast plats (3.2.6); BankID/Freja/SITHS utan kognitiva test (3.3.8); vyn fungerar i porträtt och
vid 400 % zoom (1.4.10 Reflow / 1.3.4) — viktigt vid hembesök/fält; nedräkningsklockor aldrig enbart
färg (1.4.1).

---

## Primära åtgärder (verb-först, 5)

1. **Gör förhandsbedömning** (plocka orosanmälan + starta 14-dgr-flödet, skyddsbedömning, skapa ärenderum).
2. **Svara säkert / kommunicera** (säkert meddelande till klient/motpart med läskvittens).
3. **Kalla till & anslut säkert möte** (boka tid → auto säkert videorum → BankID-lobby → transkribering).
4. **Skicka beslut för underskrift / Signera** (Inera AES; lågrisk → "Godkänn"; delge med kvittens).
5. **För över till Treserva** (committa handling/bevakning via Frends — den genomgående mellanlagring→
   facksystem-åtgärden, synlig på varje rad).

*(Tillgängliga via radens kontextuella primärknapp och via Ctrl/Cmd+K command palette, rollfiltrerat.)*

---

## Konkret exempel-scenario — ärende SN 2026-0142 genom `Min dag`

> Följer **en** orosanmälan (kommunal triage-referens **SN 2026-0142**; efter aktualisering Treserva-dnr
> **2026-IFO-1234**) steg för steg genom vyn. Måndag 14 juni, 08:00–14:00.

**08:00 — Anna loggar in (Freja eID Plus, LOA3).** `MinDagHeader`: "God morgon, Anna · måndag 14 juni",
suveränitetsprick grön. **Dagspulsen** lyser: **⏰ 2 · 📹 1 · ✍ 1 · 📥 4**. Hon läser tidslinjen
uppifrån.

**08:01 — Zon 2 "Nu & strax", översta raden är röd:**
`▸ KRITISK · Förhandsbedömning förfaller IMORGON — SN 2026-0142 · inkom 31/5 · → Treserva — ej registrerad`.
`FristChip` är röd (1 dag kvar; den kom in 31/5, klockan startade på **inkom-datum**, inte idag). Anna
klickar raden → **Quick View** öppnas i samma vy: anmälan (skola via SDK, avsändare SITHS-verifierad,
LOA3-badge), bilagor, och en **fas-badge** "Förhandsbedömning — endast vårdnadshavare/anmälare/barn".

**08:05 — Hon trycker primärknappen "Plocka & starta förhandsbedömning".** Raden får hennes assignee;
en mallstyrd **skyddsbedömning** visas (redan dokumenterad samma dag den kom in — tidsstämpeln räknad från
provenance). Hon klickar **"Skapa ärenderum"** → ett säkert ärenderum (Groupfolder + ACL: hon skriver,
gruppledare läser + restricted Retention-tagg) skapas i ett klick; anmälan + bilagor läggs där.

**08:20 — Hon kontaktar vårdnadshavaren** via radens **"Svara säkert"** (securemail + BankID-länk). På
vårdnadshavarens senare komplettering klickar hon **"Skapa bevakning från meddelande"** — Hubs förifyller
"Följ upp komplettering — SN 2026-0142", länkar meddelandet, kopplar dnr-token och **föreslår delad
board** (default för fristbärande). Bevakningen landar i **Zon 4 "Bevakat & framåt"**.

**09:40 — Förhandsbedömningen klar: beslut "inleda utredning".** Raden visar `SignaturKort` — lågrisk →
**"Godkänn"** (loggat, ingen BankID). Statusen flippar till "Beslut inleda".

**09:45 — Aktualisering.** Anna klickar `DestinationsChip` **"→ Treserva — ej registrerad"** → ett
**förifyllt registreringsformulär** (avsändare/inkom-datum/föreslaget dnr/ärendemening/sekretess) POST:as
till **Treserva via Frends (mönster A)**. Sekunder senare kommer commit-kvittensen: chipen blir
**"Registrerad i Treserva, dnr 2026-IFO-1234 · Hubs-rum gallras 2026-09"**. **Retention-countdownen
startar nu — på den verifierade commit-händelsen.** Den 14-dgr-röda raden lämnar Zon 2; Dagspulsen: **⏰ 1**.

**10:00 — Zon 2: "Säkert SIP-möte — Barn 2026-0412 · börjar om 0 min · Anslut".** (Ett annat barns
ärende; visar att tidslinjen väver in dagens möten.) Hon ansluter i ett klick, släpper in BankID-
verifierad vårdnadshavare ur lobbyn. Efter mötet dyker ett **`MotesanteckningKort`** upp i Zon 3:
"Transkript klart · AI-utkast väntar granskning". Hon **granskar sida vid sida**, rättar, **"Godkänn"** →
**"För över till Treserva"**; rå-WebM får gallringsklocka.

**11:30 — Tillbaka i SN 2026-0142.** I Zon 3 ligger nu "Skriv utredningsbedömning — SN 2026-0142" (grå
chip, 4-mån-fristen lugn i Zon 4, **speglad från Treserva** så datumet är sant). Hon öppnar ärenderummet,
samredigerar on-prem.

**13:00 — Zon 2: "Beslut väntar din underskrift (frist idag)".** `SignaturKort`, kravnivå **AES via
Inera**. Hon trycker **"Signera"** → BankID → bevarandepanelen "Giltig nu / Giltig då" bekräftar PAdES +
PDF/A-1 + LTV ✓. Dagspulsen: **✍ 0**.

**13:30 — "Delge beslut"** via säker kanal; `kvittenser`-tidslinjen i Quick View börjar ticka
Skickad→Levererad. En **överklagandefrist (3 v)** skapas automatiskt i Zon 4, startdatum härlett ur
delgivningssättet. Hon klickar **"För över till Treserva"** → signerad PDF/A + valideringsintyg +
delgivningsbevis committas via Frends.

**14:00 — Anna scrollar upp.** Dagspulsen: **⏰ 0 · 📹 0 · ✍ 0 · 📥 1**. Zon 2 är tom: *"Inga fler
deadlines idag."* Zon 4 säger lugnt: *"12 bevakningar framåt — nästa röd om 9 dagar."* SN 2026-0142 är
registrerad i Treserva, fristen hölls, barnet föll inte mellan stolarna — och hon **läste aldrig en
widget; hon läste sin dag**.

---

*Grundas i `hubs_start/docs/SOCIALSEKRETERARE-WALKTHROUGH.md` (51 steg, Akt 1–5),
`hubs_start/docs/GAP-ANALYSIS.md` (blockers antagna lösta per uppdrag),
`analysis-output/extended/persona-usage-socialsekreterare.md`, `hubs_start/docs/WIDGET-APP-MAP.md`,
`hubs_start/docs/PERSONA-DASHBOARD-SPEC.md`, `analysis-output/extended/research-personalisering.md`
(Card View/Quick View, låst kärna + kuraterat skal, lokal AI/art. 22, WCAG 2.2) och
`analysis-output/extended/research-uppgifter.md` (GOV.UK task-list, "skapa bevakning från meddelande",
deadline-eskalering). Varumärkesregel: aldrig "Nextcloud"/"Talk" i UI-text.*
