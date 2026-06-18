<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Gap-analys — socialsekreterar-flödet: håller resonemanget eller finns det luckor?

> **Vad detta är:** ett prioriterat gap-register som samlar **varje** ⚠ LUCKA och ⚠ ANTAGANDE ur de fem
> walkthrough-dokumenten (Walkthrough 1–5) för socialsekreterar-personan. Detta är dokumentet som svarar på
> frågan: *fungerar resonemanget end-to-end, eller finns det luckor — och i så fall vilka är blockerande?*
>
> **Persona:** `socialsekreterare` (barn & familj) · **System of record:** Treserva/Lifecare/Viva/Combine →
> e-arkiv (Sydarkivera, FGS). **Datum:** 2026-06-14. **Plattform:** server v32 (Hub 25 Autumn).
>
> **Varje gap bär:** `id` · **var** (steg i master-walkthrough / källflöde) · **beskrivning** · **allvarlighet**
> (blocker / major / minor) · **vad som krävs för att lösa** · **typ** (teknisk = NC-app/integration, juridisk
> = OSL/arkivlag/IMY/delgivningslag, process = rutin/disciplin/utbildning/policy).
>
> **Allvarlighetsskala:**
> - **blocker** = stoppar skarp produktionskörning av flödet på riktiga klientärenden, eller skapar konkret
>   risk att en bevarandepliktig allmän handling/lagstadgad frist tappas (arkiv-/rättssäkerhetsbrott).
> - **major** = flödet är demobart men inte produktionsklart utan att gapet stängs; rätt-/dubbellagrings- eller
>   integrationsrisk som måste lösas per kund före skarp drift.
> - **minor** = friktion/UX-/driftdetalj som inte i sig fäller flödet men bör åtgärdas.

---

## Sammanfattande svar: håller flödet?

**Ja — resonemanget håller arkitektoniskt.** Mellanlagrings-/slutlagringsmodellen (Hubs stagar, facksystemet
äger originalet) är konsekvent genom alla 51 steg och alla fem flöden. **Men:** flödet är **demobart, inte
skarpt produktionsklart**, och det vilar på tre återkommande klasser av luckor:

1. **Facksystem-konnektorn (mönster A/B) är inte byggd.** Allt "för över till Treserva" är dag 1 **mönster D
   (manuell)** → "bekräftad överföring" är en kryssruta, inte ett API-svar. Detta gör en hel rad gallrings- och
   registreringsgarantier obekräftade (GAP-003, GAP-004, GAP-007, GAP-019, GAP-043, GAP-048, GAP-050).
2. **Skarp e-signering och skarp AI-på-klientsamtal saknar trust-ankaret.** LibreSign ≠ svensk myndighets-AES
   (kräver Inera/Sweden Connect); transkribering av sekretessbelagda klientsamtal ligger i "röd zon — kör inte
   skarpt än" (GAP-034, GAP-035, GAP-037, GAP-052).
3. **Flera arkiv-/sekretess-grindar vilar på handläggar-disciplin, inte systemkontroll** (skyddsbedömningens
   dokumentation, fristens startpunkt, maskering vid delning, gallra-vs-arkivera-valet, delgivningssättet) —
   GAP-001, GAP-002/046, GAP-017, GAP-038, GAP-050.

De **tre tyngsta blockerarna** (om man bara får lösa tre): **GAP-001** (skyddsbedömningens dokumentation),
**GAP-007** (gallring utan verifierad commit) och **GAP-034/052** (Inera-AES + röd-zon-AI). Allt annat hänger
på den centrala icke-byggda **Treserva-konnektorn** (GAP-019).

---

## Gap-registret (prioriterat: blocker → major → minor)

### Blockers

| id | Var (steg / flöde) | Beskrivning | Allvarlighet | Vad som krävs för att lösa | Typ |
|---|---|---|---|---|---|
| **GAP-001** | Steg 3 (WT1) — omedelbar skyddsbedömning | Var dokumenteras den omedelbara skyddsbedömningen "officiellt"? Ett tidskritiskt, dokumentationspliktigt moment riskerar att bara ligga i ett Hubs-Forms-svar/notering om varken ärenderum (Steg 6) eller aktualisering (Steg 9) ännu finns. **Känsligaste luckan i hela flödet.** | **blocker** | Tvinga aktualisering/överföring till facksystemet **före eller omedelbart efter** skyddsbedömningen; eller dokumentera skyddsbedömningen direkt i Treserva (mönster D/A) och låt Hubs bara vara inkommande-kanal. Kräver kanonisk regel i datamodellen + DHP. | juridisk + process |
| **GAP-007** | Steg 10/22/34/43/51 (WT1/2/3/4/5) — gallring | Gallring av Hubs-kopian triggar på **tagg + tid**, inte på **verifierad commit** till facksystemet. Vid mönster D är "bekräftad överföring" handläggarens egen kryssruta. Worst case: markerar "överförd" utan att ha registrerat → Hubs gallrar enda kopian → bevarandepliktig handling/frist finns ingenstans (arkiv-/offentlighetsbrott, arkivlagen 1990:782). | **blocker** | Mönster-A-återkoppling: gallra först N dgr **efter verifierad commit-händelse** från facksystemet. Tills A finns: säkerhetsmarginal (gallra inte vid "markera överförd"; kräv andra-handläggares bekräftan för fristbärande poster). | teknisk + juridisk |
| **GAP-019** | Steg 9/20/33/43/50 (alla flöden) — facksystem-överföring | **Ingen färdig Treserva-konnektor.** `arenderum`/`registreraFordela`/`motesanteckningar` är `proposed-integration`. Mönster A (öppet API) och B (drag-to-case) är inte implementerade; realistiskt dag-1-läge är **mönster D (manuell)**. Allt "Förd över"-status blir en obekräftad manuell markering. Detta är **den största icke-byggda biten** och grundorsaken bakom GAP-003/004/007/043/048/050. | **blocker** | Bygg signerings-/registreringsadapter med Treserva-/Lifecare-konnektor (Ena REST-profil); verifiera vilka operationer API:t exponerar (skapa aktualisering/bevakning, bifoga PDF, sätta sekretessmarkering) per kund; återkvittens till Hubs. | teknisk |
| **GAP-034** | Steg 37 (WT4) — signera myndighetsbeslut | **LibreSign-AES ≠ svensk myndighets-AES.** Starkaste interna externfaktor är SMS (NIST *restricted*); trust-ankare är självsignerad lokal rot-CA. Håller för internt "Godkänn", **inte** för ett överklagbart avslagsbeslut som ska stå sig i förvaltningsrätt. | **blocker** (för skarpa myndighetsbeslut) | Produktion **kräver** Inera Underskriftstjänst-API (mTLS/OOB + SITHS funktionscert, BankID/Freja/SITHS → PAdES) eller egen Sweden Connect-nod. Demo måste etikettera identiteten ärligt ("konto/SMS, ej BankID"). Se `SIGNING-INERA.md`. | teknisk + juridisk |
| **GAP-052** | Akt III hela (WT3, steg 26–31) — AI på klientsamtal | **Skarp drift på sekretessbelagt klientsamtal transkriberat med AI = röd zon.** Tekniskt demobart, men branschlinjen (Kalmar/IMY-dialog) är "compliance first" tills IMY/SKR/Socialstyrelsen gett tydlig vägledning. On-prem löser tredjelandsfrågan men inte hela OSL/arkiv-frågan. | **blocker** (för skarp körning på riktiga klientsamtal) | Avvakta IMY/SKR/Socialstyrelsen-vägledning; dokumentera/villkora; kör human-in-the-loop, samtycke, dataminimering, gallring, on-prem som inbyggda villkor. Status: **dokumentera, kör inte skarpt än.** | juridisk |
| **GAP-031** | Steg 34 (WT3) — Retention vs utlämnandebegäran | Den automatiska Retention-klockan för rå-WebM/-transkript måste kunna **pausas** vid en utlämnandebegäran (TF). En gallring får inte radera en handling som någon begärt ut innan begäran prövats. | **blocker** | Bygg "pausa Retention vid registrerad utlämnandebegäran"-hook; koppla till `utlamnande`-flödet. Konkret bygg-/policylucka. | teknisk + juridisk |

### Majors

| id | Var (steg / flöde) | Beskrivning | Allvarlighet | Vad som krävs för att lösa | Typ |
|---|---|---|---|---|---|
| **GAP-002 / GAP-046** | Steg 4 & 49 (WT1/WT5) — 14-dgr-klockans start | Var startar 14-dagars förhandsbedömnings-klockan? Juridiskt löper fristen från att anmälan **inkom** (Steg 1, JO-praxis), inte från tilldelning/plock. Persona-underlaget säger ibland "startar när hon plockar" — **fel referenspunkt** → falsk trygghet (dagar redan förbrukade). Motstridighet prosa vs juridik. | **major** | Bind klockan till sdkmc **inkom-datum**; plock påverkar bara *tilldelning*, inte fristen. Datamodell-fix. | teknisk + juridisk |
| **GAP-003** | Steg 9 (WT1) — tidpunkt för aktualisering | Dubbel/oklar tidpunkt: aktualisering kan ske vid skyddsbedömning (3), plock (4) eller beslut (8/9) beroende på kommun/facksystem. Utan **en** kanonisk punkt + "förd över"-status uppstår dubbelregistrering eller för sen registrering (registreringsplikt OSL 5:1 brusten). | **major** | Välj en kanonisk aktualiseringspunkt per kund och visa den i provenance-bandet; koppla till konnektor (GAP-019). | process + teknisk |
| **GAP-004** | Steg 9 (WT1) — mönster A overifierad | Treserva/Lifecare marknadsförs med "öppna API:er", men exakt vilka operationer (skapa aktualisering, bifoga PDF, sätta sekretessmarkering) som exponeras är **inte verifierat** → A blir i praktiken B/D tills byggt/testat per kund. | **major** | Verifiera API-kapabilitet per facksystem/kund; bygg mot Ena REST-profil. | teknisk |
| **GAP-005** | Steg 6/9 (WT1) — Hubs-token ↔ dnr-mappning | Ärenderum skapas före dnr finns → måste **återkopplas** till facksystemets dnr; mappningen (Hubs-token ↔ Treserva-dnr) är ospecificerad och en känd felkälla (fel rum mot fel dnr). Hanterar inte heller syskon-/familjefallet (1:n barn↔anmälan) entydigt. | **major** | Specificera token-/dnr-mappningsmekanism + 1:n-modell för barn/syskon i konnektordesignen. | teknisk |
| **GAP-012** | Steg 13/15/20 (WT2) + Steg 35 (WT4) — var skrivs texten | **Skrivs utredningen/beslutet i Hubs (Collabora) eller direkt i Treservas BBIC-/beslutsformulär?** De två modellerna är **ömsesidigt uteslutande**: on-prem-samredigering (Steg 15) + "committa texten" (Steg 20) förutsätter att texten skrivs i Hubs och flyttas. Om kommunen skriver direkt i Treserva används inte Hubs-samredigeringen — och "dubbel-författande" ger två divergerande versioner. Produkten har inte valt. | **major** | Produktbeslut + konnektordesign: antingen (a) exportera utkast ur Treserva, signera i Hubs, för tillbaka, eller (b) skriv i Hubs och committa. Får inte vila på användardisciplin. | process + teknisk |
| **GAP-017** | Steg 18 (WT2) — maskering vid säker delning | Ingen maskering/sekretessprövning av vad som får delas vid kommunicering. Handläggaren väljer filer och bedömer tredjemansuppgifter helt manuellt. **Att dela fel fil ur ett känsligt rum är flödets allvarligaste enskilda felrisk.** | **major** | Bygg maskerings-/sekretessprövningsstöd i `arenderum` (välj utvalda handlingar, varna för tredjemansuppgifter); tills dess: tydlig process + utbildning. | teknisk + juridisk + process |
| **GAP-018 / GAP-047** | Steg 19 & 49 (WT2/WT5) — dubbelbevakning 4-mån | Både Hubs och Treserva bevakar 4-månadersfristen → dubbel röd siffra, oklart vilken som gäller. Strikt enligt modellen borde fristen **ägas av Treserva och bara speglas/läsas** i Hubs (mönster A). Ingen läskonnektor → Hubs räknar egen frist som kan **divergera**. Förlängd frist (särskilda skäl) kan ge "falsk-röd" i Hubs medan Treserva har giltig förlängning. | **major** | Läskonnektor (mönster A) mot Treserva så fristen speglas, inte räknas självständigt; hantera förlängningsbeslut-synk. | teknisk |
| **GAP-038** | Steg 41 (WT4) — läskvittens ≠ delgivning | En teknisk "Läst"-notis är **bevisning**, inte automatiskt fullbordad delgivning per delgivningslagen (2010:1932). Vanlig delgivning kräver delgivningskvitto; förenklad delgivning kräver kontrollmeddelande + 2-veckorsfiktion + förhandsupplysning; Mina meddelanden/Kivra-delgivningens rättsläge (SOU 2024:47) måste verifieras. UI får inte påstå att en läsnotis fullbordar delgivning. | **major** | Modellera delgivningssätt (vanlig/förenklad/digital brevlåda) med tidsstyrt flöde (schemalägg kontrollmeddelande, starta 2-veckorsfiktion); juridisk verifiering per beslutstyp. Hubs **spårar** delgivningssätt, påstår inte delgivning. | juridisk + teknisk |
| **GAP-039** | Steg 42 (WT4) — fristens startpunkt vs delgivningssätt | 3-veckors överklagandefrist (FL 44 §) startar olika vid vanlig vs förenklad delgivning. Hubs måste härleda startdatum **ur valt delgivningssätt** (Steg 41↔42 hårt kopplade), annars blir fristen fel. | **major** | Härled fristens startdatum ur delgivningssättet (koppling steg 41→42). | teknisk + juridisk |
| **GAP-035** | Steg 38 (WT4) — LTV/tidsstämpel i LibreSign | LibreSign saknar robust LTV/kvalificerad tidsstämpel → "Giltig då" (validerbar efter cert-utgång) gäller realistiskt bara Inera/Sweden Connect-vägen. LibreSign-demons bevarandepanel får inte påstå LTV. | **major** | Inera/Sweden Connect för LTV; Hubs härdar PDF/A-1 + LTV efter PAdES-svar; etikettera demo ärligt. | teknisk + juridisk |
| **GAP-037** | Steg 40 (WT4) — svag gäst-/extern-identitet | LibreSign gästlänk + konto/SMS räcker för intern medsignering men **inte** för en extern part i ett myndighetsbeslut — där måste medsigneringen gå via Inera-AES. | **major** | Inera-AES för externa signatärer i myndighetsbeslut. | teknisk + juridisk |
| **GAP-040** | Steg 44 (WT4) — FGS-ansvarsgräns | Ansvarsgränsen Treserva→e-arkiv vs Hubs→e-arkiv är inte skarp. För beslut committade till Treserva bör e-arkivering ske *via Treserva*; Hubs FGS-export (C) är reserv för det som bara bor i Hubs. Risk för dubbelarkivering. | **major** | Definiera per kund i DHP + Treservas e-arkivkoppling; Hubs FGS-paketerar inte det som redan arkiveras via facksystemet. | process + teknisk |
| **GAP-006 / GAP-013** | Steg 7 & 14 (WT1/WT2) — fasvalidering | Ingen systemspärr mot att kontakta "fel" part (utomstående) under förhandsbedömningsfasen — den rättsliga begränsningen (endast vårdnadshavare/anmälare/barn) vilar på handläggarens kunskap. Datamodellen skiljer inte förhandsbedömnings- vs utredningsfas, så widgeten kan föreslå otillåten uppgiftsinhämtning för tidigt. | **major** | Lägg fas-attribut i datamodellen + statusbunden varning ("under förhandsbedömning: endast vårdnadshavare/anmälare/barn"). | teknisk + juridisk |
| **GAP-031b → GAP-043** | Steg 47 (WT5) — trippellagrad sekretess | Bilagan kan ligga i tre lager samtidigt (sdkmc + ärenderum + Treserva) under en period (dubbel-/trippellagrad sekretess). Löses principiellt med Retention "efter bekräftad överföring" — men tidpunkten är odefinierad vid mönster D. | **major** | Samma lösning som GAP-007 (verifierad commit innan gallring); tydlig lager-livscykel. | teknisk |
| **GAP-044** | Steg 48 & 50 (WT5) — riv-mekanism dubbelbevakning | Underlagen säger att Hubs inte ska dubblera Treservas fristbevakning, men **avaktiveringen av Hubs-påminnelsen när Treserva tagit över är inte byggd/specificerad** → två konkurrerande påminnelser med olika datum i övergångsfönstret. | **major** | Bygg regel: när Hubs-uppgift markeras "förd till facksystemet" avaktiveras kvarvarande VALARM/Deck-påminnelser så Treserva blir ensam fristägare. | teknisk |
| **GAP-045** | Steg 48 (WT5) — Deck-påminnelse-motor "proposed" | T-7/T-3/T-0-logiken som täcker Deck #1549/#566 är egen kod ovanpå native Deck och måste driftsättas; utan den faller Deck-vägen tillbaka på Decks bristfälliga avisering (till alla, ingen pre-deadline-puff). Tasks/VTODO-vägen fungerar native idag. | **major** | Driftsätt Hubs Deck-påminnelse-bakgrundsjobb; tills dess prioritera Tasks/VTODO för fristbärande påminnelser. | teknisk |
| **GAP-048** | Steg 50 (WT5) — mönster A för bevakningar overifierad | Treservas öppna API är dokumenterat för aktualisering/beslut, **inte** för att skapa **bevakningsposter** via API. Dag-1-verklighet: mönster D (manuell). Auto-sync av bevakningar Hubs→Treserva bör inte utlovas innan API-kapabilitet bekräftas per kund. | **major** | Verifiera bevaknings-API per facksystem; sälj inte auto-sync innan bekräftat. | teknisk |
| **GAP-049** | Steg 50 (WT5) — inget tvingar registrering | Inget i Hubs *tvingar* fram registreringen i Treserva — om handläggaren nöjer sig med Hubs-bevakningen lever fristen i ett icke-arkivpliktigt system (rättssäkerhets-/arkivrisk). | **major** | Provenance-chip "→ Treserva — ej registrerad" som **öppen åtgärd**; tom "ej registrerad"-kö som compliance-KPI. | process + teknisk |
| **GAP-016** | Steg 17/24 (WT2/WT3) — Forms-begränsningar | Forms saknar **native filuppladdning** och **förgrening** + ger inte BankID-signatur native. Räcker för enkelt samtycke, men inte om blanketten kräver bilaga/villkorslogik; "BankID-loggat signeringssteg på Forms-svar" förutsätter en bygd brygga Forms↔signeringsadapter. | **major** | Bygg Forms↔signeringsadapter-brygga; för fildropp/förgrening krävs annan e-tjänstlösning. | teknisk |
| **GAP-032** | Steg 35 (WT4) — dubbel-författande beslut | Beslut skrivet i Collabora *och* i Treserva-journalen → två divergerande versioner. (Specialfall av GAP-012 för beslutshandlingen.) | **major** | Renast: exportera utkast ur Treserva (A), signera i Hubs, för tillbaka. Lös i konnektordesign, inte användardisciplin. | teknisk + process |
| **GAP-033** | Steg 36 (WT4) — Inera mTLS vs OOB | Tidigare underlag säger Inera-API = mTLS; aktuell anslutningsguide indikerar att nya Underskriftstjänsten för **SITHS eID kräver OOB** (mTLS utfasad), medan funktionscert per system kvarstår. Påverkan på BankID/Freja-signering oklar. | **major** (konfig, inte arkitektur) | Verifiera exakt anslutningsprofil mot aktuell Inera-anslutningsguide vid implementation. Se `SIGNING-INERA.md`. | teknisk |
| **GAP-021** | Steg 23 (WT3) — oautentiserad bokningssida | Calendars publika bokningssida är **oautentiserad som standard** → vem som helst med länken kan boka tid hos en socialsekreterare. För känsliga ärenden krävs LOA3-lager framför / riktad privat länk. | **major** | Lägg LOA3/identitetslager framför Appointments eller använd riktad privat hemlig URL till redan identifierad part. | teknisk |
| **GAP-024** | Steg 25 (WT3) — BankID-lobby ej native | Lobbyns BankID-verifiering är ITSL:s spreed-itsl-fork (ID-core), **inte** native Talk. På ren v32 finns bara konto-/lösenordslobby. | **major** | Driftsätt ID-core-lobbyintegration; verifiera per miljö. | teknisk |
| **GAP-025** | Steg 26 (WT3) — recording server skör | Recording server kräver egen maskin (~4 kärnor/RAM per parallell inspelning), browser-baserad, sköraste komponenten. Utan den finns ingen inspelning → faller tillbaka på manuell anteckning. | **major** | Driftsätt + dimensionera recording server + HPB; ha manuell-anteckning-fallback. | teknisk |
| **GAP-029** | Steg 31 (WT3) — human-in-the-loop måste tvingas | Human-in-the-loop får inte degenerera till ett klick (tidspress + hallucinationsrisk → AI-fel committas till journalen, GDPR art. 22). | **major** | Visa transkript + utkast **sida vid sida**, kräv aktivt, loggat godkännande; tekniskt påtvingat, inget auto-commit. | teknisk + juridisk |
| **GAP-009** | Steg 5 (WT1) — historik vid triage utanför Hubs | "Se historik vid triage" (tidigare aktualiseringar/LVU) sker helt utanför Hubs idag (separat facksystem-inloggning, manuell context-switch). Gapet "inkorg↔facksystem" stängs men inte "historik i samma vy". | **major** | Läskonnektor (mönster A) mot Treservas API ("tidigare aktualisering: ja/nej"-chip); rättsligt beroende av behörighet att läsa facksystemet via Hubs. | teknisk + juridisk |
| **GAP-041** | Steg 45 (WT5) — ConversationId→dnr-mappning | "Kopplar dnr" förutsätter en mappning mellan sdkmc-meddelandets referens och ärendet i Treserva. Mappningsmekanismen är inte specificerad → ärendekopplingen blir ett fritextfält. | **major** | Specificera ConversationId↔dnr-mappning i summary-endpoint/datamodell. | teknisk |

### Minors

| id | Var (steg / flöde) | Beskrivning | Allvarlighet | Vad som krävs för att lösa | Typ |
|---|---|---|---|---|---|
| **GAP-008 / GAP-026** | Steg 10/22/34 (WT1/2/3) — gallringsbeslut i DHP | En rensning av Hubs-mellanlagring (inkl. rå-inspelning/-transkript som potentiella allmänna handlingar) kräver ett **dokumenterat gallringsbeslut** i kommunens dokumenthanteringsplan; utan det är även en kort rensning formellt en oreglerad gallring (arkivlagen). | **minor** (men förankringskrav) | Förankra uttrycklig rensningsregel för Hubs som mellanlagring + handlingstypen "rå mötesinspelning, arbetsmaterial" i kommunens DHP. | juridisk + process |
| **GAP-010** | Steg 11 (WT2) — orkestrering ej "ett klick" | "Ett klick → Groupfolder + ACL + tagg + BBIC-struktur i rätt ordning" är inte färdigbyggt; idag flera manuella admin-steg. | **minor** | Bygg orkestreringslagret för ärenderum-skapande. | teknisk |
| **GAP-011** | Steg 13 (WT2) — BBIC-licens | BBIC är upphovsrättsskyddat/licensierat av Socialstyrelsen; att replikera BBIC-mallar i Collectives kan kräva licens/överenskommelse. | **minor** | Klargör licens/överenskommelse med Socialstyrelsen innan BBIC-mallar replikeras on-prem. | juridisk |
| **GAP-014** | Steg 15/27 (WT2/WT3) — Collabora/WOPI-beroende | On-prem samredigering förutsätter Collabora/OnlyOffice (WOPI) driftsatt (Nivå 2-backendberoende). På ren v32 utan WOPI degraderas steget till "ladda ner/redigera/ladda upp" → bryter samredigerings- + versionslöftet. | **minor** (driftantagande) | Säkerställ Collabora/OnlyOffice WOPI driftsatt; annars degraderad fallback. | teknisk |
| **GAP-015 / GAP-020** | Steg 16/21 (WT2) — gallringsbedömning per handling | Gränsdragningen utkast/arbetsmaterial vs upprättad allmän handling (TF 2 kap.) görs av handläggaren utan stöd; fel åt båda håll skapar arkiv-/offentlighetsproblem. Versionshistorikens gallring måste förankras i DHP. | **minor** | Checklista/automatik per handlingstyp ("bevaras/gallras"); förankra i DHP + utbildning. | process + juridisk |
| **GAP-022** | Steg 23 (WT3) — auto-Talk-rum versionkänsligt | Auto-Talk-rum vid Appointments-bokning är funktionellt men rapporterat versionskänsligt (calendar #3480/#3581). I prod-demo bör rummet skapas explicit om auto-skapande inte slår till. | **minor** | Explicit rum-skapande som fallback; verifiera mot v32-instans. | teknisk |
| **GAP-023** | Steg 24 (WT3) — samtycke ≠ rättslig grund | Samtycke åberopas som transparens/förtroende, men rättslig grund är myndighetsutövning (inte GDPR art. 6.1.a); samtycket häver inte sekretess (OSL/HSLF-FS 2016:40 kan inte avtalas bort). UI får inte ge sken av att "kryssa i samtycke" gör allt lagligt. | **minor** (UI-/formuleringskrav) | UI-formulering: samtycket dokumenterar *information*, inte hävd sekretess/rättslig grund. | juridisk |
| **GAP-028** | Steg 30 (WT3) — AI-hallucination | AI kan missa nyanser eller hitta på → därför är human-in-the-loop (GAP-029) obligatoriskt. | **minor** (mitigeras av GAP-029) | Promptmall för svenskt myndighetsformat; obligatorisk granskning (GAP-029). | teknisk + process |
| **GAP-030** | Steg 27 (WT3) — ingen svensk live-textning | `live_transcription`/Vosk saknar svenska → ingen textremsa under mötet (bara efterhands-Whisper). Tillgänglighetsvärdet (undertext, WCAG/DOS-lagen) uteblir. | **minor** | Avvakta svensk streaming-STT; använd KB-Whisper efterhand. | teknisk |
| **GAP-036** | Steg 39 (WT4) — kravnivå-mappning odefinierad | Mappningen beslutstyp → kravnivå (AES vs "Godkänn" vs QES) är en policy-/juristfråga (SKR:s riskmodell), inte specificerad i underlaget; måste tas fram per kund. Hubs visar badge, gissar inte. | **minor** (per-kund-policy) | Ta fram beslutstyp→kravnivå-matris per kommun (kommunjurist/nämnd). | process + juridisk |
| **GAP-042** | Steg 46 (WT5) — disciplinval personlig vs delad | Att handläggaren väljer rätt mellan personlig VTODO och delad Deck-board är en UX-/disciplinfråga; allt-personligt → teamet blint vid frånvaro. | **minor** | Föreslå delad board som default för fristbärande bevakningar. | process + teknisk |
| **GAP-050** | Steg 51 (WT5) — gallra-vs-arkivera förståelse | Att handläggaren förstår "gallra vs för till ärendet" vid varje klarmarkering är en utbildnings-/UX-fråga; fel default = systematiskt arkivfel. (Den juridiskt känsligaste interaktionen; den tekniska risken ligger i GAP-007.) | **minor** (process-sidan) | Tydlig default + formulering + utbildning; teknisk sida = GAP-007. | process + juridisk |
| **GAP-051** | Steg 51 (WT5) — ACL-granularitet enhetsvy | "Vem ser vems bevakning" på enhetsnivå är en OSL-åtkomstgräns (`IConditionalWidget`), men granulariteten per barn/ärende är inte detaljbeskriven → risk att enhetsvyn exponerar sekretess till fel handläggare om board-ACL slarvas. | **minor** | Specificera board-/ärende-ACL-granularitet för "Enhetens bevakningar". | teknisk + juridisk |
| **GAP-053** | Steg 1 (WT1) — anonym/overifierad avsändare | Anonym orosanmälan (privatperson får vara anonym) saknar verifierad avsändaridentitet. Provenance-bandet måste kunna visa "avsändare ej verifierad/anonym" som ett **legitimt** tillstånd, inte ett fel. "Verifierad LOA3-avsändare" gäller bara SDK-org-cert/BankID-signerad securemail — inte anonym fax/papper. | **minor** | Modellera "ej verifierad/anonym" som legitimt provenance-tillstånd i sdkmc. | teknisk + juridisk |
| **GAP-054** | Steg 4 (WT1) — routing-regel funktionsadress | Antagande att Hubs känner till vilken handläggargrupp/enhet som äger funktionsadressen så att "plocka" bara kan göras av behörig. Om flera enheter delar adress krävs routing-regel. | **minor** | Routing-/behörighetsregel per funktionsadress vid delad adress. | teknisk |
| **GAP-055** | Steg 3 (WT1) — skyddsbedömningens tidsstämpel | Antagande att "samma dag"-tidsstämpeln beräknas från **inkom-datum** (provenance), inte från när handläggaren öppnar raden — annars kan en sent inkommen anmälan ha brutit fristen utan att klockan visat det. (Samma rotorsak som GAP-002.) | **minor** | Bind skyddsbedömnings-tidsstämpeln till inkom-datum. | teknisk + juridisk |

---

## Gap per typ (för planering)

- **Tekniska (NC-app/integration) — tyngst:** GAP-019 (Treserva-konnektor, grundorsak), GAP-007 (verifierad
  commit), GAP-005, GAP-009, GAP-018/047, GAP-041, GAP-044, GAP-045, GAP-048, GAP-016, GAP-021, GAP-024,
  GAP-025, GAP-010, GAP-014, GAP-022. Plus signeringsteknik: GAP-034, GAP-033, GAP-035, GAP-037.
- **Juridiska (OSL/arkivlag/IMY/delgivningslag):** GAP-001 (skyddsbedömning), GAP-052 (röd-zon-AI), GAP-031
  (Retention vs TF), GAP-038 (delgivningslagen), GAP-039, GAP-008/026 (gallringsbeslut DHP), GAP-011 (BBIC),
  GAP-023 (samtycke ≠ rättslig grund), GAP-040 (FGS-gräns), GAP-002/046 (fristens start), GAP-036 (kravnivå).
- **Process (rutin/disciplin/policy):** GAP-003 (kanonisk aktualiseringspunkt), GAP-012/032 (var skrivs
  texten), GAP-017 (maskering), GAP-049/050 (registrering/gallra-disciplin), GAP-042 (delad-board-default),
  GAP-015/020 (gallringsbedömning), GAP-036.

---

## Bottom line

Resonemanget **håller** — men för att gå från demo till skarp produktion på riktiga barnärenden måste minst de
sex blockerarna stängas: **bygg Treserva-konnektorn med verifierad commit-återkoppling** (GAP-019 + GAP-007),
**bind skyddsbedömningens dokumentation och fristens start till facksystemet/inkom-datum** (GAP-001 + GAP-002),
**använd Inera-AES för myndighetsbeslut** (GAP-034) och **avvakta IMY-vägledning innan AI körs skarpt på
klientsamtal** (GAP-052), samt **bygg Retention-paus vid utlämnandebegäran** (GAP-031). Resten är majors/minors
som kan lösas per kund i konnektordesign, DHP-förankring och UX.

---

*Källor: samtliga ⚠ LUCKA/⚠ ANTAGANDE i `analysis-output/extended/walkthrough-1…5`,
`persona-usage-socialsekreterare.md`, `hubs_start/docs/WIDGET-APP-MAP.md`, `signing-apps-eval.md`,
`signing-inera-api.md`. Rättsliga referenser per gap finns i respektive källflödes "Källor"-sektion.*
