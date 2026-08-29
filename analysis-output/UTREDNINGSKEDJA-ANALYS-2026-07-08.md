# Utredningskedjan i Hubs — följer vi hela kedjan, eller bara etiketterna?

**Datum:** 2026-07-08
**Fråga (Fredrik):** *"Gör en detaljerad analys kring hur vi följer hela utredningskedjan. Jag har känslan att vissa steg hoppas över utan att vi gör det som är avsett i steget."*
**Metod:** 21 agenter i två workflow-svep — 6 parallella kartläggare (intended vs faktiskt vs handläggarens verkliga förmåga) → strukturerad syntes; sedan 8 adversariella skeptiker (refutera varje GAP mot koden) + 5 designspår → prioriterad plan. ~2,1 M tokens kodläsning.

---

## 1. Kärntes

> **Hubs följer utredningskedjan i FORM men inte i INNEHÅLL.**

Motorns tillståndsmaskin (`ArendeLifecycleService::transitionera`, `ALLOWED_TRANSITIONS` rad 53–60) garanterar **ordning** — du kan inte hoppa över en nod i grafen — men **ingen nod kräver att stegets avsedda arbete producerats**. Det finns exakt **EN** innehållsgrind i hela systemet (plikt-grinden `forhandsbedomning→utredning`), och den kontrollerar bara en **klient-satt boolean**, aldrig att en skyddsbedömning faktiskt existerar.

Frontend är genomgående **obunden**: alla tunga åtgärder (commit/"För till Treserva", signering, alla 18 mallar) ligger öppna i varje steg; grindarna (Avsluta/Commit) är **bekräftelserutor**, inte innehållskontroller; steppern spärrar bara framåt-*navigering*, inte själva stegbytet.

**Svar på din känsla: ja, den stämmer.** Ett ärende kan i praktiken avancera hela kedjan `förhandsbedömning → utredning → beslut → uppföljning` med ett API-anrop per steg **utan att någonting utrednings­mässigt producerats**. Ett tomt utredningssteg lämnar exakt samma spår (två journalrader) som ett fullständigt.

---

## 2. Per-steg-matris: avsett vs faktiskt

| Steg | Avsett innehåll (definition of done) | Vad backend TVINGAR | Vad handläggaren kan i GUI | Bevis som produceras | GAP |
|---|---|---|---|---|---|
| **Inflöde** | Anmälan dokumenteras (inkomstdatum+tid, källa, oro), 14-dagarsklockan startar, syskonregel | Inget — case-typer *föds* direkt i `forhandsbedomning` (`fodelseSteg`), inflöde passeras aldrig | Bara "Starta ärende" | `skapad`-händelse | Inflöde finns knappt som processteg; mottagningsarbetet sker implicit i skapandeklicket |
| **Förhandsbedömning** (14 d) | (1) omedelbar skyddsbedömning **samma dag** m. motivering, (2) beslut inleda/inte inleda **inom 14 d** m. motivering | Plikt-grind PÅ `→utredning` — men bara en klient-boolean; `→avslutat` **helt ogated** | Nästa-åtgärd + alla flikar/moduler öppna | `steg`-rad; kvittensen journalförs **aldrig** | **Störst.** Skyddsbedömningen kan "kvitteras" utan att den finns; "inte inleda"-avslut är ogated |
| **Utredning** (4 mån) | Utredningsplan → BBIC-utredning (barnets röst, källa vs bedömning, analys) → kommunicering (FL 25 §) | Bara att grafkanten finns. Ingen innehållskontroll | Alla 18 mallar (platt lista), parter, kommunicering, commit — allt **obundet** | `steg`-rad; ev. `handling`/`part` (frivilligt) | Hela BBIC + kommunicering kan hoppas; tomt = samma spår som fullständigt |
| **Beslut** (21 d överklagande) | (A) kommunicering inför beslut, (B) beslut m. motivering + e-underskrift + commit, (C) delgivning m. styrkt delfående + överklagandehänvisning | `beslut→uppfoljning` ogated; commit är **ortogonal** mot steget | Signeringskryssa ("Jag har signerat") — enda innehålls-spärren, men självdeklaration | `registrerad` + commit-kvitto (dnr) OM man committar | Kommunicering (FL 25 §) + styrkt delgivning obundna; kan gå vidare **utan commit** = ingen extern provenans |
| **Uppföljning** (6 mån) | Genomförandeplan; **lagstadgat** övervägande/omprövning minst var 6:e mån (LVU 13 §) | `→avslutat` villkorslöst | "Avsluta" via ren bekräftelseruta; bevakningar i fliken | `steg`-rad; ev. bevakning | LVU-omprövningen vilar **enbart** på att en 6-mån-bevakning råkar skapas |
| **Avslutat** | Avslutsanteckning m. utfall; gallrings-/bevarandebedömning (arkivlag) | Terminal. Inträde ogated från alla föregående steg | Död knapp ("Arkiverat") | `steg`-rad; **journalen gallras med ärendet** | Ärende avslutat utan commit lämnar **inget permanent spår** efter gallring |

---

## 3. Verifierade GAP (8 — adversariellt stresstestade)

Varje GAP fick en skeptiker som försökte *refutera* det mot koden. Resultat:

| GAP | Allvar | Verdikt | Kärna | Evidens |
|---|---|---|---|---|
| **GAP-U1** | P0 | **BEKRÄFTAT** | Plikt-grinden kollar bara `skyddsbedomningKvitterad===true`; flaggan sätts *automatiskt* efter vilken verifierad commit som helst (även tom doklista); systerhärledningen `pliktForArende()` är cirkulär (bevis = stegflytten själv) | `ArendeLifecycleService.php:123-134`, `MinaArenden.vue:1034-1038`, `ArendeService.php:2337-2354` |
| **GAP-U2** | P0→**P1** | **DELVIS** | Backend-kanten `forhandsbedomning→avslutat` är genuint ogated, MEN **ingen GUI-affordans når den idag** — "avsluta" är bunden till uppföljning, och förhandsbedömnings-commit avancerar till *utredning*. Latent/API-risk, inte ett klickbart flöde | `ArendeLifecycleService.php:55,116-122`; `arendeFlow.js:18` |
| **GAP-U3** | P0 | **BEKRÄFTAT** (skärpt) | `utredning→beslut` ogated; commit inspekterar aldrig ärenderummets innehåll; tom doklista tillåts. **Skärpning:** övergången kräver inte ens en commit (steg-advancering ortogonal) | `ArendeLifecycleService.php:56`, `ArendeService.php:1879-1988,1962-1967`, `CommitGrind.vue:77,166` |
| **GAP-U4** | P1 | **BEKRÄFTAT** | `uppfoljning→avslutat` villkorslöst; LVU 13 §-omprövningen bärs helt av `overvagande_6man`-bevakningen — som avbryts vid avslut | `ArendeLifecycleService.php:58,170`, `AvslutaGrind.vue:77-83`, `ArendeTypRegistry.php:266-276` |
| **GAP-U5** | P1 | **BEKRÄFTAT** | Journalen bevisar bara ATT ett steg byttes, aldrig ATT arbetet gjordes; kvittensen journalförs aldrig; historik-fliken saknar case för `handling`/`part`/`bevakning` (faller till rått typnamn); allt gallras med ärendet | `Handelse.php:41-73`, `ArendeKort.vue:678-699` |
| **GAP-U6** | P1 | **BEKRÄFTAT** | Hela verktygslådan obunden; `HandlingModal` visar alla 18 mallar platt; mallbibliotekets Fas A–E finns bara som prosa i `00-oversikt.md`, aldrig i koden | `NastaAtgardKnapp.vue:62-77`, `MallService.php:86-103`, `HandlingModal.vue:187-199` |
| **GAP-U7** | P2 | **BEKRÄFTAT** | Signeringskryssan är självdeklaration; ingen PAdES/LTV-artefakt verifieras; bevarande-panelen (`full.beslut`) är `null` i live-läge | `CommitGrind.vue:166`, `ArendeService.php:1123` |
| **GAP-U8** | P2 | **BEKRÄFTAT** | Steppern spärrar bara framåt-klick; ärende i `inflode` visar felaktigt "Förhandsbedömning" som current; `substeg`-prop tas in men visas aldrig; hover säger bara vad steget *heter* | `ProcessStepper.vue:48,55-58,65-83`, `arendeFlow.js:35-41` |

**Den avgörande insikten (ur syntesen):** *Beviset finns redan men kastas.* `handling`-journalens `detalj.mall` bär mall-id:t (`HandlingService.php:305-306`), men frontend droppar det och grindarna läser det aldrig. Att **sluta kasta det** bryter den cirkulära plikt-härledningen — utan att tvinga fram en enda ny kryssruta eller dubbeldokumentation.

---

## 4. Designprincipen (från realitetsspåret)

Hubs **äger inte dokumenten** — Treserva gör. En hård innehållsgrind i Hubs kontrollerar därför bara en *proxy*, och lär handläggaren att fejka den. Därav bärande principen genom hela planen:

> **Bygg hårdhet bara på TID och SÄKERHET — där Hubs faktiskt äger sanningen. Gör allt annat till passiv synlighet + medvetna, journalförda overrides. En grind som går att passera med en lögn är värre än ingen grind.**

Två platser förtjänar hård grind: **skyddsbedömningens existens som artefakt** (U1) och **LVU-fristen som Hubs redan bevakar** (U4). Allt annat: synlighet, nudge, och overrides med journalfört skäl.

---

## 5. Processteppern 2.0 — "hovra och se vilka delar som ingår och var vi är"

Det du efterfrågar kräver en sak steppern saknar idag: **data om vad varje steg innehåller.** `PROCESS_STEG` bär bara `{id, label}`, så hover *kan* inte visa mer än namnet.

**Lösningen: `STEG_INNEHALL` — en deklarativ stegmodell som blir enda sanningskälla** för steppern (avläsning), härledningen (klar/saknas), grindarna (tvång) och `HandlingModal` (mall-gruppering). Definition-of-done skrivs på **ett** ställe.

```js
// arendeFlow.js — ny export
export const STEG_INNEHALL = {
  forhandsbedomning: {
    label: 'Förhandsbedömning',
    kortBeskrivning: 'Skyddsbedömning samma dag + beslut inleda/inte inleda inom 14 dagar.',
    frist: { dagar: 14, ankare: 'inkom', lag: 'SoL 11:1a' },
    delmoment: [
      { id: 'skyddsbedomning', label: 'Omedelbar skyddsbedömning', vadDetAr: 'Bedöm akut skyddsbehov samma dag – även vid Nej.',
        lagreferens: { lag: 'SoL', paragraf: '11:1a', verifieraParagraf: true }, niva: 'obligatorisk',
        artefakt: 'mall-02', klarNar: { signal: 'handling', match: 'skyddsbedomning' } },
      { id: 'skyddsbedomning_kvittens', label: 'Skyddsbedömning kvitterad', vadDetAr: 'Handläggaren kvitterar att bedömningen är gjord (grind mot utredning).',
        niva: 'obligatorisk', klarNar: { signal: 'kvittens', match: 'skyddsbedomning' } },   // GAP-U1
      { id: 'beslut_inleda', label: 'Beslut: inleda / inte inleda', vadDetAr: 'Motiverat beslut inom 14 dagar; motivering särskilt viktig vid "inte inleda".',
        lagreferens: { lag: 'SoL', paragraf: '11:1', verifieraParagraf: true }, frist: { dagar: 14 },
        niva: 'obligatorisk', artefakt: 'mall-03', klarNar: { signal: 'handling', match: 'forhandsbedomning' } },
    ],
  },
  // ... utredning (4 mån), beslut (21 d), uppfoljning (6 mån), avslutat
}
```

**Delmoment per steg (facit ur mallbiblioteket + FL/SoL/LVU):**

- **Inflöde** *(villkorad nod)*: anmälan dokumenterad (mall 01), 14-dagarsklockan startad.
- **Förhandsbedömning** *(14 d)*: skyddsbedömning (mall 02, SoL 11:1a) · kvittens (grind, U1) · beslut inleda/inte inleda (mall 03, SoL 11:1).
- **Utredning** *(4 mån)*: utredningsplan (mall 04) · BBIC-utredning (mall 05) · barnets egen röst (mall 08, Barnkonv art. 12) · kommunicering (mall 16, FL 25 §, U3) · löpande journal (mall 06, *rekommenderad*).
- **Beslut** *(21 d överklagande)*: kommunicering inför beslut (mall 16, FL 25 §) · beslut fattat & committat (mall 15, `klarNar=commit` — starkaste beviset) · delgivning m. styrkt delfående (mall 17, FL 33/43–44 §§).
- **Uppföljning** *(6 mån)*: genomförandeplan (mall 13) · övervägande/omprövning-datum satt (mall 12, LVU 13 §, `klarNar=bevakning`, U4).
- **Avslutat**: avslutsanteckning m. utfall (mall 18) · gallringsbedömning (arkivlag, `klarNar=commit`).

**Signal-vokabulär för "klar/saknas"** (härleds ur *befintliga* signaler — ingen ny lagring för de flesta): `handling` (matcha `journal.detalj.mall`), `part` (roll), `bevakning` (aktiv typ), `commit` (verifierad dnr) + två nya: `kvittens` (journalförd) och `manuell` (sista utväg).

**Hover-panelen** (NcPopover, ~150 ms delay, klick pinnar) visar per steg: syfte · **checklista "vilka delar ingår"** med status-ikon per delmoment (klar/pågår/saknas — *ikon + text, aldrig bara färg*) · frist-status · lagref-chip · och för aktuellt steg en **"detta återstår"**-sektion. Avklarade steg visar vem/när/kvitto per klart delmoment; framtidssteg får en **låsförklaring** istället för tom "Kommande".

**Sex nod-states** (idag 3): `done` · `done-luckor` (passerat men obligatoriskt delmoment saknar bevis → gul bock + "2/4") · `current` · `future` · `blocked` · `overhoppat` (passerat helt utan bevis → grå streckad ring). **`done-luckor` och `overhoppat` gör den tysta överhoppningen synlig i efterhand — utan att blockera.**

*(En interaktiv prototyp av denna stepper visas separat i chatten.)*

---

## 6. Förbättringsplan — 12 åtgärder, prioriterade

Princip: **synlighet först (noll kringgåenderisk), hård grind bara på tid & säkerhet.**

### Fas 1 — Quick wins (S, noll process-påverkan)
| # | Åtgärd | Nivå | Löser |
|---|---|---|---|
| **A1** | Fixa stepper-current-buggen + villkorad inflöde-nod (`effektivaSteg`-computed) | synlighet | U8 |
| **A2** | Sluta droppa journal-detalj i `historikLabel` — visa "Handling skapad ur mall: X" + aktör/datum/länk (beviset finns redan) | synlighet | U5, U6 |
| **A3** | **`STEG_INNEHALL`** — deklarativ stegmodell, enda sanningskälla (materialiserar mallbibliotekets Fas A–E till maskinläsbar data) | data/modell | U6, U8, U5 |

### Fas 2 — Synlighet & vägledning (M–L, fortfarande noll tvång)
| # | Åtgärd | Nivå | Löser |
|---|---|---|---|
| **A4** | Rik popover-hover: syfte, delmoment-checklista, frist, definition-of-done, "detta återstår", låsförklaringar | synlighet | U8, U6 |
| **A6** | Härledningsmotor `harledStatus(delmoment, {journal,commit,bevakningar,parter})` → klar/pågår/saknas ur befintlig journal | evidens | U5 |
| **A5** | Nod-states `done-luckor` + `overhoppat` — synliggör tyst överhoppning ("3/4"-badge) | synlighet | U2, U3, U5 |
| **A10** | Mall→steg-bindning i `HandlingModal` (sektioner "För detta steg" / "Används ofta" / "Andra steg" hopfälld) — **sortera, dölj aldrig** | rådgivande | U6 |

### Fas 3 — Strukturella grindar (endast där Hubs äger sanningen)
| # | Åtgärd | Nivå | Löser |
|---|---|---|---|
| **A7** | **U1 HÅRD:** plikt-grinden kräver skyddsbedömningens *existens* som artefakt (`EvidensService::harArtefakt`, noll schemaändring), inte klient-boolean; `pliktForArende()` slutar ljuga | hård grind | U1 |
| **A8** | **U4 HÅRD på fristen:** motorn skapar LVU-omprövningsbevakningen *automatiskt* vid inträde i uppföljning (osynligt, minskar börda; fixar även de kända bevaknings-P1-buggarna) | hård grind | U4 |
| **A9** | **U2/U3/avslut MJUKA:** `Handelse::TYP_GRINDVAL` — grinden sitter på *motiveringen*, aldrig på utfallet ("inte inleda" kräver strukturerat skäl; kommunicering blir stark nudge m. medveten override; commit slutar tillåta tom doklista för beslutskanten) | mjuk grind | U2, U3 |

### Fas 4 — Full provenans
| # | Åtgärd | Nivå | Löser |
|---|---|---|---|
| **A11** | U7: läs signatur-provenans ur commit-kvittot i stället för självdeklaration (bygg *inte* PAdES-verifiering i Hubs) | evidens | U7 |
| **A12** | Bevarande-kanal mot facksystemet vid varje lagstadgad kvittens + avslut (journalen gallras — rättskällan måste bo externt) | evidens | U5 |

**Kritisk beroendekedja:** A3 före A4/A6/A10 · A6 före A5 · `EvidensService` (A7) återanvänds av A9.

---

## 7. Rekommendation

Börja med **Fas 1 + 2**. De är billiga, riskfria, och löser själva känslan direkt: efter A1–A6 *ser* handläggaren på en blick vad varje steg innehåller, vad som är klart, vad som återstår, och — avgörande — **vad som tyst hoppats över**. Ingen blockering, ingen kringgåenderisk, ingen dubbeldokumentation.

Ta sedan **A7 (skyddsbedömningens existens)** som första hårda grind — störst rättssäkerhetseffekt, minst kodyta, och den bryter den cirkulära plikt-härledningen. **A8 (automatisk LVU-frist)** därefter, eftersom den *minskar* handläggarens börda samtidigt som den stänger ett av de allvarligaste process-glappen.

*Analys genererad med Claude Code (Opus 4.8), 21-agents multi-workflow. Lagreferenser markerade `*` (SoL 2025:400 ändrad paragrafnumrering) bör verifieras mot gällande lydelse.*
