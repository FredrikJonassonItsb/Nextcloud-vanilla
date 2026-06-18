<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Walkthrough 3 — Säkert videomöte med vårdnadshavare → inspelning → lokal transkribering → AI-sammanfattning → human-in-the-loop → Treserva

> **Persona:** `socialsekreterare` (barn & familj) · **System of record (slutlagring):** Treserva (CGI) / Lifecare / Viva / Combine — socialakten/BBIC-journalen · **Datum:** 2026-06-14 · **Plattform:** server v32 (Hub 25 Autumn), **HPB + recording server + Collabora/OnlyOffice förutsatt i prod.**
>
> **Flödet i en mening:** Anna (socialsekreterare) bokar ett säkert videomöte med en vårdnadshavare via en bokningsbar tid (Calendar Appointments → auto Talk-rum + BankID-lobby), genomför mötet (spreed-itsl + HPB), spelar in **med påtvingat samtycke** (`recording_consent`), transkriberar **lokalt** (recording server → WebM → `stt_whisper2` + KB-Whisper), **AI-sammanfattar lokalt** (`llm2`/Assistant), **granskar och godkänner** (human-in-the-loop, GDPR art. 22), och **för den godkända anteckningen** till Treserva-journalen — varefter **rå-WebM + rått transkript gallras** enligt Retention.
>
> **Bärande arkitektur:** Hubs är **mellanlagring (mellanarkiv)**. Mötet äger inget rekord; den godkända anteckningen committas till slutlagringen (Treserva); rå-artefakterna är transient mellanlagring. **Handoff-mönster:** A = API/REST · B = drag-to-case · C = FGS-export · D = manuell (dag-1-fallback).
>
> **Brand-regel:** i UI säger vi aldrig "Nextcloud"/"Talk" — här namnger vi apparna (calendar, spreed-itsl, recording server, `stt_whisper2`/KB-Whisper, `llm2`/Assistant, groupfolders, files_retention, forms, libresign) för spårbarhet.
>
> **Juridisk grundlinje (avgör vad som får köras skarpt):** ett sekretessbelagt klientsamtal i socialtjänsten transkriberat med AI ligger i den röda zonen — branschlinjen (Kalmar/Digitala Samtal, IMY-dialog) är *"compliance first, sharp deployment second"*. Detta walkthrough beskriver **det fulla, juridiskt försvarbara prod-flödet** med villkoren inbyggda (samtycke, human-in-the-loop, dataminimering, gallring, on-prem). Var villkoren ännu inte är uppfyllda flaggas det med ⚠.

---

## Förutsättningar som detta flöde antar (sätts före Steg 1)

- **⚠ ANTAGANDE (drift):** HPB (signaling + Janus SFU + NATS) och **recording server på egen maskin** är driftsatta; `recording_secret`/`internal_secret` satta; output styrd till ärenderummets Groupfolder (inte default `Talk/Recordings/`). Utan HPB finns ingen inspelning alls.
- **⚠ ANTAGANDE (modell):** `stt_whisper2` kör **KB-Whisper-large** (eller small/medium på CPU/liten GPU), Apache-2.0, i `nc_app_stt_whisper2_data`. Live-textning (`live_transcription`/Vosk) används **inte** — Vosk saknar svenska. All svensk kvalitet kommer via efterhands-Whisper.
- **⚠ ANTAGANDE (modell):** `llm2` kör grön-ratad GGUF (OLMo 2 / Llama 3.1) med **chunkning/map-reduce** byggd för transkript > kontextfönstret (4–8k tokens).
- **`recording_consent` = påtvingat** systemomfattande (admin `/settings/admin/talk`), samtyckes-tidsstämpel loggas per deltagare.
- **Identitet:** Anna legitimerad LOA3 (Freja eID Plus / SITHS). Vårdnadshavaren har inget myndighetskonto → verifieras med **BankID i lobbyn**.

---

## Stegen

**Steg 1 — Anna skapar en bokningsbar tid och kallar vårdnadshavaren**
- **Handläggaren:** I Hubs Start, sidopanelens **`dagensMoten`** / primäråtgärden **"Kalla till säkert möte"**, väljer hon "Skapa bokningstid" → öppnar `bokningsbaraTider` (Calendar Appointments). Card View → Quick View: längd 45 min, buffert, tillgänglighet. Hon kopplar tiden till barnets dnr (ärendereferens, inte klartext) och skickar bokningslänken till vårdnadshavaren via **säker e-post + BankID-länk** (securemail), inte öppen e-post.
- **I Hubs (mellanlagring):** Calendar (`calendar`) skapar en bokningskonfiguration; rutt `/apps/calendar/appointment/{userId}/{configToken}`. Vid bokning skapas en CalDAV-händelse i Annas kalender (`/remote.php/dav/calendars/{user}/...`), och **eftersom spreed-itsl är installerat skapas automatiskt ett unikt mötesrum** (`/call/{token}`) i bokningsbekräftelsen. Status på `dagensMoten`-raden: *Bokad*.
- **I facksystemet (slutlagring):** inget ännu — mötet är transit. Mötesanteckningen committas i Steg 11. (Kallelsen som *handling* kan dock behöva diarieföras separat; se ⚠ nedan.)
- **Data:** UT (kallelse) via securemail, LOA3-länk till vårdnadshavare; ärendereferens, inte personuppgifter i klartext (GDPR art. 5 dataminimering). Ingen retention-fråga ännu.
- **⚠ LUCKA:** Calendars publika bokningssida är **oautentiserad som standard** — Hubs måste lägga LOA3/identitetslager framför, annars kan vem som helst med länken boka. För känsliga ärenden bör bokningen vara **riktad (privat hemlig URL till en redan identifierad vårdnadshavare)**, inte en öppen portal. Uppströms-önskemål: alltid-auto-videorum + autentiserad bokning (calendar #3480/#3581/#3484). **⚠ ANTAGANDE:** auto-Talk-rum vid Appointments-bokning fungerar i v32 (verifierat som funktion, men har rapporterats versionskänsligt) — i prod-demo bör rummet skapas explicit om auto-skapande inte slår till.

**Steg 2 — Samtycke till mötet och till inspelning inhämtas i förväg**
- **Handläggaren:** Innan mötet skickar Anna ett **samtyckesformulär** (kunskapsbankens mall via `mallarSamtycke`) som täcker både (a) det säkra mötet/SIP-samtycke och (b) **uttryckligt samtycke till inspelning + automatisk transkribering/AI-sammanfattning**. Vårdnadshavaren fyller i och bekräftar med ett **BankID-loggat signeringssteg**.
- **I Hubs (mellanlagring):** Forms (`forms`, `/apps/forms/{hash}`) fångar svaret; signeringssteget via `libresign` (lågrisk "Godkänn"/SES, BankID framför inloggningen) eller Inera för starkare bevis. Det ifyllda + signerade samtycket arkiveras som **fil i ärenderummet** (Groupfolder).
- **I facksystemet (slutlagring):** inget ännu — committas tillsammans med ärendet/anteckningen i Steg 11. Det signerade samtycket är en **allmän handling** och följer med till Treserva-akten.
- **Data:** IN (samtycke) från vårdnadshavare, BankID/LOA3, tidsstämplat. Sekretess: socialtjänstsekretess (OSL 26 kap.). Retention: bevaras med ärendet (inte transient — samtycket är beviset för den rättsliga grunden).
- **⚠ ANTAGANDE:** samtycke åberopas här som en **transparens-/förtroendeåtgärd**, men den primära rättsliga grunden för socialtjänstens behandling är **myndighetsutövning/rättslig förpliktelse** (inte samtycke enligt GDPR art. 6.1.a, som en part i beroendeställning knappast kan ge fritt). ⚠ LUCKA: samtycket gör **inte** inspelningen tillåten "av sig själv" — HSLF-FS 2016:40-kraven kan inte avtalas bort, och OSL gäller oavsett samtycke. Samtycket dokumenterar att deltagaren *informerats*, inte att sekretessen hävts.

**Steg 3 — Anna ansluter mötet och släpper in vårdnadshavaren via BankID-lobby**
- **Handläggaren:** På mötesdagen öppnar hon `dagensMoten` → **en-klicks-anslut**. I **lobbyn** ser hon "1 i väntrum — vårdnadshavare, BankID/LOA3 verifierad". Hon **släpper in** deltagaren manuellt.
- **I Hubs (mellanlagring):** spreed-itsl, lobby-state `1` (lobby för icke-moderatorer); per-deltagare-insläpp. Identiteten bärs av lobbyns completionData (BankID/Freja via ID-core) → `identitetsBadge`. Rutt `/call/{token}`. SMS-OTP är **spärrad** för LOA3 (synligt spärrtillstånd, inte tyst fel).
- **I facksystemet (slutlagring):** inget — möteshändelsen är transit.
- **Data:** identitetsbevis (metod + LOA + tidpunkt) skapas; bevaras sedan med kvittensen/handlingen i Treserva. HSLF-FS 2016:40: krypterad kanal + stark autentisering uppfyllt.
- **⚠ LUCKA:** lobbyns BankID-verifiering är ITSL:s spreed-itsl-fork (ID-core), **inte** native Nextcloud Talk — den förutsätter att ID-core-lobbyintegrationen faktiskt är driftsatt. På ren v32 finns bara lösenords-/kontolobby.

**Steg 4 — Samtycke till inspelning bekräftas i rummet, inspelningen startar**
- **Handläggaren:** Anna informerar muntligt att mötet spelas in. Eftersom `recording_consent` är **påtvingat** måste **varje deltagare kryssa i samtycke innan de släpps in i samtalet**, och alla får en **synlig notis i mötet om att inspelning pågår**. Anna (moderator) trycker **Starta inspelning**.
- **I Hubs (mellanlagring):** capability `recording-consent` aktiv; samtyckes-tidsstämpel loggas som åtkomst-/samtyckeshändelse (Activity + SDK-logg, del av spårbarheten). Recording-state går `3` (startar video) → `1` (video pågår). Recording server joinar som osynlig browser via HPB.
- **I facksystemet (slutlagring):** inget — inspelningen är transient mellanlagring.
- **Data:** ljud+video börjar fångas on-prem. **0 tredjelandsöverföringar.** Samtyckeshändelsen är spårbarhetsbevis (GDPR + arkivlag-loggning, cybersäkerhetslagen 2025:1506).
- **⚠ ANTAGANDE:** recording server kräver egen maskin (~4 kärnor + RAM per parallell inspelning) och är den sköraste komponenten (browser-baserad). Om den inte är uppe finns ingen inspelning — flödet faller tillbaka på manuell anteckning.

**Steg 5 — Mötet genomförs; eventuell stödanteckning samredigeras live**
- **Handläggaren:** Hon håller samtalet, ställer BBIC-relevanta frågor. Vid behov för hon korta stödord i ett dokument i ärenderummet (samredigering on-prem) — men **det formella underlaget blir transkriptet + den godkända anteckningen**, inte live-klottret.
- **I Hubs (mellanlagring):** Collabora/OnlyOffice (WOPI) över Groupfolder; versionshantering på. Ingen live-textremsa (`live_transcription` används ej — saknar svenska).
- **I facksystemet (slutlagring):** inget ännu.
- **Data:** stödanteckning = utkast i mellanlagringen; sekretessklassad; ärendereferens som default, inte klartextcitat.
- **⚠ LUCKA:** —

**Steg 6 — Mötet avslutas; WebM-inspelningen landar som fil i ärenderummet**
- **Handläggaren:** Anna avslutar samtalet. Hon får en **avisering när inspelningsfilen är klar och uppladdad**.
- **I Hubs (mellanlagring):** recording server laddar upp **WebM** (audio+video) **in i ärenderummets Groupfolder** (styrt dit, ärver ACL + Retention — inte default `Talk/Recordings/`). Filen lagras **inte permanent** på recording-backenden. `senasteFiler`/`arenderum` visar den nya filen; rutt `/f/{fileId}`.
- **I facksystemet (slutlagring):** inget — WebM är **rå-artefakt, transient**.
- **Data:** WebM i ärenderummet, ACL: Anna skriver, gruppledare läser. **Retention/gallringsklocka sätts direkt** via restricted-tagg (`files_retention`): *"rå-inspelning gallras X dagar efter godkänd anteckning"*. Sekretess: socialtjänstsekretess.
- **⚠ LUCKA:** här uppstår den känsligaste juridiska punkten. En **ljud-/videoinspelning av ett myndighetsmöte är en handling och kan bli allmän handling (TF)** — men praxis ([allmanhandling.se](https://allmanhandling.se/tag/ljudinspelning/)) är att inspelningen är ett **utkast/mellanprodukt** som blir allmän handling **bara om den "tas om hand för arkivering"**. Designsvaret: rå-WebM tas **aldrig** om hand för arkivering — den får en kort, **beslutad gallringsfrist** och en dokumenttyp i kommunens dokumenthanteringsplan. ⚠ ANTAGANDE: kommunen har faktiskt fattat ett gallringsbeslut för handlingstypen "rå mötesinspelning, arbetsmaterial" — gallring kräver stöd i kommunens egna föreskrifter, aldrig godtycke (arkivlagen 1990:782).

**Steg 7 — Lokal transkribering: WebM → KB-Whisper → rått transkript**
- **Handläggaren:** Anna klickar **"Transkribera & sammanfatta möte (lokalt)"** på `motesanteckningar`/`dagensMoten`-raden (eller det triggas automatiskt när WebM landar).
- **I Hubs (mellanlagring):** `stt_whisper2` (ExApp, AppAPI/Docker) kör **KB-Whisper** (faster-whisper/CTranslate2; `revision="standard"` eller `"strict"` för ordagrannhet inför sekretessprövning) → producerar **transkript (.txt/.vtt)** som fil i ärenderummet. Backend för core Speech-To-Text-API. Helt on-prem.
- **I facksystemet (slutlagring):** inget — rått transkript är **transient**.
- **Data:** ljud → svensk text on-prem; KB-Whisper ~47 % lägre WER än large-v3. Samma gallringsklocka som WebM: *"rått transkript gallras X dgr efter godkänd anteckning"*.
- **⚠ LUCKA:** **rått transkript är lika känsligt som inspelningen och är potentiellt allmän handling** — samma utkast-/gallringslogik som Steg 6 gäller. Det får inte "samlas på hög för säkerhets skull". ⚠ ANTAGANDE: GPU-budget finns (≥4 GB VRAM small/medium; mer för large) eller CPU-batchtid accepteras; KB-Whisper-modellen är nedladdad och pinnad.

**Steg 8 — Lokal AI-sammanfattning: transkript → llm2 → utkast (sammanfattning + beslut + att-göra)**
- **Handläggaren:** Hon låter den lokala modellen producera ett **utkast**. (Alternativt: `call_summary_bot` har redan vid mötets slut postat deltagare + uppgifter i tråden; det är ett komplement, inte ersättning.)
- **I Hubs (mellanlagring):** `llm2` (llama.cpp, GGUF, grön-ratad) via Assistant/core Text Processing API läser transkriptet **lokalt** och *föreslår* ett myndighetsanpassat utkast: kort sammanfattning · **fattade beslut/överenskommelser** · **åtgärds-/att-göra-lista med ansvarig** · närvarande/frånvarande · **flagga "innehåller känsliga uppgifter — sekretessprövas"**. För långt transkript: chunkning/map-reduce.
- **I facksystemet (slutlagring):** inget — utkastet är **förslag, aldrig auto-committat**.
- **Data:** AI läser staging-data lokalt; **fattar aldrig beslut, skriver aldrig till facksystemet** (GDPR art. 22). 0 tredjelandsöverföringar. Suveränitetsmarkör synlig i mötesvyn ("Transkribering & sammanfattning sker lokalt").
- **⚠ LUCKA:** **hallucinationsrisk** — AI kan missa nyanser eller hitta på. Därför är Steg 9 obligatoriskt. ⚠ ANTAGANDE: `llm2` har ≥8 GB VRAM + ≥12 GB RAM (eller CPU 10–20 kärnor) och en promptmall för svenskt myndighetsformat (beslut/åtgärder) är byggd.

**Steg 9 — Human-in-the-loop: Anna granskar, redigerar och godkänner**
- **Handläggaren:** Anna **läser transkriptet mot utkastet**, rättar fel/hallucinationer, stryker irrelevant känsligt, formulerar den slutliga journalanteckningen, och trycker **"Godkänn"**. En `bevakningar`-/`minaUppgifter`-post ("Granska & godkänn mötesanteckning") stängs.
- **I Hubs (mellanlagring):** den godkända texten sparas som versionerad fil i ärenderummet; **"Godkänn" loggas som händelse** (vem, vad, när) — Activity + SDK-logg. Detta är spårbarheten IMY/SKR kräver.
- **I facksystemet (slutlagring):** inget ännu — committas i Steg 11.
- **Data:** den godkända anteckningen är nu den enda artefakt som ska bevaras; rå-WebM + rått transkript är fortfarande transient med tickande gallringsklocka.
- **⚠ LUCKA:** human-in-the-loop måste vara **obligatoriskt och tekniskt påtvingat** — inget "auto-commit". Risken är att tidspress gör att handläggaren godkänner utan att granska transkriptet ordentligt; designen bör visa transkript och utkast **sida vid sida** och kräva aktivt godkännande, inte ett enda klick.

**Steg 10 — (Vid behov) beslut e-signeras**
- **Handläggaren:** Om mötet leder till ett *beslut* (t.ex. en insats, en SIP-överenskommelse) som ska skrivas under, skickar hon det **för underskrift** från `attSignera`. Lågrisk/internt → "Godkänn" (loggat, LibreSign); formellt myndighetsbeslut → **AES via BankID/Freja** genom Inera Underskriftstjänst-API/Sweden Connect-nod → PAdES/**PDF/A-1** + LTV.
- **I Hubs (mellanlagring):** signeringskö (`attSignera`/`skickatForSignering`); rutt `/apps/libresign/` (demo) / extern signeringsadapter (prod). Bevarandepanel "Giltig nu / Giltig då".
- **I facksystemet (slutlagring):** den signerade PAdES/PDF/A + valideringsintyg committas till Treserva-akten (mönster A/B/D) — se Steg 11.
- **Data:** signerad handling + identitetsbevis; bevaras för LTV.
- **⚠ LUCKA:** native LibreSign ger bara konto/e-post/SMS-identitet (SES/svag AES) — **håller inte för myndighetsbeslut**. Riktig svensk AES kräver Inera (mTLS + SITHS funktionscert) eller Sweden Connect-nod, vilket är `proposed-integration`, inte byggt än. Detta steg är **valfritt** för flödet (många mötesanteckningar journalförs utan formell signatur).

**Steg 11 — Den godkända anteckningen förs över till Treserva-journalen**
- **Handläggaren:** Anna trycker **"För över till facksystem"** på ärenderummet/`arenderum`-raden → den godkända anteckningen (+ ev. signerat beslut + signerat samtycke) registreras i **Treserva-akten/BBIC-journalen**.
- **I Hubs (mellanlagring):** destinations-chip går *"→ Treserva — ej registrerad"* → *"Förd till Treserva, dnr 2026-IFO-1234"*. Mönster **B** (drag-to-case/förifylld registrering) som standard; **A** via Treservas öppna API hos storkund; **D** (ladda ner PDF/A + "Markera som överförd") som dag-1-fallback. Tunn konnektor mot Ena REST-profil.
- **I facksystemet (slutlagring):** den godkända mötesanteckningen blir **journalanteckning i socialakten** (dokumentationsskyldighet, höjda krav i SoL 2025:400). Om mötet skedde under en pågående utredning räknas det in i **4-månadersfristen**; om beslut fattats kan en **överklagandefrist/uppföljningsbevakning** sättas. Treserva äger nu den formella bevakningen (rödmarkeras vid passerat datum) — Hubs dubblerar inte.
- **Data:** UT (godkänd text) → Treserva. Allmän handling, socialtjänstsekretess. Treserva ansvarar för bevarande/gallring och i slutänden e-arkiv (Sydarkivera, FGS) vid avslut.
- **⚠ LUCKA:** integrationsmognaden styr realismen — mönster B/A förutsätter en byggd Treserva-konnektor; **dag 1 är överföringen manuell (D)** med "Markera som överförd"-status. Hela `motesanteckningar`-widgeten är `proposed-integration`.

**Steg 12 — Rå-WebM och rått transkript gallras; Hubs-kopian rensas efter överföring**
- **Handläggaren:** Inget aktivt krävs — men hon ser i `arenderum` att rå-artefakterna har en **gallrings-countdown** och får notis innan radering. Hon kan välja "gallra nu" när anteckningen är committad.
- **I Hubs (mellanlagring):** `files_retention` (restricted-tagg, ägarnotis) raderar **rå-WebM + rått transkript** enligt regeln *"X dgr efter godkänd anteckning/överföring"*. Den **godkända anteckningen** (om den fortfarande behövs som arbetskopia) får ärenderummets vanliga rensningsklocka: *"Rensas ur Hubs N dgr efter överföring till Treserva"*. **Dubbel countdown** (facksystemets bevarande + Hubs rensning) är synlig.
- **I facksystemet (slutlagring):** Treserva/e-arkiv bär nu den bevarade handlingen — Hubs ska **inte** bli en permanent skuggdatabas.
- **Data:** rå-artefakter raderas (dataminimering, GDPR art. 5; OSL — minimera dubbellagrad sekretess). Endast den godkända, journalförda anteckningen lever vidare, i Treserva.
- **⚠ LUCKA:** gallring av rå-data **förutsätter ett dokumenterat gallringsbeslut** i kommunens DHP ("mellanlagring/arbetsmaterial rensas efter överföring"). ⚠ ANTAGANDE: om någon *begärt ut* inspelningen/transkriptet innan gallring (TF/offentlighetsbegäran) får den **inte** gallras förrän begäran är hanterad — en automatisk Retention-klocka måste kunna **pausas** vid en utlämnandebegäran. Detta är en konkret bygg-/policylucka.

---

## Systemöversikt för detta flöde

| Steg | Hubs-app (mellanlagring) | Facksystem (slutlagring) | Handoff |
|---|---|---|---|
| 1 Boka tid + kalla | `bokningsbaraTider`/`dagensMoten` (calendar + spreed-itsl, auto-rum) + securemail | — (transit) | — |
| 2 Samtycke (möte + inspelning) | `mallarSamtycke` (forms + libresign), arkiveras i ärenderum | committas i Steg 11 (→ Treserva) | B/A/D |
| 3 Anslut + BankID-lobby | `dagensMoten`/`identitetsBadge` (spreed-itsl lobby, ID-core) | — (transit) | — |
| 4 Inspelning + `recording_consent` | spreed-itsl + recording server (HPB) | — (transient) | — |
| 5 Mötet + stödanteckning | `arenderum` (Collabora/OnlyOffice, groupfolders) | — | — |
| 6 WebM → ärenderum | `senasteFiler`/`arenderum` (groupfolders + files_retention) | — (transient, gallras Steg 12) | — |
| 7 Transkribering (KB-Whisper) | `motesanteckningar` (`stt_whisper2` + KB-Whisper) | — (transient) | — |
| 8 AI-sammanfattning (utkast) | `motesanteckningar` (`llm2`/Assistant) | — (förslag) | — |
| 9 Granska + Godkänn | `motesanteckningar` + `bevakningar` (loggad händelse) | committas i Steg 11 | — |
| 10 (Ev.) e-signera beslut | `attSignera`/`skickatForSignering` (Inera/Sweden Connect / libresign) | Signerad PDF/A → Treserva-akten | A/B/D |
| 11 För över godkänd anteckning | `arenderum` destinations-chip → Treserva | **Treserva/Lifecare/Viva/Combine** (BBIC-journal) | **B** (A storkund / D dag 1) |
| 12 Gallra rå-data + rensa Hubs | `arkivGallring`/`files_retention` (restricted-tagg) | Treserva/e-arkiv bär handlingen | C (vid avslut) |

---

## Identifierade luckor

1. **Skarp drift på sekretessbelagt klientsamtal = röd zon.** Hela flödet är tekniskt demobart men juridiskt villkorat: branschlinjen är "compliance first" tills IMY/SKR/Socialstyrelsen gett tydlig vägledning. On-prem löser **tredjelandsfrågan** men inte hela OSL/arkiv-frågan. **Status: dokumentera/villkora — kör inte skarpt på riktiga klientsamtal än.** (Steg 4–9.)
2. **Rå-inspelning OCH rått transkript är potentiellt allmänna handlingar.** Försvarbart bara om de behandlas som **utkast** (aldrig "tas om hand för arkivering") med **beslutad kort gallringsfrist** i kommunens DHP. Saknas gallringsbeslutet är gallringen olaglig (arkivlagen). (Steg 6, 7, 12.)
3. **Retention-klockan måste kunna pausas vid en utlämnandebegäran (TF).** En automatisk gallring av rå-WebM/-transkript får inte radera en handling som någon begärt ut innan begäran prövats. Konkret bygg-/policylucka. (Steg 12.)
4. **Samtycke ≠ rättslig grund och häver inte sekretess.** Samtycket är transparens/förtroende; den rättsliga grunden är myndighetsutövning, och HSLF-FS 2016:40/OSL kan inte avtalas bort. UI får inte ge sken av att "kryssa i samtycke" gör allt lagligt. (Steg 2.)
5. **Human-in-the-loop får inte degenerera till ett klick.** Tidspress + hallucinationsrisk kräver att transkript och utkast visas **sida vid sida** med tekniskt påtvingat, loggat godkännande — annars committas AI-fel till journalen. (Steg 8–9.)
6. **`motesanteckningar` är `proposed-integration`, inte byggt.** Beror på fem tunga backend-komponenter: HPB, recording server (egen maskin, skör/browser-baserad), `stt_whisper2`+KB-Whisper (GPU), `llm2` (GPU + chunkning för långa transkript), och Treserva-konnektor. Utan dessa är flödet en mock. (Steg 4, 7, 8, 11.)
7. **BankID-lobby + auto-Talk-rum är fork-/version-beroende.** Lobbyns BankID-verifiering är ITSL:s spreed-itsl/ID-core, inte native; auto-rum vid Appointments-bokning är funktionellt men rapporterat versionskänsligt (calendar #3480/#3581). På ren v32 finns bara konto-/lösenordslobby och ev. manuellt skapat rum. (Steg 1, 3.)
8. **Calendars publika bokningssida är oautentiserad** — för känsliga ärenden måste Hubs lägga LOA3 framför / använda riktad privat länk, annars kan vem som helst boka tid hos en socialsekreterare. (Steg 1.)
9. **Riktig svensk AES saknas native.** LibreSign ger bara SES/svag AES (konto/SMS); myndighetsbeslut kräver Inera (mTLS + SITHS funktionscert) eller Sweden Connect-nod, som inte är byggt än. Påverkar det valfria signeringssteget. (Steg 10.)
10. **Live-textning på svenska finns inte.** `live_transcription`/Vosk saknar svenska → ingen textremsa under mötet, bara efterhands-Whisper. Tillgänglighetsvärdet (undertext, WCAG/DOS-lagen) uteblir tills svensk streaming-STT finns. (Steg 5.)

---

### Källor (utöver de interna underlagen)
- Inspelning/transkript som utkast vs allmän handling (omhändertagen för arkivering) — https://allmanhandling.se/tag/ljudinspelning/
- OSL 2009:400 (socialtjänstsekretess, sekretessprövning vid begäran) — https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/offentlighets-och-sekretesslag-2009400_sfs-2009-400/
- Statens arkiv — OSL och allmänna handlingar — https://statensarkiv.se/offentlighet-och-sekretesslagen/
- Socialstyrelsen — sekretess inom socialtjänst — https://www.socialstyrelsen.se/kunskapsstod-och-regler/regler-och-riktlinjer/juridiskt-stod-for-dokumentation/ta-del-av-uppgifter-inom-socialtjanst/
- Calendar Appointments → auto Talk-rum (funktion + uppströms-luckor #3480/#3581) — https://github.com/nextcloud/calendar/issues/3480 · https://deepwiki.com/nextcloud/calendar/7-appointment-booking-system
- (Intern grund: `transcription-ai.md`, `native-apps-map.md`, `middleware-architecture.md`, `arendehantering-map.md`, `esign-todo-native.md`, `persona-usage-socialsekreterare.md`, `widgetApps.js`, `WIDGET-APP-MAP.md`.)
