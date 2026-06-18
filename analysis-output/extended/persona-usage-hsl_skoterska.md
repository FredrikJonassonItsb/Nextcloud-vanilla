<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Persona-användningsmönster: Kommunsjuksköterska (HSL) — `hsl_skoterska`

> **En realistisk arbetsdag för en kommunsjuksköterska i hemsjukvården, och exakt hur Hubs Start
> (dashboarden) faktiskt används timme för timme.** Underlag för demo, UX-validering och
> integrationsbygge. Datum: 2026-06-13. Plattform: server v32 (Hub 25 Autumn). Frontend: Vue 2.7.
>
> **Arkitektonisk ram (kundens egen, måste genomsyra varje rad):** Hubs är **mellanlagring**
> (middleware/staging) — den sekretesssäkra, on-prem-ytan där den tvärkanaliga kommunikationen runt
> utskrivningen *tas emot, bevakas, signeras och bearbetas*. **Slutlagringen (system of record) är
> alltid verksamhetens ärendehanteringssystem.** För denna persona finns det **två lager av
> slutlagring**: (1) **det regionala planeringssystemet** — främst **Lifecare Samordnad Planering
> (Lifecare SP, Tietoevry)**, även Cosmic Link, SAMSA, Meddix, Prator — som äger det *strukturerade*
> utskrivningsflödet; och (2) **kommunens HSL-journal** — **Treserva HSL (CGI) / Lifecare VoO /
> Combine / Viva** — som äger den kommunala journalanteckningen. Avvikelsen hamnar i (3)
> **regionens/kommunens avvikelsesystem** (ofta RLDatix/Platina-baserat). Hubs ersätter **ingen** av
> dem. Hubs fångar exakt det som *inte ryms* i det strukturerade flödet och som idag går via fax,
> telefon och brevpost — och som HSLF-FS 2016:40 kräver ska gå krypterat och mottagarverifierat.
>
> **Brand-regel:** i produkt-/UI-text säger vi aldrig "Nextcloud" eller "Talk". I detta interna
> underlag namnger vi apparna (sdkmc, securemail, mail/fax, spreed-itsl, Deck/Tasks, Tables, Forms,
> Groupfolders/Retention, Collectives, LibreSign, calendar, llm2/stt_whisper2) för att kunna wire:a.

---

## Persona i ett stycke

**Anna**, kommunsjuksköterska i en mellanstor kommuns hemsjukvård, ansvarar tillsammans med två
kollegor för ~45 patienter i ordinärt boende plus inkommande utskrivningar. Hon loggar in med
**SITHS eID (LOA3)**. Hennes dag domineras inte av video och möten — den domineras av **bevakning**:
att aldrig missa en *utskrivningsklar* (som kostar kommunen pengar per överskjutande dygn), att hinna
planera hemgång i tid, att svara regionen i säker kanal, att SIP blir kallad, och att brister
avvikelserapporteras. Hon har redan **minst två system öppna** (regionens Lifecare SP + kommunens
Treserva HSL) och vägrar ett tredje som *adderar* arbete. Hubs får bara förtjäna sin plats genom att
**aggregera och bevaka** det tvärkanaliga glappet — inte bli "system nummer åtta".

**Hennes dashboard (`hsl_skoterska`-layouten, exakt ur `personaConfig.js`):**
- **main:** `attHantera` · `utskrivningsbevakning` · `samverkansavvikelser` · `bevakningar` · `arenderum`
- **side:** `dagensMoten` · `funktionsbrevlador` · `kvittenser` · `minaUppgifter` · `senasteFiler` · `kunskapsbank`
- **primära åtgärder:** Kvittera utskrivningsmeddelande · Skapa samverkansavvikelse · Kalla till
  SIP-möte · Skicka säkert meddelande · Skapa bevakning från meddelande.

---

## En dag i arbetet (08:00 → 17:00, kronologiskt)

### 08:00 — Inloggning & morgontriage (SITHS, "vad hände i natt / över helgen")
Anna legitimerar sig med **SITHS eID**. Dashboardens diskreta `dataSuveranitet`-markör säger *"Inloggad
på tillitsnivå 3 · all data i er driftmiljö · HSLF-FS 2016:40 uppfyllt"*. Hon öppnar **`attHantera`**
(förstavyns nav): 7 inkommande som kräver åtgärd, server-side kanalklassade — 3 SDK från regionen, 2
säker e-post, 1 digital fax (läkemedelslista från en liten privat vårdgivare utan systemanslutning), 1
SIP-kallelse. Varje rad bär en **provenance-chip**: *kanal in · verifierad avsändare (SITHS/SDK
org-till-org) · → destination (Treserva HSL / Lifecare SP — ännu ej överförd)*.

**De konkreta ärendena denna morgon:**
- **Ärende A — Karin J., 84 år:** *underrättelse om utskrivningsklar* inkom 06:32 via SDK (kopia/avi
  från Lifecare SP-flödet + kompletterande meddelande). **Dygnsräknaren startar nu** mot
  betalningsansvarsgränsen.
- **Ärende B — Bertil S., 78 år:** *inskrivningsmeddelande* inkom i natt (slutenvården, inom 24 h
  enligt lag 2017:612). Preliminär utskrivningsdag om 4 dagar. Tidig planeringssignal.
- **Ärende C — Margit P., 91 år:** säker e-post från avdelningssköterska — fråga om hjälpmedel inför
  hemgång (rollator + hygienstol), faller *utanför* det strukturerade Lifecare SP-flödet.
- **Ärende D:** digital fax med läkemedelslista som **saknar** två preparat jämfört med tidigare lista
  — en informationsbrist som ska avvikelserapporteras.

### 08:15 — `utskrivningsbevakning`: kvittera Karin och läs av kr-risken
Anna öppnar **`utskrivningsbevakning`** (HSL-personans signaturwidget). Kön är deadline-sorterad med
GOV.UK-status och **dygnsräknare mot betalningsansvar**. Karin J. ligger som **Utskrivningsklar ·
dygn 0 av 3** med **grön** riskindikator (under genomsnittsmodellens 3 kalenderdagar). Anna trycker
primäråtgärden **"Kvittera utskrivningsmeddelande"** → status `Kvitterad`, tidsstämpel loggas,
`kvittenser` visar att regionen får leveranskvittens (den emotionella ersättningen för "ringa och kolla
att faxen kom fram"). Hon ser samtidigt att **Sven A.** (sedan i fredags) ligger på **dygn 3 av 3 ·
gul** — måste hem idag annars börjar **röd** (överskjutande dygn × dygnsbeloppet enligt
**HSLF-FS 2025:74** för 2026; jfr 10 500 kr/vårddygn 2023). Det blir dagens första prioritet.

### 08:30 — Skapa bevakningar så inget tappas över skiftbyten
För Bertil (inskrivningsmeddelande) trycker Anna **"Skapa bevakning från meddelande"** direkt i raden →
`bevakningar` får en post *"Planera hemgång – Bertil S."* med föreslagen frist (utskrivningsdag −1) och
påminnelser **T-7/T-3/T-0 bara till tilldelad** (Hubs egen logik ovanpå datalagret — täcker de kända
luckorna att standardaviseringar går till alla). För Sven sätter hon en **T-0-bevakning idag**.
Toggeln **Mina / Enhetens** låter henne se att kollegan redan tagit två andra utskrivningar.

### 09:00 — Ordna hemgång för Sven (den gula raden) — säker kanal + samordning
Anna ringer hemtjänstplaneraren (telefon, internt) men allt patientbärande går i **säker kanal**: hon
skickar **säkert meddelande** (SDK/securemail) till avdelningen för att bekräfta hemgångstid och
medicinsk överrapportering. Hon för **journalanteckningen i Treserva HSL** (kommunens slutlagring) — den
hör inte hemma i Hubs. Hubs *stagar* kommunikationen och kvittensen; **Treserva HSL äger anteckningen**.
När Sven kvitteras som *Hemtagen/Klar* nollas hans kr-risk.

### 09:45 — `samverkansavvikelse` i ett klick (Ärende D, saknad läkemedelslista)
Faxen med ofullständig läkemedelslista är en **informationsbrist i vårdens övergång** — ett av
patientsäkerhetens toppriskområden och **ska avvikelserapporteras**. Anna trycker **"Skapa
samverkansavvikelse"** direkt på meddelandet i **`samverkansavvikelser`**. Formuläret är **förifyllt**:
patient (ärende-id/pseudonym), motpart (region + avdelning), **bristtyp = "saknad läkemedelslista"**,
tidsstämplar och länk till källmeddelandet. Hon kompletterar en mening och skickar **säkert via SDK till
regionens avvikelsefunktion**. Hubs stagar; **avvikelsen slutlagras i regionens avvikelsesystem**
(RLDatix/Platina) och **MAS** följer trenden. Tidsåtgång: ~40 sekunder mot dagens 10–15 minuter i ett
separat system.

### 10:30 — `dagensMoten`: SIP-möte för Karin (säkert videomöte + anhörig i lobby)
Karin behöver insatser från **både** region och kommun → **SIP** ska kallas av fast vårdkontakt. Anna ser
i **`dagensMoten`** att ett SIP är bokat 13:00. Hon förbereder: primäråtgärd **"Kalla till SIP-möte"**
för en *ny* patient (Bertil, inför hemgång) → skapar ett **säkert videorum** (spreed-itsl) med
**BankID/Freja-lobby** så att **anhörig utan myndighetskonto** kan verifieras i väntrummet, medan
personal går in med **SITHS**. `identitetsBadge` visar metod + LOA per deltagare. Detta löser exakt det
gap där regioner i brist på alternativ valt Skype som "säkraste plattformen".

### 11:00 — `funktionsbrevlador`: plocka ur den delade `hemsjukvard@`/`svpl@`-kön
Utskrivningsbevakning är ett **teamflöde**, inte en personlig inkorg. Anna öppnar
**`funktionsbrevlador`** (behörighetsstyrd — visar bara brevlådor hon har OSL-behörighet till). Två nya
otilldelade i `svpl@`. Hon **plockar** en (hjälpmedelsfråga, Margit) och **fördelar** en till kollegan
som har hand om det geografiska området. "Vem tar detta?"-semantiken gör att inget tappas vid
pass-/semesterbyten.

### 12:00 — Lunch. Dashboarden är tom där det ska vara tomt
Inga röda rader i `utskrivningsbevakning`, inga förfallna i `bevakningar`. **Tom kö = inget missat = ett
compliance-värde**, inte bara bekvämlighet. Det är det Hubs mäter: *tid-till-åtgärd*, inte tid på
dashboarden.

### 13:00 — SIP-mötet för Karin (video, samtycke, planeringsunderlag i ärenderum)
Anna ansluter ett-klick ur `dagensMoten`. Anhörig släpps in ur lobbyn efter BankID-verifiering. Innan
mötet hämtades **SIP-samtycke** via **`mallarSamtycke`** (Forms + BankID) — samtycket bryter sekretess
mot region och bor som **allmän handling** i Karins **`arenderum`** (en Groupfolder per dnr/patient,
HSLF-FS-uppfyllt ACL + Retention). Under mötet förs **planeringsunderlaget** i ärenderummet; den
**formella SIP-planen committas in i Lifecare SP / Cosmic** (slutlagring) — Hubs blir inte ett tredje
planeringssystem. *(Inspelning/transkribering av själva klientsamtalet körs INTE skarpt än — se "Saknade
funktioner" §; juridiken, inte tekniken, är gränsen.)*

### 14:30 — `arenderum` + `senasteFiler`: dokumentstatus och dubbel-retention
Anna öppnar **`arenderum`**: hon ser per rum status, olästa dokument, väntar-på-signatur, om
medborgardelning är aktiv, och **två countdowns** — facksystemets bevarandestatus (*"journalförs i
Treserva HSL"*) **och Hubs egen rensning** (*"rensas ur Hubs 30 dgr efter överföring"*). Det gör
skillnaden mellan mellan- och slutlagring pedagogiskt synlig. **`senasteFiler`** visar "vad hände med
mina dokument senast": ny version av Karins läkemedelslista uppladdad av regionen, hjälpmedelsintyg
delat.

### 15:30 — `kvittenser`: stäng loopen på dagens utskick
Anna kontrollerar **`kvittenser`**: leveranstidslinjen per utgående meddelande/delgivning
(Skickad → Levererad → Öppnad → Läst → Besvarad). Sven-avdelningens bekräftelse är *Läst 09:51*; en
fråga till primärvården är *Levererad men ej öppnad* sedan i förrgår → hon trycker **eskalera/påminn**.
KPI:n "obesvarade säkra meddelanden från region > X dagar" hålls nere.

### 16:15 — `bevakningar` & `minaUppgifter`: imorgon-planering
Hon går igenom **`bevakningar`** (frist-fokus, eskaleringsfärg grå→gul→röd) och **`minaUppgifter`**
(arbets-/genomförandefokus, GOV.UK task-list: "Följ upp hjälpmedel – Margit", "Förbered hemgång –
Bertil"). Strip överst: *"3 frister denna vecka"*. Inget rött kvar.

### 16:45 — `kunskapsbank`: en snabb rutinkontroll, sedan utloggning
Innan hon loggar ut slår hon upp kommunens **rutin för betalningsansvarsberäkning** i `kunskapsbank`
(Collectives, on-prem) — fast plats, alltid samma genväg (WCAG 3.2.6 Consistent Help). Utloggning.
Dagens nettoeffekt: **0 missade utskrivningsklar, 1 avvikelse rapporterad på 40 sekunder, allt
patientbärande i säker kanal, journal och plan committade till facksystemen.**

---

## Hur Hubs + dashboarden faktiskt används (öppningsordning & åtgärder)

| Tid | Widget öppnad | Varför just nu | Åtgärd (verb-först) |
|---|---|---|---|
| 08:00 | `attHantera` | Förstavyns nav — "vad kräver åtgärd" | Triagera, läs provenance-chips |
| 08:15 | `utskrivningsbevakning` | Den dyra, deadline-kritiska kön först | **Kvittera utskrivningsmeddelande**; läs kr-risk |
| 08:30 | `bevakningar` | Säkra att inget tappas över skift | **Skapa bevakning från meddelande** (T-7/T-3/T-0) |
| 09:00 | `attHantera` → utgående | Ordna hemgång för gul/röd rad | **Skicka säkert meddelande** till avdelning |
| 09:45 | `samverkansavvikelser` | Informationsbrist upptäckt (Ärende D) | **Skapa samverkansavvikelse** (förifylld → SDK) |
| 10:30 | `dagensMoten` | SIP behövs (region + kommun) | **Kalla till SIP-möte** (auto-videorum + lobby) |
| 11:00 | `funktionsbrevlador` | Teamkö, plocka/fördela | Plocka & fördela ärende ur `svpl@` |
| 13:00 | `dagensMoten` + `arenderum` + `mallarSamtycke` | SIP-mötet, samtycke, underlag | Anslut; hämta SIP-samtycke (BankID); dokumentera i rum |
| 14:30 | `arenderum` / `senasteFiler` | Dokumentstatus + retention | Granska, se dubbel-countdown |
| 15:30 | `kvittenser` | Stäng leveransloopen | Eskalera ej öppnat utskick |
| 16:15 | `bevakningar` / `minaUppgifter` | Imorgon-planering | Klarmarkera, planera |
| 16:45 | `kunskapsbank` | Rutinuppslag | Läs betalningsansvarsrutin |

**Mönster:** Anna lever i **`attHantera` → `utskrivningsbevakning`**-axeln hela dagen; `samverkansavvikelser`,
`dagensMoten` och `funktionsbrevlador` är de återkommande *aktions*-ytorna; `kvittenser`, `arenderum`,
`senasteFiler`, `bevakningar`, `minaUppgifter`, `kunskapsbank` är *stöd*-ytor hon dyker i och ut ur.

---

## Widget → app → system-of-record-karta (per widget i denna personas layout)

> Mellanlagrings-modellen explicit per rad: **Hubs stagar X → handläggaren för över till {facksystem}.**
> "App/funktion" = intern Nextcloud-app (för wiring). "Källa" = varifrån data kommer in. "Slutlagring" =
> system of record. "Handoff-mönster" = A (API/REST) · B (drag-to-case) · C (FGS-export) · D (manuell).

### Main-kolumnen

| Widget | App/funktion (intern) | Källa IN (provenance) | Hubs stagar (mellanlagring) | Slutlagring (system of record) | Handoff |
|---|---|---|---|---|---|
| **`attHantera`** | `sdkmc` + `securemail` + `mail`/fax; summary-endpoint (server-side kanalklassning) | Region↔kommun via SDK/AS4, säker e-post, digital fax; SITHS/SDK org-till-org verifierad | Aggregerad triagekö med kanalikon + frist + **→-destination-chip** | Beroende på rad: **Treserva HSL** (journal) / **Lifecare SP** (planering) / regionens avvikelsesystem | A/D |
| **`utskrivningsbevakning`** | `sdkmc` (inflöde) + Deck/Tasks (bevakning) + Tables (status/dygnsregister); lag 2017:612 | *Underrättelse om utskrivningsklar* m.fl. (Lifecare SP-kopia/SDK); inskrivningsmeddelande (24 h) | Deadline-kö + **dygnsräknare mot betalningsansvar** + kr-riskindikator (grön<3/gul/röd); kvittens | **Lifecare SP** (strukturerad planering) + **Treserva HSL** (kommunal journalanteckning vid hemtagning) | **Hubs stagar kvittens & bevakning → ssk för över journalanteckning till Treserva HSL; planeringssvar matas i Lifecare SP** (A/D) |
| **`samverkansavvikelser`** | Tables (avvikelseregister) + `sdkmc` (säkert utskick); Forms för internt fält | Brist upptäckt i inkommande meddelande (saknad läkemedelslista / för sen underrättelse / uteblivet inskrivningsmeddelande) | Förifylld avvikelse (patient-id, motpart, bristtyp, tidsstämplar) | **Regionens/kommunens avvikelsesystem** (RLDatix/Platina); **MAS** följer trend (PSL 2010:659) | **Hubs stagar & skickar säkert via SDK → avvikelsen slutlagras i avvikelsesystemet** (A/B) |
| **`bevakningar`** | Deck (delad board) + Tasks (VALARM-påminnelser); GOV.UK-statusmodell | "Skapa bevakning från meddelande"; lagstadgade frister (utskrivningsdag, SIP, betalningsansvar) | Frist-/eskaleringslista (grå→gul→röd), påminnelser T-7/T-3/T-0 bara till tilldelad | **Treserva HSL/Lifecare** äger den *formella* fristbevakningen; Hubs bevakar **glappet före registrering** | **Hubs stagar arbetsbevakningen → formell bevakning/aktivitet committas i facksystemet; Hubs-kort gallras** (D) |
| **`arenderum`** | `files` + `groupfolders` (ACL + versioner + Retention) + Collabora/OnlyOffice | Bilagor ur säkra meddelanden, medborgardelning (BankID), SIP-samtycke, samredigerade utkast | En säker dokumentyta per dnr/patient; **dubbel countdown** (facksystem-bevarande + Hubs-rensning) | **Treserva HSL** (journalbilaga) / **Lifecare SP** (SIP-plan) / e-arkiv (FGS) vid avslut | **Hubs stagar dokument → original committas till facksystem/e-arkiv → Hubs-rum gallras** (A/C/D) |

### Side-kolumnen

| Widget | App/funktion (intern) | Källa IN | Hubs stagar | Slutlagring | Handoff |
|---|---|---|---|---|---|
| **`dagensMoten`** | `calendar` (Appointments) + `spreed`-itsl (auto-videorum + BankID/Freja-lobby) | Bokade/kommande SIP-/planeringsmöten; anhörig som extern deltagare | Mötesvy med ett-klicks-anslut + lobbystatus + LOA per deltagare | Mötet **äger inget rekord** — SIP-plan → **Lifecare SP/Cosmic**, anteckning → **Treserva HSL** | D/A (transit) |
| **`funktionsbrevlador`** | `sdkmc` funktionsadress-stöd (`hemsjukvard@`/`svpl@`); behörighet = säkerhetsgräns | Inkommande till delad verksamhetsbrevlåda (SKR 2025-rekommendation) | Oläst/otilldelat per brevlåda; plocka/fördela/eskalera | Samma som det plockade ärendets destination (Treserva HSL / Lifecare SP / avvikelsesystem) | A/D |
| **`kvittenser`** | `sdkmc` receipt-data (AS4-kvittens) | Egna utgående meddelanden/delgivningar | Leveranstidslinje Skickad→Levererad→Öppnad→Läst→Besvarad + feltillstånd | Kvittensbevis bevaras som spårbarhet; utfallet är redan committat i facksystemet | (spårbarhet) |
| **`minaUppgifter`** | `tasks` (VTODO/CalDAV, native påminnelser) | Personliga att-göra härledda ur ärenden/frister | Genomförandelista (GOV.UK task-list) | Gallras som personlig notering; ärendebundet utfall → Treserva HSL | D |
| **`senasteFiler`** | `files` + `files_versions` (Groupfolders) | Ny version / delning / uppladdning av motpart | "Vad hände med mina dokument senast" med ärenderum-kontext | Original i facksystem/e-arkiv; Hubs-kopia gallras | (referens) |
| **`kunskapsbank`** | `collectives` (wiki on-prem) | Statiskt referensmaterial (rutiner, mallar, gallringsplaner) | Genväg till HSL-rutiner, betalningsansvarsmodell, SIP-mallar | Inget ärenderekord — internt stöd, låst utanför konfigurerbara skalet | — |
| **`dataSuveranitet`** (diskret markör, alla vyer) | compliance-modul (statisk + åtkomstlogg-härledd) | — | *"All data i er driftmiljö · 0 tredjelandsöverföringar · SITHS LOA3"* | Svaret på OSL 10:2a + CLOUD Act medan datat är hos oss | — |

---

## Typiska arbetsmönster & återkommande flöden (end-to-end)

### Flöde 1 — Utskrivningsklar → kvittens → hemtagning → journal (betalningsansvars-flödet)
**Källa:** *underrättelse om utskrivningsklar* från slutenvården, in via **Lifecare SP-kopia/SDK**
(`attHantera`/`utskrivningsbevakning`). **Mellanlagring i Hubs:** dygnsräknaren startar; ssk
**kvitterar** (`kvittenser` → leveranskvittens till region); kr-riskindikator visar grön/gul/röd mot
genomsnittsmodellen (3 kalenderdagar, belopp HSLF-FS 2025:74). Ssk ordnar hemgång via **säkert
meddelande** till avdelning + hemtjänstplanering. **Slutlagring:** journalanteckningen om hemtagning
förs in i **Treserva HSL** (mönster D/A); planeringsstatus uppdateras i **Lifecare SP** (separat SITHS-
inloggning). Hubs *aggregerar och bevakar* — blir inte tredje journalsystemet. **Loop stängd** när raden
markeras *Hemtagen/Klar*; Hubs-mellanlagring gallras enligt Retention. **Kr-värdet:** ett missat/sent
hanterat meddelande = överskjutande dygn × dygnsbeloppet = femsiffrigt per patient — bevakning är en
ekonomisk kontroll.

### Flöde 2 — Informationsbrist → samverkansavvikelse i ett klick (patientsäkerhets-flödet)
**Källa:** ett inkommande meddelande/fax avslöjar en brist (saknad läkemedelslista, för sen
underrättelse, uteblivet inskrivningsmeddelande). **Mellanlagring:** ssk trycker **"Skapa
samverkansavvikelse"** på meddelandet → `samverkansavvikelser` förifyller patient-id, motpart, bristtyp
och tidsstämplar ur meddelandets metadata. **Slutlagring:** avvikelsen skickas **säkert via SDK** till
**regionens avvikelsefunktion** (mönster A/B) och slutlagras i **avvikelsesystemet** (RLDatix/Platina);
**MAS** följer trend för systematiskt patientsäkerhetsarbete (PSL 2010:659). Detta vänder en lagstadgad
börda (avvikelsen *ska* rapporteras) till en svårkopierad differentiator — ingen ren meddelandeklient
binder ihop kommunikationsbristen med dess obligatoriska uppföljning.

### Flöde 3 — SIP: kallelse → säkert videomöte → samtycke → plan (samordnings-flödet)
**Källa:** patient som efter utskrivning behöver insatser från **både** region och kommun. **Mellanlagring:**
**"Kalla till SIP-möte"** ur `dagensMoten` → kallelse i säker kanal till region/kommun/anhörig → auto-
skapat **säkert videorum** (spreed-itsl) med **BankID/Freja-lobby** för anhörig utan konto, SITHS för
personal; **SIP-samtycke** hämtas via `mallarSamtycke` (Forms + BankID) och bor som allmän handling i
`arenderum`; planeringsunderlag förs i ärenderummet. **Slutlagring:** den **formella SIP-planen committas
in i Lifecare SP / Cosmic** (mönster A/D); kommunal HSL-anteckning → Treserva HSL. Hubs löser det gap där
marknaden saknar ett bra *säkert* SIP-videoverktyg (Skype-ersättning), on-prem + BankID-lobby är skarpare
juridiskt än SaaS-alternativen.

### Flöde 4 — Tvärkanaligt inflöde via funktionsbrevlåda → bevakning → svar (team-/kontinuitets-flödet)
**Källa:** allt som *inte* ryms i det strukturerade flödet (kompletterande remiss, hjälpmedelsunderlag,
fråga mellan vårdgivare, fax från liten vårdgivare utan systemanslutning) landar i delad
**`hemsjukvard@`/`svpl@`** (`funktionsbrevlador`). **Mellanlagring:** teamet **plockar/fördelar**; ssk
skapar **bevakning från meddelande** (`bevakningar`) med påminnelse före frist; svarar i säker kanal;
`kvittenser` bekräftar leverans/läsning. **Slutlagring:** det som blir en formell handling/aktivitet
**registreras/committas i Treserva HSL/Lifecare SP**; Hubs-bevakningen får status "förd till ärendet" och
**gallras** — den blir aldrig en konkurrerande, oarkiverad fristlista. **Kontinuitetsvärdet:** inget tappas
över pass-/semesterbyten — för sekretessbelagd vård ett patientsäkerhets- och compliance-värde.

---

## Saknade funktioner för denna persona — och hur de wire:as

### 1. Mötestranskribering + lokal AI-sammanfattning av SIP/vårdplanering (record → transcribe → summarise → spara i ärende)
**Behovet:** efter ett SIP-/planeringsmöte sitter ssk och skriver av beslut, ansvar och åtgärder för
hand — tidskrävande och felkänsligt. **Hela kedjan finns redan som on-prem-byggblock** (ingen molntjänst):
- **Inspelning:** `spreed`-recording server (kräver **HPB**), `recording_consent` **påtvingat** +
  samtycke loggat; WebM styrs in i patientens `arenderum` (ärver ACL + Retention).
- **Transkribering (Sverige-kärnan):** `stt_whisper2` med **KB-Whisper** (KBLab, Apache-2.0, ~47 % lägre
  WER på svenska än whisper-large-v3) som **drop-in-modell** — Hubs viktigaste konfigurationsval och ett
  konkret upphandlingsargument. *(Live-textning via `live_transcription`/Vosk stödjer ej svenska än → byggs
  inte in nu.)*
- **AI-sammanfattning:** `llm2` (lokal, grön-ratad GGUF, t.ex. OLMo 2) + Assistant + `call_summary_bot` →
  **utkast** med kort sammanfattning, beslut, åtgärdslista med ansvarig. Långa transkript kräver
  **chunkning/map-reduce** (kontextfönster 4–8k tokens) — den enda riktiga bygguppgiften.
- **Human-in-the-loop:** sammanfattningen är **utkast** tills ssk redigerat och tryckt **"Godkänn"**
  (loggad händelse, GDPR art. 22); rå-WebM + rå-transkript får **kort gallringsfrist** (Retention).
- **Wiring i katalogen:** **inget nytt widget-id krävs för MVP** — hänger på `dagensMoten`, landar i
  `arenderum`/`senasteFiler`, sammanfattnings-utkastet blir en `minaUppgifter`/`bevakningar`-post
  ("Granska & godkänn mötesanteckning"). Föreslagen modul: *"Mötesanteckningar & lokal AI-sammanfattning"*.
- **⚠️ Skarphetsgräns (juridik, inte teknik):** för **sekretessbelagda klientsamtal/SIP** körs detta
  **inte skarpt än** — invänta IMY/SKR/Socialstyrelsen-vägledning; rättslig grund + samtycke + gallring +
  human-in-the-loop är **förutsättningar, inte tillval**. On-prem **löser tredjelandsfrågan** men inte hela
  OSL/arkiv-frågan. **Demobart nu:** internt, icke-sekretessbelagt processmöte (utan klartext-
  personuppgifter) → hela kedjan + suveränitetsmarkören utan att exponera sekretess.
- **Slutlagring:** den **godkända** mötesanteckningen — inte WebM, inte rå-transkriptet — committas till
  **Treserva HSL / Lifecare SP**; rå-artefakterna gallras. "Spara till ärende"-åtgärd (manuell i demo,
  facksystem-API på sikt).

### 2. Tvärkanalig todolista/bevakning som inte dubblerar facksystemet ("inflödet innan det blir ett ärende")
**Behovet:** ssk vill ha *en lista så inget glöms* — men Treserva HSL/Lifecare har **redan** formell
fristbevakning (texten blir röd vid passerat datum, antal dagar före varning konfigurerbart). **Hubs får
inte konkurrera/dubblera.** Hubs äger **det inkommande som ännu inte är registrerat** i facksystemet (ny
utskrivningssignal, säkert meddelande från region, fax). **Wiring:** `minaUppgifter` (personlig) på
**Tasks/VTODO** (native VALARM-påminnelser); `bevakningar` + delad funktionskö på **Deck** (tilldelning,
kort↔kort-relation till ärendet). Hubs bygger **det Deck-kärnan saknar**: påminnelse *före* deadline
(T-7/T-3/T-0) **bara till tilldelad** (täcker Deck #1549/#566) + knapp-/tangentbordsalternativ till drag
(WCAG 2.5.7). **Signaturfunktionen:** "Skapa bevakning från meddelande" (förifyller titel, länkar
meddelandet, föreslår frist, kopplar ärendereferens). **Arkivmedvetenhet vid klarmarkering:** val mellan
"gallra (personlig notering)" och "för till ärendet/facksystemet" — håller isär gallringsbara
att-göra-lappar från ärendebundna allmänna handlingar (arkivlagen 1990:782 / OSL).

### 3. Betalningsansvars-räknare med kr-exponering som riktig ekonomisk kontroll (signaturfunktionen att vässa)
**Behovet:** gör den ekonomiska konsekvensen av missad bevakning explicit och aggregerbar. **Wiring:**
`utskrivningsbevakning` per-rad dygnsräknare + en **Tables-backad** månadsaggregering ("dygn över
betalningsansvarsgräns denna månad · kr-exponering") som matar `nytta`-widgeten i chef-/MAS-läge. Belopp
parametriseras per år (**HSLF-FS 2025:74** för 2026) och per **regionens överenskomna genomsnittsmodell**
(de flesta: snitt 3 kalenderdagar/månad; psykiatri tuffare default). **Ingen konkurrent kopplar säker
kommunikation till den ekonomiska konsekvensen** — det här talar direkt till ekonomichef/förvaltningschef
och stödjer cybermiljards-/budgetäskande.

### 4. e-godkännande/e-underskrift av vårdplan/SIP-plan (flerpart, SITHS + BankID)
**Behovet:** SIP-/vårdplan ska kunna **godkännas** (loggat, SITHS — SKR:s "Godkänn" för lågrisk) eller
**signeras** (AES) av flera parter. **Wiring:** `attSignera`/`skickatForSignering` mot en
**signeringsadapter** — **LibreSign** internt/lågrisk (märk ärligt: konto/SITHS-inloggning, ej BankID-
AES), **Inera Underskriftstjänst-API** (mTLS + SITHS funktionscert, BankID/Freja/SITHS, PAdES + PDF/A-1)
för det skarpa. Bygg **bevarandepanelen "Giltig nu / Giltig då"** (PAdES/PDF/A/LTV) — gapet ingen
konkurrent säljer. **Slutlagring:** signerad PDF/A + valideringsintyg committas till Treserva HSL /
Lifecare SP. *(Sekundärt för denna persona — flödet är primärt kvittens/avvikelse, inte signering — men
nödvändigt när vårdplaner ska bära underskrift.)*

---

## KPI:er (ur `personaConfig.js`, kopplade till flödena ovan)
- **Dygn över betalningsansvarsgräns denna månad (kr-exponering)** ← Flöde 1 + saknad funktion 3
- **Antal utskrivningsklar-meddelanden obekräftade** ← `utskrivningsbevakning` kvittens
- **Antal samverkansavvikelser + trend** ← Flöde 2 (MAS)
- **Obesvarade säkra meddelanden från region > X dagar** ← `kvittenser` eskalering
- **Andel meddelanden i säker kanal vs fax** ← faxavvecklingskurvan (migreringsvärde)
- **Tid-till-kvittens på inkommande** ← `attHantera`/`utskrivningsbevakning`

---

## Källor (urval — fullständiga i de refererade underlagen)
- `research-utskrivning-hsl.md` — lag (2017:612); betalningsansvar (genomsnittsmodell 3 kalenderdagar);
  HSLF-FS 2025:74 (belopp 2026); HSLF-FS 2016:40; Lifecare SP/Cosmic Link/SAMSA/Prator;
  samverkansavvikelser; SIP/Skype-gapet.
- `middleware-architecture.md` — mellanlagring vs slutlagring; provenance-band; fem-stegs-livscykeln;
  integrationsmönster A–D; per-persona varifrån→vart.
- `arendehantering-map.md` §2.3 — HSL två lager (Lifecare SP + Treserva HSL); avvikelse → regionens
  avvikelsesystem; Cosmic till Region Stockholm (nov 2025), NLL-lagkrav 1 dec 2025.
- `native-apps-map.md` — app-id, deep-links, kapabilitet/begränsningar (sdkmc, spreed-itsl, calendar,
  Deck/Tasks, Tables, Forms, Groupfolders/Retention, Collectives, LibreSign, AI-stacken).
- `transcription-ai.md` — record→transcribe→summarise; KB-Whisper (Apache-2.0); `llm2`/Assistant/
  `call_summary_bot`; human-in-the-loop; juridikens skarphetsgräns.
- `esign-todo-native.md` — LibreSign vs Inera/Sweden Connect; todolista-gapet; "Skapa bevakning från
  meddelande"; system-of-record-mappning.
- Färska bekräftelser (jun 2026): HSLF-FS 2025:74 (Socialstyrelsen, publ. 2025-12-09); Lifecare SP vuxna
  drift maj 2025, barn/unga-utrullning våren 2026 (Region Gotland/Tietoevry).
