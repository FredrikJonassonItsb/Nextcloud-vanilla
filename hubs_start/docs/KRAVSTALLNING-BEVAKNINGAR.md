---
titel: Kravställning & specifikation — bevakningarnas livscykel
status: v1.0 (2026-07-08) — väntar Fredriks ratificering; ingen kod byggd
grund: analysis-output/BEVAKNING-LIVSCYKEL-ANALYS-2026-07-07.md (5-tråds kodgenomlysning)
relaterat: ARENDETYPER-FLODESANALYS.md §3.1, GAP-ANALYSIS.md (GAP-044/GAP-049), HUBS-KRAVSTALLNING-TOTAL.md K-7.27
---

# Kravställning — den riktiga bevaknings-livscykeln

**Styrande princip (Fredrik):** *En bevakning ska nollställas när det man bevakar uppnås.*
Analysen visade att ingenting i dagens kod gör detta — och att grundmodellen (ett inert
Deck-kort per ärende + ett engångs-frist-tal) inte ens kan uttrycka det. Denna kravställning
specificerar den modell som kan.

**Verifierade tekniska förutsättningar** (2026-07-08, styr kraven):
- Deck-API:t har redan allt som behövs: `PUT /cards/{id}` (inkl. duedate), `PUT …/done`,
  `…/undone`, `…/archive`; Card-modellen bär `done` + `duedate`. DeckClient-utökningen är
  ren klientkod — inga Deck-ändringar.
- `FristVarselJob` finns redan (dagligt varsel T-3/T-0 mot registrets frist, mottagare =
  tilldelad handläggare annars mottagningskrets, Notifier `SUBJECT_FRIST`). Varsel-lagret
  ska **generaliseras** till bevakningsposter — inte nybyggas.
- `Handelse`-journalen, pekar-modellen, `ArendeTyp`-registret (datadrivet) och
  gallrings-/avsluts-seams är etablerade mönster som återanvänds.

---

## 0. Sammanfattning — designbeslut att ratificera

1. **Bevakningen blir en egen post** (`hubs_arende_bevakning`) — inte ett fält på ärendet,
   inte ett Deck-kort. Ett ärende kan ha flera samtidiga bevakningar med olika villkor.
2. **Nollställning är händelsedriven:** varje motorhändelse (steg-övergång, koppling, commit,
   signering, avslut) utvärderas mot aktiva bevakningars **maskinläsbara villkor** →
   träff ⇒ status `uppnadd`. Ingen polling, ingen mänsklig disciplin som förutsättning.
3. **Deck blir en projektion, aldrig sanningen:** ett kort per bevakning, med `duedate`;
   uppnådd ⇒ `done`; ärende avslutat/gallrat ⇒ kortet arkiveras/raderas. Registret i motorn
   är sanningen (NEVER-SoR gäller inte här — bevakningar är koordinationsdata, exakt det
   motorn FÅR äga; de gallras med ärendet).
4. **`fristDue` blir en projektion** av den mest brådskande aktiva bevakningen — en källa
   till sanning; FristChip/dashboard förblir oförändrade konsumenter.
5. **Standardbevakningar är datadrivna** per ärendetyp (`bevakningsMallar` i ArendeTyp-
   registret) och skapas både vid födelse och vid steg-övergångar — det stänger
   `perStegFrist`-hålet (mekanismen finns men ingen typ använder den).
6. **Terminologin städas:** sdkmc-widgeten "Bevakningar" (frånvaro-delegeringar) döps om;
   ordet *bevakning* reserveras för ärende-bevakningar.

---

## 1. Begreppsmodell & tillståndsmaskin

**En bevakning** = "systemet håller ögonen på att X sker senast T (eller alls), åt person P,
och släcker sig själv när X sker."

```
                    villkor träffat (händelse)
        ┌───────────────────────────────────────► UPPNÅDD ──┐ (recurring: ny cykel)
        │                                                    ▼
  AKTIV ┤  T-0 passerat utan träff                      [ev. ny AKTIV post]
        ├───────────────────────────────────────► PASSERAD (eskalering, kvarstår
        │                                                    tills kvitterad/avbruten)
        │  ärende avslutas / manuellt borttag / ägarskifte till facksystem
        └───────────────────────────────────────► AVBRUTEN
```

| Krav | Beskrivning |
|---|---|
| **K-BEV-1.1** | En bevakning SKA vara en egen persistent post med egen livscykel, oberoende av andra bevakningar på samma ärende. Tillstånd: `aktiv`, `uppnadd`, `passerad`, `avbruten`. |
| **K-BEV-1.2** | Tillståndsövergångar SKA vara **monotona** (en uppnådd/avbruten bevakning återaktiveras aldrig — recurring skapar en NY post; historiken bevaras för journal/statistik tills gallring). |
| **K-BEV-1.3** | `passerad` är ett **larmtillstånd**, inte ett slutläge: bevakningen kvarstår synlig och eskalerad tills villkoret ändå uppnås (→`uppnadd`, försenat noteras) eller den avbryts medvetet. Ett barn-ärende där 14-dagarsfristen sprungit förbi är inte "klart" — det är brådskande. |
| **K-BEV-1.4** | Ordet **"bevakning"** SKA i UI reserveras för ärende-bevakningar. sdkmc-dashboardwidgeten (OutOfOffice-delegeringar) döps om till **"Frånvaro & delegering"**. |

## 2. Datamodell

Ny tabell **`hubs_arende_bevakning`** (mönster: Member/Part — QBMapper, index på hubs_case_id):

| Kolumn | Typ | Innebörd |
|---|---|---|
| `id` | BIGINT PK | |
| `hubs_case_id` | VARCHAR(36) NOT NULL | FK → ärendet; gallras med det |
| `typ` | VARCHAR(32) NOT NULL | mall-id (`forhandsbedomning_14d`, `komplettering`, `manuell`, …) |
| `titel` | VARCHAR(255) NOT NULL | **pseudonym** rubrik ("Förhandsbedömning — beslut inom 14 dgr"), ALDRIG PII (samma regel som Deck-korten/journalen) |
| `villkor_typ` | VARCHAR(32) NOT NULL | maskinläsbart villkor, se §3 |
| `villkor_arg` | VARCHAR(128) NULL | villkorets argument (t.ex. målsteg `utredning`) |
| `status` | VARCHAR(16) NOT NULL | `aktiv`/`uppnadd`/`passerad`/`avbruten` |
| `frist_due` | DATE NULL | bevakningens deadline (null = villkorsbevakning utan datum) |
| `ankare` | VARCHAR(32) NOT NULL | vad fristen räknades från (`inkom_datum`, `steg_datum`, `manuell`, `cykel`) |
| `recurring_dagar` | INT NULL | cykellängd; ≠null ⇒ uppnådd föder ny aktiv post (§5) |
| `lagstadgad` | BOOL NOT NULL | styr eskalerings-/UI-ton (röd rättslig chip vs SLA, jfr K-roll-kraven "aldrig röd rättslig chip" för SLA) |
| `skapad_av` | VARCHAR(64) NOT NULL | uid eller `''` = systemet/mallen |
| `uppnadd_datum`, `uppnadd_av` | DATETIME/VARCHAR NULL | när + vilken händelse/uid som släckte |
| `skapad` | DATETIME NOT NULL | |

| Krav | Beskrivning |
|---|---|
| **K-BEV-2.1** | Posten SKA vara koordinationsdata utan PII: titel/typ/villkor/datum — aldrig namn/pnr/sakinnehåll. Vad bevakningen *handlar om i sak* bor i akten/facksystemet. |
| **K-BEV-2.2** | Raderna SKA gallras med ärendet (`GallringService` utökas — samma paragraf som members/parter/journal). |
| **K-BEV-2.3** | Deck-kortets pekare per bevakning lagras i befintlig pekar-modell (`objekt_typ='deck_card'`, `objekt_id=cardId`, `riktning=boardId`) — en pekare per bevakningskort, så kompensation/gallring/enumerering återanvänder etablerad mekanik. Bevakningsposten bär `deck_card_id` som referens. |

## 3. Villkorsmotorn — händelsedriven nollställning (kärnan)

**`BevakningService::utvardera(string $hubsCaseId, string $handelseTyp, array $kontext)`**
anropas av motorn **efter varje huvudhändelse**. Den matchar ärendets aktiva bevakningar mot
händelsen; träff ⇒ `uppnadd` + Deck-done + journal + ev. recurring-avknoppning + fristDue-
omprojektion. Best-effort som journalen: ett bevaknings-fel får ALDRIG fälla huvudåtgärden.

**Villkorstyper (v1) och vilka händelser som utvärderar dem:**

| `villkor_typ` | Träffas av | Exempel |
|---|---|---|
| `steg_uppnatt` (arg=målsteg) | steg-övergång (`transitionera`) | 14-dgrs förhandsbedömning släcks när ärendet lämnar `forhandsbedomning`; 4-mån utredning släcks vid `beslut` |
| `komplettering_kopplad` | `kopplaMeddelande` (kat 4-koppling) | "Väntar på komplettering" släcks när handlingen kopplas |
| `commit_registrerad` | verifierad commit-callback (`FacksystemCommitService::verifyCallback`) | "Väntar på registrering" släcks; även ägarskiftes-triggern §9 |
| `signering_kvitterad` | signeringskvittens (när SigneringPort wiras) | "Beslut väntar underskrift" släcks |
| `datum_passerat` | dagligt varseljobb (§6) | ren datumbevakning: T-0 nått = **uppnådd** (t.ex. överklagandefrist löpt ut ⇒ laga kraft) — skiljs från `passerad` som är miss |
| `manuell_kvittering` | handläggaren klarmarkerar i UI (§10) | fritt formulerade bevakningar |

| Krav | Beskrivning |
|---|---|
| **K-BEV-3.1** | Varje händelse i tabellen SKA anropa villkorsmotorn direkt efter sin egen commit/journal (samma transaktionsfilosofi som `loggaHandelse`: efteråt, best-effort, aldrig fail-closed för huvudflödet). |
| **K-BEV-3.2** | Villkorstyperna SKA vara en **sluten, maskinläsbar enum** — fri text får inte förekomma i `villkor_typ` (annars kan motorn aldrig utvärdera; fri text hör till `manuell_kvittering`-bevakningars titel). |
| **K-BEV-3.3** | En träff SKA registrera `uppnadd_av` (händelsetyp + aktör-uid) och journalföras (`TYP_BEVAKNING`, detalj: {bevakningTyp, handling: uppnadd/passerad/avbruten/skapad, villkor} — utan PII). |
| **K-BEV-3.4** | Bevakningar med både villkor OCH datum (normalfallet: "X ska ske senast T"): villkorsträff före T ⇒ `uppnadd`; T passerat utan träff ⇒ `passerad` (larm §6) men **fortsätter utvärderas** — sen träff ⇒ `uppnadd` med `forsenad=true` i journalen (K-BEV-1.3). |

## 4. Standardbevakningar per ärendetyp (datadrivet)

`ArendeTyp`-registret utökas med **`bevakningsMallar[]`**: `{typ, titel, villkor, fristDagar,
ankare, vidSteg, lagstadgad, recurringDagar?}` där `vidSteg` styr NÄR mallen instansieras —
`fodelse` eller ett stegnamn. **Detta ersätter dagens oanvända `perStegFrist`.**

Seedning (v1, barn & familj — härledd ur ARENDETYPER-FLODESANALYS §3.1 + BBIC-livscykeln):

| Ärendetyp | Mall | Skapas | Villkor | Frist |
|---|---|---|---|---|
| orosanmalan | Förhandsbedömning — beslut | födelse | `steg_uppnatt:utredning` *(eller avslut = "inte inleda")* | 14 dgr ur inkom |
| orosanmalan | Utredning — färdigställ | steg `utredning` | `steg_uppnatt:beslut` | 4 mån ur stegdatum |
| ansokan_bistand | Registrering i facksystem | födelse | `commit_registrerad` | — (villkorsbevakning) |
| ekonomi | Månadscykel | födelse | `datum_passerat` | 30 dgr, `recurringDagar=30` |
| vard_samverkan | *(ingen — kalenderkoordinering, kat 5)* | | | |
| rattsligt_tvang | Domstolsfrist | födelse | `datum_passerat` | speglas/`lagstadgad` |
| uppfoljning/placering | Övervägande av vård | steg `uppfoljning` | `manuell_kvittering` | 6 mån, `recurringDagar=182` |

| Krav | Beskrivning |
|---|---|
| **K-BEV-4.1** | Standardbevakningar SKA skapas automatiskt (a) i sagan vid födelse (ersätter R5:s generiska kort) och (b) i `transitionera()` när ärendet når ett `vidSteg` — datadrivet ur registret, ingen ny kod per ärendetyp. |
| **K-BEV-4.2** | En steg-övergång som släcker en bevakning och föder nästa (14-dgrs → 4-mån) SKA göra båda i samma händelseutvärdering — det ÄR "nollställningen" Fredrik efterfrågar: gamla klockan släcks, nya startar. |
| **K-BEV-4.3** | `fristPolicy.perStegFrist` avvecklas (ingen typ använder den); dess avsikt realiseras av `bevakningsMallar.vidSteg`. `computeFristDue` behålls under migreringen (§13). |

## 5. Recurring (övervägande var 6:e månad m.fl.)

| Krav | Beskrivning |
|---|---|
| **K-BEV-5.1** | När en bevakning med `recurring_dagar` uppnås/kvitteras SKA en NY aktiv post skapas med frist = kvitteringsdatum + cykel (ankare=`cykel`). Historiken bevaras (varje övervägande är juridiskt ett eget ställningstagande). |
| **K-BEV-5.2** | Recurring SKA upphöra när ärendet lämnar det steg som bar mallen (`vidSteg`) eller avslutas — ingen evighetscykel på döda ärenden. |

## 6. Påminnelser & eskalering — generalisera FristVarselJob

| Krav | Beskrivning |
|---|---|
| **K-BEV-6.1** | `FristVarselJob` SKA generaliseras till **BevakningVarselJob**: daglig sweep över AKTIVA bevakningar med `frist_due`; varselpunkter **T-7, T-3, T-0** (T-7 tillkommer mot idag). Mottagarlogik oförändrad (tilldelad handläggare, annars mottagningskrets). Notifier får `SUBJECT_BEVAKNING` (behåller SUBJECT_FRIST för bakåtkomp under migrering). |
| **K-BEV-6.2** | T-0 passerat utan villkorsträff ⇒ status `passerad` + **eskaleringsnotis** även till arbetsledarrollen (fördelarläge-behöriga) för `lagstadgad=true`-bevakningar. SLA-bevakningar eskalerar inte rättsligt-rött (K-roll-regeln "aldrig röd rättslig chip" för SLA). |
| **K-BEV-6.3** | Varsel SKA vara deterministiska utan extra state (dagens exakthetsmönster: träff bara på exakt T-7/T-3/T-0) och PII-fria (pseudonym referens + bevaknings-titel + datum). |

## 7. Deck-projektionen

| Krav | Beskrivning |
|---|---|
| **K-BEV-7.1** | Ett Deck-kort **per bevakning** (ersätter dagens ett-per-ärende): titel = bevakningens pseudonyma titel + kortRef, `duedate` = `frist_due`, label `case:{hubsCaseId}`. Skapas när bevakningen skapas. |
| **K-BEV-7.2** | `DeckClient` utökas med `updateCard(boardId, cardId, {duedate?, title?})` (`PUT /cards/{id}`), `markDone(cardId)` / `markUndone` (`PUT /cards/{id}/done|undone`) och `archiveCard` — API-ytan är verifierad i Deck-appen. Samma graceful/no-op-mönster som övriga metoder. |
| **K-BEV-7.3** | Statusprojektion: `uppnadd` ⇒ done; `passerad` ⇒ kortet kvar + (om Deck-versionen saknar visuell overdue-signal räcker duedate — Deck rödmarkerar själv); `avbruten`/avslut ⇒ arkiveras; **gallring ⇒ raderas** (P1-fixen: `GallringService` får DeckClient och kallar `deleteCard` för alla `deck_card`-pekare — åtgärdar orphan-buggen oavsett resten). |
| **K-BEV-7.4** | Deck förblir presentationslager: om Deck saknas/failar lever bevakningen fullt ut i motorn (skapande, villkor, varsel, UI-flik). Kort-operationer är best-effort. |

## 8. `fristDue` blir projektion

| Krav | Beskrivning |
|---|---|
| **K-BEV-8.1** | `register.fristDue` SKA sättas av BevakningService = **min(frist_due) över aktiva bevakningar** (lagstadgade före SLA vid samma datum). Uppdateras vid varje bevaknings-mutation. FristChip/dashboard/varsel-listan förblir oförändrade konsumenter. |
| **K-BEV-8.2** | Tonen på FristChip (`lagstadgad` vs SLA) SKA följa den ledande bevakningens flagga — dagens frist-ton-härledning byts från policy-gissning till bevaknings-fakta. |

## 9. Dubbelbevakningen (GAP-044) — ägarskifte, preciserat

Kraven sa VAD men inte NÄR/HUR. Här är preciseringen:

| Krav | Beskrivning |
|---|---|
| **K-BEV-9.1** | **NÄR:** vid **verifierad** commit-callback (samma grind som retention, GAP-007) — aldrig på anropet. **HUR:** bevakningar vars ärendetyp har `speglasUrTreserva=true` och som avser den frist facksystemet nu äger ⇒ status `avbruten` med `uppnadd_av='agarskifte_facksystem'` + journalnotis ("bevakning överlämnad till facksystemet vid registrering dnr X"). |
| **K-BEV-9.2** | Efter ägarskiftet får Hubs INTE visa egen röd frist för samma sak (false reassurance-risken) — FristChip visar i stället provenansmarkerad text "Frist bevakas i facksystemet". |

## 10. Manuella bevakningar + OCS-API (lagar den döda knappen)

| Krav | Beskrivning |
|---|---|
| **K-BEV-10.1** | Nya OCS-endpoints (mönster: PartController): `GET /arende/{ref}/bevakningar` (ersätter dagens Deck-projektion — läser nu registret), `POST /arende/{ref}/bevakning` `{titel, fristDatum?, recurringDagar?}` (villkor=`manuell_kvittering`), `POST /arende/{ref}/bevakning/{id}/kvittera`, `DELETE /arende/{ref}/bevakning/{id}` (⇒ `avbruten`). Alla authz via `ArendeService::show`. |
| **K-BEV-10.2** | Frontendens `skapa-bevakning` flyttas till `ARENDE_VERB`-familjen/egen api-funktion mot motorn (buggen: routas idag till obefintligt sdkmc-endpoint men visar "Bevakning skapad"). Toast först efter verifierat svar — aldrig optimistisk succé (husregeln efter tidigare fynd). |
| **K-BEV-10.3** | "Skapa bevakning" från ett inflöde-meddelande SKA förifylla titeln pseudonymt (aldrig ämnesrad/PII rakt av) — handläggaren bekräftar i en enkel modal (NyChattModal-mönstret). |

## 11. UI-krav

| Krav | Beskrivning |
|---|---|
| **K-BEV-11.1** | Ärendekortets **Bevakningar-flik** visar registrets poster: titel, frist, status-chip (`aktiv`=neutral, `uppnadd`=grön m. datum, `passerad`=röd/varning enligt `lagstadgad`, `avbruten`=grå m. orsak) + **Kvittera**-knapp för `manuell_kvittering`-poster + skapa/ta bort. Räknaren i fliketiketten = antal AKTIVA. |
| **K-BEV-11.2** | Dashboardens varsel-lista ("Kräver åtgärd nu") matas av `passerad`-bevakningar (idag: frist-ton ur registret) — samma yta, ärligare källa. |
| **K-BEV-11.3** | Uppnådda bevakningar syns i "Historik & beslut"-tidslinjen via journalposterna (TYP_BEVAKNING) — nollställningen blir synlig och granskningsbar, inte tyst. |

## 12. Journal, PII & doktrin

| Krav | Beskrivning |
|---|---|
| **K-BEV-12.1** | Ny journaltyp `TYP_BEVAKNING`; varje skapande/uppnående/passering/avbrott journalförs med koordinationsdetaljer — ALDRIG PII (etablerad detalj-regel). |
| **K-BEV-12.2** | Bevakningsposter är koordinationsdata som motorn äger (ingen SoR-konflikt); de gallras med ärendet (K-BEV-2.2) och kopieras aldrig till handlingar. |

## 13. Migrering & kompatibilitet

| Krav | Beskrivning |
|---|---|
| **K-BEV-13.1** | Additiv migration (tabellen) + backfill-steg: varje AKTIVT ärende med `fristDue` får en bevakningspost skapad ur sin ärendetyps mall (ankare/status härleds); ärenden utan frist får mallarnas födelse-bevakningar i efterhand. Gamla generiska R5-kortet: uppdateras till första bevakningens kort (rename + duedate) i stället för att skapa dubblett. |
| **K-BEV-13.2** | `computeFristDue`/`maybeRecomputeFristDue` behålls som fallback tills backfill körts på alla miljöer; därefter avvecklas de (fristDue skrivs då enbart av projektionen K-BEV-8.1). |

## 14. Acceptanskriterier — real-fall-matrisen som test

Analysens matris blir kravens facit; varje rad = E2E-testfall:

| # | Scenario | Godkänt när |
|---|---|---|
| A1 | Orosanmälan föds | 14-dgrs-bevakning aktiv, Deck-kort med duedate=+14d, FristChip visar samma |
| A2 | Steg → utredning (dag 9) | 14-dgrs `uppnadd` (kort=done, journalfört), 4-mån-bevakning född, fristDue=+4 mån |
| A3 | Steg → utredning (dag 16) | 14-dgrs var `passerad` (eskalering skickades dag 14) och blir `uppnadd (försenad)` |
| A4 | Komplettering kopplas | `komplettering_kopplad`-bevakning `uppnadd` i samma åtgärd |
| A5 | Verifierad commit, speglasUrTreserva-typ | frist-bevakningen `avbruten (ägarskifte)`; FristChip visar "bevakas i facksystemet" |
| A6 | Manuell bevakning kvitteras | `uppnadd`, kort done, journalrad; recurring-varianten föder ny post +182d |
| A7 | Ärendet avslutas | alla aktiva ⇒ `avbrutna`, Deck-kort arkiverade |
| A8 | Ärendet gallras | poster raderade, **Deck-kort raderade** (inga orphans) |
| A9 | T-7/T-3/T-0 | notiser till rätt mottagare, exakt en gång per tröskel |
| A10 | Deck avstängt | A1–A9 fungerar ändå (minus kort-projektionen) |

## 15. Faser

| Fas | Innehåll | Kommentar |
|---|---|---|
| **0 — P1-buggar (kan gå före ratificering)** | GallringService→deleteCard (orphans, K-BEV-7.3-delen) + döda skapa-bevakning-knappen (ta bort eller stub-ärlig disable tills fas 1) | Små, fristående, redan verifierade |
| **1 — Kärnan** | Tabell+mapper+BevakningService, villkorsmotorn på steg/koppling/commit/avslut, standardmallar (orosanmälan-kedjan), fristDue-projektion, OCS + flik-UI, journal, gallring/avslut-städning, backfill | Levererar Fredriks princip end-to-end för huvudfallen |
| **2 — Projektion & varsel** | DeckClient-utökning + per-bevakning-kort + done/arkiv, BevakningVarselJob (T-7/T-3/T-0 + eskalering), varsel-listans nya källa | Synlighet på tavlan + proaktivitet |
| **3 — Fullständighet** | Recurring (övervägande), ägarskiftet GAP-044, signering-villkoret (när SigneringPort wiras), omdöpning sdkmc-widget, avveckla computeFristDue | |

## 16. Icke-mål & öppna beslutspunkter

**Icke-mål:** kalenderkoordinering (kat 5 förblir CalDAV — ingen bevakning), Treserva-frist-
*spegling in* (hör till QueryPort-spåret 2b; ägarskiftet §9 räcker här), egna
notifikationskanaler utöver plattformens.

**Beslutspunkter (Fredrik):**
1. **Deck-kort per bevakning** (rekommenderas — tavlan blir arbetsledarens sanna kö) eller
   behåll ett kort per ärende med bevakningarna som checklista på kortet?
2. **Överklagandefristen** (3 v efter delgivning): egen standardmall i fas 1 (kräver
   delgivningsdatum som ankare — finns inte i registret ännu) eller manuell bevakning tills
   delgivningskvittens-flödet byggs?
3. Ska **`passerad` + lagstadgad** även notifiera utanför plattformen (mejl) eller räcker
   NC-notiser + dashboardens röda lista (rekommenderat: räcker, mejl = senare beslut)?
4. Fas 0 direkt (P1-buggarna) — kör vi den utan att invänta ratificering av resten?
