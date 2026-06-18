<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# UX-koncept — "Mina ärenden" (ärende-centrisk Hubs Start för socialsekreteraren)

> **Koncept-id:** `ux-concept-arende` · **Persona:** `socialsekreterare` (barn & familj, SoL 2025:400, BBIC)
> · **Vinkel:** ärende-centrisk — allt organiseras kring *ärendet* som ett kort med synlig process-stepper,
> nästa-åtgärd-knapp, frist-indikator och inline-snabbåtgärder. **Plattform:** server v32 (Hub 25 Autumn) ·
> **Frontend:** Vue 2.7 + @nextcloud/vue v8.
>
> **Antagande (per uppdrag):** alla blockerare är lösta — Treserva-integration via **Frends** (iPaaS) är klar
> med verifierad commit-återkoppling, **Inera Underskriftstjänst** (AES) finns på plats, laglig + lokal
> **transkribering** är juridiskt klarställd, och **Retention-paus** finns i Hubs. Vyn designas därför som ett
> **skarpt verktyg** — varje åtgärd i den är riktig, inte "föreslagen".
>
> **Varumärkesregel:** UI-text säger aldrig "Nextcloud" eller "Talk". Vi säger Hubs, ärenderum, säkert möte,
> säkert meddelande, e-underskrift, facksystemet/Treserva. Interna app-id nämns bara i byggnoteringar.

---

## Bärande idé (1 stycke) & varför det är lätt att förstå

**Ett ärende = ett kort. Kortet vet var i processen ärendet är, vad som ska hända härnäst, och hur lång tid
det är kvar.** Hela vyn är listan av Annas aktiva ärenden, vart och ett renderat som ett **ärendekort** med en
horisontell **process-stepper** (Förhandsbedömning → Utredning → Beslut → Uppföljning → Avslutat), en stor
**"Nästa åtgärd"-knapp** som alltid pekar på exakt det steg ärendet väntar på, en **frist-chip** i färg, och en
rad **inline-snabbåtgärder** (öppna ärenderum · skicka säkert · boka möte · signera · för till Treserva). Det
otrierade inflödet — nya orosanmälningar och inkommande som ännu inte hör till ett ärende — ligger i en separat,
tydligt avgränsad **"Att ta emot"-ström** överst, så att "nya saker som kräver ett ärendebeslut" aldrig blandas
med "ärenden jag redan driver". Det är lätt att förstå därför att det matchar hur en socialsekreterare redan
tänker — *"mina barn/familjer och var de är i sin process"* — i stället för 13 parallella verktygswidgetar som
var och en visar en skiva av verkligheten. Mentalmodellen blir en enda: **en rad per barn, en stepper som visar
vägen, en knapp som visar nästa steg.** Den nya handläggaren behöver inte lära sig vilka tretton appar som gör
vad; hon behöver bara läsa sina ärendekort uppifrån och ned och trycka på den knapp som lyser.

---

## Informationsarkitektur (zoner uppifrån och ned)

Vyn är en enspaltig, prioritetsordnad kolumn (bento-griden är medvetet bortvald för denna persona — en
linjär läsordning sänker kognitiv last och fungerar i porträtt/400 % zoom). Sex zoner, uppifrån och ned:

### Zon 0 — Sidhuvud & lägesrad ("Min dag")
Tunn, sticky rad högst upp: **"God morgon, Anna · 22 aktiva ärenden"** + en **fristsammanfattning i klartext**:
*"2 förhandsbedömningar förfaller inom 3 dagar · 1 utredning klar för beslut · 0 förfallna."* Längst till höger:
diskret **datasuveränitets-markör** ("Säker kanal · all data i er driftmiljö") och **Ctrl/Cmd+K**-hint.
*Varför först:* ger på 5 sekunder svar på "är något på väg att brinna?" innan man scrollar. Inget actionrikt —
det är en temperaturmätare. Siffrorna är klickbara filter ("visa de 2 som förfaller").

### Zon 1 — "Att ta emot" (otrierat inflöde — triage-strömmen)
En avgränsad sektion med egen rubrik och **räknarbadge** ("Att ta emot · 4"). Här ligger det som ännu **inte är
ett ärende jag driver**: nya orosanmälningar ur funktionsadressen `orosanmalan@`, samt inkommande säkra
meddelanden/fax/svar som väntar på att kopplas till rätt ärende. Varje rad är **smal** (en triagerad, inte
fullt utvecklad, presentation): kanalikon · avsändare + verifierad LOA/identitet · inkom-tid · **14-dgr-chip
som redan tickar** (bunden till inkom-datum, inte plock) · destinations-chip ("→ Treserva — ej registrerad").
Två primärknappar per rad: **"Ta emot & starta förhandsbedömning"** och **"Koppla till befintligt ärende"**.
*Varför här, separerat:* triage-paradigmet (Linear Triage / Superhuman Split Inbox) — "nya saker som behöver
ett ärendebeslut" är en annan kognitiv uppgift än "driva mina pågående ärenden". När strömmen är tom står det
**"Inget otriagerat — allt inkommande är omhändertaget"** (compliance-värde, inte bara tomt tillstånd).

### Zon 2 — "Kräver åtgärd nu" (de heta ärendekorten — pinnade överst)
De ärenden vars **nästa åtgärd är förfallen/brådskande eller väntar på just Anna** lyfts hit som **fulla
ärendekort** (se Nyckelkomponenter). Sorteras deterministiskt: **frist (rött→gult) → väntar-på-mig →
sekretessnivå → oläst**. Ovanpå den deterministiska ordningen kan det avstängbara, lokala AI-lagret *föreslå*
ordning med synligt "varför" ("hög prio: beslutsfrist imorgon"), men aldrig dölja ett ärende. Max ~3–4 kort
här; resten ligger i Zon 3.
*Varför:* progressive disclosure i makro — visa nästa åtgärd, inte allt på en gång. Det här är vyns hjärta:
"vad ska jag göra härnäst?".

### Zon 3 — "Mina ärenden" (alla aktiva ärendekort)
Hela ärendelistan, samma kortkomponent, grupperad/filtrerbar på **processteg** (segmenterad kontroll:
Alla · Förhandsbedömning · Utredning · Beslut · Uppföljning). Default-grupp = processteg, sekundär sortering =
frist. Varje rad är ett ärendekort i kollapsat läge som expanderar (Quick View) utan sidbyte.
*Varför:* här arbetar Anna sig igenom dagen ärende för ärende. Filtret på processteg låter henne "beta av en
batch i taget" (alla utredningar som ska skrivas; alla beslut som ska signeras).

### Zon 4 — "Mina möten idag" (smal sidoremsa/sektion)
Dagens säkra möten som en kompakt tidslinje med en-klicks-anslut och lobby-status. Varje mötesrad är
**ärendekopplad** (dnr-chip) så att mötet leder tillbaka in i sitt ärendekort efteråt (transkribering →
godkänn → för till Treserva).
*Varför sist men synlig:* tidsbundet och viktigt, men inte ärendedrivet på samma sätt — det är en kalenderskiva.
Placeras som en lugn remsa, inte som ett av tretton jämbördiga kort.

### Zon 5 — Foten ("Klart idag" + genvägar)
En lugn avslutszon: **"Klart idag"-räknare** (avslutade åtgärder, för känslan av framsteg/inbox-zero),
genväg till **Kunskapsbank & mallar** (BBIC, samtycke, gallring — fast plats, WCAG 3.2.6 Consistent Help),
och **"Senaste säkra filer"** som en diskret lista (vad hände i mina rum sedan sist).

> **Designprincip för hela IA:n:** *Inflöde överst (Zon 1) → mina ärenden i mitten (Zon 2–3) → tid & stöd
> nederst (Zon 4–5).* Allt som inte är "ett ärende eller på väg att bli ett" är nedtonat. De tidigare ~13
> parallella widgetarna har vikts in: `attHantera`/`funktionsbrevlador`/`orosanmalningar` → **Zon 1**;
> `bevakningar`/`minaUppgifter`/`attSignera`/`arenderum` → **inbäddade i ärendekortet**; `dagensMoten` →
> **Zon 4**; `kvittenser`/`senasteFiler`/`kunskapsbank` → **Zon 5 / kort-expansionen**.

---

## Nyckelkomponenter (byggbara i Vue 2.7 + @nextcloud/vue v8)

### 1. `ArendeKort` (ärendekortet — vyns kärnkomponent)
- **Syfte:** representera *ett* ärende i alla processteg; ersätter merparten av de gamla widgetarna genom att
  samla det relevanta per ärende.
- **Visar (kollapsat läge):** barn-/ärendetitel (pseudonym + dnr-token, t.ex. *"Barn 2026-0412 · dnr
  2026-IFO-1234"*); **process-stepper** (5 steg, aktuellt markerat); **frist-chip** i färg; en rad **"Nästa
  åtgärd: …"** + primärknapp; **destinations-/provenance-chip** ("Registrerad i Treserva · Hubs-rum gallras
  2026-09" eller "→ Treserva — ej registrerad"); sekretess-/LOA-badge.
- **Visar (expanderat, Quick View — ingen sidbyte):** ärenderum-innehåll i flikar (Dokument · Meddelanden ·
  Möten · Bevakningar · Beslut), inbäddade snabbåtgärder, hela fristpanelen, ACL-/delningsstatus.
- **Åtgärder (inline):** **Öppna ärenderum** · **Skicka säkert meddelande** · **Boka säkert möte** · **Skicka
  för underskrift / Signera** · **För till Treserva** · **Skapa bevakning**. Vilka som är primära avgörs av
  processteget (se "Hur arbetsgången stöds").
- **Teknik:** en `NcCard`-baserad komponent; props `{ dnr, barnRef, steg, frist, nastaAtgardId,
  provenance, sekretess }`; emit per åtgärd; expansion via `NcCollapsible`/lokalt `expanded`-state. Data via
  **en** server-side aggregat-endpoint per ärende (`/ocs/v2.php/apps/sdkmc/api/v1/arende/{dnr}` som slår ihop
  sdkmc-status, ärenderum-state, Deck/Tasks-frister, signeringskö och Frends-commit-status). Ingen klient-fan-out.

### 2. `ProcessStepper` (processindikatorn i kortet)
- **Syfte:** göra "var är ärendet?" begripligt på en blick och leda blicken till nästa steg.
- **Visar:** fem segment **Förhandsbedömning → Utredning → Beslut → Uppföljning → Avslutat**; avklarade = ifylld
  bock, aktuellt = markerat + etikett, kommande = grått. Aldrig enbart färg — varje steg har **ikon + text**
  (WCAG 1.4.1). Hovring/expansion visar substeg (t.ex. under Utredning: "Inhämta uppgifter · Samredigera ·
  Kommunicera · Färdigställ").
- **Åtgärder:** klick på aktuellt steg expanderar kortet på rätt flik; klick på avklarat steg visar
  read-only-historik.
- **Teknik:** ren presentational-komponent; prop `steg` + `substeg`; `aria-current="step"`; tangentbordsnavigerbar.

### 3. `NastaAtgardKnapp` (den ledande knappen)
- **Syfte:** låg kognitiv last — handläggaren ska aldrig behöva räkna ut vad nästa steg är.
- **Visar:** verb-först etikett härledd ur processteg + ärendetillstånd ("Fatta beslut: inleda/inte inleda",
  "Färdigställ utredning & för till Treserva", "Granska & godkänn mötesanteckning", "Signera beslut",
  "Sätt uppföljningsbevakning"). Sekundärt: "eller gör annat" → meny med övriga lagliga åtgärder.
- **Teknik:** `NcButton type="primary"`; etikett och target-route mappas ur en liten **steg→åtgärd-tabell**
  (state machine) i frontend, med serverside-validering av att åtgärden är tillåten i fasen (se fas-spärr nedan).

### 4. `FristChip` / `FristPanel` (frist-indikatorn)
- **Syfte:** garantera att ingen lagstadgad klocka missas.
- **Visar:** en chip per aktiv frist med **dagar kvar + datum + fristtyp** och eskaleringsfärg grå→gul (≤3 dgr)
  →röd (förfallen). Fristtyper: **14 dgr** (förhandsbedömning, från inkom-datum), **4 mån** (utredning),
  **3 v** (överklagande), **tidsbegränsat beslut** (uppföljning "i god tid innan beslut upphör"), **FL 6 mån /
  4 v**. Chip bär alltid ikon + text + dagsiffra (aldrig bara färg, WCAG).
- **Åtgärder:** klick → fristpanel med källa ("härledd ur inkom-datum 2026-06-10"), påminnelsestatus
  (T-7/T-3/T-0) och "ägs av Treserva — speglad här" (läst via Frends, inte självständigt räknad).
- **Teknik:** `frist`-objekt `{ typ, due, start, kalla, paminnelser, agare }`; eskaleringsfärg = ren funktion av
  `(due − idag)`; speglas ur Frends-läskonnektor så Hubs och Treserva inte divergerar.

### 5. `AttTaEmotRad` (triage-raden i Zon 1)
- **Syfte:** snabb, säker triage av otrierat inflöde utan att öppna ett tungt ärende.
- **Visar:** kanalikon · avsändare + identitets-/LOA-badge (inkl. legitimt "ej verifierad/anonym"-tillstånd) ·
  inkom-tid · 14-dgr-chip (tickar redan) · destinations-chip.
- **Åtgärder:** **"Ta emot & starta förhandsbedömning"** (orkestrerar i ett klick: tilldela → skapa ärenderum
  med ACL + Retention-tagg + BBIC-mall → starta 14-dgr-klocka → registrera aktualisering i Treserva via Frends)
  · **"Koppla till befintligt ärende"** (söker dnr) · "Visa".
- **Teknik:** `NcListItem`-baserad; primäråtgärden anropar ett **orkestrerings-endpoint** (det tidigare GAP-010,
  nu byggt) och flippar provenance-chip på svar.

### 6. `MotesRad` (Zon 4) & `MotesEfterspel` (i kortet)
- **Syfte:** koppla möte → transkribering → godkänd anteckning → Treserva utan att lämna ärendekortet.
- **Visar:** tid, deltagar-lobbystatus (BankID/Freja-verifierad per person), en-klicks-anslut; efter mötet en
  **"Granska & godkänn mötesanteckning"-uppgift** i kortets Bevakningar-flik med transkript + AI-utkast **sida
  vid sida** (påtvingad human-in-the-loop).
- **Teknik:** calendar/spreed-itsl för mötet; efter WebM landar i rummet körs transkribering (lokalt) + AI-utkast
  (lokalt); godkännande loggas som händelse och committas via Frends; rå-WebM/-transkript får Retention-klocka.

### 7. `ProvenansChip` (mellanlagring→facksystem-känslan)
- **Syfte:** göra "var hamnar det till slut" begripligt utan att störa.
- **Visar:** två tillstånd — "→ Treserva — ej registrerad" (öppen åtgärd) och "Registrerad i Treserva, dnr X ·
  Hubs-rum gallras {datum}". Vid Frends-bekräftad commit flippar den automatiskt och **dubbel countdown**
  (facksystemets bevarande + Hubs-rensning) blir synlig.
- **Teknik:** lyssnar på Frends commit-callback; tom "ej registrerad"-kö = compliance-KPI.

### 8. `KommandoPalett` (Ctrl/Cmd+K)
- **Syfte:** expertacceleration utan att belasta nybörjaren (skalar med användaren).
- **Visar/gör:** fuzzy-sök över ärenden (dnr/namn) + verb-åtgärder ("Skapa ärenderum", "Skicka säkert
  meddelande", "Boka säkert möte", "Gå till frister denna vecka").
- **Teknik:** combobox + fuzzy search ovanpå unified search; fullt tangentbordsstöd.

---

## Hur arbetsgången stöds (Akt 1–5 → UI)

Genomgående regel: **ärendekortets processteg = aktens fas**, och **"Nästa åtgärd"-knappen = aktens nästa steg**.
Commit till Treserva sker via **Frends** och syns alltid som en provenans-flip + dubbel countdown.

### Akt 1 — Inflöde & triage (Walkthrough steg 1–10) → **Zon 1 "Att ta emot"**
- Steg 1–2 (inkomst, triagekö): raden dyker upp i **Zon 1** med kanalikon, verifierad LOA och en **14-dgr-chip
  som redan tickar** (bunden till inkom-datum — den tidigare GAP-002 är löst i datamodellen).
- Steg 3 (omedelbar skyddsbedömning): primäråtgärden **"Ta emot & starta förhandsbedömning"** öppnar en
  mallstyrd skyddsbedömnings-notering och **committar den direkt till Treserva via Frends** (GAP-001 löst:
  skyddsbedömningen blir journalnotat i facksystemet i samma steg, inte enbart en Hubs-notering).
- Steg 4–6 (plocka, skapa ärenderum): ett klick orkestrerar tilldelning + ärenderum (ACL least-permission +
  Retention-tagg + BBIC-mall). Raden **lämnar Zon 1 och blir ett `ArendeKort`** i Zon 2/3 med stepper på
  **Förhandsbedömning**.
- Steg 7 (tillåtna kontakter): kortets meddelande-åtgärd är **fas-spärrad** — i förhandsbedömningsfasen tillåts
  bara vårdnadshavare/anmälare/barn; försök att kontakta utomstående ger varning ("under förhandsbedömning:
  endast dessa parter", tidigare GAP-006, nu fas-attribut i datamodellen).
- Steg 8–9 (beslut inleda + aktualisering): "Nästa åtgärd" blir **"Fatta beslut: inleda / inte inleda"**;
  beslutet + aktualiseringen committas via **Frends** (ProvenansChip flippar till "Registrerad i Treserva,
  dnr …"). Lågrisk → "Godkänn" (loggat); annars → signeringskö.
- Steg 10 (stäng loop, gallra): vid commit-bekräftelse från Frends startar Hubs-rensningens countdown
  (gallring sker **efter verifierad commit**, inte vid manuell kryssruta — GAP-007 löst).

### Akt 2 — Utredning & ärenderum (steg 11–22) → **stepper: Utredning; arbete i kortets expansion**
- Kortet växlar till **Utredning**; "Nästa åtgärd" leder genom substegen i `ProcessStepper`: Inhämta uppgifter →
  Samredigera (Collabora on-prem) → Inhämta samtycke (säkert formulär + BankID) → Kommunicera utvalda handlingar.
- Steg 18 (kommunicering): **maskerings-/sekretessprövningsstöd** är inbyggt i "Dela utvalda handlingar säkert"
  (välj handlingar, varning för tredjemansuppgifter — tidigare GAP-017).
- Steg 19 (4-mån-frist): `FristChip` visar fristen, **speglad ur Treserva via Frends** (ingen självständig
  Hubs-räkning som kan divergera — GAP-018 löst); förlängningsbeslut synkas.
- Steg 20–22 (färdigställ → committa BBIC-journal → gallra): "Nästa åtgärd" blir **"Färdigställ utredning & för
  till Treserva"**; Frends committar slutversionen och returnerar commit-id → provenans-flip + dubbel countdown;
  rena utkast/dubbletter markeras för gallring.

### Akt 3 — Möte & transkribering (steg 23–34) → **Zon 4 + kortets Möten-/Bevakningar-flik**
- Steg 23–25 (boka, kalla, lobby): från kortet **"Boka säkert möte"** → bokningsbar tid + auto säkert videorum;
  kallelse via säker e-post + BankID-länk; **`MotesRad`** i Zon 4 visar lobbystatus per verifierad deltagare.
- Steg 26–28 (inspelning, WebM → rummet): `recording_consent` påtvingat; WebM landar i ärenderummet med
  Retention-tagg.
- Steg 29–31 (transkribering → AI-utkast → godkänn): **lokalt** transkript + **lokalt** AI-utkast (juridiskt
  klarställt per antagande). En **"Granska & godkänn mötesanteckning"-uppgift** i kortet visar **transkript och
  utkast sida vid sida** — godkännande är tekniskt påtvingat och loggat (GAP-029 löst).
- Steg 33–34 (för över godkänd anteckning, gallra rå-data): "För till Treserva" committar via Frends; rå-WebM +
  rått transkript gallras efter commit; **Retention kan pausas** vid utlämnandebegäran (GAP-031 löst).

### Akt 4 — Beslut, signering, delgivning (steg 35–44) → **stepper: Beslut**
- Steg 35–37 (ta fram beslut, signeringskö, signera): "Nästa åtgärd" → **"Skicka beslut för underskrift"** →
  **Inera Underskriftstjänst (AES via BankID/Freja/SITHS)** → PAdES/PDF/A-1 + LTV (GAP-034/035 lösta).
  Signeringskön och spegelvyn (Skickat → Öppnat → Signerat X av N) bor i kortets **Beslut-flik**.
- Steg 38 (bevarandekontroll): bevarandepanel **"Giltig nu / Giltig då"** (PAdES + PDF/A-1 + LTV ✓) som grind
  före commit.
- Steg 41–42 (delge + sätt frist): **"Delge beslut"** med val av delgivningssätt (vanlig/förenklad/digital
  brevlåda); `kvittenser`-tidslinjen visas i kortet (Skickad → Levererad → Öppnad → Inloggad LOA3 → Läst); en
  **överklagande-frist (3 v)** sätts automatiskt med startdatum **härlett ur valt delgivningssätt** (GAP-039).
- Steg 43–44 (committa + arkivera): Frends committar signerad handling + valideringsintyg + delgivningsbevis →
  provenans-flip; vid avslut FGS-export till e-arkiv (ansvarsgräns Treserva↔Hubs definierad).

### Akt 5 — Bevakning & todo (steg 45–51) → **kortets Bevakningar-flik + Zon 0 fristrad**
- Steg 45–47 ("Skapa bevakning från meddelande"): på en triage-rad eller i kortet skapas en bevakning med
  förifylld titel/dnr/föreslagen frist; för fristbärande poster **föreslås delad board som default** så inget
  faller mellan stolarna (GAP-042).
- Steg 48–49 (påminnelser, fristmodellering): T-7/T-3/T-0 **bara till tilldelad**; de fyra lagklockorna
  modelleras och speglas ur Treserva.
- Steg 50–51 (registrera bevakning, klarmarkera gallra/för till ärendet): vid klarmarkering frågar Hubs
  **"Gallra (personlig notering)"** vs **"För till ärendet/facksystemet"**; "för till ärendet" committar via
  Frends och **river kvarvarande Hubs-påminnelser** så Treserva blir ensam fristägare (GAP-044). Tom
  "ej registrerad"-kö i Zon 0 = compliance-KPI (GAP-049).

---

## Frister & sekretess i UI

### Så visas de fyra/fem klockorna
- **Zon 0 (makro):** klartext-sammanfattning av veckans frister ("2 förhandsbedömningar förfaller inom 3 dagar")
  — den första saken man ser.
- **Ärendekort (per ärende):** `FristChip` med **fristtyp + dagar kvar + datum** och eskaleringsfärg grå→gul
  (≤3 dgr)→röd (förfallen). Varje frist bär ikon + text + dagsiffra; **färg är aldrig enda informationsbärare**
  (WCAG 1.4.1, krav i spec).
- **14 dgr (förhandsbedömning):** tickar **från inkom-datum** redan i Zon 1, innan ärendet plockats — ingen
  falsk trygghet av "startar när jag plockar".
- **4 mån (utredning), 3 v (överklagande), tidsbegränsat beslut (uppföljning), FL 6 mån/4 v:** varje får sin
  fristtyp; **speglas ur Treserva via Frends** så att Hubs och facksystemet aldrig visar olika röda siffror
  (förlängningsbeslut synkas → ingen "falsk-röd").

### Hur "inget missas" garanteras (flera lager)
1. **Inflödesklockan startar automatiskt** vid inkomst (Zon 1), inte vid mänsklig handling.
2. **Deterministisk topp-sortering** (frist först) lyfter heta ärenden till Zon 2 — de kan inte scrollas bort.
3. **Påminnelser T-7/T-3/T-0** bara till tilldelad (Tasks/VTODO native + Deck-logik).
4. **Tom kö = compliance-värde:** "Inget otriagerat", "0 förfallna", "0 ej registrerade i Treserva" visas som
   uttryckliga mål-tillstånd, inte tystnad.
5. **Frist ägs av Treserva, speglas i Hubs** (via Frends) — det rättsligt bindande och det visade kan inte
   divergera.

### Sekretess / LOA i UI
- **Korttext = ärendereferens, inte klartextcitat** (GDPR dataminimering) — pseudonym + dnr-token, aldrig
  känsligt innehåll i listvyn.
- **Sekretess-/LOA-badge** per ärende och per motpart ("OSL 26 kap.", "Verifierad med BankID · LOA3 · 14:02",
  legitimt "Ej verifierad/anonym"-tillstånd för anonyma anmälningar).
- **Behörighet = säkerhetsgräns:** ett ärendekort/triage-rad visas bara för den som har OSL-behörighet
  (`IConditionalWidget` som åtkomstgräns) — en kollega utan behörighet ser inte ens att ärendet finns.
- **Fas-spärr på kontakter** under förhandsbedömning (endast vårdnadshavare/anmälare/barn).
- **Maskeringsstöd** vid säker delning (varning för tredjemansuppgifter).
- **Diskret datasuveränitets-markör** i Zon 0 ("all data i er driftmiljö · 0 tredjelandsöverföringar").

---

## Lättbegriplighet & onboarding

### Första 30 sekunderna för en ny socialsekreterare
1. **0–5 s:** Zon 0 säger i klartext hur dagen ser ut ("22 ärenden · 2 frister inom 3 dagar · 0 förfallna").
2. **5–15 s:** Zon 1 "Att ta emot" är självförklarande — "nya saker som ännu inte är mina ärenden", med två
   tydliga knappar. Hon förstår att hennes jobb börjar med att *ta emot*.
3. **15–30 s:** Hon ser sina ärendekort. **Steppern lär ut processen utan manual** — fem ord visar hela
   ärendelivscykeln. Den lysande "Nästa åtgärd"-knappen säger exakt vad hon ska göra. Hon behöver aldrig veta
   vilken app som ligger bakom.

### Etiketter (verb-först, svensk myndighetston, lånad från FK/AF-designsystem)
"Ta emot & starta förhandsbedömning" · "Skapa ärenderum" · "Skicka säkert meddelande" · "Boka säkert möte" ·
"Fatta beslut" · "Skicka beslut för underskrift" · "Granska & godkänn mötesanteckning" · "Delge beslut" ·
"För till Treserva" · "Skapa bevakning". GOV.UK-statusmodell, minimal: **Ny · Påbörjad · Väntar på motpart ·
Klar för beslut · Klar** + rött **Åtgärd krävs**.

### Tomma tillstånd (positiva, inte tomma)
- Zon 1 tom → *"Inget otriagerat — allt inkommande är omhändertaget."*
- Inga röda frister → *"Inga förfallna frister. Inget barn mellan stolarna."*
- Inga ärenden i ett steg → *"Inga ärenden i Beslut just nu."*

### Mikrohjälp (progressive disclosure)
Liten **"?"-ikon vid steppern** ("Vad betyder förhandsbedömning?") och vid varje frist ("14-dagarsfristen löper
från att anmälan inkom 2026-06-10"). Hjälp/Kunskapsbank på **fast plats** i foten (WCAG 3.2.6 Consistent Help).
Samma ikon = samma funktion mellan vyer (3.2.4).

### WCAG 2.2 AA (byggs in från start)
- **Target Size ≥ 24×24 px** på alla status-/snabbåtgärdsknappar och frist-chips.
- **Dragging Movements (2.5.7):** omordning/filtrering via knapp/tangentbord, aldrig bara drag.
- **Focus Not Obscured (2.4.11):** sticky Zon 0/fristrad får inte dölja fokuserat ärendekort.
- **Reflow/Orientation:** enspalts-IA fungerar i porträtt och vid 400 % zoom (fältarbete/hembesök).
- **Accessible Authentication (3.3.8):** BankID/Freja/SITHS utan kognitiva test.
- **Nedräkningsklockor aldrig enbart färg:** ikon + text + dagsiffra alltid.

---

## Primära åtgärder (verb-först, 3–5)

1. **Ta emot & starta förhandsbedömning** (triagera nytt inflöde → ärende, med skyddsbedömning committad till Treserva)
2. **Öppna ärenderum / driv nästa steg** (kontextuell "Nästa åtgärd" per ärendekort)
3. **Skicka säkert meddelande / svara klient** (fas-spärrad, med läskvittens)
4. **Kalla till säkert möte** (→ transkribering → godkänn → för till Treserva)
5. **Skicka beslut för underskrift & delge** (Inera-AES → delgivning med kvittens → committa via Frends)

---

## Konkret exempel-scenario — ärende SN 2026-0142 genom vyn

> *(Ett separat ärende från walkthrough-fallet, för att visa vyn på egna ben.)* Ärendet: en orosanmälan om
> "Barn 2026-0142" från en skola. Vi följer Anna steg för steg — vad hon ser och klickar.

**1. 08:00 — Anna loggar in (Freja eID Plus, LOA3).** Zon 0 möter henne: *"God morgon, Anna · 22 aktiva ärenden ·
1 förhandsbedömning förfaller inom 3 dagar · 0 förfallna."* Hon scrollar inte — temperaturen är lugn.

**2. 08:02 — Zon 1 "Att ta emot · 1".** Överst en ny rad: **skolikon (SDK) · "Skolkurator, SITHS-verifierad ·
LOA3" · inkom 07:58 · 14-dgr-chip "13 dgr kvar" (grå) · "→ Treserva — ej registrerad".** Korttexten är
*"Orosanmälan – Barn 2026-0142"* (ingen känslig text). Hon klickar **"Ta emot & starta förhandsbedömning"**.

**3. 08:03 — Ett klick orkestrerar.** Hubs: tilldelar henne, skapar **ärenderum** (ACL: hon skriver, gruppledare
läser; Retention-tagg satt), instansierar **BBIC-förhandsbedömningsmall**, och öppnar en mallstyrd
**skyddsbedömnings-notering**. Hon dokumenterar barnets omedelbara skyddsbehov och trycker **"Spara & journalför
i Treserva"** → **Frends committar skyddsbedömningen** → ProvenansChip flippar till *"Skyddsbedömning journalförd
i Treserva · dnr 2026-IFO-0142."* Raden lämnar Zon 1 och blir ett **ärendekort** i Zon 3, stepper på
**Förhandsbedömning**.

**4. 08:10 — Anna öppnar ärendekortet.** Steppern visar *Förhandsbedömning* markerad. **"Nästa åtgärd: Kontakta
vårdnadshavare (inom ramen)."** Hon klickar **"Skicka säkert meddelande"** — kortet **fas-spärrar**
mottagarvalet till vårdnadshavare/anmälare/barn (försök att lägga till skolans rektor som extra mottagare ger
varningen *"Under förhandsbedömning får endast vårdnadshavare, anmälare och barnet kontaktas"*). Hon skickar
till vårdnadshavaren via säker e-post + BankID-länk. `kvittenser`-tidslinjen dyker upp i kortets
Meddelanden-flik: *Skickad.*

**5. Senare i veckan — beslut.** På torsdag har förhandsbedömningen mognat. Ärendekortet har klättrat till
**Zon 2 "Kräver åtgärd nu"** eftersom 14-dgr-chipen nu är **gul ("2 dgr kvar")**. "Nästa åtgärd" lyder
**"Fatta beslut: inleda / inte inleda utredning."** Anna klickar; väljer **"Inleda utredning"**. Beslutet är
lågrisk → **"Godkänn"** (loggat, ingen BankID krävs, per SKR:s riskmodell). **Frends committar** beslut +
aktualisering → ProvenansChip: *"Registrerad i Treserva, dnr 2026-IFO-0142 · Hubs-rum gallras efter
överföring."* Steppern flyttar fram till **Utredning**; en **4-mån-frist** dyker upp i kortet, **speglad ur
Treserva**.

**6. Utredningsfasen — i kortets expansion.** "Nästa åtgärd" leder genom substegen: Anna **inhämtar uppgifter**
(skolans pedagogiska kartläggning landar som säkert meddelande → "Spara i ärenderum"), **samredigerar**
utredningstexten on-prem, **inhämtar samtycke** via säkert formulär + BankID, och **bokar ett SIP-möte** via
**"Boka säkert möte"**.

**7. Mötet (Zon 4).** På mötesdagen visar `MotesRad` i Zon 4: *"SIP – Barn 2026-0142 · 10:00 · 2 i väntrum:
vårdnadshavare (BankID/LOA3), skolkurator (SITHS)."* Anna ansluter i ett klick, släpper in dem, startar
inspelning (samtycke påtvingat). Efter mötet landar WebM i rummet; **lokal transkribering + lokalt AI-utkast**
körs. I kortets Bevakningar-flik dyker **"Granska & godkänn mötesanteckning"** upp med **transkript och utkast
sida vid sida**. Anna rättar, stryker irrelevant känsligt, trycker **"Godkänn"** (loggat). **Frends committar**
den godkända anteckningen till BBIC-journalen; rå-WebM + transkript får gallrings-countdown.

**8. Beslut & delgivning.** När utredningen är klar blir "Nästa åtgärd" **"Skicka beslut för underskrift."**
Anna skickar → **Inera Underskriftstjänst (AES via BankID)** → PAdES/PDF/A-1 + LTV; bevarandepanelen **"Giltig
nu / Giltig då"** visar grönt. Hon klickar **"Delge beslut"**, väljer delgivningssätt; `kvittenser` följer
*Skickad → Levererad → Öppnad → Inloggad (LOA3) → Läst.* En **överklagande-frist (3 v)** sätts automatiskt med
startdatum härlett ur delgivningssättet. **Frends committar** signerad handling + valideringsintyg +
delgivningsbevis. Steppern flyttar till **Uppföljning**.

**9. 16:30 — Stäng loopen.** I kortets Bevakningar-flik klarmarkerar Anna dagens poster; för en fristbärande
post väljer hon **"För till ärendet/facksystemet"** → Frends committar och **river Hubs-påminnelsen** så
Treserva blir ensam fristägare. Zon 0 visar nu *"0 förfallna · 0 ej registrerade i Treserva."* Zon 5 "Klart
idag": *"7 åtgärder klara."* Inget barn mellan stolarna — vyn bevisar det, den döljer det inte.

---

**Koncept-id `ux-concept-arende` — starkaste idén:** *ett ärende = ett kort med en process-stepper och en enda
lysande "Nästa åtgärd"-knapp*, så att hela den 51-stegs-arbetsgången kollapsar till en mentalmodell —
"läs mina ärendekort uppifrån och ned, tryck på knappen som lyser" — där steppern lär ut processen utan manual
och frist + provenans (speglad/committad via Frends) garanterar att inget barn och ingen lagstadgad klocka
faller mellan stolarna.
