<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Ark-3 — Mottagning → tilldelning: chefen delar ut ärendet

> **Vad detta är:** designsvaret på frågan *"vem äger ett ärende innan det blir mitt, och hur fördelar chefen
> ut det?"* — den organisatoriska sanning vi hittills gömt bakom verbet **"plocka"** i
> `SOCIALSEKRETERARE-WALKTHROUGH.md` (steg 4) och `funktionsbrevlador`-widgeten. I verklig svensk
> barn-och-familj-socialtjänst *plockar* en handläggare sällan fritt ur en delad korg. En **mottagningsgrupp**
> äger inflödet och förhandsbedömningen; en **gruppledare / 1:e socialsekreterare (chef)** **fördelar**
> ärendet till en namngiven **utredare** vid eller efter beslutet att inleda utredning. Det här dokumentet
> designar de tre rollerna, var i flödet tilldelningen sker, och **hur** tilldelningen tekniskt bär
> ärende-identiteten i Hubs (assignee-tagg, ärenderum/Deck-kort, ACL) — plus den lättviktiga
> **fördelningsvyn** chefen behöver.
>
> **Persona-kärna:** `socialsekreterare` (utredare, barn & familj). **Nya roller detta dokument inför:**
> `mottagningssekreterare` (variant av personan), `gruppledare` (chef/fördelare). **System of record:**
> Treserva/Lifecare/Viva/Combine. **Plattform:** server v32 (Hub 25 Autumn). **Datum:** 2026-06-14.
>
> **Antagande (per uppdragsserie):** alla blockerare lösta — Treserva-commit via **Frends** (verifierad
> återkoppling), **Inera Underskriftstjänst**, lokal transkribering, **Retention-paus**. Tilldelningen
> designas som ett **skarpt** verktyg.
>
> **Varumärkesregel (enforced):** i produkt-/UI-text aldrig "Nextcloud"/"Talk". Vi säger *Hubs, korg,
> funktionsadress, ärenderum, fördelningsvy, bevakning*. Interna app-id (sdkmc, deck, groupfolders, circles,
> tasks, workflow_engine, activity) nämns bara i byggnoteringar.
>
> **Grundas i:** `SOCIALSEKRETERARE-WALKTHROUGH.md` (steg 1–10), `persona-usage-socialsekreterare.md`
> (08:15 "plocka & fördela"), `GAP-ANALYSIS.md` (GAP-002/046 fristens start, GAP-051 ACL-granularitet,
> GAP-054 routing-regel funktionsadress), `UX-REDESIGN-SOCIALSEKRETERARE.md` (Zon 1 "Att ta emot"),
> `arendehantering-map.md`, `native-apps-map.md`, `WIDGET-APP-MAP.md`.

---

## 1. Den organisatoriska sanningen (varför "plocka" inte räcker)

En enhet för barn och unga är i normalfallet uppdelad i **två arbetsgrupper med var sin gruppledare**: en
**mottagningsgrupp** (~9 socialsekreterare + gruppledare) som tar emot och förhandsbedömer allt inflöde, och
en **utredningsgrupp** som driver de utredningar som mottagningen beslutat inleda. Mottagningssekreteraren är
*specialist på inflöde och förhandsbedömning* — inte den som driver utredningen vidare. Det är när beslutet
"inleda utredning" fattas som ärendet **byter grupp och byter ägare**: gruppledaren **fördelar** det till en
namngiven utredare (ofta på ett kort dagligt **fördelningsmöte**).

Detta har tre konsekvenser som vår nuvarande "plocka ur funktionsbrevlådan"-modell missar:

1. **Tilldelning är en chefshandling, inte självbetjäning.** Den fria "plocka"-knappen passar mottagnings­
   gruppens *interna* arbete (vem i mottagningen tar nästa orosanmälan), men **inte** överlämningen till
   utredning. Där bestämmer **gruppledaren** vem — för att jämna ut arbetsbörda, undvika jäv, matcha kompetens
   (LVU-erfarenhet, språk, känd familj) och hålla koll på vem som redan har 22 aktiva utredningar.
2. **Ärendet har en "ingenmansland"-fas.** Mellan *beslut inleda* och *fördelad till NN* tillhör ärendet
   **enheten/funktionen**, inte en person. Det får inte falla mellan stolarna och det får inte heller bli
   synligt för fel handläggare (OSL 26 kap.). Det behöver en egen, behörighetsstyrd kö: **"Otilldelat i
   mottagningen / väntar på fördelning"**.
3. **Beslutsrätten är delegerad och styr vem som ens *får* fördela.** Per kommunala delegationsordningar är
   beslut att *inleda/inte inleda utredning* (11 kap. 1 §, 1 a §, 2 § SoL) delegerat till socialsekreterare
   och/eller gruppledare beroende på beslutstyp. Tilldelnings-UI:t måste därför *känna till* vem som är
   behörig fördelare för korgen (GAP-054) — annars kan fel person dela ut ett barnärende.

> **Designsats:** Hubs behöver två tilldelnings-*lägen*, inte ett. **(a) Plock** (mottagningens interna,
> symmetriska "jag tar nästa") och **(b) Fördelning** (chefens asymmetriska "jag delar ut till NN"). Samma
> datamodell (en assignee bärs), olika UI och olika behörighet.

---

## 2. Rollerna (tre, inte en)

| Roll | Persona-id | Äger | Hubs-yta | Behörighet (ACL/delegation) |
|---|---|---|---|---|
| **Mottagningssekreterare** | `socialsekreterare` (läge `mottagning`) | Inflöde + förhandsbedömning (steg 1–8): triage, skyddsbedömning, kontakt inom ramen, beslutsunderlag | Standard "Mina ärenden"-vy, men ärendekorten är i steg **Förhandsbedömning** och bor i mottagningens korgar | Läs+skriv i mottagnings-korgarna; **plock** (symmetriskt) inom mottagningen; får (per delegation) ofta fatta "inte inleda" och föreslå "inleda" |
| **Gruppledare / 1:e socialsekreterare** | `gruppledare` *(ny, lättviktig)* | **Fördelning** av inledda utredningar; arbetsbörde-överblick; eskalering; (per delegation) beslut "inleda" | **Fördelningsvyn** (eget läge, §6) ovanpå samma kortmodell + en arbetsbörde-kolumn | Fördelare för sina korgar (`circles`-medlem med fördelarroll); ser otilldelat + alla utredares last; sätter assignee |
| **Utredare / handläggare** | `socialsekreterare` (läge `utredning`, default) | Utredningen (steg 11→): ärenderum, BBIC, möten, beslut, delgivning | Den vanliga **"Mina ärenden"**-vyn (`UX-REDESIGN-SOCIALSEKRETERARE.md`) | Skriv i tilldelade ärenderum; ser bara *sina* ärenden + enhetens delade bevakningsboard (ACL-snävat) |

**En person kan bära flera roller.** En 1:e socialsekreterare är ofta både fördelare *och* utredare på egna
ärenden; en mottagningssekreterare kan en dag flyttas till utredning. Rollen är därför en **funktion knuten
till korg/grupp** (en Team/`circles`-medlemsroll), inte en hård kontotyp. Hubs avgör rollläge ur (a) vilken
korg ärendet ligger i och (b) användarens medlemsroll i den korgens Team.

---

## 3. Var i flödet tilldelningen sker (mottagning äger 1–8, chef fördelar vid 9, utredare tar vid)

Vi mappar exakt mot master-walkthroughens steg 1–11. **Brytpunkten är steg 8→9** (beslut inleda →
aktualisering): där lämnar ärendet mottagningen och fördelas.

```
  STEG 1–2  INFLÖDE              → korg: orosanmalan@ / barn-familj@ (funktionsadress)
            ägare: FUNKTIONEN (mottagningsgruppen kollektivt)        assignee = null
            ─────────────────────────────────────────────────────────────────────────
  STEG 3–8  FÖRHANDSBEDÖMNING    → mottagningssekreterare PLOCKAR (symmetriskt, inom mottagningen)
            ägare: en MOTTAGNINGSSEKR.   assignee = mottagningssekr.  steg = 'forhandsbedomning'
            (skyddsbedömning, kontakt inom ramen, beslutsunderlag, förslag inleda/ej)
            ─────────────────────────────────────────────────────────────────────────
  STEG 8    BESLUT INLEDA  ──┐
                            ├─► "INTE INLEDA"  → ärendet stängs i mottagningen, gallras/arkiveras
                            │                     (ingen fördelning; mottagningssekr. avslutar)
                            └─► "INLEDA"  → assignee NOLLSTÄLLS, ärendet flyttas till kön
            ─────────────────────────────────────────────────────────────────────────
  STEG 8b   OTILLDELAT I MOTTAGNINGEN  → ägare: FUNKTIONEN igen, men nu märkt 'väntar fördelning'
            ägare: ENHETEN   assignee = null   steg = 'utredning' (inledd)   status = 'otilldelat'
            ↑ DETTA ÄR DEN NYA, BEHÖRIGHETSSTYRDA KÖN — bara fördelare + (read) gruppen ser den
            ─────────────────────────────────────────────────────────────────────────
  STEG 9    GRUPPLEDAREN FÖRDELAR  → chefens FÖRDELNINGSVY: öppnar ärendet, väljer utredare
            ägare: UTREDARE NN   assignee = NN   steg = 'utredning'   status = 'tilldelat'
            ↳ EN handling: sätter assignee-tagg + orkestrerar ärenderum + ACL + Deck-kort (§5)
            ─────────────────────────────────────────────────────────────────────────
  STEG 9    AKTUALISERING (parallellt/efter) → committas till Treserva via Frends; dnr återkopplas
            ─────────────────────────────────────────────────────────────────────────
  STEG 10–  UTREDNING             → ärendet dyker upp i NN:s "Mina ärenden" (Zon 2/3), 4-mån-klocka
  11        ägare: UTREDARE NN    utredaren driver Akt II→IV som vanligt
```

**Tre nyanser värda att låsa:**

- **Fristen flyttas inte vid fördelning.** 14-dagars förhandsbedömnings-klockan ägs och konsumeras under
  mottagningsfasen (steg 1–8) och är **bunden till inkom-datum** (GAP-002/046). 4-månadersfristen för
  utredningen startar vid **beslut inleda** (steg 8), *inte* vid fördelningen (steg 9). Att en chef dröjer en
  dag med att fördela får aldrig dölja att utredningsklockan redan tickar. Fördelningsvyn visar därför
  **"inledd 13/6 · ofördelad i 1 dag · utredningsfrist löper"** så att en lucka mellan beslut och fördelning
  blir synlig, inte gömd.
- **Två tilldelningstillstånd, inte ett "tilldelad".** `otilldelat` (steg 8b — väntar fördelning) och
  `tilldelat` (steg 9 — har utredare) är skilda status. En tom `otilldelat`-kö är en **compliance-KPI** på
  enhetsnivå precis som tom "ej registrerad"-kö är det på handlingsnivå (GAP-049): *inget inlett ärende utan
  ansvarig handläggare*.
- **Fördelning ≠ aktualisering.** De är två olika commits. Fördelningen (intern, sätter assignee + bygger
  arbetsytan) sker i Hubs. Aktualiseringen (extern, skapar ärendet i Treserva, ger dnr) sker via Frends. De
  kan ske i valfri ordning men **båda** måste ske; UI:t visar dem som två separata öppna åtgärder.

---

## 4. Hur tilldelning bär ärende-identiteten (assignee-tagg + ärenderum + Deck-kort + ACL)

Tilldelningen är inte "skriv ett namn i ett fält" — det är **ögonblicket då ärendets identitet och säkerhets­
gräns sätts**. En fördelning orkestrerar fyra saker atomärt (ett klick, server-side, GAP-010-orkestreringen):

### 4.1 Assignee-tagg (vem äger ärendet)

`assignee` sätts på ärende-objektet i sdkmc-modellen *och* speglas som **Deck-kortets tilldelning** (Deck
stödjer kortassignering till användare, och sökning `assigned:anna` — den native bäraren). Detta är den
maskinläsbara sanningen "ärende → utredare". Den driver:
- vilken "Mina ärenden"-vy ärendet dyker upp i (filtret är `assignee = jag`),
- vem T-7/T-3/T-0-påminnelser går till (**bara tilldelad**, inte hela gruppen),
- enhetsvyns arbetsbörde-kolumn (räkna `assigned:NN`).

### 4.2 Ärenderum (var handlingarna bor)

Om inget ärenderum finns (vanligt: mottagningen jobbade i ett tidigt/lättviktigt rum, eller inget alls)
skapar fördelningen en **Groupfolder per barn/dnr-token** (`groupfolders`) med BBIC-struktur ur
`kunskapsbank`. Om ett tidigt rum redan finns (skapades vid förhandsbedömningen, steg 6) **återanvänds** det
och växlar till utredningsläge — och dess ACL **skrivs om** (4.4). Ärenderummet är arbetslagret; originalet
committas till Treserva.

### 4.3 Deck-kort / bevakning (arbetsmetadata)

Ett **Deck-kort** på enhetens utredningsboard skapas/flyttas: titel = ärendereferens (aldrig klartext-PII,
GDPR), tilldelad = NN, due = härledd frist, etikett = steg. Kortet bär de delade bevakningarna. Det är
mellanlagring — den formella fristen ägs av Treserva och speglas via Frends; Hubs dubblerar inte.

### 4.4 ACL (säkerhetsgränsen — det viktigaste)

Fördelningen **snävar åtkomsten** på ärenderummets Groupfolder via Advanced ACL (`groupfolders`):

| Före fördelning (steg 8b, otilldelat) | Efter fördelning (steg 9, tilldelat NN) |
|---|---|
| Mottagningsgruppens Team: läs (de som hanterat) | **Utredare NN: skriv** |
| Gruppledare: läs | Gruppledare: läs (uppföljning) |
| Övriga enheten: ingen åtkomst | Ev. medhandläggare: läs |
| | Mottagningsgruppen: **åtkomst återkallas** (de är klara) |
| | Övriga: ingen åtkomst (ser inte ens att rummet finns) |

Detta är **least permission** och OSL 26 kap. i praktiken: en kollega utan tilldelning ser inte barnet.
Fördelnings-handlingen är därmed *både* en arbetsfördelning *och* en sekretessåtgärd. (GAP-051 — board-/rum-
ACL-granulariteten per barn måste vara exakt detta, annars läcker enhetsvyn sekretess till fel handläggare.)

### 4.5 Allt loggas (spårbarhet)

Vem fördelade, till vem, när, från vilken korg → `activity` + sdkmc-logg. Fördelning är en
myndighetsorganisatorisk åtgärd som ska kunna granskas (vem gav vem ansvar för barnet, och när).

> **Identitets-kedjan i en mening:** *fördelning = sätt `assignee` (Deck) → orkestrera/återanvänd ärenderum
> (`groupfolders`) → skriv om ACL till least-permission → skapa/flytta Deck-kort + bevakning → logga* — allt
> atomärt server-side, speglat i klienten.

---

## 5. Hur tilldelningen *triggas* — tre vägar (manuell chef, halvauto, regel)

Designen stödjer en glidskala från ren handpåläggning till regelstyrd routing, så att samma datamodell bär
både den lilla kommunens "gruppledaren delar ut allt på morgonmötet" och den stora enhetens delvis
automatiserade flöde.

| Väg | Hur | När den passar | Byggblock |
|---|---|---|---|
| **A. Manuell fördelning (default)** | Gruppledaren öppnar fördelningsvyn, väljer utredare per ärende | Standard barn & familj; chefen vill ha kontroll (jäv, kompetens, last) | Fördelningsvy + assignee-set (§4) |
| **B. Föreslagen fördelning** | Hubs *föreslår* utredare (minst last / rund-robin / "känner familjen sedan förr") — chefen bekräftar | Hög volym, vill snabba upp men behålla människan i loopen | `llm2`/regelmotor *föreslår*, chef committar (GDPR art. 22 — aldrig auto-beslut) |
| **C. Regelstyrd routing till korg** | `workflow_engine`/Flow + funktionsadress-routing: rätt korg/grupp äger inflödet (t.ex. distrikt, ålder) | Stänger GAP-054: flera enheter delar adress → routa till rätt mottagning | sdkmc-routing + Flow-tagg → bestämmer *vilken korg/fördelare*, inte vilken person |

**Viktig gräns:** väg C routar till **rätt korg/fördelare** (organisatorisk routing), aldrig direkt till en
namngiven barn-utredare utan mänskligt fördelningsbeslut. Att peka ut *vilken socialsekreterare* som utreder
ett barn är en bedömning (last, jäv, kompetens) som ska bäras av en chef — väg A/B — inte av en regel.

---

## 6. Vad chefen ser — fördelningsvyn (eget lättviktigt läge, inte en ny app)

Gruppledaren behöver **inte** hela utredar-personans "Mina ärenden". Hen behöver en smal **fördelningsyta**:
"vad är inlett men ofördelat, vem har plats, vem ska få vad". Vi bygger den som ett **eget läge** ovanpå den
befintliga kort- och zon-arkitekturen (`MinaArenden.vue`), inte som en separat app — samma `zonOf`-selector,
samma `ArendeKort`, ett extra läge `fordelning`.

```
┌──────────────────────────────────────────────────────────────────────────────┐
│  FÖRDELNINGSVY — Mottagningen barn & familj · gruppledare Eva · 14 juni         │
│  📥 3 att fördela   ⏰ 1 inledd ofördelad 2 dgr   👥 6 utredare                  │
├──────────────────────────────────────────────────────────────────────────────┤
│  ZON A — ATT FÖRDELA · 3   (inledda utredningar utan utredare — kan ej tystna)  │
│  ┌────────────────────────────────────────────────────────────────────────┐    │
│  │ Barn 2026-0142 · inledd 13/6 · OFÖRDELAD 1 dag · ⏰ utredningsfrist löper │    │
│  │ Mottagning: Anna · förslag: "inleda" (LVU-historik i familjen)           │    │
│  │  ┃ FÖRDELA TILL ▾  [ Sara (8) · Karim (11) · Mia (19) … ]  ┃  [Öppna]     │    │
│  └────────────────────────────────────────────────────────────────────────┘    │
├──────────────────────────────────────────────────────────────────────────────┤
│  ZON B — UTREDARNAS BELASTNING  (välj klokt — arbetsbörde-överblick)            │
│  Sara   ████████░░  8 utredn · 0 röda     ← föreslås (lägst last)               │
│  Karim  ███████████ 11 utredn · 2 röda                                          │
│  Mia    ██████████████████ 19 utredn · 1 röd  ⚠ nära tak                        │
├──────────────────────────────────────────────────────────────────────────────┤
│  ZON C — MOTTAGNINGENS PÅGÅENDE  (förhandsbedömningar, read-only översikt)       │
│  5 under förhandsbedömning · 2 frister inom 3 dgr                               │
└──────────────────────────────────────────────────────────────────────────────┘
```

**Zon-för-zon:**

- **Zon A — Att fördela.** Inledda utredningar med `assignee = null` (steg 8b). Varje kort visar
  mottagningssekreterarens namn + förslag (inleda/ej + kort motivering), ofördelad-tid och att utrednings­
  fristen redan löper. **Primäråtgärd = "Fördela till ▾"** — en utredar-väljare med **lasten i parentes**
  (`Sara (8)` = 8 aktiva utredningar) så chefen ser belastningen vid valet. Räknaren "att fördela" kan
  **aldrig nå noll med kort kvar** (compliance-ankare, samma logik som Dagspulsens "frister brinner").
- **Zon B — Utredarnas belastning.** Stapel per utredare: antal aktiva utredningar + antal röda frister +
  varning vid "nära tak". Klick på en utredare → filtrerar Zon A:s väljare till "fördela nästa till denna".
  Detta är chefens *enda* legitima fönster in i andras ärendelast — och det visar **siffror, inte innehåll**
  (last och frist-färg, aldrig barnets uppgifter): en chef behöver veta *att* Mia har 19 ärenden, inte *vad*
  de innehåller (OSL 26 kap. — chefen har inte automatiskt sekretess-åtkomst till varje barn).
- **Zon C — Mottagningens pågående.** Read-only överblick av förhandsbedömningar (steg 3–8) så chefen ser vad
  som är på väg att bli fördelningsbart. Ingen åtgärd; bara framförhållning.

**Behörighet (vem ser fördelningsvyn):** rollen `gruppledare` = medlem med **fördelarroll** i korgens Team
(`circles`). `IConditionalWidget`-mönstret (åtkomstgräns) gör att vyn bara renderas för fördelare för just de
korgar hen ansvarar för. En gruppledare för mottagningen ser inte en annan enhets fördelningsvy.

**Fördelningshandlingen (UI→effekt):** "Fördela till Sara" → bekräftelse-dialog ("Sara blir ansvarig utredare
för Barn 2026-0142. Hon får skrivåtkomst till ärenderummet, ärendet visas i hennes Mina ärenden, och
fristpåminnelser går till henne.") → server orkestrerar §4 atomärt → kortet lämnar Zon A → en notis går till
Sara.

---

## 7. Hur det syns för utredaren (mottagande sida)

Sara behöver inte leta. När Eva fördelat:

1. **Ärendet dyker upp i Saras "Mina ärenden"** (`UX-REDESIGN`-vyn) — i **Zon 2 "Kräver åtgärd nu"** om
   fristen redan är het, annars Zon 3. Kortet är i steg **Utredning**, stepper på första substeget, och
   **"Nästa åtgärd"** lyser: *"Öppna ärenderum & lägg utredningsplan"*.
2. **En tydlig "NY — tilldelad dig av Eva 14/6"-markör** på kortet de första 24 h (graft från
   triage-strömmens nyhets-markör), så att en nyfördelning inte drunknar bland 21 pågående.
3. **En notis/aktivitetspost** ("Eva fördelade Barn 2026-0142 till dig") via `activity` + ev. säker chatt
   (se §8) — så att överlämningen har en mänsklig kanal, inte bara ett tyst statusbyte.
4. **Ärenderummet är redan riggat:** ACL ger henne skriv, BBIC-strukturen ligger där, mottagningens
   beslutsunderlag + skyddsbedömning + orosanmälan finns i rummet. Hon börjar inte från noll — hon ärver
   mottagningens arbete.
5. **Provenance-bandet bär överlämningen:** *"Inkom via SDK 10/6 · förhandsbedömd av Anna (mottagning) ·
   inledd 13/6 · fördelad till mig av Eva 14/6 · → Treserva: registrerad, dnr 2026-IFO-0142"*. Hela kedjan
   mottagning→chef→utredare→facksystem är läsbar på en rad.

---

## 8. Överlämningssamtalet (var chatt och muntlig kontext hör hemma)

En fördelning är sällan bara metadata — mottagningssekreteraren har *tyst kunskap* ("mamman var svår att nå",
"misstänkt våld men inte styrkt") som ska följa med. Två lägen, båda lagligt rena:

- **Strukturerat i ärenderummet:** mottagningens beslutsunderlag + skyddsbedömning är redan i rummet (det
  formella). Det är detta som är allmän handling.
- **Säker chatt för det informella (`spreed-itsl`):** en **ärendetrådad säker chatt** mellan Anna, Eva och
  Sara vid överlämning — "Teams-känslan" men on-prem och sekretesssäker. Viktig regel: chatten är
  **mellanlagring/arbetskommunikation**, och om något i den blir beslutsunderlag måste det *föras in i
  ärenderummet/Treserva* (annars finns en allmän handling bara i en chatt — samma felrisk som rå-transkript).
  Chatten knyts till dnr/ärenderum, inte till en lös konversation.

*(Säker chatt + multi-korg är egna arkitektur-varv — se task #18/#19. Här noteras bara var överlämnings­
samtalet hör hemma i tilldelningsflödet.)*

---

## 9. Kantfall att hantera (annars läcker modellen)

- **Syskon / 1:n barn↔anmälan.** En orosanmälan kan röra flera barn → flera utredningar, ev. olika utredare.
  Fördelningsvyn måste kunna **splitta** ett inflöde till N ärenden och fördela dem var för sig (kopplar till
  GAP-005 token/dnr-mappning, 1:n-modellen). Ofta hålls syskon hos *samma* utredare — väljaren bör föreslå
  "samma som syskonet".
- **Omfördelning (utredare blir sjuk / jäv upptäcks / går i semester).** Tilldelning är inte engångs. Chefen
  måste kunna **flytta** ett pågående ärende till ny utredare: assignee byts, ACL skrivs om (gammal utredare:
  skriv→läs eller ingen, ny: skriv), bevakningar/påminnelser pekas om, allt loggas. Samma orkestrering som §4,
  bara på ett ärende som redan har historik.
- **Jäv.** Om föreslagen utredare är jävig (känner familjen privat) måste chefen kunna **utesluta** hen ur
  väljaren / markera jäv. Hubs kan inte avgöra jäv automatiskt, men ska göra det lätt att dokumentera och
  spärra.
- **Ingen ledig utredare (alla över tak).** Zon B visar att alla är nära tak → fördelningen blir en
  *eskalering* till enhetschef, inte en tyst tilldelning till någon som inte har plats. "Att fördela"-kön som
  inte töms är en signal uppåt.
- **Delad funktionsadress mellan enheter (GAP-054).** Innan fördelning måste rätt mottagning äga inflödet.
  Väg C (regel-routing) löser detta: funktionsadress + Flow-tagg → rätt korg/fördelare. Utan routing-regel
  riskerar fel enhets gruppledare se fel barn.
- **"Inte inleda" efter plock men före fördelning.** Då finns ingen fördelning alls — mottagnings­
  sekreteraren avslutar ärendet i mottagningen (beslut + anmälan bevaras/gallras per plan). Ärendet når aldrig
  Zon A.

---

## Implementering

**Vilka appar:**
- **`sdkmc`** (funktionsadress/korg + ärende-objektets `assignee`/`status`/`steg`-fält + summary-endpoint som
  redan klassar inflöde): bär `otilldelat`/`tilldelat`-status och fördelningsmetadata. Nya OCS-routes
  (additivt, samma mönster som CONTRACTS): `/api/v1/fordelning-summary` (chefens vy-payload: att-fördela +
  utredarlast), `/api/v1/arende/{ref}/tilldela` (sätt assignee + orkestrera), `/api/v1/arende/{ref}/omfordela`.
- **`deck`**: bär `assignee` native (kortassignering + `assigned:NN`-sök), enhetens utredningsboard, delade
  bevakningar; arbetsbörde-räkningen i Zon B = `assigned:NN`-count per utredare.
- **`groupfolders`** (+ `files`, `files_versions`, `files_retention`): ärenderummet; **ACL-omskrivningen vid
  fördelning** (mottagning read→revoke, utredare write) är kärnan i säkerhetsgränsen.
- **`circles`** (Team): bär roll-modellen — `gruppledare` = medlem med fördelarroll i korgens Team; styr vem
  som ser fördelningsvyn (`IConditionalWidget`-åtkomstgräns) och vilka korgar hen är fördelare för.
- **`tasks`/VTODO**: T-7/T-3/T-0-påminnelser **bara till tilldelad** (pekas om vid omfördelning).
- **`activity`**: loggar fördelning/omfördelning (vem→vem→när→från korg) för spårbarhet.
- **`spreed-itsl`**: säker, ärendetrådad överlämningschatt (informell tyst kunskap; det formella förs till
  rummet/Treserva).

**Vad i Flow (`workflow_engine`/Flow):**
- **Routing-regel per funktionsadress** (väg C, GAP-054): inkommande till delad adress → tagg → rätt
  korg/mottagningsgrupp äger inflödet och rätt gruppledare blir fördelare. Routar till korg/grupp, **aldrig**
  direkt till en namngiven barn-utredare.
- **Statusövergångs-hook:** beslut "inleda" → nollställ `assignee`, sätt `status='otilldelat'`,
  `steg='utredning'`, flytta ärendet till "att fördela"-kön, starta utredningsfrist-spegling (Frends).
- **ACL-orkestrering-trigger:** vid `tilldela`-event → skriv om Groupfolder-ACL (mottagning revoke, utredare
  write), spegla assignee till Deck-kort, peka om VALARM-påminnelser. Atomärt server-side (en handling).
- **"Otilldelat åldras"-bevakning:** ärende `status='otilldelat'` > N timmar → höj synlighet i fördelningsvyn
  + ev. notis till enhetschef (eskalering, inte tyst tilldelning).

**Vad programmatiskt (ej Flow/native):**
- **Fördelnings-orkestreringsendpoint** (`tilldela`): atomär transaktion {set assignee → skapa/återanvänd
  ärenderum + ACL-omskrivning → skapa/flytta Deck-kort + bevakning → logga → notifiera utredare}. Samma kärna
  återanvänds av `omfordela`.
- **Arbetsbörde-aggregat** för Zon B: räknar aktiva utredningar + röda frister **per utredare**, exponerar
  bara *tal och frist-färg* (aldrig ärendeinnehåll — OSL-gräns för chefens vy).
- **Föreslagen-fördelning** (väg B, valfritt): minst-last / rund-robin / "känner familjen"-heuristik som
  *föreslår* en utredare; `llm2` får på sin höjd föreslå, chef committar (GDPR art. 22).
- **`gruppledare`-persona + `fordelning`-läge** i `personaConfig.js`/`MinaArenden.vue`: nytt vy-läge ovanpå
  befintlig kort-/zon-arkitektur, ingen ny app. Demo-stubbar i `demo/`.
- **Splitta-inflöde-till-N-ärenden** (syskonfallet): UI + datamodell för 1:n barn↔anmälan vid fördelning.

## UI i socialsekreterarvyn

- **Triage-zonen ("Att ta emot", Zon 1) förfinas till två tilldelningslägen.** För
  **mottagningssekreteraren** behåller raden "Ta emot & starta förhandsbedömning" (= **plock**, symmetriskt).
  För det inledda-men-ofördelade ärendet (steg 8b) visas raden **inte** i en utredares vy alls — den lever
  bara i **fördelningsvyn**. En utredare ser ärendet först när det är tilldelat *henne*.
- **Gruppledaren får ett eget läge — fördelningsvyn (§6)** — nåbar via persona-/roll-växeln: Zon A "Att
  fördela" (inledda utan utredare, kan ej tystna), Zon B "Utredarnas belastning" (tal + frist-färg, ej
  innehåll), Zon C "Mottagningens pågående" (read-only). Primäråtgärd per kort: **"Fördela till ▾"** med last
  i väljaren. Samma `ArendeKort`-komponent, extra läge.
- **På utredarens "Mina ärenden":** ett nyfördelat ärende dyker upp i **Zon 2/3** med **"NY — tilldelad dig av
  {chef} {datum}"-markör** (24 h), **"Nästa åtgärd: Öppna ärenderum & lägg utredningsplan"**, färdigriggat
  ärenderum (ACL + BBIC + mottagningens underlag), och ett **provenance-band som bär hela kedjan**
  mottagning→chef→utredare→Treserva. En `activity`-/chatt-notis ger överlämningen en mänsklig kanal.
- **Omfördelning** finns både i fördelningsvyn (chefens "flytta till annan utredare") och som en
  chef-åtgärd på utredarens kort (ej självbetjäning för utredaren — hen kan *begära* omfördelning, chefen
  beslutar).
- **Compliance-känslan:** tom "Att fördela"-kö = *inget inlett barnärende utan ansvarig* — en enhets-KPI
  bredvid den befintliga "ej registrerad i Treserva"-KPI:n.

---

*Källor (svensk socialtjänst-process): mottagnings-/utredningsgrupp + gruppledare som fördelar —
[Stockholms stad, mottagningsgrupp BOU](https://jobba.stockholm/lediga-jobb/platsannonser/socialsekreterare-till-var-mottagningsgrupp-bou-forsta-linjen-861042),
[Danderyd mottagningsgrupp](https://ledigajobb.se/jobb/a5232e/socialsekreterare-i-mottagningsgruppen-till-socialf%C3%B6rvaltningen-danderyds);
utrednings-/inledandebeslut — [Kunskapsguiden: besluta inleda/inte inleda](https://kunskapsguiden.se/omraden-och-teman/barn-och-unga/handlaggning-och-dokumentation-med-barnet-i-centrum/aktualisera/besluta-om-att-inleda-eller-inte-inleda-en-utredning/),
[Trollhättan: hur en utredning går till](https://www.trollhattan.se/startsida/omsorg-och-hjalp/familj-barn-och-ungdom/mottagningsgruppen/hur-en-utredning-av-barn-och-ungdomar-gar-till/);
delegation av inledandebeslut till socialsekr./gruppledare —
[Stockholm delegationsordning socialnämnden 2025](https://meetingspublic.stockholm.se/welcome-sv/namnder-styrelser/socialnamnden/mote-2025-06-10/agenda/bilaga-1-delegationsordning-for-socialnamnden-from-1-juli-2025pdf?downloadMode=open),
[Valdemarsvik delegationsordning IFO](https://www.valdemarsvik.se/wp-content/uploads/2019/04/delegationsordning-ifo.pdf).
Teknik (assignee/Team/ACL): [Nextcloud Deck — kortassignering + assigned:-sök + Circles](https://deck.readthedocs.io/en/latest/User_documentation_en/),
[Nextcloud Hub 22 — decentraliserad gruppadministration/Circles](https://nextcloud.com/blog/nextcloud-hub-22-introduces-approval-workflows-integrated-knowledge-management-and-decentralized-group-administration/).
Övrigt grundat i interna underlag (SOCIALSEKRETERARE-WALKTHROUGH, GAP-ANALYSIS, UX-REDESIGN, arendehantering-map, native-apps-map, WIDGET-APP-MAP).*
