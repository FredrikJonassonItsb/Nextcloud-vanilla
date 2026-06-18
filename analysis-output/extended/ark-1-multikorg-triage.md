<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# ARK-1 — Multi-korg & informationssortering: hur triagen spänner över ALLA korgar

> **Vad detta är:** arkitektur-varv 1 för socialsekreterarvyn ("Mina ärenden"). Frågan: en
> socialsekreterare sitter inte i *en* inkorg utan i **flera korgar samtidigt** (personlig brevlåda,
> gruppkorgar/funktionsadresser, digital fax, SDK). Hur ska triage-zonerna ("Att ta emot" + ny "Att
> hantera (mina korgar)") spänna över allt detta och sortera inflödet *meningsfullt* — per korg, per
> informationstyp, per ärendekoppling — utan att handläggaren drunknar i hög volym?
>
> **Bygger vidare på:** den vunna ärende-centriska vyn i `UX-REDESIGN-SOCIALSEKRETERARE.md` (Zon 1 "Att ta
> emot"), `SOCIALSEKRETERARE-WALKTHROUGH.md` (Akt I + V, kanaler/inflöde), `GAP-ANALYSIS.md` (GAP-053/054,
> -002/046, -041, -017, -049), sdkmc-modellen (`widgetApps.js`, `arendehantering-map.md`,
> `middleware-architecture.md`, `WIDGET-APP-MAP.md`).
>
> **Persona:** `socialsekreterare` (barn & familj, SoL 2025:400, BBIC). **System of record:**
> Treserva/Lifecare/Viva/Combine. **Hubs = mellanlagring.** **Datum:** 2026-06-14.
>
> **Varumärkesregel (enforced):** i produkt-/UI-text aldrig "Nextcloud"/"Talk" — vi säger *korg, säkert
> meddelande, säker e-post, digital fax, SDK, funktionsadress, ärenderum, facksystemet*. App-id namnges
> bara i byggnoteringar.

---

## Kärninsikt först

Dagens Zon 1 "Att ta emot" visar i praktiken **en informationstyp ur en korg** (orosanmälan ur
`orosanmalan@`). Men en socialsekreterare har **flera korgar × många informationstyper**, och de delar inte
samma kognitiva uppgift. Lösningen är att **separera tre frågor som triagen idag blandar ihop**:

1. **Vilken korg kom det in i?** (en behörighets-/OSL-gräns — en korg-väljare/filter, inte en sorteringsaxel)
2. **Vilken informationstyp är det?** (orosanmälan vs komplettering vs medborgarfråga vs remiss vs internpost
   vs fax vs SDK-myndighet vs skräp — styr *vilken åtgärd* raden erbjuder)
3. **Hör det till ett ärende?** (`nytt-ärende` vs `hör-till-ärende` vs `ej-kopplat/oklart`) — **detta är den
   avgörande routing-axeln** som avgör om raden hamnar i **"Att ta emot"** (ska bli/triageras till ärende)
   eller i en ny **"Att hantera (mina korgar)"** (hör redan till mina pågående ärenden, ska *bearbetas*).

"Att ta emot" = *ärendebeslut* (ska detta bli ett ärende, och vems?). "Att hantera" = *ärendearbete* (det här
hör redan till ett av mina ärenden — gör nästa sak med det). Att hålla isär dem är samma Superhuman/Linear-
split som redan motiverar Zon 1↔Zon 2/3 i grundvyn — vi förlänger bara den principen till **allt inflöde, ur
alla korgar**, klassat server-side av sdkmc.

---

## 1. Korg-modellen (typer, behörighet/OSL)

En **korg** är en adresserbar mottagningspunkt i sdkmc. Den är inte en mapp utan en **behörighetsstyrd ström**
av inkommande sdkmc-objekt. Modellen finns redan latent i `widgetApps.js` (`funktionsbrevlador`,
`attHantera`, `kvittenser`) och i SKR:s funktionsadress-rekommendation 2025 — vi gör den explicit.

### 1.1 Korg-typer

| Korg-typ | Adress-exempel | Ägare/scope | Kanaler in i korgen | OSL/behörighet |
|---|---|---|---|---|
| **Personlig brevlåda** | `anna.svensson@kommunen` | Enskild handläggare | internpost (kollega→kollega), säker e-post (securemail), ev. SDK direktadresserad | Bara Anna ser den. Personlig = lägst delningsgrad. |
| **Gruppkorg / funktionsadress** | `mottagningen@`, `barn-familj@`, `orosanmalan@` | En enhet/grupp (mottagnings- eller utredningsgrupp) | internpost, säker e-post, **SDK org-till-org**, digital fax (om fax-nummer pekar hit) | **`IConditionalWidget` = OSL-säkerhetsgräns.** Bara medlemmar i den behöriga gruppen ser korgen och dess innehåll. Otilldelat syns för *enheten*, inte för andra enheter. |
| **Digital fax** | fax-nr → routas till en funktionsadress | Oftast en gruppkorg (`mottagningen@`) | inkommande fax (vårdcentral, privat utförare, ombud utan e-tjänst) | Ärver den korg faxen routas till. Avsändare ofta **ej verifierad** (legitimt, GAP-053). |
| **SDK-korg (org-till-org)** | funktionsadress i nationella adressboken | En enhet | SDK-meddelanden från annan myndighet/region/skola | Avsändare **förhandsidentifierad + LOA3** (SDK-org-cert). Högsta provenance-tillit. |
| **SMS-utkanal** | (utgående huvudsakligen) | — | inkommande SMS-svar i undantagsfall | Lågtillit; aldrig bärare av sekretess in. |

> **En socialsekreterare har tillgång till FLERA korgar samtidigt.** Anna i mottagningsgruppen ser t.ex.
> sin personliga brevlåda + `mottagningen@` + `orosanmalan@` + faxen som routas dit. En utredare ser sin
> personliga + `barn-familj@`/utredningsgruppens korg. Vissa (gruppledare/1:e socialsekreterare) ser **flera
> gruppers** korgar för fördelning. Korg-tillhörighet = roll i organisationen (mottagning vs utredning).

### 1.2 Behörighet är en hård gräns, inte ett filter

Korg-väljaren (avsnitt 5) får **bara visa korgar handläggaren faktiskt har OSL-behörighet till**. Detta är inte
UX-bekvämlighet utan en sekretessgräns: en kollega på vuxenenheten ska inte ens se att `barn-familj@` finns,
än mindre dess rader. Server-side: sdkmc summary-endpoint returnerar bara korgar där den inloggade är medlem
(`IConditionalWidget`-mönstret, redan etablerat för `funktionsbrevlador`). **GAP-054** (routing-/behörighets-
regel per funktionsadress vid delad adress) och **GAP-051** (ACL-granularitet för enhetsvy) är de öppna
luckorna här — korg-modellen gör dem explicita och adresserbara.

### 1.3 Korgen bär provenance — men är INTE sorteringsaxeln

Varje inkommande objekt bär redan (fångat vid mottag, walkthrough Steg 1): **kanal**, **avsändarens
verifierade identitet + LOA**, **tidsstämpel**, **funktionsadress/korg**. Korgen är alltså en *dimension* på
varje rad — men att sortera *primärt* per korg vore fel: det återskapar tretton inkorgar. Korgen blir därför
ett **filter och en härkomst-etikett**, medan den meningsbärande sorteringen sker på **typ + ärendekoppling +
frist** (avsnitt 4).

---

## 2. Informationstyper (vad som faktiskt ligger i korgarna)

Korgarna innehåller MÅNGA informationstyper. Typen styr **vilken åtgärd raden erbjuder** och **default-
routing** (till "Att ta emot" eller "Att hantera"). Server-side klassning i sdkmc summary-endpoint
(`messageType` finns redan i `TriageRad`-shapen) + Flow-regler (avsnitt 6).

| # | Informationstyp | Typisk kanal/korg | Default ärendekoppling | Default zon | Primär åtgärd på raden |
|---|---|---|---|---|---|
| 1 | **Orosanmälan** (ny) | SDK/fax/säker e-post → `orosanmalan@`/`mottagningen@` | `nytt-ärende` | **Att ta emot** | "Ta emot & starta förhandsbedömning" · "Koppla till befintligt" |
| 2 | **Komplettering till befintligt ärende** | SDK/säker e-post/fax → gruppkorg el. personlig | `hör-till-ärende` (om dnr/ConversationId matchar) | **Att hantera** | "Spara i ärenderum" · "Skapa bevakning" |
| 3 | **Fråga / medborgarsvar** | säker e-post + BankID → personlig/gruppkorg | `hör-till-ärende` el. `ej-kopplat` | **Att hantera** (om kopplat) / **Att ta emot** (om oklart) | "Besvara säkert" · "Koppla till ärende" |
| 4 | **Remiss / begäran om uppgifter (in)** | SDK org-till-org → gruppkorg | `hör-till-ärende` el. `nytt-ärende` | **Att ta emot** (om ny part) | "Bedöm & koppla" · "Starta ärende" |
| 5 | **Internpost från kollega** | internpost → personlig | `hör-till-ärende` el. `ej-kopplat` | **Att hantera** | "Öppna" · "Koppla" · "Svara" |
| 6 | **Fax** (vårdcentral/utförare) | digital fax → gruppkorg | `ej-kopplat` (ofta) / `nytt-ärende` | **Att ta emot** | "Bedöm typ" · "Koppla/Starta" |
| 7 | **SDK-myndighet** (annan myndighet) | SDK → gruppkorg | varierar | **Att ta emot** | "Bedöm & routa" |
| 8 | **Skräp / fellevererat / dubblett** | valfri | `ej-kopplat` | **Att ta emot** (avförs snabbt) | "Avför (fel adressat)" · "Vidarebefordra rätt" |

**Två observationer:**

- Typen är **inte** alltid given av kanalen. Samma SDK-korg kan bära både en ny orosanmälan (typ 1) och en
  komplettering till ett pågående ärende (typ 2). Därför krävs *både* kanalklassning och typklassning, och en
  **ärendematchning** (avsnitt 3) ovanpå.
- Typ 8 (skräp/fel) måste vara en **förstklassig, snabb åtgärd**, inte en gråzon. Vid hög volym är "avför fel
  adressat i ett klick" det som håller köerna rena. Avförande loggas (Activity/SDK-logg) — aldrig tyst radering
  av ett inkommande (det kan vara allmän handling).

---

## 3. Sorterings-/klassningsmodellen (den bärande axeln: ärendekoppling)

Triagen klassar varje inkommande rad längs **tre ortogonala axlar**. Axel C är den som avgör zon.

### Axel A — Korg (härkomst, behörighet)
`personlig | gruppkorg:<addr> | fax | sdk`. Server-filtrerad till behöriga korgar. Används som **filter +
etikett**, inte primär sortering.

### Axel B — Informationstyp
De åtta typerna ovan (`messageType`). Styr **radens åtgärdsknappar** och **batch-gruppering** (avsnitt 7).

### Axel C — Ärendekoppling (routar till zon)

| Klass | Betydelse | Hur den bestäms (server-side) | Zon |
|---|---|---|---|
| **`nytt-ärende`** | Inget befintligt ärende; ska bli ett (förhandsbedömning). | Typ = orosanmälan; ingen dnr/ConversationId-match. | **Att ta emot** |
| **`hör-till-ärende`** | Hör till ett av mina (eller enhetens) pågående ärenden. | **dnr/ConversationId-match** mot ärenderegistret (GAP-041). Hög konfidens → auto-koppling; låg → "föreslås koppla". | **Att hantera** |
| **`ej-kopplat / oklart`** | Kan inte säkert kopplas och är inte tydligt en ny orosanmälan. | Ingen match + typ ≠ ren orosanmälan (fax, fråga, internpost utan referens). | **Att ta emot** (som triage-beslut: koppla / starta / avför) |

> **Den avgörande designregeln:** zon bestäms av **Axel C**, inte av korg och inte av typ. `zonOf()` i
> grundvyn utökas:
>
> ```js
> // utökad zonOf — ärendekoppling routar inflöde; frist/plikt routar ärendekort (oförändrat)
> function zonOf(item) {
>   if (item.kind === 'inflode') {
>     if (item.arendekoppling === 'hor_till_arende') return 'attHantera'  // NY zon
>     return 'attTaEmot'                                                  // nytt-ärende + ej-kopplat
>   }
>   // ...befintlig ärendekort-logik (het/aktiv) oförändrad
> }
> ```

### Ärendematchnings-konfidens (GAP-041, GAP-005)
`hör-till-ärende` kräver en **ConversationId/dnr ↔ ärende-mappning**. Modellen:

- **Hög konfidens** (SDK ConversationId matchar ett aktivt ärendes lagrade tråd, eller dnr i ämnesrad): rad
  routas direkt till "Att hantera" med ärendechip **förkryssad** ("hör till Barn 2026-0142"). Handläggaren kan
  ändra.
- **Låg konfidens** (namn/personnummer-pseudonym matchar men ingen tråd-id): rad visas i "Att ta emot" med en
  **föreslagen koppling** ("Liknar Barn 2026-0142 — bekräfta?").
- **Ingen match**: `nytt-ärende` (om orosanmälan) eller `ej-kopplat`.

Detta gör ärendekopplingen till **system-stödd men människo-bekräftad** — aldrig en gissning som tyst flyttar
sekretess till fel akt (felkoppling är en sekretessincident, jfr GAP-005 syskon-/familjefall 1:n).

### Frist som tvärgående prioritet (oförändrad princip)
Frist är **inte** en fjärde klassningsaxel utan en **prioritet inom varje zon**. Orosanmälningar i "Att ta
emot" bär sin **14-dgr `FristChip` som tickar från inkom-datum** (GAP-002/046/055 — bunden till provenance,
inte till plock/öppning). Kompletteringar i "Att hantare" ärver sitt ärendes frist. Sorteringen inom zon:
**frist (röd→gul) → väntar-på-mig → sekretessnivå → oläst** (samma deterministiska ordning som Zon 2 i
grundvyn).

---

## 4. Hur "Att ta emot" och "Att hantare (mina korgar)" ser ut

Två sektioner, samma datakälla (`fetchInflodeSummary` — utökar `fetchArendeSummary`), olika kognitiv uppgift.
Båda ligger i `MinaArenden.vue`-kolumnen ovanför ärendekorten (Zon 1 i grundvyns layout splittas i 1a + 1b).

### 4.1 ZON 1a — "Att ta emot" (triage → ärendebeslut)

Otrierat inflöde som ska bli/routas till ärende: `nytt-ärende` + `ej-kopplat`. Spänner över **alla behöriga
korgar** (default), filtrerbart per korg.

```
┌──────────────────────────────────────────────────────────────────────────────────────┐
│  ATT TA EMOT · 5            Korg: [Alla ▾]   Typ: [Alla ▾]   sort: frist ▾              │
│  ──────────────────────────────────────────────────────────────────────────────────── │
│  ▸ [SDK·orosanmalan@] Orosanmälan – nytt barn · Skolkurator SITHS·LOA3 · 07:58         │
│        ⏰ 14 dgr (tickar)   → Treserva: ej registrerad                                  │
│        [Ta emot & starta förhandsbedömning]   [Koppla till befintligt]   [Visa]         │
│  ▸ [FAX·mottagningen@] Fax – vårdcentral · avsändare EJ VERIFIERAD · 08:12              │
│        ej-kopplat   → bedöm: orosanmälan? komplettering? fel adressat?                   │
│        [Starta ärende]   [Koppla till befintligt]   [Avför (fel adressat)]   [Visa]     │
│  ▸ [SDK·barn-familj@] Remiss/begäran – annan myndighet · LOA3 · 08:20                   │
│        ej-kopplat   → bedöm & routa                                                      │
│        [Bedöm & koppla]   [Starta ärende]   [Avför]   [Visa]                             │
│  … (tom → "Inget otriagerat — allt inkommande ur dina korgar är omhändertaget")         │
└──────────────────────────────────────────────────────────────────────────────────────┘
```

- **Varje rad bär korg-etikett + kanalikon + typ-ikon + LOA-badge (inkl. legitimt "Ej verifierad/anonym") +
  frist-chip + provenans-chip.** Korttext = ärendereferens, aldrig klartext-PII (GDPR, dataminimering).
- **Tre triage-utfall per rad:** *starta nytt ärende* (→ ärendekort, Akt I Steg 2), *koppla till befintligt*
  (→ blir "Att hantera"-post på det ärendet), *avför* (fel adressat/skräp — loggat). Detta operationaliserar
  GAP-049 (provenans "ej registrerad" som öppen åtgärd) och GAP-053 (ej-verifierad som legitimt tillstånd).
- `aria-live="polite"` runt listan (inkommande aviseras tillgängligt).

### 4.2 ZON 1b — "Att hantare (mina korgar)" (NY — inflöde till pågående ärenden)

Inkommande som **redan hör till ett av mina ärenden** (`hör-till-ärende`): kompletteringar, medborgarsvar,
remissvar, internpost med dnr. Detta är det som idag försvinner mellan "ärendekort" och "inkorg" — det är
*ärendearbete*, inte triage, men det kommer in via korgarna.

```
┌──────────────────────────────────────────────────────────────────────────────────────┐
│  ATT HANTERA (MINA KORGAR) · 8     Korg: [Alla ▾]  Gruppera: [Per ärende ▾]            │
│  ──────────────────────────────────────────────────────────────────────────────────── │
│  ▾ Barn 2026-0142 · dnr 2026-IFO-0142                                    ⏰ 4 mån (gul) │
│      ▸ [SDK·barn-familj@] Pedagogisk kartläggning (komplettering) · Skola LOA3 · 08:14 │
│            [Spara i ärenderum]   [Skapa bevakning]   [Öppna ärende]                      │
│      ▸ [e-post] Svar från vårdnadshavare (BankID) · 08:31                                │
│            [Spara i ärenderum]   [Besvara säkert]                                        │
│  ▾ Barn 2026-0412 · dnr 2026-IFO-0412                                    ⏰ 3 v överkl. │
│      ▸ [internpost] Notering från gruppledare · 07:50                                    │
│            [Öppna]   [Skapa bevakning]                                                   │
│  … (tom → "Inget olöst inflöde till dina pågående ärenden")                              │
└──────────────────────────────────────────────────────────────────────────────────────┘
```

- **Default gruppering: per ärende** (kollapsbara ärendegrupper) — så att allt som hör till samma barn syns
  ihop oavsett vilken korg det kom in via. Alternativ gruppering: **per korg** eller **per typ** (för
  batch-bearbetning, avsnitt 7).
- Raderna är **inte** fulla ärendekort — de är lätta "inflöde-till-ärende"-rader med 1–2 åtgärder (spara i
  rummet / skapa bevakning / besvara). Att spara en komplettering i ärenderummet är Akt II Steg 14; raden
  lämnar "Att hantare" när den är omhändertagen.
- Ärendechip + frist ärvs från ärendet (speglad ur Treserva, GAP-018). En komplettering kan inte ha *egen*
  laglig frist — den ärver ärendets klocka.

### 4.3 Relation till ärendekorten (Zon 2/3 oförändrade)
"Att ta emot" och "Att hantare" är **inflöde-zoner**; ärendekorten (Zon 2 heta, Zon 3 alla aktiva) är
**ärende-zoner**. En rad i "Att hantare" pekar på ett ärendekort men dubblerar det inte — när handläggaren
sparar kompletteringen i rummet syns den därefter i ärendekortets Meddelanden-/Dokument-flik. Dagspulsens
`📥 nya`-räknare = summan av "Att ta emot" + "Att hantare".

---

## 5. Korg-väljare & filter

En **horisontell korg-rad** överst i inflöde-sektionen, plus typ-filter. Designad för att en handläggare med
4–6 korgar snabbt ska kunna fokusera/avfokusera utan att tappa helheten.

```
Korg:  [● Alla 13]  [Personlig 2]  [mottagningen@ 5]  [orosanmalan@ 3]  [barn-familj@ 2]  [Fax 1]
Typ:   [Alla]  [Orosanmälan]  [Komplettering]  [Fråga]  [Remiss]  [Internpost]  [Fax]  [SDK]  [Skräp]
```

- **Default: "Alla korgar".** Poängen med vyn är att handläggaren *slipper* öppna korg för korg — allt inflöde
  ur alla behöriga korgar är samlat och klassat. Korg-väljaren är ett **fokus-filter** (klick på `orosanmalan@`
  visar bara den korgen), aldrig en navigeringskostnad.
- Varje korg-pille bär en **räknare** (otriagerat i den korgen). En gruppkorg vars räknare aldrig når noll med
  obehandlade rader kvar är en synlig enhets-skuld (jfr Dagspulsens compliance-ankare).
- Korg- och typ-filter **kombineras** (korg ∩ typ). Filtren är knapp-/tangentbordsstyrda (WCAG 2.5.7 — aldrig
  drag), färg aldrig enda informationsbärare (ikon + text + tal).
- **Gruppledar-/1:e-läge:** den som har behörighet till flera gruppers korgar får en extra dimension i
  väljaren (gruppkorgar för *fördelning*, se avsnitt 8). En vanlig handläggare ser bara sina egna.

---

## 6. Mappning mot sdkmc-kanalklassning + funktionsadresser (vad som byggs var)

Triagens klassning är till stor del **redan server-side** i sdkmc summary-endpoint; multi-korg-vyn lägger på en
korg-dimension och en ärendematchning. Genomgående: **klassning sker i sdkmc/Flow, inte i klienten** (CONTRACTS
hård regel: ingen klient-fan-out).

| Klassningssteg | Var det görs | Mekanism |
|---|---|---|
| **Korg-tillhörighet + behörighet** | sdkmc funktionsadress-stöd | Summary-endpoint returnerar bara behöriga korgar (`IConditionalWidget` = OSL-gräns). Härkomst-fält per rad: `korg`, `kanal`, `LOA`, `tidsstämpel`. |
| **Kanalklassning** (SDK/e-post/fax/internpost/SMS) | sdkmc summary-endpoint (finns idag) | `channel` + `channelLabel` i `TriageRad`-shapen. |
| **Informationstyp** (de 8 typerna) | sdkmc + **Flow/workflow_engine** | Regler på avsändar-cert, ämne, ConversationId-mönster, källkorg → sätt `messageType`. Flow kan auto-tagga inkommande och routa (dokumenterad kapabilitet: "mail → special inbox → tag → Deck-kort"). |
| **Ärendematchning** (Axel C) | sdkmc ärenderegister + Flow | ConversationId/dnr ↔ ärende-mappning (GAP-041). Hög/låg/ingen konfidens → `arendekoppling`. |
| **Auto-routing** (spara komplettering i rätt ärenderum) | Flow + groupfolders | Vid hög-konfidens-match kan en bilaga auto-speglas till ärenderummet (walkthrough Steg 45 "om auto-routing är på"); annars manuellt. |
| **Prioritet/frist** | server-beräknad, speglad | `FristChip.tone` ren funktion av `(due − idag)`, bunden till inkom-datum. Speglas ur Treserva för pågående ärenden (ingen självständig Hubs-räkning, GAP-018). |

Funktionsadress-modellen följer **SKR:s rekommendation 2025** (grundläggande funktionsadresser kopplade mot
Diggs kodverk) — `orosanmalan@`, `mottagningen@`, `barn-familj@` är konkreta exempel. Den nationella
adressboken gör att SDK-meddelanden landar på rätt funktionskorg, vilket minskar personberoendet.

---

## 7. Hög volym utan att drunkna (batch, prioritet, frist)

Nationellt ~400 000+ orosanmälningar/år, ~62 % leder inte till utredning, mottagning är högst prioriterad
funktion. Vyn måste skala till **många rader per dag per handläggare/grupp** utan att bli en oöverblickbar
mejlhög. Fyra mekanismer:

1. **Batch per typ.** Typ-filtret gör att handläggaren kan beta av **en informationstyp i taget** — "alla
   kompletteringar nu, alla nya orosanmälningar sen". Samma typ → samma åtgärd → muskelminne. I "Att hantare"
   ger gruppering "Per typ" en ren batch-vy (alla "spara i ärenderum" tillsammans).
2. **Batch per korg.** Mottagningsgruppen kan jobba `orosanmalan@` som ett pass; utredaren `barn-familj@`.
   Korg-filtret stödjer arbetspass-tänket utan att dölja helheten.
3. **Prioritet = frist + sekretess, deterministisk.** Default-sortering inom varje zon:
   **frist (röd→gul→grå) → väntar-på-mig → sekretessnivå → oläst.** Heta orosanmälningar (få dagar kvar)
   kan aldrig scrollas bort — de pinnas överst, precis som Zon 2-kort. Frist är **härledd ur inkom-datum**, så
   den brinner även om handläggaren inte öppnat raden (GAP-055).
4. **Frist-säkerhet som tak, inte golv.** En orosanmälan kan ligga i "Att ta emot" i 14 dagar, men 14-dgr-
   chipen tickar från dag ett. Skyddsbedömningen (samma dag / dagen efter, 11 kap. 1 a § SoL) modelleras som en
   **röd pliktmarkör** på raden om den inte är kvitterad — samma hårda spärr som ärendekortets pliktmarkör
   (graft processboard). En obehandlad skyddsbedömning kan inte "scrollas bort".

**Valfritt AI-lager (avstängbart, lokalt, transparent):** ovanpå sorteringen kan `llm2` *föreslå* ordning per
korg med synligt "varför" ("hög prio: frist imorgon + okänd avsändare"). AI prioriterar **ärendeegenskaper**
(frist/sekretess/oläst/okänd avsändare), aldrig användarbeteende; **döljer/avför aldrig** en rad; **skriver
aldrig** till facksystemet (GDPR art. 22). Människan triagerar och committar. Detta är samma doktrin som
`persona-usage-socialsekreterare.md` redan slår fast — multi-korg-vyn är exakt ytan där den ger mest värde.

**Tomma tillstånd som compliance-kvitto.** "Att ta emot" tom → *"Inget otriagerat ur dina korgar — allt
inkommande är omhändertaget."* "Att hantera" tom → *"Inget olöst inflöde till dina pågående ärenden."* En
ren kö = inget barn mellan stolarna, inte bara en tom skärm.

---

## 8. Gruppledare/1:e socialsekreterare: fördelning ur gruppkorgen (avgränsat)

Den svenska processen: mottagnings-/utredningsgrupp med **gruppledare/1:e socialsekreterare** som **fördelar**
otilldelat inflöde på morgonmötet. Multi-korg-vyn stödjer detta med en **fördelnings-affordans i "Att ta
emot"** för den som har behörighet:

- Otilldelade rader i en gruppkorg visar **"Tilldela →"** (till handläggare i gruppen) utöver "Ta emot själv".
- Plock vs fördela: en handläggare kan **plocka** (ta själv); en gruppledare kan **fördela** (tilldela annan).
  Båda binder *tilldelning*, inte fristen — 14-dgr-klockan löper från inkom-datum oavsett (GAP-002/046).
- Detta är en **avgränsad** del av detta varv (full fördelnings-/gruppledarvy är eget arkitektur-varv) — här
  räcker att korg-modellen + behörighetsgränsen + "Tilldela"-knappen finns, så att gruppkorgen inte tvingar
  alla att jobba samma rader.

---

## Implementering

**Vilka appar.** Inflödet bärs av **sdkmc** (kanaler/korgar/funktionsadress + summary-endpoint, kanal- och
typklassning, ConversationId/dnr-match), **securemail** (säker e-post), **mail/fax-brygga** (digital fax),
samt ID-core (LOA/identitet per rad). Triagens utfall landar i: **groupfolders** (spara komplettering i
ärenderum, Akt II Steg 14), **deck/tasks** ("Skapa bevakning från rad", Akt V Steg 46), och
ärendekort-state machine (starta förhandsbedömning, Akt I Steg 2). Behörighet = `IConditionalWidget`.

**Vad i Flow (workflow_engine).** (a) **Auto-typning:** regler på avsändar-cert/ämne/källkorg/ConversationId-
mönster → sätt `messageType` (de 8 typerna) — Flow:s dokumenterade "mail→inbox→tagg→routa"-kapabilitet rakt
av. (b) **Auto-routing:** hög-konfidens ärendematch → spegla bilaga till ärenderummet (groupfolders) +
sätt restricted Retention-tagg. (c) **Pliktmarkör-trigger:** ny orosanmälan utan kvitterad skyddsbedömning →
flagga raden röd. Allt server-side; klienten speglar.

**Vad programmatiskt (sdkmc + hubs_start).**
- sdkmc: utöka summary-endpoint till **`fetchInflodeSummary()`** → `{ korgar:[{addr,scope,otriagerat}],
  inflode:[InflodeRad] }` där `InflodeRad = { id, korg, channel, messageType, arendekoppling:('nytt'|
  'hor_till'|'ej_kopplat'), arendeRef?, konfidens, avsandare, identitet, inkomDatum, frist?, plikt?,
  provenance }`. Behörighetsfiltrera korgar server-side. Specificera **ConversationId↔dnr-mappning** (GAP-041)
  och **token↔dnr** (GAP-005) i datamodellen.
- hubs_start: utöka `zonOf()` (route på `arendekoppling`); ny `AttHanteraSektion.vue` (Zon 1b, gruppering
  per ärende/korg/typ) bredvid befintlig `AttTaEmotSektion.vue` (Zon 1a); ny `KorgValjare.vue` (korg- +
  typ-filter, knapp/tangentbord, räknare); `InflodeRad.vue` (lätt rad med typ-styrda åtgärder); återanvänd
  `FristChip`, `ProvenansChip`, `LoaChip`, `channelMeta`. Demo: `demo/socialsekreterare.js` får 4–6 korgar
  och ~13 inflöde-rader över alla typer (inkl. 1 fax ej-verifierad, 1 skräp/fel, 3 hör-till-ärende).
- Öppna gap som detta varv gör adresserbara men inte stänger: **GAP-041** (match-mekanism), **GAP-054**
  (delad-adress-routing), **GAP-051** (enhets-ACL-granularitet), **GAP-005** (1:n barn/syskon).

## UI i socialsekreterarvyn

I "Mina ärenden" splittas dagens Zon 1 i **två sektioner** ovanför ärendekorten:

- **"Att ta emot" (Zon 1a)** — otrierat inflöde ur **alla behöriga korgar** (`nytt-ärende` + `ej-kopplat`),
  med korg-etikett + kanal-/typ-ikon + LOA-badge + tickande frist-chip per rad och tre triage-utfall
  (*starta · koppla · avför*). Spänner över personlig brevlåda, gruppkorgar, fax och SDK samtidigt.
- **"Att hantera (mina korgar)" (Zon 1b, NY)** — inkommande som redan hör till ett pågående ärende
  (`hör-till-ärende`: kompletteringar, medborgarsvar, remissvar, internpost), **grupperat per ärende** med
  lätta åtgärder (*spara i ärenderum · skapa bevakning · besvara*). Det stänger gapet "inkorg↔ärende".

Överst i båda: en **korg-väljare** (Alla / Personlig / varje gruppkorg / Fax, med räknare) + **typ-filter**,
så att handläggaren kan **batcha per korg eller per typ** vid hög volym men ändå default-se allt samlat.
**Dagspulsens `📥 nya`** = "Att ta emot" + "Att hantera" och fungerar som filter. Frist styr prioritet inom
varje zon (röd→gul→grå, härledd ur inkom-datum); en okvitterad skyddsbedömning på en ny orosanmälan bär en
**röd pliktmarkör** som inte kan scrollas bort. Tomma köer formuleras som compliance-kvitto ("allt inkommande
är omhändertaget"), inte bara tom skärm.

---

*Grundas i `UX-REDESIGN-SOCIALSEKRETERARE.md`, `SOCIALSEKRETERARE-WALKTHROUGH.md` (Akt I + V),
`GAP-ANALYSIS.md` (GAP-002/005/018/041/049/051/053/054/055), `arendehantering-map.md`,
`native-apps-map.md`, `WIDGET-APP-MAP.md`, `middleware-architecture.md`, `widgetApps.js`. Web: svensk
mottagnings-/utredningsgruppsorganisation + gruppledare/1:e socialsekreterare; SKR funktionsadress-
rekommendation 2025 + nationell adressbok; orosanmälningsvolym/14-dgr-frist; Nextcloud Flow/workflow_engine
auto-tagg/routing. Varumärkesregel: aldrig "Nextcloud"/"Talk" i UI-text.*

### Källor (web)
- Mottagnings-/utredningsgrupp, barn & familj: [Stockholms stad — mottagningsgrupp barn, unga och familj](https://jobba.stockholm/lediga-jobb/platsannonser/socialsekreterare-mottagningsgrupp-barn-unga-och-familj-913462) · [Trollhättan — Mottagningsgruppen](https://www.trollhattan.se/startsida/omsorg-och-hjalp/familj-barn-och-ungdom/mottagningsgruppen/) · [Vänersborg — Utredningsgrupp barn och unga](https://www.vanersborg.se/omsorg-och-hjalp/familj-barn-och-ungdom/utredningsgrupp-barn-och-unga)
- SDK / funktionsadresser / behörighet: [SKR — Säker digital kommunikation (SDK)](https://skr.se/digitaliseringivalfarden/digitalinfrastruktur/sakerdigitalkommunikationsdk.9116.html) · [SKR — Införande SDK för socialtjänsten](https://skr.se/skr/naringslivarbetedigitalisering/digitalisering/handslagfordigitalisering/initiativhandslagfordigitalisering/initiativ/inforandesakerdigitalkommunikationsdkforsocialtjansten.81837.html) · [Digg — Vad är Säker digital kommunikation](https://www.digg.se/saker-digital-kommunikation/vad-ar-saker-digital-kommunikation)
- Volym / 14-dgr / triage: [Socialstyrelsen — Fler orosanmälningar](https://www.socialstyrelsen.se/om-socialstyrelsen/pressrum/press/fler-orosanmalningar-till-socialtjansten--okad-kunskap-en-av-forklaringarna/) · [Bris — Så funkar en orosanmälan](https://www.bris.se/for-vuxna/bris-guidar/sa-funkar-en-orosanmalan/)
- Nextcloud Flow / auto-tagg / routing: [Nextcloud — Flow automate actions and workflows](https://nextcloud.com/blog/nextcloud-flow-makes-it-easy-to-automate-actions-and-workflows/) · [Tagging and Workflows (Nextcloud Portal)](https://portal.nextcloud.com/article/Operations/Tagging-and-Workflows)
