<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# En dag som Förvaltare / IT / informationssäkerhet (`forvaltare`) — verklig användning av Hubs + dashboarden

*Konkret, kronologisk arbetsdag för CISO/IS-samordnaren/systemförvaltaren som äger Hubs i en svensk kommun/region. Grundad i `persona-forvaltare.md`, `personaConfig.js` (layouten `complianceStatus · incidentrapporter · sakerhetshandelser · loggSparbarhet · authLoa` + side `systemhalsa · provisionering · arkivGallring · dataSuveranitet · nytta`), `middleware-architecture.md`, `native-apps-map.md`, `arendehantering-map.md`, `transcription-ai.md` och `esign-todo-native.md`. Datum i scenariot: fredag 2026-06-12 (dagen efter en patch-tisdag, mitt i NIS2-införandefönstret). Brand-regel: i UI säger vi aldrig "Nextcloud"/"Talk" — här namnges app-id för wiring.*

> **Arkitektonisk ram (genomsyrar hela dokumentet):** Hubs är **mellanlagring (middleware)**. För denna persona är slutlagringen (system of record) tredelad och ovanlig jämfört med handläggarpersonorna — den är inte ett verksamhetssystem utan **(1) MCF/PTS** (incidentanmälan enligt cybersäkerhetslagen 2025:1506), **(2) kommunens SIEM/loggsystem** (säkerhets-/åtkomstlogg som långtidsbevaras och korreleras) och **(3) e-arkiv via Sydarkivera/FGS** (arkivpliktiga compliance-handlingar). Förvaltaren *committar inte ärendeutfall* som de andra personorna — hen committar **incidentrapporter till MCF, loggexporter till SIEM och arkivpaket till e-arkivet**, och bevakar att de andra personornas överföringar till facksystemen faktiskt skett (provisioneringshygien, gallring satt, 0 tredjelandsöverföringar). Hubs egen 12-månaders SDK-logg är medvetet *transient mellanlagring*: den korta operativa fönstret; slutlagringen av det som ska bevaras längre sker i SIEM/e-arkiv.

---

## En dag i arbetet (08:00→17:00, kronologiskt, konkret)

**Vem just denna dag:** Anna, IS-samordnare + systemförvaltare för Hubs i en mellanstor kommun (~3 200 anställda, 6 förvaltningar). Hon bär tre hattar samtidigt: säkerhetssamordnare (compliance uppåt mot kommunstyrelsen), driftförvaltare (systemhälsa/provisionering) och de facto dataskyddsstöd åt DSO. Mentala modellen styr ordningen hon öppnar saker i: **"Är vi säkra just nu? Kan vi bevisa att vi följer lagen? Är det värt pengarna?"**

**07:58 — Inloggning på LOA3.** Anna legitimerar sig med SITHS eID (LOA3). Redan här produceras compliance-data: sessionen taggas LOA3 och matar `authLoa`. Hubs öppnar hennes rollhärledda förstavy (`hubs_start`), härledd ur grupp `infosak`+`it-drift`.

**08:00 — Lägeskoll (är något på fel?).** Översta kärnkortet `complianceStatus` visar sammanvägd gul (inte grön): en punkt har slagit om sedan igår. Hon scannar `incidentrapporter` (inga aktiva nedräkningsklockor — bra) och `sakerhetshandelser`, som visar **en spik på 14 misslyckade inloggningar mot funktionsadressen `orosanmalan@` mellan 23:10–23:40 i natt**, plus **en delning av en fil ut till en extern e-postdomän utanför org** kl 06:50. `systemhalsa` (side) är grön: SDK-accesspunkten uppe 99,98 %, 0 meddelanden i fellager, 2 ej kvitterade leveranser (under tröskel).

**08:15 — Bedöm säkerhetshändelsen.** Anna öppnar Quick View på inloggningsspiken. Den lokala AI-prioriteringen (`llm2`, avstängbar, transparent) har flaggat raden överst med synligt "varför": *"avvikande: auth-spik utanför kontorstid + extern delning samma natt"*. Hon ser att de 14 försöken kom från en IP utanför Sverige men aldrig lyckades (inget LOA3-genombrott), och att den externa delningen var en handläggare som delade en mötesagenda till sin privata Gmail — slarv, inte intrång, men en **avvikande delning** som ska loggas och tillrättavisas. Hon bedömer: inloggningsspiken är **ingen betydande incident** (inget genombrott, MFA höll), men ska dokumenteras; den externa delningen återkallas och blir en internkontroll-notering.

**08:30 — (Om-det-varit-värre-scenariot, som hon övar på.)** Hade spiken lyckats hade hon klickat **"Eskalera till incident"** på raden → `incidentrapporter` skapar incidenten och **24 h-klockan för tidig varning till MCF startar** (synlig nedräkning + ej-bara-färg-markör), och MCF-rapportgeneratorn förfylls med tidpunkt, berörda konton/funktionsadress och initial bedömning ur loggen. Idag stannar det vid att hon markerar händelsen "bedömd – ej incident" (loggad), återkallar den externa delningen och skickar en kort säker påminnelse till handläggaren.

**09:30 — Provisionering & behörighet.** `provisionering` (side) visar dagens kö: **2 nya** (en ny socialsekreterare som ska in i `orosanmalan@` med LOA3-krav, en vikarie på HR), **1 avslutad** (en medarbetare vars sista dag var igår — ska avetableras), och **1 vilande konto** som inte loggat in på 90 dagar (överbehörighetsflagga). Hon klickar "Lägg till i funktionsadress" för den nya, sätter minsta tillitsnivå LOA3 (SMS-OTP **spärrad** för detta sekretessflöde), "Avetablera" på den avslutade (åtkomst dras samma dag, loggas som åtkomsthändelse), och skickar en fråga till HR-chefen om det vilande kontot ska stängas. `authLoa` bekräftar att MFA-täckningen fortsatt är 100 %.

**10:30 — Veckans säkerhetsavstämning (säkert möte).** Kort `dagensMoten`-poster finns inte i hennes default-layout, men hon har ett bokat säkert videomöte (spreed-itsl) med driftleverantören om HPB-uppgraderingen. Eftersom det är ett **internt, icke-sekretessbelagt** möte testar hon transkriberings-kedjan: inspelning (recording server) + KB-Whisper efterhands-transkript + lokal `llm2`-sammanfattning → utkast med beslut + åtgärdslista, som hon granskar och godkänner (human-in-the-loop) och sparar som mötesanteckning. Rå-WebM:en får en kort gallringsklocka.

**12:45 — Gallring & arkiv.** `arkivGallring` (side) visar avslutade ärenden/loggobjekt med gallringsstatus. En batch socialtjänst-ärenderum är överförd till Treserva och Hubs-kopiorna har nått sin rensningsfrist ("Rensas ur Hubs 30 dgr efter överföring" — Retention/`files_retention`, restricted-tagg). Hon kontrollerar att **inget gallras som ska bevaras** och att tre avslutade nämndärenden är redo för **FGS-export till Sydarkivera**. Hon trycker "Leverera till e-arkiv (FGS)" på de tre — det är överlämnandet till slutlagring.

**14:00 — Tillsyn/DSO-begäran → loggsök.** DSO har fått en begäran (en medborgare vill veta vem som haft åtkomst till en handling med utökat skyddsbehov) plus en handläggare som behöver verifiera att ett säkert meddelande verkligen levererades. Anna går till `loggSparbarhet`: söker mot **AS4 Message ID** → får meddelandetyp, accesspunkt-id, avsändande/mottagande deltagare, tidsstämplar (**utan innehåll**, per Diggs SDK-loggkrav). Bekräftar leverans. Sedan exporterar hon åtkomstloggen ("vem har sett vad") filtrerad per ärende/handläggare → **PDF-underlag till DSO + maskinell export till SIEM** (GDPR art. 30/32-bevis). `loggSparbarhet` visar grön bock: **SDK-loggretention 12/12 mån, sökbar**.

**15:30 — Rätta compliance-gulet från morgonen.** Hon går tillbaka till `complianceStatus`. Punkten som var gul: "ledningsgenomgång daterad" hade gått ut (>12 mån sedan senaste). Hon kan inte fixa det själv men skapar underlaget och bokar in punkten på nästa kommunstyrelse-sammanträde; punkten blir gul→"åtgärd planerad". De övriga kravområdena (anmäld MCF, incidentrutin aktiv, logg 12 mån, MFA/LOA3 100 %, data i egen miljö) är gröna. `dataSuveranitet` visar: **All data i er driftmiljö · 0 tredjelandsöverföringar · senaste externa åtkomst: ingen.**

**16:15 — Rapportera uppåt (är det värt pengarna?).** Inför måndagens budgetberedning sammanställer hon `nytta` (side): ersatta fax + rek-brev + okrypterad e-post denna månad × Diggs schablon ~30 min/ärende → frigjord tid, plus faxavvecklingskurvan. Hon klickar primäråtgärden **"Sammanställ nytta & efterlevnad för ledningen"** → paketerar `complianceStatus` + `dataSuveranitet` + `nytta` till en exporterbar "NIS2-åtgärd: kostnad/nytta/efterlevnad" för **cybermiljards-äskandet** (kommunen har 200 mkr/år 2026–2028 att äska ur).

**16:50 — Dagsavslut.** Sista blicken på `complianceStatus` (en gul punkt med plan), `incidentrapporter` (0 missade MCF-deadlines), `sakerhetshandelser` (alla bedömda). Tom incidentkö = inget missat = compliance-värde. Loggar ut.

---

## Hur Hubs + dashboarden faktiskt används (öppningsordning, åtgärder)

Förvaltarvyn är **triage av efterlevnad**, inte av ärenden. Default 5–7 kort, låst kärna överst, kuraterat skal under. Den faktiska interaktionssekvensen en typisk dag:

| Tid | Widget (öppnas) | Varför just då | Åtgärd som tas | Card View → Quick View → full |
|---|---|---|---|---|
| 08:00 | `complianceStatus` (kärna 1) | "Är vi säkra/lagliga nu?" — första frågan | Läs sammanvägd färg; identifiera vilken punkt som ändrats | Card = grön/gul/röd; Quick = kravlista mappad mot Infosäkkollen-nivå |
| 08:05 | `incidentrapporter` (kärna 2) | Tickar någon MCF-klocka? | Inget idag; annars "Skicka tidig varning"/"Komplettera anmälan" | Card = antal öppna + närmaste deadline; Quick = klock-kedja 24h/72h/1 mån |
| 08:08 | `sakerhetshandelser` (kärna 3) | Vad har hänt sedan igår? | Bedöm spik + extern delning; ev. "Eskalera till incident" | Card = aggregerade signaler; Quick = berörda konton/ärenden (aggregat, ej klartext) |
| 08:30 | (`incidentrapporter` igen) | Om eskalering behövs | Eskalera → klocka startar, generator förfylls | full vy = MCF-rapportutkast |
| 09:30 | `provisionering` (side) | Daglig åtkomstlivscykel | "Lägg till i funktionsadress", "Avetablera", flagga vilande | Card = in/ut/vilande-kö; Quick = per-konto LOA + funktionsadressmedlemskap |
| 09:40 | `authLoa` (kärna 5) | Kontroll efter provisionering | Bekräfta MFA 100 %, inga under-tröskel-inloggningar | Card = % LOA3 + eIDAS2-redo-markör; Quick = lista under-tröskel |
| 12:45 | `arkivGallring` (side) | Eftermiddagens gallringspass | "Leverera till e-arkiv (FGS)"; kontroll bevaras/gallras | Card = kommande gallringar (countdown) + objekt utan regel; Quick = per handlingstyp |
| 14:00 | `loggSparbarhet` (kärna 4) | DSO/tillsyn + handläggar-felsökning | Sök AS4 Message ID; exportera åtkomstlogg → SIEM/PDF | Card = grön bock 12/12; Quick = sökruta (ej innehåll) |
| 15:30 | `complianceStatus` (åter) | Stäng morgonens gula punkt | Skapa underlag, boka ledningsgenomgång | — |
| 16:15 | `nytta` + `dataSuveranitet` (side) | "Är det värt pengarna?" | "Sammanställ nytta & efterlevnad för ledningen" → export | Card = ROI/faxkurva; full = cybermiljards-underlag |

**Mönster:** kärnkorten (1–5) läses **uppifrån-ned varje morgon** (säker → laglig → bevisbar). Side-korten (`systemhalsa`, `provisionering`, `arkivGallring`, `dataSuveranitet`, `nytta`) öppnas **uppgiftsdrivet** vid bestämda tider (provisionering förmiddag, gallring efter lunch, rapportering sen eftermiddag). `systemhalsa` är ett passivt "andas-kort" — bara om det blir rött kräver det åtgärd. Ctrl/Cmd+K-paletten (rollfiltrerad) används för de fem primäråtgärderna utan att navigera till kortet.

---

## Widget → app → system-of-record-karta (per widget i forvaltare-layouten)

Mellanlagrings-modellen explicit: **Hubs stagar X → förvaltaren/handläggaren för över till {system}**. För denna persona är destinationen ofta MCF, SIEM eller e-arkiv — och för flera kort är "överföringen" snarare en **bevisbarhet** att andra personors handoff skett.

### Main (låst kärna)

| Widget | Driver-app (intern) | Data IN (varifrån) | Hubs stagar (mellanlagring) | Slutlagring (system of record) — "för över till …" |
|---|---|---|---|---|
| `complianceStatus` | Compliance-/NIS2-modul (ny i sdkmc/hubs_start), **härledd** ur `activity`+`authLoa`+SDK-status+`files_retention` | Operativa signaler ur Hubs egna kanaler (auth, delning, loggstatus, gallringsregler) — **ingen manuell inmatning** | Sammanvägd grön/gul/röd mot kravområden, mappad mot Infosäkkollen-nivå (mål ≥ nivå 3) | **Kommunstyrelsen/ledningen** (ledningsgenomgång, personligt ansvar) + **MSB Infosäkkollen-självskattning** (compliance-bilden *fyller i* självskattningen) → exporteras som nämndunderlag |
| `incidentrapporter` | Incidenthantering = klock-logik ovanpå `sakerhetshandelser`-feed + `tables` (incidentregister) | Eskalerad säkerhetshändelse (från kort 3) | Triagekö med nedräkningsklockor (24h/72h/1 mån); MCF-rapportgenerator förfylls ur logg | **MCF/PTS** — tidig varning → incidentanmälan → läges-/slutrapport (mönster D: generator förfyller, förvaltaren skickar in via IRON/blankett tills MCF:s tjänst är live; mönster A på sikt). Arkivpliktig incidentrapport → **e-arkiv (FGS)** |
| `sakerhetshandelser` | `sdkmc` säkerhetshändelse-feed (auth-logg, delningslogg, SDK-routing) + `activity` OCS-API v2; lokal `llm2` *föreslår* prio | Misslyckade inloggningar, utomgrupps-/avvikande delningar, meddelande till oväntad funktionsadress, inloggning under tröskel-LOA | Aggregerad verksamhetsnära signal-feed; "Eskalera till incident"-knapp | Bedömd händelse → **internkontroll-logg i Hubs** (transient) eller → eskaleras till `incidentrapporter` → **MCF**. Korrelerade signaler exporteras till **SIEM** |
| `loggSparbarhet` | Logg- & spårbarhetspanel = SDK-loggindex (`sdkmc`) + sökindex + `activity` (åtkomstlog) | SDK AS4 Message/Conversation ID-loggrader (**utan meddelandeinnehåll**, per Digg); fil-åtkomsthändelser | 12/12 mån sökbar logg; "vem har sett vad"-export | **Kommunens SIEM/loggsystem** (maskinell export, långtidskorrelation) + **DSO/tillsyn** (PDF-underlag, GDPR art. 30/32). Hubs-loggen *själv* bevaras 12 mån (transient) — SIEM är slutlagringen för längre retention |
| `authLoa` | ID-core / auth (sessionsdata; BankID/Freja/SITHS) | LOA per session, MFA-status, metod | Andel LOA3, MFA-täckning, eIDAS2/EUDI-redo-markör, lista under-tröskel | **Internkontroll/Infosäkkollen-bevis** (HSLF-FS 2016:40 + Diggs tillitsramverk). Matar `complianceStatus`; vid avvikelse → SIEM |

### Side (kuraterat skal)

| Widget | Driver-app (intern) | Data IN | Hubs stagar | Slutlagring — "för över till …" |
|---|---|---|---|---|
| `systemhalsa` | `sdkmc` driftstatus (accesspunkt/kvittens) | SDK-anslutningsupptid, fellager, ej kvitterade leveranser | Driftöversikt + "ej hanterade meddelanden > X dgr" | NIS2 kontinuitetsbevis (internkontroll); allvarlig avvikelse → `incidentrapporter` → **MCF** |
| `provisionering` | Användar-/grupphantering + funktionsadress-admin (core + `groupfolders`) | Anställning/avslut (idealt från **Personec/Heroma/Visma** via HR), gruppmedlemskap | Provisioneringskö (in/ut/vilande/överbehörig); "Lägg till i funktionsadress"/"Avetablera" loggas som åtkomsthändelse | Åtkomstlivscykeln **slutlagras i åtkomstloggen → SIEM**; den auktoritativa identiteten bor i **HR-systemet/katalogen (IAM)** — Hubs speglar, äger inte personalrekordet |
| `arkivGallring` | `files_retention` (Retention, restricted-tagg) + FGS-byggare + `files`/`groupfolders` | Avslutade ärenderum/loggobjekt; dokumenthanteringsplan (kundkonfigurerad) | Gallrings-countdown per handlingstyp; "Leverera till e-arkiv (FGS)" | **E-arkiv via Sydarkivera/FGS** (FGS Paketstruktur 2.0 = E-ARK CSIP; FGS Ärendehantering 2.0 = CITS ERMS) — mönster C. Hubs-kopian **gallras efter bekräftad överföring** (mellanarkiv → slutarkiv) |
| `dataSuveranitet` | Compliance-modul (statisk + åtkomstlogg-härledd) | Driftmiljö-fakta + extern-åtkomst-logg | "All data i er driftmiljö · 0 tredjelandsöverföringar" | Inget rekord att föra över — det är **beviset** att slutlagringen sker on-prem/hos rätt part (svar på OSL 10:2a + CLOUD Act). Liten diskret variant i alla personavyer |
| `nytta` | Strukturerat register (`tables`) ovanpå kanalstatistik (`sdkmc`) | Ersatta fax/rek-brev/okrypterad e-post per månad | ROI-räknare (× ~30 min/ärende), faxavvecklingskurva | **Nämnd/kommunstyrelse + cybermiljards-äskande** — exporteras som beslutsunderlag (kan i sig bli allmän handling → e-arkiv) |

**Provenance-bandet (per `middleware-architecture.md` §4):** varje rad i `incidentrapporter`/`sakerhetshandelser`/`arkivGallring` bär tre koordinater — *härkomst* (kanal + verifierad identitet/IP), *tillstånd nu* (Hubs · mellanlagring + GOV.UK-status), *slutdestination* (MCF / SIEM / e-arkiv + överföringsstatus, t.ex. "Anmäld till MCF 2026-06-12 10:14" eller "FGS-levererad till Sydarkivera"). Tom "ej överförd"-kö = allt committat = compliance-värde.

---

## Typiska arbetsmönster & återkommande flöden (end-to-end, in → slutlagring)

### Flöde 1 — Upptäck säkerhetshändelse → eskalera → rapportera till MCF i tid
1. **IN:** `sakerhetshandelser` aggregerar ur Hubs egna kanaler (`sdkmc` auth/delning/routing + `activity`): auth-spik + avvikande extern delning. Lokal `llm2` flaggar mönstret transparent ("varför").
2. **Mellanlagra/bedöm:** Quick View → berörda konton/funktionsadress (aggregat, ej klartext); bedöm betydande/ej betydande.
3. **Eskalera:** "Eskalera till incident" → `incidentrapporter` skapar incident, **24 h-klockan startar**, MCF-generator förfylls ur loggen (tidpunkt, system, initial bedömning).
4. **Tidig varning ≤24 h → Incidentanmälan ≤72 h → Läges-/slutrapport ≤1 mån:** generatorn kompletteras stegvis (allvarlighet, påverkan, ev. angreppsindikatorer ur logg).
5. **SLUTLAGRING:** varje steg **förs över till MCF/PTS** (mönster D via IRON/blankett tills MCF-tjänsten är live; mönster A på sikt). Hela kedjan loggas → blir bevis i `complianceStatus` ("incidentrutin aktiv ✓") + nämndunderlag; den arkivpliktiga slutrapporten **FGS-exporteras till e-arkiv**. *Värde: 0 missade lagstadgade frister.*

### Flöde 2 — Provisionera ny handläggare → sätt rätt åtkomst → avetablera vid avslut
1. **IN:** anställnings-/avslutssignal (idealt från **Personec/Heroma/Visma**); ny socialsekreterare ska in i `orosanmalan@` med LOA3.
2. **Mellanlagra/åtgärda:** `provisionering` → "Lägg till i funktionsadress", sätt minsta tillitsnivå LOA3 (SMS-OTP spärrad), bekräfta. `authLoa` kontrollerar att MFA-täckning fortsatt 100 %. Vilande/överbehöriga konton flaggas och rättas.
3. **Avslut:** "Avetablera" → åtkomst dras **samma dag**, loggas som åtkomsthändelse.
4. **SLUTLAGRING:** den auktoritativa identiteten bor i **HR-systemet/IAM-katalogen** (Hubs speglar); **åtkomstlivscykeln slutlagras i åtkomstloggen → SIEM** som NIS2-åtkomststyrnings- + GDPR-bevis. *Värde: inga föräldralösa konton; dokumenterad livscykel i Infosäkkollen.*

### Flöde 3 — Tillsyn/DSO-begäran → sök i loggen → exportera spårbart underlag → gallra enligt plan
1. **IN:** DSO/tillsyn vill veta "vem har sett vad" + verifiera en specifik SDK-leverans.
2. **Sök:** `loggSparbarhet` → sök mot **AS4 Message ID** → meddelandetyp, accesspunkt, deltagare, tidpunkter (utan innehåll). Bekräftar leverans.
3. **Exportera:** åtkomstlogg filtreras per ärende/handläggare → **PDF till DSO** + **maskinell export till SIEM** (GDPR art. 30/32).
4. **Gallra/bevara:** `arkivGallring` visar handlingstypens regel ("Gallras 2031 enligt handlingstyp X" / "Bevaras") → vid bevarande **"Leverera till e-arkiv (FGS)"** till Sydarkivera. SDK-loggen själv behålls 12 mån (transient mellanlagring).
5. **SLUTLAGRING:** bevarandepliktigt → **e-arkiv (FGS)**; loggunderlag → **SIEM**. *Värde: självbetjänad tillsyns-/revisionskedja; arkivlag + GDPR + SDK-loggkrav i samma vy.*

### Flöde 4 — Är det värt pengarna? Nytto- & efterlevnadsrapport uppåt (kvartalsvis / inför budget)
1. **IN:** kanalstatistik ur `sdkmc` (ersatta fax/rek-brev/okrypterad e-post) + compliance-signaler.
2. **Mellanlagra/beräkna:** `nytta` räknar × Diggs ~30-min-schablon → frigjord tid/årsarbetskrafter + faxavvecklingskurva; `complianceStatus`+`dataSuveranitet` ger efterlevnads-/suveränitetsbilden.
3. **Paketera:** primäråtgärd "Sammanställ nytta & efterlevnad för ledningen" → exporterbar "NIS2-åtgärd: kostnad/nytta/efterlevnad".
4. **SLUTLAGRING:** **kommunstyrelse/nämnd + cybermiljards-äskande** (kommuner 200 mkr/år, regioner 50 mkr/år 2026–2028). Beslutsunderlaget kan självt vara allmän handling → registreras/diarieförs och **arkiveras** där arkivredovisningen finns. *Värde: Hubs motiveras som kvalificerande NIS2-åtgärd med riktig data, inte gissningar.*

---

## Saknade funktioner för denna persona (och hur de byggs/wire:as)

1. **MCF-rapportgenerator med riktig auto-förfyllnad (det enskilt mest köp-/demovärda gapet).** Idag finns klock-logiken (`incidentrapporter`) som koncept men ingen motor som **förfyller MCF:s rapportmall (tidig varning/anmälan/läges-/slutrapport) ur loggen och exporterar till IRON/blankett**. *Bygg:* en `tables`-baserad incidentmodell + en mallmotor som drar tidpunkt/berörda system/initial bedömning ur `sdkmc`-feed och `activity`; export-adapter mot MCF (mönster D nu via IRON/blankett-export, mönster A när MCF:s rapporterings-API är live). Klockorna ska aldrig förlita sig enbart på färg (WCAG 1.4.1) — text "18 h 40 min kvar" + ikon.

2. **Loggkorrelation/SIEM-brygga ("vem har sett vad" + AS4-sök som maskinell export).** `loggSparbarhet` täcker manuell sökning, men SIEM-personan vill ha en **strukturerad, schemalagd export** (CEF/syslog/JSON) till kommunens SIEM och en larm-feed för avvikelser. *Bygg:* en exportkonnektor ovanpå SDK-loggindexet + `activity` OCS-API v2, med signering av exporten (tamper-evidence) så att SIEM-importen i sig är spårbar; respektera Diggs krav att meddelandeinnehåll **inte** ingår.

3. **Mötestranskribering + lokal AI-sammanfattning (record → transcribe → summarise → godkänn).** Saknas som wire:at flöde fast alla byggblock finns on-prem. *Bygg:* recording server (kräver HPB) → `stt_whisper2` med **KB-Whisper (KBLab, Apache-2.0, ~47 % lägre WER på svenska än large-v3 — Hubs default-STT)** → `llm2`/Assistant + `call_summary_bot` för utkast (beslut + åtgärdslista) → **human-in-the-loop "Godkänn"** (loggad) → "Spara till ärende"; rå-WebM + rå-transkript får kort Retention-gallring (transient), bara godkänd text committas till slutlagring. För förvaltaren: **interna/icke-sekretessbelagda möten först** (säkerhets-/driftavstämning, nämndberedning); skarp körning på sekretessbelagda samtal *dokumenteras men väntar* på IMY/SKR/Socialstyrelse-vägledning. Hänger på `dagensMoten` + `arenderum` + `senasteFiler`, inget nytt widget-id krävs för MVP.

4. **Bevarande-/valideringspanel "Giltig nu / Giltig då" (LTV-bevis).** Förvaltaren äger revisions-/överklagandebeviset för signerade handlingar men har ingen vy som visar **PAdES/PDF/A + LTV-status + "Verifiera underskrift nu"** per arkiverat signerat dokument. *Bygg:* ovanpå e-underskrifts-adaptern (LibreSign internt/lågrisk; **Inera Underskriftstjänst-API / Sweden Connect-nod** för BankID/Freja/SITHS-AES i prod) — konvertera till PDF/A-1, lägg på kvalificerad tidsstämpel + LTV, spara valideringsintyg; exponera inom `arkivGallring`/compliance-export. Detta är funktionen ingen konkurrent (Scrive/Assently/Visma Addo) säljer tydligt.

5. **Provisioneringsbrygga mot HR-systemet/IAM (auto-livscykel).** `provisionering` är idag manuell kö; den auktoritativa anställnings-/avslutssignalen bor i **Personec/Heroma/Visma**. *Bygg:* en läs-konnektor (mönster A om API finns, annars schemalagd import) så att "ny/avslutad" föreslås automatiskt med rätt funktionsadress/LOA-krav — men **människan bekräftar** (åtkomst = säkerhetsgräns), och varje åtgärd loggas → SIEM.

6. **Cybermiljards-/Infosäkkollen-export som färdig mall.** `nytta` + `complianceStatus` finns men paketeringen till **MSB Infosäkkollen-självskattning** och cybermiljards-äskandets format är manuell. *Bygg:* en mappning compliance-kravområden → Infosäkkollen-nivåer (mål ≥ nivå 3) + en export-mall som fyller självskattningen med Hubs-härledd data i stället för att förvaltaren matar ännu ett system (differentieringen mot Secify/Secure State Cyber/Purview: operativ data → automatisk efterlevnadsbild).

---

**persona-usage-forvaltare.md klar.** Wiring-prioritet för forvaltare: kärnkorten 1–5 är nivå-1/2-byggbara (compliance härledd ur `activity`+`authLoa`+SDK-status; loggsök på befintlig SDK-logg); MCF-generatorn + SIEM-bryggan + LTV-panelen är de distinkta byggena; transkriberingen kräver HPB+GPU men har KB-Whisper som svensk nyckel.
