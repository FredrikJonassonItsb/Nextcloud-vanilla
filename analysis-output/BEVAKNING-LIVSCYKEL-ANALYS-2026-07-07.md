# Analys — Bevakningarnas livscykel: nollställs de när det bevakade uppnås?

**Fråga (Fredrik):** En bevakning ska nollställas när det man bevakar uppnås. Gör koden det idag?
**Datum:** 2026-07-07 · **Metod:** read-only kodgenomlysning (5 trådar) + direkt verifiering · **Scope:** hubs_arende + hubs_start + sdkmc.

---

## 0. Kort svar

**Nej.** Genom hela kedjan finns **ingen** mekanism som nollställer, uppdaterar eller stänger en
bevakning när det bevakade uppnås. Bevakningen är helt inert från ärendets födelse till (i
praktiken) aldrig. Värre: den städas inte ens bort när ärendet **avslutas** eller **gallras** —
det blir kvarlämnade "orphan"-kort på Deck-tavlan. Den enda plats i koden där ett bevaknings­kort
någonsin raderas är saga-kompensationen om **skapandet misslyckas** — dvs. bara om ärendet
*inte* föds.

---

## 1. Vad "bevakning" faktiskt är i koden — tre olika saker med samma namn

En stor del av otydligheten är att **ordet "bevakning" betyder tre olika saker** i systemet:

| # | "Bevakning" | Källa | Vad det är |
|---|---|---|---|
| **A** | Per-ärende **Deck-kort** | hubs_arende, saga R5 → `bevakningar()`-projektion | Ett generiskt kort per ärende på enhetens Deck-tavla. Detta är det arbetsledaren ser. |
| **B** | **Fristen** (`register.fristDue`) | hubs_arende, `computeFristDue` | Ett enda deadline-datum på ärende-raden. Bärs av ärendekortet i Hubs Start. |
| **C** | Dashboard-**BevakningarWidget** | **sdkmc** `SummaryService` (OutOfOffice) | **Frånvaro-delegeringar** — vem som täcker för vem vid frånvaro. **Helt orelaterat** till ärende-bevakning. |

Plus en **"Skapa bevakning"-åtgärd** i triage-flödet (som visar sig vara död, se §5).

Att A och C båda heter "Bevakningar" i UI:t är i sig en förvirringskälla — de har inget med
varandra att göra.

---

## 2. Livscykeln idag — händelse för händelse

För **A (Deck-kortet)** och **B (fristen)**, vad gör koden vid varje händelse?

| Livscykel-händelse | Deck-kortet (A) | Fristen (B) | Bevis |
|---|---|---|---|
| **Skapande** (saga R5/R8) | Kort skapas: titel `Ärende {kortRef}`, **due=null**, generisk beskrivning | Sätts *en gång*: 14 dgr (förhandsbedömning) / 30 (månadscykel) / 21 (skyndsam) / **null** om `speglasUrTreserva` | `ArendeService.php:589-602`, `:698` |
| **Steg-övergång** (t.ex. förhandsbedömning→utredning) | **Orört** | Återberäknas **bara om** ärendetypen har `perStegFrist` — **ingen ärendetyp har det** → i praktiken **orört** | `ArendeLifecycleService.php:133-188`; seed i `ArendeTypRegistry.php:170-358` saknar `perStegFrist` |
| **Komplettering kopplas** (kat 4 — den väntade handlingen inkommer) | **Orört** | **Orört** | `kopplaMeddelande()` rör aldrig DeckClient/frist |
| **Commit / registrering** (dnr sätts) | **Orört** | **Orört** — och `speglasUrTreserva`-spegling är **aldrig wirad** (commit-kvittot bär dnr+gallrasDatum, inte frist) | `FacksystemCommitService` |
| **Signering kvitteras** (beslut undertecknat) | **Orört** | **Orört** — `SigneringPort` finns men **anropas aldrig** | grep `requestSignature` = 0 träffar i motorn |
| **Avslut** (steg→avslutat) | **Orört — kortet ligger kvar** | **Orört — fristen ligger kvar** | `transitionera()` gör ingen cleanup |
| **Gallring** (hela ärendet rensas) | **Orört — kortet raderas ALDRIG** (se §3) | (pekar-raden raderas) | `GallringService.php` saknar DeckClient |

**Slutsats:** kortet lever **fullständigt inert** från R5 till gallring, och fristen sätts en gång
och rörs sedan aldrig i praktiken. Ingen händelse som "uppnår det bevakade" rör någondera.

---

## 3. Den allvarligaste bristen: kortet städas inte ens vid gallring

Detta är mer än en saknad feature — det är en **läcka**:

- `GallringService` har **ingen `DeckClient`-referens**. Gallrings-loopen kör
  `pekareMapper->delete($pekare)` för alla pekare (inkl. `deck_card`) — den raderar **DB-raden**
  men anropar **aldrig** `deckClient->deleteCard()`. → Deck-kortet blir kvar på tavlan som
  ett **orphan-kort** som ingen längre kan koppla till ett ärende.
- Kontrast: teamet får en riktig extern rivning (`teamClient->destroyTeam`) i samma loop —
  Deck gjordes aldrig färdig.
- Ironin: **förmågan finns och är wirad** — men bara i **R5-saga-kompensationen** (om skapandet
  fallerar): där kallas `deckClient->deleteCard(...)` korrekt (`ArendeService.php:607-619`).
  Så det enda tillfället ett bevakningskort någonsin försvinner ur Deck är om **ärendet inte
  föds**. Ett ärende som lever hela sitt liv → kortet blir kvar för evigt.

Detta strider direkt mot den redan kända principen "gallring ska riva **kartan**" (jfr
nattanalysens P0-fynd om att gallring river kartan men inte datat — här är det omvänt: kartan
rivs inte alls för Deck).

---

## 4. Real-fall-matrisen: när bevakningen BORDE nollställas × vad koden gör

Grundat i `ARENDETYPER-FLODESANALYS.md §3.1` (som namnger **fem olika** bevaknings-typer per
kategori) + BBIC-livscykeln från mallbiblioteks-arbetet:

| Verkligt fall | När bevakningen SKA nollställas | Vad koden gör idag |
|---|---|---|
| **Förhandsbedömning 14 dgr** | När beslut "inleda/inte inleda" fattas | 14-dgrs-fristen ligger kvar (ingen `perStegFrist`); kortet orört |
| **Väntar på komplettering** (kat 4) | När kompletteringen inkommer och kopplas | `kopplaMeddelande` rör ingenting — väntan syns aldrig, rensas aldrig |
| **Utredning 4 mån** | När utredningen färdigställs / beslut fattas | Ingen 4-mån-frist sätts ens (14-dgrs-värdet från födelsen ligger kvar) |
| **Beslut väntar underskrift** | När signeringskvittens mottagits | Signeringsflödet är inte integrerat alls |
| **Överklagandefrist 3 v** | När fristen passerat / laga kraft | Ingen sådan frist modelleras |
| **Övervägande vård var 6:e mån** | **Recurring** — återställs varje cykel | Ingen recurring-mekanism, ingen schemalagd återställning |
| **Utskrivningsklar / SIP** (kat 5) | När samordningen är klar | `koordinering`-policy ⇒ frist=null; ingen kalender-koppling som stänger |
| **Ärendet avslutas** | Alla bevakningar bort | Kort + frist ligger kvar; kortet syns kvar på tavlan |

**Varenda rad: koden gör inte det som borde ske.**

---

## 5. Djupare orsak — modellen är fel, inte bara oimplementerad

Det handlar inte om en bortglömd `if`-sats. Grundmodellen matchar inte behovet:

1. **Ett generiskt kort per ärende, inte ett per bevakad händelse.** Kravdokumenten
   (`ARENDETYPER-FLODESANALYS.md §3.1`) beskriver flera samtidiga, händelse-specifika bevakningar
   per ärende (14-dgrs, akut boende/avhysning extern frist, domstols-T-3, recurring övervägande,
   utskrivningsklar) plus **T-7/T-3/T-0-påminnelser**. Det som byggts är "ett kort som säger att
   ärendet existerar" + "ett frist-tal". Systemet kan därför inte ens **skilja** en 14-dgrs-bevakning
   (som ska sluta efter beslut) från en recurring 6-mån-bevakning (som ska återställas) från en
   domstols-frist (som speglas ur facksystemet).
2. **`DeckClient` kan inte uttrycka "klar".** Publika metoder är enbart `createCard`, `addLabel`,
   `deleteCard`, `getCard` — **ingen** `updateCard`/`setDue`/`moveColumn`/`markDone`/`archive`.
   Så även om logiken fanns kan kortet inte flyttas till en "Klart"-kolumn eller markeras uppnått.
3. **"Skapa bevakning"-åtgärden är död.** `skapa-bevakning` saknas i `ARENDE_VERB`
   (`api.js:877`) → routas till **sdkmc** `POST /inflode/skapa-bevakning`, som **inte finns**.
   (sdkmc:s "Bevakningar" är frånvaro-delegeringar, punkt C.) Handläggaren kan klicka och får
   toasten "Bevakning skapad" — men inget kort skapas. Ingen väg finns över huvud taget att
   skapa en bevakning efter födelsen, och ingen väg att nollställa en utan att gallra hela ärendet.
4. **Kraven själva är ofullständiga.** `GAP-ANALYSIS.md` GAP-044 ("riv-mekanism dubbelbevakning")
   och GAP-049 ("inget tvingar registrering") är klassade **MAJOR** och säger **VAD** men inte
   **NÄR** (T-0? commit? avslutat?) eller **HUR** (per kategori?). `HUBS-INTERNALS-ARENDEMOTOR.md`
   rad-shape har `retentionState` men **inget `bevakningsState`**. Koden ärver luckan.
5. **Dubbelbevaknings-risken (GAP-044).** För `speglasUrTreserva`-typerna bevakar Treserva samma
   frist parallellt. Två röda datum som kan **divergera** (Treserva förlängs, Hubs reagerar inte)
   → false reassurance.

---

## 6. Omedelbara buggar att åtgärda (oavsett större ombyggnad)

| Prio | Bugg | Fix |
|---|---|---|
| **P1** | Orphan Deck-kort vid **gallring OCH avslut** (`GallringService` kallar aldrig `deleteCard`; avslut städar inget) | Injicera `DeckClient` i `GallringService` och kalla `deleteCard` för `deck_card`-pekarna (mönstret finns i R5-kompensationen); städa även vid steg→avslutat |
| **P1** | **"Skapa bevakning" är död** — visar "skapad" utan effekt | Antingen ta bort åtgärden, eller lägg `skapa-bevakning` i `ARENDE_VERB` + ett riktigt OCS-endpoint i motorn |
| **P2** | Deck-kortets `due=null` — kortet visar aldrig fristen trots att dess syfte antyds vara frist-bärande | Om Deck ska bära frist: sätt `due` vid R8 och uppdatera vid recompute (kräver `updateCard`) |
| **P2** | Namnkollision "Bevakningar" (frånvaro vs ärende-Deck-kort) | Döp om ettdera i UI (t.ex. "Frånvaro & delegering" vs "Ärendebevakningar") |

---

## 7. Vad som krävs för att göra det rätt (om ni vill bygga bevaknings-livscykeln)

1. **Datamodell:** en per-bevakning-post (inte ett register-fält) med `typ`, `bevakatVillkor`,
   `status` (aktiv / uppnådd / passerad), `recurringCykel`, `ankare`. En bevakning kan då
   nollställas oberoende av andra på samma ärende.
2. **Villkorsutvärdering på händelser:** varje livscykel-händelse (steg, komplettering-koppling,
   commit, signering, avslut) triggar "uppnåddes någon bevaknings villkor? → markera uppnådd +
   flytta/stäng Deck-kortet".
3. **`DeckClient`-utökning:** `updateCard` (due/kolumn/done) — och koppla gallring till `deleteCard`
   (stänger orphan-läckan direkt, P1 ovan).
4. **Recurring:** schemalagd återställning (BackgroundJob) för övervägande-cykeln (var 6:e mån).
5. **Frist-recompute på steg:** fyll `perStegFrist` i ärendetyperna (då fungerar den redan
   byggda `maybeRecomputeFristDue`), eller inför per-händelse-frist-modell.
6. **Lös dubbelbevakningen (GAP-044):** bestäm ägaren — Hubs bevakar tills registrering, sedan
   lämnar över till Treserva (eller tvärtom) — och riv Hubs-bevakningen vid överlämning.
7. **Stäng kravluckan först:** GAP-044/GAP-049 behöver preciseras (NÄR + HUR per kategori) innan
   bygget, annars ärver koden samma otydlighet igen.

---

## Källor
Kodfynd verifierade i `hubs_arende/lib/Service/{ArendeService,ArendeLifecycleService,GallringService,
ArendeTypRegistry}.php`, `Integration/Client/DeckClient.php`, `hubs_start/src/services/api.js`,
`backend-additions/sdkmc/lib/Service/SummaryService.php`. Kravgrund i
`hubs_start/docs/{ARENDETYPER-FLODESANALYS,GAP-ANALYSIS,HUBS-KRAVSTALLNING-TOTAL,HUBS-INTERNALS-ARENDEMOTOR}.md`.
