# Persona-dashboard: Förvaltare / IT / informationssäkerhet (`forvaltare`)

*Personaliserad förstavy i Hubs (ITSL) — den Nextcloud-baserade säkra kommunikationssviten för svensk offentlig sektor. Brand-regel: i produktnära/UI-text säger vi aldrig "Nextcloud" eller "Talk" — vi säger "Hubs", "säker kanal", "säkert videomöte", "säker dokumentyta". Plattformsnamn används bara i interna byggnoteringar. Datum: 2026-06-13.*

> Bygg-koppling: rendera som rollvy i `hubs_start`-appen (Vue 2.7 + @nextcloud/vue v8), härled rollen från grupp `forvaltning`/`infosak`/`it-drift`/`ledning`, registrera kompletterande kort via `IAPIWidgetV2`/`IConditionalWidget`. Allt data härleds ur Hubs egna kanaler via sdkmc-aggregeringsendpointen — compliance-vyn ska *inte* bli ett tomt GRC-skal som kräver manuell inmatning. Det är differentieringen mot Secify/Secure State Cyber/Microsoft Purview: **operativ kommunikationsdata → automatisk efterlevnadsbild**.

---

## Persona & en dag i arbetet

**Vem:** Informationssäkerhetssamordnare / CISO / IT-förvaltare / systemförvaltare för Hubs i en kommun eller region. I små kommuner är detta ofta *en* person som bär flera hattar (säkerhetssamordnare + systemägare + den som rapporterar uppåt till nämnd/förvaltningsledning). I större organisationer är det ett team där CISO äger compliance, en driftförvaltare äger systemhälsa/provisionering, och dataskyddsombudet (DSO) äger gallring/åtkomstlogg.

**Vad som styr deras vardag 2026 (det dashboarden måste svara mot):**
- **Cybersäkerhetslagen (2025:1506)** trädde i kraft **15 januari 2026** och genomför NIS2. Sverige gick längre än direktivet — *alla* kommuner och regioner omfattas oavsett storlek. Förvaltaren förbereder organisationen inför att de tunga föreskrifterna börjar gälla: **incidentrapportering och informationsskyldighet ~1 juli 2026**, **säkerhetsåtgärder/utbildning samt säkerhetsrevision/säkerhetsskanning preliminärt ~1 oktober 2026**.
- **Ledningen har personligt ansvar.** Förvaltaren producerar underlaget som nämnd/förvaltningsledning behöver för att kunna godkänna, följa upp och utbilda sig — annars riskerar ledningen sanktionsavgift och i värsta fall förbud att utöva ledningsfunktion. Förvaltaren är "den som måste kunna visa att arbetet faktiskt bedrivs".
- **Incidentrapportering till MCF är en tidsstyrd, nedräknande skyldighet** (24 h tidig varning → 72 h incidentanmälan → lägesrapport/slutrapport inom en månad). Innan MCF:s nya rapporteringstjänst är live går det via e-tjänsten IRON eller blankett.
- **SDK-loggkravet (Digg): minst 12 månader, i läsbar/sökbar form.** Förvaltaren måste kunna bevisa retention och ta fram loggrader mot AS4 Message/Conversation ID vid felsökning och tillsyn.
- **Compliance-fönstret + pengarna:** cybermiljarden ger kommuner **200 mkr/år** och regioner **50 mkr/år** 2026–2028 öronmärkt för NIS2/motståndskraft. Förvaltaren ska kunna motivera Hubs som en kvalificerande NIS2-åtgärd i budget-/bidragsäskandet — och visa ROI.
- **Mognadsluckan:** Infosäkkollen visar att endast ~31 % av offentliga organisationer når någon mognadsnivå alls (69 % saknar grundläggande systematiskt arbete; endast 4 av 120 statliga myndigheter når MSB:s föreskriftsnivå). Förvaltaren behöver verktyg som *fyller i* självskattningen med riktig data, inte ännu ett system att mata.

**En typisk dag (kronologiskt — så ska vyn stötta):**
1. **08:00 — Lägeskoll.** Loggar in på LOA3 (BankID/Freja/SITHS). Översta kortet svarar på "är något på fel": öppna säkerhetshändelser, eventuella incidenter med tickande MCF-klockor, systemhälsa (accesspunkt/SDK-anslutning uppe?), ej hanterade säkra meddelanden över tröskel.
2. **08:30 — Incidenttriage (om något hänt).** En misslyckad-inloggning-spik eller en utomgruppsdelning eskaleras till incident; nedräkningsklockan för 24 h tidig varning startar; rapportgeneratorn förfylls.
3. **10:00 — Provisionering & behörighet.** Ny socialsekreterare ska in i funktionsadressen `orosanmalan@`, en avslutad medarbetare ska avetableras (avstängd åtkomst = compliance-händelse). Kontroll att MFA/LOA3-täckning är 100 %.
4. **13:00 — Gallring & arkiv.** Genomgång av retention: vilka ärenderum/loggar gallras enligt dokumenthanteringsplan, vad ska bevaras/levereras till e-arkiv (FGS). Kontroll att inget gallras som ska bevaras och tvärtom.
5. **14:30 — Loggning & tillsynsunderlag.** Söker i SDK-loggen mot ett AS4 Message ID för en handläggares felsökning; exporterar åtkomstlogg ("vem har sett vad") för ett DSO-ärende.
6. **16:00 — Rapportera uppåt.** Sammanställer compliance-status och "nytta hittills" (ersatta fax/brev × Diggs 30-min-schablon) till nästa nämndmöte / cybermiljards-äskande.

**Personans mentala modell:** "Jag måste alltid kunna svara på tre frågor — *Är vi säkra just nu? Kan vi bevisa att vi följer lagen? Är det värt pengarna?*" Dashboarden ska besvara dem i den ordningen, utan att förvaltaren behöver öppna sju system.

---

## Mål & nyckeltal (KPI)

Princip: **task- och statusorienterat, inte grafdumpar.** Mät *tid-till-åtgärd* och *bevisbar efterlevnad*, inte "tid på dashboarden". Varje KPI ska härledas automatiskt ur Hubs kanaler och mappas mot ett myndighetsramverk (cybersäkerhetslagen, Infosäkkollen-nivåer, SDK-regelverket, GDPR art. 5/30/32).

| # | KPI | Definition / mål | Källa & ramverk |
|---|---|---|---|
| K1 | **Compliance-status (sammanvägd)** | Grön/gul/röd mot kravområdena: anmäld till MCF, incidentrutin aktiv, loggretention 12 mån, MFA/LOA3 100 %, data i egen miljö, ledningsgenomgång daterad. Mål: grön på alla. | Cybersäkerhetslagen 2025:1506; mappad mot Infosäkkollens 4-gradiga skala (mål ≥ nivå 3) |
| K2 | **Öppna säkerhetshändelser → incidenter** | Antal öppna händelser; antal eskalerade till incident; **andel MCF-deadlines hållna** (24 h/72 h/1 mån). Mål: 0 missade deadlines. | MCF incidentrapportering; PTS rapporteringsväg |
| K3 | **SDK-loggretention & sökbarhet** | Faktisk retention vs. krav (mål: **12/12 mån, sökbar**). Antal lyckade logguppslag mot AS4 ID. | Digg SDK-regelverk + Bilaga IT-säkerhet (AS4 Message/Conversation ID) |
| K4 | **Autentisering / tillitsnivå** | Andel sessioner på LOA3, MFA-täckning (mål 100 %), antal inloggningar under tröskelnivå (avvikelse). | Diggs tillitsramverk LOA2–4; HSLF-FS 2016:40 |
| K5 | **Systemhälsa & leveranssäkerhet** | Accesspunkt/SDK-anslutning upptid; antal meddelanden i fellager; **ej hanterade/ej kvitterade säkra meddelanden över X dagar**. | NIS2 kontinuitet; SDK leveranskvittens |
| K6 | **Datasuveränitet** | Antal tredjelandsöverföringar (mål: **0**); senaste externa åtkomst; andel data i egen driftmiljö (mål 100 %). | OSL 10:2a + eSam ES2023-06; GDPR tredjeland |
| K7 | **Gallring & arkiv** | Antal objekt med satt gallringsregel vs. utan; kommande gallringar (countdown); antal FGS-leveranser. | Arkivlagen 1990:782; arkivförordningen (2024-kravet export+radering); RA-FS |
| K8 | **Åtkomst & delning (spårbarhet)** | Antal delningar utanför org, exporter, åtkomst till handlingar med utökat skyddsbehov — allt loggat & sökbart. | GDPR art. 30/32; SDK-loggkrav |
| K9 | **Nytta / ROI** | Ersatta fax + rek-brev + okrypterad e-post × **~30 min/ärende** → sparad tid & frigjorda årsarbetskrafter; relaterat till sektorsnyttan ~1 620 mnkr/år. | Diggs schablon; Inera/Sigtuna-kalkyl (break-even år 3) |
| K10 | **Riskreduktion** | Felskickade-fax-tillfällen undvikna (verifierad funktionsadress vs faxnummer); sekretessmeddelanden som *inte* gick okrypterat. | NIS2 riskbaserat arbete; VGR faxrisk |
| K11 | **Provisioneringshygien** | Tid från anställning→åtkomst och avslut→avetablering; antal vilande/överbehöriga konton. | NIS2 åtkomststyrning; GDPR dataminimering |

---

## Primära åtgärder (verb-först)

De 4–5 åtgärder förvaltaren oftast tar — varje med vilken Hubs-funktion/app som utför den. Exponeras både som knappar på relevanta kort (Card View) och i **Ctrl/Cmd+K command palette** (rollfiltrerade).

1. **Eskalera till incident & starta MCF-klockan** — gör en säkerhetshändelse till en incident, vilket startar nedräkningsklockorna (24 h/72 h/1 mån) och förfyller rapportgeneratorn. *Funktion:* Incidenthantering (sdkmc säkerhetshändelse-feed → incidentmodul). 
2. **Generera MCF-rapportunderlag** — bygg utkast till tidig varning / incidentanmälan / läges-/slutrapport från samlade händelser, exportera till IRON/blankett. *Funktion:* MCF-rapportgenerator (compliance-modul).
3. **Sök i SDK-loggen / exportera åtkomstlogg** — slå upp en meddelandeöverföring mot AS4 Message/Conversation ID, eller ta fram "vem har sett vad" för ett DSO-/tillsynsärende. *Funktion:* Logg- & spårbarhetspanel (sdkmc loggindex; säker dokumentyta händelselogg).
4. **Provisionera / avetablera användare & funktionsadress** — lägg till handläggare i en funktionsbrevlåda, sätt rätt LOA-krav, eller stäng av en avslutad medarbetare (loggas som åtkomsthändelse). *Funktion:* Användar-/grupphantering + funktionsadress-administration.
5. **Sammanställ nytta & efterlevnad för ledningen** — exportera "NIS2-åtgärd: kostnad vs nytta vs efterlevnad" + compliance-status till nämndunderlag/cybermiljards-äskande. *Funktion:* Nytto-/ROI-kort + compliance-export.

---

## Widgetar (ordnad lista)

Layout: **låst kärna** (compliance-/säkerhetskritiska kort rollen alltid ser) överst, **kuraterat skal** (vitlistat, omordningsbart med knappar — inte bara drag, WCAG 2.5.7) under. Default 5–7 kort, progressive disclosure (summeringskort → Quick View → full vy). Statusmodell genomgående GOV.UK-stil: *Klar / Påbörjad / Väntar / Problem*.

| Ordning | id | Titel | Syfte | Datakälla [befintlig/föreslagen] | App/funktion |
|---|---|---|---|---|---|
| 1 (kärna) | `compliance-status` | **Efterlevnad — cybersäkerhetslagen** | Sammanvägd grön/gul/röd mot kravområden (anmäld MCF, incidentrutin, logg 12 mån, MFA/LOA3, data i egen miljö, ledningsgenomgång). Quick View = kravlista med status per punkt, mappad mot Infosäkkollen-nivåerna. | Föreslagen (härleds ur befintliga signaler: logg, auth, SDK-status) | Compliance-modul (ny i sdkmc/hubs_start) |
| 2 (kärna) | `incident-mcf-clock` | **Incidenter & MCF-frister** | Triagekö över öppna säkerhetshändelser/incidenter med **nedräkningsklockor** (24 h tidig varning / 72 h anmälan / 1 mån läges-/slutrapport) i färg + ej-bara-färg-markör. Verb-knappar: "Skicka tidig varning", "Komplettera anmälan". | Föreslagen (klock-logik) ovanpå befintlig händelse-feed | Incidenthantering |
| 3 (kärna) | `security-event-feed` | **Säkerhetshändelser** | Aggregerar verksamhetsnära signaler ur Hubs kanaler: misslyckade inloggningar, utomgrupps-/avvikande delningar, meddelande till oväntad funktionsadress, inloggning under tröskel-LOA. Knapp "Eskalera till incident" förfyller #2. | Befintlig (auth-logg, delningslogg, SDK-routing) + föreslagen aggregering | sdkmc säkerhetshändelse-feed |
| 4 (kärna) | `log-retention` | **Loggretention & spårbarhet (SDK 12 mån)** | Visar **SDK-loggretention 12/12 mån, sökbar** som grön bock (Diggs krav). Sökruta mot AS4 Message ID/Conversation ID, avsändare/mottagare, tidpunkt — *utan* meddelandeinnehåll (per Digg). Tillsyn + felsökning i ett. | Befintlig (SDK-logg krävs redan) + föreslaget sökindex/UI | Logg- & spårbarhetspanel |
| 5 (kärna) | `auth-loa` | **Autentisering & tillitsnivå** | Andel sessioner på LOA3 (BankID/Freja/SITHS), MFA-täckning, "eIDAS2/EUDI-redo"-markör, lista över inloggningar under tröskelnivå. HSLF-FS 2016:40-bevis. | Befintlig (sessionsdata) + föreslagen sammanställning | ID-core / auth |
| 6 | `system-health` | **Systemhälsa & leverans** | Accesspunkt/SDK-anslutning upptid, meddelanden i fellager, ej kvitterade leveranser, ej hanterade säkra meddelanden över X dagar (internkontroll). | Befintlig (SDK accesspunkt-status, kvittens) | sdkmc driftstatus |
| 7 | `provisioning` | **Användare & funktionsadresser** | Provisioneringskö: nya som ska in, avslutade som ska av, vilande/överbehöriga konton, funktionsadressmedlemskap. Verb-knappar "Lägg till i funktionsadress", "Avetablera". | Befintlig (grupp-/användarhantering) + föreslagen hygien-vy | Användar-/grupphantering |
| 8 | `retention-archiv` | **Gallring & arkiv** | Kommande gallringar (countdown) per handlingstyp enligt dokumenthanteringsplan, objekt utan gallringsregel (varning), FGS-leveransstatus. "Leverera till e-arkiv". | Befintlig (Retention-app, taggar, versioner) + föreslagen översikt | Säker dokumentyta / Retention |
| 9 | `data-sovereignty` | **Datasuveränitet** | "All data i er driftmiljö. 0 tredjelandsöverföringar. Senaste externa åtkomst: ingen." Svar på OSL 10:2a + CLOUD Act-oron, visualiserat. | Föreslagen (statisk + åtkomstlogg-härledd) | Compliance-modul |
| 10 | `validity-archive` | **Underskrifter — Giltig nu / Giltig då** | Per arkiverat signerat dokument: signaturnivå (SES/AES/QES), tidsstämpel, **LTV-status**, valideringsintyg; knapp "Verifiera underskrift nu". Revisions-/överklagandebevis. | Föreslagen (PAdES/PDF/A + LTV ovanpå Inera Underskriftstjänst / Sweden Connect-nod) | E-underskrift / bevarande |
| 11 | `nytta-roi` | **Nytta hittills (ROI)** | Ersatta fax/rek-brev/okrypterad e-post × ~30 min → sparad tid & årsarbetskrafter; relaterat till ~1 620 mnkr/år; faxavvecklingskurva (andel fax vs SDK/månad). | Befintlig (kanalstatistik) + föreslagen schablonberäkning | Nytto-/ROI-kort |
| 12 | `cybermiljard-export` | **NIS2-åtgärd: kostnad/nytta/efterlevnad** | Paketerar #1, #9, #11 till exporterbar sammanställning för cybermiljards-/budgetäskande och nämndprotokoll. | Föreslagen | Compliance-export |

**Tre mest distinktiva (störst köp-/demovärde, lägst byggkostnad — prioritera till första leverans):** `incident-mcf-clock`, `log-retention`, `nytta-roi`.

---

## Föreslagna appar/moduler (befintlig vs föreslagen + motivering)

| Modul/app | Status | Motivering |
|---|---|---|
| **SDK-/säker e-post-/fax-kanaler (sdkmc, securemail, mail)** | Befintlig | Är källan till nästan all compliance-data — loggar, kvittenser, kanalstatistik, säkerhetshändelser. Compliance-vyn *härleds* ur dessa, inte ur manuell inmatning. |
| **Logg- & spårbarhetspanel (sök mot AS4 ID, åtkomstlogg-export)** | Föreslagen (ovanpå befintlig SDK-logg) | SDK kräver redan 12 mån sökbar logg — bygg sök-UI:t och åtkomstloggen så att teknisk efterlevnad blir ett *synligt* säljargument (grön bock) och DSO/tillsyn kan självbetjäna. |
| **Compliance-/NIS2-modul (status, MCF-klockor, rapportgenerator, suveränitetskort)** | Föreslagen | Kärnan i personan. Mappar mot cybersäkerhetslagen + Infosäkkollen; producerar MCF-rapportunderlag. Differentiering mot fristående GRC (Secify/Purview): operativ data → automatisk efterlevnadsbild. |
| **Användar-/funktionsadress-provisionering med hygien-vy** | Befintlig (grupp-/användarhantering) + föreslagen vy | Provisionering/avetablering är NIS2 åtkomststyrning *och* daglig förvaltarsyssla. SKR:s 2025-rekommendation om funktionsadresser gör delade brevlådor till förstaklassobjekt. |
| **Retention/gallring + FGS-export (säker dokumentyta)** | Befintlig (Retention-app, taggar, versioner) + föreslagen översikt | Arkivförordningens 2024-krav (export+radering före införande) är ett *upphandlingskrav*. Konfigurerbar gallring per handlingstyp + FGS till Sydarkivera/e-arkiv. |
| **ID-core / auth-tillitsnivåvy** | Befintlig (LOA3-inloggning) + föreslagen sammanställning | Leverantörsoberoende identitetsabstraktion (BankID/Freja/SITHS nu; Sverige-id dec 2026 + EUDI-wallet inkopplingsbara). "eIDAS2-redo" som demonstrerbart upphandlingsargument. |
| **E-underskrift med bevarande (PAdES/PDF/A/LTV)** | Föreslagen (stå på Inera Underskriftstjänst / Sweden Connect-nod, bygg inte kryptokärnan) | "Giltig nu/Giltig då"-panelen är den funktion ingen konkurrent gör tydligt; ger revisions-/överklagandebevis och knyter signering till compliance-fönstret. |
| **Lokal AI-assistans (prioriteringsförslag, händelse-sammanfattning)** | Föreslagen, *valfri & avstängbar* | Endast lokala, grön-ratade modeller (`llm2`, t.ex. OLMo 2). Får *föreslå* ordning/sammanfatta händelser, aldrig dölja/avföra eller profilera användaren (GDPR art. 22). Vänder suveränitetskravet till differentiator: "AI utan att data lämnar er server". |
| **Kunskapsbank (Collectives)** | Befintlig | Gallringsplaner per handlingstyp, incidentrutiner, MCF-rapportmallar, lathundar — on-prem "internt stöd" som minskar kognitiv belastning (Arbetsmiljöverket/Suntarbetsliv-argumentet). |

---

## Terminologi (persona-anpassade ord)

Förvaltaren talar säkerhets- och förvaltningsspråk, inte handläggarspråk. UI-copy ska kännas igen från MSB/MCF, Digg och eSam.

| I UI (säg) | Inte (undvik) | Varför |
|---|---|---|
| **Säkerhetshändelse** → **Incident** | "Larm", "fel" | Speglar cybersäkerhetslagens begrepp; "incident" har en juridisk innebörd (rapporteringsplikt). |
| **Tidig varning / Incidentanmälan / Lägesrapport / Slutrapport** | "Rapport 1/2/3" | Exakt MCF:s rapportkedja — igenkänning + rätt frist per typ. |
| **Tillitsnivå (LOA3)** | "Säker inloggning" | Diggs tillitsramvärksspråk; mätbart och upphandlingsbart. |
| **Loggretention / spårbarhet** | "Historik" | Digg/SDK-regelverkets ord; signalerar 12-månaderskravet. |
| **Funktionsadress / funktionsbrevlåda** | "Delad mejl" | SKR:s 2025-rekommendation; förstaklassbegrepp. |
| **Gallring / bevarande / FGS-leverans** | "Radera / arkivera" | Arkivlagens ord; gallring ≠ radering juridiskt. |
| **Datasuveränitet / i er driftmiljö** | "I molnet", "hos oss" | OSL 10:2a / CLOUD Act-argumentet; "er server" är poängen. |
| **Avetablering / provisionering** | "Ta bort konto" | Åtkomststyrningsspråk (NIS2). |
| **Nytta / frigjord tid / årsarbetskrafter** | "Besparing" | Diggs nyttoschablon-språk; matchar ROI-äskandet. |
| **Säker kanal** | "Krypterad chatt", aldrig "Talk"/"Nextcloud" | Brand-regel + HSLF-FS 2016:40-budskap. |

Genomgående UI-copy-block (synligt i vyn): *"All data i er driftmiljö"*, *"Inloggning på tillitsnivå 3"*, *"Krypterat till endast avsedd mottagare (HSLF-FS 2016:40)"*, *"eIDAS2-redo"*, *"SDK-loggretention 12/12 mån — sökbar"*.

---

## Flöden (end-to-end)

### Flöde 1: Upptäck säkerhetshändelse → eskalera → rapportera till MCF i tid
1. **Upptäck:** `security-event-feed` visar en spik av misslyckade inloggningar mot en funktionsadress + en delning till en extern mottagare utanför grupp. Lokal AI flaggar mönstret (transparent, "föreslagen hög prio: avvikande delning + auth-spik").
2. **Bedöm:** förvaltaren öppnar Quick View, ser berörda konton/ärenden, bedömer att det kan vara en betydande incident.
3. **Eskalera:** klick "Eskalera till incident" → `incident-mcf-clock` skapar incidenten, **24 h-klockan för tidig varning startar** (synlig nedräkning + ej-bara-färg-markör), rapportgeneratorn förfylls med tidpunkt, berörda system, initial bedömning.
4. **Tidig varning (≤24 h):** "Skicka tidig varning" → MCF-rapportgenerator producerar utkast enligt MCF:s mall, exporteras till IRON/blankett. Klockan kvitteras grön.
5. **Incidentanmälan (≤72 h):** generatorn kompletteras med allvarlighet, påverkan, ev. angreppsindikatorer (hämtade ur loggen). Skickas; 72 h-klockan kvitteras.
6. **Läges-/slutrapport (≤1 mån):** vid avslut genereras slutrapport (omständigheter, konsekvenser, trolig orsak, åtgärder). Hela kedjan loggas och blir bevis i `compliance-status` ("incidentrutin aktiv ✓") + underlag till nämnden.
*Compliance-värde:* 0 missade lagstadgade frister, full spårbarhet, ledningens personliga ansvar dokumenterat hanterat.

### Flöde 2: Provisionera ny handläggare → sätt rätt åtkomst → avetablera vid avslut
1. **Inflöde:** ny socialsekreterare ska börja; ska in i funktionsadressen `orosanmalan@` med LOA3-krav.
2. **Provisionera:** `provisioning`-kortet → "Lägg till i funktionsadress" → välj grupp, sätt minsta tillitsnivå (LOA3, SMS-OTP spärrad för detta sekretessflöde), bekräfta. Åtkomsten loggas som händelse.
3. **Kontroll:** `auth-loa` bekräftar att kontot har MFA, att tillitsnivå-täckningen fortsatt är 100 %.
4. **Hygien löpande:** kortet flaggar vilande/överbehöriga konton; förvaltaren rättar.
5. **Avslut:** medarbetare slutar → "Avetablera" → åtkomst dras tillbaka samma dag, vilket loggas som åtkomsthändelse (NIS2 åtkomststyrning + GDPR). `access-log` visar att ingen kvarvarande åtkomst finns.
*Compliance-värde:* dokumenterad åtkomstlivscykel; inga föräldralösa konton; bevis i Infosäkkollen-självskattningen.

### Flöde 3: Tillsyn/DSO-begäran → sök i loggen → exportera spårbart underlag → gallra enligt plan
1. **Begäran:** DSO eller tillsyn vill veta vem som haft åtkomst till en handling med utökat skyddsbehov, samt verifiera en specifik SDK-leverans.
2. **Sök leverans:** `log-retention` → sök mot AS4 Message ID → får meddelandetyp, accesspunkt-id, avsändande/mottagande deltagare, tidpunkter (utan innehåll, per Digg). Bekräftar leverans.
3. **Exportera åtkomst:** `access-log` ("vem har sett vad") filtreras per ärende/handläggare → export (för SIEM eller som PDF-underlag). GDPR art. 30/32-bevis.
4. **Gallra/bevara:** `retention-archiv` visar att handlingstypen ska gallras 2031 enligt dokumenthanteringsplan, eller bevaras → vid bevarande "Leverera till e-arkiv" paketerar enligt FGS för Sydarkivera. Loggen själv behålls 12 mån (SDK) / enligt plan.
*Compliance-värde:* en självbetjänad tillsyns-/revisionskedja; arkivlag + GDPR + SDK-loggkrav uppfyllt i samma vy.

---

## Tillgänglighet & sekretess

### Tillgänglighet (WCAG 2.2 AA — bygg mot detta nu, DOS-lagen + EN 301 549 v4.1.1 väntas få rättslig verkan ~2026)
- **Nedräkningsklockorna (MCF-frister) får aldrig förlita sig enbart på färg** (1.4.1) — visa alltid text "18 h 40 min kvar" + ikon/mönster, inte bara röd/gul/grön.
- **Target Size ≥ 24×24 px** (2.5.8) på alla widgetknappar, status-/kryssrutor, "eskalera"/"verifiera"-knappar.
- **Focus Not Obscured** (2.4.11): fokus får inte döljas av sticky filterpaneler när loggtabeller scrollas eller Quick View expanderar.
- **Dragging Movements** (2.5.7): omordning/visa-dölj av kort i "anpassa vy"-läget måste ha knapp-/tangentbordsalternativ (upp/ner), inte bara drag — standard-widgetgriden klarar inte detta, Hubs egen vy måste lösa det.
- **Consistent Help** (3.2.6): hjälp/support på samma plats i varje vy; lås hjälp-kortet utanför det konfigurerbara skalet.
- **Accessible Authentication** (3.3.8): LOA3-inloggning (BankID/Freja/SITHS) utan kognitiva test; OTP-fält ska tillåta klistra/uppläsning.
- **Loggar/tabeller** som riktiga datatabeller med rubrikceller och sortering som funkar med skärmläsare och vid 400 % zoom/Reflow (1.4.10) — förvaltaren arbetar ofta i stora tabeller.
- Dokumentera efterlevnad **per kriterium** — tillgänglighet är ett poängsatt tilldelningskriterium i offentlig upphandling.

### Sekretess & rättslig hänsyn
- **OSL + 10 kap. 2 a § + eSam ES2023-06:** Hubs on-prem-modell eliminerar lämplighetsbedömningen (ingen extern part får informationen). `data-sovereignty`-kortet gör detta synligt. Compliance-vyn får aldrig visa *innehåll* i sekretesshandlingar — bara metadata, status och loggrader (SDK-loggen ska enligt Digg *inte* omfatta meddelandeinnehåll).
- **Rollvyn är en behörighetsgräns, inte bara UX:** förvaltarens compliance-vy får inte avslöja ärenderubriker/avsändare från funktionsadresser som förvaltaren saknar OSL-behörighet till — visa aggregat (antal, status, frister), inte klartext. Åtkomstloggen ska kunna sökas *om* utan att exponera de sekretessbelagda uppgifterna i sig.
- **HSLF-FS 2016:40:** kryptering till endast avsedd mottagare + stark autentisering. `auth-loa` och "säker kanal"-markeringen bevisar detta i UI.
- **GDPR:** åtkomst-/delningsloggen levererar art. 30/32-bevis; sparade personaliseringsinställningar (kortordning/filter) hålls minimala, syftesbundna, raderbara — ingen beteendeprofilering av förvaltaren själv. Lokal AI prioriterar *händelseegenskaper* (frist, avvikelse, sekretessnivå), aldrig användarbeteende → ingen profilering enligt art. 22.
- **Arkivlagen 1990:782 + arkivförordningen (2024) + RA-FS:** gallring är konfigurerbar per handlingstyp enligt kommunens egen dokumenthanteringsplan (inte hårdkodad); bevarande är huvudregel; FGS-export för leverans till e-arkiv. Håll isär personliga gallringsbara noteringar från ärendebundna allmänna handlingar.
- **Datakällor som är allmänna handlingar:** incidentrapporter, identifieringsbevis och åtkomstloggar som blir beslutsunderlag kan vara allmänna handlingar — ska kunna bevaras/gallras och exporteras som revisionsspår.

---

*Källor (utöver grundnings- och extended-analyserna i `analysis-output/`): Cybersäkerhetslag (2025:1506) & cybersäkerhetsförordning (2025:1507), i kraft 15 jan 2026; MCF incidentrapportering (24 h/72 h/1 mån; IRON/blankett tills ny tjänst) — mcf.se & pts.se; föreskrifter om incidentrapportering ~1 juli 2026, säkerhetsåtgärder/utbildning + revision/skanning ~1 okt 2026; MSB Infosäkkollen/It-säkkollen-resultatredovisning (31 %/69 %; 4 av 120) — msb.se; cybermiljarden (nationellt 300/350/400 mkr 2026–2028; kommuner 200 mkr/år, regioner 50 mkr/år) — regeringen.se 2025-09; Digg SDK-regelverk + Bilaga IT-säkerhet (12 mån sökbar logg, AS4 Message/Conversation ID); Diggs tillitsramverk LOA2–4; Sverige-id godkänd 2026-04-21 (lansering dec 2026); arkivförordningen uppdaterad 1 aug 2024; SKR vägledning Digitala underskrifter (dec 2025); HSLF-FS 2016:40; OSL 10:2a + eSam ES2023-06; DOS-lagen 2018:1937 / EN 301 549 / WCAG 2.2.*
