# Persona-dashboard: Socialsekreterare (barn & familj)

> Personaliserad förstavy ("Hubs Start — Flödesnavet") för **en** Hubs-persona: socialsekreteraren inom barn och familj. Brand-regel: i produktnära text säger vi aldrig "Nextcloud" eller "Talk" — vi säger **Hubs**, **säkra meddelanden**, **säkert möte/videomöte**, **ärenderum**, **uppgifts-/bevakningsmodulen**. Underliggande appnamn används bara i denna interna analys för spårbarhet.
> Persona-id: `socialsekreterare`. Härleds automatiskt från grupp/kontext `socialtjanst` (alt. `barn-familj`). Datum: 2026-06-13. Körs på server v32 (Hub 25 Autumn).
> Designgrund: roll = låst kärna + kuraterat skal (se `research-personalisering.md`); task-orienterad triage, inte statistik (se `market-ux-trender.json`); väv in de föreslagna modulerna e-signering, uppgifter/bevakning, säkra filer/ärenderum, Forms-samtycke, kalender/säkra möten (se `extended/`).

---

## Persona & en dag i arbetet

**Vem:** En socialsekreterare på en barn- och familjeenhet i en svensk kommun. Hon är myndighetsutövare under socialtjänstlagen (nya **SoL 2025:400**, i kraft 1 juli 2025), arbetar enligt **BBIC** (Barns behov i centrum), och bär ett ständigt spänningsfält: **dokumentationsbördan tar nästan lika mycket tid som klientkontakten** (Umeå/Örebro-studier i personaunderlaget) och vid tidsbrist släpar journalföringen flera dagar. Hennes vardag är ett av kommunsektorns största enskilda flöden av känslig information: **ca 514 000 orosanmälningar om barn 2024** (Socialstyrelsen), och varje anmälan är en latent uppgift med en hård rättslig frist.

**De fasta tidslåsen som styr hennes dag (måste synas i dashboarden):**
- **Förhandsbedömning: max 14 dagar** från det att en orosanmälan inkommit till beslut om att inleda/inte inleda utredning ([Kunskapsguiden – Förhandsbedömning](https://kunskapsguiden.se/omraden-och-teman/barn-och-unga/handlaggning-och-dokumentation-med-barnet-i-centrum/aktualisera/forhandsbedomning/)). Under förhandsbedömningen får bara vårdnadshavare, anmälaren och barnet kontaktas.
- **Utredning: ska bedrivas skyndsamt och vara klar inom 4 månader** (11 kap. 2 § SoL; tidigare 11 kap. 1 §), förlängning bara vid särskilda skäl ([Socialstyrelsen – BBIC](https://www.socialstyrelsen.se/kunskapsstod-och-regler/omraden/barn-och-unga/barn-och-unga-i-socialtjansten/barns-behov-i-centrum/)).
- **Uppföljning av tidsbegränsade beslut** ska ske "i god tid innan beslutet upphör" ([Kunskapsguiden – Besluta](https://kunskapsguiden.se/omraden-och-teman/barn-och-unga/handlaggning-och-dokumentation-med-barnet-i-centrum/besluta/)).
- **Förvaltningslagens dröjsmålsregler**: parten kan efter 6 månader begära avgörande (11–12 §§ FL 2017:900).
- **Höjda dokumentationskrav i SoL 2025:400** — handläggning av ärenden och genomförande av insatser ska som huvudregel dokumenteras ([Socialtjänstlag 2025:400, riksdagen](https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/socialtjanstlag-2025400_sfs-2025-400/); [MFoF – ny socialtjänstlag 2025](https://mfof.se/om-mfof/ny-socialtjanstlag-2025.html)).

**En typisk dag (vad dashboarden ska stödja, steg för steg):**
1. **Morgon – triage.** Loggar in med SITHS/Freja/BankID (LOA3). Möter inte en mejlinkorg utan en **aggregerad "Att hantera"-kö**: nya orosanmälningar via SDK/säker e-post/digital fax, säkra svar från region/skola/polis, mötesinbjudningar, dokument som väntar på hennes underskrift. Varje rad bär en **kanalikon** och en **fristräknare**.
2. **Plocka & fördela.** Ur den **delade funktionsbrevlådan** (`orosanmalan@kommunen`, SKR:s funktionsadress-rekommendation 2025) tar hon eller fördelas ärenden. Otilldelat/oläst syns för hela enheten → inget faller mellan stolarna.
3. **Förhandsbedömning.** Öppnar en ny orosanmälan → **14-dagars countdown** startar synligt. Skapar ett **ärenderum** (dnr-baserad säker dokumentyta), lägger anmälan + bilagor där, gör kontroll i akt, dokumenterar.
4. **Utredning (BBIC).** För inledda ärenden: ärenderum med utredningsdokument (samredigering on-prem), **4-månaders frist** synlig, säker klientkommunikation via säkra meddelanden, hembesök bokas.
5. **SIP/samverkansmöte.** Kallar barn, vårdnadshavare, skola och region till ett **säkert videomöte** (BankID/Freja-verifiering i lobby för anhöriga utan myndighetskonto), inhämtar **samtycke** (Forms + signeringssteg), dokumenterar planen i ärenderummet.
6. **Beslut → signering → delgivning.** Färdigt beslut skickas **för underskrift** (avancerad e-underskrift, BankID/Freja); status spåras; beslutet **delges** klienten via säker kanal med synlig **leveranskvittens** ("levererat → öppnat → läst").
7. **Eftermiddag – stäng loopen.** Klarmarkerar bevakningar, ser att inga fristar är röda, dokumentationsskulden är liten. Tom kö = inget missat (ett **compliance-värde**, inte bara bekvämlighet).

**Smärtpunkter dashboarden ska döda:** systemträngsel (vill inte ha "system nummer åtta" – Arbetsmiljöverket/Suntarbetsliv); osäkerhet om att fax/meddelande nått fram (ersätts av kvittens); fristar i huvudet/på post-it (ersätts av bevakningar); dokumentation som släpar (ärenderum + mallar minskar friktionen); felskickad känslig info (rätt kanal, rätt mottagare, rätt LOA-nivå).

---

## Mål & nyckeltal (KPI)

Mät **tid-till-åtgärd och frist-träffsäkerhet**, aldrig tid-på-dashboarden.

| Mål | KPI | Datakälla |
|---|---|---|
| Aldrig missa en lagstadgad frist | Andel förhandsbedömningar avslutade inom 14 dgr; andel utredningar klara inom 4 mån; antal röda (förfallna) bevakningar = mål 0 | Bevakningsmodul (kanban-/VTODO-data) + ärendekoppling |
| Snabb triage av inflödet | Median tid-till-öppnat och tid-till-fördelat för ny orosanmälan (SDK/säker e-post/fax) | sdkmc `summary`-endpoint (kanalklassad inkorg) |
| Inget faller mellan stolarna | Antal otilldelade/olästa i funktionsbrevlådan > X timmar; eskaleringar | Delad funktionskö |
| Säker, kvitterad kommunikation | Andel utskick med läskvittens; andel via säker kanal vs fax | Leveransstatus per meddelande |
| Minskad dokumentationsskuld | Antal ärenden med journalföring > 3 dgr efter händelse; andel beslut e-signerade (vs utskrivet/postat) | Ärenderum + signeringsstatus |
| Faxavveckling (migrering) | Andel fax vs SDK/säkra meddelanden per månad | Kanalstatistik (Tables-motor) |
| Nytta/ROI (för chef) | Antal ersatta fax/rek-brev, uppskattad sparad tid (Diggs schablon ~30 min/ärende) | "Nytta hittills"-register |

---

## Primära åtgärder (verb-först)

De 3–5 åtgärder som ska vara nåbara i ett klick från förstavyn (och i Ctrl/Cmd+K-paletten). Verb-först enligt GOV.UK-mönstret.

1. **Ta emot & fördela orosanmälan** — plocka/ tilldela ur delad funktionsbrevlåda, starta 14-dagars förhandsbedömning. *Funktion:* delad funktionskö + bevakningsmodul (sdkmc-inkorg).
2. **Skapa ärenderum** — säker dokumentyta per dnr/barn med rätt behörighet (ACL), gallringsregel och deltagare. *Funktion:* säkra filer / ärenderum (Groupfolders + Retention).
3. **Skicka säkert meddelande / svara klient** — till klient (säker e-post + BankID-länk), till region/skola/polis (SDK), eller digital fax-brygga; med synlig leveranskvittens. *Funktion:* säkra meddelanden (sdkmc/securemail/fax).
4. **Kalla till säkert möte (SIP/samtal)** — boka tid → auto-skapat säkert videorum → BankID/Freja-lobby för anhöriga; samtycke via Forms. *Funktion:* kalender-bokning + säkert videomöte + Forms.
5. **Skicka beslut för underskrift** — avancerad e-underskrift (BankID/Freja), spåra status, arkivera PAdES/PDF/A, delge klient. *Funktion:* e-signeringsmodul (Inera Underskriftstjänst-API / Sweden Connect-nod).

---

## Widgetar (ordnad lista)

Ordnad efter dagens flöde och prioritet. Varje widget följer **Card View + Quick View** (progressive disclosure) och **GOV.UK-statusmodellen** (håll antalet statusar lågt). **Låst kärna** = alltid synlig för rollen (compliance/tillgänglighet); **kuraterat skal** = får ordnas/döljas.

| # | id | Titel | Syfte | Datakälla | App/funktion | Kärna/skal |
|---|---|---|---|---|---|---|
| 1 | `soc-att-hantera` | **Att hantera** (aggregerad triage-kö) | Förstavyn: alla inkommande som kräver åtgärd — nya orosanmälningar, säkra svar, fax, mötesinbjudningar, dokument att signera — med kanalikon (SDK/säker e-post/fax/möte) och fristräknare. Triage à la Linear/Superhuman; tom kö = inget missat. | **Befintlig** – sdkmc `/ocs/v2.php/apps/sdkmc/api/v1/summary` (server-side kanalklassning, en aggregeringsendpoint) | Säkra meddelanden (sdkmc/securemail/fax) + bevakningsmodul | Kärna |
| 2 | `soc-orosanmalningar` | **Orosanmälningar – förhandsbedömning** | Dedikerad kö för nya anmälningar med **14-dagars countdown** per anmälan, status (Ny / Under förhandsbedömning / Beslut inleda / Beslut ej inleda), källa (skola/vård/polis/privat) och kanal. Räknare överst: "3 förhandsbedömningar förfaller denna vecka". | **Föreslagen** – bevakningsregister (Tables-motor) kopplat till inkommande via sdkmc | Bevakningsmodul + Forms (internt anmälningsformulär) | Kärna |
| 3 | `soc-mina-bevakningar` | **Mina bevakningar / fristar** | "Mina uppgifter": utredningsfrister (4 mån), uppföljning av tidsbegränsade beslut, svarsfrister, FL 6-mån. Deadline-eskalering grå→gul→röd; verb-inledda titlar ("Slutför utredning – ärende 2026-114", "Följ upp beslut – insats upphör 30/6"). Påminnelse före deadline (T-7/T-3/T-0). Toggle Mina/Enhetens. | **Föreslagen** – uppgifts-/bevakningsmodul (kanban/VTODO som datalager, egen widget ovanpå) | Uppgifts-/bevakningsmodul | Kärna |
| 4 | `soc-arenderum` | **Mina ärenderum** | Översikt över öppna barn-/familjeärenden som säkra dokumentytor: dnr + kort titel, status (Ny/Påbörjad/Väntar på motpart/Klar för beslut/Klar/Problem), olästa dokument, väntar-på-signatur, gallrings-countdown, om medborgardelning är aktiv. | **Föreslagen** (orkestrering) ovanpå **befintliga** Groupfolders/ACL/versioner/Retention | Säkra filer / ärenderum (Collabora/OnlyOffice on-prem) | Skal (men hög prio) |
| 5 | `soc-dagens-moten` | **Dagens & veckans säkra möten** | Bokade/kommande säkra videomöten (klientsamtal, SIP, samverkan) med en-klicks-anslut och **lobby-status** ("2 i väntrum – Anna A. BankID/LOA3 verifierad, okänd SMS-kod"). | **Befintlig** – kalender + säkert videomöte (spreed-itsl) | Kalender + säkert videomöte | Skal |
| 6 | `soc-att-signera` | **Att signera & Skickat för signering** | Dokument som väntar på *min* underskrift (beslut, utredningar) med nivåbadge (AES standard) och deadline; spegelvy för utskickade (Skickat → Öppnat → Signerat → Arkiverat). Visar "Godkänn" (loggat) vs "Signera" enligt SKR:s riskmodell. | **Föreslagen** – e-signeringskö ovanpå Inera Underskriftstjänst-API / Sweden Connect-nod | E-signeringsmodul | Skal |
| 7 | `soc-leveransstatus` | **Leveranser & kvittens** | Spårar utgående säkra meddelanden/delgivningar: Skickad → Levererad → Notis öppnad → Inloggad (LOA3) → Läst → Besvarad, med feltillstånd (studsad / ej öppnad inom X dgr → eskalera). Den emotionella ersättningen för "ringa och kolla att faxen kom fram". | **Befintlig** – kvittensdata i sdkmc (receipt) | Säkra meddelanden | Skal |
| 8 | `soc-kunskapsbank` | **Kunskapsbank & mallar** | Genväg till rutiner, BBIC-mallar, "så gör du en orosanmälan-yta", gallringsplaner, samtyckesmallar — on-prem wiki. Minskar kognitiv börda och dokumentationsfriktion. | **Befintlig** – Collectives + dokumentmallar | Kunskapsbank (Collectives) | Skal |
| 9 | `soc-sakerhet-suveranitet` | **Säker kanal** (diskret markör) | Liten, alltid synlig markör: "All data i er driftmiljö · SITHS/LOA3 · HSLF-FS 2016:40 uppfyllt". Gör suveräniteten synlig i demo och stärker förtroende. | **Befintlig** – statisk + sessionsstatus | Plattform/identitet | Kärna |

**Valfritt AI-lager (avstängbart, lokalt):** ovanpå `soc-att-hantera`/`soc-orosanmalningar` kan en **lokal, grön-ratad modell** (llm2) föreslå *ordning* och en kort sammanfattning per inkommande anmälan, med synligt "varför" ("hög prio: frist imorgon + okänd avsändare"). AI får aldrig dölja/avföra ärenden, prioriterar **ärendeegenskaper** (frist/sekretess/oläst) inte användarbeteende (undviker profilering, GDPR art. 22), och har knapp "visa oredigerad kö". Säljs som "AI-prioritering utan att ett enda meddelande lämnar er server".

---

## Föreslagna appar/moduler (befintlig vs föreslagen + motivering)

| Modul | Status | Motivering för socialsekreteraren |
|---|---|---|
| **Säkra meddelanden** (SDK + säker e-post till klient + digital fax-brygga) | **Befintlig** (sdkmc, securemail, fax) | Kärnkanalen för orosanmälningar in och delgivning ut. SDK ersätter fax/post org↔org; säker e-post + BankID-länk når klient; fax-brygga är migreringsbrygga under övergång. HSLF-FS/OSL kräver krypterad kanal — ersätter okrypterad e-post/fax. |
| **Uppgifts-/bevakningsmodul** | **Föreslagen** (egen widget ovanpå kanban/VTODO som datalager) | Bygger bryggan inflöde→frist. Ingen vertikal konkurrent (Provisum/Aider) eller generiskt verktyg (Planner/Trello) kopplar meddelande→uppgift→ärende→påminnelse *inom samma sekretessäkra driftmiljö*. Täcker kanban-kärnans luckor (påminnelse före deadline, avisering bara till tilldelad). |
| **Säkra filer / ärenderum** | **Föreslagen orkestrering** ovanpå **befintliga** Groupfolders/ACL/versioner/Retention + on-prem kontorssvit | "Ett ärenderum per barn/dnr" är den bärande berättelsen: bilagor, utredning, beslut bor i rummet; kommunikationen *refererar* dit. ACL "least permission", gallring per handlingstyp enligt kommunens dokumenthanteringsplan, FGS-export till e-arkiv. On-prem eliminerar OSL-lämplighetsbedömningen. |
| **E-signering** (avancerad e-underskrift) | **Föreslagen** (kö/spårning ovanpå **Inera Underskriftstjänst-API** eller **Sweden Connect-nod**, Digg open source) | Beslut/utredningar signeras med BankID/Freja, arkivklart i PAdES/PDF/A med långtidsvalidering. Bygg inte signeringskärnan — äg arbetsytan. Differentierar på on-prem + signering *i* arbetsytan (mot Scrive/Assently molntjänster). |
| **Kalender-bokning + säkert videomöte** | **Befintlig** (kalender + spreed-itsl) | Klientsamtal och SIP: bokningslänk → auto-skapat säkert videorum → BankID/Freja-lobby för anhöriga utan konto. Löser gapet att Region Uppsala 2022 valde "Skype som säkraste plattformen". |
| **Forms (internt samtycke/inrapportering)** | **Befintlig, med tydlig gräns** | SIP-samtycke och internt anmälnings-/avvikelseformulär. *Konkurrera inte* med den publika orosanmälan-e-tjänsten (Open ePlatform/Abou) — Forms saknar native filuppladdning + förgrening. Använd för internt strukturerat + samtycke (kopplat till signeringssteg). |
| **Kunskapsbank** (Collectives) | **Befintlig** | Rutiner, BBIC-mallar, gallringsplaner on-prem — minskar dokumentationsfriktion och systemträngsel. |
| **Lokal AI-prioritering** (llm2, grön rating) | **Föreslagen, valfri** | Triage-stöd vid hög volym (514k anmälningar/år nationellt). Endast lokal modell → datasuveränitet bevarad; transparent och avstängbar. |
| **Maps (hembesök/geografi)** | **Avstå nu** | Maps-appen saknar v32-stöd och har osäker underhållsstatus. Hembesöksgeografi (förstärkt av SoL 2025:400 uppsökande) löses vid behov via adress-/kartfält i Tables-register, inte Maps-appen. |

---

## Terminologi (persona-anpassade ord)

Använd socialtjänstens språk, inte generiska it-termer. Lås mot Försäkringskassans/Arbetsförmedlingens öppna designsystem för formulär- och språkkonventioner.

| Generiskt / tekniskt | Persona-anpassat i Hubs |
|---|---|
| Inkorg / inbox | **Att hantera** · **Inkommande** |
| Ticket / ärende-objekt | **Orosanmälan** · **Ärende** · **Utredning** |
| Task / todo | **Bevakning** · **Frist** · **Uppgift** |
| Deadline | **Frist** (förhandsbedömning 14 dgr · utredning 4 mån · uppföljning) |
| Folder / projektrum | **Ärenderum** (per dnr/barn) |
| Message / DM | **Säkert meddelande** · **Delgivning** (utgående beslut) |
| Video call / meeting room | **Säkert möte** · **SIP-möte** · **Klientsamtal** |
| Sign / signature | **Underskrift** · **Skicka för underskrift** · (lågrisk) **Godkänn** |
| Read receipt | **Läskvittens** · **Leveransstatus** |
| Assign | **Fördela** · **Plocka** (ur funktionsbrevlådan) |
| Tags / labels | **Handlingstyp** · **Sekretessnivå** |
| Retention / TTL | **Gallring** · **Bevarande** (enligt dokumenthanteringsplan) |
| Login / auth | **Legitimering** (SITHS/BankID/Freja, **LOA3**) |
| Funktionskonto | **Funktionsbrevlåda** / **funktionsadress** (`orosanmalan@kommunen`) |

Statusuppsättning (GOV.UK, minimal): `Ny` · `Under förhandsbedömning` · `Påbörjad` · `Väntar på motpart` · `Klar för beslut` · `Klar` · `Åtgärd krävs` (rött).

---

## Flöden (end-to-end)

### Flöde 1 — Ta emot orosanmälan → förhandsbedömning → beslut → (ev.) inled utredning
1. **Inflöde.** En orosanmälan kommer in via SDK (från skola/vård/polis), säker e-post eller digital fax till funktionsbrevlådan `orosanmalan@kommunen`. Den dyker upp i `soc-att-hantera` och `soc-orosanmalningar` med kanalikon, oläst-status och en startad **14-dagars countdown**.
2. **Fördela.** En socialsekreterare **plockar** ärendet (eller registrator fördelar). Otilldelat syns för hela enheten tills någon tar ansvar.
3. **Skapa ärenderum.** Ett klick → `soc-arenderum` skapar en säker dokumentyta per dnr/barn, sätter ACL (handläggare skriver, kollegor läser), applicerar gallringsregel för handlingstypen, lägger anmälan + bilagor där.
4. **Förhandsbedömning.** Handläggaren kontaktar (inom ramen) vårdnadshavare/anmälare/barn, dokumenterar i ärenderummet via mallar från kunskapsbanken. `soc-mina-bevakningar` räknar ned mot 14-dagarsfristen med eskaleringsfärg.
5. **Beslut.** Beslut "inleda" / "inte inleda" dokumenteras. Lågrisk-beslut kan **godkännas** (loggat, ingen formell signatur enligt SKR); annars **skickas för underskrift** (AES). Anmälaren återkopplas via säker kanal med **leveranskvittens**.
6. **Stäng/öppna loop.** "Inte inleda" → bevakning klarmarkeras, gallring enligt plan. "Inleda" → övergår till Flöde 2 (utredning), 4-månadersfrist startar.

*Juridik:* förhandsbedömning ≤14 dgr; OSL/sekretess i hela kedjan; data on-prem (ingen lämplighetsbedömning); arkiv/gallring per dokumenthanteringsplan; orosanmälan ska inte ligga i elevakt — original till socialnämnden.

### Flöde 2 — Utredning (BBIC) → SIP/samverkansmöte → signerat beslut → delgivning
1. **Utredning startar.** Ärenderummet fylls med BBIC-utredningsdokument (samredigering on-prem). `soc-mina-bevakningar` visar **4-månadersfristen**; säker klientkommunikation och hembesök bokas från `soc-dagens-moten`.
2. **Kalla till SIP/samverkan.** Handläggaren bokar tid → **auto-skapat säkert videorum**; kallelse går via SDK (region/skola) och säker e-post (vårdnadshavare). Anhöriga utan myndighetskonto verifieras med **BankID/Freja i lobby**; mötesledaren med SITHS.
3. **Samtycke.** Inför mötet inhämtas **samtycke** (Forms + signeringssteg/BankID-loggad), arkiverat som handling i ärenderummet.
4. **Möte.** Säkert videomöte med lobby-insläpp per deltagare (synlig LOA-nivå); planen dokumenteras i ärenderummet.
5. **Beslut → underskrift.** Beslutet **skickas för underskrift** från `soc-att-signera` (AES via BankID/Freja); status spåras (Skickat → Signerat → Arkiverat); arkivklart i **PAdES/PDF/A** med långtidsvalidering.
6. **Delgivning.** Beslutet **delges** klienten via säker kanal; `soc-leveransstatus` visar levererat → öppnat → läst. Bevakning för ev. överklagandefrist/uppföljning skapas automatiskt.

*Juridik:* utredning skyndsam ≤4 mån; SIP-samtycke bryter sekretess; HSLF-FS 2016:40 (kryptering + stark autentisering) för HSL-nära delar; e-underskrift enligt eIDAS (AES standard); bevarande enligt Riksarkivet/dokumenthanteringsplan.

### Flöde 3 — Skapa bevakning från meddelande → följ upp tidsbegränsat beslut
1. På ett inkommande säkert meddelande/fax (t.ex. komplettering från skola) klickar handläggaren **"Skapa bevakning"** — titel förifylls (avsändare + ämne), länkas till meddelandet och kopplas till ärendets dnr; föreslagen frist sätts.
2. För **tidsbegränsade beslut** skapas en bevakning på beslutets slutdatum ("Följ upp – insats upphör 30/6") med påminnelse T-7/T-3.
3. `soc-mina-bevakningar` eskalerar färg mot fristen; vid klarmarkering väljs **"gallra (personlig notering)"** eller **"för till ärendet/diariet"** — håller isär privata att-göra-lappar från arkivpliktiga allmänna handlingar.

*Juridik:* uppföljning "i god tid innan beslutet upphör"; FL dröjsmålsregler; arkivlagen (skilj personlig notering från allmän handling); GDPR-dataminimering (ärendereferens, inte klartextcitat, som default).

---

## Tillgänglighet & sekretess

**WCAG 2.2 AA (DOS-lagen / EN 301 549 v4.1.1 – bygg mot 2.2 redan nu).** Träffar denna persona-vy direkt:
- **2.5.8 Target Size 24×24 px** — klarmarkera-, plocka-, status- och snabbåtgärdsknappar i alla widgetar.
- **2.5.7 Dragging Movements** — omordning av kort i "anpassa vy" och kanban måste ha **knapp-/tangentbordsalternativ** (flytta upp/ner), inte bara drag.
- **2.4.11 Focus Not Obscured** — fokus får inte döljas när Quick View/expansion eller filterpanel öppnas (sticky paneler).
- **3.2.6 Consistent Help** — kunskapsbank/hjälp på samma relativa plats i varje vy, **låst utanför** det konfigurerbara skalet.
- **3.3.8 Accessible Authentication** — SITHS/Freja/BankID-inloggning utan kognitiva test.
- **3.2.3/3.2.4 Consistent Navigation/Identification** — samma ikon = samma funktion mellan roller och sessioner; personaliseringen får inte bryta igenkänning.
- **1.4.10 Reflow / 1.3.4 Orientation** — fungerar vid 400 % zoom och i porträtt (relevant vid hembesök på mobil).
- Verb-inledda titlar, tydliga tomtillstånd ("Inga anmälningar väntar på åtgärd" som *positivt* besked), färgkodning aldrig som enda informationsbärare (komplettera frist-färg med text/ikon).

**OSL & sekretess (OSL 2009:400, 26 kap. socialtjänstsekretess + tystnadspliktslagen 2020:914):**
- **On-prem eliminerar OSL-lämplighetsbedömningen** (10 kap. 2 a § + eSam ES2023-06): ingen extern part får informationen. Visa "all data i er driftmiljö" i `soc-sakerhet-suveranitet`.
- **Behörighet = säkerhetsgräns, inte UX.** En widget får aldrig visa rubriker/avsändare/antal från en funktionsbrevlåda användaren saknar behörighet till. Audience targeting (IConditionalWidget) är här en åtkomstgräns.
- **Dataminimering** (GDPR art. 5): kort-/bevakningstext default = ärendereferens, inte klartextcitat av känsliga uppgifter om barn.
- **Orosanmälan är extra sekretesskänslig** — ska inte ligga i elevakt; original till socialnämnden; bara kort notering kvar i skolan.

**HSLF-FS 2016:40 (för HSL-nära flöden, t.ex. SIP/vårdplan):** kryptering så bara avsedd mottagare kan läsa + stark autentisering (MFA/LOA3). Kraven kan inte avtalas bort med samtycke. Synliggör efterlevnad i gränssnittet.

**eIDAS / LOA3 och frist-styrd identitet:** orosanmälan/socialtjänst = minst **LOA3** (BankID, Freja eID Plus, SITHS). Hubs ska **kräva och visa** miniminivå per flöde och **spärra** lägre Freja-/SMS-nivåer som ett synligt spärrtillstånd (inte tyst fel). Förbered statlig e-legitimation (nov 2026) och EUDI-plånbok (2026/27) — "eIDAS2-redo".

**Arkivlagen (1990:782) & NIS2/cybersäkerhetslagen (2025:1506, i kraft 15 jan 2026):** åtgärder i vyn (öppna/kvittera/besvara/signera) ska generera spårbara, bevarade händelser; skilj gallringsbar personlig notering från arkivpliktig allmän handling; loggning/spårbarhet stödjer ledningens systematiska säkerhetsarbete.

---

### Källor (fräscha, 2025–2026)
- Förhandsbedömning ≤14 dgr: https://kunskapsguiden.se/omraden-och-teman/barn-och-unga/handlaggning-och-dokumentation-med-barnet-i-centrum/aktualisera/forhandsbedomning/
- Besluta om att inleda/inte inleda utredning: https://kunskapsguiden.se/omraden-och-teman/barn-och-unga/handlaggning-och-dokumentation-med-barnet-i-centrum/aktualisera/besluta-om-att-inleda-eller-inte-inleda-en-utredning/
- BBIC + utredning skyndsam/4 mån: https://www.socialstyrelsen.se/kunskapsstod-och-regler/omraden/barn-och-unga/barn-och-unga-i-socialtjansten/barns-behov-i-centrum/
- Socialtjänstlag (2025:400): https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/socialtjanstlag-2025400_sfs-2025-400/
- MFoF – ny socialtjänstlag 2025 (dokumentationskrav): https://mfof.se/om-mfof/ny-socialtjanstlag-2025.html
- SKR – checklista inför 1 juli 2025: https://extra.skr.se/framtidenssocialtjanst/kunskapochstod/styrningledningsamverkan/checklistainfor1juli.86533.html
- Socialstyrelsen – orosanmälningar 514k (2024): https://www.socialstyrelsen.se/om-socialstyrelsen/pressrum/press/fler-barn-orosanmals--men-ofta-nar-det-har-gatt-for-langt/

*(Grundas i sin helhet på `analysis-output/` och `analysis-output/extended/`-underlagen; se respektive fils källförteckning för fullständiga referenser till OSL/HSLF-FS/eIDAS/NIS2/WCAG/SDK/SKR.)*
