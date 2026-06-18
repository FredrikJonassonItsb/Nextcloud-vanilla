# Persona-dashboard: Överförmyndarhandläggare

> Personaliserad förstavy (dashboard) för **en** persona i Hubs (ITSL) — den säkra kommunikations- och handläggningssviten för svensk offentlig sektor, byggd på en suverän plattform driftad på kundens egna servrar.
> Brand-regel: i produkt-/kundtext heter det aldrig "Nextcloud" eller "Talk" — vi säger "Hubs", "säkra meddelanden", "säkert videomöte", "ärenderum", "uppgifts-/bevakningsmodulen", "e-underskrift". De underliggande appnamnen används bara i interna underlag som detta.
> Persona-id: `overformyndare` · Datum: 2026-06-13 · Plattform: server v32 (Hub 25 Autumn).

---

## Persona & en dag i arbetet

**Vem:** Handläggare på en överförmyndarenhet/överförmyndare i samverkan (ÖiS) i en kommun eller kommungemensam nämnd. Granskar gode mäns, förvaltares och förmyndares redovisningar, kommunicerar med ställföreträdare och huvudmän, fattar och föredrar beslut (arvode, uttag från spärrat konto, försäljning av bostad, byte av ställföreträdare), och utövar tillsyn. Lever i ett **deadline-drivet årshjul** med en gigantisk topp runt **1 mars** (årsräkningar) och en lång granskningssäsong fram till sommaren.

**Verksamhetssystemet** är **Provisum** (Sambruk) eller **Aider** — där bor ställföreträdarregistret, årsräkningarna, granskningsstödet och bevakningarna. Hubs konkurrerar **inte** med facksystemet. Hubs äger **flödet runt omkring**: den säkra tvåvägskommunikationen med ställföreträdare och huvudmän, e-underskrift av beslut och redovisning, det säkra ärenderummet med verifikat och bilagor, säkra möten, och den aggregerade "vad måste jag göra och när"-vyn som facksystemets fristlistor inte ger på ett mänskligt sätt.

**Rättslig ram som styr dagen:**
- **Föräldrabalken 14 kap. 15 §** — gode män/förvaltare lämnar **årsräkning före 1 mars** för föregående års förvaltning. Det kanoniska kalenderlåsta massflödet (tusentals ärenden per enhet).
- **Ställföreträdaren väljer redovisningsform** — får lagstadgat lämna **på papper**. Dubbla kanaler (e-tjänst + papper + post + telefon) består under lång tid; Hubs måste samla alla inkommande kanaler i en vy och fungera som migreringsbrygga, inte tvinga fram digitalt.
- **Granskningsmål och fristkrav** (kommunal praxis, t.ex. ÖiS): ~**80 % av i tid inkomna årsräkningar färdiggranskade 30 juni**, 100 % påbörjade 30 juni, **max ~7 månader** per ärende om inte särskilda skäl. **Förvaltningslagen (2017:900) 11–12 §§**: underrättelse vid väsentlig försening, partens rätt att efter 6 månader begära avgörande (avgöras inom 4 veckor).
- **JO-praxis (dec 2025):** granskning är "en helt central del" — måste pröva om poster är **rimliga**, om utgifter avser den enskilde och är till dennes nytta; kritik mot enheter som dröjt. Separat JO-kritik om bristande **kontroll av hur många uppdrag** en ställföreträdare har. → Granskningskvalitet och uppdragsöverblick är rättssäkerhet med revisionsspår, inte trevligt-att-ha.
- **Reform "Ett ställföreträdarskap att lita på" (prop. dec 2025):** i samförståndsärenden flyttas **beslut från tingsrätten till överförmyndaren** (mer myndighetsutövning, fler beslut att fatta/föredra/underteckna), **MFoF** blir tillsynsvägledande myndighet, och ett **nationellt register** + **obligatorisk utbildning** införs. **Ikraftträdande: merparten 1 juli 2026, register + utbildningskrav 1 jan 2028.** → Mer beslutsvolym och mer dokumenterad kommunikation hamnar hos handläggaren just under produktens införandefönster.
- **OSL + GDPR + arkivlagen:** redovisningar och verifikat innehåller känsliga ekonomiska och ofta hälsorelaterade uppgifter om en skyddsvärd huvudman. Får inte röjas i öppna kanaler; allmänna handlingar som ska bevaras/gallras enligt dokumenthanteringsplan. **HSLF-FS 2016:40** kan vara relevant när läkarintyg om huvudmannens tillstånd hanteras.

**En dag i mars (toppen):**
Loggar in med BankID/Freja (LOA3). Dashboarden öppnar i **"Årsräkningar 2026"-kampanjvyn**: *"312 av 540 årsräkningar granskade · 18 dagar till 1 mars · 47 saknar verifikat."* Hen plockar nästa otilldelade årsräkning ur **granskningskön**, ser att två verifikat saknas, klickar **"Begär komplettering"** — ett färdigt säkert meddelande går till ställföreträdaren via säker kanal med läskvittens, och en **bevakning** skapas automatiskt (svar väntas inom X dagar). I **"Att signera"-kön** ligger tre arvodesbeslut och en sluträkning för **e-underskrift** — hen granskar och signerar med BankID; PAdES/PDF/A-kopia + valideringsintyg arkiveras. En ställföreträdare har bett om ett **säkert möte** om en bostadsförsäljning — hen bokar en tid, ett säkert videorum skapas automatiskt. I **"Skickat för signering"** ser hen att gårdagens komplettering är *öppnad men ej besvarad* och trycker **Påminn**. Hela tiden visar en strip överst: *"4 bevakningar förfaller denna vecka."* Inget faller mellan stolarna; tom kö = inget missat.

**En dag utanför toppen (höst/vinter):**
Tillsynsärenden, uttag från spärrat konto, byte av ställföreträdare, nya godmanskap (efter reformen fler beslut hos enheten), ställföreträdarrekrytering och löpande frågor från gode män. Mindre volym, mer dialog och fler enskilda beslut att skriva, underteckna, delge och bevaka.

---

## Mål & nyckeltal (KPI)

Mät **tid-till-åtgärd och deadlineträffsäkerhet**, inte tid på dashboarden. Föreslagna nyckeltal som widgetarna ska kunna visa:

| KPI | Varför | Källa |
|---|---|---|
| **Andel årsräkningar färdiggranskade** (mot mål 80 % per 30 juni) | Direkt mot granskningsmålet och JO:s krav | Provisum/Aider (föreslagen integration) + Hubs kampanjvy |
| **Granskningskö: antal ej påbörjade / under granskning / väntar på komplettering** | "Vad återstår", lastbalansering | Facksystem + Hubs bevakningsregister |
| **Dagar till 1 mars** + **antal som saknar verifikat/komplettering** | Eskalering i toppen | Hubs kampanjvy |
| **Ärenden över fristgränsen** (7 mån / FL 6 mån) | Rättssäkerhet, undvik JO-kritik | Hubs bevakning (deadlineröster) |
| **Obesvarade säkra meddelanden > X dagar** | Internkontroll, NIS2-ledningsansvar, inget missat | Hubs säkra inkorg |
| **Dokument som väntar på min e-underskrift** + **utskickade som väntar på motpartens** | Beslutsgenomströmning, ersätter "ringa och kolla" | Hubs e-underskrift |
| **Andel digital vs pappersredovisning** + **ersatta brev/fax** och **sparad tid** (~30 min/ärende, Diggs schablon) | Migreringsbevis + ROI till chef/nämnd | Hubs nytto-/migreringsmätare |
| **Ställföreträdare med ovanligt många uppdrag** (flaggning) | JO-kravet på uppdragskontroll | Föreslagen Tables-flagga/facksystem |
| **Svarstid på komplettering** (median) | Flödeshälsa ställföreträdare↔enhet | Hubs säkra meddelanden |

---

## Primära åtgärder (verb-först)

De 3–5 knappar/kommandon som ska vara nåbara direkt i förstavyn och via Ctrl+K — var och en kopplad till funktion/app:

1. **Granska nästa årsräkning** → öppnar nästa otilldelade post i granskningskön (kampanjvyn) med ärenderummets verifikat sida vid sida. *Funktion: Uppgifts-/bevakningsmodul + Ärenderum (säkra filer); data från Provisum/Aider (föreslagen integration).*
2. **Begär komplettering** → förifyllt säkert meddelande till ställföreträdaren (saknade verifikat/poster), med läskvittens; skapar automatiskt en bevakning med svarsfrist. *Funktion: Säkra meddelanden (SDK/säker e-post) + Uppgifts-/bevakningsmodul.*
3. **Signera & delge beslut** → e-underskriv arvodes-/uttags-/tillsynsbeslut (BankID/Freja, AES) och delge ställföreträdare/huvudman via säker kanal; arkivklar PAdES/PDF/A. *Funktion: e-underskrift + Säkra meddelanden.*
4. **Boka säkert möte** → skapa bokningsbar tid/skicka kallelse; säkert videorum skapas automatiskt (t.ex. bostadsförsäljning, svårt ärende, rekryteringssamtal). *Funktion: Kalender/bokning + säkert videomöte.*
5. **Skapa bevakning från meddelande/ärende** → gör ett inkommande meddelande eller en beslutsfrist till en spårbar uppgift med deadline och ansvarig. *Funktion: Uppgifts-/bevakningsmodul (limmet mot inkorgen).*

---

## Widgetar (ordnad lista)

Ordnad efter förstavyns prioritet för denna persona. Varje widget följer Viva-mönstret **Card View** (agera direkt) + **Quick View** (detalj utan sidbyte), GOV.UK-statusmodell där tillämpligt, och WCAG 2.2 AA. Alla byggs som Hubs-branded widgets ovanpå plattformens dashboard-API (IAPIWidgetV2 + IButtonWidget + IConditionalWidget för rollstyrning).

| # | id | Titel | Syfte | Datakälla | App/funktion |
|---|---|---|---|---|---|
| 1 | `ofm-arsrakningskampanj` | **Årsräkningar 2026 — granskningsläget** | Den deadline-låsta toppen som en GOV.UK task-list-kampanj: aggregerad progress *"312 av 540 granskade · 18 dagar till 1 mars"*, per-ärende-status (Inkommen / Under granskning / Komplettering begärd / Klar för arvode / Klar), filter (mina/enhetens/förtur). Förtursflagga för förstagångsredovisare och tidigare anmärkta. | **Föreslagen**: Provisum/Aider-integration (status, deadline, ställföreträdare); Hubs bevakningsregister (Tables) som spegel | Uppgifts-/bevakningsmodul (kampanjvy) |
| 2 | `ofm-granskningsko` | **Granskningskö — nästa att granska** | Plockbar kö över otilldelade/tilldelade redovisningar; "ta ansvar"-knapp, källkanal-ikon (e-tjänst/papper-inskannat/post), saknas-verifikat-markering. "Granska nästa"-primäråtgärd. Visar ärenden som närmar sig 7-mån/FL-frist i rött. | **Föreslagen**: facksystem + Hubs bevakning | Uppgifts-/bevakningsmodul + Ärenderum |
| 3 | `ofm-att-signera` | **Att signera** | Personlig + funktionsbaserad kö över beslut/redovisningar som väntar på *min* e-underskrift: arvodesbeslut, sluträkningar, tillsynsbeslut. Kravnivå-badge (SES/AES/QES — AES via BankID standard), deadline (laga-kraft-frist). "Granska & signera". För lågriskhandlingar visas "Godkänn" (loggat) i stället för "Signera" (SKR:s riskmodell). | **Befintlig** byggsten (e-underskriftsmotor via Inera Underskriftstjänsten/Sweden Connect-nod); Hubs kö | e-underskrift |
| 4 | `ofm-skickat-for-signering` | **Skickat för signering & kompletteringar** | Spegelbilden: utskickade beslut/kompletteringsbegäran och var de ligger — *Skickat → Öppnat → Besvarat/Signerat → Klart* eller *Påminnelse skickad / Utgånget*. Per-part-status, **Påminn**-knapp. Den känslomässiga ersättningen för "ringa och kolla att posten kom fram". | **Befintlig**: säkra meddelanden + e-underskrift (statuskedja/kvittens) | Säkra meddelanden + e-underskrift |
| 5 | `ofm-bevakningar` | **Mina bevakningar & frister** | Aggregerad "att hantera"-lista (GOV.UK-statusar: Ny / Påbörjad / Väntar på motpart / Klar + rött Åtgärd krävs), deadline-sorterad med eskaleringsfärg. Räknare överst: *"4 bevakningar förfaller denna vecka."* Inkluderar FL-6-mån-röster och beslut med laga-kraft-frist. Påminnelser T-7/T-3/T-0 bara till tilldelad person. | **Befintlig** byggsten (kanban-/VTODO som datalager); Hubs bevakningslogik | Uppgifts-/bevakningsmodul |
| 6 | `ofm-saker-inkorg` | **Säkra meddelanden — ställföreträdare & huvudmän** | En inkorg, alla kanaler (säker e-post till medborgare/ställföreträdare, SDK org-till-org mot t.ex. bank/region/tingsrätt, digital fax-in) med kanalindikator, oläst/kvittens-status och "Skapa bevakning"-knapp per meddelande. Delad funktionsbrevlåda med "vem tar detta?". | **Befintlig**: SDK + säker e-post + digital fax | Säkra meddelanden |
| 7 | `ofm-arenderum` | **Ärenderum — per ställföreträdarskap** | Översikt över öppna ärenderum (funktionsmapp per huvudman/uppdrag): årsräkning + verifikat + beslut + intyg, versionshistorik, gallringsstatus ("Bevaras"/"Gallras ÅÅÅÅ"), säker medborgardelning aktiv?, kommande deadline. Status per rum. | **Befintlig**: funktionsmappar/ACL/versioner/Retention; Hubs orkestrering | Säkra filer (ärenderum) |
| 8 | `ofm-moten` | **Dagens & veckans säkra möten** | Bokade/kommande säkra videomöten (ställföreträdarsamtal, rekryteringssamtal, svåra ärenden) med en-klicks-anslut, plus "Boka säkert möte"-åtgärd (auto-videorum). | **Befintlig**: kalender/bokning + säkert videomöte | Kalender + säkert videomöte |
| 9 | `ofm-uppdragskontroll` | **Uppdragsöverblick (flaggning)** | JO-kravet operationaliserat: flaggar ställföreträdare med ovanligt många uppdrag eller upprepade anmärkningar för fördjupad tillsyn/stickprov. Diskret, tillsynsstödjande. | **Föreslagen**: facksystem + Tables-regelmotor | Uppgifts-/bevakningsmodul (register) |
| 10 | `ofm-nytta` | **Nytta hittills** (chef/nämnd) | Ersatta brev/fax, andel digital vs pappersredovisning, uppskattad sparad tid (~30 min/ärende), genomsnittlig granskningstid. ROI-/NIS2-underlag till nämnd och förvaltningschef. Rollstyrd (visas för chef). | **Föreslagen**: Tables-register matat av Hubs-händelser | Uppgifts-/register (Tables) |

> **Tom-tillstånd som positivt besked** överallt ("Inga redovisningar väntar på granskning", "Inga bevakningar förfaller denna vecka") — tom kö = inget missat är ett *compliance-värde* för en skyddsvärd huvudmans tillgångar.

---

## Föreslagna appar/moduler (befintlig vs föreslagen + motivering)

| Modul/app | Status | Motivering för denna persona |
|---|---|---|
| **Säkra meddelanden** (SDK, säker e-post, digital fax) | **Befintlig** (Hubs kärna) | Ställföreträdare och huvudmän nås säkert; SDK mot bank/region/tingsrätt; fax-in som brygga. Funktionsbrevlåda med kvittens ersätter post/telefon för komplettering och delgivning. |
| **e-underskrift** (BankID/Freja AES; Inera Underskriftstjänsten eller egen Sweden Connect-nod) | **Befintlig byggsten / föreslagen integration** | Arvodes-, uttags- och tillsynsbeslut samt sluträkningar undertecknas i arbetsytan; PAdES/PDF/A + LTV/valideringsintyg för arkiv. Efter reformens fler beslut hos enheten ökar signeringsvolymen. Stå på nationell tjänst — bygg inte kryptokärnan. |
| **Uppgifts-/bevakningsmodul** (kanban-/VTODO som datalager) | **Befintlig byggsten / Hubs-logik föreslagen** | Kampanjvyn (1 mars), granskningskön, frist-röster (7 mån/FL 6 mån), påminnelser-före-deadline (täcker känd lucka i kanban-kärnan), "skapa bevakning från meddelande". Signaturfunktionen för persona. |
| **Säkra filer / ärenderum** (funktionsmappar, ACL, versioner, Retention, Collabora/OnlyOffice on-prem) | **Befintlig** | Ett rum per ställföreträdarskap: årsräkning + verifikat + beslut, gallring enligt dokumenthanteringsplan, säker medborgardelning av utvalda dokument via BankID. Bilagor *bor* i rummet; meddelanden *refererar*. |
| **Kalender/bokning + säkert videomöte** | **Befintlig** | Ställföreträdarsamtal, rekryteringssamtal, svåra ärenden (bostadsförsäljning) som bokat möte med auto-skapat säkert videorum — utan att exponera känsliga uppgifter i konsumentverktyg. |
| **Provisum/Aider-integration** (status, deadline, ställföreträdarregister, granskningsläge) | **Föreslagen** | Hubs ersätter inte facksystemet men måste *läsa* dess status för att kampanjvyn/granskningskön ska vara sanna. API/läsintegration mot Provisum (Sambruk) och Aider; där integration saknas, manuell/halvautomatisk spegling i Hubs bevakningsregister. |
| **Strukturerat register (Tables) som osynlig motor** | **Föreslagen** | Backend för bevaknings-/deadline-register, uppdragskontroll-flaggning och nytto-/migreringsmätaren. Visas aldrig rått — renderas som Hubs-widgets. |
| **Forms (internt)** | **Föreslagen, begränsat** | Internt strukturerat underlag (t.ex. checklista vid granskning, rekryteringsintresse), inte publik e-tjänst. Notera Forms-begränsningar (ingen filuppladdning/förgrening). |
| **Command palette (Ctrl+K)** | **Föreslagen** | "Granska nästa årsräkning", "Begär komplettering", "Sök huvudman/dnr", "Boka möte" — skalar för en daglig expertanvändare i toppsäsong. |
| **Kunskapsbank (Collectives)** | **Föreslagen, lågprioritet** | Granskningsrutiner, gallringsplan per handlingstyp, mallar för kompletteringsbegäran och beslut, lathund inför 1 mars. Minskar kognitiv belastning ("en ingång"). |
| **Maps / Notes** | **Avstå** | Maps saknar v32-stöd och har osäker underhållsstatus; Notes är för smal. Ingen relevans för denna persona. |

---

## Terminologi (persona-anpassade ord)

Använd verksamhetens egna ord i UI; undvik generiska "ärende/inkorg/uppgift" där facktermen finns. Brand-regel: aldrig "Nextcloud"/"Talk".

| Generiskt | Persona-anpassat ord i Hubs UI |
|---|---|
| Klient/medborgare | **Huvudman** (den som har god man/förvaltare) |
| Motpart/avsändare | **Ställföreträdare** (god man / förvaltare / förmyndare) |
| Inkommande blankett att granska | **Årsräkning / sluträkning / förteckning / redogörelse** |
| Bilaga | **Verifikat / underlag** |
| Uppgift/todo | **Bevakning** |
| Deadline | **Frist** (t.ex. 1 mars; arvodesbeslutets laga-kraft-frist) |
| Begär mer info | **Begär komplettering** |
| Påpekande/avvikelse | **Anmärkning** |
| Granskningstopp | **Granskningssäsong / årsräkningsperioden** |
| Beslut att skriva under | **Beslut för underskrift** (arvode, uttag spärrat konto, samtycke försäljning) |
| Skicka säkert | **Skicka säkert meddelande / delge** |
| Mapp per ärende | **Ärenderum (per uppdrag/huvudman)** |
| Möte | **Säkert möte / ställföreträdarsamtal** |
| Tillsyn | **Tillsyn / granskning** (inte "kontroll") |

---

## Flöden (end-to-end)

### Flöde 1 — Granska årsräkning med komplettering → arvodesbeslut → delgivning (toppen, 1 mars)
1. **Inkommer:** Ställföreträdaren lämnar årsräkning + verifikat via e-tjänst (Provisum/Aider) **eller** papper (skannas in i ärenderummet). Hubs visar den i **granskningskön** (`ofm-granskningsko`) med källkanal-ikon; förstagångsredovisare/tidigare anmärkta får förtursflagga.
2. **Granska:** Handläggaren klickar **Granska nästa** → ärenderummet (`ofm-arenderum`) öppnar årsräkning + verifikat sida vid sida (samredigering on-prem). JO-checklista: är posterna rimliga, avser de huvudmannen, till dennes nytta?
3. **Brist upptäcks:** Två verifikat saknas → **Begär komplettering** (`ofm-saker-inkorg`): förifyllt säkert meddelande till ställföreträdaren med läskvittens; en **bevakning** (`ofm-bevakningar`) skapas automatiskt med svarsfrist och påminnelser T-7/T-3.
4. **Svar:** Ställföreträdaren svarar via säker kanal (eller laddar upp i den BankID-skyddade delningen av ärenderummet). **Skickat för signering & kompletteringar** (`ofm-skickat-for-signering`) visar *Besvarat*; bevakningen stängs.
5. **Beslut:** Granskning klar → handläggaren skriver arvodesbeslut, **Signera & delge** (`ofm-att-signera`): e-underskrift med BankID (AES); PAdES/PDF/A + valideringsintyg arkiveras i ärenderummet med gallringsregel.
6. **Delge:** Beslutet delges ställföreträdaren via säker kanal; laga-kraft-frist sätts som bevakning. **Nytta hittills** (`ofm-nytta`) räknar upp ett ersatt brev + sparad tid.

### Flöde 2 — Ansökan om uttag från spärrat konto → samtycke/beslut → e-underskrift → besked till bank (året runt)
1. **Begäran:** Ställföreträdaren ansöker (säkert meddelande eller e-tjänst) om uttag från huvudmannens spärrade konto för en utgift, bifogar underlag. Hubs gör det till en **bevakning** med frist (FL: enkelt, snabbt, rättssäkert).
2. **Bered:** Handläggaren prövar i ärenderummet; vid behov **boka säkert möte** (`ofm-moten`) med ställföreträdaren (auto-videorum) för att reda ut underlaget.
3. **Beslut:** Handläggaren fattar beslut (samtycke/avslag), **e-underskriver** (`ofm-att-signera`).
4. **Verkställ mot bank:** Besked till banken skickas **org-till-org via SDK** (`ofm-saker-inkorg`) med kvittens — ersätter fax/post; spårbart leveranskvitto.
5. **Återkoppla:** Ställföreträdaren delges säkert; bevakning stängs när bankens bekräftelse kvitteras. Allt loggat och arkiverat (NIS2/arkivlagen).

### Flöde 3 — Nytt godmanskap i samförstånd (efter reform 1 juli 2026) → utredning → beslut → registrering
1. **Initiering:** Ansökan/anmälan inkommer (säkert meddelande/e-tjänst). Med reformen beslutar **överförmyndaren** i samförståndsärenden i stället för tingsrätten → mer myndighetsutövning hos enheten. Hubs öppnar **ärenderum** för det blivande uppdraget och en **bevakning** för utredningstiden.
2. **Underlag:** Läkarintyg om huvudmannens tillstånd och samtycken hämtas in säkert (HSLF-FS-medveten hantering); lämplig ställföreträdare matchas — **uppdragsöverblick** (`ofm-uppdragskontroll`) flaggar om kandidaten redan har många uppdrag (JO-kravet).
3. **Samtal:** **Säkert möte** med huvudman/anhörig/ställföreträdare för samförstånd; whiteboard-/anteckningsstöd vid behov.
4. **Beslut & underskrift:** Överförmyndaren fattar och **e-underskriver** beslutet om godmanskap; det delges parterna säkert.
5. **Registrering:** Uppgifter förs till facksystemet och (från 2028) det **nationella registret**; bevakning sätts för första årsräkning. Hubs har då fört hela samtals-/dokument-/underskriftskedjan utan att känsliga uppgifter lämnat driftmiljön.

---

## Tillgänglighet & sekretess

**Tillgänglighet (DOS-lagen / EN 301 549 / WCAG 2.2 AA — bygg mot 2.2 redan nu):**
- **Target Size ≥ 24×24 px** på status-/klarmarkera-knappar, kanalikoner, "ta ansvar"-knappen i granskningskön och signeringsstegen.
- **Focus Not Obscured** — fokuserad rad i granskningskön/bevakningslistan får aldrig döljas av sticky filter-/deadline-strip.
- **Dragging Movements** — eventuell drag-and-drop av kort/widgets måste ha tangentbords-/knappalternativ (kanban-kärnan klarar inte detta som standard).
- **Accessible Authentication** — BankID/Freja-inloggning utan kognitiva test; LOA3 synligt markerad.
- **Consistent Help** + verbledda namn ("Begär komplettering", "Signera & delge") och tydliga statusord (GOV.UK-modellen). Tom-tillstånd som positivt, läsbart besked.
- Signeringsstegen (välj dokument → granska → legitimera → bekräfta) tangentbordsnavigerbara och skärmläsarvänliga — gäller även om en ställföreträdare/huvudman signerar externt.

**Sekretess, dataskydd, arkiv:**
- **OSL + GDPR:** Årsräkningar och verifikat rör en skyddsvärd huvudmans ekonomi och ofta hälsa (särskilda kategorier). All hantering sker i Hubs egen driftmiljö **på kundens servrar** — ingen extern part får informationen, vilket gör OSL 10:2a-lämplighetsbedömningen (eSam ES2023-06) och CLOUD Act-/tredjelandsfrågan överflödig. Visa "all data i er driftmiljö" i gränssnittet. **Dataminimering:** bevakningstitlar default till ärendereferens/huvudman-token, inte klartextcitat av känsliga uppgifter.
- **HSLF-FS 2016:40:** När läkarintyg/uppgifter om huvudmannens hälsa hanteras (godmanskapsutredning) — kryptering så bara avsedd mottagare läser + stark autentisering (LOA3) by design.
- **Säker medborgar-/ställföreträdardelning:** notis + BankID/Freja, aldrig hela ärenderummet — bara utvalda dokument; inget konto krävs. Mottagarmönstret kommuner och medborgare redan känner igen.
- **Arkivlagen + Riksarkivet:** håll isär (a) personliga, gallringsbara bevakningar och (b) ärendebundna allmänna handlingar som ska bevaras/diarieföras. Gallring **konfigurerbar per handlingstyp enligt kommunens dokumenthanteringsplan** (Retention-tagg, ägarnotis före radering). Signerade beslut bevaras i **PAdES/PDF/A med LTV + valideringsintyg** så bevisvärdet överlever certifikatets utgång; **FGS-export** till e-arkiv/Sydarkivera.
- **NIS2 / cybersäkerhetslagen (i kraft 15 jan 2026):** full loggning/spårbarhet på meddelanden, beslut, delningar och åtkomst. "Obesvarade säkra meddelanden > X dagar" och "ej kvitterade leveranser" blir internkontroll- och ledningsstöd, inte bara handläggarbekvämlighet. Kvalificerar som NIS2-/cybermiljard-åtgärd i budget-/bidragsmotivering 2026–2028.

---

## Källor (utöver grundanalyserna i `analysis-output/` och `analysis-output/extended/`)

- Regeringen, "Ett ställföreträdarskap att lita på" / "Tryggare ställföreträdarskap …" (prop., dec 2025; beslut flyttas till överförmyndaren i samförstånd, MFoF tillsynsvägledning, nationellt register + obligatorisk utbildning; ikraft 1 juli 2026 resp. 1 jan 2028): https://www.regeringen.se/pressmeddelanden/2025/12/tryggare-stallforetradarskap-och-starkt-sjalvbestammande-for-den-enskildes-basta/ · https://www.regeringen.se/pressmeddelanden/2025/11/ett-stallforetradarskap-att-lita-pa--reform-for-okad-trygghet-och-sjalvbestammande/
- JO, granskning av årsräkningar (rimlighetsbedömning, kritik mot dröjsmål): https://www.jo.se/besluten/nagot-om-overformyndares-granskning-av-arsrakningar-fran-stallforetradare-aven-kritik-mot-overformyndarnamnden-i-stockholms-stad-for-att-ha-drojt-med-sadan-kontroll/
- JO, kontroll av antal uppdrag per ställföreträdare: https://www.jo.se/besluten/om-overformyndarnas-kontroll-av-hur-manga-uppdrag-stallforetradare-har-aven-kritik-mot-overformyndarnamnden-i-stockholms-stad-for-brister-i-detta-avseende/
- Södertörns ÖFN, granskningsläget (mål 80 % per 30 juni, 7-månadersgräns, förtur): https://overformyndaren.haninge.se/om-oss/granskningslaget/
- Gävle, granskning av årsräkningar 2025 (komplettering, arvodesbeslut i anslutning till granskning): https://www.gavle.se/overformyndarnamnden/granskning-av-arsrakningar-for-2025/
- Stockholms stad, anvisningar för årsräkning/sluträkning (1 mars, originalunderskrift, verifikat): https://godman.stockholm/du-som-ar-god-man/redovisa-uppdrag/anvisningar/
- Provisum (Sambruk) — verksamhetsstöd, granskning, bevakning, ställföreträdar-e-tjänst, stickprov för fördjupad granskning: https://sambruk.se/overformyndare-provisum/ · https://overformyndarkansliet.se/du-som-ar-god-man/provisum-stallforetradare-sa-anvander-du-e-tjansten/
- Aider — överförmyndare, "Aider Bevakning/Tillsyn" (2025): https://support.aider.nu/sv/articles/6884612-overformyndare-och-aider
- Mölndal/ÖiS — ställföreträdaren väljer redovisningsform (papper/e-tjänst), årsräkning: https://www.molndal.se/startsida/omsorg-och-hjalp/god-man-forvaltare-och-formyndare/arsrakning.html · https://www.molndal.se/omsorg-och-stod/god-man-forvaltare-formyndare/aktuell-information-fran-overformyndare-i-samverkan
- Länsstyrelsen Stockholm — överförmyndartillsyn (ramverk): https://www.lansstyrelsen.se/stockholm/samhalle/livshandelser/overformyndare.html
- Föräldrabalken 14 kap. 15 § (årsräkning före 1 mars) och Förvaltningslagen (2017:900) 9, 11–12 §§ (frister/dröjsmål): https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/forvaltningslag-2017900_sfs-2017-900/
