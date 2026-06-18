# Persona-dashboard: HR / chef (rehab & känsliga personalärenden)

*Personaliserad förstavy för Hubs (ITSL) — den säkra kommunikationssviten för svensk offentlig sektor. Persona-id: `hr_chef`. Brand-regel: i produkt-/kundtext säger vi aldrig "Nextcloud" eller "Talk" — vi säger "Hubs", "Hubs Start", "säkert möte", "säkra meddelanden", "Hubs Filer", "ärenderum", "uppgifts-/bevakningsmodulen". Underliggande appnamn används bara i detta interna underlag. Datum: 2026-06-13. Hubs körs på server v32 (Hub 25 Autumn).*

> Rollen härleds automatiskt från grupp/kontext `hr` (jfr personaliserings-analysen: "default = roll, inte tom canvas"). Vyn delas i en **låst kärna** (compliance- och tillgänglighetskritiska kort) och ett **kuraterat skal** (vitlistade kort användaren får ordna/dölja med knappar, inte bara drag).

---

## Persona & en dag i arbetet

**Vem:** En HR-partner, HR-konsult eller enhetschef/verksamhetschef i en kommun eller region med personalansvar. Ofta bär hen flera hattar i en mindre kommun (chef *och* HR-stöd). Hens vardag kretsar kring **personuppgifter i den känsligaste kategorin** — hälsodata, sjukfrånvaroorsaker, läkarintyg, rehabdokumentation, missbruks- och arbetsanpassningsärenden, anställnings- och avslutsärenden samt fackliga förhandlingar.

**Det dokumenterade gapet:** Endast **34 %** av cheferna uppger att de har ett verktyg/system för säker hantering av läkarintyg, rehabiliteringsmöten, hälsosamtal och Försäkringskassan-kontakter, och **35 %** av organisationerna saknar digitalt systemstöd för rehabärenden — vilket gör processerna manuella, personberoende och dokumentationssvaga (MedHelp). I praktiken hanteras detta idag i osäker e-post, på papper, i telefon och i HR-/lönesystemets fristlistor. **Vårdgivare och Försäkringskassan får av sekretesskäl inte mejla om rehabärenden**, och samtycke måste ofta skickas (historiskt per post) innan arbetsgivaren får kontakta vårdgivaren. Personuppgifter om medarbetare får aldrig skickas i extern okrypterad e-post. Detta är en marknad utan stark sammanhållen konkurrent — Hubs öppning.

**En typisk dag (rehab- och personalfokus):**

- **08:00 — Triage av känslig inkorg.** Hen öppnar Hubs Start och ser en *avskild* HR-kö: nytt läkarintyg inkommet via säkert meddelande från en medarbetare, ett svar från företagshälsovården, en kallelse till **avstämningsmöte** från Försäkringskassan, och en facklig begäran. Inget av detta ligger i vanlig e-post. Tom kö = inget missat (compliance-värde).
- **08:30 — Deadlinebevakning.** Bevaknings-strippen längst upp varnar: en medarbetare passerar snart **dag 30** i sjukperioden — **plan för återgång i arbete** måste upprättas (krav om sjukskrivning väntas ≥60 dagar). En annan medarbetares läkarintyg går ut om 3 dagar (begär förlängning/uppföljning).
- **09:00 — Rehabmöte.** Hen ansluter till ett **säkert videomöte** med medarbetare + företagshälsovård direkt från dagens-möten-kortet; mötet är knutet till rehab-ärenderummet. Anteckningar och plan-utkast finns i rummet.
- **10:30 — Signera & delge.** Ett **anställningsavtal** till en nyrekryterad ska signeras (avancerad e-underskrift med BankID). Ett **rehabiliteringsbeslut/överenskommelse om arbetsanpassning** ska delges medarbetaren via säkert meddelande med läskvittens.
- **13:00 — Samtycke & FK.** Hen skickar en **samtyckesblankett** (säkert formulär + BankID) till en medarbetare för att få kontakta vårdgivaren, och skickar plan för återgång till Försäkringskassan via säker kanal.
- **15:00 — Uppföljning.** Hen klarmarkerar bevakningar, sätter nästa uppföljningsdatum på två rehabärenden, och ser att enhetens samlade rehab-kö inte har något som "fallit mellan stolarna".

Designkonsekvens: HR-vyn måste vara **avskild, sekretessmärkt och deadline-medveten** — den får aldrig blanda ihop personalärenden med allmän kommunikation, och den måste göra de lagstadgade tidsfristerna (dag 8, dag 30, 60-dagarströskeln, dröjsmålsregler) synliga som "nästa åtgärd".

---

## Mål & nyckeltal (KPI)

**Personans mål:** att inget rehab- eller personalärende faller mellan stolarna, att all känslig kommunikation sker i säker kanal (aldrig i öppen e-post), att lagstadgade frister hålls, och att dokumentation är spårbar och arkivklar inför en eventuell granskning (FK, Arbetsmiljöverket, IMY, revision).

| KPI | Varför (rättslig/verksamhetsdrivare) | Visas i widget |
|---|---|---|
| **Andel rehab-/personalkommunikation i säker kanal** (vs öppen e-post) | OSL/GDPR (hälsodata = känsliga personuppgifter); HSLF-FS 2016:40 där HSL-nära; "data på er server" | "Min HR-dag", "Säker kanal"-markör |
| **Plan för återgång upprättad i tid (senast dag 30)** | FK-krav: plan vid förväntad sjukskrivning ≥60 dgr, senast dag 30; FK kan anmäla låg kvalitet till Arbetsmiljöverket | Rehab-bevakning, deadline-strip |
| **Antal aktiva rehabärenden per status** (Ny / Pågående / Väntar på motpart / Plan upprättad / Avslutad) | Arbetsgivarens rehabansvar (AFS 2023:2 / arbetsmiljölagen); överblick & lastbalansering | "Rehab- & personalärenden"-kö |
| **Frister/uppföljningar som förfaller denna vecka** | Dag 8 (läkarintyg till arbetsgivare), dag 30 (plan), avstämningsmöten, intygsförlängning, förvaltningslagens dröjsmålsregler vid myndighetsutövning | Bevaknings-strip |
| **Dokument som väntar på signatur / signerade i tid** | Anställningsavtal, rehaböverenskommelser, beslut (avancerad e-underskrift, eIDAS art. 26) | "Att signera" / "Skickat för signering" |
| **Tid-till-åtgärd på inkommande känsligt meddelande** | Arbetsmiljö (minska personberoende/manuell hantering), tom-kö-princip | "Min HR-dag" |
| **Ej kvitterade utgående delgivningar** | Bevis att medarbetare/FK/vårdgivare tagit del (motsvarar "ringa och kolla att faxen kom fram") | "Skickat & kvittens" |
| **Nytta hittills** (ersatta utskick/fax/papperssamtycken, sparad tid) | ROI mot förvaltningschef; NIS2-/cybermiljard-budgetmotivering | "Nytta hittills" (chef-skal) |

Mätprincip (från UX-analysen): mät **tid-till-åtgärd och fristträffsäkerhet**, inte tid-på-dashboarden.

---

## Primära åtgärder (verb-först)

1. **Skapa rehab-ärenderum** — startar ett avskilt, behörighetsstyrt ärenderum per personalärende (rehab/arbetsanpassning/personalärende) med rätt ACL, gallringsregel enligt dokumenthanteringsplanen och deltagare. *Funktion/app:* Hubs Filer / ärenderum (Groupfolders + Retention + ACL).
2. **Skicka säkert meddelande / delge** — skickar läkarintygsbegäran, rehabbeslut, samtyckesförfrågan eller information till medarbetare, företagshälsovård eller Försäkringskassan med läskvittens — aldrig i öppen e-post. *Funktion/app:* säkra meddelanden / säker e-post (securemail) + SDK där motparten är ansluten myndighet.
3. **Boka & starta säkert rehabmöte** — skapar en bokningsbar tid, genererar automatiskt ett säkert videorum, kallar medarbetare + företagshälsovård (BankID-verifierat insläpp). *Funktion/app:* kalender/bokning + säkert möte (calendar + spreed-itsl).
4. **Begär underskrift** — skickar anställningsavtal, rehaböverenskommelse eller beslut för avancerad e-underskrift (BankID/Freja), med PAdES/PDF/A-arkivering. *Funktion/app:* e-underskrift (Sweden Connect / Inera Underskriftstjänst-API) i ärenderummet.
5. **Skapa bevakning från ärende** — gör ett inkommande intyg/FK-kallelse till en spårbar uppgift med deadline (dag 30-plan, intygsförlängning, uppföljningsdatum) och ansvarig. *Funktion/app:* uppgifts-/bevakningsmodulen (Deck/VTODO) ovanpå meddelandet.

---

## Widgetar (ordnad lista)

Ordning = topp-till-botten i förstavyn. Varje kort följer Card View + Quick View (progressive disclosure). **L = låst kärna** (alltid synlig för rollen, compliance/tillgänglighet), **S = kuraterat skal** (vitlistat, valbart).

| # | id | Titel | Syfte | Datakälla | App/funktion | Kärna/skal |
|---|---|---|---|---|---|---|
| 1 | `hr_min_dag` | **Min HR-dag** | Summeringskort överst: antal nya känsliga meddelanden, antal med frist idag, antal som väntar på motpart, antal att signera. "Säker kanal · all data i er driftmiljö"-markör. | Befintlig: `sdkmc`-aggregeringsendpoint (`/summary`) filtrerat på HR-kontext | Hubs Start (sdkmc IAPIWidgetV2) | L |
| 2 | `hr_frist_strip` | **Frister denna vecka** | Eskaleringsstrip för lagstadgade tider: dag 8 (läkarintyg), **dag 30 (plan för återgång)**, 60-dagarströskel, avstämningsmöte, intygsförlängning, uppföljningsdatum. Grå→gul (≤3 dgr)→röd (förfallen). | Föreslagen: deadline-register (Tables) + bevakningsmodul | Uppgifts-/bevakningsmodul + Tables-motor | L |
| 3 | `hr_kanslig_inkorg` | **Känslig inkorg (rehab & personal)** | Avskild triage-kö för säkra meddelanden/SDK/fax som rör personalärenden, separerad från allmän kommunikation. Kanalikon (säkert meddelande / SDK / fax) per rad, oläst/kvittens-status. Tom kö = inget missat. | Befintlig: `sdkmc` summary + kanalklassificering (server-side) | Säkra meddelanden / säker e-post / SDK / fax | L |
| 4 | `hr_rehab_arenden` | **Rehab- & personalärenden** | Task-orienterad lista över aktiva ärenden med fast statusuppsättning (Ny / Pågående / Väntar på motpart / Plan upprättad / Avslutad), deadline-markör, antal nya dokument, om medarbetardelning/-signatur är aktiv. Filter: "Mina" / "Enhetens" / "Väntar på mig". | Föreslagen: ärenderum (Groupfolders) + statusregister (Tables) | Hubs Filer / ärenderum + bevakningsmodul | L |
| 5 | `hr_att_signera` | **Att signera & Skickat för signering** | Två flikar: dokument som väntar på *min* underskrift (anställningsavtal, rehabbeslut) med nivåbadge (AES standard), och utgående med statuskedja (Skickat → Öppnat → Signerat → Arkiverat) + påminnelse-knapp per part. | Föreslagen: e-underskriftskö (Sweden Connect/Inera API) | E-underskrift i ärenderum | S (rekommenderas på) |
| 6 | `hr_moten` | **Dagens & veckans säkra möten** | Kommande rehab-/medarbetar-/avstämningsmöten med ett-klicks-anslut och väntrumsstatus (BankID-verifierade deltagare). "Boka säkert möte" som primär åtgärd. | Befintlig: kalender + säkert möte | Kalender/bokning + säkert möte | S |
| 7 | `hr_mallar_samtycke` | **Mallar & samtycke** | Snabbstart av återkommande dokument: samtyckesblankett (kontakt med vårdgivare), plan för återgång i arbete (FK 7459), rehaböverenskommelse, kallelser. Säkert formulär + BankID/signering. | Föreslagen: Forms (internt) + mallbibliotek i Hubs Filer/Collectives | Forms + Hubs Filer / e-underskrift | S |
| 8 | `hr_kunskapsbank` | **HR-kunskapsbank** | Genväg till rutiner: rehabprocessens steg, lathund "vad får jag skicka var", gallringsplan per handlingstyp, AFS 2023:2-checklista. | Föreslagen: Collectives (wiki on-prem) | Collectives | S |
| 9 | `hr_nytta` | **Nytta hittills** | ROI-räknare: ersatta papperssamtycken/utskick/fax, andel i säker kanal, sparad tid (~30 min/ärende-schablon). För chef-/ledningsdialog och budgetmotivering. | Föreslagen: nytto-register (Tables) | Tables-motor | S (chef-läge) |

**Distinktiva, persona-definierande widgetar:** `hr_frist_strip` (lagstadgad rehab-tidslinje: dag 8 / dag 30 / 60-dagarströskel), `hr_kanslig_inkorg` (avskild sekretess-triage för personalärenden) och `hr_rehab_arenden` (ärenderum med rehab-statusflöde). Dessa tre finns inte i någon konkurrents produkt.

---

## Föreslagna appar/moduler (befintlig vs föreslagen + motivering)

| Modul | Status | Motivering |
|---|---|---|
| **Säkra meddelanden / säker e-post** (securemail) | Befintlig | Kärnan: ersätter den otillåtna öppna e-posten om rehab. Vårdgivare/FK får ej mejla om rehab → säker kanal + läskvittens är direkt behovsuppfyllande. |
| **SDK-klient** (sdkmc) | Befintlig | Org-till-org mot Försäkringskassan (ansluten myndighet) och andra offentliga motparter; även dataägaren bakom aggregerings-endpointen och widgetarna. |
| **Säkert möte** (spreed-itsl) | Befintlig | Rehabmöten, avstämningsmöten och medarbetarsamtal med BankID-verifierat insläpp; ersätter Teams/Skype som underkänns för sekretess. |
| **Kalender/bokning** (calendar) | Befintlig | Bokningsbar tid + auto-genererat säkert videorum per möte; löser kallelse till rehab-/avstämningsmöte. |
| **Digital fax** (mail/fax-brygga) | Befintlig | Övergångsbrygga: små vårdgivare/företagshälsor utan SDK; inkommande intyg som PDF i säker kö. |
| **Hubs Filer / ärenderum** (Groupfolders + Retention + ACL + Collabora/OnlyOffice) | Befintlig bas, **föreslaget HR-lager** | Avskilt rum per rehab-/personalärende med least-permission-ACL och gallringsregel — adresserar exakt 66 %-gapet (ingen säker dokumentyta för läkarintyg/samtycken). |
| **Uppgifts-/bevakningsmodul** (Deck/VTODO + egen widget) | Befintlig bas, **föreslagen task-logik** | "Skapa bevakning från meddelande" + frist-eskalering (dag 30, intygsförlängning, uppföljning). Kärnmodulen saknar separat påminnelse-före-deadline → Hubs bygger det. |
| **E-underskrift** (Sweden Connect-nod / Inera Underskriftstjänst-API) | **Föreslagen** | Anställningsavtal, rehaböverenskommelser, beslut: avancerad e-underskrift med BankID/Freja, PAdES/PDF/A-arkivering. Bygg *inte* egen kryptomotor — stå på suverän nationell infrastruktur, äg arbetsytan/köerna. |
| **Forms (internt) + mallbibliotek** | **Föreslagen (inom sina gränser)** | Samtyckesblankett, plan-underlag, enkäter. Forms saknar native filuppladdning/förgrening → koppla på signeringssteg och fildropp; använd internt, ej som publik e-tjänst. |
| **Collectives (kunskapsbank)** | **Föreslagen** | On-prem rutin-/mall-/gallringsbank — "en ingång, inte system nummer åtta" (Arbetsmiljöverket/Suntarbetsliv). |
| **Tables (osynlig motor)** | **Föreslagen** | Backend för frist-/status-/nytto-register; renderas som Hubs-branded widgets, aldrig som rå tabell. |
| **Lokal AI-prioritering** (llm2, grön Ethical-AI) | **Föreslagen, valfri** | Förslag på köordning + sammanfattning av inkommande intyg, *transparent och avstängbart*, prioriterar ärendeegenskaper (frist/sekretess) — aldrig profilering av medarbetare (GDPR art. 22). "AI utan att data lämnar er server." |
| **Maps / Notes** | **Avstå** | Maps saknar v32-stöd och är underhållsosäker; Notes för smal. Ingen HR-relevans som differentierare. |

---

## Terminologi (persona-anpassade ord)

Använd HR-/rehabspråk medarbetaren känner igen — inte generiska "ärende"-ord och aldrig plattformsnamn.

| Generiskt / tekniskt | Persona-anpassat (HR/chef) |
|---|---|
| Inkorg / meddelandekö | **Känslig inkorg** / "Rehab & personal" |
| Ärende | **Personalärende** / **rehabärende** |
| Ärenderum / mapp | **Rehab-rum** / **personalakt (avskild)** |
| Uppgift / todo | **Bevakning** / **uppföljning** |
| Deadline | **Frist** (dag 30, dag 8, uppföljningsdatum) |
| Dokument att signera | **Avtal att skriva under** / **beslut att signera** |
| Kvittens | **Läskvittens** / "medarbetaren har tagit del" |
| Möte | **Rehabmöte** / **avstämningsmöte** / **medarbetarsamtal** |
| Motpart | **Medarbetare** / **företagshälsovård** / **Försäkringskassan** / **facklig part** |
| Formulär | **Samtycke** / **blankett** (t.ex. plan för återgång) |
| Säker kanal-markör | **"Säker kanal · all data i er driftmiljö"** |
| Status "väntar" | **Väntar på motpart** (FK/vårdgivare/medarbetare) |

Centrala domänbegrepp som ska finnas ordagrant i UI: **plan för återgång i arbete**, **avstämningsmöte**, **läkarintyg**, **samtycke**, **arbetsanpassning**, **rehabiliteringsansvar**, **företagshälsovård**.

---

## Flöden (end-to-end)

### Flöde 1 — Ta emot läkarintyg → bedöm → upprätta plan för återgång → följ upp
1. **Inkommer:** Medarbetare skickar läkarintyg via säkert meddelande (eller fax-in från vårdgivare). Landar i `hr_kanslig_inkorg`, inte i öppen e-post.
2. **Triage:** HR öppnar, läser, **skapar bevakning från meddelandet** — Hubs förifyller titel, länkar tillbaka, och föreslår frist. Systemet känner igen sjukperiodens längd och flaggar **dag 30**-tröskeln om sjukskrivning väntas ≥60 dagar.
3. **Ärenderum:** HR skapar (eller öppnar) **rehab-rummet** för medarbetaren; intyget arkiveras där med gallringsregel; ACL begränsar till HR + ansvarig chef.
4. **Plan:** HR startar mallen **"Plan för återgång i arbete" (FK 7459)** i rummet, samredigerar med chef (Collabora/OnlyOffice on-prem), upprättar i samråd med medarbetaren.
5. **Signera & delge:** Planen/överenskommelsen skickas för **avancerad e-underskrift** (medarbetare via BankID); signerad PDF/A + valideringsintyg arkiveras. Plan delges Försäkringskassan via säker kanal vid behov.
6. **Följ upp:** Bevakningen sätts på nästa uppföljningsdatum; `hr_frist_strip` röjer upp den i god tid. Tom kö = inget missat.
- *Compliance:* OSL/GDPR (hälsodata), FK-krav på plan senast dag 30, AFS 2023:2 rehabansvar, arkivlagen (bevarande/gallring), spårbar logg (NIS2).

### Flöde 2 — Boka rehab-/avstämningsmöte → genomför säkert → dokumentera → delge beslut
1. **Boka:** Från `hr_moten` skapar HR en bokningsbar tid; ett **säkert videorum** genereras automatiskt. Kallelse till medarbetare + företagshälsovård via säkert meddelande (länk, inget konto krävs).
2. **Insläpp:** Deltagare verifieras med BankID/Freja; HR ser i väntrummet vilka som identifierats och släpper in.
3. **Genomför:** Möte hålls; överenskommelse om arbetsanpassning antecknas i rehab-rummet (ev. en mall-tavla som stöd, aldrig som enda beslutsbärare).
4. **Signera:** Överenskommelsen skickas för e-underskrift till medarbetaren.
5. **Delge:** Beslut/överenskommelse delges via säkert meddelande med **läskvittens**; status syns i `hr_att_signera` (Skickat → Öppnat → Signerat).
- *Compliance:* sekretess-säkert insläpp (eID), OSL/GDPR, eIDAS art. 26 (AES), HSLF-FS 2016:40 där HSL-nära.

### Flöde 3 — Inhämta samtycke för vårdgivarkontakt → kontakta → dokumentera
1. **Behov:** HR behöver kontakta medarbetarens vårdgivare men får inte utan **samtycke** (och vårdgivare/FK får ej mejla om rehab).
2. **Begär samtycke:** HR skickar **samtyckesblankett** (säkert formulär) till medarbetaren via säkert meddelande; medarbetaren legitimerar sig med BankID och signerar — ersätter det historiska "samtycke per post".
3. **Arkivera:** Det signerade samtycket arkiveras i rehab-rummet (tidsstämplat, spårbart).
4. **Kontakta:** HR kontaktar vårdgivaren via säker kanal/SDK eller bokat säkert möte; allt knyts till rummet.
5. **Bevaka:** Bevakning skapas för svar och uppföljning.
- *Compliance:* GDPR (laglig grund/samtycke för hälsodata), OSL, dataminimering (korttext = ärendereferens, inte klartext-diagnos).

### Flöde 4 (sekundärt) — Anställningsavtal: upprätta → signera → arkivera
1. HR upprättar avtalet (mall i Hubs Filer), **skickar för avancerad e-underskrift** till den nyrekryterade (BankID, även på distans innan tillträde).
2. Statuskedja följs i `hr_att_signera`; påminnelse vid behov.
3. Signerat avtal (PAdES → PDF/A + valideringsintyg) arkiveras i personalakten; "verifiera underskrift nu/då"-panel ger bevisbarhet över tid.
- *Compliance:* eIDAS art. 26 (giltigt/bindande), arkivlagen (bevarande), bevisvärde via LTV/tidsstämpling.

---

## Tillgänglighet & sekretess

**Tillgänglighet (DOS-lagen → EN 301 549 → bygg mot WCAG 2.2 AA redan nu):**
- **Target Size ≥24×24 px** på alla status-/klarmarkera-/snabbåtgärdsknappar i korten.
- **Dragging Movements (2.5.7):** all omordning/visa-dölj av kort och kanban-flytt måste ha **knapp-/tangentbordsalternativ**, inte bara drag — kärn-dashboarden klarar inte detta, Hubs egen vy måste lösa det.
- **Focus Not Obscured (2.4.11):** fokuserad rad/kort får aldrig döljas av sticky frist-strip eller expanderad Quick View.
- **Consistent Help (3.2.6) & Consistent Identification (3.2.4):** hjälp-/supportkort på fast plats; samma ikon = samma funktion mellan roller och sessioner.
- **Accessible Authentication (3.3.8):** inloggning via BankID/Freja/SITHS utan kognitiva test.
- **Reflow/Orientation:** vyn fungerar i porträtt och vid 400 % zoom (chefer/HR på mobil).
- Dokumentera efterlevnad per kriterium — tillgänglighet är ett tilldelningskriterium i offentlig upphandling.

**Sekretess & dataskydd:**
- **OSL + GDPR:** Hälsodata, sjukfrånvaroorsak, läkarintyg och rehabdokumentation är känsliga personuppgifter/särskild kategori. Rollvyn är **hårt behörighetsstyrd** — en widget får aldrig avslöja rubrik, medarbetarnamn eller antal från en HR-kö för någon utan behörighet (audience targeting = säkerhetsgräns, inte bekvämlighet). **Dataminimering:** korttext/bevakningstitel default = ärendereferens/initialer, aldrig klartext-diagnos.
- **On-prem som juridiskt argument:** all data i kundens egen driftmiljö → ingen extern part röjs informationen → ingen OSL 10:2a-lämplighetsbedömning, ingen CLOUD Act-/tredjelandsfråga (till skillnad från SaaS-signering och Teams/Outlook). Synlig "säker kanal · all data i er driftmiljö"-markör.
- **HSLF-FS 2016:40** (där HSL-nära, t.ex. företagshälsovård/SIP-angränsande): kryptering så att bara avsedd mottagare läser + stark autentisering (LOA3) — uppfylls by design.
- **Aldrig öppen e-post om rehab:** produkten ska aktivt styra bort från det otillåtna mönstret (vårdgivare/FK får ej mejla; medarbetarnamn ska ej stå i mejl) genom att göra den säkra kanalen till standardvägen.
- **Arkiv & spårbarhet:** ärenderum med gallringsregel per handlingstyp (kommunens dokumenthanteringsplan), versionshistorik, fullständig händelselogg och FGS-export — håll isär personliga gallringsbara bevakningar från ärendebundna allmänna handlingar.
- **NIS2/cybersäkerhetslagen (i kraft 15 jan 2026):** spårbar hantering, åtkomstlogg och "ej hanterade känsliga meddelanden över X dagar" är konkret stöd för ledningens systematiska säkerhetsarbete och kvalificerar som cybermiljard-/budgetåtgärd.

---

## Källor (fresh + grounding)

**Fresh (rehab/HR 2025–2026):**
- Försäkringskassan – Plan för återgång i arbete (krav, dag 30, ≥60 dgr, FK 7459, avstämningsmöte): https://www.forsakringskassan.se/arbetsgivare/att-forebygga-sjukfranvaro/plan-for-atergang-i-arbete
- Försäkringskassan – Arbetslivsinriktad rehabilitering: https://www.forsakringskassan.se/halso-och-sjukvarden/sjukdom-och-skada/arbetslivsinriktad-rehabilitering
- Arbetsgivarverket – Rehabiliteringsprocessen, olika steg: https://www.arbetsgivarverket.se/arbetsgivarguiden/rehabilitering/rehabiliteringsprocessen---olika-steg
- AFS 2023:2 (Planering och organisering av arbetsmiljöarbete, ersätter AFS 2020:5 från 1 jan 2025) – Hälsobolaget om AFS 2020:5: https://halsobolaget.se/afs-20205/
- MedHelp – Rehabilitering för arbetsgivare (34 %-/35 %-statistiken): https://www.medhelp.se/rehabilitering-arbetsgivare/
- MedHelp – Säker hantering av sjukfrånvaro och hälsodata: https://www.medhelp.se/saker-hantering-av-sjukfranvaro-och-halsodata/
- KI – Checklista rehabilitering för chef och HR (samtycke, process): https://medarbetare.ki.se/din-anstallning/arbetsmiljo-och-halsa/arbetsanpassning-och-rehabilitering/checklista-rehabilitering-for-chef-och-hr
- Scrive – Offentlig sektor och e-underskrifter (AES för anställningsavtal, BankID/Freja/OrgID): https://www.scrive.com/sv/resurser/kunskapscenter/nyheter/offentlig-sektor-och-e-underskrifter-checklista

**Grounding (interna analysunderlag):**
- `analysis-output/market-personas-anvandningsfall.json` (HR-gapet 66 %, FK/vårdgivare-mejlförbud, samtycke per post)
- `analysis-output/market-regulatorik.json` (NIS2/cybersäkerhetslagen, OSL 10:2a, eIDAS2/LOA3, cybermiljarden)
- `analysis-output/market-ux-trender.json` (GOV.UK task-list, Viva Card/Quick View, WCAG 2.2, FK/AF designsystem)
- `analysis-output/market-nextcloud-ekosystem.json` (widget-API, egen default-app, @nextcloud/vue)
- `analysis-output/extended/research-esignering.md` (Sweden Connect/Inera, PAdES/PDF/A, AES-standard)
- `analysis-output/extended/research-uppgifter.md` (bevakning, frist-eskalering, "skapa bevakning från meddelande")
- `analysis-output/extended/research-filer.md` (ärenderum per ärende, ACL, Retention, FGS)
- `analysis-output/extended/research-forms-apps.md` (Forms-gränser, Tables-motor, kalender-bokning + auto-videorum)
- `analysis-output/extended/research-personalisering.md` (låst kärna + kuraterat skal, lokal AI, audience targeting = säkerhetsgräns)
