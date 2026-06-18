<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# UX-koncept: Processtavlan — socialsekreterarens omdesignade Hubs Start-vy

> **Koncept-id:** `processboard` · **Persona:** `socialsekreterare` (barn & familj) · **Plattform:** server v32 (Hub 25 Autumn) · **Frontend:** Vue 2.7 + @nextcloud/vue v8 · **Datum:** 2026-06-14
>
> **Vad detta är:** ett komplett UX-omtag av socialsekreterarens förstavy i Hubs Start, som ersätter dagens widget-soppa (~13 parallella widgetar) med **en kanban-tavla där kolumnerna ÄR ärendets processteg**. Grundat i `SOCIALSEKRETERARE-WALKTHROUGH.md` (51 steg / 5 akter), `GAP-ANALYSIS.md`, `persona-usage-socialsekreterare.md`, `WIDGET-APP-MAP.md`, `PERSONA-DASHBOARD-SPEC.md`.
>
> **Antagande (givet):** alla blockers lösta — Treserva via **Frends** (iPaaS), **Inera Underskriftstjänst** för signering, laglig + lokal **transkribering** klarställd, **Retention-paus** finns. Designas som ett SKARPT verktyg; åtgärder är riktiga, inga "föreslagen funktion"-reservationer i UI.
>
> **Varumärkesregel:** aldrig "Nextcloud"/"Talk" i UI-text. I detta interna underlag namnger vi app-id för spårbarhet.

---

## Bärande idé (1 stycke) & varför det är lätt att förstå

**Ett ärende är ett kort. Ett kort rör sig vänster→höger genom socialtjänstens lagstadgade livscykel — och varje kolumn är ett steg i den lagen, inte en personlig att-göra-hög.** Processtavlan visar exakt **sex kolumner** som speglar SoL-processen: **Inkommet & triage → Under förhandsbedömning → Under utredning → Klart för beslut → Bevakas/uppföljning → Avslutat**. Det är lätt att förstå därför att tavlan *är en bild av lagen* — en ny socialsekreterare som aldrig sett Hubs känner ändå igen kolumnerna, eftersom de är samma steg hon lärt sig på socionomutbildningen och i kommunens rutin. Hon behöver inte lära sig en produkt; hon känner igen sin egen process. Och till skillnad från widget-soppan — där tretton parallella paneler tävlar om uppmärksamhet och hon själv måste hålla ihop "var är jag i det här ärendet?" — ger tavlan **rumslig minneshjälp**: ett barns ärende har en *plats*, den platsen *är* dess juridiska status, och kortet *flyttar sig självt* till nästa kolumn när hon committar utfallet till Treserva. Den enda fråga vyn ställer är den enda fråga som betyder något: *vilket är nästa steg för varje barn, och hinner jag det innan fristen?*

---

## Informationsarkitektur (zoner uppifrån och ned)

Vyn är en **vertikal stapel av fyra zoner**. Zon A–B är låst kärna (alltid synliga, compliance-/fristkritiska). Zon C är navet. Zon D är kontextuellt djup som öppnas vid behov (progressive disclosure). Designprincipen: *du ser frister först, processen i mitten, och detaljer bara när du ber om dem.*

### Zon A — Fristbandet (sticky topp, låst)
Det allra första ögat möter. En smal, horisontell **eskaleringsstrip** över hela bredden som sammanfattar veckans rättsliga klockor i klartext, sorterat röd→gul→grå:

> **2 förhandsbedömningar förfaller inom 3 dagar · 1 utredning klar för beslut · 0 röda frister idag**

Bandet är **alltid synligt** (sticky, följer med vid scroll) — det är fristgarantins ankare. Klick på en delmängd filtrerar tavlan till just de korten. Aldrig enbart färg: varje siffra bär ikon + text (WCAG 1.4.1). Detta är `fristStrip`/`bevakningar`-logiken hissad till toppen.

### Zon B — Triagelinjen / inkorgsremsan (under fristbandet, låst)
En horisontell rad av **inkommande som ännu inte är ett kort** — orosanmälningar och säkra meddelanden som ligger i `attHantera`/`funktionsbrevlador` men inte är triagerade till en kolumn än. Visas som en tunn "väntar på dig"-ficka till vänster om tavlan (kan tänkas som **kolumn 0 / inflödesficka**). Varje post: kanalikon (SDK/säker e-post/digital fax), avsändarens LOA-badge, inkom-tidsstämpel, **14-dgr-countdown som redan tickar** (bunden till inkom-datum, inte plock — löser GAP-002/046). Primär gest: dra eller klicka **"Ta ärendet → triagera"**, varpå posten blir ett kort i kolumn 1.

> Varför separat zon: inflödet (rådata) och processen (ärenden) är *olika mentala lägen*. Triage = "ska detta bli ett ärende, och vems?". Att hålla isär dem är hela poängen med att inte vara en mejlinkorg.

### Zon C — Processtavlan (navet, fyller resten av skärmen)
De **sex processkolumnerna**, vänster→höger, varje kolumn med rubrik + räknare ("Under utredning · 9") + en diskret fristsammanfattning för kolumnen. Korten är **ärenden (barn/dnr)**. Detta är vyns hjärta och beskrivs i detalj nedan. På mobil/400 %-zoom kollapsar tavlan till **en kolumn i taget med en stegväljare** (Reflow, WCAG 1.4.10) — man swajpar mellan stegen, kortordningen bevaras.

### Zon D — Ärendepanel (Quick View, glider in från höger)
Klick på ett kort öppnar **inte** en ny sida — en panel glider in över höger tredjedel (Card View → Quick View, Viva-mönstret). Här bor allt djup för *ett* ärende: tidslinje, dokument i ärenderummet, kontakter/kvittenser, fristdetaljer, transkriberingar, och **den enda primära åtgärdsknappen för just detta steg**. Resten av tavlan dimmas men syns. Esc/klick utanför stänger. Detta ersätter sju av de gamla widgetarna (`arenderum`, `kvittenser`, `senasteFiler`, `motesanteckningar`, `attSignera`, `dagensMoten`, `minaUppgifter`) — de blir **flikar i ärendepanelen**, inte parallella paneler på startsidan.

> **Nettoeffekten på kognitiv last:** av ~13 samtidiga widgetar blir den synliga ytan vid varje given sekund: 1 fristband + 1 inflödesficka + 6 kolumner med kort + (vid behov) 1 panel. Allt annat är *inuti* ett kort eller en panel — det finns men tränger sig inte på.

---

## Nyckelkomponenter (byggbara i Vue)

Namngivna komponenter med syfte, vad de visar, åtgärder och vilket befintligt datalager de står på. Kolumn-id i kod inom parentes.

### 1. `FristStrip` — Fristbandet (Zon A)
- **Syfte:** garantera att inget med en lagstadgad klocka kan missas oavsett var det ligger på tavlan.
- **Visar:** aggregerade frister veckan, röd/gul/grå, klartext + ikon + antal. De fyra lagklockorna (14 dgr förhandsbedömning, 4 mån utredning, FL 6-mån/4-veckor, tidsbegränsade beslut).
- **Åtgärder:** klick på delmängd → filtrera tavlan; "Visa alla frister" → öppnar fristlista i Zon D.
- **Datalager:** `deck` + `tasks` (VTODO/VALARM T-7/T-3/T-0), speglat ur `summary`-endpoint. Fristen *läses* från Treserva via Frends där den ägs där (löser dubbelbevaknings-GAP-018/047).
- **Props:** `frister: Frist[]`, `filterActive: string|null`. **Events:** `@filter(fristId)`.

### 2. `Inflodesficka` — Triagelinjen (Zon B, "kolumn 0")
- **Syfte:** skilja otriageerat inflöde från processade ärenden; binda 14-dgr-klockan till inkom-datum.
- **Visar:** rader av inkommande (orosanmälan/säkert meddelande/komplettering), kanalikon, avsändar-LOA-badge ("Verifierad BankID · LOA3" / "Ej verifierad · anonym" — GAP-053 som legitimt tillstånd), inkom-tid, tickande countdown.
- **Åtgärder:** **"Ta ärendet"** (plock → blir kort i kolumn 1, tilldelning loggas), "Öppna meddelande", "Skapa bevakning från meddelande".
- **Datalager:** `sdkmc` summary-endpoint + `funktionsbrevlador` (behörighet = OSL-säkerhetsgräns, `IConditionalWidget`).
- **Props:** `inflode: Inkommande[]`. **Events:** `@plock(id)`, `@triagera(id, kolumn)`.

### 3. `Processtavla` — tavelbehållaren (Zon C)
- **Syfte:** rendera de sex kolumnerna och hantera kort-flytt + filter + WCAG-omordning.
- **Visar:** sex `ProcessKolumn`, kolumnräknare, kolumn-fristsammanfattning, tom-tillstånd per kolumn.
- **Åtgärder:** drag-and-drop **och** knappbaserad "Flytta till nästa steg ▸" (WCAG 2.5.7 — aldrig bara drag); sortering inom kolumn (frist/uppdaterad/namn).
- **Datalager:** Hubs egen tavellogik ovanpå `tables` (status-/fristregister) som speglar varje ärende; kortflytt = statusändring.
- **Props:** `arenden: Arende[]`, `aktivtFilter`. **Events:** `@flytta(arendeId, frånKolumn, tillKolumn)`, `@oppnaArende(id)`.

### 4. `ProcessKolumn` — en processkolumn
- **Syfte:** representera **ett juridiskt steg** och dess regler.
- **Visar:** rubrik (verb-/tillstånds-formulerad, se nedan), antal, "X med röd frist", och **fas-regel-rad** när relevant (t.ex. i *Under förhandsbedömning*: "Endast vårdnadshavare, anmälare, barn får kontaktas" — gör GAP-006/013 till synlig spärr).
- **De sex kolumnerna (id):**
  1. **Inkommet & triage** (`inkommet`) — Akt I steg 1–5
  2. **Under förhandsbedömning** (`forhandsbedomning`) — Akt I steg 6–8
  3. **Under utredning** (`utredning`) — Akt II + Akt III
  4. **Klart för beslut** (`beslut`) — Akt IV steg 35–40
  5. **Bevakas / uppföljning** (`bevakas`) — Akt IV steg 41–42 + Akt V
  6. **Avslutat** (`avslutat`) — Akt IV steg 43–44, kollapsad som standard
- **Åtgärder:** kollapsa/expandera; "Visa avslutade" (kolumn 6 är hopvikt by default för fokus).

### 5. `ArendeKort` — kortet (ett barn/dnr)
- **Syfte:** sammanfatta ett ärendes status så att nästa åtgärd är uppenbar på 1 sekund.
- **Visar (kompakt):**
  - **Ärendetitel:** ärendereferens, inte klartextcitat (GDPR dataminimering) — t.ex. "Barn SN 2026-0142" + dnr-chip om registrerad.
  - **Sekretess-/LOA-rad:** OSL-låsikon + "OSL 26 kap." + ev. "skyddade uppgifter".
  - **Fristmätare:** liten horisontell stapel grå→gul→röd med **klartext** ("Förhandsbedömning · 3 dgr kvar"), aldrig bara färg.
  - **Provenance-/destinations-chip:** "→ Treserva — ej registrerad" eller "Registrerad i Treserva · dnr 2026-IFO-1234" (löser "var hamnar det", GAP-049).
  - **Nästa-åtgärd-rad:** *en* verb-formulerad mening: "▸ Fatta beslut: inleda/inte inleda".
- **Åtgärder:** klick → öppna Zon D; "Flytta till nästa steg ▸" (med commit-grind, se nedan); "Skapa bevakning".
- **Datalager:** `arenderum` (groupfolders+ACL+Retention) + `tables` (status/frist) + provenance ur Frends-svar.
- **Props:** `arende: Arende`. **Events:** `@oppna`, `@flytta`, `@bevakning`.

### 6. `ArendePanel` — Quick View (Zon D)
- **Syfte:** allt djup för ett ärende utan sidbyte; hem för det som var sju widgetar.
- **Flikar (progressive disclosure):**
  - **Översikt:** tidslinje (inkom → triage → … → nu), aktuellt steg, fristdetaljer, fas-regler.
  - **Ärenderum:** dokument i Groupfoldern, versioner, "Dela utvalda handlingar säkert" (med maskeringsvarning, GAP-017), `senasteFiler`-flöde.
  - **Kontakter & kvittens:** in-/utgående säkra meddelanden, identitets-badge per motpart, leveranstidslinje (`kvittenser`).
  - **Möte & anteckning:** boka säkert möte, lobbystatus, transkribering + AI-utkast + **human-in-the-loop sida-vid-sida granska/godkänn** (GAP-029).
  - **Beslut & signering:** beslutsmall, "Skicka för underskrift (AES via Inera)", bevarandepanel "Giltig nu/Giltig då", "Delge".
- **Åtgärder:** stegets primära verb är **alltid en stor knapp längst ned i panelen** — "Committa till Treserva", "Signera", "Delge", "Godkänn anteckning".
- **Datalager:** allt ovan.

### 7. `CommitGrind` — överföringsdialogen (tvärgående, kritisk)
- **Syfte:** göra "för över till Treserva via Frends" till ett **synligt, verifierat ögonblick** — och knyta kort-flytt till bekräftad commit (löser GAP-007/019).
- **Visar:** vad som förs över (handling + provenance + ev. signatur/kvittens), destination (Treserva-akt/dnr), och **Frends-status: skickat → bekräftat (API-svar) → registrerat**.
- **Åtgärder:** "För över"; vid bekräftat API-svar flippar provenance-chip och **kortet flyttar sig till nästa kolumn av sig självt**. Retention-rensningsklockan startar *först efter* bekräftad commit (inte vid en kryssruta).
- **Datalager:** Frends iPaaS-konnektor mot Treserva; återkvittens → `tables`/provenance.

### 8. `OnboardingLager` — första-gången-hjälp (tvärgående)
- **Syfte:** 30-sekunders begriplighet för ny socialsekreterare.
- **Visar:** en gång: korta coach-bubblor på de sex kolumnrubrikerna ("Här tickar 14-dagarsklockan"); tomma tillstånd med mikrohjälp; `kunskapsbank`-ikon på fast plats (WCAG 3.2.6).

---

## Hur arbetsgången stöds (Akt 1–5 mappade till UI)

Genomgående regel: **kortets kolumn = ärendets juridiska status; en commit till Treserva via Frends = det som flyttar kortet.** Handläggaren leds vidare av att varje kort bär *exakt en* nästa-åtgärd och varje kolumn *exakt en* fasregel.

### Akt I — Inflöde, triage, aktualisering (steg 1–10) → Zon B + kolumn 1→2
- **Var:** Orosanmälan landar i **Inflödesfickan** (Zon B) med tickande 14-dgr-countdown (bunden till inkom-datum). Anna ser den i `attHantera`-linjen, plockar via **"Ta ärendet"** → posten blir ett `ArendeKort` i **kolumn 1 (Inkommet & triage)**.
- **Skyddsbedömning (steg 3, GAP-001):** kortet visar en **röd pliktmarkör "Skyddsbedömning krävs idag"** som *måste* kvitteras innan kortet kan lämna kolumn 1 — och kvitteringen committas direkt till Treserva via CommitGrind (skyddsbedömningen får inte bara ligga i Hubs).
- **Ärenderum + förhandsbedömning (steg 6–8):** "Skapa ärenderum" i ArendePanel orkestrerar Groupfolder+ACL+Retention; kortet flyttas till **kolumn 2 (Under förhandsbedömning)**, vars fasregel-rad lyser: "Endast vårdnadshavare/anmälare/barn får kontaktas".
- **Beslut + aktualisering (steg 8–9):** nästa-åtgärd "Fatta beslut: inleda/inte inleda". Vid "inleda" → **CommitGrind** för över anmälan+beslut till Treserva via Frends; provenance-chip flippar till "Registrerad i Treserva · dnr"; kortet glider till **kolumn 3 (Under utredning)** och 4-månadersklockan startar. Vid "inte inleda" → kortet går till **kolumn 6 (Avslutat)**.

### Akt II — Utredning i ärenderummet (steg 11–22) → kolumn 3, ArendePanel-fliken "Ärenderum"
- **Var:** kortet bor i **kolumn 3**. Allt utredningsarbete sker i **ArendePanel → Ärenderum/Kontakter**: BBIC-struktur ur `kunskapsbank`, inhämta handlingar (sparas i Groupfolder), samredigera on-prem (Collabora/WOPI), samtycke via Forms+BankID, "Dela utvalda handlingar säkert" med **maskeringsvarning** (GAP-017).
- **Frist:** kortets fristmätare = 4-månadersklockan, *speglad från Treserva via Frends* (Hubs räknar inte själv → ingen divergens).
- **Committa utredning (steg 20–21):** "Färdigställ → För över BBIC-journal till Treserva" via CommitGrind. Utkasthistoriken gallras (Retention) *efter* bekräftad commit.

### Akt III — Säkert möte, transkribering, AI-utkast (steg 23–34) → kolumn 3, ArendePanel-fliken "Möte & anteckning"
- **Var:** mötesflödet är en flik i ArendePanel, inte en egen startsidewidget. "Kalla till säkert möte" → bokningsbar tid + auto-säkert rum + BankID-lobby. Inspelning med påtvingat samtycke.
- **Transkribering + AI (steg 29–31):** "Transkribera & sammanfatta (lokalt)" → KB-Whisper → llm2-utkast. **Human-in-the-loop tvingas:** transkript och utkast visas **sida vid sida**; "Godkänn" loggas (GAP-029). En bevakningspost "Granska & godkänn mötesanteckning" syns på kortet tills den stängs.
- **Committa (steg 33):** godkänd anteckning → CommitGrind → Treserva. Rå-WebM/-transkript får kort Retention-klocka (pausbar vid utlämnandebegäran, GAP-031).

### Akt IV — Beslut, signering, delgivning, arkivering (steg 35–44) → kolumn 4→5→6
- **Klart för beslut (kolumn 4):** ett ärende vars utredning är klar flyttas hit — det är **signaturen att "nu ska någon fatta och signera"**. ArendePanel-fliken "Beslut & signering": skriv beslut, "Skicka för underskrift (AES via Inera)", bevarandepanel "Giltig nu/Giltig då".
- **Delge + frist (steg 41–42):** "Delge beslut" → securemail/SDK + `kvittenser`-tidslinje; vid delgivning skapas automatiskt en **överklagandefrist-bevakning**, och kortet flyttas till **kolumn 5 (Bevakas/uppföljning)**.
- **Committa beslut (steg 43):** signerad PAdES/PDF/A + valideringsintyg + delgivningsbevis → CommitGrind → Treserva (system-of-record-ögonblicket). Vid ärendeavslut → **kolumn 6 (Avslutat)**, FGS-export hanteras via Treserva.

### Akt V — Bevakning & todo, tvärsnittet (steg 45–51) → kolumn 5 + FristStrip + kortens bevakningar
- **Var:** **kolumn 5 (Bevakas/uppföljning)** är hemvist för ärenden som väntar (överklagandefrist, tidsbegränsat beslut, uppföljning) — och **FristStrip** (Zon A) är tvärsnittet som lyfter *alla* kolumners frister till toppen.
- **Skapa bevakning från meddelande (steg 46):** knapp i Inflödesfickan/kort; Hubs **föreslår delad board som default** för fristbärande poster (GAP-042/049).
- **Klarmarkering: gallra vs för till ärendet (steg 51):** den juridiskt känsligaste interaktionen får en **explicit val-dialog med tydlig default** ("För till ärendet/facksystemet" för allt fristbärande); kvarvarande Hubs-påminnelser avaktiveras så Treserva blir ensam fristägare (GAP-044). Gallring sker bara efter verifierad commit.

---

## Frister & sekretess i UI

**Designaxiom: en frist får aldrig bo på bara ett ställe, och aldrig signaleras med bara färg.** Tre samverkande lager garanterar "inget missas":

1. **FristStrip (Zon A)** — det aggregerade ankaret. Visar veckans klockor i klartext: "2 förhandsbedömningar förfaller inom 3 dagar". Sticky, alltid synligt.
2. **Kortets fristmätare** — per ärende, grå→gul (≤3 dgr)→röd (förfallen), med **klartext** ("Utredning · 12 dgr kvar"). Eskaleringsfärgen kompletteras alltid av text + ikon (WCAG 1.4.1, 1.4.11).
3. **Bevakningsmotorn (osynlig)** — `tasks`/VTODO-VALARM T-7/T-3/T-0, bara till tilldelad; speglar Treservas frist via Frends där den ägs där.

**De fyra lagklockorna, konkret i UI:**
- **14 dgr förhandsbedömning** — startar på **inkom-datum** (Inflödesfickans countdown tickar redan innan plock; GAP-002/046/055 lösta). Bärs av kolumn 1→2.
- **4 mån utredning** — kortets fristmätare i kolumn 3, **speglad från Treserva** (inte självräknad → ingen falsk-röd vid förlängning, GAP-047). Förlängningsbeslut syns som ny frist.
- **FL 6 mån / 4 veckor** — modelleras som bevakningstyp i kolumn 5; FristStrip lyfter den när parten kan begära avgörande.
- **Tidsbegränsat beslut (uppföljning)** — bevakning på slutdatum i kolumn 5 ("Följ upp — insats upphör 30/6"), T-7/T-3-påminnelse.

**"Inget missas"-garantin tekniskt:**
- Ett kort med **röd pliktmarkör** (skyddsbedömning, förfallen frist) kan **inte tyst lämna sin kolumn** — flytt kräver att plikten kvitteras/committas (CommitGrind).
- **Retention-rensning startar först efter verifierad Frends-commit** — aldrig vid en manuell kryssruta (GAP-007). Pausbar vid utlämnandebegäran (GAP-031).
- **Tom "ej registrerad"-kö är en compliance-KPI:** alla kort som visar "→ Treserva — ej registrerad" är en öppen åtgärd; målet är noll (GAP-049).

**Sekretess & LOA i UI:**
- **OSL-låsikon + "OSL 26 kap."** på varje kort; korttext default = ärendereferens, inte klartextcitat (GDPR dataminimering).
- **Behörighet = säkerhetsgräns:** ett kort/en funktionsbrevlåda Anna saknar OSL-behörighet till renderas inte ens (`IConditionalWidget`); kollega utan behörighet ser inte att ärenderummet finns (ACL = least permission).
- **Fasregel som synlig spärr:** kolumn 2 (förhandsbedömning) visar "Endast vårdnadshavare/anmälare/barn"; försök att kontakta utomstående part varnar (GAP-006/013).
- **LOA-badge per motpart:** "Verifierad BankID · LOA3 · 2026-06-13 14:02" / "Ej verifierad · anonym" som **legitimt** tillstånd (GAP-053).
- **Diskret datasuveränitets-markör** ("Säker kanal · all data i er driftmiljö") fast i sidfoten.

---

## Lättbegriplighet & onboarding

**Första 30 sekunderna för en ny socialsekreterare:**
1. **0–5 s:** Hon ser sex kolumner med rubriker hon känner igen från lagen/rutinen. *"Det här är min process."* Ingen inlärning av produktbegrepp krävs.
2. **5–15 s:** Fristbandet överst säger i klartext vad som brådskar. Hon vet vad hon ska göra först innan hon klickat någonstans.
3. **15–30 s:** Hon ser sina egna barn-kort ligga i den kolumn som matchar var hon vet att ärendet är. Ett kort har en röd "3 dgr kvar"-mätare och texten "▸ Fatta beslut: inleda/inte inleda". Hon förstår nästa steg utan att fråga någon.

**Etiketter (verb-/tillstånds-först, GOV.UK-mönster, svensk myndighetston):**
- Kolumnrubriker = tillstånd: *Inkommet & triage · Under förhandsbedömning · Under utredning · Klart för beslut · Bevakas/uppföljning · Avslutat.*
- Nästa-åtgärd = verb: *Ta ärendet · Skapa ärenderum · Fatta beslut · Kalla till säkert möte · Skicka för underskrift · Delge beslut · För över till Treserva.*
- Status = GOV.UK-minimal: *Ny · Påbörjad · Väntar på motpart · Klar för beslut · Klar* + rött *Åtgärd krävs.*

**Tomma tillstånd (varje kolumn har ett):**
- Kolumn 1 tom: "Inga otriageerade ärenden. Nytt inflöde dyker upp i inflödesfickan överst."
- Kolumn 4 tom: "Inga ärenden väntar på beslut just nu."
- Tom tavla: "Inga aktiva ärenden. När en orosanmälan kommer in landar den i inflödesfickan och du triagerar den hit."
- **Tom kö = compliance-värde**, inte tråkig skärm — "Inget barn mellan stolarna."

**Mikrohjälp & onboarding:**
- Engångs-coachbubblor på kolumnrubrikerna (avstängbara): "Här tickar 14-dagarsklockan."
- "?"-ikon per kolumn → kort regelförklaring ur `kunskapsbank` (fast plats, WCAG 3.2.6 Consistent Help).
- Varje primär knapp har ett konsekvensförtydligande ("För över till Treserva — handlingen blir allmän handling i akten").

**WCAG 2.2 AA (DOS-lagen / EN 301 549):**
- **2.5.7 Dragging Movements:** kort flyttas både med drag **och** "Flytta till nästa steg ▸"-knapp/tangentbord.
- **1.4.10 Reflow / 1.3.4 Orientation:** tavlan kollapsar till en-kolumn-i-taget vid 400 % zoom och i porträtt (mobilt hembesök).
- **2.4.11 Focus Not Obscured:** sticky fristband/panel får inte dölja fokus.
- **1.4.1 / 1.4.11:** frist-/statusfärg aldrig ensam informationsbärare — alltid ikon + text; kontrast ≥3:1 på mätare.
- **2.5.5/Target Size:** status-/flyttknappar ≥24×24 px.
- **3.3.8 Accessible Authentication:** BankID/Freja/SITHS utan kognitiva test.
- **3.2.4 Consistent Identification:** samma ikon = samma funktion mellan kolumner.

---

## Primära åtgärder (verb-först)

De fem primära åtgärderna, alltid nåbara via Ctrl/Cmd+K-palett och kontextuellt på kort/panel:

1. **Ta emot & triagera orosanmälan** (Inflödesfickan → kort i kolumn 1)
2. **Skapa ärenderum** (kolumn 1→2, orkestrerar Groupfolder+ACL+Retention)
3. **Kalla till säkert möte** (ArendePanel → Möte; bokningsbar tid + säkert rum + BankID-lobby)
4. **Skicka beslut för underskrift** (ArendePanel → Beslut; AES via Inera)
5. **För över till Treserva** (CommitGrind; via Frends — flyttar kortet till nästa kolumn vid bekräftad commit)

---

## Konkret exempel-scenario: ärende **SN 2026-0142** genom vyn

> Anna, socialsekreterare barn & familj. Måndag 08:00. Hon loggar in med Freja eID Plus (LOA3).

**08:00 — Inflödet.** Tavlan öppnas. **Fristbandet** överst säger: *"1 förhandsbedömning förfaller inom 3 dagar."* I **inflödesfickan** (Zon B) ligger en ny rad: kanalikon SDK, "Verifierad BankID · LOA3", inkom fredag 16:20, och en countdown som redan visar **13 dgr kvar** (klockan startade på inkom-datum, inte nu). Anna klickar **"Ta ärendet"**. En val-dialog föreslår dnr-token; hon bekräftar. Raden blir ett **kort "Barn SN 2026-0142"** i **kolumn 1 (Inkommet & triage)**.

**08:05 — Skyddsbedömning.** Kortet bär en röd pliktmarkör: **"Skyddsbedömning krävs idag"**. Anna klickar kortet → **ArendePanel** glider in. Hon fyller den mallstyrda skyddsbedömningen (barnet behöver inte omedelbart skydd) och trycker **"Kvittera & committa"**. CommitGrind för över bedömningen till Treserva via Frends; provenance-chipet på kortet flippar inte än (ärendet är inte aktualiserat), men plikten är kvitterad och den röda markören släcks. Nu *kan* kortet lämna kolumn 1.

**08:15 — Skapa ärenderum → förhandsbedömning.** I panelen klickar Anna **"Skapa ärenderum"**. Groupfolder+ACL (hon: skriv, gruppledare: läs) + Retention-tagg orkestreras i ett klick. Hon drar kortet (eller trycker **"Flytta till nästa steg ▸"**) till **kolumn 2 (Under förhandsbedömning)**. Kolumnens fasregel-rad lyser: *"Endast vårdnadshavare/anmälare/barn får kontaktas."* Kortets fristmätare är nu gul: *"Förhandsbedömning · 3 dgr kvar"* — samma frist FristStrip varnade för 08:00.

**08:30 — Kontakt inom ramen.** I panelens flik **Kontakter** skickar Anna ett säkert meddelande till vårdnadshavaren (securemail + BankID-länk). `kvittenser`-tidslinjen börjar ticka: Skickad → Levererad. Hade hon försökt kontakta barnets skola hade fasregeln varnat ("utomstående uppgiftsinhämtning först i utredning").

**Onsdag — Beslut: inleda utredning.** Kortets nästa-åtgärd: **"▸ Fatta beslut: inleda/inte inleda".** Anna fattar "inleda utredning". **CommitGrind** öppnas: visar anmälan + förhandsbedömning + beslut, destination Treserva, Frends-status *skickat → bekräftat → registrerat*. Vid bekräftat API-svar flippar provenance-chipet till **"Registrerad i Treserva · dnr 2026-IFO-1234"**, och kortet **glider av sig självt till kolumn 3 (Under utredning)**. 4-månadersklockan dyker upp på kortet — *speglad från Treserva*, grön: "Utredning · 118 dgr kvar".

**Vecka 3 — Utredning + säkert möte.** Kortet ligger i kolumn 3. I panelens **Ärenderum**-flik bygger Anna BBIC-strukturen ur `kunskapsbank`, sparar inhämtade handlingar (skola, BUP), samredigerar utredningstexten on-prem. I **Möte & anteckning**-fliken kallar hon till SIP-möte (bokningsbar tid → säkert rum → BankID-lobby). Efter mötet: **"Transkribera & sammanfatta (lokalt)"** → KB-Whisper-transkript + llm2-utkast visas **sida vid sida**; Anna rättar och trycker **"Godkänn"** (loggat). CommitGrind för över den godkända anteckningen till Treserva; rå-WebM får en kort, pausbar Retention-klocka.

**Vecka 14 — Klart för beslut.** Utredningen är klar. Anna trycker **"Flytta till nästa steg ▸"** → kortet går till **kolumn 4 (Klart för beslut)** — den synliga signalen "nu ska beslut fattas och signeras". I **Beslut & signering**-fliken skriver hon beslutet (mall), trycker **"Skicka för underskrift (AES via Inera)"**. Hon signerar med BankID; bevarandepanelen visar *PAdES + PDF/A-1 ✓ · LTV ✓ · Giltig då ✓*.

**Samma vecka — Delgivning.** **"Delge beslut"** → securemail + BankID-länk; `kvittenser` visar Skickad → Levererad → Notis öppnad → Inloggad LOA3 → **Läst**. Vid delgivning skapas automatiskt en **överklagandefrist-bevakning** (3 v från delgivningsdatum, härledd ur delgivningssättet), och kortet **flyttas till kolumn 5 (Bevakas/uppföljning)**. CommitGrind för över signerad PAdES/PDF/A + valideringsintyg + delgivningsbevis till Treserva.

**Senare — Bevakas → Avslutat.** Kortet vilar i **kolumn 5** med överklagandefristen synlig i FristStrip. När fristen löpt ut och ärendet avslutas klarmarkerar Anna: val-dialogen **"Gallra (personlig notering)" vs "För till ärendet/facksystemet"** med default på det senare. Kvarvarande Hubs-påminnelser avaktiveras (Treserva ensam fristägare). Kortet glider till **kolumn 6 (Avslutat)**, som är hopvikt by default. Hubs-kopian får sin rensningsklocka — som *startade först vid den bekräftade Frends-committen*, aldrig tidigare.

**Nettot:** Anna har följt SN 2026-0142 från orosanmälan till arkiverat, signerat beslut genom att läsa kort röra sig vänster→höger genom lagen — utan att en enda frist legat i huvudet, och med varje bestående handling bevisligt landad i Treserva innan Hubs-kopian rörs.

---

*Grundat i `hubs_start/docs/SOCIALSEKRETERARE-WALKTHROUGH.md`, `hubs_start/docs/GAP-ANALYSIS.md`, `analysis-output/extended/persona-usage-socialsekreterare.md`, `hubs_start/docs/WIDGET-APP-MAP.md`, `hubs_start/docs/PERSONA-DASHBOARD-SPEC.md`, `analysis-output/extended/research-personalisering.md`. Antagande: alla blockers lösta (Treserva via Frends, Inera-signering, laglig+lokal transkribering, Retention-paus).*
