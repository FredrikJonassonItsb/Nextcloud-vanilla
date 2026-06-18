<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Ark-4 — Meddelanden utan ärende: hur Hubs hanterar inkommande som (ännu) inte hör till ett ärende

> **Vad detta är:** ett arkitektur-varvs-dokument som löser ett hål i den ärende-centriska
> socialsekreterarvyn. "Mina ärenden" antar att allt inkommande *redan* hör till ett ärende. Men en
> socialsekreterare har flera korgar (personlig brevlåda, gruppkorgar/funktionsadresser, digital fax,
> SDK, SMS) med *många* informationstyper, och en stor del av inflödet är **inte** kopplat till ett
> ärende när det landar — vissa ska aldrig bli ärende, vissa ska bli nya, vissa hör till befintliga men
> är inte kopplade än. Detta dokument definierar de fyra fallen, designar en **"Ej ärendekopplat"-hink**
> med sina åtgärder, sätter de **juridiska gränserna** (när måste något ändå diarieföras/registreras
> trots att det inte är ett "ärende"?), och beskriver hur Hubs **föreslår koppling automatiskt**.
>
> **Persona:** `socialsekreterare` (barn & familj) · **System of record:** Treserva / Lifecare / Viva /
> Combine (socialakten/BBIC-journalen) → e-arkiv (Sydarkivera, FGS). **Plattform:** server v32 (Hub 25
> Autumn). **Datum:** 2026-06-14.
>
> **Bärande arkitektur:** Hubs är **mellanlagring**. Den ärende-centriska vyn (`UX-REDESIGN-
> SOCIALSEKRETERARE.md`) bygger på ärendekort + ProcessStepper + "Att ta emot". Detta dokument adderar ett
> **fjärde tillstånd** vid sidan av "Att ta emot / Aktiva ärenden / Avslutade": **"Ej ärendekopplat"** —
> hinken för inflöde som ännu inte fått en hemvist. Modellmeningen genomgående: *ingen rad får bli kvar i
> systemet utan att till slut antingen kopplas till ett ärende, registreras separat, eller gallras med
> stöd av ett dokumenterat beslut.*
>
> **Brand-regel:** i produkt-/UI-text aldrig "Nextcloud"/"Talk"; här namnger vi app-id (sdkmc, securemail,
> mail, tables, deck, groupfolders, workflowengine/flow, files_retention, activity, collectives) för att
> kunna wire:a.

---

## 0. Problemet: den ärende-centriska vyn har ingen plats för det som inte är ett ärende

Den befintliga vyn är **ärende-först**: varje rad antas tillhöra ett barn/dnr, få ett ärendekort, en
ProcessStepper och en "Nästa åtgärd". Triagezonen "Att ta emot" visar idag mest orosanmälningar — som ju
*ska* bli ärenden. Men den verkliga korg-modellen (ur sdkmc) är bredare: en socialsekreterare ser flera
korgar samtidigt (personlig brevlåda, `mottagningen@`, `barn-familj@`, fax, SDK, SMS) som rymmer
orosanmälan, komplettering, fråga från medborgare, remiss, internpost från kollega, fax från vårdcentral,
SDK från annan myndighet, reklam, fel-adresserat, autosvar … Stora delar av detta är **inte ärende-bart i
mottagningsögonblicket**, och en del ska **aldrig** bli ärende.

Om vyn tvingar varje rad in i ärende-modellen uppstår tre fel:

1. **Falska ärenden** — en allmän fråga eller ett autosvar blir ett "ärende" som skräpar ner ärendelistan
   och driver onödig dokumentation.
2. **Hemlösa rader** — det som inte passar någonstans blir kvar oläst/otriagerat i en korg, och faller
   mellan stolarna (rättssäkerhets- och arkivrisk).
3. **Registreringsmiss** — något som *rättsligt måste registreras* (allmän handling) men som "inte är ett
   ärende" hanteras informellt och diarieförs aldrig (OSL 5:1-brott).

Lösningen är en uttrycklig **mellanstation** — ett tillstånd mellan "inkommet" och "kopplat till
ärende/registrerat/gallrat" — som vyn äger lika tydligt som ärendekorten. Det är "Ej ärendekopplat".

---

## 1. De fyra fallen (taxonomin handläggaren faktiskt möter)

Varje inkommande som inte redan bär ett dnr faller i ett av fyra fall. Hubs ska klassa dem — gärna med
AI-förslag (avstängbart) — men människan avgör.

### (a) Hör till ett BEFINTLIGT ärende men är inte kopplat än
Komplettering, svar, ny uppgift som gäller ett barn/dnr som redan finns i Treserva, men där meddelandet kom
in löst (fel funktionsadress, avsändaren angav inte dnr, ny tråd). **Vanligast i absoluta tal.** Exempel:
skola skickar "Bifogar efterfrågad pedagogisk kartläggning" utan dnr i ämnet; vårdnadshavare svarar från en
ny e-postadress.
- **Rättslig status:** nästan alltid **allmän handling** (inkommen), oftast **sekretess (OSL 26 kap.)**.
  Ska föras till **rätt** akt i facksystemet och journalföras där.
- **Åtgärd:** **Koppla till befintligt ärende** (sök dnr/barn) → bilagan speglas till ärenderummet, raden
  ärver ärendets ProcessStepper och destinations-chip.

### (b) Ska bli ett NYTT ärende
Nytt inflöde som motiverar att ett ärende öppnas: en orosanmälan om ett barn utan pågående ärende, en
ansökan om bistånd, en ny remiss. Detta är det fall den nuvarande "Att ta emot"-zonen redan hanterar — men
det måste rymmas i samma hink som de andra fallen för att triagen ska bli komplett.
- **Rättslig status:** **allmän handling** (inkommen); orosanmälan triggar omedelbar skyddsbedömning
  (11 kap. 1 a § SoL) + 14-dagars förhandsbedömning. Registreringsplikt senast nästa arbetsdag.
- **Åtgärd:** **Skapa nytt ärende** → aktualisering i Treserva (mönster B/A), ärenderum skapas, fristklocka
  startar (bunden till **inkom-datum**, GAP-002).

### (c) Ska ALDRIG bli ärende — och kan/ska gallras eller bara hållas ordnat
Allmän fråga utan ärendeanknytning, ren information (nyhetsbrev, kurserbjudande), uppenbart fel mottagare,
reklam/skräp, autosvar, dubbletter. **Här ligger den juridiskt känsligaste gränsdragningen** (se §3): "ska
aldrig bli ärende" betyder *inte* automatiskt "får raderas".
- **Rättslig status:** spänner från **inte allmän handling alls** (rena utkast/mellanprodukter) via
  **allmän handling av uppenbart ringa betydelse** (får gallras direkt, OSL 5:1 + arkivlagen) till
  **allmän handling som ändå måste registreras eller bevaras** (t.ex. en skrivelse till nämnden som inte
  blir ett socialtjänstärende men är diariepliktig hos registratur).
- **Åtgärd:** **Gallra/arkivera utan ärende** *eller* **Vidarebefordra (fel mottagare)** — med ett
  dokumenterat stöd för gallringsbeslutet.

### (d) Ska BESVARAS utan ärende
En fråga som kan och bör besvaras direkt utan att ett ärende öppnas: "Vilka är era öppettider?", "Hur
ansöker jag om kontaktfamilj?", en allmän upplysning till en samverkanspart. Svaret avslutar interaktionen.
- **Rättslig status:** både frågan och svaret är typiskt **allmänna handlingar** (inkommen resp.
  expedierad), men ofta av **ringa betydelse** → får hållas ordnade/gallras enligt rutin snarare än
  diarieföras som ärende. Innehåller frågan personuppgifter/sekretess ändras bedömningen.
- **Åtgärd:** **Besvara utan ärende** (säkert svar via sdkmc/securemail) → raden stängs; svaret + frågan
  hålls ordnade i en "besvarat utan ärende"-logg, gallras enligt plan.

> **Nyckeln:** fall (a)/(b) → *in i* ärende-/facksystemvärlden. Fall (c)/(d) → *stannar utanför* ärende,
> men måste ändå passera en **registrerings-/gallringsgrind** innan raden får försvinna. Det är den grinden
> "Ej ärendekopplat"-vyn gör synlig och obligatorisk.

---

## 2. "Ej ärendekopplat"-hinken: vy, kolumner och åtgärder

### 2.1 Var den bor i socialsekreterarvyn
"Ej ärendekopplat" är ett **eget segment i triage-zonen "Att ta emot"**, inte en separat app-flik. Zonen
delas i två rader:

- **"Att ta emot — blir ärende"** (fall a/b): det som ska in i ärendevärlden. Behåller dagens
  orosanmälningskänsla.
- **"Ej ärendekopplat"** (fall c/d, plus a/b *innan* de fått hemvist): en hink för allt löst inflöde, med
  en **synlig räknare** ("Ej ärendekopplat: 7 — 2 äldre än 3 dagar") som blir en **compliance-KPI**: en
  växande hink = inflöde som inte triageras = registreringsrisk. Målet är att hinken töms dagligen, precis
  som "tom kö = inget barn mellan stolarna".

Hinken aggregerar **över alla korgar** handläggaren har behörighet till (personlig + grupp + fax + SDK +
SMS), så hon slipper hoppa mellan brevlådor. Varje rad bär — som ärendekorten — **kanalikon**, **korg**
(varifrån), **avsändarens verifierade identitet + LOA** (eller "ej verifierad/anonym" som *legitimt*
tillstånd, GAP-053), **inkom-tidsstämpel** och en **klassnings-chip** (allmän fråga / info / komplettering?
/ fel mottagare / skräp). Radtexten är **ärendereferens/metadata, inte klartextcitat** (GDPR art. 5
dataminimering).

### 2.2 Åtgärdsmenyn per rad (de sex åtgärderna)

| Åtgärd | Fall | Vad som händer i Hubs | Var det hamnar |
|---|---|---|---|
| **Koppla till befintligt ärende** [sök dnr/barn] | (a) | Bilaga/meddelande speglas till ärenderummet (Groupfolder); raden ärver dnr, ProcessStepper, destinations-chip; ConversationId↔dnr-mappning sätts | Treserva-akten (mönster B/A) |
| **Skapa nytt ärende** | (b) | Aktualiserings-/registreringsformulär förifylls (avsändare/inkom-datum/kanal/föreslagen ärendemening/sekretess); ärenderum + fristklocka skapas | Treserva-akten (aktualisering) |
| **Besvara utan ärende** | (d) | Säkert svar via sdkmc/securemail; fråga+svar loggas i "besvarat utan ärende"-register; raden stängs | Hålls ordnat → gallras enligt plan |
| **Vidarebefordra / fel mottagare** | (c) | Säker vidarebefordran till rätt funktionsadress/myndighet (SDK org-till-org); kvittens; raden stängs med spårbar överlämning | Rätt mottagares korg; överlämningen loggas |
| **Gallra utan ärende** | (c) | Raden raderas **endast** efter att gallringsgrinden passerats (handlingstyp + dokumenterat gallringsbeslut, se §3); händelsen loggas (vem/vad/när) | Raderas i Hubs; ingen slutlagring |
| **Registrera utan ärende** (arkivera/diarieför) | (c), ibland (d) | För allmän handling som ska bevaras/diarieföras men *inte* blir socialtjänstärende: skicka till registratur/diarium (W3D3 m.fl.) eller sätt bevarande-tagg | Diariet / e-arkiv (FGS) |

Två designregler:

- **"Gallra utan ärende" är aldrig ett ensamt klick som raderar.** Den öppnar en mini-prövning:
  *handlingstyp* (välj ur kommunens dokumenthanteringsplan) → systemet visar *gallrings-/bevarandebeslutet*
  för den typen → handläggaren bekräftar. Saknas en gallringsgrund visas i stället **"Registrera utan
  ärende"** som tvingande väg. Detta operationaliserar att man inte får radera en allmän handling utan stöd
  (arkivlagen 1990:782).
- **Default-åtgärden Hubs föreslår** sätts av klassnings-chipen + auto-koppling (§4): "komplettering, hög
  träff mot dnr 2026-IFO-1234" → default **Koppla**; "autosvar/reklam" → default **Gallra** (men grinden
  kvarstår); "allmän fråga från medborgare" → default **Besvara utan ärende**.

### 2.3 Datamodell (osynlig motor)
Hinken backas av ett **Tables-register** (`ej_kopplat`) med kolumner: `källa/korg`, `kanal`, `avsändare`,
`LOA/verifiering`, `inkom`, `klassning`, `föreslagen_åtgärd`, `status` (Ny / Triagerad / Kopplad / Besvarad
/ Vidarebefordrad / Registrerad / Gallrad), `dnr` (om kopplad), `gallringsgrund`. Registret **renderas som
widget, aldrig som rå tabell**. sdkmc summary-endpoint matar in raderna; Activity/SDK-loggen bevarar varje
statusövergång som spårbarhet (12 mån).

---

## 3. De juridiska gränserna: när måste något registreras *trots att det inte är ett "ärende"?*

Detta är dokumentets kärna, eftersom produkten lätt frestas att likställa "inget ärende" med "ingen
skyldighet". Det är fel.

### 3.1 Registreringsplikten gäller handlingen, inte ärendet (OSL 5:1)
Registreringsplikten i **OSL 5 kap. 1 §** knyter an till att en handling är **allmän** (inkommen till eller
upprättad hos myndigheten) — **inte** till att den hör till ett formellt "ärende". En lös skrivelse,
en komplettering eller en fråga kan vara allmän handling i samma sekund den kommer in, oavsett om något
ärende öppnas. Huvudregeln: allmänna handlingar ska registreras så snart de kommit in/upprättats (JO-praxis:
normalt **senast nästa arbetsdag**).

### 3.2 De fyra utvägarna i OSL 5:1 — och varför socialtjänst sällan slipper registrering
OSL 5:1 ger fyra hanteringsvägar för en allmän handling:

1. **Registrera (diarieföra)** — huvudregeln.
2. **Hålla ordnad utan registrering** — *men endast om handlingen inte omfattas av sekretess* och det ändå
   utan svårighet går att fastställa om den kommit in/upprättats.
3. **Varken registrera eller hålla ordnad** — endast om handlingen är av **uppenbart ringa betydelse** för
   verksamheten (reklam, nyhetsbrev, autosvar, uppenbara dubbletter, fel-adresserat utan koppling).
4. (Särskilda undantag via förordning för vissa massärendetyper — t.ex. FK:s försäkringsärenden — inte
   tillämpligt på en kommuns socialtjänst i allmänhet.)

**Konsekvensen för socialtjänst är skarp:** eftersom socialtjänstens handlingar normalt är
**sekretessbelagda (OSL 26 kap.)** är väg 2 (hålla ordnad utan registrering) **stängd** för det mesta av
inflödet. En sekretessbelagd allmän handling ska som huvudregel **registreras** — lagstiftarens motiv är att
det åtminstone ska vara känt att handlingen finns, så att den inte kan döljas. Det betyder:

- Fall (a)/(b): registreras *via aktualisering/journalföring i facksystemet* — där sker registreringen i
  praktiken, och Hubs "för över".
- Fall (c)/(d) **med sekretess eller mer än ringa betydelse**: kan **inte** bara hållas ordnat eller gallras
  — måste registreras någonstans (socialtjänstärende *eller* diarium). Det är därför "Registrera utan
  ärende" finns som egen åtgärd: en handling kan vara registreringspliktig utan att vara ett
  *socialtjänstärende*.

### 3.3 Vad får gallras — och vad krävs för att få gallra
Gallring av en allmän handling kräver **stöd** (arkivlagen 1990:782; kommunen beslutar gallring i sin
**dokumenthanteringsplan/gallringsbeslut**, Riksarkivet ger allmänna råd). Två lagliga gallringsvägar är
relevanta för hinken:

- **Uppenbart ringa betydelse** (reklam, autosvar, nyhetsbrev, dubbletter, fel-adresserat utan
  ärendeanknytning) → får gallras direkt; behöver varken registreras eller hållas ordnat. **Detta är den
  enda väg där "Gallra utan ärende" är friktionsfritt korrekt.**
- **Handlingstyp med gallringsfrist i DHP** (t.ex. "allmänna förfrågningar utan ärende — gallras vid
  inaktualitet") → får gallras enligt den fristen; Hubs visar fristen och kräver bekräftelse.

Allt annat — sekretessbelagt, av mer än ringa betydelse, beslutsunderlag — får **inte** gallras utan att
först ha registrerats/bevarats. Hubs `files_retention` får därför **aldrig** trigga radering av en
"ej-kopplat"-rad enbart på tid; den måste passera handläggarens gallringsgrind med vald handlingstyp (samma
princip som GAP-007/GAP-008/GAP-026: gallring vilar på beslut, inte på en kryssruta).

### 3.4 Utkast och mellanprodukter (inte allmän handling)
En del av hinken är *inte* allmän handling alls: rena arbetsutkast, minnesanteckningar som inte tillför
sakuppgift, mellanprodukter. Dessa får hanteras/gallras fritt. Men gränsen utkast vs upprättad handling
(TF 2 kap.) görs av handläggaren — Hubs stöttar med handlingstyps-val men avgör inte (GAP-015/020).

### 3.5 Sammanfattande beslutsgrind (vad vyn tvingar fram)

```
 Inkommande "ej kopplat"
        │
        ├─ Hör till befintligt ärende? ──► KOPPLA (a) ──► registreras/journalförs i Treserva
        ├─ Ska bli nytt ärende?         ──► SKAPA (b)  ──► aktualisering i Treserva (frist startar)
        │
        └─ Varken eller:
              ├─ Uppenbart ringa betydelse (reklam/autosvar/dubblett/fel-adress)? ──► GALLRA (c)  [grind: handlingstyp]
              ├─ Allmän handling, mer än ringa / sekretess?                       ──► REGISTRERA (c) ──► diarium/e-arkiv
              ├─ Fel mottagare men reell handling?                                ──► VIDAREBEFORDRA (c) + logga
              └─ Allmän fråga som kan besvaras?                                   ──► BESVARA (d) ──► håll ordnat, gallra enligt plan
```

---

## 4. Hur Hubs föreslår koppling automatiskt

Auto-förslag minskar mängden manuell triage och fångar fall (a) — det vanligaste och mest felbenägna. Tre
signaler, rangordnade efter tillförlitlighet, plus ett AI-lager:

1. **dnr i ämnet/innehållet** (högst tillit). Regex/parsing mot dnr-mönstret (`2026-IFO-####`) i
   ämnesrad/brödtext/filnamn → direkt högträffsförslag "Koppla till 2026-IFO-1234". Drivs av Flow/
   workflow_engine-regel på inkommande.
2. **Avsändare ↔ tidigare parter.** Avsändarens verifierade identitet (SDK org-cert/BankID-signerad
   securemail) eller e-postadress matchas mot parter i pågående ärenden (vårdnadshavare, anmälande skola,
   BUP-enhet). Träff → förslag med konfidens ("samma skola som i 2026-IFO-1234").
3. **Tidigare trådar (ConversationId).** sdkmc bär AS4 Conversation/Message-ID; ett svar i en befintlig
   tråd ärver trådens ärendekoppling automatiskt (GAP-041: mappningen ConversationId↔dnr måste finnas i
   datamodellen — den specificeras här som nyckeln bakom auto-koppling).
4. **AI-lager (lokalt, avstängbart, grön-ratat `llm2`).** *Föreslår* klassning (komplettering / allmän
   fråga / info / fel mottagare) och rangordnar matchningskandidater, med synligt "varför" ("nämner barnets
   förnamn + skolans namn → trolig komplettering till 2026-IFO-1234"). AI **fattar aldrig beslut, kopplar
   aldrig själv, gallrar aldrig** (GDPR art. 22) — den fyller bara i default-åtgärden som människan
   godkänner.

**Viktig spärr (sekretess):** auto-koppling får aldrig *avslöja* ett ärendes existens för en handläggare
utan behörighet. Förslaget "Koppla till 2026-IFO-1234" visas bara om handläggaren redan har åtkomst till det
ärendet/rummet (`IConditionalWidget` = OSL-åtkomstgräns). För andra visas bara "möjlig koppling finns —
eskalera till gruppledare".

**Falsk-positiv-hantering:** ett auto-förslag är ett *förslag*, inte en handling. Fel föreslagen koppling
ska vara ett klick att avvisa, och en felkopplad bilaga får inte redan ha hamnat i fel ärenderum innan
människan bekräftat (spegla **vid bekräftelse**, inte vid förslag — annars trippellagrad sekretess i fel
rum, jfr GAP-043).

---

## 5. Hur ej-kopplat syns och hanteras i vyn (konkret interaktion)

- **Triage-zonen** överst i "Mina ärenden" får två tydligt åtskilda band: **"Att ta emot — blir ärende"**
  och **"Ej ärendekopplat (7)"**. Sifferbadgen på det senare är röd när rader är äldre än
  triage-SLA:t (t.ex. >1 arbetsdag, speglar registreringsplikten).
- **Raden** visar kanalikon · korg · avsändare+LOA (eller "anonym/ej verifierad") · inkom-tid ·
  klassnings-chip · **föreslagen default-åtgärd som primärknapp** (t.ex. "Koppla → 2026-IFO-1234") + en
  "Mer"-meny med de övriga fem åtgärderna.
- **Koppla** öppnar en **sök-dnr/barn**-ruta (förifylld med auto-förslagets toppkandidat); bekräftelse
  speglar bilagan till ärenderummet och raden glider visuellt över till ärendekortet (ProcessStepper +
  "Nästa åtgärd" tar över).
- **Skapa nytt ärende** öppnar det förifyllda aktualiseringsformuläret; vid spar startar fristklockan
  (inkom-datum) och ärendekortet skapas.
- **Besvara utan ärende** öppnar säkert svar-läge inline; vid skickat loggas fråga+svar och raden stängs
  med chip "Besvarad utan ärende — hålls ordnat".
- **Gallra utan ärende** öppnar gallringsgrinden (handlingstyp-val → visat gallringsbeslut → bekräfta).
  Saknas grund byts knappen mot **Registrera utan ärende**.
- **Vidarebefordra** öppnar mottagar-/funktionsadressval; skickas säkert med kvittens; raden stängs med
  spårbar överlämning.
- **Tom hink** visar samma compliance-bekräftelse som tom ärendekö: *"Ej ärendekopplat: 0 — allt inflöde
  triagerat."*

---

## Implementering

**Vilka appar.**
- **sdkmc / securemail / mail (digital fax) / SMS** — inflödet och dess provenance (kanal, korg, avsändar-
  LOA, ConversationId, inkom-tid). Summary-endpoint (`/ocs/v2.php/apps/sdkmc/api/v1/summary`) matar hinken
  över **alla** behöriga korgar; funktionsadress-stöd (SKR 2025) ger gruppkorgarna.
- **tables** — registret `ej_kopplat` (klassning/status/föreslagen åtgärd/gallringsgrund); osynlig motor,
  renderas som widget. Backend för triage-räknaren och compliance-KPI:n.
- **groupfolders (+ files, files_versions)** — vid **Koppla**/**Skapa**: bilagan speglas in i rätt
  ärenderum med ärvd ACL och retention-tagg (vid bekräftelse, inte vid förslag).
- **workflowengine / flow + files_automatedtagging** — auto-koppling/-klassning: regel på inkommande som
  (a) parsar dnr ur ämne/innehåll, (b) matchar avsändare/ConversationId, (c) sätter klassnings-/route-tagg
  (t.ex. `route:komplettering`, `route:allman-fraga`, `route:skrap`) som driver default-åtgärd och, för
  uppenbar reklam/autosvar, en föreslagen gallringskö. Mönstret är det dokumenterade Flow-exemplet "mejl →
  mapp → tagg → Deck-kort", riktat till socialtjänst-triage.
- **deck** — valfri delad triage-board för gruppkorgar (plockbar "ej-kopplat"-kö när en mottagningsgrupp
  delar en funktionsadress; GAP-054 routing-/behörighetsregel per adress).
- **libresign/Inera, calendar, spreed** — berörs inte direkt; svar utan ärende går via sdkmc/securemail.
- **files_retention** — gallrar **inte** ej-kopplat-rader på tid; aktiveras först när gallringsgrinden
  satt handlingstyp + gallringsgrund (annars registrerings-/arkivbrott).
- **activity (+ SDK-logg)** — bevarar varje statusövergång (kopplad/besvarad/vidarebefordrad/registrerad/
  gallrad) med vem/vad/när, 12 mån, som spårbarhetsbevis.
- **collectives (kunskapsbank)** — handlingstyps-/gallringsmallar och rutin "så triageras ej-kopplat" (låst
  utanför skalet).

**Vad i Flow (workflow_engine).** Tre regelklasser på inkommande sdkmc-/mail-händelser:
(1) **dnr-parsning** → sätt `dnr`-kandidat + tagg `route:komplettering`;
(2) **avsändar-/ConversationId-match** → sätt kopplingskandidat + konfidens;
(3) **typklassning** → `route:allman-fraga` / `route:info` / `route:fel-mottagare` / `route:skrap`, där
`route:skrap` föreslår (inte utför) gallring. Reglerna **föreslår** — ingen regel kopplar, registrerar eller
raderar autonomt (människan i loopen). Avancerad logik (konfidensvägning, familje-/syskonfall 1:n) kan på
sikt köras i Windmill/Tables-Flow.

**Vad programmatiskt (Hubs ovanpå native).**
- **ConversationId↔dnr-mappningstabell** (löser GAP-041) som nyckeln bakom auto-koppling och tråd-arv.
- **Gallringsgrinden**: UI + logik som tvingar handlingstyp-val ur DHP och blockerar radering utan
  gallringsgrund; byter knapp till "Registrera utan ärende" när grund saknas.
- **Behörighetsfilter på förslag** (`IConditionalWidget`): auto-kopplingsförslag visas bara för den som har
  åtkomst till målärendet; annars "eskalera".
- **AI-lagret** (`llm2`, lokalt, avstängbart): klassning + kandidatrangordning med "varför"; aldrig
  beslutande.
- **Spegla-vid-bekräftelse** (inte vid förslag) för att undvika felkopplad sekretess i fel rum.

---

## UI i socialsekreterarvyn

- **Nytt band i "Att ta emot": "Ej ärendekopplat".** Triage-zonen delas i *"blir ärende"* (fall a/b, dagens
  orosanmälningskänsla) och *"Ej ärendekopplat"* (fall c/d + otriagerade a/b) med en **röd-när-gammal
  räknare** ("Ej ärendekopplat: 7 — 2 äldre än 3 dagar") som compliance-KPI. Aggregeras **över alla
  korgar** (personlig + grupp + fax + SDK + SMS) så handläggaren slipper hoppa mellan brevlådor.
- **Raden** bär kanalikon · korg · avsändare+LOA (eller "anonym/ej verifierad" som *legitimt* tillstånd) ·
  inkom-tid · **klassnings-chip** · **föreslagen default-åtgärd som primärknapp** + "Mer"-meny med de övriga
  fem åtgärderna. Radtext = ärendereferens/metadata, aldrig klartextcitat.
- **De sex åtgärderna** (Koppla till befintligt ärende [sök dnr/barn] · Skapa nytt ärende · Besvara utan
  ärende · Vidarebefordra/fel mottagare · Gallra utan ärende · Registrera utan ärende) ligger direkt på
  raden. **Gallra** är aldrig ett naket klick — den öppnar gallringsgrinden (handlingstyp → visat
  gallringsbeslut → bekräfta), och byts mot **Registrera** när gallringsgrund saknas.
- **Koppla** glider raden visuellt in i ärendekortet (ProcessStepper + "Nästa åtgärd" tar över). **Skapa**
  startar fristklockan (inkom-datum) och öppnar ett ärendekort. **Besvara/Vidarebefordra** stänger raden
  med spårbar status-chip.
- **Auto-koppling** visas som förifylld topp-kandidat ("Koppla → 2026-IFO-1234 · trolig komplettering,
  samma skola") med synligt "varför"; ett klick bekräftar, ett klick avvisar. Förslag som rör ärenden
  handläggaren saknar behörighet till döljs (visas som "eskalera till gruppledare").
- **Tom hink** ger samma trygghetssignal som tom ärendekö: *"Ej ärendekopplat: 0 — allt inflöde triagerat."*

---

## Källor

**Juridik (registreringsplikt, gallring, allmän handling):**
- Offentlighets- och sekretesslag (2009:400) 5 kap. (registrering) — https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/offentlighets-och-sekretesslag-2009400_sfs-2009-400/
- Legala handboken — Registrering och diarieföring (huvudregel + undantag, sekretess måste registreras) — http://www.legalahandboken.se/offentlighet/regler_reg.html
- Skatteverket, Rättslig vägledning — Registrera allmänna handlingar (OSL 5:1, ringa betydelse, hålla ordnad) — https://www4.skatteverket.se/rattsligvagledning/edition/2024.1/329083.html
- Allmanhandling.se — Registrering av handlingar — https://allmanhandling.se/registrering-av-handlingar/
- Allmanhandling.se — Handlingar som inte hör till något ärende — https://allmanhandling.se/2012/09/16/handlingar-som-inte-hor-till-nagot-arende/
- Lawline — Skyldighet att diarieföra inkomna/upprättade handlingar (sekretess → registrera) — https://lawline.se/answers/skyldighet-for-myndigheter-att-diariefora-inkomna-och-upprattade-handlingar
- Tryckfrihetsförordning (1949:105) 2 kap. (allmän handling, upprättad/inkommen) — https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/tryckfrihetsforordning-1949105_sfs-1949-105/

**Nextcloud Flow / automated tagging / routing (auto-koppling):**
- Nextcloud Flow — automatisera workflows (mejl → mapp → tagg → Deck-kort-exemplet) — https://nextcloud.com/blog/nextcloud-flow-makes-it-easy-to-automate-actions-and-workflows/
- Automated tagging of files (regelbaserad taggning, restricted/invisible-taggar) — https://docs.nextcloud.com/server/stable/admin_manual/file_workflows/automated_tagging.html
- Tagging and Workflows (portal) — https://portal.nextcloud.com/article/Operations/Tagging-and-Workflows

**Intern grund:** `SOCIALSEKRETERARE-WALKTHROUGH.md` (Steg 1–10, 45–47), `GAP-ANALYSIS.md`
(GAP-007/008/026 gallring, GAP-041 ConversationId↔dnr, GAP-043 trippellagring, GAP-049 registrering,
GAP-053 anonym avsändare, GAP-054 routing per funktionsadress), `WIDGET-APP-MAP.md`,
`persona-usage-socialsekreterare.md`, `native-apps-map.md`, `arendehantering-map.md`,
`UX-REDESIGN-SOCIALSEKRETERARE.md`.
