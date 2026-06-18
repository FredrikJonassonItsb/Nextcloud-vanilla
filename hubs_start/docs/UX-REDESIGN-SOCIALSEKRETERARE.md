<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# UX-REDESIGN — Socialsekreterarens Hubs Start-vy ("Mina ärenden")

> **Status:** byggbar designspec (synthesis av UX-tävlingen, 2026-06-14)
> **Vinnare:** `ux-concept-arende` (ärende-centrisk), enhälligt hos alla tre domare
> **Grafter in:** `mindag` (Dagspulsen + `zonOf`-arkitektur), `processboard` (CommitGrind + pliktmarkör-spärr), `guidad` (verbingång + undervisande tomma tillstånd + onboarding)
> **Stack:** Vue 2.7 + `@nextcloud/vue` v8 · standalone-appen `hubs_start` (namespace `HubsStart`)
> **Persona:** `socialsekreterare` (barn & familj, SoL 2025:400, BBIC)
> **Grundas i:** `CONTRACTS.md`, `DEMO-WIDGETS-CONTRACT.md`, `SOCIALSEKRETERARE-WALKTHROUGH.md` (51 steg / Akt 1–5), `GAP-ANALYSIS.md`, `SIGNING-INERA.md`, `WIDGET-APP-MAP.md`, `PERSONA-DASHBOARD-SPEC.md`
>
> **Antagande (per uppdrag):** alla blockerare lösta — Treserva-commit via **Frends** (iPaaS, verifierad återkoppling), **Inera Underskriftstjänst** (AES via BankID/Freja/SITHS), laglig + lokal **transkribering**, **Retention-paus**. Vyn designas som ett **skarpt verktyg** — varje åtgärd är riktig.
>
> **Varumärkesregel (enforced):** UI-text säger aldrig "Nextcloud" eller "Talk". Vi säger *Hubs, ärenderum, säkert möte, säkert meddelande, e-underskrift, facksystemet/Treserva*. Interna app-id nämns bara i byggnoteringar.

---

## Vald riktning & motiv

**Riktning: ärende-centrisk — "Mina ärenden".** Vyn är en enspaltig, prioritetsordnad lista av socialsekreterarens aktiva ärenden. Varje ärende är **ett kort** (`ArendeKort`) som bär en horisontell **process-stepper** (Förhandsbedömning → Utredning → Beslut → Uppföljning → Avslutat), **EN lysande "Nästa åtgärd"-knapp**, en **frist-chip i färg** och en rad inline-snabbåtgärder. Otrierat inflöde (nya orosanmälningar och inkommande som ännu inte hör till ett ärende) hålls i en **separat triage-ström överst** ("Att ta emot"), så att "nya saker som kräver ett ärendebeslut" aldrig blandas med "ärenden jag redan driver".

**Varför den vann (och varför den är lättast att förstå + stödjer arbetsgången):**

1. **Lättbegriplig (designmål 1).** Mentalmodellen matchar exakt hur en socialsekreterare redan tänker — *"mina barn/familjer och var de är i processen"* — i stället för 13 parallella verktygswidgetar. En rad per barn, en stepper som visar vägen, en knapp som visar nästa steg. **Steppern lär ut hela ärendelivscykeln utan manual.**
2. **Stödjer arbetsgången (designmål 2 — högst workflowFit av alla).** Walkthroughens 51 steg ÄR i grunden *ett ärendes resa genom 5 akter*, och steppern är exakt de 5 akterna. Stepperns fas = aktens fas; "Nästa åtgärd"-knappen = aktens nästa steg, härledd ur en **state machine** (steg + tillstånd → åtgärd). Alla 51 steg kollapsar till "läs korten, tryck på knappen som lyser".
3. **Frist-säkerhet (designmål 4).** Varje ärende visar sin frist ALLTID via `FristChip` på kortet; 14-dgr-klockan tickar från inkom-datum redan i triage-zonen; deterministisk frist-först-sortering lyfter heta ärenden till en pinnad zon de inte kan scrollas bort ur; frister speglas ur Treserva via Frends så Hubs och facksystemet aldrig divergerar.

**Vad vi ympar in för att täppa till ärende-konceptets tre svagheter** (låg kognitiv last, hård fristgaranti, nybörjar-onboarding) **utan att röra dess överlägsna per-ärende-workflow:**

- **FRÅN `mindag` → Dagspulsen + arkitektur.** Ärende-konceptets Zon 0 var löpande prosa ("22 ärenden · 2 frister inom 3 dagar"). Vi ersätter den med **`Dagspulsen`** — fyra ikoniska, klickbara räknare (⏰ frister · 📹 möten · ✍ signera · 📥 nya) som ger 3-sekunders-temperaturen och blir filter. Vi adopterar också mindags rena **`zonOf(item)`-selector** över EN summary-payload (höjer byggbarheten) och dess "tom kö = compliance-kvitto"-tomma tillstånd.
- **FRÅN `processboard` → CommitGrind + pliktmarkör.** Ärende-konceptets `ProvenansChip` "flippade" vid Frends-callback men saknade ett *synligt, verifierat* commit-ögonblick OCH en *hård spärr*. Vi ympar in **`CommitGrind`** (skickat → bekräftat API-svar → registrerat) bakom varje "För till Treserva", och den **röda pliktmarkören** som strukturell spärr: ett kort med okvitterad skyddsbedömning (GAP-001) eller förfallen frist kan **inte tyst avancera sin stepper** förrän plikten committas. Det binder Retention-start till verifierad commit (GAP-007), inte chip-flip.
- **FRÅN `guidad` → verbingång + undervisning.** Ärende-konceptet hade KommandoPalett (Ctrl+K) för experter men inget visuellt för dag-1-nybörjaren. Vi lägger **`VadVillDuGora`** — fem stora verbknappar — som en avstängbar ingångsrad ovanför listan ("kartan över yrket" för den nye, försvinner för den vane), plus **undervisande tomma tillstånd** och en **dismissbar dag-1 onboarding-tour** som highlightar steppern.

**Nettot:** ärendekortet + stepper + Nästa-åtgärd som orörlig kärna, med mindags 4-tals-puls (lägre last), processboards CommitGrind + pliktspärr (hård fristgaranti) och guidads verbkarta + undervisande tomtillstånd (nybörjar-learnability).

---

## Vyns layout (zoner uppifrån och ned)

Enspaltig, prioritetsordnad kolumn. Bento-griden (`Start.vue`s `__grid` 3fr/2fr) är **medvetet bortvald för denna persona** — linjär läsordning sänker kognitiv last och reflowar rent i porträtt/400 % (hembesök). Zonerna fylls av en ren `zonOf(item)`-selector över EN summary-payload.

```
┌──────────────────────────────────────────────────────────────────────────────┐
│  ZON 0 — SIDHUVUD + DAGSPULSEN  (sticky, alltid synlig)                          │
│  God morgon, Anna · måndag 14 juni        [Säker kanal ●]  [Ctrl/⌘K]  [Hjälp ?]  │
│  ⏰ 2 frister brinner   📹 1 möte 10:00   ✍ 1 att signera   📥 4 nya  ← filter   │
├──────────────────────────────────────────────────────────────────────────────┤
│  ZON V — "VAD VILL DU GÖRA?"  (avstängbar verbingång — på som default dag 1–14)  │
│  [Ta emot anmälan] [Arbeta med utredning] [Boka möte] [Signera beslut] [Följ upp]│
├──────────────────────────────────────────────────────────────────────────────┤
│  ZON 1 — ATT TA EMOT · 4   (otrierat inflöde — smala triage-rader)               │
│  ▸ [SDK] Orosanmälan – Barn 2026-0142 · Skolkurator SITHS·LOA3 · inkom 07:58     │
│        ⏰ 13 dgr  → Treserva: ej registrerad   [Ta emot & starta]  [Koppla]      │
│  … (tom → "Inget otriagerat — allt inkommande är omhändertaget")                 │
├──────────────────────────────────────────────────────────────────────────────┤
│  ZON 2 — KRÄVER ÅTGÄRD NU · 3   (heta ärendekort, pinnade, max ~3–4)             │
│  ┌────────────────────────────────────────────────────────────────────────┐    │
│  │ Barn 2026-0142 · dnr 2026-IFO-0142            [OSL 26 kap.] [LOA3]       │    │
│  │ ●──●──○──○──○   Förhandsb. · Utredn · Beslut · Uppföljn · Avslutat       │    │
│  │ ⏰ FÖRHANDSBEDÖMNING · 2 dgr kvar (gul) · förfaller 16/6                  │    │
│  │ → Treserva: Registrerad · Hubs-rum gallras 2026-09                        │    │
│  │  ┃ NÄSTA ÅTGÄRD:  Fatta beslut: inleda / inte inleda  ┃   [▾ gör annat]   │    │
│  │ [Öppna ärenderum] [Skicka säkert] [Boka möte] [Signera] [Bevakning]      │    │
│  └────────────────────────────────────────────────────────────────────────┘    │
├──────────────────────────────────────────────────────────────────────────────┤
│  ZON 3 — MINA ÄRENDEN  (alla aktiva kort; segmenterad filter på processteg)      │
│  [Alla] [Förhandsbed.] [Utredning] [Beslut] [Uppföljning]   sort: frist ▾        │
│  …kollapsade ärendekort, expanderar till Quick View (flikar) utan sidbyte…       │
├──────────────────────────────────────────────────────────────────────────────┤
│  ZON 4 — MINA MÖTEN IDAG  (smal tidslinje, ärendekopplad)                        │
│  10:00 SIP – Barn 2026-0412 · börjar om 38 min · 2 i lobby  [Anslut säkert]      │
├──────────────────────────────────────────────────────────────────────────────┤
│  ZON 5 — FOTEN  "Klart idag: 7"  ·  [Kunskapsbank & mallar]  ·  Senaste filer    │
└──────────────────────────────────────────────────────────────────────────────┘
```

**Zon-för-zon — vad, i vilken ordning, varför:**

- **Zon 0 — Sidhuvud + Dagspulsen (sticky, kontext + 3-sek-orientering).** Namn + datum + diskret datasuveränitets-prick + Ctrl/⌘K + fast Hjälp-ikon. Under: **Dagspulsen** — fyra räknare som komprimerar hela dagen och fungerar som **klickbara filter** (klick på ⏰ filtrerar listan till frister som brinner). *Varför först:* svarar "brinner något?" innan man scrollar; den sticky räknaren `frister brinner` kan aldrig nå noll med röda kort kvar (compliance-ankare). WCAG 2.4.11: sticky-höjden får inte dölja fokuserat kort.
- **Zon V — "Vad vill du göra?" (avstängbar verbingång).** Fem stora verbknappar = hela yrket i fem verb. **På som default de första 14 dagarna** (per-user-preferens `verbEntryDismissed`), sedan kollapsad till en tunn "Vad vill du göra? ▸"-rad. *Varför här:* kartan över arbetet för den nye; för den vane är samma fem verb redan i Ctrl/⌘K.
- **Zon 1 — Att ta emot (triage-strömmen).** Smala `AttTaEmotRad`. *Varför separerat:* triage ("ska detta bli ett ärende, och vems?") är en annan kognitiv uppgift än "driva mina ärenden" — Superhuman/Linear-split-paradigmet. 14-dgr-chipen **tickar redan**, bunden till inkom-datum.
- **Zon 2 — Kräver åtgärd nu (heta kort, pinnade).** De ärenden vars nästa åtgärd är förfallen/brådskande eller väntar på just Anna, som **fulla `ArendeKort`**. Deterministisk sortering: **frist (röd→gul) → väntar-på-mig → sekretessnivå → oläst**. Max ~3–4 kort (progressive disclosure i makro). *Vyns hjärta:* "vad ska jag göra härnäst?".
- **Zon 3 — Mina ärenden (alla aktiva kort).** Samma kortkomponent, **kollapsat läge**, filtrerbar via segmenterad kontroll på processteg. *Varför:* "beta av en batch i taget" (alla beslut att signera; alla utredningar att skriva). Klick expanderar Quick View (flikar) utan sidbyte.
- **Zon 4 — Mina möten idag (smal tidslinje).** Dagens säkra möten, ärendekopplade (dnr-chip), en-klicks-anslut + lobbystatus. *Varför sist men synlig:* tidsbundet men inte ärendedrivet — en lugn remsa, inte ett av tretton jämbördiga kort.
- **Zon 5 — Foten.** "Klart idag"-räknare (framstegskänsla), fast genväg till Kunskapsbank & mallar (WCAG 3.2.6 Consistent Help), diskret "Senaste säkra filer".

**Vad som vikts in:** de tidigare ~13 widgetarna mappas så här — `attHantera`/`orosanmalningar`/`funktionsbrevlador` → **Zon 1**; `bevakningar`/`minaUppgifter`/`attSignera`/`arenderum`/`kvittenser`/`senasteFiler`/`motesanteckningar` → **inbäddade i ärendekortets flikar**; `dagensMoten`/`bokningsbaraTider` → **Zon 4**; `kunskapsbank` → **Zon 5 (fast plats)**; `fristStrip` → **Dagspulsen**; `dataSuveranitet` → **Zon 0-prick**.

---

## Komponenter att bygga

Konvention (ärvd ur `CONTRACTS.md`): varje komponent är `export default { name, components, props, data, computed, methods }`; alla synliga strängar via `t('hubs_start', …)`; `.hs-card`-skal + tokens i `css/variables.scss`; klickytor ≥24×24 px; färg aldrig enda informationsbärare (ikon + text + färg); inga drag-only-interaktioner (WCAG 2.5.7); per-komponent-import av Nc*-komponenter (`import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'`). En ny container-vy **`MinaArenden.vue`** ersätter `Start.vue`-griden för denna persona (se Migrationsnot).

Datat kommer ur **en** utökad summary-payload (`fetchArendeSummary`) + **en** per-ärende-aggregat (`fetchArende(dnr)`) — inget klient-fan-out (CONTRACTS hård regel 6).

---

### 1. `MinaArenden.vue` — container-vyn (ersätter griden för socialsekreteraren)

- **Syfte:** rendera de sju zonerna i en kolumn; äga summary-state och `zonOf`-fördelningen; ta emot alla kort/rad-events och routa dem till tjänster.
- **Props:** inga (läser `store.state`).
- **Visar:** `MinDagHeader` (Zon 0) → `VadVillDuGora` (Zon V) → `AttTaEmotSektion` (Zon 1) → `ArendeZon` "Kräver åtgärd nu" (Zon 2, `pinned`) → `ArendeZon` "Mina ärenden" (Zon 3, filtrerbar) → `MotesRemsa` (Zon 4) → `Foten` (Zon 5). Modaler: `CommitGrind`, `MeetingWizard` (befintlig), `CommandPalette` (befintlig), `OnboardingTour`.
- **Åtgärder/metoder:** `zonOf(arende)` (ren selector → 'taEmot' | 'het' | 'aktiv'); `onNastaAtgard(arende)` (slår upp i state machine → öppnar rätt flik / dialog); `onCommit(payload)` → öppnar `CommitGrind`; `onTriage(rad, mode)` → orkestrerings-endpoint; `onFilterPuls(key)` (Dagspulse-klick sätter aktivt filter).
- **Hur den ser ut:** identisk yttre ram som dagens `hubs-start` (max-width, centrerad, 16/24 px padding) men **en kolumn** (`display:flex; flex-direction:column; gap:16px`) i stället för `__grid`.

```js
// zonOf — ren funktion, mindags arkitektur. Ingen fan-out.
function zonOf(a) {
  if (a.steg === 'inflode') return 'taEmot'           // ännu ej ett ärende
  if (a.frist && (a.frist.tone === 'error' || a.frist.tone === 'warning')) return 'het'
  if (a.nastaAtgard && a.nastaAtgard.vantarPaMig) return 'het'
  return 'aktiv'
}
```

---

### 2. `MinDagHeader.vue` — Zon 0 sidhuvud (sticky)

- **Syfte:** identitet, datum, datasuveränitet, global navigering. Värd för Dagspulsen.
- **Props:** `loa: String`, `profile: String`, `puls: Object` (Dagspulse-tal).
- **Visar:** "God morgon, {namn} · {veckodag} {datum}" (lokaliserat); `LoaChip` (befintlig komponent); statisk datasuveränitets-prick `t('hubs_start','Säker kanal · all data i er driftmiljö')`; Ctrl/⌘K-knapp; fast Hjälp-"?". Innehåller `Dagspulsen` som barn.
- **Åtgärder:** `@open-palette()`, `@upgrade-loa()` (bubblad från LoaChip), `@open-help()`.
- **Hur den ser ut:** tunn sticky rad (`position:sticky; top:0; z-index:10; background:var(--color-main-background)`). En rad text + chips till höger; Dagspulsen direkt under.

---

### 3. `Dagspulsen.vue` — fyra räknare (graft från `mindag`)

- **Syfte:** 3-sekunders dagsöverblick + klickbara filter. Ersätter `fristStrip` + Zon 0-prosan.
- **Props:** `puls: { fristerBrinner:Number, motenIdag:Number, attSignera:Number, nyaInflode:Number }`, `aktivtFilter: String|null`.
- **Visar:** fyra `NcCounterBubble`-bärande knappar, var och en ikon + tal + text: **⏰ {n} frister brinner** (`ClockAlert`), **📹 {n} möten idag** (`Video`), **✍ {n} att signera** (`FileSign`), **📥 {n} nya** (`InboxArrowDown`). Aldrig bara färg. `frister brinner` röd när >0.
- **Åtgärder:** `@filter(key)` — klick togglar ett filter på listan nedan (key ∈ `'frist'|'mote'|'signera'|'inflode'`); aktiv räknare markeras (`aria-pressed`).
- **Hur den ser ut:** horisontell strip av fyra "piller" (`.hs-target`, ≥44px), ikon vänster, tal stort, etikett liten under; aktivt filter = ifylld accent + ram. Reflow: wrap till 2×2 i smal vy.

---

### 4. `VadVillDuGora.vue` — verbingång (graft från `guidad`)

- **Syfte:** den synliga arbetskartan för nya + Cmd/K-skalning för vana. Avstängbar.
- **Props:** `dismissed: Boolean`.
- **Visar (utfälld):** fem stora verbknappar med ikon + undertext: **Ta emot anmälan** ("starta förhandsbedömning"), **Arbeta med utredning**, **Boka möte**, **Signera beslut**, **Följ upp**. **Visar (kollapsad):** en tunn rad "Vad vill du göra? ▸" + liten "stäng tips"-länk.
- **Åtgärder:** `@verb(id)` (id ∈ `'taEmot'|'utredning'|'mote'|'signera'|'foljUpp'`) → container öppnar rätt flöde/filter; `@dismiss()` → sätter `verbEntryDismissed` i preferenser (savePreferences).
- **Hur den ser ut:** fem jämnbreda kort i en rad (wrap i smal vy), ikon överst, verb fet, undertext grå. Den vane ser bara den kollapsade raden.

---

### 5. `AttTaEmotSektion.vue` + `AttTaEmotRad.vue` — Zon 1 triage

- **Syfte:** snabb, säker triage av otrierat inflöde utan att öppna ett tungt ärende.
- **Sektion-props:** `items: Array<TriageRad>`, `aktivtFilter: String|null`.
- **Rad-props:** `rad: TriageRad`.
- **Rad visar:** kanalikon via `channelMeta(rad.channel.channel)` · avsändare + `LoaChip`/identitets-badge (inkl. legitimt "Ej verifierad/anonym") · inkom-tid · **14-dgr `FristChip` som redan tickar** (bunden till `rad.inkomDatum`) · `ProvenansChip` ("→ Treserva — ej registrerad"). Korttext = ärendereferens, aldrig klartextcitat (GDPR).
- **Åtgärder:** **"Ta emot & starta förhandsbedömning"** (`@triage(rad,'start')`) och **"Koppla till befintligt ärende"** (`@triage(rad,'koppla')`) + "Visa" (`@open(rad)`).
- **Sektion-empty:** undervisande (graft guidad) `NcEmptyContent`: *"Här landar nya orosanmälningar. Du har inga otriagerade just nu. När en kommer ser du en 14-dagars nedräkning direkt."* (compliance-värde, inte bara tomt).
- **Hur den ser ut:** rubrik "Att ta emot" + `NcCounterBubble`; smala `NcListItem`-rader (en triagerad, inte full presentation); två primärknappar per rad. `aria-live="polite"` runt listan för inkommande.

---

### 6. `ArendeZon.vue` — Zon 2 & 3 (samma komponent, två lägen)

- **Syfte:** rendera en grupp ärendekort, antingen pinnade heta (Zon 2) eller hela filtrerbara listan (Zon 3).
- **Props:** `arenden: Array<Arende>`, `pinned: Boolean`, `title: String`, `filterSteg: String|null` (bara Zon 3), `keyboardMode: Boolean`.
- **Visar:** rubrik + räknare; i Zon 3 dessutom en **segmenterad kontroll** `[Alla][Förhandsbed.][Utredning][Beslut][Uppföljning]` + sorteringsväljare (frist ▾). Renderar `ArendeKort` per ärende (Zon 2 = expanderat-redo/öppet kortläge med full Nästa-åtgärd; Zon 3 = kollapsat läge).
- **Åtgärder (rebubblade):** `@nasta-atgard(arende)`, `@open-rum(arende)`, `@commit(arende, payload)`, `@bevakning(arende)`, `@expand(arende)`, `@filter-steg(id)`.
- **Empty (Zon 2):** *"Inga heta ärenden just nu. Inga förfallna frister — inget barn mellan stolarna."* **Empty (Zon 3 per filter):** *"Inga ärenden i Beslut just nu."*
- **Hur den ser ut:** Zon 2 = upp till 4 fulla kort med tydlig ram/skugga (pinnade); Zon 3 = tätare lista av kollapsade kort. WCAG 2.5.7: filtrering/sortering via knapp + tangentbord, aldrig drag.

---

### 7. `ArendeKort.vue` — **vyns kärnkomponent**

- **Syfte:** representera *ett* ärende i alla processteg; ersätter merparten av de gamla widgetarna genom att samla det relevanta per ärende.
- **Props:** `arende: Arende` (full shape nedan), `expanded: Boolean` (default false i Zon 3, true i Zon 2), `keyboardMode: Boolean`.
- **Visar (kollapsat):** barn-/ärendetitel (pseudonym + dnr-token, t.ex. *"Barn 2026-0142 · dnr 2026-IFO-0142"*); `ProcessStepper`; `FristChip`; `ProvenansChip`; sekretess-/LOA-badge; rad **"Nästa åtgärd: …"** + `NastaAtgardKnapp`; **pliktmarkör** (röd) om `arende.plikt` är okvitterad.
- **Visar (expanderat, Quick View — ingen sidbyte, via `NcCollapsible`):** ärenderum-innehåll i **flikar** — *Dokument · Meddelanden · Möten · Bevakningar · Beslut* — med inbäddade snabbåtgärder, hela `FristPanel`, ACL-/delningsstatus, `kvittenser`-tidslinje.
- **Åtgärder (inline):** **Öppna ärenderum** · **Skicka säkert meddelande** · **Boka säkert möte** · **Skicka för underskrift / Signera** · **För till Treserva** (→ `CommitGrind`) · **Skapa bevakning**. Vilka som är *primära* avgörs av processteget (se "Hur arbetsgången stöds"). Emit per åtgärd; `@expand` togglar Quick View.
- **Teknik:** `NcCard`-baserad; data per kort hämtas lazy via `fetchArende(dnr)` när kortet expanderas (annars ur summary-payloaden). Pliktmarkör + fas-spärr enforced server-side; klienten speglar.
- **Hur den ser ut:** se ASCII ovan. Titel + sekretess/LOA högst; stepper-rad; frist-rad (färgad chip + datum); provenans-rad; **en bred, hög primärknapp** (Nästa åtgärd) med en `[▾ gör annat]`-meny för övriga lagliga åtgärder; en rad sekundära ikon-knappar längst ned. Pliktmarkör = röd pill ovanför stepper ("⚠ Skyddsbedömning krävs idag — måste committas").

---

### 8. `ProcessStepper.vue` — processindikatorn

- **Syfte:** göra "var är ärendet?" begripligt på en blick och leda blicken till nästa steg; lär ut ärendelivscykeln utan manual.
- **Props:** `steg: String` (`'forhandsbedomning'|'utredning'|'beslut'|'uppfoljning'|'avslutat'`), `substeg: String|null`, `plikt: Object|null`.
- **Visar:** fem segment **Förhandsbedömning → Utredning → Beslut → Uppföljning → Avslutat**; avklarade = ifylld bock, aktuellt = markerat + etikett (`aria-current="step"`), kommande = grått. **Varje steg har ikon + text** (WCAG 1.4.1). Hover/expansion visar substeg (under Utredning: "Inhämta uppgifter · Samredigera · Kommunicera · Färdigställ").
- **Åtgärder:** klick på aktuellt steg → `@goto-flik` (expanderar kortet på rätt flik); klick på avklarat steg → read-only-historik. **Spärr:** om `plikt` är okvitterad är framåt-övergång blockerad (knappen disabled + tooltip "Kvittera skyddsbedömningen först").
- **Hur den ser ut:** ren presentational-rad av fem noder förbundna med linje; tangentbordsnavigerbar (pil vänster/höger). Aldrig enbart färg.

---

### 9. `NastaAtgardKnapp.vue` — den ledande knappen

- **Syfte:** låg kognitiv last — handläggaren ska aldrig behöva räkna ut nästa steg.
- **Props:** `arende: Arende`.
- **Visar:** verb-först etikett härledd ur **state machine** `(steg, tillstånd) → åtgärd`: "Fatta beslut: inleda / inte inleda", "Färdigställ utredning & för till Treserva", "Granska & godkänn mötesanteckning", "Skicka beslut för underskrift", "Sätt uppföljningsbevakning". Sekundärt: `[▾ gör annat]` → `NcActions`-meny med övriga lagliga åtgärder i fasen.
- **Åtgärder:** `@nasta-atgard(arende)` (container slår upp target-route/dialog). Serverside-validering av att åtgärden är tillåten i fasen (fas-spärr).
- **Hur den ser ut:** `NcButton type="primary"`, bred, hög (≥44px), ikon + verb. Den enda visuellt dominanta knappen på kortet.

```js
// steg→åtgärd state machine (frontend; server validerar)
const NASTA_ATGARD = {
  forhandsbedomning: {
    label: 'Fatta beslut: inleda / inte inleda utredning',
    action: 'beslut-inleda', flik: 'beslut',
  },
  utredning: {
    label: 'Färdigställ utredning & för till Treserva',
    action: 'commit-utredning', flik: 'dokument',
  },
  beslut: {
    label: 'Skicka beslut för underskrift',
    action: 'signera', flik: 'beslut',
  },
  uppfoljning: {
    label: 'Sätt uppföljningsbevakning',
    action: 'bevakning', flik: 'bevakningar',
  },
  avslutat: { label: 'Visa avslutat ärende', action: 'open-rum', flik: 'oversikt' },
}
// väntar-på-motpart / mötesefterspel overrides:
// arende.vantar === 'motesanteckning' → label 'Granska & godkänn mötesanteckning', flik 'moten'
// arende.vantar === 'signaturkvittens' → label 'Delge beslut', flik 'beslut'
```

---

### 10. `FristChip.vue` + `FristPanel.vue` — frist-indikatorn

- **Syfte:** garantera att ingen lagstadgad klocka missas.
- **Chip-props:** `frist: { typ, due, start, kalla, tone, paminnelser, agare }`.
- **Visar (chip):** ikon + **fristtyp + dagar kvar + datum**, eskaleringsfärg grå→gul(≤3 dgr)→röd(förfallen). Fristtyper: **14 dgr** (förhandsbedömning, från inkom-datum), **4 mån** (utredning), **3 v** (överklagande), **tidsbegränsat beslut** (uppföljning), **FL 6 mån / 4 v**. Alltid ikon + text + dagsiffra (aldrig bara färg).
- **Visar (panel, på klick):** källa ("härledd ur inkom-datum 2026-06-10"), påminnelsestatus (T-7/T-3/T-0), och "ägs av Treserva — speglad här" (läst via Frends, inte självständigt räknad).
- **Åtgärder:** chip-klick → `FristPanel`; ingen mutation.
- **Teknik:** `tone` = ren funktion av `(due − idag)`, beräknad **server-side** och speglad (Hubs räknar inte själv → ingen falsk-röd vid förlängning). Återanvänds i `AttTaEmotRad`, `ArendeKort`, `MotesRad`.
- **Hur den ser ut:** liten pill, färgkodad ram + ikon, text "Förhandsbedömning · 2 dgr kvar". Identisk grammatik överallt.

---

### 11. `ProvenansChip.vue` — mellanlagring→facksystem-känslan

- **Syfte:** göra "var hamnar det till slut" begripligt utan att störa.
- **Props:** `provenance: { state:('ej_registrerad'|'registrerad'), dnr?, gallrasDatum?, bevarasDatum? }`.
- **Visar:** två tillstånd — "→ Treserva — ej registrerad" (öppen åtgärd, neutral-tone) och "Registrerad i Treserva, dnr X · Hubs-rum gallras {datum}" (success-tone, **dubbel countdown**: facksystemets bevarande + Hubs-rensning).
- **Åtgärder:** klick i "ej registrerad"-läge → `@commit(arende)` (öppnar `CommitGrind`). Lyssnar på Frends commit-callback för auto-flip.
- **Hur den ser ut:** liten chip med pil-ikon; tom "ej registrerad"-kö = compliance-KPI (mål: noll).

---

### 12. `CommitGrind.vue` — överföringsdialogen (graft från `processboard`, kritisk)

- **Syfte:** göra "för över till Treserva via Frends" till ett **synligt, verifierat ögonblick** och knyta stepper-framsteg + Retention-start till bekräftad commit (GAP-007/019). Ersätter `ProvenansChip`s tidigare tysta auto-flip.
- **Props:** `arende: Arende`, `payload: { typ:('skyddsbedomning'|'beslut'|'utredning'|'motesanteckning'|'bevakning'|'signerat-beslut'), artefakter: Object }`.
- **Visar:** vad som förs över (handling + provenance + ev. signatur/kvittens), destination (Treserva-akt/dnr), och **Frends-status: skickat → bekräftat (API-svar) → registrerat** som en tre-stegs progressindikator.
- **Åtgärder:** **"För över"** → `api.commitToTreserva(payload)`; vid bekräftat API-svar flippar `ProvenansChip`, steppern flyttas fram, **pliktmarkören släcks**, och **Retention-rensningens countdown startar** (aldrig vid en kryssruta). Vid fel: stannar på "skickat", visar felton, ingen flytt.
- **Teknik:** Frends iPaaS-konnektor mot Treserva; återkvittens → provenance. **Hård spärr:** ett kort med okvitterad plikt eller förfallen frist kan inte avancera sin stepper förrän plikten committas här.
- **Hur den ser ut:** `NcDialog`; tre-stegs-rad med spinner→bock; stor "För över"-knapp; konsekvenstext ("Handlingen blir allmän handling i akten. Hubs-rummet gallras {datum} efter bekräftad överföring.").

---

### 13. `MotesRemsa.vue` + `MotesRad.vue` — Zon 4 + mötesefterspel

- **Syfte:** koppla möte → transkribering → godkänd anteckning → Treserva utan att lämna ärendekortet.
- **Remsa-props:** `meetings: Array` (shape ur befintlig `fetchTodaysMeetings`, utökad med `dnr`).
- **Rad visar:** tid + nedräkning ("börjar om 38 min"), `dnr`-chip (ärendekoppling), deltagar-lobbystatus (BankID/Freja-verifierad per person, grön/lila bock-konvention), en-klicks **"Anslut säkert"**.
- **Efterspel (i kortets Möten-flik):** efter mötet en **"Granska & godkänn mötesanteckning"-uppgift** med **transkript + AI-utkast sida vid sida** (påtvingad human-in-the-loop). "Godkänn" loggas → committas via `CommitGrind`; rå-WebM/-transkript får Retention-klocka (pausbar).
- **Åtgärder:** `@join(meeting)` (återanvänder befintlig `deepLinks.callLink`), `@godkann(arende, anteckning)`.
- **Hur den ser ut:** kompakt tidslinje, en rad per möte; lugn remsa, inte ett jämbördigt kort.

---

### 14. `OnboardingTour.vue` — dag-1-guide (graft från `guidad`)

- **Syfte:** 30-sekunders begriplighet för nyanställd. Dismissbar, en gång.
- **Props:** `seen: Boolean`.
- **Visar:** dismissbar banner "Ny här? Så här hänger vyn ihop →" + 3 coach-bubblor i tur: (1) Dagspulsen ("dagens läge i fyra tal"), (2) ett ärendekorts stepper ("så här går ett barnärende — uppifrån och ned"), (3) Nästa-åtgärd-knappen ("tryck på knappen som lyser").
- **Åtgärder:** `@finish()` → `markOnboardingSeen()` (befintlig store-action).
- **Hur den ser ut:** lättviktig overlay med pil-pekare; aldrig blockerande; "Hoppa över" alltid synlig.

> **Återanvänds oförändrat:** `LoaChip.vue`, `MeetingWizard.vue`, `CommandPalette.vue`, `SmartMottagare.vue`, `Onboarding.vue` (5-stegs första-gången, kompletterar `OnboardingTour`). `KommandoPalett` = befintlig `CommandPalette` (Ctrl/⌘K) utökad med fuzzy-sök över ärenden (dnr/namn) + verb-åtgärder.

---

## Demodata som krävs

Demoläget driver hela vyn utan backend (`demoData.js`-mönstret). Två nya feeds plus utökningar; alla i `src/services/demo/socialsekreterare.js`, exponerade via `fetchArendeSummary()` och `fetchArende(dnr)`.

### `Arende` (kärn-shapen — per ärendekort)

```js
{
  dnr: '2026-IFO-0142',              // facksystem-dnr (null om ännu ej registrerad)
  triageRef: 'SN 2026-0142',         // kommunal triage-referens före aktualisering
  barnRef: 'Barn 2026-0142',         // pseudonym, ALDRIG klartext-PII
  steg: 'forhandsbedomning',         // 'inflode'|'forhandsbedomning'|'utredning'|'beslut'|'uppfoljning'|'avslutat'
  substeg: null,                     // ex 'samredigera' under utredning
  sekretess: { kod: 'OSL 26 kap.', skyddadeUppgifter: false },
  loa: 'LOA3',
  frist: {
    typ: 'forhandsbedomning',        // 'forhandsbedomning'|'utredning'|'overklagande'|'tidsbegransat'|'fl6man'
    label: 'Förhandsbedömning',
    due: '2026-06-16', start: '2026-06-02',
    daysLeft: 2, tone: 'warning',    // 'neutral'|'warning'|'error' — server-beräknad
    kalla: 'Inkom 2026-06-02', agare: 'Treserva (speglad via Frends)',
    paminnelser: ['T-7 skickad', 'T-3 idag'],
  },
  provenance: {
    state: 'registrerad',            // 'ej_registrerad'|'registrerad'
    dnr: '2026-IFO-0142',
    bevarasDatum: '2031-06-16',      // facksystemets bevarande
    gallrasDatum: '2026-09-16',      // Hubs-rensning (start efter verifierad commit)
  },
  plikt: null,                       // eller { typ:'skyddsbedomning', label:'Skyddsbedömning krävs idag', kvitterad:false }
  nastaAtgard: {
    action: 'beslut-inleda', label: 'Fatta beslut: inleda / inte inleda utredning',
    flik: 'beslut', vantarPaMig: true,
  },
  vantar: null,                      // 'motesanteckning'|'signaturkvittens'|null (override för Nästa åtgärd)
  // expansion (lazy via fetchArende) — flik-innehåll:
  rum: { groupfolderId, dokument:[…], olasta:2, acl:'du skriver, gruppledare läser' },
  meddelanden: [ /* kvittenser-tidslinje per meddelande */ ],
  moten: [ /* möten + ev. motesanteckning {transkript, aiUtkast, godkand:false} */ ],
  bevakningar: [ /* {titel, dnr, frist, delad:true} */ ],
  beslut: { kravniva:'AES', signStatus:'Skickat 0/1', bevarande:{ pades:true, pdfa:true, ltv:true } },
}
```

### `TriageRad` (Zon 1 — otrierat inflöde)

```js
{
  id: 'tri-1',
  channel: { channel:'sdk', channelLabel:'SDK-Meddelande', messageType:'sdk_message' },
  avsandare: 'Skolkurator', identitet: { badge:'SITHS · LOA3', verifierad:true },
  titel: 'Orosanmälan – Barn 2026-0142',   // ärendereferens, ingen PII
  inkomDatum: '2026-06-14T07:58:00',
  frist: { typ:'forhandsbedomning', label:'Förhandsbedömning', daysLeft:13, tone:'neutral',
           due:'2026-06-28', start:'2026-06-14' },
  provenance: { state:'ej_registrerad' },
}
```

### `Puls` (Dagspulsen)

```js
{ fristerBrinner: 2, motenIdag: 1, attSignera: 1, nyaInflode: 4 }
```

### Möten (utökar befintlig `fetchTodaysMeetings`)

```js
{ token, title:'SIP – Barn 2026-0412', dnr:'2026-IFO-0412', start:'2026-06-14T10:00:00',
  countdownMin: 38, participants, verificationBadge:'green', lobbyState:{ waiting:2 }, hasCall:true }
```

### Sammansättning av demo-listan (minst, för en trovärdig vy)

- **Zon 1:** 4 `TriageRad` (1 ny orosanmälan + 3 inkommande komplettering/svar), varav 1 anonym (legitimt "Ej verifierad").
- **Zon 2:** 3 heta `Arende` — en med **gul 14-dgr-frist** (Barn 2026-0142, nästa åtgärd "Fatta beslut"), en med **röd förfallen frist** + okvitterad pliktmarkör (skyddsbedömning), en som **väntar på motesanteckning-godkännande**.
- **Zon 3:** ~19 aktiva `Arende` fördelade över alla fyra processteg (så segment-filtret har innehåll): ex. 8 utredning, 4 beslut, 5 uppföljning, 2 förhandsbedömning. En i Beslut med `kravniva:'AES'` och `signStatus:'Öppnat 0/1'`.
- **Zon 4:** 1 möte idag (SIP, countdown 38 min, 2 i lobby).
- **Zon 5:** "Klart idag: 7".

> Demo-shaparna ska vara persona-koherenta precis som `DEMO-WIDGETS-CONTRACT.md` kräver: 14-dgr-nedräkningar på orosanmälningar, 4-mån-klocka speglad på utredningar, AES/QES-badge på beslut, dubbel countdown på registrerade ärenden.

---

## Hur arbetsgången (Akt 1–5) stöds steg för steg

Genomgående regel: **ärendekortets processteg = aktens fas**, **"Nästa åtgärd" = aktens nästa steg**, och **varje commit till Treserva sker via `CommitGrind` (Frends)** och syns som ett verifierat ögonblick → provenans-flip + dubbel countdown. Treserva-commit, Inera-signering och lokal transkribering är **riktiga åtgärder** (alla blockerare lösta).

### Akt 1 — Inflöde & triage (steg 1–10) → Zon 1 → Zon 2/3

1. **Inkomst (steg 1–2):** orosanmälan dyker upp som `AttTaEmotRad` i **Zon 1** med kanalikon, verifierad LOA och **14-dgr `FristChip` som redan tickar** (bunden till inkom-datum, GAP-002 löst). Dagspulsen `📥`++.
2. **Skyddsbedömning + plock (steg 3–6):** **"Ta emot & starta förhandsbedömning"** orkestrerar i ETT klick (orkestrerings-endpoint, f.d. GAP-010): tilldela → skapa ärenderum (ACL least-permission + Retention-tagg + BBIC-mall) → starta 14-dgr-klocka → öppna mallstyrd skyddsbedömnings-notering. Skyddsbedömningen **committas direkt via `CommitGrind`** (GAP-001 — den blir journalnotat i Treserva, inte bara Hubs-notering). Raden lämnar Zon 1 och blir ett `ArendeKort` i **Zon 2/3**, stepper på **Förhandsbedömning**. Tills skyddsbedömningen är committad bär kortet en **röd pliktmarkör** som spärrar stepper-framsteg (graft processboard).
3. **Tillåtna kontakter (steg 7):** kortets "Skicka säkert meddelande" är **fas-spärrad** — i förhandsbedömning tillåts bara vårdnadshavare/anmälare/barn; försök att lägga till utomstående ger varning (GAP-006, fas-attribut i datamodellen).
4. **Beslut inleda + aktualisering (steg 8–9):** "Nästa åtgärd" = **"Fatta beslut: inleda / inte inleda"**. Lågrisk → "Godkänn" (loggat, ingen BankID per SKR:s riskmodell). Beslut + aktualisering **committas via `CommitGrind`** → `ProvenansChip` flippar till "Registrerad i Treserva, dnr …". Steppern flyttar till **Utredning**; 4-mån-frist dyker upp, **speglad ur Treserva**.
5. **Stäng loop / gallra (steg 10):** Retention-rensningens countdown startar **efter verifierad commit** (GAP-007), inte vid kryssruta.

### Akt 2 — Utredning & ärenderum (steg 11–22) → stepper: Utredning

- Kortet är i **Utredning**; "Nästa åtgärd" leder genom substegen i `ProcessStepper`: **Inhämta uppgifter** (skolans kartläggning landar som säkert meddelande → "Spara i ärenderum") → **Samredigera** (Collabora on-prem) → **Inhämta samtycke** (säkert formulär + BankID) → **Kommunicera utvalda handlingar** (med maskerings-/sekretessprövningsstöd, varning för tredjemansuppgifter — GAP-017).
- **4-mån-frist (steg 19):** `FristChip` **speglad ur Treserva via Frends** (ingen självständig Hubs-räkning, GAP-018); förlängningsbeslut synkas → ingen falsk-röd.
- **Färdigställ (steg 20–22):** "Nästa åtgärd" = **"Färdigställ utredning & för till Treserva"** → `CommitGrind` committar slutversionen, returnerar commit-id → provenans-flip + dubbel countdown; rena utkast/dubbletter markeras för gallring efter commit.

### Akt 3 — Möte & transkribering (steg 23–34) → Zon 4 + kortets Möten-flik

- **Boka/kalla/lobby (steg 23–25):** från kortet **"Boka säkert möte"** (befintlig `MeetingWizard` → `createSecureMeeting`, ETT server-op) → bokningsbar tid + auto säkert videorum + kallelse via säker e-post + BankID-länk. `MotesRad` i Zon 4 visar lobbystatus per verifierad deltagare.
- **Inspelning → rummet (steg 26–28):** `recording_consent` påtvingat; WebM landar i ärenderummet med Retention-tagg.
- **Transkribering → AI-utkast → godkänn (steg 29–31):** **lokalt** transkript + **lokalt** AI-utkast. **"Granska & godkänn mötesanteckning"** i kortets Möten-flik visar **transkript och utkast sida vid sida** — godkännande är tekniskt påtvingat och loggat (GAP-029). "Nästa åtgärd" på kortet blir denna granskning så länge den är öppen (`arende.vantar = 'motesanteckning'`).
- **För över + gallra (steg 33–34):** "Godkänn" → **`CommitGrind`** committar godkänd anteckning till BBIC-journalen; rå-WebM + transkript får gallrings-countdown; **Retention kan pausas** vid utlämnandebegäran (GAP-031).

### Akt 4 — Beslut, signering, delgivning (steg 35–44) → stepper: Beslut

- **Ta fram → signera (steg 35–37):** "Nästa åtgärd" = **"Skicka beslut för underskrift"** → **Inera Underskriftstjänst (AES via BankID/Freja/SITHS)** → PAdES/PDF/A-1 + LTV (se `SIGNING-INERA.md`). Signeringskön + spegelvyn (Skickat → Öppnat → Signerat X av N) bor i kortets **Beslut-flik**.
- **Bevarandekontroll (steg 38):** bevarandepanel **"Giltig nu / Giltig då"** (PAdES + PDF/A-1 + LTV ✓) som grind före commit.
- **Delge + frist (steg 41–42):** **"Delge beslut"** med val av delgivningssätt (vanlig/förenklad/digital brevlåda); `kvittenser`-tidslinjen (Skickad → Levererad → Öppnad → Inloggad LOA3 → Läst) i kortet; **överklagande-frist (3 v)** sätts automatiskt med startdatum **härlett ur delgivningssättet** (GAP-039).
- **Committa + arkivera (steg 43–44):** `CommitGrind` committar signerad handling + valideringsintyg + delgivningsbevis → provenans-flip; vid avslut FGS-export till e-arkiv (ansvarsgräns Treserva↔Hubs). Steppern flyttar till **Uppföljning**.

### Akt 5 — Bevakning & todo (steg 45–51) → kortets Bevakningar-flik + Dagspulsen

- **Skapa bevakning (steg 45–47):** på en triage-rad eller i kortet skapas en bevakning med förifylld titel/dnr/föreslagen frist; för fristbärande poster **föreslås delad board som default** (GAP-042).
- **Påminnelser (steg 48–49):** T-7/T-3/T-0 **bara till tilldelad**; de fyra lagklockorna modelleras och speglas ur Treserva.
- **Klarmarkera (steg 50–51):** vid klarmarkering frågar Hubs **"Gallra (personlig notering)"** vs **"För till ärendet/facksystemet"**; "för till ärendet" → `CommitGrind` committar och **river kvarvarande Hubs-påminnelser** så Treserva blir ensam fristägare (GAP-044). Tom "ej registrerad"-kö i Dagspulsen = compliance-KPI (GAP-049).

---

## Lättbegriplighet & onboarding

### Första 30 sekunderna för en ny socialsekreterare

1. **0–5 s:** `Dagspulsen` i fyra tal — "två frister brinner, ett möte, ett att signera, fyra nya". Ingen jargong, inga widget-namn.
2. **5–15 s:** `VadVillDuGora` med fem verbknappar = hela yrket i fem verb. Hon har lärt sig arbetsgången utan manual. Zon 1 "Att ta emot" är självförklarande — "nya saker som ännu inte är mina ärenden", två tydliga knappar.
3. **15–30 s:** Hon ser sina ärendekort. **Steppern lär ut processen** — fem ord visar hela livscykeln. Den lysande "Nästa åtgärd"-knappen säger exakt vad hon ska göra. `OnboardingTour` pekar på stepper + knapp.

### Etiketter (verb-först, svensk myndighetston, FK/AF-designsystem)

"Ta emot & starta förhandsbedömning" · "Skapa ärenderum" · "Skicka säkert meddelande" · "Boka säkert möte" · "Fatta beslut" · "Skicka beslut för underskrift" · "Granska & godkänn mötesanteckning" · "Delge beslut" · "För till Treserva" · "Skapa bevakning". **Status (GOV.UK-minimal, delad med `sections.js`):** `Ny · Tilldelad · Väntar på kvittens · Besvarad · Klar` + rött `Kräver åtgärd` (återanvänder befintliga `STATUSES`/`SECTIONS`-tokens; sektion `kraver_atgard` → Zon 2-logiken).

### Tomma tillstånd (undervisande, graft guidad — `NcEmptyContent`)

- Zon 1 tom → *"Här landar nya orosanmälningar. Inget otriagerat just nu — allt inkommande är omhändertaget. När en kommer ser du en 14-dagars nedräkning direkt."*
- Inga röda frister → *"Inga förfallna frister. Inget barn mellan stolarna."*
- Zon 3 per filter tom → *"Inga ärenden i Beslut just nu."*
- Zon 4 tom → *"Inga säkra möten idag."*

### Mikrohjälp (progressive disclosure)

Liten "?"-ikon vid steppern ("Vad betyder förhandsbedömning?") och vid varje frist ("14-dagarsfristen löper från att anmälan inkom 2026-06-10"). Inline-hjälp första gången vid en åtgärd ("Att plocka ett ärende betyder att du blir ansvarig — 14-dagarsklockan räknas från när anmälan kom in, inte från nu"). Hjälp/Kunskapsbank på **fast plats** i foten (WCAG 3.2.6). Samma ikon = samma funktion mellan vyer (3.2.4).

### Första-gången-guide

`Onboarding.vue` (befintlig 5-stegs-modal, behålls) + ny `OnboardingTour.vue` (dismissbar dag-1-banner som highlightar Dagspulsen → stepper → Nästa-åtgärd). Båda styrs av `prefs.onboardingSeen` (befintlig). `verbEntryDismissed` styr när `VadVillDuGora` kollapsar.

### WCAG 2.2 AA (byggs in från start, per CONTRACTS regel 4)

- **Target Size ≥ 24×24 px** på alla status-/snabbåtgärdsknappar, frist-chips, Dagspuls-räknare (`.hs-target` eller Nc*).
- **Dragging Movements (2.5.7):** omordning/filtrering/sortering via knapp/tangentbord, aldrig bara drag.
- **Focus Not Obscured (2.4.11):** sticky Zon 0/Dagspulsen får inte dölja fokuserat ärendekort.
- **Reflow/Orientation (1.4.10/1.3.4):** enspalts-IA fungerar i porträtt och vid 400 % zoom (fältarbete/hembesök); Dagspulsen wrappar till 2×2.
- **Accessible Authentication (3.3.8):** BankID/Freja/SITHS utan kognitiva test.
- **Non-text/Color (1.4.1):** nedräkningsklockor och stepper-steg alltid ikon + text + dagsiffra, aldrig enbart färg.
- **`aria-live="polite"`** på triage-strömmen (inkommande), `aria-current="step"` på stepperns aktuella steg.

---

## Primära åtgärder (slutlig lista)

1. **Ta emot & starta förhandsbedömning** — triagera nytt inflöde → ärende, med skyddsbedömning committad till Treserva (Zon 1, `CommitGrind`).
2. **Driv nästa steg** — kontextuell "Nästa åtgärd" per ärendekort (state machine: inleda-beslut / färdigställ-utredning / signera / följ upp).
3. **Skicka säkert meddelande** — fas-spärrad, med läskvittens.
4. **Kalla till säkert möte** — → transkribering → granska & godkänn → för till Treserva.
5. **Skicka beslut för underskrift & delge** — Inera-AES → delgivning med kvittens → committa via `CommitGrind`.

*(Alla nåbara via ärendekortets kontextuella knappar, `VadVillDuGora`-verbingången, och Ctrl/⌘K-paletten — rollfiltrerat.)*

---

## Migrationsnot — så ersätter detta nuvarande widget-layout

**Princip:** denna redesign gäller **endast** personan `socialsekreterare`. Alla andra personas behåller sin nuvarande bento-grid-layout (`Start.vue` + `WidgetRenderer` + `personaConfig.js`-layouten) **tills vidare**. Inget rivs.

1. **Router/vy-växel i `Start.vue`.** `Start.vue` får en gren: om `state.activePersona === 'socialsekreterare'`, rendera `<MinaArenden />` i stället för `__grid`-blocket (`<main>`/`<aside>` + `WidgetRenderer`). Alla andra personas faller igenom till befintlig grid. `HeaderBar`/`ActionBar`/modaler ligger kvar i `Start.vue` och återanvänds; `MinaArenden` får dem som slots eller via samma store-events. (Minsta diff: villkorlig `v-if`/`v-else` runt grid-blocket; ingen befintlig persona påverkas.)

2. **Datakälla.** Socialsekreterar-vyn läser ur **två** nya tjänstefunktioner i `api.js`, byggda i samma stil som `fetchSummary`:
   - `fetchArendeSummary()` → `{ puls, triage:[TriageRad], arenden:[Arende(kollapsat)] }` (utökar/parallell till `fetchSummary`; **server-side aggregat**, ingen fan-out).
   - `fetchArende(dnr)` → full `Arende` med flik-innehåll (lazy vid kort-expansion).
   - `commitToTreserva(payload)` → Frends-orkestrering (driver `CommitGrind`).
   Backend: nya OCS-routes i sdkmc `/api/v1/arende-summary`, `/api/v1/arende/{dnr}`, `/api/v1/treserva/commit` (additivt, samma mönster som befintliga `/summary`-routes i CONTRACTS). Demoläge: motsvarande stubbar i `demo/socialsekreterare.js` (mönster från `demoData.js`).

3. **`personaConfig.js`.** Socialsekreterarens `layout.main/side` blir irrelevant när `MinaArenden` tar över — behåll posten (för fallback/andra ytor) men markera `customView: 'MinaArenden'`. `actionsForPersona('socialsekreterare')` returnerar de fem primära åtgärderna ovan.

4. **Återanvändning, inte duplicering.** `LoaChip`, `FristChip` (nytt, men delas direkt med övriga personas som idag använder `fristStrip`-descriptorn), `ProvenansChip`/`DestinationsChip` (slå ihop till en delad atom), `channelMeta`, `deepLinks`, `sections.js`-statusar, `.hs-card`, `MeetingWizard`, `CommandPalette`, `SmartMottagare` återanvänds oförändrat. `DEMO-WIDGETS-CONTRACT.md`s `tone`-enum och `channel`-enum gäller.

5. **Stegvis utrullning.** Bakom en feature-flag/preferens (`socialsekreterareNewView`, default på i demo) så att den gamla widget-vyn kan återställas per användare under inkörning. Inga andra personas, inga backend-rivningar, inga brand-regelbrott.

6. **Test/bygg.** Enhetstester för `zonOf`, state machine (`NASTA_ATGARD`), `FristChip`-tone-funktion och `CommitGrind`-spärren (okvitterad plikt → ingen stepper-flytt). Bygge i Linux dev-env (`make webpack`); Windows-host kör bara lint/jest.

---

*Grundas i `SOCIALSEKRETERARE-WALKTHROUGH.md` (51 steg), `GAP-ANALYSIS.md`, `SIGNING-INERA.md`, `CONTRACTS.md`, `DEMO-WIDGETS-CONTRACT.md`, samt UX-koncepten `ux-concept-{arende,mindag,processboard,guidad}.md` och de tre domarutslagen. Vinnare: `ux-concept-arende`, grafter från mindag/processboard/guidad. Varumärkesregel: aldrig "Nextcloud"/"Talk" i UI-text.*
