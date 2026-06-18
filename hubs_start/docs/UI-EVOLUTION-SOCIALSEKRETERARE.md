<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# UI-EVOLUTION — Socialsekreterarvyn ("Mina ärenden") för multi-korg, chatt, tilldelning & ärende-tagg

> **Status:** byggbar spec (evolution av `UX-REDESIGN-SOCIALSEKRETERARE.md`), 2026-06-14
> **Stack:** Vue 2.7 + `@nextcloud/vue` v8 · standalone-appen `hubs_start` (namespace `HubsStart`)
> **Persona i centrum:** `socialsekreterare` (barn & familj). Roll-läge `gruppledare` införs lättviktigt.
> **Bygger på (oförändrad kärna):** `MinaArenden.vue` + zon-arkitekturen + `zonOf()`-selectorn + `ArendeKort` +
> `ProcessStepper` + `NastaAtgardKnapp` + `FristChip`/`ProvenansChip`/`LoaChip` + `Dagspulsen` + `CommitGrind`.
> **Arkitekturgrund:** `HUBS-ARKITEKTUR-SOCIALTJANST.md` (kanonisk `hubsCaseId`, Flow vs programmatiskt, Deck-kopplingen).
>
> **Designvakt (genomgående):** socialsekreteraren i centrum, **ärende-först aldrig kanal-först**, och **ingen ökad
> kognitiv last** — varje ny yta är en *evolution av en befintlig zon*, inte en trettonde widget. Multi-korg, chatt,
> tilldelning och ärende-tagg läggs in i den befintliga enspalts-layouten utan att röra ärendekortets överlägsna
> per-ärende-workflow.
>
> **Varumärkesregel (enforced):** UI-text säger aldrig "Nextcloud"/"Talk"/"Circles". Vi säger *korg, funktionsadress,
> säkert meddelande, ärendechatt, enhetschatt, team, omnämnande, ärenderum, bevakning, ärende-tagg/koppling, fördela*.
> App-id namnges bara i byggnoteringar.
>
> **Konventioner (ärvda ur `CONTRACTS.md` / befintliga komponenter):** `export default { name, components, props, data,
> computed, methods }`; alla strängar via `t('hubs_start', …)`; `.hs-card`-skal + tokens i `css/variables.scss`
> (`--hs-status-success/-warning/-error`, `--hs-target`); klickytor ≥24×24 px; färg aldrig enda informationsbärare
> (ikon + text + tal); inga drag-only-interaktioner (WCAG 2.5.7); per-komponent-import av Nc*; `channelMeta()`/`deepLinks`/
> `sections.js` återanvänds. **Ingen klient-fan-out** (CONTRACTS regel 6) — allt ur server-side-aggregat.

---

## 0. Karta: vad som läggs till var (utan att öka kognitiv last)

Den befintliga vyn renderar sju zoner i `MinaArenden.vue`. Evolutionen rör **fyra punkter** och lämnar resten orört:

```
┌──────────────────────────────────────────────────────────────────────────────┐
│ ZON 0 — MinDagHeader + Dagspulsen        + 💬 omnämnanden (femte räknare)  (b) │
├──────────────────────────────────────────────────────────────────────────────┤
│ ZON V — VadVillDuGora                    (oförändrad)                           │
├──────────────────────────────────────────────────────────────────────────────┤
│ ZON 1 — TRIAGE  ► splittas i tre band via KorgValjare + flikar:                │
│   1a "Att ta emot"  (nytt-ärende)              [AttTaEmotSektion, oförändrad]   │
│   1b "Att hantera (mina korgar)" (hör-till)    [AttHanteraSektion]          (a) │
│   1c "Ej ärendekopplat" (c/d + otriagerat)     [EjKoppladSektion]           (a) │
│   ── överst: KorgValjare (korg-piller + typ-filter, räknare)               (a) │
├──────────────────────────────────────────────────────────────────────────────┤
│ ZON 2/3 — ArendeZon · ArendeKort:                                              │
│   + KopplingBadge "Kopplad till Barn 2026-0142" på objekt i flikarna       (d) │
│   + DiskussionChip 💬/💬@ på kollapsat kort; flik "Diskussion"             (b) │
│   + TilldelningBand "tilldelad/otilldelad · från mottagningen"             (c) │
├──────────────────────────────────────────────────────────────────────────────┤
│ ZON 4 — MotesRemsa                       (oförändrad; närvaroprick valfri)  (b) │
├──────────────────────────────────────────────────────────────────────────────┤
│ ZON 5 — Foten                            + "Enhetschatt ▸"-ingång (sidopanel)(b)│
└──────────────────────────────────────────────────────────────────────────────┘
   + Roll-läge 'fordelning' (gruppledare): FordelningsVy ovanpå samma kort/zon  (c)
```

**Kognitiv-last-principen:** band 1b/1c är **kollapsbara** och tomma-som-default när inget inflöde finns (compliance-kvitto,
inte tom skärm). Chatt är **pull** (en chip + en räknare + en sidoyta) — den enda push-signalen är ett omnämnande till mig.
Tilldelningsstatus är en **diskret rad**, inte ett nytt kort. Ärende-taggen visas som en **liten badge** på objekt, aldrig som
rå `hubsCaseId`. Fördelningsvyn är ett **roll-läge**, inte en ny app.

---

## (a) KORG-spännande triage — "Att hantera", korg-väljare, typ-sortering, ärendekopplings-status

### Komponent: `KorgValjare.vue` (NY) — Zon 1 topp

- **Syfte:** låta handläggaren med 4–6 korgar fokusera/avfokusera per korg + per typ, utan navigeringskostnad. Default "Alla korgar".
- **Props:**
  - `korgar: Array<Korg>` — `{ addr, label, scope:('personlig'|'grupp'|'fax'|'sdk'), otriagerat:Number }`
  - `aktivKorg: String|null` (null = "Alla")
  - `aktivTyp: String|null` — en av de 8 `messageType`-värdena (`'orosanmalan'|'komplettering'|'fraga'|'remiss'|'internpost'|'fax'|'sdk_myndighet'|'skrap'`)
- **Events:** `@valj-korg(addr|null)`, `@valj-typ(typ|null)`.
- **Visar:** en horisontell rad **korg-piller** (`[● Alla 13] [Personlig 2] [mottagningen@ 5] [orosanmalan@ 3] [Fax 1]`),
  var och en med `NcCounterBubble` (otriagerat i korgen); under den en **typ-filter-rad**. Korg ∩ typ kombineras. Knapp-/tangentbordsstyrt
  (WCAG 2.5.7), `aria-pressed` på aktiv. Korgar server-filtrerade till behöriga (`IConditionalWidget` = OSL-gräns) — visar bara det handläggaren får se.
- **Hur den ser ut:** samma piller-grammatik som `Dagspulsen`-räknarna (ikon + text + tal), accent-fylld vid aktiv. Reflow: wrap i smal vy.

### Komponent: `AttHanteraSektion.vue` (NY) — Zon 1b, "Att hantera (mina korgar)"

- **Syfte:** inkommande som **redan hör till ett av mina ärenden** (`hör-till-ärende`) — kompletteringar, medborgarsvar, remissvar,
  internpost. Det är *ärendearbete*, inte triage. Stänger gapet "inkorg↔ärende".
- **Props:** `items: Array<InflodeRad>`, `gruppering: String` (`'arende'|'korg'|'typ'`, default `'arende'`), `aktivKorg/aktivTyp`.
- **Events:** `@spara-i-rum(rad)`, `@skapa-bevakning(rad)`, `@besvara(rad)`, `@open-arende(rad)`, `@satt-gruppering(g)`.
- **Visar:** kollapsbara **ärendegrupper** (default gruppering: per ärende — allt som hör till samma barn syns ihop oavsett korg),
  med ärveda frist + ärendechip per grupp. Varje rad är **lätt** (`AttHanteraRad`, inte ett fullt kort) med 1–2 åtgärder + en
  **`KopplingBadge`** ("Kopplad till Barn 2026-0142"). Alternativ gruppering per korg/typ för batch.
- **Empty:** `NcEmptyContent` *"Inget olöst inflöde till dina pågående ärenden."*

### Komponent: `EjKoppladSektion.vue` + `EjKoppladRad.vue` (NY) — Zon 1c, "Ej ärendekopplat" (se även (e))

- Beskrivs i sektion **(e)** nedan (delar `InflodeRad`-shapen och korg-/typ-filtret).

### Återanvändning: `AttTaEmotSektion.vue` (1a) — oförändrad

Behåller dagens orosanmälningskänsla (`nytt-ärende`). De tre banden delar **samma datakälla** (`fetchInflodeSummary`) och samma
korg-/typ-filter; bara den kognitiva uppgiften skiljer (beslut vs arbete vs registrering).

### Lätt rad-komponent: `InflodeRad.vue` (NY, delas av 1b/1c)

- **Props:** `rad: InflodeRad`, `actions: Array<{key,label,primary?}>` (typ-styrda).
- **Visar:** kanalikon (`channelMeta`) · **korg-etikett** · avsändare + `LoaChip`/identitets-badge (inkl. legitimt "Ej verifierad — anonym") ·
  inkom-tid · **typ-chip** · **`KopplingBadge`** (kopplad/föreslagen/ej) · ärveda `FristChip`/`ProvenansChip`. Korttext = ärendereferens,
  aldrig klartextcitat (GDPR). Primär åtgärd som `type="primary"`-knapp, övriga i `NcActions`-meny ("Mer").
- **Mönster:** byggs i samma stil som befintliga `AttTaEmotRad.vue` (`<li>` + kanal-chip + body + actions, samma SCSS-grammatik och reflow @720px).

### Store/zon-routing

Utöka `MinaArenden.vue`s `zonOf()` (befintlig, ren selector) så att inflöde routas på `arendekoppling`:

```js
// MinaArenden.vue — utökad zonOf (inflöde routas på ärendekoppling; ärendekort-logiken oförändrad)
zonOf(item) {
  if (item.kind === 'inflode') {
    if (item.arendekoppling === 'hor_till')  return 'attHantera'  // Zon 1b
    if (item.arendekoppling === 'ej_kopplat') return 'ejKopplad'  // Zon 1c
    return 'attTaEmot'                                            // 'nytt' → Zon 1a
  }
  // ...befintlig ärendekort-logik (het/aktiv) HELT oförändrad
  if (item.steg === 'inflode') return 'taEmot'
  if (item.frist && (item.frist.tone === 'error' || item.frist.tone === 'warning')) return 'het'
  if (item.plikt && !item.plikt.kvitterad) return 'het'
  if (item.nastaAtgard && item.nastaAtgard.vantarPaMig) return 'het'
  return 'aktiv'
}
```

`MinaArenden.vue` får tre computed (`taEmotItems`, `attHanteraItems`, `ejKoppladItems`) som filtrerar `A.inflode` genom korg/typ +
`zonOf`. Dagspulsens `📥 nya` = summan av de tre banden.

### Demodata-shape: `InflodeRad` + `Korg`

```js
// InflodeRad — driver Zon 1a/1b/1c (utökar TriageRad med korg + typ + ärendekoppling)
{
  id: 'inf-12',
  kind: 'inflode',
  korg: { addr: 'barn-familj@', label: 'barn-familj@', scope: 'grupp' },
  channel: { channel: 'sdk', channelLabel: 'SDK-Meddelande', messageType: 'komplettering' },
  messageType: 'komplettering',                 // en av de 8 typerna (Axel B)
  arendekoppling: 'hor_till',                    // 'nytt' | 'hor_till' | 'ej_kopplat'  (Axel C → zon)
  koppling: {                                    // KopplingBadge-payload (d)
    status: 'kopplad',                           // 'kopplad' | 'foreslagen' | 'ej'
    barnRef: 'Barn 2026-0142', dnr: '2026-IFO-0142', konfidens: 0.94,
  },
  avsandare: 'Skola, Lindängsskolan',
  identitet: { badge: 'SITHS · LOA3', verifierad: true },
  titel: 'Pedagogisk kartläggning (komplettering)',  // ärendereferens, ingen PII
  inkomDatum: '2026-06-14T08:14:00',
  frist: null,                                   // ärvs från ärendet (ej egen)
  provenance: { state: 'registrerad', dnr: '2026-IFO-0142' },
}

// Korg — KorgValjare-piller
{ addr: 'mottagningen@', label: 'mottagningen@', scope: 'grupp', otriagerat: 5 }
```

---

## (b) CHATT-yta — ärende-chatt i kortets flik + team/omnämnanden i lättviktig zon

### Zon 0: femte Dagspuls-räknare

Utöka **`Dagspulsen.vue`** (befintlig) med en femte räknare **`💬 {n} omnämnanden`** (ikon `At` + tal + text). Räknar
**mentions + 1:1 riktade till mig**, aldrig rå olästa → chatten kan aldrig bli en växande röd inkorgs-siffra. Klick =
filtrera "väntar på mig"-vy (`@filter('omnamnanden')`). Props: `puls.omnamnanden:Number`. Reflow: 2×2→wrap till fem.

### Zon 2/3: `DiskussionChip.vue` (NY) på kollapsat kort

- **Syfte:** surfa ärende-chatt *på ärendet*, minimalt. Pull, ingen push utom omnämnande.
- **Props:** `diskussion: { olasta:Number, omnamnandeTillMig:Boolean }`.
- **Visar:** `💬 3` (olästa, neutral ton) eller starkare `💬 @1` (omnämnande till mig, accent-ton). Aldrig röda badge-moln.
- **Plats:** liten chip i `ArendeKort`s kollapsade head, bredvid `FristChip`. Endast `omnamnandeTillMig === true` får höja kortet
  till Zon 2 (utöka `zonOf`: `if (item.diskussion && item.diskussion.omnamnandeTillMig) return 'het'`).

### Zon 2/3: flik "Diskussion" i `ArendeKort` Quick View

`ArendeKort`s expanderade Quick View har idag flikarna *Dokument · Meddelanden · Möten · Bevakningar · Beslut*. Vi:
- **byter "Meddelanden" till en tvådelad yta:** **"Säkra meddelanden"** (befintlig extern kommunikation, kvittensbärande) och
  **"Diskussion"** (ny intern ärende-chatt).
- **Diskussion-fliken** (komponent `ArendeDiskussion.vue`, NY) visar överst en **sekretess-/deltagar-rad** (`SekretessRad.vue`, NY:
  *"3 deltagare = ärenderummets ACL · OSL 26 kap. · intern arbetsmaterial"*), sedan tråden (Hub 25-trådning, `@`-omnämnanden), och
  per meddelande **"Gör detta till en handling"** → öppnar mall-förifylld notering → human-in-the-loop → `CommitGrind` (befintlig) →
  systemkvitto tillbaka i tråden. Knapp **"Lyft till enhetschatt"** (postar avidentifierad referens i team-chatt).

> **Bygg-ärlighet:** ärende-chatten är ett `spreed`-rum vars koppling bärs av ärenderegistrets `talkToken`-pekare (Talk saknar native
> objektbindning till dnr). I demoläge driver `fetchArende(ref).diskussion` fliken; i prod läser den `talkToken` ur registret.
> `ArendeDiskussion` renderar en lättviktig trådvy — den bäddar **inte** in hela Talk-UI:t (undvik inkorgskänsla).

### Zon 5: "Enhetschatt ▸"-ingång + `EnhetschattPanel.vue` (NY)

- **Syfte:** team-/enhetschatt (Circles-team: mottagningsgruppen, utredningsgrupp, enheten) som en **lugn sidoyta**, *aldrig* ett kort i ärendeströmmen.
- **Foten (`MinaArenden.vue` Zon 5):** en diskret länk **"Enhetschatt ▸"** med liten olästa-/omnämnande-indikator → öppnar `EnhetschattPanel`
  (en `NcAppSidebar`-liknande sidopanel eller dedikerad vy).
- **`EnhetschattPanel` props:** `team: Array<{ id, label, olasta, omnamnanden }>`. Visar teamens trådar + knapp "Starta fördelningsmöte"
  (kopplar till (c)). Sekretess-/deltagar-rad överst per tråd. Aldrig i Zon 1–3.

### Demodata-shape: chatt

```js
// per Arende (lazy via fetchArende) — driver DiskussionChip + Diskussion-fliken
diskussion: {
  olasta: 3, omnamnandeTillMig: true,
  deltagare: [{ uid: 'eva', namn: 'Eva (gruppledare)', roll: 'läs/skriv' }, /* = ACL-krets */],
  sekretess: { kod: 'OSL 26 kap.', niva: 'intern_arbetsmaterial' },
  meddelanden: [
    { id: 'm1', fran: 'Anna', text: '@Eva kan du medbedöma inför beslut inleda?', tid: '2026-06-14T09:10', mention: ['eva'] },
  ],
}
// puls (Dagspulsen)
{ fristerBrinner: 2, motenIdag: 1, attSignera: 1, nyaInflode: 4, omnamnanden: 2 }
// team (EnhetschattPanel)
[{ id: 'mottagningen', label: 'Mottagningsgruppen', olasta: 4, omnamnanden: 1 }]
```

---

## (c) TILLDELNINGS-STÖD — tilldelad/otilldelad-status, "från mottagningen"-ursprung, enkelt chef-läge

### Zon 2/3: `TilldelningBand.vue` (NY) — diskret rad på `ArendeKort`

- **Syfte:** göra tilldelnings-status och ursprung synligt utan ett nytt kort.
- **Props:** `tilldelning: { status:('otilldelat'|'tilldelat'), agareUid?, agareNamn?, fran?:('mottagning'|null), tilldeladAv?, tilldeladDatum?, nyFor24h?:Boolean }`.
- **Visar:** vid `tilldelat`: en diskret rad *"Tilldelad mig av Eva 14/6"* (och, de första 24 h, en **"NY — tilldelad dig av Eva"-markör**
  i accent — graft från triage-strömmens nyhetsmarkör, så en nyfördelning inte drunknar bland 21 pågående). Vid `fran:'mottagning'`:
  ett **"Från mottagningen"-ursprungs-chip**. Ingår även i kortets **provenans-band** (utöka `ProvenansChip`-raden):
  *"Inkom via SDK 10/6 · förhandsbedömd av Anna (mottagning) · inledd 13/6 · fördelad till mig av Eva 14/6 · → Treserva: registrerad, dnr X"*.
- **Plats:** en rad i `ArendeKort` mellan provenans och Nästa-åtgärd. Aldrig ett eget kort.

### Roll-läge `fordelning` (gruppledare) — `FordelningsVy.vue` (NY) ovanpå samma kort/zon

- **Syfte:** chefens **lättviktiga fördelningsyta** (inte en ny app) — "vad är inlett men ofördelat, vem har plats, vem ska få vad".
- **Aktivering:** roll-/läge-växel (samma mekanism som `PersonaSwitcher`); `state.arende.lage === 'fordelning'`. Renderas bara för den med
  fördelarroll i korgens team (`IConditionalWidget` = åtkomstgräns).
- **Återanvänder:** samma `zonOf`-selector, samma `ArendeKort`-komponent (ett extra läge), `FristChip`, `ProvenansChip`.
- **Zoner:**
  - **Zon A — "Att fördela"** (`status='otilldelat'`, `steg='utredning'`): `ArendeKort` med primäråtgärd **`FordelaTill.vue`** (NY) —
    en utredar-väljare med **lasten i parentes** (`Sara (8)`), så chefen ser belastningen vid valet. Räknaren kan **aldrig nå noll med kort kvar**
    (compliance-ankare). Visar mottagningssekreterarens förslag + "ofördelad i N dagar · utredningsfrist löper".
  - **Zon B — "Utredarnas belastning"** (`UtredarLast.vue`, NY): stapel per utredare — **tal + frist-färg, ALDRIG innehåll**
    (chefen har inte automatiskt sekretess-åtkomst till varje barn). Props: `utredare: Array<{ namn, aktiva:Number, roda:Number, naraTak:Boolean }>`.
  - **Zon C — "Mottagningens pågående"**: read-only `ArendeKort`-lista (förhandsbedömningar), ingen åtgärd.
- **Fördelningshandlingen (UI→effekt):** "Fördela till Sara" → bekräftelse-dialog (*"Sara blir ansvarig utredare … hon får skrivåtkomst till
  ärenderummet, ärendet visas i hennes Mina ärenden, fristpåminnelser går till henne."*) → `@fordela(arende, utredareUid)` → server orkestrerar
  atomärt (assignee + ACL-omskrivning + Deck-kort + logg, se arkitekturdok §5.3) → kortet lämnar Zon A → notis till Sara. **Omfördelning**
  finns både i `FordelningsVy` och som chef-åtgärd på utredarens kort (ej självbetjäning).

### Store/events

`MinaArenden.vue` får `lage`-state (`'utredning'|'fordelning'`) och en `FordelningsVy`-gren (`v-if="lage === 'fordelning'"`) parallellt med
den vanliga zon-listan — samma mönster som persona-grenen i `Start.vue`. Nya store-actions (demo-stubbar): `tilldela(ref, uid)`, `omfordela(ref, uid)`.

### Demodata-shape: tilldelning + fördelning

```js
// per Arende — TilldelningBand + provenans-band
tilldelning: {
  status: 'tilldelat', agareUid: 'anna', agareNamn: 'Anna',
  fran: 'mottagning', tilldeladAv: 'Eva', tilldeladDatum: '2026-06-14', nyFor24h: true,
},
provenansKedja: [  // utökar ProvenansChip-raden till ett band
  'Inkom via SDK 10/6', 'förhandsbedömd av Anna (mottagning)', 'inledd 13/6',
  'fördelad till mig av Eva 14/6', '→ Treserva: registrerad, dnr 2026-IFO-0142',
],

// fetchFordelningSummary() → FordelningsVy
{
  attFordela: [ /* Arende med status:'otilldelat', forslag:{utfall:'inleda', motiv:'LVU-historik'}, ofordeladDagar:1 */ ],
  utredare: [
    { namn: 'Sara', aktiva: 8, roda: 0, naraTak: false },
    { namn: 'Mia', aktiva: 19, roda: 1, naraTak: true },
  ],
  mottagningPagaende: 5,
}
```

---

## (d) Synlig ÄRENDE-TAGG/koppling på objekt — "Kopplad till Barn 2026-0142"

### Komponent: `KopplingBadge.vue` (NY) — den synliga röda tråden

- **Syfte:** göra ärende-identiteten **synlig på objektet** (meddelande/fil/uppgift visar att det hör till ett ärende) — utan att
  någonsin visa rå `hubsCaseId` (GDPR; UUID är join-nyckeln, pseudonym är vad människan ser).
- **Props:** `koppling: { status:('kopplad'|'foreslagen'|'ej'), barnRef?, dnr?, triageRef?, konfidens?:Number }`.
- **Visar (tre tillstånd, alltid ikon + text + färg):**
  - `kopplad` → *"🔗 Kopplad till Barn 2026-0142"* (success-ton; klick → `@open-arende`).
  - `foreslagen` → *"🔗? Liknar Barn 2026-0142 — bekräfta?"* (warning-ton; två knappar: bekräfta/avvisa; visas bara om handläggaren har
    åtkomst till målärendet, annars *"Möjlig koppling — eskalera till gruppledare"*).
  - `ej` → *"Ej ärendekopplat"* (neutral; leder till åtgärderna i (e)).
- **Var den syns:** på `InflodeRad` (1b/1c), på objekt i `ArendeKort`s flikar (Dokument: "Kopplad till …" på en fil; Meddelanden:
  på en meddelanderad; Bevakningar: på ett Deck-kort). Samma grammatik överallt (WCAG 3.2.4 konsekvens). Mappar tekniskt till
  systemtaggen `case:{hubsCaseId}` (fil/meddelande) resp. register-pekaren (Deck/Talk/kalender), men det är **osynligt** för användaren.
- **Hur den ser ut:** liten pill i samma familj som `FristChip`/`ProvenansChip` (delar `.hs-chip`-grammatik), färgkodad ram + länk-ikon.

> **Demo:** drivs av `koppling`-fältet i `InflodeRad` och ett `koppling`-fält per objekt i `fetchArende`-flikarna. Konfidens (`0–1`)
> styr `kopplad` vs `foreslagen` (≥0.9 → kopplad i demo).

---

## (e) "EJ ÄRENDEKOPPLAT"-hink med åtgärder

### Komponent: `EjKoppladSektion.vue` + `EjKoppladRad.vue` (NY) — Zon 1c

- **Syfte:** en uttrycklig mellanstation för löst inflöde (fall c/d + otriagerat a/b) så att **ingen rad försvinner utan att kopplas,
  registreras eller gallras med dokumenterat stöd**.
- **Sektion-props:** `items: Array<InflodeRad>` (med `arendekoppling:'ej_kopplat'`), `aktivKorg/aktivTyp`.
- **Sektion visar:** rubrik **"Ej ärendekopplat"** + **röd-när-gammal räknare** (`NcCounterBubble`, röd när rader >1 arbetsdag —
  speglar registreringsplikten): *"7 — 2 äldre än 3 dagar"*. Aggregerar över **alla behöriga korgar**. Compliance-KPI.
- **`EjKoppladRad` (bygger på `InflodeRad`):** kanalikon · korg · avsändare+LOA (eller "anonym/ej verifierad" som *legitimt* tillstånd) ·
  inkom-tid · **klassnings-/typ-chip** · `KopplingBadge` · **föreslagen default-åtgärd som primärknapp** + "Mer"-meny med övriga.
- **De sex åtgärderna (events):**
  | Åtgärd | Fall | Event | UI-effekt |
  |---|---|---|---|
  | **Koppla till befintligt ärende** [sök dnr/barn] | (a) | `@koppla(rad, ref)` | raden glider visuellt in i ärendekortet (`KopplingBadge`→kopplad) |
  | **Skapa nytt ärende** | (b) | `@skapa(rad)` | förifyllt aktualiseringsformulär → fristklocka startar (inkom-datum) |
  | **Besvara utan ärende** | (d) | `@besvara(rad)` | säkert svar inline → raden stängs, chip "Besvarad — hålls ordnat" |
  | **Vidarebefordra / fel mottagare** | (c) | `@vidarebefordra(rad)` | mottagar-/funktionsadressval → säker överlämning, loggad |
  | **Gallra utan ärende** | (c) | `@gallra(rad)` | **öppnar `GallringsGrind`** (aldrig naket klick) |
  | **Registrera utan ärende** | (c)/(d) | `@registrera(rad)` | skicka till diarium/e-arkiv; visas i stället för Gallra när grund saknas |

### Komponent: `GallringsGrind.vue` (NY) — den juridiska grinden

- **Syfte:** operationalisera att en allmän handling inte får raderas utan stöd (arkivlagen 1990:782; OSL 5:1). "Gallra" är aldrig ett klick som raderar.
- **Props:** `rad: InflodeRad`, `handlingstyper: Array<{ id, label, gallringsbeslut, ringa:Boolean }>` (ur kommunens DHP).
- **Flöde:** välj **handlingstyp** → systemet visar **gallrings-/bevarandebeslutet** för typen → handläggaren bekräftar. Saknas en
  gallringsgrund (sekretess/mer än ringa betydelse) visas i stället **"Registrera utan ärende"** som tvingande väg. Bekräftelse loggas (`activity`).
- **Hur den ser ut:** `NcDialog` med en select + en konsekvenstext + en disabled/aktiverad "Gallra"-knapp beroende på vald typ.

### Auto-förslag

Default-åtgärden Hubs föreslår sätts av klassnings-chip + auto-koppling: "komplettering, hög träff mot dnr X" → default **Koppla**;
"autosvar/reklam" → default **Gallra** (men grinden kvarstår); "allmän fråga" → default **Besvara**. Förslaget visas med synligt "varför"
("nämner barnets förnamn + skolans namn → trolig komplettering"). Ett klick bekräftar, ett klick avvisar. **Bilagan speglas vid bekräftelse, inte vid förslag.**

- **Empty:** *"Ej ärendekopplat: 0 — allt inflöde triagerat."* (samma trygghetssignal som tom ärendekö.)

### Demodata-shape: ej-kopplat (utökar `InflodeRad`)

```js
{
  id: 'ej-3', kind: 'inflode', arendekoppling: 'ej_kopplat',
  korg: { addr: 'mottagningen@', label: 'mottagningen@', scope: 'grupp' },
  channel: { channel: 'fax', channelLabel: 'Fax', messageType: 'fax' },
  messageType: 'fax',
  klassning: { typ: 'oklart', forslag: 'gallra', varfor: 'autosvar-mönster' },
  koppling: { status: 'ej' },
  avsandare: 'Okänd', identitet: { badge: 'Ej verifierad', verifierad: false },
  titel: 'Inkommande fax – oklassat',
  inkomDatum: '2026-06-11T13:02:00',           // >3 dagar → röd i räknaren
  alder: { dagar: 3, overSla: true },
  foreslagenAtgard: 'gallra',                   // styr primärknappen
}
```

---

## Sammanställning — komponenter att bygga (PascalCase Vue)

| Komponent | Typ | Zon/plats | Kärn-props | Kärn-events |
|---|---|---|---|---|
| `KorgValjare.vue` | NY | Zon 1 topp | `korgar, aktivKorg, aktivTyp` | `valj-korg, valj-typ` |
| `AttHanteraSektion.vue` | NY | Zon 1b | `items, gruppering, aktivKorg, aktivTyp` | `spara-i-rum, skapa-bevakning, besvara, open-arende, satt-gruppering` |
| `EjKoppladSektion.vue` | NY | Zon 1c | `items, aktivKorg, aktivTyp` | `koppla, skapa, besvara, vidarebefordra, gallra, registrera` |
| `EjKoppladRad.vue` | NY | Zon 1c | `rad, foreslagenAtgard` | (rebubblar de sex) |
| `InflodeRad.vue` | NY (delad) | Zon 1b/1c | `rad, actions` | typ-styrda |
| `GallringsGrind.vue` | NY (modal) | Zon 1c | `rad, handlingstyper` | `gallra, registrera, close` |
| `KopplingBadge.vue` | NY (atom) | 1b/1c + kort-flikar | `koppling` | `open-arende, bekrafta, avvisa` |
| `DiskussionChip.vue` | NY (atom) | Zon 2/3 kort | `diskussion` | `open-diskussion` |
| `ArendeDiskussion.vue` | NY | `ArendeKort` flik | `arende, diskussion` | `gor-till-handling, lyft-enhetschatt` |
| `SekretessRad.vue` | NY (atom) | chatt-ytor | `sekretess, deltagare` | — |
| `EnhetschattPanel.vue` | NY (sidopanel) | Zon 5 ingång | `team` | `oppna-tradar, starta-fordelningsmote` |
| `TilldelningBand.vue` | NY | Zon 2/3 kort | `tilldelning` | `omfordela-begaran` |
| `FordelningsVy.vue` | NY (läge) | roll `fordelning` | `summary` | `fordela, omfordela` |
| `FordelaTill.vue` | NY | FordelningsVy Zon A | `arende, utredare` | `fordela` |
| `UtredarLast.vue` | NY | FordelningsVy Zon B | `utredare` | `valj-utredare` |

**Återanvänds/utökas (inte dupliceras):** `MinaArenden.vue` (zonOf + tre band + lage-gren), `Dagspulsen.vue` (femte räknare),
`AttTaEmotSektion.vue` (oförändrad, 1a), `ArendeKort.vue` (DiskussionChip + Diskussion-flik + TilldelningBand + KopplingBadge i flikar),
`ArendeZon.vue`, `ProcessStepper.vue`, `NastaAtgardKnapp.vue`, `FristChip`/`ProvenansChip`/`LoaChip`, `CommitGrind.vue` (driver
"Gör detta till en handling"), `channelMeta`, `deepLinks`, `sections.js`, `.hs-card`/tokens, `PersonaSwitcher` (mönster för `lage`-växeln).

## Datakällor (server-side aggregat — ingen fan-out)

- `fetchInflodeSummary()` → `{ korgar:[Korg], inflode:[InflodeRad] }` (behörighetsfiltrerade korgar; klassning+match server-side).
  Utökar/parallell till befintliga `fetchArendeSummary`.
- `fetchArende(ref)` → utökas med `diskussion`, `tilldelning`, `provenansKedja` och `koppling` per objekt i flikarna.
- `fetchFordelningSummary()` → `{ attFordela, utredare, mottagningPagaende }` (chefens vy; exponerar **tal + frist-färg, aldrig innehåll**).
- Store-actions (demo-stubbar i `services/demo/socialsekreterare.js` + riktiga OCS-routes i sdkmc): `koppla`, `skapa`, `besvara`,
  `vidarebefordra`, `gallra`, `registrera`, `tilldela`, `omfordela`, `gorTillHandling` (→ `CommitGrind`).
- Demo: utöka `demo/socialsekreterare.js` med 4–6 korgar och ~13 inflöde-rader över alla typer (inkl. 1 fax ej-verifierad, 1 skräp/fel,
  3 hör-till-ärende, 2 ej-kopplat >3 dgr), `diskussion`-block på 2–3 ärenden (ett med `omnamnandeTillMig:true`), `tilldelning`-band
  (ett `nyFor24h`), och en `fordelningSummary` med 3 att-fördela + 6 utredare.

## WCAG & varumärke (oförändrade krav)

Target Size ≥24×24 px på alla chips/knappar; filtrering/sortering via knapp/tangentbord (aldrig drag, 2.5.7); `aria-live="polite"` på
de tre inflöde-banden; `aria-pressed` på korg-/typ-piller; färg aldrig enda informationsbärare (ikon + text + tal på `KopplingBadge`,
`DiskussionChip`, korg-räknare); reflow i porträtt/400 % (banden + KorgValjare wrappar). UI-text aldrig "Nextcloud"/"Talk"/"Circles" —
endast *korg, ärendechatt, enhetschatt, team, ärende-koppling, fördela*.

---

*Grundas i `analysis-output/extended/ark-1…ark-5`, `HUBS-ARKITEKTUR-SOCIALTJANST.md`, `UX-REDESIGN-SOCIALSEKRETERARE.md`,
`SOCIALSEKRETERARE-WALKTHROUGH.md`, samt befintliga `hubs_start/src/components/socialsekreterare/*`. Varumärkesregel: aldrig
"Nextcloud"/"Talk"/"Circles" i UI-text.*
