<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# En dag i arbetet — persona `registrator` (Registrator / nämndsekreterare)

> **Syfte:** den *konkreta, realistiska* dagliga användningen av Hubs + dashboarden för registratorn/nämndsekreteraren — vilka ärenden, vilka beslut, vilka kontakter, i vilken ordning widgetarna öppnas, och var varje datum **kommer ifrån** och **slutlagras**. Kompletterar den abstrakta `persona-registrator.md` (designspec) med ett dygn på golvet.
>
> **Arkitektonisk ram (kundens egen, bärande genom hela dokumentet):** Hubs är **mellanlagring (middleware / staging)**. **Slutlagringen (system of record) är alltid diariesystemet** — för denna persona **W3D3 / Public 360° / Ciceron / Platina / Evolution / LEX** — och i slutänden **e-arkivet (FGS, Sydarkivera)**. Hubs *tar emot, triagerar, registrerar-förbereder, signerar och delger* säkert; registratorn **för över** den allmänna handlingen till diariet, och Hubs-kopian gallras. Hubs blir aldrig "system nummer åtta".
>
> **Varumärkesregel:** i produkt-/UI-text säger vi aldrig "Nextcloud" eller "Talk". I denna interna analys namnger vi apparna (sdkmc, securemail, mail/fax, spreed-itsl, Groupfolders, Deck/Tasks, Tables, LibreSign, Forms, Calendar, Retention) för spårbarhet. **Datum:** 2026-06-13 · **Plattform:** server v32 (Hub 25 Autumn).
>
> **Layout som beskrivs (ur `personaConfig.js`):** `main` = `attHantera` · `registreraFordela` · `funktionsbrevlador` · `namndcykel` · `justeringAnslag`. `side` = `kvittenser` · `bevakningar` · `utlamnande` · `arkivGallring` · `dataSuveranitet` · `nytta`. Låst kärna: `attHantera`, `registreraFordela`, `dataSuveranitet`. Kuraterat skal: resten.

---

## En dag i arbetet (08:00 → 17:00, kronologiskt, konkret)

Vi följer **Anna**, registrator + nämndsekreterare i en mellanstor kommun (kommunstyrelseförvaltningen). Hon delar funktionsbrevlådan `registrator@kommunen` med en kollega och är sekreterare åt kommunstyrelsen + två nämnder. Diariet är **W3D3**. E-arkiv via **Sydarkivera**. Det är fredag; KS hade sammanträde i tisdags, och en nämnd har kallelse-deadline på måndag.

**07:55 — Inloggning (BankID, LOA3).** Anna loggar in. Hubs Start öppnar registrator-vyn. Överst en diskret rad (`dataSuveranitet`): *"All data i er driftmiljö · 0 tredjelandsöverföringar"*. Hon ser inte tom canvas — hon ser dagens kö.

**08:00 — Morgontriage (`attHantera`).** Räknaren överst: **"19 oregistrerade · 3 med svarsfrist idag · 1 felskickad till fel funktionsadress"**. Kön blandar kanaler med kanalikon per rad: SDK-meddelanden från andra myndigheter (region, Försäkringskassan, en grannkommun), säker e-post till funktionsadressen från medborgare, två inskannade fax-in (en ställföreträdare som lämnat papper; en liten privat vårdgivare utan SDK), och tre e-tjänsteärenden. Varje rad bär ett provenance-band: *"Inkom via SDK från Region X 07:14 · BankID-verifierad LOA3 · ska registreras i W3D3"*.

**08:05 — Batch-registrering (`registreraFordela`).** Anna betar av kön. Per handling klickar hon **"Registrera & fördela"** → ett **förifyllt** formulär öppnas: avsändare och inkommen-datum hämtas från meddelandets metadata, **dnr** föreslås (nästa lediga i serien), hon skriver/justerar **ärendemening**, sätter ev. **sekretessmarkering** (OSL 5:2 — datum och dnr alltid offentliga; avsändare/ärendemening maskeras bara om de röjer skyddat intresse), och **fördelar** till rätt handläggare/nämnd. Konkreta ärenden idag:
- En **begäran om bygglovshandlingar** (medborgare, säker e-post) → dnr, fördelas till bygg.
- Ett **remissvar** från Länsstyrelsen (SDK) → kopplas till befintligt dnr (KS-2026-0231).
- En **synpunkt på detaljplan** → nytt dnr, samhällsbyggnad.
- Fem **likartade yttranden** i samma remiss → **massregistreras** i ett svep (samma ärendemening-mall, olika avsändare).
Varje registrering skapar en bevarad, sökbar händelse (matar `loggSparbarhet`/spårbarheten). JO-tumregeln — registrering senast **nästkommande arbetsdag** — bevakas av räknaren "oregistrerat >1 arbetsdag".

**08:50 — Den felskickade (`attHantera` → `sakerhetshandelser`-signal).** Raden "1 felskickad" är ett SDK-meddelande som hamnat på `registrator@` men hör till socialnämndens funktionsadress. Anna **vidarefördelar** internt (loggat) i stället för att det blir en felskickad fax — exakt det riskmoment Hubs är byggt att eliminera. Räknaren "Felskickat undvikt" tickar upp (matar `nytta`).

**09:30 — Funktionsbrevlådan & "vem tar detta" (`funktionsbrevlador`).** Anna växlar till den delade vyn: oläst/otilldelat per brevlåda. Kollegan har plockat tre, Anna plockar fyra. Inget ligger otilldelat ("ingen mellan stolarna"). Behörighetsstyrt — hon ser bara brevlådor hon har OSL-behörighet till.

**10:00 — Utlämnande av allmän handling (`utlamnande`).** En lokal **journalist** begär ut "alla handlingar i ärende KS-2026-0188 (upphandling X)". **"Skyndsamt"-timern** startar. Anna söker i diariet (deep-link till W3D3), identifierar handlingarna, går igenom **sekretessprövnings-checklistan**, **maskerar** två sidor (anbudspris omfattas av sekretess tills tilldelning), och **skickar ut säkert** med logg. Utlämnandet loggas (vem fick vad, när) — GDPR art. 30/32-bevis. En andra begäran (en privatperson som vill se sitt eget ärende) hanteras likadant.

**11:00 — Nämndkalla (`namndcykel`).** Måndagens nämnd ska ha kallelse idag. `namndcykel` visar dagordningen som GOV.UK-task-list: 14 ärenden, varav **2 saknar komplett beslutsunderlag** (rött "Åtgärd krävs"). Anna mejlar säkert de två handläggarna och bevakar (`bevakningar`). När underlagen är inne genererar hon **kallelse + nämndhandlingar** och **delar säkert** till de förtroendevalda (ärenderum + säker delning); `kvittenser` visar vilka som öppnat. *Färsk 2026-detalj:* Prop. 2025/26:164 (i kraft 1 juli 2026) tillåter **helt digitala nämndsammanträden** — kameror/mikrofoner behöver inte vara påslagna hela tiden, ordföranden behöver bara säkerställa vilka som deltar. Det gör det säkra mötesrummet (spreed-itsl + BankID/Freja-lobby) till nämndens deltagandeyta, inte bara ett komplement.

**12:00 — Lunch.** Kön är tom på "oregistrerat >1 arbetsdag" → tom-tillståndet visar *"Inget oregistrerat — allt diariefört i tid"* (positivt besked, compliance-värde).

**13:00 — Justering & expediering (`justeringAnslag`).** Tisdagens KS-protokoll är klart. Det ligger i `justeringAnslag` och väntar på **digital justering**: ordförande + en justerare **signerar med BankID** (avancerad e-underskrift, PAdES/PDF/A). De sitter inte ens i huset — signeringen sker på distans via säker länk. När båda signerat **anslås** protokollet på den officiella anslagstavlan → **laga-kraft-nedräkningen (3 veckor / 21 dagar)** startar och visas på kortet. Sedan **expedieras/delges** de beslut som berör enskilda via säker kanal med **delgivningskvittens** (syns i `kvittenser`). Ett beslut som inte öppnats inom tröskeln flaggas → Anna trycker "Påminn"/eskalera.

**14:30 — Bevakningar & frister (`bevakningar`).** Anna går igenom veckans frister: en **dröjsmålsunderrättelse** (FL 11–12 §) i ett ärende som passerat handläggningstid, två **laga-kraft-bevakningar** (kan ärendet stängas?), och en **överklagandefrist** att bevaka. Eskaleringsfärg grå→gul (≤3 dgr)→röd. Hon skapar en ny bevakning **direkt från ett meddelande** ("Skapa bevakning från meddelande").

**15:00 — Arkiv & gallring (`arkivGallring`).** Anna kontrollerar avslutade ärenden: gallringsstatus per handlingstyp ("Gallras 2031 enligt handlingstyp X" / "Bevaras"), och förbereder en **FGS-leverans till Sydarkivera** för ett kvartals avslutade ärenden. Här ser hon **dubbel-retention** explicit: facksystemets/e-arkivets bevarandestatus *och* Hubs egen rensning ("Hubs-rummet rensas 30 dgr efter att ärendet förts över").

**16:00 — Nytta & ledningssvep (`nytta`).** Inför ett ledningsmöte tar Anna fram ROI-vyn: antal ersatta fax/rek-brev × Diggs schablon (~30 min/ärende) → sparad tid; SDK-andel vs fax denna månad (faxavvecklingskurvan pekar nedåt). Underlag till förvaltningschefen och cybermiljards-äskandet.

**16:45 — Sista svepet (`attHantera`).** En sista koll: räknaren "oregistrerat" är 0 för dagen, allt fördelat, kvittenser inkomna på det viktigaste utgående. Hon loggar ut med tom kö — *inget missat*.

**Kontakter under dagen:** medborgare (utlämnande, säker e-post), journalist (utlämnande), andra myndigheter (region, Länsstyrelse, FK, grannkommun via SDK), interna handläggare (fördelning, underlagsbevakning), förtroendevalda (kallelse, justering), Sydarkivera (FGS), förvaltningschef (nytta). **Beslut hon själv fattar:** registreringsbeslut (ska/ska inte diarieföras), sekretessprövning vid utlämnande, fördelningsbeslut. **Beslut hon iscensätter men inte fattar:** nämndbesluten (politiken), justeringen (ordförande/justerare).

---

## Hur Hubs + dashboarden faktiskt används (öppningsordning & åtgärder)

Registratorns dag är **kö-driven, inte navigations-driven**. Hon "bor" i två-tre widgetar och hoppar in i de övriga vid behov. Typisk interaktionssekvens:

1. **`attHantera` (hela dagen, hemmabasen).** Förstavyn. Öppnas först och återkommande. Åtgärd: läs rad → bedöm → starta registrering. Räknaren styr prioritet (frist idag > oregistrerat > otilldelat).
2. **`registreraFordela` (08:05–09:30, åter vid behov).** Öppnas *från* en `attHantera`-rad (Card View → förifyllt formulär). Åtgärd: sätt dnr/ärendemening/sekretess → fördela → spara. Detta är **dagens mest använda flöde** och kärndifferentiatorn. Stänger gapet meddelande↔diarium.
3. **`funktionsbrevlador` (09:30, morgon + eftermiddag).** Öppnas för "vem tar detta". Åtgärd: plocka/fördela.
4. **`utlamnande` (vid begäran, reaktivt).** Öppnas när en framställan kommer in. Åtgärd: diariesök → sekretessprövning → maskera → skicka säkert (loggat).
5. **`namndcykel` (förmiddag de dagar kallelse/sammanträde ligger nära).** Åtgärd: kontrollera komplett underlag → generera & dela kallelse → följ läskvittens.
6. **`justeringAnslag` (efter sammanträden).** Åtgärd: skicka för justering (BankID) → anslå → expediera/delge → följ delgivningskvittens.
7. **`bevakningar` (eftermiddag + ad hoc).** Åtgärd: beta av frister, skapa bevakning från meddelande.
8. **`kvittenser` (löpande, "den emotionella ersättningen för att ringa och kolla att faxen kom fram").** Öppnas reflexmässigt efter varje viktigt utskick.
9. **`arkivGallring` (slutet av dag/vecka, periodiskt).** Åtgärd: kontrollera gallringsstatus → FGS-leverans.
10. **`nytta` (periodiskt, inför ledning).** Åtgärd: läs av ROI, exportera.
11. **`dataSuveranitet` (passiv, alltid synlig).** Ingen åtgärd — den är trygghetsmarkören/säljbeviset i UI.

**Tvärgående interaktionsmönster:**
- **Ctrl+K-palett** (rollfiltrerade åtgärder): "Registrera & fördela handling", "Lämna ut allmän handling", "Bygg & skicka nämndkallelse", "Justera & expediera beslut", "Leverera ärende till e-arkiv", "Sök ärende".
- **Card View → Quick View** överallt: agera i kortet, expandera för detalj utan sidbyte.
- **Provenance-band per rad**: *kanal in · tillstånd nu (Hubs mellanlagring) · slutdestination (W3D3 — ej registrerad / registrerad dnr X)*.

---

## Widget → app → system-of-record-karta (mellanlagrings-modellen explicit)

För **varje** widget i registratorns layout: vilken Nextcloud-app/funktion driver den (mellanlagring), och vart resultatet **slutlagras**. Genomgående mönster: **Hubs stagar X → handläggaren för över till {system}**. Handoff-mönster A–D enligt `arendehantering-map.md` (A=API/REST, B=drag-to-case "skicka till diariet", C=FGS-export, D=manuell).

| Widget (UI) | Driver-app/funktion (mellanlagring) | dataSource | Vad Hubs stagar | Slutlagring (system of record) | Handoff |
|---|---|---|---|---|---|
| **`attHantera`** (Att hantera) | sdkmc + securemail + mail/fax → `summary`-endpoint (server-side kanalklassning) | real | Allt inkommande triageras i en kö med kanal/frist/provenance | Inget *i sig* — varje rad pekar mot sin destination; **Hubs stagar inflödet → registratorn för över via `registreraFordela`** | — (port till B) |
| **`registreraFordela`** (Registrera & fördela) | Diarie-/registreringsstöd: förifyllning ur sdkmc-metadata; Tables/Forms som internt registerlager | proposed | Förifyllt registreringsformulär (avsändare/inkommen-datum/föreslaget dnr/ärendemening/sekretess) + fördelning | **W3D3 / Public 360° / Ciceron / Platina / Evolution / LEX** (dnr, allmän handling, sekretessmarkering) | **B** (drag-to-case, kärndifferentiator), A hos storkund, D dag 1. *Hubs stagar inkommen handling → registratorn för över till diariet; Hubs-kopian gallras.* |
| **`funktionsbrevlador`** (Funktionsbrevlådor) | sdkmc funktionsadress-stöd (SKR 2025) | real | Delad kö per funktionsadress; plocka/fördela; behörighetsstyrt | Samma som ovan — den plockade handlingen registreras i **diariet** | B (via `registreraFordela`) |
| **`namndcykel`** (Nämndcykeln) | Groupfolders + ACL (ärenderum) + säker delning + Calendar + spreed-itsl + Tables (sammanträdesregister) | proposed | Dagordning, komplett-underlag-status, kallelse + nämndhandlingar delade säkert, mötesrum | **Diariet** (kallelse, beslutsunderlag, protokoll som allmänna handlingar) → e-arkiv | B (handlingarna diarieförs), C vid avslut. *Hubs stagar nämndunderlaget → förs in i diariet/protokollet.* |
| **`justeringAnslag`** (Justering & anslag) | E-underskrift (Inera Underskriftstjänst-API / Sweden Connect; LibreSign för internt lågrisk) + anslagstavla + säker kanal (kvittens) | proposed | Digital justering (BankID, PAdES/PDF/A), anslag + laga-kraft-klocka (21 dgr), expediering/delgivning | **Diariet** (det justerade protokollet = allmän handling) → e-arkiv (FGS) | B/D + C. *Hubs stagar signering & delgivning → det signerade protokollet slutlagras i diariet.* |
| **`kvittenser`** (Leveranser & kvittens) | sdkmc receipt-data + ID-core (leverans/läs-LOA3) | real | Leveranstidslinje per utgående/delgivning (Skickad→Levererad→Öppnad→Läst→Besvarad) | Delgivningsbeviset bevaras med beslutet i **diariet**; kvittensloggen i SDK-loggen (12 mån) | — (bevis till B) |
| **`bevakningar`** (Mina bevakningar & frister) | Deck (delad kö) + Tasks/VTODO (personliga påminnelser T-7/T-3/T-0) | real | Frist-/dröjsmåls-/laga-kraft-/leveransbevakning under mellanlagringsfasen | **Diariet äger den formella fristbevakningen** (W3D3/Public360 har egen bevakning); Hubs-bevakningen gallras/länkas vid överföring | D (för över → markera "förd till ärendet") |
| **`utlamnande`** (Lämna ut allmän handling) | Diariesök (deep-link diariet) + utlämnandelogg + securemail/sdkmc (säkert utskick) | proposed | Framställan-kö, "skyndsamt"-timer, sekretessprövnings-checklista, maskering, säkert utskick | Utlämnandet **loggas i Hubs** (åtkomst-/utlämnandelogg, GDPR art. 30); originalhandlingen bor i **diariet** | — (läser ur diariet, levererar säkert) |
| **`arkivGallring`** (Arkiv & leverans) | Files/Groupfolders + Retention (restricted-tagg) + FGS-byggare | proposed | Avslutade ärenden, gallringsstatus per handlingstyp, FGS-paketering | **E-arkiv (Sydarkivera, FGS Paketstruktur 2.0 = E-ARK CSIP/SIP)** | **C** (FGS-export). *Hubs stagar avslut → levererar FGS-paket till e-arkivet; mellanlagrad kopia gallras.* |
| **`dataSuveranitet`** (Datasuveränitet) | Compliance-modul (statisk + åtkomstlogg-härledd) | proposed | "All data i er driftmiljö · 0 tredjelandsöverföringar" | — (svaret på OSL 10:2a / CLOUD Act medan datat är i mellanlagringen) | — |
| **`nytta`** (Nytta hittills) | Tables (ROI-register) + Dashboard-API | proposed | Ersatta fax/rek-brev × ~30 min; SDK- vs fax-andel | Internt internkontroll-/lednings­underlag (inget ärenderekord) | — |

**Den explicita mellanlagrings-meningen för demon:** *"Ett säkert meddelande kommer in via SDK från regionen (Hubs stagar det, BankID-verifierat, i er driftmiljö). Anna trycker Registrera & fördela → förifylld metadata → handlingen förs över till W3D3, dnr KS-2026-0456. Nu är den allmänna handlingen registrerad i tid (OSL 5:1). Hubs-kopian får en rensningsklocka. Den bestående sanningen bor i diariet — Hubs lämnar ingen permanent skuggdatabas av kommunens känsligaste flöden."*

---

## Typiska arbetsmönster & återkommande flöden (end-to-end)

Fyra flöden, vart och ett med **var data tas emot** och **var den slutlagras** explicit.

### Flöde 1 — Ta emot → registrera → fördela → bevaka (det dagliga massflödet)
1. **Tas emot:** SDK-meddelande / säker e-post / fax-in landar i funktionsadressens delade kö (`attHantera`/`funktionsbrevlador`). Provenance fångas: kanal, BankID/Freja-LOA, tidsstämpel.
2. Registratorn öppnar handlingen → `registreraFordela` Card View → **förifyllt** formulär (avsändare + inkommen-datum ur metadata, föreslaget dnr, ärendemening, sekretessmarkering).
3. **Fördelar** till handläggare/nämnd. Handlingen lämnar "oregistrerat".
4. **Bevakning** kopplas (`bevakningar`): svarsfrist/uppföljning, påminnelse T-3/T-0 till tilldelad.
5. **Förs över / slutlagras:** registreringen committas i **W3D3/Public 360°/Ciceron/Platina** (mönster B; A hos storkund; D dag 1). Hubs-kopian gallras enligt Retention efter bekräftad överföring.
*Resultat:* diariefört i tid (OSL 5:1, senast nästa arbetsdag), fördelat, bevakat, spårbart — utan dubbelarbete mot diariet.

### Flöde 2 — Bygg nämndkallelse → justera → anslå → expediera (politiska beslutskedjan)
1. **Tas emot:** ärenden/beslutsunderlag från handläggare + facksystemet; `namndcykel` visar vilka som saknar komplett underlag (rött).
2. **Kallelse + nämndhandlingar** genereras (ärenderum + säker delning) och skickas till förtroendevalda; läskvittens i `kvittenser`. (Med Prop. 2025/26:164 från 1 juli 2026: helt digitalt sammanträde via säkert mötesrum möjligt.)
3. Efter sammanträdet → `justeringAnslag`: ordförande + justerare **signerar digitalt med BankID** (AES, PAdES/PDF/A via Inera Underskriftstjänst).
4. Protokollet **anslås** → **laga-kraft-nedräkning (21 dgr)** startar.
5. Besluten **expedieras/delges** via säker kanal; **delgivningskvittens** i `kvittenser`; eskalering om ej öppnat.
6. **Förs över / slutlagras:** det justerade protokollet (allmän handling) registreras/bevaras i **diariet**; vid laga kraft → `arkivGallring` → **FGS-export till e-arkiv** (mönster C).
*Resultat:* hela beslutskedjan digital, signerad, anslagen, delgiven med bevis, arkiverad.

### Flöde 3 — Utlämnande av allmän handling (offentlighetsprincipen i praktiken)
1. **Tas emot:** en framställan (journalist/medborgare) inkommer (`utlamnande`); "skyndsamt"-timer startar.
2. Registratorn **söker i diariet** (deep-link W3D3 / Ctrl+K "Sök ärende"), identifierar handlingarna — *de bor redan i slutlagringen*.
3. **Sekretessprövnings-checklista** → maskera skyddade uppgifter (OSL).
4. **Skickas ut säkert** (securemail/sdkmc) eller görs tillgängliga; **utlämnandet loggas** (vem fick vad, när) i Hubs — GDPR art. 30/32-bevis.
*Resultat:* offentlighetsprincipen tillgodosedd skyndsamt, sekretess intakt, fullständig spårbarhet. (Här *läser* Hubs ur slutlagringen och stagar den säkra leveransen — omvänt flöde mot flöde 1.)

### Flöde 4 — Avsluta → gallra/bevara → leverera till e-arkiv (livscykelns slut)
1. **Tas emot:** ett ärende markeras klart (laga kraft nådd, alla handlingar inne).
2. `arkivGallring` visar **gallringsstatus per handlingstyp** enligt dokumenthanteringsplanen ("Gallras 2031" / "Bevaras").
3. **Bevaras-handlingar** paketeras enligt **FGS Paketstruktur 2.0** och **levereras till Sydarkivera/e-arkiv** (mönster C).
4. **Hubs egen mellanlagrings-kopia gallras** (Retention, restricted-tagg, ägarnotis innan radering) — *efter* bekräftad överföring.
*Resultat:* originalet slutlagrat där arkivredovisningen finns; Hubs förblir transient (ingen skuggdatabas; GDPR-dataminimering; suveränitetsberättelsen sluten).

---

## Saknade funktioner för denna persona (och hur de byggs/wire:as)

Det registratorn/nämndsekreteraren saknar mest, rangordnat efter värde, med konkret wiring mot system of record:

**1. Förifyllt diarie-/registreringsstöd med faktisk write-back till diariet (`registreraFordela`).** *Detta är den enskilt största luckan.* Idag stannar mönster B/D vid "öppna förifyllt formulär" / "markera som överförd". Bygg en **tunn diarie-konnektor per facksystem** ovanpå en standardadapter: (a) **mönster B / drag-to-case** mot W3D3/Public 360°/Platina (Formpipe har redan "Teams för W3D3" — Hubs gör motsvarande från en *sekretessäker, on-prem* yta), (b) **mönster A** mot diariesystem med öppet API / Ena REST-API-profil, (c) **mönster D** som dag-1-fallback (generera importfil + "markera överförd", loggat). UI:t måste alltid visa destinationsraden ("Slutdestination: W3D3 — ej registrerad" som en *öppen åtgärd*, precis som en frist).

**2. Mötestranskribering + lokal AI-sammanfattning av nämndberedning (roadmap-modul, denna persona är *bästa första skarpa användningen*).** Nämndberednings-/processmöten är **minst sekretesskänsliga** → idealt första skarpa körning. Wiring (allt lokalt, 0 tredjelandsöverföringar): inspelning via **recording server** (kräver HPB) → fil i ärenderummet → efterhands-transkript via **`stt_whisper2` med KB-Whisper** (KBLab, Apache-2.0, svensk-tränad, slår whisper-large-v3 på svenska — *Hubs viktigaste STT-konfigval*) → lokal sammanfattning via **`llm2`** (chunkning/map-reduce för långa transkript) + `call_summary_bot` → **utkast** (sammanfattning + fattade beslut + åtgärdslista). **Human-in-the-loop obligatoriskt:** nämndsekreteraren redigerar och trycker "Godkänn" (loggat) → "Spara till ärende" committar bara den **godkända texten** till diariet/protokollet. Rå-inspelning + rå-transkript får kort Retention-frist (transient mellanlagring — en mötesinspelning kan vara allmän handling). Hänger på befintliga `namndcykel`/`arenderum`/`bevakningar` utan nytt widget-id för MVP.

**3. Påminnelse-före-deadline-motor som täcker diariets blinda fläck (`bevakningar`).** Diariesystemen äger den *formella* fristbevakningen, men Hubs äger inflödet **innan** det blivit ett ärende (den oregistrerade handlingen med latent registrerings-/svarsfrist) samt frister i den säkra kommunikationen (delgivning, laga kraft, "skyndsamt"-timern). Bygg påminnelse **T-7/T-3/T-0 bara till tilldelad** (täcker Deck-luckor #1549/#566) på Deck+Tasks som osynligt datalager, med knapp-/listalternativ till drag (WCAG 2.5.7). Vid klarmarkering: val "för till ärendet/diariet" vs "gallra (personlig notering)" — håller isär arkivpliktigt från gallringsbart.

**4. FGS-export rakt mot e-arkivet (`arkivGallring`).** Idag är arkivleverans manuell/omständlig (W3D3-exportpaket har historiskt krävt ompaketering till korrekt SIP). Bygg en **FGS Paketstruktur 2.0-byggare** (E-ARK CSIP/SIP + FGS Ärendehantering 2.0 = CITS ERMS) ovanpå Files/Groupfolders + Retention, så Hubs "talar FGS rakt" till Sydarkivera. Mönster C.

**5. "Giltig nu / Giltig då"-bevarandepanel för signerade protokoll (`justeringAnslag` → arkiv).** PAdES/PDF/A + LTV + kvalificerad tidsstämpel så ett justerat protokoll kan **valideras även efter cert-utgång** (revisions-/överklagandebevis). Ingen konkurrent (Scrive/Assently/Visma) säljer detta tydligt; Riksarkivets tyngsta krav är att *beviset* om underskriften arkiveras. Bygg ovanpå Inera/Sweden Connect, inte egen kryptokärna.

---

*Källor (utöver de interna `analysis-output/extended/`-underlagen — middleware-architecture.md, arendehantering-map.md, native-apps-map.md, transcription-ai.md, esign-todo-native.md, persona-registrator.md):* W3D3/Public 360° registrator-praktik (Lunds universitet medarbetarwebb; KTH intranät; SU FAQ för registratorer; VGR SOFIA-manual Public 360°) — https://www.medarbetarwebben.lu.se/dokument-och-arendehantering/dokumenthantering/diarieforingdiarieforingssystemet-w3d3 · https://intra.kth.se/administration/dokument/diariet-w3d3/diarieforing-och-w3d3-1.948210 · https://www.su.se/medarbetare/råd-stöd/arkivering-diarieföring/registrator/faq-för-registratorer-1.494366 · https://www.vgregion.se/ov/manual-sofia/allman-handling/Dokument-som-aven-ska-finnas-i-andra-it-stod/Diariefora-dokument/ ; digital justering/anslag/laga kraft + helt digitala nämndsammanträden (Tyresö kommun digital signering; Prop. 2025/26:164 i kraft 1 juli 2026; kommunallagen 2017:725 anslagstavla 21 dgr) — https://www.tyreso.se/organisation--styrning/politik-och-fortroendevalda/fortroendevalda/for-dig-som-ar-fortroendevald/sammantradet/digital-signering.html · https://www.riksdagen.se/sv/dokument-och-lagar/dokument/proposition/battre-forutsattningar-for-digitala-kommunala_hd03164/html/ .
