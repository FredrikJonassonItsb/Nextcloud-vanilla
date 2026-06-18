# Persona-dashboard: Registrator / nämndsekreterare

*Personaliserad startyta för Hubs (ITSL) — den Nextcloud-baserade säkra kommunikationssviten för svensk offentlig sektor. Persona-id: `registrator`. Datum: 2026-06-13. Hubs körs på server v32 (Hub 25 Autumn) enligt repo.*

> **Varumärkesregel:** i produkt-/UI-text säger vi aldrig "Nextcloud" eller "Talk". Vi säger **Hubs**, **säkra meddelanden**, **säker e-post**, **digital fax**, **säkert möte**, **ärenderum**, **diarie-/registreringsstöd**. Plattformsnamnen används bara internt i detta underlag för spårbarhet.

---

## Persona & en dag i arbetet

**Vem:** Registratorn (och i mindre kommuner samma person som **nämndsekreteraren**) är navet i kommunens informationsflöde — "spindeln i nätet". Registratorn tar emot, öppnar, bedömer, registrerar (diarieför), klassar och **fördelar** inkommande handlingar till rätt handläggare/nämnd; sätter diarienummer (dnr) och ärendemening; bevakar att inget faller mellan stolarna; och svarar på framställningar om utlämnande av allmän handling. Nämndsekreteraren lägger till den **politiska beslutskedjan**: bygga kallelse och nämndhandlingar, kvalitetssäkra beslutsunderlag, föra protokoll, hantera **justering**, **anslå** på anslagstavlan, bevaka **laga kraft** och **expediera/delge** besluten.

**Persona-verkligheten (från grundningen):** Detta är ett av de största enskilda flödena av känslig information i kommunsektorn. SDK-anslutningsvågen 2025–2026 (>100 anslutna org aug 2025, Region Stockholm sep 2025, 2026 = standardrutin) gör att inflödet av **spårbara, kvittenskrävande** meddelanden ökar kraftigt — och varje inkommet meddelande är en latent registreringspliktig handling med en lagstadgad svars-/registreringsfrist. SKR:s 2025-rekommendation om **funktionsadresser** (delade brevlådor per verksamhet, t.ex. `registrator@kommunen`) gör den delade funktionsbrevlådan till registratorns kärnvy — inte en personlig mejlinkorg. Fax lever kvar som inkommande kanal under lång övergångstid (ställföreträdare har lagstadgad rätt att lämna papper; små vårdgivare saknar SDK).

**En typisk dag:**
- **07:30 Morgonsvep.** Öppnar Hubs och ser *en* aggregerad triagekö över allt inkommande natten/morgonen: SDK-meddelanden, säker e-post till funktionsadressen, inskannad/digital fax-in, e-tjänsteärenden. Räknaren överst: "23 oregistrerade · 4 med svarsfrist idag · 1 felskickad till fel funktionsadress".
- **08:00 Registrering & fördelning.** Betar av kön i batch. Per handling: bedöm om den ska registreras (huvudregel: ja för allmän handling som inte är av "uppenbart ringa betydelse"), sätt **dnr**, **ärendemening**, **avsändare**, **inkommen-datum**, sekretessmarkering, fördela till handläggare/nämnd. JO:s tumregel: registrering senast **nästkommande arbetsdag**. Massregistrering av likartade handlingar (t.ex. samma typ av remissvar) görs i en svep.
- **10:00 Utlämnandeframställan.** En journalist begär ut handlingar i ett ärende. Registratorn söker i diariet, gör sekretessprövning, maskerar och lämnar ut "skyndsamt".
- **11:00 Nämndförberedelse (nämndsekreteraren).** Bygger kallelse till nästa nämnd: drar ihop ärenden, kontrollerar att varje ärende har komplett beslutsunderlag, skickar kallelse + handlingar säkert till förtroendevalda.
- **13:00 Justering & expediering.** Gårdagens protokoll justeras digitalt (ordförande + justerare signerar med BankID), **anslås** på anslagstavlan (laga-kraft-klockan startar — överklagande inom 3 veckor), och besluten **expedieras/delges** berörda via säker kanal med kvittens.
- **15:00 Arkiv & gallring.** Kontrollerar att avslutade ärenden är kompletta, att rätt gallringsregel gäller per handlingstyp enligt dokumenthanteringsplanen, och förbereder leverans till e-arkiv (FGS) för Sydarkivera-kommuner.
- **Hela dagen:** Är "compliance-samvetet" — ser till att tom kö = inget missat, att kvittens finns på det som skickats, och att spårbarheten håller för revision/tillsyn.

**Smärtpunkter Hubs ska lösa:** (1) inflödet splittrat över många kanaler/system → kognitiv börda och risk att missa; (2) "ringa och kolla att faxen kom fram"-oron → behov av synlig leverans-/läskvittens; (3) manuellt dubbelarbete mellan meddelandeklient och diariesystem → behov av förifylld registrering; (4) deadlines i huvudet/på post-it → behov av bevakning med eskalering; (5) osäkerhet om gallring/bevarande → behov av synlig gallringsstatus och FGS-export.

---

## Mål & nyckeltal (KPI)

Princip (från UX-grundningen): mät **tid-till-åtgärd och fullständighet**, inte tid-på-dashboarden. Tom kö är ett *compliance-värde*, inte bara bekvämlighet.

| KPI | Definition | Varför (lag/nytta) |
|---|---|---|
| **Tid till registrering** | Median-/maxtid från inkommen handling till diarieförd | OSL 5 kap 1 § + JO: "så snart som möjligt", normalt nästa arbetsdag. Mätbart rättssäkerhetsmått. |
| **Oregistrerat över 1 arbetsdag** | Antal inkomna handlingar äldre än 1 arbetsdag som ännu inte registrerats | Direkt registreringsskyldighet; röd flagga = laglig brist. |
| **Otilldelade i funktionskön** | Antal inkomna som ingen handläggare "plockat" | "Vem tar detta?" — inget ska falla mellan stolarna (intern­kontroll, NIS2-ledningsansvar). |
| **Svarsfrist-/dröjsmålsbevakning** | Antal ärenden som närmar sig/passerat frist (svar, FL 6-mån-gräns, laga kraft) | Förvaltningslagen 11–12 §§; bevisbar handläggningstid. |
| **Leverans-/läskvittensgrad** | Andel utgående säkra meddelanden/delgivningar med bekräftad leverans/öppning | Ersätter "kolla att faxen kom fram"; delgivningsbevis. |
| **Felskickat undvikt** | Antal meddelanden till verifierad funktionsadress i stället för faxnummer | Riskreduktion (felskickade fax är den utpekade säkerhetsrisken). |
| **Nytta hittills** | Antal ersatta fax/rek-brev × Diggs schablon ~30 min/ärende → sparad tid | ROI-underlag till förvaltningschef; cybermiljards-/budgetmotivering. |
| **Nämndcykel-genomströmning** | Andel ärenden med komplett underlag vid kallelse; tid kallelse→justering→anslag→expediering | Kommunallagen; rättssäker beslutskedja. |
| **Gallrings-/leveransstatus** | Andel avslutade ärenden med korrekt gallringsregel + FGS-redo | Arkivlagen + arkivförordningen (export/radering före införande). |
| **Loggretention** | SDK-logg 12/12 mån, sökbar | Diggs SDK-krav (verbatim 12 mån, läsbar form). Grön/röd compliance-bock. |

---

## Primära åtgärder (3–5, verb-först)

Dessa är de knappar registratorn ska nå på en sekund — i Card View på korten och i **Ctrl+K-paletten** (rollfiltrerade åtgärder).

1. **Registrera & fördela handling** — diariestöds-/triagemodulen. Öppna inkommen handling → förifylld registrering (avsändare, inkommen-datum, föreslaget dnr, ärendemening, sekretessmarkering) → tilldela handläggare/nämnd. (Datakälla: SDK-/säker e-post-/fax-metadata; integration mot diariesystem.)
2. **Lämna ut allmän handling** — diariesök + sekretessprövning. Sök ärende/handling, maskera sekretess, skicka ut säkert med logg. (Funktion: säker e-post/SDK + åtkomst-/utlämnandelogg.)
3. **Bygg & skicka nämndkallelse** — nämnd-/sammanträdesmodulen. Samla ärenden med komplett underlag, generera kallelse + handlingar, skicka säkert till förtroendevalda. (Funktion: ärenderum + säker delning + kalender/säkert möte.)
4. **Justera & expediera beslut** — protokoll-/expedieringsmodulen. Justera protokoll digitalt (BankID-underskrift av ordförande/justerare), anslå på anslagstavlan (starta laga-kraft-klocka), expediera/delge berörda med kvittens. (Funktion: e-underskrift via Inera Underskriftstjänst/Sweden Connect + säker kanal + delgivningskvittens.)
5. **Leverera ärende till e-arkiv** — arkiv-/gallringsmodulen. Kontrollera komplett ärende, applicera gallringsregel, paketera enligt FGS för Sydarkivera/e-arkiv. (Funktion: säkra filer/ärenderum + Retention + FGS-export.)

---

## Widgetar (ordnad lista)

Designmodell genomgående: **låst kärna** (compliance-/tillgänglighetskritiska kort rollen alltid ser) + **kuraterat skal** (vitlistade kort som får ordnas/döljas, knappbaserad omordning enligt WCAG 2.5.7). Varje kort: **Card View** (agera direkt) + **Quick View** (detalj utan sidbyte). Roll härleds automatiskt från grupp-/funktionsadress-medlemskap; standardlayout sätts av admin (motsv. Viva audience targeting / `IConditionalWidget`). Default visar 5–6 kort, inte 12.

> **Låst kärna:** W1, W2, W3, W9. **Kuraterat skal:** W4, W5, W6, W7, W8, W10.

| # | id | Titel | Syfte | Datakälla | App/funktion |
|---|---|---|---|---|---|
| W1 | `reg-triage-inkorg` | **Att registrera & fördela** | Aggregerad triagekö över allt inkommande (SDK / säker e-post / fax-in / e-tjänst) med GOV.UK-statusar (Ny / Påbörjad / Väntar / Klar / Åtgärd krävs), kanalikon, oläst/otilldelad, och räknare överst ("23 oregistrerade · 4 frist idag"). Förstavyn. | Befintlig (SDK-/mail-/fax-metadata via sdkmc) + **föreslagen** Tables-motor för status/tilldelning | Säkra meddelanden + säker e-post + digital fax; triagelogik ovanpå Dashboard-API (`IAPIWidgetV2`/`IConditionalWidget`) |
| W2 | `reg-registrera-fordela` | **Registrera handling** | Card View "Registrera & fördela" som öppnar förifyllt registreringsformulär: avsändare, inkommen-datum, föreslaget dnr/ärendemening, sekretessmarkering, tilldela handläggare/nämnd. Stänger gapet meddelande↔diarium. | **Föreslagen** (förifyllning från meddelandemetadata; integration mot diariesystem W3D3/Public360/Ciceron/Lifecare) | Diarie-/registreringsstöd (nytt Hubs-flöde) + Forms/Tables som internt registerlager |
| W3 | `reg-leverans-kvittens` | **Skickat & kvittens** | Spegelbild av utkorgen: leveranstidslinje per utgående meddelande/delgivning — *Skickad → Levererad → Öppnad → Läst (LOA3) → Besvarad* + feltillstånd (Studsade / Ej öppnad inom X dgr → eskalera). Den känslomässiga ersättningen för "kolla att faxen kom fram". | Befintlig (leverans-/läskvittens från SDK & säkra meddelanden) | Säkra meddelanden + identitets-/leveranslager (ID-core) |
| W4 | `reg-utlamnande` | **Lämna ut allmän handling** | Snabb diariesök + framställan-kö (utlämnandebegäran), med sekretessprövnings-checklista, maskering och säkert utskick. "Skyndsamt"-timer per begäran. | **Föreslagen** (diariesök + utlämnandelogg) | Diariesök + säker e-post/SDK + åtkomst-/utlämnandelogg |
| W5 | `reg-namndcykel` | **Nämndcykeln** | Status för kommande sammanträde: ärenden på dagordningen, vilka som saknar komplett underlag, kallelse skickad?, handlingar delade?, protokoll att justera, anslag aktivt, expediering kvar. GOV.UK-task-list per steg. | **Föreslagen** (sammanträdesregister) | Ärenderum + säker delning + kalender/säkert möte + Tables |
| W6 | `reg-justering-anslag` | **Justering & anslag** | Protokoll som väntar på digital justering (BankID-underskrift av ordförande/justerare); aktiva anslag med **laga-kraft-nedräkning** (3 v efter anslag); expediering/delgivning kvar att göra. | **Föreslagen** (e-underskrift + anslagstavla) | E-underskrift (Inera Underskriftstjänst / Sweden Connect, PAdES/PDF/A) + säker kanal |
| W7 | `reg-bevakningar` | **Mina bevakningar & frister** | Deadline-sorterad lista med eskaleringsfärg: registreringsfrist, svarsfrist, FL 6-mån-gräns, laga kraft, leveransfrist. "Skapa bevakning från meddelande". Strip överst: "4 frister denna vecka". | Befintlig (kanban/VTODO som datalager) + **föreslagen** påminnelse-före-deadline | Uppgifts-/bevakningsmodul (Deck/Tasks-backend, egen widgetlogik) |
| W8 | `reg-arkiv-gallring` | **Arkiv & leverans** | Avslutade ärenden med gallringsstatus ("Gallras 2031 enligt handlingstyp X" / "Bevaras"), notis innan automatisk radering, och "Leverera till e-arkiv (FGS)". | Befintlig (Groupfolders + Retention + versioner) + **föreslagen** FGS-export | Säkra filer / ärenderum + Retention + FGS-paketering |
| W9 | `reg-compliance-sakerhet` | **Säker kanal & spårbarhet** | Diskret men låst: "All data i er driftmiljö · 0 tredjelandsöverföringar", SDK-loggretention 12/12 mån sökbar, inloggad tillitsnivå (LOA3), felskickade-fax undvikna. Säkerhetshändelse-feed med "eskalera till incident". | Befintlig (logg/åtkomst) + **föreslagen** compliance-härledning | Loggnings-/spårbarhetslager + Dashboard-API |
| W10 | `reg-nytta-hittills` | **Nytta hittills** | Räknar ersatta fax/rek-brev × ~30 min (Diggs schablon) → sparad tid; andel SDK vs fax per månad (faxavveckling). ROI-underlag för chef/budget. | **Föreslagen** (Tables "nytta"-register) | Tables-motor + Dashboard-API |

---

## Föreslagna appar/moduler (befintlig vs föreslagen)

| Modul | Status | Motivering för registrator-personan |
|---|---|---|
| **Säkra meddelanden (SDK) + funktionsadresser** | Befintlig (sdkmc) | Kärninflödet. SKR:s 2025-rekommendation gör funktionsadress (delad brevlåda) till registratorns arbetsyta. Delad kö med tilldelning ("plocka ärende") är grundmönstret. |
| **Säker e-post till/från medborgare** | Befintlig | Andra kanalen in i funktionsbrevlådan; socialtjänst/elevhälsa pekas ut som störst behov. |
| **Digital fax-in/ut** | Befintlig (migreringsbrygga) | Fax lever under lång övergång (ställföreträdare/små vårdgivare). Visa i samma triagevy + mät avvecklingen. |
| **Diarie-/registreringsstöd** | **Föreslagen** (kärndifferentiator för denna persona) | Förifylld registrering (avsändare/datum/dnr/ärendemening/sekretess) direkt från meddelandemetadata, med tilldelning. Integrerar mot — ersätter inte — diariesystemet (W3D3/Public360/Ciceron/Lifecare). Stänger gapet meddelande↔diarium som ingen konkurrent löser i samma sekretessäkra yta. |
| **Nämnd-/sammanträdesmodul** | **Föreslagen** | Kallelse, beslutsunderlag, protokoll, justering, anslag, expediering. Gör nämndsekreterar-halvan av personan komplett; bygger på ärenderum + säker delning + kalender. |
| **E-underskrift (BankID/Freja/SITHS)** | **Föreslagen** (stå på Inera Underskriftstjänst / Sweden Connect) | Digital justering av protokoll och e-sigill på utgående beslut/massutskick. PAdES/PDF/A + långtidsvalidering för arkivbeständighet. Bygg inte egen signeringsmotor. |
| **Uppgifts-/bevakningsmodul** | Befintlig backend (Deck/Tasks) + **föreslagen** widgetlogik | Frist-/dröjsmålsbevakning med påminnelse-före-deadline och avisering bara till tilldelad person (täcker känd kärnlucka). |
| **Säkra filer / ärenderum + Retention + FGS-export** | Befintlig bas + **föreslagen** orkestrering | Ärenderum per dnr; gallring per handlingstyp enligt dokumenthanteringsplan; FGS-leverans till Sydarkivera/e-arkiv (upphandlingskrav enligt arkivförordningen 2024). |
| **Tables (osynlig motor)** | Befintlig | Backend för triage-status, nytto-register, deadline-register — renderas som Hubs-widgets, aldrig rå tabell. |
| **Kalender + säkert möte** | Befintlig | Bokning av sammanträden/möten; auto-säkert videorum vid behov. |
| **Lokal AI-prioritering** | **Föreslagen** (lokal, `llm2`, grön rating) | Förslagslager ovanpå deterministisk sortering i triagekön (hög inkommande volym). Transparent, avstängbar, aldrig destruktiv; prioriterar ärendeegenskaper (frist/sekretess/oläst), inte användarbeteende → ingen profilering enligt GDPR art. 22. |
| **Maps / Notes** | Avstå | Maps saknar v32-stöd; Notes för smal. Ej relevant för denna persona. |

---

## Terminologi (persona-anpassade ord)

Använd registratorns/nämndsekreterarens fackspråk — det signalerar "byggt för svensk offentlig sektor" och sänker inlärningströskeln. Lån från FK:s/Arbetsförmedlingens öppna designsystem för komponent- och formulärkonventioner.

| Hubs-ord (UI) | Betyder | Undvik |
|---|---|---|
| **Registrera / diarieföra** | Föra in handling i diariet med dnr | "Spara", "arkivera" (fel skede) |
| **Diarienummer (dnr)** | Ärendets identifierare | "Ärende-ID" enbart |
| **Ärendemening** | Kort beskrivning av ärendets innehåll | "Titel", "ämne" |
| **Avsändare / inkommen-datum** | Två av de fyra obligatoriska diarieuppgifterna | — |
| **Fördela / tilldela handläggare** | Skicka ärendet till rätt person/nämnd | "Assigna" |
| **Funktionsadress / funktionsbrevlåda** | Delad verksamhetsbrevlåda (SDK) | "Gruppmejl" |
| **Allmän handling** | Inkommen/upprättad handling som omfattas av offentlighet | "Dokument" enbart |
| **Sekretessmarkering / sekretessprövning** | OSL-bedömning före utlämnande | "Hemligstämpla" |
| **Utlämnande / framställan** | Begäran om att få ut allmän handling | "Förfrågan" |
| **Kallelse / dagordning / nämndhandlingar** | Underlag inför sammanträde | "Mötesinbjudan" |
| **Justering / justerare** | Godkännande och underskrift av protokoll | "Signera" enbart |
| **Anslag / anslagstavla** | Tillkännagivande som startar överklagandetid | — |
| **Laga kraft** | Beslut som inte längre kan överklagas | "Klart" |
| **Expediera / delge** | Skicka beslut till berörd part med bevis | "Skicka ut" |
| **Delgivningskvittens / läskvittens** | Bevis på att mottagaren tagit del | "Bekräftelse" |
| **Gallra / bevara** | Förstöra eller behålla enligt dokumenthanteringsplan | "Radera"/"spara" |
| **Dokumenthanteringsplan** | Styr gallring/bevarande per handlingstyp | "Regler" |
| **E-arkiv / FGS-leverans** | Slutarkivering i utbytesformat | "Export" enbart |
| **Säker kanal** | Krypterad, mottagarverifierad väg (HSLF-FS 2016:40) | "Skyddad mejl" |

---

## Flöden (end-to-end)

### Flöde 1: Ta emot → registrera → fördela → bevaka (det dagliga massflödet)
1. **Inkommande** SDK-meddelande/säker e-post/fax-in landar i funktionsadressens delade kö (**W1**). Räknaren ökar; kanalikon visar källa.
2. Registratorn öppnar handlingen. Card View **"Registrera & fördela"** (**W2**) öppnar ett **förifyllt** formulär: avsändare och inkommen-datum hämtas från metadata, **dnr** föreslås, registratorn skriver/justerar **ärendemening**, sätter ev. **sekretessmarkering** (OSL 5:2 — datum och dnr är alltid offentliga; avsändare/ärendemening kan utelämnas om de röjer skyddat intresse).
3. Registratorn **fördelar** till rätt handläggare/nämnd. Handlingen försvinner ur "oregistrerat", dyker upp som tilldelad. JO-tumregeln (registrering senast nästa arbetsdag) bevakas av "oregistrerat >1 arbetsdag"-räknaren.
4. **Bevakning skapas** (**W7**): svarsfrist/uppföljning kopplas till ärendet; påminnelse T-3/T-0 till tilldelad handläggare.
5. **Spårbarhet** (**W9**): registreringen genererar en bevarad, sökbar händelse; data ligger i kommunens egen driftmiljö (ingen OSL 10:2a-lämplighetsbedömning behövs).
*Resultat:* inflödet är diariefört i tid, fördelat, bevakat och spårbart — i en yta, utan dubbelarbete mot diariesystemet.

### Flöde 2: Bygg nämndkallelse → justera protokoll → anslå → expediera (beslutskedjan)
1. **Nämndcykeln** (**W5**) visar kommande sammanträde. Nämndsekreteraren samlar ärenden; kort flaggar vilka som **saknar komplett underlag** (GOV.UK-status "Problem").
2. **Kallelse + nämndhandlingar** genereras och **delas säkert** till förtroendevalda (ärenderum + säker delning, läskvittens i **W3**).
3. Efter sammanträdet: protokoll förs och hamnar i **Justering & anslag** (**W6**). Ordförande och justerare **signerar digitalt med BankID** (avancerad e-underskrift, PAdES/PDF/A via Inera Underskriftstjänst). Inget pappersjusterande, oavsett var de befinner sig.
4. Protokollet **anslås** på anslagstavlan → **laga-kraft-nedräkning** (3 veckor) startar och visas på kortet.
5. Besluten **expedieras/delges** berörda via säker kanal; **delgivningskvittens** syns i **W3**. Eskaleringsknapp om mottagaren inte öppnat inom tröskel (→ digital brevlåda/hybridpost).
6. Vid laga kraft markeras ärendet klart och förbereds för arkiv (**W8**).
*Resultat:* hela den politiska beslutskedjan är digital, signerad, anslagen, delgiven med bevis och spårbar.

### Flöde 3: Utlämnande av allmän handling (offentlighetsprincipen i praktiken)
1. En begäran om utlämnande inkommer (**W4**). "Skyndsamt"-timer startar.
2. Registratorn **söker i diariet** (även via Ctrl+K: "Sök ärende"), identifierar handlingarna.
3. **Sekretessprövnings-checklista** vägleder maskering av skyddade uppgifter (OSL).
4. Handlingarna **skickas ut säkert** (säker e-post/SDK) eller görs tillgängliga; **utlämnandet loggas** (vem fick vad, när) i **W9** — GDPR art. 30/32-bevis och tillsynsunderlag.
*Resultat:* offentlighetsprincipen tillgodosedd skyndsamt, med sekretess intakt och fullständig spårbarhet.

---

## Tillgänglighet & sekretess

### Tillgänglighet (WCAG 2.2 AA, DOS-lagen / EN 301 549)
Bygg mot **WCAG 2.2 AA redan nu** (EN 301 549 v4.1.1 väntas få rättslig verkan ~2026; tillgänglighet är dessutom poängsatt upphandlingskriterium). Direkt relevant för registrator-vyn:
- **2.5.8 Target Size 24×24 px** — status-/klarmarkera-knappar, kanalikoner, registrera-/fördela-knappar i triagekön.
- **2.5.7 Dragging Movements** — omordning av kort och fördelning får inte kräva drag; erbjud knapp-/listalternativ (standard-dashboardens drag-grid klarar inte detta — Hubs egen vy måste lösa det).
- **2.4.11 Focus Not Obscured** — fokuserad ärenderad får inte döljas av sticky filter-/räknarpanel eller när Quick View expanderar.
- **3.2.6 Consistent Help** — hjälp ("Hur registrerar jag?", "Saknar mottagaren BankID?") på fast plats i varje vy; lås hjälpkortet utanför det konfigurerbara skalet.
- **3.3.8 Accessible Authentication** — inloggning (BankID/Freja/SITHS) utan kognitiva test; erbjud kopiera/klistra och uppläsning där koder förekommer.
- **1.4.10 Reflow / 1.3.4 Orientation** — fungerar i 400 % zoom och porträtt.
- **Nedräkningsklockor** (laga kraft, frister, "skyndsamt"-timer) får **aldrig** förlita sig enbart på färg — komplettera med text/ikon (1.4.1 Use of Color).
- **Tom-tillstånd som positivt besked** ("Inget oregistrerat — allt är diariefört") snarare än en tom yta.

### Sekretess & dataskydd (OSL, HSLF-FS, GDPR, arkiv, NIS2)
- **OSL (offentlighets- och sekretesslagen) + registreringsskyldighet:** **5 kap 1 §** (allmänna handlingar ska registreras så snart de kommit in/upprättats) och **5 kap 2 §** (de fyra uppgifterna: inkommen/upprättad-datum + dnr [alltid offentliga], samt avsändare/mottagare + ärendemening [får utelämnas om de röjer skyddat intresse]). Vyn ska göra de två obligatoriska uppgifterna alltid synliga och de två villkorliga maskeringsbara — så att en handlings *existens* aldrig döljs.
- **OSL 10 kap 2 a § + eSam ES2023-06:** Hubs on-prem-modell (data i kundens egen driftmiljö, ingen extern part får informationen) **eliminerar lämplighetsbedömningen** som plågar molnalternativen. Visa "all data i er driftmiljö" i UI (W9).
- **Behörighet är en OSL-gräns, inte UX:** en widget får aldrig avslöja ärendemening, avsändare eller antal från en funktionsbrevlåda för någon utan behörighet till just den. Audience targeting = säkerhetsgräns.
- **HSLF-FS 2016:40:** för vård-/omsorgsnära handlingar krävs kryptering till endast avsedd mottagare + stark autentisering (LOA3). Säker-kanal-markering och LOA3-inloggning uppfyller detta by design; kan ej avtalas bort med samtycke.
- **GDPR:** dataminimering — korttext/ärendemening default som referens, inte klartextcitat av känsliga uppgifter; bevarade verifierings-/utlämnandebevis med gallringsregel; ingen beteendeprofilering av handläggare (lokal AI prioriterar ärendeegenskaper).
- **Arkivlagen (1990:782) + arkivförordningen (2024):** håll isär personliga gallringsbara noteringar från ärendebundna allmänna handlingar i datamodellen; gallring konfigurerbar per handlingstyp enligt kommunens dokumenthanteringsplan; **FGS-export + radering före införande** är upphandlingskrav.
- **Diggs SDK-loggkrav:** loggar bevaras **minst 12 mån i läsbar/sökbar form** (utan meddelandeinnehåll; AS4 Message/Conversation ID). Visa som grön compliance-bock (W9).
- **Cybersäkerhetslagen (2025:1506)/NIS2:** "oregistrerat/obesvarat över X dagar" + leverans-/läskvittens + åtkomst-/utlämnandelogg är konkret internkontroll- och rättssäkerhetsstöd som möter ledningens personliga ansvar — och ett säljargument mot förvaltningschef/CISO.

---

*Källor:* OSL 5 kap 1–3 §§ och JO:s praxis om registrering (allmanhandling.se; Skatteverkets rättsliga vägledning 2025.1; Regionarkivet Stockholm); nämndsekreterarens roll (Sydarkiveras wiki; SKR); digital justering/protokollsignering med BankID (Tyresö kommun; eIDAS art. 25); diariesystem W3D3/Public360/Ciceron (Lunds/Stockholms universitet, Formpipe); samt grundnings- och extended-analyserna i `analysis-output/` (personas, regulatorik, UX-trender, Nextcloud-ekosystem, e-signering, uppgifter, säkra filer, forms, compliance-NIS2, citizen-id, personalisering).
