<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Daglig användning — Socialsekreterare (barn & familj)

> **Persona-id:** `socialsekreterare` · **System of record (slutlagring):** Treserva (CGI) / Lifecare (Tietoevry) / Viva / Combine (Pulsen) — socialakten/BBIC-journalen. · **Datum:** 2026-06-13 · **Plattform:** server v32 (Hub 25 Autumn).
>
> **Den bärande modellen:** Hubs är **mellanlagringen** — den sekretesssäkra, on-prem arbetsytan där orosanmälan tas emot, förhandsbedöms, utreds, klientkommunikationen sker och beslutet e-signeras. **Slutlagringen är alltid socialtjänstens verksamhetssystem** (Treserva/Lifecare/Viva/Combine), där det rättsliga beslutet och journalanteckningen committas och arkivredovisas. Varje widget måste svara på två frågor: *varifrån kom datan in* och *i vilket facksystem hamnar den till slut*. Modellmeningen genomgående: **"Hubs stagar X → handläggaren för över till {facksystem}."**
>
> **Brand-regel:** i produkt-/UI-text säger vi aldrig "Nextcloud" eller "Talk". I detta interna underlag namnger vi apparna (sdkmc, securemail, mail/fax, spreed-itsl, calendar, Deck/Tasks, Groupfolders/ACL/Retention, Tables, Forms, Collectives, LibreSign/Inera Underskriftstjänst) för att kunna wire:a.
>
> **Personas dashboard-layout** (ur `personaConfig.js`): **main** = `attHantera`, `orosanmalningar`, `bevakningar`, `arenderum`, `attSignera`. **side** = `dagensMoten`, `kvittenser`, `funktionsbrevlador`, `minaUppgifter`, `senasteFiler`, `kunskapsbank`. Primäråtgärder = Ta emot & fördela orosanmälan · Skapa ärenderum · Skicka säkert meddelande · Kalla till säkert möte · Skicka beslut för underskrift.

---

## En dag i arbetet (08:00 → 17:00, kronologiskt)

Anna är socialsekreterare på en barn- och familjeenhet i en mellanstor svensk kommun. Hon är myndighetsutövare under nya **SoL 2025:400** (i kraft 1 juli 2025), arbetar enligt **BBIC**, och har ~22 aktiva utredningar plus en ström av nya orosanmälningar. Hennes dag styrs av hårda rättsliga frister, och poängen med Hubs Start är att ingen av dem ligger i huvudet eller på en post-it.

**08:00 — Legitimering & morgontriage (`attHantera`).** Anna loggar in med Freja eID Plus (LOA3). Hon möter inte en mejlinkorg utan **Att hantera** — en aggregerad triagekö över allt inkommande som kräver åtgärd, klassat server-side av sdkmc summary-endpointen. Översta strip: *"2 förhandsbedömningar förfaller inom 3 dagar · 1 utredning klar för beslut."* Hon ser sex rader: en ny orosanmälan från en skola (SDK, avsändare verifierad LOA3, 08:14), ett säkert svar från BUP på en begäran om uppgifter (SDK), en komplettering från en vårdnadshavare (säker e-post + BankID), ett digitalt fax från en privat utförare, en mötesinbjudan, och ett beslut som väntar på hennes underskrift. Varje rad bär **kanalikon**, **fristräknare** och en liten **destinations-chip** ("→ Treserva — ej registrerad").

**08:15 — Plocka & fördela ur funktionsbrevlådan (`funktionsbrevlador`).** Den nya skol-anmälan kom till den delade funktionsadressen `orosanmalan@kommunen` (SKR:s funktionsadress-rekommendation 2025). Den ligger otilldelad och syns för hela enheten. Anna **plockar** den (eller mottagningsgruppen fördelar den till henne på morgonmötet). I samma sekund den blir hennes startar en **14-dagars countdown** för förhandsbedömningen.

**08:30 — Ny orosanmälan → förhandsbedömning (`orosanmalningar` + `arenderum`).** Anna öppnar anmälan i kön **Orosanmälningar – förhandsbedömning**. Status sätts till *Under förhandsbedömning*. Hon klickar **Skapa ärenderum** → en säker dokumentyta per barn/dnr skapas (Groupfolder med ACL: hon skriver, gruppledaren läser), anmälan + bilagor läggs där, gallringsregeln för handlingstypen sätts automatiskt. Under förhandsbedömningen får bara vårdnadshavare, anmälaren och barnet kontaktas — hon gör kontroll i facksystemet (Treserva: tidigare aktualiseringar?) i parallellt fönster och dokumenterar sin första notering i ärenderummet via en BBIC-mall ur kunskapsbanken.

**09:00 — Säkra svar till motpart (`attHantera` → skicka säkert meddelande).** BUP-svaret innehåller uppgifter hon begärt i en pågående utredning. Hon läser det i ärenderummets kontext, och svarar BUP via säkert meddelande (SDK org-till-org). På vårdnadshavarens komplettering klickar hon **Skapa bevakning från meddelande** — titel förifylls ("Följ upp komplettering – Barn 2026-0412"), länkas till meddelandet och kopplas till dnr.

**09:30 — Bevakningsgenomgång (`bevakningar` + `minaUppgifter`).** Anna går igenom **Mina bevakningar & frister**: en utrednings-4-månadersfrist är gul (12 dagar kvar), en uppföljning av ett tidsbegränsat insatsbeslut förfaller 30/6. Inga röda. I **Mina uppgifter** (arbets-/genomförandefokus, GOV.UK task-list) ser hon dagens konkreta steg: "Inhämta uppgifter från skola – Barn X", "Boka barnsamtal – Barn Y", "Skriv utredningsbedömning – Barn Z".

**10:00 — SIP-/samverkansmöte (`dagensMoten` + `bokningsbaraTider`).** Säkert videomöte med vårdnadshavare, skola och region kring ett barn i utredning. Anna ansluter i ett klick. **Lobby-status**: vårdnadshavaren väntar i väntrummet, verifierad med BankID (LOA3); skolkuratorn med SITHS. Hon släpper in dem. Inför mötet inhämtades **samtycke** via ett säkert formulär (Forms + BankID-loggat steg), arkiverat i ärenderummet. Planen dokumenteras i ärenderummet under mötet.

**11:30 — Dokumentation & journalskuld.** Anna för in mötesanteckningen och uppdaterar utredningstexten (samredigering on-prem i ärenderummet). Det formella, rättsliga: hon **för över** journalanteckningen till Treserva-akten — Hubs är inte socialakten.

**13:00 — Beslut → underskrift (`attSignera` + `skickatForSignering`).** En förhandsbedömning från tidigare i veckan är klar: beslut "inleda utredning". Lågrisk-beslut **godkänns** (loggat, ingen formell signatur per SKR:s riskmodell). Ett utredningsbeslut enligt SoL skickas däremot **för underskrift** (AES via BankID/Freja). I **Att signera** ligger ett beslut som väntar hennes egen underskrift med deadline; hon signerar. I **Skickat för signering** ser hon spegelvyn för det hon skickat till gruppledaren för medsignering: *Skickat → Öppnat → Signerat 1 av 2*.

**14:00 — Delgivning & kvittens (`kvittenser`).** Det signerade beslutet (PAdES/PDF/A) **delges** vårdnadshavaren via säker kanal. I **Leveranser & kvittens** följer hon tidslinjen: Skickad → Levererad → Notis öppnad → Inloggad (LOA3) → Läst. Det är den emotionella ersättningen för "ringa och kolla att faxen kom fram". En bevakning för överklagandefristen skapas automatiskt. Beslutet **förs över till Treserva-akten**.

**15:00 — Hembesök / fältarbete (mobil).** Anna gör ett hembesök. Vyn fungerar i porträtt och vid 400 % zoom (WCAG 1.4.10/1.3.4). Hon läser ärenderummet, gör en röstnotering hon renskriver senare.

**16:30 — Stäng loopen.** Tillbaka på kontoret klarmarkerar hon bevakningar. Vid klarmarkering väljer hon **"gallra (personlig notering)"** eller **"för till ärendet/facksystemet"** — håller isär privata att-göra-lappar från arkivpliktiga allmänna handlingar. **Senaste säkra filer** visar att en motpart laddat upp ett dokument i ett rum. Inga röda frister. Tom kö = inget barn mellan stolarna — ett **compliance-värde**, inte bara bekvämlighet.

---

## Hur Hubs + dashboarden faktiskt används (öppningsordning & åtgärder)

| Tid | Widget som öppnas | Varför / vilken åtgärd | Resultat-destination |
|---|---|---|---|
| 08:00 | **`attHantera`** | Morgontriage av allt inkommande; läs strip "frister denna vecka" | (navigering) |
| 08:15 | **`funktionsbrevlador`** | **Plocka/fördela** ny orosanmälan ur `orosanmalan@` | Tilldelad → 14-dgr-klocka |
| 08:30 | **`orosanmalningar`** → **`arenderum`** | Starta förhandsbedömning; **Skapa ärenderum**; dokumentera | Notering → Treserva-akten |
| 09:00 | **`attHantera`** | **Skicka säkert meddelande** till BUP/vårdnadshavare; **Skapa bevakning från meddelande** | Svar via SDK; bevakning skapad |
| 09:30 | **`bevakningar`** + **`minaUppgifter`** | Genomgång frister + dagens arbets-steg | (planering) |
| 10:00 | **`dagensMoten`** (+ **`bokningsbaraTider`**) | Anslut SIP-möte; lobby-insläpp per verifierad deltagare; samtycke via Forms | SIP-plan → Treserva-akten |
| 13:00 | **`attSignera`** + **`skickatForSignering`** | **Godkänn** (lågrisk) / **Skicka beslut för underskrift** (AES) | Signerad PDF/A i ärenderum |
| 14:00 | **`kvittenser`** | Delge beslut; följ leveranstidslinje | Beslut **förs över till Treserva** |
| 16:30 | **`bevakningar`** + **`senasteFiler`** | Klarmarkera (gallra vs för till ärendet); slutkoll | Gallras / committas |
| löpande | **`kunskapsbank`** | BBIC-/samtyckes-/gallringsmallar (låst, WCAG 3.2.6) | (referens) |

**Valfritt AI-lager (avstängbart, lokalt):** ovanpå `attHantera`/`orosanmalningar` kan en lokal grön-ratad modell (`llm2`) *föreslå* ordning och en kort sammanfattning per ny anmälan, med synligt "varför" ("hög prio: frist imorgon + okänd avsändare"). AI får aldrig dölja/avföra ärenden, prioriterar ärendeegenskaper (frist/sekretess/oläst) inte användarbeteende (GDPR art. 22), och skriver aldrig till facksystemet — människan committar.

---

## Widget → app → system-of-record-karta (per widget i denna personas layout)

Mellanlagringsmodellen explicit per widget: vilken Nextcloud-app driver den, varifrån data kommer, och vart utfallet committas.

### Main-widgetar

| Widget | App/funktion (intern) | Data IN (varifrån) | Mellanlagring i Hubs | Slutlagring (system of record) |
|---|---|---|---|---|
| **`attHantera`** (Att hantera) | sdkmc/securemail/mail-fax → `summary`-endpoint (`/ocs/v2.php/apps/sdkmc/api/v1/summary`) | Orosanmälan/svar/komplettering via SDK, säker e-post, digital fax; identitet BankID/Freja/SITHS-verifierad | Aggregerad triagekö, kanalklassning, fristräknare, destinations-chip | **Hubs stagar inflödet → handläggaren registrerar/för över handlingen till Treserva/Lifecare/Viva/Combine** (mönster B/A) |
| **`orosanmalningar`** (Förhandsbedömning) | Tables-register (status/frist) + Forms (internt anmälningsled) + sdkmc-inflöde | Skola/vård/polis/privat via e-tjänst/SDK/fax/papper | 14-dgr-countdown, status Ny/Under förhandsbedömning/Beslut inleda/ej inleda | **Hubs stagar förhandsbedömningen → beslut + aktualisering registreras i Treserva-akten** (aktualisering/ärende skapas i facksystemet) |
| **`bevakningar`** (Mina bevakningar & frister) | Deck (delad board) + Tasks/VTODO (påminnelser T-7/T-3/T-0) | Frister härledda ur lagkrav + facksystemets fristlista + "Skapa bevakning från meddelande" | Deadline-eskalering grå→gul→röd, bara-till-tilldelad-avisering | **Hubs stagar arbetslistan runt det inkommande → den formella bevakningen/aktiviteten committas i Treserva/Lifecare** (som redan har inbyggd bevakning som rödmarkeras vid passerat datum). Hubs dubblerar INTE facksystemets fristbevakning |
| **`arenderum`** (Mina ärenderum) | Files + Groupfolders + ACL + versioner + Retention + Collabora/OnlyOffice | Bilagor ur säkra meddelanden, medborgaruppladdning (BankID-delning), samredigerade utkast | En säker dokumentyta per barn/dnr; **dubbel countdown** (facksystemets bevarande + Hubs rensning) | **Originalhandlingen förs över till Treserva-akten; ärenderummet är aktivt arbetslager → FGS-export till e-arkiv vid avslut + Retention-gallring efter överföring** |
| **`attSignera`** (Att signera) | E-underskrift: **Inera Underskriftstjänst-API / Sweden Connect-nod** (prod, BankID/Freja/SITHS, AES); **LibreSign** (internt lågrisk-"Godkänn") | Beslut/utredning skapad i ärenderum eller exporterad ur Treserva | Signeringskö, kravnivå-badge (SES/AES/QES), "Godkänn" vs "Signera" | **Signerad PAdES/PDF/A + valideringsintyg → committas till Treserva-akten; bevis bevaras för LTV** |

### Side-widgetar

| Widget | App/funktion (intern) | Data IN | Mellanlagring i Hubs | Slutlagring |
|---|---|---|---|---|
| **`dagensMoten`** (Säkra möten) | calendar + spreed-itsl (lobby, BankID/Freja-verifiering) | Bokning/kallelse; deltagare verifieras i lobby | Klientsamtal/SIP/samverkansmöte; lobby-status per LOA | **Mötet äger inget rekord → SIP-plan/anteckning förs in i Treserva** (och regionens system där HSL berörs) |
| **`kvittenser`** (Leveranser & kvittens) | sdkmc receipt-data | Kvittens per utgående delgivning/beslut | Tidslinje Skickad→Levererad→Öppnad→Inloggad(LOA3)→Läst→Besvarad | **Leveransbeviset bevaras med handlingen i Treserva-akten / diariet** |
| **`funktionsbrevlador`** (Funktionsbrevlådor) | sdkmc funktionsadress (`orosanmalan@`) — behörighetsstyrd (IConditionalWidget = åtkomstgräns) | Allt till delad adress | Plocka/fördela/eskalera; otilldelat syns för enheten | **Plockad anmälan → registreras i Treserva** |
| **`minaUppgifter`** (Mina uppgifter) | Tasks/VTODO (native påminnelser) | Personliga arbets-steg knutna till barn/ärende | GOV.UK task-list, status + nästa-åtgärd | **Genomförandet dokumenteras i Treserva; uppgiften gallras som personlig notering** |
| **`senasteFiler`** (Senaste säkra filer) | Files + Groupfolders-versioner | Delningar/uppladdningar/nya versioner | "Vad hände senast" med ärenderum-kontext | **Originalet bor i ärenderummet → committas till Treserva/e-arkiv** |
| **`kunskapsbank`** (Kunskapsbank & mallar) | Collectives | BBIC-/samtyckes-/gallringsmallar, rutiner | On-prem wiki, låst utanför skalet | (statiskt referensmaterial — inget ärenderekord) |

**Genomgående modellmening:** *"Hubs stagar [orosanmälan / förhandsbedömning / utredningsdokument / SIP-plan / signerat beslut] → handläggaren för över till Treserva / Lifecare / Viva / Combine (socialakten/BBIC-journalen), och Hubs-kopian gallras efter bekräftad överföring."*

---

## Typiska arbetsmönster & återkommande flöden (end-to-end)

### Flöde 1 — Orosanmälan → förhandsbedömning → beslut (data tas emot i Hubs, slutlagras i Treserva)
1. **In:** Orosanmälan från skola via SDK (avsändare BankID/SITHS-verifierad) till `orosanmalan@` → syns i `attHantera`/`funktionsbrevlador`/`orosanmalningar`; **14-dgr-countdown** startar.
2. **Mellanlagring:** Handläggaren **plockar**, **skapar ärenderum** (Groupfolder + ACL + gallringstagg), kontaktar (inom ramen) vårdnadshavare/anmälare/barn, dokumenterar via BBIC-mall. `bevakningar` räknar ned mot fristen.
3. **Beslut:** "inleda"/"inte inleda" — lågrisk **godkänns** (loggat); annars **skickas för underskrift** (AES). Anmälaren återkopplas via säker kanal med `kvittenser`.
4. **Slutlagring:** Aktualisering/beslut **registreras i Treserva-akten** (mönster B; mönster A via Treservas öppna API hos storkund). Hubs-rummet får rensningscountdown ("Rensas ur Hubs 30 dgr efter överföring"). "Inte inleda" → gallras enligt plan.
*Provenance-band:* "Orosanmälan inkom via SDK 2026-06-10 · ej registrerad" → "Registrerad i Treserva, dnr 2026-IFO-1234 · Hubs-rum gallras 2026-09".

### Flöde 2 — Utredning (BBIC) → SIP-möte → signerat beslut → delgivning
1. **In:** Inledd utredning; **4-månadersfrist** synlig i `bevakningar`. Uppgifter inhämtas från skola/BUP/region via säkra meddelanden (in i ärenderummet).
2. **Mellanlagring:** Kalla till SIP → **auto-skapat säkert videorum**; kallelse via SDK (region/skola) + säker e-post (vårdnadshavare); anhöriga verifieras med **BankID/Freja i lobby**. **Samtycke** via Forms + signeringssteg, arkiverat i rummet. Plan dokumenteras.
3. **Beslut → underskrift:** Beslutet **skickas för underskrift** (AES via BankID/Freja → PAdES/PDF/A med LTV) i `attSignera`/`skickatForSignering`.
4. **Delgivning + slutlagring:** Beslut **delges** via säker kanal; `kvittenser` visar levererat→öppnat→läst; överklagandefrist som bevakning. Utredning + beslut + journal **förs över till Treserva/Lifecare** (BBIC-journalen). Mötesanteckning/ev. transkript-sammanfattning (godkänd) committas, rå-artefakter gallras.

### Flöde 3 — Skapa bevakning från meddelande → följ upp tidsbegränsat beslut
1. **In:** Inkommande säkert meddelande/fax (t.ex. komplettering från skola) → knapp **"Skapa bevakning"** förifyller titel (avsändare + ämne), länkar meddelandet, kopplar dnr, föreslår frist.
2. **Mellanlagring:** För tidsbegränsade beslut skapas bevakning på slutdatum ("Följ upp – insats upphör 30/6") med påminnelse T-7/T-3 (egen logik som täcker Deck #1549/#566).
3. **Slutlagring:** Vid klarmarkering — **"gallra (personlig notering)"** eller **"för till ärendet/facksystemet"**. Den formella fristen/aktiviteten committas i Treserva/Lifecare (som äger fristbevakningen); Hubs-uppgiften gallras eller länkas.

### Flöde 4 — Klientdialog/delgivning till medborgare utan myndighetskonto
1. **In/ut:** Vårdnadshavare når Hubs via säker e-post + BankID-länk (LOA3); svar/uppladdningar mellanlagras i ärenderummet.
2. **Mellanlagring:** Tvåvägsdialog (säker kanal), inte massutskick. Identitets-badge per motpart (metod + LOA + tidpunkt).
3. **Slutlagring:** Allmänna handlingar i dialogen **förs över till Treserva**; på sikt utkanal till medborgarens digitala brevlåda (Mina meddelanden/Kivra) för delgivning, SOU 2024:47. Hubs äger dialogen, inte massutskicket.

---

## Saknade funktioner för denna persona (och hur de byggs/wire:as)

1. **Todolista för socialtjänsten (deadline-bärande bevakningslista, inte generisk kanban).** *Gap:* socialsekreterare bär fristerna i huvudet/på post-it; inflödet (orosanmälan/SDK) är inte kopplat till en lista innan det blir ärende. *Bygg/wire:* `minaUppgifter` på **Tasks/VTODO** (native påminnelser) + delad `bevakningar` på **Deck** (delad board, kort↔kort-relation till ärendet). Hubs bygger ovanpå det Deck-kärnan saknar: **påminnelse-före-deadline (T-7/T-3/T-0) bara till tilldelad** (täcker #1549/#566) + knapp-/tangentbordsomordning (WCAG 2.5.7). Signaturfunktion: **"Skapa bevakning från meddelande"**. System-of-record-regel: todon är **mellanlagring** — den formella bevakningen/journalen committas i Treserva/Lifecare (som redan rödmarkerar passerade bevakningar); Hubs konkurrerar inte, den **stänger gapet inkorg↔facksystem**.

2. **Mötestranskribering + lokal AI-sammanfattning (record → transcribe → summarise → spara i ärende).** *Gap:* utrednings-/SIP-samtal dokumenteras manuellt; tung journalskuld. *Bygg/wire:* inspelning via **recording server** (kräver HPB) → WebM i ärenderummet → efterhands-transkript via **`stt_whisper2` med KB-Whisper** (KBLab, Apache-2.0, svensk-tränad, ~47 % lägre WER än large-v3 — Hubs viktigaste STT-val) → **`llm2`** (grön-ratad, lokal, chunkning för långa transkript) producerar **utkast** med sammanfattning + beslut + att-göra-lista. **Human-in-the-loop obligatoriskt:** handläggaren redigerar och **"Godkänner"** (loggat); bara den godkända texten committas till Treserva, rå-WebM + rå-transkript får kort Retention-gallring. *Juridisk gräns:* för **sekretessbelagda klientsamtal — dokumentera, kör inte skarpt än** (invänta IMY/SKR/Socialstyrelsen); on-prem löser tredjelandsfrågan men inte hela OSL/arkiv-frågan. `recording_consent` påtvingat + loggat. Inget nytt widget-id krävs för MVP — hänger på `dagensMoten` → landar i `arenderum`/`senasteFiler`, godkännande blir en `bevakningar`-post.

3. **E-underskrift med riktig svensk BankID/Freja-AES (inte LibreSigns lokala rot-CA).** *Gap:* `attSignera`/`skickatForSignering` är `proposed`; LibreSign native ger bara konto/e-post/SMS-identitet (SES/svag AES) — håller inte för myndighetsbeslut. *Bygg/wire:* signeringsadapter med två backends bakom samma kö-UI: **LibreSign** för internt lågrisk-"Godkänn" (märk identiteten ärligt i UI), **Inera Underskriftstjänst-API** (mTLS + SITHS funktionscert) eller **egen Sweden Connect-nod** för riktig AES → PAdES/**PDF/A-1** + LTV. Bygg arbetsytan + **bevarandepanelen "Giltig nu / Giltig då"**, inte kryptokärnan. Slutlagring: signerad handling + valideringsbevis → Treserva.

4. **Förifylld "Registrera/för över till facksystem"-brygga (provenance/destination-band).** *Gap:* steget meddelande↔Treserva-akt är idag manuellt; ingen synlig destinations-/överföringsstatus. *Bygg/wire:* destinations-chip per rad i `attHantera`/`arenderum` ("→ Treserva — ej registrerad" → "Förd till Treserva, dnr X · Hubs-rum gallras Y"). Mönster B (drag-to-case, som Formpipe Teams fast on-prem) → mönster A (Treservas öppna API hos storkund); mönster D (manuell + "Markera som överförd") som dag-1-fallback. Följ **Ena REST-API-profil** och bygg mot standarden, inte facksystem-för-facksystem.

---

persona id: `socialsekreterare` · system(s) of record: Treserva / Lifecare / Viva / Combine (socialakten/BBIC-journalen) · 1 missing function: todolista för socialtjänsten (deadline-bärande bevakningslista på Tasks/VTODO + Deck med "Skapa bevakning från meddelande", som mellanlagring — den formella bevakningen committas i Treserva/Lifecare).
