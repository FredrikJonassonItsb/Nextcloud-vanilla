<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Daglig användning — Överförmyndarhandläggare (`overformyndare`)

> **Vad detta dokument är:** den *verkliga* dygnsrytmen för EN persona — överförmyndarhandläggaren — och exakt hur Hubs + dashboarden faktiskt används timme för timme, widget för widget. Komplement till `persona-overformyndare.md` (som äger persona-beskrivning, KPI:er, terminologi och widget-katalog). Här ligger fokus på **flödet i tiden** och på **mellanlagrings-modellen gjord explicit per widget**.
>
> **Arkitektonisk ram (kundens egen, bär hela dokumentet):** Hubs är **MIDDLEWARE / mellanlagring**. Slutlagring (system of record) för denna persona är alltid **Provisum (Sambruk/Flowfactory)** eller **Aider** — med **Mitt Wärna** (f.d. e-Wärna Go) som ställföreträdarens inrapporterings-e-tjänst som matar Provisum. Hubs stagar säker kommunikation, e-underskrift, möten och verifikat per uppdrag/huvudman — sedan **för handläggaren över utfallet (granskningsbeslut, arvodesbeslut, anmärkning) in i facksystemet**. Hubs blir aldrig redovisningsregistret.
>
> **Brand-regel:** i produkt-/UI-text aldrig "Nextcloud"/"Talk". I detta interna underlag namnger vi apparna (sdkmc, securemail, spreed-itsl, Groupfolders, Deck/Tasks, Tables, LibreSign, Calendar, Forms, Collectives, Retention, llm2, stt_whisper2) för att kunna wire:a.
>
> **Persona-id:** `overformyndare` · **Datum:** 2026-06-13 · **Plattform:** server v32 (Hub 25 Autumn) · **System of record:** Provisum / Aider (+ Mitt Wärna-inrapportering).

---

## En dag i arbetet (08:00 → 17:00, kronologiskt, konkret)

**Scen:** överförmyndarenhet i kommungemensam nämnd (Överförmyndare i samverkan, ÖiS). Datum i scenariot: **torsdag 19 februari 2026** — mitt i inrushningen mot **1 mars**. Handläggaren ("Lena") har ~180 egna uppdrag i sin granskningsportfölj av enhetens ~540 inkomna årsräkningar för räkenskapsåret 2025. Facksystemet är **Provisum**; ställföreträdarna lämnar i **Mitt Wärna**.

**08:00 — Inloggning och lägesbild.**
Lena loggar in med **BankID (LOA3)**. Hubs Start öppnar i hennes rollvy. Överst: **`arsrakningar`-kampanjremsan** — *"312 av 540 granskade · 10 dagar till 1 mars · 47 saknar verifikat"* — och `bevakningar`-stripen *"4 frister förfaller denna vecka"*. Hon läser av: ingen röd (förfallen) frist, två gula (≤3 dgr). Tom-tillstånd där det är tomt = inget missat. *(Provenance redan här: kampanjsiffrorna speglas från Provisums granskningsstatus; Hubs äger inte siffran, den visar den.)*

**08:10 — Triage av nattens inflöde (`funktionsbrevlador` + `attHantera`).**
Funktionsbrevlådan `overformyndare@kommunen` har 9 nya säkra meddelanden: 3 ställföreträdare som svarat på kompletteringsbegäran (verifikat bifogade), 1 bank som org-till-org via **SDK** bekräftar ett uttag från spärrat konto, 2 nya frågor från gode män, 1 inskannad pappersårsräkning (digital fax-in), 1 läkarintyg om en huvudmans tillstånd (godmanskapsutredning, HSLF-FS 2016:40-känsligt), 1 felskickat. Hon **plockar** de tre kompletteringssvaren till sig och **fördelar** två frågor till kollega. Det felskickade markeras och loggas.

**08:30 — Tre kompletteringssvar stänger loopar (`skickatForSignering` → `granskningsko`).**
De tre svaren matchar öppna bevakningar. Hon öppnar varje verifikat i **ärenderummet** (`arenderum`), kontrollerar mot den saknade posten, och eftersom redovisningen nu är komplett flyttas ärendet i `granskningsko` från *Väntar på komplettering* → *Klar för granskning*. Två av dem hinner hon granska direkt.

**08:45–10:30 — Granskningspass (`granskningsko` → `arenderum`).**
Hon trycker **"Granska nästa årsräkning"**. Kön plockar nästa otilldelade/tilldelade post; ärenderummet öppnar **årsräkning + verifikat sida vid sida** (samredigering on-prem). Hon kör JO-rimlighetskontrollen: stämmer ingående/utgående saldo, avser utgifterna huvudmannen, är de till hens nytta? Tre utfall under passet:
- 4 årsräkningar **utan anmärkning** → markeras granskade; arvode beräknas; **arvodesbeslut** läggs i `attSignera`-kön.
- 2 **saknar verifikat** → **"Begär komplettering"**: förifyllt säkert meddelande till ställföreträdaren (vilken post, vilket belopp) med **läskvittens**; en **bevakning** skapas automatiskt (svar inom 14 dgr, påminnelse T-7/T-3). Ärendet → *Väntar på komplettering*.
- 1 med **misstänkt orimlig post** (stor kontantuttag utan kvitto) → flaggas för **anmärkning** + fördjupad granskning; bevakning sätts.

**10:30 — Förtursflagga och uppdragskontroll (`uppdragskontroll`).**
Kampanjvyns förtursfilter lyfter en **förstagångsredovisare** och en **tidigare anmärkt** ställföreträdare. `uppdragskontroll` flaggar dessutom en god man som nu har **ovanligt många uppdrag** (JO-kravet, dec 2025) — Lena noterar att hens nästa årsräkning ska stickprovsgranskas djupare.

**11:00 — Säkert möte om bostadsförsäljning (`dagensMoten` / spreed-itsl).**
En god man har begärt enhetens samtycke till **försäljning av huvudmannens bostadsrätt**. Bokat **säkert videomöte** kl 11:00; ställföreträdaren ansluter via **BankID-lobby** (väntrum, LOA3 verifierad). De går igenom värderingsunderlag som ligger i ärenderummet. *(Möte = transit; inget beslut "bor" i mötet.)* Lena startar **lokal transkribering** (KB-Whisper via `stt_whisper2`) som *utkast* till tjänsteanteckning — human-in-the-loop, hon godkänner texten efteråt.

**11:45 — E-underskrift av morgonens beslut (`attSignera`).**
Signeringskön: **4 arvodesbeslut** (lågrisk → SKR:s riskmodell tillåter **"Godkänn"**, loggat) och **1 samtyckesbeslut om bostadsförsäljning** (myndighetsbeslut → **AES via BankID**). Hon granskar och signerar; varje resultat blir **PAdES/PDF/A + valideringsintyg**, arkiveras i respektive ärenderum.

**12:00 — Lunch.** Köerna står stilla; inga aviseringar pushas under lunch utom röd frist.

**13:00 — Beslut till bank + delgivning (`attHantera` SDK + `kvittenser`).**
Samtyckesbeslutet om bostadsförsäljning **delges** den gode mannen via säker kanal; en separat underrättelse går **org-till-org via SDK till banken** (spärrat konto) med leveranskvittens. `kvittenser` visar tidslinjen *Skickad → Levererad → Öppnad → Läst*. Laga-kraft-frist sätts som bevakning.

**13:30 — Överför morgonens utfall till Provisum (commit till slutlagring).**
Detta är **mellanlagrings-brytpunkten**. För var och en av de 4 granskade + arvoderade och det 1 samtyckesbeslutet **för Lena över utfallet till Provisum-ärendet**: granskningsresultat (u.a./anmärkning), arvodesbeslut, det signerade PDF/A-dokumentet som referens. I `arenderum`/`granskningsko` ändras destinationsstatus *ej överförd* → **"Förd till Provisum 2026-02-19"**, och Hubs-kopians **rensningscountdown** startar. *(Dag-1-läge: manuell handoff, mönster D; storkund med Provisum-API: mönster A/B.)*

**14:00–15:30 — Nytt godmanskap i samförstånd (förbereder reformen 1 juli 2026).**
En anmälan om behov av god man har kommit in. Hon öppnar **ärenderum** för det blivande uppdraget, hämtar in **läkarintyg** (HSLF-FS-medveten kanal) och samtycken, och matchar lämplig ställföreträdare — `uppdragskontroll` flaggar om kandidaten redan är överbelastad. Bokar ett **säkert samförståndsmöte** med huvudman/anhörig. *(Efter 1 juli 2026 fattar överförmyndaren själv beslutet i samförståndsärenden i stället för tingsrätten → mer myndighetsutövning, fler beslut att skriva/underteckna/delge här.)*

**15:30 — Påminnelser och eftersläntrare (`skickatForSignering` + `bevakningar`).**
Hon filtrerar utskickade kompletteringsbegäranden: 3 är *Öppnade men ej besvarade* med <3 dgr kvar → **Påminn**-knapp (ny säker påminnelse + bevakningen förlängs inte, fristen står kvar). 1 ställföreträdare har inte öppnat alls på 8 dagar → eskaleras.

**16:00 — Pappersårsräkningen (migreringsbryggan).**
Den inskannade pappersårsräkningen från morgonen registreras i `granskningsko` med **källkanal-ikon "papper"**. Lagen låter ställföreträdaren välja redovisningsform → Hubs samlar pappers- och digitala kanaler i *samma* kö. Hon granskar; begär ett saknat kontoutdrag per säker kanal (eller post om mottagaren saknar e-leg, via ombud).

**16:45 — Dagsavslut.**
Snabb koll: kampanjremsan står nu på *"319 av 540 · 10 dagar till 1 mars"*; `bevakningar` har inga röda; `attSignera` är tom (allt signerat); `attHantera` har 0 ohanterade > 1 dag. **Tom kö = inget missat = compliance-värde.** Hon loggar ut; BankID-sessionen avslutas.

**Utanför toppen (höst/vinter):** mindre volym, mer dialog och fler enskilda beslut — tillsynsärenden, uttag från spärrat konto, byte av ställföreträdare, nya godmanskap (efter reformen fler beslut hos enheten), ställföreträdarrekrytering. Samma widgetar, men `granskningsko`/`arsrakningar` är lugna och `attHantera`/`attSignera`/`dagensMoten` dominerar.

---

## Hur Hubs + dashboarden faktiskt används (öppningsordning & åtgärder)

Den verkliga interaktionsordningen — inte widget-katalogens prioritetsordning, utan *handgreppen*:

| Tidpunkt | Widget som öppnas | Konkret åtgärd | Resultat & nästa widget |
|---|---|---|---|
| 08:00 | `arsrakningar` (kampanjremsa) + `bevakningar` (strip) | **Läs** lägesbilden (X av Y, dagar till 1 mars, röda frister) | Ingen åtgärd — orientering. Styr var dagens kraft läggs. |
| 08:10 | `funktionsbrevlador` → `attHantera` | **Plocka/fördela** inkommande; kanalklassning (SDK/säker e-post/fax) | Kompletteringssvar → matchar bevakningar; frågor fördelas |
| 08:30 | `skickatForSignering` + `arenderum` | **Öppna verifikat**, bekräfta komplett | Ärende i `granskningsko`: *Väntar* → *Klar för granskning* |
| 08:45 | `granskningsko` → `arenderum` | **"Granska nästa"**; JO-rimlighetskontroll side-by-side | u.a. → arvode till `attSignera`; brist → `Begär komplettering` |
| ⤷ vid brist | `attHantera` (utkanal) + `bevakningar` | **"Begär komplettering"** (förifyllt säkert meddelande + läskvittens) | Auto-skapad bevakning T-7/T-3; ärende → *Väntar på komplettering* |
| 10:30 | `uppdragskontroll` + `arsrakningar` (förtur) | **Granska flaggor**: många uppdrag / tidigare anmärkt / förstagångsredovisare | Markerar djupare stickprov; matar tillsyn |
| 11:00 | `dagensMoten` (spreed-itsl) | **Anslut** säkert möte; BankID-lobby; ev. lokal transkribering (utkast) | Tjänsteanteckning-utkast → godkänns → `arenderum` |
| 11:45 | `attSignera` | **Granska & signera/godkänn** (AES för myndighetsbeslut, "Godkänn" lågrisk) | PAdES/PDF/A + valideringsintyg → `arenderum` |
| 13:00 | `attHantera` (SDK) + `kvittenser` | **Delge** ställföreträdare; **org-till-org-besked till bank**; sätt laga-kraft-bevakning | Leveranstidslinje i `kvittenser` |
| **13:30** | `granskningsko` / `arenderum` (destinationsstatus) | **"För över till Provisum"** (commit till slutlagring) | *ej överförd* → **Förd till Provisum**; rensningscountdown startar |
| 14:00 | `arenderum` (nytt) + `dagensMoten` + `uppdragskontroll` | **Öppna ärenderum** för nytt godmanskap; hämta läkarintyg; matcha ställföreträdare; boka möte | Bevakning för utredningstid |
| 15:30 | `skickatForSignering` + `bevakningar` | **Påminn** ej besvarade; **eskalera** ej öppnade | Säker påminnelse skickad; bevakning kvar |
| 16:00 | `granskningsko` (papperskanal) | **Registrera** inskannad pappersårsräkning; granska | Migreringsbryggan: papper + digitalt i samma kö |
| 16:45 | `arsrakningar` + `bevakningar` + `attSignera` + `attHantera` | **Avslutskoll**: tomma köer? röda frister? | Tom kö = inget missat |

**Sidokolumn-widgetar** (`arenderum`, `kvittenser`, `kunskapsbank`, `nytta`) öppnas *reaktivt* — `kunskapsbank` när hon behöver en granskningsmall/gallringsplan/kompletteringsmall; `nytta` aldrig dagligen, bara inför chef/nämnd. `Ctrl/Cmd+K` används av expertanvändaren i toppsäsong för "Granska nästa", "Begär komplettering", "Sök huvudman/dnr", "Boka möte".

---

## Widget → app → system-of-record-karta (mellanlagring gjord explicit)

För **varje widget i `overformyndare`-layouten** (main: `arsrakningar` · `granskningsko` · `attSignera` · `skickatForSignering` · `bevakningar`; side: `funktionsbrevlador` · `arenderum` · `dagensMoten` · `uppdragskontroll` · `kvittenser` · `kunskapsbank` · `nytta`): vilken app driver den, varifrån data kommer, och **var resultatet slutlagras**. Modellen: *Hubs stagar X → handläggaren för över till {system}*.

| Widget | NC-app / funktion (mellanlagring) | Data IN (varifrån) | Slutlagring (system of record) | Mellanlagrings-mening |
|---|---|---|---|---|
| **`arsrakningar`** (kampanjvy) | Deck/Tasks (kampanj-rendering) + **Tables** (statusspegel); *läser* Provisum-status | Granskningsstatus & frister från **Provisum/Aider**; deadline 1 mars (FB 14:15) | **Provisum/Aider** | *Hubs stagar progress-/fristöversikten → den arkivpliktiga granskningsstatusen bor i Provisum.* Hubs renderar siffran mänskligt, äger den inte. |
| **`granskningsko`** | Deck (plockbar delad kö) + `arenderum` | Inkomna årsräkningar via **Mitt Wärna** (e-tjänst→Provisum), inskannat papper (fax/`files`), post | **Provisum/Aider** (granskningsresultat, anmärkning) | *Hubs stagar plock-/granskningsarbetsytan → handläggaren för över granskningsresultatet till Provisum-ärendet.* |
| **`attSignera`** | **LibreSign** (demo/internt "Godkänn") / **Inera Underskriftstjänst-API el. Sweden Connect-nod** (prod, AES) | Beslutsutkast skapat i ärenderummet (arvode/uttag/tillsyn/samtycke) | **Provisum/Aider** (beslutet) + ärenderum (PDF/A-referens) | *Hubs stagar signeringssteget → den signerade PAdES/PDF/A-handlingen + valideringsintyg förs till Provisum-ärendet; bevisvärdet bevaras.* |
| **`skickatForSignering`** | securemail/sdkmc + LibreSign (statuskedja/kvittens) | Utskickade beslut & kompletteringsbegäranden | **Provisum** (när besvarat/signerat committas) | *Hubs stagar spårningen Skickat→Öppnat→Besvarat/Signerat → utfallet committas, raden stängs "Förd till Provisum".* |
| **`bevakningar`** | **Deck/Tasks** (kanban/VTODO som datalager); Hubs påminnelse-logik T-7/T-3/T-0 | Frister härledda ur lag/ärende (1 mars, 7-mån, FL 6-mån, laga kraft) | **Provisum** äger den *formella* fristbevakningen | *Hubs stagar den personliga/operativa bevakningen runt det säkra flödet → den formella, arkivpliktiga bevakningen bor i Provisum. Dubblera inte facksystemet; gallra Hubs-noten efter överföring.* |
| **`funktionsbrevlador`** | **sdkmc** funktionsadress-stöd (`overformyndare@`) | Allt inkommande (SDK org-till-org, säker e-post, digital fax) | Per ärende → **Provisum**; ingen registreras i Hubs som slutlager | *Hubs stagar delad triage ("vem tar detta") → varje plockat ärende förs vidare till Provisum-ärendet.* Behörighet = säkerhetsgräns (OSL). |
| **`arenderum`** | **Groupfolders + ACL + versioner + Retention + Collabora/OnlyOffice** | Verifikat (Mitt Wärna/skannat), läkarintyg, beslutsutkast, mötestranskript | **Provisum**-ärendet; bestående original committas dit | *Hubs stagar dokumentytan per uppdrag/huvudman → bestående handling förs till Provisum; ärenderummet visar DUBBEL retention: Provisums bevarande + Hubs egen rensning.* |
| **`dagensMoten`** | **calendar (Appointments) + spreed-itsl** (auto-videorum, BankID-lobby) | Bokning av ställföreträdare/handläggare; kallelse | Tjänsteanteckning → **Provisum**; mötet äger inget rekord | *Hubs stagar det säkra mötet + ev. lokal transkript-utkast → godkänd anteckning förs till Provisum; rå-inspelning gallras.* |
| **`uppdragskontroll`** | **Tables** (regelmotor) + *läser* facksystemets uppdragsdata | Uppdragsantal/anmärkningshistorik från **Provisum/Aider** | **Provisum** (tillsynsbeslut/notering) | *Hubs stagar JO-flaggningen (många uppdrag/upprepade anmärkningar) → tillsynsåtgärden dokumenteras i Provisum.* Operationaliserar JO dec 2025. |
| **`kvittenser`** | **sdkmc** receipt-data | Utgående delgivningar/besked (ställföreträdare, bank) | Leveranskvitto → **Provisum** som händelse | *Hubs stagar leveranstidslinjen (ersätter "ringa och kolla att posten kom fram") → kvittensen loggas i Provisum-ärendet.* |
| **`kunskapsbank`** | **Collectives** (wiki on-prem) | Granskningsrutiner, gallringsplan, kompletterings-/beslutsmallar | — (referensmaterial, ej ärendedata) | *Statiskt stöd; minskar kognitiv börda. Låst utanför det konfigurerbara skalet (WCAG 3.2.6).* |
| **`nytta`** | **Tables** (register matat av Hubs-händelser) | Ersatta brev/fax, digital vs papper, sparad tid (~30 min/ärende) | Underlag → nämnd/cybermiljards-äskande (rapport, ej ärende) | *Hubs aggregerar migrerings-/ROI-bevis → levereras som rapport uppåt; ingen huvudmansdata.* |

**Genomgående UI-konsekvens (provenance-band per ärenderad):** *"Inkom via Mitt Wärna 2026-02-12 · granskning pågår · beslut journalförs i Provisum"* → efter handoff: *"Förd till Provisum 2026-02-19 · Hubs-rum rensas 30 dgr efter överföring."* En grön "Förd till Provisum"-markör är compliance-värde (inget arkivpliktigt fastnar i mellanlagringen).

---

## Typiska arbetsmönster & återkommande flöden (end-to-end)

Varje flöde märker ut **var data tas emot** och **var den slutlagras**.

### Flöde 1 — Granska årsräkning → komplettering → arvodesbeslut → delgivning (toppen mot 1 mars)
1. **Mottag:** ställföreträdaren lämnar årsräkning + verifikat i **Mitt Wärna** (→ Provisum) **eller** på papper (skannas in i `arenderum`). Visas i `granskningsko` med källkanal-ikon; förtursflagga för förstagångsredovisare/tidigare anmärkta.
2. **Bearbeta:** `granskningsko` → **"Granska nästa"** → `arenderum` öppnar årsräkning + verifikat side-by-side. JO-rimlighetskontroll.
3. **Brist:** saknade verifikat → **"Begär komplettering"** (säkert meddelande + läskvittens via sdkmc) → auto-bevakning (`bevakningar`) med svarsfrist + T-7/T-3-påminnelser. `skickatForSignering` visar *Skickat→Öppnat→Besvarat*.
4. **Beslut:** komplett → arvodesbeslut i `attSignera` (lågrisk → "Godkänn" loggat; annars AES/BankID) → **PAdES/PDF/A + valideringsintyg** i `arenderum`.
5. **Delge & commit:** beslutet delges ställföreträdaren säkert (`kvittenser` = levererat→läst); laga-kraft-frist som bevakning → **för över granskningsresultat + arvodesbeslut till Provisum**; `nytta` räknar upp ett ersatt brev.
   **Slutlagring:** **Provisum/Aider** (granskning, arvode, anmärkning). Hubs-rummet rensas efter bekräftad överföring.

### Flöde 2 — Uttag från spärrat konto → samtycke/beslut → e-underskrift → besked till bank (året runt)
1. **Mottag:** ställföreträdaren ansöker (säkert meddelande/`attHantera` eller Mitt Wärna) om uttag för en utgift, bifogar underlag → blir **bevakning** med frist (FL: enkelt, snabbt, rättssäkert).
2. **Bered:** prövning i `arenderum`; vid behov **säkert möte** (`dagensMoten`, auto-videorum) för att reda ut underlaget.
3. **Beslut:** samtycke/avslag **e-underskrivs** i `attSignera` (AES/BankID).
4. **Verkställ:** besked till banken **org-till-org via SDK** (`attHantera`) med kvittens (`kvittenser`) — ersätter fax/post.
5. **Commit:** beslut + bankkvittens **förs över till Provisum-ärendet**; bevakning stängs vid bankens bekräftelse.
   **Mottag från:** ställföreträdare (BankID) + bank (SDK). **Slutlagring:** **Provisum/Aider** + bankens egen post.

### Flöde 3 — Nytt godmanskap i samförstånd (efter reform 1 juli 2026, prop. 2025/26:92) → utredning → beslut → registrering
1. **Mottag:** ansökan/anmälan inkommer (säkert meddelande/e-tjänst). **Reformen flyttar samförståndsbeslutet från tingsrätten till överförmyndaren** → mer myndighetsutövning här. `arenderum` öppnas för blivande uppdrag + utrednings-`bevakning`.
2. **Underlag:** **läkarintyg** om huvudmannens tillstånd + samtycken hämtas säkert (HSLF-FS 2016:40-medvetet, kryptering + LOA3). Lämplig ställföreträdare matchas; `uppdragskontroll` flaggar om kandidaten redan har många uppdrag (JO dec 2025).
3. **Samtal:** **säkert samförståndsmöte** (`dagensMoten`) med huvudman/anhörig/ställföreträdare; ev. lokal transkript-utkast.
4. **Beslut:** överförmyndaren fattar och **e-underskriver** beslutet om godmanskap (`attSignera`, AES) → delges parterna säkert.
5. **Registrering/commit:** uppgifter **förs till Provisum** och (från **2028**) det **nationella registret** (prop. 2025/26:92); bevakning sätts för första årsräkning.
   **Mottag från:** anmälare/huvudman/anhörig (BankID), vård (läkarintyg). **Slutlagring:** **Provisum/Aider** → nationellt register (2028).

### Flöde 4 — Tillsyn & uppdragskontroll (JO-driven, löpande)
1. **Mottag:** signal från `uppdragskontroll` (ovanligt många uppdrag / upprepade anmärkningar) eller Provisums stickprovsurval för fördjupad granskning.
2. **Bearbeta:** fördjupad granskning i `arenderum`; säker dialog/komplettering med ställföreträdaren; ev. **byte av ställföreträdare** bereds.
3. **Beslut:** tillsynsbeslut/anmärkning **e-underskrivs** (`attSignera`); delges säkert (`kvittenser`).
4. **Commit:** tillsynsutfallet **förs över till Provisum**; vid byte uppdateras uppdragsregistret där.
   **Mottag från:** facksystemets stickprov + Hubs-regelmotor. **Slutlagring:** **Provisum/Aider** (tillsynsnotering/beslut).

---

## Saknade funktioner för denna persona — och hur de byggs/wire:as

Fem konkreta luckor, rangordnade efter värde för just överförmyndarhandläggaren, var och en med bygg-/wire-väg och **mellanlagrings-respekt** (committa till Provisum, gallra i Hubs).

### 1. Provisum/Aider-läsintegration så kampanjvyn är SANN (högst värde)
**Lucka:** `arsrakningar`/`granskningsko` är bara trovärdiga om "312 av 540", fristerna och ställföreträdar-statusen speglar **Provisum** på riktigt. Idag är de demo-/manuella.
**Bygg/wire:** tunn **läskonnektor** mot Provisum (Sambruk/Flowfactory) och Aider — primärt **mönster A (REST, Ena REST-API-profil)** där API finns, annars **halvautomatisk spegling i Tables** (import/CSV) som backend för kampanj-widgeten. Skrivriktningen (commit av granskningsresultat/beslut) går samma väg men separat och alltid handläggar-initierad. Standardisera mot Ena-profilen, inte per kund. *Mellanlagring: Hubs läser status, äger den inte; beslutet förs tillbaka till Provisum.*

### 2. Lagstadgad todo-/bevakningslista som inte dubblerar facksystemets bevakning
**Lucka:** handläggaren behöver "vad måste jag göra och när" *för det säkra flödet* (komplettering ute, svar in, delgivning kvar, laga-kraft-frist) — men Provisum äger redan den *formella* fristbevakningen. Risk: två konkurrerande, oarkiverade fristlistor.
**Bygg/wire:** `bevakningar` (delad, **Deck**) + `minaUppgifter` (personlig, **Tasks/VTODO** med native VALARM) med Hubs egen **påminnelse-före-deadline T-7/T-3/T-0 bara till tilldelad** (täcker Deck #1549/#566) och **WCAG 2.5.7-knappalternativ till drag**. **Signaturfunktion: "Skapa bevakning från meddelande"** — varje inkommande säkert meddelande får en knapp som förifyller titel + frist (t.ex. 14 dgr) + ärendereferens. **Arkivmedvetenhet vid klarmarkering:** val "gallra (personlig not)" vs **"för till Provisum-ärendet"** — håller isär gallringsbara arbetsnoteringar från arkivpliktiga handlingar (arkivlagen 1990:782 / OSL). *Mellanlagring explicit: Hubs fångar uppgiften innan den blir ett formellt ärende; den formella fristen + journalen bor i Provisum.*

### 3. Mötestranskribering + lokal AI-sammanfattning av ställföreträdar-/samförståndssamtal
**Lucka:** samförståndssamtal, bostadsförsäljnings- och rekryteringsmöten dokumenteras manuellt i efterhand.
**Bygg/wire:** **recording server + HPB** (spreed-itsl) → **`stt_whisper2` med KB-Whisper** (KBLab, Apache-2.0, svensk-tränad, slår whisper-large-v3 på svenska) → **`llm2`/Assistant** (grön-ratad, lokal) producerar **utkast**: sammanfattning + beslut + att-göra-lista. **Human-in-the-loop obligatoriskt** — handläggaren redigerar och **"Godkänn"** (loggad händelse) → **"Spara till ärende"** committar *bara den godkända texten* till **Provisum**; rå-WebM + rå-transkript får **kort Retention-gallring** i `arenderum`. `recording_consent` påtvingat. *Skarp körning först på minst känsliga möten (rekrytering/intern beredning); klient-/huvudmanssamtal villkoras av IMY/SKR-vägledning. Mellanlagring: Hubs producerar utkastet, gallrar råspåret, committar godkänd anteckning.*

### 4. Produktions-e-underskrift med riktig BankID/Freja-AES (inte bara LibreSign-konto/SMS)
**Lucka:** LibreSign signerar mot egen självsignerad rot (konto/SMS) → **inte** svensk myndighets-AES. Räcker för internt "Godkänn", inte för arvodes-/samtyckes-/tillsynsbeslut som delges externt.
**Bygg/wire:** **signeringsadapter** med två backends bakom samma `attSignera`-kö: LibreSign (demo/internt, ärligt etiketterat "konto/SMS, ej BankID") **+ Inera Underskriftstjänst-API** (mTLS + SITHS funktionscert, BankID/Freja/SITHS, PAdES + PDF/A-1) **eller egen Sweden Connect-nod** (Digg open source). Lägg på **LTV + kvalificerad tidsstämpel** och bygg bevarandepanelen **"Giltig nu / Giltig då"** (verifiera underskrift efter cert-utgång) — gapet ingen konkurrent säljer tydligt. *Mellanlagring: signeringen iscensätts i Hubs; den signerade PDF/A-handlingen + valideringsintyg committas till Provisum.*

### 5. Säker ställföreträdar-/huvudmandelning utan konto + utkanal för besked
**Lucka:** ställföreträdare/huvudmän utan Hubs-konto behöver kunna ta emot beslut/begäran och ladda upp verifikat säkert; ~5–10 % saknar e-leg helt (ombud).
**Bygg/wire:** **BankID/Freja-skyddad delning av utvalda dokument** ur `arenderum` (aldrig hela rummet; inget konto krävs) + **ombudsläge** (anhörigbehörighet) för dem utan e-leg. På sikt **utkanal till digital brevlåda** (Mina meddelanden/Kivra, SOU 2024:47) för enkelriktade besked — men dialogen ägs av Hubs. *Mellanlagring: Hubs är den säkra tvåvägsytan; det bestående beslutet förs ändå till Provisum + delges via kvitterad kanal.*

---

## Källor (utöver grundanalyserna i `analysis-output/` och `analysis-output/extended/`)

- **Reform (prop. 2025/26:92 "Ett ställföreträdarskap att lita på")** — samförståndsbeslut flyttas tingsrätt→överförmyndare, MFoF tillsynsvägledning, nationellt register + obligatorisk utbildning; ikraft merparten 1 juli 2026, utbildningskrav senast 31 dec 2028, register 2028: https://www.regeringen.se/pressmeddelanden/2025/11/ett-stallforetradarskap-att-lita-pa--reform-for-okad-trygghet-och-sjalvbestammande/ · https://www.riksdagen.se/sv/dokument-och-lagar/dokument/proposition/ett-stallforetradarskap-att-lita-pa_hd0392/html/ · SKR kommande lagändringar: https://skr.se/juridik/overformyndarjuridik/kommandelagandringarforoverformyndare.10060.html
- **Mitt Wärna (f.d. e-Wärna Go) — digital årsräkning, deadline 1 mars 2026, papper som alternativ:** https://www.linkoping.se/omsorg-och-hjalp/ekonomi-stod-och-radgivning/god-man-forvaltare-och-formyndare/e-warna-go/ · https://varberg.se/overformyndare-i-vast/aktuellt/overformyndare-i-vast---nyheter/2026-01-29-arsrakningstider
- **Provisum (Sambruk/Flowfactory) — granskning, stickprovsurval för fördjupad granskning, bevakning, ställföreträdar-e-tjänst:** https://www.provisum.se/ · https://sambruk.se/overformyndare-provisum/
- **Aider — överförmyndare/tillsyn (2025):** https://support.aider.nu/sv/articles/6884612-overformyndare-och-aider
- **JO — rimlighetsbedömning av årsräkningar + kontroll av antal uppdrag (dec 2025):** https://www.jo.se/
- **Föräldrabalken 14:15 (årsräkning före 1 mars) + Förvaltningslagen 2017:900 §§11–12 (frister/dröjsmål):** https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/forvaltningslag-2017900_sfs-2017-900/
- **Interna underlag (samma analyspaket):** `persona-overformyndare.md`, `middleware-architecture.md`, `arendehantering-map.md`, `native-apps-map.md`, `transcription-ai.md`, `esign-todo-native.md`, `hubs_start/src/services/personaConfig.js`, `hubs_start/docs/PERSONA-DASHBOARD-SPEC.md`.
</content>
</invoke>
